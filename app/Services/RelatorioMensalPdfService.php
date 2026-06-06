<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Service de geração de PDF do Relatório Mensal Executivo (Phase 28).
 *
 * Recebe estrutura completa de /relatorios/mensal/{periodo} (API-GUIDE §8)
 * e renderiza a view Blade emails.relatorios.mensal-pdf via Dompdf (barryvdh/laravel-dompdf).
 *
 * Retorna binário PDF — quem grava em disco e quem anexa em email é o Job (HandleRelatorioGeradoJob).
 * Este service NÃO acessa Storage, Mail, Cache nem Log.
 */
class RelatorioMensalPdfService
{
    /**
     * Gera o PDF do relatório mensal a partir dos dados da API ECF Drive.
     *
     * @param  array  $dados  Estrutura completa de /relatorios/mensal/{periodo}:
     *                        ['periodo', 'geradoEm', 'resumo', 'historico', 'distribuicao', 'rankings', 'signals']
     * @return string         Binário PDF (output do Dompdf)
     */
    public function gerar(array $dados): string
    {
        $periodo  = $dados['periodo'] ?? '';
        $mesLabel = $this->mesLabelPt($periodo);

        // Logo em base64 inline — evita dependência de rede no Dompdf (Pitfall 2 CONTEXT)
        // Usa public/images/logo.png (caminho confirmado no codebase — RelatorioFechamentoMail usa mesmo path)
        $logoPath   = public_path('images/logo.png');
        $logoBase64 = null;
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        $dadosPraBlade = [
            'dados'      => $dados,
            'periodo'    => $periodo,
            'mesLabel'   => $mesLabel,
            'geradoEm'   => now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'logoBase64' => $logoBase64,
        ];

        // Dompdf via facade barryvdh/laravel-dompdf — CSS inline na view (não Tailwind)
        $pdf = Pdf::loadView('emails.relatorios.mensal-pdf', $dadosPraBlade)
            ->setPaper('A4');

        return $pdf->output();
    }

    /**
     * Converte período YYYYMM para label pt-BR legível.
     * Exemplo: '202605' → 'Maio/2026'
     */
    private function mesLabelPt(string $periodo): string
    {
        if (strlen($periodo) !== 6) {
            return $periodo;
        }

        $meses = [
            'Janeiro', 'Fevereiro', 'Março', 'Abril',
            'Maio', 'Junho', 'Julho', 'Agosto',
            'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        ];

        $ano = substr($periodo, 0, 4);
        $mes = (int) substr($periodo, 4, 2);

        if ($mes < 1 || $mes > 12) {
            return $periodo;
        }

        return $meses[$mes - 1] . '/' . $ano;
    }
}
