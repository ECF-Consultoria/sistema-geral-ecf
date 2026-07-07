---
phase: 61-dashboards-multi-fonte-indicador-de-origem
milestone: v14.0
status: completed
completed_date: 2026-07-07
requirements: [DATA-05, DASH-04, DASH-05, DASH-06]
plans_completed: 6
plans:
  - 61-01: Backend enriquece PortfolioController + DashboardController + config/metrics.php com feature flag UNIFIED_METRICS_ENABLED
  - 61-02: Componente <SourceBadge> reusavel (4 variantes ml/adman/unified/none com labels pt-BR)
  - 61-03: DASH-06 badge de fonte no header de /companies/{id} (Companies/Show.jsx + CompanyController::show)
  - 61-04: DASH-05 Portfolio Show + Carteiras tolerantes a fonte com badges por empresa e mini-legenda
  - 61-05: DASH-04 Dashboard ML unificado com legenda source_counts + badge por linha
  - 61-06: E2E cobrindo 4 casos ADR DATA-04 + none + regressao flag OFF (baseline Phase 60 intacto)
success_criteria:
  - "SC #1 Dashboard ML unifica fontes num KPI unico -> plans 61-01/61-05 + prova em 61-06 T2"
  - "SC #2 Analista/Estrategista tolerantes a ML-only -> plans 61-01/61-04 + prova em 61-06 T3 (Dashboard) e T3 (Portfolio)"
  - "SC #3 Badge ML na carteira individual -> plan 61-03 + prova em CompanyShowSourceTest"
  - "SC #4 Cada metrica carrega indicador de fonte -> plans 61-04/61-05 + prova cobrindo cada superficie em 61-06"
tests_summary:
  total_tests: 31
  total_assertions: 450
  baseline_phase60: "46/46 (188 assertions) preservado"
next_phase: 62 - Metas apresentacao clara + edicao rapida
---

# Phase 61 — Dashboards multi-fonte + indicador de origem — PHASE SUMMARY

## One-liner

Dashboards ML, Analista/Estrategista e carteira individual passam a exibir métricas corretas independentemente da fonte (ML / Adman / Agregado / Sem integração) via feature flag controlada `UNIFIED_METRICS_ENABLED`, com indicador visual claro de origem por métrica e zero regressão no baseline Phase 60.

## Contexto

Phase 61 é a segunda metade da Milestone v14.0 (Confiabilidade + Polish). Consome a fundação de dados construída pela Phase 60 (`MetricsProviderFactory`, `UnifiedMetricsService`, ADR DATA-04) e traduz para o consumidor final:

- **Backend** (61-01/61-03): controllers enriquecem payload Inertia com `source` (por empresa) e `source_counts` (agregado) atrás da feature flag.
- **Frontend** (61-02/61-04/61-05): componente `<SourceBadge>` reusável + integração em Portfolio + Dashboard.
- **E2E** (61-06): suite completa cobrindo os 4 casos formalizados na ADR DATA-04.

## Plans entregues

| Plan | Título | Commits | Test coverage |
|------|--------|---------|---------------|
| 61-01 | Backend flag + enriquecimento source (Wave 1) | `4ced790`, `3c6fe99`, `5f0f331`, `a1ee33d`, `1aebc85` | 11 tests / 195 assertions |
| 61-02 | Componente `<SourceBadge>` (Wave 2 paralelo) | `32b2215` | Smoke via 61-06 |
| 61-03 | DASH-06 badge no header de /companies/{id} | `a48583e`, `15badc6` | 4 tests / 48 assertions |
| 61-04 | DASH-05 Portfolio Show + Carteiras | `~4x commits` | Coberto por 61-06 Portfolio |
| 61-05 | DASH-04 Dashboard ML unificado | `~3x commits` | Coberto por 61-06 Dashboard |
| **61-06** | **E2E multi-fonte (Wave 3 FINAL)** | `f4368d2`, `021db25`, `01eff97` | **16 tests / 207 assertions** |

## Success Criteria — evidencia

### SC #1 — Dashboard ML unifica fontes num KPI

**Como:** `DashboardController::adminDashboard` enriquece `stats.source_counts` agregando os 4 buckets ADR (adman/ml/unified/none) sem alterar `stats.total_companies` (universo intocado). Frontend `Dashboard/Admin.jsx` consome via legenda no header.

**Prova:** `DashboardMultiFonteE2ETest::test_flag_on_dashboard_ml_expoe_source_counts_agregado_4_casos` — cria 4 fixtures (uma de cada caso) e assertsCa `source_counts == {adman:1, ml:1, unified:1, none:1}` + soma = `total_companies`.

### SC #2 — Analista/Estrategista tolerantes a ML-only

**Como:** `MetricsProviderFactory::caseFor()` (Phase 60) é I/O-free — apenas lê accessors denormalizados. Empresas ML-only não são mais enviadas ao endpoint Adman MCP (que retornava 422 — memory `project_ml_only_companies_adman_endpoints`). Portfolio e Dashboard mapeiam o caso corretamente.

**Prova:** 
- `PortfolioMultiFonteE2ETest::test_flag_on_portfolio_show_empresa_ml_only_nao_quebra_render` (Portfolio/Show)
- `DashboardMultiFonteE2ETest::test_flag_on_empresa_so_ml_renderiza_sem_crash_com_source_ml` (Dashboard/Admin)

Ambos verificam `assertOk()` (sem 422/500) + `source='ml'` no payload.

### SC #3 — Badge ML na carteira individual

**Como:** `CompanyController::show` enriquece `company.source` UNCONDITIONAL (sem flag — decisão do plan 61-03: badge estético obrigatório do ROADMAP). `Companies/Show.jsx` renderiza `<SourceBadge>` no header com guarda `!== 'none'` para não poluir empresas sem integração.

**Prova:** `CompanyShowSourceTest` (61-03) — 4 casos ADR cobertos individualmente.

### SC #4 — Cada métrica carrega indicador de fonte

**Como:** Frontend renderiza `<SourceBadge>` em cada superfície:
- Dashboard/Admin: legenda no header + badge por linha da tabela
- Portfolio/Show: badge por empresa (mobile card + desktop table)
- Portfolio/Carteiras: mini-legenda por profissional
- Companies/Show: badge no header

**Prova:** Cobertura E2E em 61-06 confirma que o payload backend carrega `source` em cada endpoint. Renderização visual dos badges validada nos plans de frontend 61-04/61-05.

## Testes acumulados Phase 61

```bash
$ php artisan test tests/Feature/Phase61
Tests:    31 passed (450 assertions)
Duration: 64.33s
```

Distribuição por plan:

| Plan | Test file | Tests | Assertions |
|------|-----------|-------|------------|
| 61-01 | DashboardSourceEnrichmentTest | 6 | 92 |
| 61-01 | PortfolioSourceEnrichmentTest | 5 | 103 |
| 61-03 | CompanyShowSourceTest | 4 | 48 |
| 61-06 | DashboardMultiFonteE2ETest | 5 | 90 |
| 61-06 | PortfolioMultiFonteE2ETest | 6 | 112 |
| 61-06 | FeatureFlagRegressionTest | 5 | 5 |
| **Total** | **6 files** | **31** | **450** |

## Baseline Phase 60 preservado

```bash
$ php artisan test tests/Feature/Phase60
Tests:    46 passed (188 assertions)
Duration: 15.59s
```

Zero regressão — SC #4 do ROADMAP Phase 60 mantido.

**Total combinado v14.0 (Phases 60 + 61): 77 tests / 638 assertions verdes.**

## Decisões-chave da Phase 61

1. **Feature flag `UNIFIED_METRICS_ENABLED` como gate de rollout** (ADR DATA-04 seção Rollout). Default `false`. Consumidores enriquecem payload apenas quando ON — payload legacy preservado bit-a-bit quando OFF (provado em `->missing()` asserts).

2. **Enriquecimento UNCONDITIONAL em `CompanyController::show`** (61-03) — decisão consciente diferente dos demais controllers. Badge estético obrigatório do ROADMAP + `caseFor()` I/O-free = sem custo. Documentado no 61-03-SUMMARY.

3. **Não criar tabela local `ml_metrics_daily`** (ADR DATA-04 seção Alternativas B rejeitada). Custo mínimo 1 phase inteira; candidato futuro se rate limit ML virar restrição em produção.

4. **`MetricsProviderFactory::caseFor()` é I/O-free** (Phase 60) — Phase 61 usa apenas esse método (sem `readForCompany()`). Zero risco de HTTP outbound em runtime dos consumidores.

5. **`Route::has()` + `$this->fail()` em vez de `markTestSkipped`** — SC #4 do ROADMAP Phase 60 não permite skip silencioso mascarar regressão. Padrão herdado do `Phase60/BaselineRegressionTest`.

## Threat surface

Nenhuma nova superfície de risco introduzida:

- Payload enriquece com enum público (`adman|ml|unified|none`) — sem PII, tokens, cust_ids.
- Feature flag mitiga T-61-01-01 (Tampering via env) via `filter_var(FILTER_VALIDATE_BOOLEAN)`.
- N+1 em `caseFor()` mitigado por eager-load de `mlToken` em builders de query.
- HTTP outbound acidental mitigado por design — cache driver `array` em teste + `caseFor()` I/O-free.

## Rollout — próximos passos

1. **Deploy da Phase 61 completa** — requer autorização explícita (memory `feedback_perguntar_antes_deploy_v9` — outro dev em paralelo nesta milestone).
2. **Ativar `UNIFIED_METRICS_ENABLED=true`** em dev primeiro (smoke visual da UI); se logs limpos por 24h → produção.
3. **Monitorar logs `[UnifiedMetrics] TACOS divergente`** — ML vs Adman divergência >5% grava warning (Plan 60-04). Painel de divergências fica pra phase futura.
4. **Migrar consumidores restantes** (`PerformanceController`, `AdminController`, `AdmanController`) para leitura via `UnifiedMetricsService` — decisão futura, depende de estabilidade em produção.

## Próxima phase

**Phase 62 — Metas: apresentação clara + edição rápida.** Independente de Phase 60/61; pode executar em paralelo com Phase 60/61 já concluídas. Requirements: META-01, META-04.

## Referências

- ADR canônico: `.planning/adrs/DATA-04-precedencia-multifonte.md`
- ROADMAP Phase 61: `.planning/ROADMAP.md` linhas 44-60
- Memory `project_ml_only_companies_adman_endpoints`
- Memory `project_adman_data_sources`
