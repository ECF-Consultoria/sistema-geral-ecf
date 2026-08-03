---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plan: 01
subsystem: desempenho-persistencia
tags: [migration, model, service, tdd, sqlite-pitfall]
requires: []
provides:
  - "tabela desempenho_company_score_snapshots (unique user_id+company_id+mes_referencia)"
  - "App\\Models\\DesempenhoCompanyScoreSnapshot"
  - "App\\Services\\Desempenho\\CompanyScoreSnapshotWriter::sync()"
affects:
  - "Wave 2 (122-02/03/04): os três comandos que vão chamar CompanyScoreSnapshotWriter::sync()"
tech-stack:
  added: []
  patterns:
    - "upsert manual com whereDate() em vez de updateOrCreate() com coluna date crua na chave de busca"
    - "trava de congelamento por origem com lockForUpdate() dentro da mesma transação da escrita"
key-files:
  created:
    - database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php
    - app/Models/DesempenhoCompanyScoreSnapshot.php
    - app/Services/Desempenho/CompanyScoreSnapshotWriter.php
    - tests/Feature/Phase122/CompanyScoreSnapshotWriterTest.php
    - tests/Feature/Phase122/CompanyScoreSnapshotSchemaTest.php
  modified: []
decisions:
  - "D-122-01/02/03/08 implementadas literalmente conforme 122-01-PLAN.md (mes_referencia sempre YYYY-MM-01, trava de congelamento por origem, upsert+prune numa transação, lockForUpdate na leitura que decide o congelamento)"
  - "Deviation (Rule 1): updateOrCreate() com mes_referencia cru na chave de busca não funciona sob SQLite com o cast `date` do model — trocado por find manual com whereDate() + fill()/save()/create()"
metrics:
  duration: "~30min"
  completed: "2026-08-03"
---

# Phase 122 Plan 01: Fundação de persistência por empresa Summary

Tabela `desempenho_company_score_snapshots` + model + `CompanyScoreSnapshotWriter::sync()` idempotente (upsert/prune/trava de congelamento), base para os três comandos da Wave 2.

## O que foi entregue

**Task 1 — Migration e model.** Tabela `desempenho_company_score_snapshots` com `unique(user_id, company_id, mes_referencia)` e dois índices de leitura (`mes_referencia+user_id`, `company_id+mes_referencia`). Colunas de status/origem/fonte financeira em STRING (sem CHECK — evita quebra do SQLite dos testes ao surgir valor novo). Variações (`faturamento_var_pct`, `margem_var_pp`, `margem_pct_atual`, `margem_pct_anterior`) em `decimal(14,6)` para não achatar sub-0,01. FKs `user_id`/`company_id` NOT NULL com `cascadeOnDelete`, sem `nullOnDelete` (evita 1830 no MariaDB de produção). Model `DesempenhoCompanyScoreSnapshot` com casts float nos campos numéricos, `quality` como `array`, scopes `scopeDaCompetencia()`/`scopeDoUsuario()`, sem `LogsActivity` (mesmo padrão das tabelas de auditoria por comando da Fase 121).

**Task 2 — `CompanyScoreSnapshotWriter::sync()` (TDD).** RED: suíte `CompanyScoreSnapshotWriterTest` escrita primeiro, confirmada falhando (9 testes, classe inexistente). GREEN: serviço implementado — `sync(User $user, Carbon $mes, iterable $empresasScore, string $origem): array` retorna `['upserted' => int, 'pruned' => int, 'congelado' => bool]`. Dentro de uma única `DB::transaction()`: (1) se `$origem !== ORIGEM_CONSOLIDAR_MES`, verifica com `lockForUpdate()` se já existe row de `consolidar_mes` na competência — se sim, retorna `congelado: true` sem tocar em nada (D-122-02/D-122-08); (2) upsert por `(user_id, company_id, mes_referencia)`; (3) prune das rows do par `(user, competência)` fora do conjunto recém-gravado, com o caso de coleção vazia escrito explicitamente (`whereDate()` + delete total, não depende do comportamento implícito de `whereNotIn([])`). Nenhum cálculo é feito — o serviço só persiste o shape que recebe.

**Task 3 — Suítes de schema e de comportamento.** `CompanyScoreSnapshotSchemaTest` (6 testes): colunas existem, chave única barra duplicata mas não barra mudança de competência, round-trip de precisão em `margem_var_pp` (delta 1e-6), round-trip de `quality` via cast array, `status` aceita string arbitrária. `CompanyScoreSnapshotWriterTest` (9 testes): cobre todos os itens do `<behavior>` da Task 2 mais os dois cenários extras da Task 3 (isolamento entre usuários/competências, idempotência forte comparando o conjunto completo de valores, não só a contagem).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `updateOrCreate()` com `mes_referencia` cru na chave de busca nunca encontra a row já gravada, sob SQLite**
- **Encontrado durante:** Task 2, fase GREEN (2ª chamada de `sync()` na mesma competência falhava com `UniqueConstraintViolationException`, mesmo tendo passado a 1ª chamada).
- **Causa:** o cast `date` do model `Illuminate\Database\Eloquent\Model::setAttribute()` grava a coluna como datetime completo (`'2026-06-01 00:00:00'`), não como `'2026-06-01'`. `updateOrCreate()` monta a cláusula `WHERE` com o array de busca passado literalmente (`'mes_referencia' => $mesStr`, string `'2026-06-01'` crua) — no MariaDB de produção a coluna `DATE` trunca o componente de hora e o valor bateria; no SQLite (testes) a coluna não trunca nada, então a comparação nunca casa e cada chamada tenta `INSERT` de novo, colidindo com a chave única.
- **Fix:** substituído por busca manual com `->whereDate('user_id', ...)->whereDate('mes_referencia', $mesStr)->first()` seguida de `fill()->save()` ou `create()`. `whereDate()` compara só a parte de data, imune ao formato de armazenamento em qualquer driver. A mesma troca foi aplicada na consulta de congelamento e na query de prune.
- **Nota para a Wave 2:** o padrão existente `DesempenhoScoreSnapshot::updateOrCreate([...'mes_referencia'=>$mesStr...], [...])` em `ConsolidarMesDesempenho.php:214` usa a MESMA construção arriscada — não foi tocado nesta task (fora do escopo do plano), mas se algum teste futuro exercitar reconsolidação dupla daquele comando sob SQLite, pode expor o mesmo sintoma. Registrado aqui para quem for tocar naquele arquivo.
- **Arquivos modificados:** `app/Services/Desempenho/CompanyScoreSnapshotWriter.php`, `tests/Feature/Phase122/CompanyScoreSnapshotWriterTest.php` (as próprias asserções do teste também usavam `where('mes_referencia', ...)` cru e precisaram do mesmo ajuste para `whereDate()`).
- **Commit:** `748e6033`

Nenhum outro desvio.

## TDD Gate Compliance

RED confirmado (commit `8762f411`, teste criado antes do serviço — rodado e comprovado 9 failed por classe inexistente antes do commit). GREEN confirmado (commit `748e6033`, 15/15 testes Phase122 verdes após o serviço + o fix de `whereDate()`). Nenhum commit de REFACTOR foi necessário.

## Verificação

- `php artisan test --filter=Phase122` — 15/15 verde (6 schema + 9 writer)
- `php artisan test --filter=Desempenho` — 14 failed / 101 passed (baseline exata herdada, zero regressão nova)
- `php artisan migrate:fresh` contra SQLite real (arquivo, não `:memory:`) rodou limpo, migration da Fase 122 aplicada em 1.12ms sem erro
- `grep -c "performance_company_first_score" app/Services/Desempenho/CompanyScoreSnapshotWriter.php` → 0
- `grep -c "nullOnDelete" database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` → 0
- `grep -c "lockForUpdate" app/Services/Desempenho/CompanyScoreSnapshotWriter.php` → 2 (check de congelamento)
- `grep -c "LogsActivity" app/Models/DesempenhoCompanyScoreSnapshot.php` → 0
- `git status --short` nos diretórios do plano mostra exatamente os 5 arquivos deste plano — `DesempenhoScoreService.php`, os 3 comandos da Wave 2 e `BonusAuditoriaController.php` intocados

## Known Stubs

Nenhum. O writer é consumido apenas pelos testes desta task até a Wave 2 (122-02/03/04) plugar os três comandos.

## Self-Check: PASSED

- FOUND: database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php
- FOUND: app/Models/DesempenhoCompanyScoreSnapshot.php
- FOUND: app/Services/Desempenho/CompanyScoreSnapshotWriter.php
- FOUND: tests/Feature/Phase122/CompanyScoreSnapshotWriterTest.php
- FOUND: tests/Feature/Phase122/CompanyScoreSnapshotSchemaTest.php
- FOUND commit e65b8234 (Task 1)
- FOUND commit 8762f411 (Task 2 RED)
- FOUND commit 748e6033 (Task 2 GREEN)
- FOUND commit 5775c1e9 (Task 3)
