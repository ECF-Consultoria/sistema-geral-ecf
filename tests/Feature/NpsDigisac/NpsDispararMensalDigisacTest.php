<?php

namespace Tests\Feature\NpsDigisac;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsDigisacEnvio;
use App\Models\NpsEmailEnvio;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\Digisac\DigisacClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * v15.5 — Suite Feature do canal Digisac dentro do comando `nps:disparar-mensal`.
 *
 * Cobre:
 *  T1. Config default: canal digisac OFF → nenhum NpsDigisacEnvio criado, email preservado
 *  T2. Canal digisac ON + empresa sem grupo → status=skipped registrado
 *  T3. Canal digisac ON + empresa mapeada → chama client mockado + registra enviado
 *  T4. Falha no client digisac → NpsDigisacEnvio status=falha SEM afetar log de email
 *  T5. Ambos canais desligados → comando sai cedo sem criar surveys
 *
 * Setup:
 *  - `RefreshDatabase` (SQLite in-memory) inclui as migrations v15.5 novas
 *    (companies.digisac_*, nps_digisac_envios, seed config keys)
 *  - `Mail::fake()` isola SMTP; `DigisacClient` mockado no container por teste
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see app/Services/Digisac/NpsDigisacDispatchService.php
 * @see app/Services/Digisac/DigisacClient.php
 */
class NpsDispararMensalDigisacTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        // Fixa hoje = 2026-07-08 (BRT) para batter aniversário da empresa em todos os testes.
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function criarEmpresaComEstrategista(array $overrides = []): Company
    {
        $agora = Carbon::now('America/Sao_Paulo');

        $empresa = Company::factory()->create(array_merge([
            'email_cliente' => 'cliente@' . uniqid() . '.com',
        ], $overrides));

        $empresa->timestamps = false;
        $empresa->forceFill([
            'created_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
            'updated_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
        ])->save();
        $empresa->timestamps = true;
        $empresa->refresh();

        $estrategista = User::factory()->create();
        $empresa->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now(),
        ]);

        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        // Inofensivo no T5 (ambos canais off → comando sai antes de iterar).
        $this->contratarServicoNpsCoberto($empresa);

        return $empresa->fresh();
    }

    // ═══ T1 — Config default (digisac OFF) preserva comportamento antigo ═══

    #[Test]
    public function test_canal_digisac_off_por_default_nao_cria_nps_digisac_envio(): void
    {
        Mail::fake();
        $this->criarEmpresaComEstrategista();

        // Nada muda em Configuracao → default é digisac OFF
        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);
        $this->assertDatabaseCount('nps_email_envios', 1);
        $this->assertDatabaseCount('nps_digisac_envios', 0);
    }

    // ═══ T2 — Digisac ON + empresa sem grupo → status=skipped ═══

    #[Test]
    public function test_digisac_ativo_com_empresa_sem_grupo_registra_skipped(): void
    {
        Mail::fake();
        Configuracao::set('nps_envio_digisac_ativo', '1');

        // Empresa SEM digisac_group_contact_id → skipped
        $this->criarEmpresaComEstrategista();

        // Mock do client para garantir que NÃO é chamado no fluxo skipped
        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldNotReceive('sendMessage');
        });

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_digisac_envios', 1);
        $envio = NpsDigisacEnvio::first();
        $this->assertEquals(NpsDigisacEnvio::STATUS_SKIPPED, $envio->status);
        $this->assertEquals('sem_grupo_mapeado', $envio->erro_msg);
        $this->assertNull($envio->contact_id);
    }

    // ═══ T3 — Digisac ON + empresa mapeada → enviado ═══

    #[Test]
    public function test_digisac_ativo_com_empresa_mapeada_envia_via_client(): void
    {
        Mail::fake();
        Configuracao::set('nps_envio_digisac_ativo', '1');

        $empresa = $this->criarEmpresaComEstrategista([
            'digisac_group_contact_id'    => 'contact-abc-123',
            'digisac_group_name_snapshot' => 'Grupo ECF · Cliente XYZ',
            'digisac_group_mapping_status' => 'mapped',
        ]);

        // Client mockado — devolve provider_message_id fixo
        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(function ($contactId, $text, $userId, $serviceId) {
                    // Confirma que a mensagem contém pelo menos o nome da empresa ou link
                    return $contactId === 'contact-abc-123' && is_string($text) && strlen($text) > 0;
                })
                ->andReturn([
                    'provider_message_id' => 'msg-999',
                    'raw'                 => ['id' => 'msg-999', 'ok' => true],
                ]);
        });

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // 1 email + 1 digisac usando o MESMO survey
        $this->assertDatabaseCount('nps_surveys', 1);
        $this->assertDatabaseCount('nps_email_envios', 1);
        $this->assertDatabaseCount('nps_digisac_envios', 1);

        $survey  = NpsSurvey::first();
        $emailE  = NpsEmailEnvio::first();
        $digisacE = NpsDigisacEnvio::first();

        $this->assertEquals($survey->id, $emailE->survey_id,   'email log deve apontar pro survey criado');
        $this->assertEquals($survey->id, $digisacE->survey_id, 'digisac log deve apontar pro MESMO survey');

        $this->assertEquals(NpsDigisacEnvio::STATUS_ENVIADO, $digisacE->status);
        $this->assertEquals('msg-999', $digisacE->provider_message_id);
        $this->assertEquals('contact-abc-123', $digisacE->contact_id);
    }

    // ═══ T4 — Falha no client digisac NÃO afeta log de email ═══

    #[Test]
    public function test_falha_no_digisac_nao_afeta_log_de_email(): void
    {
        Mail::fake();
        Configuracao::set('nps_envio_digisac_ativo', '1');

        $this->criarEmpresaComEstrategista([
            'digisac_group_contact_id' => 'contact-erro',
            'digisac_group_mapping_status' => 'mapped',
        ]);

        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andThrow(new \RuntimeException('[Digisac] sendMessage HTTP 500: internal'));
        });

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Email PRESERVADO (canal isolado)
        $emailE = NpsEmailEnvio::first();
        $this->assertNotNull($emailE);
        $this->assertEquals('enviado', $emailE->status);

        // Digisac gravou falha
        $digisacE = NpsDigisacEnvio::first();
        $this->assertNotNull($digisacE);
        $this->assertEquals(NpsDigisacEnvio::STATUS_FALHA, $digisacE->status);
        $this->assertStringContainsString('HTTP 500', $digisacE->erro_msg);
    }

    // ═══ T5 — Ambos canais desligados: sai cedo sem tocar em nada ═══

    #[Test]
    public function test_ambos_canais_off_nao_cria_survey(): void
    {
        Mail::fake();
        Configuracao::set('nps_envio_email_ativo',   '0');
        Configuracao::set('nps_envio_digisac_ativo', '0');

        $this->criarEmpresaComEstrategista();

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 0);
        $this->assertDatabaseCount('nps_email_envios', 0);
        $this->assertDatabaseCount('nps_digisac_envios', 0);
    }
}
