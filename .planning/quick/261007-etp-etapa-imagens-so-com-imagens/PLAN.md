# Quick 261007-etp: etapa Imagens do Publicador volta a ser só fotos

## Defeito

A Fase 169 (plano 169-04, deployado hoje) criou a 4ª etapa "Imagens" movendo a seção inteira
"Fotos e variações" de Detalhes para Imagens. O cartão de cada variação (`CartaoVariante.jsx`)
sempre foi misto — fotos **e** estoque/SKU/código universal (EAN)/AGID/MPN/atributos extras no
mesmo cartão — e foi junto por engano. O usuário relatou em produção:

> "Criou a etapa de imagens mas levou alguns campos que não devia para essa etapa, como o:
> Estoque, SKU, Código universal (EAN), AGID e MPN. Todas esses campos deveriam ter ficado na
> etapa detalhes, na primeira sessão. A etapa Imagens é para ter apenas as imagens."

## Causa raiz

`Mesa/CartaoVariante.jsx` renderizava, no mesmo `<article>`: o bloco de fotos (`BlocoDeFotos`) e a
grade de estoque/SKU/GTIN/atributos extras (`CampoEstoque`/`CampoSku`/`CampoGtin`/
`atributosExtrasDaVariante`, de `GradeVariantes.jsx`). `Mesa/FotosEVariacoes.jsx` renderizava essa
lista de cartões + "Adicionar variação"/órfãs/eixos avançado — tudo isso saiu de Detalhes e entrou
em Imagens (169-04) como um bloco só, sem separar o que é foto do que é dado.

O roteamento de erro por etapa (`apoio.js::etapaDoProblema`) já estava correto: `E5`
(estoque/SKU/GTIN/atributo de variante) sempre apontou para `'detalhes'`; `E6`/`alvo.grupo`/
`alvo.imagem` sempre apontou para `'imagens'`. O bug era só de **apresentação** — os campos certos
estavam na etapa errada — não de roteamento.

## Fix

Dividir o cartão de variação em dois cartões irmãos, reaproveitando um cabeçalho comum:

- `CartaoVariante.jsx` (Detalhes) — DADOS: estoque, SKU, GTIN, atributos extras, tom de cor. Ganha
  `onTirar` e exporta `CabecalhoVariante` (nome, cor, badge "publicada", "Vender esta variação",
  "Tirar").
- `CartaoFotosVariante.jsx` (Imagens, novo) — FOTOS: só o bloco de fotos. Reaproveita
  `CabecalhoVariante` sem os controles de gestão (eles ficam só em Detalhes).
- `FotosEVariacoes.jsx` (Imagens) — vira fotos-apenas: fotos gerais + `CartaoFotosVariante` por
  variação. Perde `acaoDeTirar`, `NovaVariacao`, órfãs e o editor de eixos avançado.
- `DadosDasVariacoes.jsx` (Detalhes, novo) — gestão da variação (criar, tirar, trazer de volta,
  eixos avançado) + lista de `CartaoVariante` (dados). Vira a **primeira seção** de Detalhes.
- `EtapaDetalhes.jsx` — `<DadosDasVariacoes />` antes de `<FichaTecnica />`.

Nada muda em `apoio.js`, `usePublicador.js` nem no backend (`ValidadorRascunho.php`).

## Verificação

- `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js
  tests/js/publicador-ferramentas.test.js` — todos passando.
- `npm run test:js` (suíte completa) — igual à baseline (2 falhas pré-existentes, documentadas,
  não relacionadas).
- `npm run build` — `Editor.jsx` continua no manifest do Vite.
- `php artisan test tests/Feature/Publicador tests/Unit/Publicador` — 816 passed (baseline, sem
  mudança no backend).

## Fora de escopo

- Não tocar em `usePublicador.js`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`.
- Não ressuscitar o bloco de pontos fortes/medidas digitados à mão (removido em 261007-rmv).
- Deploy é decisão separada do usuário.
