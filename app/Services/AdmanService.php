<?php

namespace App\Services;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\CompanyMonthlyRevenue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdmanService
{
    /**
     * Limite de requisições por minuto da API Adman.
     *
     * Documentado pela Adman em 2026-05-27: 10 req/min por API key.
     * Throttle aplicado entre chamadas sequenciais = 7s.
     * Cálculo: 60s / 10 req = 6s teórico + 1s de folga para
     * garantir nunca passar do limite mesmo com jitter de rede.
     */
    public const ADMAN_RATE_LIMIT_RPM = 10;

    private string $baseUrl;
    private string $apiKey;

    /**
     * Marketplace fallback transicional (Phase 18.5).
     *
     * DIVIDA: a propriedade NAO e mais lida em paths (todos os 8 endpoints
     * + 9 wrappers de cache aceitam `string $marketplace = 'meli'` como
     * argumento). Mantida como propriedade somente para inspecao via
     * debug/tinker — pode ser removida apos a fase estabilizar e todos os
     * callers passarem `$marketplace` explicitamente.
     */
    private string $marketplace;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.adman.base_url', 'https://api.ad-man.io/v1'), '/');
        $this->apiKey      = config('services.adman.api_key', '');
        $this->marketplace = 'meli';
    }

    private function headers(): array
    {
        return [
            'integrator-api-key' => $this->apiKey,
            'Accept'             => 'application/json',
        ];
    }

    public function syncAll(): array
    {
        $results = ['success' => 0, 'failed' => 0, 'skipped' => 0];

        // chunk() evita OOM ao processar centenas de empresas — cada lote é coletado pela GC antes do próximo
        Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->chunk(20, function ($companies) use (&$results) {
                foreach ($companies as $company) {
                    try {
                        $this->syncCompany($company);
                        $results['success']++;
                        // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM (10 rpm → 7s/req com folga)
                        usleep(7_000_000);
                    } catch (\Throwable $e) {
                        Log::error("[Adman] Erro empresa {$company->id} ({$company->name}): " . $e->getMessage());
                        $results['failed']++;
                    }
                }
            });

        return $results;
    }

    public function syncCompany(Company $company, ?string $date = null): AdmanMetric
    {
        // Padrão: ontem — dados do dia corrente ficam incompletos até o processamento noturno da Adman
        $date   = $date ?? now()->subDay()->toDateString();
        $custId = $company->ml_store_id ?: $company->adman_account_id;

        // Phase 18.5: marketplace dinamico por empresa. Empresas Shopee/Amazon
        // batem em endpoints diferentes (path /shopee/... e /amazon/...).
        $marketplace = $company->marketplace ?? 'meli';

        try {
            $performance = $this->fetchPerformance($custId, $date, $date, 3, $marketplace);
            $summarized  = $performance['summarizedData'] ?? [];
            $items       = $performance['items'] ?? [];

            $grossBilling = $summarized['grossBilling']['value']  ?? null;
            $netBilling   = $summarized['netBilling']['value']    ?? null;
            $salesFee     = $summarized['salesFee']['value']      ?? null;
            $taxes        = $summarized['taxes']['value']         ?? null;
            $shippingCost = $summarized['shippingCost']['value']  ?? null;
            $productCost  = $summarized['productCost']['value']   ?? null;
            $returnCost   = $summarized['returnCost']['value']    ?? null;
            $investment   = $summarized['investment']['value']    ?? null;
            $profitMargin = $summarized['profitMargin']['value']  ?? null;
            $profitShare  = $summarized['profitShare']['value']   ?? null;
            $soldQty      = $summarized['soldQuantity']['value']  ?? null;
            $prevBilling  = $summarized['grossBilling']['prev']   ?? null;

            $tacos     = ($grossBilling > 0 && $investment !== null)
                ? round(($investment / $grossBilling) * 100, 4) : null;
            $marginPct = ($grossBilling > 0 && $profitMargin !== null)
                ? round(($profitMargin / $grossBilling) * 100, 4) : null;

            $productsTotal       = count($items);
            $productsWithoutCost = collect($items)->filter(fn($i) => ($i['cost']['value'] ?? 0) == 0)->count();

            $metric = AdmanMetric::updateOrCreate(
                ['company_id' => $company->id, 'reference_date' => $date],
                [
                    'tacos'                   => $tacos,
                    'revenue'                 => $grossBilling,
                    'net_billing'             => $netBilling,
                    'sales_fee'               => $salesFee,
                    'taxes'                   => $taxes,
                    'shipping_cost'           => $shippingCost,
                    'product_cost'            => $productCost,
                    'return_cost'             => $returnCost,
                    'profit_share'            => $profitShare,
                    'sold_quantity'           => $soldQty,
                    'ad_spend'                => $investment,
                    'contribution_margin'     => $profitMargin,
                    'contribution_margin_pct' => $marginPct,
                    'products_total'          => $productsTotal > 0 ? $productsTotal : null,
                    'products_without_cost'   => $productsTotal > 0 ? $productsWithoutCost : null,
                    'revenue_prev_period'     => $prevBilling,
                    'raw_data'                => $summarized,
                    'synced_at'               => now(),
                ]
            );

            // Registro de diff para o painel de diagnóstico (DEV-03)
            $foiCriado     = $metric->wasRecentlyCreated;
            $foiAtualizado = !$foiCriado && $metric->wasChanged();
            $foiIgnorado   = !$foiCriado && !$metric->wasChanged();

            AdmanSyncLog::create([
                'company_id'    => $company->id,
                'created_count' => $foiCriado     ? 1 : 0,
                'updated_count' => $foiAtualizado ? 1 : 0,
                'skipped_count' => $foiIgnorado   ? 1 : 0,
                'error_message' => null,
                'synced_at'     => now(),
            ]);

            try {
                $this->syncCampaigns($company, $custId, $date, $marketplace);
            } catch (\Throwable $e) {
                Log::warning("[Adman] Campanhas empresa {$company->id}: " . $e->getMessage());
            }

            return $metric;
        } catch (\Throwable $e) {
            // Registro de erro mesmo em falha — admin precisa enxergar (DEV-02)
            AdmanSyncLog::create([
                'company_id'    => $company->id,
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error_message' => $e->getMessage(),
                'synced_at'     => now(),
            ]);
            throw $e; // preservar comportamento SyncAdmanCompanyJob retry/failed
        }
    }

    public function syncCampaigns(Company $company, string $custId, string $date, string $marketplace = 'meli'): void
    {
        $campaigns = $this->fetchCampaigns($custId, $marketplace);

        foreach ($campaigns as $campaign) {
            $campaignId = (string) ($campaign['campaignId'] ?? $campaign['id'] ?? null);
            if (!$campaignId) continue;

            try {
                $cm = $this->fetchCampaignMetrics($custId, $campaignId, $date, $date, $marketplace);

                // A API pode retornar valores diretos ou dentro de um sub-objeto {value: X}
                $val = fn($key) => is_array($cm[$key] ?? null)
                    ? ($cm[$key]['value'] ?? null)
                    : ($cm[$key] ?? null);

                AdmanCampaignMetric::updateOrCreate(
                    ['company_id' => $company->id, 'reference_date' => $date, 'campaign_id' => $campaignId],
                    [
                        'campaign_name'   => $campaign['name'] ?? null,
                        'campaign_status' => $campaign['status'] ?? null,
                        'investment'      => $val('investment'),
                        'revenue'         => $val('revenue'),
                        'acos'            => $val('acos'),
                        'tacos'           => $val('tacos'),
                        'roas'            => $val('roas'),
                        'cpc'             => $val('cpc'),
                        'clicks'          => $val('clicks'),
                        'impressions'     => $val('impressions'),
                        'sold_quantity'   => $val('soldQuantity'),
                        'synced_at'       => now(),
                    ]
                );

                usleep(400_000);
            } catch (\Throwable $e) {
                Log::warning("[Adman] Campanha {$campaignId}: " . $e->getMessage());
            }
        }
    }

    public function fetchPerformance(string $custId, string $dateFrom, string $dateTo, int $maxRetries = 3, string $marketplace = 'meli'): array
    {
        $attempt = 0;
        while (true) {
            $response = Http::withHeaders($this->headers())
                ->connectTimeout(15)
                ->timeout(120)
                ->get("{$this->baseUrl}/{$marketplace}/performance/{$custId}", [
                    'dateFrom' => $dateFrom,
                    'dateTo'   => $dateTo,
                ]);

            if ($response->status() === 401) throw new \RuntimeException('Adman API: chave inválida (401).');

            if ($response->status() === 429) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw new \RuntimeException('Adman API: rate limit (429) após ' . $maxRetries . ' tentativas.');
                }
                $delay = pow(2, $attempt); // 2s, 4s, 8s
                Log::warning("[Adman] Rate limit custId={$custId}, aguardando {$delay}s (tentativa {$attempt}/{$maxRetries})");
                sleep($delay);
                continue;
            }

            if ($response->failed()) throw new \RuntimeException("Adman API erro {$response->status()} custId={$custId}");

            return $response->json() ?? [];
        }
    }

    /**
     * Faturamento bruto (summarizedData.grossBilling.value) numa janela.
     *
     * Por que chamar a Adman direto em vez de SUM(adman_metrics.revenue)?
     *  - O sync diário pode pular dias (job falhando, empresa nova, etc) e
     *    a soma fica subestimada.
     *  - A Adman aplica ajustes retroativos (devoluções, conciliação) que
     *    não voltam para o nosso DB.
     *  - Chamar /performance com range = exatamente o que a dashboard Adman
     *    mostra → garante bate-bate.
     *
     * Cache TTL de 10min (chave por custId+range) — balanceia frescor com
     * latência da Adman (~200-500ms/chamada). Fail-open: erro → null (UI
     * mostra '—' em vez de quebrar).
     */
    /**
     * Sentinel usado quando a Adman retorna erro persistente (429/500/etc).
     * Cachear o erro evita: (a) martelar a API estourada, (b) re-tentativa
     * em toda request da UI. TTL curto (10min) permite recuperação rápida
     * quando a empresa volta a responder.
     */
    private const ERROR_SENTINEL = '__error__';
    private const ERROR_CACHE_MINUTES = 10;

    /**
     * Data atual em BRT no formato YYYY-MM-DD — usada como sufixo das chaves
     * de cache (gross_billing / account_metrics) para auto-invalidar ao virar
     * o dia. A API Adman é D-1 (publica 10h BRT, 1× ao dia), portanto faz
     * sentido manter TTL=24h e chave por dia: na 1ª chamada do dia há cache
     * miss → busca fresh; demais chamadas do mesmo dia leem do cache.
     */
    private function cacheDay(): string
    {
        return now()->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * @param  bool  $forceRefresh  Bypass cache hit lookup — chama Adman direto
     *   e sobrescreve o cache. Usar no Job de refresh pra recuperar empresas
     *   com ERROR_SENTINEL cacheado (sem isso, cache em erro fica preso até
     *   o TTL de 10min expirar entre execuções).
     *
     * TTL = 24h (1440min) — API Adman é D-1; chave inclui data atual em BRT
     * (via cacheDay()) para auto-invalidar ao virar o dia.
     */
    public function fetchGrossBilling(string $custId, string $dateFrom, string $dateTo, int $cacheMinutes = 1440, bool $forceRefresh = false, string $marketplace = 'meli'): ?float
    {
        // Phase 18.5: cache key inclui marketplace para evitar colisao entre
        // marketplaces que por acaso compartilhem o mesmo custId. Entradas
        // antigas (sem marketplace) ficam orfas e expiram naturalmente em 24h.
        $cacheKey = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached === self::ERROR_SENTINEL ? null : (float) $cached;
            }
        }

        try {
            // fetchPerformance retorna o JSON completo (com items[] grande).
            // unset+gc imediato libera a memória — necessário pra job batch
            // não acumular 50 responses na memória do worker.
            $data  = $this->fetchPerformance($custId, $dateFrom, $dateTo, 3, $marketplace);
            $value = $data['summarizedData']['grossBilling']['value'] ?? null;
            unset($data);
            gc_collect_cycles();

            if ($value === null) {
                // Resposta OK mas sem grossBilling — cacheia como erro pra
                // não voltar a chamar essa empresa por 10min.
                Cache::put($cacheKey, self::ERROR_SENTINEL, now()->addMinutes(self::ERROR_CACHE_MINUTES));
                return null;
            }

            Cache::put($cacheKey, (float) $value, now()->addMinutes($cacheMinutes));
            return (float) $value;
        } catch (\Throwable $e) {
            Log::warning("[Adman/GrossBilling] custId={$custId} range={$dateFrom}..{$dateTo}: " . $e->getMessage());
            Cache::put($cacheKey, self::ERROR_SENTINEL, now()->addMinutes(self::ERROR_CACHE_MINUTES));
            return null;
        }
    }

    /**
     * Lê APENAS do cache (não chama Adman se miss). Usado pelos controllers
     * que listam N empresas — fazer chamada síncrona aqui estouraria memória
     * (response Adman traz items[] grande × N empresas) e travaria o request.
     *
     * O cache é pre-aquecido pelo RefreshGrossBillingCacheJob (cron 30min).
     * Retorna null em 3 cenários:
     *  - Cache miss (job nunca rodou para essa empresa)
     *  - Cache hit com ERROR_SENTINEL (Adman retornou erro persistente)
     *  - Cache hit com null (não deveria acontecer, mas seguro)
     * Use hasCachedEntry() pra distinguir "miss" de "erro" no controller.
     */
    public function getCachedGrossBilling(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): ?float
    {
        // Chave inclui marketplace (Phase 18.5) + cacheDay() — leitura so "ve"
        // o cache do dia atual em BRT, e nunca colide com outro marketplace.
        $cacheKey = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();
        $value    = Cache::get($cacheKey);
        if ($value === null || $value === self::ERROR_SENTINEL) return null;
        return (float) $value;
    }

    /**
     * True se existe QUALQUER entrada no cache (valor real ou ERROR_SENTINEL).
     * Permite controllers diferenciarem:
     *  - hasCachedEntry=false → job nunca rodou pra empresa → dispatch job
     *  - hasCachedEntry=true mas getCachedGrossBilling=null → erro cacheado
     *    → NÃO dispatch (já tentou recentemente, evita martelar)
     */
    public function hasCachedEntry(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): bool
    {
        // Chave inclui marketplace (Phase 18.5) + cacheDay() — consulta so "ve"
        // entradas do dia atual em BRT para o marketplace correto.
        $cacheKey = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();
        return Cache::has($cacheKey);
    }

    /**
     * Batch read otimizado pra controllers com N empresas. Usa Cache::many
     * (1 round-trip Redis) em vez de N Cache::get sequenciais — escala
     * linearmente até 1000+ chaves sem overhead perceptível.
     *
     * @param  array<string>  $custIds
     * @return array<string, array{value: ?float, hasEntry: bool}>
     *         Map [custId => ['value' => ?, 'hasEntry' => bool]]
     */
    public function getCachedGrossBillingsMany(array $custIds, string $dateFrom, string $dateTo, string $marketplace = 'meli'): array
    {
        $custIds = array_values(array_unique(array_filter($custIds, fn($id) => $id !== null && $id !== '')));
        if (empty($custIds)) return [];

        // Mapeia custId → cacheKey e vice-versa pra recompor depois.
        // Chave inclui marketplace (Phase 18.5) + cacheDay() — consulta so "ve"
        // entradas do dia atual em BRT para o marketplace correto.
        // Decisao: marketplace e UNICO por chamada (homogeneo). Callers com
        // lista mista (meli+shopee+amazon) agrupam por marketplace e chamam
        // 1x por marketplace antes de fazer merge — evita explodir a assinatura
        // do batch em array<custId => marketplace>.
        $keysByCustId = [];
        $custIdsByKey = [];
        $day          = $this->cacheDay();
        foreach ($custIds as $custId) {
            $key = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
            $keysByCustId[$custId] = $key;
            $custIdsByKey[$key]    = $custId;
        }

        // 1 round-trip Redis pra todos os custIds.
        $raw = Cache::many(array_values($keysByCustId));

        $out = [];
        foreach ($custIds as $custId) {
            $key = $keysByCustId[$custId];
            $val = $raw[$key] ?? null;

            // hasEntry distingue:
            //  - null = chave nunca existiu (Cache::many retorna null pra miss)
            //  - ERROR_SENTINEL = empresa com erro persistente
            //  - float = valor real
            // Mas Cache::many não nos diz a diferença entre "miss" e "valor null".
            // Aqui assumimos que null no Redis = miss (consistente com fetchGrossBilling
            // que SEMPRE cacheia ERROR_SENTINEL em vez de null).
            $hasEntry = $val !== null;

            $out[$custId] = [
                'value'    => ($val !== null && $val !== self::ERROR_SENTINEL) ? (float) $val : null,
                'hasEntry' => $hasEntry,
            ];
        }

        return $out;
    }

    // ─── Account Metrics (ACOS, TACOS, margem, etc) ───────────────────────────
    //
    // Endpoint /v1/{marketplace}/accounts/{custId}/metrics retorna métricas
    // agregadas que NÃO estão no /performance: acos, tacos, liquidMargin,
    // percentageMargin, billing, investment. Cacheamos em estrutura única
    // pra UI consumir em 1 read.

    /**
     * Forma do array retornado/cachado por empresa. Null pra campo ausente.
     *
     * @return array{acos: ?float, tacos: ?float, investment: ?float,
     *               liquid_margin: ?float, percentage_margin: ?float,
     *               billing: ?float}
     */
    /**
     * @param  bool  $forceRefresh  Bypass cache hit lookup — chama Adman direto
     *   e sobrescreve o cache. Usar no Job de refresh pra recuperar empresas
     *   com ERROR_SENTINEL cacheado (sem isso, fica preso até o TTL de 10min
     *   expirar entre execuções e timing nunca casa).
     *
     * TTL = 24h (1440min) — API Adman é D-1; chave inclui data atual em BRT
     * (via cacheDay()) para auto-invalidar ao virar o dia.
     */
    public function fetchAccountMetricsCached(string $custId, string $dateFrom, string $dateTo, int $cacheMinutes = 1440, bool $forceRefresh = false, string $marketplace = 'meli'): ?array
    {
        // Phase 18.5: cache key inclui marketplace.
        $cacheKey = "adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached === self::ERROR_SENTINEL ? null : $cached;
            }
        }

        try {
            $data = $this->fetchAccountMetrics($custId, $dateFrom, $dateTo, $marketplace);

            // A Adman embrulha cada métrica em {value, diff, prev} — extrai só o value.
            $val = fn(string $key): ?float => isset($data['metrics'][$key]['value'])
                ? (float) $data['metrics'][$key]['value']
                : null;

            $metrics = [
                'acos'              => $val('acos'),
                'tacos'             => $val('tacos'),
                'investment'        => $val('investment'),
                'liquid_margin'     => $val('liquidMargin'),
                'percentage_margin' => $val('percentageMargin'),
                'billing'           => $val('billing'),
            ];

            unset($data);
            gc_collect_cycles();

            // Se todos os campos vieram null, trata como erro (resposta inválida).
            if (array_filter($metrics, fn($v) => $v !== null) === []) {
                Cache::put($cacheKey, self::ERROR_SENTINEL, now()->addMinutes(self::ERROR_CACHE_MINUTES));
                return null;
            }

            Cache::put($cacheKey, $metrics, now()->addMinutes($cacheMinutes));
            return $metrics;
        } catch (\Throwable $e) {
            Log::warning("[Adman/AccountMetrics] custId={$custId} range={$dateFrom}..{$dateTo}: " . $e->getMessage());
            Cache::put($cacheKey, self::ERROR_SENTINEL, now()->addMinutes(self::ERROR_CACHE_MINUTES));
            return null;
        }
    }

    /**
     * Lê APENAS do cache. Mesma motivação de getCachedGrossBilling: chamadas
     * síncronas em controllers com N empresas estouram memória/rate limit.
     */
    public function getCachedAccountMetrics(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): ?array
    {
        // Chave inclui marketplace (Phase 18.5) + cacheDay().
        $cacheKey = "adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();
        $value    = Cache::get($cacheKey);
        if ($value === null || $value === self::ERROR_SENTINEL) return null;
        return is_array($value) ? $value : null;
    }

    /**
     * True se existe QUALQUER entrada (valor real OU ERROR_SENTINEL).
     */
    public function hasCachedAccountMetricsEntry(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): bool
    {
        // Chave inclui marketplace (Phase 18.5) + cacheDay().
        $cacheKey = "adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:" . $this->cacheDay();
        return Cache::has($cacheKey);
    }

    /**
     * Batch read otimizado pra controllers com N empresas — 1 round-trip Redis.
     *
     * @param  array<string>  $custIds
     * @return array<string, array{value: ?array, hasEntry: bool}>
     */
    public function getCachedAccountMetricsMany(array $custIds, string $dateFrom, string $dateTo, string $marketplace = 'meli'): array
    {
        $custIds = array_values(array_unique(array_filter($custIds, fn($id) => $id !== null && $id !== '')));
        if (empty($custIds)) return [];

        // Chave inclui marketplace (Phase 18.5) + cacheDay(). Mesma decisao do
        // getCachedGrossBillingsMany: marketplace unico por chamada.
        $keysByCustId = [];
        $day          = $this->cacheDay();
        foreach ($custIds as $custId) {
            $keysByCustId[$custId] = "adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
        }

        $raw = Cache::many(array_values($keysByCustId));

        $out = [];
        foreach ($custIds as $custId) {
            $key = $keysByCustId[$custId];
            $val = $raw[$key] ?? null;
            $hasEntry = $val !== null;

            $out[$custId] = [
                'value'    => ($val !== null && $val !== self::ERROR_SENTINEL && is_array($val)) ? $val : null,
                'hasEntry' => $hasEntry,
            ];
        }

        return $out;
    }

    /**
     * Versão batch para N empresas — SEQUENCIAL THROTTLED por causa do rate
     * limit da Adman (~50 req/min). Versão anterior usava Http::pool paralelo
     * mas isso amplificava o rate limit e quebrava o sync diário em background.
     *
     * Estratégia:
     *  - Cache de 30min por (custId, range) — cobre cargas repetidas da Dashboard
     *  - Cache lock (mutex) por chave — evita stampede quando 2+ admins abrem
     *    a mesma tela ao mesmo tempo com cache cold (sem lock cada um faria a
     *    mesma chamada → multiplicaria N)
     *  - Sleep 1.5s entre chamadas → ~40 req/min, abaixo do limite Adman
     *  - Fail-open: erro → null
     *
     * Custo: cache cold com 50 empresas = 50 × 1.5s = 75s primeira carga.
     * Depois é instantâneo durante 30min. Aceitável dado que isso só ocorre
     * 1x a cada 30min quando o admin abre Dashboard/Empresas.
     *
     * TTL = 24h (1440min) — API Adman é D-1; chave inclui data atual em BRT
     * (via cacheDay()) para auto-invalidar ao virar o dia.
     *
     * @param  array<string>  $custIds  Adman account IDs
     * @return array<string, ?float>    Map [custId => grossBilling] (null em falha)
     */
    public function fetchGrossBillingsBatch(array $custIds, string $dateFrom, string $dateTo, int $cacheMinutes = 1440, string $marketplace = 'meli'): array
    {
        $custIds = array_values(array_unique(array_filter($custIds, fn($id) => $id !== null && $id !== '')));
        $result  = [];
        $toFetch = [];
        $day     = $this->cacheDay();

        foreach ($custIds as $custId) {
            // Phase 18.5: cache key inclui marketplace.
            $cacheKey = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
            $cached   = Cache::get($cacheKey);
            if ($cached !== null) {
                $result[$custId] = (float) $cached;
            } else {
                $toFetch[] = $custId;
            }
        }

        if (empty($toFetch)) return $result;

        foreach ($toFetch as $i => $custId) {
            // Throttle conforme ADMAN_RATE_LIMIT_RPM = 10 (60s/10 = 6s teorico, 7s com folga).
            // Phase 18 W4-T2: 1.5s (40 rpm) violava o throttle global; embora chamado em cache
            // miss apenas (TTL 24h), em manhas pos-D-1 com cache frio empurrava o pool inteiro
            // para 429. Alinhado com RefreshGrossBillingCacheJob.
            if ($i > 0) usleep(7_000_000);

            $value = $this->fetchGrossBilling($custId, $dateFrom, $dateTo, $cacheMinutes, false, $marketplace);
            $result[$custId] = $value;
        }

        return $result;
    }

    public function fetchCampaigns(string $custId, string $marketplace = 'meli'): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$marketplace}/ads/{$custId}/campaigns");

        if ($response->failed()) {
            throw new \RuntimeException("Adman campanhas erro {$response->status()} custId={$custId}");
        }

        $data = $response->json() ?? [];
        return is_array($data) && isset($data[0]) ? $data : ($data['data'] ?? $data['campaigns'] ?? []);
    }

    public function fetchCampaignMetrics(string $custId, string $campaignId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$marketplace}/ads/{$custId}/{$campaignId}/metrics", [
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Adman métricas campanha {$campaignId} erro {$response->status()}");
        }

        return $response->json() ?? [];
    }

    public function syncHistorical(Company $company, string $dateFrom, string $dateTo): array
    {
        $current = new \DateTime($dateFrom);
        $end     = new \DateTime($dateTo);
        $results = ['success' => 0, 'failed' => 0];

        while ($current <= $end) {
            $date = $current->format('Y-m-d');
            try {
                $this->syncCompany($company, $date);
                $results['success']++;
                usleep(700_000);
            } catch (\Throwable $e) {
                Log::warning("[Adman] Histórico {$date} empresa {$company->id}: " . $e->getMessage());
                $results['failed']++;
            }
            $current->modify('+1 day');
        }

        return $results;
    }

    public function fetchAccountMetrics(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$marketplace}/accounts/{$custId}/metrics", [
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ]);

        if ($response->failed()) throw new \RuntimeException("Adman account metrics erro {$response->status()}");
        return $response->json() ?? [];
    }

    public function listAccounts(?string $filter = null, int $page = 1, string $marketplace = 'meli'): array
    {
        $params = ['page' => $page];
        if ($filter) $params['filter'] = $filter;

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$marketplace}/accounts", $params);

        if ($response->failed()) throw new \RuntimeException("Adman accounts erro {$response->status()}");
        return $response->json() ?? [];
    }

    /**
     * Puxa o faturamento bruto do mês completo via Adman e persiste em company_monthly_revenues.
     * Usa dateFrom=início do mês, dateTo=hoje (mês corrente) ou fim do mês (meses passados).
     */
    public function syncMonthRevenue(Company $company, string $yearMonth): CompanyMonthlyRevenue
    {
        $custId = $company->ml_store_id ?: $company->adman_account_id;
        if (!$custId) {
            throw new \RuntimeException("Empresa {$company->id} sem ID Loja ML/Adman.");
        }

        $ref = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();

        // Mês atual: rolling 30 dias (alinhado com VPS — captura billing mais recente)
        // Mês passado: mês calendário completo
        if ($ref->isSameMonth(Carbon::now())) {
            $start = Carbon::now()->subDays(30)->toDateString();
            $end   = Carbon::now()->toDateString();
        } else {
            $start = $ref->toDateString();
            $end   = $ref->copy()->endOfMonth()->toDateString();
        }

        // fetchGrossBilling já faz unset + gc após extrair o valor,
        // evitando estouro de memória com a resposta completa da API.
        // Phase 18.5: passa marketplace do model (default 'meli' preservado).
        $marketplace = $company->marketplace ?? 'meli';
        $grossBilling = $this->fetchGrossBilling($custId, $start, $end, cacheMinutes: 0, marketplace: $marketplace);

        return CompanyMonthlyRevenue::updateOrCreate(
            ['company_id' => $company->id, 'year_month' => $yearMonth],
            ['gross_revenue' => $grossBilling, 'synced_at' => now()]
        );
    }

    // ─── Métodos para o módulo Sugadores ────────────────────────────────────

    /**
     * Lista TODAS as campanhas de um custId, paginando até esgotar.
     * Cada item contém: campaignId, name, status, budget.
     */
    public function fetchAllCampaigns(string $custId, string $marketplace = 'meli'): array
    {
        $all  = [];
        $page = 1;

        do {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->get("{$this->baseUrl}/{$marketplace}/ads/{$custId}/campaigns", [
                    'page' => $page,
                ]);

            if ($response->failed()) {
                throw new \RuntimeException("Adman campanhas erro {$response->status()} custId={$custId} page={$page}");
            }

            $data       = $response->json() ?? [];
            $campaigns  = $data['campaigns'] ?? (isset($data[0]) ? $data : []);
            $totalPages = (int) ($data['totalPages'] ?? 1);

            $all = array_merge($all, $campaigns);
            $page++;
            if ($page <= $totalPages) usleep(300_000);
        } while ($page <= $totalPages);

        return $all;
    }

    /**
     * Lista campanhas candidatas a destino da ação "Mover sugador" — convenção
     * do time: campanha de quarentena tem nome contendo SGI/Sugador/Sugadores
     * e fica pausada. Filtro client-side em cima do fetchAllCampaigns; sem
     * métricas (só id/name/status).
     *
     * Cada item: ['id' => string, 'name' => string, 'status' => string|null]
     */
    public function fetchSugadorCampaigns(string $custId, string $marketplace = 'meli'): array
    {
        $campaigns = $this->fetchAllCampaigns($custId, $marketplace);
        $out = [];

        foreach ($campaigns as $c) {
            $id     = (string) ($c['campaignId'] ?? $c['id'] ?? '');
            $name   = (string) ($c['name'] ?? '');
            $status = $c['status'] ?? null;

            if ($id === '' || $name === '') continue;

            // Match igual ao usado em SugadorAnalysisService::QUARANTINE_NAME_REGEX
            // pra que "candidata a destino" e "campanha já em quarentena" tenham
            // exatamente a mesma definição em todo o módulo.
            if (!preg_match('/\b(sgi|sugadores?)\b/iu', $name)) continue;

            // Filtra pausadas — a convenção é mover pra campanha SGI parada
            // (não-ativa) pra não gerar veiculação. Aceita também closed/ended.
            $statusLower = $status ? strtolower((string) $status) : '';
            if (!in_array($statusLower, ['paused', 'closed', 'ended'], true)) continue;

            $out[] = [
                'id'     => $id,
                'name'   => $name,
                'status' => $status,
            ];
        }

        // Ordena por nome para UX previsível no select
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Retorna campanhas de um custId já enriquecidas com métricas no range
     * (1 chamada de listagem + 1 chamada de métricas por campanha).
     *
     * Cada item retornado:
     * [
     *   'campaign_id'     => string,
     *   'campaign_name'   => string|null,
     *   'campaign_status' => string|null,  // active/paused etc
     *   'investment'      => float|null,
     *   'revenue'         => float|null,
     *   'sold_quantity'   => int|null,
     *   'clicks'          => int|null,
     *   'impressions'     => int|null,
     *   'cpc'             => float|null,
     *   'acos'            => float|null,   // já em %
     *   'roas'            => float|null,
     *   'raw'             => array,        // payload bruto da Adman pra debug
     * ]
     */
    public function fetchCampaignsRange(string $custId, string $dateFrom, string $dateTo, string $marketplace = 'meli'): array
    {
        $campaigns = $this->fetchAllCampaigns($custId, $marketplace);
        $enriched  = [];

        foreach ($campaigns as $campaign) {
            $campaignId = (string) ($campaign['campaignId'] ?? $campaign['id'] ?? '');
            if ($campaignId === '') continue;

            try {
                $cm  = $this->fetchCampaignMetrics($custId, $campaignId, $dateFrom, $dateTo, $marketplace);
                $val = fn($key) => is_array($cm[$key] ?? null)
                    ? ($cm[$key]['value'] ?? null)
                    : ($cm[$key] ?? null);

                $enriched[] = [
                    'campaign_id'     => $campaignId,
                    'campaign_name'   => $campaign['name']   ?? $cm['name']   ?? null,
                    'campaign_status' => $campaign['status'] ?? $cm['status'] ?? null,
                    'investment'      => $val('investment')   !== null ? (float) $val('investment')   : null,
                    'revenue'         => $val('revenue')      !== null ? (float) $val('revenue')      : null,
                    'sold_quantity'   => $val('soldQuantity') !== null ? (int)   $val('soldQuantity') : null,
                    'clicks'          => $val('clicks')       !== null ? (int)   $val('clicks')       : null,
                    'impressions'     => $val('impressions')  !== null ? (int)   $val('impressions')  : null,
                    'cpc'             => $val('cpc')          !== null ? (float) $val('cpc')          : null,
                    'acos'            => $val('acos')         !== null ? (float) $val('acos')         : null,
                    'roas'            => $val('roas')         !== null ? (float) $val('roas')         : null,
                    'raw'             => $cm,
                ];

                usleep(100_000);
            } catch (\Throwable $e) {
                Log::warning("[Adman/Sugadores] Métricas campanha {$campaignId}: " . $e->getMessage());
            }
        }

        return $enriched;
    }

    /**
     * Métricas em range de TODOS os adgroups de um custId, com paginação.
     * Adgroup é a unidade prática de "anúncio" no MLB Ads — cada adgroup
     * contém 1+ MLBs e tem métricas próprias (clicks, cpc, etc).
     *
     * Cada item retornado:
     * [
     *   'adgroup_id'      => string,
     *   'adgroup_name'    => string|null,
     *   'campaign_id'     => string,
     *   'status'          => string|null,
     *   'thumbnail'       => string|null,   // URL da imagem do produto
     *   'adgroup_type'    => string|null,   // CATALOG/PRODUCT/MANUAL
     *   'catalog_listing' => bool,
     *   'investment'      => float|null,
     *   'revenue'         => float|null,    // derivado de directAmount/totalAmount
     *   'sold_quantity'   => int|null,
     *   'clicks'          => int|null,
     *   'impressions'     => int|null,
     *   'cpc'             => float|null,
     *   'ctr'             => float|null,
     *   'acos'            => float|null,
     *   'roas'            => float|null,
     *   'organic_amount'  => float|null,    // receita orgânica no período (contexto)
     *   'organic_units'   => int|null,
     *   'raw'             => array,
     * ]
     */
    public function fetchAdsMetrics(string $custId, string $dateFrom, string $dateTo, int $itemsPerPage = 50, string $marketplace = 'meli'): array
    {
        // Adman cap: itemsPerPage > 50 retorna HTTP 400 ("itemsPerPage must be less than 50").
        $itemsPerPage = min($itemsPerPage, 50);
        $all  = [];
        $page = 1;

        do {
            // Adman aplica rate-limit ao endpoint /adgroups/metrics — retry exponencial em 429
            // e 5xx é necessário pra contas grandes (centenas de páginas).
            $response = null;
            $maxAttempts = 5;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $response = Http::withHeaders($this->headers())
                    ->timeout(60)
                    ->get("{$this->baseUrl}/{$marketplace}/ads/{$custId}/adgroups/metrics", [
                        'dateFrom'     => $dateFrom,
                        'dateTo'       => $dateTo,
                        'page'         => $page,
                        'itemsPerPage' => $itemsPerPage,
                    ]);

                $status = $response->status();
                $isTransient = $status === 429 || ($status >= 500 && $status < 600);
                if (!$isTransient || $attempt === $maxAttempts) {
                    break;
                }

                // Respeita Retry-After se a Adman mandar, senão backoff exponencial: 1s, 2s, 4s, 8s
                $retryAfter = (int) ($response->header('Retry-After') ?? 0);
                $sleepSec = $retryAfter > 0 ? min($retryAfter, 30) : (2 ** ($attempt - 1));
                sleep($sleepSec);
            }

            if ($response->failed()) {
                $body = trim(substr($response->body(), 0, 300));
                throw new \RuntimeException("Adman adgroups metrics erro {$response->status()} custId={$custId} page={$page}: {$body}");
            }

            $data       = $response->json() ?? [];
            $adgroups   = $data['adgroups'] ?? [];
            $totalPages = (int) ($data['totalPages'] ?? 1);

            foreach ($adgroups as $ag) {
                $m   = $ag['metrics'] ?? [];
                $val = fn($key) => is_array($m[$key] ?? null)
                    ? ($m[$key]['value'] ?? null)
                    : ($m[$key] ?? null);

                // Receita do adgroup: preferimos totalAmount (direto + indireto) se disponível,
                // senão directAmount, senão null.
                $revenue = $val('totalAmount') ?? $val('directAmount');

                $all[] = [
                    'adgroup_id'      => (string) ($ag['adgroupId'] ?? ''),
                    'adgroup_name'    => $ag['name']        ?? null,
                    'campaign_id'     => (string) ($ag['campaignId'] ?? ''),
                    'status'          => $ag['status']      ?? null,
                    'thumbnail'       => $ag['thumbnail']      ?? null,
                    'adgroup_type'    => $ag['adGroupType']    ?? null,
                    'catalog_listing' => (bool) ($ag['catalogListing'] ?? false),
                    'investment'      => $val('investment')   !== null ? (float) $val('investment')   : null,
                    'revenue'         => $revenue             !== null ? (float) $revenue             : null,
                    'sold_quantity'   => $val('unitsQuantity') !== null ? (int)  $val('unitsQuantity') : null,
                    'clicks'          => $val('clicks')       !== null ? (int)   $val('clicks')       : null,
                    'impressions'     => $val('impressions')  !== null ? (int)   $val('impressions')  : null,
                    'cpc'             => $val('cpc')          !== null ? (float) $val('cpc')          : null,
                    'ctr'             => $val('ctr')          !== null ? (float) $val('ctr')          : null,
                    'acos'            => $val('acos')         !== null ? (float) $val('acos')         : null,
                    'roas'            => $val('roas')         !== null ? (float) $val('roas')         : null,
                    'organic_amount'  => $val('organicAmount')        !== null ? (float) $val('organicAmount')        : null,
                    'organic_units'   => $val('organicUnitsQuantity') !== null ? (int)   $val('organicUnitsQuantity') : null,
                    'raw'             => $ag,
                ];
            }

            $page++;
            if ($page <= $totalPages) usleep(100_000);
        } while ($page <= $totalPages);

        return $all;
    }

}
