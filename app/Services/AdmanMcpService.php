<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cliente da API MCP do Adman (https://mcp.ad-man.io/v1/mcp) — separada da REST
 * legada usada em AdmanService. Usa protocolo JSON-RPC 2.0 sobre HTTP.
 *
 * Existe pra resolver a limitação descrita em project_adman_adgroup_vs_mlb:
 * a MCP retorna métricas MLB-level confiáveis (clicks/ads vs orgânico, direto vs
 * indireto) que a REST legada não tem ou retorna com inconsistências.
 *
 * Hoje só é consumido pelo drilldown "MLBs dentro do adgroup" no SugadorController.
 */
class AdmanMcpService
{
    private string $url;
    private string $apiKey;
    private string $marketplace = 'meli';

    public function __construct()
    {
        $this->url    = rtrim(config('services.adman_mcp.url', ''), '/');
        $this->apiKey = config('services.adman_mcp.api_key', '');
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->apiKey !== '';
    }

    /**
     * Executa um tools/call na MCP. Retorna o `result` desempacotado
     * (preferindo structuredContent quando disponível).
     *
     * @throws \RuntimeException em erro de rede, 4xx/5xx, ou isError=true do MCP
     */
    public function call(string $toolName, array $arguments): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Adman MCP não configurada (services.adman_mcp.url/api_key).');
        }

        // Phase 30 fix W1 — Rate limiter LOCAL global aplicado a TODA chamada à
        // Adman MCP (caminho síncrono via SugadorController + caminho async via
        // FetchAdmanMlbsByCampaignJob). Antes só Jobs tinham proteção via middleware
        // RateLimited; caminho síncrono estourava 429 facilmente — o middleware do
        // Plan 30-01 não cobria controller. Bucket 'adman-api' (8/min global) está
        // registrado em AppServiceProvider e compartilhado entre workers via Redis.
        // Se estourar, throw RuntimeException com retry-after — controller mostra
        // mensagem amigável ao usuário em vez de "status 429" cru.
        if (RateLimiter::tooManyAttempts('adman-api', 8)) {
            $availableIn = RateLimiter::availableIn('adman-api');
            throw new \RuntimeException(
                "Limite Adman MCP atingido (8 req/min globais). Tente novamente em {$availableIn}s. "
                . "Workers em paralelo podem estar consumindo a janela."
            );
        }
        RateLimiter::hit('adman-api', 60);

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => random_int(1, 1_000_000),
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $toolName,
                'arguments' => $arguments,
            ],
        ];

        // Retry em 429/5xx — a MCP tem rate limit real de 10 req/min/key
        // (confirmado pela Adman na Phase 16, não 50/min como o comentário antigo
        // dizia). Devolvido em 429 HTTP ou empacotado no payload como isError.
        // Pra 429 dormimos a janela inteira (60s) — backoff curto não ajuda já
        // que outras chamadas nesta mesma janela continuam batendo no mesmo limite.
        $maxAttempts = 6;
        $lastBody    = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // O servidor MCP da Adman tem TLS handshake lentíssimo (medido 4–26s
            // do nosso VPS, vs <0.1s pra api.ad-man.io/google). Desligar ALPN
            // corta o tempo pela metade (38s→15s nos testes). Keep-alive
            // tenta evitar handshake em chamadas subsequentes da mesma
            // execução de paginação.
            $response = Http::withHeaders([
                'integrator-api-key' => $this->apiKey,
                'Content-Type'       => 'application/json',
                'Accept'             => 'application/json, text/event-stream',
            ])
                ->withOptions([
                    'curl' => [
                        CURLOPT_SSL_ENABLE_ALPN => false,
                        CURLOPT_TCP_KEEPALIVE   => 1,
                    ],
                ])
                ->connectTimeout(60)
                ->timeout(120)
                ->post($this->url, $payload);

            $status      = $response->status();
            $isTransient = $status === 429 || ($status >= 500 && $status < 600);

            if ($response->successful()) {
                $json = $response->json() ?? [];

                if (isset($json['error'])) {
                    throw new \RuntimeException("Adman MCP erro JSON-RPC: " . ($json['error']['message'] ?? json_encode($json['error'])));
                }

                $result = $json['result'] ?? [];

                if (!empty($result['isError'])) {
                    $msg = $result['content'][0]['text'] ?? 'erro desconhecido';
                    $lastBody = $msg;
                    // 5xx e 429 (rate limit) reportados dentro do payload do MCP
                    // são transitórios — vale tentar de novo.
                    $isRateLimit = str_contains($msg, 'status code 429');
                    $isServer    = str_contains($msg, 'status code 5');
                    if ($attempt < $maxAttempts && ($isServer || $isRateLimit)) {
                        Log::info("[AdmanMcp] {$toolName} {$msg} — retry {$attempt}/{$maxAttempts}");
                        $this->sleepBackoff($attempt, $isRateLimit ? 65 : 0);
                        continue;
                    }
                    throw new \RuntimeException("Adman MCP tool {$toolName} erro: {$msg}");
                }

                return $result;
            }

            $lastBody = trim(substr($response->body(), 0, 300));

            if (!$isTransient || $attempt === $maxAttempts) {
                throw new \RuntimeException("Adman MCP HTTP {$status} em {$toolName}: {$lastBody}");
            }

            $retryAfter = (int) ($response->header('Retry-After') ?? 0);
            $this->sleepBackoff($attempt, $retryAfter);
        }

        // Inalcançável (o loop sempre retorna ou lança), mas mantém o type-checker feliz
        throw new \RuntimeException("Adman MCP {$toolName}: esgotou tentativas. Último: {$lastBody}");
    }

    private function sleepBackoff(int $attempt, int $retryAfterSeconds = 0): void
    {
        // Pra rate limit (retryAfterSeconds=65) dormimos a janela inteira do
        // Adman (60s) — clampar a 30s aqui só fazia o retry imediato cair no
        // mesmo limite. Pra retry transitório sem hint, backoff exponencial.
        $secs = $retryAfterSeconds > 0 ? min($retryAfterSeconds, 65) : (2 ** ($attempt - 1));
        sleep($secs);
    }

    /**
     * Busca productAds de uma conta no range, paginando até `maxPages` ou esgotar.
     * Retorna array com chaves `items` (productAds brutos) e `truncated` (bool).
     *
     * Cache de 30 min — TLS handshake do MCP é ~15s/chamada do nosso VPS, então
     * paginação completa demora vários minutos. Limite default de 16 páginas
     * (800 MLBs) cabe em ~4 min, dentro do fastcgi_read_timeout=300s do nginx.
     */
    public function fetchAllProductAds(string $custId, string $dateFrom, string $dateTo, int $itemsPerPage = 50, int $maxPages = 8, ?string $progressCacheKey = null, int $startPage = 1): array
    {
        // Phase 30 D-03 — cacheKey AGNÓSTICA ao startPage: o cache representa
        // "varredura completa conhecida" e quem consome (SugadorController::mlbs
        // via cachedFullScanIfReady) sempre busca pelo (custId, dateFrom, dateTo,
        // maxPages). Se incluíssemos startPage na key, continuações nunca
        // encontrariam o cache do snapshot final.
        $cacheKey = sprintf('adman_mcp:productads:%s:%s:%s:%d', $custId, $dateFrom, $dateTo, $maxPages);

        // MCP da Adman tem rate limit real 10 req/min/key (confirmado Phase 16 —
        // comentário antigo dizia 50/min e estava errado). Múltiplas chamadas
        // concorrentes para o mesmo custId (drilldown de N adgroups da mesma
        // empresa ao mesmo tempo) estouram em getMarketplaceadsCustIdproductAdsmetrics.
        // Lock por custId serializa — a paginação interna agora tem throttle
        // de 6.5s/página (= ~9 req/min, dentro do limite de 10/min).
        //
        // Por que lock por custId (não global): permite paralelismo entre contas distintas,
        // onde o rate limit é independente.
        //
        // Por que 90s no block: paginação típica 8 páginas × 6.5s = 52s + folga TLS.
        // Se travar (conta muito grande), próxima chamada cai com RuntimeException e o
        // usuário retenta — risco baixo (T-19-06 accepted no threat model).
        //
        // Por que NÃO envolve listCampaigns: cache de 1h cobre o caso comum e a
        // chamada é leve (1 endpoint, sem paginação pesada).
        //
        // Driver compatível: database (Laravel 12 DatabaseLock — CACHE_STORE=database em prod).
        $lockKey = "adman_mcp:custid:{$custId}";
        $lock    = Cache::lock($lockKey, 90);

        try {
            return $lock->block(90, function () use ($cacheKey, $custId, $dateFrom, $dateTo, $itemsPerPage, $maxPages, $progressCacheKey, $startPage) {
                return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($custId, $dateFrom, $dateTo, $itemsPerPage, $maxPages, $progressCacheKey, $startPage) {
                    $itemsPerPage = min($itemsPerPage, 50); // cap da Adman
                    $all        = [];
                    // Phase 30 D-03 — $page inicializado com $startPage. Default=1
                    // preserva comportamento original; continuações via Job passam
                    // startPage = pages_read+1 do checkpoint anterior.
                    $page       = $startPage;
                    $totalPages = 1;
                    $startedAt  = microtime(true);

                    do {
                        $result = $this->call('getMarketplaceadsCustIdproductAdsmetrics', [
                            'marketplace'  => $this->marketplace,
                            'custId'       => $custId,
                            'dateFrom'     => $dateFrom,
                            'dateTo'       => $dateTo,
                            'page'         => $page,
                            'itemsPerPage' => $itemsPerPage,
                        ]);

                        $sc          = $result['structuredContent'] ?? [];
                        $productAds  = $sc['productAds'] ?? [];
                        $totalPages  = (int) ($sc['totalPages'] ?? 1);

                        foreach ($productAds as $ad) {
                            $all[] = $ad;
                        }

                        // Progresso opcional: o caller (FetchAdmanMlbsByCampaignJob) passa
                        // a chave de status pra UI poder mostrar "X/Y páginas lidas". Sem
                        // isso, durante scans de 30min a UI ficava silenciosa.
                        if ($progressCacheKey !== null) {
                            Cache::put($progressCacheKey, [
                                'status'      => 'running',
                                'pages_read'  => $page,
                                'total_pages' => $totalPages,
                                'items_count' => count($all),
                                'started_at'  => date('c', (int) $startedAt),
                                'updated_at'  => now()->toIso8601String(),
                            ], now()->addMinutes(35));
                        }

                        $page++;
                        // Phase 30 D-02 — throttle interno removido. O RateLimited
                        // middleware do FetchAdmanMlbsByCampaignJob (RateLimiter
                        // 'adman-api' = 8/min global via Redis) é dono do controle
                        // de taxa. Workers concorrentes agora compartilham bucket
                        // — antes cada um respeitava 9/min isoladamente e juntos
                        // estouravam 18/min, gerando 429 com o hard limit 10/min/key.
                        // Retry em 429 do call() (linhas 64-126) segue como safety net.
                    } while ($page <= $totalPages && $page <= $maxPages);

                    return [
                        'items'       => $all,
                        'truncated'   => $totalPages > $maxPages,
                        'pages_read'  => $page - 1,
                        'total_pages' => $totalPages,
                    ];
                });
            });
        } catch (LockTimeoutException $e) {
            // Timeout de 30s: paginação típica 16 págs × 1.5s = 24s + folga TLS.
            // Se chegar aqui, conta muito grande ou MCP lento — caller exibe erro ao usuário.
            throw new \RuntimeException(
                "Adman MCP ocupado para a conta {$custId} — tente novamente em alguns segundos."
            );
        }
    }

    /**
     * Lista campanhas (campaignId + name) de uma conta. Cache de 1h porque
     * raramente muda durante uma sessão.
     */
    public function listCampaigns(string $custId): array
    {
        $cacheKey = "adman_mcp:campaigns:{$custId}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($custId) {
            $all  = [];
            $page = 1;

            do {
                $result = $this->call('getMarketplaceadsCustIdcampaigns', [
                    'marketplace' => $this->marketplace,
                    'custId'      => $custId,
                    'page'        => $page,
                ]);

                $sc         = $result['structuredContent'] ?? [];
                $campaigns  = $sc['campaigns'] ?? [];
                $totalPages = (int) ($sc['totalPages'] ?? 1);

                foreach ($campaigns as $c) {
                    $all[] = [
                        'campaign_id'   => (string) ($c['campaignId'] ?? ''),
                        'campaign_name' => $c['name']   ?? null,
                        'status'        => $c['status'] ?? null,
                        'budget'        => $c['budget'] ?? null,
                    ];
                }

                $page++;
                // Phase 30 D-02 — throttle interno removido. RateLimited middleware
                // global ('adman-api' 8/min via Redis) controla a taxa nos Jobs que
                // chamam listCampaigns indiretamente. Chamadas síncronas continuam
                // protegidas pelo cache de 1h (linha 249) que evita re-busca.
            } while ($page <= $totalPages);

            return $all;
        });
    }

    /**
     * MLBs (productAds) de uma campanha específica no período, normalizados pro
     * formato consumido pelo frontend do Sugador.
     *
     * Estratégia: getMarketplaceadsCustIdproductAdsmetrics não aceita filtro por
     * campaignId — só por listingId — e cada productAd traz `campaignName` (não
     * id). Então: busca tudo, descobre o name a partir do id via listCampaigns
     * (cacheada), e filtra localmente por campaignName.
     */
    public function fetchMlbsByCampaign(string $custId, string $campaignId, string $dateFrom, string $dateTo): array
    {
        $campaigns = $this->listCampaigns($custId);
        $campaign  = collect($campaigns)->firstWhere('campaign_id', $campaignId);
        $campaignName = $campaign['campaign_name'] ?? null;

        if (!$campaignName) {
            Log::warning("[AdmanMcp] Campanha {$campaignId} não encontrada na conta {$custId} — drilldown vazio.");
            return ['mlbs' => [], 'truncated' => false, 'pages_read' => 0, 'total_pages' => 0];
        }

        $result = $this->fetchAllProductAds($custId, $dateFrom, $dateTo);

        $filtered = array_values(array_filter($result['items'], fn($ad) => ($ad['campaignName'] ?? null) === $campaignName));

        return [
            'mlbs'        => array_map(fn($ad) => $this->normalizeProductAd($ad), $filtered),
            'truncated'   => $result['truncated'],
            'pages_read'  => $result['pages_read'],
            'total_pages' => $result['total_pages'],
        ];
    }

    /**
     * Retorna o resultado do full-scan (maxPages=1000) se já estiver cacheado.
     * Sem fallback: se não tiver cache, retorna null (chamador decide o que fazer).
     * Usado pelo SugadorController pra checar se já tem varredura completa pronta
     * antes de cair no fetch síncrono de 16 páginas.
     */
    public function cachedFullScanIfReady(string $custId, string $dateFrom, string $dateTo, int $maxPages = 1000): ?array
    {
        $cacheKey = sprintf('adman_mcp:productads:%s:%s:%s:%d', $custId, $dateFrom, $dateTo, $maxPages);
        return Cache::get($cacheKey);
    }

    /**
     * Filtra um conjunto pré-carregado de productAds pela campanha + normaliza.
     * Usado quando já temos o cache do full-scan (via cachedFullScanIfReady) e
     * só precisamos reaplicar o filtro de campanha — sem rede.
     */
    public function filterMlbsByCampaignFromItems(array $items, string $campaignId, string $custId): array
    {
        $campaigns    = $this->listCampaigns($custId);
        $campaign     = collect($campaigns)->firstWhere('campaign_id', $campaignId);
        $campaignName = $campaign['campaign_name'] ?? null;

        if (!$campaignName) {
            return ['mlbs' => [], 'truncated' => false, 'pages_read' => null, 'total_pages' => null];
        }

        $filtered = array_values(array_filter($items, fn($ad) => ($ad['campaignName'] ?? null) === $campaignName));

        return [
            'mlbs'        => array_map(fn($ad) => $this->normalizeProductAd($ad), $filtered),
            'truncated'   => false,
            'pages_read'  => null,
            'total_pages' => null,
        ];
    }

    /**
     * Achata o formato `{value, diff, prev}` da MCP e expõe só os campos que
     * a UI do Sugador consome.
     */
    private function normalizeProductAd(array $ad): array
    {
        $val = fn($key) => is_array($ad[$key] ?? null)
            ? ($ad[$key]['value'] ?? null)
            : ($ad[$key] ?? null);

        return [
            'listing_id'             => $ad['listingId']    ?? null,
            'sku'                    => $ad['sku']          ?? null,
            'title'                  => $ad['title']        ?? null,
            'permalink'              => $ad['permalink']    ?? null,
            'image_url'              => $ad['imageUrl']     ?? null,
            'status'                 => $ad['status']       ?? null,
            'catalog'                => (bool) ($ad['catalog'] ?? false),
            'campaign_name'          => $ad['campaignName'] ?? null,
            'curve'                  => $ad['curve']        ?? null,

            'investment'             => $this->num($val('investment')),
            'impressions'            => $this->int($val('impressions')),
            'clicks'                 => $this->int($val('clicks')),
            'cpc'                    => $this->num($val('cpc')),
            'ctr'                    => $this->num($val('ctr')),

            'revenue'                => $this->num($val('revenue')),
            'ads_revenue'            => $this->num($val('adsRevenue')),
            'organic_revenue'        => $this->num($val('organicRevenue')),

            'sold_quantity'          => $this->int($val('soldQuantity')),
            'ads_sold_quantity'      => $this->int($val('adsSoldQuantity')),
            'ads_sold_direct'        => $this->int($val('adsSoldQuantityDirect')),
            'ads_sold_indirect'      => $this->int($val('adsSoldQuantityIndirect')),
            'organic_sold_quantity'  => $this->int($val('organicSoldQuantity')),

            'acos'                   => $this->num($val('acos')),
            'tacos'                  => $this->num($val('tacos')),
            'ads_contribution'       => $this->num($val('adsContribution')),

            'stock'                  => $this->int($val('stock')),
            'days_with_stock'        => $this->int($val('daysWithStock')),
            'profit_margin'          => $this->num($val('profitMargin')),
        ];
    }

    private function num($v): ?float { return $v === null ? null : (float) $v; }
    private function int($v): ?int   { return $v === null ? null : (int)   $v; }
}
