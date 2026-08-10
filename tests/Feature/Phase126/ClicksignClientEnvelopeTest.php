<?php

namespace Tests\Feature\Phase126;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Clicksign\ClicksignClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 126 Plan 126-02 (CLICK-01) — cobre as chamadas que faltavam no
 * `ClicksignClient` para montar um envelope de ponta a ponta: signatário,
 * requisito de qualificação/autenticação, ativação com prazo explícito,
 * consulta, notificação e cancelamento — mais o método composto
 * `montarEnvelope()` com rollback (D-12).
 *
 * Mesmo padrão do plano 126-01: `Http::fake()` com fixtures anonimizadas
 * (D-15), sem chamar a API real.
 */
class ClicksignClientEnvelopeTest extends TestCase
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
     * Os 3 signatários fixos da ECF (D-08) usados nos testes — dados
     * fictícios (`@example.com`), fora do escopo do que o empírico mediu.
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

    // ─── Task 1: signatários e requisitos ───

    #[Test]
    public function adicionar_signatario_envia_communicate_by_email_e_group_1(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/signers' => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
        ]);

        $this->client()->adicionarSignatario(self::ENVELOPE_ID, 'Fulano de Tal', 'fulano@example.com');

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];

            return ($atributos['name'] ?? null) === 'Fulano de Tal'
                && ($atributos['email'] ?? null) === 'fulano@example.com'
                && ($atributos['communicate_by'] ?? null) === 'email'
                && ($atributos['group'] ?? null) === 1;
        });
    }

    public static function papeisProvider(): array
    {
        return [
            'contratante -> party'    => [ContratoAssinaturaSignatario::PAPEL_CONTRATANTE, 'party'],
            'contratada -> contractor' => [ContratoAssinaturaSignatario::PAPEL_CONTRATADA, 'contractor'],
            'testemunha -> sign'      => [ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA, 'sign'],
        ];
    }

    #[Test]
    #[DataProvider('papeisProvider')]
    public function criar_requisito_qualificacao_mapeia_o_papel_para_o_role_da_clicksign(string $papel, string $roleEsperado): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/requirements' => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
        ]);

        $this->client()->criarRequisitoQualificacao(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID, $papel);

        Http::assertSent(function ($request) use ($roleEsperado) {
            $atributos = $request['data']['attributes'] ?? [];
            $relacoes  = $request['data']['relationships'] ?? [];

            return ($atributos['action'] ?? null) === 'agree'
                && ($atributos['role'] ?? null) === $roleEsperado
                && ($relacoes['document']['data']['id'] ?? null) === self::DOCUMENT_ID
                && ($relacoes['signer']['data']['id'] ?? null) === self::SIGNER_ID;
        });
    }

    #[Test]
    public function criar_requisito_qualificacao_com_papel_desconhecido_lanca_excecao_sem_requisicao(): void
    {
        Http::fake();

        try {
            $this->client()->criarRequisitoQualificacao(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID, 'papel-invalido');
            $this->fail('Esperava ClicksignException por papel desconhecido.');
        } catch (ClicksignException $e) {
            // ok — papel fora do mapa PAPEL_PARA_CLICKSIGN_ROLE
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function criar_requisito_autenticacao_envia_auth_email_e_as_duas_relationships(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/requirements' => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
        ]);

        $this->client()->criarRequisitoAutenticacao(self::ENVELOPE_ID, self::DOCUMENT_ID, self::SIGNER_ID);

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];
            $relacoes  = $request['data']['relationships'] ?? [];

            return ($atributos['action'] ?? null) === 'provide_evidence'
                && ($atributos['auth'] ?? null) === 'email'
                && ($relacoes['document']['data']['id'] ?? null) === self::DOCUMENT_ID
                && ($relacoes['signer']['data']['id'] ?? null) === self::SIGNER_ID;
        });
    }

    #[Test]
    public function config_signatarios_ecf_tem_exatamente_tres_entradas_com_as_chaves_certas(): void
    {
        $signatarios = config('services.clicksign.signatarios_ecf');

        $this->assertCount(3, $signatarios);

        foreach ($signatarios as $sig) {
            $this->assertArrayHasKey('nome', $sig);
            $this->assertArrayHasKey('email', $sig);
            $this->assertArrayHasKey('papel', $sig);
        }
    }

    // ─── Task 2: ativação, consulta, notificação, cancelamento ───

    #[Test]
    public function ativar_envelope_envia_deadline_at_30_dias_e_remind_interval_3_explicitos(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*' => Http::response(ClicksignSandboxFixtures::envelopeAtivado(), 200),
        ]);

        $this->client()->ativarEnvelope(self::ENVELOPE_ID);

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];

            if (($atributos['status'] ?? null) !== 'running' || ($atributos['remind_interval'] ?? null) !== 3) {
                return false;
            }

            $deadline = Carbon::parse($atributos['deadline_at'] ?? null);

            return $deadline->diffInHours(now()->addDays(30)) <= 1;
        });
    }

    #[Test]
    public function consultar_envelope_devolve_o_bloco_data(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*' => Http::response(ClicksignSandboxFixtures::envelopeAtivado(), 200),
        ]);

        $data = $this->client()->consultarEnvelope(self::ENVELOPE_ID);

        $this->assertSame('running', $data['attributes']['status']);
    }

    #[Test]
    public function listar_eventos_do_documento_devolve_a_lista_de_eventos(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents/*/events' => Http::response([
                'data' => [ClicksignSandboxFixtures::eventoSignDoDocumento()['data']],
            ], 200),
        ]);

        $eventos = $this->client()->listarEventosDoDocumento(self::ENVELOPE_ID, self::DOCUMENT_ID);

        $this->assertCount(1, $eventos);
        $this->assertSame('sign', $eventos[0]['attributes']['name']);
    }

    #[Test]
    public function reenviar_notificacao_429_nao_retenta_e_expoe_status_429(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/signers/*/notifications' => Http::response(
                ClicksignSandboxFixtures::erro429NotificacaoTexto()['body'],
                429
            ),
        ]);

        try {
            $this->client()->reenviarNotificacao(self::ENVELOPE_ID, self::SIGNER_ID);
            $this->fail('Esperava ClicksignException por 429.');
        } catch (ClicksignException $e) {
            $this->assertSame(429, $e->httpStatus);
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function cancelar_envelope_sucesso_devolve_true(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*' => Http::response(ClicksignSandboxFixtures::envelopeCancelado(), 200),
        ]);

        $resultado = $this->client()->cancelarEnvelope(self::ENVELOPE_ID);

        $this->assertTrue($resultado);
    }

    #[Test]
    public function cancelar_envelope_com_resposta_500_devolve_false_sem_lancar(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*' => Http::response(
                ['errors' => [['code' => 'server_error', 'status' => 500]]],
                500
            ),
        ]);

        $resultado = $this->client()->cancelarEnvelope(self::ENVELOPE_ID);

        $this->assertFalse($resultado);
    }

    // ─── Task 3: montarEnvelope — sequência completa com rollback ───

    #[Test]
    public function montar_envelope_caminho_feliz_consome_15_chamadas(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'                 => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'      => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'        => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'   => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
            self::BASE . '/envelopes/*'                => Http::response(ClicksignSandboxFixtures::envelopeAtivado(), 200),
        ]);

        $resultado = $this->client()->montarEnvelope(
            ['name' => 'Contrato de teste — ECF Admin'],
            'contrato.pdf',
            '%PDF-1.4 conteúdo binário falso',
            ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE]
        );

        Http::assertSentCount(15);

        $this->assertSame(self::ENVELOPE_ID, $resultado['envelope_id']);
        $this->assertSame(self::DOCUMENT_ID, $resultado['document_id']);
        $this->assertCount(4, $resultado['signatarios']);
    }

    #[Test]
    public function montar_envelope_falha_no_meio_cancela_o_envelope_e_propaga_o_erro_original(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'               => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'    => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'      => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements' => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'role inválido']]],
                422
            ),
            self::BASE . '/envelopes/*'              => Http::response(ClicksignSandboxFixtures::envelopeCancelado(), 200),
        ]);

        try {
            $this->client()->montarEnvelope(
                ['name' => 'Contrato de teste — ECF Admin'],
                'contrato.pdf',
                '%PDF-1.4 conteúdo binário falso',
                ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE]
            );
            $this->fail('Esperava a ClicksignException original propagada, não a de cancelamento.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        // Exatamente 1 requisição de cancelamento: PATCH direto em
        // /envelopes/{id}, sem sufixo de documents/signers/requirements.
        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID;
        });

        // criar envelope (1) + anexar documento (1) + 1º signatário (1) +
        // 1º requisito, que falha (1) + cancelamento (1) = 5.
        Http::assertSentCount(5);
    }

    #[Test]
    public function montar_envelope_falha_na_criacao_do_envelope_nao_cancela_nada(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes' => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'name inválido']]],
                422
            ),
        ]);

        try {
            $this->client()->montarEnvelope(
                ['name' => ''],
                'contrato.pdf',
                '%PDF-1.4 conteúdo binário falso',
                ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE]
            );
            $this->fail('Esperava ClicksignException na criação do envelope.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        // Nenhuma requisição de cancelamento: não há o que cancelar.
        Http::assertSentCount(1);
    }
}
