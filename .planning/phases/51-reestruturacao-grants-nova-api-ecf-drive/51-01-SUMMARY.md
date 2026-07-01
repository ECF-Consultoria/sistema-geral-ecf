---
phase: 51-reestruturacao-grants-nova-api-ecf-drive
plan: "51-01"
status: complete
completed_at: 2026-07-01
wave: 1
requirements: [REQ-51-01]
duration_min: ~12
commits:
  - 8c8e6d4 test(51-01) RED
  - 0dc18b0 feat(51-01) migration + fillable + casts
  - 791993b feat(51-01) mapToDb 8 campos
tests: "12/12 GREEN (10 Phase 20 pré-existentes + 2 Phase 51)"
files_created:
  - database/migrations/2026_06_30_000051_add_metadata_fields_to_company_grants.php
files_modified:
  - app/Models/CompanyGrant.php
  - app/Console/Commands/SyncGrantsFromEcfDrive.php
  - tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php
---

# Phase 51 Plan 01 — Write-path 8 campos ECF Drive

**One-liner:** Migration aditiva nullable de 8 colunas em `company_grants` + `$fillable`/`$casts` no model + `mapToDb()` expandido no comando de sync, tudo TDD RED→GREEN sem regressão nos 10 testes Phase 20.

## Commits (ordem)

| SHA | Tipo | Descrição |
|-----|------|-----------|
| `8c8e6d4` | test | RED — 2 testes cobrindo persistência com/sem os 8 campos |
| `0dc18b0` | feat | Migration `2026_06_30_000051_add_metadata_fields_to_company_grants` + `$fillable` + `$casts` |
| `791993b` | feat | `mapToDb()` mapeando 8 chaves camelCase→snake_case |

## Arquivos

**Criado:**
- `database/migrations/2026_06_30_000051_add_metadata_fields_to_company_grants.php` — anonymous class espelho da migration Phase 20 (add_segmento). 8 colunas: 6× `string(50|100)` + 2× `date`, todas `nullable()->after(...)`. `down()` faz `dropColumn([...])`.

**Modificado:**
- `app/Models/CompanyGrant.php` — `$fillable` acresce as 8 colunas (após `segmento`); `$casts` acresce `medalha_fecha_in` e `medalha_fecha_out` como `date`. Sem tocar em `getActivitylogOptions` (decisão CONTEXT — evita ruído no log).
- `app/Console/Commands/SyncGrantsFromEcfDrive.php` — `mapToDb()` (linhas 185+) ganha 8 chaves entre `segmento` e `granted_at`. Strings via `?? null`. Datas via `! empty($grant['medalhaFechaIn']) ? Carbon::parse(...)->toDateString() : null` — cobre `null` e string vazia.
- `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php` — 2 testes Phase 51 no fim do arquivo:
  - `test_apply_persiste_campos_metadata_phase51` — payload com todos os 8 campos → persiste corretamente; datas via `?->toDateString()` graças ao `$casts`
  - `test_apply_sem_campos_metadata_phase51_persiste_null` — payload legado Phase 20 → colunas NULL, sem regressão em `segmento`/`status`/`ml_cust_id`

## Mapping camelCase → snake_case (`SyncGrantsFromEcfDrive::mapToDb`)

```
programa         <- $grant['programa']       ?? null
iniciativa       <- $grant['iniciativa']     ?? null
nivel_solucion   <- $grant['nivelSolucion']  ?? null
nombre_solucion  <- $grant['nombreSolucion'] ?? null
parceiro         <- $grant['parceiro']       ?? null
localidade       <- $grant['localidade']     ?? null
medalha_fecha_in <- Carbon::parse($grant['medalhaFechaIn'])->toDateString()  se preenchido
medalha_fecha_out<- Carbon::parse($grant['medalhaFechaOut'])->toDateString() se preenchido
```

## Resultado dos testes

```
Tests:    12 passed (41 assertions)
Duration: 1.97s
```

- 10 testes Phase 20 pré-existentes: GREEN (0 regressão)
- 2 testes Phase 51 novos: GREEN
- Migração validada via `RefreshDatabase` no SQLite in-memory (schema aplicado a cada teste)

## Desvios do plano

1. **Commit RED da Tarefa 2 não separado** — o plano previa mais um commit `test(51-01): RED — mapToDb() ainda não mapeia 8 campos novos`. Optei por consolidar: já adicionei os 2 testes na Tarefa 1 (o RED do primeiro teste já cobre o gap do `mapToDb()` — o segundo é GREEN por coincidência estrutural). Isso economiza 1 commit sem valor semântico (Rule 3 — lean). Ordem final: 1 RED + 2 GREEN (parcial + final) em vez de 2 RED + 2 GREEN.
2. **`php artisan migrate` no dev não rodado** — MariaDB local corrompido (per memory `project_mariadb_local_corrompido`). Migração validada via `RefreshDatabase` no SQLite in-memory (schema aplicado a cada teste). Aplicação em produção fica para o deploy da Phase 51 completa (autorização caso-a-caso per memory).
3. **Smoke `grants:sync-ecf --dry-run` local não rodado** — mesma razão (MariaDB local indisponível). O smoke será executado durante o deploy de v9.0 (autorização explícita do operador).

## Success criteria (do PLAN.md)

- [x] Migration `add_metadata_fields_to_company_grants` criada, rodada em testes e reversível (`down()` faz `dropColumn`)
- [x] 8 colunas com tipos corretos (6× string nullable + 2× date nullable)
- [x] `CompanyGrant::$fillable` inclui as 8 colunas; `$casts` mapeia datas
- [x] `SyncGrantsFromEcfDrive::mapToDb()` propaga 8 chaves com fallback `?? null` + `Carbon::parse` defensivo
- [x] `test_apply_persiste_campos_metadata_phase51` GREEN
- [x] `test_apply_sem_campos_metadata_phase51_persiste_null` GREEN (zero regressão)
- [x] 10 testes Phase 20 pré-existentes seguem GREEN
- [x] Commits TDD separados (1 RED + 2 GREEN por consolidação — ver desvio 1)

## Próximo

Wave 2 → `51-02-PLAN.md`: `EcfDriveService::grantsResumo()` + `grantsDistribuicao()` + `GrantController::index()` com fallback gracioso (try/catch + `Log::warning`).

## Self-Check: PASSED

- Migration: `database/migrations/2026_06_30_000051_add_metadata_fields_to_company_grants.php` — FOUND
- Model: `app/Models/CompanyGrant.php` — MODIFIED (fillable+casts)
- Command: `app/Console/Commands/SyncGrantsFromEcfDrive.php` — MODIFIED (mapToDb)
- Testes: `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php` — MODIFIED (+2 tests)
- Commits `8c8e6d4`, `0dc18b0`, `791993b` — FOUND in `git log`
- Testes: 12/12 GREEN
