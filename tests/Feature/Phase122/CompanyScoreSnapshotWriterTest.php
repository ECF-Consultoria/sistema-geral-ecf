<?php

namespace Tests\Feature\Phase122;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de comportamento do `CompanyScoreSnapshotWriter::sync()` — um teste
 * por item do `<behavior>` da Task 2 do 122-01-PLAN.md, mais os dois
 * cenários extras da Task 3 (isolamento e idempotência forte).
 *
 * As linhas de `empresasScore` são montadas como `(object)` literais no
 * próprio teste — o writer é puro em relação ao cálculo (não monta fixture
 * financeira real, não chama Adman); a lógica por empresa já foi provada
 * pela Fase 119.
 */
class CompanyScoreSnapshotWriterTest extends TestCase
{
    use RefreshDatabase;

    private CompanyScoreSnapshotWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(CompanyScoreSnapshotWriter::class);
    }

    /**
     * Monta uma linha no shape de
     * `CompanyScoreService::computeEmpresasScore()`.
     */
    private function linha(int $companyId, array $overrides = []): object
    {
        return (object) array_merge([
            'company_id'            => $companyId,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => 'adman',
            'nps_pontos'            => 4.0,
            'faturamento_atual'     => 10000.0,
            'faturamento_anterior'  => 9000.0,
            'faturamento_var_pct'   => 11.11,
            'faturamento_pontos'    => 5.0,
            'margem_pct_atual'      => 20.5,
            'margem_pct_anterior'   => 18.0,
            'margem_var_pp'         => 2.5,
            'margem_pontos'         => 4.0,
            'componentes_presentes' => 3,
            'nota_empresa'          => 4.33,
            'nota_empresa_parcial'  => 4.33,
            'status'                => 'complete',
            'quality'               => [
                'revenue_diff_source' => 'native',
                'margin_diff_source'  => 'native',
                'margin_source'       => null,
                'motivos'             => [],
            ],
        ], $overrides);
    }

    #[Test]
    public function sync_com_tres_linhas_grava_tres_rows_com_origem_e_gerado_em(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $c3 = Company::factory()->create();

        $resultado = $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c1->id), $this->linha($c2->id), $this->linha($c3->id)]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $this->assertSame(['upserted' => 3, 'pruned' => 0, 'congelado' => false], $resultado);

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->get();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('consolidar_mes', $row->origem);
            $this->assertNotNull($row->gerado_em);
        }
    }

    #[Test]
    public function sync_chamado_duas_vezes_com_mesma_entrada_nao_duplica(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $c3 = Company::factory()->create();

        $linhas = collect([$this->linha($c1->id), $this->linha($c2->id), $this->linha($c3->id)]);

        $this->writer->sync($user, $mes, $linhas, CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        $this->writer->sync($user, $mes, $linhas, CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->get();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(4.33, $row->nota_empresa);
        }
    }

    #[Test]
    public function sync_com_duas_das_tres_empresas_apaga_a_terceira(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $c3 = Company::factory()->create();

        $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c1->id), $this->linha($c2->id), $this->linha($c3->id)]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $resultado = $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c1->id), $this->linha($c2->id)]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $this->assertSame(['upserted' => 2, 'pruned' => 1, 'congelado' => false], $resultado);

        $idsRestantes = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->pluck('company_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$c1->id, $c2->id], $idsRestantes);
    }

    #[Test]
    public function sync_com_colecao_vazia_apaga_todas_as_rows_do_par(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();

        $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c1->id), $this->linha($c2->id)]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $resultado = $this->writer->sync($user, $mes, collect(), CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->assertSame(['upserted' => 0, 'pruned' => 2, 'congelado' => false], $resultado);

        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->count());
    }

    #[Test]
    public function sync_com_origem_nao_oficial_numa_competencia_congelada_nao_altera_nada(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');
        $c1   = Company::factory()->create();
        $c2   = Company::factory()->create();

        // Congela via consolidar_mes.
        $this->writer->sync($user, $mes, collect([$this->linha($c1->id)]), CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        // Tenta sobrescrever via warm_cache com uma empresa DIFERENTE.
        $resultado = $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c2->id)]),
            CompanyScoreSnapshotWriter::ORIGEM_WARM_CACHE,
        );

        $this->assertSame(['upserted' => 0, 'pruned' => 0, 'congelado' => true], $resultado);

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($c1->id, $rows->first()->company_id);
        $this->assertSame('consolidar_mes', $rows->first()->origem);
    }

    #[Test]
    public function sync_com_origem_snapshot_diario_numa_competencia_congelada_tambem_nao_altera_nada(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');
        $c1   = Company::factory()->create();

        $this->writer->sync($user, $mes, collect([$this->linha($c1->id)]), CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $resultado = $this->writer->sync(
            $user,
            $mes,
            collect(),
            CompanyScoreSnapshotWriter::ORIGEM_SNAPSHOT_DIARIO,
        );

        $this->assertTrue($resultado['congelado']);
        $this->assertSame(1, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->count());
    }

    #[Test]
    public function sync_com_origem_consolidar_mes_sobrescreve_mesmo_ja_havendo_row_de_consolidar_mes(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');
        $c1   = Company::factory()->create();

        $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c1->id, ['nota_empresa' => 3.0])]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $resultado = $this->writer->sync(
            $user,
            $mes,
            collect([$this->linha($c1->id, ['nota_empresa' => 4.5])]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $this->assertSame(['upserted' => 1, 'pruned' => 0, 'congelado' => false], $resultado);

        $row = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->where('company_id', $c1->id)
            ->first();

        $this->assertSame(4.5, $row->nota_empresa);
    }

    #[Test]
    public function sync_de_um_usuario_nao_toca_rows_de_outro_usuario_nem_de_outra_competencia(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $mes   = Carbon::parse('2026-06-01');
        $mesAnterior = Carbon::parse('2026-05-01');

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();

        // userB tem linha na MESMA competência.
        $this->writer->sync($userB, $mes, collect([$this->linha($c1->id)]), CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        // userA tem linha numa competência ANTERIOR.
        $this->writer->sync($userA, $mesAnterior, collect([$this->linha($c2->id)]), CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        // userA sincroniza a competência corrente com um conjunto vazio
        // (pruning total do par user/competência).
        $this->writer->sync($userA, $mes, collect(), CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        // Linha do userB na mesma competência sobrevive intocada.
        $this->assertSame(1, DesempenhoCompanyScoreSnapshot::where('user_id', $userB->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->count());

        // Linha do userA na competência anterior sobrevive intocada.
        $this->assertSame(1, DesempenhoCompanyScoreSnapshot::where('user_id', $userA->id)
            ->whereDate('mes_referencia', '2026-05-01')
            ->count());
    }

    #[Test]
    public function sync_duas_vezes_identico_mantem_o_mesmo_conjunto_de_valores_por_empresa(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();

        $linhas = collect([
            $this->linha($c1->id, ['nota_empresa' => 3.67, 'status' => 'partial', 'margem_var_pp' => -1.2]),
            $this->linha($c2->id, ['nota_empresa' => 4.33, 'status' => 'complete', 'margem_var_pp' => 3.1]),
        ]);

        $this->writer->sync($user, $mes, $linhas, CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $primeiraExecucao = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->orderBy('company_id')
            ->get(['company_id', 'nota_empresa', 'status', 'margem_var_pp'])
            ->map(fn ($r) => [$r->company_id, $r->nota_empresa, $r->status, $r->margem_var_pp])
            ->all();

        $this->writer->sync($user, $mes, $linhas, CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $segundaExecucao = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->orderBy('company_id')
            ->get(['company_id', 'nota_empresa', 'status', 'margem_var_pp'])
            ->map(fn ($r) => [$r->company_id, $r->nota_empresa, $r->status, $r->margem_var_pp])
            ->all();

        $this->assertSame($primeiraExecucao, $segundaExecucao);
    }
}
