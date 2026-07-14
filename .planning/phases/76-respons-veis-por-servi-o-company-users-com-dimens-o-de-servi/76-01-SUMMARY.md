---
phase: 76-respons-veis-por-servi-o-company-users-com-dimens-o-de-servi
plan: 01
subsystem: schema / company_users
tags: [migration, cross-driver, pivot, data-migration, v16.0, gate]
requires: []
provides:
  - "company_users.servico_id (nullable, FK servicos no MySQL)"
  - "unique 4-col (company_id, user_id, role, servico_id)"
  - "migração de dados performance→servico_id idempotente"
  - "infra de testes tests/Feature/V16/ (trait CriaCenarioResponsaveis)"
affects:
  - "planos 76-02/76-03 (leitura consolidada + escrita por-serviço)"
tech-stack:
  added: []
  patterns:
    - "migration cross-driver em passos (branch por DB::getDriverName)"
    - "data-migration idempotente via whereNull"
    - "fixture trait via DB::table (sem factories novas)"
key-files:
  created:
    - database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php
    - tests/Feature/V16/CriaCenarioResponsaveis.php
    - tests/Feature/V16/MigrationCompanyUsersServicoTest.php
    - tests/Feature/V16/DataMigrationServicoTest.php
  modified: []
decisions:
  - "migrarLinhasExistentes() é público e retorna int (linhas afetadas) — habilita o teste de idempotência sem re-migrate"
  - "selectRaw com aliases explícitos no lugar de pluck(DB::raw) — evita mismatch de nome de propriedade"
metrics:
  duration: "~20min"
  completed: 2026-07-14
  tasks: 2
  files: 4
---

# Phase 76 Plan 01: Fundação de schema — company_users com dimensão de serviço Summary

Migration cross-driver idempotente que adiciona `servico_id` (nullable, FK `servicos` só no MySQL) à pivot `company_users`, troca o unique de 3 para 4 colunas e faz backfill das linhas existentes para o `servico_id` do contrato performance ativo (NULL = consolidado/legado), mais a infra de testes `tests/Feature/V16/`. GATE bloqueante da fase liberado.

## O que foi construído

- **Migration `2026_07_14_000001_add_servico_id_to_company_users.php`** — em 4 passos:
  1. `ADD COLUMN servico_id` nullable + índice (sem `->constrained()` — SQLite não adiciona FK em ALTER).
  2. FK `servicos` (`nullOnDelete`) apenas no branch `DB::getDriverName() === 'mysql'`.
  3. Swap do unique: drop `(company_id,user_id,role)` → cria `(company_id,user_id,role,servico_id)`.
  4. `migrarLinhasExistentes()` — método **público** que monta o mapa `company_id→servico_id` (join `contratos_servico`+`servicos`, `ativo=true` e `setor='performance'`, `groupBy` + `MIN`) em 1 query e faz `UPDATE ... whereNull('servico_id')`. Retorna o total de linhas afetadas.
  - `down()` reverte em ordem inversa (zera servico_id → unique 3-col → drop FK no MySQL → drop índice/coluna).
- **Trait `CriaCenarioResponsaveis`** — fixture V16 via `DB::table` (sem criar `ServicoFactory`/`ContratoServicoFactory`): `criarServico`, `criarContrato`, `inserirPivot`, `criarCenarioMlComResponsaveis`, `inserirLinhaShopee`.
- **2 testes Feature bloqueantes** — 7 casos, todos verdes.

## Como validar

```bash
php artisan test tests/Feature/V16 --stop-on-failure
```

- `MigrationCompanyUsersServicoTest`: coluna existe; múltiplos NULL do mesmo par coexistem; mesmo `servico_id` não-NULL viola o unique.
- `DataMigrationServicoTest`: backfill performance ativo; NULL sem performance / com contrato inativo; idempotência (2ª passada retorna 0).

Resultado: **7 passed (8 assertions)**. Regressão: `CompanyPortfolioAccessTest` **4 passed** (a pivot legada continua inserindo sem `servico_id`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `pluck(DB::raw('MIN(...)'), 'ct.company_id')` lançava erro de propriedade indefinida**
- **Found during:** Tarefa 2 (GREEN)
- **Issue:** O `pluck` com expressão `DB::raw` como coluna e chave qualificada (`ct.company_id`) não resolve o nome da propriedade no objeto de resultado (`$row->$key`/`$row->$column`), quebrando a data-migration com "Undefined property".
- **Fix:** Substituído por `->selectRaw('ct.company_id as company_id, MIN(ct.servico_id) as servico_id')->get()` + loop com aliases explícitos. Mesma resolução em 1 query, sem N+1.
- **Files modified:** database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php
- **Commit:** c32308c

## Validação MySQL (pendente — fora desta fase)

O branch MySQL da FK **não** é exercitado pelos testes locais (SQLite `:memory:`). Validar manualmente no VPS pós-deploy: `php artisan migrate` + conferir a constraint `servico_id` em `company_users` (`SHOW CREATE TABLE company_users`). Conforme critical_notes e `76-RESEARCH.md` (Environment Availability).

## Self-Check: PASSED

- Migration + trait + 2 testes: arquivos presentes em disco (criados via Write).
- Commits presentes: 31a71de (trait), 819e055 (RED), c32308c (GREEN GATE).
- Suite `tests/Feature/V16`: 7 passed.
