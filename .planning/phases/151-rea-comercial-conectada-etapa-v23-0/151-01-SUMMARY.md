---
phase: 151-rea-comercial-conectada-etapa-v23-0
plan: 01
subsystem: testing
tags: [phpunit, baseline, gate-clau, mariadb, companies]

# Dependency graph
requires:
  - phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
    provides: "150-BASELINE-TESTES.md como molde de estrutura e como baseline anterior de comparação"
provides:
  - "151-BASELINE-TESTES.md — registro escrito do estado verde/vermelho da suíte ANTES da migration de 3 colunas em companies (plano 151-03)"
  - "Regra de comparação de regressão para os planos 151-02 em diante (nunca contra zero)"
affects: [151-03, 138-verify-work]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Baseline de testes por escrito antes de migration em tabela com dado de produção (gate do CLAUDE.md)"

key-files:
  created:
    - .planning/phases/151-rea-comercial-conectada-etapa-v23-0/151-BASELINE-TESTES.md
  modified: []

key-decisions:
  - "Nenhuma divergência numérica tratada como regressão: crescimento de 285→307 testes na suíte Unit é atribuído ao crescimento orgânico da Fase 150 entre a baseline dela e hoje, não a este plano"

patterns-established:
  - "Toda suíte desta fase roda por diretório/arquivo com C:\\xampp\\php\\php.exe vendor/bin/phpunit — nunca php artisan test nem --testsuite=Feature inteiro"

requirements-completed: []

# Metrics
duration: ~6min
completed: 2026-09-02
---

# Phase 151 Plan 01: Baseline de testes pré-migration Summary

**Baseline escrita e verde registrada antes da migration de 3 colunas em `companies` (plano 151-03), confirmando que as ~10 falhas antigas de Polos e as 12 falhas antigas da suíte Unit seguem idênticas às da Fase 150 — nenhuma regressão nova.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-09-02T18:44:51Z (aprox., herdado do `last_updated` do STATE.md antes da execução)
- **Completed:** 2026-09-02T18:50:09Z
- **Tasks:** 2/2
- **Files modified:** 1 criado

## Accomplishments
- Medidas as 5 suítes que a Fase 151 encosta, cada uma com comando literal, resumo literal (`Tests`/`Assertions`/`Failures`/`Errors`) e exit code
- Confirmado que `admin.contratos.*` permanece sob `permission:admin.contratos` (D-15, regressão-alvo) e que a máquina de estados da Fase 150 está verde: 26/26, 60 assertions
- Confirmado que as duas suítes de Polos (`PolosControllerTest`, `PolosFaturamentoSnapshotTest`) mantêm exatamente os mesmos números da baseline da Fase 150 (10 falhas no total) — nomeadas para não virarem caça a fantasma em planos futuros
- Escrito `151-BASELINE-TESTES.md` com frontmatter (`sha_head` real), as três colunas futuras nomeadas (`hubspot_owner_id`, `hubspot_owner_nome`, `data_venda`) e a tabela "Regra de comparação"
- Confirmado que nenhum arquivo de `app/`, `database/` ou `resources/` foi tocado por este plano

## Task Commits

Task 1 (medição) não produz artefato de arquivo próprio — só números capturados para a Task 2 escrever. As duas tasks foram consolidadas em um único commit de documentação:

1. **Task 1+2: Medir suítes e escrever 151-BASELINE-TESTES.md** - `e44b1013` (docs)

**Plan metadata:** (a seguir, commit de STATE.md/ROADMAP.md desta execução)

## Files Created/Modified
- `.planning/phases/151-rea-comercial-conectada-etapa-v23-0/151-BASELINE-TESTES.md` - Baseline de testes "antes" da migration da D-09, com 5 comandos medidos, seção de falhas pré-existentes de Polos e tabela de regra de comparação

## Decisions Made
- O crescimento de 285→307 testes na suíte Unit (entre a baseline da Fase 150 e hoje) foi atribuído ao crescimento orgânico da própria Fase 150 (`tests/Unit/Phase150/*` nasceram depois daquela baseline), não registrado como anomalia — os 9 erros + 3 falhas pré-existentes são nominalmente idênticos aos já documentados

## Deviations from Plan

None - plano executado exatamente como escrito. As duas tasks foram medição (Task 1, sem artefato de arquivo próprio) e escrita (Task 2, produz o único arquivo do plano); commitadas juntas porque a Task 1 não altera nenhum arquivo rastreado pelo git.

## Issues Encountered
None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Gate do `CLAUDE.md` § "GSD obrigatório" cumprido para a migration do plano `151-03` (3 colunas aditivas em `companies`)
- `151-BASELINE-TESTES.md` pronto para servir de referência de regressão em todos os planos 151-02 em diante e no `/gsd:verify-work` da fase
- Nenhum bloqueio conhecido para o próximo plano da wave (151-02, `checkpoint:human-verify` da property HubSpot)

---
*Phase: 151-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-02*

## Self-Check: PASSED

- FOUND: `.planning/phases/151-rea-comercial-conectada-etapa-v23-0/151-BASELINE-TESTES.md`
- FOUND: `.planning/phases/151-rea-comercial-conectada-etapa-v23-0/151-01-SUMMARY.md`
- FOUND commit: `e44b1013`
