---
phase: 10-ui-sino-e-pagina-de-historico
plan: 01
status: complete
completed: 2026-05-21
duration_minutes: ~25
tasks_completed: 6/6
tests:
  phase_10:
    suite: Phase10UiTest
    passed: 4
    failed: 0
    assertions: 47
    duration_seconds: 0.94
  regression_phase_9:
    suite: Phase9BackendTest
    passed: 6
    failed: 0
    assertions: 43
    duration_seconds: ~0.21
  regression_phase_8:
    suite: Phase8FoundationTest
    passed: 7
    failed: 0
    assertions: 33
    duration_seconds: ~0.17
  total:
    passed: 17
    failed: 0
    assertions: 123
    duration_seconds: 1.25
requirements_delivered:
  - SINO-01   # sino no header de toda página autenticada
  - SINO-02   # badge numérico amarelo só quando count > 0
  - SINO-03   # dropdown lazy-fetch das 10 mais recentes
  - SINO-04   # cada item com título, prévia, autor, tempo relativo, indicador unread
  - SINO-05   # clicar em unread chama marcar-lida via Inertia + decrementa badge
  - SINO-06   # link "Ver todas" → notificacoes.index
  - HIST-02   # abas "Não lidas" / "Todas" + janela 30d na aba todas
  - HIST-05   # cards com título, mensagem, origem, ícone+cor por categoria, data absoluta
key_files:
  created:
    - resources/js/Components/NotificationBell.jsx — componente sino com badge, polling 60s e dropdown lazy-fetch
    - tests/Feature/Notifications/Phase10UiTest.php — suíte canônica 4 testes backend cobrindo recentes + abas
  modified:
    - app/Http/Controllers/NotificacaoController.php — +método recentes() + filtragem por aba em index() + helper serializar()
    - routes/web.php — +1 rota notificacoes.recentes (GET /api/notificacoes/recentes)
    - resources/js/Layouts/AppLayout.jsx — +import NotificationBell + montagem no header antes do link de perfil
    - resources/js/Pages/Notificacoes/Index.jsx — reescrita do stub: Tabs (nao-lidas/todas), cards por categoria, marcar todas
deviations:
  - id: TIE_BREAK_LATEST
    rule: Rule 1 (bug)
    task: Tarefa 2 (Test 1)
    found_during: execução Tarefa 6 (php artisan test)
    issue: 15 dispatches no mesmo segundo viram empate em created_at — SQLite degenera latest() para insert-order reverso, fazendo Test 1 falhar ao asserir que "Notif 15" é o primeiro.
    fix: Após o send loop, força created_at distinto via DatabaseNotification::update(['created_at' => now()->subSeconds(15 - $idx)]) — Notif 15 = 0s atrás, Notif 1 = 14s atrás. Garante ordem desc estável em qualquer engine.
    files_modified:
      - tests/Feature/Notifications/Phase10UiTest.php
    commit: 6828ea3
---

# Phase 10 — Slice 01: UI do Sino + Página de Histórico

## Resumo

UI completa do sistema de notificações entregue end-to-end. Pela primeira vez o usuário consome notificações reais no produto sem precisar de Tinker ou dev tools:

1. **Sino do header (`<NotificationBell />`)**: ícone Bell + badge amarelo (`ecf-yellow`) numérico cap "99+", consome a shared prop `notificacoes_nao_lidas` (entregue na Phase 9) para o badge inicial, faz polling 60s no endpoint `/api/notificacoes/contador`, e abre dropdown shadcn com lazy-fetch das 10 notificações mais recentes via `/api/notificacoes/recentes`. Clicar em item unread marca como lida via Inertia (`router.patch`) e decrementa o badge sem reload. Link "Ver todas" no rodapé navega para `/notificacoes`.

2. **Página `/notificacoes`**: reescrita completa do stub da Phase 9. Tabs shadcn ("Não lidas" / "Todas"), troca de aba via `router.get(?aba=...)` preservando state/scroll, cards por categoria com ícone e cor (Mail/amarelo para `MANUAL`, Target/azul para `META_ATRIBUIDA`, CheckCircle2/verde para `META_ATINGIDA`), origem (`autor_nome || 'Sistema'`), data absoluta formatada pt-BR, botão "Marcar todas como lidas" no topo quando há unread visível, e botão inline "Marcar como lida" em cada card unread.

3. **Backend estendido (1 método novo + filtragem no index)**: `NotificacaoController::recentes()` retorna JSON com as 10 mais recentes; `index()` agora aceita `?aba=nao-lidas|todas` (default = `nao-lidas`); aba `todas` aplica janela rolling de 30 dias (POLL-04). Helper privado `serializar()` enriquece cada item com `autor_nome` resolvido de `data.autor_user_id`.

4. **Suíte Phase10UiTest 4/4 GREEN**: cobre `recentes` (10 max + ordem desc), filtragem por aba (nao-lidas / todas / default), e janela 30 dias da aba todas. Padrão Phase 9 (classe anônima inline + BaseNotification, sem `Notification::fake`).

5. **Regression 13/13 GREEN**: Phase 8 (7) + Phase 9 (6) — nenhuma quebra introduzida.

6. **Build Vite limpo**: 7.49s, `NotificationBell` inlined no bundle `AppLayout-*.js`, manifest atualizado.

## Tarefas Executadas

| # | Nome                                                                     | Commit  | Arquivos                                                                                  |
| - | ------------------------------------------------------------------------ | ------- | ----------------------------------------------------------------------------------------- |
| 1 | NotificacaoController.recentes() + filtragem por aba + rota              | bcc4c6e | app/Http/Controllers/NotificacaoController.php, routes/web.php                            |
| 2 | Suíte Phase10UiTest (4 testes backend)                                   | 70490bd | tests/Feature/Notifications/Phase10UiTest.php                                             |
| 3 | NotificationBell.jsx — sino com badge, polling e dropdown                | b6dac19 | resources/js/Components/NotificationBell.jsx                                              |
| 4 | Monta NotificationBell no header do AppLayout                            | cb32b0c | resources/js/Layouts/AppLayout.jsx                                                        |
| 5 | Reescreve Notificacoes/Index.jsx com UI completa                         | fd076b6 | resources/js/Pages/Notificacoes/Index.jsx                                                 |
| 6 | Build + suíte (validação) + correção Test 1 tie-break                    | 6828ea3 | tests/Feature/Notifications/Phase10UiTest.php (fix only)                                  |

## Decisões Aplicadas

- **D-01 (executado)**: Badge inicial via `usePage().props.notificacoes_nao_lidas` (shared prop Phase 9). Cada navegação Inertia revalida; `useEffect([sharedCount])` mantém o estado local em sync com o backend.
- **D-02 (executado)**: Polling 60s via `setInterval` no `useEffect` com cleanup no unmount. Endpoint usado: `notificacoes.contador` (Phase 9).
- **D-03 (executado)**: Dropdown shadcn (`@/Components/ui/dropdown-menu`) controlado por `open` state; trigger é um `<button>` com `<Bell />` da lucide-react.
- **D-04 (executado)**: Lazy-fetch das 10 mais recentes só quando o dropdown abre — `fetch(route('notificacoes.recentes'))` com `Accept: application/json` e `credentials: same-origin`.
- **D-05 (executado)**: Tempo relativo via `date-fns/formatDistanceToNow` com `locale: ptBR`. `date-fns ^4.1.0` confirmado no `package.json`.
- **D-06 (executado)**: Aba "Todas" janela = 30 dias rolling (`where('created_at', '>=', now()->subDays(30))`). Test 3 cria 4 unread hoje + 3 read hoje + 2 read 40d atrás → assert 7 (exclui as 2 antigas).
- **D-07 (executado)**: Helper `serializar()` no controller injeta `autor_nome` resolvendo `User::find($autorUserId)?->name`. Quando `autor_user_id` é null, a prop fica null e a UI mostra "Sistema".
- **D-08 (executado)**: Categoria → ícone/cor mapping inline em `Notificacoes/Index.jsx`: `manual → Mail/ecf-yellow/Manual`, `meta_atribuida → Target/blue-400/Meta atribuída`, `meta_atingida → CheckCircle2/green-400/Meta atingida`.

## Rotas Notificacoes (5 total)

| Método | URI                              | Nome                              | Phase |
| ------ | -------------------------------- | --------------------------------- | ----- |
| GET    | /api/notificacoes/contador       | notificacoes.contador             | 9     |
| GET    | /api/notificacoes/recentes       | notificacoes.recentes             | **10** |
| GET    | /notificacoes                    | notificacoes.index                | 9 (estendida na 10) |
| PATCH  | /notificacoes/{id}/marcar-lida   | notificacoes.marcar-lida          | 9     |
| POST   | /notificacoes/marcar-todas-lidas | notificacoes.marcar-todas-lidas   | 9     |

## Verificação Build Vite

- `npm run build` finalizou em 7.49s, 0 erros, 0 warnings críticos.
- Manifest gerado com novos chunks; `NotificationBell.jsx` foi inlined em `AppLayout-*.js` (esperado — é imported direto no layout).
- `Notificacoes/Index.jsx` aparece como chunk próprio (`Index-*.js`).

## Verificação Manual (orientação para o usuário)

Para validar visualmente no browser (após merge + `php artisan serve`):

1. **Sino visível em toda página autenticada** → checar `<header>` à direita do link de perfil. Ícone Bell em qualquer rota `/dashboard`, `/empresas`, etc.
2. **Badge amarelo aparece com contagem** → criar 3 notifications via Tinker:
   ```php
   $u = User::first();
   $u->notify(new \App\Notifications\Manual\... // ou anônima de teste
   ```
   Após dispatch, recarregar página → badge mostra `3`.
3. **Polling 60s** → após 1 minuto, contagem refresca sem reload (verificar Network tab no DevTools).
4. **Dropdown lazy-fetch** → clicar no sino → request `/api/notificacoes/recentes` aparece no Network; dropdown mostra 10 itens com bolinha amarela nas unread.
5. **Marcar como lida no dropdown** → clicar em item unread → request PATCH `/notificacoes/{id}/marcar-lida`; badge decrementa; bolinha amarela some.
6. **Link "Ver todas"** → leva para `/notificacoes` na aba "Não lidas".
7. **Página /notificacoes** → tabs funcionais; troca de aba navega via `?aba=...`; aba "Todas" mostra unread+read dos últimos 30d.
8. **Marcar todas** → botão no topo quando aba ativa = "nao-lidas" e há unread visível.

## Estilo Preservado

- Dark theme `ecf-bg` / `ecf-card` / borders `white/[0.06]` / opacity tokens consistentes com sidebar e demais componentes do projeto.
- `ecf-yellow` usado SOMENTE em estados ativos (badge, link, bolinha unread, border de card unread, label "Manual").
- Categorias usam paleta complementar (`blue-400`, `green-400`) que respeita o dark theme.
- Componentes shadcn já no projeto (`dropdown-menu`, `tabs`, `card`) — nenhum primitivo novo criado.
- Comentários em pt-BR; nomes de routes/rotas/métodos/classes em inglês.

## Threat Flags

Nenhuma nova superfície de segurança introduzida. As 2 rotas afetadas (`notificacoes.index` estendida e `notificacoes.recentes` nova) usam:

- `auth + verified` middleware (mesmo grupo das demais rotas notificacoes.* Phase 9).
- Scope automático via `$request->user()->notifications()` — usa `morphMany('App\Models\User', 'notifiable')` que filtra por `notifiable_id` + `notifiable_type`, impossibilitando cross-user leak por design (T-09-01 da Phase 9 já cobre essa propriedade).
- Sem novos endpoints publicly accessíveis; sem upload, sem exec, sem deserialização não controlada.

## Known Stubs

Nenhum stub introduzido. O stub anterior (`Notificacoes/Index.jsx` da Phase 9) foi substituído pela UI definitiva.

## Self-Check: PASSED

- [x] `app/Http/Controllers/NotificacaoController.php` modificado (verificado: contém `public function recentes`)
- [x] `routes/web.php` modificado (verificado: contém `notificacoes.recentes`)
- [x] `resources/js/Components/NotificationBell.jsx` criado (verificado: contém `export default function NotificationBell`)
- [x] `resources/js/Layouts/AppLayout.jsx` modificado (verificado: linhas 12 e 266 com `NotificationBell`)
- [x] `resources/js/Pages/Notificacoes/Index.jsx` reescrito (verificado: contém `Tabs`, `Card`, `route('notificacoes.marcar-todas-lidas'`)
- [x] `tests/Feature/Notifications/Phase10UiTest.php` criado (verificado: 4 métodos `test_*`, sem `Notification::fake`)
- [x] Commit bcc4c6e existe (Tarefa 1)
- [x] Commit 70490bd existe (Tarefa 2)
- [x] Commit b6dac19 existe (Tarefa 3)
- [x] Commit cb32b0c existe (Tarefa 4)
- [x] Commit fd076b6 existe (Tarefa 5)
- [x] Commit 6828ea3 existe (Tarefa 6 / fix Test 1)
- [x] 17/17 testes GREEN (Phase 8 + 9 + 10)
- [x] 5 rotas `notificacoes.*` registradas
- [x] Build Vite limpo em 7.49s
