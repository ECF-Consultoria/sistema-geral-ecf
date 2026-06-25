---
phase: 41-onboarding-ml-por-empresa
reviewed: 2026-06-25T20:30:00Z
depth: standard
files_reviewed: 18
files_reviewed_list:
  - database/migrations/2026_06_25_410101_create_ml_advertisers_table.php
  - database/migrations/2026_06_25_410102_create_sugador_ml_company_config_table.php
  - app/Models/MlAdvertiser.php
  - app/Models/SugadorMlCompanyConfig.php
  - app/Models/Company.php
  - app/Providers/AppServiceProvider.php
  - app/Services/Sugadores/MercadoLivreAdsService.php
  - app/Console/Commands/SugadoresShadowMl.php
  - app/Services/Sugadores/ShadowRunService.php
  - app/Http/Controllers/Dev/SugadoresMlOnboardingController.php
  - resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx
  - routes/web.php
  - resources/js/Layouts/AppLayout.jsx
  - tests/Feature/Phase41/Phase41SchemaTest.php
  - tests/Feature/Phase41/MercadoLivreAdsServiceBackoffTest.php
  - tests/Feature/Phase41/SugadoresShadowMlCommandConfigTableTest.php
  - tests/Feature/Phase41/ShadowRunServiceMlMetricsTest.php
  - tests/Feature/Phase41/SugadoresMlOnboardingControllerTest.php
findings:
  critical: 2
  warning: 7
  info: 5
  total: 14
status: issues_found
---

# Phase 41: Code Review Report

**Reviewed:** 2026-06-25T20:30:00Z
**Depth:** standard
**Files Reviewed:** 18
**Status:** issues_found

## Summary

A Phase 41 entrega 5 planos (41-01 a 41-05) cobrindo: migrations (`ml_advertisers`, `sugador_ml_company_config`), refactor producao-ready do `MercadoLivreAdsService` (backoff/cache/rate-limit/metrics), refactor do comando `sugadores:shadow-ml` (DB > env), integracao de `ml_metrics` no `ShadowRunService` e UI admin de onboarding por empresa. A arquitetura geral esta solida e bem testada — porem ha 2 defeitos **BLOCKERS** que comprometem o objetivo central de Phase 41-04 (telemetria ML no shadow) e a observabilidade de cut-over Phase 42:

1. **`MercadoLivreAdsService` nao tem binding singleton** — `ShadowRunService` e `MercadoLivreSugadoresProvider` recebem **instancias diferentes** via DI, entao o `getLastRunMetrics()` lido pelo `ShadowRunService` sempre retorna zeros em producao. Os testes mascaram isso via `$this->app->instance(...)`.
2. **Contador `falhas` do comando shadow conta erros em dobro** — quando `ShadowRunService::run()` lanca `\Throwable`, incrementa apenas 1 falha, mas em sucesso parcial onde `adman_status='completed'` e `ml_status='failed'`, conta corretamente 1; quando `run()` lanca antes de qualquer provider rodar, conta 1 falha — porem nao incrementa `mlOk`/`admanOk` corretamente. Pior: o `final` summary fica com `admanOk + mlOk + falhas != days * 2 * empresas`, quebrando o assert de "completou" no scheduler.

Alem dos blockers, ha 7 warnings que afetam robustez/operabilidade (rate-limit em cache hit, timeout em token, log noise, defensive-coding do toggle, fingerprint silencioso da diferenca ml_metrics/timing) e 5 info items de qualidade/consistencia.

---

## Critical Issues

### CR-01: `MercadoLivreAdsService` nao tem binding singleton — `ml_metrics` sempre vazio em producao

**File:** `app/Providers/AppServiceProvider.php` (ausencia de binding) + `app/Services/Sugadores/ShadowRunService.php:150-158`

**Issue:**
O `ShadowRunService` recebe `?MercadoLivreAdsService $mlAds` via DI (construtor) e o `MercadoLivreSugadoresProvider` (consumido por dentro do `SugadorAnalysisService` durante `analyzeCompany(..., 'ml')`) tambem recebe `MercadoLivreAdsService` via construtor. Sem registro como `singleton` no container, o Laravel resolve **2 instancias distintas**. A instancia que de fato faz as chamadas HTTP (e acumula `total_calls`, `pages_read`, `rate_limit_429`, `refresh_token_count`, `backoff_sleep_ms`) e a do `MercadoLivreSugadoresProvider`. Quando o `ShadowRunService` (linha 152) chama `$this->mlAds->getLastRunMetrics()`, le da SUA propria instancia — que nunca foi usada — e devolve um snapshot com TODAS as metricas zeradas (exceto `total_duration_ms` que reflete o tempo desde a injecao da instancia ate a leitura, nao a chamada HTTP).

Os testes (`ShadowRunServiceMlMetricsTest::summary_ml_contem_ml_metrics_quando_provider_ml`) **nao pegam o bug** porque o `mockMlAds()` faz `$this->app->instance(MercadoLivreAdsService::class, $mock)` — ou seja, binda a mesma instancia mock para qualquer resolucao via container. Em producao, o servidor real nao tem esse binding.

Em producao, todas as rows `sugador_provider_runs` do provider `ml` terao `summary.ml_metrics = {total_calls: 0, pages_read: 0, rate_limit_429: 0, refresh_token_count: 0, backoff_sleep_ms: 0, total_duration_ms: <microsegundos da injecao DI>}`. O dashboard de paridade (UI Plan 41-05) e o cut-over Phase 42 nao terao telemetria real para decidir, comprometendo o objetivo central do Plan 41-04.

**Fix:**

Em `app/Providers/AppServiceProvider.php::register()`:

```php
public function register(): void
{
    // Phase 20 — EcfDriveService...
    $this->app->singleton(\App\Services\EcfDriveService::class, ...);

    // Phase 41-04 — MercadoLivreAdsService precisa ser singleton porque o
    // ShadowRunService le getLastRunMetrics() de uma instancia que precisa
    // ser a MESMA usada pelo MercadoLivreSugadoresProvider durante
    // analyzeCompany(provider='ml'). Sem singleton, sao 2 instancias e a
    // leitura retorna zeros.
    $this->app->singleton(\App\Services\Sugadores\MercadoLivreAdsService::class);
}
```

E adicionar um teste de regressao que NAO usa `$this->app->instance(...)`, mas faz spy real:

```php
public function ml_metrics_refletem_chamadas_reais_via_singleton_binding(): void
{
    // Bind concrete service ($this->app instance only spies real metrics)
    $service = $this->app->make(MercadoLivreAdsService::class);
    $this->assertSame(
        $service,
        $this->app->make(MercadoLivreAdsService::class),
        'MercadoLivreAdsService precisa ser singleton em producao.'
    );
}
```

---

### CR-02: Contador `falhas` no comando `sugadores:shadow-ml` conta erros em dobro quando `run()` lanca antes dos providers rodarem

**File:** `app/Console/Commands/SugadoresShadowMl.php:92-109`

**Issue:**

Quando `$this->shadow->run($company, $refDate)` lanca uma `\Throwable` (linha 94), o catch em 94-98 incrementa `$falhas++` **uma vez** e usa `continue` — pulando os incrementos das linhas 100-109 que sao executados em caminhos de sucesso normais. Porem, semanticamente, uma falha em `run()` significa que **2 providers** (Adman e ML) nao foram processados, porque `executeForProvider()` por provider tem seu proprio try/catch que captura ate `\Throwable`. A unica forma de `run()` lancar e o `SugadorConfig::forCompany($company)` lancar antes do loop dos providers. Resultado:

- `admanOk + mlOk + falhas` nao bate com `2 * days * len(companies)` na conta total (que era a invariante esperada para o sumario final).
- A mensagem final `"Concluído: {$admanOk} runs Adman ok, {$mlOk} runs ML ok, {$falhas} falhas."` da informacao enganosa para o operador: ele ve `falhas=1` quando na realidade 2 providers nao foram processados.
- Quando `--days=N` e o erro acontece em uma das datas mas nao em outras, o operador nao consegue derivar quantos dias completos rodaram.

Cenarios:
1. `run()` lanca em 1 data → `falhas=1`, mas 2 providers x 1 dia = 2 providers nao processados.
2. `run()` retorna com `adman_status='failed'` E `ml_status='failed'` → `falhas=2` (correto).
3. `run()` retorna com `adman_status='completed'` E `ml_status='failed'` → `admanOk=1, falhas=1` (correto).

**Fix:**

```php
foreach ($companies as $company) {
    for ($i = 0; $i < $days; $i++) {
        $refDate = now()->startOfDay()->subDays($i);
        $this->line("Empresa {$company->id} ({$company->name}) — {$refDate->toDateString()}");

        try {
            $result = $this->shadow->run($company, $refDate);
        } catch (\Throwable $e) {
            // Falha de orquestracao antes dos providers — conta como 2 falhas
            // (1 Adman + 1 ML nao executados) pra invariante batter no sumario.
            $falhas += 2;
            $this->error("  Erro orquestracao: " . $e->getMessage());
            continue;
        }

        if (($result['adman_status'] ?? null) === 'completed') {
            $admanOk++;
        } else {
            $falhas++;
        }
        if (($result['ml_status'] ?? null) === 'completed') {
            $mlOk++;
        } else {
            $falhas++;
        }
        // ...
    }
}
```

Considerar tambem adicionar um teste no `SugadoresShadowMlCommandConfigTableTest` que simula `ShadowRunService::run()` lancando para validar o contador.

---

## Warnings

### WR-01: `withRateLimit` consome bucket mesmo em chamadas que vao falhar com 4xx imediato (403/404)

**File:** `app/Services/Sugadores/MercadoLivreAdsService.php:133-146, 169-247`

**Issue:**

O `withRateLimit` chama `RateLimiter::hit` **antes** do callback executar. Em chamadas que vao retornar 403 (scope missing) ou 404, o bucket consome 1 hit por requisicao mesmo que essas falhas sejam imediatas e nao precisem ser repetidas. Em uma empresa com scope ML faltando, cada `discoverAdvertiser` queima 1 hit/min do bucket — depois de 60 tentativas (1 hora), bloqueia chamadas legitimas para a mesma `seller_id`.

Adicionalmente, quando o response e 5xx ou 429 e fazemos `continue` (linhas 198-215), cada retry **tambem** consome 1 hit no `withRateLimit` (porque a chamada e re-feita dentro do loop). Em 5 tentativas de 5xx, o bucket recebe 5 hits. Se o limite era 60/min e esta sequencia consome 5, restam 55 — aceitavel mas vale registrar.

**Fix:**

Aceitar a contagem dos retries (sao chamadas HTTP reais), mas garantir que 403 nao bloqueia indefinidamente o bucket. Considerar circuit breaker:

```php
private function withRateLimit(?string $sellerId, callable $cb): mixed
{
    $sellerId ??= 'unknown';
    $key = "ml-api:{$sellerId}";

    if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_MIN)) {
        throw new \RuntimeException(
            "[MercadoLivreAds] Rate limit ML excedido para seller {$sellerId} (60/min). Tente novamente em 1 minuto."
        );
    }
    RateLimiter::hit($key, self::RATE_LIMIT_DECAY_SEC);

    return $cb();
}
```

Considerar tambem registrar circuit breaker por company quando 403 persiste — flag em `ml_advertisers` ou nova tabela `ml_circuit_breaker` para nao tentar novamente nos proximos 6h.

---

### WR-02: HTTP request sem timeout — chamada ML pode pendurar processo PHP

**File:** `app/Services/Sugadores/MercadoLivreAdsService.php:179-183`

**Issue:**

```php
$response = $this->withRateLimit($sellerId, fn () =>
    Http::withToken($token->access_token)
        ->withHeaders($headers)
        ->get($url, $query)
);
```

Nenhum `->timeout(N)` configurado. O default do client HTTP do Laravel (Guzzle subjacente) e **30s timeout** mas **null connection timeout** — em caso de servidor ML lento ou network split, o worker PHP fica esperando indefinidamente. Considerando que `MAX_ATTEMPTS=5` e que 5xx leva backoff exponencial (2+4+8+16+32 = 62s em pior caso), um unico `discoverAdvertiser` pode demorar minutos. Em um `--company=all` com 50 empresas, pode pendurar o worker do scheduler por horas.

**Fix:**

```php
$response = $this->withRateLimit($sellerId, fn () =>
    Http::withToken($token->access_token)
        ->withHeaders($headers)
        ->timeout(15)               // hard 15s por chamada
        ->connectTimeout(5)          // 5s para conectar
        ->get($url, $query)
);
```

15s alinha com o ritmo das chamadas Adman (Phase 30 usa ~15s). Connection timeout 5s e padrao defensivo. Documentar no docblock que timeouts sao parte do contrato.

---

### WR-03: `toggleShadow` desabilitado quando `advertiser` virou `none` — operador perde controle de desligar

**File:** `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php:144-148` + `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx:344-370`

**Issue:**

```php
'can_toggle_shadow' => $tokenState === 'active' && $advertiser === 'ok',
```

Empresa que esta com `shadow_enabled=true` mas cujo `MlAdvertiser` cache expirou OU que entrou em estado degradado (token revoked) **nao pode ser desativada via UI** — o botao `Toggle` fica disabled. O operador precisa abrir uma transacao SQL direto ou um Artisan tinker para limpar `shadow_enabled=false`. Isso e particularmente problematico durante incidentes (token revogado, falha de scope) onde voce **quer** desativar shadow rapidamente.

A regra de `can_run_smoke`/`can_run_shadow` e correta (precisa de advertiser pra fazer chamadas reais), mas `toggleShadow` apenas escreve no DB local — nao deveria estar gateado pela presenca de advertiser.

**Fix:**

```php
'actions'            => [
    'can_run_smoke'     => $tokenState === 'active',
    'can_run_shadow'    => $tokenState === 'active' && $advertiser === 'ok',
    // Toggle e operacao local apenas — nao precisa nem de token nem advertiser.
    // Operador deve poder desativar shadow durante incidentes mesmo com token error.
    'can_toggle_shadow' => true,
],
```

Considerar tambem deixar `can_toggle_shadow` sempre verdadeiro mas mudar o texto do tooltip quando token/advertiser ausentes para deixar claro o efeito.

---

### WR-04: `resolveLastSmoke` faz I/O na storage para cada empresa em loop — O(N) listagens redundantes

**File:** `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php:174-196`

**Issue:**

`index()` itera sobre todas as empresas ativas e chama `resolveLastSmoke($c->id)` para cada uma. Dentro, `Storage::disk('local')->files('sugadores/ml-smoke')` lista TODOS os arquivos do diretorio a cada chamada. Se ha 100 empresas e 500 arquivos de smoke, e 100 * 500 = 50k comparacoes de string + 100 listagens de diretorio. Em produc̃ao com VPS modesta, isso pode causar latencia perceptivel no GET `/dev/sugadores-ml-onboarding`.

Estritamente isto e "performance" (out of scope v1) mas o filesystem I/O em loop tambem pode disparar warnings se o diretorio nao existe, e o try/catch defensivo so captura excecoes — nao detecta corrupcao silenciosa.

**Fix:**

Listar uma vez fora do loop e indexar por company_id:

```php
private function buildSmokeIndex(): array
{
    try {
        $files = Storage::disk('local')->files('sugadores/ml-smoke');
        $index = [];
        foreach ($files as $f) {
            // basename: "{companyId}-{date}-{time}.json"
            $basename = basename($f);
            if (preg_match('/^(\d+)-/', $basename, $m)) {
                $companyId = (int) $m[1];
                $mtime = Storage::disk('local')->lastModified($f);
                if (!isset($index[$companyId]) || $mtime > $index[$companyId]) {
                    $index[$companyId] = $mtime;
                }
            }
        }
        return $index;
    } catch (\Throwable $e) {
        Log::warning('[SugadoresMlOnboarding] Falha ao listar smokes', ['msg' => $e->getMessage()]);
        return [];
    }
}
```

Passar `$smokeIndex` para `buildRow` e fazer lookup O(1).

---

### WR-05: `MercadoLivreAdsService::callWithBackoff` chama `usleep` apos a ULTIMA tentativa (5xx/429)

**File:** `app/Services/Sugadores/MercadoLivreAdsService.php:197-215`

**Issue:**

```php
if ($status === 429) {
    // ...
    usleep($waitMs * 1000);
    continue;
}
if ($status >= 500) {
    // ...
    usleep($waitMs * 1000);
    continue;
}
```

Quando estamos na 5a tentativa (`$attempt === MAX_ATTEMPTS`) e o response e 5xx, fazemos o `usleep` antes do `continue`. O `continue` volta para o `while ($attempt < MAX_ATTEMPTS)` que e `5 < 5 = false`, saindo do loop e lancando RuntimeException. O `usleep` antes foi **trabalho perdido** — pode dormir ate 32s + jitter antes de abortar.

Em pior caso (5xx persistente com cap), o tempo total e `2 + 4 + 8 + 16 + 32 = 62s` mais jitter — o ultimo `32s` e desnecessario.

**Fix:**

```php
if ($status >= 500) {
    if ($attempt >= self::MAX_ATTEMPTS) {
        break; // sai do while, vai pro throw final
    }
    $wait = min(2 ** $attempt, self::BACKOFF_CAP_SECONDS);
    $waitMs = ($wait * 1000) + random_int(0, 1000);
    $this->metrics['backoff_sleep_ms'] += $waitMs;
    usleep($waitMs * 1000);
    continue;
}
```

Mesmo padrao para o 429. Resultado: economiza ate ~30s de wait perdido + ainda mais determinismo do timeout total.

---

### WR-06: Comando `runShadow` no controller hardcoda `--days=1` — UI nao deixa operador rodar janela maior

**File:** `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php:81-89`

**Issue:**

```php
public function runShadow(Company $company): RedirectResponse
{
    Artisan::queue('sugadores:shadow-ml', [
        '--company' => (string) $company->id,
        '--days'    => 1,
    ]);
    ...
}
```

O comando `sugadores:shadow-ml` aceita `--days=N` (clamp 1..90), mas o controller hardcoda `--days=1`. Para reproduzir paridade historica de uma empresa (ex: investigar discrepancia de 5 dias atras), o operador precisa abrir terminal no VPS — a UI nao serve. Pequena qualidade operacional perdida.

Considerar receber `days` via request:

**Fix:**

```php
public function runShadow(Request $request, Company $company): RedirectResponse
{
    $days = (int) $request->input('days', 1);
    $days = max(1, min(7, $days)); // clamp UI 1..7 (manual usa 1..90)

    Artisan::queue('sugadores:shadow-ml', [
        '--company' => (string) $company->id,
        '--days'    => $days,
    ]);

    return back()->with('success', "Shadow ML ({$days}d) disparado para {$company->name}.");
}
```

E adicionar input no frontend. Pode ficar para um segundo iteration se for muito escopo agora; pelo menos documentar a limitacao.

---

### WR-07: `Index.jsx` filtros e KPIs nao reagem a mudancas no payload — operador precisa reload manual

**File:** `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx:139-179`

**Issue:**

Apos `dispararAcao` (smoke/shadow/toggle), o componente faz `router.post(...)`. O `onFinish` so limpa o estado de loading, mas Inertia faz reload automatico das props se o servidor retornar `back()->with('success', ...)`. Porem o componente `companies` so atualiza no proximo GET — toggleShadow muda DB mas o ParityCell, StatusBadge e KPIs continuam mostrando o estado antigo ate o usuario navegar para outra pagina e voltar.

`router.post(..., { preserveScroll: true })` por default **nao** reusa as props da resposta — `back()` retorna um redirect que dispara um novo GET com as props atualizadas. Mas o operador precisa esperar esse round-trip. Sem polling, o feedback visual fica desatualizado por alguns segundos.

Adicionalmente, smoke/shadow sao Artisan::queue — sao async, entao mesmo que reloademos, o "smoke_last_at" so vai aparecer quando o worker rodar (15min do scheduler ou imediato se ja ha worker). UI nao indica esse delay.

**Fix:**

Para `toggleShadow` (sincrono no DB): garantir que `router.post` use `preserveState: false` para forcar reload (default ja faz isso). Confirmar com test manual.

Para `runSmoke`/`runShadow`: adicionar tooltip ou banner explicando "Disparado assincrono — atualize a pagina em alguns minutos para ver o resultado".

Considerar adicionar polling otimista (every 30s) para a UI quando ha `emAcao` itens, mas isso esta no `CONTEXT §Deferred` — deixar como info por enquanto.

---

## Info

### IN-01: Defensive coding desnecessario em `MlAdvertiser` cache check

**File:** `app/Services/Sugadores/MercadoLivreAdsService.php:285`

**Issue:**

```php
if ($cached && $cached->discovered_at && $cached->discovered_at->gt(now()->subDays(self::CACHE_TTL_DAYS))) {
```

O check `$cached->discovered_at` (linha 285) e redundante: a migration declara `$table->timestampTz('discovered_at')` sem `->nullable()`, entao a coluna e NOT NULL. O cast `immutable_datetime` sempre devolve `CarbonImmutable` quando a coluna nao e null. O `&&` curto-circuita mas e sinal de codigo defensivo sem fundamento.

**Fix:** Simplificar para `if ($cached && $cached->discovered_at->gt(now()->subDays(self::CACHE_TTL_DAYS)))`. Ou, melhor, registrar um comentario explicando o porque do double-check (caso a coluna venha a ser nullable no futuro).

---

### IN-02: `site_id` inconsistente entre cache miss e cache hit

**File:** `app/Services/Sugadores/MercadoLivreAdsService.php:308-334`

**Issue:**

Quando o cache e miss/expirado e a API retorna sem `site_id`, o retorno `site_id` no array de resposta e `null` (linha 309 `$advertisers[0]['site_id'] ?? null`). Mas o cache grava `$siteId ?? 'MLB'` (linha 319). Entao um cache hit posterior retorna `'MLB'` e nao `null`. Pequena inconsistencia que pode quebrar testes assert dependendo do caminho.

**Fix:** Normalizar a resposta tambem na primeira chamada:

```php
$siteId = $advertisers[0]['site_id'] ?? 'MLB';
```

E retornar `$siteId` direto em ambas as branches.

---

### IN-03: `array_map('intval', $ids)` no resolve do command e no-op porque config ja retorna ints

**File:** `app/Console/Commands/SugadoresShadowMl.php:151-153`

**Issue:**

```php
$ids = (array) config('sugadores.ml_shadow_companies', []);
return array_map('intval', $ids);
```

Olhando `config/sugadores.php`, o array ja sai como `int[]` (passa por `array_map('intval', ...)` la). Esse segundo `array_map` e redundante. Nao causa bug — apenas codigo dupliado.

**Fix:** Remover ou comentar como defesa: `// defesa caso config seja redefinido em tempo de execucao.`

---

### IN-04: `excludeRoles` no item Dev > "Sugadores ML Onboarding" lista publication roles que nao sao mainRole

**File:** `resources/js/Layouts/AppLayout.jsx:86`

**Issue:**

```jsx
{ label: 'Sugadores ML Onboarding', ..., excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
```

Per CLAUDE.md, `User.role` so aceita `admin`, `consultor`, `mentor`. Os outros valores (`publicador`, `analista`, `gestor`, `lider`) sao `publication_role` — outro campo. O filtro `excludeRoles: ['publicador', ...]` checa contra `mainRole = user?.role`, entao esses valores **nunca** vao matchar.

O gate funciona em pratica (so admin nao esta na lista) mas e codigo enganoso e pode confundir manutencao futura. Sugestao: simplificar para o pattern usado em outros lugares ou usar `permission` explicita.

**Fix:**

```jsx
// Equivalente semantico mais limpo:
{ label: 'Sugadores ML Onboarding', routeName: '...', page: '...', icon: Activity, excludeRoles: ['consultor', 'mentor'] },
```

Ou criar uma permission_key dedicada `sistema.sugadores_ml_onboarding` para alinhar com o pattern dos outros itens dev.

---

### IN-05: `getActivitylogOptions()` em `Company` nao loga campos novos da Phase 41

**File:** `app/Models/Company.php:14-26` + `app/Models/MlAdvertiser.php` + `app/Models/SugadorMlCompanyConfig.php`

**Issue:**

Por convencao de projeto (CLAUDE.md "Activity Logging"), modelos principais usam `LogsActivity`. Os models `MlAdvertiser` e `SugadorMlCompanyConfig` documentam intencionalmente "NAO usa LogsActivity" — escolha defensavel. Porem nao ha rastreamento de quem ativou `shadow_enabled` em qual empresa — isso e info crítica para auditoria do cut-over Phase 42.

Considere registrar atividade no controller (em vez do model):

**Fix:**

```php
public function toggleShadow(Company $company): RedirectResponse
{
    // ... logic ...
    $config->save();

    activity('sugadores-ml-onboarding')
        ->performedOn($company)
        ->causedBy(auth()->user())
        ->withProperties(['shadow_enabled' => $config->shadow_enabled])
        ->log($config->shadow_enabled ? 'Shadow ML ativado' : 'Shadow ML desativado');

    // ... return ...
}
```

Pequeno overhead, audit trail rico — segue o pattern de `AppServiceProvider::boot()` para Login/Logout.

---

_Reviewed: 2026-06-25T20:30:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
