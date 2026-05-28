<?php

namespace App\Http\Controllers;

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
            ->get(['id', 'name', 'ml_store_id', 'ml_link_generated_at'])
            ->map(fn($c) => [
                'id'                   => $c->id,
                'name'                 => $c->name,
                'ml_store_id'          => $c->ml_store_id,
                'ml_link_generated_at' => $c->ml_link_generated_at?->toISOString(),
                'ml_link_expires_at'   => $c->ml_link_generated_at?->addDays(7)->toISOString(),
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

            // Preenche ml_store_id e limpa o link pendente
            $company->update([
                'ml_store_id'          => $company->ml_store_id ?? $mlToken->ml_user_id,
                'ml_link_generated_at' => null,
            ]);

            // Divergência: ml_store_id existente difere do user_id retornado
            $diverge = $company->ml_store_id && $company->ml_store_id !== $mlToken->ml_user_id;

            Log::info("[MercadoLivre] Token salvo empresa {$company->id} ({$company->name})", [
                'ml_user_id' => $mlToken->ml_user_id,
                'diverge'    => $diverge,
            ]);

            return view('oauth.ml-result', [
                'success'      => true,
                'company_name' => $company->name,
                'diverge'      => $diverge,
                'stored_id'    => $company->ml_store_id,
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
