<?php

namespace Tests\Feature\Phase94;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 94 Plan 02 — Task 2 (AB-94-2 + AB-94-4).
 *
 * Cobre o rastro de RESPOSTA (response_ip_address/response_user_agent/
 * response_duration_seconds) + o veredito do `NpsSuspicionService`
 * (is_suspicious/suspicion_reasons) persistidos via o helper privado
 * compartilhado `capturarRastroEAvaliarSuspeita()`, exercitado nos DOIS
 * paths de submit (v15 dinâmico e legado).
 */
class NpsResponseTrailAndSuspicionTest extends TestCase
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

    /**
     * Cria um survey v15.0 (com template_id) pronto para submit dinâmico.
     * `criadoHaSegundos` controla o `created_at` (base da duração) — 0 para
     * "resposta imediata", N para simular decorrer de tempo.
     */
    private function criarSurveyV15(Company $empresa, int $criadoHaSegundos = 0): array
    {
        $template = NpsTemplate::factory()->create(['active' => true]);
        $pergunta = NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
        ]);
        $opcao = NpsTemplateOption::factory()->create([
            'question_id' => $pergunta->id,
            'label'       => '4',
            'peso'        => 4,
        ]);

        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);

        $survey->created_at = now()->subSeconds($criadoHaSegundos);
        $survey->saveQuietly();

        return [$survey, $pergunta, $opcao];
    }

    /**
     * Cria um survey LEGADO (sem template_id) pronto para submit legacy.
     */
    private function criarSurveyLegacy(Company $empresa, int $criadoHaSegundos = 0): NpsSurvey
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
        ]);

        $survey->created_at = now()->subSeconds($criadoHaSegundos);
        $survey->saveQuietly();

        return $survey;
    }

    /**
     * Cenário 1 — path v15: NpsResponse grava response_ip_address/
     * response_user_agent/response_duration_seconds na MESMA criação.
     */
    public function test_path_v15_grava_rastro_de_resposta_completo(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $empresa = $this->criarEmpresa();
        [$survey, $pergunta, $opcao] = $this->criarSurveyV15($empresa, criadoHaSegundos: 600);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (V15Test)'])
            ->post("/nps/{$survey->token}", [
                'respondent_name' => 'Cliente V15',
                'answers'         => [(string) $pergunta->id => $opcao->id],
            ])
            ->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->firstOrFail();

        $this->assertSame('198.51.100.9', $resposta->response_ip_address);
        $this->assertSame('Mozilla/5.0 (V15Test)', $resposta->response_user_agent);
        $this->assertNotNull($resposta->response_duration_seconds);
        $this->assertEqualsWithDelta(600, $resposta->response_duration_seconds, 5);
    }

    /**
     * Cenário 2 — path legado: MESMO tratamento (comprova helper compartilhado).
     */
    public function test_path_legado_grava_rastro_de_resposta_completo(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyLegacy($empresa, criadoHaSegundos: 600);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (LegacyTest)'])
            ->post("/nps/{$survey->token}", [
                'respondent_name'    => 'Cliente Legado',
                'score_estrategista' => 5,
                'score_analista'     => 4,
                'score_empresa'      => 5,
                'comment'            => 'Ótimo!',
            ])
            ->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->firstOrFail();

        $this->assertSame('198.51.100.9', $resposta->response_ip_address);
        $this->assertSame('Mozilla/5.0 (LegacyTest)', $resposta->response_user_agent);
        $this->assertNotNull($resposta->response_duration_seconds);
        $this->assertEqualsWithDelta(600, $resposta->response_duration_seconds, 5);
    }

    /**
     * Cenário 3 — resposta rápida (survey criada agora, submit imediato):
     * is_suspicious=true, suspicion_reasons objeto com motivo da Regra 2,
     * severity 'media'.
     */
    public function test_resposta_rapida_marca_suspeita_severidade_media(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $empresa = $this->criarEmpresa();
        [$survey, $pergunta, $opcao] = $this->criarSurveyV15($empresa, criadoHaSegundos: 0);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post("/nps/{$survey->token}", [
                'respondent_name' => 'Cliente Rápido',
                'answers'         => [(string) $pergunta->id => $opcao->id],
            ])
            ->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->firstOrFail();

        $this->assertTrue((bool) $resposta->is_suspicious);
        $this->assertNotNull($resposta->suspicion_reasons);
        $this->assertArrayHasKey('reasons', $resposta->suspicion_reasons);
        $this->assertArrayHasKey('severity', $resposta->suspicion_reasons);
        $this->assertSame('media', $resposta->suspicion_reasons['severity']);
        $this->assertContains(
            'Resposta enviada em menos de 1 minuto após geração do link.',
            $resposta->suspicion_reasons['reasons']
        );
    }

    /**
     * Cenário 4 — IP interno ECF marca motivo da Regra 1.
     */
    public function test_ip_interno_marca_motivo_regra_1(): void
    {
        config(['nps.anti_burlamento.internal_ips' => ['203.0.113.55']]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyLegacy($empresa, criadoHaSegundos: 600);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->post("/nps/{$survey->token}", [
                'score_estrategista' => 5,
                'score_empresa'      => 5,
            ])
            ->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->firstOrFail();

        $this->assertTrue((bool) $resposta->is_suspicious);
        $this->assertContains(
            'Resposta enviada a partir da rede interna da ECF.',
            $resposta->suspicion_reasons['reasons']
        );
        $this->assertSame('media', $resposta->suspicion_reasons['severity']);
    }

    /**
     * Cenário 5 — IP interno + resposta rápida → 3 motivos, severity 'alta'.
     */
    public function test_ip_interno_e_rapida_combinam_severidade_alta_com_3_motivos(): void
    {
        config(['nps.anti_burlamento.internal_ips' => ['203.0.113.55']]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $empresa = $this->criarEmpresa();
        [$survey, $pergunta, $opcao] = $this->criarSurveyV15($empresa, criadoHaSegundos: 0);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->post("/nps/{$survey->token}", [
                'answers' => [(string) $pergunta->id => $opcao->id],
            ])
            ->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->firstOrFail();

        $this->assertTrue((bool) $resposta->is_suspicious);
        $this->assertSame('alta', $resposta->suspicion_reasons['severity']);
        $this->assertCount(3, $resposta->suspicion_reasons['reasons']);
    }

    /**
     * Cenário 6 — sessão autenticada (usuário interno coexistente na aba
     * pública): Regra 4 endurecida pela Fase 96 (AB-96-1) — o submit é
     * BLOQUEADO ANTES de qualquer NpsResponse::create(), não mais aceito com
     * a resposta apenas marcada como suspeita (comportamento pré-96 da Fase
     * 94, superado por este teste — ver tests/Feature/Phase96/
     * NpsBloqueioSessaoInternaTest.php para a cobertura completa do bloqueio).
     */
    public function test_sessao_autenticada_e_bloqueada_pelo_endurecimento_da_fase_96(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $admin   = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyLegacy($empresa, criadoHaSegundos: 600);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post("/nps/{$survey->token}", [
                'score_estrategista' => 5,
                'score_empresa'      => 5,
            ])
            ->assertOk();

        $this->assertSame(0, NpsResponse::where('survey_id', $survey->id)->count());

        $survey->refresh();
        $this->assertSame('pending', $survey->status, 'Bloqueio nao transiciona o status do survey (Fase 96 AB-96-1)');
    }

    /**
     * Cenário 7 — resposta limpa: IP externo, duração longa, anônimo →
     * is_suspicious=false, suspicion_reasons NULL (nao array vazio).
     */
    public function test_resposta_limpa_persiste_suspicion_reasons_null(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyLegacy($empresa, criadoHaSegundos: 600);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post("/nps/{$survey->token}", [
                'score_estrategista' => 5,
                'score_empresa'      => 5,
            ])
            ->assertOk();

        $resposta = NpsResponse::where('survey_id', $survey->id)->firstOrFail();

        $this->assertFalse((bool) $resposta->is_suspicious);
        $this->assertNull($resposta->suspicion_reasons);
    }

    /**
     * Cenário 10 — POST com falha de validação (422 / redirect com erros de
     * sessão): nenhuma NpsResponse é criada (rastro/suspeita nunca rodam).
     */
    public function test_post_invalido_nao_grava_response(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyLegacy($empresa, criadoHaSegundos: 600);

        // score_estrategista fora de 1..5 → ValidationException (302 + session errors).
        $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 0,
            'score_empresa'      => 3,
        ])->assertStatus(302);

        $this->assertSame(0, NpsResponse::where('survey_id', $survey->id)->count());
    }
}
