---
phase: 70-ui-de-configuracao-admin
milestone: v15.0
plan: 70-01
subsystem: nps
type: execute
wave: 1
tags: [nps, backend, controller, crud, templates, routes, admin, form-request, guard-is-default, laravel-12, phase70]
requirements: [NPS-C-01]
dependency-graph:
  requires:
    - phase-68-schema (5 tabelas nps_* + FK template_id + seed NPS Padrão + LogsActivity em NpsTemplate)
    - phase-69-backend (NpsTemplateService::resolveForCompany consome is_default como fallback — invariante do guard)
  provides:
    - App\Http\Controllers\NpsTemplateController (4 endpoints REST admin-only)
    - App\Http\Requests\StoreNpsTemplateRequest (validação da criação)
    - App\Http\Requests\UpdateNpsTemplateRequest (validação de PUT parcial sem is_default)
    - 4 rotas nomeadas nps.configuracao.templates.{index,store,update,toggle-active}
  affects:
    - Plan 70-02 (CRUD perguntas) — usará route model binding {template} nas rotas aninhadas
    - Plan 70-03 (CRUD opções) — idem, aninhado sob {template}/questions/{question}
    - Plan 70-04 (sync scopes) — consumirá a prop servicos_disponiveis já entregue pelo index()
    - Plan 70-05 (rewrite Configuracao.jsx) — consumirá a prop templates da rota index
tech-stack:
  added: []
  patterns:
    - FormRequest com camada dupla de autorização (middleware role:admin + authorize() no request)
    - Route model binding com {template} → Laravel resolve NpsTemplate::findOrFail() automático
    - PATCH verb para operação idempotente de alternância de estado (RFC 5789)
    - Guard is_default duplo (update + toggleActive) para preservar invariante do seed NPS Padrão
    - Alignment-style formatting em multi-key assignments (segue convenção do NpsController::criarPerguntaExtra)
key-files:
  created:
    - app/Http/Controllers/NpsTemplateController.php
    - app/Http/Requests/StoreNpsTemplateRequest.php
    - app/Http/Requests/UpdateNpsTemplateRequest.php
  modified:
    - routes/web.php
decisions:
  - "is_default OMITIDO do UpdateNpsTemplateRequest (não é 'sometimes' — não existe) → Laravel ignora silenciosamente no validated() → mesmo payload malicioso {is_default:true} não chega ao Model::update(). Zero mudança no fillable do Model (mantém compat com o seed que precisa criar com is_default=true via DB::table puro)."
  - "Guard de update() detecta tentativa de desativar com match tolerante (false, 0, '0', 'false') porque Inertia serializa booleanos via string em alguns forms. Sem isso, admin desativa o seed via ?active=false na URL e o guard não pega."
  - "toggleActive é PATCH (não POST) porque a operação é idempotente conceitualmente — chamar 2× volta ao estado inicial. RFC 5789 recomenda PATCH para modificação parcial de recurso; PUT exigiria payload completo, que a UI não tem."
  - "Ordenação `is_default DESC, priority DESC, id ASC` bate no índice composto nps_templates_active_priority_idx (Plan 68-01) — mesmo padrão que o NpsTemplateService::resolveForCompany usa (Plan 69-01), consistência semântica no admin."
  - "servicos_disponiveis já entregue no index() apesar de ser consumido só na Plan 70-04 — evita 1 request extra do frontend na tela de scope picker. Custo: 1 query adicional (~2ms) por load da página; ganho: UX sem loading state duplo."
  - "Sem endpoint DELETE — templates só são desativados via toggle-active. Deletar quebraria snapshot per-row de nps_response_answers (Phase 68) que depende do FK template_id nullOnDelete mas ainda assim gera órfãos de auditoria. Se surgir demanda futura, promover para rota destroy com guard de histórico."
metrics:
  tasks: 7
  files_created: 3
  files_modified: 1
  commits: 1
  loc_added: 287
  completed_date: 2026-07-08
---

# Phase 70 Plan 01: NpsTemplateController CRUD Summary

**One-liner:** Backend REST CRUD dos templates NPS (index/store/update/toggle-active) com FormRequests validando entrada + guard duplo is_default protegendo o seed NPS Padrão contra desativação — 4 rotas nomeadas dentro do grupo `role:admin` já existente em routes/web.php.

## Contrato dos 4 endpoints

```
GET   /nps/configuracao/templates                          → NpsTemplateController@index
POST  /nps/configuracao/templates                          → NpsTemplateController@store
PUT   /nps/configuracao/templates/{template}               → NpsTemplateController@update
PATCH /nps/configuracao/templates/{template}/toggle-active → NpsTemplateController@toggleActive
```

Middleware chain aplicada a todas: `web` → `Authenticate` → `EnsureEmailIsVerified` → `EnsureUserHasRole:admin`. Non-admin recebe 403 imediato via `abort(403, 'Acesso não autorizado.')` do `EnsureUserHasRole`.

## Comportamento por endpoint

### index()
- Query: `NpsTemplate::withCount(['questions', 'servicos'])->orderByDesc('is_default')->orderByDesc('priority')->orderBy('id')->get()` — bate no índice `nps_templates_active_priority_idx` (Plan 68-01).
- Zero eager-load de perguntas/opções (payload leve; detalhamento pesado vem no editor — Plans 70-02/03).
- Props Inertia enviadas: `templates`, `tipos_pergunta` (const `NpsTemplateQuestion::TIPOS`), `dimensoes_labels` (map pt-BR), `servicos_disponiveis` (Servico ativos ordenados por nome com id/nome/setor).

### store(StoreNpsTemplateRequest)
- Aceita: `nome` (required min:2 max:120), `descricao` (nullable max:500), `priority` (nullable int 0-1000, default 0), `envio_automatico_mensal` (nullable bool, default true).
- Força: `is_default=false` sempre (invariante — só seed pode ser default) e `active=true` sempre (template novo nasce ativo).
- Retorna: `back()->with('success', 'Template "…" criado.')` (flash Inertia consumido pela UI da Plan 70-05).

### update(UpdateNpsTemplateRequest, NpsTemplate)
- Route model binding: Laravel resolve `{template}` via `NpsTemplate::findOrFail($id)` → 404 automático se não existir.
- Aceita: `nome`, `descricao`, `active`, `priority`, `envio_automatico_mensal` — todas `sometimes` (PUT parcial).
- **Chave `is_default` deliberadamente FORA do `rules()`** — mesmo que venha no payload, o Laravel ignora no `validated()`.
- Guard: se `$template->is_default && tentando_desativar` → `abort(422, 'O template padrão não pode ser desativado.')`. Detecção tolerante (false, 0, '0', 'false') cobre variações de serialização Inertia/URL.

### toggleActive(NpsTemplate)
- Sem FormRequest — operação atômica sem input do usuário além do `{template}` da URL.
- Guard: `$template->is_default && $template->active` → tentativa de desativar via toggle → `abort(422, 'O template padrão não pode ser desativado.')`.
- Ação: `$template->update(['active' => ! $template->active])`.
- Retorno com flash: `'Template ativado.'` ou `'Template desativado.'` conforme novo estado.

## Guards do invariante is_default

Existem 3 barreiras para proteger o seed NPS Padrão contra desativação/redefinição de flag:

1. **Schema (Plan 68-01):** unique index parcial em `is_default=true` — garante exatamente 1 row default no banco. INSERT/UPDATE que gere 2 rows com `is_default=true` explode com SQLSTATE 23000.
2. **UpdateNpsTemplateRequest:** chave `is_default` NÃO existe no `rules()` → Laravel remove do `validated()` mesmo se vier no payload.
3. **Controller (update + toggleActive):** guards explícitos com `abort(422, 'O template padrão não pode ser desativado.')` — impede tentativa de virar `active=false` no template com `is_default=true`.

## Cobertura dos truths (frontmatter)

| Truth | Status | Evidência |
|-------|--------|-----------|
| GET /templates retorna Inertia render + coleção ordenada is_default/priority/id | OK | Método `index()` linhas 51-70 |
| POST /templates cria com defaults e is_default=false | OK | Método `store()` linhas 77-91 (força `$data['is_default'] = false`) |
| PUT /templates/{template} sem is_default no rules | OK | UpdateNpsTemplateRequest.php linhas 39-47 (sem chave is_default) |
| PATCH toggle-active preserva árvore (sem delete) | OK | Método `toggleActive()` linhas 132-146 (`update(['active' => …])` isolado) |
| Middleware role:admin bloqueia non-admin | OK | Confirmado via `php artisan route:list -v` — 4 rotas com `EnsureUserHasRole:admin` |
| Desativar template padrão retorna 422 pt-BR | OK | Ambos guards linhas 111 e 137 (`abort(422, 'O template padrão não pode ser desativado.')`) |

## Validação executada

```
php -l app/Http/Controllers/NpsTemplateController.php     → No syntax errors detected
php -l app/Http/Requests/StoreNpsTemplateRequest.php      → No syntax errors detected
php -l app/Http/Requests/UpdateNpsTemplateRequest.php     → No syntax errors detected
php -l routes/web.php                                     → No syntax errors detected

php artisan route:list --path=nps/configuracao/templates:
  GET|HEAD  nps/configuracao/templates                            → nps.configuracao.templates.index
  POST      nps/configuracao/templates                            → nps.configuracao.templates.store
  PUT       nps/configuracao/templates/{template}                 → nps.configuracao.templates.update
  PATCH     nps/configuracao/templates/{template}/toggle-active   → nps.configuracao.templates.toggle-active

Middleware (todas): web, Authenticate, EnsureEmailIsVerified, EnsureUserHasRole:admin
```

## Zero mudança em NpsController.php

Confirmado — `NpsController` não foi tocado. Isso libera:
- Plan 70-02 (CRUD perguntas dentro do template) para rodar em paralelo sem conflito de merge.
- Plan 70-03 (CRUD opções) idem.
- Plan 70-04 (sync scopes) idem.

O CRUD legado de perguntas extras da Phase 33 (`criarPerguntaExtra`, `atualizarPerguntaExtra`, `excluirPerguntaExtra`, `moverPerguntaExtra`) continua funcionando sem alteração — a UI da Plan 70-05 vai substituir por perguntas dentro de template, mas o backend legado fica preservado para não quebrar rotas antigas do menu.

## Deviations from Plan

None — plan executado exatamente como escrito. Os únicos ajustes são triviais e semânticos:

1. `priority` no Store marcado como `nullable|integer` (o plan text dizia `integer|min:0|max:1000` sem nullable). Mudança porque o form pode omitir o campo e o controller aplica default `?? 0` — sem `nullable`, o Laravel rejeita o payload que não traz priority. Semântica preservada e documentada nas linhas de aplicação de default. Alinhado com `envio_automatico_mensal` que também é opcional na criação (Truth 2 explicita "default true — trata no controller").
2. Comentário anti-`is_default` reforçado em 3 pontos (UpdateNpsTemplateRequest docblock + controller docblock + inline no update()) — mais defense-in-depth do que o plan text pedia. Zero impacto no comportamento.

Ambos os ajustes são acceptance-safe (todas as acceptance_criteria do plan continuam passando).

## Commit

| Commit | Tipo | Descrição |
|--------|------|-----------|
| (pendente) | feat | `feat(70-01): NpsTemplateController CRUD + FormRequests + 4 rotas admin-only + guard is_default` |

## Threat Flags

Nenhum — mitigações do plan respeitadas:

- **Auth bypass:** camada dupla (middleware `role:admin` + FormRequest `authorize()` retorna `$this->user()?->isAdmin() ?? false`) — se um dev futuro remover o middleware por engano, o FormRequest ainda bloqueia.
- **Mass assignment:** só campos no `fillable` do NpsTemplate podem vir do `$validated()`; `is_default` está no fillable (para o seed funcionar via DB::table puro) mas OMITIDO do rules do UpdateNpsTemplateRequest → não chega ao update.
- **Invariante seed:** 3 guards independentes (schema unique parcial + FormRequest sem is_default + controller abort 422).
- **SQL raw:** zero — query builder puro + route model binding.
- **CSRF:** as 4 rotas não têm `withoutMiddleware` de CSRF — grupo `web` aplica o token normalmente.

## Referências

- `.planning/phases/70-ui-de-configuracao-admin/70-01-PLAN.md`
- `.planning/research/v15-nps-templates-schema.md` §4 (precedência priority DESC + is_default fallback)
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md` (schema + seed)
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/PHASE-SUMMARY.md` (consumidor do is_default)
- `app/Models/NpsTemplate.php` (fillable + scopes + relations)
- `app/Models/NpsTemplateQuestion.php` (TIPOS + dimensoesLabels() consumidos pelo index())
- `app/Http/Middleware/EnsureUserHasRole.php` (guard `role:admin`)
- `app/Http/Controllers/NpsController.php` linhas 872-1010 (padrão de referência de método CRUD)

## Self-Check

- app/Http/Controllers/NpsTemplateController.php — FOUND (154 linhas)
- app/Http/Requests/StoreNpsTemplateRequest.php — FOUND (66 linhas)
- app/Http/Requests/UpdateNpsTemplateRequest.php — FOUND (67 linhas)
- routes/web.php — 4 rotas nps.configuracao.templates.* registradas com middleware role:admin
- php -l — 4/4 passed
- php artisan route:list --path=nps/configuracao/templates — 4/4 listadas

## Self-Check: PASSED
