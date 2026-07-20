---
phase: 101-admanmetricdiffservice-v18-0
plan: 02
subsystem: api
tags: [laravel, adman-api, raw_data, tdd, live-read]

# Dependency graph
requires:
  - phase: 101-01
    provides: "App\\Services\\Metrics\\AdmanMetricDiffService — núcleo (compute()) live-read do diff de período"
provides:
  - "AdmanMetricDiffService::lerDiffDiarioRawData(AdmanMetric) — leitura auxiliar do diff DIÁRIO persistido em raw_data, com scope='daily' e guard anti-confusão"
affects: [102-bonus-por-competencia, 103-carteira-por-periodo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guard anti-confusão por shape de retorno: chaves/valores exclusivos de um contrato (diff_source/adman_diff) nunca aparecem no shape do outro (scope='daily')"
    - "Fail-open com ?? em todo acesso aninhado a JSON histórico potencialmente malformado"

key-files:
  created:
    - tests/Feature/V18/AdmanMetricDiffBackfillTest.php
  modified:
    - app/Services/Metrics/AdmanMetricDiffService.php

key-decisions:
  - "ADM-04 REFRAMADO: não há backfill de coluna (Fase 101 é live-read, sem colunas — decisão travada). raw_data.*.diff é sempre diff DIÁRIO (dia vs dia anterior, resultado de fetchPerformance com dateFrom=dateTo), nunca diff de período — não pode alimentar o diff do bônus."
  - "lerDiffDiarioRawData é FISICAMENTE distinto de compute(): método público separado, shape de retorno sem 'diff_source', com 'scope'=>'daily' explícito — guard estrutural contra o Pitfall 1 do research (confundir diff diário com diff de período)."
  - "percentageMargin_diff é hardcoded null (nunca deriva de leitura): percentageMargin nunca esteve em raw_data, só em /accounts/{custId}/metrics."

requirements-completed: [ADM-04]

# Metrics
duration: 12min
completed: 2026-07-20
---

# Phase 101 Plan 02: Helper de diff DIÁRIO do raw_data (ADM-04 reframado) Summary

**`AdmanMetricDiffService::lerDiffDiarioRawData()` lê o diff DIÁRIO já persistido em `AdmanMetric.raw_data` (grossBilling/profitMargin), marcado `scope='daily'`, com guard estrutural que impede confusão com o diff de PERÍODO de `compute()`.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-07-20T15:26:00-03:00
- **Completed:** 2026-07-20T15:38:00-03:00
- **Tasks:** 1
- **Files modified:** 2 (1 criado, 1 modificado)

## Accomplishments
- `AdmanMetricDiffService::lerDiffDiarioRawData(AdmanMetric $metric): array` — lê `raw_data.summarizedData.{grossBilling,profitMargin}.diff` com fail-open total (`?? null` em cada nível de acesso)
- Guard anti-confusão comprovado por teste: o retorno NUNCA contém a chave `diff_source` nem o valor `'adman_diff'` — não pode ser confundido/reaproveitado como diff de período pela Fase 102
- `percentageMargin_diff` sempre `null` — documentado inline e testado explicitamente (nunca esteve em `raw_data`, só em `/accounts/{custId}/metrics`)
- 5 testes novos verdes: fixture real, `raw_data` ausente, `raw_data` malformado, invariante do guard, `percentageMargin_diff` sempre null
- Regressão completa: `--filter=Adman` → 100 testes passando (439 assertions), incluindo os 8 testes do 101-01 intactos

## Task Commits

TDD RED → GREEN:

1. **Task 1 RED: teste falhando para `lerDiffDiarioRawData`** - `548b661` (test) — 5 falhas confirmadas por `Call to undefined method`
2. **Task 1 GREEN: implementação do helper** - `76c0d6e` (feat) — 5/5 testes verdes

**Plan metadata:** commit deste SUMMARY (a seguir)

## Files Created/Modified
- `tests/Feature/V18/AdmanMetricDiffBackfillTest.php` — novo; 5 testes com fixture real de `raw_data` diário do research (`2026-07-18` vs `2026-07-17`)
- `app/Services/Metrics/AdmanMetricDiffService.php` — método público `lerDiffDiarioRawData()` adicionado após `diffPctGuardado()`, com docblock extenso em pt-BR explicando o reframe do ADM-04 e a proibição de reuso como diff de período

## Decisions Made

**DESVIO CONSCIENTE do texto original do ADM-04 (obrigatório documentar, per plano):**

O texto original do REQ pedia "backfill de `raw_data` antigo para preencher os novos campos de diff". O research (101-RESEARCH.md) provou empiricamente que isso é um equívoco:

- **Não há backfill de colunas** porque a Fase 101 é live-read, sem colunas novas em `adman_metrics` — decisão travada desde o Plan 01.
- **`raw_data.*.diff` é sempre DIÁRIO** (dia vs dia anterior): `raw_data` é sempre o resultado persistido de `fetchPerformance($custId, $date, $date)` com `dateFrom=dateTo` (dia único). Logo o `.diff` embutido nele é sempre "esse dia vs o dia anterior" — NUNCA "esse período vs o período anterior". Reaproveitar esse valor como diff de janela do bônus seria o Pitfall 1 do research.
- **`percentageMargin` nunca esteve em `raw_data`** — só existe no endpoint `/accounts/{custId}/metrics` (já coberto por `fetchAccountMetricsDetailedCached` do Plan 01).
- O helper `lerDiffDiarioRawData()` existe **só para auditoria/metadados de fato diário**, com `scope='daily'` explícito. O diff de PERÍODO (consumido pelo bônus) segue exclusivamente ao vivo por `AdmanMetricDiffService::compute()` (Plan 01) — os dois métodos são fisicamente distintos e o shape de retorno de um nunca é confundível com o do outro (guard testado).

## Deviations from Plan

None (além do desvio consciente do ADM-04 já documentado acima, que é o próprio objetivo do plano). Plano executado exatamente como escrito, incluindo o fixture real do research e o guard de invariante obrigatório.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Fase 101 (Plans 01 + 02) está completa: `AdmanMetricDiffService::compute()` (diff de período, live-read) + `lerDiffDiarioRawData()` (diff diário auxiliar, guardado contra confusão) prontos para a Fase 102.
- A Fase 102 (BON-03, bônus por competência) deve consumir `compute()` para o diff de período do bônus e REMOVER a duplicação de guards do `DesempenhoScoreService` documentada no 101-01-SUMMARY.md ("Duplicação temporária intencional").
- `lerDiffDiarioRawData()` fica disponível para telas de auditoria/diagnóstico que precisem mostrar variação dia-a-dia, sem risco de vazar para o cálculo do bônus.
- Validação de produção pendente da Assumption A2 (janela de 30 dias) segue aberta desde o Plan 01 — não bloqueia este plano.

---
*Phase: 101-admanmetricdiffservice-v18-0*
*Completed: 2026-07-20*
