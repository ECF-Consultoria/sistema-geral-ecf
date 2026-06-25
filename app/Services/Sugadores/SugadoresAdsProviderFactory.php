<?php

namespace App\Services\Sugadores;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;

/**
 * Factory de resolução do provider de anúncios para o módulo Sugadores.
 *
 * Phase 39:
 *  - Plan 39-01 entregou versão minimal (só Adman registrado, branch 'ml' lançava
 *    RuntimeException).
 *  - Plan 39-02 (este) adiciona MercadoLivreSugadoresProvider e ativa a branch 'ml'.
 *  - Plan 39-04 refatora o SugadorAnalysisService para consumir este factory ao
 *    invés do AdmanService direto.
 *  - Phase 42 introduz cut-over por empresa via envs SUGADORES_PROVIDER_MODE
 *    e SUGADORES_ML_PRIMARY_COMPANIES.
 *
 * Regras de resolução (Plan 39-02):
 *  - forceName='adman' → retorna AdmanProvider sem checar supports() (override
 *    explícito do caller, ex: comando CLI passando --provider=adman).
 *  - forceName='ml'    → retorna MercadoLivreSugadoresProvider sem checar supports()
 *    (caller assume responsabilidade — útil em smoke / debug).
 *  - sem forceName     → checa supports() em ordem. Preferência por Adman quando
 *    AMBOS suportam (regra travada até Phase 42 introduzir cut-over por empresa).
 *    Se nenhum suportar, lança RuntimeException com mensagem clara.
 */
class SugadoresAdsProviderFactory
{
    public function __construct(
        private AdmanSugadoresProvider $admanProvider,
        private MercadoLivreSugadoresProvider $mlProvider,
    ) {}

    /**
     * Resolve qual provider deve atender a empresa.
     *
     * @param Company $company
     * @param string|null $forceName Override explícito ('adman'|'ml'). Quando null,
     *                               usa auto-detecção via supports().
     */
    public function for(Company $company, ?string $forceName = null): SugadoresAdsProvider
    {
        if ($forceName === 'adman') {
            return $this->admanProvider;
        }

        if ($forceName === 'ml') {
            return $this->mlProvider;
        }

        // Auto-detecção: Adman tem prioridade quando ambos suportam (compat até Phase 42).
        if ($this->admanProvider->supports($company)) {
            return $this->admanProvider;
        }

        if ($this->mlProvider->supports($company)) {
            return $this->mlProvider;
        }

        throw new \RuntimeException(
            "Empresa {$company->id} sem provider compatível "
            . '(sem adman_account_id e sem mlToken ativo).'
        );
    }
}
