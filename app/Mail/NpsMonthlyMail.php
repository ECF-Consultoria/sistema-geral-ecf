<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable da Pesquisa Mensal de Satisfação NPS (Phase 31, Plan 02).
 *
 * Reusa SMTP Gmail já validado em produção desde a Phase 28 (RelatorioMensalMail).
 * O comando `nps:disparar-mensal` instancia este Mailable no aniversário do
 * cadastro da empresa (D-01 / D-03) com o token público da survey.
 *
 * Decisões D-05 (template Markdown HTML), D-06 (escala 1-5) e D-07 (3
 * dimensões) — analista é opcional: quando $analistaName é null, a view
 * `emails.nps.mensal` omite a linha do analista (caso mentoria pura).
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see resources/views/emails/nps/mensal.blade.php
 */
class NpsMonthlyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string      $companyName     Nome legível da empresa que recebe a pesquisa
     * @param string      $estrategistaName Nome do estrategista designado (obrigatório)
     * @param string|null $analistaName    Nome do analista designado, ou null em mentoria pura
     * @param string      $linkPublico     URL absoluta de /nps/{token}
     * @param string      $mesLabel        Rótulo do mês de referência em pt-BR (ex: "Junho/2026")
     */
    public function __construct(
        public string $companyName,
        public string $estrategistaName,
        public ?string $analistaName,
        public string $linkPublico,
        public string $mesLabel,
    ) {}

    /**
     * Envelope com assunto pt-BR: "[ECF Admin] Pesquisa de satisfação — Junho/2026".
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[ECF Admin] Pesquisa de satisfação — {$this->mesLabel}",
        );
    }

    /**
     * Conteúdo do email — view HTML curta com saudação + CTA.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.nps.mensal',
            with: [
                'companyName'      => $this->companyName,
                'estrategistaName' => $this->estrategistaName,
                'analistaName'     => $this->analistaName,
                'linkPublico'      => $this->linkPublico,
                'mesLabel'         => $this->mesLabel,
            ],
        );
    }
}
