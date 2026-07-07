---
phase: 60-base-multi-fonte-backend-ml-adman-unificado
milestone: v14.0 — Confiabilidade + Polish
status: concluida
plans_executed:
  - 60-01 (ADR DATA-04)
  - 60-02 (Contract + DTO + AdmanMetricsProvider)
  - 60-03 (MlMetricsProvider + MetricsProviderFactory)
  - 60-04 (UnifiedMetricsService + BaselineRegressionTest)
completed: 2026-07-07
tests_total: 46
assertions_total: 188
regressao_legacy: 0
requirements_atendidos:
  - DATA-04 (precedência ML vs Adman)
  - DATA-06 (base multi-fonte para dashboards)
---

# Phase 60 — Base Multi-Fonte (Backend ML + Adman Unificado) — SUMMARY

Base backend completa para leitura unificada de métricas por empresa+período
com regra de precedência formalizada em ADR, contract + DTO imutável, 2
providers (Adman DB-read + ML LIVE com cache 15 min) e serviço orquestrador
com fusão campo-a-campo. Zero regressão em consumidores legados.

## Status dos 4 Success Criteria do ROADMAP Phase 60

| SC | Descrição | Prova executável | Status |
|----|-----------|------------------|--------|
| **#1** | Camada backend consegue ler métricas de ML e Adman sem quebrar em 3 casos (só-Adman / só-ML / ambos) | `UnifiedMetricsServiceTest` T1/T2/T3 (nomeados por caso) + `AdmanMetricsProviderTest` (13) + `MlMetricsProviderTest` (11) | ✅ |
| **#2** | Regra de precedência documentada em ADR versionado | `.planning/adrs/DATA-04-precedencia-multifonte.md` (8 seções, tabela 15 campos, 4 valores de `source`) | ✅ |
| **#3** | Testes automatizados dos 3 casos com fixtures + RefreshDatabase | `UnifiedMetricsServiceTest` T1-T11 (11 testes cobrindo 3 casos nomeadamente + none + divergência TACOS) | ✅ |
| **#4** | Delta regressão = 0 nos consumidores atuais de `adman_metrics` | `BaselineRegressionTest` 6/6 verdes + `git diff HEAD -- <legacy>` = 0 linhas | ✅ |

## Requisitos atendidos

- **DATA-04** — Precedência multi-fonte ML vs Adman: formalizada no ADR
  DATA-04, implementada em `UnifiedMetricsService::mergeFields()` e coberta
  por T3-T8 do `UnifiedMetricsServiceTest`.
- **DATA-06** — Base backend multi-fonte para dashboards: entregue como
  contract + 2 providers + factory + serviço orquestrador; consumidor
  próximo é Phase 61 (dashboards com badge de origem).

## Contagem de testes por plan

| Plan | Test files | Verdes | Assertions |
|------|-----------|--------|-----------|
| 60-01 | (ADR — sem código) | — | — |
| 60-02 | `AdmanMetricsProviderTest` | 13 | 50 |
| 60-03 | `MlMetricsProviderTest` (11) + `MetricsProviderFactoryTest` (5) | 16 | 53 |
| 60-04 | `UnifiedMetricsServiceTest` (11) + `BaselineRegressionTest` (6) | 17 | 85 |
| **Total** | 5 arquivos | **46** | **188** |

Verificado via `php artisan test tests/Feature/Phase60` — 46/46 verdes.

## Artefatos entregues

### Código de produção (novos — 5 arquivos)

- `app/Contracts/MetricsProvider.php` — interface 3 métodos (60-02).
- `app/Services/Metrics/UnifiedMetricsDto.php` — DTO `final readonly` 19 props (60-02).
- `app/Services/Metrics/AdmanMetricsProvider.php` — leitura pura `adman_metrics` (60-02).
- `app/Services/Metrics/MlMetricsProvider.php` — envelope `MercadoLivreService` + cache 15 min (60-03).
- `app/Services/Metrics/MetricsProviderFactory.php` — resolução 4 casos ADR (60-03).
- `app/Services/Metrics/UnifiedMetricsService.php` — orquestrador fusão campo-a-campo (60-04).

### ADR + Documentação

- `.planning/adrs/DATA-04-precedencia-multifonte.md` — ADR versionado.
- `.planning/phases/60-*/60-01-SUMMARY.md` a `60-04-SUMMARY.md` — 4 sumários.
- `.planning/phases/60-*/PHASE-SUMMARY.md` — este arquivo.

### Testes (novos — 5 arquivos, 46 casos)

- `tests/Feature/Phase60/AdmanMetricsProviderTest.php` — 13 casos.
- `tests/Feature/Phase60/MlMetricsProviderTest.php` — 11 casos.
- `tests/Feature/Phase60/MetricsProviderFactoryTest.php` — 5 casos.
- `tests/Feature/Phase60/UnifiedMetricsServiceTest.php` — 11 casos.
- `tests/Feature/Phase60/BaselineRegressionTest.php` — 6 casos.

## Decisões-chave da phase

1. **ML é fonte primária, Adman enriquece campos exclusivos** (ADR DATA-04).
   Justificativa: cutover v11.0 Phase 42 promoveu ML como canonical; Adman
   reflete último sync (delay até 24h). ML é fresh no período consultado.
2. **4 valores do enum `source` travados** (`adman|ml|unified|none`). Nenhum
   consumidor pode inventar valor novo sem atualizar o ADR.
3. **Detecção do caso via campos denormalizados** (`is_ml_driven` +
   `adman_account_id`), NÃO consulta pivot `company_marketplaces`. Justificativa:
   evita JOIN em hotpath de dashboards; accessors legacy da Phase 57 mantêm contrato.
4. **Cache ML 15 min** por par `(company, período, ml)` — balanceia frescor
   com custo de rate limit.
5. **Divergência TACOS > 5%** gera `Log::warning` estruturado, nunca falha o
   cálculo. Endereça memory `project_adman_data_sources`.
6. **Coexistência total com legado nesta phase** — Phase 60 entrega apenas
   infra + testes; migração dos 6 controllers atuais fica para Phase 61.

## Coexistência com legado — delta zero auditado

`git diff HEAD --` nos arquivos legacy retorna **0 linhas**:

- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Http/Controllers/PortfolioController.php`
- `app/Http/Controllers/CompanyController.php` (working tree pré-existente
  não relacionado ao Plan 60-04 — untracked-from-scope)
- `app/Services/AdmanService.php`
- `app/Services/MercadoLivreService.php`

Baseline regressão coberta por 6 smoke tests que rodam rotas legadas com
admin autenticado e asseguram 200 sem exception.

## Próxima phase

**Phase 61 — Dashboards multi-fonte + badge de origem (DASH-06 / DATA-05).**

Consumidor natural do `UnifiedMetricsService`:

1. Habilitar `UNIFIED_METRICS_ENABLED=true` no env de produção (feature flag do ADR).
2. Migrar `DashboardController` (admin) para consumir `UnifiedMetricsService->readForCompany()`
   em vez de query direta em `adman_metrics`.
3. Exibir badge visual no card KPI conforme `$dto->source`
   (`adman` = amarelo/legacy; `ml` = azul/fresh; `unified` = verde/completo;
   `none` = cinza/sem integração + CTA).
4. Adicionar painel de divergências TACOS no `/dev/desenvolvimento`
   consumindo os logs estruturados desta phase — evidência auditável do
   problema registrado em memory `project_adman_data_sources`.
5. Considerar migração de `CompanyController`, `PerformanceController`,
   `AdminController`, `PortfolioController` para o novo service (opcional
   nesta phase — pode ficar para v14.1).

`Phase 60 → CONCLUÍDA. Ready for Phase 61.`
