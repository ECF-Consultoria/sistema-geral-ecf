<?php

namespace App\Services\Metrics;

use App\Models\Company;

/**
 * Factory de resolução de providers de métricas para leitura unificada
 * (Phase 60 Plan 60-03 Task 2).
 *
 * RED SKELETON — GREEN commit imediatamente seguinte implementa `forCompany`
 * + `caseFor` conforme regra de precedência do ADR DATA-04.
 */
class MetricsProviderFactory
{
    public function __construct(
        private AdmanMetricsProvider $adman,
        private MlMetricsProvider $ml,
    ) {
    }

    /**
     * Retorna array ordenado de providers que devem contribuir para a empresa.
     *
     * @return array<int, \App\Contracts\MetricsProvider>
     */
    public function forCompany(Company $company): array
    {
        throw new \RuntimeException('MetricsProviderFactory::forCompany — GREEN pendente (Plan 60-03 Task 2).');
    }

    /**
     * Retorna literal ('ambos'|'so-ml'|'so-adman'|'none') que identifica o
     * caso do ADR DATA-04.
     */
    public function caseFor(Company $company): string
    {
        throw new \RuntimeException('MetricsProviderFactory::caseFor — GREEN pendente (Plan 60-03 Task 2).');
    }
}
