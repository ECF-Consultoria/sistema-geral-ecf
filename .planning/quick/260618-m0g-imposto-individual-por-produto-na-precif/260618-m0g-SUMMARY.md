---
phase: quick-260618-m0g
plan: 01
subsystem: mlb-implementacao-publica
tags: [precificacao, imposto-individual, toggle, spreadsheet-grid]
dependency_graph:
  requires: []
  provides: [modo_imposto, imposto_individual, impostoEfetivo]
  affects: [resources/js/Pages/Mlb/ImplementacaoPublica.jsx]
tech_stack:
  added: []
  patterns: [helper-impostoEfetivo, toggle-modo-imposto, useMemo-condicional]
key_files:
  created: []
  modified:
    - resources/js/Pages/Mlb/ImplementacaoPublica.jsx
decisions:
  - "impostoEfetivo como helper local no PrecificacaoModal — único ponto de decisão de qual imposto entra no calcPreco"
  - "Coluna imposto_individual oculta no modo massa para reduzir ruído (discretion do CONTEXT)"
  - "headerGroups calculado condicionalmente (span 3 massa / span 4 individual)"
  - "SimuladorPreco recebe modoImposto e impostoEfetivo como props — sem elevar estado para o root"
  - "Avançado do Simulador: campo de imposto é condicional — edita global (massa) ou produto selecionado (individual)"
metrics:
  duration: "~20 min"
  completed: "2026-06-18"
  tasks: 2
  files: 1
---

# Quick Task 260618-m0g: Imposto individual por produto na Precificação — Sumário

**One-liner:** Toggle Massa|Individual persistido em `dados.modo_imposto` com coluna `imposto_individual` por produto no Lote e helper `impostoEfetivo` substituindo o imposto global nos cálculos do Lote e do Simulador.

## O que foi entregue

### Task 1 — Estado + campo + helper

- `modoImposto` lido de `dados.modo_imposto` com default `'massa'` (compatibilidade retroativa: precificações antigas sem a chave funcionam como massa).
- `setModo(novo)` persiste via `onSaveCfg('modo_imposto', novo)` → `onChange('precificacao', 'modo_imposto', novo, true)` → PATCH no controller sem whitelist.
- `imposto_individual: ''` adicionado ao `emptyRow`; o spread `...(existente[p.sku] ?? {})` em `mergeComPlanilha` já preserva o valor existente por SKU.
- `impostoEfetivo(row, tierDecimal)`: modo individual retorna `parseFloat(row.imposto_individual) / 100`; modo massa retorna o `tierDecimal` sem alteração — zero regressão garantida.

### Task 2 — UI + cálculos

- **Toggle** "Imposto: Massa | Individual" no cabeçalho do `PrecificacaoModal`, ao lado (não no lugar) do toggle de view Simulador|Lote. Estilo `bg-ecf-yellow text-black` no ativo, idêntico ao restante do app.
- **Coluna Lote** `imposto_individual` (`Imposto Ind. %`, type number, width 110) inserida após `custo`, antes do grupo Clássico — visível só no modo individual.
- **headerGroups** dinâmico: span do grupo de produto é 3 (massa, 13 colunas total) ou 4 (individual, 14 colunas); Clássico=5 e Premium=5 permanecem fixos.
- **8 computes** do Lote (anunciado/preço/LL/MC × Clássico/Premium) substituem `cc.imposto`/`cp.imposto` por `impostoEfetivo(row, cc.imposto)`/`impostoEfetivo(row, cp.imposto)`.
- **Simulador**: `impostoSimulador = impostoEfetivo(row, t.imposto)` alimenta `preco`, `impostoRs` e os chips do catálogo.
- **Avançado do Simulador**: modo massa mantém campo "Imposto %" editando o global via `updateCfg`; modo individual exibe "Imposto deste produto %" editando `row.imposto_individual` via `onEditProduto`.
- `modoImposto` adicionado ao array de deps do `useMemo` de `cols`.

## Commits

| Hash | Mensagem |
|------|----------|
| 29f671f | feat(quick-260618-m0g-01): estado modoImposto + campo imposto_individual + helper impostoEfetivo |
| ea6102a | feat(quick-260618-m0g-01): toggle Massa\|Individual + coluna Lote + calculos com impostoEfetivo |

## Deviações do plano

Nenhuma — plano executado exatamente como especificado.

## Verificação

- `node -e "..."` na Task 1: OK (modo_imposto, imposto_individual, impostoEfetivo presentes no código não-comentado).
- `npm run build` na Task 2: verde, sem erros ou avisos, 4429 módulos transformados.

## Known Stubs

Nenhum — o campo `imposto_individual` é editável pelo usuário no Lote (Task 2, coluna) e no Simulador (Avançado, Task 2), e o helper `impostoEfetivo` o usa diretamente nos cálculos.

## Threat Flags

Nenhum — nenhuma nova rota, endpoint ou caminho de auth foi introduzido. Alteração é puramente frontend (JSX).

## Self-Check: PASSED

- `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` existe e foi modificado.
- Commits 29f671f e ea6102a existem em `git log`.
- `npm run build` verde confirmado.
