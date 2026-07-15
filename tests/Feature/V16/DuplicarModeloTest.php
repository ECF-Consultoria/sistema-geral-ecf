<?php

namespace Tests\Feature\V16;

use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 81 Plan 01 (DEC-81-1) — DUPLICAR modelo NPS.
 *
 * Prova que o `NpsTemplateController@duplicate` clona, em transação, um modelo
 * completo:
 *  1. novo NpsTemplate com nome "{nome} (cópia)" e is_default=false;
 *  2. TODAS as perguntas (texto/tipo/dimensao/obrigatoria/ordem) + opções
 *     (label/peso/ordem) do original;
 *  3. o pivot nps_template_service_scopes com os mesmos servico_id;
 *  4. duplicar o modelo PRINCIPAL (is_default=true) produz clone is_default=false
 *     — sem colidir no unique parcial (QueryException 23000);
 *  5. o modelo ORIGINAL permanece intocado.
 *
 * @see .planning/phases/81-.../81-01-PLAN.md
 */
class DuplicarModeloTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — cascade dos scopes/perguntas depende disso.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Loga um admin (role=admin cobre o grupo role:admin das rotas de templates).
     */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Monta um template com 2 perguntas (a 1ª tipo escala com 5 opções, a 2ª
     * texto livre sem opções) + 1 serviço no pivot. Retorna o template hidratado.
     */
    private function criarTemplateComArvore(array $overrides = []): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(array_merge([
            'nome'   => 'NPS | Performance ECF',
            'active' => true,
        ], $overrides));

        // Pergunta 1 — escala com 5 opções.
        $q1 = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Como avalia o analista?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_ANALISTA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $q1->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        // Pergunta 2 — sem opções (prova que o clone lida com pergunta vazia).
        NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Deixe um comentário',
            'tipo'        => NpsTemplateQuestion::TIPO_OPCOES,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_GERAL,
            'obrigatoria' => false,
            'ordem'       => 2,
        ]);

        // 1 serviço no pivot.
        $servicoId = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template->servicos()->attach($servicoId);

        return $template->fresh(['questions.options', 'servicos']);
    }

    #[Test]
    public function test_duplicate_clona_arvore_completa_com_is_default_false(): void
    {
        $this->actingAsAdmin();
        // Baseline conta os modelos seedados por migration (NPS Padrão + Shopee).
        $baseline = NpsTemplate::count();

        $original = $this->criarTemplateComArvore();

        $response = $this->post(route('nps.configuracao.templates.duplicate', $original->id));
        $response->assertRedirect();

        // Um novo template foi criado (baseline + original + clone).
        $this->assertSame($baseline + 2, NpsTemplate::count());

        $clone = NpsTemplate::where('nome', $original->nome . ' (cópia)')->firstOrFail();

        // Nome com "(cópia)" e is_default=false.
        $this->assertSame('NPS | Performance ECF (cópia)', $clone->nome);
        $this->assertFalse($clone->is_default);

        // Herda active/priority/envio do original.
        $this->assertSame($original->active, $clone->active);
        $this->assertSame($original->priority, $clone->priority);

        // Mesma contagem de perguntas/opções/scopes.
        $this->assertSame(
            $original->questions()->count(),
            $clone->questions()->count(),
            'clone deve ter a mesma quantidade de perguntas'
        );
        $this->assertSame(
            NpsTemplateOption::whereIn('question_id', $original->questions()->pluck('id'))->count(),
            NpsTemplateOption::whereIn('question_id', $clone->questions()->pluck('id'))->count(),
            'clone deve ter a mesma quantidade de opções'
        );
        $this->assertSame(
            $original->servicos()->count(),
            $clone->servicos()->count(),
            'clone deve ter os mesmos service scopes'
        );

        // O pivot do clone aponta para os mesmos servico_id.
        $this->assertEquals(
            $original->servicos()->pluck('servicos.id')->sort()->values()->all(),
            $clone->servicos()->pluck('servicos.id')->sort()->values()->all()
        );

        // Perguntas do clone pertencem ao clone (não ao original).
        foreach ($clone->questions as $q) {
            $this->assertSame($clone->id, $q->template_id);
        }
    }

    #[Test]
    public function test_duplicate_do_principal_nao_gera_segundo_is_default(): void
    {
        $this->actingAsAdmin();
        // Usa o modelo principal seedado por migration (is_default=true) — só
        // pode existir um (unique parcial), então não criamos outro.
        $principal = NpsTemplate::where('is_default', true)->firstOrFail();

        // Não deve estourar QueryException 23000 (unique parcial).
        $this->post(route('nps.configuracao.templates.duplicate', $principal->id))
            ->assertRedirect();

        // Continua existindo apenas UM is_default=true (o original principal).
        $this->assertSame(1, NpsTemplate::where('is_default', true)->count());

        $clone = NpsTemplate::where('nome', $principal->nome . ' (cópia)')->firstOrFail();
        $this->assertFalse($clone->is_default);
    }

    #[Test]
    public function test_duplicate_nao_altera_o_modelo_original(): void
    {
        $this->actingAsAdmin();
        $original = $this->criarTemplateComArvore();

        $nomeOriginal   = $original->nome;
        $perguntasAntes = $original->questions()->count();
        $opcoesAntes    = NpsTemplateOption::whereIn('question_id', $original->questions()->pluck('id'))->count();
        $scopesAntes    = $original->servicos()->count();

        $this->post(route('nps.configuracao.templates.duplicate', $original->id))->assertRedirect();

        $original->refresh();
        $this->assertSame($nomeOriginal, $original->nome);
        $this->assertSame($perguntasAntes, $original->questions()->count());
        $this->assertSame(
            $opcoesAntes,
            NpsTemplateOption::whereIn('question_id', $original->questions()->pluck('id'))->count()
        );
        $this->assertSame($scopesAntes, $original->servicos()->count());
    }
}
