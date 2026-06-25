---
phase: 38-smoke-ml-piloto-bymobile
plan: 01
subsystem: sugadores
tags: [ml, mercado-ads, product-ads, http-layer, tdd, smoke]
dependency_graph:
  requires:
    - app/Services/MercadoLivreService.php (Phase 20 — get/ensureValidToken/refreshToken)
    - app/Models/MlToken.php (Phase 20 — modelo do token OAuth ML)
    - app/Models/Company.php (relação mlToken)
  provides:
    - app/Services/Sugadores/MercadoLivreAdsService.php (4 metodos publicos: discoverAdvertiser, listCampaigns, listAds, tryFetchAdsMetrics)
    - storage/app/sugadores/ml-smoke/ (diretorio versionado para fixture JSON do Plan 02)
  affects: []
tech_stack:
  added: []
  patterns:
    - "Stateless service wrapper com constructor injection (delega autenticacao para service ja existente)"
    - "Http::fake + Http::sequence + Http::preventStrayRequests para testar camada HTTP isolada de rede"
    - "Endpoints marcados como CANDIDATO em comentarios pt-BR quando shape nao foi comprovado em prod"
key_files:
  created:
    - app/Services/Sugadores/MercadoLivreAdsService.php
    - storage/app/sugadores/ml-smoke/.gitkeep
    - tests/Feature/Phase38/MercadoLivreAdsServiceTest.php
  modified: []
decisions:
  - "Reuso 100% de MercadoLivreService::get() para auth/refresh — service novo NUNCA toca access_token diretamente (T-38-01)"
  - "Endpoints advertisers + campaigns/search comprovados em prod (Phase 20); ads/items marcado CANDIDATO no codigo"
  - "Payload de retorno SEMPRE expoe raw + url + status — Plan 02 imprime no relatorio CLI sem precisar reformatar"
  - "listAds NAO envia header Api-Version: 1 (alinhado com MercadoLivreService::fetchAdsItems linha 614); smoke confirma se precisa"
  - "Conta sem PADS (advertisers=[]) retorna advertiser_id=null SEM lancar excecao — estado valido, nao erro"
  - "tryFetchAdsMetrics encapsula listAds em try/catch para o relatorio diferenciar 'endpoint vazio' de '404/5xx'"
  - "Tetos de seguranca paginacao: 500 campanhas / 2000 ads (espelha Phase 20)"
metrics:
  duration_min: 4
  completed_date: "2026-06-25"
  task_count: 2
  file_count: 3
  commits: 2
---

# Phase 38 Plan 38-01: Service Mercado Ads + suite Http::fake

**One-liner:** Wrapper stateless `MercadoLivreAdsService` (advertiser/campaigns/ads/metrics) reutilizando OAuth da Phase 20, testado via `Http::fake` — pavimenta o Plan 02 sem tocar producao do Sugadores.

## O que foi entregue

Camada HTTP isolada para o ML Mercado Ads (Product Ads) que o comando `sugadores:ml-smoke` (Plan 02) vai consumir. Service stand-alone, sem rota, sem persistencia, sem efeitos colaterais — apenas chama a API ML via `MercadoLivreService::get()` ja existente e devolve payloads CRUS.

### Métodos públicos do service

| Método | Endpoint | Status na Phase 20 |
|--------|----------|---------------------|
| `discoverAdvertiser(Company): array` | `GET /advertising/advertisers?product_id=PADS` | COMPROVADO (Phase 20 `resolveAdvertiserId` linha 438) |
| `listCampaigns(Company, int, string, string): array` | `GET /marketplace/advertising/MLB/advertisers/{id}/product_ads/campaigns/search` | COMPROVADO (Phase 20 `fetchCampaigns` linha 548) |
| `listAds(Company, int, string, string): array` | `GET /advertising/advertisers/{id}/product_ads/items` | CANDIDATO — Phase 20 implementou (`fetchAdsItems` linha 598) mas nunca rodou em prod |
| `tryFetchAdsMetrics(Company, int, string, string): array` | wrapper try/catch sobre `listAds` | wrapper logico (Plan 02 consome) |

### Shape do payload de retorno

Todos os metodos retornam array com `raw` (payload cru completo), `url` (endpoint chamado), `status` (HTTP code) — pronto pro Plan 02 imprimir URL+status no relatorio CLI sem reformatar.

```php
// discoverAdvertiser
['advertiser_id' => int|null, 'site_id' => 'MLB'|null, 'seller_id' => string|null, 'raw' => [...], 'url' => '...', 'status' => 200]

// listCampaigns / listAds
['count' => int, 'results' => [...], 'raw_first_page' => [...], 'endpoints_tried' => ['...?offset=0', '...?offset=50']]

// tryFetchAdsMetrics
['ok' => bool, 'data' => [...]|null, 'error' => ['message' => '...', 'class' => 'RuntimeException']|null]
```

## Tarefas executadas

| # | Tipo | Descricao | Commit | Arquivos |
|---|------|-----------|--------|----------|
| 1 | test (RED) | Suite Http::fake com 4 tests apontando para classe inexistente | `4a2676c` | `tests/Feature/Phase38/MercadoLivreAdsServiceTest.php` + `.gitkeep` |
| 2 | feat (GREEN) | `MercadoLivreAdsService` 4 metodos publicos, DI MercadoLivreService | `8b3d026` | `app/Services/Sugadores/MercadoLivreAdsService.php` |

### TDD Gate Compliance

- RED commit (`test:`) presente: SIM (4a2676c)
- GREEN commit (`feat:`) presente apos RED: SIM (8b3d026)
- REFACTOR commit: nao necessario (codigo limpo no GREEN)

## Tests adicionados (4 — Http::fake)

| # | Test | Cobre |
|---|------|-------|
| 1 | `discoverAdvertiser_returns_advertiser_data_when_account_has_pads` | path feliz — advertiser ID extraido do payload `advertisers[0]` |
| 2 | `discoverAdvertiser_returns_empty_when_account_has_no_pads` | conta sem Mercado Ads — retorna `advertiser_id=null` SEM excecao |
| 3 | `listCampaigns_paginates_and_returns_all_results` | paginacao via `Http::sequence` — 50 + 23 results, total=73 |
| 4 | `discoverAdvertiser_throws_when_401_persists_after_refresh` | 401 persistente apos refresh propaga `RuntimeException` |

**Execucao:** `php artisan test --filter=MercadoLivreAdsServiceTest` → 4 passed (19 assertions) em 3.31s.

## Verificação de regressão

| Suite | Resultado | Tempo |
|-------|-----------|-------|
| `tests/Feature/Phase20` (regressao MercadoLivreService Phase 20) | 20 passed (33 assertions) | 10.96s |
| `tests/Feature/Phase38/MercadoLivreAdsServiceTest` (novo) | 4 passed (19 assertions) | 3.31s |

Zero quebra na Phase 20.

## Confirmacao "Nao-mexer" (CONTEXT)

`git diff --name-only HEAD~2 HEAD` para arquivos de prod do Sugadores:

```bash
$ git diff --name-only HEAD app/Services/SugadorAnalysisService.php \
                            app/Http/Controllers/SugadorController.php \
                            app/Jobs/AnalyzeCompanySugadoresJob.php \
                            app/Jobs/FetchAdmanMlbsByCampaignJob.php
(vazio — zero alteracoes)
```

Tambem zero migrations, zero rotas novas, zero alteracoes no AdmanService.

## Deviations from Plan

None — plan executado exatamente como escrito, sem auto-fix Rule 1/2/3 necessario.

## Threat surface scan

Nenhuma nova surface de seguranca. Service nao tem rota HTTP, nao persiste dados, nao expoe credenciais (T-38-01 mitigado: grep confirma 0 ocorrencias de `access_token`/`refresh_token` no service).

## Proximo passo

**Plan 38-02** (wave 2, checkpoint humano): consome este service no comando `sugadores:ml-smoke` + relatorio CLI + smoke real contra a API ML com token da Bymobille. Plan 02 vai precisar de gate humano porque o executor automatizado nao consegue rodar HTTP real contra ML com token de producao.

## Self-Check: PASSED

- [x] `app/Services/Sugadores/MercadoLivreAdsService.php` existe
- [x] `storage/app/sugadores/ml-smoke/.gitkeep` existe
- [x] `tests/Feature/Phase38/MercadoLivreAdsServiceTest.php` existe
- [x] Commit `4a2676c` (test/RED) presente
- [x] Commit `8b3d026` (feat/GREEN) presente
- [x] 4/4 tests Phase 38 verdes
- [x] 20/20 tests Phase 20 verdes (sem regressao)
- [x] Zero modificacoes em arquivos prod do Sugadores
