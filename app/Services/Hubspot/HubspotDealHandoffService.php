<?php

namespace App\Services\Hubspot;

use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Carbon\Carbon;

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
     * Fase 113 Plan 113-02 (HUB-CONTATO-01/02) — parâmetros OPCIONAIS ao final
     * (defaults preservam 100% o comportamento anterior de valor/contratos —
     * chamadas com 3 args, como `Phase112HandoffServiceTest`, continuam
     * idênticas). Quando informados, preenchem `company_data`/`contact_data`
     * do DTO com a normalização de company/contatos já buscados pelo controller.
     *
     * @param  array       $deal              payload HubSpot do deal (chave 'properties')
     * @param  array       $lineItems         line items normalizados (shape de HubspotApiClient::fetchDealLineItems); [] cai no fluxo legado
     * @param  array       $propsDeal         config('services.hubspot.props.deal') — mapeia nome lógico -> nome da property HubSpot
     * @param  array|null  $hubCompany        payload HubSpot da company associada (chave 'properties') ou null
     * @param  array       $contatosLogicos   lista completa de contatos já normalizados (chaves lógicas id/firstname/lastname/email/phone/mobilephone/jobtitle)
     * @param  array|null  $contatoPrincipal  contato principal já escolhido (HubspotContactSelector) ou null
     * @param  array       $propsCompany      config('services.hubspot.props.company') — mapeia nome lógico -> nome da property HubSpot
     */
    public function build(
        array $deal,
        array $lineItems,
        array $propsDeal,
        ?array $hubCompany = null,
        array $contatosLogicos = [],
        ?array $contatoPrincipal = null,
        array $propsCompany = [],
    ): HubspotHandoffData {
        $dprops = $deal['properties'] ?? [];

        $contracts   = [];
        $warnings    = [];
        $confidences = [];

        // ── Quick 260820-jc8 (Tarefa 2) — data da 1ª parcela + dia de vencimento ──
        // `data_do_1_pagamento` é property do DEAL e vale pra TODOS os serviços
        // do negócio (não existe uma data por line item) — resolvida UMA vez
        // aqui e aplicada a CADA contrato via montarContrato(). Vazia => os
        // dois campos ficam null e caem nas pendências que a tela do
        // Administrativo já mostra (ContratoDadosMinimosService) — sem default
        // inventado.
        $dataPrimeiraParcela = $this->parseDataHubspot(
            $dprops[$propsDeal['data_do_1_pagamento'] ?? 'data_do_1_pagamento'] ?? null
        );
        $diaVencimento = $dataPrimeiraParcela?->day;

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
                $contracts[]   = $this->montarContrato($servico, $item, $result, $dataPrimeiraParcela, $diaVencimento);
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
                $contracts[]   = $this->montarContrato($servico, [], $result, $dataPrimeiraParcela, $diaVencimento);
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

        // ── Fase 113 Plan 113-02 (HUB-CONTATO-02) — company_data normalizada ────
        // null quando o controller nao passou $hubCompany (deal sem company
        // associada) — preserva o contrato "sempre null nesta fase" pras
        // chamadas antigas de 3 args (Phase112HandoffServiceTest).
        $companyData = null;
        if ($hubCompany !== null) {
            $cprops = $hubCompany['properties'] ?? [];
            $companyData = [
                'name'     => $cprops[$propsCompany['name'] ?? 'name'] ?? null,
                'cnpj'     => $cprops[$propsCompany['cnpj'] ?? 'cnpj'] ?? null,
                'email'    => $cprops[$propsCompany['email'] ?? 'email'] ?? null,
                'telefone' => $cprops[$propsCompany['phone'] ?? 'phone'] ?? null,
                'domain'   => $cprops[$propsCompany['domain'] ?? 'domain'] ?? null,
            ];
        }

        // ── Fase 113 Plan 113-02 (HUB-CONTATO-01) — contact_data: principal +
        // lista completa (chaves lógicas, já normalizadas pelo controller).
        $contactData = null;
        if ($contatoPrincipal !== null || !empty($contatosLogicos)) {
            $contactData = [
                'principal' => $contatoPrincipal,
                'todos'     => $contatosLogicos,
            ];
        }

        // ── Quick 260820-jc8 (Tarefa 1) — dados de CONTRATO que vêm do DEAL
        // (razão social/CNPJ/endereço), distintos de company_data (que vem da
        // HubSpot COMPANY). Sempre calculado — o deal sempre existe aqui.
        //
        // Quick 260821-cq0 — voltou a devolver as 5 partes do endereço
        // SEPARADAS (não mais uma string composta via comporEndereco(),
        // removido): o contrato de Gestão do jurídico precisa de
        // `{{endereco}}`, `{{bairro}}`, `{{cidade}}`, `{{estado}}` e
        // `{{cep}}` como variáveis distintas. `endereco` aqui é o
        // LOGRADOURO cru (rua e número), mesma property `logradouro` de
        // antes — só parou de ser concatenado com o resto.
        $dealContractData = [
            'razao_social' => $dprops[$propsDeal['razao_social'] ?? 'razao_social'] ?? null,
            'cnpj'         => $dprops[$propsDeal['cnpj_da_empresa'] ?? 'cnpj_da_empresa'] ?? null,
            'endereco'     => $dprops[$propsDeal['logradouro'] ?? 'logradouro'] ?? null,
            'bairro'       => $dprops[$propsDeal['bairro'] ?? 'bairro'] ?? null,
            'cidade'       => $dprops[$propsDeal['cidade'] ?? 'cidade'] ?? null,
            'estado'       => $dprops[$propsDeal['estado'] ?? 'estado'] ?? null,
            'cep'          => $dprops[$propsDeal['cep'] ?? 'cep'] ?? null,
        ];

        return new HubspotHandoffData(
            deal_data: $dprops,
            line_items: $lineItems,
            contracts_to_create: $contracts,
            warnings: $warnings,
            confidence: $confidence,
            company_data: $companyData,
            contact_data: $contactData,
            deal_contract_data: $dealContractData,
        );
    }

    /**
     * Quick 260820-jc8 (Tarefa 2) — converte a property `date` do deal
     * `data_do_1_pagamento` para Carbon.
     *
     * ⚠️ **MEDIDO contra a conta real da ECF em 2026-08-20**, lendo o deal da
     * Maderatto Móveis (`64133858455`) pela própria API:
     *
     *   data_do_1_pagamento  (tipo `date`)      => '2026-08-24'
     *   closedate            (tipo `datetime`)  => '2026-08-31T11:49:23.585Z'
     *
     * Ou seja: property `date` chega como **string 'Y-m-d'**, e `datetime`
     * como **ISO 8601** — NÃO como epoch em milissegundos. A primeira versão
     * deste método afirmava o contrário (epoch ms como formato principal e
     * 'Y-m-d' como "fallback para o futuro"); a medição inverteu isso, e o
     * registro fica aqui para ninguém reintroduzir a suposição errada.
     *
     * O ramo numérico continua existindo porque é barato e cobre o caso de o
     * HubSpot passar a mandar epoch ms — mas é o ramo EXCEPCIONAL, não o real.
     *
     * ⚠️ O timezone UTC explícito no parse não é decoração: o app roda em
     * `America/Sao_Paulo` (UTC-3). Uma data que chegue com componente de hora
     * em meia-noite UTC viraria 21h do dia ANTERIOR se parseada no fuso local
     * — e **dia errado aqui vira dia de vencimento errado num contrato
     * assinado**, que é caro de desfazer.
     */
    private function parseDataHubspot(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        // Ramo EXCEPCIONAL: epoch em milissegundos. Não é o que a conta da ECF
        // devolve hoje (ver docblock), mas custa uma linha cobrir.
        if (is_numeric($valor)) {
            return Carbon::createFromTimestampMs((int) $valor, 'UTC');
        }

        // Ramo REAL, medido: 'Y-m-d' (property `date`) e ISO 8601 (`datetime`).
        try {
            return Carbon::parse((string) $valor, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Monta o array pronto para virar um ContratoServico a partir do
     * resultado de HubspotValueResolver::resolve() + dados brutos do line item
     * (ou [] no fluxo legado — hubspot_line_item_id/product_id/currency ficam null).
     *
     * Quick 260820-jc8 (Tarefa 2) — $dataPrimeiraParcela/$diaVencimento
     * chegam JÁ resolvidos do build() (property do DEAL, uma vez só) e são
     * aplicados IDÊNTICOS a cada contrato deste deal.
     */
    private function montarContrato(
        Servico $servico,
        array $lineItem,
        array $result,
        ?Carbon $dataPrimeiraParcela = null,
        ?int $diaVencimento = null,
    ): array {
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
            // Quick 260820-jc8 (Tarefa 2) — property do DEAL, mesma data em
            // TODOS os contratos deste deal (não existe uma data por serviço).
            'data_primeira_parcela'             => $dataPrimeiraParcela?->toDateString(),
            'dia_vencimento'                    => $diaVencimento,
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
