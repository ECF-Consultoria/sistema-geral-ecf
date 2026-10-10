<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\EstruturaProdutoVariacao;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Planilha de produtos para o cliente preencher fora do sistema (D-13) — versão 2, 09/10/2026.
 *
 * Três abas: "Produtos" (uma linha por variação), "Instruções" e "Listas" (oculta: os tipos de
 * variação e as famílias e ambientes que a empresa JÁ cadastrou, fonte das validações em lista).
 * Sai vazia, com duas linhas de exemplo fictícias (`gerar()` sem linhas), ou preenchida com o que
 * a empresa já tem (`PlanilhaDosProdutos::exportar`), para editar e enviar de novo.
 *
 * ### Sigilo (decisão do usuário, 09/10/2026)
 * O cliente não sabe para onde vai o cadastro: nada daqui cita a plataforma de venda — nem
 * cabeçalho, nem instrução, nem nome de aba, nem lista, nem mensagem de validação. O modelo de
 * antes (os cabeçalhos da aba Produtos da 3Planejamento, com "Grupo (anúncio)" e a Variação
 * ordinal) continua sendo LIDO pelo `LeitorPlanilhaProdutos`, que casa a coluna pelo nome; ele só
 * não é mais escrito. Há teste que varre abas, células, listas e validações atrás dos termos.
 *
 * Toda célula é gravada como texto explícito: evita notação científica em código numérico e
 * impede que um valor vire fórmula (T-167-37) — inclusive nome de família vindo da empresa.
 */
final class ModeloProdutosXlsx
{
    public const ABA_PRODUTOS   = 'Produtos';
    public const ABA_INSTRUCOES = 'Instruções';
    public const ABA_LISTAS     = 'Listas';

    public const CABECALHOS = [
        'Ref*',
        'Produto (grupo)*',
        'Tipo de variação',
        'Variação',
        'Nome do produto*',
        'Família (linha/coleção)',
        'Ambiente(s)',
        'Categoria',
        'Nº volumes',
        "Volumes (C\u{00D7}L\u{00D7}A cm \u{00B7} kg)",
        'Peso total (kg)',
        'Custo (R$)',
        'Estoque (un.)',
        'Descrição',
    ];

    /**
     * Campo lógico de cada coluna, na ordem de CABECALHOS (o mesmo que o leitor deduz pelo nome).
     * `eixo` é o "Tipo de variação" e `variacao` o nome dela ("Natural").
     */
    public const CAMPOS = [
        'codigo', 'grupo', 'eixo', 'variacao', 'nome', 'familia', 'ambientes', 'categoria',
        'n_volumes', 'volumes_texto', 'peso_total', 'custo', 'estoque', 'descricao',
    ];

    /**
     * As linhas 2 e 3 do modelo vazio: um produto fictício em duas cores, para mostrar o grupo e o
     * nome da variação. A importação ignora (com aviso) a linha idêntica a uma delas (BE-IN-06).
     */
    public const EXEMPLOS = [
        [
            'codigo' => 'EXEMPLO-1', 'grupo' => 'EXEMPLO', 'eixo' => 'Cor', 'variacao' => 'Natural',
            'nome' => 'Mesa de exemplo', 'familia' => 'Linha Exemplo', 'ambientes' => 'Sala de Jantar / Sala de Estar',
            'categoria' => 'Mesa de jantar', 'n_volumes' => '2',
            'volumes_texto' => "120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5",
            'peso_total' => '33,5', 'custo' => '1.234,50', 'estoque' => '10',
            'descricao' => 'Mesa retangular de madeira para seis lugares.',
        ],
        [
            'codigo' => 'EXEMPLO-2', 'grupo' => 'EXEMPLO', 'eixo' => 'Cor', 'variacao' => 'Off White',
            'nome' => 'Mesa de exemplo', 'familia' => 'Linha Exemplo', 'ambientes' => 'Sala de Jantar / Sala de Estar',
            'categoria' => 'Mesa de jantar', 'n_volumes' => '2',
            'volumes_texto' => "120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5",
            'peso_total' => '33,5', 'custo' => '1.234,50', 'estoque' => '0',
            'descricao' => '',
        ],
    ];

    /**
     * A linha de exemplo do modelo de ANTES de 09/10/2026 (lida pelos cabeçalhos antigos): quem
     * baixou aquele arquivo e não a apagou continua com ela ignorada. Nunca é escrita.
     */
    public const EXEMPLO_ANTIGO = [
        'codigo' => 'EXEMPLO-1', 'grupo' => 'EXEMPLO', 'variacao' => 'Cor: Natural', 'nome' => 'Mesa de exemplo',
        'familia' => 'Linha Exemplo', 'ambientes' => 'Sala de Jantar / Sala de Estar', 'n_volumes' => '2',
        'volumes_texto' => "120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5", 'peso_total' => '33,5',
    ];

    private const LARGURAS = [14, 18, 17, 18, 32, 24, 30, 24, 11, 42, 15, 13, 13, 60];

    /** Colunas de código: formato texto para "0012" não virar 12 nem "1014" virar número. */
    private const COLUNAS_TEXTO = ['codigo', 'grupo', 'variacao'];

    /**
     * @param  array{familias?: list<string>, ambientes?: list<string>}  $listas  nomes já cadastrados na empresa
     * @param  list<array<string, string|null>>|null  $linhas  linhas por campo; null = o modelo vazio, com os exemplos
     */
    public static function gerar(array $listas = [], ?array $linhas = null): Spreadsheet
    {
        $planilha = new Spreadsheet();
        $planilha->getProperties()->setCreator('ECF')->setLastModifiedBy('ECF')->setTitle('Produtos')
            ->setSubject('')->setDescription('')->setKeywords('')->setCategory('');

        $folha = $planilha->getActiveSheet();
        $folha->setTitle(self::ABA_PRODUTOS);
        $preenchida = $linhas !== null;
        $dados = $linhas ?? self::EXEMPLOS;

        foreach (self::CABECALHOS as $i => $titulo) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            self::texto($folha, $col.'1', $titulo);
            $folha->getColumnDimension($col)->setWidth(self::LARGURAS[$i]);
        }
        foreach (array_values($dados) as $n => $linha) {
            foreach (self::CAMPOS as $i => $campo) {
                $valor = trim((string) ($linha[$campo] ?? ''));
                if ($valor !== '') {
                    self::texto($folha, Coordinate::stringFromColumnIndex($i + 1).($n + 2), $valor);
                }
            }
        }

        $ultima = Coordinate::stringFromColumnIndex(count(self::CABECALHOS));
        $folha->getStyle("A1:{$ultima}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $folha->getRowDimension(1)->setRowHeight(22);
        $folha->freezePane('A2');

        // Até onde valem formato e validações: as 1.000 linhas que a importação aceita, ou mais,
        // quando a planilha baixada já vem com mais produtos que isso.
        $fim = max(LeitorPlanilhaProdutos::MAX_LINHAS + 1, count($dados) + 1);
        foreach (self::COLUNAS_TEXTO as $campo) {
            $col = self::coluna($campo);
            $folha->getStyle("{$col}2:{$col}{$fim}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        // Ordem das abas: Produtos, Instruções e, por último, a oculta.
        self::abaInstrucoes($planilha, $preenchida);
        $nomesListas = self::abaListas($planilha, $listas);
        self::validacoes($folha, $nomesListas, $fim);

        $planilha->setActiveSheetIndex(0);

        return $planilha;
    }

    /** Letra da coluna de um campo do modelo. */
    public static function coluna(string $campo): string
    {
        $i = array_search($campo, self::CAMPOS, true);

        return Coordinate::stringFromColumnIndex(($i === false ? 0 : $i) + 1);
    }

    // ═══ Abas de apoio ══════════════════════════════════════════════════════

    /**
     * A aba oculta das listas. Coluna A: os tipos de variação (os eixos do cadastro); B: as famílias
     * da empresa; C: os ambientes da empresa.
     *
     * @return array{tipos: int, familias: int, ambientes: int} quantos itens cada lista tem
     */
    private static function abaListas(Spreadsheet $planilha, array $listas): array
    {
        $aba = $planilha->createSheet();
        $aba->setTitle(self::ABA_LISTAS);

        $colunas = [
            'A' => ['Tipo de variação', array_values(EstruturaProdutoVariacao::EIXOS)],
            'B' => ['Família', self::nomes($listas['familias'] ?? [])],
            'C' => ['Ambiente', self::nomes($listas['ambientes'] ?? [])],
        ];
        $contagem = [];
        foreach ($colunas as $col => [$titulo, $itens]) {
            self::texto($aba, "{$col}1", $titulo);
            foreach ($itens as $n => $nome) {
                self::texto($aba, $col.($n + 2), $nome);
            }
            $aba->getColumnDimension($col)->setWidth(28);
            $contagem[$col] = count($itens);
        }
        $aba->getStyle('A1:C1')->getFont()->setBold(true);
        $aba->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        return ['tipos' => $contagem['A'], 'familias' => $contagem['B'], 'ambientes' => $contagem['C']];
    }

    /**
     * Tipo de variação só da lista (o leitor ainda aceita outro texto, como "Outro" com aviso);
     * família e ambiente SUGEREM a lista da empresa mas aceitam nome novo (ele é criado na
     * importação) e vários ambientes separados por "/"; estoque, inteiro de 0 para cima.
     */
    private static function validacoes(Worksheet $folha, array $listas, int $fim): void
    {
        $intervalo = fn (string $campo) => self::coluna($campo)."2:".self::coluna($campo).$fim;

        $tipo = self::lista(self::ABA_LISTAS.'!$A$2:$A$'.($listas['tipos'] + 1));
        $tipo->setErrorStyle(DataValidation::STYLE_STOP);
        $tipo->setShowErrorMessage(true);
        $tipo->setErrorTitle('Tipo de variação');
        $tipo->setError('Escolha da lista: '.self::emTexto(array_values(EstruturaProdutoVariacao::EIXOS)).'.');
        $folha->setDataValidation($intervalo('eixo'), $tipo);

        if ($listas['familias'] > 0) {
            $folha->setDataValidation($intervalo('familia'), self::lista(self::ABA_LISTAS.'!$B$2:$B$'.($listas['familias'] + 1)));
        }
        if ($listas['ambientes'] > 0) {
            $folha->setDataValidation($intervalo('ambientes'), self::lista(self::ABA_LISTAS.'!$C$2:$C$'.($listas['ambientes'] + 1)));
        }

        $estoque = new DataValidation();
        $estoque->setType(DataValidation::TYPE_WHOLE);
        $estoque->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL);
        $estoque->setFormula1('0');
        $estoque->setAllowBlank(true);
        $estoque->setErrorStyle(DataValidation::STYLE_STOP);
        $estoque->setShowErrorMessage(true);
        $estoque->setErrorTitle('Estoque');
        $estoque->setError('Use unidades inteiras: 0 ou mais.');
        $folha->setDataValidation($intervalo('estoque'), $estoque);
    }

    /** Lista com a seta na célula; sem mensagem de erro (aceita texto fora dela) até alguém pedir o contrário. */
    private static function lista(string $formula): DataValidation
    {
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST);
        $v->setFormula1($formula);
        $v->setAllowBlank(true);
        $v->setShowDropDown(true);
        $v->setShowErrorMessage(false);

        return $v;
    }

    private static function abaInstrucoes(Spreadsheet $planilha, bool $preenchida): void
    {
        $aba = $planilha->createSheet();
        $aba->setTitle(self::ABA_INSTRUCOES);
        $aba->getColumnDimension('A')->setWidth(120);

        $abertura = $preenchida
            ? 'Esta planilha traz os produtos que você já cadastrou. Edite, acrescente linhas e envie de novo.'
            : 'Uma linha por variação (cada cor, tamanho…). As linhas 2 e 3 são só exemplo: apague ou escreva por cima.';

        $linhas = [
            'Como preencher a aba Produtos',
            $abertura,
            'Os campos com * são obrigatórios. Uma linha por variação.',
            'Ref*: o código da variação, único na sua empresa. É por ele que enviar a planilha de novo atualiza o que já existe.',
            'Produto (grupo)*: o código que junta as variações de um mesmo produto. Repita o mesmo código em todas as variações dele.',
            'Tipo de variação: '.self::emTexto(array_values(EstruturaProdutoVariacao::EIXOS)).'. Em branco quando o produto não tem variação.',
            'Variação: o nome da variação, como Natural ou Off White. Dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto.',
            'Nome do produto*, Família, Ambiente(s), Categoria e Descrição são do produto: valem os da primeira linha dele.',
            'Família (linha/coleção): um nome só. Ambiente(s): separe vários com "/". O que ainda não existe é criado na lista.',
            'Categoria: o tipo do produto, como Mesa de jantar. Depois de enviar, você confirma a categoria de cada nome.',
            "Volumes: C\u{00D7}L\u{00D7}A \u{00B7} kg, uma caixa por vez, separadas por \"|\". Exemplo: 120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5. Escreva SEM MEDIDAS se ainda não tem as medidas.",
            'Custo (R$): em reais, como 1.234,50. Estoque (un.): unidades inteiras; 0 quer dizer sem estoque.',
            'Descrição: o texto que apresenta o produto. Basta na primeira linha dele.',
            'Enviar de novo acrescenta e atualiza pelo Ref. Célula em branco não apaga nada.',
            'Limites: arquivo .xlsx de até 2 MB e '.number_format(LeitorPlanilhaProdutos::MAX_LINHAS, 0, ',', '.').' linhas.',
        ];
        foreach ($linhas as $i => $linha) {
            self::texto($aba, 'A'.($i + 1), $linha);
        }
        $aba->getStyle('A1')->getFont()->setBold(true);
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** @return list<string> nomes limpos, sem vazios */
    private static function nomes(array $itens): array
    {
        $saida = [];
        foreach ($itens as $item) {
            $nome = is_array($item) ? (string) ($item['nome'] ?? '') : (string) $item;
            $nome = ListasDaEmpresaService::limpar($nome);
            if ($nome !== '') {
                $saida[] = $nome;
            }
        }

        return $saida;
    }

    /** "Cor, Tamanho, … ou Outro". */
    private static function emTexto(array $itens): string
    {
        $ultimo = array_pop($itens);

        return $itens === [] ? (string) $ultimo : implode(', ', $itens).' ou '.$ultimo;
    }

    private static function texto(Worksheet $folha, string $celula, string $valor): void
    {
        // Texto explícito: nunca fórmula, nunca notação científica.
        $folha->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }
}
