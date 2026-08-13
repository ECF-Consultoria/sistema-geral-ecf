<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ClicksignSondaHmacController — Fase 129, gate A1 (D-07/D-08).
 *
 * ⚠️ TEMPORÁRIA — existe só para medir a fórmula do HMAC contra um webhook
 * real vindo do túnel local (D-07). É REMOVIDA pelo plano 129-02, assim que
 * a medição fechar o gate A1. Depois disso, a verificação vira o comando
 * `clicksign:verificar-assinatura` (D-09), que lê os eventos já gravados
 * por esta sonda sem precisar de superfície pública nova.
 *
 * Deliberadamente burra: não decide nada, não valida nada, não libera nada.
 * Só mede as 4 candidatas de HMAC e grava o corpo bruto — sempre responde
 * 200 para não fazer a Clicksign parar de entregar durante a janela de
 * medição (D-08; `melhores-praticas-webhooks`, 129-RESEARCH.md §5).
 */
class ClicksignSondaHmacController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        // Bytes exatos — precisam bater com o que a Clicksign assinou.
        // Sempre a primeira linha, antes de qualquer parsing.
        $rawBody = $request->getContent();

        $headerRecebido = (string) $request->header('Content-Hmac', '');
        $secret          = (string) config('services.clicksign.webhook_secret');

        $veredito = ClicksignHmacVarredura::identificarTodas($rawBody, $secret, $headerRecebido);

        // Log seguro: nunca o secret, nunca o rawBody inteiro, nunca o
        // header completo (o header É o hash — logar inteiro não vaza o
        // segredo, mas o prefixo já basta para diagnóstico).
        Log::channel('ecf-webhooks')->info('[Clicksign Sonda HMAC] Varredura de fórmulas', [
            'recebido_prefixo' => mb_substr($headerRecebido, 0, 15),
            'body_size'        => strlen($rawBody),
            'ip'               => $request->ip(),
            ...$veredito,
        ]);

        $decodificado = json_decode($rawBody, true);

        if (is_array($decodificado)) {
            $payload = $decodificado;
        } else {
            $payload = [
                'raw'    => mb_strcut($rawBody, 0, ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES),
                'motivo' => 'json invalido',
            ];
        }

        $payload['_headers'] = ['content-hmac' => $headerRecebido];
        $payload['_sonda']   = $veredito;

        $name = $decodificado['event']['name']
            ?? $decodificado['data']['attributes']['name']
            ?? null;

        $envelopeId = $decodificado['document']['key']
            ?? $decodificado['data']['id']
            ?? null;

        try {
            ContratoAssinaturaEvento::create([
                'origem'                => ContratoAssinaturaEvento::ORIGEM_SONDA,
                'signature_valid'       => false,
                'name'                  => is_string($name) ? $name : null,
                'clicksign_envelope_id' => is_string($envelopeId) ? $envelopeId : null,
                'payload'               => $payload,
                'raw_body'              => mb_strcut($rawBody, 0, ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES),
                'raw_truncado'          => strlen($rawBody) > ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES,
                'payload_hash'          => hash('sha256', $rawBody),
                'ip_address'            => $request->ip(),
                'status'                => ContratoAssinaturaEvento::STATUS_RECEBIDO,
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            // Reentrega do mesmo corpo — já medido, idempotência OK.
        }

        // Sempre 200: a sonda existe para receber o máximo de webhooks
        // possível durante a janela de medição; qualquer não-2xx faria a
        // Clicksign parar de entregar.
        return response()->json(['ok' => true], 200);
    }
}
