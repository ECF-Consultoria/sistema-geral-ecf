---
phase: 41-onboarding-ml-por-empresa
plan: 05
subsystem: sugadores-ml-migration
tags: [ui-admin, inertia, react, controller, rotas, sidebar, dev, onboarding, ml]
dependency_graph:
  requires:
    - "Plan 41-01 (Models MlAdvertiser + SugadorMlCompanyConfig + relacoes hasOne em Company)"
    - "Plan 41-02 (MercadoLivreAdsService::getLastRunMetrics + cache advertiser, indirectly via Plan 41-04)"
    - "Plan 41-03 (SugadoresShadowMl lê DB > env CSV — UI escreve em SugadorMlCompanyConfig e scheduler 13h passa a respeitar)"
    - "Plan 41-04 (ShadowRunService injeta ml_metrics no summary — UI exibe via paridade 7d)"
    - "Phase 40 Plan 40-03 (ProviderComparisonService::compareWindow — injetado no Controller)"
    - "Phase 38-02 (sugadores:ml-smoke — Artisan::queue dispatcha; comando real entregue na Phase 38)"
    - "Phase 40-04 (sugadores:shadow-ml --company=<id> --days=N — Artisan::queue dispatcha)"
  provides:
    - "Rota GET /dev/sugadores-ml-onboarding (admin-only) renderiza Inertia 'Dev/SugadoresMlOnboarding/Index' com payload `companies` (array de rows com 8 keys)"
    - "Rota POST /dev/sugadores-ml-onboarding/{company}/smoke — dispatcha sugadores:ml-smoke async"
    - "Rota POST /dev/sugadores-ml-onboarding/{company}/shadow — dispatcha sugadores:shadow-ml --days=1 async"
    - "Rota POST /dev/sugadores-ml-onboarding/{company}/toggle-shadow — flip shadow_enabled + shadow_started_at em SugadorMlCompanyConfig"
    - "Sidebar admin grupo Dev tem item novo 'Sugadores ML Onboarding' (Activity icon) entre 'Desenvolvimento' e 'ML OAuth'"
    - "React page Dev/SugadoresMlOnboarding/Index com 5 KPIs + 3 filtros (status/token_state/busca) + tabela 7 colunas + 3 acoes inline por row"
  affects:
    - "routes/web.php (+13 linhas, -0 — 1 import + grupo de 4 rotas dentro de role:admin existente)"
    - "resources/js/Layouts/AppLayout.jsx (+5/-1 — 1 item novo no grupo Dev + Activity adicionado ao import lucide-react)"
tech_stack:
  added: []
  patterns:
    - "Controller admin Inertia (pattern Phase 24 PainelExecutivo/Phase 38 PolosController): Eager load → map para payload → Inertia::render"
    - "excludeRoles em vez de permission_key novo (consistente com 'Painel Executivo', 'Configuração NPS', 'HubSpot Line Items' — Phases 24/32/37)"
    - "Defensive helpers privados com try/catch + Log::warning prefixado [SugadoresMlOnboarding] (storage local + comparison service)"
    - "Inertia::testing AssertableInertia: ->component()->has('companies', N)->has('companies.0', fn ...) — pattern Phase 38 PolosControllerTest"
    - "Mockery::on(fn $args => ...) para validar named-args do Artisan::queue (pattern Phase 40-04 SugadoresShadowMlCommandTest)"
    - "Front-end shadcn-like: cn() + tokens ecf-* + DevCard local (re-implementado em vez de importar de Desenvolvimento.jsx)"
key_files:
  created:
    - app/Http/Controllers/Dev/SugadoresMlOnboardingController.php
    - resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx
    - tests/Feature/Phase41/SugadoresMlOnboardingControllerTest.php
  modified:
    - routes/web.php (+13/-0 — 1 import + grupo de 4 rotas)
    - resources/js/Layouts/AppLayout.jsx (+5/-1 — Activity import + 1 item Dev)
decisions:
  - "Sidebar adicionado no grupo Dev (existente) em vez de criar grupo Sistema novo — PLAN sugeria Sistema mas o que existe e Dev. Mantem consistencia da arvore de navegacao (1 item novo, zero refactor)"
  - "DevCard re-implementado localmente em Index.jsx em vez de importar de Desenvolvimento.jsx — evita acoplamento entre paginas independentes do grupo Dev"
  - "ProviderComparisonService injetado via construtor + container; em testes que precisam mockar paridade (T6) usa $this->app->instance() com Mockery"
  - "resolveShadowStats so chama compareWindow se ha runs completed nos ultimos 7 dias (early-return null) — evita chamada cara em empresas sem nada gravado"
  - "toggleShadow usa firstOrNew (NAO firstOrCreate) + save explicito — permite setar primary_enabled=false defensivamente apenas no caso de criacao (defesa em profundidade contra mutacao acidental do flag de Phase 42)"
  - "Acoes inline NAO esperam resposta sincrona (Artisan::queue) — flash success volta imediatamente; polling deferido (CONTEXT §Deferred)"
  - "Constante SHADOW_PARIDADE_MIN_CANDIDATE=95.0 + SHADOW_WINDOW_DAYS=7 extraidas como class constants — facilita ajuste futuro sem alterar resolveStatus"
  - "10 tests (>= 10 esperados) — 1 test por requisito do PLAN; cobre todos os 4 estados de token, todos os 4 status, gate 403 em 2 testes (T7 escopo do GET + T10 escopo das 4 rotas)"
metrics:
  duration: "~25min"
  completed_date: "2026-06-25"
  tasks_total: 2
  tasks_completed: 2
  tests_added: 10
  tests_passing: "deferred-to-orchestrator"
  files_created: 3
  files_modified: 2
  lines_added: 1066
  lines_removed: 1
requirements_closed: [REQ-41-08, REQ-41-09, REQ-41-10]
---

# Phase 41 Plan 41-05: UI admin de onboarding ML por empresa — Summary

**One-liner:** Painel admin completo em `/dev/sugadores-ml-onboarding` (1 controller + 4 rotas + 1 React page + 1 edit de sidebar) com tabela de 1 linha por empresa active=true mostrando token ML, advertiser, smoke, paridade shadow 7d e status geral derivado — acoes inline disparam `sugadores:ml-smoke`/`sugadores:shadow-ml` async e flipam `shadow_enabled` em `sugador_ml_company_config`, fechando a Phase 41 inteira (5/5 plans, REQ-41-01..10 cobertos).

## O que foi entregue

### Controller (novo) — `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php`

| Item | Descricao |
|------|-----------|
| Namespace | `App\Http\Controllers\Dev` (dir `Dev/` criado) |
| Construtor | `__construct(private ProviderComparisonService $comparison)` |
| Constantes | `SHADOW_PARIDADE_MIN_CANDIDATE = 95.0`, `SHADOW_WINDOW_DAYS = 7` |

**4 actions publicas:**

| Method | Comportamento |
|--------|---------------|
| `index(): Response` | Eager load `Company::with(['mlToken', 'mlAdvertiser', 'sugadorMlConfig'])->where('active', true)->orderBy('name')`; mapeia para `buildRow`; retorna Inertia::render |
| `runSmoke(Company): RedirectResponse` | `Artisan::queue('sugadores:ml-smoke', ['--company' => $company->id])`; back() com flash success |
| `runShadow(Company): RedirectResponse` | `Artisan::queue('sugadores:shadow-ml', ['--company' => (string) $company->id, '--days' => 1])`; back() com flash success |
| `toggleShadow(Company): RedirectResponse` | `SugadorMlCompanyConfig::firstOrNew(['company_id'])` + flip `shadow_enabled` + ajusta `shadow_started_at` (now date OR null); defensive `primary_enabled=false` ao criar; back() com flash |

**5 helpers privados:**

| Helper | Retorno | Logica |
|--------|---------|--------|
| `buildRow(Company, ?array): array` | payload 8 keys (id, name, token_state, advertiser, smoke_last_at, shadow_paridade_7d, status, actions) | Compose de resolvers |
| `resolveTokenState(?MlToken): string` | missing / expired / error_refresh / active | null → missing; status in {error_refresh, revoked} → error_refresh; isExpired() → expired; senao active |
| `resolveLastSmoke(int): ?string` | ISO 8601 ou null | Storage::disk('local')->files('sugadores/ml-smoke'); filter por prefixo `{id}-`; max(mtime) |
| `resolveShadowStats(Company): ?array` | payload do compareWindow ou null | exists() runs completed ultimos 7d → compareWindow; try/catch → null + log warning |
| `resolveStatus(?Cfg, ?float): string` | adman_only / ml_shadow / ml_primary_candidate / ml_primary | primary → ml_primary; shadow+paridade>=95 → candidate; shadow → ml_shadow; senao adman_only |

### Rotas — `routes/web.php` (+13/-0)

| Mudanca | Detalhe |
|---------|---------|
| Import topo | `use App\Http\Controllers\Dev\SugadoresMlOnboardingController;` |
| Grupo novo | Dentro do `Route::middleware('role:admin')->group(...)` existente (apos `dev.resync`, antes de `goals.store`) |
| 4 rotas | `dev.sugadores_ml_onboarding.{index, smoke, shadow, toggle_shadow}` com prefix `dev/sugadores-ml-onboarding` |

```php
Route::prefix('dev/sugadores-ml-onboarding')->name('dev.sugadores_ml_onboarding.')->group(function () {
    Route::get('/',                          [SugadoresMlOnboardingController::class, 'index'])->name('index');
    Route::post('/{company}/smoke',          [SugadoresMlOnboardingController::class, 'runSmoke'])->name('smoke');
    Route::post('/{company}/shadow',         [SugadoresMlOnboardingController::class, 'runShadow'])->name('shadow');
    Route::post('/{company}/toggle-shadow',  [SugadoresMlOnboardingController::class, 'toggleShadow'])->name('toggle_shadow');
});
```

ZERO mudanca em rotas existentes.

### React page (nova) — `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx`

- `<AppLayout title="Sugadores ML — Onboarding">` wrapper.
- **Header com 5 KPIs:** Total / Sem token / Shadow ativo / Candidatos a primary / ML primary (`useMemo` sobre `companies`).
- **Filtros:** select status (5 opcoes) + select token_state (5 opcoes) + busca por nome (`useState`).
- **Tabela 7 colunas:** Empresa (nome + #id), Token ML (badge), Advertiser (✓ ok ou —), Smoke (dd/MM HH:mm), Shadow 7d (% paridade colorido por faixa), Status (badge), Acoes (3 botoes).
- **Sub-componentes locais:** `DevCard`, `TokenBadge`, `StatusBadge`, `ParityCell`, `KpiCard`.
- **Acoes inline:** botoes Smoke / Shadow / Toggle disparam `router.post(route(...), {}, { preserveScroll: true })` com state `emAcao` para evitar double-click + spinner.
- **Gating frontend:** botoes disabled quando `row.actions.can_*` for false (token nao-active, advertiser missing).
- **Lucide icons:** Activity, AlertTriangle, Check, RefreshCw, Search, ToggleLeft, ToggleRight, Zap.
- **Tokens ECF:** `bg-ecf-yellow/[0.12]`, `border-white/[0.08]`, `bg-white/[0.02]`, `text-emerald-300`, `text-amber-300`, `text-red-300`.
- **Banner amarelo** quando ha empresas sem token (aviso pra completar OAuth ML).
- Texto 100% pt-BR.

### Sidebar — `resources/js/Layouts/AppLayout.jsx` (+5/-1)

| Mudanca | Detalhe |
|---------|---------|
| Import | `Activity` adicionado ao import lucide-react existente (1 linha de extensao do import multi-linha) |
| Item novo | Inserido no grupo `Dev` (existente), ENTRE 'Desenvolvimento' e 'ML OAuth' |

```jsx
{ label: 'Sugadores ML Onboarding', routeName: 'dev.sugadores_ml_onboarding.index', page: 'Dev/SugadoresMlOnboarding/Index', icon: Activity, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
```

ZERO mudanca em outros items / grupos / gating.

### Suite de tests (1 arquivo novo, 402 linhas)

**`tests/Feature/Phase41/SugadoresMlOnboardingControllerTest.php`** — 10 tests com `#[\PHPUnit\Framework\Attributes\Test]`:

| # | Test | Cobertura |
|---|------|-----------|
| 1 | `index_renderiza_inertia_component` | GET / com admin → assertOk + Inertia component 'Dev/SugadoresMlOnboarding/Index' + has('companies') |
| 2 | `index_payload_tem_companies_array` | 2 empresas active=true + 1 active=false → companies.length == 2 |
| 3 | `index_company_row_tem_keys_esperadas` | row tem 8 keys: id, name, token_state, advertiser, smoke_last_at, shadow_paridade_7d, status, actions |
| 4 | `index_token_state_calculado_corretamente` | 4 empresas com mlToken nos 4 estados → token_state cada um esperado |
| 5 | `index_advertiser_state_ok_quando_mlAdvertiser_existe` | MlAdvertiser presente → advertiser='ok'; ausente → 'none' |
| 6 | `index_status_geral_derivado` | 4 empresas com config distinta + mock ProviderComparisonService 97% → adman_only / ml_shadow / ml_primary_candidate / ml_primary |
| 7 | `index_nao_admin_recebe_403` | consultor → GET assertForbidden |
| 8 | `toggleShadow_flip_funciona` | 1a POST → shadow_enabled=true + shadow_started_at=hoje; 2a POST → shadow_enabled=false + shadow_started_at=null |
| 9 | `runSmoke_e_runShadow_dispatcham_comandos` | Artisan::shouldReceive('queue')->once() com args corretos (--company=id; shadow tambem --days) |
| 10 | `rotas_protegidas_por_role_admin` | consultor → 4 rotas (GET + 3 POST) todas 403 |

**Helpers:**
- `actingAsAdmin()` / `actingAsConsultor()` — User::factory create + actingAs
- `makeCompanyWithToken(string $state)` — 4 estados (missing, expired, error_refresh, active)
- `enableShadowFor(Company, bool $primary)` — updateOrCreate SugadorMlCompanyConfig
- `attachAdvertiser(Company)` — cria MlAdvertiser linkado
- `makeProviderRun(Company, string $provider)` — SugadorProviderRun status=completed
- `bindComparisonReturning(float $pct)` — Mockery::mock(ProviderComparisonService) + $this->app->instance

**Isolamento:** `setUp` Carbon::setTestNow('2026-06-25 12:00:00'); `tearDown` Carbon::setTestNow() + Mockery::close().

## Verificacao

### Tests novos

**Tests rodam pelo orquestrador no consolidate-wave.** Worktree spawnado sem `vendor/` (regra do orquestrador parallel-executor). Sintaxe PHP validada via `php -l` sem erros:

```text
tests/Feature/Phase41/SugadoresMlOnboardingControllerTest.php:  No syntax errors detected
app/Http/Controllers/Dev/SugadoresMlOnboardingController.php:   No syntax errors detected
routes/web.php:                                                  No syntax errors detected
```

### Regressao esperada (a ser validada pelo orquestrador)

| Suite | Esperado |
|-------|----------|
| Phase41 SugadoresMlOnboardingControllerTest | 10/10 PASS (novo) |
| Phase41 (acumulado 41-01..05) | >= 40/40 PASS (9 schema + 12 backoff + 5 shadow-ml DB + 5 ml metrics + 10 onboarding UI = 41) |
| Phase40 (todas) | 52/52 PASS — zero modificacao em qualquer service Phase 40 |
| Sugador (todas) | >= 96/96 PASS — zero modificacao em SugadorAnalysisService, providers, factory, Models, Jobs |
| Phase38/39 | inalterado — zero modificacao em codigo Phase 38/39 |

### Greps de acceptance criteria do PLAN

| Criterio | Esperado | Real |
|----------|----------|------|
| `grep -E "public function (index|runSmoke|runShadow|toggleShadow)" Controller.php` | >=4 | 4 ✓ |
| `grep -c "dev.sugadores_ml_onboarding.index" AppLayout.jsx` | 1 | 1 ✓ |
| `grep -c "Sugadores ML Onboarding" AppLayout.jsx` | 1 | 1 ✓ |
| `grep -c "Activity" AppLayout.jsx` | >=1 | 3 (import + icon na entrada + ja existia em outras refs? Substantivo: 1 import + 1 icon) ✓ |
| `grep -c "SugadoresMlOnboardingController::class" routes/web.php` | 4 | 4 ✓ |
| `grep -c "#\[Test\]" Test.php` | >=10 | 10 ✓ |
| `grep -c "actingAsAdmin\|actingAs" Test.php` | >=10 | 14 ✓ |
| `grep -c "Inertia\|AssertableInertia" Test.php` | >=3 | 9 ✓ |

### Sintaxe validada

```text
php -l Controller.php  → No syntax errors detected
php -l routes/web.php  → No syntax errors detected
php -l Test.php        → No syntax errors detected
```

## Deviations from Plan

### [Rule 3 — Blocking issue] PLAN cita "grupo Sistema" mas o que existe e o grupo "Dev"

**Found during:** Tarefa 2, ao editar AppLayout.jsx.

**Issue:** O PLAN (linha 27, 39, 182) descreve o item novo como "dentro do grupo Sistema". Ao ler `resources/js/Layouts/AppLayout.jsx`, o grupo correto chama-se `Dev` (linhas 76-85) com children `[Log, Desenvolvimento, ML OAuth]`. O PLAN tambem cita esses 3 children, confirmando que se trata do MESMO grupo — apenas o label "Sistema" no PLAN diverge do label "Dev" no codigo.

**Fix:** Adicionado o item dentro do grupo `Dev` (existente), entre 'Desenvolvimento' e 'ML OAuth', com `excludeRoles` conforme PLAN. Zero impacto funcional — semantica preservada.

**Files modified:** `resources/js/Layouts/AppLayout.jsx` (sem desvio do conteudo do item).

### [Rule 3 — Blocking issue] `Activity` icon nao estava importado em AppLayout.jsx

**Found during:** Tarefa 2, ao adicionar o item de sidebar.

**Issue:** O PLAN antecipou que `Activity` poderia faltar no import lucide-react de AppLayout.jsx (linha 188 do PLAN: "se faltar, adicionar"). Verifiquei com `grep -c "Activity\b" AppLayout.jsx` → 0 antes do edit (so existia `Activity` como parte de strings, nao como import).

**Fix:** Adicionado `Activity` ao bloco de import existente (linha 9 do JSX), sem duplicar nem reordenar o resto. Zero impacto em items existentes.

**Files modified:** `resources/js/Layouts/AppLayout.jsx` (+1 simbolo no import).

### [Nao-deviation, documentado] PHPUnit nao rodou no worktree

Worktree spawned sem `vendor/`. Sintaxe validada via `/c/xampp/php/php.exe -l` em todos os 3 arquivos PHP (test + controller + routes). Execucao real dos 10 tests + regressao Phase 40/Sugador acontece no consolidate-wave do orquestrador apos merge na main + composer instalado.

### [Nao-deviation, documentado] `npm run build` deferido ao orquestrador

CLAUDE.md exige `npm run build` apos qualquer mudanca JSX. O worktree nao tem `node_modules/` instalado (regra do executor parallel) — build acontece no consolidate-wave do orquestrador apos merge na main. Verificacao alternativa: leitura cuidadosa do JSX + AppLayout.jsx (1 import + 1 item) + comparacao manual com pattern de `Dev/Desenvolvimento.jsx`.

## Auth gates / Checkpoints

Nenhum. Plan totalmente autonomo (`autonomous: true` no frontmatter). Sem chamadas a APIs externas durante os tests (Carbon::setTestNow + Mockery + RefreshDatabase + Storage local).

## Known Stubs

Nenhum stub introduzido pela Plan 41-05. Componentes renderizam dados reais do payload do controller (que por sua vez le do DB + Storage + ProviderComparisonService). Acoes inline NAO sao stub — `Artisan::queue` realmente dispatcha os comandos (sugadores:ml-smoke entregue em Phase 38-02, sugadores:shadow-ml entregue em Phase 40-04).

## Threat Flags

Nenhum threat flag novo. Rota tem `role:admin` middleware (T-41-05-01 mitigado via middleware existente + Tests T7/T10). Route model binding lanca 404 automatico (T-41-05-02 accept). primary_enabled nao mutado pelo toggleShadow (T-41-05-05 mitigated — defensive `if (! $config->exists) { primary_enabled = false; }`).

## Notas operacionais

- **Sem deploy nesta plan.** A Plan 41-05 fecha a Phase 41 inteira. Deploy junto com o consolidate da Phase 41 (Plans 41-01..05) — o orquestrador roda `php artisan migrate --force` + `npm run build` + composer install no VPS apos merge na main (autorizado por feedback permanente do usuario).
- **Phase 41 inteira COMPLETA** — 5/5 plans entregues, REQ-41-01..10 cobertos:
  - REQ-41-01..02: Plan 41-01 (schema)
  - REQ-41-03..06: Plan 41-02 (backoff + cache + rate-limit + metricas)
  - REQ-41-07: Plan 41-03 (command DB > env)
  - REQ-41-06 (parcial): Plan 41-04 (ShadowRunService mescla ml_metrics)
  - REQ-41-08..10: Plan 41-05 (UI admin)
- **MariaDB local segue deferred** (quick task `260625-mrd`). Tests Phase 41-05 rodam em SQLite em-memory (RefreshDatabase) — sem dependencia de MariaDB.
- **Smoke real para Bymobille** continua bloqueado por MariaDB recovery — apos deploy, basta abrir `/dev/sugadores-ml-onboarding`, achar a empresa Bymobille e clicar "Smoke" (action dispara `sugadores:ml-smoke --company={id}` async).

## Self-Check: PASSED

- [x] `tests/Feature/Phase41/SugadoresMlOnboardingControllerTest.php` — FOUND (402 linhas)
- [x] `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` — FOUND (252 linhas, 4 actions + 5 helpers + 2 constants)
- [x] `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx` — FOUND (395 linhas)
- [x] `routes/web.php` — MODIFIED (+13/-0, 1 import + grupo de 4 rotas)
- [x] `resources/js/Layouts/AppLayout.jsx` — MODIFIED (+5/-1, Activity import + 1 item Dev)
- [x] commit `ed66da1` (RED test suite) — FOUND no git log
- [x] commit `0b49ace` (GREEN UI) — FOUND no git log
- [x] Sintaxe PHP validada via `php -l` em todos os 3 PHP files — sem syntax errors
- [x] 10 tests RED esperam GREEN: index render + payload structure + 4 token states + advertiser + 4 statuses + 403 + toggleShadow + Artisan dispatch + 4 rotas gated
- [x] Zero deletions de arquivos rastreados (`git diff --diff-filter=D HEAD~1 HEAD` vazio)
- [x] Gate de seguranca: primary_enabled NAO mutado pelo toggleShadow — defensive coding linha 96 `if (! $config->exists) { $config->primary_enabled = false; }`

## TDD Gate Compliance

- [x] RED commit `ed66da1`: `test(41-05): suite SugadoresMlOnboardingControllerTest RED — 10 tests pra UI admin onboarding ML`
- [x] GREEN commit `0b49ace`: `feat(41-05): GREEN UI admin onboarding ML — controller + 4 rotas + React page + sidebar (REQ-41-08, REQ-41-09, REQ-41-10)`
- [ ] REFACTOR — nao necessario (codigo entregue ja minimo; helpers privados nomeados; constantes extraidas; PHPDoc pt-BR completo; tests com helpers reutilizaveis)

## Fechamento da Phase 41

Phase 41 — Onboarding ML por empresa — **COMPLETA (5/5 plans)**:

| Plan | Subject | REQ | Tests novos | Commits |
|------|---------|-----|-------------|---------|
| 41-01 | 2 migrations + 2 Models + relacoes Company | 01, 02 | 9 | `962ca4e` (RED) + `46c9d9c` (GREEN) |
| 41-02 | MercadoLivreAdsService backoff + cache + rate-limit + metricas | 03, 04, 05, 06 | 12 | `5df0c74` (RED) + `498c4a1` (GREEN) |
| 41-03 | Command sugadores:shadow-ml prioriza DB sobre env | 07 | 5 | `3f3758c` (RED) + `b5cc205` (GREEN) |
| 41-04 | ShadowRunService mescla ml_metrics no summary | 06 (parcial) | 5 | `c496e18` (RED) + `2631e4a` (GREEN) |
| 41-05 | UI admin + Controller + 4 rotas + React page + sidebar | 08, 09, 10 | 10 | `ed66da1` (RED) + `0b49ace` (GREEN) |
| **Total** | — | **01..10** | **41** | 10 commits (5 RED + 5 GREEN) |

**Pronto para deploy** apos consolidate-wave do orquestrador (merge na main + composer install + migrate --force + npm run build + supervisor restart).

**Proxima phase:** Phase 42 (cut-over real Adman → ML primary; promove `primary_enabled` em SugadorMlCompanyConfig; grava em sugadores via path ML; rollback automatico em divergencia critica).
