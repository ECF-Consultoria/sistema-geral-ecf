<?php

namespace Tests\Feature\Phase129;

use App\Jobs\ProcessarEventoClicksignJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
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
 * Suite Feature — Fase 129, plano 129-03, Task 2 (ProcessarEventoClicksignJob).
 *
 * ⚠️ `Http::fake()` aqui NÃO prova que a forma do payload/resposta está
 * certa — cinco bugs desta milestone nasceram exatamente de fixture
 * inventada confirmando a si mesma (§9.1/§10.2 do empírico). O que esta
 * suite prova é a FIAÇÃO: guard de reentrega, reconsulta vencendo o
 * payload, `failed()` sem PII. A prova ponta a ponta é o gate humano do
 * plano 129-07.
 */
class ProcessarEventoClicksignJobTest extends TestCase
{
    use RefreshDatabase;

    private const BASE         = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID  = '00000000-0000-4000-8000-000000000010';
    private const DOCUMENT_ID  = '00000000-0000-4000-8000-000000000011';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: 'token-falso', baseUrl: self::BASE);
    }

    private function sync(): ContratoSignatariosSyncService
    {
        return new ContratoSignatariosSyncService();
    }

    private function fakeEnvelope(string $status, array $eventos = []): void
    {
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response([
                'data' => [
                    'id'         => self::ENVELOPE_ID,
                    'type'       => 'envelopes',
                    'attributes' => ['status' => $status],
                ],
            ], 200),
            self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents/' . self::DOCUMENT_ID . '/events' => Http::response([
                'data' => $eventos,
            ], 200),
        ]);
    }

    private function contratoDeTeste(array $overrides = []): ContratoAssinatura
    {
        $company = Company::factory()->create();
        $servico = Servico::query()->value('id') ?? Servico::create([
            'nome'          => 'Serviço de teste (job)',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ])->id;

        return ContratoAssinatura::factory()->create(array_merge([
            'company_id'             => $company->id,
            'servico_id'             => $servico,
            'status'                 => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id'  => self::ENVELOPE_ID,
            'clicksign_document_id'  => self::DOCUMENT_ID,
        ], $overrides));
    }

    private function eventoDeTeste(?ContratoAssinatura $contrato, array $overrides = []): ContratoAssinaturaEvento
    {
        $rawBody = json_encode(['event' => ['name' => 'add_signer']]);

        return ContratoAssinaturaEvento::create(array_merge([
            'contrato_assinatura_id' => $contrato?->id,
            'clicksign_envelope_id'  => $contrato?->clicksign_envelope_id ?? self::ENVELOPE_ID,
            'name'                   => 'add_signer',
            'signature_valid'        => true,
            'payload'                => ['event' => ['name' => 'add_signer']],
            'payload_hash'           => hash('sha256', $rawBody . uniqid('', true)),
            'raw_body'               => $rawBody,
            'raw_truncado'           => false,
            'origem'                 => ContratoAssinaturaEvento::ORIGEM_WEBHOOK,
            'status'                 => ContratoAssinaturaEvento::STATUS_RECEBIDO,
            'ip_address'             => '203.0.113.10',
        ], $overrides));
    }

    #[Test]
    public function envelope_running_marca_enviado_em_e_move_rascunho_para_aguardando_assinaturas(): void
    {
        $contrato = $this->contratoDeTeste();
        $evento   = $this->eventoDeTeste($contrato);
        $this->fakeEnvelope('running');

        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), new GateLiberacaoOperacionalService(), app(EmpresaOperacionalRouter::class));

        $contrato->refresh();
        $evento->refresh();

        $this->assertNotNull($contrato->enviado_em);
        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $contrato->status);
        $this->assertSame(ContratoAssinaturaEvento::STATUS_PROCESSADO, $evento->status);
        $this->assertNotNull($evento->processado_em);
    }

    #[Test]
    public function evento_cujo_envelope_nao_casa_com_nenhum_contrato_vira_ignorado_e_nao_lanca(): void
    {
        $evento = $this->eventoDeTeste(null, [
            'clicksign_envelope_id' => '00000000-0000-4000-8000-000000000099',
        ]);
        Http::fake();

        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), new GateLiberacaoOperacionalService(), app(EmpresaOperacionalRouter::class));

        $evento->refresh();

        $this->assertSame(ContratoAssinaturaEvento::STATUS_IGNORADO, $evento->status);
        $this->assertNotNull($evento->erro_msg);
        Http::assertNothingSent();
    }

    #[Test]
    public function rodar_o_job_duas_vezes_sobre_o_mesmo_evento_faz_uma_unica_rodada_de_http_guard_de_reentrega(): void
    {
        $contrato = $this->contratoDeTeste();
        $evento   = $this->eventoDeTeste($contrato);
        $this->fakeEnvelope('running');

        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), new GateLiberacaoOperacionalService(), app(EmpresaOperacionalRouter::class));
        // Segunda execução — simula reentrega da fila (worker derrubado).
        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), new GateLiberacaoOperacionalService(), app(EmpresaOperacionalRouter::class));

        // consultarEnvelope() + listarEventosDoDocumento() = 2 chamadas, UMA
        // única vez — a segunda execução não bate na Clicksign de novo.
        Http::assertSentCount(2);
    }

    #[Test]
    public function job_decide_pela_reconsulta_e_nao_pelo_payload_do_evento(): void
    {
        $contrato = $this->contratoDeTeste();
        // O payload MENTE (diz "closed"), mas o Http::fake (reconsulta) diz
        // "running" — a reconsulta precisa vencer (prova direta da D-07).
        $evento = $this->eventoDeTeste($contrato, [
            'payload' => ['event' => ['name' => 'auto_close'], 'document' => ['status' => 'closed']],
        ]);
        $this->fakeEnvelope('running');

        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), new GateLiberacaoOperacionalService(), app(EmpresaOperacionalRouter::class));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $contrato->status);
    }

    #[Test]
    public function failed_grava_status_erro_e_erro_msg_sem_email(): void
    {
        $contrato = $this->contratoDeTeste();
        $evento   = $this->eventoDeTeste($contrato);

        (new ProcessarEventoClicksignJob($evento))->failed(
            new \RuntimeException('Falha ao processar signatario alguem@exemplo.com')
        );

        $evento->refresh();

        $this->assertSame(ContratoAssinaturaEvento::STATUS_ERRO, $evento->status);
        $this->assertNotEmpty($evento->erro_msg);
        $this->assertStringNotContainsString('@exemplo.com', $evento->erro_msg);
    }
}
