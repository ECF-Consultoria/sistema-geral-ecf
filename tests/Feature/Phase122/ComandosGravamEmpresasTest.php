<?php

namespace Tests\Feature\Phase122;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 122 Plano 03 (SNAP-01/SNAP-03) — os três comandos do fechamento
 * (`desempenho:consolidar-mes`, `desempenho:snapshot-scores`,
 * `desempenho:warm-cache`) gravam as linhas por empresa via
 * `CompanyScoreSnapshotWriter::sync()`.
 *
 * `CompanyScoreService::computeEmpresasScore()` é mockado (Mockery partial
 * via `$this->mock()`, padrão do `Phase120/AgregacaoProfissionalTest`) para
 * controlar precisamente o shape de entrada sem depender de HTTP real — a
 * lógica de cálculo por empresa já está provada pela Fase 119, e o
 * `CompanyScoreSnapshotWriter::sync()` já está provado pela
 * `CompanyScoreSnapshotWriterTest` (122-01). Esta suíte testa só o FIO que
 * liga os três comandos ao writer.
 *
 * Todos os testes asseram por RECONSULTA ao banco, nunca por stdout do
 * comando (122-CONTEXT.md item 5).
 */
class ComandosGravamEmpresasTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-122-cmd',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function criarUserComCargo(string $nome): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /**
     * Vínculo MÍNIMO — 1 empresa/contrato/pivot — o suficiente pra
     * `sem_carteira=false` (DESEMP-10 exige ZERO vínculos de qualquer setor
     * pra disparar `sem_carteira=true`). O conteúdo financeiro é irrelevante
     * porque `computeEmpresasScore()` é mockado — as linhas que o writer
     * recebe são as devolvidas pelo mock, não as desta empresa.
     *
     * Setor SHOPEE de propósito (não performance): uma empresa performance
     * SEM `AdmanMetric` real conta como elegível-mas-degradada pro gate
     * FIXMARG-03 (cobertura=0, mesmo padrão do
     * `Phase110/ConsolidarMesMargemResilienteTest::criarEmpresaSemMargem`),
     * o que faria `desempenho:consolidar-mes` RECUSAR o congelamento antes
     * de sequer chegar no writer — sem relação nenhuma com o que esta suíte
     * testa. Shopee entra com cobertura=1.0/n_elegivel=0 (mesmo padrão do
     * `test_user_so_shopee_nao_e_tratado_como_degradado`), nunca aciona o
     * gate.
     */
    private function criarVinculoMinimo(User $user): void
    {
        $servico = $this->criarServico(Servico::SETOR_SHOPEE);
        $company = Company::factory()->create();
        $this->criarContrato($company->id, $servico, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servico);
    }

    /** Linha no shape de `CompanyScoreService::computeEmpresasScore()`. */
    private function linha(int $companyId, array $overrides = []): object
    {
        return (object) array_merge([
            'company_id'            => $companyId,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'nota_empresa'          => 4.0,
            'nota_empresa_parcial'  => 4.0,
            'margem_var_pp'         => 2.0,
            'componentes_presentes' => 3,
        ], $overrides);
    }

    /**
     * Workaround SÓ DE TESTE para uma armadilha de ambiente PRÉ-EXISTENTE e
     * FORA DO ESCOPO deste plano (122-01-SUMMARY.md, "Nota para a Wave 2";
     * confirmada pelo orquestrador nas 2 falhas conhecidas de
     * `Phase110/ConsolidarMesMargemResilienteTest`): o
     * `DesempenhoScoreSnapshot::updateOrCreate(['user_id','mes_referencia'
     * => $mesStr])` dentro de `ConsolidarMesDesempenho` busca com
     * `mes_referencia` CRU — sob SQLite (só testes) o cast `date` do model
     * grava a coluna como datetime completo, e a busca da 2ª chamada nunca
     * casa, tentando um INSERT que colide com a UNIQUE(user_id, ref_date,
     * mes_referencia) da row mensal já gravada pela 1ª chamada. Em MariaDB
     * de produção a coluna DATE trunca a hora e o comando funciona
     * normalmente — não é uma regressão desta task, e mexer nesse
     * `updateOrCreate` é decisão própria (fora deste plano). Apaga só a row
     * AGREGADA (não a por-empresa, que é o que este teste mede) entre as
     * duas execuções, para o comando conseguir rodar uma 2ª vez sob SQLite
     * sem esbarrar nessa armadilha de ambiente.
     */
    private function contornarBugSqliteUpdateOrCreateMensal(User $user, string $mesStr): void
    {
        DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesStr)
            ->delete();
    }

    /**
     * Mocka `CompanyScoreService::computeEmpresasScore()` com uma sequência
     * de retornos — 1 elemento por CHAMADA esperada, na ordem em que os
     * comandos rodam neste teste. Mockery repete o último valor da
     * sequência se houver mais chamadas do que elementos.
     */
    private function mockSequenciaDeLinhas(array $sequencia): void
    {
        $this->mock(CompanyScoreService::class, function ($mock) use ($sequencia) {
            $mock->shouldReceive('computeEmpresasScore')->andReturn(...$sequencia);
        });
    }

    // ═══ Teste 1 — consolidar-mes grava N linhas ════════════════════════════

    #[Test]
    public function consolidar_mes_grava_linhas_com_origem_consolidar_mes_e_mes_referencia_correto(): void
    {
        $user = $this->criarUserComCargo('Consolidar Grava');
        $this->criarVinculoMinimo($user);

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();

        $this->mockSequenciaDeLinhas([
            collect([$this->linha($c1->id), $this->linha($c2->id)]),
        ]);

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('consolidar_mes', $row->origem);
            $this->assertSame('2026-07-01', $row->mes_referencia->toDateString());
        }
    }

    // ═══ Teste 2 — idempotência: 2 execuções mantêm N linhas ════════════════

    #[Test]
    public function consolidar_mes_rodado_duas_vezes_mantem_as_mesmas_linhas(): void
    {
        $user = $this->criarUserComCargo('Consolidar Idempotente');
        $this->criarVinculoMinimo($user);

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $linhas = collect([$this->linha($c1->id), $this->linha($c2->id)]);

        $this->mockSequenciaDeLinhas([$linhas, $linhas]);

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $this->contornarBugSqliteUpdateOrCreateMensal($user, '2026-07-01');
        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(4.0, $row->nota_empresa, 0.001);
        }
    }

    // ═══ Teste 3 — reconsolidação com 1 empresa a menos poda ═══════════════

    #[Test]
    public function reconsolidacao_com_uma_empresa_a_menos_poda_a_linha_que_saiu(): void
    {
        $user = $this->criarUserComCargo('Consolidar Poda');
        $this->criarVinculoMinimo($user);

        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();

        $this->mockSequenciaDeLinhas([
            collect([$this->linha($c1->id), $this->linha($c2->id)]),
            collect([$this->linha($c1->id)]),
        ]);

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $this->assertCount(2, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->get());

        $this->contornarBugSqliteUpdateOrCreateMensal($user, '2026-07-01');
        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $idsRestantes = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->pluck('company_id')->all();

        $this->assertSame([$c1->id], $idsRestantes, 'c2 saiu da carteira na reconsolidação — linha podada.');
    }

    // ═══ Teste 4 — snapshot-scores grava linhas diárias + empresas_score ════

    #[Test]
    public function snapshot_scores_grava_linhas_origem_snapshot_diario_e_preenche_empresas_score_no_breakdown(): void
    {
        $user = $this->criarUserComCargo('Snapshot Diario');
        $this->criarVinculoMinimo($user);

        $c1 = Company::factory()->create();

        $this->mockSequenciaDeLinhas([
            collect([$this->linha($c1->id)]),
        ]);

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-08-15'])->assertSuccessful();

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('snapshot_diario', $rows->first()->origem);

        $snapDiario = DesempenhoScoreSnapshot::where('user_id', $user->id)
            ->whereDate('ref_date', '2026-08-15')
            ->whereNull('mes_referencia')
            ->first();

        $this->assertNotNull($snapDiario);
        $this->assertNotEmpty($snapDiario->breakdown_json['empresas_score'] ?? [],
            'SNAP-01: breakdown_json da row DIÁRIA passa a ter empresas_score não-vazio.');
    }

    // ═══ Teste 5 — warm-cache NÃO altera competência já congelada ══════════

    #[Test]
    public function warm_cache_sobre_competencia_congelada_por_consolidar_mes_nao_altera_nenhuma_linha(): void
    {
        $user = $this->criarUserComCargo('Warm Congelado');
        $this->criarVinculoMinimo($user);

        $c1 = Company::factory()->create();

        $this->mockSequenciaDeLinhas([
            collect([$this->linha($c1->id, ['nota_empresa' => 3.5])]), // consolidar-mes
            collect([$this->linha($c1->id, ['nota_empresa' => 9.9])]), // warm-cache (NÃO deveria persistir)
        ]);

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $antes = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->first();
        $this->assertNotNull($antes);
        $updatedAtAntes = $antes->updated_at->toDateTimeString();

        $this->artisan('desempenho:warm-cache', ['--mes' => '2026-07'])->assertSuccessful();

        $depois = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->first();

        $this->assertSame($updatedAtAntes, $depois->updated_at->toDateTimeString(),
            'Competência congelada por consolidar_mes — warm-cache não pode tocar updated_at.');
        $this->assertEqualsWithDelta(3.5, $depois->nota_empresa, 0.001,
            'Valor permanece o do consolidar_mes — o payload 9.9 do warm nunca foi persistido.');
        $this->assertSame('consolidar_mes', $depois->origem);
        $this->assertSame(1, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->count());
    }

    // ═══ Teste 6 — warm-cache SEM congelamento grava origem warm_cache ══════

    #[Test]
    public function warm_cache_sobre_competencia_sem_congelamento_grava_linhas_origem_warm_cache(): void
    {
        $user = $this->criarUserComCargo('Warm Sem Congelamento');
        $this->criarVinculoMinimo($user);

        $c1 = Company::factory()->create();

        $this->mockSequenciaDeLinhas([
            collect([$this->linha($c1->id)]),
        ]);

        $this->artisan('desempenho:warm-cache', ['--mes' => '2026-07'])->assertSuccessful();

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('warm_cache', $rows->first()->origem);
    }

    // ═══ Teste 7 — user sem_carteira não gera nenhuma linha ═════════════════

    #[Test]
    public function user_sem_carteira_nao_gera_nenhuma_linha_por_empresa(): void
    {
        // Cargo elegível, mas ZERO vínculos de qualquer setor — sem_carteira=true.
        $user = $this->criarUserComCargo('Sem Carteira Nenhuma');

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->count());
        $this->assertSame(0, DesempenhoScoreSnapshot::where('user_id', $user->id)->count());
    }
}
