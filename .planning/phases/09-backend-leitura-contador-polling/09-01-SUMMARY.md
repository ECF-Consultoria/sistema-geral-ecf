---
phase: 09-backend-leitura-contador-polling
plan: 01
status: complete
completed: 2026-05-21
duration_minutes: ~20
tasks_completed: 5/5
tests:
  phase_9:
    suite: Phase9BackendTest
    passed: 6
    failed: 0
    assertions: 43
    duration_seconds: 0.97
  regression_phase_8:
    suite: Phase8FoundationTest
    passed: 7
    failed: 0
    assertions: 33
    duration_seconds: 1.01
requirements_delivered:
  - POLL-01  # shared prop notificacoes_nao_lidas
  - POLL-02  # endpoint /api/notificacoes/contador
  - POLL-03  # closure → recálculo automático em toda navegação Inertia
  - HIST-01  # rota notificacoes.index com paginação
  - HIST-03  # marcar uma como lida + 403 anti-cross-user
  - HIST-04  # marcar todas como lidas
key_files:
  created:
    - app/Http/Controllers/NotificacaoController.php — controller com 4 métodos públicos (index/contador/marcarLida/marcarTodasLidas)
    - resources/js/Pages/Notificacoes/Index.jsx — stub Inertia (UI plena na Phase 10)
    - tests/Feature/Notifications/Phase9BackendTest.php — suíte canônica 6 testes
  modified:
    - app/Http/Middleware/HandleInertiaRequests.php — +1 closure shared prop `notificacoes_nao_lidas`
    - routes/web.php — +5 rotas (1 use import + 4 rotas no grupo auth)
deviations: []
---

# Phase 9 — Slice 01: Backend de Leitura, Contador e Polling

## Resumo

Backend completo do sistema de Notificações entregue end-to-end: 4 rotas autenticadas
(`notificacoes.contador`, `notificacoes.index`, `notificacoes.marcar-lida`,
`notificacoes.marcar-todas-lidas`), shared prop Inertia `notificacoes_nao_lidas`
injetada via closure, controller com 4 métodos públicos, página Inertia stub
funcional, e suíte de teste 6/6 GREEN sem `Notification::fake()`.

Tudo testável via HTTP/Tinker antes da UI da Phase 10 existir.

## Resultados Empíricos

```
PASS  Tests\Feature\Notifications\Phase9BackendTest
  ✓ shared prop notificacoes nao lidas reflete contagem      0.55s
  ✓ shared prop e zero quando user sem notificacoes          0.04s
  ✓ endpoint contador retorna json com count                 0.04s
  ✓ index retorna inertia com notificacoes paginadas         0.08s
  ✓ marcar lida funciona no dono e retorna 403 em alheia     0.05s
  ✓ marcar todas lidas zera unread count do user             0.03s

Tests:    6 passed (43 assertions)
Duration: 0.97s
```

Regression Phase 8 também GREEN (7 passed, 33 assertions).

## Decisões Implementadas

| ID | Decisão | Implementação |
|----|---------|---------------|
| D-01 | Endpoint contador JSON `{count: N}` | `app/Http/Controllers/NotificacaoController.php::contador()` |
| D-02 | Shared prop via closure (não valor estático) | `HandleInertiaRequests.php` linha após `sugadores_pendentes` |
| D-03 | 403 via `abort_unless` por dono (não Gate) | `NotificacaoController::marcarLida()` |
| D-04 | Mark-all em uma query (`->update()`) | `NotificacaoController::marcarTodasLidas()` |
| D-05 | Stub Inertia mínimo (`<pre>JSON</pre>`) | `Notificacoes/Index.jsx` — UI plena Phase 10 |
| D-06 | Testes com `Notification::send` real | `Phase9BackendTest.php` — sem `Notification::fake()` |

## Sequência de Execução

1. **Tarefa 1** (commit `b9d16fb`): suíte de teste Phase9BackendTest com 6 testes RED.
2. **Tarefa 2** (commit `ef3e0ec`): NotificacaoController + 4 rotas — torna Tests 3/4/5/6 GREEN.
3. **Tarefa 3** (commit `4370631`): shared prop via closure em HandleInertiaRequests — torna Tests 1/2 GREEN.
4. **Tarefa 4** (commit `3ea9cda`): stub Inertia `Notificacoes/Index.jsx` — habilita Inertia render.
5. **Tarefa 5** (inline pelo orquestrador): `npm run build` + run suíte completa + SUMMARY.md.

## Nota de Execução (handoff inline)

O agente executor (gsd-executor worktree, agentId `ad64806e63e06c861`) atingiu o limite de sessão
após commitar as Tarefas 1-4. O orquestrador fechou a Tarefa 5 inline: mergeou o worktree, rodou
`npm run build` (necessário para o Vite manifest do `Notificacoes/Index.jsx`), executou a suíte
6/6 GREEN, fez regression check Phase 8 7/7 GREEN, e escreveu este SUMMARY.

Tentativa anterior interrompida deixou um worktree órfão (`agent-a46302bee7e7d49be`) que foi
descartado em segurança — todos os artefatos canônicos vieram do worktree mais recente
(`agent-ad64806e63e06c861`).

## Próximos Passos

- **Phase 10**: UI do Sino + Página de Histórico — consome a shared prop `notificacoes_nao_lidas`
  para o badge e os endpoints existentes para mark-as-read inline. A página `Notificacoes/Index.jsx`
  deve substituir o stub atual com layout completo (tabs Não lidas/Todas, cards por categoria,
  botão Marcar todas como lidas).
- **Phase 11**: Disparos automáticos de metas via subclasses concretas de `BaseNotification` —
  pode chamar `Notification::send($users, new MetaAtribuidaNotification(...))` direto.
