---
phase: 102-desempenho-oficial-por-competencia-v18-0
plan: 01
subsystem: api
tags: [desempenho, bonus, adman, metric-period-resolver, tdd, http-isolation]

# Dependency graph
requires:
  - phase: 100-metricperiodresolver-fundacao-de-periodo-v18-0
    provides: "MetricPeriodResolver::resolve() — resolvedor único de janelas current/baseline"
  - phase: 101-admanmetricdiffservice-v18-0
    provides: "AdmanMetricDiffService::compute() — diff de período pronto da Adman com gate + fallback guardado"
provides:
  - "DesempenhoScoreService::computeOficial(User) — modo oficial de bônus (último mês fechado)"
  - "compute(User, Carbon, ?array $periodoOverride) — assinatura compatível com ~40 call-sites"
  - "computeVarFaturamento/computeVarMargem consumindo janelas do MetricPeriodResolver"
  - "computeVarMargem delegando a AdmanMetricDiffService — ~140 linhas de guards duplicados removidas"
affects: [102-02, 103-carteira-integracao-periodo, 104-ui-periodo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Http::preventStrayRequests() + Http::fake() determinístico em todo teste que alcança compute() com empresa custId'd"
    - "Fixture DENSA (1 row AdmanMetric/dia) para testes de margem em mês fechado — evita interseção vazia de dias-comuns quando a baseline não começa no dia 1"

key-files:
  created:
    - tests/Feature/V18/DesempenhoPeriodoOficialTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
    - tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php
    - tests/Feature/V16/ComparacaoContextualBlockedTest.php
    - tests/Feature/V16/DesempenhoElegibilidadeTest.php
    - tests/Feature/V16/PerformanceIndexMetadadosTest.php
    - tests/Feature/DesempenhoScoreSnapshotTest.php
    - tests/Feature/DesempenhoEvolucaoTest.php

key-decisions:
  - "Assinatura Opção B travada: compute(User, Carbon, ?array $periodoOverride=null) preserva 100% dos call-sites existentes; computeOficial(User) é o caminho explícito para o bônus oficial"
  - "Âncora Carlos RECALIBRADA (não preservada): nota_final muda de 4.08 para 4.42 — baseline de mês fechado deixa de ser calendário e vira janela-de-mesmo-tamanho, e var_margem_pct muda de definição (percentageMargin da Adman, não mais R$ absoluto)"
  - "var_faturamento_pct NÃO foi migrado para AdmanMetricDiffService (decisão do research, fora de escopo BON-03) — continua ML-first + fallback Adman próprio, só as janelas mudaram de fonte"

patterns-established:
  - "Fixtures de teste de margem em mês fechado precisam ser DENSAS (1 row/dia) cobrindo current e baseline REAIS resolvidos via MetricPeriodResolver — nunca mais '1 linha no dia 15'"
  - "Toda Company usada em teste que precisa de var_margem_pct não-nulo precisa de adman_account_id explícito — custId vazio = AdmanMetricDiffService retorna emptyMetrics() sem HTTP"

requirements-completed: [BON-01, BON-02, BON-03]

duration: ~3h
completed: 2026-07-20
---

# Phase 102 Plan 01: DesempenhoScoreService por competência oficial (v18.0) Summary

**`DesempenhoScoreService` migrado para `MetricPeriodResolver` (janelas) + `AdmanMetricDiffService` (margem) — `computeOficial()` paga o mês fechado anterior, âncora Carlos recalibrada de 4.08 para 4.42 sob a régua nova.**

## Performance

- **Duration:** ~3h
- **Tasks:** 2 (TDD RED→GREEN confirmados)
- **Files modified:** 8 (1 produção + 7 teste)
- **Files created:** 1 (suíte V18 nova)

## Accomplishments

- `computeOficial(User)` resolve `last_closed_month` via `MetricPeriodResolver` — em julho/2026, paga a competência junho/2026 (janela 01/06..30/06, baseline 02/05..31/05) — BON-02.
- `computeVarFaturamento`/`computeVarMargem` deixam de calcular janelas inline (`now()`/`startOfMonth()`/`subMonth()`) — consomem `$periodo['current_start'/'current_end'/'baseline_start'/'baseline_end']` — BON-01.
- `computeVarMargem` delega por empresa a `AdmanMetricDiffService::compute()`, removendo ~140 linhas de guards duplicados (janelas inline + `margem_dias` + interseção de dias-comuns) — fonte única passa a ser o diff service — BON-03.
- Golden number da âncora Carlos recalibrado (4.08 → 4.42) com aritmética explícita documentada no teste — NÃO preservado por atalho.
- Isolamento HTTP total: `Http::preventStrayRequests()` + `Http::fake()` determinístico em todos os arquivos do edit-set — nenhuma chamada real à Adman durante os testes.

## Task Commits

1. **Task 1: Resolver + modo oficial + faturamento por janela + margem via diff service** - `5ce65b2` (feat)
2. **Task 2: Recalibrar âncora Carlos + suíte Phase74 (fixtures densos, golden derivado da régua)** - `c270a71` (test)
3. **Deviation fix: 3 regressões reais fora do edit-set declarado** - `2055161` (fix)

_TDD confirmado: Task 1 rodou RED via `git stash` do serviço (3 erros de método inexistente + 1 falha de gate), depois GREEN com o serviço restaurado (6/6 verde)._

## Files Created/Modified

- `app/Services/DesempenhoScoreService.php` — `computeOficial()` novo, `resolvePeriodo()` privado, `computeVarFaturamento`/`computeVarMargem` recebem `$periodo`, ~140 linhas de guards duplicados removidas de `computeVarMargem`.
- `tests/Feature/V18/DesempenhoPeriodoOficialTest.php` — suíte nova (6 testes): competência oficial, janelas exatas, gate `adman_diff` vs `calculated_fallback`, var_faturamento por janela.
- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — âncora Carlos recalibrada + 4 testes com fixture densa + fix da chave dinâmica do stub ML (baseline pode cruzar mês anterior).
- `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` — isolamento HTTP (sem mudança de fixture — não asserta margem numérica).
- `tests/Feature/V16/ComparacaoContextualBlockedTest.php` — isolamento HTTP (oráculo self-referente, sem mudança de fixture).
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php` — isolamento HTTP + 1 teste recalibrado (`test_misto_e_official_com_financeiro_so_do_vinculo_elegivel`, custId + margem pct-based).
- `tests/Feature/V16/PerformanceIndexMetadadosTest.php` — **fora do edit-set declarado** — custId + isolamento HTTP (regressão real de `score_status`).
- `tests/Feature/DesempenhoScoreSnapshotTest.php` / `tests/Feature/DesempenhoEvolucaoTest.php` — **fora do edit-set declarado** — assinatura do override `compute()` da classe anônima corrigida (LSP fatal error).

## Decisions Made

- **Assinatura Opção B travada** (recomendação do research): `compute(User, Carbon, ?array $periodoOverride=null)` em vez de trocar tudo para `array $periodo`. Preserva 100% dos ~40 call-sites existentes; `computeOficial()` é o caminho explícito para "o número oficial de bônus".
- **var_faturamento_pct NÃO migrado para AdmanMetricDiffService** — decisão já tomada no research (BON-03 fala só de margem); ML-first + fallback Adman próprio de `computeVarFaturamento` continua intocado na lógica, só as janelas de comparação mudaram de fonte.
- **Âncora Carlos: NÚMERO NOVO, não preservado.** nota_final vai de 4.08 (v17) para 4.42 (v18) — a baseline de mês fechado deixa de ser calendário (01/06..30/06) e vira janela-de-mesmo-tamanho (31/05..30/06); `var_margem_pct` muda de definição (variação do `percentageMargin` da Adman via `AdmanMetricDiffService`, não mais variação de margem R$ absoluta calculada manualmente). Aritmética completa no docblock de `criarCarlosCompleto()`.
- **Matemática de faturamento operacional (mês em curso) permanece equivalente à v17** — o ramo `current_month` do `MetricPeriodResolver` é matematicamente idêntico ao cálculo inline antigo (mesmo alinhamento por dia, mesmo clamp) — troca 1:1, sem mudança de número.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug real] Stub ML de `var_faturamento_pct` respondia por chave `Y-m` FIXA que não bate mais com a baseline shiftada**
- **Found during:** Task 2 (`test_provider_ml_first_com_adman_fallback`)
- **Issue:** `computeVarFaturamento` chama `readForCompany()` para AMBAS as janelas (atual e baseline). Sob a régua nova, `baseline_start` de mês fechado pode cruzar pro mês calendário anterior (ex.: julho/2026 → `baseline_start=2026-05-31`, chave `'2026-05'`, não mais `'2026-06'`). O teste configurava o stub com a chave antiga (`'2026-06'`) — o lookup falhava (null), o ML era descartado inteiro, e o fallback Adman produzia -44,44% em vez de +11,11%.
- **Fix:** Resolve a chave dinamicamente via `MetricPeriodResolver::resolve(['period_key'=>'2026-07'])['baseline_start']` no teste, em vez de hardcode.
- **Files modified:** `tests/Feature/Phase74/DesempenhoScoreServiceTest.php`
- **Verification:** `var_faturamento_pct` volta a bater em +11,115% (assert inalterado).
- **Committed in:** `c270a71`

**2. [Rule 3 - Blocking] PHP fatal error de LSP em 2 classes anônimas fora do edit-set**
- **Found during:** varredura de regressão ampla (não fazia parte do `<verification>` do plano, mas foi descoberta ao rodar `tests/Feature/DesempenhoScoreSnapshotTest.php`/`DesempenhoEvolucaoTest.php`)
- **Issue:** `compute()` do parent ganhou `?array $periodoOverride = null`. Duas classes anônimas (`extends DesempenhoScoreService`) sobrescreviam `compute(User, Carbon): array` com a assinatura ANTIGA — PHP recusa em tempo de boot por incompatibilidade LSP (fatal error, não falha de teste).
- **Fix:** Adicionado `?array $periodoOverride = null` na assinatura do override nas 2 classes anônimas.
- **Files modified:** `tests/Feature/DesempenhoScoreSnapshotTest.php`, `tests/Feature/DesempenhoEvolucaoTest.php`
- **Verification:** `phpunit tests/Feature/DesempenhoScoreSnapshotTest.php tests/Feature/DesempenhoEvolucaoTest.php` → 19/19 verde.
- **Committed in:** `2055161`

**3. [Rule 1 - Bug real] `score_status` regrediu de `official` pra `partial` em teste fora do edit-set**
- **Found during:** varredura de regressão ampla (`tests/Feature/V16/PerformanceIndexMetadadosTest.php`)
- **Issue:** `computeVarMargem` agora delega a `AdmanMetricDiffService`, que retorna `emptyMetrics()` (sem HTTP) quando a empresa não tem `adman_account_id`. A empresa deste teste não tinha custId — `var_margem_pct` virou sempre `null`, e `computeScoreStatus` classifica como `partial` quando qualquer componente financeiro é `null` (mesmo com faturamento presente).
- **Fix:** Adiciona `adman_account_id` à empresa + `Http::preventStrayRequests()`/`Http::fake()` no `setUp()`. Não altera a asserção do teste (que só checa `'official'`, não o valor exato de `var_margem_pct`).
- **Files modified:** `tests/Feature/V16/PerformanceIndexMetadadosTest.php`
- **Verification:** `phpunit tests/Feature/V16/PerformanceIndexMetadadosTest.php` → 4/4 verde.
- **Committed in:** `2055161`

---

**Total deviations:** 3 auto-fixed (2 Rule 1 - bug real, 1 Rule 3 - blocking). Todas as 3 fora do edit-set declarado no `files_modified` do plano, mas causadas DIRETAMENTE pela mudança de assinatura/comportamento de `DesempenhoScoreService::compute()` — corrigidas por precisão (não descartadas nem ignoradas), conforme instrução do usuário ("É A FASE QUE MUDA O NÚMERO DO BÔNUS — precisão acima de tudo").
**Impact on plan:** Nenhum scope creep de produção — os 3 fixes são só em arquivos de TESTE (fixtures/isolamento HTTP/assinatura de override), zero mudança em `PerformanceController.php`, `CarteiraContextService`, `AdmanMetricDiffService` ou `MetricPeriodResolver` (fronteira respeitada).

## Issues Encountered

- **Descoberta durante análise (não bug, achado do research confirmado empiricamente):** com companies sem `adman_account_id`/`ml_store_id`, `AdmanMetricDiffService::compute()` retorna `emptyMetrics()` IMEDIATAMENTE (sem tentar HTTP nem ler `AdmanMetric` local). Isso significa que QUALQUER teste que precise de `var_margem_pct` não-nulo precisa fabricar `adman_account_id` explícito na empresa — documentado como padrão estabelecido acima.
- **Coincidência matemática investigada (não é bug):** o teste `test_var_margem_nao_inverte_sinal_quando_janela_atual_tem_dias_finais_sem_margem` (mês EM CURSO, não fechado) manteve a asserção `+50,00%` inalterada mesmo com a mudança de definição de `var_margem_pct` — verificado algebricamente que isso acontece porque o `revenue` é IDÊNTICO (1000/dia) nas duas janelas do fixture, fazendo a razão `percentageMargin` coincidir com a razão de margem R$ absoluta. Documentado no comentário do teste para não confundir o próximo dev.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- **102-02** pode prosseguir: bump de cache `v4→v5` (incluindo `period_key`) e o toque no `NpsController` ficam explicitamente FORA deste plano (conforme fronteira declarada) — `computeCached()` não foi tocado, a chave de cache permanece `v4` sem incluir `period_key` (débito conhecido, a ser resolvido em 102-02).
- **Snapshot de junho/2026 já consolidado sob a régua ANTIGA** (decisão de reprocessar ou não é de negócio, fora do escopo técnico deste plano — ver 102-RESEARCH.md "Snapshots de mês fechado"). Se a diretoria decidir reprocessar, o comando é `php artisan desempenho:consolidar-mes --mes=2026-06` (idempotente).
- `PerformanceController`, `CarteiraContextService`, `NPS` continuam INTOCADOS — fronteira da fase respeitada integralmente.
- **Regressão confirmada limpa** em: `tests/Feature/Phase74` (32/32), `tests/Feature/V16/ComparacaoContextualBlockedTest` + `DesempenhoElegibilidadeTest` + `PerformanceIndexMetadadosTest` + `BonusDualPathRegressaoTest` + `BonusAtribuicoesNpsTest`, `tests/Feature/V18` (10/10), `tests/Feature/DesempenhoScoreSnapshotTest` + `DesempenhoEvolucaoTest`, `tests/Feature/Portfolio/RenderPortfolioTest`, `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest`.
- **Falhas pré-existentes CONFIRMADAS via baseline** (git checkout do arquivo antes da Fase 102, mesmas falhas idênticas): `PublicacaoDesempenhoRouteTest` (403≠200, permissão `mlb.dashboard`), `Unit/CalcularFaixaTest` (ArgumentCountError em `AdminController`, não relacionado a Desempenho), `Unit/CompanyServiceTypeTest` (SQLite CHECK enum). `tests/Feature/PerformanceCargoFilterTest` (5 falhas) também confirmado pré-existente via baseline nesta sessão — não fazia parte da lista conhecida do plano, mas checkado explicitamente.

---
*Phase: 102-desempenho-oficial-por-competencia-v18-0*
*Completed: 2026-07-20*
