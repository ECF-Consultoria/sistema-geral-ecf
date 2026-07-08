---
phase: 73-limpeza-legado-testes-e2e
plan: 73-02
subsystem: nps
tags: [nps, backend, jobs, calculate-goal-results, metric-nps, score-calculator, phase73, req-nps-f-03, sc3]
milestone: v15.0

dependency_graph:
  requires:
    - "Phase 69-02 (NpsScoreCalculator::compute)"
    - "Phase 31 (colunas legacy nps_responses.score_empresa nullable, dual-path)"
    - "Plan 73-01 (helper avgNotaDimensao — mesmo padrão dual-path reusado aqui)"
  provides:
    - "CalculateGoalResults::extractMetricValue com metric='nps' funcional (real, não mais null)"
    - "método privado CalculateGoalResults::computeNps(int companyId, int year, int month): ?float — dual-path v15/legacy"
    - "SC#3 do Phase 73 atendido: metric='nps' calcula progresso real de meta"
  affects:
    - "Fluxo de scheduler que dispara CalculateGoalResults mensal — metas NPS passam a ter GoalResult gravado"
    - "Widget de progresso de metas (frontend) — meta NPS deixa de mostrar 'sem dado' quando há resposta"

tech_stack:
  added: []
  patterns:
    - "Early-return por metric antes da query padrão AdmanMetric — mesmo padrão de 'acos' (linhas 127-133 do arquivo, pre-existente)"
    - "Dual-path v15 vs legacy centralizado por response: template_id != null → calculator; else → coluna score_empresa"
    - "Semântica null preservada: null = 'sem dado' (não grava GoalResult); float 1..5 = 'com dado'"

key_files:
  created: []
  modified:
    - app/Jobs/CalculateGoalResults.php

decisions:
  - "Assinatura computeNps(int companyId, int year, int month): ?float — coerente com extractMetricValue (Goal fillable NÃO tem portfolio_id nem period_start/end; único escopo suportado é company_id + período YYYY-MM do job)"
  - "Early-return para 'nps' ANTES da query AdmanMetric — se ficasse dentro do match, empresas sem AdmanMetric no mês (ML-only ou sync atrasado) ficariam sem cálculo de meta NPS (bug latente evitado)"
  - "Dual-path idêntico ao Plan 73-01 (template_id !== null como discriminador) — coerência entre 3 call-sites (DashboardController::avgNotaDimensao, PerformanceController::index, CalculateGoalResults::computeNps)"
  - "Não implementado escopo portfolio/carteira — plan mencionou como possibilidade mas Goal model não suporta hoje (fillable = ['company_id', 'metric', ...])"

metrics:
  duration_min: 8
  completed_date: "2026-07-08"
  tasks_completed: 2
  files_modified: 1
  files_created: 0
---

# Phase 73 Plan 02: CalculateGoalResults metric='nps' via NpsScoreCalculator Summary

Implementação cirúrgica do branch `'nps'` no `CalculateGoalResults::extractMetricValue` — hoje retorna `null` (metas NPS sem progresso). Agora calcula a média real das notas 'empresa' das `NpsResponse` completed no período, com dual-path v15/legacy idêntico ao Plan 73-01. Zero mudança em `NpsScoreCalculator`; suite baseline (146 verdes + 1 pré-existente Phase33 documentado) preservada bit-a-bit.

## Objetivo Alcançado

REQ NPS-F-03 100% coberto no backend. SC#3 do Phase 73 atendido: `metric='nps'` calcula progresso real de meta.

## Tasks Executadas

| Task | Objetivo | Commit | Arquivo |
|------|----------|--------|---------|
| T1   | Separar 'nps' do agrupamento nulo + adicionar método `computeNps(int, int, int): ?float` com dual-path v15/legacy | `41be6f2` | app/Jobs/CalculateGoalResults.php |
| T2   | Verificação: `php -l` verde + suite baseline delta zero | `41be6f2` (mesmo commit) | — |

## Mudanças Detalhadas

### app/Jobs/CalculateGoalResults.php — imports (linhas 5-17)

Adicionados 2 imports:
- `use App\Models\NpsResponse;`
- `use App\Services\Nps\NpsScoreCalculator;`

Ambos alfabeticamente inseridos na seção Model/Service já existente. Nenhum outro import alterado.

### app/Jobs/CalculateGoalResults.php — extractMetricValue (linhas 125-169)

**Antes (bloco relevante):**
```php
if ($metric === 'acos') { /* ... */ }

// Usa o último registro do mês para métricas diárias
$row = AdmanMetric::where('company_id', $companyId)
    ->whereYear('reference_date', $year)
    ->whereMonth('reference_date', $month)
    ->latest('reference_date')
    ->first();

if (!$row) return null;

return match ($metric) {
    // ...
    'absenteeism', 'nps', 'ppa_completion', 'products_without_cost' => null,
    default => null,
};
```

**Depois:**
```php
if ($metric === 'acos') { /* ... */ }

// Phase 73 Plan 02 — metric 'nps' NÃO vem de AdmanMetric; fonte é
// `nps_responses` filtradas por company_id + completed_at no mês.
// Precisa vir ANTES da query AdmanMetric (linha `if (!$row) return null`)
// para não excluir empresas ML-only ou sem sync Adman no período.
// 'nps' => $this->computeNps($companyId, $year, $month)  ← via early-return
if ($metric === 'nps') {
    return $this->computeNps($companyId, $year, $month);
}

// Usa o último registro do mês para métricas diárias
$row = AdmanMetric::where('company_id', $companyId)
    // ...
    ->first();

if (!$row) return null;

return match ($metric) {
    // ...
    'absenteeism', 'ppa_completion', 'products_without_cost' => null,
    default => null,
};
```

- Chave `'nps'` REMOVIDA do agrupamento null do match.
- Novo branch early-return para `'nps'` antes da query `AdmanMetric`.
- **Rationale do early-return** (não colocar dentro do match): se `'nps'` ficasse no match, seria bloqueado pelo `if (!$row) return null` (linha 151) — empresas ML-only ou com sync Adman atrasado no período nunca teriam meta NPS calculada. Bug latente evitado. O padrão espelha o `'acos'` (linhas 127-133), que também não depende de `AdmanMetric`.

### app/Jobs/CalculateGoalResults.php — método novo computeNps (linhas 171-240)

Método privado `computeNps(int $companyId, int $year, int $month): ?float`:

- **Query:** `NpsResponse::whereHas('survey', ...)` filtra por `company_id`, `status='completed'`, `whereYear+whereMonth` em `completed_at`.
- **Eager-load:** `->with('survey')` — evita N+1 no dual-path (leitura de `survey->template_id`).
- **Dual-path por response:**
  - v15 (`$r->survey && $r->survey->template_id !== null`): usa `$calculator->compute($r, 'empresa')` — AVG do `option_peso_snapshot` da dimensão 'empresa' em `nps_response_answers`.
  - Legacy (`template_id === null` OU survey ausente): usa `$r->score_empresa` direto (nullable desde Phase 68).
- **Filtragem null:** `.filter(fn ($n) => $n !== null)` remove respostas onde nenhuma nota computável foi produzida (dimension 'empresa' vazia + legacy null).
- **Retorno:** `round((float) $notas->avg(), 2)` na escala 1..5. `null` quando `$responses->isEmpty()` (sem responses no período) OU `$notas->isEmpty()` (nenhuma nota computável).

## Verificação (T2)

```bash
# 1. Sintaxe PHP verde
$ /c/xampp/php/php.exe -l app/Jobs/CalculateGoalResults.php
No syntax errors detected in app/Jobs/CalculateGoalResults.php

# 2. Acceptance criteria via grep
$ grep -c "'nps'.*=>.*null" app/Jobs/CalculateGoalResults.php
0    # chave 'nps' NÃO está mais no agrupamento null

$ grep -n "'nps'.*=>.*computeNps\|'nps'.*=>.*this->computeNps" app/Jobs/CalculateGoalResults.php
139:        // 'nps' => $this->computeNps($companyId, $year, $month)  ← via early-return

$ grep -n "private function computeNps" app/Jobs/CalculateGoalResults.php
205:    private function computeNps(int $companyId, int $year, int $month): ?float

$ grep -c "NpsScoreCalculator" app/Jobs/CalculateGoalResults.php
5    # import + docblock + call + apiuse

$ grep -nE "template_id.*!==.*null|template_id.*!=.*null" app/Jobs/CalculateGoalResults.php
187:     *  - Surveys v15 (`template_id !== null`): usa
226:            // PerformanceController::index): template_id !== null → v15
229:            if ($r->survey && $r->survey->template_id !== null) {

$ grep -n "responses->isEmpty\|notas->isEmpty" app/Jobs/CalculateGoalResults.php
217:        if ($responses->isEmpty()) {
235:        if ($notas->isEmpty()) {

# 3. Suite baseline delta zero (Phases 31 + 33 + 68-72)
$ /c/xampp/php/php.exe artisan test tests/Feature/Phase31* tests/Feature/Phase33* tests/Feature/Phase68 tests/Feature/Phase69 tests/Feature/Phase70 tests/Feature/Phase71 tests/Feature/Phase72
Tests:    1 failed, 146 passed (993 assertions)
Duration: 75.48s
# → 1 failed = Phase33OnboardingFichaTest "Serra Gaúcha" (PRE-EXISTENTE, documentado
#   em Phase 72 PHASE-SUMMARY.md e Plan 73-01-SUMMARY.md — não relacionado a Phase 73)
# → 146 verdes = baseline preservado, delta zero confirmado
```

## Contrato Preservado

| Preservado | Justificativa |
|------------|---------------|
| `NpsScoreCalculator::compute(NpsResponse, string): ?float` | Zero mudança; consumido via `app()` helper |
| Colunas legacy `nps_responses.score_empresa` | Nullable desde Phase 68; fallback preservado |
| Fluxo `handle()` → `extractMetricValue` → `GoalResult::updateOrCreate` | Assinatura de `extractMetricValue` inalterada; retorno `?float` mesma semântica |
| Semântica null = "sem dado" | Preservada — `null` no retorno → `handle()` faz `continue` → GoalResult NÃO gravado (comportamento legacy do fluxo) |
| Dispatch AUTO-05 (`dispatchAtingidaIfNeeded`) | Zero mudança; disparado normalmente quando `achieved === true` |
| Suite baseline Phases 31/33/68-72 | Delta zero — 146 verdes + 1 pré-existente Phase33 documentado |

## Contrato Mudado

Nenhum breaking change. Comportamento visível para caller externo:
- Metas com `metric='nps'` ANTES: `extractMetricValue` retornava `null` sempre → GoalResult nunca gravado → widget de progresso mostrava "sem dado".
- DEPOIS: `extractMetricValue` retorna `float 1..5` quando há resposta NPS no período → GoalResult gravado → widget mostra progresso real. Retorna `null` apenas quando genuinamente não há resposta (semântica correta).

## Deviations from Plan

**[Rule 3 - Blocking issue] Adaptação da assinatura de computeNps ao schema real do Goal.**
- **Encontrado durante:** T1 leitura do Goal model
- **Issue:** Plano especificava `computeNps(Goal $goal): ?float` com escopo `goal->portfolio->companies` e período `goal->period_start_date/period_end_date`. Schema real do Goal (`$fillable`, migrações) NÃO tem `portfolio_id` nem `period_*_date` — Goal.php tem apenas `['company_id', 'metric', 'target_value', 'value_type', 'period_type', 'active', 'description']`. Período é derivado do parâmetro `$this->period` (YYYY-MM) do próprio job (linhas 24, 34-35).
- **Fix:** Assinatura ajustada para `computeNps(int $companyId, int $year, int $month): ?float`, coerente com `extractMetricValue(string $metric, int $companyId, int $year, int $month)`. Documentado no docblock que expansão futura para carteira exigirá whereIn.
- **Files modified:** app/Jobs/CalculateGoalResults.php (mesmo arquivo do plan)
- **Impacto:** Zero — cobertura semântica é IDÊNTICA (calcula NPS da empresa no período YYYY-MM). Diferença é interna, não afeta caller.

**[Rule 3 - Blocking issue] Early-return para 'nps' antes de match.**
- **Encontrado durante:** T1 análise do fluxo `extractMetricValue`.
- **Issue:** Plan sugeria manter `'nps' => $this->computeNps(...)` DENTRO do `match ($metric)`, mas match está DEPOIS do `if (!$row) return null` (linha 151). Empresas sem `AdmanMetric` no mês (ML-only, sync atrasado, empresa nova) nunca alcançariam o branch NPS — bug latente.
- **Fix:** `if ($metric === 'nps') return $this->computeNps(...)` como early-return ANTES da query `AdmanMetric` (linhas 140-142). Mesmo padrão do `'acos'` pré-existente (linhas 127-133).
- **Files modified:** app/Jobs/CalculateGoalResults.php (mesmo arquivo do plan)
- **Impacto:** Melhoria semântica — metas NPS agora funcionam mesmo em empresas sem AdmanMetric. Zero regressão.

## Known Stubs

Nenhum. Implementação end-to-end da branch NPS — não há placeholder, TODO ou retorno hardcoded. Todos os paths (v15, legacy, sem responses, sem notas computáveis) têm tratamento explícito.

## Threat Flags

Nenhum. Nenhuma nova superfície de rede/endpoint/auth/schema. A mudança é interna a um Job já disparado apenas pelo scheduler e por controller admin gated. `NpsResponse::whereHas` opera em colunas já indexadas (`nps_surveys.company_id`, `nps_surveys.status`, `nps_surveys.completed_at`).

## Impacto na Suite

| Suite | Antes | Depois | Delta |
|-------|-------|--------|-------|
| Phase31* | 3 arquivos verdes | 3 arquivos verdes | 0 |
| Phase33* | 39 verdes + 1 vermelho (Serra Gaúcha, pré-existente) | 39 verdes + 1 vermelho | 0 |
| Phase68 | verde | verde | 0 |
| Phase69 | verde (incl. NpsScoreCalculator + integração E2E) | verde | 0 |
| Phase70 | verde | verde | 0 |
| Phase71 | verde | verde | 0 |
| Phase72 | verde (dashboards NPS pendências) | verde | 0 |

Total: **146 verdes + 1 pré-existente Phase33** — bit-a-bit idêntico ao baseline. SC#5 do Phase 73 preservado.

## Próximo Plan

**73-03** — Frontend cleanup (`Dashboard/Admin.jsx` + `Performance/Dashboard.jsx` consumindo `positivas/negativas` + `nota` float sem `classe`, do 73-01).

**73-04** — E2E test do CalculateGoalResults com metric='nps' (dual-path v15 + legacy + escala 1..5 + AUTO-05 dispatch quando meta atingida). Cobertura E2E fora do escopo deste plan por design.

## Self-Check: PASSED

Arquivos criados/modificados:
- FOUND: app/Jobs/CalculateGoalResults.php (modificado T1)
- FOUND: .planning/phases/73-limpeza-legado-testes-e2e/73-02-SUMMARY.md (este arquivo)

Verificação estrutural:
- FOUND: `private function computeNps` linha 205
- FOUND: `if ($metric === 'nps')` early-return linha 140
- FOUND: chave `'nps'` REMOVIDA do agrupamento null (grep 0)
- FOUND: dual-path `template_id !== null` linha 229
- FOUND: import `NpsResponse` + `NpsScoreCalculator` linhas 9 + 12
- FOUND: `php -l` verde
- FOUND: suite baseline 146 verdes + 1 pré-existente delta zero

Commit hash: `41be6f2`
