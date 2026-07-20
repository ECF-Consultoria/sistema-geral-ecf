---
phase: 102-desempenho-oficial-por-competencia-v18-0
plan: 02
subsystem: api
tags: [desempenho, bonus, cache, nps, metric-period-resolver, tdd, http-isolation]

# Dependency graph
requires:
  - phase: 102-desempenho-oficial-por-competencia-v18-0 (plan 01)
    provides: "DesempenhoScoreService::computeOficial()/resolvePeriodo() — núcleo do cálculo por competência oficial"
provides:
  - "compute() expõe 'periodo' (janelas/mode/comparison_mode) e 'bonus' (competence_month/payment_month) no retorno — BON-04"
  - "DesempenhoScoreService::cacheKey(int $userId, Carbon $mes): string — helper público único de montagem da chave de cache"
  - "Cache v5 com period_key embutido (current_month vs YYYY-MM) — operacional e oficial do mesmo mês não colidem (T-102-04)"
  - "NpsController::bustarCacheDoBonus() invalida a chave certa via cacheKey() (sem hardcode de versão)"
affects: [103-carteira-integracao-periodo, 104-ui-periodo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Helper público único de chave de cache (cacheKey()) — nenhum consumidor monta a chave à mão; período operacional vs oficial nunca colidem"
    - "RED via revert temporário do arquivo (git checkout -- <file> + restauração da versão GREEN via backup em scratchpad), em vez de git stash — evita risco de stash cross-worktree"

key-files:
  created:
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - app/Http/Controllers/NpsController.php
    - tests/Feature/V16/BonusDualPathRegressaoTest.php
    - tests/Feature/V16/DesempenhoElegibilidadeTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php

key-decisions:
  - "Bump v4→v5 obrigatório: baseline de mês fechado (janela-de-mesmo-tamanho) e fonte de var_margem_pct (AdmanMetricDiffService) mudaram no 102-01 — sem o bump o Redis serviria o número velho por até 7 dias"
  - "Chave de cache passa a incluir period_key ('current_month' vs 'YYYY-MM') — sem isso, o modo operacional e o modo oficial do MESMO mês calendário colidiriam na mesma chave (T-102-04)"
  - "bonus.competence_month/payment_month são derivados de $mes (não do periodo['bonus_*'] do resolver) — ficam populados para QUALQUER mês fechado, não só o caminho computeOficial()/last_closed_month"
  - "Nos testes de regressão v16 (BonusDualPathRegressaoTest/DesempenhoElegibilidadeTest), o mês de referência da suíte É o mês corrente (Carbon::setTestNow no mesmo mês) — a chave v5 correta usa period_key='current_month', não 'YYYY-MM'; por isso o ajuste usou o helper cacheKey() em vez de find/replace literal do formato da chave"

patterns-established:
  - "TDD RED sem git stash: backup do arquivo GREEN em scratchpad + git checkout -- <file> pra voltar ao HEAD pré-mudança, roda os testes (RED), restaura o backup (GREEN) — evita o risco de colisão de refs/stash entre worktrees mesmo rodando fora de worktree"

requirements-completed: [BON-02, BON-04, BON-05]

duration: ~45min
completed: 2026-07-20
---

# Phase 102 Plan 02: Metadados de período/bônus + cache v5 com period_key Summary

**`compute()` passa a expor `periodo`/`bonus` no retorno, a chave de cache do bônus bumpa v4→v5 com `period_key` embutido via helper público único (`DesempenhoScoreService::cacheKey()`), e `NpsController::bustarCacheDoBonus` para de hardcodar o formato da chave.**

## Performance

- **Duration:** ~45min
- **Tasks:** 2 (TDD RED→GREEN confirmados)
- **Files modified:** 5 (2 produção + 4 teste, sendo 1 teste novo)

## Accomplishments

- `compute()` retorna `periodo` (current/baseline start/end + mode + comparison_mode, espelhando o `$periodo` já resolvido) e `bonus` (`competence_month`/`payment_month` derivados de `$mes`, nulls no mês em curso) — BON-04.
- `DesempenhoScoreService::cacheKey(int $userId, Carbon $mes): string` — helper público ÚNICO; `computeCached()` e `NpsController::bustarCacheDoBonus()` passam a usá-lo, eliminando os 2 pontos de hardcode do formato/versão da chave.
- Bump de cache v4→v5: `period_key` embutido (`current_month` no mês corrente, `YYYY-MM` no mês fechado) — operacional e oficial do MESMO mês calendário nunca mais colidem na mesma chave (T-102-04).
- `NpsController::bustarCacheDoBonus` (o "toque de 1 linha") commitado ATOMICAMENTE e IMEDIATAMENTE, isolado dos demais commits, com `git status` verificado antes (arquivo limpo, sem interferência da sessão paralela).
- Invariantes v17 (BON-05) provadas com dados reais: só-Shopee → `blocked`/`nota_final=null`; misto Performance+Shopee → score único (`official`/`partial`, nunca chave por marketplace); `empresas_unicas`/`vinculos_*` coerentes.
- NPS zero-diff provado com 2 cenários: trivial (sem respostas → 0.0) e com dados reais (2 atribuições, peso 4 e 2 → média 3.0) — a fórmula da média aritmética (v17) permanece intocada pela migração de período do 102-01.
- BON-02: `computeOficial(User)` em julho/2026 rotula `bonus.competence_month='2026-06'`/`payment_month='2026-07'` e `nota_final` bate exatamente com `compute($u, Carbon('2026-06-01'))` (mesmo número, dois caminhos).

## Task Commits

1. **Toque atômico NpsController (helper cacheKey)** - `6fdf1b6` (fix) — commitado ANTES do bump de versão em si, minimizando a janela de colisão com a sessão paralela de NPS.
2. **Task 1 RED — testes de metadados/cache v5** - `c845436` (test)
3. **Task 1 GREEN — periodo/bonus + cacheKey() + bump v5** - `88b50f0` (feat)
4. **Task 2 — invariantes v17 + NPS zero-diff + regressão v16 na chave v5** - `99dfbbb` (test)

_TDD confirmado: Task 1 rodou RED via backup do arquivo em scratchpad + `git checkout -- app/Services/DesempenhoScoreService.php` (reverte ao HEAD pré-mudança) — 6/9 testes falharam (Undefined array key 'periodo'/'bonus', Call to undefined method cacheKey()) — depois GREEN restaurando o backup (9/9 verde). Task 2 rodou RED nas suítes de regressão v16 já existentes (2 falhas reais na asserção de chave v4) antes do ajuste cirúrgico para v5._

## Files Created/Modified

- `app/Services/DesempenhoScoreService.php` — blocos `periodo`/`bonus` no retorno de `compute()`; `cacheKey()` público novo; `computeCached()` usa o helper e aceita `?array $periodoOverride`; docblock do shape e comentário de bump v1..v5 atualizados.
- `app/Http/Controllers/NpsController.php` — `bustarCacheDoBonus()` chama `DesempenhoScoreService::cacheKey()` em vez de `sprintf('desempenho.compute.v4...')` hardcoded.
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` — suíte nova (10 testes): shape periodo/bonus (mês fechado vs corrente), cache v5 via helper (grava/lê/não colide), invariantes BON-05 (blocked/official, score único), NPS zero-diff (trivial + dados reais), BON-02 (computeOficial == compute direto).
- `tests/Feature/V16/BonusDualPathRegressaoTest.php` — `test_cache_bumpado_para_v4` renomeado/ajustado para `test_cache_bumpado_para_v5`, usando `cacheKey()` para montar a chave esperada (mês de referência da suíte é o mês CORRENTE → `period_key='current_month'`, não `'YYYY-MM'`).
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php` — mesmo ajuste (`test_cache_bumpado_para_v5`).
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — 2 seeds de chave hardcoded trocados de `v4` para `v5` (mês fechado 2026-06 nesta suíte, `period_key='2026-06'` — literal Y-m mesmo).

## Decisions Made

- **`bonus` derivado de `$mes`, não do `$periodo` resolvido**: o `MetricPeriodResolver` só popula `bonus_competence_month`/`bonus_payment_month` no modo `last_closed_month`. Como o plano exige que QUALQUER mês fechado (inclusive `compute($u, $mesJunho)` sem override, usado por `desempenho:consolidar-mes`) tenha os metadados de competência, a derivação ficou em `compute()` a partir de `$mes`/`$ehMesEmCurso` (já calculados ali), não do array `$periodo`.
- **Helper público único `cacheKey()`** em vez de expor a lógica de `period_key` duas vezes (uma em `computeCached()`, outra em `NpsController`): qualquer consumidor futuro (ex.: um comando de warm-cache que precise invalidar antes de recalcular) usa o mesmo método.
- **Ajuste v4→v5 via helper, não find/replace literal**: descoberto durante a implementação que `BonusDualPathRegressaoTest`/`DesempenhoElegibilidadeTest` testam `computeCached()` com `$mes` == mês corrente da suíte (`Carbon::setTestNow` no mesmo mês) — sob a régua v5 isso usa `period_key='current_month'`, não `'YYYY-MM'`. Um find/replace ingênuo de `v4`→`v5` mantendo o sufixo `Y-m` teria produzido uma chave que `computeCached()` NUNCA escreve, quebrando o teste silenciosamente por um motivo diferente do pretendido. Usar `$service->cacheKey($id, $mes)` para montar a chave esperada elimina essa classe de erro.

## Deviations from Plan

None - plano executado exatamente como escrito. O único ponto de atenção (chave `current_month` vs `YYYY-MM` nos testes de regressão v16) já estava implicitamente coberto pela instrução do `<interfaces>` do plano ("chave é construída por um helper público único") — resolvido usando o próprio helper em vez de hardcode, sem precisar de decisão fora do escopo.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Validação Necessária Antes do Deploy / Comunicação

1. **Junho/2026 NÃO tem snapshot mensal sob a régua v18** — os 226 registros diários de `desempenho_score_snapshots` para junho têm `mes_referencia=NULL` (nunca foram consolidados mensalmente). Isso significa que, após o deploy, o `PerformanceController` cai no **COMPUTE LIVE** (`computeCached`) pra junho — nenhum número velho fica "preso" em snapshot, mas também nenhum congelamento formal existe ainda.
2. **Congelar junho/2026 é uma escolha de DEPLOY-TIME, fora do escopo de código desta fase**: `php artisan desempenho:consolidar-mes --mes=2026-06` (idempotente, já validado no 102-01 via `ConsolidarMesDesempenhoCommandTest` recalibrado). Rodar isso é decisão de negócio/timing, não uma tarefa de plano.
3. **Validação numérica pré-comunicação (recomendado, fora do escopo desta fase)**: antes de comunicar o número novo aos profissionais, comparar em prod o bônus **v17 (antigo) vs v18 (novo)** por profissional no modo oficial (`computeOficial()`), especificamente pra quem tem margem de contribuição alta variância — a mudança de baseline (calendário → janela-de-mesmo-tamanho) e a troca de fonte de margem (cálculo manual → `AdmanMetricDiffService`/`percentageMargin`) já são conhecidas por mudar números de forma material (ver âncora Carlos no 102-01: 4.08 → 4.42).
4. **Wiring do `PerformanceController` para consumir `computeOficial()` no contexto "Bônus atual"** continua para a Fase 104 (UI) — o backend já sustenta (`periodo`/`bonus` no shape), mas nenhum controller/view foi tocado nesta fase (fronteira respeitada).

## Follow-ups Recomendados (não obrigatórios, fora do escopo de código desta fase)

- **`WarmDesempenhoCache` não conhece o período oficial**: o comando de warm-cache (cron 8min, ver `app/Console/Commands/WarmDesempenhoCache.php`) chama `computeCached($user, $mesReferencia)` só pro mês corrente/mês selecionado da view — nunca pré-aquece a chave `desempenho.compute.v5.{id}.YYYY-MM` do modo oficial (`computeOficial()`/`last_closed_month`). Quando a Fase 104 ligar a UI ao modo oficial, o primeiro acesso de cada usuário no mês será sempre cold-cache (síncrono, potencialmente lento com HTTP à Adman).
- **Custo do live-read no batch**: `computeVarMargem` agora chama `AdmanMetricDiffService::compute()` por empresa (HTTP síncrono quando não há `.diff` cacheado localmente) — o comando `desempenho:consolidar-mes` e qualquer rota que itere múltiplos profissionais (ex.: ranking `/performance`) paga esse custo por empresa elegível financeiramente. Medir o tempo de execução do `ConsolidarMesDesempenho` em prod sob carga real (pitfall já sinalizado no 102-RESEARCH.md, ainda não medido empiricamente).

## Next Phase Readiness

- **Fase 103/104** podem prosseguir: o backend expõe `periodo`/`bonus` no shape de `compute()`, a cache está na chave v5 com `period_key` (sem colisão operacional×oficial), e o cache-busting do NPS está alinhado — nenhum débito técnico conhecido bloqueando a integração de UI.
- `PerformanceController`, `CarteiraContextService`, `CarteiraContextService::forUser()` continuam INTOCADOS — fronteira da fase respeitada integralmente (nenhum arquivo fora de `DesempenhoScoreService.php` + a 1 linha do `NpsController.php` + os testes do `files_modified` do plano foi tocado).
- **Regressão confirmada limpa** (270/270 testes) em: `tests/Feature/V18` (29/29), `tests/Feature/V16` (160/160), `tests/Feature/Phase74`, `tests/Feature/Phase96`, `tests/Feature/DesempenhoScoreSnapshotTest`, `tests/Feature/DesempenhoEvolucaoTest`. Adicionalmente verificados: `tests/Feature/Portfolio` (7/7), `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest` + `tests/Feature/V16/PerformanceIndexMetadadosTest` + `tests/Feature/V16/BonusAtribuicoesNpsTest` (18/18).
- **Falhas pré-existentes CONHECIDAS** (confirmadas via baseline no 102-01, não re-verificadas nesta plan por estarem fora do edit-set): `PublicacaoDesempenhoRouteTest` (403≠200, permissão `mlb.dashboard`), `Unit/CalcularFaixaTest` (ArgumentCountError em `AdminController`), `Unit/CompanyServiceTypeTest` (SQLite CHECK enum), `tests/Feature/PerformanceCargoFilterTest` (5 falhas).

---
*Phase: 102-desempenho-oficial-por-competencia-v18-0*
*Completed: 2026-07-20*
