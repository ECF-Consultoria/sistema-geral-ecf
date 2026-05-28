<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoLivreOAuthController extends Controller
{
    public function __construct(private MercadoLivreService $ml) {}

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
                'message' => 'Link expirado (válido por 15 minutos). Peça um novo link ao administrador.',
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

            // Preenche ml_store_id se a empresa ainda não tiver
            if (! $company->ml_store_id) {
                $company->update(['ml_store_id' => $mlToken->ml_user_id]);
            }

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
