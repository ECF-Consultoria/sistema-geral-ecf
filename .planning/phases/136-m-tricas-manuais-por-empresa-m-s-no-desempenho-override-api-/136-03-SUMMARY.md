---
phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-
plan: 03
subsystem: api
tags: [laravel, desempenho, lancamento-manual, override, cmv, margem, phpunit]

# Dependency graph
requires:
  - phase: 136-01
    provides: "FinancialSourceResolver + cache desempenho.compute em v20"
  - phase: 136-02
    provides: "Tabela desempenho_metricas_manuais + model DesempenhoMetricaManual (METRICAS, ativasDaCompetencia())"
provides:
  - "ManualMetricOverrideService — decorator que substitui o eixo manual sobre o resultado do MetricDiffDispatcher"
  - "CompanyScoreService ligado ao override (aplicar() logo após compute()) e ao desbloqueio da margem Shopee com CMV manual"
  - "quality.faturamento_fonte/margem_fonte propagados por todo o pipeline até o snapshot congelado e o reader"
affects: ["136-04", "136-05", "136-06", "136-07", "desempenho-bonificacao"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Decorator pós-dispatcher (nunca terceiro ramo do match do MetricDiffDispatcher) para eixo ortogonal à fonte técnica"
    - "Memoização por (company_id, periodKey, fonte) para evitar recomputar a mesma janela de mês cheio duas vezes no mesmo request"
    - "Sinal auto/manual propagado via quality como blob opaco (mesmo canal de D-03/D-04 já usado por revenue_diff_source/margin_source)"

key-files:
  created:
    - app/Services/Metrics/ManualMetricOverrideService.php
    - tests/Feature/Phase136/MetricaManualLancamentoTest.php
  modified:
    - app/Services/Desempenho/CompanyScoreService.php
    - app/Services/Desempenho/CompanyScoreSnapshotReader.php

key-decisions:
  - "O override roda DEPOIS do MetricDiffDispatcher::compute(), nunca dentro do match() dele — 'manual' é eixo ortogonal à fonte técnica, não uma terceira fonte"
  - "D-EXC-01 documentado com destaque no docblock da classe nova — exceção estreita ao hotfix a413e823, identificada por diff_source='manual_mes_calendario', não relaxa o caminho automático"
  - "Base do mês anterior da margem exige CMV MANUAL do mês anterior (não há fallback de API para CMV) — só o faturamento efetivo segue a cascata D-06 completa"
  - "margin_source (quality) NÃO foi alterado por este plano — só faturamento_fonte/margem_fonte foram acrescentados; margin_source continua sinalizando 'sem_margem_shopee' para leitura legada"
  - "Sem bump de cache — v20 do Plano 01 cobre a fase inteira, nenhum deploy ocorreu entre os planos"

patterns-established:
  - "ManualMetricOverrideService::totalMesCheio() é o único ponto que resolve janela de mês cheio para o eixo manual — sempre via MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM']), nunca cálculo de dias à mão"

requirements-completed: ["D-01", "D-02", "D-05", "D-06", "D-07", "D-08", "D-03", "D-EXC-01"]

# Metrics
duration: 53min
completed: 2026-08-11
---

# Phase 136 Plan 03: Override do valor manual no motor de nota + desbloqueio da margem Shopee Summary

**`ManualMetricOverrideService` decora o resultado do `MetricDiffDispatcher` com o valor lançado à mão, deriva margem do CMV em mês cheio com base em cascata, e faz `CompanyScoreService` ceder o gate que zerava margem de loja Shopee — só quando há CMV manual — tirando a carteira só-Shopee do teto de 3,33.**

## Performance

- **Duration:** ~53 min (17:20 → 18:13 BRT, 2026-08-11)
- **Started:** 2026-08-11T20:20:00Z
- **Completed:** 2026-08-11T21:13:42Z
- **Tasks:** 3/3
- **Files modified:** 2 criados, 2 modificados

## Accomplishments

- `ManualMetricOverrideService` criado em `app/Services/Metrics/`, com `carregarLancamentos()` (pré-carga em lote, zero N+1) e `aplicar()` (substitui `metrics.revenue`/`metrics.contribution_margin_*` do eixo marcado manual), preservando byte-a-byte o shape do dispatcher e cobrando HTTP só de quem tem lançamento ativo (caminho rápido sem nenhuma chamada extra)
- `CompanyScoreService::computeEmpresasScore()` ganhou as 5 emendas cirúrgicas: injeção do override, pré-carga única por carteira, chamada a `aplicar()` logo após `MetricDiffDispatcher::compute()`, o gate `$margemManual` liberando régua/componentes-esperados/motivos exclusivamente para célula manual, e `quality.faturamento_fonte`/`margem_fonte` propagados na linha normal e em `linhaSemFonte()` (sempre `'auto'`, protegendo a trava D-91-01)
- Loja Shopee com CMV manual no mês e no mês anterior passa a ter `margem_pontos` não nulo e `componentes_esperados=3` — mecanismo comprovado por teste que tira a carteira só-Shopee do teto de (5+0+5)/3=3,33
- `CompanyScoreSnapshotReader::mapear()` ganhou os defaults `'auto'`/`'auto'` no bloco de normalização (linha antiga com `quality` nulo) — o writer não precisou de nenhuma alteração, pois `quality` já é blob opaco gravado inteiro
- Suíte `tests/Feature/Phase136/MetricaManualLancamentoTest.php` com 13 testes cobrindo D-01/D-02/D-05/D-06/D-07/D-08, o desbloqueio da margem Shopee (com regressão zero para quem não tem CMV manual), a trava de escopo D-91-01, e D-03 (sinal sobrevivendo à reconsulta do snapshot congelado, sem expor autor)

## Task Commits

Each task was committed atomically:

1. **Task 1: Criar o ManualMetricOverrideService (decorator do resultado do dispatcher)** - `4d2b865e` (feat)
2. **Task 2: Ligar o override ao motor de nota e liberar a margem de Shopee quando o CMV é manual** - `674443ba` (feat)
3. **Task 3: Propagar o sinal de origem manual até a leitura do snapshot (D-03)** - `9a5247e2` (test)

## Files Created/Modified

- `app/Services/Metrics/ManualMetricOverrideService.php` - decorator do eixo manual: `carregarLancamentos()` em lote, `aplicar()` substituindo os blocos revenue/margem, `totalMesCheio()` memoizado via `MetricPeriodResolver`
- `app/Services/Desempenho/CompanyScoreService.php` - injeção do `ManualMetricOverrideService`, pré-carga única de lançamentos, chamada a `aplicar()`, gate `$margemManual` nos três pontos (régua, componentes esperados, motivos), `quality.faturamento_fonte`/`margem_fonte` em ambas as linhas de retorno
- `app/Services/Desempenho/CompanyScoreSnapshotReader.php` - bloco de normalização de `quality` (linha antiga) ganha os defaults `'auto'`/`'auto'`
- `tests/Feature/Phase136/MetricaManualLancamentoTest.php` - 13 testes: D-01/D-02/D-05/D-06/D-07/D-08, desbloqueio da margem Shopee, trava D-91-01, D-03

## Decisions Made

- O override roda como decorator DEPOIS do `MetricDiffDispatcher::compute()`, nunca como terceiro ramo do `match()` dele — "manual" é eixo ortogonal à fonte técnica ('adman'/'shopee'), e misturá-los quebraria a whitelist que defende contra tampering (T-109-02)
- D-EXC-01 documentado com destaque no docblock da classe nova, citando o hotfix `a413e823` de 2026-07-24 e por que a exceção é estreita e não relaxa o caminho automático
- Base da margem do mês anterior exige CMV **manual** do mês anterior (nunca API) — só o faturamento efetivo do mês anterior segue a cascata completa de D-06; sem a decisão explícita ficaria ambíguo o que "base de margem vinda parcialmente da API" significaria
- `quality.margin_source` foi deliberadamente **não** alterado — o plano especificou só a adição de `faturamento_fonte`/`margem_fonte`, mantendo `margin_source='sem_margem_shopee'` como metadado legado que outros consumidores (se algum já ler essa chave) continuam a enxergar sem mudança de contrato
- Todos os testes rodam sobre fonte `'shopee'` (leitura 100% local, sem HTTP) exceto o cenário D-06 rung 3, que usa uma empresa Adman sem `cust_id` (dispatcher devolve shape vazio sem tocar rede) — nenhuma chamada HTTP real ocorre em nenhum teste da suíte

## Deviations from Plan

None - plano executado exatamente como escrito, incluindo os detalhes de `<interfaces>` (shape preservado, `diffPctGuardado()` replicado com a mesma semântica, memoização por `{company_id}:{periodKey}:{fonte}`, D-EXC-01 registrado).

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ManualMetricOverrideService` pronto para ser consumido pelo comando de relatório de impacto (D-11, Plano 06) e pela tela (Plano 05) — a fonte de verdade do cálculo já reflete o lançamento manual
- `quality.faturamento_fonte`/`margem_fonte` disponíveis para o selo de D-04 no lado do profissional (`resources/js/Pages/Performance/Show.jsx` / `EmpresasScoreTabela.jsx`) — pendente de plano futuro que toque o front
- Baseline de 9 falhas pré-existentes (`136-BASELINE-TESTES.md`) reconfirmada intacta após as 3 tasks — nenhuma regressão introduzida
- `git diff --name-only app/Services/Desempenho/CompanyScoreSnapshotWriter.php` permanece vazio — o writer não precisou de nenhuma alteração nesta fase

---
*Phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-*
*Completed: 2026-08-11*
