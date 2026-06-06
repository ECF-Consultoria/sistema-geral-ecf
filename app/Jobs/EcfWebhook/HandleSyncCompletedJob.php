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
 * Handler do evento sync.completed — ECF Drive finalizou um sync SFTP com sucesso.
 *
 * Ação: apenas log informativo. Não há cache a invalidar (etl.completed cobre isso)
 * e não há notificação necessária (sync OK = comportamento esperado).
 * Phase 28 pode adicionar lógica adicional aqui se necessário.
 */
class HandleSyncCompletedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $webhookDeliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            // sync.completed é informativo — apenas registramos no log dedicado
            Log::channel('ecf-webhooks')->info('[ECF Webhook] sync.completed recebido', [
                'event_id'   => $delivery->event_id,
                'ip_address' => $delivery->ip_address,
                'payload'    => $delivery->payload['data'] ?? [],
            ]);

            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em sync.completed', [
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
        Log::channel('ecf-webhooks')->error('[ECF Webhook] HandleSyncCompletedJob FALHOU definitivamente', [
            'delivery_id' => $this->webhookDeliveryId,
            'error'       => $e->getMessage(),
        ]);
    }
}
