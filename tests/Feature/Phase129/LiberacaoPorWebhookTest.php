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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 05, Task 1 (CLICK-05, SC3) — prova que
 * `ProcessarEventoClicksignJob` decide a liberação POR RECONSULTA
 * (`GateLiberacaoOperacionalService::avaliar()`) e chama
 * `EmpresaOperacionalRouter::liberarEmpresa()`, uma vez só por (empresa,
 * serviço), independente de quantos eventos diferentes do mesmo envelope
 * forem processados.
 *
 * ⚠️ `Http::fake()` aqui NÃO prova que a forma do payload/resposta está
 * certa — a prova ponta a ponta é o gate humano do plano 129-07. O que esta
 * suíte prova é a FIAÇÃO: reconsulta → gate → `liberarEmpresa()`.
 */
class LiberacaoPorWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const BASE         = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID  = '00000000-0000-4000-8000-000000000020';
    private const DOCUMENT_ID  = '00000000-0000-4000-8000-000000000021';

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

    /** Evento do DOCUMENTO (attributes.name = 'sign') — o que o `ContratoSignatariosSyncService` consome. */
    private function eventoDocumentoSign(string $signerKey, string $criadoEm): array
    {
        return [
            'attributes' => [
                'name'    => 'sign',
                'created' => $criadoEm,
                'data'    => [
                    'signer' => [
                        'key'     => $signerKey,
                        'email'   => 'cliente.gate129@example.com',
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

    private function contratoDeTeste(Servico $servico, array $overrides = []): ContratoAssinatura
    {
        $company = Company::factory()->create();

        return ContratoAssinatura::factory()->comSnapshot()->create(array_merge([
            'company_id'             => $company->id,
            'servico_id'             => $servico->id,
            'status'                 => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'clicksign_envelope_id'  => self::ENVELOPE_ID,
            'clicksign_document_id'  => self::DOCUMENT_ID,
        ], $overrides));
    }

    /** Evento do WEBHOOK (a fila de entrada) — dispara o job. */
    private function eventoWebhook(ContratoAssinatura $contrato, string $name, array $overrides = []): ContratoAssinaturaEvento
    {
        $rawBody = json_encode(['event' => ['name' => $name]]);

        return ContratoAssinaturaEvento::create(array_merge([
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
        ], $overrides));
    }

    private function processar(ContratoAssinaturaEvento $evento): void
    {
        (new ProcessarEventoClicksignJob($evento))->handle($this->client(), $this->sync(), $this->gate(), $this->router());
    }

    #[Test]
    public function envelope_closed_com_contratante_assinado_libera_com_assinado_em_do_evento_sign(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        $signerKey = '00000000-0000-4000-8000-000000000030';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $criadoEm = '2026-08-10T10:00:00.000-03:00';
        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, $criadoEm)]);

        $evento = $this->eventoWebhook($contrato, 'sign');
        $this->processar($evento);

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNotNull($contrato->assinado_em);
        $this->assertSame(
            Carbon::parse($criadoEm)->timestamp,
            $contrato->assinado_em->timestamp,
            'assinado_em precisa vir do created do evento sign, nao de now()'
        );
        $this->assertNotNull($contrato->liberado_em);

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('servico_id', $servico->id)
                ->where('via', ContratoLiberacao::VIA_WEBHOOK)
                ->count()
        );
    }

    #[Test]
    public function servico_que_gera_ficha_cria_mlbempresa(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        $signerKey = '00000000-0000-4000-8000-000000000031';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())]);

        $this->processar($this->eventoWebhook($contrato, 'sign'));

        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $contrato->company_id)->where('tipo', 'ASSESSORIA')->count()
        );
    }

    #[Test]
    public function servico_que_nao_gera_ficha_libera_sem_criar_mlbempresa(): void
    {
        $servico  = $this->servico('Gestão');
        $contrato = $this->contratoDeTeste($servico);

        $signerKey = '00000000-0000-4000-8000-000000000032';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())]);

        $this->processar($this->eventoWebhook($contrato, 'sign'));

        $liberacao = ContratoLiberacao::where('company_id', $contrato->company_id)
            ->where('servico_id', $servico->id)
            ->first();

        $this->assertNotNull($liberacao, 'D-03: a liberacao precisa constar mesmo quando o servico nao gera ficha');
        $this->assertFalse($liberacao->gerou_ficha);
        $this->assertSame(0, MlbEmpresa::where('company_id', $contrato->company_id)->count());
    }

    #[Test]
    public function dois_eventos_diferentes_do_mesmo_envelope_geram_uma_unica_liberacao_e_ficha(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        $signerKey = '00000000-0000-4000-8000-000000000033';
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => $signerKey,
        ]);

        $criadoEm = now()->toIso8601String();

        // Primeiro evento: `sign` — o envelope já reconsulta como `closed`
        // e contratante assinado (a rajada de retroativos da ativação pode
        // entregar isto fora de ordem, D-06/D-07).
        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, $criadoEm)]);
        $this->processar($this->eventoWebhook($contrato, 'sign'));

        // Segundo evento: `document_closed` do MESMO envelope — a reconsulta
        // devolve o mesmo estado já liberado. `liberarEmpresa()` precisa ser
        // idempotente por construção (guard interno, não duplicado aqui).
        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, $criadoEm)]);
        $this->processar($this->eventoWebhook($contrato, 'document_closed'));

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)->where('servico_id', $servico->id)->count()
        );
        $this->assertSame(1, MlbEmpresa::where('company_id', $contrato->company_id)->count());
    }

    #[Test]
    public function envelope_closed_com_contratante_pendente_nao_libera_e_contrato_nao_vira_assinado(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        // Nenhum evento `sign` no documento — o fechamento é o do prazo
        // vencido com assinatura parcial (Pitfall A / achado medido do
        // `deadline_partial_signature_action: "closed"`).
        $this->fakeEnvelope('closed', []);

        $this->processar($this->eventoWebhook($contrato, 'document_closed'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $contrato->status);
        $this->assertNull($contrato->liberado_em);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $contrato->company_id)->count());
        $this->assertSame(0, MlbEmpresa::where('company_id', $contrato->company_id)->count());
    }

    #[Test]
    public function assinado_em_usa_now_como_ultimo_recurso_quando_o_contratante_nao_tem_data_gravada(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        // Caso extremo: signatário já `assinou` no nosso banco (ex.:
        // corrigido manualmente ou sincronizado por outra via) mas sem
        // `assinado_em` gravado — o `max()` do contratante devolve `null` e
        // o job precisa cair no último recurso (`now()`), nunca lançar.
        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_ASSINOU,
            'assinado_em'            => null,
        ]);

        $this->fakeEnvelope('closed', []);

        $antes = now();
        $this->processar($this->eventoWebhook($contrato, 'document_closed'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNotNull($contrato->assinado_em);
        $this->assertLessThanOrEqual(2, $contrato->assinado_em->diffInSeconds($antes), 'assinado_em precisa cair no now() do processamento (ultimo recurso)');
    }
}
