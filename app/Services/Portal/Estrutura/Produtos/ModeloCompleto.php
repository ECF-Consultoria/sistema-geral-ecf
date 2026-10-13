<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * O MODELO COMPLETO da planilha (13/10/2026): o produto e a ficha técnica dele na MESMA linha.
 *
 * O pedido do usuário: "preciso dela completa; o problema é que a ficha técnica vem de acordo com a
 * categoria". Até aqui eram dois arquivos em dois tempos — o de Produtos (sem ficha) e, só depois de
 * cadastrar e confirmar a categoria, o da Ficha técnica. Uma planilha não consulta nada sozinha e cada
 * categoria tem colunas diferentes; por isso a categoria é escolhida ANTES de baixar (pelo nome do
 * produto ou navegando), como nos modelos por tipo de produto de outros marketplaces.
 *
 * ### O arquivo
 * Uma aba por categoria escolhida, com o nome dela. Em cada aba, as colunas do produto (as do modelo
 * de Produtos, sem a "Categoria" — a aba já é a categoria — e com o grupo na frente) e, depois, uma
 * coluna por campo da ficha da categoria, com as mesmas listas, "*" e unidades da planilha da ficha
 * ({@see PlanilhaDasFichasTecnicas::colunasDaFicha()}). Mais "Instruções" e duas ocultas: "Listas"
 * (as opções) e "Categorias" (qual categoria é cada aba).
 *
 * ### Sigilo
 * Nada cita a plataforma. A aba "Categorias" guarda só os NÚMEROS do código da categoria (sem as
 * letras do prefixo) e o nome dela; {@see self::categoriasDoArquivo()} devolve o código inteiro.
 *
 * A importação é {@see ImportadorModeloCompleto}: as duas que já existiam, na ordem certa.
 */
class ModeloCompleto
{
    public const ABA_CATEGORIAS = 'Categorias';
    public const ABA_INSTRUCOES = 'Instruções';

    /** A1 da aba oculta: é por ela que a importação reconhece o modelo. */
    public const MARCA = 'modelo-completo-v1';

    public const MAX_CATEGORIAS = 10;

    /** Prefixo do código da categoria, tirado na ida e devolvido na volta (o arquivo nunca o carrega). */
    private const PREFIXO = 'MLB';

    /** As colunas do produto, na ordem do modelo completo: o grupo na frente, sem a Categoria. */
    public const CAMPOS_DO_PRODUTO = [
        'grupo', 'codigo', 'eixo', 'variacao', 'nome', 'familia', 'ambientes',
        'n_volumes', 'volumes_texto', 'peso_total', 'custo', 'estoque', 'descricao',
    ];

    private const LARGURAS = ['grupo' => 18, 'codigo' => 14, 'eixo' => 17, 'variacao' => 18, 'nome' => 32, 'familia' => 24, 'ambientes' => 28,
        'n_volumes' => 11, 'volumes_texto' => 42, 'peso_total' => 15, 'custo' => 13, 'estoque' => 13, 'descricao' => 50];

    public function __construct(
        private FichaTecnicaDaCategoria $definicao,
        private CategoriaSugestaoService $categorias,
        private PlanilhaDasFichasTecnicas $fichas,
    ) {}

    /** O cabeçalho de cada coluna do produto (o mesmo texto do modelo de Produtos). */
    public static function cabecalhosDoProduto(): array
    {
        $porCampo = array_combine(ModeloProdutosXlsx::CAMPOS, ModeloProdutosXlsx::CABECALHOS);

        return array_map(fn (string $campo) => $porCampo[$campo], self::CAMPOS_DO_PRODUTO);
    }

    /**
     * @param  list<string>  $ids  códigos das categorias escolhidas (só a última de cada ramo serve)
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: string, nome: string, aba: string, campos: int}>, recusadas: list<array{id: string, motivo: string}>}
     */
    public function gerar(array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_map(fn ($i) => mb_strtoupper(trim((string) $i)), $ids))), 0, self::MAX_CATEGORIAS);
        $detalhes = $ids === [] ? [] : $this->categorias->detalhes($ids);

        $planilha = new Spreadsheet();
        $planilha->getProperties()->setCreator('ECF')->setLastModifiedBy('ECF')->setTitle('Produtos e ficha técnica')
            ->setSubject('')->setDescription('')->setKeywords('')->setCategory('');
        $planilha->removeSheetByIndex(0);

        $listas = new Worksheet($planilha, PlanilhaDasFichasTecnicas::ABA_LISTAS);
        $colunaDaLista = 0;
        $usados = [];
        $abas = [];
        $recusadas = [];
        $fim = LeitorPlanilhaProdutos::MAX_LINHAS + 1;
        $doProduto = self::cabecalhosDoProduto();

        foreach ($ids as $id) {
            $d = $detalhes[$id] ?? null;
            if ($d === null) {
                $recusadas[] = ['id' => $id, 'motivo' => 'Não achamos esta categoria agora.'];
                continue;
            }
            if (! $d['folha']) {
                $recusadas[] = ['id' => $id, 'motivo' => "“{$d['nome']}” ainda tem categorias dentro dela. Escolha uma mais específica."];
                continue;
            }
            try {
                $campos = array_values(FichaTecnicaDaCategoria::camposPorId($this->definicao->definicao($id)));
            } catch (\Throwable $e) {
                Log::warning("[Estrutura Produtos] ficha da categoria {$id} indisponível para o modelo completo", ['erro' => $e->getMessage()]);
                $campos = [];
            }
            if ($campos === []) {
                $recusadas[] = ['id' => $id, 'motivo' => "A ficha técnica de “{$d['nome']}” não está disponível agora. Tente de novo em instantes."];
                continue;
            }

            $nomeDaAba = PlanilhaDasFichasTecnicas::nomeDeAba($d['nome'], $usados);
            $usados[] = $nomeDaAba;
            $aba = new Worksheet($planilha, $nomeDaAba);
            $planilha->addSheet($aba);

            foreach (self::CAMPOS_DO_PRODUTO as $i => $campo) {
                $coluna = Coordinate::stringFromColumnIndex($i + 1);
                self::texto($aba, $coluna.'1', $doProduto[$i]);
                $aba->getColumnDimension($coluna)->setWidth(self::LARGURAS[$campo]);
                if (in_array($campo, ['grupo', 'codigo', 'variacao'], true)) {
                    $aba->getStyle("{$coluna}2:{$coluna}{$fim}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
            }
            $colunaDoEixo = Coordinate::stringFromColumnIndex(array_search('eixo', self::CAMPOS_DO_PRODUTO, true) + 1);
            $aba->setDataValidation("{$colunaDoEixo}2:{$colunaDoEixo}{$fim}", self::listaDeTipos());

            $primeiraDaFicha = count(self::CAMPOS_DO_PRODUTO) + 1;
            $this->fichas->colunasDaFicha($aba, $campos, $primeiraDaFicha, $fim, $listas, $colunaDaLista);

            $ultima = Coordinate::stringFromColumnIndex($primeiraDaFicha + count($campos) - 1);
            $ultimaDoProduto = Coordinate::stringFromColumnIndex($primeiraDaFicha - 1);
            $primeiraFicha = Coordinate::stringFromColumnIndex($primeiraDaFicha);
            $estilo = fn (string $cor) => [
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $cor]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ];
            // Dois tons no cabeçalho: os dados do produto e, mais claro, a ficha técnica da categoria.
            $aba->getStyle("A1:{$ultimaDoProduto}1")->applyFromArray($estilo('1F2430'));
            $aba->getStyle("{$primeiraFicha}1:{$ultima}1")->applyFromArray($estilo('3B4257'));
            $aba->getRowDimension(1)->setRowHeight(32);
            $aba->freezePane('C2');

            $abas[] = ['id' => $id, 'nome' => $d['nome'], 'aba' => $nomeDaAba, 'campos' => count($campos),
                'caminho' => implode(' > ', array_column($d['caminho'], 'nome'))];
        }

        if ($abas === []) {
            return ['planilha' => null, 'abas' => [], 'recusadas' => $recusadas];
        }

        $instrucoes = new Worksheet($planilha, self::ABA_INSTRUCOES);
        $planilha->addSheet($instrucoes);
        self::preencherInstrucoes($instrucoes, count($abas));

        $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $planilha->addSheet($listas);

        $mapa = new Worksheet($planilha, self::ABA_CATEGORIAS);
        $planilha->addSheet($mapa);
        self::texto($mapa, 'A1', self::MARCA);
        foreach ($abas as $n => $a) {
            $linha = $n + 2;
            self::texto($mapa, "A{$linha}", $a['aba']);
            self::texto($mapa, "B{$linha}", substr($a['id'], strlen(self::PREFIXO)));
            self::texto($mapa, "C{$linha}", $a['nome']);
            self::texto($mapa, "D{$linha}", $a['caminho']);
        }
        $mapa->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $planilha->setActiveSheetIndex(0);

        return ['planilha' => $planilha, 'abas' => array_map(fn ($a) => array_diff_key($a, ['caminho' => 1]), $abas), 'recusadas' => $recusadas];
    }

    /**
     * As categorias de um arquivo do modelo completo: nome da aba => {id, nome}. Vazio = não é o
     * modelo completo (ou a aba oculta foi apagada). Só lê a aba "Categorias".
     *
     * @return array<string, array{id: string, nome: string}>
     */
    public static function categoriasDoArquivo(string $caminho): array
    {
        try {
            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $leitor->setLoadSheetsOnly([self::ABA_CATEGORIAS]);
            $planilha = $leitor->load($caminho);
            $folha = $planilha->getSheetByName(self::ABA_CATEGORIAS);
            if ($folha === null || trim((string) $folha->getCell('A1')->getValue()) !== self::MARCA) {
                return [];
            }

            $saida = [];
            $ultima = min((int) $folha->getHighestDataRow(), self::MAX_CATEGORIAS + 1);
            for ($linha = 2; $linha <= $ultima; $linha++) {
                $aba = trim((string) $folha->getCell("A{$linha}")->getValue());
                $numero = trim((string) $folha->getCell("B{$linha}")->getValue());
                if ($aba !== '' && preg_match('/^\d{1,17}$/D', $numero) === 1) {
                    $saida[$aba] = ['id' => self::PREFIXO.$numero, 'nome' => trim((string) $folha->getCell("C{$linha}")->getValue())];
                }
            }
            $planilha->disconnectWorksheets();

            return $saida;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function listaDeTipos(): DataValidation
    {
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST);
        $v->setAllowBlank(true);
        $v->setShowDropDown(true);
        $v->setShowInputMessage(true);
        $v->setPromptTitle('Tipo de variação');
        $v->setPrompt('Por onde o produto varia. Deixe em branco se não varia.');
        $v->setFormula1('"'.implode(',', array_values(EstruturaProdutoVariacao::EIXOS)).'"');

        return $v;
    }

    private static function preencherInstrucoes(Worksheet $aba, int $categorias): void
    {
        $linhas = [
            'Como preencher',
            $categorias === 1
                ? 'A primeira aba é a categoria que você escolheu. Todos os produtos dela vão nessa aba.'
                : 'Cada aba é uma das categorias que você escolheu. Ponha cada produto na aba da categoria dele.',
            'Uma linha por variação do produto (cada cor ou tamanho é uma linha). Produto sem variação ocupa uma linha só.',
            'Produto (grupo)*: o código que junta as variações de um mesmo produto. Repita o mesmo código em todas as linhas dele.',
            'Ref*: o código de cada variação. Não pode repetir.',
            "Volumes: comprimento\u{00D7}largura\u{00D7}altura em cm e o peso em kg, assim: 120\u{00D7}80\u{00D7}10 \u{00B7} 25,0. Mais de um volume: separe com | (120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5).",
            'As colunas de cabeçalho mais claro são a ficha técnica da categoria. Preencha na PRIMEIRA linha do produto; nas outras variações pode ficar em branco.',
            'Coluna com * é obrigatória. Onde houver lista, escolha uma opção; em algumas listas você também pode escrever o seu valor.',
            'Para marcar mais de uma opção num campo, separe com | . Para dizer que o campo não vale para o produto, escreva: Não se aplica.',
            'Célula em branco não apaga nada do que já está cadastrado. Enviar de novo atualiza pelo código da variação.',
            'Não mude o nome das abas nem dos cabeçalhos. As fotos entram depois, em "Enviar fotos em lote".',
        ];
        foreach ($linhas as $n => $texto) {
            self::texto($aba, 'A'.($n + 1), $texto);
        }
        $aba->getStyle('A1')->getFont()->setBold(true);
        $aba->getColumnDimension('A')->setWidth(140);
        $aba->getStyle('A1:A'.count($linhas))->getAlignment()->setWrapText(true);
    }

    private static function texto(Worksheet $folha, string $celula, string $valor): void
    {
        $folha->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }
}
