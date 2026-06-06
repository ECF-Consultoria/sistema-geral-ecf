<?php

namespace Tests\Feature\Phase26;

use App\Jobs\EcfWebhook\HandleEtlCompletedJob;
use App\Jobs\EcfWebhook\HandleGrantExpirandoJob;
use App\Jobs\EcfWebhook\HandleRelatorioGeradoJob;
use App\Jobs\EcfWebhook\HandleSignalDetectedJob;
use App\Jobs\EcfWebhook\HandleSyncCompletedJob;
use App\Jobs\EcfWebhook\HandleSyncFailedJob;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Phase 26 — Receiver de webhooks ECF Drive.
 *
 * Cobre os 4 caminhos críticos do EcfWebhookController:
 *  1. HMAC válido + payload conhecido → 200 OK + Job despachado + persistência
 *  2. HMAC inválido → 401 + nenhum job + nenhuma persistência
 *  3. Idempotência por event_id (mesmo id 2× = 200 ambos mas Job só 1×)
 *  4. Evento desconhecido → 200 OK + log + nenhum dispatch
 */
class EcfWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-fake-not-real-for-test-only';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.ecf.webhook_secret', self::SECRET);
    }

    /** Calcula a assinatura HMAC SHA256 no formato sha256=<hex>. */
    private function assinar(string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    }

    /** Dispara o webhook com HMAC e body informados. */
    private function dispararWebhook(array $payload, ?string $signature = null)
    {
        $rawBody   = json_encode($payload);
        $signature ??= $this->assinar($rawBody);

        return $this->postJson(
            route('ecf-webhook.receive'),
            $payload,
            ['X-ECF-Signature' => $signature]
        );
    }

    public function test_assinatura_valida_persiste_e_despacha_job(): void
    {
        Bus::fake();

        $response = $this->dispararWebhook([
            'event'    => 'etl.completed',
            'event_id' => 'evt-test-001',
            'data'     => ['filename' => 'teste.csv'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('webhook_deliveries', [
            'event_id'        => 'evt-test-001',
            'event_type'      => 'etl.completed',
            'signature_valid' => true,
            'status'          => 'received',
        ]);
        Bus::assertDispatched(HandleEtlCompletedJob::class);
    }

    public function test_assinatura_invalida_retorna_401_e_nao_persiste(): void
    {
        Bus::fake();

        $response = $this->dispararWebhook(
            ['event' => 'etl.completed', 'event_id' => 'evt-bad-001'],
            signature: 'sha256=' . str_repeat('0', 64)
        );

        $response->assertStatus(401);
        $this->assertDatabaseMissing('webhook_deliveries', ['event_id' => 'evt-bad-001']);
        Bus::assertNothingDispatched();
    }

    public function test_header_signature_ausente_retorna_401(): void
    {
        Bus::fake();

        $response = $this->postJson(
            route('ecf-webhook.receive'),
            ['event' => 'etl.completed', 'event_id' => 'evt-noheader']
        );

        $response->assertStatus(401);
        Bus::assertNothingDispatched();
    }

    public function test_idempotencia_event_id_duplicado_nao_dispatcha_2x(): void
    {
        Bus::fake();

        $payload = [
            'event'    => 'sync.completed',
            'event_id' => 'evt-dup-001',
            'data'     => [],
        ];

        $first  = $this->dispararWebhook($payload);
        $second = $this->dispararWebhook($payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(1, WebhookDelivery::where('event_id', 'evt-dup-001')->count());
        Bus::assertDispatchedTimes(HandleSyncCompletedJob::class, 1);
    }

    public function test_evento_desconhecido_retorna_200_sem_dispatch(): void
    {
        Bus::fake();

        $response = $this->dispararWebhook([
            'event'    => 'evento.inventado',
            'event_id' => 'evt-unknown-001',
            'data'     => [],
        ]);

        $response->assertOk();
        Bus::assertNothingDispatched();
    }

    public function test_dispatch_correto_por_tipo_de_evento(): void
    {
        Bus::fake();

        $eventos = [
            'sync.completed'   => HandleSyncCompletedJob::class,
            'sync.failed'      => HandleSyncFailedJob::class,
            'etl.completed'    => HandleEtlCompletedJob::class,
            'grant.expirando'  => HandleGrantExpirandoJob::class,
            'signal.detected'  => HandleSignalDetectedJob::class,
            'relatorio.gerado' => HandleRelatorioGeradoJob::class,
        ];

        foreach ($eventos as $evento => $jobEsperado) {
            $this->dispararWebhook([
                'event'    => $evento,
                'event_id' => "evt-{$evento}-once",
                'data'     => [],
            ])->assertOk();
        }

        foreach ($eventos as $jobEsperado) {
            Bus::assertDispatched($jobEsperado);
        }
    }
}
