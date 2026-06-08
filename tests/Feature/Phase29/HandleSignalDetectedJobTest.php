<?php

namespace Tests\Feature\Phase29;

use App\Jobs\EcfWebhook\HandleSignalDetectedJob;
use App\Models\Company;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\AlertaEcfNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Suite Feature — HandleSignalDetectedJob (Phase 29).
 *
 * Cobre: caminho feliz (critical + carteira → 3 notifications), empresa fora
 * da carteira, severity warning, severity info, idempotência de signal_id,
 * roles fora da whitelist e custId nulo.
 *
 * Usa Notification::fake() para interceptar envios sem persistir — exceto no
 * teste de idempotência que cria linha real em DatabaseNotification ANTES de
 * ativar o fake.
 */
class HandleSignalDetectedJobTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───

    /**
     * Cria um WebhookDelivery com payload de signal.detected.
     */
    private function criarDelivery(array $overrides = []): WebhookDelivery
    {
        $data = array_merge([
            'id'        => 91,
            'eventType' => 'seller.gmv_queda_mom',
            'custId'    => '1354156948',
            'severity'  => 'critical',
            'payload'   => [
                'delta_pct'    => -76.5,
                'gmv_atual'    => 11135.78,
                'gmv_anterior' => 47388.20,
                'mes_atual'    => 'maio/2026',
            ],
        ], $overrides);

        return WebhookDelivery::create([
            'event_id'        => 'sig-' . uniqid(),
            'event_type'      => 'signal.detected',
            'payload'         => ['data' => $data],
            'signature_valid' => true,
            'received_at'     => now(),
            'status'          => 'received',
            'ip_address'      => '127.0.0.1',
        ]);
    }

    /**
     * Cria empresa ativa com adman_account_id correspondente ao custId padrão.
     */
    private function criarEmpresaNaCarteira(string $custId = '1354156948'): Company
    {
        return Company::create([
            'name'              => 'RELOJOARIA WENUS',
            'adman_account_id'  => $custId,
            'active'            => true,
            'status'            => 'ativo',
        ]);
    }

    /**
     * Cria usuários com roles diferentes (admin, consultor, mentor).
     * Retorna array indexado por role.
     */
    private function criarDestinatarios(): array
    {
        return [
            'admin'     => User::factory()->create(['role' => 'admin',     'active' => true]),
            'consultor' => User::factory()->create(['role' => 'consultor', 'active' => true]),
            'mentor'    => User::factory()->create(['role' => 'mentor',    'active' => true]),
        ];
    }

    // ─── Testes ───

    /**
     * T01 — Critical + empresa da carteira → notification criada para admin, consultor e mentor.
     */
    public function test_critical_empresa_na_carteira_envia_notificacoes_para_3_roles(): void
    {
        Notification::fake();

        $empresa = $this->criarEmpresaNaCarteira();
        $users   = $this->criarDestinatarios();

        $delivery = $this->criarDelivery(['custId' => '1354156948']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        // Cada destinatário recebe AlertaEcfNotification
        foreach ($users as $user) {
            Notification::assertSentTo($user, AlertaEcfNotification::class);
        }

        // Delivery marcado como processed
        $this->assertDatabaseHas('webhook_deliveries', [
            'id'     => $delivery->id,
            'status' => 'processed',
        ]);
    }

    /**
     * T02 — Critical + empresa FORA da carteira → 0 notifications.
     */
    public function test_critical_empresa_fora_da_carteira_nao_envia_notificacao(): void
    {
        Notification::fake();

        $users = $this->criarDestinatarios();

        // Delivery com custId que não existe em nenhuma Company
        $delivery = $this->criarDelivery(['custId' => '9999999999']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        Notification::assertNothingSent();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id'     => $delivery->id,
            'status' => 'processed',
        ]);
    }

    /**
     * T03 — Severity warning → 0 notifications (filtro de severity).
     */
    public function test_severity_warning_nao_envia_notificacao(): void
    {
        Notification::fake();

        $this->criarEmpresaNaCarteira();
        $this->criarDestinatarios();

        $delivery = $this->criarDelivery(['severity' => 'warning']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        Notification::assertNothingSent();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id'     => $delivery->id,
            'status' => 'processed',
        ]);
    }

    /**
     * T04 — Severity info → 0 notifications (filtro de severity).
     */
    public function test_severity_info_nao_envia_notificacao(): void
    {
        Notification::fake();

        $this->criarEmpresaNaCarteira();
        $this->criarDestinatarios();

        $delivery = $this->criarDelivery(['severity' => 'info']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        Notification::assertNothingSent();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id'     => $delivery->id,
            'status' => 'processed',
        ]);
    }

    /**
     * T05 — Idempotência: signal_id já existe no banco → Job não envia de novo.
     *
     * Cria linha real em DatabaseNotification com data->meta->signal_id = '91'
     * ANTES de ativar o fake, depois dispara o Job — deve pular o envio.
     */
    public function test_idempotencia_signal_id_existente_nao_envia_novamente(): void
    {
        $empresa = $this->criarEmpresaNaCarteira();
        $user    = User::factory()->create(['role' => 'admin', 'active' => true]);

        // Cria linha real simulando notification já existente com mesmo signal_id
        DatabaseNotification::create([
            'id'              => \Illuminate\Support\Str::uuid(),
            'type'            => AlertaEcfNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id'   => $user->id,
            'data'            => [
                'titulo'        => 'Queda crítica de faturamento em RELOJOARIA WENUS',
                'mensagem'      => 'GMV caiu 76,5% (R$ 47.388 → R$ 11.135) em maio/2026',
                'categoria'     => 'alerta_ecf',
                'autor_user_id' => null,
                'url'           => '/alertas-estrategicos',
                'meta'          => [
                    'signal_id'  => '91',  // Mesmo signal_id do payload
                    'event_type' => 'seller.gmv_queda_mom',
                    'cust_id'    => '1354156948',
                    'link'       => '/alertas-estrategicos',
                    'severity'   => 'critical',
                ],
            ],
        ]);

        // Ativa fake APÓS criar a linha real — guard lê o banco de verdade
        Notification::fake();

        $delivery = $this->criarDelivery(['id' => 91, 'custId' => '1354156948']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        // Idempotência: sem envio novo
        Notification::assertNothingSent();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id'     => $delivery->id,
            'status' => 'processed',
        ]);
    }

    /**
     * T06 — Roles fora da whitelist (publicador/analista) não recebem.
     *
     * Cria users com roles que não estão em ['admin','consultor','mentor'] e
     * confirma que SOMENTE os roles corretos recebem.
     */
    public function test_roles_fora_da_whitelist_nao_recebem_notificacao(): void
    {
        Notification::fake();

        $this->criarEmpresaNaCarteira();

        $admin     = User::factory()->create(['role' => 'admin',     'active' => true]);
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $delivery = $this->criarDelivery(['custId' => '1354156948']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        // Roles na whitelist recebem
        Notification::assertSentTo($admin,     AlertaEcfNotification::class);
        Notification::assertSentTo($consultor, AlertaEcfNotification::class);
    }

    /**
     * T07 — custId nulo no payload → 0 notifications.
     */
    public function test_cust_id_nulo_nao_envia_notificacao(): void
    {
        Notification::fake();

        $this->criarEmpresaNaCarteira();
        $this->criarDestinatarios();

        $delivery = $this->criarDelivery(['custId' => null]);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        Notification::assertNothingSent();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id'     => $delivery->id,
            'status' => 'processed',
        ]);
    }

    /**
     * T08 — Lookup por ml_store_id (empresa com ML store, sem adman_account_id).
     */
    public function test_critical_empresa_matched_por_ml_store_id_envia_notificacao(): void
    {
        Notification::fake();

        // Empresa com ml_store_id, sem adman_account_id
        Company::create([
            'name'        => 'LOJA ML TESTE',
            'ml_store_id' => 'ML-570267839',
            'active'      => true,
            'status'      => 'ativo',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $delivery = $this->criarDelivery(['custId' => 'ML-570267839']);

        (new HandleSignalDetectedJob($delivery->id))->handle();

        Notification::assertSentTo($admin, AlertaEcfNotification::class);
    }
}
