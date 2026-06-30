---
phase: 46-historico-longitudinal-scores-desempenho
plan: 46-03
status: complete
completed_at: 2026-06-30
wave: 3
type: execute
depends_on: ["46-02"]
requirements:
  - REQ-46-03
files_modified:
  - resources/js/Pages/Performance/Index.jsx
files_created: []
commits:
  - cb3dfa5 feat(46-03) ScoreDelta local + micro-indicadores de delta no ranking de /performance
  - a38d861 feat(46-03) EvolucaoDrawer com Recharts LineChart + mediana do grupo no /performance
build:
  result: success
  duration_s: 17.82
provides:
  - "RankingConsultoria exibe micro-deltas hoje/semana abaixo do ScorePill (paleta emerald/red/cinza)"
  - "Click no nome do user abre EvolucaoDrawer (max-w-2xl) com LineChart de 30 dias"
  - "Mediana do grupo (linha pontilhada cinza) calculada client-side via Promise.all"
  - "ChevronRight ainda navega para portfolio.show (preservado)"
next:
  - "Wave 4 (46-04): UAT humano em produção após deploy do schedule 13:30 BRT"
---

# Phase 46 Plan 46-03 — UI de deltas + drawer Evolução: SUMMARY

## Entregue

Camada UI da Phase 46: micro-indicadores de delta visíveis na linha de cada
profissional do `/performance` + drawer lateral com gráfico de evolução individual
de 30 dias comparada à mediana do grupo. Tudo dentro do mesmo arquivo
`Performance/Index.jsx` (sem componentes reusáveis globais — convenção do projeto).

## Commits (2)

| SHA       | Tipo | Mensagem                                                                                  |
| --------- | ---- | ----------------------------------------------------------------------------------------- |
| `cb3dfa5` | feat | `ScoreDelta` local + coluna Score reformatada com 2 deltas abaixo do `ScorePill`          |
| `a38d861` | feat | `EvolucaoDrawer` + estado `userSelecionado` + ESC handler + ChevronRight preservada       |

## Arquivos modificados

- `resources/js/Pages/Performance/Index.jsx` — +250 linhas / −8 linhas
  - Imports: `useState`, `useEffect`, `X`, `LineChart`, `Line`, `XAxis`, `YAxis`, `Tooltip`, `ResponsiveContainer`, `Legend`
  - Componente local `ScoreDelta({ delta, label })` — paleta espelha `SparklineCrescimento.jsx:22-49`
  - Componente local `EvolucaoDrawer({ rankingItem, allRankingIds, onClose })` — espelha `PoloDrawer` com `max-w-2xl`
  - Estado `userSelecionado` + `useEffect` para ESC fechar drawer
  - `RankingConsultoria` aceita prop `onSelectUser`; click no nome abre drawer; ChevronRight vira botão separado para portfolio
  - Coluna Score agora é `flex flex-col` com `ScorePill` + 2 `ScoreDelta` (hoje, sem.)

## Arquivos novos

Nenhum. Plano explicitamente pede componentes locais no mesmo arquivo.

## `npm run build`

```
✓ built in 17.82s
```

Sem erros, sem warnings críticos.

## Visual / comportamento final

- **Ranking:** cada linha mostra `ScorePill` (badge + número) + abaixo `↑ +2.3 hoje · ↑ +5.1 sem.`
- **Pré-deploy do schedule:** ambos os deltas aparecem como `hoje: —` e `sem.: —` em `text-white/20`
- **Click no nome do profissional:** abre drawer lateral à direita
  - Header sticky: nome + cargo_label + score atual
  - KPI cards: "Score hoje" (ecf-yellow grande) e "vs ontem" (com `TrendingUp` lucide rotacionado para baixo se negativo)
  - LineChart 280px de altura: linha sólida `#ffe600` (user) + linha pontilhada `rgba(255,255,255,0.4)` (mediana)
  - Estado loading: pulse + texto "Carregando histórico..."
  - Estado vazio: "Sem histórico ainda — snapshots começam a partir do próximo 13:30 BRT"
- **Fechar:** click no backdrop OU botão X OU tecla ESC
- **ChevronRight:** preserva navegação para `/admin/users/{id}/portfolio` (botão separado, `e.stopPropagation()`)

## Patterns reutilizados

| Pattern                         | Origem                                                    |
| ------------------------------- | --------------------------------------------------------- |
| Paleta delta (emerald/red/grey) | `Carteira/SparklineCrescimento.jsx:22-49`                 |
| Estrutura do drawer             | `Polos/Index.jsx:399-436` (PoloDrawer) com `max-w-2xl`    |
| KPI card no header              | `PainelExecutivo/components/KpiCard.jsx:101-114` (TrendingUp/Down + ecf-yellow) |
| Fetch sob demanda em useEffect  | NotificacaoController consumption (research §5)           |

## Desvios do plano

Nenhum desvio funcional. 2 ajustes técnicos pequenos:

1. **`label` opcional no `ScoreDelta`** — no header do drawer chamo `<ScoreDelta delta={...} label="" />` mas optei por inline JSX (`TrendingUp` rotacionado) em vez de usar o componente, porque a tipografia do KPI card precisa ser maior (`text-2xl` vs `text-[10px]` do componente). Mantém consistência visual com KpiCard.

2. **Botão dedicado para ChevronRight** — o plano deixava como Claude's discretion. Optei por transformar a célula da seta num `<button>` com `e.stopPropagation()` + `hover:bg-white/[0.06]` para acessibilidade (Tab funciona) e affordance visual. A row já não tem mais `onClick`, apenas as células do nome e da seta.

## success_criteria — status

- [x] `ScoreDelta` definido localmente em `Performance/Index.jsx` (não em `Components/ui`)
- [x] Cada linha do ranking de consultoria exibe `ScorePill` + `delta_vs_ontem` + `delta_vs_semana_passada`
- [x] Deltas null renderizam `—` em cinza claro (não 0, não bug)
- [x] Click no nome do user abre `EvolucaoDrawer` com `max-w-2xl`
- [x] `EvolucaoDrawer` faz fetch paralelo: 1 do user + N dos outros do ranking
- [x] Mediana do grupo calculada client-side por data
- [x] LineChart Recharts mostra linha sólida amarela (user) + linha pontilhada cinza (mediana)
- [x] Estado de loading exibido enquanto fetches estão em andamento
- [x] Estado vazio (sem snapshots ainda) exibido graciosamente
- [x] `npm run build` verde (17.82s)
- [x] ChevronRight ainda navega para `portfolio.show` (preservado via botão separado com stopPropagation)
- [x] ESC fecha o drawer (useEffect com keydown listener)
- [x] Sem regressão em `RankingPolos` (não foi tocado)

## Próximo

Wave 4 — Plan 46-04: UAT humano em produção após deploy do schedule 13:30 BRT.
