<?php

namespace App\Jobs\EcfWebhook;

use App\Models\Company;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\AlertaEcfNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Handler do evento signal.detected — ECF Drive detectou um signal estratégico.
 *
 * Pipeline (Phase 29):
 * 1. Extrai dados do payload do WebhookDelivery.
 * 2. Invalida cache de signals (Phase 23) — independente da severity.
 * 3. Filtra severity: apenas `critical` prossegue. warning/info → processed + return.
 * 4. Filtra cust_id nulo → processed + return.
 * 5. Lookup de carteira: Company por adman_account_id OR ml_store_id + active=true.
 *    Fora da carteira → processed + return (sem notification — sem ruído).
 * 6. Guard idempotência: signal_id já notificado → processed + return.
 * 7. Query destinatários: admin + consultor + mentor ativos.
 * 8. Notification::send com AlertaEcfNotification (canal database, Phase 8).
 * 9. Marca delivery processed + log de sucesso.
 *
 * tries=3, timeout=60 preservados da Phase 26.
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
            // ─── 1. Extrai campos do payload ───
            $dados      = $delivery->payload['data'] ?? [];
            $severity   = $dados['severity']  ?? 'info';
            $eventType  = $dados['eventType'] ?? null;
            $custId     = $dados['custId']    ?? null;
            $signalId   = $dados['id']        ?? null;
            $inner      = $dados['payload']   ?? [];

            // ─── 2. Invalida cache de signals independente da severity ───
            // Phase 23: /alertas-estrategicos cacheia a listagem por acked+page.
            // Qualquer signal novo (inclusive warning/info) deve forçar re-fetch.
            $chaveSignals = 'ecf.signals.' . md5(http_build_query(['acked' => false, 'limit' => 50, 'page' => 1]));
            Cache::forget($chaveSignals);

            // ─── 3. Filtro de severity — apenas critical dispara notification ───
            if ($severity !== 'critical') {
                Log::channel('ecf-webhooks')->info('[Signal] Severity não-crítica ignorada — sem notification', [
                    'delivery_id' => $delivery->id,
                    'event_type'  => $eventType,
                    'severity'    => $severity,
                ]);
                $delivery->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            // ─── 4. Filtra cust_id nulo ───
            if ($custId === null) {
                Log::channel('ecf-webhooks')->warning('[Signal] custId ausente no payload — ignorado', [
                    'delivery_id' => $delivery->id,
                    'event_type'  => $eventType,
                    'signal_id'   => $signalId,
                ]);
                $delivery->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            // ─── 5. Lookup da carteira ───
            // Compara custId contra adman_account_id E ml_store_id (empresa pode ter ambos).
            // Cast (string) garante compat MySQL (VARCHAR) e SQLite (TEXT) — API pode enviar int.
            $company = Company::where('active', true)
                ->where(function ($q) use ($custId) {
                    $q->where('adman_account_id', (string) $custId)
                      ->orWhere('ml_store_id', (string) $custId);
                })
                ->first(['id', 'name', 'adman_account_id', 'ml_store_id']);

            if (!$company) {
                Log::channel('ecf-webhooks')->info('[Signal] Empresa fora da carteira — notificação não criada', [
                    'delivery_id' => $delivery->id,
                    'cust_id'     => $custId,
                    'event_type'  => $eventType,
                ]);
                $delivery->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            // ─── 6. Guard de idempotência por signal_id ───
            // Mesmo signal re-emitido pelo ECF Drive (raro) não duplica notificações.
            // data->meta->signal_id: Laravel compila para JSON_EXTRACT(data, '$.meta.signal_id').
            $jaExiste = DatabaseNotification::where('data->meta->signal_id', (string) $signalId)->exists();

            if ($jaExiste) {
                Log::channel('ecf-webhooks')->info('[Signal] Notificação já criada para este signal — skip idempotência', [
                    'delivery_id' => $delivery->id,
                    'signal_id'   => (string) $signalId,
                    'cust_id'     => $custId,
                ]);
                $delivery->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            // ─── 7. Destinatários: admin + consultor + mentor ativos ───
            // Consultados no momento do handle (query fresh — D-07 CONTEXT).
            // publication_role_legacy (publicadores/analistas MLB) NÃO recebe.
            $usuarios = User::whereIn('role', ['admin', 'consultor', 'mentor'])
                ->where('active', true)
                ->get(['id', 'email', 'name', 'role']);

            if ($usuarios->isEmpty()) {
                Log::channel('ecf-webhooks')->warning('[Signal] Nenhum destinatário ativo encontrado — signal registrado sem notification', [
                    'delivery_id' => $delivery->id,
                    'signal_id'   => (string) $signalId,
                    'empresa'     => $company->name,
                ]);
                $delivery->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            // ─── 8. Envia notification (canal database, Phase 8) ───
            // Cria N linhas na tabela notifications (uma por usuário).
            Notification::send($usuarios, new AlertaEcfNotification(
                signalId:        $signalId,
                eventType:       $eventType,
                custId:          (string) $custId,
                empresaNome:     $company->name,
                payloadResumido: $inner,
                link:            '/alertas-estrategicos',
            ));

            // ─── 9. Marca delivery processed + log de sucesso ───
            $delivery->update(['status' => 'processed', 'processed_at' => now()]);

            Log::channel('ecf-webhooks')->info('[Signal] Notificações criadas com sucesso', [
                'delivery_id'    => $delivery->id,
                'signal_id'      => (string) $signalId,
                'event_type'     => $eventType,
                'empresa'        => $company->name,
                'cust_id'        => $custId,
                'destinatarios'  => $usuarios->count(),
            ]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em signal.detected', [
                'delivery_id' => $delivery->id,
                'error'       => $e->getMessage(),
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
