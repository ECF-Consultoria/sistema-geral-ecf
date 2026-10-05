<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Services\Portal\Estrutura\Produtos\LeitorPlanilhaProdutos;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
class ModeloEImportacaoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporarios = [];

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

    /** @return list<string> */
    private function cabecalho(): array
    {
        return ModeloProdutosXlsx::CABECALHOS;
    }

    // ═══ Task 1: modelo e leitor ════════════════════════════════════════════

    public function test_modelo_tem_as_11_colunas_na_ordem_exemplo_ficticio_e_aba_instrucoes_tudo_texto(): void
    {
        $planilha = ModeloProdutosXlsx::gerar();

        $this->assertSame(['Produtos', 'Instruções'], $planilha->getSheetNames());
        $folha = $planilha->getSheetByName('Produtos');

        $this->assertCount(11, ModeloProdutosXlsx::CABECALHOS);
        $this->assertContains('Grupo (anúncio)', ModeloProdutosXlsx::CABECALHOS);
        foreach (ModeloProdutosXlsx::CABECALHOS as $i => $titulo) {
            $this->assertSame($titulo, $folha->getCell(Coordinate::stringFromColumnIndex($i + 1).'1')->getValue());
        }
        $this->assertSame('EXEMPLO-1', $folha->getCell('A2')->getValue());

        foreach ([$folha, $planilha->getSheetByName('Instruções')] as $aba) {
            foreach ($aba->getCellCollection()->getCoordinates() as $coord) {
                $this->assertSame(DataType::TYPE_STRING, $aba->getCell($coord)->getDataType(), "célula {$coord} deveria ser texto");
            }
        }
        $this->assertFalse($folha->getCellCollection()->has('A3'), 'só o cabeçalho e uma linha de exemplo');
    }

    public function test_modelo_baixado_volta_a_ser_lido_pelo_leitor(): void
    {
        $caminho = $this->gravar(ModeloProdutosXlsx::gerar());

        $r = (new LeitorPlanilhaProdutos())->ler($caminho);

        $this->assertNull($r['erro_geral']);
        $this->assertCount(1, $r['linhas']);
        $this->assertSame('EXEMPLO-1', $r['linhas'][0]['bruta']['codigo']);
        $this->assertSame('Sala de Jantar / Sala de Estar', $r['linhas'][0]['bruta']['ambientes']);
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

    public function test_o_teste_nao_cita_a_planilha_real_do_cliente(): void
    {
        $this->assertStringNotContainsString('3Planejamento'.'_Estrutural', (string) file_get_contents(__FILE__));
    }
}
