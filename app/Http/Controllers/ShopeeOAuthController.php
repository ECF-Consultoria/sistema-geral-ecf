<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Shopee\ShopeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Painel e fluxo OAuth Shopee. Espelha o MercadoLivreOAuthController:
 * admin gera o link → cliente autoriza na Shopee → callback público salva o
 * token → refresh automático. Rotas do painel são gated por role:admin.
 */
class ShopeeOAuthController extends Controller
{
    public function __construct(private ShopeeService $shopee) {}

    // ── Painel admin ─────────────────────────────────────────────────────────

    /**
     * Lista todas as empresas com status OAuth Shopee para o painel dedicado.
     */
    public function adminIndex(): \Inertia\Response
    {
        $companies = Company::with('shopeeToken')
            ->orderBy('name')
            ->get(['id', 'name', 'shopee_link_generated_at', 'shopee_link_url'])
            ->map(fn ($c) => [
                'id'                       => $c->id,
                'name'                     => $c->name,
                'shopee_link_generated_at' => $c->shopee_link_generated_at?->toISOString(),
                'shopee_link_expires_at'   => $c->shopee_link_generated_at?->addDays(7)->toISOString(),
                'shopee_link_url'          => $c->shopee_link_url,
                'shopee_token'             => $c->shopeeToken ? [
                    'status'       => $c->shopeeToken->status,
                    'shop_id'      => $c->shopeeToken->shop_id,
                    'connected_at' => $c->shopeeToken->connected_at?->toISOString(),
                    'expires_at'   => $c->shopeeToken->expires_at?->toISOString(),
                ] : null,
            ])->values();

        return Inertia::render('ShopeeOAuth/Index', ['companies' => $companies]);
    }

    // ── Gerar URL de autorização ──────────────────────────────────────────────

    /**
     * Gera a URL OAuth Shopee para a empresa e retorna ao frontend.
     */
    public function initiate(Company $company): JsonResponse
    {
        $url = $this->shopee->buildAuthUrl($company);

        return response()->json(['url' => $url]);
    }

    // ── Callback da Shopee ────────────────────────────────────────────────────

    /**
     * Rota pública — a Shopee redireciona para cá após o cliente autorizar.
     * Recebe `state` (nosso, para vincular a empresa), `code` e `shop_id`.
     */
    public function callback(Request $request)
    {
        $state  = $request->string('state')->value();
        $code   = $request->string('code')->value();
        $shopId = $request->string('shop_id')->value();

        if (! $state || ! $code || ! $shopId) {
            return view('oauth.shopee-result', [
                'success' => false,
                'message' => 'Parâmetros inválidos no callback.',
            ]);
        }

        $stateData = $this->shopee->consumeState($state);

        if (! $stateData) {
            Log::warning('[Shopee] State inválido ou expirado no callback', ['state' => $state]);
            return view('oauth.shopee-result', [
                'success' => false,
                'message' => 'Link expirado (válido por 7 dias). Peça um novo link ao administrador.',
            ]);
        }

        $company = Company::find($stateData['company_id']);

        if (! $company) {
            return view('oauth.shopee-result', [
                'success' => false,
                'message' => 'Empresa não encontrada. Entre em contato com o suporte.',
            ]);
        }

        try {
            $tokenData = $this->shopee->exchangeCode($code, $shopId);
            $this->shopee->saveToken($company, $shopId, $tokenData);

            // Registra o shop_id no modelo multi-marketplace (pivot shopee).
            $company->marketplaces()->updateOrCreate(
                ['marketplace' => 'shopee'],
                ['store_id' => $shopId, 'integracao_status' => 'ativa'],
            );

            $company->update([
                'shopee_link_generated_at' => null,
                'shopee_link_url'          => null,
            ]);

            Log::info("[Shopee] Token salvo empresa {$company->id} ({$company->name})", [
                'shop_id' => $shopId,
            ]);

            return view('oauth.shopee-result', [
                'success'      => true,
                'company_name' => $company->name,
            ]);
        } catch (\Throwable $e) {
            Log::error("[Shopee] Falha no callback empresa {$company->id}: {$e->getMessage()}");
            return view('oauth.shopee-result', [
                'success' => false,
                'message' => 'Erro ao processar autorização. Tente novamente ou contate o suporte.',
            ]);
        }
    }

    // ── Sync manual (leitura ao vivo — faturamento bruto D-1) ─────────────────

    /**
     * Busca ao vivo o faturamento bruto de pedidos da empresa e retorna JSON.
     * Aceita ?date=YYYY-MM-DD; padrão: ontem. Não persiste métricas (a decisão de
     * onde gravar dados de Shopee fica para a onda de sync).
     */
    public function syncNow(Request $request, Company $company): JsonResponse
    {
        if (! $company->shopeeToken || $company->shopeeToken->status !== 'active') {
            return response()->json(['error' => 'Empresa sem token Shopee ativo.'], 422);
        }

        $date = $request->input('date', now()->subDay()->toDateString());

        try {
            $summary = $this->shopee->fetchOrdersSummary($company, $date, $date);

            Log::info("[Shopee] Leitura manual empresa {$company->id} ({$company->name}) data {$date}");

            return response()->json(array_merge(['date' => $date], $summary));
        } catch (\Throwable $e) {
            Log::error("[Shopee] Erro na leitura manual empresa {$company->id}: {$e->getMessage()}");
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Desconectar ───────────────────────────────────────────────────────────

    /**
     * Remove o token Shopee da empresa (admin only via rota gated por role:admin).
     */
    public function disconnect(Company $company)
    {
        $company->shopeeToken?->delete();

        Log::info("[Shopee] Token removido empresa {$company->id} ({$company->name}) por " . auth()->user()?->name);

        return back()->with('success', 'Conexão com Shopee removida.');
    }
}
