---
phase: 61-dashboards-multi-fonte-indicador-de-origem
plan: 06
subsystem: [testing, dashboards, portfolio, feature-flag]
tags: [testing, e2e, feature-flag, regressao, cobertura-4-casos, adr-data-04, wave-3-final]
requirements: [DATA-05, DASH-04, DASH-05, DASH-06]
dependency_graph:
  requires:
    - "61-01 (backend enriquece PortfolioController + DashboardController + config/metrics.php)"
    - "61-03 (CompanyController::show enriquece company.source UNCONDITIONAL)"
    - "61-04 (frontend Portfolio consome companies[].source + user_portfolios[].source_counts)"
    - "61-05 (frontend Dashboard consome stats.source_counts + companies_performance[].source)"
    - "Phase 60 (MetricsProviderFactory::caseFor + UnifiedMetricsService bootavel)"
  provides:
    - "Cobertura E2E dos 4 casos ADR DATA-04 (so-Adman / so-ML / ambos / none) em cada superficie afetada"
    - "Regressao da feature flag OFF documentada com fail-fast (Route::has + fail explicito, sem markTestSkipped silencioso)"
    - "Baseline Phase 60 (46/46) preservado apos concluir Phase 61"
  affects:
    - "tests/Feature/Phase61/DashboardMultiFonteE2ETest.php (novo)"
    - "tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php (novo)"
    - "tests/Feature/Phase61/FeatureFlagRegressionTest.php (novo)"
tech_stack:
  added: []
  patterns:
    - "AssertableInertia com combo has()/missing()/where() encadeado para provar shape do payload"
    - "Config::set('metrics.unified_metrics_enabled', bool) por teste — runtime override sem afetar env"
    - "Helper `criarEmpresaPorCaso($caso, $suffix)` — 1 factory helper cobre os 4 casos ADR via match"
    - "Cross-check `viewData('page')['props']` -> Collection::pluck('source', 'id') pra mapping asserts"
    - "Route::has() com $this->fail() explicito no lugar de markTestSkipped silencioso (padrao Phase60/BaselineRegressionTest)"
    - "tap() nas factories Adman-only + ML-only pra encadear attachMlToken() sem quebrar readability"
key_files:
  created:
    - tests/Feature/Phase61/DashboardMultiFonteE2ETest.php
    - tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php
    - tests/Feature/Phase61/FeatureFlagRegressionTest.php
    - .planning/phases/61-dashboards-multi-fonte-indicador-de-origem/61-06-SUMMARY.md
    - .planning/phases/61-dashboards-multi-fonte-indicador-de-origem/PHASE-SUMMARY.md
  modified: []
decisions:
  - "Nao mockar AdmanService no container: os testes Wave 1 (61-01) provaram que cache Adman com Cache driver 'array' (padrao teste) retorna vazio sem HTTP outbound. Simplificar preserva legibilidade e velocidade."
  - "Test 5 do plan (rodar suite Phase60 via `\\Artisan::call('test', ...)` recursivo) DEFERIDO: teste recursivo inflaria duração e traria fragilidade. Baseline Phase 60 e verificado no verify command combinado do plan (`php artisan test tests/Feature/Phase60`) e documentado nesse SUMMARY (46/46 confirmado)."
  - "Task 3 usa `Route::has()` + `$this->fail()` (mesmo padrao do Phase60/BaselineRegressionTest) em vez de `markTestSkipped` — SC #4 do ROADMAP Phase 60 nao permite skip silencioso mascarar regressao."
  - "Helper `criarEmpresaPorCaso` com match sobre string 'so-adman'|'so-ml'|'ambos'|'none' (nao usar caseFor do factory pra evitar dependencia circular no teste) — mapa canonico duplicado com o ADR de proposito, torna o intent do teste literal."
  - "6 tests na Task 2 (nao 5 como texto da mission) para satisfazer o AC do plan (>= 6 test_ methods)."
metrics:
  duration: "~45 min (Wave 3 final da Phase 61)"
  completed_date: "2026-07-07"
  tests_added: 16
  assertions_added: 207
  test_files_created: 3
---

# Phase 61 Plan 06: E2E multi-fonte (Wave 3 — FINAL) — SUMMARY

## One-liner

Suite E2E cobrindo os 4 casos ADR DATA-04 (só-Adman / só-ML / ambos / none) em cada superficie afetada pela Phase 61 (Dashboard ML, Portfolio/Show, Portfolio/Carteiras, companies.show), com regressão da feature flag `UNIFIED_METRICS_ENABLED` OFF e preservação verificada do baseline Phase 60.

## Escopo entregue

### Task 1 — `tests/Feature/Phase61/DashboardMultiFonteE2ETest.php` — 5 tests

Fecha o **SC #1 do ROADMAP Phase 61** (Dashboard ML unifica fontes num KPI).

| # | Teste | Foco |
|---|-------|------|
| 1 | `test_flag_off_dashboard_ml_nao_expoe_source_counts` | Payload legacy preservado — `stats.source_counts` e `companies_performance.0.source` LITERALMENTE ausentes |
| 2 | `test_flag_on_dashboard_ml_expoe_source_counts_agregado_4_casos` | 4 fixtures (só-Adman + só-ML + ambos + none) → `stats.source_counts == {adman:1, ml:1, unified:1, none:1}` + soma bate com `stats.total_companies` |
| 3 | `test_flag_on_empresa_so_adman_recebe_source_adman` | `caseFor()` → 'so-adman' → mapeia para 'adman' |
| 4 | `test_flag_on_empresa_so_ml_renderiza_sem_crash_com_source_ml` | Prova SC #2 (memory `project_ml_only_companies_adman_endpoints` — sem 422) |
| 5 | `test_flag_on_empresa_none_aparece_no_dashboard_com_source_none` | DTO sentinela ADR: none NÃO é omitida do universo |

**5 tests / 90 assertions** — commit `f4368d2`.

### Task 2 — `tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php` — 6 tests

Fecha o **SC #2 e SC #3 do ROADMAP Phase 61** (Portfolio tolerante a ML-only + badge de origem por empresa).

| # | Teste | Foco |
|---|-------|------|
| 1 | `test_flag_off_portfolio_show_nao_expoe_source_em_companies` | Regressao bit-a-bit — `companies.0.source` ausente |
| 2 | `test_flag_on_portfolio_show_expoe_source_por_empresa_nos_4_casos` | 4 empresas mistas → cada uma mapeada corretamente por ADR |
| 3 | `test_flag_on_portfolio_show_empresa_ml_only_nao_quebra_render` | Isolado: 1 ML-only → render OK sem 422/500 |
| 4 | `test_flag_on_portfolio_carteiras_admin_expoe_source_counts_por_user` | Carteiras admin → `user_portfolios[N].source_counts` com contagens corretas e soma = companies_count |
| 5 | `test_flag_off_portfolio_carteiras_admin_nao_expoe_source_counts` | Regressao — `user_portfolios.0.source_counts` LITERALMENTE ausente |
| 6 | `test_flag_on_portfolio_show_empresa_none_aparece` | ADR sentinela — none aparece com `source='none'` |

**6 tests / 112 assertions** — commit `021db25`.

### Task 3 — `tests/Feature/Phase61/FeatureFlagRegressionTest.php` — 5 tests

Regressão focada de feature flag OFF nas rotas afetadas pela Phase 61.

| # | Teste | Foco |
|---|-------|------|
| 1 | `test_flag_default_false_em_config` | Default seguro — `config('metrics.unified_metrics_enabled') === false` sem env |
| 2 | `test_flag_off_route_dashboard_ml_permanece_200` | Smoke HTTP — `mercadolivre.dashboard` OK com flag OFF |
| 3 | `test_flag_off_route_portfolio_own_permanece_200` | Smoke HTTP — `portfolio.own` OK com flag OFF |
| 4 | `test_flag_off_route_companies_show_permanece_200` | Smoke HTTP — `companies.show` OK (61-03 enriquece unconditionally; regressão aqui é apenas de status) |
| 5 | `test_flag_off_route_portfolio_show_permanece_200` | Smoke HTTP — `portfolio.show` OK com flag OFF (analista carteira vazia) |

**5 tests / 5 assertions** — commit `01eff97`.

## Verificação

```bash
$ php artisan test tests/Feature/Phase61
Tests:    31 passed (450 assertions)
Duration: 64.33s

$ php artisan test tests/Feature/Phase60
Tests:    46 passed (188 assertions)
Duration: 15.59s
```

**Total combinado: 77 verdes, 638 assertions.** Baseline Phase 60 (46/46, 188 assertions) preservado bit-a-bit — zero regressão.

### Distribuição Phase 61 pós Wave 3

| Plano | Test file | Tests | Assertions |
|-------|-----------|-------|------------|
| 61-01 | DashboardSourceEnrichmentTest | 6 | 92 |
| 61-01 | PortfolioSourceEnrichmentTest | 5 | 103 |
| 61-03 | CompanyShowSourceTest | 4 | 48 |
| 61-06 | **DashboardMultiFonteE2ETest** | **5** | **90** |
| 61-06 | **PortfolioMultiFonteE2ETest** | **6** | **112** |
| 61-06 | **FeatureFlagRegressionTest** | **5** | **5** |
| **Total** | **6 files** | **31** | **450** |

## Mapeamento SC do ROADMAP → evidencia de teste

| SC ROADMAP Phase 61 | Requirement | Teste que prova |
|---------------------|-------------|-----------------|
| **#1** Dashboard ML unifica fontes num KPI | DASH-04 | `DashboardMultiFonteE2ETest::test_flag_on_dashboard_ml_expoe_source_counts_agregado_4_casos` |
| **#2** Analista/Estrategista tolerantes a ML-only | DASH-05 | `PortfolioMultiFonteE2ETest::test_flag_on_portfolio_show_empresa_ml_only_nao_quebra_render` + `DashboardMultiFonteE2ETest::test_flag_on_empresa_so_ml_renderiza_sem_crash_com_source_ml` |
| **#3** Badge ML na carteira individual | DASH-06 | `CompanyShowSourceTest::test_empresa_so_ml_recebe_source_ml` (61-03) + `PortfolioMultiFonteE2ETest` cobrindo Show |
| **#4** Cada métrica carrega indicador visual da fonte | DATA-05 | `DashboardMultiFonteE2ETest::test_flag_on_empresa_so_adman_recebe_source_adman` + Portfolio tests + Wave 2 61-05 |

## Deviations from Plan

**1. Test 5 do Task 3 (invocar suite Phase60 via `\\Artisan::call`) DEFERIDO** — o próprio plan documentou essa possibilidade ("Test 5 pode ser deferido — ver constraints"). Baseline Phase 60 verificado no verify command combinado (`php artisan test tests/Feature/Phase60`) e resultado (46/46, 188 assertions) documentado neste SUMMARY. Alternativa recursiva foi rejeitada: inflaria duração e traria fragilidade.

**2. Task 2 entregou 6 tests (não 5 como sugere trechos da mission)** — o AC do plan (`grep -c "public function test_" ... >= 6`) exige 6, cumprido literalmente. Alinhamento com a versão canônica do plan.

**3. Não mockei AdmanService no container** — o plan sugeria mock em service container ("`$this->app->instance(AdmanService::class, Mockery::mock(...))`"). Os testes Wave 1 (61-01) demonstraram que o cache Adman com Cache driver `array` (padrão em teste) retorna vazio sem outbound HTTP. Como todos os testes Phase 61 dependem apenas de `caseFor()` (I/O-free) e SUM DB fallback, o mock virou over-engineering. Testes de 61-01 e 61-06 rodam < 5s cada sem HTTP — objetivo do threat T-61-06-01 alcançado por design (empty cache, empty AdmanMetric).

## Threat surface

Todos os threats do plan cobertos:

- **T-61-06-01** (Tampering — HTTP outbound acidental): mitigado por design — cache driver `array` + `caseFor()` I/O-free eliminam necessidade de mock explícito.
- **T-61-06-02** (Availability — SQLite não-transacional): mitigado — `use RefreshDatabase` reseta base entre testes.
- **T-61-06-03** (Repudiation — falha em CI): mitigado — fixtures determinísticas com `now()->subDay()` explícito.
- **T-61-06-SC** (Supply chain): n/a — nenhum novo package.

Nenhuma superfície nova de risco introduzida.

## Rollout (próximos passos após esta phase)

1. **Deploy da Phase 61 completa** — todos os plans 01-06 fecham a wave. Requer autorização explícita (memory `feedback_perguntar_antes_deploy_v9`).
2. **Virar `UNIFIED_METRICS_ENABLED=true` em produção** — após deploy, seguir a estratégia da ADR DATA-04 seção "Rollout": ativação incremental em dev primeiro; se logs limpos por 24h → produção.
3. **Monitorar logs de divergência TACOS** — o `UnifiedMetricsService` (Plan 60-04) grava `Log::warning('[UnifiedMetrics] TACOS divergente', ...)` quando ML e Adman diferem >5%. Painel de divergências fica pro Plan 62+ (fora do escopo Phase 61).
4. **Phase 62** — Metas apresentação clara + edição rápida (próxima na ROADMAP).

## Self-Check: PASSED

- `tests/Feature/Phase61/DashboardMultiFonteE2ETest.php` — FOUND
- `tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php` — FOUND
- `tests/Feature/Phase61/FeatureFlagRegressionTest.php` — FOUND
- Commit `f4368d2` (Task 1) — FOUND
- Commit `021db25` (Task 2) — FOUND
- Commit `01eff97` (Task 3) — FOUND
- Phase61 31/31 verde — CONFIRMED (450 assertions)
- Phase60 baseline 46/46 verde — CONFIRMED (188 assertions)
