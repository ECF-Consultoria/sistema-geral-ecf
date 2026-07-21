<?php

namespace Tests\Feature;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Item 3/4 (2026-07-21) — invalidação de empresa para BÔNUS por competência.
 *
 * Prova: (1) `companyIdsInvalidadas` isola por competência; (2) a rota toggle é
 * admin-only e cria/remove a linha; (3) invalidar a empresa remove o NPS dela
 * da nota do profissional NA COMPETÊNCIA CERTA — a sutileza é que o NPS da
 * competência M é coletado em M+1, mas a chave de invalidação é sempre M.
 */
class BonusInvalidacaoEmpresaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
        // Mês em curso = julho/2026 → último fechado = junho (competência do
        // "Bônus atual"). O NPS de junho é coletado em julho (janela +1).
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): DesempenhoScoreService
    {
        return app(DesempenhoScoreService::class);
    }

    /** Cria survey completo + response + atribuição de NPS para o user no papel dado. */
    private function criarNpsAtribuido(Company $company, User $user, Carbon $completedAt, float $nota = 4.0, string $role = 'consultor'): void
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => $completedAt->copy()->addDays(7),
            'status'       => 'completed',
            'completed_at' => $completedAt,
            'template_id'  => null,
        ]);

        $response = NpsResponse::create([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => (int) $nota,
            'score_analista'     => (int) $nota,
            'score_empresa'      => (int) $nota,
        ]);

        $score = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'analista',
            'score_sum'       => $nota,
            'question_count'  => 1,
            'average_score'   => $nota,
            'calculated_at'   => $completedAt,
        ]);

        NpsScoreAssignment::create([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $score->id,
            'company_id'            => $company->id,
            'servico_id'            => null,
            'service_setor'         => 'performance',
            'role'                  => $role,
            'user_id'               => $user->id,
            'average_score'         => $nota,
            'assigned_at'           => $completedAt,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_company_ids_invalidadas_isola_por_competencia(): void
    {
        $company = Company::factory()->create(['active' => true]);

        BonusInvalidacao::create([
            'company_id'  => $company->id,
            'competencia' => '2026-06-01',
            'invalidated_by' => null,
        ]);

        $this->assertTrue(
            BonusInvalidacao::companyIdsInvalidadas(Carbon::parse('2026-06-10'))->contains($company->id),
            'junho deveria listar a empresa invalidada (startOfMonth normaliza o dia).'
        );
        $this->assertFalse(
            BonusInvalidacao::companyIdsInvalidadas(Carbon::parse('2026-07-01'))->contains($company->id),
            'julho NÃO deveria enxergar a invalidação de junho.'
        );
    }

    #[Test]
    public function test_toggle_e_admin_only_e_cria_e_remove_a_invalidacao(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $naoAdmin = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $company = Company::factory()->create(['active' => true]);

        // Não-admin → 403.
        $this->actingAs($naoAdmin)
            ->post(route('desempenho.auditoria-bonus.toggle'), [
                'company_id' => $company->id, 'competencia' => '2026-06',
            ])
            ->assertForbidden();

        // Admin invalida (cria).
        $this->actingAs($admin)
            ->post(route('desempenho.auditoria-bonus.toggle'), [
                'company_id' => $company->id, 'competencia' => '2026-06', 'motivo' => 'Custo não preenchido',
            ])
            ->assertRedirect();

        // `competencia` é coluna DATE — no SQLite volta como '...00:00:00',
        // em MySQL como '2026-06-01'; whereDate normaliza os dois.
        $this->assertTrue(
            BonusInvalidacao::whereDate('competencia', '2026-06-01')
                ->where('company_id', $company->id)
                ->where('motivo', 'Custo não preenchido')
                ->where('invalidated_by', $admin->id)
                ->exists(),
            'a invalidação de junho deveria ter sido criada.'
        );

        // Admin toggla de novo → remove (reativa).
        $this->actingAs($admin)
            ->post(route('desempenho.auditoria-bonus.toggle'), [
                'company_id' => $company->id, 'competencia' => '2026-06',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('bonus_invalidacoes', [
            'company_id' => $company->id, 'competencia' => '2026-06-01',
        ]);
    }

    #[Test]
    public function test_invalidar_empresa_remove_o_nps_dela_na_competencia_certa(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $analista = $cenario['analista'];

        // NPS da competência junho é coletado em JULHO (janela +1).
        $this->criarNpsAtribuido($company, $analista, Carbon::parse('2026-07-05 10:00:00'), 4.0);

        // Antes: o NPS de junho (coletado em julho) entra na nota.
        $antes = $this->service()->computeOficial($analista);
        $this->assertSame(4.0, $antes['componentes']['nps_medio'],
            'antes da invalidação, o NPS da empresa deveria compor a nota de junho.');

        // Invalida a empresa para a competência JUNHO (não julho).
        BonusInvalidacao::create([
            'company_id' => $company->id, 'competencia' => '2026-06-01', 'invalidated_by' => null,
        ]);

        // Depois: o NPS some (empresa invalidada em junho remove o NPS coletado
        // em julho, porque a chave é a competência M=junho).
        $depois = $this->service()->computeOficial($analista);
        $this->assertNull($depois['componentes']['nps_medio'],
            'após invalidar junho, o NPS da empresa deve sumir da nota de junho.');
    }

    #[Test]
    public function test_invalidar_uma_empresa_nao_remove_o_nps_das_outras(): void
    {
        $cenario  = $this->criarCenarioMlComResponsaveis();
        $empresaX = $cenario['company'];
        $analista = $cenario['analista'];
        $servico  = $cenario['servicoPerf'];

        // Segunda empresa na carteira do MESMO analista, mesma competência.
        $empresaY = Company::factory()->create(['active' => true]);
        $this->inserirPivot($empresaY->id, $analista->id, 'consultor', $servico);

        // Ambas com NPS de junho (coletado em julho): X=4.0, Y=5.0.
        $this->criarNpsAtribuido($empresaX, $analista, Carbon::parse('2026-07-05 10:00:00'), 4.0);
        $this->criarNpsAtribuido($empresaY, $analista, Carbon::parse('2026-07-06 10:00:00'), 5.0);

        // Antes: média das duas = 4.5.
        $antes = $this->service()->computeOficial($analista);
        $this->assertSame(4.5, $antes['componentes']['nps_medio']);

        // Invalida SÓ a empresa X para junho.
        BonusInvalidacao::create([
            'company_id' => $empresaX->id, 'competencia' => '2026-06-01', 'invalidated_by' => null,
        ]);

        // Depois: só o NPS de Y (5.0) resta — a invalidação de X não tocou Y.
        $depois = $this->service()->computeOficial($analista);
        $this->assertSame(5.0, $depois['componentes']['nps_medio'],
            'invalidar a empresa X não pode remover o NPS da empresa Y.');
    }
}
