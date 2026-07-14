---
phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
plan: 01
subsystem: NPS multi-modelo — persistência do snapshot congelado
tags: [nps, snapshot, migration, eloquent, cross-driver, v16]
requires: [nps_responses, companies, servicos, users]
provides:
  - nps_response_scores (tabela + model NpsResponseScore)
  - nps_response_covered_services (tabela + model NpsResponseCoveredService)
  - nps_score_assignments (tabela + model NpsScoreAssignment)
affects: [79-04 (NpsSnapshotService), Fase 80 (bônus/relatórios)]
tech-stack:
  added: []
  patterns: [migration cross-driver SQLite+MySQL, snapshot congelado append-only]
key-files:
  created:
    - database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php
    - app/Models/NpsResponseScore.php
    - app/Models/NpsResponseCoveredService.php
    - app/Models/NpsScoreAssignment.php
    - tests/Feature/V16/SnapshotSchemaTest.php
  modified:
    - app/Models/NpsResponse.php
decisions:
  - "role em nps_score_assignments persiste o valor da pivot company_users ('consultor'/'estrategista') — JOIN direto na Fase 80 (DEC-79-C / A3)"
  - "enum dimensao só [estrategista, analista, empresa] — 'geral' não gera score de pessoa nem atribuição"
  - "servico_id usa nullOnDelete (preserva service_setor congelado); nps_response_id/company_id/user_id/nps_response_score_id usam cascadeOnDelete"
metrics:
  duration: ~6min
  completed: 2026-07-14
  tasks: 3
  files: 6
---

# Phase 79 Plan 01: Fundação de Persistência do Snapshot NPS Multi-Modelo Summary

Criadas as 3 tabelas do snapshot congelado do NPS multi-modelo (`nps_response_scores`,
`nps_response_covered_services`, `nps_score_assignments`) via 1 migration cross-driver e os
3 Eloquent models correspondentes — contrato imutável que o `NpsSnapshotService` (79-04)
preencherá no submit e a Fase 80 consumirá para bônus/relatórios.

## O que foi feito

- **Tarefa 1 (RED):** `tests/Feature/V16/SnapshotSchemaTest.php` no namespace `Tests\Feature\V16`
  com `RefreshDatabase` — asserts de existência das 3 tabelas, das colunas exatas de DEC-79-C e
  do cascade delete (apagar `nps_responses` zera as 3 filhas). Falhou como esperado (tabelas
  inexistentes) antes da migration.
- **Tarefa 2 (GREEN):** migration `2026_07_14_200001_create_nps_snapshot_tables.php` com `Schema::create`
  das 3 tabelas na ordem scores → covered_services → assignments (respeita a FK
  `nps_response_score_id`). FKs via `foreignId()->constrained()` com `cascadeOnDelete`/`nullOnDelete`
  conforme DEC-79-C, uniques (`nps_resp_scores_dim_uniq`, `nps_resp_cov_serv_uniq`) e índices
  (`(company_id, dimensao)`, `nps_score_assign_user_role_idx`, `nps_score_assign_setor_idx`).
  `down()` dropa na ordem inversa.
- **Tarefa 3:** models `NpsResponseScore`, `NpsResponseCoveredService`, `NpsScoreAssignment` com
  fillable explícito, casts (decimal→float, snapshot timestamp→datetime) e relations
  (belongsTo/hasMany), incluindo o key_link `NpsScoreAssignment::score()` →
  `NpsResponseScore` via `nps_response_score_id`. Relations `hasMany` inversas adicionadas em
  `NpsResponse` (`scores()`, `coveredServices()`, `scoreAssignments()`).

## Decisões

- **`role` = valor da pivot** — a coluna `role` de `nps_score_assignments` é `enum('consultor','estrategista')`,
  persistindo exatamente o valor de `company_users` (não analyst/strategist), para JOIN direto no bônus
  da Fase 80 (DEC-79-C / A3 do RESEARCH).
- **enum `dimensao` sem 'geral'** — só `[estrategista, analista, empresa]`, pois 'geral' não gera score
  de pessoa nem atribuição; casa com `question_dimensao_snapshot` de `nps_response_answers`.
- **`service_setor` congelado + `servico_id` nullOnDelete** — o setor do serviço é gravado como string
  histórica; remover o serviço do catálogo não corrompe o snapshot.

## Cross-driver

Migration usa apenas `enum` simples e `foreignId()->constrained()` — sem `virtualAs`/índice parcial
(desnecessário aqui). FK em `CREATE TABLE` funciona no SQLite dos testes e no MySQL de produção; o
gotcha 1553 (índice usado por FK) só aparece em `ALTER`, não é o caso. A validação MySQL real
(`SHOW CREATE TABLE`) permanece como item Manual-Only da 79-VALIDATION no VPS.

## Deviations from Plan

### Auto-added (Rule 2 - functionality crítica)

**1. [Rule 2] Teste de contrato Eloquent adicionado ao SnapshotSchemaTest**
- **Encontrado durante:** Tarefa 3
- **Motivo:** o teste de schema da Tarefa 1 usa `DB::table` puro e NÃO exercita os models; o `done`
  da Tarefa 3 exige provar que "os 3 models resolvem e mapeiam corretamente". Adicionado o método
  `test_models_mapeiam_tabelas_e_relations` que cria registros via Eloquent e verifica casts + relations
  (incluindo o key_link `assignment.score`).
- **Arquivos:** `tests/Feature/V16/SnapshotSchemaTest.php`
- **Commit:** 14821dc

**2. [Rule 2] Relations hasMany inversas em NpsResponse**
- **Encontrado durante:** Tarefa 3 (read_first apontava NpsResponse.php "para adicionar as relações hasMany inversas")
- **Motivo:** completar o contrato bidirecional consumido pelo NpsSnapshotService/Fase 80.
- **Arquivos:** `app/Models/NpsResponse.php`
- **Commit:** 14821dc

## Verificação

- `/c/xampp/php/php.exe artisan test tests/Feature/V16/SnapshotSchemaTest.php` → 4 passed (18 assertions).
- `/c/xampp/php/php.exe artisan test tests/Feature/V16` → **39 passed (161 assertions)** — suite V16 verde, sem regressão.
- Cascade delete comprovado no teste (deletar `nps_responses` zera as 3 filhas).

## Known Stubs

Nenhum. As tabelas e models são fundação de schema (sem lógica de negócio por design — o
preenchimento vem no Plano 79-04).

## Commits

- `9859037` — test(79-01): teste RED do schema das 3 tabelas de snapshot NPS
- `83aec9f` — feat(79-01): migration cross-driver das 3 tabelas de snapshot NPS
- `14821dc` — feat(79-01): models Eloquent das 3 tabelas de snapshot NPS

## Self-Check: PASSED
