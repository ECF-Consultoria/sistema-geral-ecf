---
phase: 06-backend-fechamento
plan: "02"
subsystem: api
tags: [php, laravel, eloquent, aggregation, carbon, inertia, phpunit]

# Dependency graph
requires:
  - phase: 06-backend-fechamento/06-01
    provides: "calcularFaixa() + FAIXAS const + 8 feature tests RED como contrato TDD"

provides:
  - "fechamento() expandido com SUM(revenue) GROUP BY company_id via AdmanMetric::selectRaw"
  - "Estado por empresa: sem_integracao / sem_dados / ok derivado de adman_account_id + presença de métricas"
  - "Props FCH-04: faturamento (float), periodo_inicio (d/m), periodo_fim (d/m) entregues ao Financeiro.jsx"
  - "Props FCH-05: faixa (string), valor_mensal (float) calculados por calcularFaixa()"
  - "AdminFechamentoControllerTest: 16/16 testes GREEN (8 originais + 8 novos)"

affects:
  - 07-frontend-fechamento

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "AdmanMetric::whereBetween(reference_date, [inicio, fim])->selectRaw(SUM/MIN/MAX)->groupBy(company_id)->keyBy(company_id) — aggregation canônica de faturamento mensal"
    - "Carbon::parse($selectRaw_result)->format('d/m') — necessário porque selectRaw não passa pelo $casts do Model"
    - "(float) $metrica->faturamento — cast explícito de string SUM() para float antes de calcularFaixa()"
    - "match(true) com 3 arms para derivar estado: sem_integracao / sem_dados / ok"
    - "JSON round-trip (json_encode → json_decode) converte float sem decimal para int — testes de asserção devem usar int para valores exatos"

key-files:
  created: []
  modified:
    - app/Http/Controllers/AdminController.php
    - tests/Feature/AdminFechamentoControllerTest.php

key-decisions:
  - "selectRaw retorna strings para campos calculados — Carbon::parse() e (float) cast são obrigatórios"
  - "JSON round-trip descarta .0 de floats sem parte decimal — testes corrigidos de 1000000.0/3000.0 para 1000000/3000 (int)"
  - "whereBetween usa Carbon diretamente sem .toDateString() — Eloquent aceita Carbon objects no binding"
  - "calcularFaixa() chamada com (float) $metrica->faturamento para garantir tipo correto independente de SQLite/MySQL"

patterns-established:
  - "Wave TDD: Plan 01 cria testes RED + lógica pura; Plan 02 implementa query que faz os testes virarem GREEN"
  - "Agregação de métricas mensais: selectRaw + whereBetween + keyBy(company_id) + map() com closure"

requirements-completed:
  - FCH-04
  - FCH-05

# Metrics
duration: 8min
completed: 2026-05-19
---

# Phase 06 Plan 02: fechamento() aggregation query + FCH-04/FCH-05 props — 16/16 testes GREEN

**SUM(revenue) GROUP BY company_id com estados sem_integracao/sem_dados/ok e faixas de investimento entregues ao Financeiro.jsx via Inertia props — 16/16 AdminFechamentoControllerTest GREEN**

## Performance

- **Duration:** 8 min
- **Started:** 2026-05-19T18:39:51Z
- **Completed:** 2026-05-19T18:47:40Z
- **Tasks:** 2 (Task 1: implementação + Task 2: verificação suite completa)
- **Files modified:** 2

## Accomplishments

- `AdminController::fechamento()` expandido com aggregation query `SUM/MIN/MAX GROUP BY company_id` sobre `adman_metrics` para o mês corrente
- Estado por empresa derivado: `sem_integracao` (sem adman_account_id), `sem_dados` (integrada sem métricas no mês), `ok` (com dados)
- Props FCH-04 entregues: `faturamento` (soma do mês), `periodo_inicio` / `periodo_fim` formatados como `d/m`
- Props FCH-05 entregues: `faixa` e `valor_mensal` calculados por `calcularFaixa()` apenas para empresas com estado `ok`
- Suite completa: 57/57 (exceto ExampleTest pré-existente) — zero regressões

## Task Commits

1. **Task 1+2: fechamento() expansão + verificação suite** - `09ec991` (feat)

**Plan metadata:** (criado neste commit)

## Files Created/Modified

- `app/Http/Controllers/AdminController.php` — `fechamento()` substituído com aggregation query AdmanMetric + mapeamento de estado + Carbon::parse() para datas de selectRaw + (float) cast para SUM
- `tests/Feature/AdminFechamentoControllerTest.php` — 4 valores float sem decimal corrigidos para int (bug de tipo: JSON round-trip descarta .0)

## Decisions Made

- `Carbon::parse()` obrigatório nos campos de data do `selectRaw`: o `groupBy()->get()` retorna objetos sem os `$casts` do Model, então `periodo_inicio` chega como string `"2026-05-01"`, não Carbon
- `(float)` obrigatório no `faturamento` antes de `calcularFaixa()`: SQLite/MySQL retornam `SUM()` como string sem cast
- Testes corrigidos para usar `int` nos valores exatos: `json_encode(1000000.0)` → `"1000000"` → `json_decode` → `int(1000000)`. O `assertSame` é estrito quanto a tipo, então `assertSame(float(1000000.0), int(1000000))` falha

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Testes com expectativas de tipo incorreto após JSON round-trip**
- **Found during:** Task 1 (verificação dos testes)
- **Issue:** Os 4 testes criados no Plan 01 usavam `1000000.0`, `3000.0`, `4500.0`, `12000.0` (float) como valor esperado. O helper `AssertableInertia::fromTestResponse()` faz `json_decode(json_encode($props), true)` — o `json_encode` converte `float(1000000.0)` para `"1000000"` (sem decimal), o `json_decode` retorna `int(1000000)`, e o `assertSame(float(1000000.0), int(1000000))` falha por tipo diferente (`===`)
- **Fix:** Corrigidos os 4 valores esperados para int: `1000000.0 → 1000000`, `3000.0 → 3000`, `4500.0 → 4500`, `12000.0 → 12000`. Semanticamente correto: os valores são inteiros exatos e o JSON os representa como inteiros
- **Files modified:** `tests/Feature/AdminFechamentoControllerTest.php`
- **Verification:** `AdminFechamentoControllerTest` passou de 12/16 para 16/16 GREEN
- **Committed in:** `09ec991` (parte do commit da task)

---

**Total deviations:** 1 auto-fixed (Rule 1 - bug de tipo nos testes)
**Impact on plan:** Correção necessária para os testes funcionarem corretamente. O comportamento do sistema está correto — a correção foi apenas na expectativa de tipo nas asserções.

## Issues Encountered

- `php artisan test` não disponível via PATH no shell bash do Windows/XAMPP — usando `/c/xampp/php/php vendor/bin/phpunit` diretamente (mesma solução do Plan 01)
- SQLite em testes retorna `SUM()` de inteiros como int, não string — o `(float)` cast no controller funciona em ambos os casos, mas o JSON round-trip converte float sem decimal de volta para int

## User Setup Required

None - nenhuma configuração externa necessária.

## Next Phase Readiness

- Phase 07 (frontend Fechamento) pode iniciar: todas as props necessárias estão disponíveis no `Inertia::render('Admin/Financeiro', compact('companies'))`
- Props shape confirmado: `id`, `name`, `service_type`, `contract_start`, `contract_end`, `additional_service`, `has_adman`, `estado`, `faturamento`, `periodo_inicio`, `periodo_fim`, `faixa`, `valor_mensal`
- Tipos no JSON: `faturamento` e `valor_mensal` chegam como int quando o valor é inteiro exato (ex: 3000, 1000000) — o frontend deve tratar com `Number()` ou `parseFloat()` se precisar de decimal display

---
*Phase: 06-backend-fechamento*
*Completed: 2026-05-19*

## Self-Check: PASSED

- `app/Http/Controllers/AdminController.php` — FOUND
- `tests/Feature/AdminFechamentoControllerTest.php` — FOUND
- `.planning/phases/06-backend-fechamento/06-02-SUMMARY.md` — FOUND (este arquivo)
- Commit `09ec991` — FOUND
- AdminFechamentoControllerTest: 16/16 GREEN confirmado
- CalcularFaixaTest: 9/9 GREEN confirmado
- Suite completa: 57/57 (1 falha ExampleTest pré-existente — esperado)
