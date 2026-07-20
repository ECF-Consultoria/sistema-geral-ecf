<?php

namespace Tests\Feature\V18;

use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 101 Plan 01 — AdmanMetricDiffService (v18.0).
 *
 * Cobre ADM-01/02/03/05: leitura AO VIVO do diff de período pronto da Adman
 * (`.diff`), gate por `comparison_mode`, e o `calculated_fallback` com os
 * guards já cicatrizados por 3 rounds de bugs de produção (margem_dias +
 * interseção de dias-comuns).
 *
 * Fixtures = payloads REAIS capturados no research (empresa id 242, range
 * 2026-07-01..2026-07-18) — ver 101-RESEARCH.md.
 *
 * @see .planning/phases/101-admanmetricdiffservice-v18-0/101-01-PLAN.md
 * @see .planning/phases/101-admanmetricdiffservice-v18-0/101-RESEARCH.md
 */
class AdmanMetricDiffServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payload real de /performance/{custId} (summarizedData) — research §41-63.
     */
    private function respostaPerformance(): array
    {
        return [
            'summarizedData' => [
                'grossBilling'  => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'profitMargin'  => ['value' => 141428.81, 'diff' => 147.75, 'prev' => 57084.83],
                'investment'    => ['value' => 9990.82,   'diff' => 81.96,  'prev' => 5490.65],
                'profitShare'   => 26.64,
            ],
            'items' => [],
        ];
    }

    /**
     * Payload real de /accounts/{custId}/metrics (metrics) — research §67-81.
     */
    private function respostaAccountMetrics(): array
    {
        return [
            'metrics' => [
                'billing'           => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'      => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin'  => ['value' => 27.47,     'diff' => 14.09,  'prev' => 24.08],
                'investment'        => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }

    // ─────────────────────── Task 1 — leitura detalhada aditiva ───────────────────────

    /**
     * fetchAccountMetricsDetailedCached preserva value+diff+prev por campo —
     * com o fixture real, percentageMargin => value 27.47, diff 14.09, prev 24.08.
     */
    public function test_fetch_account_metrics_detailed_cached_preserva_value_diff_prev(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);

        $service = new AdmanService();
        $detalhado = $service->fetchAccountMetricsDetailedCached('CUST1', '2026-07-01', '2026-07-18');

        $this->assertNotNull($detalhado);
        $this->assertSame(27.47, $detalhado['percentageMargin']['value']);
        $this->assertSame(14.09, $detalhado['percentageMargin']['diff']);
        $this->assertSame(24.08, $detalhado['percentageMargin']['prev']);
    }

    /**
     * REGRESSÃO: fetchAccountMetricsCached continua retornando o shape
     * simplificado {acos,tacos,investment,liquid_margin,percentage_margin,billing}
     * só com floats — inalterado (mesmo fixture).
     */
    public function test_fetch_account_metrics_cached_simplificado_permanece_inalterado(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);

        $service = new AdmanService();
        $simples = $service->fetchAccountMetricsCached('CUST1', '2026-07-01', '2026-07-18');

        $this->assertNotNull($simples);
        $this->assertSame(
            ['acos', 'tacos', 'investment', 'liquid_margin', 'percentage_margin', 'billing'],
            array_keys($simples)
        );
        $this->assertSame(530797.73, $simples['billing']);
        $this->assertSame(27.47, $simples['percentage_margin']);
        $this->assertSame(141428.81, $simples['liquid_margin']);
    }
}
