---
phase: 12-criacao-manual-permissao-ui-cleanup
plan: 01
subsystem: notifications
tags: [notifications, manual-dispatch, permissions, activity-log, cleanup, milestone-close]

dependency-graph:
  requires:
    - app/Notifications/BaseNotification.php (Phase 8)
    - app/Notifications/Categoria.php (Phase 8 — MANUAL)
    - tabela notifications (Phase 8 migration)
    - permission_key `notificacoes.criar` (Phase 8 — catalog + AUTO_LIDERANCA)
    - rotas notificacoes.index/contador/recentes/marcar-* (Phase 9 + 10)
    - middleware alias `permission` → EnsurePermission (pré-existente)
    - spatie/laravel-activitylog (pré-existente)
  provides:
    - app/Notifications/ManualNotification.php (Categoria::MANUAL, autorUserId humano)
    - app/Console/Commands/NotificationsCleanup.php (Artisan `notifications:cleanup`)
    - NotificacaoController::nova() + criar() (2 métodos novos)
    - 2 rotas novas: notificacoes.nova (GET) + notificacoes.criar (POST)
    - Page Notificacoes/Nova.jsx (form de envio manual com 4 públicos)
    - Sidebar item "Enviar notificação" (gated por notificacoes.criar)
    - Schedule notifications-cleanup às 04:00 diário
  affects:
    - resources/js/Layouts/AppLayout.jsx (novo NAV_ITEMS + import `Send`)
    - routes/web.php (sub-grupo permission:notificacoes.criar)
    - routes/console.php (entrada Schedule notifications-cleanup)
    - app/Http/Controllers/NotificacaoController.php (2 métodos novos, 4 imports novos)

tech-stack:
  added: []
  patterns:
    - "Sub-grupo de rotas com `Route::middleware('permission:notificacoes.criar')->group()` reaproveitando middleware alias existente"
    - "match expression para resolução polimórfica de destinatários (usuario/setor/lideres/todos)"
    - "Activity log apenas em dispatch manual via helper global `activity()` (diferenciação intencional de automáticos da Phase 11)"
    - "Artisan command com `withoutOverlapping()` + horário 04:00 (antes de calculate-goal-results 06:00)"

key-files:
  created:
    - app/Notifications/ManualNotification.php
    - app/Console/Commands/NotificationsCleanup.php
    - resources/js/Pages/Notificacoes/Nova.jsx
    - tests/Feature/Notifications/Phase12ManualTest.php
    - .planning/phases/12-criacao-manual-permissao-ui-cleanup/12-01-SUMMARY.md
  modified:
    - app/Http/Controllers/NotificacaoController.php (+2 métodos, +4 imports)
    - routes/web.php (+sub-grupo de 2 rotas notificacoes.nova/criar)
    - routes/console.php (+Schedule notifications-cleanup às 04:00)
    - resources/js/Layouts/AppLayout.jsx (+import Send +1 NAV_ITEMS gated)

decisions:
  - "D-01: ManualNotification é classe concreta separada de BaseNotification — facilita filtro por type FQCN nos testes e activity_log e diferencia visualmente de MetaAtribuidaNotification/MetaAtingidaNotification (Phase 11)."
  - "D-02: Activity log apenas em envios manuais (POLL-05 explícito). Automáticos da Phase 11 não logam — evita inundação do activity_log com eventos sistêmicos rotineiros."
  - "D-03: Inclui autor no público=todos sem self-exclusion (decisão D-04 do plano) — `User::where('active', true)` retorna o próprio autor quando ele está ativo. Consistente com semântica de \"todos os usuários ativos\" sem exceções."
  - "D-04: Cleanup às 04:00 (antes de calculate-goal-results 06:00). Janela exclusiva via `withoutOverlapping()` evita corrida com sync Adman ou cálculo de metas. Idempotente — delete sem cascata."
  - "D-05: Página Nova.jsx usa `<select>` nativo para escolher público + subcampo dinâmico (usuario_id input numérico ou setor_id select). Busca por nome de usuário fica para fase futura — MVP só com ID numérico."
  - "D-06: Tarefa 5 entregue como 10 testes em um único arquivo Phase12ManualTest (não dividida em Phase12ManualTest + Phase12CleanupTest) — coesão da suíte facilita o filter combinado na verificação final."

metrics:
  duration: "~30 min execução automatizada"
  completed: 2026-05-21
  tarefas: 6
  arquivos_criados: 4
  arquivos_modificados: 4
  commits: 5
  testes_novos: 10
  testes_total_notifications: 33
---

# Phase 12 Plan 01: Criação Manual + Permissão + Cleanup Summary

**One-liner:** Adiciona `ManualNotification`, endpoints `nova()`/`criar()` gated por `permission:notificacoes.criar`, página de envio com 4 públicos (usuario/setor/lideres/todos), activity log do envio e command Artisan `notifications:cleanup` agendado para 04:00 — fechando a milestone v3.0.

## Goal Achieved

A milestone v3.0 — Sistema de Notificações — está encerrada. Usuários com permissão
`notificacoes.criar` (admin sempre; líderes via AUTO_LIDERANCA; outros via setor
explícito) acessam `/notificacoes/nova`, escolhem público (individual / setor /
líderes / todos ativos) e disparam notificações manuais que aparecem em tempo real
no sino + página de histórico (entregue pelas Phases 9/10). Cada envio manual é
auditado em `activity_log` (POLL-05). O scheduler diário (04:00) remove
notificações lidas com mais de 30 dias (POLL-04), mantendo a tabela enxuta.

## Tasks Executed

| # | Tarefa | Commit | Status |
|---|--------|--------|--------|
| 1 | ManualNotification + NotificationsCleanup + scheduler entry | `3ebeea0` | ✓ |
| 2 | NotificacaoController::nova/criar + 2 rotas com gating | `d2e98de` | ✓ |
| 3 | Sidebar item "Enviar notificação" no AppLayout | `d514a6f` | ✓ |
| 4 | Página Notificacoes/Nova.jsx | `f32ce06` | ✓ |
| 5 | Phase12ManualTest (10 testes E2E) | `83a4efc` | ✓ |
| 6 | Build Vite + verificações de suítes 8-12 + smoke route/schedule | — | ✓ (33/33 GREEN, 7 rotas, schedule OK) |

## Verification

### Tests
- **Phase12ManualTest:** 10/10 GREEN (59 assertions, ~1.19s)
- **Regression Phases 8-11:** 23/23 GREEN
  - Phase8FoundationTest: 7/7
  - Phase9BackendTest: 6/6
  - Phase10UiTest: 4/4
  - Phase11AutoTest: 6/6
- **Total Notifications:** 33/33 GREEN (219 assertions, ~1.79s)

### Build
- `npm run build` — Vite manifest atualizado, novo asset `Notificacoes/Nova.jsx`
  bundled (3 entradas em manifest.json correspondendo ao chunk + dependencies)
- PHP lint clean em todos os 4 arquivos PHP criados/modificados

### Smoke
- `artisan route:list --name=notificacoes` → **7 rotas** (5 antigas + nova + criar)
- `artisan list` mostra `notifications:cleanup` com descrição em pt-BR
- `artisan schedule:list` mostra `notifications:cleanup` em `0 4 * * *` ("Next Due: em 7 horas")

## Requirements Coverage

| ID | Descrição | Onde foi entregue | Teste |
|----|-----------|-------------------|-------|
| ENVIO-01 | User com permissão acessa /notificacoes/nova | `permission:notificacoes.criar` middleware + sidebar item | `test_envio_01_user_com_permissao_acessa_nova_com_setores` |
| ENVIO-02 | Targeting por 4 públicos (usuario/setor/lideres/todos) | `criar()` match expression + resolução de destinatários | `test_envio_02_publico_setor_envia_para_membros_apenas`, `test_envio_02_publico_lideres_envia_para_lideres_apenas`, `test_envio_02_publico_todos_envia_para_users_ativos` |
| ENVIO-03 | Validation rejeita título/mensagem vazios ou >limit | `$request->validate` regras max:100/max:1000 | `test_envio_03_validation_rejeita_titulo_e_mensagem_invalidos` |
| ENVIO-04 | Flash success contém contagem exata de destinatários | `back()->with('success', "...{$count} destinatário(s)")` | `test_envio_04_e_poll_05_flash_count_e_activity_log_registrado` |
| ENVIO-05 | User sem permissão recebe 403 em GET e POST | EnsurePermission aborta com 403 | `test_envio_05_user_sem_permissao_recebe_403_em_get_nova`, `test_envio_05_user_sem_permissao_recebe_403_em_post_criar` |
| PERM-04 | Catálogo Permissions inclui grupo Notificações | Já entregue pela Phase 8; sanity test nesta phase | `test_perm_04_catalog_inclui_notificacoes_criar` |
| POLL-04 | Cleanup diário de notificações lidas >30 dias | Artisan command + Schedule às 04:00 | `test_poll_04_cleanup_remove_lidas_antigas_e_preserva_unread_e_recentes` |
| POLL-05 | Activity log registra envios manuais com causedBy + properties | `activity()->causedBy()->withProperties()->log(...)` em `criar()` | `test_envio_04_e_poll_05_flash_count_e_activity_log_registrado` |

## Decisions Made

### D-01 — ManualNotification como classe concreta separada

Diferente do approach do plano original que sugeria reuso direto de BaseNotification
via classe anônima nos dispatch sites, optei por criar `ManualNotification` como
classe concreta extends BaseNotification, alinhada com o padrão da Phase 11
(MetaAtribuidaNotification + MetaAtingidaNotification).

**Benefícios:**
- Filtro por type FQCN nos testes E2E (`assertDatabaseHas type =
  ManualNotification::class`)
- Diferenciação clara no `activity_log` quando consumir o type da notification
- Construtor enxuto especializa apenas `categoria = MANUAL` e `url =
  route('notificacoes.index')`

### D-02 — Activity log apenas em envios manuais

POLL-05 é explícito: log apenas em criações manuais, não em automáticas. Para
preservar essa contrato:
- `NotificacaoController::criar` invoca `activity()->...->log()` após dispatch
- `MetaAtribuidaNotification` / `MetaAtingidaNotification` (Phase 11) NÃO chamam
  `activity()` em seus dispatch sites

Isso evita inundação do `activity_log` com 1 entry por meta atribuída × N
membros notificados, mantendo o log focado em ações humanas auditáveis.

### D-03 — Inclui autor no público=todos

Decisão D-04 do plano: `publico=todos` envia para `User::where('active', true)`
SEM exclusão do autor. Implicação:
- Autor recebe sua própria notificação manual quando escolhe "Todos"
- Test 7 (`test_envio_02_publico_todos_envia_para_users_ativos`) valida que
  autor (admin, active=true) está entre os 4 destinatários quando há 3 outros
  active + 1 inactive

Razão: "Todos os usuários ativos" tem semântica universal sem exceções — adicionar
self-exclusion seria comportamento surpresa (autor pode querer registro da
própria mensagem na linha do tempo dele).

### D-04 — Cleanup às 04:00 (janela exclusiva)

Schedule a `dailyAt('04:00')` evita colidir com:
- `goals:calculate` (06:00)
- `sugadores:analyze` (06:30)
- `sync-ml-grants-sftp` (03:00 — termina muito antes)
- `adman:sync` (a cada 5 min — operação curta, sobreposição mitigada por
  `withoutOverlapping()`)

Delete é idempotente (sem cascata, escopo `read_at < cutoff` deterministicamente
fixado no início do handle).

### D-05 — UI MVP com `<select>` nativo + ID numérico de usuário

Para o público=usuario, a UI usa `<input type="number">` para o ID, não busca
por nome. Razão: phase enxuta com foco em fechar a milestone — busca/typeahead
de usuários é refinamento de UX para fase futura. Backend já valida
`exists:users,id` então erros são reportados via flash.

### D-06 — 10 testes em arquivo único Phase12ManualTest

Plano sugeria divisão opcional (Phase12ManualTest + Phase12CleanupTest); optei
por manter os 10 testes em um único arquivo pois:
- Suíte total ainda é pequena (~1.2s)
- Filter combinado fica mais simples (`--filter=Phase12ManualTest`)
- Helpers internos (`autorComPermissao`, `autorViaSetorPermissao`,
  `userSemPermissao`) ficam reutilizados sem duplicação cross-file

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Vite manifest faltando bloqueava teste de Inertia render**

- **Found during:** Tarefa 5 (rodando Phase12ManualTest pela primeira vez)
- **Issue:** Teste `test_envio_01_user_com_permissao_acessa_nova_com_setores`
  falhou com `Unable to locate file in Vite manifest: resources/js/Pages/Notificacoes/Nova.jsx`
  ao tentar renderizar a página via Inertia. O manifest do Vite no main repo
  ainda não tinha a entrada `Nova.jsx` porque o build havia sido feito antes
  da criação do arquivo (Tarefa 4).
- **Fix:** Executei `npm run build` no main repo (worktree não tem node_modules)
  após a Tarefa 4 e antes da execução da suíte na Tarefa 6.
- **Files modified:** nenhum (apenas regeração do manifest)
- **Commit:** N/A (build artifact gitignored em `public/build/`)

Nenhum outro desvio. Todas as tarefas seguiram o plano literalmente.

## Trade-offs

### Sidebar item posicionado logo após "Setores"

Plano sugeria "perto dos outros utilitários, antes de Meu Setor ou após Setores".
Escolhi após Setores (linha 26 de `AppLayout.jsx`) — agrupa visualmente com
itens de governança (Usuários / Setores / Enviar notificação). Líderes/admins
veem esses itens próximos no scroll.

### Activity log apenas em manuais é assimétrico

Disparos automáticos da Phase 11 NÃO entram no activity_log, enquanto disparos
manuais entram. Razão documentada na D-02. Trade-off: para uma auditoria
completa "quais notificações foram disparadas hoje?", o admin precisa cruzar
2 fontes:
- `activity_log` (apenas manuais)
- `notifications.created_at` (tudo, mas sem causedBy quando autor=null)

Mitigado: Phase 10 já popula `autor_nome = null → "Sistema"` na UI, então o
read path tem clareza visual sobre origem.

## Known Stubs

Nenhum. Todos os 4 públicos resolvem destinatários reais via queries Eloquent
diretas. Página Nova.jsx é totalmente funcional (sem placeholder/TODO).

A entrada de usuário via ID numérico (vs. typeahead) é uma decisão de MVP
explícita (D-05), não um stub — funciona, apenas é menos refinada que a fase
futura.

## Threat Flags

Nenhum threat surface novo:
- `POST /notificacoes/nova` é gated por permission via middleware (não há
  bypass de gating em controller)
- Validation reject inputs maliciosos (max:100/max:1000 + exists nos IDs)
- Dispatch usa `Notification::send` que delega ao canal database confiável
  da Phase 8
- Activity log registra causer real (`$request->user()`) — não há forjamento
  via params do form

## Self-Check: PASSED

Verificações de arquivos:
- `app/Notifications/ManualNotification.php` — FOUND
- `app/Console/Commands/NotificationsCleanup.php` — FOUND
- `app/Http/Controllers/NotificacaoController.php` — FOUND (com 2 métodos novos)
- `resources/js/Pages/Notificacoes/Nova.jsx` — FOUND
- `resources/js/Layouts/AppLayout.jsx` — FOUND (com Send import + NAV_ITEMS)
- `routes/web.php` — FOUND (com sub-grupo permission)
- `routes/console.php` — FOUND (com Schedule notifications-cleanup)
- `tests/Feature/Notifications/Phase12ManualTest.php` — FOUND

Verificações de commits (worktree):
- `3ebeea0` ManualNotification + NotificationsCleanup + scheduler — FOUND
- `d2e98de` nova/criar + rotas gated — FOUND
- `d514a6f` sidebar item — FOUND
- `f32ce06` Nova.jsx page — FOUND
- `83a4efc` Phase12ManualTest — FOUND

Verificação de testes:
- Phase12ManualTest 10/10 GREEN, 59 assertions, 1.19s
- Regression Phases 8-11: 23/23 GREEN
- Total Notifications: 33/33 GREEN, 219 assertions, 1.79s

Verificação de smoke:
- `route:list --name=notificacoes` → 7 rotas
- `artisan list` contém `notifications:cleanup`
- `schedule:list` contém `notifications-cleanup` em `0 4 * * *`
