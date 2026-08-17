<?php

namespace Tests\Feature\Phase126;

use App\Exceptions\ClicksignException;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 126 Plan 126-01 (CLICK-01, Task 2) — cobre o núcleo do
 * `ClicksignClient`: header sem "Bearer", Data URI do `content_base64`,
 * retry disciplinado (D-11) e o guard de tamanho do upload (gate #5).
 *
 * Instancia o client com o token literal 'token-clicksign-falso' (ação da
 * Task 2) para que as asserções de header sejam exatas.
 */
class ClicksignClientTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    #[Test]
    public function header_authorization_e_o_token_puro_sem_bearer(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
        ]);

        $this->client()->criarEnvelope(['name' => 'Contrato de teste']);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? null;

            return $auth === self::TOKEN
                && !str_contains((string) $auth, 'Bearer')
                && ($request->header('Accept')[0] ?? null) === 'application/vnd.api+json'
                && ($request->header('Content-Type')[0] ?? null) === 'application/vnd.api+json';
        });
    }

    #[Test]
    public function criar_envelope_devolve_o_bloco_data(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
        ]);

        $data = $this->client()->criarEnvelope(['name' => 'Contrato de teste']);

        $this->assertSame('00000000-0000-4000-8000-000000000001', $data['id']);
    }

    #[Test]
    public function anexar_documento_envia_content_base64_como_data_uri_completo(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
        ]);

        $this->client()->anexarDocumento(
            '00000000-0000-4000-8000-000000000001',
            'contrato.pdf',
            '%PDF-1.4 conteúdo binário falso de teste'
        );

        Http::assertSent(function ($request) {
            $conteudo = $request['data']['attributes']['content_base64'] ?? '';

            return str_starts_with($conteudo, 'data:application/pdf;base64,');
        });
    }

    #[Test]
    public function retry_acontece_em_429_ate_esgotar_as_tentativas(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::sequence()
                ->push(['errors' => [['code' => 'rate_limited', 'status' => 429]]], 429)
                ->push(['errors' => [['code' => 'rate_limited', 'status' => 429]]], 429)
                ->push(['errors' => [['code' => 'rate_limited', 'status' => 429]]], 429),
        ]);

        try {
            $this->client()->criarEnvelope(['name' => 'x']);
            $this->fail('Esperava ClicksignException por 429 esgotado.');
        } catch (ClicksignException $e) {
            $this->assertSame(429, $e->httpStatus);
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function retry_acontece_em_500_ate_esgotar_as_tentativas(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::sequence()
                ->push(['errors' => [['code' => 'server_error', 'status' => 500]]], 500)
                ->push(['errors' => [['code' => 'server_error', 'status' => 500]]], 500)
                ->push(['errors' => [['code' => 'server_error', 'status' => 500]]], 500),
        ]);

        try {
            $this->client()->criarEnvelope(['name' => 'x']);
            $this->fail('Esperava ClicksignException por 500 esgotado.');
        } catch (ClicksignException $e) {
            $this->assertSame(500, $e->httpStatus);
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function nao_retenta_em_400_erro_de_dado(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(
                ClicksignSandboxFixtures::erroContentBase64NaoDataUri(),
                400
            ),
        ]);

        try {
            $this->client()->anexarDocumento('00000000-0000-4000-8000-000000000001', 'contrato.pdf', 'binario');
            $this->fail('Esperava ClicksignException por 400.');
        } catch (ClicksignException $e) {
            $this->assertSame(400, $e->httpStatus);
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function nao_retenta_em_422_erro_de_dado(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422]]],
                422
            ),
        ]);

        try {
            $this->client()->criarEnvelope(['name' => 'x']);
            $this->fail('Esperava ClicksignException por 422.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        Http::assertSentCount(1);
    }

    /**
     * 401 e 403 são cobertos em dois testes independentes (não dois
     * `Http::fake()` no mesmo método): `Factory::fake()` acumula stubs numa
     * Collection e resolve pelo PRIMEIRO match — um segundo `Http::fake()`
     * para a mesma URL, dentro do mesmo teste, não substitui o primeiro (a
     * resposta 401 continuaria "vencendo"). Cada teste assere o texto
     * distintivo da PRÓPRIA mensagem (prova indireta de que são diferentes
     * — a Task 1 já prova a diferença diretamente em `ClicksignException::
     * fromResponse()`; aqui o que se prova é que o client PROPAGA a
     * mensagem certa por status, sem reescrevê-la).
     */
    #[Test]
    public function mensagem_de_401_menciona_token_invalido(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::response(ClicksignSandboxFixtures::erro401TokenInvalido(), 401),
        ]);

        try {
            $this->client()->criarEnvelope(['name' => 'x']);
            $this->fail('Esperava ClicksignException por 401.');
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('token', $e->getMessage());
            $this->assertSame(401, $e->httpStatus);
        }
    }

    /**
     * Fase 126 Plan 126-07 (D-16) — a mensagem de 403 passou a compor o
     * `detail` da própria API com as DUAS causas conhecidas (email da API
     * não configurado × conta sem acesso a modelos). A asserção não pode
     * afrouxar: o caso do e-mail da API continua apontando literalmente
     * "Configurações → API", não só citando o e-mail em abstrato.
     */
    #[Test]
    public function mensagem_de_403_menciona_email_do_usuario_da_api(): void
    {
        Http::fake([
            self::BASE . '/envelopes' => Http::response(ClicksignSandboxFixtures::erro403EmailApiNaoConfigurado(), 403),
        ]);

        try {
            $this->client()->criarEnvelope(['name' => 'x']);
            $this->fail('Esperava ClicksignException por 403.');
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('e-mail do usuário da API', $e->getMessage());
            $this->assertStringContainsString('Configurações → API', $e->getMessage());
            $this->assertSame(403, $e->httpStatus);
        }
    }

    #[Test]
    public function pdf_acima_do_limite_lanca_excecao_antes_de_qualquer_requisicao(): void
    {
        Http::fake();
        config(['services.clicksign.max_upload_bytes' => 10]);

        $pdfGrande = str_repeat('A', 20);

        try {
            $this->client()->anexarDocumento('00000000-0000-4000-8000-000000000001', 'contrato.pdf', $pdfGrande);
            $this->fail('Esperava ClicksignException pelo limite de tamanho.');
        } catch (ClicksignException $e) {
            // guarda disparou antes de qualquer chamada HTTP
        }

        Http::assertNothingSent();
    }
}
