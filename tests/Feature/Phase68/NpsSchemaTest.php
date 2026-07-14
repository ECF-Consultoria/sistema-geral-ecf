<?php

namespace Tests\Feature\Phase68;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 68 Plan 05 — Suite Feature do schema completo NPS Templates v15.0.
 *
 * Valida os SC #1, #2 e #4 do ROADMAP:
 *  - SC #1: 5 tabelas novas com FKs/indices/constraints observaveis
 *  - SC #2: relationships Eloquent funcionais (indireto via factories chain)
 *  - SC #4: snapshot per-row preservado apos edicao/delete do template
 *
 * Rede de seguranca DB-level — antes que qualquer plan da Phase 69 toque
 * codigo, esta suite prova que a fundacao esta solida. Testes focam em
 * contrato observavel (Schema::has*, insert com bypass, exception esperada,
 * count antes/depois de delete).
 *
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-05-PLAN.md
 * @see database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php
 * @see database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php
 */
class NpsSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante que FKs SQLite estao ativas em cada teste. O config
     * `foreign_key_constraints=true` (config/database.php) ja liga isso por
     * default, mas Phase 40 estabeleceu o padrao de reforcar explicitamente
     * antes de cada teste de cascade — evita surpresas se o driver mudar.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #1 — 5 tabelas novas com colunas + FKs + indices
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_5_tabelas_novas_existem(): void
    {
        $this->assertTrue(Schema::hasTable('nps_templates'),                'nps_templates deveria existir');
        $this->assertTrue(Schema::hasTable('nps_template_questions'),       'nps_template_questions deveria existir');
        $this->assertTrue(Schema::hasTable('nps_template_options'),         'nps_template_options deveria existir');
        $this->assertTrue(Schema::hasTable('nps_template_service_scopes'),  'nps_template_service_scopes deveria existir');
        $this->assertTrue(Schema::hasTable('nps_response_answers'),         'nps_response_answers deveria existir');
    }

    #[Test]
    public function test_nps_surveys_tem_template_id_nullable(): void
    {
        // SC #1 parte 2 — coluna template_id deve existir e ser NULLABLE.
        // Rows legadas (Phase 31-33) coexistem com NULL antes do seed retro.
        $this->assertTrue(
            Schema::hasColumn('nps_surveys', 'template_id'),
            'nps_surveys.template_id deveria existir apos migration 100002'
        );

        // Insert direto sem template_id nao pode disparar exception.
        // Bypass do Model + factory pra provar comportamento no DB puro.
        $company = Company::factory()->create();

        NpsSurvey::create([
            'token'          => (string) Str::uuid(),
            'company_id'     => $company->id,
            'status'         => 'pending',
            'expires_at'     => now()->addDays(7),
            'auto_generated' => false,
            'template_id'    => null,
        ]);

        $this->assertDatabaseHas('nps_surveys', [
            'company_id'  => $company->id,
            'template_id' => null,
        ]);
    }

    #[Test]
    public function test_nps_responses_scores_sao_nullable(): void
    {
        // SC #1 parte 3 — score_estrategista e score_empresa viraram NULLABLE
        // (migration 100003). score_analista ja era nullable desde Phase 31.
        // A partir da Phase 69, novas responses gravam NULL nessas colunas e
        // persistem em nps_response_answers (snapshot per-row).
        $response = NpsResponse::factory()->create([
            'score_estrategista' => null,
            'score_analista'     => null,
            'score_empresa'      => null,
        ]);

        $this->assertNull($response->score_estrategista);
        $this->assertNull($response->score_analista);
        $this->assertNull($response->score_empresa);

        $this->assertDatabaseHas('nps_responses', [
            'id'                 => $response->id,
            'score_estrategista' => null,
            'score_empresa'      => null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #2 — Relationships Eloquent funcionais (via factory chain)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_relationships_nps_template(): void
    {
        // Template hasMany Questions + Question hasMany Options + belongsToMany servicos.
        // Testa a chain completa via factory nested (Plan 68-02 provou que ->has()
        // funciona em 2 niveis) — se qualquer relation quebrar, count falha.
        $template = NpsTemplate::factory()
            ->has(
                NpsTemplateQuestion::factory()
                    ->count(3)
                    ->has(NpsTemplateOption::factory()->count(5), 'options'),
                'questions'
            )
            ->create();

        $this->assertEquals(3, $template->questions()->count(), 'template hasMany questions com count=3');

        foreach ($template->questions as $q) {
            $this->assertEquals(5, $q->options()->count(), 'question hasMany options com count=5');
        }

        // belongsTo inverso: question->template retorna o mesmo template.
        $primeira = $template->questions->first();
        $this->assertEquals($template->id, $primeira->template->id);

        // belongsToMany servicos — retorna Collection vazia se nao houver pivot;
        // basta provar que a relation nao explode ao ser chamada.
        $this->assertCount(0, $template->servicos, 'servicos() vazio por default');
        $this->assertCount(0, $template->serviceScopes, 'serviceScopes() alias funciona');
    }

    #[Test]
    public function test_cascade_deletes_functional(): void
    {
        // SC #1 parte 4 — FK cascade em nps_template_questions.template_id
        // e nps_template_options.question_id. Apagar template dispara cascade
        // que dropa toda a arvore (questions + options).
        //
        // Nota: o seed migration 100004 ja cria 1 template padrao com 3 questions
        // e 15 options. Este teste escopa contagens ao template criado no
        // proprio teste — nao afetamos o seed.
        $template = NpsTemplate::factory()
            ->has(
                NpsTemplateQuestion::factory()
                    ->count(2)
                    ->has(NpsTemplateOption::factory()->count(3), 'options'),
                'questions'
            )
            ->create();

        $questionIds = $template->questions->pluck('id')->all();

        $this->assertEquals(2, NpsTemplateQuestion::where('template_id', $template->id)->count());
        $this->assertEquals(
            6,
            NpsTemplateOption::whereIn('question_id', $questionIds)->count(),
            '2 questions x 3 options do template criado'
        );

        $template->delete();

        // Todas as questions do template dropadas (cascadeOnDelete).
        foreach ($questionIds as $qid) {
            $this->assertDatabaseMissing('nps_template_questions', ['id' => $qid]);
        }
        // Options cascade via question_id.
        $this->assertEquals(
            0,
            NpsTemplateOption::whereIn('question_id', $questionIds)->count(),
            'cascade em question deveria dropar options'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #4 — Snapshot per-row preservado apos delete/edit do template
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_response_answer_null_on_delete_da_question_com_snapshot_preservado(): void
    {
        // SC #4 — nullOnDelete em nps_response_answers.template_question_id.
        // Se a pergunta original for hard-deleted, a FK viva vira NULL, MAS
        // o snapshot (question_texto_snapshot + question_dimensao_snapshot)
        // PERMANECE INTACTO — snapshot e fonte de verdade historica.
        $company = Company::factory()->create();
        $survey  = NpsSurvey::factory()->create(['company_id' => $company->id]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        $template = NpsTemplate::factory()->create();
        $question = NpsTemplateQuestion::factory()
            ->for($template, 'template')
            ->create(['texto' => 'Texto ORIGINAL da pergunta']);

        $answer = NpsResponseAnswer::factory()->create([
            'response_id'                => $response->id,
            'template_question_id'       => $question->id,
            'question_texto_snapshot'    => 'Texto ORIGINAL da pergunta',
            'question_dimensao_snapshot' => 'empresa',
            'option_peso_snapshot'       => 4,
        ]);

        // Sanity: FK viva populada.
        $this->assertNotNull($answer->fresh()->template_question_id);

        // Hard-delete da pergunta.
        $question->delete();

        // FK viva zerada (nullOnDelete).
        $answer->refresh();
        $this->assertNull(
            $answer->template_question_id,
            'nullOnDelete deveria zerar template_question_id apos hard-delete da pergunta'
        );

        // Snapshot INTACTO — fonte de verdade historica preservada.
        $this->assertEquals('Texto ORIGINAL da pergunta', $answer->question_texto_snapshot);
        $this->assertEquals('empresa', $answer->question_dimensao_snapshot);
        $this->assertEquals(4, $answer->option_peso_snapshot);
    }

    #[Test]
    public function test_snapshot_preservado_apos_edit_do_texto_da_question(): void
    {
        // SC #4 (parte 2) — snapshot congelado NAO acompanha UPDATE no template.
        // Cenario: admin edita o texto de uma pergunta apos historico ter sido
        // gerado. Dashboards NPS-E-05 devem continuar exibindo o texto que o
        // cliente REALMENTE viu no momento da resposta, nao o texto atual.
        $template = NpsTemplate::factory()->create();
        $question = NpsTemplateQuestion::factory()
            ->for($template, 'template')
            ->create(['texto' => 'Texto ORIGINAL']);

        $response = NpsResponse::factory()->create();
        $answer   = NpsResponseAnswer::factory()->create([
            'response_id'                => $response->id,
            'template_question_id'       => $question->id,
            'question_texto_snapshot'    => 'Texto ORIGINAL',
            'question_dimensao_snapshot' => 'empresa',
        ]);

        // Admin edita o texto da pergunta (simula CRUD Phase 70).
        $question->update(['texto' => 'Texto EDITADO pelo admin']);

        // Snapshot na answer NAO deve mudar — congelamento e imutavel.
        $answer->refresh();
        $this->assertEquals(
            'Texto ORIGINAL',
            $answer->question_texto_snapshot,
            'snapshot deve permanecer congelado mesmo apos update no template'
        );

        // FK viva ainda aponta pra pergunta (nao foi deletada, apenas editada).
        $this->assertEquals($question->id, $answer->template_question_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Unique parcial em is_default (Plan 68-01)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_unique_parcial_is_default_bloqueia_segundo_template_padrao(): void
    {
        // O seed migration 100004 ja criou 1 template com is_default=true
        // ("NPS Padrao"). Qualquer tentativa de inserir um 2o com is_default=true
        // dispara QueryException (SQLite: partial unique index; MySQL: virtual
        // generated column + unique).
        $this->assertEquals(
            1,
            NpsTemplate::where('is_default', true)->count(),
            'baseline: seed criou 1 template default'
        );

        $this->expectException(QueryException::class);

        // ->padrao() seta is_default=true — deve colidir com o seed.
        NpsTemplate::factory()->padrao()->create();
    }

    #[Test]
    public function test_multiplos_templates_nao_default_coexistem_sem_erro(): void
    {
        // Sanity: unique parcial NAO bloqueia rows com is_default=false.
        // Estado inicial: 1 template default (seed "NPS Padrão") + 1 nao-default
        // (seed "NPS Shopee", Phase 79). Contamos o baseline de nao-default antes
        // de adicionar mais 3 pra o teste ser robusto a seeds aditivos.
        $naoDefaultBaseline = NpsTemplate::where('is_default', false)->count();

        NpsTemplate::factory()->count(3)->create(['is_default' => false]);

        $this->assertEquals(1, NpsTemplate::where('is_default', true)->count());
        $this->assertEquals($naoDefaultBaseline + 3, NpsTemplate::where('is_default', false)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Unique parcial dedup_key em nps_surveys (Plan 68-04)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dedup_parcial_bloqueia_segunda_survey_completed_mesma_tupla(): void
    {
        // Plan 68-04 unique parcial: bloqueia INSERT/UPDATE de 2a survey COMPLETED
        // com mesma (company_id, month_reference, template_id). Pending/expired
        // ficam livres (validado no teste seguinte).
        $company  = Company::factory()->create();
        // Reusa o template padrao ja seedado — evita colisao com unique de is_default.
        $template = NpsTemplate::default()->first();
        $this->assertNotNull($template, 'template padrao deveria estar seedado');

        // Survey #1 completed — passa.
        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'completed',
            'completed_at'    => now(),
        ]);

        $this->expectException(QueryException::class);

        // Survey #2 completed identica — deve disparar QueryException 23xxx.
        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'completed',
            'completed_at'    => now(),
        ]);
    }

    #[Test]
    public function test_dedup_parcial_permite_surveys_pending_mesma_tupla(): void
    {
        // Semantica intencional: dedup so bloqueia rows COMPLETED. Surveys
        // pending/expired coexistem livremente — o nps:disparar-mensal
        // permanece idempotente e podemos criar novas surveys enquanto a
        // anterior nao foi respondida.
        $company  = Company::factory()->create();
        $template = NpsTemplate::default()->first();

        // 2 surveys PENDING com tupla identica — deve permitir.
        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'pending',
            'completed_at'    => null,
        ]);
        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'pending',
            'completed_at'    => null,
        ]);

        // Bonus sanity: 1 COMPLETED em template diferente NAO colide (dedup
        // e composto — mudar 1 componente libera nova insercao).
        $outroTpl = NpsTemplate::factory()->create();
        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $outroTpl->id,
            'month_reference' => '2026-07-01',
            'status'          => 'completed',
            'completed_at'    => now(),
        ]);

        $this->assertEquals(
            3,
            NpsSurvey::where('company_id', $company->id)->count(),
            '2 pending + 1 completed em template distinto = 3 surveys convivendo'
        );
    }
}
