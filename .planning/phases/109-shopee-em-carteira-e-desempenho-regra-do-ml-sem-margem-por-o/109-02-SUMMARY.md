---
phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o
plan: 02
subsystem: web
tags: [shopee, carteira, portfolio-controller, react, laravel, dispatcher-por-fonte]

# Dependency graph
requires:
  - phase: 109-01
    provides: "ShopeeMetricDiffService::compute(), MetricDiffDispatcher::compute(company, periodo, source), CarteiraContextService com setor shopee elegível (financial_source='shopee')"
provides:
  - "PortfolioController roteando por fonte financeira ('adman'|'shopee') nas 4 telas de carteira: transparencia(), renderCarteiraProfissional(), renderCarteirasConsolidadas(), renderPortfolio() (self-view /portfolio)"
  - "fontesFinanceirasPorEmpresa() — resolve a fonte VENCEDORA por empresa (desempate travado: adman vence sobre shopee), reutilizada pelas 4 funções"
  - "companyIdsComDadosShopee() — distingue revenue Shopee genuinamente zero de empresa que nunca sincronizou (null vs 0.0)"
  - "vinculosParaContadoresDisplay() — preserva a semântica v17 de financial_metrics_eligible pro chip 'sem fonte financeira' (Shopee sempre conta como sem-fonte neste contador, mesmo sendo a fonte real dos números)"
affects: [109-03-desempenho, 109-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dispatch por fonte financeira: resolver o vencedor por empresa (groupBy company_id + regra 'adman vence') ANTES do map() de renderização, não dentro dele — evita resolver a regra de desempate 2x por linha"
    - "Campo de display 'financial_metrics_eligible'/'elegivel' em servicos[]/contadores() é DIFERENTE do financial_source bruto do vínculo — Shopee sempre aparece como false nesse campo específico (preserva o vocabulário v17 'tem margem', não 'tem qualquer fonte'), mesmo sendo a fonte financeira real que alimenta faturamento/investimento"
    - "Distinção null-vs-zero para agregados por-empresa (nullable): existence-check batched (whereIn + distinct) ANTES do SUM, não depois — SUM sem linhas retorna 0.0, não null, então não dá pra inferir 'sem dado' do resultado do SUM sozinho"

key-files:
  created:
    - tests/Feature/PortfolioShopeeCarteiraTest.php
    - .planning/phases/109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o/deferred-items.md
  modified:
    - app/Http/Controllers/PortfolioController.php
    - resources/js/Pages/Portfolio/AdminCarteira.jsx
    - resources/js/Pages/Portfolio/Transparencia.jsx

key-decisions:
  - "financial_metrics_eligible em servicos[]/'elegivel' em transparencia SEMPRE false para setor='shopee' (não depende de desempate) — decisão tomada durante a task para bater com os testes travados (CarteiraIndividualContextoTest/CarteiraPeriodoDiffTest), que preservam o vocabulário v17 desse campo específico ('tem fonte COM margem'), diferente do financial_source bruto do CarteiraContextService (que despois do Plano 01 é true pra Shopee)"
  - "renderCarteirasConsolidadas()/renderCarteiraProfissional() dividem $companyIdsElegiveis (todas, usado pro gate de visibilidade/dedup) em $companyIdsAdman/$companyIdsShopee (por fonte vencedora) — só o subconjunto 'adman' alimenta as queries AdmanMetric/cache Adman; o subconjunto 'shopee' alimenta uma leitura SEPARADA de shopee_metrics, somados no total"
  - "renderPortfolio() (self-view) ESPELHA o tratamento das outras 3 telas mas preserva 100% o bug legado de $user->companies() (decisão travada do usuário no CONTEXT.md) — só o bloco financeiro por-empresa muda; empresas fora de qualquer vínculo CarteiraContextService continuam no caminho Adman/SUM antigo, sem alteração de comportamento"
  - "Carteiras.jsx e Show.jsx NÃO precisaram de mudança de JSX — já renderizavam null/badge de fonte genericamente (fmtBRL/formatCurrencyCompact com fallback '—', FonteBadge já tinha entrada 'shopee'); só AdminCarteira.jsx/Transparencia.jsx receberam tooltip + texto de legenda corrigido"

requirements-completed: [SHOP-CAR-01, SHOP-CAR-02]

duration: ~110min
completed: 2026-07-23
---

# Phase 109 Plan 02: Fonte Shopee nas 4 Telas de Carteira Summary

**PortfolioController passa a rotear transparencia/carteira-individual/carteira-consolidada/self-view por fonte financeira ('adman'|'shopee') via MetricDiffDispatcher, com regra de desempate travada (adman vence) e distinção null-vs-zero para empresas Shopee sem dado sincronizado.**

## Performance

- **Duration:** ~110 min
- **Tasks:** 3/3 completos
- **Files modified:** 6 (2 criados, 1 doc de deferred-items, 3 modificados)

## Accomplishments

- **Transparência (`transparencia()`)** e **carteira individual (`renderCarteiraProfissional()`)** roteiam por `MetricDiffDispatcher::compute($c, $periodo, $source)`, `$source` resolvido por `fontesFinanceirasPorEmpresa()` (desempate: `adman` vence quando a MESMA empresa tem vínculo performance E shopee elegíveis do profissional). Empresa Shopee mostra faturamento/investimento reais quando há `shopee_metrics`, `null` (não `0.0`) quando a empresa nunca sincronizou — status próprio (`sem_dados`/`sem_baseline`/`completo`), nunca `sem_fonte` (isso é reservado a vínculo não elegível).
- **Carteira consolidada admin (`renderCarteirasConsolidadas()`)**: cada card de profissional soma `AdmanMetric` (empresas fonte `adman`) + `SUM(shopee_metrics.revenue/ad_expense)` (empresas fonte `shopee`) no mesmo total — nunca duplica quando o profissional tem os 2 vínculos na mesma empresa. `source_counts` (ADR DATA-04) e a query de cache Adman ficaram restritas às empresas fonte `adman` (não mais chamando `Company::whereIn` com IDs Shopee à toa).
- **Self-view `/portfolio` (`renderPortfolio()` → `Portfolio/Show.jsx`)**: bloco financeiro por-empresa espelha o mesmo tratamento (faturamento+investimento Shopee, margem sempre `null`), preservando 100% o bug legado de `$user->companies()` conforme decisão travada do usuário — nenhuma feature rica (sugadores/PPA/NPS/meta/comparação contextual) tocada.
- **Regressão dos 10 testes Feature deixados vermelhos pelo Plano 01** (109-01-SUMMARY.md "Regressão Conhecida") — todos voltam a passar: `CarteiraFinanceiroElegibilidadeTest` (5), `CarteiraIndividualContextoTest` (4, incluindo o alvo `entrada_shopee_nao_elegivel_performance_elegivel`), `CarteiraPeriodoDiffTest` (1, `empresa_shopee_sem_fonte_nao_aciona_diff_service`), `CarteirasConsolidadasContextoTest` (5, incluindo `source_counts_nao_conta_empresa_shopee_only` e `resumo_individual_expoe_contadores_de_vinculos`).
- **Novo `tests/Feature/PortfolioShopeeCarteiraTest.php`** (9 testes): faturamento/investimento reais em transparência e carteira individual, distinção null-vs-zero, desempate com dado real dos dois lados, carteira consolidada em-curso e bônus-atual, self-view com dado e sem dado, features ricas presentes no payload.
- **UI**: `AdminCarteira.jsx`/`Transparencia.jsx` já renderizavam `"—"` para margem `null` e badge "Shopee" via `FonteBadge` (data-driven, sem mudança estrutural) — ajuste de tooltip pt-BR ("Shopee ainda não fornece margem") + correção de texto de legenda que dizia (incorretamente, pós-Fase 109) que empresa só-Shopee ficava "sem fonte financeira". `Carteiras.jsx`/`Show.jsx` não precisaram de mudança de JSX (já tolerantes a `null`).

## Task Commits

1. **Task 1+2 (backend, combinados — ver Deviations)**: dispatch por fonte nas 4 telas + helpers compartilhados + regra de desempate + null-vs-zero + novo teste. `d7833254` (feat)
2. **Task 3 (UI + build)**: tooltips de margem Shopee + correção de legenda em `AdminCarteira.jsx`/`Transparencia.jsx`; `npm run build` validado. `7215810b` (feat)

## Files Created/Modified

- `app/Http/Controllers/PortfolioController.php` — dispatch por fonte nas 4 funções + 3 helpers privados novos (`fontesFinanceirasPorEmpresa`, `companyIdsComDadosShopee`, `vinculosParaContadoresDisplay`).
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` — tooltip de margem Shopee + subtítulo de fonte.
- `resources/js/Pages/Portfolio/Transparencia.jsx` — tooltip de margem Shopee + legenda corrigida.
- `tests/Feature/PortfolioShopeeCarteiraTest.php` — 9 testes novos cobrindo as 4 telas.
- `.planning/phases/109-.../deferred-items.md` — 2 falhas Phase61 pré-existentes (não causadas por este plano), confirmadas via `git worktree` read-only no HEAD anterior.

## Decisions Made

- **`financial_metrics_eligible`/`elegivel` em `servicos[]` sempre `false` para setor Shopee** (não depende do desempate) — descoberto durante a execução ao rodar `CarteiraPeriodoDiffTest::test_empresa_shopee_sem_fonte_nao_aciona_diff_service` (vínculo Shopee ÚNICO, sem concorrência de performance, e o teste ainda espera `false`). Esse campo preserva o vocabulário v17 ("tem fonte financeira COM margem"), que é DIFERENTE do `financial_source` bruto do vínculo (que a Fase 109-01 tornou `true`/`'shopee'`). Aplicado nas 4 funções via checagem direta de setor, não via comparação com a fonte vencedora.
- **`vinculosParaContadoresDisplay()`** — descoberto ao rodar a suíte ampla: `CarteiraContextService::contadores()` (Plano 01, intocado) conta `vinculos_sem_fonte_financeira` usando o flag BRUTO (agora `true` pra Shopee), quebrando `resumo_individual_expoe_contadores_de_vinculos` e o chip amber "N vínculo(s) sem fonte financeira" em ambas as telas de carteira. Fix local (não mexe no service): o controller passa uma cópia dos vínculos com Shopee forçado a `false` antes de chamar `contadores()`.
- **Null-vs-zero para Shopee sem dado sincronizado** — `ShopeeMetricDiffService` (Plano 01) trata "dia sem linha = zero real" por desenho (revenue), então `SUM()` sem NENHUMA linha histórica também devolve `0.0`, não `null`. Como os testes (`CarteiraFinanceiroElegibilidadeTest`) esperam `null` quando a empresa nunca sincronizou Shopee, adicionado `companyIdsComDadosShopee()` — existence-check batched que os 4 consumidores usam pra decidir entre "mostrar o SUM real" e "mostrar null (sem_dados)".

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Regressão do Plano 01] `financial_metrics_eligible` em `servicos[]`/contadores() precisou de tratamento de display separado do `financial_source` bruto**
- **Found during:** Task 1 (verificação dos 10 testes-alvo)
- **Issue:** o Plano 01 tornou `financial_metrics_eligible=true` pro vínculo Shopee bruto (decisão correta pra elegibilidade financeira), mas os testes Feature pré-existentes (Fase 89/90/103) codificam um vocabulário DIFERENTE pra esse campo especificamente em `servicos[]`/`contadores()`: "vínculo com fonte financeira COMPLETA (com margem)". Sem tratamento, o payload quebrava 10 asserções.
- **Fix:** `servicos[].financial_metrics_eligible`/`elegivel` força `false` pra setor Shopee (sempre, não condicional a desempate); `contadores()` recebe vínculos com o mesmo ajuste via `vinculosParaContadoresDisplay()`. O financeiro REAL (faturamento/investimento) usa o `financial_source` bruto via `fontesFinanceirasPorEmpresa()` — os dois campos coexistem com significados diferentes, documentado em comentário no código.
- **Files modified:** `app/Http/Controllers/PortfolioController.php`
- **Verification:** os 10 testes-alvo (`CarteiraFinanceiroElegibilidadeTest`, `CarteiraIndividualContextoTest`, `CarteiraPeriodoDiffTest`, `CarteirasConsolidadasContextoTest`) — 19/19 verdes.
- **Committed in:** `d7833254`

**2. [Rule 1 - Bug de dado] `SUM(shopee_metrics.revenue)` sem linhas retorna `0.0`, não `null` — precisa de existence-check separado**
- **Found during:** Task 1, ao rodar `test_shopee_only_analista_nao_herda_financeiro_ml` (esperava `faturamento=null`, recebia `0.0`)
- **Issue:** `ShopeeMetricDiffService::calcularRevenue()` (Plano 01, por desenho) trata dia sem linha como zero real — correto pra soma de período COM histórico, mas indistinguível de "empresa nunca sincronizou Shopee" quando NENHUMA linha existe.
- **Fix:** `companyIdsComDadosShopee()` — batched `ShopeeMetric::whereIn(...)->distinct()->pluck('company_id')` chamado ANTES do dispatcher; os 4 consumidores retornam `null` explícito quando a empresa não está nesse conjunto, só chamam o dispatcher quando há dado histórico real.
- **Files modified:** `app/Http/Controllers/PortfolioController.php`
- **Verification:** `test_carteira_individual_vinculo_shopee_sem_dados_sincronizados_mostra_null_nao_zero` (novo) + `self_view_portfolio_vinculo_shopee_sem_dados_mostra_null` (novo).
- **Committed in:** `d7833254`

### Combinação de tasks no commit (decisão pragmática, não Rule 1-4)

**Tasks 1 e 2 foram commitadas JUNTAS** (`d7833254`), não em 2 commits separados como o plano sugeria. Motivo: os 3 helpers compartilhados (`fontesFinanceirasPorEmpresa`, `companyIdsComDadosShopee`, `vinculosParaContadoresDisplay`) foram escritos incrementalmente no MESMO bloco de código do arquivo, consumidos por TODAS as 4 funções (Task 1 e Task 2), e a correção do item 1 acima (`vinculosParaContadoresDisplay`) só foi descoberta necessária ao verificar a regressão de `renderCarteirasConsolidadas` (Task 2), mas também se aplicava a `renderCarteiraProfissional` (Task 1). Separar os commits exigiria hunk-splitting manual de um diff intercalado (risco de quebrar um commit intermediário) — optei por 1 commit backend coeso + 1 commit UI (Task 3), documentado aqui em vez de forçar uma separação artificial e arriscada.

---

**Total deviations:** 2 auto-fixed (Rule 1, ambos descobertos durante a verificação dos testes-alvo do Plano 01) + 1 decisão pragmática de agrupamento de commit (documentada, não uma correção de bug).
**Impact on plan:** Nenhum scope creep — os 2 fixes são exatamente o trabalho que o 109-01-SUMMARY.md previu pro Plano 02 ("ensinando esses consumidores a rotear por financial_source... respeitando a regra de desempate"). A combinação de commit não afeta o conteúdo entregue, só a granularidade do histórico git.

## Known Gaps (documentados, fora do escopo literal desta task)

- **`renderPortfolio()` — `empresas_em_queda` (alertas) e `revenue_timeseries` (gráfico diário) NÃO incluem Shopee.** Esses dois widgets leem `AdmanMetric` diretamente (loops separados do bloco financeiro principal). O plano especificava literalmente "revenue/ad_spend" do bloco financeiro por-empresa — `top_3_revenue` já herda a correção automaticamente (usa `$companies`, já com Shopee); os outros 2 widgets ficariam fora do escopo mínimo do plano (evitar scope creep na função mais arriscada da fase, que já tem a restrição explícita "NÃO consertar o bug legado"). Candidato a follow-up se o usuário notar a lacuna.
- **Pré-existente, não causado por este plano:** 2 testes de `tests/Feature/Phase61/` (`PortfolioMultiFonteE2ETest`/`PortfolioSourceEnrichmentTest`) falham em `renderCarteirasConsolidadas()` por fixture desatualizada (`attachCarteira()` sem `servico_id` e sem contrato Performance ativo) — confirmado via `git worktree` read-only no commit `d5a52cd2` (antes de qualquer trabalho deste plano). Documentado em `deferred-items.md`.

## Issues Encountered

Nenhum além do documentado em Deviations/Known Gaps.

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- As 4 telas de carteira mostram números Shopee reais; `MetricDiffDispatcher`/`fontesFinanceirasPorEmpresa()` prontos para o Plano 03 (`DesempenhoScoreService`) aplicar a MESMA regra de desempate (texto idêntico já documentado no 109-02-PLAN.md).
- **Bloqueio conhecido (não deste plano):** os 8 testes de `DesempenhoScoreService` (V16/V18) continuam vermelhos — são do Plano 03, que decide COMO o score/ranking consome margem placeholder=1 pra Shopee (decisão de produto documentada no CONTEXT.md, não deve ser antecipada aqui).

---
*Phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o*
*Completed: 2026-07-23*

## Self-Check: PASSED

Todos os 6 arquivos declarados (criados/modificados) existem no disco; os 2 commits de task (`d7833254`, `7215810b`) existem no histórico git.
