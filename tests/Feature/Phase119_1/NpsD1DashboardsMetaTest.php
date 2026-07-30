<?php

namespace Tests\Feature\Phase119_1;

use App\Http\Controllers\DashboardController;
use App\Jobs\CalculateGoalResults;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 09 (Task 3) — D1 na régua por EMPRESA: dashboard admin,
 * ranking "Desempenho da equipe", dashboard do usuário, página da empresa
 * e meta de NPS passam a contar nota 1 para empresa ELEGÍVEL sem NENHUM
 * link no mês fechado, mesma régua do bônus, com contrapeso de não
 * elegível/invalidada/janela aberta/carteira alheia provado, e a meta
 * comprovando que competência FIXA não depende da hora do recompute
 * (DEC-09-C).
 *
 * Molde de invocação de `NpsFloorDashboardsTest` — reflection nos métodos
 * privados `buildRanking()`/`userDashboard()`/`computeNps()`, props Inertia
 * reais via HTTP para dashboard admin e página da empresa.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Http/Controllers/DashboardController.php
 * @see app/Http/Controllers/CompanyController.php
 * @see app/Jobs/CalculateGoalResults.php
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-09-PLAN.md
 */
class NpsD1DashboardsMetaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures e invocadores (molde de NpsFloorDashboardsTest)
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function criarAnalista(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 119.1-09 T3 ' . uniqid(),
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

    /**
     * Monta empresa ativa + serviço/contrato ativo + estrategista/analista
     * + modelo PRINCIPAL aplicável (active + envio_automatico_mensal),
     * cobrindo as 3 dimensões (estrategista/analista/empresa).
     *
     * @return array{empresa: Company, servico: int, template: NpsTemplate, estrategista: User}
     */
    private function criarCenarioElegivel(User $analista, array $atributosEmpresa = []): array
    {
        $empresa      = Company::factory()->create(array_merge(['active' => true], $atributosEmpresa));
        $servico      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $this->criarContrato($empresa->id, $servico, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servico],
            principal: true,
        );
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        return ['empresa' => $empresa, 'servico' => $servico, 'template' => $template, 'estrategista' => $estrategista];
    }

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
            ->assertInertia(function (Assert $page) use (&$props) {
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
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Companies/Show');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — Dashboard admin
    // ═══════════════════════════════════════════════════════════════════

    public function test_dashboard_admin_empresa_elegivel_sem_link_conta_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin    = $this->admin();
        $analista = $this->criarAnalista();
        $this->criarCenarioElegivel($analista);

        $props = $this->propsDoDashboardAdmin($admin);

        $this->assertEquals(1.0, $props['stats']['avg_nps'],
            'stats.avg_nps passa de 0 para 1.0 quando a única empresa é elegível e ficou sem link no mês anterior fechado.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2/3 — Ranking "Desempenho da equipe"
    // ═══════════════════════════════════════════════════════════════════

    public function test_ranking_pessoa_cuja_unica_empresa_e_elegivel_sem_link_conta_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista = $this->criarAnalista();
        $this->criarCenarioElegivel($analista);

        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);

        $this->assertSame(1.0, $linha['avg_nps'], 'avg_nps passa de null para 1.0 (D1).');
    }

    public function test_ranking_pessoa_cuja_unica_empresa_nao_e_elegivel_continua_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);
        // SEM estrategista — empresa NÃO elegível (NPSMAN-07).

        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);

        $this->assertNull($linha['avg_nps'], 'Contrapeso NPSMAN-07: empresa NÃO elegível continua sem contar nota.');
    }

    public function test_ranking_com_2_pessoas_de_carteiras_distintas_nao_mistura_media(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analistaComEmpresa = $this->criarAnalista();
        $analistaSemEmpresa = $this->criarAnalista();
        $this->criarCenarioElegivel($analistaComEmpresa);
        // $analistaSemEmpresa não tem NENHUMA empresa na carteira.

        $ranking = $this->invocarBuildRanking(collect([$analistaComEmpresa, $analistaSemEmpresa]), now()->subDays(30));

        $linhaCom = $ranking->firstWhere('id', $analistaComEmpresa->id);
        $linhaSem = $ranking->firstWhere('id', $analistaSemEmpresa->id);

        $this->assertSame(1.0, $linhaCom['avg_nps'], 'Analista com a empresa elegível sem link conta 1.');
        $this->assertNull($linhaSem['avg_nps'], 'A empresa de UM analista não puxa a média do outro, que não tem carteira nenhuma.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — Dashboard do usuário
    // ═══════════════════════════════════════════════════════════════════

    public function test_dashboard_usuario_empresa_elegivel_sem_link_conta_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista = $this->criarAnalista();
        $this->criarCenarioElegivel($analista);

        $props = $this->invocarUserDashboard($analista, now()->subDays(30));

        $this->assertEquals(1.0, $props['stats']['avg_nps'], 'Mesma inversão do ranking em stats.avg_nps.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5/6 — Página da empresa
    // ═══════════════════════════════════════════════════════════════════

    public function test_pagina_da_empresa_elegivel_sem_link_conta_1_sem_sujar_lista_de_respondidos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin    = $this->admin();
        $analista = $this->criarAnalista();
        ['empresa' => $empresa] = $this->criarCenarioElegivel($analista);

        $props = $this->propsDaEmpresa($admin, $empresa);

        $this->assertEquals(1.0, $props['company']['nps_avg'], 'nps_avg passa de null para 1.0.');
        $this->assertEquals(1, $props['company']['nps_nao_respondidos'], 'nps_nao_respondidos reflete a linha D1.');
        $this->assertCount(0, $props['company']['nps_surveys'], 'A lista "NPS Respondidos" continua vazia — só respostas reais.');
    }

    public function test_pagina_da_empresa_mes_fora_do_piso_nao_e_recontado(): void
    {
        // "Hoje" = 15/07/2026 ⇒ piso = junho. A janela de nps_avg é
        // 1970→now (irrestrita), mas o piso corta tudo antes de junho —
        // mesmo a empresa sendo (hoje) elegível "para sempre" no passado,
        // só 1 mês (junho) pode contar D1, nunca o histórico inteiro.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin    = $this->admin();
        $analista = $this->criarAnalista();
        ['empresa' => $empresa] = $this->criarCenarioElegivel($analista);

        $props = $this->propsDaEmpresa($admin, $empresa);

        $this->assertEquals(1, $props['company']['nps_nao_respondidos'],
            'Piso retroativo (DEC-09-B): só o mês anterior fechado conta — nunca o histórico inteiro fabricado por uma leitura.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7/8/9/10 — Meta de NPS
    // ═══════════════════════════════════════════════════════════════════

    public function test_meta_de_nps_mes_fechado_empresa_elegivel_sem_link_devolve_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista = $this->criarAnalista();
        ['empresa' => $empresa] = $this->criarCenarioElegivel($analista);

        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 6);

        $this->assertEquals(1.0, $media, 'Mês FECHADO (junho) com empresa elegível sem link devolve 1.0 (hoje devolve null).');
    }

    public function test_meta_de_nps_mes_corrente_aberto_continua_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista = $this->criarAnalista();
        ['empresa' => $empresa] = $this->criarCenarioElegivel($analista);

        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 7);

        $this->assertNull($media, 'Julho ainda está com a coleta aberta — meta continua null.');
    }

    public function test_meta_de_nps_empresa_nao_elegivel_mes_fechado_continua_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);
        // SEM estrategista — empresa NÃO elegível (NPSMAN-07).

        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 6);

        $this->assertNull($media, 'Contrapeso NPSMAN-07: empresa NÃO elegível continua sem contar, mesmo em mês fechado.');
    }

    public function test_meta_de_nps_mesma_competencia_fechada_em_dois_agoras_diferentes_devolve_o_mesmo_numero(): void
    {
        // DEC-09-C — competência FIXA (aqui, junho/2026) não pode depender
        // da hora em que o recompute roda. `computeNps()` NUNCA recebe piso.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $analista = $this->criarAnalista();
        ['empresa' => $empresa] = $this->criarCenarioElegivel($analista);

        $primeiroAgora = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 6);

        Carbon::setTestNow(Carbon::parse('2026-09-20 14:00:00'));
        $segundoAgora = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 6);

        $this->assertEquals(1.0, $primeiroAgora);
        $this->assertEquals($primeiroAgora, $segundoAgora,
            'Mesma competência FECHADA (junho) recalculada em dois "agoras" diferentes devolve o MESMO número.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 11 — Empresa invalidada na competência não conta, nos 5 sites
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_invalidada_na_competencia_nao_conta_em_nenhum_dos_5_sites(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin    = $this->admin();
        $analista = $this->criarAnalista();
        ['empresa' => $empresa] = $this->criarCenarioElegivel($analista);

        // Mês de coleta = junho (mês anterior); competência FINANCEIRA
        // correspondente (M) = maio.
        BonusInvalidacao::create([
            'company_id'     => $empresa->id,
            'competencia'    => Carbon::create(2026, 5, 1),
            'motivo'         => 'teste',
            'invalidated_by' => null,
        ]);

        // 1) Dashboard admin.
        $propsAdmin = $this->propsDoDashboardAdmin($admin);
        $this->assertEquals(0, $propsAdmin['stats']['avg_nps'], 'Empresa invalidada não conta no dashboard admin.');

        // 2) Ranking.
        $ranking = $this->invocarBuildRanking(collect([$analista]), now()->subDays(30));
        $linha   = $ranking->firstWhere('id', $analista->id);
        $this->assertNull($linha['avg_nps'], 'Empresa invalidada não conta no ranking.');

        // 3) Dashboard do usuário.
        $propsUser = $this->invocarUserDashboard($analista, now()->subDays(30));
        $this->assertEquals(0, $propsUser['stats']['avg_nps'], 'Empresa invalidada não conta no dashboard do usuário.');

        // 4) Página da empresa.
        $propsEmpresa = $this->propsDaEmpresa($admin, $empresa);
        $this->assertNull($propsEmpresa['company']['nps_avg'], 'Empresa invalidada não conta na página da empresa.');

        // 5) Meta de NPS.
        $media = $this->invocarComputeNpsDaMeta($empresa->id, 2026, 6);
        $this->assertNull($media, 'Empresa invalidada não conta na meta de NPS.');
    }
}
