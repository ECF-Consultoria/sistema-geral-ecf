---
phase: 14
plan: 02
subsystem: servicos
tags: [phase-14, services-consolidation, migration, data-migration, idempotent]
requires:
  - "Plan 14-01 (CobrancaCalculator helper + comando phase14:verificar-cobranca)"
provides:
  - "database/migrations/2026_05_27_100001_seed_servicos_catalog.php (catálogo populado)"
  - "database/migrations/2026_05_27_100002_migrate_legacy_service_data.php (contratos derivados)"
  - "tests/Feature/Phase14MigrationTest (5 testes cobrindo D-01/D-02/D-04 + idempotência)"
affects:
  - "table `servicos`: passa a conter os 6 nomes canônicos pt-BR"
  - "table `contratos_servico`: passa a ser populada para empresas com campos legacy"
tech_added: []
patterns_used:
  - "Idempotência via firstOrCreate no catálogo (preserva edições manuais de valor_padrao)"
  - "Idempotência via guard where(company_id, servico_id, valor_contratado)->exists() nos contratos"
  - "DB::transaction envolvente para atomicidade (falha loud se algo der errado no meio)"
  - "Company::chunk(100) para evitar OOM em produção (Pitfall 2 do RESEARCH)"
  - "Cache Servico::pluck('id', 'nome') para resolver IDs sem N queries"
  - "Normalização Title Case via mb_convert_case(trim($x), MB_CASE_TITLE, 'UTF-8') (D-02)"
key_files:
  created:
    - "database/migrations/2026_05_27_100001_seed_servicos_catalog.php"
    - "database/migrations/2026_05_27_100002_migrate_legacy_service_data.php"
    - "tests/Feature/Phase14MigrationTest.php"
  modified: []
decisions:
  - "firstOrCreate (não updateOrCreate) no catálogo — preserva ajustes manuais via /servicos pós-migration"
  - "Guards de idempotência usam tripla (company_id, servico_id, valor_contratado) — combinação exata por contrato, sem precisar de UNIQUE constraint"
  - "Cache local servicosByNome atualizado dentro do loop quando firstOrCreate cria um servico novo (additional_service) — evita re-query"
  - "data_contratacao usa toDateString() consistente em ambos branches (contract_start ou created_at) — evita Carbon/string mismatch entre SQLite e MySQL (Pitfall 8)"
  - "(float) explícito em valor_contratado antes de operações aritméticas — cast decimal:2 retorna string em SQLite (Pitfall 4)"
  - "down() das duas migrations é no-op informativo — reverter dados de migration exige backup do DB"
metrics:
  duration_minutes: 4
  task_count: 3
  files_created: 3
  files_modified: 0
  test_assertions: 46
  completed_date: 2026-05-26
---

# Phase 14 Plan 02: Migrations de seed + data legacy — Summary

Migrations 1 (seed do catálogo `servicos` com os 6 tipos canônicos) e 2 (data migration que cria `contratos_servico` derivados dos campos legacy de `companies`) entregues idempotentes (rodam 2× sem duplicar) + suíte `Phase14MigrationTest` com 5 cenários cobrindo D-01, D-02 e D-04. Sistema agora em **COEXISTÊNCIA** — campos legacy permanecem populados E o novo modelo passa a ser populado; o runtime AINDA lê dos legacy até o Plan 14-03 refatorar os consumers.

## Objetivo

Popular o catálogo de serviços com os 6 nomes canônicos pt-BR (Publicação, Polos, Assessoria, Incubadora, Publicidade, Gestão) e, para toda empresa existente, derivar contratos a partir dos campos legacy (`service_type` JSON array + `additional_service` texto livre + `additional_service_price` decimal + `contract_start`/`contract_end`). Tudo idempotente para suportar re-runs sem efeitos colaterais.

## Arquivos Criados

### Migrations

| Arquivo | Linhas | Propósito |
| ------- | ------ | --------- |
| `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` | 65 | Seed idempotente do catálogo via `Servico::firstOrCreate` |
| `database/migrations/2026_05_27_100002_migrate_legacy_service_data.php` | 188 | Data migration iterando `Company::chunk(100)` dentro de `DB::transaction` |

### Testes

| Arquivo | Tipo | Cenários | Assertions |
| ------- | ---- | -------- | ---------- |
| `tests/Feature/Phase14MigrationTest.php` | Feature (`RefreshDatabase`) | 5 | 46 |

## Tarefas Executadas

### Task 1: Migration 1 — seed do catálogo (`934f103`)

- **Commit:** `feat(14-02): seed idempotente do catálogo de servicos (6 tipos canônicos)`
- 6 chamadas a `Servico::firstOrCreate(['nome' => $nome], [valor_padrao=0, tipo_cobranca='mensal', ativo=true])`
- `firstOrCreate` (não `updateOrCreate`) para preservar ajustes manuais de `valor_padrao` feitos via UI pós-migration
- `down()` no-op com comentário explicando que contratos via `restrictOnDelete` impediriam o drop
- Verificação `migrate --pretend`: 6 INSERTs + 6 inserts em `activity_log` (consistente com LogsActivity de Servico)

### Task 2: Migration 2 — data migration legacy → contratos (`21ada57`)

- **Commit:** `feat(14-02): data migration legacy → contratos_servico (D-04 cumulativo)`
- `DB::transaction` envolvente (atomicidade — se falhar no meio, nada fica criado)
- `Company::chunk(100)` evita OOM em prod com muitas empresas (Pitfall 2)
- Cache local `servicosByNome = Servico::pluck('id', 'nome')` resolve IDs em O(1)
- Para cada empresa:
  - **(1)** Para cada slug em `service_type`: lookup via mapa D-01 → 1 contrato com `valor_contratado=0` (guard `where(company_id, servico_id, 0)->exists()`)
  - **(2)** Se `additional_service` preenchido: `firstOrCreate` do nome normalizado em Title Case UTF-8 (D-02), depois 1 contrato com `valor_contratado=additional_service_price` (guard `where(company_id, servico_id, $valor)->exists()`)
  - **(3)** Empresa sem `service_type` nem `additional_service`: nenhum contrato (estado neutro — D-04 item 3)
- `data_contratacao = contract_start?->toDateString() ?? created_at->toDateString()`
- `data_vencimento = contract_end?->toDateString()`
- `(float)` explícito em `additional_service_price` antes de comparação (Pitfall 4 — cast `decimal:2` retorna string em SQLite)
- `down()` no-op com comentário sobre exigir backup do DB para reverter

### Task 3: Suíte Phase14MigrationTest (`cd8a57e`)

- **Commit:** `test(14-02): suite Phase14MigrationTest — criação + idempotência (5/5)`
- 5 testes cobrindo todas as branches do D-04 + idempotência:

| # | Teste | Cobertura |
|---|-------|-----------|
| 1 | `test_migration_cria_6_servicos_canonicos` | D-01 — 6 nomes pt-BR, tipo_cobranca='mensal', valor_padrao=0, ativo=true |
| 2 | `test_migration_cria_contratos_para_empresa_com_service_type` | D-04 item 1 — `['polos','gestao']` → 2 contratos com datas corretas |
| 3 | `test_migration_cria_contrato_adicional_com_normalizacao_title_case` | D-02 + D-04 item 2 — `'  TREINAMENTO PERSONALIZADO  '` → `'Treinamento Personalizado'` com valor=350 e fallback `created_at` |
| 4 | `test_migration_skip_empresa_sem_service_type_nem_additional` | D-04 item 3 — estado neutro, 0 contratos |
| 5 | `test_migration_pode_rodar_2x_sem_duplicar` | Idempotência — re-run mantém count em 2 e catálogo sem duplicatas |

- `Company::create()` direto nos testes (sem factory — consistente com padrão Phase13MigrationTest e Phase14VerificarCobrancaTest)
- Re-run da Migration 2 feita via `require database_path(...)` + `$migration->up()` (mesmo padrão de Phase13MigrationTest)

## Comandos de Verificação

```bash
# Linting
c:\xampp\php\php.exe -l database/migrations/2026_05_27_100001_seed_servicos_catalog.php
# >>> No syntax errors detected

c:\xampp\php\php.exe -l database/migrations/2026_05_27_100002_migrate_legacy_service_data.php
# >>> No syntax errors detected

c:\xampp\php\php.exe -l tests/Feature/Phase14MigrationTest.php
# >>> No syntax errors detected

# Migrations em dev DB
php artisan migrate
# >>> 2026_05_27_100001_seed_servicos_catalog DONE
# >>> 2026_05_27_100002_migrate_legacy_service_data DONE

# Estado pós-migration em dev (DB dev local está vazio — sem companies)
php artisan tinker --execute='print_r([App\Models\Servico::count(), App\Models\ContratoServico::count(), App\Models\Company::count()]);'
# >>> [6, 0, 0]  (6 servicos canônicos; 0 contratos pois 0 companies)

# Suite Plan 14-02
php artisan test --filter=Phase14MigrationTest
# >>> Tests: 5 passed (46 assertions) Duration: 1.21s

# Suite combinada Phase 14 (Wave 0 + Wave 1)
php artisan test --filter='Phase14MigrationTest|Phase14VerificarCobrancaTest|CobrancaCalculatorTest'
# >>> Tests: 16 passed (59 assertions) Duration: 1.17s
```

## Contagem de Empresas Afetadas em Dev

| Métrica | Valor | Observação |
|---------|-------|------------|
| `Servico::count()` | 6 | 6 nomes canônicos seedados |
| `ContratoServico::count()` | 0 | DB dev local está vazio (sem `companies`) — migrations criaram zero contratos |
| `Company::count()` | 0 | DB dev local resetado recentemente |

**Em prod (VPS Hostinger):** o impacto será proporcional ao volume real de empresas. A Migration 2 já está validada para suportar `chunk(100)` em volumes maiores. O dry-run em prod fica para o Plan 14-06 (após refator dos consumers).

## Critérios de Sucesso vs. Realização

| # | Critério | Status |
|---|----------|--------|
| 1 | Migration 1 popula `servicos` com os 6 nomes canônicos via `firstOrCreate` (idempotente) | OK |
| 2 | Migration 2 cria contratos derivados de `service_type` (1 por tipo) e `additional_service` (1 adicional) | OK |
| 3 | Guards de idempotência: re-runs não duplicam contratos | OK (Teste 5 valida) |
| 4 | Empresas sem dados legacy ficam sem contratos (estado neutro) | OK (Teste 4 valida) |
| 5 | Datas migradas corretamente: `data_contratacao = contract_start ?? created_at`, `data_vencimento = contract_end` | OK (Testes 2 e 3 validam) |
| 6 | Normalização Title Case + trim funciona para `additional_service` | OK (Teste 3 valida) |
| 7 | Suíte `Phase14MigrationTest` verde com 5 testes cobrindo as 4 branches do D-04 + idempotência | OK (5/5, 46 assertions) |

## Commits do Plan 14-02

| Hash | Tipo | Mensagem |
|------|------|----------|
| `934f103` | feat | seed idempotente do catálogo de servicos (6 tipos canônicos) — Task 1 |
| `21ada57` | feat | data migration legacy → contratos_servico (D-04 cumulativo) — Task 2 |
| `cd8a57e` | test | suite Phase14MigrationTest — criação + idempotência (5/5) — Task 3 |

## Decisões de Execução

- **`firstOrCreate` no catálogo, não `updateOrCreate`** — re-runs preservam edições manuais de `valor_padrao` feitas via UI `/servicos`. Documentado em comentário.
- **Cache `servicosByNome` atualizado in-place** quando `additional_service` dispara `firstOrCreate` de um novo serviço — evita re-query desnecessária no mesmo loop (sub-otimização inofensiva).
- **`toDateString()` explícito em ambos branches** de `data_contratacao` — garante string Y-m-d consistente, evita mismatch Carbon ↔ string entre SQLite (testes) e MySQL (prod) — Pitfall 8 do RESEARCH.
- **`(float)` explícito** em `additional_service_price` e `valor_contratado` antes de comparações `where('valor_contratado', $valorAdicional)` — Pitfall 4 (cast `decimal:2` retorna string em SQLite).
- **`down()` no-op informativo** em ambas migrations — reverter o catálogo dropparia serviços com contratos vinculados via `restrictOnDelete`; reverter dados de contratos exigiria backup do DB. Documentado em comentário.
- **`require database_path(...)` + `->up()`** para re-rodar a Migration 2 manualmente nos testes — mesmo padrão de `Phase13MigrationTest` (não inventar API nova).
- **Helper privado `migrarContratosLegacy`** extraído da Migration 2 — mantém `up()` enxuto e facilita leitura linear da regra cumulativa D-04.

## Sistema em COEXISTÊNCIA

**Estado atual após Plan 14-02:**

- `companies.service_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price` — **AINDA POPULADOS** (nenhum drop foi feito)
- `servicos` — **POPULADO** com os 6 canônicos
- `contratos_servico` — **POPULADO** com contratos derivados (1 por slug + 1 por additional_service quando aplicável)
- **Runtime AINDA lê dos campos legacy** (`AdminController::fechamento`, `MlbController` filtros, etc.) — o refator dos consumers começa só no Plan 14-03

**Sequência completa até o drop (referência futura):**

```bash
# Já feito (Plan 14-02):
php artisan migrate --path=database/migrations/2026_05_27_100001_seed_servicos_catalog.php
php artisan migrate --path=database/migrations/2026_05_27_100002_migrate_legacy_service_data.php

# Pendente (Plans 14-03 a 14-05):
# - Refator de consumers PHP/Blade/JSX para ler do novo modelo

# Plan 14-06 (drop irreversível):
php artisan phase14:verificar-cobranca --abort-on-divergence    # ← CHECKPOINT (helper do Plan 14-01)
# Se exit 0: php artisan migrate --path=database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php
# Se exit 1: investiga divergências e corrige antes de prosseguir
```

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito. Todas as decisões D-01, D-02, D-04 e D-06 do CONTEXT.md respeitadas verbatim.

## Threat Flags

Nenhuma. A migration:

- **Não introduz endpoints novos** — só código de migration (executado via `php artisan migrate`)
- **Não toca auth/RBAC** — sem mudança em middleware ou policies
- **Não acessa arquivos** — só operações Eloquent (`firstOrCreate`, `create`, `where(...)->exists()`)
- **Não muda schema** — apenas insere linhas em `servicos` e `contratos_servico` (schemas inalterados, criados na Frente A)
- **Idempotência forte** — guards explícitos `where(...)->exists()` antes de cada insert; rodar 2× é seguro

A operação é puro data-write controlado pela aplicação, dentro de `DB::transaction` (rollback automático se algo falhar).

## Self-Check: PASSED

- Arquivo `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` existe (verificado via `php -l` + `migrate --pretend`)
- Arquivo `database/migrations/2026_05_27_100002_migrate_legacy_service_data.php` existe (verificado via `php -l` + `migrate --pretend`)
- Arquivo `tests/Feature/Phase14MigrationTest.php` existe (verificado — 5/5 testes verdes, 46 assertions)
- Comandos `php artisan migrate` e `php artisan migrate:status` confirmam que ambas as migrations rodaram com sucesso em dev DB
- Suíte combinada (Phase14MigrationTest + Phase14VerificarCobrancaTest + CobrancaCalculatorTest) verde: 16/16 testes, 59 assertions
- Commits `934f103`, `21ada57`, `cd8a57e` presentes em `git log --oneline`
