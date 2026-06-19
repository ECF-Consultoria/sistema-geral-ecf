---
phase: quick-260619-fq7
plan: 01
subsystem: frontend/polos
tags: [cartograma, svg, faturamento, polos, dark-theme]
dependency_graph:
  requires: []
  provides: [CityCartogram]
  affects: [resources/js/Pages/Polos/Index.jsx]
tech_stack:
  added: []
  patterns: [svg-escalado-por-sqrt, fillRule-evenodd, glow-feDropShadow, fallback-circular]
key_files:
  created:
    - resources/js/Pages/Polos/components/CityCartogram.jsx
  modified:
    - resources/js/Pages/Polos/Index.jsx
decisions:
  - "Escala de altura simples: heightPx = maxHeight * scaleVis (largura automática via viewBox) em vez de normalizar pelo aspect-ratio da maior cidade — suficiente para refletir área ∝ sqrt(fat)"
  - "Piso visual scaleVis = max(scale, 0.18) para cidades de muito menor faturamento não sumirem completamente"
  - "Estado vazio (maxFat=0): CityCartogram retorna null; a rose já exibe 'Sem faturamento'"
  - "IDs de filtro SVG únicos por polo (slug normalizado) para não colidir entre múltiplos svgs na mesma página"
metrics:
  duration: "2m39s"
  completed: "2026-06-19T14:30:55Z"
  tasks_total: 3
  tasks_completed: 3
  files_modified: 2
---

# Quick 260619-fq7: CityCartogram — cartograma de silhuetas de cidades escaladas por faturamento

**One-liner:** Novo primitivo `CityCartogram.jsx` — cada polo representado pela silhueta IBGE real escalada por `sqrt(fat/maxFat)`, preenchimento sólido na cor do polo com glow, empilhado abaixo da rose existente na seção de faturamento de `/polos`.

## O que foi construído

- **`CityCartogram.jsx`** (170 linhas): componente SVG que recebe `distrib.itens` (já ordenado por faturamento desc) e desenha cada cidade como sua silhueta real — obtida via `shapeForPolo(item.polo)` de `cityShapes.js` (zero duplicação de contornos). A cidade é escalada por `scaleVis = max(sqrt(fat/maxFat), 0.18)` — raiz quadrada para que a ÁREA cresça proporcional ao faturamento, não o lado linear. Preenchimento sólido `fill={cor}` com `fillRule="evenodd"` (respeita furos de São Bento do Sul), contorno + `feDropShadow` no estilo do `CityGauge`. Fallback circular escalado para polos sem contorno mapeado. Retorna `null` quando `maxFat === 0`.

- **`Index.jsx`**: import de `CityCartogram` adicionado junto aos demais imports de `./components`; render inserido abaixo da `RoseChart` e do "Total:" existente, com subtítulo discreto `"Por cidade (área ∝ faturamento)"`. A rose permanece intacta; nenhuma prop existente foi alterada.

## Commits

| Tarefa | Commit | Mensagem |
|--------|--------|----------|
| 1 — CityCartogram.jsx | `d32dc59` | feat(quick-260619-fq7): criar CityCartogram - cartograma de cidades por faturamento |
| 2 — Index.jsx | `2b010d6` | feat(quick-260619-fq7): renderizar CityCartogram abaixo da rose em Index.jsx |

## Verificação

- `npm run build` concluído com exit 0 em ~14s (Vite 7.3.2)
- Todos os 5 padrões obrigatórios encontrados no arquivo: `shapeForPolo`, `Math.sqrt`, `formatCurrency`, `fillRule`, `export default`
- `<RoseChart>` e `<CityCartogram>` ambos presentes em `Index.jsx`
- Nenhuma deleção acidental de arquivos rastreados

## Desvios do Plano

Nenhum — plano executado exatamente como escrito.

## Known Stubs

Nenhum. O componente consome `distrib.itens` que já é calculado em tempo real a partir dos dados reais do polo (via `useMemo` no `Index.jsx`).

## Threat Flags

Nenhum. Alteração 100% frontend (render SVG estático); sem novo endpoint, sem nova entrada de usuário não confiável, sem nova dependência npm.

## Self-Check: PASSED

- `resources/js/Pages/Polos/components/CityCartogram.jsx` — FOUND
- `public/build/manifest.json` — FOUND (build verde)
- Commits `d32dc59` e `2b010d6` — FOUND em `git log`
