<?php

namespace Tests\Feature\Phase129;

use App\Jobs\ProcessarEventoClicksignJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoSignatariosSyncService;
use App\Services\Contratos\GateLiberacaoOperacionalService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 05, Task 1 (SC3, gate #11) — o teste que dá NOME ao
 * critério de sucesso. O achado medido no gate A1 é que a ativação de um
 * envelope entrega uma RAJADA de eventos retroativos em segundos — a ordem
 * de chegada não pode governar a decisão (129-GATE.md achado 3). Esta suíte
 * prova que processar `document_closed` ANTES de `sign` (ordem invertida em
 * relação à intuição) não perde nem antecipa a liberação: quem decide é
 * SEMPRE a reconsulta do estado agregado do envelope, nunca a ordem/nome do
 * evento isolado.
 */
class EventoOrdemTrocadaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000040';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000041';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: 'token-falso', baseUrl: self::BASE);
    }

    private function sync(): ContratoSignatariosSyncService
    {
        return new ContratoSignatariosSyncService();
    }

    private function gate(): GateLiberacaoOperacionalService
    {
        return new GateLiberacaoOperacionalService();
    }

    private function router(): EmpresaOperacionalRouter
    {
        return app(EmpresaOperacionalRouter::class);
    }

    private string $envelopeStatusAtual = 'running';

    private array $eventosDocumentoAtual = [];

    /**
     * ⚠️ Um SEGUNDO `Http::fake([...])` no MESMO teste NÃO substitui o
     * anterior — Laravel mescla `stubCallbacks` numa collection e resolve
     * pelo PRIMEIRO match (`PendingRequest::buildStubHandler()`), então o
     * fake mais ANTIGO venceria sempre que a URL repetisse. Para simular a
     * reconsulta mudando de resposta entre duas chamadas do job dentro do
     * MESMO teste (o próprio ponto desta suíte — SC3/gate #11), o fake é
     * registrado UMA VEZ com um closure que lê o estado mutável abaixo,
     * atualizado por `mudarEnvelopePara()` antes de cada `processar()`.
     */
    private function registrarFakeDinamico(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/documents/' . self::DOCUMENT_ID . '/events')) {
                return Http::response(['data' => $this->eventosDocumentoAtual], 200);
            }

            if (str_ends_with($url, '/envelopes/' . self::ENVELOPE_ID)) {
                return Http::response([
                    'data' => [
                        'id'         => self::ENVELOPE_ID,
                        'type'       => 'envelopes',
                        'attributes' => ['status' => $this->envelopeStatusAtual],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });
    }

    private function mudarEnvelopePara(string $status, array $eventosDocumento = []): void
    {
        $this->envelopeStatusAtual   = $status;
        $this->eventosDocumentoAtual = $eventosDocumento;
    }

    private function eventoDocumentoSign(string $signerKey, string $criadoEm): array
    {
        return [
            'attributes' => [
                'name'    => 'sign',
                'created' => $criadoEm,
                'data'    => [
                    'signer' => [
                        'key'     => $signerKey,
                        'email'   => 'cliente.ordem@example.com',
                        'address' => '203.0.113.10',
                        'auths'   => ['email'],
                    ],
                ],
            ],
        ];
    }

    private function servico(string $nome): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function contratoDeTeste(Servico $servico): ContratoAssinatura
    {
        $company = Company::factory()->create();

        return ContratoAssinatura::factory()->comSnapshot()->create([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'clicksign_document_id' => self::DOCUMENT_ID,
        ]);
    }

    private function eventoWebhook(ContratoAssinatura $contrato, string $name): ContratoAssinaturaEvento
    {
        $rawBody = json_encode(['event' => ['name' => $name]]);

        return ContratoAssinaturaEvento::create([
            'contrato_assinatura_id' => $contrato->id,
            'clicksign_envelope_id'  => $contrato->clicksign_envelope_id,
            'name'                   => $name,
            'signature_valid'        => true,
            'payload'                => ['event' => ['name' => $name]],
            'payload_hash'           => hash('sha256', $rawBody . uniqid('', true)),
            'raw_body'               => $rawBody,
            'raw_truncado'           => false,
            'origem'                 => ContratoAssinaturaEvento::ORIGEM_WEBHOOK,
            'status'                 => ContratoAssinaturaEvento::STATUS_RECEBIDO,
            'ip_address'             => '203.0.113.10',
        ]);
    }

    private function processar(ContratoAssinaturaEvento $evento): void
    {
        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), $this->gate(), $this->router());
    }

    #[Test]
    public function document_closed_antes_do_envelope_fechar_nao_libera_e_sign_depois_libera_sem_perder_nada(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        $signerKey = '00000000-0000-4000-8000-000000000050';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $this->registrarFakeDinamico();

        // 1º evento processado: `document_closed`, mas a reconsulta AINDA diz
        // `running` (o gatilho de conclusão chegou antes do envelope
        // realmente fechar do lado da Clicksign — cenário plausível da
        // rajada retroativa).
        $this->mudarEnvelopePara('running', []);
        $this->processar($this->eventoWebhook($contrato, 'document_closed'));

        $contrato->refresh();
        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $contrato->status);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $contrato->company_id)->count());

        // 2º evento processado: `sign`, agora a reconsulta diz `closed` com
        // o contratante assinado. NADA foi perdido pela ordem invertida.
        $this->mudarEnvelopePara('closed', [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())]);
        $this->processar($this->eventoWebhook($contrato, 'sign'));

        $contrato->refresh();
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNotNull($contrato->liberado_em);
        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)->where('servico_id', $servico->id)->count()
        );
        $this->assertSame(1, MlbEmpresa::where('company_id', $contrato->company_id)->count());
    }

    #[Test]
    public function sign_chegando_depois_de_document_closed_ja_ter_liberado_nao_cria_segunda_liberacao_nem_segunda_ficha(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        $signerKey = '00000000-0000-4000-8000-000000000051';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $criadoEm = now()->toIso8601String();

        $this->registrarFakeDinamico();

        // 1º evento processado: `document_closed` — a reconsulta JÁ mostra o
        // envelope `closed` com o contratante assinado (o evento de
        // conclusão chegou depois da assinatura, ordem "normal"). Libera.
        $this->mudarEnvelopePara('closed', [$this->eventoDocumentoSign($signerKey, $criadoEm)]);
        $this->processar($this->eventoWebhook($contrato, 'document_closed'));

        $contrato->refresh();
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertSame(1, ContratoLiberacao::where('company_id', $contrato->company_id)->count());
        $this->assertSame(1, MlbEmpresa::where('company_id', $contrato->company_id)->count());

        // 2º evento processado: `sign`, do MESMO envelope, chegando por
        // último (retry/reentrega tardia). A reconsulta continua dizendo a
        // mesma coisa — `liberarEmpresa()` é idempotente por construção.
        $this->mudarEnvelopePara('closed', [$this->eventoDocumentoSign($signerKey, $criadoEm)]);
        $this->processar($this->eventoWebhook($contrato, 'sign'));

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)->where('servico_id', $servico->id)->count(),
            'Nenhuma segunda liberacao deve nascer do evento tardio'
        );
        $this->assertSame(1, MlbEmpresa::where('company_id', $contrato->company_id)->count());
    }
}
