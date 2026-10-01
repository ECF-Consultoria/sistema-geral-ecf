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
            'services.creative.gemini.text_fallbacks'    => '',
            'services.creative.gemini.aspect_ratio'      => '1:1',
            'services.creative.gemini.image_size'        => '2K',
            'services.creative.gemini.mime'              => 'image/jpeg',
            'services.creative.gemini.timeout'           => 30,
            'services.creative.gemini.connect_timeout'   => 10,
        ]);
    }

    /**
     * Corpo de sucesso de imagem no formato MEDIDO da Interactions API.
     *
     * A primeira versão deste arquivo usava `interaction.output_image.data`,
     * que é como a PÁGINA DE DOCS resume a resposta — e não é o que a API
     * devolve. Chamada real em 2026-10-01 mostrou objeto plano com `steps[]`,
     * e o passo `thought` vindo ANTES do `model_output`; ele entra no fake de
     * propósito, porque foi justamente ele que expôs o bug de ler o [0].
     */
    private function respostaImagemOk(string $modelo = 'modelo-imagem-teste'): array
    {
        return [
            'status' => 'completed',
            'model'  => $modelo,
            'steps'  => [
                ['type' => 'thought', 'signature' => 'abc123'],
                ['type' => 'model_output', 'content' => [[
                    'type'      => 'image',
                    'mime_type' => 'image/jpeg',
                    'data'      => base64_encode('bytes-falsos-jpeg'),
                ]]],
            ],
        ];
    }

    /** O mesmo sucesso, mas na grafia da documentação (caminho de último recurso). */
    private function respostaImagemFormatoDoc(string $modelo = 'modelo-imagem-teste'): array
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
        // 200 com saída de TEXTO onde se esperava imagem: é o que a Gemini faz
        // quando recusa o pedido por política de conteúdo.
        Http::fake(['gemini.teste/*' => Http::response([
            'status' => 'completed',
            'steps'  => [
                ['type' => 'model_output', 'content' => [[
                    'type' => 'text',
                    'text' => 'o modelo recusou o pedido',
                ]]],
            ],
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

    /**
     * Forma MEDIDA contra a API em 2026-10-01: o `thought` vem primeiro e não
     * tem conteúdo. Quem lesse `steps[0]` leria o passo errado.
     */
    public function test_gera_texto_com_sucesso(): void
    {
        Http::fake(['gemini.teste/*' => Http::response([
            'status' => 'completed',
            'steps'  => [
                ['type' => 'thought', 'signature' => 'abc123'],
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'OK']]],
            ],
        ])]);

        $provider = app(GeminiImageProvider::class);
        $resposta = $provider->gerarTexto('Responda apenas com a palavra OK.');

        $this->assertSame('OK', $resposta);
    }

    /**
     * Texto também troca de modelo em falha trocável. Regressão do caso real:
     * o modelo principal 503 fazia o comando acusar a chave, que estava boa.
     */
    public function test_texto_troca_de_modelo_quando_o_principal_esta_sobrecarregado(): void
    {
        config(['services.creative.gemini.text_fallbacks' => 'modelo-texto-reserva']);

        Http::fake(fn ($req) => $req['model'] === 'modelo-texto-teste'
            ? Http::response(['error' => ['message' => 'high demand']], 503)
            : Http::response([
                'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'veio da reserva']]]],
            ]));

        $provider = app(GeminiImageProvider::class);

        $this->assertSame('veio da reserva', $provider->gerarTexto('qualquer'));
        Http::assertSentCount(2);
    }

    /** Saída quebrada em vários blocos: concatena, não trunca no primeiro. */
    public function test_texto_em_varios_blocos_e_concatenado(): void
    {
        Http::fake(['gemini.teste/*' => Http::response([
            'steps' => [
                ['type' => 'model_output', 'content' => [
                    ['type' => 'text', 'text' => 'parte um '],
                    ['type' => 'text', 'text' => 'parte dois'],
                ]],
            ],
        ])]);

        $provider = app(GeminiImageProvider::class);

        $this->assertSame('parte um parte dois', $provider->gerarTexto('qualquer'));
    }

    /**
     * Rede de segurança: se a API algum dia devolver a grafia que a
     * documentação descreve, o provider ainda entende.
     */
    public function test_formato_da_documentacao_ainda_e_entendido(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemFormatoDoc())]);

        $provider = app(GeminiImageProvider::class);
        $resultado = $provider->gerarImagem(new CreativeGenerationRequest('prompt qualquer'));

        $this->assertSame('bytes-falsos-jpeg', $resultado->bytes);
        $this->assertSame('image/jpeg', $resultado->mime);
    }

    /**
     * O 429 de tier não pode sair como "tente em alguns minutos": nos modelos
     * de imagem no tier grátis o limite é 0/dia e esperar nunca resolve.
     */
    public function test_mensagem_de_429_fala_de_tier_nao_so_de_esperar(): void
    {
        Http::fake(['gemini.teste/*' => Http::response([
            'error' => ['message' => 'Rate limit exceeded (limit: 0 requests per day on Free Tier)'],
        ], 429)]);

        $provider = app(GeminiImageProvider::class);

        try {
            $provider->gerarImagem(new CreativeGenerationRequest('prompt qualquer'));
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/tier/i', $e->getMessage());
        }
    }
}
