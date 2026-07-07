---
phase: 60-base-multi-fonte-backend-ml-adman-unificado
plan: 01
subsystem: metrics-multifonte
tags: [adr, multi-fonte, precedencia, ml, adman]
requires: []
provides:
  - "ADR versionado DATA-04 com regra de precedência ML vs Adman"
  - "Vocabulário do campo source (4 valores) travado para 60-02/03/04"
  - "Estratégia de cache ML (TTL 15 min + chave canônica)"
  - "Feature flag UNIFIED_METRICS_ENABLED declarada"
affects:
  - .planning/adrs/DATA-04-precedencia-multifonte.md
tech-stack:
  added: []
  patterns:
    - "ADR (Architecture Decision Record) — template DATA-01"
    - "Precedência campo-a-campo em tabela markdown"
    - "Enum de source (adman|ml|unified|none) para DTO"
key-files:
  created:
    - .planning/adrs/DATA-04-precedencia-multifonte.md
    - .planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/60-01-SUMMARY.md
  modified: []
decisions:
  - "ML é fonte primária; Adman enriquece campos exclusivos (net_billing, taxes, product_cost, contribution_margin, shipping_cost, return_cost, profit_share)"
  - "Detecção do caso usa campos denormalizados de companies (is_ml_driven + adman_account_id), NÃO consulta pivot company_marketplaces — accessors legacy + hotpath"
  - "4 valores válidos para DTO.source: 'adman', 'ml', 'unified', 'none'"
  - "TTL de cache ML = 15 min; chave canônica unified_metrics:{company_id}:{from}:{to}:{source}"
  - "Feature flag UNIFIED_METRICS_ENABLED (env, default false); flip para true no início da Phase 61"
  - "Criação de tabela ml_metrics_daily local fica OUT-OF-SCOPE v14.0 (candidato v15.x)"
metrics:
  duration: "~15 min"
  completed: 2026-07-07
---

# Phase 60 Plan 01: ADR Precedência Multi-fonte — Summary

ADR DATA-04 versionado em `.planning/adrs/` estabelece regra de precedência
formal entre ML e Adman como fontes de métricas, fecha o Success Criterion
#2 da Phase 60 (regra documentada em ADR versionado) e trava o vocabulário
de `source` do `UnifiedMetricsDto` antes da implementação começar em 60-02.

## O que foi entregue

- **`.planning/adrs/DATA-04-precedencia-multifonte.md`** com 8 seções
  obrigatórias: Contexto (6 fatos), Decisão (precedência + 3 casos +
  detecção + vocabulário source + definição operacional ML + tabela
  campo-a-campo + reconciliação TACOS), Consequências positivas (5),
  Consequências negativas (3), Alternativas consideradas (3 rejeitadas),
  Estratégia de cache ML, Rollout e feature flag, Referências.
- **Tabela de precedência campo-a-campo** com 15 linhas: revenue, ad_spend,
  sold_quantity, tacos, net_billing, sales_fee, taxes, shipping_cost,
  product_cost, contribution_margin, acos, roas, clicks, impressions,
  orders_count.

## Decisão de precedência (headline)

**ML é fonte PRIMÁRIA** para métricas que ambas as fontes expõem
(revenue, ad_spend, sold_quantity, sales_fee, orders_count, tacos derivado).
**Adman ENRIQUECE** campos exclusivos que ML não expõe hoje: `net_billing`,
`taxes`, `product_cost`, `contribution_margin`, `shipping_cost`,
`return_cost`, `profit_share`.

Justificativa: cutover v11.0 (Phase 42) já promoveu ML como canonical; ML
é fresh no período consultado, Adman é offline cacheado (delay até 24h).

## 3 casos formalizados

1. **só-Adman** — `mlToken` inativo E `adman_account_id` presente →
   leitura 100% de `adman_metrics`; DTO com `source: 'adman'`.
2. **só-ML** — `mlToken.status === 'active'` E `adman_account_id` NULL →
   leitura 100% via `MercadoLivreService`; DTO com `source: 'ml'`; campos
   exclusivos-Adman retornam `null`.
3. **ambos** — `mlToken.status === 'active'` E `adman_account_id` presente →
   ML dita operacionais, Adman enriquece contábeis; DTO com
   `source: 'unified'`.

**Caso ausente formalizado**: sem NENHUMA integração → DTO sentinela com
`source: 'none'` (todos numéricos `null`).

## Detecção do caso — decisão explícita

A detecção usa **campos denormalizados** de `companies` (`is_ml_driven` +
`adman_account_id`) e **NÃO** consulta pivot `company_marketplaces`.
Justificativa: (i) accessors legacy Phase 57 mantêm contrato; (ii) evita
JOIN adicional em hotpath de dashboards; (iii) migração para pivot é
`DATA-FUTURE-02`, out-of-scope v14.0.

## TTL de cache decidido

- **TTL**: 15 minutos por par (empresa, período, tipo).
- **Chave canônica**: `unified_metrics:{company_id}:{from}:{to}:{source}`.
- **Invalidação**: `Cache::forget` no fim de `AdmanService::syncCompany()`
  e ao transição de `mlToken.status`.
- Caso `'none'` NÃO é cacheado.

## Feature flag

- **Nome**: `UNIFIED_METRICS_ENABLED` (env variable).
- **Default**: `false` na v14.0 inicial.
- **Semântica**: quando `true`, `UnifiedMetricsService` está disponível
  para injeção; consumidores optam em migrar. Flip para `true` no início
  da Phase 61, após validação dos testes automatizados dos 3 casos.
- **Zero regressão nesta phase**: os 6 controllers atuais
  (`DashboardController`, `CompanyController`, `PerformanceController`,
  `AdminController`, `PortfolioController`, `AdmanController`) continuam
  lendo `adman_metrics` direto.

## Reconciliação de TACOS

Divergência ML vs Adman > 5% → `Log::warning('[UnifiedMetrics] TACOS divergente', [...])`
com `company_id`, `periodo`, `ml`, `adman`. **Nunca falha o cálculo** —
ML vence, log fica para auditoria (endereça memory
`project_adman_data_sources`).

## Deviations from Plan

None — plan executado exatamente como escrito. Frontmatter, todas as 8
seções, os 3 casos, a detecção via denormalização, o vocabulário de 4
valores do `source`, a tabela campo-a-campo com 15 linhas, cache TTL e
feature flag conforme especificado. Zero placeholder `[TODO]/[TBD]/[definir]`.

## Self-Check: PASSED

- `.planning/adrs/DATA-04-precedencia-multifonte.md` criado (frontmatter
  válido, 8 seções, tabela 15 linhas, 4 valores de source, referências).
- `.planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/60-01-SUMMARY.md`
  criado.
- Zero arquivo modificado fora de `.planning/`.

## Próximo plan

**60-02** — Contract `MetricsProvider` (interface) + DTO `UnifiedMetricsDto`
(com enum `source` travado neste ADR) + `AdmanMetricsProvider` (lê
`adman_metrics` local). Este ADR é a âncora obrigatória de leitura para
60-02.
