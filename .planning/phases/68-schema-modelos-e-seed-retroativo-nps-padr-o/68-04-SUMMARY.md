---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
plan: 04
subsystem: database
tags: [nps, migrations, sqlite, mysql, mariadb, unique-index, dedup, laravel-12]

# Dependency graph
requires:
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 01
    provides: "Coluna nps_surveys.template_id (FK nullable) — componente do dedup_key composto"
provides:
  - "Índice UNIQUE PARCIAL `nps_surveys_dedup_uniq` bloqueia INSERT/UPDATE de segunda survey COMPLETED com mesma (company_id, month_reference, template_id)"
  - "Semântica idêntica cross-driver: SQLite (partial unique index) + MySQL/MariaDB (virtual generated column dedup_key + UNIQUE INDEX)"
  - "Base para guard de UX no Phase 69 NpsController (captura QueryException 23000 → tela 'já respondida no mês')"
affects:
  - 68-05-testes-schema (testes de bloqueio DB-level)
  - phase-69 (backend NpsController::submit guard 23000)
  - phase-72 (dispatcher nps:disparar-mensal — idempotência garantida no DB)

# Tech tracking
tech-stack:
  added: []  # zero deps
  patterns:
    - "Unique parcial cross-driver: DB::connection()->getDriverName() → CREATE UNIQUE INDEX ... WHERE (SQLite) vs virtual generated column + UNIQUE (MySQL)"
    - "NULL não colide com NULL em UNIQUE — surveys pending/expired ficam livres sem exigir CHECK ou trigger"
    - "Ordem timestamp críticas: 100005 roda APÓS 100004 (seed retro-associativo do Plan 68-03) para evitar detectar conflitos legados antes do backfill de template_id"
    - "Idempotência dupla: sqlite_master query (SQLite) + Schema::hasColumn (MySQL)"
    - "DATE_FORMAT(month_reference, '%Y-%m') no CONCAT do dedup_key — imune a variação de dia (defensivo além do YYYY-MM-01 do Phase 31)"

key-files:
  created:
    - "database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php"
  modified: []

key-decisions:
  - "Split por driver via DB::connection()->getDriverName() em vez de Schema::table()->virtualAs() — este último emite sintaxe incompatível com SQLite in-memory (research §2)"
  - "Virtual generated column em MySQL em vez de real column + trigger — MySQL recalcula na leitura sem custo em disco e sem risco de dessincronização"
  - "CONCAT com DATE_FORMAT '%Y-%m' + COALESCE(template_id, 0) — defensivo contra dia variável e template_id NULL residual antes do seed 68-03 completar"
  - "Timestamp 100005 (não 100003 ou 100006) — a lacuna deliberada em 100004 reserva slot para o seed do Plan 68-03; ordem preserva integridade em prod"
  - "down() do MySQL usa try/catch no DROP INDEX — permite reverter estado parcial em cenário de rollback intermediário"

patterns-established:
  - "Unique parcial semanticamente condicional (só bloqueia quando row atende predicado) via virtual generated column + NULL-em-UNIQUE — reusável em qualquer tabela com 'status terminal' que precise dedup"
  - "Sanity funcional de partial unique em SQLite via 5 cenários canônicos (pending×pending, completed×none, completed×completed, template diferente, mês diferente) — protocolo reproduzível pra próximos partial indexes"

requirements-completed: [NPS-A-01]  # parte 2/2 — schema dedup completo

# Metrics
duration: 12min
completed: 2026-07-07
---

# Phase 68 Plan 04: Dedup Key `nps_surveys` Summary

**Unique parcial em `(company_id, month_reference, template_id)` bloqueia DB-level a segunda tentativa de responder NPS pra mesma empresa/mês/template quando a survey já está completa — split por driver (partial index em SQLite, virtual column + UNIQUE em MySQL) mantém semântica idêntica em dev/test e prod.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-07-07 (paralelo a 68-02)
- **Completed:** 2026-07-07
- **Tasks:** 1
- **Files created:** 1 migration
- **Files modified:** 0

## Accomplishments

- **Migration `2026_07_07_100005_add_dedup_key_to_nps_surveys.php` criada** (147 linhas) implementando o split por driver do research §2
- **SQLite path (dev/test):** `CREATE UNIQUE INDEX nps_surveys_dedup_uniq ON nps_surveys(company_id, month_reference, template_id) WHERE status = 'completed' AND completed_at IS NOT NULL` — partial unique index nativo (SQLite 3.31+)
- **MySQL/MariaDB path (prod):** `ALTER TABLE nps_surveys ADD COLUMN dedup_key VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN status='completed' AND completed_at IS NOT NULL THEN CONCAT(company_id, '|', DATE_FORMAT(month_reference, '%Y-%m'), '|', COALESCE(template_id, 0)) END) VIRTUAL` + `UNIQUE INDEX nps_surveys_dedup_uniq (dedup_key)` — virtual generated column + unique regular
- **Idempotência confirmada:** `sqlite_master` query antes de `CREATE UNIQUE INDEX` no SQLite; `Schema::hasColumn` antes de `ALTER TABLE` no MySQL
- **Bloqueio DB-level validado em SQLite** (6 cenários canônicos passaram — ver seção "Verificação de schema")
- **Zero regressão** — 29/29 testes NPS existentes verdes após aplicar migration

## Task Commits

1. **Task 1: Migration split por driver — dedup_key + unique parcial** — vide hash abaixo

## Files Created

- **`database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php`** (147 linhas)
  - Docblock pt-BR extensivo referenciando research §2, Phase 68 Plan 04, REQ NPS-A-01 e NPS-B-03
  - `up()` — split por driver com guard de idempotência em ambos os paths
  - `down()` — SQLite dropa índice; MySQL dropa índice (try/catch defensivo) + coluna virtual
  - Comentários explicando: por que virtual column vs Schema::virtualAs, por que DATE_FORMAT, por que ordem timestamp 100005

## Comando para rodar em produção

```bash
# No VPS (após deploy autorizado — deploy gate ativo)
php artisan migrate --force
```

**Impacto em prod (MySQL/MariaDB):** ALTER TABLE em `nps_surveys` para adicionar coluna virtual + unique index. Coluna virtual é metadata-only (não regrava dados existentes), mas o UNIQUE INDEX faz um scan completo da tabela para verificar uniqueness. Em `nps_surveys` (tabela pequena — algumas centenas de rows), latência esperada <1s. **Recomendação:** rodar em janela de manutenção, comunicar time antes.

## Verificação de schema

Após `migrate:fresh --env=testing` (SQLite in-memory):

```
=== VERIFICACOES DO PARTIAL UNIQUE INDEX ===
1) Indice presente: SIM
   WHERE clause presente: SIM   (status = 'completed' AND completed_at IS NOT NULL)
2) 2 surveys PENDING mesma tupla: ACEITO (esperado)
3) 1 survey COMPLETED insert: ACEITO (esperado)
4) 2o COMPLETED duplicado BLOQUEADO com QueryException code=23000 (23xxx=SIM)
5) COMPLETED em template DIFERENTE: ACEITO (esperado)
6) COMPLETED em mes DIFERENTE: ACEITO (esperado)

=== TESTE DE IDEMPOTENCIA ===
1) Apos rollback --step=1: indice REMOVIDO (OK)
2) Apos re-migrate: indice RECRIADO (OK)
3) Segundo migrate (nada pendente): OK
4) Dois migrate:fresh seguidos: OK; indice presente = SIM

=== BASELINE PHASE 31 NPS ===
Tests: 29, Assertions: 172 — 100% verdes (zero regressão)
```

## Decisions Made

- **Split por driver via `DB::connection()->getDriverName()`** (não `Schema::table()->virtualAs()`): elimina bug conhecido de sintaxe incompatível com SQLite in-memory (research §2)
- **Virtual generated column em MySQL** (não real column + trigger): recalculada na leitura, sem custo em disco, sem risco de dessincronização com `status`/`completed_at`
- **`COALESCE(template_id, 0)`** no CONCAT do MySQL: defensivo contra template_id NULL residual antes do seed 68-03 populá-lo. Como o dedup_key também exige `status='completed' AND completed_at IS NOT NULL`, na prática nunca vai gerar chave com `|0` (Phase 69 nunca completa survey sem template resolvido), mas garante que o CONCAT nunca retorna NULL parcial por template_id ausente
- **`DATE_FORMAT(month_reference, '%Y-%m')`** em vez de `CAST(month_reference AS CHAR)`: garante formato consistente independente de storage type — MySQL guarda DATE como YYYY-MM-DD, e usar só year-month protege o dedup_key de variação do dia (defensivo, já que Phase 31 grava sempre YYYY-MM-01)
- **Timestamp `100005`** (com gap em 100004): reserva slot deliberado para o seed do Plan 68-03 que ainda vai ser criado; garante ordem `100001 (schema) → 100002 (template_id FK) → 100003 (score nullable) → 100004 (seed retro) → 100005 (unique parcial)` em prod
- **`down()` do MySQL usa try/catch no DROP INDEX**: permite reverter estado parcial (índice já removido manualmente mas coluna ainda existe) sem travar

## Deviations from Plan

None — plan executado conforme escrito. A única adição defensiva foi o `COALESCE(template_id, 0)` no path MySQL (Rule 2: código correto exige que CONCAT nunca produza NULL parcial que quebre a comparação de UNIQUE). Semântica preservada: `template_id NULL` só ocorre em surveys pending, e essas nem entram no CASE WHEN, então na prática o COALESCE é dead code — mas resiliente contra edge cases de dados históricos malformados.

## Issues Encountered

- Nenhum bloqueador. Testes do Phase 31 e verificações funcionais passaram na primeira execução.

## Known Stubs

Nenhum stub. Migration entrega comportamento final — dedup key é infraestrutura DB-level completa. O guard do controller vem na Phase 69 (fora do escopo desta wave).

## User Setup Required

Nenhum — migration é puramente schema. Nenhuma env var nova, nenhum job/scheduler. Deploy quando autorizado roda `php artisan migrate --force`.

## Next Phase Readiness

- **Wave 3 (sequencial) — Plan 68-03 (seed retro-associativo)**: pode arrancar agora que dedup key está em vigor (e será backfilled em ordem correta pelo timestamp 100004 < 100005)
- **Wave 4 — Plan 68-05 (testes Feature)**: vai reproduzir os 6 cenários canônicos validados aqui via `Tests\Feature\NpsDedupKeyTest`
- **Phase 69 — Backend `NpsController::submit`**: guard `try/catch QueryException` capturando `$e->getCode() === '23000'` e redirecionando pra tela "Já respondida no mês"

**Zero blockers.** Deploy autorizado precisa vir do usuário antes do plan hit prod.

## Self-Check: PASSED

- [x] `database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php` existe
- [x] `php -l` passou sem erros de sintaxe
- [x] `grep getDriverName` presente
- [x] `grep "CREATE UNIQUE INDEX"` presente (SQLite path)
- [x] `grep "GENERATED ALWAYS AS"` presente (MySQL path)
- [x] `grep "nps_surveys_dedup_uniq"` presente (nome de índice canônico do research §2)
- [x] `grep VIRTUAL` presente
- [x] `grep sqlite_master` presente (idempotência SQLite)
- [x] `grep "Schema::hasColumn"` presente (idempotência MySQL)
- [x] `migrate:fresh --env=testing` roda sem erro em SQLite
- [x] Índice `nps_surveys_dedup_uniq` existe após migrate (validado via sqlite_master)
- [x] Índice contém WHERE `status = 'completed' AND completed_at IS NOT NULL`
- [x] 2 surveys pending mesma tupla ACEITAS
- [x] 1 survey completed ACEITA + 2ª completed duplicada BLOQUEADA com QueryException code=23000
- [x] COMPLETED em template diferente ACEITO
- [x] COMPLETED em mês diferente ACEITO
- [x] `migrate:rollback --step=1 → migrate` funciona sem erro
- [x] 2 `migrate:fresh` seguidos funcionam sem erro
- [x] Baseline Phase 31 NPS: 29/29 verdes (zero regressão)

---

*Phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o*
*Plan: 04 — Wave 2 de 4 (paralelo a 68-02)*
*Completed: 2026-07-07*
