---
phase: 58-dashboard-ecf-agregado-shells-por-marketplace
plan: 01
subsystem: dashboard
tags: [laravel, inertia, routing, whitelist-validation, multi-marketplace]

# Dependency graph
requires:
  - phase: 57-modelo-de-dados-multi-marketplace
    provides: coluna companies.marketplace + tabela company_marketplaces (N:N) usadas como base do filtro flat
provides:
  - 4 rotas GET nomeadas (ecf.dashboard, mercadolivre.dashboard, shopee.dashboard, amazon.dashboard) no grupo auth+verified
  - 4 metodos publicos no DashboardController (ecf, mercadolivre, shopee, amazon)
  - Filtro opcional ?marketplace= com whitelist (meli/shopee/amazon) em adminDashboard() e userDashboard()
  - Suite de testes Feature Phase58 (11 tests) cobrindo rotas, filtro e contrato Inertia dos shells
affects: [58-02-shells-jsx, 58-03-nav-tree]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Rotas dedicadas delegando para index() existente via merge de request (mercadolivre forca marketplace=meli)"
    - "Shells renderizam Inertia::render direto (bypass do pipeline agregado) para evitar KPIs zerados enganosos"
    - "Teste de contrato Inertia via X-Inertia + X-Inertia-Version + assertJson (evita depender do manifest Vite de paginas ainda nao criadas)"

key-files:
  created:
    - tests/Feature/Phase58/DashboardRoutesTest.php
    - tests/Feature/Phase58/DashboardFilterTest.php
    - tests/Feature/Phase58/DashboardShellsBackendTest.php
  modified:
    - routes/web.php
    - app/Http/Controllers/DashboardController.php

key-decisions:
  - "Whitelist de ?marketplace= via $request->validate(['marketplace' => 'nullable|string|in:meli,shopee,amazon']) em ecf() e mercadolivre() (threat T-58-01)"
  - "Filtro aplicado via coluna flat companies.marketplace (indice Phase 18.5) — pivot CompanyMarketplace fica para v14+"
  - "userDashboard() filtra a Collection ja carregada (sem re-query) pois a carteira do usuario ja e restrita"
  - "Testes de shell usam assertJson() no payload Inertia (X-Inertia header) em vez de assertInertia()/assertViewHas, pois os componentes JSX reais (Plan 58-02) ainda nao existem no manifest Vite"

patterns-established:
  - "Teste de rota Inertia full-page sem componente JSX pronto: enviar X-Inertia + X-Inertia-Version calculado via HandleInertiaRequests::version() para evitar 409 e o crash de manifest"

requirements-completed: [DASH-01, DASH-02, DASH-03]

# Metrics
duration: ~20min
completed: 2026-07-03
---

# Phase 58 Plan 01: Rotas Dashboard multi-marketplace (backend) Summary

**4 rotas GET nomeadas + 4 metodos no DashboardController (ecf/mercadolivre delegam ao pipeline existente, shopee/amazon renderizam shells direto) + filtro `?marketplace=` com whitelist contra injection, mantendo zero regressao nos 20 tests baseline da Phase 57.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-07-03T20:47:25Z (aprox., inicio da execucao)
- **Completed:** 2026-07-03T21:06:30Z
- **Tasks:** 3 (RED, GREEN, gate de regressao)
- **Files modified:** 5 (2 backend + 3 testes)

## Accomplishments
- 4 rotas novas nomeadas (`ecf.dashboard`, `mercadolivre.dashboard`, `shopee.dashboard`, `amazon.dashboard`) registradas dentro do grupo `auth+verified`, com a rota legacy `/dashboard` preservada como fallback de deep links.
- 4 metodos publicos no `DashboardController`: `ecf()`/`mercadolivre()` delegam ao `index()` existente (zero duplicacao de logica de agregacao); `shopee()`/`amazon()` renderizam `Inertia::render('Dashboard/ShopeeShell'|'Dashboard/AmazonShell', ...)` diretamente, preparando o terreno visual para o Plan 58-02.
- Filtro opcional `?marketplace=` implementado com whitelist (`in:meli,shopee,amazon`) validada via `$request->validate()` antes de qualquer query — mitiga o threat T-58-01 (injection via query string).
- Filtro aplicado em `adminDashboard()` (query base `$companiesQuery` + dropdown `$allCompanies`) e em `userDashboard()` (filtro em Collection ja carregada, sem re-query extra).
- 11 tests Feature novos em `tests/Feature/Phase58/` cobrindo as 5 rotas, o whitelist do filtro (3 valores aceitos + 1 rejeitado com 422) e o contrato Inertia dos 2 shells backend.
- Gate de zero regressao confirmado: **31/31 tests verdes** (20 Phase 57 + 11 Phase 58).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Feature tests RED** - `90dc26e` (test) — 3 arquivos novos, 11 tests (10 falhando por rota inexistente, 1 passando por ser a rota legacy ja ativa).
2. **Fix intermediario nos testes** - `a24bd8f` (fix) — correcao de headers de teste para refletir o comportamento real do Inertia (ver Deviations).
3. **Task 2: GREEN** - `9e9d9be` (feat) — 4 rotas + 4 metodos + filtro whitelist; 11/11 tests Phase58 verdes.
4. **Task 3: Gate de regressao** - sem commit adicional (apenas validacao); 20/20 Phase 57 + 11/11 Phase 58 confirmados.

**Plan metadata:** (commit deste SUMMARY + STATE/ROADMAP, feito a seguir)

## Files Created/Modified
- `routes/web.php` - 4 rotas GET nomeadas apos a rota `/dashboard` legacy, dentro do grupo `auth+verified`.
- `app/Http/Controllers/DashboardController.php` - 4 metodos publicos (`ecf`, `mercadolivre`, `shopee`, `amazon`) + leitura/aplicacao do filtro `marketplace` em `adminDashboard()` e `userDashboard()`.
- `tests/Feature/Phase58/DashboardRoutesTest.php` - 5 tests das rotas (4 novas + legacy).
- `tests/Feature/Phase58/DashboardFilterTest.php` - 4 tests do whitelist do filtro.
- `tests/Feature/Phase58/DashboardShellsBackendTest.php` - 2 tests do contrato Inertia dos shells (component + props).

## Decisions Made
- Filtro por coluna flat `companies.marketplace` (nao pivot `CompanyMarketplace`) por performance — decisao ja travada no CONTEXT §2, mantida sem alteracao.
- `userDashboard()` recebeu a mudanca minima possivel: filtro aplicado na Collection ja carregada via `request()->get('marketplace')`, sem alterar a assinatura do metodo nem adicionar `Request $request` como parametro (preserva zero-mudanca no `index()` que o chama).
- Testes de contrato Inertia usam `assertJson()` sobre o payload retornado com header `X-Inertia`, em vez de `assertInertia()`/`assertViewHas('page')` — decisao tecnica descoberta durante a execucao (ver Deviations).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Teste de whitelist invalido usava `get()` em vez de `getJson()`**
- **Found during:** Task 2 (GREEN)
- **Issue:** `$request->validate()` sem header `X-Requested-With`/`Accept: application/json` faz o Laravel devolver redirect 302 (nao 422) para `ValidationException` — o teste escrito na Task 1 assumia 422 direto via `get()` simples, mas isso so acontece quando a requisicao "parece" AJAX/JSON (como o axios do Inertia sempre envia `X-Requested-With: XMLHttpRequest` em producao).
- **Fix:** Trocado `get()` por `getJson()` no teste `test_filter_marketplace_invalido_rejeitado`, reproduzindo fielmente o que acontece numa navegacao real via Inertia.
- **Files modified:** `tests/Feature/Phase58/DashboardFilterTest.php`
- **Verification:** Teste passa com `assertStatus(422)`.
- **Committed in:** `a24bd8f`

**2. [Rule 1 - Bug] Rotas shopee/amazon com `get()` simples quebravam por manifest Vite ausente**
- **Found during:** Task 2 (GREEN)
- **Issue:** `Inertia::render('Dashboard/ShopeeShell', ...)` numa requisicao full-page (sem header `X-Inertia`) forca o Blade `@vite(["...ShopeeShell.jsx"])` a resolver o manifest de assets — como o componente JSX real so sera criado no Plan 58-02, a resposta quebrava com "Unable to locate file in Vite manifest".
- **Fix:** Testes de rota para shopee/amazon passaram a enviar `X-Inertia: true` + `X-Inertia-Version` (calculado via `HandleInertiaRequests::version()`) simulando uma navegacao client-side real, que bypassa o Blade e devolve o payload JSON puro do Inertia sem tocar no manifest.
- **Files modified:** `tests/Feature/Phase58/DashboardRoutesTest.php`
- **Verification:** `dashboard shopee route retorna 200` e `dashboard amazon route retorna 200` passam.
- **Committed in:** `a24bd8f`

**3. [Rule 1 - Bug] `assertInertia()` da lib exige response HTML, incompativel com o cenario "sem JSX ainda"**
- **Found during:** Task 2 (GREEN)
- **Issue:** O helper `assertInertia()` do pacote `inertiajs/inertia-laravel` chama internamente `$response->assertViewHas('page')`, o que so funciona para responses HTML full-page (nao para o JSON devolvido com header `X-Inertia`). Como o Plan explicitamente diz que os JSX shells ainda nao existem nesta plan, usar `assertInertia()` era inviavel sem antes criar os componentes (fora de escopo do Plan 58-01).
- **Fix:** Testes de `DashboardShellsBackendTest` reescritos para inspecionar o payload JSON diretamente via `assertJson(['component' => ..., 'props' => [...]])`, validando exatamente o mesmo contrato (nome do componente + props `marketplace`/`label`) sem depender do manifest Vite.
- **Files modified:** `tests/Feature/Phase58/DashboardShellsBackendTest.php`
- **Verification:** `shopee shell renderiza componente e props` e `amazon shell renderiza componente e props` passam.
- **Committed in:** `a24bd8f`

---

**Total deviations:** 3 auto-fixed (Rule 1 — bugs nas premissas de teste da Task 1, corrigidas na Task 2)
**Impact on plan:** Nenhuma mudanca de escopo do backend (routes/web.php + DashboardController.php ficaram exatamente como planejado). As correcoes foram inteiramente nos arquivos de teste, alinhando as asserções ao comportamento real do framework Inertia. Nenhum arquivo JSX foi criado — a suposicao do plan de que os shells backend podem ser testados "so pelo contrato" se confirmou, mas a tecnica de teste teve que mudar de `assertInertia()` para `assertJson()` com headers corretos.

## Issues Encountered
- Nenhum bloqueio de infraestrutura: os testes rodam 100% contra SQLite `:memory:` (config do `phpunit.xml`), entao a indisponibilidade do MariaDB local (nota conhecida do projeto) nao afetou esta execucao.

## User Setup Required
None - nenhuma configuracao de servico externo necessaria.

## Next Phase Readiness
- Plan 58-02 (shells JSX) pode criar `resources/js/Pages/Dashboard/ShopeeShell.jsx` e `Dashboard/AmazonShell.jsx` livremente — as rotas e props (`marketplace`, `label`) ja estao prontas e testadas no backend.
- Plan 58-03 (NAV_TREE) pode referenciar `route('ecf.dashboard')` e `route('mercadolivre.dashboard')` com seguranca — nomes de rota confirmados via `php artisan route:list --path=dashboard`.
- Apos o Plan 58-02 criar os componentes JSX reais e rodar `npm run build`, os testes `DashboardRoutesTest::test_dashboard_shopee_route_retorna_200` e `test_dashboard_amazon_route_retorna_200` (que ja usam header `X-Inertia`) continuarao passando sem alteracao; nenhum ajuste adicional de teste e esperado quando o manifest for atualizado.
- Nenhum blocker conhecido.

## Known Stubs
Nenhum stub introduzido — os metodos `shopee()`/`amazon()` retornam props reais (`marketplace`, `label`) consumidas pelos testes; os componentes JSX que vao renderizar essas props ficam para o Plan 58-02 (documentado explicitamente no CONTEXT §3 e nesta Summary, nao e um stub silencioso).

## Self-Check: PASSED

Todos os arquivos criados e commits referenciados foram verificados como existentes:
- `tests/Feature/Phase58/DashboardRoutesTest.php` — FOUND
- `tests/Feature/Phase58/DashboardFilterTest.php` — FOUND
- `tests/Feature/Phase58/DashboardShellsBackendTest.php` — FOUND
- `.planning/phases/58-dashboard-ecf-agregado-shells-por-marketplace/58-01-SUMMARY.md` — FOUND
- Commits `90dc26e`, `a24bd8f`, `9e9d9be`, `ea6f009` — FOUND em `git log --oneline --all`

---
*Phase: 58-dashboard-ecf-agregado-shells-por-marketplace*
*Completed: 2026-07-03*
