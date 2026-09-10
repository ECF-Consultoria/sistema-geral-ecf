<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 05 — Tarefa 3: comando read-only
 * `fechamento:verificar-consolidacao`.
 *
 * O veredito é o EXIT CODE (nunca `expectsOutput`) — disciplina registrada
 * em `.planning/learnings/desempenho-bonificacao.md` §4.
 */
class Phase137VerificarConsolidacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function linhaEmpresaBasica(int $companyId, array $overrides = []): array
    {
        return array_merge([
            'company_id'         => $companyId,
            'company_name'       => 'Empresa '.$companyId,
            'faturamento_total'  => 500_000.00,
            'estado'             => 'ok',
            'origem'             => FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'          => now(),
        ], $overrides);
    }

    private function linhaGrupoBasica(int $groupId, array $overrides = []): array
    {
        return array_merge([
            'company_group_id'  => $groupId,
            'grupo_name'        => 'Grupo '.$groupId,
            'faturamento_total' => 1_000_000.00,
            'estado'            => 'ok',
            'empresas_count'    => 2,
            'origem'            => FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'         => now(),
        ], $overrides);
    }

    private function inserirSnapshotEmpresa(string $mesStr, array $linha): void
    {
        DB::table('fechamento_snapshots')->insert(array_merge($linha, [
            'mes_referencia' => $mesStr,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]));
    }

    private function inserirSnapshotGrupo(string $mesStr, array $linha): void
    {
        DB::table('fechamento_grupo_snapshots')->insert(array_merge($linha, [
            'mes_referencia' => $mesStr,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]));
    }

    #[Test]
    public function competencia_integra_devolve_exit_code_0_e_json_sem_inconsistencias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-1']);
        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($company->id));

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(0);
    }

    #[Test]
    public function empresa_ativa_com_integracao_sem_snapshot_gera_sem_snapshot_exit_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        Company::factory()->create(['adman_account_id' => 'cust-orfa']);
        // Nenhuma linha em fechamento_snapshots para esta empresa.

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(1);
    }

    #[Test]
    public function grupo_com_faturamento_divergente_da_soma_das_empresas_gera_divergencia_soma_exit_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $grupo    = CompanyGroup::create(['name' => 'Grupo Div', 'color' => '#000']);
        $membroA  = Company::factory()->create(['adman_account_id' => 'cust-a', 'company_group_id' => $grupo->id]);
        $membroB  = Company::factory()->create(['adman_account_id' => 'cust-b', 'company_group_id' => $grupo->id]);

        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($membroA->id, ['faturamento_total' => 300_000.00, 'company_group_id' => $grupo->id]));
        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($membroB->id, ['faturamento_total' => 250_000.00, 'company_group_id' => $grupo->id]));

        // Grupo gravado com valor ERRADO — soma real é 550.000.
        $this->inserirSnapshotGrupo('2026-08-01', $this->linhaGrupoBasica($grupo->id, ['faturamento_total' => 999_999.00, 'empresas_count' => 2]));

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(1);
    }

    #[Test]
    public function grupo_sem_nenhuma_empresa_membro_com_snapshot_gera_linhas_orfas_exit_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $grupo = CompanyGroup::create(['name' => 'Grupo Orfao', 'color' => '#000']);

        $this->inserirSnapshotGrupo('2026-08-01', $this->linhaGrupoBasica($grupo->id));
        // Nenhuma linha em fechamento_snapshots com este company_group_id.

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(1);
    }

    #[Test]
    public function empresas_count_divergente_do_numero_real_de_membros_gera_divergencia_contagem_exit_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $grupo   = CompanyGroup::create(['name' => 'Grupo Contagem', 'color' => '#000']);
        $membroA = Company::factory()->create(['adman_account_id' => 'cust-c', 'company_group_id' => $grupo->id]);

        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($membroA->id, ['faturamento_total' => 300_000.00, 'company_group_id' => $grupo->id]));

        // empresas_count diz 2, mas só existe 1 linha de empresa membro.
        $this->inserirSnapshotGrupo('2026-08-01', $this->linhaGrupoBasica($grupo->id, ['faturamento_total' => 300_000.00, 'empresas_count' => 2]));

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(1);
    }

    #[Test]
    public function competencia_fechada_com_origem_diferente_de_consolidar_mes_gera_origem_nao_congelada_exit_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-origem']);
        // Mês fechado (agosto, hoje é setembro) com origem diferente da
        // oficial — simula escrita fora do writer (não deveria acontecer,
        // mas o verificador precisa flagar se acontecer).
        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($company->id, ['origem' => 'warm_cache']));

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(1);
    }

    #[Test]
    public function mes_em_curso_com_origem_diferente_nao_e_flagado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-atual']);
        // Setembro é o mês EM CURSO (hoje = 2026-09-02) — origem diferente
        // de consolidar_mes aqui é esperado, nunca inconsistência.
        $this->inserirSnapshotEmpresa('2026-09-01', $this->linhaEmpresaBasica($company->id, ['origem' => 'warm_cache']));

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    #[Test]
    public function saida_json_e_parseavel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-json']);
        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($company->id));

        $exitCode = \Illuminate\Support\Facades\Artisan::call('fechamento:verificar-consolidacao', [
            '--mes'  => '2026-08',
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $texto        = \Illuminate\Support\Facades\Artisan::output();
        $decodificado = json_decode($texto, true);

        $this->assertNotNull($decodificado, 'json_decode não pode devolver null — a saída --json precisa ser parseável.');
        $this->assertArrayHasKey('inconsistencias', $decodificado);
        $this->assertArrayHasKey('ok', $decodificado);
        $this->assertTrue($decodificado['ok']);
    }

    #[Test]
    public function comando_nao_escreve_em_nenhuma_tabela(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $company = Company::factory()->create(['adman_account_id' => 'cust-readonly']);
        $this->inserirSnapshotEmpresa('2026-08-01', $this->linhaEmpresaBasica($company->id));

        $antesSnapshots = DB::table('fechamento_snapshots')->count();
        $antesGrupos    = DB::table('fechamento_grupo_snapshots')->count();
        $antesReconsol  = DB::table('fechamento_reconsolidacoes')->count();

        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->assertSame($antesSnapshots, DB::table('fechamento_snapshots')->count());
        $this->assertSame($antesGrupos, DB::table('fechamento_grupo_snapshots')->count());
        $this->assertSame($antesReconsol, DB::table('fechamento_reconsolidacoes')->count());
    }
}
