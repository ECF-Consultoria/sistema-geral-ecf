<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Ficha técnica dinâmica do produto: os campos da categoria (lidos do catálogo com o app
 * token), o que o cliente preenche, a validação no servidor, o escopo por empresa e o
 * SIGILO — nada que revele de onde vêm os campos pode chegar ao cliente.
 *
 * Nenhuma chamada real: Http::fake com closure por URL.
 */
class FichaTecnicaDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** Quantas vezes a API de atributos foi chamada (para provar cache e o "vazio não gruda"). */
    private int $chamadas = 0;

    private bool $catalogoFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            if (preg_match('#/categories/(MLB\d+)/attributes$#', $r->url(), $m)) {
                $this->chamadas++;

                if ($this->catalogoFora) {
                    return Http::response(['message' => 'erro'], 500);
                }

                return Http::response($m[1] === 'MLB1' ? $this->atributosDaCategoria() : [], 200);
            }

            return Http::response([], 404);
        });
    }

    /** A resposta do catálogo: um atributo de cada tipo + os que precisam sumir + textos que citam a plataforma. */
    private function atributosDaCategoria(): array
    {
        return [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true],
                'attribute_group_id' => 'MAIN', 'attribute_group_name' => 'Principais', 'value_max_length' => 20,
                'hint' => 'Como aparece no Mercado Livre', 'tooltip' => 'Será usada no anúncio'],
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                'attribute_group_id' => 'MAIN', 'attribute_group_name' => 'Principais',
                'values' => [['id' => '101', 'name' => 'Madeira'], ['id' => '102', 'name' => 'Metal']]],
            ['id' => 'SEAT_HEIGHT', 'name' => 'Altura do assento', 'value_type' => 'number_unit', 'tags' => [],
                'attribute_group_id' => 'DIM', 'attribute_group_name' => 'Dimensões',
                'allowed_units' => [['id' => 'cm', 'name' => 'cm'], ['id' => 'mm', 'name' => 'mm']], 'default_unit' => 'cm'],
            ['id' => 'CAPACITY', 'name' => 'Capacidade', 'value_type' => 'number', 'tags' => [],
                'attribute_group_id' => 'DIM', 'attribute_group_name' => 'Dimensões'],
            ['id' => 'WITH_DRAWER', 'name' => 'Com gaveta', 'value_type' => 'boolean', 'tags' => []],
            // Lista que aceita mais de uma opção (os chips). Vem como `string` com `values`,
            // que é como o catálogo entrega a maior parte das opções.
            ['id' => 'MATERIALS', 'name' => 'Materiais', 'value_type' => 'string', 'tags' => ['multivalued' => true],
                'values' => [['id' => '1', 'name' => 'Algodão'], ['id' => '2', 'name' => 'Couro'], ['id' => '3', 'name' => 'Microfibra']]],
            // Descartados:
            ['id' => 'ITEM_CONDITION', 'name' => 'Condição', 'value_type' => 'list', 'tags' => ['hidden' => true]],
            ['id' => 'INTERNO', 'name' => 'Interno', 'value_type' => 'string', 'tags' => ['read_only' => true]],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            ['id' => 'VOLTAGE', 'name' => 'Voltagem', 'value_type' => 'list', 'tags' => ['variation_attribute' => true]],
            ['id' => 'APARECE', 'name' => 'Aparece no Mercado Livre', 'value_type' => 'string', 'tags' => []],
        ];
    }

    private function produto($empresa, array $extra = []): EstruturaProduto
    {
        return EstruturaProduto::create(array_merge([
            'company_id' => $empresa->id, 'codigo' => 'P-'.uniqid(), 'nome' => 'Cadeira Teste',
            'categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Cadeiras',
        ], $extra));
    }

    private function ficha(array $mais = []): array
    {
        return ['atributos' => array_merge([
            ['id' => 'BRAND', 'valor' => 'Bela Casa'],
            ['id' => 'MATERIAL', 'valor' => '101'],
        ], $mais)];
    }

    // ─── Endpoint de leitura dos campos ─────────────────────────────────────

    public function test_sem_sessao_os_dois_endpoints_mandam_para_a_entrada_do_portal(): void
    {
        $this->get('/portal/estrutura/produtos/campos-categoria?categoria=MLB1')->assertRedirect();
        $this->put('/portal/estrutura/produtos/12/ficha-tecnica', [])->assertRedirect();
    }

    public function test_o_endpoint_devolve_os_grupos_com_obrigatorios_em_destaque(): void
    {
        $r = $this->entrarNoPortal($this->empresaDoGabarito())
            ->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']))
            ->assertOk()
            ->assertJsonPath('indisponivel', false);

        $grupos = $r->json('grupos');
        $this->assertSame(['Principais', 'Dimensões', 'Outras características'], array_column($grupos, 'grupo'));
        $this->assertSame(['BRAND', 'MATERIAL'], array_column($grupos[0]['campos'], 'id'));
        $this->assertTrue($grupos[0]['campos'][0]['obrigatorio']);
        $this->assertSame('texto', $grupos[0]['campos'][0]['tipo']);
        $this->assertSame(20, $grupos[0]['campos'][0]['max']);
        $this->assertSame('lista', $grupos[0]['campos'][1]['tipo']);
        $this->assertSame([['id' => '101', 'nome' => 'Madeira'], ['id' => '102', 'nome' => 'Metal']], $grupos[0]['campos'][1]['valores']);
        $this->assertSame('numero_unidade', $grupos[1]['campos'][0]['tipo']);
        $this->assertSame('cm', $grupos[1]['campos'][0]['unidade_padrao']);
        $this->assertSame('numero', $grupos[1]['campos'][1]['tipo']);
        $this->assertSame('sim_nao', $grupos[2]['campos'][0]['tipo']);

        $ids = array_column(array_merge(...array_column($grupos, 'campos')), 'id');
        foreach (['ITEM_CONDITION', 'INTERNO', 'COLOR', 'VOLTAGE', 'APARECE'] as $fora) {
            $this->assertNotContains($fora, $ids);
        }
    }

    public function test_categoria_precisa_ser_um_id_valido(): void
    {
        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());
        $url = route('portal.auth.estrutura.produtos.campos_categoria');

        $sessao->getJson($url)->assertStatus(422)->assertJsonValidationErrors('categoria');
        $sessao->getJson($url.'?categoria=abc')->assertStatus(422)->assertJsonValidationErrors('categoria');
        $sessao->getJson($url.'?categoria=MLB12/attributes')->assertStatus(422);
        $sessao->getJson($url.'?categoria=MLB')->assertStatus(422);
        $this->assertSame(0, $this->chamadas, 'categoria inválida não pode virar chamada ao catálogo');
    }

    public function test_catalogo_fora_devolve_indisponivel_e_a_proxima_tentativa_nao_fica_presa_no_vazio(): void
    {
        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());
        $url = route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']);

        $this->catalogoFora = true;
        $sessao->getJson($url)->assertOk()->assertJsonPath('grupos', [])->assertJsonPath('indisponivel', true);

        $this->catalogoFora = false;
        $sessao->getJson($url)->assertOk()->assertJsonPath('indisponivel', false);
        $this->assertNotEmpty($sessao->getJson($url)->json('grupos'));
    }

    // ─── Gravar ─────────────────────────────────────────────────────────────

    public function test_salvar_valida_e_persiste_escopado_pela_empresa_da_sessao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), array_merge($this->ficha([
                ['id' => 'SEAT_HEIGHT', 'valor' => '12,5', 'unidade' => 'mm'],
                ['id' => 'CAPACITY', 'valor' => 30],
                ['id' => 'WITH_DRAWER', 'valor' => true],
            ]), ['company_id' => $outra->id]))
            ->assertOk()
            ->assertJsonPath('mensagem', 'Ficha técnica salva.');

        $linhas = EstruturaProdutoAtributo::where('produto_id', $produto->id)->get()->keyBy('atributo_id');
        $this->assertCount(5, $linhas);
        $this->assertSame($empresa->id, (int) $linhas['BRAND']->company_id);
        $this->assertSame(0, EstruturaProdutoAtributo::where('company_id', $outra->id)->count());

        $this->assertSame('Bela Casa', $linhas['BRAND']->valor);
        $this->assertSame('Marca', $linhas['BRAND']->atributo_nome);
        $this->assertSame('Madeira', $linhas['MATERIAL']->valor);
        $this->assertSame('101', $linhas['MATERIAL']->valor_id);
        $this->assertSame('12.5', $linhas['SEAT_HEIGHT']->valor);
        $this->assertSame('mm', $linhas['SEAT_HEIGHT']->unidade);
        $this->assertSame('30', $linhas['CAPACITY']->valor);
        $this->assertSame('Sim', $linhas['WITH_DRAWER']->valor);
    }

    public function test_salvar_de_novo_atualiza_e_remove_o_que_ficou_vazio_ou_ausente(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);

        $sessao->putJson($url, $this->ficha([['id' => 'SEAT_HEIGHT', 'valor' => '10'], ['id' => 'CAPACITY', 'valor' => '5']]))->assertOk();
        $this->assertSame('cm', EstruturaProdutoAtributo::where('atributo_id', 'SEAT_HEIGHT')->value('unidade'), 'sem unidade: vale a padrão');

        // Altura do assento com valor null (esvaziada) não grava; e a capacidade, ausente, sai.
        $sessao->putJson($url, $this->ficha([['id' => 'SEAT_HEIGHT', 'valor' => null]]))->assertOk()->assertJsonCount(2, 'salvos');
        // Troca a marca e a opção do material.
        $sessao->putJson($url, ['atributos' => [['id' => 'BRAND', 'valor' => 'Outra Marca'], ['id' => 'MATERIAL', 'valor' => '102']]])
            ->assertOk()
            ->assertJsonCount(2, 'salvos');

        $linhas = EstruturaProdutoAtributo::where('produto_id', $produto->id)->get()->keyBy('atributo_id');
        $this->assertSame(['BRAND', 'MATERIAL'], $linhas->keys()->sort()->values()->all());
        $this->assertSame('Outra Marca', $linhas['BRAND']->valor);
        $this->assertSame('Metal', $linhas['MATERIAL']->valor);
    }

    public function test_id_que_a_categoria_nao_conhece_e_ignorado_e_nunca_gravado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha([
                ['id' => 'COLOR', 'valor' => 'Azul'],          // variação: fora da ficha
                ['id' => 'ITEM_CONDITION', 'valor' => '2230284'], // sistema
                ['id' => 'NAO_EXISTE', 'valor' => 'x'],
            ]))
            ->assertOk();

        $this->assertSame(['BRAND', 'MATERIAL'], EstruturaProdutoAtributo::where('produto_id', $produto->id)->orderBy('atributo_id')->pluck('atributo_id')->all());
    }

    public function test_obrigatorio_faltando_responde_422_e_nao_grava_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), ['atributos' => [['id' => 'MATERIAL', 'valor' => '101'], ['id' => 'BRAND', 'valor' => '   ']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['atributos.BRAND'])
            ->assertJsonMissingValidationErrors(['atributos.MATERIAL']);

        $this->assertSame(0, EstruturaProdutoAtributo::where('produto_id', $produto->id)->count());
    }

    public function test_valor_de_lista_invalido_responde_422(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), [
                'atributos' => [['id' => 'BRAND', 'valor' => 'Bela Casa'], ['id' => 'MATERIAL', 'valor' => '999']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['atributos.MATERIAL']);

        $this->assertSame(0, EstruturaProdutoAtributo::count());
    }

    public function test_numero_unidade_e_texto_longo_invalidos_respondem_422(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);

        $sessao->putJson($url, $this->ficha([['id' => 'CAPACITY', 'valor' => 'muito']]))
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.CAPACITY']);
        $sessao->putJson($url, $this->ficha([['id' => 'SEAT_HEIGHT', 'valor' => '10', 'unidade' => 'km']]))
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.SEAT_HEIGHT']);
        $sessao->putJson($url, ['atributos' => [['id' => 'BRAND', 'valor' => str_repeat('a', 21)], ['id' => 'MATERIAL', 'valor' => '101']]])
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.BRAND']);
        $sessao->putJson($url, $this->ficha([['id' => 'WITH_DRAWER', 'valor' => 'talvez']]))
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.WITH_DRAWER']);
        $sessao->putJson($url, $this->ficha([['id' => 'CAPACITY', 'valor' => ['a']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.CAPACITY']);

        $this->assertSame(0, EstruturaProdutoAtributo::count());
    }

    public function test_produto_de_outra_empresa_responde_404_e_nada_muda(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $alheio = $this->produto($outra);
        EstruturaProdutoAtributo::create([
            'company_id' => $outra->id, 'produto_id' => $alheio->id, 'atributo_id' => 'BRAND', 'atributo_nome' => 'Marca', 'valor' => 'Original',
        ]);

        $sessao = $this->entrarNoPortal($minha);

        // 404 antes da validação: nem o corpo vazio (que daria 422) nem um válido mudam a resposta.
        $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $alheio->id), $this->ficha())->assertNotFound();
        $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $alheio->id), [])->assertNotFound();
        $sessao->putJson('/portal/estrutura/produtos/999999/ficha-tecnica', $this->ficha())->assertNotFound();
        $sessao->putJson('/portal/estrutura/produtos/abc/ficha-tecnica', $this->ficha())->assertNotFound();

        $this->assertSame('Original', EstruturaProdutoAtributo::where('produto_id', $alheio->id)->value('valor'));
        $this->assertSame(1, EstruturaProdutoAtributo::count());
    }

    public function test_produto_sem_categoria_responde_422_sem_chamar_o_catalogo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa, ['categoria_ml_id' => null, 'categoria_ml_nome' => null]);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['atributos']);

        $this->assertSame(0, $this->chamadas);
    }

    public function test_catalogo_fora_ao_gravar_responde_422_sem_apagar_o_que_ja_estava_salvo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        EstruturaProdutoAtributo::create([
            'company_id' => $empresa->id, 'produto_id' => $produto->id, 'atributo_id' => 'BRAND', 'atributo_nome' => 'Marca', 'valor' => 'Antiga',
        ]);

        $this->catalogoFora = true;
        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha())
            ->assertStatus(422)->assertJsonValidationErrors(['atributos']);

        $this->assertSame('Antiga', EstruturaProdutoAtributo::where('produto_id', $produto->id)->value('valor'));
    }

    public function test_gravar_registra_a_origem_no_log(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha())
            ->assertOk();

        $log = Activity::query()->where('properties->evento', 'ficha_tecnica_gravada')->latest('id')->firstOrFail();
        $this->assertSame('cliente', $log->properties['origem']);
        $this->assertSame(2, $log->properties['campos']);
    }

    // ─── A ficha já salva volta para a tela ─────────────────────────────────

    public function test_a_ficha_do_produto_leva_os_atributos_ja_salvos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        EstruturaProdutoAtributo::create([
            'company_id' => $empresa->id, 'produto_id' => $produto->id, 'atributo_id' => 'MATERIAL', 'atributo_nome' => 'Material',
            'valor' => 'Madeira', 'valor_id' => '101',
        ]);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaProdutoFicha')
                ->has('ficha_tecnica.salvos', 1)
                ->where('ficha_tecnica.salvos.0', ['id' => 'MATERIAL', 'nome' => 'Material', 'valor' => 'Madeira', 'valor_id' => '101', 'unidade' => null])
            );
    }

    public function test_a_ficha_de_produto_novo_leva_a_lista_vazia(): void
    {
        $this->withoutVite()->entrarNoPortal($this->empresaDoGabarito())
            ->get(route('portal.auth.estrutura.produtos.novo'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ficha_tecnica.salvos', []));
    }

    // ─── Schema ─────────────────────────────────────────────────────────────

    private function atributo($empresa, EstruturaProduto $produto, string $id = 'BRAND'): EstruturaProdutoAtributo
    {
        return EstruturaProdutoAtributo::create([
            'company_id' => $empresa->id, 'produto_id' => $produto->id, 'atributo_id' => $id, 'atributo_nome' => 'Marca', 'valor' => 'X',
        ]);
    }

    public function test_a_tabela_tem_as_colunas_do_desenho(): void
    {
        $this->assertTrue(Schema::hasColumns('estrutura_produto_atributos', [
            'id', 'company_id', 'produto_id', 'atributo_id', 'atributo_nome', 'valor', 'valor_id', 'unidade', 'created_at', 'updated_at',
        ]));
    }

    public function test_o_mesmo_atributo_nao_repete_no_produto_mas_repete_em_outro_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $a = $this->produto($empresa);
        $b = $this->produto($empresa);
        $this->atributo($empresa, $a);
        $this->atributo($empresa, $b);

        $this->expectException(QueryException::class);
        $this->atributo($empresa, $a);
    }

    public function test_excluir_o_produto_ou_a_empresa_leva_a_ficha_junto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $this->atributo($empresa, $produto);
        $this->atributo($empresa, $produto, 'MATERIAL');
        $this->atributo($outra, $this->produto($outra));

        $produto->delete();
        $this->assertSame(0, EstruturaProdutoAtributo::where('company_id', $empresa->id)->count());
        $this->assertSame(1, EstruturaProdutoAtributo::where('company_id', $outra->id)->count());

        $outra->delete();
        $this->assertSame(0, EstruturaProdutoAtributo::count());
    }

    public function test_rodar_a_migration_de_novo_e_um_no_op(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->atributo($empresa, $this->produto($empresa));

        $migration = require database_path('migrations/2026_10_07_100200_create_estrutura_produto_atributos_table.php');
        $migration->up();

        $this->assertSame(1, EstruturaProdutoAtributo::count());
    }

    // ─── SIGILO ─────────────────────────────────────────────────────────────

    /** O que NUNCA pode aparecer no que o cliente recebe. */
    private function assertSemOrigem(string $json, string $onde): void
    {
        foreach (['mercado', 'mercadolib', 'anúncio', 'anuncio', 'publicar', 'mlb'] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase($termo, $json, "“{$termo}” vazou em {$onde}");
        }
    }

    public function test_o_que_o_cliente_recebe_nao_revela_de_onde_vem_a_ficha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        // 1. Os campos da categoria (o catálogo traz hint/tooltip/nomes que citam a plataforma).
        $campos = $sessao->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']))->assertOk();
        $this->assertNotEmpty($campos->json('grupos'));
        $this->assertSemOrigem($campos->getContent(), 'campos-categoria');

        // 2. A resposta do salvar.
        $salvou = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha())->assertOk();
        $this->assertSemOrigem($salvou->getContent(), 'salvar');

        // 3. Os erros de validação.
        $erro = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), ['atributos' => [['id' => 'MATERIAL', 'valor' => '999']]])
            ->assertStatus(422);
        $this->assertSemOrigem($erro->getContent(), 'erros');
        $erro2 = $sessao->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'xyz']))->assertStatus(422);
        $this->assertSemOrigem($erro2->getContent(), 'categoria inválida');

        // 4. O que a ficha entrega à tela (a fatia nova das props).
        $sessao->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray();
                $props = $props['props'] ?? $props;
                $this->assertArrayHasKey('ficha_tecnica', $props);
                $this->assertSemOrigem(json_encode($props['ficha_tecnica'], JSON_UNESCAPED_UNICODE), 'props da ficha');
            });
    }

    // ─── Lista que aceita mais de uma opção (os chips) ──────────────────────

    public function test_multivalor_grava_as_opcoes_escolhidas_numa_linha_so_e_volta_para_a_tela(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);

        $sessao->putJson($url, $this->ficha([['id' => 'MATERIALS', 'valor' => ['1', '3']]]))
            ->assertOk()
            ->assertJsonPath('salvos', fn ($salvos) => collect($salvos)->firstWhere('id', 'MATERIALS')['valor'] === 'Algodão | Microfibra');

        $linha = EstruturaProdutoAtributo::where('produto_id', $produto->id)->where('atributo_id', 'MATERIALS')->firstOrFail();
        $this->assertSame('Algodão | Microfibra', $linha->valor, 'uma linha só, nomes na ordem escolhida');
        $this->assertNull($linha->valor_id, 'multivalor não cabe no valor_id (varchar 40): o nome é a verdade');
        $this->assertSame(1, EstruturaProdutoAtributo::where('atributo_id', 'MATERIALS')->count());
    }

    public function test_multivalor_descarta_repetido_e_recusa_opcao_que_nao_existe(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);

        $sessao->putJson($url, $this->ficha([['id' => 'MATERIALS', 'valor' => ['2', '2', '1']]]))->assertOk();
        $this->assertSame('Couro | Algodão', EstruturaProdutoAtributo::where('atributo_id', 'MATERIALS')->value('valor'));

        // Valor que não é opção da categoria é recusado, igual à lista de uma escolha só.
        $sessao->putJson($url, $this->ficha([['id' => 'MATERIALS', 'valor' => ['1', '999']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.MATERIALS']);

        // Lista vazia é campo não preenchido: a linha sai (é o "limpar" dos chips).
        $sessao->putJson($url, $this->ficha([['id' => 'MATERIALS', 'valor' => []]]))->assertOk();
        $this->assertSame(0, EstruturaProdutoAtributo::where('atributo_id', 'MATERIALS')->count());
    }

    public function test_campo_string_com_opcoes_vira_lista_e_recusa_valor_fora_dela(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        // MATERIALS chega do catálogo como `string`; mesmo assim o endpoint entrega como lista.
        $sessao->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']))
            ->assertOk()
            ->assertJsonPath('grupos', function ($grupos) {
                $campos = collect($grupos)->flatMap(fn ($g) => $g['campos'])->keyBy('id');

                return $campos['MATERIALS']['tipo'] === 'lista' && $campos['MATERIALS']['multivalor'] === true;
            });

        // E o servidor recusa texto livre nele — era por aqui que entrava valor que a plataforma rejeita.
        $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id),
            $this->ficha([['id' => 'MATERIALS', 'valor' => 'Algodao escrito a mao']]))
            ->assertStatus(422)->assertJsonValidationErrors(['atributos.MATERIALS']);
    }
}
