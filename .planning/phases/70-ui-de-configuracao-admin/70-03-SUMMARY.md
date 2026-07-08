---
phase: 70-ui-de-configuracao-admin
milestone: v15.0
plan: 70-03
subsystem: nps-backend
tags: [nps, backend, controller, crud, options, peso, reorder, swap, admin, phase70]
requires:
  - "NpsTemplate model (Plan 68-02)"
  - "NpsTemplateQuestion model (Plan 68-02)"
  - "NpsTemplateOption model (Plan 68-02)"
  - "NpsTemplateController (Plan 70-01)"
  - "NpsTemplateQuestionController (Plan 70-02)"
provides:
  - "REST CRUD triplo-nested de opções de resposta das perguntas de template NPS"
  - "Guard invariante: pergunta escala não pode ficar com 0 opções"
  - "Validação peso 1..5 em profundidade (FormRequest + UI)"
  - "SWAP transacional reorder scoped por question_id"
affects:
  - "app/Http/Controllers/NpsTemplateOptionController.php (novo)"
  - "app/Http/Requests/StoreNpsTemplateOptionRequest.php (novo)"
  - "app/Http/Requests/UpdateNpsTemplateOptionRequest.php (novo)"
  - "routes/web.php (4 rotas + 1 use import)"
tech_stack:
  added: []
  patterns:
    - "Route model binding com scopeBindings() triplo-nested"
    - "FormRequest sometimes-based para PUT parcial"
    - "SWAP transacional O(1) para reorder (herdado Plan 70-02)"
    - "Guard defesa em profundidade abort_if em cada método"
key_files:
  created:
    - "app/Http/Controllers/NpsTemplateOptionController.php"
    - "app/Http/Requests/StoreNpsTemplateOptionRequest.php"
    - "app/Http/Requests/UpdateNpsTemplateOptionRequest.php"
  modified:
    - "routes/web.php"
decisions:
  - "Store TAMBÉM usa scopeBindings() (não só update/destroy/mover) — a URL de store já recebe {pergunta}, então scoped binding elimina classe inteira de bugs cross-template desde o primeiro método"
  - "Guard invariante escala checa `options()->count() <= 1` (não `< 1`) — o count atual INCLUI a opção que está sendo apagada; <=1 significa que apagar deixaria a pergunta com 0"
  - "Peso 1..5 travado no FormRequest via `integer|min:1|max:5` — mesmo Store e Update repetem a regra para não deixar Update fugir por sometimes"
  - "Perguntas tipo=opcoes NÃO caem no guard — admin pode zerar e recomeçar durante a montagem inicial (pattern 'warn but allow' com aviso na UI Phase 71)"
metrics:
  duration_min: 8
  completed_date: "2026-07-08"
  tasks_total: 6
  tasks_completed: 6
  files_created: 3
  files_modified: 1
---

# Phase 70 Plan 70-03: NpsTemplateOptionController CRUD Summary

CRUD backend triplo-nested de opções de resposta das perguntas de NpsTemplate — 4 endpoints REST sob `/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/…` com scopeBindings() em todas as rotas, guard duplo de escopo em cada método, guard invariante contra apagar a última opção de uma pergunta escala, peso 1..5 travado em FormRequest, e SWAP transacional idêntico ao pattern do Plan 70-02 mas scoped por `question_id`.

## Objetivo Atingido

Fechar 100% do REQ NPS-C-03 (label + peso 1..5 + reorder) com defesa em profundidade nos dois eixos: (a) validação de peso não pode ser burlada nem pelo Store nem pelo Update (mesmo com sometimes); (b) invariante "escala tem pelo menos 1 opção" preservado — sem esse guard, apagar a última opção de uma escala trancaria o form público (radio group vazio). Zero mudança em `NpsController`, `NpsTemplateController` (Plan 70-01) ou `NpsTemplateQuestionController` (Plan 70-02) — 3 controllers de templates convivem em paralelo.

## Tarefas Completadas

| Task | Nome | Files | Notas |
|------|------|-------|-------|
| T1 | StoreNpsTemplateOptionRequest | `app/Http/Requests/StoreNpsTemplateOptionRequest.php` | authorize() via isAdmin, label required max:200, peso integer 1..5 |
| T2 | UpdateNpsTemplateOptionRequest sometimes-based | `app/Http/Requests/UpdateNpsTemplateOptionRequest.php` | Ambas regras `sometimes\|required` para PUT parcial |
| T3 | NpsTemplateOptionController::store | `app/Http/Controllers/NpsTemplateOptionController.php` | Guard scope + ordem MAX+1 + `question_id` do route |
| T4 | update + destroy (guard escala) + mover | mesma file | Guard duplo scope nos 3 métodos + guard invariante `TIPO_ESCALA && count<=1` → 422 + SWAP scoped por `question_id` |
| T5 | 4 rotas triplo-nested com scopeBindings | `routes/web.php` | Import + 4 rotas — todas com `->scopeBindings()`, inclusive `store` |
| T6 | Smoke test | — | `php -l` verde nos 3 novos PHP; `route:list` mostra 12 rotas |

## Endpoints Registrados

| Método | URI | Nome | Model bindings |
|--------|-----|------|----------------|
| POST | `/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes` | `nps.configuracao.templates.perguntas.opcoes.store` | template + pergunta scoped |
| PUT | `/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}` | `nps.configuracao.templates.perguntas.opcoes.update` | triplo scoped |
| DELETE | `/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}` | `nps.configuracao.templates.perguntas.opcoes.destroy` | triplo scoped |
| POST | `/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}/mover` | `nps.configuracao.templates.perguntas.opcoes.mover` | triplo scoped |

Total rotas em `/nps/configuracao/templates`: **12** (4 Plan 70-01 + 4 Plan 70-02 + 4 Plan 70-03), confirmado via `php artisan route:list --path=nps/configuracao/templates`.

## Contratos Cobertos

- **Guard duplo scope em update/destroy/mover:**
  ```php
  abort_if($pergunta->template_id !== $template->id, 404);
  abort_if($opcao->question_id !== $pergunta->id, 404);
  ```
  Defesa em profundidade além do `scopeBindings()` da rota — se alguém remover o scopeBindings no futuro, os guards evitam edição/exclusão cross-template ou cross-question.

- **Guard invariante escala em destroy:**
  ```php
  if ($pergunta->tipo === NpsTemplateQuestion::TIPO_ESCALA
      && $pergunta->options()->count() <= 1) {
      abort(422, 'Uma pergunta de escala precisa ter ao menos 1 opção.');
  }
  ```
  Perguntas `tipo=opcoes` NÃO caem no guard (pattern "warn but allow" — a UI da Phase 71 vai avisar).

- **SWAP transacional scoped por `question_id`** (idêntico ao pattern do Plan 70-02, mas com WHERE em `question_id` no lugar de `template_id`):
  ```php
  $vizinha = NpsTemplateOption::where('question_id', $pergunta->id)
      ->where('ordem', '<', $opcao->ordem)  // ou > para down
      ->orderByDesc('ordem')
      ->orderByDesc('id')
      ->first();
  ```
  No-op se `!$vizinha` (opção no extremo); swap dentro de `DB::transaction` — 2 rows tocadas, O(1) independente do tamanho da lista.

- **Peso 1..5 travado em ambos FormRequests** — não só no Store; mesmo com `sometimes`, o Update valida `integer|min:1|max:5` quando o campo está presente.

## Decisões Chave

1. **Store TAMBÉM usa `scopeBindings()`** — o plan text sugeria só update/destroy/mover, mas a URL de store já contém `{pergunta}`, então adicionar `scopeBindings()` na store elimina classe inteira de bugs cross-template desde o primeiro método (belt-and-suspenders coerente).

2. **`count() <= 1` (não `< 1`)** — o `$pergunta->options()->count()` é executado ANTES do `$opcao->delete()` e portanto conta a opção atual; `<= 1` significa que apagar deixaria com 0.

3. **Peso 1..5 duplicado em Store e Update** — não usar herança via FormRequest base porque o overhead não paga (2 arquivos de 60 linhas cada, regra idêntica) e mantém cada FormRequest legível de cima a baixo.

4. **Perguntas `tipo=opcoes` sem guard** — admin precisa poder zerar durante a montagem inicial ("apagar tudo e recomeçar" é fluxo válido); a UI da Phase 71 renderiza aviso no form público, não trava o backend.

## Deviations from Plan

Nenhum. Plan executado exatamente como escrito. Único ajuste interpretativo: `store` também recebeu `scopeBindings()` na rota — o plan text abriu essa possibilidade na "Nota nome do parâmetro" (T5) e implementá-la é a extensão natural do padrão de segurança em profundidade.

## Verificações Executadas

- `php -l app/Http/Controllers/NpsTemplateOptionController.php` → No syntax errors detected
- `php -l app/Http/Requests/StoreNpsTemplateOptionRequest.php` → No syntax errors detected
- `php -l app/Http/Requests/UpdateNpsTemplateOptionRequest.php` → No syntax errors detected
- `php artisan route:list --path=nps/configuracao/templates` → **12 rotas** listadas (4+4+4), todas com scoped bindings visíveis nos parâmetros nomeados
- `git diff --name-only HEAD -- app/Http/Controllers/NpsController.php app/Http/Controllers/NpsTemplateController.php app/Http/Controllers/NpsTemplateQuestionController.php` → **vazio** (zero regressão em controllers preexistentes)

## Isolamento

- ZERO mudança em `NpsController.php`, `NpsTemplateController.php` ou `NpsTemplateQuestionController.php`.
- ZERO mudança em Models (`NpsTemplate`, `NpsTemplateQuestion`, `NpsTemplateOption`).
- ZERO mudança em migrations, jobs, services, comandos ou factories.
- ZERO dep nova (composer.lock / package.json intactos).
- Working tree `app/Http/Controllers/MercadoLivreOAuthController.php` (M pré-existente da sessão) intocado.

## Próximo Passo

Wave 1 do Phase 70 (Plans 70-01, 70-02, 70-03) fechada — backend REST de templates + perguntas + opções pronto. Waves seguintes:

- **Wave 2:** Plan 70-04 (sync scopes atomic + preview endpoint + empresas-afetadas via NpsTemplateService).
- **Wave 3:** Plan 70-05 (reescrita `Configuracao.jsx` multi-template com 6 componentes filhos consumindo estas 12 rotas + PreviewFormulario portável Phase 71).
- **Wave 4:** Plan 70-06 (Feature tests 24 cobrindo SC1-SC5 + baseline regressão zero).

## Self-Check: PASSED

- FOUND: app/Http/Controllers/NpsTemplateOptionController.php
- FOUND: app/Http/Requests/StoreNpsTemplateOptionRequest.php
- FOUND: app/Http/Requests/UpdateNpsTemplateOptionRequest.php
- FOUND: routes/web.php (edited)
- FOUND: 12 rotas em `/nps/configuracao/templates` via `php artisan route:list`
