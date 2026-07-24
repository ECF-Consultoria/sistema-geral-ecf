<?php

namespace App\Services\Hubspot;

use App\Models\Servico;

/**
 * HubspotValueResolver — decide o valor OPERACIONAL de um contrato a partir
 * dos dados brutos de um deal/line item do HubSpot.
 *
 * Classe PURA: sem I/O, sem banco, sem chamadas HTTP. Recebe o Servico já
 * resolvido (o mapping line_item → Servico é responsabilidade do plano 112-02;
 * a orquestração multi-item e a persistência ficam no plano 112-03).
 *
 * Regra central (bug real R$ 36.000 anual × R$ 3.000 mensal — prompt Fase 6):
 * quando o serviço é mensal, o valor OPERACIONAL gravado em
 * `contratos_servico.valor_contratado` deve ser sempre o valor MENSAL,
 * mesmo quando o HubSpot só expõe o valor anual/total do contrato.
 *
 * Shape do retorno de resolve() (8 chaves):
 * - valor_operacional (float): valor final para persistir em `valor_contratado`.
 * - valor_original (float|null): valor bruto observado no HubSpot (antes de normalizar).
 * - valor_original_tipo (string): proveniência do valor original — ex.:
 *     'unit_price', 'net_price', 'mrr', 'deal_amount', 'deal_amount_annual', 'valor_padrao'.
 * - normalizado_mensal (float|null): valor mensal equivalente (null quando o serviço é único).
 * - billing_frequency (string|null): ex. 'monthly', 'annually' (do line item).
 * - billing_period (string|null): ISO-8601 (ex. 'P1Y') do line item.
 * - confidence (string): 'high' | 'medium' | 'low'.
 * - warning (string|null): motivo de inferência/baixa confiança
 *     (contém a marca 'valor_revisar' quando a inferência não é segura).
 */
class HubspotValueResolver
{
    /** Margem de tolerância padrão (5%) para aproximar amount/12 de valor_padrao/price. */
    private const TOLERANCIA_PADRAO = 0.05;

    /**
     * Resolve o valor operacional de um contrato.
     *
     * @param  Servico  $servico    serviço já resolvido (tipo_cobranca/valor_padrao)
     * @param  array    $lineItem   shape normalizado por HubspotApiClient::fetchDealLineItems (ou [] no fluxo legado)
     * @param  array    $dealProps  deal['properties'] do HubSpot (usa 'amount' no fluxo legado)
     * @return array{
     *     valor_operacional: float,
     *     valor_original: float|null,
     *     valor_original_tipo: string,
     *     normalizado_mensal: float|null,
     *     billing_frequency: string|null,
     *     billing_period: string|null,
     *     confidence: string,
     *     warning: string|null,
     * }
     */
    public function resolve(Servico $servico, array $lineItem, array $dealProps): array
    {
        if (!empty($lineItem)) {
            return $this->resolverComLineItem($servico, $lineItem);
        }

        return $this->resolverSemLineItem($servico, $dealProps);
    }

    /**
     * Resolve o valor quando o deal trouxe um line item (fluxo novo Fase 111/112).
     *
     * Regras (prompt Fase 6, bloco <decisions>):
     * - Serviço ÚNICO: usa `amount` do line item se numérico, senão `price*quantity`.
     *   NUNCA divide por 12.
     * - Serviço MENSAL:
     *   - `recurringbillingfrequency=monthly` + price numérico → price*quantity, high
     *     (= comportamento atual, INVARIANTE de não-regressão).
     *   - `hs_mrr` numérico presente → fonte forte, sempre high (independe do período).
     *   - `recurringbillingfrequency=annually` + price numérico (sem hs_mrr) →
     *     price*quantity/12; high se `hs_recurring_billing_period` indicar 12 meses
     *     (começa com 'P1Y'), senão medium.
     *   - só `amount` do line item (sem price/hs_mrr) → compara amount/12 com
     *     valor_padrao/price via tolerância; aceita com warning de inferência (medium)
     *     ou cai conservador (low + valor_revisar) quando incompatível.
     */
    private function resolverComLineItem(Servico $servico, array $lineItem): array
    {
        $quantity = $this->numericoOu($lineItem['quantity'] ?? null, 1.0);
        if ($quantity <= 0) {
            $quantity = 1.0;
        }

        $price      = $this->numericoOuNull($lineItem['price'] ?? null);
        $amount     = $this->numericoOuNull($lineItem['amount'] ?? null);
        $hsMrr      = $this->numericoOuNull($lineItem['hs_mrr'] ?? null);
        $hsArr      = $this->numericoOuNull($lineItem['hs_arr'] ?? null);
        $frequency  = $lineItem['recurringbillingfrequency'] ?? null;
        $period     = $lineItem['hs_recurring_billing_period'] ?? null;
        $valorPadrao = (float) ($servico->valor_padrao ?? 0);

        $base = [
            'billing_frequency' => $frequency,
            'billing_period'    => $period,
        ];

        // ─── Serviço ÚNICO: nunca divide por 12 ─────────────────────────────
        if ($servico->tipo_cobranca !== Servico::TIPO_MENSAL) {
            if ($amount !== null) {
                $valor = $amount;
                $tipo  = 'deal_amount';
            } elseif ($price !== null) {
                $valor = $price * $quantity;
                $tipo  = 'unit_price';
            } else {
                $valor = $valorPadrao;
                $tipo  = 'valor_padrao';
            }

            return array_merge($base, [
                'valor_operacional'   => $valor,
                'valor_original'      => $valor,
                'valor_original_tipo' => $tipo,
                'normalizado_mensal'  => null,
                'confidence'          => $amount !== null || $price !== null ? 'high' : 'low',
                'warning'             => $amount !== null || $price !== null
                    ? null
                    : 'valor_revisar: line item sem amount/price, usando valor_padrao do serviço',
            ]);
        }

        // ─── Serviço MENSAL ──────────────────────────────────────────────────

        // hs_mrr é fonte forte independente da frequência declarada.
        if ($hsMrr !== null) {
            $valorOriginal = $hsArr ?? ($price !== null ? $price * $quantity : $hsMrr);

            return array_merge($base, [
                'valor_operacional'   => $hsMrr,
                'valor_original'      => $valorOriginal,
                'valor_original_tipo' => 'mrr',
                'normalizado_mensal'  => $hsMrr,
                'confidence'          => 'high',
                'warning'             => null,
            ]);
        }

        if ($frequency === 'monthly' && $price !== null) {
            $valor = $price * $quantity;

            return array_merge($base, [
                'valor_operacional'   => $valor,
                'valor_original'      => $valor,
                'valor_original_tipo' => 'unit_price',
                'normalizado_mensal'  => $valor,
                'confidence'          => 'high',
                'warning'             => null,
            ]);
        }

        if ($frequency === 'annually' && $price !== null) {
            $bruto = $price * $quantity;
            $valor = $bruto / 12;
            $confidence = is_string($period) && str_starts_with($period, 'P1Y') ? 'high' : 'medium';

            return array_merge($base, [
                'valor_operacional'   => $valor,
                'valor_original'      => $bruto,
                'valor_original_tipo' => 'net_price',
                'normalizado_mensal'  => $valor,
                'confidence'          => $confidence,
                'warning'             => $confidence === 'medium'
                    ? 'valor operacional inferido de price*quantity/12 — período de cobrança não confirma 12 meses (P1Y)'
                    : null,
            ]);
        }

        // Só amount no line item (sem price/hs_mrr) — infere anual via tolerância.
        if ($amount !== null) {
            $mensalInferido = $amount / 12;
            $bateComPadrao = $valorPadrao > 0 && $this->aproximadamente($mensalInferido, $valorPadrao);
            $bateComPrice  = $price !== null && $this->aproximadamente($mensalInferido, $price);

            if ($bateComPadrao || $bateComPrice) {
                return array_merge($base, [
                    'valor_operacional'   => $mensalInferido,
                    'valor_original'      => $amount,
                    'valor_original_tipo' => 'deal_amount_annual',
                    'normalizado_mensal'  => $mensalInferido,
                    'confidence'          => 'medium',
                    'warning'             => 'valor operacional inferido de amount/12 do line item — confirmar',
                ]);
            }

            return array_merge($base, [
                'valor_operacional'   => $mensalInferido,
                'valor_original'      => $amount,
                'valor_original_tipo' => 'deal_amount_annual',
                'normalizado_mensal'  => $mensalInferido,
                'confidence'          => 'low',
                'warning'             => 'valor_revisar: amount/12 do line item não bate com valor_padrao/price dentro da tolerância de 5%',
            ]);
        }

        // Nenhum dado utilizável — ramo conservador degenerado.
        return array_merge($base, [
            'valor_operacional'   => $valorPadrao,
            'valor_original'      => null,
            'valor_original_tipo' => 'valor_padrao',
            'normalizado_mensal'  => $valorPadrao,
            'confidence'          => 'low',
            'warning'             => 'valor_revisar: line item sem price/amount/hs_mrr, usando valor_padrao do serviço',
        ]);
    }

    /**
     * Resolve o valor no fluxo legado (sem line item, só `deal.amount`).
     *
     * Regras (prompt Fase 6, item 3 do bloco <decisions>):
     * - serviço mensal e `deal.amount` já parece mensal (≈ valor_padrao) → usa direto, high.
     * - serviço mensal e `deal.amount/12` ≈ valor_padrao (tolerância) → usa amount/12, medium + warning.
     * - indecidível → ramo conservador (amount/12), low + 'valor_revisar'.
     */
    private function resolverSemLineItem(Servico $servico, array $dealProps): array
    {
        $amount      = $this->numericoOuNull($dealProps['amount'] ?? null);
        $valorPadrao = (float) ($servico->valor_padrao ?? 0);

        $base = [
            'billing_frequency' => null,
            'billing_period'    => null,
        ];

        if ($servico->tipo_cobranca !== Servico::TIPO_MENSAL) {
            $valor = $amount ?? $valorPadrao;

            return array_merge($base, [
                'valor_operacional'   => $valor,
                'valor_original'      => $amount,
                'valor_original_tipo' => $amount !== null ? 'deal_amount' : 'valor_padrao',
                'normalizado_mensal'  => null,
                'confidence'          => $amount !== null ? 'high' : 'low',
                'warning'             => $amount !== null
                    ? null
                    : 'valor_revisar: deal sem amount, usando valor_padrao do serviço',
            ]);
        }

        if ($amount === null) {
            return array_merge($base, [
                'valor_operacional'   => $valorPadrao,
                'valor_original'      => null,
                'valor_original_tipo' => 'valor_padrao',
                'normalizado_mensal'  => $valorPadrao,
                'confidence'          => 'low',
                'warning'             => 'valor_revisar: deal sem amount, usando valor_padrao do serviço',
            ]);
        }

        // deal.amount já parece ser o valor mensal (bate direto com valor_padrao).
        if ($valorPadrao > 0 && $this->aproximadamente($amount, $valorPadrao)) {
            return array_merge($base, [
                'valor_operacional'   => $amount,
                'valor_original'      => $amount,
                'valor_original_tipo' => 'deal_amount',
                'normalizado_mensal'  => $amount,
                'confidence'          => 'high',
                'warning'             => null,
            ]);
        }

        $mensalInferido = $amount / 12;

        // deal.amount parece anual: amount/12 bate com valor_padrao dentro da tolerância.
        if ($valorPadrao > 0 && $this->aproximadamente($mensalInferido, $valorPadrao)) {
            return array_merge($base, [
                'valor_operacional'   => $mensalInferido,
                'valor_original'      => $amount,
                'valor_original_tipo' => 'deal_amount_annual',
                'normalizado_mensal'  => $mensalInferido,
                'confidence'          => 'medium',
                'warning'             => 'valor operacional inferido de deal.amount/12 (fluxo legado, sem line item) — confirmar',
            ]);
        }

        // Indecidível: nem amount nem amount/12 batem com valor_padrao — conservador
        // MANTÉM o valor bruto observado (nunca adivinha divisão por 12 sem
        // evidência), preservando a INVARIANTE de não-regressão do fluxo legado
        // Phase 34/35/37 (valor sempre foi `deal.amount` cru nesses casos —
        // T-112-03-02, bug encontrado durante o gate de regressão do plano 112-03).
        return array_merge($base, [
            'valor_operacional'   => $amount,
            'valor_original'      => $amount,
            'valor_original_tipo' => 'deal_amount',
            'normalizado_mensal'  => null,
            'confidence'          => 'low',
            'warning'             => 'valor_revisar: deal.amount não bate com valor_padrao do serviço dentro da tolerância de 5%',
        ]);
    }

    /**
     * Compara dois valores com margem de tolerância percentual (default 5%).
     * Usa epsilon no denominador para nunca dividir por zero (T-112-01-03).
     */
    private function aproximadamente(float $a, float $b, float $margem = self::TOLERANCIA_PADRAO): bool
    {
        $epsilon = 0.0001;
        $denominador = max(abs($b), $epsilon);

        return (abs($a - $b) / $denominador) <= $margem;
    }

    /**
     * Cast defensivo: devolve (float) quando o valor é numérico, senão $default.
     * Protege contra dados HubSpot não confiáveis (string vazia, null, lixo) — T-112-01-01.
     */
    private function numericoOu(mixed $valor, float $default): float
    {
        return is_numeric($valor) ? (float) $valor : $default;
    }

    /**
     * Cast defensivo: devolve (float) quando o valor é numérico, senão null.
     */
    private function numericoOuNull(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }
}
