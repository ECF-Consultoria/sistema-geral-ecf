---
phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o
verified: 2026-07-23T18:31:52Z
status: passed
score: 6/6 must-haves verificados
overrides_applied: 0
---

# Phase 109: Shopee em Carteira e Desempenho — Verification Report

**Phase Goal:** Empresas do setor Shopee (conectadas via API) passam a aparecer nas carteiras de quem cuida e na aba Desempenho, usando a MESMA regra de período do Mercado Livre. Fonte: `shopee_metrics` (faturamento + investimento; SEM margem/CMV por ora).
**Verified:** 2026-07-23T18:31:52Z
**Status:** passed
**Re-verification:** Não — verificação inicial.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SHOP-CAR-01 — Shopee é fonte financeira elegível na carteira | ✓ VERIFIED | `CarteiraContextService::flagsFinanceirasPorSetor()` (linhas 247-276) tem branch `Servico::SETOR_SHOPEE` → `financial_source='shopee'`, `financial_metrics_eligible=true`. `MetricDiffDispatcher::compute()` roteia `'adman'`/`'shopee'` via `match` com `InvalidArgumentException` no default (whitelist real, não silenciosa). Testes: `CarteiraContextShopeeElegivelTest` 3/3 PASS, `MetricDiffDispatcherTest` 3/3 PASS, `ShopeeMetricDiffServiceTest` 7/7 PASS. |
| 2 | SHOP-CAR-02 — 4 telas de carteira mostram faturamento+investimento Shopee por período (mesma janela do ML); margem "—" | ✓ VERIFIED | `PortfolioController` tem dispatch por fonte nas 4 funções (`transparencia()`, `renderCarteiraProfissional()`, `renderCarteirasConsolidadas()`, `renderPortfolio()`) via helpers `fontesFinanceirasPorEmpresa()`/`companyIdsComDadosShopee()` (grep confirma uso nas 4). UI (`AdminCarteira.jsx`, `Transparencia.jsx`) exibe tooltip "Shopee ainda não fornece margem" e legenda corrigida. Teste `PortfolioShopeeCarteiraTest` 9/9 PASS (cobre as 4 telas, null-vs-zero, desempate, em-curso e bônus-atual). **Validado em produção com números reais** (109-04-SUMMARY): 3 responsáveis Shopee, `score_status='official'`, backfill de maio corrigiu Matheus 2,95→3,63. |
| 3 | SHOP-DES-01 — vínculos Shopee no universo/score/ranking do Desempenho | ✓ VERIFIED | `DesempenhoScoreService` injeta `MetricDiffDispatcher` (não mais `AdmanMetricDiffService` direto); `computeUniverso()` monta mapa `fontes` (company_id→financial_source vencedora); `computeVarFaturamento()`/`computeVarMargem()` roteiam por essa fonte (linhas ~1000, ~1079). Teste `DesempenhoShopeeScoreTest` 7/7 PASS, incluindo `faturamento_inclui_revenue_shopee_via_dispatcher` e `empresa_com_performance_e_shopee_resolve_fonte_adman_desempate`. |
| 4 | SHOP-DES-02 — margem placeholder=1 (future-ready), score_status tolerante, cacheKey v9→v10 | ✓ VERIFIED | `margemPontos()` (linha 1233) implementa blend ponderado por contagem exatamente como o design travado (`$pReal×nComMargemReal + 1.0×nShopeePlaceholder`, dividido pela soma das contagens). `computeScoreStatus()` (linha 639) só retorna `blocked` quando `vinculos_financeiros===0` — só-Shopee não bloqueia mais. `cacheKey()` retorna `desempenho.compute.v10...` (linha 296); `grep -r "desempenho.compute.v9" app tests routes` retorna 0 ocorrências. Teste `cache_key_bumpado_para_v10` PASS; `so_shopee_official_nota_final_nao_null_margem_placeholder_1` PASS. **Impacto real sinalizado e confirmado pelo usuário em prod** (109-04-SUMMARY): Gustavo −0,61, Felipe −0,30, Matheus (só-Shopee) piso conservador — pendência do CONTEXT.md fechada. |
| 5 | Regressão-zero para só-performance | ✓ VERIFIED | Teste `so_performance_regressao_zero_margem_pontos_e_nota_identicos_ao_baseline` PASS (invariante algébrica: `nShopeePlaceholder=0` → `margemPontos` idêntico a `reguaMargem` pré-Fase-109). Suíte ampla de regressão (V16, V18, Phase74, Phase96, Phase106, Dashboard, CompanyPortfolioAccessTest) — **310 passed, 0 failed** rodada nesta verificação (comando `php artisan test`, 217s). 3 falhas em `tests/Feature/Phase61/` (`PortfolioMultiFonteE2ETest` x2, `PortfolioSourceEnrichmentTest` x1) confirmadas **pré-existentes** — reproduzidas de forma independente nesta verificação via `git worktree` no commit `d5a52cd2` (fim do Plano 01, marco antes do Plano 02/03 tocarem consumidores), mesmo erro exato (`user_portfolios` size 0 vs 1), causa raiz é fixture `attachCarteira()` desatualizada de Fase 61 (não relacionada a Shopee). |
| 6 | Regra de período do ML intocada | ✓ VERIFIED | `git log -- app/Services/Metrics/MetricPeriodResolver.php` mostra o último commit tocando o arquivo como sendo da Fase 100 (`feat(100-01)`); nenhum dos 5 commits da Fase 109 (`1874e6cf`, `a1786e83`, `449db5cd`, `afdc5ca2`, `c838222e`) toca o arquivo. |

**Score:** 6/6 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Metrics/ShopeeMetricDiffService.php` | diff revenue+investment via `shopee_metrics`, margem sempre null, shape idêntico ao Adman | ✓ VERIFIED | 232 linhas, substantivo, cache 1440min por dia BRT, `whereDate` (não `whereBetween`) — bug de borda documentado e corrigido. Wired via `MetricDiffDispatcher`. |
| `app/Services/Metrics/MetricDiffDispatcher.php` | roteador por `financial_source` com whitelist | ✓ VERIFIED | `match` explícito, `InvalidArgumentException` no default. Injetado em `PortfolioController` e `DesempenhoScoreService` (constructor injection confirmado). |
| `app/Services/Portfolio/CarteiraContextService.php` (branch shopee) | `flagsFinanceirasPorSetor()` reconhece setor Shopee | ✓ VERIFIED | Branch `Servico::SETOR_SHOPEE` presente e testado. |
| `app/Services/DesempenhoScoreService.php` (margemPontos/computeScoreStatus/cacheKey v10) | blend de margem, status tolerante, cacheKey bumpado | ✓ VERIFIED | Todos os 3 elementos confirmados no código-fonte com docblocks explicando a Fase 109. |
| `app/Http/Controllers/PortfolioController.php` (4 telas) | dispatch por fonte em `transparencia`/`renderCarteiraProfissional`/`renderCarteirasConsolidadas`/`renderPortfolio` | ✓ VERIFIED | Helpers `fontesFinanceirasPorEmpresa`/`companyIdsComDadosShopee`/`vinculosParaContadoresDisplay` usados nas 4 funções (grep confirma). |
| `app/Console/Commands/WarmShopeeDiffCache.php` | espelha `WarmAdmanDiffCache`, agendado | ✓ VERIFIED | Comando `shopee:warm-diff` completo, testado (`WarmShopeeDiffCacheCommandTest` 3/3 PASS), agendado em `routes/console.php` às 11:35 (após `shopee:sync-ads` 11:30, antes de `adman:warm-diff` 11:40). |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `PortfolioController` (4 funções) | `MetricDiffDispatcher::compute()` | constructor injection + chamada direta nos 4 métodos | ✓ WIRED | Confirmado via grep de uso em `transparencia`, `renderCarteiraProfissional`, `renderCarteirasConsolidadas`, `renderPortfolio`. |
| `DesempenhoScoreService::computeVarFaturamento/computeVarMargem` | `MetricDiffDispatcher::compute()` | mapa `$fontes` (company_id→source) passado como parâmetro | ✓ WIRED | Confirmado no código-fonte (linhas 990-1140) e nos testes `DesempenhoShopeeScoreTest`. |
| `ShopeeMetricDiffService` | `shopee_metrics` (tabela) | Eloquent query `naJanela()` | ✓ WIRED | `whereDate` em `reference_date`, soma `revenue`/`ad_expense`. |
| `WarmShopeeDiffCache` | `ShopeeMetricDiffService::compute()` | chamada direta em loop empresa×período | ✓ WIRED | Popula o mesmo cache lido pelos consumidores (`shopee:diff:v1:...`). |
| `routes/console.php` | `shopee:warm-diff` | `Schedule::command()` | ✓ WIRED | Agendado 11:35, ordem correta após sync. |
| UI (`AdminCarteira.jsx`/`Transparencia.jsx`/`Performance/Index.jsx`) | payload do controller | props Inertia (`fonte='shopee'`, `pontos_componentes.margem`) | ✓ WIRED | Tooltips/indicadores condicionais confirmados no código JSX. |

### Behavioral Spot-Checks / Testes Automatizados

| Suíte | Comando | Resultado | Status |
|-------|---------|-----------|--------|
| `DesempenhoShopeeScoreTest`, `PortfolioShopeeCarteiraTest`, `Unit/Metrics/*`, `CarteiraContextShopeeElegivelTest`, `WarmShopeeDiffCacheCommandTest` | `php artisan test <paths>` | **32 passed (128 assertions)** | ✓ PASS |
| Regressão ampla (V16, V18, Phase74, Phase96, Phase106, Dashboard, CompanyPortfolioAccessTest) | `php artisan test <paths>` | **310 passed (1643 assertions)**, 0 falhas | ✓ PASS |
| `tests/Feature/Phase61/*` (HEAD atual) | `php artisan test tests/Feature/Phase61` | 3 failed, 28 passed | ⚠️ Falhas pré-existentes (ver abaixo) |
| `tests/Feature/Phase61/*` (baseline pré-Fase-109, commit `d5a52cd2` via `git worktree`) | idem, ambiente isolado | **mesmos 3 failed, 8 passed** (subset filtrado), erro idêntico | ✓ Confirma NÃO-regressão |
| `grep -r "desempenho.compute.v9" app tests routes` | grep | 0 ocorrências | ✓ PASS |
| `git log -- app/Services/Metrics/MetricPeriodResolver.php` | git log | Último commit é da Fase 100 | ✓ PASS (intocado) |

### Anti-Patterns Found

Nenhum debt-marker (`TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`) encontrado nos arquivos-chave da fase (`ShopeeMetricDiffService.php`, `MetricDiffDispatcher.php`, `CarteiraContextService.php`, `DesempenhoScoreService.php`, `PortfolioController.php`, `WarmShopeeDiffCache.php`). Menções a "placeholder" no código são o termo de design travado (margem=1), não uma marca de dívida técnica.

### Known Gap (documentado, não bloqueante)

- **`renderPortfolio()` — os widgets `empresas_em_queda` (alertas) e `revenue_timeseries` (gráfico diário) NÃO incluem Shopee.** Leem `AdmanMetric` diretamente em loops separados do bloco financeiro principal (que já tem Shopee). Documentado no 109-02-SUMMARY como decisão consciente de escopo mínimo ("evitar scope creep na função mais arriscada da fase, que já tem a restrição explícita 'NÃO consertar o bug legado'"). `top_3_revenue` já herda a correção (usa `$companies`, que já tem Shopee). Não contradiz o texto do SHOP-CAR-02 ("mostram faturamento+investimento Shopee por período" — satisfeito pelo bloco financeiro principal, que é o que os testes cobrem). Classificado como INFO, não WARNING — candidato a follow-up se o usuário notar a lacuna nos 2 widgets secundários.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| SHOP-CAR-01 | 109-01 | Shopee elegível financeiro na carteira | ✓ SATISFIED | Ver Truth #1 |
| SHOP-CAR-02 | 109-02, 109-04 | Faturamento+investimento por período, mesma janela do ML | ✓ SATISFIED | Ver Truth #2 |
| SHOP-DES-01 | 109-03 | Shopee entra no score/ranking de Desempenho | ✓ SATISFIED | Ver Truth #3 |
| SHOP-DES-02 | 109-03, 109-04 | Margem placeholder=1, arquitetura future-ready | ✓ SATISFIED | Ver Truth #4 |

Nota: SHOP-CAR/SHOP-DES não constam em `.planning/REQUIREMENTS.md`/`REQUIREMENTS-v18.md` — são requisitos ad-hoc definidos diretamente no ROADMAP.md para esta fase avulsa (fora da milestone estruturada v18.0), conforme o texto "a refinar no plan". Não é órfão: todas as 4 REQs citadas no ROADMAP foram reivindicadas nos planos (`requirements-completed` nos frontmatters dos 4 SUMMARYs) e verificadas acima.

### Human Verification Required

Nenhum item pendente. O checkpoint 109-04 (plano `autonomous:false`, dedicado a validação humana) já foi executado: deploy em produção, `shopee:warm-diff`/`desempenho:warm-cache` aquecidos, números reais conferidos pelo usuário para os 3 responsáveis com carteira Shopee, e o impacto de margem=1 sinalizado com números concretos (pendência explícita do CONTEXT.md) — fechado conforme 109-04-SUMMARY.

### Achado Separado (fora do escopo desta verificação)

A instabilidade da margem % nativa do `.diff` da Adman (detectada durante o checkpoint 109-04) é uma questão pré-existente do caminho Adman, não tocada pela Fase 109 (regressão-zero comprovada nesta verificação). Não conta contra o goal desta fase — foi corretamente escopada pelo usuário para investigação separada via `/gsd:debug`.

### Gaps Summary

Nenhum gap bloqueante. Todas as 6 truths derivadas do goal/CONTEXT.md foram verificadas com evidência direta no código-fonte, testes automatizados (32 testes específicos da fase + 310 testes de regressão ampla, todos verdes) e validação de produção documentada com números reais confirmados pelo usuário. O único item notável é um known-gap de escopo já documentado pelo próprio executor (2 widgets secundários do self-view não somam Shopee), classificado como informativo por não contradizer o texto do requisito SHOP-CAR-02.

---

_Verified: 2026-07-23T18:31:52Z_
_Verifier: Claude (gsd-verifier)_
