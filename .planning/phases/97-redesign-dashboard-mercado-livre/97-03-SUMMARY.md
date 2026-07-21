---
phase: 97-redesign-dashboard-mercado-livre
plan: 03
subsystem: ui
tags: [inertia, react, dashboard, filtros, kpi, marketplace, ziggy]

# Dependency graph
requires:
  - phase: 97-01
    provides: "stats.total_revenue_delta_pct, stats.avg_margin_delta_pp, stats.novas_empresas_count, avg_margin ponderado, filters.marketplace, dashboard_route_name"
  - phase: 97-02
    provides: "nps_ruins (respostas nota baixa do recorte, sem invalidadas), novas_empresas, performance_equipe/nps_pendentes respeitando o recorte"
provides:
  - "FiltrosDashboard.jsx — componente reutilizável de painel de filtros (rascunho→aplicar, chips, colapsável)"
  - "Admin.jsx com applyFilters corrigido (navega sempre pela rota corrente + preserva marketplace)"
  - "4 KPIs (Faturamento, Margem, NPS, Empresas ativas) com delta vs. período anterior + link Ziggy"
  - "DashboardController::getPeriodRange aceita períodos 60/90 (além de 1/7/30/180 legado)"
affects: [97-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Padrão rascunho→aplicar em filtros: estado local `draft` isolado do recorte `applied` vindo do backend; useEffect ressincroniza draft quando applied muda por fora (navegação concluída, chip removido)"
    - "Cor/seta/label de delta computados DENTRO do callback do .map() ao renderizar listas (pitfall Rollup) — nunca herdados de variável de escopo externo"
    - "Navegação de filtro sempre via nome de rota corrente (dashboard_route_name do backend), nunca rota fixa — preserva contexto de marketplace/menu ativo"

key-files:
  created:
    - resources/js/Components/Dashboard/FiltrosDashboard.jsx
  modified:
    - resources/js/Pages/Dashboard/Admin.jsx
    - app/Http/Controllers/DashboardController.php

key-decisions:
  - "Tasks 1+2 commitadas juntas (mesmo arquivo Admin.jsx, mesmo componente, mesmo padrão do Plan 97-01) — separar geraria commits artificiais no mesmo hunk"
  - "Margem contrib. média linka para performance.index (não há rota dedicada de 'relatório de margem'; a Área da equipe já expõe margem por profissional/carteira)"
  - "getPeriodRange ganhou suporte a '60'/'90' (Rule 1 - bug): sem isso os novos filtros de período do mockup cairiam silenciosamente no default 30 dias; '1'/'180' preservados por compat com links antigos que não passam mais pela UI nova"

requirements-completed: [DASH-97-1, DASH-97-2, DASH-97-3]

# Metrics
duration: ~50min
completed: 2026-07-21
---

# Phase 97 Plan 03: Frontend do Redesign da Dashboard Mercado Livre Summary

**`FiltrosDashboard.jsx` novo (rascunho→aplicar + chips + colapsável) substitui a barra de filtros antiga do `Dashboard/Admin.jsx`, corrige o bug do marketplace sumindo na navegação, e os 4 KPIs do topo ganham delta vs. período anterior + link Ziggy para a área completa.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-07-20T19:30:00Z (aprox.)
- **Completed:** 2026-07-21T (aprox.)
- **Tasks:** 2/2 completos
- **Files modified:** 3 (1 componente novo, 1 página, 1 controller)

## Accomplishments
- `FiltrosDashboard.jsx` — painel colapsável com botão "Filtros" (contador de chips), padrão RASCUNHO→APLICAR (mudar um select só altera estado local; botão "Aplicar" destaca em amarelo quando há mudança pendente), chips do recorte ativo removíveis individualmente + "Limpar tudo", estado vazio "Nenhum — mostrando todo o setor (últimos 30 dias)". Cross-filter analista↔estrategista via `combinacoes` preservado (movido do `Admin.jsx` original, agora lendo o rascunho).
- **BUG do marketplace corrigido (DASH-97-2):** `applyFilter` usava `route('dashboard')` fixo — filtrar em `/dashboard/mercadolivre` caía na dashboard genérica e perdia o recorte `marketplace=meli`. Agora `applyFilters` navega sempre por `dashboard_route_name` (prop do Plan 97-01, refletindo a rota Inertia corrente) e reenvia `filters.marketplace` explicitamente — nunca inventado pelo front, sempre o valor já validado pelo backend (mitigação da threat T-97-03-01).
- **4 KPIs redesenhados (DASH-97-3):** Faturamento total (delta % vs. período anterior), Margem contrib. média (delta em pp, ponderada por faturamento), NPS médio ("N respostas · M ruins", M vindo de `nps_ruins.length` do Plan 97-02), Empresas ativas ("+N novas no mês"). Cada card tem link↗ Ziggy para a área completa (`companies.index`, `performance.index`, `nps.index`). Deltas com cor por sinal (verde/vermelho) e "sem base" quando `null`. Modo TV preservado sem nenhuma mudança (grid de 5 KPIs antigo mantido lá).
- Correção auto-aplicada no backend: `DashboardController::getPeriodRange` passou a aceitar `'60'` e `'90'` dias (o painel novo oferece 7/30/60/90 conforme o mockup do usuário) — sem isso, selecionar "Últimos 60 dias" silenciosamente devolvia 30 dias.

## Task Commits

Tasks 1 e 2 foram commitadas juntas (mesmo arquivo `Admin.jsx`, mesmo componente, mesmíssimo padrão adotado no Plan 97-01 para tasks fortemente entrelaçadas):

1. **Task 1 + Task 2: FiltrosDashboard + fix marketplace + 4 KPIs com delta/link** - `30724cb` (feat)
2. **Deviation (Rule 1 - bug): getPeriodRange aceita 60/90** - `bef0ebc` (fix)

**Plan metadata:** (a ser commitado nesta etapa — docs: complete plan)

## Files Created/Modified
- `resources/js/Components/Dashboard/FiltrosDashboard.jsx` (novo) - painel de filtros rascunho→aplicar + chips + colapsável; exporta `PERIOD_OPTIONS` (7/30/60/90) reutilizado pelo `Admin.jsx`.
- `resources/js/Pages/Dashboard/Admin.jsx` - `applyFilter` (bug) substituído por `applyFilters` (navega por `dashboard_route_name` + preserva `marketplace`); barra de filtros antiga (`ECFSelect` inline) removida em favor de `<FiltrosDashboard>`; cross-filter movido para o componente; grid de 5 KPIs antigo (modo normal) substituído por 4 KPIs com delta/link; `nps_ruins`/`dashboard_route_name` adicionados à assinatura de props.
- `app/Http/Controllers/DashboardController.php` - `getPeriodRange` ganhou os cases `'60' => 60` e `'90' => 90`.

## Decisions Made
- Margem contrib. média linka para `performance.index` (Área da equipe já expõe margem por profissional/carteira); não existe rota dedicada de "relatório de margem" no sistema — decisão de reaproveitar a rota mais próxima em vez de criar uma nova (fora do escopo desta fase, D2 do CONTEXT restringe o escopo ao `Dashboard/Admin.jsx`).
- Tasks 1+2 commitadas num único commit atômico — ambas mexem no mesmo `Admin.jsx`/mesmo componente; separar exigiria hunks artificiais no mesmo bloco de código.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `getPeriodRange` não reconhecia os períodos 60/90 do novo painel de filtros**
- **Found during:** Task 1 (implementação do `FiltrosDashboard.jsx` com `PERIOD_OPTIONS` 7/30/60/90 conforme mockup/CONTEXT)
- **Issue:** O backend (`DashboardController::getPeriodRange`) só reconhecia `1`/`7`/`30`/`180`; qualquer outro valor caía no `default => 30`. Selecionar "Últimos 60 dias" ou "Últimos 90 dias" no novo painel resultaria em receber silenciosamente 30 dias de dados — bug de correção (o usuário veria o rótulo "60 dias" mas os números seriam de 30 dias).
- **Fix:** Adicionados os cases `'60' => 60` e `'90' => 90` ao match. Os fallbacks genéricos por dias (não específicos de `period === '30'`) já cobriam qualquer contagem de dias corretamente — mudança aditiva e segura, sem impacto nos ramos de cache híbrido (que só têm tratamento especial para `period === '30'`, os demais já usam fallback DB completo independente do valor).
- **Files modified:** `app/Http/Controllers/DashboardController.php`
- **Verification:** `php artisan test --filter=Dashboard` — 65 passed (só a falha pré-existente e documentada de `PublicacaoDesempenhoRouteTest`, sem relação com este plano).
- **Committed in:** `bef0ebc`

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Correção necessária para os filtros de período do redesign funcionarem sem silenciosamente devolver dado errado. Não altera nenhum comportamento pré-existente (`1`/`180` continuam funcionando por compat com links antigos).

## Issues Encountered
Nenhum além do já documentado nas Deviations.

## User Setup Required
None - nenhuma configuração externa necessária.

## Next Phase Readiness
- `Admin.jsx` está pronto para o Plan 97-04 (gráfico com abas Faturamento/Margem, "NPS ruim", "Score da equipe", "Novas empresas no mês") consumir os mesmos props (`margin_chart`, `nps_ruins`, `novas_empresas`) sem mudança adicional de backend.
- `FiltrosDashboard.jsx` é reutilizável — qualquer widget adicionado no Plan 97-04 já herda o recorte aplicado (`filters`/`period`) via as mesmas props do `Admin.jsx`.
- Bug do marketplace resolvido na base (Plan 97-01) e no consumo (este plano) — `/dashboard/mercadolivre` preserva o recorte ao longo de toda a jornada de filtro.
- `/dashboard` genérica não regrediu — `dashboard_route_name` default `'dashboard'` e `filters.marketplace` `undefined` mantêm o comportamento antigo intacto (confirmado por `php artisan test --filter=Dashboard`).

---
*Phase: 97-redesign-dashboard-mercado-livre*
*Completed: 2026-07-21*

## Self-Check: PASSED

- FOUND: resources/js/Components/Dashboard/FiltrosDashboard.jsx
- FOUND: resources/js/Pages/Dashboard/Admin.jsx
- FOUND: app/Http/Controllers/DashboardController.php
- FOUND: commit 30724cb
- FOUND: commit bef0ebc
