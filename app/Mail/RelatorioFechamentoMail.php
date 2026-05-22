<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable do Relatório Geral de Fechamento.
 * Recebe os dados já montados (mesLabel, relatorios, totais) e renderiza
 * a view emails.relatorio-fechamento para envio por email.
 * Os destinatários são configurados via ->to() no Job, não aqui.
 */
class RelatorioFechamentoMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array $dados Array com chaves: mesLabel, relatorios[], totais[]
     */
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
}
