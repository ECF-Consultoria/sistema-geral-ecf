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
 * Phase 70 Plan 06 T3 — Suite Feature do CRUD triplo-aninhado de
 * NpsTemplateOption.
 *
 * Cobre SC #3 do ROADMAP Phase 70 (opções + peso 1..5) mais regras críticas
 * do research §5:
 *   - peso entre 1..5 (StoreNpsTemplateOptionRequest bloqueia fora do range)
 *   - ordem inicial = MAX(ordem)+1 na pergunta (posiciona no final)
 *   - destroy última opção de escala retorna 422 (invariante do calculator)
 *   - scoped binding rejeita opção cross-question com 404
 *
 * REQ atendido: NPS-C-04 (opções CRUD).
 *
 * Referências:
 *   - .planning/phases/70-ui-de-configuracao-admin/70-06-PLAN.md (T3)
 *   - app/Http/Controllers/NpsTemplateOptionController.php
 *   - app/Http/Requests/StoreNpsTemplateOptionRequest.php (peso 1..5)
 */
class NpsTemplateOptionCrudTest extends TestCase
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

    /**
     * Helper: cria template + 1 pergunta tipo=opcoes (sem options auto-geradas)
     * pronta para receber options manuais nos testes.
     */
    private function fixtureTemplateComPerguntaOpcoes(): NpsTemplateQuestion
    {
        $template = NpsTemplate::factory()->create();

        return NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Qual sua opção preferida?',
            'tipo'        => 'opcoes',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — store opção com peso válido incrementa ordem
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_store_opcao_com_peso_valido_incrementa_ordem(): void
    {
        // Setup: pergunta opcoes já com 2 options (ordem 1 e 2). Nova option
        // deve entrar com ordem=3 (MAX+1) e peso preservado do payload.
        $pergunta = $this->fixtureTemplateComPerguntaOpcoes();
        $template = $pergunta->template;

        NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'Ruim',
            'peso'        => 1,
            'ordem'       => 1,
        ]);
        NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'Regular',
            'peso'        => 2,
            'ordem'       => 2,
        ]);

        $this->actingAs($this->admin());

        $this->post(
            route('nps.configuracao.templates.perguntas.opcoes.store', [
                'template' => $template,
                'pergunta' => $pergunta,
            ]),
            [
                'label' => 'Ok',
                'peso'  => 3,
            ]
        )->assertRedirect();

        $nova = NpsTemplateOption::where('question_id', $pergunta->id)
            ->where('label', 'Ok')
            ->firstOrFail();

        $this->assertSame(3, $nova->peso);
        $this->assertSame(3, $nova->ordem, 'ordem deveria ser MAX(2)+1 = 3');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — peso fora do range 1..5 retorna 422 (research §5)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_store_opcao_com_peso_fora_range_retorna_422(): void
    {
        // Peso 1..5 é hardcoded no StoreNpsTemplateOptionRequest (research §5).
        // Payload com peso=6 ou peso=0 deve bater na validação antes de
        // chegar no controller. UI React já bloqueia, mas defesa em
        // profundidade contra chamadas cruas no endpoint.
        $pergunta = $this->fixtureTemplateComPerguntaOpcoes();
        $template = $pergunta->template;

        $this->actingAs($this->admin());

        $urlOpcoes = route('nps.configuracao.templates.perguntas.opcoes.store', [
            'template' => $template,
            'pergunta' => $pergunta,
        ]);

        // Peso 6 → 422
        $this->post($urlOpcoes, [
            'label' => 'Excelente',
            'peso'  => 6,
        ])->assertSessionHasErrors(['peso']);

        // Peso 0 → 422
        $this->post($urlOpcoes, [
            'label' => 'Zerado',
            'peso'  => 0,
        ])->assertSessionHasErrors(['peso']);

        // Nenhuma opção foi criada por payload inválido.
        $this->assertSame(
            0,
            NpsTemplateOption::where('question_id', $pergunta->id)->count(),
            'opção com peso inválido não deve ser criada no banco'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — update edita label e peso
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_update_edita_label_e_peso(): void
    {
        // Cenário simples: option existe → PUT com novo label/peso → banco atualizado.
        $pergunta = $this->fixtureTemplateComPerguntaOpcoes();
        $template = $pergunta->template;

        $opcao = NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'Antigo',
            'peso'        => 2,
            'ordem'       => 1,
        ]);

        $this->actingAs($this->admin());

        $this->put(
            route('nps.configuracao.templates.perguntas.opcoes.update', [
                'template' => $template,
                'pergunta' => $pergunta,
                'opcao'    => $opcao,
            ]),
            [
                'label' => 'Novo Label',
                'peso'  => 4,
            ]
        )->assertRedirect();

        $opcao->refresh();
        $this->assertSame('Novo Label', $opcao->label);
        $this->assertSame(4, $opcao->peso);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — destroy option normal apaga (não última de escala)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_destroy_opcao_normal_apaga(): void
    {
        // Pergunta opcoes com 2 options. Delete na primeira → apagada.
        // Segunda continua no banco intacta.
        $pergunta = $this->fixtureTemplateComPerguntaOpcoes();
        $template = $pergunta->template;

        $opcao1 = NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'Primeira',
            'peso'        => 1,
            'ordem'       => 1,
        ]);
        $opcao2 = NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'Segunda',
            'peso'        => 5,
            'ordem'       => 2,
        ]);

        $this->actingAs($this->admin());

        $this->delete(
            route('nps.configuracao.templates.perguntas.opcoes.destroy', [
                'template' => $template,
                'pergunta' => $pergunta,
                'opcao'    => $opcao1,
            ])
        )->assertRedirect();

        $this->assertDatabaseMissing('nps_template_options', ['id' => $opcao1->id]);
        $this->assertDatabaseHas('nps_template_options', ['id' => $opcao2->id]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — destroy última opção de escala retorna 422 (invariante calculator)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_destroy_ultima_opcao_de_escala_retorna_422(): void
    {
        // Setup: pergunta tipo=escala nasce com 5 options auto-geradas. Delete
        // 4 uma por uma → deve funcionar. Delete da última → 422 com mensagem
        // clara. Banco preserva a última opção.
        $template = NpsTemplate::factory()->create();

        $this->actingAs($this->admin());

        // Cria via endpoint pra ter as 5 options auto-geradas do tipo=escala.
        $this->post(
            route('nps.configuracao.templates.perguntas.store', $template),
            [
                'texto'    => 'Pergunta escala com 5 options?',
                'tipo'     => 'escala',
                'dimensao' => 'empresa',
            ]
        )->assertRedirect();

        $pergunta = NpsTemplateQuestion::where('template_id', $template->id)->firstOrFail();
        $options = NpsTemplateOption::where('question_id', $pergunta->id)
            ->orderBy('ordem')
            ->get();

        $this->assertCount(5, $options);

        // Deleta as 4 primeiras — deve funcionar sem restrição.
        for ($i = 0; $i < 4; $i++) {
            $this->delete(
                route('nps.configuracao.templates.perguntas.opcoes.destroy', [
                    'template' => $template,
                    'pergunta' => $pergunta,
                    'opcao'    => $options[$i],
                ])
            )->assertRedirect();
        }

        // Sanity: sobrou exatamente 1 option (a última).
        $ultimaId = $options[4]->id;
        $this->assertSame(
            1,
            NpsTemplateOption::where('question_id', $pergunta->id)->count(),
            'depois de deletar 4, deve sobrar apenas a última option'
        );

        // Delete da última → 422 com mensagem clara pt-BR.
        $response = $this->delete(
            route('nps.configuracao.templates.perguntas.opcoes.destroy', [
                'template' => $template,
                'pergunta' => $pergunta,
                'opcao'    => $options[4],
            ])
        );

        $response->assertStatus(422);
        $response->assertSee('escala precisa ter ao menos 1 opção');

        // Última opção permanece no banco — guard bloqueou o delete.
        $this->assertDatabaseHas('nps_template_options', ['id' => $ultimaId]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 6 — opção de outra pergunta retorna 404 (scoped binding)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_opcao_de_outra_pergunta_retorna_404(): void
    {
        // Setup: template com 2 perguntas. Option pertence à pergunta A.
        // Tenta acessar via URL /templates/{t}/perguntas/{perguntaB}/opcoes/
        // {opcaoA} → 404 (scopeBindings rejeita).
        $template = NpsTemplate::factory()->create();

        $perguntaA = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta A?',
            'tipo'        => 'opcoes',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
        $perguntaB = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta B?',
            'tipo'        => 'opcoes',
            'dimensao'    => 'empresa',
            'obrigatoria' => true,
            'ordem'       => 2,
        ]);

        $opcaoA = NpsTemplateOption::create([
            'question_id' => $perguntaA->id,
            'label'       => 'Da pergunta A',
            'peso'        => 3,
            'ordem'       => 1,
        ]);

        $this->actingAs($this->admin());

        // Tenta PUT em opcaoA via URL scoped à perguntaB → 404.
        $response = $this->put(
            route('nps.configuracao.templates.perguntas.opcoes.update', [
                'template' => $template,
                'pergunta' => $perguntaB, // PERGUNTA ERRADA
                'opcao'    => $opcaoA,
            ]),
            ['label' => 'Não deveria conseguir editar', 'peso' => 5]
        );

        $response->assertStatus(404);

        // Sanity: opção permanece intacta na pergunta A.
        $opcaoA->refresh();
        $this->assertSame('Da pergunta A', $opcaoA->label);
        $this->assertSame($perguntaA->id, $opcaoA->question_id);
    }
}
