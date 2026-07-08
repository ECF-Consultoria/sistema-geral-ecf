---
phase: 70-ui-de-configuracao-admin
milestone: v15.0
plan: 70-02
subsystem: nps
type: execute
wave: 1
tags: [nps, backend, controller, crud, questions, escala, auto-options, reorder, swap, admin, form-request, scope-bindings, phase70]
requirements: [NPS-C-02, NPS-C-04]
dependency-graph:
  requires:
    - phase-68-schema (nps_template_questions + nps_template_options + FK cascade)
    - plan-70-01 (NpsTemplateController + rotas base + use import no routes/web.php)
  provides:
    - App\Http\Controllers\NpsTemplateQuestionController (4 endpoints REST admin-only)
    - App\Http\Requests\StoreNpsTemplateQuestionRequest (validação com Rule::in TIPOS+DIMENSOES)
    - App\Http\Requests\UpdateNpsTemplateQuestionRequest (PUT parcial SEM tipo — imutável)
    - 4 rotas nomeadas nps.configuracao.templates.perguntas.{store,update,destroy,mover}
  affects:
    - Plan 70-03 (CRUD opções) — usará route model binding {template}/perguntas/{pergunta}/opcoes/{opcao}
    - Plan 70-05 (rewrite Configuracao.jsx) — consumirá 4 endpoints via forms Inertia
    - Plan 70-06 (Feature tests SC2 SC5) — testa auto-5-options em escala + guard tipo imutável + SWAP reorder
tech-stack:
  added: []
  patterns:
    - FormRequest com camada dupla de autorização (middleware role:admin + authorize())
    - Route model binding nested com scopeBindings() → 404 auto se pergunta não pertence ao template
    - Guard interno abort_if($pergunta->template_id !== $template->id, 404) — defesa em profundidade
    - DB::transaction ao criar pergunta escala (pergunta + 5 options atômico)
    - SWAP transacional de ordem (pattern Phase 33 NpsController::moverPerguntaExtra)
    - Chave imutável fora do rules() (Laravel ignora silenciosamente no validated())
key-files:
  created:
    - app/Http/Controllers/NpsTemplateQuestionController.php
    - app/Http/Requests/StoreNpsTemplateQuestionRequest.php
    - app/Http/Requests/UpdateNpsTemplateQuestionRequest.php
  modified:
    - routes/web.php
decisions:
  - "tipo OMITIDO do UpdateNpsTemplateQuestionRequest (sem 'sometimes' — não existe no rules) → Laravel ignora silenciosamente no validated() → payload malicioso {tipo:opcoes} não chega ao Model::update(). Motivo: mudar escala→opcoes deixaria 5 options órfãs (confuso); mudar opcoes→escala com options manuais destruiria trabalho do admin (silencioso). Admin que quer mudar tipo: apaga e recria."
  - "DB::transaction envolve create pergunta + loop 5 options porque quebrar a atomicidade permitiria estado 'pergunta escala sem opções' — invariante 'escala tem exatamente 5 opções' precisa ser garantida no backend (form público renderiza radio group vazio se options=[])."
  - "scopeBindings() SÓ em update/destroy/mover (não em store) — store não recebe {pergunta}, só {template}. Aplicar scopeBindings em store daria warning silencioso do Laravel."
  - "Guard interno abort_if($pergunta->template_id !== $template->id, 404) em update/destroy/mover mesmo com scopeBindings() ativo — belt-and-suspenders. Se algum dev futuro remover ->scopeBindings() da rota por engano, o guard evita cross-template edit/delete/swap (bug catastrófico silencioso)."
  - "SWAP reorder scoped ao template (WHERE template_id = $template->id nas duas queries de vizinha) — sem isso, mover pergunta A de Template X 'up' poderia trocar ordem com pergunta B de Template Y (bug catastrófico). Pattern idêntico ao Phase 33 mas com filtro extra."
  - "Loop `for ($i = 1; $i <= 5; $i++)` cria 5 options com label=(string)$i, peso=$i, ordem=$i — pesos fixos 1..5 hardcoded (research §5). Não sobrescrevemos pesos aqui — admin edita via Plan 70-03 depois se quiser 0..10 ou labels textuais."
  - "obrigatoria default true no store (após validated()) — pergunta nasce obrigatória, admin desmarca via update se quiser opcional. Simetria com o seed 'NPS Padrão' (estrategista + empresa obrigatorias, analista opcional)."
metrics:
  tasks: 6
  files_created: 3
  files_modified: 1
  commits: 1
  loc_added: 274
  completed_date: 2026-07-08
---

# Phase 70 Plan 02: NpsTemplateQuestionController CRUD Summary

**One-liner:** Backend REST CRUD das perguntas de templates NPS (store/update/destroy/mover) com auto-geração transacional de 5 options em tipo=escala + tipo imutável após criação + SWAP reorder scoped por template — 4 rotas nested com `scopeBindings()` dentro do grupo `role:admin`.

## Contrato dos 4 endpoints

```
POST   /nps/configuracao/templates/{template}/perguntas                   → store
PUT    /nps/configuracao/templates/{template}/perguntas/{pergunta}        → update    (scopeBindings)
DELETE /nps/configuracao/templates/{template}/perguntas/{pergunta}        → destroy   (scopeBindings)
POST   /nps/configuracao/templates/{template}/perguntas/{pergunta}/mover  → mover     (scopeBindings)
```

Middleware chain aplicada a todas: `web` → `Authenticate` → `EnsureEmailIsVerified` → `EnsureUserHasRole:admin`. Non-admin recebe 403 imediato via `abort(403, 'Acesso não autorizado.')` do `EnsureUserHasRole`.

## Comportamento por endpoint

### store(StoreNpsTemplateQuestionRequest, NpsTemplate)
- Aceita: `texto` (required min:3 max:500), `tipo` (required in TIPOS), `dimensao` (required in DIMENSOES), `obrigatoria` (boolean).
- Injeta: `template_id = $template->id` (do route binding, não do payload).
- Calcula: `ordem = ($template->questions()->max('ordem') ?? 0) + 1` — final da lista.
- Default: `obrigatoria ??= true` — pergunta nasce obrigatória.
- **Transação atômica** (`DB::transaction`): cria a pergunta e, se `tipo=escala`, cria 5 `NpsTemplateOption` filhas com labels `"1"…"5"`, pesos `1..5`, ordens `1..5`. Se qualquer parte falhar, rollback total — sem estado intermediário quebrado.
- Retorna: `back()->with('success', 'Pergunta criada.')`.

### update(UpdateNpsTemplateQuestionRequest, NpsTemplate, NpsTemplateQuestion)
- Route model binding scoped: Laravel resolve `{pergunta}` via `NpsTemplateQuestion::where('template_id', $template->id)->firstOrFail()` → 404 se cross-template.
- Guard interno: `abort_if($pergunta->template_id !== $template->id, 404)` — defesa em profundidade caso scopeBindings falhe.
- Aceita: `texto`, `dimensao`, `obrigatoria` — todas `sometimes` (PUT parcial).
- **Chave `tipo` deliberadamente FORA do `rules()`** — mesmo que venha no payload, o Laravel ignora no `validated()`. Tipo é IMUTÁVEL (research §5).
- Retorna: `back()->with('success', 'Pergunta atualizada.')`.

### destroy(NpsTemplate, NpsTemplateQuestion)
- Guard idem `update` (abort_if scope).
- Cascade FK em `nps_template_options.question_id` (Plan 68-01) apaga options filhas automaticamente.
- Snapshot congelado em `nps_response_answers` (Phase 68) preserva histórico de respostas dadas — o `question_texto_snapshot` sustenta o display no drilldown mesmo após delete.
- Retorna: `back()->with('success', 'Pergunta removida.')`.

### mover(Request, NpsTemplate, NpsTemplateQuestion)
- Guard idem (abort_if scope) + valida `direcao` (`required|in:up,down`).
- Query de vizinha **scoped ao template** — filtro `where('template_id', $template->id)` em ambas as direções (crítico: sem isso, mover pergunta A de Template X poderia trocar ordem com pergunta B de Template Y silenciosamente).
- No-op se pergunta já está no extremo (`return back()` sem mensagem).
- SWAP atômico em `DB::transaction` — só 2 rows tocadas (O(1) independente do tamanho da lista).
- Retorna: `back()` (sem flash message — a UI já mostra a nova ordem).

## Regras de imutabilidade do tipo

Existem 2 barreiras para proteger o invariante "tipo é imutável após criação":

1. **UpdateNpsTemplateQuestionRequest:** chave `tipo` NÃO existe no `rules()` → Laravel remove do `validated()` mesmo se vier no payload.
2. **Motivo semântico documentado no docblock do Request:** mudança de tipo é destrutiva (5 options órfãs ou perda silenciosa de options manuais). Admin apaga e recria se precisar.

## Cobertura dos truths (frontmatter)

| Truth | Status | Evidência |
|-------|--------|-----------|
| POST cria pergunta com ordem MAX+1 auto | OK | `store()` linha `$data['ordem'] = ($template->questions()->max('ordem') ?? 0) + 1;` |
| tipo=escala auto-cria 5 options 1..5 na transação | OK | `store()` — loop `for ($i = 1; $i <= 5; $i++)` dentro de `DB::transaction` |
| tipo=opcoes nasce sem opções | OK | `store()` — condicional `if ($question->tipo === TIPO_ESCALA)` só entra em escala |
| PUT NÃO permite mudar tipo (imutável) | OK | `UpdateNpsTemplateQuestionRequest::rules()` sem chave `tipo` |
| DELETE apaga pergunta + cascade options | OK | `destroy()` — `$pergunta->delete()` + FK cascade schema Plan 68-01 |
| POST /mover SWAP transacional no-op no extremo | OK | `mover()` — `if (!$vizinha) return back()` + `DB::transaction` |
| {pergunta} pertence a {template} — 404 se scope errado | OK | `scopeBindings()` na rota + `abort_if` interno em 3 métodos |
| Dimensão ∈ DIMENSOES | OK | Store: `Rule::in(NpsTemplateQuestion::DIMENSOES)`, Update: idem |

## Validação executada

```
C:/xampp/php/php.exe -l app/Http/Controllers/NpsTemplateQuestionController.php    → No syntax errors detected
C:/xampp/php/php.exe -l app/Http/Requests/StoreNpsTemplateQuestionRequest.php     → No syntax errors detected
C:/xampp/php/php.exe -l app/Http/Requests/UpdateNpsTemplateQuestionRequest.php    → No syntax errors detected
C:/xampp/php/php.exe -l routes/web.php                                            → No syntax errors detected

C:/xampp/php/php.exe artisan route:list --path=nps/configuracao/templates:
  GET|HEAD  nps/configuracao/templates                                              → nps.configuracao.templates.index
  POST      nps/configuracao/templates                                              → nps.configuracao.templates.store
  PUT       nps/configuracao/templates/{template}                                   → nps.configuracao.templates.update
  POST      nps/configuracao/templates/{template}/perguntas                         → nps.configuracao.templates.perguntas.store
  PUT       nps/configuracao/templates/{template}/perguntas/{pergunta}              → nps.configuracao.templates.perguntas.update
  DELETE    nps/configuracao/templates/{template}/perguntas/{pergunta}              → nps.configuracao.templates.perguntas.destroy
  POST      nps/configuracao/templates/{template}/perguntas/{pergunta}/mover        → nps.configuracao.templates.perguntas.mover
  PATCH     nps/configuracao/templates/{template}/toggle-active                     → nps.configuracao.templates.toggle-active

Total: 8 rotas (4 do Plan 70-01 + 4 do Plan 70-02).
Middleware (todas): web, Authenticate, EnsureEmailIsVerified, EnsureUserHasRole:admin.
```

## Zero mudança em NpsController.php nem NpsTemplateController.php

Confirmado via `git diff --name-only` — ambos os controllers legados intactos. Isso libera:
- Plan 70-03 (CRUD opções) para rodar em paralelo sem conflito de merge.
- Plan 70-04 (sync scopes + preview) idem.
- Plan 70-05 (rewrite Configuracao.jsx) idem — consome os 4 endpoints via Inertia forms.

O CRUD legado de perguntas extras da Phase 33 (`criarPerguntaExtra`, `atualizarPerguntaExtra`, `excluirPerguntaExtra`, `moverPerguntaExtra` em `NpsController`) continua funcionando sem alteração — perguntas extras da Phase 33 e perguntas de template da Phase 70 são recursos independentes que coexistem até a Plan 70-05 substituir a UI.

## Deviations from Plan

None — plan executado exatamente como escrito. Nenhum ajuste semântico foi necessário.

Nota sobre alinhamento com o Plan 70-01: o método `store` foi implementado seguindo EXATAMENTE o padrão do plan (transação envolve pergunta + options). A verificação de `$question->tipo === NpsTemplateQuestion::TIPO_ESCALA` acontece DENTRO da transação para garantir atomicidade da criação escala inteira.

## Commit

| Commit | Tipo | Descrição |
|--------|------|-----------|
| (pendente) | feat | `feat(70-02): NpsTemplateQuestionController CRUD + auto-5-options escala + SWAP reorder + scopeBindings` |

## Threat Flags

Nenhum — mitigações do plan respeitadas:

- **Auth bypass:** camada dupla (middleware `role:admin` + FormRequest `authorize()` retorna `$this->user()?->isAdmin() ?? false`).
- **Cross-template edit/delete/swap:** camada tripla — schema FK garante integridade referencial + `scopeBindings()` na rota devolve 404 auto + guard interno `abort_if($pergunta->template_id !== $template->id, 404)` em 3 métodos.
- **Mass assignment (tipo imutável):** chave `tipo` OMITIDA do UpdateNpsTemplateQuestionRequest → payload malicioso `{tipo:opcoes}` não chega ao Model::update().
- **Estado intermediário quebrado (escala sem options):** `DB::transaction` no store envolve create pergunta + loop 5 options — rollback total em qualquer falha.
- **SWAP cross-template silencioso:** queries de vizinha filtradas por `where('template_id', $template->id)` — impossível trocar ordem com pergunta de outro template.
- **SQL raw:** zero — query builder puro + route model binding.
- **CSRF:** as 4 rotas herdam grupo `web` (não têm `withoutMiddleware`).

## Referências

- `.planning/phases/70-ui-de-configuracao-admin/70-02-PLAN.md`
- `.planning/phases/70-ui-de-configuracao-admin/70-01-SUMMARY.md` (padrão herdado)
- `.planning/research/v15-nps-templates-schema.md` §5 (tipo escala auto-5) e §3 (SWAP zero-deps)
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md` (schema + cascade FK)
- `app/Models/NpsTemplateQuestion.php` (fillable + TIPOS + DIMENSOES)
- `app/Models/NpsTemplateOption.php` (fillable label/peso/ordem)
- `app/Http/Controllers/NpsController.php` linhas 978-1010 (SWAP pattern origem)
- `app/Http/Controllers/NpsTemplateController.php` (Plan 70-01 — padrão de docblock/comentários)
- `app/Http/Middleware/EnsureUserHasRole.php` (guard `role:admin`)

## Self-Check

- app/Http/Controllers/NpsTemplateQuestionController.php — FOUND (~200 linhas)
- app/Http/Requests/StoreNpsTemplateQuestionRequest.php — FOUND (~72 linhas)
- app/Http/Requests/UpdateNpsTemplateQuestionRequest.php — FOUND (~72 linhas)
- routes/web.php — 4 rotas `nps.configuracao.templates.perguntas.*` registradas com middleware `role:admin`
- `php -l` — 4/4 passed
- `php artisan route:list --path=nps/configuracao/templates` — 8/8 listadas (4 do 70-01 + 4 do 70-02)
- NpsController.php intocado — `git diff --name-only` retorna vazio
- NpsTemplateController.php intocado — `git diff --name-only` retorna vazio

## Self-Check: PASSED
