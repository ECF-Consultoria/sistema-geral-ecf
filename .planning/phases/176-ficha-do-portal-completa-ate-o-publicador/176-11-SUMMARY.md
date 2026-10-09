---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 11
subsystem: publicador-front
tags: [publicador, descricao-ia, react]
requires: [176-09]
provides: [useDescricaoIa, descricaoIa.js, painel Descrição do cliente]
key-files:
  created:
    - resources/js/Components/Publicador/descricaoIa.js
    - resources/js/Components/Publicador/useDescricaoIa.js
    - tests/js/publicador-descricao-ia.test.js
  modified:
    - resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
    - tests/js/publicador-mesa.test.js
    - tests/js/publicador-editor.test.js
requirements-completed: [FP176-08]
---

# Fase 176 Plano 11: Descrição do cliente e descrição por IA no editor

O editor mostra a descrição do cliente (recolhível, só leitura), dispara sozinho a descrição MAG T8 no rascunho vazio e oferece o botão de gerar/regerar, aplicando sempre por `m.mudarRasc`.

## Entregue
- `descricaoIa.js`: `deveDispararAuto` e `podeAplicarDescricao` (puras).
- `useDescricaoIa.js`: pede (202 `rodando`; `ja_pedido`/`nao_se_aplica` voltam a "parado"), dispara automático uma vez por montagem, acompanha a cada 2,5 s por até 5 min, só aceita o próprio `pedido`; em "pronto" aplica por `mudarRasc` se `podeAplicarDescricao`, senão guarda o valor para "Usar a descrição gerada".
- `EtapaDetalhes.jsx`: `<details>` "Descrição do cliente" (`data-descricao-cliente`, texto como nó React), botão `gerar-descricao-ia`, link `usar-descricao-ia`, erro em texto.
- `Editor.jsx`: chama `useDescricaoIa` junto dos efeitos da página (vale em qualquer etapa).
- `usePublicador.js` e `ferramentas.js` intocados.

## Testes
- `publicador-descricao-ia`, `-mesa`, `-editor`, `-ferramentas`: 208 testes, todos verdes.
- `npm run test:js`: 1325 testes, 1323 passam; 2 falhas são as antigas conhecidas ("Características secundárias nasce recolhido", "FASES_TERMINAIS...").
- `npm run build`: ok; `Pages/Mlb/Publicador/Editor.jsx` no manifest.

## Desvios
- [Rule 3] Gates de fonte antigos barravam `<details` em `EtapaDetalhes` e exigiam `<EtapaDetalhes m={m} />` exato. Ajustei `publicador-mesa.test.js` (a proibição de `<details` vale só antes de `function Descricao`, onde mora o novo recolhível) e `publicador-editor.test.js` (aceita a prop `descricaoIa`). Ficha técnica continua sem nada recolhido.
- Classes do painel ajustadas aos gates de tipografia (11/13px) e peso (sem `font-medium`).

## Fora do escopo / a decidir
- Nada de prova real (a IA roda na fila; não testada em browser/#459).

## Commits
- fb1b52b7 feat(176-11): regras puras e hook useDescricaoIa
- (task 2) feat(176-11): painel Descrição do cliente e botão Gerar descrição com IA no editor

## Self-Check: PASSED
