---
phase: 180-publicacoes-lista
plan: 07
subsystem: ui
tags: [publicador, publicacoes, chips-de-filtro, popover, triagem, fidelidade, render-adverso, mutacao, sonda-css, z-index]

requires:
  - phase: 180-publicacoes-lista
    plan: "01"
    provides: "o token `pub-line-off-2` (#2A2A2A), que existe EXATAMENTE para a borda OFF dos chips-toggle deste plano, mais `pub-sub` (10px), `pub-control-sm` (7px), `pub-badge` (4px), `pub-table` (14px), `pub-line-off`, `pub-txt-off`, `pub-warn`/`pub-warn-txt`, `pub-danger`/`pub-danger-txt`, `pub-ok-txt` e os tamanhos 10/11.5/12.5/13/15 em `TAMANHOS_PERMITIDOS`"
  - phase: 180-publicacoes-lista
    plan: "04"
    provides: "as props `filtros.{fase,tipo,catalogo,com_venda,responsavel,periodo,motivo}` SANEADAS por lista fechada (`fase` como INT em `[1,2]`, `:596`; `tipo` em `[classico,premium]`, `:602`; `periodo` em `[hoje,7d,30d]`, `:636`; `responsavel` como `array<int,int>` positivos, `:618`-`:626`) e `triagem` = `{total, chips, nao_avaliado}`"
  - phase: 180-publicacoes-lista
    plan: "06"
    provides: "a CORREÇÃO que este plano confirmou (o `seg()` é `0.16` SEM borda e o `font-weight:600` é dele, não do toggle), o molde do harness de render real, o `descreve()` na mensagem de asserção, a sonda de CSS com as normalizações, a localização por `blocoEm()` com profundidade, a decisão do `role=\"group\"` (nenhum radiogroup novo) e a contagem de `publicacoes/` em 5"
  - phase: 177-identidade-visual-do-publicador
    provides: "`identidade.js` — `iniciais()` e `tomDoProduto()`, a ÚNICA casa do `oklch(` (IDENT-08)"
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "o card de triagem de HOJE (`MeusAnuncios.jsx:436`-`:486`) — os literais, o `tabular-nums`, os 3 ícones do Lucide e a disciplina do `triagem.total` que este plano preserva"
provides:
  - "`publicacoes/MenuDeFiltro.jsx` — a casca dos dois popovers: `top:38px`, raio 10, sombra `0 16px 40px rgba(0,0,0,0.6)`, as DUAS larguras medidas (220/190) como classe LITERAL num mapa fechado, `Esc` com cleanup e backdrop de clique-fora. Exports `LARGURA_RESPONSAVEL`, `LARGURA_PUBLICADA`, `CLASSE_DA_LARGURA`, `CLASSE_DO_POPOVER`, `CLASSE_DO_BACKDROP`, `TECLA_DE_FECHAR`"
  - "`publicacoes/ChipsDeFiltro.jsx` — as 6 famílias do protótipo (Fase, Tipo, Em catálogo, Com vendas, Responsável, Publicada) + 'Limpar filtros', com as DUAS regras de ativo tratadas como distintas. Exports `OPCOES_DE_FASE/TIPO/PERIODO`, os 7 rótulos, as 10 classes, `montarSegmentado`, `montarResponsaveis`, `montarPeriodos`, `idsSelecionados`, `sufixoDeResponsavel`, `sufixoDePeriodo` e — por necessidade medida — `itemDeResponsavel`/`itemDePeriodo`"
  - "`publicacoes/ChipsDeTriagem.jsx` — os 5 motivos de `motivosDef()` + 'Não avaliado' no vocabulário novo, com o total SEMPRE de `triagem.total`. Exports `RESSALVA`, `SEM_PENDENCIA`, `NAO_AVALIADO`, `SUFIXO_SINGULAR`, `SUFIXO_PLURAL`, `TITULO_SEM_TOTAL`, `CORES_DO_MOTIVO`, `COR_PADRAO`, `numeroMedido`, `montarChips`, `sufixoDoTotal`"
  - "`tests/js/publicador-publicacoes-filtros.test.js` — 112 testes: literais byte a byte, os 11 irmãos por ÁRVORE com índices absolutos, render adverso (25 formas × cada prop × cada campo nos 3), z-index com lookahead, LIST-22 por nó do título e sonda de CSS com 2 iscas frescas"
  - "a contagem de `publicacoes/` ELEVADA de 5 para 8 no gate de vocabulário, com os 3 nomes declarados"
  - "DOIS BUGS DE CRASH achados e corrigidos (ver §2) e QUATRO furos do próprio gate (ver §3)"
affects: [180-10, 180-11, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "⚠️ `itemDeResponsavel()`/`itemDePeriodo()` em ESCOPO DE MÓDULO e EXPORTADOS: o menu abre por estado LOCAL e `renderToStaticMarkup` só vê o estado INICIAL, logo os itens NUNCA aparecem no HTML de `ChipsDeFiltro` e o gate mediria NADA da geometria deles. É a mesma limitação do portal, com a saída que a Fase 178 já usou (exportar as peças internas). Continuam sendo helpers que o `.map()` CHAMA — nenhum JSX inline no callback"
    - "⚠️ `valorDaLista(bruto, opcoes, semFiltro)` aceita o INTEIRO além da string: o servidor manda `fase` como `?int` e `tipo`/`periodo` como `?string` com `null`. Sem isso `fase: 1` nunca casaria com a opção `'1'` e o chip ficaria MUDO com o filtro aplicado — é a armadilha do `comDot` em snake_case, ao contrário"
    - "⚠️ A geometria do chip tem UMA casa: `ChipsDeTriagem` IMPORTA `CLASSE_DO_CHIP` de `ChipsDeFiltro` e sobrepõe cor/cursor com `cn()` (tailwind-merge). O gate trava o conjunto FINAL de classes byte a byte, para a mesclagem não ser opaca — e há asserção negativa de que as 4 medidas (`h-[32px]`, `rounded-[8px]`, `px-[11px]`, `text-[12.5px]`) NÃO aparecem na fonte de `ChipsDeTriagem`"
    - "⚠️ Backdrop ANTES do popover no DOM: os dois em `z-10`, no mesmo contexto de empilhamento (o wrapper `relative` não tem `z-index`, logo não cria contexto novo), então quem pinta por cima é quem vem DEPOIS na ordem do documento. Invertido, o backdrop cobriria os próprios itens"
    - "O `useEffect` do `Esc` roda ANTES de qualquer `return`, e o `onFechar` do chamador é `useCallback` com lista vazia — senão o listener seria removido e recolocado a cada render"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/MenuDeFiltro.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/ChipsDeFiltro.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/ChipsDeTriagem.jsx
    - tests/js/publicador-publicacoes-filtros.test.js
    - .planning/phases/180-publicacoes-lista/180-07-SUMMARY.md
  modified:
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "⚠️ CONFIRMADA a correção do 180-06, e são DUAS regras que NÃO se uniformizam: a OPÇÃO SEGMENTADA (`seg()`, `:535`) é `rgba(250,204,21,0.16)` + `font-weight:600` e **SEM borda**; o CHIP-TOGGLE (`on(b)`, `:608`) é fundo `rgba(250,204,21,0.12)` + **borda** `rgba(250,204,21,0.55)` e **sem mudança de peso**. Medido nas duas linhas do protótipo, travado por asserção negativa nos dois sentidos e provado no CSS pelo BYTE do alfa (`#facc1529` × `#facc151f`). **A tabela FID-01 do plano acertou as duas** — o briefing anterior é que estava errado"
  - "⚠️ O SPAN DO SUFIXO EXISTE SEMPRE, inclusive vazio. Medido: no protótipo (`:123`/`:142`) é um `<span>` FIXO cujo conteúdo é `''` sem seleção. Como ele é item flex do botão, o `gap:6px` conta com ele — omiti-lo estreitaria o chip em **6px** no estado OFF (dois gaps viram um). A tabela dizia só 'vazio quando não há seleção', o que é ambíguo"
  - "⚠️ Z-INDEX: transposição de ORDEM, não de valor. O protótipo é header 50 / popover 40 / backdrop 30; aqui o header é `z-30` e o menu lateral do `AppLayout` é `z-40`. Transpor o 40 cru poria o popover EMPATADO com o menu lateral e ACIMA do header, invertendo a ordem que `publicador-header-zindex-ordem.test.js` trava. ➡️ popover e backdrop em **`z-10`**, o slot 'filtros'. Desvio DECLARADO"
  - "⚠️ `algumAtivo` é prop e NÃO é recalculado — e o SERVIDOR NÃO MANDA essa prop hoje. Medido em `MlbAnuncioController.php:1052`-`:1065`: `filtros` tem as 11 chaves de filtro e NENHUM `filtros_ativos`. A lacuna está escrita com a derivação recomendada para o 180-10 (§6a). O componente lê a prop; a trava é a mutação iii + a contagem de 2 ocorrências na fonte"
  - "⚠️ O chip de 'Responsável' só existe COM opção: `opcoes: []` não desenha nada (popover vazio é botão sem destino, EXMP-01). E o servidor **não manda lista de responsáveis hoje** — logo, na prática, o chip NÃO aparece até o 180-10/181 definirem a fonte. Lacuna escrita em §6b, não maquiada"
  - "⚠️ As iniciais do avatar saem de `iniciais()` e NÃO de um campo `iniciais` do servidor (que o contrato do plano previa): duas fontes para as mesmas 2 letras é o que IDENT-08 existe para impedir. Travado por fixture com o campo errado PREENCHIDO (`iniciais: 'ZZ'` → a tela mostra 'AR')"
  - "O bloco de TRIAGEM é desvio DECLARADO de fidelidade (o protótipo não o tem; o critério 14 do ROADMAP manda que ele siga). Cada medida está derivada dos tokens e registrada em §5"
  - "Concordância no SINGULAR corrigida: a tela de hoje escreve '1 anúncios precisam de você'. O vocabulário é o mesmo, só a flexão mudou"
  - "Sem handler o chip de motivo nasce `<span>` e NÃO botão desabilitado: a contagem é informação medida e não pode sumir, mas botão que não filtra é botão sem destino"
  - "`role=\"menu\"` + `menuitemcheckbox`/`menuitemradio` nos popovers — precedente deste projeto (`publicador-produtos-fases.test.js:454`) e **ZERO `role=\"radiogroup\"` novo**, preservando o gate da página que trava em 1"

metrics:
  duration: "~3h (2026-10-13)"
  completed: 2026-10-13
  tasks: 3
  commits: 3
  testes-js-novos: 130
  mutacoes: "11/11 pegas, 0 sobreviventes"
---

# Fase 180 Plano 07: chips de filtro, popovers e chips de triagem — Summary

A linha de chips da tela de Publicações, medida no protótipo e não na tabela: as **6 famílias** do
`.dc.html` (Fase, Tipo, Em catálogo, Com vendas, Responsável, Publicada) + "Limpar filtros", a casca
dos **dois popovers**, e o bloco de **triagem** de hoje traduzido para o vocabulário novo — os dois
convivem, como LIST-22 manda.

## O que foi feito

| Task | O que | Commit | Arquivos |
|---|---|---|---|
| 1 | `MenuDeFiltro.jsx` + `ChipsDeFiltro.jsx` | `fe4bef1f` | +891 / **-0** |
| 2 | `ChipsDeTriagem.jsx` | `e6323198` | +319 / **-0** |
| 3 | o gate (112 testes), `publicacoes/` de 5 → 8, e os 2 bugs de crash corrigidos | `09a3ac3e` | +1893 / -16 |

Total dos meus 3 commits: **+3103 / -16**, em **5 arquivos**, todos `.jsx`/`.js`.
**Zero PHP, zero migration, zero pacote, zero deleção** — provado por `git diff --numstat` vazio em
`app/`, `database/`, `routes/`, `composer.json` e `package.json` **commit por commit** (não só no
range, porque o range pega a sessão paralela — ver §9).

---

## (1) ⚠️ A CORREÇÃO DO 180-06, CONFIRMADA: SÃO DUAS REGRAS DE "ATIVO"

O briefing anterior dizia que o visual de chip ativo é `rgba(250,204,21,0.12)` + borda
`rgba(250,204,21,0.55)`. O plano 180-06 mediu que isso é do **chip de filtro** e avisou que o `seg()`
é diferente. **Medi os dois e ele está certo:**

| regra | onde no protótipo | fundo | borda | peso |
|---|---|---|---|---|
| **opção segmentada** (Fase/Tipo) | `seg()`, **`:535`** | `rgba(250,204,21,0.16)` | ⚠️ **NENHUMA** | **600** quando ativa, 400 quando não |
| **chip-toggle** (catálogo/vendas/responsável/publicada) | `on(b)`, **`:608`** | `rgba(250,204,21,0.12)` | ⚠️ `rgba(250,204,21,0.55)` | ⚠️ **não muda** (o `:114` não declara `font-weight`) |

➡️ **Uniformizar as duas mudaria a cor de 5 controles.** Travado por três provas independentes:

1. **asserção negativa nos dois sentidos**: a opção segmentada não pode ganhar `border` nem
   `border-pub-accent*`; o chip-toggle não pode ganhar `font-semibold` nem `font-medium`;
2. **o BYTE do alfa no CSS construído**: `bg-pub-accent/[0.16]` → `#facc15`**`29`** e
   `bg-pub-accent/[0.12]` → `#facc15`**`1f`**. `0x29 ≠ 0x1f` — e um gate de presença passaria com
   qualquer um dos dois;
3. **contagens travadas**: exatamente **2** `bg-pub-accent/[0.16]` (uma por grupo) e exatamente
   **4** `border-pub-accent/[0.55]` + **4** `bg-pub-accent/[0.12]`.

⚠️ **E a minha tabela FID-01 NÃO estava errada aqui** — o plano trouxe as duas regras separadas e
corretas. Quem estava errado era o briefing.

---

## (2) ⚠️⚠️ DOIS BUGS DE CRASH, ACHADOS PELO RENDER ADVERSO — E UMA ARMADILHA NOVA

O render adverso pegou **dois defeitos reais** que derrubariam a **página inteira** (a família da
tela preta de 07/10/2026), e os dois têm a MESMA causa — uma armadilha que **não está no learnings**:

> ⚠️ **`Object.prototype.hasOwnProperty.call(obj, chave)` LANÇA quando a chave é `{toString: null}`:**
> `TypeError: Cannot convert object to primitive value`. A busca de chave **COAGE o valor para
> string**, exatamente como `String(valor)` — e `String(valor)` LANÇAR com `{toString: null}` **já
> está** no learnings (§7), só não nesse lugar.

| onde | o que acontecia | fix |
|---|---|---|
| `MenuDeFiltro.jsx` — `largura` | `hasOwnProperty.call(CLASSE_DA_LARGURA, largura)` com `largura = {toString: null}` | `Number.isInteger(largura) &&` ANTES |
| `ChipsDeTriagem.jsx` — `chip.cor` | `hasOwnProperty.call(CORES_DO_MOTIVO, chip.cor)` com `cor = {toString: null}` | `corConhecida()`, com `soTexto()` ANTES |

⚠️ **Nenhum dos dois era hipotético:** as duas chaves vêm do servidor (`cor` de `motivosDef()`) ou do
chamador (`largura`), e um campo-objeto chegando ali é precisamente o defeito de 07/10/2026.
➡️ Travados pelas mutações **viii** e **ix**, que reprovaram no render adverso.

⚠️ **Varri os outros usos do padrão:** `AbasDeStatus.jsx:159` (plano 180-06) também usa
`hasOwnProperty.call`, mas lá a chave passou por `soTexto()` antes — **está segura, conferido, não
presumido.** Nada a corrigir em território de outro plano.

---

## (3) ⚠️ QUATRO FUROS DO MEU PRÓPRIO GATE — todos com a pergunta obrigatória

A pergunta *"o que precisaria estar errado no código para este gate falhar?"* desmascarou quatro
asserções minhas. **Duas delas reprovavam o código CERTO** (pior que gate decorativo: faria o
executor seguinte "consertar" o que estava bom), e **uma é um caso NOVO** da família substring:

| # | o furo | por que mentia |
|---|---|---|
| 1 | a negativa de jargão listava `premium`/`classico` | **"Premium" e "Clássico" são os RÓTULOS do protótipo** (`:632`) — a comparação insensível a caixa acusava o rótulo legítimo. Mesma classe do *"Sem oferta" é substring de "Sem ofertas no Portal"* (learnings §5) |
| 2 | `!FONTE_MENU.includes('w-[')` | **vazio ao contrário**: o próprio mapa fechado CONTÉM os dois literais `'w-[220px]'`/`'w-[190px]'`. O gate reprovava o código certo |
| 3 | `bloco.includes('15')` no `<h2>` | o bloco do `<h2>` tem **`text-[15px]` na classe**. Mesma família de `doesNotMatch(/0%/)` casando em "100%" |
| 4 | ⚠️ `w-\[` na contagem de larguras | **`w-[` casa DENTRO de `shado`**`w-[`**`0_16px…]`** → contava 3 em vez de 2. **Caso NOVO:** até agora a família era "substring casa com prefixo de classe" (`font-pub` em `font-pub-mono`) e "com sufixo de atributo" (`href=` em `data-href=`); este é **substring casando dentro de OUTRA classe**, pelo fim do nome |

⚠️ **E um quinto, na SONDA DE CSS, que vem herdado dos planos 180-05/180-06:**

> ⚠️ **O `seletorDe()` não escapava PARÊNTESES.** A lista era `[ ] . : / % ,`. Resultado:
> `shadow-[0_16px_40px_rgba(0,0,0,0.6)]` saía **AUSENTE** com a regra **presente e correta** no CSS
> (medida crua: `.shadow-\[0_16px_40px_rgba\(0\,0\,0\,0\.6\)\]{--tw-shadow: 0 16px 40px rgba(0,0,0,.6)}`).
> **Qualquer valor arbitrário com `rgba(...)`, `calc(...)` ou `var(...)` cai no mesmo buraco.**
> ➡️ O `seletorDe()` deste gate escapa `( )` e há asserção explícita com mensagem dizendo o motivo.
> ⚠️ E o cssnano **comeu o zero da frente** outra vez: `0.6` → `.6` (armadilha iv, num lugar novo).

---

## (4) A tabela FID-01, conferida ITEM A ITEM

Medido com `grep -n` e leitura de `new_layout_publicador/Publicacoes v2.dc.html`, confirmando cada
linha pela **semântica**, e **procurando explicitamente o que a tabela não trouxe**.

> ⚠️ **PLACAR: 34 medidas conferidas, 7 divergências — TODAS de OMISSÃO, todas a favor do
> protótipo. Nenhum valor que a tabela trouxe estava errado.** É o mesmo padrão do 180-06 (5 omissões
> em 27): desde que o planejador passou a medir por script, ela acerta os números e erra por
> silêncio. ➡️ **A regra "procure o que a tabela OMITIU" é a que ainda paga.**

### 4a. A linha e os dois grupos segmentados

| onde (semântica) | linha MEDIDA | medida do protótipo | bate com a tabela? |
|---|---|---|---|
| a linha de chips | **:102** | `display:flex;align-items:center;gap:8px;flex-wrap:wrap` | sim |
| grupo "Fase" / "Tipo" | **:103** = **:109** | `display:flex;align-items:center;height:32px;border:1px solid #2E2E2E;border-radius:8px;background:#111111;overflow:hidden` | sim |
| rótulo do grupo | **:104** = **:110** | `padding:0 10px;font-size:12.5px;color:#8A8A90;border-right:1px solid #262626;height:100%;display:flex;align-items:center` | sim |
| opção do grupo | **:106** = **:112** | `height:100%;padding:0 11px;border:none;background:{{o.bg}};font-size:12.5px;font-weight:{{o.weight}};color:#F4F4F5;`**`cursor:pointer`** | ⚠️ **omitiu `cursor:pointer`** |
| ON/OFF da opção | **:535** | `rgba(250,204,21,0.16)` / `transparent`, `600` / `400`, **sem borda** | sim |
| literais de "Fase" | **:631** | `Todas` · `1 · Simples` · `2 · Combo` | sim, byte a byte |
| literais de "Tipo" | **:632** | `Todos` · `Clássico` · `Premium` | sim, byte a byte |

### 4b. Os quatro chips-toggle

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| chip-toggle | **:114** = **:118** = **:122** = **:140** | **`display:flex;align-items:center`**`;gap:6px;height:32px;padding:0 11px;border:1px solid …;border-radius:8px;background:…;font-size:12.5px;color:#F4F4F5;`**`cursor:pointer`** | ⚠️ **omitiu `display:flex` e `cursor:pointer`** (a tabela trouxe só o `gap:6px`, que implica flex) |
| ON/OFF do chip | **:608** | `[0.55 / #2A2A2A, 0.12 / #111111]` | sim |
| ícone do chip | **:116** / **:119** | `13×13 viewBox "0 0 16 16"`, `fill:none`, **`stroke:currentColor`**, `stroke-width:1.6`, **`stroke-linecap:round`**, **`stroke-linejoin:round`** | ⚠️ **omitiu `fill:none`, `stroke:currentColor` e os dois `stroke-line*`** (dizia só "SVG inline") |
| chevron | **:124** = **:143** | `12×12 viewBox "0 0 16 16"`, `stroke:#8A8A90`, `stroke-width:1.7`, **`stroke-linecap/linejoin:round`** | ⚠️ **omitiu os dois `stroke-line*`** |
| sufixo | **:123** / **:142** + **:635** | `<span style="color:#A1A1AA">` com `'· ' + N` ou `'· ' + rótulo`, e **`''`** sem seleção | ⚠️ **ambíguo: o SPAN existe, vazio** (ver (1) nas decisões) |
| wrapper | **:121** / **:139** | `position:relative` | sim |
| "Limpar filtros" | **:156** (`sc-if`) + **:157** | `height:32px;padding:0 10px;border:none;background:transparent;font-size:12.5px;color:#A1A1AA;text-decoration:underline;text-underline-offset:3px;`**`cursor:pointer`** | ⚠️ **omitiu `cursor:pointer`** |

### 4c. Os dois popovers

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| popover | **:127** / **:146** | `position:absolute;top:38px;left:0;z-index:40;width:220px\|190px;padding:6px;background:#111111;border:1px solid #2E2E2E;border-radius:10px;box-shadow:0 16px 40px rgba(0,0,0,0.6)` | sim (o `z-index` é desvio DECLARADO) |
| item de Responsável | **:129** | `display:flex;align-items:center;gap:10px;width:100%;height:34px;padding:0 8px;border:none;border-radius:7px;`**`background:transparent`**`;font-size:13px;color:#F4F4F5;`**`cursor:pointer`**`;`**`text-align:left`**, hover `#0A0A0A` | ⚠️ **omitiu `background:transparent`, `cursor:pointer` e `text-align:left`** |
| checkbox | **:130** + **:605** | `15×15;border-radius:4px;border:1px solid {ON #FACC15 / OFF #3F3F46};background:{ON #FACC15 / OFF #141414};display:grid;place-items:center` | sim |
| check do checkbox | **:131** | `10×10 viewBox 16;fill:none;stroke:#0A0A0A;stroke-width:2.4` | sim |
| avatar | **:133** | `22×22;border-radius:50%;font-size:10px;font-weight:600`, fundo/texto por `tone()` | sim |
| item de Publicada | **:148** | `display:flex;align-items:center;`**`justify-content:space-between`**`;width:100%;height:34px;padding:0 10px;border-radius:7px;background:{{e.bg}};font-size:13px;cursor:pointer;text-align:left` | ⚠️ **omitiu `justify-content:space-between`** |
| item ATIVO | **:607** | `background:#0A0A0A` (inativo `transparent`) | sim |
| check do ativo | **:150** | `12×12 viewBox 16;stroke:#FACC15;stroke-width:2` | sim |
| literais de "Publicada" | **:606** | `Qualquer data` · `Hoje` · `Últimos 7 dias` · `Últimos 30 dias` | sim, byte a byte |
| backdrop | **:307** | `position:fixed;inset:0;z-index:30` | sim (ordem transposta) |

⚠️ **`cursor:pointer` foi omitido CINCO vezes nesta tabela** (opção do grupo, chip-toggle, "Limpar
filtros", item de Responsável, item de Publicada) — **a quinta vez na fase**, somando às quatro que o
180-06 e os anteriores já registraram. **O protótipo ganha nas cinco.**

### 4d. O que foi medido e NÃO virou classe, com o motivo escrito

- **`border:none` dos botões** — o *preflight* do Tailwind já emite `button { border-style: solid;
  border-width: 0 }`. Classe seria redundante. (Os chips-toggle, que TÊM borda, declaram `border`.)
- **`color:#F4F4F5` do item do popover** — virou `text-pub-txt` (é o mesmo hex), e não foi omissão.
- **`z-index:40` / `z-index:30`** — ⚠️ **transposto como ORDEM e não como valor**, pelo motivo
  escrito em `<interfaces>` e nas decisões. É o único desvio de valor do plano e está declarado.
- **`'8 famílias'` do briefing** — o protótipo e o plano têm **6 famílias** + "Limpar filtros"
  (`:102`-`:158`). Implementei as 6 do protótipo. Se o "8" do briefing contava os chips de triagem
  como família, eles estão lá, no `ChipsDeTriagem`.

---

## (5) O bloco de TRIAGEM — desvio declarado, medida por medida

⚠️ **Este bloco NÃO EXISTE no protótipo.** O critério 14 do ROADMAP manda que ele siga, e LIST-22
manda que fique "ao lado dos chips do README". Cada medida foi derivada dos tokens `pub-*`:

| elemento | medida escolhida | por que esta |
|---|---|---|
| o card | `rounded-pub-table border border-pub-line bg-pub-surface p-[16px]` | o raio da TABELA de Publicações (14px) e a borda de card: é um card da mesma tela |
| título | `text-[15px] font-semibold text-pub-txt`, número em `tabular-nums` | 15px é o tamanho do README mais próximo do `text-base` de hoje; `tabular-nums` é o que a tela de hoje já usa e impede o número "pular" |
| ressalva | `text-[11.5px] text-pub-txt-3` | o menor tamanho do README acima de 11, no cinza de metadado |
| geometria do chip | **importada** de `CLASSE_DO_CHIP` | ⚠️ duas alturas de chip na mesma região da tela seriam visíveis — é o motivo medido, não estética |
| chip `red` | OFF `border-pub-danger/[0.30] bg-pub-danger/[0.05] text-pub-danger-txt` · ON `/[0.50]` + `/[0.15]` | é a escala de hoje (`red-500/30` → `/50`) com o token novo |
| chip `amber` | o mesmo com `pub-warn`/`pub-warn-txt` | idem |
| "Não avaliado" | `ml-auto cursor-default border-dashed border-pub-line-off text-pub-txt-off` + `CircleDashed` | `line-off` é o token das "células tracejadas" e `txt-off` é o de desabilitado: o selo não é clicável |
| sem pendência | `CheckCircle2` em `text-pub-ok-txt` | o verde semântico do README |
| com pendência | `AlertTriangle` em `text-pub-accent` | o `text-ecf-yellow` de hoje, no token novo (FID-04 veta `ecf-yellow`) |

⚠️ **Os 3 ícones são do Lucide** (`viewBox 0 0 24 24`) e **não** SVG inline: estes elementos não
existem no protótipo, logo **não há medida para transpor** — e são os MESMOS três ícones que a tela
de hoje usa, o que preserva o que o usuário já conhece. A regra do `strokeWidth × 1,5` vale para SVG
**do protótipo**, e os 5 SVG deste plano que vêm do protótipo usam o `viewBox "0 0 16 16"` dele.

⚠️ **Comportamento preservado:** com `total: 0` os chips continuam na tela (é o que
`MeusAnuncios.jsx:460` faz hoje). Com dado real todos valem 0 por construção —
`severidade > 0 ⟺ motivos não vazio`, `AcervoTriagemService.php:429`. Não mudei isso porque o plano
manda manter o comportamento e porque esconder os chips ali seria decisão de produto, não de código.

---

## (6) ⚠️ AS DUAS LACUNAS DE PROP — MEDIDAS, não presumidas

### 6a. `algumAtivo`: o servidor NÃO manda, e o plano dizia que mandava

O plano escreveu *"`algumAtivo: boolean, // vem do SERVIDOR; o componente não recalcula"*.
**Medido: não vem.** `MlbAnuncioController.php:1052`-`:1065` monta `filtros` com 11 chaves
(`busca`, `status`, `motivo`, `com_venda`, `fase`, `tipo`, `catalogo`, `responsavel`, `periodo`,
`visao`, `sel`) e **nenhuma** `filtros_ativos` / `algum_ativo`.

➡️ **O componente continua só LENDO a prop** (é o que a mutação iii e a contagem de 2 ocorrências
travam). Quem decide de onde ela vem é o 180-10. **A derivação recomendada, com o literal exato das
listas fechadas do servidor:**

```jsx
<ChipsDeFiltro
    fase={{ valor: filtros.fase, onTrocar: (v) => navegar({ fase: v === 'todas' ? null : v, page: 1 }) }}
    tipo={{ valor: filtros.tipo, onTrocar: (v) => navegar({ tipo: v === 'todos' ? null : v, page: 1 }) }}
    catalogo={{ ligado: filtros.catalogo, onAlternar: () => navegar({ catalogo: !filtros.catalogo, page: 1 }) }}
    comVendas={{ ligado: filtros.com_venda, onAlternar: () => navegar({ comVenda: !filtros.com_venda, page: 1 }) }}
    responsavel={{ selecionados: filtros.responsavel, opcoes: [], onAlternar: (id) => … }}  // ⚠️ ver 6b
    periodo={{ valor: filtros.periodo, onTrocar: (v) => navegar({ periodo: v === 'qualquer' ? null : v, page: 1 }) }}
    algumAtivo={                                   // ⚠️ derivação NO CLIENTE — ver abaixo
        filtros.fase !== null || filtros.tipo !== null || filtros.catalogo === true
        || filtros.com_venda === true || (filtros.responsavel ?? []).length > 0
        || filtros.periodo !== null
    }
    onLimparFiltros={() => navegar({ fase: null, tipo: null, catalogo: false, comVenda: false, responsavel: [], periodo: null, page: 1 })}
/>
```

⚠️ **E isto é dívida, não solução:** a regra fica no cliente e pode divergir do que o servidor
aplica. **O certo é o servidor mandar `filtros_ativos`**, derivado do mesmo saneamento que já roda em
`:596`-`:636`. Escrevo isso aqui e não "resolvo" no componente porque esconder a derivação dentro
dele faria exatamente o que o plano proibiu: duas casas para a mesma regra.
⚠️ **Nota:** `com_venda` (singular) é a chave do servidor e `comVenda` é o nome do parâmetro de URL
(`:573`) — **os dois diferem de propósito** e o 180-10 precisa dos dois.

### 6b. `responsavel.opcoes`: a fonte NÃO existe hoje

Varri `MlbAnuncioController.php` e `app/Services/Publicador/` e **não há prop com a lista de
responsáveis**. O servidor só recebe e aplica os `ids` (`:618`-`:626`, `atorEhDaEquipe():1466`).

➡️ **Com `opcoes: []` o chip simplesmente NÃO é desenhado** (EXMP-01, testado) — o que é honesto:
**na prática o chip de "Responsável" não aparece até alguém definir a fonte.** O caminho existe:
`PainelVisaoGeralService::resolverAtor()` (`:126`-`:218`) já separa `equipe` de `cliente` e já tem os
nomes, e `MlbAnuncioController.php:1473` já exige `equipe === true`. Falta a prop.
⚠️ **T-180-07-I continua respeitado em qualquer caso:** `opcoes` só carrega `{id, nome de
exibição}` — nenhum e-mail, telefone nem papel — e o sufixo do chip é **CONTAGEM** (`· 3`), nunca
nomes. Provado com os 3 nomes PREENCHIDOS nas props e nenhum aparecendo no HTML dos chips.

### 6c. ⚠️ `nao_avaliado` × `naoAvaliado` — a armadilha do `comDot`, ao contrário

O servidor manda **`triagem.nao_avaliado`** em snake_case (`AcervoTriagemService.php:454`); o
contrato do plano nomeia a prop **`naoAvaliado`** em camelCase. ➡️ **Segui o contrato** e o gate
prova que a fonte **não** contém `nao_avaliado` (nenhuma leitura dupla silenciosa).
⚠️⚠️ **PORTANTO, 180-10: `<ChipsDeTriagem {...triagem} />` PERDERIA O SELO EM SILÊNCIO.** A ligação é:

```jsx
<ChipsDeTriagem
    total={triagem.total}
    chips={triagem.chips}
    naoAvaliado={triagem.nao_avaliado}        {/* ⚠️ snake → camel, EXPLÍCITO */}
    motivoAtivo={filtros.motivo}
    onFiltrarMotivo={(chave) => navegar({ motivo: filtros.motivo === chave ? null : chave, page: 1 })}
/>
```

---

## (7) O que o 180-10 liga, e o que ele NÃO deve fazer

A **coluna externa de `gap:12px`** (`:82`) que o 180-06 registrou envolve a linha de controles **e**
esta linha de chips:

```jsx
<div className="flex flex-col gap-[12px]">
    <LinhaDeControles … />
    <ChipsDeFiltro … />          {/* este plano */}
</div>
```

O bloco de triagem **não** entra nessa coluna: ele é um card à parte, acima ou abaixo dela — a
posição é decisão do 180-10, porque o protótipo não tem o bloco.

### O que o 180-10 **NÃO** deve fazer

- **Não** passar `algumAtivo` calculado DENTRO do componente — a derivação é explícita no call site;
- **Não** espalhar `{...triagem}` (ver 6c);
- **Não** desenhar o chip de "Responsável" com `opcoes` inventadas só para ele aparecer;
- **Não** mexer em `publicador-paginas-anuncios-render.test.js:354` — **zero radiogroup novo** aqui
  também, confirmado por asserção nos 3 arquivos e nos 4 HTML;
- ⚠️ **Não** criar z-index acima de 10 nesta fase. O painel de inspeção de 460px (que no protótipo é
  `z-index:70`, ACIMA do header) é a **Fase 181**, e é lá que a ordem pode mudar.

---

## (8) ⚠️ Os nomes para `ARQUIVOS_NOVOS` — e a contagem ELEVADA

A contagem de `publicacoes/` no gate de vocabulário subiu de **5 → 8**, com os 3 nomes declarados:

```
resources/js/Components/Mlb/Publicador/publicacoes/MenuDeFiltro.jsx
resources/js/Components/Mlb/Publicador/publicacoes/ChipsDeFiltro.jsx
resources/js/Components/Mlb/Publicador/publicacoes/ChipsDeTriagem.jsx
```

➡️ **O plano 180-08 em diante tem de ELEVAR de 8** junto com os arquivos dele. Deixar o número
parado é o que denuncia o esquecimento — e arquivo fora da lista fica **sem** conferência contra
`text-sm`, `ecf-*`, `font-display`, hex literal e breakpoint.

### O par `+assert` / `-assert`

| arquivo | linhas | `+assert` | `-assert` | leitura |
|---|---|---|---|---|
| `tests/js/publicador-vocabulario-novo.test.js` | +22 / **-14** | **0** | **0** | as 14 removidas são **comentário (11)** e **reaponte de valor (3**: o `5,` e as 2 linhas da mensagem). **Nenhuma contém a palavra `assert`** — conferido por `grep -c`. O nome do teste virou "os 8 arquivos" e os 18 sub-testes novos (3 × 6) passaram a rodar: o gate foi de **277 → 295** |
| `tests/js/publicador-publicacoes-filtros.test.js` | +1845 / **-0** (novo) | — | — | — |
| os 3 componentes | +1239 / **-2** | — | — | as 2 removidas são as **linhas dos fixes de crash** (§2), substituídas por versões guardadas |

É o caso que o learnings chama de *"gate vazio ao contrário"*: um `grep '^-' | grep assert` acusaria
remoção onde não houve.

---

## (9) As 11 mutações — 11/11 PEGAS, 0 sobreviventes

Contagem do alvo **travada em 1** antes de aplicar (recusa se ≠1), **qual linha mudou** conferida
depois, teste designado rodado, restauração por cópia pristina conferida por `sha256`.
⚠️ **ANSI removido da saída ANTES de filtrar o veredicto** — é a armadilha que fez a campanha inteira
do 180-03 parecer verde sem provar nada.

Base da campanha: **108 testes / 107 pass / 0 fail / 1 skipped** nos dois gates.

| # | mutação | arquivo:linha | reprovou em |
|---|---|---|---|
| i | `border-pub-accent/[0.55]` → `/[0.5]` | `ChipsDeFiltro.jsx:203` | **2** (`chips ON` + geometria) |
| ii | `border-pub-line-off-2` → `border-pub-line-strong` | `ChipsDeFiltro.jsx:206` | **4** |
| iii | `algumAtivo` RECALCULADO no cliente | `ChipsDeFiltro.jsx:716` | **2** (o caso do 9º dos nove) |
| iv | `total` = SOMA dos chips (LIST-22 quebrada) | `ChipsDeTriagem.jsx:232` | **11** |
| v | `total` nulo passando a mostrar `0` | `ChipsDeTriagem.jsx:157` | **5** |
| vi | `z-10` → `z-40` no popover | `MenuDeFiltro.jsx:79` | **6** (inclusive o z-index) |
| vii | `removeEventListener` do cleanup REMOVIDO | `MenuDeFiltro.jsx:138` | **2** |
| viii | guarda `Number.isInteger(largura)` REMOVIDA | `MenuDeFiltro.jsx:155` | **2** (render adverso) |
| ix | guarda `soTexto(cor)` REMOVIDA | `ChipsDeTriagem.jsx:145` | **2** (render adverso) |
| x | `cursor-pointer` FORA do chip-toggle | `ChipsDeFiltro.jsx:200` | **7** |
| xi | span vazio do sufixo OMITIDO | `ChipsDeFiltro.jsx:637` | **2** |

**0 recusadas, 0 sobreviventes.** `sha256` final idêntico ao pristino nos três arquivos:
`ChipsDeFiltro.jsx` `1e36e943e24c…`, `MenuDeFiltro.jsx` `d0e6fca755b4…`,
`ChipsDeTriagem.jsx` `3e24ec6871bb…`.

### ⚠️ Notas honestas sobre a campanha

1. **Quem pegou i e ii foi o gate de HTML, não o de CSS** — reconfirmando a limitação estrutural do
   plano 180-05/180-06 por **dois** motivos: (a) mutar o `.jsx` o deixa mais novo que o CSS e o
   bloco entra em `skip` com a razão escrita (o `skipped 1` da base é a prova de que o detector não
   é decorativo); (b) mesmo com rebuild, `/[0.5]` poderia ter outro dono. ⚠️ **Medi que não tem:**
   `border-pub-accent/[0.5]` e `bg-pub-accent/[0.1]` estão **AUSENTES** do CSS inteiro, e há
   asserção explícita disso. Os dois gates são necessários e não se substituem.
2. **As mutações vii, viii e ix apagam/encurtam linhas**, e o comparador linha-a-linha reportou 38,
   21 e 3 "linhas divergentes" — é **deslocamento**, não 38 edições. O que vale é o alvo, cuja
   **contagem estava travada em 1** e cuja posição foi medida antes de aplicar.
3. **A eliminação de variável pelo Rollup NÃO é reproduzível** em `renderToStaticMarkup` — lacuna
   **declarada**, como no 180-06. O que existe no lugar: o gate **estrutural** sobre a forma literal
   dos quatro `.map()` (JSX inline reprova), a forma `opcaoSegmentada()`/`chipDeMotivo()` como
   declarações de função, e a trava de **5 / 1 / 0** `<button` por arquivo.

---

## (10) "O que precisaria estar errado no código para este gate falhar?" — por teste

| teste (sub-testes) | falha se… |
|---|---|
| `os literais dos chips de filtro` (6) | parafrasear um dos 6 rótulos de família, trocar "1 · Simples" por "Fase 1", perder o `·` (asserção sobre o **code point** U+00B7), traduzir "Qualquer data", mudar "Limpar filtros", ou **vazar nome de pessoa fora do menu** (T-180-07-I, com os 3 nomes PREENCHIDOS) |
| `os literais da triagem` (4) | parafrasear a ressalva, o estado sem pendência ou o selo; **cravar um dos 5 motivos na fonte** (a fonte única é `motivosDef()`); ou escrever o plural com `total: 1` |
| `a geometria da linha e dos grupos` (2) | qualquer medida trocada, classe a mais/a menos, ou o rótulo "Fase"/"Tipo" cair no grupo errado (identidade confirmada pelo TEXTO antes da classe) |
| `as 6 opções segmentadas` (5) | a ativa cair na irmã ERRADA (rodado por índice absoluto, uma asserção por irmã, com a contagem de `bg-pub-accent/[0.16]` travada em 2); a opção ativa **ganhar borda**; ou `fase: 1` (INT, como o servidor manda) deixar de casar com a opção `'1'` |
| `os 4 chips-toggle` (7) | ON/OFF trocados; a borda OFF virar `pub-line-off` (prefixo!) ou `pub-line-strong` (FID-02); o chip ganhar `font-semibold`; **o span vazio do sufixo desaparecer**; o ícone virar Lucide; ou `aria-expanded`/`haspopup` nascer nos chips SEM menu |
| `os wrappers e os dois popovers` (7) | o wrapper perder o `relative`; as larguras se trocarem; a sombra mudar; o backdrop nascer **sem** handler ou **depois** do popover; a largura virar template literal; ou popover vazio desenhar moldura |
| `o listener de Esc TEM cleanup` (4) | tirar o `removeEventListener`; usar o nome antigo `'Esc'`; ou um dos outros 2 arquivos passar a escutar o documento |
| `z-index` (3) | transpor o `z-index:40` cru do protótipo (que aqui inverteria a ordem do header), com extração por **lookahead** porque `z-3` casa DENTRO de `z-30` |
| `"Limpar filtros" só com algumAtivo` (4) | recalcular `algumAtivo` no cliente (negativa rodada com **tudo** ligado), desenhar sem handler, ou desenhar **desabilitado** (`/disabled=/` **com o `=`**) |
| `"Responsável" sem opção` (3) | desenhar o chip com `opcoes: []`; deixar entrar opção com `id` 0/negativo/fracionário/string, sem nome, com nome objeto, ou repetida; ou a string `'7'` contar como o id `7` |
| `os itens do popover de Responsável` (5) | o checkbox mudar de 15px/raio; o ON virar outro amarelo; o marcado ser o errado (contagem de `aria-checked="true"` travada em 1); o avatar deixar de usar `tomDoProduto`; ou as iniciais virem de um campo do servidor (fixture com `iniciais: 'ZZ'` PREENCHIDO) |
| `os itens do popover de Publicada` (3) | o `justify-between` cair; o ativo ser o errado; o check do ativo virar o escuro do checkbox; ou nascer um **2º radiogroup** |
| `LIST-22` (6) | somar os chips (`total: 7` com chips 3+5 → a tela mostraria 8), com o número localizado **no nó do título** por árvore; `null` virar "0"; `0` cair no caso do nulo; ou string/`NaN`/`Infinity`/fracionário/negativo virarem contagem |
| `os chips de triagem` (8) | a geometria ser COPIADA em vez de importada; o ON/OFF de `red`/`amber` se confundirem; `motivoAtivo` marcar o chip errado (rodado para cada um dos 5); `count` nulo inventar "0"; o selo aparecer com 0/`null`; linha vazia nascer; ou cor desconhecida virar CRÍTICO |
| `render adverso` (6) | qualquer prop ou campo chegando objeto/`NaN`/nulo/ausente e sendo renderizado como texto — **e foi ele que achou os dois crashes de §2**. 25 formas adversas × cada prop × cada campo dos 3 componentes, mais a prop inteira nula e uma ISCA provando que o detector não está cego |
| `D-11/REND-04` (7) | `router.*`/`axios`/`fetch(` nascer num dos 3; `new Date()`; JSX inline no `.map()`; `<button` a mais; hex literal; pacote novo; ou `String(`/`JSON.stringify` fora do único uso guardado por `Number.isInteger` |
| `as classes GERAM CSS com o valor certo` (11) | classe presente com VALOR errado — **o alfa pelo BYTE** (0,55=`8c`, 0,12=`1f`, 0,16=`29`, e os 8 dos chips de triagem), o `pub-line-off-2` apontando para outro cinza, a sombra mudar de 16/40, ou o cssnano mudar de forma |

### E o que NÃO tem gate — declarado, não maquiado

- **O runtime do `Esc` e do clique-fora.** `renderToStaticMarkup` não roda `useEffect` nem despacha
  `keydown` — não há DOM. O gate prova a **FONTE** (registra + remove no cleanup) e o teste **diz
  isso no próprio nome**. O comportamento em si é da validação visual.
- **A ABERTURA dos popovers dentro de `ChipsDeFiltro`.** O menu abre por estado local; o render de
  servidor só vê o inicial. Os itens são conferidos pelos helpers exportados (§ padrões) e a ligação
  deles ao `MenuDeFiltro` é provada **estruturalmente** (a forma literal dos `.map()` dentro do
  `<MenuDeFiltro`), não por render. **Lacuna escrita.**
- **A eliminação de variável pelo Rollup** — ver §9, nota 3.
- **A fonte de `algumAtivo` e de `responsavel.opcoes`** — ver §6a e §6b. Não há teste que diga "o
  servidor deveria mandar isto".
- ⚠️ **Nenhuma tela renderizada foi vista.** Não abri navegador, não deployei, não toquei no VPS e
  **não li produção** (leitura bloqueada; o `ml_acervo_itens` local está vazio). Tudo aqui é render
  de servidor + CSS construído.

---

## (11) Verificação

| gate | resultado |
|---|---|
| `node --test` no gate novo | **112 testes / 112 pass / 0 fail / 0 skipped** |
| `node --test` nos 3 gates do plano | **414 / 414 pass / 0 fail / 0 skipped** (112 + 295 do vocabulário + 7 do z-index do header) |
| `publicador-header-zindex-ordem.test.js` | **verde e INTOCADO** (0 linhas no diff) — a ordem filtros < header < menu lateral segue valendo |
| `publicador-publicacoes-busca.test.js` (180-06) | **verde e INTOCADO** — nada do plano irmão quebrou |
| `npm run test:js` **ANTES** (medido antes de tocar em nada) | **3960 / 3959 pass / 1 fail / 0 skipped** — bate com o briefing |
| `npm run test:js` **DEPOIS** | **4090 / 4089 pass / 1 fail / 0 skipped** — delta **+130 testes, +130 pass** (112 do gate novo + 18 do vocabulário), **medido, não inferido** |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` — **NÃO corrigida**, é conhecida e intencional |
| `npm run build` | exit **0**, 31,9 s (rodado DEPOIS da campanha de mutação, para o bloco de CSS não ficar em `skip`) |
| manifest **pelo JSON** (`--input-type=commonjs`) | **478 entradas** (idênticas às 478 dos planos 180-05/180-06), `app.jsx` → `assets/app-DPYNyHeh.js`, css `assets/app-CJCeysx8.css` (221.685 bytes); **195 páginas** `.jsx`; 11 casando `/publicador/i`; `MeusAnuncios.jsx` e `AnunciosHistorico.jsx` **presentes** — nenhuma página perdida |
| `test -e` nos **29** alvos de território | **29/29 existem** — zero caminho fantasma. Usei os caminhos CERTOS registrados pelos planos anteriores: `Components/Publicador/Mesa` (**sem** o `Mlb`), `Pages/Mlb/AnunciosHistorico.jsx` (**fora** de `Publicador/`), `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `app/Services/Publicador/` (os serviços), `.planning/ROADMAP.md` |
| território RANGE A (`f23f610d..HEAD`) | **0** em `identidade/`, `header/`, `visaogeral/`, `Components/Publicador/Mesa`, `SubAbasDePublicacoes.jsx`, `Editor.jsx`, `AnunciosHistorico.jsx`, `anuncioHistoricoUtils.js`, `AppLayout.jsx`, `app/Support/Publicador`, `MlPublicacaoService.php`, `routes/mlb_anuncios.php`, `estrutura-meus-anuncios.test.js`, `publicador-paginas-anuncios-render.test.js`, `estrutura-anuncios-historico.test.js`, `publicador-header-zindex-ordem.test.js`, `HistoricoAnunciosTest.php` |
| território RANGE B (`092f43a2..HEAD`) | **0** em `MeusAnuncios.jsx`, `MlbAnuncioController.php`, `app/Services/Publicador/`, `tailwind.config.js`, `REQUIREMENTS-v26.md`, e nos **5** arquivos de `publicacoes/` dos planos 180-05/180-06 |
| **CONTROLE** do gate de território | o MESMO comando vê `723/0`, `337/0`, `174/0`, `1845/0` e `22/14` nos meus 5 — logo não é vazio por construção |
| PHP / migrations / pacotes **NOS MEUS 3 COMMITS** | `git diff --numstat` em `app/ database/ routes/ composer.json package.json` → **vazio nos três**. ⚠️ Conferido **commit por commit**, e não pelo range, porque o range pega a sessão paralela (abaixo) |
| `package.json` / `package-lock.json` na **FASE INTEIRA** (`f23f610d..HEAD`) | **vazio** — nenhum pacote em toda a fase |
| árvore | limpa nos meus alvos (`git status --short -- resources/js tests/js app/ database/` → vazio) |

### ⚠️ O falso positivo do range — 6º plano seguido, e agora com PHP

O gate por range acusou **três coisas que não são minhas**, e **conferi autor e hash antes de
acusar**, como o learnings manda:

1. ⚠️ **`app/Models/BlingConexao.php`, `BlingVinculo.php`, `app/Services/Bling/TradutorBlingParaPortal.php`
   e DUAS migrations** aparecem em `092f43a2..HEAD`. **São da sessão paralela da Fase 184**, nos
   commits `19f873b0`, `8f1dfc67`, `46886c88`, `fd96476c`, `498525e7`, `30fb586d` e `8e657767` — os
   **sete** intercalados entre os meus três. ⚠️ **É a primeira vez na fase que o falso positivo
   inclui PHP e migration**, e é exatamente por isso que a prova de "zero PHP" aqui é
   **commit por commit**, não por range.
2. **`.planning/ROADMAP.md` (+2/-2)** — commits `53c4da16` e `8e657767`, `docs(184-0X)`, marcando
   checkbox da Fase 184. O learnings já avisa que `ROADMAP.md`, `REQUIREMENTS-v26.md` e `STATE.md`
   não pertencem a plano nenhum.
3. **`tests/js/publicador-publicacoes-busca.test.js` (+1445)** apareceu no RANGE A porque **a minha
   lista o classificou no range errado**: ele nasceu no commit `baf7f757` (`test(180-06)`), que é um
   plano **desta** fase — logo pertence ao RANGE B, onde ele dá **vazio**. Erro meu de
   classificação, não violação.

⚠️ **A suíte PHP (~7 min) NÃO foi rodada, por desnecessária E por não ser medível aqui:** o diff em
`app/`/`database/`/`routes/` dos meus três commits é **vazio** (essa é a prova), e rodar a suíte
mediria o PHP **da sessão paralela**, não o meu. Declarado em vez de maquiado.

Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma migration, nenhum pacote, nenhuma
leitura de produção. `ROADMAP.md` e `REQUIREMENTS-v26.md` **não tocados** e `state.advance-plan`
**não executado** — o bloco do `STATE.md` é aditivo.

---

## Self-Check: PASSED

| o que | resultado |
|---|---|
| os 6 arquivos declarados existem no disco (`test -f`) | **6/6 FOUND** |
| os 3 commits existem (`git log --oneline --all \| grep`) | **3/3 FOUND** (`fe4bef1f`, `e6323198`, `09a3ac3e`) |
| deleções em cada commit (`git diff --diff-filter=D HEAD~1 HEAD`) | **0 nos três** |
| as contagens de linha da SUMMARY batem com o disco (`wc -l`) | **sim**: 174 / 723 / 337 / 1845 |
| `min_lines` do plano (`ChipsDeFiltro` ≥ 120, `ChipsDeTriagem` ≥ 70) | **723** e **337** |
| arquivo gerado/não rastreado deixado para trás (`git status --short` nos meus diretórios) | **nenhum** — os 4 scripts de medição/campanha/manifest e os bundles de smoke foram apagados |
| `STATE.md` aditivo | **+19 / -0** por `git diff --numstat` |
