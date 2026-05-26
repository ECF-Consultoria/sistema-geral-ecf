---
phase: 14
plan: 01
subsystem: servicos
tags: [phase-14, services-consolidation, prep, helper, artisan-command, tdd]
requires: []
provides:
  - "App\\Support\\CobrancaCalculator"
  - "phase14:verificar-cobranca (Artisan command)"
affects: []
tech_added: []
patterns_used:
  - "Helper estático puro testável sem container Laravel"
  - "TDD RED → GREEN por task (commits separados)"
  - "Eager loading (with[...]) + chunk(100) para evitar N+1"
  - "Tolerância R$ 0,01 em comparações decimais (abs(a-b) > 0.01)"
key_files:
  created:
    - "app/Support/CobrancaCalculator.php"
    - "app/Console/Commands/Phase14VerificarCobranca.php"
    - "tests/Unit/CobrancaCalculatorTest.php"
    - "tests/Feature/Phase14VerificarCobrancaTest.php"
  modified: []
decisions:
  - "Helper retorna float sempre (nunca null) — semântica 'null quando vazio' delegada ao caller (será aplicada no Plan 14-03)"
  - "Tabela FAIXAS duplicada verbatim no comando (não compartilhada com AdminController) — duplicação intencional/temporária pois o comando é deletado após Phase 14"
  - "Testes do helper usam objetos anônimos (sem RefreshDatabase) — pure unit per RESEARCH §3 Pitfall 4"
metrics:
  duration_minutes: 5
  task_count: 2
  files_created: 4
  files_modified: 0
  test_assertions: 13
  completed_date: 2026-05-26
---

# Phase 14 Plan 01: Pre-flight de verificação de cobrança — Summary

Helper puro `CobrancaCalculator` + comando Artisan `phase14:verificar-cobranca` criados via TDD (RED→GREEN) para garantir o invariante SVC-02 (fatura idêntica até R$ 0,01) antes do drop irreversível das colunas legacy no Plan 14-06.

## Objetivo

Criar a infraestrutura de verificação que será consumida pelo Plan 14-03 (refator dos 3 call-sites de cálculo em `AdminController`) e pelo Plan 14-06 (drop das colunas legacy). Sem nenhuma modificação em código de produção — puro prep work.

## Arquivos Criados

### Código de produção

| Arquivo | Linhas | Propósito |
| ------- | ------ | --------- |
| `app/Support/CobrancaCalculator.php` | 78 | Helper estático puro com `legacy()` e `novo()` |
| `app/Console/Commands/Phase14VerificarCobranca.php` | 148 | Comando Artisan que itera empresas comparando os dois cálculos |

### Testes

| Arquivo | Tipo | Cenários | Assertions |
| ------- | ---- | -------- | ---------- |
| `tests/Unit/CobrancaCalculatorTest.php` | Unit (sem DB) | 8 | 8 |
| `tests/Feature/Phase14VerificarCobrancaTest.php` | Feature (RefreshDatabase) | 3 | 5 |
| **Total** |  | **11** | **13** |

## Tarefas Executadas

### Task 1: CobrancaCalculator helper + suíte de testes unitários (TDD)

**RED commit** (`f43ae51`): `test(14-01): add failing tests for CobrancaCalculator helper`
- 8 cenários cobrindo todas as branches de `legacy()` e `novo()`
- Objetos anônimos para contratos (sem DB)
- Confirmou RED: 8 testes falham com `Class "App\Support\CobrancaCalculator" not found`

**GREEN commit** (`7ec63e1`): `feat(14-01): implementa CobrancaCalculator helper estatico puro`
- `legacy(?array $faixaData, ?float $additionalServicePrice): float` — fórmula atual de AdminController
- `novo(?array $faixaData, iterable $contratos): float` — faixa + SUM contratos ativos mensais
- Aceita `iterable` em `novo()` → Collection eager-loaded em prod OU array de objetos em testes
- Filtra: `ativo === true && servico && servico->tipo_cobranca === Servico::TIPO_MENSAL`
- 8/8 testes verdes

### Task 2: Comando phase14:verificar-cobranca + teste de integração (TDD)

**RED commit** (`ff93005`): `test(14-01): add failing tests for phase14:verificar-cobranca command`
- 3 cenários: zero divergência / aborta com flag / sem flag retorna 0
- `RefreshDatabase` + `Company::create` direto (sem CompanyFactory que não existe no projeto)
- Confirmou RED: 3 testes falham com `CommandNotFoundException`

**GREEN commit** (`99adfb8`): `feat(14-01): implementa comando phase14:verificar-cobranca`
- Signature: `phase14:verificar-cobranca {--abort-on-divergence : Aborta com exit code 1 se houver divergência}`
- Itera todas empresas via `chunk(100)` + eager loading (`with(['contratosServico' => fn => active->with('servico')])`)
- Para cada empresa: calcula `faixaData` via FAIXAS interna (duplicada de `AdminController::FAIXAS`), faturamento via SUM `adman_metrics.revenue` últimos 30 dias, compara `CobrancaCalculator::legacy` vs `::novo` com tolerância R$ 0,01
- Logs prefixados `[Phase14]` consistentes com convenção do projeto
- 3/3 testes verdes

## Comandos de Verificação

```bash
# Linting
php -l app/Support/CobrancaCalculator.php            # No syntax errors detected
php -l app/Console/Commands/Phase14VerificarCobranca.php  # No syntax errors detected

# Suite Plan 14-01
php artisan test --filter=CobrancaCalculatorTest          # 8/8 passed (8 assertions)
php artisan test --filter=Phase14VerificarCobrancaTest    # 3/3 passed (5 assertions)
php artisan test --filter='CobrancaCalculatorTest|Phase14VerificarCobrancaTest'  # 11/11 passed (13 assertions)

# Comando registrado
php artisan list | findstr phase14
# >>> phase14:verificar-cobranca    Phase 14: compara cálculo de cobrança legacy vs novo...
```

## Critérios de Sucesso vs. Realização

| # | Critério | Status |
| - | -------- | ------ |
| 1 | `CobrancaCalculator::legacy()` e `::novo()` retornam valores conforme fórmulas D-03/D-10 | OK |
| 2 | Comando `phase14:verificar-cobranca` itera empresas e reporta divergências | OK |
| 3 | Flag `--abort-on-divergence` força exit 1; sem flag retorna 0 mesmo divergente | OK |
| 4 | >= 11 assertions verdes entre as duas suítes (8 unit + 3 feature) | OK (13 assertions) |
| 5 | Zero impacto em rotas, views, JSX ou queries de produção | OK |

## Commits do Plan 14-01

| Hash | Tipo | Mensagem |
| ---- | ---- | -------- |
| `f43ae51` | test | add failing tests for CobrancaCalculator helper (RED Task 1) |
| `7ec63e1` | feat | implementa CobrancaCalculator helper estatico puro (GREEN Task 1) |
| `ff93005` | test | add failing tests for phase14:verificar-cobranca command (RED Task 2) |
| `99adfb8` | feat | implementa comando phase14:verificar-cobranca (GREEN Task 2) |

## Decisões de Execução

- **`novo()` aceita `iterable`** (não `Collection` estrita): permite array de objetos anônimos em testes unit (Pitfall 4 do RESEARCH) e Collection eager-loaded em produção (sem segundo método/sobrecarga).
- **Helper retorna `float`, não `?float`**: a semântica legacy de "null quando ambos zerados" é responsabilidade do caller. O caller (no Plan 14-03) decide via `$cobranca ?: null` quando converter para null no array Inertia. Mantém o helper trivial.
- **FAIXAS duplicada no comando**: extrair para um `FaixaCalculator` compartilhado teria valor de longo prazo, mas o comando é descartável (removido pós-Phase 14). Duplicação aceita e documentada com comentário.
- **`Company::create` direto nos testes** (não factory): segue padrão consagrado do projeto (`DevControllerTest`, `AdminFechamentoControllerTest`) — não há `CompanyFactory` em `database/factories/`.

## Pronto Para Consumo

Helper `CobrancaCalculator::novo()` e `::legacy()` estão prontos para serem chamados pelo **Plan 14-03** (refator dos 3 call-sites de cálculo em `AdminController::fechamento`/`gerarRelatorio`/`gerarRelatorioGeral`).

Comando `phase14:verificar-cobranca` está pronto para ser o **checkpoint humano do Plan 14-06** (antes do drop das colunas legacy):

```bash
# Sequência de execução em prod (Plan 14-06):
php artisan migrate --path=database/migrations/2026_05_27_100001_seed_servicos_catalog.php
php artisan migrate --path=database/migrations/2026_05_27_100002_migrate_legacy_service_data.php
php artisan phase14:verificar-cobranca --abort-on-divergence    # <-- CHECKPOINT
# Se exit 0: php artisan migrate --path=database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php
# Se exit 1: investiga divergências e corrige antes de prosseguir
```

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito.

## Threat Flags

Nenhuma. O plano é puro prep work; não introduz endpoints novos, auth paths, file access ou mudanças de schema. O comando Artisan só lê do DB e não muta estado (apenas reporta).

## Self-Check: PASSED

- Arquivo `app/Support/CobrancaCalculator.php` existe (verificado via `php -l`)
- Arquivo `app/Console/Commands/Phase14VerificarCobranca.php` existe (verificado via `php -l`)
- Arquivo `tests/Unit/CobrancaCalculatorTest.php` existe (8/8 verdes)
- Arquivo `tests/Feature/Phase14VerificarCobrancaTest.php` existe (3/3 verdes)
- Comando `phase14:verificar-cobranca` aparece em `php artisan list`
- Commits `f43ae51`, `7ec63e1`, `ff93005`, `99adfb8` presentes em `git log`
