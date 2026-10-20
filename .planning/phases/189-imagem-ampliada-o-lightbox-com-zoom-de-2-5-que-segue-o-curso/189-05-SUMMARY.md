---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 05
subsystem: publicador-aprovacao
tags: [lightbox, lateral, original-de-referencia, verificacoes, ordinal, travas, img-13, img-09]
requires:
  - "189-02 — a prop `lightbox.originais` (LightboxDoPainelService)"
  - "189-03 — `rotuloDoZoom` / `alternarZoom` / `MarcadoresDoLightbox` / `RaizDoLightbox` (slot `lateral`)"
  - "188-08 — `CABECALHO_DAS_VERIFICACOES`, `CLASSES_CABECALHO_VERIFICACOES`, `CLASSES_ORDINAL`, `CLASSES_GLIFO`, `CLASSES_VERIFICACOES`, `verificacoesDesenhaveis`, `fundoDoGlifo`, `REPROVAR`, `APROVAR_FOTO`, `ESTADO_PENDENTE`, `TEXTO_DA_COR`, `classeDaCor`, `CLASSES_PLACEHOLDER`, `CLASSES_IMAGEM`"
provides:
  - "`LateralDoLightbox.jsx` — a coluna 2 de 320px do lightbox, com 34 named exports + 1 default"
  - "`podeAgirNaFoto` — a trava estrita de Reprovar/Aprovar foto (IMG-09), função pura"
  - "`aoClicarEmFechar` / `aoClicarNoZoom` / `aoClicarEmReprovar` / `aoClicarEmAprovar` — os handlers puros"
affects:
  - "189-06 — monta a lateral como NÓ no slot `lateral` e liga `onFechar`/`onAlternarZoom`"
  - "189-07 — liga `onAprovarFoto`/`onReprovar` pelo `escreverNoPainel` com o contexto do lightbox"
  - "189-08 — as RESSALVAS do critério 4 e de AMPL-07, e a lacuna do `refLabel`"
tech-stack:
  added: []
  patterns:
    - "named exports para o gate medir sem depender do portal"
    - "igualdade de CONJUNTO de classes, nunca `includes` (casa prefixo)"
    - "árvore com ÍNDICE ABSOLUTO e PROFUNDIDADE, nunca `indexOf` de classes"
    - "render cruzado com a peça do 188-08 quando a classe não é importável crua"
key-files:
  created:
    - "resources/js/Components/Mlb/Publicador/aprovacao/LateralDoLightbox.jsx (686 linhas)"
    - "tests/js/publicador-lightbox-lateral-189.test.js (1439 linhas, 27 casos)"
    - "tests/gates/contagens-189-05.txt (2 ids)"
  modified:
    - "tests/js/publicador-vocabulario-novo.test.js (ARQUIVOS_NOVOS: 1 linha + o bloco de razão)"
    - "tests/js/publicador-fila-fechamento-187.test.js (JSX_DEPOIS_DA_187: 1 linha + a razão)"
decisions:
  - "a linha Variação NÃO é desenhada (INV-10/A3): o 188-08 não desenha nenhuma, e a chave do payload é canônica"
  - "o cabeçalho APOIO entra UMA vez (PERGUNTA-189-A (a)) — 4ª derrota do protótipo por decisão do usuário"
  - "o ordinal é elemento próprio, LIDO do servidor — divergência PRESCRITA por AMPL-06/critério 2"
  - "`aoClicarNoZoom` entrega o ZOOM NOVO por `alternarZoom` do 189-03, em vez de `onAlternarZoom()` sem argumento"
  - "o placeholder da caixa da original com arquivo ausente reusa `TITULO_DA_ORIGINAL` — a prop não traz o `refLabel` do protótipo"
metrics:
  duration: "~3h"
  completed: "2026-10-20"
---

# Phase 189 Plan 05: A lateral de 320px do lightbox — Summary

A coluna 2 do grid `minmax(0,1fr) 320px` do lightbox num `.jsx` novo de 686 linhas com **34 named
exports + 1 default** (medido: 35 linhas `^export `, uma delas o `default`; a lista CONSTANTE do gate
cobra **23** deles, o recorte que o `<action>` do plano prescreve): "Fechar · Esc", os metadados (**Origem · Posição · Estado** — a Variação cai por lacuna de
dado medida), a **original de referência** ou o **estado IMG-13 lido do servidor** com ícone **e**
texto, as **verificações numeradas com o MESMO ordinal dos marcadores do palco** (provado por render
cruzado), o botão **"Ampliar 2,5×" / "Reduzir"** com os rótulos importados do 189-03, e
**Reprovar / Aprovar foto** atrás de uma trava estrita — 27 casos de gate, **7 mutações, 0
sobreviventes**.

---

## §1 — A PARTIDA, e a árvore durante a wave

**PARTIDA capturada na PRIMEIRA ação: `0e48f1c7`** (`docs(189-04): a SUMMARY das camadas e do CSS
construido — o sobrevivente que virou caso`, `MB.ECF-100376`, 2026-10-20 13:51:57 −0300).

| commit | assunto |
|---|---|
| `83ff4638` | `test(189-05)` — o RED da lateral de 320px: 18 casos, 12 vermelhos |
| `a979888d` | `feat(189-05)` — a casca, os metadados e a original de referência |
| `6274b0fd` | `test(189-05)` — o RED das verificações numeradas, do zoom e das travas |
| `15e72d3c` | `feat(189-05)` — as verificações, o zoom e as travas |
| `e9574d1d` | `docs(189-05)` — a Fase 190 ficou FORA DE ESCOPO durante a wave |

⚠️ **GATE SEQUENCE do TDD, lido do `git log`:** `test(189-05)` → `feat(189-05)` → `test(189-05)` →
`feat(189-05)`. **As duas tarefas seguiram o ciclo RED/GREEN**, com RED **MEDIDO e DISCRIMINANTE** nas
duas. Não houve `refactor`.

⚠️⚠️ **OUTRA SESSÃO COMMITOU DURANTE A WAVE** — `0607f5a9`, 14:29:57, `docs(v27): a revisao detalhada
sai do escopo`. Ela tocou **só** `.planning/ROADMAP.md` e `.planning/STATE.md` (conferido por
`git show --name-only`); **nenhum arquivo meu**. O efeito dela sobre o meu entregável está na §12.

---

## §2 — AS BASELINES RE-MEDIDAS, pelo RECORTE

### ⚠️⚠️ A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA

`artisan test` **aborta com Fatal de memória** em `Illuminate\Http\Testing\FileFactory.php:87` a ~6%
de 10.653 testes, e **`-d memory_limit=-1` NÃO resolve** (o estouro é em processo **FILHO**).
➡️ **Medi pelo RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`) e **digo isso por
escrito**, como as waves anteriores.

| suíte | PARTIDA (`0e48f1c7`) | FIM | leitura |
|---|---|---|---|
| **JS inteira** | **5924 / 5923 pass / 1 fail / 0 skipped** | **5958 / 5957 / 1 / 0** | **+34 = 27 (o meu gate) + 7 (os 7 sub-testes por `.jsx` novo do `vocabulario-tamanhos`)**. A conta FECHA exatamente |
| **PHP RECORTE** | **2595 passed (23878 assertions) / 0 falhas** | **2595 passed (23878 assertions) / 0 falhas** | **IDÊNTICO**, como tem de ser: este plano **não toca um único arquivo PHP**. Medido nas duas pontas (754 s no fim, 1004 s na partida) |
| **MANIFEST** | — | **498 entradas** | lido pelo **JSON** (`public/build/manifest.json`), nunca por glob de hash |
| **protótipo** | — | **1432 linhas, `sha256 68624883ce35547e`** | **não se moveu** |

⚠️ **`1432` × `1433` é MÉTODO DE MEDIDA, não divergência:** `wc -l` dá **1432** e `split(/\r?\n/)` dá
**1433** (a linha final vazia). Medi os dois no mesmo script e declaro os dois.

### As falhas conhecidas, NOMEADAS e atribuídas por execução ISOLADA — nenhuma corrigida

| onde | falha | atribuição por execução ISOLADA | ação |
|---|---|---|---|
| JS | `tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce recolhido (é o grupo que mais infla)"* | **19 / 18 pass / 1 fail / 0 skipped** rodando o arquivo **SOZINHO** → é a pré-existente da **Fase 87** | **NÃO corrigida.** É a ÚNICA falha JS aceitável, e ela é a mesma na partida e no fim |
| PHP recorte | **nenhuma** | — | — |

⚠️ **Falhas FORA do recorte** (`CalcularFaixaTest` 9 · `CompanyServiceTypeTest::test_service_type_aceita_polo` 1 ·
`Phase39` 2 · os dois de `tests/Feature/Publicador/CockpitDaContaTest.php`, que **OSCILAM COM A HORA
DO DIA**): **não medidas aqui**, porque estão fora do recorte, **nenhuma é desta fase**, e **não criei
`REF_PHP_FALHAS`**. ⚠️ Asserções do PHP **não são invariante** — comparei **TESTES e FALHAS**.

---

## §3 — ⚠️⚠️ A PROP `lightbox.originais` LIDA NAS TRÊS FORMAS

**LEITURA, não remontagem.** Conferi a `189-02-SUMMARY.md` §4 e **reconferi o serviço no disco** por
`grep` (`LightboxDoPainelService.php:254`-`:273`): bate **byte a byte** com o `189-05-PLAN.md:76`-`:80`.

| forma | quando | o que a lateral desenha | caso de gate |
|---|---|---|---|
| **`null`** | upload (`de_ia !== true`), ou IA sem entrada | **o bloco §C9 NÃO existe** | LAT-189-11 |
| **`{disponivel: true, url_arquivo: string, quantas: int}`** | IA com vínculo e com arquivo | `<img object-contain>` na caixa §C11, `src` = a URL da **ORIGINAL** | LAT-189-8 |
| **`{disponivel: true, url_arquivo: null, quantas: int}`** | **caso REAL** (o `caminho` é nulo no banco) | o **MESMO** estado de arquivo ausente do painel (`CLASSES_PLACEHOLDER`, INV-11, **IMPORTADO**) — **nunca `<img src="">`** | LAT-189-9 |
| **`{disponivel: false, estado: 'sem_original_de_referencia', rotulo, apoio, icone: 'image-off', url_arquivo: null}`** | IA sem vínculo utilizável (IMG-13) | ícone `ImageOff` + `rotulo` + `apoio`, **LIDOS da prop** | LAT-189-10 |

⚠️ **`url_arquivo = null` é caso REAL e a tela mostra o ESTADO, não imagem quebrada** — provado por
`assert.equal(imagensDo(html).length, 0)` **e** `doesNotMatch(/src="/)`.
⚠️ **T-189-32 provado:** o `src` é **diferente** da `url_arquivo` da imagem de IA, com **isca cega**
(a URL da IA tem de EXISTIR na fixture, senão "diferente" não prova nada — foi o conserto da isca do
189-02, reusado).
⚠️ **O texto do IMG-13 NÃO é reescrito pela tela:** o literal
`Original de referência não disponível` **não aparece no `.jsx` lido sem comentário** (ele está no
docblock, de propósito — isca do leitor) e **aparece no HTML** vindo da prop.
⚠️ **O `quantas` não vira texto de tela** (ele é para a comparação, que saiu de escopo): asserção
`doesNotMatch(texto(html), /\b2\b/)`.
⚠️ **Imagem Original não tem o bloco** — IMG-13 fala de **criativo** gerado sem vínculo, e dizer
"original não disponível" sobre a foto que **É** a original seria **mentira**. Declarado no docblock.

### O render ADVERSO de cada forma

| eixo | cobertura medida |
|---|---|
| campos da `imagem` | **16 campos × 19 valores adversos** (`{toString:null}`, `{valueOf:null}`, `Object.create(null)`, `NaN`, `Infinity`, `-0`, `[]`, `[1,2]`, `{a:1}`, `'   '`, …) |
| campos da `original` | **7 campos × 19 valores × as 3 formas base** |
| props inteiras | `imagem`, `original`, `aoVivo`, `zoom` cada uma com os 19 |
| `imagem` nula / ausente | `render(default, {})` e `{imagem: null}` → **`''`** |
| `icone` adverso | 10 chaves, incluindo `{toString:null}`, `'constructor'` e `'__proto__'` → **sem ícone, COM o texto** |
| no **CLIQUE** | **12 imagens adversas × 8 handlers × 2 ações**, mais o Fechar e o zoom |

Em todos: `assert.doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`, com **sonda não-cega**
(`assert.match('<span>[object Object]</span>', …)`). **Nenhum `String(` e nenhum `JSON.stringify` na
mensagem** — e há gate da 187-04 proibindo `String(` nesta região, que continua verde.

---

## §4 — ⚠️⚠️ OS SÍMBOLOS IMPORTADOS DO `LightboxDaImagem.jsx`, e a prova de que não há segunda cópia

| símbolo | onde é consumido | prova |
|---|---|---|
| `rotuloDoZoom` | **no componente** (o texto do botão §C14) | `FONTE_LATERAL.includes('rotuloDoZoom')` + `match(/from '\.\/LightboxDaImagem'/)` |
| `alternarZoom` | **no componente** (`aoClicarNoZoom` entrega o zoom NOVO) | idem + `deepEqual(vistos, [ZOOM_AMPLIADO, ZOOM_NORMAL])` |
| `ROTULO_AMPLIAR` | **no gate** | `comProps({zoom:1}).includes(LIGHTBOX.ROTULO_AMPLIAR)` |
| `ROTULO_REDUZIR` | **no gate** | `comProps({zoom:2.5}).includes(LIGHTBOX.ROTULO_REDUZIR)` |

⚠️⚠️ **A PROVA DE QUE NÃO HÁ SEGUNDA CÓPIA, medida:** lido **sem comentário**, o `.jsx` **não contém
`Ampliar 2,5×` nem `Reduzir`** (conferido por comando: as duas linhas `AUSENTE ... sim OK`). Os dois
literais existem **numa fonte só**, o `LightboxDaImagem.jsx`, e chegam à tela por `rotuloDoZoom(zoom)`.

### ⚠️ DIVERGÊNCIA DECLARADA do briefing: DOIS no componente, QUATRO no gate

O briefing manda **importar os quatro** no `.jsx`. **Importar `ROTULO_AMPLIAR`/`ROTULO_REDUZIR` ali
seria ENTRADA MORTA** — `rotuloDoZoom` já resolve os dois, e *"entrada morta é linha morta"*. ➡️ O
componente importa os **dois vivos**; o **gate** importa os **quatro** e é lá que a prova mora (ele
compara o HTML com a constante do 189-03 **e** cobra a ausência do literal na minha fonte). Isto é
**mais forte** que importar constantes sem uso, e foi medido: **`IMPORTS=21 · IMPORTS_MORTOS=[]`**,
com isca (um nome inventado seria acusado).

⚠️ **`onAlternarZoom` NÃO foi acrescentada à assinatura do `LightboxDaImagem`** (divergência D3 do
189-03, respeitada): a lateral chega como **nó pronto** e recebe o handler direto de quem a monta.
⚠️ **A FONTE do `LightboxDaImagem.jsx` NÃO foi tocada** — o `tests=27` declarado no manifesto do
189-03 segue válido, e o gate dele foi re-rodado: **27 / 27 / 0**.

### E os 10 símbolos importados do 188-08 (a REGRA ÚNICA DE REUSO, régua §2)

`CABECALHO_DAS_VERIFICACOES`, `CLASSES_CABECALHO_VERIFICACOES`, `CLASSES_ORDINAL`, `CLASSES_GLIFO`,
`CLASSES_VERIFICACOES`, `verificacoesDesenhaveis`, `fundoDoGlifo`, `REPROVAR`, `APROVAR_FOTO`,
`ESTADO_PENDENTE` (de `BlocoDaImagemSelecionada`) · `TEXTO_DA_COR`, `classeDaCor`,
`CLASSES_PLACEHOLDER`, `CLASSES_IMAGEM`, `objetoDoPainel`, `textoDoPainel` (de
`ImagemGrandeDoPainel`). **Nenhum arquivo do 188 foi editado** (PI, §9).

⚠️⚠️ **A ÚNICA classe NÃO importada, e a razão MEDIDA:** `CLASSES_ITEM_DA_VERIFICACAO`. O item do
**PAINEL** (`:633`) **tem** `align-items:flex-start` e o do **LIGHTBOX** (`:760#1`) **NÃO** —
re-medido por `sed` nos dois lados nesta wave. Reusar `CLASSES_ITEM` cru traria o `items-start` de
volta. ➡️ Desenhada local, com gate provando que as **duas continuam DIFERENTES**
(`notDeepEqual`) e que a do painel **continua tendo** `items-start` (se ela perder, a diferença medida
deixa de existir e o gate avisa).

---

## §5 — O SLOT `lateral` ACEITA SÓ `isValidElement` — medido

Renderizei `RaizDoLightbox` (189-03) com **dez** valores no slot `lateral`:

```
objeto comum               -> IGNORADO (raiz vazia)
array                      -> IGNORADO (raiz vazia)
string                     -> IGNORADO (raiz vazia)
numero                     -> IGNORADO (raiz vazia)
nulo                       -> IGNORADO (raiz vazia)
ausente                    -> IGNORADO (raiz vazia)
funcao (nao chamada)       -> IGNORADO (raiz vazia)
{toString:null}            -> IGNORADO (raiz vazia)
booleano                   -> IGNORADO (raiz vazia)
objeto que PARECE elemento -> IGNORADO (raiz vazia)   <- {type:'div', props:{}}
ISCA: elemento de verdade  -> ACEITO (a sonda nao e cega)
```

⚠️ **O caso que importa é o penúltimo:** `{type:'div', props:{}}` **parece** um elemento e é
**recusado** — `isValidElement` e nada mais. **Prop de NÓ chegando objeto comum MATA A PÁGINA**
(*"Objects are not valid as a React child"*), e aqui ela não chega. ➡️ **A lateral é passada como NÓ
PRONTO** (`React.createElement(LATERAL.default, {...})`), nunca como função.

---

## §6 — ⚠️⚠️ A PROFUNDIDADE DA LATERAL DE 320px, por árvore com ÍNDICE ABSOLUTO

Render real de `RaizDoLightbox({lateral: <nó da lateral>}, <PalcoDoLightbox>)`:

```
ÍNDICE | PROF | TAG    | CLASSES
     0 |    0 | div    | fixed inset-0 z-[41] grid grid-cols-[minmax(0,1fr)_320px] bg-[rgba(5,5,5,0.94)] font-p…
     1 |    1 | div    | relative flex flex-col items-center justify-center gap-[14px] px-[70px] py-[24px]   <- PALCO
     5 |    2 | div    | relative aspect-square w-[min(78vh,100%)] … (a caixa da imagem)
     6 |    3 | div    | absolute inset-0                                   <- A CAMADA DO ZOOM
     8 |    4 | span   | pointer-events-none absolute grid h-[26px] …       <- O MARCADOR, FILHO DA CAMADA
     9 |    2 | div    | flex gap-[6px]                                     <- a faixa, IRMÃ da caixa
    13 |    1 | aside  | flex flex-col gap-[14px] overflow-y-auto border-l … <- A LATERAL
    14 |    2 | div    | flex justify-end
    15 |    3 | button | h-[30px] … (o "Fechar · Esc")
    16 |    2 | div    | grid grid-cols-[110px_minmax(0,1fr)] gap-y-[9px] text-[12.5px]
    17 |    3 | span   | text-pub-txt-4          <- Origem
    18 |    3 | span   | font-semibold           <- "Gerada por IA"
    19 |    3 | span   | text-pub-txt-4          <- Posição
    20 |    3 | span   | (sem classe)            <- "#1 · principal"  (§H2: herda da grade)
    21 |    3 | span   | text-pub-txt-4          <- Estado
    22 |    3 | span   | font-semibold text-pub-warn-txt   <- "A conferir", a cor DO SELO do 188-08
    23 |    2 | div    | flex flex-col gap-[8px]           <- o bloco da original
    25 |    3 | div    | relative aspect-square … rounded-[8px]  <- a caixa §C11
```

| medida | resultado |
|---|---|
| RAIZ | índice **0**, prof **0** |
| PALCO | índice **1**, prof **1** |
| **LATERAL** | índice **13**, prof **1** |
| PALCO e LATERAL são **IRMÃOS** (mesma prof, filhos diretos da raiz)? | **true** |
| a LATERAL vem **DEPOIS** do palco na ordem do DOM (coluna 2 do grid)? | **true** |
| a LATERAL **NÃO** é descendente do palco? | **true** |
| o MARCADOR é **FILHO** da camada do zoom (prof 4 × 3)? | **true** (a profundidade do 189-03 **não mudou**) |

⚠️ **Por que isto importa, e por que nenhum teste de aparência pegaria:** se a lateral nascesse
**dentro** do palco, ela entraria na coluna 1 do grid (`minmax(0,1fr)`), sob um
`flex-direction:column` com `padding:24px 70px` — **o HTML teria as mesmas classes e a tela sairia
errada**. A ordem no DOM também é invariante: num `grid-template-columns` de duas colunas, trocar a
ordem dos filhos **troca as colunas**.
⚠️ **As SETAS saíram vazias nesta medição e é esperado:** com **uma** imagem na fixture,
`SetasDoLightbox` devolve `null` (não há para onde ir). A profundidade delas é do **189-03**, que a
provou (`filhas do palco`), e eu **não a toquei**.

---

## §7 — O Z-INDEX PELA ORDEM, com DUAS ISCAS OPOSTAS

**A cadeia, re-medida no disco:** `header 30 < barra 35 < backdrop 39 < painel 40 < LIGHTBOX 41 <
modal 42 < toast 45` — **estritamente crescente: `true`**; e o lightbox está **ENTRE** o painel (40) e
a casca (42): `true`.

⚠️ **O dono da prova pela ORDEM é o 189-04** (`publicador-lightbox-camadas-189.test.js`, mapa
`DO_PROTOTIPO` por peça). **Eu o re-rodei e está VERDE** (`189-04 camadas-e-css` → PASSOU) e **não
toquei nenhuma lista dele** (§9). ⚠️ **NÃO escrevi lista larga de números** — a 187-09 mediu que `45`
é o número do **popover** e o toast legitimamente ficou em `z-[45]`.

**O invariante DESTE plano é outro:** *"a lateral **não declara camada própria**"*, porque um `z-`
nela criaria **contexto de empilhamento DENTRO do lightbox** — e aí qualquer coisa que a lateral
abrisse ficaria presa no 41.

| isca | direção | resultado |
|---|---|---|
| (a) fonte com `z-[43]` no **CÓDIGO** | a sonda tem de **ACUSAR** | **ACUSOU** (a sonda enxerga) |
| (b) `z-[41]` só em **COMENTÁRIO** | a sonda **não** pode acusar | **NÃO ACUSOU** (senão reprovaria o próprio conserto) |
| (c) a LATERAL declara `z-` própria? | — | **NÃO** (`[]` medido na fonte) |
| (d) o `90` do protótipo aparece em alguma das 7 fontes? | — | **NÃO** — ele foi **RECUSADO por peça** |

⚠️ **`tests/js/publicador-header-zindex-ordem.test.js`: 7 / 7 pass / 0 fail / 0 skipped, e INTOCADO**
(`git log 0e48f1c7..HEAD --` devolveu **vazio**).

---

## §8 — ⚠️ O QUE **NÃO SE PROVA** SEM NAVEGADOR, DECLARADO

Não há navegador nesta máquina; o `mysqld` local está **parado**; o banco não tem empresa com
`ml_token` (a rota dá **404**); `pub_aprovacao_ofertas` está **vazia**; e **a fila só ganha oferta na
Fase 194**. ➡️ **Em toda esta SUMMARY, *"a tela mostra X"* significa *"o HTML gerado contém X"*.**

**Não provado, e declarado no docblock e aqui:**

1. que o **clique** nos quatro botões dispare de fato (o clique é exercido chamando o **handler
   exportado** — `renderToStaticMarkup` **não dispara evento**);
2. que a `<img>` da original **carregue** (e, com ela, que o efeito do `EditorEmUso` aconteça);
3. que a lateral **role** (`overflow-y-auto`) e que o `mt-auto` empurre as ações para o pé;
4. que a **largura de 320px** se comporte em viewport estreita;
5. que o ícone `ImageOff` **desenhe** no navegador (o render só prova que sai um `<svg>`).

### A premissa INOBSERVÁVEL, TRAVADA como PROXY DE FONTE

O que eu **não** consigo observar sem navegador é *"a lateral não cria contexto de empilhamento"*.
➡️ Ela está travada por **igualdade de FONTE** (`doesNotMatch(FONTE_LATERAL, /\bz-\[/)`), e o teste
**declara por escrito** que isso é **PROXY DE FONTE** e **NÃO prova comportamento de empilhamento** —
a prova de verdade é a conferência visual da conta **459** (plano 189-08).
⚠️ **As conferências visuais das Fases 187 E 188 seguem ABERTAS e separadas.** Nenhuma é presumida
feita, e esta wave não as toca.

---

## §9 — A FID-01 §C RE-MEDIDA **POR SCRIPT**, com o diretório citado

**COMO MEDIDA: por `SCRIPT`** (`fid01-c-189-05.cjs`, escrito por **ferramenta** no scratchpad — nunca
`node -e`, nunca heredoc: o heredoc do shell **come a barra invertida**, e nesta mesma sessão um
`cat >> arquivo <<'EOF'` morreu com *"unexpected EOF"*, o que me fez trocar por `Write` + `cat`).
**Arquivo: `design_handoff_publicador/Aprovacao.dc.html`** — ⚠️ **cito o diretório**:
`new_layout_publicador/Aprovacao.dc.html` **NÃO EXISTE** (medido pelo 189-01).

**Medidas globais da faixa `744`-`770`:** `ATRIBUTOS_NA_FAIXA=22` em `LINHAS_COM_STYLE=17` ·
`FONT_SIZES = 9.5 12 12.5 13` · `RAIOS = 7px 8px 50%` · `PESOS = 500 600 800`.

```
awk cursor:            -> 745 756 762 763 766 767
awk white-space:nowrap -> 745 756 762 763 766 767
awk text-overflow      -> (nenhuma)
```

### As 17 linhas da §C, com `cursor` / `white-space` / `text-overflow` em COLUNAS PRÓPRIAS

| id | ponteiro | 1ª propriedade | cursor | white-space | text-overflow | confere? |
|---|---|---|---|---|---|---|
| C1 | `:744` | `background:#0D0D0D` | — | — | — | **OK** |
| C2 | `:745#1` | `display:flex` | — | — | — | **OK** |
| C3 | `:745#2` | **`white-space:nowrap`** | **pointer** | **nowrap** | — | **OK** |
| C4 | `:746` | `display:grid` | — | — | — | **OK** |
| C5 | `:747#1` / `:747#2` | `color:#71717A` / `font-weight:600` | — | — | — | **OK** |
| C6 | `:748` | `color:#71717A` | — | — | — | **OK** |
| C7 | `:749` | `color:#71717A` | — | — | — | **OK** (e **NÃO desenhada** — §12 D1) |
| C8 | `:750#1` / `:750#2` | `color:#71717A` / `color:{{ LB.stColor }}` | — | — | — | **OK** |
| C9 | `:753` | `display:flex` | — | — | — | **OK** |
| C10 | `:754` | `font-size:12px` | — | — | — | **OK** |
| C11 | `:755` | `position:relative` | — | — | — | **OK** |
| C12 | `:760#1` | `display:flex` | — | — | — | **OK** — e **SEM** `items-start` |
| C13 | `:760#2` | `width:16px` | — | — | — | **OK** |
| C14 | `:763` | **`white-space:nowrap`** | **pointer** | **nowrap** | — | **OK** |
| C15 | `:765` | `display:flex` | — | — | — | **OK** |
| C16 | `:766` | **`white-space:nowrap`** | **pointer** | **nowrap** | — | **OK** |
| C17 | `:767` | **`white-space:nowrap`** | **pointer** | **nowrap** | — | **OK** |

**Fora da faixa, conferidos por `sed`:** `:369` = o cabeçalho APOIO **byte a byte**
(`font-size:11px;font-weight:600;letter-spacing:0.08em;color:#71717A`) · `:633` **TEM**
`align-items:flex-start` · `:760` **NÃO tem** · `:755` tem `fit="contain"` · `:756` = "Comparar lado a
lado na revisão detalhada" · `:762` = "✦ Gerar novamente com IA" · `:763` = `{{ LB.zoomLabel }}`.

**As iscas do script, nas duas direções:** `ATRIBUTOS_NA_FAIXA >= 10` OK · `:744` presente OK · `:767`
presente OK · **`L9999` ausente** OK · **nenhum `text-overflow`** (bate com a §A) OK · `cursor` em
`:745` OK · **NÃO há `cursor` em `:744`** OK.

➡️ **§C: ZERO divergência por VALOR, por OMISSÃO ou por REFERÊNCIA — as 17 linhas conferem.** As
divergências desta wave são **de NOTAÇÃO e de ESCOPO**, e estão na §12.

---

## §10 — A ORDEM MECÂNICA: as DUAS escriturações e o GATE-03

⚠️ **Na MESMA tarefa que criou o `.jsx`** (a tarefa 1, commit `83ff4638`):

| lista | arquivo | o que acontece se faltar |
|---|---|---|
| **`ARQUIVOS_NOVOS`** | `tests/js/publicador-vocabulario-novo.test.js:601` | o GATE-03 passaria **POR OMISSÃO** (VOC-07): o arquivo ficaria sem conferência contra `text-sm`, `ecf-*`, hex cru e `font-display`; e, como `aprovacao/` está em `DIRETORIOS_FECHADOS`, o `deepEqual(noDisco, declarados)` **REPROVA na hora** |
| **`JSX_DEPOIS_DA_187`** | `tests/js/publicador-fila-fechamento-187.test.js` | o `deepEqual` do fechamento-187 **REPROVA NO MEIO DO PLANO** — ele varre `readdirSync(aprovacao/)` e lê as TRÊS listas |

⚠️⚠️ **SÃO DUAS, E É DELIBERADO. NÃO criei a TERCEIRA** (um conserto anterior nesta milestone a
RECUSOU). Gate próprio: **LAT-189-14**, contando **EXATAMENTE 1** em cada, com sonda não-cega nos dois
sentidos (o vizinho `LightboxDaImagem.jsx` está nos dois → 1; um nome inventado → 0).

**GATE-03 VERDE: `vocabulario-tamanhos` 695 / 695 pass / 0 fail / 0 skipped.** E
`fechamento-arquivos-e-manifestos` (187-11) **6 / 6 / 0**.

### O `vocabulario-tamanhos` — COM linha de id, porque EU O RODO

`vocabulario-tamanhos  js  …  ::  tests=>=695`, em **LINHA PRÓPRIA**, forma **`>=`**, **N MEDIDO
agora**: o 189-03 declarou `>=688`, aquele arquivo faz **7 sub-testes por `.jsx` varrido** e este
plano cria **1** `.jsx` → `688 + 7 = 695`. ⚠️ **Ele está no `<verify>` da tarefa 1**, então **tem**
linha de id — a forma "medição SEM linha de id" é para quem **não roda** o id (id fora de todo
`<verify>` é **ÓRFÃO**, e gate sobre zero casos não prova nada).

### ⚠️ Nada a liberar em `LIBERADOS_DEPOIS_DA_187`

Os tamanhos da faixa `744`-`770` são **9.5 · 12 · 12.5 · 13**, mais **11.5** (o apoio do §H4, escolhido
dentro da lista) e **11** (o cabeçalho, **IMPORTADO**). **Os seis já estão em
`TAMANHOS_PERMITIDOS`.** ➡️ **Nenhum tamanho novo, nenhuma linha acrescentada, e o `18` do 189-01
intocado.**

---

## §11 — AS LISTAS CONSTANTES: as que TOQUEI e as que NÃO, com a razão

### As DUAS que toquei

| lista | mudança | número MEDIDO depois |
|---|---|---|
| `ARQUIVOS_NOVOS` | **+1 linha** (`${DIR_APROV}/LateralDoLightbox.jsx`) + o bloco de razão (FID-03, o `font-[800]`, o `.jsx` × `.js`) | `vocabulario-tamanhos` **695** |
| `JSX_DEPOIS_DA_187` | **+1 linha**, com a fase e o plano dono | `fechamento-arquivos-e-manifestos` **6** |

### As que **NÃO** mexi, com a razão medida (declarar por escrito é parte da régua)

| lista / arquivo | por que NÃO | prova |
|---|---|---|
| `LIBERADOS_DEPOIS_DA_187` (`publicador-fila-tokens-187.test.js`) | **nenhum tamanho novo** (§10) | `git log 0e48f1c7..HEAD --` **vazio** |
| `DO_PROTOTIPO` / a cadeia de camadas (`publicador-lightbox-camadas-189.test.js`) | o invariante é do **189-04** e a minha peça **não declara `z-`** | **INTOCADO** |
| `publicador-painel-fotos-188.test.js:639`/`:640` (a ausência de "⤢ Ampliar") | **é o 189-06** que as inverte, e **só estas duas** | **INTOCADO** |
| `publicador-painel-casca-188.test.js:1035` (`/Ampliar/` na FONTE de `PainelDeRevisao.jsx`) | **a minha peça não exige prop nenhuma naquele arquivo** → não provoquei o gate | **INTOCADO** |
| `publicador-header-zindex-ordem.test.js` | **INTOCÁVEL** por régua | **INTOCADO**, 7/7/0 |
| `estrutura-meus-anuncios.test.js` (D-11/D-22) | **INTOCÁVEL** por SEMÂNTICA | **INTOCADO** |
| `CLASSES_ITEM` / `CLASSES_GLIFO` / `CLASSES_ORDINAL` (`BlocoDaImagemSelecionada.jsx`) | **IMPORTO**, não edito; e o `CLASSES_ITEM` é o que **prova a diferença** medida do `items-start` | **INTOCADO** |
| `TEXTO_DA_COR` / `FUNDO_DA_COR` / `COR_NEUTRA` (`ImagemGrandeDoPainel.jsx`) | idem — uma segunda cópia do mapa divergiria | **INTOCADO** |
| `LightboxDaImagem.jsx` (189-03) | mexer na FONTE mudaria o `tests=27` do manifesto dele | **INTOCADO**, gate 27/27/0 |
| `tailwind.config.js` | **nenhum token novo** (§T: todo hex do lightbox já tem nome) | **INTOCADO** |
| `TAMANHOS_PERMITIDOS` | os seis tamanhos já estavam lá | não mexido |
| `REF_JS_TESTS` / `REF_PHP_TESTS` do harness | contagem **ACIMA** da referência é esperada e PASSA | não mexidos |

### Os ids REMEDIDOS, com número **MEDIDO** (nenhum "subido", nada voltou para literal)

| id | manifesto | antes | agora | como |
|---|---|---|---|---|
| `lateral-do-lightbox` | `contagens-189-05.txt` | — (novo) | **`tests=27,27`** | medido nas duas tarefas: `18,18` na tarefa 1 e **REMEDIDO para `27,27` ANTES do verify da tarefa 2** |
| `vocabulario-tamanhos` | `contagens-189-05.txt` | `>=688` (189-03) | **`>=695`** | `# tests 688` → `# tests 695`, o passo de 7 por arquivo |

⚠️ **O segundo regime (`,27`) não é decorativo:** nenhuma camada deste gate depende de `mtime`, então
`cp -p` **não** muda a contagem — e **confirmar que o `# tests` do MUTADO é IGUAL ao do pristino é
obrigatório**. Na campanha, os sete regimes mutados deram **`tests=27`**, todos.

### ⚠️⚠️ ACHADO DE MEDIÇÃO, **NÃO-REGRESSÃO DESTA WAVE**: o id `186-02 voc-07-gate-03`

`tests/gates/contagens-186-02.txt:83` declara `voc-07-gate-03 … tests=399,399` — contagem **EXATA** do
**mesmo** `publicador-vocabulario-novo.test.js`. Ele **já estava estourado ANTES desta wave**, e a
prova é **aritmética, sem precisar rodar**: o **189-03 mediu 688** (está escrito na
`189-03-SUMMARY.md:634`), e **688 ≠ 399**. As Fases 187 e 188 subiram aquele arquivo de 399 para 681
por `>=` e **não atualizaram** o id exato da 186 — que é justamente a razão de todos os manifestos
seguintes usarem `>=`. O manifesto da 186 **não é tocado desde 2026-10-14** (`a10a8ffb`).
➡️ **NÃO é desta wave consertar** (mexer no manifesto de uma fase **em produção** sem o plano dono é
fora de território). **Devolvido ao 189-08.**

---

## §12 — ⚠️⚠️ AS DIVERGÊNCIAS, com `arquivo:linha` — e onde o briefing ou o FID-01 perdeu da medição

### D1 — A linha **"Variação"** (`:749`, §C7) **NÃO é desenhada** — lacuna de DADO, já decidida

**Medido:** `189-CONTRATO-188.md` INV-10 / ACHADO **A3** · `BlocoDaImagemSelecionada.jsx:297`
(reconferido por `sed`: `{/* ⚠️ Só o TIPO: ver a divergência escrita em `VARIACAO`. */}`).
O 188-08 **não desenha variação nenhuma**: o payload só traz a **CHAVE CANÔNICA**
(`ChaveCanonica::combinacao`, na prática `COLOR=id:52049`) e o rótulo humano é `private static` em
`App\Support\Publicador` — **território VETADO**.
➡️ A régua §2 manda *"a 189 USA O QUE O 188-08 USAR"*, e o usuário decidiu em 19/10 **seguir sem o
nome**. **A grade sai com TRÊS pares: Origem · Posição · Estado.**
⚠️ **O `must_haves.truths` do plano diz "…Variação e Estado" — e o CONTRATO ganha**, por ordem
explícita dele (*"quando este inventário e a régua divergirem, VALE ESTE"*) e do próprio
`<action>` do plano (*"se o 188-08 não desenha variação nenhuma, a linha segue a mesma regra que ele
seguiu"*).
⚠️ **NÃO é um quinto conflito protótipo × regra do projeto** — o A3 diz isso textualmente: *"é lacuna
de DADO já decidida pelo usuário em 19/10"*. **Gate próprio:** a chave canônica **nunca** vai à tela
(`COLOR=`, `id:52049`, `grupo_geral`, `cor=preto`), recortado à **lista CONSTANTE** dos meus arquivos,
com isca (a fixture **tem** a chave, senão a sonda seria cega).
⚠️ **E o `A4` do inventário se confirma:** o docblock da 188-08 manda *"ver a divergência escrita em
`VARIACAO`"* e **não há `VARIACAO` naquele arquivo** — erro de REFERÊNCIA **dele**, **não desta fase
consertar**. Registrado.

### D2 — `font-[800]` e **não** `font-extrabold` (§C13) — NOTAÇÃO, valor idêntico

A §C13 prescreve `font-extrabold`. O glifo vem **IMPORTADO** (`CLASSES_GLIFO`,
`BlocoDaImagemSelecionada.jsx:130`-`:133`), que usa **`font-[800]`** — e o **sub-teste 2 do GATE-03
PROÍBE** a escala nomeada (`/font-(thin|extralight|light|extrabold|black)\b/`). As duas formas
entregam o **MESMO `font-weight:800`**. ➡️ **Divergência de notação, por valor idêntico**, no mesmo
precedente que o 189-03 declarou para o marcador e que `ImagemGrandeDoPainel.jsx:170` e
`AnelDeProgresso.jsx` já carregam **em produção**.

⚠️⚠️ **E AQUI O MEU BRIEFING PERDEU DA MEDIÇÃO (erro 1 dele, confirmado pelo outro lado):** ele avisa
que *"uma sonda procurando `font-extrabold` ACHA a classe (135 ocorrências), e passa VERDE pelo motivo
errado"*. Medido aqui: a minha sonda **não procura** a classe no CSS — ela é **de AUSÊNCIA, na FONTE
SEM COMENTÁRIO, recortada ao MEU arquivo** (`LAT-189-13`), com **controle positivo** provando que ela
acha `font-extrabold` quando ele existe. ➡️ **O CSS é global e a classe tem outro dono; a lista está
amarrada aos TOKENS DA FONTE, não ao CSS.**

### D3 — O **ORDINAL** é divergência **PRESCRITA PELO REQUISITO**, não escolha minha

O protótipo do lightbox **não tem** o número (`:760` só desenha `a.glyph`, reconferido por `sed`).
**AMPL-06 e o critério 2 o EXIGEM** (§H3). ➡️ Requisito explícito ganha do protótipo. O número é o
**`ordinal` da prop**, **elemento próprio**, com as classes do 188-08 (`CLASSES_ORDINAL`,
`BlocoDaImagemSelecionada.jsx:141`), **nunca recontado**.

### D4 — O cabeçalho **APOIO**: a **QUARTA** derrota do protótipo, por decisão do usuário

`:759`-`:761` **não tem** cabeçalho × **`:369` tem o literal** `VERIFICAÇÃO AUTOMÁTICA · APOIO` (que é
o **E4**, a revisão detalhada). **PERGUNTA-189-A RESPONDIDA (a) SIM, usuário, 19/10/2026**, razão
registrada: *"sem o cabeçalho a verificação pode ser lida como VEREDICTO"*.
⚠️ **Gate de entrada conferido por comando na primeira ação da tarefa 2:**
`grep -c "PERGUNTA-189-A: RESPONDIDA" 189-REGUA.md` → **1**.
⚠️ **O literal é IMPORTADO** (`BlocoDaImagemSelecionada.jsx:73`, o `·` é **U+00B7**) e o gate cobra que
ele **não** esteja redigitado na minha fonte sem comentário. **Placar do protótipo: ~146 a 4** — este
é o 4º, **já decidido**; **nenhum QUINTO apareceu**, e nada foi devolvido ao usuário por esta via.

### D5 — `aoClicarNoZoom` entrega o **ZOOM NOVO** (o `<interfaces>` do plano diz `onAlternarZoom()`)

O `189-05-PLAN.md:88` descreve `onAlternarZoom()` **sem argumento**. Entrego
`onAlternarZoom(alternarZoom(zoom))`. **Razão:** é a MESMA forma dos handlers do palco (189-03), que
devolvem um **PEDIDO**; e é o que torna `alternarZoom` um import **VIVO** em vez de deixar a lateral
com uma segunda regra de alternância. **Acréscimo, não quebra** — o 189-06 (que liga) ainda não
existe. **Declarado no docblock.**

### D6 — O placeholder da caixa da original: a prop **não traz o `refLabel`** do protótipo

⚠️ **MEDIDO no protótipo:** `:755` é
`<image-slot … placeholder="{{ LB.refLabel }}">` e `:1111` define
`refLabel: m.ref ? this.im(m.ref, D).label : ''` — **o placeholder é o RÓTULO da imagem original**.
**A prop `lightbox.originais` do 189-02 não tem esse campo** (ela traz `disponivel`, `url_arquivo`,
`quantas`). ➡️ Usei **`TITULO_DA_ORIGINAL`** ("Original de referência") como texto do placeholder:
**nunca caixa vazia**, **nunca `<img src="">`** (INV-11).
⚠️ **Não é conflito protótipo × regra do projeto — é a MESMA CLASSE do D1: lacuna de DADO.** O
conserto é de **UMA linha no 189-02** (`LightboxDoPainelService` já carrega a original para montar a
URL: bastaria devolver o `rotulo` dela), e **a régua §3 manda PARAR em vez de mexer em serviço de
outra fase**. ➡️ **DEVOLVIDO ao 189-08 / orquestrador como lacuna NÃO-BLOQUEANTE** (o caso só aparece
quando o arquivo da original está ausente).

### D7 — ⚠️ Um critério de aceitação do plano é **FALSO POR CONSTRUÇÃO**

O plano cobra `grep -c "aprovacao/LateralDoLightbox.jsx" tests/js/publicador-vocabulario-novo.test.js`
→ **1**. **Medido: 0** — e **a MESMA sonda sobre o vizinho do 189-03 também dá 0**
(`aprovacao/LightboxDaImagem.jsx` → **0**). A razão: as entradas de `ARQUIVOS_NOVOS` são **template
literals** (`${DIR_APROV}/Nome.jsx`, linhas `565` e `601`), então a substring `aprovacao/` **nunca
encosta no nome do arquivo**. ➡️ **É gate vazio por construção.** O equivalente **honesto** é
`grep -c "LateralDoLightbox.jsx"` → **1**, e é isso que o **LAT-189-14** cobra — em **cada uma das
duas** listas, com sonda não-cega. Mesma família do **D1 do 189-02** (*"o critério é falso na
partida"*).

### D8 — ⚠️⚠️ As Fases **190 e 191** ficaram **⛔ FORA DE ESCOPO DURANTE a wave**

**Medido**, não herdado: `0607f5a9`, 2026-10-20 14:29:57, `docs(v27): a revisao detalhada sai do
escopo — so a revisao simples`, por **decisão do usuário** (*"vamos descartar a revisão completa do
plano, vamos ficar apenas com a revisão simples"* — **decisão 20** do `ROADMAP.md:5259`).

| consequência | o que muda |
|---|---|
| a RESSALVA do **critério 4** | o dono **não é mais uma fase FUTURA: é uma fase CANCELADA**. Quem ler o docblock depois **não deve esperar** a 190 entregar o "Comparar lado a lado" |
| o que **NÃO** se perde | a própria decisão registra: *"NÃO se perde a comparação com o original de referência — ela entra no lightbox, na Fase 189 (plano 189-05)"*. O que saiu foi a comparação **lado a lado com zoom 1×–4× sincronizado** (`:317`-`:320`), **não** o bloco da original que esta wave entrega |
| a RESSALVA de **AMPL-07** | **continua com dono**: a Fase 192 segue viva, **reancorada no painel e movida para o fim** (`189→193→194→192`) |
| a prova citada | a decisão **cita este arquivo** (`LateralDoLightbox.jsx:37`) como evidência de que o corte **custou zero** — foi a régua **"nada nasce decorativo"** que o fez sair de graça |

➡️ Registrado em **`e9574d1d`**, só no **docblock** do meu arquivo, **aditivo, zero mudança de
comportamento** (`lateral-do-lightbox` seguiu 27/27/0). **Nada do `ROADMAP.md`/`STATE.md` foi tocado
por este plano.**

### D9 — ⚠️ DOIS ponteiros do `189-FID01.md` §G **deslocaram** (documento VIVO)

| citado na §G | medido agora | desvio |
|---|---|---|
| `ROADMAP.md` **L4730** (`Requirements: … MOD-06`) | **L4752** | **+22** |
| `ROADMAP.md` **L5038** (`MOD-06 (1) \| Phase 190`) | **L5081** (agora `\| MOD-06 (1) \| ~~Phase 190~~ \| ⛔ Fora de escopo \|`) | **+43** |

**Causa medida:** `0607f5a9` cresceu o `ROADMAP.md` (hoje **5399 linhas**). ➡️ É exatamente o que a
**§I.8 do FID-01 já avisava**: *"ponteiro de linha para documento vivo apodrece sozinho"* — e
apodreceu **de novo, em dois dias**.
⚠️ **NÃO editei o `189-FID01.md`**, apesar de poder: **cinco arquivos de gate o leem**
(`FechamentoDeBaselines189Test.php`, `contagens-189-01.txt`, `publicador-lightbox-casca-189.test.js`,
`publicador-lightbox-tokens-189.test.js`, `publicador-vocabulario-novo.test.js`), e um deles tem isca
por **bytes da §G**. ➡️ **Devolvido ao 189-08**, que é o dono do fechamento. **Os três gates que leem o
FID-01/ROADMAP foram re-rodados depois do commit alheio e estão VERDES** (3/0 · 27/0 · 3 passed/34
assertions).

### D10 — ⚠️ Onde o **meu briefing** perdeu da medição (as três previsões dele)

| previsão do briefing | o que eu medi |
|---|---|
| *"sonda de `font-extrabold` passa VERDE pelo motivo errado"* | **confirmado como RISCO e evitado**: a minha sonda é de **AUSÊNCIA na FONTE**, recortada ao meu arquivo, com controle positivo (D2) |
| *"os ponteiros do INV-13 deslocaram +4; RECONFIRME CADA ponteiro por `sed`"* | **confirmado e exercido**: reconfirmei **26 ponteiros** por `sed` (§13). **Vinte e quatro bateram**; os **dois** que não bateram são os do `ROADMAP.md`, e deslocaram por **causa nova** (D9), não pelos +4 do INV-13 |
| *"ancore por NOME, nunca por linha"* | **exercido**: o efeito do `EditorEmUso` está declarado no meu docblock por **NOME de arquivo e de método** — `AgendadorDaFila::checar`, `PreparoIaDoRascunhoService::preparar`/`::sincronizarAgora`/`::executarEtapa`, `GarantiaPadrao::aplicarNaConta`, `ExcluirProdutoService::impedimento` (**cinco arquivos, seis pontos**), **zero número de linha** |

### D11 — O `absolute inset-0` do estado IMG-13 (§H4) — acréscimo declarado

A §H4 prescreve `flex flex-col items-center justify-center gap-[6px] p-[12px] text-center`.
Acrescentei **`absolute inset-0`**, porque a caixa da §C11 é `relative aspect-square` e **sem ele o
estado não ocupa a caixa** — é o mesmo mecanismo do `CLASSES_PLACEHOLDER` do painel. **Valores
escolhidos, SEM medida no protótipo** (ele **esconde** o bloco em `:752`) — declarado no docblock, aqui
e no bloco de razão de `ARQUIVOS_NOVOS`.

---

## §13 — OS 26 PONTEIROS RECONFIRMADOS POR `sed`

| ponteiro | o que eu esperava | medido | ok? |
|---|---|---|---|
| `BlocoDaImagemSelecionada.jsx:73` | `CABECALHO_DAS_VERIFICACOES` | **exato** | ✅ |
| `:127` | `CLASSES_CABECALHO_VERIFICACOES` | **exato** (`text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4`) | ✅ |
| `:130` | `CLASSES_ITEM` **com** `items-start` | **exato** | ✅ |
| `:141` | `CLASSES_ORDINAL = 'flex-none text-[12px] font-bold'` | **exato** | ✅ |
| `:219` | `VerificacoesAutomaticas` | **exato** | ✅ |
| `:297` | só o TIPO (INV-10) | **exato** | ✅ |
| `ImagemGrandeDoPainel.jsx:72 / :81 / :95 / :108 / :159 / :325 / :362` | `TEXTO_DA_COR` · `FUNDO_DA_COR` · `COR_NEUTRA` · `classeDaCor` · `CLASSES_PLACEHOLDER` · `SeloDeEstado` · o ramo `url === null` | **os sete exatos** | ✅ |
| `PainelDeRevisao.jsx:711 / :715 / :717` | `<aside>` abre · fecha · `{noModais}` **IRMÃO** (INV-04/A1) | **os três exatos** | ✅ |
| `PubImagemReferencia.php:69 / :71 / :73 / :76 / :130` | as 4 constantes `SEM_ORIGINAL*` e `originaisDe` | **os cinco exatos** | ✅ |
| `routes/mlb_anuncios.php:158 / :178` | o prefixo e `/fotos/{imagem}/arquivo` | **os dois exatos** (⚠️ **`158`, não `157`** — a correção do 189-02 confirmada) | ✅ |
| `publicador-painel-casca-188.test.js:1035` | `[/Ampliar/, 'idem']` | **exato** | ✅ |
| `publicador-painel-fotos-188.test.js:639 / :640 / :641` | as duas de "Ampliar" + a de "Gerar novamente" | **as três exatas** | ✅ |
| `Aprovacao.dc.html` — os 20 da §9 | §C1..§C17 + `:369` + `:633` + `:760` | **os vinte exatos** | ✅ |
| **`ROADMAP.md:4730`** | `Requirements: … MOD-06` | **L4752** | ❌ **D9** |
| **`ROADMAP.md:5038`** | `MOD-06 (1) \| Phase 190` | **L5081** | ❌ **D9** |

⚠️ **E eu NÃO usei os números do INV-13** (o 189-02 mediu que eles deslocaram **+4** pela própria
edição dele): este plano **não toca PHP nenhum**, então não precisou deles — e o digo por escrito em
vez de deixar o leitor supor.

---

## §14 — A BIJEÇÃO (com o EXPANSOR), o HOMÔNIMO e o `--autoteste`

### Bijeção id ↔ `<verify>`, nos DOIS sentidos, por script (`bijecao-189-05.cjs`)

```
IDS_DO_MANIFESTO=["lateral-do-lightbox","vocabulario-tamanhos"]
BLOCOS_VERIFY=2
CHAMADAS_NOS_VERIFY=[{"manifesto":"189-05","id":"lateral-do-lightbox"},
                     {"manifesto":"189-05","id":"vocabulario-tamanhos"},
                     {"manifesto":"189-05","id":"lateral-do-lightbox"},
                     {"manifesto":"186-09","id":"transversais"}]
IDS_DESTE_MANIFESTO_NOS_VERIFY=["lateral-do-lightbox","vocabulario-tamanhos"]
IDS_DE_OUTROS_MANIFESTOS=["186-09 transversais"]
ID_ORFAO=[]
VERIFY_SEM_ID=[]
PRIMEIRO_ARGUMENTO_NU=[]
CASOS_NO_GATE=27
ISCA_EXPANSOR=["test_a_fim","test_b_fim"]
CASO_SEM_SUFIXO=[]
NOME_REPETIDO=[]
IDS_PHP=0

INFRATORES=[]
```

⚠️ **O EXPANSOR DE ALTERNÂNCIA está no script e foi EXERCIDO:** `test_(a|b)_fim` →
`["test_a_fim","test_b_fim"]`. Sem ele, `/test_[A-Za-z0-9_]+/` casaria **ZERO** na forma com
alternância — o furo que deu `SEM_ID=14` **acusando trabalho correto** numa wave anterior.
⚠️ **`PRIMEIRO_ARGUMENTO_NU=[]`**: nenhum `<verify>` deste plano chama o harness com o número **NU**
(`05` resolveria `contagens-186-05.txt`, a fase ERRADA, **em silêncio**).
⚠️ **ZERO id órfão** e **ZERO verify sem id**. Os **27** casos terminam em **`189_05`**, nenhum
repetido.

### HOMÔNIMO — **este plano não tem id `php`**, e isso é declarado

`IDS_PHP=0`. O `--filter` do PHPUnit — que é o que **SOMA** contagens de métodos homônimos — **não roda
sobre arquivo de teste JS**; no modo `js` o harness roda o **arquivo inteiro**. ➡️ **Não há homônimo de
`--filter` a conferir.** Os nomes levam o sufixo `189_05` de todo modo, porque a régua vale para o dia
em que rodar. **Nenhum id com mais de TRÊS métodos** (não há id `php`).
⚠️ **E não repeti o erro da 189-04**: não contei "homônimos" sobre **nomes de sub-teste**, que este
projeto reusa de propósito e que **filtro nenhum vê** — foram **27 falsos** lá.

### `--autoteste`: **20 casos, 0 divergência** — COLADO

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

⚠️ **Nenhum `node --test` nem `artisan test` foi chamado DIRETO num gate** — sempre pelo harness (o
reporter do Node v24 emite **`ℹ`**, não `#`, e `grep -E "Tests:|OK"` **PASSA COM TESTE FALHANDO**).
⚠️ **E NUNCA li `${TMPDIR:-/tmp}/ultima-suite-php.log` durante a execução** — caminho **FIXO E
COMPARTILHADO**; uma wave leu e reportou `0 falhas` quando eram **11**. As minhas medições de suíte
foram para **arquivos próprios no scratchpad**, com o caminho completo.

---

## §15 — OS GATES: os meus e a NÃO-REGRESSÃO dos vizinhos

| gate | contagem | leitura |
|---|---|---|
| `189-05 lateral-do-lightbox` | **27 / 27 / 0** (`tests=27,27`) | o gate deste plano |
| `189-05 vocabulario-tamanhos` | **695 / 695 / 0** (`>=695`) | GATE-03 **VERDE** |
| `186-09 transversais` | **12 passed (258 assertions)** | **INALTERADO**, como o plano exige |
| `187-01 vocabulario-tamanhos` | VERDE | `>=443` |
| `188-12 vocabulario-tamanhos` | VERDE | `>=681` |
| `189-01 vocabulario-tamanhos` | VERDE | `>=681` |
| `189-03 vocabulario-tamanhos` | VERDE | `>=688` |
| `187-11 fechamento-arquivos-e-manifestos` | VERDE (**6**) | o `deepEqual` das TRÊS listas × o disco |
| `189-03 lightbox-casca` | VERDE (**27**) | o `tests=27` do 189-03 **não se moveu** |
| `189-04 camadas-e-css` | VERDE | a ordem de camadas e o CSS construído |
| `publicador-header-zindex-ordem.test.js` | **7 / 7 / 0** | **INTOCÁVEL e INTOCADO** |
| `publicador-lightbox-tokens-189.test.js` | **3 / 0** | re-rodado **depois** do commit alheio |
| `FechamentoDeBaselines189Test.php` | **3 passed (34 assertions)** | idem (ele cobra `'Ampliar 2,5×'` escrito **em algum lugar** — e agora está) |

⚠️ **Nenhuma contagem alheia foi remedida.** A única vermelha do repositório é `186-02
voc-07-gate-03`, **pré-existente e provada aritmeticamente** (§11).

---

## §16 — A CAMPANHA DE MUTAÇÃO: **7 mutações, 0 sobreviventes**

**Script:** `mutar-189-05.sh` + `mutador-189-05.cjs`, escritos por **ferramenta** no scratchpad
(⚠️ **o scratchpad é COMPARTILHADO**: na Fase 187 um script foi **sobrescrito** e a campanha foi
**refeita inteira**, learnings §4c.3 — por isso o nome carrega o plano).
**`sha256` dos dois, colados:** `a5760dc67e60c02d944e8ca8` (`.sh`) · `931f3339da3b1817595aac29` (`.cjs`).

**A receita, na ordem executada:** commit **ANTES** → `SHA_DISCO == SHA_HEAD` por
`git show HEAD:… | tr -d '\r' | sha256sum` (**`704c30c123000ccf` nos dois**) → `cp -p` do pristino →
restauração **DA CÓPIA** em `trap EXIT` (⚠️ **`git checkout` NUNCA**) → ocorrência **travada em 1
ANTES**, por **string exata** e `indexOf` em laço (⚠️ **`grep -c` conta LINHAS**) → **linha conferida
por `sed`** → aplicação provada por **DIVERGÊNCIA de `sha256`** → **`# tests` do mutado conferido
igual ao do pristino**.

| # | mutação | linha | ocorr. | `sha256` mutado | `# tests` | morreu em (pelo NOME) |
|---|---|---|---|---|---|---|
| i | número **também no `ok`** e no `ordinal: 0` | `560` | 1 | `f599d8e94cada095` | **27** | `LAT-189-20` |
| ii | número **COLADO** no texto do item | `570` | 1 | `c0de941379e51912` | **27** | `LAT-189-20` |
| iii | tirar **`aceita_acao`** da trava | `383` | 1 | `47ba38c8f7687d33` | **27** | `LAT-189-24` |
| iv | **`aoVivo` por truthiness** | `381` | 1 | `70141a9f2e812984` | **27** | `LAT-189-24` |
| v | desenhar **"Comparar lado a lado" desabilitado** | `470` | 1 | `8050fd8add9a5535` | **27** | `LAT-189-12` (+ `LAT-189-4` e `LAT-189-23`) |
| vi | a original mostrando a **url da imagem de IA** | `466` | 1 | `741cc946f8fd3719` | **27** | `LAT-189-8` (+ `LAT-189-9`) |
| vii | cabeçalho APOIO **DUAS vezes** | `556` | 1 | `ba5cf619ca1114a7` | **27** | `LAT-189-19` (+ `LAT-189-22`) |

**`SOBREVIVENTES: (nenhum)`** · `PRISTINO: tests=27 fail=0` · as sete linhas-alvo conferidas por `sed`
são **CÓDIGO**, nenhuma comentário.

### ⚠️ A INVESTIGAÇÃO de ONDE cada mutação morreu (a lição das waves 1 e 4)

⚠️ Não bastaria contar mortes: na **189-01** uma sobrevivente **matou o gate pela asserção ERRADA** e
não provava nada, e na **189-04** uma sobrevivente **acusou um furo real**. Aqui as sete morreram
**no caso que o plano prescreve**, e **três mataram caso EXTRA** — e isso é informação:

- **(v)** matou também `LAT-189-4` e `LAT-189-23` porque o `disabled` injetado trips as asserções
  `doesNotMatch(/disabled/)` dos dois botões. ➡️ **É o desenho, não ruído**: *"`onClick` vazio e
  `disabled` decorativo são a mesma dívida"*, e **"desabilitado com explicação" foi OFERECIDO ao
  usuário e RECUSADO**. A asserção de `disabled` é **deliberadamente de HTML inteiro**.
- **(vi)** matou também `LAT-189-9`: com `dados.url_arquivo` a caixa passaria a desenhar uma `<img>`
  **mesmo no caso `url_arquivo: null`** da original — a mutação revela **dois** danos, não um.
- **(vii)** matou também `LAT-189-22`, porque o item passa a contar **5** filhos em vez de 4. A
  asserção de **contagem de filhos por profundidade** é o que pega cabeçalho repetido **sem** depender
  do literal.

⚠️ **E o `# tests` do MUTADO foi IGUAL (27) nas SETE**: nenhuma morte veio de o arquivo **não
carregar**. Era a conferência obrigatória.

---

## §17 — `npm run build` **DEPOIS** da mutação, com o diff conferido **ANTES**

| passo | medida |
|---|---|
| 1. restauração conferida | `DISCO=704c30c123000ccf` == `HEAD=704c30c123000ccf` (com `tr -d '\r'`) |
| 2. ⚠️ `git diff HEAD --stat -- resources/` **ANTES** de construir | **VAZIO** → **nenhum `.jsx` alheio meio-salvo**; com WIP de outra sessão eu **não** teria construído (o artefato sairia meio-feito) |
| 3. `npm run build` | **exit 0**, `✓ built in 1m 18s` |
| 4. manifest | **498 entradas**, lido pelo **JSON** |

⚠️ **O build foi DEPOIS da mutação, de propósito:** `cp -p` atualiza o `mtime`, e uma camada de CSS que
dependa dele **PULA**, dando `skipped` que **NÃO é regressão**.

### As classes deste plano no **CSS CONSTRUÍDO**, lido pelo JSON (`assets/app-CSIqQ5bQ.css`, 234.300 bytes)

```
.grid-cols-\[110px_minmax\(0\,1fr\)\]   1      .text-\[11\.5px\]                 1
.gap-y-\[9px\]                          1      .text-\[12\.5px\]                 1
.p-\[18px\]                             1      .rounded-\[8px\]                  1
.px-\[10px\]                            1      .border-pub-danger\/\[0\.45\]     1
.leading-\[1\.4\]                       1      .mt-auto                          1
.h-\[30px\]                             1      .overflow-y-auto                  1
.h-\[32px\]                             1      .gap-\[14px\]                     1
.h-\[36px\]                             1      naoexiste9z7                      0   <- ISCA
```

⚠️ **A leitura é com `grep -F` e o ESCAPE do CSS** — o 189-04 mediu que *"o escape é GERAL"*, e sem o
`\[` literal **todas as 16 dariam 0 e eu teria concluído o contrário**.
⚠️ **ACHADO que refina as oito normalizações do 189-04:** o cssnano **come o zero da frente do VALOR,
mas NÃO do SELETOR** — `.border-pub-danger\/\[0\.45\]` está presente (**1**) e
`.border-pub-danger\/\[\.45\]` está **AUSENTE** (**0**). Quem escrevesse a sonda na forma `.45`
mediria **gate vazio por construção**.
⚠️ **O CSS foi achado pela chave `resources/js/app.jsx` → `.css[0]` do JSON**, e **não** por
`manifest['resources/css/app.css']` (que **não existe** nesta configuração) nem por glob de hash — e o
primeiro `.css` do JSON é o do **`GradeAnuncioGlide`**, não o do app. ➡️ **Mais uma razão para ler pelo
JSON e conferir QUAL entrada.**

---

## §18 — O TERRITÓRIO por **ESCOPO DO ASSUNTO**: PI(189-05) COLADA

```
═══ 1. test -e EM CADA ALVO ═══
N_ALVOS=43
FANTASMAS: (nenhum)

═══ 2. OS COMMITS DO ESCOPO, recortados no ASSUNTO (%s) ═══
DESCARTADO (escopo so no CORPO, nao no assunto): d8b30fdf
COMMITS_DO_ESCOPO: e9574d1d 15e72d3c 6274b0fd a979888d 83ff4638

═══ 3/4. AS DUAS PASSADAS COINCIDEM ═══
  resources/js/Components/Mlb/Publicador/aprovacao/LateralDoLightbox.jsx
  tests/gates/contagens-189-05.txt
  tests/js/publicador-fila-fechamento-187.test.js
  tests/js/publicador-lightbox-lateral-189.test.js
  tests/js/publicador-vocabulario-novo.test.js

═══ 5. INTERSECAO com os 43 alvos ═══
INTERSECAO: VAZIA

═══ 6a. ISCA (a) — a sonda ENXERGA? ═══
  isca (188-07) sobre …/PainelDeRevisao.jsx      -> 1 ocorrencia(s) ACUSOU (OK)
  isca (188-08) sobre tests/gates/contagens-188-08.txt -> 1 ocorrencia(s) ACUSOU (OK)

═══ 6b. ISCA (b) — o recorte VAZA para outra sessao? ═══
  nenhum commit de assunto (188-…)/(190-…)/(261019-…)/(189-0N) entrou — OK

APROVADO: PI(189-05) com intersecao VAZIA e as DUAS iscas exercidas.
```

### ⚠️⚠️ AS TRÊS FACES DO LIMITE, e qual delas **SE CONCRETIZOU** aqui

| face | o que é | medido nesta wave |
|---|---|---|
| **1. SOBRE-CAPTURA** | `git log --grep` lê a **MENSAGEM INTEIRA**: um commit que só **CITE** `(189-05)` no corpo entra | ⚠️⚠️ **SE CONCRETIZOU:** **`d8b30fdf`** (o `feat(189-03)`) cita `(189-05)` no **corpo** e foi capturado pelo `--grep`. O recorte no **`%s`** o **DESCARTOU** — e isso está impresso na saída. **Sem o recorte no assunto, o `LightboxDaImagem.jsx` entraria na minha lista de caminhos e a interseção NÃO seria vazia** |
| **2. SUB-CAPTURA** | commit que tocou um alvo **sem** o escopo no assunto não aparece (se concretizou na **189-04**) | **medido À MÃO** abaixo: **nenhum** |
| **3. O AUTOR NÃO DISCRIMINA** | as três máquinas usam o mesmo `MB.ECF-100376` | **conferido que é inútil, e NÃO confiro autor em lugar nenhum** |

### Os VIZINHOS, medidos **À MÃO** (`git log 0e48f1c7..HEAD -- <arquivo>`, sem `--grep`)

```
tests/js/publicador-vocabulario-novo.test.js      -> 83ff4638 (meu)
tests/js/publicador-fila-fechamento-187.test.js   -> 83ff4638 (meu)
.../aprovacao/LateralDoLightbox.jsx               -> e9574d1d 15e72d3c a979888d 83ff4638 (todos meus)
tests/js/publicador-lightbox-lateral-189.test.js  -> 6274b0fd 83ff4638 (meus)
tests/gates/contagens-189-05.txt                  -> 6274b0fd 83ff4638 (meus)
```

➡️ **Nenhum commit alheio tocou os meus cinco arquivos.** O único commit alheio da janela
(`0607f5a9`) tocou **só** `.planning/ROADMAP.md` e `.planning/STATE.md`.

⚠️ **`git diff --numstat -- <alvos>` "sair vazio" NÃO foi usado como prova** em nenhum ponto: depois
do commit ele compara a **ÁRVORE** e sai vazio **SEMPRE** — gate vazio por construção.
⚠️ **"Árvore limpa" por `git status` também não** — ela aborta pelos `??` de outra sessão (há **27
`stash`** nesta árvore, que eu **apenas LISTEI**, sem rodar `git stash` em forma nenhuma). Usei
`git diff HEAD` nos **meus** arquivos, antes de cada edição e antes do build.

---

## §19 — Deviations from Plan

### Auto-fixed / declarados (Regras 1-3 e divergências medidas — nenhum pediu permissão)

**1. [Rule 1 — Bug] A sonda não-cega da lista de valores adversos cobrava `20` e a lista tem `19`**
- **Found during:** tarefa 1, logo após o RED
- **Issue:** `assert.equal(ADVERSOS.length, 20)` com 19 elementos — a asserção falharia **por erro
  próprio**, não por regressão, e um executor seguinte poderia "consertá-la" tirando a asserção
  (perdendo a sonda não-cega inteira)
- **Fix:** número **CONTADO**, não chutado → `19`
- **Commit:** `83ff4638`

**2. [Rule 2 — Funcionalidade crítica] `Fragment` em vez de wrapper na grade de metadados**
- **Found during:** tarefa 1 (GREEN)
- **Issue:** a primeira redação usava `<div className="contents">` por par. A grade é
  `grid-cols-[110px_minmax(0,1fr)]` e os dois `<span>` **têm de ser filhos DIRETOS** — um wrapper
  (mesmo `display:contents`) **muda a PROFUNDIDADE da árvore**, que é o que prova estrutura
- **Fix:** `Fragment` com `key` → zero elemento no DOM
- **Commit:** `a979888d`

**3. [Rule 2 — Funcionalidade crítica] `ordinal: 0` não desenha (inteiro POSITIVO)**
- **Found during:** tarefa 2
- **Issue:** `inteiroDoPainel(0)` devolve `0`, que **passa** por `Number.isInteger` — e `0` na tela
  apontaria para um marcador **que não existe** sobre a foto
- **Fix:** `typeof item.ordinal === 'number' && item.ordinal > 0`, com caso de gate e **mutação (i)**
- **Commit:** `15e72d3c`

**4. [Rule 2 — Documentação crítica] A ressalva do critério 4 ficou com dono CANCELADO**
- **Found during:** a medição final (§12 D8)
- **Issue:** um leitor futuro do docblock esperaria a Fase 190 entregar o "Comparar lado a lado" —
  e ela foi marcada ⛔ FORA DE ESCOPO **durante** a wave
- **Fix:** nota **ADITIVA**, só comentário, no meu próprio arquivo
- **Commit:** `e9574d1d`

### TDD — o ciclo foi seguido nas DUAS tarefas, com um desvio declarado

Para o RED existir, o `.jsx` precisa **existir no disco** (o `esbuild` do gate o compila no
carregamento do módulo, e declarar o caminho em `ARQUIVOS_NOVOS` **antes** de o arquivo existir
**LANÇA**). ➡️ O commit de RED de cada tarefa traz o arquivo **no estado mínimo** (tarefa 1: só o
default devolvendo `null`); o GREEN o preenche. **Gate sequence no `git log`: `test` → `feat` → `test`
→ `feat`.** Não houve `refactor`.

### Nada foi devolvido ao usuário por decisão de produto

Nenhum **QUINTO conflito protótipo × regra do projeto** apareceu: o cabeçalho APOIO é o **4º, JÁ
decidido**; a Variação (D1) e o `refLabel` (D6) são **lacunas de DADO**, e o próprio
`189-CONTRATO-188.md` A3 diz textualmente que a primeira *"NÃO é um quinto conflito"*. Nenhum gate
alheio reprovou. **Não precisei mudar a FONTE do `LightboxDaImagem.jsx`**, **não exigi prop nenhuma em
`PainelDeRevisao.jsx`** (então o `casca-188:1035` **não foi provocado**) e **nada exigiu tocar**
`AppLayout.jsx`, `PublicadorHeader.jsx` ou serviço/`.jsx` das Fases 186/187/188.

### DEVOLVIDO ao 189-08 / orquestrador (não-bloqueante, nenhum consertado aqui)

| # | o que | por que não é desta wave |
|---|---|---|
| 1 | **o `refLabel`** (D6): a prop do 189-02 não traz o rótulo da original | o conserto é **no serviço do 189-02** e a régua §3 manda **PARAR** em vez de mexer em serviço de outra fase |
| 2 | **`186-02 voc-07-gate-03`** com `tests=399,399` (§11) | manifesto de uma fase **em produção**, sem o plano dono |
| 3 | **os dois ponteiros do `189-FID01.md` §G** (D9) | **cinco gates leem** aquele arquivo, um deles com isca por bytes da §G |
| 4 | **`VARIACAO` inexistente** no docblock do 188-08 (A4) | erro de REFERÊNCIA **daquele** arquivo |
| 5 | **`publicador-lightbox-casca-189.test.js:1352`** — o gate mais largo que o invariante que a 189-04 mediu e **não consertou** | **a decisão é do 189-08. Não reabri.** |

---

## §20 — Known Stubs

**Nenhum.** Varredura dos meus arquivos por `TODO`, `FIXME`, `coming soon`, `em breve`,
`não disponível`, `= []`, `= {}`, `placeholder"`:

```
não disponível     no CRU=sim  no CODIGO=nao     <- o docblock explica que o texto vem do SERVIDOR
TODO               no CRU=sim  no CODIGO=nao     <- substring de "TODOS"/"TODO modo" em pt-BR
FIXME              no CRU=nao  no CODIGO=nao
em breve           no CRU=sim  no CODIGO=nao     <- o docblock diz "nem 'em breve'"
coming soon        no CRU=nao  no CODIGO=nao
```

⚠️ **Nenhum valor vazio flui para a tela:** `imagem` nula → `''` (e **quem diz o vazio é o painel**);
par de metadado sem valor **não nasce**; `verificacoes` vazia → **nem cabeçalho nem itens**; caixa da
original **nunca** vazia. ⚠️ **Nenhum botão decorativo:** os quatro só nascem com handler **função**, e
não há **um** `disabled` no HTML em nenhum estado.

---

## §21 — Threat Flags

| threat | disposição | como ficou |
|---|---|---|
| **T-189-30** (ação em `regenerating` / fora do `live`) | **mitigado** | `podeAgirNaFoto` estrita + a matriz **6×2×2×2 com UM ponto liberado** + o conflito `pending`+`aceita_acao:false`. Mutações **iii** e **iv** matam |
| **T-189-31** (número da lista ≠ número do marcador) | **mitigado** | `ordinal` **LIDO**; **render cruzado palco × lateral** comparando os números **do HTML**, iguais e **na mesma ordem**, com ordinais `4` e `2` distinguindo *ler* de *contar*. Mutações **i** e **ii** matam |
| **T-189-32** (a imagem de IA mostrada como "original") | **mitigado** | `src` provado **≠** `url_arquivo` da IA, com **isca cega**. Mutação **vi** mata |
| **T-189-33** (`icone`/`estado_rotulo` adverso derrubando a página) | **mitigado** | guarda de **tipo ANTES** do mapa (`typeof` → `hasOwnProperty.call`); render adverso **no render E no clique** |
| **T-189-34** (botão de outra fase sem destino) | **mitigado** | "Comparar lado a lado" e "✦ Gerar novamente com IA" **ausentes**, gate com **isca nas duas direções**; RESSALVAS com dono. Mutação **v** mata |
| **T-189-35** (escrita direta do lightbox) | **mitigado** | ausência de `router.post(` / `axios.` / `fetch(` **lida sem comentário**, com isca sintética que acusa **5 dos 11** literais |
| **T-189-SC** (npm installs) | **mitigado** | **este plano não instalou nada** — `lucide-react` já é dependência, e o import de `ImageOff` foi provado por `node --input-type=module` antes de ser escrito |

**Nenhuma superfície de segurança nova** — este plano não cria rota, não escreve, não lê banco.

---

## §22 — O que a próxima wave precisa saber

| wave | o que consumir, e a armadilha |
|---|---|
| **189-06** | **IMPORTE** `LateralDoLightbox` (default) e passe-a como **NÓ PRONTO** no slot `lateral` da `RaizDoLightbox` — ela aceita **`isValidElement` e nada mais**, e `{type:'div',props:{}}` é **recusado** (medido). ⚠️ A assinatura é `{imagem, original, aoVivo, zoom, onFechar, onAlternarZoom, onAprovarFoto, onReprovar}`. ⚠️⚠️ **`onAlternarZoom` recebe o ZOOM NOVO** (`aoClicarNoZoom` já chamou `alternarZoom` do 189-03) — **não recompute**. ⚠️ `original` é `lightbox.originais[imagem.id]`, indexado pelo **`id` da revisão** (`pub_aprovacao_imagens.id`), **não** `imagem_id`. ⚠️ **ARMADILHA A2 segue valendo:** `casca-188:1035` varre a FONTE de `PainelDeRevisao.jsx` por `/Ampliar/` — uma prop `onAmpliar` ali **REPROVA**; use `onAbrirLightbox`. ⚠️ **Eu NÃO toquei** `publicador-painel-fotos-188.test.js:639`/`:640` — são suas, e **só elas** |
| **189-07** | **IMPORTE** `podeAgirNaFoto` e os dois handlers (`aoClicarEmReprovar`, `aoClicarEmAprovar`) — eles já **saneiam a imagem** e **não chamam** o handler com imagem adversa. ⚠️ A trava da tela é **estrita**; a trava **REAL** é o **422 do 188-05**. ⚠️ `escritasDaColuna({empresaId, ofertaId, versao, onConflito})` aceita os três **por parâmetro** (INV-03/A6): chame com o contexto **DO LIGHTBOX** |
| **189-08** | ⚠️ **Registre as 11 divergências da §12**, em especial: **D8** (as Fases 190/191 ⛔ **fora de escopo**, o que muda a leitura da RESSALVA do **critério 4** — o dono é uma fase **cancelada**, e o que **não** se perde é o bloco da original que esta wave entregou); **D6** (a lacuna do `refLabel`); **D7** (o critério de aceitação **falso por construção**); **D9** (os dois ponteiros do `189-FID01.md` §G, de novo apodrecidos). ⚠️ **E os 5 itens devolvidos na §19.** ⚠️ `PERGUNTA-VISUAL-189` segue **ABERTA**, e as conferências visuais das Fases **187 e 188** também, **separadas** |

---

## §23 — O QUE EU **NÃO** POSSO AFIRMAR

1. **Não vi tela renderizada.** Sem navegador, `mysqld` **parado**, banco sem empresa com `ml_token`
   (**404**), `pub_aprovacao_ofertas` **vazia**, fila vazia **até a Fase 194**.
2. **Não medi a suíte PHP completa** — ela **não é medível** nesta máquina (Fatal de memória em
   processo **FILHO**; `-d memory_limit=-1` **não** resolve). Medi **pelo recorte**.
3. **Não deployei, não fiz push, não toquei a VPS, não chamei a API do ML.** Nenhuma migration
   (**esta fase não tem**).
4. **Não rodei `state.advance-plan`** e **não toquei** `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md`
   nem `STATE.md` (GATE-07) — por instrução, e provado pela **PI**.
5. **Não provei** que a lateral **não cria contexto de empilhamento** no navegador: isso está travado
   por **proxy de FONTE**, declarado como tal.

---

## Self-Check: PASSED

**Arquivos — `test -e`:**
```
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/LateralDoLightbox.jsx   (686 linhas, 34 named exports + 1 default)
FOUND: tests/js/publicador-lightbox-lateral-189.test.js                         (1439 linhas, 27 casos)
FOUND: tests/gates/contagens-189-05.txt                                         (103 linhas, 2 ids)
FOUND: .planning/phases/189-…/189-05-SUMMARY.md                                 (este arquivo)
```

**Mínimos de linha do plano:** `LateralDoLightbox.jsx` **686 >= 180** ✅ ·
`publicador-lightbox-lateral-189.test.js` **1439 >= 300** ✅

**Commits — `git log --oneline --all | grep -q`:**
```
FOUND: 83ff4638  test(189-05): o RED da lateral de 320px — 18 casos, 12 vermelhos e discriminantes
FOUND: a979888d  feat(189-05): a lateral de 320px — os metadados e a original de referencia
FOUND: 6274b0fd  test(189-05): o RED das verificacoes numeradas, do zoom e das travas
FOUND: 15e72d3c  feat(189-05): as verificacoes numeradas, o zoom e as travas de Reprovar/Aprovar
FOUND: e9574d1d  docs(189-05): a Fase 190 ficou FORA DE ESCOPO durante a wave
```

**Critérios de aceitação, por comando:**
```
test -e <jsx>                                          -> 0                        ✅
grep -c "LateralDoLightbox.jsx" vocabulario-novo       -> 1                        ✅  (o literal "aprovacao/…" dá 0 — D7)
grep -c "LateralDoLightbox.jsx" fila-fechamento-187    -> 1                        ✅
grep -cE "^export (function|const) podeAgirNaFoto\b"   -> 1                        ✅
grep -c "ImageOff"                                     -> 2                        ✅  (>= 1)
sem comentário: CONTÉM "Fechar · Esc", "Original de referência"                     ✅
sem comentário: AUSENTES os 12 literais vetados (incl. "Ampliar 2,5×"/"Reduzir")    ✅
```

**Gates:** `lateral-do-lightbox` **27/27/0** ✅ · `vocabulario-tamanhos` **695 (>=695)** ✅ ·
`186-09 transversais` **12 passed / 258 assertions, INALTERADO** ✅ · **10 gates vizinhos VERDES**,
nenhuma contagem alheia remedida ✅ · `publicador-header-zindex-ordem.test.js` **7/7/0, INTOCADO** ✅ ·
`--autoteste` **20/0** ✅ · **bijeção** `INFRATORES=[]`, zero órfão ✅ · **PI(189-05)** interseção
**VAZIA**, 43 alvos, 2 iscas exercidas, as 3 faces declaradas ✅ · **mutação 7/7 mortas, 0
sobreviventes** ✅ · `npm run build` **exit 0** com as **16 classes no CSS** e a isca em 0 ✅

**Suítes:** JS **5958 / 5957 / 1 / 0** (a única falha é a herdada da **Fase 87**, atribuída por
execução **ISOLADA** em 19/18/1) · PHP recorte **2595 / 23878 assertions / 0 falhas** — re-medido no
FIM e **IDÊNTICO à PARTIDA**.
⚠️ A suíte PHP **completa não é medível** nesta máquina.

**Entrada morta:** `IMPORTS=21 · IMPORTS_MORTOS=[]`, com isca ✅
