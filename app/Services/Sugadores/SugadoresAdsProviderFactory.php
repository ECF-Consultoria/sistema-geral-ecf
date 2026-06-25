<?php

namespace App\Services\Sugadores;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;

/**
 * Factory de resolução do provider de anúncios para o módulo Sugadores.
 *
 * Phase 39 Plan 39-01: versão minimal — só Adman registrado. Plan 39-02 adiciona
 * a branch para MercadoLivreSugadoresProvider; Plan 39-04 refatora o
 * SugadorAnalysisService para consumir este factory ao invés do AdmanService
 * direto; Phase 42 introduz cut-over por empresa via envs SUGADORES_PROVIDER_MODE
 * e SUGADORES_ML_PRIMARY_COMPANIES.
 *
 * Regras de resolução (Plan 39-01):
 *  - forceName='adman' → retorna AdmanProvider sem checar supports() (override
 *    explícito do caller, ex: comando CLI passando --provider=adman).
 *  - forceName='ml'    → lança RuntimeException (placeholder até Plan 39-02).
 *  - sem forceName     → checa supports() em ordem (hoje só Adman). Se nenhum
 *    suportar a empresa, lança RuntimeException com mensagem clara.
 *
 * A preferência default por Adman (quando ambos providers existirem em Plan 39-02)
 * vale até Phase 42 introduzir lógica de cut-over por empresa.
 */
class SugadoresAdsProviderFactory
{
    public function __construct(private AdmanSugadoresProvider $admanProvider) {}

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
            throw new \RuntimeException(
                'MercadoLivreSugadoresProvider ainda não implementado (Plan 39-02). '
                . 'Use --provider=adman ou aguarde a entrega do Plan 39-02.'
            );
        }

        if ($this->admanProvider->supports($company)) {
            return $this->admanProvider;
        }

        throw new \RuntimeException(
            "Empresa {$company->id} sem provider compatível "
            . '(Phase 39 Plan 39-01: apenas Adman disponível; Plan 39-02 adiciona ML).'
        );
    }
}
