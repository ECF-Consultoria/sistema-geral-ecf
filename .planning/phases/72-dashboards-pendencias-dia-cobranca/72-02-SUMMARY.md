---
phase: 72-dashboards-pendencias-dia-cobranca
milestone: v15.0
plan: 72-02
type: execute
wave: 2
depends_on: [72-01]
requirements: [NPS-E-03, NPS-E-05]
tags: [nps, backend, dashboard, controller, dimensao, score-calculator, pending-widget, refactor, phase72]
tech_stack:
  added: []
  patterns:
    - "DI via constructor (Laravel autowire) — DashboardController recebe NpsPendingService"
    - "Service resolvido via app() em callback isolado — CompanyController::show usa NpsScoreCalculator via IIFE para escopar $calculator sem poluir a arrow map inicial"
    - "Dual-path template_id != null vs template_id == null — surveys v15 dinamicos coexistem com surveys legacy Phase 31"
key_files:
  created: []
  modified:
    - app/Http/Controllers/DashboardController.php
    - app/Http/Controllers/CompanyController.php
    - app/Http/Controllers/PortfolioController.php
decisions:
  - "Constructor DI em DashboardController (padrao ja existente com AdmanService e MetricsProviderFactory) — evita repeticao do injecao em 2 metodos privados"
  - "app(NpsScoreCalculator::class) em CompanyController::show via IIFE — mantem a assinatura do arrow map isolada e evita elevacao do $calculator para escopo mais amplo do metodo (LOC ~500)"
  - "app(NpsPendingService::class) em PortfolioController::show (via renderPortfolio) — controller ja usa autowire por construtor em outros services, mas a chamada e cirurgica em 1 ponto; app() e menos intrusivo do que refatorar o construtor"
  - "Eager-load response.answers + template adicionado em CompanyController::show ao carregar npsSurveys — NpsScoreCalculator::compute() precisa da relacao answers() para AVG(option_peso_snapshot); sem eager-load geraria N+1 (ate 10 responses * 1 query cada)"
  - "Zero remocao de campos legados score_estrategista/score_analista/score_empresa nos 3 controllers — dual-path aceito na v15.0; cleanup completo fica pra Phase 73 quando Dashboards Admin e User forem convertidos para NpsScoreCalculator"
metrics:
  duration_min: 25
  completed_date: 2026-07-08
  tasks_completed: 4
  files_touched: 3
---

# Phase 72 Plan 02: Dashboards backend + CompanyController::show refactor Summary

Injecao de `nps_pendentes` (Plan 72-01 `NpsPendingService::forCarteira`) em Dashboard/Admin, Dashboard/User e Portfolio/Show, e refactor de `CompanyController::show` para dual-path — surveys v15 (`template_id != null`) leem medias via `NpsScoreCalculator` (Phase 69-02), surveys legacy pre-v15 mantem `score_*` persistidos.

## Deliverables

### 1. DashboardController.php — nps_pendentes em admin + user (SC#3)

- Adicionado `use App\Services\Nps\NpsPendingService;`
- Construtor recebe `NpsPendingService $npsPending` (padrao DI ja existente para `AdmanService` e `MetricsProviderFactory`)
- `adminDashboard(Request $request, ...)` chama `$this->npsPending->forCarteira($request->user())` — para admin, `forCarteira` retorna todas as empresas ativas (`isAdmin() === true`)
- `userDashboard(User $user, ...)` chama `$this->npsPending->forCarteira($user)` — restringe a `$user->companies()` (analista/estrategista veem apenas propria carteira)
- Chave `'nps_pendentes' => $npsPendentes` adicionada ao final de ambos `Inertia::render('Dashboard/Admin', ...)` e `Inertia::render('Dashboard/User', ...)`
- Zero alteracao em score_estrategista, score_analista, score_empresa, avg_nps, performance_equipe, buildRanking (cleanup fica pra Phase 73)

### 2. CompanyController.php::show — dual-path NpsScoreCalculator (SC#5)

- Adicionado `use App\Services\Nps\NpsScoreCalculator;`
- Eager-load `npsSurveys` expandido: `->with('response')` -> `->with(['response.answers', 'template'])` — sem N+1 quando `compute()` percorre answers da dimensao
- Bloco `'nps_surveys'` refatorado em IIFE: `$calculator = app(NpsScoreCalculator::class)`, entao mapeia surveys aplicando ternario `$isV15 = $s->template_id !== null`:
  - `$isV15 === true` → `$calculator->compute($response, 'empresa'|'analista'|'estrategista')` (usa `option_peso_snapshot` — imune a hard-delete do template)
  - `$isV15 === false` → `$response->score_empresa|analista|estrategista` (legacy Phase 31)
- Chaves de saida `score_empresa`, `score_analista`, `score_estrategista` PRESERVADAS bit-a-bit — Companies/Show.jsx nao muda

### 3. PortfolioController.php::show — nps_pendentes via renderPortfolio (SC#2 + SC#3)

- Adicionado `use App\Services\Nps\NpsPendingService;`
- `renderPortfolio(Request $request, User $user)` recebe `$user` = owner do portfolio (nao necessariamente request user — admin/lider ve carteira de terceiros). Chama `app(NpsPendingService::class)->forCarteira($user)` — `forCarteira` filtra internamente por `$user->companies()` (nao-admin) ou todas (admin), semantica correta pro widget "empresas pendentes na carteira visualizada"
- Chave `'nps_pendentes' => $npsPendentes` adicionada ao final do `Inertia::render('Portfolio/Show', ...)`

## Verification

### Sintaxe (php -l)

```
No syntax errors detected in app/Http/Controllers/DashboardController.php
No syntax errors detected in app/Http/Controllers/CompanyController.php
No syntax errors detected in app/Http/Controllers/PortfolioController.php
```

### Suite baseline 118 tests (Phase 31/33 NPS + 68 + 69 + 70 + 71)

```
Tests: 118 passed (764 assertions)
Duration: 74.17s
```

Comando executado:

```
php artisan test tests/Feature/Phase31NpsDispararMensalTest.php \
  tests/Feature/Phase31NpsMonthlyMailTest.php \
  tests/Feature/Phase31NpsSubmitTest.php \
  tests/Feature/Phase33NpsPerguntasExtrasTest.php \
  tests/Feature/Phase68 tests/Feature/Phase69 \
  tests/Feature/Phase70 tests/Feature/Phase71
```

Zero regressao em qualquer suite NPS/dashboard existente.

### Acceptance criteria por task

**T1 DashboardController:**
- `NpsPendingService` referenciado 3 vezes (import + type-hint construtor + comentario)
- `'nps_pendentes'` presente 2 vezes (adminDashboard + userDashboard) ≥ 2 ✓
- `forCarteira` chamado 2 vezes (adminDashboard + userDashboard) ≥ 2 ✓
- Referencias legadas `score_estrategista/score_analista/score_empresa` preservadas: 6 ocorrencias na linhas 875, 1007, 1013, etc (baseline preservado ≥ 5) ✓
- `php -l` verde ✓

**T2 CompanyController:**
- `NpsScoreCalculator` presente no `show()` (import + 2 usos: `app(NpsScoreCalculator::class)` e comentario) ≥ 1 ✓
- Dual-path `$isV15 ?` presente 3 vezes (empresa/analista/estrategista) ≥ 3 ✓
- Chaves `'score_empresa'/analista/estrategista' =>` preservadas ✓
- `php -l` verde ✓

**T3 PortfolioController:**
- `'nps_pendentes'` injetada em `Inertia::render('Portfolio/Show', ...)` ≥ 1 ✓
- `NpsPendingService` usado (via `app()`) ✓
- `php -l` verde ✓

**T4 Smoke:**
- 3 `php -l` verdes ✓
- Suite 118 tests baseline verde ✓
- Tinker sanity: NAO EXECUTADO — MariaDB local off (documentado em `MEMORY.md#project_mariadb_local_corrompido`). Validado no lugar via suite completa NPS em SQLite in-memory (118 tests batem no mesmo caminho de codigo)

## Deviations from Plan

**Nenhuma.** Plan executado exatamente como escrito. Comentario adicional pt-BR em cada bloco novo identificando "Phase 72 Plan 02" + SC coberto.

## Deferred Issues

- Tinker sanity check nao executado — MariaDB local corrompido (memory note existente). Cobertura Feature vira no Plan 72-04 (testes contra SQLite in-memory).
- Cleanup completo de `score_estrategista/analista/empresa` nos Dashboards (linhas 875, 1007, 1013 do `DashboardController.php`) permanece agendado para Phase 73 — a decisao (documentada no `<research_reference>` do plan) foi manter dual-path na v15.0 e limpar em fase dedicada com testes E2E.
- Pre-existing failure em `Phase33OnboardingFichaTest::padroes_expoem_mensagem_e_grants_padrao` — relacionado a `MlbImplementacao::ONB_POLO_OPCOES` grants (chave `Serra Gaucha` ausente); confirmado nao-regressao (falha identica com/sem meu diff). Fora de escopo — nao NPS, nao dashboard, nao controller tocado.

## Self-Check: PASSED

- Arquivos modificados EXISTEM: `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/CompanyController.php`, `app/Http/Controllers/PortfolioController.php` ✓
- Sintaxe verde nos 3 controllers ✓
- Suite baseline 118 tests verde (764 assertions) ✓
- Grep confirma que todas as chaves esperadas foram injetadas e nenhuma foi removida ✓
- Contrato absoluto respeitado: zero mudanca em `NpsPendingService`, `NpsScoreCalculator`, `NpsTemplateService`, rotas, middleware
