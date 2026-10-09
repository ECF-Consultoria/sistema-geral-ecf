<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Publicador\ExplicacaoDeAtributos;
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

                return Http::response(match ($m[1]) {
                    'MLB1' => $this->atributosDaCategoria(),
                    'MLB2' => $this->atributosComEixos(),
                    'MLB3' => $this->atributosComMedidas(),
                    default => [],
                }, 200);
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
            // Deixa variar, mas não é eixo do portal: entra (08/10/2026).
            ['id' => 'UPHOLSTERY_MATERIAL', 'name' => 'Material do estofamento', 'value_type' => 'string',
                'tags' => ['allow_variations' => true], 'values' => [['id' => '7', 'name' => 'Couro'], ['id' => '8', 'name' => 'Tecido']]],
            // Escondidos editáveis: grupo "Mais detalhes", no fim. Ajuda e opção que citam a plataforma não passam.
            ['id' => 'SEAT_WIDTH', 'name' => 'Largura do assento', 'value_type' => 'number_unit', 'tags' => ['hidden' => true],
                'allowed_units' => [['id' => 'cm', 'name' => 'cm']], 'default_unit' => 'cm', 'hint' => 'Aparece no anúncio do Mercado Livre'],
            ['id' => 'LUMBAR_SUPPORT_TYPE', 'name' => 'Tipo de apoio lombar', 'value_type' => 'list', 'tags' => ['hidden' => true],
                'values' => [['id' => '31', 'name' => 'Fixo'], ['id' => '32', 'name' => 'Regulável'], ['id' => '33', 'name' => 'Igual ao anúncio']]],
            // Descartados:
            ['id' => 'ESCONDIDO_ML', 'name' => 'Destaque no Mercado Livre', 'value_type' => 'string', 'tags' => ['hidden' => true]],
            ['id' => 'ITEM_CONDITION', 'name' => 'Condição', 'value_type' => 'list', 'tags' => ['hidden' => true]],
            ['id' => 'INTERNO', 'name' => 'Interno', 'value_type' => 'string', 'tags' => ['read_only' => true]],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            ['id' => 'VOLTAGE', 'name' => 'Voltagem', 'value_type' => 'list', 'tags' => ['variation_attribute' => true]],
            ['id' => 'APARECE', 'name' => 'Aparece no Mercado Livre', 'value_type' => 'string', 'tags' => []],
        ];
    }

    /**
     * Categoria onde Cor e Material DEIXAM variar (os dois são eixo do portal) e o Material é
     * obrigatório: o produto que varia por cor precisa informá-lo; o que varia por material, não.
     */
    private function atributosComEixos(): array
    {
        return [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => []],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true],
                'values' => [['id' => '52049', 'name' => 'Preto'], ['id' => '52055', 'name' => 'Branco']]],
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'string', 'tags' => ['allow_variations' => true, 'required' => true],
                'values' => [['id' => '201', 'name' => 'Madeira'], ['id' => '202', 'name' => 'Metal']]],
        ];
    }

    /**
     * Categoria do Puff (09/10/2026): as medidas genéricas do produto (escondidas, como no catálogo
     * real) e uma ajuda que cita a plataforma, que não pode chegar ao cliente.
     */
    private function atributosComMedidas(): array
    {
        $medida = fn (string $id, string $nome, array $unidades) => ['id' => $id, 'name' => $nome, 'value_type' => 'number_unit',
            'tags' => ['hidden' => true], 'allowed_units' => array_map(fn ($u) => ['id' => $u, 'name' => $u], $unidades),
            'default_unit' => $unidades[0], 'tooltip' => 'Medida exibida no anúncio do Mercado Livre'];

        return [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => []],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            $medida('HEIGHT', 'Altura', ['cm', 'mm']),
            $medida('DIAMETER', 'Diâmetro', ['cm', 'mm']),
            $medida('WEIGHT', 'Peso', ['kg', 'g']),
            $medida('LENGTH', 'Comprimento', ['cm', 'mm']),
            $medida('WIDTH', 'Largura', ['cm', 'mm']),
        ];
    }

    /** Variações do produto, uma por par [eixo, valor]. */
    private function variacoes($empresa, EstruturaProduto $produto, array $pares): void
    {
        EstruturaProdutoVariacao::where('produto_id', $produto->id)->delete();
        foreach ($pares as $i => [$eixo, $valor]) {
            EstruturaProdutoVariacao::create(['company_id' => $empresa->id, 'produto_id' => $produto->id, 'ordem' => $i,
                'codigo' => "EX-{$produto->id}-{$i}", 'nome' => $produto->nome, 'eixo' => $eixo, 'valor' => $valor]);
        }
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
        $this->assertSame(['Principais', 'Dimensões', 'Outras características', 'Mais detalhes'], array_column($grupos, 'grupo'));
        $this->assertSame(['SEAT_WIDTH', 'LUMBAR_SUPPORT_TYPE'], array_column($grupos[3]['campos'], 'id'));
        $this->assertContains('UPHOLSTERY_MATERIAL', array_column($grupos[2]['campos'], 'id'));
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

        $campos = array_column(array_merge(...array_column($grupos, 'campos')), null, 'id');
        foreach (['ITEM_CONDITION', 'INTERNO', 'VOLTAGE', 'APARECE', 'ESCONDIDO_ML'] as $fora) {
            $this->assertArrayNotHasKey($fora, $campos);
        }
        // A cor entra marcada como eixo do portal (quem a esconde é o produto que varia por cor).
        $this->assertSame('cor', $campos['COLOR']['eixo_do_portal']);
        $this->assertNull($campos['BRAND']['eixo_do_portal']);
        $this->assertNull($campos['UPHOLSTERY_MATERIAL']['eixo_do_portal'], 'deixa variar, mas não é eixo do portal');
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
        $this->variacoes($empresa, $produto, [['cor', 'Azul'], ['cor', 'Preto']]);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha([
                ['id' => 'COLOR', 'valor' => 'Azul'],          // o produto varia por cor: fora da ficha
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
        // O JSON do Laravel escapa acento; sem decodificar, “anúncio” passaria batido.
        $json = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $json);
        foreach (['mercado', 'mercadolib', 'anúncio', 'anuncio', 'publicar', 'mlb'] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase($termo, $json, "“{$termo}” vazou em {$onde}");
        }
    }

    public function test_o_que_o_cliente_recebe_nao_revela_de_onde_vem_a_ficha(): void
    {
        // O glossário também pode errar: um texto que cite a plataforma tem de ser neutralizado.
        config([
            'publicador_glossario.atributos.CAPACITY' => 'Quanto cabe, como pede o Mercado Livre no anúncio.',
            'publicador_glossario.portal_campos.custo' => 'Custo usado para calcular o preço do anúncio.',
        ]);
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        // 1. Os campos da categoria (o catálogo traz hint/tooltip/nomes que citam a plataforma).
        $campos = $sessao->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']))->assertOk();
        $this->assertNotEmpty($campos->json('grupos'));
        $this->assertContains('Mais detalhes', array_column($campos->json('grupos'), 'grupo'), 'a varredura cobre o grupo novo');
        $this->assertSemOrigem($campos->getContent(), 'campos-categoria');
        $this->assertSemOrigem(json_encode(end($campos->json()['grupos']), JSON_UNESCAPED_UNICODE), 'grupo Mais detalhes');

        // 1b. As explicações (o ícone ao lado de cada rótulo): todo campo tem uma, e nenhuma revela a origem.
        $explicacoes = array_column(array_merge(...array_column($campos->json('grupos'), 'campos')), 'explicacao', 'id');
        $this->assertSame(array_column(array_merge(...array_column($campos->json('grupos'), 'campos')), 'id'), array_keys($explicacoes));
        foreach ($explicacoes as $id => $texto) {
            $this->assertIsString($texto, "{$id} sem explicação");
            $this->assertNotSame('', trim($texto), "{$id} com explicação vazia");
        }
        $this->assertSemOrigem(json_encode($explicacoes, JSON_UNESCAPED_UNICODE), 'explicações da ficha técnica');
        // Glossário que citava a plataforma → texto montado; ajuda do catálogo que citava → texto montado.
        $this->assertSame('Capacidade, em número.', $explicacoes['CAPACITY']);
        $this->assertSame('Largura do assento, em centímetros.', $explicacoes['SEAT_WIDTH']);
        // A marca tinha ajuda que cita a plataforma no catálogo; vale o glossário (neutro).
        $this->assertSame(config('publicador_glossario.atributos.BRAND'), $explicacoes['BRAND']);

        // 2. A resposta do salvar.
        $salvou = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), $this->ficha())->assertOk();
        $this->assertSemOrigem($salvou->getContent(), 'salvar');
        $comNa = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id),
            $this->ficha([['id' => 'SEAT_WIDTH', 'nao_se_aplica' => true], ['id' => 'LUMBAR_SUPPORT_TYPE', 'valor' => '32']]))->assertOk();
        $this->assertSemOrigem($comNa->getContent(), 'salvar com "Não se aplica"');
        $naRecusado = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id),
            $this->ficha([['id' => 'BRAND', 'nao_se_aplica' => true]]))->assertStatus(422);
        $this->assertSemOrigem($naRecusado->getContent(), '"Não se aplica" recusado');

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

                // As explicações dos campos fixos: neutras; a que citava a plataforma sai (o campo fica sem ícone).
                $fixos = $props['explicacoes_campos'];
                $this->assertSemOrigem(json_encode($fixos, JSON_UNESCAPED_UNICODE), 'explicações dos campos fixos');
                $this->assertArrayNotHasKey('custo', $fixos);
                $this->assertSame(app(ExplicacaoDeAtributos::class)->camposFixos()['estoque'], $fixos['estoque'], 'o mesmo texto do editor');
                foreach (['nome', 'familia', 'ambientes', 'categoria', 'ref', 'eixo', 'valor', 'volumes', 'comprimento', 'largura', 'altura', 'peso', 'descricao', 'estoque_produto'] as $chave) {
                    $this->assertNotEmpty($fixos[$chave] ?? null, "sem explicação para {$chave}");
                }
            });
    }

    /** Fase 172-05: descrição e estoque (campos novos) também não revelam a origem. */
    public function test_campos_novos_nao_revelam_origem(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa, ['descricao' => 'Mesa de jantar em madeira maciça, acompanha manual.']);
        \App\Models\EstruturaProdutoVariacao::create([
            'company_id' => $empresa->id, 'produto_id' => $produto->id, 'codigo' => 'EST-N1', 'nome' => 'Cadeira Teste', 'estoque' => 5,
        ]);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        // 1. Props da ficha: só as chaves novas (`ml_conectado` é anterior à fase e fica fora).
        $sessao->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray();
                $props = $props['props'] ?? $props;
                $this->assertSame('Mesa de jantar em madeira maciça, acompanha manual.', $props['descricao']);
                $this->assertSemOrigem(json_encode($props['descricao'], JSON_UNESCAPED_UNICODE), 'descricao');
                $estoques = array_map(fn ($l) => ['estoque' => $l['estoque'] ?? null], (array) $props['linhas']);
                $this->assertNotEmpty($estoques);
                $this->assertSemOrigem(json_encode($estoques), 'estoque das linhas');
            });

        // 2. PUT da descrição: 200 e 422.
        $url = route('portal.auth.estrutura.produtos.descricao', $produto->id);
        $this->assertSemOrigem($sessao->putJson($url, ['descricao' => 'Texto neutro.'])->assertOk()->getContent(), 'PUT descrição 200');
        $this->assertSemOrigem($sessao->putJson($url, ['descricao' => str_repeat('a', 5001)])->assertStatus(422)->getContent(), 'PUT descrição 422');

        // 3. Estoque inválido no POST de linhas: a recusa não cita a origem.
        $resp = $sessao->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => [[
            'chave' => 'k', 'codigo' => 'EST-N2', 'nome' => 'Mesa', 'estoque' => '-1',
        ]]]);
        $this->assertNotEmpty($resp->json('erros'), 'estoque -1 deve ser recusado');
        $this->assertSemOrigem($resp->getContent(), 'estoque inválido');
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

    // ─── "Não se aplica" (08/10/2026) ──────────────────────────────────────

    public function test_a_definicao_diz_quais_campos_aceitam_nao_se_aplica(): void
    {
        $grupos = $this->entrarNoPortal($this->empresaDoGabarito())
            ->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB1']))
            ->assertOk()->json('grupos');
        $na = array_column(array_merge(...array_column($grupos, 'campos')), 'nao_se_aplica', 'id');

        $this->assertFalse($na['BRAND'], 'obrigatório não aceita');
        $this->assertFalse($na['MATERIAL'], 'obrigatório não aceita');
        $this->assertTrue($na['SEAT_HEIGHT']);
        $this->assertTrue($na['WITH_DRAWER']);
        $this->assertTrue($na['SEAT_WIDTH'], 'o escondido editável também');
    }

    public function test_nao_se_aplica_grava_valor_id_menos_um_sem_valor_e_volta_ao_reabrir(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);

        // O marcador vence o valor que vier junto (o controle fica travado na tela).
        $salvos = $sessao->putJson($url, $this->ficha([
            ['id' => 'WITH_DRAWER', 'nao_se_aplica' => true, 'valor' => 'Sim'],
            ['id' => 'SEAT_WIDTH', 'nao_se_aplica' => true, 'valor' => '45', 'unidade' => 'cm'],
        ]))->assertOk()->json('salvos');

        $porId = collect($salvos)->keyBy('id');
        $this->assertSame('-1', $porId['WITH_DRAWER']['valor_id']);
        $this->assertNull($porId['WITH_DRAWER']['valor']);
        $this->assertSame('-1', $porId['SEAT_WIDTH']['valor_id']);
        $this->assertNull($porId['SEAT_WIDTH']['unidade']);

        $linha = EstruturaProdutoAtributo::where('produto_id', $produto->id)->where('atributo_id', 'SEAT_WIDTH')->firstOrFail();
        $this->assertSame('-1', $linha->valor_id);
        $this->assertNull($linha->valor);
        $this->assertNull($linha->unidade);

        // Reabrir a ficha devolve o N/A à tela.
        $sessao->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray();
                $props = $props['props'] ?? $props;
                $salvos = collect($props['ficha_tecnica']['salvos'])->keyBy('id');
                $this->assertSame('-1', $salvos['WITH_DRAWER']['valor_id']);
            });

        // Desmarcar e preencher troca o N/A pelo valor.
        $salvos = $sessao->putJson($url, $this->ficha([['id' => 'WITH_DRAWER', 'valor' => 'Não']]))->assertOk()->json('salvos');
        $porId = collect($salvos)->keyBy('id');
        $this->assertSame('Não', $porId['WITH_DRAWER']['valor']);
        $this->assertNull($porId['WITH_DRAWER']['valor_id']);
        $this->assertArrayNotHasKey('SEAT_WIDTH', $porId->all(), 'ausente sai, como qualquer campo');
    }

    public function test_nao_se_aplica_em_obrigatorio_responde_422_e_nao_grava_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id),
                $this->ficha([['id' => 'MATERIAL', 'nao_se_aplica' => true]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('atributos.MATERIAL');

        $this->assertSame(0, EstruturaProdutoAtributo::where('produto_id', $produto->id)->count());
    }

    // ─── Eixo de variação por PRODUTO, não por categoria (08/10/2026) ───────

    public function test_produto_que_varia_por_cor_grava_o_material_e_o_que_varia_por_material_nao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        $definicao = $sessao->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB2']))->assertOk()->json('grupos');
        $marcas = array_column(array_merge(...array_column($definicao, 'campos')), 'eixo_do_portal', 'id');
        ksort($marcas);
        $this->assertSame(['BRAND' => null, 'COLOR' => 'cor', 'MATERIAL' => 'material'], $marcas);

        // Varia por cor: o material é do produto, obrigatório e gravado.
        $porCor = $this->produto($empresa, ['categoria_ml_id' => 'MLB2']);
        $this->variacoes($empresa, $porCor, [['cor', 'Preto'], ['cor', 'Branco']]);
        $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $porCor->id), ['atributos' => [['id' => 'BRAND', 'valor' => 'X']]])
            ->assertStatus(422)->assertJsonValidationErrors('atributos.MATERIAL');
        $salvos = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $porCor->id), ['atributos' => [
            ['id' => 'MATERIAL', 'valor' => '201'], ['id' => 'COLOR', 'valor' => '52049'],
        ]])->assertOk()->json('salvos');
        $this->assertSame(['MATERIAL' => '201'], array_column($salvos, 'valor_id', 'id'), 'a cor é a variação: ignorada');

        // Varia por material: o material vem da variação — ignorado, e deixa de ser exigido.
        $porMaterial = $this->produto($empresa, ['categoria_ml_id' => 'MLB2']);
        $this->variacoes($empresa, $porMaterial, [['material', 'Madeira'], ['material', 'Metal']]);
        $salvos = $sessao->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $porMaterial->id), ['atributos' => [
            ['id' => 'MATERIAL', 'valor' => '202'], ['id' => 'COLOR', 'valor' => '52055'],
        ]])->assertOk()->json('salvos');
        $this->assertSame(['COLOR' => '52055'], array_column($salvos, 'valor_id', 'id'), 'sem variar por cor, a cor é do produto');
        $this->assertFalse(EstruturaProdutoAtributo::where('produto_id', $porMaterial->id)->where('atributo_id', 'MATERIAL')->exists());
    }

    public function test_trocar_o_eixo_do_produto_tira_o_campo_e_a_linha_antiga_e_voltar_o_devolve(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $produto = $this->produto($empresa, ['categoria_ml_id' => 'MLB2']);
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);
        $gravado = fn () => EstruturaProdutoAtributo::where('produto_id', $produto->id)->orderBy('atributo_id')->pluck('valor_id', 'atributo_id')->all();

        // Sem variação nenhuma (ou só "—"): os dois campos valem para o produto.
        $this->variacoes($empresa, $produto, [[null, null]]);
        $sessao->putJson($url, ['atributos' => [['id' => 'MATERIAL', 'valor' => '201'], ['id' => 'COLOR', 'valor' => '52049']]])->assertOk();
        $this->assertSame(['COLOR' => '52049', 'MATERIAL' => '201'], $gravado());

        // Passou a variar por material: o salvar seguinte limpa o material, mesmo que a tela o mande.
        $this->variacoes($empresa, $produto, [['material', 'Madeira'], ['material', 'Metal']]);
        $sessao->putJson($url, ['atributos' => [['id' => 'MATERIAL', 'valor' => '201'], ['id' => 'COLOR', 'valor' => '52049']]])->assertOk();
        $this->assertSame(['COLOR' => '52049'], $gravado());

        // Voltou a variar por cor: o material volta a ser do produto (e a cor sai).
        $this->variacoes($empresa, $produto, [['cor', 'Preto'], ['cor', 'Branco']]);
        $sessao->putJson($url, ['atributos' => [['id' => 'MATERIAL', 'valor' => '202'], ['id' => 'COLOR', 'valor' => '52049']]])->assertOk();
        $this->assertSame(['MATERIAL' => '202'], $gravado());
    }

    public function test_os_eixos_do_produto_saem_das_variacoes_dele_e_so_dele(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa, ['categoria_ml_id' => 'MLB2']);
        $outro = $this->produto($empresa, ['categoria_ml_id' => 'MLB2']);
        $this->variacoes($empresa, $produto, [['cor', 'Preto'], ['cor', 'Branco'], [null, null], ['', null]]);
        $this->variacoes($empresa, $outro, [['material', 'Metal']]);

        $this->assertSame(['cor'], \App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto::eixosDoProduto($produto));
        $this->assertSame(['material'], \App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto::eixosDoProduto($outro));
    }

    public function test_menos_um_digitado_num_texto_continua_texto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);

        $salvos = $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id), ['atributos' => [
                ['id' => 'BRAND', 'valor' => '-1'], ['id' => 'MATERIAL', 'valor' => '101'],
            ]])
            ->assertOk()->json('salvos');

        $marca = collect($salvos)->firstWhere('id', 'BRAND');
        $this->assertSame('-1', $marca['valor']);
        $this->assertNull($marca['valor_id'], 'só o marcador grava o N/A');
    }

    // ─── Medidas do produto fora da caixa (09/10/2026) ───────────────────────

    public function test_medidas_do_produto_vem_num_bloco_proprio_e_gravam_como_atributo_normal(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa, ['categoria_ml_id' => 'MLB3']);
        $this->variacoes($empresa, $produto, [['cor', 'Azul']]);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $grupos = $sessao->getJson(route('portal.auth.estrutura.produtos.campos_categoria', ['categoria' => 'MLB3']))->assertOk()->json('grupos');
        $medidas = collect($grupos)->firstWhere('medidas_do_produto', true);
        $this->assertNotNull($medidas, 'as medidas do produto vêm num grupo próprio');
        $this->assertSame('Medidas do produto (fora da caixa)', $medidas['grupo']);
        $this->assertSame(['LENGTH', 'WIDTH', 'HEIGHT', 'DIAMETER', 'WEIGHT'], array_column($medidas['campos'], 'id'));
        $outros = collect($grupos)->reject(fn ($g) => ! empty($g['medidas_do_produto']))->flatMap(fn ($g) => array_column($g['campos'], 'id'))->all();
        $this->assertNotContains('DIAMETER', $outros, 'não aparece duas vezes');
        $this->assertSemOrigem(json_encode($medidas, JSON_UNESCAPED_UNICODE), 'bloco das medidas do produto');

        // Gravam pelo mesmo PUT da ficha, como atributos normais (sem coluna nova).
        $url = route('portal.auth.estrutura.produtos.ficha_tecnica', $produto->id);
        $salvou = $sessao->putJson($url, ['atributos' => [
            ['id' => 'LENGTH', 'valor' => '60', 'unidade' => 'cm'], ['id' => 'WIDTH', 'valor' => '60', 'unidade' => 'cm'],
            ['id' => 'HEIGHT', 'valor' => '40', 'unidade' => 'cm'], ['id' => 'DIAMETER', 'valor' => '58,5', 'unidade' => 'cm'],
            ['id' => 'WEIGHT', 'valor' => '7,5', 'unidade' => 'kg'],
        ]])->assertOk();
        $this->assertSemOrigem($salvou->getContent(), 'PUT com as medidas');

        $linhas = EstruturaProdutoAtributo::where('produto_id', $produto->id)->get()->keyBy('atributo_id');
        $this->assertSame('58.5', $linhas['DIAMETER']->valor);
        $this->assertSame('cm', $linhas['DIAMETER']->unidade);
        $this->assertSame('7.5', $linhas['WEIGHT']->valor);
        $this->assertSame('kg', $linhas['WEIGHT']->unidade);
        $this->assertCount(5, $linhas);

        // Unidade que a medida não aceita é recusada como qualquer outro campo.
        $sessao->putJson($url, ['atributos' => [['id' => 'HEIGHT', 'valor' => '40', 'unidade' => 'km']]])
            ->assertStatus(422)->assertJsonValidationErrors('atributos.HEIGHT');
    }

    public function test_a_explicacao_do_bloco_e_da_caixa_do_volume_sao_neutras(): void
    {
        $textos = app(ExplicacaoDeAtributos::class)->camposDoPortal();

        $this->assertArrayHasKey('medidas_produto', $textos);
        $this->assertArrayHasKey('mesmas_medidas', $textos);
        $this->assertSemOrigem(json_encode([$textos['medidas_produto'], $textos['mesmas_medidas']], JSON_UNESCAPED_UNICODE), 'explicações das medidas');
    }
}
