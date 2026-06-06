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
 * Handler do evento grant.expirando — grant de empresa vence em 30/15/7 dias.
 *
 * Ação: envia Notification para todos os usuários com permissão core.grants.
 * Payload contém: cust_id, cnpj, grant_fim, threshold (dias restantes).
 *
 * N+1 aceitável — universo de users < 50 (D-L do PLAN, memory: feedback_lean_planning).
 * Admin recebe por default (isAdmin() faz short-circuit em hasPermission).
 */
class HandleGrantExpirandoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $webhookDeliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            $payload   = $delivery->payload['data'] ?? [];
            $custId    = $payload['cust_id']      ?? null;
            $cnpj      = $payload['cnpj']          ?? null;
            $grantFim  = $payload['grant_fim']     ?? null;
            $threshold = $payload['threshold']     ?? null; // 30, 15 ou 7
            $razao     = $payload['razao_social']  ?? $cnpj ?? 'empresa sem nome';

            // Filtra usuários com permissão core.grants (admin recebe por short-circuit)
            $usuarios = User::where('active', true)
                ->get()
                ->filter(fn($u) => $u->hasPermission('core.grants'));

            if ($usuarios->isNotEmpty()) {
                $diasLabel = $threshold ? " ({$threshold} dias restantes)" : '';
                $titulo    = "[ECF Drive] Grant expirando{$diasLabel}: {$razao}";
                $mensagem  = "O grant da empresa {$razao}"
                    . ($cnpj ? " (CNPJ: {$cnpj})" : '')
                    . " vence em {$threshold} dias"
                    . ($grantFim ? " — data: {$grantFim}" : '')
                    . '. Verifique a aba Grants.';

                // BaseNotification anônima inline — D-E do PLAN (sem subclasse concreta)
                Notification::send(
                    $usuarios,
                    new class(
                        $titulo,
                        $mensagem,
                        Categoria::MANUAL,
                        null,
                        route('grants.index'),
                        [
                            'evento'    => 'grant.expirando',
                            'cust_id'   => $custId,
                            'cnpj'      => $cnpj,
                            'grant_fim' => $grantFim,
                            'threshold' => $threshold,
                        ]
                    ) extends BaseNotification {}
                );
            }

            Log::channel('ecf-webhooks')->info('[ECF Webhook] grant.expirando processado', [
                'event_id'        => $delivery->event_id,
                'cust_id'         => $custId,
                'threshold'       => $threshold,
                'usuarios_count'  => $usuarios->count(),
                'ip_address'      => $delivery->ip_address,
            ]);

            $delivery->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em grant.expirando', [
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
        Log::channel('ecf-webhooks')->error('[ECF Webhook] HandleGrantExpirandoJob FALHOU definitivamente', [
            'delivery_id' => $this->webhookDeliveryId,
            'error'       => $e->getMessage(),
        ]);
    }
}
