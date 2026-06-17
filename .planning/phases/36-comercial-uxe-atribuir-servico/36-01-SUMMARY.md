---
phase: 36-comercial-uxe-atribuir-servico
plan: 01
subsystem: comercial
tags: [comercial, ux, routes, sidebar, refactor]
requires: []
provides:
  - rota_comercial_empresas_vira_redirect
  - comercial_controller_empresas_removido
  - comercial_empresas_jsx_apagado
  - appLayout_sidebar_aponta_para_novo
affects:
  - routes/web.php
  - app/Http/Controllers/ComercialController.php
  - resources/js/Pages/Comercial/Empresas.jsx
  - resources/js/Layouts/AppLayout.jsx
tech-stack:
  added: []
  patterns:
    - Closure-based route redirect (no controller method needed for 302)
    - Sidebar route binding direto pra rota final (evita 302 hop intermediario)
key-files:
  created: []
  modified:
    - routes/web.php
    - app/Http/Controllers/ComercialController.php
    - resources/js/Layouts/AppLayout.jsx
  deleted:
    - resources/js/Pages/Comercial/Empresas.jsx
decisions:
  - "D-01: /comercial/empresas vira redirect 302 (closure inline na rota — sem método controller)"
  - "D-02: bloco filha_ids some junto com Comercial/Empresas.jsx — backend update() preserva compat (validate de filha_ids mantido)"
  - "Sidebar: label 'Cadastrar empresa', routeName aponta direto pra comercial.empresas.novo"
metrics:
  duration_min: 8
  completed: "2026-06-17"
  tasks_count: 4
  files_modified: 4
---

# Phase 36 Plan 36-01: Simplifica /comercial/empresas Summary

`/comercial/empresas` deixa de ser uma listagem + edit form e vira redirect 302 para `/comercial/empresas/novo` — a UI legada (`Comercial/Empresas.jsx`) é apagada e o método `ComercialController::empresas()` é removido; sidebar passa a apontar direto pra rota de cadastro.

## Implementação

### 1. `routes/web.php` — rota vira closure de redirect

Antes:
```php
Route::get('/empresas', [ComercialController::class, 'empresas'])->name('empresas');
```

Depois:
```php
Route::get('/empresas', function () {
    return redirect()->route('comercial.empresas.novo');
})->name('empresas');
```

Nome `comercial.empresas` preservado (sidebar/bookmarks/links em emails de notificação não quebram). Demais rotas comerciais (POST/PUT/DELETE + `empresas.novo`) intactas.

### 2. `app/Http/Controllers/ComercialController.php` — remove `empresas()`

Método `empresas()` (70 linhas — query Company + eager load contratos/pai/filhas, transform de servicos_contratados, query Servico, render `Inertia::render('Comercial/Empresas')`) **removido por completo**.

Bonus: `index()` (deprecated) ajustado para apontar direto pra `comercial.empresas.novo` (evita redirect duplo: antes redirecionava pra `comercial.empresas`, que agora também é redirect).

Imports `App\Models\Setor` e `Inertia\Inertia` **preservados** — ainda usados em `store()` (notificação de líderes) e `create()` (render NovaEmpresa).

`ComercialController::update()` mantém intacto o validate de `filha_ids` (compat com possíveis outros consumers — D-02 do CONTEXT).

### 3. `resources/js/Pages/Comercial/Empresas.jsx` — DELETADO

757 linhas removidas. Não há nenhum outro consumer (grep `Comercial/Empresas` em código JSX/PHP retornou apenas referências de `.planning/*` docs).

### 4. `resources/js/Layouts/AppLayout.jsx` — sidebar Comercial

Antes:
```jsx
{ label: 'Entrada de Empresas', routeName: 'comercial.empresas', page: 'Comercial/Empresas', ... }
```

Depois:
```jsx
{ label: 'Cadastrar empresa', routeName: 'comercial.empresas.novo', page: 'Comercial/NovaEmpresa', ... }
```

- Label: clareza ("Entrada de Empresas" sugere listagem; "Cadastrar empresa" descreve a ação real)
- routeName: direto pra rota final, sem hop pelo redirect 302
- page: apontamento Inertia coerente com a página real

## Verificação

### Rotas (`php artisan route:list | grep comercial`)

```
GET    comercial/empresas           name=comercial.empresas        routes/web.php:173 (closure redirect)
GET    comercial/empresas/novo      name=comercial.empresas.novo   ComercialController@create
POST   comercial/empresas           name=comercial.empresas.store  ComercialController@store
PUT    comercial/empresas/{company} name=comercial.empresas.update ComercialController@update
DELETE comercial/empresas/{company} name=comercial.empresas.destroy ComercialController@destroy
GET    comercial/atribuir-servico/{company} name=comercial.atribuir-servico (Plan 36-02)
```

### Build (`npm run build`) — Verde

Vite build completou em 18.54s. Bundle do `Comercial/Empresas` não consta mais; `Comercial/NovaEmpresa` segue ativo.

### Test suite

- **Phase 31+33+34+35 (escopo do prompt): 70 passed (526 assertions)** — zero regressão
- Phase 13/14 Comercial: 11 falhas **pré-existentes** (verificadas no commit `b1c0470` antes do meu commit `9055948`) — não causadas por este plan. São testes legacy de `service_type` que dependem de schema antigo.

## Commits

| # | Hash      | Mensagem                                                         | Notas                                                  |
|---|-----------|------------------------------------------------------------------|--------------------------------------------------------|
| 1 | `9055948` | `feat(36-01): /comercial/empresas vira redirect para /novo`      | rota + Controller::empresas() removido + index() fix   |
| 2 | `b67edb2` | `feat(36-02): rota + ComercialController::atribuirServico`       | (paralelo 36-02 absorveu `git rm Empresas.jsx` — D-02) |
| 3 | `9ec7cd3` | `feat(36-02): redirect botao Servico pendencias para Comercial`  | (paralelo 36-02 absorveu mudança no AppLayout sidebar) |

**Nota sobre commits 2 e 3**: o Plan 36-02 rodou em paralelo e absorveu duas mudanças que estavam no working tree do Plan 36-01 (deletion do `Comercial/Empresas.jsx` e edit do `AppLayout.jsx`). O conteúdo final aplicado é exatamente o planejado pelo Plan 36-01 (comentários, label, routeName, page do AppLayout), apenas a autoria do commit ficou no namespace 36-02. Conflito previsto pelo prompt ("ambos tocam routes/web.php e ComercialController e AppLayout.jsx — conflito provável mas trivial").

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug fix preventivo] `ComercialController::index()` apontava pra rota agora redirect**
- **Found during:** Task 1 (remove `empresas()`)
- **Issue:** `index()` deprecated fazia `redirect()->route('comercial.empresas')`. Com `comercial.empresas` virando redirect, qualquer caller do `index()` faria 2 hops (302 → 302).
- **Fix:** Ajustado para `redirect()->route('comercial.empresas.novo')` direto.
- **Files modified:** `app/Http/Controllers/ComercialController.php`
- **Commit:** `9055948`

### Auth gates

Nenhum encontrado.

### Arquitetural changes

Nenhuma. Mudança puramente de roteamento/UI.

## Known Stubs

Nenhum. Plan 36-01 não introduz stubs — apenas remove código obsoleto.

## Threat Flags

Nenhum. Remoção de listagem + redirect 302 não introduz nova surface security.

## Coordination notes for Plan 36-02 (paralelo)

- **Resolved overlap**: Plan 36-02 absorveu naturalmente os artefatos do Plan 36-01 no `Comercial/Empresas.jsx` delete e no `AppLayout.jsx` sidebar update. Sem merge conflict — git tratou como add/del/modify limpos.
- **Routes**: ambos os plans tocaram `routes/web.php` (Plan 36-01 alterou linha existente comercial.empresas; Plan 36-02 adicionou rota nova comercial.atribuir-servico). Sem colisão.
- **ComercialController.php**: Plan 36-01 removeu método `empresas()`; Plan 36-02 adicionou método `atribuirServico()`. Sem colisão.
- **Gotcha para 36-02**: o sidebar atualizado pelo Plan 36-01 só lista UMA entrada do grupo Comercial ("Cadastrar empresa"). Se o Plan 36-02 quiser adicionar item de menu "Atribuir Serviço" no sidebar, basta inserir 2º item no `children` do grupo Comercial.

## Self-Check

### Created files
- `c:\xampp\htdocs\ecf_admin\ecf_admin\.planning\phases\36-comercial-uxe-atribuir-servico\36-01-SUMMARY.md` — FOUND (este arquivo)

### Deleted files
- `resources/js/Pages/Comercial/Empresas.jsx` — REMOVED via `b67edb2` (Plan 36-02 absorveu)

### Modified files
- `routes/web.php` — modified via `9055948` (FOUND)
- `app/Http/Controllers/ComercialController.php` — modified via `9055948` (FOUND)
- `resources/js/Layouts/AppLayout.jsx` — modified via `9ec7cd3` (FOUND)

### Commits
- `9055948` — FOUND in git log
- `b67edb2` — FOUND in git log (Plan 36-02 absorved overlap)
- `9ec7cd3` — FOUND in git log (Plan 36-02 absorved overlap)

## Self-Check: PASSED
