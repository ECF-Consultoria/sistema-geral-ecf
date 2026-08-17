<?php

namespace Tests\Feature\Phase127;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 127 Plan 127-02 (CLICK-02, D-02) — prova o caminho que PARA no
 * rascunho: `montarEnvelope()`/`montarEnvelopePorModelo()` recebem
 * `ativar: false` e montam o envelope inteiro (documento, 4 signatários, 8
 * requisitos) SEM disparar `ativarEnvelope()`. Quem ativa depois é o
 * Comercial, pela interface da Clicksign (§10.4 do empírico: não existe
 * pré-visualizar sem ativar).
 *
 * Todas as fixtures usadas aqui já existem em `ClicksignSandboxFixtures` e
 * foram criadas/medidas na Fase 126 — nenhuma fixture nova de payload de
 * ENTRADA nasce neste plano, porque ele só REMOVE uma chamada da sequência,
 * não muda forma de payload nenhum.
 *
 * Mesmo padrão de setup dos testes da Fase 126
 * (`ClicksignClientEnvelopeTest`/`ClicksignClientModeloTest`): `Http::fake()`
 * com token/baseUrl fixos, sem chamar a API real.
 */
class ClicksignParaNoRascunhoTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const TEMPLATE_ID = '00000000-0000-4000-8000-000000000008';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    /**
     * Os 3 signatários fixos da ECF (D-08), mesmo molde dos testes da Fase
     * 126.
     *
     * @return array<int, array{nome: string, email: string, papel: string}>
     */
    private function signatariosEcfDeTeste(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA],
        ];
    }

    private function signatarioCliente(): array
    {
        return ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE];
    }

    /**
     * Identifica exatamente a chamada de ativação — `PATCH /envelopes/{id}`
     * com `attributes.status === 'running'` — para diferenciar de
     * `consultarEnvelope()` (GET) e `cancelarEnvelope()` (DELETE), que caem
     * no MESMO padrão de URL `/envelopes/*`.
     */
    private function assertRequisicaoEhAtivacao($request): bool
    {
        $atributos = $request['data']['attributes'] ?? [];

        return $request->method() === 'PATCH'
            && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID
            && ($atributos['status'] ?? null) === 'running';
    }

    private function fakeSequenciaCompleta(): void
    {
        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
            self::BASE . '/envelopes/*'               => Http::response(ClicksignSandboxFixtures::envelopeAtivado(), 200),
        ]);
    }

    // ─── Teste 1 + 2: montarEnvelopePorModelo(ativar: false) ───

    #[Test]
    public function montar_envelope_por_modelo_com_ativar_false_nao_manda_a_chamada_de_ativacao(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $this->client()->montarEnvelopePorModelo(
            ['name' => 'Contrato de teste — ECF Admin'],
            'Contrato de teste.docx',
            self::TEMPLATE_ID,
            ['razao_social' => 'Empresa Teste LTDA'],
            $this->signatarioCliente(),
            ativar: false
        );

        Http::assertNotSent(fn ($request) => $this->assertRequisicaoEhAtivacao($request));
    }

    #[Test]
    public function montar_envelope_por_modelo_com_ativar_false_completa_a_sequencia_e_devolve_ids(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $resultado = $this->client()->montarEnvelopePorModelo(
            ['name' => 'Contrato de teste — ECF Admin'],
            'Contrato de teste.docx',
            self::TEMPLATE_ID,
            ['razao_social' => 'Empresa Teste LTDA'],
            $this->signatarioCliente(),
            ativar: false
        );

        // 15 chamadas do caminho feliz medido (Fase 126) MENOS a ativação: 14.
        Http::assertSentCount(14);

        $this->assertSame(self::ENVELOPE_ID, $resultado['envelope_id']);
        $this->assertArrayHasKey('document_id', $resultado);
        $this->assertCount(4, $resultado['signatarios']);
    }

    // ─── Teste 3: default preservado — rede contra regressão da Fase 126 ───

    #[Test]
    public function montar_envelope_por_modelo_sem_o_parametro_novo_continua_ativando(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $this->client()->montarEnvelopePorModelo(
            ['name' => 'Contrato de teste — ECF Admin'],
            'Contrato de teste.docx',
            self::TEMPLATE_ID,
            ['razao_social' => 'Empresa Teste LTDA'],
            $this->signatarioCliente()
        );

        Http::assertSent(fn ($request) => $this->assertRequisicaoEhAtivacao($request));
        Http::assertSentCount(15);
    }

    // ─── Teste 4: rollback D-04 sobrevive com ativar: false ───

    #[Test]
    public function montar_envelope_por_modelo_com_ativar_false_ainda_cancela_no_meio_da_falha(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'role inválido']]],
                422
            ),
            self::BASE . '/envelopes/*'               => Http::response('', ClicksignSandboxFixtures::envelopeDescartadoStatusHttp()),
        ]);

        try {
            $this->client()->montarEnvelopePorModelo(
                ['name' => 'Contrato de teste — ECF Admin'],
                'Contrato de teste.docx',
                self::TEMPLATE_ID,
                ['razao_social' => 'Empresa Teste LTDA'],
                $this->signatarioCliente(),
                ativar: false
            );
            $this->fail('Esperava a ClicksignException original propagada, não a de cancelamento.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID;
        });

        // criar envelope (1) + anexar documento por modelo (1) + 1º signatário (1) +
        // 1º requisito, que falha (1) + cancelamento (1) = 5 — igual ao caminho
        // COM ativação (Fase 126), porque a falha acontece antes de chegar na
        // ativação de qualquer forma.
        Http::assertSentCount(5);
    }

    // ─── Teste 5: mesmo par (3 e 4) para montarEnvelope() (upload de binário) ───

    #[Test]
    public function montar_envelope_sem_o_parametro_novo_continua_ativando(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $this->client()->montarEnvelope(
            ['name' => 'Contrato de teste — ECF Admin'],
            'contrato.pdf',
            '%PDF-1.4 conteúdo binário falso',
            $this->signatarioCliente()
        );

        Http::assertSent(fn ($request) => $this->assertRequisicaoEhAtivacao($request));
        Http::assertSentCount(15);
    }

    #[Test]
    public function montar_envelope_com_ativar_false_nao_ativa_mas_completa_a_sequencia(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $resultado = $this->client()->montarEnvelope(
            ['name' => 'Contrato de teste — ECF Admin'],
            'contrato.pdf',
            '%PDF-1.4 conteúdo binário falso',
            $this->signatarioCliente(),
            ativar: false
        );

        Http::assertNotSent(fn ($request) => $this->assertRequisicaoEhAtivacao($request));
        Http::assertSentCount(14);

        $this->assertSame(self::ENVELOPE_ID, $resultado['envelope_id']);
        $this->assertCount(4, $resultado['signatarios']);
    }

    #[Test]
    public function montar_envelope_com_ativar_false_ainda_cancela_no_meio_da_falha(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'role inválido']]],
                422
            ),
            self::BASE . '/envelopes/*'               => Http::response('', ClicksignSandboxFixtures::envelopeDescartadoStatusHttp()),
        ]);

        try {
            $this->client()->montarEnvelope(
                ['name' => 'Contrato de teste — ECF Admin'],
                'contrato.pdf',
                '%PDF-1.4 conteúdo binário falso',
                $this->signatarioCliente(),
                ativar: false
            );
            $this->fail('Esperava a ClicksignException original propagada, não a de cancelamento.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID;
        });

        Http::assertSentCount(5);
    }
}
