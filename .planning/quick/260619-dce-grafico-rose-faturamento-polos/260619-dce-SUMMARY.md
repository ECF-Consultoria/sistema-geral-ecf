---
phase: quick-260619-dce
plan: "01"
subsystem: frontend-polos
tags: [svg, gráfico, nightingale-rose, visualização, /polos]
dependency_graph:
  requires: []
  provides: [RosePie.jsx]
  affects: [resources/js/Pages/Polos/Index.jsx]
tech_stack:
  added: []
  patterns: [SVG puro, nightingale rose, leader lines, SVG filter glow]
key_files:
  created:
    - resources/js/Pages/Polos/components/RosePie.jsx
  modified:
    - resources/js/Pages/Polos/Index.jsx
decisions:
  - RosePie usa viewBox expandido (margens laterais = 0.52*R) para garantir que labels/leader lines nunca são cortadas
  - Raio interpolado linearmente entre rMin=0.42*R e rMax=0.92*R para distinção visual clara entre menor e maior fatia
  - Glow implementado via feGaussianBlur+feMerge no SVG (não CSS drop-shadow) para compatibilidade cross-browser em SVG inline
  - ID do filtro gerado por Math.random para isolar instâncias múltiplas do componente na mesma página
  - Fatias com value=0 descartadas via filter para evitar arcos degenerados (divisão por zero no ângulo)
metrics:
  duration: "~8 min"
  completed: "2026-06-19"
  tasks_completed: 3
  files_changed: 2
---

# Quick 260619-dce: Gráfico Rose de Faturamento em /polos — SUMMARY

**One-liner:** Pizza nightingale rose SVG puro substituindo Pie3D na seção de faturamento de /polos, com raio proporcional ao valor, leader lines multicor e glow suave, sem nenhuma nova dependência.

## Tarefas Executadas

| # | Nome | Commit | Arquivos |
|---|------|--------|---------|
| 1 | Criar RosePie.jsx (nightingale SVG puro) | `742f85a` | `resources/js/Pages/Polos/components/RosePie.jsx` (criado) |
| 2 | Trocar Pie3D por RosePie na seção de faturamento | `f23823a` | `resources/js/Pages/Polos/Index.jsx` (import + uso) |
| 3 | Build de validação (npm run build) | sem commit | Build passou em 10.07s, sem erros |

## Resultado do Build

`npm run build` concluiu com sucesso:
- `RosePie-BeGFSNys.js` gerado (2.75 kB / gzip 1.39 kB)
- Zero erros ou avisos de compilação
- 4432 módulos transformados em 10.07s

## Implementação

O componente `RosePie.jsx` implementa a visualização nightingale rose em SVG puro:

- **Geometria:** Ângulo e raio de cada fatia proporcionais ao valor. O raio varia linearmente de `rMin=0.42*R` (menor fatia) a `rMax=0.92*R` (maior fatia). Começa do topo (−90°) replicando o demo ECharts.
- **Leader lines:** Segmento radial saindo do raio externo + perna horizontal (esquerda/direita conforme o lado). Labels com nome do polo + valor BRL + percentual, todos em tom esmaecido (rgba branco ~0.35–0.70).
- **Glow/fundo:** Retângulo arredondado escuro sob o disco; filtro SVG `feGaussianBlur+feMerge` aplicado ao grupo de fatias para glow suave de sombra.
- **Filete separador:** `stroke="rgba(5,5,7,0.5)"` com `strokeWidth=1.5` e `strokeLinejoin="round"` entre fatias, mesmo estilo do Pie3D.
- **Cores:** Exclusivamente de `slices[].color` (POLO_PALETTE multicor) — nenhuma cor hardcoded.

## Escopo Cirúrgico Mantido

- A seção "Distribuição de status" (linhas 282-322 do Index.jsx) continua usando `<Pie3D>` intacto.
- O import de `Pie3D` foi preservado (linha 7 do Index.jsx).
- DonutCards, filtros de polo, drawer de empresas e todos os KPIs permanecem inalterados.
- Nenhuma nova dependência adicionada ao `package.json`.

## Desvios do Plano

Nenhum — plano executado exatamente como escrito.

## Known Stubs

Nenhum stub identificado — o componente recebe dados reais via `distrib.itens` (prop do controller).

## Threat Flags

Nenhuma nova superfície de segurança introduzida — alteração puramente frontend (SVG rendering).

## Self-Check: PASSED

- [x] `resources/js/Pages/Polos/components/RosePie.jsx` existe (215 linhas, exporta `default function RosePie`)
- [x] `resources/js/Pages/Polos/Index.jsx` importa RosePie (linha 8) e usa `<RosePie>` na seção de faturamento (linha 248)
- [x] `<Pie3D>` ainda presente na seção de status (linha 289)
- [x] Import de Pie3D preservado (linha 7)
- [x] Commit `742f85a` — feat(quick-260619-dce): RosePie criado
- [x] Commit `f23823a` — feat(quick-260619-dce): Index.jsx atualizado
- [x] `npm run build` passou sem erros (`✓ built in 10.07s`)
