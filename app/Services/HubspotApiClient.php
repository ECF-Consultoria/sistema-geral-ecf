<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Phase 34 Plan 34-04 — Wrapper HTTP para HubSpot CRM API v3.
 *
 * Cobre os 3 GETs necessarios pelo HubspotWebhookController quando um deal
 * vira "Fechado Ganho":
 *   1. fetchDeal($id, $props)               — propriedades do deal
 *   2. fetchAssociatedCompanyId($dealId)    — primeiro company associado
 *   3. fetchCompany($id, $props)            — propriedades do company
 *
 * Token: Bearer de Private App (config('services.hubspot.access_token')).
 * Base: https://api.hubapi.com (fixa).
 *
 * Erros 4xx/5xx em fetchDeal/fetchCompany sao re-lancados via $res->throw()
 * para o controller capturar e marcar HubspotEvento.status='erro'. Em
 * fetchAssociatedCompanyId um 404 (deal sem company) e tratado como null
 * — situacao valida (deal nao tem company associada).
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
}
