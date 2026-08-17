<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarEventoClicksignJob;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ClicksignWebhookController — Fase 129, plano 129-03 (CLICK-03, CLICK-04,
 * CLICK-06, DADOS-03). Receiver de PRODUÇÃO do webhook Clicksign.
 *
 * Fluxo em 4 passos, na ordem exata do método `receive()`:
 *  1. Ler o raw body ANTES de qualquer parsing e validar `Content-Hmac`
 *     (`ClicksignHmacVarredura::confere()`, fórmula MEDIDA no gate A1).
 *  2. Decodificar o JSON, defensivamente.
 *  3. Gravar `ContratoAssinaturaEvento` — SEMPRE, mesmo em falha de
 *     validação (DADOS-03) — com dedup por `payload_hash` UNIQUE
 *     (CLICK-04).
 *  4. Enfileirar `ProcessarEventoClicksignJob` e responder.
 *
 * A janela síncrona é SÓ ISTO: validar assinatura, gravar o evento bruto,
 * enfileirar (CLICK-06). Nenhuma chamada HTTP à Clicksign acontece aqui —
 * tudo que envolve rede (reconsulta do envelope, eventos do documento)
 * roda depois, na fila. Nada que acontece DEPOIS do 200 (na fila) pode
 * influenciar o status code — a resposta já foi enviada.
 *
 * Matriz de status code (D-10 do 129-CONTEXT.md):
 *
 * | Situação                                  | HTTP | Por quê |
 * |---|---|---|
 * | `Content-Hmac` ausente ou não confere     | 401  | assinatura inválida é recusa deliberada — reenviar não conserta secret errado. Grava mesmo assim (DADOS-03): é evidência de secret trocado ou tentativa de forjar liberação. |
 * | Corpo não é JSON válido                   | 200  | erro é do payload em si — reenviar não conserta e viraria loop até a Clicksign desistir (a política de retry dela, gate #11, é permanentemente não medida). |
 * | Envelope não casa com nenhum contrato     | 200  | não é erro — grava com `contrato_assinatura_id` nulo e enfileira assim mesmo (o job tenta resolver de novo, pode ser corrida com o commit do envelope). |
 * | `payload_hash` já existe (evento duplicado) | 200  | idempotência da CLICK-04 funcionando, não um erro. |
 * | Falha ao gravar no banco (não-duplicata)  | 503  | transitório — banco indisponível. Reenvio é seguro (dedup por hash). |
 * | Falha ao enfileirar                       | 503  | transitório — melhor a Clicksign reenviar do que o evento ficar gravado e nunca processado. |
 * | Sucesso                                   | 200  | evento gravado e job enfileirado. |
 *
 * ⚠️ Divergência DELIBERADA em relação a `HubspotWebhookController`: aqui
 * assinatura inválida responde 401 (não 200). O HubSpot sempre responde
 * 200 até para assinatura inválida; a D-10 desta fase é explícita sobre a
 * diferença — reenviar um webhook Clicksign com assinatura errada não
 * conserta nada, então recusar é o sinal correto.
 *
 * Disciplina copiada de `HubspotWebhookController` (NÃO a fórmula do HMAC,
 * NÃO o timestamp de replay, NÃO o "200 sempre"): raw body antes de
 * qualquer parsing, `hash_equals` (via `ClicksignHmacVarredura::confere`),
 * truncamento de 65KB, log sem secret nem payload.
 */
class ClicksignWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        // 1. Raw body SEMPRE antes de qualquer parsing — os bytes exatos
        // precisam bater com o HMAC.
        $rawBody = $request->getContent();

        $secret         = (string) config('services.clicksign.webhook_secret');
        $headerRecebido = (string) $request->header('Content-Hmac', '');

        if ($headerRecebido === '' || !ClicksignHmacVarredura::confere($rawBody, $secret, $headerRecebido)) {
            $this->gravarEvento($rawBody, $request, [
                'signature_valid' => false,
                'status'          => ContratoAssinaturaEvento::STATUS_ERRO,
                'erro_msg'        => 'assinatura invalida ou ausente',
                'payload'         => [
                    'raw'    => mb_strcut($rawBody, 0, ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES),
                    'motivo' => 'assinatura invalida ou ausente',
                    'ip'     => $request->ip(),
                ],
            ]);

            $this->logarRecusa('assinatura invalida ou ausente', $request, $rawBody);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        // 2. Decodifica o corpo, defensivamente.
        $decodificado = json_decode($rawBody, true);

        if (!is_array($decodificado)) {
            $this->gravarEvento($rawBody, $request, [
                'signature_valid' => true,
                'status'          => ContratoAssinaturaEvento::STATUS_ERRO,
                'erro_msg'        => 'json invalido',
                'payload'         => [
                    'raw'    => mb_strcut($rawBody, 0, ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES),
                    'motivo' => 'json invalido',
                    'ip'     => $request->ip(),
                ],
            ]);

            // 200 e não 4xx (D-10): reenviar não conserta payload malformado.
            return response()->json(['ok' => true, 'ignorado' => true], 200);
        }

        // 3. Extrair name/envelopeId pelo caminho CONFIRMADO no gate A1
        // (129-GATE.md — forma real medida, {"event":{"name",...},
        // "document":{"key",...}}), com fallback defensivo para a forma
        // JSON:API que a doc oficial também mostra. Nunca promover mais
        // nada do `document` embutido a coluna (Pitfall C).
        $nome       = $decodificado['event']['name'] ?? $decodificado['data']['attributes']['name'] ?? null;
        $envelopeId = $decodificado['document']['key'] ?? $decodificado['data']['id'] ?? null;

        // Contrato não encontrado NÃO é erro — corrida possível com o
        // commit do envelope; o job tenta resolver de novo.
        $contrato = $envelopeId ? ContratoAssinatura::where('clicksign_envelope_id', $envelopeId)->first() : null;

        // 4. Grava o evento — dedup por payload_hash UNIQUE (CLICK-04).
        try {
            $evento = ContratoAssinaturaEvento::create([
                'contrato_assinatura_id' => $contrato?->id,
                'clicksign_envelope_id'  => $envelopeId,
                'name'                   => $nome,
                'signature_valid'        => true,
                'payload'                => $decodificado,
                'payload_hash'           => hash('sha256', $rawBody),
                'raw_body'               => mb_strcut($rawBody, 0, ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES),
                'raw_truncado'           => strlen($rawBody) > ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES,
                'origem'                 => ContratoAssinaturaEvento::ORIGEM_WEBHOOK,
                'ip_address'             => $request->ip(),
                'status'                 => ContratoAssinaturaEvento::STATUS_RECEBIDO,
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                // 23000 é a idempotência da CLICK-04 funcionando, não um erro.
                return response()->json(['ok' => true, 'duplicado' => true], 200);
            }

            Log::channel('ecf-webhooks')->error('[Clicksign Webhook] Falha ao gravar evento', [
                'ip'        => $request->ip(),
                'body_size' => strlen($rawBody),
            ]);

            return response()->json(['error' => 'temporary'], 503);
        }

        // 5. Enfileira o trabalho pesado — falha ao enfileirar é
        // transitório: melhor a Clicksign reenviar do que o evento ficar
        // gravado e nunca processado (D-10).
        try {
            ProcessarEventoClicksignJob::dispatch($evento);
        } catch (\Throwable $e) {
            Log::channel('ecf-webhooks')->error('[Clicksign Webhook] Falha ao enfileirar o processamento', [
                'evento_id' => $evento->id,
                'ip'        => $request->ip(),
            ]);

            return response()->json(['error' => 'temporary'], 503);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Grava o evento bruto em caso de recusa de validação (assinatura ou
     * JSON inválido) — DADOS-03 obriga gravar mesmo assim.
     *
     * @param  array<string, mixed>  $campos
     */
    private function gravarEvento(string $rawBody, Request $request, array $campos): void
    {
        try {
            ContratoAssinaturaEvento::create(array_merge([
                'payload_hash' => hash('sha256', $rawBody),
                'raw_body'     => mb_strcut($rawBody, 0, ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES),
                'raw_truncado' => strlen($rawBody) > ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES,
                'origem'       => ContratoAssinaturaEvento::ORIGEM_WEBHOOK,
                'ip_address'   => $request->ip(),
            ], $campos));
        } catch (QueryException $e) {
            // Mesmo corpo já gravado antes (retry de uma requisição
            // recusada) — idempotência da CLICK-04, não é erro.
            if ((string) $e->getCode() !== '23000') {
                Log::channel('ecf-webhooks')->error('[Clicksign Webhook] Falha ao gravar evento invalido', [
                    'ip'        => $request->ip(),
                    'body_size' => strlen($rawBody),
                ]);
            }
        }
    }

    private function logarRecusa(string $motivo, Request $request, string $rawBody): void
    {
        // Nunca o secret, nunca o header inteiro, nunca o payload.
        Log::channel('ecf-webhooks')->warning('[Clicksign Webhook] Requisicao recusada', [
            'motivo'    => $motivo,
            'ip'        => $request->ip(),
            'body_size' => strlen($rawBody),
        ]);
    }
}
