---
phase: 60-base-multi-fonte-backend-ml-adman-unificado
plan: 04
subsystem: metrics-multifonte
tags: [unified-metrics, integration, baseline, tdd]
requires:
  - ADR DATA-04 (Plan 60-01) — precedência ML vs Adman + 4 valores de source
  - Plan 60-02 — contract MetricsProvider + DTO UnifiedMetricsDto + AdmanMetricsProvider
  - Plan 60-03 — MlMetricsProvider + MetricsProviderFactory
provides:
  - "Orquestrador App\\Services\\Metrics\\UnifiedMetricsService (fusão campo-a-campo ADR DATA-04)"
  - "Suite de testes explícita 3 casos (só-Adman / só-ML / ambos) + none + divergência TACOS"
  - "Baseline regressão zero: rotas legadas que consultam adman_metrics continuam 200"
  - "Phase 60 CONCLUÍDA — todos os 4 Success Criteria do ROADMAP atendidos com prova executável"
affects:
  - app/Services/Metrics/UnifiedMetricsService.php
  - tests/Feature/Phase60/UnifiedMetricsServiceTest.php
  - tests/Feature/Phase60/BaselineRegressionTest.php
tech-stack:
  added: []
  patterns:
    - "Match expression para dispatch de caso (ADR DATA-04 3 casos + none)"
    - "Fusão de DTOs imutáveis via null-coalesce (?? fallback) na tabela de precedência"
    - "Log::spy() + shouldHaveReceived/shouldNotHaveReceived para asserção de log divergência"
    - "Route::has() com $this->fail() explícito (sem markTestSkipped) em baseline test"
    - "TDD RED → GREEN por task com commit atômico"
key-files:
  created:
    - app/Services/Metrics/UnifiedMetricsService.php
    - tests/Feature/Phase60/UnifiedMetricsServiceTest.php
    - tests/Feature/Phase60/BaselineRegressionTest.php
    - .planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/60-04-SUMMARY.md
    - .planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/PHASE-SUMMARY.md
  modified: []
decisions:
  - "UnifiedMetricsService.readForCompany() é o método canônico; for() é alias público pra paridade com o texto do PLAN"
  - "Match expression sobre `caseFor()` da factory dispatcha os 4 casos — dispatcher explícito e exaustivo"
  - "readAmbos() desagrega providers por `name()` (defensivo — se ordem do factory mudar, ainda funciona)"
  - "Guarda defensiva no caso 'ambos': se um provider retornar null, degrada para o outro sem vazar exception"
  - "Log de divergência TACOS emitido ANTES do merge para capturar valores originais dos 2 providers (auditoria — T-60-04-04)"
  - "Threshold TACOS = 5% relativo (`abs(ml-adman)/max(adman,0.01) > 0.05`) conforme mission — divisão por zero mitigada com max(x,0.01)"
  - "BaselineRegressionTest usa Route::has() + \$this->fail() em vez de markTestSkipped — skip silencioso mascararia SC #4"
  - "portfolio.own escolhida em vez de portfolio.show — não requer parâmetro {user} e é o path canonical do usuário logado"
metrics:
  duration: "~50 min"
  completed: 2026-07-07
  tests_added: 17
  tests_passing: 17
  phase60_total_passing: 46
  regressao: 0
---

# Phase 60 Plan 04: UnifiedMetricsService + Baseline Regressão — Summary

Fecha a Phase 60. Entrega o orquestrador multi-fonte que aplica a fusão
campo-a-campo do ADR DATA-04 e prova zero regressão nos consumidores
legados via smoke test dedicado. Todos os 4 Success Criteria do ROADMAP
têm agora prova executável.

## O que foi entregue

### 1. `App\Services\Metrics\UnifiedMetricsService`

Orquestrador com constructor DI (`MetricsProviderFactory`) e 3 métodos públicos:

- **`readForCompany(Company, Carbon, Carbon): UnifiedMetricsDto`** —
  método canônico. `match($case)` dispatcha os 4 casos:
  - `'none'`     → DTO sentinela com todos numéricos `null`, `source='none'`.
  - `'so-adman'` → DTO do `AdmanMetricsProvider` direto (`source='adman'`).
  - `'so-ml'`    → DTO do `MlMetricsProvider` direto (`source='ml'`).
  - `'ambos'`    → chama `readAmbos()` que consulta ambos providers, aplica
    fusão via `mergeFields()` e emite `source='unified'`.
- **`for(...)`** — alias público conveniente (paridade nominal com o texto do PLAN).
- **`available(Company): array`** — passa direto para `factory->forCompany()`;
  útil para dashboards Phase 61 decidirem qual badge exibir sem exigir
  `readForCompany()` completo.

### 2. Fusão campo-a-campo (`mergeFields`)

Espelha a tabela do ADR DATA-04:

| Campo               | Fonte de origem                                             |
| ------------------- | ----------------------------------------------------------- |
| `revenue`           | ML dita; `??` fallback pro Adman se ML null                |
| `ad_spend`          | ML dita; `??` fallback pro Adman                            |
| `sold_quantity`     | ML dita; `??` fallback pro Adman                            |
| `sales_fee`         | ML dita; `??` fallback pro Adman                            |
| `orders_count`      | ML dita; `??` fallback pro Adman                            |
| `tacos`             | ML dita; `??` fallback pro Adman                            |
| `net_billing`       | **Adman canonical** (ML não expõe)                          |
| `taxes`             | **Adman canonical**                                         |
| `shipping_cost`     | **Adman canonical**                                         |
| `product_cost`      | **Adman canonical**                                         |
| `contribution_margin` | **Adman canonical**                                       |
| `acos`              | **ML canonical** (Adman não expõe direto)                  |
| `roas`              | **ML canonical**                                            |
| `clicks`            | **ML canonical**                                            |
| `impressions`       | **ML canonical**                                            |

### 3. Log de divergência TACOS

`logTacosDivergenteSeExcede()` calcula
`abs(ml.tacos - adman.tacos) / max(adman.tacos, 0.01)`. Se > 5%:
`Log::warning('[UnifiedMetrics] TACOS divergente', ['company_id', 'period_from', 'period_to', 'ml', 'adman', 'delta_pct'])`.
Nunca falha o cálculo. Endereça memory `project_adman_data_sources`
(discrepância TACOS ML vs Adman relatada).

Contexto sanitizado — sem token, email, telefone ou payload raw
(mitigação T-60-04-01: Information Disclosure).

### 4. `UnifiedMetricsServiceTest` — 11 testes

Arquivo: `tests/Feature/Phase60/UnifiedMetricsServiceTest.php`.
Namespace `Tests\Feature\Phase60`, `use RefreshDatabase`, mock
`MercadoLivreService` via `Mockery` + `$this->app->instance()`.

| # | Nome | Foco |
|---|------|------|
| T1 | `test_caso_so_adman_retorna_dto_com_source_adman` | fixture Adman, sem mlToken; DTO source='adman' + ML-only null |
| T2 | `test_caso_so_ml_retorna_dto_com_source_ml` | mlToken active, sem adman_account_id; mock ML; DTO source='ml' + Adman-only null |
| T3 | `test_caso_ambos_retorna_dto_com_source_unified` | Adman fixtures + mock ML; ML dita revenue/ad_spend; Adman enriquece net_billing/taxes/etc |
| T4 | `test_caso_ambos_ml_dita_revenue_sobre_adman` | Adman revenue=100, ML revenue=200 → DTO.revenue=200 |
| T5 | `test_caso_ambos_adman_dita_net_billing_sobre_ml` | Adman net_billing=500 → DTO.net_billing=500 |
| T6 | `test_caso_ambos_ml_com_campo_null_cai_pro_adman` | ML.sales_fee null + Adman.sales_fee=50 → DTO.sales_fee=50 |
| T7 | `test_caso_ambos_tacos_divergente_gera_log_warning` | Log::spy(); ML=5.0, Adman=8.0 (delta 37.5%) → shouldHaveReceived warning |
| T8 | `test_caso_ambos_tacos_dentro_de_5pct_nao_gera_log` | ML=5.0, Adman=5.2 (delta 3.85%) → shouldNotHaveReceived |
| T9 | `test_caso_none_retorna_dto_source_none_todos_campos_null` | empresa sem integração → DTO source='none' + tudo null |
| T10 | `test_readforcompany_nunca_lanca_exception_com_provider_com_erro` | mock ML throws → DTO com nulls, sem exception |
| T11 | `test_source_do_dto_por_caso_exato` | dict-driven: `{ambos→unified, so-ml→ml, so-adman→adman, none→none}` |

**Resultado**: 11/11 verdes (74 assertions).

### 5. `BaselineRegressionTest` — 6 testes

Arquivo: `tests/Feature/Phase60/BaselineRegressionTest.php`.
Setup: admin + Company com `adman_account_id` + 1 row AdmanMetric no D-1.

| # | Nome | Foco |
|---|------|------|
| T1 | `test_route_dashboard_ainda_retorna_200` | `route('dashboard')` GET → assertOk |
| T2 | `test_route_admin_financeiro_ainda_retorna_200` | `route('admin.financeiro')` GET → assertOk |
| T3 | `test_route_companies_show_ainda_retorna_200` | `route('companies.show', $company)` GET → assertOk |
| T4 | `test_route_portfolio_ainda_retorna_200` | `route('portfolio.own')` GET → assertOk (nome real validado via route:list) |
| T5 | `test_query_admanmetric_agregada_por_company_ainda_funciona` | SUM(revenue) GROUP BY company_id — schema íntegro (esperado 600) |
| T6 | `test_admanservice_legado_pode_conviver_com_admanmetricsprovider_novo` | `app()->make()` resolve ambos sem interferência |

**Rotas validadas via** `php artisan route:list --json > scratchpad/routes.json`.
Todas as 4 rotas presentes com nomes exatos. Nenhum `markTestSkipped` — se rota
mudar, `$this->fail()` explícito com instrução de atualização.

**Resultado**: 6/6 verdes (11 assertions).

## Gate compliance TDD

Verificado em `git log`:

- Commit RED Task 1 (`8247beb`) — `test(60-04): RED — UnifiedMetricsServiceTest cobrindo 3 casos + none` (11 failed / 0 assertions, `Class UnifiedMetricsService does not exist`)
- Commit GREEN Task 1 (`0941350`) — `feat(60-04): GREEN — UnifiedMetricsService com fusao campo-a-campo + log divergencia TACOS` (11 passed / 74 assertions)
- Commit Task 2 (`b929962`) — `test(60-04): baseline regressao consumidores adman_metrics` (6 passed / 11 assertions)

Sequência RED → GREEN respeitada. Fail-fast confirmado: RED de Task 1 falhou
com `BindingResolutionException: Target class [UnifiedMetricsService] does not exist`.

## Contagem Phase 60 acumulada

| Plan | Testes | Assertions |
|------|--------|-----------|
| 60-02 (AdmanMetricsProviderTest) | 13 | 50 |
| 60-03 T1 (MlMetricsProviderTest) | 11 | 36 |
| 60-03 T2 (MetricsProviderFactoryTest) | 5 | 17 |
| 60-04 T1 (UnifiedMetricsServiceTest) | 11 | 74 |
| 60-04 T2 (BaselineRegressionTest) | 6 | 11 |
| **Total Phase 60** | **46** | **188** |

Verificado via `php artisan test tests/Feature/Phase60` — 46/46 verdes.

## Delta legacy = 0 auditado

`git diff HEAD --` nos arquivos sensíveis retorna **0 linhas modificadas**:

- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Http/Controllers/PortfolioController.php`
- `app/Services/AdmanService.php`
- `app/Services/MercadoLivreService.php`
- `app/Services/Metrics/AdmanMetricsProvider.php` (Plan 60-02)
- `app/Services/Metrics/MlMetricsProvider.php` (Plan 60-03)
- `app/Services/Metrics/MetricsProviderFactory.php` (Plan 60-03)
- `app/Services/Metrics/UnifiedMetricsDto.php` (Plan 60-02)
- `app/Contracts/MetricsProvider.php` (Plan 60-02)

Working tree tinha 3 arquivos pré-existentes modificados (`CompanyController.php`,
`GoalController.php`, `MercadoLivreOAuthController.php`) — todos são untracked-from-scope
(mudanças anteriores ao Plan 60-04, documentado em git status inicial).

## Amostragem de log de divergência TACOS

Padrão observado (T7 do UnifiedMetricsServiceTest):

```json
{
  "level": "warning",
  "message": "[UnifiedMetrics] TACOS divergente",
  "context": {
    "company_id": 42,
    "period_from": "2026-07-01",
    "period_to":   "2026-07-07",
    "ml": 5.0,
    "adman": 8.0,
    "delta_pct": 37.5
  }
}
```

Decisão para Phase 61: adicionar contador de divergências no dashboard admin
(painel "Setor Dev") para pontuar frequência real por empresa e usar como
input do futuro `DATA-FUTURE-02` (consistência denormalização vs pivot).

## Sucess Criteria do ROADMAP — status por prova

| SC | Descrição | Prova executável |
|----|-----------|------------------|
| #1 | Leitura sem quebrar em 3 casos | UnifiedMetricsServiceTest T1-T4 + T9 + AdmanMetricsProviderTest + MlMetricsProviderTest |
| #2 | ADR de precedência versionado | `.planning/adrs/DATA-04-precedencia-multifonte.md` (Plan 60-01) |
| #3 | Testes automatizados 3 casos com fixtures | UnifiedMetricsServiceTest T1/T2/T3 (nomeados `so_adman/so_ml/ambos`) + T5-T8 (casos nomeados) — grep retorna >3 ocorrências |
| #4 | Delta regressão = 0 | BaselineRegressionTest 6 verdes + `git diff HEAD -- <legacy>` = 0 linhas |

## Deviations from Plan

Um único ajuste inline documentado — Rule 1 (bug):

### 1. [Rule 1 - Bug] Coluna `ativo` inexistente em `companies`

- **Onde:** setUp() do `BaselineRegressionTest.php`, linha do `Company::factory()->create()`.
- **Sintoma:** `SQLSTATE[HY000]: General error: 1 table companies has no column named ativo`.
- **Correção:** removido `'ativo' => 1` do payload. A coluna correta é `active`
  (já preenchida pelo default do factory). Nenhum outro efeito colateral.
- **Detectado:** primeira execução dos 6 testes (todos falharam com o mesmo erro no setUp).

Nenhum outro desvio material. Plan executado exatamente como escrito:
- 2 tasks TDD com ordem RED → GREEN → commit por task.
- 3 casos + none + divergência TACOS cobertos nomeadamente.
- Sem `markTestSkipped` no baseline (rota portfolio via `Route::has` + `$this->fail`).
- Delta legacy zero confirmado.

## Threat Flags

Nenhum novo threat flag introduzido além dos já cobertos pelo threat register:

- **T-60-04-01 (Information Disclosure)**: mitigado — log de divergência TACOS
  contém apenas `company_id`, período e valores agregados. `grep -c "access_token\|password\|api_key"` no `UnifiedMetricsService.php` = 0.
- **T-60-04-02 (DoS)**: mitigado por delegação — provider ML já tem cache 15 min.
- **T-60-04-03 (Tampering)**: mitigado — tabela de precedência espelha ADR
  DATA-04 diretamente no método `mergeFields()`; alteração exige update
  coordenado ADR + código.
- **T-60-04-04 (Repudiation)**: mitigado — log emitido ANTES do merge para
  preservar valores originais dos 2 providers.

## Self-Check: PASSED

- `app/Services/Metrics/UnifiedMetricsService.php` existe (verificado via `git ls-files`).
- `tests/Feature/Phase60/UnifiedMetricsServiceTest.php` existe.
- `tests/Feature/Phase60/BaselineRegressionTest.php` existe.
- Commit `8247beb` (RED Task 1) presente em `git log`.
- Commit `0941350` (GREEN Task 1) presente em `git log`.
- Commit `b929962` (Task 2) presente em `git log`.
- `php artisan test tests/Feature/Phase60` → 46/46 verdes.
- `git diff HEAD -- <legacy>` = 0 linhas em todos os arquivos sensíveis.
- Nenhum arquivo modificado fora do escopo declarado (working tree pré-existente inalterado).

## Próxima phase

**Phase 61 — Dashboards multi-fonte + badge de origem (DASH-06 / DATA-05).**

Consumidor natural do `UnifiedMetricsService`:
1. Habilitar `UNIFIED_METRICS_ENABLED=true` no env de produção (feature flag do ADR).
2. Migrar `DashboardController` (admin) para consumir `UnifiedMetricsService->readForCompany()`
   em vez de query direta em `adman_metrics`.
3. Exibir badge visual no card KPI conforme `$dto->source` (`adman|ml|unified|none`).
4. Adicionar contador de divergências TACOS no painel `/dev/desenvolvimento` a partir
   dos logs estruturados desta phase.

`Phase 60 → CONCLUÍDA.`

## Self-Check final

- Todos os arquivos criados existem (verificado via `test -f`).
- Commits `8247beb` (RED), `0941350` (GREEN Task 1), `b929962` (Task 2) presentes em `git log`.
- `PHASE-SUMMARY.md` criado sumarizando os 4 plans + 4 SC + delta zero.

**Self-Check: PASSED**
