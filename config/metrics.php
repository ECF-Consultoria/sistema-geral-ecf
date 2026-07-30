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

    /*
    |----------------------------------------------------------------------
    | Feature flag da agregação do profissional por empresa (Fase 120)
    |----------------------------------------------------------------------
    |
    | Troca a origem de `nota_final`/`score_status` do modelo AGREGADO por
    | profissional (`DesempenhoScoreService::computeNotaFinal`/
    | `computeScoreStatus`, média da carteira antes da régua) para a MÉDIA
    | DAS NOTAS POR EMPRESA produzidas pelo `CompanyScoreService` da Fase
    | 119 (régua aplicada por empresa, antes da média).
    |
    | Default (`false`): preserva 100% do comportamento legado — nenhum dos
    | ~40 call-sites existentes de `compute()`/`computeCached()` muda de
    | comportamento. `CompanyScoreService` fica bootável em testes mas não
    | é consumido em runtime de produção.
    |
    | Casting explícito via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` aceita
    | `'true'`, `'1'`, `'on'` (case-insensitive) da env como bool `true` — e
    | rejeita valores ambíguos como `'yes'`, `'sim'` preservando semântica
    | estrita (defesa contra tampering T-120-01 do threat model).
    |
    | IMPORTANTE: consumidores devem ler via
    | `config('metrics.performance_company_first_score')` — NUNCA
    | `env('PERFORMANCE_COMPANY_FIRST_SCORE')` direto em runtime. O Laravel
    | invalida `env()` fora de config files quando o cache de config está
    | aquecido em produção (`php artisan config:cache`).
    |
    | LIGAR EM PRODUÇÃO DEPENDE DE DOIS PRÉ-REQUISITOS (120-CONTEXT.md):
    |  1. GATE MPP-04 aprovado — hoje `reprovado` (cobertura de
    |     `percentageMargin.prev` sob contenção real).
    |  2. Delta da Fase 121 aceito pelo usuário — ligar antes disso muda o
    |     número que paga bônus sem evidência do tamanho da mudança.
    |
    */
    'performance_company_first_score' => filter_var(
        env('PERFORMANCE_COMPANY_FIRST_SCORE', false),
        FILTER_VALIDATE_BOOLEAN
    ),

];
