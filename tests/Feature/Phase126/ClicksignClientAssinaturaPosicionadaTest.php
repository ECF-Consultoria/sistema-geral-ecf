<?php

namespace Tests\Feature\Phase126;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Quick 260824-ot1 (Tarefa 2) — assinatura manuscrita POSICIONADA
 * (`{{~position_sign_ID}}`, doc oficial `docs-modelos` da Clicksign),
 * opt-in por serviço via `$assinaturaPosicionada` em
 * `montarEnvelope()`/`montarEnvelopePorModelo()`.
 *
 * ⚠️ O teste MAIS IMPORTANTE deste arquivo é
 * `servico_sem_a_flag_se_comporta_identico_a_hoje_nenhum_rubric_field_enviado()`
 * — a regressão que protege os 8 serviços cujo `.docx` NÃO tem as tags
 * `{{~position_sign_ID}}`. Se este teste quebrar, a geração de contrato de
 * TODOS os outros serviços quebra em produção.
 */
class ClicksignClientAssinaturaPosicionadaTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000002';
    private const SIGNER_ID   = '00000000-0000-4000-8000-000000000003';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    /**
     * Mesmo molde de `ClicksignClientEnvelopeTest`/`ClicksignClientModeloTest`
     * — 3 signatários fixos da ECF, dados fictícios.
     *
     * @return array<int, array{nome: string, email: string, papel: string}>
     */
    private function signatariosEcfDeTeste(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA],
        ];
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

    // ─── criarRequisitoRubricaPosicionada() isolado ───

    #[Test]
    public function criar_requisito_rubrica_posicionada_envia_action_rubricate_rubric_field_e_kind_manuscript(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/requirements' => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
        ]);

        $this->client()->criarRequisitoRubricaPosicionada(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID, 'contratante');

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];
            $relacoes  = $request['data']['relationships'] ?? [];

            return ($atributos['action'] ?? null) === 'rubricate'
                && ($atributos['rubric_field'] ?? null) === 'contratante'
                && ($atributos['kind'] ?? null) === 'manuscript'
                && ! array_key_exists('pages', $atributos)
                && ($relacoes['document']['data']['id'] ?? null) === self::DOCUMENT_ID
                && ($relacoes['signer']['data']['id'] ?? null) === self::SIGNER_ID;
        });
    }

    // ─── Regressão: SEM a flag, comportamento idêntico a hoje ───

    /**
     * ⚠️ O teste mais importante deste plano. `montarEnvelopePorModelo()`
     * chamado sem `$assinaturaPosicionada` (default `false`) precisa manter
     * EXATAMENTE o comportamento anterior a esta quick: nenhum
     * `rubric_field` sai em nenhuma requisição.
     */
    #[Test]
    public function servico_sem_a_flag_se_comporta_identico_a_hoje_nenhum_rubric_field_enviado(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $resultado = $this->client()->montarEnvelopePorModelo(
            ['name' => 'Contrato de teste — ECF Admin'],
            'contrato.docx',
            'template-id-teste',
            ['razao_social' => 'Empresa Teste LTDA'],
            ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE]
            // $ativar e $assinaturaPosicionada NÃO informados — default de ambos.
        );

        // 3 signatários (cliente contratante + sócio contratada + comercial
        // testemunha) × (signer + 3 requisitos: qualificação, autenticação,
        // rubrica) (12) + criar (1) + anexar por modelo (1) + ativar (1) =
        // 15. Nenhuma chamada extra de rubrica posicionada.
        Http::assertSentCount(15);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/requirements')) {
                return true; // não é requisição de requirement, ignora
            }

            $atributos = $request['data']['attributes'] ?? [];

            return ! array_key_exists('rubric_field', $atributos);
        });

        $this->assertCount(3, $resultado['signatarios']);
    }

    // ─── Com a flag: dois requisitos de rubrica por signatário mapeado ───

    #[Test]
    public function servico_com_a_flag_signatario_mapeado_ganha_dois_requisitos_de_rubrica(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaCompleta();

        $this->client()->montarEnvelopePorModelo(
            ['name' => 'Contrato de teste — ECF Admin'],
            'contrato.docx',
            'template-id-teste',
            ['razao_social' => 'Empresa Teste LTDA'],
            ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE],
            ativar: true,
            assinaturaPosicionada: true,
        );

        $requisicoesDeRubricaTodasPaginas = 0;
        $requisicoesDeRubricaPosicionada  = 0;

        Http::assertSent(function ($request) use (&$requisicoesDeRubricaTodasPaginas, &$requisicoesDeRubricaPosicionada) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/requirements')) {
                return true;
            }

            $atributos = $request['data']['attributes'] ?? [];

            if (($atributos['action'] ?? null) !== 'rubricate') {
                return true;
            }

            if (array_key_exists('rubric_field', $atributos)) {
                $requisicoesDeRubricaPosicionada++;
                $this->assertSame('manuscript', $atributos['kind'] ?? null);
                $this->assertContains($atributos['rubric_field'], ['contratante', 'contratada']);
            } else {
                $requisicoesDeRubricaTodasPaginas++;
                $this->assertSame('all', $atributos['pages'] ?? null);
                $this->assertSame('initials', $atributos['kind'] ?? null);
            }

            return true;
        });

        // Rubrica em todas as páginas: 1 por signatário (3 signatários).
        $this->assertSame(3, $requisicoesDeRubricaTodasPaginas);

        // Rubrica posicionada: só contratante (cliente) e contratada (sócio)
        // — testemunha (comercial) NÃO está no mapa, mesmo com a flag ligada.
        $this->assertSame(2, $requisicoesDeRubricaPosicionada);
    }

    /**
     * `PAPEL_TESTEMUNHA` não ganha o requisito posicionado nem com a flag
     * ligada — trava isolada, independente da contagem do teste anterior.
     */
    #[Test]
    public function papel_testemunha_nunca_ganha_requisito_posicionado_mesmo_com_a_flag_ligada(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA],
        ]]);
        $this->fakeSequenciaCompleta();

        $this->client()->montarEnvelope(
            ['name' => 'Contrato de teste — ECF Admin'],
            'contrato.pdf',
            '%PDF-1.4 conteúdo binário falso',
            ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE],
            ativar: true,
            assinaturaPosicionada: true,
        );

        // 2 signatários (contratante + testemunha) × (signer + 3
        // requisitos base) (8) + 1 requisito posicionado (só o contratante)
        // + criar (1) + anexar (1) + ativar (1) = 12.
        Http::assertSentCount(12);

        $requisicoesPosicionadas = 0;

        Http::assertSent(function ($request) use (&$requisicoesPosicionadas) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/requirements')) {
                return true;
            }

            $atributos = $request['data']['attributes'] ?? [];

            if (array_key_exists('rubric_field', $atributos)) {
                $requisicoesPosicionadas++;
                $this->assertSame('contratante', $atributos['rubric_field']);
            }

            return true;
        });

        $this->assertSame(1, $requisicoesPosicionadas, 'só o contratante tem papel mapeado neste cenário — testemunha nunca ganha o requisito posicionado');
    }

    // ─── Falha no requisito posicionado: rollback D-12 + exceção original ───

    #[Test]
    public function falha_no_requisito_posicionado_cancela_o_envelope_e_propaga_a_excecao_original(): void
    {
        config(['services.clicksign.signatarios_ecf' => []]);

        $chamadaDeRequirement = 0;

        Http::fake([
            self::BASE . '/envelopes'             => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'  => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'    => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements' => function () use (&$chamadaDeRequirement) {
                $chamadaDeRequirement++;

                // 1ª = qualificação, 2ª = autenticação, 3ª = rubrica (todas as
                // páginas) — todas passam. 4ª = rubrica posicionada — falha.
                if ($chamadaDeRequirement === 4) {
                    return Http::response(
                        ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'rubric_field inválido']]],
                        422
                    );
                }

                return Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200);
            },
            self::BASE . '/envelopes/*' => Http::response('', ClicksignSandboxFixtures::envelopeDescartadoStatusHttp()),
        ]);

        try {
            $this->client()->montarEnvelopePorModelo(
                ['name' => 'Contrato de teste — ECF Admin'],
                'contrato.docx',
                'template-id-teste',
                ['razao_social' => 'Empresa Teste LTDA'],
                ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE],
                ativar: true,
                assinaturaPosicionada: true,
            );
            $this->fail('Esperava a ClicksignException ORIGINAL propagada, não a de cancelamento.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        // Exatamente 1 cancelamento: DELETE direto em /envelopes/{id}.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID);

        // criar (1) + anexar por modelo (1) + signatário único (1) +
        // qualificação (1) + autenticação (1) + rubrica todas-as-páginas (1)
        // + rubrica posicionada, que falha (1) + cancelamento (1) = 8.
        Http::assertSentCount(8);
    }
}
