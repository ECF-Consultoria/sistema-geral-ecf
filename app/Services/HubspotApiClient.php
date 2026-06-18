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
     * @return array<int, array{id: string, name: ?string, price: ?float, quantity: ?int, hs_product_id: ?string, recurringbillingfrequency: ?string}>
     */
    public function fetchDealLineItems(string $dealId): array
    {
        // ─── Chamada 1: associations ───
        // Resiliente: qualquer falha (404 deal inexistente, 500 instabilidade)
        // retorna [] sem propagar excecao — mesmo padrao de fetchAssociatedCompanyId.
        $assocRes = Http::withToken($this->token)
            ->get(self::BASE . "/crm/v3/objects/deals/{$dealId}/associations/line_items");

        if (!$assocRes->ok()) {
            return [];
        }

        $results = $assocRes->json('results') ?? [];
        $ids = [];
        foreach ($results as $r) {
            if (isset($r['id']) && $r['id'] !== '' && $r['id'] !== null) {
                $ids[] = (string) $r['id'];
            }
        }

        if (empty($ids)) {
            return [];
        }

        // ─── Chamada 2..N: detalhes de cada line item ───
        $properties = 'name,price,quantity,hs_product_id,recurringbillingfrequency';
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
                'id'                        => $id,
                'name'                      => $props['name'] ?? null,
                'price'                     => isset($props['price']) && is_numeric($props['price'])
                    ? (float) $props['price']
                    : null,
                'quantity'                  => isset($props['quantity']) && is_numeric($props['quantity'])
                    ? (int) $props['quantity']
                    : null,
                'hs_product_id'             => isset($props['hs_product_id'])
                    ? (string) $props['hs_product_id']
                    : null,
                'recurringbillingfrequency' => $props['recurringbillingfrequency'] ?? null,
            ];
        }

        return $out;
    }
}
