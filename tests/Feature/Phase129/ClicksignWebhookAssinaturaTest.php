<?php

namespace Tests\Feature\Phase129;

use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite Feature — Fase 129, plano 129-03, Task 3 (CLICK-03 + DADOS-03).
 *
 * Secret literal de fixture, nunca o `.env` real — mesma disciplina de
 * `Phase34HubspotWebhookTest`.
 */
class ClicksignWebhookAssinaturaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-clicksign-testes-32-chars-zz';
    private const URL    = '/api/webhooks/clicksign';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clicksign.webhook_secret' => self::SECRET]);
    }

    private function assinatura(string $body, string $secret = self::SECRET): string
    {
        return ClicksignHmacVarredura::calcular($body, $secret, ClicksignHmacVarredura::FORMULA_CONFIRMADA);
    }

    /**
     * Converte headers em formato $_SERVER (HTTP_X_*) — necessário quando
     * usamos `$this->call()` direto (que NÃO aplica `defaultHeaders` do
     * `withHeaders()`). Mesmo helper de `Phase34HubspotWebhookTest`.
     */
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
                'key'    => '00000000-0000-4000-8000-000000000020',
                'status' => 'running',
            ],
        ]);
    }

    #[Test]
    public function header_ausente_responde_401_e_grava_uma_linha_com_signature_valid_falso(): void
    {
        $body = $this->corpoValido();

        $response = $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);

        $this->assertSame(1, ContratoAssinaturaEvento::count());
        $evento = ContratoAssinaturaEvento::first();
        $this->assertFalse((bool) $evento->signature_valid);
        $this->assertSame(ContratoAssinaturaEvento::STATUS_ERRO, $evento->status);
    }

    #[Test]
    public function header_errado_responde_401_e_grava_a_linha(): void
    {
        $body = $this->corpoValido();

        $response = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'Content-Hmac' => 'sha256=' . str_repeat('0', 64),
        ]), $body);

        $response->assertStatus(401);

        $this->assertSame(1, ContratoAssinaturaEvento::count());
        $this->assertFalse((bool) ContratoAssinaturaEvento::first()->signature_valid);
    }

    #[Test]
    public function header_certo_responde_200_e_grava_linha_com_signature_valid_verdadeiro(): void
    {
        $body   = $this->corpoValido();
        $header = $this->assinatura($body);

        $response = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'Content-Hmac' => $header,
        ]), $body);

        $response->assertStatus(200);

        $this->assertSame(1, ContratoAssinaturaEvento::count());
        $evento = ContratoAssinaturaEvento::first();
        $this->assertTrue((bool) $evento->signature_valid);
        $this->assertSame('add_signer', $evento->name);
    }

    #[Test]
    public function corpo_nao_json_com_header_certo_responde_200_e_grava_motivo(): void
    {
        $body   = 'isto nao e json';
        $header = $this->assinatura($body);

        $response = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'Content-Hmac' => $header,
        ]), $body);

        $response->assertStatus(200);

        $this->assertSame(1, ContratoAssinaturaEvento::count());
        $evento = ContratoAssinaturaEvento::first();
        $this->assertTrue((bool) $evento->signature_valid);
        $this->assertSame('json invalido', $evento->payload['motivo']);
    }
}
