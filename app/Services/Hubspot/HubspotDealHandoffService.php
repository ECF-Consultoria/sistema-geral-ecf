<?php

namespace App\Services\Hubspot;

use App\Models\HubspotLineItemMapping;
use App\Models\Servico;

/**
 * HubspotDealHandoffService — extrai do `HubspotWebhookController` a
 * montagem de VALOR/CONTRATOS num serviço fino e testável (Fase 112,
 * plano 112-02 — HUB-VAL-04 + HUB-VAL-02).
 *
 * Recebe o deal e os line items JÁ BUSCADOS (a busca HubSpot + criação de
 * Company/contato permanece no controller até a Fase 113) e devolve um
 * `HubspotHandoffData` com `contracts_to_create` (cada um já com valor
 * operacional + campos de auditoria resolvidos por `HubspotValueResolver`),
 * `warnings` e `confidence` agregada.
 *
 * Lógica extraída fielmente de `HubspotWebhookController::processarLineItems()`
 * e `processarServicoLegado()` — SÓ a parte de valor/contrato. A criação da
 * empresa, o roteamento de implementação e a gravação de anotações internas
 * permanecem fora deste service (escopo do controller).
 */
class HubspotDealHandoffService
{
    public function __construct(
        private readonly HubspotValueResolver $resolver,
    ) {
    }

    /**
     * Monta o DTO de handoff a partir do deal + line items já buscados.
     *
     * @param  array  $deal       payload HubSpot do deal (chave 'properties')
     * @param  array  $lineItems  line items normalizados (shape de HubspotApiClient::fetchDealLineItems); [] cai no fluxo legado
     * @param  array  $propsDeal  config('services.hubspot.props.deal') — mapeia nome lógico -> nome da property HubSpot
     */
    public function build(array $deal, array $lineItems, array $propsDeal): HubspotHandoffData
    {
        $dprops = $deal['properties'] ?? [];

        $contracts   = [];
        $warnings    = [];
        $confidences = [];

        if (!empty($lineItems)) {
            foreach ($lineItems as $item) {
                $nome = (string) ($item['name'] ?? '');
                if ($nome === '') {
                    continue;
                }

                $mapping = HubspotLineItemMapping::paraNome($nome);
                // Sem mapping ativo OU mapping aponta para Servico inativo → warning,
                // NUNCA contrato (paridade com processarLineItems — T-112-02-01).
                if (!$mapping || !$mapping->servico || !$mapping->servico->ativo) {
                    $warnings[] = [
                        'name'                      => $nome,
                        'price'                     => $item['price'] ?? null,
                        'recurringbillingfrequency' => $item['recurringbillingfrequency'] ?? null,
                    ];
                    continue;
                }

                $servico = $mapping->servico;
                $result  = $this->resolver->resolve($servico, $item, $dprops);

                $confidences[] = $result['confidence'];
                $contracts[]   = $this->montarContrato($servico, $item, $result);
            }
        } else {
            // ── Fluxo legado (sem line items) — deal.amount + Servico::where nome ──
            $servicoNome = $dprops[$propsDeal['servico']] ?? null;
            $servico     = $servicoNome
                ? Servico::where('nome', $servicoNome)->where('ativo', true)->first()
                : null;

            if ($servico) {
                $result = $this->resolver->resolve($servico, [], $dprops);
                $confidences[] = $result['confidence'];
                $contracts[]   = $this->montarContrato($servico, [], $result);
            } elseif ($servicoNome) {
                // Nome presente no deal mas nao bate com o catalogo — warning
                // (gravacao em notes fica a cargo do controller, como hoje).
                $warnings[] = [
                    'name'   => $servicoNome,
                    'motivo' => 'servico_nao_encontrado',
                ];
            }
        }

        // ── Confidence agregada do DTO: a MENOR entre os contratos ──────────────
        // (ordenacao low < medium < high). Sem contratos: 'low' se ha warning
        // pendente (algo nao foi resolvido), 'high' quando nao ha nada a reportar.
        $confidence = empty($contracts)
            ? (empty($warnings) ? 'high' : 'low')
            : $this->menorConfianca($confidences);

        return new HubspotHandoffData(
            deal_data: $dprops,
            line_items: $lineItems,
            contracts_to_create: $contracts,
            warnings: $warnings,
            confidence: $confidence,
        );
    }

    /**
     * Monta o array pronto para virar um ContratoServico a partir do
     * resultado de HubspotValueResolver::resolve() + dados brutos do line item
     * (ou [] no fluxo legado — hubspot_line_item_id/product_id/currency ficam null).
     */
    private function montarContrato(Servico $servico, array $lineItem, array $result): array
    {
        return [
            'servico_id'                       => $servico->id,
            'servico_nome'                      => $servico->nome,
            'valor_contratado'                  => $result['valor_operacional'],
            'hubspot_line_item_id'              => $lineItem['id'] ?? null,
            'hubspot_product_id'                => $lineItem['hs_product_id'] ?? null,
            'hubspot_billing_frequency'         => $result['billing_frequency'],
            'hubspot_billing_period'            => $result['billing_period'],
            'hubspot_currency'                  => $lineItem['hs_line_item_currency_code'] ?? null,
            'hubspot_valor_original'            => $result['valor_original'],
            'hubspot_valor_original_tipo'       => $result['valor_original_tipo'],
            'hubspot_valor_normalizado_mensal'  => $result['normalizado_mensal'],
            'hubspot_valor_confidence'          => $result['confidence'],
            'hubspot_valor_warning'             => $result['warning'],
            'hubspot_snapshot'                  => [
                'line_item'       => $lineItem,
                'resolver_result' => $result,
            ],
        ];
    }

    /**
     * Devolve a MENOR confiança entre uma lista ('low' < 'medium' < 'high').
     * Valor desconhecido é tratado como 'high' (mais permissivo) para nunca
     * derrubar a confiança agregada por um valor inesperado.
     */
    private function menorConfianca(array $confidences): string
    {
        $ordem = ['low' => 0, 'medium' => 1, 'high' => 2];
        $menor = 'high';

        foreach ($confidences as $c) {
            $rank      = $ordem[$c] ?? $ordem['high'];
            $rankMenor = $ordem[$menor] ?? $ordem['high'];
            if ($rank < $rankMenor) {
                $menor = $c;
            }
        }

        return $menor;
    }
}
