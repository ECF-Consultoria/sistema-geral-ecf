---
phase: 08-funda-o-de-notifica-es
plan: 02
subsystem: notificacoes
tags: [domain-types, enum, abstract-class, foundation, tdd, smoke-test]
requires:
  - 08-01 (tabela `notifications` migrada)
provides:
  - enum `App\Notifications\Categoria` (backed string, 3 cases MVP v3.0)
  - classe abstrata `App\Notifications\BaseNotification` (canal único `database` + payload canônico 6 chaves)
  - Test 7 GREEN — prova end-to-end de Success Criterion #1
affects:
  - app/Notifications/
  - tests/Feature/Notifications/Phase8FoundationTest.php
tech_added:
  - primeiro enum backed string do projeto (idiom PHP 8.1+ — `App\Notifications\Categoria`)
  - primeira classe abstrata estendendo `Illuminate\Notifications\Notification` (canal `database` único)
patterns_used:
  - PHP 8 property promotion no construtor (6 params canônicos)
  - enum backed string → `->value` para JSON-serializable em `toArray()`
  - smoke test E2E via classe anônima inline (`new class(...) extends BaseNotification {}`) — sem subclasse concreta
  - named arguments (PHP 8) no smoke test
  - `Notification::send` REAL (sem `Notification::fake()`) — DatabaseChannel persiste de fato
key_files:
  created:
    - app/Notifications/Categoria.php
    - app/Notifications/BaseNotification.php
  modified:
    - tests/Feature/Notifications/Phase8FoundationTest.php
key_decisions:
  - "D-01 implementado: `BaseNotification::via()` retorna `['database']` fixo — mail/broadcast Out of Scope da v3.0."
  - "D-02 implementado: construtor canônico com 6 parâmetros via property promotion (`titulo`, `mensagem`, `categoria`, `autorUserId`, `url`, `meta`). `meta` defaulta a `[]` (NUNCA `null`) — shape estável para Phase 9."
  - "D-03 implementado: enum `Categoria: string` com 3 cases (`META_ATRIBUIDA`, `META_ATINGIDA`, `MANUAL`). Lista estática justificada por docblock no mesmo princípio do catálogo `App\\Support\\Permissions`."
  - "D-04 respeitado: ZERO subclasse concreta criada — smoke usa classe anônima inline. `ManualNotification`/`MetaAtribuidaNotification`/`MetaAtingidaNotification` saem nas Phases 11/12."
  - "D-06 respeitado: classe abstrata SEM trait `LogsActivity`; model nativo `DatabaseNotification` usado direto. Activity log fica nas dispatch sites da Phase 12 (POLL-05)."
  - "Refactor de imports no Test 7: usa `BaseNotification`, `Categoria`, `User`, `Notification`, `DatabaseNotification` via `use` (consome os imports já presentes no esqueleto Plan 01) em vez de FQCN inline. Plan acceptance permite ambas formas — escolhi import porque diagnósticos do IDE acusavam `unused use directives` e legibilidade do teste fica melhor."
  - "Estratégia de execução em worktree sem `vendor/` próprio: arquivos criados na worktree foram copiados temporariamente para o main repo (que tem `vendor/`) para rodar `php artisan test`. Após validação, o main repo foi restaurado ao estado pristine via `git checkout -- <test>` + `rm <app/Notifications/*>`. Worktree mantém commits canônicos; merge do orquestrador trará os arquivos para o main repo de forma legítima."
metrics:
  duration_minutes: 6
  tasks_completed: 3
  files_created: 2
  files_modified: 1
  commits: 3
  completed_at: 2026-05-21T18:56:55Z
---

# Phase 08 Plan 02: Fundação de Notificações — Slice 2 Domain Types Summary

**One-liner:** Enum backed `Categoria` (3 cases) + classe abstrata `BaseNotification` (via=database, payload canônico 6 chaves) criados e provados end-to-end via Test 7 smoke (11 asserts, `Notification::send` REAL → `notifications` row deserializada com cast `array`).

## Tarefas Concluídas

| Tarefa | Nome                                                                                                          | Commit  | Arquivos                                                            |
| ------ | ------------------------------------------------------------------------------------------------------------- | ------- | ------------------------------------------------------------------- |
| 1      | Criar enum `App\Notifications\Categoria` (backed string, 3 cases MVP v3.0)                                    | c66d455 | `app/Notifications/Categoria.php`                                   |
| 2      | Criar classe abstrata `App\Notifications\BaseNotification`                                                    | f574514 | `app/Notifications/BaseNotification.php`                            |
| 3      | Preencher Test 7 smoke E2E (`test_base_notification_persiste_payload_canonico`)                              | f534676 | `tests/Feature/Notifications/Phase8FoundationTest.php`              |

## O que foi entregue

### 1. Enum `App\Notifications\Categoria` (Tarefa 1)

Primeiro enum do projeto. Backed string (não int, não unbacked) — exigência de
`BaseNotification::toArray()` que faz `$this->categoria->value` para gerar
payload JSON-serializável.

```php
enum Categoria: string
{
    case META_ATRIBUIDA = 'meta_atribuida';
    case META_ATINGIDA  = 'meta_atingida';
    case MANUAL         = 'manual';
}
```

Cada case com PHPDoc curto pt-BR descrevendo o gatilho (Phase 11 para metas,
Phase 12 para manual). PHPDoc da classe ecoa o tom do docblock de
`App\Support\Permissions` ("lista INTENCIONALMENTE estática" + "adicionar
exige código novo"), citando explicitamente o uso de `Categoria::from($string)`
para hidratar do banco nas Phases 9+ (defesa contra "categoria forjada"
persistida fora do dispatch). Zero cases adicionais (Deferred Ideas trava
`sync_falhado`, `sugador_detectado`, etc.).

### 2. Classe abstrata `App\Notifications\BaseNotification` (Tarefa 2)

```php
abstract class BaseNotification extends \Illuminate\Notifications\Notification
{
    public function __construct(
        public string $titulo,
        public string $mensagem,
        public Categoria $categoria,
        public ?int $autorUserId = null,
        public ?string $url = null,
        public array $meta = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'titulo'        => $this->titulo,
            'mensagem'      => $this->mensagem,
            'categoria'     => $this->categoria->value,
            'autor_user_id' => $this->autorUserId,
            'url'           => $this->url,
            'meta'          => $this->meta,
        ];
    }
}
```

Decisões anti-padrão respeitadas (todas com comentário inline em pt-BR
justificando):

- **Sem `toDatabase()`** — única via é `toArray()` (RESEARCH §Anti-Patterns).
  O `DatabaseChannel::getData()` (vendor linhas 54–67) usa `toArray` como
  fallback automaticamente.
- **Sem `LogsActivity`** — D-06 trava activity log no model de notificação
  (evita inundação). Log de envios manuais sai na Phase 12 (POLL-05).
- **Sem subclasses concretas** — D-04 reserva isso para Phases 11/12. O
  smoke test usa classe anônima inline.
- **`meta` defaulta a `[]` (NUNCA `null`)** — Phase 9 lê `$row->data['meta']`
  sem coalesce; shape estável é parte do contrato.

Verificação por reflection confirma `isAbstract() === true` e
`getParentClass()->getName() === 'Illuminate\Notifications\Notification'`.

### 3. Test 7 smoke E2E (Tarefa 3)

`tests/Feature/Notifications/Phase8FoundationTest::test_base_notification_persiste_payload_canonico`
saiu de `markTestIncomplete` para **GREEN com 11 asserts** (versus o template
mínimo de 10 do RESEARCH — o `assertNotEmpty($row->type)` adicional cobre W4).

**Estrutura final:**

1. `User::factory()->create(['role' => 'consultor'])` — notifiable real (trait `Notifiable` já presente).
2. `new class(...) extends BaseNotification {}` — classe anônima inline com 6 named arguments (D-04 trava arquivos separados).
3. `Notification::send($user, $notif)` — SEM `fake()` (Pitfall 1).
4. `assertDatabaseCount('notifications', 1)`.
5. **3 asserts de identidade:** `notifiable_id == $user->id`, `notifiable_type === User::class`, `read_at === null`.
6. **`assertNotEmpty($row->type)`** — coluna populada pelo dispatcher (W4 — sem hardcodar nome de classe anônima, conforme Pitfall 2).
7. **6 asserts sobre `$row->data['...']`** cobrindo as chaves canônicas:
   - `titulo === 'Título teste'`
   - `mensagem === 'Mensagem teste'`
   - `categoria === 'manual'` (enum→value persistido como string)
   - `autor_user_id === null`
   - `url === '/notificacoes'`
   - `meta === ['ref' => 'phase-8-smoke']`

Cast `array` do `DatabaseNotification` (vendor linhas 47–50) faz o round-trip
JSON automático — nenhum `json_decode` manual. Pitfall 3 do RESEARCH (acessar
`data` como string crua) também respeitado.

## Estado da suíte `Phase8FoundationTest` ao final do plano

| Teste                                                | Estado            | Onde fica GREEN |
| ---------------------------------------------------- | ----------------- | --------------- |
| `test_migration_cria_tabela_notifications`          | **GREEN** (Plan 01) | Plan 01 (Slice 1) |
| `test_permissions_all_inclui_notificacoes_criar`    | `markTestIncomplete` | Plan 03 (Slice 3) |
| `test_catalog_inclui_grupo_notificacoes`            | `markTestIncomplete` | Plan 03 (Slice 3) |
| `test_auto_lideranca_inclui_notificacoes_criar`     | `markTestIncomplete` | Plan 03 (Slice 3) |
| `test_admin_tem_permissao_via_short_circuit`        | `markTestIncomplete` | Plan 03 (Slice 3) |
| `test_lider_tem_permissao_via_auto_lideranca`       | `markTestIncomplete` | Plan 03 (Slice 3) |
| `test_base_notification_persiste_payload_canonico`  | **GREEN** (Plan 02 — este plan) | Plan 02 (Slice 2) |

**Total ao final do Plan 02:** 2 GREEN / 7 (29%), 5 incomplete. Mesmo padrão
das 5 incompletes que esperam Plans 03 e 04 — nada quebrou, nada falhou.

## Sequência TDD intra-plan (RED → GREEN)

| Etapa     | Estado                                                                                | Causa |
| --------- | ------------------------------------------------------------------------------------- | ----- |
| Antes de T1 | Test 7 em `markTestIncomplete` (Plan 01 deixou assim por design)                    | — |
| Após T1   | Test 7 ainda em `markTestIncomplete`; `Categoria::MANUAL->value === 'manual'` verificado via `php -r` (acceptance criterion da Tarefa 1) | Enum existe; smoke ainda não tem `BaseNotification` para estender |
| Após T2   | Test 7 ainda em `markTestIncomplete`; classe `BaseNotification` carrega via autoload, `isAbstract === true` verificado por reflection | Classe existe; smoke ainda não foi preenchido |
| Após T3   | **Test 7 GREEN** — 11 assertions; suíte mostra 2 passed / 5 incomplete / 0 failed     | Smoke preenchido + ordem TDD respeitada (1 → 2 → 3, sequencial intra-plan W3) |

Sequencialidade intra-plan estritamente respeitada — o plan W3 trava paralelismo
porque Tarefa 2 depende do tipo `Categoria $categoria` no construtor e Tarefa 3
depende de ambos para instanciar a classe anônima.

## Deviations from Plan

### Auto-fixed Issues

**Nenhuma deviation Rule 1/2/3.** As 3 tarefas seguiram o `<action>` do PLAN.md
quase literalmente (com a única variação descrita abaixo, que é uma decisão
estilística permitida pelas acceptance criteria do próprio plan).

### Decisão estilística no Test 7

O `<action>` da Tarefa 3 sugere FQCN inline (`\Illuminate\Support\Facades\Notification::send`,
`\Illuminate\Notifications\DatabaseNotification::query()`, etc.). Após a edição
inicial com FQCN, os diagnósticos do IDE acusaram **7 imports unused** no topo
do arquivo (`User`, `BaseNotification`, `Categoria`, `Notification`,
`DatabaseNotification`, etc. — todos já presentes desde Plan 01 antecipando os
demais testes). A acceptance criterion **explicitamente permite ambos**:

> "`extends \App\Notifications\BaseNotification` (com `\` ou via import — equivalente)"

Refatorei o Test 7 para usar `BaseNotification`, `Categoria`, `User`,
`Notification` e `DatabaseNotification` via `use` (consumindo os imports já
presentes), reduzindo ruído de IDE e mantendo legibilidade — sem violar nenhum
critério. Comportamento idêntico: 11 asserts, todos verdes.

### Out-of-scope discoveries (deferred)

Nenhum. DEF-08-01 (driver-guard em `rename_legacy_columns`) já foi corrigido
no commit de base pelo orquestrador (`9f508f8`) antes do spawn deste agente —
a suíte agora roda em SQLite via `RefreshDatabase` sem prova alternativa.
Confirmação: Test 7 rodou normalmente via `php artisan test --filter=...`
com 11 asserts verdes em 0.07s.

## Threat Flags

Nenhuma nova superfície de segurança introduzida. T-08-04 (categoria inválida)
mitigada estruturalmente via tipagem PHP do parâmetro `Categoria $categoria`
no construtor — PHP rejeita em runtime qualquer valor que não seja um dos 3
cases do enum. T-08-05 (cross-tenant leak) e T-08-06 (instanciação direta da
abstrata) seguem o threat_model do plano:

- **T-08-06:** mitigada — `php -r "new App\\Notifications\\BaseNotification(...)"` lança fatal `Cannot instantiate abstract class`, comportamento esperado.
- **T-08-05:** aceito para Phase 8 (sem endpoint exposto); Phase 9 endereça via `$user->notifications` (filtro morphTo automático).
- **T-08-04:** mitigado via tipagem estrutural (compile/runtime). Phase 9 adicionará validação adicional `Categoria::tryFrom()` no read path.

## Known Stubs

Os **5 testes ainda em `markTestIncomplete`** (`test_permissions_all_inclui_notificacoes_criar`,
`test_catalog_inclui_grupo_notificacoes`, `test_auto_lideranca_inclui_notificacoes_criar`,
`test_admin_tem_permissao_via_short_circuit`, `test_lider_tem_permissao_via_auto_lideranca`)
são **stubs intencionais herdados do Plan 01**. Cada um declara no corpo qual
slice futura preenche:

| Stub                                              | Resolvido em |
| ------------------------------------------------- | ------------ |
| `test_permissions_all_inclui_notificacoes_criar`  | Plan 03 (Slice 3) |
| `test_catalog_inclui_grupo_notificacoes`          | Plan 03 (Slice 3) |
| `test_auto_lideranca_inclui_notificacoes_criar`   | Plan 03 (Slice 3) |
| `test_admin_tem_permissao_via_short_circuit`      | Plan 03 (Slice 3) |
| `test_lider_tem_permissao_via_auto_lideranca`     | Plan 03 (Slice 3) |

Nenhum stub bloqueia o objetivo deste plan (domain types + smoke E2E) — todos
fazem parte da fundação Permissions, escopo do Plan 03.

## Próxima Slice

**Plan 03 — Slice 3: Permission Catalog (PERM-01 + PERM-03 registro)**

- Adicionar `Permissions::NOTIFICACOES_CRIAR = 'notificacoes.criar'` (D-07)
- Adicionar grupo `'Notificações'` em `Permissions::catalog()` entre Sistema e Liderança (D-08)
- Adicionar `NOTIFICACOES_CRIAR` em `Permissions::AUTO_LIDERANCA` (D-09)
- Preencher Tests 2–6 (Permissions + AUTO_LIDERANCA + admin short-circuit + líder auto-grant)

## Confirmação de pt-BR

- **Enum `Categoria`:** PHPDoc de classe + 1 PHPDoc por case, todos em pt-BR.
- **Classe `BaseNotification`:** PHPDoc de classe + PHPDoc do construtor + PHPDoc de cada método público (`via`, `toArray`) + comentários inline justificando D-01/D-02/D-04/D-06, todos em pt-BR.
- **Test 7:** Comentários inline em pt-BR explicando setup, classe anônima (D-04), `Notification::send` real (Pitfall 1), `assertNotEmpty($row->type)` (W4 + Pitfall 2), e cada bloco de asserts.
- **Commits:** 3 commits em pt-BR seguindo a convenção `tipo(phase-plan): descrição`.
- **Termos técnicos mantidos em inglês:** `abstract class`, `enum`, `backed string`, `named arguments`, `property promotion`, `cast`, `DatabaseChannel`, `payload`, etc. — política do CLAUDE.md.

## Self-Check: PASSED

- `app/Notifications/Categoria.php` — FOUND
- `app/Notifications/BaseNotification.php` — FOUND
- `tests/Feature/Notifications/Phase8FoundationTest.php` — FOUND (Test 7 preenchido, demais 5 ainda em markTestIncomplete)
- Commit `c66d455` (enum Categoria) — FOUND no histórico
- Commit `f574514` (classe BaseNotification) — FOUND no histórico
- Commit `f534676` (Test 7 preenchido) — FOUND no histórico
- `php artisan test --filter=Phase8FoundationTest::test_base_notification_persiste_payload_canonico` → GREEN com 11 asserts
- `php artisan test --filter=Phase8FoundationTest` → 2 passed (Tests 1+7) + 5 incomplete + 0 failed
