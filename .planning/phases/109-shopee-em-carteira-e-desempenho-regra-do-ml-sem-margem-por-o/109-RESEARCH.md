# Phase 109: Shopee em Carteira e Desempenho — Research

**Data:** 2026-07-23
**Fonte:** 2 agentes Explore (fluxo ML na carteira/desempenho + dados Shopee existentes) nesta sessão.

## Resumo executivo

Viável e cirúrgico. Os dados Shopee (`shopee_metrics`) têm granularidade diária e as métricas necessárias (revenue + ad_expense), sem margem — exatamente o que a regra ML pede. A fonte única atual de números na Carteira E no Desempenho é `AdmanMetricDiffService::compute(Company, $periodo)`. Plugar Shopee = espelhar esse contrato + abrir o portão de elegibilidade + dispatcher por fonte + tolerância a margem ausente. `MetricPeriodResolver` (janelas) NÃO muda.

## Como o ML flui hoje (a espelhar)

```
CarteiraContextService::forUser()  → vínculos + elegibilidade financeira por setor
   (flagsFinanceirasPorSetor: só 'performance' → financial_source='adman')
        │
        ├─► PortfolioController (renderCarteiraProfissional / renderCarteirasConsolidadas / transparencia)
        └─► DesempenhoScoreService::compute() → PerformanceController (ranking)
                    │
        MetricPeriodResolver::resolve()  (em-curso vs bônus/fechado — agnóstico de fonte)
                    │
        AdmanMetricDiffService::compute(company, periodo)
                    │
        metrics.revenue / contribution_margin_value / contribution_margin_pct  (value + diff_pct + diff_source)
```

## Contrato de retorno a espelhar (ShopeeMetricDiffService)

`AdmanMetricDiffService::compute()` retorna (shape a replicar):
```
[
  'metrics' => [
    'revenue'                  => ['value'=>float, 'diff_pct'=>?float, 'diff_source'=>string],
    'contribution_margin_value'=> ['value'=>?float, 'diff_pct'=>?float, 'diff_source'=>string],  // Shopee: null
    'contribution_margin_pct'  => ['value'=>?float, 'diff_pct'=>?float, 'diff_source'=>string],  // Shopee: null
  ],
  ...
]
```
- **Shopee**: `revenue` somado de `shopee_metrics.revenue` no `$periodo['current']` vs `$periodo['baseline']` → diff_pct; `contribution_margin_*` = null (sempre). Investimento (`ad_expense`) pode ser exposto como campo extra para a carteira.
- **Sem gate `.diff` nativo** (a Shopee não tem endpoint de diff como a Adman) — cálculo é sempre soma-DB dos dois intervalos que o `MetricPeriodResolver` já fornece (`current` + `baseline`). Simples e determinístico; o `comparison_mode` do resolver não afeta o cálculo Shopee (mas a janela sim).
- **Guards**: dias ausentes = 0 (não há linha por dia sem venda); investimento `null` quando a janela cai fora dos ~6 meses de Ads → distinguir "sem dado de Ads" de "R$ 0 investido".

## Dispatcher por fonte

Onde hoje se chama `admanDiffService->compute($company, $periodo)`, decidir Adman vs Shopee pela fonte do vínculo. `CarteiraContextService` já expõe `financial_source` por vínculo (hoje 'adman' só p/ performance). Após abrir o branch Shopee em `flagsFinanceirasPorSetor()`, o dispatcher lê `financial_source` ('adman' | 'shopee') e roteia para o service correto. Consumidores a converter:
- `DesempenhoScoreService::computeVarFaturamento()` (:959) e `computeVarMargem()` (:1004)
- `PortfolioController::renderCarteiraProfissional()` (:577, :589-593) e `transparencia()` (:249-252)
- Leituras diretas de `adman_metrics` agregado (`PortfolioController:438-449`) precisam de caminho Shopee equivalente (SUM `shopee_metrics.revenue`/`ad_expense`).

## Nota do Desempenho com margem ausente (decisão do usuário)

- `computeVarMargem()` para vínculo Shopee → sem número real; a régua de margem (`reguaMargem()`) deve produzir **nota = 1** (placeholder), não null.
- `computeScoreStatus()` (:573-584) NÃO pode marcar `blocked`/`partial` por margem ausente quando há fonte financeira Shopee elegível — precisa reconhecer Shopee como fonte válida com margem placeholder.
- `computeNotaFinal()` = média(régua_faturamento, régua_margem=1, ...NPS) → produz nota. Estrutura das 3 dimensões preservada; quando houver margem real Shopee, o service retorna o valor e a régua passa a usar o número (future-ready).

## Cache / crons

- `cacheKey()` (:274-289) hoje **v9** → bump para **v10** (mudança de comportamento no compute). Atualizar strings hardcoded nos testes (Phase96/V16/V18) — ver armadilha conhecida.
- `WarmAdmanDiffCache` só aquece Adman → adicionar aquecimento Shopee (ou generalizar) para os vínculos Shopee elegíveis, senão a carteira/ranking Shopee computa frio.
- `SnapshotDesempenhoScores`/`ConsolidarMesDesempenho`/`WarmDesempenhoCache` herdam automaticamente do `compute()`.

## Testes / validação (Nyquist)

- **Unit** `ShopeeMetricDiffService`: soma de intervalos, diff_pct, dias ausentes=0, investimento null fora dos 6 meses, margem sempre null.
- **Feature Carteira**: profissional com empresa Shopee vê faturamento/investimento por período (em-curso e bônus); empresa ML+Shopee não duplica; só-Shopee não quebra.
- **Feature Desempenho**: profissional só-Shopee tem nota_final != null com régua margem=1; misto ML+Shopee agrega corretamente; `score_status` não `blocked` por margem ausente.
- **Regressão**: suítes V16/V18/Nps/Desempenho verdes após bump v9→v10 (strings atualizadas).
- **Validação numérica real** (VERIFICATION): rodar ranking real e mostrar o impacto de margem=1 na média/posição de quem é só-Shopee para o usuário confirmar.

## Pitfalls

Ver `<specifics>` do CONTEXT.md — cacheKey bump, company_users multi-linha, Rollup .map() scope, enum SQLite, MySQL FK/índice no VPS.
