<?php

namespace App\Services\Metrics;

use App\Contracts\MetricsProvider;
use App\Models\Company;
use App\Services\MercadoLivreService;
use Carbon\Carbon;

/**
 * Implementação ML do contract MetricsProvider (Phase 60 Plan 60-03).
 *
 * RED SKELETON — Task 1 Plan 60-03: assinatura pública fixada aqui para
 * permitir que `MlMetricsProviderTest` compile; comportamento completo
 * (cache 15 min + delegação a `MercadoLivreService::fetchOrdersSummary` +
 * `fetchAdsSummary` + tratamento de falha por endpoint) chega no commit
 * GREEN imediatamente seguinte.
 */
class MlMetricsProvider implements MetricsProvider
{
    public function __construct(private MercadoLivreService $mlService)
    {
    }

    public function supports(Company $company): bool
    {
        throw new \RuntimeException('MlMetricsProvider::supports — GREEN pendente (Plan 60-03 Task 1).');
    }

    public function name(): string
    {
        throw new \RuntimeException('MlMetricsProvider::name — GREEN pendente (Plan 60-03 Task 1).');
    }

    public function readForCompany(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        throw new \RuntimeException('MlMetricsProvider::readForCompany — GREEN pendente (Plan 60-03 Task 1).');
    }
}
