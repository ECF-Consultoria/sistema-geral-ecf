<?php

namespace App\Services\Renovacao;

use App\Models\Company;
use App\Models\HubspotEvento;

/**
 * Fase 202 (D-02) — negócio original (Ganho) da empresa.
 *
 * Só REFERÊNCIA: nunca é lido para escrever no HubSpot. Mesma regra de
 * `AvancarDealHubspotJob::resolverDealId`: deal do último evento processado
 * que criou a empresa; fallback na coluna `hubspot_deal_id`.
 */
class NegocioOriginalResolver
{
    public function resolver(Company $company): ?string
    {
        $doEvento = HubspotEvento::where('company_id_criada', $company->id)
            ->where('status', 'processado')
            ->whereNotNull('object_id')
            ->orderByDesc('id')
            ->value('object_id');

        if (filled($doEvento)) {
            return (string) $doEvento;
        }

        return filled($company->hubspot_deal_id) ? (string) $company->hubspot_deal_id : null;
    }
}
