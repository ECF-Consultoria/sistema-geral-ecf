<?php

namespace Tests\Feature\Phase116;

use App\Http\Controllers\DashboardController;
use App\Jobs\CalculateGoalResults;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsImputationService;
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
 * Fase 116 Plan 05 (RED) — os call-sites RESTANTES que agregam média de NPS
 * por EMPRESA passam a contar o NPS efetivamente disparado e não respondido
 * como nota 1: dashboard admin, ranking "Desempenho da equipe", dashboard do
 * usuário, página da empresa (só a MÉDIA — a lista "NPS Respondidos" não
 * muda) e a meta de NPS (`CalculateGoalResults::computeNps`).
 *
 * Molde de fixture herdado de `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`
 * (mesma trait `CriaCenarioResponsaveis`, mesmo padrão de invocação por
 * reflection dos métodos privados `buildRanking`/`userDashboard`/`computeNps`,
 * e leitura de props Inertia reais via `GET /dashboard` e `GET /companies/{id}`).
 * As linhas imputadas SEMPRE nascem via `NpsImputationService::materializarLote()`
 * — nunca criadas na mão.
 *
 * @see .planning/phases/116-.../116-05-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 */
class NpsFloorDashboardsTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (espelham NpsInvalidacaoCallSitesTest / NpsFloorAreaNpsTest)
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function imputationService(): NpsImputationService
    {
        return app(NpsImputationService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 116-05 ' . uniqid(),
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

    /** Responde o survey pelo FLUXO REAL (`POST /nps/{token}`) — completed_at = now(). */
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
            'respondent_name' => 'Cliente 116-05',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /** Survey `completed` LEGADO (template_id null) — molde de NpsInvalidacaoRespostaTest. */
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

    /** Resposta legada direto via colunas score_* — não passa pelo NpsSnapshotService. */
    private function criarResponseLegado(NpsSurvey $survey, array $overrides = []): NpsResponse
    {
        return NpsResponse::create(array_merge([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente Teste 116-05',
            'comment'         => null,
        ], $overrides));
    }

    /** Survey NÃO respondido — candidato a imputação. */
    private function criarSurveyNaoRespondido(Company $empresa, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => now()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ], $overrides));
    }

    /** Extrai o array de `props` de um `Inertia\Response` sem passar pelo middleware HTTP. */
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

    private function invocarComputeNpsDaMeta(int $companyId, int $year, int $month): ?float
    {
        $job    = new CalculateGoalResults(period: sprintf('%04d-%02d', $year, $month));
        $metodo = new ReflectionMethod($job, 'computeNps');
        $metodo->setAccessible(true);

        return $metodo->invoke($job, $companyId, $year, $month);
    }

    private function propsDoDashboardAdmin(User $admin): array
    {
        $props = null;
        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$props) {
                $page->component('Dashboard/Admin');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function propsDaEmpresa(User $user, Company $empresa): array
    {
        $props = null;
        $this->actingAs($user)
            ->get("/companies/{$empresa->id}")
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$props) {
                $page->component('Companies/Show');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — Dashboard admin (widget stats.avg_nps): 5 real + 1 não respondido = 3.0
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dashboard_admin_conta_nao_respondido_como_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        // Widget admin só olha o modelo PRINCIPAL — template precisa ser is_default=true.
        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf],
            principal: true,
        );

        $this->responder($empresa, $template, 5);

        $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
        ]);
        $this->imputationService()->materializarLote(Carbon::now());

        $props = $this->propsDoDashboardAdmin($admin);

        $this->assertEquals(3.0, $props['stats']['avg_nps'],
            'dashboard admin deve contar o survey não respondido como nota 1 (média (5+1)/2 = 3.0)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — Ranking "Desempenho da equipe" (buildRanking): pessoa só com
    //     não respondido tem avg_nps 1.0 (hoje vem null)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_ranking_desempenho_equipe_conta_nao_respondido_como_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        // Ranking não filtra por modelo — não precisa ser principal. A
        // imputação de dimensão analista/estrategista exige serviço coberto
        // com contrato ATIVO (mesma régua de responsavelDoServicoOuConsolidado).
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);

        $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
        ]);
        $this->imputationService()->materializarLote(Carbon::now());

        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);

        $this->assertNotNull($linha, 'analista precisa aparecer no ranking construído por buildRanking()');
        $this->assertSame(1.0, $linha['avg_nps'],
            'call-site do ranking: buildRanking() deve contar o não respondido como 1 (hoje vem null/0)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — Dashboard do usuário (stats.avg_nps): mesma regra
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dashboard_usuario_conta_nao_respondido_como_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        // Widget do dashboard do usuário só olha o modelo PRINCIPAL (mesma
        // restrição do widget admin) — template precisa ser is_default=true.
        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );

        $this->responder($empresa, $template, 5);
        $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
        ]);
        $this->imputationService()->materializarLote(Carbon::now());

        $props = $this->invocarUserDashboard($analista, now()->subDays(30));

        $this->assertEquals(3.0, $props['stats']['avg_nps'],
            'dashboard do usuário deve contar o não respondido como nota 1 (média (5+1)/2 = 3.0)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — Página da empresa: avgNps conta o não respondido, lista "NPS
    //     Respondidos" continua só com respostas reais
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_pagina_da_empresa_nao_suja_lista_de_respondidos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        $surveyRespondido = $this->criarSurveyCompleto($empresa, ['completed_at' => now()]);
        $this->criarResponseLegado($surveyRespondido, ['score_empresa' => 5]);

        $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
        ]);
        $this->imputationService()->materializarLote(Carbon::now());

        $props = $this->propsDaEmpresa($admin, $empresa);

        $this->assertEquals(3.0, $props['company']['nps_avg'],
            'avgNps da página da empresa deve contar o não respondido como nota 1');
        $this->assertCount(1, $props['company']['nps_surveys'],
            'a lista "NPS Respondidos" deve continuar mostrando SÓ a resposta real (o não respondido não é completed)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — Meta de NPS (CalculateGoalResults::computeNps)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_meta_de_nps_conta_nao_respondido_como_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], [], principal: true);

        $this->responder($empresa, $template, 5);
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 7);

        $this->assertEquals(3.0, $media,
            'CalculateGoalResults::computeNps() deve contar o não respondido como nota 1 (média (5+1)/2 = 3.0)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — Invariante D3, empresa NÃO elegível: SEM NENHUM survey no período
    //     mantém o comportamento ATUAL em todos os call-sites (nunca vira 1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_nao_elegivel_sem_survey_no_periodo_mantem_comportamento_atual_em_todos_call_sites(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin    = $this->admin();
        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);
        // SEM ESTRATEGISTA atribuído — empresa NÃO é elegível (NPSMAN-07),
        // o contrapeso de D1/Fase 119.1.

        // Nenhum survey criado — nem respondido, nem não respondido.

        // 1) Dashboard admin — sentinela 0 preservada.
        $propsAdmin = $this->propsDoDashboardAdmin($admin);
        $this->assertEquals(0, $propsAdmin['stats']['avg_nps']);

        // 2) Ranking — sentinela null preservada.
        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);
        $this->assertNull($linha['avg_nps']);

        // 3) Dashboard do usuário — sentinela 0.0 preservada.
        $propsUser = $this->invocarUserDashboard($analista, now()->subDays(30));
        $this->assertEquals(0, $propsUser['stats']['avg_nps']);

        // 4) Página da empresa — sem dado nenhum, nps_avg null e lista vazia.
        $propsCompany = $this->propsDaEmpresa($admin, $empresa);
        $this->assertNull($propsCompany['company']['nps_avg']);
        $this->assertCount(0, $propsCompany['company']['nps_surveys']);

        // 5) Meta NPS — continua retornando null (sem dado, nunca vira 1).
        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 7);
        $this->assertNull($media);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — Empresa ELEGÍVEL (D1/Fase 119.1) sem survey: estes 5 call-sites
    //     AGORA CONTAM NOTA 1 — plano 119.1-09 fechou o gap, 2026-07-29
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_elegivel_sem_survey_agora_conta_nota_1_em_todos_os_call_sites(): void
    {
        // Fase 119.1 Plan 03/04 inverteu D3 DE PROPÓSITO para o BÔNUS
        // (`DesempenhoScoreService::computeNpsMedio()`) e para a ÁREA NPS
        // (`NpsController::index()`) — mas deixou estes 5 call-sites
        // (dashboard admin, ranking "Desempenho da equipe", dashboard do
        // usuário, página da empresa, meta de NPS) como GAP CONHECIDO. O
        // usuário foi consultado em 2026-07-29 e decidiu fechar os 4
        // consumidores restantes (a meta é um dos 4; o quinto — carteira —
        // foi coberto em `NpsD1CarteiraTest`) — Plano 119.1-09 é essa
        // entrega. Estas asserções foram INVERTIDAS (nunca apagadas): a
        // mesma empresa GENUINAMENTE elegível que antes mantinha a
        // sentinela D3 agora conta nota 1, mesma régua do bônus.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin        = $this->admin();
        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $estrategista = User::factory()->create();

        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        // Template PRINCIPAL (dashboards admin/usuário só olham o modelo
        // principal) + envio_automatico_mensal=true — empresa ELEGÍVEL.
        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf],
            principal: true,
        );
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        // Nenhum survey criado — a empresa é ELEGÍVEL e, com "hoje" em
        // 15/07/2026, o mês anterior (junho) já fechou e está dentro do
        // piso retroativo (DEC-09-B) — D1 conta nota 1 para ele em todos os
        // call-sites que leem janela ROLANTE.

        // 1) Dashboard admin — Plano 119.1-09 fechou o gap.
        $propsAdmin = $this->propsDoDashboardAdmin($admin);
        $this->assertEquals(1.0, $propsAdmin['stats']['avg_nps'],
            'Plano 119.1-09 (2026-07-29): dashboard admin agora consome D1.');

        // 2) Ranking — Plano 119.1-09 fechou o gap.
        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);
        $this->assertSame(1.0, $linha['avg_nps'],
            'Plano 119.1-09 (2026-07-29): ranking "Desempenho da equipe" agora consome D1.');

        // 3) Dashboard do usuário — Plano 119.1-09 fechou o gap.
        $propsUser = $this->invocarUserDashboard($analista, now()->subDays(30));
        $this->assertEquals(1.0, $propsUser['stats']['avg_nps'],
            'Plano 119.1-09 (2026-07-29): dashboard do usuário agora consome D1.');

        // 4) Página da empresa — Plano 119.1-09 fechou o gap; a LISTA "NPS
        //    Respondidos" permanece vazia (só respostas reais).
        $propsCompany = $this->propsDaEmpresa($admin, $empresa);
        $this->assertEquals(1.0, $propsCompany['company']['nps_avg'],
            'Plano 119.1-09 (2026-07-29): página da empresa agora consome D1.');
        $this->assertCount(0, $propsCompany['company']['nps_surveys'],
            'A lista de respostas reais continua vazia — D1 nunca aparece nela.');

        // 5) Meta NPS para JULHO (mês corrente, coleta AINDA ABERTA) —
        //    continua legitimamente null. Isto NÃO é o gap: é a regra de
        //    janela aberta (`NpsJanelaResolver::fechada()`), preservada tal
        //    como estava.
        $mediaMesAberto = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 7);
        $this->assertNull($mediaMesAberto,
            'Julho ainda está com a coleta aberta — a meta continua null (regra de janela, não o gap).');

        // 5b) Meta NPS para JUNHO (mês FECHADO, dentro do piso) — Plano
        //     119.1-09 fechou o gap. Sem esta asserção nova, o arquivo
        //     provaria a meta sem nunca exercitá-la de fato fechada.
        $mediaMesFechado = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 6);
        $this->assertEquals(1.0, $mediaMesFechado,
            'Plano 119.1-09 (2026-07-29): meta de NPS para o mês FECHADO agora consome D1.');
    }
}
