---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 07
subsystem: publicador-aprovacao-painel
tags: [tela, portal, z-index, teclado, fidelidade, col-04]
requires:
  - "188-02 (o view-model do painel: `identificacao`, `falha`, `navegacao`)"
  - "Fase 186 (`andamentoDoPortao.jsx`, `AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE`)"
  - "Fase 187 (a página da fila, `CascaDeModal`, `ToastDaFila`, `BarraDeSelecao`)"
provides:
  - "a casca do painel de 1140px, POR PORTAL, acima do header e sem alterá-lo"
  - "o topo de 54px com os QUATRO elementos (o quinto é da Fase 190/191)"
  - "os atalhos Esc / ↑↓ / ←→ como FUNÇÃO PURA (`resolverAtalhoDoPainel`)"
  - "as DUAS COLUNAS DE MESMO PESO, com os dois slots NOMEADOS e VAZIOS"
  - "`escreverNoPainel()` — o helper único de escrita, com `versao_esperada` SEMPRE"
affects:
  - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx (o quinto slot: `painelNo`)"
tech-stack:
  added: []
  patterns:
    - "createPortal + guarda de ambiente + named exports (o padrão da Fase 178)"
    - "`font-pub` na raiz do portado (REND-05)"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/TopoDoPainel.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/IdentificacaoDaOferta.jsx
    - tests/js/publicador-painel-casca-188.test.js
    - tests/gates/contagens-188-07.txt
  modified:
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "PERGUNTA-188-B3 aplicada: 'Abrir revisão detalhada ↗' NÃO é desenhado — critério 2 com RESSALVA"
  - "o `margin-left:auto` de `:558` passa para o × (mudança de DONO, não de VALOR)"
  - "seta sem destino NÃO é desenhada (régua de PERGUNTA-188-B, não escolha nova)"
  - "o gate da rota ausente lê `git show HEAD:`, não a árvore (WIP de outra sessão)"
  - "FID-01: o protótipo GANHA no `Esc` disparar TAMBÉM digitando (`:1015` vem depois de `:1014`)"
metrics:
  tasks: 3
  commits: 3
  duration: "~5h"
  completed: 2026-10-19
status: ENTREGUE COM PERGUNTA EM ABERTO (quatro gates de FECHAMENTO da Fase 187)
---

# Fase 188 Plano 07: a casca do painel de 1140px por portal — Summary

O painel de revisão simples abre **por portal** para o `document.body`, acima do header e **sem
tocá-lo**, com o topo de 54px, os quatro atalhos de teclado provados como **função pura**, a
identificação com os cinco chips, a caixa de falha com a mensagem **real** do Mercado Livre e as
**duas colunas de mesmo peso** — com os dois slots (fotos e preço) **nomeados e vazios**.

---

## ⚠️⚠️ PARE AQUI: QUATRO GATES DE FECHAMENTO DA FASE 187 REPROVAM, E O CONSERTO **NÃO É DO MEU LADO**

**Devolvo a pergunta, como o briefing manda, e NÃO toquei em nenhum dos quatro.**

Os quatro são **gates de FECHAMENTO da Fase 187** (que está **EM PRODUÇÃO**) e reprovam pelo
**próprio trabalho correto desta fase**: os três afirmam **TOTAIS** ("exatamente 4 slots",
"exatamente 21 `.jsx`") num diretório e numa página que a **Fase 188 existe para acrescentar**, e o
quarto é uma **isca invertida** cuja mensagem **já contém a instrução do conserto**.

| # | arquivo:linha | o que afirma | por que reprova | id(s) e manifesto |
|---|---|---|---|---|
| 1 | `tests/js/publicador-fila-cabecalho-187.test.js:1509` | `isValidElement(` aparece **exatamente 4** vezes na página | o plano 188-07 prescreve o **QUINTO** slot (`painelNo`, o quarto par nó×dado) → **5** | `cabecalho-da-fila`, `faixa-de-andamento`, `pagina-e-contagem` — **TRÊS ids sobre o MESMO arquivo**, em `contagens-187-04.txt:62`/`:76`/`:99` |
| 2 | `tests/js/publicador-fila-proximo-187.test.js:822` | idem (exatamente 4) | idem | `proximo-render`, `contagens-187-05.txt:120` |
| 3 | `tests/js/publicador-fila-fechamento-187.test.js:79` | `aprovacao/` tem **exatamente 21** `.jsx`, e nada fora das listas 187+186 | os **TRÊS `.jsx`** deste plano → **24**. ⚠️ E a `188-REGUA.md` §6 manda explicitamente que *"TODO arquivo `.jsx` novo entra em `ARQUIVOS_NOVOS`"* de `aprovacao/` | `fechamento-arquivos-e-manifestos`, `contagens-187-11.txt:31` |
| 4 | `tests/js/publicador-fila-tokens-187.test.js:359` | **isca invertida**: nenhum `.jsx` usa `shadow-pub-painel` | a `188-REGUA.md` manda **USAR o token** (`shadow-pub-painel` **É** exatamente o `-24px 0 64px rgba(0,0,0,0.6)` do critério 1) | `tokens-da-fila`, `contagens-187-01.txt:61` |

### ⚠️ Por que eu NÃO consertei

1. **O conserto certo é do lado deles, não do meu.** Eu não posso deixar de criar os três `.jsx` nem
   de ligar o quinto slot — os dois são o plano. O que mede a coisa errada é o **TOTAL**.
2. **Bump de número é o anti-padrão explícito** (learnings §4b.2): as waves **188-08 a 188-13** vão
   acrescentar MAIS `.jsx` em `aprovacao/` e MAIS slots na página. Subir 21→24 e 4→5 obrigaria cada
   wave seguinte a subir de novo — *"a régua que sobe a cada plano treina o executor seguinte a
   ajustar números em vez de consertar código"*.
3. **É cirurgia atravessada em 4 arquivos de teste + 4 manifestos (6 linhas de id)** de uma fase **em
   produção**, com a **conferência visual da 187 pelo usuário AINDA ABERTA** — e com **outra sessão
   commitando nesta mesma árvore agora**. Foi exatamente assim que a 187-05 reprovou dois ids alheios
   e exigiu consertar **quatro** manifestos.

### ➡️ O conserto estruturalmente certo (recomendação, não execução)

É o **"REAPONTE com veto"**, que **já tem precedente neste repositório**, escrito dentro do próprio
`publicador-vocabulario-novo.test.js`: a wave **187-03** fez isso em `DA_FASE_180` e a **187-04** em
`DO_PORTAO_DA_FASE_186`, nos dois casos porque *"o diretório deixou de ser 100% daquela fase"*.

- **#1 e #2:** trocar `assert.equal(…length, 4)` por **lista CONSTANTE dos quatro nomes de slot**
  (`proximo`, `filtros`, `tabela`, `rodape`), cada um provado passando por `isValidElement` — e
  **largar o total**. Fica **estritamente mais forte** no que protege (nenhum dos quatro perdeu a
  guarda) e para de medir o tamanho da página.
- **#3:** manter as duas listas de nomes (que já provam que nenhum dos 20+3 saiu) e **largar
  `assert.equal(noDisco.length, 21)` + o `deepEqual` contra o disco**. ⚠️ **O outro lado já está
  fechado e VERDE:** o `deepEqual(noDisco, declarados)` de `DIRETORIOS_FECHADOS` no **GATE-03** varre
  o mesmo diretório e **já declara os meus três** — então nada fica sem dono.
- **#4:** o próprio gate manda: *"troque o precedente desta isca invertida"*. Basta eleger outro
  token declarado-e-dormindo como precedente (há vários na camada).

⚠️ **Quem fizer isso tem de RE-MEDIR os 6 ids** (`contagens-187-01/04/05/11.txt`) — e os três ids de
`contagens-187-04.txt` apontam para o **MESMO arquivo**, então um conserto move os três de uma vez.

---

## A PARTIDA, e as BASELINES

**PARTIDA: `14fa6b32`** (capturada na PRIMEIRA ação, `git rev-parse --short HEAD`, 2026-10-19T18:02:09Z).

| medição | na PARTIDA (`14fa6b32`) | no FIM | o que mudou, e de quem é |
|---|---|---|---|
| **JS** | 5498 / 5497 pass / **1 fail** / 0 skipped | **5567 / 5559 pass / 8 fail** / 0 skipped | **+69 testes** (os 45 meus + 24 sub-testes novos do GATE-03 e vizinhos). ⚠️ **As 8 falhas: 1 pré-existente + os 4 gates alheios da seção acima (com 3 sub-testes aninhados)**. **ZERO falha nos meus** |
| **PHP** | 2534 / 23014 / **1 fail** | **2534 / 23020 / 0 fail** | **+12 contra a baseline da wave 3 (2522)** — são os **12 casos da coluna de preço** que a **wave 188-04** commitou em `f454a2e3`/`14fa6b32`. **NÃO é desta wave** |
| **BUILD** | — | **exit 0**, 2m31s | rodado **DEPOIS** da campanha de mutação, como o briefing manda |
| **MANIFEST** | — | **495 entradas / 202 páginas** | pelo **JSON**, nunca por grep; isca ausente, controle presente |
| **PROTÓTIPO** | 1432 linhas, `sha256 68624883ce35547e` | idem | `design_handoff_publicador/` — **o diretório citado**, porque há DUAS cópias do pacote com conteúdo DIFERENTE |

### ⚠️⚠️ A falha PHP da partida NÃO é desta fase, nem é um dos dois osciladores conhecidos — e isso foi MEDIDO

```
FAILED  Tests\Feature\Publicador\PortalComposicaoNoRascunhoTest
        > componente sem variacao fica fora com aviso e os demais preenchem
UnexpectedValueException: FilesystemIterator::__construct(
  …/storage/framework/testing/disks/local/estrutura/1/produtos/1/variacoes/2)
```

➡️ **Rodada SOZINHA ela PASSA** (`1 passed (6 assertions)`, medido). É **CORRIDA DE SISTEMA DE
ARQUIVOS** com a suíte da **wave 188-04, que roda EM PARALELO nesta MESMA árvore** e usa o MESMO
`storage/framework/testing`. **Não é regressão, não conserto, e não criei `REF_PHP_FALHAS`.**
⚠️ Os **dois osciladores** conhecidos da Fase 179 (`CockpitDaContaTest.php:966` e `:1318`,
fronteira de dia) estavam **VERDES** na partida.

### ⚠⚠ E NO FIM A FALHA PHP DESAPARECEU — o que **CONFIRMA** o diagnóstico

**PHP no fim: `2534 / 23020 / 0`.** ➡️ **A régua é `0`, e foi alcançada.**

| comparação | partida | fim | leitura |
|---|---|---|---|
| **TESTES** | 2534 | **2534** | **IDÊNTICO** — e tem de ser: **este plano não escreve PHP nenhum** |
| **FALHAS** | 1 | **0** | a falha era a **corrida de sistema de arquivos** com a suíte da wave 188-04; **ela fechou, e a falha sumiu sem ninguém tocar em código** — é a prova independente do diagnóstico |
| asserções | 23014 | 23020 | **+6**, e ⚠️ **asserção NÃO é invariante** (a wave 1 mediu 21.915 × 21.920 com testes **idênticos** e zero commits em `tests/`). **Comparei TESTES e FALHAS**, nunca persegui a contagem de asserções |

⚠️ Os **dois osciladores** da Fase 179 (`CockpitDaContaTest.php:966`/`:1318`) seguem **VERDES** nas
duas medições.

⚠️ **A única falha JS pré-existente** é `tests/js/estrutura-grade-glide.test.js` (Fase 87),
identificada por **SEMÂNTICA** e não por número de linha: `not ok 191 - Características secundárias
nasce recolhido (é o grupo que mais infla)`. **Não corrigida.**

---

## ⚠️⚠️ O PORTAL — a armadilha central, e os TRÊS motivos no docblock

| motivo | o que está declarado em `PainelDeRevisao.jsx` | a prova |
|---|---|---|
| **1. por que portal** | o `backdrop-filter` do `PublicadorHeader` (`header/PublicadorHeader.jsx:171`, `backdrop-blur-[14px] backdrop-saturate-[1.4]`) **cria containing block para `position:fixed`** | `numstat` **VAZIO** em `PublicadorHeader.jsx` e em `AppLayout.jsx` |
| **2. por que a guarda, e por que NAMED EXPORTS** | *"Portals are not currently supported by the server renderer"* — um teste do COMPOSTO veria **HTML VAZIO** e passaria **POR OMISSÃO** | o gate exige `render(composto) === ''` **E** `render(RaizDoPainel).length > 200` — a contraprova sem a qual o `equal('')` seria vazio por construção |
| **3. por que `font-pub` na RAIZ** | conteúdo portado **NÃO herda a fonte** (REND-05, medido na Fase 178; `ModalNovaPublicacao.jsx:95`-`:97` declara textualmente) | provado na CONSTANTE **e** no `<aside>` renderizado, com **isca do precedente** |

**A solução é a da Fase 178, repetida pela 187-09:** `typeof document === 'undefined'` **ANTES** do
`createPortal` (o gate prova a ORDEM pelos índices na fonte), e **TODAS** as peças internas como
**named exports** — `RaizDoPainel`, `BackdropDoPainel`, `CorpoDoPainel`, `ColunasDoPainel`,
`ColunaDoPainel`, `filhosSeguros`, `escreverNoPainel`, `resolverAtalhoDoPainel`, mais 17 de
`TopoDoPainel.jsx` e 29 de `IdentificacaoDaOferta.jsx`. ⚠️ **Lista CONSTANTE, nunca `>= N`**: piso
não prova PRESENÇA.

**O docblock é lido COM COMENTÁRIO, de propósito** — é o único bloco deste gate que o faz, e a
inversão está **declarada** no arquivo de teste: o docblock **é** o artefato, porque a próxima pessoa
que vir o painel preso ao header tem de achar a causa **por `grep`**, não por bisseção.

### ⚠️ O PRIMEIRO achado da própria sonda: a frase quebrada em duas linhas

O gate reprovou `containing block` **ausente** — e estava **certo**: eu havia escrito a frase
quebrada (`…cria containing` / `block para…`). **Quem fizer `grep "containing block"` não a acha.**
➡️ **Consertei a PROSA, não o gate**, e deixei a razão escrita na própria linha.

### ⚠️ Os popovers da 187 NÃO foram tocados

Eles são `absolute` dentro de pai `relative` e **não precisam de portal** (medido). **São casos
diferentes.** Idem as três peças `fixed` da página (`BarraDeSelecao`, `ModalDeLote`, `ToastDaFila`):
são **IRMÃS** do header, não descendentes, e `backdrop-filter` cria containing block **só para
DESCENDENTES** — está escrito na página, ao lado do painel.

---

## ⚠️⚠️ O Z-INDEX: `z-[39]` / `z-[40]`, provado pela ORDEM e **nunca** por lista de números

```
projeto:   header(30) < backdrop(39) < painel(40) < modal(42) < toast(45)
protótipo: header(50) < backdrop(69) < painel(70) < modal(100) < toast(120)
```

Os cinco números são **LIDOS DO CÓDIGO** dos respectivos módulos (não de literais no teste), com
âncora que **exige exatamente 1 ocorrência** — extração que devolve `null` em silêncio é como um gate
vira decoração. E a barra de lote (`z-[35]`) é provada **ENTRE** o header e o backdrop.

### ⚠️ A ISCA que distingue INVERSÃO de simples TROCA DE NÚMERO — e ela foi MEDIDA pela mutação

| caso | `ordemOk()` | mutação | o que MORREU |
|---|---|---|---|
| `backdrop: 41` (**INVERTE** com o painel) | **false** | `iv-zindex-invertido` | o gate de **ORDEM** |
| `backdrop: 29` (abaixo do header) | **false** | — | o gate de **ORDEM** |
| `painel: 43` (acima do modal) | **false** | — | o gate de **ORDEM** |
| **`painel: 41` (NÃO inverte nada)** | **true** | `ivb-zindex-trocado-sem-inverter` | ⚠️ **SÓ o gate de FID** (teste 18, a geometria), e **o gate de ORDEM PASSOU** |
| escala inteira deslocada (20/25/26/27/28) | **true** | — | nada |

⚠️⚠️ **Essa última linha foi MEDIDA, não presumida:** rodei a mutação `z-[40] → z-[41]` isolada e
**só o teste 18 (`CASCA: a geometria literal`) reprovou** — os testes 8 (ordem) e 9 (a isca da
ordem) **passaram**. ➡️ São **dois invariantes diferentes**, e é exatamente a distinção que o plano
pediu por escrito:
- o gate de **ORDEM** ancora no **comportamento** e **aceita** 41 (a 187-09 mediu que **`45` é o
  número do POPOVER** e o toast legitimamente ficou em `z-[45]`: lista larga de números **reprova
  código certo**);
- o gate de **FID** cobra o **valor prescrito** (`z-[39]`/`z-[40]`), que é remapeamento **medido**.

### ⚠️ `publicador-header-zindex-ordem.test.js` INTACTO e VERDE

`git diff --numstat HEAD -- tests/js/publicador-header-zindex-ordem.test.js` → **VAZIO**, e a sua
asserção central (`assert.equal(z.menu, z.painel)`) segue legível. ⚠️ Ele lê **outro** painel (o
`PainelDoProdutoLateral`, Fases 178/180), e o meu `z-[40]` **não disputa com ele**.
⚠️ **A isca do `numstat` foi CONSERTADA na execução:** a primeira redação usava o `numstat` de um
arquivo MEU, que sai **vazio nas duas pontas** (antes do commit ele é *untracked* e `git diff` não vê
untracked; depois do commit ele é idêntico ao HEAD) — **gate vazio por construção**, a mesma família
do `git diff --stat` sem range. A isca passou a ser `HEAD~1..HEAD`, que **sempre** tem conteúdo.

---

## O TOPO DE 54px: os QUATRO elementos, e a RESSALVA do critério 2

`REVISÃO SIMPLES` · `x de N na fila` · `↑` `↓` · `×` — provados por **árvore com índice ABSOLUTO**
(7 elementos, nunca `indexOf` de classes) e **igualdade de CONJUNTO** de classes.

### ⚠️⚠️ "Abrir revisão detalhada ↗" **NÃO É DESENHADO** — e o critério 2 sai COM RESSALVA

**PERGUNTA-188-B3, respondida pelo usuário em 18/10/2026:** ele nasce na **Fase 190/191**, junto com
a tela. Razão dele: *"é a mesma régua do aviso de tela vazia — sem botão, porque não há o que fazer
com ele"*. ⚠️ **"Desenhar desabilitado com explicação" foi OFERECIDO e RECUSADO.**

- **ZERO `onClick` vazio, ZERO `disabled` decorativo** — gate de ausência com **5 formas**;
- ⚠️ `/disabled=/` e **nunca** `/disabled/`, que casaria `disabled:opacity-40`;
- ⚠️ o `↗` é procurado **como glifo de tela** (`/['">]\s*↗/`), não só a frase inteira — a forma
  estreita deixaria passar um botão só com a flecha;
- ⚠️ **cada uma das 5 regex é testada contra um CEBO que ela DEVE casar** (e **duas estavam cegas**:
  `onClick={nada}` não existia no cebo, e foi trocada por `onClick={null}`);
- a rota **não existe**: `routes/mlb_anuncios.php:491` diz que a revisão detalhada é da 190/191
  (reconferido por `sed`).

➡️ ⚠️ **O CRITÉRIO 2 FICA COM RESSALVA DECLARADA:** o topo entrega **QUATRO** dos cinco elementos de
`:551`-`:559`. **O quinto é da Fase 190/191.** Não sai como OK silencioso.

### ⚠️ DIVERGÊNCIA DECLARADA: o `margin-left:auto` muda de DONO

No protótipo o `margin-left:auto` mora em **`:558`** — é **ele** que empurra o resto para a direita,
e o × (`:559`) vem encostado na borda. **Sem `:558`, a propriedade não tem mais dono**, e o × ficaria
colado no ↓ com os 14px de `padding-right` do topo vazios.
➡️ O `ml-auto` passou para o **×**. ⚠️ É mudança de **DONO**, não de **VALOR**: o × continua a 14px
da borda direita, como em `:559`. É consequência direta de uma decisão já tomada, não escolha nova.

### ⚠️ DIVERGÊNCIA DECLARADA: seta sem destino **NÃO é desenhada**

O protótipo **CLAMPA** (`:1018`, `Math.max(0, …)`): lá os dois botões existem sempre e, na ponta,
**não fazem nada**. ➡️ Aqui a seta só nasce quando o **destino existe**
(`anterior_id`/`proxima_id`), e o **wrapper de `:554`** também não nasce quando nenhuma das duas
existe.
⚠️ **Não é escolha nova:** é a aplicação da MESMA régua que o usuário exerceu em **PERGUNTA-188-B**
(destino `null` → **SEM BOTÃO**, e o texto continua) e em **PERGUNTA-188-B3** (*"sem botão, porque
não há o que fazer com ele"*; *"desabilitado com explicação"* **RECUSADO**). As duas alternativas —
`onClick` vazio e `disabled` apagado — são **exatamente** o que aquelas respostas proíbem.
⚠️ **Provado com as DUAS pontas, que se DISTINGUEM**: na primeira falta o ↑ e **sobra** o ↓; na
última, o contrário; em `preparando` sobra **só o ×** (1 botão).

### ⚠️ `title` NUNCA vazio

Os três botões são **glifo puro** (↑ ↓ ×). `title` vazio produz **elemento sem nome acessível**, que
**passa em render e DESAPARECE na tela** (foi o conserto do anel na 187-08). Os três `title` são
**CONSTANTES do módulo**, nunca props, e o gate exige texto não-vazio **nos três** — mais
`SetaDoPainel({titulo: ''})` devolvendo `null`.

### ⚠️ "0 de N na fila" não existe

Com `pos = null` (a oferta em `preparando`) o bloco **não é desenhado**. O protótipo PERDE aqui
(`:1318` deixa o `indexOf` devolver **−1** e escreve *"0 de 3 na fila"*) — e essa divergência foi
**medida e declarada pelo plano 188-02**, não por este. Aqui só se **LÊ** a frase pronta do servidor.

---

## ⚠️⚠️ AS DUAS COLUNAS TÊM O MESMO PESO — e NÃO foram reequilibradas

`grid-cols-[minmax(0,1fr)_minmax(0,1fr)]` (`:586`), provado por **IGUALDADE de string** com **isca do
quase-igual**:

| forma | passa? | por quê |
|---|---|---|
| `minmax(0,1fr)_minmax(0,1fr)` | ✅ | a de `:586` — sem esta ponta o bloco seria vazio por construção |
| `minmax(0,1fr)_minmax(0,2fr)` | ❌ | a coluna de preço ficaria com o dobro do peso |
| `minmax(0,2fr)_minmax(0,1fr)` | ❌ | idem, invertido |
| `grid-cols-2` | ❌ | não tem o `minmax(0,…)` que permite o conteúdo encolher |
| três colunas | ❌ | — |

É **decisão de desenho declarada** (README E2: *"para dar o mesmo destaque a fotos e preço"*;
**REVS-06: "Não reequilibrar"**). *"Deixei a de fotos mais estreita porque caberia melhor"* é
**FID-02 literal**.

### Os dois slots: NOMEADOS e VAZIOS

`colunaDeFotos` (plano **188-08**) e `colunaDePreco` (plano **188-09**) chegam como **NÓ**. ⚠️ **Slot
sem nó NÃO desenha casca** — nem borda, nem "nenhum item" (D4): a grade inteira some, e provado que
**com UM nó** ela aparece (sem essa ponta o `equal('')` seria vazio por construção). A
`ColunaDoPainel` (`:587`/`:639`) sai como named export **para os planos 08/09 montarem dentro**.

⚠️ **`painel.preco` pode chegar `null`** enquanto o plano 188-04 não fechava — está **declarado no
docblock** como esperado, e **não foi "consertado"**.

---

## OS ATALHOS: função pura, e o runtime **DECLARADO NÃO PROVADO**

⚠️⚠️ **Não há navegador nesta máquina** e `renderToStaticMarkup` não dispara evento nenhum. O que
está provado é **`resolverAtalhoDoPainel()`**, que é **função pura**; o `addEventListener` **NÃO está
provado**, e esta wave **não afirma que "a tecla funciona"**. A declaração está no docblock **e tem
gate** (quatro âncoras: *"NÃO ESTÁ PROVADO"*, *"não há navegador"*, *"função pura"*, *"Fase 189"*).

| tecla | comportamento provado | ponteiro (reconferido por `sed`) |
|---|---|---|
| `Esc` | fecha **o modal primeiro**; sem modal, o painel | `:1014` (cadeia `modal → lightbox → menu → painel`) |
| `↑`/`↓` | **LÊ** `anterior_id`/`proxima_id`; `null` na ponta → não navega; com modal, nada | `:1017` (exige `!S.modal`) |
| `←`/`→` | troca a foto, **clampada nas DUAS pontas**; galeria vazia → nada | `:1018` (piso) + **`:1293`** (teto, na LEITURA) |
| qualquer outra | devolve `null` → **nada de `preventDefault`** | — |

### ⚠️⚠️ DIVERGÊNCIA DO PLANO, e o PROTÓTIPO GANHA: o `Esc` dispara **TAMBÉM digitando**

O `<behavior>` do plano diz *"os **QUATRO** atalhos não disparam quando o foco está em INPUT,
TEXTAREA ou SELECT"*. **Medido por `sed`:** `:1015` (`if (typing) return;`) vem **DEPOIS** do bloco
do `Escape` (`:1014`) — então o protótipo deixa o `Escape` passar **mesmo digitando**, e só `↑ ↓ ← →`
são barrados.
➡️ **Reproduzido como no protótipo. São TRÊS atalhos barrados, não quatro.** O contrário
**TRANCARIA** a pessoa dentro de um campo de texto de modal, onde o `Esc` é justamente a saída de que
ela precisa. ⚠️ Não é conflito protótipo × regra do projeto (seria o quarto, e eu devolveria): é o
plano que descreveu o protótipo pela metade.

### ⚠️ O degrau da Fase 189 é PREVISTO, nunca implementado

A cadeia do protótipo é `modal → lightbox → menu → painel`. **O lightbox é da Fase 189 e o menu é da
187**, então aqui é `modal → painel`, com o degrau **declarado** no docblock. Gate: a lista `ACOES` é
**FECHADA** nas quatro chaves e **não contém** `fechar-lightbox`.

### ⚠️⚠️ DESVIO DECLARADO DA ASSINATURA DO PLANO — e é o conserto que a própria prosa manda

A assinatura prescrita (`:…`) era `{tecla, temModal, alvoEhCampo, **indiceAtual, total**, imagemAtual,
totalDeImagens}`. ⚠️ Com `indiceAtual`/`total` a tela teria de **recalcular a fila no cliente** — e a
própria prosa do plano corrige isso: *"devolve o ID de destino lido de `anterior_id`/`proxima_id` —
nunca recalcula a fila, nunca filtra `preparando` no cliente"*.
➡️ A assinatura real recebe **`anteriorId`/`proximaId`** e devolve `{acao, oferta, imagem}`.
⚠️ **`preparando` sai da NAVEGAÇÃO mas CONTINUA nos `ids` do LOTE** (divergência **D9** da 187, já no
ar): **são listas diferentes, e não foram unificadas.**

### ⚠️⚠️ A PREMISSA QUE O RUNTIME NÃO PODE PROVAR FOI **TRAVADA**, não descartada

A guarda de `tagName` tem **duas metades**: a **DECISÃO** (`if (e.alvoEhCampo === true) return null;`),
que é função pura e **está provada**; e a **COMPOSIÇÃO** (ler `evento.target.tagName` e casar com
`TAGS_DE_CAMPO`), que vive no `useEffect` e **só roda no navegador**.
➡️ A mutação da composição **SOBREVIVERIA** — sem navegador, nenhum teste de comportamento a alcança.
⚠️ **Sobrevivente inobservável não se descarta: trava-se.** A composição ganhou **igualdade de
FONTE**, e está **DECLARADO no próprio teste** que isto é **proxy de fonte** — que **NÃO prova
comportamento**; quem prova é a conferência humana na conta 459. Com a trava, a mutação
`viib-composicao-tagname` **MORREU**.

---

## ⚠️⚠️ COL-04 / W11: `escreverNoPainel()` — o helper único de escrita

**O defeito que ele conserta:** o envio de `versao_esperada` estava atribuído ao plano **188-12**, mas
as escritas vivem em componentes dos planos **08 a 11**, **fora do `files` do 12** — e **nenhum plano
tinha o helper**. Sem dono, o critério **17** ficava sem mecanismo.

| provado | como |
|---|---|
| `versao_esperada` entra em **TODA** escrita | o corpo gravado é `{oferta: 12, versao_esperada: 3}` |
| o corpo **NÃO VENCE** a versão que a tela viu | corpo com `versao_esperada: 99` sai como `3` (a versão entra **DEPOIS** do espalhamento) |
| **sem versão NADA é enviado** | 7 formas inválidas → `false` e **zero** chamadas. Sem isso, `exigirVersao()` recebendo `null` **LIBERA** a escrita (o ramo de uso interno) — que é literalmente *"sobrescrever em silêncio"* |
| o 422 de versão divergente **NUNCA é engolido** | `onError` registrado; a mensagem **do SERVIDOR** (que diz as DUAS versões) chega ao `onConflito`; erro comum também sobe |
| a regra é **LIDA** do servidor | `AprovacaoDaOfertaService.php:100` — o teste casa o literal contra o PHP, para não haver dois literais da mesma regra |
| sacola adversa não derruba **o clique** | 9 formas, incluindo `{a: {toString: null}}` |

⚠️ **Conserto MEDIDO do próprio gate:** o `montar()` usa `bundle: true`, então o esbuild **INLINA o
stub do Inertia dentro do bundle** — o `CHAMADAS` de dentro e o de um `import()` do stub são **DOIS
objetos DIFERENTES**. Com `export const` o gate lia um array **sempre vazio** e reprovava por um
motivo **que não é o código**. O gravador passou a viver no `globalThis`.

---

## FIDELIDADE (FID-01) — medida **por SCRIPT**, no diretório citado

⚠️ **COMO MEDI: `SCRIPT`** (`sed -n '<L>p'` por linha + `grep -o` das três propriedades), em
`design_handoff_publicador/Aprovacao.dc.html` — **1432 linhas, `sha256 68624883ce35547e`**. ⚠️ **O
diretório é citado de propósito: há DUAS cópias do pacote de handoff na árvore, com conteúdo
DIFERENTE.**

| # | elemento | linha | `cursor` | `white-space` | `text-overflow` | reproduzido |
|---|---|---|---|---|---|---|
| B1 | backdrop | `:549` | — | — | — | ✅ |
| B2 | `<aside>` 1140px | `:550` | — | — | — | ✅ |
| B3 | topo 54px | `:551` | — | — | — | ✅ |
| B4 | "REVISÃO SIMPLES" | `:552` | — | — | — | ✅ |
| B5 | "x de N na fila" | `:553` | — | — | — | ✅ |
| B6 | wrapper ↑↓ | `:554` | — | — | — | ✅ |
| B7 | botão ↑ | `:555` | **`pointer`** | **`nowrap`** | — | ✅ |
| B8 | botão ↓ | `:556` | **`pointer`** | **`nowrap`** | — | ✅ |
| B9 | "Abrir revisão detalhada ↗" | `:558` | **`pointer`** | **`nowrap`** | — | ⛔ **NÃO desenhado** (B3) |
| B10 | botão × | `:559` | **`pointer`** | **`nowrap`** | — | ✅ (+ `ml-auto`, ver divergência) |
| B11 | corpo rolável | `:562` | — | — | — | ✅ |
| B11w | wrapper produto × resp. | `:563` | — | — | — | ✅ |
| B12w | coluna esquerda (`min-width:0`) | `:564` | — | — | — | ✅ |
| B12 | produto · SKU | `:565` | — | — | — | ✅ |
| B13 | título | `:566` | — | — | — | ✅ |
| B14w | fila dos chips (`flex-wrap`) | `:567` | — | — | — | ✅ |
| B14 | chip modalidade + losango | `:568` | — | **`nowrap`** | — | ✅ |
| B15 | chip formato | `:569` | — | **`nowrap`** | — | ✅ |
| B15 | chip origem | `:570` | — | **`nowrap`** | — | ✅ |
| B15 | chip versão | `:571` | — | **`nowrap`** | — | ✅ |
| B16 | chip de andamento + dot | `:572` | — | **`nowrap`** | — | ✅ |
| B17 | responsável + espera | `:575` | — | **`nowrap`** | — | ✅ |
| B18 | caixa de falha | `:579` | — | — | — | ✅ |
| B19 | glifo `!` | `:580` | — | — | — | ✅ |
| B20 | título + mensagem | `:581` | — | — | — | ✅ |
| B21 | "Tentar publicar novamente" | `:582` | **`pointer`** | **`nowrap`** | — | ⛔ **NÃO desenhado** (sem rota) |
| B22 | **as duas colunas** | `:586` | — | — | — | ✅ |
| B23 | `<section>` de coluna | `:587`/`:639` | — | — | — | ✅ (named export para 08/09) |

⚠️ **`text-overflow` é ZERO em todas as 28 linhas do meu escopo** — medido, não presumido. A coluna
existe porque a omissão dela já mordeu antes.

### ⚠️⚠️ QUATRO OMISSÕES NOVAS da tabela FID-01 §B, medidas nesta wave

A re-medição da wave 1 achou **33 divergências (20 por REFERÊNCIA, 11 por OMISSÃO, 2 por VALOR, ZERO
de medida)**. Estas **quatro** são **OMISSÕES** que ela não pegou, e **todas são load-bearing**:

| # | onde | o que o documento OMITE | o que acontece sem isso |
|---|---|---|---|
| 1 | **B19**, `:580` | **`display:grid;place-items:center`** | o `!` **cola no canto superior-esquerdo** do círculo de 22px em vez de centrar |
| 2 | **B20**, `:581` | o **WRAPPER** `display:flex;flex-direction:column;gap:2px;**flex:1**` (a célula lista só os dois spans) | é o `flex:1` que **empurra o botão de `:582` para a direita** |
| 3 | **B18**, `:579` | `display:flex;align-items:center` | o glifo, o texto e o botão não se alinham na vertical |
| 4 | **B14/B15/B16**, `:568`-`:572` | `display:flex;align-items:center` nas TRÊS medidas-chave | o chip **não centra o conteúdo** na altura de 24px |

**As quatro estão REPRODUZIDAS**, com asserção própria e com a omissão nomeada no código. Confirmado
por `grep -o` em `:580`/`:581`: `display:grid place-items:center display:flex flex:1`.

### ⚠️ Os PONTEIROS que reconfirmei por `sed` (todos de §J/§K, mais dois novos)

| ponteiro | o que a prosa dizia | **MEDIDO** | o que está na linha citada |
|---|---|---|---|
| `_nav` / o `pos` | `:1317` | **`:1318`** ✅ confirmado | `:1317` é o `indexOf` |
| o clamp de leitura da imagem | `:1293` ✅ confirmado | `:1293` | `sel = imgs[Math.min(selIdx, imgs.length - 1)] \|\| null` |
| o prefixo da rota da foto | `:157` (`188-REGUA.md` §14) | **`:158`** ✅ confirmado (§K) | `:157` é o `throttle` da rota ANTERIOR |
| **a guarda de digitação** | *(novo)* | **`:1015`**, **DEPOIS** de `:1014` | `if (typing) return;` — **e é por isso que o `Esc` passa digitando** |
| **o `\|\| ''` do `tagName`** | *(novo)* | **`:1013`** | `['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName \|\| '')` |
| a revisão detalhada é da 190/191 | `routes/mlb_anuncios.php:491` ✅ confirmado | `:491` | *"(`…/aprovacao/{oferta}/{aba}`) é da Fase 190/191 e NÃO nasce aqui"* |

⚠️ **Não estendi o `188-FID01.md`** (era opcional): a **outra sessão acabou de commitar a §L nele**
(`9a3901b8`), e encostar num arquivo que o vizinho está editando é como a 187-05 virou quatro
manifestos consertados. As quatro omissões e os dois ponteiros novos ficam **aqui**, e o gate
`fid01-remedida` segue **VERDE**.

### FID-02/03/04 — nenhuma justificativa vetada usada

`text-[11px]` / `[11.5px]` / `[12px]` / `[12.5px]` / `[13px]` / `[14px]` / `[16px]` / `[20px]`, nunca
`text-sm`/`text-xs`/`text-base`/`text-lg` (gate de ausência nos três arquivos). **Zero** `ecf-bg`,
`ecf-card`, `ecf-yellow`, `font-display`, `border-white/[0.08]`. **Zero hex literal** no código
(TOK-15, via GATE-03). **`21` não foi usado** por este plano, e **nada foi acrescentado** à lista
`TAMANHOS_PERMITIDOS`.

⚠️ **`font-[800]` e NÃO `font-extrabold`** no glifo de `:580`: o **GATE-03 REPROVOU** a escala nomeada
(só 400/500/600/700) — e **estava certo**. O valor arbitrário entrega o **MESMO peso computado**, é o
precedente de `AnelDeProgresso.jsx:67` e `CabecalhoDaFila.jsx` (**as duas em produção**), e há **gate
de CSS CONSTRUÍDO** provando que compila para `font-weight:800`.

⚠️ **Nenhum token novo.** `shadow-pub-painel` **JÁ É** exatamente `-24px 0 64px rgba(0,0,0,0.6)` —
escrever o arbitrário seria a **segunda cópia** do mesmo valor. `bg-pub-sunken` é `#0D0D0D`, **não**
`pub-page` (`#0A0A0A`) nem `pub-surface` (`#111111`).

---

## O CSS CONSTRUÍDO — com as OITO normalizações, e as iscas MEDIDAS

| normalização | prova neste gate |
|---|---|
| 1/2 — token sólido **não** compila para hex | `bg-pub-sunken` → `rgb(13 13 13 / var(--tw-bg-opacity…))` |
| 3 — opacidade muda de FORMA conforme a PROPRIEDADE (hex de 8 dígitos, e o alfa PROVA) | `bg-black/[0.55]` → `#0000008c` · `border-pub-danger/[0.4]` → `#ef444466` · `bg-pub-danger/[0.07]` → `#ef444412` |
| 4 — o cssnano come o zero da frente, **inclusive após o sinal de menos** | `tracking-[0.08em]` → `.08em` · `tracking-[-0.015em]` → `-.015em` |
| 5 — o seletor vem **escapado** | via `seletorDe()` |
| 6 — na variante, **o prefixo faz parte do nome** | `hover:bg-pub-hover-3` → `rgb(26 26 26…)` |
| 7/8 — o escape é **GERAL** (parênteses e vírgulas incluídos) | `w-[min(1140px,calc(100vw-100px))]` e `grid-cols-[minmax(0,1fr)_minmax(0,1fr)]` **presentes** — sem o escape geral sairiam **AUSENTES estando corretos** |

- **ISCAS MEDIDAS** contra o CSS de verdade: `bg-pub-isca-188-07`, `text-[97.5px]`, `w-[1993px]`,
  `h-[467px]` → todas ausentes (⚠️ `w-[13px]` parecia inventada e saiu **presente**; `h-[64px]` e
  `left-[30%]` **existem**);
- **CONTROLE POSITIVO**: `regraDe('text-[12px]')` não é nulo — sem ele o gate não distingue *"o
  arbitrário não compila"* de *"a sonda está cega"*;
- ⚠️ **`duration-[180ms]` continua NÃO compilando** e `duration-200` compila (`transition-duration:.2s`);
- **68 classes** da casca, do topo e da identificação conferidas numa asserção coletando.

---

## A TELA PRETA — render adverso **e no CLIQUE**

**12 formas adversas** (`{}`, `null`, `undefined`, `NaN`, `[]`, `{toString:null}`, `0`, `''`, `true`,
`-1`, `1e99`, `{a:{toString:null}}`) × **6 campos** da navegação × **6+5 campos** da identificação, a
prop **inteira** adversa, e **TAMBÉM nos handlers exportados** (porque `renderToStaticMarkup` **não
dispara `onClick`**, e a tela preta da 187-06 foi **no clique**).

- `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)` em todos;
- **nunca `String()`/`JSON.stringify()` na mensagem de asserção** (eles lançam / mentem);
- `textoDoTopo(NaN)` → `null` **provado** (⚠️ `typeof NaN` é `'number'`, e um `textoSeguro` ingênuo
  devolveria a string `'NaN'` para a tela);
- **filho que é OBJETO COMUM é filtrado elemento a elemento** (precedente medido de
  `CascaDeModal.jsx:197`-`:207`): `[{}, {}]` → `null`, e `['ok', {}, 7]` → `['ok', 7]`;
- **zero `String(`**, zero `hasOwnProperty`, zero `JSON.stringify` nos três arquivos **e na página**
  (há gate da 187-04 cobrando na página — **não foi afrouxado**);
- `andamentoDe()` **nunca** devolve rótulo vazio: **10 chaves adversas** numa asserção coletando, e a
  chave desconhecida aparece **COMO CHEGOU** (`chave_inventada_9z7`), nunca apagada.

⚠️ **Flag DENTRO do callback** no `.map()` dos 5 chips e em todos os `.filter()` do gate — o Rollup
**elimina** variável de escopo do componente usada dentro de `.map()`, e o `ReferenceError` só aparece
no bundle de produção. ⚠️ A correção medida na 187-09 vale: expressão que lê só **parâmetro** ou nome
de **MÓDULO** é aceita.

---

## MUTAÇÃO: `mutar-188-07.sh` — **20 mutações, 20 MORTAS, 0 sobreviventes**

⚠️ **O nome carrega o plano, e isso NÃO é zelo** (learnings §4c.3): o scratchpad é **compartilhado**
entre subagentes paralelos, e na 187 um `mutar.sh` foi **sobrescrito no meio**, obrigando a refazer a
campanha. **`sha256` do próprio script: `df056dc44010bfed`.**

| # | mutação | veredicto |
|---|---|---|
| i | o portal perde o destino (`document.body` → `null`) | ✅ MORREU |
| ii | a guarda de ambiente deixa de disparar | ✅ MORREU |
| iii | `font-pub` sai da raiz do portado | ✅ MORREU |
| iv | `z-[39]` → `z-[41]` (**INVERTE** com o painel) | ✅ MORREU (pelo gate de **ORDEM**) |
| iv-b | `z-[40]` → `z-[41]` (**não inverte**) | ✅ MORREU — ⚠️ **só pelo gate de FID**; o de ORDEM **passou** |
| v | as colunas reequilibradas (`2fr`) | ✅ MORREU |
| vi | `title` vazio no × | ✅ MORREU |
| vii | a guarda de campo de texto invertida | ✅ MORREU |
| vii-b | a **composição** de `tagName` → `false` (**inobservável**) | ✅ MORREU (pela **premissa TRAVADA**) |
| viii | o clamp do **teto** das setas | ✅ MORREU |
| viii-b | o clamp do **piso** | ✅ MORREU |
| ix | `versao_esperada` sai do corpo | ✅ MORREU |
| ix-b | o corpo **vence** a versão da tela | ✅ MORREU |
| x | o 422 passa a ser **engolido** | ✅ MORREU |
| xi | o losango por **truthiness genérica** | ✅ MORREU |
| xii | a omissão 1 volta (`place-items-center`) | ✅ MORREU |
| xiii | a omissão 2 volta (o `flex-1` do wrapper) | ✅ MORREU |
| xiv | as cores do andamento **recopiadas** | ✅ MORREU |
| xv | a seta desenhada **sem destino** | ✅ MORREU |
| xvi | a posição **inventada** (`"0 de 3 na fila"`) | ✅ MORREU |

**A receita, cumprida:** trabalho **COMMITADO antes**; `cp` com o plano no nome; ocorrência
**travada em 1 ANTES** (âncoras de **UMA LINHA** — `grep -c` conta LINHAS, e âncora multi-linha já deu
12 e 724); **linha conferida depois**; restauração **DA CÓPIA** em `trap EXIT`; e a guarda de
aplicação por **DIVERGÊNCIA DE `sha256`** (a guarda *"âncora antiga sumiu"* **aborta em réplica que
contém a âncora**). ⚠️ **`git checkout` NUNCA.**

**`sha256` contra `git show HEAD:` com `tr -d '\r'` nos DOIS lados**, antes e depois:

```
PainelDeRevisao.jsx        e33d7b0dffac9db4  ANTES = DEPOIS = HEAD
TopoDoPainel.jsx           a1cbf9e34a849ed1  ANTES = DEPOIS = HEAD
IdentificacaoDaOferta.jsx  3afbad98f498aa83  ANTES = DEPOIS = HEAD
```

⚠️ **`npm run build` rodado DEPOIS da campanha e ANTES de medir a suíte JS**, como o briefing manda:
o `cp`/`perl -i` atualiza o `mtime` e a camada de CSS **PULA**, dando `skipped` e contagem "errada"
que **não é regressão**. Resultado: **exit 0** e **0 skipped**.

---

## O HARNESS, OS IDS E AS CONTAGENS

**`sh tests/gates/verificar-testes.sh 188-07 <id>`** — o 1º argumento é **`188-07`**, nunca `07` nu
(que resolveria `contagens-186-07.txt`, a fase **errada**, em silêncio).

```
casca-do-painel       js  tests/js/publicador-painel-casca-188.test.js       ::  tests=45,41
vocabulario-tamanhos  js  tests/js/publicador-vocabulario-novo.test.js       ::  tests=>=569
```

- **DOIS REGIMES, os dois MEDIDOS:** **45** com o CSS construído mais novo que os `.jsx` (os 41
  testes de topo + os **4 sub-testes** da camada de CSS) e **41** com o CSS mais antigo (a camada cai
  em **`skip` NOMEADO** durante a mutação). ⚠️ Sem os dois, a campanha **abortaria na 1ª execução** ou
  marcaria *"SOBREVIVEU"* por um motivo que **não é o código**.
- ⚠️ **`vocabulario-tamanhos` em LINHA PRÓPRIA**, forma **`>=`**, com **N MEDIDO na hora: `>=569`**
  (`# tests 569 / # pass 569`). A contagem **cresce por construção** (um sub-teste por arquivo
  varrido) e **os meus três `.jsx` a aumentaram** — a wave 1 media `>=548`. ⚠️ **Convenção medida:**
  o id está declarado em **linha própria** nos **dez** manifestos da 187 e no `contagens-188-01.txt`;
  o proibido é **só** embuti-lo num id que roda OUTROS arquivos.
- **UM id por arquivo de gate** no modo `js` (o harness roda o **arquivo inteiro**; dois ids sobre o
  mesmo arquivo dariam a MESMA contagem duas vezes — gate decorativo por construção, medido na 187-08).

### `--autoteste`: **20 casos, 0 divergência** — colado

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

### A BIJEÇÃO, nos três sentidos — por SCRIPT

```
IDS=2 (casca-do-painel, vocabulario-tamanhos)
USADOS_EM_VERIFY=2 (casca-do-painel, vocabulario-tamanhos)
LINHAS_PHP_NO_MANIFESTO=0  (zero = nenhum --filter roda por este plano)
MEUS_TESTES_JS=41   MEUS_SEM_SUFIXO=0   MEUS_DUPLICADOS=0
VEREDICTO=OK
```

**Zero órfão** nos dois sentidos (todo id num `<verify>`, todo `<verify>` com id declarado), zero id
repetido, zero arquivo fantasma, nenhum id com mais de 3 métodos, nenhuma linha sem `tests=`.

### ⚠️⚠️ HOMÔNIMO — e o CONSERTO MEDIDO da própria sonda

**A primeira redação do meu script acusou 82 homônimos — TODOS FALSOS**, por dois defeitos dela:

1. ⚠️ **GATE MAIS LARGO QUE O INVARIANTE** (a família de **DOZE** ocorrências deste projeto): o
   `--filter` **NÃO ANCORADO** do PHPUnit é o que faz contagem **SOMAR**, e ele **só existe no modo
   `php`**. No modo `js` o harness roda o **ARQUIVO INTEIRO** — **não há filtro de nome**, então nome
   de teste JS parecido **não soma nada**;
2. a extração de nomes JS **quebrava com aspas DENTRO do nome** (`test('o "x" …')` virava o nome
   `o `), e era esse `o ` de uma letra que casava como "substring" de tudo.

➡️ **Reancorei no COMPORTAMENTO (o `--filter`).** Medido:

```
ARQUIVOS_PHP_DE_TESTE=1213   METODOS_PHP=7843   PHP_COM_188_07=0
ISCA_D1(test_territorio_e_ausencias_do_plano é substring de)=7 metodos REAIS
   _187_06  _187_07  _187_08  _187_09  _187_10  _188_02  _188_03
ISCA_CONTROLE=0
```

⚠️ **A isca da direção 1 acusa 7 métodos que EXISTEM** — é exatamente por isso que o sufixo não é
zelo. **Este plano não declara linha `php`**, então o risco é **estruturalmente ausente**, e o
manifesto tem asserção que **avisa** se uma linha `php` aparecer.

### Os ids das waves 1-3: **re-rodados, contagens INALTERADAS**

| wave | ids | resultado |
|---|---|---|
| 188-01 | `baselines-medidas`, `tokens-e-tamanhos`, `fid01-remedida` (+ `vocabulario-tamanhos`) | **3/3 PASSOU** |
| 188-02 | `identificacao-e-chips`, `pendencias-e-destinos`, `rodape-e-aprovavel`, `cadeia-e-incompleto`, `navegacao-e-payload`, `custo-do-painel`, `painel-na-rota`, `territorio-painel`, `bijecao-manifesto-02` | **9/9 PASSOU** |
| 188-03 | `galeria-e-marcadores`, `borda-e-estados`, `sem-alerta-e-contagem`, `verificacoes-e-ordinal` | **4/4 PASSOU** |

⚠️ **Nenhum id da wave 4 falhou** — e se tivesse falhado, não seria meu: ela rodou em paralelo.

---

## TERRITÓRIO — por **ESCOPO DO ASSUNTO**, e a prova de que o RANGE não serve

⚠️⚠️ **MEDIDO NESTA WAVE, e confirma a régua:** o range `14fa6b32..HEAD` tem **5 commits**, e **DOIS
não são deste plano** (`856cb843` e `9a3901b8`, da **wave 188-04**, que fechou em paralelo e escreveu
a própria SUMMARY e a §L do FID-01). O **escopo do assunto `(188-07)`** recorta **exatamente os
TRÊS** meus.
⚠️ **E o AUTOR não discrimina nada:** as três máquinas desta árvore commitam com o MESMO `user.name`
(`MB.ECF-100376`).

**Duas passadas independentes, com isca nas duas direções**, no gate:
1. todo commit de assunto `(188-07)` toca **só** arquivo deste plano → **zero infrator**;
2. nenhum arquivo **INTOCÁVEL** aparece em commit deste assunto → **zero infrator**;
3. iscas: o `git log` não veio vazio (`>10` commits), `(188-zz)` dá **0**, e `(188-0N)` dá **≥1**.

**`git diff --numstat HEAD` VAZIO** em: `publicador-header-zindex-ordem.test.js`,
`header/PublicadorHeader.jsx`, `AppLayout.jsx`, `CascaDeModal.jsx`, `ToastDaFila.jsx`,
`BarraDeSelecao.jsx` — com **isca** provando que a sonda não está cega (`HEAD~1..HEAD` não é vazio).
⚠️ **`test -e` em CADA um dos 13 caminhos citados** (anti-fantasma, com isca), porque são **NOVE**
fantasmas nesta milestone.

**Ausências provadas, recortadas à lista CONSTANTE dos TRÊS arquivos deste plano** (⚠️ varrer `app/`
já **BARROU UM DEPLOY** na 186 por `lucro_liquido`, que é campo legítimo do Portal): `localStorage`,
`sessionStorage`, *"Restaurar dados de exemplo"*, `ecf-aprovacao-v1`, `String(`, `hasOwnProperty`,
`JSON.stringify`, "lucro líquido"/`lucro_liquido`, `⤢`/`Ampliar` (**Fase 189**), "Gerar novamente"
(**Fase 192**), "Confirmar publicação"/"Desfazer"/"Cancelar aprovação" (**decisão 3: aprovar JÁ
PUBLICA**), `ArquivosDeImagem`/`ImagemAssetService`/`Acervo`, `MlPublicacaoService` (**a escrita no ML
é da Fase 193**) e `useCriativosDoPublicador`. ⚠️ **Cada uma das 17 regex é testada contra um CEBO que
ela DEVE casar** — "nenhum achado" com sonda cega não significa nada.

- ⚠️ **"nenhuma PEÇA nova sob `resources/` é `.js`"** — o invariante **CERTO** (a 187-11 mediu que
  *"nenhum arquivo novo é `.js`"* era **literalmente FALSO**: há 9 `.js`, **todos testes**);
- **T-188-SC:** `numstat` vazio em `package.json`/`package-lock.json` — **nada instalado**;
- **nenhuma migration** (esta fase não tem, e eu **não rodei `artisan migrate`**);
- **zero deploy, zero push, zero VPS, zero chamada à API real do ML**;
- `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md`, `STATE.md` e os `188-NN-PLAN.md`/`188-REGUA.md`/
  `188-COBERTURA.md` **intocados**; **não rodei `state.advance-plan`**;
- **não rodei `pint`**, não criei worktree, não usei `Write` em arquivo existente, e **nunca**
  `git add -A`, `git stash`, `git reset`, `git checkout`, `git commit --amend`, `git clean`.

---

## ⚠️⚠️ OUTRA SESSÃO ESTÁ RODANDO OS PLANOS 188-05/06 — e eu NÃO construí sobre o WIP dela

O briefing dizia que **só** a wave 188-04 rodava em paralelo. **Medido durante a execução:** apareceram
na árvore **434 linhas NÃO COMMITADAS** em `app/Http/Controllers/MlbPublicadorAprovacaoController.php`
e **71** em `routes/mlb_anuncios.php`, criando:

```
mlb.anuncios.meus.aprovacao.painel.salvar-revisao
mlb.anuncios.meus.aprovacao.painel.aprovar
mlb.anuncios.meus.aprovacao.painel.tentar-de-novo    ← esta é a da MINHA caixa de falha
```

(mais o teste `tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php`, untracked.)

**O que isso provocou, e o que eu fiz:**

1. o meu gate da **ausência do botão** lia a **ÁRVORE** e ficou **VERMELHO** — não pelo meu código,
   mas porque o vizinho salvou um arquivo;
2. ➡️ **reanco­rei o gate em `git show HEAD:`**, o único estado determinístico em relação ao **meu**
   código. Ler a árvore o faria **oscilar vermelho/verde conforme o vizinho salva**, e o conserto
   barato seria ligar a página a uma **rota ainda não commitada**, que pode ser reescrita. É a mesma
   família do *"gate mais largo que o invariante"*;
3. **NÃO liguei o botão.** ⚠️ **Nada do componente precisa mudar quando a rota aterrar:**
   `IdentificacaoDaOferta` **já aceita** `onTentarDeNovo`, e **há gate provando** que **com handler o
   botão NASCE e o clique CHAMA**. Falta **UMA linha** na página — está escrito no docblock do
   componente, **para ser achado por `grep`**;
4. **não toquei em nenhum dos dois arquivos** (`git diff HEAD --numstat` confirma: as 434 e as 71
   linhas não são minhas).

---

## OS RESÍDUOS DE REDAÇÃO DO PLANO, e como foram resolvidos

| resíduo | onde | resolução |
|---|---|---|
| `vocabulario-tamanhos` prescrito nas tarefas 1 e 2, mas o `<verify>` só da 2 | `188-07-PLAN.md:198`×`:310`×`:336` | declarado **na tarefa 2**, com N medido **depois** de o terceiro `.jsx` existir (a contagem cresce com ele) |
| `IdentificacaoDaOferta.jsx` nasce na tarefa 2 sem o teste de vocabulário nos `files`, embora `:336` mande registrar | `188-07-PLAN.md:250` | registrado **na mesma tarefa que o cria** — `aprovacao/` está em `DIRETORIOS_FECHADOS` e `.jsx` fora da lista deixa o `deepEqual` **vermelho na hora** |
| a assinatura do resolvedor de tecla com `indiceAtual`/`total` | `188-07-PLAN.md` | corrigida para `anteriorId`/`proximaId`, **como a própria prosa do plano manda** (ver acima) |
| *"os QUATRO atalhos não disparam em campo de texto"* | `<behavior>` da tarefa 2 | **o protótipo GANHA**: são **TRÊS** (`:1015` vem depois de `:1014`) |

⚠️ **Resíduo conhecido que NÃO consertei (território do planejador):** o `188-01-PLAN.md:105` chama a
PARTIDA de *"início de escopo"*, contra o `:333`. **O meu plano não repete a frase** (conferido por
`grep`), e eu segui o **escopo do assunto**. Declarado, não editado.

---

## O ROTEIRO DA CONTA 459 — o trecho desta wave

⚠️⚠️ **NÃO POSSO AFIRMAR TER VISTO TELA RENDERIZADA.** Todo render desta wave é **`esbuild` +
`react-dom/server`**: *"a tela mostra X"* significa **"o HTML gerado contém X"**. O banco local **não
tem empresa com `ml_token`** (a rota dá **404**), `pub_aprovacao_ofertas` está **VAZIA**, a fila nasce
vazia até a **Fase 194**, e **não há navegador**. ⚠️ **A conferência visual da Fase 187 pelo usuário
segue ABERTA** — nada daqui a substitui nem a presume feita.

1. abrir `/mlb/anuncios/meus/459/aprovacao?oferta=<id>` → o painel entra **pela direita**, cobrindo o
   header com um véu escuro, **sem** cobrir o menu lateral do sistema;
2. a largura é `min(1140px, 100vw − 100px)`: numa tela de 1200px sobra **uma faixa de 100px** à
   esquerda;
3. o topo traz **"REVISÃO SIMPLES"**, **"x de N na fila"**, as setas **↑ ↓** e o **×** — e **NÃO** traz
   "Abrir revisão detalhada ↗" (Fase 190/191);
4. **`Esc`** fecha; **clique no véu** fecha; **↑/↓** trocam de oferta **pulando as em preparo**;
5. as duas colunas nascem **com o mesmo peso** — e, até os planos 188-08/09, **vazias**: nenhuma borda
   de 14px sem conteúdo;
6. abrir uma oferta **em preparo** → **não** há *"0 de N na fila"*, e **não** há setas: só o ×;
7. abrir uma oferta **em falha** → a caixa vermelha com a mensagem **real** do Mercado Livre, **sem**
   o botão "Tentar publicar novamente" (ele chega com a rota dos planos 188-05/06).

---

## Deviations from Plan

### Rule 1 — Bugs consertados (achados pela própria sonda / pela mutação)

**1. [Rule 1 - Bug] A frase `containing block` quebrada em duas linhas no docblock**
- **Found during:** tarefa 1, no primeiro `node --test`
- **Issue:** eu havia escrito `…cria containing` / `block para…`. **Quem faz `grep "containing block"`
  não acha** — e o docblock existe exatamente para ser achado por `grep`.
- **Fix:** a frase foi para **uma linha só**, com a razão escrita ao lado.
- **Commit:** `ec4f805f`

**2. [Rule 1 - Bug] O gravador do stub do Inertia era um objeto DIFERENTE do do bundle**
- **Issue:** `montar()` usa `bundle: true` → o esbuild **inlina** o stub. O `CHAMADAS` exportado e o
  importado eram **dois arrays**; o gate de COL-04 lia sempre **vazio** e reprovava por um motivo que
  **não é o código**.
- **Fix:** o gravador passou a viver no `globalThis`.
- **Commit:** `8c4a7c7f`

**3. [Rule 1 - Bug] A isca do `numstat` era VAZIA POR CONSTRUÇÃO**
- **Issue:** ela usava o `numstat` de um arquivo **meu** — vazio **antes** do commit (untracked) e
  vazio **depois** (idêntico ao HEAD). Mesma família do `git diff --stat` sem range.
- **Fix:** a isca passou a ser `HEAD~1..HEAD`, que sempre tem conteúdo.
- **Commit:** `ec4f805f`

**4. [Rule 1 - Bug] Duas regex do gate de ausência estavam CEGAS**
- **Issue:** `onClick={nada}` não existia no cebo (`nada` é nome só do teste), e a contagem
  `FONTES_DO_PLANO.length === 2` reprovou quando a tarefa 2 criou o terceiro arquivo — **a régua que
  sobe a cada tarefa**.
- **Fix:** a regex virou `onClick={null}` (forma que pode existir em componente) e a contagem virou
  **lista CONSTANTE** dos três caminhos.
- **Commits:** `ec4f805f`, `8c4a7c7f`

**5. [Rule 1 - Bug] Um teste meu esperava o comportamento ERRADO do clamp**
- **Issue:** eu esperava `null` no `→` a partir de `imagemAtual: 99` numa galeria de 5. **O código
  estava certo:** índice 99 é estado inválido, e a tecla deve trazer a seleção para a **última foto
  que EXISTE** em vez de não fazer nada e deixar a pessoa olhando um bloco vazio.
- **Fix:** a **expectativa do teste** foi corrigida (nunca o código), e o caso do índice **negativo**
  foi acrescentado.
- **Commit:** `8c4a7c7f`

**6. [Rule 1 - Bug] O meu probe de HOMÔNIMO era mais largo que o invariante**
- **Issue:** acusou **82 homônimos falsos** (ver a seção do homônimo).
- **Fix:** reancorado no `--filter`, que é o que **soma** — e só existe no modo `php`.
- **Commit:** (script de scratchpad, não versionado; o resultado está colado acima)

### Rule 2 — Funcionalidade crítica acrescentada

**7. [Rule 2 - Correção] A premissa inobservável do teclado foi TRAVADA**
- **Issue:** a **composição** da guarda de `tagName` vive no `useEffect` e **só roda no navegador** —
  mutá-la **SOBREVIVERIA**.
- **Fix:** igualdade de **FONTE**, **declarada no teste** como *proxy de fonte, que NÃO prova
  comportamento*.
- **Commit:** `6a5ebd19`

**8. [Rule 2 - Ambiente] O gate da rota ausente passou a ler o estado COMMITADO**
- **Issue:** WIP **não commitado** de outra sessão fazia o gate oscilar.
- **Fix:** `git show HEAD:`, com a razão escrita no gate **e** no docblock do componente.
- **Commit:** `6a5ebd19`

### Divergências do protótipo e do plano — **declaradas, nenhuma decidida por mim**

| o que | protótipo | aqui | por quê |
|---|---|---|---|
| "Abrir revisão detalhada ↗" | `:558` | **não desenhado** | **PERGUNTA-188-B3**, decisão do usuário |
| `margin-left:auto` | em `:558` | no **×** | consequência direta da ausência acima (**DONO**, não valor) |
| seta na ponta da fila | **clampa** (`:1018`) | **não desenhada** | régua de **PERGUNTA-188-B** (destino `null` → SEM BOTÃO) |
| "Tentar publicar novamente" | `:582` | **não desenhado** | **MEDIDO:** a rota não existe no HEAD |
| `Esc` com foco em campo | **dispara** (`:1015` depois de `:1014`) | **dispara** | o **protótipo GANHA**; o plano descreveu pela metade |
| `"0 de N na fila"` | `:1318` | **não desenhado** | divergência do plano **188-02**, não desta wave |

⚠️ **NENHUM QUARTO CONFLITO protótipo × regra do projeto foi encontrado.** As três derrotas do
protótipo seguem as três conhecidas, todas decisão do usuário. Se eu tivesse achado um quarto, eu
pararia e devolveria — não decidiria.

### Auth gates

Nenhum.

---

## Known Stubs

| stub | arquivo | por quê, e quem resolve |
|---|---|---|
| `colunaDeFotos` chega `null` | `PainelDeRevisao.jsx` | é o **slot NOMEADO e VAZIO** do plano **188-08**. ⚠️ **Não desenha casca vazia** (D4), e há gate provando |
| `colunaDePreco` chega `null` | idem | plano **188-09** |
| `children` (pendências e rodapé) `null` | idem | plano **188-10** |
| `temModal`/`onFecharModal` default `false`/`null` | idem | os modais são dos planos **188-11/12**; a cadeia `Esc → modal` **já está provada** pela função pura |
| `onTentarDeNovo` não ligado na página | `Aprovacao.jsx` | a rota é dos planos **188-05/06** (**não commitada**). Falta **UMA linha**; o componente e o gate já estão prontos |
| `imagemAtual`/`totalDeImagens` default `0`/`0` | idem | a galeria é do plano **188-08**; o clamp das setas **já está provado** |

**Nenhum deles impede o objetivo deste plano** — a casca, o topo, o teclado e as duas colunas estão
entregues e provados.

---

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano. ⚠️ O único ponto de escrita
que este plano cria é `escreverNoPainel()`, e ele **só** compõe `router.post` com `versao_esperada`
**sempre** — nenhum endpoint, nenhuma rota, nenhum acesso a arquivo, nenhuma mudança de schema.


---

## Self-Check: PASSED

**Arquivos criados, conferidos no disco (`test -f` + contagem de linhas):**

```
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx        (669 linhas, min_lines 200)
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/TopoDoPainel.jsx           (256 linhas)
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/IdentificacaoDaOferta.jsx  (432 linhas)
FOUND: tests/js/publicador-painel-casca-188.test.js                                (1805 linhas, min_lines 300)
FOUND: tests/gates/contagens-188-07.txt                                            (249 linhas)
FOUND: resources/js/Pages/Mlb/Publicador/Aprovacao.jsx                             (892 linhas, modificado)
FOUND: tests/js/publicador-vocabulario-novo.test.js                                (1616 linhas, modificado)
```

**Commits, conferidos em `git log --oneline --all`:**

```
FOUND: ec4f805f  feat(188-07): a casca do painel de 1140px POR PORTAL …
FOUND: 8c4a7c7f  feat(188-07): o teclado como FUNCAO PURA, a identificacao … DUAS COLUNAS DE MESMO PESO
FOUND: 6a5ebd19  test(188-07): o gate da rota ausente passa a ler o ESTADO COMMITADO …
```

**`key_links` conferidos por `grep`:**
- `Aprovacao.jsx:307` declara `painelNo = null` e `:725` monta `<PainelDeRevisao`;
- `Aprovacao.jsx:696` tem `only: ['painel']` (o recarregamento parcial de COL-04);
- `PainelDeRevisao.jsx` tem `createPortal(` e `document.body`, com a guarda **ANTES**.

**Cirurgia no arquivo alheio: ADITIVA.** `git diff 14fa6b32..HEAD --numstat -- tests/js/publicador-vocabulario-novo.test.js`
→ **26 inserções, 0 remoções**, e `TAMANHOS_PERMITIDOS` **intacta** (o `21` que a wave 1 liberou não
foi usado por este plano, e **nada** foi acrescentado à lista).

**Os meus dois gates, no HEAD, depois do build final:**
```
PASSOU: gate 'casca-do-painel'        (# tests 45 / # pass 45 / # fail 0 / # skipped 0)
PASSOU: gate 'vocabulario-tamanhos'   (# tests 569 / # pass 569 / # fail 0 / # skipped 0)
```

**Árvore:** nada meu pendente. Os únicos arquivos modificados são
`app/Http/Controllers/MlbPublicadorAprovacaoController.php` e `routes/mlb_anuncios.php` — **da outra
sessão (planos 188-05/06)**, e **eu não os toquei**.
