---
phase: 119-score-por-empresa-v21-0
plan: 03
subsystem: testing
tags: [phpunit, adman, dispatcher, margem, diff_pp]

requires:
  - phase: 119-02
    provides: "CompanyScoreService::computeEmpresasScore() completo (universo, fonte vencedora, guard C-04, chamada única ao dispatcher)"
provides:
  - "Prova âncora de EMPS-03: margem pontuada sobre diff_pp, nunca diff_pct (fixture MPP-06 divergente)"
  - "Prova de EMPS-05: dispatcher chamado exatamente 1x por empresa com fonte, 0x sem fonte, por contador (não por HTTP)"
  - "Prova de C-04: guard antes da chamada, nenhuma exceção lançada nem capturada"
affects: [120-flag-consumo-companyscoreservice]

tech-stack:
  added: []
  patterns:
    - "Dublê contador extends MetricDiffDispatcher, registrado via app()->instance() ANTES de resolver o serviço sob teste"
    - "Assert::fail() dentro de classe não-TestCase para provar que um branch nunca é acionado"

key-files:
  created:
    - tests/Feature/Phase119/CompanyScoreServiceMargemTest.php
    - tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php
  modified: []

key-decisions:
  - "Nenhuma mudança em CompanyScoreService.php — a Wave 1 já implementava diff_pp e a chamada única corretamente; esta wave só prova."

patterns-established:
  - "Contagem de chamadas via dublê no dispatcher (não Http::assertSentCount) — memo+cache do AdmanMetricDiffService pode colapsar 2 chamadas lógicas numa única requisição HTTP"

requirements-completed: [EMPS-03, EMPS-05]

duration: 45min
completed: 2026-07-29
---

# Fase 119 Plano 03: Prova dura de EMPS-03 e EMPS-05 (margem por diff_pp, dispatcher 1x) — Summary

**Duas suítes de teste provam que `CompanyScoreService` pontua margem sobre `diff_pp` (nunca `diff_pct`) e chama `MetricDiffDispatcher::compute()` exatamente 1x por empresa com fonte — zero mudança de código, a Wave 1 já estava correta.**

## Performance

- **Duration:** ~45 min
- **Completed:** 2026-07-29
- **Tasks:** 2/2
- **Files modified:** 2 (ambos novos, testes)

## Accomplishments

- **EMPS-03 provado com a fixture âncora MPP-06** — `margem_pontos = 4.0` (via `diff_pp = 3,39`), explicitamente `assertNotSame(5.0, ...)` (o que `diff_pct = 14,09` produziria). Cobertos também os dois caminhos que zeram `diff_pp`: payload sem `percentageMargin.prev` e período com `comparison_mode = same_interval_previous_month` (mês em curso) — ambos com `margem_var_pp = null`, `margem_pontos = null` e motivo `margem_pp_indisponivel`.
- **EMPS-05 provado por contador, não por HTTP** — dublê `DispatcherContador` (estende `MetricDiffDispatcher`, delega em `parent::compute()`) registrado no container prova 1 chamada para a empresa Adman, 1 para a empresa Shopee, 0 para a empresa `polos` — soma sempre 2, nunca 4.
- **C-04 provado sem exceção** — nenhuma `InvalidArgumentException` escapa em nenhum cenário; o dublê falha o teste explicitamente (`Assert::fail()`) se receber `$source` fora da whitelist `'adman'`/`'shopee'` — nunca é acionado, porque o guard vive antes da chamada.
- **D-03 confirmado intacto** — a empresa sem fonte segue na `Collection` com `status='sem_fonte'`, o guard não virou "descarta a empresa".

## Task Commits

1. **Task 1: EMPS-03 — margem pontuada sobre diff_pp, nunca sobre diff_pct** — `b5d57680` (test)
2. **Task 2: EMPS-05 — dispatcher 1x por empresa + guard de fonte nula (C-04)** — `5f31239d` (test)

_Nenhum commit `feat`/`fix` nesta wave — o `CompanyScoreService` já satisfazia os dois requirements desde a Wave 1 (linhas 220 e 230 de `CompanyScoreService.php`, chamada única + leitura de `diff_pp`)._

## Files Created/Modified

- `tests/Feature/Phase119/CompanyScoreServiceMargemTest.php` — 4 testes: fixture MPP-06 (4,0 pts, não 5,0), sem `prev` (null + motivo), mês em curso (null + motivo), reforço "nunca diff_pct".
- `tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php` — 2 testes + classe `DispatcherContador` (dublê contador): contagem 1x/1x/0x + soma=2, e empresa sem fonte não lança exceção e segue `status='sem_fonte'`.

## Decisions Made

- **Nenhuma mudança em `CompanyScoreService.php`.** Ao ler o código produzido na Wave 1, `margem_var_pp` já lê exclusivamente `contribution_margin_pct.diff_pp` (linha 230) e o guard `if ($fonteFinanceira === null)` (linha 208) já precede a única chamada ao dispatcher (linha 220) — os dois requirements da Task 1/2 já estavam satisfeitos. As duas suítes desta wave são prova, não implementação. Rodei os 4 + 2 testes antes de qualquer ajuste e todos passaram de primeira — não houve necessidade de "corrigir o serviço para caber no teste" (regra do `<action>` do plano).
- **NPS fixado por dublê (`$this->mock(NpsPorEmpresaService::class, ...)`) na `CompanyScoreServiceMargemTest`** — os asserts de margem não dependem da janela/survey de NPS (já coberto em `CompanyScoreServiceContratoTest`), evitando acoplamento desnecessário à régua `gte` de fechamento de janela M+1.
- **`custId` único por teste** (`CUST-MARGEM-01..04`) — protege contra colisão de cacheKey do `AdmanMetricDiffService` entre cenários da mesma suíte (mesmo padrão já usado na Wave 1).

## Deviations from Plan

None — plano executado exatamente como escrito. Os dois testes passaram na primeira execução, sem necessidade de ajustar o `CompanyScoreService`.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Verificação

| Gate | Resultado |
|---|---|
| `--filter=CompanyScoreServiceMargemTest` | **4/4 verdes** (26 asserções) |
| `--filter=CompanyScoreServiceDispatcherTest` | **2/2 verdes** (18 asserções) |
| `--filter=Phase119` (suíte completa) | **18/18 verdes** (196 asserções) |
| Aditividade: `sha256sum app/Services/DesempenhoScoreService.php` | `cfc16da2a8404fba…9edd` — byte-a-byte intocado, verificado em toda task e antes/depois desta wave |
| `--filter=Desempenho` | **14 falhas** — exatamente a baseline pré-existente (debug de margem já aberto, `.planning/debug/margem-adman-diff-instavel.md`). Sem regressão. |
| Nenhuma chamada real à Adman | `Http::preventStrayRequests()` ativo em toda a suíte; toda fixture veio de `Http::fake()` |

## Next Phase Readiness

- EMPS-03 e EMPS-05 provados com testes que teriam ficado vermelhos se o fio estivesse errado (divergência de nota 4×5, contagem 1×2 vs 2×4) — a Fase 120 pode ligar a flag com confiança de que a unidade da margem e a disciplina de 1 chamada/empresa estão corretas.
- Wave 3 (`119-04-PLAN.md`) segue conforme roadmap da fase — Shopee e taxonomia de status (EMPS-06/07) ainda não têm suíte dedicada além do que `CompanyScoreServiceContratoTest`/`CompanyScoreServiceDispatcherTest` já tocam de raspão (placeholder de margem Shopee=1.0 confirmado nesta wave).
- Débito herdado da Wave 1 (réguas duplicadas via Reflection, não extração real) permanece registrado para a Fase 120.

## Self-Check: PASSED

Arquivos criados verificados no disco (`tests/Feature/Phase119/CompanyScoreServiceMargemTest.php`, `tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php`) e os 2 commits (`b5d57680`, `5f31239d`) verificados via `git log --oneline`.

---
*Phase: 119-score-por-empresa-v21-0*
*Completed: 2026-07-29*
