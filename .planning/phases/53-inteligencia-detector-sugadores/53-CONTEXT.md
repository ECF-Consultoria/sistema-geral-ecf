# Phase 53: Inteligência do detector de sugadores — Context

**Gathered:** 2026-07-02
**Status:** Ready for research (research vai investigar os 3 casos em prod)
**Source:** briefing 2026-07-01 bloco B do TODO `.planning/todos/pending/270701-melhorias-sugadores-ui-ux-e-detector.md`

<domain>
## Phase Boundary

Após conexão de mais contas ML (antes só Bymobille tinha ML ativo), o operador expôs 3 casos reais de **falso-positivo** do detector. Cada caso aponta para um gap específico. Esta phase corrige o detector para reduzir falso-positivos, mantendo o critério canônico de "adgroup drenando investimento sem retorno".

**Fluxo canônico do detector (referência):**
- `AnalyzeCompanySugadoresJob` → `SugadorAnalysisService::analyzeCompany`
- Service escolhe `SugadoresAdsProvider` (Adman ou ML via factory)
- Provider retorna `adgroups[]` com métricas + campaign_id
- Service filtra via `shouldSkipCampaign` (quarentena SGI/pausada/encerrada — `QUARANTINE_NAME_REGEX = /\b(sgi|sugadores?)\b/iu`, `QUARANTINE_STATUSES = ['paused','closed','ended']`)
- Service avalia critérios em `evaluateMetrics`: `gasto_sem_venda`, `cpc_alto`, `acos_alto`, `cliques_sem_conversao`
- Upserta em `sugadores` table via unique key `(company_id, tipo, campaign_id, adgroup_id)` (memory `project_sugadores_unique_key_inclui_adgroup_id`)

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED (3 casos B1/B2/B3)

- **B1 CAMILLO PARTS** — adgroup `1902557017` (produto: "Caixa De Direcao Hidraulica Chery Face 1.3 16v 2011") virou sugador mesmo com anúncio **"indisponível no momento"** (pausado no ML).
  - **Hipótese:** detector só olha métricas do adgroup (`gasto_sem_venda` bate porque investment >= threshold + sold=0). Não consulta status do MLB individual (ativo/pausado/indisponível/deleted).
  - **Fix candidato:** provider ML já tem `mlb_id` no payload — adicionar verificação de `status` do MLB via API ML (`/items/{id}?attributes=status,available_quantity`) e skipar quando `status IN ('paused','closed','under_review')` ou `available_quantity=0`. Cache curto (~1h) porque status muda pouco.
  - **Alternativa lean:** ignorar hit de `gasto_sem_venda` quando `investment=0 AND clicks=0` na janela (anúncio inativo — não está drenando nada agora, é ruído histórico). Não requer chamada ML nova.

- **B2 BARAOSHOP VARIEDADES** — adgroup `496843010` (produto: "Meia 7/8 Sigvaris Antitrombo") — drilldown mostra "Nenhum MLB encontrado neste adgroup no período" apesar do anúncio ter **500+ vendas no FULL**.
  - **Hipótese A:** sync `sugadores:sync-adgroup-mlbs` está falhando ou pegando janela errada para esta empresa (`AdgroupMlbMapRepository` retorna vazio).
  - **Hipótese B:** provider ML retorna adgroup sem mlb_id populado (Phase 42 mudou isso — verificar se está funcionando). Se mlb_id está null, o drilldown não acha o MLB, mas o detector ainda flagga porque `sold_quantity` do adgroup pode estar OK.
  - **Hipótese C:** `sold_quantity` retornado pelo provider ML **ignora vendas do FULL** (fulfillment) porque a API Ads só conta vendas atribuídas ao ads. Se o produto tem 500 vendas orgânicas via FULL mas 0 via ads, o `gasto_sem_venda` está tecnicamente correto — mas operacionalmente errado. Se o adgroup pausou o ads porque "já vende no FULL", flagged como sugador está errado.
  - **Fix candidato:** research investiga qual das 3 hipóteses. Depois define o fix (pode ser: fix do sync de MLBs, fix do mlb_id no provider, ou adicionar critério "produto tem venda orgânica >> venda ads" como sinal de "ads desnecessário mas produto vende").

- **B3 DINMAP** — adgroup `1784220962` (produto: "Bota Pvc Cano Extra Curto Bracol") — anúncio com "bastante vendas inclusive dentro do período" foi flagged. Além disso, **um dos sugadores desta empresa está em campanha SGI** (resolvido) e ainda aparece.
  - **Hipótese SGI:** `MercadoLivreSugadoresProvider` linha 156-171 chama `listCampaigns` para popular `campaignNames[]`. Fail-open: se falha, `campaignNames=[]` → `campaignsInfo[cId]=null` → `shouldSkipCampaign(null)` retorna false → adgroup em SGI **NÃO** é skippado. Precisamos ver logs em prod se listCampaigns falhou pra DINMAP OU se o nome da campanha SGI dela não bate no regex.
  - **Hipótese vendas:** `sold_quantity` retornado pelo provider ML pode não incluir vendas efetivas do período (janela errada ou filtro). Se vendas=0 no payload mas real=N, `gasto_sem_venda` bate falsamente.
  - **Fix candidato SGI:** logar quando `listCampaigns` falha (não fail-open silencioso) + failover para consultar campaign_status diretamente por adgroup se lista falhou. Ou: além do regex de nome, checar também `status` do adgroup (se `paused/closed`, skipar).
  - **Fix candidato vendas:** research investiga se `sold_quantity` do provider bate com o que o ML mostra.

### FORA da Phase 53 (locked)

- Reescrita completa do detector — só ajustes cirúrgicos nos 3 gaps identificados
- Consulta de status MLB para Adman (só ML — Adman continua como está)
- Bulk-update de sugadores históricos após correções (ficam onde estão; cron `cleanup-quarentena` já limpa alguns)
- Detector para outros marketplaces (só ML/Adman como sempre)
- Modernização visual — Phase 55

### Abordagem técnica (hipóteses; research valida)

- **Instrumentação primeiro:** research usa SSH tinker em prod pra confirmar cada hipótese com dados reais das 3 empresas. Se hipótese X é confirmada, fix vira task no plan. Se refutada, plan não gasta esforço nela.
- **Fix mínimo por caso:** cada caso ganha 1-2 mudanças no service ou provider. Não empilhar features novas.
- **Tests via SQLite in-memory:** mock do provider retornando payloads dos 3 casos + assertion que os fixes filtram corretamente.
- **Config de detector NÃO muda:** thresholds em `sugador_configs` continuam onde estão. Fix é no critério de skip/hit, não na config.

### Claude's Discretion

- Priorização se algum caso precisar de mais trabalho (research decide)
- Cache de `/items/{id}` do ML — TTL curto (1h razoável)
- Se hipótese "vendas FULL não contam" for real (B2), decidir se adicionar nova coluna `sold_full` no payload do provider OU só flag `has_organic_sales` calculado
- Testes: 1 arquivo por caso ou 1 consolidado — plan decide com base no volume de fixtures

</decisions>

<specifics>
## Specific Ideas

### 3 casos com IDs para reproduzir em prod

| Caso | Empresa | Adgroup ID | Campaign ID | Sintoma |
|---|---|---|---|---|
| B1 | CAMILLO PARTS | 1902557017 | 357429608 | Anúncio "indisponível no momento" no ML flagged |
| B2 | BARAOSHOP VARIEDADES | 496843010 | 351549509 | 500+ vendas no FULL, drilldown vazio |
| B3 | DINMAP | 1784220962 | 358096150 | Vendas no período + outro sugador em SGI resolvido |

### Endpoints úteis para instrumentação (research)

- Provider ML retorna adgroups: `SugadorAnalysisService::analyzeCompany(company)` — rodar via `php artisan sugadores:analyze --company={id} --dry-run`
- Status MLB no ML: `MercadoLivreService::getItem($mlbId)` (ou equivalente — research confirma existência)
- Campanhas ML: `MercadoLivreAdsService::listCampaigns($company, $advertiserId, $from, $to)`

### Métricas atualmente avaliadas (`evaluateMetrics`)

- `gasto_sem_venda`: `sold_quantity=0 AND investment >= threshold`
- `cpc_alto`: `sold_quantity=0 AND cpc > threshold AND clicks >= min_cliques`
- `acos_alto`: `sold_quantity>0 AND acos > threshold`
- `cliques_sem_conversao`: `sold_quantity=0 AND clicks >= threshold`

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefing

- `.planning/todos/pending/270701-melhorias-sugadores-ui-ux-e-detector.md` — bloco B (casos B1/B2/B3)

### Patterns existentes (a investigar/reusar)

- `app/Services/SugadorAnalysisService.php` (728 linhas):
  - Constantes de quarentena linhas 54-57
  - `analyzeCompany` fluxo linhas 150+
  - Loop de adgroups + `shouldSkipCampaign` linhas 184-246
  - `evaluateMetrics` linhas 543-628
  - `shouldSkipCampaign` linhas 711-726 (fail-open documentado linha 713)
- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` (306 linhas):
  - Merge com listCampaigns linhas 143-171 (fail-open documentado linha 154)
- `app/Services/Sugadores/MercadoLivreAdsService.php` — cache `listCampaigns` (Phase 41)
- `app/Console/Commands/CleanupSugadoresQuarentena.php` — pattern de fechar sugador quando campanha vira SGI depois do fato
- `app/Repositories/AdgroupMlbMapRepository.php` — usado pelo drilldown para achar MLBs do adgroup (B2)

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade (falso-positivo confunde time)
- `feedback_gsd_language_pt_br` — pt-BR
- `feedback_lean_planning` — pular discuss/plan-check overhead — APLICADO
- `project_sugadores_unique_key_inclui_adgroup_id` — mudanças no adgroup_id criam fantasmas
- `project_ml_only_companies_adman_endpoints` — empresas ML-only não têm Adman (esperado 422)
- `feedback_sugadores_provider_pattern` — Phase 39 provider pattern; não voltar pra mirror

</canonical_refs>

<deferred>
## Deferred Ideas

- Fix definitivo do `AnalyzeCompanySugadoresJob` para nunca ficar "limite de tempo 16 páginas" — memory `project_sugadores_pagination_limit`. Fora do escopo Phase 53.
- Detector diferenciado por porte da empresa (empresas grandes têm threshold X, pequenas Y) — futuro.
- Cross-check: se produto tem venda no FULL >> venda ads, sinalizar sem flagged — depende de hipótese B2 ser confirmada.
- Consulta de status MLB em batch — se performance ficar ruim com 1 chamada por item.
- Análise de estacionalidade (produto vende só em mês X) — fora do escopo.

</deferred>

---

*Phase: 53-inteligencia-detector-sugadores*
*Context gerado: 2026-07-02 (síntese lean — 3 casos claros com IDs pra reproduzir; research vai em prod validar hipóteses)*
