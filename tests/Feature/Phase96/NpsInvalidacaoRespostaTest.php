<?php

namespace Tests\Feature\Phase96;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 96 Plan 03 (AB-96-3) — FUNDAÇÃO da invalidação manual de resposta NPS.
 *
 * Prova: flag `invalidated_at`/`invalidated_by` + `scopeValida()` (Task 1);
 * ações admin-only `invalidarResposta()`/`revalidarResposta()` com trilha
 * `activity()` explícita + cache-busting do bônus (mês fechado) + filtro nos
 * cards/série de `NpsController::index()` sem afetar a listagem paginada
 * (Task 2). O molde de setup segue `tests/Feature/Phase95/NpsConfiancaPayloadTest.php`
 * (NpsResponse/NpsSurvey criados direto via `::create()`, nunca via POST
 * `/nps/{token}`, para não contaminar `suspicion_reasons`).
 */
class NpsInvalidacaoRespostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — necessário para os relacionamentos usados
        // (invalidated_by → users, assignments → scores/responses).
        DB::statement('PRAGMA foreign_keys = ON');
        // Congela "agora" — evita que o filtro de mês do index() ou o cálculo
        // de "mês fechado" do cache-busting caiam numa virada de mês durante
        // a execução da suite.
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    /** Cria um survey `completed` legado (template_id null). */
    private function criarSurveyCompleto(Company $company, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'completed',
            'completed_at' => now(),
            'template_id'  => null,
        ], $overrides));
    }

    /** Cria a NpsResponse direto (nunca via POST — evita contaminar o veredito de suspeita). */
    private function criarResponse(NpsSurvey $survey, array $overrides = []): NpsResponse
    {
        return NpsResponse::create(array_merge([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => 4,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => null,
            'response_ip_address'       => '203.0.113.10',
            'response_user_agent'       => 'Mozilla/5.0 (teste)',
            'response_duration_seconds' => 300,
            'is_suspicious'             => false,
            'suspicion_reasons'         => null,
        ], $overrides));
    }

    /**
     * Cria o snapshot mínimo (Fase 79) para simular uma resposta v15 com
     * template: 1 `NpsResponseScore` + 1 `NpsScoreAssignment` amarrados à
     * pessoa informada. Suficiente para o cache-busting da invalidação
     * (que só lê `NpsScoreAssignment::where('nps_response_id', ...)`), sem
     * precisar montar o fluxo real de template/perguntas/opções.
     */
    private function criarSnapshot(NpsResponse $response, Company $company, User $user, string $role = 'consultor'): NpsScoreAssignment
    {
        $score = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'analista',
            'score_sum'       => 4,
            'question_count'  => 1,
            'average_score'   => 4,
            'calculated_at'   => now(),
        ]);

        return NpsScoreAssignment::create([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $score->id,
            'company_id'            => $company->id,
            'servico_id'            => null,
            'service_setor'         => 'performance',
            'role'                  => $role,
            'user_id'               => $user->id,
            'average_score'         => 4,
            'assigned_at'           => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1 — flag de invalidação + scopeValida()
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_flag_invalidated_at_e_invalidated_by_persistem_via_update(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $response = $this->criarResponse($survey);

        $this->assertNull($response->invalidated_at);
        $this->assertNull($response->invalidated_by);

        $response->update(['invalidated_at' => now(), 'invalidated_by' => $admin->id]);
        $response->refresh();

        $this->assertNotNull($response->invalidated_at);
        $this->assertSame($admin->id, $response->invalidated_by);
        $this->assertInstanceOf(Carbon::class, $response->invalidated_at);
    }

    #[Test]
    public function test_scope_valida_exclui_a_invalidada_e_mantem_a_limpa(): void
    {
        $company = Company::factory()->create(['active' => true]);

        $surveyLimpo = $this->criarSurveyCompleto($company);
        $respostaLimpa = $this->criarResponse($surveyLimpo);

        $surveyInvalidado = $this->criarSurveyCompleto($company);
        $respostaInvalidada = $this->criarResponse($surveyInvalidado, [
            'invalidated_at' => now(),
            'invalidated_by' => $this->admin()->id,
        ]);

        $validas = NpsResponse::query()->valida()->pluck('id');

        $this->assertTrue($validas->contains($respostaLimpa->id));
        $this->assertFalse($validas->contains($respostaInvalidada->id));
    }
}
