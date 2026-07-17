<?php

namespace Tests\Feature\Phase96;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PortfolioController;
use App\Jobs\CalculateGoalResults;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Phase 96 Plan 04 (AB-96-3) — CHECKLIST EXECUTÁVEL dos 10 call-sites de
 * agregação (bônus/dashboards/metas/página de detalhe da empresa) que devem
 * excluir uma `NpsResponse` com `invalidated_at` preenchido.
 *
 * 1 método de teste por call-site (RESEARCH Padrão 3) — nenhum pode faltar.
 * Este é o plano MAIS CRÍTICO da fase: um call-site esquecido "meio-conta" a
 * resposta invalidada e corrompe o cálculo do bônus (financeiro) ou vaza a
 * nota suspeita numa tela visível a não-admins.
 *
 * Molde de fixture herdado de `tests/Feature/V16/BonusDualPathRegressaoTest.php`
 * (mesma trait `CriaCenarioResponsaveis`, mesmo padrão de template/survey/
 * response via fluxo real `POST /nps/{token}` para gerar atribuições da
 * Fase 79, e via factory direta para simular o ramo legado).
 *
 * @see .planning/phases/96-.../96-04-PLAN.md <interfaces> — checklist #1-#10
 * @see .planning/phases/96-.../96-RESEARCH.md Padrão 3
 */
class NpsInvalidacaoCallSitesTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Congela o "agora" — o submit real grava completed_at = now(), então
        // toda resposta cai em agosto/2026, o mês computado nos asserts.
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-96-04',
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

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (espelham BonusDualPathRegressaoTest)
    // ═══════════════════════════════════════════════════════════════════

    private function mesReferencia(): Carbon
    {
        return Carbon::parse('2026-08-01');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function criarUserComCargo(string $nome, int $cargoId): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 96-04 ' . uniqid(),
            'active'     => true,
            'is_default' => $principal,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        if ($principal) {
            NpsTemplate::resetPrincipalCache();
        }

        return $template->fresh(['questions.options']);
    }

    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    /**
     * Responde o survey pelo FLUXO REAL (`POST /nps/{token}` → `NpsSnapshotService`)
     * — gera as atribuições da Fase 79.
     */
    private function responder(Company $empresa, NpsTemplate $template, int $peso): NpsResponse
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /**
     * Cria uma resposta do jeito PRÉ-FASE 79: survey + response + answers por
     * factory direta, sem passar pelo NpsSnapshotService → ZERO atribuição.
     */
    private function criarRespostaLegado(
        Company $empresa,
        NpsTemplate $template,
        int $peso,
        ?Carbon $completedAt = null,
    ): NpsResponse {
        $completedAt ??= $this->mesReferencia()->copy()->setDay(10)->setTime(9, 0);

        $survey = NpsSurvey::factory()
            ->for($empresa)
            ->completed()
            ->create([
                'template_id'     => $template->id,
                'month_reference' => null,
                'completed_at'    => $completedAt,
            ]);

        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        foreach ($template->questions as $q) {
            $option = $q->options->firstWhere('peso', $peso);

            NpsResponseAnswer::create([
                'response_id'                => $response->id,
                'template_question_id'       => $q->id,
                'template_option_id'         => $option->id,
                'question_texto_snapshot'    => $q->texto,
                'question_dimensao_snapshot' => $q->dimensao,
                'option_label_snapshot'      => (string) $peso,
                'option_peso_snapshot'       => $peso,
            ]);
        }

        return $response;
    }

    /** Marca a resposta como invalidada (mesmo efeito de NpsController::invalidarResposta()). */
    private function invalidar(NpsResponse $response, ?User $admin = null): NpsResponse
    {
        $response->update([
            'invalidated_at' => now(),
            'invalidated_by' => ($admin ?? $this->admin())->id,
        ]);

        return $response->refresh();
    }

    private function invocarComputeNpsMedio(User $user): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $this->mesReferencia());
    }

    private function invocarNotasNpsDoUsuarioPorResposta(User $user, \Illuminate\Support\Collection $companyIds, Carbon $desde): \Illuminate\Support\Collection
    {
        $controller = app(\App\Http\Controllers\PerformanceController::class);
        $metodo     = new ReflectionMethod($controller, 'notasNpsDoUsuarioPorResposta');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, $user, $companyIds, $desde);
    }

    /**
     * Extrai o array de `props` de um `Inertia\Response` sem passar pelo
     * middleware HTTP — constrói uma Request com o header `X-Inertia` (o
     * mesmo que `Inertia\Response::toResponse()` verifica para decidir entre
     * JSON e a view Blade completa).
     */
    private function inertiaProps(\Inertia\Response $response): array
    {
        $request = HttpRequest::create('/', 'GET');
        $request->headers->set('X-Inertia', 'true');

        return $response->toResponse($request)->getData(true)['props'];
    }

    private function invocarUserDashboard(User $user, Carbon $since, string $period = '30'): array
    {
        $this->app->instance('request', HttpRequest::create('/dashboard', 'GET'));

        $controller = app(DashboardController::class);
        $metodo     = new ReflectionMethod($controller, 'userDashboard');
        $metodo->setAccessible(true);

        return $this->inertiaProps($metodo->invoke($controller, $user, $since, $period));
    }

    private function invocarBuildRanking(\Illuminate\Support\Collection $users, Carbon $since): \Illuminate\Support\Collection
    {
        $controller = app(DashboardController::class);
        $metodo     = new ReflectionMethod($controller, 'buildRanking');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, $users, $since);
    }

    private function invocarRenderPortfolio(User $user): array
    {
        $request = HttpRequest::create('/admin/users/' . $user->id . '/portfolio', 'GET');

        $controller = app(PortfolioController::class);
        $metodo     = new ReflectionMethod($controller, 'renderPortfolio');
        $metodo->setAccessible(true);

        return $this->inertiaProps($metodo->invoke($controller, $request, $user));
    }

    private function invocarComputeNpsDaMeta(int $companyId, int $year, int $month): ?float
    {
        $job    = new CalculateGoalResults(period: sprintf('%04d-%02d', $year, $month));
        $metodo = new ReflectionMethod($job, 'computeNps');
        $metodo->setAccessible(true);

        return $metodo->invoke($job, $companyId, $year, $month);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #1 — DesempenhoScoreService::notasPorAtribuicao() (JOIN)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_1_notas_por_atribuicao_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS1', $this->cargoAnalistaId);

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );

        // Válida (peso 4) + invalidada (peso 2) — as duas geram atribuição.
        $respostaValida     = $this->responder($empresa, $tpl, 4);
        $respostaInvalidada = $this->responder($empresa, $tpl, 2);
        $this->invalidar($respostaInvalidada);

        $this->assertSame(2, NpsScoreAssignment::where('user_id', $analista->id)->count(),
            'pré-condição: as duas respostas precisam ter gerado atribuição — senão o teste não mede o call-site #1');

        // Se a invalidada ainda contasse: (4.0 + 2.0) / 2 = 3.0. Só a válida: 4.0.
        $this->assertSame(4.0, $this->invocarComputeNpsMedio($analista),
            'call-site #1: notasPorAtribuicao() deve excluir a resposta invalidada do JOIN');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #2 — DesempenhoScoreService::notasLegado() (eager-load)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_2_notas_legado_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS2', $this->cargoAnalistaId);

        $empresaA = Company::factory()->create();
        $empresaB = Company::factory()->create();
        $this->inserirPivot($empresaA->id, $analista->id, 'consultor', null);
        $this->inserirPivot($empresaB->id, $analista->id, 'consultor', null);

        $tplPrincipal = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        $respostaValida     = $this->criarRespostaLegado($empresaA, $tplPrincipal, 4);
        $respostaInvalidada = $this->criarRespostaLegado($empresaB, $tplPrincipal, 2);
        $this->invalidar($respostaInvalidada);

        $this->assertSame(0, NpsScoreAssignment::count(),
            'pré-condição: cenário legado precisa ter ZERO atribuições — senão não mede o ramo legado');

        // Se a invalidada ainda contasse: (4.0 + 2.0) / 2 = 3.0. Só a válida: 4.0.
        $this->assertSame(4.0, $this->invocarComputeNpsMedio($analista),
            'call-site #2: notasLegado() deve excluir a resposta invalidada do eager-load');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #3 — PerformanceController::notasNpsDoUsuarioPorResposta() ramo A (JOIN)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_3_performance_ramo_a_atribuicoes_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS3', $this->cargoAnalistaId);

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );

        $respostaValida     = $this->responder($empresa, $tpl, 5);
        $respostaInvalidada = $this->responder($empresa, $tpl, 1);
        $this->invalidar($respostaInvalidada);

        $companyIds = collect([$empresa->id]);
        $linhas = $this->invocarNotasNpsDoUsuarioPorResposta($analista, $companyIds, $this->mesReferencia()->copy()->startOfMonth());

        $respostaIds = NpsScoreAssignment::where('user_id', $analista->id)->pluck('nps_response_id');
        $this->assertSame(2, $respostaIds->count(),
            'pré-condição: as duas respostas precisam ter gerado atribuição');

        $this->assertCount(1, $linhas,
            'call-site #3: ramo A (JOIN) deve excluir a resposta invalidada dos widgets de carteira');
        $this->assertSame(5.0, $linhas->first()['nota']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #4 — PerformanceController::notasNpsDoUsuarioPorResposta() ramo B (legado)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_4_performance_ramo_b_legado_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS4', $this->cargoAnalistaId);

        $empresa = Company::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        $tplPrincipal = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        $respostaValida     = $this->criarRespostaLegado($empresa, $tplPrincipal, 4);
        $respostaInvalidada = $this->criarRespostaLegado($empresa, $tplPrincipal, 1);
        $this->invalidar($respostaInvalidada);

        $this->assertSame(0, NpsScoreAssignment::count(),
            'pré-condição: cenário legado precisa ter ZERO atribuições');

        $companyIds = collect([$empresa->id]);
        $linhas = $this->invocarNotasNpsDoUsuarioPorResposta($analista, $companyIds, $this->mesReferencia()->copy()->startOfMonth());

        $this->assertCount(1, $linhas,
            'call-site #4: ramo B (legado) deve excluir a resposta invalidada dos widgets de carteira');
        $this->assertSame(4.0, $linhas->first()['nota']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #5 — DashboardController::adminDashboard() widgets NPS
    // (rota GET /dashboard como admin — stats.avg_nps)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_5_admin_dashboard_avg_nps_exclui_resposta_invalidada(): void
    {
        $admin       = $this->admin();
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf],
            principal: true,
        );

        $respostaValida     = $this->responder($empresa, $tpl, 5);
        $respostaInvalidada = $this->responder($empresa, $tpl, 1);
        $this->invalidar($respostaInvalidada);

        $props = null;
        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$props) {
                $page->component('Dashboard/Admin');
                $props = $page->toArray()['props'];
            });

        // Se a invalidada ainda contasse: (5.0 + 1.0) / 2 = 3.0. Só a válida: 5.0.
        $this->assertSame(5.0, $props['stats']['avg_nps'],
            'call-site #5: adminDashboard() deve excluir a resposta invalidada de stats.avg_nps');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #6 — DashboardController::userDashboard() widgets NPS
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_6_user_dashboard_avg_nps_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS6', $this->cargoAnalistaId);
        $empresa  = Company::factory()->create(['active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        $respostaValida     = $this->responder($empresa, $tpl, 5);
        $respostaInvalidada = $this->responder($empresa, $tpl, 1);
        $this->invalidar($respostaInvalidada);

        $props = $this->invocarUserDashboard($analista, now()->subDays(30));

        $this->assertSame(5.0, $props['stats']['avg_nps'],
            'call-site #6: userDashboard() deve excluir a resposta invalidada de stats.avg_nps');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #7 — DashboardController::buildRanking() — $surveys
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_7_build_ranking_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS7', $this->cargoAnalistaId);
        $empresa  = Company::factory()->create(['active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        $respostaValida     = $this->responder($empresa, $tpl, 4);
        $respostaInvalidada = $this->responder($empresa, $tpl, 1);
        $this->invalidar($respostaInvalidada);

        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);

        $this->assertNotNull($linha, 'analista precisa aparecer no ranking construído por buildRanking()');
        $this->assertSame(4.0, $linha['avg_nps'],
            'call-site #7: buildRanking() deve excluir a resposta invalidada da média avg_nps');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #8 — PortfolioController — "Histórico NPS mensal" (renderPortfolio)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_8_portfolio_historico_nps_mensal_exclui_resposta_invalidada(): void
    {
        $analista = $this->criarUserComCargo('Analista CS8', $this->cargoAnalistaId);
        $empresa  = Company::factory()->create(['active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        $respostaValida     = $this->responder($empresa, $tpl, 4);
        $respostaInvalidada = $this->responder($empresa, $tpl, 1);
        $this->invalidar($respostaInvalidada);

        $props = $this->invocarRenderPortfolio($analista);

        $mesAtual = collect($props['nps_history'])->firstWhere('month', $this->mesReferencia()->format('Y-m'));

        $this->assertNotNull($mesAtual, 'o mês corrente precisa aparecer no histórico NPS');
        $this->assertSame(1, $mesAtual['count'],
            'call-site #8: histórico NPS mensal deve contar só a resposta válida');
        $this->assertSame(4.0, $mesAtual['avg']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Call-site #9 — CalculateGoalResults::computeNps() (metas NPS mensais)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_callsite_9_calculate_goal_results_computa_nps_exclui_resposta_invalidada(): void
    {
        $empresa = Company::factory()->create();

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [],
            principal: true,
        );

        $respostaValida     = $this->responder($empresa, $tpl, 5);
        $respostaInvalidada = $this->responder($empresa, $tpl, 1);
        $this->invalidar($respostaInvalidada);

        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 8);

        $this->assertNotNull($media, 'computeNps() deveria retornar float — há 1 resposta válida no período');
        $this->assertSame(5.0, $media,
            'call-site #9: CalculateGoalResults::computeNps() deve excluir a resposta invalidada da meta NPS');
    }
}
