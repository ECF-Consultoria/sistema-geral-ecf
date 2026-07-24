<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 34 Plan 34-04 — Wrapper HTTP para HubSpot CRM API v3.
 *
 * Cobre os GETs necessarios pelo HubspotWebhookController quando um deal
 * vira "Fechado Ganho":
 *   1. fetchDeal($id, $props)               — propriedades do deal
 *   2. fetchAssociatedCompanyId($dealId)    — primeiro company associado
 *   3. fetchCompany($id, $props)            — propriedades do company
 *
 * Phase 35 Plan 35-02 — adiciona suporte ao contato vinculado (D-04):
 *   4. fetchAssociatedContactId($dealId)    — primeiro contato associado
 *   5. fetchContact($id, $props)            — propriedades do contato
 *
 * Token: Bearer de Private App (config('services.hubspot.access_token')).
 * Base: https://api.hubapi.com (fixa).
 *
 * Erros 4xx/5xx em fetchDeal/fetchCompany/fetchContact sao re-lancados via
 * $res->throw() para o caller capturar. Em fetchAssociatedCompanyId/
 * fetchAssociatedContactId um 404 (deal sem associacao) e tratado como null
 * — situacao valida (deal pode nao ter company/contato associado).
 */
class HubspotApiClient
{
    private const BASE = 'https://api.hubapi.com';

    /**
     * Token pode ser injetado para testes; fallback para config().
     */
    public function __construct(private ?string $token = null)
    {
        $this->token = $this->token ?? config('services.hubspot.access_token');
    }

    /**
     * GET /crm/v3/objects/deals/{id}?properties=...
     *
     * @param  string         $id          objectId do deal no HubSpot
     * @param  array<string>  $properties  lista de prop names a retornar
     * @return array          payload decoded com chave 'properties'
     *
     * @throws \Illuminate\Http\Client\RequestException em 4xx/5xx
     */
    public function fetchDeal(string $id, array $properties): array
    {
        $res = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/deals/{$id}", [
                'properties' => implode(',', $properties),
            ]);
        $res->throw();
        return $res->json();
    }

    /**
     * GET /crm/v3/objects/deals/{id}/associations/companies
     *
     * Retorna o ID do primeiro company associado, ou null se nao houver
     * associacao OU o endpoint retornar erro (resiliente — deal pode existir
     * sem company associado, fluxo segue criando empresa so com dados do deal).
     */
    public function fetchAssociatedCompanyId(string $dealId): ?string
    {
        $res = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/deals/{$dealId}/associations/companies");

        if (!$res->ok()) {
            return null;
        }

        $id = $res->json('results.0.toObjectId');
        return $id !== null ? (string) $id : null;
    }

    /**
     * GET /crm/v3/objects/companies/{id}?properties=...
     *
     * @param  string         $id          objectId do company no HubSpot
     * @param  array<string>  $properties  lista de prop names a retornar
     * @return array          payload decoded com chave 'properties'
     *
     * @throws \Illuminate\Http\Client\RequestException em 4xx/5xx
     */
    public function fetchCompany(string $id, array $properties): array
    {
        $res = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/companies/{$id}", [
                'properties' => implode(',', $properties),
            ]);
        $res->throw();
        return $res->json();
    }

    /**
     * Phase 35 Plan 35-02 — GET /crm/v3/objects/deals/{id}/associations/contacts
     *
     * Retorna o ID do primeiro contato associado, ou null se nao houver
     * associacao OU o endpoint retornar erro (resiliente — deal pode existir
     * sem contato associado; o fluxo segue criando empresa so com os dados do
     * deal + company).
     */
    public function fetchAssociatedContactId(string $dealId): ?string
    {
        $res = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/deals/{$dealId}/associations/contacts");

        if (!$res->ok()) {
            return null;
        }

        $id = $res->json('results.0.toObjectId');
        return $id !== null ? (string) $id : null;
    }

    /**
     * Phase 35 Plan 35-02 — GET /crm/v3/objects/contacts/{id}?properties=...
     *
     * @param  string         $id          objectId do contato no HubSpot
     * @param  array<string>  $properties  lista de prop names a retornar
     * @return array          payload decoded com chave 'properties'
     *
     * @throws \Illuminate\Http\Client\RequestException em 4xx/5xx
     */
    public function fetchContact(string $id, array $properties): array
    {
        $res = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/contacts/{$id}", [
                'properties' => implode(',', $properties),
            ]);
        $res->throw();
        return $res->json();
    }

    /**
     * Phase 37 Plan 37-03 (REQ-37-01) — busca line items associados a um deal HubSpot.
     *
     * Encadeia 2 chamadas:
     *   1. GET /crm/v3/objects/deals/{dealId}/associations/line_items — lista IDs dos line items
     *   2. GET /crm/v3/objects/line_items/{id}?properties=...        — detalhes de cada item
     *
     * Resiliente: 4xx/5xx em associations retorna []; falha em GET /line_items/{id}
     * individual loga warning e pula o item (segue com os demais). Deal sem line
     * items eh cenario valido (retorna [] silencioso).
     *
     * Consumido pelo HubspotWebhookController (Plan 37-04) apos criarEmpresa para
     * materializar contratos_servico em DB::transaction.
     *
     * IMPORTANTE: nunca loga o Bearer token no contexto do warning — somente
     * deal_id / line_item_id / status (T-37-05 do threat model).
     *
     * Phase 111 Plan 111-02 (HUB-API-03) — ampliado com o conjunto completo de
     * propriedades do handoff comercial (MRR/ARR/TCV/ACV, periodo/datas de
     * recorrencia, moeda, description, amount, hs_sku). Campos atuais
     * (id/name/price/quantity/hs_product_id/recurringbillingfrequency)
     * preservados com o mesmo comportamento/cast de antes.
     *
     * @return array<int, array{
     *     id: string,
     *     name: ?string,
     *     description: ?string,
     *     price: ?float,
     *     amount: ?float,
     *     quantity: ?int,
     *     hs_product_id: ?string,
     *     hs_sku: ?string,
     *     recurringbillingfrequency: ?string,
     *     hs_recurring_billing_period: ?string,
     *     hs_recurring_billing_start_date: ?string,
     *     hs_recurring_billing_end_date: ?string,
     *     hs_line_item_currency_code: ?string,
     *     hs_mrr: ?float,
     *     hs_arr: ?float,
     *     hs_tcv: ?float,
     *     hs_acv: ?float
     * }>
     */
    public function fetchDealLineItems(string $dealId): array
    {
        // ─── Chamada 1: associations ───
        // Resiliente: qualquer falha (404 deal inexistente, 500 instabilidade)
        // retorna [] sem propagar excecao — mesmo padrao de fetchAssociatedCompanyId.
        $assocRes = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/deals/{$dealId}/associations/line_items");

        if (!$assocRes->ok()) {
            Log::channel('ecf-webhooks')->warning(
                '[HubSpot Webhook] fetchDealLineItems: associations falhou',
                [
                    'deal_id' => $dealId,
                    'status'  => $assocRes->status(),
                ]
            );
            return [];
        }

        $results = $assocRes->json('results') ?? [];
        $ids = [];
        foreach ($results as $r) {
            if (isset($r['id']) && $r['id'] !== '' && $r['id'] !== null) {
                $ids[] = (string) $r['id'];
            }
        }

        Log::channel('ecf-webhooks')->info(
            '[HubSpot Webhook] fetchDealLineItems: associations OK',
            [
                'deal_id'        => $dealId,
                'line_item_ids'  => $ids,
                'total'          => count($ids),
            ]
        );

        if (empty($ids)) {
            return [];
        }

        // ─── Chamada 2..N: detalhes de cada line item ───
        // Phase 111 Plan 111-02 (HUB-API-03) — conjunto completo de props do
        // handoff comercial (§Fase 3 do prompt canonico). Ordem espelha o
        // shape de saida abaixo.
        $properties = 'name,description,price,amount,quantity,hs_product_id,hs_sku,'
            . 'recurringbillingfrequency,hs_recurring_billing_period,'
            . 'hs_recurring_billing_start_date,hs_recurring_billing_end_date,'
            . 'hs_line_item_currency_code,hs_mrr,hs_arr,hs_tcv,hs_acv';
        $out = [];

        foreach ($ids as $id) {
            $res = Http::withToken($this->token)
                ->get(self::BASE . "/crm/v3/objects/line_items/{$id}", [
                    'properties' => $properties,
                ]);

            if (!$res->ok()) {
                // Falha individual loga warning + pula — segue com os demais.
                // NUNCA logar o token aqui (T-37-05): apenas IDs + status HTTP.
                Log::channel('ecf-webhooks')->warning(
                    '[HubSpot Webhook] Falha ao buscar line item',
                    [
                        'deal_id'      => $dealId,
                        'line_item_id' => $id,
                        'status'       => $res->status(),
                    ]
                );
                continue;
            }

            $props = $res->json('properties') ?? [];

            $out[] = [
                'id'                               => $id,
                'name'                             => $props['name'] ?? null,
                'description'                      => $props['description'] ?? null,
                'price'                            => isset($props['price']) && is_numeric($props['price'])
                    ? (float) $props['price']
                    : null,
                'amount'                           => isset($props['amount']) && is_numeric($props['amount'])
                    ? (float) $props['amount']
                    : null,
                'quantity'                         => isset($props['quantity']) && is_numeric($props['quantity'])
                    ? (int) $props['quantity']
                    : null,
                'hs_product_id'                    => isset($props['hs_product_id'])
                    ? (string) $props['hs_product_id']
                    : null,
                'hs_sku'                           => $props['hs_sku'] ?? null,
                'recurringbillingfrequency'        => $props['recurringbillingfrequency'] ?? null,
                'hs_recurring_billing_period'      => $props['hs_recurring_billing_period'] ?? null,
                'hs_recurring_billing_start_date'  => $props['hs_recurring_billing_start_date'] ?? null,
                'hs_recurring_billing_end_date'    => $props['hs_recurring_billing_end_date'] ?? null,
                'hs_line_item_currency_code'       => $props['hs_line_item_currency_code'] ?? null,
                'hs_mrr'                           => isset($props['hs_mrr']) && is_numeric($props['hs_mrr'])
                    ? (float) $props['hs_mrr']
                    : null,
                'hs_arr'                           => isset($props['hs_arr']) && is_numeric($props['hs_arr'])
                    ? (float) $props['hs_arr']
                    : null,
                'hs_tcv'                           => isset($props['hs_tcv']) && is_numeric($props['hs_tcv'])
                    ? (float) $props['hs_tcv']
                    : null,
                'hs_acv'                           => isset($props['hs_acv']) && is_numeric($props['hs_acv'])
                    ? (float) $props['hs_acv']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Phase 111 Plan 111-02 (HUB-API-03) — GET generico de associacoes v3.
     *
     * GET /crm/v3/objects/{fromObject}/{fromId}/associations/{toObject}
     *
     * Base para fetchAssociatedCompanyIds/fetchAssociatedContactIds e para
     * qualquer par objeto→objeto futuro. Resiliente: 4xx/5xx retorna []
     * (mesmo padrao dos metodos singulares de associacao ja existentes).
     *
     * @return array<int, array{toObjectId: int|string, type?: string}>
     */
    public function fetchAssociations(string $fromObject, string $fromId, string $toObject): array
    {
        $res = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/{$fromObject}/{$fromId}/associations/{$toObject}");

        if (!$res->ok()) {
            return [];
        }

        return $res->json('results') ?? [];
    }

    /**
     * Phase 111 Plan 111-02 (HUB-API-03) — todos os company IDs associados a um deal.
     *
     * Diferente de fetchAssociatedCompanyId (singular, so o primeiro), retorna
     * a lista completa de toObjectId como strings. 404/500 => [] (deal pode
     * nao ter nenhuma company associada — cenario valido).
     *
     * @return array<int, string>
     */
    public function fetchAssociatedCompanyIds(string $dealId): array
    {
        $results = $this->fetchAssociations('deals', $dealId, 'companies');

        return array_values(array_map(
            static fn ($r) => (string) $r['toObjectId'],
            array_filter($results, static fn ($r) => isset($r['toObjectId']))
        ));
    }

    /**
     * Phase 111 Plan 111-02 (HUB-API-03) — todos os contact IDs associados a um deal.
     *
     * Diferente de fetchAssociatedContactId (singular, so o primeiro), retorna
     * a lista completa de toObjectId como strings. 404/500 => [].
     *
     * @return array<int, string>
     */
    public function fetchAssociatedContactIds(string $dealId): array
    {
        $results = $this->fetchAssociations('deals', $dealId, 'contacts');

        return array_values(array_map(
            static fn ($r) => (string) $r['toObjectId'],
            array_filter($results, static fn ($r) => isset($r['toObjectId']))
        ));
    }

    /**
     * Phase 111 Plan 111-02 (HUB-API-03) — busca multiplas companies por ID.
     *
     * Segue o padrao de N GETs ja usado no client (mesmo estilo de
     * fetchDealLineItems para line items individuais), reusando fetchCompany
     * por id. Falha individual loga warning + pula o id — segue com os
     * demais (nao propaga excecao). $ids vazio => [] sem chamada HTTP.
     *
     * @param  array<string>  $ids
     * @param  array<string>  $properties
     * @return array<string, array<string, mixed>>  mapa id => properties
     */
    public function fetchCompanies(array $ids, array $properties): array
    {
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            $res = Http::withToken($this->token)
                ->get(self::BASE . "/crm/v3/objects/companies/{$id}", [
                    'properties' => implode(',', $properties),
                ]);

            if (!$res->ok()) {
                // NUNCA logar o token aqui (T-111-03): apenas ID + status HTTP.
                Log::channel('ecf-webhooks')->warning(
                    '[HubSpot] Falha ao buscar company em fetchCompanies',
                    [
                        'company_id' => $id,
                        'status'     => $res->status(),
                    ]
                );
                continue;
            }

            $out[(string) $id] = $res->json('properties') ?? [];
        }

        return $out;
    }

    /**
     * Phase 111 Plan 111-02 (HUB-API-03) — busca multiplos contacts por ID.
     *
     * Mesmo padrao de fetchCompanies (N GETs reusando fetchContact). Falha
     * individual loga warning + pula o id. $ids vazio => [] sem chamada HTTP.
     *
     * @param  array<string>  $ids
     * @param  array<string>  $properties
     * @return array<string, array<string, mixed>>  mapa id => properties
     */
    public function fetchContacts(array $ids, array $properties): array
    {
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            $res = Http::withToken($this->token)
                ->get(self::BASE . "/crm/v3/objects/contacts/{$id}", [
                    'properties' => implode(',', $properties),
                ]);

            if (!$res->ok()) {
                // NUNCA logar o token aqui (T-111-03): apenas ID + status HTTP.
                Log::channel('ecf-webhooks')->warning(
                    '[HubSpot] Falha ao buscar contact em fetchContacts',
                    [
                        'contact_id' => $id,
                        'status'     => $res->status(),
                    ]
                );
                continue;
            }

            $out[(string) $id] = $res->json('properties') ?? [];
        }

        return $out;
    }
}
