---
phase: 46-historico-longitudinal-scores-desempenho
plan: "46-01"
status: complete
completed_at: 2026-06-30
wave: 1
type: execute
requirements:
  - REQ-46-01
files_created:
  - database/migrations/2026_06_30_000001_create_desempenho_score_snapshots_table.php
  - app/Models/DesempenhoScoreSnapshot.php
  - app/Console/Commands/SnapshotDesempenhoScores.php
  - tests/Feature/DesempenhoScoreSnapshotTest.php
files_modified:
  - routes/console.php
commits:
  - e2a11fb test(46-01) RED — testes para snapshot diário de scores
  - 9f89436 feat(46-01) migration desempenho_score_snapshots
  - 2912427 feat(46-01) comando + model + schedule 13:30 BRT
tests:
  passed: 8
  failed: 0
  assertions: 34
provides:
  - "Tabela desempenho_score_snapshots com unique(user_id,ref_date) — base do histórico longitudinal"
  - "Comando Artisan desempenho:snapshot-scores idempotente (updateOrCreate)"
  - "Schedule diário 13:30 BRT após cascata D-1 (SyncPolosFaturamentoJob 13:00)"
  - "Model DesempenhoScoreSnapshot com cast breakdown_json => array"
next:
  - "Wave 2 (46-02): leitura dos snapshots → enriquece /performance com delta_vs_ontem/semana"
---

# Phase 46 Plan 46-01 — Snapshot diário de scores: SUMMARY

## Entregue

Backbone de persistência do score de desempenho: tabela enxuta + model com cast +
comando Artisan iterando os mesmos users do `PerformanceController::index()` +
schedule diário 13:30 BRT logo após o fim da cascata D-1.

## Commits (3)

| SHA       | Tipo  | Mensagem                                                            |
| --------- | ----- | ------------------------------------------------------------------- |
| `e2a11fb` | test  | RED — 8 testes que falham (tabela + comando ainda não existem)      |
| `9f89436` | feat  | Migration `desempenho_score_snapshots` (unique user_id/ref_date)    |
| `2912427` | feat  | Model + Command `desempenho:snapshot-scores` + schedule 13:30 BRT   |

## Arquivos novos

- `database/migrations/2026_06_30_000001_create_desempenho_score_snapshots_table.php`
- `app/Models/DesempenhoScoreSnapshot.php`
- `app/Console/Commands/SnapshotDesempenhoScores.php`
- `tests/Feature/DesempenhoScoreSnapshotTest.php`

## Arquivos modificados

- `routes/console.php` — adicionada entry `desempenho-snapshot-scores` (dailyAt 13:30
  BRT, onOneServer, withoutOverlapping)

## Resultado dos testes

```
Tests:    8 passed (34 assertions)
Duration: 1.27s
```

Cobertura:
- `test_tabela_tem_colunas_esperadas` — schema completo
- `test_unique_constraint_user_id_ref_date` — QueryException em duplicata
- `test_command_persiste_snapshot_por_user_eligivel` — 2 users analista/estrategista → 2 linhas
- `test_command_idempotente_re_run_atualiza_nao_duplica` — 2 runs no mesmo dia = 1 linha atualizada
- `test_breakdown_json_persistido_como_array` — cast funciona e contém chaves de `metricas`
- `test_ranking_pos_ordenado_por_score_desc` — scores 50/80/65 → ranking_pos 3/1/2
- `test_ignora_users_sem_cargo_analista_ou_estrategista` — publicador NÃO entra no snapshot
- `test_filtro_user_isolado_via_opcao` — `--user=X` snapshota apenas X

## Verificações de smoke

```
$ php artisan list | grep desempenho
desempenho:snapshot-scores  Grava snapshot diário do score de desempenho...

$ php artisan schedule:list | grep desempenho
30 13 * * *  php artisan desempenho:snapshot-scores  ........ Next Due: em 2 horas
```

## Desvios do plano

Nenhum desvio funcional. 3 ajustes técnicos de execução documentados nos commits:

1. **`whereDate` em vez de `where('ref_date', $str)`** — SQLite armazena `date`
   castado como `'YYYY-MM-DD 00:00:00'`, então comparações com `'YYYY-MM-DD'`
   string falham. Solução portável: `whereDate()` no Eloquent + `DATE(ref_date) = ?`
   no SQL bruto do `popularRankingPos()`. **Funciona idêntico em MariaDB prod**.

2. **`updateOrCreate` reescrito como lookup + fill/create explícitos** — pelo
   mesmo motivo do item 1: o key match interno do `updateOrCreate` comparava
   string contra valor cast. Usar `whereDate` no lookup garante idempotência
   real em ambos os drivers.

3. **`popularRankingPos` portável** — branch MariaDB usa `ROW_NUMBER() OVER (ORDER BY
   score DESC, id ASC)` em 1 query; fallback iterativo (SQLite/PostgreSQL) faz N
   updates. Tiebreaker por `id ASC` garante determinismo do ranking.

## success_criteria — status

- [x] Migration `2026_06_30_000001_create_desempenho_score_snapshots_table.php` criada
      com `unique(['user_id','ref_date'])` e `index(['ref_date','score'])`
- [x] Model `DesempenhoScoreSnapshot` com cast `breakdown_json => array` e `$fillable` completo
- [x] Comando `desempenho:snapshot-scores` registrado, com DI de `PortfolioScoreService`
      e `ranking_pos` populado pós-lote
- [x] Schedule entry em `routes/console.php` às 13:30 BRT com `withoutOverlapping()` +
      `onOneServer()`
- [x] Idempotência: re-run no mesmo dia atualiza linha existente (unique constraint impede duplicata)
- [x] Testes PHPUnit GREEN para: schema, unique, comando persiste, idempotência,
      `breakdown_json` array cast, `ranking_pos` ordenado, filtro de cargos
- [x] Commits separados RED → GREEN para cada bloco TDD

## Próximo

Wave 2 — Plan 46-02: enriquecer `PerformanceController::index()` lendo desta tabela
para calcular `delta_vs_ontem` e `delta_vs_semana_passada`; UI ganha 2 micro-indicadores
na coluna do score.
