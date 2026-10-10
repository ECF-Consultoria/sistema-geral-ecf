<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ImportadorProdutos;
use App\Services\Portal\Estrutura\Produtos\LeitorPlanilhaProdutos;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use App\Services\Portal\Estrutura\Produtos\PlanilhaDosProdutos;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Planilha de produtos v2 (09/10/2026): o modelo com Tipo de variação, Variação (nome), Estoque e
 * Descrição; a planilha baixada já preenchida; e a prévia com as categorias a confirmar em lote.
 *
 * Fixture SINTÉTICA, gerada aqui. O "formato da planilha do Planejamento" é só a FORMA medida
 * no arquivo real (cabeçalhos, Variação ordinal 1/2/única, categoria em texto, números do Excel
 * como double) — nenhum nome, Ref ou custo dele entra aqui.
 *
 * SIGILO: o cliente não pode perceber para onde vai o cadastro. Há varredura do arquivo
 * gerado (abas, células, listas, validações, propriedades e o XML cru) e do JSON da prévia.
 */
class PlanilhaDeProdutosV2Test extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** Termos que revelariam o destino do cadastro. `\bml\b` sem caixa: "ML" sozinho, nunca "xml". */
    private const PROIBIDO = '/mercado|an[uú]ncio|publica|\bmlb|\bml\b/iu';

    /** @var list<string> */
    private array $temporarios = [];

    private bool $catalogoFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_contains($url, '/domain_discovery/search')) {
                if ($this->catalogoFora) {
                    return Http::response(['message' => 'erro'], 500);
                }
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $texto = mb_strtolower((string) ($q['q'] ?? ''));

                return Http::response(match (true) {
                    str_contains($texto, 'mesa')    => [['category_id' => 'MLB10', 'category_name' => 'Mesas de Jantar', 'domain_name' => 'Mesas']],
                    str_contains($texto, 'cadeira') => [
                        ['category_id' => 'MLB20', 'category_name' => 'Cadeiras', 'domain_name' => 'Cadeiras'],
                        ['category_id' => 'MLB21', 'category_name' => 'Cadeiras de Jantar', 'domain_name' => 'Cadeiras'],
                    ],
                    default => [],
                });
            }
            if (preg_match('#/categories/(MLB\d+)$#', $url, $m)) {
                return match ($m[1]) {
                    'MLB10' => Http::response(['id' => 'MLB10', 'name' => 'Mesas de Jantar', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB1', 'name' => 'Casa'], ['id' => 'MLB10', 'name' => 'Mesas de Jantar']]]),
                    'MLB20' => Http::response(['id' => 'MLB20', 'name' => 'Cadeiras', 'children_categories' => [['id' => 'MLB21', 'name' => 'Cadeiras de Jantar']],
                        'path_from_root' => [['id' => 'MLB1', 'name' => 'Casa'], ['id' => 'MLB20', 'name' => 'Cadeiras']]]),
                    'MLB21' => Http::response(['id' => 'MLB21', 'name' => 'Cadeiras de Jantar', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB1', 'name' => 'Casa'], ['id' => 'MLB20', 'name' => 'Cadeiras'], ['id' => 'MLB21', 'name' => 'Cadeiras de Jantar']]]),
                    default => Http::response(['message' => 'erro'], 500),
                };
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
        parent::tearDown();
    }

    // ═══ Apoio ══════════════════════════════════════════════════════════════

    /**
     * .xlsx com as abas pedidas (nome => linhas). Texto vai como texto; int/float como número
     * (como o Excel guarda Nº volumes, peso e custo na planilha do Planejamento).
     *
     * @param  array<string, list<list<mixed>>>  $abas
     */
    private function xlsx(array $abas): string
    {
        $planilha = new Spreadsheet();
        $primeira = true;
        foreach ($abas as $titulo => $linhas) {
            $folha = $primeira ? $planilha->getActiveSheet() : $planilha->createSheet();
            $primeira = false;
            $folha->setTitle($titulo);
            foreach ($linhas as $r => $celulas) {
                foreach (array_values($celulas) as $c => $valor) {
                    if ($valor === null || $valor === '') {
                        continue;
                    }
                    $ref = Coordinate::stringFromColumnIndex($c + 1).($r + 1);
                    if (is_int($valor) || is_float($valor)) {
                        $folha->setCellValueExplicit($ref, $valor, DataType::TYPE_NUMERIC);
                    } else {
                        $folha->setCellValueExplicit($ref, (string) $valor, DataType::TYPE_STRING);
                    }
                }
            }
        }

        return $this->gravar($planilha);
    }

    private function gravar(Spreadsheet $planilha): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'prodv2').'.xlsx';
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    /** Uma linha do modelo v2 pelos campos (o resto em branco), na ordem das colunas. */
    private function linha(array $campos): array
    {
        return array_map(fn (string $c) => $campos[$c] ?? null, ModeloProdutosXlsx::CAMPOS);
    }

    /** @param  list<array>  $linhas  campos por linha */
    private function arquivoV2(array $linhas): string
    {
        return $this->xlsx(['Produtos' => [ModeloProdutosXlsx::CABECALHOS, ...array_map(fn ($l) => $this->linha($l), $linhas)]]);
    }

    private function upload(string $caminho): UploadedFile
    {
        return new UploadedFile($caminho, 'produtos.xlsx', null, null, true);
    }

    private ?string $ultimoBaixado = null;

    private function baixado($resposta): Spreadsheet
    {
        $caminho = tempnam(sys_get_temp_dir(), 'baixv2').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $resposta->streamedContent());
        $this->ultimoBaixado = $caminho;

        return IOFactory::load($caminho);
    }

    /**
     * As validações da aba Produtos como o Excel as lê: o XML da aba, por intervalo. (Ao reler, o
     * PhpSpreadsheet espalha cada intervalo em uma validação por célula; o arquivo tem uma só.)
     *
     * @return array<string, array{tipo: string, formula: string, erro: bool}>
     */
    private function validacoesDoArquivo(string $caminho): array
    {
        $zip = new \ZipArchive();
        $zip->open($caminho);
        $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        $saida = [];
        foreach ($xml->xpath('//*[local-name()="dataValidation"]') ?: [] as $v) {
            $formula = $v->xpath('./*[local-name()="formula1"]');
            $saida[(string) $v['sqref']] = [
                'tipo'    => (string) $v['type'],
                'formula' => $formula ? (string) $formula[0] : '',
                'erro'    => (string) $v['showErrorMessage'] === '1',
            ];
        }

        return $saida;
    }

    private function importador(): ImportadorProdutos
    {
        return app(ImportadorProdutos::class);
    }

    private function ator(Company $empresa): AtorDoPortal
    {
        return $this->atorCliente($empresa);
    }

    /** Valor de uma célula da aba Produtos pelo campo do modelo. */
    private function celula(Worksheet $folha, string $campo, int $linha): ?string
    {
        $v = $folha->getCell(ModeloProdutosXlsx::coluna($campo).$linha)->getValue();

        return $v === null ? null : (string) $v;
    }

    /** Toda string que o cliente leria no arquivo, com onde ela está. @return array<string, string> */
    private function textosDoArquivo(Spreadsheet $planilha): array
    {
        $p = $planilha->getProperties();
        $textos = [
            'propriedade criador' => (string) $p->getCreator(), 'propriedade título' => (string) $p->getTitle(),
            'propriedade assunto' => (string) $p->getSubject(), 'propriedade descrição' => (string) $p->getDescription(),
            'propriedade palavras' => (string) $p->getKeywords(), 'propriedade categoria' => (string) $p->getCategory(),
            'propriedade empresa' => (string) $p->getCompany(), 'propriedade gerente' => (string) $p->getManager(),
        ];
        foreach ($planilha->getAllSheets() as $aba) {
            $textos["nome da aba {$aba->getTitle()}"] = $aba->getTitle();
            foreach ($aba->getCellCollection()->getCoordinates() as $coord) {
                $textos["{$aba->getTitle()}!{$coord}"] = (string) $aba->getCell($coord)->getValue();
            }
            foreach ($aba->getDataValidationCollection() as $onde => $v) {
                $textos["validação {$aba->getTitle()}!{$onde}"] = implode(' | ', [$v->getFormula1(), $v->getErrorTitle(), $v->getError(), $v->getPromptTitle(), $v->getPrompt()]);
            }
        }

        return $textos;
    }

    private function assertSemOrigem(string $texto, string $onde): void
    {
        // O JSON do Laravel escapa acento; sem decodificar, “anúncio” passaria batido.
        $texto = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $texto);
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $texto, "a origem vazou em {$onde}: {$texto}");
    }

    // ═══ 1. Modelo v2 ═══════════════════════════════════════════════════════

    public function test_modelo_tem_produtos_instrucoes_e_listas_oculta_com_as_listas_da_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        $listas = app(ListasDaEmpresaService::class);
        $listas->criar($empresa, ListasDaEmpresaService::FAMILIA, 'Farmhouse', $ator);
        $listas->criar($empresa, ListasDaEmpresaService::FAMILIA, 'Nordic', $ator);
        $listas->criar($empresa, ListasDaEmpresaService::AMBIENTE, 'Cozinha', $ator);
        // A lista de OUTRA empresa nunca aparece no modelo desta.
        $outra = $this->empresaDoGabarito();
        $listas->criar($outra, ListasDaEmpresaService::FAMILIA, 'Linha Secreta', $this->ator($outra));

        $planilha = $this->baixado($this->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos.modelo'))->assertOk()->assertDownload('modelo-produtos.xlsx'));

        $this->assertSame(['Produtos', 'Instruções', 'Listas'], $planilha->getSheetNames());
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $planilha->getSheetByName('Listas')->getSheetState());
        $this->assertSame(Worksheet::SHEETSTATE_VISIBLE, $planilha->getSheetByName('Produtos')->getSheetState());

        $folha = $planilha->getSheetByName('Produtos');
        foreach (ModeloProdutosXlsx::CABECALHOS as $i => $titulo) {
            $this->assertSame($titulo, $folha->getCell(Coordinate::stringFromColumnIndex($i + 1).'1')->getValue());
        }
        $this->assertSame(['Ref*', 'Produto (grupo)*', 'Tipo de variação', 'Variação', 'Nome do produto*'], array_slice(ModeloProdutosXlsx::CABECALHOS, 0, 5));
        $this->assertSame(['Estoque (un.)', 'Descrição'], array_slice(ModeloProdutosXlsx::CABECALHOS, -2));

        $listasAba = $planilha->getSheetByName('Listas');
        $coluna = fn (string $col) => array_values(array_filter(array_map(
            fn ($r) => $listasAba->getCell("{$col}{$r}")->getValue(), range(2, 12)), fn ($v) => $v !== null));
        $this->assertSame(['Cor', 'Tamanho', 'Voltagem', 'Material', 'Sabor', 'Outro'], $coluna('A'));
        $this->assertSame(['Farmhouse', 'Nordic'], $coluna('B'));
        $this->assertSame(['Cozinha'], $coluna('C'));

        // Validação em lista: tipo de variação estrito; família e ambiente sugerem a lista e aceitam nome novo.
        $intervalo = fn (string $campo) => ModeloProdutosXlsx::coluna($campo).'2:'.ModeloProdutosXlsx::coluna($campo).'1001';
        $this->assertSame([
            $intervalo('eixo')      => ['tipo' => DataValidation::TYPE_LIST, 'formula' => 'Listas!$A$2:$A$7', 'erro' => true],
            $intervalo('familia')   => ['tipo' => DataValidation::TYPE_LIST, 'formula' => 'Listas!$B$2:$B$3', 'erro' => false],
            $intervalo('ambientes') => ['tipo' => DataValidation::TYPE_LIST, 'formula' => 'Listas!$C$2:$C$2', 'erro' => false],
            $intervalo('estoque')   => ['tipo' => DataValidation::TYPE_WHOLE, 'formula' => '0', 'erro' => true],
        ], $this->validacoesDoArquivo($this->ultimoBaixado), 'família e ambiente novos são aceitos (são criados na importação)');

        $this->assertStringNotContainsString('Linha Secreta', json_encode($this->textosDoArquivo($planilha), JSON_UNESCAPED_UNICODE));
    }

    public function test_empresa_sem_familias_nem_ambientes_tem_modelo_sem_essas_validacoes(): void
    {
        $folha = ModeloProdutosXlsx::gerar()->getSheetByName('Produtos');
        $formulas = array_map(fn ($v) => $v->getFormula1(), $folha->getDataValidationCollection());

        $this->assertContains('Listas!$A$2:$A$7', $formulas);
        $this->assertNotContains('Listas!$B$2:$B$1', $formulas);
        $this->assertCount(2, $formulas, 'só tipo de variação e estoque');
    }

    /** SIGILO: nada do arquivo (vazio ou preenchido) cita a plataforma — cabeçalho, aba, instrução, lista, validação, propriedade, XML. */
    public function test_nada_do_modelo_nem_da_planilha_baixada_revela_o_destino_do_cadastro(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'S-1', 'grupo' => 'S', 'nome' => 'Sofá Alfa', 'eixo' => 'cor', 'valor' => 'Cinza', 'familia' => 'Linha A', 'ambientes' => 'Sala', 'categoria_ml_id' => 'MLB10', 'custo' => '10,00', 'estoque' => '3', 'descricao' => 'Sofá de três lugares.'],
        ], $ator);

        foreach (['modelo' => app(PlanilhaDosProdutos::class)->modelo($empresa), 'baixada' => app(PlanilhaDosProdutos::class)->exportar($empresa)] as $qual => $planilha) {
            foreach ($this->textosDoArquivo($planilha) as $onde => $texto) {
                $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $texto, "{$qual}: {$onde} = {$texto}");
            }

            // O XML cru de todas as partes do pacote, atributos inclusive (mensagem de validação é atributo).
            $zip = new \ZipArchive();
            $zip->open($this->gravar($planilha));
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nome = $zip->getNameIndex($i);
                $xml = (string) $zip->getFromIndex($i);
                $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|publica|\bmlb\d/iu', $xml, "{$qual}: {$nome}");
            }
            $zip->close();
        }
    }

    public function test_as_instrucoes_sao_curtas_e_explicam_grupo_e_nome_da_variacao(): void
    {
        $aba = ModeloProdutosXlsx::gerar()->getSheetByName('Instruções');
        $linhas = array_values(array_filter(array_map(fn ($r) => $aba->getCell("A{$r}")->getValue(), range(1, 40)), fn ($v) => $v !== null));

        $this->assertLessThanOrEqual(16, count($linhas), 'instruções curtas');
        $texto = implode("\n", $linhas);
        $this->assertStringContainsString('Produto (grupo)*: o código que junta as variações de um mesmo produto.', $texto);
        $this->assertStringContainsString('Dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto.', $texto);
        $this->assertStringContainsString('Cor, Tamanho, Voltagem, Material, Sabor ou Outro', $texto);
        $this->assertStringContainsString('Célula em branco não apaga nada.', $texto);
    }

    // ═══ 2. Leitor: cabeçalhos novos e antigos ══════════════════════════════

    public function test_leitor_le_os_cabecalhos_do_modelo_novo_e_do_antigo_pela_mesma_regra(): void
    {
        $novo = (new LeitorPlanilhaProdutos())->ler($this->arquivoV2([
            ['codigo' => 'A-1', 'grupo' => 'A', 'eixo' => 'Voltagem', 'variacao' => '220', 'nome' => 'Ventilador', 'estoque' => 0, 'descricao' => "Linha 1\nLinha 2"],
        ]));
        $this->assertNull($novo['erro_geral']);
        $this->assertSame(ModeloProdutosXlsx::CAMPOS, $novo['colunas']);
        $b = $novo['linhas'][0]['bruta'];
        $this->assertSame(['A-1', 'A', 'Voltagem', '220', 'Ventilador', '0', "Linha 1\nLinha 2"],
            [$b['codigo'], $b['grupo'], $b['eixo'], $b['variacao'], $b['nome'], $b['estoque'], $b['descricao']]);

        $antigo = (new LeitorPlanilhaProdutos())->ler($this->xlsx(['Produtos' => [
            ModeloEImportacaoTest::CABECALHOS_ANTIGOS,
            ['B-1', 'B', '1', 'Mesa', 'Linha', 'Sala', 'Mesa de jantar', 1.0, null, 9.5, 100.0],
        ]]));
        $this->assertNull($antigo['erro_geral']);
        $this->assertSame(['codigo', 'grupo', 'variacao', 'nome', 'familia', 'ambientes', 'categoria', 'n_volumes', 'volumes_texto', 'peso_total', 'custo'], $antigo['colunas']);
        $this->assertSame('Mesa de jantar', $antigo['linhas'][0]['bruta']['categoria'], '"Categoria ML" ainda é a categoria');
        $this->assertSame('B', $antigo['linhas'][0]['bruta']['grupo'], '"Grupo (anúncio)" ainda é o grupo');

        // "Produto (grupo)" é o grupo e "Produto" é o nome, na mesma planilha.
        $misto = (new LeitorPlanilhaProdutos())->ler($this->xlsx(['Produtos' => [['Ref', 'Produto', 'Produto (grupo)', 'Tipo'], ['C-1', 'Cadeira', 'C', 'Cor']]]));
        $this->assertSame(['codigo' => 'C-1', 'nome' => 'Cadeira', 'grupo' => 'C', 'eixo' => 'Cor'], $misto['linhas'][0]['bruta']);
    }

    // ═══ 3. Importação: tipo, nome da variação, estoque e descrição ═════════

    public function test_tipo_de_variacao_vira_o_eixo_e_a_variacao_e_o_nome_ao_pe_da_letra(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->arquivoV2([
            ['codigo' => 'M-1', 'grupo' => 'M', 'eixo' => 'Cor', 'variacao' => 'Natural', 'nome' => 'Mesa Alfa', 'estoque' => 5, 'descricao' => 'Mesa de madeira.'],
            ['codigo' => 'M-2', 'grupo' => 'M', 'eixo' => 'cor', 'variacao' => 'Off White', 'nome' => 'Mesa Alfa', 'estoque' => 0],
            ['codigo' => 'V-1', 'grupo' => 'V', 'eixo' => 'Voltagem', 'variacao' => '220', 'nome' => 'Ventilador'],
            ['codigo' => 'V-2', 'grupo' => 'V', 'eixo' => 'Voltagem', 'variacao' => '110', 'nome' => 'Ventilador'],
            ['codigo' => 'E-1', 'grupo' => 'E', 'eixo' => 'Estampa', 'variacao' => 'Floral', 'nome' => 'Almofada'],
            ['codigo' => 'U-1', 'grupo' => 'U', 'nome' => 'Banco'],
        ]);

        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertSame(['novos' => 6, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0], $previa['totais']);
        $this->assertContains('linha 6: o tipo de variação “Estampa” não está na lista, usamos “Outro”.', $previa['avisos']);

        $r = $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));
        $this->assertSame(6, $r['novos']);

        $v = fn (string $ref) => EstruturaProdutoVariacao::where('codigo', $ref)->firstOrFail();
        $this->assertSame(['cor', 'Natural', 5], [$v('M-1')->eixo, $v('M-1')->valor, $v('M-1')->estoque]);
        $this->assertSame(['cor', 'Off White', 0], [$v('M-2')->eixo, $v('M-2')->valor, $v('M-2')->estoque], 'estoque 0 é zero, não vazio');
        $this->assertSame($v('M-1')->produto_id, $v('M-2')->produto_id, 'as duas cores no mesmo produto');
        $this->assertSame(['voltagem', '220'], [$v('V-1')->eixo, $v('V-1')->valor], '"220" com tipo é nome, não a posição');
        $this->assertSame(['voltagem', '110'], [$v('V-2')->eixo, $v('V-2')->valor]);
        $this->assertNotSame(220, $v('V-1')->ordem);
        $this->assertSame(['outro', 'Floral'], [$v('E-1')->eixo, $v('E-1')->valor]);
        $this->assertNull($v('U-1')->estoque, 'estoque em branco fica vazio');
        $this->assertSame('Mesa de madeira.', $v('M-1')->produto->descricao);
        $this->assertSame(4, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    public function test_celula_em_branco_nao_apaga_estoque_nem_descricao_e_a_mudanca_aparece_na_previa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'K-1', 'grupo' => 'K', 'nome' => 'Cômoda', 'eixo' => 'cor', 'valor' => 'Preta', 'estoque' => '7', 'descricao' => 'Texto antigo.'],
        ], $ator);

        // Só o nome: estoque e descrição ficam.
        $this->importador()->aplicar($empresa, $this->arquivoV2([['codigo' => 'K-1', 'grupo' => 'K', 'nome' => 'Cômoda']]), $ator);
        $k = EstruturaProdutoVariacao::where('codigo', 'K-1')->firstOrFail();
        $this->assertSame(7, $k->estoque);
        $this->assertSame('Texto antigo.', $k->produto->descricao);
        $this->assertSame(['cor', 'Preta'], [$k->eixo, $k->valor]);

        // Estoque 0 e descrição nova: a prévia mostra as duas mudanças.
        $caminho = $this->arquivoV2([['codigo' => 'K-1', 'grupo' => 'K', 'nome' => 'Cômoda', 'estoque' => '0', 'descricao' => 'Texto novo.']]);
        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertSame(1, $previa['totais']['atualizados']);
        $this->assertSame(['descrição', 'estoque'], $previa['grupos']['atualizados'][0]['mudou']);

        $r = $this->importador()->aplicar($empresa, $caminho, $ator);
        $this->assertSame(1, $r['atualizados']);
        $k->refresh();
        $this->assertSame(0, $k->estoque);
        $this->assertSame('Texto novo.', $k->produto->descricao);
    }

    public function test_a_descricao_e_do_produto_vale_a_primeira_que_vier_e_descricoes_diferentes_avisam(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->arquivoV2([
            ['codigo' => 'P-1', 'grupo' => 'P', 'eixo' => 'Cor', 'variacao' => 'Azul', 'nome' => 'Pufe'],
            ['codigo' => 'P-2', 'grupo' => 'P', 'eixo' => 'Cor', 'variacao' => 'Rosa', 'nome' => 'Pufe', 'descricao' => 'Escrita na segunda linha.'],
            ['codigo' => 'Q-1', 'grupo' => 'Q', 'eixo' => 'Cor', 'variacao' => 'Azul', 'nome' => 'Banqueta', 'descricao' => 'A primeira.'],
            ['codigo' => 'Q-2', 'grupo' => 'Q', 'eixo' => 'Cor', 'variacao' => 'Rosa', 'nome' => 'Banqueta', 'descricao' => 'Outra.'],
            ['codigo' => 'L-1', 'nome' => 'Longa', 'descricao' => str_repeat('a', 5001)],
        ]);

        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertContains('Produto (grupo) Q: descrições diferentes, usamos a da linha 4.', $previa['avisos']);
        $this->assertSame([['linha' => 6, 'codigo' => 'L-1', 'nome' => 'Longa', 'motivo' => 'A descrição pode ter até 5.000 caracteres.']], $previa['grupos']['erros']);

        $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));
        $this->assertSame('Escrita na segunda linha.', EstruturaProduto::where('codigo', 'P')->value('descricao'), 'quem escreveu na 2ª linha não perde');
        $this->assertSame('A primeira.', EstruturaProduto::where('codigo', 'Q')->value('descricao'));
        $this->assertSame(0, EstruturaProdutoVariacao::where('codigo', 'L-1')->count());
    }

    /** O produto criado ou mudado pela planilha é preparado no Publicador, inclusive quando só a descrição mudou. */
    public function test_importar_agenda_o_preparo_dos_produtos_criados_e_dos_que_so_mudaram_a_descricao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'D-1', 'grupo' => 'D', 'nome' => 'Aparador', 'descricao' => 'Antes.'],
            ['codigo' => 'F-1', 'grupo' => 'F', 'nome' => 'Fixo'],
        ], $ator);
        $aparador = EstruturaProduto::where('codigo', 'D')->firstOrFail();
        $fixo = EstruturaProduto::where('codigo', 'F')->firstOrFail();

        Queue::fake();
        $this->importador()->aplicar($empresa, $this->arquivoV2([
            ['codigo' => 'D-1', 'grupo' => 'D', 'nome' => 'Aparador', 'descricao' => 'Depois.'],
            ['codigo' => 'F-1', 'grupo' => 'F', 'nome' => 'Fixo'],
            ['codigo' => 'N-1', 'grupo' => 'N', 'nome' => 'Novo'],
        ]), $ator);

        $novo = EstruturaProduto::where('codigo', 'N')->firstOrFail();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $aparador->id && $j->companyId === $empresa->id);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $novo->id);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $fixo->id);
    }

    // ═══ 4. Variações que não ficariam juntas ═══════════════════════════════

    public function test_variacao_sem_nome_repetida_ou_de_outro_tipo_avisa_na_previa_com_texto_neutro(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->arquivoV2([
            // Ordinal da planilha antiga, sem tipo: as duas ficam sem nome.
            ['codigo' => 'O-1', 'grupo' => 'O', 'variacao' => '1', 'nome' => 'Cristaleira'],
            ['codigo' => 'O-2', 'grupo' => 'O', 'variacao' => '2', 'nome' => 'Cristaleira'],
            ['codigo' => 'R-1', 'grupo' => 'R', 'eixo' => 'Cor', 'variacao' => 'Azul', 'nome' => 'Rack'],
            ['codigo' => 'R-2', 'grupo' => 'R', 'eixo' => 'Cor', 'variacao' => 'azul', 'nome' => 'Rack'],
            ['codigo' => 'T-1', 'grupo' => 'T', 'eixo' => 'Cor', 'variacao' => 'Azul', 'nome' => 'Tapete'],
            ['codigo' => 'T-2', 'grupo' => 'T', 'eixo' => 'Cor', 'variacao' => 'Verde', 'nome' => 'Tapete'],
            ['codigo' => 'T-3', 'grupo' => 'T', 'eixo' => 'Tamanho', 'variacao' => 'G', 'nome' => 'Tapete'],
            // Nomeadas e do mesmo tipo: nenhum aviso. Uma variação só: nenhum aviso.
            ['codigo' => 'B-1', 'grupo' => 'B', 'eixo' => 'Cor', 'variacao' => 'Natural', 'nome' => 'Buffet'],
            ['codigo' => 'B-2', 'grupo' => 'B', 'eixo' => 'Cor', 'variacao' => 'Off White', 'nome' => 'Buffet'],
            ['codigo' => 'S-1', 'grupo' => 'S', 'variacao' => 'única', 'nome' => 'Sapateira'],
        ]);

        $avisos = $this->importador()->previa($empresa, $caminho)['avisos'];

        $this->assertContains('1 produto com variação sem nome (Cristaleira): dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto.', $avisos);
        $this->assertContains('1 produto com duas variações de mesmo nome (Rack): use um nome diferente para cada variação.', $avisos);
        $this->assertContains('1 produto com mais de um tipo de variação (Tapete): use um tipo de variação só em cada produto.', $avisos);
        $this->assertCount(3, $avisos);
        foreach ($avisos as $a) {
            $this->assertSemOrigem($a, 'aviso de variação');
        }
    }

    public function test_variacao_nova_sem_nome_de_produto_ja_cadastrado_tambem_avisa(): void
    {
        $empresa = $this->empresaDoGabarito();
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'G-1', 'grupo' => 'G', 'nome' => 'Guarda-roupa', 'eixo' => 'cor', 'valor' => 'Branco'],
        ], $this->ator($empresa));

        // Só a Ref nova, sem nome de variação: junto da cadastrada, o produto passa a ter uma sem nome.
        $avisos = $this->importador()->previa($empresa, $this->arquivoV2([['codigo' => 'G-2', 'grupo' => 'G', 'nome' => 'Guarda-roupa']]))['avisos'];
        $this->assertContains('1 produto com variação sem nome (Guarda-roupa): dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto.', $avisos);

        // Com o nome, nada a avisar (o tipo vem da 1ª variação, como na gravação).
        $avisos = $this->importador()->previa($empresa, $this->arquivoV2([['codigo' => 'G-2', 'grupo' => 'G', 'variacao' => 'Cinza', 'eixo' => 'Cor', 'nome' => 'Guarda-roupa']]))['avisos'];
        $this->assertSame([], $avisos);
    }

    /**
     * A FORMA da aba Produtos da planilha do Planejamento (medida no arquivo real: 11 colunas,
     * Variação ordinal "1"/"2"/"única", categoria em texto, números como double). Entra sem erro,
     * avisa das variações sem nome e agrupa as categorias pelo nome digitado.
     */
    public function test_planilha_no_formato_do_planejamento_entra_sem_erro_e_mostra_o_que_falta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $vol = "93\u{00D7}55\u{00D7}6 \u{00B7} 9.5";
        $caminho = $this->xlsx(['Planejamento' => [['x']], 'Produtos' => [
            ModeloEImportacaoTest::CABECALHOS_ANTIGOS,
            ['1014-1', '1014', '1', 'Cristaleira Alfa', 'Linha A', 'Sala de Jantar', 'Cristaleira', 1.0, $vol, 9.5, 100.0],
            ['1014-2', '1014', '2', 'Cristaleira Alfa', 'Linha A', 'Sala de Jantar', 'Cristaleira', 1.0, $vol, 9.5, 100.0],
            ['2020-1', '2020', 'única', 'Mesa Beta', 'Linha A', 'Sala de Jantar', 'Mesa de jantar', 1.0, $vol, 9.5, 250.0],
            ['3030-1', '3030', '1', 'Cadeira Gama', 'Linha B', 'Sala de Jantar / Cozinha', 'Cadeira', 1.0, $vol, 9.5, 80.0],
            ['3030-2', '3030', '2', 'Cadeira Gama', 'Linha B', 'Sala de Jantar / Cozinha', 'Cadeira', 1.0, $vol, 9.5, 80.0],
            ['4040-1', '4040', 'única', 'Mesa Delta', 'Linha B', 'Cozinha', 'mesa de Jantar', 1.0, $vol, 9.5, 300.0],
        ]]);

        $previa = $this->importador()->previa($empresa, $caminho);

        $this->assertSame(['novos' => 6, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0], $previa['totais']);
        $this->assertContains('2 produtos com variação sem nome (Cristaleira Alfa, Cadeira Gama): dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto.', $previa['avisos']);
        $this->assertSame(3, $previa['categorias_a_confirmar']['total_nomes']);
        $this->assertSame(4, $previa['categorias_a_confirmar']['total_produtos']);
        $this->assertSame(['texto' => 'Mesa de jantar', 'produtos' => 2, 'exemplos' => ['Mesa Beta', 'Mesa Delta']],
            array_intersect_key($previa['categorias_a_confirmar']['nomes'][0], array_flip(['texto', 'produtos', 'exemplos'])),
            'o mesmo nome sem caixa nem acento é um nome só, e o mais usado vem primeiro');
        $this->assertSemOrigem(json_encode($previa, JSON_UNESCAPED_UNICODE), 'prévia');
    }

    // ═══ 5. Baixar meus produtos na planilha ════════════════════════════════

    public function test_baixar_meus_produtos_traz_so_os_da_empresa_da_sessao_no_modelo_v2(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'M-1', 'grupo' => 'M', 'nome' => 'Mesa Alfa', 'eixo' => 'cor', 'valor' => 'Natural', 'familia' => 'Farmhouse', 'ambientes' => 'Sala / Cozinha',
                'categoria_ml_id' => 'MLB10', 'volumes_texto' => "120\u{00D7}80\u{00D7}10 \u{00B7} 25 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5", 'custo' => '1.234,5', 'estoque' => '0', 'descricao' => 'Mesa de madeira.'],
            ['codigo' => 'M-2', 'grupo' => 'M', 'nome' => 'Mesa Alfa', 'eixo' => 'cor', 'valor' => 'Off White', 'estoque' => '4'],
            ['codigo' => 'C-1', 'grupo' => 'C', 'nome' => 'Cadeira', 'categoria_texto' => 'Cadeira de jantar'],
        ], $ator);
        $outra = $this->empresaDoGabarito();
        app(ProdutoCadastroService::class)->gravarLinhas($outra, [['codigo' => 'X-1', 'nome' => 'Produto de Outra Empresa']], $this->ator($outra));

        $resposta = $this->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos.exportar'))
            ->assertOk()->assertDownload('meus-produtos-'.now()->format('Y-m-d').'.xlsx');
        $planilha = $this->baixado($resposta);
        $folha = $planilha->getSheetByName('Produtos');

        $this->assertSame(['Produtos', 'Instruções', 'Listas'], $planilha->getSheetNames());
        $this->assertSame(['M-1', 'M-2', 'C-1'], [$this->celula($folha, 'codigo', 2), $this->celula($folha, 'codigo', 3), $this->celula($folha, 'codigo', 4)]);
        $this->assertNull($this->celula($folha, 'codigo', 5));
        $this->assertStringNotContainsString('Outra Empresa', json_encode($this->textosDoArquivo($planilha), JSON_UNESCAPED_UNICODE));

        $this->assertSame(['M', 'Cor', 'Natural', 'Mesa Alfa', 'Farmhouse', 'Cozinha / Sala', 'Mesas de Jantar'], array_map(
            fn ($c) => $this->celula($folha, $c, 2), ['grupo', 'eixo', 'variacao', 'nome', 'familia', 'ambientes', 'categoria']));
        $this->assertSame(['2', "120\u{00D7}80\u{00D7}10 \u{00B7} 25 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5", '33,5', '1.234,50', '0', 'Mesa de madeira.'], array_map(
            fn ($c) => $this->celula($folha, $c, 2), ['n_volumes', 'volumes_texto', 'peso_total', 'custo', 'estoque', 'descricao']));
        $this->assertNull($this->celula($folha, 'descricao', 3), 'a descrição só na 1ª linha do produto');
        $this->assertSame('4', $this->celula($folha, 'estoque', 3));
        $this->assertSame('Cadeira de jantar', $this->celula($folha, 'categoria', 4), 'categoria a confirmar sai pelo nome digitado');
        foreach ($this->textosDoArquivo($planilha) as $onde => $texto) {
            $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $texto, $onde);
        }
    }

    /** Ida e volta: a planilha baixada, enviada de novo sem mexer, é "sem mudança" em tudo. */
    public function test_a_planilha_baixada_volta_pela_importacao_sem_nenhuma_mudanca(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'M-1', 'grupo' => 'M', 'nome' => 'Mesa Alfa', 'eixo' => 'cor', 'valor' => 'Natural', 'familia' => 'Farmhouse', 'ambientes' => 'Sala / Cozinha',
                'categoria_ml_id' => 'MLB10', 'volumes_texto' => "120,5\u{00D7}80\u{00D7}10 \u{00B7} 25,125", 'custo' => '0,5', 'estoque' => '0', 'descricao' => "Linha 1\nLinha 2"],
            ['codigo' => 'M-2', 'grupo' => 'M', 'nome' => 'Mesa Alfa', 'eixo' => 'cor', 'valor' => 'Off White', 'custo' => '12345678,9'],
            ['codigo' => 'C-1', 'grupo' => 'C', 'nome' => 'Cadeira', 'categoria_texto' => 'Cadeira de jantar', 'estoque' => '12'],
            ['codigo' => 'S-1', 'nome' => 'Solo'],
        ], $ator);
        $antes = EstruturaProdutoVariacao::orderBy('id')->get()->map->only(['codigo', 'eixo', 'valor', 'ordem', 'custo', 'estoque'])->all();

        $caminho = $this->gravar(app(PlanilhaDosProdutos::class)->exportar($empresa));
        $previa = $this->importador()->previa($empresa, $caminho);

        $this->assertSame(['novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 4, 'erros' => 0], $previa['totais'], json_encode($previa['grupos']['atualizados']));
        $this->assertSame([], $previa['avisos']);
        $this->assertSame(['Cadeira de jantar'], array_column($previa['categorias_a_confirmar']['nomes'], 'texto'), 'a confirmada não volta para confirmar');

        $r = $this->importador()->aplicar($empresa, $caminho, $ator);
        $this->assertSame([0, 0, 4], [$r['novos'], $r['atualizados'], $r['sem_mudanca']]);
        $this->assertSame($antes, EstruturaProdutoVariacao::orderBy('id')->get()->map->only(['codigo', 'eixo', 'valor', 'ordem', 'custo', 'estoque'])->all());
        $this->assertSame(EstruturaProduto::CATEGORIA_CONFIRMADA, EstruturaProduto::where('codigo', 'M')->first()->estadoCategoria());
    }

    /** Acrescentar uma linha na planilha baixada, com o mesmo grupo, acrescenta a variação ao produto — inclusive o que ficou sem código. */
    public function test_linha_nova_na_planilha_baixada_entra_no_produto_do_grupo_mesmo_sem_codigo_proprio(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'X', 'grupo' => 'X', 'nome' => 'Dono do código', 'eixo' => 'cor', 'valor' => 'Azul'],
        ], $ator);
        // Produto sem código próprio: acontece quando o código que ele pediria já é de outro produto.
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [['codigo' => 'Y-1', 'nome' => 'Sem código', 'eixo' => 'cor', 'valor' => 'Verde']], $ator);
        EstruturaProduto::where('nome', 'Sem código')->update(['codigo' => null]);
        $semCodigo = EstruturaProduto::where('nome', 'Sem código')->firstOrFail();

        $planilha = app(PlanilhaDosProdutos::class)->exportar($empresa);
        $folha = $planilha->getSheetByName('Produtos');
        $this->assertSame('Y-1', $this->celula($folha, 'grupo', 3), 'sem código, o grupo é a Ref da 1ª variação');

        // A pessoa acrescenta uma cor em cada produto, copiando o grupo da linha de cima.
        foreach ([[4, 'X-2', 'X', 'Dono do código', 'Rosa'], [5, 'Y-2', 'Y-1', 'Sem código', 'Lilás']] as [$n, $ref, $grupo, $nome, $cor]) {
            foreach (['codigo' => $ref, 'grupo' => $grupo, 'eixo' => 'Cor', 'variacao' => $cor, 'nome' => $nome] as $campo => $valor) {
                $folha->setCellValueExplicit(ModeloProdutosXlsx::coluna($campo).$n, $valor, DataType::TYPE_STRING);
            }
        }

        $r = $this->importador()->aplicar($empresa, $this->gravar($planilha), $ator);

        $this->assertSame(2, $r['novos']);
        $this->assertSame(EstruturaProdutoVariacao::where('codigo', 'X')->value('produto_id'), EstruturaProdutoVariacao::where('codigo', 'X-2')->value('produto_id'));
        $this->assertSame($semCodigo->id, EstruturaProdutoVariacao::where('codigo', 'Y-2')->value('produto_id'));
        $this->assertSame(2, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    // ═══ 6. Categorias a confirmar em lote ══════════════════════════════════

    public function test_previa_agrupa_as_categorias_a_confirmar_pelo_nome_e_deixa_de_fora_as_ja_escolhidas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'J-1', 'grupo' => 'J', 'nome' => 'Mesa Já Escolhida', 'categoria_ml_id' => 'MLB10'],
            ['codigo' => 'A-1', 'grupo' => 'A', 'nome' => 'Mesa A Confirmar', 'categoria_texto' => 'Mesa'],
        ], $ator);

        $previa = $this->importador()->previa($empresa, $this->arquivoV2([
            ['codigo' => 'J-1', 'grupo' => 'J', 'nome' => 'Mesa Já Escolhida', 'categoria' => 'Mesa de jantar'],
            ['codigo' => 'A-1', 'grupo' => 'A', 'nome' => 'Mesa A Confirmar', 'categoria' => 'Mesa de jantar'],
            ['codigo' => 'N-1', 'grupo' => 'N', 'eixo' => 'Cor', 'variacao' => 'Azul', 'nome' => 'Mesa Nova', 'categoria' => 'Mesa de Jantar'],
            ['codigo' => 'N-2', 'grupo' => 'N', 'eixo' => 'Cor', 'variacao' => 'Rosa', 'nome' => 'Mesa Nova', 'categoria' => 'Mesa de Jantar'],
            ['codigo' => 'C-1', 'grupo' => 'C', 'nome' => 'Cadeira Nova', 'categoria' => 'Cadeira'],
            ['codigo' => 'I-1', 'grupo' => 'I', 'nome' => 'Com código', 'categoria' => 'MLB21'],
            ['codigo' => 'V-1', 'grupo' => 'V', 'nome' => 'Sem categoria'],
        ]));

        $nomes = $previa['categorias_a_confirmar']['nomes'];
        $this->assertSame([['texto' => 'Mesa de jantar', 'produtos' => 2], ['texto' => 'Cadeira', 'produtos' => 1]],
            array_map(fn ($n) => ['texto' => $n['texto'], 'produtos' => $n['produtos']], $nomes),
            'por produto (não por linha), sem o já escolhido e sem o código digitado');
        $this->assertSame(['Mesa A Confirmar', 'Mesa Nova'], $nomes[0]['exemplos']);
        $this->assertSame(['nomes' => 2, 'produtos' => 3], ['nomes' => $previa['categorias_a_confirmar']['total_nomes'], 'produtos' => $previa['categorias_a_confirmar']['total_produtos']]);
        $this->assertSemOrigem(json_encode($previa, JSON_UNESCAPED_UNICODE), 'prévia com código de categoria na célula');
    }

    public function test_sugestao_por_nome_devolve_a_folha_usa_o_cache_e_tem_limite(): void
    {
        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());
        $url = route('portal.auth.estrutura.produtos.categorias.sugerir_nomes');

        $r = $sessao->postJson($url, ['nomes' => ['Mesa de jantar', 'Cadeira', 'Coisa sem sugestão', 'mesa de JANTAR', 'x']])->assertOk();
        $this->assertSame([
            ['texto' => 'Mesa de jantar', 'sugestao' => ['id' => 'MLB10', 'nome' => 'Mesas de Jantar', 'caminho_texto' => 'Casa > Mesas de Jantar']],
            ['texto' => 'Cadeira', 'sugestao' => ['id' => 'MLB21', 'nome' => 'Cadeiras de Jantar', 'caminho_texto' => 'Casa > Cadeiras > Cadeiras de Jantar']],
            ['texto' => 'Coisa sem sugestão', 'sugestao' => null],
        ], $r->json('sugestoes'), 'a primeira FOLHA; o mesmo nome sem caixa só uma vez; texto curto demais fica de fora');
        $this->assertFalse($r->json('indisponivel'));
        foreach ($r->json('sugestoes') as $s) {
            $this->assertSemOrigem((string) json_encode([$s['texto'], $s['sugestao']['nome'] ?? '', $s['sugestao']['caminho_texto'] ?? ''], JSON_UNESCAPED_UNICODE), 'texto da sugestão');
        }

        // A segunda vez sai do cache: nenhuma consulta nova ao catálogo para os nomes já sugeridos.
        $antes = count(Http::recorded());
        $sessao->postJson($url, ['nomes' => ['Mesa de jantar', 'Cadeira']])->assertOk()->assertJsonPath('sugestoes.0.sugestao.id', 'MLB10');
        $this->assertSame($antes, count(Http::recorded()));

        $sessao->postJson($url, ['nomes' => array_map(fn ($i) => "Nome {$i}", range(1, 11))])->assertStatus(422)->assertJsonValidationErrors('nomes');
        $sessao->postJson($url, ['nomes' => []])->assertStatus(422);

        // Catálogo fora do ar: 200 com `indisponivel`, nunca erro (e a falha não fica guardada).
        $this->catalogoFora = true;
        $fora = $sessao->postJson($url, ['nomes' => ['Banqueta']])->assertOk();
        $this->assertTrue($fora->json('indisponivel'));
        $this->assertNull($fora->json('sugestoes.0.sugestao'));
    }

    public function test_aplicar_com_a_categoria_confirmada_por_nome_vale_para_todos_os_produtos_daquele_nome(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'J-1', 'grupo' => 'J', 'nome' => 'Já escolhida', 'categoria_ml_id' => 'MLB21'],
        ], $ator);
        $arquivo = $this->arquivoV2([
            ['codigo' => 'J-1', 'grupo' => 'J', 'nome' => 'Já escolhida', 'categoria' => 'Mesa de jantar'],
            ['codigo' => 'M-1', 'grupo' => 'M', 'eixo' => 'Cor', 'variacao' => 'Azul', 'nome' => 'Mesa Um', 'categoria' => 'Mesa de jantar'],
            ['codigo' => 'M-2', 'grupo' => 'M', 'eixo' => 'Cor', 'variacao' => 'Rosa', 'nome' => 'Mesa Um', 'categoria' => 'Mesa de jantar'],
            ['codigo' => 'D-1', 'grupo' => 'D', 'nome' => 'Mesa Dois', 'categoria' => 'MESA DE JANTAR'],
            ['codigo' => 'C-1', 'grupo' => 'C', 'nome' => 'Cadeira', 'categoria' => 'Cadeira'],
            ['codigo' => 'P-1', 'grupo' => 'P', 'nome' => 'Poltrona', 'categoria' => 'Poltrona'],
        ]);

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.produtos.importacao'), ['arquivo' => $this->upload($arquivo), 'categorias' => [
                ['texto' => 'mesa de jantar', 'id' => 'MLB10'],
                // Categoria que não é a última do caminho: ignorada, o produto fica a confirmar.
                ['texto' => 'Cadeira', 'id' => 'MLB20'],
            ]])
            ->assertRedirect()
            ->assertSessionHas('success', 'Importação concluída: 5 novos, 0 atualizados. Categoria confirmada em 2 produtos. 2 produtos com a categoria a confirmar.');

        $mesaUm = EstruturaProduto::where('codigo', 'M')->firstOrFail();
        $this->assertSame(['MLB10', 'Mesas de Jantar', 'Casa > Mesas de Jantar'], [$mesaUm->categoria_ml_id, $mesaUm->categoria_ml_nome, $mesaUm->categoria_ml_caminho]);
        $this->assertSame('MLB10', EstruturaProduto::where('codigo', 'D')->value('categoria_ml_id'));
        $this->assertSame('MLB21', EstruturaProduto::where('codigo', 'J')->value('categoria_ml_id'), 'a já escolhida fica como estava (BE-CR-02)');
        $cadeira = EstruturaProduto::where('codigo', 'C')->firstOrFail();
        $this->assertSame([null, 'Cadeira'], [$cadeira->categoria_ml_id, $cadeira->categoria_ml_nome]);
        $this->assertSame(EstruturaProduto::CATEGORIA_A_CONFIRMAR, EstruturaProduto::where('codigo', 'P')->first()->estadoCategoria());
    }

    public function test_confirmacao_com_categoria_em_formato_invalido_e_recusada_sem_gravar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $arquivo = $this->arquivoV2([['codigo' => 'M-1', 'grupo' => 'M', 'nome' => 'Mesa', 'categoria' => 'Mesa de jantar']]);

        $r = $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.produtos.importacao'), ['arquivo' => $this->upload($arquivo), 'categorias' => [['texto' => 'Mesa de jantar', 'id' => 'xyz']]])
            ->assertRedirect()
            ->assertSessionHasErrors('categorias.0.id');

        $this->assertSame('Categoria inválida. Escolha de novo.', session('errors')->first('categorias.0.id'));
        $this->assertSame(0, EstruturaProduto::count());
        $this->assertSemOrigem((string) session('errors')->first('categorias.0.id'), 'erro da confirmação');
    }
}
