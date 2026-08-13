<?php

namespace Tests\Feature\Phase129;

use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova da rota-sonda TEMPORÁRIA do gate A1 (D-07/D-08) — recebe POST
 * público sem CSRF, mede as 4 candidatas de HMAC, grava o corpo bruto e
 * sempre responde 200 (nunca faz a Clicksign parar de entregar).
 */
class ClicksignSondaHmacTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-de-teste-da-sonda-129';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clicksign.webhook_secret' => self::SECRET]);
    }

    #[Test]
    public function grava_evento_com_json_valido_e_marca_a_candidata_vencedora(): void
    {
        $corpo  = json_encode(['event' => ['name' => 'sign'], 'data' => ['id' => 'env-abc']]);
        $header = ClicksignHmacVarredura::calcular($corpo, self::SECRET, 'hmac_secret_chave_body');

        $response = $this->call('POST', '/api/webhooks/clicksign-sonda', [], [], [], [
            'HTTP_Content-Hmac'  => $header,
            'CONTENT_TYPE'       => 'application/json',
        ], $corpo);

        $response->assertStatus(200);

        $this->assertDatabaseCount('contrato_assinatura_eventos', 1);

        $evento = ContratoAssinaturaEvento::first();

        $this->assertSame('sonda', $evento->origem);
        $this->assertSame($corpo, $evento->raw_body);
        $this->assertTrue($evento->payload['_sonda']['hmac_secret_chave_body']);
    }

    #[Test]
    public function corpo_nao_json_tambem_responde_200_e_grava_motivo(): void
    {
        $corpo = 'nao-e-json';

        $response = $this->call('POST', '/api/webhooks/clicksign-sonda', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $corpo);

        $response->assertStatus(200);

        $evento = ContratoAssinaturaEvento::first();

        $this->assertNotNull($evento);
        $this->assertSame('json invalido', $evento->payload['motivo']);
    }

    #[Test]
    public function dois_posts_identicos_geram_uma_linha_so(): void
    {
        $corpo = json_encode(['event' => ['name' => 'sign']]);

        $primeira = $this->call('POST', '/api/webhooks/clicksign-sonda', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $corpo);

        $segunda = $this->call('POST', '/api/webhooks/clicksign-sonda', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $corpo);

        $primeira->assertStatus(200);
        $segunda->assertStatus(200);

        $this->assertDatabaseCount('contrato_assinatura_eventos', 1);
    }

    #[Test]
    public function rota_nao_exige_token_csrf(): void
    {
        $response = $this->call('POST', '/api/webhooks/clicksign-sonda', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $response->assertStatus(200);
        $this->assertNotSame(419, $response->getStatusCode());
    }
}
