<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarExplicacoesDeAtributosJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ModeloCompleto;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PlanilhaDaTrilha;
use App\Services\Trilha\VersaoDoCadastro;
use App\Support\Portal\Estrutura\RegrasDoCadastro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A PLANILHA POR CATEGORIA da trilha (3ª entrega, F7, desenho §17 — fechado com o usuário em 03/11/2026).
 *
 * - **Baixar**: uma aba por categoria, as QUATRO colunas de variação (cor E tamanho), a marca própria, nenhuma IA ao
 *   baixar (`GerarExplicacoesDeAtributosJob` nunca enfileirado), nada que cite "Mercado", "Portal" ou "/portal", e a
 *   categoria que não é folha, não é anunciável ou não cabe nas colunas do leitor → 422 `errors.categorias` (JSON).
 * - **Prévia** = o gravar desfeito: as tabelas `estrutura_*` e o `activity_log` iguais antes e depois, nenhum Job, e o
 *   resultado de cada produto (novo, atualizado, recusado — Ref repetida, cores inválidas, travado, outra categoria).
 * - **Gravar**: 2 cores × 3 tamanhos pelo `eixo_2`, branco não apaga, a Ref é a chave (nunca trocada), obrigatório vazio
 *   grava (vira falta), campo da ficha inválido é aviso, o produto ruim não derruba os outros, o novo sem foto NÃO vai
 *   ao Publicador e o que já está lá vai UMA vez, um evento `passo: planilha` por produto, a versão velha recusa só o
 *   produto que mudaria.
 * - **Limites**: 413 → 422 `errors.arquivo`, não-xlsx, > 2 MB, outro modelo, > 500 linhas, dado fora da área lida.
 *
 * Nenhuma chamada real: o catálogo é um `Http::fake` por URL (`Http::preventStrayRequests`).
 */
class PlanilhaNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    /** Nada disso pode aparecer no arquivo (sigilo da ficha e "a trilha substitui o Portal"). */
    private const PROIBIDO = '/mercado|portal|publicador|an[uú]nci|\bmlb|\bml\b/iu';

    private const L1 = ModeloCompleto::PRIMEIRA_LINHA;

    private Company $empresa;

    private User $dev;

    /** @var list<string> */
    private array $temporarios = [];

    /** @var array<string, array{0: mixed, 1: int}> os termos mais buscados de mentira, por categoria */
    private array $trends = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Http::fake(fn (Request $r) => self::catalogo($r, $this->trends));

        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * O catálogo de mentira: MLB1 Camisetas (Marca e Material obrigatórios, Cor — que é o eixo —, Manga), MLB2 Calças,
     * MLB9 Roupas (não é folha), MLB5 Fantasias (folha que não aceita produto novo) e MLB7 (190 campos: não cabe).
     */
    public static function catalogo(Request $r, array $trends = []): \GuzzleHttp\Promise\PromiseInterface
    {
        $url = $r->url();
        if (preg_match('#/trends/MLB/(MLB\d+)$#', $url, $m)) {
            return isset($trends[$m[1]]) ? Http::response(...$trends[$m[1]]) : Http::response([], 404);
        }
        if (preg_match('#/categories/MLB\d+/(technical_specs/input|sale_terms)$#', $url)) {
            return Http::response([], 200);
        }
        if (preg_match('#/categories/(MLB\d+)/attributes$#', $url, $m)) {
            return Http::response(match ($m[1]) {
                'MLB1' => [
                    ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true], 'value_max_length' => 20],
                    ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                        'values' => [['id' => '101', 'name' => 'Algodão'], ['id' => '102', 'name' => 'Poliéster']]],
                    // A categoria deixa variar pela cor: no produto que varia por ela, a Cor sai da ficha.
                    ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'list', 'tags' => ['allow_variations' => true],
                        'values' => [['id' => '51', 'name' => 'Preto'], ['id' => '52', 'name' => 'Branco']]],
                    ['id' => 'SLEEVE_TYPE', 'name' => 'Tipo de manga', 'value_type' => 'list', 'tags' => [],
                        'values' => [['id' => '61', 'name' => 'Curta'], ['id' => '62', 'name' => 'Longa']]],
                ],
                'MLB2', 'MLB5' => [['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]]],
                'MLB7' => array_map(fn (int $i) => ['id' => "CAMPO_{$i}", 'name' => "Campo {$i}", 'value_type' => 'string', 'tags' => []], range(1, 190)),
                default => [],
            }, 200);
        }
        if (preg_match('#/categories/(MLB\d+)$#', $url, $m)) {
            $folha = fn (string $id, string $nome, array $extra = []) => Http::response(['id' => $id, 'name' => $nome, 'children_categories' => [],
                'path_from_root' => [['id' => 'MLB9', 'name' => 'Roupas'], ['id' => $id, 'name' => $nome]]] + $extra);

            return match ($m[1]) {
                'MLB1'  => $folha('MLB1', 'Camisetas'),
                'MLB2'  => $folha('MLB2', 'Calças'),
                'MLB5'  => $folha('MLB5', 'Fantasias', ['settings' => ['listing_allowed' => false]]),
                'MLB7'  => $folha('MLB7', 'Larga'),
                'MLB9'  => Http::response(['id' => 'MLB9', 'name' => 'Roupas', 'children_categories' => [['id' => 'MLB1', 'name' => 'Camisetas']],
                    'path_from_root' => [['id' => 'MLB9', 'name' => 'Roupas']]]),
                default => Http::response(['message' => 'erro'], 500),
            };
        }

        return Http::response([], 404);
    }

    // ═══ Baixar ═════════════════════════════════════════════════════════════

    public function test_baixar_traz_uma_aba_por_categoria_com_as_quatro_colunas_de_variacao_a_marca_propria_e_nenhuma_ia(): void
    {
        $resposta = $this->actingAs($this->dev)->get($this->url('/planilha/modelo?categorias[]=MLB1&categorias[]=MLB2'))->assertOk();
        // O nome do arquivo: só letras, números, ".", "_" e "-", terminando em .xlsx (a tela o usa como veio).
        $this->assertMatchesRegularExpression('/filename="?[A-Za-z0-9._-]+\.xlsx"?$/', (string) $resposta->headers->get('Content-Disposition'));
        $planilha = $this->abrir($resposta);

        $this->assertSame(['Camisetas', 'Calças', 'Instruções', 'Guia dos campos', 'Listas', 'Categorias'], $planilha->getSheetNames());
        $camisetas = $planilha->getSheetByName('Camisetas');
        $cabecalhos = $this->cabecalhos($camisetas);
        $doProduto = ModeloCompleto::cabecalhosDoProduto(ModeloCompleto::VOLUMES_NO_MODELO, false, true);
        $this->assertSame($doProduto, array_slice($cabecalhos, 0, count($doProduto)));
        // As quatro colunas da variação, juntas e nesta ordem (contrato da F4).
        $this->assertSame(['Tipo de variação', 'Variação', 'Tipo da 2ª variação', '2ª variação'], array_slice($cabecalhos, 2, 4));
        // A ficha da categoria depois do produto (a Cor sai como campo; quem varia por cor a tem ignorada na volta).
        $this->assertSame(['Marca', 'Material', 'Cor', 'Tipo de manga'], array_slice($cabecalhos, count($doProduto)));
        $this->assertSame('"Cor,Tamanho,Voltagem,Material,Sabor,Outro"',
            $camisetas->getDataValidation($this->coluna($camisetas, 'Tipo da 2ª variação').self::L1)->getFormula1());

        // A marca da trilha (o importador do Portal não a reconhece), e só os números do código na aba oculta.
        $mapa = $planilha->getSheetByName('Categorias');
        $this->assertSame([ModeloCompleto::MARCA_DA_TRILHA, 'Camisetas', '1'], [(string) $mapa->getCell('A1')->getValue(),
            (string) $mapa->getCell('A2')->getValue(), (string) $mapa->getCell('B2')->getValue()]);

        // Zero IA ao baixar: a ficha sai sem as explicações (o Portal enfileira a IA delas).
        Queue::assertNotPushed(GerarExplicacoesDeAtributosJob::class);
        Queue::assertNothingPushed();

        // Nenhuma célula, nota, aba ou validação cita a plataforma, o Portal ou outra tela.
        foreach ($planilha->getAllSheets() as $folha) {
            $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $folha->getTitle());
            foreach ($folha->toArray() as $linha) {
                foreach ($linha as $celula) {
                    $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, (string) $celula, "aba {$folha->getTitle()}");
                    $this->assertStringNotContainsString('/portal', (string) $celula);
                    $this->assertStringNotContainsString('Enviar fotos em lote', (string) $celula);
                    $this->assertStringNotContainsString('Baixar modelo', (string) $celula);
                }
            }
            foreach ($folha->getComments() as $nota) {
                $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $nota->getText()->getPlainText());
            }
            foreach ($folha->getDataValidationCollection() as $v) {
                $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $v->getPrompt().' '.$v->getError().' '.$v->getPromptTitle());
            }
        }
    }

    public function test_os_termos_do_titulo_que_citam_o_portal_ficam_fora_da_seta(): void
    {
        $this->trends['MLB1'] = [[['keyword' => 'camiseta basica'], ['keyword' => 'camiseta portal']], 200];

        $planilha = $this->abrir($this->actingAs($this->dev)->get($this->url('/planilha/modelo?categorias[]=MLB1'))->assertOk());

        $listas = $planilha->getSheetByName('Listas')->toArray();
        $termos = array_filter(array_merge(...$listas), fn ($c) => is_string($c) && str_starts_with($c, 'Camiseta'));
        $this->assertContains('Camiseta Basica', $termos);
        $this->assertNotContains('Camiseta Portal', $termos);
    }

    public function test_categoria_que_nao_serve_volta_422_em_json_com_o_motivo_e_nao_sai_arquivo(): void
    {
        $casos = [
            'MLB9' => 'ainda tem categorias dentro dela',
            'MLB5' => 'não aceita produtos novos',
            'MLB7' => 'campos demais para caber numa planilha',
        ];
        foreach ($casos as $id => $motivo) {
            // Sem pedir JSON (o download pode sair de um link): o 422 vem em JSON mesmo assim.
            $r = $this->actingAs($this->dev)->get($this->url("/planilha/modelo?categorias[]=MLB1&categorias[]={$id}"))
                ->assertStatus(422)->assertJsonCount(1, 'errors.categorias');
            $this->assertStringContainsString($motivo, $r->json('errors.categorias.0'), $id);
        }

        $this->actingAs($this->dev)->getJson($this->url('/planilha/modelo'))->assertStatus(422)
            ->assertJsonPath('errors.categorias.0', 'Escolha ao menos uma categoria.');
        // O item inválido também vem em `errors.categorias` (a tela mostra a 1ª mensagem dali).
        $this->actingAs($this->dev)->getJson($this->url('/planilha/modelo?categorias[]=xyz'))->assertStatus(422)
            ->assertJsonPath('errors.categorias.0', 'Categoria inválida. Escolha de novo.');
        $onze = implode('&', array_map(fn (int $i) => "categorias[]=MLB{$i}", range(1, 11)));
        $this->actingAs($this->dev)->getJson($this->url("/planilha/modelo?{$onze}"))->assertStatus(422)
            ->assertJsonPath('errors.categorias.0', 'Escolha no máximo 10 categorias por arquivo.');
        Queue::assertNothingPushed();
    }

    // ═══ Prévia ═════════════════════════════════════════════════════════════

    public function test_a_previa_e_o_gravar_desfeito_nada_grava_nada_registra_nada_enfileira(): void
    {
        $existente = $this->produtoCompleto($this->empresa, 'Camiseta Lisa', 'LISA', ['Azul', 'Verde'], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $versao = VersaoDoCadastro::de($this->empresa, $existente['produto']);
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        $this->camisetaEmSeisVariacoes($aba, self::L1);
        // O que já existe: a Ref de sempre (escrita em minúsculas) com custo novo, e uma cor nova.
        $this->linha($aba, self::L1 + 6, ['Produto (grupo)' => 'LISA', 'Ref' => 'lisa-1', 'Nome do produto' => 'Camiseta Lisa', 'Custo (R$)' => '150,00']);
        $this->linha($aba, self::L1 + 7, ['Produto (grupo)' => 'LISA', 'Ref' => 'LISA-3', 'Tipo de variação' => 'Cor', 'Variação' => 'Rosa', 'Nome do produto' => 'Camiseta Lisa']);
        $arquivo = $this->salvar($planilha);
        $antes = $this->contagens();
        $conteudoAntes = VersaoDoCadastro::conteudo($this->empresa, $existente['produto']);

        $r = $this->previa($arquivo)->assertOk()->json();

        $this->assertSame($antes, $this->contagens(), 'a prévia gravou ou registrou alguma coisa');
        $this->assertSame($conteudoAntes, VersaoDoCadastro::conteudo($this->empresa, $existente['produto']));
        Queue::assertNothingPushed();

        $this->assertFalse($r['gravado']);
        $this->assertSame([['categoria_id' => 'MLB1', 'categoria_nome' => 'Camisetas', 'linhas' => 8]], $r['abas']);
        $this->assertSame(['novos' => 1, 'atualizados' => 1, 'sem_mudanca' => 0, 'recusados' => 0], $r['totais']);
        $this->assertSame('1 produto novo, 1 atualizado, nenhum recusado.', $r['mensagem']);

        [$novo, $atualizado] = $r['produtos'];
        $this->assertSame(['chave' => 'CAM-01', 'aba' => 'MLB1', 'linhas' => range(self::L1, self::L1 + 5), 'produto_id' => null, 'nome' => 'Camiseta básica',
            'codigo' => 'CAM-01-PT-P', 'situacao' => 'novo', 'variacoes' => ['novas' => 6, 'atualizadas' => 0], 'erros' => [], 'avisos' => [],
            'faltas' => ['família', 'ambiente', 'fotos (0 de 3)'], 'versao' => null], $novo);
        $this->assertSame([$existente['produto']->id, 'atualizado', ['novas' => 1, 'atualizadas' => 1], $versao, []],
            [$atualizado['produto_id'], $atualizado['situacao'], $atualizado['variacoes'], $atualizado['versao'], $atualizado['erros']]);
    }

    public function test_a_previa_recusa_o_produto_inteiro_e_diz_a_linha_e_a_coluna(): void
    {
        $travado = $this->produtoCompleto($this->empresa, 'Camiseta Travada', 'TRAV', [null], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        EstruturaAnuncio::create(['oferta_id' => $travado['simples'][0]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Camiseta']);
        $outra = $this->produtoCompleto($this->empresa, 'Calça Jeans', 'JEANS', [null], ['categoria_ml_id' => 'MLB2', 'categoria_ml_nome' => 'Calças']);
        // D12: já no Publicador e ainda sem categoria — a da aba mudaria a dele.
        $semCategoria = $this->produtoCompleto($this->empresa, 'Camiseta Sem', 'SEM', [null], ['categoria_ml_id' => null, 'categoria_ml_nome' => null]);
        $this->grupoNoPublicador($this->empresa, $semCategoria);
        $this->produtoCompleto($this->empresa, 'Camiseta Alfa', 'ALFA', [null], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $this->produtoCompleto($this->empresa, 'Camiseta Beta', 'BETA', [null], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        $l = self::L1;
        // Ref repetida no mesmo produto.
        $this->linha($aba, $l, ['Produto (grupo)' => 'REP', 'Ref' => 'REP-1', 'Nome do produto' => 'Repetida', 'Tipo de variação' => 'Cor', 'Variação' => 'Preto']);
        $this->linha($aba, $l + 1, ['Produto (grupo)' => 'REP', 'Ref' => 'rep-1', 'Nome do produto' => 'Repetida', 'Tipo de variação' => 'Cor', 'Variação' => 'Branco']);
        // Cores inválidas: a mesma cor duas vezes (o Publicador separaria em silêncio).
        $this->linha($aba, $l + 2, ['Produto (grupo)' => 'COR', 'Ref' => 'COR-1', 'Nome do produto' => 'Cores', 'Tipo de variação' => 'Cor', 'Variação' => 'Preto']);
        $this->linha($aba, $l + 3, ['Produto (grupo)' => 'COR', 'Ref' => 'COR-2', 'Nome do produto' => 'Cores', 'Tipo de variação' => 'Cor', 'Variação' => 'preto']);
        // O travado (o anúncio no ar) e o que está em outra categoria.
        $this->linha($aba, $l + 4, ['Produto (grupo)' => 'TRAV', 'Ref' => 'TRAV', 'Nome do produto' => 'Camiseta Travada', 'Custo (R$)' => '1,00']);
        $this->linha($aba, $l + 5, ['Produto (grupo)' => 'JEANS', 'Ref' => 'JEANS', 'Nome do produto' => 'Calça Jeans', 'Custo (R$)' => '1,00']);
        // Um produto bom, para provar que os outros seguem.
        $this->linha($aba, $l + 6, ['Produto (grupo)' => 'BOA', 'Ref' => 'BOA-1', 'Nome do produto' => 'Camiseta Boa', 'Tipo de variação' => 'Cor', 'Variação' => 'Preto']);
        $this->linha($aba, $l + 7, ['Produto (grupo)' => 'SEM', 'Ref' => 'SEM', 'Custo (R$)' => '1,00']);
        // Um grupo com Refs de dois produtos que já existem, e o mesmo produto em dois grupos.
        $this->linha($aba, $l + 8, ['Produto (grupo)' => 'MIX', 'Ref' => 'ALFA', 'Custo (R$)' => '1,00']);
        $this->linha($aba, $l + 9, ['Produto (grupo)' => 'MIX', 'Ref' => 'BETA', 'Custo (R$)' => '1,00']);
        $this->linha($aba, $l + 10, ['Produto (grupo)' => 'BOA', 'Ref' => 'BOA-2', 'Nome do produto' => 'Camiseta Boa', 'Tipo de variação' => 'Cor', 'Variação' => 'Azul']);
        $antes = $this->contagens();

        $r = $this->previa($this->salvar($planilha))->assertOk()->json();

        $this->assertSame($antes, $this->contagens());
        $porChave = array_column($r['produtos'], null, 'chave');
        $this->assertSame([['linha' => $l + 1, 'coluna' => 'Ref', 'mensagem' => sprintf(PlanilhaDaTrilha::MSG_REF_REPETIDA, 'rep-1', $l)]], $porChave['REP']['erros']);
        $this->assertSame('recusado', $porChave['COR']['situacao']);
        $this->assertSame([$l + 3, 'Variação'], [$porChave['COR']['erros'][0]['linha'], $porChave['COR']['erros'][0]['coluna']]);
        $this->assertStringContainsString('já existe neste produto', $porChave['COR']['erros'][0]['mensagem']);
        $this->assertSame([['linha' => $l + 4, 'coluna' => null, 'mensagem' => app(CadastroNaTrilha::class)->travado($this->empresa, $travado['produto'])]], $porChave['TRAV']['erros']);
        $this->assertSame([['linha' => $l + 5, 'coluna' => null, 'mensagem' => sprintf(PlanilhaDaTrilha::MSG_OUTRA_CATEGORIA, 'Calças')]], $porChave['JEANS']['erros']);
        $this->assertSame([['linha' => $l + 7, 'coluna' => null, 'mensagem' => CadastroNaTrilha::MSG_CATEGORIA_NO_PUBLICADOR]], $porChave['SEM']['erros']);
        $this->assertSame([['linha' => $l + 8, 'coluna' => null, 'mensagem' => sprintf(PlanilhaDaTrilha::MSG_PRODUTOS_DIFERENTES, 'ALFA, BETA')]], $porChave['MIX']['erros']);
        // O grupo BOA tem duas linhas longe uma da outra: um produto só, com as duas.
        $this->assertSame(['novo', [$l + 6, $l + 10]], [$porChave['BOA']['situacao'], $porChave['BOA']['linhas']]);
        $this->assertSame(['novos' => 1, 'atualizados' => 0, 'sem_mudanca' => 0, 'recusados' => 6], $r['totais']);
        foreach ($r['produtos'] as $p) {
            // `erros` não vazio ⇔ recusado.
            $this->assertSame($p['erros'] !== [], $p['situacao'] === 'recusado', $p['chave']);
        }
        $this->assertSame(100.0, $outra['variacoes'][0]->fresh()->custo);
        Queue::assertNothingPushed();
    }

    // ═══ Gravar ═════════════════════════════════════════════════════════════

    public function test_gravar_cria_duas_cores_por_tres_tamanhos_pelo_segundo_eixo_com_a_ficha_e_um_evento(): void
    {
        $planilha = $this->modelo(['MLB1']);
        $this->camisetaEmSeisVariacoes($planilha->getSheetByName('Camisetas'), self::L1);

        $r = $this->gravar($this->salvar($planilha))->assertOk()->json();

        $this->assertTrue($r['gravado']);
        $this->assertSame('Gravado: 1 produto novo, nenhum recusado.', $r['mensagem']);
        $produto = EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole();
        // O id novo vem na resposta (a tela abre o produto por ele).
        $this->assertSame($produto->id, $r['produtos'][0]['produto_id']);
        $this->assertSame(VersaoDoCadastro::de($this->empresa, $produto), $r['produtos'][0]['versao']);
        $this->assertSame(['Camiseta básica', 'CAM-01-PT-P', 'MLB1', 'Camisetas', 'Camiseta Básica Algodão', 'Uma camiseta lisa.'],
            [$produto->nome, $produto->codigo, $produto->categoria_ml_id, $produto->categoria_ml_nome, $produto->titulo, $produto->descricao]);
        $variacoes = $produto->variacoes()->orderBy('ordem')->get();
        $this->assertSame([
            ['CAM-01-PT-P', 'cor', 'Preto', 'tamanho', 'P'], ['CAM-01-PT-M', 'cor', 'Preto', 'tamanho', 'M'], ['CAM-01-PT-G', 'cor', 'Preto', 'tamanho', 'G'],
            ['CAM-01-BR-P', 'cor', 'Branco', 'tamanho', 'P'], ['CAM-01-BR-M', 'cor', 'Branco', 'tamanho', 'M'], ['CAM-01-BR-G', 'cor', 'Branco', 'tamanho', 'G'],
        ], $variacoes->map(fn ($v) => [$v->codigo, $v->eixo, $v->valor, $v->eixo_2, $v->valor_2])->all());
        $this->assertSame([39.9, 39.9, 42.5, 39.9, 39.9, 42.5], $variacoes->pluck('custo')->all());
        // As caixas da linha (o volume completo) e cada variação com a sua oferta simples.
        $this->assertSame(1, $variacoes[0]->volumes()->count());
        $this->assertSame(6, DB::table('estrutura_ofertas')->where('company_id', $this->empresa->id)->whereNotNull('variacao_id')->count());
        // A ficha da 1ª linha; a Cor (o eixo do produto) não entra na ficha.
        $this->assertSame(['BRAND' => 'Bela Malha', 'MATERIAL' => '101', 'SLEEVE_TYPE' => '61'],
            EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->orderBy('atributo_id')->get()
                ->mapWithKeys(fn ($a) => [$a->atributo_id => $a->valor_id ?? $a->valor])->all());
        // UM evento da trilha, com o passo da planilha.
        $eventos = $this->eventosDaTrilha();
        $this->assertCount(1, $eventos);
        $this->assertSame(CadastroNaTrilha::PASSO_PLANILHA, $eventos->first()->getExtraProperty('passo'));
        // Sem fotos o produto não completa: nada vai ao Publicador.
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
        Queue::assertNotPushed(SincronizarProdutoDoPortalJob::class);
    }

    public function test_branco_nao_apaga_a_ref_e_a_chave_e_ref_nova_vira_variacao_nova(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Camiseta Lisa', 'LISA', ['Azul', 'Verde'], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $tituloDeAntes = $p['produto']->titulo;
        EstruturaProdutoAtributo::create(['company_id' => $this->empresa->id, 'produto_id' => $p['produto']->id, 'atributo_id' => 'BRAND',
            'atributo_nome' => 'Marca', 'valor' => 'Marca Antiga']);
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        // Só o custo da Azul (o resto em branco), a Ref escrita em minúsculas, e uma Rosa nova.
        $this->linha($aba, self::L1, ['Produto (grupo)' => 'LISA', 'Ref' => 'lisa-1', 'Custo (R$)' => '150,00', 'Material' => 'Poliéster']);
        $this->linha($aba, self::L1 + 1, ['Produto (grupo)' => 'LISA', 'Ref' => 'LISA-3', 'Tipo de variação' => 'Cor', 'Variação' => 'Rosa', 'Nome do produto' => 'Camiseta Lisa']);

        $r = $this->gravar($this->salvar($planilha))->assertOk()->json();

        $this->assertSame(['atualizado', ['novas' => 1, 'atualizadas' => 1]], [$r['produtos'][0]['situacao'], $r['produtos'][0]['variacoes']]);
        $produto = $p['produto']->fresh();
        $this->assertSame(['Camiseta Lisa', $tituloDeAntes, 'Uma descrição do produto.', 'LISA'],
            [$produto->nome, $produto->titulo, $produto->descricao, $produto->codigo], 'branco não apaga');
        [$azul, $verde, $rosa] = $produto->variacoes()->orderBy('ordem')->get()->all();
        $this->assertSame(['LISA-1', 150.0, 'Azul'], [$azul->codigo, $azul->custo, $azul->valor], 'a Ref não muda de caixa: é a chave');
        $this->assertSame(['LISA-2', 110.0, 'Verde'], [$verde->codigo, $verde->custo, $verde->valor], 'nada é excluído nem mexido');
        $this->assertSame(['LISA-3', 'cor', 'Rosa'], [$rosa->codigo, $rosa->eixo, $rosa->valor]);
        // A ficha mescla: a Marca de antes fica, o Material entra.
        $this->assertSame(['Marca Antiga', '102'], [
            EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->where('atributo_id', 'BRAND')->value('valor'),
            EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->where('atributo_id', 'MATERIAL')->value('valor_id'),
        ]);
    }

    public function test_obrigatorio_vazio_grava_e_vira_falta_e_o_campo_invalido_da_ficha_e_aviso(): void
    {
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        // Sem a Marca (obrigatória) e com um Material fora das opções.
        $this->linha($aba, self::L1, ['Produto (grupo)' => 'SIMPLES', 'Ref' => 'SIMPLES-1', 'Nome do produto' => 'Camiseta Simples',
            'Material' => 'Plástico', 'Tipo de manga' => 'Longa']);

        $r = $this->gravar($this->salvar($planilha))->assertOk()->json('produtos.0');

        $this->assertSame('novo', $r['situacao']);
        $this->assertSame([], $r['erros']);
        $this->assertSame([self::L1, 'Material'], [$r['avisos'][0]['linha'], $r['avisos'][0]['coluna']]);
        $this->assertStringContainsString('ficha: marca', implode(' | ', $r['faltas']));
        $produto = EstruturaProduto::query()->findOrFail($r['produto_id']);
        $this->assertSame(['SLEEVE_TYPE'], EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->pluck('atributo_id')->all(),
            'só o campo inválido ficou de fora');
    }

    public function test_o_produto_ruim_fica_inteiro_de_fora_e_nao_derruba_os_outros(): void
    {
        $planilha = $this->modelo(['MLB1', 'MLB2']);
        $aba = $planilha->getSheetByName('Camisetas');
        $this->linha($aba, self::L1, ['Produto (grupo)' => 'BOA', 'Ref' => 'BOA-1', 'Nome do produto' => 'Camiseta Boa', 'Marca' => 'Bela']);
        // Ruim: a 2ª linha com custo ilegível — nada dele grava, nem a linha boa, nem a ficha.
        $this->linha($aba, self::L1 + 1, ['Produto (grupo)' => 'RUIM', 'Ref' => 'RUIM-1', 'Nome do produto' => 'Camiseta Ruim', 'Tipo de variação' => 'Cor',
            'Variação' => 'Preto', 'Marca' => 'Ruim']);
        $this->linha($aba, self::L1 + 2, ['Produto (grupo)' => 'RUIM', 'Ref' => 'RUIM-2', 'Nome do produto' => 'Camiseta Ruim', 'Tipo de variação' => 'Cor',
            'Variação' => 'Branco', 'Custo (R$)' => 'muito caro']);
        $this->linha($planilha->getSheetByName('Calças'), self::L1, ['Produto (grupo)' => 'CALCA', 'Ref' => 'CALCA-1', 'Nome do produto' => 'Calça Boa']);

        $r = $this->gravar($this->salvar($planilha))->assertOk()->json();

        $this->assertSame(['novos' => 2, 'atualizados' => 0, 'sem_mudanca' => 0, 'recusados' => 1], $r['totais']);
        $ruim = array_column($r['produtos'], null, 'chave')['RUIM'];
        $this->assertSame([self::L1 + 2, 'Custo (R$)'], [$ruim['erros'][0]['linha'], $ruim['erros'][0]['coluna']]);
        $this->assertNull($ruim['produto_id']);
        $this->assertSame(['Calça Boa', 'Camiseta Boa'], EstruturaProduto::query()->orderBy('nome')->pluck('nome')->all());
        $this->assertSame(0, EstruturaProdutoVariacao::query()->where('codigo', 'like', 'RUIM%')->count());
        $this->assertSame(0, EstruturaProdutoAtributo::query()->where('valor', 'Ruim')->count());
        $this->assertSame(['MLB1', 'MLB2'], EstruturaProduto::query()->orderBy('nome', 'desc')->pluck('categoria_ml_id')->all());
        $this->assertCount(2, $this->eventosDaTrilha());
    }

    public function test_o_novo_sem_foto_nao_vai_ao_publicador_e_o_que_ja_esta_la_vai_uma_vez(): void
    {
        $noPublicador = $this->produtoCompleto($this->empresa, 'Camiseta Lisa', 'LISA', ['Azul', 'Verde'], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $noPublicador));
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        $this->linha($aba, self::L1, ['Produto (grupo)' => 'LISA', 'Ref' => 'LISA-1', 'Custo (R$)' => '150,00']);
        $this->linha($aba, self::L1 + 1, ['Produto (grupo)' => 'LISA', 'Ref' => 'LISA-2', 'Custo (R$)' => '160,00']);
        $this->linha($aba, self::L1 + 2, ['Produto (grupo)' => 'NOVA', 'Ref' => 'NOVA-1', 'Nome do produto' => 'Camiseta Nova', 'Marca' => 'X']);

        $this->gravar($this->salvar($planilha))->assertOk()->assertJsonPath('totais.novos', 1)->assertJsonPath('totais.atualizados', 1);

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $noPublicador['produto']->id);
        Queue::assertPushed(SincronizarProdutoDoPortalJob::class, 1);
    }

    public function test_a_versao_velha_recusa_so_o_produto_que_mudaria_e_a_do_sem_mudanca_nao_tem_efeito(): void
    {
        $muda = $this->produtoCompleto($this->empresa, 'Camiseta Muda', 'MUDA', [null], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $fica = $this->produtoCompleto($this->empresa, 'Camiseta Fica', 'FICA', [null], ['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Camisetas']);
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        $this->linha($aba, self::L1, ['Produto (grupo)' => 'MUDA', 'Ref' => 'MUDA', 'Custo (R$)' => '150,00']);
        $this->linha($aba, self::L1 + 1, ['Produto (grupo)' => 'FICA', 'Ref' => 'FICA', 'Custo (R$)' => '100,00']);
        $this->linha($aba, self::L1 + 2, ['Produto (grupo)' => 'OUTRA', 'Ref' => 'OUTRA-1', 'Nome do produto' => 'Camiseta Outra']);
        $arquivo = $this->salvar($planilha);

        // A versão da prévia, e depois outra aba muda os dois produtos.
        $previa = $this->previa($arquivo)->assertOk()->json('produtos');
        $versoes = [];
        foreach ($previa as $p) {
            if ($p['produto_id'] !== null) {
                $versoes[$p['produto_id']] = $p['versao'];
            }
        }
        $muda['produto']->update(['descricao' => 'Mudou em outra aba.']);
        $fica['produto']->update(['descricao' => 'Mudou em outra aba também.']);
        // Lixo a tela não manda, mas o servidor aceita sem efeito.
        $versoes['abc'] = 'x';
        $versoes[999999] = 'y';

        $r = $this->gravar($arquivo, $versoes)->assertOk()->json();

        $porChave = array_column($r['produtos'], null, 'chave');
        $this->assertSame([['linha' => self::L1, 'coluna' => null, 'mensagem' => CadastroNaTrilha::MSG_VERSAO]], $porChave['MUDA']['erros']);
        $this->assertSame(100.0, $muda['variacoes'][0]->fresh()->custo, 'nada do recusado gravou');
        // O custo do FICA já era 100: nada a escrever, a versão velha não importa.
        $this->assertSame(['sem_mudanca', []], [$porChave['FICA']['situacao'], $porChave['FICA']['erros']]);
        $this->assertSame('novo', $porChave['OUTRA']['situacao']);
    }

    // ═══ Limites ════════════════════════════════════════════════════════════

    public function test_o_envio_grande_demais_vira_422_errors_arquivo_nas_duas_rotas(): void
    {
        foreach (['/planilha/previa', '/planilha'] as $caminho) {
            $this->actingAs($this->dev)->call('POST', $this->url($caminho), [], [], [], [
                'CONTENT_LENGTH' => 50 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            ])->assertStatus(422)->assertJsonPath('errors.arquivo.0', RegrasDoCadastro::MSG_PLANILHA_GRANDE);

            // O arquivo que passou do `upload_max_filesize` chega marcado inválido: a mesma frase.
            $tmp = tempnam(sys_get_temp_dir(), 'pl');
            $this->temporarios[] = $tmp;
            $this->actingAs($this->dev)->post($this->url($caminho), ['arquivo' => new UploadedFile($tmp, 'grande.xlsx', null, UPLOAD_ERR_INI_SIZE, true)])
                ->assertStatus(422)->assertJsonPath('errors.arquivo.0', RegrasDoCadastro::MSG_PLANILHA_GRANDE);
        }
        $this->assertSame(0, EstruturaProduto::query()->count());
    }

    public function test_o_arquivo_que_nao_serve_volta_422_errors_arquivo_sem_gravar_nada(): void
    {
        // Não é .xlsx (pelo conteúdo e pelo nome).
        $this->previa(UploadedFile::fake()->createWithContent('produtos.csv', "a;b\n1;2"))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', 'Envie a planilha no formato .xlsx.');
        $boa = $this->salvar($this->modelo(['MLB1']));
        $this->previa(new UploadedFile($boa->getRealPath(), 'planilha.zip', null, null, true))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', 'Envie a planilha no formato .xlsx.');
        // Mais de 2 MB.
        $this->previa(UploadedFile::fake()->create('grande.xlsx', 3000))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', RegrasDoCadastro::MSG_PLANILHA_GRANDE);
        // O modelo completo do Portal (outra marca) e uma planilha qualquer. O do Portal, ao montar, enfileira a IA das
        // explicações — é o que o perfil da trilha evita (o teste de baixar prova que ela não sai).
        $doPortal = app(ModeloCompleto::class)->gerar(['MLB1'])['planilha'];
        Queue::assertPushed(GerarExplicacoesDeAtributosJob::class);
        $this->linha($doPortal->getSheetByName('Camisetas'), self::L1, ['Produto (grupo)' => 'X', 'Ref' => 'X-1', 'Nome do produto' => 'X']);
        $this->previa($this->salvar($doPortal))->assertStatus(422)->assertJsonPath('errors.arquivo.0', PlanilhaDaTrilha::MSG_OUTRO_MODELO);
        $qualquer = new Spreadsheet();
        $qualquer->getActiveSheet()->setCellValue('A1', 'Nome')->setCellValue('A2', 'Algo');
        $this->previa($this->salvar($qualquer))->assertStatus(422)->assertJsonPath('errors.arquivo.0', PlanilhaDaTrilha::MSG_NAO_RECONHECIDO);
        // A planilha da trilha sem produto nenhum.
        $this->previa($this->salvar($this->modelo(['MLB1'])))->assertStatus(422)->assertJsonPath('errors.arquivo.0', PlanilhaDaTrilha::MSG_VAZIA);
        // A aba de categoria renomeada: o produto dela se perderia — o arquivo volta.
        $renomeada = $this->modelo(['MLB1']);
        $renomeada->getSheetByName('Camisetas')->setTitle('Minhas camisetas');
        $this->linha($renomeada->getSheetByName('Minhas camisetas'), self::L1, ['Produto (grupo)' => 'X', 'Ref' => 'X-1', 'Nome do produto' => 'X']);
        $this->previa($this->salvar($renomeada))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', sprintf(PlanilhaDaTrilha::MSG_ABA_ESTRANHA, 'Minhas camisetas'));

        $this->assertSame(0, EstruturaProduto::query()->count());
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
        Queue::assertNotPushed(SincronizarProdutoDoPortalJob::class);
    }

    public function test_mais_de_500_linhas_ou_dado_fora_da_area_lida_recusa_o_arquivo_nunca_corta_em_silencio(): void
    {
        $planilha = $this->modelo(['MLB1']);
        $aba = $planilha->getSheetByName('Camisetas');
        for ($i = 0; $i < PlanilhaDaTrilha::MAX_LINHAS + 1; $i++) {
            $this->linha($aba, self::L1 + $i, ['Produto (grupo)' => "P{$i}", 'Ref' => "R{$i}", 'Nome do produto' => "Produto {$i}"]);
        }
        $this->previa($this->salvar($planilha))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', sprintf(PlanilhaDaTrilha::MSG_LINHAS, 501, 500));

        // Uma linha só, mas abaixo da linha que o leitor lê (1.001): seria cortada sem aviso.
        $longe = $this->modelo(['MLB1']);
        $this->linha($longe->getSheetByName('Camisetas'), self::L1, ['Produto (grupo)' => 'A', 'Ref' => 'A-1', 'Nome do produto' => 'Perto']);
        $longe->getSheetByName('Camisetas')->setCellValueExplicit('B1500', 'LONGE-1', DataType::TYPE_STRING);
        $this->previa($this->salvar($longe))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', sprintf(PlanilhaDaTrilha::MSG_ALEM_DO_CORTE, 'Camisetas', 1001, 200));
        // E depois da coluna 200.
        $larga = $this->modelo(['MLB1']);
        $this->linha($larga->getSheetByName('Camisetas'), self::L1, ['Produto (grupo)' => 'A', 'Ref' => 'A-1', 'Nome do produto' => 'Perto']);
        $larga->getSheetByName('Camisetas')->setCellValueExplicit('GZ'.self::L1, 'longe', DataType::TYPE_STRING);
        $this->previa($this->salvar($larga))->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', sprintf(PlanilhaDaTrilha::MSG_ALEM_DO_CORTE, 'Camisetas', 1001, 200));

        $this->assertSame(0, EstruturaProduto::query()->count());
    }

    // ─── apoio ───

    private function url(string $caminho): string
    {
        return "/trilha/{$this->empresa->id}{$caminho}";
    }

    /** O modelo das categorias pela rota (o mesmo arquivo que a tela baixa), aberto para preencher. */
    private function modelo(array $categorias): Spreadsheet
    {
        $busca = implode('&', array_map(fn (string $c) => "categorias[]={$c}", $categorias));

        return $this->abrir($this->actingAs($this->dev)->get($this->url("/planilha/modelo?{$busca}"))->assertOk());
    }

    private function abrir(TestResponse $resposta): Spreadsheet
    {
        $caminho = tempnam(sys_get_temp_dir(), 'pt').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $resposta->streamedContent());

        return IOFactory::load($caminho);
    }

    private function salvar(Spreadsheet $planilha): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'pt').'.xlsx';
        $this->temporarios[] = $caminho;
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);

        return new UploadedFile($caminho, 'planilha.xlsx', null, null, true);
    }

    private function previa(UploadedFile $arquivo): TestResponse
    {
        return $this->actingAs($this->dev)->post($this->url('/planilha/previa'), ['arquivo' => $arquivo]);
    }

    private function gravar(UploadedFile $arquivo, array $versoes = []): TestResponse
    {
        return $this->actingAs($this->dev)->post($this->url('/planilha'), ['arquivo' => $arquivo, 'versoes' => $versoes]);
    }

    /** @return list<string> */
    private function cabecalhos(Worksheet $aba): array
    {
        $saida = [];
        for ($c = 1; $c <= 60; $c++) {
            $titulo = (string) $aba->getCell([$c, ModeloCompleto::LINHA_DO_CABECALHO])->getValue();
            if ($titulo !== '') {
                $saida[] = $titulo;
            }
        }

        return $saida;
    }

    private function coluna(Worksheet $aba, string $cabecalho): string
    {
        for ($c = 1; $c <= 80; $c++) {
            if ((string) $aba->getCell([$c, ModeloCompleto::LINHA_DO_CABECALHO])->getValue() === $cabecalho) {
                return Coordinate::stringFromColumnIndex($c);
            }
        }
        $this->fail("A aba {$aba->getTitle()} não tem a coluna {$cabecalho}");
    }

    private function linha(Worksheet $aba, int $numero, array $valores): void
    {
        foreach ($valores as $cabecalho => $valor) {
            $celula = $this->coluna($aba, $cabecalho).$numero;
            is_string($valor) ? $aba->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING) : $aba->setCellValue($celula, $valor);
        }
    }

    /** A camiseta em 2 cores × 3 tamanhos: a ficha e o produto na 1ª linha; o G custa mais. */
    private function camisetaEmSeisVariacoes(Worksheet $aba, int $de): void
    {
        $n = 0;
        foreach (['PT' => 'Preto', 'BR' => 'Branco'] as $sigla => $cor) {
            foreach (['P', 'M', 'G'] as $tamanho) {
                $this->linha($aba, $de + $n, [
                    'Produto (grupo)' => 'CAM-01', 'Ref' => "CAM-01-{$sigla}-{$tamanho}", 'Nome do produto' => 'Camiseta básica',
                    'Tipo de variação' => 'Cor', 'Variação' => $cor, 'Tipo da 2ª variação' => 'Tamanho', '2ª variação' => $tamanho,
                    'Custo (R$)' => $tamanho === 'G' ? 42.5 : '39,90', 'Estoque (un.)' => 10,
                    'Volume 1: comprimento (cm)' => 30, 'Volume 1: largura (cm)' => 25, 'Volume 1: altura (cm)' => 3, 'Volume 1: peso (kg)' => 0.3,
                ] + ($n === 0 ? ['Título' => 'Camiseta básica algodão', 'Descrição' => 'Uma camiseta lisa.', 'Marca' => 'Bela Malha',
                    'Material' => 'algodão', 'Cor' => 'Preto', 'Tipo de manga' => 'Curta'] : []));
                $n++;
            }
        }
    }

    /** Linhas das tabelas que a prévia não pode tocar: todas as `estrutura_*`, o `activity_log` e a fila. */
    private function contagens(): array
    {
        $saida = [];
        foreach (array_column(Schema::getTables(), 'name') as $tabela) {
            if (str_starts_with($tabela, 'estrutura_') || in_array($tabela, ['activity_log', 'jobs', 'trilha_onda_itens', 'trilha_mapeamentos'], true)) {
                $saida[$tabela] = DB::table($tabela)->count();
            }
        }
        ksort($saida);

        return $saida;
    }

    private function eventosDaTrilha(): \Illuminate\Support\Collection
    {
        return Activity::query()->get()->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
    }
}
