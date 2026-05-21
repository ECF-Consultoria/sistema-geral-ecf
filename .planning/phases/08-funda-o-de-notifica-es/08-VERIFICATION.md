---
phase: 08-funda-o-de-notifica-es
verified: 2026-05-21T19:30:00Z
status: passed
score: 5/5 success criteria verificados (33/33 assertions GREEN)
mode: mvp
verdict: PHASE_GOAL_ACHIEVED
re_verification:
  first_verification: true
test_run:
  command: "php artisan test --filter=Phase8FoundationTest"
  result: "7 passed (33 assertions)"
  duration: "0.84s"
  failures: 0
  incomplete: 0
d10_honored:
  command: "git diff a6c83869..HEAD -- app/Models/User.php"
  lines_changed: 0
  verdict: ZERO_CHANGES
warnings_triage:
  WR-01: "ADVISORY — fix DEF-08-01 já aplicado pelo orchestrator (commit 9f508f8); WR-01 cobre cenário diferente (assimetria up/down em produção MySQL após rollback). Não é foundation-blocker; cleanup recomendado em phase futura."
  WR-02: "ADVISORY — defesa anti-tampering parcial via readonly. Propriedade documentada mas não enforçada. Phase 9+ pode reforçar quando integrar com FormRequest."
  WR-03: "ADVISORY — janela TOCTOU teórica em discovery de check constraints (Phase 7). Não afeta a fundação Phase 8."
deferred_items:
  - id: DEF-08-01
    status: fixed
    fixed_in_commit: 9f508f8
    description: "Phase 7 migration driver-guard — orchestrator wrappou o bloco MySQL-only em if (driver === 'mysql') para permitir RefreshDatabase em SQLite."
  - id: DEF-08-02
    status: out_of_scope
    description: "8 falhas pré-existentes (CalcularFaixaTest × 7 + ExampleTest × 1) no commit base — não introduzidas pela Phase 8. Encaminhar via /gsd:debug e /gsd:quick em fixes dedicados."
---

# Phase 08 — Fundação de Notificações: Relatório de Verificação

**Phase Goal (ROADMAP.md §Phase 8):** A infraestrutura mínima de notificações (tabela `notifications` do Laravel, classe `Notification` base e a permission `notificacoes.criar` no catálogo) existe e está disponível para que qualquer outra fase possa criar e ler notificações.

**Verificado:** 2026-05-21
**Status:** passed
**Veredito:** PHASE_GOAL_ACHIEVED
**Re-verificação:** Não — verificação inicial

## Resumo Executivo

A Phase 8 entrega a fundação completa para o sistema de Notificações v3.0. **Todos os 5 success criteria do ROADMAP estão verificados empiricamente** pela suíte canônica `tests/Feature/Notifications/Phase8FoundationTest` (7 testes, 33 assertions, 0 incomplete, 0 failed, 0.84s no run de verificação).

A propriedade central de design (D-10 — admin via short-circuit + líder via `AUTO_LIDERANCA` merge **sem nenhuma mudança em `app/Models/User.php`**) está confirmada empiricamente: `git diff a6c83869..HEAD -- app/Models/User.php` retorna zero linhas modificadas.

Phases 9–12 podem agora construir sobre a fundação Phase 8 sem precisar tocar em storage, classes base de Notification, enum de categorias, ou catálogo de permissions.

## Goal Achievement — Verificação dos 5 Success Criteria

### Tabela de Verdade Observável

| # | Success Criterion (ROADMAP §Phase 8) | Status | Evidência em código (linha:arquivo) |
|---|---|--------|---|
| 1 | A tabela `notifications` (schema nativo Laravel: id uuid, type, notifiable_id, notifiable_type, data json, read_at, created_at, updated_at) existe e foi migrada | ✓ ACHIEVED | `database/migrations/2026_05_21_100001_create_notifications_table.php:30-37` cria as 8 colunas canônicas (uuid PK + morphs + text data + read_at nullable + timestamps). Teste `test_migration_cria_tabela_notifications` valida com 9 asserts (hasTable + 8 hasColumn). |
| 2 | A permission_key `notificacoes.criar` aparece no catálogo retornado por `Permissions::all()` com label e descrição em pt-BR, sob o grupo "Notificações" | ✓ ACHIEVED | `app/Support/Permissions.php:68` declara `NOTIFICACOES_CRIAR = 'notificacoes.criar'`; linhas 135-137 inserem grupo `'Notificações'` em `catalog()` posicionado entre `'Sistema'` e `'Liderança'`; label `'Criar notificações'` + description pt-BR mencionando "manuais" + "usuários, setores, líderes ou todos". Testes `test_permissions_all_inclui_notificacoes_criar` (3 asserts) + `test_catalog_inclui_grupo_notificacoes` (5 asserts) verificam ponta a ponta. |
| 3 | Qualquer usuário admin retorna `true` em `$user->hasPermission('notificacoes.criar')` sem precisar de atribuição manual (short-circuit já existente) | ✓ ACHIEVED | `app/Models/User.php:104-109` (linha 106: `if ($this->isAdmin()) return true;` — pré-existente, D-10). Teste `test_admin_tem_permissao_via_short_circuit` cria admin sem nenhuma atribuição de setor/permission e assert passa. |
| 4 | Qualquer usuário cadastrado em `setor_lideres` retorna `true` em `$user->hasPermission('notificacoes.criar')` automaticamente | ✓ ACHIEVED | `app/Models/User.php:135-137` (merge `AUTO_LIDERANCA` quando `isLider()`) — pré-existente, D-10. Teste `test_lider_tem_permissao_via_auto_lideranca` cria consultor + Setor + attach via `setoresLiderados()->attach` + refresh (mitigação T-08-12) — assert passa sem permission manual em setor. |
| 5 | A permission_key `notificacoes.criar` consta no array `Permissions::AUTO_LIDERANCA` | ✓ ACHIEVED | `app/Support/Permissions.php:81-86` — array passou de 3 para 4 entries; entry nova `self::NOTIFICACOES_CRIAR` com comentário inline em pt-BR. Teste `test_auto_lideranca_inclui_notificacoes_criar` valida. |

**Score: 5/5 (100%)** — todos os success criteria do ROADMAP §Phase 8 verificados empiricamente em código + teste automatizado.

### Suíte Canônica Phase8FoundationTest — Run de Verificação

Comando executado pelo verifier (não dependendo de SUMMARY claims):

```
$ /c/xampp/php/php.exe artisan test --filter=Phase8FoundationTest

PASS  Tests\Feature\Notifications\Phase8FoundationTest
  ✓ migration cria tabela notifications              0.46s
  ✓ permissions all inclui notificacoes criar        0.02s
  ✓ catalog inclui grupo notificacoes                0.02s
  ✓ auto lideranca inclui notificacoes criar         0.02s
  ✓ admin tem permissao via short circuit            0.06s
  ✓ lider tem permissao via auto lideranca           0.03s
  ✓ base notification persiste payload canonico      0.04s

Tests:    7 passed (33 assertions)
Duration: 0.84s
```

**Resultado:** 7/7 GREEN, 33/33 assertions, 0 incomplete, 0 failed.

## Foundation Readiness para Phases 9–12

Avaliação goal-backward: o que cada phase downstream precisa, e a Phase 8 entregou?

| Phase downstream | O que precisa da fundação Phase 8 | Entregue? | Evidência |
|------------------|----------------------------------|-----------|-----------|
| Phase 9 (Backend leitura + contador + polling) | Tabela `notifications` para `$user->notifications`/`unreadNotifications` queries via trait `Notifiable` | ✓ SIM | Migration cria schema canônico; `User` model já tem trait `Notifiable` (User.php:16); `DatabaseNotification` model nativo do framework é usado direto (D-06) |
| Phase 9 | Payload com shape estável para shared prop + dropdown rendering | ✓ SIM | `BaseNotification::toArray()` garante 6 chaves canônicas (`titulo`, `mensagem`, `categoria`, `autor_user_id`, `url`, `meta`) — `meta` defaulta a `[]` (nunca null) — shape uniforme para read path |
| Phase 11 (Disparos automáticos de metas) | Classe abstrata que subclasses concretas (`MetaAtribuidaNotification`, `MetaAtingidaNotification`) estendam com 1 linha por evento | ✓ SIM | `BaseNotification` é `abstract`, construtor canônico de 6 params, `via()` fixo em `database`, `toArray()` automático — Phase 11 só precisa criar subclasses com construtor que chame `parent::__construct(...)` ou usar property promotion adicional |
| Phase 11 | Enum tipado `Categoria` para evitar "categoria forjada" | ✓ SIM | `Categoria` é `enum: string` backed com 3 cases (META_ATRIBUIDA, META_ATINGIDA, MANUAL); construtor de `BaseNotification` exige `Categoria $categoria` — PHP rejeita string arbitrária em compile/runtime |
| Phase 12 (Criação manual + UI setores + cleanup) | Permission key `notificacoes.criar` exposta no catálogo + gateavel via `hasPermission()` | ✓ SIM | `Permissions::NOTIFICACOES_CRIAR` registrada; aparece em `catalog()['Notificações']`; `hasPermission()` resolve `true` para admin (short-circuit) e líder (AUTO_LIDERANCA merge) verificado E2E |
| Phase 12 | UI de setores `/sistema/setores` listar a key e permitir atribuição ao setor "Administrativo" | ✓ SIM | `Permissions::catalog()` agora retorna grupo `'Notificações'` com a entry — UI da Phase 12 vai consumir esse catálogo dinamicamente (mesmo padrão das demais keys) |
| Phase 12 | Cleanup diário via `routes/console.php` que descarte lidas > 30d | — N/A | Out of scope explícito da Phase 8 (POLL-04 mapeado para Phase 12) — não esperado entregar aqui |

**Veredito:** Phases 9–12 podem ser planejadas sem qualquer dependência circular ou retrabalho na Phase 8. A fundação está pronta.

## Artefatos Verificados — 3 Níveis (existe, substantive, wired)

### Camada Storage

| Arquivo | Exists | Substantive | Wired | Status |
|---------|--------|-------------|-------|--------|
| `database/migrations/2026_05_21_100001_create_notifications_table.php` | ✓ | ✓ (47 linhas, schema canônico replicado do stub Laravel 12) | ✓ (Test 1 prova via `RefreshDatabase` + 8 `Schema::hasColumn`; Test 7 prova via `Notification::send` REAL persistindo linha) | ✓ VERIFIED |

### Camada Domain Types

| Arquivo | Exists | Substantive | Wired | Status |
|---------|--------|-------------|-------|--------|
| `app/Notifications/Categoria.php` | ✓ | ✓ (enum backed string, 3 cases conforme D-03, PHPDoc pt-BR) | ✓ (Test 7 usa `Categoria::MANUAL` no construtor da classe anônima; `toArray()` persiste `->value`) | ✓ VERIFIED |
| `app/Notifications/BaseNotification.php` | ✓ | ✓ (86 linhas, abstract, construtor de 6 params via property promotion, `via()` fixo em `['database']`, `toArray()` retorna 6 chaves canônicas) | ✓ (Test 7 instancia subclasse anônima inline; `Notification::send` percorre `via()` → `DatabaseChannel::getData()` → `toArray()` → DB insert; verificado por `assertDatabaseCount` + 6 asserts em `$row->data['...']`) | ✓ VERIFIED |

### Camada Permission Catalog

| Arquivo | Exists | Substantive | Wired | Status |
|---------|--------|-------------|-------|--------|
| `app/Support/Permissions.php` (3 edições cirúrgicas) | ✓ | ✓ (constante `NOTIFICACOES_CRIAR` linha 68 + grupo `'Notificações'` linhas 135-137 + entry em `AUTO_LIDERANCA` linha 85, todos em pt-BR) | ✓ (Tests 2/3/4 provam: `all()` inclui a key, `catalog()['Notificações']` tem label+description, `AUTO_LIDERANCA` contém a key; Tests 5/6 provam que `User::hasPermission()` resolve `true` via short-circuit/merge usando esses 3 pontos) | ✓ VERIFIED |

### Camada Test Suite

| Arquivo | Exists | Substantive | Wired | Status |
|---------|--------|-------------|-------|--------|
| `tests/Feature/Notifications/Phase8FoundationTest.php` | ✓ | ✓ (262 linhas, 7 métodos `test_*` todos implementados — zero `markTestIncomplete`) | ✓ (rodado via `php artisan test --filter=Phase8FoundationTest` durante verificação: 7 passed, 33 assertions, 0.84s) | ✓ VERIFIED |

## Key Link Verification

| From | To | Via | Status | Detalhe |
|------|----|----|--------|---------|
| `BaseNotification::toArray()` | tabela `notifications` (coluna `data`) | `DatabaseChannel::getData()` fallback → cast `array` no `DatabaseNotification` model nativo | ✓ WIRED | Test 7 prova end-to-end com `Notification::send` REAL (sem `fake()`) — payload de 6 chaves deserializado corretamente |
| `BaseNotification` (construtor) | enum `Categoria` | type hint `Categoria $categoria` no parâmetro 3 + `$this->categoria->value` em `toArray()` | ✓ WIRED | Test 7 passa `Categoria::MANUAL`; verifica `$row->data['categoria'] === 'manual'` (string, enum→value) |
| `Permissions::NOTIFICACOES_CRIAR` (constante) | catálogo `Permissions::catalog()['Notificações']` | referência via `self::NOTIFICACOES_CRIAR` no array do grupo | ✓ WIRED | Test 3 prova com `firstWhere('key', 'notificacoes.criar')` + `assertSame('Criar notificações', $entry['label'])` |
| `Permissions::NOTIFICACOES_CRIAR` | `Permissions::AUTO_LIDERANCA` | referência via `self::NOTIFICACOES_CRIAR` no array constante | ✓ WIRED | Test 4 prova com `assertContains('notificacoes.criar', Permissions::AUTO_LIDERANCA)` |
| `User::hasPermission('notificacoes.criar')` (admin) | retorno `true` automático | short-circuit `if ($this->isAdmin()) return true;` (User.php:106) — pré-existente, D-10 | ✓ WIRED | Test 5 cria admin sem atribuição, assert passa em 0.06s |
| `User::hasPermission('notificacoes.criar')` (líder) | retorno `true` automático | `effectivePermissions()` → `array_merge($keys, Permissions::AUTO_LIDERANCA)` quando `isLider()` (User.php:135-137) — pré-existente, D-10 | ✓ WIRED | Test 6 cria consultor + attach em `setor_lideres` + refresh, assert passa em 0.03s |

Todos os 6 key links verificados como WIRED. Nenhum órfão (artifact existe mas não está conectado) detectado.

## Data-Flow Trace (Level 4)

| Artefato | Variável de dado | Origem | Produz dado real? | Status |
|----------|------------------|--------|-------------------|--------|
| Linha em `notifications` (após `Notification::send`) | `data` (JSON deserializado para array via cast) | `BaseNotification::toArray()` retorna 6 chaves do estado da instância (property promotion) | ✓ SIM — Test 7 verifica que `$row->data['titulo'] === 'Título teste'` (não é static/empty fallback; valor flui do construtor passado em named arguments) | ✓ FLOWING |
| `Permissions::all()` return | array de keys | `foreach (catalog() as $group) → foreach ($group as $perm) → keys[] = $perm['key']` (linhas 152-159) | ✓ SIM — `catalog()` é construído estaticamente em código (sem DB), e `all()` varre dinamicamente — chave nova adicionada ao catálogo aparece automaticamente em `all()` (mitiga T-08-08) | ✓ FLOWING |
| `User::hasPermission()` return | bool | Caminho 1 (admin): `isAdmin()` short-circuit; Caminho 2 (líder): query `SetorPermissao` + merge `AUTO_LIDERANCA` | ✓ SIM — Test 5 e Test 6 cada um exercita um caminho diferente com setup mínimo, sem fixtures sintéticas | ✓ FLOWING |

Nenhum HOLLOW_PROP, STATIC fallback, ou DISCONNECTED data source identificado.

## Anti-Patterns Scan

Arquivos modificados pela Phase 8 escaneados:

| Arquivo | Padrão buscado | Linha | Severidade | Impacto |
|---------|----------------|-------|------------|---------|
| `app/Notifications/Categoria.php` | TODO/FIXME/XXX/TBD | — | nenhum match | — |
| `app/Notifications/BaseNotification.php` | TODO/FIXME/XXX/TBD | — | nenhum match | — |
| `app/Support/Permissions.php` (linhas adicionadas) | TODO/FIXME/XXX/TBD | — | nenhum match | — |
| `database/migrations/2026_05_21_100001_create_notifications_table.php` | TODO/FIXME/XXX/TBD | — | nenhum match | — |
| `tests/Feature/Notifications/Phase8FoundationTest.php` | `Notification::fake()` (Pitfall 1) | — | nenhum match (proibido conforme PLAN) | — |
| `tests/Feature/Notifications/Phase8FoundationTest.php` | `markTestIncomplete` (stub residual) | — | nenhum match (todos os 7 testes preenchidos) | — |
| `tests/Feature/Notifications/Phase8FoundationTest.php` | `get_class(` em assertions sobre `type` (Pitfall 2) | — | nenhum match (usa `assertNotEmpty($row->type)` ao invés) | — |

**Resultado:** Zero anti-patterns detectados. A migration usa `text('data')` (não `json`) conforme decisão reconciliada D-05 + Pitfall 3 do RESEARCH (forma canônica Laravel 12 + cast `array` no model nativo).

## Behavioral Spot-Checks

| Comportamento | Comando | Resultado | Status |
|---------------|---------|-----------|--------|
| Suíte canônica Phase 8 passa | `php artisan test --filter=Phase8FoundationTest` | 7 passed (33 assertions), 0.84s | ✓ PASS |
| Migration roda em SQLite in-memory via RefreshDatabase | (incluído implicitamente em Test 1) | hasTable + 8 hasColumn verdes | ✓ PASS |
| `Notification::send` REAL persiste linha (sem fake) | (incluído implicitamente em Test 7) | `assertDatabaseCount('notifications', 1)` verde + 6 asserts no payload | ✓ PASS |
| User admin via factory retorna true em hasPermission | (incluído implicitamente em Test 5) | assert verde em 0.06s (sub-100ms — short-circuit) | ✓ PASS |
| User líder via attach+refresh retorna true em hasPermission | (incluído implicitamente em Test 6) | assert verde em 0.03s | ✓ PASS |

## Requirements Coverage

REQ-IDs declarados nos plans 08-01..08-04 (do frontmatter `requirements`) cruzados com REQUIREMENTS.md §Permissões (PERM):

| Requirement | Plan de origem | Descrição | Status | Evidência |
|-------------|----------------|-----------|--------|-----------|
| PERM-01 | 08-03 | Sistema registra nova permission_key `notificacoes.criar` no catálogo com label/descrição pt-BR sob grupo "Notificações" | ✓ SATISFIED | Permissions.php:68 (const) + linhas 135-137 (catalog group) + Tests 2 e 3 GREEN |
| PERM-02 | 08-04 | Admin tem `notificacoes.criar` automaticamente via short-circuit existente em `hasPermission` | ✓ SATISFIED | User.php:106 (pré-existente) + Test 5 GREEN |
| PERM-03 | 08-03, 08-04 | Qualquer usuário em `setor_lideres` recebe `notificacoes.criar` via inclusão em `AUTO_LIDERANCA` | ✓ SATISFIED | Permissions.php:85 + Test 4 (catalog) + Test 6 (E2E via hasPermission) GREEN |

**Cobertura:** 3/3 REQs declarados na Phase 8 são SATISFIED. PERM-04 (UI de setores `/sistema/setores`) e POLL-04 (cleanup) estão corretamente mapeados para Phase 12 — não esperados aqui.

**Orphaned requirements check:** REQUIREMENTS.md §Traceability v3.0 mapeia exatamente PERM-01/02/03 para Phase 8 — sem REQs adicionais não-cobertos pelos plans. Zero órfãos.

## Code Review WARNING Triage

O código review (`08-REVIEW.md`) reporta 3 WARNINGs (advisory). Triage de cada um como pre-foundation-blocker vs. cleanup-later:

### WR-01 — `down()` da migration de rename (Phase 7) não recria check constraints removidos

- **Arquivo:** `database/migrations/2026_05_20_200008_rename_legacy_columns_in_users_table.php:71-80`
- **Triage:** **CLEANUP-LATER** (não é foundation-blocker da Phase 8).
- **Racional:**
  - O fix DEF-08-01 (que era o blocker real — `RefreshDatabase` quebrava em SQLite) já foi aplicado pelo orchestrator no commit `9f508f8`. Verificável em `database/migrations/2026_05_20_200008_rename_legacy_columns_in_users_table.php:31` — bloco MySQL-only agora vive dentro de `if (DB::connection()->getDriverName() === 'mysql')`.
  - WR-01 reporta cenário diferente: assimetria up/down quando rollback é executado em produção MySQL (o down não recria check constraints). Isto é uma propriedade de robustez de rollback, não bloqueia nada da Phase 8 nem das Phases 9–12 (que nunca rodam rollback dessa migration).
  - Impacto na fundação Phase 8: **zero**. A suíte canônica roda em SQLite via `RefreshDatabase` e passa 7/7.

### WR-02 — `BaseNotification::toArray()` não valida invariante `meta` em runtime (sugestão: `readonly` em property promotion)

- **Arquivo:** `app/Notifications/BaseNotification.php:43,75-85`
- **Triage:** **CLEANUP-LATER** (não é foundation-blocker; recomendado endereçar antes da Phase 11/12 quando subclasses concretas existirem).
- **Racional:**
  - A invariante "meta nunca null" é hoje garantida em runtime pelo type hint `array $meta = []` no construtor — PHP rejeita `null` em compile/runtime nesse parâmetro. Para uma subclasse mutar a property após construção precisaria fazer `$this->meta = null` explicitamente em algum método — a Phase 8 não tem subclasses concretas (D-04 trava), então o vetor não existe ainda.
  - A sugestão de `readonly` é uma melhoria de defesa em profundidade, alta-valor mas baixa-urgência. Pode ser aplicada como refactor antes da Phase 11 introduzir as primeiras subclasses concretas.
  - Impacto na fundação Phase 8: **zero**. Test 7 prova que o construtor canônico funciona e o payload de 6 chaves é persistido corretamente.

### WR-03 — Discovery de check constraints via `information_schema` (Phase 7) tem janela TOCTOU teórica

- **Arquivo:** `database/migrations/2026_05_20_200008_rename_legacy_columns_in_users_table.php:32-49`
- **Triage:** **CLEANUP-LATER** (não é foundation-blocker; nem sequer toca em código da Phase 8).
- **Racional:**
  - Janela TOCTOU teórica em ambiente single-tenant MySQL. O `try/catch (\Throwable)` no DROP CHECK já protege contra "constraint not found" — a mitigação atual é suficiente para o caso real.
  - Esta WARNING é puramente sobre código de Phase 7 (migration de rename). Não há sobreposição com a Phase 8.
  - Impacto na fundação Phase 8: **zero**.

**Resultado do triage:** Nenhuma das 3 WARNINGs é foundation-blocker. Todas são cleanup-later, com janela natural de endereçamento em phase futura (WR-02 antes da Phase 11; WR-01 e WR-03 oportunisticamente quando alguma phase tocar a migration 2026_05_20_200008).

## Deferred Items Validation

| ID | Status | Justificativa |
|----|--------|---------------|
| DEF-08-01 | ✓ FIXED | Driver-guard aplicado pelo orchestrator no commit `9f508f8` — verificado em código (migration linha 31). Sem este fix, `RefreshDatabase` quebraria em SQLite e a suíte Phase8FoundationTest não rodaria. Crítico para a Phase 8, **resolvido**. |
| DEF-08-02 | ⚠ OUT-OF-SCOPE | 8 falhas pré-existentes confirmadas no commit base `a6c83869` (antes de qualquer mudança Phase 8). Não introduzidas pela fundação. Encaminhamento sugerido: `/gsd:debug CalcularFaixaTest` + `/gsd:quick remover ExampleTest dummy`. Não bloqueia início da Phase 9. |

**Nada crítico foi deferido sem justificativa.** O único item realmente crítico (DEF-08-01) foi corrigido in-line pelo orchestrator, com fix inspecionável e isolado a uma única linha (`if (driver === 'mysql')`). DEF-08-02 documenta dívida técnica pré-existente que precede a Phase 8.

## Decisões Honradas (D-01 a D-11)

| Decisão | Origem | Status |
|---------|--------|--------|
| D-01 — `via()` fixo em `['database']` | 08-CONTEXT §decisions | ✓ HONRADA (BaseNotification.php:55-58) |
| D-02 — Construtor canônico 6 params via property promotion | 08-CONTEXT §decisions | ✓ HONRADA (BaseNotification.php:37-44) |
| D-03 — Enum `Categoria: string` com 3 cases | 08-CONTEXT §decisions | ✓ HONRADA (Categoria.php:23-33) |
| D-04 — Sem subclasses concretas nesta phase | 08-CONTEXT §decisions | ✓ HONRADA (Glob `app/Notifications/*.php` retorna apenas Categoria.php + BaseNotification.php; smoke test usa classe anônima inline) |
| D-05 — Schema canônico Laravel 12 (`text('data')`, não `json`) | 08-CONTEXT §decisions + reconciliação em 08-01-PLAN | ✓ HONRADA (migration linha 34: `$table->text('data')`) |
| D-06 — Sem trait `LogsActivity` na entidade Notification | 08-CONTEXT §decisions | ✓ HONRADA (BaseNotification.php não tem `use LogsActivity`; usa `DatabaseNotification` nativo direto) |
| D-07 — Constante `Permissions::NOTIFICACOES_CRIAR = 'notificacoes.criar'` | 08-CONTEXT §decisions | ✓ HONRADA (Permissions.php:68) |
| D-08 — Novo grupo `'Notificações'` em `catalog()` entre `'Sistema'` e `'Liderança'` | 08-CONTEXT §decisions | ✓ HONRADA (Permissions.php:135-137 posicionado entre linhas 130-134 (Sistema) e 138-142 (Liderança)) |
| D-09 — `NOTIFICACOES_CRIAR` em `Permissions::AUTO_LIDERANCA` | 08-CONTEXT §decisions | ✓ HONRADA (Permissions.php:85) |
| D-10 — ZERO mudança em `app/Models/User.php` | 08-CONTEXT §decisions | ✓ HONRADA empiricamente (`git diff a6c83869..HEAD -- app/Models/User.php` retorna 0 linhas) |
| D-11 — Suíte com ~5-7 testes em `tests/Feature/Notifications/Phase8FoundationTest.php` | 08-CONTEXT §decisions | ✓ HONRADA (7 testes implementados, 33 assertions, todos GREEN) |

**Resultado:** Todas as 11 decisões arquiteturais formais da Phase 8 estão honradas em código verificável.

## Pitfalls Mitigados (do 08-RESEARCH.md)

| Pitfall | Mitigação aplicada | Evidência |
|---------|---------------------|-----------|
| Pitfall 1 — Não usar `Notification::fake()` | Suíte de teste não contém a string em lugar nenhum | Grep no arquivo retorna 0 matches |
| Pitfall 2 — Não hardcodar nome de classe anônima em assertions | Test 7 usa `assertNotEmpty($row->type)` ao invés de `assertSame(get_class($notif), $row->type)` | Phase8FoundationTest.php:250 |
| Pitfall 3 — `data` como `text` (não `json`), cast `array` no model nativo | Migration linha 34: `$table->text('data')` + PHPDoc explícita reconciliando D-05 vs stub | Migration linhas 10-18 |
| Pitfall 4 — UUID PK gerado pelo dispatcher | Migration usa `$table->uuid('id')->primary()` sem cast manual | Migration linha 31 |
| Pitfall 6 — Migration timestamp > `2026_05_20_200008` | Arquivo `2026_05_21_100001_create_notifications_table.php` está estritamente posterior | Verificado por `ls database/migrations/` ordenado |
| T-08-12 — Cache stale em `effectivePermissionsCache` | Test 6 chama `$user->refresh()` imediatamente após attach (mitiga falso-negativo) | Phase8FoundationTest.php:184-185 |

## Verificação Humana — Não Necessária

Phase 8 é 100% backend (sem UI, sem rotas, sem mudança em frontend). Toda a verificação é automatizável e foi automatizada via suíte canônica. Nenhum item de verificação humana é necessário.

**Manual checks documentados nos plans (Phase 8 §verification "Manual (dev host XAMPP)"):**
- `php artisan migrate` em MySQL local → criar tabela `notifications` em produção. **Status:** Não testado em ambiente MySQL pelo verifier (XAMPP local roda, mas SQLite cobre o teste de schema; runs em produção saem do escopo desta verificação).
- Tinker: `User::find(<id_admin>)->hasPermission('notificacoes.criar') === true`. **Status:** Coberto pelo Test 5 em SQLite — mesma resposta esperada em MySQL.

Nenhum desses requer ação humana antes de proceder para Phase 9.

## Gaps Summary

**Nenhum gap identificado.** A Phase 8 cumpriu todos os 5 success criteria do ROADMAP, os 3 requirements REQ-IDs (PERM-01/02/03), todas as 11 decisões arquiteturais (D-01 a D-11), todos os pitfalls do RESEARCH foram mitigados, e a suíte canônica está 7/7 GREEN com 33 assertions.

As 3 WARNINGs do code review são todas advisory e cleanup-later — nenhuma bloqueia a Phase 9 ou compromete a fundação entregue.

## Veredito Final

**Status:** `passed`
**Verdict:** `PHASE_GOAL_ACHIEVED`
**Score:** 5/5 success criteria verificados empiricamente (33/33 assertions GREEN, 0 incomplete, 0 failed)

A Phase 8 — Fundação de Notificações — entrega o que prometeu:

1. ✓ Tabela `notifications` migrada com schema canônico Laravel 12.
2. ✓ Classe abstrata `BaseNotification` com `via=['database']` fixo + `toArray()` 6-key canônico.
3. ✓ Enum `Categoria` backed string com 3 cases (META_ATRIBUIDA, META_ATINGIDA, MANUAL).
4. ✓ Permission `notificacoes.criar` registrada em Permissions.php (constante + grupo Notificações + AUTO_LIDERANCA).
5. ✓ Admin ganha via short-circuit; líder ganha via AUTO_LIDERANCA merge — ZERO código novo em User.php (D-10 confirmado por `git diff`).
6. ✓ Suíte Phase8FoundationTest 7/7 GREEN incluindo smoke E2E sem `Notification::fake()` provando que `Notification::send` persiste payload canônico real em `notifications`.

Phase 8 está **completa e pronta** para o orchestrator commitar e o usuário iniciar Phase 9 (Backend de Leitura, Contador e Polling).

---

*Verified: 2026-05-21*
*Verifier: Claude Opus 4.7 (1M context) (gsd-verifier)*
*Mode: goal-backward com prova empírica via `php artisan test --filter=Phase8FoundationTest`*
