---
phase: 05
plan: 02
subsystem: admin-fechamento
tags: [controller, routes, tdd-green, wave-2]
dependency_graph:
  requires: [05-01]
  provides: [fechamento-controller, financeiro-patch-route]
  affects: [AdminController, Company, routes/web.php]
tech_stack:
  added: []
  patterns: [manual-validator-422, inertia-redirect-success, date-cast-explicit-format]
key_files:
  created: []
  modified:
    - app/Http/Controllers/AdminController.php
    - app/Models/Company.php
    - routes/web.php
decisions:
  - Manual Validator facade over $request->validate() para retornar 422 JSON em falhas de validação sem depender do header X-Inertia
  - cast date:Y-m-d no lugar de date para forçar formato ISO no SQLite (evita Y-m-d H:i:s)
metrics:
  duration: ~15min
  completed: 2026-05-19
---

# Phase 5 Plan 02: AdminController fechamento() + updateFechamento() + PATCH Route Summary

AdminController recebe `fechamento()` com query de empresas ativas + mapa has_adman, e `updateFechamento()` com validação explícita retornando 422 JSON em falha; PATCH route registrada em routes/web.php.

## Tasks Executadas

| Task | Descrição | Commit | Arquivos |
|------|-----------|--------|---------|
| 1 | AdminController.php — fechamento() + updateFechamento() | 35a393a | app/Http/Controllers/AdminController.php, app/Models/Company.php |
| 2 | routes/web.php — GET atualizado + PATCH adicionado | 35a393a | routes/web.php |

## Route List Output

```
  GET|HEAD    administrativo/financeiro ................................ admin.financeiro › AdminController@fechamento
  PATCH       administrativo/financeiro/{company} ......... admin.financeiro.update › AdminController@updateFechamento

                                                                        Showing [2] routes
```

## Test Results

### AdminFechamentoControllerTest

```
........                                  8 / 8 (100%)
OK (8 tests, 48 assertions)
```

### Todos os testes Fechamento (--filter=Fechamento)

```
.........                                 9 / 9 (100%)
OK (9 tests, 52 assertions)
```

### Suite completa

```
40 tests, 163 assertions
1 pre-existing failure (ExampleTest — não autenticado em / espera 200, app redireciona 302)
Zero regressões introduzidas por este plano
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Validação retornava 302 ao invés de 422 em falha**
- **Encontrado durante:** Task 1 — execução dos testes
- **Issue:** `$request->validate()` lança `ValidationException` que o Laravel redireciona com 302 em rotas web sem header `X-Inertia`. Os testes `test_update_rejeita_service_type_invalido` e `test_update_rejeita_contract_end_anterior` esperavam 422.
- **Fix:** Substituído `$request->validate()` por `Validator::make()` manual com retorno explícito `response()->json(['errors' => ...], 422)` em caso de falha. Path de sucesso permanece `back()->with('success', ...)`.
- **Arquivos modificados:** `app/Http/Controllers/AdminController.php`
- **Commit:** 35a393a

**2. [Rule 1 - Bug] Cast `date` armazenava como `Y-m-d H:i:s` no SQLite**
- **Encontrado durante:** Task 1 — teste `test_update_persiste_datas_contrato`
- **Issue:** `assertDatabaseHas` comparava `'2026-01-01'` mas o banco (SQLite in-memory) armazenava `'2026-01-01 00:00:00'` porque o `$dateFormat` padrão do Eloquent é `Y-m-d H:i:s`.
- **Fix:** Alterados os casts de `'date'` para `'date:Y-m-d'` no modelo Company para forçar armazenamento no formato ISO `Y-m-d`.
- **Arquivos modificados:** `app/Models/Company.php`
- **Commit:** 35a393a

## Known Stubs

Nenhum. `fechamento()` entrega dados reais do banco (`Company::where('active', true)`). `updateFechamento()` persiste via `$company->update()`.

## Threat Flags

Nenhum novo surface de segurança introduzido. A rota PATCH está dentro do grupo `role:admin`, model binding protege contra IDs inexistentes, validação impede valores inválidos.

## Self-Check: PASSED

- `app/Http/Controllers/AdminController.php` — FOUND
- `app/Models/Company.php` — FOUND  
- `routes/web.php` — FOUND
- Commit 35a393a — FOUND
- Route admin.financeiro (GET) — FOUND
- Route admin.financeiro.update (PATCH) — FOUND
- 8/8 AdminFechamentoControllerTest — PASSED
- 9/9 Fechamento tests — PASSED

## Status: COMPLETE
