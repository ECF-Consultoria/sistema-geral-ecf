<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 81 Plan 01 (DEC-81-2) — EXCLUIR modelo NPS.
 *
 * Prova as guardas de negócio do `NpsTemplateController@destroy`:
 *  1. modelo não-principal SEM respostas → apaga o template; cascade limpa
 *     perguntas/opções/scopes; redirect com success;
 *  2. modelo is_default=true → abort(422) (espelha update/toggleActive);
 *     template permanece;
 *  3. modelo com ao menos 1 survey respondido → abort(422) sugerindo arquivar
 *     (Pitfall 1: nullOnDelete zeraria as notas no dashboard); template permanece.
 *
 * @see .planning/phases/81-.../81-01-PLAN.md
 */
class ExcluirModeloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — cascade/nullOnDelete dependem disso.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Modelo não-principal com 1 pergunta escala (5 opções). Retorna o template.
     */
    private function criarModeloComPergunta(): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo Descartável',
            'active'     => true,
            'is_default' => false,
        ]);

        $q = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Como avalia?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $q->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        return $template->fresh();
    }

    #[Test]
    public function test_destroy_modelo_nao_principal_sem_respostas_apaga_e_limpa_config(): void
    {
        $this->actingAsAdmin();
        $template   = $this->criarModeloComPergunta();
        $questionId = $template->questions()->first()->id;

        $this->delete(route('nps.configuracao.templates.destroy', $template->id))
            ->assertRedirect();

        // Template e árvore removidos (cascade nas FKs).
        $this->assertDatabaseMissing('nps_templates', ['id' => $template->id]);
        $this->assertDatabaseMissing('nps_template_questions', ['template_id' => $template->id]);
        $this->assertDatabaseMissing('nps_template_options', ['question_id' => $questionId]);
    }

    #[Test]
    public function test_destroy_do_modelo_principal_e_bloqueado_com_422(): void
    {
        $this->actingAsAdmin();
        // Modelo principal seedado por migration (is_default=true).
        $principal = NpsTemplate::where('is_default', true)->firstOrFail();

        $this->delete(route('nps.configuracao.templates.destroy', $principal->id))
            ->assertStatus(422);

        // Continua no banco.
        $this->assertDatabaseHas('nps_templates', ['id' => $principal->id]);
    }

    #[Test]
    public function test_destroy_de_modelo_com_respostas_e_bloqueado_com_422(): void
    {
        $this->actingAsAdmin();
        $template = $this->criarModeloComPergunta();
        $company  = Company::factory()->create();

        // Survey respondido apontando pro template.
        $survey = NpsSurvey::create([
            'token'       => Str::uuid()->toString(),
            'company_id'  => $company->id,
            'expires_at'  => now()->addDays(30),
            'status'      => 'completed',
            'template_id' => $template->id,
        ]);
        NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente Respondente',
        ]);

        $this->delete(route('nps.configuracao.templates.destroy', $template->id))
            ->assertStatus(422);

        // Preserva o modelo (histórico/dashboard intactos).
        $this->assertDatabaseHas('nps_templates', ['id' => $template->id]);
    }
}
