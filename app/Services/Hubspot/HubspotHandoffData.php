<?php

namespace App\Services\Hubspot;

/**
 * HubspotHandoffData — DTO do handoff HubSpot → Comercial.
 *
 * Produzido por `HubspotDealHandoffService::build()` a partir de um deal
 * (+ line items já buscados). Nesta fase (112-02) carrega SÓ o recorte de
 * valor/contratos — a montagem de Company/contato/dedup permanece no
 * controller até a Fase 113 mover essa responsabilidade para cá.
 *
 * Shape de cada item de `contracts_to_create` (array associativo pronto
 * para virar um `ContratoServico`):
 * - servico_id (int): FK do Servico resolvido (mapping ou fluxo legado).
 * - servico_nome (string): nome do Servico — usado no roteamento de
 *   implementação (Polos/Assessoria/Incubadora) pelo controller.
 * - valor_contratado (float): valor OPERACIONAL já normalizado pelo
 *   HubspotValueResolver (mensal quando o serviço é mensal).
 * - hubspot_line_item_id (string|null): id do line item de origem; null
 *   no fluxo legado (sem line item).
 * - hubspot_product_id (string|null): hs_product_id do line item.
 * - hubspot_billing_frequency (string|null): ex. 'monthly', 'annually'.
 * - hubspot_billing_period (string|null): ISO-8601 (ex. 'P1Y').
 * - hubspot_currency (string|null): hs_line_item_currency_code do line item.
 * - hubspot_valor_original (float|null): valor bruto observado no HubSpot
 *   antes de normalizar (proveniência/auditoria).
 * - hubspot_valor_original_tipo (string): ex. 'unit_price', 'mrr',
 *   'deal_amount', 'deal_amount_annual', 'valor_padrao'.
 * - hubspot_valor_normalizado_mensal (float|null): valor mensal equivalente
 *   (null quando o serviço é do tipo único).
 * - hubspot_valor_confidence (string): 'high' | 'medium' | 'low' — confiança
 *   individual DESTE contrato (distinta da confiança agregada do DTO).
 * - hubspot_valor_warning (string|null): motivo de inferência/baixa
 *   confiança quando aplicável.
 * - hubspot_snapshot (array): { 'line_item' => array normalizado do item
 *   (ou [] no fluxo legado), 'resolver_result' => retorno bruto de
 *   HubspotValueResolver::resolve() } — rastro completo para auditoria/replay.
 */
class HubspotHandoffData
{
    /**
     * @param  array  $deal_data              deal['properties'] do HubSpot (ou já normalizado pelo service)
     * @param  array  $line_items             line items normalizados recebidos pelo build()
     * @param  array  $contracts_to_create    ver shape documentado acima (um item por contrato a criar)
     * @param  array  $warnings               pendências acumuladas (line item sem mapping, serviço não encontrado, etc.)
     * @param  string $confidence             confiança AGREGADA do handoff — a MENOR entre os contratos ('low' < 'medium' < 'high')
     * @param  array|null $company_data       RESERVADO para a Fase 113 (enriquecimento de Company) — sempre null nesta fase
     * @param  array|null $contact_data       RESERVADO para a Fase 113 (escolha de contato principal) — sempre null nesta fase
     */
    public function __construct(
        public array $deal_data = [],
        public array $line_items = [],
        public array $contracts_to_create = [],
        public array $warnings = [],
        public string $confidence = 'high',
        public ?array $company_data = null,
        public ?array $contact_data = null,
    ) {
    }
}
