<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\Metrics\AdmanMetricDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 101 Plan 02 — ADM-04 REFRAMADO (v18.0).
 *
 * O texto original do REQ pedia "backfill de raw_data antigo para preencher
 * os novos campos de diff". O research provou que `raw_data.*.diff` é sempre
 * DIÁRIO (resultado de fetchPerformance com dateFrom=dateTo, dia único) —
 * NUNCA diff de período. Não há backfill de coluna (Fase 101 é live-read,
 * sem colunas novas — decisão travada).
 *
 * Este teste prova o helper `lerDiffDiarioRawData()`: leitura AUXILIAR de
 * metadados diários do raw_data histórico, com scope='daily' explícito e
 * guard anti-confusão (nunca contém diff_source/adman_diff — não pode ser
 * reaproveitado como diff de período pela Fase 102).
 *
 * @see .planning/phases/101-admanmetricdiffservice-v18-0/101-02-PLAN.md
 * @see .planning/phases/101-admanmetricdiffservice-v18-0/101-RESEARCH.md
 */
class AdmanMetricDiffBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fixture real (research): raw_data resultado de fetchPerformance com
     * dateFrom=dateTo='2026-07-18' (dia único). O .diff aqui é
     * 2026-07-18 vs 2026-07-17 — DIÁRIO, não de período.
     */
    private function rawDataDiarioReal(): array
    {
        return [
            'summarizedData' => [
                'grossBilling' => ['value' => 41250.10, 'diff' => 5.45, 'prev' => 39119.06],
                'profitMargin' => ['value' => 10870.33, 'diff' => 3.10, 'prev' => 10543.20],
            ],
        ];
    }

    /**
     * Com o fixture real: grossBilling_diff=5.45, profitMargin_diff=3.10,
     * percentageMargin_diff=null (nunca esteve em raw_data), scope='daily'.
     */
    public function test_le_diff_diario_do_raw_data_com_fixture_real(): void
    {
        $company = Company::factory()->create();
        $metric  = AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-18',
            'raw_data'       => $this->rawDataDiarioReal(),
        ]);

        $resultado = app(AdmanMetricDiffService::class)->lerDiffDiarioRawData($metric);

        $this->assertSame(5.45, $resultado['grossBilling_diff']);
        $this->assertSame(3.10, $resultado['profitMargin_diff']);
        $this->assertNull($resultado['percentageMargin_diff']);
        $this->assertSame('daily', $resultado['scope']);
    }

    /**
     * raw_data ausente/malformado ⇒ todos os diffs null, scope='daily'
     * (fail-open, ?? em todo acesso — nunca lança).
     */
    public function test_raw_data_ausente_retorna_diffs_null_fail_open(): void
    {
        $company = Company::factory()->create();
        $metric  = AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-18',
            'raw_data'       => null,
        ]);

        $resultado = app(AdmanMetricDiffService::class)->lerDiffDiarioRawData($metric);

        $this->assertNull($resultado['grossBilling_diff']);
        $this->assertNull($resultado['profitMargin_diff']);
        $this->assertNull($resultado['percentageMargin_diff']);
        $this->assertSame('daily', $resultado['scope']);
    }

    /**
     * raw_data malformado (sem chave summarizedData) ⇒ fail-open, nunca lança.
     */
    public function test_raw_data_malformado_nao_lanca_excecao(): void
    {
        $company = Company::factory()->create();
        $metric  = AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-18',
            'raw_data'       => ['algo_inesperado' => true],
        ]);

        $resultado = app(AdmanMetricDiffService::class)->lerDiffDiarioRawData($metric);

        $this->assertNull($resultado['grossBilling_diff']);
        $this->assertNull($resultado['profitMargin_diff']);
        $this->assertNull($resultado['percentageMargin_diff']);
        $this->assertSame('daily', $resultado['scope']);
    }

    /**
     * INVARIANTE (guard contra Pitfall 1): a estrutura de retorno NUNCA
     * contém a chave `diff_source` nem o valor `'adman_diff'` — é dado
     * diário, não de período. Ninguém deve confundir este helper com o
     * diff de período de compute().
     */
    public function test_invariante_retorno_nunca_contem_diff_source_ou_adman_diff(): void
    {
        $company = Company::factory()->create();
        $metric  = AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-18',
            'raw_data'       => $this->rawDataDiarioReal(),
        ]);

        $resultado = app(AdmanMetricDiffService::class)->lerDiffDiarioRawData($metric);

        $this->assertArrayNotHasKey('diff_source', $resultado);
        $this->assertNotContains('adman_diff', $resultado, true);
    }

    /**
     * percentageMargin_diff é SEMPRE null — nunca esteve em raw_data
     * (só existe no endpoint /accounts/{custId}/metrics).
     */
    public function test_percentage_margin_diff_sempre_null(): void
    {
        $company = Company::factory()->create();
        $metric  = AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-18',
            'raw_data'       => $this->rawDataDiarioReal(),
        ]);

        $resultado = app(AdmanMetricDiffService::class)->lerDiffDiarioRawData($metric);

        $this->assertArrayHasKey('percentageMargin_diff', $resultado);
        $this->assertNull($resultado['percentageMargin_diff']);
    }
}
