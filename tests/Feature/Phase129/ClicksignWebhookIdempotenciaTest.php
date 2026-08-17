<?php

namespace Tests\Feature\Phase129;

use App\Jobs\ProcessarEventoClicksignJob;
use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite Feature — Fase 129, plano 129-03, Task 3 (CLICK-04 — idempotência).
 */
class ClicksignWebhookIdempotenciaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-clicksign-testes-32-chars-zz';
    private const URL    = '/api/webhooks/clicksign';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clicksign.webhook_secret' => self::SECRET]);
    }

    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }

        return $out;
    }

    private function corpoValido(): string
    {
        return json_encode([
            'event'    => [
                'name'        => 'add_signer',
                'occurred_at' => '2026-08-13T10:27:02.931-03:00',
            ],
            'document' => [
                'key'    => '00000000-0000-4000-8000-000000000021',
                'status' => 'running',
            ],
        ]);
    }

    #[Test]
    public function dois_posts_identicos_geram_200_nos_dois_uma_linha_e_um_unico_job_enfileirado(): void
    {
        Queue::fake();

        $body   = $this->corpoValido();
        $header = ClicksignHmacVarredura::calcular($body, self::SECRET, ClicksignHmacVarredura::FORMULA_CONFIRMADA);
        $server = $this->servidor(['Content-Hmac' => $header]);

        $primeira  = $this->call('POST', self::URL, [], [], [], $server, $body);
        $segunda   = $this->call('POST', self::URL, [], [], [], $server, $body);

        $primeira->assertStatus(200);
        $segunda->assertStatus(200);

        $this->assertSame(1, ContratoAssinaturaEvento::count());

        // O segundo POST não enfileira de novo — enfileirar duas vezes
        // reprocessaria (CLICK-04).
        Queue::assertPushed(ProcessarEventoClicksignJob::class, 1);
    }
}
