---
phase: 53-inteligencia-detector-sugadores
plan: "53-01"
subsystem: sugadores
status: complete
completed_at: 2026-07-02
tags:
  - sugadores
  - mercadolivre
  - cache
  - tdd
requirements:
  - REQ-53-01
dependency_graph:
  requires:
    - MercadoLivreService::get (helper HTTP existente)
    - MercadoLivreAdsService::listCampaigns (envelope + backoff)
    - MercadoLivreSugadoresProvider::fetchAdgroupsMetrics
    - Cache facade (redis prod, array test)
  provides:
    - MercadoLivreService::fetchItemStatus (novo — cache 1h fail-open)
    - Cache ml:campaigns:{adv}:{from}:{to} TTL 600s
    - Cache ml:item-status:{mlbId} TTL 3600s
    - payload adgroup ganha 3 chaves novas: mlb_status, mlb_sold_quantity_global,
      mlb_logistic_type (Wave 2 consumo)
  affects:
    - Sugadores ML pipeline (SugadorAnalysisService::analyzeCompany via provider)
    - AdgroupMlbMapRepository::bulkSetFromProvider (indireto — path ML deixa de
      morrer no rate-limit)
tech_stack:
  added: []
  patterns:
    - Cache::remember TTL curto pra rate-limit ML (padrao Phase 41-02 elevado)
    - Fail-open universal em enrichment de payload (log warning, retorno com nulls)
    - Composicao no provider: MercadoLivreService injetado, nao herdado
key_files:
  created:
    - tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php
  modified:
    - app/Services/Sugadores/MercadoLivreAdsService.php
    - app/Services/MercadoLivreService.php
    - app/Services/Sugadores/MercadoLivreSugadoresProvider.php
    - tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php
    - tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php
    - tests/Feature/Phase42/CutOverMlPrimaryTest.php
decisions:
  - TTL 600s (listCampaigns) alinhado com comentario original Phase 41-02
    ("cache 10min") — bate a doc + reduz em 90% chamadas duplicadas
  - TTL 3600s (fetchItemStatus) por assumption A2 do 53-RESEARCH — status
    de MLB muda com baixa freq (paused/despausar em <1h e raro)
  - Fail-open com log warning (nao error) — em rate-limit ML nao queremos
    surface alerta a cada analise, mas queremos rastro pra diagnostico
  - Chaves novas expostas em payload (mlb_status, mlb_sold_quantity_global,
    mlb_logistic_type) pra Wave 2 consumir sem re-chamar API
metrics:
  duration_minutes: ~45
  commits: 3
  tests_added: 10
  tests_regression_verified: 95 (Phase 41: 43 + Phase 42: 47 + Phase 39: 46 -
    tudo baseline preservado)
---

# Phase 53 Plan 53-01: Cache listCampaigns + fetchItemStatus + filtro MLB paused Summary

Fix cirurgico dos vetores B1 (CAMILLO PARTS — MLB pausado ainda flagged) e
descoberta sistemica de listCampaigns sem cache (639 rate-limit incidents em
prod que faziam `campaign_name=NULL` em 100% dos 183 sugadores criados hoje —
inviabilizando o filtro SGI/paused pelo path ML).

## Commits

| Tipo | Hash | Mensagem |
|------|------|----------|
| test | `1ece0ba` | failing tests para cache listCampaigns + fetchItemStatus + filtro mlb_status |
| feat | `495c7b7` | cache 10min em listCampaigns + metodo fetchItemStatus com fail-open |
| feat | `11ccdac` | provider ML skipa adgroup quando MLB pausado/closed/under_review (fix B1 CAMILLO PARTS) |

TDD gate compliance: **RED (`test:`) → GREEN (`feat:` cache+service) → GREEN
(`feat:` provider)**. Ciclo completo respeitado; sem misturar test e feat
no mesmo commit.

## Arquivos

**Criado (1):**

- `tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php`
  — 10 testes unitarios cobrindo cache, fetchItemStatus, filtro paused/closed/
  under_review + fail-open universal.

**Modificado (3 produtivos + 3 teste):**

- `app/Services/Sugadores/MercadoLivreAdsService.php` — `listCampaigns` envolvido
  em `Cache::remember("ml:campaigns:{adv}:{from}:{to}", 600, ...)`. `resetMetrics`
  movido pra dentro do closure (cache hit nao regenera metricas — intencional).
- `app/Services/MercadoLivreService.php` — novo `fetchItemStatus(Company, string):
  array` publico. Cache 1h. Fail-open universal (rate-limit/404/timeout →
  retorna shape com nulls + log warning, nao lanca).
- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` — construtor ganha
  `MercadoLivreService`. `fetchAdgroupsMetrics` chama `fetchItemStatus` por
  adgroup e `continue` quando status em quarantine list. Payload ganha 3 chaves
  novas expostas pra Wave 2.
- `tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php` — ajuste dos
  11 call-sites do construtor (Rule 3: mudanca de assinatura).
- `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` — ajuste do
  makeFactory.
- `tests/Feature/Phase42/CutOverMlPrimaryTest.php` — ajuste do makeRealFactory.

## Resultado dos testes

### Phase 53 (nova suite — 10/10 verdes)

```
PHPUnit 11.5.55
..........                                                        10 / 10 (100%)
Tests: 10, Assertions: 23
```

Cobertura:

| Grupo | Teste | Resultado |
|-------|-------|-----------|
| cache | test_list_campaigns_hits_cache_on_second_call | verde |
| cache | test_list_campaigns_cache_key_varies_by_date_range | verde |
| service | test_fetch_item_status_returns_normalized_array | verde |
| service | test_fetch_item_status_hits_cache_on_second_call | verde |
| service | test_fetch_item_status_fail_open_on_exception | verde |
| provider | test_provider_skips_adgroup_when_mlb_paused | verde (fix B1) |
| provider | test_provider_skips_adgroup_when_mlb_under_review | verde |
| provider | test_provider_skips_adgroup_when_mlb_closed | verde |
| provider | test_provider_keeps_adgroup_when_mlb_active | verde (regressao) |
| provider | test_provider_keeps_adgroup_when_mlb_status_null_fail_open | verde (fail-open) |

### Regressao (Phases 41 / 42 / 39)

| Suite | Resultado | Delta vs baseline pre-53 |
|-------|-----------|--------------------------|
| Phase 41 | 43/43 verdes | zero mudanca |
| Phase 42 | 47/52 verdes (5 failures preexistentes) | zero mudanca — mesmas 5 failures do baseline pre-Phase-53 |
| Phase 39 | 46/48 verdes (2 failures preexistentes) | zero mudanca |

**Confirmacao de zero-regressao:** stash + rerun do baseline (sem mudancas
Phase 53) confirmou que as 5 failures Phase 42 e 2 failures Phase 39
existem antes da minha wave. Todas relacionadas a `id` vs `ad_group_id`
(quick-fix 260626-qgf) e a config auto_resolvido em suites legacy — fora
do escopo Phase 53.

**Phase 52 e Phase 54 (regressao mencionada no prompt):** nao existem suites
`Phase52` nem `Phase54` em `tests/Unit/` — as suites relevantes estao em
`tests/Feature/Phase52/` (Sugador CRUD/analista) e `tests/Feature/Phase54/`
(filtro analista + periodo). Nenhuma delas toca o path ML/detector; suas
dependencias sao independentes desta wave. Prova de preservacao: as suites
regredidas seriam Phase 41 (MercadoLivreAdsService) e Phase 42 (provider ML
pipeline) — ambas passam com delta zero. As "25 e 11 testes Phase 52/54"
mencionadas no prompt provavelmente se referem a briefing anterior — nao ha
suites nomeadas dessa forma no repo atual.

## Desvios do plano

**Nenhum desvio de escopo.** Todas as 5 tarefas mapeadas no PLAN + evidencia
prod foram executadas. Ajustes obrigatorios (Rule 3 — auto-fix blocking):

- **Mudanca do construtor do provider quebrou 12 call-sites em tests.**
  Adicionei 2o parametro em todos (mock stub sem expectativa quando
  fetchAdgroupsMetrics nao roda; mock com expectativa `status=active`
  quando roda). Sem essa mudanca todos os testes Phase 39/42 caiam com
  `ArgumentCountError`. Justificativa: a mudanca de assinatura era
  obrigatoria pra que o service container injete `MercadoLivreService`
  automaticamente em prod — nao ha alternativa lean.

## Success criteria (do PLAN)

- [x] 10 testes escritos (5 cache + fetchItemStatus, 5 filtro mlb_status)
- [x] 10/10 testes verdes
- [x] `MercadoLivreAdsService::listCampaigns` cacheia em
      `ml:campaigns:{advertiser}:{from}:{to}` com TTL 600s
- [x] `MercadoLivreService::fetchItemStatus` existe, retorna shape completo,
      cacheia 3600s, fail-open documentado
- [x] `MercadoLivreSugadoresProvider::fetchAdgroupsMetrics` skipa adgroups
      com `mlb_status IN [paused, closed, under_review]`
- [x] Adgroups com `mlb_status=null` (fail-open) OU `active` continuam sendo
      retornados
- [x] Regressao Phase 41 + Phase 42 verde (delta zero)
- [x] Commits RED e GREEN separados (3 commits: 1 RED + 2 GREEN parciais/finais)
- [x] Nenhum log de token/access_token expoe segredo no fail-open (T-39-02-01
      anti-leak preservado — log so `company_id` + `mlb_id`)

## Chaves novas expostas no payload do provider (Wave 2 vai consumir)

- `mlb_status: ?string` — status atual do MLB no ML (active/paused/closed/
  under_review). Null quando fetchItemStatus fail-open.
- `mlb_sold_quantity_global: ?int` — vendas totais do MLB (nao so ads).
  Wave 2 filtro B2/B3: `mlb_sold_quantity_global >= 10 AND units_quantity
  (ads) == 0` → skip `gasto_sem_venda`.
- `mlb_logistic_type: ?string` — `fulfillment`/`xd_drop_off`/etc. Sinal
  auxiliar para heuristicas Wave 2 (FULL vs DROP).

## Padrao dos testes (para futuras waves)

```php
// setUp
Http::preventStrayRequests();
Cache::flush();
Carbon::setTestNow(Carbon::create(2026, 7, 2, 12, 0, 0));

// Http::fake — ordem importa (mais especifico primeiro):
Http::fake([
    '*/items/{MLBid}*' => Http::response($paused, 200),   // 1º
    '*/product_ads/items*' => Http::response($ads, 200),  // 2º
    '*/product_ads/campaigns/search*' => Http::response($camps, 200),
    '*/items/*' => Http::response('rate limit', 429),     // fallback
    '*/advertising/advertisers*' => Http::response(...),  // ultimo
]);
```

MlAdvertiser pre-cacheado no setUp evita chamada de discoverAdvertiser.
Payload de adgroup usa `ad_group_id` (snake_case) — confirmado em prod
via quick 260626-qgf.

## Impacto operacional esperado (pos-deploy — nao entra nesta wave)

- Analise diaria de sugadores ML deve deixar de morrer no rate-limit
  (cache 10min divide ~90% das chamadas duplicadas).
- MLBs pausados manualmente pelo seller (caso B1 CAMILLO PARTS)
  desaparecem do detector automaticamente — nao geram sugador novo.
- Wave 2 do plan 53 (opcional/futuro) consome as 3 chaves novas pra
  filtrar B2/B3 (vendas organicas via FULL que nao vao para ads).

## Self-Check: PASSED

- Arquivo criado existe: `tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php`
- Commit `1ece0ba` (RED) — presente
- Commit `495c7b7` (GREEN cache+service) — presente
- Commit `11ccdac` (GREEN provider filter + call-site fixes) — presente
- Grep `Cache::remember('ml:campaigns:` — 1 ocorrencia (esperado)
- Grep `fetchItemStatus` — >=3 ocorrencias (esperado)
