---
quick_id: 260602-k3e
plan: "01"
subsystem: mlb
status: complete
tags: [bugfix, regressao, queue, async, 504, mlb, vendas]
dependency_graph:
  requires: [SyncTodasVendasAdmanJob]
  provides: []
  affects: [MlbController.syncTodasVendasAdman]
key_files:
  modified:
    - app/Http/Controllers/MlbController.php
decisions:
  - "Restauração fiel do commit e20a94e (não reescrita): o job já existia e estava instrumentado, então só o controller precisava voltar ao despacho async"
  - "Flash message aponta para /dev/desenvolvimento (não apenas 'logs'): o card de Sync de Vendas MLB foi adicionado lá na dx3, dando observabilidade do progresso"
metrics:
  completed: "2026-06-02"
  commit: "04435a2"
  tasks_completed: 2
  files_modified: 1
---

# Quick Task 260602-k3e: Restaurar despacho assíncrono em syncTodasVendasAdman

**One-liner:** `syncTodasVendasAdman` voltou a despachar `SyncTodasVendasAdmanJob` em vez
de rodar o loop síncrono — elimina o 504 do nginx no botão "Sync Vendas + Preços".

## Causa raiz (regressão)

| Evento | Commit | Data | Efeito |
|--------|--------|------|--------|
| Fix original (async) | `e20a94e` | 22/05 | Moveu o loop para o job; controller retorna <1s |
| **Clobber** | `7b7a2a9` | 25/05 | Feature financeiro sobrescreveu o controller com cópia antiga síncrona → 504 voltou |
| **Esta restauração** | `04435a2` | 02/06 | Reaplica o despacho async no controller |

A reversão foi um desync da VPS (mesmo padrão de `f79e805 "card perdido na sync da VPS"`).
O job `SyncTodasVendasAdmanJob` nunca foi removido — só o controller deixou de usá-lo.

## Tarefas concluídas

| # | Tarefa | Arquivo |
|---|--------|---------|
| 1 | Reimportar `use App\Jobs\SyncTodasVendasAdmanJob;` | app/Http/Controllers/MlbController.php |
| 2 | Restaurar corpo async de `syncTodasVendasAdman` + docblock | app/Http/Controllers/MlbController.php |

**Diff:** 10 inserções, 37 remoções (loop síncrono + `set_time_limit(0)` removidos).

## Verificação

- `php -l app/Http/Controllers/MlbController.php` → **No syntax errors detected**
- `npm run build` → **✓ built in 10.18s** (sem mudança funcional no bundle — alteração é backend-only; rodado por convenção)
- `SyncTodasVendasAdmanJob::dispatch(...)` presente no método ✔
- `use App\Jobs\SyncTodasVendasAdmanJob;` presente nos imports ✔
- Loop síncrono e `set_time_limit(0)` removidos ✔

## Intocado (confirmado)

- `syncVendasAdman` (sync individual de 1 empresa) — inalterado
- `extrairMlbsVendidos` (helper privado) — inalterado
- `SyncTodasVendasAdmanJob` — inalterado
- `routes/web.php`, `resources/js/Pages/Mlb/Empresas.jsx` — não tocados

## Dependência operacional / próximos passos

⚠️ O fix elimina o 504 e efetiva o sync **somente** com o **queue worker rodando** na VPS
(supervisor `ecf-worker:*`). Sem worker, o job fica pendente em `jobs` (sem 504, mas vendas
não atualizam).

- **Requer deploy** (não executado — sem autorização do usuário).
- Pós-deploy, confirmar worker ativo: `supervisorctl status ecf-worker:*`.
- Risco de regressão futura: a mesma reversão por desync de VPS já aconteceu 2x. Considerar
  um teste/guard que falhe se `syncTodasVendasAdman` voltar a conter `set_time_limit` ou loop
  síncrono sobre `MlbEmpresa`.

## Self-Check: PASSED

- [x] Import restaurado
- [x] Método despacha o job
- [x] `php -l` limpo
- [x] Build OK
- [x] Commit `04435a2` criado
- [x] syncVendasAdman e extrairMlbsVendidos intocados
