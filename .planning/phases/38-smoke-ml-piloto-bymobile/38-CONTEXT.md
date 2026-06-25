# Phase 38: Smoke ML (piloto Bymobile) — Context

**Gathered:** 2026-06-25
**Status:** Ready for planning
**Source:** Import express path (`plano-migracao-sugadores-ml-direto.md` §9 — "Prompt operacional para Claude Code") + AskUserQuestion 2026-06-25

<domain>
## Phase Boundary

Phase 38 é o **gate de entrada** da Milestone v11.0 (Migração Sugadores Adman → ML). Único entregável: comando Artisan `sugadores:ml-smoke --company={id} --days=30` que valida na prática a API oficial Mercado Livre Mercado Ads / Product Ads usando o token real da empresa **ByMobille - Teste** (única com OAuth ML direto hoje no sistema).

Esta phase **NÃO** cria provider pattern, **NÃO** refatora `SugadorAnalysisService`, **NÃO** introduz shadow mode, **NÃO** grava em nenhuma tabela de produção do módulo Sugadores. Tudo isso fica para Phase 39+.

O critério de "Done" é entregar um relatório curto e uma fixture JSON suficientes para a Phase 39 decidir, com base em dados reais, qual contrato normalizado adotar e quais endpoints ML chamar.

**Fora de escopo nesta phase:**
- `SugadoresAdsProvider` contract (Phase 39)
- `AdmanSugadoresProvider` / `MercadoLivreSugadoresProvider` (Phase 39)
- Refactor `SugadorAnalysisService` (Phase 39)
- `AdgroupMlbMapRepository` (Phase 39)
- Shadow mode + tabelas auxiliares `sugador_provider_runs` / `_items` (Phase 40)
- Tela admin de onboarding + tabela `ml_advertisers` (Phase 41)
- Rate limiter `ml-api:{seller_id}` (Phase 41 — não precisa pra smoke pontual)
- Qualquer mudança nos jobs/controllers de produção do Sugadores

</domain>

<decisions>
## Implementation Decisions

### Comando e localização
- Comando: `php artisan sugadores:ml-smoke --company={id} --days=30`
- Arquivo: `app/Console/Commands/SugadoresMlSmoke.php` (PascalCase verb-noun por convenção do projeto — ver CLAUDE.md)
- Signature: `sugadores:ml-smoke {--company= : ID numérico da empresa (default: Bymobille)} {--days=30 : Janela em dias para métricas}`
- Output: Console (formato legível pra operador) + arquivo JSON em `storage/app/sugadores/ml-smoke/{company_id}-{YYYY-MM-DD}.json`

### Resolução de token e advertiser
- Reusar `MercadoLivreService` (Phase 20) para autenticação/refresh do `mlToken` da empresa
- Se faltar método para `/advertising/advertisers` ou Product Ads, **criar** `App\Services\Sugadores\MercadoLivreAdsService` (novo, em namespace dedicado de Sugadores) — não inflar o `MercadoLivreService` legado
- `MercadoLivreAdsService` é stateless, single-responsibility: faz as chamadas ML necessárias para o smoke e devolve payloads crus (anonimizáveis depois)
- Cache de `advertiser_id` em variável local da execução do comando (sem persistir em tabela — `ml_advertisers` é da Phase 41)

### Endpoints candidatos (validar contra a API real)
O plano `.planning/research/sugadores-ml-direto/PLANO-ORIGINAL.md` §2 elenca os endpoints CANDIDATOS. Tratar como hipótese — o comando deve imprimir URL chamada + status + shape do primeiro payload para o operador corrigir caso a doc oficial use nome diferente. Candidatos:

- Advertiser: `GET /advertising/advertisers` → extrair `advertiser_id`, `site_id`, `seller_id`
- Campanhas: `GET /advertising/product_ads/campaigns?advertiser_id={id}` → lista
- Métricas campanhas: endpoint Product Ads de métricas por período (a confirmar)
- Anúncios/Product Ads: `GET /advertising/product_ads/ads...` → lista (paginar)
- Métricas anúncio/item: endpoint Product Ads de métricas (por período)

URL base: `https://api.mercadolibre.com/` (referência: `https://developers.mercadolivre.com.br/`)

### Contrato normalizado (referência §2.3 do plano — para mapear campos)
Quando imprimir o relatório, comparar campos do payload ML real com este contrato-alvo (que será usado em Phase 39):

```php
[
  'adgroup_id' => (string) $mlAdIdOrItemId,
  'adgroup_name' => $titleOrAdName,
  'campaign_id' => (string) $campaignId,
  'thumbnail' => $thumbnail,
  'adgroup_type' => $type,
  'catalog_listing' => (bool) $isCatalog,
  'mlb_id' => $itemId,                  // MLB...
  'mlb_titulo' => $title,
  'investment' => $cost,
  'revenue' => $totalRevenue,
  'sold_quantity' => $units,
  'clicks' => $clicks,
  'impressions' => $prints,
  'cpc' => $cpc ?? safe_div($cost, $clicks),
  'ctr' => $ctr ?? safe_div($clicks, $prints),
  'acos' => $acos ?? safe_div($cost * 100, $totalRevenue),
  'roas' => $roas ?? safe_div($totalRevenue, $cost),
  'organic_amount' => $organicRevenue ?? null,   // pode não existir na API ML
  'organic_units' => $organicUnits ?? null,      // pode não existir na API ML
  'raw' => $payload,
]
```

### Tratamento de erros
- `401`: tentar refresh token uma vez; se falhar, imprimir "Token inválido/expirado — reautenticar empresa via OAuth ML" e terminar com exit code != 0
- `403`: imprimir "Permissão/scope ML Ads ausente" + listar scopes presentes vs esperados, terminar com exit code != 0
- `429`: imprimir "Rate limit hit (improvável em smoke pontual)" + `Retry-After` se presente; aguardar e tentar uma vez
- `5xx`: log + abort com exit code != 0
- Exceptions de rede: log + abort

### Fixture JSON
- Path: `storage/app/sugadores/ml-smoke/{company_id}-{YYYY-MM-DD}.json`
- Schema:
  ```json
  {
    "company_id": 123,
    "company_name": "ByMobille - Teste",
    "run_at": "2026-06-25T15:30:00-03:00",
    "days_window": 30,
    "advertiser": { "id": "...", "site_id": "...", "seller_id": "...", "raw": {...} },
    "campaigns": { "count": N, "sample": {...}, "raw_list": [...] },
    "ads": { "count": N, "sample": {...}, "raw_list": [...] },
    "metrics": { "endpoint_tried": "...", "status": 200, "sample": {...}, "raw": [...] },
    "report": { "endpoints_ok": [...], "endpoints_failed": [...], "contract_fields_present": [...], "contract_fields_missing": [...], "blockers": [...] }
  }
  ```
- Anonimizável: PII (nome de cliente final, descrição com termos sensíveis) não é objetivo do smoke — gravar **payloads crus** mas o operador pode trocar `company_name` por placeholder se for compartilhar fora da equipe

### Relatório CLI
Formato sugerido (Markdown legível no terminal):
```
## Smoke ML — Bymobile - Teste (company_id=N, 30d)

✓ Auth OK (token refresh: sim/não)
✓ Advertiser descoberto: ID=..., site=..., seller=...
✓ Campanhas: N listadas
- Campos disponíveis: campaign_id, name, status, ...
- Campos ausentes (vs contrato): ...

✓/✗ Ads: N listados
- Campos disponíveis: ...
- Campos ausentes: ...

✓/✗ Métricas: endpoint=... status=200/...
- Campos numéricos presentes: cost, clicks, ...
- Campos numéricos ausentes: organic_amount, organic_units, ...

## Blockers para Phase 39
- (vazio = pronto) | ou lista de issues encontrados

## Fixture gravada
storage/app/sugadores/ml-smoke/N-2026-06-25.json
```

### Não-mexer
- `app/Services/AdmanService.php` — Adman path intocado
- `app/Services/SugadorAnalysisService.php` — análise de produção intocada
- `app/Http/Controllers/SugadorController.php` — controller intocado
- `app/Jobs/AnalyzeCompanySugadoresJob.php` — job intocado
- `app/Jobs/FetchAdmanMlbsByCampaignJob.php` — job intocado
- Migrations, schema, seeds — nenhuma alteração nesta phase
- `routes/console.php` — não agendar o smoke (é manual sob demanda)

### Testes
- Suite Feature mínima: `tests/Feature/Phase38/MlSmokeCommandTest.php` com:
  - Test 1: comando falha com erro claro se `--company` não existir ou sem `mlToken`
  - Test 2: comando falha com 401 simulado se token inválido (após tentar refresh)
  - Test 3: comando grava fixture JSON em path esperado com shape correto quando todas as chamadas retornam OK (Http::fake)
  - Test 4: comando imprime relatório com seções obrigatórias (endpoints_ok, endpoints_failed, blockers)
- Smoke real: rodar `php artisan sugadores:ml-smoke --company={id_bymobille}` no host de dev contra a API real (não testavel automaticamente — checkpoint humano)

### Claude's Discretion
- Exato layout do output console (cores, símbolos) — manter pt-BR, legível
- Estrutura interna de `MercadoLivreAdsService` (métodos, signatures) — desde que payload cru seja exposto pro comando
- Granularidade da fixture (1 sample + raw_list completa, ou só sample) — preferir raw_list completa pra reuso em testes da Phase 39
- Onde colocar tests namespace (`tests/Feature/Phase38/` ou `tests/Feature/Sugadores/Phase38/`) — consistente com Phase30 (`tests/Feature/Phase30/`)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano de migração (fonte de verdade)
- `plano-migracao-sugadores-ml-direto.md` (raiz) — plano técnico completo da migração v11.0; §0, §1, §2, §3, §9 são os mais relevantes pra Phase 38
- `.planning/research/sugadores-ml-direto/PLANO-ORIGINAL.md` — cópia canônica do plano
- `.planning/research/sugadores-ml-direto/README.md` — mapeamento Fase plano → Phase GSD e decisões da importação

### Sistema atual de Sugadores e ML
- `app/Services/MercadoLivreService.php` (Phase 20) — auth/refresh do `mlToken`, base para `MercadoLivreAdsService` novo
- `app/Models/MlToken.php` — modelo do token ML (campos: company_id, access_token, refresh_token, expires_at, status)
- `app/Models/Company.php` — accessor `is_ml_driven` + relação `mlToken`
- `app/Services/AdmanService.php` — referência para entender o contrato Adman atual (não modificar)
- `app/Console/Commands/SyncAdmanData.php` — referência de padrão de comando Artisan que chama API externa com throttle

### Roadmap e CONTEXT vizinhos
- `.planning/ROADMAP.md` — seção Phase 38 (detail) + milestone v11.0
- `.planning/phases/30-.../CONTEXT.md` — nota da supersedure W2/W3/W4 + decisão de provider pattern

### Doc externa (consultar durante implementação)
- `https://developers.mercadolivre.com.br/` — doc oficial ML Developers (fonte para nomes exatos de endpoints/params/scopes)
- `https://api.mercadolibre.com/` — base da API

</canonical_refs>

<specifics>
## Specific Ideas

- Empresa piloto: **ByMobille - Teste** (única com `mlToken` ativo + sem `adman_account_id` segundo plano §0)
  - Confirmar `company_id` no momento da execução: `php artisan tinker --execute='dump(\App\Models\Company::where("name", "like", "%Bymobille%")->whereHas("mlToken")->get(["id","name"])->toArray());'`
  - Default do `--company` se vazio: tentar resolver Bymobile via lookup; se ambíguo, falhar com lista de candidatos
- Janela default `--days=30` alinha com janela do Sugadores prod (auto-detect padrão)
- Comando rodável local no XAMPP do dev (não no VPS) — não exige queue worker

</specifics>

<deferred>
## Deferred Ideas

- Persistir `advertiser_id` em tabela `ml_advertisers` — Phase 41
- Rate limiter `ml-api:{seller_id}` — Phase 41 (smoke pontual não precisa)
- Repository `AdgroupMlbMapRepository` — Phase 39
- Provider contract `SugadoresAdsProvider` — Phase 39
- Comando `sugadores:analyze --provider=ml --dry-run` — Phase 39
- Shadow mode / `sugadores:shadow-ml` / `sugadores:compare-providers` — Phase 40
- UI admin de onboarding ML — Phase 41
- Envs `SUGADORES_PROVIDER_MODE` / `SUGADORES_ML_SHADOW_COMPANIES` / `SUGADORES_ML_PRIMARY_COMPANIES` — Phase 42
- Remoção `ADMAN_API_KEY` obrigatório — Phase 43
- Rename `adman_adgroup_mlbs` → `sugador_adgroup_mlbs` — Phase 43

</deferred>

---

*Phase: 38-smoke-ml-piloto-bymobile*
*Context gathered: 2026-06-25 via import express path*
