<?php

namespace Tests\Feature\Publicador;

use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Services\Publicador\PalavrasChaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * O prompt do TÍTULO leva o bloco "FATOS DO PRODUTO" (09/10/2026), o mesmo do Modelo: o ML diz o que é
 * buscado, os fatos dizem o que o produto é. Prova pelo pedido que sai para o provedor de IA (um único
 * `Http::fake`, nada real), com o `AnaliseAnuncioService` verdadeiro montando o prompt.
 */
class TituloComFatosDoProdutoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    public function test_o_prompt_do_titulo_contem_os_fatos_do_produto(): void
    {
        $this->montarCenario();
        config(['services.llm' => ['base_url' => 'https://llm.teste/v1', 'key' => 'k', 'model' => 'modelo-teste', 'fallbacks' => '', 'timeout' => 30, 'max_tokens' => 1000]]);
        $this->mock(TermosMaisBuscadosService::class, fn ($m) => $m->shouldReceive('termos')->andReturn(['termos' => [
            ['termo' => 'cadeira escritorio', 'posicao' => 1, 'relacionado' => true],
        ]]));
        Http::preventStrayRequests();
        Http::fake(['llm.teste/*' => Http::response(['model' => 'modelo-teste', 'choices' => [['message' => ['content' => '{"titulo":"Cadeira Escritório Executiva ECF Giratória"}']]]])]);

        $titulo = app(PalavrasChaveService::class)->gerarTitulo($this->r->fresh());

        // A marca (BRAND = ECF) sai do título no servidor, mesmo que a IA a escreva (09/10/2026).
        $this->assertSame('Cadeira Escritório Executiva Giratória', $titulo);
        Http::assertSent(function (Request $req) {
            $prompt = (string) ($req->data()['messages'][0]['content'] ?? '');

            return str_contains($prompt, 'FATOS DO PRODUTO (use só o que é verdade segundo estes fatos):')
                && str_contains($prompt, 'ECF')
                && str_contains($prompt, 'Nunca cite material, tamanho, público, formato ou característica que os fatos não confirmem.')
                && str_contains($prompt, 'REGRAS DOS TÍTULOS (ruleset ECF)');
        });
    }
}
