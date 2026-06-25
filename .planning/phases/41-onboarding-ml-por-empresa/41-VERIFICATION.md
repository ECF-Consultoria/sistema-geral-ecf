---
phase: 41-onboarding-ml-por-empresa
verified: 2026-06-25T22:00:00Z
status: human_needed
score: 10/10 must-haves verificados
overrides_applied: 0
re_verification:
  previous_status: critical_fixed
  previous_score: "n/a (fase de code review, nao verify-work)"
  gaps_closed:
    - "CR-01: MercadoLivreAdsService nao tinha singleton — corrigido em commit 7b29dc6 com teste de regressao mercadoLivreAdsService_e_singleton_no_container"
    - "CR-02: Contador `falhas` nao contava 2 quando shadow run lanca \\Throwable — corrigido em commit 7b29dc6 (`$falhas += 2`) com teste de regressao shadow_run_lanca_throwable_conta_2_falhas"
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Acessar /dev/sugadores-ml-onboarding como admin no navegador"
    expected: "Pagina renderiza com tabela de empresas, KPIs no header, filtros e botoes de acao. Layout pt-BR, tokens ecf-* aplicados, sem erros JS no console."
    why_human: "Verifica renderizacao real do React + AppLayout integrado + sidebar mostra item 'Sugadores ML Onboarding' so para role admin. Build esta presente (manifest.json mapeia para assets/Index-CYNpzIa4.js), mas qualidade visual e UX nao verificavel via grep."
  - test: "Logar como usuario com role=consultor/mentor e tentar acessar /dev/sugadores-ml-onboarding"
    expected: "403 Forbidden ou redirect. Sidebar NAO mostra item 'Sugadores ML Onboarding'."
    why_human: "Gate role:admin so e testavel programaticamente via PHPUnit; comportamento real do navegador + sidebar com excludeRoles requer click manual."
  - test: "Clicar em 'Rodar smoke' para uma empresa com token ativo + advertiser ok"
    expected: "Flash success 'Smoke ML disparado para {nome}.' aparece. Comando sugadores:ml-smoke realmente entra na queue (verificar tabela jobs ou queue:work em paralelo)."
    why_human: "Artisan::queue eh async; verifica que dispatch real do comando funciona, nao apenas o test que mockou Artisan::shouldReceive. Tambem depende de MariaDB voltar (quick task 260625-mrd)."
  - test: "Clicar em 'Toggle Shadow' para uma empresa e validar que `sugador_ml_company_config` foi gravado/atualizado"
    expected: "Row criada/atualizada em sugador_ml_company_config com shadow_enabled=true e shadow_started_at=hoje; subsequente toggle reverte. Activity log do operador opcional (IN-05 nao bloqueia)."
    why_human: "Confirma persistencia real em MariaDB; testes rodam SQLite em-memory. Verificar tambem que botao volta a estado correto apos reload."
  - test: "Rodar `php artisan sugadores:shadow-ml --company=all` no VPS apos UI ter habilitado shadow para >=1 empresa"
    expected: "Comando le da tabela sugador_ml_company_config (NAO do env CSV) e processa apenas as empresas com shadow_enabled=true; output cita empresas corretas; sumario `admanOk + mlOk + falhas == 2 * days * len(companies)` (invariante CR-02)."
    why_human: "Smoke real do path completo Plan 41-03+41-04 em producao; depende de MariaDB local e/ou VPS estavel + tokens ML reais; testes mockam ShadowRunService nao rodam contra ML real."
  - test: "Validar metricas `ml_metrics` em `sugador_provider_runs.summary` apos um shadow run real (provider=ml)"
    expected: "Coluna summary contem chaves total_calls, pages_read, rate_limit_429, refresh_token_count, backoff_sleep_ms, total_duration_ms com valores >0 quando o run fez chamadas HTTP reais ao ML."
    why_human: "Valida fix CR-01 em producao (singleton garante que getLastRunMetrics retorne valores reais, NAO zeros). Testes unitarios usam $this->app->instance() que mascarava o bug; o teste de regressao garante singleton mas nao executa shadow real."
  - test: "Verificar comportamento de backoff em 429/5xx contra a API ML real"
    expected: "Quando ML responde 429, observar log/metricas indicando retry com Retry-After respeitado; nao deve cascatear erro nem queimar tokens."
    why_human: "Tests Http::fake simulam mas nao exercitam o sistema operacional real (ratelimiter::hit em cache, latencia de network, etc); validacao em piloto com 1 empresa pequena (ex: Bymobile) eh recomendada antes de habilitar shadow em massa."
---

# Phase 41: Onboarding ML por empresa — Relatorio de Verificacao

**Phase Goal:** Tela admin: empresas ativas com mlToken valido/expirado/ausente/erro. Checklist por empresa (OAuth, seller_id, advertiser_id, scopes Ads, smoke, shadow). Politica temporaria: sem token → Adman; com token mas smoke falha → Adman + alerta; com shadow aprovado 7d → candidata a ml_primary. Tabela opcional ml_advertisers para cache de advertiser_id/seller_id/site_id. Rate limiter ml-api:{seller_id} por seller (nao global) com backoff 429/5xx/401/403.

**Verificado:** 2026-06-25T22:00:00Z
**Status:** human_needed
**Re-verificacao:** Sim — apos code review (CR-01 + CR-02 corrigidos em commit 7b29dc6)

## Achievement do Goal

### Truths Observaveis

| # | Truth (oriundo de Success Criteria + must_haves dos 5 plans) | Status | Evidencia |
|---|------|--------|-----------|
| 1 | **Tabela `ml_advertisers` existe** com colunas exatas (id, company_id FK unique, advertiser_id idx, seller_id nullable, site_id default 'MLB', raw_data json, discovered_at timestampTz, timestamps) | VERIFIED | `database/migrations/2026_06_25_410101_create_ml_advertisers_table.php` linhas 24-38: schema com `foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete()`, `advertiser_id` idx, `site_id` default 'MLB', `raw_data` json, `discovered_at` timestampTz. 9/9 tests Phase41SchemaTest PASS. |
| 2 | **Tabela `sugador_ml_company_config` existe** com flags shadow_enabled/primary_enabled + datas + notes + uniqueness por company_id | VERIFIED | `database/migrations/2026_06_25_410102_create_sugador_ml_company_config_table.php` linhas 30-43: schema completo + FK cascade + indices em shadow_enabled/primary_enabled. Test `tabela_sugador_ml_company_config_tem_colunas_esperadas` PASS. |
| 3 | **Models Eloquent (MlAdvertiser + SugadorMlCompanyConfig)** com $fillable, $casts apropriados, relacoes company() BelongsTo, e relacoes hasOne em Company.mlAdvertiser() + sugadorMlConfig() | VERIFIED | `app/Models/MlAdvertiser.php`, `app/Models/SugadorMlCompanyConfig.php` (com `$table` explicito singular). `app/Models/Company.php` linhas 250-263: 2 hasOne adicionados. Tests 5-7 PASS (casts + relacoes). |
| 4 | **MercadoLivreAdsService::callWithBackoff** cobre 429 (Retry-After clamp 1..60s + jitter), 5xx (exponencial 2^n cap 60s + jitter), 401 (refresh 1x via MercadoLivreService::refreshToken; segundo 401 aborta), 403 (sem retry, scope_missing); MAX_ATTEMPTS=5 | VERIFIED | `app/Services/Sugadores/MercadoLivreAdsService.php` linhas 165-247: callWithBackoff completo. Tests 1-5 (`discoverAdvertiser_retry_em_429_com_Retry_After`, `discoverAdvertiser_backoff_exponencial_em_500`, `discoverAdvertiser_refresh_token_em_401`, `discoverAdvertiser_aborta_em_403_sem_retry`, `discoverAdvertiser_aborta_apos_maxAttempts`) PASS. |
| 5 | **discoverAdvertiser cache 7d** em `ml_advertisers` (cache hit pula HTTP; miss/expirado faz updateOrCreate) | VERIFIED | MercadoLivreAdsService.php linhas 280-336: `MlAdvertiser::where('company_id', $company->id)->first()` + comparacao `discovered_at->gt(now()->subDays(CACHE_TTL_DAYS))` + updateOrCreate. Tests 6, 7, 8 (cache miss/hit/expirado) PASS. CACHE_TTL_DAYS=7. |
| 6 | **RateLimiter::for('ml-api', ...)** registrado em AppServiceProvider boot() com `Limit::perMinute(60)->by($sellerId)` (bucket por seller, NAO global) + helper privado `withRateLimit` aborta com RuntimeException se tooManyAttempts | VERIFIED | `app/Providers/AppServiceProvider.php` linhas 65-67. `MercadoLivreAdsService.php::withRateLimit` linhas 133-146 (`RateLimiter::tooManyAttempts → throw`). Tests 9 + 10 PASS. |
| 7 | **MercadoLivreAdsService::getLastRunMetrics()** retorna 6 chaves (total_calls, pages_read, rate_limit_429, refresh_token_count, backoff_sleep_ms, total_duration_ms); resetMetrics() chamado em cada metodo publico; SINGLETON registrado para que ShadowRunService leia a mesma instancia (fix CR-01) | VERIFIED | `MercadoLivreAdsService.php` linhas 89-128 (reset + getLastRunMetrics). `AppServiceProvider::register` linha 33: `$this->app->singleton(MercadoLivreAdsService::class)`. Tests 11, 12 + CR-01 regressao `mercadoLivreAdsService_e_singleton_no_container` PASS. |
| 8 | **Command `sugadores:shadow-ml --company=all` prioriza tabela `sugador_ml_company_config`** (rows com shadow_enabled=true); fallback env CSV preservado; mensagem de erro pt-BR cita ambas as fontes; contador `falhas` em catch \\Throwable conta 2 (CR-02 fix) | VERIFIED | `app/Console/Commands/SugadoresShadowMl.php` linhas 144-159 (DB > env). Linha 64: mensagem cita `sugador_ml_company_config` E `SUGADORES_ML_SHADOW_COMPANIES`. Linhas 92-102: catch \\Throwable → `$falhas += 2`. 5 tests Phase 41-03 + regressao CR-02 `shadow_run_lanca_throwable_conta_2_falhas` PASS. Phase 40-04 baseline 8/8 sem regressao. |
| 9 | **ShadowRunService mescla `ml_metrics` no summary JSON** quando provider='ml' E mlAds disponivel via DI; provider='adman' permanece sem ml_metrics (back-compat); coleta defensiva (try/catch + Log::warning) nao interrompe run | VERIFIED | `app/Services/Sugadores/ShadowRunService.php` linhas 50-51 (`?MercadoLivreAdsService $mlAds = null` opcional), linhas 145-158 (bloco condicional `if ($providerName === 'ml' && $this->mlAds !== null)` + try/catch + Log::warning prefixado `[Sugadores Shadow]`). Gate REQ-40-02 dryRun=true intacto. 5 tests Phase 41-04 PASS. |
| 10 | **UI admin completa**: Rota GET /dev/sugadores-ml-onboarding (role:admin) renderiza Inertia 'Dev/SugadoresMlOnboarding/Index'; payload `companies` com 8 keys (id, name, token_state, advertiser, smoke_last_at, shadow_paridade_7d, status, actions); 3 POST (smoke, shadow, toggle-shadow); sidebar mostra item para admin via excludeRoles; npm build com chunk Index-*.js | VERIFIED | `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` (252 linhas, 4 actions + 5 helpers + 2 constants). `routes/web.php` linhas 316-321: 4 rotas dentro do grupo `role:admin`. `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx` existe (23 KB). `resources/js/Layouts/AppLayout.jsx` linha 86 (item sidebar). `public/build/manifest.json` mapeia `Index.jsx` → `assets/Index-CYNpzIa4.js`. 10 tests Phase 41-05 PASS. |

**Score:** 10/10 truths verificados

### Artefatos Requeridos

| Artefato | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `database/migrations/2026_06_25_410101_create_ml_advertisers_table.php` | Migration idempotente (Schema::hasTable guard) com schema exato | VERIFIED | 1678 bytes, schema completo, guard + dropIfExists |
| `database/migrations/2026_06_25_410102_create_sugador_ml_company_config_table.php` | Migration idempotente schema exato (singular intencional) | VERIFIED | 2037 bytes, schema + FK cascade + indices |
| `app/Models/MlAdvertiser.php` | Model com $fillable, $casts (raw_data array + discovered_at immutable_datetime), company() BelongsTo | VERIFIED | 1059 bytes, codigo correto |
| `app/Models/SugadorMlCompanyConfig.php` | Model com $table explicito singular + casts booleanos + relacoes | VERIFIED | 1453 bytes, $table='sugador_ml_company_config' linha 26 |
| `app/Models/Company.php` (edit) | Apenas adicao de 2 relacoes hasOne sem remocao | VERIFIED | linhas 250-263: mlAdvertiser() + sugadorMlConfig() |
| `app/Providers/AppServiceProvider.php` (edit) | RateLimiter::for('ml-api', ...) + singleton MercadoLivreAdsService (fix CR-01) | VERIFIED | linhas 33 (singleton) + 65-67 (rate limiter) |
| `app/Services/Sugadores/MercadoLivreAdsService.php` (refactor) | callWithBackoff + withRateLimit + cache + metricas | VERIFIED | 5+ ocorrencias de cada API, constantes corretas |
| `app/Console/Commands/SugadoresShadowMl.php` (edit) | resolveCompanies DB > env + msg pt-BR + falhas += 2 | VERIFIED | linhas 144-159 + 64 + 92-102 |
| `app/Services/Sugadores/ShadowRunService.php` (edit) | DI opcional MercadoLivreAdsService + merge ml_metrics quando provider=ml | VERIFIED | linhas 50-51, 145-158 |
| `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` | 4 actions publicas + 5 helpers privados + 2 constantes | VERIFIED | 8922 bytes, todos os metodos presentes |
| `routes/web.php` (edit) | 4 rotas dentro de role:admin com prefix dev/sugadores-ml-onboarding | VERIFIED | linhas 316-321 + import linha 18 |
| `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx` | React page com tabela 6+ colunas + filtros + acoes | VERIFIED | 23 KB, build gerado em assets/Index-CYNpzIa4.js |
| `resources/js/Layouts/AppLayout.jsx` (edit) | Item sidebar admin com excludeRoles | VERIFIED | linha 86: 'Sugadores ML Onboarding' + Activity icon import linha 10 |
| `tests/Feature/Phase41/*.php` | 5 suites, >= 40 tests #[Test] | VERIFIED | 43 #[Test] em 5 arquivos: schema(9) + backoff(13) + config_table(6) + ml_metrics(5) + onboarding_controller(10) |

### Verificacao de Key Links

| De | Para | Via | Status | Detalhes |
|----|------|-----|--------|----------|
| `ml_advertisers.company_id` | `companies.id` | FK cascadeOnDelete | WIRED | migration linha 28-31 |
| `sugador_ml_company_config.company_id` | `companies.id` | FK unique + cascadeOnDelete | WIRED | migration linha 34-37 |
| `Company` | `MlAdvertiser` | hasOne | WIRED | Company.php linha 252 `$this->hasOne(MlAdvertiser::class)` |
| `Company` | `SugadorMlCompanyConfig` | hasOne | WIRED | Company.php linha 262 |
| `MercadoLivreAdsService::discoverAdvertiser` | `ml_advertisers` (cache 7d) | `MlAdvertiser::where + updateOrCreate` | WIRED | Service linhas 284 (where) + 314 (updateOrCreate) |
| `MercadoLivreAdsService::withRateLimit` | RateLimiter ml-api bucket | `tooManyAttempts/hit` por seller_id | WIRED | Service linha 138, AppServiceProvider linha 65 |
| `MercadoLivreAdsService::callWithBackoff` | `Http::withToken` (ML API) | Http facade chain | WIRED | Service linhas 179-183 |
| `SugadoresShadowMl::resolveCompanies('all')` | `SugadorMlCompanyConfig::where(shadow_enabled=true)` | Eloquent + array_map intval | WIRED | Command linha 147 |
| `SugadoresShadowMl::resolveCompanies('all')` (fallback) | `config('sugadores.ml_shadow_companies')` | Laravel config | WIRED | Command linha 156 (preservado) |
| `ShadowRunService::executeForProvider(ml)` | `summary.ml_metrics` | `$this->mlAds->getLastRunMetrics()` | WIRED | Service linhas 150-152, dentro de try/catch |
| `ShadowRunService` | `MercadoLivreAdsService` (singleton) | DI opcional + binding singleton | WIRED | Constructor linha 51, AppServiceProvider linha 33 — CR-01 fix |
| `/dev/sugadores-ml-onboarding` | `SugadoresMlOnboardingController@index` | Route::get role:admin | WIRED | routes/web.php linha 317 |
| `SugadoresMlOnboardingController::index` | `Inertia::render('Dev/SugadoresMlOnboarding/Index')` | Inertia + payload companies | WIRED | Controller linha 63 |
| `SugadoresMlOnboardingController::runSmoke` | `sugadores:ml-smoke` | `Artisan::queue` | WIRED | Controller linha 73 |
| `SugadoresMlOnboardingController::runShadow` | `sugadores:shadow-ml --days=1` | `Artisan::queue` | WIRED | Controller linhas 83-86 |
| `SugadoresMlOnboardingController::toggleShadow` | `SugadorMlCompanyConfig::firstOrNew + save` | Eloquent | WIRED | Controller linhas 100-111 |
| AppLayout sidebar | `dev.sugadores_ml_onboarding.index` | routeName attribute | WIRED | AppLayout.jsx linha 86 |
| Build Vite | `assets/Index-CYNpzIa4.js` | manifest.json | WIRED | manifest mapeia `Pages/Dev/SugadoresMlOnboarding/Index.jsx` |

### Data-Flow Trace (Level 4)

| Artefato | Variavel/Estado | Fonte | Produz Dados Reais? | Status |
|----------|-----------------|-------|---------------------|--------|
| `Index.jsx` (UI admin) | prop `companies` | Controller `index()` → `Inertia::render` | Sim — eager load Company::with([mlToken, mlAdvertiser, sugadorMlConfig])->where active=true | FLOWING |
| `Index.jsx` linha shadow paridade | `row.shadow_paridade_7d` | `resolveShadowStats(Company)` → `ProviderComparisonService::compareWindow` | Sim — query real em SugadorProviderRun ultimos 7d + service Phase 40-03 | FLOWING (com defensive try/catch → null se erro) |
| `Index.jsx` token state | `row.token_state` | `resolveTokenState(mlToken)` calculado de status + isExpired | Sim — derivado de MlToken real | FLOWING |
| `Index.jsx` smoke last | `row.smoke_last_at` | `Storage::disk('local')->files('sugadores/ml-smoke')` + lastModified | Sim — Storage real (defensivo via try/catch) | FLOWING |
| `Index.jsx` actions disabled | `row.actions.can_*` | Booleano calculado em buildRow | Sim — depende de token_state + advertiser reais | FLOWING |
| `sugador_provider_runs.summary.ml_metrics` | 6 chaves int | `MercadoLivreAdsService::getLastRunMetrics` (singleton via AppServiceProvider) | Sim — apos fix CR-01, singleton garante que ShadowRunService leia da MESMA instancia que fez chamadas HTTP. Antes do fix, retornava zeros. | FLOWING (apos CR-01 fix em commit 7b29dc6) |
| `ml_advertisers.advertiser_id` | string 64 chars | `MercadoLivreAdsService::discoverAdvertiser` (cache miss → API ML real → updateOrCreate) | Sim — chamadas reais a `/advertising/advertisers` via callWithBackoff | FLOWING |
| `sugador_ml_company_config.shadow_enabled` | boolean | `SugadoresMlOnboardingController::toggleShadow` (firstOrNew + save) OU `SugadoresShadowMl::resolveCompanies` (leitura) | Sim — escrita pela UI admin, leitura pelo scheduler 13h BRT | FLOWING |

### Behavioral Spot-Checks

| Comportamento | Comando | Resultado | Status |
|---------------|---------|-----------|--------|
| Suite Phase 41 inteira | `php artisan test --filter=Phase41` | 43/43 PASS (relatado pelo orquestrador no instructional context) | PASS |
| Suite Phase 40 baseline (zero regressao) | `php artisan test --filter=Phase40` | Pass (relatado) | PASS |
| Suite Sugador (zero regressao) | `php artisan test --filter=Sugador` | Pass (relatado) | PASS |
| Build Vite produz chunk para Index.jsx | `cat public/build/manifest.json \| grep SugadoresMlOnboarding` | `"file": "assets/Index-CYNpzIa4.js"` | PASS |
| 43 testes #[Test] em Phase 41 | grep Phase41 | 43 (9 schema + 13 backoff + 6 config_table + 5 ml_metrics + 10 onboarding) | PASS |
| Suite Phase38/PolosControllerTest (6 falhas pre-existentes) | `php artisan test --filter=PolosControllerTest` | 6 falhas reproduzem no commit pre-Phase-41 b9441ed (NAO causadas por Phase 41) | SKIP (pre-existente, fora de escopo per instructional context) |

### Probe Execution

Phase 41 e fase de feature (UI + backend Laravel/React), nao migration tool/CLI. PLAN nao declara probes (`scripts/*/tests/probe-*.sh`). Padrao Phase 41 usa PHPUnit como verificacao automatica + UI manual.

| Probe | Comando | Resultado | Status |
|-------|---------|-----------|--------|
| (nao aplicavel) | — | Sem probes declarados nos PLANs | N/A |

### Cobertura de Requisitos

| Requisito | Plano Fonte | Descricao | Status | Evidencia |
|-----------|-------------|-----------|--------|-----------|
| REQ-41-01 | 41-01 | Migration `ml_advertisers` + Model + relacao Company hasOne | SATISFIED | Truths 1, 3; tests Phase41Schema 1, 3, 5, 7, 8 |
| REQ-41-02 | 41-01 | Migration `sugador_ml_company_config` + Model + relacao Company hasOne | SATISFIED | Truths 2, 3; tests Phase41Schema 2, 4, 6, 7, 9 |
| REQ-41-03 | 41-02 | callWithBackoff aplicado nos metodos publicos cobrindo 429/5xx/401/403 | SATISFIED | Truth 4; tests backoff 1-5 |
| REQ-41-04 | 41-02 | Cache advertiser TTL 7 dias em ml_advertisers | SATISFIED | Truth 5; tests backoff 6-8 |
| REQ-41-05 | 41-02 | RateLimiter ml-api 60/min por seller_id + withRateLimit helper | SATISFIED | Truth 6; tests backoff 9-10 |
| REQ-41-06 | 41-02 + 41-04 | Metricas operacionais + ShadowRunService mescla ml_metrics no summary | SATISFIED | Truths 7, 9; tests backoff 11-12 + tests ml_metrics 1-5 + regressao singleton |
| REQ-41-07 | 41-03 | Command shadow-ml prioriza DB sobre env CSV | SATISFIED | Truth 8; tests config_table 1-5 + regressao CR-02 |
| REQ-41-08 | 41-05 | Controller Dev/SugadoresMlOnboardingController + 4 rotas role:admin | SATISFIED | Truth 10; tests onboarding 1, 7, 9, 10 |
| REQ-41-09 | 41-05 | Pagina React Index.jsx + sidebar item Sistema/Dev | SATISFIED | Truth 10; build chunk gerado; tests 2-3 |
| REQ-41-10 | 41-05 | Suite Feature cobrindo migration/models/controller/backoff/ratelimiter/cache/comando; zero regressao | SATISFIED | Truths 1-10; 43 tests Phase41 + zero regressao Phase 40/Sugador |

Todos os 10 requisitos REQ-41-01..10 estao mapeados em pelo menos 1 plan dentro da Phase 41 (verificado via `requirements:` no frontmatter dos 5 PLANs: 41-01[01,02], 41-02[03,04,05,06], 41-03[07], 41-04[06], 41-05[08,09,10]). Nenhum requisito orfao.

### Anti-Patterns Encontrados

| Arquivo | Linha | Padrao | Severidade | Impacto |
|---------|-------|--------|------------|---------|
| `app/Services/Sugadores/MercadoLivreAdsService.php` | 285 | Double-check defensivo `$cached->discovered_at &&` (coluna NOT NULL na migration) | Info | IN-01 do REVIEW: codigo defensivo redundante mas inofensivo. Nao bloqueador. |
| `app/Services/Sugadores/MercadoLivreAdsService.php` | 309 vs 319 | `site_id` retornado null no cache miss mas grava 'MLB'; cache hit retorna 'MLB' | Info | IN-02 do REVIEW: pequena inconsistencia entre primeira chamada e cache hit. Nao quebra contrato publico. |
| `app/Console/Commands/SugadoresShadowMl.php` | 156 | `array_map('intval', $ids)` redundante (config ja retorna int[]) | Info | IN-03 do REVIEW: codigo duplicado defensivo. Sem impacto. |
| `resources/js/Layouts/AppLayout.jsx` | 86 | `excludeRoles` lista publication_roles (publicador/analista/gestor/lider) que NAO sao mainRole | Info | IN-04 do REVIEW: codigo enganoso mas funcional — gate via excludeRoles=['consultor','mentor'] ja excluiria todos os nao-admin. |
| `app/Models/MlAdvertiser.php` + `SugadorMlCompanyConfig.php` | — | Sem LogsActivity (decisao consciente) | Info | IN-05 do REVIEW: audit nao registra quem ativou shadow_enabled; sugestao defere ao operador via activity() no Controller. |
| `app/Services/Sugadores/MercadoLivreAdsService.php` | 169-247 | Sem `->timeout(N)` configurado em Http (default 30s Guzzle) | Warning | WR-02 do REVIEW: pior caso teorico 60s+ por chamada se network split; nao bloqueador para v1 (rate limit + MAX_ATTEMPTS limitam impacto). |
| `app/Services/Sugadores/MercadoLivreAdsService.php` | 197-215 | usleep apos a ultima tentativa (atrasa abort em ate 32s+jitter) | Warning | WR-05 do REVIEW: trabalho perdido em 5a tentativa de 5xx; eficiencia, nao correctness. |
| `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` | 147 | `can_toggle_shadow` gated por token+advertiser ok | Warning | WR-03 do REVIEW: operador nao consegue desabilitar shadow durante incidentes (token revogado). Usabilidade, nao seguranca. |
| `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` | 175 | `Storage::files()` chamado N vezes em loop (1x por empresa) | Warning | WR-04 do REVIEW: performance O(N*M); aceitavel ate <100 empresas. |
| `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` | 83-86 | `--days=1` hardcoded em runShadow | Warning | WR-06 do REVIEW: UI nao permite janela maior. Iteracao futura. |
| `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx` | 139-179 | Acoes assincronas sem polling/banner explicando delay | Warning | WR-07 do REVIEW: UX feedback insuficiente para async actions. Iteracao futura. |
| `app/Services/Sugadores/MercadoLivreAdsService.php` | 133-146 | RateLimiter consome bucket mesmo em 403 imediato | Warning | WR-01 do REVIEW: empresas com scope missing podem queimar bucket; circuit breaker futuro. |

**Resumo:** 0 BLOCKERS (CR-01 e CR-02 corrigidos em 7b29dc6 com testes de regressao). 7 Warnings + 5 Infos — todos triados como `backlog` no REVIEW frontmatter (resolvido em phase de polish posterior). Nenhum impede o objetivo principal da Phase 41.

### Verificacao Humana Requerida

Vide secao `human_verification:` no frontmatter. 7 testes manuais devem ser executados antes de marcar a Phase 41 como totalmente fechada em producao:

1. **Acesso admin** ao painel `/dev/sugadores-ml-onboarding` (visual + sidebar)
2. **Gate role:admin** validado em navegador para usuarios consultor/mentor
3. **Acao runSmoke** disparada — verifica que comando entra na queue real
4. **Acao toggleShadow** persiste em MariaDB real (testes rodam SQLite em-memory)
5. **Smoke real** do `php artisan sugadores:shadow-ml --company=all` no VPS (depende de MariaDB voltar)
6. **Validar ml_metrics nao-zero** em sugador_provider_runs.summary apos shadow run real (valida CR-01 em producao)
7. **Backoff real contra ML** com piloto em 1 empresa pequena antes de habilitar shadow em massa

### Resumo de Gaps

Nenhum gap bloqueador. Os 2 BLOCKERS detectados pelo code review (CR-01 singleton + CR-02 contador falhas) foram corrigidos em commit `7b29dc6` com testes de regressao dedicados (`mercadoLivreAdsService_e_singleton_no_container` em MercadoLivreAdsServiceBackoffTest.php + `shadow_run_lanca_throwable_conta_2_falhas` em SugadoresShadowMlCommandConfigTableTest.php).

Os 7 Warnings e 5 Infos do REVIEW foram triados como `backlog` (resolvido em phase de polish posterior). Eles afetam robustez/UX mas nao impedem o objetivo central da Phase 41 (UI admin observavel + onboarding por empresa + backoff + cache + rate limiter + metricas).

Phase 41 esta em status `human_needed` exclusivamente porque os 7 itens de verificacao humana listados acima (visual, integracao real com ML API, smoke real no VPS) so podem ser confirmados via interacao manual.

---

_Verificado: 2026-06-25T22:00:00Z_
_Verificador: Claude (gsd-verifier)_
