<?php

namespace Tests\Feature\Phase71;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 71 Plan 03 T2 — Suite Feature do POST /nps/{token} (submitResponse)
 * do ponto de vista do fluxo Phase 71 v15.0.
 *
 * Escopo pragmatico (nao duplica cobertura da Phase 69-03):
 *   - Prova que POST v15 chegando pelo path Phase 71 (backend + frontend
 *     integrados) gera NpsResponse + N NpsResponseAnswer com snapshot
 *     congelado (verdade historica preservada — research §1).
 *   - Prova que obrigatorias omitidas rejeitam com validation error na chave
 *     `answers.<qid>` (guard server-side redundante ao `podeEnviar` client-side).
 *   - Prova que option_id de outro template dispara Rule::in (tampering guard).
 *   - Prova que a colisao do dedup unique parcial (Plan 68-04) e traduzida
 *     em `Nps/AlreadyCompleted` sem 500 — sanity end-to-end do path 71.
 *   - Prova que o sucesso renderiza `Nps/ThankYou` + persiste
 *     `nps_surveys.status='completed'` + `completed_at`.
 *
 * Racional pragmatico das duplicacoes com Phase 69-03: os testes aqui NAO
 * revalidam TODOS os cenarios de validacao dinamica (Phase 69-03 ja cobre com
 * 7 tests + 60 assertions). Cobrimos apenas os 5 casos minimos que "provam
 * que o path Phase 71 (form dinamico -> POST) integra sem quebras". Suite
 * Phase 69-03 continua sendo o proprietario canonico da cobertura backend
 * do submitResponseV15.
 *
 * As rotas sao publicas (sem `actingAs`) — token e o unico guard.
 *
 * Setup implicito via RefreshDatabase:
 *   - Migrations Phase 68 (100001 schema + 100005 unique parcial de dedup)
 *   - Seed "NPS Padrao" (100004) — irrelevante para esta suite.
 *
 * Referencias:
 *   - .planning/phases/71-formul-rio-p-blico-din-mico/71-03-PLAN.md T2
 *   - tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php (contract)
 *   - app/Http/Controllers/NpsController.php linhas 477-578 (submitResponseV15)
 */
class NpsRespondSubmitFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — o guard 23000 depende do unique parcial
     * (Plan 68-04) estar ativo. Padrao herdado das Phases 68/69/70.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup (duplicados de NpsRespondRenderTest — pragmatismo
    // Plan 71-03: 10 linhas de duplicacao < hierarquia de test cases)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria empresa minima para setup do survey.
     */
    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    /**
     * Cria template v15.0 com N perguntas escala (5 options 1..5 cada).
     * Aceita array de defs [{texto, dimensao, obrigatoria}] — se vazio, cai
     * em default de 1 pergunta obrigatoria dimensao=geral.
     *
     * @return NpsTemplate hidratado com questions.options preloaded
     */
    private function fixtureTemplate(array $perguntasDef = []): NpsTemplate
    {
        if (empty($perguntasDef)) {
            $perguntasDef = [['dimensao' => 'geral', 'obrigatoria' => true, 'texto' => 'Pergunta padrao?']];
        }

        $t = NpsTemplate::factory()->create([
            'nome'   => 'Template ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($perguntasDef as $def) {
            $q = NpsTemplateQuestion::create([
                'template_id' => $t->id,
                'texto'       => $def['texto'] ?? ('Pergunta ' . uniqid() . '?'),
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $def['dimensao'] ?? NpsTemplateQuestion::DIMENSAO_GERAL,
                'obrigatoria' => $def['obrigatoria'] ?? true,
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

        return $t->fresh(['questions.options']);
    }

    /**
     * Cria survey pending v15.0 (template_id preenchido).
     */
    private function criarSurveyV15(Company $company, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'status'          => 'pending',
            'month_reference' => Carbon::now()->startOfMonth()->toDateString(),
            'expires_at'      => Carbon::now()->addDays(7),
            'auto_generated'  => true,
        ], $overrides));
    }

    /**
     * Retorna a opcao com peso especifico dentro de uma question — util
     * para montar payload deterministico.
     */
    private function opcaoComPeso(NpsTemplateQuestion $q, int $peso): NpsTemplateOption
    {
        return $q->options->firstWhere('peso', $peso);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — submit v15 completo cria NpsResponse + N answers snapshot
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_completo_cria_response_com_answers_snapshot(): void
    {
        // Cenario canonico do form dinamico (Phase 71-02 Respond.jsx):
        //   template com 2 perguntas escala, cliente responde ambas com pesos
        //   diferentes. Backend Phase 69-03 grava:
        //     - 1 NpsResponse (score_* legacy NULL)
        //     - 2 NpsResponseAnswer com snapshot congelado
        //       (question_texto/dimensao + option_label/peso)
        //     - Survey vira completed + completed_at not null
        //     - Renderiza Nps/ThankYou
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplate([
            ['dimensao' => 'estrategista', 'texto' => 'Como avalia o estrategista?', 'obrigatoria' => true],
            ['dimensao' => 'empresa',      'texto' => 'Como avalia a empresa?',       'obrigatoria' => true],
        ]);
        $survey = $this->criarSurveyV15($empresa, $template);

        [$q1, $q2] = [$template->questions[0], $template->questions[1]];
        $opt1 = $this->opcaoComPeso($q1, 3);
        $opt2 = $this->opcaoComPeso($q2, 5);

        $response = $this->post(route('nps.submit', $survey->token), [
            'respondent_name' => 'Joao',
            'comment'         => 'otimo mes',
            'answers'         => [
                (string) $q1->id => $opt1->id,
                (string) $q2->id => $opt2->id,
            ],
        ]);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/ThankYou')
        );

        // NpsResponse criado com respondent + comment; scores legacy NULL
        // (fonte de verdade v15.0 e nps_response_answers).
        $this->assertDatabaseHas('nps_responses', [
            'survey_id'       => $survey->id,
            'respondent_name' => 'Joao',
            'comment'         => 'otimo mes',
        ]);
        $this->assertSame(1, NpsResponse::count());
        $this->assertSame(2, NpsResponseAnswer::count());

        // Snapshot congelado — verifica q1 (estrategista, peso 3).
        $this->assertDatabaseHas('nps_response_answers', [
            'template_question_id'       => $q1->id,
            'question_texto_snapshot'    => 'Como avalia o estrategista?',
            'question_dimensao_snapshot' => 'estrategista',
            'option_label_snapshot'      => '3',
            'option_peso_snapshot'       => 3,
        ]);

        // Snapshot congelado — verifica q2 (empresa, peso 5).
        $this->assertDatabaseHas('nps_response_answers', [
            'template_question_id'       => $q2->id,
            'question_dimensao_snapshot' => 'empresa',
            'option_peso_snapshot'       => 5,
        ]);

        // Survey virou completed.
        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — obrigatoria omitida retorna 422 (Rule required)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_obrigatoria_omitida_retorna_422(): void
    {
        // Guard server-side redundante ao `podeEnviar` client-side do
        // Respond.jsx (Plan 71-02). Cliente malicioso que contorne o disable
        // do botao ainda tropeca em 422 do request->validate() do
        // submitResponseV15 (Phase 69-03).
        //
        // Comportamento Laravel para requests non-JSON: 302 redirect com
        // errors na session (padrao Inertia). Frontend recebe e re-renderiza
        // com o campo destacado.
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplate([
            ['dimensao' => 'estrategista', 'obrigatoria' => true, 'texto' => 'Obrigatoria?'],
            ['dimensao' => 'empresa',      'obrigatoria' => false, 'texto' => 'Opcional?'],
        ]);
        $survey = $this->criarSurveyV15($empresa, $template);

        [$qObrig, $qOpc] = [$template->questions[0], $template->questions[1]];

        // POST omitindo a resposta da obrigatoria — envia apenas a opcional.
        $response = $this->post(route('nps.submit', $survey->token), [
            'respondent_name' => 'Joao',
            'answers'         => [
                (string) $qOpc->id => $this->opcaoComPeso($qOpc, 4)->id,
                // qObrig deliberadamente omitida.
            ],
        ]);

        // Laravel converte ValidationException em 302 + session errors para
        // requests non-JSON (padrao Inertia). Cliente recebe validation
        // error na chave `answers.<qid>`.
        $response->assertStatus(302);
        $response->assertSessionHasErrors("answers.{$qObrig->id}");

        // Nada persistido no banco — DB::transaction() nao chegou a rodar.
        $this->assertSame(0, NpsResponse::count());
        $this->assertSame(0, NpsResponseAnswer::count());
        // Survey continua pending.
        $this->assertDatabaseHas('nps_surveys', [
            'id'     => $survey->id,
            'status' => 'pending',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — option_id de OUTRO template retorna 422 (Rule::in guard)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_option_id_de_outro_template_retorna_422(): void
    {
        // Guard de tampering: cliente malicioso que injete option_id de outro
        // template no payload deve tropecar em Rule::in($optionIds da question).
        // Cenario simula 2 templates coexistentes — survey do template A
        // recebe POST tentando usar option_id do template B.
        //
        // Este guard e critico para historico: sem ele, uma answer poderia
        // referenciar option de outro template e o snapshot ficaria
        // incoerente com question_dimensao_snapshot.
        $empresa    = $this->criarEmpresa();
        $templateA  = $this->fixtureTemplate([
            ['dimensao' => 'empresa', 'texto' => 'Template A pergunta'],
        ]);
        $templateB  = $this->fixtureTemplate([
            ['dimensao' => 'empresa', 'texto' => 'Template B pergunta'],
        ]);

        $survey     = $this->criarSurveyV15($empresa, $templateA);
        $qA         = $templateA->questions[0];
        $optionB    = $templateB->questions[0]->options[0]; // pertence a OUTRO template

        $response = $this->post(route('nps.submit', $survey->token), [
            'respondent_name' => 'Cliente',
            'answers'         => [
                (string) $qA->id => $optionB->id, // option de OUTRO template
            ],
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors("answers.{$qA->id}");

        // Zero persistencia — transacao nao rodou.
        $this->assertSame(0, NpsResponse::count());
        $this->assertSame(0, NpsResponseAnswer::count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — dedup 23000 retorna Nps/AlreadyCompleted (sanity Phase 71)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_dedup_23000_retorna_already_completed(): void
    {
        // Cenario race: duas surveys da mesma tupla (company_id,
        // month_reference, template_id) — 1a ja completed. POST no pending
        // duplicado dispara QueryException 23000 no update final via unique
        // parcial (Plan 68-04). Controller captura e renderiza AlreadyCompleted.
        //
        // Este teste NAO revalida detalhes do guard (Phase 69-03 cobre com
        // profundidade em NpsSubmitDynamicValidationTest). Aqui provamos
        // apenas que o fluxo integrado da Phase 71 (form dinamico -> POST)
        // ainda respeita o guard sem 500.
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplate([
            ['dimensao' => 'empresa', 'obrigatoria' => true, 'texto' => 'Como avalia a empresa?'],
        ]);
        $mesRef = now()->startOfMonth()->toDateString();

        // Survey #1 — ja completed no mesmo mes com o mesmo template.
        $surveyDone = $this->criarSurveyV15($empresa, $template, [
            'month_reference' => $mesRef,
        ]);
        $surveyDone->update([
            'status'       => 'completed',
            'completed_at' => now()->subMinutes(10),
        ]);

        // Survey #2 — pending, mesma tupla. Vai colidir no update final.
        $surveyPending = $this->criarSurveyV15($empresa, $template, [
            'month_reference' => $mesRef,
        ]);
        $q1  = $template->questions[0];
        $opt = $this->opcaoComPeso($q1, 5);

        $response = $this->post(route('nps.submit', $surveyPending->token), [
            'respondent_name' => 'Cliente',
            'answers'         => [
                (string) $q1->id => $opt->id,
            ],
        ]);

        // Guard 23000 renderiza AlreadyCompleted (200), nao 500.
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/AlreadyCompleted')
        );

        // Survey pending NAO virou completed (rollback da transacao).
        $surveyPending->refresh();
        $this->assertSame('pending', $surveyPending->status);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — sucesso renderiza Nps/ThankYou + status=completed
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_redireciona_para_thankyou_no_sucesso(): void
    {
        // Redundante com Test 1 no shape de dados, mas separado por SC — SC #4
        // do ROADMAP Phase 71 pede validacao explicita de que "sucesso ->
        // ThankYou preservado". Sem este teste isolado, uma regressao futura
        // que trocasse o component name so quebraria Test 1 (que tambem checa
        // muitas outras coisas) — aqui isolamos o assert de ThankYou.
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplate([
            ['dimensao' => 'geral', 'obrigatoria' => true, 'texto' => 'Nota geral?'],
        ]);
        $survey = $this->criarSurveyV15($empresa, $template);

        $q1  = $template->questions[0];
        $opt = $this->opcaoComPeso($q1, 4);

        $response = $this->post(route('nps.submit', $survey->token), [
            'respondent_name' => 'Cliente Sucesso',
            'answers'         => [
                (string) $q1->id => $opt->id,
            ],
        ]);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/ThankYou')
        );

        // Verdade dupla: status='completed' + completed_at populado.
        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);
    }
}
