<?php

namespace App\Services\Metrics;

use App\Contracts\MetricsProvider;
use App\Models\AdmanMetric;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Implementação Adman do contract MetricsProvider.
 *
 * Phase 60 (base multi-fonte backend ML+Adman unificado):
 * Lê exclusivamente da tabela `adman_metrics` local. NUNCA chama a API
 * Adman em runtime — o sync agendado (`AdmanService::syncCompany()` via
 * scheduler diário) é quem popula a tabela. Este provider é puro
 * DB-read, então coexiste 100% com {@see \App\Services\AdmanService}
 * sem modificá-lo e sem competir por rate limit.
 *
 * Decisões de design (Phase 60 Plan 60-02):
 *  - Constructor SEM dependências injetadas. Não recebe AdmanService
 *    (para não emular chamada HTTP), não recebe QueryBuilder — usa o
 *    model AdmanMetric diretamente (padrão do resto do projeto:
 *    DashboardController, PerformanceController, etc.).
 *  - `supports()` só olha o campo denormalizado `adman_account_id`
 *    (accessor legacy da Phase 57 mantido — ADR DATA-04 justifica).
 *    Empresa com adman_account_id mas ZERO rows em adman_metrics no
 *    período ainda é caso "suportado" — retorna DTO com campos numéricos
 *    `null` (não é erro, é histórico vazio).
 *  - TACOS é agregado como MÉDIA PONDERADA POR REVENUE quando revenue
 *    total > 0. Justificativa: cada row em `adman_metrics` já traz TACOS
 *    diário calculado pelo sync; somar TACOS não faz sentido; média
 *    simples ignora que dias com mais faturamento têm mais peso na
 *    métrica real. Se soma_revenue = 0, cai para média simples.
 *  - Campos exclusivos de ML por ADR DATA-04 (`clicks`, `impressions`,
 *    `acos`, `roas`, `orders_count`) retornam literalmente `null` — o
 *    provider NÃO tenta inferir/calcular. `contribution_margin` é
 *    Adman-only mas os call-sites atuais leem `contribution_margin_pct`
 *    da coluna direta; agregação ambígua sem período fixo, então
 *    retorna soma dos valores diários quando disponíveis (Adman grava
 *    valor absoluto por dia).
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — tabela
 * campo-a-campo e vocabulário do enum `source`.
 */
class AdmanMetricsProvider implements MetricsProvider
{
    public function supports(Company $company): bool
    {
        // Guard denormalizado (accessors legacy Phase 57 — ADR DATA-04).
        // Não consulta pivot company_marketplaces para não onerar hotpath.
        return ! empty($company->adman_account_id);
    }

    public function name(): string
    {
        return 'adman';
    }

    public function readForCompany(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        $rows = AdmanMetric::query()
            ->where('company_id', $company->id)
            ->whereBetween('reference_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        // Coleção vazia: DTO válido com todos numéricos null (histórico vazio
        // NÃO é erro — permite composição com MlMetricsProvider no Plan 60-04).
        if ($rows->isEmpty()) {
            return $this->emptyDto($company, $from, $to);
        }

        // Somas simples para métricas absolutas.
        $revenue       = $this->sumNullable($rows, 'revenue');
        $adSpend       = $this->sumNullable($rows, 'ad_spend');
        $soldQuantity  = $this->sumNullableInt($rows, 'sold_quantity');
        $netBilling    = $this->sumNullable($rows, 'net_billing');
        $salesFee      = $this->sumNullable($rows, 'sales_fee');
        $taxes         = $this->sumNullable($rows, 'taxes');
        $shippingCost  = $this->sumNullable($rows, 'shipping_cost');
        $productCost   = $this->sumNullable($rows, 'product_cost');
        $contribMargin = $this->sumNullable($rows, 'contribution_margin');

        return new UnifiedMetricsDto(
            company_id:          $company->id,
            source:              'adman',
            period_from:         $from,
            period_to:           $to,
            revenue:             $revenue,
            ad_spend:            $adSpend,
            sold_quantity:       $soldQuantity,
            tacos:               self::tacosPonderado($rows),
            net_billing:         $netBilling,
            sales_fee:           $salesFee,
            taxes:               $taxes,
            shipping_cost:       $shippingCost,
            product_cost:        $productCost,
            contribution_margin: $contribMargin,
            // Campos exclusivos de ML por ADR DATA-04 tabela campo-a-campo —
            // Adman não expõe direto. Null é sinal explícito de "fonte não
            // cobre" (não confundir com "zero").
            acos:         null,
            roas:         null,
            clicks:       null,
            impressions:  null,
            orders_count: null,
        );
    }

    /**
     * DTO sentinela para empresa suportada mas sem rows no período.
     */
    private function emptyDto(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        return new UnifiedMetricsDto(
            company_id:          $company->id,
            source:              'adman',
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
     * Soma float ignorando valores null. Retorna null se TODOS os valores
     * do campo forem null — assim distingue "zero real" de "fonte sem dado".
     */
    private function sumNullable(Collection $rows, string $field): ?float
    {
        $values = $rows->pluck($field)->filter(fn ($v) => $v !== null);
        if ($values->isEmpty()) {
            return null;
        }
        return (float) $values->sum();
    }

    /**
     * Soma int ignorando valores null. Ver justificativa em sumNullable.
     */
    private function sumNullableInt(Collection $rows, string $field): ?int
    {
        $values = $rows->pluck($field)->filter(fn ($v) => $v !== null);
        if ($values->isEmpty()) {
            return null;
        }
        return (int) $values->sum();
    }

    /**
     * TACOS agregado como MÉDIA PONDERADA POR REVENUE.
     *
     *   TACOS_agg = SUM(revenue_i * tacos_i) / SUM(revenue_i)
     *
     * Justificativa: TACOS diário é uma taxa (%). Somar taxas não tem
     * significado; média aritmética ignora que dias com mais faturamento
     * têm peso maior no TACOS real do período. Ponderar por revenue é
     * o cálculo estatisticamente correto (mesma lógica que
     * DashboardController usa em outros contextos).
     *
     * Fallback: se soma revenue = 0 (ou todas as revenues são null), cai
     * para média aritmética simples dos TACOS não-null. Se TODAS as
     * TACOS forem null, retorna null.
     */
    private static function tacosPonderado(Collection $rows): ?float
    {
        // Filtra rows onde tacos não é null — se todas forem null, sinaliza
        // "fonte sem dado" retornando null.
        $comTacos = $rows->filter(fn ($r) => $r->tacos !== null);
        if ($comTacos->isEmpty()) {
            return null;
        }

        $sumRevenue = (float) $comTacos->sum(fn ($r) => (float) ($r->revenue ?? 0));

        if ($sumRevenue > 0) {
            $sumWeighted = (float) $comTacos->sum(
                fn ($r) => ((float) ($r->revenue ?? 0)) * ((float) $r->tacos),
            );
            return $sumWeighted / $sumRevenue;
        }

        // Fallback: média simples quando não há revenue para ponderar.
        return (float) $comTacos->avg(fn ($r) => (float) $r->tacos);
    }
}
