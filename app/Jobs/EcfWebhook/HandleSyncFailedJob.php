<?php

namespace App\Jobs\EcfWebhook;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\BaseNotification;
use App\Notifications\Categoria;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Handler do evento sync.failed — ECF Drive falhou no sync SFTP.
 *
 * Ação: envia Notification crítica para todos os usuários com role=admin.
 * Usa BaseNotification anônima inline (D-E do PLAN — sem subclasse concreta).
 * Categoria: MANUAL (único valor disponível para eventos do parceiro, D-05 Phase 12).
 */
class HandleSyncFailedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $webhookDeliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            $admins = User::where('role', 'admin')->where('active', true)->get();

            if ($admins->isNotEmpty()) {
                $payload  = $delivery->payload['data'] ?? [];
                $titulo   = '[ECF Drive] Sync SFTP falhou';
                $mensagem = 'O sync do ECF Drive falhou: '
                    . ($payload['mensagem'] ?? $payload['error'] ?? 'sem detalhes do parceiro')
                    . '. Verifique o painel ECF Drive.';

                // BaseNotification anônima inline — D-E do PLAN (sem subclasse concreta)
                Notification::send(
                    $admins,
                    new class(
                        $titulo,
                        $mensagem,
                        Categoria::MANUAL,
                        null,
                        route('dev.desenvolvimento'),
                        ['evento' => 'sync.failed', 'payload' => $payload]
                    ) extends BaseNotification {}
                );
            }

            Log::channel('ecf-webhooks')->error('[ECF Webhook] sync.failed processado — admins notificados', [
                'event_id'       => $delivery->event_id,
                'admins_count'   => $admins->count(),
                'ip_address'     => $delivery->ip_address,
            ]);

            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em sync.failed', [
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
        Log::channel('ecf-webhooks')->error('[ECF Webhook] HandleSyncFailedJob FALHOU definitivamente', [
            'delivery_id' => $this->webhookDeliveryId,
            'error'       => $e->getMessage(),
        ]);
    }
}
