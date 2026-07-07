<?php

namespace App\Services\Metrics;

use App\Contracts\MetricsProvider;
use App\Models\Company;
use App\Services\MercadoLivreService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Implementação ML do contract MetricsProvider (Phase 60 Plan 60-03).
 *
 * Lê métricas ML LIVE via `MercadoLivreService::fetchOrdersSummary`
 * (revenue, sold_quantity, orders_count, sales_fee) e `::fetchAdsSummary`
 * (ad_spend, clicks, impressions, acos, roas). Cache 15 min por par
 * `(empresa, período)` — respeita ADR DATA-04 estratégia de cache e o
 * orçamento de rate limit da API ML (Phase 28+).
 *
 * Design (Plan 60-03):
 *  - Constructor injeta `MercadoLivreService` — DI Laravel resolve; provider
 *    NÃO instancia HTTP client próprio, delegando toda a mecânica de token +
 *    endpoint para o service existente (coexistência total, zero modificação).
 *  - `supports()` checa `$company->mlToken?->status === 'active'` via
 *    `loadMissing('mlToken')` para evitar N+1 quando factory itera empresas.
 *  - `name()` retorna literal `'ml'` — chave em cache
 *    (`unified_metrics:{id}:ml:{from}:{to}`), badge UI (DATA-05) e campo
 *    `source` do DTO. Não traduzir.
 *  - `readForCompany()`:
 *      * Sem `supports()` → DTO com todos numéricos `null` e `source='ml'`,
 *        SEM chamar API. Evita queimar rate limit + 401 previsível.
 *      * Com `supports()` → `Cache::remember` com TTL 15 min sobre
 *        `fetchFromApi()` que executa orders + ads com isolamento de erro
 *        POR ENDPOINT (falha em orders não impacta ads, e vice-versa).
 *      * Falha de API é traduzida em bloco de campos `null` + `Log::warning`
 *        estruturado — nunca propaga exception (contract permite; ADR DATA-04
 *        exige composição sem quebra com Adman-side).
 *  - TACOS derivado no `buildDto`: `(ad_spend/revenue)*100` quando ambos > 0,
 *    senão null. ADR DATA-04 tabela campo-a-campo: ML derivado tem
 *    precedência sobre Adman quando ambos suportam (fusão fica no
 *    UnifiedMetricsService — Plan 60-04).
 *
 * Cobertura de campos DTO por este provider (ADR DATA-04):
 *  ML preenche: `revenue`, `ad_spend`, `sold_quantity`, `orders_count`,
 *               `sales_fee`, `clicks`, `impressions`, `acos`, `roas`, `tacos`.
 *  ML NÃO expõe (`null` sinaliza "fonte não cobre"): `net_billing`, `taxes`,
 *               `shipping_cost`, `product_cost`, `contribution_margin`.
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md`.
 */
class MlMetricsProvider implements MetricsProvider
{
    /**
     * TTL de cache por chave `unified_metrics:{company_id}:ml:{from}:{to}`.
     *
     * 15 minutos = 900s. Valor definido no ADR DATA-04 (Estratégia de cache ML)
     * para balancear frescor (mesma janela dos dashboards ML atuais) com custo
     * de rate limit em bursts de dashboards concorrentes.
     */
    private const CACHE_TTL_SECONDS = 900;

    public function __construct(private MercadoLivreService $mlService)
    {
    }

    public function supports(Company $company): bool
    {
        // Eager load para evitar N+1 quando factory itera lista de empresas
        // (ex: dashboards Phase 61 percorrem carteira do usuário).
        $company->loadMissing('mlToken');

        return optional($company->mlToken)->status === 'active';
    }

    public function name(): string
    {
        return 'ml';
    }

    public function readForCompany(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        // Guard denormalizado — empresa sem mlToken ativo retorna DTO com
        // nulls e SEM tocar na API (evita queimar rate limit + evita 401).
        if (! $this->supports($company)) {
            return $this->emptyDto($company, $from, $to);
        }

        $payload = Cache::remember(
            $this->cacheKey($company, $from, $to),
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchFromApi($company, $from, $to),
        );

        return $this->buildDto($company, $from, $to, $payload['orders'], $payload['ads']);
    }

    /**
     * Executa as 2 leituras ML (orders + ads) com isolamento de erro por
     * endpoint. Falha em orders NÃO impacta ads e vice-versa. Nunca lança —
     * em erro grava Log::warning e retorna nulls para o bloco falho.
     *
     * IMPORTANTE: o retorno DESTE método é o que fica cacheado. Se um dos
     * blocos falha e cachearmos os nulls por 15 min, o próximo hit terá
     * dados stale-null até TTL expirar. Trade-off consciente para não
     * queimar rate limit em bursts de retries — dashboards refletem
     * "sem dado" durante a janela.
     */
    private function fetchFromApi(Company $company, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        // ─── Bloco orders (fetchOrdersSummary) ───
        try {
            $raw = $this->mlService->fetchOrdersSummary($company, $fromStr, $toStr);
            $orders = [
                'revenue'       => isset($raw['revenue'])       ? (float) $raw['revenue']       : null,
                'sold_quantity' => isset($raw['sold_quantity']) ? (int)   $raw['sold_quantity'] : null,
                'orders_count'  => isset($raw['orders_count'])  ? (int)   $raw['orders_count']  : null,
                'sales_fee'     => isset($raw['sales_fee'])     ? (float) $raw['sales_fee']     : null,
            ];
        } catch (\Throwable $e) {
            // Log estruturado — sanitizado (não inclui mlToken/access_token
            // para evitar leak — mitigação T-60-03-02).
            Log::warning('[UnifiedMetrics][ML] Falha ao ler fetchOrdersSummary', [
                'company_id'  => $company->id,
                'period_from' => $fromStr,
                'period_to'   => $toStr,
                'error'       => $e->getMessage(),
            ]);
            $orders = [
                'revenue'       => null,
                'sold_quantity' => null,
                'orders_count'  => null,
                'sales_fee'     => null,
            ];
        }

        // ─── Bloco ads (fetchAdsSummary) ───
        try {
            $raw = $this->mlService->fetchAdsSummary($company, $fromStr, $toStr);
            $ads = [
                'ad_spend'    => isset($raw['ad_spend'])    ? (float) $raw['ad_spend']    : null,
                'clicks'      => isset($raw['clicks'])      ? (int)   $raw['clicks']      : null,
                'impressions' => isset($raw['impressions']) ? (int)   $raw['impressions'] : null,
                'acos'        => isset($raw['acos'])        ? (float) $raw['acos']        : null,
                'roas'        => isset($raw['roas'])        ? (float) $raw['roas']        : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[UnifiedMetrics][ML] Falha ao ler fetchAdsSummary', [
                'company_id'  => $company->id,
                'period_from' => $fromStr,
                'period_to'   => $toStr,
                'error'       => $e->getMessage(),
            ]);
            $ads = [
                'ad_spend'    => null,
                'clicks'      => null,
                'impressions' => null,
                'acos'        => null,
                'roas'        => null,
            ];
        }

        return ['orders' => $orders, 'ads' => $ads];
    }

    /**
     * Monta o DTO na ordem exata do constructor de UnifiedMetricsDto (19 props).
     *
     * TACOS derivado: `(ad_spend / revenue) * 100`. Só calculado quando AMBOS
     * são numéricos > 0 — se algum for null ou zero, retorna null (evita
     * divisão-por-zero e evita produzir 0.0 quando nenhum dos dois bloc
     * respondeu).
     */
    private function buildDto(
        Company $company,
        Carbon $from,
        Carbon $to,
        array $orders,
        array $ads,
    ): UnifiedMetricsDto {
        $tacos = null;
        if (
            $orders['revenue'] !== null && $orders['revenue'] > 0
            && $ads['ad_spend'] !== null && $ads['ad_spend'] > 0
        ) {
            $tacos = ($ads['ad_spend'] / $orders['revenue']) * 100;
        }

        return new UnifiedMetricsDto(
            company_id:          $company->id,
            source:              'ml',
            period_from:         $from,
            period_to:           $to,
            revenue:             $orders['revenue'],
            ad_spend:            $ads['ad_spend'],
            sold_quantity:       $orders['sold_quantity'],
            tacos:               $tacos,
            // Campos exclusivos Adman por ADR DATA-04 tabela campo-a-campo —
            // ML não expõe. Null é sinal explícito de "fonte não cobre".
            net_billing:         null,
            sales_fee:           $orders['sales_fee'],
            taxes:               null,
            shipping_cost:       null,
            product_cost:        null,
            contribution_margin: null,
            // Campos ML-canonical (Ads API).
            acos:                $ads['acos'],
            roas:                $ads['roas'],
            clicks:              $ads['clicks'],
            impressions:         $ads['impressions'],
            orders_count:        $orders['orders_count'],
        );
    }

    /**
     * DTO sentinela para empresa NÃO suportada (sem mlToken active).
     *
     * `source='ml'` mesmo assim — este provider afirma sua identidade; a
     * escolha de emitir `source='none'` é responsabilidade do
     * `UnifiedMetricsService` (Plan 60-04) quando NENHUMA integração está
     * disponível para a empresa.
     */
    private function emptyDto(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        return new UnifiedMetricsDto(
            company_id:          $company->id,
            source:              'ml',
            period_from:         $from,
            period_to:           $to,
            revenue:             null,
            ad_spend:            null,
            sold_quantity:       null,
            tacos:               null,
            net_billing:         null,
            sales_fee:           null,
            taxes:               null,
            shipping_cost:       null,
            product_cost:        null,
            contribution_margin: null,
            acos:                null,
            roas:                null,
            clicks:              null,
            impressions:         null,
            orders_count:        null,
        );
    }

    /**
     * Chave canônica de cache por par `(empresa, período, ml)`.
     *
     * Formato: `unified_metrics:{company_id}:ml:{from}:{to}` — o segmento
     * `ml` evita colisão com o cache futuro do UnifiedMetricsService
     * (`source='unified'`). ADR DATA-04 Estratégia de cache detalha o esquema.
     */
    private function cacheKey(Company $company, Carbon $from, Carbon $to): string
    {
        return "unified_metrics:{$company->id}:ml:{$from->toDateString()}:{$to->toDateString()}";
    }
}
