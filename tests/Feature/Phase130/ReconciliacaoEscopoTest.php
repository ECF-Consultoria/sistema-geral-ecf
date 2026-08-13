<?php

namespace Tests\Feature\Phase130;

use App\Console\Commands\ClicksignReconciliar;
use App\Jobs\BaixarPdfContratoAssinadoJob;
use App\Jobs\ReconciliarContratoClicksignJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130, plano 130-03, Task 2 (D-06/D-09) — prova que
 * `clicksign:reconciliar` enfileira EXATAMENTE o escopo 1 (D-07:
 * `aguardando_assinaturas` com envelope, ainda não liberado) e deixa de fora
 * de propósito os demais estados — esse recorte é do alerta (REDE-02,
 * plano 130-05), não da varredura.
 */
class ReconciliacaoEscopoTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): Servico
    {
        return Servico::create([
            'nome'          => 'Assessoria',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function contrato(Servico $servico, string $status, array $overrides = []): ContratoAssinatura
    {
        $company = Company::factory()->create();

        return ContratoAssinatura::factory()->comSnapshot()->create(array_merge([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => $status,
        ], $overrides));
    }

    #[Test]
    public function estados_fora_do_escopo_da_varredura_nunca_despacham_o_job_de_reconciliacao(): void
    {
        Queue::fake();
        $servico = $this->servico();

        $foraDoEscopo = [
            ContratoAssinatura::STATUS_RASCUNHO,
            ContratoAssinatura::STATUS_RECUSADO,
            ContratoAssinatura::STATUS_EXPIRADO,
            ContratoAssinatura::STATUS_CANCELADO,
            ContratoAssinatura::STATUS_ERRO,
        ];

        $ids = [];
        foreach ($foraDoEscopo as $status) {
            $ids[] = $this->contrato($servico, $status)->id;
        }

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        foreach ($ids as $id) {
            Queue::assertNotPushed(
                ReconciliarContratoClicksignJob::class,
                fn (ReconciliarContratoClicksignJob $job) => $job->contratoAssinatura->id === $id
            );
        }
    }

    #[Test]
    public function aguardando_assinaturas_com_envelope_despacha_o_job_uma_vez(): void
    {
        Queue::fake();
        $servico = $this->servico();

        $contrato = $this->contrato($servico, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, [
            'clicksign_envelope_id' => '00000000-0000-4000-8000-000000000060',
        ]);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertPushed(
            ReconciliarContratoClicksignJob::class,
            fn (ReconciliarContratoClicksignJob $job) => $job->contratoAssinatura->id === $contrato->id
        );
        Queue::assertPushed(ReconciliarContratoClicksignJob::class, 1);
    }

    #[Test]
    public function aguardando_assinaturas_sem_envelope_nao_e_despachado(): void
    {
        Queue::fake();
        $servico = $this->servico();

        $this->contrato($servico, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, [
            'clicksign_envelope_id' => null,
        ]);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertNotPushed(ReconciliarContratoClicksignJob::class);
    }

    #[Test]
    public function aguardando_assinaturas_ja_liberado_nao_e_despachado(): void
    {
        Queue::fake();
        $servico = $this->servico();

        $this->contrato($servico, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, [
            'clicksign_envelope_id' => '00000000-0000-4000-8000-000000000061',
            'liberado_em'           => now(),
        ]);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertNotPushed(ReconciliarContratoClicksignJob::class);
    }

    #[Test]
    public function carimbo_de_execucao_e_gravado_com_contagens_corretas(): void
    {
        Queue::fake();
        $servico = $this->servico();

        // 1 no escopo 1 (D-07)
        $this->contrato($servico, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, [
            'clicksign_envelope_id' => '00000000-0000-4000-8000-000000000062',
        ]);

        // 1 no escopo 2 (D-08)
        $this->contrato($servico, ContratoAssinatura::STATUS_ASSINADO, [
            'pdf_assinado_path' => null,
        ]);

        // Fora de qualquer escopo — não deve contar em nenhuma das duas colunas
        $this->contrato($servico, ContratoAssinatura::STATUS_RECUSADO);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        $statusFresco = json_decode(Configuracao::get(ClicksignReconciliar::CHAVE_STATUS), true);

        $this->assertNotNull($statusFresco);
        $this->assertNotNull($statusFresco['executado_em']);
        $this->assertNull($statusFresco['erro']);
        $this->assertSame(2, $statusFresco['vistos']);
        $this->assertSame(1, $statusFresco['corrigidos']);
        $this->assertSame(1, $statusFresco['pdfs_redisparados']);

        Queue::assertPushed(BaixarPdfContratoAssinadoJob::class, 1);
    }
}
