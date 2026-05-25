---
phase: 13-reestruturacao-cadastro-empresas
plan: 01
subsystem: database
tags: [migrations, laravel, sqlite, mysql, schema, company, mlb_empresas, tdd]

# Dependency graph
requires: []
provides:
  - "companies.status coluna com default='ativo' e suporte a 'pendente'"
  - "Backfill status='ativo' para todos os registros existentes em companies"
  - "Rename service_type='polo'→'polos' no banco (D-12)"
  - "mlb_empresas.company_id FK nullable nullOnDelete para companies"
  - "AdminController.updateEmpresa() e updateFechamento() validam novos service_types"
  - "Financeiro.jsx exibe badges corretos para 'polos', 'publicidade', 'gestao'"
affects:
  - "13-reestruturacao-cadastro-empresas (waves 2-4)"
  - "ComercialController (wave 2 — usa company_id e status)"
  - "AdminController — validação service_type expandida"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration idempotente com ifNotExists para addColumn (evita erro em re-run)"
    - "FK nullable nullOnDelete: padrão 2026_05_20_100003 reutilizado para mlb_empresas"
    - "TDD RED→GREEN para testes de schema de migration"

key-files:
  created:
    - database/migrations/2026_05_25_100001_add_status_to_companies.php
    - database/migrations/2026_05_25_100002_add_company_id_to_mlb_empresas.php
    - tests/Feature/Phase13MigrationTest.php
  modified:
    - app/Models/Company.php
    - app/Models/MlbEmpresa.php
    - app/Http/Controllers/AdminController.php
    - resources/js/Pages/Admin/Financeiro.jsx

key-decisions:
  - "Migration 100001 usa ifNotExists para idempotência (não Schema::hasColumn no schema builder — mais explícito)"
  - "updateFechamento() também atualizado (segunda ocorrência do mesmo bug — Armadilha 1 do RESEARCH.md)"
  - "GraficoServico em Financeiro.jsx atualizado com cores Tailwind para publicidade (#f97316) e gestao (#06b6d4)"

patterns-established:
  - "FK nullable nullOnDelete: sempre usar dropForeign(['column']) no down() — não dropForeign('table_column_foreign')"
  - "Migration de backfill: sempre separar addColumn de UPDATE para clareza"

requirements-completed:
  - COM-10
  - COM-11

# Metrics
duration: 25min
completed: 2026-05-25
---

# Phase 13 Plan 01: Fundação de Schema — companies.status e mlb_empresas.company_id

**Duas migrations de fundação com TDD: adiciona companies.status (backfill='ativo', rename polo→polos), mlb_empresas.company_id (FK nullable nullOnDelete), e corrige simultaneamente validação AdminController e labels Financeiro.jsx para não quebrar o sistema pós-rename.**

## Performance

- **Duration:** 25 min
- **Started:** 2026-05-25T13:45:25Z
- **Completed:** 2026-05-25T14:10:00Z
- **Tasks:** 2 (TDD + fix)
- **Files modified:** 7

## Accomplishments

- Migration 100001 executada: `companies.status` existe, backfill `status='ativo'` aplicado, `service_type='polo'` renomeado para `'polos'`
- Migration 100002 executada: `mlb_empresas.company_id` FK nullable nullOnDelete para `companies`
- AdminController corrigido em dois pontos: `updateEmpresa()` e `updateFechamento()` agora aceitam `polos,assessoria,incubadora,publicidade,gestao`
- Financeiro.jsx atualizado: `SERVICE_LABELS`, `SERVICE_COLORS` e `GraficoServico` refletem `polos`, `publicidade`, `gestao`
- Phase13MigrationTest: 9 testes GREEN (cobrindo COM-10, COM-11 e comportamentos relacionados)
- `npm run build` executado sem erros

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **RED (test):** `555c527` — `test(13-01): adiciona testes RED da Phase 13 Migration`
2. **GREEN (migrations + models):** `6009958` — `feat(13-01): migrations companies.status e mlb_empresas.company_id + models`
3. **Tarefa 2 (AdminController + Financeiro.jsx):** `5ecad8f` — `fix(13-01): corrige validação service_type e labels polo→polos no frontend`

## Files Created/Modified

- `database/migrations/2026_05_25_100001_add_status_to_companies.php` — Adiciona `companies.status`, backfill `ativo`, rename `polo→polos`
- `database/migrations/2026_05_25_100002_add_company_id_to_mlb_empresas.php` — Adiciona `mlb_empresas.company_id` FK nullable nullOnDelete
- `tests/Feature/Phase13MigrationTest.php` — 9 testes de schema: colunas, backfill, FK nullOnDelete, fillable dos models
- `app/Models/Company.php` — `$fillable` += `status`; `$casts` += `status => string`; `getActivitylogOptions` inclui `status`
- `app/Models/MlbEmpresa.php` — `$fillable` += `company_id`
- `app/Http/Controllers/AdminController.php` — Validação `service_type` em `updateEmpresa()` e `updateFechamento()` expandida
- `resources/js/Pages/Admin/Financeiro.jsx` — `SERVICE_LABELS`, `SERVICE_COLORS`, `GraficoServico` com chaves `polos`, `publicidade`, `gestao`

## Decisions Made

- **Migration idempotente via `if (!Schema::hasColumn(...))` ao invés de `$table->string()->ifNotExists()`**: PHP-level check dá melhor mensagem de erro e é mais explícito que o modificador fluente do Blueprint.
- **`updateFechamento()` também corrigido**: O PLAN.md mencionava apenas `updateEmpresa()`, mas a leitura do arquivo revelou uma segunda ocorrência com o mesmo bug em `updateFechamento()`. Corrigida como Rule 1 (bug) — sem esta correção, o formulário de fechamento também retornaria 422 para empresas com `service_type='polos'`.
- **Cores distintas para novos service_types**: `publicidade` → laranja Tailwind (`#f97316`); `gestao` → ciano (`#06b6d4`). Coerente com o design system ecf-* e diferenciados dos existentes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Corrigido `updateFechamento()` além de `updateEmpresa()`**
- **Found during:** Tarefa 2 (leitura do AdminController completo via read_first)
- **Issue:** O PLAN.md especificava corrigir `updateEmpresa()` linha 53. A leitura do arquivo revelou que `updateFechamento()` linha 240 tinha a mesma validação `'nullable|in:polo,assessoria,incubadora'` — sem a correção, qualquer edição de empresa via tela de Fechamento retornaria 422 pós-migration.
- **Fix:** Atualizado `updateFechamento()` com os mesmos novos valores: `'nullable|in:polos,assessoria,incubadora,publicidade,gestao'`
- **Files modified:** `app/Http/Controllers/AdminController.php`
- **Verification:** `grep -n "in:polo," AdminController.php` retorna vazio
- **Committed in:** `5ecad8f`

---

**Total deviations:** 1 auto-fixed (Rule 1 - bug)
**Impact on plan:** Fix essencial para consistência. Sem ele, o formulário de Fechamento (`updateFechamento`) teria o mesmo bug que o de Empresas. Sem scope creep.

## Issues Encountered

Nenhum problema. Todas as verificações passaram na primeira execução.

## User Setup Required

Nenhum — sem configuração externa necessária.

## Next Phase Readiness

- Fundação de schema completa: `companies.status` e `mlb_empresas.company_id` prontos para uso nas waves seguintes
- AdminController aceita todos os novos `service_type` values — sem 422 inesperado pós-rename
- Financeiro.jsx exibe badges corretos para todos os tipos
- Wave 2 pode prosseguir: criação do `ComercialController`, `EmpresaCadastradaNotification`, setor "Comercial" e permission `comercial.cadastrar_empresa`

---
*Phase: 13-reestruturacao-cadastro-empresas*
*Completed: 2026-05-25*

## Self-Check: PASSED

- [x] `tests/Feature/Phase13MigrationTest.php` existe em disco
- [x] `database/migrations/2026_05_25_100001_add_status_to_companies.php` existe em disco
- [x] `database/migrations/2026_05_25_100002_add_company_id_to_mlb_empresas.php` existe em disco
- [x] Commits `555c527`, `6009958`, `5ecad8f` existem no git log
- [x] `artisan test --filter=Phase13MigrationTest` → 9/9 PASSED
- [x] `artisan migrate:status` → ambas as migrations mostradas como "Ran"
- [x] `AdminController.php` não contém `in:polo,` (sem 's')
- [x] `Financeiro.jsx` não contém chave `polo:` como objeto de labels
- [x] `Financeiro.jsx` contém chaves `polos:`, `publicidade:`, `gestao:`
- [x] `npm run build` → 0 erros
