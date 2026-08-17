<?php

namespace Tests\Feature\Phase130;

use App\Jobs\ReconciliarContratoClicksignJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130, plano 130-03, Task 1 (REDE-04, D-07) — prova que
 * `ReconciliarContratoClicksignJob` corrige sozinho um contrato assinado na
 * Clicksign cujo webhook nunca chegou, pelo MESMO caminho do fluxo
 * automático (`EmpresaOperacionalRouter::liberarEmpresa()`), com
 * `via='reconciliacao'` e sem duplicar liberação.
 *
 * Disciplina do projeto: toda asserção reconsulta o banco (`::find`,
 * `::where(...)->count()`), nunca lê stdout do job.
 */
class ReconciliacaoDivergenciaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000040';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000041';

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

    private function eventoDocumentoSign(string $signerKey, string $criadoEm): array
    {
        return [
            'attributes' => [
                'name'    => 'sign',
                'created' => $criadoEm,
                'data'    => [
                    'signer' => [
                        'key'     => $signerKey,
                        'email'   => 'cliente.gate130@example.com',
                        'address' => '203.0.113.10',
                        'auths'   => ['email'],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function contrato_assinado_na_clicksign_sem_webhook_e_corrigido_pela_reconciliacao(): void
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

        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())]);

        ReconciliarContratoClicksignJob::dispatchSync($contrato);

        $contratoAtualizado = ContratoAssinatura::find($contrato->id);

        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contratoAtualizado->status);
        $this->assertNotNull($contratoAtualizado->liberado_em);

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('servico_id', $servico->id)
                ->where('via', ContratoLiberacao::VIA_RECONCILIACAO)
                ->count()
        );
    }

    #[Test]
    public function rodar_o_job_duas_vezes_no_mesmo_contrato_nao_duplica_liberacao(): void
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

        $this->fakeEnvelope('closed', [$this->eventoDocumentoSign($signerKey, now()->toIso8601String())]);

        ReconciliarContratoClicksignJob::dispatchSync($contrato);

        // Segunda rodada: o contrato já está assinado com liberado_em
        // preenchido — o guard do passo 1 evita a chamada e a liberação
        // idempotente (herdada de EmpresaOperacionalRouter) garante que
        // uma segunda tentativa (se o guard falhasse) não duplicaria.
        ReconciliarContratoClicksignJob::dispatchSync($contrato->fresh());

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('servico_id', $servico->id)
                ->where('via', ContratoLiberacao::VIA_RECONCILIACAO)
                ->count()
        );
    }

    #[Test]
    public function envelope_ainda_em_andamento_nao_libera_nem_cria_contratoliberacao(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $this->fakeEnvelope('running', []);

        ReconciliarContratoClicksignJob::dispatchSync($contrato);

        $contratoAtualizado = ContratoAssinatura::find($contrato->id);

        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $contratoAtualizado->status);
        $this->assertNull($contratoAtualizado->liberado_em);
        $this->assertSame(
            0,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('via', ContratoLiberacao::VIA_RECONCILIACAO)
                ->count()
        );
    }

    #[Test]
    public function contrato_ja_liberado_nunca_chama_a_clicksign(): void
    {
        $servico  = $this->servico('Assessoria');
        $contrato = $this->contratoDeTeste($servico, [
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'liberado_em' => now(),
        ]);

        Http::fake();

        ReconciliarContratoClicksignJob::dispatchSync($contrato);

        Http::assertNothingSent();
    }
}
