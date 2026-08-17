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
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 06, Task 2 (CLICK-11, D-14) — prova que uma falha
 * PERMANENTE do download do PDF assinado NUNCA desfaz a liberação da
 * empresa: o cliente assinou, a liberação já aconteceu, e o download roda
 * como job SEPARADO fora do caminho crítico.
 *
 * ⚠️ `Http::fake()` aqui NÃO prova a forma real do payload/resposta — a
 * prova ponta a ponta é o gate humano do plano 129-07. O que esta suíte
 * prova é a FIAÇÃO: liberação → dispatch protegido por try/catch → falha do
 * download não propaga.
 */
class DownloadPdfFalhaNaoBloqueiaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE         = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID  = '00000000-0000-4000-8000-000000000050';
    private const DOCUMENT_ID  = '00000000-0000-4000-8000-000000000051';
    private const LINK_S3      = 'https://s3.sandbox.example.com/link-quebrado?X-Amz-Expires=300';

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

    private function urlEnvelope(): string
    {
        return self::BASE . '/envelopes/' . self::ENVELOPE_ID;
    }

    private function urlEventosDocumento(): string
    {
        return self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents/' . self::DOCUMENT_ID . '/events';
    }

    private function urlDocumento(): string
    {
        return self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents/' . self::DOCUMENT_ID;
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
                        'email'   => 'cliente.gate12906@example.com',
                        'address' => '203.0.113.11',
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

    private function contratoDeTeste(Servico $servico, array $overrides = []): ContratoAssinatura
    {
        $company = Company::factory()->create();

        return ContratoAssinatura::factory()->comSnapshot()->create(array_merge([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'clicksign_document_id' => self::DOCUMENT_ID,
        ], $overrides));
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
            'ip_address'             => '203.0.113.11',
        ]);
    }

    private function processar(ContratoAssinaturaEvento $evento): void
    {
        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), $this->gate(), $this->router());
    }

    #[Test]
    public function download_falha_nao_impede_liberacao_nem_ficha(): void
    {
        Storage::fake('local');

        $servico   = $this->servico('Assessoria'); // gera MlbEmpresa
        $contrato  = $this->contratoDeTeste($servico);
        $signerKey = '00000000-0000-4000-8000-000000000052';

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        Http::fake([
            $this->urlEnvelope() => Http::response([
                'data' => [
                    'id'         => self::ENVELOPE_ID,
                    'type'       => 'envelopes',
                    'attributes' => ['status' => 'closed'],
                ],
            ], 200),
            $this->urlEventosDocumento() => Http::response([
                'data' => [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())],
            ], 200),
            $this->urlDocumento() => Http::response([
                'data' => [
                    'id'         => self::DOCUMENT_ID,
                    'type'       => 'documents',
                    'attributes' => [
                        'status' => 'closed',
                        'files'  => ['signed' => self::LINK_S3],
                    ],
                ],
            ], 200),
            // S3 com o link "quebrado" — simula falha PERMANENTE de rede/expiração.
            self::LINK_S3 => Http::response('<Error><Code>AccessDenied</Code></Error>', 403),
        ]);

        // Não deve propagar exceção — a falha do download é engolida pelo
        // try/catch do ProcessarEventoClicksignJob (D-14).
        $this->processar($this->eventoWebhook($contrato, 'sign'));

        $contrato->refresh();

        // 1. Liberação existe.
        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('servico_id', $servico->id)
                ->where('via', ContratoLiberacao::VIA_WEBHOOK)
                ->count(),
            'D-14: falha de download nunca pode impedir a liberacao'
        );

        // 2. Ficha existe (Assessoria dispara implementação).
        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $contrato->company_id)->count(),
            'D-14: falha de download nunca pode impedir a criacao de ficha'
        );

        // 3. Contrato continua assinado, PDF pendente.
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNull($contrato->pdf_assinado_path);
        $this->assertNotNull($contrato->pdf_assinado_erro);
        $this->assertStringNotContainsString('@', $contrato->pdf_assinado_erro, 'WR-11: nunca gravar e-mail na mensagem de erro');
    }

    #[Test]
    public function excecao_ao_despachar_download_nao_impede_liberacao_ja_gravada(): void
    {
        Storage::fake('local');

        $servico   = $this->servico('Gestão');
        $signerKey = '00000000-0000-4000-8000-000000000053';

        // Contrato SEM clicksign_document_id — dado incompleto que faz o
        // guard de `BaixarPdfContratoAssinadoJob::handle()` lançar
        // RuntimeException imediatamente ao rodar (fila `sync`: dispatch()
        // executa o job na hora e RELANÇA a exceção pro chamador). Prova
        // que o try/catch em ProcessarEventoClicksignJob protege a
        // liberação independente de QUAL exceção o download lança — não só
        // falha HTTP simples.
        $contrato = $this->contratoDeTeste($servico, ['clicksign_document_id' => null]);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        Http::fake([
            $this->urlEnvelope() => Http::response([
                'data' => [
                    'id'         => self::ENVELOPE_ID,
                    'type'       => 'envelopes',
                    'attributes' => ['status' => 'closed'],
                ],
            ], 200),
        ]);

        // Sem clicksign_document_id o passo 4 do handle() (sync de
        // signatarios) é pulado — precisamos marcar a assinatura por fora.
        ContratoAssinaturaSignatario::where('contrato_assinatura_id', $contrato->id)
            ->update(['situacao' => ContratoAssinaturaSignatario::SITUACAO_ASSINOU, 'assinado_em' => now()]);

        $this->processar($this->eventoWebhook($contrato, 'sign'));

        $contrato->refresh();

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('servico_id', $servico->id)
                ->count(),
            'liberacao gravada ANTES do dispatch precisa sobreviver a qualquer excecao do download'
        );
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
    }
}
