<?php

namespace Tests\Feature\Phase129;

use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de que `clicksign:verificar-assinatura` (D-09) consegue dizer, a
 * partir de um evento já gravado, por qual das 4 candidatas a assinatura
 * confere — e recusa responder quando o corpo foi truncado.
 */
class ClicksignVerificarAssinaturaCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-de-teste-do-comando-129';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clicksign.webhook_secret' => self::SECRET]);
    }

    #[Test]
    public function identifica_a_candidata_vencedora_de_um_evento_gravado(): void
    {
        $rawBody = '{"event":{"name":"sign"},"data":{"id":"env-1"}}';
        $header  = ClicksignHmacVarredura::calcular($rawBody, self::SECRET, 'soma_secret_body');

        $evento = ContratoAssinaturaEvento::create([
            'payload'      => ['_headers' => ['content-hmac' => $header]],
            'raw_body'     => $rawBody,
            'raw_truncado' => false,
            'payload_hash' => hash('sha256', $rawBody),
            'status'       => ContratoAssinaturaEvento::STATUS_RECEBIDO,
        ]);

        $this->artisan('clicksign:verificar-assinatura', ['--evento' => $evento->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('soma_secret_body');
    }

    #[Test]
    public function recusa_verificar_evento_com_corpo_truncado(): void
    {
        $evento = ContratoAssinaturaEvento::create([
            'payload'      => ['_headers' => ['content-hmac' => 'sha256=' . str_repeat('a', 64)]],
            'raw_body'     => str_repeat('x', 100),
            'raw_truncado' => true,
            'payload_hash' => hash('sha256', str_repeat('x', 65_100)),
            'status'       => ContratoAssinaturaEvento::STATUS_RECEBIDO,
        ]);

        $this->artisan('clicksign:verificar-assinatura', ['--evento' => $evento->id])
            ->assertExitCode(1);
    }

    #[Test]
    public function sem_eventos_no_banco_ultimo_sai_com_falha_sem_stack_trace(): void
    {
        $this->artisan('clicksign:verificar-assinatura', ['--ultimo' => true])
            ->assertExitCode(1);
    }
}
