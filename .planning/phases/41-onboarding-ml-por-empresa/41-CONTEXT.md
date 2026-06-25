# Phase 41: Onboarding ML por empresa — Context

**Gathered:** 2026-06-25
**Status:** Ready for planning (Phase 39+40 fechadas; providers operando + shadow mode gravando paridade)
**Source:** Import express path (`plano-migracao-sugadores-ml-direto.md` §3 Fase 3 + §3 rate limiter) + Phase 40 deliverables

<domain>
## Phase Boundary

Phase 41 entrega **infraestrutura operacional** + **UI admin** para gerenciar a migração ML por empresa:

1. **Tabela `ml_advertisers`** (cache de `advertiser_id`/`seller_id`/`site_id` por empresa) — evita descobrir advertiser a cada chamada ML
2. **Rate limiter `ml-api:{seller_id}`** por seller (não global) com backoff escalonado para 429/5xx/401/403
3. **Tela admin `/dev/sugadores-ml-onboarding`** com:
   - Lista de empresas ativas mostrando estado por coluna:
     - `mlToken` (ausente / expirado / erro_refresh / válido)
     - `advertiser_id` (não descoberto / OK)
     - Smoke (não rodou / rodou OK / rodou falhou)
     - Shadow approval (não aplicável / pendente N dias / aprovada / reprovada)
     - Status geral (`adman_only` / `ml_shadow` / `ml_primary_candidate` / `ml_primary`)
   - Checklist por empresa expansível (botão "Detalhes"):
     - OAuth conectado?
     - seller_id confirmado?
     - advertiser_id descoberto?
     - Scopes Ads presentes?
     - Smoke verde?
     - Shadow ≥95% paridade últimos 7 dias?
   - Ações inline: "Rodar smoke agora" (dispara `sugadores:ml-smoke`), "Rodar shadow agora" (dispara `sugadores:shadow-ml --company={id}`), "Habilitar shadow" (adiciona empresa a `SUGADORES_ML_SHADOW_COMPANIES` via UI — escreve em config persistente, não no .env)
4. **Persistência da lista de shadow companies** (decisão pendente: continuar via env CSV ou mover para tabela `sugador_provider_config`?) — sugestão deste plan: criar tabela leve `sugador_ml_company_config(company_id PK, shadow_enabled, primary_enabled, notes)` para a UI ter onde escrever
5. **Política temporária aplicada no `SugadoresAdsProviderFactory`** (refactor leve):
   - Empresa sem mlToken → Adman (default atual)
   - Empresa com mlToken + smoke falhou → Adman + alerta visual na UI
   - Empresa com shadow aprovado 7 dias → status `ml_primary_candidate` (UI mostra botão "promover para primary"; promoção real é Phase 42)
6. **Backoff escalonado por status HTTP no `MercadoLivreAdsService`** (§3 do plano):
   - 429: respeitar `Retry-After`, teto 60s, jitter
   - 5xx: backoff exponencial + jitter
   - 401: tentar refresh token uma vez, marcar `mlToken.status='error_refresh'` se falhar
   - 403: log + marcar empresa como `scope_missing` (sem retry)
7. **Métricas operacionais por empresa** (gravadas durante runs):
   - total de chamadas
   - páginas lidas
   - 429 hits
   - refresh token executado
   - duração total

**Esta phase NÃO entrega:**
- Gravação em `sugadores` via path ML (`ml_primary` real) — Phase 42
- Cut-over por empresa automatizado — Phase 42
- Rollback automático em divergência crítica — Phase 42
- Remoção da Adman — Phase 43

**Pré-requisitos:**
- Phase 38-01 ✓ — `MercadoLivreAdsService`
- Phase 39 ✓ — providers + factory + `SugadorAnalysisService` refatorado
- Phase 40 ✓ — `ShadowRunService` + `ProviderComparisonService` + comandos + scheduler 13h
- **Bloqueio conhecido:** smoke real Phase 38-02 (deferred MariaDB recovery). Phase 41 UI exibe estado "smoke não rodou" para Bymobille até MariaDB voltar e smoke ser executado.

</domain>

<decisions>
## Implementation Decisions

### 1. Tabela `ml_advertisers`

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_ml_advertisers_table.php`

```php
Schema::create('ml_advertisers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
    $table->string('advertiser_id', 64)->index();
    $table->string('seller_id', 64)->nullable();
    $table->string('site_id', 8)->default('MLB');     // mercado livre Brasil
    $table->json('raw_data')->nullable();              // payload completo do /advertising/advertisers
    $table->timestampTz('discovered_at');
    $table->timestamps();
});
```

**Model:** `App\Models\MlAdvertiser` com `belongsTo(Company)` + cast `raw_data => array`.

Repository helper: `MercadoLivreAdsService::discoverAdvertiser()` ganha lógica "lê cache; se ausente OU expirado (>7d), busca + grava".

### 2. Tabela `sugador_ml_company_config`

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_sugador_ml_company_config_table.php`

```php
Schema::create('sugador_ml_company_config', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
    $table->boolean('shadow_enabled')->default(false)->index();
    $table->boolean('primary_enabled')->default(false)->index();
    $table->date('shadow_started_at')->nullable();
    $table->date('primary_promoted_at')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

**Model:** `App\Models\SugadorMlCompanyConfig`.

**Decisão:** essa tabela substitui o env CSV `SUGADORES_ML_SHADOW_COMPANIES` da Phase 40 — comando `sugadores:shadow-ml --company=all` passa a ler `SugadorMlCompanyConfig::where('shadow_enabled', true)`. **Backwards compat:** se a tabela existir mas estiver vazia, fallback para o env CSV (não quebra Phase 40). Comando aceita ambos enquanto a tabela está sendo populada.

**Refactor mínimo no comando `sugadores:shadow-ml`:**
- `resolveCompanies('all')` → primeiro tenta `SugadorMlCompanyConfig::where('shadow_enabled', true)->pluck('company_id')`; se vazio, fallback `config('sugadores.ml_shadow_companies')`

### 3. Rate limiter `ml-api:{seller_id}`

**Arquivo:** `app/Providers/AppServiceProvider.php` — adicionar registro do limiter:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

// Phase 41: rate limiter ML por seller (não global)
RateLimiter::for('ml-api', function (Request $request, $sellerId = 'unknown') {
    return Limit::perMinute(60)->by($sellerId);
});
```

Sintaxe específica do Laravel: `RateLimiter::for(name, callback)` recebe parâmetro dinâmico. O caller passa `$sellerId` via `RateLimiter::attempt('ml-api', 60, fn() => ..., 60, ['sellerId' => $seller])` OU usa `Limit::by($sellerId)` no callback.

**Aplicação:** novo middleware leve para Jobs `App\Http\Middleware\RateLimitMlApi` (ou método helper em `MercadoLivreAdsService`):

```php
// Em MercadoLivreAdsService, antes de cada chamada:
private function withRateLimit(string $sellerId, callable $callback)
{
    $key = "ml-api:{$sellerId}";
    $executed = RateLimiter::attempt($key, 60, $callback, 60);
    if (!$executed) {
        throw new \RuntimeException("Rate limit ML excedido para seller {$sellerId}");
    }
    return $executed;
}
```

**Bucket:** 60 req/min por seller (conservador conforme §3 do plano "Comecar conservador, 60 req/min por seller").

### 4. Backoff escalonado

**Arquivo:** `app/Services/Sugadores/MercadoLivreAdsService.php` — extender lógica de chamadas HTTP existentes para:

```php
private function callWithBackoff(string $url, array $headers, int $maxAttempts = 5): array
{
    $attempt = 0;
    while ($attempt < $maxAttempts) {
        $attempt++;
        $response = Http::withHeaders($headers)->get($url);
        $status = $response->status();
        
        if ($status >= 200 && $status < 300) {
            return ['status' => 200, 'body' => $response->json(), 'url' => $url];
        }
        
        if ($status === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 0);
            $wait = min(max($retryAfter, 1), 60);          // teto 60s
            $wait += random_int(0, 1000) / 1000;            // jitter 0-1s
            sleep((int) $wait);
            continue;
        }
        
        if ($status >= 500) {
            $wait = min(2 ** $attempt, 60);                 // expon backoff cap 60s
            $wait += random_int(0, 1000) / 1000;            // jitter
            sleep((int) $wait);
            continue;
        }
        
        if ($status === 401) {
            // Tentar refresh token UMA vez
            if ($attempt === 1) {
                $this->refreshTokenForCompany();            // ou similar
                continue;
            }
            throw new \RuntimeException("Token ML inválido após refresh");
        }
        
        if ($status === 403) {
            throw new \RuntimeException("Permissão/scope ML ausente (HTTP 403)");
        }
        
        // outros 4xx
        throw new \RuntimeException("ML API HTTP {$status}: " . $response->body());
    }
    throw new \RuntimeException("Max attempts excedido para {$url}");
}
```

**IMPORTANTE:** o atual `MercadoLivreAdsService` da Phase 38 não tem essa lógica completa. Phase 41 entrega o refactor adicionando `callWithBackoff` como wrapper interno dos 4 métodos públicos (`discoverAdvertiser`, `listCampaigns`, `listAds`, `tryFetchAdsMetrics`). Tests devem validar cada cenário de status code com Http::fake.

### 5. UI admin

**Controller:** `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` (criar dir `Dev/` se necessário)

```php
namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\SugadorMlCompanyConfig;
use App\Models\SugadorProviderRun;
use App\Services\Sugadores\ProviderComparisonService;
use Inertia\Inertia;

class SugadoresMlOnboardingController extends Controller
{
    public function __construct(private ProviderComparisonService $comparison) {}
    
    public function index() {
        $companies = Company::with(['mlToken', 'mlAdvertiser', 'sugadorMlConfig'])
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn($c) => $this->buildRow($c));
        
        return Inertia::render('Dev/SugadoresMlOnboarding/Index', [
            'companies' => $companies,
        ]);
    }
    
    public function runSmoke(int $companyId) {
        // Dispatcha o comando sugadores:ml-smoke OU spawna job
        // Phase 41 MVP: dispatch async via Artisan::queue
    }
    
    public function runShadow(int $companyId) {
        // Similar — dispatcha sugadores:shadow-ml --company={id}
    }
    
    public function toggleShadow(int $companyId) {
        $config = SugadorMlCompanyConfig::firstOrCreate(['company_id' => $companyId]);
        $config->update(['shadow_enabled' => !$config->shadow_enabled, 'shadow_started_at' => $config->shadow_enabled ? null : now()]);
        return back();
    }
    
    private function buildRow(Company $c): array {
        // Monta os status colunados (mlToken state, advertiser, smoke, shadow paridade últimos 7d, status geral)
    }
}
```

**Página React:** `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx`

UI elementos:
- Header com contadores (Total empresas | Sem token | Shadow ativo | ML primary | Erros)
- Tabela com colunas:
  - Empresa (nome + ID)
  - mlToken (badge verde/amarelo/vermelho)
  - Advertiser (✓ ou —)
  - Smoke (data último run | "rodar agora" botão)
  - Shadow (% paridade últimos 7d | "rodar agora" botão | toggle ativo/inativo)
  - Status (chip: adman_only | ml_shadow | candidate | primary)
  - Ações (dropdown com: Ver detalhes / Toggle shadow / Promover candidato)
- Filtros: por status, por token state
- Reusar componentes ECF existentes (`DevCard`, `cn()`, tokens `ecf-*`)
- Convenção pt-BR (CLAUDE.md)

**Rotas:** em `routes/web.php`, adicionar dentro do grupo `role:admin`:

```php
Route::prefix('dev/sugadores-ml-onboarding')->name('dev.sugadores_ml_onboarding.')->group(function () {
    Route::get('/', [SugadoresMlOnboardingController::class, 'index'])->name('index');
    Route::post('/{company}/smoke', [SugadoresMlOnboardingController::class, 'runSmoke'])->name('smoke');
    Route::post('/{company}/shadow', [SugadoresMlOnboardingController::class, 'runShadow'])->name('shadow');
    Route::post('/{company}/toggle-shadow', [SugadoresMlOnboardingController::class, 'toggleShadow'])->name('toggle_shadow');
});
```

**Sidebar:** `resources/js/Layouts/AppLayout.jsx` — adicionar item dentro do grupo Dev (só admin):
```
"Sugadores ML Onboarding" → route('dev.sugadores_ml_onboarding.index')
```

### 6. Métricas operacionais

Coletar **durante** as chamadas em `MercadoLivreAdsService` (não precisa de tabela nova — gravar no `summary` JSON de cada `SugadorProviderRun` ao final do run):
```json
"summary": {
    "campanhas_detectadas": 0,
    "adgroups_detectados": 3,
    "items_gravados": 3,
    "ml_metrics": {
        "total_calls": 12,
        "pages_read": 4,
        "rate_limit_429": 0,
        "refresh_token_count": 0,
        "total_duration_ms": 1850
    }
}
```

**ShadowRunService** (Phase 40-02) tem que ser estendido nesta phase para passar essas métricas via callback OU coletar do `MercadoLivreAdsService` internamente. Plano: `MercadoLivreAdsService` ganha método `getLastRunMetrics(): array` que retorna stats da última sessão; `ShadowRunService` chama isso após cada provider e mescla no `summary`.

### 7. Não-tocar

- `app/Models/Sugador.php`, `SugadorConfig.php`, `SugadorAcao.php` (intactos)
- Tabela `sugadores` (intacta — gravação ML é Phase 42)
- `app/Services/SugadorAnalysisService.php` (Phase 39 fechou)
- `app/Services/Sugadores/AdmanSugadoresProvider.php`, `MercadoLivreSugadoresProvider.php`, `SugadoresAdsProviderFactory.php` (Phase 39 — só se precisar reforçar suporte a fallback "shadow falhou → adman", mas é leve)
- `ShadowRunService.php` Phase 40-02 — só estender métricas se necessário, não refatorar
- `ProviderComparisonService.php` Phase 40-03 — consumir, não modificar
- `AdmanService.php`, `MercadoLivreService.php` (Phase 20)
- Comandos Phase 38, 39, 40 (consumir, não modificar — só comando shadow-ml pode ganhar refactor mínimo no `resolveCompanies` se for trivial)

### 8. Claude's Discretion

- Substituir env CSV pela tabela `sugador_ml_company_config` agora vs deixar Phase 42 fazer (Recomendado: fazer agora — UI precisa de algum lugar pra escrever)
- Implementar refresh token automatic no MercadoLivreAdsService vs delegar para MercadoLivreService Phase 20 (Recomendado: delegar — Phase 20 já tem)
- UI usa Vue/React (projeto usa React + Inertia — confirmado)
- Botões "Rodar smoke/shadow agora" disparam síncrono no controller vs Job queueable (Recomendado: Job queueable para não travar request)
- Validar tabela `ml_advertisers` ANTES de criar a `sugador_ml_company_config` (pode ser numa migration única ou separadas — separadas é mais limpo, vão em plans diferentes)

</decisions>

<canonical_refs>
## Canonical References

### Plano de migração
- `plano-migracao-sugadores-ml-direto.md` §3 (rate limit + backoff) e §4 Fase 3 (onboarding empresas + checklist)

### Phases consumidas
- Phase 38-01: `app/Services/Sugadores/MercadoLivreAdsService.php` — vai ganhar `callWithBackoff` + `getLastRunMetrics`
- Phase 39: contract, providers, factory, `SugadorAnalysisService` refatorado
- Phase 40: `ShadowRunService`, `ProviderComparisonService`, comandos, scheduler 13h, tabelas `sugador_provider_runs`/`_items`

### Código existente para referência
- `app/Http/Controllers/DevController.php` (referência de padrão admin Inertia)
- `resources/js/Pages/Dev/Desenvolvimento.jsx` (única página atual `/dev/*` — pattern de layout)
- `routes/web.php` linhas 300-310 (grupo `role:admin` onde rota nova vai)
- `app/Providers/AppServiceProvider.php` (RateLimiter::for('adman-api') já existe — pattern para `ml-api`)
- `resources/js/Layouts/AppLayout.jsx` (sidebar — onde adicionar item Dev)
- `app/Models/Company.php` (accessor `is_ml_driven`, relação `mlToken`, vai ganhar `mlAdvertiser` e `sugadorMlConfig`)
- `app/Models/MlToken.php` (estados do token)

### Doc externa
- Laravel Rate Limiter: https://laravel.com/docs/12.x/rate-limiting
- Inertia Page Props: https://inertiajs.com/pages

</canonical_refs>

<requirements_to_register>
## Requirements desta Phase

- **REQ-41-01** — Migration cria tabela `ml_advertisers` (company_id unique FK, advertiser_id, seller_id, site_id, raw_data JSON, discovered_at); model Eloquent + relação Company belongsTo
- **REQ-41-02** — Migration cria tabela `sugador_ml_company_config` (company_id unique FK, shadow_enabled, primary_enabled, shadow_started_at, primary_promoted_at, notes); model Eloquent + relação Company belongsTo
- **REQ-41-03** — `MercadoLivreAdsService` ganha `callWithBackoff` wrapper interno aplicado nos 4 métodos públicos; testes Http::fake cobrindo 429 (com Retry-After), 5xx (exponencial), 401 (refresh + retry), 403 (sem retry)
- **REQ-41-04** — Cache de advertiser em `discoverAdvertiser`: lê `ml_advertisers` table primeiro; se ausente OU `discovered_at < 7d`, faz chamada ML e grava; testes cobrindo cache hit + cache miss + refresh
- **REQ-41-05** — `RateLimiter::for('ml-api', ...)` registrado em AppServiceProvider; 60 req/min por seller_id; `MercadoLivreAdsService` usa helper `withRateLimit($sellerId, fn)` que aborta se limite excedido
- **REQ-41-06** — `MercadoLivreAdsService` coleta métricas (total_calls, pages_read, 429_hits, refresh_count, duration_ms) e expõe via `getLastRunMetrics()`; ShadowRunService mescla no `summary` JSON da run
- **REQ-41-07** — `ml_shadow_companies` source agora prioriza tabela `sugador_ml_company_config` (where shadow_enabled=true); fallback config env CSV mantido para back-compat; `sugadores:shadow-ml --company=all` testa ambos
- **REQ-41-08** — Controller `Dev/SugadoresMlOnboardingController` + rotas (4 actions: index, runSmoke, runShadow, toggleShadow); rotas em `dev/sugadores-ml-onboarding/*` sob middleware `role:admin`
- **REQ-41-09** — Página React `Dev/SugadoresMlOnboarding/Index.jsx` com tabela 6 colunas + filtros + ações inline; reusa tokens ECF (`ecf-*`) + `DevCard` + `cn()`; sidebar item adicionado em AppLayout
- **REQ-41-10** — Suite de testes Feature: migration, models, controller index payload, controller actions (smoke/shadow/toggle dispatch corretos), MercadoLivreAdsService backoff (4 cenários), rate limiter triggered, cache advertiser, comando shadow-ml usa tabela; zero regressão Sugador/Phase 39/40

</requirements_to_register>

<plan_slicing_suggestion>
## Slicing sugerido (5 plans em 3 waves)

**Wave 1** — fundação independente:
- **Plan 41-01** — 2 migrations (`ml_advertisers` + `sugador_ml_company_config`) + 2 models + relações em Company + tests schema. (REQ-41-01, REQ-41-02)

**Wave 2** — paralelo, sem overlap:
- **Plan 41-02** — Refactor `MercadoLivreAdsService`: `callWithBackoff` (429/5xx/401/403) + cache advertiser em `discoverAdvertiser` + `getLastRunMetrics` + tests Http::fake (REQ-41-03, REQ-41-04, REQ-41-06)
- **Plan 41-03** — Rate limiter `ml-api` em `AppServiceProvider` + helper `withRateLimit` no MercadoLivreAdsService + tests cobrindo limite excedido (REQ-41-05). NOTA: depende de 41-02 (vai injetar o helper); melhor mover para Wave 3 ou fundir com 41-02. **Decisão:** fundir em 41-02 OU executar SEQUENCIAL 41-02 → 41-03. Sugiro **41-02 absorve 41-03** (escopo cabe).

**Wave 2 revisado** (4 plans total ao invés de 5):
- **Plan 41-02** — Refactor MercadoLivreAdsService completo: callWithBackoff + rate limiter + cache advertiser + métricas + tests (REQ-41-03..06)

**Wave 2 (paralelo com 41-02):**
- **Plan 41-03** — Refactor command `sugadores:shadow-ml` para priorizar `SugadorMlCompanyConfig::where('shadow_enabled')` + fallback env + tests (REQ-41-07)
- **Plan 41-04** — Refactor `ShadowRunService` para mesclar `MercadoLivreAdsService::getLastRunMetrics()` no summary; tests atualizam. Depende: 41-02 (precisa do método novo). Sugiro **Wave 3**.

**Wave 3:**
- **Plan 41-04** (movido) — ShadowRunService integra métricas + tests
- **Plan 41-05** — UI completa: controller + 4 rotas + página React + sidebar item + tests Feature (REQ-41-08, REQ-41-09, REQ-41-10 parte UI)

### Slicing FINAL (5 plans em 3 waves):

| Wave | Plan | Depende | Descrição | REQ |
|------|------|---------|-----------|-----|
| 1 | 41-01 | — | 2 migrations + 2 models + relações Company + test schema | 01, 02 |
| 2 | 41-02 | 41-01 | MercadoLivreAdsService refactor (backoff + cache advertiser + rate limiter + métricas + tests) | 03, 04, 05, 06 |
| 2 | 41-03 | 41-01 | Command shadow-ml prioriza config DB + tests | 07 |
| 3 | 41-04 | 41-02, 41-03 | ShadowRunService integra métricas + tests | 06 (parcial) |
| 3 | 41-05 | 41-04 | Controller + rotas + página React + sidebar + tests Feature | 08, 09, 10 |

**Bloqueio MariaDB:** todos os 5 plans rodam tests em SQLite em-memory. Smoke manual real (clicar em "Rodar smoke agora" no painel novo) continua deferido até MariaDB local voltar.

</plan_slicing_suggestion>

<specifics>
## Specific Ideas

- `MlAdvertiser` Model fillable: `company_id, advertiser_id, seller_id, site_id, raw_data, discovered_at`; casts: `raw_data => array`, `discovered_at => immutable_datetime`
- `SugadorMlCompanyConfig` fillable: `company_id, shadow_enabled, primary_enabled, shadow_started_at, primary_promoted_at, notes`; casts: `shadow_enabled => boolean`, `primary_enabled => boolean`, `shadow_started_at => date`, `primary_promoted_at => date`
- Para garantir `primary_enabled` não vazar para path de produção em Phase 41, factory adiciona check `if ($company->sugadorMlConfig?->primary_enabled) throw "Phase 42 não implementada"` — defensive coding
- UI: `npm run build` obrigatório após edição JSX (CLAUDE.md)
- Tests UI: Inertia Feature tests via `Inertia::assertHasProp` e `assertComponent`
- Rate limiter: usar `Cache::driver('redis')` se disponível (prod), `cache.default` (dev) — Laravel resolve sozinho
- Polling para "shadow rodando agora" não necessário — request síncrono com flash message é suficiente

</specifics>

<deferred>
## Deferred Ideas

- Cut-over real para `ml_primary` (gravar em sugadores via ML) — Phase 42
- Botão "Promover para primary" funcional — Phase 42 (Phase 41 só mostra o candidato)
- Rollback automático em divergência crítica — Phase 42
- Remoção `ADMAN_API_KEY` obrigatório — Phase 43
- Rename tabela `adman_adgroup_mlbs` — Phase 43
- Polling em tempo real do progresso de smoke/shadow — futuro (overkill agora)
- Histórico de runs por empresa visual — futuro (já tem dados nas tabelas, UI extra pode esperar)
- Notificação automática quando empresa cai em `scope_missing` — pode usar Phase 8 BaseNotification; opcional

</deferred>

---

*Phase: 41-onboarding-ml-por-empresa*
*Context gathered: 2026-06-25 via import express path + Phase 38/39/40 deliverables*
