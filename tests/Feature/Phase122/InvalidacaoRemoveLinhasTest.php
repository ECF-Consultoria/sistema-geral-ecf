<?php

namespace Tests\Feature\Phase122;

use App\Models\BonusInvalidacao;
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
 * Fase 122 Plano 04 (SNAP-04) — a invalidação de empresa por competência
 * (`BonusAuditoriaController::toggle()`) também remove as linhas de
 * `desempenho_company_score_snapshots` daquela competência, ao lado do
 * snapshot MENSAL agregado que já era apagado.
 *
 * D-122-08: escopo `(competência) AND (empresa OU profissional afetado)`.
 * D-122-09: os dois sentidos do toggle (invalidar E reativar) limpam.
 * T-122-15/T-122-18: sem vazamento entre competências e sem detalhe
 * sobrevivendo ao resumo apagado.
 *
 * Todas as asserções por RECONSULTA ao banco (122-CONTEXT.md item 5).
 */
class InvalidacaoRemoveLinhasTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Semeia direto pelo model — não é preciso rodar comando algum. */
    private function seedLinha(int $userId, int $companyId, string $mesStr, array $overrides = []): DesempenhoCompanyScoreSnapshot
    {
        return DesempenhoCompanyScoreSnapshot::create(array_merge([
            'user_id'               => $userId,
            'company_id'            => $companyId,
            'mes_referencia'        => $mesStr,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'nota_empresa'          => 4.0,
            'nota_empresa_parcial'  => 4.0,
            'componentes_presentes' => 3,
            'origem'                => 'consolidar_mes',
            'gerado_em'             => now(),
        ], $overrides));
    }

    private function seedMensal(int $userId, string $mesStr): DesempenhoScoreSnapshot
    {
        return DesempenhoScoreSnapshot::create([
            'user_id'              => $userId,
            'ref_date'             => $mesStr,
            'mes_referencia'       => $mesStr,
            'score'                => 80,
            'classificacao'        => 'intermediario',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => [],
        ]);
    }

    private function toggle(User $admin, int $companyId, string $mesLabel): void
    {
        $this->actingAs($admin)
            ->post(route('desempenho.auditoria-bonus.toggle'), [
                'company_id'  => $companyId,
                'competencia' => $mesLabel,
            ])
            ->assertRedirect();
    }

    // ═══ Comportamento 1 — invalidar apaga as linhas da empresa e dos profissionais vinculados ═══

    #[Test]
    public function invalidar_empresa_apaga_linhas_da_competencia_da_empresa_e_dos_profissionais_vinculados(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $analista = $cenario['analista'];

        $this->seedLinha($analista->id, $company->id, '2026-06-01');

        $this->toggle($this->admin(), $company->id, '2026-06');

        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::daCompetencia('2026-06-01')
            ->where('company_id', $company->id)
            ->count(),
            'a linha da empresa invalidada em junho deveria ter sido apagada.');
    }

    // ═══ Comportamento 2 — outras competências dos mesmos profissionais permanecem intactas ═══

    #[Test]
    public function invalidar_junho_nao_toca_linhas_de_maio_dos_mesmos_profissionais(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $analista = $cenario['analista'];

        $this->seedLinha($analista->id, $company->id, '2026-06-01');
        $linhaMaio = $this->seedLinha($analista->id, $company->id, '2026-05-01');

        $this->toggle($this->admin(), $company->id, '2026-06');

        $this->assertTrue(
            DesempenhoCompanyScoreSnapshot::whereKey($linhaMaio->id)->exists(),
            'a linha de maio do mesmo profissional/empresa não pode ser tocada pela invalidação de junho.'
        );
    }

    // ═══ Comportamento 3 — profissional sem vínculo e outras empresas permanecem intactos ═══

    #[Test]
    public function invalidar_empresa_x_nao_toca_linhas_de_profissional_sem_vinculo_nem_de_outras_empresas(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $empresaX = $cenario['company'];
        $analista = $cenario['analista'];

        // Profissional Q, sem qualquer vínculo com a empresa X, e empresa Y.
        $profissionalQ = User::factory()->create();
        $empresaY = Company::factory()->create();
        $linhaAlheia = $this->seedLinha($profissionalQ->id, $empresaY->id, '2026-06-01');

        $this->seedLinha($analista->id, $empresaX->id, '2026-06-01');

        $this->toggle($this->admin(), $empresaX->id, '2026-06');

        $this->assertTrue(
            DesempenhoCompanyScoreSnapshot::whereKey($linhaAlheia->id)->exists(),
            'a linha de um profissional sem vínculo com a empresa invalidada, de outra empresa, não pode ser tocada.'
        );
    }

    // ═══ Comportamento 4 — reativar (segundo toggle) executa a mesma limpeza ═══

    #[Test]
    public function reativar_empresa_segundo_toggle_tambem_apaga_as_linhas_da_competencia(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $analista = $cenario['analista'];
        $admin = $this->admin();

        // 1º toggle: invalida (não havia linha ainda — só prova o fluxo).
        $this->toggle($admin, $company->id, '2026-06');
        $this->assertDatabaseHas('bonus_invalidacoes', ['company_id' => $company->id]);

        // Simula um recompute que rodou ENQUANTO a empresa estava invalidada
        // (ex.: warm-cache de outra competência tocando o mesmo par por
        // coincidência de fixture) — a linha reaparece antes da reativação.
        $this->seedLinha($analista->id, $company->id, '2026-06-01');

        // 2º toggle: reativa — a limpeza roda de novo (D-122-09).
        $this->toggle($admin, $company->id, '2026-06');
        $this->assertDatabaseMissing('bonus_invalidacoes', ['company_id' => $company->id]);

        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::daCompetencia('2026-06-01')
            ->where('company_id', $company->id)
            ->count(),
            'a reativação (2º toggle) deveria ter apagado a linha reaparecida também.');
    }

    // ═══ Teste 5 — não-vazamento por competência ═══════════════════════════

    #[Test]
    public function nao_vaza_para_outras_competencias(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $analista = $cenario['analista'];
        $estrategista = $cenario['estrategista'];

        // 3 competências, 2 linhas cada (analista + estrategista).
        foreach (['2026-04-01', '2026-05-01', '2026-06-01'] as $mesStr) {
            $this->seedLinha($analista->id, $company->id, $mesStr);
            $this->seedLinha($estrategista->id, $company->id, $mesStr);
        }

        $this->toggle($this->admin(), $company->id, '2026-06');

        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::daCompetencia('2026-06-01')->count(),
            'junho (competência invalidada) deveria ficar sem nenhuma linha.');
        $this->assertSame(2, DesempenhoCompanyScoreSnapshot::daCompetencia('2026-05-01')->count(),
            'maio deveria manter a contagem exata de linhas.');
        $this->assertSame(2, DesempenhoCompanyScoreSnapshot::daCompetencia('2026-04-01')->count(),
            'abril deveria manter a contagem exata de linhas.');
    }

    // ═══ Teste 6 — coerência: resumo mensal e detalhe por empresa nunca ficam meio-apagados ═══

    #[Test]
    public function resumo_mensal_e_detalhe_por_empresa_ficam_ambos_vazios_apos_o_toggle(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $analista = $cenario['analista'];
        $estrategista = $cenario['estrategista'];

        foreach ([$analista, $estrategista] as $user) {
            $this->seedMensal($user->id, '2026-06-01');
            $this->seedLinha($user->id, $company->id, '2026-06-01');
        }

        $this->toggle($this->admin(), $company->id, '2026-06');

        foreach ([$analista, $estrategista] as $user) {
            $mensalCount = DesempenhoScoreSnapshot::mensal()
                ->where('user_id', $user->id)
                ->whereDate('mes_referencia', '2026-06-01')
                ->count();
            $detalheCount = DesempenhoCompanyScoreSnapshot::daCompetencia('2026-06-01')
                ->where('user_id', $user->id)
                ->count();

            $this->assertSame(0, $mensalCount, "resumo mensal do user {$user->id} deveria estar vazio.");
            $this->assertSame(0, $detalheCount, "detalhe por empresa do user {$user->id} deveria estar vazio.");
        }
    }

    // ═══ Teste 7 — round-trip com a reconsolidação ══════════════════════════

    /**
     * Cargo elegível ao filtro de `ConsolidarMesDesempenho` (analista/estrategista
     * em `user_setores`/`cargos`) — mesmo padrão do
     * `Phase122/ComandosGravamEmpresasTest::criarUserComCargo()`.
     */
    private function criarUserComCargoAnalista(): User
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Performance', 'slug' => 'performance-122-p04',
            'active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cargoId = DB::table('cargos')->insertGetId([
            'setor_id' => $setorId, 'nome' => 'Analista', 'slug' => 'analista',
            'active' => true, 'ordem' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId, 'cargo_id' => $cargoId,
            'is_principal' => true, 'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** Linha no shape de `CompanyScoreService::computeEmpresasScore()`. */
    private function linhaMock(int $companyId, array $overrides = []): object
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

    /** Ver `Phase122/ComandosGravamEmpresasTest` — armadilha SQLite pré-existente, fora de escopo. */
    private function contornarBugSqliteUpdateOrCreateMensal(User $user, string $mesStr): void
    {
        DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesStr)
            ->delete();
    }

    #[Test]
    public function invalidar_a_empresa_e_reconsolidar_faz_as_linhas_voltarem_sem_a_empresa_invalidada(): void
    {
        $user = $this->criarUserComCargoAnalista();

        // Vínculo mínimo (setor Shopee, evita acionar o gate FIXMARG-03 real —
        // ver `ComandosGravamEmpresasTest::criarVinculoMinimo()`).
        $servico = $this->criarServico(Servico::SETOR_SHOPEE);
        $companyVinculo = Company::factory()->create();
        $this->criarContrato($companyVinculo->id, $servico, true);
        $this->inserirPivot($companyVinculo->id, $user->id, 'consultor', $servico);

        $empresaX = Company::factory()->create(); // será invalidada
        $empresaY = Company::factory()->create(); // permanece

        $this->mock(CompanyScoreService::class, function ($mock) use ($empresaX, $empresaY) {
            $mock->shouldReceive('computeEmpresasScore')->andReturn(
                collect([$this->linhaMock($empresaX->id), $this->linhaMock($empresaY->id)]), // 1ª rodada
                collect([$this->linhaMock($empresaY->id)]), // 2ª rodada — contrato Fase 119: já vem sem a invalidada
            );
        });

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-06'])->assertSuccessful();

        $this->assertSame(
            [$empresaX->id, $empresaY->id],
            DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
                ->whereDate('mes_referencia', '2026-06-01')
                ->orderBy('company_id')
                ->pluck('company_id')
                ->sort()
                ->values()
                ->all(),
            'antes da invalidação, as duas empresas deveriam ter linha.'
        );

        // Invalida a empresa X para a competência de junho.
        BonusInvalidacao::create([
            'company_id'  => $empresaX->id,
            'competencia' => '2026-06-01',
            'invalidated_by' => null,
        ]);

        $this->contornarBugSqliteUpdateOrCreateMensal($user, '2026-06-01');
        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-06'])->assertSuccessful();

        $idsRestantes = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->pluck('company_id')
            ->all();

        $this->assertSame([$empresaY->id], $idsRestantes,
            'após reconsolidar com a empresa invalidada fora do retorno, a linha da empresa X deve ter sido podada.');
    }
}
