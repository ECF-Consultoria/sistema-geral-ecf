<?php

namespace App\Services\Portal\Estrutura\Produtos;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Planilha-modelo para o cliente preencher fora do sistema (D-13).
 *
 * As 11 colunas são as da aba "Produtos" da planilha 3Planejamento, com os
 * mesmos nomes e a mesma ordem — quem já tem a planilha original importa sem
 * reformatar. A linha de exemplo é FICTÍCIA (nenhum dado de cliente).
 *
 * Toda célula é gravada como texto explícito: evita notação científica em
 * código numérico e impede que um valor vire fórmula (T-167-37).
 */
final class ModeloProdutosXlsx
{
    public const CABECALHOS = [
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

    private const EXEMPLO = [
        'EXEMPLO-1',
        'EXEMPLO',
        'Cor: Natural',
        'Mesa de exemplo',
        'Linha Exemplo',
        'Sala de Jantar / Sala de Estar',
        '',
        '2',
        "120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5",
        '33,5',
        '',
    ];

    public static function gerar(): Spreadsheet
    {
        $planilha = new Spreadsheet();
        $folha = $planilha->getActiveSheet();
        $folha->setTitle('Produtos');

        $largura = [14, 18, 16, 30, 20, 30, 20, 11, 42, 15, 12];

        foreach (self::CABECALHOS as $i => $titulo) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            self::texto($folha, $col.'1', $titulo);
            self::texto($folha, $col.'2', self::EXEMPLO[$i]);
            $folha->getColumnDimension($col)->setWidth($largura[$i]);
        }

        $ultima = Coordinate::stringFromColumnIndex(count(self::CABECALHOS));
        $folha->getStyle("A1:{$ultima}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $folha->getRowDimension(1)->setRowHeight(22);
        $folha->freezePane('A2');

        $instrucoes = $planilha->createSheet();
        $instrucoes->setTitle('Instruções');
        $instrucoes->getColumnDimension('A')->setWidth(110);

        $linhas = [
            'Como preencher a aba Produtos',
            'Uma linha por variação. A linha 2 é só um exemplo: apague ou substitua.',
            'Ref: código da variação. Único por empresa. É por ele que reimportar atualiza.',
            'Grupo (anúncio): junta as variações do mesmo produto. Linhas com o mesmo grupo viram um produto.',
            'Variação: aceita 1, 2, única ou "Cor: Natural". Eixos: Cor, Tamanho, Voltagem, Material, Sabor, Outro.',
            'Família: um nome só. Se ainda não existe, criamos na lista.',
            'Ambiente: separe vários com "/". Se ainda não existe, criamos na lista.',
            "Volumes: \"C\u{00D7}L\u{00D7}A \u{00B7} kg\", um por caixa, separados por \"|\". Exemplo: 120\u{00D7}80\u{00D7}10 \u{00B7} 25,0 | 80\u{00D7}40\u{00D7}10 \u{00B7} 8,5.",
            'Escreva SEM MEDIDAS quando o produto ainda não tem medidas. Ao reimportar, SEM MEDIDAS não apaga as medidas já cadastradas.',
            'Custo: em reais, como 1.234,50.',
            'Categoria ML: o nome ou o código MLB da categoria. Nome fica "a confirmar" até alguém escolher a categoria. O nome não troca uma categoria já escolhida no sistema.',
            'Reimportar acrescenta e atualiza pelo Ref. Nada é apagado.',
            'Limites: arquivo .xlsx de até 2 MB e 1.000 linhas.',
        ];
        foreach ($linhas as $i => $linha) {
            self::texto($instrucoes, 'A'.($i + 1), $linha);
        }
        $instrucoes->getStyle('A1')->getFont()->setBold(true);

        $planilha->setActiveSheetIndex(0);

        return $planilha;
    }

    private static function texto($folha, string $celula, string $valor): void
    {
        // Texto explícito: nunca fórmula, nunca notação científica.
        $folha->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }
}
