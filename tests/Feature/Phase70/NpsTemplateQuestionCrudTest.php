<?php

namespace Tests\Feature\Phase70;

use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 70 Plan 06 T2 — Suite Feature do CRUD aninhado de NpsTemplateQuestion.
 *
 * Cobre SC #2 do ROADMAP Phase 70 (perguntas CRUD) mais regras críticas do
 * research §5:
 *   - tipo=escala auto-gera 5 options peso 1..5 na MESMA transação
 *   - tipo=opcoes nasce sem options (admin adiciona via Plan 70-03)
 *   - ordem inicial = MAX(ordem)+1 (posiciona no final da lista)
 *   - tipo é IMUTÁVEL após criação (UpdateNpsTemplateQuestionRequest omite)
 *   - cascade delete apaga options junto com pergunta
 *   - mover swap troca ordem com vizinha O(1)
 *   - scoped binding rejeita pergunta cross-template com 404
 *
 * REQ atendido: NPS-C-04 (perguntas CRUD).
 *
 * Referências:
 *   - .planning/phases/70-ui-de-configuracao-admin/70-06-PLAN.md (T2)
 *   - app/Http/Controllers/NpsTemplateQuestionController.php
 *   - .planning/research/v15-nps-templates-schema.md §5 (tipo escala|opcoes)
 */
class NpsTemplateQuestionCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — tipo=escala auto-gera 5 options peso 1..5 (research §5)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_store_pergunta_tipo_escala_auto_gera_5_options_com_pesos_1_a_5(): void
    {
        // Cenário canônico: admin cria pergunta escala → controller cria a
        // pergunta + 5 NpsTemplateOption em transação atômica. Se qualquer
        // insert falhar, rollback total (invariante "escala tem 5 opções").
        $template = NpsTemplate::factory()->create();

        $this->actingAs($this->admin());

        $response = $this->post(
            route('nps.configuracao.templates.perguntas.store', $template),
            [
                'texto'       => 'Como você avalia o atendimento do estrategista?',
                'tipo'        => 'escala',
                'dimensao'    => 'estrategista',
                'obrigatoria' => true,
            ]
        );

        $response->assertRedirect();

        // 1 pergunta CRIADA para o template de teste + 5 options em cadeia
        // (auto-geração escala). Escopo por template — o seed NPS Padrão da
        // migration 100004 também tem perguntas/options e não deve interferir.
        $this->assertSame(1, NpsTemplateQuestion::where('template_id', $template->id)->count());
        $pergunta = NpsTemplateQuestion::where('template_id', $template->id)->firstOrFail();
        $this->assertSame(5, NpsTemplateOption::where('question_id', $pergunta->id)->count());

        // Pesos 1..5 com labels equivalentes — invariante research §5.
        for ($peso = 1; $peso <= 5; $peso++) {
            $this->assertDatabaseHas('nps_template_options', [
                'question_id' => $pergunta->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — tipo=opcoes nasce SEM options (admin adiciona via Plan 70-03)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_store_pergunta_tipo_opcoes_nasce_sem_opcoes(): void
    {
        // tipo=opcoes → controller NÃO auto-gera nada. Admin popula depois
        // via NpsTemplateOptionController.
        $template = NpsTemplate::factory()->create();

        $this->actingAs($this->admin());

        $this->post(
            route('nps.configuracao.templates.perguntas.store', $template),
            [
                'texto'       => 'Qual canal você mais utiliza?',
                'tipo'        => 'opcoes',
                'dimensao'    => 'empresa',
                'obrigatoria' => false,
            ]
        )->assertRedirect();

        // Escopo por template (o seed NPS Padrão tem perguntas próprias).
        $this->assertSame(1, NpsTemplateQuestion::where('template_id', $template->id)->count());
        $pergunta = NpsTemplateQuestion::where('template_id', $template->id)->firstOrFail();
        $this->assertSame(
            0,
            NpsTemplateOption::where('question_id', $pergunta->id)->count(),
            'tipo=opcoes NÃO deve auto-gerar options — admin popula depois'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — ordem inicial = MAX(ordem)+1 (posiciona no final)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_store_pergunta_calcula_ordem_max_mais_um(): void
    {
        // Setup: template com 2 perguntas ordem 1 e 2. Nova pergunta deve
        // entrar com ordem=3 (final da lista). Bate no pattern MAX(ordem)+1
        // do controller (Phase 33 e Plan 70-02).
        $template = NpsTemplate::factory()->create();

        NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Primeira pergunta?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Segunda pergunta?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 2,
        ]);

        $this->actingAs($this->admin());

        $this->post(
            route('nps.configuracao.templates.perguntas.store', $template),
            [
                'texto'    => 'Terceira pergunta criada?',
                'tipo'     => 'opcoes',
                'dimensao' => 'geral',
            ]
        )->assertRedirect();

        $terceira = NpsTemplateQuestion::where('texto', 'Terceira pergunta criada?')->firstOrFail();
        $this->assertSame(3, $terceira->ordem, 'ordem deveria ser MAX(2)+1 = 3');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — update ignora tipo (imutável após criação, research §5)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_update_edita_texto_dimensao_mas_ignora_tipo(): void
    {
        // Cenário: pergunta tipo=escala existe. PUT tenta mudar tipo=opcoes.
        // UpdateNpsTemplateQuestionRequest não tem `tipo` no rules() →
        // validated() filtra → Model::update ignora → tipo continua escala.
        $template = NpsTemplate::factory()->create();
        $pergunta = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Texto original?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        $this->actingAs($this->admin());

        $this->put(
            route('nps.configuracao.templates.perguntas.update', [
                'template' => $template,
                'pergunta' => $pergunta,
            ]),
            [
                'texto'    => 'Texto editado?',
                'dimensao' => 'analista',
                'tipo'     => 'opcoes', // tentativa maliciosa — deve ser ignorada
            ]
        )->assertRedirect();

        $pergunta->refresh();
        $this->assertSame('Texto editado?', $pergunta->texto);
        $this->assertSame('analista', $pergunta->dimensao);
        $this->assertSame(
            'escala',
            $pergunta->tipo,
            'tipo deveria continuar "escala" — imutável após criação (research §5)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — destroy pergunta apaga options via cascade FK
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_destroy_pergunta_apaga_options_via_cascade(): void
    {
        // Setup: pergunta escala com 5 options auto-geradas. DELETE na
        // pergunta → cascade FK apaga as 5 options automaticamente.
        $template = NpsTemplate::factory()->create();

        $this->actingAs($this->admin());

        // Cria via endpoint pra usar o mesmo caminho de auto-geração de options.
        $this->post(
            route('nps.configuracao.templates.perguntas.store', $template),
            [
                'texto'    => 'Pergunta a ser deletada?',
                'tipo'     => 'escala',
                'dimensao' => 'empresa',
            ]
        )->assertRedirect();

        $pergunta = NpsTemplateQuestion::where('template_id', $template->id)->firstOrFail();
        $this->assertSame(
            5,
            NpsTemplateOption::where('question_id', $pergunta->id)->count(),
            'pergunta criada deveria ter 5 options auto-geradas antes do destroy'
        );

        $this->delete(
            route('nps.configuracao.templates.perguntas.destroy', [
                'template' => $template,
                'pergunta' => $pergunta,
            ])
        )->assertRedirect();

        $this->assertDatabaseMissing('nps_template_questions', ['id' => $pergunta->id]);
        $this->assertSame(
            0,
            NpsTemplateOption::where('question_id', $pergunta->id)->count(),
            'cascade FK deveria ter apagado as 5 options junto com a pergunta'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 6 — mover swap troca ordem com vizinha (pattern Phase 33)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mover_swap_troca_ordem_com_vizinha(): void
    {
        // Setup: 3 perguntas ordem 1, 2, 3. Mover a do meio "up" → troca com
        // a de ordem 1 → agora a de ordem 1 tem ordem 2 e vice-versa.
        $template = NpsTemplate::factory()->create();

        $p1 = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'P1?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
        $p2 = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'P2?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 2,
        ]);
        $p3 = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'P3?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 3,
        ]);

        $this->actingAs($this->admin());

        // Mover p2 up → swap com p1. Depois: p1.ordem=2, p2.ordem=1, p3.ordem=3.
        $this->post(
            route('nps.configuracao.templates.perguntas.mover', [
                'template' => $template,
                'pergunta' => $p2,
            ]),
            ['direcao' => 'up']
        )->assertRedirect();

        $this->assertSame(2, $p1->fresh()->ordem);
        $this->assertSame(1, $p2->fresh()->ordem);
        $this->assertSame(3, $p3->fresh()->ordem, 'p3 não deve ser tocada');

        // Segundo swap — mover p1 (agora ordem=2) up → deve trocar de novo
        // com p2 (agora ordem=1). Depois: p1.ordem=1, p2.ordem=2.
        $this->post(
            route('nps.configuracao.templates.perguntas.mover', [
                'template' => $template,
                'pergunta' => $p1,
            ]),
            ['direcao' => 'up']
        )->assertRedirect();

        $this->assertSame(1, $p1->fresh()->ordem, 'p1 voltou pra ordem original');
        $this->assertSame(2, $p2->fresh()->ordem);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 7 — scoped binding rejeita pergunta cross-template com 404
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_pergunta_de_outro_template_retorna_404_via_scoped_binding(): void
    {
        // Setup: templates A e B. Pergunta pertence ao A. Tentar acessar via
        // URL /templates/{B}/perguntas/{perguntaA} deve retornar 404 —
        // scopeBindings() do Laravel adiciona WHERE template_id = B automaticamente.
        $templateA = NpsTemplate::factory()->create(['nome' => 'Template A']);
        $templateB = NpsTemplate::factory()->create(['nome' => 'Template B']);

        $perguntaA = NpsTemplateQuestion::create([
            'template_id' => $templateA->id,
            'texto'       => 'Pergunta do Template A?',
            'tipo'        => 'escala',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        $this->actingAs($this->admin());

        // Tenta PUT em pergunta A via URL scoped ao template B → 404.
        $response = $this->put(
            route('nps.configuracao.templates.perguntas.update', [
                'template' => $templateB, // TEMPLATE ERRADO
                'pergunta' => $perguntaA,
            ]),
            ['texto' => 'Não deveria conseguir editar?']
        );

        $response->assertStatus(404);

        // Sanity: pergunta permanece intacta no template A.
        $perguntaA->refresh();
        $this->assertSame('Pergunta do Template A?', $perguntaA->texto);
        $this->assertSame($templateA->id, $perguntaA->template_id);
    }
}
