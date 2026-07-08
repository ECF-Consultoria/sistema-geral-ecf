---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
plan: 01
subsystem: database
tags: [nps, migrations, sqlite, mysql, mariadb, snapshot, laravel-12]

# Dependency graph
requires:
  - phase: 31-nps-mensal-automatizado
    provides: "Schema base de nps_surveys (com month_reference + auto_generated) e nps_responses (com score_estrategista/analista/empresa)"
  - phase: 33-perguntas-customizadas-nps
    provides: "Padrão de snapshot per-row provado em nps_respostas_customizadas (fonte de inspiração para nps_response_answers)"
provides:
  - "5 tabelas novas: nps_templates, nps_template_questions, nps_template_options, nps_template_service_scopes, nps_response_answers"
  - "Coluna nps_surveys.template_id (FK nullable com nullOnDelete)"
  - "nps_responses.score_estrategista + score_empresa viraram NULLABLE"
  - "Índices nomeados: nps_ans_response_dim_idx, nps_tpl_scope_uniq, nps_templates_default_uniq, nps_templates_active_priority_idx"
  - "Unique parcial em nps_templates.is_default (SQLite partial index; MySQL virtual generated column) — garante exatamente 1 template default"
affects:
  - 68-02-modelos-factories
  - 68-03-seed-nps-padrao-retroativo
  - 68-04-dedup-key
  - 68-05-testes-schema
  - phase-69 (backend NpsTemplateService + NpsScoreCalculator)
  - phase-70 (UI CRUD templates)

# Tech tracking
tech-stack:
  added: []  # zero deps novas — 100% Laravel 12 Schema builder + DB::statement por driver
  patterns:
    - "Snapshot per-row congelado (question_texto_snapshot + option_peso_snapshot) replicado de nps_respostas_customizadas"
    - "Unique parcial cross-driver: partial index (SQLite) vs virtual generated column (MySQL/MariaDB) via DB::connection()->getDriverName()"
    - "Idempotência via Schema::hasTable / Schema::hasColumn guards em toda migration alter/create"
    - "down() defensivo no-op quando reverter destruiria dados legítimos (padrão Phase 31 D-10)"
    - "FK cascadeOnDelete para tabelas subordinadas ao template; FK nullOnDelete para preservar histórico snapshot quando pai for hard-deletado"

key-files:
  created:
    - "database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php"
    - "database/migrations/2026_07_07_100002_alter_nps_surveys_add_template_id.php"
    - "database/migrations/2026_07_07_100003_alter_nps_responses_scores_nullable.php"
  modified: []  # Zero código de app tocado nesta wave; só schema físico

key-decisions:
  - "Snapshot per-row em nps_response_answers com 4 colunas snapshot (research §1) — dashboard NPS-E-05 lê do snapshot, FK viva serve para audit mas nunca vira fonte de cálculo"
  - "Unique parcial de is_default split por driver: SQLite CREATE UNIQUE INDEX ... WHERE is_default=1 / MySQL virtual generated column + UNIQUE — testado emitindo SQLSTATE 23000 na inserção do 2º default"
  - "FK nps_surveys.template_id = nullOnDelete (não cascade) — se template pai for apagado, surveys sobrevivem com snapshot congelado como fonte de verdade"
  - "score_analista NÃO foi alterada — já era nullable desde Phase 31 (schema recreate)"
  - "down() da migration 100003 é no-op informativo com Log::warning — reverter para NOT NULL destruiria rows legítimas NULL da Phase 69+ (padrão Phase 31 D-10)"

patterns-established:
  - "Snapshot per-row para configurações versionadas: sempre que uma tabela CRUD admin puder ter historico associado (respostas, submissões, etc.), replicar o padrão de nps_respostas_customizadas — snapshot congelado + FK viva com nullOnDelete"
  - "Unique parcial portátil: usar DB::connection()->getDriverName() para split entre SQLite partial index e MySQL virtual generated column — evita bug do Schema::table()->virtualAs() com SQLite in-memory"
  - "Indices nomeados explicitamente quando são acessados em queries hot-path — facilita EXPLAIN QUERY PLAN e evita colisão com nomes gerados"

requirements-completed: [NPS-A-01, NPS-A-04]

# Metrics
duration: 22min
completed: 2026-07-07
---

# Phase 68 Plan 01: Schema NPS Templates v15.0 Summary

**5 tabelas novas + template_id FK em nps_surveys + score_* NULLABLE em nps_responses — fundação de dados para templates NPS configuráveis com snapshot per-row congelado.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-07-07T (aprox 20 min antes deste commit)
- **Completed:** 2026-07-07
- **Tasks:** 3
- **Files created:** 3 migrations (zero código de app modificado)

## Accomplishments

- **5 tabelas novas** criadas em uma migration atômica (`2026_07_07_100001`):
  - `nps_templates` — configuração raiz + unique parcial em `is_default` + índice `(active, priority)`
  - `nps_template_questions` — perguntas do template com enum `dimensao` para NpsScoreCalculator
  - `nps_template_options` — opções com peso 1..5 que alimentam `AVG(option_peso_snapshot)`
  - `nps_template_service_scopes` — pivot template×serviço com unique composto `nps_tpl_scope_uniq`
  - `nps_response_answers` — snapshot per-row congelado + FK viva com `nullOnDelete` + índice composto `nps_ans_response_dim_idx (response_id, question_dimensao_snapshot)`
- **nps_surveys.template_id** adicionado como FK nullable com `nullOnDelete` (`2026_07_07_100002`)
- **nps_responses.score_estrategista** e **score_empresa** viraram NULLABLE via `->change()` (`2026_07_07_100003`) — habilita fluxo Phase 69 que grava snapshot em `nps_response_answers` deixando os score_* legados NULL
- **Unique parcial em `is_default`** validado emitindo `SQLSTATE 23000` no 2º INSERT com `is_default=true` (controller da Phase 70 vai capturar essa exception para UX de "já existe um template default")
- **Idempotência confirmada:** ciclo `migrate:fresh → migrate:rollback --step=3 → migrate` sem erros

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Migration principal (5 tabelas novas)** — `f84df0f` (feat)
2. **Task 2: Alter nps_surveys — adiciona template_id FK** — `e674195` (feat)
3. **Task 3: Alter nps_responses — score_* nullable** — `9fbd985` (feat)

## Files Created

- **`database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php`** (288 linhas) — Cria as 5 tabelas novas + 4 índices nomeados + unique parcial cross-driver
- **`database/migrations/2026_07_07_100002_alter_nps_surveys_add_template_id.php`** (70 linhas) — Adiciona coluna template_id posicionada após `auto_generated`
- **`database/migrations/2026_07_07_100003_alter_nps_responses_scores_nullable.php`** (88 linhas) — Torna score_estrategista + score_empresa NULLABLE (score_analista intocada — já era nullable)

## Comando para rodar em produção

```bash
# No VPS (após deploy autorizado — deploy gate ativo)
php artisan migrate --force
```

Sequência aplicada pelo Laravel:
1. `2026_07_07_100001` — cria 5 tabelas
2. `2026_07_07_100002` — adiciona `nps_surveys.template_id`
3. `2026_07_07_100003` — torna `nps_responses.score_estrategista/empresa` NULLABLE

**Impacto em prod:** zero downtime esperado (SQLite/MySQL alteram metadata sem tocar dados). Rows existentes em `nps_responses` mantêm os valores atuais em `score_*`; novas rows da Phase 69 gravarão NULL nessas colunas e persistirão em `nps_response_answers`.

## Verificação de schema

Após migrate em SQLite in-memory (`testing`):

```
=== 5 tabelas novas ===
  nps_templates: OK
  nps_template_questions: OK
  nps_template_options: OK
  nps_template_service_scopes: OK
  nps_response_answers: OK
=== nps_surveys.template_id ===
  OK
=== nps_responses.score_* NULLABLE ===
  score_estrategista nullable: OK
  score_empresa nullable: OK
=== Indices nomeados ===
  nps_ans_response_dim_idx: OK
  nps_tpl_scope_uniq: OK
  nps_templates_default_uniq: OK
  nps_templates_active_priority_idx: OK
```

## Decisions Made

- **Snapshot per-row com 4 colunas explícitas** (não JSON blob): garante índice em `question_dimensao_snapshot` — dashboard NPS-E-05 vai filtrar por essa coluna com WHERE + GROUP BY sem re-parse de JSON
- **Unique parcial cross-driver via `DB::statement`** (não `Schema::virtualAs()`): elimina bug conhecido do Laravel Schema builder com SQLite in-memory
- **FK `template_id` nullable + `nullOnDelete`**: rollback intermediário da milestone deixa surveys funcionais; hard-delete futuro do template não corrompe histórico (snapshot é a verdade)
- **`down()` da migration 100003 é no-op**: reverter para NOT NULL destruiria rows legítimas NULL (padrão consolidado Phase 31 D-10)

## Deviations from Plan

None — plan executado exatamente como escrito. Ordem das FKs, nomes de índices, guards de idempotência e down() defensivo seguiram o plan sem desvios.

## Issues Encountered

- **Falha pré-existente em `Phase33OnboardingFichaTest > padroes_expoem_mensagem_e_grants_padrao`** detectada durante baseline: falha persiste **antes** das minhas migrations (confirmado via `git stash --include-untracked` + re-run). Não relacionada a NPS/Phase 68. Deferred to next quick task fora do escopo desta wave.
- **Suite Phase 31 NPS legada** (19 testes) permanece 100% verde após as 3 migrations — zero regressão introduzida.

## User Setup Required

Nenhum — as migrations são puramente schema. Nenhuma variável de ambiente nova, nenhum serviço externo, nenhum job/scheduler novo. Deploy quando autorizado roda `php artisan migrate --force` e schema fica pronto.

## Next Phase Readiness

**Wave 2 (paralela)** pode arrancar imediatamente após esta wave commitar:

- **Plan 68-02 — Models + factories** (depende deste plan; provê classes Eloquent para 68-03/04/05)
- **Plan 68-04 — dedup_key composto** (depende deste plan por precisar de `nps_surveys.template_id`; provê constraint anti-duplicata para Phase 69/72)

**Wave 3 (sequencial após 68-02):**
- **Plan 68-03 — Seed retro "NPS Padrão"** (depende de 68-02 para usar factory/model + deste plan para as tabelas)

**Wave 4:**
- **Plan 68-05 — Testes Feature do schema completo**

**Zero blockers.** Deploy autorizado precisa vir do usuário antes do plan hit prod (deploy gate ativo).

## Self-Check: PASSED

- [x] `database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php` existe
- [x] `database/migrations/2026_07_07_100002_alter_nps_surveys_add_template_id.php` existe
- [x] `database/migrations/2026_07_07_100003_alter_nps_responses_scores_nullable.php` existe
- [x] Commit `f84df0f` presente em `git log`
- [x] Commit `e674195` presente em `git log`
- [x] Commit `9fbd985` presente em `git log`
- [x] 5 tabelas criadas (verificado via `Schema::hasTable` em cada uma)
- [x] `nps_surveys.template_id` existe (verificado via `Schema::hasColumn`)
- [x] `nps_responses.score_estrategista/empresa` nullable (verificado via `sqlite_master.sql`)
- [x] 4 índices nomeados presentes (`nps_ans_response_dim_idx`, `nps_tpl_scope_uniq`, `nps_templates_default_uniq`, `nps_templates_active_priority_idx`)
- [x] Unique parcial de `is_default` bloqueia 2º INSERT com `is_default=true` (SQLSTATE 23000)
- [x] Idempotência: `migrate:fresh → rollback --step=3 → migrate` sem erros
- [x] Suite Phase 31 NPS: 19/19 verdes (zero regressão)

---

*Phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o*
*Plan: 01 — Wave 1 de 4*
*Completed: 2026-07-07*
