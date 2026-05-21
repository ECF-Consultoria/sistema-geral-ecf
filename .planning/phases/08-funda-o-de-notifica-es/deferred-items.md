# Itens Deferidos — Phase 08 Fundação de Notificações

Lista de issues encontradas durante execução das slices que **não foram** corrigidas
porque ficam fora do `SCOPE BOUNDARY` da slice em curso. Cada item deve ser
endereçado em uma fase própria (fix dedicado) ou reavaliado no review da phase.

---

## DEF-08-01 — Migration `2026_05_20_200008_rename_legacy_columns_in_users_table` quebra suíte de testes em SQLite

- **Encontrado por:** Plan 08-01 (Slice 1 — Storage), Tarefa 2
- **Sintoma:** Qualquer teste que use `RefreshDatabase` falha com
  `SQLSTATE[HY000]: General error: 1 no such table: information_schema.CHECK_CONSTRAINTS`
  ao tentar rodar `2026_05_20_200008_rename_legacy_columns_in_users_table` em
  SQLite in-memory (driver default da suíte conforme `phpunit.xml` linha 27).
- **Causa:** A migration faz `DB::select("SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS ...")`
  sem guard de driver. `information_schema` é uma view MySQL — não existe em SQLite.
- **Impacto:** Toda a suíte `Tests\Feature\*` que usa `RefreshDatabase` está
  RED em ambiente de teste local (não apenas Phase 8). Verificável reproduzindo
  com `php artisan test --filter=FechamentoMigrationTest` (teste pré-existente
  da Phase 7) — também falha.
- **Por que não corrigido nesta slice:** Está em código de outra phase (Phase 7
  — Administrativo/Fechamento). SCOPE BOUNDARY proíbe auto-fix de issues
  pré-existentes que não foram introduzidas pela tarefa atual. A correção é
  envolver o bloco em `if (DB::getDriverName() === 'mysql')` ou usar
  `Schema::getConnection()->getDriverName()` para detectar e pular o discovery
  em SQLite.
- **Prova alternativa do Test 1 (Slice 1):** A migration
  `2026_05_21_100001_create_notifications_table` foi validada isoladamente via:
  ```bash
  DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
    php artisan migrate --path=database/migrations/2026_05_21_100001_create_notifications_table.php --pretend
  ```
  O SQL emitido confirma o schema canônico exato:
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
  Todas as 8 colunas canônicas estão presentes, `data` é `text` (não `json`),
  UUID PK como `varchar`, `read_at` nullable, `morphs` gerou o índice composto.
  Roda sem erros em SQLite in-memory isolado.
- **Encaminhamento sugerido:** Abrir um fix dedicado fora da Phase 8 (e.g.,
  `/gsd:quick` "guard MySQL em rename_legacy_columns") que adicione:
  ```php
  if (DB::connection()->getDriverName() !== 'mysql') {
      // SQLite/PostgreSQL não têm information_schema.CHECK_CONSTRAINTS;
      // o discovery só é necessário em MySQL 8+.
  } else {
      $checks = DB::select("SELECT ...");
      // ...
  }
  ```
  Depois disso, `Phase8FoundationTest::test_migration_cria_tabela_notifications`
  passa pela rota normal (`RefreshDatabase` + asserts) sem necessidade de prova
  isolada. Slices 2–4 também dependem desse fix para rodar a suíte completa em
  ambiente CI / dev local.
- **Severidade:** **Bloqueador para CI** (em MySQL roda normal), **não-bloqueador
  para entrega da Slice 1** porque o schema da migration nova foi provado por
  via alternativa equivalente.
