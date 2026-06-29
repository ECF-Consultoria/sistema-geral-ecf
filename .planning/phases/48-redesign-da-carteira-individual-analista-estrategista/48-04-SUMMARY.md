---
phase: 48-redesign-da-carteira-individual-analista-estrategista
plan: "04"
status: complete
executed_at: 2026-06-29
commits:
  - sha: 288bc5c
    desc: "feat(48-04): cria NpsHistoryWidget.jsx — mini LineChart NPS mensal com stats"
  - sha: 76ad8d1
    desc: "feat(48-04): cria BlocoFuncionario.jsx — counters diferenciados analista/estrategista"
  - sha: 5875ac3
    desc: "feat(48-04): integra NpsHistoryWidget e BlocoFuncionario no painel lateral"
---

# SUMMARY — Plan 48-04: Widget NPS History + BlocoFuncionario no painel lateral

## Status: COMPLETO

## O que foi feito

### Tarefa 1 — Componente NpsHistoryWidget.jsx

- Criado `resources/js/Components/Carteira/NpsHistoryWidget.jsx`.
- Recebe `npsHistory` (array `{month, avg, count, ultima_nota}`) e `cargoSlug`.
- Com dados: mini `LineChart` Recharts (80px, sem eixos, sem grid) com linha `ecf-yellow` (#ffe600) e pontos circulares pequenos. Tooltip customizado com mês + avg.toFixed(1) + count.
- Stats row em 3 colunas: **Última nota** | **Média** | **Avaliações**.
- Barra de progresso colorida (âmbar < 3, sky 3-4, emerald ≥ 4) em Última e Média.
- Escala de referência "1 — Ruim / escala 1-5 / 5 — Excelente".
- Empty state: "Sem avaliações no período." quando `npsHistory` vazio/null.
- Sem nova dependência — Recharts já no projeto.

### Tarefa 2 — Componente BlocoFuncionario.jsx

- Criado `resources/js/Components/Carteira/BlocoFuncionario.jsx`.
- Recebe `cargoSlug`, `sugadorCounters`, `ppaCounters`.
- **analista** + `sugadorCounters`: card "Sugadores" com grid 3 colunas (pendentes em âmbar/emerald por quantidade > 0, resolvidos em emerald, não resolvidos em red/white/50). Sub-texto "empresas da carteira em análise".
- **estrategista** + `ppaCounters`: card "PPAs" com grid 3 colunas (em andamento sky, concluídos este mês emerald, total white/60).
- **null / admin**: `return null` — não renderiza nada.
- Sub-componente local `CounterItem`: número grande (2xl bold) + label (10px white/45).

### Tarefa 3 — Integração no Show.jsx (painel lateral)

- Adicionados imports de `NpsHistoryWidget` e `BlocoFuncionario` no topo do arquivo.
- Adicionada prop `nps_history = []` no destructuring do `PortfolioShow` (após `ppa_counters`).
- Inseridos os dois componentes no `div.space-y-3` do painel lateral, após o card "Ações estratégicas":
  - `<NpsHistoryWidget npsHistory={nps_history} cargoSlug={cargo_slug} />`
  - `<BlocoFuncionario cargoSlug={cargo_slug} sugadorCounters={sugador_counters} ppaCounters={ppa_counters} />`

### Tarefa 4 — Safety-check de resíduos (sem alteração)

- `grep portfolio_goals Show.jsx` retorna apenas o comentário informativo já existente (nenhum resíduo funcional).
- Sem imports mortos: Dialog, Pencil, Trash2, Plus, Textarea, Label — todos já limpos no Plan 48-02.
- Nenhuma alteração necessária.

### Tarefa 5 — Build final

- `npm run build` concluído sem erros: `✓ built in 11.88s`.
- Bundle `Show-CajGumLe.js`: 36.94 kB / 9.85 kB gzip (crescimento esperado de 31.64 kB pós 48-03).
- Sem warnings de imports não utilizados.

## Verificações de Success Criteria

- [x] `NpsHistoryWidget.jsx` criado em `resources/js/Components/Carteira/` com mini LineChart Recharts (escala 1-5)
- [x] `BlocoFuncionario.jsx` criado com branching analista/estrategista
- [x] Painel lateral integra `NpsHistoryWidget` após "Ações estratégicas"
- [x] Painel lateral integra `BlocoFuncionario` após `NpsHistoryWidget`
- [x] Prop `nps_history` consumida (do Plan 48-01)
- [x] Sem resíduos de `portfolio_goals` no `Show.jsx` (apenas comentário informativo)
- [x] `npm run build` sem erros nem warnings de imports mortos
- [x] 3 commits isolados em pt-BR (Tarefa 1, 2, 3; safety-check sem commit — nenhuma mudança; build só verificação)
- [x] Não tocou em hero card (Plan 48-02)
- [x] Não tocou em KPIs / bloco diferenciado inline nos KPIs (Plan 48-02)
- [x] Não tocou em tabela de empresas e SparklineCrescimento (Plan 48-03)
- [x] Não tocou em modais (já removidos no Plan 48-02)
- [x] Comentários em pt-BR

## Desvios do Plan

| Desvio | Justificativa |
|--------|---------------|
| NpsHistoryWidget inserido ANTES de BlocoFuncionario (não como "primeiro item" — mas logo após Ações estratégicas) | O plan 48-04 especificou "após bloco Ações estratégicas", não "primeiro item do painel". O plano-mestre dizia "PRIMEIRO elemento do div.space-y-3" mas isso conflitaria com Top faturamento + Comparação contextual + Ações estratégicas que já existem. Seguiu-se a especificação mais detalhada do 48-04-PLAN.md (key_links): "Adicionar após bloco Ações estratégicas". |
| Tarefa 4 sem commit | Safety-check não encontrou nenhum resíduo a remover — commit seria vazio. |
| Tarefa 5 (build) sem commit | Build é verificação, não entrega de artefato. |

## Ordem final do painel lateral (div.space-y-3)

1. Top faturamento (Plan 48-02 — intocado)
2. Comparação contextual (Plan 48-02 — intocado)
3. Alertas estratégicos (Plan 48-02 — intocado)
4. **NpsHistoryWidget** ← Plan 48-04 (novo)
5. **BlocoFuncionario** ← Plan 48-04 (novo)
