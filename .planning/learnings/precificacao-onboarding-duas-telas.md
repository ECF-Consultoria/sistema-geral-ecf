# Precificação do Onboarding — duas telas, uma régua

Leitura obrigatória antes de tocar em qualquer coisa de preço em
`/implementacao/{token}` (Simulador do cliente) ou `/implementacao/{token}/publicador`
(visão do Publicador). O que está aqui **não é dedutível do código** e já custou
preço errado publicado e dado de cliente destruído.

## 1. Os mesmos dados alimentam DUAS telas — e elas divergiram

`MlbImplementacao.dados.itens.precificacao` serve as duas páginas, mas cada uma
tinha a **própria cópia** da conta do preço:

| | Simulador do cliente | Visão do Publicador (antes) |
|---|---|---|
| arquivo | `Pages/Mlb/ImplementacaoPublica.jsx` | `Pages/Mlb/ImplementacaoPublicador.jsx` |
| MC / LL | por produto (`mc_individual`/`ll_individual`), global como padrão | **só o global** |
| imposto | `modo_imposto` = massa → tier; individual → `imposto_individual` | **sempre o tier** |
| pareamento planilha × salvo | `lib/precificacaoProdutos.js` (1-para-1) | mapa chaveado por **SKU** |

Caso real (onboarding do Renan Souza, 2026-08-17): global MC 0% e LL 0%, produto
com `mc_individual` 10 e `ll_individual` 25. Divisor `1 − 0,115 − 0,0737 − MC − LL`:

- cliente configurou → **R$ 7.706,48** (publicar por R$ 9.247,78)
- publicador anunciava → **R$ 4.381,86** (publicar por R$ 5.258,23) — **43% a menos**

A régua virou uma só, em `lib/precificacaoProdutos.js`
(`impostoEfetivo`/`mcEfetivo`/`llEfetivo`/`calcPrecoFinal`), com os números deste
caso travados em `tests/js/precificacaoProdutos.test.js`. **Não recriar a conta
dentro de uma página** — foi exatamente assim que as duas divergiram.

## 2. As DUAS unidades que convivem no mesmo objeto

Erro que passa silencioso, porque 10 e 0,10 são os dois "válidos":

- **globais** (`margem_contribuicao`, `lucro_liquido`, `acrescimo`, `<tier>.imposto`)
  são **decimais** — `0.19` = 19%;
- **overrides por produto** (`*_individual`) são **strings em ponto percentual**,
  como o cliente digitou — `"10"` = 10%.

Trocar as duas estoura o divisor e devolve preço negativo (ou `null`).

Semântica que não é simétrica, e é de propósito:

- MC e LL: campo **vazio herda o global**; `"0"` é override legítimo de zero.
- Imposto no `modo_imposto = 'individual'`: campo vazio é **ZERO**, não herda o
  tier — foi o cliente que escolheu tratar imposto produto a produto.
- `modo_imposto` ausente (precificações antigas) = `'massa'`.

## 3. O SKU não identifica produto — e o save do publicador destruía dado

O cliente digita `"Não tenho"`, `"-"`, `"sem código"` no SKU de **todos** os
produtos. Qualquer estrutura chaveada por SKU colapsa as N linhas numa só.

O `persist()` do frete na visão do Publicador reconstruía a lista salva a partir
de um mapa por SKU: **a cada tecla de frete**, as 11 linhas viravam cópia da
última — custo, imposto e margens de todos os produtos substituídos pelos de um
só. É o que explica as 11 linhas idênticas com `descricao = "Banco Rialto"`
(o último produto da planilha) encontradas em produção.

Consequências práticas:

- **Custo perdido não volta por código.** O merge re-deriva SKU e nome a partir da
  Planilha de Produtos, então os NOMES voltam sozinhos ao abrir a tela; os números
  digitados pelo cliente, não. Só o cliente (ou o histórico) traz de volta.
- Toda tela que **grava** `precificacao.produtos` tem de partir de
  `mesclarPrecificacaoComPlanilha()` e escrever a lista inteira preservando cada
  linha — nunca reconstruir por chave.
- Estado de UI por produto (input de frete, seleção) indexa pela **posição**, não
  pelo SKU. Com SKU repetido, um input governava e salvava a linha de todos.

## 4. Ainda aberto: o check-in do publicador é chaveado por SKU

`dados.publicador_checkin` é um mapa `{sku => true}`, e a rota
`implementacao.publicador.checkin` recebe `sku`. Com o SKU repetido nos 11
produtos, marcar um marca **todos** (o contador salta de 0/11 para 11/11).

Não foi corrigido junto porque trocar a chave (sku → posição) invalida os
check-ins já gravados de **todos** os onboardings, e isso é decisão de produto,
não refactor. Ver `MlbImplementacaoController::checkinPublicador()`.

## 5. Como conferir sem acreditar no build

`npm run build` verde não prova nada aqui (o projeto não tem ESLint) e grep no
bundle prova o deploy, não a função — a conta agora mora num **chunk separado**
(`precificacaoProdutos-*.js`), então os nomes dos campos nem aparecem mais no
chunk da página.

Receita que funcionou: extrair o `dados` real de produção do `data-page` da
própria página pública, semear numa `MlbImplementacao` temporária no local
(`empresa_id` é **unique** — precisa de uma `MlbEmpresa` sem implementação),
abrir as duas telas com o Puppeteer e comparar os valores renderizados. O
passo-a-passo (interceptação de asset, CORS, `innerText` em maiúsculas, limpeza
do dado semeado) está em `verificacao-visual-local.md`.

Para o caminho de gravação: digitar um frete na tela e **reconsultar o banco** —
conferir que só a linha editada mudou e que as outras mantiveram nome e números.
Nunca conferir por stdout do script que gravou.
