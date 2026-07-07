<?php

/*
|--------------------------------------------------------------------------
| Métricas multi-fonte (ADR DATA-04)
|--------------------------------------------------------------------------
|
| Referência canônica: `.planning/adrs/DATA-04-precedencia-multifonte.md`
| (seção "Rollout e feature flag" — linhas 298-303).
|
| Feature flag `unified_metrics_enabled` controla o rollout gradual do
| enriquecimento de dashboards com o campo `source` (`adman|ml|unified|none`)
| via `MetricsProviderFactory::caseFor()`.
|
|  - Default (`false`): consumidores (PortfolioController, DashboardController)
|    preservam 100% do comportamento legado — leituras `AdmanMetric` diretas
|    + cache Adman intocados. `UnifiedMetricsService` fica bootável em testes
|    mas não é consumido em runtime de produção.
|
|  - Ativado (`true`): consumidores enriquecem cada linha de empresa com
|    `source` + agregam `source_counts` em stats/carteiras. O caminho legado
|    continua ativo em paralelo (coexistência) — a flag apenas ADICIONA
|    metadados no payload Inertia, nunca substitui dados existentes.
|
| IMPORTANTE: consumidores devem ler via `config('metrics.unified_metrics_enabled')`
| — NUNCA `env('UNIFIED_METRICS_ENABLED')` direto em runtime. O Laravel invalida
| `env()` fora de config files quando o cache de config está aquecido em produção
| (`php artisan config:cache`).
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Feature flag da camada multi-fonte
    |----------------------------------------------------------------------
    |
    | Ativa/desativa o enriquecimento com `source` nos dashboards Phase 61.
    | Casting explícito via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` aceita
    | `'true'`, `'1'`, `'on'` (case-insensitive) da env como bool `true` — e
    | rejeita valores ambíguos como `'yes'`, `'sim'`, `'off'` preservando
    | semântica estrita (defesa contra tampering T-61-01-01 do threat model).
    |
    */
    'unified_metrics_enabled' => filter_var(
        env('UNIFIED_METRICS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),

];
