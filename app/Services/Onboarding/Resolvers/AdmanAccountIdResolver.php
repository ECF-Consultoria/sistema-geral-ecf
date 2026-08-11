<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\TemplatePasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo 3 do template de Gestão — "Planilha de custos ADMAN".
 *
 * Lê `$company->adman_account_id` SEMPRE pelo accessor Eloquent
 * (`Company::getAdmanAccountIdAttribute()`), nunca por SQL cru. Desde a
 * Phase 57 o valor mora primeiro no pivot `company_marketplaces`
 * (`marketplace='meli'`, coluna `adman_id`) e só cai para a coluna flat
 * `companies.adman_account_id` em fallback — ler a coluna flat direto via
 * query builder ou atributo bruto dá falso-negativo em toda empresa já
 * migrada para o pivot (Pitfall 1 do RESEARCH da Fase 135).
 *
 * Nunca devolve `indeterminado`: não há rede envolvida, o dado ou está no
 * banco ou não está.
 */
class AdmanAccountIdResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return TemplatePasso::AUTO_FONTE_ADMAN_ACCOUNT_ID;
    }

    public function label(): string
    {
        return 'Planilha de custos ADMAN preenchida';
    }

    public function ajuda(): string
    {
        return 'Confere se a empresa tem adman_account_id cadastrado (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $valor = $onboarding->company->adman_account_id;

        if ($valor === null || $valor === '') {
            return OnboardingResolverResultado::naoColetado('Empresa ainda não cadastrada na planilha Adman');
        }

        return OnboardingResolverResultado::concluido(['adman_account_id' => $valor]);
    }
}
