<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoReconsolidacao;
use App\Models\FechamentoSnapshot;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 05 — Tarefas 1 e 2: FechamentoSnapshotWriter e o comando
 * `fechamento:consolidar-mes`.
 *
 * Toda asserção de resultado é por RECONSULTA ao banco (`DB::table`/model
 * fresh query) — nunca por `expectsOutput` (disciplina registrada em
 * `.planning/learnings/desempenho-bonificacao.md` §4).
 */
class Phase137ConsolidarMesTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers de linha (Tarefa 1 — writer puro) ──────────────────────

    private function linhaEmpresa(int $companyId, array $overrides = []): array
    {
        return array_merge([
            'company_id'        => $companyId,
            'company_name'      => 'Empresa '.$companyId,
            'faturamento_total' => 500_000.00,
            'estado'            => FechamentoSnapshot::ESTADO_OK,
        ], $overrides);
    }

    // ─── Tarefa 1 — FechamentoSnapshotWriter ────────────────────────────

    #[Test]
    public function primeira_gravacao_de_competencia_inedita_grava_e_nao_reconsolida(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $resultado = $writer->sync(
            $mes,
            [$this->linhaEmpresa($company->id)],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $this->assertFalse($resultado['reconsolidado']);
        $this->assertSame(1, $resultado['empresas_upserted']);
        $this->assertSame(0, FechamentoReconsolidacao::count());

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravado);
        $this->assertEqualsWithDelta(500_000.00, (float) $gravado->faturamento_total, 0.001);
    }

    #[Test]
    public function segunda_gravacao_sem_motivo_lanca_excecao_e_nao_altera_o_valor_gravado(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [$this->linhaEmpresa($company->id, ['faturamento_total' => 500_000.00])], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->expectException(\RuntimeException::class);

        try {
            $writer->sync($mes, [$this->linhaEmpresa($company->id, ['faturamento_total' => 999_999.00])], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        } finally {
            $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
            $this->assertEqualsWithDelta(500_000.00, (float) $gravado->faturamento_total, 0.001, 'Sem motivo, o valor da primeira rodada tem que permanecer intocado.');
        }
    }

    #[Test]
    public function segunda_gravacao_com_motivo_preserva_o_valor_anterior_na_auditoria(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [$this->linhaEmpresa($company->id, ['faturamento_total' => 500_000.00])], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $resultado = $writer->sync(
            $mes,
            [$this->linhaEmpresa($company->id, ['faturamento_total' => 999_999.00])],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            reconsolidadoPor: null,
            motivo: 'Adman corrigiu o faturamento depois do fechamento.',
        );

        $this->assertTrue($resultado['reconsolidado']);
        $this->assertSame(1, FechamentoReconsolidacao::count());

        $auditoria = FechamentoReconsolidacao::first();
        $this->assertSame('Adman corrigiu o faturamento depois do fechamento.', $auditoria->motivo);
        $this->assertEqualsWithDelta(
            500_000.00,
            (float) $auditoria->snapshot_anterior['empresas'][0]['faturamento_total'],
            0.001,
            'snapshot_anterior precisa preservar o valor ANTES da sobrescrita.'
        );

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertEqualsWithDelta(999_999.00, (float) $gravado->faturamento_total, 0.001, 'Com motivo, a sobrescrita acontece.');
    }

    #[Test]
    public function empresa_removida_do_conjunto_e_podada_na_rodada_seguinte(): void
    {
        $empresaFica = Company::factory()->create();
        $empresaSai  = Company::factory()->create();
        $mes         = Carbon::parse('2026-08-01');
        $writer      = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [
            $this->linhaEmpresa($empresaFica->id),
            $this->linhaEmpresa($empresaSai->id),
        ], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->assertSame(2, DB::table('fechamento_snapshots')->count());

        $resultado = $writer->sync(
            $mes,
            [$this->linhaEmpresa($empresaFica->id)],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            motivo: 'Empresa saiu da carteira.',
        );

        $this->assertSame(1, $resultado['empresas_pruned']);
        $this->assertSame(1, DB::table('fechamento_snapshots')->count());
        $this->assertNull(DB::table('fechamento_snapshots')->where('company_id', $empresaSai->id)->first());
        $this->assertNotNull(DB::table('fechamento_snapshots')->where('company_id', $empresaFica->id)->first());
    }

    #[Test]
    public function rerun_com_mesmo_conjunto_nao_duplica_linha(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [$this->linhaEmpresa($company->id)], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        $writer->sync($mes, [$this->linhaEmpresa($company->id)], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES, motivo: 'rerun idêntico');

        $this->assertSame(1, DB::table('fechamento_snapshots')->where('company_id', $company->id)->count());
    }

    #[Test]
    public function writer_grava_e_poda_linhas_de_grupo(): void
    {
        $grupo   = CompanyGroup::create(['name' => 'Grupo Teste', 'color' => '#000']);
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $linhaGrupo = [
            'company_group_id'  => $grupo->id,
            'grupo_name'        => $grupo->name,
            'faturamento_total' => 800_000.00,
            'estado'            => 'ok',
            'empresas_count'    => 2,
        ];

        $resultado = $writer->sync($mes, [], [$linhaGrupo], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->assertSame(1, $resultado['grupos_upserted']);
        $gravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();
        $this->assertNotNull($gravado);
        $this->assertEqualsWithDelta(800_000.00, (float) $gravado->faturamento_total, 0.001);

        // Segunda rodada com motivo e SEM o grupo — precisa podar.
        $resultado2 = $writer->sync($mes, [], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES, motivo: 'grupo desfeito');
        $this->assertSame(1, $resultado2['grupos_pruned']);
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count());
    }
}
