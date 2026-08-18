<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMlCompanyJob;
use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class MercadoLivreOAuthController extends Controller
{
    public function __construct(private MercadoLivreService $ml) {}

    // ── Painel admin ─────────────────────────────────────────────────────────

    /**
     * Lista todas as empresas com status OAuth ML para o painel dedicado.
     */
    public function adminIndex(): \Inertia\Response
    {
        $companies = Company::with('mlToken')
            ->orderBy('name')
            ->get(['id', 'name', 'ml_store_id', 'ml_link_generated_at', 'ml_link_url'])
            ->map(fn($c) => [
                'id'                   => $c->id,
                'name'                 => $c->name,
                'ml_store_id'          => $c->ml_store_id,
                'ml_link_generated_at' => $c->ml_link_generated_at?->toISOString(),
                'ml_link_expires_at'   => $c->ml_link_generated_at?->addDays(7)->toISOString(),
                'ml_link_url'          => $c->ml_link_url,
                'ml_token'             => $c->mlToken ? [
                    'status'       => $c->mlToken->status,
                    'ml_user_id'   => $c->mlToken->ml_user_id,
                    'connected_at' => $c->mlToken->connected_at?->toISOString(),
                    'expires_at'   => $c->mlToken->expires_at?->toISOString(),
                ] : null,
            ])->values();

        return Inertia::render('MlOAuth/Index', ['companies' => $companies]);
    }

    // ── Gerar URL de autorização ──────────────────────────────────────────────

    /**
     * Gera a URL OAuth para a empresa e retorna ao frontend.
     * O admin copia e envia ao cliente, ou abre diretamente.
     */
    public function initiate(Company $company)
    {
        $url = $this->ml->buildAuthUrl($company);

        return response()->json(['url' => $url]);
    }

    // ── Callback do Mercado Livre ─────────────────────────────────────────────

    /**
     * Rota pública — o ML redireciona para cá após o cliente autorizar.
     * Troca o code por tokens e vincula à empresa via state.
     */
    public function callback(Request $request)
    {
        // Erros enviados pelo ML (ex.: acesso negado pelo usuário)
        if ($request->has('error')) {
            Log::warning('[MercadoLivre] OAuth negado pelo usuário', [
                'error'       => $request->error,
                'description' => $request->error_description,
            ]);
            return view('oauth.ml-result', [
                'success' => false,
                'message' => 'Autorização negada. Feche esta aba e tente novamente.',
            ]);
        }

        $state = $request->string('state')->value();
        $code  = $request->string('code')->value();

        if (! $state || ! $code) {
            return view('oauth.ml-result', [
                'success' => false,
                'message' => 'Parâmetros inválidos no callback.',
            ]);
        }

        // Recupera e invalida o state (evita replay)
        $stateData = $this->ml->consumeState($state);

        if (! $stateData) {
            Log::warning('[MercadoLivre] State inválido ou expirado no callback', ['state' => $state]);
            return view('oauth.ml-result', [
                'success' => false,
                'message' => 'Link expirado (válido por 7 dias). Peça um novo link ao administrador.',
            ]);
        }

        $companyId    = $stateData['company_id'];
        $codeVerifier = $stateData['code_verifier'];

        $company = Company::find($companyId);

        if (! $company) {
            return view('oauth.ml-result', [
                'success' => false,
                'message' => 'Empresa não encontrada. Entre em contato com o suporte.',
            ]);
        }

        try {
            $tokenData = $this->ml->exchangeCode($code, $codeVerifier);
            $mlToken   = $this->ml->saveToken($company, $tokenData);

            // ml_store_id = Seller ID (ml_user_id) da conta autorizada. Esse é o
            // identificador canônico (Cust ID) — sobrescreve qualquer valor
            // divergente digitado manualmente. A mudança é auditada pelo Spatie
            // activitylog (ml_store_id está no logOnly da Company).
            $previousStoreId = $company->ml_store_id;
            $company->update([
                'ml_store_id'          => $mlToken->ml_user_id,
                'ml_link_generated_at' => null,
                'ml_link_url'          => null,
            ]);

            // Corrigido: havia um ml_store_id diferente do user_id retornado
            $corrected = $previousStoreId && $previousStoreId !== $mlToken->ml_user_id;

            if ($corrected) {
                Log::warning("[MercadoLivre] ml_store_id corrigido empresa {$company->id}: '{$previousStoreId}' → '{$mlToken->ml_user_id}' (Seller ID do OAuth)");
            }

            Log::info("[MercadoLivre] Token salvo empresa {$company->id} ({$company->name})", [
                'ml_user_id' => $mlToken->ml_user_id,
                'corrected'  => $corrected,
            ]);

            // Quando o fluxo começou numa página que sabe continuar sozinha (o
            // portal público do Onboarding), devolve o cliente pra lá em vez da
            // página de resultado — ele volta e vê o passo já fechado pelo
            // resolver. A URL vem do state, montada com `route()` por quem
            // iniciou; nunca do request (seria open redirect).
            $retornoUrl = $stateData['retorno_url'] ?? null;
            if ($retornoUrl) {
                return redirect($retornoUrl)->with('success', 'Acesso autorizado — obrigado!');
            }

            return view('oauth.ml-result', [
                'success'      => true,
                'company_name' => $company->name,
                'corrected'    => $corrected,
                'previous_id'  => $previousStoreId,
                'received_id'  => $mlToken->ml_user_id,
            ]);

        } catch (\Throwable $e) {
            Log::error("[MercadoLivre] Falha no callback empresa {$companyId}: {$e->getMessage()}");
            return view('oauth.ml-result', [
                'success' => false,
                'message' => 'Erro ao processar autorização. Tente novamente ou contate o suporte.',
            ]);
        }
    }

    // ── Sync manual ──────────────────────────────────────────────────────────

    /**
     * Dispara sync ML imediato para a empresa (admin only).
     * Aceita ?date=YYYY-MM-DD; padrão: ontem (D-1).
     */
    public function syncNow(Request $request, Company $company): JsonResponse
    {
        if (! $company->mlToken || $company->mlToken->status !== 'active') {
            return response()->json(['error' => 'Empresa sem token ML ativo.'], 422);
        }

        $date = $request->input('date', now()->subDay()->toDateString());

        try {
            $metric = $this->ml->syncCompany($company, $date);

            Log::info("[MercadoLivre] Sync manual empresa {$company->id} ({$company->name}) data {$date}");

            return response()->json([
                'date'      => $date,
                'revenue'   => $metric->revenue,
                'ad_spend'  => $metric->ad_spend,
                'tacos'     => $metric->tacos,
            ]);
        } catch (\Throwable $e) {
            Log::error("[MercadoLivre] Erro sync manual empresa {$company->id}: {$e->getMessage()}");
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Sync global (fan-out) ─────────────────────────────────────────────────

    /**
     * Dispara o sync D-1 de TODAS as empresas com token OAuth ML ativo (admin only).
     * Replica o fan-out assíncrono do comando `ml:sync` sem travar o request.
     * Aceita ?date=YYYY-MM-DD; padrão: ontem (D-1).
     *
     * @return JsonResponse { enfileiradas: int, date: string }
     */
    public function syncAll(Request $request): JsonResponse
    {
        // Data alvo: D-1 por padrão (ML é D-1), mas aceita override manual
        $date = $request->input('date', now()->subDay()->toDateString());

        // Busca apenas empresas ativas com token ML ativo (mesmo critério do ml:sync)
        $companies = Company::query()
            ->where('active', true)
            ->whereHas('mlToken', fn($q) => $q->where('status', 'active'))
            ->with('mlToken')
            ->get();

        $total = $companies->count();

        if ($total === 0) {
            Log::info('[MercadoLivre] Sync global solicitado mas nenhuma empresa com token ativo encontrada.', ['date' => $date]);
            return response()->json(['enfileiradas' => 0, 'date' => $date]);
        }

        // Delay escalonado de 2s entre jobs — respeita rate limit ML (1.500 req/min)
        foreach ($companies as $i => $company) {
            SyncMlCompanyJob::dispatch($company, $date)->delay(now()->addSeconds($i * 2));
        }

        Log::info("[MercadoLivre] Sync global enfileirado por " . auth()->user()?->name, [
            'enfileiradas' => $total,
            'date'         => $date,
        ]);

        return response()->json(['enfileiradas' => $total, 'date' => $date]);
    }

    // ── Desconectar ───────────────────────────────────────────────────────────

    /**
     * Remove o token ML da empresa (admin only via rota gated por role:admin).
     */
    public function disconnect(Company $company)
    {
        $company->mlToken?->delete();

        Log::info("[MercadoLivre] Token removido empresa {$company->id} ({$company->name}) por " . auth()->user()?->name);

        return back()->with('success', 'Conexão com Mercado Livre removida.');
    }
}
