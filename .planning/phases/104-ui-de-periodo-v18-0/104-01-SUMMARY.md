---
phase: 104
plan: 104-01
subsystem: backend · payload de período/bônus (ranking + carteira)
tags: [performance, portfolio, metric-period-resolver, v18.0]
dependency-graph:
  requires: [MetricPeriodResolver (Fase 100), CAR-01/CAR-02/CAR-03 (Fase 103)]
  provides: [UIP-02, UIP-03 — payload periodo/bonus no ranking e na carteira; ?modo=bonus_atual simétrico]
  affects: [104-02 (frontend — Performance/Index.jsx, Portfolio/AdminCarteira.jsx, Portfolio/Carteiras.jsx)]
tech-stack:
  added: []
  patterns:
    - "MetricPeriodResolver::resolve() chamado 1x nível de página, direto no controller — nunca lido do sub-array de compute() (só 6 chaves)"
    - "?modo= é convenience de UI: só decide QUAL mês vira $mesReferencia/$mesSelecionado, nunca bifurca score (DESEMP-02)"
key-files:
  created:
    - tests/Feature/V18/PeriodoBonusPayloadTest.php
  modified:
    - app/Http/Controllers/PerformanceController.php
    - app/Http/Controllers/PortfolioController.php
decisions:
  - "bonus.competence_month/payment_month para ?mes=YYYY-MM específico (modo closed_period) é DERIVADO pelo controller (mês selecionado + mês seguinte), já que o resolver só popula bonus_* nativamente em last_closed_month — decisão explícita do plano (Task 1 item 3), não um desvio"
metrics:
  duration: "~25min"
  completed: 2026-07-21
---

# Phase 104 Plan 01: Backend — payload periodo/bonus + preset Bônus atual Summary

Ranking `/performance` e as duas telas de carteira (`PortfolioController`) passam a expor `periodo` (shape completo do `MetricPeriodResolver`, 14 chaves) e `bonus` (competence/payment quando fechado) no payload Inertia, com `?modo=bonus_atual` reconhecido simetricamente nas 3 telas para resolver o último mês fechado.

## O que foi feito

### Task 1 — `PerformanceController::index()` (commit `3e91479`)
- Injetado `MetricPeriodResolver` no construtor.
- `?modo=em_curso|bonus_atual` (whitelist; qualquer outro valor cai em `null`/comportamento atual) decide qual mês vira `$mesReferencia`:
  - `bonus_atual` → `resolve(['period_key' => 'last_closed_month'])`, usa `bonus_competence_month` como referência.
  - `?mes=YYYY-MM` (sem `modo` ou `modo=em_curso`) → comportamento preservado (mês fechado específico).
  - ausência → mês corrente (comportamento preservado).
- `$periodoResolvido` é resolvido **uma única vez** no ponto de decisão acima (não recalculado depois) e passado ao payload como `periodo`.
- `$bonusMeta` = `{competence_month, payment_month}` quando `$periodoResolvido['is_closed']`; `null` no mês em curso. Para `last_closed_month` usa os campos nativos do resolver; para `closed_period` (`?mes=`) deriva do mês selecionado + mês seguinte (resolver não popula `bonus_*` nesse modo — plano explicitou essa regra).
- `Inertia::render('Performance/Index', [...])` (linhas ~250-262, **não** a 563 do `dashboardCarteira`) ganhou as chaves `periodo` e `bonus`.

### Task 2 — `PortfolioController` (commit `c78cef0`)
- `renderCarteiraProfissional()` (carteira individual) e `renderCarteirasConsolidadas()` (carteira consolidada) reconhecem `?modo=bonus_atual` com a mesma whitelist/regra da Task 1, reusando `$this->periodResolver` já injetado (Fase 103).
- Ambas ganharam bloco `bonus` no payload (`null` em curso; `{competence_month, payment_month}` em período fechado), mesma lógica de derivação da Task 1.
- Nenhuma mudança em cálculo/elegibilidade/dedup — só a resolução de período e o bloco `bonus`.

## Verificação

```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/V18/PeriodoBonusPayloadTest.php tests/Feature/V18/CarteiraPeriodoDiffTest.php tests/Feature/V18/CarteiraConsolidadaPeriodoTest.php tests/Feature/V16/PerformanceIndexMetadadosTest.php
```
Resultado: **OK (21 tests, 155 assertions)** — 7 testes novos (`PeriodoBonusPayloadTest`) + 14 de regressão (Fase 103 carteira + Fase 92 ranking), todos verdes.

Baseline pré-mudança (mesmos 3 arquivos de regressão, sem o teste novo): 14/14 já passavam — confirma que as mudanças desta fase não quebraram nada preexistente.

RED confirmado antes do GREEN: rodada inicial de `PeriodoBonusPayloadTest.php` (7 testes) resultou em 3 failures + 4 errors (chaves `periodo`/`bonus` ausentes, `?modo=bonus_atual` ignorado) — prova de que os testes realmente exercitavam comportamento ainda não implementado.

## Deviations from Plan

None — plano executado exatamente como escrito (já incorporava os 2 blockers do plan-checker: linha certa no `index()` do ranking, e `?modo=bonus_atual` simétrico nas 3 telas).

## Fronteira respeitada

Não tocados: `DesempenhoScoreService`, `MetricPeriodResolver` (só consumido), `AdmanMetricDiffService`, `CarteiraContextService`, qualquer `.jsx` (Wave 2 / 104-02), `dashboardCarteira()` (linha 563, fora de escopo), NPS/Dashboard (sessão paralela).

## Sessão paralela

Durante a execução, o working tree tinha alterações não commitadas de outra sessão (`resources/js/Pages/Dashboard/Admin.jsx`, `resources/js/Components/Dashboard/*.jsx`). Em um dos commits desta plan, esses arquivos apareceram STAGED (não por ação minha) — foram identificados via `git status --short` antes do commit, removidos do stage com `git restore --staged`, e o commit final incluiu **somente** `app/Http/Controllers/PortfolioController.php`. Confirmado via `git status --short` pós-commit que nenhum arquivo alheio foi commitado.

## Self-Check: PASSED

- `app/Http/Controllers/PerformanceController.php` — FOUND, contém `periodo`/`bonus` no payload do `index()`.
- `app/Http/Controllers/PortfolioController.php` — FOUND, contém `?modo=bonus_atual` + `bonus` nas 2 funções de carteira.
- `tests/Feature/V18/PeriodoBonusPayloadTest.php` — FOUND, 7 testes.
- Commit `3e91479` — FOUND em `git log`.
- Commit `c78cef0` — FOUND em `git log`.
- Nenhuma deleção acidental em nenhum dos 2 commits (`git diff --diff-filter=D` vazio nos dois).
