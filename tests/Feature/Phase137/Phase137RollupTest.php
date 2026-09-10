<?php

namespace Tests\Feature\Phase137;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 03 — Tarefa 2: FechamentoRollupService.
 *
 * Cobre D-06 (mês-calendário fechado, nunca janela móvel de 30 dias) e D-05
 * /D-07 (ML + Shopee somados, Shopee entra na soma mesmo sozinho). O item
 * do último dia do mês prova a armadilha de `whereBetween` vs `whereDate`
 * documentada no plano (137-03, tarefa 2).
 */
class Phase137RollupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── janela() ────────────────────────────────────────────────────────

    #[Test]
    public function janela_de_mes_fechado_devolve_o_mes_calendario_completo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $service = app(FechamentoRollupService::class);
        $janela  = $service->janela('2026-08');

        $this->assertSame('2026-08-01', $janela['inicio']->toDateString());
        $this->assertSame('2026-08-31', $janela['fim']->toDateString());
    }

    #[Test]
    public function janela_do_mes_corrente_vai_ate_hoje_nunca_30_dias_para_tras(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $service = app(FechamentoRollupService::class);
        $janela  = $service->janela('2026-09');

        $this->assertSame('2026-09-01', $janela['inicio']->toDateString());
        $this->assertSame('2026-09-02', $janela['fim']->toDateString());
    }

    // ─── porEmpresa() ────────────────────────────────────────────────────

    #[Test]
    public function faturamento_de_agosto_soma_so_os_dias_de_agosto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create();

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-05', 'revenue' => 1_000.00]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-15', 'revenue' => 2_000.00]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-25', 'revenue' => 3_000.00]);
        // Fora da janela — não pode entrar na soma de agosto.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-07-20', 'revenue' => 9_999.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08');

        $this->assertEqualsWithDelta(6_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
    }

    #[Test]
    public function empresa_com_ml_e_shopee_no_mesmo_mes_soma_os_dois(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create();

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_000.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08');

        $this->assertEqualsWithDelta(10_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertEqualsWithDelta(4_000.00, $resultado[$company->id]['faturamento_shopee'], 0.001);
        $this->assertEqualsWithDelta(14_000.00, $resultado[$company->id]['faturamento_total'], 0.001, 'D-05: ML + Shopee somados numa faixa única.');
    }

    #[Test]
    public function empresa_so_com_shopee_devolve_ml_nulo_e_total_igual_ao_shopee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create();

        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_500.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08');

        $this->assertNull($resultado[$company->id]['faturamento_ml']);
        $this->assertEqualsWithDelta(4_500.00, $resultado[$company->id]['faturamento_shopee'], 0.001);
        $this->assertEqualsWithDelta(4_500.00, $resultado[$company->id]['faturamento_total'], 0.001, 'D-07: Shopee sozinho entra no fechamento.');
    }

    #[Test]
    public function empresa_sem_metrica_no_mes_devolve_faturamento_total_nulo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create();

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08', collect([$company]));

        $this->assertArrayHasKey($company->id, $resultado);
        $this->assertNull($resultado[$company->id]['faturamento_ml']);
        $this->assertNull($resultado[$company->id]['faturamento_shopee']);
        $this->assertNull($resultado[$company->id]['faturamento_total'], 'Ausência de métrica é estado distinto de "faturou zero" — nunca 0.0.');
    }

    #[Test]
    public function metrica_de_shopee_no_ultimo_dia_do_mes_entra_na_janela(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create();

        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-31', 'revenue' => 777.77]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08');

        $this->assertEqualsWithDelta(777.77, $resultado[$company->id]['faturamento_shopee'], 0.001, 'whereDate (não whereBetween) — o último dia do mês precisa entrar na janela.');
    }
}
