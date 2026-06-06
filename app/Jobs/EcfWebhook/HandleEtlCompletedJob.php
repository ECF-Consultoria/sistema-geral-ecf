<?php

namespace App\Jobs\EcfWebhook;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handler do evento etl.completed — ECF Drive terminou de processar um arquivo.
 *
 * Ação: invalida as chaves de cache conhecidas das Phases 22/23/24.
 * Cache driver=database NÃO suporta wildcards — invalidação granular via lista
 * hardcoded (D-F do PLAN). Adicionar chaves novas aqui quando surgirem.
 *
 * Chaves invalidadas:
 * - ecf.carteira.resumo — Phase 24, carteiraResumo()
 * - ecf.signals.{md5} — Phase 23, inbox padrão acked=false,limit=50,page=1
 */
class HandleEtlCompletedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $webhookDeliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            // Lista hardcoded das chaves base conhecidas das Phases 22/23/24.
            // Cache::forget com driver=database não suporta wildcards — limpamos
            // uma a uma. Ao adicionar nova chave nas próximas phases, incluir aqui.
            $chaves = [
                // Phase 24 — Painel Executivo Carteira (carteiraResumo sem params)
                'ecf.carteira.resumo',

                // Phase 23 — inbox de alertas padrão (mesmo md5 que ackSignal invalida)
                'ecf.signals.' . md5(http_build_query(['acked' => false, 'limit' => 50, 'page' => 1])),
            ];

            $esquecidas = 0;
            foreach ($chaves as $chave) {
                $resultado = Cache::forget($chave);
                if ($resultado) {
                    $esquecidas++;
                }
            }

            Log::channel('ecf-webhooks')->info('[ECF Webhook] etl.completed — cache invalidado', [
                'event_id'         => $delivery->event_id,
                'chaves_tentadas'  => count($chaves),
                'chaves_removidas' => $esquecidas,
                'ip_address'       => $delivery->ip_address,
            ]);

            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em etl.completed', [
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
        Log::channel('ecf-webhooks')->error('[ECF Webhook] HandleEtlCompletedJob FALHOU definitivamente', [
            'delivery_id' => $this->webhookDeliveryId,
            'error'       => $e->getMessage(),
        ]);
    }
}
