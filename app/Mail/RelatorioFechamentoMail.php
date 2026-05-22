<?php

namespace App\Mail;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable do Relatório Geral de Fechamento.
 * Envia o relatório em HTML no corpo + PDF em anexo gerado via DomPDF.
 */
class RelatorioFechamentoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $dados)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Relatório de Fechamento — ' . ($this->dados['mesLabel'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.relatorio-fechamento',
            with: ['dados' => $this->dados],
        );
    }

    public function attachments(): array
    {
        $mesLabel = $this->dados['mesLabel'] ?? 'fechamento';
        $nomeArquivo = 'relatorio-' . str($mesLabel)->slug() . '.pdf';

        $pdf = Pdf::loadView('emails.relatorio-fechamento-pdf', ['dados' => $this->dados])
            ->setPaper('a4', 'portrait');

        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $pdf->output(),
                $nomeArquivo,
            )->withMime('application/pdf'),
        ];
    }
}
