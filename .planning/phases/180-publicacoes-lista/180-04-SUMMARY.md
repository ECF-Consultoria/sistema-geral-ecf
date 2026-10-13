---
phase: 180-publicacoes-lista
plan: 04
subsystem: api
tags: [publicador, publicacoes, inertia, contrato-de-props, lista-fechada, custo-de-consulta, mutacao]

requires:
  - phase: 180-publicacoes-lista
    plan: "02"
    provides: "`PublicacoesDaListaService::metodologiaPorMlItemId/mlItemIdsPorProduto/coberturaPorProduto` — a metodologia da linha, a busca por produto e o mapa F1/F2 × Clássico/Premium, uma consulta cada"
  - phase: 180-publicacoes-lista
    plan: "03"
    provides: "`AcervoTriagemService::escopo()` com o 4º argumento (busca por produto) e o eixo `com_erro`; `filtros()` composável; `triagem()` com `$filtros` e `$lista`; as constantes `STATUS_COM_ERRO`/`TIPO_CLASSICO`/`TIPO_PREMIUM`"
  - phase: 180-publicacoes-lista
    plan: "05"
    provides: "o contrato de `CabecalhoDePublicacoes` (`{coletado_em, horas, rotulo, nunca_coletado}`) e de `FaixaDeSucesso` (`resumo` ou `null`), e a conclusão medida de que a fonte da faixa não existe"
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "`MlbAnuncioController::meus()`, as 13 props e as 24 chaves do item que são a régua da não-regressão, e os 25 testes de `MeusAnunciosTest`"
  - phase: 179-visao-geral-cockpit
    provides: "os 5 destinos `?status=` que `CockpitDaContaService::resumoDePublicacoes()` monta e que não podem mudar de recorte"
provides:
  - "`meus()` com os 7 parâmetros novos da URL saneados por lista fechada, `com_erro` como 7º valor de `status`, e Fase/Responsável/Período resolvidos em `ml_item_ids` por UMA consulta, por interseção"
  - "a prop `filtros` com 11 chaves (as 4 de hoje, mesmo nome e tipo, + 7 novas)"
  - "a prop `abas` — 6 entradas ORDENADAS `{chave, rotulo, total, comDot}` — e a prop `contagens` com os 7 eixos de status + `rascunhos`, de UMA consulta agregada + 1 para `com_erro`"
  - "a prop `grupos` da visão Por produto, com cobertura, 'x de N', grupo próprio para as publicações sem produto e nenhum botão sem destino"
  - "as props `rodape`, `sincronizacao` (com `rotulo` pronto em pt-BR) e `faixaSucesso` (`null` SEMPRE, medido)"
  - "10 chaves ADITIVAS no item (produto/fase/kit/autor/`publicada_em`/`tipo`/`catalogo`), sem `margem`, `lucro`, `repasse` nem `tarifa`"
  - "`tests/Feature/Publicador/PublicacoesDaTelaTest.php` — 40 casos, 469 asserções, 13 mutações pegas, custo travado em 33 consultas e constante entre 1 e 50 itens"
affects: [180-06, 180-07, 180-08, 180-09, 180-10, 180-11, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "Contagem de aba por UMA consulta agregada (`status, count(*) group by status`) em vez de N `count()`: com busca ativa, 7 chamadas a `escopo()` resolveriam 7 vezes os `ml_item_id` do produto — 14 consultas em vez de 2"
    - "Duplicação de regra GUARDADA por gate em vez de evitada: a soma de `acionaveis` repete o `match` do serviço, e o gate compara as 7 chaves do agregado com o `filtros(escopo(…, <chave>))->count()` do próprio serviço"
    - "`is_string()` antes de `trim()`/cast em parâmetro escalar de querystring: `(string) []` emite `Array to string conversion`, que o `HandleExceptions` do Laravel converte em `ErrorException` — 500 de graça em `?busca[]=x`"
    - "Filtro por coluna json classificado em PHP, não em SQL: `like '%\"id\":5%'` casa também com `\"id\":55` e o `id` não é necessariamente a última chave do objeto, então não há sufixo confiável"
    - "Mensagem de asserção de gate de custo que ENUMERA as consultas da rodada: quando o número muda, quem lê precisa saber QUAL entrou, não só que mudou"
    - "Gate de custo em duas camadas: o número absoluto (travado, frágil a middleware) E o teto de repetição do MESMO SQL (robusto, pega N+1 sozinho)"
    - "Comparador de ordenação consistente onde o protótipo tem um inconsistente: `a || b || c` com `b` que nunca devolve 0 torna `c` inalcançável e produz ordem instável — respeitar a INTENÇÃO, não o defeito"

key-files:
  created:
    - tests/Feature/Publicador/PublicacoesDaTelaTest.php
    - .planning/phases/180-publicacoes-lista/180-04-SUMMARY.md
  modified:
    - app/Http/Controllers/MlbAnuncioController.php

key-decisions:
  - "`comDot` em camelCase dentro de cada entrada de `abas`, e NÃO `com_dot` como meu plano escreveu em prosa: é o nome LITERAL que `AbasDeStatus.jsx` consome (`180-06-PLAN.md:94`). Mandar snake faria o componente ler `undefined` e NUNCA desenhar o ponto vermelho, em silêncio"
  - "`abas` traz SEIS entradas (as que a tela desenha, LIST-25) e `contagens` traz os SETE eixos + `rascunhos`: `encerrados` e `acionaveis` não têm aba no layout novo, mas são destinos vivos da Visão Geral e `acionaveis` é o DEFAULT — a contagem deles não podia desaparecer"
  - "Fase/Responsável/Período resolvidos num HELPER PRIVADO do controller, não em `PublicacoesDaListaService`: o serviço é território do plano 180-02, já commitado, e editá-lo estava explicitamente vedado. Registrado como pendência de consolidação"
  - "`com_vendas` NÃO entra nos filtros passados à `triagem()`, e isso é deliberado: `MeusAnunciosTest::com_venda_filtra_a_listagem_sem_mudar_a_triagem` é um teste EXISTENTE cujo propósito é essa decisão da Fase 173-02. Os filtros NOVOS entram, porque sem eles os chips contariam um universo maior que a lista"
  - "`criar_combo_url` EXISTE, apontando para o MESMO destino que a linha 'Prontos para a Fase 2' da Visão Geral já usa — com a limitação medida e escrita de que ele não pré-seleciona o produto, porque `Produtos.jsx` lê `?filtro=` e `?fase=` da URL mas a busca é estado local (`:463`)"
  - "`sem_fase2` derivado das CÉLULAS de F2 da cobertura, com a limitação escrita: publicação de fase 2 com `listing_type_id` fora dos dois tipos conta no `total` e não acende célula, logo não limpa a flag"
  - "A ordenação dentro do grupo respeita a INTENÇÃO do protótipo e não o seu comparador: lá o terceiro termo (data DESC) é INALCANÇÁVEL por construção e o comparador é inconsistente para dois clássicos"
  - "O nome do grupo sem produto é a constante pública `MlbAnuncioController::GRUPO_SEM_PRODUTO` = 'Sem produto no Publicador' — DIZ o que é, nunca '—' nem nome inventado (EXMP-01)"
  - "`busca`, `status` e `sub` ganharam guarda contra array na querystring (Rule 1 — era 500 latente). Nome e tipo das props não mudaram"
  - "ZERO migration, ZERO índice, ZERO pacote, ZERO linha de frontend, ZERO rota nova"

patterns-established:
  - "Fechamento aritmético EXATO como gate: 2039 + 40 = 2079 testes e 15.418 + 469 = 15.887 asserções, com o arquivo novo medido EM ISOLAMENTO (40/469) para que a soma não seja circular"
  - "Sobrevivente de mutação investigada e não presumida equivalente: a (j) sobreviveu, e a causa era o MEU teste vazio por construção — o `ml_item_id` do fixture continha o termo da busca"
  - "Controle NEGATIVO da trava de contagem do mutador: alvo com 20 ocorrências é RECUSADO e o arquivo fica intacto (sha256 igual antes e depois)"

requirements-completed: [LIST-05, LIST-06, LIST-07, LIST-09, LIST-11, LIST-12, LIST-13, LIST-16, LIST-19, LIST-20, LIST-22, LIST-23, LIST-24, EXMP-01]

duration: 73min
completed: 2026-10-13
---

# Fase 180 Plano 04: `meus()` — a ponte entre os dois serviços novos e a tela — Resumo

**Os 12 parâmetros da tela passaram a viver na URL e a voltar na prop, as 7 abas ganharam contagem
sobre os DEMAIS filtros numa consulta agregada, a linha passou a dizer de onde veio (produto, fase,
kit, autor) e a visão Por produto chega agrupada do servidor — sem uma única prop de hoje sair ou
mudar de nome, e sem a página crescer com o tamanho do acervo.**

## Desempenho

- **Duração:** ~73 min
- **Tarefas:** 3 de 3
- **Arquivos criados:** 1 (`PublicacoesDaTelaTest.php`, 1.358 linhas)
- **Arquivos modificados:** 1 (`MlbAnuncioController.php`, 3.632 → 4.476 linhas)
- **Commits:** 5 — `18745843` (Task 1), `5f0ef8af` (Task 2), `8b5ac633` (Task 3), `e6e45fb7`
  (2 lacunas de cobertura + o caso que a mutação provou vazio) + o desta SUMMARY

---

## (1) O SHAPE EXATO — é isto que os planos 180-06 a 180-11 ligam

### `filtros` — 11 chaves

⚠️ **As 4 primeiras têm o MESMO nome e o MESMO tipo de hoje.** 25 testes de `MeusAnunciosTest` as
leem, e o contrato é percorrido por lista literal no caso 1.

| chave | tipo | default | fonte / nota |
|---|---|---|---|
| `busca` | `string` | `''` | já existia |
| `status` | `string` | `'acionaveis'` | lista FECHADA de **7**: `acionaveis`, `ativos`, `pausados`, `em_revisao`, `encerrados`, `todos`, `com_erro` |
| `motivo` | `?string` | `null` | whitelist de `AcervoTriagemService::motivosDef()` |
| `com_venda` | `bool` | `false` | `$request->boolean('comVenda')` |
| `fase` | `?int` | `null` | lista fechada `1`\|`2` |
| `tipo` | `?string` | `null` | `AcervoTriagemService::TIPO_CLASSICO`\|`TIPO_PREMIUM` |
| `catalogo` | `bool` | `false` | `$request->boolean('catalogo')` |
| `responsavel` | `array<int,int>` | `[]` | ids de usuário da EQUIPE, saneados a `int > 0` |
| `periodo` | `?string` | `null` | lista fechada `hoje`\|`7d`\|`30d` |
| `visao` | `string` | `'grupo'` | `grupo`\|`lista` — **Por produto é o default** (LIST-09) |
| `sel` | `?string` | `null` | validado pela FORMA `/^MLB\d+$/`, **não lido do banco** |

⚠️ **`sub` NÃO está em `filtros`** — ele continua em prop PRÓPRIA (`props.sub`), como desde a Fase
134, e é o 12º parâmetro da URL. ⚠️ **`page` é do paginator**, que carrega os 12 de graça pelo
`->withQueryString()` que continua no `paginate(50)`.

### `abas` — SEIS entradas, a ORDEM é contrato

```php
[
  ['chave' => 'todos',      'rotulo' => 'Todas',      'total' => int, 'comDot' => false],
  ['chave' => 'ativos',     'rotulo' => 'Ativas',     'total' => int, 'comDot' => false],
  ['chave' => 'pausados',   'rotulo' => 'Pausadas',   'total' => int, 'comDot' => false],
  ['chave' => 'com_erro',   'rotulo' => 'Com erro',   'total' => int, 'comDot' => bool],
  ['chave' => 'em_revisao', 'rotulo' => 'Em revisão', 'total' => int, 'comDot' => false],
  ['chave' => 'rascunhos',  'rotulo' => 'Rascunhos',  'total' => int, 'comDot' => false],
]
```

⚠️⚠️ **`comDot`, em camelCase** — e isto é uma DIVERGÊNCIA DELIBERADA do meu próprio plano, que
escreveu `com_dot` em prosa. O nome literal que `AbasDeStatus.jsx` consome está declarado em
`180-06-PLAN.md:94` (`abas: [{ chave, rotulo, total: ?number, comDot: boolean }]`); mandar snake
faria o componente ler `undefined` e **nunca desenhar o ponto vermelho, em silêncio** — modo de
falhar exatamente do tipo que esta milestone caça.

⚠️ **`total` é sempre `int`, nunca `null`** nesta prop — o `?number` do contrato do 180-06 existe
para o componente tolerar `null`, e o componente **não** desenha número quando ele vem nulo. Aqui
sempre há número medido, inclusive **o zero honesto** (há caso provando que `com_erro` em 0 vem como
`0` e não como `null`, e que o dot não acende).

⚠️ **`rotulo` vem do servidor** porque é ele que conhece a ORDEM, e ordem e rótulo andam juntos.
Se o 180-06 preferir fixar os literais no componente (o gate de literais dele sugere isso), **pode
ignorar `rotulo`** — o load-bearing é `chave` + a ordem. Está escrito no comentário do controller.

### `contagens` — os SETE eixos + `rascunhos`

```php
['todos'=>int, 'acionaveis'=>int, 'ativos'=>int, 'pausados'=>int,
 'em_revisao'=>int, 'encerrados'=>int, 'com_erro'=>int, 'rascunhos'=>int]
```

⚠️ **Por que existem DUAS props para contagem:** o layout novo desenha 6 abas (LIST-25), mas
`encerrados` e `acionaveis` continuam **valores válidos de `status`** — são destinos que a Visão
Geral da Fase 179 monta (`CockpitDaContaService::resumoDePublicacoes()`) e `acionaveis` é o
**DEFAULT da tela**. Fazer a contagem deles desaparecer junto com a aba deixaria a tela sem número
para o próprio recorte em que ela abre. ➡️ **Os planos 180-06/180-10 precisam decidir o que a tela
mostra quando `filtros.status` é `encerrados` ou `acionaveis` e nenhuma das 6 abas casa.**

### `grupos` — a visão Por produto (`[]` quando `visao === 'lista'`)

```php
[
  ['produto_id'       => ?int,        // null = grupo das publicações SEM produto
   'produto_nome'     => ?string,     // `MlbAnuncioController::GRUPO_SEM_PRODUTO` no grupo legado
   'produto_sku'      => ?string,
   'estoque'          => ?int,        // `available_quantity` do 1º item do grupo
   'cobertura'        => ['f1_classico'=>bool,'f1_premium'=>bool,'f2_classico'=>bool,'f2_premium'=>bool],
   'total_no_produto' => int,         // TODAS as publicações do produto (coberturaPorProduto.total)
   'total_visivel'    => int,         // quantas desta página/filtro -> o "x de N"
   'sem_fase2'        => bool,
   'ver_produto_url'  => ?string,
   'criar_combo_url'  => ?string,
   'ml_item_ids'      => [string, …]], // a ORDEM das linhas dentro do grupo
  …
]
```

- **ORDEM DOS GRUPOS:** a de aparição na lista já ordenada por gravidade (D-12) — é o que preserva o
  critério 13 **e** agrupa. Confere com o protótipo (`Publicacoes v2.dc.html:564`,
  `order.push(p.prod)`). **O grupo sem produto é sempre o ÚLTIMO.**
- **ORDEM DENTRO DO GRUPO:** `fase DESC` → Premium antes de Clássico → `publicada_em DESC` →
  `ml_item_id ASC`.
- **`cobertura`:** as 4 chaves são EXATAMENTE as de `MapaDeCobertura.jsx:24-28` (conferidas pelo
  plano 180-02 no componente). `listing_type_id` fora de `gold_special`/`gold_pro` conta no `total` e
  **não acende célula**.
- **`total_no_produto`:** `max(cobertura.total, total_visivel)`. O `max` é cinto de segurança: se o
  serviço contasse menos que a página (não deveria), o "x de N" nunca mostraria um N menor que o x.

### `rodape` (LIST-16)

```php
['visiveis' => int, 'total' => int, 'produtos' => int]
```
`total` é **o do paginator** (`$anuncios->total()`), a contagem que o `paginate(50)` já fez de graça.
`produtos` é a contagem **distinta de `produto_id` da página** — as publicações sem produto não
entram, porque não há produto que contar; **o grupo delas aparece na tela do mesmo jeito.**

### `sincronizacao` (LIST-01)

```php
['coletado_em' => ?string /*ISO*/, 'horas' => ?int, 'defasado' => bool,
 'nunca_coletado' => bool, 'rotulo' => ?string]
```
Derivado do `$defasagem` que a action **já** calculou — zero consulta nova. `rotulo` chega **pronto
em pt-BR** (`agora` · `há N min` · `há N h` · `há N dias`) porque `CabecalhoDePublicacoes.jsx` não
tem (e não pode ter) `new Date()`, e porque ele **recusa** rótulo que case `/\d{4}-\d{2}-\d{2}/`.
⚠️ Acervo nunca coletado devolve `rotulo => null` e `nunca_coletado => true`, e então o bloco inteiro
desaparece da tela: **"nunca medimos" ≠ "medimos e é zero"**, e não existe "Sincronizado há —".

### `faixaSucesso` (LIST-02)

**`null` SEMPRE.** Ver a seção (2).

### As 10 chaves ADITIVAS do item

`produto_id` · `produto_nome` · `produto_sku` · `fase` · `quantidade_kit` · `autor_nome` ·
`autor_tipo` · `publicada_em` · `tipo` · `catalogo`

⚠️ **Item ausente do mapa de metodologia = Legado:** as 8 primeiras vêm `null` e **quem desenha a
palavra é a tela**. Não existe `fase = 0` nem a string `'Legado'` saindo do servidor.
⚠️ **`estoque` do item continua vindo do ACERVO** (`available_quantity`) e não é afetado por ser
legado — era a pendência nomeada do plano 180-02, e o `estoque` do GRUPO sai do primeiro item.
⚠️ **`tipo`** é `classico`/`premium`/`null`; `listing_tier` continua mandando o valor CRU.
⚠️ **`margem`, `lucro`, `repasse` e `tarifa` NÃO EXISTEM** — asserção negativa com o item CHEIO.

---

## (2) ⚠️ `faixaSucesso` — a fonte NÃO EXISTE, remedida aqui com ISCA

O plano exigia MEDIR, não citar. Refiz as três sondas do plano 180-05 **com controle positivo**:

| o que procurei | resultado medido |
|---|---|
| `session()->put(`/`->flash(` de publicação em `app/` | **0 ocorrências reais** — o único "hit" é um comentário que eu mesmo acabei de escrever no controller |
| `session`/`flash` em `PublicacaoService.php` e `PublicarRascunhoJob.php` | **0** |
| chaves da lista BRANCA de `flash` do `HandleInertiaRequests` | **9**: `success`, `error`, `nps_link`, `nps_link_existente`, `workspace_url`, `portal_codigo_enviado`, `portal_email`, `portal_sucesso`, `aviso`. **Nenhuma de publicação** |
| como a publicação termina | `MlbPublicadorController` responde **202** (`:255`, `:267`) e `PublicacaoService:120` faz `PublicarRascunhoJob::dispatch()` — **assíncrono** |
| **ISCA (controle positivo)** | a mesma sonda de `session()->put(` **acha** 1 em `ConfirmablePasswordController`, 2 em `GoogleCalendarController`, 3 em `PortalAuthController`… ➡️ o "zero" acima **não é sonda quebrada** |

⚠️ **Divergência de 1 contra a 180-05**, que reportou 10 chaves de flash: o meu script extrai as
chaves do bloco `'flash' => [` até o `],` e conta **9**. A conclusão não muda — nenhuma é de
publicação — mas o número certo é **9**.

➡️ **`faixaSucesso` é `null` SEMPRE e a faixa nunca aparece.** Três razões independentes, qualquer
uma basta. ⚠️ **O que isso NÃO autoriza é fabricar o resumo:** o protótipo traz *"2 publicações
criadas agora · Poltrona Beny"* e aquilo é dado MOCK (EXMP-01). Há asserção negativa provando que a
string `publicações criadas agora` não existe no payload.

**PENDÊNCIA NOMEADA, para um plano futuro:** `pub_publicacoes.concluida_em` +
`pub_publicacao_itens` já têm tudo (quantos itens, qual produto, qual fase, qual tipo, qual ator).
Falta **(a)** uma chave de flash NOVA **declarada no `HandleInertiaRequests`** — chave fora da lista
branca morre no caminho em silêncio, e este projeto já foi mordido por isso duas vezes — e **(b)**
decidir o que a tela mostra quando a fila ainda não concluiu, que é **decisão de produto, não de
código**.

---

## (3) ⚠️ `criar_combo_url` — a conclusão medida

**EXISTE**, e aponta para `route('mlb.anuncios.publicador.produtos', ['conta' => <chave>, 'filtro'
=> 'publicados', 'fase' => 'so_base'])` — **o MESMO destino que a linha "Prontos para a Fase 2" da
Visão Geral já usa em produção** (`PainelVisaoGeralService::linhaDeProdutos()`, `:624`, com
`fase => 'so_base'` em `:529`).

Só é preenchido quando **`sem_fase2 === true`** e a conta foi resolvida (`$alvo['chave']`). Nos
outros casos é `null`, e aí **a tela não desenha o link** — nunca um `href="#"` (EXMP-01, com
asserção).

⚠️ **A LIMITAÇÃO, medida e escrita:** ele **não pré-seleciona o produto**. Não existe rota GET que
crie o combo de um produto específico — criar uma Fase 2 é um diálogo DENTRO da tela de Produtos.
E não há como mandar o produto na querystring: `Produtos.jsx` lê `?filtro=` (`:171`) e `?fase=`
(`:185`) da URL, mas **a busca é estado local** (`const [busca, setBusca] = useState('')`, `:463`).
➡️ **Se o 180-08 achar que o botão promete mais do que entrega, o certo é `null`** — a decisão fica
nomeada aqui, com os dois fatos na mão.

**`ver_produto_url`**, ao contrário, é **por produto e real**:
`route('mlb.anuncios.publicador.editor', ['produto' => <id>])`.

---

## (4) ⚠️ As SETE pendências que o plano 180-03 deixou — uma a uma

| # | pendência | o que foi feito |
|---|---|---|
| 1 | rotear `comVenda` por `filtros()` | FEITO. O `->when($comVenda, fn => where('sold_quantity','>',0))` inline **saiu**; `com_vendas` é chave de `$filtrosDoAcervo`. O predicado existe num lugar só |
| 2 | **`com_erro` na lista fechada de status** | FEITO. A lista tem **7** valores, com `AcervoTriagemService::STATUS_COM_ERRO` (constante, não literal). ⚠️ A forma `in_array($statusFiltro,` e o literal `'em_revisao'` foram **preservados byte a byte** porque `AcervoBuscaEFiltrosTest:247-253` os cobra por leitura do arquivo. Mutação (i) prova |
| 3 | Fase/Responsável/Período por `ml_item_ids`, `[]` ≠ `null` | FEITO, numa consulta só, por interseção. `null` = sem filtro, `[]` = casou com zero. Mutação (d) prova, e o caso roda com o acervo CHEIO (2 linhas), não vazio |
| 4 | as 7 contagens de aba | FEITO — mas **por UMA consulta agregada, não por 7 `count()`**. Ver (5) |
| 5 | `catalogo`/`com_vendas` por `$request->boolean()` | FEITO. Há caso com a string literal `'false'` nos dois |
| 6 | o rótulo é da tela, o servidor manda a chave | Parcialmente DIVERGIDO, de propósito: a **chave** circula em `filtros.status` e em `contagens`; o `rotulo` vai dentro de `abas` porque **`180-06-PLAN.md:94` o declara no contrato** e o servidor é quem conhece a ORDEM. Está escrito no controller que o 180-06 pode ignorá-lo |
| 7 | a ordenação não foi tocada | CONFIRMADO. `severidade` → `nota_ecf IS NULL` → `nota_ecf` → `ml_item_id`, intacta. Anúncio moderado com `severidade = 0` cai no fim da própria aba "Com erro" — decisão de produto |

**E as do plano 180-02:**

- **`estoque`** preenchido de `ml_acervo_itens.available_quantity` no grupo (o item já o tinha).
- **"Legado" é a AUSÊNCIA de entrada no mapa** — respeitado: as 8 chaves vêm `null` e nenhuma string
  `'Legado'` sai do servidor. **Exceção explícita e única:** o **nome do GRUPO** é
  `MlbAnuncioController::GRUPO_SEM_PRODUTO` = `'Sem produto no Publicador'`, porque o plano 180-04
  manda o grupo ter "nome que DIZ o que é". Não é a palavra da linha.
- **Margem/preço não vêm daqui** — confirmado por asserção negativa.

---

## (5) ⚠️ As contagens: UMA consulta agregada, e por que NÃO as 7 `count()`

O plano 180-03 sugeriu `filtros(escopo($c, $busca, <chave da aba>, $lista), $filtros)->count()`, sete
vezes. **O plano 180-04 proíbe** ("UMA consulta, nunca 7 `count()`"), e a medição dá razão a ele por
uma razão que vai além de 7 contra 1:

> ⚠️ `escopo()` com `$busca !== ''` chama `mlItemIdsPorProduto()`. **Sete chamadas a `escopo()`
> resolveriam SETE vezes os `ml_item_id` do produto** — 14 consultas em vez de 2.

**O que foi feito:**
- **1 consulta** `escopo($c, $busca, 'todos', $lista)` + `filtros()` + o motivo, com
  `selectRaw('status, count(*) as total')->groupBy('status')`. Dela saem **seis** das sete chaves.
- **1 consulta** para `com_erro`, que é consulta própria porque **não é um valor de `status`** — é o
  eixo `sub_status`, e o serviço é a fonte fechada dele.

**Total medido: 2 consultas para as 7 abas** (+1 do `count()` de rascunhos, que é reaproveitado por
`subTotais.rascunhos` em vez de contado duas vezes).

### ⚠️ A duplicação de regra, e o gate que a guarda

A soma de `acionaveis` (`active + paused + under_review`) e os nomes das colunas de `status`
**repetem o `match` de `AcervoTriagemService::escopo()`**. Isso é dívida consciente, e tem gate:

`test_agregado_bate_com_o_servico_nas_sete_chaves` compara, **para TODAS as 7 chaves**, o agregado
deste controller com `filtros(escopo($c, '', <chave>, $lista))->count()` do próprio serviço. Se o
`match` mudar e a soma não, reprova — e a mensagem diz que o `match` é a fonte única.

### O que entra nas contagens

Entram: `busca` · `motivo` · `tipo` · `catalogo` · `com_vendas` · `ml_item_ids`.
**Não entra:** o eixo de `status`, que é o que cada aba representa.
⚠️ **`motivo` entra**, e por isso o predicado dele foi extraído para o helper privado `comMotivo()` —
escrevê-lo duas vezes no mesmo método é o caminho mais curto para as duas divergirem.

---

## (6) ⚠️ A divergência que o controller NÃO podia resolver: Fase/Responsável/Período

O plano manda resolvê-los "com o `PublicacoesDaListaService` injetado na assinatura". **O serviço não
tem método para isso** — ele expõe `metodologiaPorMlItemId`, `mlItemIdsPorProduto` e
`coberturaPorProduto`, e nada que filtre por fase, ator ou data. E **editá-lo estava
explicitamente vedado** a este plano (território do 180-02, já commitado).

**Decisão:** helper privado `MlbAnuncioController::idsPorMetodologia()`, com a MESMA cadeia de joins
e o MESMO grupo de escopo por dupla âncora que `PublicacoesDaListaService::base()` e
`MlbAnuncioController::historico()` já percorrem. O serviço **continua injetado** (é ele quem traz a
metodologia, a busca e a cobertura), então o `key_link` do plano está honrado.

➡️ **PENDÊNCIA DE CONSOLIDAÇÃO:** quando um plano futuro puder tocar `PublicacoesDaListaService`,
este helper deveria virar `idsPorMetodologia(Company, ?int $fase, array $responsaveis, ?string
$periodo): ?array` lá dentro, ao lado dos outros três. Hoje a cadeia de joins existe em **três**
lugares (serviço, `historico()`, este helper).

### ⚠️ Por que o Responsável é classificado em PHP e não em SQL

`pub_publicacoes.ator` é json `{"equipe":bool,"id":int,"nome":string,"conta":{…}}`. Um
`like '%"id":5%'` casaria também com `"id":55`, e o `id` **não é necessariamente a última chave** do
objeto, então não há sufixo confiável. Classificar em PHP é o padrão que
`PainelVisaoGeralService::classificarAtor()` (`:151`) já estabeleceu para esta mesma coluna, e é
exato.

⚠️ **`equipe !== true` nunca casa**, mesmo com o id coincidindo: o ator do CLIENTE pelo Portal também
tem `id` gravado, e o id dele vive em `portal_usuarios`. Caso de teste com `equipe => false, id => 7`
e o nome `'Fulano Cliente'`, provando que (a) o filtro não casa e (b) **o nome do cliente não sai no
payload** (T-173-09). Mutação (k) prova.

### `periodo` — janela deslizante, declarada

`hoje` = `now()->startOfDay()`; `7d` = `now()->subDays(7)`; `30d` = `now()->subDays(30)` — **janela
deslizante de 168 h / 720 h**, não "7 dias de calendário". O `match` é **sem `default`** de
propósito: o valor vem de lista fechada, e quem ampliar a lista sem ampliar isto tem de ver o erro,
não um filtro que silenciosamente não filtra.

---

## (7) ⚠️ A divergência medida contra o PROTÓTIPO: o comparador do grupo é inconsistente

O plano manda conferir a ordem no protótipo e diz que, se divergir, **o protótipo ganha**. Conferi
(`Publicacoes v2.dc.html:566`):

```js
pubs.sort((a, b) => b.ph - a.ph || (a.t === 'premium' ? -1 : 1) || b.d.localeCompare(a.d))
```

⚠️ **O terceiro termo é INALCANÇÁVEL por construção:** o termo do meio devolve `-1` ou `1` e
**nunca `0`**, então o desempate por data nunca roda. E o comparador é **inconsistente**: para dois
clássicos, `cmp(a,b)` e `cmp(b,a)` devolvem os dois `1`.

➡️ **Replicar isso daria ordem instável entre requests** — exatamente o que o tie-break do D-12
existe para evitar. O que foi implementado respeita a **INTENÇÃO** inteira (fase DESC → Premium antes
→ data DESC) com um comparador consistente, mais `ml_item_id` ASC como tie-break estável. Está
escrito no docblock de `gruposPorProduto()`, e o caso
`test_ordem_dentro_do_grupo_segue_o_prototipo` prova que a data mais recente **não** ganha do tipo.

**Esta é a primeira divergência da milestone em que o protótipo NÃO ganha** — e ela é sobre um
defeito mecânico do mock, não sobre aparência.

### E a limitação de `sem_fase2`

`sem_fase2 = ! (cobertura.f2_classico || cobertura.f2_premium)`. O protótipo usa
`!allP.some(p => p.ph === 2)`, sobre TODAS as publicações. ⚠️ **Uma publicação de fase 2 cujo
`listing_type_id` não seja `gold_special` nem `gold_pro`** conta no `cobertura.total` e **não acende
célula nenhuma**, logo **não limpa esta flag**. Corrigir exige contagem POR FASE em
`coberturaPorProduto()` — território do plano 180-02. **Registrado, não escondido.**

---

## (8) Um bug latente consertado no caminho (Rule 1)

`?busca[]=x`, `?status[]=x` ou `?sub[]=x` produziam **500**: `(string) []` emite
`Array to string conversion`, e o `HandleExceptions` do Laravel converte qualquer erro do nível de
reporte em `ErrorException`. As três leituras passaram a ser `is_string()` + lista fechada **sem
cast**; nome e tipo das props não mudaram. Caso
`test_valor_invalido_cai_no_default_sem_lancar_e_sem_chegar_ao_sql` cobre os 11 parâmetros de uma vez
e prova, além do default, que `'; DROP TABLE ml_acervo_itens; --'` **não aparece em consulta nenhuma
nem como bind** — com a guarda de que o log **não está vazio** (senão o gate seria vazio por
construção).

---

## (9) O CUSTO — medido, travado e declarado

**33 consultas** na action com 50 linhas em 9 produtos (`?status=todos`, `visao=grupo`, sem busca),
medidas na **SEGUNDA** requisição (cache vivo entre requisições faz a 1ª esconder o teto real — na
Fase 178, 12 produtos custaram menos que 6).

**As 11 que são desta action**, enumeradas:

| # | consulta | nota |
|---|---|---|
| 14 | `count(*)` do `paginate(50)` | é dela que sai `rodape.total` |
| 15 | o `select` da página | ≤50 linhas |
| **16** | `metodologiaPorMlItemId` | **UMA**, com os ≤50 ids, FORA do `through()` |
| 17 | `triagem()` | UMA agregada |
| 18-20 | `defasagem()` | `exists` + `max(coletado_em)` + o último erro |
| **21** | `status, count(*) … group by status` | **UMA** para SEIS das sete abas |
| **22** | `com_erro` | consulta própria (é `sub_status`) |
| 23 | `$totalDeRascunhos` | reaproveitado por `subTotais.rascunhos` |
| **24** | `coberturaPorProduto` | **UMA** para os 9 produtos |

As outras 22 são middleware (`HandleInertiaRequests`, setores, `sugadores_pendentes`,
`notifications`) e o `cabecalho` da Fase 177 — **já existiam antes da Fase 180**.

⚠️ **O gate tem DUAS camadas**, porque a primeira é frágil ao middleware:
1. **o número absoluto travado** em `CONSULTAS_DA_ACTION = 33`, com a mensagem **enumerando as
   consultas da rodada** (quando mudar, quem lê sabe QUAL entrou);
2. **o teto de repetição do MESMO SQL**: nenhum texto de consulta pode aparecer mais de 5 vezes. O
   recorde legítimo medido é **3** (`count(*) from ml_acervo_itens where company_id` aparece no
   `paginate`, no cabeçalho e no `subTotais.publicados`); um `where ml_item_id = ?` por linha daria
   **50**. Esta camada **não** depende do middleware.

E o gate operacional: **`test_custo_nao_cresce_de_um_para_cinquenta_itens`** — a MESMA action com 1 e
com 50 linhas custa o MESMO número, cada medição feita na segunda requisição. Mutação (e) prova.

⚠️ **Com `busca` preenchida o custo é maior** e isso é declarado: `escopo()` resolve
`mlItemIdsPorProduto()` por chamada, e a action a chama **4 vezes** (listagem, triagem, abas,
`com_erro`). São 4 consultas que **não tocam o acervo** (provado pelo plano 180-02) e não crescem com
a página. Reduzi-las exigiria um cache de request no serviço — território do 180-02.

---

## (10) "O que precisaria estar errado no código para este gate falhar?" — por caso

| # | caso | o que precisaria estar errado |
|---|---|---|
| 1 | `contrato_de_props_intacto` | qualquer das 13 props de hoje removida ou renomeada; qualquer das 6 novas ausente; `filtros.busca`/`status` deixando de ser string ou `com_venda` deixando de ser bool |
| 2 | `chaves_do_item_sao_aditivas` | qualquer das 24 chaves de hoje removida (inclusive as 6 que LIST-24 **migra** para o painel); qualquer das 10 novas ausente; a metodologia vindo de placeholder; **um campo chegando como OBJETO** (a tela preta de 07/10) |
| 3 | `item_legado_vem_com_as_chaves_nulas` | o servidor mandando a string `'Legado'` ou `fase = 0`; o `estoque` do item virando `null` por causa do Publicador |
| 4 | `nota_base_continua_de_oitenta_e_seis` | a Nota ECF renormalizada para 100; a constante mudando sem a tela mudar (o literal 86 acusa o **desalinhamento**, não acompanha) |
| 5 | `filtros_voltam_na_prop` | qualquer dos 11 valores não voltando, voltando com outro tipo, ou `visao` com default `lista` em vez de `grupo`; `sub` saindo da prop própria |
| 6 | `valor_invalido_cai_no_default…` | qualquer lista fechada removida; `(string)` de volta num parâmetro escalar (500 em `?x[]=`); valor do usuário virando bind; `responsavel` aceitando `0`/negativo/fracionário/aninhado; **o log de consultas vazio** |
| 7 | `sel_so_sobrevive_com_a_forma…` | o regex afrouxado (`mlb123` passando), o `^`/`$` removidos, ou `sel` passando a ser lido do banco (custo por request) |
| 8 | `paginacao_preserva_todos_os_filtros…` | `->withQueryString()` removido ou trocado por `->appends()`; a paginação deixando de ser 50 |
| 9 | `page_fora_de_faixa_nao_explode` | exceção com `page` alto; grupo inventado em página vazia; `rodape.total` deixando de ser o do paginator |
| 10-14 | os **cinco** destinos da Visão Geral | qualquer arm do `match` desfeito — e **um caso por arm**, para que cada um reprove por motivo próprio. `encerrados` perdeu a ABA, não o valor |
| 15 | `com_erro_e_o_setimo_valor…` | o 7º valor fora da lista fechada do controller (`?status=com_erro` seria coagido para `acionaveis` **em silêncio**) |
| 16 | `contagens_sao_sobre_os_demais_filtros` | o filtro de status entrando nas próprias contagens (os dois conjuntos ficariam diferentes), ou um filtro que não é status deixando de entrar |
| 17 | `agregado_bate_com_o_servico_nas_sete_chaves` | a soma de `acionaveis` divergindo do `match`; o nome de uma coluna de status errado; `todos` deixando de somar `inactive`; qualquer das 8 chaves de `contagens` saindo |
| 18 | `com_dot_so_acende…` | `>= 0` no lugar de `> 0`; contagem `null` em vez de `0`; o dot acendendo em outra aba |
| 19 | `as_seis_abas_vem_na_ordem_do_servidor` | a ordem mudando; `comDot` virando `com_dot`; `total` deixando de ser int; "Rascunhos" saindo do trilho |
| 20 | `cobertura_acende_as_celulas_reais` | chave com nome diferente das 4 de `MapaDeCobertura.jsx`; célula acesa na fase errada; `sem_fase2` invertido |
| 21 | `grupo_filtrado_mostra_x_de_n` | `total_no_produto` contando só a página (o "x de N" desapareceria) |
| 22 | `grupo_legado_e_proprio_e_fica_no_fim` | o legado misturado com um produto; o grupo no topo; `produto_nome` virando `'—'` ou nome inventado; `ver_produto_url`/`criar_combo_url` não nulos |
| 23 | `visao_lista_nao_manda_grupo` | `grupos` montado em `visao=lista` (custo pago sem uso) |
| 24 | `nenhum_botao_sem_destino` | `href="#"`; "Criar combo" oferecido a quem já tem fase 2; `ver_produto_url` apontando para outra rota |
| 25 | `ordem_dentro_do_grupo_segue_o_prototipo` | Clássico antes de Premium; a data ganhando do tipo; o tie-break removido (ordem instável) |
| 26 | `rodape_bate_com_o_paginator` | `visiveis` ≠ `count()` da página; `total` recontado; `produtos` contando o grupo legado |
| 27 | `item_nao_tem_margem_nem_lucro` | `margem`/`lucro`/`repasse`/`tarifa` entrando no item — e a negativa roda com o item **CHEIO** |
| 28 | `nenhum_segredo_sai_no_payload` | `access_token`/`refresh_token` no payload (a empresa **TEM** `MlToken` no fixture); `autor_id` saindo junto do nome |
| 29 | `fase_responsavel_e_periodo_compoem_por_intersecao` | os três virando OU; `periodo` saindo de `created_at` do acervo; o filtro ignorado |
| 30 | `filtro_que_nao_casa_nada…` | `[]` tratado como `null` — o acervo INTEIRO apareceria |
| 31 | `responsavel_nunca_casa_ator_de_cliente` | o `equipe !== true` removido; nome de cliente no payload |
| 32 | `tipo_do_item_e_o_inverso_do_filtro_do_servico` | os dois mapas divergindo; `free` virando Clássico; `listing_tier` perdendo o valor cru |
| 33 | `catalogo_e_com_venda_exigem_bool…` | truthiness em vez de `$request->boolean()` (a string `'false'` ligaria o filtro) |
| 34 | `busca_por_nome_de_produto_chega_a_tela` | o 4º argumento do `escopo()` removido — **e o fixture não pode ter o termo no título, no SKU nem no `ml_item_id`** |
| 35 | `chips_contam_o_mesmo_universo_da_lista` | `triagem()` deixando de receber os filtros novos (os chips diriam um número maior que a lista) |
| 36 | `sincronizacao_manda_rotulo_humano…` | carimbo cru no lugar do rótulo; `new Date()` no componente; `Carbon::setTestNow()` removido (o caso passaria a depender do horário) |
| 37 | `acervo_nunca_coletado_nao_inventa_sincronizacao` | "Sincronizado há —"; `horas => 0` em vez de `null` |
| 38 | `faixa_de_sucesso_e_nula…` | um resumo de EXEMPLO no lugar do `null`; o dado mock do protótipo no payload |
| 39 | `custo_da_action_esta_travado` | qualquer consulta nova; um `where ml_item_id = ?` por linha (pego pelas DUAS camadas) |
| 40 | `custo_nao_cresce_de_um_para_cinquenta_itens` | o serviço de metodologia ou de cobertura indo para dentro do `through()`; a página não estando cheia (a premissa é asserida) |

---

## Prova por mutação — 13 aplicadas, 13 pegas, **1 sobrevivente investigada**

Cada uma: trava de contagem num script em **ARQUIVO** (`mutar.php`, **recusa alvo com ocorrência
≠ 1**), impressão de **QUAL linha** muda antes de aplicar, teste designado rodado **por método**,
restauração por **cópia pristina** e conferência por `sha256` — **igual nas 13**
(`283853b9…393a`). ⚠️ **ANSI removido com `sed 's/\x1b\[[0-9;]*m//g'` ANTES do grep do veredicto** —
é a armadilha que fez a campanha inteira do plano 180-03 parecer verde sem ter medido nada.

⚠️ **CONTROLE NEGATIVO da trava:** rodei o mutador com um alvo que aparece **20** vezes
(`AcervoTriagemService`) e ele **RECUSOU** com exit 1, deixando o arquivo com o `sha256` idêntico.
Sem esse controle, "nenhuma recusada" poderia ser "a trava não funciona".

| # | mutação | linha | teste que reprovou |
|---|---|---|---|
| a | tirar `->withQueryString()` | 706 | `paginacao_preserva_todos_os_filtros_nos_links` |
| b | `com_erro > 0` → `>= 0` no `comDot` | 891 | `com_dot_so_acende_quando_com_erro_e_maior_que_zero` |
| c | contagens passando a receber o filtro de status | 836 | `contagens_sao_sobre_os_demais_filtros` |
| d | interseção vazia passando a virar `null` | 1196 | `filtro_que_nao_casa_nada_mostra_zero_e_nao_o_acervo_inteiro` |
| e | `metodologiaPorMlItemId` virando UMA chamada POR LINHA | 726 | `custo_nao_cresce_de_um_para_cinquenta_itens` |
| f | `gold_pro` → `gold_premium` no mapa de tipo | 90 | `tipo_do_item_e_o_inverso_do_filtro_do_servico` |
| g | acrescentar `'margem' => 0` ao item | 804 | `item_nao_tem_margem_nem_lucro` |
| h | grupo legado reordenado para o topo | 1351 | `grupo_legado_e_proprio_e_fica_no_fim` |
| i | **extra** tirar `com_erro` da lista fechada do controller | 551 | `com_erro_e_o_setimo_valor_da_lista_fechada` |
| j | **extra** tirar o 4º argumento do `escopo()` da listagem | 696 | `busca_por_nome_de_produto_chega_a_tela` — ⚠️ **SOBREVIVEU na 1ª rodada, ver abaixo** |
| k | **extra** `responsavel` passando a casar ator de CLIENTE | 1473 | `responsavel_nunca_casa_ator_de_cliente` |
| l | **extra** `rotulo` virando o carimbo cru | 1439 | `sincronizacao_manda_rotulo_humano_e_nunca_data_crua` |
| m | **extra** `triagem()` deixando de receber os filtros | 812 | `chips_contam_o_mesmo_universo_da_lista` |

**As 13 linhas alvo foram conferidas uma a uma e todas são linha de CÓDIGO, nenhuma é comentário** —
este arquivo tem 4.476 linhas e comentários longos que citam `withQueryString`, `com_erro`,
`nota_base`, `acionaveis`, `margem`, `estoque` e `Legado`. Um replace cego pegaria a prosa.

### ⚠️ A sobrevivente (j): o defeito era MEU TESTE, vazio por construção

A mutação (j) tirou o 4º argumento de `escopo()` — ou seja, **desligou a busca por nome de produto** —
e `test_busca_por_nome_de_produto_chega_a_tela` **passou**. Investigado em vez de presumido
equivalente:

O fixture usava `ml_item_id = 'MLB-OSLO'` e o termo era `'Oslo'`. O **segundo** braço da busca
(`ml_item_id like '%Oslo%'`, com o `LIKE` do SQLite dobrando ASCII) casava sozinho. **O caso provava
que a busca acha por código MLB, não por nome de produto.**

Conserto: `ml_item_id` passou a `MLB7000000001`, título `'Assento decorativo veludo cinza'`, SKU
`'XYZ-1'` — **nenhum dos outros três identificadores contém o termo**. Refeita, a mutação **reprovou**
(`j2`). ➡️ **Sem essa mutação, o plano teria entregue um caso decorativo** sobre o requisito LIST-03,
que é o único gate de que o trabalho do plano 180-03 chega à tela.

---

## Gates rodados

| gate | resultado |
|---|---|
| baseline PHP `tests/{Feature,Unit}/Publicador` medida antes | **2039 passed / 15.418 assertions / 0 falhando** (459,23 s) — bate EXATAMENTE com a 180-03-SUMMARY |
| suíte depois | **2079 passed / 15.887 assertions / 0 falhando** (373,92 s) |
| o arquivo novo medido **em isolamento** | **40 passed / 469 assertions** |
| **fechamento aritmético** | 2039 + **40** = 2079 ✓ e 15.418 + **469** = 15.887 ✓ — nenhum teste existente perdeu asserção, e a soma **não é circular** porque o 40/469 foi medido sozinho |
| `tests/Feature/Phase134/MeusAnunciosTest.php` (a régua), **sem edição** | **25 passed / 110 assertions** — idêntico ao medido pelo plano 180-03 |
| os 2 destinos da Fase 179 em `CockpitDaContaTest` | 2 passed / 9 assertions |
| `test -e` nos 20 alvos de território | **19/20 existem**; 1 FANTASMA — ver abaixo |
| controle POSITIVO do gate de diff | o mesmo comando vê `856/12` e `1358/0` nos meus 2 arquivos |
| território de ninguém, range da FASE `f23f610d..HEAD` | **VAZIO** nos 13 caminhos (`app/Support/Publicador`, `MlPublicacaoService.php`, `AppLayout.jsx`, `Editor.jsx`, `Components/Publicador/Mesa`, `Pages/Mlb/AnunciosHistorico.jsx`, `header/PublicadorHeader.jsx`, `MeusAnunciosTest.php`, `database/migrations`, `composer.*`, `package*.json`) |
| território de outro plano, range do PLANO `f5b898d2..HEAD` | **VAZIO** em `AcervoTriagemService.php`, `PublicacoesDaListaService.php`, `tailwind.config.js`, `resources/js`, `tests/js`, `resources/css`, `routes`, `REQUIREMENTS-v26.md`. ⚠️ **`.planning/ROADMAP.md` aparece com +95/-0 e NÃO é meu** — ver abaixo |
| arquivos tocados nos MEUS 4 commits | exatamente **2** |
| deleções nos meus commits | **0** |
| migrations / índices / pacotes | **0 / 0 / 0** |
| linhas de frontend nos meus commits | **0** (`resources/js`, `tests/js`, `resources/css`, `tailwind.config.js` todos vazios) |
| asserções REMOVIDAS em `tests/` | **0** — enumeradas: `8b5ac633` = +158/-0 e `e6e45fb7` = +5/-0 |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| mutações | 13 aplicadas, 13 pegas, **1 sobrevivente investigada e o teste consertado**, 0 recusadas, `sha256` igual nas 13; controle NEGATIVO da trava conferido |
| `tests/js/estrutura-meus-anuncios.test.js` | **intocado** — a cirurgia nele é do plano 180-10, e nada do meu trabalho quebrou asserção dele (nenhuma linha de JS mudou) |

### ⚠️ O falso positivo do range da fase — 4º plano seguido

`.planning/ROADMAP.md` (+95), `.planning/STATE.md` e o diretório inteiro de
`.planning/phases/184-conexao-com-o-bling-…/` aparecem no range `f5b898d2..HEAD`. **Conferi o autor
antes de acusar**, como o learnings manda: vêm dos commits **`98b64c87`** (*"docs(roadmap): Fases 184
e 185"*) e **`45afd6fb`** (*"docs(184): contexto, pesquisa destilada e estratégia de validação"*), que
foram **interleavados entre os meus** pelo orquestrador/sessão paralela na mesma árvore
compartilhada. Os meus 4 commits são `18745843`, `5f0ef8af`, `8b5ac633`, `e6e45fb7` e tocam **2
arquivos, nada mais**.

### ⚠️ O 6º caminho fantasma da milestone

**`ROADMAP.md` na RAIZ do repositório NÃO EXISTE** — o real é **`.planning/ROADMAP.md`**. Um gate
escrito com o caminho da raiz daria diff vazio e "provaria" território intocado. Os 5 fantasmas
anteriores (registrados pelos planos 180-01/180-03) foram usados nos caminhos CERTOS e também deram
vazio: `resources/js/Components/Publicador/Mesa` (**sem** o `Mlb`),
`app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `resources/js/Pages/Mlb/AnunciosHistorico.jsx`
(**fora** de `Publicador/`) e `header/PublicadorHeader.jsx` (**dentro** de `header/`).

---

## ⚠️ PARA OS PLANOS 180-06 A 180-11: o que vocês ligam, e as 3 decisões que sobraram

```jsx
<AbasDeStatus abas={abas} ativa={filtros.status} onTrocar={…} />   {/* 6 entradas, ORDEM do servidor, `comDot` camelCase */}
<LinhaDeControles abas={<AbasDeStatus …/>} visao={filtros.visao} … />
<CabecalhoDePublicacoes sincronizacao={sincronizacao} acaoAtualizar={<BotaoAtualizarDoCabecalho …/>} />
<FaixaDeSucesso resumo={faixaSucesso} … />                        {/* hoje: SEMPRE null */}
{/* grupos / rodape / anuncios.data / triagem / filtros — ver a seção (1) */}
```

**Decisão 1 — `filtros.status` pode ser `encerrados` ou `acionaveis`, e nenhuma das 6 abas casa.**
`acionaveis` é o **default da tela** e os dois são destinos vivos da Visão Geral. A contagem deles
está em `contagens`. **O 180-06/180-10 têm de decidir** o que o trilho mostra nesses dois casos (uma
7ª/8ª aba condicional? a aba "Todas" marcada com um aviso? nada marcado?). ⚠️ **Não é decisão de
servidor** e eu não a tomei.

**Decisão 2 — `rotulo` dentro de `abas`.** Mandei porque `180-06-PLAN.md:94` o declara. Se o gate de
literais do 180-06 preferir fixá-los no componente, **ignore `rotulo`**: `chave` + ordem é o
load-bearing.

**Decisão 3 — `criar_combo_url` não pré-seleciona o produto.** Ver a seção (3). Se o 180-08 achar que
o botão promete mais do que entrega, o honesto é não desenhá-lo — e aí o servidor deve passar a
mandar `null`.

**E o que o 180-11 precisa saber:** `navegar()` vai de 4 para 12 params; `sub` continua preservado ao
trocar status e continua zerando `motivo`; os 7 filtros novos ficam **preservados na URL e ignorados
no servidor** em `sub=rascunhos` (a listagem de rascunhos não os usa), para que voltar a "Publicados"
restaure o recorte. ⚠️ **Zerar `page` ao filtrar é do 180-11** — o servidor já **tolera** `page` fora
de faixa sem explodir (caso 9), mas o vazio que ele devolve *parece* "não achei nada".

---

## O que NÃO foi feito (e por quê)

- **Nenhuma linha de frontend.** `resources/js`, `tests/js`, `resources/css` e `tailwind.config.js`
  dão diff vazio nos meus 4 commits. A suíte JS **não foi rodada** porque nada de JS foi tocado — a
  baseline JS (3828 / 3827 pass / 1 fail pré-existente em
  `tests/js/estrutura-grade-glide.test.js:122`) é a do plano 180-05, **citada e não remedida**.
- **`npm run build` não foi rodado** — sem mudança de frontend não há bundle a refazer.
- **Nenhuma tela vista renderizada.** Não há nenhuma afirmação de render neste plano: o que foi
  medido é o **payload** de `Inertia::render`, por requisição HTTP real nos testes.
- **Nenhum número de PRODUÇÃO medido.** Leitura de produção bloqueada nesta sessão. As 1.080.206
  linhas / 1.776 MB de `ml_acervo_itens` são medição anterior **citada**; `ml_acervo_itens` tem
  **0 linhas no banco local**, então todo cenário é sintético (SQLite em memória — `phpunit.xml:27`).
  ⚠️ **O custo de 33 consultas é do SQLite dos testes**, não do MariaDB/MySQL de produção: a
  CONTAGEM é a mesma (é o mesmo código), o TEMPO não foi medido.
- **Nenhuma migration, nenhum índice, nenhum pacote.** Os filtros novos operam dentro da fatia já
  restrita por `company_id`, como o `LIKE` de hoje; as 6 arestas do join do Publicador já são
  indexadas (reconferido pelo plano 180-02).
- **Nenhuma rota nova.** `mlb.anuncios.meus` já existe (`routes/mlb_anuncios.php:402`) e os 12
  parâmetros são querystring. `routes/` dá diff vazio.
- **`MeusAnunciosTest.php`, `AcervoTriagemService.php`, `PublicacoesDaListaService.php`,
  `estrutura-meus-anuncios.test.js`, o editor, a Visão Geral e o `historico()` não foram tocados.**
  A rota `historico`, o `historico()`, `Pages/Mlb/AnunciosHistorico.jsx` e os testes deles continuam
  inteiros — são o insumo da Fase 183.
- **`ROADMAP.md` e `REQUIREMENTS-v26.md` NÃO tocados** — a marcação é do fechamento da fase, e
  `requirements.mark-complete` devolve `not_found` para IDs de `REQUIREMENTS-vNN.md`.
- **`state.advance-plan` NÃO executado** (já corrompeu o `STATE.md` seis vezes); bloco aditivo à mão.
- **Nada deployado, nada empurrado, nenhum comando no VPS.**

### ⚠️ Duas honestidades sobre o processo

1. **A baseline "antes" rodou em paralelo com as minhas primeiras edições.** Disparei-a em segundo
   plano e comecei a editar o controller ~2 min depois; o `artisan test` levou 459 s. O número
   (**2039 / 15.418 / 0**) é **idêntico ao que a 180-03-SUMMARY registrou** no fim do plano anterior,
   e `f5b898d2` (o HEAD de partida) só mexeu em JS — a triangulação é o que valida a medição. O
   fechamento aritmético exato no fim fecha a prova.
2. **O commit `18745843` (Task 1) saiu SEM a linha `Co-Authored-By`.** Esqueci. Os outros três a
   têm. **Não corrigi com `git commit --amend`** porque a árvore é compartilhada com outra sessão
   ativa e reescrever commit publicado ali é destrutivo — a regra do projeto proíbe o `--amend`.

---

## Self-Check: PASSED

| verificação | resultado |
|---|---|
| `app/Http/Controllers/MlbAnuncioController.php` existe | ENCONTRADO (4.476 linhas, era 3.632) |
| `tests/Feature/Publicador/PublicacoesDaTelaTest.php` existe | ENCONTRADO (1.358 linhas, 40 métodos, 469 asserções) |
| `.planning/phases/180-publicacoes-lista/180-04-SUMMARY.md` existe | ENCONTRADO |
| commit `18745843` existe | ENCONTRADO |
| commit `5f0ef8af` existe | ENCONTRADO |
| commit `8b5ac633` existe | ENCONTRADO |
| commit `e6e45fb7` existe | ENCONTRADO |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| árvore limpa depois das 13 mutações | `git status --short` sem o controller; `sha256` de volta a `283853b9…393a` nas 13 restaurações, e o ATUAL é o mesmo |
| suíte `tests/{Feature,Unit}/Publicador` | 2079 passed / 15.887 assertions / **0 falhando** |
| `STATE.md` aditivo | ver `git diff --numstat` no commit desta SUMMARY — **0 remoções** |
