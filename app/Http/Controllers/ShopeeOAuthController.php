<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Servico;
use App\Services\Shopee\ShopeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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
        $companies = Company::query()
            // Só empresas que contrataram o serviço Shopee (contrato ATIVO de setor
            // 'shopee' — mesmo critério da aba /shopee/empresas), MAIS qualquer empresa
            // que já tenha token Shopee (para nunca perder do painel uma conexão viva,
            // mesmo que o contrato tenha sido cancelado depois).
            ->where(function ($q) {
                $q->whereHas('contratosServico', fn ($ct) =>
                    $ct->where('contratos_servico.ativo', true)
                       ->whereHas('servico', fn ($s) => $s->where('setor', Servico::SETOR_SHOPEE))
                )->orWhereHas('shopeeTokens');
            })
            ->with('shopeeToken')
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

    // ── Gerar link de conexão (guiado, 1 link p/ os 2 apps) ───────────────────

    /**
     * Gera o LINK ÚNICO (assinado) que o admin envia ao cliente. Ele abre a
     * landing guiada (`connectLanding`), que conduz o cliente por Passo 1 (ERP)
     * → Passo 2 (Ads). Guardamos o link na empresa p/ o painel admin exibir.
     */
    public function initiate(Company $company): JsonResponse
    {
        // Link expira DE VERDADE em 7 dias (bate com o contador do painel, com a
        // mensagem "válido por 7 dias" e com o STATE_TTL do OAuth). Depois disso a
        // assinatura fica inválida (403) e o admin regenera pelo botão "Regerar".
        $url = URL::temporarySignedRoute('shopee.connect.landing', now()->addDays(7), ['company' => $company->id]);

        $company->update([
            'shopee_link_generated_at' => now(),
            'shopee_link_url'          => $url,
        ]);

        return response()->json(['url' => $url]);
    }

    // ── Landing guiada (pública, assinada) ────────────────────────────────────

    /**
     * Página que o cliente abre. Decide em que passo ele está (retomável):
     * já conectou ERP+Ads → concluído; só ERP → Passo 2 (Ads); nada → Passo 1 (ERP).
     */
    public function connectLanding(Company $company)
    {
        $hasErp = $company->shopeeToken()->where('status', 'active')->exists();
        $hasAds = $company->shopeeAdsToken()->where('status', 'active')->exists();
        $ads    = ShopeeService::for('ads');

        // Ambos conectados (ou só ERP quando Ads nem existe ainda) → concluído.
        if ($hasErp && ($hasAds || ! $ads->isConfigured())) {
            return view('oauth.shopee-connect', [
                'step'         => 'done',
                'company_name' => $company->name,
            ]);
        }

        // ERP feito, falta Ads → Passo 2 (mesma loja do passo 1).
        if ($hasErp && $ads->isConfigured()) {
            $shopId = (string) $company->shopeeToken()->value('shop_id');
            return view('oauth.shopee-connect', [
                'step'         => '2',
                'company_name' => $company->name,
                'button_url'   => $ads->buildAuthUrl($company, $shopId),
                'button_label' => 'Conectar Shopee Ads',
            ]);
        }

        // Início → Passo 1 (ERP).
        return view('oauth.shopee-connect', [
            'step'         => '1',
            'company_name' => $company->name,
            'button_url'   => $this->shopee->buildAuthUrl($company),
            'button_label' => 'Conectar Shopee',
        ]);
    }

    // ── Callback do app ERP (passo 1) ─────────────────────────────────────────

    /**
     * Rota pública — a Shopee redireciona para cá após o cliente autorizar o app
     * ERP. Salva o token, registra o marketplace e ENCADEIA para o passo Ads
     * (se o app Ads estiver configurado); senão, conclui só com ERP.
     */
    public function callback(Request $request)
    {
        $state  = $request->string('state')->value();
        $code   = $request->string('code')->value();
        $shopId = $request->string('shop_id')->value();

        if (! $state || ! $code || ! $shopId) {
            return $this->erro('Parâmetros inválidos no callback.');
        }

        $stateData = $this->shopee->consumeState($state);

        if (! $stateData || ($stateData['app'] ?? 'erp') !== 'erp') {
            Log::warning('[Shopee] State inválido/errado no callback ERP', ['state' => $state]);
            return $this->erro('Link expirado (válido por 7 dias). Peça um novo link ao administrador.');
        }

        $company = Company::find($stateData['company_id']);

        if (! $company) {
            return $this->erro('Empresa não encontrada. Entre em contato com o suporte.');
        }

        try {
            $tokenData = $this->shopee->exchangeCode($code, $shopId);
            $this->shopee->saveToken($company, $shopId, $tokenData);

            // Registra o shop_id no modelo multi-marketplace (pivot shopee).
            $company->marketplaces()->updateOrCreate(
                ['marketplace' => 'shopee'],
                ['store_id' => $shopId, 'integracao_status' => 'ativa'],
            );

            Log::info("[Shopee] Token ERP salvo empresa {$company->id} ({$company->name})", [
                'shop_id' => $shopId,
            ]);

            // Encadeia para o Ads (mesmo shop_id) se o 2º app existir.
            $ads = ShopeeService::for('ads');
            if ($ads->isConfigured()) {
                return view('oauth.shopee-connect', [
                    'step'         => '2',
                    'company_name' => $company->name,
                    'button_url'   => $ads->buildAuthUrl($company, $shopId),
                    'button_label' => 'Conectar Shopee Ads',
                ]);
            }

            // Sem app Ads → conclui só com ERP.
            $this->limparLink($company);
            return view('oauth.shopee-connect', [
                'step'         => 'done',
                'company_name' => $company->name,
                'only_erp'     => true,
            ]);
        } catch (\Throwable $e) {
            Log::error("[Shopee] Falha no callback ERP empresa {$company->id}: {$e->getMessage()}");
            return $this->erro('Erro ao processar autorização. Tente novamente ou contate o suporte.');
        }
    }

    // ── Callback do app ADS (passo 2) ─────────────────────────────────────────

    /**
     * Rota pública — a Shopee redireciona para cá após o cliente autorizar o app
     * Ads. Exige que a loja autorizada seja a MESMA do passo ERP (expected_shop_id).
     */
    public function adsCallback(Request $request)
    {
        $state  = $request->string('state')->value();
        $code   = $request->string('code')->value();
        $shopId = $request->string('shop_id')->value();

        if (! $state || ! $code || ! $shopId) {
            return $this->erro('Parâmetros inválidos no callback.');
        }

        $ads       = ShopeeService::for('ads');
        $stateData = $ads->consumeState($state);

        if (! $stateData || ($stateData['app'] ?? null) !== 'ads') {
            Log::warning('[Shopee] State inválido/errado no callback Ads', ['state' => $state]);
            return $this->erro('Link expirado (válido por 7 dias). Peça um novo link ao administrador.');
        }

        $company = Company::find($stateData['company_id']);

        if (! $company) {
            return $this->erro('Empresa não encontrada. Entre em contato com o suporte.');
        }

        // Barra loja diferente da conectada no passo 1.
        $expected = $stateData['expected_shop_id'] ?? null;
        if ($expected && (string) $shopId !== (string) $expected) {
            Log::warning("[Shopee] Loja diferente no passo Ads empresa {$company->id}", [
                'esperado' => $expected,
                'recebido' => $shopId,
            ]);
            return $this->erro('Você autorizou uma loja diferente da conectada no passo 1. Refaça o passo Ads usando a MESMA loja.');
        }

        try {
            $tokenData = $ads->exchangeCode($code, $shopId);
            $ads->saveToken($company, $shopId, $tokenData);

            $this->limparLink($company);

            Log::info("[Shopee] Token ADS salvo empresa {$company->id} ({$company->name})", [
                'shop_id' => $shopId,
            ]);

            return view('oauth.shopee-connect', [
                'step'         => 'done',
                'company_name' => $company->name,
            ]);
        } catch (\Throwable $e) {
            Log::error("[Shopee] Falha no callback Ads empresa {$company->id}: {$e->getMessage()}");
            return $this->erro('Erro ao processar autorização do Ads. Tente novamente ou contate o suporte.');
        }
    }

    /** Renderiza a página de erro do fluxo guiado. */
    private function erro(string $message)
    {
        return view('oauth.shopee-connect', ['step' => 'error', 'message' => $message]);
    }

    /** Zera o link pendente da empresa (fluxo concluído). */
    private function limparLink(Company $company): void
    {
        $company->update([
            'shopee_link_generated_at' => null,
            'shopee_link_url'          => null,
        ]);
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
        // Remove os dois tokens (ERP + Ads) da empresa.
        $company->shopeeTokens()->delete();

        Log::info("[Shopee] Tokens removidos empresa {$company->id} ({$company->name}) por " . auth()->user()?->name);

        return back()->with('success', 'Conexão com Shopee removida.');
    }
}
