---
phase: 05-funda-o-fechamento
plan: "01"
subsystem: database/model
tags: [migration, company-model, test-stubs, wave-1]
dependency_graph:
  requires: []
  provides:
    - companies.service_type column
    - companies.contract_start column
    - companies.contract_end column
    - companies.additional_service column
    - Company.$fillable expanded
    - Company.$casts with date types
    - Company.logOnly with new fields
    - AdminFechamentoControllerTest stubs (RED)
    - CompanyServiceTypeTest stubs (GREEN)
    - FechamentoMigrationTest stubs (GREEN)
  affects:
    - app/Models/Company.php
    - database/migrations/
    - tests/
tech_stack:
  added: []
  patterns:
    - anonymous Migration class (same as 2026_04_27 analog)
    - RefreshDatabase + Company::create() (same as DevControllerTest pattern)
key_files:
  created:
    - database/migrations/2026_05_19_100001_add_service_fields_to_companies.php
    - tests/Feature/AdminFechamentoControllerTest.php
    - tests/Unit/CompanyServiceTypeTest.php
    - tests/Feature/FechamentoMigrationTest.php
  modified:
    - app/Models/Company.php
decisions:
  - "Usar string (não enum) para service_type conforme decisão D-02 do PLAN.md"
  - "Usar date (não datetime) para contract_start/contract_end conforme D-03"
  - "Testes AdminFechamentoController implementados completamente (não com markTestIncomplete) — falham RED porque o controller não existe ainda"
metrics:
  duration: "~10 minutos"
  completed_date: "2026-05-19"
  tasks_completed: 3
  tasks_total: 3
  files_created: 4
  files_modified: 1
---

# Phase 05 Plan 01: Fundação de Dados (Migration + Company Model + Test Stubs) Summary

## One-liner

Schema preparado: 4 colunas adicionadas à tabela companies, model Company expandido com fillable/casts/logOnly, e 3 arquivos de teste Wave 0 criados.

## What Was Done

### Task 1 — Wave 0: Criar stubs de teste RED

Criados 3 arquivos de teste seguindo o padrão exato de `DevControllerTest.php`:

- `tests/Feature/AdminFechamentoControllerTest.php` — 8 métodos cobrindo FCH-01/02/03 e controle de acesso
- `tests/Unit/CompanyServiceTypeTest.php` — 1 método validando persistência de service_type
- `tests/Feature/FechamentoMigrationTest.php` — 1 método verificando as 4 colunas novas

Padrão seguido: `namespace Tests\Feature`, `use RefreshDatabase`, `Company::create()` direto (sem factory), `actingAs`, `assertInertia`, `assertDatabaseHas`.

### Task 2 — Migration

Criada `database/migrations/2026_05_19_100001_add_service_fields_to_companies.php` seguindo o padrão anonymous class do analog `2026_04_27_100001_add_fields_to_adman_metrics_table.php`.

Colunas adicionadas após `notes`:
- `service_type` (string nullable)
- `contract_start` (date nullable)
- `contract_end` (date nullable)
- `additional_service` (string nullable)

`php artisan migrate` executado com sucesso — status: **Ran**.

### Task 3 — Model Company: edições cirúrgicas

3 mudanças aplicadas em `app/Models/Company.php`:

1. `$fillable` expandido com os 4 campos novos
2. `$casts` expandido de 1 para 3 entradas (`active`, `contract_start`, `contract_end`)
3. `logOnly()` em `getActivitylogOptions()` expandido com `service_type`, `contract_start`, `contract_end`

Nenhum outro método ou relacionamento foi alterado.

## Verification Results

| Teste | Status | Notas |
|-------|--------|-------|
| FechamentoMigrationTest | GREEN (1 test, 4 assertions) | 4 colunas confirmadas via Schema::hasColumn |
| CompanyServiceTypeTest | GREEN (1 test, 1 assertion) | service_type persiste corretamente |
| AdminFechamentoControllerTest | RED (7 falhas, 1 passa) | **Esperado** — controller não existe até Plan 02 |

Verificação de migrate:status:
```
2026_05_19_100001_add_service_fields_to_companies ......... [15] Ran
```

## Deviations from Plan

None — plano executado exatamente como especificado.

O teste `test_nao_admin_recebe_403` passa imediatamente (1 de 8) porque o middleware `EnsureUserHasRole` retorna 403 mesmo sem a rota existir — comportamento correto e esperado.

## Known Stubs

Nenhum stub de dados em produção. Os arquivos de teste são intencionalmente RED para `AdminFechamentoControllerTest` — resolvidos no Plan 02.

## Threat Flags

Nenhuma nova superfície de segurança introduzida. Os campos adicionados ao `$fillable` são dados de configuração de contrato (service_type, contract_start, contract_end, additional_service) — sem exposição de dados sensíveis além do que já estava no fillable por design do projeto.

## Commit

- `327d914` — feat(05-01): migration + Company model + test stubs (Wave 1)

## Self-Check: PASSED

- [x] `database/migrations/2026_05_19_100001_add_service_fields_to_companies.php` — EXISTS
- [x] `app/Models/Company.php` — MODIFIED (service_type em fillable, casts, logOnly)
- [x] `tests/Feature/AdminFechamentoControllerTest.php` — EXISTS
- [x] `tests/Unit/CompanyServiceTypeTest.php` — EXISTS
- [x] `tests/Feature/FechamentoMigrationTest.php` — EXISTS
- [x] Commit `327d914` — EXISTS
- [x] FechamentoMigrationTest — GREEN
- [x] CompanyServiceTypeTest — GREEN
- [x] AdminFechamentoControllerTest — RED (esperado)
