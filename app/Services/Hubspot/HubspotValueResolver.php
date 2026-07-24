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
        // TODO (Tarefa 2 — GREEN): stub fixo apenas para o esqueleto RED carregar
        // e a suite falhar por asserção (não por classe/método ausente).
        return [
            'valor_operacional'   => 0.0,
            'valor_original'      => null,
            'valor_original_tipo' => 'valor_padrao',
            'normalizado_mensal'  => null,
            'billing_frequency'   => null,
            'billing_period'      => null,
            'confidence'          => 'low',
            'warning'             => null,
        ];
    }
}
