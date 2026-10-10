<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Services\Portal\Estrutura\Produtos\ImportadorProdutos;
use App\Services\Portal\Estrutura\Produtos\LeitorPlanilhaProdutos;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-09: planilha-modelo, leitor seguro e importação com prévia.
 *
 * A fixture é SINTÉTICA, gerada aqui: o arquivo real do cliente nunca entra no teste.
 *
 * Desde 09/10/2026 o modelo tem 14 colunas (`PlanilhaV2Test`). Os testes daqui montam o
 * arquivo com os 11 cabeçalhos ANTIGOS — os da aba Produtos da planilha do Planejamento —
 * e por isso são também a prova de que o arquivo antigo do cliente continua entrando.
 */
class ModeloEImportacaoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** Os cabeçalhos do modelo de antes de 09/10/2026, como estão na aba Produtos do Planejamento. */
    public const CABECALHOS_ANTIGOS = [
        'Ref',
        'Grupo (anúncio)',
        'Variação',
        'Produto',
        'Família',
        'Ambiente',
        'Categoria ML',
        'Nº volumes',
        "Volumes (C\u{00D7}L\u{00D7}A cm \u{00B7} kg)",
        'Peso total (kg)',
        'Custo (R$)',
    ];

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            if (preg_match('#/categories/(MLB\d+)$#', $r->url(), $m)) {
                return match ($m[1]) {
                    'MLB1' => Http::response([
                        'id' => 'MLB1', 'name' => 'Cristaleiras', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB9', 'name' => 'Moveis'], ['id' => 'MLB1', 'name' => 'Cristaleiras']],
                    ]),
                    // Categoria pai (não é folha): só falha na GRAVAÇÃO.
                    'MLB2' => Http::response([
                        'id' => 'MLB2', 'name' => 'Moveis', 'children_categories' => [['id' => 'MLB1', 'name' => 'Cristaleiras']],
                        'path_from_root' => [['id' => 'MLB2', 'name' => 'Moveis']],
                    ]),
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

    /**
     * Gera um .xlsx com as abas pedidas (nome => matriz de linhas) e devolve o caminho.
     * Todo valor vai como texto, exceto `['=num', x]`/números reais passados como int/float.
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
                    } elseif (is_string($valor) && str_starts_with($valor, '=')) {
                        $folha->setCellValueExplicit($ref, $valor, DataType::TYPE_FORMULA);
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
        $caminho = tempnam(sys_get_temp_dir(), 'prod167').'.xlsx';
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    /** @return list<string> os cabeçalhos ANTIGOS: o arquivo de antes de 09/10/2026 continua entrando */
    private function cabecalho(): array
    {
        return self::CABECALHOS_ANTIGOS;
    }

    // ═══ Task 1: modelo e leitor ════════════════════════════════════════════

    public function test_modelo_tem_as_14_colunas_na_ordem_exemplos_ficticios_e_tudo_texto(): void
    {
        $planilha = ModeloProdutosXlsx::gerar();

        $this->assertSame(['Produtos', 'Instruções', 'Listas'], $planilha->getSheetNames());
        $folha = $planilha->getSheetByName('Produtos');

        $this->assertCount(14, ModeloProdutosXlsx::CABECALHOS);
        $this->assertCount(14, ModeloProdutosXlsx::CAMPOS);
        foreach (ModeloProdutosXlsx::CABECALHOS as $i => $titulo) {
            $this->assertSame($titulo, $folha->getCell(Coordinate::stringFromColumnIndex($i + 1).'1')->getValue());
        }
        $this->assertSame('EXEMPLO-1', $folha->getCell('A2')->getValue());
        $this->assertSame('EXEMPLO-2', $folha->getCell('A3')->getValue());

        foreach ($planilha->getAllSheets() as $aba) {
            foreach ($aba->getCellCollection()->getCoordinates() as $coord) {
                $celula = $aba->getCell($coord);
                if ($celula->getValue() === null) {
                    continue; // célula só com formato (as colunas de código são texto até a linha 1.001)
                }
                $this->assertSame(DataType::TYPE_STRING, $celula->getDataType(), "{$aba->getTitle()}!{$coord} deveria ser texto");
            }
        }
        $this->assertNull($folha->getCell('A4')->getValue(), 'só o cabeçalho e as duas linhas de exemplo');
    }

    public function test_modelo_baixado_volta_a_ser_lido_pelo_leitor(): void
    {
        $caminho = $this->gravar(ModeloProdutosXlsx::gerar());

        $r = (new LeitorPlanilhaProdutos())->ler($caminho);

        $this->assertNull($r['erro_geral']);
        $this->assertSame(ModeloProdutosXlsx::CAMPOS, $r['colunas']);
        $this->assertCount(2, $r['linhas']);
        $this->assertSame('EXEMPLO-1', $r['linhas'][0]['bruta']['codigo']);
        $this->assertSame('Sala de Jantar / Sala de Estar', $r['linhas'][0]['bruta']['ambientes']);
        $this->assertSame('Off White', $r['linhas'][1]['bruta']['variacao']);
    }

    public function test_leitor_le_a_aba_produtos_entre_varias_e_a_primeira_quando_nao_ha_produtos(): void
    {
        $linhas = [$this->cabecalho(), ['A1', 'G1', '1', 'Cristaleira Exemplo']];

        $comProdutos = $this->xlsx(['Planejamento' => [['x', 'y']], 'Produtos' => $linhas]);
        $r = (new LeitorPlanilhaProdutos())->ler($comProdutos);
        $this->assertNull($r['erro_geral']);
        $this->assertSame('A1', $r['linhas'][0]['bruta']['codigo']);

        $semProdutos = $this->xlsx(['Dados' => $linhas]);
        $r = (new LeitorPlanilhaProdutos())->ler($semProdutos);
        $this->assertNull($r['erro_geral']);
        $this->assertSame('A1', $r['linhas'][0]['bruta']['codigo']);
    }

    public function test_leitor_mapeia_cabecalhos_por_nome_e_sinonimos_em_qualquer_ordem(): void
    {
        $caminho = $this->xlsx(['Produtos' => [
            ['Nome', 'SKU', 'Volumes', 'Nº volumes', 'Custo (R$)', 'Família'],
            ['Mesa Exemplo', 'M-1', "10\u{00D7}10\u{00D7}10 \u{00B7} 2,5", 1, '12,50', 'Linha X'],
        ]]);

        $r = (new LeitorPlanilhaProdutos())->ler($caminho);

        $this->assertNull($r['erro_geral']);
        $b = $r['linhas'][0]['bruta'];
        $this->assertSame('M-1', $b['codigo']);
        $this->assertSame('Mesa Exemplo', $b['nome']);
        $this->assertSame("10\u{00D7}10\u{00D7}10 \u{00B7} 2,5", $b['volumes_texto']);
        $this->assertSame(1, $b['n_volumes']);
        $this->assertSame('12,50', $b['custo']);
        $this->assertSame('Linha X', $b['familia']);
    }

    public function test_leitor_recusa_planilha_sem_ref_ou_sem_produto_com_a_mensagem_fixa(): void
    {
        $msg = 'Não conseguimos ler este arquivo. Use o modelo (.xlsx) e confira se a aba se chama Produtos.';

        $semRef = $this->xlsx(['Produtos' => [['Produto', 'Família'], ['Mesa', 'X']]]);
        $this->assertSame($msg, (new LeitorPlanilhaProdutos())->ler($semRef)['erro_geral']);

        $semProduto = $this->xlsx(['Produtos' => [['Ref', 'Família'], ['A', 'X']]]);
        $this->assertSame($msg, (new LeitorPlanilhaProdutos())->ler($semProduto)['erro_geral']);
    }

    public function test_leitor_pula_linhas_vazias_e_guarda_o_numero_real_da_linha(): void
    {
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['A1', null, null, 'Mesa'],
            [null, null, null, null],
            ['A2', null, null, 'Cadeira'],
        ]]);

        $r = (new LeitorPlanilhaProdutos())->ler($caminho);

        $this->assertSame([2, 4], array_column($r['linhas'], 'numero'));
    }

    public function test_formula_volta_como_texto_e_nao_e_calculada(): void
    {
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['A1', null, null, 'Mesa', null, null, null, null, null, null, '=1+1'],
        ]]);

        $r = (new LeitorPlanilhaProdutos())->ler($caminho);

        $this->assertSame('=1+1', $r['linhas'][0]['bruta']['custo']);
    }

    /**
     * BE-WR-02: fórmula em coluna de texto usa o valor que o Excel salvou em cache (nada é
     * calculado); sem cache, ou com erro de fórmula, a linha não entra e diz por quê.
     */
    public function test_formula_em_coluna_de_texto_usa_o_valor_salvo_ou_recusa_a_linha(): void
    {
        // Com cache (o writer calcula ao salvar, como o Excel): a Ref vira o resultado.
        $comCache = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['=B2&"-"&C2', '1014', '1', 'Cristaleira'],
            ['M-1', null, null, '=1/0'],
        ]]);
        $r = (new LeitorPlanilhaProdutos())->ler($comCache);
        $this->assertNull($r['erro_geral']);
        $this->assertSame('1014-1', $r['linhas'][0]['bruta']['codigo']);
        $this->assertArrayNotHasKey('erro', $r['linhas'][0]);
        $this->assertSame('Produto com fórmula — cole como valor.', $r['linhas'][1]['erro'], 'erro de fórmula (#DIV/0!) não vira nome');

        // Sem cache (arquivo gerado sem calcular): a linha é recusada, nunca grava "=B2&…".
        $planilha = new Spreadsheet();
        $folha = $planilha->getActiveSheet();
        $folha->setTitle('Produtos');
        $folha->fromArray($this->cabecalho(), null, 'A1');
        $folha->setCellValueExplicit('A2', '=B2&"-"&C2', DataType::TYPE_FORMULA);
        $folha->setCellValueExplicit('B2', '1014', DataType::TYPE_STRING);
        $folha->setCellValueExplicit('D2', 'Cristaleira', DataType::TYPE_STRING);
        $folha->setCellValueExplicit('A3', 'OK-1', DataType::TYPE_STRING);
        $folha->setCellValueExplicit('D3', 'Mesa', DataType::TYPE_STRING);
        $semCache = tempnam(sys_get_temp_dir(), 'prod167').'.xlsx';
        $this->temporarios[] = $semCache;
        $escritor = IOFactory::createWriter($planilha, 'Xlsx');
        $escritor->setPreCalculateFormulas(false);
        $escritor->save($semCache);

        $r = (new LeitorPlanilhaProdutos())->ler($semCache);
        $this->assertSame('Ref com fórmula — cole como valor.', $r['linhas'][0]['erro']);

        $empresa = $this->empresaDoGabarito();
        $previa = $this->importador()->previa($empresa, $semCache);
        $this->assertSame(['novos' => 1, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 1], $previa['totais']);
        $this->assertSame(2, $previa['grupos']['erros'][0]['linha']);
        $this->assertSame('Ref com fórmula — cole como valor.', $previa['grupos']['erros'][0]['motivo']);

        $aplicado = $this->importador()->aplicar($empresa, $semCache, $this->ator($empresa));
        $this->assertSame(1, $aplicado['novos']);
        $this->assertSame(0, EstruturaProdutoVariacao::where('codigo', 'like', '=%')->count());
    }

    public function test_arquivo_que_nao_e_zip_e_arquivo_com_mais_de_mil_linhas_dao_erro_geral(): void
    {
        $falso = tempnam(sys_get_temp_dir(), 'prod167').'.xlsx';
        file_put_contents($falso, "Ref;Produto\nA;B\n");
        $this->temporarios[] = $falso;
        $this->assertNotNull((new LeitorPlanilhaProdutos())->ler($falso)['erro_geral']);

        $linhas = [$this->cabecalho()];
        for ($i = 1; $i <= 1001; $i++) {
            $linhas[] = ["R{$i}", null, null, "Produto {$i}"];
        }
        $grande = $this->xlsx(['Produtos' => $linhas]);
        $this->assertSame(
            'A planilha tem mais de 1.000 linhas. Divida em arquivos menores.',
            (new LeitorPlanilhaProdutos())->ler($grande)['erro_geral']
        );

        $mil = $this->xlsx(['Produtos' => array_slice($linhas, 0, 1001)]);
        $this->assertNull((new LeitorPlanilhaProdutos())->ler($mil)['erro_geral']);
    }

    /**
     * Pacote válido cuja aba Produtos é o XML cru `$sheetData` (cabeçalho em inline string).
     * Serve para montar o arquivo de ataque sem passar pelo writer do PhpSpreadsheet.
     */
    private function xlsxComAbaCrua(string $sheetData, string $dimensao): string
    {
        $caminho = $this->xlsx(['Produtos' => [['Ref']]]);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<dimension ref=\"{$dimensao}\"/><sheetData>{$sheetData}</sheetData></worksheet>";
        $zip = new \ZipArchive();
        $zip->open($caminho);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return $caminho;
    }

    private function celulaTexto(string $ref, string $texto): string
    {
        return "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>{$texto}</t></is></c>";
    }

    /**
     * BE-WR-01: uma célula VAZIA em XFD1048576 num arquivo de poucos KB fazia o `toArray()` tentar
     * 17 bilhões de posições (estouro de memória, fatal). Agora célula vazia não conta e a leitura
     * é esparsa: lê as 2 linhas reais com memória de sobra.
     */
    public function test_arquivo_de_poucos_kb_com_dimensao_enorme_e_lido_sem_estourar_memoria(): void
    {
        $linhas = '<row r="1">'.$this->celulaTexto('A1', 'Ref').$this->celulaTexto('D1', 'Produto').'</row>'
            .'<row r="2">'.$this->celulaTexto('A2', 'A1').$this->celulaTexto('D2', 'Mesa').'</row>'
            .'<row r="3" s="1" customFormat="1"><c r="A3" s="1"/><c r="XFD3" s="1"/></row>'
            .'<row r="1048576"><c r="XFD1048576" s="1"/></row>';
        $caminho = $this->xlsxComAbaCrua($linhas, 'A1:XFD1048576');
        $this->assertLessThan(20 * 1024, filesize($caminho), 'o ataque cabe em poucos KB');

        $antes = memory_get_usage();
        $r = (new LeitorPlanilhaProdutos())->ler($caminho);
        $usado = memory_get_peak_usage() - $antes;

        $this->assertNull($r['erro_geral']);
        $this->assertSame([2], array_column($r['linhas'], 'numero'));
        $this->assertSame('A1', $r['linhas'][0]['bruta']['codigo']);
        $this->assertLessThan(64 * 1024 * 1024, $usado, 'a leitura não monta a dimensão declarada');
    }

    /** BE-WR-01: linhas com valor demais são recusadas pela sondagem, antes de carregar a planilha. */
    public function test_milhares_de_linhas_repetitivas_sao_recusadas_antes_de_carregar(): void
    {
        $cab = '<row r="1">'.$this->celulaTexto('A1', 'Ref').$this->celulaTexto('D1', 'Produto').'</row>';

        $linhas = $cab;
        for ($i = 2; $i <= 5001; $i++) {
            $linhas .= "<row r=\"{$i}\">".$this->celulaTexto("A{$i}", "R{$i}").$this->celulaTexto("D{$i}", 'Mesa').'</row>';
        }
        $r = (new LeitorPlanilhaProdutos())->ler($this->xlsxComAbaCrua($linhas, 'A1:D5001'));
        $this->assertSame('A planilha tem mais de 1.000 linhas. Divida em arquivos menores.', $r['erro_geral']);

        // 300 mil linhas comprimem para pouco mais de 1 MB: o XML da aba passa do teto e nem é sondado.
        $linhas = $cab.str_repeat('<row><c t="inlineStr"><is><t>R</t></is></c><c/><c/><c t="inlineStr"><is><t>Mesa</t></is></c></row>', 300000);
        $caminho = $this->xlsxComAbaCrua($linhas, 'A1:D300001');
        $this->assertLessThan(LeitorPlanilhaProdutos::MAX_BYTES, filesize($caminho));
        $r = (new LeitorPlanilhaProdutos())->ler($caminho);
        $this->assertSame('O arquivo é grande demais para importar. Divida a planilha em arquivos menores.', $r['erro_geral']);
    }

    /** Linhas em branco formatadas (o Google Sheets exporta até a linha 1.000) não contam no limite. */
    public function test_linhas_em_branco_formatadas_nao_contam_no_limite(): void
    {
        $linhas = '<row r="1">'.$this->celulaTexto('A1', 'Ref').$this->celulaTexto('D1', 'Produto').'</row>'
            .'<row r="2">'.$this->celulaTexto('A2', 'A1').$this->celulaTexto('D2', 'Mesa').'</row>';
        for ($i = 3; $i <= 3000; $i++) {
            $linhas .= "<row r=\"{$i}\" s=\"1\" customFormat=\"1\"><c r=\"A{$i}\" s=\"1\"/><c r=\"D{$i}\" s=\"1\"/></row>";
        }
        $linhas .= '<row r="3001">'.$this->celulaTexto('A3001', 'A2').$this->celulaTexto('D3001', 'Cadeira').'</row>';

        $r = (new LeitorPlanilhaProdutos())->ler($this->xlsxComAbaCrua($linhas, 'A1:D3001'));

        $this->assertNull($r['erro_geral']);
        $this->assertSame([2, 3001], array_column($r['linhas'], 'numero'));
    }

    public function test_o_teste_nao_cita_a_planilha_real_do_cliente(): void
    {
        $this->assertStringNotContainsString('3Planejamento'.'_Estrutural', (string) file_get_contents(__FILE__));
    }

    // ═══ Task 2: importador ═════════════════════════════════════════════════

    private function importador(): ImportadorProdutos
    {
        return app(ImportadorProdutos::class);
    }

    private function ator(Company $empresa): AtorDoPortal
    {
        return $this->atorCliente($empresa);
    }

    /** @return array<string, int> */
    private function contagens(): array
    {
        return [
            'produtos'  => EstruturaProduto::count(),
            'variacoes' => EstruturaProdutoVariacao::count(),
            'volumes'   => EstruturaProdutoVolume::count(),
            'ofertas'   => EstruturaOferta::count(),
            'familias'  => EstruturaFamilia::count(),
            'ambientes' => EstruturaAmbiente::count(),
        ];
    }

    private function vol(): string
    {
        return "10\u{00D7}10\u{00D7}10 \u{00B7} 2,5";
    }

    public function test_previa_classifica_novos_atualizados_sem_mudanca_e_erros_sem_gravar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);

        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'E1', 'nome' => 'Existente Um', 'custo' => '10,00'],
            ['codigo' => 'E2', 'nome' => 'Existente Dois', 'custo' => '5,00', 'volumes_texto' => $this->vol()],
        ], $ator);

        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['N1', null, null, 'Novo Um'],
            ['N2', null, null, 'Novo Dois'],
            ['N3', null, null, 'Novo Tres'],
            ['E1', null, null, 'Existente Um', null, null, null, null, null, null, '20,00'],
            ['E2', null, null, 'Existente Dois', null, null, null, null, $this->vol(), null, '5,00'],
            [null, null, null, 'Sem Ref'],
            ['X9', null, null, 'Custo ruim', null, null, null, null, null, null, 'abc'],
        ]]);

        $antes = $this->contagens();
        $r = $this->importador()->previa($empresa, $caminho);

        $this->assertNull($r['erro_geral']);
        $this->assertSame(['novos' => 3, 'atualizados' => 1, 'sem_mudanca' => 1, 'erros' => 2], $r['totais']);
        $this->assertSame(['custo'], $r['grupos']['atualizados'][0]['mudou']);
        $this->assertSame('E1', $r['grupos']['atualizados'][0]['codigo']);
        $this->assertSame([7, 8], array_column($r['grupos']['erros'], 'linha'));
        $this->assertStringContainsString('código', $r['grupos']['erros'][0]['motivo']);
        $this->assertSame($antes, $this->contagens(), 'a prévia não grava nada');
    }

    public function test_previa_limita_o_detalhe_a_200_mas_os_totais_sao_inteiros(): void
    {
        $empresa = $this->empresaDoGabarito();
        $linhas = [$this->cabecalho()];
        for ($i = 1; $i <= 250; $i++) {
            $linhas[] = ["R{$i}", null, null, "Produto {$i}"];
        }

        $r = $this->importador()->previa($empresa, $this->xlsx(['Produtos' => $linhas]));

        $this->assertSame(250, $r['totais']['novos']);
        $this->assertCount(ImportadorProdutos::DETALHE_MAXIMO, $r['grupos']['novos']);
    }

    public function test_previa_lista_familias_e_ambientes_novos_uma_vez_cada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['A1', null, null, 'Mesa', 'Linha Nova', 'Sala Jantar / Sala estar'],
            ['A2', null, null, 'Cadeira', 'linha nova', 'Sala Estar'],
        ]]);

        $r = $this->importador()->previa($empresa, $caminho);

        $this->assertCount(1, $r['criar_listas']['familias']);
        $this->assertCount(2, $r['criar_listas']['ambientes']);
        $this->assertSame(0, EstruturaFamilia::count());
    }

    public function test_avisos_de_volumes_peso_total_e_grupo_com_nomes_diferentes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $dois = "10\u{00D7}10\u{00D7}10 \u{00B7} 2,5 | 5\u{00D7}5\u{00D7}5 \u{00B7} 1";
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['G-1', 'G', '1', 'Mesa Alfa', null, null, null, 3, $dois, 9],
            ['G-2', 'G', '2', 'Mesa Beta'],
        ]]);

        $r = $this->importador()->previa($empresa, $caminho);

        $this->assertSame(0, $r['totais']['erros']);
        $texto = implode(' | ', $r['avisos']);
        $this->assertStringContainsString('Nº volumes (3)', $texto);
        $this->assertStringContainsString('Peso total (9 kg)', $texto);
        $this->assertStringContainsString('usamos o da primeira linha', $texto);
    }

    public function test_aplicar_grava_pelo_servico_da_grade_cria_ofertas_e_registra_o_modo_importacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['C-1', 'C', '1', 'Cristaleira', 'Linha C', 'Sala', null, 2, $this->vol(), null, '1.234,50'],
            ['C-2', 'C', '2', 'Cristaleira'],
            ['S-1', null, 'única', 'Solo'],
            [null, null, null, 'Sem Ref'],
        ]]);

        $r = $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));

        $this->assertSame(['novos' => 3, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 1,
            'nao_entraram' => [['linha' => 5, 'codigo' => null, 'motivo' => 'Informe o código (Ref).']],
            'categorias_confirmadas' => 0, 'categorias_a_confirmar' => 0], $r);
        $this->assertSame(2, EstruturaProduto::count());
        $this->assertSame(3, EstruturaProdutoVariacao::count());
        $this->assertSame(3, EstruturaOferta::whereNotNull('variacao_id')->count());
        $this->assertSame(1234.5, (float) EstruturaProdutoVariacao::where('codigo', 'C-1')->value('custo'));
        $log = Activity::where('log_name', 'portal')->orderByDesc('id')->get()
            ->first(fn ($l) => $l->getExtraProperty('evento') === 'produtos_gravados');
        $this->assertNotNull($log);
        $this->assertSame('importacao', $log->getExtraProperty('modo'));
    }

    /**
     * BE-WR-04: a Ref repetida no arquivo vai para os erros já na prévia (a 2ª sobrescreveria a
     * 1ª), e a linha que só falha na gravação (categoria que não é folha) volta em `nao_entraram`.
     */
    public function test_ref_repetida_no_arquivo_e_erro_de_gravacao_voltam_como_linhas_que_nao_entraram(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['OK-1', null, null, 'Certo', null, null, null, null, null, null, '10,00'],
            ['PAI-1', null, null, 'Categoria pai', null, null, 'MLB2'],
            ['D-1', null, null, 'Primeira', null, null, null, null, null, null, '1,00'],
            ['d-1', null, null, 'Repetida', null, null, null, null, null, null, '2,00'],
        ]]);

        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertSame(['novos' => 3, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 1], $previa['totais']);
        $this->assertSame(5, $previa['grupos']['erros'][0]['linha']);
        $this->assertSame('A Ref d-1 já está na linha 4 do arquivo. Deixe uma linha só para cada Ref.', $previa['grupos']['erros'][0]['motivo']);

        $r = $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));

        $this->assertSame(2, $r['novos']);
        $this->assertSame(2, $r['erros']);
        $this->assertSame([
            ['linha' => 3, 'codigo' => 'PAI-1', 'motivo' => 'Escolha uma categoria mais específica (a última do caminho).'],
            ['linha' => 5, 'codigo' => 'd-1', 'motivo' => 'A Ref d-1 já está na linha 4 do arquivo. Deixe uma linha só para cada Ref.'],
        ], $r['nao_entraram']);
        $this->assertSame(1.0, (float) EstruturaProdutoVariacao::where('codigo', 'D-1')->value('custo'), 'vale a 1ª linha da Ref');
        $this->assertSame(0, EstruturaProdutoVariacao::where('codigo', 'PAI-1')->count());
    }

    /** BE-IN-06: o modelo preenchido sem apagar as linhas 2 e 3 não cria "Mesa de exemplo", família nem ambientes. */
    public function test_linha_de_exemplo_do_modelo_e_ignorada_com_aviso(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = ModeloProdutosXlsx::gerar();
        $planilha->getSheetByName('Produtos')->setCellValueExplicit('A4', 'REAL-1', DataType::TYPE_STRING);
        $planilha->getSheetByName('Produtos')->setCellValueExplicit('E4', 'Produto real', DataType::TYPE_STRING);
        $caminho = $this->gravar($planilha);

        $this->assertSame(ModeloProdutosXlsx::CAMPOS, (new LeitorPlanilhaProdutos())->ler($caminho)['colunas'], 'CAMPOS é o que o leitor deduz dos cabeçalhos');

        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertSame(['novos' => 1, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0], $previa['totais']);
        $this->assertContains('linha 2: é a linha de exemplo do modelo e foi ignorada.', $previa['avisos']);
        $this->assertContains('linha 3: é a linha de exemplo do modelo e foi ignorada.', $previa['avisos']);
        $this->assertSame(['familias' => [], 'ambientes' => []], $previa['criar_listas']);
        $this->assertSame([], $previa['categorias_a_confirmar']['nomes'], 'a categoria do exemplo não vira nome a confirmar');

        $r = $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));
        $this->assertSame(1, $r['novos']);
        $this->assertSame(['REAL-1'], EstruturaProdutoVariacao::pluck('codigo')->all());
        $this->assertSame(0, EstruturaFamilia::count());
        $this->assertSame(0, EstruturaAmbiente::count());

        // Mexeu na linha de exemplo (virou produto de verdade): entra.
        $planilha = ModeloProdutosXlsx::gerar();
        $planilha->getSheetByName('Produtos')->setCellValueExplicit('E2', 'Mesa de verdade', DataType::TYPE_STRING);
        $this->assertSame(1, $this->importador()->previa($empresa, $this->gravar($planilha))['totais']['novos']);

        // A linha de exemplo do modelo ANTIGO (quem baixou antes de 09/10 e não a apagou) também sai.
        $antigo = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['EXEMPLO-1', 'EXEMPLO', 'Cor: Natural', 'Mesa de exemplo', 'Linha Exemplo', 'Sala de Jantar / Sala de Estar', null, '2',
                "120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5", '33,5'],
            ['REAL-2', null, null, 'Outro real'],
        ]]);
        $previa = $this->importador()->previa($empresa, $antigo);
        $this->assertSame(1, $previa['totais']['novos']);
        $this->assertContains('linha 2: é a linha de exemplo do modelo e foi ignorada.', $previa['avisos']);
    }

    public function test_aplicar_refaz_o_plano_variacao_criada_entre_a_previa_e_a_confirmacao_conta_como_atualizada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['P-1', null, null, 'Primeiro', null, null, null, null, null, null, '30,00'],
            ['P-2', null, null, 'Segundo'],
        ]]);

        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertSame(2, $previa['totais']['novos']);

        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [['codigo' => 'P-1', 'nome' => 'Primeiro', 'custo' => '1,00']], $ator);

        $r = $this->importador()->aplicar($empresa, $caminho, $ator);

        $this->assertSame(1, $r['novos']);
        $this->assertSame(1, $r['atualizados']);
        $this->assertSame(2, EstruturaProdutoVariacao::count());
        $this->assertSame(30.0, (float) EstruturaProdutoVariacao::where('codigo', 'P-1')->value('custo'));
    }

    public function test_reimportar_sem_uma_variacao_que_existe_nao_a_apaga_e_celula_em_branco_nao_apaga_dado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'K-1', 'nome' => 'Mantida', 'custo' => '7,00', 'volumes_texto' => $this->vol()],
            ['codigo' => 'K-2', 'nome' => 'Outra', 'custo' => '8,00'],
        ], $ator);

        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['K-1', null, null, 'Mantida'],
        ]]);
        $r = $this->importador()->aplicar($empresa, $caminho, $ator);

        $this->assertSame(0, $r['novos']);
        $this->assertSame(2, EstruturaProdutoVariacao::count());
        $k1 = EstruturaProdutoVariacao::where('codigo', 'K-1')->first();
        $this->assertSame(7.0, (float) $k1->custo);
        $this->assertSame(1, $k1->volumes()->count());
        $this->assertSame(2, EstruturaOferta::whereNotNull('variacao_id')->count());
    }

    /**
     * BE-CR-02 (D-14 "nada é apagado"): depois de escolher a categoria real e digitar as medidas no
     * sistema, reimportar a mesma planilha só para atualizar custo não volta a categoria para "a
     * confirmar" nem apaga as medidas por causa do "SEM MEDIDAS".
     */
    public function test_reimportar_nao_rebaixa_a_categoria_confirmada_nem_apaga_medidas_com_sem_medidas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->ator($empresa);
        $planilha = fn (string $custo, string $catS) => $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['R-1', 'R', '1', 'Cristaleira', null, null, 'Cristaleiras', null, 'SEM MEDIDAS', null, $custo],
            ['S-1', 'S', '1', 'Sapateira', null, null, $catS, null, 'Sem medidas', null, '20,00'],
        ]]);

        $this->importador()->aplicar($empresa, $planilha('10,00', 'Sapateiras'), $ator);
        $r1 = EstruturaProdutoVariacao::where('codigo', 'R-1')->firstOrFail();
        $this->assertSame(EstruturaProduto::CATEGORIA_A_CONFIRMAR, $r1->produto->estadoCategoria());

        // No sistema: a categoria real é escolhida e as medidas, digitadas.
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['id' => $r1->id, 'codigo' => 'R-1', 'nome' => 'Cristaleira', 'categoria_ml_id' => 'MLB1', 'volumes' => [['c' => 93, 'l' => 55, 'a' => 6, 'kg' => 9.5]]],
        ], $ator);
        $this->assertSame(EstruturaProduto::CATEGORIA_CONFIRMADA, $r1->produto->fresh()->estadoCategoria());

        // Reimporta para mudar só o custo da R-1 e o texto da categoria da S-1 (que não tem id).
        $de_novo = $planilha('15,00', 'Sapateiras de Madeira');
        $previa = $this->importador()->previa($empresa, $de_novo);
        $this->assertSame(['novos' => 0, 'atualizados' => 2, 'sem_mudanca' => 0, 'erros' => 0], $previa['totais']);
        $mudou = array_column($previa['grupos']['atualizados'], 'mudou', 'codigo');
        $this->assertSame(['custo'], $mudou['R-1'], 'nome da categoria e SEM MEDIDAS não aparecem como mudança');
        $this->assertSame(['categoria'], $mudou['S-1'], 'sem id, o texto ainda preenche');

        $this->importador()->aplicar($empresa, $de_novo, $ator);

        $r1->refresh();
        $produto = $r1->produto;
        $this->assertSame(15.0, (float) $r1->custo);
        $this->assertSame('MLB1', $produto->categoria_ml_id);
        $this->assertSame('Cristaleiras', $produto->categoria_ml_nome);
        $this->assertSame('Moveis > Cristaleiras', $produto->categoria_ml_caminho);
        $this->assertSame(EstruturaProduto::CATEGORIA_CONFIRMADA, $produto->estadoCategoria());
        $this->assertSame([9.5], $r1->volumes()->pluck('peso')->map(fn ($p) => (float) $p)->all());

        $s1 = EstruturaProdutoVariacao::where('codigo', 'S-1')->firstOrFail()->produto;
        $this->assertSame('Sapateiras de Madeira', $s1->categoria_ml_nome);
        $this->assertSame(EstruturaProduto::CATEGORIA_A_CONFIRMAR, $s1->estadoCategoria());
    }

    public function test_celulas_da_planilha_original_viram_ordem_eixo_volumes_e_categoria(): void
    {
        $empresa = $this->empresaDoGabarito();
        $dois = "186\u{00D7}43\u{00D7}12 \u{00B7} 27.8 | 97\u{00D7}42\u{00D7}12 \u{00B7} 12.1";
        $caminho = $this->xlsx(['Planejamento' => [['x']], 'Produtos' => [
            $this->cabecalho(),
            [1014, 1014, 1, 'Cristaleira Exemplo', 'Linha Y', 'Sala / Cozinha', 'Cristaleiras', 2, $dois, 39.9, 100],
            ['1014-2', 1014, 'Cor: Natural', 'Cristaleira Exemplo', 'Linha Y', 'Sala / Cozinha', 'Cristaleiras', null, 'SEM MEDIDAS'],
            ['U-1', 'U', 'única', 'Mesa Exemplo', null, null, 'MLB1', 1, "120\u{00D7}80\u{00D7}10 \u{00B7} 25"],
        ]]);

        $r = $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));

        $this->assertSame(0, $r['erros']);
        $v1 = EstruturaProdutoVariacao::where('codigo', '1014')->first();
        $this->assertSame(1, $v1->ordem);
        $this->assertSame(2, $v1->volumes()->count());
        $this->assertSame(27.8, (float) $v1->volumes()->first()->peso);
        $this->assertSame(100.0, (float) $v1->custo);

        $v2 = EstruturaProdutoVariacao::where('codigo', '1014-2')->first();
        $this->assertSame('cor', $v2->eixo);
        $this->assertSame('Natural', $v2->valor);
        $this->assertSame(0, $v2->volumes()->count());

        $cristaleira = $v1->produto;
        $this->assertSame(EstruturaProduto::CATEGORIA_A_CONFIRMAR, $cristaleira->estadoCategoria());
        $this->assertSame(['Cozinha', 'Sala'], $cristaleira->ambientes()->pluck('nome')->sort()->values()->all());

        $solo = EstruturaProdutoVariacao::where('codigo', 'U-1')->first();
        $this->assertSame(1, $solo->ordem);
        $this->assertSame(EstruturaProduto::CATEGORIA_CONFIRMADA, $solo->produto->estadoCategoria());
    }

    public function test_formula_no_custo_vai_para_erros_e_nao_e_calculada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->xlsx(['Produtos' => [
            $this->cabecalho(),
            ['F-1', null, null, 'Com formula', null, null, null, null, null, null, '=1+1'],
        ]]);

        $previa = $this->importador()->previa($empresa, $caminho);
        $this->assertSame(1, $previa['totais']['erros']);
        $this->assertSame(0, $previa['totais']['novos']);

        $r = $this->importador()->aplicar($empresa, $caminho, $this->ator($empresa));
        $this->assertSame(1, $r['erros']);
        $this->assertSame(0, EstruturaProdutoVariacao::count());
    }

    public function test_arquivo_invalido_devolve_erro_geral_na_previa_e_na_confirmacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $falso = tempnam(sys_get_temp_dir(), 'prod167').'.xlsx';
        file_put_contents($falso, 'nada de zip');
        $this->temporarios[] = $falso;

        $this->assertNotNull($this->importador()->previa($empresa, $falso)['erro_geral']);
        $this->assertNotNull($this->importador()->aplicar($empresa, $falso, $this->ator($empresa))['erro_geral']);
    }
}
