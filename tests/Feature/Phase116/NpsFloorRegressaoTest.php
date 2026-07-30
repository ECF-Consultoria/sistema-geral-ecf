<?php

namespace Tests\Feature\Phase116;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PerformanceController;
use App\Jobs\CalculateGoalResults;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plan 08 (FECHAMENTO) — checklist executável de COERÊNCIA ENTRE
 * TODOS OS CALL-SITES da regra "NPS não respondido conta como nota mínima
 * (1)". Molde de `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`
 * (Pitfall 4 da Fase 96): 1 cenário-base montado UMA vez e verificado em
 * cada consumidor — se algum call-site futuro esquecer a regra (ou
 * reimplementar a resolução por conta própria e divergir), este arquivo
 * fica vermelho.
 *
 * Cenário-base (`montarCenarioBase()`): 1 empresa, 1 responsável de cada
 * papel (analista=consultor, estrategista=estrategista), 1 resposta REAL
 * nota 5 (fluxo real `POST /nps/{token}`) e 1 survey NÃO respondido no
 * MESMO mês (materializado via `NpsImputationService::materializarLote()`,
 * nunca criado na mão). Consumidores verificados:
 *
 *  1. Card da área NPS (`NpsController::index()`)
 *  2. Componente de NPS do bônus (`DesempenhoScoreService::computeNpsMedio()`)
 *  3. Coluna NPS da carteira (`PerformanceController::dashboardCarteira()`)
 *  4. Ranking "Desempenho da equipe" (`DashboardController::buildRanking()`)
 *  5. Média da página da empresa (`CompanyController::show()` → nps_avg)
 *  6. Meta de NPS (`CalculateGoalResults::computeNps()`)
 *  7. `NpsPorEmpresaService::notasNpsPorEmpresa()` (bônus por empresa, Fase
 *     118, v21.0) — ATUALIZAÇÃO (Fase 119.1 · D1, 2026-07-29): até a Fase
 *     118, este consumidor DIVERGIA deliberadamente da D3 acima no
 *     cenário-espelho (empresa da carteira sem NENHUM disparo valia 1.0
 *     aqui, enquanto os 6 consumidores acima continuavam com a sentinela de
 *     vazio — divergência APROVADA em `118-CONTEXT.md` `<risks>` opção 1).
 *     A Fase 119.1 fecha essa divergência nos DOIS sentidos: (a) para
 *     empresa GENUINAMENTE elegível a NPS, o novo 4º ramo (D) de
 *     `computeNpsMedio()` (`NpsSemLinkService`) TAMBÉM passa a valer 1.0 —
 *     bônus e D-04 CONCORDAM; (b) para empresa da carteira SEM
 *     elegibilidade real (o cenário exato de `montarCenarioVazioNaoElegivel()`
 *     abaixo — sem modelo automático aplicável ao serviço contratado), o
 *     D-04 passou a filtrar por `NpsElegibilidadeService` e NÃO vale mais
 *     1.0 sozinho — os DOIS ficam de fora, novamente CONCORDANDO. Ver
 *     `test_nps_por_empresa_do_bonus_concorda_com_d3_apos_fase_119_1_quando_nao_elegivel()`
 *     no fim desta classe.
 *
 * IMPORTANTE — duas réguas de dedupe DIFERENTES e DELIBERADAS coexistem no
 * sistema (documentadas em `docs/nps-nao-respondido-nota-1.md`): a área NPS
 * dedupe por (survey, dimensão); o bônus/carteira/ranking dedupe por
 * (survey, role) por pessoa. Isso NÃO é bug — são consumidores diferentes
 * com granularidades diferentes. Este teste NÃO exige o MESMO número em
 * TODOS os consumidores (a coluna NPS da carteira, por exemplo, mostra a
 * nota da resposta MAIS RECENTE por empresa, não uma média) — exige que
 * NENHUM consumidor IGNORE o não respondido.
 *
 * Cenário-espelho NÃO ELEGÍVEL (`montarCenarioVazioNaoElegivel()`): mesma
 * empresa/pessoas, ZERO surveys — prova que o invariante D3 (empresa sem
 * disparo nunca vira nota 1) se mantém intacto em TODOS os consumidores, de
 * ponta a ponta, para quem NÃO é elegível a NPS.
 *
 * Cenário-espelho ELEGÍVEL (`montarCenarioVazioElegivel()`, Fase 119.1 ·
 * D1): MESMA empresa/pessoas, ZERO surveys, mas COM modelo automático
 * aplicável — prova que D3 foi INVERTIDO de propósito nos 2 consumidores já
 * plugados (área NPS, bônus), e que os 4 restantes (carteira, ranking,
 * página da empresa, meta de NPS) ainda mantêm a sentinela antiga — GAP
 * CONHECIDO, registrado para o plano 08 fechar a coerência entre telas.
 *
 * @see .planning/phases/116-.../116-08-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 * @see tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php (molde)
 */
class NpsFloorRegressaoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (molde de NpsInvalidacaoCallSitesTest / NpsFloorAreaNpsTest)
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
            'nome'       => 'Template 116-08 ' . uniqid(),
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

    /** Responde o survey pelo FLUXO REAL (`POST /nps/{token}`) — gera as atribuições da Fase 79. */
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
            'respondent_name' => 'Cliente 116-08',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /** Survey NÃO respondido — candidato à imputação. */
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

    /**
     * Monta o cenário-base único: 1 empresa, 1 analista (role consultor) e 1
     * estrategista (role mentor — isMentor() determina a carteira/dimensão
     * de `buildRanking()` sem precisar de scaffolding extra de cargo), 1
     * resposta REAL nota 5 (fluxo real) e 1 survey não respondido no MESMO
     * mês (2026-07), materializado via `materializarLote()`.
     *
     * @return array{empresa: Company, analista: User, estrategista: User, template: NpsTemplate, respostaReal: NpsResponse}
     */
    private function montarCenarioBase(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa Coerência 116-08']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        // isMentor() (role='mentor') resolve companyIds via estrategistaCompanies()
        // em buildRanking() sem precisar de user_setores/cargos — mesmo padrão
        // já usado em NpsFloorDashboardsTest/NpsFloorCarteiraTest.
        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        $template = $this->criarTemplateEscopado(
            [
                NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                NpsTemplateQuestion::DIMENSAO_ANALISTA,
                NpsTemplateQuestion::DIMENSAO_EMPRESA,
            ],
            [$servicoPerf],
            principal: true, // meta de NPS (CalculateGoalResults) só conta o modelo principal
        );

        // Resposta REAL nota 5 — gera atribuições (Fase 79) para analista E
        // estrategista, mais o score da dimensão empresa.
        $respostaReal = $this->responder($empresa, $template, 5);

        // Survey NÃO respondido no MESMO mês — materializado como nota 1 em
        // TODAS as dimensões aplicáveis (estrategista/analista/empresa, D7).
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        return compact('empresa', 'analista', 'estrategista', 'template', 'respostaReal');
    }

    /**
     * Cenário-espelho NÃO ELEGÍVEL: mesma empresa/pessoas, ZERO surveys e
     * NENHUM modelo automático aplicável ao serviço contratado (NPSMAN-07)
     * — para provar D3 (empresa sem disparo nunca vira nota 1) em todos os
     * consumidores. Fase 119.1 (D1) inverteu D3 para empresa ELEGÍVEL (ver
     * `montarCenarioVazioElegivel()` abaixo) — este cenário prova o
     * CONTRAPESO: D3 continua intacto para quem não tinha o que enviar.
     *
     * @return array{empresa: Company, analista: User, estrategista: User}
     */
    private function montarCenarioVazioNaoElegivel(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa Sem Disparo 116-08']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        // Nenhum survey criado — nem respondido, nem não respondido. NENHUM
        // modelo NPS automático aplicável ao serviço contratado — a empresa
        // é GENUINAMENTE não elegível (NPSMAN-07), não só "sem link".

        return compact('empresa', 'analista', 'estrategista');
    }

    /**
     * Cenário-espelho ELEGÍVEL (Fase 119.1 · D1): MESMA empresa/pessoas de
     * `montarCenarioVazioNaoElegivel()`, ZERO surveys, mas COM um modelo
     * automático aplicável ao serviço contratado (`envio_automatico_mensal
     * = true`, escopado ao mesmo serviço, e is_default=true — cobre também
     * a meta de NPS/CalculateGoalResults, que só olha o modelo principal).
     * Prova a inversão deliberada de D3: empresa ELEGÍVEL sem NENHUM link
     * conta nota 1, depois que o mês fecha.
     *
     * @return array{empresa: Company, analista: User, estrategista: User, template: NpsTemplate}
     */
    private function montarCenarioVazioElegivel(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa Elegivel Sem Link 119.1-04']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        $template = $this->criarTemplateEscopado(
            [
                NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                NpsTemplateQuestion::DIMENSAO_ANALISTA,
                NpsTemplateQuestion::DIMENSAO_EMPRESA,
            ],
            [$servicoPerf],
            principal: true,
        );
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        // Nenhum survey criado — nem respondido, nem não respondido. A
        // empresa é GENUINAMENTE elegível (contrato ativo + modelo
        // automático aplicável + estrategista) — D1/NPSMAN-06.

        return compact('empresa', 'analista', 'estrategista', 'template');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Invocadores (reflection em métodos privados — mesmo molde de
    // NpsInvalidacaoCallSitesTest / NpsFloorDashboardsTest)
    // ═══════════════════════════════════════════════════════════════════

    private function invocarComputeNpsMedio(User $user, Carbon $mes): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes);
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

    private function propsDoIndex(User $user, array $query = []): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('nps.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function propsDashboardCarteira(User $user): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Performance/Dashboard');
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
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Companies/Show');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — Card da área NPS (NpsController::index) — dedupe por (survey, dimensão)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_area_nps_card_reflete_cenario_base(): void
    {
        $cenario = $this->montarCenarioBase();
        $admin   = $this->admin();

        $props = $this->propsDoIndex($admin, ['mes' => '2026-07']);

        // (5 real + 1 imputada) / 2 = 3.0 nas 3 dimensões (D7 inclui empresa).
        $this->assertEquals(3.0, $props['cards']['estrategista']['media']);
        $this->assertEquals(3.0, $props['cards']['analista']['media']);
        $this->assertEquals(3.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['cards']['empresa']['nao_respondidos'],
            'o card precisa contabilizar explicitamente 1 não respondido — não pode ignorá-lo');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — Componente de NPS do bônus (DesempenhoScoreService::computeNpsMedio)
    //     — dedupe por (survey, role) POR PESSOA
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_bonus_reflete_cenario_base_para_analista_e_estrategista(): void
    {
        $cenario = $this->montarCenarioBase();

        $npsAnalista     = $this->invocarComputeNpsMedio($cenario['analista'], Carbon::parse('2026-07-01'));
        $npsEstrategista = $this->invocarComputeNpsMedio($cenario['estrategista'], Carbon::parse('2026-07-01'));

        // (5 real + 1 imputada) / 2 = 3.0 para CADA responsável — nenhum dos
        // dois "meio-conta" o não respondido do outro papel.
        $this->assertSame(3.0, $npsAnalista,
            'bônus do analista deve contar a nota real (5) + a nota imputada (1) = média 3.0');
        $this->assertSame(3.0, $npsEstrategista,
            'bônus do estrategista deve contar a nota real (5) + a nota imputada (1) = média 3.0');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — Coluna NPS da carteira (PerformanceController::dashboardCarteira)
    //     — MOSTRA A NOTA DA RESPOSTA MAIS RECENTE por empresa, NÃO uma
    //     média. A nota imputada usa data SINTÉTICA (fim do mês do disparo),
    //     que é mais recente que a resposta real (dia 15) — então a coluna
    //     mostra 1.0. Isso NÃO é um bug: prova que o não respondido está
    //     sendo CONSIDERADO (não ignorado, não mascarado pela nota antiga).
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_coluna_nps_carteira_reflete_cenario_base(): void
    {
        $cenario = $this->montarCenarioBase();

        $props = $this->propsDashboardCarteira($cenario['analista']);

        $linha = collect($props['empresas'])->firstWhere('id', $cenario['empresa']->id);

        $this->assertNotNull($linha, 'a empresa precisa aparecer na tabela de carteira');
        $this->assertEquals(1.0, $linha['nps'],
            'coluna NPS da carteira usa a nota MAIS RECENTE (data sintética do não respondido = fim do mês, '
            . 'mais recente que a resposta real do dia 15) — prova que o não respondido NÃO é ignorado nem '
            . 'mascarado pela resposta real mais antiga');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — Ranking "Desempenho da equipe" (DashboardController::buildRanking)
    //     — média via avgNotaDimensao (mesma régua de dedupe do bônus)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_ranking_dashboard_reflete_cenario_base_para_analista_e_estrategista(): void
    {
        $cenario = $this->montarCenarioBase();

        $ranking = $this->invocarBuildRanking(collect([$cenario['analista'], $cenario['estrategista']]), now()->subDays(30));

        $linhaAnalista     = $ranking->firstWhere('id', $cenario['analista']->id);
        $linhaEstrategista = $ranking->firstWhere('id', $cenario['estrategista']->id);

        $this->assertNotNull($linhaAnalista);
        $this->assertNotNull($linhaEstrategista);
        $this->assertSame(3.0, $linhaAnalista['avg_nps'],
            'ranking do analista deve contar a nota real (5) + a imputada (1) = média 3.0');
        $this->assertSame(3.0, $linhaEstrategista['avg_nps'],
            'ranking do estrategista deve contar a nota real (5) + a imputada (1) = média 3.0');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — Média da página da empresa (CompanyController::show → nps_avg)
    //     — dimensão empresa, sem filtro de modelo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_pagina_da_empresa_reflete_cenario_base(): void
    {
        $cenario = $this->montarCenarioBase();
        $admin   = $this->admin();

        $props = $this->propsDaEmpresa($admin, $cenario['empresa']);

        $this->assertEquals(3.0, $props['company']['nps_avg'],
            'nps_avg da página da empresa deve contar a nota real (5) + a imputada (1) = média 3.0');
        $this->assertCount(1, $props['company']['nps_surveys'],
            'a lista "NPS Respondidos" continua mostrando SÓ a resposta real — o não respondido não é completed');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — Meta de NPS (CalculateGoalResults::computeNps) — só modelo principal
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_meta_de_nps_reflete_cenario_base(): void
    {
        $cenario = $this->montarCenarioBase();

        $media = $this->invocarComputeNpsDaMeta($cenario['empresa']->id, 2026, 7);

        $this->assertEquals(3.0, $media,
            'meta de NPS deve contar a nota real (5) + a imputada (1) = média 3.0 (modelo principal)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — Cenário-espelho NÃO ELEGÍVEL, SEM NENHUM survey: D3 preservado em
    //     TODOS os consumidores (nenhum "inventa" nota 1 onde não houve
    //     disparo E a empresa não tinha o que enviar — NPSMAN-07, o
    //     contrapeso de D1/Fase 119.1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cenario_espelho_nao_elegivel_sem_survey_preserva_sentinela_em_todos_consumidores(): void
    {
        $cenario = $this->montarCenarioVazioNaoElegivel();
        $admin   = $this->admin();

        // 1) Área NPS — sentinela de vazio (media=0, total=0).
        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(0, $propsNps['cards']['estrategista']['media']);
        $this->assertEquals(0, $propsNps['cards']['analista']['media']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['media']);

        // 2) Bônus — sentinela 0.0 (DESEMP-03, "sem resposta força nps=0").
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($cenario['analista'], Carbon::parse('2026-07-01')));
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($cenario['estrategista'], Carbon::parse('2026-07-01')));

        // 3) Carteira — coluna NPS null (sem disparo, sem resposta).
        $propsCarteira = $this->propsDashboardCarteira($cenario['analista']);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $cenario['empresa']->id);
        $this->assertNotNull($linhaCarteira);
        $this->assertNull($linhaCarteira['nps']);

        // 4) Ranking — sentinela null preservada.
        $ranking       = $this->invocarBuildRanking(collect([$cenario['analista']]), now()->subDays(30));
        $linhaRanking  = $ranking->firstWhere('id', $cenario['analista']->id);
        $this->assertNull($linhaRanking['avg_nps']);

        // 5) Página da empresa — nps_avg null, lista de respondidos vazia.
        $propsEmpresa = $this->propsDaEmpresa($admin, $cenario['empresa']);
        $this->assertNull($propsEmpresa['company']['nps_avg']);
        $this->assertCount(0, $propsEmpresa['company']['nps_surveys']);

        // 6) Meta de NPS — continua null (nunca vira 1 sem dado nenhum).
        $media = $this->invocarComputeNpsDaMeta($cenario['empresa']->id, 2026, 7);
        $this->assertNull($media);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — Fase 119.1 (D1) FECHOU a divergência do Plano 118-02/NPSE-06:
    //     NpsPorEmpresaService (bônus por empresa) agora CONCORDA com os 6
    //     consumidores acima quando a empresa NÃO É elegível a NPS.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_por_empresa_do_bonus_concorda_com_d3_apos_fase_119_1_quando_nao_elegivel(): void
    {
        // ATUALIZAÇÃO (Fase 119.1 · D1, 2026-07-29): até a Fase 118, este
        // era o 7º consumidor — o ÚNICO que divergia da sentinela de vazio,
        // por decisão aprovada em `118-CONTEXT.md` <risks> (opção 1). A
        // Fase 119.1 fecha essa divergência: `NpsPorEmpresaService::
        // notasNpsPorEmpresa()` (D-04) passou a filtrar por
        // `NpsElegibilidadeService` — a MESMA régua que o novo 4º ramo (D)
        // de `computeNpsMedio()` usa (`NpsSemLinkService`). O cenário deste
        // teste (`montarCenarioVazioNaoElegivel()`) tem estrategista + analista, mas
        // NENHUM modelo NPS automático aplicável ao serviço contratado —
        // portanto é uma empresa GENUINAMENTE NÃO ELEGÍVEL (NPSMAN-07), e
        // os dois caminhos (bônus e D-04) agora concordam em "nada" para
        // ela, em vez de divergir.
        $cenario = $this->montarCenarioVazioNaoElegivel();
        $service = app(\App\Services\Desempenho\NpsPorEmpresaService::class);

        // 1) Janela ainda em COLETA (2026-07-15, a data que `montarCenarioVazioNaoElegivel()`
        //    já fixa): a empresa é EXCLUÍDA (nota null) — janela vence sobre
        //    elegibilidade (ordem fixa), exatamente como nos 6 consumidores
        //    acima.
        $notasAberta = $service->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linhaAberta = $notasAberta->get($cenario['empresa']->id);
        $this->assertNull($linhaAberta->nota);
        $this->assertSame('janela_aberta', $linhaAberta->origem);

        // 2) Janela ENCERRADA, MESMO cenário — a empresa NÃO é elegível
        //    (sem modelo automático aplicável), então o D-04 NÃO aplica o
        //    piso 1.0: `origem = 'nao_elegivel'`, `nota = null`, para os
        //    dois papéis (NPSMAN-07). Este é o comportamento NOVO — antes
        //    da Fase 119.1, valia 1.0 aqui (a divergência aprovada).
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));

        $notasAnalista = $service->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linhaAnalista = $notasAnalista->get($cenario['empresa']->id);
        $this->assertNull($linhaAnalista->nota, 'Fase 119.1 — empresa não elegível fica de fora do D-04 também.');
        $this->assertSame('nao_elegivel', $linhaAnalista->origem);

        $notasEstrategista = $service->notasNpsPorEmpresa($cenario['estrategista'], Carbon::parse('2026-06-01'), true, collect());
        $linhaEstrategista = $notasEstrategista->get($cenario['empresa']->id);
        $this->assertNull($linhaEstrategista->nota);
        $this->assertSame('nao_elegivel', $linhaEstrategista->origem);

        // 3) O OUTRO LADO, lado a lado, na MESMA janela de julho: a
        //    sentinela 0.0 da Fase 116 (DESEMP-03) continua valendo para os
        //    DOIS papéis — o novo ramo (D) de `computeNpsMedio()` também
        //    NÃO se aplica aqui, porque esta empresa não é elegível. Os
        //    dois caminhos concordam em "nada" — é a coerência que a Fase
        //    119.1 (T-119.1-14) existe para garantir. `invocarComputeNpsMedio()`
        //    recebe o mês de COLETA (julho, mesma convenção dos 7 métodos
        //    acima); `notasNpsPorEmpresa()` recebe a competência FINANCEIRA
        //    M (junho) e desloca internamente — são a MESMA janela,
        //    argumentos DIFERENTES (decisão 4 do Plano 118-02).
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($cenario['analista'], Carbon::parse('2026-07-01')));
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($cenario['estrategista'], Carbon::parse('2026-07-01')));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8 — Cenário-espelho ELEGÍVEL (Fase 119.1 · D1), SEM NENHUM survey: os
    //     6 consumidores AGORA CONCORDAM contando nota 1 — plano 119.1-09
    //     fechou os 4 que faltavam, 2026-07-29.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cenario_espelho_elegivel_sem_survey_conta_1_em_todos_os_consumidores(): void
    {
        // Até o plano 119.1-04, só 2 dos 6 consumidores (área NPS, bônus)
        // consumiam D1 — os outros 4 (carteira, ranking, página da empresa,
        // meta de NPS) mantinham a sentinela D3 antiga, GAP CONHECIDO. O
        // usuário foi consultado em 2026-07-29 e decidiu fechar os 4 — Plano
        // 119.1-09 é essa entrega. Estas asserções foram INVERTIDAS (nunca
        // apagadas): com "hoje" em 2026-08-01, julho fechou e está dentro
        // do piso retroativo (DEC-09-B: piso = mês anterior a "hoje" =
        // julho) — os 6 consumidores concordam em nota 1.
        $cenario = $this->montarCenarioVazioElegivel();
        $admin   = $this->admin();

        // Mês (julho) já fechado — condição obrigatória de D1.
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        // 1) Área NPS — CONTA nota 1 (D1, Plan 04 desta fase).
        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(1.0, $propsNps['cards']['estrategista']['media'],
            'D1 — empresa elegível sem link conta nota 1 na área NPS (estrategista).');
        $this->assertEquals(1.0, $propsNps['cards']['analista']['media'],
            'D1 — empresa elegível sem link conta nota 1 na área NPS (analista).');
        $this->assertEquals(1.0, $propsNps['cards']['empresa']['media'],
            'D1 — empresa elegível sem link conta nota 1 na área NPS (empresa, D7).');

        // 2) Bônus — CONTA nota 1 (D1, Plan 03 desta fase, ramo D).
        $this->assertSame(1.0, $this->invocarComputeNpsMedio($cenario['analista'], Carbon::parse('2026-07-01')),
            'D1 — empresa elegível sem link conta nota 1 no bônus do analista.');
        $this->assertSame(1.0, $this->invocarComputeNpsMedio($cenario['estrategista'], Carbon::parse('2026-07-01')),
            'D1 — empresa elegível sem link conta nota 1 no bônus do estrategista.');

        // 3) Carteira — Plano 119.1-09 fechou o gap: CONTA nota 1.
        $propsCarteira = $this->propsDashboardCarteira($cenario['analista']);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $cenario['empresa']->id);
        $this->assertNotNull($linhaCarteira);
        $this->assertEquals(1.0, $linhaCarteira['nps'],
            'Plano 119.1-09 (2026-07-29): dashboardCarteira agora consome D1.');

        // 4) Ranking — Plano 119.1-09 fechou o gap: CONTA nota 1.
        $ranking      = $this->invocarBuildRanking(collect([$cenario['analista']]), now()->subDays(30));
        $linhaRanking = $ranking->firstWhere('id', $cenario['analista']->id);
        $this->assertSame(1.0, $linhaRanking['avg_nps'],
            'Plano 119.1-09 (2026-07-29): ranking "Desempenho da equipe" agora consome D1.');

        // 5) Página da empresa — Plano 119.1-09 fechou o gap: CONTA nota 1;
        //    a lista "NPS Respondidos" permanece vazia (só respostas reais).
        $propsEmpresa = $this->propsDaEmpresa($admin, $cenario['empresa']);
        $this->assertEquals(1.0, $propsEmpresa['company']['nps_avg'],
            'Plano 119.1-09 (2026-07-29): página da empresa agora consome D1.');
        $this->assertCount(0, $propsEmpresa['company']['nps_surveys'],
            'A lista de respostas reais continua vazia — D1 nunca aparece nela.');

        // 6) Meta de NPS — Plano 119.1-09 fechou o gap: CONTA nota 1 (julho
        //    já fechou nesta data congelada — DIFERENTE do teste do bloco 7
        //    de NpsFloorDashboardsTest, onde julho ainda estava aberto).
        $media = $this->invocarComputeNpsDaMeta($cenario['empresa']->id, 2026, 7);
        $this->assertEquals(1.0, $media,
            'Plano 119.1-09 (2026-07-29): meta de NPS agora consome D1.');
    }
}
