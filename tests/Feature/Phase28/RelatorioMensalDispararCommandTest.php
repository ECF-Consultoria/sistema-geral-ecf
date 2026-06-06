<?php

namespace Tests\Feature\Phase28;

use App\Jobs\EcfWebhook\HandleRelatorioGeradoJob;
use App\Models\WebhookDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Suite Feature — RelatorioMensalDisparar (comando artisan) — Phase 28.
 *
 * Cobre: período explícito, default mês anterior fechado, período inválido.
 * Usa Queue::fake() para interceptar dispatch sem processar o Job.
 */
class RelatorioMensalDispararCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Comando com período explícito: cria WebhookDelivery correto + dispatcha Job.
     */
    public function test_comando_com_periodo_explicito_cria_delivery_e_dispatcha_job(): void
    {
        Queue::fake();

        $this->artisan('relatorios:disparar-mensal', ['periodo' => '202605'])
            ->assertExitCode(0)
            ->expectsOutputToContain('202605');

        // WebhookDelivery criado com event_type e status corretos
        $this->assertDatabaseHas('webhook_deliveries', [
            'event_type' => 'relatorio.gerado',
            'status'     => 'received',
        ]);

        // Verifica payload e event_id do delivery criado
        $delivery = WebhookDelivery::where('event_type', 'relatorio.gerado')->first();
        $this->assertNotNull($delivery);
        $this->assertStringStartsWith('manual-202605-', $delivery->event_id, 'event_id deve ter prefixo manual- para evitar colisao UNIQUE');
        $this->assertEquals('202605', $delivery->payload['data']['periodo']);

        // Job deve ter sido pushed para a queue (não executado — Queue::fake intercepta)
        Queue::assertPushed(HandleRelatorioGeradoJob::class, 1);
    }

    /**
     * Sem argumento: default mês anterior fechado (Carbon::subMonthNoOverflow).
     */
    public function test_comando_sem_argumento_usa_mes_anterior_fechado(): void
    {
        Queue::fake();

        // Calcula o mesmo default que o comando usaria
        $mesEsperado = Carbon::now()->subMonthNoOverflow()->format('Ym');

        $this->artisan('relatorios:disparar-mensal')
            ->assertExitCode(0);

        $this->assertDatabaseHas('webhook_deliveries', [
            'event_type' => 'relatorio.gerado',
        ]);

        // Verifica que o período do delivery é o mês anterior
        $delivery = WebhookDelivery::where('event_type', 'relatorio.gerado')->first();
        $this->assertStringStartsWith("manual-{$mesEsperado}-", $delivery->event_id);
        $this->assertEquals($mesEsperado, $delivery->payload['data']['periodo']);

        Queue::assertPushed(HandleRelatorioGeradoJob::class, 1);
    }

    /**
     * Período inválido: comando retorna exit code 1, nenhum delivery criado, nenhum Job pushed.
     */
    public function test_comando_rejeita_periodo_invalido(): void
    {
        Queue::fake();

        $this->artisan('relatorios:disparar-mensal', ['periodo' => 'invalido'])
            ->assertExitCode(1);

        // Nenhum delivery deve ter sido criado
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('webhook_deliveries', [
            'event_type' => 'relatorio.gerado',
        ]);
    }
}
