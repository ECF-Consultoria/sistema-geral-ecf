# Requirements: ECF Admin — Milestone v18.0

**Defined:** 2026-07-17
**Milestone:** v18.0 — Períodos, competência de bônus e variação via Adman
**Core Value:** Toda métrica de resultado declara período atual, período comparativo, fonte e status — resolvidos por uma regra única. O bônus oficial usa mês fechado por competência (julho paga junho). A variação de margem vem do diff pronto da Adman, não de recálculo manual.
**Plano canônico:** `plano-carteira-desempenho-multi-servico.md` (seções "Regra de período", "Regra de variação de margem via Adman", Fases 0/2/3/4/5).

## v18.0 Requirements

### PER — MetricPeriodResolver (Fase 100)

- [x] **PER-01**: `MetricPeriodResolver::resolve($filtros)` retorna `{mode, period_key, current_start, current_end, baseline_start, baseline_end, days_count, comparison_mode, timezone, data_fresh_until, bonus_payment_month, bonus_competence_month, is_current_month, is_closed}` com datas inclusivas e timezone America/Sao_Paulo
- [x] **PER-02**: Modo operacional (mês atual) resolve `01/mês..último dia confiável` vs. `mesmo intervalo do mês anterior` — nunca compara N dias do mês atual com o mês anterior inteiro, nunca usa dia ainda não consolidado pela fonte
- [x] **PER-03**: Modo oficial/bônus resolve a competência do último mês fechado: em julho/2026 → competência junho/2026, pagamento julho/2026, atual `01/06..30/06`, baseline `02/05..31/05` (janela de mesmo tamanho — N dias imediatamente anteriores, decisão do usuário 2026-07-17)
- [x] **PER-04**: Modo mês fechado selecionado resolve o mês calendário completo vs. janela anterior de mesmo tamanho (ex.: maio/2026 → `01/05..31/05` vs `31/03..30/04`); período custom `DD..DD` → baseline com a mesma contagem de dias imediatamente anterior
- [x] **PER-05**: Testes unitários cobrindo os 4 casos obrigatórios do plano (mês atual em 20/07; último fechado em 20/07; filtro junho; custom 01/06..15/06→17/05..31/05) — `tests/Unit/MetricPeriodResolverTest.php`
- [x] **PER-06**: Nenhum controller/tela monta período ou calcula "mês passado" manualmente fora do resolver (regra transversal — verificada por gate nos consumidores do núcleo)

### ADM — AdmanMetricDiffService (Fase 101)

- [x] **ADM-01**: `AdmanMetricDiffService` lê `revenue`, `profitMargin.value/.diff`, `percentageMargin.value/.diff` da resposta/cache Adman — hoje o `AdmanService` descarta o `.diff` (lê só `['value']`)
- [x] **ADM-02**: Prefere o diff oficial da Adman (`diff_source='adman_diff'`); só usa fallback calculado quando o diff não existe para a janela, marcando `diff_source='calculated_fallback'`
- [x] **ADM-03**: Diff de período é persistido/retornado com contexto de período e fonte — não vira fato diário; fato diário guarda valor do dia, snapshot/retorno de período guarda a comparação da janela
- [x] **ADM-04**: Backfill: quando `raw_data` antigo tiver `profitMargin.diff`/`percentageMargin.diff`, preencher os novos campos; quando não tiver, deixar null e permitir fallback marcado
- [x] **ADM-05**: Labels separados sem ambiguidade — Margem R$ (`profitMargin`) distinta de Margem % (`percentageMargin`); nunca misturar `percentageMargin.value` com variação manual de `contribution_margin`

### BON — Desempenho oficial por competência (Fase 102)

- [x] **BON-01**: `DesempenhoScoreService` consome o `MetricPeriodResolver` — o cálculo de var. faturamento/margem usa `period.current_*`/`period.baseline_*`, não `now()`/`startOfMonth` inline
- [x] **BON-02**: Ranking oficial de bônus em julho/2026 usa competência junho/2026 fechada (atual `01/06..30/06` vs `02/05..31/05`); o score de junho é exibido/pago em julho
- [x] **BON-03**: `var_margem_pct` usa `percentageMargin.diff` da Adman quando disponível (via `AdmanMetricDiffService`); fallback calculado só quando ausente, marcado — nenhum teste aceita variação manual quando `adman_diff` existe
- [x] **BON-04**: O retorno do service adiciona `periodo` (janelas atual/baseline) e `bonus` (`payment_month`, `competence_month`) aos metadados; score único preservado (sem score por marketplace — invariante da v17.0)
- [x] **BON-05**: Leitura operacional segue disponível (mês em curso) mas marcada como operacional/parcial; a régua de elegibilidade financeira da v17.0 (`financial_metrics_eligible`, `score_status`) permanece intacta

### CAR — Carteira por período (Fase 103)

- [ ] **CAR-01**: `renderCarteiraProfissional` e `renderCarteirasConsolidadas` resolvem período via `MetricPeriodResolver`; quando o filtro for mês fechado, o cálculo não usa `now()` nem "mês em curso"
- [ ] **CAR-02**: A soma financeira da carteira usa as janelas do resolver (atual/baseline) e a variação de margem vem do diff da Adman quando disponível; elegibilidade financeira da v17.0 preservada (Shopee sem fonte não entra)
- [ ] **CAR-03**: Todos os cards/tabelas/séries da carteira leem `period.current_start/end` e `period.baseline_start/end` — coerência de janela entre todos os blocos

### UIP — UI de período (Fase 104)

- [ ] **UIP-01**: O ranking `/performance` e a carteira exibem um toggle/segmento de contexto de período: "Em curso" / "Bônus atual" / "Mês fechado" (+ mês específico) — rótulos sem jargão
- [ ] **UIP-02**: O payload Inertia dessas telas carrega `periodo` (janelas + label) e, no modo bônus, `bonus.competence_month`/`payment_month`; a tela mostra a competência avaliada e o mês de pagamento
- [ ] **UIP-03**: Filtro de período nas telas de resultado do núcleo (carteira individual, consolidada, ranking); toda comparação exibida vem da janela resolvida, não de cálculo próprio da tela
- [ ] **UIP-04**: A tela indica claramente quando está em modo operacional/parcial vs. oficial de bônus (para não confundir número em curso com número de pagamento)

## Critérios de aceite globais (do plano canônico)

- Nenhum lugar calcula "mês passado" manualmente fora do resolver
- Toda resposta da API/Inertia do núcleo carrega `periodo`
- Fallback calculado de variação só é usado quando a Adman não trouxer diff (e é marcado)
- Fatos diários não guardam diff de período sem contexto; diffs de período ficam em snapshot/retorno com fonte declarada
- Todas as invariantes da v17.0 preservadas (score único, elegibilidade financeira, sem duplicação de empresa)

## Out of Scope (v18.0)

- **Fase 7 do plano (propagação geral)** — dashboards admin/profissional, detalhe de empresa, metas, relatórios de fechamento/mensais: milestone seguinte (decisão do usuário 2026-07-17). Esta milestone entrega o resolver + núcleo (Carteira/Desempenho); a propagação para o resto vem depois.
- **Baseline por mês calendário** — descartado; usa-se janela de mesmo tamanho (N dias anteriores). O resolver pode suportar o modo calendário internamente, mas o oficial de bônus é janela-de-mesmo-tamanho.
- **Régua de bônus do Shopee sem financeiro** — segue decisão de diretoria (herdada da v17.0; Matheus permanece `blocked`).
- **Fonte financeira Shopee** — continua inexistente; vínculos Shopee seguem sem fonte.

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| PER-01 | Fase 100 | Complete |
| PER-02 | Fase 100 | Complete |
| PER-03 | Fase 100 | Complete |
| PER-04 | Fase 100 | Complete |
| PER-05 | Fase 100 | Complete |
| PER-06 | Fase 100 | Complete |
| ADM-01 | Fase 101 | Complete |
| ADM-02 | Fase 101 | Complete |
| ADM-03 | Fase 101 | Complete |
| ADM-04 | Fase 101 | Complete |
| ADM-05 | Fase 101 | Complete |
| BON-01 | Fase 102 | Complete |
| BON-02 | Fase 102 | Complete |
| BON-03 | Fase 102 | Complete |
| BON-04 | Fase 102 | Complete |
| BON-05 | Fase 102 | Complete |
| CAR-01 | Fase 103 | Pending |
| CAR-02 | Fase 103 | Pending |
| CAR-03 | Fase 103 | Pending |
| UIP-01 | Fase 104 | Pending |
| UIP-02 | Fase 104 | Pending |
| UIP-03 | Fase 104 | Pending |
| UIP-04 | Fase 104 | Pending |

**Cobertura:** 23/23 requirements v18.0 mapeados ✓ — zero órfãos, zero duplicatas.
