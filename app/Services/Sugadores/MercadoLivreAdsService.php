<?php

namespace App\Services\Sugadores;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wrapper stateless para chamadas ao Mercado Ads (Product Ads) da API Mercado Livre.
 *
 * Phase 38 Plan 38-01 — camada HTTP que o comando `sugadores:ml-smoke` (Plan 02)
 * consome. Reutiliza `MercadoLivreService` (Phase 20) para autenticação/refresh
 * do mlToken — NÃO duplica lógica OAuth no path normal (apenas usa `ensureValidToken`
 * + `refreshToken` quando precisa retentar 401).
 *
 * Phase 41 Plan 41-02 — refactor producao-ready:
 *   - `callWithBackoff`: 429 (Retry-After clamp 1..60s + jitter), 5xx (exponencial 2^n cap 60s + jitter),
 *     401 (refreshToken 1x; segundo 401 lanca RuntimeException), 403 (sem retry; scope_missing).
 *   - Cache de advertiser persistente em `ml_advertisers` (TTL 7 dias) — `discoverAdvertiser`
 *     consulta a tabela primeiro; somente faz chamada HTTP em miss/expirado.
 *   - `withRateLimit($sellerId, $cb)`: bucket `ml-api:{seller_id}` 60/min via
 *     `RateLimiter::for('ml-api', ...)` registrado em `AppServiceProvider::boot()`.
 *   - Metricas operacionais (total_calls, pages_read, rate_limit_429, refresh_token_count,
 *     backoff_sleep_ms, total_duration_ms) expostas via `getLastRunMetrics()` para o
 *     ShadowRunService (Plan 41-04) mesclar no `summary` JSON.
 *
 * Retorna payloads CRUS (não normaliza para o contrato §2.3 do plano de migração).
 * Normalização é responsabilidade do comando smoke do Plan 38-02.
 *
 * Tratamento de erro: propaga `\RuntimeException` em 401 persistente / 4xx (incluindo 403)
 * / 5xx esgotando maxAttempts. Conta sem Mercado Ads (lista `advertisers=[]`) é estado
 * VÁLIDO (não exceção).
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

    // ─── Phase 41 Plan 41-02 — backoff + cache + rate limit ──────────────────
    // Base URL ML (MercadoLivreService::API_BASE eh private; declaramos local
    // pra nao acoplar via reflection. Manter alinhado se a constante de la mudar).
    private const API_BASE             = 'https://api.mercadolibre.com';
    private const MAX_ATTEMPTS         = 5;
    private const RATE_LIMIT_PER_MIN   = 60;
    private const CACHE_TTL_DAYS       = 7;
    private const BACKOFF_CAP_SECONDS  = 60;
    private const RATE_LIMIT_DECAY_SEC = 60;

    /** @var array<string,int> contador operacional desta sessao */
    private array $metrics = [];

    /** @var float timestamp UNIX (microsegundos) do inicio da sessao corrente */
    private float $metricsStartedAt = 0.0;

    public function __construct(private MercadoLivreService $ml)
    {
        $this->resetMetrics();
    }

    /**
     * Reseta o contador de metricas e marca o inicio da sessao.
     * Chamado no inicio de cada metodo publico (discoverAdvertiser, listCampaigns,
     * listAds, tryFetchAdsMetrics) — getLastRunMetrics reflete apenas a chamada
     * mais recente, NUNCA acumulado.
     */
    private function resetMetrics(): void
    {
        $this->metrics = [
            'total_calls'         => 0,
            'pages_read'          => 0,
            'rate_limit_429'      => 0,
            'refresh_token_count' => 0,
            'backoff_sleep_ms'    => 0,
        ];
        $this->metricsStartedAt = microtime(true);
    }

    /**
     * Snapshot de metricas operacionais da ultima chamada publica.
     *
     * Consumido pelo ShadowRunService (Plan 41-04) que mescla em `summary.ml_metrics`
     * do `sugador_provider_runs`.
     *
     * @return array{
     *   total_calls: int,
     *   pages_read: int,
     *   rate_limit_429: int,
     *   refresh_token_count: int,
     *   backoff_sleep_ms: int,
     *   total_duration_ms: int
     * }
     */
    public function getLastRunMetrics(): array
    {
        return $this->metrics + [
            'total_duration_ms' => (int) round((microtime(true) - $this->metricsStartedAt) * 1000),
        ];
    }

    /**
     * Aplica rate limit ML antes de executar o callback.
     *
     * Bucket `ml-api:{sellerId}` definido em `AppServiceProvider::boot()` —
     * 60 req/min por seller (T-41-02-02). Se o seller eh desconhecido (primeiro
     * discoverAdvertiser ainda sem cache), cai no bucket `ml-api:unknown`
     * compartilhado — aceitavel pois discover so dispara 1 chamada por empresa.
     *
     * @throws \RuntimeException quando o bucket esta cheio
     */
    private function withRateLimit(?string $sellerId, callable $cb): mixed
    {
        $sellerId ??= 'unknown';
        $key = "ml-api:{$sellerId}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_MIN)) {
            throw new \RuntimeException(
                "[MercadoLivreAds] Rate limit ML excedido para seller {$sellerId} (60/min). Tente novamente em 1 minuto."
            );
        }
        RateLimiter::hit($key, self::RATE_LIMIT_DECAY_SEC);

        return $cb();
    }

    /**
     * Chamada HTTP com backoff escalonado por status code.
     *
     * Politica (§3, §4 do 41-CONTEXT):
     *   - 2xx → retorna payload
     *   - 429 → respeita Retry-After (clamp 1..60s) + jitter 0..1000ms, retry
     *   - 5xx → backoff exponencial 2^attempt (cap 60s) + jitter, retry
     *   - 401 → primeira tentativa: refresh token via MercadoLivreService::refreshToken; segunda 401 = abort
     *   - 403 → abort IMEDIATA (scope_missing) — NAO retenta
     *   - Outros 4xx → abort com mensagem do body
     *   - MAX_ATTEMPTS esgotado → RuntimeException
     *
     * NUNCA loga `$token->access_token` — apenas company.id e endpoint (T-41-02-04).
     *
     * @return array{status:int, body:array, url:string}
     * @throws \RuntimeException
     */
    private function callWithBackoff(Company $company, string $url, array $query, array $headers, ?string $sellerId): array
    {
        $attempt = 0;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            // Token resolvido a cada tentativa: refresh em 401 grava novo access_token
            // no MlToken, e ensureValidToken devolve o atualizado.
            $token = $this->ml->ensureValidToken($company);
            if (! $token) {
                throw new \RuntimeException("[MercadoLivreAds] Empresa {$company->id} sem token ML valido.");
            }

            $response = $this->withRateLimit($sellerId, fn () =>
                Http::withToken($token->access_token)
                    ->withHeaders($headers)
                    ->get($url, $query)
            );
            $this->metrics['total_calls']++;

            $status = $response->status();

            // ─── 2xx: sucesso ─────────────────────────────────────────────
            if ($status >= 200 && $status < 300) {
                return [
                    'status' => $status,
                    'body'   => $response->json() ?? [],
                    'url'    => $url,
                ];
            }

            // ─── 429: respeita Retry-After + jitter ───────────────────────
            if ($status === 429) {
                $this->metrics['rate_limit_429']++;
                $retryAfter = (int) ($response->header('Retry-After') ?? 1);
                $wait = min(max($retryAfter, 1), self::BACKOFF_CAP_SECONDS);
                $waitMs = ($wait * 1000) + random_int(0, 1000);
                $this->metrics['backoff_sleep_ms'] += $waitMs;
                usleep($waitMs * 1000);
                continue;
            }

            // ─── 5xx: backoff exponencial 2^n + jitter ────────────────────
            if ($status >= 500) {
                $wait = min(2 ** $attempt, self::BACKOFF_CAP_SECONDS);
                $waitMs = ($wait * 1000) + random_int(0, 1000);
                $this->metrics['backoff_sleep_ms'] += $waitMs;
                usleep($waitMs * 1000);
                continue;
            }

            // ─── 401: refresh token UMA vez ───────────────────────────────
            if ($status === 401) {
                if ($attempt === 1) {
                    try {
                        $this->ml->refreshToken($token);
                        $this->metrics['refresh_token_count']++;
                        continue;
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(
                            "[MercadoLivreAds] Falha ao renovar token ML empresa {$company->id}: " . $e->getMessage()
                        );
                    }
                }
                throw new \RuntimeException(
                    "[MercadoLivreAds] 401 persistente apos refresh em {$url} empresa {$company->id}"
                );
            }

            // ─── 403: scope_missing — abort sem retry ─────────────────────
            if ($status === 403) {
                throw new \RuntimeException(
                    "[MercadoLivreAds] Permissao/scope ML ausente (HTTP 403) em {$url} empresa {$company->id}"
                );
            }

            // ─── Outros 4xx (404, 422, etc): abort com corpo truncado ─────
            throw new \RuntimeException(
                "[MercadoLivreAds] HTTP {$status} em {$url} empresa {$company->id}: "
                . mb_substr((string) $response->body(), 0, 500)
            );
        }

        // Esgotou maxAttempts (5 tentativas de 5xx/429 sem sucesso).
        throw new \RuntimeException(
            "[MercadoLivreAds] Max attempts ({$attempt}) esgotado em {$url} empresa {$company->id}"
        );
    }

    /**
     * Descobre o advertiser_id Mercado Ads da empresa.
     *
     * Phase 41 — consulta cache `ml_advertisers` primeiro (TTL 7 dias). Cache hit
     * retorna sem chamada HTTP (`status=200`, `cached=true`). Cache miss/expirado
     * dispara `callWithBackoff` e grava `updateOrCreate` no banco.
     *
     * O advertiser_id NÃO é o ml_user_id (seller ID) — é um ID próprio do Mercado Ads
     * que precisa ser resolvido via `/advertising/advertisers?product_id=PADS`.
     *
     * Conta sem Mercado Ads retorna `advertisers=[]` (status 200) e o método devolve
     * `advertiser_id=null` SEM lançar exceção — é estado válido. Para 401 persistente
     * ou 5xx esgotado, propaga RuntimeException do callWithBackoff.
     *
     * @return array{
     *   advertiser_id: ?int,
     *   site_id: ?string,
     *   seller_id: ?string,
     *   raw: array,
     *   url: string,
     *   status: int,
     *   cached: bool
     * }
     */
    public function discoverAdvertiser(Company $company): array
    {
        $this->resetMetrics();

        // ─── Cache lookup (TTL 7 dias) ────────────────────────────────────
        $cached = MlAdvertiser::where('company_id', $company->id)->first();
        if ($cached && $cached->discovered_at && $cached->discovered_at->gt(now()->subDays(self::CACHE_TTL_DAYS))) {
            return [
                'advertiser_id' => (int) $cached->advertiser_id,
                'site_id'       => $cached->site_id,
                'seller_id'     => $cached->seller_id,
                'raw'           => (array) ($cached->raw_data ?? []),
                'url'           => 'cache://ml_advertisers/' . $company->id,
                'status'        => 200,
                'cached'        => true,
            ];
        }

        // ─── Cache miss/expirado: chamada HTTP com backoff ────────────────
        $sellerId = $cached?->seller_id; // pode ser null no primeiro discover
        $result   = $this->callWithBackoff(
            $company,
            self::API_BASE . self::ENDPOINT_ADVERTISERS,
            ['product_id' => 'PADS'],
            self::API_VERSION_HEADER,
            $sellerId,
        );

        $advertisers = $result['body']['advertisers'] ?? [];
        $advertiserId = isset($advertisers[0]['advertiser_id']) ? (int) $advertisers[0]['advertiser_id'] : null;
        $siteId       = $advertisers[0]['site_id']   ?? null;
        $sellerIdNew  = isset($advertisers[0]['seller_id']) ? (string) $advertisers[0]['seller_id'] : null;

        // Grava cache apenas quando descoberta retornou advertiser real.
        if ($advertiserId !== null) {
            MlAdvertiser::updateOrCreate(
                ['company_id' => $company->id],
                [
                    'advertiser_id' => (string) $advertiserId,
                    'seller_id'     => $sellerIdNew,
                    'site_id'       => $siteId ?? 'MLB',
                    'raw_data'      => $result['body'],
                    'discovered_at' => now(),
                ],
            );
        }

        return [
            'advertiser_id' => $advertiserId,
            'site_id'       => $siteId,
            'seller_id'     => $sellerIdNew,
            'raw'           => $result['body'],
            'url'           => self::ENDPOINT_ADVERTISERS . '?product_id=PADS',
            'status'        => $result['status'],
            'cached'        => false,
        ];
    }

    /**
     * Lista campanhas Product Ads do advertiser no período (com paginação automática).
     *
     * Endpoint COMPROVADO em produção (Phase 20 — MercadoLivreService::fetchCampaigns).
     * Teto de segurança: offset máximo 500 (PAGE_LIMIT × 10 páginas).
     *
     * Phase 41 — usa callWithBackoff + withRateLimit (bucket ml-api:{seller_id} via
     * MlAdvertiser cache local) + incrementa `pages_read` a cada iteracao.
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
        $this->resetMetrics();

        $sellerId = MlAdvertiser::where('company_id', $company->id)->value('seller_id');

        $endpoint        = sprintf(self::ENDPOINT_CAMPAIGNS_SEARCH, $advertiserId);
        $offset          = 0;
        $results         = [];
        $endpointsTried  = [];
        $firstPagePayload = null;

        do {
            $result = $this->callWithBackoff(
                $company,
                self::API_BASE . $endpoint,
                [
                    'date_from'        => $dateFrom,
                    'date_to'          => $dateTo,
                    'aggregation_type' => 'CAMPAIGN',
                    'metrics'          => self::DEFAULT_METRICS,
                    'limit'            => self::PAGE_LIMIT,
                    'offset'           => $offset,
                ],
                self::API_VERSION_HEADER,
                $sellerId,
            );
            $page = $result['body'];
            $this->metrics['pages_read']++;

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
     * Phase 41 — usa callWithBackoff + withRateLimit + pages_read metric (idem listCampaigns).
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
        $this->resetMetrics();

        $sellerId = MlAdvertiser::where('company_id', $company->id)->value('seller_id');

        $endpoint        = sprintf(self::ENDPOINT_ADS_ITEMS, $advertiserId);
        $offset          = 0;
        $results         = [];
        $endpointsTried  = [];
        $firstPagePayload = null;

        do {
            $result = $this->callWithBackoff(
                $company,
                self::API_BASE . $endpoint,
                [
                    'date_from'        => $dateFrom,
                    'date_to'          => $dateTo,
                    // BUG fix Phase 42 polish — confirmado via direct call em prod
                    // (ByMobille - Teste #298, advertiser 620095): o endpoint
                    // /product_ads/items NAO aceita aggregation_type=total. Retorna
                    // 500 internal_error "No enum constant AggregationTypeItemAdGroupEnum.TOTAL".
                    // Sem aggregation_type, o endpoint retorna as metricas agregadas
                    // por item ao longo da janela [date_from, date_to] (564 items + paging.total).
                    // listCampaigns continua usando aggregation_type=CAMPAIGN (valor valido
                    // do enum CampaignAggregationTypeEnum — ja confirmado 200 OK).
                    'metrics'          => self::DEFAULT_METRICS,
                    'limit'            => self::PAGE_LIMIT,
                    'offset'           => $offset,
                ],
                // O endpoint Items NÃO usa Api-Version: 1 em Phase 20 (linha 614).
                // Smoke vai confirmar — se quebrar, adicionar header aqui.
                [],
                $sellerId,
            );
            $page = $result['body'];
            $this->metrics['pages_read']++;

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
     * Phase 41 — listAds chama resetMetrics no inicio (sucesso) ou propaga RuntimeException
     * com metricas acumuladas ate o ponto da falha (sucesso parcial visivel via getLastRunMetrics).
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
            // Log diagnóstico — só company_id, NUNCA o token (T-38-01 + T-41-02-04).
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
