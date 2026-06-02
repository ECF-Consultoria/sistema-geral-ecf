---
phase: quick-260602-d8i
plan: 01
subsystem: ml-oauth
tags: [mercado-livre, oauth, sync, jobs, react]
dependency_graph:
  requires: [SyncMlCompanyJob, MercadoLivreOAuthController, MlOAuth/Index.jsx]
  provides: [ml.oauth.sync-all rota, syncAll() método, botão sync global no painel ML OAuth]
  affects: [app/Http/Controllers/MercadoLivreOAuthController.php, routes/web.php, resources/js/Pages/MlOAuth/Index.jsx]
tech_stack:
  added: []
  patterns: [fan-out com delay escalonado via SyncMlCompanyJob, fetch POST com CSRF token, useState para loading/feedback]
key_files:
  created: []
  modified:
    - app/Http/Controllers/MercadoLivreOAuthController.php
    - routes/web.php
    - resources/js/Pages/MlOAuth/Index.jsx
decisions:
  - "Delay escalonado 2s entre jobs (herdado do SyncMlData command) — respeita rate limit ML 1.500 req/min"
  - "Aceita ?date=YYYY-MM-DD override para facilitar re-sync manual de datas específicas"
  - "Botão posicionado antes do input de busca no header — agrupa ações globais à direita"
  - "Feedback de syncMsg exibido abaixo do subtitle (connected/pending count) — área menos intrusiva"
metrics:
  duration: ~15min
  completed: 2026-06-02T12:41:04Z
  tasks_completed: 2
  files_modified: 3
---

# Quick Task 260602-d8i: Botão "Sincronizar todas as conectadas" no Painel ML OAuth

**Resumo:** Método `syncAll()` com fan-out assíncrono D-1 via SyncMlCompanyJob + rota `ml.oauth.sync-all` + botão amarelo no header do painel React com estados loading/feedback/disabled.

## O que foi entregue

### Task 1 — Backend: syncAll() + rota ml.oauth.sync-all

- Adicionado `use App\Jobs\SyncMlCompanyJob;` no `MercadoLivreOAuthController`
- Método `syncAll(Request $request): JsonResponse` inserido entre `syncNow()` e `disconnect()`
- Lógica de fan-out idêntica ao comando `ml:sync`: busca empresas `active=true` com `mlToken.status=active`, dispatch assíncrono com delay escalonado de 2s entre jobs
- Trata caso de 0 empresas (loga e retorna `{ enfileiradas: 0, date }`)
- Loga com tag `[MercadoLivre]`, total enfileirado, data e nome do usuário que disparou
- Rota `POST /ml-oauth/sync-all` registrada no grupo admin/role:admin com nome `ml.oauth.sync-all`
- PHP syntax check: sem erros

### Task 2 — Frontend: Botão no header + npm run build

- Estados `syncing` (boolean) e `syncMsg` (string|null) adicionados ao componente `MlOAuthIndex`
- Função `sincronizarTodas()` async com padrão fetch do arquivo (X-CSRF-TOKEN + Accept: application/json)
- Botão inserido no header com:
  - Estilo amarelo `bg-[#ffe116]/10 border border-[#ffe116]/20 text-[#ffe116]` consistente com o arquivo
  - `disabled={syncing || connected.length === 0}` — desabilitado quando não há conectadas ou durante disparo
  - Spinner `<RefreshCw size={11} className="animate-spin" />` durante loading; ícone estático quando idle
  - Texto "Sincronizando…" durante disparo; "Sincronizar todas as conectadas" quando idle
- Feedback `syncMsg` exibido em `text-emerald-400` abaixo do subtitle; limpa após 5s via setTimeout
- `npm run build` concluído sem erros (10.79s, 4360 módulos)

## Commits

| Task | Commit | Descrição |
|------|--------|-----------|
| Task 1 | `1b0dd78` | feat(260602-d8i): syncAll() no controller + rota ml.oauth.sync-all |
| Task 2 | `b2b72b9` | feat(260602-d8i): botão "Sincronizar todas as conectadas" no header ML OAuth |

## Deviações do Plano

Nenhuma — plano executado exatamente como descrito.

## Verificação

- PHP syntax: sem erros em `MercadoLivreOAuthController.php` e `routes/web.php`
- `npm run build`: passou sem erros
- Rota `ml.oauth.sync-all` declarada no grupo correto (admin/role:admin) herdando autenticação e RBAC
- Build inclui o chunk `MlOAuth/Index` com as alterações do botão

## Self-Check

- [x] `app/Http/Controllers/MercadoLivreOAuthController.php` — modificado e commitado
- [x] `routes/web.php` — rota adicionada e commitada
- [x] `resources/js/Pages/MlOAuth/Index.jsx` — botão e lógica adicionados e commitados
- [x] Commit `1b0dd78` existe no log
- [x] Commit `b2b72b9` existe no log
- [x] `npm run build` passou sem erros de compilação

## Self-Check: PASSED
