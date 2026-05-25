---
phase: 13-reestruturacao-cadastro-empresas
plan: 02
subsystem: database
tags: [migrations, permissions, setor, tdd, laravel, retroativa, idempotente]

# Dependency graph
requires:
  - phase: 13-reestruturacao-cadastro-empresas
    plan: 01
    provides: "companies.status e mlb_empresas.company_id colunas criadas na Wave 1"
provides:
  - "Constante COMERCIAL_CADASTRAR_EMPRESA e grupo 'Comercial' no catálogo Permissions"
  - "Setor 'Comercial' criado em setores (slug='comercial', is_system=false)"
  - "setor_permissoes vinculada ao setor Comercial com permission_key='comercial.cadastrar_empresa'"
  - "37 mlb_empresas retroativamente vinculadas a companies via company_id FK"
  - "Migration idempotente: re-execução não cria duplicatas"
affects:
  - "13-reestruturacao-cadastro-empresas (waves 3-4)"
  - "ComercialController (wave 3 — usa setor Comercial e permission)"
  - "AppLayout.jsx (wave 3 — sidebar com item 'Comercial')"
  - "Sistema de setores — setor Comercial agora visível em /sistema/setores"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration de dados com derivação de service_type: lógica encapsulada em método privado da migration"
    - "Idempotência via whereNull('company_id') para migração retroativa"
    - "TDD RED→GREEN para migration de dados com require() direto no teste"

key-files:
  created:
    - database/migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php
  modified:
    - app/Support/Permissions.php
    - tests/Feature/Phase13MigrationTest.php

key-decisions:
  - "Setor Comercial criado com is_system=false (pode ser deletado, diferente de Administração)"
  - "derivarServiceType() encapsulado como método privado na migration — não exposto ao modelo"
  - "Testes chamam migration->up() diretamente via require() para testar comportamento em isolamento"
  - "down() remove apenas setor/permissão, NÃO desfaz migration retroativa (destrutivo e irreversível)"

patterns-established:
  - "Teste de migration de dados: inserir fixtures → require migration → assertar resultado"
  - "Derivação de service_type para mlb_empresas: POLO+POLOS→polos, POLO+ssessoria→assessoria, ASSESSORIA→assessoria, ncubadora→incubadora, fallback→polos"

requirements-completed:
  - COM-01
  - COM-10
  - COM-11

# Metrics
duration: 20min
completed: 2026-05-25
---

# Phase 13 Plan 02: Permissions + Setor Comercial + Migration Retroativa

**Permission 'comercial.cadastrar_empresa' adicionada ao catálogo, setor 'Comercial' criado via migration idempotente, e 37 mlb_empresas retroativamente vinculadas a companies com derivação automática de service_type.**

## Performance

- **Duration:** 20 min
- **Started:** 2026-05-25T14:10:00Z
- **Completed:** 2026-05-25T14:30:00Z
- **Tasks:** 2 (1 auto + 1 TDD)
- **Files modified:** 3

## Accomplishments

- `Permissions::all()` agora inclui `'comercial.cadastrar_empresa'` — grupo 'Comercial' adicionado ao `catalog()` após 'Notificações'
- Setor 'Comercial' criado em `setores` (slug='comercial', is_system=false) com permissão vinculada em `setor_permissoes`
- 37 mlb_empresas processadas pela migration retroativa: todas com `company_id` preenchido, `status='ativo'`
- Migration 100003 idempotente: re-execução ignora registros já processados via `whereNull('company_id')`
- Phase13MigrationTest: 17 testes GREEN (9 da Wave 1 + 8 novos da Wave 2)

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Permissions.php** - `7fe9ef7` (feat)
2. **RED (testes Wave 2):** `c3a648d` (test)
3. **GREEN (migration 100003):** `b11c235` (feat)

_TDD: commit test antes da implementação (RED→GREEN)_

## Files Created/Modified

- `app/Support/Permissions.php` — Constante `COMERCIAL_CADASTRAR_EMPRESA` + grupo 'Comercial' em `catalog()`; NÃO adicionado em `AUTO_LIDERANCA`
- `database/migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php` — 3 etapas: setor, setor_permissoes, migração retroativa; método privado `derivarServiceType()`
- `tests/Feature/Phase13MigrationTest.php` — 8 novos testes cobrindo setor, setor_permissoes, retroativa, idempotência, derivação service_type, status='ativo'

## Decisions Made

- **Setor Comercial com `is_system=false`**: Diferente de 'Administração', o setor Comercial pode ser deletado se necessário. Segue a convenção do sistema para setores funcionais não-essenciais ao core.
- **`derivarServiceType()` como método privado da migration**: A lógica de derivação não pertence a nenhum model existente — encapsulada na migration garante coesão e facilita teste isolado.
- **`down()` não desfaz retroativa**: Reverter a criação de 37 companies seria destrutivo e poderia causar perda de dados em ambiente de produção. O `down()` remove apenas o setor e a permissão.
- **Testes chamam `up()` diretamente via `require()`**: Permite testar comportamento da migration sem depender do ciclo completo do Artisan, o que é mais rápido e determinístico em testes de feature.

## Deviations from Plan

None — plano executado exatamente como escrito.

## Issues Encountered

Nenhum problema. Todos os 17 testes passaram na primeira execução após criação da migration.

## User Setup Required

Nenhum — sem configuração externa necessária.

## Next Phase Readiness

- Setor 'Comercial' disponível em `/sistema/setores` — membros podem ser adicionados imediatamente
- Permission `comercial.cadastrar_empresa` disponível no catálogo — waves 3-4 podem usar `abort_unless($user->hasPermission('comercial.cadastrar_empresa'))`
- Todos os 37 mlb_empresas têm `company_id` preenchido — queries de fechamento que fazem JOIN com companies não encontrarão NULL
- Wave 3 pode prosseguir: `ComercialController`, rotas `/comercial/*`, e item 'Comercial' no sidebar

---
*Phase: 13-reestruturacao-cadastro-empresas*
*Completed: 2026-05-25*

## Self-Check: PASSED

- [x] `app/Support/Permissions.php` existe e contém `COMERCIAL_CADASTRAR_EMPRESA`
- [x] `database/migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php` existe em disco
- [x] `tests/Feature/Phase13MigrationTest.php` existe e tem 17 testes
- [x] Commit `7fe9ef7` existe (feat: Permissions)
- [x] Commit `c3a648d` existe (test: RED)
- [x] Commit `b11c235` existe (feat: GREEN migration)
- [x] `artisan test --filter=Phase13MigrationTest` → 17/17 PASSED
- [x] `artisan migrate:status` → migration 100003 mostrada como "Ran"
- [x] `Permissions::all()` inclui `'comercial.cadastrar_empresa'` → bool(true)
- [x] `DB::table('mlb_empresas')->whereNull('company_id')->count()` → int(0)
- [x] `DB::table('setores')->where('slug','comercial')->exists()` → bool(true)
- [x] `DB::table('setor_permissoes')->where('permission_key','comercial.cadastrar_empresa')->exists()` → bool(true)
- [x] AUTO_LIDERANCA não contém `COMERCIAL_CADASTRAR_EMPRESA`
