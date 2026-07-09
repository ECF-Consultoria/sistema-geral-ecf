---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 01
subsystem: desempenho
tags: [migration, schema, model, snapshot, mensal, diario]
requires:
  - Phase 46 desempenho_score_snapshots (schema base preservado)
provides:
  - Coluna `mes_referencia DATE NULL` na tabela `desempenho_score_snapshots`
  - Unique key `(user_id, ref_date, mes_referencia)` habilitando coexistência diária + mensal
  - Índice `(mes_referencia, score)` acelerando ranking mensal
  - Scopes `mensal()` / `diario()` no Model `DesempenhoScoreSnapshot`
affects:
  - Plan 74-03 (DesempenhoScoreService.compute — grava snapshots mensais)
  - Plan 74-04 (comando `desempenho:consolidar-mes`)
  - Plan 74-06 (Performance/Dashboard.jsx — view mensal)
tech-stack:
  added: []
  patterns:
    - Migration alter idempotente com Schema::hasColumn + try/catch em índices
    - Scope Eloquent whereNotNull / whereNull como filtro binário de modalidade
key-files:
  created:
    - database/migrations/2026_07_09_140001_alter_desempenho_score_snapshots_add_mes_referencia.php
  modified:
    - app/Models/DesempenhoScoreSnapshot.php
decisions:
  - D-01 · Evoluir tabela existente (não criar nova) — preserva histórico v1
  - D-02 · Uma tabela, duas modalidades — mes_referencia=NULL diário, YYYY-MM-01 mensal
  - D-03 · Unique key evolutiva (user_id, ref_date, mes_referencia)
metrics:
  duration: 10min
  completed: 2026-07-09
  tasks: 2
  files: 2
  commits: [833c530, 60c0210]
---

# Phase 74 Plan 01: Fundação de schema para snapshot mensal fechado

Migration alter idempotente + Model atualizado desbloqueiam a coexistência das duas modalidades de snapshot (diário legado + mensal fechado) no mesmo storage `desempenho_score_snapshots`, sem perda de histórico v1.

## O que foi feito

### Task 1 — Migration alter idempotente (commit `833c530`)

Criado `database/migrations/2026_07_09_140001_alter_desempenho_score_snapshots_add_mes_referencia.php`. Em `up()`:

1. Guard `Schema::hasTable('desempenho_score_snapshots')` — não age em ambiente sem a tabela.
2. Guard `Schema::hasColumn(..., 'mes_referencia')` — adiciona `date('mes_referencia')->nullable()->after('ref_date')` só se ausente.
3. Drop unique legado `(user_id, ref_date)` envolvido em `try/catch \Throwable` (idempotência em rerun).
4. Create unique novo `(user_id, ref_date, mes_referencia)` com nome curto `desempenho_score_snapshots_user_ref_mes_unique` (respeita limite 64 chars do MariaDB).
5. Create índice `(mes_referencia, score)` como `desempenho_score_snapshots_mes_score_idx` para acelerar ranking mensal.

`down()` reverte: drop dos índices/uniques novos, restaura unique legado `(user_id, ref_date)`, remove a coluna. Todos os passos idempotentes.

**Validação executada:**
- `php artisan migrate --force` em SQLite tmp: aplicou sem erros.
- `PRAGMA table_info(desempenho_score_snapshots)`: coluna `mes_referencia` presente como `date` NOT NULL=0.
- `sqlite_master` para índices: `desempenho_score_snapshots_user_ref_mes_unique` (UNIQUE) e `desempenho_score_snapshots_mes_score_idx` presentes; unique legado ausente.
- `migrate:rollback --step=1` + `migrate --force` em sequência: rollback + reapply sem erros (idempotência confirmada).

### Task 2 — Model DesempenhoScoreSnapshot (commit `60c0210`)

Editado `app/Models/DesempenhoScoreSnapshot.php`:

1. `'mes_referencia'` adicionado ao `$fillable` (após `'ref_date'`).
2. Cast `'mes_referencia' => 'date'` adicionado ao `$casts` (hidratação Carbon consistente com `ref_date`).
3. Docblock class-level reescrito para documentar as duas modalidades (D-02) e referenciar `.planning/phases/74-.../74-CONTEXT.md`.
4. Scope `mensal()` — `whereNotNull('mes_referencia')` — para consumo do ranking mensal.
5. Scope `diario()` — `whereNull('mes_referencia')` — para consumo do toggle "Ver diário".

**Validação executada** (via `php artisan tinker`):
- `getFillable()` inclui `'mes_referencia'` na posição 2 (após `ref_date`).
- `getCasts()` mapeia `'mes_referencia' => 'date'`.
- `DesempenhoScoreSnapshot::mensal()->toSql()` → `... where "mes_referencia" is not null`.
- `DesempenhoScoreSnapshot::diario()->toSql()` → `... where "mes_referencia" is null`.

## Decisões implementadas

- **D-01** · Evolução da tabela existente (não criação de nova) — histórico Phase 46 preservado, `mes_referencia = NULL` para todas as rows legadas.
- **D-02** · Semântica dual da mesma tabela: NULL = diário legado, YYYY-MM-01 = mensal fechado.
- **D-03** · Unique key evolutiva `(user_id, ref_date, mes_referencia)`, substituindo o `(user_id, ref_date)` da Phase 46.

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito.

Ajuste operacional (não é desvio): `php artisan migrate --pretend` sem conexão MariaDB não conclui por causa da corrupção local documentada em `MEMORY.md` (`project_mariadb_local_corrompido.md`). Validei via SQLite tmp em `scratchpad/74-test.sqlite` (padrão da phase 74 — `74-SPEC.md` constraints referem SQLite RefreshDatabase nos testes).

## Success Criteria

- [x] Coluna `mes_referencia DATE NULL` presente em `desempenho_score_snapshots`.
- [x] Unique legado `(user_id, ref_date)` substituído por unique `(user_id, ref_date, mes_referencia)`.
- [x] Índice `(mes_referencia, score)` criado.
- [x] Model expõe cast/fillable + scopes `mensal`/`diario`.
- [x] Migration idempotente (rerun/rollback+reapply sem erro).
- [x] Zero perda de dados históricos v1 (down() restaura unique legado antes de remover coluna).

## Links

- SPEC: `.planning/phases/74-.../74-SPEC.md` DESEMP-09
- CONTEXT decisões: `.planning/phases/74-.../74-CONTEXT.md` §D-01, D-02, D-03

## Self-Check: PASSED

- FOUND: `database/migrations/2026_07_09_140001_alter_desempenho_score_snapshots_add_mes_referencia.php`
- FOUND: `app/Models/DesempenhoScoreSnapshot.php` (modificado)
- FOUND commit `833c530` (Task 1)
- FOUND commit `60c0210` (Task 2)
