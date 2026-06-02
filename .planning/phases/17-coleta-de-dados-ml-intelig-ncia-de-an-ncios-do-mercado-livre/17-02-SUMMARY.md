---
phase: 17-coleta-de-dados-ml
plan: 02
subsystem: database
tags: [laravel, migration, eloquent, queue, job, mlb-coleta]

requires:
  - phase: 17-01
    provides: MlKeywordMinerService (injetado no Job para o pipeline)
provides:
  - Tabela mlb_coletas (keyword, status enum, resultado json, ciclo de vida)
  - Model MlbColeta (constantes STATUS_*, casts resultado=>array, datetimes)
  - MlbColetaJob assíncrono (status pendente→rodando→concluido/erro + failed())
  - Contrato executarPipeline(MlbColeta, MlKeywordMinerService): array para o Plan 17-03
affects: [17-03-coleta-service, 17-04-controller, 17-05-coleta-page]

tech-stack:
  added: []
  patterns:
    - "Job na queue database análogo a SyncMlCompanyJob (tries/timeout/backoff/failed)"
    - "Rastreamento de ciclo de vida via colunas status + started_at/finished_at + erro_mensagem"

key-files:
  created:
    - database/migrations/2026_06_01_120000_create_mlb_coletas_table.php
    - app/Models/MlbColeta.php
    - app/Jobs/MlbColetaJob.php
    - tests/Unit/MlbColetaJobTest.php
  modified: []

key-decisions:
  - "Interface-first: contrato executarPipeline definido neste plano e consumido sem o 17-03 existir ainda"
  - "timeout=300 (> SyncMlCompanyJob) por o pipeline ser mais longo; backoff [60,300], tries=2 (D-06, T-17-04)"

patterns-established:
  - "Job nunca loga app token (T-17-03) — apenas id/status/keyword com tag [MLB Coleta]"

requirements-completed: [D-06]

duration: ~6min (recuperado inline após stall de subagente)
completed: 2026-06-01
---

# Phase 17 / Plan 02: Fundação de Dados + MlbColetaJob Summary

**Persistência da coleta (tabela mlb_coletas + model MlbColeta) e orquestrador assíncrono MlbColetaJob com ciclo de status e hook failed() (D-06).**

## Performance

- **Duration:** ~6 min (finalização inline após stream-idle timeout do subagente paralelo)
- **Completed:** 2026-06-01
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- Migration `mlb_coletas` com enum de status, `resultado` json, `started_at`/`finished_at`, índices em keyword/status/created_at
- Model `MlbColeta` com constantes `STATUS_*` e casts (`resultado=>array`, datetimes)
- `MlbColetaJob` (ShouldQueue): `handle()` marca rodando → delega a `MlColetaService::executarPipeline` (contrato 17-03) → grava concluido/resultado; `failed()` grava erro
- Teste `test_failed_marca_erro` verde (RefreshDatabase)

## Task Commits

1. **Task 1: Migration + Model MlbColeta** - `1b7ad59` (feat)
2. **Task 2: MlbColetaJob + MlbColetaJobTest (D-06)** - `6582ea3` (feat)

## Files Created/Modified
- `database/migrations/2026_06_01_120000_create_mlb_coletas_table.php` - Schema mlb_coletas
- `app/Models/MlbColeta.php` - Model Eloquent (status + casts)
- `app/Jobs/MlbColetaJob.php` - Job assíncrono (orquestração + status + failed)
- `tests/Unit/MlbColetaJobTest.php` - Cobertura do failed() hook

## Decisions Made
- Interface-first conforme plano: `executarPipeline(MlbColeta, MlKeywordMinerService): array` é o contrato que o Plan 17-03 implementa.

## Deviations from Plan
None - plan executed exactly as written.

## Issues Encountered
- O subagente paralelo (worktree) só havia commitado a Task 1 antes do *stream idle timeout*; a Task 2 (Job + teste) estava escrita mas não commitada no worktree. O orquestrador resgatou os arquivos não-commitados, trouxe a Task 1 para `main` via cherry-pick, commitou a Task 2 atomicamente e validou `php artisan test --filter MlbColetaJobTest` (verde) antes de gerar este SUMMARY.

## User Setup Required
**Migração pendente no banco de dev:** rodar `php artisan migrate` no host XAMPP para criar a tabela `mlb_coletas` no banco de desenvolvimento (os testes usam RefreshDatabase e não dependem disso).

## Next Phase Readiness
- Contrato `executarPipeline` pronto para o `MlColetaService` (Plan 17-03) implementar.
- Job pronto para ser despachado pelo controller (Plan 17-04).

---
*Phase: 17-coleta-de-dados-ml*
*Completed: 2026-06-01*
