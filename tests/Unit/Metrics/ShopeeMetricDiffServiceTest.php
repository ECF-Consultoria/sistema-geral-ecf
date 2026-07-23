<?php

namespace Tests\Unit\Metrics;

use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Services\Metrics\ShopeeMetricDiffService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 109 Plan 01 (SHOP-CAR-01) — ShopeeMetricDiffService.
 *
 * Espelha o contrato de `AdmanMetricDiffService::compute()` (mesmo shape:
 * company_id/period/metrics/quality), mas 100% leitura local (sem HTTP) e
 * SEM margem — `contribution_margin_value`/`contribution_margin_pct`
 * SEMPRE null. Campo extra `investment` (fora de `metrics`) expõe
 * `ad_expense`, null-aware (distingue "sem dado de Ads" de "R$ 0 investido").
 *
 * @see .planning/phases/109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o/109-01-PLAN.md
 */
class ShopeeMetricDiffServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Período com janela current de 3 dias e baseline de 3 dias (meses distintos). */
    private function periodo(): array
    {
        return [
            'mode'                   => 'closed_period',
            'period_key'             => 'custom',
            'current_start'          => '2026-07-01',
            'current_end'            => '2026-07-03',
            'baseline_start'         => '2026-06-01',
            'baseline_end'           => '2026-06-03',
            'days_count'             => 3,
            'comparison_mode'        => 'previous_equal_length_window',
            'timezone'               => 'America/Sao_Paulo',
            'data_fresh_until'       => '2026-07-03',
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => false,
            'is_closed'              => true,
        ];
    }

    /** Semeia uma linha diária de ShopeeMetric. */
    private function semear(Company $company, string $dia, float $revenue, ?float $adExpense = null): void
    {
        ShopeeMetric::create([
            'company_id'     => $company->id,
            'reference_date' => $dia,
            'revenue'        => $revenue,
            'orders_count'   => 0,
            'sold_quantity'  => 0,
            'ad_expense'     => $adExpense,
        ]);
    }

    // ─────────────────────── revenue: soma + diff_pct ───────────────────────

    public function test_revenue_soma_e_diff_pct_corretos(): void
    {
        $company = Company::factory()->create();

        // Current: 100+100+100 = 300. Baseline: 100+100+100 = 300 (dobrado depois).
        $this->semear($company, '2026-07-01', 100.0);
        $this->semear($company, '2026-07-02', 200.0);
        $this->semear($company, '2026-07-03', 300.0);

        $this->semear($company, '2026-06-01', 100.0);
        $this->semear($company, '2026-06-02', 100.0);
        $this->semear($company, '2026-06-03', 100.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        // current = 600, baseline = 300 -> diff_pct = 100%
        $this->assertSame(600.0, $resultado['metrics']['revenue']['value']);
        $this->assertSame(100.0, $resultado['metrics']['revenue']['diff_pct']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['revenue']['diff_source']);
    }

    public function test_baseline_zero_diff_pct_null(): void
    {
        $company = Company::factory()->create();

        $this->semear($company, '2026-07-01', 500.0);
        // Sem NENHUMA linha no baseline -> soma = 0 -> guard baseline<=0 -> null.

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        $this->assertSame(500.0, $resultado['metrics']['revenue']['value']);
        $this->assertNull($resultado['metrics']['revenue']['diff_pct']);
    }

    public function test_dia_sem_linha_na_janela_conta_como_zero(): void
    {
        $company = Company::factory()->create();

        // Só 2 dos 3 dias da janela current têm linha (07-02 ausente).
        $this->semear($company, '2026-07-01', 100.0);
        $this->semear($company, '2026-07-03', 100.0);

        $this->semear($company, '2026-06-01', 100.0);
        $this->semear($company, '2026-06-02', 100.0);
        $this->semear($company, '2026-06-03', 100.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        // Dia ausente conta como zero (não infla, não quebra): soma = 200.
        $this->assertSame(200.0, $resultado['metrics']['revenue']['value']);
    }

    // ─────────────────────── margem sempre null ───────────────────────

    public function test_margem_valor_e_pct_sempre_null(): void
    {
        $company = Company::factory()->create();
        $this->semear($company, '2026-07-01', 100.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        $this->assertNull($resultado['metrics']['contribution_margin_value']['value']);
        $this->assertNull($resultado['metrics']['contribution_margin_value']['diff_pct']);
        $this->assertNull($resultado['metrics']['contribution_margin_value']['diff_source']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['value']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_source']);
    }

    // ─────────────────────── investment: null-aware ───────────────────────

    public function test_investment_null_quando_janela_toda_tem_ad_expense_null(): void
    {
        $company = Company::factory()->create();

        // Ads fora do lookback de 6 meses -> ad_expense NULL em toda a janela.
        $this->semear($company, '2026-07-01', 100.0, null);
        $this->semear($company, '2026-07-02', 100.0, null);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        $this->assertNull($resultado['investment']['value']);
        $this->assertNull($resultado['investment']['diff_pct']);
    }

    public function test_investment_com_valor_quando_ha_ao_menos_um_dia_com_ad_expense(): void
    {
        $company = Company::factory()->create();

        $this->semear($company, '2026-07-01', 100.0, 10.0);
        $this->semear($company, '2026-07-02', 100.0, null);

        $this->semear($company, '2026-06-01', 100.0, 5.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        // Soma só das linhas NOT NULL: 10.0 (dia sem Ads não conta como zero investido).
        $this->assertSame(10.0, $resultado['investment']['value']);
        // baseline = 5.0 -> diff_pct = (10-5)/5*100 = 100%
        $this->assertSame(100.0, $resultado['investment']['diff_pct']);
    }

    // ─────────────────────── shape idêntico ao Adman ───────────────────────

    public function test_shape_identico_ao_adman(): void
    {
        $company = Company::factory()->create();
        $periodo = $this->periodo();
        $this->semear($company, '2026-07-01', 100.0, 10.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $periodo);

        $this->assertSame($company->id, $resultado['company_id']);
        $this->assertSame($periodo, $resultado['period']);
        $this->assertSame(
            ['revenue', 'contribution_margin_value', 'contribution_margin_pct'],
            array_keys($resultado['metrics'])
        );
        foreach ($resultado['metrics'] as $metric) {
            $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($metric));
        }
        $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($resultado['investment']));
        $this->assertSame(['status', 'source', 'computed_at'], array_keys($resultado['quality']));
        $this->assertSame('shopee', $resultado['quality']['source']);
        // Margem sempre null -> nunca 'complete' no critério herdado do Adman.
        $this->assertSame('partial', $resultado['quality']['status']);
    }
}
