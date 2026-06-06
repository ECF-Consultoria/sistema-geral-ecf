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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Handler do evento signal.detected — ECF Drive detectou um signal estratégico.
 *
 * Ações:
 * 1. Invalida cache das chaves ecf.signals.* (Phase 23) para forçar re-fetch.
 * 2. Se severity=critical: envia Notification para admins (D-P do PLAN).
 *    Signals warning/info já aparecem via polling — push apenas para críticos
 *    evita ruído (ECF Drive gera dezenas de signals/dia, ~5% são critical).
 */
class HandleSignalDetectedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $webhookDeliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            $payload  = $delivery->payload['data'] ?? [];
            $severity = $payload['severity'] ?? 'info';

            // Invalida cache de signals independente da severity (dados stale)
            $chaveSignals = 'ecf.signals.' . md5(http_build_query(['acked' => false, 'limit' => 50, 'page' => 1]));
            Cache::forget($chaveSignals);

            // Notificação SOMENTE para signals críticos — D-P do PLAN
            if ($severity === 'critical') {
                $admins = User::where('role', 'admin')->where('active', true)->get();

                if ($admins->isNotEmpty()) {
                    $signalId    = $payload['signal_id'] ?? null;
                    $descricao   = $payload['descricao'] ?? $payload['description'] ?? 'sem descrição';
                    $titulo      = '[ECF Drive] Signal crítico detectado';
                    $mensagem    = "Signal crítico detectado pelo ECF Drive: {$descricao}. Verifique os alertas estratégicos.";

                    // BaseNotification anônima inline — D-E do PLAN (sem subclasse concreta)
                    Notification::send(
                        $admins,
                        new class(
                            $titulo,
                            $mensagem,
                            Categoria::MANUAL,
                            null,
                            route('alertas.index'),
                            [
                                'evento'    => 'signal.detected',
                                'severity'  => $severity,
                                'signal_id' => $signalId,
                                'payload'   => $payload,
                            ]
                        ) extends BaseNotification {}
                    );
                }
            }

            Log::channel('ecf-webhooks')->info('[ECF Webhook] signal.detected processado', [
                'event_id'   => $delivery->event_id,
                'severity'   => $severity,
                'notificado' => $severity === 'critical',
                'ip_address' => $delivery->ip_address,
            ]);

            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em signal.detected', [
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
        Log::channel('ecf-webhooks')->error('[ECF Webhook] HandleSignalDetectedJob FALHOU definitivamente', [
            'delivery_id' => $this->webhookDeliveryId,
            'error'       => $e->getMessage(),
        ]);
    }
}
