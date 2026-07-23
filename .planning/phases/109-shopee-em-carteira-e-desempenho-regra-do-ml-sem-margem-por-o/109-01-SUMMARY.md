---
phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o
plan: 01
subsystem: api
tags: [shopee, metrics, carteira, desempenho, laravel, php-services]

# Dependency graph
requires:
  - phase: 88-fundacao-carteiracontextservice
    provides: "CarteiraContextService::forUser()/flagsFinanceirasPorSetor() como fonte única de vínculos por setor"
  - phase: 101-admanmetricdiffservice-v18-0
    provides: "Contrato AdmanMetricDiffService::compute() a espelhar (shape company_id/period/metrics/quality)"
provides:
  - "ShopeeMetricDiffService::compute(Company, periodo) — diff de revenue+investment lendo shopee_metrics, margem sempre null"
  - "MetricDiffDispatcher::compute(Company, periodo, source) — roteador adman/shopee por financial_source, whitelist com InvalidArgumentException"
  - "CarteiraContextService::flagsFinanceirasPorSetor() reconhece setor 'shopee' como financial_metrics_eligible=true, financial_source='shopee'"
affects: [109-02-carteira, 109-03-desempenho, 109-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Diff service local determinístico (sem HTTP) espelhando o shape de um service HTTP existente — ver ShopeeMetricDiffService vs AdmanMetricDiffService"
    - "whereDate() (não whereBetween cru) para filtrar colunas 'date' persistidas como datetime — evita comparação de string que exclui a borda superior"
    - "Dispatcher por fonte com match+whitelist explícita (InvalidArgumentException no default) para nunca cair num branch silencioso"

key-files:
  created:
    - app/Services/Metrics/ShopeeMetricDiffService.php
    - app/Services/Metrics/MetricDiffDispatcher.php
    - tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php
    - tests/Unit/Metrics/MetricDiffDispatcherTest.php
    - tests/Unit/Portfolio/CarteiraContextShopeeElegivelTest.php
  modified:
    - app/Services/Portfolio/CarteiraContextService.php
    - tests/Feature/V16/CarteiraContextServiceTest.php

key-decisions:
  - "Shopee vira financial_source elegível ('shopee') no CarteiraContextService — decisão travada 2026-07-23 pelo usuário, documentada no CONTEXT.md da fase"
  - "ShopeeMetricDiffService usa whereDate() em vez de whereBetween() puro, porque reference_date é persistido como datetime (Y-m-d 00:00:00) — bug descoberto durante TDD (ver Deviations)"
  - "Regra de desempate 'adman vence quando a mesma empresa tem os dois vínculos elegíveis' fica com os consumidores (Planos 02/03), não com CarteiraContextService (que resolve por vínculo/setor, não por empresa)"

patterns-established:
  - "Diff service Shopee: dias sem linha em shopee_metrics = zero real de venda (soma direto, sem guard de dias-comuns do Adman)"
  - "Campo null-aware fora do bloco metrics (investment) para distinguir 'sem dado' de 'zero real' sem contaminar o critério de quality.status"

requirements-completed: [SHOP-CAR-01]

duration: ~50min
completed: 2026-07-23
---

# Phase 109 Plan 01: Fundação Shopee em Carteira e Desempenho Summary

**ShopeeMetricDiffService (revenue+investment via shopee_metrics, margem sempre null) espelhando o contrato do AdmanMetricDiffService, mais MetricDiffDispatcher e abertura da elegibilidade financeira do setor Shopee no CarteiraContextService.**

## Performance

- **Duration:** ~50 min
- **Tasks:** 2/2 completos
- **Files modified:** 7 (5 criados, 2 modificados)

## Accomplishments

- `ShopeeMetricDiffService::compute(Company, $periodo)` devolve exatamente o shape do `AdmanMetricDiffService` (`company_id`/`period`/`metrics`/`quality`) + campo extra `investment`, 100% leitura local (sem HTTP).
- Margem (`contribution_margin_value`/`contribution_margin_pct`) sempre `null` — arquitetura *future-ready* pra quando a Shopee fornecer CMV.
- `investment` null-aware: distingue "sem dado de Ads" (fora do lookback de ~6 meses) de "R$ 0 investido de fato".
- `MetricDiffDispatcher::compute(Company, $periodo, $source)` roteia `'adman'`→`AdmanMetricDiffService`, `'shopee'`→`ShopeeMetricDiffService`, qualquer outra string lança `InvalidArgumentException` (T-109-02, whitelist).
- `CarteiraContextService::flagsFinanceirasPorSetor()` reconhece `Servico::SETOR_SHOPEE` como fonte financeira elegível (`financial_source='shopee'`, `financial_metrics_eligible=true`).

## Task Commits

Cada task foi commitada atomicamente (TDD na Task 1):

1. **Task 1 — RED:** teste `ShopeeMetricDiffServiceTest` (7 casos, todos falhando por classe inexistente) - `8120224` (test)
2. **Task 1 — GREEN:** `ShopeeMetricDiffService` implementado (após fix do bug de `whereBetween` vs `whereDate`, ver Deviations) - `175a631` (feat)
3. **Task 2:** `MetricDiffDispatcher` + branch `shopee` em `flagsFinanceirasPorSetor()` + testes novos + atualização de 2 asserções em `CarteiraContextServiceTest.php` - `8855c45` (feat)

## Files Created/Modified

- `app/Services/Metrics/ShopeeMetricDiffService.php` — diff de período Shopee (revenue+investment), margem sempre null, cache local por dia BRT.
- `app/Services/Metrics/MetricDiffDispatcher.php` — roteador `adman`/`shopee` por `financial_source`.
- `app/Services/Portfolio/CarteiraContextService.php` — branch `SETOR_SHOPEE` em `flagsFinanceirasPorSetor()`.
- `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` — 7 testes (soma+diff, dias ausentes=zero, margem null, investment null-aware, shape).
- `tests/Unit/Metrics/MetricDiffDispatcherTest.php` — 3 testes (routing adman/shopee, whitelist).
- `tests/Unit/Portfolio/CarteiraContextShopeeElegivelTest.php` — 3 testes (shopee elegível, performance regressão, setor desconhecido default intocado).
- `tests/Feature/V16/CarteiraContextServiceTest.php` — 2 asserções atualizadas (ver Deviations).

## Decisions Made

- **Fonte financeira Shopee elegível** — decisão travada pelo usuário em 2026-07-23 (ver `109-CONTEXT.md`); implementada exatamente como especificado no plano.
- **`whereDate()` em vez de `whereBetween()`** para filtrar `shopee_metrics.reference_date` — necessário porque a coluna `date` é persistida pelo Eloquent como `Y-m-d 00:00:00` (datetime), e `whereBetween` compara STRING contra a borda `Y-m-d`, excluindo o último dia da janela. Mesmo padrão já usado em `AdmanMetricDiffService::somasComGuards()` (`whereDate`), só não documentado explicitamente no research desta fase — descoberto durante o RED→GREEN do TDD.
- **`investment` como campo extra fora de `metrics`** (não dentro do bloco `metrics`) — o plano permitia as duas opções ("fora do bloco metrics OU como chave adicional"); optei por fora, mantendo `metrics` com EXATAMENTE as 3 chaves do contrato Adman (facilita a asserção de shape idêntico nos testes/consumidores).
- **Cache Shopee sempre TTL de 1 dia** (não a política tiered do Adman baseada em `missing`/`partial`/`complete`) — como o Shopee é leitura 100% local determinística (sem HTTP a "auto-curar"), e `quality.status` é estruturalmente sempre `'partial'` (margem null), aplicar a política tiered do Adman (que usa `'partial'` pra TTL curto de retry) cachearia por só 10 min sempre, sem ganho — decisão documentada no docblock da classe.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `whereBetween('reference_date', [...])` excluía o último dia da janela**
- **Found during:** Task 1, ciclo TDD RED→GREEN (`test_revenue_soma_e_diff_pct_corretos` e `test_dia_sem_linha_na_janela_conta_como_zero` falhavam mesmo após a implementação inicial)
- **Issue:** `ShopeeMetric.reference_date` é uma coluna `date` no schema, mas o Eloquent persiste o valor como `'Y-m-d 00:00:00'` (datetime). `whereBetween('reference_date', ['2026-07-01', '2026-07-03'])` compara como STRING: `'2026-07-03 00:00:00'` é lexicograficamente MAIOR que `'2026-07-03'` (prefixo + sufixo), então a borda superior era excluída — 1 dia de dados "sumia" silenciosamente de toda soma.
- **Fix:** Troquei para `whereDate('reference_date', '>=', $inicio)->whereDate('reference_date', '<=', $fim)` (extrai só a parte de data antes de comparar) — mesmo padrão já usado em `AdmanMetricDiffService::somasComGuards()`. Extraído pro helper privado `naJanela()`.
- **Files modified:** `app/Services/Metrics/ShopeeMetricDiffService.php`
- **Verification:** `test_revenue_soma_e_diff_pct_corretos` e `test_dia_sem_linha_na_janela_conta_como_zero` passam com o valor correto (antes: 300.0/100.0 incorretos por excluir o último dia; depois: 600.0/200.0 corretos).
- **Committed in:** `175a631` (Task 1 GREEN commit)

**2. [Rule 1 - Test expectation] `CarteiraContextServiceTest` (Feature/V16) tinha 2 asserções que codificavam Shopee=não-elegível**
- **Found during:** Task 2, ao rodar a suíte de regressão mais ampla (verificação explícita pedida pelo plano: "se algum teste unit de carteira quebrar aqui por elegibilidade Shopee, atualizar a expectativa neste plano")
- **Issue:** `test_cenario_2_so_shopee_retorna_vinculo_sem_fonte_financeira` e `test_contadores_dedup_empresas_unicas_vs_vinculos_de_servico` testavam DIRETAMENTE `CarteiraContextService::forUser()`/`contadores()` (sem HTTP/controller) esperando `financial_metrics_eligible=false` pro setor Shopee — comportamento intencionalmente mudado por esta fase.
- **Fix:** Atualizei as 2 asserções pra refletir a nova regra (`financial_source='shopee'`, `financial_metrics_eligible=true`; contadores do cenário "2 vínculos" passam de `financeiros=1/sem_fonte=1` pra `financeiros=2/sem_fonte=0`). Renomeei o 1º teste (`..._sem_fonte_financeira` → `..._com_fonte_financeira_shopee`) pra não mentir no nome.
- **Files modified:** `tests/Feature/V16/CarteiraContextServiceTest.php`
- **Verification:** `php artisan test tests/Feature/V16/CarteiraContextServiceTest.php` — 12/12 passam.
- **Committed in:** `8855c45` (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (1 bug real de query, 1 atualização de expectativa de teste intencionalmente prevista pelo plano)
**Impact on plan:** Ambos necessários para a correção do service e para refletir a decisão travada da fase. Sem scope creep — nenhum consumidor (`PortfolioController`/`DesempenhoScoreService`) foi tocado.

## Regressão Conhecida — Deferida aos Planos 02/03 (NÃO corrigida neste plano)

Abrir `financial_metrics_eligible=true` pro setor Shopee muda o comportamento OBSERVADO de consumidores que já leem essa flag diretamente (sem passar pelo dispatcher desta fase) — `PortfolioController` (soma `AdmanMetric` pra toda empresa `financial_metrics_eligible=true`, não filtra por `financial_source`) e `DesempenhoScoreService` (idem, no universo elegível). Isso é EXATAMENTE o cenário que o plano previu na seção `<verification>` ("nenhum consumidor é alterado neste plano — só a base que os dois herdam") e que os Planos 02/03 resolvem ensinando esses consumidores a rotear por `financial_source` via `MetricDiffDispatcher` (não mais só pela flag de elegibilidade).

Rodei a suíte de regressão ampla (`tests/Feature/V16 tests/Feature/V18 tests/Feature/Phase74 tests/Feature/Phase106 tests/Feature/Dashboard` — 276 testes) e **18 testes ficaram vermelhos**, todos e SOMENTE testes de consumidor (nenhum é o `CarteiraContextService`/`ShopeeMetricDiffService`/`MetricDiffDispatcher` criados/tocados por este plano):

**Carteira (`PortfolioController`) — Plano 02 resolve (10 testes):**
- `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` — 3 testes (`shopee_only_analista_nao_herda_financeiro_ml`, `shopee_only_estrategista_nao_herda_financeiro_ml`, `empresas_mistas_carteira_so_soma_elegiveis`)
- `tests/Feature/V16/CarteiraIndividualContextoTest.php` — 1 teste (`entrada_shopee_nao_elegivel_performance_elegivel`)
- `tests/Feature/V18/CarteiraPeriodoDiffTest.php` — 1 teste (`empresa_shopee_sem_fonte_nao_aciona_diff_service`)
- `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — 5 testes (`shopee_only_nao_herda_financeiro_ml_na_consolidada`, `filtro_contexto_shopee_esconde_card_sem_vinculo_shopee`, `source_counts_nao_conta_empresa_shopee_only`, `filtro_contexto_funciona_na_carteira_individual`, `resumo_individual_expoe_contadores_de_vinculos`)

**Desempenho (`DesempenhoScoreService`) — Plano 03 resolve (8 testes):**
- `tests/Feature/V16/ComparacaoContextualBlockedTest.php` — 2 testes
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php` — 3 testes
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` — 2 testes
- `tests/Feature/V16/PerformanceIndexMetadadosTest.php` — 1 teste

**Por que não corrigi aqui:** o plano 109-01 lista explicitamente `files_modified` sem `PortfolioController.php`/`DesempenhoScoreService.php`, e o objetivo do plano é textual: *"Nenhum consumidor (carteira/desempenho) é alterado neste plano — só a base que os dois herdam"*. Editar esses arquivos seria Rule 4 (mudança arquitetural fora do escopo declarado) e preemptaria o trabalho específico dos Planos 02/03 (que decidem COMO consumir o dispatcher — ex.: régua de margem placeholder=1 pro Desempenho, é uma decisão de produto documentada no CONTEXT.md que não deve ser antecipada aqui).

**Ação necessária:** os Planos 02 e 03 devem, como parte do próprio trabalho de "plugar" a fonte Shopee, também trocar as leituras diretas de `financial_metrics_eligible`/`AdmanMetric` nesses controllers/service por `MetricDiffDispatcher::compute(..., $vinculo['financial_source'])` (respeitando a regra de desempate "adman vence" quando a empresa tem os dois vínculos elegíveis) — isso deve fazer os 18 testes voltarem a passar (ou, quando a intenção do teste for genuinamente "Shopee não deve mais ficar sem fonte", a expectativa deve ser atualizada nesses planos, igual ao que fiz aqui pro `CarteiraContextServiceTest`).

## Issues Encountered

Nenhum além do documentado em Deviations.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ShopeeMetricDiffService` e `MetricDiffDispatcher` prontos para os Planos 02 (Carteira) e 03 (Desempenho) consumirem.
- `CarteiraContextService::forUser()` já devolve `financial_source='shopee'` pros vínculos Shopee — os Planos 02/03 podem ler essa chave direto pra decidir qual service chamar via dispatcher.
- **Bloqueio conhecido:** os 18 testes listados acima devem ser corrigidos (ou reescritos) como parte do trabalho dos Planos 02/03 — não é um blocker pra COMEÇAR esses planos (a base está pronta e testada), mas a suíte de regressão da fase só volta a ficar 100% verde quando os consumidores forem atualizados.

---
*Phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o*
*Completed: 2026-07-23*

## Self-Check: PASSED

Todos os 8 arquivos declarados (criados/modificados) existem no disco; os 3 commits de task (`8120224`, `175a6319`, `8855c458`) existem no histórico git.
