---
phase: 70-ui-de-configuracao-admin
milestone: v15.0
plan: 70-04
subsystem: nps
type: execute
wave: 2
tags: [nps, backend, controller, service-scopes, preview, empresas-afetadas, admin, form-request, laravel-12, phase70]
requirements: [NPS-C-05, NPS-C-06]
dependency-graph:
  requires:
    - phase-68-schema (pivot nps_template_service_scopes + template.servicos() BelongsToMany)
    - phase-69-backend (NpsTemplateService::resolveForCompany — reusado 100% pela empresasAfetadas)
    - 70-01 (NpsTemplateController base com methods index/store/update/toggleActive)
  provides:
    - App\Http\Controllers\NpsTemplateController::syncServicos (PUT servicos — sync atomic)
    - App\Http\Controllers\NpsTemplateController::empresasAfetadas (GET — simulação via NpsTemplateService)
    - App\Http\Controllers\NpsTemplateController::preview (POST — puro, zero side effects DB)
    - App\Http\Requests\SyncNpsTemplateScopesRequest (validação servicos[] com exists + distinct)
    - App\Http\Requests\PreviewNpsTemplateRequest (validação payload aninhado)
    - 3 rotas nomeadas nps.configuracao.templates.{servicos.sync, empresas-afetadas, preview}
  affects:
    - Plan 70-05 (rewrite Configuracao.jsx) — consumirá os 3 endpoints via axios/fetch quando o admin abrir o picker de serviços e o preview panel
tech-stack:
  added: []
  patterns:
    - BelongsToMany::sync() atomic (transação implícita do Laravel) — safe para concurrent edits
    - Simulação stateless via NpsTemplateService::resolveForCompany (reuso 100% da Phase 69)
    - Perf guard LIMIT 100 + campo `truncated` na resposta JSON (evita over-fetch em carteiras grandes)
    - Catch RuntimeException isolado por empresa — 1 empresa em estado anômalo não crasha simulação inteira
    - Preview endpoint pure function (zero INSERT/UPDATE/DELETE/sync) — shape idêntico ao `template_snapshot_json` da Phase 71
    - Payload aninhado `perguntas.*.options.*` validado por Rule::in + integer|min:1|max:5 (peso 1..5)
key-files:
  created:
    - app/Http/Requests/SyncNpsTemplateScopesRequest.php
    - app/Http/Requests/PreviewNpsTemplateRequest.php
  modified:
    - app/Http/Controllers/NpsTemplateController.php
    - routes/web.php
decisions:
  - "Preview endpoint SEM {template} na URL — stateless, o payload contém tudo. Permite preview de template ANTES de criar o registro (fluxo montar → preview → salvar sem persistir nada intermediário)."
  - "empresasAfetadas retorna JSON (não Inertia) — chamado sob demanda via fetch quando admin abre picker de serviços; re-renderizar página inteira seria desperdício. Payload contém {template_id, count, empresas, sampled_from, total_ativas, truncated} para UI decidir mostrar aviso 'primeiras 100 de N'."
  - "Base de simulação = Company::where('active', true)->whereHas('contratosServico', ativo=true) — evita simular sobre empresas dormentes que nem receberiam disparo mensal. Adição do filtro `active=true` (Rule 2 - correção crítica implícita) não estava explícita no plan text mas é essencial para não gastar queries em empresas desativadas."
  - "SyncNpsTemplateScopesRequest sem `required` em `servicos` — payload `{\"servicos\": []}` é semanticamente válido (desassocia template de todos os serviços numa chamada só). O único caminho para o template ainda ser usado é sendo is_default=true (fallback do NpsTemplateService::resolveForCompany)."
  - "PreviewNpsTemplateRequest permite `perguntas.*.options` nullable ou vazio — durante edição a UI pode ter pergunta 'escala' que ainda vai auto-gerar 5 opções, ou 'opcoes' sem opções cadastradas. Zero erro 422 nesses casos intermediários."
  - "Ordem recalculada via `$idx + 1` no preview — respeita ordem do array vindo do form; frontend arrasta perguntas/opções livremente sem precisar recomputar ordens manualmente antes de enviar."
metrics:
  duration: ~15 min
  tasks: 7
  files: 4 (2 novos + 2 modificados)
  completed_date: 2026-07-08
---

# Phase 70 Plan 70-04: Service scopes sync + empresas-afetadas + preview endpoint — Summary

Backend fecha REQ NPS-C-05 e NPS-C-06 com 3 endpoints REST admin-only que complementam o CRUD de templates (Plans 70-01/02/03): (a) sync atômico do pivot `nps_template_service_scopes` via `$template->servicos()->sync($ids)`, (b) simulação stateless de "quais empresas em carteira receberiam este template" reusando 100% do `NpsTemplateService::resolveForCompany` da Phase 69-01, com perf guard LIMIT 100 + campo `truncated` para UI decidir mostrar aviso; e (c) preview endpoint puro (zero side effects DB) que retorna estrutura idêntica ao `template_snapshot_json` da Phase 71, permitindo o `<PreviewFormulario>` (Plan 70-05) renderizar preview live sem ping-pong com o banco.

## Objective

Fechar a camada backend da UI de Configuração admin com os 3 endpoints complementares dos requisitos NPS-C-05 (service scopes com feedback visual) e NPS-C-06 (preview live sem persistir).

## Requirements Addressed

- **REQ NPS-C-05:** Admin pode associar templates a tipos de serviço via pivot `nps_template_service_scopes` — 100% coberto (sync atomic + feedback visual de empresas afetadas via simulação NpsTemplateService).
- **REQ NPS-C-06:** Admin vê preview live do formulário público renderizado a partir do template em edição, sem persistir mudanças — 100% coberto no backend (endpoint puro; Plan 70-05 vai consumir).

## Tarefas Executadas

### T1 — SyncNpsTemplateScopesRequest (novo)

Criado `app/Http/Requests/SyncNpsTemplateScopesRequest.php` (77 linhas):
- `servicos: array` (sem `required` — permite desassociar todos)
- `servicos.*: integer|distinct|exists:servicos,id`
- `authorize()`: `$this->user()?->isAdmin() ?? false` (belt-and-suspenders com middleware role:admin)
- Mensagens pt-BR para exists/distinct/array/integer

### T2 — PreviewNpsTemplateRequest (novo)

Criado `app/Http/Requests/PreviewNpsTemplateRequest.php` (114 linhas):
- `nome: nullable|string|max:120`, `descricao: nullable|string|max:500`
- `perguntas: required|array|min:1`
- `perguntas.*.texto: required|string|min:3|max:500`
- `perguntas.*.tipo: required + Rule::in(NpsTemplateQuestion::TIPOS)` (escala|opcoes)
- `perguntas.*.dimensao: required + Rule::in(NpsTemplateQuestion::DIMENSOES)` (estrategista|analista|empresa|geral)
- `perguntas.*.obrigatoria: boolean`
- `perguntas.*.options: nullable|array` (permite draft state intermediário)
- `perguntas.*.options.*.label: required|string|min:1|max:200`
- `perguntas.*.options.*.peso: required|integer|min:1|max:5` (peso 1..5 conforme Plan 70-03)
- `ordem` DELIBERADAMENTE não validado — controller ecoa via `$idx + 1`
- Mensagens pt-BR para todas as regras

### T3 — NpsTemplateController::syncServicos (adicionado)

Método `syncServicos(SyncNpsTemplateScopesRequest $request, NpsTemplate $template)`:
- Extrai `$ids = $request->validated()['servicos'] ?? []`
- `$template->servicos()->sync($ids)` — DELETE+INSERT em transação implícita do Laravel (BelongsToMany atomic)
- Retorna `back()->with('success', ...)` com mensagem contextual:
  - 0 serviços → "Template desassociado de todos os serviços."
  - 1 serviço → "Template agora vale para 1 serviço."
  - N serviços → "Template agora vale para N serviços."

### T4 — NpsTemplateController::empresasAfetadas (adicionado)

Método `empresasAfetadas(NpsTemplate $template, NpsTemplateService $service)`:
- DI injeta `NpsTemplateService` (Plan 69-01)
- Base: `Company::where('active', true)->whereHas('contratosServico', ativo=true)->orderBy('name')->limit(100)` — empresas ativas com pelo menos 1 contrato ativo
- `$totalAtivas`: mesmo predicado sem limit — feed do campo `truncated`
- Loop `foreach ($companies as $company)` chamando `$service->resolveForCompany($company)`
- Compara `$resolved->id === $template->id` — se sim, adiciona a `$afetadas[]`
- `catch (RuntimeException)` isola falhas por empresa (seed NPS Padrão revertido não crasha simulação inteira; apenas skip aquela empresa)
- Retorna `response()->json([template_id, count, empresas, sampled_from, total_ativas, truncated])`

### T5 — NpsTemplateController::preview (adicionado)

Método `preview(PreviewNpsTemplateRequest $request)`:
- PURE function — zero INSERT/UPDATE/DELETE/sync
- Normaliza `perguntas` recalculando `ordem = $idx + 1` para 1-based humano-legível
- Normaliza `options` recalculando `ordem = $j + 1` (respeita ordem do array; peso interno separado da ordem visual)
- Fallback `nome ?? '(sem título)'` apenas na resposta JSON (nunca no banco)
- `obrigatoria ?? true` como default seguro
- Retorna `response()->json([template => [nome, descricao, perguntas]])` — shape idêntico ao `template_snapshot_json` da Phase 71

### T6 — 3 rotas registradas em routes/web.php

Inseridas dentro do grupo `role:admin` após as rotas do Plan 70-03 (linha 190), antes do `nps.responses.destroy` (quick task 260612-flt):

```php
Route::put ('/nps/configuracao/templates/{template}/servicos', ...)
    ->name('nps.configuracao.templates.servicos.sync');

Route::get ('/nps/configuracao/templates/{template}/empresas-afetadas', ...)
    ->name('nps.configuracao.templates.empresas-afetadas');

Route::post('/nps/configuracao/templates/preview', ...)
    ->name('nps.configuracao.templates.preview');
```

Note: `preview` NÃO recebe `{template}` — endpoint stateless, payload contém tudo.

### T7 — Smoke tests

- `php -l app/Http/Controllers/NpsTemplateController.php` → No syntax errors detected
- `php -l app/Http/Requests/SyncNpsTemplateScopesRequest.php` → No syntax errors detected
- `php -l app/Http/Requests/PreviewNpsTemplateRequest.php` → No syntax errors detected
- `php -l routes/web.php` → No syntax errors detected
- `php artisan route:list --path=nps/configuracao/templates` → **15 rotas** (12 dos Plans 70-01/02/03 + 3 desta plan) ✓
- `php artisan tinker --execute "echo get_class(app(NpsTemplateService::class))"` → `App\Services\Nps\NpsTemplateService` ✓ (DI funciona)
- Sanity Company query: `where('active', true)->whereHas('contratosServico', ativo=true)->limit(100)->count()` gerou SQL correto (validado até MariaDB local — que está offline por corrupção documentada no memory).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Filtro `where('active', true)` adicionado ao Company query da `empresasAfetadas`**

- **Encontrado durante:** Task T4 execução
- **Issue:** O plan text especifica apenas `whereHas('contratosServico', ativo=true)` como base — não filtra `companies.active=true`. Simular sobre empresas desativadas gasta queries do `resolveForCompany` sem propósito (empresa desativada nem recebe disparo mensal do `nps:disparar-mensal` — validado no Plan 69-05 SUMMARY que também filtra empresas ativas antes de aplicar guards).
- **Fix:** Adicionado `->where('active', true)` no query base (linha 240) e no `$totalAtivas` count (linha 249). Consistência com o comportamento do disparo mensal Phase 69.
- **Arquivos modificados:** `app/Http/Controllers/NpsTemplateController.php`
- **Impacto:** Simulação mais rápida (menos empresas iteradas) + resposta mais fiel ao que realmente aconteceria no disparo mensal.

### Auth Gates

Nenhum. Endpoints são admin-only via middleware `role:admin` + `authorize()` no FormRequest — camada dupla de defesa.

### Known Stubs

Nenhum stub introduzido nesta plan.

## Threat Flags

Nenhum. Endpoints são todos admin-only (`role:admin` no grupo de rotas + `authorize() = user()->isAdmin()` nos FormRequests). Não há surface de rede pública nova, autenticação ou trust boundary novo introduzido.

## Files Created

- `app/Http/Requests/SyncNpsTemplateScopesRequest.php` (77 linhas)
- `app/Http/Requests/PreviewNpsTemplateRequest.php` (114 linhas)

## Files Modified

- `app/Http/Controllers/NpsTemplateController.php` (+184 linhas — 3 novos métodos syncServicos/empresasAfetadas/preview + 6 novos imports)
- `routes/web.php` (+17 linhas — 3 novas rotas)

## Zero Changes Made

- **`NpsController.php`** (CRUD legado de perguntas extras Phase 33 intacto)
- **`NpsTemplateQuestionController`** (Plan 70-02 intacto)
- **`NpsTemplateOptionController`** (Plan 70-03 intacto)
- **`NpsTemplateService`** (Plan 69-01 consumido via DI — read-only)
- **`NpsTemplate` Model** (fillable/casts/relations Phase 68-02 intactos)
- **`Company` Model** (query direta, sem alterar relation `contratosServico`)
- **Migrations** (schema Phase 68 intacto)
- **Nenhuma nova dependência** (composer.json intacto)

## Self-Check: PASSED

- FOUND: app/Http/Requests/SyncNpsTemplateScopesRequest.php
- FOUND: app/Http/Requests/PreviewNpsTemplateRequest.php
- FOUND: app/Http/Controllers/NpsTemplateController.php (3 novos métodos syncServicos/empresasAfetadas/preview)
- FOUND: routes/web.php (3 novas rotas registradas: servicos.sync, empresas-afetadas, preview)
- FOUND: 15 rotas ativas em `route:list --path=nps/configuracao/templates`
- FOUND: DI `NpsTemplateService` resolve via container
- FOUND: `php -l` verde nos 3 arquivos alterados
