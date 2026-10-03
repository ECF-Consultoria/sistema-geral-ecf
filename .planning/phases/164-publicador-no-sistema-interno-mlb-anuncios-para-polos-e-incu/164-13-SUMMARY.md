---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 13
subsystem: frontend
tags: [publicador, editor, mesa-de-anuncio, ia, react]
requires: [164-05, 164-11, 164-12]
provides: [Pages/Mlb/Publicador/Editor, BarraDoEditor, BotaoAnunciarPorIa, FaixaDeProdutos, LateralValidacao, LateralResumo]
affects: [164-14]
key-files:
  created:
    - resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx
    - resources/js/Components/Publicador/Mesa/BotaoAnunciarPorIa.jsx
    - resources/js/Components/Publicador/Mesa/FaixaDeProdutos.jsx
    - resources/js/Components/Publicador/Mesa/LateralValidacao.jsx
    - resources/js/Components/Publicador/Mesa/LateralResumo.jsx
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
  modified:
    - tests/js/publicador-editor.test.js
decisions:
  - "BotaoPublicar é exportado de BarraDoEditor e reaproveitado pela lateral: o gradiente amarelo existe em um único arquivo"
  - "A lateral é uma única árvore React: em < 1360px vira card recolhido (hidden, não desmontado), assim a nota nota-conta-travada sempre existe para o aria-describedby"
  - "Cards escritos explicitamente com m={pub.m} (7 linhas), sem map, para o gate de fonte"
metrics:
  completed: 2026-10-02
---

# Fase 164 Plano 13: Editor do Publicador interno (mesa de anúncio) Summary

Página `Mlb/Publicador/Editor` compondo barra de 56px, faixa de produtos, os 7 cards e a lateral (Validação + Resumo) sobre `usePublicador`/`useIaDoPublicador`, com "Anunciar por IA" e o estado calmo de conta não liberada (D21/D26).

## Commits

- `736c3690` barra do editor, Anunciar por IA e faixa de produtos
- `06d99260` lateral (validação e resumo), página Editor.jsx e gates

## O que foi feito

- **BarraDoEditor**: sticky `h-14`, trilha, chip da empresa (Lock se não liberada, "ML conectado"/"Reconectar"), salvamento `aria-live`, IA, Conferir, Publicar. Publicado: "Publicado no Mercado Livre" + "Voltar aos produtos". Conta não liberada: "Conferir dados" com title literal e `aria-describedby="nota-conta-travada"`; publicar desabilitado com Lock.
- **Um só amarelo sólido**: `BotaoPublicar` primário só na barra quando `primarioNaLateral=false`; em ≥ 1360px (matchMedia, listener limpo no unmount) o primário é o do Resumo.
- **BotaoAnunciarPorIa**: confirmação (Dialog) só com `rascunhoPreenchido`; `pub.descarregar()` antes de disparar.
- **FaixaDeProdutos**: `aria-current`, setas do teclado, "Ver todos" (Popover radix de 360px, acima de 12), "+ Produto" abre `ModalNovoProduto`; trocar descarrega o pendente e faz `router.get`.
- **LateralValidacao**: 8 linhas de `SECOES`, barra de 4px, nota "Falta pouco"/"Tudo pronto". D26: conferência local em `AvisoContaTravada variante="linha"`, pendências locais com ponto âmbar e "Ir para {card}", sem vermelho, sem "Li os avisos".
- **LateralResumo**: pares do resumo, apoio por estado (D26 incluso), nota de conta travada, andamento por item (LinkMl, reenviar descrição, plano_b).
- **Editor.jsx**: faixas de IA (azul/vermelha), aviso/erro do hook, token expirado (com `LinkReconexao`), erro de abertura literal, esqueleto enquanto não há estado; sem abas e sem rodapé fixo.

## Verificação

- `node --test tests/js/publicador-editor.test.js`: 29/29.
- `npm run test:js`: 625 testes, 623 passam, 2 falham (baseline: "Características secundárias nasce recolhido (é o grupo que mais infla)" e "FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha"). Nenhuma falha nova.
- `npm run build`: exit 0; manifest com mtime novo (14:58); `Editor.jsx` -> `assets/Editor-DGIqtHBz.js` existente; `Produtos.jsx` e `AnunciosEmpresas.jsx` seguem no manifest.
- `tests/Feature/Publicador/MlbPublicadorProdutosTest.php` (renderiza a casca): 12 testes, OK.

## Deviations from Plan

- Nenhuma de regra. Detalhes de implementação: chip "ML conectado"/"Reconectar" é inline na barra (o `SeloConta` existente diz "Conectada"); reenviar descrição chama a rota direto e usa `pub.recarregar()` (o contrato `m` não tem ação para isso).
- O `top` da barra não foi ajustado: o `AppLayout` tem o cabeçalho de 60px fora do `<main>` (que é o contêiner de scroll, com `p-6`); a página usa `-m-6` para a barra colar no topo do scroll e `sticky top-0`. Lateral sticky em `top-[80px]`.

## Pendente para o 164-14 (precisa de navegador)

Nada foi conferido visualmente. Verificar: barra realmente a 56px e colada no topo do scroll (efeito do `-m-6`); sticky da lateral a 80px; quebra em 1360px (lateral colapsada vs. coluna); setas e rolagem horizontal da faixa; Popover "Ver todos"; confirmação da IA; conta não liberada (Lock, "Conferir dados", nota, conferência local calma); foco/teclado.

## Known Stubs

Nenhum.

## Self-Check: PASSED
