---
phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-
plan: 01
subsystem: api
tags: [laravel, desempenho, carteira, financial-source, resolver, cache, phpunit]

# Dependency graph
requires: []
provides:
  - "FinancialSourceResolver (app/Services/Metrics/) — fonte única do desempate de fonte financeira, critério Company::cust_id"
  - "3 call-sites (CompanyScoreService, DesempenhoScoreService, PortfolioController) delegando ao resolvedor único"
  - "Cache desempenho.compute em v20"
  - "Gate de hash da Fase 119 rotacionado e verde"
  - "Baseline numérica de falhas pré-existentes (136-BASELINE-TESTES.md)"
affects: ["136-02", "136-03", "desempenho-bonificacao"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Resolvedor único injetado por construtor em vez de regra duplicada por call-site (molde MetricDiffDispatcher)"
    - "Carga de Company adiantada para ANTES do desempate, reaproveitada no loop seguinte (zero query nova em 2 dos 3 call-sites)"

key-files:
  created:
    - app/Services/Metrics/FinancialSourceResolver.php
    - tests/Feature/Phase136/FinancialSourceResolverTest.php
    - .planning/phases/136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-/136-BASELINE-TESTES.md
    - .planning/phases/136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-/deferred-items.md
  modified:
    - app/Services/Desempenho/CompanyScoreService.php
    - app/Services/DesempenhoScoreService.php
    - app/Http/Controllers/PortfolioController.php
    - tests/Feature/DesempenhoShopeeScoreTest.php
    - tests/Feature/Phase116/NpsFloorDesempenhoTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
    - tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php
    - tests/Feature/Phase119/CompanyScoreServiceFonteTest.php
    - tests/Feature/Phase119/CompanyScoreServiceMargemTest.php
    - tests/Feature/Phase119/CompanyScoreServiceReconciliacaoTest.php
    - tests/Feature/Phase119/CompanyScoreServiceStatusTest.php
    - tests/Feature/PortfolioShopeeCarteiraTest.php
    - tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php
    - tests/Feature/V16/CarteirasConsolidadasContextoTest.php

key-decisions:
  - "Critério de 'tem conta Adman de fato' é Company::cust_id (accessor), nunca a coluna crua adman_account_id — travado em <interfaces> do plano"
  - "Uma única versão de cache (v20) para a fase inteira — Plano 03 não bumpa de novo"
  - "4 fixtures de teste que assumiam 'adman' vencendo incondicionalmente ganharam adman_account_id real (Rule 1), em vez de reescrever a asserção para o comportamento antigo"

patterns-established:
  - "FinancialSourceResolver: fonte única de regra de desempate, nunca consulta banco sozinho — recebe Collection de Company já carregada"

requirements-completed: ["D-10"]

# Metrics
duration: 43min
completed: 2026-08-11
---

# Phase 136 Plan 01: Correção do desempate de fonte financeira (D-10) Summary

**Extrai `FinancialSourceResolver` como fonte única do desempate Adman×Shopee (critério `Company::cust_id`), religa os 3 call-sites que duplicavam a regra byte-a-byte, bumpa `desempenho.compute` para v20 e rotaciona o gate de hash da Fase 119.**

## Performance

- **Duration:** 43 min (16:06 → 16:49 BRT, 2026-08-11)
- **Started:** 2026-08-11T19:06:13Z
- **Completed:** 2026-08-11T19:49:07Z
- **Tasks:** 3/3
- **Files modified:** 15 (4 criados, 11 modificados) + 4 arquivos adicionais de teste corrigidos por deviation (Rule 1)

## Accomplishments
- `FinancialSourceResolver` criado em `app/Services/Metrics/`, com o critério travado `Company::cust_id` (nunca a coluna crua `adman_account_id`) e cobertura de 8 testes (incluindo o caso NOVO — carteira mista sem `cust_id` — que a suíte da Fase 119 nunca cobriu, mais 1 teste de integração até o motor de nota)
- Os 3 call-sites duplicados (`CompanyScoreService::computeEmpresasScore()`, `DesempenhoScoreService::computeUniverso()`, `PortfolioController::fontesFinanceirasPorEmpresa()`) agora delegam ao mesmo resolvedor — zero cópia da regra sobrevive no codebase
- Cache `desempenho.compute` bumpado `v19 → v20` com changelog inline; os 4 arquivos de teste com a chave hardcoded acompanharam no mesmo commit
- Gate de hash da Fase 119 rotacionado nos 5 arquivos — suíte volta 100% verde (137 passed, 1060 assertions, bate com a referência do RESEARCH)
- Baseline de falhas pré-existentes registrada (9 failed / 18 passed, SHA `f71f8aa9`) e reconfirmada sem crescimento após as 3 tasks

## Task Commits

Each task was committed atomically:

1. **Task 1: Registrar baseline + criar FinancialSourceResolver com sua suíte** - `26fdc7e1` (test)
2. **Task 2: Ligar os 3 call-sites, bump v20, atualizar 4 testes com a chave hardcoded** - `51b7ac9e` (feat)
3. **Task 3: Rotacionar o gate de hash da Fase 119 + reconfirmar a baseline** - `29bd892e` (test)

_Nenhum commit de metadados adicional foi necessário além do final (`docs(136-01)`, feito na etapa de state_updates)._

## Files Created/Modified

- `app/Services/Metrics/FinancialSourceResolver.php` - resolvedor único do desempate; critério `Company::cust_id`
- `tests/Feature/Phase136/FinancialSourceResolverTest.php` - 8 testes (7 diretos ao resolvedor + 1 integração via `CompanyScoreService`)
- `.planning/phases/136-.../136-BASELINE-TESTES.md` - baseline numérica de falhas pré-existentes, congelada
- `.planning/phases/136-.../deferred-items.md` - registro dos 5 achados fora de escopo (pré-existentes)
- `app/Services/Desempenho/CompanyScoreService.php` - carga de `Company` adiantada para antes do desempate; delega ao resolvedor
- `app/Services/DesempenhoScoreService.php` - `computeUniverso()` delega ao resolvedor (zero query nova); `cacheKey()` em v20
- `app/Http/Controllers/PortfolioController.php` - `fontesFinanceirasPorEmpresa()` ganha a única query nova da correção; docblock de regra travada reescrito
- `tests/Feature/{DesempenhoShopeeScoreTest,Phase116/NpsFloorDesempenhoTest,Phase96/NpsInvalidacaoRespostaTest,V18/DesempenhoMetadadosCacheTest}.php` - chave `v19` → `v20`
- `tests/Feature/Phase119/CompanyScoreService{Dispatcher,Fonte,Margem,Reconciliacao,Status}Test.php` - constante do gate de hash rotacionada
- `tests/Feature/PortfolioShopeeCarteiraTest.php`, `tests/Feature/V16/{CarteiraFinanceiroElegibilidadeTest,CarteirasConsolidadasContextoTest}.php` - fixtures de carteira mista ganharam `adman_account_id` real (Rule 1)

## Decisions Made

- Critério de "tem conta Adman de fato" é o accessor `Company::cust_id`, nunca a coluna crua `adman_account_id` — travado em `<interfaces>` do plano e reforçado por teste dedicado (`ml_store_id`-only resolve `adman`)
- Terceiro ramo do `match` preserva o comportamento anterior quando não há fonte alternativa (vínculo só performance, sem `cust_id`, continua `adman`) — D-10 corrige o desempate entre fontes concorrentes, não inventa um quarto estado
- Uma única versão de cache (v20) cobre a fase inteira — o Plano 03 (que também mexe neste payload) não deve bumpar de novo, pois nada foi deployado entre os planos

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] 4 fixtures de teste com carteira mista assumiam 'adman' vencendo incondicionalmente**
- **Found during:** Task 2, ao rodar uma varredura mais ampla (`tests/Feature/V16/`, `tests/Feature/V18/`, `tests/Feature/Phase117/`, `PortfolioShopeeCarteiraTest`, `NpsFaltantesPorModeloTest`, `CarteiraContextShopeeElegivelTest`) para garantir que D-10 não regrediu nada fora das 5 suítes da baseline oficial
- **Issue:** `PortfolioShopeeCarteiraTest` (2 métodos) e `V16/{CarteiraFinanceiroElegibilidadeTest,CarteirasConsolidadasContextoTest}` (1 método cada) criavam empresa com vínculo performance+shopee via `criarCenarioMlComResponsaveis()` (fixture da trait `CriaCenarioResponsaveis`) sem nunca setar `adman_account_id`. Sob a regra corrigida, essas empresas passaram a resolver `'shopee'` em vez de `'adman'`, quebrando as asserções ("adman vence, nunca soma")
- **Fix:** Adicionado `$cenario['company']->update(['adman_account_id' => 'CUST-136-...'])` logo após montar o cenário, em cada um dos 4 métodos — preserva a intenção original do teste ("quando a empresa REALMENTE tem conta Adman, adman vence") sob o critério corrigido
- **Files modified:** `tests/Feature/PortfolioShopeeCarteiraTest.php`, `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php`, `tests/Feature/V16/CarteirasConsolidadasContextoTest.php`
- **Verification:** `--filter="PortfolioShopeeCarteiraTest"` 12/12 verdes; varredura ampla `tests/Feature/V16|V18|Phase117 + Portfolio.../Nps.../CarteiraContextShopee...` volta a 9 failed/277 passed (as 9 são exatamente as pré-existentes documentadas em `deferred-items.md`, nenhuma nova)
- **Committed in:** `51b7ac9e` (parte do commit da Task 2)

---

**Total deviations:** 1 auto-fixed (Rule 1, 4 arquivos de teste)
**Impact on plan:** Necessário para que a correção de D-10 não regredisse cobertura existente fora do radar das 5 suítes explicitamente listadas em `<files_modified>` do plano. Nenhum scope creep — o fix restaura exatamente o comportamento que os testes já verificavam, só com um fixture que reflete um cenário real (empresa com conta Adman de fato).

## Issues Encountered

Durante a investigação da varredura ampla da Task 2, 5 falhas adicionais apareceram fora das 5 suítes da baseline oficial. Investigadas com evidência direta (dump do mapa de desempate resolvido, mostrando resultado byte-idêntico ao da regra antiga nos cenários afetados) e confirmadas como **pré-existentes, não causadas por esta fase**:

- 3 em `tests/Feature/V16/{DesempenhoElegibilidadeTest×2,PerformanceIndexMetadadosTest×1}` — mesma causa raiz dos 9 failures da baseline oficial: o hotfix de 2026-07-24 revogou a prioridade do `calculated_fallback` local em `AdmanMetricDiffService::resolveMargemPct()`, e fixtures que criam `AdmanMetric` direto no banco (sem `.diff` nativo via HTTP fake) deixam de produzir `contribution_margin_pct.diff_pct`
- 2 em `tests/Feature/Phase61/{PortfolioMultiFonteE2ETest×2,PortfolioSourceEnrichmentTest×1}` — causa diferente: a fixture usa `$user->companies()->attach()` puro, sem `servico_id` nem `ContratoServico` ativo; `CarteiraContextService::forUser()` (arquivo não tocado por esta fase) já devolve vazio para esse padrão, ANTES de qualquer código que a Fase 136 modificou rodar

Nenhuma delas foi corrigida — fora do escopo da Task 1 do plano (nenhuma relação com o critério `cust_id`). Registradas em `deferred-items.md` para não confundir dívida antiga com regressão desta fase, seguindo a mesma disciplina do `136-BASELINE-TESTES.md`.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `FinancialSourceResolver` pronto para ser consumido por qualquer código futuro que precise da regra de desempate — não duplicar
- Cache em v20; o Plano 03 (que também muda o cálculo neste payload) deve reaproveitar v20, não bumpar de novo
- **Pendência explícita do plano** (não deste plano — registrada para o Plano 06): reconciliar `adman_account_id`/`ml_store_id` das ~20 empresas candidatas contra produção antes de considerar D-10 encerrado — o banco local já mentiu sobre o `cust_id` de pelo menos uma empresa conhecida (Utilarshop)
- 5 falhas pré-existentes fora do escopo desta fase documentadas em `deferred-items.md` — não são bloqueio para os próximos planos da Fase 136

---
*Phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-*
*Completed: 2026-08-11*

## Self-Check: PASSED

- Todos os 8 arquivos-chave (criados/modificados) verificados no disco — FOUND
- Todos os 4 commits (26fdc7e1, 51b7ac9e, 29bd892e, 6f5d1307) verificados em `git log --oneline --all` — FOUND
