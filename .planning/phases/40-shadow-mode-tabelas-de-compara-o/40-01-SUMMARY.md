---
phase: 40-shadow-mode-tabelas-de-compara-o
plan: 01
subsystem: database

tags: [phase-40, sugadores, migration, shadow-mode, ml-migration, tdd, eloquent]

requires:
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    provides: "SugadorAnalysisService::analyzeCompany(..., $dryRun, $forceProvider) operacional — Plan 40-02 vai chamar com dryRun=true em loop adman+ml"
provides:
  - "Tabela sugador_provider_runs (10 colunas + idx_company_ref_provider + FK cascade) registra 1 linha por execução shadow (empresa+data+provider)"
  - "Tabela sugador_provider_items (8 colunas + idx_run_tipo + FK cascade) registra cada sugador detectado em cada run"
  - "Model Eloquent SugadorProviderRun com casts (date, immutable_datetime, summary array) + relações company()/items()"
  - "Model Eloquent SugadorProviderItem com casts (motivos/metrics_json array) + relação run()"
  - "Migration idempotente (guards Schema::hasTable) e segura no rollback (down() dropa filha antes da pai por causa da FK cascade)"
affects: [phase-40-02-shadow-run-service, phase-40-03-provider-comparison-service, phase-40-04-comandos-scheduler]

tech-stack:
  added: []
  patterns:
    - "Tabela auxiliar sem LogsActivity (audit log fica na fonte canônica `sugadores`)"
    - "Migration idempotente com guards Schema::hasTable individuais por tabela"
    - "FK cascade em par filho→pai: down() dropa filha primeiro (mesmo padrão do polos_faturamento_snapshots Phase 21)"
    - "Cast immutable_datetime para timestampTz (started_at/finished_at) — datas de janela como cast 'date'"

key-files:
  created:
    - database/migrations/2026_06_25_400001_create_sugador_provider_runs_and_items_tables.php
    - app/Models/SugadorProviderRun.php
    - app/Models/SugadorProviderItem.php
    - tests/Feature/Phase40/CreateSugadorProviderTablesTest.php
  modified: []

key-decisions:
  - "Sem LogsActivity nas 2 Models — tabelas auxiliares de comparação shadow, sem necessidade de audit log (a `sugadores` continua sendo a fonte auditada)"
  - "Migration usa guards Schema::hasTable INDIVIDUAIS por tabela (não 1 guard só) — permite re-run que tenha criado parcialmente uma tabela antes de falhar"
  - "down() dropa sugador_provider_items ANTES de sugador_provider_runs para respeitar a FK cascade"
  - "raw_hash declarado como string(64) (SHA256 hex) — popularização vem no Plan 40-02 (ShadowRunService) ou via Observer (deferred conforme §specifics do CONTEXT)"
  - "Tests usam DB::statement('PRAGMA foreign_keys = ON') antes dos asserts de cascade — SQLite por default não força FKs sem PRAGMA"
  - "Atributo #[PHPUnit\\Framework\\Attributes\\Test] em vez de /** @test */ — alinhado ao deprecation warning do PHPUnit 12 (Rule 1 fix preventivo)"

patterns-established:
  - "Tabelas auxiliares Sugadores Phase 40+: prefixo `sugador_provider_*`, sem LogsActivity, com FK cascade contida no schema"
  - "Models auxiliares: sem trait HasFactory (não há factory definida para Company tampouco), criação via `Model::create([...])` nos tests"

requirements-completed: [REQ-40-01]

duration: 25min
completed: 2026-06-25
---

# Phase 40 Plan 40-01: Migration sugador_provider_runs+items e Models Eloquent

**Tabelas auxiliares de shadow mode (`sugador_provider_runs` + `sugador_provider_items`) e Models Eloquent prontos para que Plans 40-02/03 gravem/leiam runs Adman+ML em paralelo sem tocar na `sugadores` canônica**

## Performance

- **Duration:** ~25 minutos
- **Started:** 2026-06-25T20:07:30Z
- **Completed:** 2026-06-25T20:15:21Z
- **Tasks:** 2 (RED + GREEN)
- **Files created:** 4 (1 migration + 2 Models + 1 test Feature)
- **Files modified:** 0 (zero modificação em arquivos do "não-tocar")

## Accomplishments

- Migration `2026_06_25_400001_create_sugador_provider_runs_and_items_tables` cria 2 tabelas com schema EXATO do `40-CONTEXT.md §Implementation Decisions` — `sugador_provider_runs` (13 colunas: 10 funcionais + id/timestamps) e `sugador_provider_items` (11 colunas: 8 funcionais + id/timestamps). Índices compostos `idx_company_ref_provider` e `idx_run_tipo` nomeados conforme spec. FKs com cascadeOnDelete.
- Migration idempotente (guards `Schema::hasTable` individuais) e segura no rollback (`down()` dropa filha antes da pai).
- Models `SugadorProviderRun` e `SugadorProviderItem` com casts corretos (date/immutable_datetime/array) e relações `hasMany`/`belongsTo` funcionando.
- Suite Feature `tests/Feature/Phase40/CreateSugadorProviderTablesTest.php` com 8 tests verdes (23 assertions, 1.33s) cobrindo schema, índices, FK cascade e comportamento dos casts/relações.
- **GATE DE ZERO REGRESSÃO ATENDIDO:** suite Sugador acumulada continua em **73 verdes** (era 65 baseline Phase 39, +8 desta plan = 73), suite Phase 39 continua em **48/48** verdes (208 assertions).

## Task Commits

1. **Tarefa 1 (RED): Suite Feature valida schema** - `3107df5` (test) — 8 tests escritos. Suite RED roda e falha 8/8 com mensagens esperadas ("Class App\\Models\\SugadorProviderRun not found").
2. **Tarefa 2 (GREEN): Migration + 2 Models** - `7abd364` (feat) — migration + SugadorProviderRun + SugadorProviderItem criados. Suite GREEN 8/8 verde (23 assertions).

**Plan metadata commit:** (será criado após este SUMMARY)

## Files Created/Modified

### Criados

- `database/migrations/2026_06_25_400001_create_sugador_provider_runs_and_items_tables.php` — Migration única criando as 2 tabelas com índices compostos e FKs cascade; idempotente; down() respeita ordem filha→pai.
- `app/Models/SugadorProviderRun.php` — Model Eloquent com $fillable (10 campos), $casts (3 dates + 2 immutable_datetime + 1 array), relações `company()`/`items()`. PHPDoc pt-BR explica propósito e ausência intencional do LogsActivity.
- `app/Models/SugadorProviderItem.php` — Model Eloquent com $fillable (8 campos), $casts (2 arrays), relação `run()`. PHPDoc pt-BR explica `raw_hash` (SHA256 de motivos+metrics_json para detectar duplicatas).
- `tests/Feature/Phase40/CreateSugadorProviderTablesTest.php` — 8 tests Feature usando `RefreshDatabase` + SQLite em-memory + `PRAGMA foreign_keys = ON` para validar cascades.

### Modificados

Nenhum. Zero modificação em:
- `app/Models/Sugador.php`, `SugadorConfig.php`, `SugadorAcao.php`
- `app/Services/SugadorAnalysisService.php`
- `app/Services/Sugadores/*Provider*.php`, `*Factory*.php` (Phase 39)
- `app/Services/AdmanService.php`, `MercadoLivreService.php`, `MercadoLivreAdsService.php`
- `app/Console/Commands/AnalyzeSugadores.php`
- `app/Jobs/AnalyzeCompanySugadoresJob.php`
- `routes/console.php` (scheduler é Plan 40-04)
- `config/sugadores.php` (criação é Plan 40-04)

Validado via `git diff --name-only HEAD~2 HEAD` mostrando apenas os 4 arquivos novos acima.

## Decisions Made

1. **Sem LogsActivity nas 2 Models** — tabelas auxiliares de comparação shadow não precisam de audit log; a `sugadores` continua sendo a fonte canônica auditada (decisão explícita no `<interfaces>` do PLAN).
2. **Guards `Schema::hasTable` individuais por tabela** — permite re-run que tenha criado parcialmente (1 tabela ok, outra ainda não); diferente do `polos_faturamento_snapshots` Phase 21 que usa 1 guard para 1 tabela só, mas mesmo padrão idempotente.
3. **`down()` dropa filha antes da pai** — `Schema::dropIfExists('sugador_provider_items')` antes de `sugador_provider_runs` para respeitar a FK cascade no rollback (documentado no PHPDoc da migration e no §threat T-40-01-01 do plan).
4. **`PRAGMA foreign_keys = ON` nos tests de cascade** — SQLite por default ignora FKs sem esse PRAGMA. Aplicado apenas nos 2 tests que precisam (não nos 6 outros) para manter os asserts dos demais simples.
5. **Atributo `#[Test]` em vez de docblock `/** @test */`** — Rule 1 fix preventivo durante a Tarefa 1: PHPUnit warning explícito "Metadata in doc-comments is deprecated and will no longer be supported in PHPUnit 12". Importado `PHPUnit\Framework\Attributes\Test`.
6. **`Company::create()` direto nos tests, sem factory** — não há `CompanyFactory` definida (só `UserFactory`); seguindo o pattern já estabelecido na suite `tests/Feature/Phase39/SugadorAnalysisServiceRefactorTest.php::makeCompanyWithConfig()`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Substituição de `/** @test */` por atributo `#[Test]`**
- **Found during:** Tarefa 1 (RED) — primeiro run do `php artisan test` saiu como "No tests found" com 8 WARN explícitos de "Metadata in doc-comments is deprecated".
- **Issue:** PHPUnit 11.x emite warning de deprecação para docblocks `/** @test */` (será removido em PHPUnit 12); como o filtro `--filter=Phase40\\CreateSugadorProviderTables` ficou meio confuso pelo escape de bash, o PHPUnit acabou não descobrindo os tests via doccomment.
- **Fix:** Importado `use PHPUnit\Framework\Attributes\Test;` e substituídos os 8 `/** @test */` por `#[Test]` antes dos métodos. Filtro CLI ajustado para `--filter=CreateSugadorProviderTables` (sem namespace) que funciona em ambos os shells.
- **Files modified:** `tests/Feature/Phase40/CreateSugadorProviderTablesTest.php` (durante a própria Tarefa 1, antes do commit RED).
- **Verification:** Tests passaram a ser descobertos pelo PHPUnit (8 failed conforme esperado para RED, depois 8 passed no GREEN).
- **Committed in:** `3107df5` (commit RED já com a versão `#[Test]`).

---

**Total deviations:** 1 auto-fixed (Rule 1 - bug de descoberta de tests + deprecation warning)
**Impact on plan:** Sem scope creep. Fix preventivo alinha o repositório com PHPUnit 12 (que vai virar requisito eventualmente). Nenhum outro test do projeto foi tocado.

## Issues Encountered

- **Bloqueio conhecido — MariaDB local não sobe.** O comando `php artisan migrate --pretend` (recomendado pelo plan) falhou na primeira tentativa contra MariaDB (`SQLSTATE[HY000] [2002] Nenhuma conexão pôde ser feita`). Esse bloqueio é conhecido do CONTEXT (quick task `dev:reparar-mariadb-local`) e não invalida a migração: re-executei o comando explicitando `DB_CONNECTION=sqlite DB_DATABASE=:memory:` e obtive o SQL gerado pela migration com:
  - `create table "sugador_provider_runs" (..., foreign key("company_id") references "companies"("id") on delete cascade)`
  - `create index "idx_company_ref_provider" on "sugador_provider_runs" (...)`
  - `create table "sugador_provider_items" (..., foreign key("run_id") references "sugador_provider_runs"("id") on delete cascade)`
  - `create index "idx_run_tipo" on "sugador_provider_items" (...)`
  
  A execução real contra MariaDB fica para quando o ambiente local destravar (mesmo follow-up da Phase 38 Tarefa 3). A suite de tests usa SQLite em-memory e prova que a migration roda end-to-end (8/8 verdes).
- **PHPUnit 11.x deprecation warning** — tratado via Rule 1 (acima) substituindo doccomment por atributo.

## User Setup Required

Nenhum — Plan 40-01 só cria schema e Models PHP. Sem env nova, sem rota HTTP, sem dependência externa. Em produção, basta `php artisan migrate` durante o próximo deploy (após Plan 40-04 fechar a Phase) ou pode ser feito isolado se o usuário quiser destravar Plan 40-02/03 antes.

## Next Phase Readiness

- **Wave 2 (Plans 40-02 + 40-03) LIBERADA** — pode rodar em paralelo:
  - `Plan 40-02 ShadowRunService`: tem onde gravar (`SugadorProviderRun::create([...])` + `SugadorProviderItem::create([...])`).
  - `Plan 40-03 ProviderComparisonService`: tem onde ler (`SugadorProviderRun::with('items')->...`).
- **REQ-40-01 fechado.**
- **Nenhum blocker para Plans seguintes** além do já documentado (MariaDB local — não afeta tests automatizados, apenas smoke real).
- **Self-check:** todos os arquivos commitados existem em git (`3107df5` test, `7abd364` feat), suite Phase 40 8/8 verde, suite Sugador acumulada 73/73 verde (zero regressão), suite Phase 39 48/48 verde.

## Self-Check

- Arquivo `tests/Feature/Phase40/CreateSugadorProviderTablesTest.php` existe — FOUND
- Arquivo `database/migrations/2026_06_25_400001_create_sugador_provider_runs_and_items_tables.php` existe — FOUND
- Arquivo `app/Models/SugadorProviderRun.php` existe — FOUND
- Arquivo `app/Models/SugadorProviderItem.php` existe — FOUND
- Commit `3107df5` (RED) presente em `git log` — FOUND
- Commit `7abd364` (GREEN) presente em `git log` — FOUND
- Suite `php artisan test --filter=CreateSugadorProviderTables` retorna 8/8 PASS — VERIFIED
- Suite `php artisan test --filter=Sugador` retorna 73 PASS (era 65 baseline + 8 novos) — VERIFIED
- Suite `php artisan test --filter=Phase39` retorna 48/48 PASS — VERIFIED
- Greps obrigatórios do PLAN passam: `idx_company_ref_provider`=1, `idx_run_tipo`=1, `cascadeOnDelete`=2 — VERIFIED

**## Self-Check: PASSED**

---
*Phase: 40-shadow-mode-tabelas-de-compara-o*
*Completed: 2026-06-25*
