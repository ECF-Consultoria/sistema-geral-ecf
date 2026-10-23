<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaProduto;
use App\Models\MlCategoriaSchema;
use App\Services\Portal\Estrutura\Produtos\ImportadorProdutos;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Título de venda no cadastro do produto do Portal (19/10/2026): separado do nome, com a 1ª letra
 * de cada palavra maiúscula (20/10/2026), limitado pelo `max_title_length` da categoria, montado com os termos mais buscados (a
 * rota não cita a plataforma) e, na planilha, título e categoria vazios não bloqueiam — o produto
 * entra pendente. Nenhuma chamada real: o schema da categoria já está guardado e o resto é fake.
 */
class TituloDeVendaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const CATEGORIA = 'MLB1';

    private bool $termosFora = false;

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        // O schema da categoria já lido (24 h): limite de 60 e o caminho.
        MlCategoriaSchema::create([
            'category_id' => self::CATEGORIA, 'domain_id' => null, 'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
            'categoria' => [
                'id' => self::CATEGORIA, 'name' => 'Cadeiras Gamer', 'children_categories' => [],
                'settings' => ['max_title_length' => 60],
                'path_from_root' => [['id' => 'MLB0', 'name' => 'Casa'], ['id' => 'MLB9', 'name' => 'Cadeiras'], ['id' => self::CATEGORIA, 'name' => 'Cadeiras Gamer']],
            ],
            'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
        ]);

        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/trends/MLB/'.self::CATEGORIA)) {
                if ($this->termosFora) {
                    return Http::response(['message' => 'erro'], 500);
                }

                return Http::response(array_map(fn (string $t) => ['keyword' => $t, 'url' => 'https://lista.mercadolivre.com.br/'.str_replace(' ', '-', $t)], [
                    'cadeira gamer', 'cadeira gamer barata', 'cadeira escritorio', 'cadeira gamer reclinavel', 'husky', 'pichau',
                    'cadeira gamer rgb', 'cadeira gamer com apoio de pe', 'cadeira de escritorio ergonomica', 'cadeira presidente', 'cadeira giratoria', 'gamer',
                ]));
            }
            if (preg_match('#/categories/(MLB\d+)$#', $r->url(), $m) && $m[1] === self::CATEGORIA) {
                return Http::response([
                    'id' => self::CATEGORIA, 'name' => 'Cadeiras Gamer', 'children_categories' => [], 'settings' => ['max_title_length' => 60],
                    'path_from_root' => [['id' => 'MLB0', 'name' => 'Casa'], ['id' => 'MLB9', 'name' => 'Cadeiras'], ['id' => self::CATEGORIA, 'name' => 'Cadeiras Gamer']],
                ]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function gravar($sessao, array $linha)
    {
        return $sessao->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => [
            ['chave' => 'k1', 'codigo' => 'CG-1', 'nome' => 'Cadeira Gamer Azul', 'volumes' => [['c' => 70, 'l' => 60, 'a' => 40, 'kg' => 18]], ...$linha],
        ]]);
    }

    // ─── Ficha: nome × título ───────────────────────────────────────────────

    public function test_nome_e_titulo_sao_campos_distintos_e_o_titulo_ganha_a_maiuscula(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        $r = $this->gravar($sessao, ['titulo' => 'cadeira gamer  reclinável azul'])->assertOk();

        $produto = EstruturaProduto::where('company_id', $empresa->id)->firstOrFail();
        $this->assertSame('Cadeira Gamer Azul', $produto->nome);
        $this->assertSame('Cadeira Gamer Reclinável Azul', $produto->titulo, 'maiúscula em toda palavra e espaços juntados; o resto como veio');
        $this->assertSame('Cadeira Gamer Reclinável Azul', $r->json('linhas.0.titulo'));
        $this->assertNotContains(PendenciasDoProduto::TITULO, $r->json('linhas.0.pendencias'));

        $this->gravar($sessao, ['id' => $r->json('linhas.0.id'), 'titulo' => 'ébano poltrona'])->assertOk();
        $this->assertSame('Ébano Poltrona', $produto->fresh()->titulo);
    }

    public function test_sem_titulo_o_produto_salva_e_fica_pendente_e_null_explicito_limpa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        $r = $this->gravar($sessao, [])->assertOk();
        $this->assertSame([], $r->json('erros'));
        $this->assertContains(PendenciasDoProduto::TITULO, $r->json('linhas.0.pendencias'));
        $this->assertSame('título', PendenciasDoProduto::ROTULOS[PendenciasDoProduto::TITULO]);

        $id = $r->json('linhas.0.id');
        $this->gravar($sessao, ['id' => $id, 'titulo' => 'Cadeira gamer'])->assertOk();
        $this->gravar($sessao, ['id' => $id, 'titulo' => null])->assertOk();
        $this->assertNull(EstruturaProduto::where('company_id', $empresa->id)->value('titulo'));
    }

    public function test_titulo_acima_do_limite_da_categoria_e_recusado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $longo = 'Cadeira gamer reclinável com apoio de braço e almofada lombar preta'; // 67

        $r = $this->gravar($sessao, ['titulo' => $longo, 'categoria_ml_id' => self::CATEGORIA])->assertOk();

        $this->assertSame(1, $r->json('totais.com_erro'));
        $this->assertStringContainsString('o máximo desta categoria é 60', $r->json('erros.0.mensagem'));
        $this->assertSame(0, EstruturaProduto::where('company_id', $empresa->id)->count(), 'a linha recusada não grava nada');

        // No limite, passa.
        $this->gravar($sessao, ['titulo' => mb_substr($longo, 0, 60), 'categoria_ml_id' => self::CATEGORIA])->assertOk()->assertJsonPath('totais.criadas', 1);
    }

    public function test_o_titulo_volta_na_ficha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $this->gravar($sessao, ['titulo' => 'cadeira gamer'])->assertOk();
        $produto = EstruturaProduto::where('company_id', $empresa->id)->firstOrFail();

        $sessao->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('linhas.0.titulo', 'Cadeira Gamer')->where('linhas.0.nome', 'Cadeira Gamer Azul'));
    }

    // ─── Termos da categoria ────────────────────────────────────────────────

    public function test_os_termos_vem_todos_com_o_limite_e_sem_nada_que_cite_a_plataforma(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.produtos.termos_titulo', ['categoria' => self::CATEGORIA, 'nome' => 'Cadeira Gamer Azul']))
            ->assertOk();

        $this->assertSame(60, $r->json('limite'));
        $this->assertFalse($r->json('indisponivel'));
        $this->assertCount(12, $r->json('termos'), 'todos os termos da categoria, sem filtro');
        $this->assertSame(['posicao', 'termo', 'grupo', 'relacionado', 'origem'], array_keys($r->json('termos.0')));
        $this->assertSame(['Casa', 'Cadeiras', 'Cadeiras Gamer'], $r->json('categoria.caminho'));
        // A pista de relacionado vem em todo termo (o filtro é da tela, e começa desligado).
        $this->assertSame([true], collect($r->json('termos'))->map(fn ($t) => is_bool($t['relacionado']))->unique()->values()->all());

        $json = $r->getContent();
        $this->assertDoesNotMatchRegularExpression('/mercado|MLB|an[uú]ncio|Publicador|"url"/iu', $json);
    }

    public function test_termos_fora_do_ar_responde_200_indisponivel_com_o_limite(): void
    {
        $this->termosFora = true;
        $empresa = $this->empresaDoGabarito();

        $r = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.produtos.termos_titulo', ['categoria' => self::CATEGORIA]))
            ->assertOk();

        $this->assertTrue($r->json('indisponivel'));
        $this->assertSame(60, $r->json('limite'));
        $this->assertNull($r->json('termos'));
        $this->assertDoesNotMatchRegularExpression('/mercado|MLB|trends|HTTP/iu', $r->getContent());
    }

    public function test_categoria_em_formato_invalido_e_recusada(): void
    {
        $this->entrarNoPortal($this->empresaDoGabarito())
            ->getJson(route('portal.auth.estrutura.produtos.termos_titulo', ['categoria' => '../x']))
            ->assertStatus(422);
    }

    // ─── Planilha ───────────────────────────────────────────────────────────

    private function planilha(array $linhas): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'titulo').'.xlsx';
        IOFactory::createWriter(ModeloProdutosXlsx::gerar([], $linhas), 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    public function test_planilha_tem_colunas_separadas_para_nome_e_titulo(): void
    {
        $nome = array_search('Nome do produto*', ModeloProdutosXlsx::CABECALHOS, true);
        $titulo = array_search('Título', ModeloProdutosXlsx::CABECALHOS, true);
        $this->assertIsInt($nome);
        $this->assertSame($nome + 1, $titulo);
        $this->assertSame('titulo', ModeloProdutosXlsx::CAMPOS[$titulo]);
    }

    public function test_planilha_com_titulo_e_categoria_vazios_importa_e_o_produto_fica_pendente(): void
    {
        $empresa = $this->empresaDoGabarito();
        $arquivo = $this->planilha([
            ['codigo' => 'P-1', 'grupo' => 'P', 'nome' => 'Poltrona Opala', 'titulo' => '', 'categoria' => '', 'volumes_texto' => '80×70×60 · 15'],
            ['codigo' => 'C-1', 'grupo' => 'C', 'nome' => 'Cadeira Gamer', 'titulo' => 'cadeira gamer reclinável', 'categoria' => '', 'volumes_texto' => '70×60×40 · 18'],
        ]);

        $res = app(ImportadorProdutos::class)->aplicar($empresa, $arquivo, $this->atorCliente($empresa));

        $this->assertSame(2, $res['novos']);
        $this->assertSame(0, $res['erros']);
        $poltrona = EstruturaProduto::where('company_id', $empresa->id)->where('codigo', 'P')->firstOrFail();
        $this->assertNull($poltrona->titulo);
        $this->assertSame('Cadeira Gamer Reclinável', EstruturaProduto::where('company_id', $empresa->id)->where('codigo', 'C')->value('titulo'));

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos.ficha', $poltrona->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('linhas.0.pendencias', fn ($p) => in_array(PendenciasDoProduto::TITULO, (array) collect($p)->all(), true)
                    && in_array(PendenciasDoProduto::CATEGORIA, (array) collect($p)->all(), true)));
    }

    public function test_planilha_baixada_antes_do_titulo_continua_entrando(): void
    {
        $empresa = $this->empresaDoGabarito();
        // O modelo de antes: sem a coluna Título (e com a linha de exemplo que o cliente não apagou).
        $semTitulo = array_values(array_filter(ModeloProdutosXlsx::CABECALHOS, fn ($c) => $c !== 'Título'));
        $campos = array_values(array_filter(ModeloProdutosXlsx::CAMPOS, fn ($c) => $c !== 'titulo'));
        $exemplo = array_diff_key(ModeloProdutosXlsx::EXEMPLOS[0], ['titulo' => true]);
        $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $folha = $planilha->getActiveSheet()->setTitle('Produtos');
        $folha->fromArray([$semTitulo, array_map(fn ($c) => $exemplo[$c] ?? null, $campos),
            array_map(fn ($c) => ['codigo' => 'M-1', 'grupo' => 'M', 'nome' => 'Mesa'][$c] ?? null, $campos)], null, 'A1', true);
        $caminho = tempnam(sys_get_temp_dir(), 'antigo').'.xlsx';
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        $res = app(ImportadorProdutos::class)->aplicar($empresa, $caminho, $this->atorCliente($empresa));

        $this->assertSame(1, $res['novos'], 'só a Mesa: a linha de exemplo antiga, sem título, continua ignorada');
        $this->assertNull(EstruturaProduto::where('company_id', $empresa->id)->value('titulo'));
    }
}
