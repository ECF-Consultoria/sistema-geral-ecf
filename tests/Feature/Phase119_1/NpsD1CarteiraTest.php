<?php

namespace Tests\Feature\Phase119_1;

use App\Models\BonusInvalidacao;
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
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 09 (Task 2) — D1 na régua por PESSOA: a coluna NPS da
 * carteira do profissional (`PerformanceController::dashboardCarteira()`)
 * e o histórico mensal (`PortfolioController`) passam a contar nota 1 para
 * empresa ELEGÍVEL sem NENHUM link no mês fechado, mesma régua do bônus,
 * com contrapeso de não elegível/invalidada/carteira alheia provado.
 *
 * Exercitado pelo caminho REAL (rotas HTTP + props Inertia), molde de
 * `NpsFloorCarteiraTest` — nunca por reflection em método privado.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Http/Controllers/PerformanceController.php
 * @see app/Http/Controllers/PortfolioController.php
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-09-PLAN.md
 */
class NpsD1CarteiraTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures (molde de NpsFloorCarteiraTest — não inventar outro)
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
            // `NpsSurvey::scopePrincipal()` (usado pelo ramo real do
            // histórico do Portfolio) exige EXATAMENTE 1 `is_default=true`.
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 119.1-09 ' . uniqid(),
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

    /**
     * Monta empresa ativa + serviço/contrato ativo + estrategista/analista
     * + modelo NPS aplicável (active + envio_automatico_mensal).
     *
     * @return array{empresa: Company, servico: int, template: NpsTemplate}
     */
    private function criarCenarioElegivel(User $analista, array $atributosEmpresa = []): array
    {
        $empresa = Company::factory()->create(array_merge(['active' => true], $atributosEmpresa));
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servico);

        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        // Principal (is_default=true) — o histórico REAL do Portfolio só
        // enxerga respostas de `->principal()` (molde de NpsFloorCarteiraTest).
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servico], principal: true);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        return ['empresa' => $empresa, 'servico' => $servico, 'template' => $template];
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
    // Coluna NPS da carteira (PerformanceController::dashboardCarteira)
    // ═══════════════════════════════════════════════════════════════════

    public function test_coluna_nps_empresa_elegivel_sem_link_mes_anterior_fechado_conta_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista = $this->criarAnalista();
        $this->criarCenarioElegivel($analista);

        $props = $this->propsDashboard($analista);
        $linha = collect($props['empresas'])->first();

        $this->assertEquals(1.0, $linha['nps'], 'Empresa elegível sem NENHUM link no mês anterior fechado conta nota 1 (D1).');
    }

    public function test_coluna_nps_empresa_nao_elegivel_sem_estrategista_continua_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista    = $this->criarAnalista();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa     = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        // SEM estrategista atribuído — empresa NÃO é elegível (NPSMAN-07).

        $props = $this->propsDashboard($analista);
        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertNull($linha['nps'], 'Contrapeso NPSMAN-07: empresa NÃO elegível continua sem contar nota.');
    }

    public function test_coluna_nps_empresa_elegivel_invalidada_na_competencia_continua_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

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

        $props = $this->propsDashboard($analista);
        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertNull($linha['nps'], 'Empresa invalidada na competência continua fora — contrapeso de D1.');
    }

    public function test_coluna_nps_nao_puxa_empresa_de_carteira_alheia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analistaDono  = $this->criarAnalista();
        $outroAnalista = $this->criarAnalista();
        ['empresa' => $empresaDoDono] = $this->criarCenarioElegivel($analistaDono);

        $props = $this->propsDashboard($outroAnalista);

        $linha = collect($props['empresas'])->firstWhere('id', $empresaDoDono->id);
        $this->assertNull($linha, 'Empresa de OUTRO analista, elegível e sem link, não aparece na carteira de quem não cuida dela.');
    }

    public function test_coluna_nps_continua_null_quando_empresa_nao_elegivel_por_falta_de_modelo_e_mes_corrente_esta_aberto(): void
    {
        // Empresa com estrategista e contrato ativo (não é o motivo #2), mas
        // SEM nenhum modelo automático cobrindo o serviço contratado —
        // outro dos 4 motivos de NPSMAN-07. Nem o mês anterior (fechado) nem
        // o mês corrente (aberto) produzem nota — coluna continua null.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista     = $this->criarAnalista();
        $estrategista = User::factory()->create();
        $servicoPerf  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresa      = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);
        // Nenhum NpsTemplate criado para o serviço — sem modelo aplicável.

        $props = $this->propsDashboard($analista);
        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertNull($linha['nps'], 'Sem modelo aplicável (NPSMAN-07), nenhum mês (fechado ou aberto) produz nota.');
    }

    public function test_coluna_nps_survey_pendente_na_competencia_conta_1_uma_vez_pelo_ramo_c_sem_duplicar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista = $this->criarAnalista();
        ['empresa' => $empresa, 'template' => $template] = $this->criarCenarioElegivel($analista);

        // Survey disparado (pendente) no mês anterior fechado — ramo (C) da
        // Fase 116 já conta 1; o ramo D1 deve reconhecer que já existe link
        // e NÃO duplicar.
        NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => Carbon::create(2026, 6, 1)->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => Carbon::create(2026, 6, 1)->toDateString(),
            'auto_generated'  => true,
        ]);
        $this->imputationService()->materializarLote(Carbon::create(2026, 6, 1));

        $props = $this->propsDashboard($analista);
        $linha = collect($props['empresas'])->firstWhere('id', $empresa->id);

        $this->assertEquals(1.0, $linha['nps'], 'Ramo (C) conta 1 uma vez; ramo D1 não duplica quando já existe link no mês.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Histórico mensal do profissional (PortfolioController)
    // ═══════════════════════════════════════════════════════════════════

    public function test_historico_mes_anterior_fechado_sem_link_aparece_com_avg_1_e_count_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista = $this->criarAnalista();
        $this->criarCenarioElegivel($analista);

        $props = $this->propsPortfolio($analista);
        $mesAnterior = collect($props['nps_history'])->firstWhere('month', '2026-06');

        $this->assertNotNull($mesAnterior, 'Mês anterior fechado sem link precisa aparecer na série, mesmo sem NENHUM survey completed.');
        $this->assertEquals(1.0, $mesAnterior['avg']);
        $this->assertEquals(1, $mesAnterior['count']);
    }

    public function test_historico_mes_fora_do_piso_nao_ganha_nota_1(): void
    {
        // "Hoje" = 15/07/2026 ⇒ piso = junho. Maio está FORA do piso — o
        // histórico antigo não é reescrito por este ramo.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $analista = $this->criarAnalista();
        $this->criarCenarioElegivel($analista);

        $props = $this->propsPortfolio($analista);
        $mesForaDoPiso = collect($props['nps_history'])->firstWhere('month', '2026-05');

        $this->assertNull($mesForaDoPiso, 'Maio está fora do piso retroativo — não pode ganhar nota 1 por efeito colateral de uma leitura (DEC-09-B).');
    }

    public function test_historico_mes_com_resposta_real_nao_ganha_nota_fantasma(): void
    {
        // A resposta precisa acontecer DENTRO do mês de coleta (survey
        // expira no fim do próprio mês) — congela "hoje" em junho pra
        // responder de verdade, depois avança pra julho pra conferir o
        // histórico (mesmo padrão de qualquer NPS respondido no mês certo).
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));

        $analista = $this->criarAnalista();
        ['empresa' => $empresa, 'template' => $template] = $this->criarCenarioElegivel($analista);

        $survey = NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => Carbon::create(2026, 6, 1)->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => Carbon::create(2026, 6, 1)->toDateString(),
            'auto_generated'  => true,
        ]);

        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', 5)->id;
        }
        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $answers,
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $props = $this->propsPortfolio($analista);
        $mesAnterior = collect($props['nps_history'])->firstWhere('month', '2026-06');

        $this->assertNotNull($mesAnterior);
        $this->assertEquals(5.0, $mesAnterior['avg'], 'Resposta real (5.0) prevalece — nenhuma nota fantasma somada pelo ramo D1.');
        $this->assertEquals(1, $mesAnterior['count'], 'Apenas 1 nota no mês — a real, sem duplicação.');
    }
}
