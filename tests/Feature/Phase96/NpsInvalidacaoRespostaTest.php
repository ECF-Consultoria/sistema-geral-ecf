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
use Tests\Feature\V16\CriaCenarioResponsaveis;
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
    use CriaCenarioResponsaveis;

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

    // ═══════════════════════════════════════════════════════════════════
    // Task 2 — invalidarResposta()/revalidarResposta() admin-only +
    // cache-busting + activitylog + filtro em index()
    // ═══════════════════════════════════════════════════════════════════

    /** Lê a última entrada de activity_log da resposta (subject = NpsResponse). */
    private function ultimaActivity(NpsResponse $response): ?Activity
    {
        return Activity::where('subject_type', NpsResponse::class)
            ->where('subject_id', $response->id)
            ->latest('id')
            ->first();
    }

    /** GET /nps?template_id=__todos__ (evita o filtro default=principal do index()). */
    private function propsDoIndex(User $user): array
    {
        $props = null;

        $this->actingAs($user)
            ->get('/nps?template_id=__todos__')
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        $this->assertIsArray($props, 'O payload Inertia deveria trazer props.');

        return $props;
    }

    #[Test]
    public function test_admin_invalida_grava_flag_e_activity_log_com_motivo(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $response = $this->criarResponse($survey);

        $this->actingAs($admin)
            ->patch(route('nps.responses.invalidar', $survey), ['motivo' => 'Resposta veio da rede interna'])
            ->assertRedirect();

        $response->refresh();
        $this->assertNotNull($response->invalidated_at);
        $this->assertSame($admin->id, $response->invalidated_by);

        $activity = $this->ultimaActivity($response);
        $this->assertNotNull($activity, 'Deveria existir 1 entrada em activity_log para a invalidação.');
        $this->assertSame('Resposta NPS invalidada', $activity->description);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('Resposta veio da rede interna', $activity->properties['motivo'] ?? null);
    }

    #[Test]
    public function test_invalidar_resposta_de_mes_fechado_com_atribuicao_busta_o_cache_do_bonus(): void
    {
        $admin   = $this->admin();
        $pessoa  = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company, ['completed_at' => Carbon::parse('2026-06-15 10:00:00')]);
        $response = $this->criarResponse($survey);
        $this->criarSnapshot($response, $company, $pessoa);

        // Fase 105 (NPSWIN-03): completed_at em junho/2026 (X) alimenta a
        // competência X−1 = maio/2026 (DesempenhoScoreService::computeNpsWindow
        // desloca a leitura do NPS +1 mês desde a 105-01 — maio lê o NPS de
        // junho). O bust tem que atingir a chave de MAIO, não a de junho.
        // Prefixo v15 (quick 260731-pvk: bump v14→v15, mediana no faturamento).
        $cacheKey = sprintf('desempenho.compute.v16.%d.%s', $pessoa->id, '2026-05');
        Cache::put($cacheKey, ['fake' => true], now()->addDays(7));
        $this->assertTrue(Cache::has($cacheKey), 'pré-condição: cache do bônus precisa existir antes da invalidação.');

        $this->actingAs($admin)
            ->patch(route('nps.responses.invalidar', $survey), [])
            ->assertRedirect();

        $this->assertFalse(Cache::has($cacheKey), 'Cache::forget deveria ter sido chamado para o user_id da atribuição.');
    }

    #[Test]
    public function test_invalidar_resposta_legada_sem_atribuicao_nao_quebra_e_nao_precisa_de_cache_forget(): void
    {
        // Pitfall 5 do RESEARCH: resposta legada (sem NpsScoreAssignment) não
        // tem cache de bônus a bustar — isso é CORRETO, não um bug. Só
        // provamos que a ação continua funcionando (200/redirect) sem erro.
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $response = $this->criarResponse($survey);

        $this->actingAs($admin)
            ->patch(route('nps.responses.invalidar', $survey), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $response->refresh();
        $this->assertNotNull($response->invalidated_at);
    }

    #[Test]
    public function test_status_do_survey_permanece_completed_apos_invalidar(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $this->criarResponse($survey);

        $this->actingAs($admin)
            ->patch(route('nps.responses.invalidar', $survey), [])
            ->assertRedirect();

        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);
    }

    #[Test]
    public function test_cards_e_serie_nao_contam_invalidada_mas_listagem_paginada_preserva(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);

        $surveyValido = $this->criarSurveyCompleto($company);
        $this->criarResponse($surveyValido, [
            'score_estrategista' => 5, 'score_analista' => 5, 'score_empresa' => 5,
        ]);

        $surveyInvalidado = $this->criarSurveyCompleto($company);
        $respostaInvalidada = $this->criarResponse($surveyInvalidado, [
            'score_estrategista' => 1, 'score_analista' => 1, 'score_empresa' => 1,
        ]);
        $respostaInvalidada->update(['invalidated_at' => now(), 'invalidated_by' => $admin->id]);

        $props = $this->propsDoIndex($admin);

        // Cards: só a resposta válida (nota 5) entra na média do mês corrente.
        // Se a invalidada ainda contasse, a média cairia para 3.
        // assertEquals (não assertSame) — json_encode(5.0) vira inteiro 5 sem
        // JSON_PRESERVE_ZERO_FRACTION, então o valor decodificado do payload
        // Inertia chega como int quando a média é um número redondo.
        $this->assertEquals(5.0, $props['cards']['estrategista']['media']);
        $this->assertSame(1, $props['cards']['estrategista']['total']);

        // Série 12m: o mês corrente (último item) também não pode contar a
        // invalidada — mesmo raciocínio dos cards.
        $serieMesCorrente = collect($props['serie_12m'])->last();
        $this->assertEquals(5.0, $serieMesCorrente['estrategista']);

        // Listagem paginada preserva as DUAS — admin precisa gerir/revalidar.
        $tokens = collect($props['surveys']['data'])->pluck('token');
        $this->assertTrue($tokens->contains($surveyValido->token));
        $this->assertTrue($tokens->contains($surveyInvalidado->token));
    }

    #[Test]
    public function test_admin_revalida_restaura_flag_activity_e_cache_forget(): void
    {
        $admin   = $this->admin();
        $pessoa  = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company, ['completed_at' => Carbon::parse('2026-06-15 10:00:00')]);
        $response = $this->criarResponse($survey, [
            'invalidated_at' => now(),
            'invalidated_by' => $admin->id,
        ]);
        $this->criarSnapshot($response, $company, $pessoa);

        // Fase 105 (NPSWIN-03): idem — competência bustada é X−1 = maio/2026,
        // chave v15 (quick 260731-pvk: bump v14→v15 — ver comentário do
        // teste de invalidar acima).
        $cacheKey = sprintf('desempenho.compute.v16.%d.%s', $pessoa->id, '2026-05');
        Cache::put($cacheKey, ['fake' => true], now()->addDays(7));

        $this->actingAs($admin)
            ->patch(route('nps.responses.revalidar', $survey), [])
            ->assertRedirect();

        $response->refresh();
        $this->assertNull($response->invalidated_at);
        $this->assertNull($response->invalidated_by);
        $this->assertFalse(Cache::has($cacheKey));

        $activity = $this->ultimaActivity($response);
        $this->assertNotNull($activity);
        $this->assertSame('Resposta NPS revalidada', $activity->description);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    #[Test]
    public function test_nao_admin_recebe_403_ao_invalidar_ou_revalidar(): void
    {
        $naoAdmin = $this->naoAdmin();
        $company  = Company::factory()->create(['active' => true]);
        $survey   = $this->criarSurveyCompleto($company);
        $this->criarResponse($survey);

        $this->actingAs($naoAdmin)
            ->patch(route('nps.responses.invalidar', $survey), [])
            ->assertForbidden();

        $this->actingAs($naoAdmin)
            ->patch(route('nps.responses.revalidar', $survey), [])
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 3 — `invalidada` no payload da listagem (admin-only), consumido
    // pelo botão Invalidar/Revalidar do modal em Nps/Index.jsx
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_item_da_listagem_traz_invalidada_admin_only_e_reflete_o_estado(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $response = $this->criarResponse($survey);

        $item = collect($this->propsDoIndex($admin)['surveys']['data'])->firstWhere('token', $survey->token);
        $this->assertArrayHasKey('invalidada', $item);
        $this->assertFalse($item['invalidada']);

        $response->update(['invalidated_at' => now(), 'invalidated_by' => $admin->id]);

        $item = collect($this->propsDoIndex($admin)['surveys']['data'])->firstWhere('token', $survey->token);
        $this->assertTrue($item['invalidada']);

        // Blindagem: não-admin nunca recebe a chave (mesmo padrão de
        // confianca/auditoria — a chave simplesmente não é criada).
        $naoAdmin = $this->naoAdmin();
        $this->inserirPivot($company->id, $naoAdmin->id, 'consultor', null);
        $itemNaoAdmin = collect($this->propsDoIndex($naoAdmin)['surveys']['data'])->firstWhere('token', $survey->token);
        $this->assertNotNull($itemNaoAdmin, 'O survey deveria aparecer na carteira do não-admin.');
        $this->assertArrayNotHasKey('invalidada', $itemNaoAdmin);
    }
}
