<?php

namespace Tests\Feature\Phase126;

use App\Exceptions\ClicksignException;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 126 Plan 126-01 (CLICK-01, Task 3) — prova do Success Criteria 1 da
 * fase: "nenhuma linha de log jamais contém o token", em sucesso e em erro,
 * na mensagem e no contexto serializado.
 *
 * Molde: `tests/Feature/Phase111HubspotApiClientTest.php` linhas 249-274
 * (mock de `Psr\Log\LoggerInterface` + `Log::shouldReceive('channel')`).
 * O vetor real de vazamento não é a mensagem da `RequestException` (ela só
 * ecoa o CORPO da resposta, truncado) — é `Response->transferStats
 * ->getRequest()`, que carrega os headers da requisição ORIGINAL, incluindo
 * `Authorization` (confirmado em
 * `vendor/laravel/framework/src/Illuminate/Http/Client/Response.php:484`,
 * usado internamente por `dump()`). Ver
 * `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-RESEARCH.md`
 * §Pitfall 2.
 */
class ClicksignClientNaoVazaTokenTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso-nao-deve-vazar';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    /**
     * Mocka o canal `ecf-webhooks` com um `LoggerInterface` que reprova
     * qualquer chamada (mensagem OU contexto serializado) contendo o token
     * literal ou a string "Bearer ".
     */
    private function mockLoggerSemVazamento(): void
    {
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);

        $semVazamento = function ($message, $context = []) {
            $ctxJson = json_encode($context);

            return !str_contains((string) $message, self::TOKEN)
                && !str_contains((string) $message, 'Bearer')
                && !str_contains((string) $ctxJson, self::TOKEN)
                && !str_contains((string) $ctxJson, 'Bearer ');
        };

        $logger->shouldReceive('info')->withArgs($semVazamento)->zeroOrMoreTimes();
        $logger->shouldReceive('warning')->withArgs($semVazamento)->zeroOrMoreTimes();
        $logger->shouldReceive('error')->withArgs($semVazamento)->zeroOrMoreTimes();

        Log::shouldReceive('channel')->with('ecf-webhooks')->andReturn($logger);
    }

    // ─── Caminho de sucesso ───

    #[Test]
    public function sucesso_criar_envelope_nao_vaza_token_no_log(): void
    {
        $this->mockLoggerSemVazamento();

        Http::fake([
            self::BASE . '/envelopes' => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
        ]);

        $this->client()->criarEnvelope(['name' => 'Contrato de teste']);

        $this->assertTrue(true); // mock reprova sozinho se algo vazar
    }

    #[Test]
    public function sucesso_anexar_documento_nao_vaza_token_no_log(): void
    {
        $this->mockLoggerSemVazamento();

        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
        ]);

        $this->client()->anexarDocumento('00000000-0000-4000-8000-000000000001', 'contrato.pdf', 'binario de teste');

        $this->assertTrue(true);
    }

    // ─── Caminho de erro (400, 401, 429, 500) ───

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>}>
     */
    public static function statusDeErro(): array
    {
        return [
            '400 content_base64' => [400, ClicksignSandboxFixtures::erroContentBase64NaoDataUri()],
            '401 token invalido' => [401, ClicksignSandboxFixtures::erro401TokenInvalido()],
            '429 rate limit'     => [429, ['errors' => [['code' => 'rate_limited', 'status' => 429]]]],
            '500 erro servidor'  => [500, ['errors' => [['code' => 'server_error', 'status' => 500]]]],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('statusDeErro')]
    public function erro_nao_vaza_token_no_log_nem_na_mensagem_da_excecao(int $status, array $corpo): void
    {
        $this->mockLoggerSemVazamento();

        Http::fake([
            self::BASE . '/envelopes' => Http::response($corpo, $status),
        ]);

        try {
            $this->client()->criarEnvelope(['name' => 'Contrato de teste']);
            $this->fail("Esperava ClicksignException para status {$status}.");
        } catch (ClicksignException $e) {
            $this->assertStringNotContainsString(
                self::TOKEN,
                $e->getMessage(),
                'A mensagem da ClicksignException não pode conter o token — vetor: mensagem propagada até a tela.'
            );
            $this->assertStringNotContainsString('Bearer', $e->getMessage());
        }
    }

    // ─── Guarda estática ───

    /**
     * Mesmo helper de `MigrationsFase125ConvencoesTest::migrationSemComentarios()`
     * — remove comentários ANTES de qualquer checagem de padrão. Passo
     * obrigatório: o próprio `ClicksignClient` documenta em comentário por
     * que não usa `withToken()`, então sem remover comentários a guarda
     * acusaria a si mesma e seria inútil por construção.
     */
    private function clientSemComentarios(): string
    {
        $conteudo = file_get_contents(app_path('Services/Clicksign/ClicksignClient.php'));

        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);
        $semLinhas = preg_replace('/\/\/.*$/m', '', $semBlocos);

        return $semLinhas;
    }

    #[Test]
    public function client_nao_usa_with_token(): void
    {
        $this->assertStringNotContainsString(
            'withToken(',
            $this->clientSemComentarios(),
            'ClicksignClient usa Http::withToken() — esse helper do Laravel sempre prefixa "Bearer " no Authorization, e a Clicksign devolve 401 com o prefixo (medido em CLICKSIGN-SANDBOX-EMPIRICO.md §1).'
        );
    }

    #[Test]
    public function client_nao_acessa_transfer_stats(): void
    {
        $this->assertStringNotContainsString(
            'transferStats',
            $this->clientSemComentarios(),
            'ClicksignClient acessa Response->transferStats — esse é o vetor REAL de vazamento do token: transferStats->getRequest() carrega os headers da requisição original, incluindo Authorization (vendor/laravel/framework/src/Illuminate/Http/Client/Response.php:484).'
        );
    }

    #[Test]
    public function client_nao_usa_dump_dd_ou_handler_stats(): void
    {
        $codigo = $this->clientSemComentarios();

        foreach (['->dump(', '->dd(', 'handlerStats'] as $padrao) {
            $this->assertStringNotContainsString(
                $padrao,
                $codigo,
                "ClicksignClient usa '{$padrao}' — qualquer um desses caminhos pode expor o objeto Response inteiro (e, por transitividade, transferStats->getRequest() com o Authorization) num log ou na saída padrão."
            );
        }
    }

    #[Test]
    public function client_nao_faz_json_encode_da_resposta_inteira(): void
    {
        $this->assertStringNotContainsString(
            'json_encode($res',
            $this->clientSemComentarios(),
            'ClicksignClient serializa a resposta inteira com json_encode($res...) — risco de incluir headers da requisição original via transferStats.'
        );
    }

    /**
     * Nenhuma chamada `Log::channel('ecf-webhooks')->...` do client pode
     * passar `$res`/`$response`/`$e`/`$exception` como VALOR de contexto —
     * só campos nomeados extraídos deles (`$res->status()`,
     * `$res->json('errors.0.code')`, etc.) são aceitáveis. O regex aceita
     * `$res->algo(...)` (extração de campo) e reprova `$res` isolado
     * (variável inteira sendo passada).
     */
    #[Test]
    public function nenhum_log_do_client_passa_resposta_ou_excecao_inteira_como_contexto(): void
    {
        $codigo = $this->clientSemComentarios();

        $this->assertDoesNotMatchRegularExpression(
            '/=>\s*\$(res|response|e|exception)(?!\s*->)\b/',
            $codigo,
            'ClicksignClient passa $res/$response/$e/$exception inteiro como valor de contexto num Log:: — a resposta de erro da Clicksign pode ecoar nome/e-mail do signatário (achado WR-11 da Fase 125), e a resposta de sucesso carrega transferStats->getRequest() com o Authorization.'
        );
    }
}
