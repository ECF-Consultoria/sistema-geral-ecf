<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoOrigemMl;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Produtos → "Importar do Mercado Livre" (28/10/2026), de ponta a ponta pela rota, como o cliente
 * do Portal: anúncio da conta conectada (ativo ou não) vira produto com variações, caixa, ficha, fotos do
 * anúncio original e o selo de origem; a IA completa o campo que faltava. Nada é escrito no ML.
 */
class TrazerDoMercadoLivreTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const VENDEDOR = '999000111';

    /** @var list<string> o que a IA recebeu como "campos que faltam" */
    private array $pedidoIa = [];

    /** Quantas vezes a ficha da categoria responde 500 antes de responder (queda passageira do catálogo). */
    private int $falhasDosAtributos = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Storage::fake('local');

        $this->mock(AnaliseAnuncioService::class, function ($m) {
            $m->shouldReceive('ficha')->andReturnUsing(function ($produto, $specs, $titulo, $caminho, $catalogo) {
                $this->pedidoIa = [$specs, $catalogo];

                return ['dados' => ['atributos' => ['MATERIAL' => 'Couro sintético', 'BRAND' => 'Inventada']], 'meta' => ['modelo' => 'teste']];
            });
        });
    }

    // ─── Montagem ───────────────────────────────────────────────────────────

    private function empresaConectada(): Company
    {
        $empresa = $this->empresaDoGabarito();
        MlToken::create(['company_id' => $empresa->id, 'ml_user_id' => self::VENDEDOR, 'access_token' => 'APP_USR-x',
            'refresh_token' => 'TG-x', 'expires_at' => now()->addHours(5), 'status' => 'active']);

        return $empresa->fresh();
    }

    private function noAcervo(Company $empresa, string $mlb, array $extra = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => "Anúncio {$mlb}", 'category_id' => 'MLB193945',
            'status' => 'active', 'listing_type_id' => 'gold_special', 'price' => 399.9, 'available_quantity' => 7,
            'sold_quantity' => 31, 'catalog_listing' => false, 'nota_ecf' => 40, 'coletado_em' => now(),
        ], $extra));
    }

    private function anuncio(string $mlb = 'MLB111', string $vendedor = self::VENDEDOR): array
    {
        return [
            'id' => $mlb, 'seller_id' => (int) $vendedor, 'title' => 'Cadeira Escritório Giratória', 'category_id' => 'MLB193945',
            'status' => 'active', 'listing_type_id' => 'gold_special', 'price' => 399.9, 'available_quantity' => 7, 'sold_quantity' => 31,
            'attributes' => [
                ['id' => 'BRAND', 'name' => 'Marca', 'value_name' => 'Ergo'],
                ['id' => 'MODEL', 'name' => 'Modelo', 'value_name' => 'ER-200'],
                ['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => 'CAD-ER200'],
                ['id' => 'SEAT_DEPTH', 'name' => 'Profundidade do assento', 'value_name' => '48 cm', 'value_struct' => ['number' => 48, 'unit' => 'cm']],
                ['id' => 'WITH_ARMRESTS', 'name' => 'Com braços', 'value_id' => '242085', 'value_name' => 'Sim'],
                ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_struct' => ['number' => 12, 'unit' => 'cm'], 'value_name' => '12 cm'],
                ['id' => 'SELLER_PACKAGE_WIDTH', 'value_struct' => ['number' => 45, 'unit' => 'cm'], 'value_name' => '45 cm'],
                ['id' => 'SELLER_PACKAGE_LENGTH', 'value_struct' => ['number' => 60, 'unit' => 'cm'], 'value_name' => '60 cm'],
                ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_struct' => ['number' => 4500, 'unit' => 'g'], 'value_name' => '4500 g'],
            ],
            'pictures' => [
                ['id' => 'P1', 'secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg'],
                ['id' => 'P2', 'secure_url' => 'https://http2.mlstatic.com/D_2-O.jpg'],
                ['id' => 'P3', 'secure_url' => 'https://http2.mlstatic.com/D_3-O.jpg'],
            ],
            'variations' => [
                ['id' => 1, 'available_quantity' => 4, 'picture_ids' => ['P1', 'P2'],
                    'attribute_combinations' => [['id' => 'COLOR', 'name' => 'Cor', 'value_name' => 'Preto']],
                    'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'CAD-ER200-PT']]],
                ['id' => 2, 'available_quantity' => 3, 'picture_ids' => ['P1', 'P3'],
                    'attribute_combinations' => [['id' => 'COLOR', 'name' => 'Cor', 'value_name' => 'Cinza']],
                    'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'CAD-ER200-CZ']]],
            ],
            'shipping' => ['mode' => 'me2', 'logistic_type' => 'xd_drop_off', 'free_shipping' => true],
            'sale_terms' => [],
        ];
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    /** A API do ML e o CDN das fotos, só leitura. */
    private function fakeMl(array $itens): void
    {
        $jpeg = $this->jpeg();
        Http::fake(function (Request $r) use ($itens, $jpeg) {
            $url = $r->url();
            if (str_contains($url, 'mlstatic.com')) {
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_contains($url, '/categories/MLB193945/attributes')) {
                if ($this->falhasDosAtributos-- > 0) {
                    return Http::response(['message' => 'erro'], 500);
                }

                return Http::response([
                    ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
                    ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => ['required' => true]],
                    ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                        'values' => [['id' => '10', 'name' => 'Couro sintético'], ['id' => '11', 'name' => 'Tecido']]],
                    ['id' => 'SEAT_DEPTH', 'name' => 'Profundidade do assento', 'value_type' => 'number_unit',
                        'allowed_units' => [['id' => 'cm', 'name' => 'cm']], 'default_unit' => 'cm', 'tags' => []],
                    ['id' => 'WITH_ARMRESTS', 'name' => 'Com braços', 'value_type' => 'boolean', 'tags' => [],
                        'values' => [['id' => '242085', 'name' => 'Sim'], ['id' => '242084', 'name' => 'Não']]],
                ], 200);
            }
            if (preg_match('#/categories/MLB193945$#', parse_url($url, PHP_URL_PATH) ?? '')) {
                return Http::response(['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório', 'children_categories' => [],
                    'path_from_root' => [['id' => 'MLB1574', 'name' => 'Casa'], ['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório']],
                    'settings' => ['max_title_length' => 60]], 200);
            }
            if (preg_match('#/items/(MLB\d+)/description#', $url, $m)) {
                return Http::response(['plain_text' => 'Cadeira giratória com encosto em tela.'], 200);
            }
            if (preg_match('#/items/(MLB\d+)/visits/time_window#', $url, $m)) {
                return Http::response(['results' => [['date' => now()->subDay()->toDateString().'T00:00:00Z', 'total' => 40]]], 200);
            }
            if (preg_match('#/items/(MLB\d+)(\?|$)#', $url, $m) && isset($itens[$m[1]])) {
                return Http::response($itens[$m[1]], 200);
            }
            if (str_contains(parse_url($url, PHP_URL_PATH) ?? '', '/items') && str_contains($url, 'ids=')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $ids = explode(',', (string) ($q['ids'] ?? ''));

                return Http::response(array_map(fn ($id) => isset($itens[$id]) ? ['code' => 200, 'body' => $itens[$id]] : ['code' => 404, 'body' => []], $ids), 200);
            }

            return Http::response([], 404);
        });
    }

    // ─── Testes ─────────────────────────────────────────────────────────────

    public function test_anuncio_vira_produto_com_variacoes_caixa_ficha_fotos_selo_e_a_ia_completa_o_que_faltava(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111');
        $this->fakeMl(['MLB111' => $this->anuncio()]);

        $r = $this->withoutVite()->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.produtos.ml.importar'), ['mlbs' => ['MLB111']])->assertOk();

        $item = $r->json('itens.0');
        $this->assertSame('pronto', $item['situacao'], json_encode($item));
        $this->assertSame(1, $item['campos_ia'], 'a IA completou o Material, que o anúncio não tinha');

        $produto = EstruturaProduto::where('company_id', $empresa->id)->sole();
        $this->assertSame($item['produto_id'], $produto->id);
        $this->assertSame('Cadeira Escritório Giratória', $produto->nome);
        $this->assertNull($produto->titulo, 'o título de venda fica para a IA reescrever');
        $this->assertSame('MLB193945', $produto->categoria_ml_id);
        $this->assertSame('Cadeiras de Escritório', $produto->categoria_ml_nome);
        $this->assertSame('Cadeira giratória com encosto em tela.', $produto->descricao);
        $this->assertSame('CAD-ER200', $produto->codigo);

        $variacoes = EstruturaProdutoVariacao::where('produto_id', $produto->id)->with('volumes')->orderBy('ordem')->get();
        $this->assertSame(['CAD-ER200-PT', 'CAD-ER200-CZ'], $variacoes->pluck('codigo')->all());
        $this->assertSame(['cor', 'cor'], $variacoes->pluck('eixo')->all());
        $this->assertSame(['Preto', 'Cinza'], $variacoes->pluck('valor')->all());
        $this->assertSame([4, 3], $variacoes->pluck('estoque')->all());
        foreach ($variacoes as $v) {
            $vol = $v->volumes->sole();
            $this->assertSame([60.0, 45.0, 12.0, 4.5], [(float) $vol->comprimento, (float) $vol->largura, (float) $vol->altura, (float) $vol->peso]);
        }

        $ficha = EstruturaProdutoAtributo::where('produto_id', $produto->id)->get()->keyBy('atributo_id');
        $this->assertSame('Ergo', $ficha['BRAND']->valor, 'o que veio do anúncio fica — a IA só preenche campo vazio');
        $this->assertSame(['48', 'cm'], [$ficha['SEAT_DEPTH']->valor, $ficha['SEAT_DEPTH']->unidade]);
        $this->assertSame('Sim', $ficha['WITH_ARMRESTS']->valor);
        $this->assertSame(['Couro sintético', '10'], [$ficha['MATERIAL']->valor, $ficha['MATERIAL']->valor_id]);
        $this->assertFalse($ficha->has('MODEL'), 'o Modelo não volta para a ficha: fica no retrato, como fato');

        $this->assertStringContainsString('MATERIAL', $this->pedidoIa[1], 'a IA recebe só o que falta');
        $this->assertStringNotContainsString('BRAND', $this->pedidoIa[1]);
        $this->assertStringContainsString('Modelo informado pelo vendedor: ER-200', $this->pedidoIa[0]);

        $fotos = EstruturaProdutoVariacaoImagem::whereIn('variacao_id', $variacoes->pluck('id'))->get()->groupBy('variacao_id');
        $this->assertCount(2, $fotos[$variacoes[0]->id]);
        $this->assertCount(2, $fotos[$variacoes[1]->id]);
        foreach ($fotos->flatten() as $f) {
            Storage::disk('local')->assertExists($f->caminho);
        }
        $this->assertCount(3, Http::recorded(fn (Request $q) => str_contains($q->url(), 'mlstatic.com')), 'a foto repetida entre variações é baixada uma vez');

        $origem = EstruturaProdutoOrigemMl::where('produto_id', $produto->id)->sole();
        $this->assertSame('MLB111', $origem->ml_item_id);
        $this->assertSame('ER-200', $origem->retrato['modelo']);
        $this->assertSame(['MATERIAL'], $origem->jardinagem['campos_ia']);

        // Nada foi escrito no ML.
        $this->assertCount(0, Http::recorded(fn (Request $q) => $q->method() !== 'GET'));

        // O selo: a lista de Produtos diz de onde veio.
        $lista = $this->withoutVite()->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos'))->assertOk();
        $linhas = $lista->viewData('page')['props']['produtos']['linhas'];
        $this->assertSame('MLB111', $linhas[0]['origem_ml']['mlb']);
    }

    public function test_ficha_que_nao_carregou_na_importacao_recebe_os_campos_do_anuncio_na_jardinagem(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111');
        $this->fakeMl(['MLB111' => $this->anuncio()]);
        $this->falhasDosAtributos = 1;

        $r = $this->withoutVite()->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.produtos.ml.importar'), ['mlbs' => ['MLB111']])->assertOk();

        $this->assertSame('pronto', $r->json('itens.0.situacao'));
        $this->assertContains('A ficha técnica da categoria não carregou agora: os campos do anúncio entram em seguida.', $r->json('itens.0.avisos'));
        $ficha = EstruturaProdutoAtributo::where('produto_id', $r->json('itens.0.produto_id'))->pluck('valor', 'atributo_id')->all();
        $this->assertSame('Ergo', $ficha['BRAND'] ?? null, 'o campo do anúncio entrou na jardinagem');
        $this->assertSame('48', $ficha['SEAT_DEPTH'] ?? null);
        $this->assertSame('Couro sintético', $ficha['MATERIAL'] ?? null, 'e a IA completou só o que o anúncio não tinha');
        $this->assertSame(1, $r->json('itens.0.campos_ia'));
    }

    public function test_anuncio_de_outra_conta_nao_entra(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111');
        $this->fakeMl(['MLB111' => $this->anuncio('MLB111', '123')]);

        $r = $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.ml.importar'), ['mlbs' => ['MLB111']])->assertOk();

        $this->assertSame('erro', $r->json('itens.0.situacao'));
        $this->assertSame('Este anúncio não é da conta conectada.', $r->json('itens.0.mensagem'));
        $this->assertSame(0, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    public function test_anuncio_pausado_ou_encerrado_tambem_e_importado(): void
    {
        // 28/10/2026, o usuário: a lista traz TODOS os anúncios, e qualquer um pode ser importado (é um clone).
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111', ['status' => 'closed']);
        $encerrado = $this->anuncio();
        $encerrado['status'] = 'closed';
        $this->fakeMl(['MLB111' => $encerrado]);

        $r = $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.ml.importar'), ['mlbs' => ['MLB111']])->assertOk();

        $this->assertSame('pronto', $r->json('itens.0.situacao'));
        $this->assertSame(1, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    public function test_mlb_fora_do_acervo_da_empresa_e_recusado(): void
    {
        $empresa = $this->empresaConectada();
        $outra = $this->empresaConectada();
        $this->noAcervo($outra, 'MLB111');
        $this->fakeMl(['MLB111' => $this->anuncio()]);

        $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.ml.importar'), ['mlbs' => ['MLB111']])
            ->assertStatus(422)->assertJsonValidationErrors('mlbs');
        $this->assertSame(0, EstruturaProduto::count());
    }

    public function test_ref_que_ja_existe_nao_e_sobrescrita(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111');
        $this->fakeMl(['MLB111' => $this->anuncio()]);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [['codigo' => 'cad-er200-pt', 'grupo' => 'MEU', 'nome' => 'Minha cadeira', 'estoque' => 99]],
            $this->atorCliente($empresa), agendarPreparo: false);

        $r = $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.ml.importar'), ['mlbs' => ['MLB111']])->assertOk();

        $this->assertSame('ja_existe', $r->json('itens.0.situacao'));
        $this->assertStringContainsString('Minha cadeira', $r->json('itens.0.mensagem'));
        $this->assertSame(1, EstruturaProduto::where('company_id', $empresa->id)->count());
        $this->assertSame(99, EstruturaProdutoVariacao::where('company_id', $empresa->id)->sole()->estoque, 'o cadastro do cliente fica como estava');
        $this->assertSame(0, EstruturaProdutoOrigemMl::count());
    }

    public function test_lista_traz_todos_da_maior_oportunidade_para_a_menor_com_filtro_de_situacao(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB1', ['nota_ecf' => 70, 'sold_quantity' => 5]);
        $this->noAcervo($empresa, 'MLB2', ['nota_ecf' => 20, 'sold_quantity' => 40]);
        $this->noAcervo($empresa, 'MLB3', ['nota_ecf' => 10, 'catalog_listing' => true, 'sold_quantity' => 10]);
        $this->noAcervo($empresa, 'MLB4', ['nota_ecf' => 5, 'status' => 'paused', 'sold_quantity' => 20]);
        $this->noAcervo($empresa, 'MLB5', ['nota_ecf' => null, 'sold_quantity' => 50]);
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'X', 'nome' => 'Já trazido']);
        EstruturaProdutoOrigemMl::create(['company_id' => $empresa->id, 'produto_id' => $produto->id, 'ml_item_id' => 'MLB1', 'importado_em' => now()]);

        // Padrão: TODOS (ativo, pausado, catálogo), da nota mais baixa para a mais alta; sem nota por último.
        $r = $this->entrarNoPortal($empresa)->getJson(route('portal.auth.estrutura.produtos.ml.anuncios'))->assertOk();
        $this->assertSame(['MLB4', 'MLB3', 'MLB2', 'MLB1', 'MLB5'], array_column($r->json('itens'), 'mlb'));
        $this->assertSame(['produto_id' => $produto->id, 'nome' => 'Já trazido'], $r->json('itens.3.importado'));
        $this->assertSame([false, 'Pausado'], [$r->json('itens.0.ativo'), $r->json('itens.0.status')]);
        $this->assertTrue($r->json('itens.1.catalogo'));

        $ver = fn (array $q) => array_column($this->entrarNoPortal($empresa)->getJson(route('portal.auth.estrutura.produtos.ml.anuncios', $q))->assertOk()->json('itens'), 'mlb');
        $this->assertSame(['MLB3', 'MLB2', 'MLB1', 'MLB5'], $ver(['situacao' => 'ativos']));
        $this->assertSame(['MLB4'], $ver(['situacao' => 'inativos']));
        $this->assertSame(['MLB4', 'MLB3', 'MLB2', 'MLB5'], $ver(['so_novos' => 1]));
        $this->assertSame(['MLB5', 'MLB2', 'MLB4', 'MLB3', 'MLB1'], $ver(['ordem' => 'vendidos']), 'mais vendidos primeiro');
        $this->entrarNoPortal($empresa)->getJson(route('portal.auth.estrutura.produtos.ml.anuncios', ['ordem' => 'piores']))->assertStatus(422);
    }

    public function test_diagnostico_da_pagina_aponta_o_que_falta_e_os_numeros(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111');
        $semModelo = $this->anuncio();
        $semModelo['attributes'] = array_values(array_filter($semModelo['attributes'], fn ($a) => $a['id'] !== 'MODEL'));
        $semModelo['title'] = 'Cadeira Giratória';
        $this->fakeMl(['MLB111' => $semModelo]);
        Cache::put('estrutura:vendas30d:'.$empresa->id, ['MLB111' => [now()->toDateString() => 0]], 600);

        $r = $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.ml.diagnostico'), ['mlbs' => ['MLB111', 'MLB999']])->assertOk();

        $this->assertTrue($r->json('vendas_prontas'));
        $this->assertSame(['MLB111'], array_keys($r->json('diagnosticos')), 'MLB fora do acervo não é lido');
        $d = $r->json('diagnosticos.MLB111');
        $this->assertSame(['vendas_30d' => 0, 'visitas_30d' => 40, 'conversao' => 0], $d['metricas']);
        $this->assertSame(['titulo_curto', 'sem_modelo', 'obrigatorio_faltando', 'pouca_visita', 'sem_venda'], array_column($d['problemas'], 'chave'));
        $this->assertSame('alta', $d['prioridade']);
        $this->assertSame(['Material'], $d['campos']['obrigatorios_faltando']);
    }

    public function test_pagina_abre_para_o_cliente(): void
    {
        $empresa = $this->empresaConectada();
        $this->noAcervo($empresa, 'MLB111');

        $r = $this->withoutVite()->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos.ml'))->assertOk();
        $props = $r->viewData('page')['props'];
        $this->assertSame('Portal/EstruturaProdutosDoMl', $r->viewData('page')['component']);
        $this->assertTrue($props['conectado']);
        $this->assertSame('MLB111', $props['anuncios']['itens'][0]['mlb']);
    }
}
