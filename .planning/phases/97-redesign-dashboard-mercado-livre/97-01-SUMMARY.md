---
phase: 97-redesign-dashboard-mercado-livre
plan: 01
subsystem: dashboard
tags: [laravel, inertia, adman_metrics, kpi, margem-ponderada, contratos-servico]

# Dependency graph
requires: []
provides:
  - "stats.total_revenue_delta_pct + stats.avg_margin_delta_pp no payload de Dashboard/Admin (janela vs. período imediatamente anterior)"
  - "avg_margin ponderado por faturamento (não mais média simples)"
  - "margin_chart (série diária de margem, mesmo eixo do revenue_chart)"
  - "stats.novas_empresas_count via início de contrato (D3)"
  - "filters.marketplace + dashboard_route_name no payload (base do fix do bug do marketplace)"
affects: [97-02, 97-03, 97-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Janela período-anterior: getPeriodRange() devolve from/to/prev_from/prev_to (mesmo nº de dias, sem gap nem overlap)"
    - "Margem ponderada por faturamento: SUM(revenue*margin)/SUM(revenue), só registros com margem não-nula em ambos numerador/denominador"
    - "whereBetween com limite superior EXCLUSIVO (< próximo dia) para evitar bug do sufixo ' 00:00:00' em colunas date-cast no SQLite"

key-files:
  created:
    - tests/Feature/Dashboard/DashboardMetricasPeriodoTest.php
  modified:
    - app/Http/Controllers/DashboardController.php

key-decisions:
  - "Delta de faturamento e margem calculados via SUM/AVG direto em adman_metrics (não o cache híbrido Adman), para os dois lados da comparação usarem a mesma fonte (apples-to-apples)"
  - "total_revenue_delta_pct é null quando a janela anterior não tem faturamento (front trata como 'sem base', não 0%/infinito)"
  - "novas_empresas_count usa contratos_servico.data_contratacao (D3), nunca companies.created_at"

requirements-completed: [DASH-97-2, DASH-97-3, DASH-97-4]

# Metrics
duration: 55min
completed: 2026-07-20
---

# Phase 97 Plan 01: Backend do Redesign da Dashboard Mercado Livre Summary

**`DashboardController::adminDashboard` ganhou janela período-anterior (delta % faturamento, delta pp margem ponderada), `margin_chart` diário, `novas_empresas_count` por início de contrato, e a base do fix do bug do marketplace (`filters.marketplace` + `dashboard_route_name`) — tudo sem alterar `revenue_chart`/`tacos_chart` existentes.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-07-20T15:20:00Z (aprox.)
- **Completed:** 2026-07-20T19:00:00Z
- **Tasks:** 2/2 completos
- **Files modified:** 2 (1 controller, 1 teste novo)

## Accomplishments
- Janela período-anterior (mesmo nº de dias, imediatamente antes de `from`) alimentando `stats.total_revenue_delta_pct` (delta % de faturamento) e `stats.avg_margin_delta_pp` (delta em pontos percentuais de margem).
- `avg_margin` deixou de ser média simples de `contribution_margin_pct` e passou a ser ponderado por faturamento (`SUM(revenue*margin)/SUM(revenue)`).
- `margin_chart[]` — série diária de margem ponderada, mesmo eixo `d/m` do `revenue_chart`, para a aba "Margem" do gráfico de evolução (D4).
- `stats.novas_empresas_count` — conta empresas do recorte com contrato ativo (setor Performance) iniciado no mês corrente, via `contratos_servico.data_contratacao` (D3), não `companies.created_at`.
- `filters.marketplace` + `dashboard_route_name` adicionados ao payload Inertia — corrige a BASE do bug onde o front perdia o recorte `marketplace='meli'` ao filtrar em `/dashboard/mercadolivre` (CONTEXT Riscos §1). O consumo real no front fica para o Plan 97-03.
- Suíte `tests/Feature/Dashboard/DashboardMetricasPeriodoTest.php` (5 testes) cobrindo os deltas, a margem ponderada, `novas_empresas_count`, o marketplace preservado em `/dashboard/mercadolivre`, e a NÃO-REGRESSÃO explícita da `/dashboard` genérica.

## Task Commits

Ambas as tasks foram implementadas e commitadas em um único commit atômico, já que estão fortemente entrelaçadas no mesmo método (`adminDashboard`) e no mesmo arquivo de teste:

1. **Task 1 + Task 2: Janelas período-anterior, margem ponderada, margin_chart, novas empresas e fix da base do marketplace** - `d114a13` (feat)

**Plan metadata:** (a ser commitado nesta etapa — docs: complete plan)

## Files Created/Modified
- `app/Http/Controllers/DashboardController.php` - `getPeriodRange()` passou a devolver também `prev_from`/`prev_to`; novo cálculo de janela anterior via `adman_metrics` (SUM/AVG direto, restrito ao recorte `$companies`); margem ponderada por faturamento (substitui média simples); `margin_chart` diário; `novas_empresas_count` (D3); `filters.marketplace` + `dashboard_route_name` no payload Inertia.
- `tests/Feature/Dashboard/DashboardMetricasPeriodoTest.php` - 5 testes cobrindo deltas de janela, margem ponderada, `novas_empresas_count`, marketplace preservado em `/dashboard/mercadolivre`, e não-regressão da `/dashboard` genérica.

## Decisions Made
- Fonte dos deltas (faturamento e margem) é `adman_metrics` puro (não o cache híbrido Adman) — os dois lados da comparação (janela atual vs. anterior) precisam vir da mesma fonte para o delta ser matematicamente consistente; o cache híbrido não cobre a janela anterior de qualquer forma.
- `total_revenue_delta_pct` é `null` (não 0 ou infinito) quando a janela anterior não tem faturamento — decisão explícita do plano para o front tratar como "sem base de comparação".
- Margem ponderada só considera registros com `contribution_margin_pct` não-nulo tanto no numerador quanto no denominador, para não diluir a média com dias sem leitura de margem.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Limite superior de `whereBetween('reference_date', ...)` corrigido na query nova da janela anterior**
- **Found during:** Task 1 (implementação da janela período-anterior + teste)
- **Issue:** A coluna `adman_metrics.reference_date` (cast `date` no model) persiste no SQLite com sufixo `" 00:00:00"`. Um `whereBetween` com string pura `"Y-m-d"` no limite superior compara lexicograficamente `"2026-06-19 00:00:00" > "2026-06-19"` e exclui o último dia do intervalo — a query nova da janela anterior perdia 1 dia por empresa (58 de 60 registros esperados no teste), distorcendo o delta de faturamento (106,9% em vez de 100%).
- **Fix:** Query da janela anterior reescrita com limite superior EXCLUSIVO (`where('reference_date', '<', $currentFromN)` em vez de `whereBetween` com string pura), eliminando a ambiguidade independente do sufixo de horário armazenado.
- **Files modified:** `app/Http/Controllers/DashboardController.php`
- **Verification:** `DashboardMetricasPeriodoTest::test_janela_periodo_anterior_calcula_deltas_de_faturamento_e_margem` passa com os valores matemáticos exatos esperados (delta 100%, margem 25%, delta pp 10).
- **Committed in:** `d114a13` (commit único da task)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Correção necessária para a corretude do próprio recurso introduzido nesta fase (delta de faturamento). Não afeta escopo — nenhuma query PRÉ-EXISTENTE foi tocada (ver "Known Issues / Deferred" abaixo).

## Known Issues / Deferred (fora do escopo desta fase)

Registrados em `.planning/phases/97-redesign-dashboard-mercado-livre/deferred-items.md`:

1. **`PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200`** falha pré-existente (403 em vez de 200), reproduzida em isolamento, sem relação com `DashboardController` — não corrigida (fora do escopo do Plan 97-01).
2. **Bug latente pré-existente** nas queries híbridas de revenue/ad_spend (`$sumDbPorEmpresa`, `$sumDb`, `$adSpendDbPorEmpresa`) que usam o mesmo padrão `whereBetween` com string pura no limite superior — mesma classe de bug identificada na Deviation #1 acima, mas nas queries JÁ EXISTENTES (não introduzidas por este plano). Não corrigido aqui (fora do escopo — só a query NOVA desta fase foi corrigida). Recomendado abrir quick task de auditoria.

## Issues Encountered
Nenhum além do já documentado nas Deviations.

## User Setup Required
None - nenhuma configuração externa necessária. Este plano é backend-only; o consumo dos novos props (`margin_chart`, `filters.marketplace`, `dashboard_route_name`, `stats.*_delta_*`) fica para os Plans 97-03/97-04 (frontend).

## Next Phase Readiness
- `DashboardController::adminDashboard` está pronto para o redesign do JSX (Plans 97-03/97-04) consumir os novos props sem nenhuma mudança adicional de backend.
- `revenue_chart` e `tacos_chart` permanecem inalterados na forma — sem regressão para o componente atual.
- `/dashboard` genérica (marketplace ausente) continua funcionando integralmente — coberto por teste dedicado de não-regressão.

---
*Phase: 97-redesign-dashboard-mercado-livre*
*Completed: 2026-07-20*

## Self-Check: PASSED

- FOUND: app/Http/Controllers/DashboardController.php
- FOUND: tests/Feature/Dashboard/DashboardMetricasPeriodoTest.php
- FOUND: .planning/phases/97-redesign-dashboard-mercado-livre/97-01-SUMMARY.md
- FOUND: .planning/phases/97-redesign-dashboard-mercado-livre/deferred-items.md
- FOUND: commit d114a13
