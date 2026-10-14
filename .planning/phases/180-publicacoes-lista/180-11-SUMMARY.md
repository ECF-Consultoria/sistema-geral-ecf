---
phase: 180-publicacoes-lista
plan: 11
subsystem: Publicador — tela de Publicações
tags: [publicador, publicacoes, atalhos-de-teclado, estado-na-url, funcao-pura, list-18, list-19, criterios-11-e-12]
requires:
  - 180-04 (os 12 params do servidor, `sel` validado por `/^MLB\d+$/`, `->withQueryString()` no `paginate(50)`)
  - 180-06 (`BuscaDePublicacoes` com os ganchos `refDoInput` e `onTecla` já no contrato)
  - 180-08 (`LinhaDePublicacao` com `comFoco`, `className` e os cinco fundos — `bg-pub-row-focus` = `#1C1C1C`)
  - 180-10 (a página composta, o `navegar()` com os 12 params e o `grupoAberto()`)
  - 177-04 (`ignoraAtalho()`, a guarda de digitação — REUSADA, não reimplementada)
provides:
  - "`atalhosDaLista.js` — a máquina de teclado de LIST-18 como 6 funções PURAS exportadas, sem DOM e sem classe"
  - "`blocosDaLista()` e `chaveDoItem()` — a travessia da tabela virou função pura, e o teclado percorre o MESMO retorno"
  - "`selecionar()`/`limparSelecao()` — `sel` na URL só no clique e no Enter, PRESERVANDO a página"
  - "o gate `publicador-publicacoes-atalhos.test.js` (28 testes), com a tabela FID-01 caso a caso e a cascata do Escape em cascata"
  - "CIRURGIA 4 no gate do 180-05: ele reprovava o CÓDIGO CERTO ao ler o comentário"
affects:
  - 181 (o painel de inspeção de 460px: lê o MESMO `sel` da URL; `limparSelecao` é o "fecha o painel" da cascata)
  - 183 (a fase do Histórico — intocada)
tech-stack:
  added: []
  patterns:
    - "comportamento de RUNTIME (teclado, foco, preventDefault) extraído para função PURA, porque `renderToStaticMarkup` não o observa"
    - "`ref` em vez de dependências no `useEffect`: ouvinte registrado UMA vez, lendo sempre a versão atual"
    - "classe-gancho `js-*` para `querySelector` — explicitamente NÃO vocabulário do Tailwind"
    - "sonda de DOM por contrato de ARIA (`aria-expanded`) em vez de classe de aparência"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/atalhosDaLista.js
    - tests/js/publicador-publicacoes-atalhos.test.js
  modified:
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - tests/js/publicador-publicacoes-cabecalho.test.js
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "`sel` vai à URL SÓ no clique e no Enter; as setas mexem apenas no `foco`, que nunca vai à URL — um mecanismo só, zero `history.pushState`"
  - "o `cur` do `move()` inverteu a prioridade do protótipo (`foco ?? sel` em vez de `sel || foco`) porque aqui `sel` NÃO se move com as setas"
  - "`selecionar()` preserva a PÁGINA (`page: paginaAtual`) — selecionar não é mudar de recorte"
  - "`Enter` abre o Modal de Detalhe do Anúncio: destino REAL hoje, o mesmo do clique na linha (EXMP-01)"
  - "guarda de modificador ACRESCENTADA ao protótipo, por colisão medida com o ⌘K/Ctrl+K e o N do PublicadorHeader"
  - "os 7 filtros ficam PRESERVADOS na URL em `sub=rascunhos` e são ignorados no servidor"
  - "`ignoraAtalho()` da Fase 177 reusada; divergência declarada: alvo ausente devolve `false` aqui (senão a cascata do Escape travaria a seleção)"
metrics:
  duration: ~2h15
  completed: 2026-10-14
  tasks: 3
  commits: 4
  testes-js-novos: 35
  mutacoes: "8/8 pegas, 0 sobreviventes, 0 recusadas (5 controles negativos recusados)"
---

# Fase 180 Plano 11: os atalhos de teclado e o estado na URL — Summary

**Os dois últimos comportamentos da tela de Publicações.** `/` foca a busca, ↑/↓ e j/k navegam,
Enter abre, Esc fecha em cascata, e recarregar a página devolve o mesmo recorte e a mesma publicação
selecionada. **Nenhuma tecla ficou muda**, e o que não é provável por gate está escrito como lacuna.

**Commits:** `edb75231` (a máquina pura) · `4b8a8942` (a página ligada + CIRURGIA 4) ·
`a8815f15` (o gate + as 8 mutações) · este (SUMMARY + STATE).

---

## (0) ⚠️ O QUE O PLANO AFIRMAVA E JÁ ESTAVA FEITO — medido antes de escrever código

O plano manda "ampliar `navegar()` dos 4 params de hoje para os 12" e chama o `page` não-zerado de
**"bug latente que esta fase conserta"**. **Medido: as duas coisas já estavam prontas**, e não pelo
plano 180-04 — pelo **180-10** (`git log -S`, commit `a68be042`):

| afirmação do plano | medida real |
|---|---|
| "`navegar()` tem 4 params (`sub,busca,status,motivo`)" | **tem 12**: `sub, busca, status, motivo, comVenda, fase, tipo, catalogo, responsavel, periodo, visao, sel` — `MeusAnuncios.jsx`, `function navegar()` |
| "hoje `navegar()` **não** zera `page` — bug latente" | **já zera**: `page: 1,` está no corpo do objeto, antes do `...params`, com comentário explicando |
| "`preserveState: true, replace: true` ficam" | já estavam, e ficaram |
| "`->withQueryString()` já no `paginate(50)`" | confirmado — a paginação carrega os params de graça |

➡️ **O que entrou neste plano foi o GATE desses dois** (caso 8, itens 6 e 7) e **um bug latente
NOVO, que eu encontrei ao ligar a seleção** — descrito em (4).

---

## (1) FID-01 DE TECLADO — conferida item a item no protótipo, **o protótipo ganha**

Medido pela **semântica** (`componentDidMount`, `move(dir)`, `onSearchKey`, `onQuery`, `onLocate`),
nunca por número de linha. A tabela do plano **acertou as 10 linhas da tabela principal**; o que ela
errou foi por **omissão** e por um detalhe de `preventDefault`:

| # | o que a tabela do plano dizia | o que o protótipo DIZ | quem ganhou |
|---|---|---|---|
| 1 | as 10 linhas de gatilho/condição/efeito | idênticas | **empate — tabela correta** |
| 2 | (silêncio sobre `preventDefault` no `Enter`) | `Enter` **NÃO** chama `preventDefault()`; só as setas/`j`/`k` chamam | **protótipo** — implementado `preventDefault: false`, com asserção própria |
| 3 | (OMITIDO) | **`onQuery` também faz `focusId: null`** — digitar na busca LIMPA o foco do teclado | **protótipo** — virou `digitarBusca()` |
| 4 | `onLocalizar`: busca + filtros no default + `visao` + `sel` + `page` | **`onLocate` também faz `status: 'all'`** | **protótipo** — virou `status: 'todos'` (o valor real da whitelist do servidor) |
| 5 | `move()`: `cur = selId \|\| focusId` | idem | **protótipo**, mas com **consequência invertida** — ver (3) |
| 6 | `i < 0 → 0` e limites travados (não circula) | idem, confirmado | **empate** |
| 7 | `estaDigitando` = `tagName === 'INPUT'` | idem | **protótipo**, e reusei a guarda mais larga da Fase 177 — ver (2) |

⚠️ **Divergência que eu ACRESCENTEI de propósito, por colisão medida:** a **guarda de modificador**.
O protótipo não a tem porque não tem header. Esta tela tem: o `PublicadorHeader` (Fase 177, em
produção e aprovado) usa **⌘K / Ctrl+K** e a tecla **N**. Sem a guarda, `Ctrl+K` abriria a busca
global **e** subiria uma linha na lista, porque `k` está na tabela desta tela. `Escape` **não** passa
pela guarda (a cascata vale com qualquer modificador, como no protótipo), e há asserção dos dois
lados — incluindo o **controle** de que `k` sem modificador continua subindo.

⚠️ **`N` não está nesta tabela** — não há segunda colisão. Asserção explícita de que `n` e `N`
devolvem `nada`.

---

## (2) `ignoraAtalho()` REUSADA — e a divergência de contrato que ela exigiu

O briefing manda reusar a guarda da Fase 177 em vez de reimplementar. **Feito**, e a razão pela qual
isso **não** é divergir do protótipo está medida: a tela de Publicações tem **exatamente um** campo
de digitação (o `<input>` de 54px) e **nenhum** `textarea`, `select`, `canvas` ou `contenteditable`.
A guarda larga e a estreita são **equivalentes hoje**, e a larga protege de graça o dia em que a tela
ganhar um desses.

⚠️ **Mas o contrato tinha de divergir num ponto, e está escrito no código:** `ignoraAtalho(alvo)`
devolve **`true` na dúvida** (alvo nulo/forjado), porque lá a dúvida protege o que está sendo
digitado. Aqui esse valor alimenta a **cascata do `Escape`**, e um `true` por dúvida devolveria
`sairDaBusca` para um evento **sem alvo** — a página tentaria `blur()` num `null` e, pior, **a
seleção da linha nunca seria limpa**. `estaDigitando()` exige alvo-objeto com `tagName` string antes
de delegar; sem isso, devolve `false`. Há caso de teste dos dois (`target: null` + `temSelecao` →
`limparSelecao`).

---

## (3) ⚠️ O MECANISMO DO ESTADO NA URL, por extenso

### Por que `router.get`, e um só

`router.get(route('mlb.anuncios.meus', …), {os 12 params}, { preserveState: true, replace: true })`.
`preserveState` guarda o estado efêmero (`colapsados`, `copiada`, `marcadas`, `foco`, o modal) sem
remontar; `replace` não empilha histórico, então 20 toques num chip não enterram o "voltar".
**Zero `history.pushState` e zero `history.replaceState`** — com asserção negativa e isca (a negativa
não é vazia: `router.get(` está lá).

### `sub=rascunhos`: os 7 filtros ficam PRESERVADOS na URL

**Decisão tomada e registrada:** `trocarSubAba` manda `{ sub, motivo: null }` e **nada mais** — logo
os 7 filtros novos continuam no `router.get` (eles saem de `f.*`, que o servidor ecoou) e **ficam na
querystring**. O servidor os ignora quando `sub=rascunhos`, como já ignora `motivo`.
➡️ **Voltar para "Publicados" restaura o recorte**, em vez de o usuário perder o filtro por ter dado
uma olhada nos rascunhos. `motivo` continua zerado ao trocar `sub`, porque os chips de triagem não
existem no universo dos rascunhos.

### `page`: zerado ao filtrar, PRESERVADO ao selecionar

`navegar()` escreve `page: 1` **antes** do `...params` (asserção de POSIÇÃO, não só de presença: se
viesse depois, ninguém poderia pedir outra página).

⚠️⚠️ **E AQUI ESTÁ O BUG LATENTE QUE ESTE PLANO ENCONTROU — ele não estava no plano.** Como
`navegar()` zera `page`, e como este plano passou a escrever `sel` pelo MESMO `navegar()`, **clicar
numa linha da página 7 jogaria o usuário na página 1** — e a linha que ele acabou de selecionar
sumiria da tela. `selecionar()` e `limparSelecao()` sobrescrevem com `page: paginaAtual` (de
`anuncios.current_page`, saneado contra `NaN`/negativo/string). Há asserção de que `selecionar()`
**não** contém `page: 1`.

### `sel` só no clique e no Enter — e por que as setas NÃO escrevem na URL

`sel` é param de **servidor** (validado por `/^MLB\d+$/` no 180-04). Escrevê-lo a cada seta seria um
`router.get` por tecla sobre um acervo de dezenas de milhares de linhas. ➡️ **As setas mexem só no
`foco`** (estado da página, `bg-pub-row-focus` = `#1C1C1C`, o token do 180-08) e o **Enter commita**
a seleção na URL. Recarregar depois de **clicar** devolve a seleção — é o que o critério 12 pede.

⚠️⚠️ **CONSEQUÊNCIA MEDIDA QUE INVERTEU UMA LINHA DO PROTÓTIPO:** lá, `move()` faz
`cur = selId || focusId` e funciona porque `move()` **arrasta `selId` junto**. Aqui `sel` fica
**parado**, então partir dele prenderia o foco na linha de baixo da selecionada **para sempre** —
`↓↓↓` não sairia do lugar. A partida é `foco ?? sel`, com a razão escrita no próprio `switch`.
**Isto não é uma "melhoria por conta própria": é a única forma coerente com a decisão de não escrever
na URL a cada seta.**

### O `foco` é DESCARTADO quando a linha sai da tela

Troca de página, de filtro, de aba ou grupo recolhido: `focoAtual` só sobrevive se estiver em
`idsVisuais`. Sem isso o `Enter` abriria uma publicação que não está mais à vista — e o fundo de foco
não apareceria em lugar nenhum, deixando o `Enter` "aleatório" para o usuário.

---

## (4) A CASCATA DO `Escape` — e por que a 1ª rung **não** é tecla muda

A ordem é a literal do protótipo: **menu → busca → seleção**, e cada rung **retorna**.

⚠️ **O achado que o plano não previu:** o estado "qual menu de filtro está aberto" é **local do
`ChipsDeFiltro`** (`useState` dele), e o `MenuDeFiltro` **já fecha sozinho no `Escape`**, com ouvinte
próprio em `document`. A página **não tem** menu para fechar — e ler o estado do componente exigiria
editar `ChipsDeFiltro`, que é território de outro plano.

➡️ **Resolução, sem tocar em `ChipsDeFiltro` e sem tecla muda:** a rung `fecharMenu` existe para a
cascata **PARAR ali**. Sem ela, o mesmo `Escape` que fecha o menu **também limparia a seleção da
linha** — e é exatamente isso que o critério 11 proíbe ("fecha **só** o menu"). O efeito é
**observável e testado** (`fecharMenu` × `limparSelecao` com os dois estados ligados ao mesmo tempo).
A página pergunta ao DOM por **contrato de ARIA** (`[aria-expanded="true"]`), não por classe de
aparência, e **escopado** num `ref` do container do corpo — com `document` ele pegaria os popovers do
`PublicadorHeader`, que é irmão.

⚠️ **E o Modal de Detalhe é dono do teclado enquanto está aberto:** `modalAberto` desliga o ouvinte
inteiro. O Radix já fecha o diálogo no `Escape`, e deixar as setas moverem um foco **atrás** do modal
seria mexer no que o usuário não vê.

---

## (5) "Enter abre" LEVA A DESTINO REAL HOJE (EXMP-01)

`selecionar(chave)` → pinta a linha (`sel` na URL) **e** abre o **Modal de Detalhe do Anúncio** — a
casa da Nota ECF detalhada, da saúde do ML, do `performance_score`, do buybox e da série de 90 dias.
É o **mesmo** destino do clique na linha, que já existia. Asserção de fonte cobrando
`abrirDetalhe(chave)` dentro de `selecionar()`.

**O painel de inspeção de 460px é a Fase 181** e vai ler o **mesmo** `sel` da URL — o contrato está
escrito em comentário no `selecionar()`. **Nada desta fase depende dele.**

---

## (6) `blocosDaLista()` — a travessia virou função pura, e por quê

Os atalhos precisam das chaves **na ordem em que as linhas aparecem na tela**. Na visão "Por produto"
essa ordem **não é** a de `anuncios.data` (as linhas são reordenadas por grupo) e as de **grupo
recolhido não existem na tela**. O protótipo resolve isso empilhando `ids.push(p.id)` **dentro** do
`mkRow`, que só roda para as linhas renderizadas.

➡️ A travessia que vivia dentro do JSX subiu para `blocosDaLista()`, **exportada e testada**, e a
tabela desenha do **mesmo retorno** que `idsNaOrdemVisual()` percorre. Duas travessias divergiriam e
o `↓` pularia para uma linha invisível. `grupoAberto()` (LIST-09, do 180-10) continua sendo a única
casa da regra "a busca força os grupos abertos" — `blocosDaLista` a **chama**, não a repete.
Há caso de teste provando que a ordem visual (`MLB2, MLB1, MLB3`) **difere** da ordem do servidor
(`MLB1, MLB2, MLB3`) — sem esse contraste o teste não provaria que a função serve para algo.

⚠️ **A classe-gancho `js-pub-linha-ativa`** marca a linha ativa (a com foco; na falta dele, a
selecionada) para o `scrollIntoView({block:'nearest'})` achá-la. Ela **não é** utilitário do
Tailwind, **não gera CSS** e não deve gerar: a aparência é decidida por `fundoDaLinha()` no 180-08.
Escolhi classe e não `ref` porque `LinhaDePublicacao` já aceita `className` no contrato e **não**
aceita `ref` — dar-lhe um `ref` exigiria editar o componente. Há asserção de que ela aparece
**exatamente uma vez** com `sel` válido e **zero** sem `sel`.

---

## (7) ⚠️ AS 9 PEÇAS PROVADAS PELO **LITERAL**, NÃO PELO RUNTIME — escrito, não maquiado

`renderToStaticMarkup` **não dispara efeito de React, não tem `window`, não entrega `keydown` e não
mede `preventDefault`**. O caso 8 do gate impede que as peças **desapareçam**; ele **não** prova que
o ouvinte faz a coisa certa.

| # | o que o literal prova | o que ele **NÃO** prova |
|---|---|---|
| 1 | há **1** `addEventListener('keydown'` e **1** `removeEventListener('keydown'` | que o ouvinte seja de fato removido no unmount do Inertia |
| 2 | o literal **`1300`** existe (`String.raw` + isca de borda) e há `clearTimeout` | que o selo "copiado" volte sozinho em 1,3 s na tela |
| 3 | `preserveState: true` e `replace: true` estão no `router.get` | que o scroll e os grupos recolhidos sobrevivam a um filtro |
| 4 | o acesso a `navigator.clipboard` está dentro de um `try` **daquela função** (fatia por índice) | que a cópia funcione, ou que o `NotAllowedError` seja de fato engolido |
| 5 | **zero** `history.pushState`/`replaceState` | que o botão "voltar" do navegador se comporte |
| 6 | `page: 1` existe **e vem antes** do `...params` | que filtrar na página 7 volte à 1 de verdade |
| 7 | `selecionar()` manda `page: paginaAtual` e **não** `page: 1`, e chama `abrirDetalhe` | que o clique numa linha da página 7 preserve a página |
| 8 | **zero** `router.put/delete/patch` e **exatamente 1** `router.post` (D-11), e **zero `router`** no módulo de teclado | nada — este é estrutural e é o mais forte da lista |
| 9 | a página LIGA `refDoInput` e `onTecla` no `BuscaDePublicacoes`, importa a máquina, e tem `scrollIntoView({block:'nearest'})` | que `/` de fato ponha o cursor na busca, ou que a rolagem aconteça |

➡️ **O comportamento é provado nos casos 1-7 e 10-12 (função pura, rodando) e conferido na validação
visual com o usuário.** O que o gate de fonte garante é que a peça não sumiu em silêncio.

---

## (8) AS 8 MUTAÇÕES — 8/8 PEGAS, 0 sobreviventes, 0 recusadas

Disciplina aplicada: **contagem do alvo travada em 1 antes** (recusa se ≠ 1), **qual linha mudou**
impressa, **ANSI removido antes de filtrar**, **contagem de testes comparada**, restauração por cópia
pristina com **sha256**.

⚠️ **E a CONTAGEM foi medida nos dois estados, não chutada.** O gate deste plano **não tem sonda de
CSS**, logo **não depende de `mtime`** e tem **um regime só: 28**. Medido na BASE e em cada uma das 8
mutações (`contagem_ok=true` nas 8). Os gates vizinhos **têm** os dois regimes, e eu os medi:

| gate | FRESCO (build novo) | MUTADO (fonte mais novo) |
|---|---|---|
| `publicador-publicacoes-atalhos` (este) | **28** | **28** (sem sonda de CSS) |
| `publicador-paginas-anuncios-render` | **119** | **114** |
| `publicador-publicacoes-cabecalho` | **74** | **74** |
| `estrutura-meus-anuncios` | **30** | **30** |

| # | mutação | arquivo:linha | reprovou em |
|---|---|---|---|
| i | ordem do `Escape` trocada (selecionado **antes** de menu) | `atalhosDaLista.js:241`-`243` | caso **2b** (1 falha) |
| ii | `estaDigitando` removido do `/` | `atalhosDaLista.js:235` | caso **3** (1) |
| iii | `estaDigitando` removido de `j`/`k`/setas/Enter | `atalhosDaLista.js:249` | caso **4** (1) |
| iv | `proximoIndice` passa a **circular** | `atalhosDaLista.js:189` | caso **1** (1) |
| v | `i < 0 ? 0` → `-1` | `atalhosDaLista.js:187` | caso **1** (1) |
| vi | `1300` → `800` | `MeusAnuncios.jsx:652` | caso **8 (2)** (2) |
| vii | `removeEventListener` removido do cleanup | `MeusAnuncios.jsx:890` | caso **8 (1)** (2) |
| viii | `page: 1` removido do `navegar()` (o do `onLocalizar` **fica**) | `MeusAnuncios.jsx:461` | caso **8 (6)** (2) |

⚠️ **A (viii) merece nota:** a asserção `match(fonte, /page: 1,/)` **sobreviveria** — o `page: 1` do
`onLocalizar` continua lá. Quem a pegou foi a asserção de **POSIÇÃO dentro do corpo de `navegar()`**
(`posPage > 0 && posPage < posSpread`). Uma asserção de presença global teria registrado "sobreviveu"
num furo real.

⚠️ **E as mutações iv e v mostram por que o alvo foi escolhido na forma `encontrado + passo` e
`if (encontrado < 0)`:** o **docblock** da função cita a expressão do protótipo
(`Math.max(0, Math.min(ids.length - 1, i + dir))` e `i < 0 ? 0`). Um `sed` em `i + dir` pegaria **o
comentário** e registraria furo inexistente. É a armadilha §6.1 do learnings, evitada escolhendo os
nomes reais das variáveis.

**sha256 pós-campanha, IDÊNTICO ao pristino nos dois arquivos:**
`atalhosDaLista.js` `6181b3de1b54d379…` · `MeusAnuncios.jsx` `c2c5281a97f92b9c…`

**CONTROLE NEGATIVO da trava de contagem — 5 alvos recusados, sha INTACTO nos 5:**
`if (digitando || comModificador(evento)) return nada();` (**2** ocorrências),
`page: 1,` (**2**), `navegar({` (**17**), `return nada();` (**10**) e um alvo **inexistente** (**0**).
➡️ A trava não é decorativa.

---

## (9) ⚠️ CIRURGIA 4 — o gate do 180-05 reprovava o **CÓDIGO CERTO**

`publicador-publicacoes-cabecalho.test.js` tem o bloco `TAILWIND` que proíbe classe nos `.js` de
`publicacoes/`. Ele estava **vazio por construção** até hoje (o diretório não tinha `.js` nenhum, e o
próprio comentário dele dizia *"o diretório ganha `atalhosDaLista.js` no plano 180-11"*).

**Ao ganhar alvo real, ele reprovou — e reprovou o arquivo que OBEDECE a regra.** A causa: ele lia a
fonte **CRUA** (`fs.readFileSync`), e o cabeçalho do `atalhosDaLista.js` traz a frase que **explica
por que o arquivo não tem classe nenhuma** — e essa frase contém a palavra `className`.

| opção | por que NÃO |
|---|---|
| tirar a palavra do comentário | é dobrar o código ao gate. "Número que muda quando o trabalho certo é feito faz o executor seguinte ajustá-lo para passar" |
| afrouxar a regra | comentário não vira CSS, mas classe no código vira — a regra está certa |
| **corrigir a LEITURA** ✅ | o gate tem de medir o que o **runtime** enxerga, e `lerSemComentarios()` existe neste projeto exatamente para isso |

**Feito a terceira.** `-assert` = **0** (nenhuma asserção saiu; mudou **uma linha de leitura**) e
`+assert` = **5**, incluindo uma **ISCA DA LEITURA** que prova que a troca não cegou o gate: para cada
`.js` do diretório, a fonte sem comentário **não vem vazia** e **ainda contém `export`**; e, sem
escrever nada no repo, uma fonte com classe **fora** de comentário continua acusada e uma com classe
**só** no comentário não. **Contagem de testes: 73 → 74.**

➡️ **É a 5ª vez nesta milestone que um `grep` que casa com comentário produz desvio** — e a primeira
em que o alvo era um arquivo criado no mesmo plano.

---

## (10) O REAPONTE DO VOCABULÁRIO (sem remoção)

`publicador-vocabulario-novo.test.js`: `atalhosDaLista.js` entrou em `ARQUIVOS_NOVOS`, o diretório
subiu de **14 para 15** e a fase de **15 para 16**.

⚠️ **`MeusAnuncios.jsx` JÁ estava na lista** (plano 180-10) — **conferido lendo**, não acrescentado de
novo. E, como `DIRETORIOS_FECHADOS` **não cobre `Pages/`**, uma duplicata passaria calada: entrou
asserção de que o caminho aparece **exatamente uma vez**, nomeando a causa (o gate rodaria o mesmo
arquivo duas vezes).

⚠️ Entrou também `deepEqual` provando que `atalhosDaLista.js` é o **único `.js`** do diretório — o
resto é `.jsx` de propósito, porque é só no `.jsx` que o `content` do Tailwind varre classe.

⚠️ **`-assert` = 0 e `+assert` = 3 neste arquivo — e esse número é ENGANOSO**, exatamente como o
learnings avisa: a troca de `14` por `15` ficou numa **linha de argumento**, não na linha
`assert.equal(`. **O que mede de verdade é a CONTAGEM DE TESTES: 338 → 344 (+6).**

---

## (11) VERIFICAÇÃO

| gate | resultado |
|---|---|
| `publicador-publicacoes-atalhos.test.js` (**novo**) | **28 / 28 / 0** |
| `publicador-publicacoes-cabecalho.test.js` (180-05) | **74 / 74 / 0** (era 73) |
| `publicador-vocabulario-novo.test.js` | **344 / 344 / 0** (era 338) |
| `publicador-paginas-anuncios-render.test.js` | **119 / 119 / 0**, intocado |
| `estrutura-meus-anuncios.test.js` | **30 / 30 / 0**, **diff VAZIO** nos 3 commits |
| os 5 juntos, pós-build | **595 / 595 / 0**, **0 skipped** (28+74+344+119+30 = 595, fecha) |
| `publicador-visao-geral-acoes.test.js` (gate do ERP) | **214 / 214 / 0** e **diff VAZIO** desde `f23f610d` |
| `npm run test:js` **ANTES** | **4446 / 4445 pass / 1 fail / 0 skipped** |
| `npm run test:js` **DEPOIS** | **4481 / 4480 pass / 1 fail / 0 skipped** |
| a aritmética do delta | **+35** = 28 (gate novo) + 6 (vocabulário) + 1 (cabeçalho). **Fecha exatamente** |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` — **não corrigida**, de propósito |
| `npm run build` | exit **0**, 36,1 s |
| manifest **pelo JSON** | **479 entradas / 196 páginas** — exatamente a referência. `Pages/Mlb/MeusAnuncios.jsx` → `assets/MeusAnuncios-Dkr5s0y-.js` **PRESENTE** (⚠️ o hash tem **hífen**: por isso manifest por JSON, nunca por grep). `AnunciosHistorico`, `Publicador/Editor` e `Publicador/Configuracoes` presentes |
| `test -e` nos alvos de território | **23/23 existem** — zero caminho fantasma |
| território **commit por commit** | **0** arquivo proibido, **0** deleção, **0** arquivo PHP/migration/rota nos **três** commits |
| **CONTROLE** do gate de território | o MESMO comando **vê** os 5 arquivos que eu toquei (`321/0`, `512/0`, `511/53`, `36/1`, `360/2`, `53/14`) — logo não é vazio por construção |
| `publicacoes/` desde `0eb59ecf` | **só** `atalhosDaLista.js` (`321/0`), como o plano manda |
| `header/` + `AppLayout.jsx` desde `f23f610d` | **VAZIO** — com controle provando que o mesmo range VÊ os 15 arquivos de `publicacoes/` |
| os 2 gates PHP do ERP | **diff VAZIO** desde `f23f610d`, e `grep` prova que **nenhum dos dois** referencia `MeusAnuncios`, `atalhosDaLista` ou `resources/js` (**0** ocorrências). Meus 3 commits têm **zero linha de PHP** |
| suíte PHP | ⚠️ **NÃO RODADA, de propósito** (instrução do briefing: a sessão paralela da Fase 184 commita PHP na mesma árvore) |
| D-11 (×2) e D-22 | **intocados**, identificados por **SEMÂNTICA**: `estrutura-meus-anuncios.test.js:250`, `:256` e `:285` — as referências do briefing bateram desta vez |
| o discriminador de sessão | o **ESCOPO DO ASSUNTO** (`180-*` × `184-*`). ⚠️ **NÃO conferi autor**: as duas sessões usam o mesmo `user.name` |
| árvore | limpa nos meus alvos (`git status --short -- resources/js tests/js app database routes` → vazio) |

---

## (12) ⚠️ O QUE OS GATES **NÃO** PROVAM — escrito, não maquiado

1. ⚠️⚠️ **NENHUMA TELA RENDERIZADA FOI VISTA.** Não abri navegador, não deployei, não toquei no VPS e
   **não li produção** (bloqueada nesta sessão). O banco local **não tem empresa com `ml_token`**
   (`meus()` faz `abort_unless($company->mlToken !== null, 404)`) e **`ml_acervo_itens` está VAZIA** —
   todo cenário é sintético (`esbuild` + `react-dom/server`).
2. ⚠️ **As 9 peças da seção (7)** são provadas pelo **LITERAL**, não pelo comportamento. Teclado,
   foco, `preventDefault`, debounce, `preserveState` e rolagem **não são observáveis** em
   `renderToStaticMarkup`.
3. ⚠️ **Que `/` ponha o cursor no campo, que `↓` role a lista e que `Esc` feche o popover do
   `MenuDeFiltro` é VALIDAÇÃO VISUAL com o usuário.** O que está provado é a **decisão** de cada tecla
   (função pura) e a **presença** de cada peça (literal).
4. ⚠️ **A sonda `[aria-expanded="true"]`** foi medida no fonte do `ChipsDeFiltro`
   (`aria-expanded={temMenu ? abreEste : undefined}`), **não** exercitada com um popover aberto de
   verdade — isso exigiria DOM. Se um dia outro elemento dentro do container ganhar
   `aria-expanded="true"` sem ser menu de filtro, a 1ª rung da cascata disparará de mais (o efeito
   seria "Esc preserva a seleção quando não devia", nunca uma exceção).
5. ⚠️ **`Escape` tem DOIS ouvintes de janela** nesta tela: o do `PublicadorHeader` (Fase 177) e o meu.
   O do header é **idempotente** (fecha o que estiver aberto, no-op quando nada está). ➡️ **Overlap
   declarado:** com um popover **do header** aberto e uma linha selecionada, um `Escape` fecha o
   popover **e** limpa a seleção. Consertar exigiria editar `atalhosDoHeader.js`, que é **território
   proibido** neste plano. **Não é regressão** — antes deste plano não havia seleção para perder.
6. ⚠️ **A eliminação de variável pelo Rollup** não é reproduzível em `renderToStaticMarkup`. A
   proteção é **estrutural** (todas as flags nascem dentro do callback do `.map()`, incluindo as de
   `blocosDaLista`) e está declarada.
7. ⚠️ **Os 2 gates PHP do ERP não foram EXECUTADOS.** A prova é diff vazio + zero PHP + zero
   referência deles aos meus arquivos — evidência **estrutural**, não medição de execução.
8. ⚠️ **`onLocalizar` nunca roda hoje:** `faixaSucesso` é `null` SEMPRE (medido por três caminhos
   independentes no 180-04). A ligação é **real** e **nenhum resumo de exemplo foi fabricado**
   (EXMP-01). O handler está escrito, defensivo e com `status: 'todos'` medido no protótipo, mas **o
   caminho é inalcançável até alguém gravar o resumo na sessão** — e isso é do servidor.

---

## Known Stubs

Nenhum stub novo. Nenhum controle desabilitado, nenhum texto "em breve", nenhuma tecla muda.

| pendência herdada | estado |
|---|---|
| `algumFiltroAtivo` derivado no CLIENTE (o servidor não manda `filtros_ativos`) | **dívida do 180-07/180-10, inalterada.** Não é desta fase consertar: exige editar o controller |
| `responsavel.opcoes` fica `[]` (a lista de responsáveis não existe como prop) | **inalterada** — com `[]` o chip não é desenhado, que é honesto (EXMP-01) |
| `nova` não chega no item, logo `bg-pub-row-new` e o `<BadgeNova>` são inalcançáveis | **inalterada** (180-08) |
| o painel de inspeção de 460px | **Fase 181.** `sel` e `limparSelecao` já são o contrato dela |
| recarregar devolve a SELEÇÃO, mas **não** o FOCO do teclado | **decisão, não lacuna** — o foco é efêmero por desenho; escrevê-lo na URL seria um round-trip por seta |

## Threat Flags

Nenhuma superfície nova. **Zero** rota, endpoint, caminho de auth, acesso a arquivo ou mudança de
schema. Nenhuma migration, nenhum pacote novo, **nenhuma API nova** (`history.*` recusada de
propósito), nenhuma deleção.

- **T-180-11-D** (ouvinte global vazando): mitigado — `removeEventListener` no cleanup, com contagem
  exata no caso 8 (1) e a mutação **vii** pega.
- **T-180-11-D2** (`setTimeout` acumulando): mitigado — `clearTimeout` no cleanup do `useEffect`
  keyed em `copiada`, e o literal `1300` gateado (mutação **vi** pega).
- **T-180-11-T** (os 12 params na URL): mitigado — o cliente lê `filtros`, que o servidor **já
  saneou**, nunca a URL crua. 87 cenários de render adverso (`sel` em 15 formatos + 12 params × 6
  formatos + `filtros` inteiro em 6).
- **T-180-11-I** (`clipboard.writeText(ml_item_id)`): **accept** — o `ml_item_id` é público (está no
  permalink do ML), e agora dentro de `try` porque em contexto não seguro o `writeText` **lança de
  forma síncrona**, antes de devolver promessa nenhuma.
- **T-180-11-E** (**D-11**): mitigado — zero `router.put/delete/patch`, exatamente **1**
  `router.post` (para a rota que **enfileira**), e **zero `router`** no módulo de teclado. **Nenhum
  atalho de teclado dispara escrita.**

## Self-Check: PASSED

| o que | resultado |
|---|---|
| os 2 arquivos criados existem (`test -f`) | **2/2 FOUND** — `atalhosDaLista.js` (321 linhas), `publicador-publicacoes-atalhos.test.js` (870) |
| os 3 arquivos modificados existem | **3/3 FOUND** — `MeusAnuncios.jsx` (1387), `publicador-publicacoes-cabecalho.test.js` (1162), `publicador-vocabulario-novo.test.js` (669) |
| os 4 commits existem (`git log --oneline --all`) | **4/4 FOUND** — `edb75231`, `4b8a8942`, `a8815f15`, este |
| deleções em cada commit (`--diff-filter=D`) | **0 nos quatro** |
| `+assert > -assert` nos 3 gates tocados | **108>0** (novo) · **5>0** (cabeçalho) · **3>0** (vocabulário) |
| ⚠️ e a **CONTAGEM DE TESTES**, que é o que mede de verdade | 0→**28** · 73→**74** · 338→**344** |
| D-11 (×2) e D-22 intocados | **sim**, por SEMÂNTICA (`:250`, `:256`, `:285`), **diff VAZIO** no arquivo |
| 8 mutações | **8/8 pegas**, 0 sobreviventes, 0 recusadas, **5 controles negativos recusados** |
| `sha256` pós-campanha | **IDÊNTICO** nos dois arquivos |
| contagem de testes travada na campanha | **28 na BASE e nas 8**, com os dois regimes dos gates vizinhos **medidos** |
| JS antes/depois | 4446/4445/1 → **4481/4480/1** (+35, aritmética fechada), mesma falha pré-existente |
| `npm run build` + manifest pelo JSON | exit **0** · **479 entradas / 196 páginas**, página **PRESENTE** |
| arquivo gerado/não rastreado deixado para trás | **nenhum** — os 4 scripts (campanha, controle, manifest, território) vivem no scratchpad da sessão, fora do repo |
| `STATE.md` aditivo | conferido por `git diff --numstat` **no commit**: **0 remoções** |
| `ROADMAP.md` / `REQUIREMENTS-v26.md` | **não tocados**; `state.advance-plan` **não executado** |
| nenhuma afirmação de ter visto tela renderizada | **confirmado** — ver (12) item 1 |
