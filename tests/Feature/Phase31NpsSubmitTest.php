<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Suite Feature — NpsController na nova taxonomia (Phase 31 Plan 02).
 *
 * Cobre:
 *  T1. POST /nps/{token} válido com 3 scores 1-5 grava NpsResponse e completa survey
 *  T2. POST sem score_analista (mentoria pura) é aceito (campo nullable)
 *  T3. POST com score fora de 1-5 retorna 422 na chave correta
 *  T4. POST sem respondent_name é aceito (campo agora nullable)
 *  T5. GET /nps/{token} retorna payload com chaves novas (estrategista_name,
 *      analista_name, tem_analista) e SEM mentor_name/consultant_name
 *  T6. POST /nps/generate (auth admin) cria survey com auto_generated=false
 */
class Phase31NpsSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    private function criarSurveyPendente(Company $empresa): NpsSurvey
    {
        return NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
        ]);
    }

    /**
     * T1 — POST válido com 3 scores 1-5 + comment grava na nova taxonomia
     * e marca survey como completed.
     */
    public function test_submit_response_grava_3_scores_e_completa_survey(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => 'Atendimento excepcional!',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('nps_responses', [
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => 'Atendimento excepcional!',
        ]);

        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);
    }

    /**
     * T2 — POST sem score_analista (mentoria pura) → 200 OK; coluna grava NULL.
     */
    public function test_submit_response_aceita_score_analista_nulo(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name'    => 'Cliente',
            'score_estrategista' => 4,
            // score_analista omitido
            'score_empresa'      => 5,
            'comment'            => null,
        ]);

        $response->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->first();
        $this->assertNotNull($resposta);
        $this->assertNull($resposta->score_analista);
        $this->assertSame(4, $resposta->score_estrategista);
        $this->assertSame(5, $resposta->score_empresa);
    }

    /**
     * T3 — Score fora de 1-5 retorna 422 na chave correta.
     */
    public function test_submit_response_rejeita_score_fora_de_1_a_5(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        // score_estrategista = 0 (abaixo do mínimo)
        $response = $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 0,
            'score_empresa'      => 3,
        ]);
        $response->assertStatus(302); // Inertia retorna 302 com erros de validação
        $response->assertSessionHasErrors('score_estrategista');

        // score_empresa = 6 (acima do máximo)
        $response = $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 3,
            'score_empresa'      => 6,
        ]);
        $response->assertStatus(302);
        $response->assertSessionHasErrors('score_empresa');
    }

    /**
     * T4 — respondent_name ausente → 200 OK (agora nullable — D-07).
     */
    public function test_submit_response_aceita_respondent_name_ausente(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $response = $this->post("/nps/{$survey->token}", [
            // respondent_name omitido
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
        ]);

        $response->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->first();
        $this->assertNotNull($resposta);
        $this->assertNull($resposta->respondent_name);
    }

    /**
     * T5 — GET /nps/{token} retorna payload com chaves novas e SEM as legacy.
     */
    public function test_respond_payload_inertia_chaves_nova_taxonomia(): void
    {
        $empresa      = $this->criarEmpresa();
        $estrategista = User::factory()->create(['name' => 'Nathalia']);
        $analista     = User::factory()->create(['name' => 'Bruno']);
        $empresa->users()->attach($estrategista->id, ['role' => 'estrategista', 'assigned_at' => now()]);
        $empresa->users()->attach($analista->id, ['role' => 'consultor', 'assigned_at' => now()]);

        $survey = $this->criarSurveyPendente($empresa);

        $response = $this->get("/nps/{$survey->token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Respond')
            ->where('survey.estrategista_name', 'Nathalia')
            ->where('survey.analista_name', 'Bruno')
            ->where('survey.tem_analista', true)
            ->missing('survey.mentor_name')
            ->missing('survey.consultant_name')
        );
    }

    /**
     * T5b — Mentoria pura: empresa sem analista atribuído → tem_analista=false,
     * analista_name=null.
     */
    public function test_respond_payload_mentoria_pura_sem_analista(): void
    {
        $empresa      = $this->criarEmpresa();
        $estrategista = User::factory()->create(['name' => 'Carlos']);
        $empresa->users()->attach($estrategista->id, ['role' => 'estrategista', 'assigned_at' => now()]);

        $survey = $this->criarSurveyPendente($empresa);

        $response = $this->get("/nps/{$survey->token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Respond')
            ->where('survey.estrategista_name', 'Carlos')
            ->where('survey.analista_name', null)
            ->where('survey.tem_analista', false)
        );
    }

    /**
     * T6 — POST /nps/generate (auth admin) cria survey com auto_generated=false.
     */
    public function test_generate_cria_survey_com_auto_generated_false(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();

        $response = $this->actingAs($admin)->post('/nps/generate', [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302); // back() redirect
        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertSame($empresa->id, $survey->company_id);
        $this->assertSame($admin->id, $survey->generated_by);
        $this->assertFalse((bool) $survey->auto_generated);
        $this->assertNull($survey->month_reference);
        $this->assertSame('pending', $survey->status);
        // expires_at = +7 dias para manuais (D-12)
        $this->assertTrue($survey->expires_at->between(now()->addDays(6), now()->addDays(8)));
    }
}
