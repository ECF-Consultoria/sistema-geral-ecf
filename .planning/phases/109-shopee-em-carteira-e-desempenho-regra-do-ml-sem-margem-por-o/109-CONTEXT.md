# Phase 109: Shopee em Carteira e Desempenho — Context

**Gathered:** 2026-07-23
**Status:** Ready for planning
**Source:** Reconhecimento em sessão (2 agentes Explore) + decisões do usuário

<domain>
## Phase Boundary

Empresas do setor Shopee (conectadas via API — ex: Ale Peças, Baraoshop) passam a aparecer nas **carteiras** de quem cuida e na aba **Desempenho**, usando a MESMA regra de período do Mercado Livre. Fonte de dados: `shopee_metrics` (diária). Shopee ainda NÃO tem margem — faturamento + investimento entram; margem fica com placeholder até haver dado.

**Entra no escopo:**
- Carteira individual (`renderCarteiraProfissional`), carteira consolidada admin (`renderCarteirasConsolidadas`) e Transparência mostram números Shopee (faturamento + investimento) por período.
- Desempenho (`DesempenhoScoreService` → ranking/nota) inclui vínculos Shopee no universo elegível e no score.
- Ambos os modos de período: "Em curso" e "Bônus atual".

**Fora do escopo:**
- Não mexer em `MetricPeriodResolver` (agnóstico de fonte, já resolve as janelas).
- Não implementar margem real da Shopee (não há dado); só deixar a arquitetura pronta.
- Não tocar no sync Shopee (`shopee:sync`/`shopee:sync-ads` já populam `shopee_metrics`).
- Não mexer no Dashboard Shopee standalone.
</domain>

<decisions>
## Implementation Decisions (TRAVADAS pelo usuário 2026-07-23)

### Regra de período (idêntica ao ML — via MetricPeriodResolver, sem alteração)
- **"Em curso"** = dia 01 do mês atual até hoje vs o mesmo intervalo alinhado do mês passado (`comparison_mode='same_interval_previous_month'`).
- **"Bônus atual"** = mês fechado (competência) vs janela de mesmo tamanho imediatamente anterior (`comparison_mode='previous_equal_length_window'`), com badge de crescimento/queda.

### Métricas Shopee
- **Faturamento** = `shopee_metrics.revenue` (GMV bruto do dia). Entra na carteira e no desempenho.
- **Investimento** = `shopee_metrics.ad_expense`. Entra na carteira; no desempenho segue a mesma dimensão que o ML usa hoje (variação de faturamento é a dimensão financeira primária).
- **Margem** = NÃO existe na Shopee. `contribution_margin_value`/`contribution_margin_pct` retornam `null` no diff service Shopee.

### Nota do Desempenho (DECISÃO CRÍTICA — afeta bônus)
- A nota mantém as **3 dimensões**: Faturamento + Margem + NPS.
- Como Shopee ainda não tem dado de margem, a **nota da dimensão margem das empresas Shopee = 1** (piso da régua 1-5) como placeholder.
- Arquitetura deve ficar **future-ready**: quando a Shopee passar a fornecer margem, basta a fonte de diff Shopee retornar o valor e a régua passa a usar o número real, sem refatorar a estrutura da nota.
- Profissional só-Shopee NÃO deve cair em `blocked`/`partial` por ausência de margem — o `score_status` precisa tolerar margem placeholder e produzir nota_final.
- ⚠️ **A VERIFICATION deve sinalizar o impacto de margem=1 na média/ranking com números reais** para o usuário confirmar antes de considerar fechado (margem=1 puxa a média para baixo em quem é só-Shopee).

### Escopo de entrega
- **UMA fase** cobre Carteira + Desempenho (compartilham a mesma fonte de diff — plugar no backend faz os dois herdarem).
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Fonte de dados Shopee (pronta)
- `app/Models/ShopeeMetric.php` — model diário (revenue, orders_count, sold_quantity, ad_expense, ad_*); `company()` belongsTo.
- `database/migrations/2026_07_19_120000_create_shopee_metrics_table.php` — schema base (unique company_id+reference_date).
- `database/migrations/2026_07_22_120000_add_ads_columns_to_shopee_metrics_table.php` — colunas ad_* (nullable).
- `app/Services/Shopee/ShopeeService.php` — `syncCompanyDay()`, `syncAdsDay()`, `tacos()`.
- `app/Models/Servico.php` — `SETOR_SHOPEE='shopee'`, `isShopee()`.
- `app/Models/Company.php` — `shopeeToken()` (app erp, status active) identifica empresa conectada.

### Contrato a espelhar (fonte única atual ML/Adman)
- `app/Services/Metrics/AdmanMetricDiffService.php` — `compute(Company, array $periodo)` retorna `metrics.revenue`/`contribution_margin_value`/`contribution_margin_pct` (cada uma value+diff_pct+diff_source); gate `.diff` por `comparison_mode`; fallback soma DB com guards; cache 24h. **O `ShopeeMetricDiffService` deve ter o MESMO shape de retorno** (margem null).
- `app/Services/Metrics/MetricPeriodResolver.php` — janelas em-curso/fechado (NÃO ALTERAR).

### Pontos de integração (onde plugar)
- `app/Services/Portfolio/CarteiraContextService.php:247-263` — `flagsFinanceirasPorSetor()` (portão de elegibilidade; hoje só `performance`). Ver `vinculosLegadoNull()` (:187-218) — ramo legado exige contrato performance ativo.
- `app/Services/DesempenhoScoreService.php` — `computeUniverso()` (:533-558), `computeVarFaturamento()` (:928-974), `computeVarMargem()` (:995-1017), `computeScoreStatus()` (:573-584), `reguaFaturamento()`/`reguaMargem()` (:1078-1107), `computeNotaFinal()` (:1046-1064), `cacheKey()` (:274-289, hoje **v9**).
- `app/Http/Controllers/PortfolioController.php` — `renderCarteiraProfissional()` (:577, :589-593), `transparencia()` (:249-252, já tem branch fonte='shopee' :214-223), `renderCarteirasConsolidadas()` (:840+), leituras diretas `adman_metrics` (:438-449), `contextoFiltro()`.
- `app/Http/Controllers/PerformanceController.php` — `contextoFiltro()` (filtro shopee view-only), `resolveContextoPeriodo()`.
- UI React (já recebe `fonte='shopee'`/`status='sem_fonte'`, passa a receber número real): `resources/js/Pages/Portfolio/AdminCarteira.jsx`, `Portfolio/Transparencia.jsx`, `Portfolio/Carteiras.jsx`, `Performance/Index.jsx`.
- Crons: `app/Console/Commands/WarmAdmanDiffCache.php` (só aquece Adman → precisa equivalente/estender p/ Shopee); `SnapshotDesempenhoScores`, `ConsolidarMesDesempenho`, `WarmDesempenhoCache` herdam do `compute()`.
</canonical_refs>

<specifics>
## Specific Ideas / Ressalvas de dados

- **Ads só ~6 meses de histórico** (`LOOKBACK_MONTHS=6` em `ShopeeSyncAds`): comparação de investimento em janela anterior a ~6 meses fica `ad_expense=NULL`. Faturamento não tem essa limitação (depende do backfill rodado). Tratar `null` de investimento graciosamente (não confundir com zero).
- **Dias sem venda NÃO geram linha** em `shopee_metrics`: ao somar/comparar períodos, dias ausentes = zero (não dado faltante).
- **Cobertura histórica de faturamento = função do backfill** (`MIN(reference_date)` por empresa); não assumir profundidade fixa.
- **Empresa Shopee** = contrato ativo `servico.setor='shopee'` (universo carteira/NPS) e/ou `shopeeToken` ativo (dados vivos) — os conjuntos podem divergir (empresa com contrato mas sem token ainda não tem métricas).
- **Dispatcher por fonte**: precisa decidir por empresa/vínculo se lê Adman ou Shopee. Base: setor do vínculo (`servicos.setor`) resolvido pelo `CarteiraContextService`; `financial_source` já é o campo que o contexto expõe.

## Armadilhas conhecidas (memória do projeto)
- **cacheKey v9→v10**: bump quebra lote de testes hardcoded (Phase96/V16/V18) — atualizar as strings junto (`[[project_cache_version_hardcoded_nos_testes]]`).
- **company_users multi-linha por (empresa, role)** desde a Fase 76 — usar `consultorDoServico`/`estrategistaDoServico`, nunca filtrar só por `role` (`[[project_company_users_multi_linha_servico]]`).
- **Pitfall Rollup**: computar flags booleanas DENTRO do `.map()` no JSX (variável de escopo do componente usada dentro de `.map()` some do bundle) (`[[feedback_rollup_map_scope_bug]]`).
- **Enum servicos.setor 'shopee' já existe**; se alguma migration adicionar enum, precisa branch SQLite senão Feature tests quebram (`[[project_enum_setor_sqlite_check]]`).
- **MySQL**: FK/índice/NULL-on-delete — SQLite não pega, validar ALTER com FK no VPS.
</specifics>

<deferred>
## Deferred Ideas

- **Margem real da Shopee** — quando a API/Shopee fornecer custo/CMV, trocar o placeholder margem=1 pelo número real (arquitetura já preparada nesta fase).
- **Investimento como 2ª dimensão financeira no desempenho** — usuário escolheu manter a estrutura Faturamento+Margem+NPS; investimento aparece na carteira mas não vira dimensão de nota agora.
</deferred>

---

*Phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o*
*Context gathered: 2026-07-23*
