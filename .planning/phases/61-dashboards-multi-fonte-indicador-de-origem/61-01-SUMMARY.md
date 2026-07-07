---
phase: 61-dashboards-multi-fonte-indicador-de-origem
plan: 01
subsystem: dashboards-multifonte
tags: [backend, feature-flag, unified-metrics, tdd, dashboards]
requires:
  - ADR DATA-04 (Plan 60-01) — precedência ML vs Adman + 4 valores de source
  - Plan 60-03 — MetricsProviderFactory::caseFor() literal ('ambos'|'so-ml'|'so-adman'|'none')
  - Plan 60-04 — UnifiedMetricsService bootável (não consumido aqui — só caseFor)
provides:
  - "config/metrics.php expondo unified_metrics_enabled (default false)"
  - "PortfolioController enriquece companies[].source (Portfolio/Show) e user_portfolios[].source_counts (Portfolio/Carteiras) atrás da flag"
  - "DashboardController::adminDashboard enriquece stats.source_counts + companies_performance[].source atrás da flag"
  - "Consumidores 61-04 (Portfolio UI) e 61-05 (Dashboard UI) recebem payload pronto pra <SourceBadge>"
affects:
  - config/metrics.php
  - .env.example
  - app/Http/Controllers/PortfolioController.php
  - app/Http/Controllers/DashboardController.php
  - tests/Feature/Phase61/PortfolioSourceEnrichmentTest.php
  - tests/Feature/Phase61/DashboardSourceEnrichmentTest.php
tech-stack:
  added: []
  patterns:
    - "Feature flag lida via config() — nunca env() em runtime (Laravel config caching)"
    - "Helper factoryToSource(Company): match sobre caseFor() → mapa 'ambos'→'unified' etc"
    - "Enriquecimento condicional atrás da flag: array-spread (`...($x !== null ? ['k'=>$x] : [])`) preserva payload legacy BIT-A-BIT quando OFF"
    - "Eager-load de `mlToken` no builder de empresas — evita N+1 em caseFor() (mitigação T-61-01-02)"
    - "TDD RED → GREEN por task (2 test files, 11 casos, 195 assertions)"
    - "filter_var(env, FILTER_VALIDATE_BOOLEAN) rejeita valores ambíguos (defesa T-61-01-01)"
key-files:
  created:
    - config/metrics.php
    - tests/Feature/Phase61/PortfolioSourceEnrichmentTest.php
    - tests/Feature/Phase61/DashboardSourceEnrichmentTest.php
  modified:
    - .env.example
    - app/Http/Controllers/PortfolioController.php
    - app/Http/Controllers/DashboardController.php
decisions:
  - "Feature flag lida SEMPRE via config() — `env()` em runtime é anti-padrão Laravel (invalidado por config:cache)"
  - "Helpers privados factoryToSource() duplicados entre os 2 controllers — extração pra trait fica pra v14.x se justificar (evita over-engineering nesta phase)"
  - "Eager-load `mlToken` incondicional no builder (mesmo com flag OFF): custo é 1 query extra, ganho é zero N+1 quando flag vira ON sem redeploy"
  - "Array-spread condicional (PHP 8.1+) preserva payload legacy BIT-A-BIT quando flag OFF — testes provam ausência real das chaves (missing), não null"
  - "Nenhuma chamada a readForCompany() neste plan — só caseFor() (I/O-free). readForCompany fica pros consumidores 61-05+ que substituírem query direta"
metrics:
  duration_min: 30
  completed: 2026-07-07
  tests_added: 11
  assertions_added: 195
---

# Phase 61 Plan 01 — Backend flag + enriquecimento source (Wave 1) — SUMMARY

Backend Phase 61 completo: `config/metrics.php` com feature flag ADR DATA-04,
`PortfolioController` e `DashboardController::adminDashboard` enriquecendo
payloads Inertia com `source` (por empresa) e `source_counts` (agregado) —
condicionado à flag `UNIFIED_METRICS_ENABLED`. Preserva 100% do
comportamento legacy quando flag OFF; adiciona metadados sem alterar
universo/totais quando flag ON. 11/11 testes verdes, 46/46 baseline Phase
60 preservado (delta zero).

## Escopo entregue

### Task 1 — `config/metrics.php` + `.env.example`

- Novo arquivo `config/metrics.php` (51 linhas) expõe
  `unified_metrics_enabled` como bool via
  `filter_var(env('UNIFIED_METRICS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)`.
- `.env.example` ganha entrada `UNIFIED_METRICS_ENABLED=false` com
  comentário pt-BR de 1 linha.
- Docblock topo do config referencia ADR
  `.planning/adrs/DATA-04-precedencia-multifonte.md` seção "Rollout e
  feature flag" e explicita a regra "consumidores leem via `config()` —
  nunca `env()` em runtime".
- Threat T-61-01-01 (Tampering via env) mitigado: casting estrito rejeita
  `'yes'`/`'sim'`/`'off'` como falsy — só `'true'`/`'1'`/`'on'` viram
  bool true.
- Commit: `4ced790`.

### Task 2 — `PortfolioController` enriquece com source (TDD)

- Constructor ganha 3º arg `MetricsProviderFactory` (coexistência com
  `AdmanService` + `PortfolioScoreService`).
- Helpers privados `unifiedMetricsEnabled()` + `factoryToSource(Company)`
  — mapa canônico ADR: `'ambos'→'unified'`, `'so-ml'→'ml'`,
  `'so-adman'→'adman'`, `'none'→'none'`.
- **`renderPortfolio` (Portfolio/Show)**: bloco condicional pré-`Inertia::render`
  mapeia cada linha de `$companies` para `source` usando
  `$rawCompanies->keyBy('id')` (O(1) lookup). `rawCompanies` ganha
  eager-load de `mlToken`.
- **`renderCarteirasConsolidadas` (Portfolio/Carteiras)**: bloco condicional
  varre a carteira de cada profissional e agrega
  `source_counts = {adman, ml, unified, none}` por user_portfolio.
- Zero mudança em cache Adman (`getCachedGrossBillingsMany`,
  `getCachedAccountMetricsMany` intocados — count baseline preservado: 6
  ocorrências).
- CRUD de metas (`storeGoal`/`updateGoal`/`destroyGoal`) intocado.
- Testes: 5/5 verdes cobrindo flag OFF, flag ON com 3 casos (adman/ml/unified),
  ML-only sem erro Adman, empresa sem integração.
- Commits: RED `3c6fe99`, GREEN `5f0f331`.

### Task 3 — `DashboardController::adminDashboard` enriquece com source (TDD)

- Constructor ganha 2º arg `MetricsProviderFactory` (coexistência com
  `AdmanService`).
- Helpers privados `unifiedMetricsEnabled()` + `factoryToSource(Company)`
  — duplicados 1:1 do PortfolioController (decisão consciente: refactor
  pra trait fica pra v14.x se justificar).
- **`adminDashboard`**: pré-computa `$sourceByCompanyId` (dicionário
  `id→enum`) e `$sourceCounts` em UMA passada por `$companies`. Alimenta:
  - `stats.source_counts` via array-spread condicional
  - `companies_performance[N].source` via array-spread condicional
- Array-spread `...($x !== null ? ['k' => $x] : [])` garante que chave
  fica LITERALMENTE ausente quando flag OFF — payload legacy preservado
  bit-a-bit (testes provam via `->missing()`).
- Eager-load de `mlToken` adicionado ao `companiesQuery` (mitigação
  T-61-01-02: caseFor() em loop de empresas sem N+1).
- **Fora de escopo (intocado)**: `userDashboard`, `ecf`, `shopee`,
  `amazon`, `AdmanMetric::whereIn` calls (baseline preservado: 2
  ocorrências).
- Testes: 6/6 verdes cobrindo flag OFF (missing `source`+`source_counts`),
  flag ON com 3 casos, ML-only sem 422, none renderiza, delta zero em
  `stats.total_companies` entre OFF e ON.
- Commits: RED `a1ee33d`, GREEN `1aebc85`.

## Verificação

```bash
$ php artisan test tests/Feature/Phase61
Tests:    11 passed (195 assertions)

$ php artisan test tests/Feature/Phase60
Tests:    46 passed (188 assertions)  # baseline preservado
```

Total combinado: **57 verdes, 383 assertivas**.

## Acceptance criteria — todos atendidos

| # | Critério | Prova |
|---|----------|-------|
| T1.1 | `config/metrics.php` existe e retorna `unified_metrics_enabled` | `test -f` + `grep -c` = 3 |
| T1.2 | `.env.example` tem `UNIFIED_METRICS_ENABLED=false` | `grep -c` = 1 |
| T1.3 | `config('metrics.unified_metrics_enabled') === false` sem env | verificado via `php -r` |
| T2.1 | `MetricsProviderFactory` em PortfolioController ≥ 2 | 4 ocorrências |
| T2.2 | `unified_metrics_enabled` em PortfolioController ≥ 1 | 1 ocorrência |
| T2.3 | `source_counts` em PortfolioController ≥ 1 | 2 ocorrências |
| T2.4 | Cache Adman calls preservadas (baseline pré-plan) | 6 ocorrências (intocado) |
| T2.5 | Test file ≥ 5 `test_` methods | 5 métodos |
| T2.6 | Phase60 46/46 verde | ✓ |
| T3.1 | `MetricsProviderFactory` em DashboardController ≥ 2 | 3 ocorrências |
| T3.2 | `unified_metrics_enabled` em DashboardController ≥ 1 | 1 ocorrência |
| T3.3 | `source_counts` em DashboardController ≥ 1 | 3 ocorrências |
| T3.4 | `AdmanMetric::whereIn` baseline preservado ≥ 2 | 2 ocorrências (intocado) |
| T3.5 | Test file ≥ 6 `test_` methods | 6 métodos |
| T3.6 | Phase60/BaselineRegressionTest 6/6 verde | ✓ |

## Deviações do plano

Nenhuma. Executado exatamente como escrito. As 3 tasks + 2 test files
seguiram RED → GREEN sem retrabalho. Fixture de empresas do adminDashboard
exigiu criação explícita de `ContratoServico` ativo com
`Servico::SETOR_PERFORMANCE` (documentado em `criarEmpresa()` helper) —
não é deviação, é reflexo direto do filtro `whereHas('contratosServico'...)`
das linhas 201-206 do DashboardController.

## Threat surface

Nenhuma nova superfície de risco introduzida. Endpoints existentes
enriquecem payload com enum público (`adman|ml|unified|none`) — sem
vazamento de secrets, tokens ou cust_ids. Threat model do PLAN.md
totalmente coberto:

- **T-61-01-01** (Tampering via env) → mitigado por
  `filter_var(FILTER_VALIDATE_BOOLEAN)`
- **T-61-01-02** (N+1 em caseFor) → mitigado por eager-load de `mlToken`
  em ambos controllers
- **T-61-01-03** (Info disclosure via payload) → aceito: enum de 4
  strings públicos, sem PII

## Consumidores próximos

- **61-03** — Frontend `<SourceBadge>` primitivo (Wave 2 — já entregue em
  paralelo pelo 61-02 hash `32b2215`).
- **61-04** — Portfolio/Show + Portfolio/Carteiras consomem
  `companies[].source` e `user_portfolios[].source_counts`.
- **61-05** — Dashboard/Admin consome `companies_performance[].source` e
  `stats.source_counts`.
- **61-06** — Painel de divergências TACOS no `/dev/desenvolvimento`
  consumindo os logs estruturados do `UnifiedMetricsService` (Plan 60-04).

## Rollout

- Flag continua **OFF em produção** até 61-04/05 estarem prontos.
- Ativação pontual em dev (`UNIFIED_METRICS_ENABLED=true`) permite
  smoke-test de payload sem afetar produção.
- Feature flag será virada para `true` no início de 61-04 conforme SC #3
  do ROADMAP Phase 60 (validação de testes automatizados).

## Self-Check: PASSED

- `config/metrics.php` — FOUND
- `tests/Feature/Phase61/PortfolioSourceEnrichmentTest.php` — FOUND
- `tests/Feature/Phase61/DashboardSourceEnrichmentTest.php` — FOUND
- Commit `4ced790` — FOUND (Task 1)
- Commit `3c6fe99` — FOUND (Task 2 RED)
- Commit `5f0f331` — FOUND (Task 2 GREEN)
- Commit `a1ee33d` — FOUND (Task 3 RED)
- Commit `1aebc85` — FOUND (Task 3 GREEN)
- Phase60 baseline 46/46 verde — CONFIRMED
- Phase61 11/11 verde — CONFIRMED
