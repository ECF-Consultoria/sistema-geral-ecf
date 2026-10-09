<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * O Modelo saiu da ficha do cliente (decisão do usuário, 09/10/2026): quem o preenche é a IA no
 * Publicador. Nem obrigatório na categoria ele aparece; vindo no PUT, é ignorado; o que o cliente
 * gravou antes FICA guardado (é dado dele — vira só fato para a IA). O sigilo continua.
 */
class ModeloForaDaFichaDoPortalTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Http::fake(fn (Request $r) => str_contains($r->url(), '/categories/MLB1/attributes')
            ? Http::response([
                ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
                // Obrigatório na categoria, como na cadeira real (MLB193945).
                ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => ['required' => true]],
            ], 200)
            : Http::response([], 404));
    }

    private function produto($empresa): EstruturaProduto
    {
        return EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-'.uniqid(), 'nome' => 'Puff',
            'categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Puffs']);
    }

    private function semOrigem(string $json): void
    {
        $json = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $json);
        foreach (['mercado', 'anúncio', 'anuncio', 'publicar', 'publicador', 'mlb'] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase($termo, $json, "“{$termo}” vazou");
        }
    }

    public function test_modelo_obrigatorio_nao_aparece_nos_campos_da_ficha(): void
    {
        $r = $this->withoutVite()->entrarNoPortal($this->empresaDoGabarito())
            ->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']))->assertOk();

        $ids = array_column(array_merge(...array_column($r->json('grupos'), 'campos')), 'id');
        $this->assertSame(['BRAND'], $ids);
        $this->semOrigem($r->getContent());
    }

    public function test_put_com_modelo_ignora_o_modelo_e_mantem_o_que_o_cliente_ja_tinha_gravado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        // Gravado ANTES de o Modelo sair da ficha.
        EstruturaProdutoAtributo::create(['company_id' => $empresa->id, 'produto_id' => $produto->id, 'atributo_id' => 'MODEL',
            'atributo_nome' => 'Modelo', 'valor' => 'Puff Redondo']);

        $r = $this->withoutVite()->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), ['atributos' => [
                ['id' => 'BRAND', 'valor' => 'Bela Casa'],
                ['id' => 'MODEL', 'valor' => 'Trocado pelo PUT'],
            ]])->assertOk();

        $this->semOrigem($r->getContent());
        $this->assertSame(['BRAND'], array_column($r->json('salvos'), 'id'), 'o Modelo guardado não volta para a tela do cliente');
        $linhas = EstruturaProdutoAtributo::where('produto_id', $produto->id)->pluck('valor', 'atributo_id')->all();
        $this->assertSame('Bela Casa', $linhas['BRAND']);
        $this->assertSame('Puff Redondo', $linhas['MODEL'], 'o PUT não troca o Modelo e o salvar não apaga o que o cliente tinha');
    }

    public function test_modelo_nao_e_mais_exigido_para_salvar(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), ['atributos' => [['id' => 'BRAND', 'valor' => 'Bela Casa']]])
            ->assertOk();

        $this->assertSame(['BRAND'], EstruturaProdutoAtributo::where('produto_id', $produto->id)->pluck('atributo_id')->all());
    }
}
