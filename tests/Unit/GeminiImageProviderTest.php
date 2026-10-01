<?php

namespace Tests\Unit;

use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\FalhaDeGeracaoTrocavel;
use App\Services\Creative\GeminiImageProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobertura do `GeminiImageProvider` — Creative Engine V0.1 (spike,
 * 261001-nkx). NENHUM teste aqui chama a API real da Gemini: tudo passa por
 * `Http::fake()` com `Http::preventStrayRequests()` ligado. A prova REAL de
 * fidelidade é o comando `creative:test-gemini` (manual, custa crédito).
 *
 * Sem `RefreshDatabase`: nada aqui toca banco.
 */
class GeminiImageProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pedido sem stub vira exceção em vez de ir à internet.
        Http::preventStrayRequests();

        config([
            'services.creative.gemini.base_url'        => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'              => 'chave-de-teste',
            'services.creative.gemini.text_model'        => 'modelo-texto-teste',
            'services.creative.gemini.image_model'       => 'modelo-imagem-teste',
            // Sem reserva por padrão: cada teste que quer a troca de modelo
            // liga a sua, senão o reserva entraria escondido em todo teste.
            'services.creative.gemini.image_fallbacks'   => '',
            'services.creative.gemini.aspect_ratio'      => '1:1',
            'services.creative.gemini.image_size'        => '2K',
            'services.creative.gemini.mime'              => 'image/jpeg',
            'services.creative.gemini.timeout'           => 30,
            'services.creative.gemini.connect_timeout'   => 10,
        ]);
    }

    /** Corpo de resposta de sucesso de imagem, formato Interactions API. */
    private function respostaImagemOk(string $modelo = 'modelo-imagem-teste'): array
    {
        return [
            'interaction' => [
                'output_image' => [
                    'data'      => base64_encode('bytes-falsos-jpeg'),
                    'mime_type' => 'image/jpeg',
                ],
            ],
            'model' => $modelo,
        ];
    }

    public function test_gera_imagem_com_sucesso(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $provider = app(GeminiImageProvider::class);
        $resultado = $provider->gerarImagem(new CreativeGenerationRequest('gere uma foto de produto'));

        $this->assertSame('bytes-falsos-jpeg', $resultado->bytes);
        $this->assertSame('image/jpeg', $resultado->mime);
        $this->assertSame('modelo-imagem-teste', $resultado->modelo);
        $this->assertGreaterThanOrEqual(0, $resultado->latenciaMs);
        $this->assertSame('sucesso', $resultado->status);
    }

    public function test_chave_ausente_falha_sem_chamar_a_api(): void
    {
        config(['services.creative.gemini.key' => '']);

        $provider = app(GeminiImageProvider::class);

        try {
            $provider->gerarImagem(new CreativeGenerationRequest('prompt qualquer'));
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/GEMINI_API_KEY/', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_erro_trocavel_tenta_o_proximo_modelo(): void
    {
        config(['services.creative.gemini.image_fallbacks' => 'modelo-reserva']);

        Http::fake(fn ($req) => $req['model'] === 'modelo-imagem-teste'
            ? Http::response(['error' => 'overloaded'], 503)
            : Http::response($this->respostaImagemOk('modelo-reserva')));

        $provider = app(GeminiImageProvider::class);
        $resultado = $provider->gerarImagem(new CreativeGenerationRequest('prompt qualquer'));

        $this->assertSame('modelo-reserva', $resultado->modelo);
        Http::assertSentCount(2);
    }

    public function test_erro_definitivo_nao_troca_de_modelo(): void
    {
        config(['services.creative.gemini.image_fallbacks' => 'modelo-reserva']);

        Http::fake(['gemini.teste/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $provider = app(GeminiImageProvider::class);

        try {
            $provider->gerarImagem(new CreativeGenerationRequest('prompt qualquer'));
            $this->fail('Deveria ter lançado RuntimeException (não FalhaDeGeracaoTrocavel).');
        } catch (FalhaDeGeracaoTrocavel $e) {
            $this->fail('Erro definitivo (401) não deveria virar FalhaDeGeracaoTrocavel.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/chave/i', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_200_sem_imagem_e_tratado(): void
    {
        // Internamente, `gerarImagemComModelo` lança `FalhaDeGeracaoTrocavel`
        // (ver GeminiImageProvider) — mas sem fallback configurado, a lista de
        // modelos se esgota e `gerarImagem` relança como `RuntimeException`,
        // mesmo padrão do `AnaliseAnuncioService::chamar()`. A classe
        // `FalhaDeGeracaoTrocavel` É um `RuntimeException`, então o contrato
        // público (quem não quer saber de troca de modelo) nunca quebra.
        Http::fake(['gemini.teste/*' => Http::response([
            'interaction' => ['outputText' => 'o modelo recusou o pedido'],
        ])]);

        $provider = app(GeminiImageProvider::class);

        try {
            $provider->gerarImagem(new CreativeGenerationRequest('prompt qualquer'));
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/sem imagem/i', $e->getMessage());
        }
    }

    public function test_payload_manda_auth_por_header_e_referencia_em_base64(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $provider = app(GeminiImageProvider::class);
        $provider->gerarImagem(CreativeGenerationRequest::comImagem('gere uma foto', 'bytes-da-foto', 'image/png'));

        Http::assertSent(function ($request) {
            $this->assertTrue($request->hasHeader('x-goog-api-key'));
            $this->assertSame('chave-de-teste', $request->header('x-goog-api-key')[0]);
            $this->assertFalse($request->hasHeader('Authorization'));

            $input = $request->data()['input'];
            $imagem = collect($input)->firstWhere('type', 'image');

            $this->assertNotNull($imagem, 'O pedido deveria conter um item type=image.');
            $this->assertSame('image/png', $imagem['mime_type']);
            $this->assertSame(base64_encode('bytes-da-foto'), $imagem['data']);

            return true;
        });
    }

    public function test_gera_texto_com_sucesso(): void
    {
        Http::fake(['gemini.teste/*' => Http::response([
            'interaction' => ['outputText' => 'OK'],
        ])]);

        $provider = app(GeminiImageProvider::class);
        $resposta = $provider->gerarTexto('Responda apenas com a palavra OK.');

        $this->assertSame('OK', $resposta);
    }
}
