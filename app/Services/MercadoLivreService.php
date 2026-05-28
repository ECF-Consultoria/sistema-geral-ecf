<?php

namespace App\Services;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\MlToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integração direta com a API oficial do Mercado Livre.
 * Gerencia o ciclo OAuth (auth URL, code exchange, token refresh)
 * e todos os endpoints de dados: pedidos, publicidade, billing e campanhas.
 */
class MercadoLivreService
{
    private const AUTH_URL  = 'https://auth.mercadolivre.com.br/authorization';
    private const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';
    private const API_BASE  = 'https://api.mercadolibre.com';
    private const STATE_TTL = 900;   // 15 minutos para o cliente autorizar
    private const SITE_ID   = 'MLB'; // Brasil

    // ═══ OAuth: geração de URL ════════════════════════════════════════════════

    /**
     * Gera URL de autorização OAuth para a empresa informada.
     * Armazena state no cache para vinculação no callback.
     */
    public function buildAuthUrl(Company $company): string
    {
        $state = Str::uuid()->toString();

        // PKCE: code_verifier aleatório → code_challenge = BASE64URL(SHA256(verifier))
        $codeVerifier  = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        Cache::put("ml_oauth_state_{$state}", [
            'company_id'    => $company->id,
            'code_verifier' => $codeVerifier,
        ], self::STATE_TTL);

        return self::AUTH_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => config('services.mercadolivre.client_id'),
            'redirect_uri'          => config('services.mercadolivre.redirect'),
            'state'                 => $state,
            // read: dados da conta; offline_access: mantém refresh token válido indefinidamente
            'scope'                 => 'read offline_access',
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Recupera os dados vinculados ao state e remove do cache.
     * Retorna array ['company_id' => int, 'code_verifier' => string] ou null se inválido/expirado.
     */
    public function consumeState(string $state): ?array
    {
        $key  = "ml_oauth_state_{$state}";
        $data = Cache::get($key);
        Cache::forget($key);

        if (! $data) {
            return null;
        }

        // Compatibilidade: versão anterior armazenava apenas o int
        if (is_int($data)) {
            return ['company_id' => $data, 'code_verifier' => null];
        }

        return $data;
    }

    // ═══ OAuth: troca e renovação de tokens ══════════════════════════════════

    /**
     * Troca o authorization code por access_token + refresh_token.
     *
     * @throws \RuntimeException
     */
    public function exchangeCode(string $code, ?string $codeVerifier = null): array
    {
        $payload = [
            'grant_type'    => 'authorization_code',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
            'code'          => $code,
            'redirect_uri'  => config('services.mercadolivre.redirect'),
        ];

        if ($codeVerifier) {
            $payload['code_verifier'] = $codeVerifier;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('[MercadoLivre] Falha ao trocar código: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Salva (cria ou atualiza) o token da empresa.
     * O ML renova o refresh_token a cada chamada — sempre substituir ambos.
     */
    public function saveToken(Company $company, array $data): MlToken
    {
        return MlToken::updateOrCreate(
            ['company_id' => $company->id],
            [
                'ml_user_id'        => (string) $data['user_id'],
                'access_token'      => $data['access_token'],
                'refresh_token'     => $data['refresh_token'],
                'token_type'        => $data['token_type'] ?? 'bearer',
                'scope'             => $data['scope'] ?? null,
                'expires_at'        => now()->addSeconds($data['expires_in'] - 60),
                'last_refreshed_at' => now(),
                'status'            => 'active',
                'connected_at'      => now(),
            ]
        );
    }

    /**
     * Renova o access_token via refresh_token.
     * ML também gera um novo refresh_token — ambos são substituídos.
     *
     * @throws \RuntimeException se o refresh foi revogado
     */
    public function refreshToken(MlToken $token): MlToken
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
            'refresh_token' => $token->refresh_token,
        ]);

        if (! $response->successful()) {
            $token->update(['status' => 'revoked']);
            Log::warning('[MercadoLivre] Refresh token revogado', [
                'company_id' => $token->company_id,
                'response'   => $response->body(),
            ]);
            throw new \RuntimeException('[MercadoLivre] Refresh token inválido — empresa precisa reconectar.');
        }

        $data = $response->json();

        $token->update([
            'access_token'      => $data['access_token'],
            'refresh_token'     => $data['refresh_token'],
            'expires_at'        => now()->addSeconds($data['expires_in'] - 60),
            'last_refreshed_at' => now(),
            'status'            => 'active',
        ]);

        return $token->fresh();
    }

    // ═══ Token: helpers ═══════════════════════════════════════════════════════

    /**
     * Retorna token válido da empresa, renovando se necessário.
     * Retorna null se sem token ou revogado.
     */
    public function ensureValidToken(Company $company): ?MlToken
    {
        $token = $company->mlToken ?? MlToken::where('company_id', $company->id)->first();

        if (! $token || $token->status === 'revoked') {
            return null;
        }

        if ($token->expiresSoon(5)) {
            try {
                $token = $this->refreshToken($token);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $token;
    }

    // ═══ HTTP: chamada autenticada ════════════════════════════════════════════

    /**
     * GET autenticado à API ML com renovação automática de token.
     *
     * @throws \RuntimeException
     */
    public function get(Company $company, string $endpoint, array $query = []): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
        }

        $response = Http::withToken($token->access_token)
            ->get(self::API_BASE . $endpoint, $query);

        if ($response->status() === 401) {
            $token->update(['status' => 'revoked']);
            Log::error("[MercadoLivre] Token rejeitado empresa {$company->id} ({$company->name})");
            throw new \RuntimeException("[MercadoLivre] Token inválido para {$company->name}.");
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "[MercadoLivre] Erro {$response->status()} em {$endpoint}: {$response->body()}"
            );
        }

        return $response->json();
    }

    // ═══ Dados: conta ═════════════════════════════════════════════════════════

    /**
     * Informações básicas da conta ML autenticada.
     * Útil para confirmar que o token vinculado é da conta correta.
     *
     * @return array{id, nickname, email, seller_reputation, ...}
     */
    public function fetchUserInfo(Company $company): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
        }

        return $this->get($company, "/users/{$token->ml_user_id}");
    }

    // ═══ Dados: pedidos (faturamento bruto) ═══════════════════════════════════

    /**
     * Busca todos os pedidos pagos do período e retorna totais agregados.
     * Faz paginação automática — uma chamada por 50 pedidos.
     *
     * @return array{
     *   revenue: float,          // soma de total_amount (faturamento bruto)
     *   sold_quantity: int,       // total de itens vendidos
     *   orders_count: int,        // número de pedidos
     *   sales_fee: float,         // soma de sale_fee (comissão ML)
     *   raw_orders: array,        // primeiros 200 pedidos raw (para auditoria)
     * }
     */
    public function fetchOrdersSummary(Company $company, string $dateFrom, string $dateTo): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
        }

        $sellerId = $token->ml_user_id;
        $limit    = 50;
        $offset   = 0;
        $allOrders = [];

        // ISO 8601 com timezone BRT (UTC-3)
        $from = $dateFrom . 'T00:00:00.000-03:00';
        $to   = $dateTo   . 'T23:59:59.000-03:00';

        do {
            $data = $this->get($company, '/orders/search', [
                'seller'                       => $sellerId,
                'order.status'                 => 'paid',
                'order.date_created.from'      => $from,
                'order.date_created.to'        => $to,
                'sort'                         => 'date_asc',
                'limit'                        => $limit,
                'offset'                       => $offset,
            ]);

            $results = $data['results'] ?? [];
            $allOrders = array_merge($allOrders, $results);
            $offset   += $limit;
            $total    = $data['paging']['total'] ?? 0;

        } while ($offset < $total && $offset < 1000); // max 1000 pedidos por dia (segurança)

        // Agrega totais
        $revenue      = 0.0;
        $soldQty      = 0;
        $salesFee     = 0.0;

        foreach ($allOrders as $order) {
            $revenue  += (float) ($order['total_amount'] ?? 0);
            $salesFee += (float) ($order['sale_fee']    ?? 0);

            foreach ($order['order_items'] ?? [] as $item) {
                $soldQty += (int) ($item['quantity'] ?? 0);
            }
        }

        return [
            'revenue'       => round($revenue, 2),
            'sold_quantity' => $soldQty,
            'orders_count'  => count($allOrders),
            'sales_fee'     => round($salesFee, 2),
            'raw_orders'    => array_slice($allOrders, 0, 200),
        ];
    }

    // ═══ Dados: publicidade (Product Ads) ════════════════════════════════════

    /**
     * Resumo de publicidade no nível da conta para o período.
     * Soma todos os investimentos e receitas de anúncios.
     *
     * @return array{
     *   ad_spend: float,          // investimento total em publicidade
     *   ad_revenue: float,        // receita total atribuída a anúncios
     *   clicks: int,
     *   impressions: int,
     *   acos: float|null,         // ad_spend / ad_revenue * 100
     *   roas: float|null,
     *   cpc: float|null,
     *   sold_quantity: int,       // unidades vendidas via anúncios
     * }
     */
    public function fetchAdsSummary(Company $company, string $dateFrom, string $dateTo): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
        }

        $advertiserId = $token->ml_user_id;

        $data = $this->get(
            $company,
            "/marketplace/advertising/" . self::SITE_ID . "/advertisers/{$advertiserId}/product_ads/campaigns/search",
            [
                'date_from'        => $dateFrom,
                'date_to'          => $dateTo,
                'aggregation_type' => 'total',
                'metrics'          => 'cost,clicks,prints,total_amount,direct_amount,indirect_amount,acos,roas,cpc,units_quantity',
                'limit'            => 100,
                'offset'           => 0,
            ]
        );

        $campaigns = $data['results'] ?? [];

        // Agrega métricas de todas as campanhas no período
        $adSpend    = 0.0;
        $adRevenue  = 0.0;
        $clicks     = 0;
        $impressions = 0;
        $soldQty    = 0;

        foreach ($campaigns as $c) {
            $m = $c['metrics'] ?? $c;
            $adSpend    += (float) ($m['cost']           ?? 0);
            $adRevenue  += (float) ($m['total_amount']   ?? 0);
            $clicks     += (int)   ($m['clicks']         ?? 0);
            $impressions += (int)  ($m['prints']         ?? 0);
            $soldQty    += (int)   ($m['units_quantity'] ?? 0);
        }

        $acos = $adRevenue > 0 ? round(($adSpend / $adRevenue) * 100, 4) : null;
        $roas = $adSpend  > 0 ? round($adRevenue / $adSpend, 4)          : null;
        $cpc  = $clicks   > 0 ? round($adSpend / $clicks, 4)             : null;

        return [
            'ad_spend'     => round($adSpend, 2),
            'ad_revenue'   => round($adRevenue, 2),
            'clicks'       => $clicks,
            'impressions'  => $impressions,
            'acos'         => $acos,
            'roas'         => $roas,
            'cpc'          => $cpc,
            'sold_quantity' => $soldQty,
        ];
    }

    /**
     * Lista campanhas ativas com métricas do período.
     * Alimenta a tabela adman_campaign_metrics.
     *
     * @return array[] lista de campanhas com campos:
     *   id, name, status, cost, total_amount, acos, roas, cpc, clicks, prints, units_quantity
     */
    public function fetchCampaigns(Company $company, string $dateFrom, string $dateTo): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
        }

        $advertiserId = $token->ml_user_id;
        $campaigns    = [];
        $offset       = 0;
        $limit        = 50;

        do {
            $data = $this->get(
                $company,
                "/marketplace/advertising/" . self::SITE_ID . "/advertisers/{$advertiserId}/product_ads/campaigns/search",
                [
                    'date_from'        => $dateFrom,
                    'date_to'          => $dateTo,
                    'aggregation_type' => 'total',
                    'metrics'          => 'cost,clicks,prints,total_amount,acos,roas,cpc,units_quantity',
                    'limit'            => $limit,
                    'offset'           => $offset,
                ]
            );

            $results    = $data['results'] ?? [];
            $campaigns  = array_merge($campaigns, $results);
            $offset    += $limit;
            $total      = $data['paging']['total'] ?? 0;

        } while ($offset < $total && $offset < 500);

        return $campaigns;
    }

    /**
     * Métricas diárias de anúncios individuais (Product Ads).
     * Usado para detecção de sugadores — equivale ao fetchAdsMetrics da Adman.
     *
     * @return array[] lista de anúncios com métricas
     */
    public function fetchAdsItems(Company $company, string $dateFrom, string $dateTo): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
        }

        $advertiserId = $token->ml_user_id;
        $items        = [];
        $offset       = 0;
        $limit        = 50;

        do {
            $data = $this->get(
                $company,
                "/advertising/advertisers/{$advertiserId}/product_ads/items",
                [
                    'date_from'        => $dateFrom,
                    'date_to'          => $dateTo,
                    'aggregation_type' => 'total',
                    'metrics'          => 'cost,clicks,prints,total_amount,direct_amount,indirect_amount,acos,roas,cpc,units_quantity,ctr',
                    'limit'            => $limit,
                    'offset'           => $offset,
                ]
            );

            $results = $data['results'] ?? [];
            $items   = array_merge($items, $results);
            $offset += $limit;
            $total   = $data['paging']['total'] ?? 0;

        } while ($offset < $total && $offset < 2000);

        return $items;
    }

    /**
     * Detalhamento de cobranças ML do mês (faturamento, taxas, frete).
     * Fonte: Billing Integration API — atualizado 1x/dia às 10h BRT.
     *
     * @param  string $yearMonth  Formato YYYY-MM (ex: "2026-05")
     * @return array{charges: array, totals: array{cv: float, cxd: float, pads: float}}
     */
    public function fetchBilling(Company $company, string $yearMonth): array
    {
        // Billing API usa primeiro dia do mês como chave
        $periodKey = $yearMonth . '-01';

        $data = $this->get(
            $company,
            "/billing/integration/periods/key/{$periodKey}/group/ML/details"
        );

        $charges = $data['results'] ?? $data['charges'] ?? [];

        // Agrupa por tipo de cobrança
        // CV = comissão de venda, CXD = Mercado Envios, PADS = publicidade
        $totals = ['cv' => 0.0, 'cxd' => 0.0, 'pads' => 0.0];

        foreach ($charges as $charge) {
            $type   = strtolower($charge['charge_type'] ?? $charge['type'] ?? '');
            $amount = (float) ($charge['amount'] ?? $charge['total'] ?? 0);

            if (str_contains($type, 'cv'))   $totals['cv']   += $amount;
            if (str_contains($type, 'cxd'))  $totals['cxd']  += $amount;
            if (str_contains($type, 'pads')) $totals['pads'] += $amount;
        }

        return [
            'charges' => $charges,
            'totals'  => [
                'cv'   => round(abs($totals['cv']), 2),    // taxas de venda (positivo)
                'cxd'  => round(abs($totals['cxd']), 2),  // custo de envio
                'pads' => round(abs($totals['pads']), 2), // investimento ads (cruzar com fetchAdsSummary)
            ],
        ];
    }

    // ═══ Sync: salva dados nas tabelas do sistema ═════════════════════════════

    /**
     * Sincroniza uma empresa para a data informada.
     * Busca pedidos + resumo de publicidade e salva em adman_metrics
     * e adman_campaign_metrics (compatibilidade com o dashboard atual).
     *
     * @throws \RuntimeException se a empresa não tiver token ML
     */
    public function syncCompany(Company $company, string $date): AdmanMetric
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException(
                "[MercadoLivre] Empresa {$company->id} ({$company->name}) sem token ML ativo."
            );
        }

        Log::info("[MercadoLivre] Iniciando sync empresa {$company->id} ({$company->name}) data {$date}");

        // ── Pedidos do dia ──────────────────────────────────────────────────
        $orders = $this->fetchOrdersSummary($company, $date, $date);

        // ── Publicidade do dia ──────────────────────────────────────────────
        $ads = $this->fetchAdsSummary($company, $date, $date);

        // ── TACOS = investimento / faturamento bruto ────────────────────────
        $tacos = $orders['revenue'] > 0
            ? round(($ads['ad_spend'] / $orders['revenue']) * 100, 4)
            : null;

        // ── Salva/atualiza adman_metrics ────────────────────────────────────
        $metric = AdmanMetric::updateOrCreate(
            ['company_id' => $company->id, 'reference_date' => $date],
            [
                'revenue'       => $orders['revenue'],
                'sold_quantity' => $orders['sold_quantity'],
                'sales_fee'     => $orders['sales_fee'],
                'ad_spend'      => $ads['ad_spend'],
                'tacos'         => $tacos,
                // Campos não disponíveis diretamente via ML API (exigiriam CMV do seller)
                'net_billing'   => $orders['revenue'] - $orders['sales_fee'],
                'raw_data'      => ['source' => 'ml_direct', 'orders' => $orders, 'ads' => $ads],
                'synced_at'     => now(),
            ]
        );

        // ── Campanhas ───────────────────────────────────────────────────────
        try {
            $this->syncCampaigns($company, $date);
        } catch (\Throwable $e) {
            // Campanhas são best-effort — não abortar o sync principal
            Log::warning("[MercadoLivre] Falha ao sync campanhas empresa {$company->id}: {$e->getMessage()}");
        }

        // ── Log de sync ─────────────────────────────────────────────────────
        $wasNew = $metric->wasRecentlyCreated;
        AdmanSyncLog::create([
            'company_id'    => $company->id,
            'created_count' => $wasNew ? 1 : 0,
            'updated_count' => $wasNew ? 0 : 1,
            'skipped_count' => 0,
            'synced_at'     => now(),
        ]);

        Log::info("[MercadoLivre] Sync empresa {$company->id} ({$company->name}) concluído — revenue={$orders['revenue']}, ad_spend={$ads['ad_spend']}, tacos={$tacos}");

        return $metric;
    }

    /**
     * Sincroniza campanhas de uma empresa para a data informada.
     * Salva em adman_campaign_metrics (compatível com dashboard atual).
     */
    public function syncCampaigns(Company $company, string $date): int
    {
        $campaigns = $this->fetchCampaigns($company, $date, $date);
        $saved     = 0;

        foreach ($campaigns as $c) {
            $m = $c['metrics'] ?? $c;

            AdmanCampaignMetric::updateOrCreate(
                [
                    'company_id'     => $company->id,
                    'reference_date' => $date,
                    'campaign_id'    => (string) ($c['id'] ?? $c['campaign_id'] ?? ''),
                ],
                [
                    'campaign_name'   => $c['name'] ?? $c['campaign_name'] ?? '',
                    'campaign_status' => $c['status'] ?? 'unknown',
                    'investment'      => (float) ($m['cost']           ?? 0),
                    'revenue'         => (float) ($m['total_amount']   ?? 0),
                    'acos'            => (float) ($m['acos']           ?? 0) ?: null,
                    'roas'            => (float) ($m['roas']           ?? 0) ?: null,
                    'cpc'             => (float) ($m['cpc']            ?? 0) ?: null,
                    'clicks'          => (int)   ($m['clicks']         ?? 0),
                    'impressions'     => (int)   ($m['prints']         ?? 0),
                    'sold_quantity'   => (int)   ($m['units_quantity'] ?? 0),
                    'synced_at'       => now(),
                ]
            );

            $saved++;
        }

        return $saved;
    }
}
