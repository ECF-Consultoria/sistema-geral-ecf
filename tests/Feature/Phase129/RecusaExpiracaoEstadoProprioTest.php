<?php

namespace Tests\Feature\Phase129;

/**
 * ⚠️ As fixtures de `refusal`/`deadline`/`cancel` usadas nesta suíte são
 * SINTÉTICAS — o `129-GATE.md` registra os gates #6 (`deadline`) e #7
 * (`refusal`) como NÃO MEDIDOS nesta rodada (nenhuma recusa nem expiração
 * foi exercitada ao vivo contra o sandbox). `Http::fake()` confirma
 * alegremente uma forma errada se a forma real divergir — a prova real é o
 * gate humano do plano 129-07. Se o `129-GATE.md` vier a registrar o
 * payload real, as fixtures abaixo devem ser atualizadas para refletir a
 * forma real, não a inventada.
 */

use App\Jobs\ProcessarEventoClicksignJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Models\ContratoServico;
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

class RecusaExpiracaoEstadoProprioTest extends TestCase
{
    use RefreshDatabase;

    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000060';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000061';

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

    private function fakeEnvelope(string $status, array $eventosDocumento = []): void
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
                'data' => $eventosDocumento,
            ], 200),
        ]);
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
                        'email'   => 'cliente.recusa@example.com',
                        'address' => '203.0.113.10',
                        'auths'   => ['email'],
                    ],
                ],
            ],
        ];
    }

    /** Fixture SINTÉTICA (gate #7 nao medido) — forma plausível a partir da doc oficial. */
    private function eventoDocumentoRefusal(string $signerKey): array
    {
        return [
            'attributes' => [
                'name'    => 'refusal',
                'created' => now()->toIso8601String(),
                'data'    => [
                    'signer' => [
                        'key'   => $signerKey,
                        'email' => 'cliente.recusa@example.com',
                    ],
                    'reasons' => ['motivo sintetico de teste'],
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

    private function contratoDeTeste(Company $company, Servico $servico, array $overrides = []): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->comSnapshot()->create(array_merge([
            'company_id'             => $company->id,
            'servico_id'             => $servico->id,
            'status'                 => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'clicksign_envelope_id'  => self::ENVELOPE_ID,
            'clicksign_document_id'  => self::DOCUMENT_ID,
        ], $overrides));
    }

    /** Evento do WEBHOOK (a fila de entrada) — dispara o job. */
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
    public function evento_refusal_do_contratante_vira_recusado_nunca_cancelado_ou_erro_e_nao_mexe_no_cadastro(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servico('Mentoria');
        $contrato = $this->contratoDeTeste($company, $servico);

        $signerKey = '00000000-0000-4000-8000-000000000070';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        // D-04: prova direta — a empresa já tinha ficha e contrato de OUTRO
        // serviço ativos, e nenhum dos dois pode ser tocado pela recusa
        // deste contrato.
        $contratoServicoExistente = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servico('Gestão')->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
        $mlbEmpresaExistente = MlbEmpresa::create([
            'nome'       => $company->name,
            'tipo'       => 'ASSESSORIA',
            'company_id' => $company->id,
        ]);

        $this->fakeEnvelope('closed', [$this->eventoDocumentoRefusal($signerKey)]);

        $this->processar($this->eventoWebhook($contrato, 'refusal'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_RECUSADO, $contrato->status);
        $this->assertNotSame(ContratoAssinatura::STATUS_CANCELADO, $contrato->status);
        $this->assertNotSame(ContratoAssinatura::STATUS_ERRO, $contrato->status);
        $this->assertNull($contrato->liberado_em);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $servico->id)->count());

        // D-04 — nada tocado no cadastro.
        $this->assertTrue($contratoServicoExistente->fresh()->ativo);
        $this->assertNotNull(MlbEmpresa::find($mlbEmpresaExistente->id));
    }

    #[Test]
    public function evento_deadline_com_envelope_closed_e_contratante_pendente_vira_expirado_sem_liberar(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servico('Mentoria');
        $contrato = $this->contratoDeTeste($company, $servico);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        // Fixture SINTÉTICA (gate #6 nao medido) — o achado medido ao vivo
        // (`deadline_partial_signature_action: "closed"`) confirma que o
        // envelope PODE fechar com assinatura parcial.
        $this->fakeEnvelope('closed', []);

        $this->processar($this->eventoWebhook($contrato, 'deadline'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_EXPIRADO, $contrato->status);
        $this->assertNull($contrato->liberado_em);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }

    #[Test]
    public function evento_deadline_com_envelope_closed_e_contratante_assinado_vira_assinado_com_liberacao(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($company, $servico);

        $signerKey = '00000000-0000-4000-8000-000000000071';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        // Prazo venceu, mas o cliente JÁ tinha assinado — não é expiração.
        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())]);

        $this->processar($this->eventoWebhook($contrato, 'deadline'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNotSame(ContratoAssinatura::STATUS_EXPIRADO, $contrato->status);
        $this->assertNotNull($contrato->liberado_em);
        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $servico->id)->count()
        );
    }

    #[Test]
    public function evento_cancel_vira_cancelado(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servico('Mentoria');
        $contrato = $this->contratoDeTeste($company, $servico);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $this->fakeEnvelope('running', []);

        $this->processar($this->eventoWebhook($contrato, 'cancel'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_CANCELADO, $contrato->status);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }

    #[Test]
    public function contrato_ja_assinado_recebendo_deadline_tardio_continua_assinado_estados_so_avancam(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servico('Assessoria');
        $contrato = ContratoAssinatura::factory()->comSnapshot()->assinado()->create([
            'company_id'             => $company->id,
            'servico_id'             => $servico->id,
            'clicksign_envelope_id'  => self::ENVELOPE_ID,
            'clicksign_document_id'  => self::DOCUMENT_ID,
        ]);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        // Reconsulta tardia mostra o envelope ainda `running` (cenário
        // hostil) — mesmo assim o contrato NÃO pode regredir.
        $this->fakeEnvelope('running', []);

        $this->processar($this->eventoWebhook($contrato, 'deadline'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNotSame(ContratoAssinatura::STATUS_EXPIRADO, $contrato->status);
    }

    #[Test]
    public function empresa_com_dois_contratos_gestao_liberada_mentoria_recusada_nao_afeta_uma_a_outra(): void
    {
        $company    = Company::factory()->create();
        $gestao     = $this->servico('Gestão de Tráfego');
        $mentoria   = $this->servico('Mentoria');

        // Gestão já liberada por um contrato ANTERIOR (fora do escopo deste
        // teste — simulado direto pelo router, como o webhook já teria
        // feito num evento anterior).
        $mlbEmpresaExistente = MlbEmpresa::create([
            'nome'       => $company->name,
            'tipo'       => 'ASSESSORIA',
            'company_id' => $company->id,
        ]);
        $liberacaoGestao = $this->router()->liberarEmpresa($company, $gestao, ContratoLiberacao::VIA_WEBHOOK);

        // Contrato da Mentoria, ainda em andamento, vai receber a recusa.
        $contratoMentoria = $this->contratoDeTeste($company, $mentoria, [
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'clicksign_document_id' => self::DOCUMENT_ID,
        ]);
        $signerKey = '00000000-0000-4000-8000-000000000072';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contratoMentoria->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $this->fakeEnvelope('closed', [$this->eventoDocumentoRefusal($signerKey)]);
        $this->processar($this->eventoWebhook($contratoMentoria, 'refusal'));

        $contratoMentoria->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_RECUSADO, $contratoMentoria->status);

        // A liberação da Gestão continua intacta.
        $this->assertNotNull(ContratoLiberacao::find($liberacaoGestao->id));
        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $gestao->id)->count()
        );
        // A MlbEmpresa não é tocada nem duplicada.
        $this->assertSame(1, MlbEmpresa::where('company_id', $company->id)->count());
        $this->assertNotNull(MlbEmpresa::find($mlbEmpresaExistente->id));
    }
}
