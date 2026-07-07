<?php

namespace App\Services\Metrics;

use App\Contracts\MetricsProvider;
use App\Models\Company;

/**
 * Factory de resolução de providers de métricas para leitura unificada
 * (Phase 60 Plan 60-03 Task 2).
 *
 * Difere estruturalmente do `SugadoresAdsProviderFactory` (Phase 42 D-05)
 * em UM aspecto essencial: aquele factory retorna UM provider por empresa
 * (Sugadores usa uma fonte só por vez, com fallback). Este factory retorna
 * um ARRAY ordenado de providers — porque no caso "ambos" (empresa com
 * Adman + ML ativos) o `UnifiedMetricsService` (Plan 60-04) precisa
 * consultar as DUAS fontes e reconciliar campo-a-campo. O array preserva
 * a ordem canônica de precedência do ADR DATA-04 (ML primeiro, Adman
 * enriquece); o service consumidor deve iterar nessa ordem para aplicar
 * a fusão corretamente.
 *
 * Regras (ADR DATA-04 seção "Detecção do caso"):
 *  - Ambos providers suportam        → [ML, Adman]   (caso "ambos")
 *  - Só MlMetricsProvider suporta    → [ML]          (caso "só-ml")
 *  - Só AdmanMetricsProvider suporta → [Adman]       (caso "só-adman")
 *  - Nenhum suporta                  → []            (caso "none")
 *
 * Detecção usa os accessors denormalizados da própria Company
 * (`adman_account_id`, `mlToken?->status === 'active'`) — SEM consultar
 * pivot `company_marketplaces` (ADR DATA-04 justifica: consumidores
 * atuais continuam usando os campos flat; migração pra pivot fica para
 * DATA-FUTURE-02).
 *
 * Método auxiliar `caseFor()` retorna literal ('ambos'|'so-ml'|'so-adman'|
 * 'none') para logs, métricas de dashboard e discriminação de branches
 * em testes/consumidores.
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md`.
 */
class MetricsProviderFactory
{
    public function __construct(
        private AdmanMetricsProvider $adman,
        private MlMetricsProvider $ml,
    ) {
    }

    /**
     * Providers ordenados por precedência que devem contribuir para a
     * leitura unificada da empresa.
     *
     * Ordem canônica no caso "ambos": [ML, Adman]. O consumidor
     * (UnifiedMetricsService no Plan 60-04) percorre o array assumindo
     * que o primeiro é o primário (ML) e os seguintes enriquecem os
     * campos exclusivos (Adman preenche net_billing/taxes/etc que ML
     * não expõe).
     *
     * Caso "none" retorna array vazio — permite ao consumidor emitir
     * DTO sentinela com `source='none'` sem tratamento especial de erro.
     *
     * @return array<int, MetricsProvider>
     */
    public function forCompany(Company $company): array
    {
        $mlSupports    = $this->ml->supports($company);
        $admanSupports = $this->adman->supports($company);

        if ($mlSupports && $admanSupports) {
            // ADR DATA-04: ML primário — deve vir primeiro no array.
            return [$this->ml, $this->adman];
        }

        if ($mlSupports) {
            return [$this->ml];
        }

        if ($admanSupports) {
            return [$this->adman];
        }

        return [];
    }

    /**
     * Classifica a empresa em um dos 4 casos do ADR DATA-04.
     *
     * Útil para logs estruturados (`['case' => 'ambos']`), assertivas em
     * testes de Plan 60-04 e discriminação de branch em consumidores que
     * precisam decidir badge de origem (DATA-05 UI).
     *
     * @return 'ambos'|'so-ml'|'so-adman'|'none'
     */
    public function caseFor(Company $company): string
    {
        $mlSupports    = $this->ml->supports($company);
        $admanSupports = $this->adman->supports($company);

        return match (true) {
            $mlSupports && $admanSupports  => 'ambos',
            $mlSupports                    => 'so-ml',
            $admanSupports                 => 'so-adman',
            default                        => 'none',
        };
    }
}
