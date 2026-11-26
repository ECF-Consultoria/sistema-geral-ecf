<?php

namespace App\Support;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planilha .xlsx de uma aba só, com cabeçalho escuro, AutoFiltro ligado e
 * cabeçalho congelado — o formato que o time abre no Excel/Sheets e filtra.
 *
 * Mesmo desenho da exportação do Painel Polos (PolosController::streamXlsx),
 * mas genérico: cada coluna diz o próprio tipo e largura.
 *
 * Coluna: ['key' => 'data', 'label' => 'Data', 'tipo' => 'data', 'largura' => 12]
 *   tipo: texto (padrão) | texto_longo (quebra linha) | data (Y-m-d) |
 *         datahora (Y-m-d H:i) | inteiro
 *
 * Linha: array associativo pelas `key`. Link opcional por célula em
 * `$linha['_links'][key] = url` — a célula vira hiperlink clicável.
 */
class PlanilhaXlsx
{
    /**
     * @param array<int,array{key:string,label:string,tipo?:string,largura?:int}> $colunas
     * @param array<int,array<string,mixed>>                                     $linhas
     */
    public static function download(array $colunas, array $linhas, string $nomeArquivo, string $nomeAba, string $congelar = 'A2'): StreamedResponse
    {
        $planilha = self::montar($colunas, $linhas, $nomeAba, $congelar);
        $writer   = new Xlsx($planilha);

        return response()->streamDownload(function () use ($writer, $planilha) {
            $writer->save('php://output');
            $planilha->disconnectWorksheets();
        }, $nomeArquivo, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /** Separado do download para o teste conseguir ler a planilha montada. */
    public static function montar(array $colunas, array $linhas, string $nomeAba, string $congelar = 'A2'): Spreadsheet
    {
        $planilha = new Spreadsheet();
        $folha    = $planilha->getActiveSheet();
        // Excel recusa nome de aba com : \ / ? * [ ] e acima de 31 caracteres.
        $folha->setTitle(mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $nomeAba), 0, 31));

        $letra = fn (int $i) => Coordinate::stringFromColumnIndex($i + 1);

        foreach ($colunas as $i => $col) {
            $folha->setCellValue($letra($i) . '1', $col['label']);
        }

        $ultimaLetra = $letra(count($colunas) - 1);
        $ultimaLinha = count($linhas) + 1;

        $folha->getStyle("A1:{$ultimaLetra}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $folha->getRowDimension(1)->setRowHeight(22);

        foreach ($linhas as $l => $linha) {
            $r = $l + 2;
            foreach ($colunas as $i => $col) {
                $valor = $linha[$col['key']] ?? null;
                if ($valor === null || $valor === '') {
                    continue; // célula vazia filtra melhor que um "—"
                }

                $celula = $letra($i) . $r;
                $tipo   = $col['tipo'] ?? 'texto';

                if ($tipo === 'data' || $tipo === 'datahora') {
                    // Número de série do Excel: só assim filtro e ordenação de data funcionam.
                    $folha->setCellValue($celula, ExcelDate::PHPToExcel(Carbon::parse($valor)));
                } elseif ($tipo === 'inteiro') {
                    $folha->setCellValueExplicit($celula, (int) $valor, DataType::TYPE_NUMERIC);
                } else {
                    // STRING explícito: código MLB e ids numéricos virariam notação científica.
                    $folha->setCellValueExplicit($celula, (string) $valor, DataType::TYPE_STRING);
                }

                if ($url = $linha['_links'][$col['key']] ?? null) {
                    $folha->getCell($celula)->getHyperlink()->setUrl($url);
                    $folha->getStyle($celula)->getFont()->setUnderline(true)->getColor()->setRGB('1155CC');
                }
            }
        }

        if ($ultimaLinha > 1) {
            foreach ($colunas as $i => $col) {
                $faixa = $letra($i) . '2:' . $letra($i) . $ultimaLinha;
                match ($col['tipo'] ?? 'texto') {
                    'data'        => $folha->getStyle($faixa)->getNumberFormat()->setFormatCode('dd/mm/yyyy'),
                    'datahora'    => $folha->getStyle($faixa)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm'),
                    'texto_longo' => $folha->getStyle($faixa)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP),
                    default       => null,
                };
            }
        }

        $folha->freezePane($congelar);
        $folha->setAutoFilter("A1:{$ultimaLetra}{$ultimaLinha}");

        // Largura fixa e não autosize: medir cada célula é a parte mais cara da geração.
        foreach ($colunas as $i => $col) {
            $largura = $col['largura'] ?? match ($col['tipo'] ?? 'texto') {
                'data'        => 12,
                'datahora'    => 17,
                'inteiro'     => 10,
                'texto_longo' => 60,
                default       => 22,
            };
            $folha->getColumnDimension($letra($i))->setWidth($largura);
        }

        return $planilha;
    }
}
