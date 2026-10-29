<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoOrigemMl;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\JardinagemDaFicha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * "Preencher com IA" na ficha técnica e a IA da importação marcando "Não se aplica" (29/10/2026).
 *
 * POR QUE EXISTE: o usuário importou uma poltrona do anúncio e a ficha veio com "Largura do colchão" e
 * outras perguntas que não são do produto em branco — a IA da importação só podia omitir. Agora ela
 * responde valor, "não se aplica" (só onde o campo aceita: opcional que a plataforma mostra) ou nada.
 * O botão NÃO grava: devolve sugestões validadas para a tela, que a pessoa revisa e salva.
 */
class FichaTecnicaPelaIaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** O que a IA falsa recebeu e o que ela responde. */
    public array $pedido = [];

    public array $resposta = [];

    public bool $iaFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Queue::fake();

        Http::fake(function (Request $r) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/categories/MLB9/attributes')) {
                return Http::response([
                    ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
                    ['id' => 'IS_RECLINABLE', 'name' => 'É reclinável', 'value_type' => 'boolean', 'tags' => ['required' => true],
                        'values' => [['id' => '242085', 'name' => 'Sim'], ['id' => '242084', 'name' => 'Não']]],
                    ['id' => 'LEGS_MATERIAL', 'name' => 'Material dos pés', 'value_type' => 'list', 'tags' => [],
                        'values' => [['id' => '1', 'name' => 'Metal'], ['id' => '2', 'name' => 'Madeira']]],
                    ['id' => 'STYLE', 'name' => 'Estilo', 'value_type' => 'list', 'tags' => [],
                        'values' => [['id' => '7', 'name' => 'Clássico'], ['id' => '8', 'name' => 'Moderno']]],
                    ['id' => 'MATTRESS_WIDTH', 'name' => 'Largura do colchão', 'value_type' => 'number_unit', 'tags' => [],
                        'allowed_units' => [['id' => 'cm', 'name' => 'cm']], 'default_unit' => 'cm'],
                    ['id' => 'WITH_REMOVABLE_COVER', 'name' => 'Com tecido removível', 'value_type' => 'boolean', 'tags' => [],
                        'values' => [['id' => '242085', 'name' => 'Sim'], ['id' => '242084', 'name' => 'Não']]],
                    // Escondido: a plataforma nem mostra; no Portal não aceita "Não se aplica".
                    ['id' => 'BED_WIDTH', 'name' => 'Largura da cama', 'value_type' => 'number_unit', 'tags' => ['hidden' => true],
                        'allowed_units' => [['id' => 'm', 'name' => 'm']], 'default_unit' => 'm'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $teste = $this;
        $this->app->instance(AnaliseAnuncioService::class, new class($teste) extends AnaliseAnuncioService
        {
            public function __construct(private $t) {}

            public function ficha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo, bool $comNaoSeAplica = false): array
            {
                if ($this->t->iaFora) {
                    throw new \RuntimeException('provedor fora');
                }
                $this->t->pedido = compact('produto', 'specs', 'titulo', 'catalogo', 'comNaoSeAplica');

                return ['dados' => ['atributos' => $this->t->resposta], 'meta' => ['modelo' => 'teste']];
            }
        });

        // A IA "esperta": um valor que o título sustenta, "não se aplica" no colchão e na cama, um valor
        // fora da lista, "não se aplica" num obrigatório e uma resposta para o campo já preenchido na tela.
        $this->resposta = [
            'LEGS_MATERIAL' => 'Madeira', 'MATTRESS_WIDTH' => 'NAO_SE_APLICA', 'BED_WIDTH' => 'NAO_SE_APLICA',
            'STYLE' => 'Barroco', 'IS_RECLINABLE' => 'NAO_SE_APLICA', 'WITH_REMOVABLE_COVER' => 'Sim', 'BRAND' => 'Inventada',
        ];
    }

    private function poltrona($empresa): EstruturaProduto
    {
        return EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-'.uniqid(), 'nome' => 'Poltrona Opala',
            'categoria_ml_id' => 'MLB9', 'categoria_ml_nome' => 'Poltronas', 'descricao' => 'Poltrona com pés palito de madeira.']);
    }

    private function pedir($empresa, EstruturaProduto $p, array $mais = [])
    {
        return $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.ficha_tecnica.ia', $p->id), array_merge([
            'categoria' => 'MLB9', 'titulo' => 'Poltrona Opala Linho Pés de Madeira', 'descricao' => '',
            // Na tela, sem salvar: a Marca e o tecido removível já estão preenchidos.
            'atributos' => [['id' => 'BRAND', 'valor' => 'ECF'], ['id' => 'WITH_REMOVABLE_COVER', 'valor' => 'Não']],
        ], $mais));
    }

    public function test_sugere_so_o_vazio_e_valida_cada_resposta_sem_gravar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $p = $this->poltrona($empresa);

        $r = $this->pedir($empresa, $p)->assertOk();

        $sugestoes = collect($r->json('sugestoes'))->keyBy('id');
        $this->assertEqualsCanonicalizing(['LEGS_MATERIAL', 'MATTRESS_WIDTH'], $sugestoes->keys()->all());
        $this->assertSame(['Madeira', '2'], [$sugestoes['LEGS_MATERIAL']['valor'], $sugestoes['LEGS_MATERIAL']['valor_id']]);
        $this->assertSame('-1', $sugestoes['MATTRESS_WIDTH']['valor_id'], 'o colchão numa poltrona: não se aplica');
        $this->assertNull($sugestoes['MATTRESS_WIDTH']['valor']);
        $this->assertSame(5, $r->json('faltavam'), 'Marca e tecido removível estavam preenchidos na tela');

        // A IA só foi perguntada sobre o que falta, com a marca [N/A] onde o campo aceita.
        $this->assertTrue($this->pedido['comNaoSeAplica']);
        $catalogo = collect(explode("\n", $this->pedido['catalogo']))->keyBy(fn ($l) => explode(' | ', $l)[0]);
        $this->assertStringEndsWith('[N/A]', $catalogo['MATTRESS_WIDTH']);
        $this->assertStringEndsWith('[N/A]', $catalogo['STYLE']);
        $this->assertStringNotContainsString('[N/A]', $catalogo['BED_WIDTH'], 'escondido não aceita');
        $this->assertStringNotContainsString('[N/A]', $catalogo['IS_RECLINABLE*'], 'obrigatório não aceita');
        $this->assertArrayNotHasKey('BRAND*', $catalogo->all());
        $this->assertArrayNotHasKey('WITH_REMOVABLE_COVER', $catalogo->all());
        $this->assertStringContainsString('Com tecido removível: Não', $this->pedido['specs'], 'o que está na tela vira fato');
        $this->assertStringContainsString('pés palito de madeira', $this->pedido['specs'], 'sem descrição na tela, vale a salva');

        $this->assertSame(0, EstruturaProdutoAtributo::where('produto_id', $p->id)->count(), 'o botão não grava');
        $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|MLB/i', $r->getContent());
    }

    public function test_produto_de_outra_empresa_e_404_e_categoria_trocada_sem_salvar_e_422(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $alheio = $this->poltrona($outra);
        $this->pedir($empresa, $alheio)->assertNotFound();

        $p = $this->poltrona($empresa);
        $this->pedir($empresa, $p, ['categoria' => 'MLB10'])->assertStatus(422)
            ->assertJsonPath('message', 'A categoria mudou: salve o produto antes de usar a IA.');
        $this->assertSame([], $this->pedido, 'nem chegou a perguntar');
    }

    public function test_ia_fora_do_ar_responde_422_com_mensagem(): void
    {
        $empresa = $this->empresaDoGabarito();
        $p = $this->poltrona($empresa);
        $this->iaFora = true;

        $this->pedir($empresa, $p)->assertStatus(422)->assertJsonPath('message', 'A IA não respondeu agora. Tente de novo em instantes.');
    }

    public function test_a_rota_existe_no_dominio_do_cliente_so_com_id_numerico(): void
    {
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/459/estrutura/produtos/79/ficha-tecnica/ia'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/459/estrutura/produtos/abc/ficha-tecnica/ia'));
    }

    public function test_a_ia_da_importacao_marca_nao_se_aplica_onde_o_campo_aceita(): void
    {
        $empresa = $this->empresaDoGabarito();
        $p = $this->poltrona($empresa);
        EstruturaProdutoAtributo::create(['company_id' => $empresa->id, 'produto_id' => $p->id, 'atributo_id' => 'BRAND', 'atributo_nome' => 'Marca', 'valor' => 'ECF']);
        $origem = EstruturaProdutoOrigemMl::create(['company_id' => $empresa->id, 'produto_id' => $p->id, 'ml_item_id' => 'MLB123',
            'retrato' => ['titulo' => 'Poltrona Opala Pés de Madeira'], 'importado_em' => now()]);

        $registro = app(JardinagemDaFicha::class)->jardinar($empresa, $origem);

        $ficha = EstruturaProdutoAtributo::where('produto_id', $p->id)->get()->keyBy('atributo_id');
        $this->assertSame('-1', $ficha['MATTRESS_WIDTH']->valor_id, 'colchão: não se aplica');
        $this->assertSame('Madeira', $ficha['LEGS_MATERIAL']->valor);
        $this->assertSame('Sim', $ficha['WITH_REMOVABLE_COVER']->valor, 'vazio na ficha salva: a IA preenche');
        $this->assertFalse($ficha->has('BED_WIDTH'), 'escondido: "não se aplica" descartado');
        $this->assertFalse($ficha->has('IS_RECLINABLE'), 'obrigatório: "não se aplica" descartado');
        $this->assertFalse($ficha->has('STYLE'), 'opção que não existe fica de fora');
        $this->assertSame('ECF', $ficha['BRAND']->valor, 'o que já estava fica');
        $this->assertContains('MATTRESS_WIDTH', $registro['campos_ia']);
        $this->assertTrue($this->pedido['comNaoSeAplica']);
    }
}
