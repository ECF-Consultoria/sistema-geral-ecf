---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 01
subsystem: testes
tags: [baseline, regressao, portal-estrutura]
requires: []
provides:
  - 168-BASELINE-TESTES.md com G1..G8 medidos antes de qualquer código da fase
affects: [168-06, 168-16]
key-files:
  created:
    - .planning/phases/168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos/168-BASELINE-TESTES.md
  modified: []
key-decisions: []
requirements-completed: [PR168-12, PR168-13]
metrics:
  duration: ~6 min
  completed: 2026-10-07
---

# Phase 168 Plan 01: Baseline de testes Summary

Baseline de G1..G7 medida em HEAD `e14dbe65` e commitada isolada; G8 registrado como novo (0 testes).

## Resultados

| Grupo | Testes | Asserções | Exit |
|---|---|---|---|
| G1 | 246 | 1704 | 0 |
| G2 | 22 | 118 | 0 |
| G3 | 7 | 180 | 0 |
| G4 | 394 | 2877 | 0 |
| G5 | 17 | 92 | 0 |
| G6 (`test:js`) | 1123 (1121 passam) | n/d | 1 |
| G7 | 62 | 256 | 0 |

- Tudo idêntico à referência do fim da 167. G1 (246/1704) não tinha referência própria (ela estava dentro do G4).
- Autoloader confirmado: `EstruturaOferta` carrega de `C:\tmp\ecf-publicador-spec-261001\app\Models\`.
- G6: as 2 falhas são exatamente o piso antigo ("Características secundárias nasce recolhido" e "FASES_TERMINAIS").

## Commit

- `1582f483` docs(168): baseline de testes antes da fase (só o arquivo da baseline, conferido com `git show --stat`).

## Deviations from Plan

None - plano executado como escrito.

## Self-Check: PASSED
