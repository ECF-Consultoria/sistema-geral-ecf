---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 04
subsystem: frontend
tags: [spreadsheet-grid, paste, tab, picker, inertia]
requires: ["167-01"]
provides:
  - "SpreadsheetGrid com props opcionais (growOnPaste, tabWrap, makeRow, rowKey, onRowsCommit, variant, rowActions, rowNote, selecionar...) e coluna type 'picker'"
  - "resources/js/lib/gradeTeclado.js: lerTsvDoExcel e proximaEditavel (puras)"
affects: ["167 telas de Produtos (planos seguintes)", "Onboarding publico (sem mudanca de comportamento)"]
tech-stack:
  added: []
  patterns: ["extensao aditiva com default igual ao anterior", "logica de teclado pura e testada em lib"]
key-files:
  created:
    - resources/js/lib/gradeTeclado.js
    - tests/js/grade-teclado.test.js
    - tests/js/estrutura-grid-produtos.test.js
  modified:
    - resources/js/Components/SpreadsheetGrid.jsx
key-decisions:
  - "Toda emissao de linhas passa por emitir(), que chama onRowsCommit?.(prev, next); undo/redo/import/+10 linhas tambem"
  - "Colar com growOnPaste usa o evento DOM paste e le tudo de ctx.current (listener de montagem unica)"
  - "Com filtro/ordenacao ativos, linhas coladas alem do exibido vao para o fim de rows (campo oi explicito no applyMulti)"
  - "Tab/Enter que criam linha so valem fora de visao filtrada/ordenada; la o cursor fica parado"
requirements-completed: [PR167-07]
duration: ~45min
completed: 2026-10-05
---

# Phase 167 Plan 04: Extensoes do SpreadsheetGrid Summary

Grade compartilhada estendida de forma aditiva: colar do Excel cresce as linhas (70 linhas gravam 70, nao 10), Tab corre so pelas colunas editaveis criando linha no fim, coluna `picker` com popover ancorado, aparencia "portal", acoes/nota por linha e aviso de linhas alteradas.

## Tarefas

| Tarefa | Commit | Resultado |
| ------ | ------ | --------- |
| 1. Colar crescendo, makeRow, onRowsCommit, tabWrap | `f9ec3997` | gradeTeclado.js (puras) + props + gates |
| 2. Picker, variant portal, rowActions/rowNote, selecionar | `3984b6a1` | picker, aparencia, a11y + build real |

## Verificacao

- `node --test` dos 3 gates (grade-teclado, estrutura-grid-produtos, estrutura-grid-textarea): 25 de 25.
- `npm run test:js`: 977 passam, 2 falham, ambas do baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`).
- `npm run build`: saiu 0 e buildou de verdade (manifest.json de 11:00 para 16:58, contem ImplementacaoPublica).
- `ImplementacaoPublica.jsx` nao foi tocado e nao passa nenhuma prop nova (gate).

## Deviations from Plan

Nenhuma de regra 1-4. Ajustes de detalhe: o gate de "emissao unica" conta chamadas diretas de onChange apenas dentro do emitir (regex restrita a `onCh(`, `ctx.current.onChange(` e `onChange(n|newRows)`), porque `onChange(` aparece tambem em componentes locais (TagsInput, TextareaPopup).

## Verificacao visual pendente

Nao foi feita conferencia no navegador (colar real do Excel, popover do picker, aparencia portal): ainda nao ha pagina que use as props. Fica para o plano que montar a tela de Produtos.

## Known Stubs

Nenhum.

## Self-Check: PASSED
