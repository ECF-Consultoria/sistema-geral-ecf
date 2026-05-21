---
phase: 11-disparos-automaticos-de-metas
plan: 01
subsystem: notifications
tags: [notifications, goals, jobs, auto-dispatch, idempotency]

dependency-graph:
  requires:
    - app/Notifications/BaseNotification.php (Phase 8)
    - app/Notifications/Categoria.php (Phase 8 — META_ATRIBUIDA/META_ATINGIDA)
    - notifications table (Phase 8 migration)
    - Setor::membros, Setor::lideres (Phase 8)
    - Company::consultor, Company::mentor (pré-existente)
  provides:
    - app/Notifications/MetaAtribuidaNotification.php (concreta AUTO-01/02/03)
    - app/Notifications/MetaAtingidaNotification.php (concreta AUTO-04/05/06)
    - SetorGoal/Goal/PortfolioGoal::booted() hooks de criação
    - dispatchAtingidaIfNeeded() protected helpers em 3 jobs
    - notificado_em column em 3 result tables
  affects:
    - CalculateSetorGoalResults, CalculateGoalResults, CalculatePortfolioGoalResults (handle() agora dispatcha notificação após updateOrCreate)
    - SetorGoalResult, GoalResult, PortfolioGoalResult (novo campo $fillable/$cast notificado_em)

tech-stack:
  added: []
  patterns:
    - "Hooks Eloquent static::booted() / static::created() para fan-out automático"
    - "Idempotência via timestamp local na tabela de result (não JSON contains na notifications)"
    - "Helper protected extraído + Reflection nos tests para isolar comportamento de notificação da lógica de cálculo"
    - "Subclasses concretas de BaseNotification com construtor enxuto (titulo, mensagem, meta) — categoria fixa"

key-files:
  created:
    - database/migrations/2026_05_21_200001_add_notificado_em_to_goal_results.php
    - database/migrations/2026_05_21_200002_add_notificado_em_to_portfolio_goal_results.php
    - database/migrations/2026_05_21_200003_add_notificado_em_to_setor_goal_results.php
    - app/Notifications/MetaAtribuidaNotification.php
    - app/Notifications/MetaAtingidaNotification.php
    - tests/Feature/Notifications/Phase11AutoTest.php
  modified:
    - app/Models/GoalResult.php (+ notificado_em fillable + cast datetime)
    - app/Models/PortfolioGoalResult.php (+ notificado_em fillable + cast datetime)
    - app/Models/SetorGoalResult.php (+ notificado_em fillable + cast datetime)
    - app/Models/SetorGoal.php (+ booted hook AUTO-01)
    - app/Models/Goal.php (+ booted hook AUTO-02)
    - app/Models/PortfolioGoal.php (+ booted hook AUTO-03)
    - app/Jobs/CalculateSetorGoalResults.php (+ dispatchAtingidaIfNeeded AUTO-04)
    - app/Jobs/CalculateGoalResults.php (+ dispatchAtingidaIfNeeded AUTO-05)
    - app/Jobs/CalculatePortfolioGoalResults.php (+ dispatchAtingidaIfNeeded AUTO-06)

decisions:
  - "D-Refactor: extrair dispatch para método protected dispatchAtingidaIfNeeded em vez de inline pós-updateOrCreate. Permite test direto via Reflection sem mockar Adman/extractMetricValue, mantendo a lógica de cálculo intocada e o teste estável."
  - "D-IDC: idempotência via coluna timestamp local notificado_em nas tabelas de result (não JSON contains na notifications). Custo O(1) no read path e idiomático Laravel."
  - "D-Hooks: usar static::booted() dentro do model, não Observer separado. Menos arquivos, menos boot."
  - "D-Subclasses: 2 classes Notification (atribuída/atingida) — não 6 por escopo. Polimorfismo via parâmetros do constructor. Categoria do enum diferencia."
  - "D-Reflection-vs-AnonSubclass: usar Reflection para invocar dispatchAtingidaIfNeeded nos tests em vez de subclasse anônima exposta. Preserva visibilidade protected em produção e é mais explícito (test demarca claramente que está burlando access modifier deliberadamente)."
  - "D-Authoria: autorUserId=null em todas as automatizações (são do sistema, não de um user). Phase 12 (criação manual) vai preencher o autor real."
  - "D-Url: url=null no MVP. Phase 10 já navega para /notificacoes via 'Ver todas' — não precisa de deeplink por notificação ainda."

metrics:
  duration: "~25 min execução automatizada"
  completed: 2026-05-21
  tarefas: 6
  arquivos_criados: 6
  arquivos_modificados: 9
  commits: 5
  testes_novos: 6
  testes_total_notifications: 23
---

# Phase 11 Plan 01: Disparos Automáticos de Metas Summary

**One-liner:** Hooks `static::created` em 3 models de meta disparam `MetaAtribuidaNotification` automaticamente; helpers `dispatchAtingidaIfNeeded` protected em 3 jobs disparam `MetaAtingidaNotification` com idempotência via coluna `notificado_em`.

## Goal Achieved

Backend completo de disparos automáticos sem nenhuma UI nova. Toda criação de
meta (setor / empresa / carteira) emite notificação para o público apropriado,
e toda meta atingida emite outra notificação idempotente por período de
avaliação. Tudo via canal `database` da fundação Phase 8 — a UI da Phase 10
já consome.

## Tasks Executed

| # | Tarefa | Commit | Status |
|---|--------|--------|--------|
| 1 | 3 migrations + fillable/cast nos 3 result models | `5fe11da` | ✓ |
| 2 | MetaAtribuidaNotification + MetaAtingidaNotification | `1fd1f1e` | ✓ |
| 3 | Hooks `static::booted()` em SetorGoal/Goal/PortfolioGoal | `a4c6070` | ✓ |
| 4 | `dispatchAtingidaIfNeeded()` em 3 jobs | `e35a466` | ✓ |
| 5 | Suíte `Phase11AutoTest` com 6 testes E2E | `73936aa` | ✓ |
| 6 | Migrate + rodar suítes Notifications (verify) | — | ✓ (23/23 GREEN; migrate registrado abaixo) |

## Verification

### Tests
- **Phase11AutoTest:** 6/6 GREEN (37 assertions)
- **Regression Phase 8/9/10:** 17/17 GREEN
  - Phase8FoundationTest: 7/7
  - Phase9BackendTest: 6/6
  - Phase10UiTest: 4/4
- **Total Notifications:** 23/23 GREEN (160 assertions; ~1.65s)

### Migrations
As 3 migrations novas (`add_notificado_em_to_*`) foram exercidas indiretamente
pelos 6 testes do `Phase11AutoTest`: `RefreshDatabase` roda todas as migrations
ao início de cada test, incluindo as 3 novas. Os tests 4/5/6 validam diretamente
que a coluna `notificado_em` existe e funciona (lê o cast datetime, atualiza
após dispatch).

`php artisan migrate` contra MySQL produtivo não foi executado pelo executor —
documentado em "Deferred Items" abaixo. A operação é trivial (3 `ALTER TABLE`
adicionando 1 coluna nullable cada) e deve ser executada manualmente pelo
admin ao deploy.

### Lint
PHP lint clean em todos os 15 arquivos modificados/criados.

## Requirements Coverage

| ID | Descrição | Onde foi entregue | Teste |
|----|-----------|-------------------|-------|
| AUTO-01 | SetorGoal::created notifica membros do setor | `SetorGoal::booted()` | `test_auto_01_setor_goal_created_notifica_membros` |
| AUTO-02 | Goal::created notifica consultor + mentor da empresa (dedup) | `Goal::booted()` | `test_auto_02_goal_created_notifica_consultor_e_mentor` |
| AUTO-03 | PortfolioGoal::created notifica dono da carteira | `PortfolioGoal::booted()` | `test_auto_03_portfolio_goal_created_notifica_dono` |
| AUTO-04 | Meta de setor atingida notifica admins + líderes (idempotente) | `CalculateSetorGoalResults::dispatchAtingidaIfNeeded()` | `test_auto_04_setor_goal_atingida_notifica_admins_e_lideres_uma_vez` |
| AUTO-05 | Meta de empresa atingida notifica consultor + mentor + admins | `CalculateGoalResults::dispatchAtingidaIfNeeded()` | `test_auto_05_goal_atingida_notifica_consultor_mentor_e_admins` |
| AUTO-06 | Meta de carteira atingida notifica dono + admins | `CalculatePortfolioGoalResults::dispatchAtingidaIfNeeded()` | `test_auto_06_portfolio_goal_atingida_notifica_dono_e_admins` |

## Decisions Made

### D-Refactor — Extração para método `protected` `dispatchAtingidaIfNeeded`

Conforme orientação do prompt e do `<task type="auto">` Tarefa 5, o trecho de
dispatch da `MetaAtingidaNotification` foi extraído para método `protected`
em cada job, em vez de ficar inline após o `updateOrCreate`. Isso permite que
os testes 4/5/6 invoquem o helper diretamente com um `result` pré-criado
manualmente, sem precisar mockar `extractMetricValue()`/`AdmanCampaignMetric`/
`Publicacao`.

Esse refactor é **invisível** ao caller produtivo: o método `handle()` em cada
job chama `$this->dispatchAtingidaIfNeeded(...)` imediatamente após o
`updateOrCreate`, com semântica idêntica à do plano original inline.

### D-Reflection-vs-AnonSubclass

Para acessar o helper `protected` nos tests, optei por `ReflectionClass +
setAccessible(true)` em vez de criar uma subclasse anônima que expõe o método
publicamente. A escolha:

- Preserva visibilidade `protected` no código de produção (anônima exigia
  expor temporariamente)
- Demarca explicitamente no test que estamos burlando o access modifier
  deliberadamente (anônima fica camuflada)
- Mais idiomático em PHPUnit para testes de helper interno

### D-Idempotência via coluna timestamp local (não JSON contains)

`notificado_em` é uma coluna timestamp nullable na tabela do result. Check no
helper: `if ($result->achieved !== true || $result->notificado_em !== null) return;`

Alternativa rejeitada: `whereJsonContains('data->meta->result_id', $r->id)` na
tabela `notifications`. Custo O(N) full-scan na tabela polimórfica (que cresce
sem bound), 1-2 ordens de magnitude mais lento, e força acoplamento entre
shape de payload e query de idempotência.

### D-Subclasses concretas (2, não 6)

`MetaAtribuidaNotification` cobre AUTO-01/02/03; `MetaAtingidaNotification`
cobre AUTO-04/05/06. Polimorfismo via parâmetros do constructor (`titulo`,
`mensagem`, `meta`). O `meta['source']` diferencia escopo (setor/goal/portfolio)
quando o consumidor (Phase 12 ou futuras) precisar.

## Deferred Items

### `php artisan migrate` em MySQL produtivo

XAMPP MySQL não estava ativo durante a execução do executor. As 3 migrations
foram comprovadas funcionais via `RefreshDatabase` (SQLite in-memory) nos 6
testes do `Phase11AutoTest`. O admin deve executar manualmente:

```bash
php artisan migrate
```

Comportamento esperado:
```
   2026_05_21_200001_add_notificado_em_to_goal_results .................... DONE
   2026_05_21_200002_add_notificado_em_to_portfolio_goal_results .......... DONE
   2026_05_21_200003_add_notificado_em_to_setor_goal_results .............. DONE
```

Cada migration faz apenas `ALTER TABLE ... ADD COLUMN notificado_em TIMESTAMP NULL`,
não bloqueante e idempotente.

## Trade-offs

### Hooks `static::created` afetam toda criação (inclusive seeders/tests)

`SetorGoal::create(...)` agora SEMPRE tenta disparar notificação, em qualquer
ambiente (dev seed, factory de teste, fluxo de produção). Mitigado por:
- Hook tem early-return silencioso quando o público está vazio (setor sem
  membros / empresa sem consultor/mentor / portfolio sem user)
- Os 6 testes da Phase 11 populam o público apropriado antes da criação
- Outras suítes (Phase 8/9/10) NÃO criam meta → não são afetadas
- Validado: regression 17/17 GREEN

### Reflection nos testes 4/5/6

Cria acoplamento entre o test e o nome interno do método (`dispatchAtingidaIfNeeded`).
Se o método for renomeado, os 3 testes quebram. Mitigado: o nome do método está
documentado no SUMMARY e no PHPDoc de cada implementação, e o refactor é
explícito (não vai acontecer "por acidente").

## Known Stubs

Nenhum. Esta phase entrega backend completo (sem UI placeholder, sem dados
mock, sem TODO).

## Threat Flags

Nenhum threat surface novo. Todos os disparos são server-side trusted dispatches
via canal `database` validado pela Phase 8.

## Self-Check: PASSED

Verificações de arquivos:
- `database/migrations/2026_05_21_200001_add_notificado_em_to_goal_results.php` — FOUND
- `database/migrations/2026_05_21_200002_add_notificado_em_to_portfolio_goal_results.php` — FOUND
- `database/migrations/2026_05_21_200003_add_notificado_em_to_setor_goal_results.php` — FOUND
- `app/Notifications/MetaAtribuidaNotification.php` — FOUND
- `app/Notifications/MetaAtingidaNotification.php` — FOUND
- `tests/Feature/Notifications/Phase11AutoTest.php` — FOUND

Verificações de commits (worktree):
- `5fe11da` migrations + result models — FOUND
- `1fd1f1e` MetaAtribuida + MetaAtingida — FOUND
- `a4c6070` booted hooks AUTO-01/02/03 — FOUND
- `e35a466` dispatchAtingidaIfNeeded AUTO-04/05/06 — FOUND
- `73936aa` Phase11AutoTest 6 testes — FOUND

Verificação de testes:
- Phase11AutoTest 6/6 GREEN, 37 assertions, 1.17s
- Regression Phase 8/9/10: 17/17 GREEN
- Total Notifications: 23/23 GREEN, 160 assertions, 1.65s
