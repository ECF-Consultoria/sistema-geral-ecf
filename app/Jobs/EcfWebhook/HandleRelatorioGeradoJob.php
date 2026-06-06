<?php

namespace App\Jobs\EcfWebhook;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Handler do evento relatorio.gerado — ECF Drive gerou o relatório mensal.
 *
 * Ação: log apenas nesta fase.
 * Phase 28 implementará o download do PDF + envio por email para a liderança.
 * Disparado dia 5 de cada mês às ~09:00 UTC.
 */
class HandleRelatorioGeradoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $webhookDeliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            $payload      = $delivery->payload['data'] ?? [];
            $relatorioId  = $payload['relatorio_id'] ?? null;
            $periodo      = $payload['periodo'] ?? null;
            $url          = $payload['url'] ?? null;

            // Por ora: log apenas. Phase 28 implementa download PDF + email liderança.
            Log::channel('ecf-webhooks')->info('[ECF Webhook] relatorio.gerado recebido', [
                'event_id'     => $delivery->event_id,
                'relatorio_id' => $relatorioId,
                'periodo'      => $periodo,
                'url'          => $url,
                'ip_address'   => $delivery->ip_address,
                'nota'         => 'Phase 28 implementará download PDF + email',
            ]);

            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em relatorio.gerado', [
                'event_id' => $delivery->event_id,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $delivery = WebhookDelivery::find($this->webhookDeliveryId);
        if ($delivery) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
        Log::channel('ecf-webhooks')->error('[ECF Webhook] HandleRelatorioGeradoJob FALHOU definitivamente', [
            'delivery_id' => $this->webhookDeliveryId,
            'error'       => $e->getMessage(),
        ]);
    }
}
