---
quick_task: 260602-fn3
plan: 01
subsystem: dev-painel
tags: [adman, diagnostico, sync, fila, anomalias, heuristica]
dependency_graph:
  requires: [adman_sync_logs, adman_metrics, jobs, failed_jobs, SyncAdmanCompanyJob]
  provides: [AdmanDiagnosticoService, dev.resync route, diagnostico UI block]
  affects: [DevController, Desenvolvimento.jsx, routes/web.php]
tech_stack:
  added: []
  patterns: [heuristica-php-pura, inertia-props, router.post-inertia, query-agregada-sem-n1]
key_files:
  created:
    - app/Services/AdmanDiagnosticoService.php
  modified:
    - app/Http/Controllers/DevController.php
    - routes/web.php
    - resources/js/Pages/Dev/Desenvolvimento.jsx
decisions:
  - "Sem N+1 no grupo sem_sync: AdmanSyncLog::selectRaw MAX groupBy pluck + Company pluck em lote"
  - "Sem N+1 no grupo erros: with('company') eager load"
  - "Sem N+1 no grupo anomalias: subquery MAX(id) para métrica mais recente + AVG(tacos) separado"
  - "Estado vazio (total=0) renderiza DevCard unico com texto amigavel — card de fila sempre visivel"
  - "sevBadge via cn() com tres variantes: alta (vermelho), media (ambar), baixa (branco/40)"
  - "Rota POST /dev/resync dentro do grupo role:admin — sem exposicao fora do admin"
  - "abort(422) quando empresa nao tem adman_account_id — evita dispatch semanticamente invalido"
metrics:
  duration: ~15 min
  completed_date: 2026-06-02
  tasks: 3
  files_changed: 4
---

# Quick Task 260602-fn3: Diagnóstico Adman no Painel Dev — Summary

**One-liner:** Bloco "Diagnóstico Adman" com heurística PHP pura (4 grupos: sem sync / erros / fila / anomalias) + botão de re-sync manual via SyncAdmanCompanyJob, sem IA e sem chamada externa.

## O que foi entregue

### Task 1 — AdmanDiagnosticoService (commit `9ae1f3f`)

Novo service `app/Services/AdmanDiagnosticoService.php` com método público `gerar(): array` retornando 4 grupos:

- **sem_sync** — empresas com `adman_account_id` sem sync há mais de 48h ou nunca sincronizadas. Severidade `alta` quando >= 120h ou nunca; `media` quando entre 48h e 120h. Ordenado por severidade, limitado a 15 itens.
- **erros** — syncs das últimas 48h com `error_message` preenchido. Severidade `alta`. Mensagem truncada em ~120 chars (mitigação T-fn3-04).
- **fila** — contadores diretos nas tabelas `jobs` e `failed_jobs`.
- **anomalias** — queda de revenue >= -40% (com piso de R$ 100 em revenue_prev_period) e TACoS acima de 130% da média dos últimos 14 dias da própria empresa.

Sem N+1: todas as consultas usam queries agregadas (`selectRaw`, `groupBy`, `pluck`) ou eager load (`with('company')`). Limiares definidos como constantes públicas documentadas em pt-BR.

### Task 2 — DevController + rota POST (commit `ae56fde`)

- `index()` injeta `AdmanDiagnosticoService` via DI e passa `diagnostico` como prop Inertia.
- `resyncCompany()` valida `company_id` (`required|integer|exists:companies,id`), exige `adman_account_id` (abort 422 caso ausente), despacha `SyncAdmanCompanyJob::dispatch($company)`, loga com tag `[DevDiagnostico]`, retorna `back()->with('success', ...)`.
- Rota `POST /dev/resync` registrada dentro do grupo `Route::middleware('role:admin')` (mitigação T-fn3-02).

### Task 3 — UI "Diagnóstico Adman" + build (commit `e967136`)

- `Desenvolvimento.jsx` recebe prop `{ diagnostico }` com defaults seguros.
- 4 DevCards: "Empresas sem sync recente", "Syncs com erro", "Fila & jobs", "Anomalias de métrica".
- Componente local `AlertRow` com badge de severidade via `sevBadge(sev)` + `cn()`.
- Botão "Re-disparar sync" com estado de loading (`RefreshCw animate-spin`, disabled durante envio).
- Estado vazio amigável quando `diag.total === 0` (DevCard único com "Tudo certo — nenhum alerta no momento.").
- Card de fila sempre visível; falhos destacados em `text-red-400` quando > 0.
- `npm run build` concluído sem erros (9,78s, `Desenvolvimento-YRp9TR9O.js`).

## Commits

| Task | Hash | Mensagem |
|------|------|----------|
| 1 | `9ae1f3f` | feat(quick-260602-fn3): adiciona AdmanDiagnosticoService com heuristica PHP pura |
| 2 | `ae56fde` | feat(quick-260602-fn3): wire DevController com diagnostico + rota POST dev.resync |
| 3 | `e967136` | feat(quick-260602-fn3): UI Diagnostico Adman em Desenvolvimento.jsx + build |

## Deviations from Plan

None — plano executado exatamente como escrito. Todas as heurísticas, estruturas de dados e controles de acesso implementados conforme especificado.

## Threat Mitigations Aplicadas

| Threat ID | Mitigação implementada |
|-----------|------------------------|
| T-fn3-01 | `validate(['company_id' => 'required|integer|exists:companies,id'])` + `abort(422)` sem `adman_account_id` |
| T-fn3-02 | Rota dentro do grupo `role:admin` |
| T-fn3-04 | `error_message` truncado em ~120 chars, exibido apenas para admin autenticado |

## Threat Flags

Nenhuma nova superfície de segurança além do planejado.

## Known Stubs

Nenhum — todos os dados vêm das consultas reais ao banco local (sem mock, sem hardcode).

## Self-Check: PASSED

- [x] `app/Services/AdmanDiagnosticoService.php` existe e passa `php -l`
- [x] `app/Http/Controllers/DevController.php` passa `php -l`
- [x] Rota `dev.resync` listada em `php artisan route:list --name=dev.resync`
- [x] `resources/js/Pages/Dev/Desenvolvimento.jsx` atualizado com bloco Diagnóstico Adman
- [x] `npm run build` concluído sem erros
- [x] Commits `9ae1f3f`, `ae56fde`, `e967136` existem no log
