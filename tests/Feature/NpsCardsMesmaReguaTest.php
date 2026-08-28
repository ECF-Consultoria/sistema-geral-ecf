<?php

namespace Tests\Feature;

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
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * 2026-08-14 — os 3 cards do `/nps` usam a MESMA régua, e é a régua do bônus.
 *
 * Relato: "todos com NPS médio alto e a empresa com 1,6, sendo que recebeu a
 * mesma nota 5". Medido em produção: 6 respostas, todas nota 5 nas três
 * dimensões, e mesmo assim `empresa = 1,71` contra `estrategista = 5,00`.
 *
 * Eram DUAS causas somadas:
 *
 *  1. A nota 1 do não respondido entrava com a janela de coleta ainda ABERTA —
 *     penalizando no dia 1 uma resposta que o cliente tem até o dia 31 para
 *     dar. O bônus nunca fez isso: `NpsPorEmpresaService` D-04 EXCLUI a empresa
 *     (`nota = null`, ramo `janela_aberta`) enquanto a coleta corre, e só
 *     converte em nota 1 depois que o mês fecha.
 *  2. Ela entrava em UM card só. `NpsImputationService::materializar()` cria a
 *     linha da dimensão `empresa` sem depender de serviço/responsável, mas
 *     `estrategista`/`analista` só nascem dentro de `serviços cobertos pelo
 *     modelo ∩ contratos ativos` — e os 3 modelos ativos de produção estão sem
 *     nenhum serviço coberto. Medido em 2026-08: empresa 28 linhas,
 *     estrategista 0, analista 0.
 *
 * A correção ataca (1), que é de código e vale para as três dimensões de uma
 * vez. A (2) é de configuração (cadastrar os serviços cobertos dos modelos) e
 * segue em aberto — com esta trava, ela deixa de distorcer a comparação.
 *
 * O `nao_respondidos` continua no payload: some da MÉDIA, nunca da tela.
 */
class NpsCardsMesmaReguaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTemplatePrincipal(array $dimensoes, array $servicoIds): NpsTemplate
    {
        NpsTemplate::query()->update(['is_default' => false]);

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo régua ' . uniqid(),
            'active'     => true,
            'is_default' => true,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $q = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $q->id,
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

    private function surveyNaoRespondido(Company $empresa, NpsTemplate $template, Carbon $mes): NpsSurvey
    {
        return NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => $mes->copy()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => $mes->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ]);
    }

    private function responder(Company $empresa, NpsTemplate $template, int $peso, Carbon $mes): void
    {
        $survey = $this->surveyNaoRespondido($empresa, $template, $mes);

        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $answers,
        ])->assertOk();
    }

    private function cards(User $admin, array $query = []): array
    {
        $props = null;
        $this->actingAs($admin)
            ->get(route('nps.index', $query + ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * Cenário do relato, reproduzido: 1 resposta nota 5 + 1 não respondido, com
     * a coleta do mês AINDA ABERTA. Antes, `empresa` caía para 3,0 enquanto
     * `estrategista` ficava em 5,0 — mesma pesquisa, mesmas notas.
     */
    #[Test]
    public function test_com_a_coleta_aberta_nenhum_card_conta_o_nao_respondido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin       = User::factory()->create(['role' => 'admin', 'active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $template = $this->criarTemplatePrincipal(
            [
                NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                NpsTemplateQuestion::DIMENSAO_ANALISTA,
                NpsTemplateQuestion::DIMENSAO_EMPRESA,
            ],
            [$servicoPerf]
        );

        $respondeu = Company::factory()->create(['active' => true]);
        $this->criarContrato($respondeu->id, $servicoPerf, true);
        $this->responder($respondeu, $template, 5, Carbon::parse('2026-08-01'));

        $calou = Company::factory()->create(['active' => true]);
        $this->criarContrato($calou->id, $servicoPerf, true);
        $this->surveyNaoRespondido($calou, $template, Carbon::parse('2026-08-01'));
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-08-01'));

        $props = $this->cards($admin, ['mes' => '2026-08']);

        $this->assertFalse($props['janela_fechada'], 'agosto ainda está em coleta no dia 14.');

        // As três dimensões enxergam a MESMA nota 5 — nenhuma leva o 1.
        foreach (['estrategista', 'analista', 'empresa'] as $dim) {
            $this->assertEquals(
                5.0,
                $props['cards'][$dim]['media'],
                "card {$dim} não pode ser penalizado enquanto o cliente ainda pode responder."
            );
        }

        // O contador some da média, nunca da tela.
        $this->assertGreaterThan(0, $props['cards']['empresa']['nao_respondidos']);
    }

    /**
     * A régua NÃO foi removida — apenas passou a respeitar o prazo. Com a
     * coleta encerrada, o não respondido volta a valer 1.
     */
    #[Test]
    public function test_com_a_coleta_encerrada_o_nao_respondido_volta_a_contar_1(): void
    {
        // O cliente responde DENTRO da janela de julho...
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin       = User::factory()->create(['role' => 'admin', 'active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $template = $this->criarTemplatePrincipal(
            [NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf]
        );

        $respondeu = Company::factory()->create(['active' => true]);
        $this->criarContrato($respondeu->id, $servicoPerf, true);
        $this->responder($respondeu, $template, 5, Carbon::parse('2026-07-01'));

        $calou = Company::factory()->create(['active' => true]);
        $this->criarContrato($calou->id, $servicoPerf, true);
        $this->surveyNaoRespondido($calou, $template, Carbon::parse('2026-07-01'));
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-07-01'));

        // ...e a tela é aberta em agosto, com a coleta de julho já encerrada.
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $props = $this->cards($admin, ['mes' => '2026-07']);

        $this->assertTrue($props['janela_fechada'], 'a coleta de julho já encerrou.');
        $this->assertEquals(
            3.0,
            $props['cards']['empresa']['media'],
            'coleta encerrada: (5 + 1) / 2 = 3,0 — a régua da Fase 116 segue valendo.'
        );
    }

    /**
     * 2026-08-18 — o rodapé do card mentia sobre QUANTAS pessoas responderam.
     *
     * Relatado em 28/08 com a coleta de agosto aberta: o chip "Respondidos"
     * dizia 126 e os cards diziam 120 / 117 / 119. A causa não era o cálculo
     * da nota, e sim a UI derivando o número por subtração
     * (`total - nao_respondidos`). Essa conta só valia enquanto as imputadas
     * SEMPRE entravam no `total` — desde 2026-08-14 elas não entram com a
     * janela aberta, então a subtração descontava algo que nunca foi somado
     * (em produção: 126 − 6 = 120).
     *
     * `respondidas` passa a vir pronto do servidor, contado ANTES do merge.
     */
    #[Test]
    public function test_respondidas_conta_respostas_reais_e_nao_desconta_o_nao_respondido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $admin       = User::factory()->create(['role' => 'admin', 'active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $template = $this->criarTemplatePrincipal(
            [
                NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
                NpsTemplateQuestion::DIMENSAO_ANALISTA,
                NpsTemplateQuestion::DIMENSAO_EMPRESA,
            ],
            [$servicoPerf]
        );

        // 2 responderam...
        foreach ([1, 2] as $i) {
            $respondeu = Company::factory()->create(['active' => true]);
            $this->criarContrato($respondeu->id, $servicoPerf, true);
            $this->responder($respondeu, $template, 5, Carbon::parse('2026-08-01'));
        }

        // ...e 1 ainda não respondeu (dentro do prazo).
        $calou = Company::factory()->create(['active' => true]);
        $this->criarContrato($calou->id, $servicoPerf, true);
        $this->surveyNaoRespondido($calou, $template, Carbon::parse('2026-08-01'));
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-08-01'));

        $props = $this->cards($admin, ['mes' => '2026-08']);

        $this->assertFalse($props['janela_fechada'], 'agosto ainda está em coleta no dia 14.');

        foreach (['estrategista', 'analista', 'empresa'] as $dim) {
            $this->assertSame(
                2,
                $props['cards'][$dim]['respondidas'],
                "card {$dim}: quem respondeu foram 2 — a subtração antiga dava 1."
            );
        }

        // O card não pode contradizer o chip "Respondidos" da lista abaixo.
        $this->assertSame(2, $props['contadores']['respondidos']);

        // E o aviso de prazo continua na tela (some da média, não da tela).
        $this->assertGreaterThan(0, $props['cards']['empresa']['nao_respondidos']);
    }
}
