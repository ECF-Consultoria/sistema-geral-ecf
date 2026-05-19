<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => random_int(1, 1_000_000),
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $toolName,
                'arguments' => $arguments,
            ],
        ];

        // Retry exponencial em 429/5xx — a MCP tem rate limit de 50 req/min e
        // ocasionalmente devolve 500 transitórios.
        $maxAttempts = 4;
        $lastBody    = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::withHeaders([
                'integrator-api-key' => $this->apiKey,
                'Content-Type'       => 'application/json',
                'Accept'             => 'application/json, text/event-stream',
            ])
                ->connectTimeout(15)
                ->timeout(90)
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
                    // 500 reportado dentro do payload do MCP também é transitório
                    if ($attempt < $maxAttempts && str_contains($msg, 'status code 5')) {
                        $this->sleepBackoff($attempt);
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
        $secs = $retryAfterSeconds > 0 ? min($retryAfterSeconds, 30) : (2 ** ($attempt - 1));
        sleep($secs);
    }

    /**
     * Busca todos os productAds de uma conta no range, paginando até esgotar.
     * Retorna o array bruto da MCP (cada item é um productAd).
     *
     * Resultado é cacheado por 30 min — contas grandes consomem >1min em requests
     * (rate limit 50/min, ~52 páginas), e o user normalmente abre vários adgroups
     * da mesma empresa em sequência.
     */
    public function fetchAllProductAds(string $custId, string $dateFrom, string $dateTo, int $itemsPerPage = 50): array
    {
        $cacheKey = sprintf('adman_mcp:productads:%s:%s:%s', $custId, $dateFrom, $dateTo);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($custId, $dateFrom, $dateTo, $itemsPerPage) {
            $itemsPerPage = min($itemsPerPage, 50); // cap da Adman
            $all  = [];
            $page = 1;

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

                $page++;
                if ($page <= $totalPages) usleep(150_000);
            } while ($page <= $totalPages);

            return $all;
        });
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
                if ($page <= $totalPages) usleep(150_000);
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
            return [];
        }

        $allAds = $this->fetchAllProductAds($custId, $dateFrom, $dateTo);

        $filtered = array_values(array_filter($allAds, fn($ad) => ($ad['campaignName'] ?? null) === $campaignName));

        return array_map(fn($ad) => $this->normalizeProductAd($ad), $filtered);
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
