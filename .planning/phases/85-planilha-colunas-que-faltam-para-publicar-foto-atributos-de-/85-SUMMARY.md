---
phase: 85-planilha-colunas-que-faltam-para-publicar-foto-atributos-de-
date: 2026-07-15
status: complete
requirements: [COL-85-1, COL-85-2, COL-85-3, COL-85-4]
one-liner: "As colunas que faltavam para o anúncio publicar (foto, atributos obrigatórios escondidos, características secundárias) + validação antes de chamar a API"
commits:
  - 2dba12a feat(85) foto por URL, atributos obrigatorios escondidos e validacao previa
  - 6647243 feat(85) caracteristicas secundarias da categoria na grade em massa
deployed: 2026-07-15 (deploy parcial) — VALIDADO PELO USUÁRIO em prod ("agora deu certo")
---

# Fase 85 — As colunas que faltavam — Summary

Motivada por **erro 400 real de produção**, reportado pelo usuário com o retorno cru do ML:

```
item.attributes.missing_required      → [COLOR, SIZE] required for MLB108791
item.listing_type_id.requiresPictures → pictures mandatory for listing type gold_pro
shipping.lost_me1_by_user             (warning — não bloqueia)
item.shipping.mandatory_free_shipping (warning — não bloqueia)
```

**Resultado: o usuário confirmou que passou a publicar.**

## As duas causas — ambas de "coluna que não existia"

**1. Foto (`COL-85-1`).** `montarPayloadLinha` tinha `pictures: []` **hardcoded**: a grade publicava
todo anúncio sem foto nenhuma. Premium não publica sem foto → **todo Premium do lote falhava 100%
das vezes**, independente do que fosse preenchido.
Solução: 6 colunas de URL (capa + 5). **Zero backend** — o wizard já publicava com
`pictures: [{ source }]` (`AnunciarML.jsx:1568`) e o `ItemBuilderBase` já aceitava; era só o
frontend que cravava vazio. Campos **planos** (`imagemUrl`…`imagemUrl6`), não array: `editarCelula`
escreve `{ ...l, [campo]: valor }`, e um array exigiria um terceiro modo de escrita — com ele,
paste, fill, Delete e o undo/redo da Fase 84 teriam que aprender o caso. Planos = tudo funciona sem
ramo novo.
`linhaDeRascunho` ganhou **round-trip** de `pictures`: sem ler de volta, reabrir a página perdia as
URLs e a linha voltava a publicar sem foto **em silêncio**.

**2. Atributos obrigatórios escondidos (`COL-85-2`).** `colunasCategoria` filtrava
`tags.allow_variations !== true` **mesmo quando `required === true`**. Correto no wizard (lá esses
atributos vão dentro de cada variação), errado na grade — que é 1 linha = 1 anúncio **simples**.
COLOR/SIZE sumiam da planilha e o ML os exigia. O próprio erro apontava a saída: *"present in the
attributes list OR in variation's attribute_combination"*.
**O wizard não regride:** ele usa `meta.atributos`; a grade usa `massa.colunas` — endpoints
separados (provado por grep). GRID continua fora (não é valor que se digita numa célula).

**3. Características secundárias** (pedido do usuário no meio da fase: *"tudo que tiver no
individual tem que ter no em massa"*). O backend só devolvia os `required` — os atributos opcionais
**nunca chegavam**. O ML pede esses campos e eles pesam na qualidade/busca; o wizard já os oferecia
desde a quick `260714-kp4`. Novo array `opcionais` no payload, com filtro espelhando o do wizard
(`AnunciarML.jsx:1258`).

## Validação prévia (`COL-85-3`)

`errosLocaisLinha` — **fonte única** compartilhada pelo realce da grade, pela `PublishBar` e pelo
`linhaPublicavel` — passou a cobrir o que o ML exige de fato: foto quando `tier = gold_pro` e
**todos** os obrigatórios da categoria (antes só título/preço/estoque + BRAND/MODEL hardcoded).

**Alcance assumido e comunicado:** linhas que antes pareciam OK passam a **acender vermelho**, e a
`PublishBar` mostra **menos** publicáveis. É o objetivo — é o 400 aparecendo **antes** de gastar a
chamada.

## Teste desatualizado, atualizado com justificativa

`GradeMassaTest` exigia `assertNotContains('COLOR')` — codificava exatamente a suposição que o ML
refutou em produção. Não era regressão: era o teste errado. Atualizado citando o erro real, para
ninguém "consertar" de volta e reabrir o problema.

## Verificação

63/63 JS (incl. os 2 casos do erro real: Premium sem foto e COLOR/SIZE obrigatórios), Phase82
**10/10** (com o teste novo das secundárias), Phase75 3F/40P **== baseline** (SSL pré-existente),
build verde. Deployado e **validado pelo usuário em produção**.
