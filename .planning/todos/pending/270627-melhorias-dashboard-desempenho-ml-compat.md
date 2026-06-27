---
id: 270627-melhorias-dashboard-desempenho-ml-compat
created: 2026-06-27
priority: high
effort_estimate: |
  Item 2 (label fix): 15min — quick task
  Item 3a (link widget): 15min — quick task
  Phase 45 (compat ML métricas + widget unify): 1-2 dias
  Phase 46 (histórico desempenho): 1 dia
  Phase 47 (novos params score): 1 dia — bloqueado por Phase 44
category: brief-umbrella
related_phases: [45, 46, 47]
related_briefings:
  - briefing-carteira-analistas-ui.md (untracked no root — UI carteira analistas/estrategistas; complementa Item 1b)
  - metodologia-desempenho-carteira.md (untracked no root — metodologia de scoring justa; complementa Itens 4 e 5)
blocks_on:
  - Phase 44 (apenas para Phase 47 — analistas/estrategistas precisam do path ML para sugador-resolvido)
status: pending
---

# Briefing — Melhorias dashboard + desempenho + compatibilidade ML

Capturado em 2026-06-27 após Phase 44 pausar no checkpoint humano. Agrupa 5 itens com escopos distintos. **Não é uma única phase** — split documentado abaixo.

## Contexto

Milestone v11.0 (Phases 38-44) migrou Sugadores Adman → ML. Hoje só Bymobille (#298) usa ML em prod. Futuro: maioria das empresas vai migrar para ML. Hoje dashboards/widgets/carteiras/desempenho leem majoritariamente `adman_metrics` local — empresas ML-only não aparecem ou aparecem zeradas.

## Item 1 — Compatibilidade ML em métricas (→ Phase 45)

Auditar e garantir que empresas com fonte ML aparecem em:

- **Dashboard admin**: faturamento total, investimento ads, TACOS médio, gráfico evolução
- **Carteira individual e geral** (admin, líder): empresas ML na lista + métricas agregadas
- **Página de desempenho**: scores refletem performance ML também

**Abordagem sugerida:** reusar pattern factory do Sugadores v11.0 — abstrair fonte (Adman vs ML) atrás de um service unificado. Provavelmente `CompanyMetricsProvider` com 2 implementações (`AdmanMetricsProvider`, `MlMetricsProvider`) + factory por empresa.

## Item 2 — BUG: label "Carteira ativa Adman" (→ /gsd-quick)

Dashboard admin tem card "111 empresas" com descrição "Carteira ativa Adman". Errado: dashboard é do setor performance, não exclusiva Adman. Deveria mostrar carteira total do setor.

**Provável fix:** `resources/js/Pages/Dashboard/*.jsx` — KpiCard com label hardcoded + ajuste query no `DashboardController`.

**Effort:** 15min — quick task. Bom candidato para `/gsd-quick`.

## Item 3 — BUG: widget "Desempenho da equipe" diverge da página /desempenho

**(a) Faltando link:** clicar no widget deveria levar pra `/desempenho`. Hoje não tem. (→ /gsd-quick)

**(b) Inconsistência crítica:** classificação no widget ≠ classificação na página. Widget e página leem queries/serviços diferentes. Precisa unificar — service único como fonte de verdade. (→ Phase 45, junto com Item 1)

## Item 4 — FEATURE: histórico de scores na página de desempenho (→ Phase 46)

Hoje scores são calculados em tempo real. Sync Adman async diário muda classificação TODO DIA — sem registro do que era ontem. Impossível decidir quem é realmente o melhor/pior ao longo do tempo.

**Solução desejada:**
- Snapshot diário dos scores (job no scheduler, após sync Adman terminar)
- Tabela `desempenho_score_snapshots` (user_id, ref_date, score, ranking_pos, breakdown_json)
- UI mostra delta vs dia anterior / semana anterior (↑ +0.5 em relação a ontem)
- Gráfico de evolução individual ao longo do tempo

**Referência:** `metodologia-desempenho-carteira.md` (untracked no root) já tem metodologia de scoring justa pensada — deve ser lida na discuss-phase da Phase 46.

## Item 5 — FUTURO: novos parâmetros de score (→ Phase 47)

**Bloqueado por Phase 44** (sugador-resolvido via ML precisa do path write ML funcionando).

- **Analistas**: pontuar quando resolver sugador via sistema (analista é quem faz função sugadores)
- **Estrategistas**: pontuar quando cliente/empresa concluir o PPA (só estrategistas fazem PPA)

Garante balanço entre as duas funções no scoring novo.

## Split sugerido

| Item | Destino | Status |
|------|---------|--------|
| Item 2 — label dashboard | `/gsd-quick` | Pronto para execução |
| Item 3a — link no widget | `/gsd-quick` (junto com Item 2 se quiser) | Pronto para execução |
| Itens 1 + 3b — compat ML + widget unify | Phase 45 | Adicionar ao ROADMAP |
| Item 4 — histórico desempenho | Phase 46 | Adicionar ao ROADMAP |
| Item 5 — novos params score | Phase 47 | Adicionar ao ROADMAP; depends_on Phase 44 |

## Próximos passos

1. Promover Itens 2 + 3a para `/gsd-quick` (~30min total — 2 fixes triviais)
2. Rodar `/gsd-phase add 45` com escopo de Item 1+3b (compat ML)
3. Rodar `/gsd-phase add 46` com escopo de Item 4 (histórico)
4. Rodar `/gsd-phase add 47` com escopo de Item 5 (novos params), marcar `depends_on=[44]`
5. (Quando Phase 44 destravar) Phase 47 fica liberada

## Referências cruzadas

- `.planning/STATE.md` blocker `44-01-T3` (Phase 44 pausada)
- `.planning/phases/44.../44-01-CHECKPOINT-PENDING.md`
- `briefing-carteira-analistas-ui.md` (root, untracked) — redesign UI carteira; deve ser ingerido no contexto da Phase 45
- `metodologia-desempenho-carteira.md` (root, untracked) — metodologia score justa; deve ser ingerido no contexto da Phase 46
- Memory: `project_adman_data_sources.md` (dashboards hoje leem `adman_metrics` local)
- Memory: `project_ml_only_companies_adman_endpoints.md` (empresas ML-only 422 em endpoints Adman MCP)
- Memory: `project_v11_sugadores_ml_migration.md` (factory pattern v11.0 reusável)
