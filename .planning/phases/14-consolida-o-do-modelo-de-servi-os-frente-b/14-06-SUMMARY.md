---
phase: 14
plan: 06
subsystem: backend
tags: [phase-14, services-consolidation, backend-cleanup, migration, drop-legacy]
completed_date: 2026-05-26
requires:
  - "Plan 14-05 fechado"
provides:
  - "Migration 3 aplicada localmente: drop das 6 colunas legacy de companies"
  - "Company.php sem fillable/casts/logOnly legacy; labelFromTypes removido"
  - "AdminController/ComercialController/MlbController/CompanyController/job sem chaves legacy nos payloads"
  - "EmpresaCadastradaNotification aceita somente array de serviços"
  - "phase14:verificar-cobranca tolera schema pós-drop"
verification:
  preflight: "php artisan phase14:verificar-cobranca --abort-on-divergence -> 0 empresas, 0 divergências"
  migration: "php artisan migrate --path=database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php -> DONE"
  schema: "Schema::hasColumn('companies','service_type')=0; additional_service_price=0"
  focused_tests: "Phase14BladeRefactorTest + Phase14FechamentoUiTest + Phase14MlbControllerFiltroTest -> 9 passed / 101 assertions"
  app_grep: "rg service_type|contract_type|contract_start|contract_end|additional_service|additional_service_price app/ -> 0 matches"
notes:
  - "Suíte combinada antiga ainda contém testes pré-drop que criam/esperam campos legacy; registrado em deferred-items.md."
---

# Phase 14 Plan 06: Drop Legacy + Cleanup Backend

Plan 14-06 executado no banco local XAMPP após o gate de cobrança retornar 0 divergências. O backend não expõe nem lê mais as 6 colunas antigas de `companies`; a migration de drop foi aplicada e o comando `phase14:verificar-cobranca` continua funcional em schema pós-drop.

## Arquivos

- `database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php` criado.
- `app/Models/Company.php` limpo de fillable/casts/logOnly legacy e `labelFromTypes`.
- `app/Http/Controllers/AdminController.php` remove payloads e validações legacy; mantém `updateFechamento()` como no-op compat.
- `app/Http/Controllers/ComercialController.php` remove reconstrução compat e passa `servicos_disponiveis`.
- `app/Http/Controllers/MlbController.php` e `app/Http/Controllers/CompanyController.php` deixam de selecionar coluna legacy nas empresas pendentes.
- `app/Jobs/EnviarRelatorioFechamentoJob.php` remove chaves legacy das vinculadas.
- `app/Notifications/EmpresaCadastradaNotification.php` passa a aceitar somente array de serviços.
- `app/Console/Commands/Phase14VerificarCobranca.php` continua útil pós-drop.

## Verificação

```bash
C:\xampp\php\php.exe artisan phase14:verificar-cobranca --abort-on-divergence
# [Phase14] Verificando cobrança em 0 empresa(s)...
# [Phase14] Todas as 0 empresas conferem (0 divergências).

C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php
# DONE

C:\xampp\php\php.exe artisan tinker --execute="echo (int) Schema::hasColumn('companies', 'service_type'); echo PHP_EOL; echo (int) Schema::hasColumn('companies', 'additional_service_price');"
# 0
# 0

C:\xampp\php\php.exe artisan test --filter='Phase14FechamentoUiTest|Phase14BladeRefactorTest|Phase14MlbControllerFiltroTest'
# Tests: 9 passed (101 assertions)
```

## Débitos

`php artisan test --filter='Phase14|CobrancaCalculator|ComercialControllerHelper'` não está verde porque há testes antigos de migração/golden que ainda assumem existência dos campos dropados e casos de ambiente local sem permissão de log/rede. Isso ficou registrado em `deferred-items.md` para a etapa de regression/gates.

Próximo: Plan 14-07, cleanup dos 5 JSX consumers.
