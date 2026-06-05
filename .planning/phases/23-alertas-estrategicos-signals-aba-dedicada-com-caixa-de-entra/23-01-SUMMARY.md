---
phase: 23-alertas-estrategicos-signals-aba-dedicada-com-caixa-de-entra
plan: 01
subsystem: alertas-estrategicos
tags: [signals, ecf-drive, caixa-de-entrada, comercial, ack, badge-sidebar, phase-23]
dependency_graph:
  requires: [22-01]  # EcfDriveService::listSignals + ackSignal
  provides: [rota-alertas-estrategicos, alertas-controller, badge-criticos-sidebar]
  affects: [sidebar-applayou, handle-inertia-requests]
tech_stack:
  added: []
  patterns:
    - Try/catch Throwable global no controller → props vazias + flash error (não quebra pageload)
    - Lookup batch whereIn(adman_account_id|ml_store_id) em 1 query
    - Lazy closure shared prop com cache 5min + fallback null
    - Classes Tailwind hardcoded por cor (evita JIT purge)
    - PRAGMA ignore_check_constraints para testes SQLite com enum
key_files:
  created:
    - app/Http/Controllers/AlertasController.php
    - resources/js/Pages/AlertasEstrategicos/Index.jsx
    - resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx
    - resources/js/Pages/AlertasEstrategicos/components/StatsHeader.jsx
    - tests/Feature/Phase23/AlertasControllerTest.php
  modified:
    - routes/web.php
    - app/Http/Middleware/HandleInertiaRequests.php
    - resources/js/Layouts/AppLayout.jsx
decisions:
  - "D-01: Middleware role:admin,VÍRGULA,consultor,mentor (não pipe) — separador correto para varargs EnsureUserHasRole no Laravel"
  - "D-02: Try/catch Throwable global em index() retorna props vazias + flash error — ECF Drive offline nunca quebra pageload"
  - "D-03: Lookup companies em batch (1 query whereIn adman_account_id + orWhereIn ml_store_id) — evita N+1"
  - "D-04: 3 chamadas listSignals(limit=1) para stats — cache 1min do wrapper absorve custo"
  - "D-05: Cache 5min para alertas_criticos_count na shared prop, fallback null — badge some silenciosamente"
  - "D-06: Constantes PHP espelhadas como props Inertia (type_labels, severity_labels, severity_colors)"
  - "D-07: formatPayload() no JSX — cosmético/locale-dependente, mantém controller enxuto"
  - "D-08: Filtros via query string GET, paginação Inertia padrão"
  - "D-09: Sem migration/model/activity log — ECF Drive é fonte da verdade"
  - "D-10: Classes Tailwind hardcoded por cor no AlertaCard e StatsHeader — evita purge JIT com classes dinâmicas"
metrics:
  duration: "~45 min"
  completed_date: "2026-06-05"
  tasks_completed: 6
  files_changed: 8
---

# Phase 23 Plan 01: Alertas Estratégicos (signals — caixa de entrada do comercial) — Summary

**One-liner:** Caixa de entrada com 778 signals ECF Drive, lookup batch empresa, ack inline preserveScroll e badge sidebar críticos com cache 5min.

## O que foi entregue

Primeira aba visível da Milestone v8.0. Rota `/alertas-estrategicos` com `AlertasController` consumindo `EcfDriveService::listSignals` + `ackSignal` (Phase 22 wrapper). Página React com 3 KPI cards de severidade, filtros de busca, lista paginada (50/pág) de `AlertaCard` com payload formatado pt-BR via `formatPayload()`, e botão "Marcar como visto" inline via `router.post`. Badge numérico vermelho na sidebar mostrando críticos não-ackeados (shared prop cache 5min).

## Commits da fase

| Hash | Tipo | Descrição |
|------|------|-----------|
| `dc1e110` | feat | AlertasController + rotas /alertas-estrategicos |
| `2b599eb` | feat | shared prop alertas_criticos_count para badge sidebar |
| `3ae04be` | feat | pagina AlertasEstrategicos/Index com filtros + paginacao |
| `1d764c9` | feat | AlertaCard com payload pt-BR + ack inline |
| `1f79071` | feat | StatsHeader com 3 KPI cards criticos/warnings/info |
| `72cecb2` | feat | item Alertas Estrategicos na sidebar + build |
| `32ebd36` | test | AlertasControllerTest com 9 testes Http::fake |

## Resultado dos testes

| Suite | Resultado |
|-------|-----------|
| Phase23/AlertasControllerTest | **9/9 verdes** |
| Phase22 (EcfDriveService*) | **27/27 verdes** (sem regressão) |
| Phase20 (grants/wrapper) | **20/20 verdes** (sem regressão) |

## Desvios do plano

### Auto-corrigidos

**1. [Rule 1 - Bug] Separador de middleware varargs: `|` → `,`**
- **Encontrado durante:** W3-T1 (testes falhando com 403 para admin)
- **Problema:** O PLAN especificava `role:admin|consultor|mentor` usando `|` como separador. No Laravel, o separador correto para argumentos de middleware com varargs (`string ...$roles`) é a **vírgula** (`,`). O `|` é passado como parte de uma única string, causando `in_array('admin', ['admin|consultor|mentor'])` = false — todos os usuários recebiam 403.
- **Correção:** Alterado para `role:admin,consultor,mentor` em `routes/web.php`.
- **Arquivo modificado:** `routes/web.php` (linha do grupo alertas)
- **Commit:** `32ebd36`

**2. [Rule 1 - Bug] Teste de role bloqueada com enum SQLite**
- **Encontrado durante:** W3-T1
- **Problema:** A tabela `users` tem enum `role` restrito a `admin/consultor/mentor`, tornando impossível criar usuário com role `publicador` via factory. O SQLite em memória impõe o CHECK constraint.
- **Correção:** Teste usa `PRAGMA ignore_check_constraints = 1` para inserir usuário com role `lider` (fora do enum de prod), validando o bloqueio real do middleware, depois reativa o constraint.
- **Arquivo modificado:** `tests/Feature/Phase23/AlertasControllerTest.php`
- **Commit:** `32ebd36`

### Não realizados (W4 — por design)

W4 é checkpoint humano blocking (smoke visual em produção). Não executado — aguarda autorização de deploy e validação manual.

## Riscos residuais

| ID | Descrição | Status |
|----|-----------|--------|
| R-01 | API ECF Drive lança em runtime — badge some silenciosamente (null retornado) | Mitigado por try/catch |
| R-03 | Badge sidebar não decrementa imediatamente após ack (cache 5min) | Aceito — documentado no PLAN |
| R-04 | cust_id de aluno ML não bate com nenhuma Company — renderiza "Cliente externo: X" | Esperado |

## W4 — Smoke visual em produção (pendente)

**Pré-requisito:** deploy autorizado pelo usuário.

**9 itens de validação:**
1. Navegação e carregamento da aba (< 3s)
2. Stats header: 3 KPI cards com números coerentes (~61 críticos)
3. Lista com lookup empresa (nomes reais vs "Cliente externo")
4. Filtro por severidade "Crítico" → URL com `?severity=critical`
5. Filtro por tipo "Oportunidade de ADS" → só PADS
6. Ack flow: card some + flash success + scroll preservado
7. Badge sidebar decrementa (pode levar até 5min — cache)
8. Consultor/mentor: acessa + vê + pode ack
9. Publicador/analista: sem item na sidebar + 403 no URL direto

## Known Stubs

Nenhum stub introduzido nesta fase. `formatPayload()` tem um fallback genérico (`Object.entries(payload).slice(0,3)`) para tipos não mapeados, mas os 5 tipos conhecidos da API estão todos cobertos. Não é stub — é fallback de segurança.

## Threat Flags

Nenhuma superfície nova além do que o PLAN já modelou no `<threat_model>`. O endpoint `POST /alertas-estrategicos/{id}/ack` está coberto pelo T-23-03 (autorização final no ECF Drive via API key) e T-23-02 (validação de input no controller index).

## Self-Check: PASSED

**Arquivos criados:**
- FOUND: `app/Http/Controllers/AlertasController.php`
- FOUND: `resources/js/Pages/AlertasEstrategicos/Index.jsx`
- FOUND: `resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx`
- FOUND: `resources/js/Pages/AlertasEstrategicos/components/StatsHeader.jsx`
- FOUND: `tests/Feature/Phase23/AlertasControllerTest.php`

**Commits verificados:**
- FOUND: `dc1e110` feat: AlertasController + rotas
- FOUND: `2b599eb` feat: shared prop alertas_criticos_count
- FOUND: `3ae04be` feat: Index.jsx
- FOUND: `1d764c9` feat: AlertaCard
- FOUND: `1f79071` feat: StatsHeader
- FOUND: `72cecb2` feat: AppLayout + build
- FOUND: `32ebd36` test: AlertasControllerTest 9/9
