# Phase 53: Inteligência do detector de sugadores — Research

**Researched:** 2026-07-02
**Domain:** Detector de sugadores ML (SugadorAnalysisService + MercadoLivreSugadoresProvider)
**Confidence:** HIGH (todos os 3 casos validados com SSH em prod)

## Summary

Investigação em prod (`root@177.7.53.164`) das 3 empresas do bloco B com IDs
específicos. Cada uma das 3 hipóteses do CONTEXT foi **confirmada com evidência
real** — a maioria via chamada direta à API ML e query nos payloads persistidos.

**Descoberta bônus (out-of-scope inicial mas alta severidade):** `listCampaigns`
falha por **rate-limit ML (60/min)** para praticamente todas as empresas na
análise diária → `campaign_name = NULL` em 100% dos sugadores de hoje (0 de 183
populados) → filtro de quarentena SGI/paused **nunca dispara pelo path ML**.
Isso amplifica os 3 casos e é um pré-requisito para que o fix do B3 funcione.

**Primary recommendation (LEAN):** 3 fixes cirúrgicos no `MercadoLivreSugadoresProvider`
+ 1 no `SugadorAnalysisService`, sem novas colunas em DB.

---

## User Constraints (from CONTEXT.md)

### Locked Decisions

- Escopo restrito aos 3 casos B1/B2/B3 (IDs fixos). Sem reescrita.
- FORA da phase: consulta status MLB para Adman, bulk-update histórico,
  outros marketplaces, modernização visual (Phase 55).
- Config `sugador_configs` não muda — fix é no critério de skip/hit.
- Tests via SQLite in-memory + mock provider.

### Claude's Discretion

- Cache `/items/{id}` — TTL curto (1h razoável).
- Estrutura do teste (1 arquivo por caso vs consolidado).
- Colunas novas vs flags calculadas (research recomenda flag, não coluna).

### Deferred Ideas (OUT OF SCOPE)

- Fix `AnalyzeCompanySugadoresJob` timeout 16 páginas.
- Threshold por porte da empresa.
- Cross-check FULL >> ads (opcional se hipótese confirmada — confirmada; ver B2).
- Batch `/items/{id}` — deferred até performance ficar ruim.
- Estacionalidade.

---

## Caso B1 — CAMILLO PARTS MATRIZ (company_id=1)

### Achados em prod

| Item | Valor |
|------|-------|
| Sugador | id=21902, ref=2026-07-02, status=pendente |
| Adgroup | 1902557017, campaign 357429608 |
| Payload | `investment=26.70`, `clicks=11`, `impressions=3499`, `sold_quantity=0`, `mlb_id=MLB6258261358` |
| Motivos | `["gasto_sem_venda"]` |
| **API ML `/items/MLB6258261358`** | **`status=paused`**, `sub_status=["paused_by_seller"]`, `available_quantity=1`, `sold_quantity=4` global |
| `raw_data.status` no payload ads | `active` (é status do ADGROUP, não do MLB — enganoso) |

### Hipótese confirmada

**Detector só olha métricas do adgroup — não consulta status individual do MLB.**
O MLB está pausado pelo seller no ML mas o adgroup ainda ativa e acumula impressões
+ clicks residuais (11 clicks, 3499 imp) sem venda — bate `gasto_sem_venda` (26,70 ≥ threshold).

Também confirmada **contra-hipótese** da opção lean (skip quando
`investment=0 AND clicks=0`): esse caso **não se aplica** — investment=26,70 e clicks=11.
Query em prod: `0 de 183` sugadores hoje têm `investment=0 AND clicks=0`. A opção
lean **não resolve B1** e pode ser descartada.

### Fix recomendado

**Opção A — Consulta status MLB via API ML (única viável para B1).**

- Adicionar método `MercadoLivreService::fetchItemStatus(Company, string $mlbId): array`
  usando `get(Company, "/items/{$mlbId}", ["attributes"=>"id,status,sub_status,available_quantity"])`
  já existente (file:line `app/Services/MercadoLivreService.php:271`).
- Cache: `Cache::remember("ml:item_status:{mlbId}", 3600, ...)` — 1h TTL alinhado
  com padrão Adman (memory `project_ecf_drive_cache_stale`).
- Em `MercadoLivreSugadoresProvider::fetchAdgroupsMetrics` (linha 190-244), após
  montar `$out[]`, adicionar filtro: `if ($itemStatus in ['paused','closed','under_review']) skip`.
  Payload `raw_data.status` NÃO substitui — é status do adgroup, não do MLB.
- **Custo:** 53 MLBs distintos pendentes hoje em prod; 60 req/min ML permite
  ~1 min de análise ML puro. Cache 1h reduz em re-runs. Viável.
- **Não requer nova coluna** — filtro decide skip antes de persistir sugador.

---

## Caso B2 — BARAOSHOP VARIEDADES (company_id=229)

### Achados em prod

| Item | Valor |
|------|-------|
| Sugador | id=21620, ref=2026-07-01, status=pendente |
| Adgroup | 496843010, campaign 351549509, mlb_id=MLB3759543315 |
| Payload | `investment=12.22`, `clicks=4`, `impressions=4605`, `sold_quantity=0`, `units_quantity=0`, `direct_units=0`, `indirect_units=0` |
| **API ML `/items/MLB3759543315`** | `status=active`, **`sold_quantity=781` global**, `shipping.logistic_type=fulfillment` (**FULL**) |
| `raw_data.logistic_type` no payload ads | `fulfillment` |
| `AdgroupMlbMapRepository::getMlbsForAdgroup(229, "496843010")` | **retorna `[]`** — drilldown vazio |
| Cust_id 91464987 mapa | 1600 rows, **100% com `adgroup_id=NULL`** |
| Log rate-limit hoje | `[2026-07-02 12:36:29] Empresa 229 ... Rate limit ML excedido para seller unknown (60/min)` |

### Hipótese confirmada (as 3 juntas)

- **A (sync falhando):** CONFIRMADA — 1600 rows mas 100% com `adgroup_id=NULL`.
  Bug antigo do `SyncCompanyAdgroupMlbsJob` (path Adman legacy) para BARAOSHOP.
  CAMILLO tem 61% NULL, DINMAP tem 0% NULL — inconsistência entre empresas.
- **B (mlb_id no provider):** REFUTADA — `mlb_id=MLB3759543315` está preenchido
  no sugador (a quick task 260626-qgf corrigiu). Provider ML popula.
- **C (sold_quantity ignora FULL):** CONFIRMADA E CRÍTICA — payload ads reporta
  `direct_units + indirect_units = 0` (Ads API só conta vendas atribuídas ao
  anúncio patrocinado). MLB tem **781 vendas globais** e é FULL. Adgroup gastou
  R$ 12,22 sem venda **atribuída** ao ads, mas o produto vende muito organicamente.
  Flaggar como sugador está tecnicamente correto pelo critério, mas
  operacionalmente errado — operador entende como "detector errado".

### Fix recomendado

**Duas mudanças complementares (Opção C é a canônica; Opção A fica deferred):**

1. **[LEAN] Sinal `has_full_organic_sales` no provider ML** — em
   `MercadoLivreSugadoresProvider::fetchAdgroupsMetrics`, quando
   `raw_data.logistic_type == 'fulfillment'`, consultar `/items/{mlb_id}` (reusa
   cache do B1) e comparar `sold_quantity_global >> units_quantity_ads`. Se
   diferença for material (>= 10 vendas globais nos últimos 30d e 0 atribuídas
   ao ads), skipar o hit de `gasto_sem_venda`. Regra do adminstrador confirmou
   em CONTEXT: "ads desnecessário mas produto vende".
2. **Sync do map adgroup-MLB** (path ML já persiste em
   `bulkSetFromProvider` linha 358-374 do service) — **não requer fix novo**,
   basta o rate-limit ser mitigado (ver descoberta sistêmica abaixo). Quando
   a análise ML de BARAOSHOP não morre no rate-limit, o mapa é populado via
   provider ML e drilldown funciona. O sync legacy Adman com adgroup_id=NULL
   é dívida técnica separada — não bloqueia B2.

**Custo API extra:** aproveita cache do B1 (mesmo `Cache::remember("ml:item_status:{mlbId}")`
pode receber `sold_quantity` no mesmo GET com `attributes=id,status,sold_quantity,shipping`).

---

## Caso B3 — DINMAP (company_id=319)

### Achados em prod

| Item | Valor |
|------|-------|
| Sugador | id=22091, ref=2026-07-02, status=pendente |
| Adgroup | 1784220962, campaign 358096150, mlb_id=MLB4359551779 (**mismatch! payload item=MLB4359551777**) |
| Payload | `investment=86.94`, `clicks=113`, `impressions=37699`, `units_quantity=0`, `direct_units=0`, `indirect_units=0` |
| **API ML `/items/MLB4359551777`** (item real do ads) | `status=active`, `sold_quantity=6`, `available_quantity=4` |
| **API ML `/orders/search`** MLB4359551777 no período | **4 orders confirmados** nos últimos 30d |
| Campanhas SGI da DINMAP | **1 encontrada: `SGI (ECF-ADS)` (id=357880303, status=paused)** |
| Sugadores em campaign_id=357880303 | 0 (nenhum na SGI atual) |
| `campaign_name` em todos os 6 sugadores DINMAP hoje | **TODOS NULL** |
| `logistic_type` B3 | `xd_drop_off` (não é FULL) |

### Hipótese confirmada

- **SGI vaza:** CONFIRMADA (indireta). Existe campanha SGI paused com id 357880303,
  mas nenhum sugador está atualmente nela — significa que ao mover, o
  `CleanupSugadoresQuarentena` limpou (funciona). O sintoma "outro sugador
  em SGI resolvido flagged" que operador reportou **não é reprodutível hoje**
  para DINMAP. Provavelmente já foi limpo pelo cron ou operador enviou para
  SGI depois do relato. **Deferrable**, mas ver descoberta sistêmica abaixo.
- **Vendas ignoradas:** CONFIRMADA E CRÍTICA — mesmo padrão do B2 (unidades
  atribuídas ao ads = 0, produto tem vendas globais). MLB4359551777 tem
  4 orders confirmados nos últimos 30d + 6 sold_quantity global. Ads API
  reporta 0 porque as vendas não foram atribuídas ao anúncio patrocinado.
  Aqui é `logistic_type=xd_drop_off` (não FULL) — atribuição pode falhar por
  outro motivo (comprador chegou por busca orgânica, não pelo ad).
- **Mismatch mlb_id:** DESCOBERTA COLATERAL — a coluna `sugador.mlb_id` do B3
  aponta MLB4359551779, mas o `raw_data.item_id` do payload ads é MLB4359551777.
  Divergência sugere que o `sugador.mlb_id` foi populado por outro path (talvez
  Adman legacy antigo ou drilldown manual) e não pelo provider ML. **Investigar
  no plan como quick check** — se `provider->fetchAdgroupsMetrics` sempre popula
  `mlb_id = raw['item_id']` (linha 228), então o valor persistido veio antes
  de um upsert corrigir.

### Fix recomendado

**B3 é combinação B1 + B2:**

- Se o **fix B1** (consulta status MLB) rodar antes, esse caso continua sugador
  (MLB está `active`). Sozinho, B1 não resolve.
- O **fix B2** (`has_organic_sales`) resolve B3 também — se sold_quantity global
  >> ads_units, skipar o hit. Regra unificada: skip quando venda global recente
  existe mas 0 foi atribuída ao ads.
- **Descoberta sistêmica (crítica; ver seção abaixo):** consertar rate-limit
  em `listCampaigns` para que `campaign_name` seja populado. Sem isso, mesmo o
  operador movendo para SGI, o adgroup detectado 1min depois volta a ser
  flagged (SGI só filtra por NOME e o nome é NULL).

---

## Descoberta sistêmica (fora do escopo original — HIGH severity)

**Rate-limit ML derruba `listCampaigns` → filtro SGI/paused nunca dispara pelo path ML.**

- 639 incidents "Rate limit ML" em `storage/logs/laravel.log` (histórico).
- Hoje (02/07): 100% dos 183 sugadores criados têm `campaign_name = NULL` e
  `campaign_status = NULL`.
- Root cause: `MercadoLivreAdsService::listCampaigns` **NÃO tem cache** (linha
  353-399) apesar do comentário Phase 41-02 mencionar "cache 10min". Cada
  `fetchAdgroupsMetrics` chama `listCampaigns` na hora + `tryFetchAdsMetrics`
  → 2+ requests por empresa. Com 40+ empresas ML → estoura 60/min facilmente.
- Fail-open silencioso em `MercadoLivreSugadoresProvider` linha 167-171: pega
  o Throwable, loga como warning, e segue com `$campaignNames = []`. Análise
  "termina com sucesso" mas sem quarentena.

**Fix mínimo (recomendo incluir na Phase 53):**

- Envolver `listCampaigns` em `Cache::remember("ml:campaigns:{advertiser}:{from}:{to}", 600, ...)`.
  10 min bate o comentário original e reduz em 90% as chamadas duplicadas
  (analyzeCompany + buildCampaignsInfoFromProvider batem 2x seguidas).
- Elevar log de warning para **error** quando `campaignNames = []` em vez de
  fail-open silencioso — surface pro operador em `DevCard` de sync.

---

## Overview de helpers existentes

- **`MercadoLivreService::get(Company, string $endpoint, array $query, array $headers)`**
  (`app/Services/MercadoLivreService.php:271`) — helper genérico HTTP autenticado
  com refresh de token. **Já usa em `MlColetaService.php:196` para `/items/{itemId}`**.
  Único ponto necessário para adicionar `fetchItemStatus`.

- **`MercadoLivreAdsService::listCampaigns`** (`app/Services/Sugadores/MercadoLivreAdsService.php:353`)
  — SEM cache. Rate-limit sistêmico.

- **`AdgroupMlbMapRepository::getMlbsForAdgroup(int companyId, string adgroupId)`**
  (`app/Repositories/AdgroupMlbMapRepository.php:42`) — funciona; problema é o
  populador (sync legacy Adman salva com `adgroup_id=NULL` para algumas empresas).

- **`shouldSkipCampaign(?array info)`** (`SugadorAnalysisService.php:711`)
  — fail-open documentado linha 713: `if (!$info) return false`. Se `info=null`,
  não skipa. Combinado com `campaignsInfo[cId]=null` (campaign_name NULL),
  quarentena inerte pra path ML.

- **Padrão de cache existente:** `MercadoLivreAdsService.php` usa
  `Cache::remember` no `discoverAdvertiser` via `MlAdvertiser` (persistente
  em DB — TTL 7 dias). Pra `/items/{id}` cache in-memory `Cache::remember`
  padrão Laravel (redis em prod) é suficiente.

- **Payload ML Ads** (`fetchAdgroupsMetrics`) já expõe no `raw_data`:
  `logistic_type`, `status_raw`, `channel`. Usar direto para heurísticas simples
  sem chamada extra (ex: `logistic_type=fulfillment` sinaliza FULL). Payload
  linha 208-244.

---

## Recomendação de plano (LEAN — 4 tarefas)

### Wave 1 — Fix B1 + descoberta sistêmica listCampaigns cache

- **T-53-01** — Cache `listCampaigns` em `MercadoLivreAdsService`.
  `Cache::remember("ml:campaigns:{advertiser}:{from}:{to}", 600, ...)`.
  Reduz 639-incidents/histórico de rate-limit; sem isso o fix B3 (SGI) não
  funciona. Bônus: teste unitário mock provider já valida cache hit.
- **T-53-02** — Novo método `MercadoLivreService::fetchItemStatus(Company, string $mlbId): array`
  com cache `Cache::remember("ml:item_status:{mlbId}", 3600, ...)`.
  Retorna `['status','sub_status','available_quantity','sold_quantity','logistic_type']`
  em 1 GET só (reusado por B1+B2+B3).
- **T-53-03** — Em `MercadoLivreSugadoresProvider::fetchAdgroupsMetrics`,
  após montar `$out[]` do adgroup, filtrar: se
  `fetchItemStatus.status IN ('paused','closed','under_review')`, skip.
  **Resolve B1.**

### Wave 2 — Fix B2/B3 vendas orgânicas

- **T-53-04** — Mesmo loop do T-53-03, se `units_quantity == 0 AND
  status_mlb.sold_quantity >= LIMIAR_ORGANIC` (sugestão: 10 vendas globais
  no período de 30d), skipar o hit de `gasto_sem_venda`. **Resolve B2 e B3.**
  Motivo especial `descartado_venda_organica` em log/debug — sem coluna nova.

### Wave 3 — Testes (uma tarefa consolidada)

- **T-53-05** — Um arquivo `tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php`
  com 5 casos: (a) MLB paused → skip, (b) MLB active + sold_quantity_ads=0 +
  global >= 10 → skip, (c) MLB active + sold_quantity_ads>0 → mantém, (d)
  MLB active + sold_quantity_global=0 → mantém (não interfere no fluxo real),
  (e) cache hit / miss de `fetchItemStatus`. Mock `MercadoLivreService::get`
  e `Cache::store` — sem SQLite. Cumpre CONTEXT §Approach técnica.

**Nada mais fora dessas 5 tarefas.** Config `sugador_configs` intocada,
sem migration, sem nova coluna, sem novo cron.

---

## Assumptions Log

| # | Claim | Section | Risk se errado |
|---|-------|---------|----------------|
| A1 | Limiar de vendas orgânicas = 10 no período 30d resolve B2/B3 sem gerar falso-negativos | T-53-04 | Se muito baixo → todo produto com 1 venda esporádica escapa detector. Recomendo tornar configurável via `SugadorConfig` em phase futura. |
| A2 | Cache 1h de `/items/{id}` é aceitável (status muda com baixa freq) | T-53-02 | Se seller pausar/despausar em <1h, detector reagirá com atraso. Aceitável — MLB pausado por 30min raramente vira sugador. |
| A3 | `logistic_type=fulfillment` no payload ads já resolve B2 sem chamar `/items` | Overview | REFUTADA na verdade — B3 é `xd_drop_off` e também precisa do fix. Por isso T-53-04 usa `sold_quantity_global` como sinal universal, não `logistic_type`. |
| A4 | Cache 10 min em `listCampaigns` reduz rate-limit sem quebrar SGI recente | T-53-01 | Se operador move para SGI e re-roda análise em <10min, adgroup ainda flagged. Aceitável — SGI é decisão que dura dias. |

---

## Open Questions

1. **Mismatch `sugador.mlb_id=MLB4359551779` vs `raw_data.item_id=MLB4359551777` (B3)**
   - O que sabemos: valor persistido no B3 diverge do payload atual.
   - O que não está claro: se é herança de sync antigo ou bug de upsert.
   - Recomendação: **quick check** em T-53-04 — se `mlb_id ≠ raw_data.item_id`,
     usar `raw_data.item_id` como fonte-verdade e re-upsert. Não bloqueia phase.

2. **Sync legacy Adman com adgroup_id=NULL (BARAOSHOP)**
   - Impacto: drilldown vazio até path ML popular via `bulkSetFromProvider`.
   - Recomendação: fora de escopo Phase 53 (bug no path Adman). Documentar
     em nova quick task para investigar `SyncCompanyAdgroupMlbsJob:119`
     (`AdmanMcpService::fetchAllProductAds` retornando payload sem `ad_group_id`
     em algumas contas). Path ML resolverá naturalmente com T-53-01+T-53-03+T-53-04.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| API ML `/items/{id}` | T-53-02, T-53-03, T-53-04 | ✓ (verificado em prod) | atual | — |
| API ML `/marketplace/advertising/.../campaigns/search` | T-53-01 | ✓ (funciona quando não rate-limit) | atual | Cache existente |
| `Cache::remember` (Redis prod, database dev) | T-53-01, T-53-02 | ✓ | Laravel 12 | — |
| PHPUnit + Mockery | T-53-05 | ✓ | ^11.5 / ^1.6 | — |

Nenhuma dependência bloqueante.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.50 |
| Config file | `phpunit.xml` |
| Quick run | `vendor/bin/phpunit --filter MercadoLivreSugadoresProviderFilterTest` |
| Full suite | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command |
|--------|----------|-----------|-------------------|
| B1 | MLB `paused` → adgroup não vira sugador | unit | `vendor/bin/phpunit --filter test_skip_when_mlb_paused` |
| B2/B3 | Vendas globais >> ads → skip `gasto_sem_venda` | unit | `vendor/bin/phpunit --filter test_skip_when_organic_sales` |
| Sistêmico | `listCampaigns` retorna do cache no 2º call | unit | `vendor/bin/phpunit --filter test_list_campaigns_cached` |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --filter MercadoLivreSugadoresProviderFilterTest`
- **Per wave merge:** `vendor/bin/phpunit tests/Unit/Services/Sugadores`
- **Phase gate:** `vendor/bin/phpunit` (full suite green)

### Wave 0 Gaps

- Criar `tests/Unit/Services/Sugadores/MercadoLivreSugadoresProviderFilterTest.php`
  (arquivo não existe ainda).
- Fixture com payload ML Ads real: aproveitar
  `storage/app/sugadores/ml-smoke/` (Phase 38) se existente em prod, ou
  inline no teste.

---

## Sources

### Primary (HIGH — evidência real de prod)

- SSH tinker root@177.7.53.164 em `/var/www/ecf_admin` (2026-07-02).
- API ML `/items/{id}` chamadas ao vivo para MLB6258261358, MLB3759543315,
  MLB4359551779, MLB4359551777.
- `storage/logs/laravel.log` produção (639 rate-limit incidents).
- `Sugador::find(21902|21620|22091)->raw_data` — payloads persistidos.
- `AdmanAdgroupMlb` counts diretos por `cust_id`.

### Secondary (código local)

- `app/Services/SugadorAnalysisService.php:1-728`
- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php:1-306`
- `app/Services/Sugadores/MercadoLivreAdsService.php:353-399` (listCampaigns sem cache)
- `app/Services/MercadoLivreService.php:271` (get genérico)
- `app/Services/MlColetaService.php:196` (padrão de `/items/{id}` já em uso)
- `app/Repositories/AdgroupMlbMapRepository.php`

---

## Metadata

**Confidence breakdown:**
- B1 (status MLB paused): **HIGH** — API ML retornou `paused` diretamente.
- B2 (vendas FULL não atribuídas): **HIGH** — 781 sold_quantity global vs 0 ads.
- B3 (vendas ignoradas): **HIGH** — 4 orders confirmados + 6 sold_quantity global vs 0 ads.
- B3 (SGI vaza): **MEDIUM** — hipótese confirmada indiretamente (campaign_name NULL
  em 100% dos sugadores + campanha SGI existe), mas caso exato não reproduzível hoje.
- Descoberta sistêmica listCampaigns rate-limit: **HIGH** — 639 incidents em log.

**Research date:** 2026-07-02
**Valid until:** 30 dias (payloads ML estáveis).
