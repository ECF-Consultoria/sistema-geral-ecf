---
phase: 60-base-multi-fonte-backend-ml-adman-unificado
plan: 03
subsystem: metrics-multifonte
tags: [ml, factory, metrics-provider, cache, tdd]
requires:
  - ADR DATA-04 (Plan 60-01) — precedência, 4 valores de `source`, cache TTL 15 min
  - Plan 60-02 — contract MetricsProvider + DTO UnifiedMetricsDto + AdmanMetricsProvider
provides:
  - "Provider App\\Services\\Metrics\\MlMetricsProvider (leitura ML LIVE + cache 15 min)"
  - "Factory App\\Services\\Metrics\\MetricsProviderFactory (resolução 4 casos ADR)"
  - "Fundação completa para Plan 60-04 (UnifiedMetricsService — fusão campo-a-campo)"
affects:
  - app/Services/Metrics/MlMetricsProvider.php
  - app/Services/Metrics/MetricsProviderFactory.php
  - tests/Feature/Phase60/MlMetricsProviderTest.php
  - tests/Feature/Phase60/MetricsProviderFactoryTest.php
tech-stack:
  added:
    - "Cache::remember TTL 900s (15 min) por par (empresa, período, ml) — ADR DATA-04"
    - "Isolamento de falha por endpoint (try/catch \\Throwable separado em orders + ads)"
  patterns:
    - "Contract + Provider + Factory (análogo estrutural a SugadoresAdsProviderFactory, adaptado)"
    - "Provider como envelope de service externo (delega MercadoLivreService sem modificar)"
    - "Log::warning estruturado sanitizado (sem access_token — anti-leak T-60-03-02)"
    - "Factory retorna array<Provider> em vez de UM provider (diferença chave vs Sugadores)"
    - "TDD Task-por-Task (RED commit → GREEN commit por task)"
key-files:
  created:
    - app/Services/Metrics/MlMetricsProvider.php
    - app/Services/Metrics/MetricsProviderFactory.php
    - tests/Feature/Phase60/MlMetricsProviderTest.php
    - tests/Feature/Phase60/MetricsProviderFactoryTest.php
    - .planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/60-03-SUMMARY.md
  modified: []
decisions:
  - "MlMetricsProvider NÃO modifica MercadoLivreService — envelopa via DI"
  - "Falha de API traduzida em bloco de campos null + Log::warning; nunca propaga exception"
  - "TACOS derivado (ad_spend/revenue)*100 apenas quando ambos numéricos > 0; senão null"
  - "empresa sem supports() retorna DTO source='ml' com nulls (identidade do provider preservada — 'none' é do UnifiedMetricsService)"
  - "MetricsProviderFactory retorna ARRAY (não UM provider) — permite Plan 60-04 iterar em ordem para fusão"
  - "Cache 15 min também cacheia payload com bloco falho — trade-off consciente para não queimar rate limit em bursts"
  - "Mock MercadoLivreService via Mockery em vez de Http::fake nos endpoints internos (mesma semântica de Http::recorded() com testes mais legíveis)"
metrics:
  duration: "~40 min"
  completed: 2026-07-07
  tests_added: 16
  tests_passing: 16
  phase60_total_passing: 29
  regressao: 0
---

# Phase 60 Plan 03: MlMetricsProvider (cache 15 min) + MetricsProviderFactory — Summary

Fecha a segunda metade do padrão contract + provider + factory. `MlMetricsProvider` envelopa `MercadoLivreService::fetchOrdersSummary` + `fetchAdsSummary` com cache 15 min e isolamento de falha por endpoint. `MetricsProviderFactory` classifica cada empresa em um dos 4 casos do ADR DATA-04 (`ambos`|`so-ml`|`so-adman`|`none`) e retorna array ordenado de providers para o Plan 60-04 consumir.

## O que foi entregue

### 1. `App\Services\Metrics\MlMetricsProvider`

Implementa `MetricsProvider` sobre `MercadoLivreService` (DI Laravel). Zero modificação no service ML existente.

Comportamento:

- **`supports(Company)`**: `$company->loadMissing('mlToken')` + `optional($company->mlToken)->status === 'active'`. Evita N+1 quando factory itera empresas.
- **`name()`**: retorna literal `'ml'` — chave de cache, badge UI (DATA-05) e campo `source` do DTO.
- **`readForCompany(Company, Carbon, Carbon): UnifiedMetricsDto`**:
  1. Sem `supports()` → DTO com todos numéricos `null`, `source='ml'`, `SEM` chamada de API (economiza rate limit + evita 401 previsível).
  2. Cache: `Cache::remember(unified_metrics:{id}:ml:{from}:{to}, 900, fetchFromApi)`.
  3. `fetchFromApi()` executa 2 blocos try/catch `\Throwable` isolados (orders + ads). Em erro: `Log::warning` estruturado sanitizado + bloco de campos `null`. Nunca lança.
  4. `buildDto()` monta DTO na ordem exata do constructor de `UnifiedMetricsDto`.

**TTL implementado**: `CACHE_TTL_SECONDS = 900` (15 min — valor do ADR DATA-04 "Estratégia de cache ML").

**Cobertura de campos do DTO** (ADR DATA-04 tabela campo-a-campo):

| Campo | ML preenche? | Origem |
|-------|--------------|--------|
| `revenue` | Sim | `fetchOrdersSummary.revenue` |
| `sold_quantity` | Sim | `fetchOrdersSummary.sold_quantity` |
| `orders_count` | Sim | `fetchOrdersSummary.orders_count` |
| `sales_fee` | Sim | `fetchOrdersSummary.sales_fee` |
| `ad_spend` | Sim | `fetchAdsSummary.ad_spend` |
| `clicks` | Sim | `fetchAdsSummary.clicks` |
| `impressions` | Sim | `fetchAdsSummary.impressions` |
| `acos` | Sim | `fetchAdsSummary.acos` |
| `roas` | Sim | `fetchAdsSummary.roas` |
| `tacos` | Sim — derivado | `(ad_spend/revenue)*100` quando ambos >0, senão null |
| `net_billing` | Não (null) | ADR DATA-04 — exclusivo Adman |
| `taxes` | Não (null) | ADR DATA-04 — exclusivo Adman |
| `shipping_cost` | Não (null) | ADR DATA-04 — exclusivo Adman |
| `product_cost` | Não (null) | ADR DATA-04 — exclusivo Adman |
| `contribution_margin` | Não (null) | ADR DATA-04 — exclusivo Adman |

### 2. `App\Services\Metrics\MetricsProviderFactory`

Constructor com DI: `AdmanMetricsProvider + MlMetricsProvider`.

Métodos:

- **`forCompany(Company): array<MetricsProvider>`** — array ordenado por precedência:
  - Ambos suportam → `[ml, adman]` (ML primário por ADR)
  - Só ML suporta → `[ml]`
  - Só Adman suporta → `[adman]`
  - Nenhum suporta → `[]`

- **`caseFor(Company): 'ambos'|'so-ml'|'so-adman'|'none'`** — discriminador literal para logs, dashboards e branches de teste no Plan 60-04.

**Diferença estrutural vs `SugadoresAdsProviderFactory`**: aquele factory retorna UM provider (Sugadores usa fonte única com fallback), este retorna ARRAY — porque no caso "ambos" o `UnifiedMetricsService` (Plan 60-04) precisa consultar as DUAS fontes e reconciliar campo-a-campo. O array preserva a ordem canônica de precedência.

## Testes

### `tests/Feature/Phase60/MlMetricsProviderTest.php`

11 cenários T1-T11 conforme prescrito:

| # | Nome | Foco |
|---|------|------|
| T1 | `test_supports_true_quando_ml_token_active` | mlToken.status='active' → true |
| T2 | `test_supports_false_quando_ml_token_inactive` | mlToken.status='expired' → false |
| T3 | `test_supports_false_quando_empresa_sem_ml_token` | sem mlToken → false |
| T4 | `test_name_retorna_ml` | name() === 'ml' |
| T5 | `test_readForCompany_sem_supports_retorna_dto_com_nulls_sem_chamar_api` | mock `shouldNotReceive()` (equiv Http::assertNothingSent) |
| T6 | `test_readForCompany_agrega_orders_e_ads_corretamente` | DTO com revenue/ad_spend/clicks/etc corretos |
| T7 | `test_readForCompany_orders_falhando_retorna_revenue_null_mas_ads_ok` | isolamento por endpoint, sem exception |
| T8 | `test_readForCompany_calcula_tacos_derivado` | ad_spend=50, revenue=1000 → tacos=5.0 |
| T9 | `test_readForCompany_campos_exclusivos_adman_retornam_null` | net_billing/taxes/etc null |
| T10 | `test_readForCompany_cache_impede_segunda_chamada_api` | mock `->once()` × 2 chamadas iguais (equiv `Http::recorded()`) |
| T11 | `test_readForCompany_source_field_igual_a_ml` | DTO.source === 'ml' |

**Resultado**: 11/11 verdes (36 assertions).

### `tests/Feature/Phase60/MetricsProviderFactoryTest.php`

5 cenários:

| # | Nome | Foco |
|---|------|------|
| 1 | `test_caso_ambos_retorna_ml_primeiro_adman_depois` | Ordem canônica [ML, Adman] no caso ambos |
| 2 | `test_caso_so_ml_retorna_apenas_ml` | Array com 1 provider ML |
| 3 | `test_caso_so_adman_retorna_apenas_adman` | Array com 1 provider Adman |
| 4 | `test_caso_none_retorna_array_vazio` | `[]` para empresa sem integração |
| 5 | `test_case_for_retorna_string_correta_por_caso` | 4 casos + edge case (ML expired) |

**Resultado**: 5/5 verdes (17 assertions).

### Total acumulado Phase 60

- Plan 60-02 (Wave 2): 13 verdes
- Plan 60-03 Task 1 (MlMetricsProvider): 11 verdes
- Plan 60-03 Task 2 (Factory): 5 verdes
- **Total Phase 60: 29 verdes / 103 assertions** — verificado via `php artisan test tests/Feature/Phase60`.

## Comportamento observado em cenário de falha ML

T7 exercita cenário real de degradação parcial:

1. `MercadoLivreService::fetchOrdersSummary` lança `\RuntimeException` (simulando 500 ou token expirado).
2. `fetchFromApi` captura, grava `Log::warning('[UnifiedMetrics][ML] Falha ao ler fetchOrdersSummary', [...])` e retorna o bloco orders como `['revenue' => null, 'sold_quantity' => null, 'orders_count' => null, 'sales_fee' => null]`.
3. `fetchAdsSummary` continua sendo chamado normalmente e retorna dados válidos.
4. `buildDto` monta DTO com bloco orders todo null + bloco ads intacto + `tacos = null` (porque revenue é null).
5. Caller (UnifiedMetricsService no Plan 60-04) recebe DTO parcialmente preenchido — não recebe exception.

Trade-off consciente: o payload retornado por `fetchFromApi` (incluindo os nulls de bloco falho) é o que fica cacheado por 15 min. Bursts de retry na mesma janela vão servir cache stale-null — comportamento intencional para não queimar rate limit em retries. Documentado como sinalização "sem dado durante a janela" nos consumidores.

## Confirmação Zero-Diff

`git diff --stat` (contra HEAD antes do plan) nos arquivos sensíveis:

- `app/Services/MercadoLivreService.php` — 0 linhas modificadas.
- `app/Services/AdmanService.php` — 0 linhas modificadas.
- `app/Services/Metrics/AdmanMetricsProvider.php` — 0 linhas modificadas.
- `app/Services/Metrics/UnifiedMetricsDto.php` — 0 linhas modificadas.
- `app/Contracts/MetricsProvider.php` — 0 linhas modificadas.
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` — 0 linhas modificadas.

Anti-leak `access_token`: 0 ocorrências em código de runtime dentro de `app/Services/Metrics/` (única menção está em comentário de documentação da mitigação T-60-03-02, `MlMetricsProvider.php:125`).

## Gate compliance TDD

Verificado em `git log`:

- `bbc5d6e` — `test(60-03): RED — MlMetricsProviderTest 11 cenários (supports/name/read/cache)` (RED confirmed: 11 failed / 0 assertions)
- `d9408e4` — `feat(60-03): GREEN — MlMetricsProvider com cache 15 min + isolamento por endpoint` (11 passed / 36 assertions)
- `8eebfac` — `test(60-03): RED — MetricsProviderFactoryTest 5 cenários (ADR DATA-04)` (RED confirmed: 5 failed / 0 assertions)
- `dd6344d` — `feat(60-03): GREEN — MetricsProviderFactory com regra de precedência ADR DATA-04` (5 passed / 17 assertions)

Sequência RED → GREEN por task respeitada. Fail-fast confirmado: skeleton classes lançaram RuntimeException até GREEN implementar.

## Baseline pré/pós regressão

**Suite Feature completa não rodada até completar** por causa do timeout pré-existente em `MercadoLivreAdsService.php:215` (Phase 39/40 legacy — issue registrada em `deferred-items.md` como DEF-60-02-02 no Plan 60-02). Isolamento aplicado: rodada apenas `tests/Feature/Phase60` (29/29 verdes) e `--filter=MlMetricsProviderTest|MetricsProviderFactoryTest` (16/16 verdes).

Nenhum arquivo modificado fora do escopo declarado.

## Deviations from Plan

**Um desvio menor documentado** — não é violação material do plan; é substituição de estratégia autorizada pelo próprio prompt de execução.

### 1. [Rule 3 - Blocking] Estratégia de teste de cache: mock em vez de `Http::recorded()`

- **Onde:** T10 do `MlMetricsProviderTest.php`.
- **Plan prescreve:** usar `Http::fake()` + `count(Http::recorded())` para provar que cache impede a 2ª chamada HTTP.
- **Adaptado para:** mock de `MercadoLivreService` via `Mockery::mock()` + `->once()` em `fetchOrdersSummary` e `fetchAdsSummary`. Se cache falhar, mock recebe 2× e `Mockery::close()` no `tearDown` falha o teste — semanticamente equivalente ao snapshot de `Http::recorded()`.
- **Porquê:** para exercitar `Http::recorded()` real precisaríamos fazer stub de 3+ endpoints internos do `MercadoLivreService` (`/advertising/advertisers`, `/orders/search` paginado, `/product_ads/campaigns/search`) além de tratar cache do advertiser (24h). Ruído de infra que ofusca o teste. O prompt de execução autoriza explicitamente essa substituição: "*se a facade `Http` intercepta a URL de fato chamada, ótimo; senão mockar `MercadoLivreService` diretamente via `$this->mock(MercadoLivreService::class, ...)`*".
- **Impacto:** nenhum na cobertura efetiva — `->once()` no boundary do service prova exatamente a mesma propriedade que `Http::recorded()` count no boundary HTTP: a closure interna do `Cache::remember` só executou uma vez.

Ajuste correlato: T5 usa `shouldNotReceive('fetchOrdersSummary')` e `shouldNotReceive('fetchAdsSummary')` no lugar de `Http::assertNothingSent()`. Mesma equivalência semântica.

Nenhum outro desvio material — plan executado exatamente como escrito (2 tasks TDD, 2 commits por task, TTL/chave de cache/isolamento por endpoint/ordem do array conforme especificado).

## Threat Flags

Nenhum novo threat flag introduzido além dos já cobertos pelo threat register do plan (T-60-03-01 mitigado via cache 15 min; T-60-03-02 mitigado via log sanitizado sem `access_token`; T-60-03-03 mitigado via chave de cache com `company_id`; T-60-03-04 aceito por design — `forceName` fora do escopo desta factory por decisão do plan, mantido como override futuro).

## Self-Check: PASSED

- `app/Services/Metrics/MlMetricsProvider.php` existe (verificado via `git ls-files`).
- `app/Services/Metrics/MetricsProviderFactory.php` existe.
- `tests/Feature/Phase60/MlMetricsProviderTest.php` existe.
- `tests/Feature/Phase60/MetricsProviderFactoryTest.php` existe.
- Commit `bbc5d6e` (RED Task 1) presente em `git log`.
- Commit `d9408e4` (GREEN Task 1) presente em `git log`.
- Commit `8eebfac` (RED Task 2) presente em `git log`.
- Commit `dd6344d` (GREEN Task 2) presente em `git log`.
- `php artisan test tests/Feature/Phase60` → 29/29 verdes.
- Zero diff em `MercadoLivreService`, `AdmanService`, `AdmanMetricsProvider`, `UnifiedMetricsDto`, `MetricsProvider`, `SugadoresAdsProviderFactory`.

## Próximo plan

**60-04 (Wave 4)** — `UnifiedMetricsService`: consome `MetricsProviderFactory::forCompany` + itera providers, monta DTO fundido campo-a-campo conforme ADR DATA-04 (ML primeiro, Adman enriquece exclusivos). Emite `source='unified'` no caso "ambos" e `source='none'` no caso vazio. Fecha o Success Criterion #3 da Phase 60 (testes automatizados dos 3 casos).
