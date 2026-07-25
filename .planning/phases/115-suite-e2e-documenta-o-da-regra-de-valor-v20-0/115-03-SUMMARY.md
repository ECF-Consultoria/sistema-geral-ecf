---
phase: 115-suite-e2e-documenta-o-da-regra-de-valor-v20-0
plan: 03
subsystem: docs
tags: [hubspot, documentacao, valor-operacional, mrr, arr]

# Dependency graph
requires:
  - phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo
    provides: HubspotValueResolver (regra de valor mensal x anual) e as colunas hubspot_valor_* em contratos_servico
provides:
  - "docs/hubspot-regra-de-valor.md — documentação técnica curta e sem jargão da regra mensal x anual do HubspotValueResolver"
affects: [115-suite-e2e-documenta-o-da-regra-de-valor-v20-0, milestone-v20.0-encerramento]

# Tech tracking
tech-stack:
  added: []
  patterns: []

key-files:
  created: [docs/hubspot-regra-de-valor.md]
  modified: []

key-decisions:
  - "Doc técnico dedicado em docs/hubspot-regra-de-valor.md (em vez de CLAUDE.md) para evitar churn de arquivo compartilhado entre sessões paralelas"

patterns-established: []

requirements-completed: [HUB-DOC-01]

# Metrics
duration: ~10min
completed: 2026-07-25
---

# Phase 115 Plan 03: Documentação da regra de valor HubSpot Summary

**Documento técnico `docs/hubspot-regra-de-valor.md` explicando, sem jargão não-explicado, como o `HubspotValueResolver` normaliza valores mensal x anual (P1Y), MRR x ARR, serviço único x mensal, inferência por tolerância de 5% e a marca `valor_revisar`.**

## Performance

- **Duration:** ~10 min
- **Tasks:** 1 completada
- **Files modified:** 1 (criado)

## Accomplishments
- Documentação fiel ao comportamento real de `HubspotValueResolver::resolve()` — nenhuma regra inventada, apenas descrição dos ramos existentes (com/sem line item, serviço único/mensal, hs_mrr, tolerância de 5%).
- Explicação em pt-BR das siglas MRR/ARR e do formato ISO-8601 `P1Y` na primeira menção, seguindo a convenção do projeto de nunca deixar jargão sem explicação.
- Tabela de mapeamento das 8 chaves de saída do resolver para as colunas de auditoria em `contratos_servico` (`hubspot_valor_original`, `hubspot_valor_normalizado_mensal`, `hubspot_valor_confidence`, `hubspot_valor_warning`, `hubspot_billing_frequency`).
- Fecha o último critério de aceite pendente da milestone v20.0 (HUB-DOC-01).

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Escrever docs/hubspot-regra-de-valor.md** - `16440f17` (docs)

**Plan metadata:** (a ser adicionado no commit final de fechamento)

## Files Created/Modified
- `docs/hubspot-regra-de-valor.md` - Documentação da regra de valor operacional mensal x anual do handoff Comercial HubSpot (7 subseções, 125 linhas)

## Decisions Made
- Doc técnico dedicado (não CLAUDE.md) — conforme já decidido no próprio PLAN.md, para evitar churn em arquivo compartilhado entre sessões paralelas na mesma árvore de trabalho.

## Deviations from Plan

None - plan executado exatamente como escrito.

## Issues Encountered

None.

## User Setup Required

None - documentação apenas, nenhuma configuração externa necessária.

## Next Phase Readiness

- HUB-DOC-01 entregue — todos os critérios de aceite da Fase 115 e da milestone v20.0 estão cobertos (pendente apenas checkpoint visual humano de fases anteriores, se ainda em aberto).
- Nenhum bloqueio para o encerramento da milestone v20.0.

## Self-Check: PASSED

- FOUND: docs/hubspot-regra-de-valor.md
- FOUND commit 16440f17 (git log --oneline --all)
- 7/7 subseções `## ` presentes; termos `valor_revisar`, `P1Y`, `hubspot_valor_original` presentes.

---
*Phase: 115-suite-e2e-documenta-o-da-regra-de-valor-v20-0*
*Completed: 2026-07-25*
