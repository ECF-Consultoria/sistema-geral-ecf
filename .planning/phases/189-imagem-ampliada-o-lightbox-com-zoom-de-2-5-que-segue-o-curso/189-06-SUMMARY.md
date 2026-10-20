---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 06
subsystem: publicador-aprovacao
tags: [lightbox, teclado, painel-de-revisao, foco, contexto, reaponte-com-veto]
requires:
  - "189-02 — a prop-raiz `lightbox` (`{originais}`) no `MlbPublicadorAprovacaoController::index`"
  - "189-03 — `LightboxDaImagem` (controlado), `moverNoLightbox`, `indiceNoLightbox`, `ZOOM_NORMAL`"
  - "189-05 — `LateralDoLightbox` (default), `aoClicarNoZoom` que ENTREGA o zoom novo"
  - "188-07 — `resolverAtalhoDoPainel`, `RaizDoPainel`, os slots de NÓ do painel"
  - "188-08 — `ImagemGrandeDoPainel` (`CLASSES_OVERLAY`), `ColunaDeFotos` (`indiceSelecionado`)"
provides:
  - "o '⤢ Ampliar' do painel (`:604`/`:607`), só com handler função"
  - "o degrau do lightbox no `Escape`: `modal → lightbox → painel`"
  - "←/→ do lightbox DANDO A VOLTA; ↑/↓ navegando a fila por baixo"
  - "`contextoDoLightbox` / `indiceNoContexto` / `fecharLightboxDevolvendoFoco` / `aoFecharOLightbox`"
  - "a prop `lightbox` repassada pela página como DADO, e o lightbox montado controlado"
affects:
  - "189-07 — as ações (Aprovar foto / Reprovar) ligam no CONTEXTO que este plano guarda"
  - "189-08 — o fechamento registra as ressalvas e os achados desta wave"
tech-stack:
  added: []
  patterns:
    - "reaponte com veto (DOIS gates da 188, contagem alheia INALTERADA nos dois)"
    - "contexto capturado na abertura e refrescado só na MESMA oferta (derivação pura, sem useEffect)"
    - "premissa inobservável travada como PROXY DE FONTE, declarada como tal"
key-files:
  created:
    - tests/js/publicador-lightbox-painel-189.test.js
    - tests/gates/contagens-189-06.txt
  modified:
    - resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-painel-fotos-188.test.js
    - tests/js/publicador-painel-casca-188.test.js
    - tests/js/publicador-fila-filtros-187.test.js
decisions:
  - "D-189-3b aplicada: o '⤢ Ampliar' é desta fase; o gate do 188-08 foi REAPONTADO COM VETO"
  - "O ESTADO do lightbox mora na PÁGINA, não no painel — divergência MEDIDA da prosa do plano"
  - "O token do foco é `botao-que-abriu`, não `ampliar`: silêncio por caixa de UMA letra é sorte"
  - "Índice adverso no '⤢ Ampliar' NÃO abre nada (o lado seguro), em vez de clampar para 0"
  - "PERGUNTA-189-D1: default aplicado — o painel FICA na oferta nova; `voltarParaAOferta` é sempre null"
metrics:
  duration: "~6h"
  completed: "2026-10-20"
---

# Fase 189 Plano 06: o fio painel ↔ lightbox — o "⤢ Ampliar", o degrau do `Escape` e o contexto próprio

O painel ganhou o **"⤢ Ampliar"** que a 188 deixou de fora de propósito, o `Escape`
passou a fechar **o lightbox antes do painel**, ←/→ passaram a mover **a foto dele**
(dando a volta) enquanto ↑/↓ continuam navegando a fila por baixo, e o lightbox
passou a guardar **a própria oferta, versão e imagens** capturadas na abertura — o que
é a diferença entre aprovar a foto certa e escrever na versão errada.

---

## §1 — A PARTIDA, e o que ela media

**PARTIDA: `50cff5f5`** (`docs(189-05): a SUMMARY da lateral de 320px — as tres formas
da prop e a face 1 do limite`), capturada na **PRIMEIRA ação** desta wave, antes de
qualquer leitura de plano. É exatamente o commit que o briefing previa.

| baseline | na PARTIDA (medida por mim) | no FIM | conta |
|---|---|---|---|
| **JS** (`--suite js`) | **5958 / 5957 pass / 1 fail / 0 skipped** | **5980 / 5979 pass / 1 fail / 0 skipped**, `exit=0` | +22 = os 22 casos do gate novo. **A soma fecha.** |
| **PHP RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`) | **2595 / 23.878 assertions / 0 falhas** | **2595 / 23.878 / 0**, `exit=0` | **IDÊNTICO nas DUAS pontas** |
| `vocabulario-tamanhos` | 695 / 695 / 0 | 695 (este plano **não cria `.jsx`**) | não sobe |
| `coluna-de-fotos` (188-08) | 32 / 32 / 0 | 32 / 32 / 0 | **INALTERADA** (`32,28`) |
| `casca-do-painel` (188-07) | 45 / 45 / 0 | 45 / 45 / 0 | **INALTERADA** (`45,41`) |
| `186-09 transversais` | — | **12 passed / 258 assertions** | **INALTERADO** |

⚠️⚠️ **A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA.** `artisan test` aborta com
**Fatal de memória** em `Illuminate\Http\Testing\FileFactory.php:87` a ~6% de 10.653
testes, e `-d memory_limit=-1` **não resolve** (o estouro é em processo **FILHO**).
➡️ **Medi pelo RECORTE, e digo isso.** O número de 10.653 não é afirmado por mim.

**A única falha JS é a pré-existente da Fase 87**, atribuída por **execução ISOLADA**:

```
node --test --test-reporter=tap tests/js/estrutura-grade-glide.test.js
# tests 19 / # pass 18 / # fail 1 / # skipped 0
tests/js/estrutura-grade-glide.test.js:122
  test('Características secundárias nasce recolhido (é o grupo que mais infla)')
```
**Não é desta fase e NÃO foi corrigida.**

### §1.1 — O recorte PHP no FIM

⚠️ Rodado **sozinho** (nenhuma suíte simultânea — a corrida de
`storage/framework/testing` entre suítes produz falhas que PASSAM em isolamento).

```
Tests:    2595 passed (23878 assertions)      exit=0
```

---

## §2 — Os commits (escopo do assunto `(189-06)` no `%s`)

| commit | assunto |
|---|---|
| `33f91b64` | `test(189-06)`: o RED do "⤢ Ampliar" — e a inversão do gate do 188-08 por REAPONTE COM VETO |
| `b52b8edc` | `feat(189-06)`: o "⤢ Ampliar" do painel — a condição do wrapper AFROUXADA, não um segundo wrapper |
| `2a2d7fbd` | `test(189-06)`: o RED do contexto próprio, do degrau do `Escape` e do foco — 9 de 11 vermelhos |
| `4a76d21b` | `feat(189-06)`: o degrau do `Escape`, o contexto PRÓPRIO do lightbox e o foco de volta |
| `cf73f396` | `fix(189-06)`: a mutação **v SOBREVIVEU** — a ausência da Fase 192 só era cobrada em UM dos dois regimes |
| `f11af1ac` | `fix(189-06)`: o **DÉCIMO-PRIMEIRO** gate — `ESTADO_LOCAL_DA_PAGINA` pela via canônica |
| `c967c77b` | `test(189-06)`: a premissa INOBSERVÁVEL do despacho, travada como PROXY DE FONTE |

**RED medido de verdade, não encenado.** O RED da tarefa 1 foi medido **com as fontes
do HEAD no lugar**, por cópia para fora do repo + `git show HEAD:` + restauração em
`trap EXIT` (⚠️ **sem `git stash` e sem `git checkout`**): **11 testes / 5 pass / 6
fail**, e os 6 vermelhos são os discriminantes (D2, D1/D4, os três estados + ordem,
`aoClicarEmAmpliar`, a `ref`, o repasse da coluna). Os 5 que já passavam são guardas de
território/manifesto/protótipo e **têm de ser verdadeiros antes E depois** — está
declarado. O RED da tarefa 2: **22 / 13 pass / 9 fail**; os 2 casos novos que já
passavam (↑/↓ e o resolvedor adverso) são guardas de **não-regressão**, e são eles que
matam as mutações **viii** e **(adverso)**.

---

## §3 — A MONTAGEM e o RELÉ DE PROPS, medidos

### §3.1 — O "⤢ Ampliar" (tarefa 1)

**`ImagemGrandeDoPainel.jsx`** (188-08) — quatro exports novos e três props novas:

| o que | onde (por NOME, nunca por linha) |
|---|---|
| `CLASSES_AMPLIAR` | `h-[30px] cursor-pointer whitespace-nowrap rounded-[8px] border-none bg-pub-page/[0.85] px-[11px] text-[12px] font-medium text-pub-txt` |
| `AMPLIAR` | `'⤢ Ampliar'` — o `⤢` é **U+2922**, medido por `codePointAt` |
| `aoClicarEmAmpliar(indice, onAmpliar)` | handler exportado (`renderToStaticMarkup` **não dispara `onClick`**) |
| `refDoBotao(ref)` | guarda da `ref` (número/string/array **LANÇAM** no React) |
| props | `indice`, `onAmpliar`, `refDoAmpliar` |

⚠️ **A CONDIÇÃO do wrapper foi AFROUXADA, não criado um segundo wrapper.**
`CLASSES_OVERLAY` já era named export do 188-08 e é **idêntico** ao `:604` medido
(`position:absolute;right:10px;bottom:10px;display:flex;gap:6px`); o que mudou é que
ele passa a nascer com `regenerando || ampliar !== null` (era só `regenerando`,
ACHADO A5). **Uma segunda cópia divergiria na primeira edição.**
`arquivo:linha` do toque: `CLASSES_OVERLAY` em **`ImagemGrandeDoPainel.jsx:213`** (era
`:205`), o ternário do wrapper agora em **`:465`** (era `:372`).

**`ColunaDeFotos.jsx`** (188-08) — **só o repasse**: duas props (`onAmpliar`,
`refDoAmpliar`) e o `<ImagemGrandeDoPainel>` passando `indice={indice}` (o VIVO e já
clampado por `indiceSelecionado`, nunca o `selecionada_indice` do payload, que está
preso no 0 — AVISO-189-1). Nenhuma outra classe, nenhum texto, nenhuma ordem mudou.
A frase do docblock que dizia *"⤢ Ampliar não é desenhado"* foi **EDITADA**, não
apagada em silêncio, nos **dois** arquivos.

### §3.2 — O estado, o teclado e o foco (tarefa 2)

**`PainelDeRevisao.jsx`** — acréscimos, nunca reescrita:

| o que | forma |
|---|---|
| `ACOES.FECHAR_LIGHTBOX` / `MOVER_LIGHTBOX` | `'fechar-lightbox'` / `'mover-lightbox'` |
| `FOCO_NO_BOTAO_DE_ABRIR` | `'botao-que-abriu'` (§6.2) |
| `idPositivoDoPainel` | inteiro **POSITIVO** (o `0` é id plausível) |
| `resolverAtalhoDoPainel` | + `temLightbox` (padrão `false`, `=== true` ESTRITO), `imagemDoLightbox`, `totalDoLightbox` |
| `contextoDoLightbox(guardado, painelAtual, lightboxAtual)` | função pura exportada |
| `indiceNoContexto(indice, contexto)` | função pura exportada |
| `fecharLightboxDevolvendoFoco({ref, fechar})` | função pura exportada |
| `aoFecharOLightbox({contexto, painelAtual})` | função pura exportada |
| props do componente | `temLightbox`, `imagemDoLightbox`, `totalDoLightbox`, `onFecharLightbox`, `onMoverLightbox` |

**`Aprovacao.jsx`** — `lightbox = null` na assinatura (**DADO**), `useState(doLightbox)`,
`useRef(refDoAmpliar)`, a derivação **PURA** do contexto a cada render (sem
`useEffect`, o mesmo contrato do `indiceDaFoto` que já existia), `onAmpliar` +
`refDoAmpliar` na `<ColunaDeFotos>`, os cinco fios novos no `<PainelDeRevisao>`, e o
`<LightboxDaImagem … lateral={<LateralDoLightbox …/>} />` **controlado**.

### §3.3 — ⚠️⚠️ RENDER ADVERSO DE CADA PROP DO RELÉ, **E NO CLIQUE**

A TELA PRETA voltou SEIS vezes nesta milestone, a última **no clique**. Medido no gate
`lightbox-no-painel`, com **17 valores adversos** (`null`, `undefined`, `0`, `-1`,
`NaN`, `Infinity`, `''`, `'  '`, `'texto'`, `true`, `false`, `[]`, `[1,2]`, `{}`,
`{toString: null}`, `{current: null}`, função):

| onde | o que foi exercido | resultado |
|---|---|---|
| `ImagemGrandeDoPainel` | **cada uma** das 4 props (`imagem`, `indice`, `onAmpliar`, `refDoAmpliar`) adversa, uma por vez | nunca lança; `doesNotMatch(/\[object Object\]\|NaN\|undefined\|Invalid Date/)` |
| `ImagemGrandeDoPainel` | **o objeto de props inteiro** adverso | nunca lança |
| `ColunaDeFotos` | `onAmpliar` e `refDoAmpliar` adversas | nunca lança; sem sujeira na tela |
| **NO CLIQUE** | `aoClicarEmAmpliar(adverso, nada)` e `(0, adverso)` | nunca lança, devolve `false`, **não chama o handler** |
| **NO CLIQUE** | `resolverAtalhoDoPainel` com `evento`/`tecla`/`imagemDoLightbox`/`totalDoLightbox` adversos | nunca lança; o resultado é `null` ou `{acao: string, imagem: inteiro\|null}` |
| **NO CLIQUE** | `contextoDoLightbox` com os três argumentos adversos e campo por campo | nunca lança; `ao_vivo` sempre booleano |
| **NO CLIQUE** | `fecharLightboxDevolvendoFoco` com `ref` nula / sem `current` / `current.focus` não-função / pedido inteiro adverso | nunca lança, **e FECHA sempre** |
| **NO CLIQUE** | `aoFecharOLightbox` com pedido adverso | nunca lança; `mudarFotoDoPainel` sempre `false` |

⚠️ **A sonda de sujeira tem ISCA nas duas direções**: ela casa `[object Object]` e
`NaN`, e o **CONTROLE** (render bom) sai limpo — sem isso ela não distinguiria nada.
⚠️ Nenhum `String(`, `hasOwnProperty`, `JSON.stringify` entrou em arquivo nenhum
(o `casca-188` os proíbe no painel, e **está certo**).

### §3.4 — `aoClicarNoZoom` é CONSUMIDO; o zoom **NÃO** é recomputado

A 189-05 divergiu de propósito do `<interfaces>` do plano: `aoClicarNoZoom(zoom,
onAlternarZoom)` **já chamou `alternarZoom`** (189-03) e **entrega o zoom NOVO**.
➡️ Na página:

```jsx
const alternarOZoom = (zoomNovo) => setDoLightbox((atual) => (atual === null
    ? null
    : { ...atual, zoom: zoomNovo }));
```

**Prova de que não há segunda verdade:** `Aprovacao.jsx` **não importa**
`alternarZoom` nem `ZOOM_AMPLIADO`, e **não contém** `2.5`:

```
# lido SEM comentario (`lerSemComentarios`), que e como os gates leem:
alternarZoom    -> 0        ZOOM_AMPLIADO -> 0        2.5 -> 0
ZOOM_NORMAL     -> 3        onAmpliar     -> 1
String(         -> 0        localStorage  -> 0        sessionStorage -> 0
isValidElement(lightbox) -> 0   (a prop e DADO, nao slot de NO)
<LightboxDaImagem -> 1      <LateralDoLightbox -> 1   lightbox = null -> 1
Gerar novamente -> 0        Comparar lado a lado -> 0
bytes sem comentario: 34827
```
⚠️ No arquivo CRU `alternarZoom` aparece **1x** e `String(`/`localStorage` aparecem
**7x**/**6x** — **todas em COMENTARIO**, que e exatamente por que a leitura dos gates
e SEM comentario: *"palavra no docblock reprova pelo proprio conserto"*.
O único import de zoom é `ZOOM_NORMAL` (= `1`), usado para **zerar** o zoom ao abrir e
ao trocar de foto (`:1319`/`:1321`/`:1327`). ⚠️ E a origem **NÃO** é zerada na troca —
só o zoom —, fiel ao protótipo.

---

## §4 — ⚠️⚠️ O `casca-188:1035`, ANTES e DEPOIS, e o NOME DE PROP QUE ESCOLHI

`tests/js/publicador-painel-casca-188.test.js:1035` varre a **FONTE** de
`PainelDeRevisao.jsx`, `TopoDoPainel.jsx` e `IdentificacaoDaOferta.jsx`, **lida SEM
comentário** (`lerSemComentarios`), por `/Ampliar/` — e `:1034` por `/⤢/`.
⚠️ **Uma prop chamada `onAmpliar` ali REPROVARIA.**

| momento | `casca-188` (arquivo inteiro) | as duas asserções |
|---|---|---|
| **ANTES** (na PARTIDA `50cff5f5`) | **45 / 45 pass / 0 fail / 0 skipped** | `:1034` e `:1035` VERDES |
| **DEPOIS** (no FIM) | **45 / 45 pass / 0 fail / 0 skipped** | `:1034` e `:1035` VERDES, **nas mesmas linhas 1034/1035** |

**O nome que escolhi, e a razão:** nenhuma prop do painel cita o botão. Os cinco fios
são **`temLightbox`**, **`imagemDoLightbox`**, **`totalDoLightbox`**,
**`onFecharLightbox`** e **`onMoverLightbox`** — e o handler de abrir **não passa pelo
painel** (ele vai da página direto para a `ColunaDeFotos`, que **não** está na lista
daquele gate). ➡️ `onAmpliar` existe **só** em `ImagemGrandeDoPainel.jsx`,
`ColunaDeFotos.jsx` e `Aprovacao.jsx`, nenhum deles varrido por `/Ampliar/`.
⚠️ **E eu travei isso no meu gate** (`ARMADILHA A2`): `doesNotMatch(FONTE_PAINEL,
/Ampliar/)` e `/⤢/`, **mais** a asserção de que as sondas do vizinho continuam
existindo — sem essa segunda metade a minha estaria protegendo o nada.

---

## §5 — ⚠️⚠️ A INVERSÃO DE **EXATAMENTE** `fotos-188:639` e `:640`

**Reaponte com veto** (`188-REGUA.md` §10: *"teste que para de provar algo é pior que
teste ausente"*), **dentro do caso que já existia** — nenhum `test()` acrescentado nem
removido:

| asserção | antes | depois |
|---|---|---|
| `:639` `doesNotMatch(completo, /Ampliar/)` | ausência absoluta | ausência **SEM handler** (continua verdadeira) |
| `:640` `doesNotMatch(completo, /⤢/)` | idem | idem |
| **nova** `match(comAmpliar, /⤢ Ampliar/)` | — | **PRESENÇA com handler função** |
| `:641` `doesNotMatch(completo, /Gerar novamente/)` | Fase 192 ausente | **INTOCADA** |
| **nova** `doesNotMatch(comAmpliar, /Gerar novamente/)` | — | Fase 192 ausente **também com handler** (§7.1) |
| `:1361` `match(em(607), /⤢ Ampliar/)` | asserção POSITIVA do protótipo | **INTOCADA** |

**Os ponteiros, por NOME (eles deslocaram porque EU editei o arquivo):**
`:639 → :667`, `:640 → :668`, `:641 → :669`, `:1361 → :1404` (arquivo 1591 → 1634
linhas). O gate `contrato-188-inventariado` **continua verde** (ele cobra `linha <=
tamanho`, e o arquivo só cresceu).

**A ISCA, colada:**
```
assert.match('<button>✦ Gerar novamente com IA</button>', /Gerar novamente/)   -> a sonda da Fase 192 ENXERGA
assert.doesNotMatch('<button>⤢ Ampliar</button>', /Gerar novamente/)           -> ela NAO acusa o "⤢ Ampliar"
assert.match('<button>⤢ Ampliar</button>', /⤢ Ampliar/)                        -> a sonda do botao ENXERGA
assert.doesNotMatch('<button>✦ Gerar novamente com IA</button>', /⤢/)          -> ela NAO acusa a Fase 192
```
E a contagem alheia é **CONFERIDA IGUAL por asserção**, não copiada de cabeça: o meu
gate lê a linha `coluna-de-fotos` de `contagens-188-08.txt` e a linha
`coluna-de-fotos-188` do meu manifesto e exige que o `tests=` seja **o mesmo texto**.

### §5.1 — Os outros SETE gates JS de ausência de "Ampliar" (+2 PHP) seguem VERDES e INTOCADOS

Medidos um a um, no FIM, **depois** do `npm run build`:

| gate | o que ele varre | medido |
|---|---|---|
| `publicador-painel-casca-188.test.js` (`:1034`/`:1035`) | FONTE de `PainelDeRevisao/TopoDoPainel/IdentificacaoDaOferta` | **45 / 45 / 0** |
| `publicador-painel-preco-188.test.js:960` | FONTE de Kpis/Composicao/Simulacao/Irma/**ColunaDePreco** | **48 / 48 / 0** |
| `publicador-painel-rodape-188.test.js:1118` | FONTE de Pendencias/Rodape | **42 / 42 / 0** |
| `publicador-painel-modais-a-188.test.js:895` | FONTE de `ModalDeAjuste` | **27 / 27 / 0** |
| `publicador-painel-modal-excecao-188.test.js:867` | FONTE de `ModalDeExcecao` | **23 / 23 / 0** |
| `publicador-painel-modais-b-188.test.js:616` | FONTE de `ModalDeFrete` | **22 / 22 / 0** |
| `publicador-painel-modal-reprovar-188.test.js:685` | FONTE de `ModalDeReprovarImagem` | **19 / 19 / 0** |
| `publicador-painel-aviso-versao-188.test.js:742`/`:761` | FONTE de `AvisoDeVersaoMudou` | **12 / 12 / 0** |
| `FotosDoPainelServiceTest.php:1733` | FONTE dos serviços (território **VETADO**) | verde no recorte PHP |
| `PrecoDoPainelServiceTest.php:1471` | idem | verde no recorte PHP |

⚠️ **Nenhum deles foi editado.** Provado pela **PI(189-06)** (§9): interseção VAZIA.

---

## §6 — ⚠️⚠️ DOIS GATES QUE O INVENTÁRIO **NÃO** MAPEOU, e que o meu trabalho tropeçou

### §6.1 — O **DÉCIMO**: o `ACOES` FECHADO do `casca-188`

O `189-CONTRATO-188.md` A2 lista **NOVE** gates de `/Ampliar/`. **Este não casa
`/Ampliar/`** e por isso ficou fora: `publicador-painel-casca-188.test.js:1264`
(caso *"o lightbox NÃO foi antecipado"*) tinha

```js
assert.equal(Object.values(M_PAINEL.ACOES).includes('fechar-lightbox'), false, 'o lightbox da Fase 189 foi antecipado');
assert.deepEqual(Object.keys(M_PAINEL.ACOES).sort(), ['FECHAR_MODAL','FECHAR_PAINEL','IR_PARA_OFERTA','TROCAR_IMAGEM'], …);
```

➡️ **Ele REPROVOU o degrau** (`casca-188`: 41 / 39 / **1 fail** / 1 skip). ⚠️ **A
premissa do `<behavior>` da tarefa 2 — *"todos os casos do `casca-188` continuam
passando sem alteração"* — é FALSA, e isso foi MEDIDO.**

**Por que o conserto é do meu lado, e não devolvi a pergunta:** (a) o degrau estava
*"PREVISTO e DECLARADO"* pela própria 188-07, no docblock do teclado daquele arquivo;
(b) a `189-REGUA.md` §2 **atribui o degrau ao 189-06**; (c) o `<action>` da tarefa 1
do meu plano autoriza, textualmente, *"se outro gate da 188 também proíbe [o que esta
fase entrega]: **a mesma cirurgia, com a mesma disciplina**"*; (d) é a família *"número
que o trabalho correto derruba"*, com 13+ ocorrências nesta milestone, **sempre
consertada na RAIZ**.

**O que entrou (e não é mais fraco):** duas listas NOMEADAS — `ACOES_DA_188_07`
(EXIGIDA, uma por uma) e `ACOES_DEPOIS_DA_188_07` (as duas da Fase 189, **com a fase
dona ao lado**) —, o conjunto **continua FECHADO** (uma sétima ação ainda reprova) e
**isca nas duas direções** (a sonda das quatro acusa a falta de uma; o conjunto acusa
uma ação inventada). **Contagem `45,41` INALTERADA.** E o **meu** gate trava o reaponte
para que ele não seja desfeito em silêncio.

### §6.2 — O **DÉCIMO-PRIMEIRO**: `ESTADO_LOCAL_DA_PAGINA`

Achado pela **suíte JS completa** (não por gate do plano):
`publicador-fila-filtros-187.test.js:684` compara o conjunto de
`const [x, setX] = useState(` da página com a lista CONSTANTE
`ESTADO_LOCAL_DA_PAGINA`, **nos dois sentidos**. O `useState` do lightbox o fez
reprovar (`--suite js`: 5980 / 5978 / **2 fail**).

➡️ Consertado pela **VIA CANÔNICA que o docblock daquela lista prescreve**: **uma linha
NOMEADA** (`doLightbox`), com a fase e o plano dono e a razão escrita (por que é GESTO
e não DADO, por que é **um** `useState` só com o contexto dentro, e por que vive na
página). **Nenhum número foi subido, nenhuma asserção foi afrouxada:** a lista continua
fechada nos dois sentidos. Medido: `filtros-render` **29 / 29 / 0** — a MESMA contagem
que `contagens-187-06.txt` declara (`29,19`).

---

## §7 — A MUTAÇÃO: `mutar-189-06.sh`, 9 de 9 mortas

**Commitei ANTES** (`4a76d21b`), `cp -p` para fora do repo, ocorrência **travada em 1**
por string exata (`indexOf`, nunca regex solta), **linha conferida** depois, aplicação
por **divergência de `sha256`**, restauração **DA CÓPIA** em `trap EXIT`, e `sha256`
final contra `git show HEAD:` com `tr -d '\r'`. ⚠️ **`git checkout` NUNCA. `git stash`
NUNCA** (a árvore tem **27 stashes**, que eu apenas **listei**).
⚠️ Script nomeado com fase e plano (`mutar-189-06.sh`, sha256 `3612077287832414`) —
o scratchpad é compartilhado e **há outro agente nesta sessão**.

```
PRISTINO tests/js/publicador-lightbox-painel-189.test.js :: # tests 22 # pass 22 # fail 0
PRISTINO tests/js/publicador-painel-fotos-188.test.js    :: # tests 32 # pass 32 # fail 0

MUTACAO i-escape-sem-degrau            :: MORREU (1) | linha 630 PainelDeRevisao.jsx     | # tests igual ao pristino (22)
MUTACAO ii-setas-clampam               :: MORREU (1) | linha 655 PainelDeRevisao.jsx     | # tests igual ao pristino (22)
MUTACAO iii-setas-no-painel            :: MORREU (1) | linha 653 PainelDeRevisao.jsx     | # tests igual ao pristino (22)
MUTACAO iv-ampliar-sem-guarda          :: MORREU (2) | linha 452 ImagemGrandeDoPainel.jsx| # tests igual ao pristino (22)
MUTACAO v-188-afrouxa-fase-192         :: MORREU (1) | linha 677 publicador-painel-fotos-188.test.js | # tests igual ao pristino (22)
MUTACAO vi-fecha-sem-foco              :: MORREU (1) | linha 813 PainelDeRevisao.jsx     | # tests igual ao pristino (22)
MUTACAO vii-contexto-segue-o-painel    :: MORREU (1) | linha 756 PainelDeRevisao.jsx     | # tests igual ao pristino (22)
MUTACAO viii-fila-inerte-com-lightbox  :: MORREU (1) | linha 665 PainelDeRevisao.jsx     | # tests igual ao pristino (22)
MUTACAO ix-despacho-desfeito (EXTRA)   :: MORREU (1) | linha 1092 PainelDeRevisao.jsx    | # tests igual ao pristino (22)

=== RESTAURAÇÃO (da CÓPIA) e sha256 contra `git show HEAD:` ===
  OK  PainelDeRevisao.jsx            (cf108bd7ce28f387797238565690cceba25da908ff06e47d97a3a3a8be916f86)
  OK  ImagemGrandeDoPainel.jsx       (4e1a5cb8b43bf345b129523fa5a1060b4189eca698836edd53cf57de684ee8df)
  OK  publicador-painel-fotos-188.test.js (14744fbe19539caa2d99b030c12f8886a26205c1cfa88e1f3b84dfb3d42db7ae)
```

⚠️⚠️ **O `# tests` do MUTADO foi conferido IGUAL ao do pristino em TODAS as nove** — se
caísse, o arquivo **não teria carregado** e a morte não seria da asserção.

### §7.1 — ⚠️⚠️ A MUTAÇÃO **v** SOBREVIVEU NA PRIMEIRA RODADA, e era FURO REAL

Na primeira campanha, `v-188-afrouxa-fase-192` **SOBREVIVEU**. **Investigada, nunca
presumida equivalente.** Causa medida: o meu gate cobrava a ausência da Fase 192 no
`fotos-188` **só no regime SEM handler** (`completo`) — então apagar a MESMA ausência
no regime **COM** handler (`comAmpliar`) passava calado. **E é exatamente o regime COM
handler que o T-189-40 teme**: é a inversão do "⤢ Ampliar" que poderia arrastar a
ausência vizinha.

➡️ Consertado (`cf73f396`): os **dois** regimes são cobrados, um por asserção, mais uma
**contagem EXATA de 2** ocorrências e uma **isca** que prova que a sonda acusa quando só
um regime existe. Re-rodada completa: **9/9 mortas, 0 sobreviventes.**

### §7.2 — `npm run build` **DEPOIS** da mutação, com o diff conferido **ANTES**

```
git diff HEAD --stat -- resources/   -> (vazio)   # restauração conferida, nada de WIP alheio
npm run build                        -> exit 0  (✓ built in 1m 17s)
```
⚠️ Dois builds nesta wave, **com `git diff HEAD -- resources/` conferido antes de cada
um** (a árvore é compartilhada e **há outro agente ativo nesta sessão**). O `cp -p`
atualiza o `mtime`, e sem o build a camada de CSS cai em `skipped` — que **não é
regressão**, e é o regime `28`/`41` que os manifestos da 188 já declaram.

### §7.3 — As 15 classes no CSS CONSTRUÍDO, com isca nas duas direções

`public/build/assets/app-CSIqQ5bQ.css` (234.284 bytes), lido pelo **manifest em JSON**
(`resources/js/app.jsx` → `css[0]`):

```
OK  h-[30px]   cursor-pointer   whitespace-nowrap   rounded-[8px]   border-none
OK  bg-pub-page/[0.85]   px-[11px]   text-[12px]   font-medium   text-pub-txt
OK  z-[41]   absolute   bottom-[10px]   right-[10px]   gap-[6px]
FALTANDO: []
isca-ausente  ausente (ok)  bg-pub-isca-189-06 · min-h-[30px] · text-[97.5px] · z-[43]
isca-presente ok            flex · z-[40] · z-[39]
```
⚠️ `min-h-[30px]` como isca do **quase-igual** (`includes` casa prefixo e já mordeu
cinco vezes). ⚠️ `bg-pub-page/[0.85]` **é** `rgba(10,10,10,0.85)` e `text-pub-txt` **é**
`#F4F4F5`: nenhum hex cru na tela (TOK-15), **nenhum token novo**.

---

## §8 — A PROFUNDIDADE, por árvore com ÍNDICE ABSOLUTO

⚠️⚠️ O renderizador de **servidor** do React **não renderiza portal** — um teste do
composto veria **VAZIO e passaria POR OMISSÃO**. ➡️ Medi na **named export**
`RaizDoLightbox`, direto, com a lateral montada como **NÓ pronto**.

Com **3 imagens** na fixtura (2.783 bytes, 30 elementos):

| peça | índice ABSOLUTO | profundidade | veredicto |
|---|---|---|---|
| raiz (`z-[41]`) | 0 | 0 | — |
| **palco** | **1** | **1** | — |
| camada do zoom | 6 | 3 | — |
| marcador | 8 | 4 | **DENTRO** da camada do zoom ✅ |
| setas ← → | 19, 20 | 2, 2 | **FILHAS do palco** ✅ |
| **lateral** (`<aside>`) | **21** | **1** | **IRMÃ do palco** ✅, **não é descendente** ✅ |

⚠️⚠️ **ACHADO — e ele corrige o meu briefing:** o briefing (e a 189-05) dão a lateral no
**índice 13** como se fosse invariante. **Medi: o índice é função da FIXTURA.** Com
**1 imagem** eu medi **13**; com **3 imagens** medi **21** (3 miniaturas + 2 setas a
mais). ➡️ **O INVARIANTE é a profundidade e a irmandade** (`palco` e `lateral` na
profundidade **1**, a lateral **não** descendente do palco), nunca o número do índice —
*"âncora em número que muda é frágil"*. As duas medições concordam no invariante.

⚠️ Se isso mudasse, seria defeito: as setas medidas contra a **viewport** em vez do
palco, e os marcadores **parados** enquanto a imagem amplia.

---

## §9 — O Z-INDEX pela ORDEM, com DUAS ISCAS OPOSTAS

```
header    -> z = 30      (PublicadorHeader.jsx, INTOCADO)
barra     -> z = 35
backdrop  -> z = 39      (PainelDeRevisao.jsx)
painel    -> z = 40      (PainelDeRevisao.jsx)
lightbox  -> z = 41      (LightboxDaImagem.jsx)
modal     -> z = 42      (CascaDeModal.jsx)
toast     -> z = 45      (ToastDaFila.jsx)
ORDEM header < barra < backdrop < painel < lightbox < modal < toast crescente? SIM
isca-1 (lightbox 43, ACIMA do modal)            acusada? SIM
isca-2 (backdrop 36 / painel 37, ordem PRESERVADA) passa? SIM (mede a ORDEM, não a TABELA)
o 90 do protótipo está no código?                NAO (certo: z = 41)
```
⚠️ **Nunca lista larga de números** (o `45` é o número do popover, e o toast
legitimamente ficou em `z-[45]`). ⚠️ As duas âncoras do `camadas-189` em
`PainelDeRevisao.jsx` continuam casando **EXATAMENTE 1x** — asseverado no meu gate,
porque eu editei aquele arquivo.
⚠️ **`tests/js/publicador-header-zindex-ordem.test.js`: 7 / 7 / 0, INTOCADO** (provado
pela PI).

### §9.1 — PI(189-06): o território, e as TRÊS faces do limite

`pi-189-06.sh`, **55 alvos**, saída colada:

```
=== 1. test -e em cada alvo (anti-fantasma) ===   alvos: 55   fantasmas: NENHUM
=== 2. commits do escopo '(189-06)', RECORTADOS no %s ===
  c967c77b | f11af1ac | cf73f396 | 4a76d21b | 2a2d7fbd | b52b8edc | 33f91b64
  --- a FACE 1 (sobre-captura) medida: o que o --grep CRU devolveria ---
    … + d8b30fdf feat(189-03): o lightbox POR PORTAL em z-[41] …
  --- e o que o recorte no %s DESCARTA (so aparece no CORPO) ---
    DESCARTADO: d8b30fdf feat(189-03): …
=== 3/4. passada 1 e passada 2 — o MESMO conjunto (8 caminhos) ===
  ColunaDeFotos.jsx · ImagemGrandeDoPainel.jsx · PainelDeRevisao.jsx · Pages/.../Aprovacao.jsx
  contagens-189-06.txt · publicador-lightbox-painel-189.test.js
  publicador-painel-casca-188.test.js · publicador-painel-fotos-188.test.js
=== 5. interseção (caminhos tocados ∩ alvos) ===   INTERSEÇÃO: VAZIA
=== 6. isca (a) ===
  ACUSA '(189-05)' sobre LateralDoLightbox.jsx       -> a sonda ENXERGA
  ACUSA '(189-03)' sobre LightboxDaImagem.jsx        -> a sonda ENXERGA
  ACUSA '(188-08)' sobre contagens-188-08.txt        -> a sonda ENXERGA
=== 7. isca (b) === nenhum assunto de outra fase/sessão casou o escopo
PI(189-06): PASSOU
```

⚠️⚠️ **AS TRÊS FACES, as três MEDIDAS nesta wave:**

1. **SOBRE-CAPTURA — SE CONCRETIZOU DE NOVO.** `git log --grep='(189-06)'
   --fixed-strings` devolve **`d8b30fdf feat(189-03)`**, porque o **CORPO** dele cita
   `(189-06)` na **linha 67** (*"quem a constroi (189-06) passa o handler direto ao…"*).
   O recorte no **`%s`** o descarta corretamente. **Sem o recorte,
   `LightboxDaImagem.jsx` entraria na lista e a interseção NÃO seria vazia.**
2. **SUB-CAPTURA — medida À MÃO, por pathspec.** Commit que toca os meus arquivos sem o
   escopo no assunto **não apareceria**. Os vizinhos de cada um dos 8 caminhos:

| meu arquivo | o vizinho mais recente ANTES de mim |
|---|---|
| `ImagemGrandeDoPainel.jsx` | `6ea63331 feat(188-08)` |
| `ColunaDeFotos.jsx` | `6ea63331 feat(188-08)` |
| `PainelDeRevisao.jsx` | `31f1a760 feat(188-12)`, `9f5b05d7 feat(188-11)`, `5098d743 feat(188-10)`, `8c4a7c7f`/`ec4f805f feat(188-07)` |
| `Pages/.../Aprovacao.jsx` | **`31b73ed9 fix(publicador)`** (⚠️ **fora de fase** — o header colando no topo), `31f1a760`, `9f5b05d7`, `5098d743`, `cdaf03a9` |
| `publicador-painel-fotos-188.test.js` | `39541bd9 test(188-08)` |
| `publicador-painel-casca-188.test.js` | **`31b73ed9 fix(publicador)`**, `391d4787`, `6a5ebd19`, `8c4a7c7f`, `ec4f805f` |

   ⚠️ **`31b73ed9` é um commit SEM escopo de fase** que tocou **dois** dos meus
   arquivos — a face 2 em carne e osso: ele **jamais** apareceria por `--grep`.
3. **O AUTOR NÃO DISCRIMINA** — as três máquinas desta árvore commitam com o MESMO
   `user.name` (`MB.ECF-100376`). **Não conferi autor em lugar nenhum.**

⚠️ **`git diff --numstat -- <alvos>` "sair vazio" NÃO foi usado como gate** em parte
nenhuma: depois do commit ele compara a ÁRVORE e sai vazio SEMPRE.

### §9.2 — ⚠️ E um ACHADO sobre `numstat` em gates ALHEIOS, que NÃO é meu para consertar

`publicador-painel-preco-188.test.js` (`INTOCAVEIS`, `:1008`) e
`publicador-painel-rodape-188.test.js` (`REL_COLUNA_DE_FOTOS`) declaram
**`ColunaDeFotos.jsx` como INTOCÁVEL** e o provam por `git diff --numstat HEAD -- rel`.
➡️ **Eles REPROVAM enquanto a minha edição está NÃO COMMITADA e ficam VERDES depois do
commit** — isto é, eles medem **WIP**, não território. Medido no FIM: **48/48/0** e
**42/42/0**. ⚠️ São gates de fase **EM PRODUÇÃO**, fora do meu território (`189-REGUA.md`
§8 não me autoriza a editá-los) e o meu trabalho **não depende** deles. **NÃO
consertei — declaro.** É a mesma família do `git diff --stat` vazio por construção.

---

## §10 — O que **NÃO** é provado sem navegador, e a premissa TRAVADA como PROXY DE FONTE

⚠️⚠️ **Eu NÃO vi tela renderizada.** Não há navegador nesta máquina; o `mysqld` local
está **PARADO** (`tasklist | grep mysqld` → nenhum processo, medido); não há empresa com
`ml_token` (a rota dá **404**); `pub_aprovacao_ofertas` está **VAZIA** e **nada em
`app/` cria oferta** (quem cria é a **Fase 194**). ➡️ *"A tela mostra X"* aqui significa
**SEMPRE** *"o HTML gerado contém X"*.

**NÃO está provado por esta wave:**
- o `keydown` **disparar** no navegador (o `addEventListener` não é exercido);
- o `focus()` **mover o foco** de verdade (exercido com **STUB que conta chamadas**);
- o `ref` ser **anexado** ao botão (`renderToStaticMarkup` **não anexa ref**);
- o despacho da decisão chegar aos handlers em runtime;
- qualquer animação ou o portal **portar**.

**O que está provado:** as **funções puras** (`resolverAtalhoDoPainel`,
`contextoDoLightbox`, `indiceNoContexto`, `fecharLightboxDevolvendoFoco`,
`aoFecharOLightbox`, `aoClicarEmAmpliar`, `refDoBotao`) e o **HTML gerado**.

⚠️⚠️ **A PREMISSA INOBSERVÁVEL, TRAVADA POR IGUALDADE DE FONTE e DECLARADA COMO
PROXY:** o despacho (`onFecharLightbox()` / `onMoverLightbox(decisao.imagem)`) vive
dentro do `useEffect` e **só roda no navegador** — mutá-lo **SOBREVIVERIA**.
*Sobrevivente inobservável não se descarta: trava-se.* Entrou um bloco **declarado como
PROXY DE FONTE** (o mesmo molde que a 188-07 usou para a guarda de `tagName`), cobrando
a forma exata dos dois despachos, a **ORDEM** entre `fechar-lightbox` e
`fechar-painel`, o ouvinte **ÚNICO** (`addEventListener('keydown'` casa **1x**) e a sua
remoção — com isca nas duas direções. ⚠️ **E o docblock diz, por escrito, que proxy de
fonte NÃO prova comportamento**: quem prova é a conferência humana na conta **459**
(`PERGUNTA-VISUAL-189`, **ABERTA**). A **mutação extra (ix)** foi rodada só para provar
o proxy: **MORREU**.

⚠️ **As conferências visuais das Fases 187 E 188 seguem ABERTAS e separadas.** Nenhuma
é presumida feita.

---

## §11 — A tabela FID-01, por **SCRIPT**

**COMO MEDIDA: `SCRIPT`** (`.cjs` escrito pela ferramenta de escrita — ⚠️ **não por
heredoc**, ver §14.1). **Arquivo: `design_handoff_publicador/Aprovacao.dc.html`** —
⚠️ **cito o diretório**: há outra cópia (`new_layout_publicador/`) com conteúdo
diferente, e ela **NÃO EXISTE** nesta máquina.
`sha256 68624883ce35547e` · **1432 linhas por `wc -l` / 1433 campos por `split`**
(⚠️ a diferença é **MÉTODO DE MEDIDA**, não mudança de arquivo).

| id | elemento | linha | estilo MEDIDO | `cursor` | `white-space` | `text-overflow` |
|---|---|---|---|---|---|---|
| **D1** | wrapper do canto inferior direito | `:604` | `position:absolute;right:10px;bottom:10px;display:flex;gap:6px` | **—** | **—** | — |
| **D2** | "⤢ Ampliar" | `:607` | `white-space:nowrap;height:30px;padding:0 11px;border:none;border-radius:8px;background:rgba(10,10,10,0.85);color:#F4F4F5;font-size:12px;font-weight:500;cursor:pointer` | **pointer** | **nowrap** | — |

**ORDEM das propriedades de `:607`** (a 1ª e a última importam):
`white-space → height → padding → border → border-radius → background → color →
font-size → font-weight → cursor`. **1ª = `white-space` · última = `cursor`** — as duas
mais OMITIDAS desta milestone, e por isso em **coluna própria**.

**ORDEM dentro do wrapper `:604`:**
```
:605  condicao=P.sel.isRegen              rotulo="Gerando nova versão…"
:606  condicao=P.selActions.canRegen      rotulo="Gerar novamente com IA"   (Fase 192, AUSENTE)
:607  condicao=SEM CONDICAO               rotulo="⤢ Ampliar"
```

**§E — o comportamento, linha por linha:**
| id | linha | o que diz | confere? |
|---|---|---|---|
| E1 | `:1319` | abre com `i = Math.min(S.pImg, imgs.length-1)` e `lbZoom: 1` | **CONFERE** |
| E2 | `:1321` | `(lb.i + d + n) % n` — **DÁ A VOLTA** | **CONFERE** |
| E12 | `:1014` | `modal → lb → menu → panelId` | **CONFERE** |
| E13 | `:1016` | `S.lb && (ArrowRight||ArrowLeft)`, com `return` | **CONFERE** |
| E14 | `:1017` | ↑/↓ da fila — **NÃO contém `!S.lb`** | **CONFERE** |
| — | `:1015` | `if (typing) return;` **DEPOIS** do `Escape` | **CONFERE** |
| — | `:1018` | `Math.max(0, S.pImg…)` — o painel CLAMPA | **CONFERE** |
| — | `:975` | `lbX: 50, lbY: 50` | **CONFERE** |
| — | `:608` | **não** contém "Ampliar" (é `</span>` puro) | ponteiro da 188-01 **CONFIRMADO** |

**Divergências de VALOR: ZERO.** Iscas: `em(99999)` = `""` (leitura fora do arquivo é
vazia) e `em(606)` **não** contém `⤢ Ampliar` (é a Fase 192).

⚠️ **Uma divergência DE DESENHO, declarada:** no protótipo `:607` é **SEM CONDIÇÃO**;
aqui o botão nasce **só com `typeof onAmpliar === 'function'`**. Não é afrouxamento: é a
disciplina **D4** (*"botão sem destino não nasce"*), e o handler só é `null` quando não
há oferta no painel — caso em que não há painel nenhum. **O comportamento visível é o
mesmo; a diferença é que aqui a casca vazia também não nasce.**

---

## §12 — As DIVERGÊNCIAS declaradas desta wave

| # | o que o plano/briefing dizia | o que eu MEDI, e o que fiz |
|---|---|---|
| **D1** | *"o estado do lightbox vive **no painel**"* | A `ColunaDeFotos` (que hospeda o botão) chega ao painel como **NÓ PRONTO** pelo slot `colunaDeFotos`, montado na **página** → estado no painel **nunca alcançaria o botão**. ➡️ Estado na **PÁGINA**; o painel mantém o **ÚNICO** `keydown` (a razão que o plano deu está preservada) e as regras puras moram no módulo dele |
| **D2** | `focar: 'ampliar'` | `'ampliar'` minúsculo passaria o `casca-188:1035` **por sorte** (a regex é `/Ampliar/`, sem `i`). *"Silêncio que depende de UMA LETRA é sorte."* ➡️ `FOCO_NO_BOTAO_DE_ABRIR = 'botao-que-abriu'`, e há asserção `doesNotMatch(/ampliar/i)` |
| **D3** | *"índice **clampado** ou nada"* | Clampar para `0` abriria a **foto 1** quando a pessoa clicou na foto 4 — e no lightbox ela **aprova/reprova**. ➡️ Índice adverso **não abre nada** (o `0` continua válido) |
| **D4** | *"todos os casos do `casca-188` continuam passando **sem alteração**"* | **FALSO, medido**: o `ACOES` fechado reprovou o degrau. ➡️ Reaponte com veto (§6.1) |
| **D5** | A2: *"há **NOVE** gates de ausência de Ampliar"* | **São nove de `/Ampliar/`, mas há DOIS gates a mais que barram o que esta fase entrega** sem casar `/Ampliar/`: o `ACOES` do `casca-188` e o `ESTADO_LOCAL_DA_PAGINA` do `filtros-187` |
| **D6** | Briefing: *"lateral (**índice 13**)"* como invariante | **O índice é função da FIXTURA**: 13 com 1 imagem, **21** com 3. O invariante é a **profundidade 1 + irmandade** (§8) |
| **D7** | Critério de aceitação: `grep -c "Fase 189, plano 05"` ≥ 1 | ⚠️ **Engano de dígito no plano** — o dono do reaponte é o **06**. Escrevi a verdade ("Fase 189, plano 06") **e** registrei a errata no comentário, que por isso contém as duas strings. Medido: `grep -c "Fase 189, plano 06"` = **3** e `grep -c "Fase 189, plano 05"` = **1** (o critério literal sai **satisfeito sem mentira**) |
| **D8** | `189-FID01.md` §G: ROADMAP **L4730** e **L5038** | **Reconfirmado por `sed`: apodreceram DE NOVO.** Hoje: `**Requirements**: … MOD-06` em **L4752** (estável desde a 189-05) e `MOD-06 (1) \| Phase 190` em **L5132** (a 189-05 mediu L5081; o commit **`0607f5a9`** o empurrou +51). ⚠️ **NÃO editei o `189-FID01.md`** — CINCO gates o leem; apenas declaro |
| **D9** | INV-01/A7/INV-05/INV-06 por LINHA | **Deslocados pela MINHA edição** (`PainelDeRevisao.jsx` 862→1132, `ImagemGrandeDoPainel.jsx` 382→501, `fotos-188` 1591→1634, `casca-188` 1826→1880). A ancoragem certa é por **NOME**, e está em §3/§5. O gate `contrato-188-inventariado` **continua verde** (cobra `linha <= tamanho`) |

---

## §13 — O MANIFESTO, a BIJEÇÃO e as LISTAS

`tests/gates/contagens-189-06.txt` — **TRÊS ids**, nascido na tarefa 1 **antes** do
verify, com o `tests=` de `lightbox-no-painel` **REMEDIDO na tarefa 2** (11 → 22):

```
lightbox-no-painel   js  tests/js/publicador-lightbox-painel-189.test.js  ::  tests=22,22
coluna-de-fotos-188  js  tests/js/publicador-painel-fotos-188.test.js     ::  tests=32,28
vocabulario-tamanhos js  tests/js/publicador-vocabulario-novo.test.js     ::  tests=>=695
```
⚠️ `vocabulario-tamanhos` em **LINHA PRÓPRIA**, forma **`>=`**, N **medido hoje** (695),
e **com linha de id porque eu RODO o id** (está no `<verify>` da tarefa 2).

**Bijeção, por script, nos DOIS sentidos, com EXPANSOR DE ALTERNÂNCIA:**
```
ids no manifesto      : coluna-de-fotos-188, lightbox-no-painel, vocabulario-tamanhos
ids usados com 189-06 : coluna-de-fotos-188, lightbox-no-painel, vocabulario-tamanhos
ids de OUTRO manifesto: 188-08 coluna-de-fotos (x2) | 188-07 casca-do-painel (x2)
ORFAOS: []        SEM_ID: []
isca-orfao (com um id inventado) -> [id-inventado]  (a sonda ENXERGA)
nomes de teste de topo: 22     SEM o sufixo _189_06 no fim: []
isca: 'algo 189_06' casa? true    isca: 'algo 188_08' casa? false
HOMONIMO: colisoes: []   isca-colisao (trecho que EXISTE noutro arquivo) -> 1 achado (ENXERGA)
ids php: []  (nenhum — nao ha homonimo de --filter a conferir)
metodos por id: 1 arquivo cada (nenhum id com mais de TRES)
```
⚠️ **ZERO órfão, ZERO `SEM_ID`**, todos os 22 nomes terminam em **`_189_06`** (o
`--filter` do PHPUnit é regex **NÃO ANCORADO** e as Fases 186/187/188 estão **em
produção**), e **um id por arquivo de gate** (no modo `js` o harness roda o arquivo
inteiro). ⚠️ O expansor cobre a forma `test('…')`/`test("…")`/`` test(`…`) `` — a regex
`/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b)`, furo que já deu `SEM_ID=14`
**acusando trabalho correto**.

**As listas constantes que EU TOQUEI** (uma linha NOMEADA cada, pela via canônica que o
docblock de cada uma prescreve, **contagem do gate dono INALTERADA nas duas**):
- `ESTADO_LOCAL_DA_PAGINA` (`filtros-187`) — `+doLightbox`. `filtros-render` **29/29/0**;
- `ACOES_DEPOIS_DA_188_07` (`casca-188`) — lista **criada** por mim no reaponte.
  `casca-do-painel` **45/45/0**.

**As que eu NÃO toquei, com a razão** (também escritas no manifesto):
`ARQUIVOS_NOVOS` e `JSX_DEPOIS_DA_187` (**este plano não cria `.jsx`**) ·
`LIBERADOS_DEPOIS_DA_187` (nenhum tamanho novo; `text-[12px]` já liberado, o `18` foi
pelo 189-01 — `tokens-187` **17/17/0**) · `SLOTS_DE_NO_DA_187` e
`SLOTS_DE_NO_DEPOIS_DA_187` nos **dois** arquivos (a prop `lightbox` é **DADO**, e o
lightbox é montado como **componente**, não por `isValidElement` — `cabecalho-187`
**99/99/0**, `proximo-187` **76/76/0**) · `QUEM_USA_SHADOW_PUB_PAINEL`, `DA_FASE_180`,
`DO_PORTAO_DA_FASE_186` · `TAMANHOS_PERMITIDOS`.

⚠️ **ACHADO ABERTO, NÃO É MEU:** `contagens-186-02.txt:83` declara
`voc-07-gate-03 … tests=399,399` — contagem **EXATA** do mesmo arquivo que hoje tem
**695**. Já estava estourado **antes** desta fase (o 189-03 mediu 688; 688 ≠ 399 por
aritmética). É manifesto de fase **EM PRODUÇÃO**: **não consertei, não remedi**, e
**nada do meu trabalho depende dele**.

**`--autoteste`, colado:**
```
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.   (exit=0)
```

---

## §14 — Os CRITÉRIOS DE ACEITAÇÃO que eu RODEI, e o que eles mediram

⚠️ *"O que precisaria estar errado no mundo para este gate falhar?"* — **"Nada" é
ACHADO.** Rodei **todos** os critérios do meu plano antes de aceitá-los:

| critério do plano | rodado | veredicto |
|---|---|---|
| `grep -c "PERGUNTA-189-B: RESPONDIDA"` = 1 | **1** | OK (e `-C` = 1, `-D: ABERTA, NÃO BLOQUEIA` = 1) |
| `grep -cE "^export (function\|const) (contextoDoLightbox\|fecharLightboxDevolvendoFoco\|aoFecharOLightbox)\b"` = **3** | ****3**** | OK |
| `grep -c "temLightbox"` ≥ 2 | ****8**** | OK |
| `grep -c "Fase 189, plano 05"` ≥ 1 | ****1**** | ⚠️ **ERRATA DO PLANO** (D7): o dono é o **06**. Satisfeito **sem mentira** |
| `Aprovacao.jsx` sem `String(` e sem `localStorage`, lido SEM comentário | sim | **0 e 0** |
| `ImagemGrandeDoPainel.jsx` sem comentário contém `⤢ Ampliar` e `typeof onAmpliar === 'function'`, e **não** contém `Gerar novamente com IA` | sim | OK |
| `sh … 188-08 coluna-de-fotos` sai 0 com a contagem da 188 INALTERADA | sim | `exit=0`, **32/32/0** (`32,28`) |
| `sh … 188-07 casca-do-painel` sai 0 com a contagem que o manifesto já declara | sim | `exit=0`, **45/45/0** (`45,41`) — **depois** do reaponte de §6.1 |
| os ids do `<verify>` saem 0 | sim | **os cinco em `exit=0`** |

⚠️⚠️ **NENHUM critério do meu plano saiu "vazio por construção"** — e eu procurei: o
achado D7 da 189-05 (`grep -c "aprovacao/LateralDoLightbox.jsx"` dando **0 SEMPRE**
porque as listas usam template literals `${DIR_APROV}/…`) **não tem equivalente aqui**,
porque nenhum critério meu conta ocorrência de **caminho** em lista. O que eu achei foi
de outra família: um critério **com o número do plano errado** (D7).

### §14.1 — ⚠️⚠️ O FURO DE FERRAMENTA que eu mesmo cometi, e consertei

A primeira versão do probe de CSS foi escrita por **heredoc** `<<'FIM'`, e o heredoc
desta máquina **COMEU UMA BARRA INVERTIDA**: `'\\$1'` virou `'\$1'`, que em JS é
`'$1'` — então a função de escape **não escapava nada** e a sonda reportou **NOVE
classes "FALTANDO" que estão no CSS**. ➡️ Reescrito pela **ferramenta de escrita**, e
aí **0 faltando**. ⚠️ **Todo script desta wave (`mutar-189-06.sh`, `pi-189-06.sh`,
`medir-css-189-06.cjs`, `fid01-189-06.cjs`, `bijecao-189-06.cjs`,
`medir-profundidade-189-06.cjs`, `mutar-proxy-189-06.sh`) foi escrito pela ferramenta
de escrita** por essa razão, e o próprio `medir-css` carrega uma asserção que imprime
`seletor('h-[30px]')` para provar que o escape escapa.

---

## §15 — A FRONTEIRA desta wave, declarada

- ⚠️⚠️ **APROVAR ENFILEIRA, NÃO PUBLICA.** A escrita no Mercado Livre é da **Fase 193**
  (que, com o corte da 190/191, virou a **PRÓXIMA**). Este plano **não cria rota
  nenhuma** e não escreve em lugar nenhum.
- ⚠️ **As ações da lateral (`onAprovarFoto` / `onReprovar`) ficam `null` AQUI**, e a
  lateral **não desenha botão sem handler função** (D4). O fio delas, **usando o
  contexto que esta wave guarda**, é o **189-07** — que deve chamar
  `escritasDaColuna({empresaId, ofertaId, versao, onConflito})` (INV-03/A6) com o
  contexto **DO LIGHTBOX**.
- ⚠️ **"✦ Gerar novamente com IA" (AMPL-07) NÃO foi antecipado.** O dono é a **Fase
  192**, que **reancorada no painel virou a ÚLTIMA** da milestone (ordem: 189 → 193 →
  194 → 192). A ressalva de **AMPL-07 segue com a 192** — **dono que AINDA EXISTE**.
- ⚠️⚠️ **"Comparar lado a lado na revisão detalhada" (AMPL-05) está SEM DONO:** as Fases
  **190 e 191** foram **⛔ FORA DE ESCOPO em 20/10/2026** (decisão **20**, commit
  `0607f5a9`) — **canceladas, não adiadas**. ➡️ Escrevo **"sem dono (190/191 fora de
  escopo em 20/10/2026)"** e **nunca** *"fica para a Fase 190"*. ⚠️ E a decisão 20 cita
  o trabalho desta fase como prova de que o corte custou zero conserto (ROADMAP
  **L4731**: *"Fase 189 (plano 189-05), que é da revisão simples"*) — **não
  contradigo**.
- ⚠️ A linha **"Variação"** continua **não desenhada** (INV-10/A3, decidido pelo usuário
  em 19/10): a chave do payload é canônica e o rótulo humano está em território vetado.
  **Não reabri, e não vazei a chave canônica para a tela.**
- ⚠️ **`refLabel`** (protótipo `:1111`) continua **sem fonte** na prop do 189-02 — item
  **DEVOLVIDO pela 189-05**. O conserto seria 1 linha **naquele serviço**, e a régua §3
  manda **PARAR**. **Não toquei.**
- ⚠️ `publicador-lightbox-casca-189.test.js:1352` (`includes('createPortal')` mais largo
  que o invariante) continua **ABERTO**, **não consertado** — a decisão é do **189-08**.
- ⚠️ **PERGUNTA-189-D segue ABERTA.** Apliquei o **DEFAULT D1** (o painel FICA na oferta
  nova; `voltarParaAOferta` é sempre `null`) e **declarei no docblock e aqui** que é
  default com **alternativa local**. O **D2** é do **189-07**.

---

## §16 — O que o 189-07 e o 189-08 precisam saber

| wave | o que consumir, e a armadilha |
|---|---|
| **189-07** | **IMPORTE** de `PainelDeRevisao.jsx`: `contextoDoLightbox`, `indiceNoContexto`, `fecharLightboxDevolvendoFoco`, `aoFecharOLightbox`, `FOCO_NO_BOTAO_DE_ABRIR`, `idPositivoDoPainel`. ⚠️ O **CONTEXTO** (`{oferta_id, versao, ao_vivo, imagens, originais}`) está no estado **da PÁGINA** (`doLightbox.contexto`), e a derivação viva é `contextoDoLb` em `Aprovacao.jsx`. ➡️ Chame `escritasDaColuna({empresaId, ofertaId: contextoDoLb.oferta_id, versao: contextoDoLb.versao, onConflito})` — **nunca** `ofertaDoPainel`/`versaoDoPainel`. ⚠️ Os handlers `onAprovarFoto`/`onReprovar` da `<LateralDoLightbox>` estão `null` em `Aprovacao.jsx` (busque `onAprovarFoto={null}`). ⚠️⚠️ **ARMADILHA A2 segue valendo**: `casca-188:1035` varre a FONTE de `PainelDeRevisao.jsx` por `/Ampliar/` — se precisar de prop lá, **não** a chame `onAmpliar`. ⚠️ O **D2 da 189-D** é seu: o default escrito na régua é *"o lightbox FECHA depois da ação"* |
| **189-08** | ⚠️ Registre as **9 divergências da §12**, em especial **D4/D5** (os **DOIS gates além dos nove** que barravam o que esta fase entrega, e os dois reapontes com veto), **D6** (o índice 13 da lateral é função da FIXTURA, não invariante), **D7** (o critério com o número do plano errado) e **D8** (os ponteiros do `189-FID01.md` §G apodrecidos **de novo**: **L5038 → L5081 → L5132**). ⚠️ E os **3 itens devolvidos da §15** (`refLabel`, `casca-189:1352`, `contagens-186-02.txt:83`) e o **achado §9.2** (dois gates da 188 medem **WIP** e não território). ⚠️ `PERGUNTA-VISUAL-189` segue **ABERTA**, e as conferências visuais das Fases **187 e 188** também, **separadas** |

---

## §17 — Threat flags

| threat | disposição | como ficou |
|---|---|---|
| **T-189-40** (o reaponte afrouxando "Gerar novamente") | **mitigado** | separação provada por isca sintética nas DUAS direções, nos DOIS regimes (⚠️ **a mutação v achou o furo e ele foi consertado** — §7.1); contagem da 188 INALTERADA. Mutação **v** mata |
| **T-189-41** (`Escape` fechando painel e lightbox juntos) | **mitigado** | **UM só** `keydown` (`addEventListener` casa **1x**, asseverado) + o degrau; uma asserção por camada. Mutação **i** mata |
| **T-189-42** (↑/↓ trocando a oferta por baixo → versão errada) | **mitigado** | `contextoDoLightbox` com comparação **ESTRITA**, refresco só na MESMA oferta, fixtura que **DISCRIMINA** em três campos (A v3/4 imagens/live × B v7/2 imagens/não-live). Mutação **vii** mata |
| **T-189-43** (tecla/índice adverso derrubando a página) | **mitigado** | render adverso de **cada prop** do relé + a prop inteira, **e no CLIQUE**, com sonda de `[object Object]\|NaN\|undefined` e isca. `tagName` com `\|\| ''` preservado |
| **T-189-44** (estado de revisão em `localStorage`) | **mitigado** | o estado é memória do componente; `doesNotMatch(/localStorage\|sessionStorage/)` na página, e a entrada em `ESTADO_LOCAL_DA_PAGINA` declara por escrito que é GESTO |
| **T-189-SC** (npm installs) | **mitigado** | **este plano não instalou nada** — `package.json` e `package-lock.json` intocados (PI) |

**Nenhuma superfície de segurança nova:** este plano não cria rota, não escreve, não lê
banco e não chama o Mercado Livre.

---

## §18 — O QUE EU **NÃO** POSSO AFIRMAR

1. **Não vi tela renderizada** (§10). *"A tela mostra X"* = *"o HTML gerado contém X"*.
2. **Não medi a suíte PHP completa** — ela **não é medível** nesta máquina (Fatal de
   memória em processo **FILHO**; `-d memory_limit=-1` **não** resolve). Medi **pelo
   RECORTE**, e digo isso.
3. **Não deployei, não fiz push, não toquei a VPS, não chamei a API do ML.** Nenhuma
   migration (**esta fase não tem**).
4. **Não rodei `state.advance-plan`** e **não toquei** `.planning/ROADMAP.md`,
   `REQUIREMENTS-v27.md` nem `STATE.md` (GATE-07) — por instrução, e **provado pela
   PI**. Também **não editei** `189-REGUA.md`, `189-FID01.md`, `189-COBERTURA.md`,
   `189-CONTRATO-188.md` nem nenhum `189-NN-PLAN.md`.
5. **Não rodei `git stash` em forma nenhuma** (a árvore tem **27** stashes; apenas
   **contei**), nem `git checkout`, nem `git reset`, nem `git clean`, nem
   `git commit --amend`, nem `git add -A`. **Não criei worktree** e **não rodei `pint`**.
6. **Não provei** que o despacho do teclado, o `focus()` ou a anexação da `ref` funcionam
   no navegador — está **travado como PROXY DE FONTE e declarado como tal** (§10).

---

## Self-Check: PASSED

**Arquivos — `test -e`:**
```
FOUND  tests/js/publicador-lightbox-painel-189.test.js                   (1189 linhas, 22 casos)
FOUND  tests/gates/contagens-189-06.txt                                  (155 linhas, 3 ids)
FOUND  .../aprovacao/ImagemGrandeDoPainel.jsx                            (501 linhas, era 382)
FOUND  .../aprovacao/ColunaDeFotos.jsx                                   (383 linhas, era 357)
FOUND  .../aprovacao/PainelDeRevisao.jsx                                 (1132 linhas, era 862)
FOUND  resources/js/Pages/Mlb/Publicador/Aprovacao.jsx                   (1651 linhas, era 1493)
FOUND  tests/js/publicador-painel-fotos-188.test.js                      (1634 linhas, era 1591)
FOUND  tests/js/publicador-painel-casca-188.test.js                      (1880 linhas, era 1826)
FOUND  tests/js/publicador-fila-filtros-187.test.js                      (1155 linhas)
FOUND  .planning/phases/189-…/189-06-SUMMARY.md                          (este arquivo)
```

**Mínimo de linha do plano:** `publicador-lightbox-painel-189.test.js` **1189 >= 240** ✅

**Commits — `git log --oneline --all | grep -q`:**
```
FOUND  33f91b64  test(189-06): o RED do "⤢ Ampliar" — e a inversão do gate do 188-08 por REAPONTE COM VETO
FOUND  b52b8edc  feat(189-06): o "⤢ Ampliar" do painel — a condição do wrapper AFROUXADA, não um segundo wrapper
FOUND  2a2d7fbd  test(189-06): o RED do contexto próprio, do degrau do Escape e do foco — 9 de 11 vermelhos
FOUND  4a76d21b  feat(189-06): o degrau do Escape, o contexto PRÓPRIO do lightbox e o foco de volta
FOUND  cf73f396  fix(189-06): a mutacao v SOBREVIVEU — a ausencia da Fase 192 so era cobrada em UM dos dois regimes
FOUND  f11af1ac  fix(189-06): o DECIMO-PRIMEIRO gate — `ESTADO_LOCAL_DA_PAGINA` pela via canonica
FOUND  c967c77b  test(189-06): a premissa INOBSERVAVEL do despacho, travada como PROXY DE FONTE
```

**Critérios de aceitação, por comando:**
```
grep -c "PERGUNTA-189-B: RESPONDIDA" 189-REGUA.md                        -> 1          ✅
grep -c "PERGUNTA-189-C: RESPONDIDA" 189-REGUA.md                        -> 1          ✅
grep -c "PERGUNTA-189-D: ABERTA, NÃO BLOQUEIA" 189-REGUA.md              -> 1          ✅
grep -cE "^export (function|const) (contextoDoLightbox|fecharLightboxDevolvendoFoco|aoFecharOLightbox)\b"
                                              PainelDeRevisao.jsx        -> 3          ✅
grep -c "temLightbox" PainelDeRevisao.jsx                                -> 8  (>= 2)  ✅
grep -c "Fase 189, plano 06" publicador-painel-fotos-188.test.js         -> 3          ✅
grep -c "Fase 189, plano 05" publicador-painel-fotos-188.test.js         -> 1          ✅ (errata declarada, D7)
Aprovacao.jsx SEM comentário: String( -> 0 · localStorage -> 0 · sessionStorage -> 0   ✅
Aprovacao.jsx SEM comentário: alternarZoom -> 0 · ZOOM_AMPLIADO -> 0 · 2.5 -> 0        ✅
Aprovacao.jsx SEM comentário: isValidElement(lightbox) -> 0 (é DADO, não slot de NÓ)   ✅
ImagemGrandeDoPainel.jsx SEM comentário: "⤢ Ampliar" ✅ · "typeof onAmpliar === 'function'" ✅
                                          "Gerar novamente com IA" AUSENTE             ✅
```

**Gates (exit code REAL do harness, não de um `tail` na frente):**
```
189-06 lightbox-no-painel    -> exit=0   (22 / 22 / 0 / 0)
189-06 coluna-de-fotos-188   -> exit=0   (32 / 32 / 0 / 0, contagem 32,28 INALTERADA)
189-06 vocabulario-tamanhos  -> exit=0   (695, >=695)
188-07 casca-do-painel       -> exit=0   (45 / 45 / 0 / 0, contagem 45,41 INALTERADA)
188-08 coluna-de-fotos       -> exit=0   (32 / 32 / 0 / 0)
```
**Gates VIZINHOS verdes, nenhuma contagem alheia subida:** `189-01` baselines-medidas ·
contrato-188-inventariado · fid01-189-remedida · tokens-e-tamanhos-189 ·
vocabulario-tamanhos · `189-02` original-de-referencia · estado-img13-e-recorte ·
prop-lightbox-e-sem-http · `189-03` lightbox-casca · `189-04` camadas-e-css ·
`189-05` lateral-do-lightbox · `186-09` transversais (**12 / 258, INALTERADO**) — **todos
`exit=0`**. `publicador-header-zindex-ordem.test.js` **7 / 7 / 0, INTOCADO** ·
`estrutura-meus-anuncios.test.js` (D-11/D-22) **30 / 30 / 0, INTOCADO** · os **nove**
gates JS de ausência de "Ampliar" **todos verdes** (§5.1).

**Outros:** `--autoteste` **20 casos / 0 divergência** ✅ · **bijeção** `ORFAOS=[]`,
`SEM_ID=[]`, 22/22 nomes com sufixo `_189_06`, 0 homônimos ✅ · **PI(189-06)** 55 alvos,
0 fantasmas, interseção **VAZIA**, 3 iscas (a) acusam, isca (b) limpa, as **TRÊS faces
declaradas** e os vizinhos medidos **à mão** ✅ · **mutação 9/9 mortas, 0 sobreviventes**
(a **v** sobreviveu na 1ª rodada, foi **investigada** e o furo **consertado**) ✅ ·
`npm run build` **exit 0** (duas vezes, com `git diff HEAD -- resources/` conferido
**antes** de cada uma) com as **15 classes no CSS** e as 4 iscas de ausência em 0 ✅

**Suítes:** JS **5980 / 5979 / 1 / 0**, `exit=0` (a única falha é a herdada da **Fase
87**, atribuída por execução **ISOLADA**: 19 / 18 / 1) · PHP **RECORTE** **2595 / 23.878
assertions / 0 falhas** — re-medido no FIM e **IDÊNTICO à PARTIDA**.
⚠️ A suíte PHP **completa não é medível** nesta máquina.

**Entrada morta:** o recorte da tarefa 1 teve **3 entradas aparadas** (`PAINEL`,
`LIGHTBOX`, `FONTE_PAGINA`) e a tarefa 2 as trouxe de volta **junto com os casos que as
USAM** — *entrada morta é linha morta*. No estado final, todos os imports e todos os
`const` do gate são usados.
