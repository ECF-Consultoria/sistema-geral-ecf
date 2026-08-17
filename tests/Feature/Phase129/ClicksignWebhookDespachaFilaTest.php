<?php

namespace Tests\Feature\Phase129;

use App\Jobs\ProcessarEventoClicksignJob;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite Feature — Fase 129, plano 129-03, Task 3 (CLICK-06 — janela
 * síncrona nunca fala com a Clicksign).
 */
class ClicksignWebhookDespachaFilaTest extends TestCase
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
                'key'    => '00000000-0000-4000-8000-000000000022',
                'status' => 'running',
            ],
        ]);
    }

    #[Test]
    public function post_valido_responde_200_sem_nenhuma_chamada_a_clicksign_e_enfileira_o_job(): void
    {
        Queue::fake();
        // Configurado para FALHAR em qualquer chamada — se o controller
        // tentar falar com a Clicksign na janela síncrona, o teste
        // estoura em vez de passar silenciosamente.
        Http::fake(function () {
            throw new \RuntimeException('Nenhuma chamada à Clicksign deveria acontecer na janela síncrona do webhook.');
        });

        $body   = $this->corpoValido();
        $header = ClicksignHmacVarredura::calcular($body, self::SECRET, ClicksignHmacVarredura::FORMULA_CONFIRMADA);
        $server = $this->servidor(['Content-Hmac' => $header]);

        $response = $this->call('POST', self::URL, [], [], [], $server, $body);

        $response->assertStatus(200);

        Http::assertNothingSent();
        Queue::assertPushed(ProcessarEventoClicksignJob::class);
    }
}
