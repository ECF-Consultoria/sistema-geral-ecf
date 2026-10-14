---
phase: 180-publicacoes-lista
plan: 08
subsystem: ui
tags: [publicador, publicacoes, tabela, grid, fidelidade, render-adverso, mutacao, sonda-css, lacuna-de-prop]

requires:
  - phase: 180-publicacoes-lista
    plan: "01"
    provides: "os CINCO fundos de linha JÁ NOMEADOS — `row-sel` (#221D07), `row-focus` (#1C1C1C), `row-checked` (#18160C), `row-new` (#14120A) e `surface` (#111111) —, mais `pub-table` (14px), `pub-control-sm` (7px), `pub-badge` (4px), `pub-line-subtle` (#1F1F1F), `pub-hover` (#141414), `pub-line-off`, `pub-txt-off`, `pub-ok-txt`, `pub-danger`, `pub-neutral-ring` e os tamanhos 10/11.5/12.5/13/13.5 em `TAMANHOS_PERMITIDOS`"
  - phase: 180-publicacoes-lista
    plan: "04"
    provides: "o shape do item de `anuncios.data` — as 34 chaves, com `status` CRU do acervo, `tipo` em `['classico','premium']` (`:801`-`:803`), `motivos` como SLUG, `fase`/`quantidade_kit`/`autor_nome`/`autor_tipo`/`publicada_em` do mapa de metodologia (`null` = Legado) e a decisão explícita de que `margem`/`lucro`/`repasse`/`tarifa` NÃO existem (LIST-12)"
  - phase: 180-publicacoes-lista
    plan: "07"
    provides: "o molde do harness (bundles antes do 1º `test()`, `blocoEm` com profundidade, `descreve()` seguro, sonda de CSS com parênteses escapados), a armadilha do `hasOwnProperty`/`obj[chave]` que COAGE e LANÇA, a contagem de `publicacoes/` em 8 e a disciplina de provar 'zero PHP' commit por commit"
  - phase: 177-identidade-visual-do-publicador
    provides: "`RotuloDaFase` (que JÁ compõe o `GlyphDaFase`), `SeloDoTipo`, `SeloDeStatusDaPublicacao`, `BadgeNova`, `TileDoProduto` e `identidade.js` (`iniciais`/`tomDoProduto`, a única casa do `oklch(`)"
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "o literal `de 86` de `MeusAnuncios.jsx:211` (D-22), o atalho `Pencil` → `mlb.anuncios.wizard` que LIST-23 manda preservar, e o `if (!info) return null` de `:596` — o precedente de que motivo sem rótulo NÃO vira slug na tela"
provides:
  - "`publicacoes/CabecalhoDaTabela.jsx` — o grid LITERAL das 9 colunas numa casa só (`GRID_DA_TABELA`), o cabeçalho de 40px, os 9 rótulos byte a byte e o checkbox de 16px. Exports `GRID_DA_TABELA`, `CLASSE_DO_CARD`, `CLASSE_DO_CHECKBOX`, `CHECKBOX_LIGADO`, `CHECKBOX_DESLIGADO`, `CheckDoCheckbox`, `CheckboxDaTabela`"
  - "`publicacoes/LinhaDePublicacao.jsx` — a linha de 62/50px com os CINCO fundos distintos e a precedência do `:553`. Exports `ALTURA_CONFORTAVEL`, `ALTURA_COMPACTA`, `fundoDaLinha`, e reexporta `CabecalhoDaTabela`/`GRID_DA_TABELA`"
  - "`publicacoes/CelulasDaLinha.jsx` — as 8 células (`CelulaPublicacao`/`Metodologia`/`Tipo`/`Status`/`Preco`/`Vendas`/`Publicada`/`Acoes`) e os helpers puros `numeroMedido`, `moedaBRL`, `inteiroPtBr`, `percentualDaMargem`, `textoDaTela`, `caminhoSeguro`, `partesDaData`, `hojeEmSaoPaulo`, `dataRelativa`, `faseDaLinha`, `quantidadeDeCombo`, `urlSegura`, `textoDaNotaEcf`"
  - "`tests/js/publicador-publicacoes-tabela.test.js` — 118 testes: o grid no CSS construído, os 5 fundos em 5 `rgb()` distintos, 62×50, o `—` da margem com item CHEIO, os 4 literais de autor, `de 86` em `sr-only`, 746 casos de render adverso e localização por ÁRVORE em 5 linhas irmãs"
  - "a contagem de `publicacoes/` ELEVADA de 8 para 11 no gate de vocabulário, com os 3 nomes declarados"
  - "UM BUG DE RENDER achado e corrigido (`textoSeguro(NaN)` → a string `'NaN'` em 5 campos) e QUATRO furos do próprio gate, TRÊS deles reprovando o código certo"
affects: [180-09, 180-10, 180-11, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "⚠️ A string do `grid-template-columns` mora numa CASA SÓ, exportada de um `.jsx`, e a linha a IMPORTA. Duas strings divergindo é o bug clássico de tabela em grid: o cabeçalho desalinha das linhas e NENHUM teste de render acusa, porque as duas classes existem. O gate prova que o literal aparece em um arquivo só e que o outro usa o IDENTIFICADOR"
    - "⚠️ `obj[chave]` com chave vinda do servidor exige guarda de TIPO antes — a busca de chave COAGE para string e LANÇA com `{toString: null}`. É a generalização da armadilha que o 180-07 mediu no `hasOwnProperty`: não é o `hasOwnProperty`, é QUALQUER acesso indexado. Aqui há UMA função (`valorDoMapa`) e os 3 usos passam por ela, com contagem travada"
    - "⚠️ Data por `Intl.DateTimeFormat.formatToParts(Date.now())` + aritmética de dias civis (`days_from_civil`), ZERO `new Date(`. O fuso é DECLARADO (`America/Sao_Paulo`) em vez de herdado do navegador, e `agora` é injetável — teste dependente de `now()` deste projeto já reprovou por horário"
    - "⚠️ `textoDaTela()` separado de `textoSeguro()`: `typeof NaN === 'number'`, então o fallback de `textoSeguro` NÃO dispara e a tela escreve a string 'NaN'. Campo que vira DESTINO usa `caminhoSeguro()`, mais estrito ainda (número nunca entra — `textoUtil(7)` já montou rota inválida 3× nesta milestone)"
    - "ZERO `.map()` nos três arquivos, por decisão: quem mapeia as linhas é a página (180-10). É a forma de não cair no buraco do Rollup (eliminação de variável de escopo usada dentro do callback) em vez de contorná-lo — e há gate estrutural que reprova o primeiro `.map(` que alguém escrever, com a mensagem mandando reler o learnings"
    - "A célula Metodologia é `<RotuloDaFase>` SOZINHO: ele JÁ compõe o `<GlyphDaFase>` internamente, e desenhar os dois renderizaria DOIS glyphs na coluna de 136px"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDaTabela.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/LinhaDePublicacao.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/CelulasDaLinha.jsx
    - tests/js/publicador-publicacoes-tabela.test.js
    - .planning/phases/180-publicacoes-lista/180-08-SUMMARY.md
  modified:
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "⚠️⚠️ `pub-row-focus` — NEM (a) NEM (b): O TOKEN JÁ EXISTE. A nota¹ do plano dizia que `#1C1C1C` ainda não tinha nome de fundo e pedia escolher entre usar `bg-pub-line-subtle-2` ou acrescentar `row-focus` ao config. **Medido: `row-focus: '#1C1C1C'` está em `tailwind.config.js:174` e em `PUB_ESPERADOS` (`publicador-tokens-do-publicador.test.js:144`), criado pelo plano 180-01 junto com os outros quatro.** ➡️ Usei `bg-pub-row-focus` e **NÃO toquei** `tailwind.config.js` nem o gate de tokens (os dois seguem fora de `files_modified`, com `git diff` vazio). Há asserção negativa de que `bg-pub-line-subtle-2` NÃO aparece na linha — usar o token de BORDA como fundo é o nome mentindo sobre o uso, e o próprio config adverte"
  - "⚠️ A CÉLULA METODOLOGIA É `<RotuloDaFase>` SOZINHO — divergência da prescrição da tabela FID-01, que mandava `<GlyphDaFase>` + `<RotuloDaFase>`. `RotuloDaFase.jsx` já renderiza o glyph internamente; obedecer renderizaria DOIS glyphs. Medido no protótipo: `:222` é um `flex gap:10px` com UM glyph (`:224`-`:232`) e a coluna de texto (`:233`-`:235`) — exatamente o que `RotuloDaFase` emite no tamanho `lista`. O gate prova que o glyph chegou ao HTML (`rounded-[2.5px]`) E que `GlyphDaFase` não é importado direto"
  - "⚠️ A NOTA ECF 'DE 86' FICOU no `title` da célula Status **e** num `<span class=\"sr-only\">` — e NÃO em `aria-label`, como a prescrição pedia. Motivo medido, não estético: `aria-label` SUBSTITUI o nome acessível calculado a partir do conteúdo, então apagaria 'Pausada' e o motivo para quem usa leitor de tela — ficaria PIOR do que o problema que IDENT-07 quer evitar; e em elemento genérico (`<div>`/`<span>` sem papel) o suporte a `aria-label` não é garantido pela própria ARIA. O `sr-only` é TEXTO de verdade no DOM: é lido em ACRÉSCIMO ao status. O literal `de 86` está na fonte e no HTML, e há negativa contra `de 100` e contra dividir/multiplicar pela base 86"
  - "⚠️ O CHEVRON CEDE O LUGAR nos 64px. Três botões de 28px com `gap:2px` dariam 88px e não cabem. 'Abrir rascunho' é atalho VIVO (LIST-23) e não pode sumir, então quando ele existe o chevron não é desenhado — ele é decoração de 'abre o painel', que é a Fase 181, e a linha inteira já é clicável. Com dois elementos são 58px, dentro dos 64. ⚠️ E o chevron é um `<span>` NÃO interativo, como no protótipo (`:274`) — virá-lo botão prometeria o que ele não faz"
  - "⚠️ `closed` entra no `SeloDeStatusDaPublicacao` como RÓTULO ('Encerrada'), não como chave: o componente de identidade só conhece QUATRO chaves e acrescentar uma quinta exige tocar `identidade/`, que este plano não pode. O componente foi feito para isso (status fora das quatro aparece COMO CHEGOU, com o anel aberto e o cinza neutro). ⚠️ E o SLUG CRU do ML nunca vai para a tela — há asserção para os quatro status"
  - "⚠️ 'Com erro' NÃO é derivado de `severidade`. Ele é o eixo `sub_status` do ML (`AcervoTriagemService::SUB_STATUS_COM_ERRO`) e o item do servidor NÃO manda `sub_status`. Derivar de `severidade` seria INFERIR — um anúncio moderado pode ter `severidade = 0`, medido pelo plano 180-04. A linha mostra o status real e o motivo embaixo; o recorte vive na aba e nos chips. Lacuna escrita em §6"
  - "⚠️ `tipo` fora da lista fechada cai no '—' do `textoSeguro` e NÃO ganha o losango nem o 'Clássico'. Leitura explícita do 'nenhum rótulo' do plano: o que LIST-20 proíbe é inventar um rótulo DE TIPO; '—' é o vocabulário de 'não medido' deste módulo (o fallback do próprio `textoSeguro`), e célula vazia faria o usuário pensar em bug"
  - "⚠️ O MOTIVO sob o status exige o mapa `rotulosDeMotivo` (de `triagem.chips`): `motivos` chega como SLUG (`sem_estoque`) e slug na tela é jargão, que é regra permanente deste projeto. Sem o mapa a 2ª linha NÃO existe — é byte a byte o que `MeusAnuncios.jsx:596` já faz hoje (`if (!info) return null`), logo zero regressão"
  - "⚠️ O CHECK do checkbox é desenhado SEMPRE, ligado ou não, como no protótipo (`:165`). Desligado ele fica `#0A0A0A` sobre `#141414` (contraste ~1,05:1, invisível na prática). Medi e escolhi a FIDELIDADE: o estado quem diz é o `aria-checked`, e renderizar condicionalmente seria 'adaptar o layout novo ao sistema atual'"
  - "⚠️ `rel=\"noreferrer\"` é ADIÇÃO DECLARADA de segurança (T-180-08-T): o protótipo (`:272`) não o tem, e sem ele a aba do ML ganha `window.opener`. Mesma disciplina do `permalink` que só vira `href` quando é string começando com `http` (`javascript:` e `ftp://` reprovam)"
  - "⚠️ `margem` é FRAÇÃO, não percentual (`0.1234` → '12,3%'). É o contrato dos próprios casos de teste do plano, está no docblock e precisa valer para a Fase 181: mandar `12.34` produziria '1234,0%'"
  - "O número de VENDAS sai sem separador de milhar e o de VISITAS sai com — é o protótipo (`:261` interpola cru, `:262` usa `toLocaleString`). Medido, não uniformizado: FID-02 veta 'ficou mais parecido com o resto'"
  - "ZERO migration, ZERO pacote, ZERO linha de PHP, ZERO deleção, ZERO `.map()`"

patterns-established:
  - "Localização por ÁRVORE em dois níveis: `indicesDasLinhas()` acha as `<div role=\"row\">`, a identidade de cada uma é confirmada pelo `ml_item_id` (único por linha) e `celulasDaLinha()` devolve os 9 filhos DIRETOS por índice absoluto, contando profundidade. Nenhuma asserção localiza por lista de classes — 5 linhas compartilham a MESMA string"
  - "`tagsInternas()` ao lado de `indicesDasTags()`: o bloco de um elemento COMEÇA com a tag dele, então `indicesDasTags(html, celula, 'div')[0]` devolve a CÉLULA e não o filho. Quatro asserções deste gate conferiam o PAI achando que conferiam o FILHO"
  - "Contagem de caso adverso DERIVADA, nunca chutada: `Object.keys(ITEM_CHEIO).length * (FORMAS_ADVERSAS.length + 1)`. Um `>= 500` chutado reprovou o laço COMPLETO de 494"

requirements-completed: [LIST-08, LIST-10, LIST-11, LIST-12, LIST-13, LIST-14, LIST-15, LIST-20, LIST-23, LIST-24, EXMP-01]

metrics:
  duration: "~3h30 (2026-10-13)"
  completed: 2026-10-13
  tasks: 3
  commits: 3
  testes-js-novos: 136
  mutacoes: "9/9 pegas, 0 sobreviventes, 0 recusadas"
---

# Fase 180 Plano 08: a tabela — o grid, a linha de 62/50px e as 7 células — Summary

**O coração da tela de Publicações: o grid literal das nove colunas numa casa só, a linha com os
cinco fundos distintos e a precedência que o protótipo dita, e as sete células onde a tela para de
mentir — o "—" da margem que não chegou, o autor que o servidor diz não conhecer, e o "Legado" que
nasce da ausência de dado e não de um `fase = 0` inventado.**

## O que foi feito

| Task | O que | Commit | Linhas |
|---|---|---|---|
| 1 | `CabecalhoDaTabela.jsx` + `LinhaDePublicacao.jsx` | `f5f88c0a` | +341 / **-0** |
| 2 | `CelulasDaLinha.jsx` (as 7 células) | `40ba569e` | +899 / **-0** |
| 3 | o gate (118 testes), `publicacoes/` de 8 → 11, as 9 mutações | `2fa1c3f9` | +1857 / -11 |

Total dos meus 3 commits: **+3097 / -11**, em **5 arquivos**, todos `.jsx`/`.js`.
**Zero PHP, zero migration, zero pacote, zero deleção** — provado por `git diff --numstat` vazio em
`app/`, `database/`, `routes/`, `composer.json` e `package.json` **commit por commit** (não por range,
porque o range pega a sessão paralela da Fase 184 — ver §9).

---

## (1) ⚠️⚠️ A DECISÃO DO `pub-row-focus`: NEM (a) NEM (b) — ELE JÁ EXISTE

O plano deixou uma decisão em aberto na nota¹, dizendo que `#1C1C1C` ainda não tinha nome de FUNDO e
que eu teria de escolher entre **(a)** usar `bg-pub-line-subtle-2` com um comentário, ou **(b)**
acrescentar `row-focus` ao config e reapontar `PUB_ESPERADOS`.

**Medi antes de decidir, e a premissa está desatualizada:**

| onde | o que está lá |
|---|---|
| `tailwind.config.js:174` | `'row-focus': '#1C1C1C'`, com o comentário explicando por que o hex repete `line-subtle-2` (lá é BORDA) |
| `publicador-tokens-do-publicador.test.js:144` | `'row-focus': '#1C1C1C'` já em `PUB_ESPERADOS` |
| `publicador-tokens-do-publicador.test.js:645` | a nota explicando por que `row-focus` **não** entra no bloco de nome-por-hex (o `#1C1C1C` tem DOIS nomes na camada) |

➡️ **O plano 180-01 criou os CINCO fundos de uma vez** (`row-sel`, `row-focus`, `row-checked`,
`row-new` + o `surface` que já existia), e a nota do plano 180-08 só listou quatro deles.
➡️ **Usei `bg-pub-row-focus`.** `tailwind.config.js` e `publicador-tokens-do-publicador.test.js`
**não foram tocados** — seguem fora de `files_modified` e com `git diff` vazio no RANGE B.
➡️ **Não houve reaponte**, logo não há par `+assert`/`-assert` a declarar neste item.

⚠️ E há asserção negativa de que **`bg-pub-line-subtle-2` não aparece** em `LinhaDePublicacao.jsx`:
usar o token de borda como fundo é o nome mentindo sobre o uso, que é o que o próprio
`tailwind.config.js` adverte.

---

## (2) A tabela FID-01, conferida ITEM A ITEM

Medido com `grep -n` e leitura de `new_layout_publicador/Publicacoes v2.dc.html`, confirmando cada
linha pela **SEMÂNTICA** (nunca pelo número de linha) e **procurando explicitamente o que a tabela
não trouxe**.

> ⚠️ **PLACAR: 40 medidas conferidas, 14 divergências — TODAS de OMISSÃO, todas a favor do
> protótipo. Nenhum valor que a tabela trouxe estava errado.** É o terceiro plano seguido com esse
> padrão exato (180-06: 5 omissões em 27; 180-07: 7 em 34). ➡️ **A regra "procure o que a tabela
> OMITIU" é a única que ainda paga.**
>
> ⚠️ **Mais DUAS divergências de PRESCRIÇÃO** (não do protótipo, e sim do plano sobre o repositório):
> a nota¹ do `row-focus` (§1) e o `<GlyphDaFase>` + `<RotuloDaFase>` da célula Metodologia (§3).

### 2a. O card e o cabeçalho

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| card da tabela | **:162** | `background:#111111;border:1px solid #262626;border-radius:14px;overflow:hidden` | sim |
| **o grid** | **:163** = **:202** | `40px minmax(300px,1fr) 136px 104px 136px 112px 68px 150px 64px` | sim, byte a byte nos dois |
| cabeçalho | **:163** | **`display:grid`**`;align-items:center;height:40px;padding:0 16px;background:#0D0D0D;border-bottom:1px solid #262626;font-size:12px;font-weight:500;color:#8A8A90` | ⚠️ **omitiu `display:grid`** (a tabela trouxe só o `grid-cols-[…]`) |
| checkbox do cabeçalho | **:164** | `16×16;border-radius:4px;border:1px solid {ON #FACC15 / OFF #3F3F46};background:{ON #FACC15 / OFF #141414};`**`padding:0`**`;display:grid;place-items:center;`**`cursor:pointer`** + **`title="Selecionar todas"`** | ⚠️ **omitiu `cursor:pointer`, `padding:0` e o `title`** |
| **check do checkbox** | **:165** | `10×10 viewBox "0 0 16 16"`, `fill:none;stroke:#0A0A0A;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round`, path `M3.5 8.5l3 3 6-7` | ⚠️ **omitido INTEIRO** |
| rótulos | **:166** | `Publicação` · `Metodologia` · `Tipo` · `Status` · `Preço` (right) · `Vendas` (right) · `Publicada` (`pl:18px`) · 9ª vazia | sim, byte a byte |

### 2b. A linha e a célula Publicação

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| a linha | **:202** | `display:grid;align-items:center;min-height:{{rowH}};padding:0 16px;border-top:1px solid #1F1F1F;background:{{r.bg}};`**`cursor:pointer`**`;transition:background .2s` | ⚠️ **omitiu `cursor:pointer`** — a **6ª vez na fase** |
| as duas alturas | **:618** | `rowH: compact ? '50px' : '62px'` | sim |
| **os CINCO fundos** | **:553** | `isSel ? '#221D07' : isFocus ? '#1C1C1C' : checked ? '#18160C' : p.isNew ? '#14120A' : '#111111'` | sim, e a ORDEM é a mesma |
| checkbox da linha | **:203** | idêntico ao `:164`, com `cursor:pointer` | ⚠️ mesma omissão (**7ª/8ª**) |
| célula Publicação | **:206** | `display:flex;align-items:center;gap:12px;min-width:0;padding-left:{{r.indent}};padding-right:16px` | sim |
| recuo agrupado | **:545** | `indent: grouped ? '30px' : '0px'` | sim |
| tile da linha | **:208** | `36×36;border-radius:8px;font-size:11.5px;font-weight:600;flex:none` | sim (⚠️ a escada do `TileDoProduto` dá raio **9** em 36px; passei `raio={8}`) |
| **coluna do título** | **:210** | `display:flex;flex-direction:column;gap:3px;min-width:0` | ⚠️ **wrapper OMITIDO pela tabela** |
| título | **:211** | `13.5px/500` + `nowrap/hidden/ellipsis` | sim |
| meta | **:212** | `gap:6px;11.5px;#8A8A90;min-width:0;overflow:hidden` | sim |
| MLB | **:213** | `font-family:'Geist Mono';flex:none` | sim |
| botão de copiar | **:214** | `18×18;border-radius:4px;`**`background:transparent`**`;padding:0;display:grid;place-items:center;color:#71717A;`**`cursor:pointer`**, hover `#262626`/`#F4F4F5` | ⚠️ **omitiu `background:transparent` e `cursor:pointer`** (**9ª**) |
| ícone COPIADO | **:215** | `11×11 viewBox 16;`**`fill:none`**`;stroke:#4ADE80;stroke-width:2.2;`**`stroke-linecap:round`**`;`**`stroke-linejoin:round`** | ⚠️ **omitiu `fill:none` e os dois `stroke-line*`** |
| ícone de copiar | **:216** | `11×11 viewBox 16;`**`fill:none`**`;stroke:currentColor;stroke-width:1.6;`**`stroke-linejoin:round`** — ⚠️ **SEM `stroke-linecap`** | ⚠️ **omitiu `fill:none` e o `linejoin`, e a AUSÊNCIA do `linecap` é a assimetria que importa** |
| badge NOVA | **:217** | `10px/700;letter-spacing:0.06em;#0A0A0A;#FACC15;padding:2px 6px;raio 4;flex:none` | sim (`<BadgeNova>` bate) |
| nome do produto | **:218** | `<span style="color:#52525B;`**`flex:none`**`">·</span>` + nome com ellipsis e **`min-width:0`** | ⚠️ **omitiu `flex:none` e `min-width:0`** |

> ⚠️ **A assimetria do `:215` × `:216` é o tipo de medida que só aparece quando se ABRE o arquivo:**
> o check (traço aberto) tem `linecap` **e** `linejoin`; o ícone de copiar (retângulo) tem **só
> `linejoin`**. A tabela dizia "SVG inline" nos dois. Reproduzi a assimetria e há asserção
> explícita de que o `:216` **não** declara `linecap`, com a mensagem dizendo "medido, não suposto".

### 2c. Metodologia, Tipo e Status

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| célula Metodologia | **:222** | `display:flex;align-items:center;gap:10px` | sim — e é o que `RotuloDaFase` emite (ver §3) |
| glyph F1 | **:224** | caixa `18×18`, retângulo `10×13;raio 2.5;border 1.5px #D4D4D8` | sim, `GlyphDaFase` tamanho `lista` bate **byte a byte** |
| glyph F2 | **:228** | dois retângulos (`left:1,top:1` com `background:#111111` e `left:6,top:4` com `#FACC15`) | sim, inclusive o fundo opaco de trás |
| glyph Legado | **:231** | `border:1.5px dashed #71717A` | sim (`border-dashed border-pub-txt-4`) |
| rótulo da fase | **:233**-**:235** | coluna `gap:1px`; 1ª `13px/500` (`#A1A1AA` no Legado, `#F4F4F5` nas fases); 2ª `11.5px #8A8A90` | sim |
| literais da fase | **:509**-**:512** | `Fase 1`/`Simples · 1 un.` · `Fase 2`/`Combo · N un.` · `Legado`/`Fora do Publicador` | sim, byte a byte |
| célula Tipo | **:237** | `column;gap:3px;align-items:flex-start` | sim (⚠️ `SeloDoTipo` acrescenta `min-w-0`, que o protótipo não tem — território de outro plano, inócuo, declarado) |
| linha do tipo | **:238** | `gap:5px;13px;font-weight:{600 Premium / 400 Clássico}` | sim |
| losango | **:239** | `7×7;#FACC15;rotate(45deg);raio 1px` | sim |
| tag "Catálogo" | **:242** | `10.5px/500;#A1A1AA;padding:1px 6px;border 1px #2E2E2E;raio 4` | sim |
| célula Status | **:244** | `column;gap:2px;`**`min-width:0`** | ⚠️ **omitiu `min-width:0`** |
| linha do status | **:245** | `gap:7px;13px/500;color:{{r.stColor}}` | sim |
| motivo | **:252** | `11.5px;#8A8A90;ellipsis;padding-left:15px` | sim |

### 2d. Preço, Vendas, Publicada e ações

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| célula Preço | **:254** | `column;gap:2px;align-items:flex-end;font-variant-numeric:tabular-nums` | sim |
| valor | **:255** | `13.5px/500` | sim |
| "Prejuízo" | **:256** | `gap:4px;11.5px/600;#EF4444` + triângulo `10×10` com `fill:#EF4444`, path `M8 1.5L15 14H1z` | sim |
| "Margem x%" | **:257** | `11.5px;#8A8A90` | sim |
| a moeda | **:488** | `(neg ? '− ' : '') + 'R$ ' + toLocaleString('pt-BR', 2, 2)` — **U+2212 e espaço** | ⚠️ **omitida** (a tabela não descreve o negativo) |
| célula Vendas | **:260**-**:262** | `column;items-end;tabular-nums`; valor `13.5px/500` (`#52525B` com 0); 2ª linha `{{visits}} vis.` com `toLocaleString` | sim |
| célula Publicada | **:264** | `gap:8px;padding-left:18px;min-width:0` | sim |
| avatar | **:265** | `24×24;raio 50%;10px/600;flex:none`, tom por `tone()` + **`title="{{r.byName}}"`** | ⚠️ **omitiu o `title`** |
| **coluna do texto** | **:266** | `column;gap:1px;min-width:0` | ⚠️ **wrapper OMITIDO** |
| data | **:267** | `12.5px/500` + ellipsis | sim |
| responsável | **:268** | `11.5px;#8A8A90` + ellipsis | sim |
| célula ações | **:271** | `justify-content:flex-end;gap:2px` | sim |
| botão de ação | **:272** | `28×28;raio 7;display:grid;place-items:center;#8A8A90`, hover `#262626`/`#F4F4F5`, `target="_blank"` | sim (⚠️ **sem `rel`** — adição declarada, §4) |
| ícone do ML | **:273** | `14×14 viewBox 16;`**`fill:none`**`;stroke:currentColor;stroke-width:1.6;`**`linecap/linejoin round`** | ⚠️ **omitiu `fill:none`, `stroke:currentColor` e os dois `stroke-line*`** |
| **o chevron** | **:274**/**:275** | um **`<span>` NÃO interativo** de `28×28;raio 7;#8A8A90` com `14×14 viewBox 16;stroke-width:1.8;linecap/linejoin round`, path `M6 4l4 4-4 4` | ⚠️ **OMITIDO INTEIRO** pela tabela (só citado como decisão em `<interfaces>`) |

### 2e. O que foi medido e NÃO virou classe, com o motivo escrito

- **`padding:0` dos botões** — o *preflight* do Tailwind já emite `button { padding: 0 }`. Classe
  seria redundante, mesma disciplina que o 180-07 aplicou ao `border:none`.
- **`border:none`** dos botões — idem.
- **`transition:background .2s`** → `transition-colors duration-200` (a classe que o plano
  prescreveu; `transition-colors` cobre `background-color` e mais algumas propriedades de cor).
- **`color:#F4F4F5`** herdado — os textos da linha não declaram cor no protótipo (herdam da página);
  onde o componente de identidade declara `text-pub-txt`, é o MESMO hex.

### 2f. ⚠️ DUAS divergências que estão em `identidade/` — e eu NÃO podia consertar

Medidas aqui, registradas para a fase que **possa** tocar `identidade/` (este plano não pode):

| componente | o que o protótipo diz | o que está no código | impacto |
|---|---|---|---|
| `SeloDeStatusDaPublicacao.jsx` — anel de "Em revisão" | **`:250`**: `border:1.6px solid #5F5F68` | `border-pub-neutral-dot` = **`#71717A`** | o anel sai 18 pontos mais CLARO que o medido. ⚠️ **E o token certo existe**: `pub-neutral-ring` (`#5F5F68`) foi criado pelo plano 180-01 **exatamente para isto** (o comentário do config diz "o anel aberto de 'Em revisão' na célula Status da tabela de Publicações") e está **órfão de uso** |
| `SeloDeStatusDaPublicacao.jsx` — ícone de "Com erro" | **`:249`**: triângulo **PREENCHIDO** `#EF4444` com um "!" escuro dentro (`stroke:#0A0A0A;stroke-width:1.8`) | `AlertTriangle` do Lucide, **contorno**, `text-pub-danger` | a forma do status mais grave é contorno onde o protótipo quer massa sólida |

➡️ **Não toquei em nenhum dos dois** (`git diff` vazio em `identidade/` no RANGE A). O conserto é
um plano que declare `identidade/` em `files_modified` — e é **aditivo** nos dois casos (trocar uma
classe e trocar um ícone por SVG inline), sem mexer em prop nem em asserção de outras telas.

---

## (3) ⚠️ A célula Metodologia: a prescrição renderizaria DOIS glyphs

A tabela FID-01 e o `key_links` do plano mandavam a célula ser
`flex items-center gap-[10px]` + `<GlyphDaFase>` + `<RotuloDaFase>`.

**Medido em `RotuloDaFase.jsx`: ele JÁ renderiza `<GlyphDaFase>` internamente** — o `return` dele é
`<span className="inline-flex items-center gap-[10px]"><GlyphDaFase …/><span>nome + complemento</span></span>`.
Obedecer a prescrição desenharia **dois glyphs** na coluna de 136px.

➡️ A célula é `<RotuloDaFase fase quantidade tamanho="lista" className="flex" />`. O `className`
troca o `inline-flex` por `flex` via `tailwind-merge` (a célula é item do grid e precisa ser bloco);
o resto da geometria é a do componente, que bate com o `:222` medido.
➡️ O gate prova as duas coisas: que o glyph **chegou** ao HTML (`rounded-[2.5px]`, exclusivo dele) e
que `GlyphDaFase` **não** é importado direto (nenhum redesenho, nenhuma duplicata).

---

## (4) As regras em que a tela parou de mentir

| requisito | o que foi implementado | o que NÃO foi aceito |
|---|---|---|
| **LIST-12** | `margem` ausente → **`—`** (U+2014, asserto pelo code point); `margem < 0` → "Prejuízo" com o triângulo PREENCHIDO (forma + cor + texto); `margem >= 0` → `Margem 12,3%` com **vírgula** | "Margem 0,0%", "R$ 0,00" no lugar de ausência, tarifa estimada por percentual |
| **LIST-12** (preço) | `preco: null` → `—`; `preco: 0` → `R$ 0,00` | zero inventado — "nunca medimos" ≠ "medimos e é zero" |
| **LIST-13** | `autor_nome` como veio; os 4 literais (`Equipe`, `Cliente`, `Origem antiga`, `Autor não registrado`) são TEXTO; `autor_tipo: 'desconhecido'` → avatar com ícone genérico | `iniciais('Autor não registrado')` = **`'AN'`**, que PARECE um nome; `—` no lugar do literal; o nome de outra linha |
| **LIST-13** (data) | `publicada_em` ausente → a data **não aparece** | `—`, `Invalid Date`, "Hoje" sem saber que dia é hoje |
| **LIST-20** | `fase` nula/ausente → **Legado**; `fase: 2` sem kit ≥ 2 → só "Combo"; `tipo` fora da lista → `—` sem losango | `Combo · NaN un.`, `Combo · 1 un.`, cair no Clássico, inferir do TÍTULO (há caso com o título cheio de "Kit 2 Combo Fase 2 Premium Catálogo" e `fase: null` → Legado) |
| **LIST-24 / D-22** | `Nota ECF 72 de 86` no `title` **e** no `sr-only`; `nota_ecf` nula → "Nota ECF ainda não avaliada" | `de 100`, `/ 100`, `* 100 / 86`, `0 de 86` |
| **LIST-23** | "Abrir rascunho" FICA; o chevron cede | perder o atalho vivo |
| **EXMP-01** | `permalink` só vira `href` quando é string `http(s)`; sem destino **não há botão** | `href="#"`, `textoUtil(7)` virando `'7'` |
| **D-11** | zero `router.*`, zero `axios`, zero `fetch(`, zero `route(` nos 3 arquivos | qualquer escrita no ML |
| **T-180-08-T** | `rel="noreferrer"` obrigatório (adição declarada; o protótipo não o tem) | `window.opener` na aba do ML |
| **"Não avaliado"** | o selo da coluna Visitas de hoje **não desapareceu do sistema**: vive nos chips de triagem (plano 180-07, já na tela) e no `ModalDetalheAnuncio`, que segue montado. Aqui `visitas: null` simplesmente não desenha a 2ª linha | — |

---

## (5) ⚠️ UM BUG DE RENDER, ACHADO PELO RENDER ADVERSO ANTES DO COMMIT

> ⚠️⚠️ **`textoSeguro(NaN)` DEVOLVE A STRING `'NaN'`.** `typeof NaN === 'number'`, então o fallback
> `'—'` **nunca dispara** — e a tela escreveria literalmente "NaN".

O laço adverso pegou **CINCO campos** antes do commit da Task 2: `titulo`, `ml_item_id`,
`produto_nome`, `autor_nome` e a prop `urlDoRascunho`. **Nenhum era hipotético** — os quatro
primeiros vêm do servidor e o quinto do chamador.

| fix | o que faz | onde se aplica |
|---|---|---|
| `textoDaTela(valor)` | número **finito** vira texto; `NaN`/`Infinity`/objeto/vazio → `null` | título, MLB, nome do produto, autor |
| `caminhoSeguro(valor)` | **só string** não vazia; número NUNCA | `urlDoRascunho` |

⚠️ A armadilha **já está no learnings** (§7, "`textoSeguro(NaN)` devolve a string `'NaN'`") — mas
`textoSeguro`/`textoUtil` continuam sendo o caminho padrão do módulo, e é por isso que ela volta.
`textoDaTela` fica EXPORTADO, com teste próprio e com a mutação implícita no laço adverso.

⚠️ **E a generalização da armadilha do 180-07:** o problema não é o `hasOwnProperty`, é **qualquer
acesso indexado por chave do servidor**. `objeto[chave]` COAGE a chave para string e LANÇA
`TypeError: Cannot convert object to primitive value` com `{toString: null}`. Aqui existem **três**
indexações assim (`tipo`, `status` e a chave do motivo) e as três passam pela MESMA função
`valorDoMapa()`, que tem `typeof chave !== 'string'` na primeira linha — com gate que trava a FORMA
da guarda e a CONTAGEM de usos em 4.

**Resultado final do render adverso: 746 casos (494 de chave do item + 252 de prop) + 18 do
cabeçalho + a prop inteira nula + chave desconhecida → 0 crash, 0 sujeira.**

---

## (6) ⚠️ AS QUATRO LACUNAS DE PROP — MEDIDAS, não presumidas

Medi o item do servidor (`MlbAnuncioController.php:729`-`:805`): são **34 chaves**, e
`preco`, `nova` e `sub_status` **não estão entre elas**.

### 6a. ⚠️ `preco` NÃO VEM DO SERVIDOR — e a coluna Preço fica inteira em "—"

| fato | medida |
|---|---|
| o item do servidor tem `preco`? | **NÃO**. As 34 chaves são `autor_nome autor_tipo buybox_nao_avaliado buybox_status catalogo estoque fase health_ml listing_tier ml_item_id motivos nota_base nota_ecf origem performance_acoes performance_level performance_score permalink produto_id produto_nome produto_sku publicada_em quantidade_kit rascunho_id saude_ml_nao_se_aplica severidade skus status thumbnail tipo titulo vendas visitas visitas_dias` |
| o dado existe no banco? | **SIM**. `MlAcervoItem` tem a coluna `price`, com cast `'decimal:2'` (`:51` e `:101`) |
| consequência hoje | **a célula Preço mostra `—` no valor E na margem, em TODAS as linhas** |

➡️ **O conserto é UMA linha aditiva no controller**, que é território do plano 180-04 e eu não podia
tocar:

```php
'preco' => $item->price,   // ml_acervo_itens.price, cast decimal:2
```

⚠️ **E quando ela chegar, chega como STRING** (`"309.90"`): o cast `decimal:` do Eloquent serializa
em JSON como string, não como número. `numeroMedido()` **já aceita** string numérica de forma
fechada exatamente por isso (com caso de teste: `moedaBRL('1234.56')` → `R$ 1.234,56`), e recusa
`''`, `'abacaxi'`, `NaN`, `Infinity`, objeto, array e booleano.

### 6b. `nova` NÃO VEM — logo `bg-pub-row-new` e o `<BadgeNova>` são inalcançáveis

`nova` também não está nas 34 chaves. Ela viria da mesma fonte que o plano 180-04 declarou
inexistente para a `faixaSucesso`: **não há chave de flash de publicação** no
`HandleInertiaRequests` (9 chaves na lista branca, nenhuma de publicação) e a publicação termina
**assíncrona** (202 + `PublicarRascunhoJob::dispatch()`).

➡️ **Não inventei nada**: o componente lê `item.nova === true`, o fundo e o badge estão
implementados e testados, e com dado real nenhum dos dois acende. A pendência é a MESMA que o plano
180-04 nomeou (uma chave de flash nova, declarada na lista branca) — não é uma pendência nova.

### 6c. `sub_status` NÃO VEM — logo a linha nunca diz "Com erro"

O status da célula sai de `MlAcervoItem.status` (`active|paused|under_review|closed`). **"Com erro"
não é um valor de `status`** — é o eixo `sub_status` do ML
(`AcervoTriagemService::SUB_STATUS_COM_ERRO`), e o item não o manda.

➡️ **Não derivei de `severidade`**, porque seria inferir: o próprio plano 180-04 mediu que **um
anúncio moderado pode ter `severidade = 0`**. A linha mostra o status REAL do ML e o `motivos[0]`
embaixo — nenhuma informação se perde, e o recorte "Com erro" continua na **aba** (que o servidor já
filtra por `sub_status`) e nos **chips**.
➡️ Se o 180-10/181 quiser a linha dizendo "Com erro", o caminho é aditivo:
`'com_erro' => $item->temSubStatusComErro()` no item, e o mapa daqui ganha a 5ª entrada.

### 6d. As TRÊS props que o 180-10 TEM de ligar — e o que acontece se esquecer

| prop | de onde vem | se esquecer |
|---|---|---|
| `rotulosDeMotivo` | `triagem.chips` → `{chave: label}` | **a 2ª linha da célula Status não aparece.** É o mesmo comportamento de hoje (`MeusAnuncios.jsx:596`), mas o motivo deixa de ser visível na linha |
| `hoje` | `hojeEmSaoPaulo()`, exportado daqui | a data cai na forma absoluta COM ano (`13 out 2026`) em vez de "Hoje, 09:05". **Não quebra nada** e é honesto, mas não é o protótipo |
| `urlDoRascunho` | `route('mlb.anuncios.wizard', { company, rascunho })` | **"Abrir rascunho" não é desenhado** (EXMP-01) e o chevron fica no lugar. ⚠️ **Isto quebraria LIST-23 em silêncio** |

⚠️ **A ligação EXPLÍCITA, para o 180-10 não ter de deduzir:**

```jsx
const ROTULOS_DE_MOTIVO = Object.fromEntries(
    (triagem.chips ?? []).map((chip) => [chip.chave, chip.label]),   // ⚠️ no .map() da PÁGINA, não no componente
);
const HOJE = hojeEmSaoPaulo();                                        // uma vez por render

<LinhaDePublicacao
    item={item}
    agrupada={filtros.visao === 'grupo'}
    selecionada={filtros.sel === item.ml_item_id}
    comFoco={focoNoItem === item.ml_item_id}
    marcada={marcados.includes(item.ml_item_id)}
    compacta={densidade === 'compacta'}
    copiada={copiado === item.ml_item_id}
    hoje={HOJE}
    rotulosDeMotivo={ROTULOS_DE_MOTIVO}
    urlDoRascunho={
        item.origem === 'ecf' && item.rascunho_id != null
            ? route('mlb.anuncios.wizard', { company: empresa.id, rascunho: item.rascunho_id })
            : null
    }
    onAbrir={() => abrirPainel(item.ml_item_id)}
    onMarcar={() => alternarMarcado(item.ml_item_id)}
    onCopiar={() => copiarMlb(item.ml_item_id)}   {/* ⚠️ o setTimeout de 1,3 s é AQUI, não no componente */}
/>
```

⚠️ **E o temporizador de 1,3 s é da PÁGINA** (`:557`, `setTimeout(…, 1300)`): o componente só desenha
os dois ícones, e há asserção de que `setTimeout` e o literal `1300` **não existem** nos 3 arquivos
— é o que permite o gate provar os dois estados sem depender de timer.

---

## (7) "O que precisaria estar errado no código para este gate falhar?" — por teste

| teste (sub-testes) | falha se… |
|---|---|
| `o grid das 9 colunas` (5) | um dos 9 valores trocado, a ordem mudada, o literal DUPLICADO num 2º arquivo (o bug clássico do cabeçalho desalinhando), a constante montada por concatenação (que o scanner do Tailwind não vê) ou movida para um `.js` (que o `content` não varre) |
| `os CINCO fundos` (5) | a ordem do ternário trocada; qualquer PAR de fundos uniformizado; o fundo decidido por truthy em vez de `=== true` (rodado com objeto, `1`, `'sim'`, `[]` e `'false'`); o `row-focus` escrito como `bg-pub-line-subtle-2`, o MESMO hex com nome de borda; ou uma linha ganhando DOIS fundos |
| `62 × 50` (4) | `min-h-[62px]` virando `h-[62px]` (e aí a Status de 2 linhas estoura), as duas alturas trocadas, ou qualquer classe a mais/a menos no conjunto COMPLETO da linha |
| `o cabeçalho do grid` (7) | um rótulo parafraseado ("Anúncio" por "Publicação"), a ordem trocada, `Preço`/`Vendas` perdendo o `text-right`, `Publicada` perdendo o `pl-[18px]`, **a 9ª coluna deixando de existir** (e aí tudo desloca uma coluna), o checkbox mudando de medida/cor, ou nascendo desabilitado (`/disabled=/` **com o `=`**) |
| `o — da margem` (5) | a 2ª linha do Preço inventando "Margem 0,0%" ou "R$ 0,00" — ⚠️ **rodado com o item CHEIO** (preço, vendas, visitas, autor todos preenchidos e conferidos no próprio teste) e **fatiado na CÉLULA** por índice absoluto, porque `doesNotMatch(html, /%/)` casaria em `100%`, em `minmax` e nas cores |
| `Prejuízo e vírgula` (3) | o Prejuízo depender só de COR (sem o triângulo), a margem sair com PONTO decimal, ou a unidade virar percentual em vez de fração |
| `o autor` (7) | `iniciais()` no autor desconhecido (e `Autor não registrado` virando "AN"); o literal do servidor virando `—`; o avatar deixando de usar `tomDoProduto` (`oklch`); as iniciais virem de um campo do servidor (fixture com `iniciais: 'ZZ'` **PREENCHIDO**); **o nome de OUTRA linha vazar** (5 linhas com 5 autores distintos, cada uma conferida contra os 4 vizinhos); ou "Hoje" aparecendo sem data |
| `a metodologia` (6) | `fase` nula deixando de ser Legado; `Combo · NaN un.` ou `Combo · 1 un.` aparecendo; a metodologia sendo **inferida do título** (caso com o título cheio dos termos e `fase: null`); ou um `7` sendo carimbado como "Legado" em vez de "—" |
| `o reuso da identidade` (5) | um dos componentes REDESENHADO aqui (geometria do glyph, losango, formas de status, badge); `oklch(` recalculado fora de `identidade.js`; um import apontando para caminho que não existe (`test -e` em cada um); ou o SLUG cru do ML (`active`, `gold`) indo para a tela |
| `copiar o MLB` (5) | o check de copiado perdendo o verde semântico; os dois ícones aparecendo juntos; **o `:216` ganhando um `stroke-linecap` que o protótipo não declara**; o `stopPropagation` sumindo (e aí copiar abriria o painel); ou um `setTimeout`/`1300` nascendo no componente |
| `as ações` (6) | o botão do ML sendo desenhado com `permalink` nulo/`7`/`javascript:`/`ftp://` — ⚠️ **com o item CHEIO, e com ISCA provando que com o permalink real ele ESTÁ lá**; o `rel="noreferrer"` caindo; **"Abrir rascunho" desaparecendo** (LIST-23); 3 slots em 64px; ou o chevron virando `<button>` |
| `a Nota ECF` (4) | `de 86` virando `de 100`; a nota sendo dividida/multiplicada pela base; a informação morando **só** no `title`; ou `nota_ecf` nula virando "0 de 86" |
| `vendas` (5) | `vendas: null` virando "0"; `vendas: 0` perdendo o cinza de desabilitado; ou a 2ª linha nascendo sem visitas |
| `a célula Publicação` (6) | a visão "Por produto" repetindo o tile e o nome que o cabeçalho do grupo já diz; o recuo de 30px caindo; o `·` ficando órfão sem nome depois; ou sobrar um botão de copiar sem MLB para copiar |
| `D-11 / REND-04` (7) | `router.*`/`axios`/`fetch(`/`route(` nascendo; `new Date(`; **um `.map()`** (com a mensagem mandando reler o learnings); `String(`/`JSON.stringify`/`hasOwnProperty`; a guarda do `valorDoMapa` saindo; hex literal; `text-sm`; ou `ecf-*`/`font-display` |
| `render adverso` (6) | qualquer campo ou prop chegando objeto/`{toString:null}`/`NaN`/`Infinity`/nulo/ausente e sendo renderizado como texto, ou derrubando o render — **e foi ele que achou o bug de §5**. Com ISCA provando que o detector não está cego e com `descreve()` que **não lança** com o valor que mata `String()` |
| `o CSS construído` (11) | classe presente com VALOR errado: um dos 9 valores do grid (e o vizinho `140px` provado AUSENTE), um dos CINCO fundos apontando para o `rgb()` de outro (**os 5 conferidos como 5 cores distintas, com `Map` provando que não há repetição**), o raio 14 da tabela, o `stroke`/`fill` sólido mudando de cor, ou meio pixel arredondado |

### E o que NÃO tem gate — declarado, não maquiado

- **A eliminação de variável pelo Rollup** não é reproduzível em `renderToStaticMarkup`. O que existe
  no lugar é o gate **estrutural**: **zero `.map()`** nos três arquivos, com a mensagem explicando o
  motivo. **Lacuna escrita** — se um plano futuro precisar mapear aqui, a regra "toda flag nasce
  DENTRO do callback" volta a valer.
- **O `onClick` da linha e o `stopPropagation` em runtime.** `renderToStaticMarkup` não despacha
  evento. O gate prova a FONTE (os 3 `stopPropagation`) e o teste diz isso no próprio nome.
- **A fonte de `preco`, `nova` e `sub_status`** (§6). Não há teste que diga "o servidor deveria
  mandar isto".
- **As duas divergências de `identidade/`** (§2f): medidas, não consertadas, território proibido.
- ⚠️ **Nenhuma tela renderizada foi vista.** Não abri navegador, não deployei, não toquei no VPS e
  **não li produção** (leitura bloqueada; o `ml_acervo_itens` local está vazio, então todo cenário é
  sintético). Tudo aqui é render de servidor + CSS construído.

---

## (8) ⚠️ QUATRO FUROS DO MEU PRÓPRIO GATE — TRÊS reprovavam o código CERTO

A pergunta obrigatória desmascarou quatro asserções minhas. **O gate nasceu com 10 falhas em 107
testes, e nenhuma delas era defeito do componente.**

| # | o furo | por que mentia |
|---|---|---|
| 1 | `indicesDasTags(html, celula, 'div')[0]` | ⚠️ **devolve a CÉLULA, não o filho** — o bloco COMEÇA com a tag dela. **Quatro asserções conferiam as classes do PAI acreditando conferir as do FILHO.** É a mesma família do `html.indexOf()` pegando o irmão errado: a localização PARECIA por árvore e era por sorte. Fix: `tagsInternas()`, que descarta a própria tag do bloco |
| 2 | `!fonte.includes('String(')` | ⚠️ casa dentro de `toLocale`**`String(`**`'pt-BR')`. É a **3ª variante do substring** (o FIM do nome de OUTRO identificador, a mesma de `w-[` dentro de `shadow-[…]` que o 180-07 mediu). **Reprovava o código certo.** Fix: `/(^|[^\w.])String\(/`, com asserção de que a isca (`toLocaleString(`) continua na fonte |
| 3 | `!fonte.includes('/ 100')` | ⚠️ casa dentro de `Math.floor(anoDaEra / 100)`, o algoritmo de dias civis. **Reprovava o código certo.** Fix: a negativa reancorada na BASE — `/[*/]\s*86\b/`, `/\b86\s*[*/]/` e `/100\s*\)?\s*\/\s*86/` |
| 4 | `assert.ok(casos >= 500, …)` | ⚠️ **número CHUTADO**: são 26 chaves × (18 formas + 1 ausente) = **494**. O gate reprovava o laço COMPLETO. Fix: a contagem DERIVADA do próprio fixture (`Object.keys(ITEM_CHEIO).length * (FORMAS_ADVERSAS.length + 1)`), com `assert.equal` e dois guardas de tamanho |

⚠️ **E um 5º, de outra natureza, cometido ao CONSERTAR o 1º:** o `sed` que reapontou os 13 call
sites de `indicesDasTags(html, ` pegou **também a DECLARAÇÃO da função** (`function
indicesDasTags(html, bloco, nome)`), criando `SyntaxError: Identifier 'tagsInternas' has already
been declared` — o arquivo inteiro deixou de carregar e o `node --test` reportou **1 teste / 1
falha**, não 107. ➡️ **Reparo em massa por `sed` precisa excluir a declaração**, e o sintoma de que
deu errado é a contagem de testes CAIR, não os testes falharem — exatamente o modo de falhar do
`after()` que o learnings §7 registra.

---

## (9) As 9 mutações — 9/9 PEGAS, 0 sobreviventes, 0 recusadas

Contagem do alvo **travada em 1** antes de aplicar (recusa se ≠1), **qual linha mudou** conferida
depois, teste designado rodado, restauração por cópia pristina conferida por `sha256`.
⚠️ **ANSI removido da saída ANTES de filtrar o veredicto** — é a armadilha que fez a campanha inteira
do 180-03 parecer verde sem provar nada.

Base da campanha: **118 testes / 118 pass / 0 fail / 0 skipped** (com o CSS construído FRESCO, para
o bloco de CSS não começar em `skip`).

| # | mutação | arquivo:linha | falhas | reprovou em |
|---|---|---|---|---|
| i | `136px` → `140px` no grid | `CabecalhoDaTabela.jsx:59` | **8** | o grid, o cabeçalho, as alturas (o conjunto de classes), a casa única |
| ii | ordem dos fundos (foco antes de selecionada) | `LinhaDePublicacao.jsx:72` | **3** | os 5 fundos, as 4 condições simultâneas, a precedência par a par |
| iii | `min-h-[62px]` → `h-[62px]` | `LinhaDePublicacao.jsx:48` | **4** | 62×50, as constantes, o conjunto COMPLETO da linha |
| iv | `—` → `0` na margem ausente | `CelulasDaLinha.jsx:704` | **3** | o `—` com item cheio, `null` × `0` |
| v | `Margem 12.3%` (ponto) | `CelulasDaLinha.jsx:221` | **5** | a vírgula, a unidade-fração, `null` × `0` |
| vi | `iniciais(autor_nome)` no autor desconhecido | `CelulasDaLinha.jsx:774` | **2** | o avatar SEM letra |
| vii | `new Date(publicada_em)` em vez da regex | `CelulasDaLinha.jsx:242` | **6** | a data por regex, `nenhum new Date(`, **o render adverso** (`Invalid Date`) |
| viii | botão do ML desenhado com `permalink` nulo | `CelulasDaLinha.jsx:868` | **3** | nenhum botão sem destino, forma inesperada |
| ix | `de 86` → `de 100` | `CelulasDaLinha.jsx:618` | **5** | o literal, o `sr-only`, a negativa de renormalização, a nota ausente |

**`sha256` final IDÊNTICO ao pristino nos três arquivos:** `CabecalhoDaTabela.jsx`
`24c828a72c78…`, `LinhaDePublicacao.jsx` `c6a144dc9d50…`, `CelulasDaLinha.jsx` `510a2c5e43bc…`.

**CONTROLE NEGATIVO da trava de contagem:** três alvos com **8, 8 e 9** ocorrências foram
**RECUSADOS** e o arquivo ficou intacto (`sha256` igual antes e depois) — logo a trava não é
decorativa.

### ⚠️ Nota honesta sobre a campanha

**Durante as mutações o bloco de CSS entra em `skip`**, porque mutar o `.jsx` o deixa mais novo que o
build (o detector de mtime é o que impede medir CSS velho). ➡️ Quem pegou a mutação **i** foi o gate
de **HTML** (igualdade de conjunto de classes), não o de CSS — **reconfirmando a limitação
estrutural dos planos 180-05/06/07: os dois gates são necessários e não se substituem.** Rodei
`npm run build` **depois** da campanha e o bloco de CSS voltou verde (118/118, 0 skipped), com a
asserção de que o grid vizinho `140px` está **AUSENTE** do CSS inteiro.

---

## (10) ⚠️ Os nomes para `ARQUIVOS_NOVOS` — e a contagem ELEVADA

A contagem de `publicacoes/` no gate de vocabulário subiu de **8 → 11**, com os 3 nomes declarados:

```
resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDaTabela.jsx
resources/js/Components/Mlb/Publicador/publicacoes/LinhaDePublicacao.jsx
resources/js/Components/Mlb/Publicador/publicacoes/CelulasDaLinha.jsx
```

➡️ **O plano 180-09 em diante tem de ELEVAR de 11** junto com os arquivos dele. Deixar o número
parado é o que denuncia o esquecimento — e arquivo fora da lista fica **sem** conferência contra
`text-sm`, `ecf-*`, `font-display`, hex literal e breakpoint.

### O par `+assert` / `-assert`

| arquivo | linhas | `+assert` | `-assert` | leitura |
|---|---|---|---|---|
| `tests/js/publicador-vocabulario-novo.test.js` | +18 / **-11** | **0** | **0** | as 11 removidas são **comentário (8)** e **reaponte de valor (3**: o `8,` e as 2 linhas da mensagem). **Nenhuma contém a palavra `assert`** — conferido por `grep -c`. O nome do teste virou "os 11 arquivos" e os 18 sub-testes novos (3 × 6) passaram a rodar: o gate foi de **295 → 313** |
| `tests/js/publicador-publicacoes-tabela.test.js` | +1839 / **-0** (novo) | — | — | — |
| os 3 componentes | +1240 / **-0** | — | — | nenhuma linha removida: os três são novos |

É o caso que o learnings chama de *"gate vazio ao contrário"*: um `grep '^-' | grep assert` acusaria
remoção onde não houve.

⚠️ **`publicador-tokens-do-publicador.test.js` e `tailwind.config.js` NÃO foram tocados** (ver §1) —
os dois seguem com `git diff` vazio, e por isso não entraram em `files_modified`.

---

## (11) Verificação

| gate | resultado |
|---|---|
| `node --test` no gate novo | **118 testes / 118 pass / 0 fail / 0 skipped** |
| `node --test` nos dois gates do plano | **431 / 431 pass / 0 fail / 0 skipped** (118 + 313 do vocabulário) |
| `publicador-publicacoes-filtros.test.js` (180-07) | **verde e INTOCADO** — nada do plano irmão quebrou |
| `publicador-publicacoes-busca.test.js` (180-06) | **verde e INTOCADO** |
| `estrutura-meus-anuncios.test.js` | **verde e INTOCADO** (0 linhas no diff) — o literal `de 86` do `:86` segue valendo, e a cirurgia nos gates da tela é do plano 180-10 |
| `publicador-tokens-do-publicador.test.js` | **verde e INTOCADO** |
| `npm run test:js` **ANTES** (medido antes de tocar em nada) | **4090 / 4089 pass / 1 fail / 0 skipped** — bate com o briefing |
| `npm run test:js` **DEPOIS** | **4226 / 4225 pass / 1 fail / 0 skipped** — delta **+136 testes, +136 pass** (118 do gate novo + 18 do vocabulário), **medido, não inferido** |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido") — **NÃO corrigida**, é conhecida e intencional |
| `npm run build` | exit **0**, 28,7 s (rodado DEPOIS da campanha de mutação, para o bloco de CSS não ficar em `skip`) |
| manifest **pelo JSON** (`--input-type=commonjs` não foi necessário: o script é `.mjs`) | **478 entradas** (idênticas às 478 dos planos 180-05/06/07), **195 páginas** `.jsx`, 11 casando `/publicador/i`; `MeusAnuncios.jsx`, `AnunciosHistorico.jsx` e `Publicador/Editor.jsx` **presentes** — nenhuma página perdida. CSS `assets/app-D4hYbEnW.css`, **222.332 bytes** (eram 221.685: **+647**, as classes novas) |
| `test -e` nos **31** alvos de território | **31/31 existem** — zero caminho fantasma. Usei os caminhos CERTOS registrados pelos planos anteriores: `Components/Publicador/Mesa` (**sem** o `Mlb`), `Pages/Mlb/AnunciosHistorico.jsx` (**fora** de `Publicador/`), `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `app/Services/Publicador/`, `.planning/ROADMAP.md` |
| território RANGE A (`f23f610d..HEAD`) | **0** em `identidade/`, `header/PublicadorHeader.jsx`, `visaogeral/`, `Components/Publicador/Mesa`, `SubAbasDePublicacoes.jsx`, `Editor.jsx`, `AnunciosHistorico.jsx`, `anuncioHistoricoUtils.js`, `AppLayout.jsx`, `app/Support/Publicador`, `MlPublicacaoService.php`, `estrutura-meus-anuncios.test.js`, `publicador-paginas-anuncios-render.test.js`, `estrutura-anuncios-historico.test.js`, `publicador-header-zindex-ordem.test.js` |
| território RANGE B (`c2e32bd2..HEAD`) | **0** em `MeusAnuncios.jsx`, `MlbAnuncioController.php`, `app/Services/Publicador/`, `tailwind.config.js`, `publicador-tokens-do-publicador.test.js` e nos **8** arquivos de `publicacoes/` dos planos 180-05/06/07 |
| **CONTROLE** do gate de território | o MESMO comando vê `173/0`, `168/0`, `899/0`, `1839/0` e `18/11` nos meus 5 — logo não é vazio por construção |
| PHP / migrations / pacotes **NOS MEUS 3 COMMITS** | `git diff --numstat` em `app/ database/ routes/ composer.json composer.lock package.json package-lock.json` → **VAZIO nos três**. ⚠️ Conferido **commit por commit**, não pelo range |
| `package.json` / `package-lock.json` / `composer.*` na **FASE INTEIRA** (`f23f610d..HEAD`) | **vazio** — nenhum pacote em toda a fase |
| deleções nos meus 3 commits | **0 nos três** (`git diff --diff-filter=D`) |
| árvore | limpa nos meus alvos (`git status --short -- resources/js tests/js app/ database/` → vazio) |

### ⚠️ O falso positivo do range — 7º plano seguido

O gate por range acusou **três coisas que não são minhas**, e **conferi autor e hash antes de
acusar**, como o learnings manda:

1. **`tests/js/publicador-publicacoes-filtros.test.js` (+1845)** e
   **`publicador-publicacoes-busca.test.js` (+1445)** aparecem no RANGE A porque **a minha lista os
   classificou no range errado**: nasceram em `09a3ac3e` (`test(180-07)`) e `baf7f757`
   (`test(180-06)`), que são planos **desta** fase — logo pertencem ao RANGE B, onde os dois dão
   **vazio**. Erro meu de classificação, não violação. (É o mesmo erro que o 180-07 cometeu com o
   arquivo do 180-06 — **a lista de territórios precisa ser reclassificada a cada plano**.)
2. **`.planning/ROADMAP.md` (+186/-2)** — **oito** commits, TODOS da sessão paralela da Fase 184 e do
   orquestrador: `6a8eca85`, `dceb295b`, `8e657767`, `53c4da16`, `933b8702` (`docs(184-*)`),
   `98b64c87`, `7a6a694a`, `32e64439` (`docs(roadmap)`/`docs(publicador)`). O learnings já avisa que
   `ROADMAP.md`, `REQUIREMENTS-v26.md` e `STATE.md` **não pertencem a plano nenhum**.

⚠️ **A sessão paralela da Fase 184 commitou `dceb295b` (184-03) e `6a8eca85` (184-04) ENTRE os meus
três**, com PHP e migrations. ➡️ **Por isso a prova de "zero PHP" aqui é commit por commit**, e **a
suíte PHP NÃO foi rodada**: ela mediria o PHP da outra sessão, não o meu, e o diff vazio em
`app/`/`database/`/`routes/` dos meus três commits é a prova que vale.

Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma migration, nenhum pacote, nenhuma
leitura de produção. `ROADMAP.md` e `REQUIREMENTS-v26.md` **não tocados** e `state.advance-plan`
**não executado** — o bloco do `STATE.md` é aditivo.

---

## (12) O que o 180-09 e o 180-10 ligam — e o que NÃO devem fazer

### Para o **180-09** (a linha de grupo e o rodapé)

- **Importe `GRID_DA_TABELA` de `CabecalhoDaTabela.jsx`** se a linha de grupo precisar alinhar com as
  colunas. ⚠️ **Não redeclare o literal** — o gate deste plano prova que ele mora em UM arquivo só e
  reprovaria.
- A linha de grupo do protótipo (`:173`) é **`height:58px`** com `padding:0 16px 0 14px`,
  `background:#151515` (= `pub-raised`) e `border-top:{{g.borderTop}}` — **não** é um grid de 9
  colunas, é um `flex`. Medido, para você não presumir que herda o grid.
- O rodapé é **`:293`** — `height:44px;padding:0 16px;border-top:1px solid #262626;background:#0D0D0D;font-size:12px;color:#8A8A90`,
  com `justify-content:space-between`.
- O estado vazio é **`:286`** (dentro do `sc-if` de `:285`) — `display:flex;flex-direction:column;align-items:center;gap:8px;padding:64px 24px;border-top:1px solid #1F1F1F;text-align:center`,
  com `15px/600` + `13px #A1A1AA max-width:380px text-wrap:pretty` + o botão "Limpar busca e
  filtros" de `height:32px;padding:0 14px;border:1px solid #2E2E2E;border-radius:8px;background:#111111;font-size:12.5px`.
  ⚠️ **Conferi estes dois números por `grep -n` do literal**, depois de errá-los por 3 e por 9
  linhas na primeira escrita — é exatamente o deslocamento de referência que o learnings §1
  descreve.
- ⚠️ **`CLASSE_DO_CARD`** está exportado daqui (`:162`: `rounded-pub-table border-pub-line bg-pub-surface overflow-hidden`) — use-o, não recrie.

### Para o **180-10** (a composição da página)

- As **três props** de §6d (`rotulosDeMotivo`, `hoje`, `urlDoRascunho`) — ⚠️ **esquecer a terceira
  quebra LIST-23 em silêncio**.
- O **`setTimeout(…, 1300)`** do "copiado" é seu (§6d).
- ⚠️ **Não** passe `nova` como prop da linha: ela sai do ITEM (`item.nova`), como no protótipo.
- ⚠️ **Não** derive "Com erro" de `severidade` (§6c).
- ⚠️ **Não** crie z-index acima de 10 nesta fase (regra do 180-07) — o painel de 460px é a Fase 181.
- ⚠️ **A barra flutuante de ação em massa** (`:405`) usa `#1C1C1C` como FUNDO, e **NÃO** é
  `bg-pub-row-focus`: quem a desenhar cria `pub-bar-float`. Dívida registrada pelo 180-01 e
  **reconfirmada aqui** — a barra é da Fase 181 (seleção em lote), não desta.
- ⚠️ A **`criar_combo_url`** do grupo tem a limitação que o 180-04 mediu (não pré-seleciona o
  produto). Decisão nomeada lá, não aqui.

---

## Self-Check: PASSED

| o que | resultado |
|---|---|
| os 6 arquivos declarados existem no disco (`test -f`) | **6/6 FOUND** |
| os 3 commits existem (`git log --oneline --all \| grep`) | **3/3 FOUND** (`f5f88c0a`, `40ba569e`, `2fa1c3f9`) |
| deleções em cada commit (`git diff --diff-filter=D`) | **0 nos três** |
| as contagens de linha da SUMMARY batem com o disco (`wc -l`) | **sim**: 173 / 168 / 899 / 1839 |
| `min_lines` do plano (`LinhaDePublicacao` ≥ 120, `CelulasDaLinha` ≥ 150) | **168** e **899** |
| `key_links` do plano (`from '\.\./identidade/`) | **6 imports**, todos com `test -e` no alvo |
| arquivo gerado/não rastreado deixado para trás (`git status --short` nos meus diretórios) | **nenhum** — os 5 scripts de smoke/campanha/manifest/território foram apagados |
| `STATE.md` aditivo | **+21 / -0** por `git diff --numstat` |
| as linhas do protótipo citadas para o 180-09 | **reconferidas por `grep -n` do literal** — eu havia escrito `:290` e `:277`, e os certos são `:293` e `:286` |
