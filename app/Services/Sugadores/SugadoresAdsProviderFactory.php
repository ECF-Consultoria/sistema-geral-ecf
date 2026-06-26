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
 *
 * Phase 42 D-05 (cut-over):
 *  - Plan 42-04 inverte a preferência: ML passa a ser primário quando empresa
 *    tem mlToken active. Adman vira fallback puro (empresas sem mlToken).
 *  - Remoção completa do Adman fica para Phase 43 após paridade >= 95% por 7d.
 *
 * Regras de resolução (vigentes pós Phase 42 Plan 42-04):
 *  - forceName='adman' → retorna AdmanProvider sem checar supports() (override
 *    explícito do caller, ex: comando CLI passando --provider=adman).
 *  - forceName='ml'    → retorna MercadoLivreSugadoresProvider sem checar supports()
 *    (caller assume responsabilidade — útil em smoke / debug).
 *  - sem forceName     → checa supports() em ordem. Preferência por ML quando
 *    AMBOS suportam (cut-over Phase 42 D-05). Adman vira fallback quando ML não
 *    suporta. Se nenhum suportar, lança RuntimeException com mensagem clara.
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

        // Phase 42 D-05: cut-over para ML como provider primário.
        // Auto-detecção prefere ML quando empresa tem mlToken active; Adman vira fallback.
        // Plano de remoção completa do Adman (Phase 43) só inicia após paridade >= 95% por 7d.
        if ($this->mlProvider->supports($company)) {
            return $this->mlProvider;
        }

        if ($this->admanProvider->supports($company)) {
            return $this->admanProvider;
        }

        throw new \RuntimeException(
            "Empresa {$company->id} sem provider compatível "
            . '(sem adman_account_id e sem mlToken ativo).'
        );
    }
}
