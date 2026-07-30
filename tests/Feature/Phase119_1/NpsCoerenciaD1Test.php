<?php

namespace Tests\Feature\Phase119_1;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 08 (FECHAMENTO) — gate de COERÊNCIA entre TODOS os
 * consumidores da regra D1 ("empresa elegível sem nenhum link no mês conta
 * nota 1"). Molde de `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`
 * e `tests/Feature/Phase116/NpsFloorRegressaoTest.php`: 1 cenário montado
 * uma vez, lido por TODOS os consumidores enumerados abaixo — se um deles
 * divergir (número OU motivo), este arquivo fica vermelho.
 *
 * IMPORTANTE (ver `.planning/.../119.1-09-SUMMARY.md`, leitura obrigatória
 * deste plano): quando o plano 08 foi ESCRITO, D1 estava plugado em só 2 dos
 * 6 consumidores conhecidos (bônus, área NPS) — o `must_haves.truths` deste
 * plano ("todos os lugares concordam") era FALSO. O plano 119.1-09 (criado
 * DEPOIS, por decisão explícita do usuário em 2026-07-29) ligou os 4 que
 * faltavam (carteira, dashboards/ranking, página da empresa, meta de NPS).
 * Com isso, D1 está em 6 de 6 — e este gate deve, portanto, PASSAR. Qualquer
 * consumidor que divergir aqui é um achado NOVO, não o gap conhecido do
 * plano 04 (já fechado pelo plano 09).
 *
 * Consumidores exercitados (mínimo do plano, mais o consumidor de carteira
 * usado por `Phase116/NpsFloorCarteiraTest`):
 *  1. `DesempenhoScoreService::computeNpsMedio()` (bônus, reflection)
 *  2. `NpsController::index()` (área NPS, rota `nps.index`, props Inertia)
 *  3. `NpsPorEmpresaService::notasNpsPorEmpresa()` (relatório por empresa)
 *  4. `CompanyScoreService::computeEmpresasScore()` (score por empresa)
 *  5. `PerformanceController::dashboardCarteira()` (carteira, rota `dashboard`)
 *
 * NUANCE (não incoerência) no cenário 3 (mês ainda aberto): a carteira
 * (consumidor 5) lê uma janela ROLANTE — sempre a nota MAIS RECENTE
 * disponível — enquanto os outros 4 leem uma COMPETÊNCIA específica. Com o
 * piso retroativo (DEC-09-B) sempre em "mês anterior a hoje", a carteira já
 * mostra 1.0 do mês anterior (fechado) mesmo com o mês de coleta atual
 * ainda aberto — não é a mesma pergunta que os outros 4 respondem, mas é a
 * MESMA regra de negócio (ver comentário no bloco 5 do cenário 3, abaixo).
 *
 * Convenção de datas fixada para os 3 cenários (a MESMA em todos os
 * consumidores, evita descasamento de janela entre eles):
 *  - Mês de COLETA do NPS (M+1) = julho/2026.
 *  - Competência FINANCEIRA (M) = junho/2026.
 *  - "Hoje" define se julho já FECHOU (`2026-08-01`) ou ainda está EM
 *    CURSO (`2026-07-15`) — únicos dois "agoras" usados neste arquivo.
 *
 * Regra de parada do plano (Task 1): este arquivo é o ÚNICO artefato desta
 * task. Nenhum arquivo de `app/` é tocado por este commit — se um
 * consumidor divergisse, o ajuste correto seria abrir um plano novo, nunca
 * "consertar" aqui dentro (ver SUMMARY para a checagem real do resultado).
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-08-PLAN.md
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-09-SUMMARY.md
 * @see tests/Feature/Phase116/NpsFloorRegressaoTest.php (molde, blocos 7/8)
 * @see tests/Feature/Phase119_1/NpsPorEmpresaElegibilidadeTest.php (gate LOCAL do plano 03 — este aqui é o AMPLO)
 */
class NpsCoerenciaD1Test extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function criarAnalista(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    private function criarEstrategista(): User
    {
        return User::factory()->create(['role' => 'mentor', 'active' => true]);
    }

    /** Molde de `NpsAreaD1Test::criarTemplateEscopado()` — 1 questão dimensão EMPRESA, opções 1..5. */
    private function criarTemplateEscopado(array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Coerência D1 ' . uniqid(),
            'active' => true,
        ]);

        $question = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta empresa ' . uniqid() . '?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $question->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    /**
     * Monta empresa + analista (sempre presente) + estrategista (opcional,
     * o motivo "sem estrategista") + contrato (ativo/inativo, o motivo "sem
     * contrato ativo em serviço coberto") + empresa ativa/inativa (o motivo
     * "empresa inativa") + modelo automático aplicável ao serviço.
     *
     * @return array{empresa: Company, servico: int, analista: User, estrategista: ?User, template: NpsTemplate}
     */
    private function criarEmpresaEPessoas(bool $comEstrategista, bool $contratoAtivo, bool $empresaAtiva, string $nome): array
    {
        $empresa = Company::factory()->create(['active' => $empresaAtiva, 'name' => $nome]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, $contratoAtivo);

        $analista = $this->criarAnalista();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);

        $estrategista = null;
        if ($comEstrategista) {
            $estrategista = $this->criarEstrategista();
            $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);
        }

        $template = $this->criarTemplateEscopado([$servico]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        return compact('empresa', 'servico', 'analista', 'estrategista', 'template');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Invocadores
    // ═══════════════════════════════════════════════════════════════════

    private function invocarComputeNpsMedio(User $user, Carbon $mesColeta, ?Collection $invalidadas = null): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mesColeta, $invalidadas);
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

    // ═══════════════════════════════════════════════════════════════════
    // CENÁRIO 1 — Empresa ELEGÍVEL sem link, mês FECHADO → todos contam 1
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_elegivel_sem_link_mes_fechado_conta_1_em_todos_os_consumidores(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        ['empresa' => $empresa, 'analista' => $analista, 'estrategista' => $estrategista]
            = $this->criarEmpresaEPessoas(true, true, true, 'Empresa Coerência Elegível');

        $admin         = $this->admin();
        $mesColeta     = Carbon::create(2026, 7, 1);
        $mesFinanceiro = Carbon::create(2026, 6, 1);

        // 1) Bônus.
        $this->assertSame(1.0, $this->invocarComputeNpsMedio($analista, $mesColeta->copy()),
            'D1 — bônus do analista conta nota 1 para empresa elegível sem link.');
        $this->assertSame(1.0, $this->invocarComputeNpsMedio($estrategista, $mesColeta->copy()),
            'D1 — bônus do estrategista conta nota 1 para empresa elegível sem link.');

        // 2) Área NPS.
        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(1.0, $propsNps['cards']['empresa']['media'],
            'D1 — card da área NPS conta nota 1.');

        // 3) Relatório por empresa.
        $notasPorEmpresa = app(NpsPorEmpresaService::class)
            ->notasNpsPorEmpresa($estrategista, $mesFinanceiro->copy(), true, collect());
        $linha = $notasPorEmpresa->get($empresa->id);
        $this->assertNotNull($linha);
        $this->assertSame(1.0, $linha->nota, 'D-04 — nota 1.0 para empresa elegível sem nenhuma nota.');
        $this->assertSame('sem_nps', $linha->origem);

        // 4) Score por empresa.
        $periodo   = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
        $resultado = app(CompanyScoreService::class)
            ->computeEmpresasScore($estrategista, $mesFinanceiro->copy(), $periodo, collect());
        $linhaScore = $resultado->get($empresa->id);
        $this->assertNotNull($linhaScore);
        $this->assertSame(1.0, $linhaScore->nps_pontos);
        $this->assertNotContains('nps_nao_elegivel', $linhaScore->quality['motivos']);
        $this->assertNotContains('nps_janela_aberta', $linhaScore->quality['motivos']);

        // 5) Carteira.
        $propsCarteira = $this->propsDashboardCarteira($analista);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $empresa->id);
        $this->assertNotNull($linhaCarteira);
        $this->assertEquals(1.0, $linhaCarteira['nps'], 'D1 — coluna NPS da carteira conta nota 1 (Plano 119.1-09).');
    }

    // ═══════════════════════════════════════════════════════════════════
    // CENÁRIO 2 — Empresa NÃO ELEGÍVEL sem link, mês FECHADO → nenhum
    //             consumidor enxerga nota. 4 sub-casos, um por motivo de
    //             NPSMAN-07 (contrapeso de D1).
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_estrategista_nunca_conta_em_nenhum_consumidor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        ['empresa' => $empresa, 'analista' => $analista]
            = $this->criarEmpresaEPessoas(false, true, true, 'Empresa Coerência Sem Estrategista');

        $admin         = $this->admin();
        $mesColeta     = Carbon::create(2026, 7, 1);
        $mesFinanceiro = Carbon::create(2026, 6, 1);

        $this->assertSame(0.0, $this->invocarComputeNpsMedio($analista, $mesColeta->copy()),
            'NPSMAN-07 — sem estrategista, bônus mantém a sentinela 0.0 (DESEMP-03).');

        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['media']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['total']);

        $notasPorEmpresa = app(NpsPorEmpresaService::class)
            ->notasNpsPorEmpresa($analista, $mesFinanceiro->copy(), true, collect());
        $linha = $notasPorEmpresa->get($empresa->id);
        $this->assertNotNull($linha, 'empresa continua no denominador da carteira do analista, só sem nota.');
        $this->assertNull($linha->nota);
        $this->assertSame('nao_elegivel', $linha->origem);

        $periodo   = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
        $resultado = app(CompanyScoreService::class)
            ->computeEmpresasScore($analista, $mesFinanceiro->copy(), $periodo, collect());
        $linhaScore = $resultado->get($empresa->id);
        $this->assertNotNull($linhaScore);
        $this->assertNull($linhaScore->nps_pontos);
        $this->assertContains('nps_nao_elegivel', $linhaScore->quality['motivos'],
            'número e motivo precisam concordar — não só o número.');
        $this->assertNotContains('nps_janela_aberta', $linhaScore->quality['motivos']);

        $propsCarteira = $this->propsDashboardCarteira($analista);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $empresa->id);
        $this->assertNotNull($linhaCarteira);
        $this->assertNull($linhaCarteira['nps']);
    }

    public function test_empresa_sem_contrato_ativo_em_servico_coberto_nunca_conta_em_nenhum_consumidor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        // Contrato INATIVO no mesmo serviço que o modelo cobre — o serviço
        // segue vivo na pivot (`servico_id` preenchido), mas
        // `contratosServico()->active()` não devolve nada, então nenhum
        // modelo é "aplicável" (NpsElegibilidadeService::modelosAplicaveis).
        ['empresa' => $empresa, 'analista' => $analista, 'estrategista' => $estrategista]
            = $this->criarEmpresaEPessoas(true, false, true, 'Empresa Coerência Sem Contrato Ativo');

        $admin         = $this->admin();
        $mesColeta     = Carbon::create(2026, 7, 1);
        $mesFinanceiro = Carbon::create(2026, 6, 1);

        $this->assertSame(0.0, $this->invocarComputeNpsMedio($estrategista, $mesColeta->copy()),
            'NPSMAN-07 — sem contrato ativo em serviço coberto, bônus mantém a sentinela 0.0.');

        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['media']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['total']);

        $notasPorEmpresa = app(NpsPorEmpresaService::class)
            ->notasNpsPorEmpresa($estrategista, $mesFinanceiro->copy(), true, collect());
        $linha = $notasPorEmpresa->get($empresa->id);
        $this->assertNotNull($linha);
        $this->assertNull($linha->nota);
        $this->assertSame('nao_elegivel', $linha->origem);

        $periodo   = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
        $resultado = app(CompanyScoreService::class)
            ->computeEmpresasScore($estrategista, $mesFinanceiro->copy(), $periodo, collect());
        $linhaScore = $resultado->get($empresa->id);
        $this->assertNotNull($linhaScore);
        $this->assertNull($linhaScore->nps_pontos);
        $this->assertContains('nps_nao_elegivel', $linhaScore->quality['motivos']);
        $this->assertNotContains('nps_janela_aberta', $linhaScore->quality['motivos']);

        $propsCarteira = $this->propsDashboardCarteira($estrategista);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $empresa->id);
        $this->assertNotNull($linhaCarteira);
        $this->assertNull($linhaCarteira['nps']);
    }

    public function test_empresa_inativa_some_do_universo_de_todos_os_consumidores(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        ['empresa' => $empresa, 'analista' => $analista]
            = $this->criarEmpresaEPessoas(true, true, false, 'Empresa Coerência Inativa');

        $admin         = $this->admin();
        $mesColeta     = Carbon::create(2026, 7, 1);
        $mesFinanceiro = Carbon::create(2026, 6, 1);

        // Empresa inativa nem chega a entrar no universo de
        // `NpsElegibilidadeService::empresasElegiveis()` (filtra
        // `Company::where('active', true)`) nem em `CarteiraContextService::
        // forUser()` (mesmo filtro) — diferente dos 2 sub-casos acima, ela
        // não vira "nao_elegivel" explícito: ela SOME por completo do
        // universo escopado por carteira/empresa em cada consumidor. É uma
        // forma AINDA MAIS forte de "não conta" — nem chega a ser avaliada.
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($analista, $mesColeta->copy()));

        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $faltante = collect($propsNps['faltantes'])->firstWhere('company_id', $empresa->id);
        $this->assertNull($faltante, 'empresa inativa não pode aparecer nem como faltante na área NPS.');

        $notasPorEmpresa = app(NpsPorEmpresaService::class)
            ->notasNpsPorEmpresa($analista, $mesFinanceiro->copy(), true, collect());
        $this->assertFalse($notasPorEmpresa->has($empresa->id),
            'empresa inativa não pode aparecer no relatório por empresa.');

        $periodo   = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
        $resultado = app(CompanyScoreService::class)
            ->computeEmpresasScore($analista, $mesFinanceiro->copy(), $periodo, collect());
        $this->assertNull($resultado->get($empresa->id), 'empresa inativa não pode aparecer no score por empresa.');

        $propsCarteira = $this->propsDashboardCarteira($analista);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $empresa->id);
        $this->assertNull($linhaCarteira, 'empresa inativa não pode aparecer na carteira.');
    }

    public function test_empresa_invalidada_na_competencia_nunca_conta_em_nenhum_consumidor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        ['empresa' => $empresa, 'analista' => $analista]
            = $this->criarEmpresaEPessoas(true, true, true, 'Empresa Coerência Invalidada');

        $admin         = $this->admin();
        $mesColeta     = Carbon::create(2026, 7, 1);
        $mesFinanceiro = Carbon::create(2026, 6, 1); // competência da invalidação = M, SEM deslocar.

        BonusInvalidacao::create([
            'company_id'     => $empresa->id,
            'competencia'    => $mesFinanceiro->copy(),
            'motivo'         => 'teste coerência 119.1-08',
            'invalidated_by' => null,
        ]);

        $invalidadas = collect([$empresa->id]);

        // 1) Bônus — invalidação é resolvida por `compute()` (não por
        //    `computeNpsMedio()`, chamado aqui isolado via reflection), então
        //    repassamos explicitamente, exatamente como `compute()` faz.
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($analista, $mesColeta->copy(), $invalidadas));

        // 2) Área NPS — resolve a invalidação internamente; a empresa
        //    CONTINUA aparecendo em `faltantes` (ela é ativa e tem vínculo
        //    vivo), só não conta nota 1 (D5/NPSMAN-07 do contrapeso).
        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['media']);
        $faltante = collect($propsNps['faltantes'])->firstWhere('company_id', $empresa->id);
        $this->assertNotNull($faltante, 'empresa invalidada continua na lista de faltantes — só não pesa na média.');
        $this->assertFalse($faltante['conta_nota_1']);

        // 3) Relatório por empresa — rejeitada ANTES de montar o universo
        //    (passo 2 de `notasNpsPorEmpresa()`), então SOME da coleção,
        //    diferente da apresentação acima (universos diferentes, mesmo
        //    resultado de negócio: não pesa em nada).
        $notasPorEmpresa = app(NpsPorEmpresaService::class)
            ->notasNpsPorEmpresa($analista, $mesFinanceiro->copy(), true, $invalidadas);
        $this->assertFalse($notasPorEmpresa->has($empresa->id),
            'empresa invalidada sai ANTES de qualquer resolução de nota — some do universo do serviço.');

        // 4) Score por empresa — mesma rejeição prévia (resolução automática
        //    via BonusInvalidacao::companyIdsInvalidadas($mes), D-05).
        $periodo   = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
        $resultado = app(CompanyScoreService::class)
            ->computeEmpresasScore($analista, $mesFinanceiro->copy(), $periodo, null);
        $this->assertNull($resultado->get($empresa->id), 'empresa invalidada some do score por empresa.');

        // 5) Carteira — mesmo padrão da área NPS: a empresa continua
        //    aparecendo na tabela (é ativa, é carteira viva), só com nps null.
        $propsCarteira = $this->propsDashboardCarteira($analista);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $empresa->id);
        $this->assertNotNull($linhaCarteira, 'empresa invalidada continua na tabela da carteira — só sem nota.');
        $this->assertNull($linhaCarteira['nps']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // CENÁRIO 3 — Empresa ELEGÍVEL sem link, mês de coleta AINDA ABERTO →
    //             nenhum consumidor penaliza; motivo é janela_aberta, NUNCA
    //             nao_elegivel.
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_elegivel_sem_link_mes_ainda_aberto_nenhum_consumidor_penaliza(): void
    {
        // Financeiro (junho) já fechou; a COLETA do NPS (julho) ainda está
        // em curso ("hoje" cai no meio de julho) — janela aberta vence sobre
        // elegibilidade (ordem fixa, preservada desde a Fase 118).
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        ['empresa' => $empresa, 'analista' => $analista, 'estrategista' => $estrategista]
            = $this->criarEmpresaEPessoas(true, true, true, 'Empresa Coerência Mês Aberto');

        $admin         = $this->admin();
        $mesColeta     = Carbon::create(2026, 7, 1);
        $mesFinanceiro = Carbon::create(2026, 6, 1);

        // 1) Bônus.
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($analista, $mesColeta->copy()));
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($estrategista, $mesColeta->copy()));

        // 2) Área NPS.
        $propsNps = $this->propsDoIndex($admin, ['mes' => '2026-07']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['media']);
        $this->assertEquals(0, $propsNps['cards']['empresa']['total']);

        // 3) Relatório por empresa — motivo É janela_aberta, NUNCA nao_elegivel.
        $notasPorEmpresa = app(NpsPorEmpresaService::class)
            ->notasNpsPorEmpresa($estrategista, $mesFinanceiro->copy(), true, collect());
        $linha = $notasPorEmpresa->get($empresa->id);
        $this->assertNotNull($linha);
        $this->assertNull($linha->nota);
        $this->assertSame('janela_aberta', $linha->origem,
            'a empresa é elegível — o motivo de excluir é a janela, nunca nao_elegivel.');

        // 4) Score por empresa.
        $periodo   = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
        $resultado = app(CompanyScoreService::class)
            ->computeEmpresasScore($estrategista, $mesFinanceiro->copy(), $periodo, collect());
        $linhaScore = $resultado->get($empresa->id);
        $this->assertNotNull($linhaScore);
        $this->assertNull($linhaScore->nps_pontos);
        $this->assertContains('nps_janela_aberta', $linhaScore->quality['motivos']);
        $this->assertNotContains('nps_nao_elegivel', $linhaScore->quality['motivos']);

        // 5) Carteira — NUANCE, não incoerência: a coluna NPS da carteira é
        //    uma janela ROLANTE que sempre mostra a nota MAIS RECENTE
        //    disponível (mesma régua já documentada em
        //    `NpsFloorRegressaoTest::test_coluna_nps_carteira_...`), nunca
        //    "a nota da competência X". O piso retroativo (DEC-09-B) é
        //    SEMPRE "mês anterior a hoje" — e o mês anterior (junho, aqui)
        //    JÁ FECHOU e é elegível pelo estado ATUAL da empresa
        //    (`NpsElegibilidadeService::empresasElegiveis()` não reconstrói
        //    elegibilidade histórica). Por isso a carteira já mostra 1.0
        //    (de junho) MESMO com julho ainda em coleta — ela não está
        //    "penalizando julho": está corretamente refletindo que junho já
        //    fechou sem link. Os outros 4 consumidores, que leem uma
        //    competência ESPECÍFICA (julho/junho), corretamente mostram
        //    "nada ainda" para aquela competência pontual — é a MESMA regra
        //    de negócio, expressa em duas formas de leitura diferentes.
        $propsCarteira = $this->propsDashboardCarteira($analista);
        $linhaCarteira = collect($propsCarteira['empresas'])->firstWhere('id', $empresa->id);
        $this->assertNotNull($linhaCarteira);
        $this->assertEquals(1.0, $linhaCarteira['nps'],
            'a carteira mostra a nota MAIS RECENTE (D1 de junho, já fechado) — não é incoerência, é janela rolante vs. competência pontual.');
    }
}
