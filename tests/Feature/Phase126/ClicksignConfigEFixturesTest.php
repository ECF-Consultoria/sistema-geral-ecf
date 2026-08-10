<?php

namespace Tests\Feature\Phase126;

use App\Exceptions\ClicksignException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 126 Plan 126-01 (CLICK-01, Task 1) — cobre o bloco `<behavior>` da
 * fundação do ClicksignClient: config, `ClicksignException` e a varredura de
 * PII nas fixtures do sandbox (D-15 / achado WR-07 da Fase 125).
 */
class ClicksignConfigEFixturesTest extends TestCase
{
    // ─── config('services.clicksign.*') ───

    #[Test]
    public function base_url_resolve_para_o_sandbox_por_padrao(): void
    {
        $this->assertSame(
            'https://sandbox.clicksign.com/api/v3',
            config('services.clicksign.base_url'),
        );
    }

    #[Test]
    public function access_token_existe_como_chave_de_config(): void
    {
        $this->assertArrayHasKey('access_token', config('services.clicksign'));
    }

    #[Test]
    public function max_upload_bytes_tem_default_de_20_mb(): void
    {
        $this->assertSame(20971520, config('services.clicksign.max_upload_bytes'));
    }

    // ─── ClicksignException ───

    #[Test]
    public function mensagem_de_401_difere_de_403(): void
    {
        $exception401 = ClicksignException::fromResponse(401, []);
        $exception403 = ClicksignException::fromResponse(403, []);

        $this->assertNotSame($exception401->getMessage(), $exception403->getMessage());
    }

    #[Test]
    public function exception_expoe_http_status_codigo_api_e_ponteiro(): void
    {
        $corpo = ClicksignSandboxFixtures::erroContentBase64NaoDataUri();

        $exception = ClicksignException::fromResponse(400, $corpo);

        $this->assertSame(400, $exception->httpStatus);
        $this->assertSame('bad_request', $exception->codigoApi);
        $this->assertSame('/data/attributes/content_base64', $exception->ponteiro);
    }

    #[Test]
    public function mensagem_de_429_fala_de_limite_de_requisicoes(): void
    {
        $exception = ClicksignException::fromResponse(429, []);

        $this->assertStringContainsString('limite de requisições', $exception->getMessage());
    }

    // ─── Varredura de PII nas fixtures (D-15 / WR-07) ───

    /**
     * @return array<int, array{0: string}>
     */
    public static function metodosDeFixture(): array
    {
        return [
            ['envelopeCriado'],
            ['documentoCriado'],
            ['signatarioCriado'],
            ['requisitoCriado'],
            ['envelopeAtivado'],
            ['envelopeCancelado'],
            ['eventoSignDoDocumento'],
            ['erroContentBase64NaoDataUri'],
            ['erro401TokenInvalido'],
            ['erro403EmailApiNaoConfigurado'],
            ['erro429NotificacaoTexto'],
        ];
    }

    #[Test]
    public function nenhuma_fixture_contem_email_fora_do_dominio_de_exemplo(): void
    {
        foreach (self::metodosDeFixture() as [$metodo]) {
            $json = json_encode(ClicksignSandboxFixtures::$metodo());

            preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $json, $emails);

            foreach ($emails[0] as $email) {
                $this->assertMatchesRegularExpression(
                    '/@example\.(com|org)$/',
                    $email,
                    "ClicksignSandboxFixtures::{$metodo}() contém o e-mail '{$email}' fora de @example.com/@example.org — risco de PII real no repositório (achado WR-07 da Fase 125)."
                );
            }
        }
    }

    #[Test]
    public function nenhuma_fixture_contem_ip_fora_da_faixa_rfc_5737(): void
    {
        foreach (self::metodosDeFixture() as [$metodo]) {
            $json = json_encode(ClicksignSandboxFixtures::$metodo());

            preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $json, $ips);

            foreach ($ips[0] as $ip) {
                $this->assertMatchesRegularExpression(
                    '/^203\.0\.113\./',
                    $ip,
                    "ClicksignSandboxFixtures::{$metodo}() contém o IP '{$ip}' fora da faixa RFC 5737 (203.0.113.0/24) — risco de IP público real no repositório (achado WR-07 da Fase 125)."
                );
            }
        }
    }

    #[Test]
    public function nenhuma_fixture_contem_uuid_fora_do_padrao_sintetico(): void
    {
        $padraoUuid = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';
        $padraoSintetico = '/^00000000-0000-4000-8000-0000000000[0-9a-f]{2}$/i';

        foreach (self::metodosDeFixture() as [$metodo]) {
            $json = json_encode(ClicksignSandboxFixtures::$metodo());

            preg_match_all($padraoUuid, $json, $uuids);

            foreach ($uuids[0] as $uuid) {
                $this->assertMatchesRegularExpression(
                    $padraoSintetico,
                    $uuid,
                    "ClicksignSandboxFixtures::{$metodo}() contém o UUID '{$uuid}' fora do padrão sintético 00000000-0000-4000-8000-0000000000NN — risco de chave real de signatário no repositório (achado WR-07 da Fase 125)."
                );
            }
        }
    }
}
