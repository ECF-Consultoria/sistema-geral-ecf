<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable do Relatório Mensal Executivo (Phase 28).
 *
 * Anexa PDF previamente gravado em Storage::disk('local') via Attachment::fromStorage().
 * PDF NÃO é gerado dentro deste Mailable — quem gera é HandleRelatorioGeradoJob
 * via RelatorioMensalPdfService (separação de responsabilidades — Decisão D-C).
 */
class RelatorioMensalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string $period   Período YYYYMM (ex: '202605')
     * @param string $pdfPath  Path relativo dentro do disco 'local' (ex: 'relatorios/relatorio-202605.pdf')
     * @param array  $resumo   KPIs essenciais para exibir no corpo do email (4 valores)
     */
    public function __construct(
        public string $period,
        public string $pdfPath,
        public array $resumo = [],
    ) {}

    /**
     * Envelope com assunto pt-BR: "[ECF Admin] Relatório Mensal Executivo — Maio/2026"
     */
    public function envelope(): Envelope
    {
        $mesLabel = $this->mesLabelPt($this->period);

        return new Envelope(
            subject: "[ECF Admin] Relatório Mensal Executivo — {$mesLabel}",
        );
    }

    /**
     * Conteúdo do email — view HTML curta com 4 KPIs essenciais.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.relatorios.mensal-email',
            with: [
                'period'   => $this->period,
                'mesLabel' => $this->mesLabelPt($this->period),
                'resumo'   => $this->resumo,
            ],
        );
    }

    /**
     * Anexa o PDF gerado pelo Job a partir do Storage::disk('local').
     */
    public function attachments(): array
    {
        $nomeArquivo = "relatorio-mensal-{$this->period}.pdf";

        return [
            Attachment::fromStorage($this->pdfPath)
                ->as($nomeArquivo)
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Converte período YYYYMM para label pt-BR legível.
     * Duplicação consciente — helper de 5 linhas sem necessidade de service dedicado (Decisão D-D).
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
