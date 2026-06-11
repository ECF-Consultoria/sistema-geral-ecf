<?php

namespace Tests\Feature;

use App\Mail\NpsMonthlyMail;
use Tests\TestCase;

/**
 * Suite Feature — NpsMonthlyMail.
 *
 * Phase 31 (Plan 02) — criação inicial: cobria construtor com 5 named args
 *   (companyName, estrategistaName, analistaName, linkPublico, mesLabel).
 *
 * Phase 32 (Plan 01) — REESCRITA: o Mailable agora recebe um array $vars
 *   com os textos JÁ renderizados via NpsTextRenderer (saudacaoRender,
 *   corpoRender, ctaRender, assinaturaRender, linkPesquisa, mesReferencia,
 *   assuntoRender). Esses testes foram ajustados para a nova assinatura;
 *   a condicional de analista nulo agora vive no comando (que monta o
 *   bloco_analista), não no template Blade — ver
 *   Phase31NpsDispararMensalTest::test_empresa_com_analista_passa_nome_no_mailable.
 *
 * Testes puros (sem DB), seguindo padrão de Phase29 AlertaEcfNotificationTest.
 */
class Phase31NpsMonthlyMailTest extends TestCase
{
    private function novoMailable(array $overrides = []): NpsMonthlyMail
    {
        $vars = array_merge([
            'assuntoRender'    => 'Pesquisa de satisfação ECF — junho/2026',
            'saudacaoRender'   => 'Olá!',
            'corpoRender'      => 'Seu estrategista é <strong>Nathalia Souza</strong> e o analista é <strong>Bruno Lima</strong>.',
            'ctaRender'        => 'Responder pesquisa',
            'assinaturaRender' => 'Obrigado,<br />Equipe ECF',
            'linkPesquisa'     => 'https://admin.ecfconsultoria.com.br/nps/abc-token-uuid',
            'mesReferencia'    => 'junho/2026',
        ], $overrides);

        return new NpsMonthlyMail($vars);
    }

    /**
     * T01 — instanciar o Mailable com array $vars não lança exceção e expõe a propriedade.
     */
    public function test_mailable_instancia_sem_excecao(): void
    {
        $mail = $this->novoMailable();
        $this->assertInstanceOf(NpsMonthlyMail::class, $mail);
        $this->assertSame('Olá!', $mail->vars['saudacaoRender']);
        $this->assertSame('Responder pesquisa', $mail->vars['ctaRender']);
        $this->assertSame('junho/2026', $mail->vars['mesReferencia']);
    }

    /**
     * T02 — envelope().subject usa o assuntoRender já renderizado (não monta mais
     * o assunto internamente — agora vem pronto do comando).
     */
    public function test_envelope_subject_usa_assunto_render(): void
    {
        $envelope = $this->novoMailable()->envelope();

        $this->assertStringContainsString('Pesquisa de satisfação', $envelope->subject);
        $this->assertStringContainsString('junho/2026',             $envelope->subject);
    }

    /**
     * T03 — content().view aponta para a view correta e content().with traz as 6 chaves
     * que o template Blade espera (não inclui assuntoRender, que vai pro Envelope).
     */
    public function test_content_view_e_with_payload_canonico(): void
    {
        $content = $this->novoMailable()->content();

        $this->assertSame('emails.nps.mensal', $content->view);

        $with = $content->with;
        $this->assertArrayHasKey('saudacaoRender',   $with);
        $this->assertArrayHasKey('corpoRender',      $with);
        $this->assertArrayHasKey('ctaRender',        $with);
        $this->assertArrayHasKey('assinaturaRender', $with);
        $this->assertArrayHasKey('linkPesquisa',     $with);
        $this->assertArrayHasKey('mesReferencia',    $with);
    }

    /**
     * T04 — render do Blade não dispara exceção e contém o link público, o CTA
     * renderizado e o logo (gradient característico do partial logo-ecf).
     */
    public function test_render_template_blade_inclui_link_cta_e_logo(): void
    {
        $html = view('emails.nps.mensal', [
            'saudacaoRender'   => 'Olá!',
            'corpoRender'      => 'Seu estrategista é <strong>Nathalia Souza</strong>.',
            'ctaRender'        => 'Responder pesquisa',
            'assinaturaRender' => 'Obrigado,<br />Equipe ECF',
            'linkPesquisa'     => 'https://admin.ecfconsultoria.com.br/nps/abc-token-uuid',
            'mesReferencia'    => 'junho/2026',
        ])->render();

        $this->assertStringContainsString('https://admin.ecfconsultoria.com.br/nps/abc-token-uuid', $html);
        $this->assertStringContainsString('Nathalia Souza',     $html);
        $this->assertStringContainsString('Responder pesquisa', $html);
        $this->assertStringContainsString('junho/2026',         $html);
        // Phase 32 D-01 — logo ECF com gradient inline deve estar no header
        $this->assertStringContainsString('linear-gradient', $html);
    }

    /**
     * T05 — quando o corpo NÃO inclui menção a analista (mentoria pura), o HTML
     * renderizado também não inclui. Essa lógica agora é responsabilidade do
     * comando (que monta o bloco_analista como string vazia), não do template.
     */
    public function test_render_omite_analista_quando_corpo_nao_menciona(): void
    {
        $html = view('emails.nps.mensal', [
            'saudacaoRender'   => 'Olá!',
            // Corpo sem o trecho "e o analista é..." — mentoria pura
            'corpoRender'      => 'Seu estrategista é <strong>Nathalia Souza</strong>.',
            'ctaRender'        => 'Responder pesquisa',
            'assinaturaRender' => 'Obrigado,<br />Equipe ECF',
            'linkPesquisa'     => 'https://admin.ecfconsultoria.com.br/nps/abc-token-uuid',
            'mesReferencia'    => 'junho/2026',
        ])->render();

        $this->assertStringContainsString('Nathalia Souza', $html);
        $this->assertStringNotContainsString('analista', $html);
    }
}
