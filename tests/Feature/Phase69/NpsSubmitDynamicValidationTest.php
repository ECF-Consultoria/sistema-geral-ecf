<?php

namespace Tests\Feature\Phase69;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 69 Plan 03 — Suite Feature RED do endpoint POST /nps/{token} (submitResponse).
 *
 * Cobre REQs NPS-B-03 (dedup guard 23000) + NPS-B-05 (validacao dinamica derivada
 * do template snapshot associado ao survey).
 *
 * Regras testadas:
 *   1. v15.0 (survey.template_id != null) grava 1 NpsResponseAnswer por pergunta
 *      respondida com colunas snapshot congeladas (question_texto_snapshot,
 *      question_dimensao_snapshot, option_label_snapshot, option_peso_snapshot)
 *   2. Perguntas obrigatorias faltando -> 422 na chave answers.<qid>
 *   3. option_id fora do template (tampering) -> 422 (Rule::in)
 *   4. Pergunta nao obrigatoria omitida -> 200 sem answer daquela pergunta
 *   5. QueryException 23000 (dedup unique parcial Plan 68-04) -> render Nps/AlreadyCompleted
 *   6. Fluxo legacy Phase 31 (template_id=null) preservado — NpsResponse com
 *      score_estrategista/analista/empresa populados, ZERO NpsResponseAnswer
 *   7. v15.0 NAO popula score_* legados — score_estrategista/analista/empresa
 *      permanecem NULL (fonte de verdade migra para nps_response_answers)
 *
 * Setup implicito via RefreshDatabase:
 *   - Migrations Phase 68 (100001 schema + 100003 nullable + 100005 dedup unique)
 *   - Seed "NPS Padrao" (migration 100004)
 *
 * Referencias:
 *   - .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
 *   - .planning/research/v15-nps-templates-schema.md §2 (dedup 23000)
 *   - .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-03-PLAN.md
 *   - app/Http/Controllers/NpsController.php linhas 384-472 (submitResponse atual)
 */
class NpsSubmitDynamicValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — padrao Phase 68/69 (constraint 23000 depende
     * do partial unique index estar ativo).
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria empresa minima para setup dos surveys.
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
     * Cria template v15.0 completo com N perguntas + 5 opcoes por pergunta
     * (escala 1..5). Aceita array de definicoes ['dimensao' => ..., 'obrigatoria' => ...].
     *
     * @return NpsTemplate hidratado com questions->options preloaded
     */
    private function criarTemplateComPerguntas(array $perguntasDef = []): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Teste ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($perguntasDef as $def) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => $def['texto'] ?? 'Pergunta ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $def['dimensao'] ?? NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'obrigatoria' => $def['obrigatoria'] ?? true,
                'ordem'       => $ordem++,
            ]);

            // 5 opcoes escala 1..5
            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        return $template->fresh(['questions.options']);
    }

    /**
     * Cria survey pending com o template dado.
     */
    private function criarSurveyPendente(Company $empresa, ?NpsTemplate $template = null, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template?->id,
        ], $overrides));
    }

    /**
     * Retorna opcao com peso especifico dentro de uma question — util para
     * montar payload determinístico nos testes de snapshot.
     */
    private function opcaoComPeso(NpsTemplateQuestion $q, int $peso): NpsTemplateOption
    {
        return $q->options->firstWhere('peso', $peso);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — snapshot per-row congelado com 1 answer por pergunta
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_grava_1_response_answer_por_pergunta_respondida_com_snapshot(): void
    {
        // Cenario canonico v15.0: template com 3 perguntas cobrindo 3 dimensoes,
        // cada uma com 5 opcoes (peso 1..5). POST responde as 3 e o controller
        // deve criar 1 NpsResponseAnswer por pergunta com snapshot congelado.
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'estrategista', 'texto' => 'Nota do estrategista?'],
            ['dimensao' => 'analista',     'texto' => 'Nota do analista?'],
            ['dimensao' => 'empresa',      'texto' => 'Nota da empresa?'],
        ]);
        $survey = $this->criarSurveyPendente($empresa, $template);

        [$q1, $q2, $q3] = [
            $template->questions[0],
            $template->questions[1],
            $template->questions[2],
        ];

        $optQ1 = $this->opcaoComPeso($q1, 4);
        $optQ2 = $this->opcaoComPeso($q2, 3);
        $optQ3 = $this->opcaoComPeso($q3, 5);

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Teste',
            'comment'         => 'Otimo mes',
            'answers'         => [
                (string) $q1->id => $optQ1->id,
                (string) $q2->id => $optQ2->id,
                (string) $q3->id => $optQ3->id,
            ],
        ]);

        $response->assertOk();

        // 1 NpsResponse + 3 NpsResponseAnswer criados
        $this->assertSame(1, NpsResponse::count());
        $this->assertSame(3, NpsResponseAnswer::count());

        // Cada answer deve ter snapshots congelados batendo com o template
        // no momento da resposta.
        $answersByQuestion = NpsResponseAnswer::all()->keyBy('template_question_id');

        $this->assertEquals('Nota do estrategista?', $answersByQuestion[$q1->id]->question_texto_snapshot);
        $this->assertEquals('estrategista', $answersByQuestion[$q1->id]->question_dimensao_snapshot);
        $this->assertEquals('4', $answersByQuestion[$q1->id]->option_label_snapshot);
        $this->assertSame(4, (int) $answersByQuestion[$q1->id]->option_peso_snapshot);

        $this->assertEquals('analista', $answersByQuestion[$q2->id]->question_dimensao_snapshot);
        $this->assertSame(3, (int) $answersByQuestion[$q2->id]->option_peso_snapshot);

        $this->assertEquals('empresa', $answersByQuestion[$q3->id]->question_dimensao_snapshot);
        $this->assertSame(5, (int) $answersByQuestion[$q3->id]->option_peso_snapshot);

        // Survey marcado como completed
        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);

        // Snapshot imutabilidade: se admin edita o texto da pergunta depois,
        // o snapshot NAO muda (verdade historica preservada — research §1).
        $q1->update(['texto' => 'PERGUNTA MODIFICADA']);
        $q1->options->first()->update(['label' => 'X']);

        $this->assertEquals(
            'Nota do estrategista?',
            $answersByQuestion[$q1->id]->fresh()->question_texto_snapshot,
            'snapshot deve continuar congelado apos edicao futura do template'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — pergunta obrigatoria ausente na payload -> 422
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_valida_obrigatoria_via_template_snapshot(): void
    {
        // Template tem 1 pergunta obrigatoria=true. POST sem essa answer -> 422
        // com erro em answers.<qid>. Nada gravado no DB.
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'estrategista', 'obrigatoria' => true],
        ]);
        $survey = $this->criarSurveyPendente($empresa, $template);
        $q1     = $template->questions[0];

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente',
            // answers omitido intencionalmente
        ]);

        // Inertia retorna 302 com erros de validacao
        $response->assertStatus(302);
        $response->assertSessionHasErrors("answers.{$q1->id}");

        $this->assertSame(0, NpsResponse::count());
        $this->assertSame(0, NpsResponseAnswer::count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — option_id fora do template -> 422 (Rule::in)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_valida_option_id_pertence_ao_template(): void
    {
        // 2 templates diferentes. POST no survey do template A tenta usar
        // option_id do template B -> Rule::in bloqueia com 422.
        $empresa   = $this->criarEmpresa();
        $templateA = $this->criarTemplateComPerguntas([
            ['dimensao' => 'empresa'],
        ]);
        $templateB = $this->criarTemplateComPerguntas([
            ['dimensao' => 'empresa'],
        ]);

        $survey   = $this->criarSurveyPendente($empresa, $templateA);
        $qA       = $templateA->questions[0];
        $optionB  = $templateB->questions[0]->options[0]; // pertence a OUTRO template

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente',
            'answers'         => [
                (string) $qA->id => $optionB->id, // tampering: option de outro template
            ],
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors("answers.{$qA->id}");

        $this->assertSame(0, NpsResponse::count());
        $this->assertSame(0, NpsResponseAnswer::count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — pergunta nao obrigatoria omitida -> 200 sem answer
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_permite_pergunta_nao_obrigatoria_omitida(): void
    {
        // Template com 2 perguntas: uma obrigatoria + uma opcional. Cliente
        // responde apenas a obrigatoria. Deve criar 1 NpsResponse + 1 answer
        // (nao 2). Nenhum erro de validacao.
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'estrategista', 'obrigatoria' => true],
            ['dimensao' => 'empresa',      'obrigatoria' => false],
        ]);
        $survey = $this->criarSurveyPendente($empresa, $template);

        $qObrig  = $template->questions[0];
        $qOpc    = $template->questions[1];
        $optObrig = $this->opcaoComPeso($qObrig, 4);

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente',
            'answers'         => [
                (string) $qObrig->id => $optObrig->id,
                // qOpc omitida intencionalmente
            ],
        ]);

        $response->assertOk();

        $this->assertSame(1, NpsResponse::count());
        $this->assertSame(1, NpsResponseAnswer::count());

        // Verifica que a unica answer criada e a da pergunta obrigatoria.
        $answer = NpsResponseAnswer::first();
        $this->assertSame($qObrig->id, $answer->template_question_id);
        $this->assertNull(
            NpsResponseAnswer::where('template_question_id', $qOpc->id)->first(),
            'pergunta opcional omitida nao deve gerar answer'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — QueryException 23000 -> render Nps/AlreadyCompleted
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_captura_query_exception_23000_e_renderiza_already_completed(): void
    {
        // Cenario race: 2 surveys da MESMA tupla (company_id, month_reference,
        // template_id) — 1 ja completed. POST no pending duplicado dispara
        // QueryException 23000 no update status='completed' via dedup unique
        // parcial (Plan 68-04). Controller captura e renderiza AlreadyCompleted.
        //
        // Se o guard nao existe, o teste vai receber 500 (uncaught exception)
        // — nao 200.
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'empresa', 'obrigatoria' => true],
        ]);
        $mesRef = now()->startOfMonth()->toDateString();

        // Survey #1 — ja completed no mesmo mes com o mesmo template.
        $surveyCompleted = $this->criarSurveyPendente($empresa, $template, [
            'month_reference' => $mesRef,
            'auto_generated'  => true,
        ]);
        $surveyCompleted->update([
            'status'       => 'completed',
            'completed_at' => now()->subMinutes(5),
        ]);

        // Survey #2 — pending, mesma tupla. Vai colidir no update final.
        $surveyPending = $this->criarSurveyPendente($empresa, $template, [
            'month_reference' => $mesRef,
            'auto_generated'  => true,
        ]);
        $q1  = $template->questions[0];
        $opt = $this->opcaoComPeso($q1, 5);

        $response = $this->post("/nps/{$surveyPending->token}", [
            'respondent_name' => 'Cliente',
            'answers'         => [
                (string) $q1->id => $opt->id,
            ],
        ]);

        // Guard 23000 deve renderizar Nps/AlreadyCompleted (200), nao 500.
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Nps/AlreadyCompleted'));

        // Survey pending NAO virou completed (rollback da transacao pelo throw).
        $surveyPending->refresh();
        $this->assertSame('pending', $surveyPending->status);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 6 — legacy Phase 31 (template_id=null) fluxo antigo preservado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v13_legacy_survey_sem_template_id_preserva_fluxo_phase31(): void
    {
        // Survey criado sem template_id (row legacy Phase 31/33). POST com
        // payload Phase 31 (3 scores hardcoded) -> mantido 100% do fluxo:
        //   - NpsResponse com score_estrategista/analista/empresa populados
        //   - ZERO NpsResponseAnswer (nao usa via nova)
        //   - Survey vira completed
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa, null); // template_id=null

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name'    => 'Cliente Legacy',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => 'Fluxo antigo',
        ]);

        $response->assertOk();

        // Row Phase 31 completo
        $this->assertDatabaseHas('nps_responses', [
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Legacy',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => 'Fluxo antigo',
        ]);

        // ZERO NpsResponseAnswer — fluxo legacy nao usa via nova.
        $this->assertSame(0, NpsResponseAnswer::count());

        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 7 — v15.0 NAO popula score_* legados
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_v15_scores_legados_score_estrategista_etc_permanecem_null(): void
    {
        // Fonte de verdade v15.0 e nps_response_answers — NpsResponse criado
        // pelo submitResponseV15 deve ter score_estrategista/analista/empresa
        // NULL (Phase 68 Plan 01 fez as colunas NULLABLE justamente para isso).
        // Dashboards NPS-E-01..04 legados continuam funcionando em rows Phase 31,
        // dashboards NPS-E-05 novos leem answers.
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'estrategista', 'obrigatoria' => true],
            ['dimensao' => 'empresa',      'obrigatoria' => true],
        ]);
        $survey = $this->criarSurveyPendente($empresa, $template);

        $qEst = $template->questions[0];
        $qEmp = $template->questions[1];

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente v15',
            'answers'         => [
                (string) $qEst->id => $this->opcaoComPeso($qEst, 5)->id,
                (string) $qEmp->id => $this->opcaoComPeso($qEmp, 4)->id,
            ],
        ]);

        $response->assertOk();

        $npsResponse = NpsResponse::first();
        $this->assertNotNull($npsResponse);
        $this->assertNull($npsResponse->score_estrategista, 'v15.0 deve deixar score_estrategista NULL');
        $this->assertNull($npsResponse->score_analista,     'v15.0 deve deixar score_analista NULL');
        $this->assertNull($npsResponse->score_empresa,      'v15.0 deve deixar score_empresa NULL');

        // Enquanto isso, as answers estao populadas — fonte de verdade.
        $this->assertSame(2, NpsResponseAnswer::count());
    }
}
