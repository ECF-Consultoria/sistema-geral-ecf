---
quick_id: 261007-ifa
slug: medida-de-embalagem-nao-e-medida-do-produto
type: quick
date: 2026-10-07
autonomous: true
---

# Medida de embalagem não pode alimentar a imagem de "Dimensões"

Achado durante a verificação da Fase 169, em produção, antes de qualquer deploy.

`CreativeSlotCatalog::PADRAO_ID_DIMENSAO` é
`/_(WIDTH|HEIGHT|LENGTH|DEPTH)$|^(WIDTH|HEIGHT|LENGTH|DEPTH)$/`, que **casa
`SELLER_PACKAGE_WIDTH`, `SELLER_PACKAGE_HEIGHT` e `SELLER_PACKAGE_LENGTH`** —
medidas da CAIXA, não do produto.

Medido no rascunho 8 em produção (Mesa de Centro Base Pirâmide): os únicos
atributos de medida são `SELLER_PACKAGE_*`, todos **12 cm**, peso 4000 g. Com
isso o slot `dimensions` é o **2º na fila de prioridade** e entraria no kit —
produzindo uma imagem com guia de medidas anunciando **12 × 12 × 12 cm para uma
mesa de centro**.

O modelo obedeceria com confiança, a imagem sairia coerente consigo mesma e
passaria pela revisão humana sem levantar suspeita. É exatamente a classe de
falha de TRUTH-02/03 que já custou caro (o caso dos "quatro pés" num produto de
cinco): **número errado no prompt é pior que número nenhum.**

## Tarefa 1 — Medida de produto e medida de embalagem deixam de ser a mesma coisa

Separar os dois no `CreativeSlotCatalog`: id de embalagem/frete não satisfaz
`dimensions`. Conferir no código quais prefixos o Mercado Livre usa de fato
(`SELLER_PACKAGE_*` é o medido; avaliar `PACKAGE_*`, `SHIPPING_*` e afins) e
preferir uma lista fechada de exclusão a uma heurística de substring — a
convenção deste arquivo é padrão fechado, nunca busca livre.

⚠️ Não quebrar o caminho legítimo: produto que TEM medida própria
(`WIDTH`/`HEIGHT`/`DEPTH`/`LENGTH` ou `*_WIDTH` que não seja de embalagem)
continua elegível. E `medidasConfirmadas` (fato humano da Fase 169) continua
valendo como antes.

## Tarefa 2 — Teste que falharia com o código de antes

Caso literal medido em produção: atributos só `SELLER_PACKAGE_WIDTH/HEIGHT/LENGTH`
= 12 cm → `dimensions` NÃO elegível. E o complemento: com medida de produto de
verdade → elegível.
