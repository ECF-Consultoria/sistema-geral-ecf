<?php

namespace Tests\Feature\Phase116;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
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
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plan 03 (RED) — a ÁREA NPS (`NpsController::index()`) passa a
 * contar cada NPS efetivamente disparado e não respondido como nota 1 nos
 * 3 cards (estrategista/analista/empresa — D7), na série de 12 meses e nos
 * contadores, respeitando invalidação por competência (D5, capacidade NOVA
 * desta tela) e disparo manual sem `month_reference` (D6).
 *
 * Molde de fixture herdado de `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`
 * (survey respondido via fluxo REAL `POST /nps/{token}` quando precisamos de
 * atribuição congelada) e `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`
 * (survey/response legado via `::create()` direto quando só precisamos de uma
 * nota "real" simples, sem tocar o veredito de suspeita). As linhas imputadas
 * SEMPRE nascem via `NpsImputationService::materializar()`/`materializarLote()`
 * — nunca criadas na mão — para provar a integração leitura↔escrita entre os
 * planos 01/02/03.
 *
 * A tela é exercitada pelo caminho REAL (`GET /nps` via `route('nps.index')`),
 * lendo as props Inertia — nunca chamando métodos privados do controller.
 *
 * @see .planning/phases/116-.../116-03-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 */
class NpsFloorAreaNpsTest extends TestCase
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
    // Helpers de fixture
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function imputationService(): NpsImputationService
    {
        return app(NpsImputationService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template 116-03 ' . uniqid(),
            'active' => true,
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

    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    /**
     * Responde o survey pelo FLUXO REAL (`POST /nps/{token}`) — gera a
     * atribuição congelada da Fase 79 e a resposta "real" propriamente dita.
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
            'respondent_name' => 'Cliente 116-03',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
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
            'respondent_name' => 'Cliente Teste 116-03',
            'comment'         => null,
        ], $overrides));
    }

    /** Resposta v15 (com answers snapshot) para um survey já `completed`. */
    private function criarRespostaComAnswers(NpsSurvey $survey, NpsTemplate $template, int $peso): NpsResponse
    {
        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente tardio 116-03',
        ]);

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

    /** GET /nps pelo caminho REAL — devolve as props Inertia como array puro. */
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

    // ═══════════════════════════════════════════════════════════════════
    // 1 — respondido (5) + não respondido (1) fazem média 3.0 no card empresa
    //     (NPSFLOOR-01/12)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_respondido_e_nao_respondido_fazem_media_3_no_card_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa = Company::factory()->create(['active' => true]);

        $templateEmpresa = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        // Respondido — nota 5 (legado, sem template).
        $surveyRespondido = $this->criarSurveyCompleto($empresa, ['completed_at' => Carbon::parse('2026-07-10 09:00:00')]);
        $this->criarResponseLegado($surveyRespondido, ['score_empresa' => 5]);

        // Não respondido — imputação nota 1.
        $this->criarSurveyNaoRespondido($empresa, $templateEmpresa, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsDoIndex($admin, ['mes' => '2026-07']);

        $this->assertEquals(3.0, $props['cards']['empresa']['media']);
        $this->assertEquals(2, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — as 3 chaves (estrategista/analista/empresa) refletem a regra
    //     (NPSFLOOR-12: a dimensão empresa TAMBÉM recebe o 1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_tres_cards_incluindo_empresa_recebem_nota_1_do_nao_respondido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista     = User::factory()->create();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf],
        );

        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        $this->assertEquals(1.0, $props['cards']['estrategista']['media']);
        $this->assertEquals(1, $props['cards']['estrategista']['total']);
        $this->assertEquals(1.0, $props['cards']['analista']['media']);
        $this->assertEquals(1, $props['cards']['analista']['total']);
        $this->assertEquals(1.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — empresa NÃO elegível SEM survey no mês não altera médias e
    //     continua em faltantes (D3, contrapeso de D1/Fase 119.1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_nao_elegivel_sem_survey_no_mes_nao_altera_medias_e_nao_aparece_em_faltantes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        // Empresa COM survey não respondido (puxa nota 1 no card empresa).
        $empresaComSurvey = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresaComSurvey->id, $servicoPerf, true);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], [$servicoPerf]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        $this->criarSurveyNaoRespondido($empresaComSurvey, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        // Empresa ATIVA com contrato do MESMO serviço, mas SEM NENHUM survey
        // no mês E SEM ESTRATEGISTA atribuído — NÃO é elegível (NPSMAN-07).
        $empresaSemSurvey = Company::factory()->create(['active' => true, 'name' => 'Empresa Sem Survey 116-03']);
        $this->criarContrato($empresaSemSurvey->id, $servicoPerf, true);

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        // Média idêntica ao cenário só com a empresa que disparou (1.0/1) — a
        // empresa NÃO elegível sem disparo NUNCA entra como nota 1 (D3).
        $this->assertEquals(1.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['cards']['empresa']['total']);

        // Quick task 260730-jzx (ajuste 4) — empresa sem estrategista sai da
        // lista de trabalho: ela ainda não entrou na operação, e a mesma
        // régua (NpsElegibilidadeService) já a ignorava no cálculo da nota.
        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresaSemSurvey->id);
        $this->assertNull($faltante, 'empresa sem estrategista não deve mais aparecer em Faltantes (ajuste 4).');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3b — empresa ELEGÍVEL (D1/Fase 119.1) sem survey no mês: passa a
    //     CONTAR nota 1 na média, e continua aparecendo em faltantes ao
    //     mesmo tempo — as duas informações convivem, não é contradição
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_elegivel_sem_survey_no_mes_conta_nota_1_e_aparece_em_faltantes(): void
    {
        // Mês (julho) já fechado — condição obrigatória de D1.
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));
        $admin = $this->admin();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $empresaComSurvey = Company::factory()->create(['active' => true]);
        $this->criarContrato($empresaComSurvey->id, $servicoPerf, true);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], [$servicoPerf]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        $this->criarSurveyNaoRespondido($empresaComSurvey, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        // Empresa ATIVA com contrato do MESMO serviço, SEM NENHUM survey no
        // mês, mas COM estrategista — GENUINAMENTE elegível (D1/NPSMAN-06).
        $empresaSemLink = Company::factory()->create(['active' => true, 'name' => 'Empresa Elegivel Sem Link 119.1-04']);
        $this->criarContrato($empresaSemLink->id, $servicoPerf, true);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresaSemLink->id, $estrategista->id, 'estrategista', $servicoPerf);

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        // (1 imputada de empresaComSurvey + 1 sem-link de empresaSemLink) / 2
        // = 1.0 — a média continua 1.0, mas o TOTAL de notas cresce para 2.
        $this->assertEquals(1.0, $props['cards']['empresa']['media']);
        $this->assertEquals(2, $props['cards']['empresa']['total'],
            'D1 — empresa elegível sem link soma no total de notas da dimensão empresa.');

        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresaSemLink->id);
        $this->assertNotNull($faltante, 'empresa elegível sem link continua aparecendo em faltantes.');
        $this->assertTrue($faltante['conta_nota_1'],
            'e TAMBÉM conta nota 1 na média — as duas informações convivem, não é contradição.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — empresa invalidada na competência não puxa 1; resposta REAL da
    //     mesma empresa continua entrando (D5/NPSFLOOR-04 — capacidade NOVA)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_invalidada_na_competencia_nao_puxa_1_mas_resposta_real_continua(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], [$servicoPerf]);

        // Resposta REAL nota 4 (legado) — continua contando normalmente.
        $surveyRespondido = $this->criarSurveyCompleto($empresa, ['completed_at' => Carbon::parse('2026-07-10 09:00:00')]);
        $this->criarResponseLegado($surveyRespondido, ['score_empresa' => 4]);

        // Survey não respondido — seria nota 1, mas a empresa está invalidada.
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        // Competência da invalidação = mês do survey MENOS 1 (junho, D5).
        BonusInvalidacao::create([
            'company_id'  => $empresa->id,
            'competencia' => '2026-06-01',
            'motivo'      => 'teste Fase 116-03',
        ]);

        // Sem filtro de template_id (a resposta legada não tem template_id) —
        // mesmo padrão do teste 1.
        $props = $this->propsDoIndex($admin, ['mes' => '2026-07']);

        // Só a resposta real conta — a nota 1 imputada NÃO entra.
        $this->assertEquals(4.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — disparo MANUAL sem month_reference conta na competência do
    //     created_at (D6/NPSFLOOR-11)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_manual_sem_month_reference_conta_na_competencia_do_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        $admin = $this->admin();

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        // Disparo manual — auto_generated=false, month_reference NULL (D6).
        $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => null,
            'auto_generated'  => false,
        ]);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        $this->assertEquals(1.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — série de 12 meses reflete a MESMA média dos cards
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_serie_12m_reflete_mesma_media_dos_cards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        $mesJulho = collect($props['serie_12m'])->firstWhere('mes_iso', '2026-07');

        $this->assertNotNull($mesJulho, 'julho/2026 precisa aparecer na série de 12 meses.');
        $this->assertEquals($props['cards']['empresa']['media'], $mesJulho['empresa']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — resposta que chega DEPOIS do fechamento não reescreve a nota
    //     definitiva da competência (D2)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_tardia_nao_reescreve_nota_definitiva_do_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $admin = $this->admin();

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        $survey = $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => '2026-07-01',
            'expires_at'      => Carbon::parse('2026-07-31 23:59:59'),
        ]);

        // Vira agosto — a competência de julho fechou; promove a linha
        // provisória para definitivo (locked).
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));
        $this->imputationService()->materializar($survey->fresh());

        // Resposta chega tarde (nota 5) — direto no banco (o link real já
        // teria expirado; o que importa aqui é o invariante D2, não o fluxo
        // HTTP de submissão).
        $survey->update(['status' => 'completed', 'completed_at' => now()]);
        $this->criarRespostaComAnswers($survey->fresh(), $template->fresh(['questions.options']), 5);
        $this->imputationService()->materializar($survey->fresh());

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        $this->assertEquals(1.0, $props['cards']['empresa']['media'],
            'a resposta que chegou depois do fechamento não pode reescrever a nota definitiva de julho (D2).');
        $this->assertEquals(1, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8 — payload expõe, por dimensão, quantas notas vieram de não
    //     respondido
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_payload_expoe_quantidade_de_nao_respondidos_por_dimensao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $empresa  = Company::factory()->create(['active' => true]);
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $props = $this->propsDoIndex($admin, ['template_id' => $template->id, 'mes' => '2026-07']);

        $this->assertEquals(1, $props['cards']['empresa']['nao_respondidos']);
        $this->assertTrue($props['regra_nao_respondido']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 9 — não-regressão (Tarefa 3): mês SEM NENHUM survey preserva a
    //     sentinela de vazio (media=0, total=0) — o piso de NPS não
    //     inventa nota nenhuma onde não há disparo algum
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_sem_nenhum_survey_preserva_sentinela_de_vazio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        // Nenhuma empresa, nenhum survey, nenhum template criado neste teste
        // — só o mês filtrado, sem qualquer disparo no período.
        $props = $this->propsDoIndex($admin, ['mes' => '2026-07']);

        foreach (['estrategista', 'analista', 'empresa'] as $dimensao) {
            $this->assertEquals(0, $props['cards'][$dimensao]['media'],
                "sem survey algum no mês, cards.{$dimensao}.media deve continuar 0 (sentinela de vazio).");
            $this->assertEquals(0, $props['cards'][$dimensao]['total']);
            $this->assertEquals(0, $props['cards'][$dimensao]['nao_respondidos']);
        }
    }
}
