<?php

namespace Tests\Feature\Phase94;

use App\Models\Company;
use App\Models\NpsSurvey;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 94 Plan 02 — Task 1 (AB-94-1).
 *
 * Cobre o rastro de ABERTURA gravado em `nps_surveys` por todo GET
 * /nps/{token}: primeira abertura, reabertura (preserva first_opened_at),
 * abertura em survey já completed, e blindagem do payload público (nenhuma
 * chave nova exposta ao respondente).
 *
 * Os cenários de evento (opened/expired em `nps_survey_events`) vivem em
 * NpsSurveyEventsTest.php — separação conforme o plano 94-02.
 */
class NpsOpenTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    private function criarSurveyPendente(Company $empresa, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'pending',
        ], $overrides));
    }

    /**
     * Cenário 1 — primeira abertura: first_opened_at/last_opened_at
     * preenchidos, open_count=1, IP e user-agent capturados.
     */
    public function test_primeira_abertura_grava_rastro_completo(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (TesteAgent)'])
            ->get("/nps/{$survey->token}")
            ->assertOk();

        $survey->refresh();

        $this->assertNotNull($survey->first_opened_at);
        $this->assertNotNull($survey->last_opened_at);
        $this->assertSame(1, $survey->open_count);
        $this->assertSame('203.0.113.55', $survey->open_ip_address);
        $this->assertSame('Mozilla/5.0 (TesteAgent)', $survey->open_user_agent);
    }

    /**
     * Cenário 2 — reabertura (Carbon avançado): first_opened_at NUNCA é
     * sobrescrito, last_opened_at avança, open_count incrementa.
     */
    public function test_reabertura_preserva_first_opened_at_e_atualiza_last_opened_at(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->get("/nps/{$survey->token}")
            ->assertOk();

        $survey->refresh();
        $primeiraAbertura = $survey->first_opened_at->copy();
        $primeiroUltimoAcesso = $survey->last_opened_at->copy();

        Carbon::setTestNow('2026-07-16 10:05:00');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->get("/nps/{$survey->token}")
            ->assertOk();

        $survey->refresh();

        $this->assertEquals($primeiraAbertura, $survey->first_opened_at, 'first_opened_at nunca deve ser sobrescrito');
        $this->assertNotEquals($primeiroUltimoAcesso, $survey->last_opened_at, 'last_opened_at deve avançar a cada abertura');
        $this->assertSame(2, $survey->open_count);
    }

    /**
     * Cenário 3 — GET em survey completed: rastro grava normalmente e a
     * tela AlreadyCompleted continua sendo renderizada.
     */
    public function test_abertura_em_survey_completed_grava_rastro_e_mantem_tela(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa, [
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $this->get("/nps/{$survey->token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Nps/AlreadyCompleted'));

        $survey->refresh();
        $this->assertSame(1, $survey->open_count);
        $this->assertNotNull($survey->first_opened_at);
    }

    /**
     * Cenário 6 — payload Inertia público NÃO ganha nenhuma chave nova:
     * `survey` continua com exatamente as 6 chaves pré-existentes.
     */
    public function test_payload_inertia_survey_nao_ganha_chaves_novas(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->get("/nps/{$survey->token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Nps/Respond')
                ->has('survey', 6)
                ->has('survey.token')
                ->has('survey.company_name')
                ->has('survey.estrategista_name')
                ->has('survey.analista_name')
                ->has('survey.tem_analista')
                ->has('survey.textos')
                ->has('perguntas_extras')
                ->has('template')
            );
    }
}
