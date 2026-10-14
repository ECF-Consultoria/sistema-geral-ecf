---
phase: 180-publicacoes-lista
plan: 10B
subsystem: Publicador — tela de Publicações
tags: [publicador, publicacoes, checkpoint-do-usuario, cirurgia-de-gate, sku-por-variacao, botao-duplicado, divergencia-autorizada]
requires:
  - 180-05 (`CabecalhoDePublicacoes`, o slot `acaoAtualizar` e o export `BotaoAtualizarDoCabecalho`)
  - 180-08 (`publicacoes/CelulasDaLinha.jsx` e os gates por índice absoluto de `<span>`)
  - 180-10 (a composição da página, o `BotaoAtualizarAcervo.jsx` e as duas perguntas do checkpoint)
provides:
  - "o cabeçalho da PÁGINA sem botão: a tela deixou de ter dois `Atualizar` com a mesma ação"
  - "`skuDaVariacao()` e `tituloDoSku()` — o SKU por variação como funções puras exportadas de `CelulasDaLinha.jsx`"
  - "o pedaço do SKU na linha da publicação, aditivo, nas DUAS visões"
  - "a Decisão 3 do checkpoint REGISTRADA no código (`STATUS_DA_PUBLICACAO`), com o motivo de nada haver a implementar"
  - "2 reapontes com veto no gate do 180-08 e 1 veto novo no gate do 180-05 — zero asserção removida"
affects:
  - 180-11 (atalhos de teclado e estado na URL)
  - 181 (o painel de inspeção de 460px, que mostra o SKU do PRODUTO em `:382`)
tech-stack:
  added: []
  patterns:
    - "⚠️ ÓRFÃO DECLARADO em vez de remoção: quando o usuário tira algo da TELA, o contrato do componente fica. Apagar o export exigiria REMOVER asserções do gate dono — e o padrão deste projeto é `reaponte com veto`, nunca remoção"
    - "⚠️ Proxy de asserção que deixa de ser exclusivo: `font-pub-mono` provava o MLB até o SKU usar a mesma fonte. A troca certa é o LITERAL (exclusivo) + a CONTAGEM (que pega o retorno por outro caminho), nunca afrouxar a negativa"
    - "⚠️ Elemento novo no fim de uma lista cujas posições estão gateadas por índice absoluto: inserir no FIM preserva os índices de todos os anteriores, e a CONTAGEM de elementos é o que prova que ele ficou lá"
    - "⚠️ Contagem de testes esperada MEDIDA em dois regimes (build fresco × fonte mutado), não chutada: a sonda de CSS entra em `skip` por mtime, e a campanha inteira marcaria `SOBREVIVEU` por um motivo que não é o código"
key-files:
  created:
    - .planning/phases/180-publicacoes-lista/180-10B-PLAN.md
    - .planning/phases/180-publicacoes-lista/180-10B-SUMMARY.md
  modified:
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/CelulasDaLinha.jsx
    - tests/js/estrutura-meus-anuncios.test.js
    - tests/js/publicador-paginas-anuncios-render.test.js
    - tests/js/publicador-publicacoes-cabecalho.test.js
    - tests/js/publicador-publicacoes-tabela.test.js
decisions:
  - "o botão 'Atualizar' saiu do cabeçalho DA PÁGINA e o 'Atualizar agora' do PublicadorHeader ficou — divergência deliberada do protótipo (`:39`), autorizada pelo usuário em 14/10/2026"
  - "`BotaoAtualizarDoCabecalho` e o slot `acaoAtualizar` FICAM: órfão declarado, como o `CelulaSku`"
  - "o SKU por VARIAÇÃO voltou à linha — também divergência do protótipo (ele não desenha SKU na linha), mas com a TIPOGRAFIA dele (`:178`/`:382`)"
  - "o SKU aparece nas DUAS visões: na 'Por produto' a linha de grupo diz o SKU do PRODUTO, que é outro dado"
  - "o rótulo 'SKU' FICA (o protótipo mostra o código cru, mas em contexto onde a coluna o identifica) — e é o contrato que o `CelulaSku` de hoje já tinha"
  - "Decisão 3 ('Em revisão' + com erro): o status real do ML ganha = comportamento de hoje. NADA implementado, tudo registrado"
metrics:
  duration: ~2h30
  completed: 2026-10-14
  tasks: 3
  commits: 4
  testes-js-novos: 19
  mutacoes: "8/8 pegas, 0 sobreviventes, 0 recusadas (3 controles negativos recusados)"
---

# Fase 180 Plano 10B: as duas mudanças que o usuário pediu no checkpoint — Summary

**Duas decisões executadas e uma registrada.** A tela de Publicações deixou de ter dois botões
"Atualizar" com a mesma ação, o SKU por variação voltou à linha de forma aditiva, e a terceira
resposta do usuário — a que **não gera trabalho** — ficou escrita no código, na SUMMARY e no STATE,
para nenhuma fase futura "consertar" o que foi escolhido.

**Commits:** `e481c7ce` (o plano) · `72cc4823` (o botão) · `0f3c9b49` (o SKU) · este (SUMMARY + STATE).

---

## (0) ⚠️ O QUE FOI MEDIDO ANTES DE TOCAR EM NADA

O briefing avisava: *"a premissa 'mesma ação' é do relatório do 180-10, não medição minha — se você
achar que as duas ações NÃO são a mesma, PARE e devolva a pergunta."* **Medi, e a premissa está
certa**, por três fatos independentes:

| fato | onde | medida |
|---|---|---|
| os dois botões recebem o MESMO handler | `MeusAnuncios.jsx:629` e `:653`-`:656` | `atualizando`, `cooldown` e `onClick={atualizarAgora}` **idênticos** nos dois |
| `atualizarAgora` é a única porta de escrita | `:394`-`:398` | **UM** `router.post`, para `mlb.anuncios.meus.atualizar` (D-11), e o gate já cobrava a contagem = 1 |
| o próprio arquivo já declarava a duplicidade | `BotaoAtualizarAcervo.jsx:30` | *"Os dois chamam o MESMO `atualizarAgora` — um `router.post`, nenhum a mais (D-11)"* |

➡️ **Não há escopo diferente de coleta.** Era um botão só, duplicado na tela — e nada a devolver
como pergunta.

E as outras três medições que mudaram o plano:

| pergunta | resposta MEDIDA |
|---|---|
| o "● Sincronizado há X" depende do botão? | **NÃO.** `CabecalhoDePublicacoes.jsx:193`-`:201`: são irmãos independentes no bloco da direita, e a única condição do selo é `rotulo !== null`. O botão sai, o selo **fica** |
| o SKU por VARIAÇÃO chega no payload? | **SIM**, sem tocar no controller: `MlbAnuncioController.php:740` → `'skus' => $item->skus ?? []`, e a migration `2026_10_10_170000` diz literalmente *"lista dos SKUs DISTINTOS do anúncio (pai + variações)"* |
| o protótipo desenha SKU na linha? | **NÃO.** `grep -ni sku` em `Publicacoes v2.dc.html`: `:70` e `:288` são textos da busca, **`:178` é a linha de GRUPO** e **`:382` é o painel de 460px** (Fase 181) |

---

## (1) ⚠️ AS TRÊS REFERÊNCIAS DE LINHA DO BRIEFING ESTAVAM DESLOCADAS

O briefing manda proteger `D-11` e `D-22` citando
`tests/js/estrutura-meus-anuncios.test.js:72`, `:78` e `:86`. **Medido: nenhuma das três aponta para
o que o briefing descreve** — é a armadilha §1 do learnings (referência que envelhece), e eu
identifiquei os três por **SEMÂNTICA**:

| o intocável | linha REAL | tocado? |
|---|---|---|
| `D-11: nenhum router.put/delete/patch — a tela nunca escreve no ML` | **`:250`** | **não**, zero caractere |
| `D-11: o único router.post existente aponta para meus.atualizar` | **`:256`** | **não**, zero caractere |
| `D-22: a nota aparece como "de 86", nunca convertida para 100` | **`:285`** | **não**, zero caractere |

➡️ Provado por `git diff e481c7ce~1..HEAD`: as 99 linhas que entraram naquele arquivo são **todas
acrescentadas no FIM** (`+99 / -0`), e `-assert` = **0**.

---

## (2) DECISÃO 1 — o botão da PÁGINA saiu

**O que mudou, e só isso:** `MeusAnuncios.jsx` (+38 / −11)

- `:8` — o named import caiu; ficou o default sozinho.
- o mount passou de 10 linhas com `acaoAtualizar={…}` para
  `<CabecalhoDePublicacoes sincronizacao={sincronizacao ?? null} />`, com **35 linhas de comentário
  anti-reversão** no lugar: o que havia, por que saiu, por que saiu ESTE, e o aviso de que é
  divergência do protótipo autorizada.

**O que NÃO mudou, conferido por diff vazio com `test -e` antes:**
`header/PublicadorHeader.jsx` (somente leitura, contrato de 9 páginas),
`components/BotaoAtualizarAcervo.jsx` (o botão que fica),
`publicacoes/CabecalhoDePublicacoes.jsx` (o slot e o export continuam),
`atualizarAgora` e o `router.post` (segue **= 1**).

### ⚠️ Por que o export ficou ÓRFÃO em vez de ser apagado

`BotaoAtualizarDoCabecalho` é cobrado em **6 pontos** do gate do 180-05
(`publicador-publicacoes-cabecalho.test.js:125`, `:243`, `:248`, `:634`, `:740`-`:741`, `:774`),
incluindo um **laço de render adverso**. Apagá-lo exigiria **remover asserções** — o oposto do que o
briefing manda (*"a cirurgia é reaponte com veto, nunca remoção da asserção"*). Ele fica **órfão
declarado**, o padrão do `CelulaSku` deste mesmo arquivo e do `PainelVisaoGeral.jsx` da Fase 179.

⚠️ **E o gate do 180-05 não precisou de UM reaponte:** ele testa o componente **em isolamento**, com
fixture própria (`acaoAtualizar: React.createElement(BotaoAtualizar, …)`). O que entrou lá foi
**veto puro** (`-assert: 0`, `+assert: 6`).

### O gate de FONTE: `CIRURGIA 3`

Molde da `CIRURGIA 2` do 180-10 — negativa com o valor antigo escrito na mensagem, mais o
**endurecimento que justifica a negativa**:

| asserção | o que precisaria estar errado para falhar |
|---|---|
| `doesNotMatch(fonte, /BotaoAtualizarDoCabecalho/)` | o mount **ou** o import voltarem |
| `doesNotMatch(fonte, /acaoAtualizar/)` | a página voltar a preencher o slot |
| `match(fonteCabecalho, /export function BotaoAtualizarDoCabecalho\(/)` | alguém **apagar** o componente do 180-05 — que não foi autorizado |
| `match(fonteCabecalho, /acaoAtualizar = null/)` | o **slot** sair do contrato do cabeçalho |
| `match(fonteCabecalho, /PREFIXO_SINCRONIZADO = 'Sincronizado'/)` | o "● Sincronizado há X" sair junto com o botão |
| `match(fonte, /sincronizacao=\{sincronizacao \?\? null\}/)` | a página parar de passar o dado do selo |
| `match(fonte, /acoes=\{\(\s*<BotaoAtualizar …\)\}/)` | ⚠️ **a tela perder os DOIS botões** — sem esta, o teste passaria no caso oposto ao decidido |
| `equal(router.post, 1)` + `match(/meus\.atualizar/)` + `match(/function atualizarAgora\(\)/)` | a ação desaparecer ou multiplicar |

### O gate de RENDER: localização por ÁRVORE

Nasceu o helper **`headerDaPagina(html)`**, que pega o **SEGUNDO** `<header>` por índices
absolutos. ⚠️ `html.indexOf('<header')` pegaria o **GLOBAL** — é o furo §4.7 do learnings (quatro
gates do ERP mediam a linha do Mercado Livre).

| sub-teste | o que prova |
|---|---|
| ⚠️ **ISCA** | `/Atualizar<\/button>/` **casa** com `Atualizar`, e **NÃO** casa com `Atualizar agora` nem `Atualizando…`. Sem o `</button>`, `/Atualizar/` seria **vazia por construção** (`Atualizar` é prefixo dos outros dois) |
| ZERO `<button>` no cabeçalho da página | a contagem exata, não a ausência de um literal |
| o H1 e o `Sincronizado` ficaram | só o botão saiu — e o `bg-pub-ok` prova o "●" |
| a `<nav>` do header global ainda tem `Atualizar agora` | não perdemos os dois |

E **um comentário** foi corrigido (a única linha removida daquele arquivo): ele descrevia o
cabeçalho da página como "H1 + Sincronizado + **Atualizar**". Comentário que mente produz o próximo
erro.

---

## (3) DECISÃO 2 — o SKU por VARIAÇÃO voltou

`CelulasDaLinha.jsx`: **+105 / −0**, puramente aditivo.

### A forma, medida no protótipo

O protótipo não desenha SKU na linha, mas desenha SKU **duas vezes** em outros lugares, com a
**mesma** tipografia:

| onde | medida literal |
|---|---|
| `:178` (linha de grupo) | `<span style="font-family:'Geist Mono',monospace;font-size:11.5px">{{ g.sku }}</span> · Estoque N` |
| `:382` (painel de 460px) | `font-family:'Geist Mono',monospace;font-size:11.5px;color:#8A8A90` |

➡️ **O vocabulário do SKU neste protótipo é Geist Mono / 11.5px / `#8A8A90`.** E a linha meta da
célula (`:212`) **já é** `text-[11.5px] text-pub-txt-3` — então o pedaço **não redeclara tamanho nem
cor**, exatamente como o MLB de `:213` não redeclara. ⚠️ Há asserção **negativa** contra
`text-[11.5px]`, `text-pub-txt-3` e `text-pub-neutral-txt` no pedaço: redeclarar "por segurança" é a
adaptação que o usuário recusou (FID-02).

O separador é o de `:218` (`color:#52525B;flex:none` → `flex-none text-pub-txt-off`), e o `flex-none`
do pedaço é o de `:213`: **quem encolhe é o nome do produto** (`min-w-0 truncate`), não o SKU que o
usuário pediu para ver.

### As peças

```
MLB4402230188 [copiar] · Fone Bluetooth XYZ · SKU FON-001-P +1
                                              └──────┬──────┘
                              title="2 SKUs (um por variação): FON-001-P, FON-001-G"
```

| decisão | motivo |
|---|---|
| **no FIM da linha meta** | o gate do 180-08 trava título/meta/MLB em `spans[1]`/`[2]`/`[3]` (índices absolutos). Inserir no meio deslocaria os três. ⚠️ E há asserção da **CONTAGEM** de spans da célula (**9** em Lista, **6** em Por produto) — é ela que prova que o pedaço ficou no fim |
| **rótulo `SKU `** | divergência declarada de `:178`/`:382`, que mostram o código cru: ali a **coluna** diz que aquilo é SKU; aqui o código ficaria colado ao MLB, e código nu é jargão (regra permanente do projeto). ⚠️ **E é o que o usuário já tinha**: o `CelulaSku` de hoje escreve `SKU FON-001` |
| **`+N` + `title` com a lista** | contrato do `CelulaSku` preservado byte a byte. Em anúncio com variações o SKU é **por variação**: mostrar um só sem dizer que há outros mentiria |
| **aparece nas DUAS visões** | a linha de grupo mostra `produto_sku` (**do produto**); este é o da **VARIAÇÃO**. ⚠️ Asserção de que a célula **não lê** `produto_sku`, e o fixture tem os dois com valores DIFERENTES (`FON-001` × `FON-001-P`) — sem isso o gate passaria com a linha mostrando o SKU do produto |
| **sem SKU → NADA** | nem `—`, nem string vazia, nem separador solto. O controller colapsa `null` em `[]` (`:740`); quem distingue "ainda não coletado" é `skuNaoColetado`, que já vive na tela |
| **dedup** | a migration promete "DISTINTOS", mas `['A','A','  A  ']` viraria `SKU A +2`, falso sobre o anúncio |
| **sem `.map(`, sem `String(`** | o gate do 180-08 proíbe `.map(` nos três arquivos (o Rollup elimina variável de escopo usada no callback) e `String({toString:null})` **LANÇA**. O laço é `for…of` com guarda de tipo, e o `join` só vê strings já filtradas |

### ⚠️ A CIRURGIA nos gates do 180-08 — os DOIS reapontes, par por par

`ITEM_CHEIO` ganhou `skus: ['FON-001-P','FON-001-G']` — assim o laço adverso (contagem **derivada**)
cobre a chave nova em 18 formas + a ausência, e as negativas do bloco novo deixam de ser **vazias por
construção** (learnings §4.10). ⚠️ **Isso fez exatamente DUAS asserções reprovarem o código CERTO**
(medido: 3 falhas, só essas — nenhuma outra):

| # | linha `-` removida | por que reprovava o CERTO | o que entrou |
|---|---|---|---|
| 1 | `assert.ok(comNome.includes('text-pub-txt-off'))` e `assert.ok(!semNome.includes('text-pub-txt-off'))` | o SKU tem o SEU separador, com a MESMA classe do `:218`: *sem nome mas com SKU* o separador existe **e deve existir** | **CONTAGEM EXATA nas 4 combinações** nome × SKU: **2 / 1 / 1 / 0**. Pega separador a mais **e** a menos, e cobre os dois casos que a antiga não via |
| 2 | `assert.ok(!celula.includes('font-pub-mono'))` com `ml_item_id: null` | `font-pub-mono` era **PROXY** do MLB e deixou de ser exclusivo dele | o **LITERAL** do MLB (`MLB4402230188`, exclusivo) + `Copiar MLB` + **contagem de mono = 1** (o do SKU) + **ISCA** de que sem os dois é **0**. Pega o MLB voltando por outro caminho |

```
tests/js/publicador-publicacoes-tabela.test.js   -assert: 3   +assert: 65   (+262 / -7)
```
⚠️ **As 3 linhas `-assert` são as 3 acima** — enumeradas uma por uma, porque `grep '^-.*assert'`
não distingue reaponte de remoção. **`+assert > -assert`**, e **nenhum teste perdeu propósito**: as
duas asserções antigas continuam provadas, com mais força.

⚠️ **E CONTEI TESTES, não linhas de `assert`** (o furo que o 180-10 declarou: uma linha removida sem
a palavra `assert` instalava 6 testes). O gate foi de **118 → 130**.

### O bloco novo (11 sub-testes) e a sonda de CSS (1)

| sub-teste | o que precisaria estar errado |
|---|---|
| a FONTE é `item.skus`, nunca `produto_sku` | a linha mostrar o SKU do PRODUTO fingindo ser o da variação |
| as classes por igualdade de conjunto e por ÁRVORE | o pedaço perder `flex-none`/`font-pub-mono`, redeclarar tamanho/cor, ou **mudar de posição** (contagem de spans) |
| o rótulo + o código + o `+N` | a forma do pedaço mudar |
| UM SKU → nenhum `+N`; duplicata não conta | afirmar uma variação que não existe |
| SEM SKU: 5 formas + a chave ausente | um `—`, um `SKU ` vazio, um separador órfão, ou `[object Object]`/`NaN` |
| lista SUJA (9 itens, 2 válidos) | a guarda de tipo cair, ou os inválidos entrarem na contagem |
| o `title` nas duas formas | perder a lista inteira |
| visão "Por produto" | o SKU da variação sumir, ou o nome do produto voltar a repetir |
| `skuDaVariacao()` puro, 18 formas × valor e × item da lista | lançar, ou aceitar array-like/string como lista |
| ⚠️ a página **não monta** `<CelulaSku` | o SKU aparecer **duas vezes** na mesma linha |
| **CSS construído**: `.font-pub-mono` = `Geist Mono` | a classe existir com a **pilha errada**; ou `font-pub` (que é **prefixo** dela) ser usada no lugar. Com **ISCA** (`font-pub-mono-sku-7z`, medida como inexistente no repo inteiro por `grep -rF`) |

⚠️ **Os dois gates são necessários e não se substituem** (learnings §3): o de HTML prova que o
**elemento** tem a classe; o de CSS prova que a **classe existe com o valor certo**.

---

## (4) DECISÃO 3 — nada implementado, tudo registrado

Sobre um anúncio **"Em revisão" E com erro**, o usuário escolheu **"o status real do ML ganha"** —
que é **o comportamento de hoje**.

➡️ **ZERO mudança de código.** O que entrou foi um bloco de **17 linhas de comentário** em
`CelulasDaLinha.jsx`, no docblock de `STATUS_DA_PUBLICACAO`, dizendo que **este mapa de QUATRO
entradas é o comportamento ESCOLHIDO, não um esquecimento**, e que uma 5ª entrada (ou uma precedência
de `com_erro` sobre o status) é o **oposto** da decisão.

O que continua valendo, sem mudança: `com_erro` chega no item (180-04B), o recorte "Com erro" vive
**na aba** (o servidor filtra por `sub_status`) e **nos chips de triagem**, a célula mostra o estado
real do ML e o `motivos[0]` embaixo, e **nada é derivado de `severidade`**.

---

## (5) AS 8 MUTAÇÕES — 8/8 PEGAS, 0 sobreviventes, 0 recusadas

Disciplina aplicada: contagem do alvo **travada em 1 antes** (recusa se ≠ 1), **qual linha mudou**
conferida depois, **ANSI removido antes de filtrar**, **contagem de testes comparada**, restauração
por cópia pristina com **sha256**.

⚠️⚠️ **E a contagem esperada foi MEDIDA em DOIS REGIMES, não chutada.** Os dois gates com sonda de
CSS entram em `contexto.skip()` quando o fonte fica mais **novo** que o build (comparação de
`mtime`) — e **mutar o arquivo o deixa mais novo**. Medi os dois regimes antes de começar:

| gate | FRESCO (build novo) | MUTADO (fonte novo) |
|---|---|---|
| `estrutura-meus-anuncios` | 30 | 30 |
| `publicador-paginas-anuncios-render` | **119** | **114** |
| `publicador-publicacoes-tabela` | **130** | **118** |

➡️ Sem isso a campanha **inteira** marcaria "SOBREVIVEU" por um motivo que não é o código. (A
primeira execução abortou com `BASE com contagem inesperada: 119` — e foi assim que o regime apareceu.)

| # | mutação | linha | reprovou em (falhas) |
|---|---|---|---|
| i | **devolve** o botão ao cabeçalho da página (import + mount) | `MeusAnuncios.jsx:8` e `:686` | `estrutura-meus-anuncios` (1) **e** `paginas-anuncios-render` (2) |
| ii | **remove** os dois nós do SKU da célula | `CelulasDaLinha.jsx:643`+ | `publicacoes-tabela` (**12**) |
| iii | lista vazia inventa um `—` de SKU | `:498` | `publicacoes-tabela` (6) |
| iv | tira a guarda de TIPO do item da lista | `:492` | `publicacoes-tabela` (6) |
| v | tira o dedup | `:494` | `publicacoes-tabela` (2) |
| vi | o separador do SKU é desenhado SEMPRE | `:643` | `publicacoes-tabela` (4) |
| vii | `font-pub-mono` → `font-pub` no código do SKU | `:648` | `publicacoes-tabela` (8) |
| viii | **remove** o "Atualizar agora" do slot do header global | `MeusAnuncios.jsx:628`+ | `estrutura-meus-anuncios` (2) **e** `paginas-anuncios-render` (6) |

⚠️ **As duas obrigatórias do briefing são a i e a ii, e as duas pegaram.** A **viii** existe para
provar o outro lado: que não tiramos o botão errado.

**sha256 final IDÊNTICO ao pristino nos dois arquivos:** `MeusAnuncios.jsx` `5c37314164c53eea…`,
`CelulasDaLinha.jsx` `1680ed068f832901…`.

**CONTROLE NEGATIVO da trava de contagem** — três alvos com **2, 6 e 8** ocorrências foram
**RECUSADOS** e o `sha256` ficou **INTACTO** nos três. Logo a trava não é decorativa.

---

## (6) VERIFICAÇÃO

| gate | resultado |
|---|---|
| `estrutura-meus-anuncios.test.js` | **30 / 30 / 0** (era 29) |
| `publicador-paginas-anuncios-render.test.js` | **119 / 119 / 0** (era 114) |
| `publicador-publicacoes-cabecalho.test.js` (180-05) | **73 / 73 / 0** (era 72) |
| `publicador-publicacoes-tabela.test.js` (180-08) | **130 / 130 / 0** (era 118) |
| `publicador-meus-anuncios-sku-render.test.js` | **10 / 10 / 0**, **diff VAZIO** — zero reaponte, o `CelulaSku` órfão intocado |
| `publicador-vocabulario-novo.test.js` | **338 / 338 / 0**, intocado |
| `publicador-publicacoes-filtros.test.js` · `-busca.test.js` | **112** e **114**, verdes e intocados |
| `npm run test:js` **ANTES** | **4427 / 4426 pass / 1 fail / 0 skipped** |
| `npm run test:js` **DEPOIS** | **4446 / 4445 pass / 1 fail / 0 skipped** |
| a aritmética do delta | **+19** = +1 (fonte) +5 (render) +1 (cabeçalho) +12 (tabela). **Fecha exatamente** |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido") — **não corrigida**, de propósito |
| `npm run build` | exit **0** (49,6 s na 1ª rodada, 22,6 s depois da campanha) |
| manifest **pelo JSON** | **479 entradas / 196 páginas** — exatamente a referência. `Pages/Mlb/MeusAnuncios.jsx` → `assets/MeusAnuncios-BZsWvogV.js` **PRESENTE**; `AnunciosHistorico`, `Publicador/Editor` e `Publicador/Configuracoes` presentes. CSS `assets/app-3tlHZ44i.css`, 222.857 bytes |
| sonda de CSS | **rodou, não pulou** (0 skipped nos dois gates com sonda) |
| `publicador-visao-geral-acoes.test.js` (gate do ERP) | **214 / 214 / 0** e **diff VAZIO** |
| `CabecalhoDoPublicadorTest.php` · `CockpitDaContaTest.php` (gates do ERP) | **diff VAZIO**, e `grep` prova que **nenhum dos dois referencia** `MeusAnuncios`, `CelulasDaLinha` ou `resources/js` (0 ocorrências) — meus 3 commits têm **zero linha de PHP**, logo não há caminho por onde eu os quebrasse |
| suíte PHP | ⚠️ **NÃO RODADA, de propósito** (instrução do briefing: a outra sessão commita PHP e medir a área dela mediria trabalho alheio). Zero PHP tocado |
| `test -e` nos **31** alvos de território | **31/31 existem** — zero caminho fantasma |
| território **commit por commit** | **0** arquivo proibido, **0** deleção, **0** linha de PHP nos **três** commits. ⚠️ Prova por commit, **não** por range: a sessão paralela commitou `1464045e docs(184)` **entre** os meus |
| **CONTROLE** do gate de território | o MESMO comando **vê** os 6 arquivos que eu de fato toquei (`307/0`, `38/11`, `99/0`, `74/1`, `49/0`, `105/0`, `262/7`) — logo não é vazio por construção |
| o discriminador de sessão | o **ESCOPO DO ASSUNTO** (`180-*` × `184-*`). ⚠️ **NÃO conferi autor**: as duas sessões usam o mesmo `user.name`, e um gate por `%an` é vazio por construção aqui |
| árvore | limpa nos meus alvos (`git status --short -- resources/js tests/js` → vazio) |

---

## (7) ⚠️ O QUE OS GATES **NÃO** PROVAM — escrito, não maquiado

1. ⚠️⚠️ **NENHUMA TELA RENDERIZADA FOI VISTA.** Não abri navegador, não deployei, não toquei no VPS
   e **não li produção** (bloqueada nesta sessão). O banco local **não tem empresa com `ml_token`**
   (`meus()` faz `abort_unless($company->mlToken !== null, 404)`) e **`ml_acervo_itens` está
   VAZIA** — todo cenário é sintético (`esbuild` + `react-dom/server`). ➡️ **Que o SKU caiba na
   coluna de 300px com título longo + nome de produto longo é VALIDAÇÃO VISUAL com o usuário**, não
   gate: o `flex-none` garante que o SKU não é o primeiro a ser cortado, e isso é tudo o que o render
   de servidor consegue afirmar.
2. ⚠️ **O comportamento do `title` (tooltip ao passar o mouse)** é provado pelo **ATRIBUTO**, não
   pelo runtime.
3. ⚠️ **A eliminação de variável pelo Rollup** não é reproduzível em `renderToStaticMarkup`. A
   proteção aqui é **estrutural** (zero `.map(` nos três arquivos, com gate próprio do 180-08) e
   está declarada.
4. ⚠️ **Os dois gates PHP do ERP não foram EXECUTADOS** (§6). A prova é diff vazio + zero PHP + zero
   referência deles aos meus arquivos — é evidência estrutural, não medição de execução.
5. ⚠️ **A distinção `null` × `[]` em `skus` não chega ao cliente**: o controller faz
   `$item->skus ?? []` (`:740`), e isso é **território do plano 180-04**. Para a linha os dois casos
   são iguais (nada desenhado), e quem distingue "ainda não coletado" é `skuNaoColetado`. **Lacuna
   declarada, não consertada** — consertá-la exigiria editar o controller, que o briefing proíbe.

---

## Known Stubs

Nenhum stub novo. **E um stub do 180-10 foi RESOLVIDO:**

| o que o 180-10 declarou | estado agora |
|---|---|
| *"o SKU por variação na linha — a célula nova não o desenha; o export `CelulaSku` ficou órfão declarado"* | ✅ **RESOLVIDO** por este plano. `CelulaSku` continua órfão (gate próprio, intocado), e o SKU é desenhado pelo componente novo — com asserção de que a página **não** monta os dois |

As demais ausências daquela lista (faixa de sucesso, chip "Responsável", margem na célula Preço,
badge NOVA, barra de ação em massa) seguem **medidas e declaradas** lá, sem mudança.

E um item saiu da lista de pendências por **decisão**, não por implementação:

| item | estado |
|---|---|
| *"'Com erro' na linha — `com_erro` chega, mas a precedência é decisão de UI"* | ✅ **DECIDIDO pelo usuário em 14/10/2026**: o status real do ML ganha = comportamento de hoje. **Nada a implementar**, e o motivo está escrito no próprio `STATUS_DA_PUBLICACAO` |

## Threat Flags

Nenhuma superfície nova. **Zero** rota, endpoint, caminho de auth, acesso a arquivo ou mudança de
schema. `D-11` intacto (um `router.post` só, para a rota que **enfileira**). O `title` e o texto do
SKU saem de `item.skus` com guarda de tipo por item, e o `join` só vê strings já filtradas —
`[{toString:null}].join()` **lançaria**. **Nenhum pacote novo. Nenhuma migration. Nenhuma deleção.**

## Self-Check: PASSED

| o que | resultado |
|---|---|
| os 2 arquivos criados existem (`test -f`) | **2/2 FOUND** (`180-10B-PLAN.md`, `180-10B-SUMMARY.md`) |
| os 6 arquivos modificados existem | **6/6 FOUND** |
| os 3 commits existem (`git log --oneline --all`) | **3/3 FOUND** — `e481c7ce`, `72cc4823`, `0f3c9b49` |
| deleções em cada commit (`--diff-filter=D`) | **0 nos três** |
| `+assert > -assert` nos 4 gates tocados | **10>0 · 12>0 · 6>0 · 65>3** |
| as 3 linhas `-assert` enumeradas uma por uma | **sim** (§3), com o par `+` de cada |
| D-11 (×2) e D-22 intocados | **sim**, identificados por SEMÂNTICA (`:250`, `:256`, `:285`) — as 3 referências do briefing estavam deslocadas |
| 8 mutações | **8/8 pegas**, 0 sobreviventes, 0 recusadas, 3 controles negativos recusados |
| `sha256` pós-campanha | **IDÊNTICO** nos dois arquivos |
| JS antes/depois | 4427/4426/1 → **4446/4445/1** (+19, aritmética fechada), mesma falha pré-existente |
| `wc -l` dos arquivos tocados | `MeusAnuncios.jsx` **929** · `CelulasDaLinha.jsx` **1004** · `publicacoes-tabela.test.js` **2094** |
| arquivo gerado/não rastreado deixado para trás | **nenhum** — os 5 scripts (campanha, controle, manifest, território, dump) vivem no scratchpad da sessão, fora do repo |
| `STATE.md` aditivo | **+2 / −0** por `git diff --numstat` |
| `ROADMAP.md` / `REQUIREMENTS-v26.md` | **não tocados**; `state.advance-plan` **não executado** |
