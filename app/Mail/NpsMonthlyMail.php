<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable da Pesquisa Mensal de Satisfação NPS — Phase 32 Plan 01 (reescrita).
 *
 * Mudança em relação à Phase 31: o Mailable não recebe mais os nomes brutos
 * (companyName, estrategistaName, etc) — ele recebe os textos JÁ renderizados
 * via NpsTextRenderer no comando NpsDispararMensal. Isso isola a lógica de
 * substituição de placeholders num único lugar (helper) e mantém o Mailable
 * burro / template-puro.
 *
 * Chaves esperadas em $vars:
 *   - saudacaoRender    (HTML safe — string com nl2br + e())
 *   - corpoRender       (HTML safe — string com nl2br + e())
 *   - ctaRender         (texto puro — texto do botão CTA)
 *   - assinaturaRender  (HTML safe — string com nl2br + e())
 *   - linkPesquisa      (URL absoluta — route('nps.respond', $token))
 *   - mesReferencia     (string pt-BR "junho/2026")
 *   - assuntoRender     (texto puro — assunto do email)
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see app/Support/NpsTextRenderer.php
 * @see resources/views/emails/nps/mensal.blade.php
 */
class NpsMonthlyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, string> $vars Textos já renderizados pelo NpsTextRenderer
     *                                    + linkPesquisa + mesReferencia + assuntoRender.
     */
    public function __construct(public array $vars) {}

    /**
     * Envelope com assunto já renderizado (placeholders substituídos pelo comando).
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->vars['assuntoRender'] ?? 'Pesquisa de satisfação ECF',
        );
    }

    /**
     * Conteúdo — view plain Blade (sem Markdown component) que consome
     * as 6 vars de render + linkPesquisa + mesReferencia.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.nps.mensal',
            with: [
                'saudacaoRender'   => $this->vars['saudacaoRender']   ?? '',
                'corpoRender'      => $this->vars['corpoRender']      ?? '',
                'ctaRender'        => $this->vars['ctaRender']        ?? 'Responder pesquisa',
                'assinaturaRender' => $this->vars['assinaturaRender'] ?? '',
                'linkPesquisa'     => $this->vars['linkPesquisa']     ?? '#',
                'mesReferencia'    => $this->vars['mesReferencia']    ?? '',
            ],
        );
    }
}
