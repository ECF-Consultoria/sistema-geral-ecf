<?php

namespace Tests\Feature\Phase122;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 122 Plano 05 (SNAP-06) — suíte do `desempenho:verificar-consolidacao`.
 *
 * Semeia as duas tabelas DIRETO pelos models — nunca roda
 * `desempenho:consolidar-mes` — o verificador só lê banco, então a fixture é
 * barata e determinística (mesmo padrão do `InvalidacaoRemoveLinhasTest`).
 *
 * Toda asserção de conteúdo usa o modo `--json` (captura + json_decode) e o
 * EXIT CODE — nunca a tabela impressa (122-CONTEXT.md item 5, D-122-12).
 */
class VerificarConsolidacaoTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-122-verif',
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

    private function criarUserElegivel(string $nome): User
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
     * Vincula uma empresa à carteira do profissional (D-122-12).
     *
     * Sem isto, o profissional é elegível pelo CARGO mas não tem carteira — e
     * nesse caso o `consolidar-mes` não grava row mensal de propósito, então
     * `SEM_SNAPSHOT` não se aplica.
     */
    private function darCarteira(int $userId): int
    {
        $company = Company::factory()->create();

        DB::table('company_users')->insert([
            'user_id'    => $userId,
            'company_id' => $company->id,
            'role'       => 'consultor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $company->id;
    }

    private function seedMensal(int $userId, string $mesStr, array $overrides = []): DesempenhoScoreSnapshot
    {
        return DesempenhoScoreSnapshot::create(array_merge([
            'user_id'              => $userId,
            'ref_date'             => $mesStr,
            'mes_referencia'       => $mesStr,
            'score'                => 80,
            'classificacao'        => 'intermediario',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => [
                    'cobertura' => 0.8,
                    'base'      => 'margem_var_pp',
                    'legado'    => ['cobertura' => 0.75],
                ],
                'empresas_score' => [],
            ],
        ], $overrides));
    }

    private function seedLinha(int $userId, int $companyId, string $mesStr, array $overrides = []): DesempenhoCompanyScoreSnapshot
    {
        return DesempenhoCompanyScoreSnapshot::create(array_merge([
            'user_id'               => $userId,
            'company_id'            => $companyId,
            'mes_referencia'        => $mesStr,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'margem_var_pp'         => 2.0,
            'nota_empresa'          => 4.0,
            'nota_empresa_parcial'  => 4.0,
            'componentes_presentes' => 3,
            'origem'                => 'consolidar_mes',
            'gerado_em'             => now(),
        ], $overrides));
    }

    /** Roda o comando e devolve [exitCode, jsonDecodificado]. */
    private function executar(string $mes): array
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('desempenho:verificar-consolidacao', [
            '--mes'  => $mes,
            '--json' => true,
        ]);
        $saida = \Illuminate\Support\Facades\Artisan::output();

        $decoded = json_decode($saida, true);
        $this->assertIsArray($decoded, "saída --json não é JSON parseável. Saída bruta:\n{$saida}");

        return [$exitCode, $decoded];
    }

    private function inconsistenciasDoUser(array $relatorio, int $userId): array
    {
        $u = collect($relatorio['usuarios'])->firstWhere('user_id', $userId);
        $this->assertNotNull($u, "user_id {$userId} não apareceu no relatório.");

        return $u['inconsistencias'];
    }

    // ═══ Comportamento 1 — competência 100% consistente ═════════════════════

    #[Test]
    public function competencia_100_por_cento_consistente_da_exit_0_sem_inconsistencias(): void
    {
        $user = $this->criarUserElegivel('Consistente');
        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $this->seedMensal($user->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                'empresas_score' => [['company_id' => $c1->id], ['company_id' => $c2->id]],
            ],
        ]);
        $this->seedLinha($user->id, $c1->id, '2026-06-01', ['origem' => 'consolidar_mes']);
        $this->seedLinha($user->id, $c2->id, '2026-06-01', ['origem' => 'consolidar_mes']);

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $relatorio['resumo']['total_inconsistencias']);
        $this->assertSame([], $this->inconsistenciasDoUser($relatorio, $user->id));
    }

    // ═══ Comportamento 2 — SEM_SNAPSHOT ══════════════════════════════════════

    #[Test]
    public function user_elegivel_sem_row_mensal_gera_sem_snapshot_com_id_e_nome_exit_diferente_de_zero(): void
    {
        $user = $this->criarUserElegivel('Sem Snapshot Nenhum');
        $this->darCarteira($user->id);
        // Nenhuma row mensal, nenhuma linha por empresa.

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertNotSame(0, $exitCode);
        $this->assertContains('SEM_SNAPSHOT', $this->inconsistenciasDoUser($relatorio, $user->id));

        $inc = collect($relatorio['inconsistencias'])->firstWhere('tipo', 'SEM_SNAPSHOT');
        $this->assertNotNull($inc, 'SEM_SNAPSHOT deveria aparecer na lista achatada com id e nome.');
        $this->assertSame($user->id, $inc['user_id']);
        $this->assertSame('Sem Snapshot Nenhum', $inc['user_name']);
    }

    /**
     * D-122-12 — achado no rollout em produção (2026-08-03).
     *
     * Jhonathan (user 25) é elegível pelo cargo mas tem ZERO empresas na
     * carteira. O `consolidar-mes` o pula de propósito ("Sem carteira: 1") e
     * não grava row mensal. Antes deste fix o verificador o acusava de
     * `SEM_SNAPSHOT` e devolvia exit 1 — o que tornaria o comando inútil como
     * gate, porque ele reprovaria para sempre por uma situação correta.
     */
    #[Test]
    public function user_elegivel_SEM_carteira_nao_gera_sem_snapshot_e_mantem_exit_zero(): void
    {
        $user = $this->criarUserElegivel('Jhonathan Sem Carteira');
        // Elegível pelo cargo, mas NENHUMA empresa vinculada — e nenhuma row.

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertSame(0, $exitCode, 'Profissional sem carteira não é inconsistência — o exit code precisa continuar 0.');
        $this->assertNotContains('SEM_SNAPSHOT', $this->inconsistenciasDoUser($relatorio, $user->id));

        $linha = collect($relatorio['usuarios'])->firstWhere('user_id', $user->id);
        $this->assertNotNull($linha);
        $this->assertTrue($linha['elegivel'], 'Continua elegível pelo cargo.');
        $this->assertFalse($linha['tem_carteira'], 'A ausência de carteira precisa ficar auditável na saída.');
    }

    // ═══ Comportamento 3 — SEM_LINHAS ════════════════════════════════════════

    #[Test]
    public function row_mensal_sem_nenhuma_linha_por_empresa_gera_sem_linhas_exit_diferente_de_zero(): void
    {
        $user = $this->criarUserElegivel('Sem Linhas');
        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $this->seedMensal($user->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                'empresas_score' => [['company_id' => $c1->id], ['company_id' => $c2->id]],
            ],
        ]);
        // Nenhuma linha em desempenho_company_score_snapshots.

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertNotSame(0, $exitCode);
        $this->assertContains('SEM_LINHAS', $this->inconsistenciasDoUser($relatorio, $user->id));
    }

    // ═══ Comportamento 4 — LINHAS_ORFAS ══════════════════════════════════════

    #[Test]
    public function linhas_por_empresa_sem_row_mensal_gera_linhas_orfas_exit_diferente_de_zero(): void
    {
        $user = $this->criarUserElegivel('Linhas Orfas');
        $c1 = Company::factory()->create();
        $this->seedLinha($user->id, $c1->id, '2026-06-01');
        // Nenhuma row mensal.

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertNotSame(0, $exitCode);
        $this->assertContains('LINHAS_ORFAS', $this->inconsistenciasDoUser($relatorio, $user->id));
    }

    // ═══ Comportamento 5 — DIVERGENCIA_EMPRESAS_SCORE ════════════════════════

    #[Test]
    public function contagem_de_linhas_divergente_do_breakdown_gera_divergencia_empresas_score(): void
    {
        $user = $this->criarUserElegivel('Divergencia');
        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $c3 = Company::factory()->create();
        $this->seedMensal($user->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                // breakdown diz 3 empresas...
                'empresas_score' => [['company_id' => $c1->id], ['company_id' => $c2->id], ['company_id' => $c3->id]],
            ],
        ]);
        // ...mas só 2 linhas persistidas.
        $this->seedLinha($user->id, $c1->id, '2026-06-01');
        $this->seedLinha($user->id, $c2->id, '2026-06-01');

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertNotSame(0, $exitCode);
        $this->assertContains('DIVERGENCIA_EMPRESAS_SCORE', $this->inconsistenciasDoUser($relatorio, $user->id));
    }

    // ═══ Comportamento 6 — ORIGEM_NAO_CONGELADA ══════════════════════════════

    #[Test]
    public function competencia_fechada_com_origem_diferente_de_consolidar_mes_gera_origem_nao_congelada(): void
    {
        $user = $this->criarUserElegivel('Origem Nao Congelada');
        $c1 = Company::factory()->create();
        $this->seedMensal($user->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                'empresas_score' => [['company_id' => $c1->id]],
            ],
        ]);
        // Competência FECHADA (2026-06, "hoje" é 2026-08-15) com linha de
        // origem warm_cache — nunca deveria sobreviver assim numa fechada.
        $this->seedLinha($user->id, $c1->id, '2026-06-01', ['origem' => 'warm_cache']);

        [$exitCode, $relatorio] = $this->executar('2026-06');

        $this->assertNotSame(0, $exitCode);
        $this->assertContains('ORIGEM_NAO_CONGELADA', $this->inconsistenciasDoUser($relatorio, $user->id));
        $this->assertTrue($relatorio['mes_fechado']);
    }

    #[Test]
    public function mes_em_curso_com_origem_warm_cache_nao_gera_origem_nao_congelada(): void
    {
        $user = $this->criarUserElegivel('Mes Em Curso');
        $c1 = Company::factory()->create();
        $this->seedMensal($user->id, '2026-08-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                'empresas_score' => [['company_id' => $c1->id]],
            ],
        ]);
        $this->seedLinha($user->id, $c1->id, '2026-08-01', ['origem' => 'warm_cache']);

        // "Hoje" é 2026-08-15 (setUp) — 2026-08 é o mês em curso.
        [$exitCode, $relatorio] = $this->executar('2026-08');

        $this->assertFalse($relatorio['mes_fechado']);
        $this->assertNotContains('ORIGEM_NAO_CONGELADA', $this->inconsistenciasDoUser($relatorio, $user->id));
        $this->assertSame(0, $exitCode);
    }

    // ═══ Comportamento 7 — --json imprime JSON parseável com o mesmo conteúdo ═══

    #[Test]
    public function json_imprime_saida_parseavel_com_o_mesmo_conteudo_do_relatorio(): void
    {
        $user = $this->criarUserElegivel('Json Parseavel');
        $c1 = Company::factory()->create();
        $this->seedMensal($user->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                'empresas_score' => [['company_id' => $c1->id]],
            ],
        ]);
        $this->seedLinha($user->id, $c1->id, '2026-06-01');

        [, $relatorio] = $this->executar('2026-06');

        $this->assertArrayHasKey('mes', $relatorio);
        $this->assertArrayHasKey('mes_referencia', $relatorio);
        $this->assertArrayHasKey('mes_fechado', $relatorio);
        $this->assertArrayHasKey('usuarios', $relatorio);
        $this->assertArrayHasKey('inconsistencias', $relatorio);
        $this->assertArrayHasKey('resumo', $relatorio);
        $this->assertSame('2026-06', $relatorio['mes']);
        $this->assertSame('2026-06-01', $relatorio['mes_referencia']);

        $u = collect($relatorio['usuarios'])->firstWhere('user_id', $user->id);
        $this->assertNotNull($u);
        $this->assertSame('Json Parseavel', $u['user_name']);
        $this->assertTrue($u['snapshot_mensal']['existe']);
        $this->assertSame(1, $u['linhas_por_empresa']['total']);
    }

    // ═══ Teste de não-escrita (D-122-10) ═════════════════════════════════════

    #[Test]
    public function comando_e_read_only_nao_altera_nenhuma_row_das_duas_tabelas(): void
    {
        $user = $this->criarUserElegivel('Read Only');
        $c1 = Company::factory()->create();
        $mensal = $this->seedMensal($user->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => ['cobertura' => 0.9, 'base' => 'margem_var_pp', 'legado' => ['cobertura' => 0.9]],
                'empresas_score' => [['company_id' => $c1->id]],
            ],
        ]);
        $linha = $this->seedLinha($user->id, $c1->id, '2026-06-01');

        $countMensalAntes = DesempenhoScoreSnapshot::count();
        $countLinhasAntes = DesempenhoCompanyScoreSnapshot::count();
        $updatedAtMensalAntes = $mensal->fresh()->updated_at->toDateTimeString();
        $updatedAtLinhaAntes  = $linha->fresh()->updated_at->toDateTimeString();

        $this->artisan('desempenho:verificar-consolidacao', ['--mes' => '2026-06'])->assertSuccessful();

        $this->assertSame($countMensalAntes, DesempenhoScoreSnapshot::count());
        $this->assertSame($countLinhasAntes, DesempenhoCompanyScoreSnapshot::count());
        $this->assertSame($updatedAtMensalAntes, $mensal->fresh()->updated_at->toDateTimeString());
        $this->assertSame($updatedAtLinhaAntes, $linha->fresh()->updated_at->toDateTimeString());
    }
}
