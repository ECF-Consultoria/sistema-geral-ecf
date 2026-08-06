---
id: 260806-fnh
slug: checkin-publicador-e-variacoes
status: complete
date: 2026-08-06
commits: [1e067110, 6075d876, 69b34d8b]
---

# Quick 260806-fnh — Check-in do Publicador + Variações de produto

## O que mudou

### Visão do Publicador (`/implementacao/{token}/publicador`)

**Check-in por SKU.** Cada linha do Catálogo de Produtos ganhou um quadradinho
que o publicador marca conforme vai publicando. Linha marcada fica esverdeada
com o SKU riscado, e o cabeçalho mostra `Check-in X/Y` (verde quando fecha tudo).

Onde o dado mora foi a decisão que importa: `dados.publicador_checkin`, chave
**top-level**, mapa `{sku => true}`. Dentro de `itens.planilha_produtos.produtos`
seria o lugar óbvio e estaria errado — o cliente reescreve aquele array inteiro
a cada save da planilha e apagaria as marcações do publicador. Tem teste
provando exatamente esse cenário.

Rota nova (`PATCH /implementacao/{token}/publicador/checkin`) porque
`salvarItem()` exige `isset($dados['itens'][$id])` e não alcança chave
top-level. `/implementacao/*` já é isento de CSRF, então nada a fazer ali.

Como a página se auto-recarrega a cada 30s, o front mantém um `Set` de SKUs com
PATCH em voo e reconcilia o estado do servidor preservando o clique recém-dado —
sem isso o reload reverteria a marcação visualmente por alguns segundos.

**Copiar na coluna Produto.** Passou a usar o mesmo `CopyCell` das outras.

**Card de texto completo.** Especificações e Descrição viram `TextoCell`: o
preview continua truncado, mas o texto agora é clicável e abre um card com o
conteúdo inteiro (`whitespace-pre-wrap`), título com SKU + nome do produto,
botão "Copiar tudo" e fechamento por Esc/clique fora.

### Link do cliente (`/implementacao/{token}`)

**Variações de produto.** Não havia como o cliente dizer que dois produtos são o
mesmo anúncio mudando só a cor — o publicador descobria na mão.

Modo **Guiado**: botão `+ Adicionar variação deste produto` duplica o produto
selecionado e limpa só o que muda entre variações (SKU, estoque e o valor).
Descartada a alternativa de perguntar "tem variação?" em todo produto: quem não
usa não vê nada novo. Se o produto ainda não estava num grupo, ele vira a
primeira variação do grupo recém-criado. Os chips das variações aparecem
agrupados num bloco roxo, e o formulário ganha o bloco **Variação** — o eixo
(Cor/Tamanho/Voltagem/Material/Sabor/Outro) vale para o grupo inteiro, o valor é
por linha, e "Não é variação" desfaz (se sobrar uma só, o grupo deixa de existir).

Modo **Lote**: três colunas novas — `Grupo variação` (texto livre; linhas com o
mesmo texto formam um grupo), `Tipo` e `Valor`.

`faltamCampos()` passou a exigir o valor quando a linha está em grupo, então
variação sem cor preenchida aparece como pendente no chip.

## Restrição respeitada

A lista `dados.itens.planilha_produtos.produtos` continua **plana**: variação são
três campos novos por linha, não uma estrutura aninhada. Por isso precificação,
a regra automática do ME1 (`planilhaExcedeMercadoEnvios`) e o
`MlbAnuncioController::montarProdutosDoCliente()` seguem funcionando sem
alteração — cada variação tem SKU, estoque e preço próprios, que é como o ML
trata mesmo.

No Publicador as variações aparecem agrupadas (barra lateral roxa + badge
"Cor: Azul") e o cabeçalho ganhou o contador **Anúncios**, porque N SKUs em
variação viram um anúncio só no Mercado Livre.

## Gates

- `tests/Feature/Quick260806/CheckinEVariacoesTest.php` — **5/5 verde**
  (marca/desmarca, validação, o save do cliente não apaga o check-in, os campos
  de variação chegam na prop do Publicador, ME1 continua reagindo com variações).
- Suítes vizinhas (`OnboardingMe1MercadoEnvios`, `Phase33Onboarding`,
  `MlbImplementacaoConteudo`, `RascunhoPorProduto`, `Phase82`): **54 passed /
  1 failed**. A falha é a pré-existente do grant do polo "Serra Gaúcha"
  (registrada como item aberto desde a quick `260803-kv2`) e não toca nenhum
  arquivo deste diff.
- `npm run build` verde, com `ImplementacaoPublicador-*.js` e
  `ImplementacaoPublica-*.js` no manifest.

## Nota de branch

`HEAD` local estava **5 commits atrás** de `origin/main` e com um commit
duplicado de outra sessão (`docs(quick-260805-dnp)` com hash diferente do
remoto). Confirmado que os 5 commits remotos (HubSpot/notes) não tocam nenhum
arquivo desta tarefa, o trabalho foi movido via `stash` para a branch
`quick/260806-fnh-checkin-publicador-e-variacoes` criada **a partir de
`origin/main`**.

## NÃO deployado

Aguardando autorização explícita.

## Fora de escopo

- Levar a variação para o wizard "Anunciar ML" (`attribute_combinations` no POST
  `/items`). O dado passa a existir e o `MlVariacaoService` já sabe montá-lo — o
  consumo fica para uma próxima.
- Check-in com autor e carimbo de hora — o pedido foi explicitamente "algo
  básico, uma pessoa só vai usar".
