<?php

namespace App\Services\Metrics;

use Carbon\Carbon;

/**
 * DTO imutável com shape canônico das métricas unificadas por empresa+período.
 *
 * Phase 60 (base multi-fonte backend ML+Adman unificado): shape único
 * consumido pelos dashboards (Phase 61) e produzido pelos providers
 * (`AdmanMetricsProvider` no 60-02, `MlMetricsProvider` no 60-03) e pelo
 * `UnifiedMetricsService` (60-04). Trava a superfície de dados para evitar
 * cada consumidor inventar chaves locais.
 *
 * Total de 19 propriedades = 4 metadados + 15 campos numéricos:
 *
 *  Metadados (sempre preenchidos, nunca null):
 *    - `company_id`   int    — FK companies.id
 *    - `source`       string — enum 4 valores ADR DATA-04: 'adman'|'ml'|'unified'|'none'
 *    - `period_from`  Carbon — data inicial do range (inclusive)
 *    - `period_to`    Carbon — data final do range (inclusive)
 *
 *  Campos numéricos (nullable — null representa "fonte não expõe" ou "sem dados"):
 *    - `revenue`             — faturamento bruto
 *    - `ad_spend`            — investimento em ads
 *    - `sold_quantity`       — quantidade vendida (unidades)
 *    - `tacos`               — Total Advertising Cost of Sale (%)
 *    - `net_billing`         — faturamento líquido (só Adman)
 *    - `sales_fee`           — comissão de vendas
 *    - `taxes`               — impostos (só Adman)
 *    - `shipping_cost`       — custo de frete (só Adman)
 *    - `product_cost`        — custo do produto (só Adman)
 *    - `contribution_margin` — margem de contribuição (só Adman)
 *    - `acos`                — Advertising Cost of Sale (só ML)
 *    - `roas`                — Return on Ad Spend (só ML)
 *    - `clicks`              — cliques em ads (só ML)
 *    - `impressions`         — impressões em ads (só ML)
 *    - `orders_count`        — número de pedidos (só ML)
 *
 * Imutabilidade: classe `final readonly` (PHP 8.2+) — nenhum setter,
 * nenhum mutation após construção. Threat T-60-02-01.
 *
 * Serialização: `toArray()` produz array com Carbon serializado como
 * string `'Y-m-d'` (ISO date sem hora — período é diário).
 *
 * Referência canônica: ADR `.planning/adrs/DATA-04-precedencia-multifonte.md`.
 */
final readonly class UnifiedMetricsDto
{
    /**
     * @param int         $company_id          FK companies.id (sempre preenchido)
     * @param string      $source              Enum ADR DATA-04: 'adman'|'ml'|'unified'|'none'
     * @param Carbon      $period_from         Data inicial do range (inclusive)
     * @param Carbon      $period_to           Data final do range (inclusive)
     * @param float|null  $revenue             Faturamento bruto no período (soma)
     * @param float|null  $ad_spend            Investimento em ads no período (soma)
     * @param int|null    $sold_quantity       Unidades vendidas no período (soma)
     * @param float|null  $tacos               TACOS no período (média ponderada por revenue quando aplicável)
     * @param float|null  $net_billing         Faturamento líquido (exclusivo Adman)
     * @param float|null  $sales_fee           Comissão de vendas no período (soma)
     * @param float|null  $taxes               Impostos (exclusivo Adman)
     * @param float|null  $shipping_cost       Custo de frete (exclusivo Adman)
     * @param float|null  $product_cost        Custo do produto (exclusivo Adman)
     * @param float|null  $contribution_margin Margem de contribuição (exclusivo Adman)
     * @param float|null  $acos                ACOS (exclusivo ML — calculado sobre attributed sales)
     * @param float|null  $roas                ROAS (exclusivo ML)
     * @param int|null    $clicks              Cliques em ads (exclusivo ML)
     * @param int|null    $impressions         Impressões em ads (exclusivo ML)
     * @param int|null    $orders_count        Número de pedidos (exclusivo ML — Adman não expõe direto)
     */
    public function __construct(
        public int $company_id,
        public string $source,
        public Carbon $period_from,
        public Carbon $period_to,
        public ?float $revenue,
        public ?float $ad_spend,
        public ?int $sold_quantity,
        public ?float $tacos,
        public ?float $net_billing,
        public ?float $sales_fee,
        public ?float $taxes,
        public ?float $shipping_cost,
        public ?float $product_cost,
        public ?float $contribution_margin,
        public ?float $acos,
        public ?float $roas,
        public ?int $clicks,
        public ?int $impressions,
        public ?int $orders_count,
    ) {
    }

    /**
     * Serialização para array — Carbon vira string `'Y-m-d'`.
     *
     * Usado por dashboards Phase 61 que precisam de payload plain-array
     * (JSON, cache, Inertia props). Ordem de chaves preserva a ordem do
     * constructor para facilitar inspection.
     */
    public function toArray(): array
    {
        return [
            'company_id'          => $this->company_id,
            'source'              => $this->source,
            'period_from'         => $this->period_from->format('Y-m-d'),
            'period_to'           => $this->period_to->format('Y-m-d'),
            'revenue'             => $this->revenue,
            'ad_spend'            => $this->ad_spend,
            'sold_quantity'       => $this->sold_quantity,
            'tacos'               => $this->tacos,
            'net_billing'         => $this->net_billing,
            'sales_fee'           => $this->sales_fee,
            'taxes'               => $this->taxes,
            'shipping_cost'       => $this->shipping_cost,
            'product_cost'        => $this->product_cost,
            'contribution_margin' => $this->contribution_margin,
            'acos'                => $this->acos,
            'roas'                => $this->roas,
            'clicks'              => $this->clicks,
            'impressions'         => $this->impressions,
            'orders_count'        => $this->orders_count,
        ];
    }
}
