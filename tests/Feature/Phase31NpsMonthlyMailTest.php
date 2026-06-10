<?php

namespace Tests\Feature;

use App\Mail\NpsMonthlyMail;
use Tests\TestCase;

/**
 * Suite Feature — NpsMonthlyMail (Phase 31, Plan 02).
 *
 * Cobre instânciação, envelope/subject pt-BR, content/view com chaves
 * canônicas e render do template Blade — incluindo a condicional de
 * omitir a linha do analista quando ele é null (D-07, mentoria pura).
 *
 * Testes puros (sem DB), seguindo padrão de Phase29 AlertaEcfNotificationTest.
 */
class Phase31NpsMonthlyMailTest extends TestCase
{
    private function novoMailable(?string $analistaName = 'Bruno Lima'): NpsMonthlyMail
    {
        return new NpsMonthlyMail(
            companyName:      'Empresa Teste',
            estrategistaName: 'Nathalia Souza',
            analistaName:     $analistaName,
            linkPublico:      'https://admin.ecfconsultoria.com.br/nps/abc-token-uuid',
            mesLabel:         'Junho/2026',
        );
    }

    /**
     * T01 — instanciar o Mailable com os 5 args não lança exceção.
     */
    public function test_mailable_instancia_sem_excecao(): void
    {
        $mail = $this->novoMailable();
        $this->assertInstanceOf(NpsMonthlyMail::class, $mail);
        $this->assertSame('Empresa Teste',  $mail->companyName);
        $this->assertSame('Nathalia Souza', $mail->estrategistaName);
        $this->assertSame('Bruno Lima',     $mail->analistaName);
    }

    /**
     * T02 — envelope().subject contém o prefixo padrão e o mesLabel.
     */
    public function test_envelope_subject_contem_prefixo_e_mes_label(): void
    {
        $envelope = $this->novoMailable()->envelope();

        $this->assertStringContainsString('Pesquisa de satisfação', $envelope->subject);
        $this->assertStringContainsString('Junho/2026',             $envelope->subject);
    }

    /**
     * T03 — content().view aponta para a view correta e content().with traz as 5 chaves.
     */
    public function test_content_view_e_with_payload_canonico(): void
    {
        $content = $this->novoMailable()->content();

        $this->assertSame('emails.nps.mensal', $content->view);

        $with = $content->with;
        $this->assertArrayHasKey('companyName',      $with);
        $this->assertArrayHasKey('estrategistaName', $with);
        $this->assertArrayHasKey('analistaName',     $with);
        $this->assertArrayHasKey('linkPublico',      $with);
        $this->assertArrayHasKey('mesLabel',         $with);
    }

    /**
     * T04 — render do Blade não dispara exceção e contém o link público + estrategista.
     */
    public function test_render_template_blade_inclui_link_e_estrategista(): void
    {
        $html = view('emails.nps.mensal', [
            'companyName'      => 'Empresa Teste',
            'estrategistaName' => 'Nathalia Souza',
            'analistaName'     => 'Bruno Lima',
            'linkPublico'      => 'https://admin.ecfconsultoria.com.br/nps/abc-token-uuid',
            'mesLabel'         => 'Junho/2026',
        ])->render();

        $this->assertStringContainsString('https://admin.ecfconsultoria.com.br/nps/abc-token-uuid', $html);
        $this->assertStringContainsString('Nathalia Souza', $html);
        $this->assertStringContainsString('Bruno Lima',     $html);
        $this->assertStringContainsString('Empresa Teste',  $html);
        $this->assertStringContainsString('Junho/2026',     $html);
        $this->assertStringContainsString('Responder pesquisa', $html);
    }

    /**
     * T05 — quando analistaName é null, a linha "Analista:" é omitida (D-07).
     */
    public function test_render_omite_label_analista_quando_nulo(): void
    {
        $html = view('emails.nps.mensal', [
            'companyName'      => 'Empresa Teste',
            'estrategistaName' => 'Nathalia Souza',
            'analistaName'     => null,
            'linkPublico'      => 'https://admin.ecfconsultoria.com.br/nps/abc-token-uuid',
            'mesLabel'         => 'Junho/2026',
        ])->render();

        // Estrategista continua aparecendo
        $this->assertStringContainsString('Nathalia Souza', $html);
        // Mas a label "Analista:" não pode aparecer
        $this->assertStringNotContainsString('Analista:', $html);
    }
}
