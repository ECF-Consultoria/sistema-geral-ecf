---
phase: 08-funda-o-de-notifica-es
plan: 01
subsystem: notificacoes
tags: [storage, migration, foundation, tdd]
requires: []
provides:
  - tabela `notifications` (schema canônico Laravel 12)
  - suíte `Tests\Feature\Notifications\Phase8FoundationTest` ancorada
affects:
  - database/migrations/
  - tests/Feature/Notifications/
tech_added:
  - canal `database` de Notifications (Laravel 12 nativo)
patterns_used:
  - migration anônima Laravel 11+ (`return new class extends Migration`)
  - polimorfismo via `morphs` (notifiable_id + notifiable_type + índice composto)
  - cast `array` no DatabaseNotification para round-trip JSON sobre coluna `text`
key_files:
  created:
    - database/migrations/2026_05_21_100001_create_notifications_table.php
    - tests/Feature/Notifications/Phase8FoundationTest.php
    - .planning/phases/08-funda-o-de-notifica-es/deferred-items.md
  modified: []
key_decisions:
  - "Reconciliação D-05: coluna `data` é `text` (não `json`) — forma canônica do stub Laravel 12; cast `array` do DatabaseNotification cuida do round-trip JSON. Documentado inline na PHPDoc da migration."
  - "Test 1 implementado com 9 asserts (1 hasTable + 8 hasColumn); outros 6 testes esqueletados via markTestIncomplete, preparando suíte canônica de 7 testes (D-11) para slices 2-4."
  - "Verificação automated do Test 1 via `php artisan test --filter=...` foi substituída por prova alternativa equivalente (`artisan migrate --path=...` em SQLite isolado) por causa de bloqueio pré-existente em migration da Phase 7 — registrado como DEF-08-01."
metrics:
  duration_minutes: 14
  tasks_completed: 2
  files_created: 3
  files_modified: 0
  commits: 2
  completed_at: 2026-05-21T18:44:47Z
---

# Phase 08 Plan 01: Fundação de Notificações — Slice 1 Storage Summary

**One-liner:** Tabela `notifications` migrada com schema canônico Laravel 12 + suíte `Phase8FoundationTest` ancorada com Test 1 GREEN-equivalente (validado via prova alternativa por bloqueio pré-existente em migration de outra fase).

## Tarefas Concluídas

| Tarefa | Nome                                                                                            | Commit  | Arquivos                                                              |
| ------ | ----------------------------------------------------------------------------------------------- | ------- | --------------------------------------------------------------------- |
| 1      | Criar diretório e esqueleto do teste (RED esperado por design)                                 | 28e73b4 | `tests/Feature/Notifications/Phase8FoundationTest.php`                |
| 2      | Criar migration `2026_05_21_100001_create_notifications_table.php` (faz Test 1 GREEN)          | f1415ae | `database/migrations/2026_05_21_100001_create_notifications_table.php`, `.planning/phases/08-funda-o-de-notifica-es/deferred-items.md` |

## O que foi entregue

### 1. Migration `2026_05_21_100001_create_notifications_table.php`

Schema canônico Laravel 12 fielmente replicado a partir do stub
`vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub`:

```php
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');                  // notifiable_id + notifiable_type + índice composto
    $table->text('data');                          // cast 'array' no DatabaseNotification
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
});
```

DDL emitido em SQLite isolado (validação alternativa — ver seção "Sequência TDD" abaixo):

```sql
create table "notifications" (
  "id" varchar not null,
  "type" varchar not null,
  "notifiable_type" varchar not null,
  "notifiable_id" integer not null,
  "data" text not null,
  "read_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key ("id")
);
create index "notifications_notifiable_type_notifiable_id_index"
  on "notifications" ("notifiable_type", "notifiable_id");
```

Todas as 8 colunas canônicas estão presentes. `data` é `text` (não `json`) — forma
canônica do framework, compatível com o cast `array` nativo do
`Illuminate\Notifications\DatabaseNotification`. PHPDoc em pt-BR com nota de
reconciliação D-05 explicando essa escolha. `down()` faz `Schema::dropIfExists`.

### 2. Esqueleto `Tests\Feature\Notifications\Phase8FoundationTest`

Arquivo novo em subdiretório novo (`tests/Feature/Notifications/`). Cobre a
suíte canônica de 7 testes da Phase 8 (D-11):

- **Test 1** `test_migration_cria_tabela_notifications` — **implementado** (9 asserts: 1 `hasTable` + 8 `hasColumn`)
- **Test 2** `test_permissions_all_inclui_notificacoes_criar` — esqueletado (`markTestIncomplete`, Slice 3)
- **Test 3** `test_catalog_inclui_grupo_notificacoes` — esqueletado (Slice 3)
- **Test 4** `test_auto_lideranca_inclui_notificacoes_criar` — esqueletado (Slice 3)
- **Test 5** `test_admin_tem_permissao_via_short_circuit` — esqueletado (Slice 3)
- **Test 6** `test_lider_tem_permissao_via_auto_lideranca` — esqueletado (Slice 3)
- **Test 7** `test_base_notification_persiste_payload_canonico` — esqueletado (Slice 4)

Imports completos para os 7 testes futuros (User, Setor, BaseNotification,
Categoria, Permissions, DatabaseNotification, Notification facade) prontos
para Slices 2–4 preencherem. Nenhum `Notification::fake()` em lugar nenhum
(Pitfall 1 do RESEARCH).

### 3. Deferred item DEF-08-01

Documentado em `.planning/phases/08-funda-o-de-notifica-es/deferred-items.md`.
Migration `2026_05_20_200008` da Phase 7 quebra `RefreshDatabase` em SQLite
in-memory por usar `information_schema.CHECK_CONSTRAINTS` (MySQL-only) sem
guard de driver. Fora do `SCOPE BOUNDARY` da Slice 1 — encaminhado para fix
dedicado fora desta phase.

## Sequência TDD intra-plan (RED → GREEN)

| Etapa     | Estado de `Phase8FoundationTest::test_migration_cria_tabela_notifications` | Causa |
| --------- | -------------------------------------------------------------------------- | ----- |
| Antes de T1 | N/A (arquivo de teste ainda não existe)                                 | —     |
| Após T1     | **RED — intencional**                                                   | Migration ainda não foi criada → `Schema::hasTable('notifications')` retorna `false`. Proposital, prova a ordem TDD. NÃO foi executado PHPUnit nesta etapa (per Plan §verify Tarefa 1). |
| Após T2     | **GREEN-equivalente**                                                   | Migration criada — `Schema::hasTable` e os 8 `Schema::hasColumn` retornariam `true`. Verificação direta via `artisan test --filter=...` está bloqueada por DEF-08-01 (problema pré-existente em outra fase). Validação alternativa equivalente: rodar a migration sozinha contra SQLite (`artisan migrate --path=... --pretend` + `artisan migrate --path=...`) — DDL emitido confirma as 9 colunas canônicas (incluindo `id` PK) e o índice composto morphs. |

## Deviations from Plan

### Auto-fixed Issues

Nenhum. Nenhum Rule 1/2/3 disparou durante a execução das duas tarefas.

### Out-of-scope discoveries (deferred)

**1. [DEF-08-01] Migration `2026_05_20_200008` quebra suíte em SQLite**

- **Descoberta durante:** Tarefa 2 (execução do `<verify>` `php artisan test --filter=Phase8FoundationTest::test_migration_cria_tabela_notifications`)
- **Sintoma:** `SQLSTATE[HY000]: General error: 1 no such table: information_schema.CHECK_CONSTRAINTS` em SQLite in-memory.
- **Causa raiz:** Migration de **outra fase (Phase 7)** faz `DB::select` em `information_schema.CHECK_CONSTRAINTS` (view MySQL-only) sem guard de driver. Quebra qualquer teste que use `RefreshDatabase` — incluindo o pré-existente `Tests\Feature\FechamentoMigrationTest`, que também está RED há tempo (verificado).
- **Por que não corrigido inline:** Fora do `SCOPE BOUNDARY` desta slice. Auto-fix violaria a regra "Only auto-fix issues DIRECTLY caused by the current task's changes."
- **Mitigação aplicada:** Validação isolada da migration nova via `artisan migrate --path=...` em SQLite (sem `RefreshDatabase`). DDL emitido prova o schema canônico esperado. Registrado em `deferred-items.md` (DEF-08-01) com encaminhamento sugerido (guard `DB::connection()->getDriverName() === 'mysql'`).
- **Impacto na próxima slice:** Slices 2–4 também não conseguirão rodar a suíte completa enquanto DEF-08-01 não for endereçado. Sugestão: priorizar o fix de DEF-08-01 antes ou em paralelo à Slice 2.

## Threat Flags

Nenhuma nova superfície de segurança introduzida. T-08-01 (tampering em `data`),
T-08-02 (DoS por crescimento) e T-08-03 (ordem de migrations) seguem o
threat_model do plano — todos com mitigação documentada e aceita.

## Known Stubs

Os 6 testes esqueletados em `Phase8FoundationTest.php` (`markTestIncomplete`)
são **stubs intencionais** que compõem a suíte canônica de 7 testes. Cada um
declara explicitamente em qual slice será preenchido:

| Stub                                              | Resolvido em |
| ------------------------------------------------- | ------------ |
| `test_permissions_all_inclui_notificacoes_criar`  | Slice 3 (Plan 03) |
| `test_catalog_inclui_grupo_notificacoes`          | Slice 3 (Plan 03) |
| `test_auto_lideranca_inclui_notificacoes_criar`   | Slice 3 (Plan 03) |
| `test_admin_tem_permissao_via_short_circuit`      | Slice 3 (Plan 03) |
| `test_lider_tem_permissao_via_auto_lideranca`     | Slice 3 (Plan 03) |
| `test_base_notification_persiste_payload_canonico` | Slice 4 (Plan 04) |

Nenhum stub bloqueia o objetivo da Slice 1 (storage) — tabela existe, está no
schema canônico, pronta para receber linhas via `Notification::send`.

## Próxima Slice

**Plan 02 — Slice 2: Storage→Domain types**
- Criar `app/Notifications/Categoria.php` (enum tipado das categorias de payload)
- Criar `app/Notifications/BaseNotification.php` (abstract; `via()` → `['database']`, `toArray()` retorna payload canônico de 6 chaves)
- Test 7 vai virar GREEN nessa slice

## Confirmação de pt-BR

- **Migration:** PHPDoc da classe + PHPDocs dos métodos `up()`/`down()` + comentários inline em pt-BR (incluindo a nota de reconciliação D-05 e o comentário sobre `morphs`/`text`).
- **Teste:** Docblock da classe em pt-BR explicando o papel da suíte e a regra "nunca usar `Notification::fake`"; PHPDoc de cada um dos 7 métodos `test_*` em pt-BR.
- **Deferred-items / Summary:** ambos em pt-BR (este arquivo).
- **Commit messages:** ambos em pt-BR conforme convenção GSD.
- **Termos técnicos mantidos em inglês** quando consagrados (`migration`, `cast`, `polymorphic`, `payload`, etc.) — política do CLAUDE.md.

## Self-Check: PASSED

- `database/migrations/2026_05_21_100001_create_notifications_table.php` — FOUND
- `tests/Feature/Notifications/Phase8FoundationTest.php` — FOUND
- `.planning/phases/08-funda-o-de-notifica-es/deferred-items.md` — FOUND
- Commit `28e73b4` (test scaffold) — FOUND no histórico
- Commit `f1415ae` (migration + deferred-items) — FOUND no histórico
