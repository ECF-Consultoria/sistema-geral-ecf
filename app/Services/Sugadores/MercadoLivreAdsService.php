<?php

namespace App\Services\Sugadores;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper stateless para chamadas ao Mercado Ads (Product Ads) da API Mercado Livre.
 *
 * Phase 38 Plan 38-01 — camada HTTP que o comando `sugadores:ml-smoke` (Plan 02)
 * vai consumir. Reutiliza `MercadoLivreService` (Phase 20) para autenticação/refresh
 * do mlToken — NÃO duplica lógica OAuth.
 *
 * Retorna payloads CRUS (não normaliza para o contrato §2.3 do plano de migração).
 * Normalização é responsabilidade do comando smoke do Plan 02.
 *
 * Tratamento de erro: propaga `\RuntimeException` do `MercadoLivreService::get()`
 * em 401 persistente / 4xx / 5xx — falha cedo, sem catch silencioso. Conta sem
 * Mercado Ads (lista `advertisers=[]`) é estado VÁLIDO (não exceção).
 */
class MercadoLivreAdsService
{
    // ─── Endpoints Mercado Ads (Product Ads) ───────────────────────────────
    // Comprovados em produção (Phase 20 — ver MercadoLivreService linhas 444-446 e 489-490).
    private const ENDPOINT_ADVERTISERS      = '/advertising/advertisers';
    private const ENDPOINT_CAMPAIGNS_SEARCH = '/marketplace/advertising/MLB/advertisers/%d/product_ads/campaigns/search';

    // CANDIDATO — validar contra doc oficial https://developers.mercadolivre.com.br/.
    // Mercado Livre nunca chamou em produção; o smoke da Phase 38 existe justamente
    // para confirmar shape do payload e ajustar URL/params se a doc usar nome diferente.
    private const ENDPOINT_ADS_ITEMS = '/advertising/advertisers/%d/product_ads/items';

    // Header obrigatório para endpoints Mercado Ads (descoberto em Phase 20).
    private const API_VERSION_HEADER = ['Api-Version' => '1'];

    // Métricas Product Ads que o contrato-alvo §2.3 do plano precisa cruzar.
    // Mantém alinhado com MercadoLivreService::fetchAdsItems (linha 619).
    private const DEFAULT_METRICS = 'cost,clicks,prints,total_amount,direct_amount,indirect_amount,acos,roas,cpc,units_quantity,ctr';

    // Tetos de segurança para evitar loop infinito de paginação (espelha Phase 20).
    private const PAGE_LIMIT          = 50;
    private const CAMPAIGNS_MAX_OFFSET = 500;
    private const ADS_MAX_OFFSET       = 2000;

    public function __construct(private MercadoLivreService $ml)
    {
    }

    /**
     * Descobre o advertiser_id Mercado Ads da empresa.
     *
     * O advertiser_id NÃO é o ml_user_id (seller ID) — é um ID próprio do Mercado Ads
     * que precisa ser resolvido via `/advertising/advertisers?product_id=PADS`.
     *
     * Conta sem Mercado Ads retorna `advertisers=[]` (status 200) e o método devolve
     * `advertiser_id=null` SEM lançar exceção — é estado válido. Para 401 persistente
     * ou 5xx, propaga RuntimeException do MercadoLivreService::get().
     *
     * @return array{
     *   advertiser_id: ?int,
     *   site_id: ?string,
     *   seller_id: ?string,
     *   raw: array,
     *   url: string,
     *   status: int
     * }
     */
    public function discoverAdvertiser(Company $company): array
    {
        $endpoint = self::ENDPOINT_ADVERTISERS;
        $query    = ['product_id' => 'PADS'];

        $payload     = $this->ml->get($company, $endpoint, $query, self::API_VERSION_HEADER);
        $advertisers = $payload['advertisers'] ?? [];

        return [
            'advertiser_id' => isset($advertisers[0]['advertiser_id']) ? (int) $advertisers[0]['advertiser_id'] : null,
            'site_id'       => $advertisers[0]['site_id']   ?? null,
            'seller_id'     => isset($advertisers[0]['seller_id']) ? (string) $advertisers[0]['seller_id'] : null,
            'raw'           => $payload,
            'url'           => $endpoint . '?product_id=PADS',
            'status'        => 200,
        ];
    }

    /**
     * Lista campanhas Product Ads do advertiser no período (com paginação automática).
     *
     * Endpoint COMPROVADO em produção (Phase 20 — MercadoLivreService::fetchCampaigns).
     * Teto de segurança: offset máximo 500 (PAGE_LIMIT × 10 páginas).
     *
     * @return array{
     *   count: int,
     *   results: array,
     *   raw_first_page: ?array,
     *   endpoints_tried: string[]
     * }
     */
    public function listCampaigns(Company $company, int $advertiserId, string $dateFrom, string $dateTo): array
    {
        $endpoint        = sprintf(self::ENDPOINT_CAMPAIGNS_SEARCH, $advertiserId);
        $offset          = 0;
        $results         = [];
        $endpointsTried  = [];
        $firstPagePayload = null;

        do {
            $page = $this->ml->get(
                $company,
                $endpoint,
                [
                    'date_from'        => $dateFrom,
                    'date_to'          => $dateTo,
                    'aggregation_type' => 'CAMPAIGN',
                    'metrics'          => self::DEFAULT_METRICS,
                    'limit'            => self::PAGE_LIMIT,
                    'offset'           => $offset,
                ],
                self::API_VERSION_HEADER,
            );

            // Preserva payload da primeira página para inspeção do relatório do Plan 02.
            $firstPagePayload ??= $page;

            $results          = array_merge($results, $page['results'] ?? []);
            $endpointsTried[] = $endpoint . '?offset=' . $offset;

            $total  = $page['paging']['total'] ?? count($results);
            $offset += self::PAGE_LIMIT;
        } while ($offset < $total && $offset < self::CAMPAIGNS_MAX_OFFSET);

        return [
            'count'           => count($results),
            'results'         => $results,
            'raw_first_page'  => $firstPagePayload,
            'endpoints_tried' => $endpointsTried,
        ];
    }

    /**
     * Lista anúncios (ads/items) do advertiser no período.
     *
     * ENDPOINT CANDIDATO — validar contra doc oficial; smoke da Phase 38 imprime
     * URL+status para correção rápida caso a doc oficial use nome diferente.
     * Teto de segurança: offset máximo 2000 (PAGE_LIMIT × 40 páginas).
     *
     * @return array{
     *   count: int,
     *   results: array,
     *   raw_first_page: ?array,
     *   endpoints_tried: string[]
     * }
     */
    public function listAds(Company $company, int $advertiserId, string $dateFrom, string $dateTo): array
    {
        $endpoint        = sprintf(self::ENDPOINT_ADS_ITEMS, $advertiserId);
        $offset          = 0;
        $results         = [];
        $endpointsTried  = [];
        $firstPagePayload = null;

        do {
            $page = $this->ml->get(
                $company,
                $endpoint,
                [
                    'date_from'        => $dateFrom,
                    'date_to'          => $dateTo,
                    'aggregation_type' => 'total',
                    'metrics'          => self::DEFAULT_METRICS,
                    'limit'            => self::PAGE_LIMIT,
                    'offset'           => $offset,
                ],
                // O endpoint Items NÃO usa Api-Version: 1 em Phase 20 (linha 614).
                // Smoke vai confirmar — se quebrar, adicionar header aqui.
            );

            $firstPagePayload ??= $page;
            $results          = array_merge($results, $page['results'] ?? []);
            $endpointsTried[] = $endpoint . '?offset=' . $offset;

            $total  = $page['paging']['total'] ?? count($results);
            $offset += self::PAGE_LIMIT;
        } while ($offset < $total && $offset < self::ADS_MAX_OFFSET);

        return [
            'count'           => count($results),
            'results'         => $results,
            'raw_first_page'  => $firstPagePayload,
            'endpoints_tried' => $endpointsTried,
        ];
    }

    /**
     * Wrapper try/catch sobre listAds() — diferencia "endpoint funcionou mas vazio"
     * de "endpoint retornou 404/5xx".
     *
     * Usado pelo relatório CLI do Plan 02 para registrar erro sem abortar o smoke
     * (`endpoints_failed` na fixture JSON).
     *
     * @return array{
     *   ok: bool,
     *   data: ?array,
     *   error: ?array{message: string, class: string}
     * }
     */
    public function tryFetchAdsMetrics(Company $company, int $advertiserId, string $dateFrom, string $dateTo): array
    {
        try {
            $data = $this->listAds($company, $advertiserId, $dateFrom, $dateTo);

            return [
                'ok'    => true,
                'data'  => $data,
                'error' => null,
            ];
        } catch (\RuntimeException $e) {
            // Log diagnóstico — só company_id, NUNCA o token (T-38-01).
            Log::warning("[MercadoLivreAds] Falha em listAds empresa {$company->id}: {$e->getMessage()}");

            return [
                'ok'    => false,
                'data'  => null,
                'error' => [
                    'message' => $e->getMessage(),
                    'class'   => get_class($e),
                ],
            ];
        }
    }
}
