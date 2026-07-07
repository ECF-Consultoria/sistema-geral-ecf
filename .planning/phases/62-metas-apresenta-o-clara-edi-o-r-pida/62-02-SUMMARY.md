---
phase: 62-metas-apresenta-o-clara-edi-o-r-pida
plan: 02
subsystem: goals-ui
tags: [react, componente-reusavel, recharts, meta-01, ui]
requires: []
provides:
  - "resources/js/Components/goals/GoalProgressPanel.jsx (default export)"
  - "5 data-testids estáveis para E2E do Plan 62-04 e 62-05"
affects:
  - "resources/js/Components/goals/ (novo diretório)"
tech_stack:
  added: []
  patterns:
    - "Componente presentational puro — props deterministic, sem fetch/router/side-effect"
    - "useMemo para ordenação + normalização de results"
    - "Espelhamento pt-BR ↔ PHP de LOWER_IS_BETTER"
    - "Recharts LineChart + ReferenceLine (padrão dark do Dashboard/Admin.jsx)"
key_files:
  created:
    - "resources/js/Components/goals/GoalProgressPanel.jsx"
  modified: []
decisions:
  - "Zero dependência nova — recharts, lucide-react, clsx, tailwind-merge já em package.json"
  - "Fallback status 'aproximando' quando percent é null (evita pintar vermelho por falta de dado)"
  - "Empty state e chart compartilham o mesmo wrapper com data-testid=goal-progress-panel para satisfazer gate literal '==1'"
  - "Tick formatter do YAxis usa `formatCurrency(v).replace('R$','')` para currency (economiza espaço horizontal)"
metrics:
  duration: "~35min"
  completed: 2026-07-07
---

# Phase 62 Plan 02: `<GoalProgressPanel />` Componente Reusável

Painel React reusável que apresenta uma meta + progresso mensal em uma única
área visual coesa (chart Recharts + percentual atingido + valor absoluto +
status), atendendo META-01. Zero fetch, zero navegação, 100% controlado por
props — consumido depois por `Companies/Show.jsx` (Plan 62-05) e
`Goals/Index.jsx` (Plan 62-04).

## Contrato final (prop signature)

```jsx
<GoalProgressPanel
  goal={{
    id, metric, metric_label, target_value,
    value_type,        // 'currency' | 'percentage' | null
    period_type,       // 'monthly' | 'quarterly' | 'yearly'
    description,       // opcional — rodapé em modo full
  }}
  results={[
    { id, period, realized_value, target_value, achieved }
  ]}
  compact={false}      // default false — grid denso usa true
  className=""
/>
```

## O que foi entregue

- **1 arquivo criado:** `resources/js/Components/goals/GoalProgressPanel.jsx`
  (novo diretório `goals/`).
- **Export:** `default GoalProgressPanel`.
- **Helpers puros internos:** `formatValue`, `computePercent`, `deriveStatus`,
  `formatPeriod` — todos determinísticos, tratam edge cases (target ≤ 0,
  valores NaN, results vazio, results fora de ordem).
- **Constantes:** `LOWER_IS_BETTER` (espelho PHP), `periodLabel`,
  `STATUS_STYLE`, `chartStyle` (padrão dark ECF do Dashboard/Admin).
- **Layout completo (compact=false):** cabeçalho (métrica + badge de período +
  status pill) → bloco central 2 colunas (Meta grande + Realizado; Atingido
  percentual grande) → chart (LineChart 160px) → descrição opcional.
- **Layout compact (compact=true):** cabeçalho + linha inline (percentual +
  realizado/meta) → sparkline (100px) → sem descrição.
- **Empty state:** ícone `<Target />` + "Sem dados de progresso ainda" +
  meta absoluta preservada — sem renderizar chart nem status pill.

## Confirmação dos grep gates (acceptance_criteria)

| Gate | Esperado | Resultado |
|------|----------|-----------|
| `data-testid="goal-progress-panel"` | == 1 | **1** OK |
| `data-testid="goal-progress-chart"` | == 1 | **1** OK |
| `data-testid="goal-progress-percentage"` | == 1 | **1** OK |
| `data-testid="goal-progress-status"` | == 1 | **1** OK |
| `data-testid="goal-progress-empty"` | == 1 | **1** OK |
| `from 'recharts'` | == 1 | **1** OK |
| `LOWER_IS_BETTER` (declaração + usos) | ≥ 2 | **3** OK |
| `router\|useForm\|axios\|fetch(` (não-comentário) | == 0 | **0** OK |
| Linhas úteis (não vazias, não comentários) | ≥ 100 | **213** OK |
| Export `default function GoalProgressPanel` | == 1 | **1** OK |

**Total de linhas do arquivo:** 275.

## Build

`npm run build` completou verde em **30.50s**. Nenhum warning novo relacionado
ao componente. Bundle `MetasPanel-4TL3KvdW.js` (39.80 kB / 12.14 kB gzip)
inclui o novo componente sem impacto negativo.

## Diff de dependências

`git diff --stat package.json package-lock.json` retornou **vazio** — zero
pacotes novos, alinhado à threat T-62-02-SC (accept).

## Decisões técnicas

1. **Fallback `deriveStatus`**: percent null → 'aproximando' (amarelo), não
   'distante' (vermelho). Motivo: pintar de vermelho por falta de dado
   induziria o Estrategista a agir sobre ruído.
2. **`data-testid="goal-progress-panel"` como wrapper único**: primeira versão
   tinha 2 wrappers (empty vs normal); refatorei para 1 wrapper + `isEmpty`
   ternário, satisfazendo gate literal `== 1` do plan.
3. **`percentEl` como variável compartilhada**: mesmo tratamento — evita
   duplicar `data-testid="goal-progress-percentage"` entre compact/full modes.
4. **Ordenação ASC no componente**: aceita results fora de ordem sem exigir
   contrato específico do consumidor (Plan 62-01 pode retornar DESC do backend).
5. **`ReferenceLine` amarela tracejada**: cor `ecf-yellow` fixa (independente
   do status) — marca semanticamente "a meta", separada da linha de tendência
   (verde/amarelo/vermelho).

## Deviations from Plan

Nenhum desvio significativo. Refatoração inicial (2 wrappers → 1 wrapper com
`isEmpty` ternário) foi puramente para satisfazer gate literal do plan sem
alterar comportamento.

## Threat Flags

Nada novo em relação ao `<threat_model>` — componente é presentational puro,
sem novos trust boundaries.

## Known Stubs

Nenhum stub. Todos os data flows estão wired via props.

## Self-Check: PASSED

- Arquivo `resources/js/Components/goals/GoalProgressPanel.jsx` existe (275 linhas).
- Todos os 10 grep gates passam.
- `npm run build` OK sem erros.
- Zero deps novas (`git diff` de package.json vazio).
