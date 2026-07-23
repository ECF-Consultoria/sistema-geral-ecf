<?php

namespace Tests\Unit\Metrics;

use App\Models\Company;
use App\Services\Metrics\AdmanMetricDiffService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\ShopeeMetricDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fase 109 Plan 01 (SHOP-CAR-01) — MetricDiffDispatcher.
 *
 * Roteador puro por `financial_source` ('adman'|'shopee'). T-109-02: fonte
 * fora da whitelist lança `InvalidArgumentException` (nunca cai num branch
 * silencioso).
 *
 * @see .planning/phases/109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o/109-01-PLAN.md
 */
class MetricDiffDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function periodo(): array
    {
        return [
            'current_start'   => '2026-07-01',
            'current_end'     => '2026-07-18',
            'baseline_start'  => '2026-06-01',
            'baseline_end'    => '2026-06-18',
            'comparison_mode' => 'same_interval_previous_month',
        ];
    }

    public function test_source_adman_delega_ao_admanmetricdiffservice(): void
    {
        $company = Company::factory()->create();
        $periodo = $this->periodo();
        $esperado = ['company_id' => $company->id, 'metrics' => [], 'fonte_teste' => 'adman'];

        $admanMock = \Mockery::mock(AdmanMetricDiffService::class);
        $admanMock->shouldReceive('compute')->once()->with($company, $periodo)->andReturn($esperado);
        $this->app->instance(AdmanMetricDiffService::class, $admanMock);

        $dispatcher = $this->app->make(MetricDiffDispatcher::class);
        $resultado  = $dispatcher->compute($company, $periodo, 'adman');

        $this->assertSame($esperado, $resultado);
    }

    public function test_source_shopee_delega_ao_shopeemetricdiffservice(): void
    {
        $company = Company::factory()->create();
        $periodo = $this->periodo();
        $esperado = ['company_id' => $company->id, 'metrics' => [], 'fonte_teste' => 'shopee'];

        $shopeeMock = \Mockery::mock(ShopeeMetricDiffService::class);
        $shopeeMock->shouldReceive('compute')->once()->with($company, $periodo)->andReturn($esperado);
        $this->app->instance(ShopeeMetricDiffService::class, $shopeeMock);

        $dispatcher = $this->app->make(MetricDiffDispatcher::class);
        $resultado  = $dispatcher->compute($company, $periodo, 'shopee');

        $this->assertSame($esperado, $resultado);
    }

    public function test_source_invalida_lanca_excecao(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->app->make(MetricDiffDispatcher::class);

        $this->expectException(InvalidArgumentException::class);

        $dispatcher->compute($company, $this->periodo(), 'ml_direto');
    }
}
