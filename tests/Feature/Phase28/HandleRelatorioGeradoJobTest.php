<?php

namespace Tests\Feature\Phase28;

use App\Jobs\EcfWebhook\HandleRelatorioGeradoJob;
use App\Mail\RelatorioMensalMail;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\EcfDriveService;
use App\Services\RelatorioMensalPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suite Feature — HandleRelatorioGeradoJob (Phase 28).
 *
 * Cobre: caminho feliz, múltiplos destinatários, zero admins ativos,
 * falha da API ECF Drive e idempotência de sobrescrita do PDF.
 * Usa Mail::fake() + Storage::fake('local') + mock EcfDriveService + mock RelatorioMensalPdfService.
 * RelatorioMensalPdfService é mockado com PDF fake (assinatura %PDF) — Dompdf não disponível em CI.
 */
class HandleRelatorioGeradoJobTest extends TestCase
{
    use RefreshDatabase;

    /** Conteúdo fake de PDF com assinatura válida para testes */
    private const PDF_FAKE = '%PDF-1.4 fake pdf content for testing';

    /** Cria WebhookDelivery mock com payload válido para testar o Job */
    private function criarDelivery(string $periodo = '202605', string $status = 'received'): WebhookDelivery
    {
        return WebhookDelivery::create([
            'event_id'        => 'test-' . $periodo . '-' . uniqid(),
            'event_type'      => 'relatorio.gerado',
            'payload'         => ['data' => ['periodo' => $periodo]],
            'signature_valid' => true,
            'received_at'     => now(),
            'status'          => $status,
            'ip_address'      => '127.0.0.1',
        ]);
    }

    /**
     * Mock do EcfDriveService retornando shape mínimo válido do /relatorios/mensal/.
     * Inclui todos os subnós para testar o PDF com seções completas.
     */
    private function mockEcfDriveOk(): void
    {
        $this->mock(EcfDriveService::class, function ($m) {
            $m->shouldReceive('relatorioMensal')->andReturn([
                'periodo'      => '202605',
                'geradoEm'     => '2026-06-05T20:41:50Z',
                'resumo'       => [
                    'gmv'             => ['atual' => 42859191.37, 'deltaPct' => -11.74],
                    'vendas'          => ['atual' => 357531,      'deltaPct' => 1.83],
                    'sellersAtivos'   => ['atual' => 1238,        'deltaPct' => 15.70],
                    'investimentoAds' => ['atual' => 1540459.82,  'deltaPct' => -16.70],
                    'gmvAds'          => ['atual' => 15341245.54, 'deltaPct' => -7.08],
                    'gmvFull'         => ['atual' => 15166178.20, 'deltaPct' => -16.23],
                    'gmvFlex'         => ['atual' => 762818.88,   'deltaPct' => 11.05],
                    'visitas'         => ['atual' => 12691798,    'deltaPct' => -5.92],
                ],
                'distribuicao' => [
                    'programa' => ['distribuicao' => [
                        ['programa' => 'POLOS', 'count' => 697, 'gmv' => 4782948, 'pct' => 56.30, 'tsi' => 16597],
                        ['programa' => 'CPP',   'count' => 541, 'gmv' => 38076243, 'pct' => 43.70, 'tsi' => 340934],
                    ]],
                    'cluster' => ['distribuicao' => [
                        ['cluster' => 'Core',    'count' => 90, 'gmv' => 17000000, 'pct' => 40.0],
                        ['cluster' => 'MeliPro', 'count' => 20, 'gmv' => 12200000, 'pct' => 28.5],
                    ]],
                ],
                'rankings'     => [
                    'topGmv' => [
                        ['rank' => 1, 'custId' => 'A', 'razaoSocial' => 'EMPRESA A', 'cnpj' => '11111111000111', 'programa' => 'CPP', 'nivelSolucion' => 'PLATINUM', 'valor' => 2733708.08],
                    ],
                ],
                'signals'      => ['total' => 778, 'porTipoESeveridade' => []],
            ]);
        });
    }

    /**
     * Mock do RelatorioMensalPdfService retornando binário PDF fake com assinatura %PDF.
     * Necessário pois barryvdh/laravel-dompdf pode não estar disponível no ambiente de testes.
     */
    private function mockPdfServiceOk(): void
    {
        $this->mock(RelatorioMensalPdfService::class, function ($m) {
            $m->shouldReceive('gerar')->andReturn(self::PDF_FAKE);
        });
    }

    /**
     * Caminho feliz: Job processa, grava PDF no storage, envia email pro admin único, marca processed.
     */
    public function test_caminho_feliz_processa_grava_pdf_envia_email_marca_processed(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->mockEcfDriveOk();
        $this->mockPdfServiceOk();

        $admin    = User::factory()->create(['role' => 'admin', 'active' => true, 'email' => 'admin@example.com']);
        $delivery = $this->criarDelivery('202605');

        (new HandleRelatorioGeradoJob($delivery->id))->handle(
            app(EcfDriveService::class),
            app(RelatorioMensalPdfService::class),
        );

        // PDF gravado no storage fake
        Storage::disk('local')->assertExists('relatorios/relatorio-202605.pdf');

        // 1 email enviado pro admin único
        Mail::assertSent(RelatorioMensalMail::class, 1);
        Mail::assertSent(RelatorioMensalMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));

        // Delivery marcado processed com timestamp
        $delivery->refresh();
        $this->assertEquals('processed', $delivery->status);
        $this->assertNotNull($delivery->processed_at);
    }

    /**
     * 2 admins ativos + 1 inativo + 1 consultor: apenas os 2 admins ativos recebem.
     * Mail::to(Collection) faz 1 send interno com múltiplos destinatários.
     */
    public function test_2_admins_ativos_e_1_inativo_apenas_2_recebem(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->mockEcfDriveOk();
        $this->mockPdfServiceOk();

        User::factory()->create(['role' => 'admin',    'active' => true,  'email' => 'a1@example.com']);
        User::factory()->create(['role' => 'admin',    'active' => true,  'email' => 'a2@example.com']);
        User::factory()->create(['role' => 'admin',    'active' => false, 'email' => 'inativo@example.com']);
        User::factory()->create(['role' => 'consultor','active' => true,  'email' => 'consultor@example.com']);

        $delivery = $this->criarDelivery('202605');

        (new HandleRelatorioGeradoJob($delivery->id))->handle(
            app(EcfDriveService::class),
            app(RelatorioMensalPdfService::class),
        );

        // 1 send totalizando 2 destinatários (Mail::to(Collection) faz 1 envio com múltiplos to)
        Mail::assertSent(RelatorioMensalMail::class, 1);
        Mail::assertSent(RelatorioMensalMail::class, fn ($mail) => $mail->hasTo('a1@example.com'));
        Mail::assertSent(RelatorioMensalMail::class, fn ($mail) => $mail->hasTo('a2@example.com'));
        Mail::assertNotSent(RelatorioMensalMail::class, fn ($mail) => $mail->hasTo('inativo@example.com'));
        Mail::assertNotSent(RelatorioMensalMail::class, fn ($mail) => $mail->hasTo('consultor@example.com'));
    }

    /**
     * Zero admins ativos: PDF gravado, delivery marcado processed, zero emails.
     * Sem admins não é falha — Job loga warning e segue (PDF preservado em storage).
     */
    public function test_zero_admins_ativos_pdf_gravado_delivery_processed_zero_emails(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->mockEcfDriveOk();
        $this->mockPdfServiceOk();

        // Apenas admin inativo no banco
        User::factory()->create(['role' => 'admin', 'active' => false]);

        $delivery = $this->criarDelivery('202605');

        (new HandleRelatorioGeradoJob($delivery->id))->handle(
            app(EcfDriveService::class),
            app(RelatorioMensalPdfService::class),
        );

        // PDF foi gravado mesmo sem destinatários
        Storage::disk('local')->assertExists('relatorios/relatorio-202605.pdf');

        // Sem emails enviados (sem admins ativos)
        Mail::assertNothingSent();

        // Delivery ainda marcado processed — sem admins não é erro
        $delivery->refresh();
        $this->assertEquals('processed', $delivery->status, 'Sem admins não é falha — PDF foi gerado');
    }

    /**
     * Falha da API ECF Drive: delivery marcado failed, exception propaga, zero emails.
     * Queue worker conta tentativa; após 3 tenta, cai em failed_jobs.
     */
    public function test_falha_ecf_drive_marca_delivery_failed_e_propaga(): void
    {
        Mail::fake();
        Storage::fake('local');

        // ECF Drive offline — RuntimeException propaga e o queue worker conta tentativa
        $this->mock(EcfDriveService::class, function ($m) {
            $m->shouldReceive('relatorioMensal')->andThrow(new \RuntimeException('ECF Drive offline'));
        });

        // PDF service não chega a ser chamado (ECF Drive falha antes) — mock preventivo
        $this->mockPdfServiceOk();

        User::factory()->create(['role' => 'admin', 'active' => true]);
        $delivery = $this->criarDelivery('202605');

        $this->expectException(\RuntimeException::class);

        try {
            (new HandleRelatorioGeradoJob($delivery->id))->handle(
                app(EcfDriveService::class),
                app(RelatorioMensalPdfService::class),
            );
        } finally {
            // Mesmo com exception, delivery deve ter sido marcado failed
            $delivery->refresh();
            $this->assertEquals('failed', $delivery->status);
            $this->assertNotNull($delivery->error_message);
            Mail::assertNothingSent();
        }
    }

    /**
     * Idempotência: PDF existente é sobrescrito, email é enviado normalmente.
     * Re-execução manual (comando disparar-mensal mesmo período) deve funcionar.
     */
    public function test_idempotencia_sobrescreve_pdf_e_envia_email_novamente(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->mockEcfDriveOk();
        $this->mockPdfServiceOk();

        User::factory()->create(['role' => 'admin', 'active' => true]);

        // Pré-condição: PDF antigo já existe no storage
        Storage::disk('local')->put('relatorios/relatorio-202605.pdf', 'PDF-ANTIGO');

        $delivery = $this->criarDelivery('202605');

        (new HandleRelatorioGeradoJob($delivery->id))->handle(
            app(EcfDriveService::class),
            app(RelatorioMensalPdfService::class),
        );

        // PDF sobrescrito — não deve mais ser 'PDF-ANTIGO'
        $conteudo = Storage::disk('local')->get('relatorios/relatorio-202605.pdf');
        $this->assertNotEquals('PDF-ANTIGO', $conteudo);
        $this->assertStringStartsWith('%PDF', $conteudo, 'PDF sobrescrito com binário Dompdf válido');

        // Email foi enviado normalmente
        Mail::assertSent(RelatorioMensalMail::class, 1);
    }
}
