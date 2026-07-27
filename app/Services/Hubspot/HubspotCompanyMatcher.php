<?php

namespace App\Services\Hubspot;

use App\Models\Company;

/**
 * HubspotCompanyMatcher — resolve se já existe uma Company correspondente
 * a um deal recebido do HubSpot, ANTES de decidir criar uma nova (Fase 113,
 * plano 03 — HUB-DEDUP-01/02).
 *
 * Ordem de precedência (para no primeiro hit):
 *   0. existing_company_id (replay de evento já vinculado) → match FORTE
 *   1. hubspot_company_id → match FORTE
 *   2. cnpj (comparado só por dígitos)  → match FORTE
 *   3. email_cliente                    → match FRACO
 *   4. hubspot_domain                   → match FRACO
 *   5. name normalizado (HubspotNameNormalizer) → match FRACO
 *
 * Critérios vazios (null/'') NUNCA geram match — evita casar empresas sem
 * cnpj/email preenchido entre si (T-113-03-01/02 do threat register).
 *
 * Debug session hubspot-handoff-sem-contatos (2026-07-27) — o critério
 * `existing_company_id` foi adicionado para resolver um gap descoberto na
 * investigação: quando o webhook original processa ANTES das associações
 * do HubSpot ficarem consultáveis (race condition/eventual consistency),
 * a Company nasce com hubspot_company_id/cnpj/email/domain TODOS vazios —
 * exatamente os critérios usados pelos passos 1-5. Um replay posterior
 * (`reprocessarEvento`), já com as associações populadas, NÃO conseguia
 * mais casar com a company original (nenhum critério bate: hubspot_company_id
 * do banco continua null, nome pode divergir do nome oficial da company no
 * HubSpot) e criava uma Company DUPLICADA. Como o replay sempre conhece o
 * `HubspotEvento.company_id_criada` (a company que ELE MESMO criou/apontou
 * originalmente), esse vínculo é a fonte de verdade mais forte possível —
 * tem prioridade sobre todos os demais critérios.
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
     * @param  array{existing_company_id?: ?int, hubspot_company_id?: ?string, cnpj?: ?string, email?: ?string, domain?: ?string, name?: ?string}  $criterios
     * @return array{company: ?Company, match: 'forte'|'fraco'|null, via: ?string}
     */
    public function encontrar(array $criterios): array
    {
        $existingCompanyId = $criterios['existing_company_id'] ?? null;
        $hubspotCompanyId  = trim((string) ($criterios['hubspot_company_id'] ?? ''));
        $cnpj              = trim((string) ($criterios['cnpj'] ?? ''));
        $email             = trim((string) ($criterios['email'] ?? ''));
        $domain            = trim((string) ($criterios['domain'] ?? ''));
        $name              = trim((string) ($criterios['name'] ?? ''));

        // ── 0. existing_company_id (replay de evento já vinculado) → forte ──
        // Prioridade máxima: se o HubspotEvento sendo reprocessado JÁ aponta
        // para uma Company (company_id_criada), essa é a fonte de verdade —
        // independe de hubspot_company_id/cnpj/email/domain/nome estarem
        // vazios ou terem mudado desde a criação original.
        if ($existingCompanyId !== null) {
            $company = Company::find($existingCompanyId);
            if ($company) {
                return ['company' => $company, 'match' => 'forte', 'via' => 'existing_company_id'];
            }
        }

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
