<?php

namespace Tests\Feature\Phase95;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Phase 95 Plan 01 — Task 1 (AB-95-1, AB-95-2, AB-95-4).
 *
 * Prova que `NpsController::index()` entrega `confianca` (badge tri-estado)
 * e `auditoria` (seção de detalhe) SOMENTE para admin, lendo dados já
 * persistidos pela Fase 94 sem recalcular nada. Molde estrutural copiado de
 * `tests/Feature/V16/NpsResponsaveisPayloadTest.php` (helpers
 * propsDoIndex/surveyNoPayload/admin + armadilha do template_id=__todos__).
 *
 * Seed via NpsResponse::create() direto (nunca POST /nps/{token}) — o POST
 * dispararia a Regra 2 (resposta rápida) do NpsSuspicionService e
 * contaminaria os cenários "confiável" com suspicion_reasons inesperado.
 */
class NpsConfiancaPayloadTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — necessário para os relacionamentos usados.
        DB::statement('PRAGMA foreign_keys = ON');
        // Congela "agora" para o filtro de mês do index() nunca cair numa
        // virada de mês durante a execução da suite.
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup (molde tests/Feature/V16/NpsResponsaveisPayloadTest.php)
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** GET /nps?template_id=__todos__ (armadilha do filtro default=principal), devolve os props Inertia. */
    private function propsDoIndex(User $user): array
    {
        $props = null;

        $this->actingAs($user)
            ->get('/nps?template_id=__todos__')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        $this->assertIsArray($props, 'O payload Inertia deveria trazer props.');

        return $props;
    }

    /** Encontra o survey (pelo token) na lista paginada do payload. */
    private function surveyNoPayload(array $props, string $token): ?array
    {
        $lista = $props['surveys']['data'] ?? [];

        foreach ($lista as $item) {
            if (($item['token'] ?? null) === $token) {
                return $item;
            }
        }

        return null;
    }

    /** Cria um survey `completed` legacy (template_id null) para a empresa. */
    private function criarSurveyCompleto(Company $company, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'completed',
            'completed_at' => now(),
            'template_id'  => null,
        ], $overrides));
    }

    /** Cria a NpsResponse direto (nunca via POST — evita contaminar o veredito). */
    private function criarResponse(NpsSurvey $survey, array $overrides = []): NpsResponse
    {
        return NpsResponse::create(array_merge([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => 4,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => null,
            'response_ip_address'       => '203.0.113.10',
            'response_user_agent'       => 'Mozilla/5.0 (teste)',
            'response_duration_seconds' => 300,
            'is_suspicious'             => false,
            'suspicion_reasons'         => null,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 1 (verde) — suspicion_reasons=null → confiável
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_limpa_mostra_confianca_confiavel_para_admin(): void
    {
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $this->criarResponse($survey, ['suspicion_reasons' => null]);

        $props    = $this->propsDoIndex($this->admin());
        $item     = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item);
        $this->assertArrayHasKey('confianca', $item);
        $this->assertSame('confiavel', $item['confianca']['status']);
        $this->assertSame([], $item['confianca']['motivos']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 2 (amarelo) — severity=media → atencao
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_com_severidade_media_mostra_confianca_atencao(): void
    {
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $motivo  = 'Resposta enviada em menos de 1 minuto após geração do link.';
        $this->criarResponse($survey, [
            'is_suspicious'     => true,
            'suspicion_reasons' => ['reasons' => [$motivo], 'severity' => 'media'],
        ]);

        $props = $this->propsDoIndex($this->admin());
        $item  = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item);
        $this->assertSame('atencao', $item['confianca']['status']);
        $this->assertContains($motivo, $item['confianca']['motivos']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 3 (vermelho) — severity=alta → suspeita
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_com_severidade_alta_mostra_confianca_suspeita(): void
    {
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $this->criarResponse($survey, [
            'is_suspicious'     => true,
            'suspicion_reasons' => [
                'reasons'  => [
                    'Resposta enviada a partir da rede interna da ECF.',
                    'Resposta enviada em menos de 1 minuto após geração do link.',
                    'Link gerado e respondido rapidamente a partir da rede interna.',
                ],
                'severity' => 'alta',
            ],
        ]);

        $props = $this->propsDoIndex($this->admin());
        $item  = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item);
        $this->assertSame('suspeita', $item['confianca']['status']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 4 — auditoria completa (12 chaves) com canal Email
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_auditoria_completa_traz_todas_as_chaves_para_admin(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company, [
            'generated_by'     => $admin->id,
            'first_opened_at'  => now()->subMinutes(30),
            'last_opened_at'   => now()->subMinutes(10),
            'open_count'       => 2,
            'open_ip_address'  => '198.51.100.5',
            'open_user_agent'  => 'Mozilla/5.0 (abertura)',
        ]);
        $this->criarResponse($survey, [
            'response_ip_address'       => '203.0.113.20',
            'response_user_agent'       => 'Mozilla/5.0 (resposta)',
            'response_duration_seconds' => 1200,
            'suspicion_reasons'         => null,
        ]);

        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_SENT_EMAIL,
            'ip_address' => null,
            'user_agent' => null,
            'user_id'    => null,
            'metadata'   => null,
        ]);

        $props = $this->propsDoIndex($admin);
        $item  = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item);
        $this->assertArrayHasKey('auditoria', $item);

        $chavesEsperadas = [
            'gerado_em', 'gerado_por', 'aberto_primeira', 'aberto_ultima',
            'aberto_contagem', 'respondido_em', 'tempo_ate_resposta',
            'ip_abertura', 'ip_resposta', 'user_agent', 'canal', 'motivos',
        ];
        foreach ($chavesEsperadas as $chave) {
            $this->assertArrayHasKey($chave, $item['auditoria'], "Chave ausente em auditoria: {$chave}");
        }

        $this->assertSame($admin->name, $item['auditoria']['gerado_por']);
        $this->assertSame(2, $item['auditoria']['aberto_contagem']);
        $this->assertSame('198.51.100.5', $item['auditoria']['ip_abertura']);
        $this->assertSame('203.0.113.20', $item['auditoria']['ip_resposta']);
        $this->assertSame(1200, $item['auditoria']['tempo_ate_resposta']);
        $this->assertSame('Email', $item['auditoria']['canal']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 5 — canal manual (evento generated com metadata.origem=manual)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_canal_manual_quando_nao_ha_evento_de_envio(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company, ['generated_by' => $admin->id]);
        $this->criarResponse($survey);

        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_GENERATED,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (admin)',
            'user_id'    => $admin->id,
            'metadata'   => ['origem' => 'manual'],
        ]);

        $props = $this->propsDoIndex($admin);
        $item  = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item);
        $this->assertSame('Manual (link gerado por admin)', $item['auditoria']['canal']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 6 (central) — blindagem AB-95-4: não-admin não recebe NADA
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_payload_de_nao_admin_nao_contem_nenhum_campo_de_suspeita_ou_auditoria(): void
    {
        $company = Company::factory()->create(['active' => true]);
        $survey  = $this->criarSurveyCompleto($company);
        $this->criarResponse($survey, [
            'is_suspicious'     => true,
            'suspicion_reasons' => ['reasons' => ['motivo x'], 'severity' => 'alta'],
        ]);

        // Não-admin precisa da empresa na carteira, senão o item some da lista
        // e o teste passaria por vacuidade (armadilha documentada no molde V16).
        $naoAdmin = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($company->id, $naoAdmin->id, 'consultor', null);

        $props = $this->propsDoIndex($naoAdmin);
        $item  = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item, 'O survey deveria aparecer na carteira do não-admin.');
        $this->assertArrayNotHasKey('confianca', $item);
        $this->assertArrayNotHasKey('auditoria', $item);
        $this->assertArrayNotHasKey('pode_ver_confianca', $props);

        // Blindagem redundante: nenhuma chave sensível solta em qualquer nível do item.
        $json = json_encode($item);
        $this->assertStringNotContainsString('is_suspicious', $json);
        $this->assertStringNotContainsString('suspicion_reasons', $json);
        $this->assertStringNotContainsString('response_ip_address', $json);
        $this->assertStringNotContainsString('open_ip_address', $json);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 7 — survey pendente sem response: confianca presente porém null
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_pendente_sem_response_nao_quebra_o_helper_de_confianca(): void
    {
        $company = Company::factory()->create(['active' => true]);
        $survey  = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'pending',
            'template_id'  => null,
        ]);

        $props = $this->propsDoIndex($this->admin());
        $item  = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($item);
        $this->assertArrayHasKey('confianca', $item);
        $this->assertNull($item['confianca']);
    }
}
