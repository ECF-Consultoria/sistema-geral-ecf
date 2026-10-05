<?php

namespace Tests\Unit\Phase162;

use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\FalhaDeGeracaoTrocavel;
use App\Services\Creative\GeminiImageProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cobertura do caminho de julgamento do `GeminiImageProvider` (Fase 162,
 * D-06) — molde de `tests/Unit/GeminiImageProviderTest.php`. NENHUM teste
 * aqui chama a API real: tudo passa por `Http::fake()` com
 * `Http::preventStrayRequests()` ligado.
 */
class GeminiJuizProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.creative.gemini.base_url'      => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'            => 'chave-de-teste',
            'services.creative.gemini.judge_model'    => 'modelo-juiz-teste',
            'services.creative.gemini.judge_fallbacks' => '',
            'services.creative.gemini.timeout'         => 30,
            'services.creative.gemini.connect_timeout' => 10,
        ]);
    }

    private function respostaTexto(string $texto, string $modelo = 'modelo-juiz-teste'): array
    {
        return [
            'status' => 'completed',
            'model'  => $modelo,
            'steps'  => [
                ['type' => 'thought', 'signature' => 'abc123'],
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $texto]]],
            ],
        ];
    }

    /** (1) corpo sem response_format, com 1 bloco de texto + N de imagem na ordem recebida. */
    public function test_corpo_nao_tem_response_format_e_imagens_vao_na_ordem(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaTexto('```json{"ok":true}```'))]);

        $provider = app(GeminiImageProvider::class);
        $pedido = new CreativeJudgementRequest('julgue estas imagens', [
            ['mime' => 'image/jpeg', 'bytes' => 'original-1'],
            ['mime' => 'image/jpeg', 'bytes' => 'original-2'],
            ['mime' => 'image/png', 'bytes' => 'gerada'],
        ]);

        $provider->julgar($pedido);

        Http::assertSent(function ($request) {
            $corpo = $request->data();

            $this->assertArrayNotHasKey('response_format', $corpo);

            $input = $corpo['input'];
            $this->assertSame('text', $input[0]['type']);
            $this->assertSame('julgue estas imagens', $input[0]['text']);

            $this->assertSame('image', $input[1]['type']);
            $this->assertSame(base64_encode('original-1'), $input[1]['data']);
            $this->assertSame('image/jpeg', $input[1]['mime_type']);

            $this->assertSame('image', $input[2]['type']);
            $this->assertSame(base64_encode('original-2'), $input[2]['data']);

            $this->assertSame('image', $input[3]['type']);
            $this->assertSame(base64_encode('gerada'), $input[3]['data']);
            $this->assertSame('image/png', $input[3]['mime_type']);

            return true;
        });
    }

    /** (2) resposta na forma medida devolve o texto concatenado, com modelo e latência preenchidos. */
    public function test_resposta_na_forma_medida_devolve_texto_modelo_e_latencia(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaTexto('```json{"veredito":"aprovada"}```'))]);

        $provider = app(GeminiImageProvider::class);
        $resultado = $provider->julgar(new CreativeJudgementRequest('prompt', [
            ['mime' => 'image/jpeg', 'bytes' => 'bytes'],
        ]));

        $this->assertSame('```json{"veredito":"aprovada"}```', $resultado->texto);
        $this->assertSame('modelo-juiz-teste', $resultado->modelo);
        $this->assertGreaterThanOrEqual(0, $resultado->latenciaMs);
    }

    /** (3) 503 no principal cai no reserva e o reserva responde — UM fake com contador. */
    public function test_erro_trocavel_no_principal_cai_no_reserva(): void
    {
        config(['services.creative.gemini.judge_fallbacks' => 'modelo-juiz-reserva']);

        $chamadas = 0;
        Http::fake(function ($request) use (&$chamadas) {
            $chamadas++;

            return $request->data()['model'] === 'modelo-juiz-teste'
                ? Http::response(['error' => 'overloaded'], 503)
                : Http::response($this->respostaTexto('veredito da reserva', 'modelo-juiz-reserva'));
        });

        $provider = app(GeminiImageProvider::class);
        $resultado = $provider->julgar(new CreativeJudgementRequest('prompt', [
            ['mime' => 'image/jpeg', 'bytes' => 'bytes'],
        ]));

        $this->assertSame('modelo-juiz-reserva', $resultado->modelo);
        $this->assertSame('veredito da reserva', $resultado->texto);
        $this->assertSame(2, $chamadas);
        Http::assertSentCount(2);
    }

    /** (4) 200 com texto vazio em TODOS os modelos vira RuntimeException. */
    public function test_texto_vazio_em_todos_os_modelos_vira_runtime_exception(): void
    {
        config(['services.creative.gemini.judge_fallbacks' => 'modelo-juiz-reserva']);

        Http::fake(['gemini.teste/*' => Http::response($this->respostaTexto(''))]);

        $provider = app(GeminiImageProvider::class);

        try {
            $provider->julgar(new CreativeJudgementRequest('prompt', [
                ['mime' => 'image/jpeg', 'bytes' => 'bytes'],
            ]));
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (FalhaDeGeracaoTrocavel $e) {
            $this->fail('Depois de esgotar os modelos, deveria virar RuntimeException, não FalhaDeGeracaoTrocavel.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/vazio/i', $e->getMessage());
        }

        Http::assertSentCount(2);
    }

    /** (5) 401 não troca de modelo (uma chamada só) e a mensagem é a amigável em pt-BR. */
    public function test_401_nao_troca_de_modelo(): void
    {
        config(['services.creative.gemini.judge_fallbacks' => 'modelo-juiz-reserva']);

        Http::fake(['gemini.teste/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $provider = app(GeminiImageProvider::class);

        try {
            $provider->julgar(new CreativeJudgementRequest('prompt', [
                ['mime' => 'image/jpeg', 'bytes' => 'bytes'],
            ]));
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/chave/i', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    /** (6) o header x-goog-api-key é usado e a chave não aparece em nenhum log capturado. */
    public function test_header_de_autenticacao_e_chave_nunca_aparece_no_log(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaTexto('```json{"ok":true}```'))]);

        $logsCapturados = [];
        Log::listen(function ($evento) use (&$logsCapturados) {
            $logsCapturados[] = $evento->message . ' ' . json_encode($evento->context);
        });

        $provider = app(GeminiImageProvider::class);
        $provider->julgar(new CreativeJudgementRequest('prompt', [
            ['mime' => 'image/jpeg', 'bytes' => 'bytes'],
        ]));

        Http::assertSent(function ($request) {
            $this->assertTrue($request->hasHeader('x-goog-api-key'));
            $this->assertSame('chave-de-teste', $request->header('x-goog-api-key')[0]);

            return true;
        });

        $this->assertNotEmpty($logsCapturados, 'Deveria ter capturado ao menos um log de info do veredito.');

        foreach ($logsCapturados as $linha) {
            $this->assertStringNotContainsString('chave-de-teste', $linha);
            $this->assertStringNotContainsString(base64_encode('bytes'), $linha);
        }
    }
}
