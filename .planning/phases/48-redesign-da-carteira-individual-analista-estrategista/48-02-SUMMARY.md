---
phase: 48-redesign-da-carteira-individual-analista-estrategista
plan: "02"
status: complete
executed_at: 2026-06-29
commits:
  - sha: 9d2b0ea
    desc: "refactor(48-02): limpa destructuring — remove meta_carteira e forms de PortfolioGoal"
  - sha: 321fb4a
    desc: "feat(48-02): hero card — remove chips meta agregada, adiciona chip meta empresas opcional"
  - sha: b28b47d
    desc: "feat(48-02): KPI grid — substitui 'Meta da carteira' por bloco diferenciado analista/estrategista"
  - sha: 0e4b232
    desc: "feat(48-02): remove modais de meta carteira, botao Meta e bloco admin Metas do painel lateral"
---

# SUMMARY — Plan 48-02: Redesign Hero + KPIs (Show.jsx)

## Status: COMPLETO

## O que foi feito

### Tarefa 1 — Limpeza do destructuring + remoção de states/forms de PortfolioGoal

- Removido `portfolio_goals`, `portfolio_goal_metrics` e `meta_carteira` das props do `PortfolioShow`.
- Adicionado `meta_carteira_calculada`, `cargo_slug`, `sugador_counters`, `ppa_counters` ao destructuring.
- Removidos todos os `useState`/`useForm` ligados a meta de carteira: `goalOpen`, `editGoalOpen`, `editingGoal`, `goalForm`, `editGoalForm`.
- Removidas funções `submitGoal`, `openEditGoal`, `submitEditGoal`, `removeGoal`.
- Removido `useForm` do import `@inertiajs/react` (não mais usado).

### Tarefa 2 — Hero card reformulado

- Removido chip "Meta X%" (`meta_carteira.achieved_pct`) do hero card.
- Removido chip "R$ restante" (`meta_carteira.restante`) do hero card.
- Adicionado chip "Meta empresas X%" opcional baseado em `meta_carteira_calculada.achieved_pct` (aparece apenas quando `has_goal=true` — derivado de metas individuais por empresa, conforme Plan 48-01).
- Chips de crescimento vs anterior e de Score mantidos intactos.

### Tarefa 3 — Bloco diferenciado nos KPIs

- Removido `KpiCard "Meta da carteira"` do grid de 4 KPIs.
- Inserido bloco condicional baseado em `cargo_slug`:
  - **analista** + `sugador_counters != null`: `KpiCard "Sugadores"` com `pendentes` em destaque e `resolvidos` no sub-texto. Accent vermelho se `pendentes > 0`, emerald se zerado.
  - **estrategista** + `ppa_counters != null`: `KpiCard "PPAs"` com `em_andamento` + `concluidos_mes`. Accent sky.
  - **fallback** (admin ou sem cargo): `KpiCard "Score"` com `performance_profissional.score` + classificação. Accent conforme `CLASSIF_CLS`.
- Grid mantém 4 cards: Empresas | [Diferenciado] | Prioridade do dia | Investimento Ads.

### Tarefa 4 — Remoção de modais e CRUD de PortfolioGoal

- Removido botão "+ Meta" do topo da página (admin).
- Removido bloco admin "Metas" do painel lateral (dependia de `portfolio_goals` removido em 48-01).
- Removido `Dialog` de criação de meta ("Nova meta de carteira").
- Removido `Dialog` de edição de meta.
- Importações limpas: `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogFooter`, `Label`, `Textarea` removidos.
- Icons limpos: `Plus`, `Pencil`, `Trash2` removidos do import lucide-react.

### Tarefa 5 — Build

- `npm run build` concluído sem erros: `✓ built in 12.09s`.
- `Show-BzuQUb6x.js` gerado (27.20 kB / 8.11 kB gzip).

## Verificações de Success Criteria

- [x] Hero card sem chips ligados a `meta_carteira` (prop removida)
- [x] Chip "Meta empresas X%" baseado em `meta_carteira_calculada` (opcional)
- [x] Grid 4 KPIs: Empresas | Bloco diferenciado | Prioridade do dia | Investimento Ads
- [x] Bloco diferenciado consome `cargo_slug` + `sugador_counters` ou `ppa_counters`
- [x] Fallback admin/sem cargo: KpiCard Score
- [x] Modais de criação/edição de meta carteira removidos do JSX
- [x] Botão "+ Meta" removido do topo
- [x] Bloco admin "Metas" do painel lateral removido (~linhas 981-1011 do original)
- [x] `npm run build` verde (sem erros, sem warnings de import não usado)
- [x] 4 commits isolados em pt-BR
- [x] Comentários em pt-BR
- [x] Não tocou em tabela de empresas (escopo Plan 48-03)
- [x] Não tocou em painel lateral NPS ou Ações Estratégicas (escopo Plan 48-04)

## Desvios do Plan

| Desvio | Justificativa |
|--------|---------------|
| `portfolio_goal_metrics` removido do destructuring (plan não mencionou explicitamente) | A prop foi usada apenas no modal de criação de meta, que foi removido. Não há outra referência no arquivo. Remoção necessária para código limpo. |
| Botão "+ Meta" substituído por comentário inline em vez de deleção completa do bloco | Mantém a linha de `flex items-center gap-2` no topo intacta; o comentário serve como documentação da decisão de remoção. |
| Nenhuma referência residual a `meta_carteira` (prop antiga) no arquivo | Verificado por grep antes e após as edições. ✓ |
