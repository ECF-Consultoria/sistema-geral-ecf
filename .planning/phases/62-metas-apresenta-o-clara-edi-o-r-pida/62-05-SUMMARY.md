---
phase: 62-metas-apresenta-o-clara-edi-o-r-pida
plan: 05
subsystem: goals-ui
tags: [meta-01, goals, ui, companies-show, chart, sc-1]
dependency_graph:
  requires:
    - 62-02 (GoalProgressPanel)
    - 61-03 (SourceBadge preservado no header)
  provides:
    - Payload CompanyController::show enriquecido com goals[].results (últimos 12 ASC)
    - Companies/Show.jsx com grid de GoalProgressPanel na seção "Metas Ativas"
  affects:
    - resources/js/Pages/Companies/Show.jsx (Section Metas Ativas + import)
    - app/Http/Controllers/CompanyController.php (método show — eager load + mapper de goals)
tech_stack:
  added: []  # zero pacotes novos (GoalProgressPanel já entregue no 62-02)
  patterns:
    - Inertia payload enrichment (adicionar chaves ao array de goals sem quebrar props existentes)
    - Grid responsivo (grid-cols-1 lg:grid-cols-2) para paineis compactos
    - Eager load com sub-query constraint (`->with(['results' => fn ...])`)
key_files:
  created:
    - tests/Feature/Phase62/CompanyShowGoalsPayloadTest.php
    - .planning/phases/62-metas-apresenta-o-clara-edi-o-r-pida/62-05-SUMMARY.md
  modified:
    - app/Http/Controllers/CompanyController.php
    - resources/js/Pages/Companies/Show.jsx
decisions:
  - "Bloco Metas movido para fora do grid Dados da Empresa (full-width) para dar espaço aos charts do compact panel"
  - "Eager load busca results DESC + limit 12, mapper reordena ASC — evita transformação pesada em memória"
  - "Cast explícito (float)/(bool) no mapper — Recharts falha silenciosamente quando valores chegam como string decimal"
  - "Testes usam `nps` em vez de `acos` como segunda metric — CHECK constraint SQLite (in-memory) só cobre o ENUM legado da migration original"
metrics:
  duration_minutes: ~28
  completed_date: 2026-07-07
  tasks_completed: 2
  files_touched: 3
  tests_added: 6
  tests_total_phase62: 19
requirements:
  - META-01
---

# Phase 62 Plan 62-05: Companies/Show grid GoalProgressPanel Summary

Substituiu a seção "Metas Ativas" da carteira individual (`/companies/{id}`) de uma listagem pobre (`metric_label + target_value` bruto) para grid responsivo de `<GoalProgressPanel compact />` — cada meta ativa exibe chart histórico + percentual atingido + valor absoluto na mesma tela, fechando o Success Criteria #1 do phase.

## Diff resumido — CompanyController::show

### Eager load (linha 279)
**Antes:**
```php
'goals' => fn($q) => $q->where('active', true),
```

**Depois:**
```php
// Phase 62 Plan 62-05 (META-01): eager load pega os 12 results
// MAIS RECENTES (desc + limit) — o mapper depois reordena ASC
// para alimentar o chart do <GoalProgressPanel /> temporalmente.
'goals' => fn($q) => $q->where('active', true)
    ->with(['results' => fn($rq) => $rq->orderBy('period', 'desc')->limit(12)]),
```

### Mapper de goals (linhas ~447-450 → ~453-475)
**Chaves adicionadas ao payload:** `value_type`, `period_type`, `results[]` (com `id`, `period`, `realized_value`, `target_value`, `achieved`).

**Chaves preservadas intactas:** `revenue_30d`, `acos_30d`, `tacos_30d`, `margin_pct_30d`, `ecf_drive`, `contratos_servico`, `adman_metrics`, `ml_metrics`, `meetings`, `nps_surveys`, `ppas`, `permissions`, `goal_metrics`, `goal_percentage_only_metrics`.

## Diff resumido — Companies/Show.jsx

### Import adicionado
```jsx
import GoalProgressPanel from '@/Components/goals/GoalProgressPanel';
```

### Section "Metas Ativas" (linha ~817 → ~821-856)
**Antes:** dentro de `<div className="grid grid-cols-1 md:grid-cols-2 gap-5">` junto com "Dados da Empresa"; conteúdo era `<div key={g.id}>{g.metric_label} — {g.target_value}</div>` linha simples com valor bruto.

**Depois:** bloco full-width fora do grid (para os charts respirarem); conteúdo é `<div data-testid="company-goals-grid" className="grid grid-cols-1 lg:grid-cols-2 gap-4">` com `<GoalProgressPanel goal={g} results={g.results || []} compact />` para cada meta ativa. Empty state ganhou `data-testid="company-goals-empty"` e `py-8` para respiro visual.

### Preservado (não tocado)
- Section "Dados da Empresa" (linhas 803-817)
- Section "Serviços contratados" (linha 858+)
- Handler `abrirNovaMeta` + Dialog Nova Meta
- Auth `canCreateGoals`
- `SourceBadge` no header (Plan 61-03 — linha 565)
- Todas as demais seções (contratos, ECF Drive, PPAs, NPS, etc.)

## Testes verdes

| Suite | Tests | Assertions | Status |
|-------|-------|------------|--------|
| tests/Feature/Phase62/CompanyShowGoalsPayloadTest.php (**novo**) | 6/6 | 102 | GREEN |
| tests/Feature/Phase62/GoalHistoryEndpointTest.php | 5/5 | — | GREEN |
| tests/Feature/Phase62/GoalUpdateAuthTest.php | 7/7 | — | GREEN |
| tests/Feature/Phase62/ActivityLogSubjectFilterTest.php | 1/1 | — | GREEN |
| **Phase62 total** | **19/19** | **159** | **GREEN** |

Cobertura dos 6 novos tests:
1. shape base — `goals[N]` expõe `id, metric, metric_label, target_value, value_type, period_type, active, results`
2. results ordenados ASC por period (mesmo com inserção fora de ordem)
3. limit 12 aplicado (goal com 15 GoalResult expõe apenas os 12 mais recentes)
4. goal sem GoalResult expõe `results: []` (array vazio, não null)
5. apenas `active=true` retornado (regressão zero da eager load original)
6. types corretos — `realized_value/target_value` são float; `achieved` é bool

## Build status

`npm run build` verde em 13.40s. `Show-CRe0bBPa.js` (34.64 kB / 9.54 kB gzip) — bundle da página cresceu marginalmente pelo import de `<GoalProgressPanel />` (Recharts já estava no vendor chunk `CategoricalChart`).

## Grep gates (aceitação)

| Gate | Resultado | Esperado | Status |
|------|-----------|----------|--------|
| `'results'` em CompanyController.php | 2 | >= 2 | OK |
| `sortBy('period')` em CompanyController.php | 1 | == 1 | OK |
| `Phase 62` em CompanyController.php | 2 | >= 1 | OK |
| `GoalProgressPanel` em Show.jsx | 3 | >= 2 | OK |
| `data-testid="company-goals-grid"` em Show.jsx | 1 | == 1 | OK |
| `data-testid="company-goals-empty"` em Show.jsx | 1 | == 1 | OK |
| `SourceBadge` em Show.jsx | 2 | preservar 61-03 | OK |
| `abrirNovaMeta` em Show.jsx | 2 | preservar | OK |

## Deviations from Plan

Nenhum desvio arquitetural. Ajustes menores durante execução:

**1. [Rule 3 - Blocking] SQLite CHECK constraint `metric` não inclui `acos`**
- **Encontrado durante:** Task 1 (RED phase, T1 e T5)
- **Issue:** Migration `2026_04_28_000001_add_value_type_and_new_metrics_to_goals_table.php` adiciona `acos` ao ENUM apenas em MySQL (skipada em SQLite via `DB::getDriverName() !== 'sqlite'`). Tests com `metric='acos'` falhavam com `CHECK constraint failed`.
- **Fix:** trocado para `metric='nps'` (presente no ENUM legado) nos testes T1 e T5. Comentário explicativo inline documenta a razão para o próximo dev.
- **Files modified:** tests/Feature/Phase62/CompanyShowGoalsPayloadTest.php
- **Commit:** 3dd7411

**2. [Rule 1 - Bug] Assertion `where('target_value', 10.00)` falhava por coerção JSON int/float**
- **Encontrado durante:** Task 1 (GREEN phase, T6)
- **Issue:** `(float) 10.00` no PHP → JSON encoder emite `10` (sem casas) → JSON decoder devolve `int 10` → `AssertableInertia::where` faz comparação estrita contra `float 10.0` → falha.
- **Fix:** valor de target trocado para `10.50` (fracionário nos dois lados). Regra padrão pra Feature tests com Inertia: sempre usar fracionários em `where(..., $float)`.
- **Files modified:** tests/Feature/Phase62/CompanyShowGoalsPayloadTest.php
- **Commit:** 3dd7411

## Confirmação de zero regressão

- Payload preserva todas as chaves originais (`revenue_30d`, `ecf_drive`, `contratos_servico`, `adman_metrics`, `ml_metrics`, `meetings`, `nps_surveys`, `ppas`, `permissions`, `goal_metrics`, `goal_percentage_only_metrics`).
- `SourceBadge` do 61-03 intacto (linha 565).
- Sections abaixo (Serviços contratados, PPAs, NPS, ECF Drive, etc.) não sofreram mudanças.
- Suites Phase62 anteriores (`GoalHistoryEndpointTest`, `GoalUpdateAuthTest`, `ActivityLogSubjectFilterTest`) permanecem verdes (13/13 pré-existentes + 6 novos = 19/19).

## TDD Gate Compliance

Sequência RED → GREEN respeitada:
- **RED:** commit `55e4c76` — `test(62-05): CompanyShowGoalsPayloadTest 6 tests (RED)` (6/6 falhando com "Property [...results] does not exist").
- **GREEN:** commit `3dd7411` — `feat(62-05): CompanyController::show enriquece goals[].results (últimos 12)` (6/6 passando).
- **REFACTOR:** não necessário — código do mapper já ficou legível com sortBy/values/map chain.

## Self-Check: PASSED

Files criados/modificados verificados:
- `tests/Feature/Phase62/CompanyShowGoalsPayloadTest.php` — FOUND (commit 55e4c76)
- `app/Http/Controllers/CompanyController.php` — FOUND (commit 3dd7411)
- `resources/js/Pages/Companies/Show.jsx` — FOUND (commit 710ff51)

Commits verificados via `git log --oneline`:
- 55e4c76 (RED)
- 3dd7411 (backend GREEN)
- 710ff51 (frontend)
