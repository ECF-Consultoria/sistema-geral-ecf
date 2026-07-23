<?php

namespace App\Services\Metrics;

use App\Models\Company;
use InvalidArgumentException;

/**
 * Fase 109 Plan 01 (SHOP-CAR-01) — roteador por fonte financeira.
 *
 * Onde hoje um consumidor chamava `AdmanMetricDiffService::compute()`
 * diretamente, passa a chamar este dispatcher com o `financial_source`
 * ('adman'|'shopee') já resolvido pelo `CarteiraContextService` (vínculo por
 * setor). O dispatcher NÃO decide a fonte — só roteia pra quem sabe ler.
 *
 * T-109-02 (Tampering): `$source` é validado contra uma whitelist explícita
 * via `match` — qualquer valor fora de 'adman'/'shopee' lança
 * `InvalidArgumentException`, nunca cai num branch silencioso.
 */
class MetricDiffDispatcher
{
    public function __construct(
        private AdmanMetricDiffService $admanMetricDiffService,
        private ShopeeMetricDiffService $shopeeMetricDiffService,
    ) {
    }

    /**
     * Roteia `compute(Company, periodo)` pro service da fonte financeira
     * indicada.
     *
     * @throws InvalidArgumentException  quando `$source` não é 'adman' nem 'shopee'.
     */
    public function compute(Company $company, array $periodo, string $source): array
    {
        return match ($source) {
            'adman'  => $this->admanMetricDiffService->compute($company, $periodo),
            'shopee' => $this->shopeeMetricDiffService->compute($company, $periodo),
            default  => throw new InvalidArgumentException(
                "Fonte financeira desconhecida: '{$source}'. Esperado 'adman' ou 'shopee'."
            ),
        };
    }
}
