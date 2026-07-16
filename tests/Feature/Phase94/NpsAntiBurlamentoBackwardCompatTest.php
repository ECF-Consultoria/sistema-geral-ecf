<?php

namespace Tests\Feature\Phase94;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 94 Plan 01 (AB-94-1..5) — Retrocompatibilidade do schema Anti-Burlamento.
 *
 * Cobre:
 *  - Schema novo (colunas de rastro + tabela nps_survey_events) existe (Task 1)
 *  - Defaults de retrocompatibilidade (AB-94-5): survey/response criados SEM os
 *    campos novos ficam com null/0/false — nunca quebram
 *  - NpsSurveyEvent::factory() persiste com event_type valido e cast metadata array
 *  - Fluxo legado completo (GET /nps/{token} + POST) continua funcionando (Task 3)
 */
class NpsAntiBurlamentoBackwardCompatTest extends TestCase
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
     * Schema: colunas de rastro em nps_surveys/nps_responses e a tabela
     * nps_survey_events existem — sem tocar no enum nps_surveys.status.
     */
    public function test_schema_de_rastro_e_eventos_existe(): void
    {
        $this->assertTrue(Schema::hasColumn('nps_surveys', 'first_opened_at'));
        $this->assertTrue(Schema::hasColumn('nps_surveys', 'last_opened_at'));
        $this->assertTrue(Schema::hasColumn('nps_surveys', 'open_count'));
        $this->assertTrue(Schema::hasColumn('nps_surveys', 'open_ip_address'));
        $this->assertTrue(Schema::hasColumn('nps_surveys', 'open_user_agent'));

        $this->assertTrue(Schema::hasColumn('nps_responses', 'response_ip_address'));
        $this->assertTrue(Schema::hasColumn('nps_responses', 'response_user_agent'));
        $this->assertTrue(Schema::hasColumn('nps_responses', 'response_duration_seconds'));
        $this->assertTrue(Schema::hasColumn('nps_responses', 'is_suspicious'));
        $this->assertTrue(Schema::hasColumn('nps_responses', 'suspicion_reasons'));

        $this->assertTrue(Schema::hasTable('nps_survey_events'));
    }

    /**
     * NpsSurveyEvent::factory() persiste com survey_id valido e event_type
     * dentro de NpsSurveyEvent::TYPES; cast metadata array funciona.
     */
    public function test_nps_survey_event_factory_persiste_com_cast_metadata(): void
    {
        $evento = NpsSurveyEvent::factory()->create([
            'metadata' => ['first_open' => true],
        ]);

        $this->assertNotNull($evento->survey_id);
        $this->assertContains($evento->event_type, NpsSurveyEvent::TYPES);
        $this->assertIsArray($evento->metadata);
        $this->assertTrue($evento->metadata['first_open']);

        $evento->refresh();
        $this->assertIsArray($evento->metadata);
        $this->assertTrue($evento->metadata['first_open']);
    }

    /**
     * AB-94-5 — Survey/Response criados SEM os campos novos (via ::create
     * direto, sem passar pelos defaults do factory) ficam com defaults de
     * retrocompatibilidade: first_opened_at/open_ip_address/suspicion_reasons
     * null, open_count 0, is_suspicious false.
     */
    public function test_survey_e_response_sem_campos_novos_ficam_com_defaults(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);
        // refresh() para ler os defaults aplicados pelo BANCO (open_count=0),
        // não os atributos locais do insert (que não incluem a coluna).
        $survey->refresh();

        $this->assertNull($survey->first_opened_at);
        $this->assertNull($survey->last_opened_at);
        $this->assertSame(0, $survey->open_count);
        $this->assertNull($survey->open_ip_address);
        $this->assertNull($survey->open_user_agent);

        $resposta = NpsResponse::create([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Legado',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => null,
        ]);
        $resposta->refresh();

        $this->assertNull($resposta->response_ip_address);
        $this->assertNull($resposta->response_user_agent);
        $this->assertNull($resposta->response_duration_seconds);
        $this->assertFalse($resposta->is_suspicious);
        $this->assertNull($resposta->suspicion_reasons);
    }

    /**
     * AB-94-5 — Survey legada (template_id null, sem rastro) responde
     * GET /nps/{token} com 200 e componente Nps/Respond.
     */
    public function test_get_nps_respond_legado_sem_rastro_funciona(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $response = $this->get("/nps/{$survey->token}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Nps/Respond'));
    }

    /**
     * AB-94-5 — POST legado completo (score_estrategista/score_empresa
     * obrigatorios) grava resposta com defaults novos intactos e completa
     * o survey — nao quebra por causa das colunas novas.
     */
    public function test_post_nps_respond_legado_completo_funciona(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name'    => 'Cliente Legado',
            'score_estrategista' => 5,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => 'Tudo certo.',
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Nps/ThankYou'));

        $survey->refresh();
        $this->assertSame('completed', $survey->status);

        $resposta = NpsResponse::where('survey_id', $survey->id)->first();
        $this->assertNotNull($resposta);
        $this->assertFalse($resposta->is_suspicious);
        $this->assertNull($resposta->suspicion_reasons);
        $this->assertNull($resposta->response_ip_address);
        $this->assertNull($resposta->response_user_agent);
    }
}
