---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 12
subsystem: admin-estrutura-geracao-ui
tags: [admin, react, devcard, tipos, pares]
requires: ["168-09"]
provides:
  - "Página Dev/EstruturaGeracao (tipos e pares em listas e janelas)"
  - "DevCard compartilhado em Components/Dev/DevCard.jsx"
  - "lib estruturaGeracaoAdmin.js"
key-files:
  created:
    - resources/js/Pages/Dev/EstruturaGeracao.jsx
    - resources/js/Components/Dev/DevCard.jsx
    - resources/js/lib/estruturaGeracaoAdmin.js
    - tests/js/estrutura-geracao-admin.test.js
  modified:
    - resources/js/Pages/Dev/Desenvolvimento.jsx
requirements: [PR168-14]
completed: 2026-10-07
---

# Phase 168 Plan 12: Tela admin "Tipos e pares" Summary

A ECF mantém tipos de produto e pares (com a direção do Combit) por uma tela de listas e janelas em `/dev/estrutura-geracao`, aberta por um link em `Dev/Desenvolvimento`, sem item novo no menu.

## Commits

- `182a3478` lib de formatação + `DevCard` extraído + link em `Desenvolvimento.jsx` + teste
- Task 2: commit `feat(168): tela de admin de tipos e pares das sugestões` (página + gate da página no teste); `d84e9aae`

## Comportamento

- `DevCard` movido sem mudar o markup (gate trava as classes originais).
- Janela do par: rótulos "Repetir {tipo}" mudam ao vivo; mesmo tipo nos dois lados permitido; erro de par repetido (`tipo_b_id`) aparece inline.
- Erros do servidor em `text-red-300` sob o campo; flash de sucesso vem do `AppLayout`.
- Sem planilha, sem amarelo fora das janelas.

## Verificação

- `node --test tests/js/estrutura-geracao-admin.test.js`: 10 testes OK.
- `npm run test:js`: 1160 testes, 2 falhas (o piso antigo).
- `AdminTiposEParesTest`: 18 testes OK.
- Build: mtime do manifest passou de 1791312847 para 1791382419 (início do build 1791382381); `resources/js/Pages/Dev/EstruturaGeracao.jsx` presente no manifest. `public/build` não commitado.

## Deviations from Plan

**1. [Rule 3] Não existia "cartão de ferramentas" em Desenvolvimento.** O link foi colocado num `DevCard` novo "Sugestões de ofertas", no mesmo estilo das linhas vizinhas (`LinkRow`).

**2. `DevControllerTest` não rodado como critério.** Tem 5 falhas antigas sem relação (documentado no 168-09); só o `AdminTiposEParesTest` foi usado.

**3. Teste:** um caso meu errou ("Colchão" termina em "o" e leva artigo); corrigido no teste, não na lib.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos criados presentes; build provado pelo manifest.
