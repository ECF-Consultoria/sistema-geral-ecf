<?php

namespace Tests\Feature\Phase130;

use App\Console\Commands\ClicksignReconciliar;
use App\Jobs\BaixarPdfContratoAssinadoJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130, plano 130-03, Task 2 (D-08) — prova que `clicksign:reconciliar`
 * redispara `BaixarPdfContratoAssinadoJob` para todo contrato `assinado` sem
 * PDF local, mesmo quando uma tentativa anterior já falhou
 * (`pdf_assinado_erro` preenchido) — o job já reconsulta o envelope a cada
 * tentativa e obtém link fresco (D-12 da Fase 129), então redisparar dias
 * depois é seguro.
 */
class ReconciliacaoPdfPendenteTest extends TestCase
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

    private function contratoAssinado(Servico $servico, array $overrides = []): ContratoAssinatura
    {
        $company = Company::factory()->create();

        return ContratoAssinatura::factory()->comSnapshot()->create(array_merge([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_ASSINADO,
            'liberado_em' => now(),
        ], $overrides));
    }

    #[Test]
    public function contrato_assinado_com_pdf_pendente_e_redisparado(): void
    {
        Queue::fake();
        $servico  = $this->servico();
        $contrato = $this->contratoAssinado($servico, ['pdf_assinado_path' => null]);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertPushed(
            BaixarPdfContratoAssinadoJob::class,
            fn (BaixarPdfContratoAssinadoJob $job) => $job->contratoAssinatura->id === $contrato->id
        );
    }

    #[Test]
    public function contrato_assinado_com_pdf_ja_baixado_nao_e_redisparado(): void
    {
        Queue::fake();
        $servico = $this->servico();
        $this->contratoAssinado($servico, ['pdf_assinado_path' => 'contratos/999/assinado.pdf']);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertNotPushed(BaixarPdfContratoAssinadoJob::class);
    }

    #[Test]
    public function contrato_com_erro_anterior_de_download_e_redisparado_mesmo_assim(): void
    {
        Queue::fake();
        $servico  = $this->servico();
        $contrato = $this->contratoAssinado($servico, [
            'pdf_assinado_path' => null,
            'pdf_assinado_erro' => 'Download do PDF assinado falhou com status 403.',
        ]);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertPushed(
            BaixarPdfContratoAssinadoJob::class,
            fn (BaixarPdfContratoAssinadoJob $job) => $job->contratoAssinatura->id === $contrato->id
        );
    }

    #[Test]
    public function carimbo_reconsultado_traz_pdfs_redisparados_igual_ao_numero_de_pendentes(): void
    {
        Queue::fake();
        $servico = $this->servico();

        // Caso 1: nunca tentou.
        $this->contratoAssinado($servico, ['pdf_assinado_path' => null]);

        // Caso 3: tentou e falhou.
        $this->contratoAssinado($servico, [
            'pdf_assinado_path' => null,
            'pdf_assinado_erro' => 'timeout',
        ]);

        // Não deve entrar na contagem — já tem PDF.
        $this->contratoAssinado($servico, ['pdf_assinado_path' => 'contratos/1000/assinado.pdf']);

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        $statusFresco = json_decode(Configuracao::get(ClicksignReconciliar::CHAVE_STATUS), true);

        $this->assertSame(2, $statusFresco['pdfs_redisparados']);
        Queue::assertPushed(BaixarPdfContratoAssinadoJob::class, 2);
    }
}
