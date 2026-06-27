# Phase 44 — Mover adgroup-sugador para SGI via API ML — Research

**Pesquisado:** 2026-06-26
**Domínio:** Mercado Ads (Product Ads) API v2 — operações de WRITE
**Confiança geral:** MEDIUM-HIGH (endpoints e payloads consistentes em múltiplas fontes secundárias; smoke do plan 44-01 obrigatório pra fechar `[CITED]` → `[VERIFIED]`)

## Sumário

A ação "mover adgroup-sugador pra SGI" no Mercado Ads é, na verdade, uma operação no nível do **ad/item** (não do "adgroup" no sentido Google Ads). No modelo ML, o `item_id` (MLB) é a unidade que pertence a uma campanha; mover entre campanhas é `PUT /marketplace/advertising/{site_id}/product_ads/ads/{item_id}` com body `{"campaign_id": N}`. Múltiplos itens podem ser movidos juntos via endpoint coletivo `PUT .../product_ads/ads`. **No nosso domínio, o `adgroup_id` que vem em `Sugador.adgroup_id` é o `ad_group_id` retornado pelo payload de `/product_ads/items` — não há endpoint REST público para mover "ad_group" diretamente; o que se move é o conjunto de `item_id` que compõem aquele `ad_group_id` (todos os MLBs daquele anúncio).** Isso muda a forma do `MercadoLivreAdsService::moveAdgroupToCampaign()` que o plan 44-02 vai escrever — ele precisa internamente buscar os `item_id`s do adgroup (já temos via `AdgroupMlbMapRepository`) e disparar 1 PUT por item, ou usar o endpoint coletivo.

**Bloqueio crítico identificado:** o token OAuth atual do sistema é gerado com `scope='read offline_access'` (`MercadoLivreService.php:53`). Operações de WRITE exigem scope `write`. **TODOS os tokens em prod hoje vão receber 403 nesse PUT.** O plan 44-04 precisa tratar a UX de re-auth com scope expandido.

**Recomendação primária:** plan 44-01 smoke testa `PUT /ads/{item_id}` com `Api-Version: 2` em conta real (Bymobille), confirma 403 → expande scope → re-conecta → confirma 200. Depois desbloqueado planejar 44-02/03/04.

## 1. Endpoints ML — referência canônica

### 1.1 Mover ad (item) entre campanhas — **operação principal da phase**

```
PUT https://api.mercadolibre.com/marketplace/advertising/{site_id}/product_ads/ads/{item_id}?channel=marketplace
Headers:
  Authorization: Bearer {access_token}
  api-version: 2          [CITED: search result lowercase confirmado; testar variante "Api-Version: 1" como fallback]
  Content-Type: application/json
Body:
  { "campaign_id": 1234567 }              # mover para outra campanha
  { "campaign_id": 1234567, "status": "active" }   # mover + ativar
  { "campaign_id": 0 }                    # remover da campanha (vai pra "idle")
```

**Regras críticas documentadas** `[CITED]`:
- `campaign_id: 0` + `status` no mesmo payload = ERRO. Idle status é automático.
- Ad sem campanha movido pra uma campanha SEM `status` no body → vira `active` por default. Se quiser entrar pausado, mandar `status: paused` junto.
- Ads em `status=hold` (desabilitados) **não podem** ser movidos entre campanhas.

### 1.2 Endpoint coletivo (mover múltiplos ads de uma vez)

```
PUT https://api.mercadolibre.com/marketplace/advertising/{site_id}/advertisers/{advertiser_id}/product_ads/ads?channel=marketplace
Body: { "ads": ["MLB...", "MLB..."], "campaign_id": 1234567 }
```

`[CITED — confirmar shape exato no smoke]`. Útil se o adgroup tiver muitos MLBs (evita N chamadas).

### 1.3 Criar campanha SGI

**Há 2 endpoints documentados — testar qual está em produção HOJE:**

**Variante A (marketplace / mais moderna)** `[CITED]`:
```
POST https://api.mercadolibre.com/marketplace/advertising/{site_id}/advertisers/{advertiser_id}/product_ads/campaigns
Headers: Bearer + api-version: 2
Body: {
  "name": "SGI 2026-06",
  "status": "paused",
  "budget": 25,
  "strategy": "profitability",
  "acos_target": 15,
  "channel": "marketplace"
}
```
- `budget` é OBRIGATÓRIO (must be within user's budget limits).
- `status: paused` aceito direto na criação `[CITED — confirmado em múltiplas fontes]`.
- `strategy` ∈ {`profitability`, `increase`, `visibility`}. Para SGI usamos `profitability` (mais conservadora).
- `acos_target` ∈ (3, 500). Default seguro: 15. Para SGI, qualquer valor serve (campanha vai ficar pausada).

**Variante B (legacy `product_ads_2`)** `[CITED]`:
```
POST https://api.mercadolibre.com/advertising/product_ads_2/campaigns
Body: { "budget": 20, "status": "paused" }
```
- Mínimo brutal; pode ser caminho mais simples se Variante A der 404 no smoke.

**Resposta esperada (ambas)** `[CITED]`: JSON com `id` (= `campaign_id` da nova SGI), `name`, `status`, `budget`, `date_created`. **Sim, retorna o `id` — não precisa de GET subsequente** para descobrir o ID.

### 1.4 Atualizar campanha (não usado na Phase 44 — referência futura)

```
PUT https://api.mercadolibre.com/marketplace/advertising/{site_id}/product_ads/campaigns/{campaign_id}
```
Aceita `name`, `budget`, `status`, `acos_target`, `strategy` (todos opcionais; envia só o que muda).

## 2. OAuth + scopes — **BLOQUEIO CRÍTICO**

| Aspecto | Estado atual | Necessário para Phase 44 |
|---------|--------------|---------------------------|
| Scope OAuth solicitado | `read offline_access` (`MercadoLivreService.php:53`) | `read write offline_access` |
| Comportamento PUT/POST hoje | **403 garantido** (token não tem permissão de write) | 200/201 após re-auth |
| Permissão na app ML | Desconhecido — verificar no DevCenter | Ativar "Advertising — access, create and manage campaigns" |

**Mecânica de re-auth** `[VERIFIED via código local]`:
- `MercadoLivreService::buildAuthUrl()` gera URL com o scope HARDCODED na linha 53. **Atualizar para `'read write offline_access'` é code change no plan 44-02 ou 44-04.**
- Cada empresa precisa re-autorizar (operador navega no painel ML, autoriza, callback grava novo `MlToken` com scope expandido).
- O refresh token novo vai trazer o scope expandido automaticamente após a primeira re-auth — não precisa migration de dados.

**Configuração da app ML (não-code):**
- No painel `developers.mercadolibre.com.br` → app ECF → Permissões funcionais → adicionar "Advertising" `[CITED]`. Sem essa permissão na app, mesmo com scope=write o token ainda retorna 403.
- Verificar PRIMEIRO no smoke 44-01: se 403 persistir após scope expandido, é a permissão da app que falta.

**UX do plan 44-04:** banner em `Show.jsx` quando `MlToken.scope` não contém `write` → "Esta empresa precisa re-conectar com permissão de escrita no Mercado Ads. [Reconectar]". Botão chama rota que dispara `buildAuthUrl()` (já existe — Phase 20).

## 3. Tratamento de erro por status HTTP

Reusar o padrão de `MercadoLivreAdsService::callWithBackoff()` (já trata 401/403/429/5xx). Para a operação WRITE específica, o `moveAdgroupToCampaign` deve mapear cada código pra ação concreta:

| HTTP | Causa provável | Ação backend | UX |
|------|----------------|--------------|-----|
| **200/201** | Sucesso | Persistir `Sugador.status='movido'` + activity_log + 10s undo window | Toast verde + "Desfazer" |
| **401** | Token expirado | `MercadoLivreService::refreshToken()` 1x + retry (já implementado em `callWithBackoff`) | Transparente |
| **401 após refresh** | Refresh token revogado | `RuntimeException` propaga | Toast vermelho "Reconectar conta ML" + link `/sistema/ml-oauth` |
| **403** | Scope sem write OU app sem permissão Advertising | NÃO retentar (igual `callWithBackoff` atual) | Toast vermelho "Token sem permissão de escrita" + botão "Reconectar com permissão expandida" |
| **404 em PUT** | Ad/item não existe mais no ML (foi removido manualmente no painel) | `Sugador.status='auto_resolvido'` + `acao_tomada='auto_removido_externamente'` | Toast neutro "Anúncio não existe mais no ML — sugador marcado como resolvido" |
| **404 em POST** | advertiser_id inválido (cache stale) | Invalidar `MlAdvertiser` daquela empresa + retry 1x | Transparente; se segundo 404, erro |
| **409 Conflict** | Ad já está nessa campanha (não documentado, mas possível) | Tratar como sucesso silencioso | Toast neutro "Já estava nessa campanha" |
| **422** | Body inválido (`campaign_id` mal formatado, status incompatível, ad em `hold`) | NÃO retentar; logar body de resposta | Toast vermelho com mensagem do ML |
| **429** | Rate limit | `callWithBackoff` já trata (Retry-After + jitter) | Transparente |
| **5xx** | ML instável | `callWithBackoff` faz backoff exponencial (até 5 tentativas) | Transparente até estourar tetom; depois toast vermelho |
| **Timeout > 30s** | Rede ou ML pendurado | Aborta no `Http::timeout(30)` (adicionar — não tem hoje) | Toast vermelho "Tempo esgotado" |

**`[ASSUMED]`** Status 409 não está documentado nas fontes consultadas. Tratamento defensivo: mapear `409` como sucesso silencioso (idempotência implícita).

## 4. Limites operacionais

| Limite | Valor | Fonte |
|--------|-------|-------|
| Rate limit ML | 60 req/min por seller `[VERIFIED via código]` | `MercadoLivreAdsService::RATE_LIMIT_PER_MIN` Phase 41 |
| Budget mínimo campanha | "within user's budget limits" — não há mínimo absoluto público `[CITED]` | Smoke 44-01 testa com `budget: 5` (BRL) |
| `acos_target` | (3, 500) `[CITED]` | Doc oficial |
| Máx N de ads por campanha | **Não documentado** `[ASSUMED]` — não há evidência de limite hard | Smoke pode pular esse teste; assumir ilimitado |
| Máx N de campanhas por advertiser | **Não documentado** `[ASSUMED]` | Idem |
| Tipo de campanha (catalog vs product) | Não há restrição documentada de cross-type move `[ASSUMED]` | Smoke 44-01: tentar mover entre catalog_listing e ad normal — se falhar, documentar |

## 5. Refresh do `MlAdvertiser` cache

**Não precisa invalidar** após `moveAdgroupToCampaign()`:
- `MlAdvertiser` cacheia (advertiser_id, seller_id, site_id) — esses NÃO mudam ao mover ads.
- Cache TTL atual = 7 dias `[VERIFIED]` em `MercadoLivreAdsService:68`.
- Após criar nova SGI (POST campaign), também NÃO precisa invalidar — o advertiser não muda; apenas a próxima chamada `listCampaigns()` vai descobrir a nova SGI (o que é o comportamento desejado para o combobox da UI).

**Único cache a invalidar:** Cache local (frontend) da lista de campanhas no combobox SGI. Solução: `router.reload({ only: ['campanhas_sgi'] })` após criar nova SGI no modal.

## 6. Pontos do plan 44-01 (smoke) — FOCADO

Smoke deve ser um comando Artisan `php artisan sugadores:ml-write-smoke --company={id} --dry-run` que executa essa sequência e imprime relatório:

1. **Detectar scope do token atual.** `MlToken::where(...)->value('scope')` — se não contém `'write'`, ABORTAR com mensagem "Re-auth necessário antes do smoke. Atualizar scope em MercadoLivreService.php linha 53 e re-conectar empresa."
2. **GET advertiser + GET 1 campanha de teste + GET 1 ad de teste** (read-only — confirma que o smoke tem dados pra trabalhar). Reusar `MercadoLivreAdsService::discoverAdvertiser()` + `listCampaigns()` + `listAds()`.
3. **POST criar SGI de teste** com payload Variante A: `{name: "SGI-SMOKE-TEST-{timestamp}", status: "paused", budget: 5, strategy: "profitability", acos_target: 15, channel: "marketplace"}`. Capturar `campaign_id` retornado. **Se 404 → testar Variante B.**
4. **PUT mover 1 ad** (do passo 2) pra SGI criada no passo 3. Payload `{campaign_id: {id_da_sgi}}`. Confirmar 200.
5. **PUT reverter o ad** pra campanha original. Confirmar 200.
6. **(NÃO) deletar a SGI criada.** Não há endpoint DELETE documentado; deixa a SGI pausada lá pra operador limpar manualmente. Imprimir aviso "SGI SGI-SMOKE-TEST-... criada com status=paused — remover manualmente no painel ML se desejar."
7. **Imprimir relatório**: `endpoints_ok / endpoints_failed / status_codes_observed / api_version_used / scope_observed / advertiser_id / new_campaign_id / move_target_item_id`.

**Critério de aprovação do plan 44-01:** todos os passos 2-5 retornam 2xx. Se passo 3 falhou (Variante A → testar B); se passo 4 retornou 403, escalar pro fluxo de scope/permissão antes de continuar Phase 44.

**Gravar fixture JSON** em `storage/app/sugadores/ml-write-smoke/{company_id}-{timestamp}.json` com a resposta de cada passo — alimenta planejamento dos plans 44-02/03/04.

## 7. Pegadinhas / landmines

### 7.1 "adgroup" do nosso domínio ≠ entidade de 1 endpoint ML
O `Sugador.adgroup_id` é um `ad_group_id` (chave agrupadora retornada pelo payload `/product_ads/items`), mas **não existe endpoint REST `/ad_groups/{id}` no Mercado Ads**. Pra mover "o adgroup", o backend tem que: (a) buscar todos os `item_id` (MLBs) daquele adgroup via `AdgroupMlbMapRepository::getMlbsForAdgroup($company_id, $adgroup_id)` (já existe — Plan 39-03); (b) iterar PUT por item OU usar endpoint coletivo `.../product_ads/ads`. **`moveAdgroupToCampaign()` é um wrapper sobre N PUTs ou 1 PUT coletivo — não é uma única chamada atômica.**

### 7.2 Falha parcial no move (N itens, M falham)
Se o adgroup tem 10 MLBs e 8 movem com sucesso mas 2 falham (ex: 1 em `hold`, 1 com 404), o estado fica **inconsistente** — 80% movido, 20% na campanha original. **Decisão recomendada:** tratar como sucesso parcial com warning, **não** tentar rollback (que pode falhar também e piorar). UI mostra toast amarelo "Movido 8 de 10 anúncios. 2 falharam — ver detalhes no painel ML". `Sugador.status='movido'` SE pelo menos 1 sucesso; activity_log registra o counter `(success, failed)`.

### 7.3 Unique key do Sugador NÃO inclui campaign_id (re-verificar antes de codar!)
Memory `project_sugadores_unique_key_inclui_adgroup_id` diz que a unique key é `(company_id, reference_date, tipo, campaign_id, adgroup_id)`. **`campaign_id` ESTÁ na chave.** Após o move, na próxima análise diária:
- Mesmo `(adgroup_id, reference_date, tipo, company_id)` mas `campaign_id` diferente (= SGI) → cria um sugador NOVO em vez de atualizar o antigo.
- O sugador ANTIGO (campaign_id original) continua com `status='movido'` (não é re-detectado porque está em `STATUS_TRAVADOS`).
- O sugador NOVO (campaign_id=SGI) **não é criado** porque `shouldSkipCampaign()` filtra campanhas com nome SGI/Sugador/Sugadores antes do upsert (`SugadorAnalysisService:711`).

**Conclusão:** **não há criação de fantasma** — a quarentena por nome bloqueia upserts em campanhas SGI. Não precisa migration nem ajuste de chave. Confirmar no smoke: rodar análise no dia seguinte ao move e verificar que `sugadores.count()` não cresceu pelo adgroup movido.

### 7.4 Empresas ML-only são o caso primário
Memory `project_ml_only_companies_adman_endpoints` lembra: empresas com `adman_account_id=NULL` retornam 422 em endpoints Adman MCP. **Phase 44 é 100% sobre operação no path ML — não toca Adman MCP — então o 422 do MCP não é problema aqui.** Mas: o método `moveAdgroupToCampaign()` deve validar que o `Sugador` é origem ML (via `Sugador::isOrigemMl()` que já existe em `Sugador.php:237`) antes de prosseguir; se for sugador origem Adman antigo (raw_data ausente), abortar com erro "Ação disponível apenas para sugadores Mercado Livre".

### 7.5 `Api-Version: 1` vs `api-version: 2` vs `Api-Version: 2`
Codebase atual usa `'Api-Version' => '1'` em GETs (`MercadoLivreAdsService:51`). Search results mostram `api-version: 2` (minúsculo, valor 2) para PUT em ads. **Plan 44-01 smoke MUST testar ambas as combinações** (case-sensitivity de header HTTP é tecnicamente ignorada, mas o VALOR 1 vs 2 importa). Documentar qual funcionou.

### 7.6 Token revogado no meio do undo (10s window)
Cenário raro: operador clica "Mover" → 200 OK → toast com undo aparece → token expira no segundo 9 → operador clica "Desfazer" → 401 → refresh → ainda 401 → undo falha. **Aceitar:** toast vermelho "Desfazer falhou — token expirou. Reverta manualmente no painel ML." `Sugador.status` continua `'movido'`. Aceitável porque o cenário é raríssimo e a recuperação manual no painel ML leva 30 segundos.

### 7.7 Outro dev trabalhando em paralelo na milestone v11.0
Memory `feedback_perguntar_antes_deploy_v9.md` — confirmar caso-a-caso ANTES de rodar `deploy.sh` (push e demais comandos seguem autorização permanente).

## 8. Recomendações para o planner (estrutura sugerida 44-01 → 44-04)

### Plan 44-01 — Smoke + scope OAuth (BLOQUEIO)
- Tarefa 1: criar comando Artisan `sugadores:ml-write-smoke` conforme §6
- Tarefa 2 (manual + code): atualizar `MercadoLivreService.php:53` scope para `'read write offline_access'`; navegar em prod até `/sistema/ml-oauth/conectar/{company_bymobille}`; re-autorizar; confirmar `MlToken.scope` contém `write`
- Tarefa 3: rodar smoke; capturar fixture JSON; aprovar/reprovar Variante A vs B do POST create campaign; documentar `api-version` que funcionou
- **Gate:** plan 44-01 só fecha com fixture mostrando 5/5 passos verdes
- **Não fazer deploy isolado** — agrupar com 44-02 minimum

### Plan 44-02 — Backend (service + controller + rota)
- Tarefa 1: `MercadoLivreAdsService::createCampaign(Company $company, array $payload): array` + `moveAdgroupToCampaign(Company $company, string $adgroupId, int $newCampaignId): array` (wrapper sobre N PUTs ou 1 PUT coletivo conforme decisão pós-smoke)
- Tarefa 2: rota `POST /sugadores/{id}/mover-sgi` em `routes/web.php` (grupo auth + role:admin sob feature flag); rota `POST /sugadores/{id}/criar-sgi-e-mover` (combinada criar+mover); rota `POST /sugadores/{id}/desfazer-move` (undo)
- Tarefa 3: `SugadorController::moverSgi/criarSgiEMover/desfazerMove` — todas com Gate::authorize, try/catch \Throwable, persistência apenas em sucesso, activity_log via Spatie (verbo `moveu_para_sgi`)
- Tarefa 4: feature flag `config/features.php` chave `sugadores_mover_sgi: bool` (default false); checar em controller + frontend prop
- Testes Feature: 1 happy path (mock `MercadoLivreAdsService`), 1 falha 403 (não persiste status), 1 falha parcial (success=8 failed=2), 1 sem feature flag (404)

### Plan 44-03 — Frontend (Show.jsx modal + toast undo)
- Tarefa 1: botão "Mover pra SGI" no header do `Show.jsx` (gated por `sugador.tipo==='adgroup' && !['movido','resolvido'].includes(sugador.status) && features.sugadores_mover_sgi`)
- Tarefa 2: modal Dialog com combobox (SGIs filtradas client-side por regex `\b(sgi|sugadores?)\b/iu` aplicado à `campanhas` já enviada como prop) + botão "Criar nova SGI" inline (input nome com placeholder `SGI [YYYY-MM]`, slider/input budget mínimo 5 BRL)
- Tarefa 3: confirmação dupla (nome do adgroup + nome da SGI exibidos, botão vermelho)
- Tarefa 4: toast com "Desfazer" 10s (manter `campaign_id` original em useState local — sem persistência DB); router.post pra `/desfazer-move` quando clicado
- Tarefa 5: aviso não-bloqueante "⚠ SGI está ATIVA" quando campanha escolhida tem `status==='active'`
- Não é phase UI dedicada (gate `ui_phase` não é gatilho aqui — só Show.jsx)

### Plan 44-04 — UX re-auth para empresas sem scope write
- Tarefa 1: prop `ml_scope_has_write: bool` no `SugadorController::show` (lê `MlToken.scope`)
- Tarefa 2: banner amarelo no `Show.jsx` quando `!ml_scope_has_write` E `sugador.tipo==='adgroup'`: "Esta conta precisa re-conectar com permissão de escrita no Mercado Ads para usar a ação Mover. [Reconectar]"
- Tarefa 3: rota de reconexão já existe (Phase 20) — apenas adicionar query param `?reauth=write` se for útil pra distinguir
- Tarefa 4: instruções operacionais em `STATE.md` — passo-a-passo pra ativar permissão "Advertising" na app ML no DevCenter

**Deploy agrupado:** 44-01 + 44-02 + 44-03 + 44-04 juntos, com a feature flag em `false` por default. Habilitar pra admin primeiro (config `features.sugadores_mover_sgi = true` apenas no painel `/dev`), validar 1 semana, depois ampliar.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-----------------|
| A1 | Endpoint `PUT .../product_ads/ads/{item_id}` aceita `campaign_id` no body com `api-version: 2` | §1.1 | Plan 44-02 quebra; smoke 44-01 detecta antes de codar muito |
| A2 | `campaign_id: 0` é o jeito de remover ad de campanha (idle) | §1.1 | Não usamos no escopo Phase 44 (deferred ação "pausar in-place") — risco nulo na phase atual |
| A3 | POST `/marketplace/.../product_ads/campaigns` retorna `id` no JSON de sucesso | §1.3 | Plan 44-02 quebra; smoke 44-01 confirma |
| A4 | `status: paused` é aceito no POST de criação | §1.3 | Workaround: criar `active` e PUT pausar logo depois (+1 chamada). Smoke 44-01 confirma |
| A5 | Scope expandido `read write offline_access` é aceito pelo OAuth ML | §2 | Plan 44-01 Tarefa 2 testa empiricamente |
| A6 | App ECF no DevCenter já tem permissão funcional "Advertising" ativada | §2 | Se faltar, 403 persiste mesmo com scope=write — Tarefa 4 do plan 44-04 cobre |
| A7 | Status 409 é tratável como sucesso silencioso (idempotência) | §3 | Não documentado; tratamento defensivo |
| A8 | Não há limite hard de ads/campanha ou campanhas/advertiser | §4 | Limite raro, smoke não cobre; deferred pra phase 44b se virar problema em prod |
| A9 | `shouldSkipCampaign()` bloqueia upsert em SGI, evitando fantasmas pós-move | §7.3 | Verificado por inspeção de código (`SugadorAnalysisService:711`); risco baixo |
| A10 | Variante A do POST campanha (`/marketplace/.../advertisers/{aid}/product_ads/campaigns`) é a vigente; Variante B (`/product_ads_2/campaigns`) é legacy | §1.3 | Smoke 44-01 confirma com fallback |

## Sources

### Primary (HIGH confidence)
- Código local — `app/Services/Sugadores/MercadoLivreAdsService.php` (Phase 41) — padrão de chamadas ML, OAuth refresh, rate limit, cache advertiser
- Código local — `app/Services/MercadoLivreService.php:53` — scope OAuth atual = `'read offline_access'` (BLOQUEIO confirmado)
- Código local — `app/Services/Sugadores/MercadoLivreSugadoresProvider.php:216` — confirmação de que payload usa `ad_group_id` (snake_case) e `item_id` = MLB
- Código local — `app/Services/SugadorAnalysisService.php:54,711` — `QUARANTINE_NAME_REGEX` + `shouldSkipCampaign` confirmam mitigação de fantasmas
- Código local — `app/Models/Sugador.php:107-113` — `STATUS_MOVIDO` já no enum, `STATUS_TRAVADOS` inclui `movido`

### Secondary (MEDIUM confidence — `[CITED]`)
- [Mercado Livre Sponsored Products API — Medium @frederic_44639](https://medium.com/@frederic_44639/mercado-libre-sponsored-products-api-an-inside-look-18b5afbb3ec9) — visão geral capabilities
- [Mercado Ads Python — dltHub](https://dlthub.com/context/source/mercado-ads) — listou GET endpoints + headers (Api-Version, Bearer)
- WebSearch (Bing/Google) sobre `PUT /product_ads/ads/{item_id}` — múltiplas fontes secundárias convergiram em mesmo formato URL, body, header `api-version: 2`, regra `campaign_id: 0`
- WebSearch sobre POST create campaign — confirmou Variante A (marketplace) e Variante B (product_ads_2) com body shapes
- WebSearch sobre OAuth scopes ML — confirmou valores aceitos `read`, `write`, `offline_access` + necessidade de permissão funcional "Advertising" na app

### Tertiary (LOW — não acessível)
- [developers.mercadolivre.com.br/en_us/product-ads-us-read](https://developers.mercadolivre.com.br/en_us/product-ads-us-read) — 403 ao WebFetch (Cloudflare bloqueia bots). Smoke 44-01 supre a verificação direta.
- [developers.mercadolibre.com.ar/en_us/product-ads](https://developers.mercadolibre.com.ar/en_us/product-ads) — idem
- [global-selling.mercadolibre.com/devsite/campaigns-ads-and-metrics](https://global-selling.mercadolibre.com/devsite/campaigns-ads-and-metrics) — idem

## Metadata

**Confidence breakdown:**
- Endpoints (§1): MEDIUM-HIGH — múltiplas fontes secundárias convergiram, mas oficial inacessível. Smoke 44-01 fecha gap.
- OAuth scopes (§2): HIGH — confirmado via código local + docs ML core OAuth.
- Tratamento erro (§3): MEDIUM — política 401/403/429/5xx já implementada no codebase; 404/409/422 baseados em padrão REST genérico.
- Limites operacionais (§4): LOW para limites hard (não documentado publicamente).
- Pegadinhas (§7): HIGH — derivadas de inspeção direta do código e memories do projeto.

**Pesquisa válida até:** 2026-07-26 (1 mês — API ML é estável mas mudanças de scope/permissão acontecem)
**Pesquisado por:** gsd-phase-researcher
