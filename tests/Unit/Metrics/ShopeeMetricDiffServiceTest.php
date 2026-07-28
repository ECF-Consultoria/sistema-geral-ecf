<?php

namespace Tests\Unit\Metrics;

use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Services\Metrics\ShopeeMetricDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        // Fase 117 (MPP-05, D-06): shape deixou de ser uniforme — só
        // contribution_margin_pct ganha diff_pp (espelhando o Adman).
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['metrics']['revenue']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['metrics']['contribution_margin_value']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_pp', 'diff_source'], array_keys($resultado['metrics']['contribution_margin_pct']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['investment']));
        // NÃO tocar — a D-08 (indicador de cobertura de diff_pp) entra só no
        // Adman; o quality da Shopee continua com exatamente 3 chaves.
        $this->assertSame(['status', 'source', 'computed_at'], array_keys($resultado['quality']));
        $this->assertSame('shopee', $resultado['quality']['source']);
        // Margem sempre null -> nunca 'complete' no critério herdado do Adman.
        $this->assertSame('partial', $resultado['quality']['status']);
    }

    // ─────────────────────── Fase 117 (v21.0) — diff_pp/prev_value aditivos (MPP-05) ───────────────────────

    /**
     * MPP-05: diff_pp sempre null na margem % (Shopee não tem CMV), e diff_pp
     * é chave AUSENTE (não presente com null) em revenue/investment.
     */
    public function test_margem_diff_pp_sempre_null(): void
    {
        $company = Company::factory()->create();
        $this->semear($company, '2026-07-01', 100.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pp']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['prev_value']);
        $this->assertArrayNotHasKey('diff_pp', $resultado['metrics']['revenue']);
        $this->assertArrayNotHasKey('diff_pp', $resultado['investment']);
    }

    /**
     * `prev_value` real em revenue/investment — o MESMO `$anterior` que já
     * alimenta `diff_pct` (custo zero, simetria de shape com o Adman).
     */
    public function test_prev_value_real_em_revenue_e_investment(): void
    {
        $company = Company::factory()->create();

        $this->semear($company, '2026-07-01', 300.0, 30.0);
        $this->semear($company, '2026-06-01', 100.0, 10.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        $this->assertSame(100.0, $resultado['metrics']['revenue']['prev_value']);
        $this->assertSame(10.0, $resultado['investment']['prev_value']);
    }

    /**
     * `prev_value` de investment é `null` quando NENHUMA linha da baseline
     * tem `ad_expense` preenchido — semântica null-aware já existente
     * (`somaAdExpenseNullAware()`), preservada em `prev_value`.
     */
    public function test_prev_value_investment_null_quando_baseline_sem_ad_expense(): void
    {
        $company = Company::factory()->create();

        $this->semear($company, '2026-07-01', 100.0, 10.0);
        // Baseline fora do lookback de Ads: ad_expense NULL em toda a janela.
        $this->semear($company, '2026-06-01', 100.0, null);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        $this->assertNull($resultado['investment']['prev_value']);
    }

    /**
     * Bump de cache `v1`→`v2` (mesmo princípio de MPP-03 no Adman): uma
     * entrada `shopee:diff:v1:` gravada ANTES do deploy (shape antigo) não é
     * servida pós-deploy — compute() devolve o shape NOVO.
     */
    public function test_cache_v2_nao_reaproveita_shape_v1(): void
    {
        $company = Company::factory()->create();
        $periodo = $this->periodo();
        $this->semear($company, '2026-07-01', 100.0, 10.0);

        // Simula shape v1 (sem prev_value/diff_pp) já cacheado na chave ANTIGA.
        $cacheKeyV1 = "shopee:diff:v1:{$company->id}:{$periodo['current_start']}:{$periodo['current_end']}:" . now()->setTimezone(config('app.timezone'))->toDateString();
        Cache::put($cacheKeyV1, [
            'metrics' => [
                'revenue' => ['value' => 1.0, 'diff_pct' => 1.0, 'diff_source' => 'calculated_fallback'],
            ],
        ], 1440);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $periodo);

        $this->assertArrayHasKey('prev_value', $resultado['metrics']['revenue']);
        $this->assertArrayHasKey('diff_pp', $resultado['metrics']['contribution_margin_pct']);
        $this->assertSame(100.0, $resultado['metrics']['revenue']['value']);
    }

    /**
     * Guarda do placeholder da Fase 109: `contribution_margin_value` e
     * `contribution_margin_pct` continuam com `value`/`diff_pct`/`diff_source`
     * todos `null` — é dessa nulidade que o `margem_pontos = 1.0` da Fase 109
     * depende. Nada nesta task inventa margem para a Shopee.
     */
    public function test_placeholder_fase_109_preservado_margem_integralmente_nula(): void
    {
        $company = Company::factory()->create();
        $this->semear($company, '2026-07-01', 100.0, 10.0);

        $resultado = app(ShopeeMetricDiffService::class)->compute($company, $this->periodo());

        foreach (['contribution_margin_value', 'contribution_margin_pct'] as $chave) {
            $this->assertNull($resultado['metrics'][$chave]['value']);
            $this->assertNull($resultado['metrics'][$chave]['diff_pct']);
            $this->assertNull($resultado['metrics'][$chave]['diff_source']);
        }
    }
}
