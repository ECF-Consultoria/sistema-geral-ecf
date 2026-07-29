<?php

namespace Tests\Feature\Phase116;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plan 04 (RED) — os DOIS consumidores de CARTEIRA DO PROFISSIONAL
 * passam a contar o NPS efetivamente disparado e não respondido como nota 1:
 *
 *  - `PerformanceController::dashboardCarteira()` (coluna NPS da tabela de
 *    empresas, heatmap e últimas notas) — rota GET /dashboard;
 *  - `PortfolioController::renderPortfolio()` (histórico NPS mensal do
 *    profissional) — rota GET /admin/users/{user}/portfolio (self-view).
 *
 * Exercitado pelo caminho REAL (rotas HTTP + props Inertia) — NUNCA por
 * reflection em método privado, instrução explícita do 116-04-PLAN.md (molde
 * de `NpsInvalidacaoCallSitesTest`, mas sem os helpers de reflection). Linhas
 * imputadas nascem SEMPRE via `NpsImputationService::materializarLote()` —
 * nunca `NpsImputedAssignment::create()` na mão — pra também provar a
 * integração escrita→leitura entre o Plan 01 e este.
 *
 * @see .planning/phases/116-.../116-04-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 */
class NpsFloorCarteiraTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (molde de NpsFloorDesempenhoTest / NpsInvalidacaoCallSitesTest)
    // ═══════════════════════════════════════════════════════════════════

    private function imputationService(): NpsImputationService
    {
        return app(NpsImputationService::class);
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
            'nome'       => 'Template 116-04 ' . uniqid(),
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

        return $template->fresh(['questions.options']);
    }

    /** Survey NÃO respondido do mês corrente (candidato à imputação). */
    private function criarSurveyNaoRespondido(Company $empresa, NpsTemplate $template, ?Carbon $mesReferencia = null): NpsSurvey
    {
        $mesReferencia ??= now()->copy()->startOfMonth();

        return NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => $mesReferencia->copy()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => $mesReferencia->toDateString(),
            'auto_generated'  => true,
        ]);
    }

    /** Responde o survey pelo FLUXO REAL (`POST /nps/{token}`). */
    private function responder(Company $empresa, NpsTemplate $template, int $peso, ?Carbon $mesReferencia = null): void
    {
        $mesReferencia ??= now()->copy()->startOfMonth();

        $survey = NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => $mesReferencia->copy()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => $mesReferencia->toDateString(),
            'auto_generated'  => true,
        ]);

        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $answers,
        ])->assertOk();
    }

    private function propsDashboard(User $user): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $page->component('Performance/Dashboard');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function propsPortfolio(User $user): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('portfolio.show', $user))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $page->component('Portfolio/Show');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — coluna NPS da tela de performance conta o não respondido como 1
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_coluna_nps_performance_conta_nao_respondido_como_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->criarSurveyNaoRespondido($empresa, $template);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsDashboard($analista);

        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertNotNull($linha, 'a empresa precisa aparecer na tabela de carteira');
        $this->assertEquals(1.0, $linha['nps'],
            'coluna NPS da carteira deve contar o survey disparado e não respondido como nota 1 (hoje vem null)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — resposta real em outra empresa convive com o não respondido, sem
    //     dupla contagem do mesmo survey
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_coluna_nps_performance_convive_com_resposta_real_sem_dupla_contagem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $empresaNaoRespondida = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresaNaoRespondida->id, $servicoPerf, true);
        $this->inserirPivot($empresaNaoRespondida->id, $analista->id, 'consultor', $servicoPerf);

        $empresaRespondida = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresaRespondida->id, $servicoPerf, true);
        $this->inserirPivot($empresaRespondida->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);

        $this->criarSurveyNaoRespondido($empresaNaoRespondida, $template);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $this->responder($empresaRespondida, $template, 5);

        $props = $this->propsDashboard($analista);

        $linhaNaoRespondida = collect($props['empresas'])->firstWhere('id', $empresaNaoRespondida->id);
        $linhaRespondida    = collect($props['empresas'])->firstWhere('id', $empresaRespondida->id);

        $this->assertEquals(1.0, $linhaNaoRespondida['nps'],
            'empresa com survey não respondido conta nota 1');
        $this->assertEquals(5.0, $linhaRespondida['nps'],
            'empresa com resposta real mantém a nota real — as duas linhas coexistem sem dupla contagem do mesmo survey');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — empresa da carteira NÃO elegível, SEM survey nenhum, continua
    //     com NPS null (D3 preservado — contrapeso de D1/Fase 119.1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_coluna_nps_performance_empresa_nao_elegivel_sem_survey_continua_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        // SEM ESTRATEGISTA atribuído — empresa NÃO é elegível (NPSMAN-07).
        // Nenhum survey criado para esta empresa — invariante D3.

        $props = $this->propsDashboard($analista);

        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertNull($linha['nps'],
            'invariante D3 preservado para empresa NÃO elegível: sem disparo nunca entra como nota 1');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3b — empresa ELEGÍVEL (D1/Fase 119.1) sem survey: este call-site
    //     AINDA mantém NPS null — GAP CONHECIDO, não é regressão
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_coluna_nps_performance_empresa_elegivel_sem_survey_ainda_continua_null_gap_conhecido(): void
    {
        // Fase 119.1 (D1) inverteu D3 DE PROPÓSITO para o BÔNUS e para a
        // ÁREA NPS (`NpsController::index()`), mas
        // `PerformanceController::dashboardCarteira()` ainda NÃO consome
        // `NpsSemLinkService`. Mesmo com uma empresa GENUINAMENTE elegível
        // (estrategista + contrato ativo + modelo automático aplicável), a
        // coluna NPS da carteira continua null — divergência CONHECIDA,
        // registrada no SUMMARY do 119.1-04 para o plano 08 fechar a
        // coerência entre telas. NÃO é regressão: este call-site não foi
        // tocado nesta fase.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista     = $this->criarAnalista();
        $estrategista = User::factory()->create();
        $servicoPerf  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa      = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);
        // Nenhum survey criado — a empresa é ELEGÍVEL, mas este consumidor
        // ainda não conta D1.

        $props = $this->propsDashboard($analista);

        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertNull($linha['nps'],
            'GAP CONHECIDO (119.1): dashboardCarteira ainda não consome D1 — pendente para o plano 08.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — histórico NPS mensal do profissional (Portfolio) conta o mês do
    //     não respondido como média 1.0
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_historico_nps_portfolio_mes_do_nao_respondido_media_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->criarSurveyNaoRespondido($empresa, $template);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsPortfolio($analista);

        $mesAtual = collect($props['nps_history'])->firstWhere('month', '2026-07');

        $this->assertNotNull($mesAtual,
            'o mês do survey não respondido precisa aparecer no histórico, mesmo sem NENHUMA resposta real');
        $this->assertEquals(1.0, $mesAtual['avg'],
            'histórico mensal do profissional conta o não respondido como nota 1');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — mês sem NENHUM survey disparado não muda o histórico (comportamento
    //     atual preservado)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_historico_nps_portfolio_mes_sem_survey_nao_muda(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        // Nenhum survey criado em nenhum mês.

        $props = $this->propsPortfolio($analista);

        $this->assertSame([], $props['nps_history'],
            'sem nenhum survey disparado, o histórico NPS mensal continua vazio (comportamento atual preservado)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — survey RESPONDIDO no mês não gera linha imputada nenhuma nos
    //     widgets de carteira (disjunção por construção)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_respondido_nao_gera_linha_imputada_nos_widgets_carteira(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // principal: true — o histórico NPS mensal do Portfolio só enxerga
        // respostas do template ->principal(); precisamos que a resposta
        // REAL apareça pra provar que ela prevalece sem imputação extra.
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf], principal: true);
        $this->responder($empresa, $template, 4);
        // Materializa DEPOIS da resposta chegar — não deve criar nem deixar
        // linha imputada (survey já está completed).
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $this->assertSame(0, DB::table('nps_imputed_assignments')->count(),
            'survey respondido não gera (nem deixa) linha imputada — disjunção por construção');

        $propsDashboard = $this->propsDashboard($analista);
        $linhaDashboard = collect($propsDashboard['empresas'])->firstWhere('id', $empresa->id);
        $this->assertEquals(4.0, $linhaDashboard['nps'],
            'nota real (4.0) prevalece na coluna NPS, sem soma de nota imputada extra');

        $propsPortfolio = $this->propsPortfolio($analista);
        $mesAtual = collect($propsPortfolio['nps_history'])->firstWhere('month', '2026-07');
        $this->assertNotNull($mesAtual);
        $this->assertEquals(4.0, $mesAtual['avg'],
            'histórico mensal também preserva a nota real (4.0), sem imputação extra');
    }
}
