---
phase: 17-coleta-de-dados-ml
plan: 04
subsystem: api
tags: [laravel, controller, routes, permissions, inertia, queue-dispatch, rbac]

requires:
  - phase: 17-02
    provides: MlbColeta model + MlbColetaJob (dispatch)
provides:
  - Permission mlb.coleta no catálogo Publicações (MLB)
  - Actions coletaIndex/coletaStore/coletaShow/coletaStatus no MlbController
  - Rotas nomeadas mlb.coleta.index|store|show|status
  - Endpoint JSON de status com timeout (rodando>10min → erro)
affects: [17-05-coleta-page]

tech-stack:
  added: []
  patterns:
    - "Gating por checkPubAccess('coleta') reutilizado (sem middleware novo)"
    - "Action store valida inline + dispatch de Job + redirect (padrão Inertia)"
    - "Endpoint de status JSON para polling (análogo a grants.sync.status)"

key-files:
  created:
    - tests/Feature/Phase17ColetaTest.php
  modified:
    - app/Support/Permissions.php
    - app/Http/Controllers/MlbController.php
    - routes/web.php

key-decisions:
  - "Visibilidade compartilhada do histórico dentro do módulo Publicação (RESEARCH Q2)"
  - "Helpers privados listarColetas()/duracaoColeta() para reuso entre index e show"

patterns-established:
  - "Página Inertia única 'Mlb/Coleta' renderizada por index (coleta=null) e show (coleta selecionada)"

requirements-completed: [D-06, D-07]

duration: ~10min (inline)
completed: 2026-06-02
---

# Phase 17 / Plan 04: HTTP da Coleta (controller + rotas + permission) Summary

**Permission mlb.coleta + 4 actions no MlbController + rotas mlb.coleta.* expondo formulário, dispatch do Job, relatório e status JSON de polling, com acesso restrito ao módulo Publicação.**

## Performance

- **Duration:** ~10 min (execução inline sequencial)
- **Completed:** 2026-06-02
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- `MLB_COLETA = 'mlb.coleta'` registrada no catálogo (grupo Publicações (MLB), label "Pub · Int. Anúncios")
- 4 actions com gating `checkPubAccess('coleta')`: `coletaIndex`, `coletaStore` (valida + dispatch), `coletaShow`, `coletaStatus` (timeout 10min)
- Rotas nomeadas `mlb.coleta.index|store|show|status` no grupo `mlb` (verificadas em route:list)
- Suíte `Phase17ColetaTest` 3/3 verde (store pendente, status JSON, 403 sem permissão)
- Suíte completa `--group phase17`: 11/11 verde (23 asserts)

## Task Commits

1. **Task 1: Permission + actions + rotas** - `(feat commit)` (feat)
2. **Task 2: Phase17ColetaTest (Feature)** - `(test commit)` (test)

## Files Created/Modified
- `app/Support/Permissions.php` - Nova key `MLB_COLETA` + entrada de catálogo
- `app/Http/Controllers/MlbController.php` - 4 actions + helpers `listarColetas`/`duracaoColeta` + imports
- `routes/web.php` - 4 rotas `mlb.coleta.*` no grupo mlb
- `tests/Feature/Phase17ColetaTest.php` - Cobertura D-06/D-07

## Decisions Made
- Página Inertia única `Mlb/Coleta` servida por index (lista, `coleta=null`) e show (lista + coleta selecionada) — simplifica o frontend (17-05).
- Adicionados helpers privados em vez do `formatarDuracao` inexistente no controller.

## Deviations from Plan
None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
**Atribuição de permissão:** para usuários não-admin verem a feature, conceder a key `mlb.coleta` ao setor Publicação via tela de Setores. Admin já vê tudo.

## Next Phase Readiness
- Backend HTTP completo: a página `Mlb/Coleta.jsx` (Plan 17-05) pode consumir `coletas`, `coleta` e o endpoint `mlb.coleta.status` para o polling.

---
*Phase: 17-coleta-de-dados-ml*
*Completed: 2026-06-02*
