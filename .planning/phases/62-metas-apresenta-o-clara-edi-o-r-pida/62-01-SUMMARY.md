---
phase: 62-metas-apresenta-o-clara-edi-o-r-pida
plan: 01
subsystem: metas-edicao
tags: [meta-04, activity-log, auth, backend]
requirements_completed: [META-04]
dependency:
  requires: [company_users pivot com role='estrategista', Spatie\Activitylog\Models\Activity, Goal::LogsActivity trait]
  provides:
    - "PUT /goals/{goal} aberto pra estrategista vinculado"
    - "GET /goals/{goal}/history retornando ate 10 entries JSON"
    - "ActivityLogController::index filtra por subject_id"
    - "Bloqueio delete-via-toggle (active=false so admin)"
  affects: [Plan 62-02 GoalEditDrawer.jsx, Plan 62-03 link "Ver log completo"]
tech_stack:
  added: []
  patterns:
    - "Auth inline no controller (mesmo pattern de GoalController::store) — nao usar Policy"
    - "orderByDesc('created_at') + orderByDesc('id') como tiebreaker deterministico"
    - "unset($data['active']) apos validate — descarte silencioso pra nao-admin"
key_files:
  created:
    - tests/Feature/Phase62/GoalUpdateAuthTest.php
    - tests/Feature/Phase62/GoalHistoryEndpointTest.php
    - tests/Feature/Phase62/ActivityLogSubjectFilterTest.php
  modified:
    - app/Http/Controllers/GoalController.php
    - app/Http/Controllers/ActivityLogController.php
    - routes/web.php
decisions:
  - "Estrategista NAO pode desativar meta via active=false: chave descartada silenciosamente (nao 422). Rationale: preserva backward compat com callers Goals/Index.jsx que hoje mandam active no payload sem discriminar user."
  - "Ordenacao history: created_at DESC + id DESC como tiebreaker. Rationale: activity_log tem granularidade de segundos; updates rapidos em sequencia (test T8) batem no mesmo timestamp e id monotonico garante ordem cronologica correta."
  - "History endpoint retorna JSON puro (nao Inertia). Rationale: consumidor e drawer AJAX; nao ha rota Inertia dedicada."
metrics:
  duration_min: ~15
  tests_added: 13
  tests_passed: 13
  regression_confirmed: [Phase60 (46), Phase61 (31), Phase11 Notifications (6)]
completed_date: 2026-07-07
---

# Phase 62 Plan 62-01: Backend edicao de meta + endpoint history Summary

**One-liner:** Abre `PUT /goals/{goal}` pra estrategista vinculado a empresa (via pivot `company_users.role='estrategista'`), adiciona `GET /goals/{goal}/history` retornando ate 10 entries do `activity_log` como JSON, e faz `ActivityLogController::index` respeitar filtro por `subject_id` — tudo mantendo bloqueio delete-via-toggle (`active=false` so admin).

## O que foi construido

### 1. `routes/web.php` — Movimentacao de rotas
- `Route::put('/goals/{goal}', ...)` movida do grupo `role:admin` para fora, logo abaixo de `Route::post('/goals', ...)` (linha ~303).
- Nova rota `Route::get('/goals/{goal}/history', ...)->name('goals.history')` adicionada ao lado do PUT.
- `Route::delete('/goals/{goal}', ...)` **mantida** dentro do grupo `role:admin` (fora escopo desta plan).

### 2. `GoalController::update` — Auth + bloqueio active
Bloco de auth adicionado no topo do metodo, apos resolver `$company = $goal->company`:
```php
$canManage = $user?->isAdmin()
    || $company->users()
        ->where('users.id', $user?->id)
        ->wherePivot('role', 'estrategista')
        ->exists();
abort_unless($canManage, 403);
```
Apos `$request->validate(...)`, bloqueio delete-via-toggle:
```php
if (!$user->isAdmin()) {
    unset($data['active']);
}
```

### 3. `GoalController::history` — Novo endpoint JSON
```php
public function history(Goal $goal, Request $request): \Illuminate\Http\JsonResponse
```
Auth: admin OU qualquer user vinculado a empresa (via pivot company_users).
Query: `Activity::with('causer')->where(subject_type=Goal, subject_id=id)->orderByDesc('created_at')->orderByDesc('id')->limit(10)`.

**Shape do JSON:**
```json
{
  "entries": [
    {
      "id": 42,
      "description": "Meta atualizada",
      "causer_name": "Fulano da Silva",
      "created_at": "2026-07-07T15:32:11+00:00",
      "changes": {
        "old":        { "target_value": "10.00" },
        "attributes": { "target_value": "20.00" }
      }
    }
  ]
}
```

### 4. `ActivityLogController::index` — Filtro subject_id
Apos o `if ($request->filled('subject_type'))`, adicionado:
```php
if ($request->filled('subject_id')) {
    $query->where('subject_id', $request->integer('subject_id'));
}
```
Chave `subject_id` incluida em `filters` retornados pra Inertia.

## Diff resumido

| Arquivo | +/- | Descricao |
|---|---|---|
| `routes/web.php` | +7/-3 | PUT+GET fora do grupo admin; DELETE mantida dentro; comentario META-04 |
| `app/Http/Controllers/GoalController.php` | +49/-1 | Auth block em update + bloqueio active + metodo history novo |
| `app/Http/Controllers/ActivityLogController.php` | +6/-1 | Filtro subject_id + chave em filters |
| `tests/Feature/Phase62/GoalUpdateAuthTest.php` | +197 novo | 7 tests (T1-T5, T11-T12) |
| `tests/Feature/Phase62/GoalHistoryEndpointTest.php` | +137 novo | 5 tests (T6-T10) |
| `tests/Feature/Phase62/ActivityLogSubjectFilterTest.php` | +72 novo | 1 test (T13) |

## Verificacao

### Contagem de testes
- **Phase62 target:** 13/13 verdes (7 GoalUpdateAuth + 5 GoalHistoryEndpoint + 1 ActivityLogSubjectFilter)
- **Regressao Phase60:** 46/46 verdes (baseline UnifiedMetrics/Providers/Baseline)
- **Regressao Phase61:** 31/31 verdes (SourceEnrichment/Dashboard/Portfolio/E2E/FeatureFlag)
- **Regressao Phase11 Notifications:** 6/6 verdes (Goal::created hook intocado)
- **Total suite completa Phase60+61+62+11:** 96/96 verdes

### Grep gates (todos passam)
```
grep -c "wherePivot('role', 'estrategista')" app/Http/Controllers/GoalController.php  # → 2 (store + update)
grep -c "public function history"           app/Http/Controllers/GoalController.php  # → 1
git diff --stat app/Models/Goal.php                                                   # → vazio
```

### route:list --path=goals
```
POST      goals                     goals.store    (sem role:admin)
PUT       goals/{goal}              goals.update   (sem role:admin — movida)
DELETE    goals/{goal}              goals.destroy  (mantem EnsureUserHasRole:admin)
GET|HEAD  goals/{goal}/history      goals.history  (sem role:admin — nova)
```

## Deviations from Plan

**Nenhuma.** Plano executado exatamente como escrito.

### Auto-ajustes (nao sao deviations — sao adaptacoes ao ambiente de teste)

**1. [Rule 3 - Blocking issue] Metric enum SQLite CHECK constraint**
- **Encontrado em:** Rodar tests pela primeira vez
- **Issue:** Migration original `create_goals_table.php` define ENUM restrito (`tacos, nps, absenteeism, contribution_margin, revenue_growth, products_without_cost, ppa_completion`). `revenue` foi adicionada em migration posterior mas SQLite trunca ENUM em CHECK constraint da migration inicial.
- **Fix:** Testes usam `metric='tacos'` (percentage-only, presente no CHECK) em vez de `revenue`. Valores ajustados de 10000/20000 pra 10.00/20.00 pra bater com `value_type=percentage`.
- **Files modificados:** `tests/Feature/Phase62/*.php`
- **Impacto zero em producao** — apenas ambiente de teste (MariaDB de producao aceita todas as metricas via migration cumulativa).

**2. [Rule 2 - Correctness] Tiebreaker no orderBy do history**
- **Encontrado em:** Test T8 (ordenacao DESC) falhou porque 3 entries no mesmo segundo
- **Issue:** `activity_log.created_at` tem granularidade de segundos; 2 updates rapidos + 1 created batem no mesmo timestamp → SQLite retornou ordem indeterministica (created primeiro).
- **Fix:** Adicionado `->orderByDesc('id')` como tiebreaker no `GoalController::history`. IDs monotonicos garantem ordem cronologica correta.
- **File:** `app/Http/Controllers/GoalController.php` linha ~197
- **Impacto:** melhoria de determinismo, sem regressao.

## Known Stubs

Nenhum stub. Toda a superficie backend esta funcional e testada.

## Threat Flags

Nenhum novo threat surface alem dos ja documentados no `<threat_model>` do PLAN. Mitigacoes aplicadas:

- **T-62-01-01 (EoP via update aberto):** mitigado por `abort_unless($canManage, 403)` — Tests T3/T4/T5 cobrem negacao.
- **T-62-01-02 (ID via history cross-empresa):** mitigado por `abort_unless($canView, 403)` no `history()` + filtro subject_id — Test T7 cobre isolamento.
- **T-62-01-03 (EoP via delete-via-toggle):** mitigado por `unset($data['active'])` pra nao-admin — Tests T11/T12 cobrem.

## Self-Check

- [x] `app/Http/Controllers/GoalController.php` modificado (auth + history)
- [x] `app/Http/Controllers/ActivityLogController.php` modificado (filtro subject_id)
- [x] `routes/web.php` modificado (rotas movidas)
- [x] `tests/Feature/Phase62/GoalUpdateAuthTest.php` criado (7 tests)
- [x] `tests/Feature/Phase62/GoalHistoryEndpointTest.php` criado (5 tests)
- [x] `tests/Feature/Phase62/ActivityLogSubjectFilterTest.php` criado (1 test)
- [x] `app/Models/Goal.php` INTOCADO (git diff vazio)
- [x] Phase60 regression 46/46 verdes
- [x] Phase61 regression 31/31 verdes
- [x] Phase11 Notifications 6/6 verdes (Goal::created hook OK)

## Self-Check: PASSED
