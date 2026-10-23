<?php

namespace Tests\Feature\Publicador;

use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Services\Publicador\PalavrasChaveService;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RegrasDoTitulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * O título da NOVA OFERTA da Jardinagem (23/10/2026): o mesmo prompt e as mesmas regras do preparo, com
 * os termos novos da semana como prioridade e TODO título já usado como "não repita" — e o servidor
 * garante a diferença quando a IA devolve o título antigo.
 *
 * O `AnaliseAnuncioService` é o verdadeiro (o prompt é conferido no pedido que sai); o provedor de IA é
 * um `Http::fake` e os termos mais buscados são um dublê. Nada real.
 */
class TituloDaJardinagemTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private string $respostaIa = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->repo->gravarAlvos($this->r, [new Alvo('gold_special', null), new Alvo('gold_pro', null)]);
        $this->produto->oferta->update(['nome' => 'Puff Redondo']);
        $this->r = $this->r->fresh();

        config(['services.llm' => ['base_url' => 'https://llm.teste/v1', 'key' => 'k', 'model' => 'modelo-teste', 'fallbacks' => '', 'timeout' => 30, 'max_tokens' => 1000]]);
        $this->mock(TermosMaisBuscadosService::class, fn ($m) => $m->shouldReceive('termos')->andReturn(['termos' => [
            ['termo' => 'puff sala', 'posicao' => 1, 'relacionado' => true],
            ['termo' => 'puff banqueta', 'posicao' => 2, 'relacionado' => true],
        ]]));
        Http::preventStrayRequests();
        Http::fake(['llm.teste/*' => fn () => Http::response(['model' => 'modelo-teste', 'choices' => [['message' => ['content' => $this->respostaIa]]]])]);
    }

    private function ultimoPrompt(): string
    {
        $pedidos = Http::recorded(fn (Request $req) => str_contains($req->url(), 'llm.teste'));

        return (string) ($pedidos->last()[0]->data()['messages'][0]['content'] ?? '');
    }

    public function test_termos_novos_vao_como_prioridade_e_os_titulos_antigos_como_nao_repita(): void
    {
        $this->respostaIa = '{"classico":"Puff Banqueta Redondo Sala","premium":"Puff Redondo Banqueta Sala Moderno"}';

        $titulos = app(PalavrasChaveService::class)->gerarTitulosDaJardinagem($this->r->fresh(), ['Puff Redondo Sala Moderno'], ['puff banqueta']);

        $this->assertSame(['gold_special' => 'Puff Banqueta Redondo Sala', 'gold_pro' => 'Puff Redondo Banqueta Sala Moderno'], $titulos);
        $prompt = $this->ultimoPrompt();
        $this->assertStringContainsString("A equipe marcou estes termos como os mais importantes — priorize-os:\n- puff banqueta", $prompt);
        $this->assertStringContainsString('NÃO REPITA este título, que já é de outro anúncio deste produto: "Puff Redondo Sala Moderno"', $prompt);
        $this->assertStringContainsString('Gere DOIS títulos', $prompt);
    }

    public function test_ia_que_devolve_o_titulo_antigo_nao_passa_igual(): void
    {
        $antigo = 'Puff Redondo Sala Banqueta';
        $this->respostaIa = '{"classico":"Puff Redondo Sala Banqueta","premium":"Puff Redondo Sala Banqueta"}';

        $titulos = app(PalavrasChaveService::class)->gerarTitulosDaJardinagem($this->r->fresh(), [$antigo], []);

        foreach ($titulos as $tipo => $titulo) {
            $this->assertFalse(RegrasDoTitulo::mesmo($titulo, $antigo), "o {$tipo} saiu igual ao título antigo");
        }
        $this->assertFalse($titulos['gold_special'] !== '' && $titulos['gold_pro'] !== '' && RegrasDoTitulo::mesmo($titulos['gold_special'], $titulos['gold_pro']));
    }
}
