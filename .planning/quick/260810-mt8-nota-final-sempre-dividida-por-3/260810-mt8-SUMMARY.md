---
quick_id: 260810-mt8
slug: nota-final-sempre-dividida-por-3
description: Nota final do desempenho sempre dividida por 3, indicador ausente = 0
date: 2026-08-10
status: complete
commits:
  - 3b320dce feat(desempenho) nota final passa a dividir sempre por 3
  - 0068bf2e test(desempenho) ajusta as contas ao divisor fixo e rotaciona o gate de hash
---

# Quick 260810-mt8 — SUMMARY

Item 2 da seção **Desempenho** do PDF "Demandas e Fluxos – Sistema ECF"
(Maycon): *"A pontuação final deverá ser calculada sempre dividindo a soma dos
indicadores por 3"*.

## O que mudou

`DesempenhoScoreService::computeNotaFinalPorIndicador()` promediava só os
indicadores presentes — dividia por 2 quando faltava um. Agora o divisor é a
constante `DIVISOR_NOTA_FINAL = 3` e o indicador ausente soma **zero**.

O zero entra no nível do **indicador**. O denominador independente **por loja**
dentro de cada indicador continua intocado: loja sem margem segue apenas fora
da média de margem, contando normalmente no faturamento e no NPS.

Carteira sem **nenhum** indicador continua com nota `null` — nunca 0.

## A consequência, explícita

Carteira **só-Shopee** passa a ter teto de `(5 + 0 + 5) / 3 = 3,33` e **nunca
alcança os 4,00** da primeira faixa de bônus. A Shopee não fornece CMV, então
a margem dessa carteira é estruturalmente ausente.

Isso foi perguntado ao Maycon com o caso na mesa, e ele escolheu a opção mais
severa das três oferecidas — a alternativa era o ausente entrar com 1,0 (piso
da régua).

Efeito nas três formulações, na mesma carteira só-Shopee do teste âncora:

| método | conta | nota |
|---|---|---|
| placeholder Shopee 1,0 (até 05/08) | (5,0 + 1,0 + 1,0)/3 | 2,33 |
| média dos presentes (05/08 → 10/08) | (5,0 + 1,0)/2 | 3,00 |
| **divisor fixo, ausente = 0 (hoje)** | (5,0 + 0 + 1,0)/3 | **2,00** |

## Payload preserva a distinção

`pontos_componentes` continua expondo `null` no indicador ausente. O payload
nunca fabrica o zero — senão "a carteira não tem esse indicador" ficaria
indistinguível de "tirou zero". Quem soma como zero é a nota e a conta na tela.

## Front

As três `formatContaNota` **homônimas e divergentes** (`lib/desempenhoLabels`,
`Performance/Index`, `Performance/Show` — a do Index devolve a sentinela
`'/ 5,00'`, as outras `null`) passam a mostrar as três parcelas sobre `/3`, com
o ausente como `0,00`. Novo `CONTA_NOTA_TOOLTIP` compartilhado explica o zero
nos três pontos onde a conta aparece (ranking, card do profissional, carteira
do admin) — sem isso a tela mostra um zero que se lê como nota ruim.

## Verificação

- Suítes Phase119 + Phase120 + Phase121 + Phase123 + V18 +
  DesempenhoShopeeScore: **208 passed, 9 failed**, e as 9 são **pré-existentes**
  — verificadas revertendo o código desta quick e da anterior, falham
  identicamente. Seis cobram o `calculated_fallback` local da margem e três o
  `componentes.var_margem_pct` pelo mesmo motivo (revogado em 2026-07-24).
- `npm run test:js` **148/149** — a falha é a do `estrutura-grade-glide`,
  obsoleta desde 17/07.
- `npm run build` verde. Bundle conferido **pelos dois lados**, aplicando a
  lição de 260807: o `/3` fixo com `?? 0` está presente nos três bundles
  resolvidos pelo manifest (`Index-Dsca37D5`, `Show-aYfmOlNY` e o chunk
  compartilhado `desempenhoLabels-CIm5B4Na`), o identificador
  `CONTA_NOTA_TOOLTIP` **não** sobrevive literal (prova de que o bundler
  resolveu o import — literal seria sinal de escopo vazado), e o padrão antigo
  `/${pts.length}` está **ausente** de todo o `public/build/assets/`.

## O que NÃO foi feito

- **Nenhuma competência reconsolidada.** Mês fechado lê snapshot congelado; a
  nota só muda quando alguém rodar `desempenho:consolidar-mes`, e isso mexe em
  pagamento. Quando for rodado, competências fechadas passam a refletir a regra
  nova — inclusive as já pagas.
- **Sem deploy** (não autorizado).
- 9 falhas pré-existentes deixadas como estão.

## Pendente da mesma demanda

Item 3 — marketplace da conta na tabela por empresa e no card do profissional.
