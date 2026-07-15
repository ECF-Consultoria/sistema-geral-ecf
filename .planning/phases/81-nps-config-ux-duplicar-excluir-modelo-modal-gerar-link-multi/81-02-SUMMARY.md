---
phase: 81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi
plan: 02
subsystem: api
tags: [nps, laravel, eloquent, json-endpoint, carteira-scoping, tdd, rbac]

# Dependency graph
requires:
  - phase: 70-ui-de-configuracao-admin
    provides: NpsTemplateController (CRUD templates) e rotas nps.configuracao.templates.*
  - phase: 79-nps-multi-modelo
    provides: pivot nps_template_service_scopes e lógica "serviços cobertos ∩ contratos ativos"
  - phase: 81-nps-config-ux (plan 01)
    provides: duplicate/destroy no NpsTemplateController
provides:
  - "NpsTemplateController@empresasElegiveis — endpoint JSON que retorna empresas elegíveis por modelo (inversão do resolveForCompany)"
  - "Rota GET nps.configuracao.templates.empresas-elegiveis no grupo ['auth','verified'] (não role:admin)"
affects: [81-04-modal-gerar-link-modelo-first, deploy-nps-v16]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Endpoint JSON de leitura escopado por carteira (whereIn companies) para não-admin"
    - "Fallback explícito: modelo sem service scopes → todas as empresas ativas"

key-files:
  created:
    - tests/Feature/V16/EmpresasElegiveisTest.php
  modified:
    - app/Http/Controllers/NpsTemplateController.php
    - routes/web.php

key-decisions:
  - "Rota no grupo ['auth','verified'] (espelha nps.generate), NÃO role:admin — o gerar-link é usado por consultor/não-admin (BLOCKER do plan-checker)"
  - "Modelo sem scopes → fallback todas as empresas ativas (cobre is_default/NPS Padrão)"
  - "Escopo de carteira via whereIn('id', user->companies) quando !isAdmin() (T-81-05 — não vaza empresas de fora)"

patterns-established:
  - "TDD RED→GREEN: test(RED) com RouteNotFoundException → feat(GREEN) com método + rota"
  - "whereHas('contratosServico', fn(q) => q->active()->whereIn('servico_id', $ids)) para elegibilidade por contrato ativo"

requirements-completed: [DEC-81-3]

# Metrics
duration: 8min
completed: 2026-07-14
---

# Phase 81 Plan 02: Endpoint empresas elegíveis por modelo Summary

Endpoint JSON `GET /nps/configuracao/templates/{template}/empresas-elegiveis` que, dado um modelo NPS, retorna as empresas elegíveis para gerar link — inversão da lógica "serviços cobertos ∩ contratos ativos" do `NpsTemplateService::resolveForCompany`. Alimenta o modal gerar-link modelo-first (Plan 81-04) sem inflar o payload inicial de `/nps`.

## O que foi construído

- **`NpsTemplateController@empresasElegiveis(Request, NpsTemplate)`**: obtém `$servicoIds = $template->servicos()->pluck('servicos.id')`. Base = `Company::where('active', true)->orderBy('name')`. Se há serviços cobertos, aplica `whereHas('contratosServico', fn($q) => $q->active()->whereIn('servico_id', $servicoIds))`; se o pivot está vazio, NÃO filtra por serviço (fallback → todas as empresas ativas). Para não-admin, restringe à carteira via `whereIn('id', $request->user()->companies()->pluck('companies.id'))`. Retorna `{ template_id, empresas: [{id, name}] }`.
- **Rota `nps.configuracao.templates.empresas-elegiveis`**: registrada junto a `nps.generate` no grupo `['auth','verified']` (antes da rota pública `/nps/{token}`).
- **`EmpresasElegiveisTest`** (3 cenários): (a) modelo com scope Shopee → só empresa com contrato Shopee ativo; empresa ML fora. (b) modelo sem scopes → todas as ativas (inativa excluída). (c) usuário não-admin com carteira de 1 empresa → só a empresa da carteira, mesmo com outra elegível.

## Decisões relevantes

- **Grupo `['auth','verified']`, NÃO `role:admin`** (BLOCKER corrigido no plano): o modal gerar-link é usado por consultor/não-admin. O `threat_model` do PLAN.md (T-81-06) ficou desatualizado ao mencionar "role:admin" — a decisão travada e o escopo de carteira dentro do método são a mitigação correta. O escopo por carteira (T-81-05) impede vazamento de empresas de fora da carteira do usuário.
- **Fallback "todas" para modelo sem scopes**: espelha o comportamento do `is_default`/NPS Padrão, que vale para todas as empresas.

## Verificações

- `php artisan test --filter=EmpresasElegiveisTest` → 3 passed (18 assertions).
- `php artisan test --filter=Nps` → 168 passed (1062 assertions), sem regressões.

## Deviations from Plan

Nenhuma — o plano foi executado exatamente como escrito. O `threat_model` do PLAN.md (T-81-06 citando "role:admin") já estava reconciliado no `<interfaces>`/critical_notes com a decisão correta (`['auth','verified']`); segui a instrução corrigida.

## Backend-only

Sem mudanças de frontend nesta task — sem `npm run build`, sem deploy (o consumo do endpoint vem no Plan 81-04).

## Self-Check: PASSED

- Arquivos: EmpresasElegiveisTest.php, NpsTemplateController.php, routes/web.php, 81-02-SUMMARY.md — todos presentes.
- Commits: aa9a967 (test/RED), 58ae411 (feat/GREEN) — ambos no histórico.
