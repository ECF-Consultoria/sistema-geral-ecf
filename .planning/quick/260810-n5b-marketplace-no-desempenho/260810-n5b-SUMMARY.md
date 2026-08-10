---
quick_id: 260810-n5b
slug: marketplace-no-desempenho
description: Mostrar o marketplace da conta no Desempenho
date: 2026-08-10
status: complete
commits:
  - 95e58467 feat(desempenho) mostra o marketplace da conta na tela
---

# Quick 260810-n5b — SUMMARY

Item 3 da seção **Desempenho** do PDF do Maycon. Ele escolheu dois lugares:
tabela por empresa e página do profissional.

## O achado que definiu a implementação

`companies.marketplace` — o campo de nome óbvio — **não serve**, e isso foi
medido antes de escrever qualquer linha:

```
companies.marketplace  → {"meli": 171}   (171 de 171)
company_marketplaces   → 0 linhas
```

É o marketplace da conta **Adman**, usado para montar a URL da API
(`/{marketplace}/accounts/...`), com default `'meli'`. Nunca foi populado com
Shopee, e a pivot da Fase 57 está vazia. Usá-lo diria "Mercado Livre" para toda
loja Shopee — exatamente o erro que a demanda quer corrigir.

A fonte correta já estava na tela, escrita como jargão: `fonte_financeira`,
derivada do **serviço contratado** em
`CarteiraContextService::flagsFinanceirasPorSetor()`:

| setor do vínculo | fonte_financeira | marketplace |
|---|---|---|
| `performance` | `adman` | Mercado Livre |
| `shopee` | `shopee` | Shopee |
| polos / publicação | `null` | (sem marketplace) |

E é a informação certa para esta tela: o marketplace de cuja métrica saiu a
pontuação daquela loja.

## O que ficou

- **Tabela por empresa** (`EmpresasScoreTabela`, usada por Performance/Show e
  pelo Relatório de Bonificação): a linha sob o nome da loja deixou de imprimir
  `ADMAN`/`SHOPEE` e passou a mostrar `Mercado Livre` / `Shopee`, com tooltip.
- **Página do profissional**: faixa `Marketplaces da carteira` com a composição
  (ex.: "Mercado Livre 18 · Shopee 4"), contada sobre `empresas_score` que a
  página já recebe — nada recalculado, nenhuma chamada nova.
- `marketplaceLabel()` e `composicaoPorMarketplace()` em `desempenhoLabels.js`,
  funções puras, com o comentário longo explicando por que a fonte é essa.

**Nuance registrada no tooltip:** empresa com os dois vínculos elegíveis
resolve `adman` pela regra de desempate do `CompanyScoreService` e aparece como
Mercado Livre. É o marketplace que produziu os números daquela linha, não uma
omissão.

**Carteira sem fonte financeira** (só Polos, só Publicação) não ganha faixa
vazia, e empresa sem fonte fica fora da contagem — um balde "sem marketplace"
competindo com os reais confundiria mais do que informa (a tabela já mostra
essas linhas com o motivo "Sem fonte financeira vinculada").

## Por que 100% front

`fonte_financeira` existe tanto no cálculo ao vivo quanto na **coluna do
snapshot congelado** (`CompanyScoreSnapshotWriter`), então a tradução vale
igual em mês corrente e em competência fechada.

Um campo novo exigiria **migration** em `desempenho_company_score_snapshots`
(o writer grava colunas fixas, não JSON) e não existiria nas competências já
congeladas. Sem backend ⇒ **sem bump de cache**, nenhum cálculo mudou.

## Verificação

- 7 testes novos em `tests/js/desempenhoLabels.test.js`: tradução, fonte
  desconhecida (nunca `undefined` nem slug cru), fonte nula, contagem ordenada,
  desempate por nome e lista vazia. Arquivo: 26/26.
- Suíte JS completa: **155/156** — a falha é a do `estrutura-grade-glide`,
  obsoleta desde 17/07.
- Gates estruturais de `Performance/Show` e `RelatorioBonificacao`: 14/14.
- `npm run build` verde, bundle conferido **pelos dois lados**: "Marketplaces
  da carteira" presente em `Show-DYp4KSrv.js`, "Mercado Livre" no chunk
  `desempenhoLabels-*`, os quatro identificadores novos
  (`composicaoPorMarketplace`, `marketplaceLabel`, `MARKETPLACE_TOOLTIP`,
  `ComposicaoMarketplaces`) com **zero** ocorrências literais em
  `public/build/assets/` (sobreviver à minificação seria sinal de escopo
  vazado — lição de 260807), e o padrão antigo da célula ausente.

## O que NÃO foi feito

- Sem deploy (não autorizado).
- Não foi populada a pivot `company_marketplaces` nem corrigido
  `companies.marketplace` — é outro trabalho (o comando
  `companies:backfill-marketplaces` existe), e nenhuma tela depende disso agora.
