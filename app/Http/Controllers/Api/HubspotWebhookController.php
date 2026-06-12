<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Services\HubspotApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 34 Plan 34-04 — Receiver POST /api/webhooks/hubspot.
 *
 * Fluxo (D-03 + D-04):
 *  1. Le raw body com $request->getContent() — bytes precisam bater com HMAC
 *  2. Valida X-HubSpot-Request-Timestamp (rejeita > 5min de diferenca — replay)
 *  3. Valida X-HubSpot-Signature-v3: base64(hmac_sha256(secret, METHOD+URI+body+ts))
 *     com hash_equals timing-safe
 *  4. Qualquer falha de validacao: grava HubspotEvento(signature_valid=false,
 *     status=erro, payload contendo raw truncado em 65KB) e retorna 401 sem
 *     vazar detalhes — para nao expor secret/cipher info ao caller
 *  5. Sucesso: decodifica payload (HubSpot manda array de eventos) e processa
 *     1 evento por vez, gerando 1 HubspotEvento por evento
 *
 * Processamento (por evento):
 *  - Filtra: subscription_type='deal.propertyChange' && property_name='dealstage'
 *    && property_value=config('services.hubspot.stage_fechado_ganho_id')
 *    Demais sao marcados status=ignorado.
 *  - Idempotencia: pula se HubspotEvento ja existe com mesmo object_id +
 *    company_id_criada NOT NULL (D-04).
 *  - fetchDeal + fetchAssociatedCompanyId + fetchCompany via HubspotApiClient.
 *  - Cria Company em DB::transaction com empresa_nova=true, status='pendente'.
 *    Se nome do servico bate com Servico::where('nome', X)->first() ativo →
 *    cria ContratoServico. Caso contrario grava o nome em notes.
 *  - Erro inesperado: status='erro' + erro_msg, retorna 200 (HubSpot nao retenta).
 *
 * Seguranca:
 *  - secret NUNCA logado (so o ip e tamanho do payload)
 *  - raw body truncado em 65KB ao gravar evento invalido (evita estourar disco)
 *  - Throttle 60/min na rota (vide routes/web.php)
 *  - CSRF isento via bootstrap/app.php (api/webhooks/*) + withoutMiddleware
 */
class HubspotWebhookController extends Controller
{
    /** Tolerancia de replay: 5 minutos em milissegundos. */
    private const REPLAY_WINDOW_MS = 5 * 60 * 1000;

    /** Truncamento defensivo do raw body ao gravar evento invalido. */
    private const RAW_BODY_MAX_BYTES = 65_000;

    public function receive(Request $request, HubspotApiClient $api): JsonResponse
    {
        $rawBody = $request->getContent();
        $secret  = (string) config('services.hubspot.client_secret');

        $sigHdr = (string) $request->header('X-HubSpot-Signature-v3', '');
        $tsHdr  = (string) $request->header('X-HubSpot-Request-Timestamp', '');

        // ── 1. Valida timestamp (replay window 5min) ─────────────────────────
        $ts = (int) $tsHdr;
        if ($ts < 1 || abs((int) (microtime(true) * 1000) - $ts) > self::REPLAY_WINDOW_MS) {
            $this->gravarInvalido($rawBody, 'timestamp invalido ou ausente', $request);
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // ── 2. Calcula HMAC esperado: base64(hmac_sha256(secret, METHOD+URI+body+ts)) ─
        $methodUriBody = $request->method() . $request->fullUrl() . $rawBody . $tsHdr;
        $expected      = base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));

        if ($sigHdr === '' || !hash_equals($expected, $sigHdr)) {
            $this->gravarInvalido($rawBody, 'signature invalida ou ausente', $request);
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // ── 3. Decodifica payload (HubSpot manda array de eventos) ───────────
        $eventos = json_decode($rawBody, true);
        if (!is_array($eventos)) {
            $this->gravarInvalido($rawBody, 'json invalido', $request);
            return response()->json(['error' => 'bad payload'], 400);
        }

        // HubSpot legitimo manda sempre array; tolera objeto unico por seguranca.
        if (isset($eventos['objectId']) || isset($eventos['subscriptionType'])) {
            $eventos = [$eventos];
        }

        // ── 4. Cria 1 HubspotEvento por evento e processa ─────────────────────
        foreach ($eventos as $evt) {
            if (!is_array($evt)) {
                continue;
            }
            $evento = HubspotEvento::create([
                'signature_valid'   => true,
                'portal_id'         => isset($evt['portalId']) ? (string) $evt['portalId'] : null,
                'object_type'       => $evt['objectType'] ?? null,
                'object_id'         => isset($evt['objectId']) ? (string) $evt['objectId'] : null,
                'subscription_type' => $evt['subscriptionType'] ?? null,
                'property_name'     => $evt['propertyName'] ?? null,
                'property_value'    => isset($evt['propertyValue']) ? (string) $evt['propertyValue'] : null,
                'payload'           => $evt,
                'status'            => 'recebido',
            ]);

            $this->processar($evento, $api);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Filtra + idempotencia + fetch HubSpot + cria Company.
     * Em qualquer erro grava status=erro e retorna (controller responde 200).
     */
    private function processar(HubspotEvento $evento, HubspotApiClient $api): void
    {
        $stageGatilho = (string) config('services.hubspot.stage_fechado_ganho_id');

        // ── Filtro: so processa deal.propertyChange + dealstage=closedwon ───
        if (
            $evento->subscription_type !== 'deal.propertyChange'
            || $evento->property_name !== 'dealstage'
            || $evento->property_value !== $stageGatilho
        ) {
            $evento->update(['status' => 'ignorado', 'processado_em' => now()]);
            return;
        }

        // ── Idempotencia: deal ja processado em evento anterior? ────────────
        $jaProcessado = HubspotEvento::where('object_id', $evento->object_id)
            ->where('id', '!=', $evento->id)
            ->whereNotNull('company_id_criada')
            ->exists();

        if ($jaProcessado) {
            $evento->update([
                'status'         => 'ignorado',
                'erro_msg'       => 'Deal ja processado em evento anterior (idempotencia D-04)',
                'processado_em'  => now(),
            ]);
            return;
        }

        try {
            $propsDeal    = config('services.hubspot.props.deal');
            $propsCompany = config('services.hubspot.props.company');

            $deal       = $api->fetchDeal((string) $evento->object_id, array_merge(
                ['dealname', 'amount', 'dealstage'],
                array_values($propsDeal),
            ));
            $companyId  = $api->fetchAssociatedCompanyId((string) $evento->object_id);
            $hubCompany = $companyId ? $api->fetchCompany($companyId, array_values($propsCompany)) : null;

            $company = $this->criarEmpresa($deal, $hubCompany);

            $evento->update([
                'status'            => 'processado',
                'company_id_criada' => $company->id,
                'processado_em'     => now(),
            ]);

            Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Empresa criada', [
                'evento_id'  => $evento->id,
                'company_id' => $company->id,
                'object_id'  => $evento->object_id,
            ]);
        } catch (\Throwable $e) {
            $evento->update([
                'status'        => 'erro',
                'erro_msg'      => mb_substr($e->getMessage(), 0, 1000),
                'processado_em' => now(),
            ]);

            Log::channel('ecf-webhooks')->error('[HubSpot Webhook] Falha no processamento', [
                'evento_id' => $evento->id,
                'object_id' => $evento->object_id,
                'erro'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cria a Company + (opcionalmente) ContratoServico em transacao.
     *
     * @param  array       $deal        payload HubSpot do deal (chave 'properties')
     * @param  array|null  $hubCompany  payload HubSpot do company associado
     */
    private function criarEmpresa(array $deal, ?array $hubCompany): Company
    {
        $propsDeal    = config('services.hubspot.props.deal');
        $propsCompany = config('services.hubspot.props.company');
        $dprops       = $deal['properties'] ?? [];
        $cprops       = $hubCompany['properties'] ?? [];

        return DB::transaction(function () use ($deal, $dprops, $cprops, $propsDeal, $propsCompany) {
            $venderMlRaw = $dprops[$propsDeal['vende_ml']] ?? null;
            $vendeMl     = $venderMlRaw === null || $venderMlRaw === ''
                ? null
                : filter_var($venderMlRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            $faturamentoRaw = $dprops[$propsDeal['faturamento_mensal']] ?? null;
            $faturamento    = is_numeric($faturamentoRaw) ? (float) $faturamentoRaw : null;

            $company = Company::create([
                'name'               => $cprops[$propsCompany['name']]
                    ?? $deal['properties']['dealname']
                    ?? 'Empresa HubSpot',
                'cnpj'               => $cprops[$propsCompany['cnpj']] ?? null,
                'email_cliente'      => $cprops[$propsCompany['email']] ?? null,
                'telefone'           => $cprops[$propsCompany['phone']] ?? null,
                'nicho'              => $dprops[$propsDeal['nicho']] ?? null,
                'dor'                => $dprops[$propsDeal['dor']] ?? null,
                'vende_ml'           => $vendeMl,
                'faturamento_mensal' => $faturamento,
                'empresa_nova'       => true,
                'status'             => 'pendente',
                'active'             => true,
            ]);

            // ── Tenta vincular ContratoServico se nome do servico bate ───────
            $servicoNome = $dprops[$propsDeal['servico']] ?? null;
            $servico     = $servicoNome
                ? Servico::where('nome', $servicoNome)->where('ativo', true)->first()
                : null;

            // amount do HubSpot eh o valor do contrato (campo nativo, sem mapeamento)
            $valor = isset($dprops['amount']) && is_numeric($dprops['amount'])
                ? (float) $dprops['amount']
                : ((float) ($servico?->valor_padrao ?? 0));

            if ($servico) {
                ContratoServico::create([
                    'company_id'       => $company->id,
                    'servico_id'       => $servico->id,
                    'valor_contratado' => $valor,
                    'data_contratacao' => now()->toDateString(),
                    'ativo'            => true,
                ]);
            } elseif ($servicoNome) {
                // Servico nao encontrado no catalogo — grava em notes para admin completar.
                $notesAtuais = $company->notes ?? '';
                $linhaNova   = "Serviço (HubSpot): {$servicoNome}";
                $notes       = trim($notesAtuais === '' ? $linhaNova : $notesAtuais . "\n" . $linhaNova);
                $company->update(['notes' => $notes]);
            }

            return $company;
        });
    }

    /**
     * Grava HubspotEvento(signature_valid=false) com motivo + raw truncado.
     * Usado por falhas pre-validacao (timestamp/signature/json).
     */
    private function gravarInvalido(string $rawBody, string $motivo, Request $request): void
    {
        HubspotEvento::create([
            'signature_valid' => false,
            'payload'         => [
                'raw'    => mb_strcut($rawBody, 0, self::RAW_BODY_MAX_BYTES),
                'motivo' => $motivo,
                'ip'     => $request->ip(),
            ],
            'status'   => 'erro',
            'erro_msg' => $motivo,
        ]);

        Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Requisicao invalida', [
            'motivo'    => $motivo,
            'ip'        => $request->ip(),
            'body_size' => strlen($rawBody),
        ]);
    }
}
