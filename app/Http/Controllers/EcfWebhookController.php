<?php

namespace App\Http\Controllers;

use App\Jobs\EcfWebhook\HandleEtlCompletedJob;
use App\Jobs\EcfWebhook\HandleGrantExpirandoJob;
use App\Jobs\EcfWebhook\HandleRelatorioGeradoJob;
use App\Jobs\EcfWebhook\HandleSignalDetectedJob;
use App\Jobs\EcfWebhook\HandleSyncCompletedJob;
use App\Jobs\EcfWebhook\HandleSyncFailedJob;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receiver único de webhooks do ECF Drive (Phase 26).
 *
 * Fluxo:
 * 1. Valida assinatura HMAC SHA256 timing-safe (D-02 do CONTEXT)
 * 2. Parse JSON do body
 * 3. Idempotência via WebhookDelivery::firstOrCreate + UNIQUE event_id (D-C)
 * 4. Se novo evento: despacha Job async (D-D) — worker lida com lógica pesada
 * 5. Retorna 200 OK em <100ms (dispatch ~3ms; sem trabalho síncrono pesado)
 *
 * URL: POST /api/webhooks/ecf (configurada no painel ECF Drive pelo usuário)
 * Sem CSRF: rota fora do grupo `web`, isento em bootstrap/app.php e withoutMiddleware.
 * Secret: lido de config('services.ecf.webhook_secret') — NUNCA logado (D-10).
 */
class EcfWebhookController extends Controller
{
    /**
     * Mapeamento evento → Job class.
     * Adicionar novos eventos aqui conforme o ECF Drive publicar.
     */
    private array $handlers = [
        'sync.completed'   => HandleSyncCompletedJob::class,
        'sync.failed'      => HandleSyncFailedJob::class,
        'etl.completed'    => HandleEtlCompletedJob::class,
        'grant.expirando'  => HandleGrantExpirandoJob::class,
        'signal.detected'  => HandleSignalDetectedJob::class,
        'relatorio.gerado' => HandleRelatorioGeradoJob::class,
    ];

    /**
     * Recebe e processa um webhook do ECF Drive.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $inicioMs = microtime(true) * 1000;

        // ── 1. Pega raw body ANTES de qualquer parse ──────────────────────────
        // NUNCA usar $request->all() — pode divergir do bytes usados no HMAC
        $rawBody = $request->getContent();

        // ── 2. Valida header X-ECF-Signature ─────────────────────────────────
        $signatureHdr = $request->header('X-ECF-Signature', '');

        if (! str_starts_with($signatureHdr, 'sha256=')) {
            // Formato inválido ou header ausente — 401 sem log do secret
            Log::channel('ecf-webhooks')->warning('[ECF Webhook] Assinatura ausente ou formato inválido', [
                'ip'             => $request->ip(),
                'header_present' => $signatureHdr !== '',
            ]);
            return response()->json(['erro' => 'Assinatura inválida'], 401);
        }

        // Strip do prefixo 'sha256='
        $received = substr($signatureHdr, 7);

        // ── 3. Cálculo HMAC timing-safe ───────────────────────────────────────
        // hash_equals é obrigatório (D-02) — evita timing attack com === ou ==
        $expected = hash_hmac('sha256', $rawBody, config('services.ecf.webhook_secret'));

        if (! hash_equals($expected, $received)) {
            Log::channel('ecf-webhooks')->warning('[ECF Webhook] Assinatura HMAC inválida', [
                'ip'         => $request->ip(),
                'event_size' => strlen($rawBody),
            ]);
            return response()->json(['erro' => 'Assinatura inválida'], 401);
        }

        // ── 4. Parse JSON ─────────────────────────────────────────────────────
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            Log::channel('ecf-webhooks')->warning('[ECF Webhook] JSON inválido no body', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['erro' => 'Body JSON inválido'], 400);
        }

        $eventType = $body['event']    ?? '';
        $eventId   = $body['event_id'] ?? '';
        $data      = $body['data']     ?? [];

        if (empty($eventId)) {
            Log::channel('ecf-webhooks')->warning('[ECF Webhook] Evento sem event_id', [
                'ip'         => $request->ip(),
                'event_type' => $eventType,
            ]);
            return response()->json(['erro' => 'event_id obrigatório'], 400);
        }

        // ── 5. Idempotência via firstOrCreate + UNIQUE constraint ─────────────
        // wasRecentlyCreated = true → 1ª entrega; false → duplicata (retenta do parceiro)
        $delivery = WebhookDelivery::firstOrCreate(
            ['event_id' => $eventId],
            [
                'event_type'      => $eventType,
                'payload'         => $body,
                'signature_valid' => true,
                'received_at'     => now(),
                'status'          => 'received',
                'ip_address'      => $request->ip(),
            ]
        );

        // ── 6. Dispatch async (somente na 1ª entrega) ─────────────────────────
        $despachado = false;

        if ($delivery->wasRecentlyCreated) {
            $jobClass = $this->handlers[$eventType] ?? null;

            if ($jobClass !== null) {
                dispatch(new $jobClass($delivery->id));
                $despachado = true;
            } else {
                // Evento desconhecido — ECF Drive pode publicar eventos novos antes de
                // termos handler. Aceitar com 200 + log de aviso (D-05 do CONTEXT).
                Log::channel('ecf-webhooks')->warning('[ECF Webhook] Evento desconhecido', [
                    'event_type' => $eventType,
                    'event_id'   => $eventId,
                    'ip'         => $request->ip(),
                ]);
            }
        }

        // ── 7. Log estruturado de auditoria ───────────────────────────────────
        // Logamos: tipo, id, válido, tempo. NUNCA secret nem rawBody (D-10).
        $processamentoMs = round(microtime(true) * 1000 - $inicioMs, 2);

        Log::channel('ecf-webhooks')->info('[ECF Webhook] Recebido', [
            'event_type'       => $eventType,
            'event_id'         => $eventId,
            'signature_valid'  => true,
            'idempotente'      => ! $delivery->wasRecentlyCreated,
            'despachado'       => $despachado,
            'processing_ms'    => $processamentoMs,
            'ip'               => $request->ip(),
        ]);

        // Responde 200 em <100ms (D-H do PLAN — dispatch ~3ms, sem trabalho pesado)
        return response()->json(['ok' => true]);
    }
}
