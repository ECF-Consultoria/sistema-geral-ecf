# Pendencias registradas — Fase 172 (Publicador, Etapa 1: Navegacao)

Este arquivo existe para nao deixar descobertas desta fase so na memoria da
sessao. Nenhum item abaixo e tarefa desta fase — sao coisas que QUEM MEXER
DE NOVO neste modulo precisa saber antes de comecar.

## 1. `route_prefix` errado do modulo "Anunciar Mercado Livre" no Controle Dev

`app/Support/Modules.php:191` registra:

```php
['key' => self::MLB_ANUNCIAR, 'name' => 'Anunciar Mercado Livre', ...,
 'route_prefix' => 'mlb.anunciar', ...]
```

Sem o ponto final. As rotas reais do modulo usam o prefixo `mlb.anuncios.*`
(ex.: `mlb.anuncios.index`, `mlb.anuncios.publicador.produtos`) —
`routes/mlb_anuncios.php:32`.

`rotaOculta()` em `resources/js/Layouts/AppLayout.jsx:487-488` so casa por
PREFIXO quando a entrada do `route_prefix` termina em `.`:

```js
const rotaOculta = (routeName) =>
    !!routeName && modulosOcultos.some(h => h.endsWith('.') ? routeName.startsWith(h) : routeName === h);
```

Como `'mlb.anunciar'` nao termina em ponto, a comparacao exige IGUALDADE
EXATA do `routeName` — e nenhuma rota do Publicador se chama literalmente
`mlb.anunciar`. Resultado: marcar este modulo como oculto em Dev -> Controle
Dev **nao esconde nenhuma rota do Publicador**. O toggle existe na tela,
parece funcionar (nao da erro), mas nao tem efeito nenhum.

Correcao quando alguem for usar esse toggle de verdade: trocar a linha 191
para `'route_prefix' => 'mlb.anuncios.'` (com o ponto).

**Por que nao foi corrigido na Fase 172:** o usuario decidiu, nesta fase,
liberar o item de menu "Publicador" direto (via `permission: 'mlb.anunciar'`
no NAV_TREE), sem depender do Controle Dev — entao o bug ficou fora do
caminho critico. Corrigi-lo agora mudaria o comportamento do toggle para
OUTROS admins que ja interagem com o Controle Dev hoje (mesmo que o efeito
pratico atual seja nulo), e essa mudanca nao foi pedida nesta fase.

## 2. Testes que vao quebrar quando `ModoAnuncioTabs.jsx`/`AreaTabs.jsx` forem apagados

A spec (`ETAPA-1-navegacao.md`, secao 6) manda MANTER os dois arquivos sem
uso por uma semana em producao, antes de apaga-los. Quando esse dia chegar,
os testes abaixo (que leem os arquivos como FONTE, nao so testam
comportamento) vao quebrar e precisam ser removidos/ajustados no MESMO
commit da remocao:

- `tests/js/estrutura-meus-anuncios.test.js:92-107` — le `ModoAnuncioTabs.jsx`
  como fonte (bloco "abas: as 4 rotas do modulo estao presentes...").
- `tests/js/publicador-entrada.test.js:134-140` — le `ModoAnuncioTabs.jsx`
  como fonte (teste "ModoAnuncioTabs — Individual aponta para o
  Publicador...").
- `tests/js/publicador-alavancas.test.js` — o teste "AreaTabs — troca de
  rota entre as duas areas e marca a atual" (linha ~70-76) le
  `AreaTabs.jsx` como fonte; esse teste so sera removido quando o arquivo
  for apagado. O teste "orfao" (linha ~61) JA FOI AJUSTADO na Fase 172 (plano
  172-03) para excluir `AreaTabs.jsx` do loop — quando o arquivo for
  apagado de verdade, essa exclusao tambem pode ser removida (nao tem mais
  nada a excluir).

## 3. Pegadinha adicional encontrada (nao estava na conferencia original do usuario)

`tests/js/publicador-entrada.test.js`, teste "Tela B — navega ao editor por
clique e Enter, e monta as abas com a conta do Publicador" (linhas ~160-168
no estado do repositorio ANTES desta fase), le `Produtos.jsx` como fonte e
travava nos literais `contaPublicador={` e `empresaId={abas.company_id}` —
nomes de prop do antigo `ModoAnuncioTabs`/`AreaTabs`. Isso NAO estava na
lista de testes-armadilha que o usuario levantou (ele citou as linhas
134-182 como sendo sobre `ModoAnuncioTabs.jsx`, mas este teste especifico le
`Produtos.jsx`, nao `ModoAnuncioTabs.jsx`). Foi corrigido no plano 172-03
(os literais passaram a ser `conta={empresa.chave}` e
`companyId={abas.company_id}`, os nomes de prop do `AbasDaConta` novo).
Registrado aqui so para constar — nao e mais uma pendencia, ja foi tratado.
