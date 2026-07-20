---
phase: 101-admanmetricdiffservice-v18-0
plan: 01
subsystem: api
tags: [laravel, adman-api, http-fake, metric-period-resolver, live-read]

# Dependency graph
requires:
  - phase: 100-metricperiodresolver
    provides: "MetricPeriodResolver::resolve() — objeto de período com comparison_mode"
provides:
  - "App\\Services\\Metrics\\AdmanMetricDiffService::compute(Company, periodo) — leitura ao vivo de revenue/margem R$/margem % com diff de período e fonte declarada"
  - "AdmanService::fetchAccountMetricsDetailedCached() — leitura aditiva que preserva {value,diff,prev}"
affects: [102-bonus-por-competencia, 103-carteira-por-periodo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate por comparison_mode antes de aceitar .diff nativo de API de terceiro"
    - "Guards cicatrizados (margem_dias + interseção de dias-comuns) copiados como métodos privados de baixo nível, sem inverter dependência"
    - "Cache live-read por dia BRT (cacheDay()) em vez de coluna persistida"

key-files:
  created:
    - app/Services/Metrics/AdmanMetricDiffService.php
    - tests/Feature/V18/AdmanMetricDiffServiceTest.php
  modified:
    - app/Services/AdmanService.php

key-decisions:
  - "calculated_fallback generaliza o guard de dias-comuns do DesempenhoScoreService (que usa 'dia do mês', já que suas janelas sempre começam no dia 1) para 'offset em dias desde o início da janela' — necessário porque as janelas do MetricPeriodResolver podem começar em qualquer dia"
  - "contribution_margin_pct no fallback é derivado de SUM(contribution_margin)/SUM(revenue)*100 em cada janela (mesmo cálculo de AdmanService::syncCompany), não de média de percentuais diários"
  - "fetchAccountMetricsDetailedCached itera TODAS as chaves de metrics dinamicamente (não lista fixa) — cobre percentageMargin sem duplicar a lista de campos do shape simplificado"

requirements-completed: [ADM-01, ADM-02, ADM-03, ADM-05]

# Metrics
duration: 20min
completed: 2026-07-20
---

# Phase 101 Plan 01: AdmanMetricDiffService (núcleo) Summary

**AdmanMetricDiffService lê ao vivo o `.diff` de período pronto da Adman com gate por `comparison_mode`, caindo para um `calculated_fallback` guardado (margem_dias + dias-comuns) quando o baseline nativo da Adman não é semanticamente equivalente ao do `MetricPeriodResolver`.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-07-20T11:50:59-03:00 (commit anterior à fase)
- **Completed:** 2026-07-20T12:01:00-03:00
- **Tasks:** 2
- **Files modified:** 3 (2 criados, 1 modificado)

## Accomplishments
- `AdmanService::fetchAccountMetricsDetailedCached()` — leitura aditiva que preserva `{value,diff,prev}` por campo do endpoint `/accounts/{custId}/metrics`, sem alterar `fetchAccountMetricsCached()` (5 consumidores intactos, comprovado por teste de regressão)
- `App\Services\Metrics\AdmanMetricDiffService::compute(Company, periodo)` — service novo que combina `/performance` (revenue, profitMargin) e `/accounts/metrics` (percentageMargin), aplicando o gate `comparison_mode==='previous_equal_length_window'` para decidir entre `adman_diff` e `calculated_fallback`
- `calculated_fallback` com os guards cicatrizados por 3 rounds de bugs de produção (margem_dias + interseção de dias-comuns), implementados como métodos privados do próprio service — sem chamar `DesempenhoScoreService` (duplicação temporária e intencional, ver seção dedicada abaixo)
- 8 testes verdes cobrindo ADM-01/02/03/05, incluindo o cenário de gap de sync parcial que espelha `audit-margem-luiz-ana.md` — prova que o fallback NÃO produz variação de -100% artificial

## Task Commits

Cada task foi commitada atomicamente (RED confirmado antes de cada GREEN):

1. **Task 1: Leitura detalhada aditiva de account-metrics** - `cdf5b52` (feat) — RED confirmado renomeando temporariamente o método novo (`Call to undefined method`), GREEN após restaurar o nome real
2. **Task 2: AdmanMetricDiffService::compute com gate + fallback guardado** - `663e872` (feat) — RED confirmado via `BindingResolutionException: Target class ... does not exist` nos 6 cenários novos antes da classe existir

**Plan metadata:** commit deste SUMMARY (a seguir)

_Nota: TDD confirmado por inspeção direta do output de falha em cada RED — não houve etapa REFACTOR separada (implementação já saiu limpa na primeira GREEN)._

## Files Created/Modified
- `app/Services/AdmanService.php` — método `fetchAccountMetricsDetailedCached()` adicionado após `fetchAccountMetricsCached()` (linha ~789); nenhuma linha do método original alterada
- `app/Services/Metrics/AdmanMetricDiffService.php` — novo; `compute()` + 6 métodos privados (`resolveField`, `fallbackSomaSimples`, `fallbackMargemPct`, `somasComGuards`, `diffPctGuardado`, `buildResult`/`buildQuality`/`cacheDay`)
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` — novo; 8 testes (2 do reader detalhado + 6 cenários do `compute()`)

## Decisions Made
- **Offset-desde-início-da-janela em vez de dia-do-mês:** o guard de dias-comuns do `DesempenhoScoreService` original usa "dia do mês" porque suas duas janelas SEMPRE começam no dia 1 (mês corrente vs mês anterior). As janelas do `MetricPeriodResolver` podem começar em qualquer dia (ex.: `previous_equal_length_window` usa N-dias-antes, não necessariamente dia 1). Generalizei a mesma ideia usando `Carbon::diffInDays()` a partir do início de cada janela — matematicamente equivalente quando as janelas começam no dia 1, e correto também nos outros casos.
- **`contribution_margin_pct` fallback deriva de somas, não de média de percentuais:** consistente com como a própria Adman/`AdmanService::syncCompany()` calcula `contribution_margin_pct` (`profitMargin/grossBilling*100`), evitando o erro estatístico de somar/mediar uma taxa diária.
- **Falha da chamada `/performance` não aborta o `/accounts/metrics`:** as duas chamadas são independentes e cada uma fail-open individualmente — uma empresa com `/performance` fora do ar ainda pode ter `contribution_margin_pct` computado (quality='partial').

## Deviations from Plan

None - plano executado exatamente como escrito, incluindo os fixtures reais do research e os 6 cenários de teste obrigatórios.

## Issues Encountered
None.

## Duplicação temporária intencional (para a Fase 102 desfazer)

O `calculated_fallback` (`AdmanMetricDiffService::somasComGuards()` + `fallbackSomaSimples()` + `fallbackMargemPct()`) carrega uma cópia PRIVADA dos guards `margem_dias` (fix Luiz 2026-07-09) e interseção de dias-comuns (audit Tomelin/LOJASINVAL/AVF2K 2026-07-13) que hoje vivem em `DesempenhoScoreService::computeVarMargem()`/`computeVarFaturamento()` (linhas ~811-978). A Fase 102 (BON-03), ao reconectar o `DesempenhoScoreService` para consumir este service, DEVE REMOVER a lógica duplicada de lá — a 102 já toca esse arquivo por natureza. A direção arquitetural foi mantida correta nesta fase: o diff service (baixo nível) NÃO chama o score service (alto nível); é o score service que, na Fase 102, passará a chamar o diff service.

## Validação pendente de produção (Assumption A2 / Open Question #3 do research)

O baseline "N dias imediatamente anteriores" (que torna `.diff` nativo da Adman equivalente ao `previous_equal_length_window` do resolver) foi confirmado empiricamente SÓ para 1 empresa (id 242) e 2 janelas (1 dia e 18 dias). Antes do BON-02 (Fase 102, mês fechado real de 30 dias), validar em produção que o `.diff` nativo da Adman continua batendo com `MetricPeriodResolver::baselineJanelaMesmoTamanho()` para uma janela de mês completo (30 dias) e, idealmente, para outro marketplace (ex.: Shopee). O gate por `comparison_mode` é a proteção arquitetural correta independentemente do resultado dessa validação — mesmo se a suposição falhar para janelas maiores, o pior caso é o service cair para `calculated_fallback` incorretamente rotulado como `previous_equal_length_window` sem correção adicional (não há regressão de segurança, só uma oportunidade de melhoria futura).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `AdmanMetricDiffService::compute(Company, periodo)` está pronto para ser consumido pela Fase 102 (bônus por competência) e Fase 103 (carteira por período)
- A Fase 102 deve remover a duplicação de guards do `DesempenhoScoreService` ao reconectá-lo (ver seção dedicada acima)
- A validação de produção da Assumption A2 (janela de 30 dias / outro marketplace) ainda está pendente — recomendado rodar 1 verificação manual antes do BON-02 fechar o primeiro mês real com este service

---
*Phase: 101-admanmetricdiffservice-v18-0*
*Completed: 2026-07-20*

## Self-Check: PASSED

- FOUND: app/Services/Metrics/AdmanMetricDiffService.php
- FOUND: tests/Feature/V18/AdmanMetricDiffServiceTest.php
- FOUND: app/Services/AdmanService.php
- FOUND: .planning/phases/101-admanmetricdiffservice-v18-0/101-01-SUMMARY.md
- FOUND commit: cdf5b52 (Task 1)
- FOUND commit: 663e872 (Task 2)
