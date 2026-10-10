<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarPalavrasChaveIaJob;
use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Services\Publicador\PalavrasChaveService;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RegrasDoTitulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Relato do usuário em produção (09/10/2026): o preparo pela IA gerou "Puff Sala Redondo Banqueta
 * Moderno ECF 130 kg" e pôs o MESMO título no Clássico e no Premium. O Mercado Livre barra dois
 * anúncios com o mesmo nome, e o título não deveria ter a marca nem o peso suportado.
 *
 * O `AnaliseAnuncioService` é o verdadeiro (o prompt é conferido no pedido que sai); o provedor de IA
 * é um único `Http::fake` que responde `$this->respostaIa` — nada real. Os termos mais buscados são
 * um dublê.
 */
class TituloDoisTiposSemMarcaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    /** O JSON que a "IA" devolve agora. */
    private string $respostaIa = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        // Clássico e Premium ativos; o Clássico começa vazio.
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

    private function servico(): PalavrasChaveService
    {
        return app(PalavrasChaveService::class);
    }

    /** O texto do último prompt que saiu para o provedor de IA. */
    private function ultimoPrompt(): string
    {
        $pedidos = Http::recorded(fn (Request $req) => str_contains($req->url(), 'llm.teste'));

        return (string) ($pedidos->last()[0]->data()['messages'][0]['content'] ?? '');
    }

    /** O "Sugerir com IA" de um tipo, rodado como o Job roda (o resultado vai para o pedido no cache). */
    private function sugerir(string $tipo, ?string $outroDaTela = null): array
    {
        try {
            $this->servico()->executar($this->r->fresh(), "titulo_{$tipo}", 'pedido-1', [], null, $outroDaTela);
        } catch (\RuntimeException) {
            // O erro fica no pedido (a tela o mostra); o Job só registra.
        }

        return $this->servico()->estado($this->r, "titulo_{$tipo}");
    }

    private function assertSemMarcaNemPeso(string $titulo): void
    {
        $this->assertDoesNotMatchRegularExpression('/\bECF\b/i', $titulo);
        $this->assertStringNotContainsString('130', $titulo);
        $this->assertDoesNotMatchRegularExpression('/\bkg\b/i', $titulo);
    }

    // ═══ O preparo: dois títulos numa chamada ════════════════════════════════

    public function test_caso_do_usuario_os_dois_titulos_saem_diferentes_sem_ecf_e_sem_130_kg(): void
    {
        $this->respostaIa = '{"classico":"Puff Sala Redondo Banqueta Moderno ECF 130 kg","premium":"Puff Sala Redondo Banqueta Moderno ECF 130 kg"}';

        $titulos = $this->servico()->gerarTitulos($this->r->fresh());

        $this->assertSame(['gold_special' => 'Puff Sala Redondo Banqueta Moderno', 'gold_pro' => 'Puff Sala Redondo Moderno Banqueta'], $titulos);
        $this->assertFalse(RegrasDoTitulo::mesmo($titulos['gold_special'], $titulos['gold_pro']));
        $this->assertSemMarcaNemPeso($titulos['gold_special']);
        $this->assertSemMarcaNemPeso($titulos['gold_pro']);

        $prompt = $this->ultimoPrompt();
        $this->assertStringContainsString('Gere DOIS títulos', $prompt);
        $this->assertStringContainsString('DOIS TÍTULOS DIFERENTES (o Mercado Livre barra dois anúncios com o mesmo nome)', $prompt);
        $this->assertStringContainsString('7. SEM MARCA: não use a marca do produto (a marca deste produto é "ECF" — ela NÃO entra)', $prompt);
        $this->assertStringContainsString('8. SEM NÚMEROS DE ESPECIFICAÇÃO', $prompt);
        $this->assertStringContainsString('{"classico":"...","premium":"..."}', $prompt);
        $this->assertStringNotContainsString('- Marca: ECF', $prompt, 'a marca não vai como fato do produto no título');
    }

    public function test_ia_devolve_dois_iguais_so_por_caixa_acento_e_plural_e_o_servidor_diferencia(): void
    {
        $this->respostaIa = '{"classico":"Puff Redondo Sala Banqueta","premium":"puff redondo sála banquetas"}';

        $titulos = $this->servico()->gerarTitulos($this->r->fresh());

        $this->assertSame('Puff Redondo Sala Banqueta', $titulos['gold_special']);
        $this->assertSame('puff redondo banquetas sála', $titulos['gold_pro'], 'o produto principal fica no começo; troca a ordem das duas últimas');
        $this->assertFalse(RegrasDoTitulo::mesmo($titulos['gold_special'], $titulos['gold_pro']));
    }

    public function test_ia_devolve_um_titulo_so_e_o_outro_tipo_nao_fica_igual(): void
    {
        $this->respostaIa = '{"classico":"Puff Redondo Sala Banqueta","premium":""}';

        $titulos = $this->servico()->gerarTitulos($this->r->fresh());

        $this->assertSame('Puff Redondo Sala Banqueta', $titulos['gold_special']);
        $this->assertSame('Puff Redondo Banqueta Sala', $titulos['gold_pro']);
    }

    public function test_marca_do_brand_diferente_de_ecf_tambem_sai(): void
    {
        $this->repo->mesclarAtributos($this->r->fresh(), ['BRAND' => ['value_name' => 'Lia Decor']]);
        $this->respostaIa = '{"classico":"Puff Redondo Lia Decor Sala Banqueta","premium":"Puff Redondo Sala Banqueta Lia Decor Moderno"}';

        $titulos = $this->servico()->gerarTitulos($this->r->fresh());

        $this->assertSame(['gold_special' => 'Puff Redondo Sala Banqueta', 'gold_pro' => 'Puff Redondo Sala Banqueta Moderno'], $titulos);
        $this->assertStringContainsString('(a marca deste produto é "Lia Decor" — ela NÃO entra)', $this->ultimoPrompt());
    }

    // ═══ O botão "Sugerir com IA" de um tipo ═════════════════════════════════

    public function test_botao_de_um_tipo_nao_repete_o_outro_ja_preenchido(): void
    {
        $this->repo->gravarTitulos($this->r, ['gold_special' => 'Puff Sala Redondo Banqueta Moderno']);
        $this->respostaIa = '{"titulo":"Puff Sala Redondo Banqueta Moderno ECF 130 kg"}';

        $estado = $this->sugerir('gold_pro');

        $this->assertSame('pronto', $estado['status']);
        $this->assertSame('Puff Sala Redondo Moderno Banqueta', $estado['valor']);
        $this->assertSemMarcaNemPeso($estado['valor']);
        $prompt = $this->ultimoPrompt();
        $this->assertStringContainsString('NÃO REPITA este título, que já é de outro anúncio deste produto: "Puff Sala Redondo Banqueta Moderno"', $prompt);
        $this->assertStringContainsString('{"titulo":"..."}', $prompt);
    }

    public function test_botao_usa_o_titulo_do_outro_tipo_que_esta_na_tela_ainda_nao_salvo(): void
    {
        $this->respostaIa = '{"titulo":"Puff Redondo Sala Banqueta"}';

        $estado = $this->sugerir('gold_special', 'puff redondo sala banqueta');

        $this->assertSame('Puff Redondo Banqueta Sala', $estado['valor']);
        $this->assertStringContainsString('"puff redondo sala banqueta"', $this->ultimoPrompt());
    }

    public function test_botao_sem_saida_para_diferenciar_da_erro_em_vez_de_repetir(): void
    {
        // Duas palavras (nada a trocar de ordem) e nenhum termo de busca novo que caiba: nunca entrega igual.
        $this->repo->gravarTitulos($this->r, ['gold_special' => 'Puff Redondo']);
        $this->mock(TermosMaisBuscadosService::class, fn ($m) => $m->shouldReceive('termos')->andReturn(['termos' => []]));
        $this->respostaIa = '{"titulo":"Puff Redondo"}';

        $estado = $this->sugerir('gold_pro');

        $this->assertSame('erro', $estado['status']);
        $this->assertSame('A IA sugeriu o mesmo título do outro tipo de anúncio. Tente de novo.', $estado['erro']);
    }

    public function test_pedido_do_titulo_leva_o_titulo_do_outro_tipo_ao_job(): void
    {
        Queue::fake();

        $this->servico()->pedir($this->r->fresh(), 'titulo_gold_pro', ['puff sala'], 'Puff Sala Redondo Banqueta');

        Queue::assertPushed(GerarPalavrasChaveIaJob::class, fn ($j) => $j->alvo === 'titulo_gold_pro' && $j->titulo === 'Puff Sala Redondo Banqueta');
    }
}
