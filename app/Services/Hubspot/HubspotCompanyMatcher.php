<?php

namespace App\Services\Hubspot;

use App\Models\Company;

/**
 * HubspotCompanyMatcher — resolve se já existe uma Company correspondente
 * a um deal recebido do HubSpot, ANTES de decidir criar uma nova (Fase 113,
 * plano 03 — HUB-DEDUP-01/02).
 *
 * Ordem de precedência (para no primeiro hit):
 *   1. hubspot_company_id → match FORTE
 *   2. cnpj (comparado só por dígitos)  → match FORTE
 *   3. email_cliente                    → match FRACO
 *   4. hubspot_domain                   → match FRACO
 *   5. name normalizado (HubspotNameNormalizer) → match FRACO
 *
 * Critérios vazios (null/'') NUNCA geram match — evita casar empresas sem
 * cnpj/email preenchido entre si (T-113-03-01/02 do threat register).
 *
 * Decisão de implementação (discrição do plano): a comparação por CNPJ
 * (dígitos) e por NOME normalizado é feita em PHP, não via SQL — o volume de
 * `companies` é baixo (centenas, T-113-03-04 do threat register aceita o
 * custo) e a normalização (acentos/pontuação/formatação) não é trivial de
 * expressar de forma portável entre os drivers SQLite (testes) e MySQL/MariaDB
 * (produção) usando apenas SQL.
 */
class HubspotCompanyMatcher
{
    /**
     * @param  array{hubspot_company_id?: ?string, cnpj?: ?string, email?: ?string, domain?: ?string, name?: ?string}  $criterios
     * @return array{company: ?Company, match: 'forte'|'fraco'|null, via: ?string}
     */
    public function encontrar(array $criterios): array
    {
        $hubspotCompanyId = trim((string) ($criterios['hubspot_company_id'] ?? ''));
        $cnpj             = trim((string) ($criterios['cnpj'] ?? ''));
        $email            = trim((string) ($criterios['email'] ?? ''));
        $domain           = trim((string) ($criterios['domain'] ?? ''));
        $name             = trim((string) ($criterios['name'] ?? ''));

        // ── 1. hubspot_company_id → forte ───────────────────────────────────
        if ($hubspotCompanyId !== '') {
            $company = Company::where('hubspot_company_id', $hubspotCompanyId)->first();
            if ($company) {
                return ['company' => $company, 'match' => 'forte', 'via' => 'hubspot_company_id'];
            }
        }

        // ── 2. cnpj (só dígitos) → forte ────────────────────────────────────
        if ($cnpj !== '') {
            $cnpjDigitos = preg_replace('/\D+/', '', $cnpj);
            if ($cnpjDigitos !== '' && $cnpjDigitos !== null) {
                $company = Company::whereNotNull('cnpj')
                    ->where('cnpj', '!=', '')
                    ->get(['id', 'cnpj'])
                    ->first(fn (Company $c) => preg_replace('/\D+/', '', (string) $c->cnpj) === $cnpjDigitos);

                if ($company) {
                    // Recarrega o model completo (a query acima só selecionou id/cnpj).
                    return ['company' => Company::find($company->id), 'match' => 'forte', 'via' => 'cnpj'];
                }
            }
        }

        // ── 3. email_cliente → fraco ─────────────────────────────────────────
        if ($email !== '') {
            $company = Company::where('email_cliente', $email)->first();
            if ($company) {
                return ['company' => $company, 'match' => 'fraco', 'via' => 'email'];
            }
        }

        // ── 4. hubspot_domain → fraco ────────────────────────────────────────
        if ($domain !== '') {
            $company = Company::where('hubspot_domain', $domain)->first();
            if ($company) {
                return ['company' => $company, 'match' => 'fraco', 'via' => 'domain'];
            }
        }

        // ── 5. name normalizado → fraco ──────────────────────────────────────
        if ($name !== '') {
            $nomeNormalizado = HubspotNameNormalizer::normalizar($name);
            if ($nomeNormalizado !== '') {
                $company = Company::whereNotNull('name')
                    ->where('name', '!=', '')
                    ->get(['id', 'name'])
                    ->first(fn (Company $c) => HubspotNameNormalizer::normalizar($c->name) === $nomeNormalizado);

                if ($company) {
                    return ['company' => Company::find($company->id), 'match' => 'fraco', 'via' => 'nome'];
                }
            }
        }

        return ['company' => null, 'match' => null, 'via' => null];
    }
}
