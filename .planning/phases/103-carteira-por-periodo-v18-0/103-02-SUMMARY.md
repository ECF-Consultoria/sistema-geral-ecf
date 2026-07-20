# 103-02 — Carteira consolidada por período — SUMMARY

**Concluído:** 2026-07-17 (GREEN finalizado no thread principal após o executor bater no limite de sessão exatamente no "staging and committing")

## O que foi entregue

`renderCarteirasConsolidadas` (PortfolioController) migrou a janela de datas do bloco rolante (`?period=` em dias) para o `MetricPeriodResolver`:
- `?mes=YYYY-MM` presente e válido → `resolve(['period_key' => $mes])` (closed_period, janela-de-mesmo-tamanho).
- Caso contrário → `resolve(['period_key' => 'current_month'])` (operacional, default preserva comportamento).
- `$dateFrom/$dateTo` passam a vir de `$periodo['current_start']/['current_end']`.
- **`$period = $request->get('period', '30')` PRESERVADA** como linha isolada — alimenta o echo legado `'period' => $period` no payload que a `Carteiras.jsx` lê até a Fase 104 (fix do plan-checker warning 1).
- `periodo` exposto no payload Inertia.

## Escopo mínimo honrado
Sem chip de variação de margem novo na consolidada (isso é UI/104). Só janelas do resolver + payload `periodo`. Teste trava a ausência do campo novo.

## Commits
- `36a60be` test(103-02): suite V18 RED
- `5f3c6c2` feat(103-02): renderCarteirasConsolidadas resolve periodo via resolver

## Gates
- `tests/Feature/V18/CarteiraConsolidadaPeriodoTest.php`: 4/4 verde (23 assertions).
- Regressão carteira (V18 + V16 89/90): 30/30 verde.
- Fronteira: só `renderCarteirasConsolidadas` tocada; `renderCarteiraProfissional` (103-01) e `renderPortfolio` (fora de escopo) intactos — confirmado por grep (resolver só nas 2 funções-alvo).

## Corrida de commit (registrado)
O commit `fcdbc68` (Wave 1) ficou mal-rotulado: mensagem `fix(97-02)` (sessão paralela) com conteúdo dos 3 arquivos de isolamento HTTP desta fase. Cosmético — nenhum trabalho perdido de nenhum lado, verificado. A sessão paralela deve conferir que o `DashboardPendencyPropsTest` dela foi commitado em outro ponto.

## Known-gap 103↔104
Os seletores visuais pré-existentes (dropdown `?mes=` na individual; rolante 1/7/30/180 na consolidada) continuam na `.jsx` com o backend já em janelas do resolver — a UI é rewired na Fase 104.
