<?php

// Phase 40 Plan 40-03 — Suite RED → GREEN do ProviderComparisonService.
//
// O ProviderComparisonService compara duas execuções shadow (uma Adman e uma ML)
// e classifica as divergências em 6 buckets:
//   - matched          → mesma chave + métricas dentro da tolerância + motivos iguais
//   - metrics_diff     → mesma chave mas métricas divergem
//   - motivo_diff      → mesma chave + métricas dentro da tolerância mas motivos diferentes
//   - apenas_adman     → chave só aparece em items Adman
//   - apenas_ml        → chave só aparece em items ML
//   - quarentena_diff  → um marca 'quarentena' no motivo e o outro não
//
// Tolerâncias §7 do plano-migracao-sugadores-ml-direto.md:
//   - Dinheiro (investment, revenue, organic_amount, cpc, roas):
//       |a-b| <= R$0,10  OU  |a-b|/max(|a|,|b|,1) <= 1%
//   - Percentual (acos, ctr): |a-b| <= 0.5 (ponto percentual, escala 0-100)
//   - Inteiro (clicks, impressions, sold_quantity, organic_units): igualdade estrita
//   - null/null → match; null/número → divergência
//
// Esta suite cobre 17 cenários (1 por bucket + 7 testes de tolerância + 3 edge cases
// + 1 fallback chave + 1 paridade composta + 1 compareWindow + 1 quarentena_diff)
// e está em `Feature/Phase40/` (em vez de `Unit/Phase40/`) porque depende de DB:
// os runs/items são persistidos via Eloquent (`RefreshDatabase`).

namespace Tests\Feature\Phase40;

use App\Models\Company;
use App\Models\SugadorProviderItem;
use App\Models\SugadorProviderRun;
use App\Services\Sugadores\ProviderComparisonService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::create(2026, 6, 25)->startOfDay();
        $this->company = Company::create([
            'name'             => 'Empresa Comparison 40-03',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-CMP-' . random_int(1000, 9999),
            'active'           => true,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeRun(string $provider, ?Carbon $referenceDate = null): SugadorProviderRun
    {
        $ref = $referenceDate ?? $this->hoje;
        return SugadorProviderRun::create([
            'company_id'     => $this->company->id,
            'provider'       => $provider,
            'reference_date' => $ref->toDateString(),
            'periodo_inicio' => $ref->copy()->subDays(7)->toDateString(),
            'periodo_fim'    => $ref->copy()->subDay()->toDateString(),
            'status'         => 'completed',
            'started_at'     => now(),
            'finished_at'    => now(),
            'error'          => null,
            'summary'        => null,
        ]);
    }

    /**
     * Cria SugadorProviderItem com defaults e overrides.
     * Defaults representam um adgroup detectado com cpc_alto.
     */
    private function makeItem(int $runId, array $overrides = []): SugadorProviderItem
    {
        $defaults = [
            'run_id'       => $runId,
            'tipo'         => 'adgroup',
            'campaign_id'  => 'CAMP1',
            'adgroup_id'   => 'AG1',
            'mlb_id'       => null,
            'motivos'      => ['cpc_alto'],
            'metrics_json' => [
                'investment' => 100.0,
                'revenue'    => 50.0,
                'clicks'     => 10,
                'cpc'        => 10.0,
                'acos'       => 200.0,
            ],
            'raw_hash'     => hash('sha256', uniqid('', true)),
        ];
        return SugadorProviderItem::create(array_merge($defaults, $overrides));
    }

    private function service(): ProviderComparisonService
    {
        return app(ProviderComparisonService::class);
    }

    // ─── Testes ─────────────────────────────────────────────────────────────

    #[Test]
    public function dois_runs_vazios_paridade_100(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(0, $report['metrics_diff']);
        $this->assertSame(0, $report['motivo_diff']);
        $this->assertSame(0, $report['apenas_adman']);
        $this->assertSame(0, $report['apenas_ml']);
        $this->assertSame(0, $report['quarentena_diff']);
        $this->assertSame(0, $report['total_items']);
        $this->assertEqualsWithDelta(100.0, $report['paridade_motivos_pct'], 0.001);
    }

    #[Test]
    public function cinco_items_iguais_em_ambos_geram_5_matched(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        for ($i = 1; $i <= 5; $i++) {
            $payload = [
                'tipo'        => 'adgroup',
                'campaign_id' => "C{$i}",
                'adgroup_id'  => "A{$i}",
                'motivos'     => ['cpc_alto'],
                'metrics_json' => [
                    'investment' => 100.0,
                    'revenue'    => 50.0,
                    'clicks'     => 10,
                    'acos'       => 200.0,
                ],
            ];
            $this->makeItem($adman->id, $payload);
            $this->makeItem($ml->id, $payload);
        }

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(5, $report['matched']);
        $this->assertSame(0, $report['metrics_diff']);
        $this->assertSame(0, $report['motivo_diff']);
        $this->assertSame(0, $report['apenas_adman']);
        $this->assertSame(0, $report['apenas_ml']);
        $this->assertSame(0, $report['quarentena_diff']);
        $this->assertSame(5, $report['total_items']);
        $this->assertEqualsWithDelta(100.0, $report['paridade_motivos_pct'], 0.001);
    }

    #[Test]
    public function tres_items_so_em_adman_geram_apenas_adman_3(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        for ($i = 1; $i <= 3; $i++) {
            $this->makeItem($adman->id, [
                'campaign_id' => "CAMP-A{$i}",
                'adgroup_id'  => "AG-A{$i}",
            ]);
        }

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(3, $report['apenas_adman']);
        $this->assertSame(0, $report['apenas_ml']);
        $this->assertSame(3, $report['total_items']);
        $this->assertEqualsWithDelta(0.0, $report['paridade_motivos_pct'], 0.001);
    }

    #[Test]
    public function tres_items_so_em_ml_geram_apenas_ml_3(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        for ($i = 1; $i <= 3; $i++) {
            $this->makeItem($ml->id, [
                'campaign_id' => "CAMP-M{$i}",
                'adgroup_id'  => "AG-M{$i}",
            ]);
        }

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(0, $report['apenas_adman']);
        $this->assertSame(3, $report['apenas_ml']);
        $this->assertSame(3, $report['total_items']);
        $this->assertEqualsWithDelta(0.0, $report['paridade_motivos_pct'], 0.001);
    }

    #[Test]
    public function dinheiro_dentro_da_tolerancia_1pct_eh_match(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // investment 100.00 vs 100.99 → diff 0.99, 0.99% ≤ 1% → match.
        $this->makeItem($adman->id, ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0]]);
        $this->makeItem($ml->id,    ['metrics_json' => ['investment' => 100.99, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['metrics_diff']);
    }

    #[Test]
    public function dinheiro_dentro_da_tolerancia_R0_10_em_valor_pequeno_eh_match(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // investment 0.05 vs 0.10 → diff 0.05, |a-b| ≤ R$0,10 → match (mesmo que 100% relativo
        // em base muito pequena, o critério absoluto resgata).
        $this->makeItem($adman->id, ['metrics_json' => ['investment' => 0.05, 'revenue' => 0.0, 'clicks' => 1, 'acos' => 0.0]]);
        $this->makeItem($ml->id,    ['metrics_json' => ['investment' => 0.10, 'revenue' => 0.0, 'clicks' => 1, 'acos' => 0.0]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['metrics_diff']);
    }

    #[Test]
    public function dinheiro_fora_da_tolerancia_vira_metrics_diff(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // investment 100.0 vs 110.0 → diff 10.0, 10% (>1%) E R$10 (>R$0,10) → metrics_diff
        $this->makeItem($adman->id, ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0]]);
        $this->makeItem($ml->id,    ['metrics_json' => ['investment' => 110.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(1, $report['metrics_diff']);
        $this->assertSame(1, $report['total_items']);
    }

    #[Test]
    public function percentual_dentro_da_tolerancia_0_5pp_eh_match(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // acos 50.0 vs 50.3 → diff 0.3pp ≤ 0.5pp → match
        $this->makeItem($adman->id, ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 50.0]]);
        $this->makeItem($ml->id,    ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 50.3]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['metrics_diff']);
    }

    #[Test]
    public function percentual_fora_da_tolerancia_vira_metrics_diff(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // acos 50.0 vs 51.0 → diff 1.0pp > 0.5pp → metrics_diff
        $this->makeItem($adman->id, ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 50.0]]);
        $this->makeItem($ml->id,    ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 51.0]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(1, $report['metrics_diff']);
    }

    #[Test]
    public function inteiro_diverge_estrito_vira_metrics_diff(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // clicks 10 vs 11 → diff 1 → metrics_diff (inteiro = estrito)
        $this->makeItem($adman->id, ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0]]);
        $this->makeItem($ml->id,    ['metrics_json' => ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 11, 'acos' => 200.0]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(1, $report['metrics_diff']);
    }

    #[Test]
    public function motivos_diferentes_com_metricas_iguais_vira_motivo_diff(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        $metricas = ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0];

        $this->makeItem($adman->id, ['motivos' => ['cpc_alto'],                'metrics_json' => $metricas]);
        $this->makeItem($ml->id,    ['motivos' => ['cpc_alto', 'sem_conversao'], 'metrics_json' => $metricas]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(0, $report['metrics_diff']);
        $this->assertSame(1, $report['motivo_diff']);
    }

    #[Test]
    public function motivos_iguais_ordem_diferente_continua_matched(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        $metricas = ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0];

        $this->makeItem($adman->id, ['motivos' => ['a', 'b'], 'metrics_json' => $metricas]);
        $this->makeItem($ml->id,    ['motivos' => ['b', 'a'], 'metrics_json' => $metricas]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['motivo_diff']);
    }

    #[Test]
    public function fallback_chave_via_mlb_id_quando_adgroup_id_null(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        $metricas = ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0];

        // Adman: adgroup_id null, mlb_id presente → chave por mlb
        // ML: mesma chave (adgroup_id null, mlb_id igual) → matched
        $this->makeItem($adman->id, [
            'adgroup_id'  => null,
            'mlb_id'      => 'MLB123',
            'metrics_json' => $metricas,
        ]);
        $this->makeItem($ml->id, [
            'adgroup_id'  => null,
            'mlb_id'      => 'MLB123',
            'metrics_json' => $metricas,
        ]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['apenas_adman']);
        $this->assertSame(0, $report['apenas_ml']);
    }

    #[Test]
    public function null_versus_numero_em_metrica_eh_divergencia(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // organic_amount null em Adman vs 100.0 em ML → metrics_diff
        $this->makeItem($adman->id, ['metrics_json' => [
            'investment'     => 100.0,
            'revenue'        => 50.0,
            'clicks'         => 10,
            'acos'           => 200.0,
            'organic_amount' => null,
        ]]);
        $this->makeItem($ml->id, ['metrics_json' => [
            'investment'     => 100.0,
            'revenue'        => 50.0,
            'clicks'         => 10,
            'acos'           => 200.0,
            'organic_amount' => 100.0,
        ]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(1, $report['metrics_diff']);
    }

    #[Test]
    public function null_versus_null_em_metrica_eh_match(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        $this->makeItem($adman->id, ['metrics_json' => [
            'investment'     => 100.0,
            'revenue'        => 50.0,
            'clicks'         => 10,
            'acos'           => 200.0,
            'organic_amount' => null,
        ]]);
        $this->makeItem($ml->id, ['metrics_json' => [
            'investment'     => 100.0,
            'revenue'        => 50.0,
            'clicks'         => 10,
            'acos'           => 200.0,
            'organic_amount' => null,
        ]]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(1, $report['matched']);
    }

    #[Test]
    public function quarentena_em_um_lado_e_nao_no_outro_vira_quarentena_diff(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        // Adman trata campanha como quarentena (motivo contém 'quarentena');
        // ML não → bucket quarentena_diff (sobrescreve motivo_diff/metrics_diff)
        $this->makeItem($adman->id, [
            'tipo'         => 'campanha',
            'campaign_id'  => 'CAMP-Q1',
            'adgroup_id'   => null,
            'motivos'      => ['quarentena'],
            'metrics_json' => ['investment' => 0.0, 'revenue' => 0.0, 'clicks' => 0, 'acos' => 0.0],
        ]);
        $this->makeItem($ml->id, [
            'tipo'         => 'campanha',
            'campaign_id'  => 'CAMP-Q1',
            'adgroup_id'   => null,
            'motivos'      => ['cpc_alto'],
            'metrics_json' => ['investment' => 50.0, 'revenue' => 0.0, 'clicks' => 5, 'acos' => 0.0],
        ]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        $this->assertSame(0, $report['matched']);
        $this->assertSame(0, $report['motivo_diff']);
        $this->assertSame(0, $report['metrics_diff']);
        $this->assertSame(1, $report['quarentena_diff']);
        $this->assertSame(1, $report['total_items']);
    }

    #[Test]
    public function paridade_motivos_pct_calculo_correto_70(): void
    {
        $adman = $this->makeRun('adman');
        $ml    = $this->makeRun('ml');

        $metricas = ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0];

        // 7 matched
        for ($i = 1; $i <= 7; $i++) {
            $payload = [
                'campaign_id'  => "C-M{$i}",
                'adgroup_id'   => "AG-M{$i}",
                'motivos'      => ['cpc_alto'],
                'metrics_json' => $metricas,
            ];
            $this->makeItem($adman->id, $payload);
            $this->makeItem($ml->id, $payload);
        }

        // 1 apenas_adman
        $this->makeItem($adman->id, ['campaign_id' => 'C-A1', 'adgroup_id' => 'AG-A1', 'metrics_json' => $metricas]);

        // 1 apenas_ml
        $this->makeItem($ml->id, ['campaign_id' => 'C-X1', 'adgroup_id' => 'AG-X1', 'metrics_json' => $metricas]);

        // 1 metrics_diff (clicks 10 vs 99)
        $this->makeItem($adman->id, ['campaign_id' => 'C-D1', 'adgroup_id' => 'AG-D1', 'metrics_json' => array_merge($metricas, ['clicks' => 10])]);
        $this->makeItem($ml->id,    ['campaign_id' => 'C-D1', 'adgroup_id' => 'AG-D1', 'metrics_json' => array_merge($metricas, ['clicks' => 99])]);

        $report = $this->service()->compareRuns($adman->id, $ml->id);

        // 7 matched + 1 apenas_adman + 1 apenas_ml + 1 metrics_diff = 10 total
        $this->assertSame(7, $report['matched']);
        $this->assertSame(1, $report['apenas_adman']);
        $this->assertSame(1, $report['apenas_ml']);
        $this->assertSame(1, $report['metrics_diff']);
        $this->assertSame(10, $report['total_items']);
        $this->assertEqualsWithDelta(70.0, $report['paridade_motivos_pct'], 0.001);
    }

    #[Test]
    public function compare_window_agrega_runs_no_intervalo(): void
    {
        $d1 = $this->hoje->copy()->subDays(2); // 2026-06-23
        $d2 = $this->hoje->copy()->subDay();   // 2026-06-24

        $admanD1 = $this->makeRun('adman', $d1);
        $admanD2 = $this->makeRun('adman', $d2);
        $mlD1    = $this->makeRun('ml', $d1);
        $mlD2    = $this->makeRun('ml', $d2);

        $metricas = ['investment' => 100.0, 'revenue' => 50.0, 'clicks' => 10, 'acos' => 200.0];

        // D1: 2 matched
        for ($i = 1; $i <= 2; $i++) {
            $payload = ['campaign_id' => "D1-C{$i}", 'adgroup_id' => "D1-AG{$i}", 'metrics_json' => $metricas];
            $this->makeItem($admanD1->id, $payload);
            $this->makeItem($mlD1->id, $payload);
        }

        // D2: 1 matched + 1 apenas_adman
        $payload = ['campaign_id' => "D2-C1", 'adgroup_id' => "D2-AG1", 'metrics_json' => $metricas];
        $this->makeItem($admanD2->id, $payload);
        $this->makeItem($mlD2->id, $payload);
        $this->makeItem($admanD2->id, ['campaign_id' => 'D2-CSOLO', 'adgroup_id' => 'D2-AGSOLO', 'metrics_json' => $metricas]);

        $report = $this->service()->compareWindow(
            $this->company->id,
            $d1->copy()->startOfDay(),
            $d2->copy()->endOfDay(),
        );

        $this->assertSame(3, $report['matched']);     // 2 D1 + 1 D2
        $this->assertSame(1, $report['apenas_adman']); // D2-CSOLO
        $this->assertSame(0, $report['apenas_ml']);
        $this->assertSame(4, $report['total_items']);
        $this->assertEqualsWithDelta(75.0, $report['paridade_motivos_pct'], 0.001);
    }
}
