---
phase: 179-visao-geral-cockpit
plan: 08
subsystem: ui
tags: [react, publicador, visao-geral, banner, desempenho, recentes, atividade, sparkline, fidelidade, tailwind, mutacao, rend-04, vg-18, exmp-01]

requires:
  - phase: 177-identidade-e-header
    provides: "Os 7 componentes de identidade (TileDoProduto, GlyphDaFase, BadgeNova, SeloDeStatusDaPublicacao), `identidade.js` (`normalizar`/`tomDoProduto`), `tempo.js` (`haQuanto`), a camada de tokens `pub-*` e a asserção negativa do ERP só declarado (177-03)"
  - phase: 178-modal-nova-publicacao
    provides: "O molde do gate por igualdade de CONJUNTO de classes, o harness esbuild + react-dom/server e o precedente da PROP ADITIVA com default preservado (a prop `letra` do TileDoProduto)"
  - phase: 179-visao-geral-cockpit
    provides: "179-03: os contratos `banner`/`desempenho`/`recentes`/`atividade` · 179-04: o token `bg-pub-accent-sunken` · 179-05: as 23 props na página · 179-06: o achado do `content` do Tailwind · 179-07: `acoes.js` (`numeroSeguro`/`urlSegura`), as 3 armadilhas de medidor e o padrão da prop de estado inicial"
provides:
  - "resources/js/Components/Mlb/Publicador/visaogeral/recentes.js — literais, a aritmética da sparkline, o rótulo de grupo de data, os verbos, os chips e os 5 view models puros (sem uma classe do Tailwind)"
  - "resources/js/Components/Mlb/Publicador/visaogeral/BannerDeIntegracao.jsx — o banner orientado a dado, só com problema"
  - "resources/js/Components/Mlb/Publicador/visaogeral/FaixaDeDesempenho.jsx — a faixa com os 3 KPIs reais, sem link"
  - "resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesRecentes.jsx — as 5 linhas de publicação recente"
  - "resources/js/Components/Mlb/Publicador/visaogeral/AtividadeRecente.jsx — 4 chips + timeline agrupada por dia"
  - "tests/js/publicador-visao-geral-recentes.test.js — 119 testes, 40 mutações, 119 classes conferidas no CSS construído com 14 iscas"
  - "GlyphDaFase: o tamanho `recentes` (adição pura) · SeloDeStatusDaPublicacao: a prop `corpo` (adição pura)"
affects: [179-09-composicao-da-pagina, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "⚠️ Classe do Tailwind NUNCA num `.js` de `resources/js` — agora com gate de 16 padrões e isca; `ICONES_ATIVIDADE` mora no `.jsx` por isso"
    - "Localizar elemento por uma ÁRVORE com índices ABSOLUTOS (`arvore(html)` → `{nome, bruto, classes, filhos, indice, inicio, fim}`), nunca por `html.indexOf` de classe: esta tela é feita de irmãos idênticos (5 linhas, N eventos, 3 KPIs, 4 chips)"
    - "⚠️ `textoSeguro(NaN)` devolve a STRING 'NaN' — `typeof NaN` é 'number'. Campo de texto do servidor precisa de pré-filtro `finito()` antes do `textoSeguro`"
    - "⚠️ `/href=\"…\"/` sem o `\\s` é VAZIA POR CONSTRUÇÃO: `data-href=\"…\"` casa com ela (a mutação M04 sobreviveu provando isso)"
    - "⚠️ Token SÓLIDO do Tailwind compila para `rgb(R G B / var(--tw-*-opacity, 1))` em DECIMAL, não para hex; só o MODIFICADOR de opacidade vira hex de 8 dígitos. Esperar hex para token sólido dá falsa falta (25 numa rodada)"
    - "Data lida por REGEX, nunca por `new Date()`: `new Date('2026-13-01')` é `Invalid Date` e `new Date('2026-10-08').getDate()` é 7 em BRT"
    - "`doMapa()` com `hasOwnProperty`: `tipo: 'constructor'` devolveria a função `Object` e `className={undefined}` sem erro no console"
    - "Prop ADITIVA no componente de identidade quando a tela mede OUTRA geometria — default = comportamento de hoje byte a byte (molde: a prop `letra` do TileDoProduto)"
    - "Mutação que troca uma string presente TAMBÉM em comentário tem de bater na linha de CÓDIGO: a M33 'sobreviveu' na 1ª rodada porque trocou um comentário de divergência"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/visaogeral/recentes.js
    - resources/js/Components/Mlb/Publicador/visaogeral/BannerDeIntegracao.jsx
    - resources/js/Components/Mlb/Publicador/visaogeral/FaixaDeDesempenho.jsx
    - resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesRecentes.jsx
    - resources/js/Components/Mlb/Publicador/visaogeral/AtividadeRecente.jsx
    - tests/js/publicador-visao-geral-recentes.test.js
  modified:
    - resources/js/Components/Mlb/Publicador/identidade/GlyphDaFase.jsx
    - resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx

key-decisions:
  - "A faixa de Desempenho é `<section>` e NÃO `<a>`, com 3 KPIs e sem a coluna 'Ver desempenho →': a tela Desempenho é a Fase 182 — confirmado pelo usuário em 12/10/2026"
  - "Os SEIS ícones são desenhados à mão com os `path` do protótipo: as seis geometrias do Lucide são outras, medidas arquivo por arquivo em node_modules"
  - "O tipo da linha de recente NÃO usa `SeloDoTipo`: o protótipo o desenha como texto de PESO 600/400 em 11,5px herdado, sem losango e sem tag de catálogo — e o peso é o canal que o próprio componente nomeia como a informação"
  - "O glyph e o status ganharam PROP ADITIVA em vez de serem redesenhados (`tamanho=\"recentes\"` e `corpo`), com o default byte-idêntico"
  - "A forma do status NÃO virou dot de 7px: IDENT-04 ('nunca depender só de cor') é requisito aprovado na Fase 177 e vale para as 4 telas que usam o componente; o TEXTO do status é idêntico nos dois"
  - "A unidade do KPI vai para o `title`, não para a tela: o protótipo CALCULA `k.unit` (`:554`) mas nunca o renderiza (`:294-:306`) — não há tamanho, cor nem posição medidos para ela"
  - "Os três títulos que o protótipo escreve em `<span>` viraram `<h2 m-0>`, como os outros quatro cards da MESMA página: pixel idêntico e a `<section>` ganha nome acessível"
  - "A faixa não nasce sem nenhum KPI, e a sparkline não nasce sem série: cabeçalho e trilho vazios afirmariam uma medição que ninguém fez (D-10 do 179-07)"
  - "O delta sai `↑ +5` / `↓ -2` — o protótipo formata o positivo com `+` (`:555`) e o negativo já traz o sinal; `↓ -2` é o que ele renderiza"
  - "`premium` e `href` viajam DENTRO do item do view model para o `.map()` ler só o próprio parâmetro (REND-04)"

metrics:
  duration: "~5h"
  completed: 2026-10-13
  tasks: 3
  tests_added: 119
  mutations: 40
  mutations_caught: 40
  mutations_survived: 0
  classes_provadas_no_css: 119
  iscas_da_sonda: 14
  migrations: 0
  pacotes: 0
---

# Fase 179 Plano 08: banner, Desempenho, recentes e atividade Summary

O banner que **só aparece com problema**, a faixa de Desempenho com **3 KPIs reais e nenhum link para
uma tela que não existe**, as 5 publicações recentes e a timeline de atividade com **4 chips** —
reproduzidos do protótipo medida por medida, com **40 mutações reprovadas** e **119 classes conferidas
pelo VALOR no CSS construído**.

## ⚠️ O que mais importa para o 179-09 (leia isto primeiro)

**1. Os CINCO arquivos novos para `ARQUIVOS_NOVOS` de `tests/js/publicador-vocabulario-novo.test.js`:**

```
resources/js/Components/Mlb/Publicador/visaogeral/recentes.js
resources/js/Components/Mlb/Publicador/visaogeral/BannerDeIntegracao.jsx
resources/js/Components/Mlb/Publicador/visaogeral/FaixaDeDesempenho.jsx
resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesRecentes.jsx
resources/js/Components/Mlb/Publicador/visaogeral/AtividadeRecente.jsx
```

Somados aos **dois do 179-06** (`jornada.js`, `JornadaDosProdutos.jsx`) e aos **quatro do 179-07**
(`acoes.js`, `OQueFazerAgora.jsx`, `SaudeDaConta.jsx`, `PublicacoesNoMercadoLivre.jsx`), são **ONZE**
nomes. ⚠️ **Esquecer faz o gate de vocabulário passar por OMISSÃO, sem acusar nada.**

⚠️ **`8.5` continua tendo de entrar em `TAMANHOS_PERMITIDOS` no MESMO commit** — mas **não por causa
deste plano**: **nenhum dos 5 arquivos daqui usa `text-[8.5px]`** (os corpos usados são 10, 11, 11.5,
12, 12.5, 13, 13.5, 14, 15 e 20, todos já na lista). O `8.5` é exigido pelos arquivos do 179-06 e do
179-07 (iniciais do avatar, `:104`/`:191`/`:202`). Medido com `grep`, não presumido.

**2. As props que os quatro componentes esperam:**

| Componente | Props |
|---|---|
| `BannerDeIntegracao` | `banner` (payload do 179-03) |
| `FaixaDeDesempenho` | `desempenho` |
| `PublicacoesRecentes` | `recentes` · `urlPublicacoes` (string\|null) · `agora` (só do teste) |
| `AtividadeRecente` | `atividade` · `chipInicial` (**só do gate**, padrão `'tudo'`) |

⚠️ **`urlPublicacoes` não existe nas 23 props** — é a MESMA pendência que o 179-07 deixou para
`PublicacoesNoMercadoLivre`. Sem ela, a linha de recente e o "Ver todas as publicações →" **não são
`<a>`** (viram `<div>`/desaparecem) e as 5 linhas continuam na tela.
⚠️ **`chipInicial` e `agora` o 179-09 NÃO passa**: `agora` existe para o teste fixar a data e
`chipInicial` para a asserção do filtro não ser vazia por construção (a lacuna que a M22 do 179-06
expôs).

**3. As duas props aditivas que este plano acrescentou aos componentes de identidade** (detalhe
abaixo, com a medida): `GlyphDaFase tamanho="recentes"` e `SeloDeStatusDaPublicacao corpo="…"`.
O default das duas é **byte-idêntico** ao de hoje — as outras 4 telas não mudam.

---

## FID-01 — as medidas: protótipo × implementado

Fonte: `new_layout_publicador/Visao Geral.dc.html`. ⚠️ **As linhas abaixo foram MEDIDAS no arquivo
com `grep -n`**; as do `<action>` do plano estão deslocadas (ver a seção seguinte).

### O banner de integração (`:28-:36`)

| # | Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|---|
| 1 | wrapper | `flex; center; gap:14px; padding:14px 16px; raio 12; 1px rgba(239,68,68,0.4); bg rgba(239,68,68,0.07)` (`:28`) | `flex items-center gap-[14px] rounded-pub-panel border border-pub-danger/[0.4] bg-pub-danger/[0.07] px-[16px] py-[14px]` |
| 2 | tile | `34×34; raio 9; bg rgba(239,68,68,0.14); grid; place-items:center; flex:none` (`:29`) | `grid h-[34px] w-[34px] flex-none place-items-center rounded-[9px] bg-pub-danger/[0.14]` |
| 3 | svg | 16×16, `viewBox 0 0 16 16`, `fill:none; stroke:#F87171; 1.7; round/round`, **DOIS `path`** (`:30`) | o MESMO svg, com `fill-none stroke-pub-danger-txt` e `strokeWidth={1.7}` — ⚠️ **divergência D-1** |
| **3b** | **coluna de texto** | **`flex column; gap:2px; flex:1` (`:32`)** | **`flex flex-1 flex-col gap-[2px]`** — ⚠️ **AUSENTE da tabela do plano** |
| 4 | título | `14px/600; color {banner.color}` (`:33`) | `text-[14px] font-semibold text-pub-danger-txt` |
| 5 | descrição | `12.5px #A1A1AA` (`:34`) | `text-[12.5px] text-pub-txt-2` |
| 6 | CTA | `nowrap; h:34px; padding:0 14px; border:none; raio 8; bg {color}; color #0A0A0A; 13px/600; cursor:pointer` (`:36`) | `flex h-[34px] items-center whitespace-nowrap rounded-[8px] bg-pub-danger-txt px-[14px] text-[13px] font-semibold text-pub-page` — ⚠️ é `<a href>` (**D-2**) |
| — | o `<sc-if>` | `hasBanner` envolve o bloco inteiro (`:27`) | `banner` nulo ⇒ `return null` (**VG-02**) |

### A faixa de Desempenho (`:286-:310`)

| # | Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|---|
| 1 | faixa | **`white-space:nowrap`**; `grid auto repeat(4,minmax(0,1fr)) auto; items:center; gap:0; bg #111111; 1px #262626; raio 16; color #F4F4F5` (`:286`) | `grid items-center whitespace-nowrap rounded-pub-card border border-pub-line bg-pub-surface text-pub-txt` + `grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]` — ⚠️ **D-3/D-4**; o `whitespace-nowrap` **não está na tabela do plano** |
| 2 | bloco do título | `flex; center; gap:12px; padding:16px 22px` (`:287`) | `flex items-center gap-[12px] px-[22px] py-[16px]` |
| 3 | tile | `36×36; raio 10; bg rgba(250,204,21,0.12); grid place-items-center` (`:288`) | `grid h-[36px] w-[36px] place-items-center rounded-pub-sub bg-pub-accent/[0.12]` |
| 4 | svg | 16×16, `viewBox 16`, `fill:none; stroke:#FACC15; 1.7; round`, **DOIS `path`** (`:289`) | o MESMO svg, com `fill-none stroke-pub-accent` — ⚠️ **D-1** |
| **4b** | **coluna do título** | **`flex column; gap:2px` (`:291`)** | **`flex flex-col gap-[2px]`** — ⚠️ **AUSENTE da tabela** |
| 5 | título / sub | `14px/600` · `12px #8A8A90` (`:291`) | `m-0 text-[14px] font-semibold` (⚠️ `<h2>`, **D-5**) · `text-[12px] text-pub-txt-3` |
| 6 | célula do KPI | `flex; center; space-between; gap:12px; padding:12px 20px; border-left 1px #1F1F1F; min-width:0` (`:294`) | `flex min-w-0 items-center justify-between gap-[12px] border-l border-pub-line-subtle px-[20px] py-[12px]` |
| **6b** | **coluna da célula** | **`flex column; gap:4px; min-width:0` (`:295`)** | **`flex min-w-0 flex-col gap-[4px]`** — ⚠️ **AUSENTE**; é o `min-width:0` dela que faz o `truncate` funcionar |
| 7 | label | `12px #8A8A90; overflow hidden; ellipsis` (`:296`) | `truncate text-[12px] text-pub-txt-3` |
| **7b** | **linha do valor** | **`flex; align-items:baseline; gap:6px` (`:297`)** | **`flex items-baseline gap-[6px]`** — ⚠️ **AUSENTE**; sem o `baseline` o delta de 11,5px centraliza no meio do valor de 20px |
| 8 | valor | `20px; line-height:1; 600; -0.02em; tabular-nums; color {valueColor}` (`:297`) | `text-[20px] font-semibold leading-none tracking-[-0.02em] tabular-nums` |
| 9 | delta ↑ / ↓ | `11.5px/500; #4ADE80` / `#F87171`, texto `↑ {delta}` / `↓ {delta}` com `delta = (d>0?'+':'')+d` (`:298`/`:299`, `:555`) | `text-[11.5px] font-medium` + `text-pub-ok-txt`/`text-pub-danger-txt`, texto `↑ +5` / `↓ -2` |
| 10 | sparkline | `width 64 height 26 viewBox "0 0 100 34" preserveAspectRatio none; flex:none; overflow:visible` (`:303`) | atributos idênticos + `flex-none overflow-visible` |
| 11 | área / linha | `fill:rgba(250,204,21,0.10)` (`:304`) · `fill:none; stroke:#FACC15; 2; round/round` (`:305`) | `fill-pub-accent/[0.10]` · `fill-none stroke-pub-accent stroke-2` + `strokeLinejoin`/`strokeLinecap` `round` |
| — | "Ver desempenho →" | coluna final com `border-left` e seta de 11px (`:308`/`:309`) | **NÃO NASCE** — ⚠️ **D-3** |

### Publicações recentes (`:313-:338`)

| # | Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|---|
| 1 | card | `bg #111111; 1px #262626; raio 16; overflow hidden` (`:313`) | `overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface` |
| 2 | cabeçalho | `flex; center; space-between; padding:18px 20px 12px` (`:314`) | `flex items-center justify-between px-[20px] pb-[12px] pt-[18px]` |
| **2b** | **coluna do título** | **`flex column; gap:3px` (`:315`)** — ⚠️ **3px, não 4** | **`flex flex-col gap-[3px]`** — ⚠️ **AUSENTE da tabela** |
| 3 | título / sub | `15px/600` · `12.5px #8A8A90` (`:315`) | `m-0 text-[15px] font-semibold` (**D-5**) · `text-[12.5px] text-pub-txt-3` |
| 4 | link | `nowrap; 12.5px/500; #A1A1AA` (`:317`) | `whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2` |
| 5 | linha | **`white-space:nowrap`**; `grid 40px minmax(0,1fr) 112px 104px; items:center; gap:14px; padding:11px 20px; border-top 1px #1C1C1C; bg {r.bg}`; hover `#161616` (`:319`) | `grid grid-cols-[40px_minmax(0,1fr)_112px_104px] items-center gap-[14px] whitespace-nowrap border-t border-pub-line-subtle-2 px-[20px] py-[11px]` + `bg-pub-row-new`/`bg-transparent` + `hover:bg-pub-hover-2` — ⚠️ o `whitespace-nowrap` **não está na tabela** |
| 6 | tile | `40×40; raio 9; 11.5px/600` (`:320`) | `<TileDoProduto tamanho={40} …/>` — a escada devolve raio 9 e `text-[11.5px]`, **nenhuma prop extra** |
| **6b** | **coluna do meio** | **`flex column; gap:4px; min-width:0` (`:321`)** | **`flex min-w-0 flex-col gap-[4px]`** — ⚠️ **AUSENTE** |
| **6c** | **linha de título** | **`flex; center; gap:8px; min-width:0` (`:322`)** | **`flex min-w-0 items-center gap-[8px]`** — ⚠️ **AUSENTE** |
| 7 | título | `13.5px/500; nowrap; overflow hidden; ellipsis` (`:322`) | `truncate text-[13.5px] font-medium` |
| 8 | badge NOVA | `10px/700; 0.06em; #0A0A0A sobre #FACC15; padding:2px 6px; raio 4; flex:none` (`:322`) | `<BadgeNova />` — **idêntico byte a byte**, nenhuma prop |
| 9 | meta | `flex; center; gap:6px; 11.5px #8A8A90; nowrap; overflow hidden` (`:323`) | `flex items-center gap-[6px] overflow-hidden whitespace-nowrap text-[11.5px] text-pub-txt-3` |
| 10 | glyph F2 | caixa `14×13; relative; flex:none`; dois retângulos `8×10; raio 2; 1.3px #D4D4D8`, trás em `0,0` com `bg #111111`, frente em `5,3` com `bg #FACC15` (`:324`) | `<GlyphDaFase fase={2} tamanho="recentes" />` — ⚠️ **prop ADITIVA, D-6** |
| 11 | glyph F1 | UM retângulo `8×10; raio 2; 1.3px; margin:0 3px` (`:325`) | o mesmo tamanho `recentes`; a caixa de 14px centrando 8px **é** `margin:0 3px` |
| 12 | fase | `color #D4D4D8` (`:326`) | `text-pub-fase1` |
| 13 | tipo | `font-weight:{600 Premium / 400}; color #D4D4D8` (`:326`, `:572`) | `text-pub-fase1` + `font-semibold`/`font-normal` — ⚠️ **D-7: sem `SeloDoTipo`** |
| 14 | MLB | `font-family 'Geist Mono'; 11px` (`:326`) | `font-pub-mono text-[11px]` |
| 15 | os `·` | nós de texto NUS entre os spans, sem espaço (`:326`) | `{'·'}` entre os spans — provado por `</span>·<span` ×2 |
| 16 | status | `flex; center; gap:7px; 12.5px/500; nowrap; color {stColor}` + dot `7×7; 50%; bg {stDot}` (`:328`) | `<SeloDeStatusDaPublicacao status={…} corpo="text-[12.5px]" />` — ⚠️ **prop ADITIVA (D-6) + D-8** |
| 17 | quem/quando | `flex column; gap:2px; items:flex-end`; `12.5px #D4D4D8` e `11.5px #71717A`, os dois nowrap (`:329`) | `flex flex-col items-end gap-[2px]` + `text-[12.5px] text-pub-fase1` + `text-[11.5px] text-pub-txt-4` |
| 18 | vazio | `flex column; items:center; gap:6px; padding:40px 20px; border-top 1px #1C1C1C; text-align:center`; `14px/600` e `12.5px #8A8A90` (`:333`-`:336`) | `flex flex-col items-center gap-[6px] border-t border-pub-line-subtle-2 px-[20px] py-[40px] text-center` + `text-[14px] font-semibold` + `text-[12.5px] text-pub-txt-3` |

### Atividade recente (`:341-:381`)

| # | Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|---|
| 1 | card | igual ao de recentes (`:341`) | `overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface` |
| 2 | cabeçalho | `flex; center; space-between; gap:12px; padding:18px 20px 12px` (`:342`) | `flex items-center justify-between gap-[12px] px-[20px] pb-[12px] pt-[18px]` |
| **2b** | **coluna do título** | **`flex column; gap:3px` (`:343`)** | **`flex flex-col gap-[3px]`** — ⚠️ **AUSENTE** |
| 3 | título / sub | `15px/600` · `12.5px #8A8A90` (`:343`) | `m-0 text-[15px] font-semibold` (**D-5**) · `text-[12.5px] text-pub-txt-3` |
| 4 | invólucro dos chips | `flex; gap:4px` (`:344`) | `flex gap-[4px]` |
| 5 | chip | **`white-space:nowrap`**; `h:26px; padding:0 9px; 1px {border}; raio 999; bg {bg}; color {color}; 12px; **cursor:pointer**` (`:346`) | `h-[26px] cursor-pointer whitespace-nowrap rounded-full border px-[9px] text-[12px]` + ativo `border-pub-line-off bg-pub-seg text-pub-txt` / inativo `border-pub-line bg-transparent text-pub-txt-2` — ⚠️ `whitespace-nowrap` e `cursor-pointer` **não estão na tabela** |
| 6 | corpo | `padding:4px 20px 18px` (`:350`) | `px-[20px] pb-[18px] pt-[4px]` |
| **6b** | **invólucro do grupo** | **`flex column` (`:352`)** | **`flex flex-col`** — ⚠️ **AUSENTE** |
| 7 | rótulo do grupo | `11px/600; 0.08em; #71717A; padding:12px 0 8px` (`:353`) | `pb-[8px] pt-[12px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4` |
| 8 | evento | `grid 30px minmax(0,1fr) auto; gap:12px; position:relative; padding-bottom:16px` (`:355`) — ⚠️ **sem `align-items`** | `relative grid grid-cols-[30px_minmax(0,1fr)_auto] gap-[12px] pb-[16px]` |
| 9 | fio | `absolute; left:14.5px; top:32px; bottom:2px; width:1px; bg #262626`, só com `hasLine` (`:356`, `:590`) | `absolute bottom-[2px] left-[14.5px] top-[32px] w-px bg-pub-line`, só quando `tem_fio` |
| 10 | ícone | `30×30; raio 50%; bg {iconBg}; 1px {iconBorder}; grid place-items-center; color {iconColor}` (`:357`, `:588`) | `grid h-[30px] w-[30px] place-items-center rounded-full border` + o trio do mapa |
| 11 | glifos | svg 14×14 `viewBox 16`, stroke **1.7** (pub, `:358`) · **1.6** (ai, `:359`) · **1.6** (prod, `:360`) · **2** (phase, `:363`) | os MESMOS `d`, desenhados à mão — ⚠️ **D-1** |
| 12 | coluna de texto | `flex column; gap:4px; min-width:0; padding-top:4px` (`:365`) | `flex min-w-0 flex-col gap-[4px] pt-[4px]` |
| 13 | frase | `13px #A1A1AA; line-height:1.4`; ator `#F4F4F5/500`; alvo `#F4F4F5/600` (`:366`) | `text-[13px] leading-[1.4] text-pub-txt-2` + `font-medium text-pub-txt` + `font-semibold text-pub-txt` |
| 14 | meta | `12px #8A8A90` (`:367`) | `text-[12px] text-pub-txt-3` |
| 15 | hora | `12px #71717A; padding-top:5px; tabular-nums` (`:375`) | `pt-[5px] text-[12px] tabular-nums text-pub-txt-4` |
| 16 | vazio | `padding:30px 0; text-align:center; 13px #71717A` (`:380`) | `py-[30px] text-center text-[13px] text-pub-txt-4` — ⚠️ **a tabela do plano não dava esta medida** |
| — | miniaturas de 34px | `flex; gap:4px; margin-top:4px` + 6 quadrados `34×34; raio 6; 1px #262626` (`:369`-`:371`) | **NÃO NASCEM** — ⚠️ **D-9** |
| — | link por evento | `nowrap; 12px/500; color {linkColor}; margin-top:2px` (`:373`) | **NÃO NASCE** — ⚠️ **D-10** |
| — | chip "Sistema" | 5º chip, `sys → ['sync']` (`:592`) | **NÃO NASCE** — ⚠️ **D-11** |

---

## ⚠️ As medidas em que o PROTÓTIPO venceu a tabela FID-01 do plano

**Placar: 23 divergências medidas, todas resolvidas a favor do protótipo.** (Fase: 177 → 21 · 178 → 3
· 179-04 → 5 · 179-06 → 31 · 179-07 → 16 · 179-08 → 23. **Em nenhuma a tabela acertou contra o
protótipo.**)

### 1. As 11 referências de linha deslocadas (`arquivo:linha`)

O `<action>` do plano foi escrito contra outra contagem. **Todas** as faixas estão deslocadas.

| O plano cita | `Visao Geral.dc.html` tem | Elemento |
|---|---|---|
| `:29-:36` | **`:28-:36`** | o banner (o **wrapper** está em `:28`, não `:29`) |
| `:284-:301` | **`:286-:310`** | a faixa de Desempenho |
| `:305-:327` | **`:313-:338`** | Publicações recentes |
| `:330-:368` | **`:341-:381`** | Atividade recente |
| `:445` | **`:443-:448`** | o `spark()` |
| `:460` | **`:554`** | os `kpis` (rótulo, unidade, delta) |
| `:365` | **`:589`** | o `dayNames` (`HOJE`/`ONTEM`/`8 OUT`) |
| `:353` | **`:588`** | o `IC` (o trio de cor do ícone) |
| `:384` | **`:594`** | o `banner` da aritmética |
| `:325` | **`:336`** | o texto do vazio das recentes |
| `:315` / `:319` | **`:324`/`:325`** / **`:328`** | o glyph de fase e o status da linha |

⚠️ As referências saíram de `grep -n`, nunca de contagem a partir de `sed` — foi assim que o 179-07
errou 10 referências e o 179-06 errou 29.

### 2. As 2 medidas em que o VALOR da tabela estava errado

| # | A tabela do plano diz | Medido no protótipo | Entregue |
|---|---|---|---|
| **V-1** | *"rótulo do grupo `py-[12px] pb-[8px]`"* numa passagem e `pt-[12px] pb-[8px]` noutra | `padding:12px 0 8px` (`:353`) | `pb-[8px] pt-[12px]` — `py-[12px]` daria **12px embaixo** |
| **V-2** | *"a coluna do título tem `gap:4px`"* (por analogia com os outros cabeçalhos) | `gap:3px` nos DOIS cards (`:315`, `:343`) | `gap-[3px]` |

### 3. As 10 medidas que a tabela OMITIU

| # | O que faltava | Protótipo | Entregue |
|---|---|---|---|
| O-1 | a **coluna de texto** do banner | `flex column; gap:2px; flex:1` (`:32`) | `flex flex-1 flex-col gap-[2px]` |
| O-2 | o `white-space:nowrap` da **faixa** | `:286` (primeira declaração) | `whitespace-nowrap` — é dele que o `truncate` do label herda o nowrap |
| O-3 | a **coluna do título** da faixa | `flex column; gap:2px` (`:291`) | `flex flex-col gap-[2px]` |
| O-4 | a **coluna da célula** do KPI (com o `min-width:0`) | `flex column; gap:4px; min-width:0` (`:295`) | `flex min-w-0 flex-col gap-[4px]` |
| O-5 | a **linha do valor** (`baseline`) | `flex; align-items:baseline; gap:6px` (`:297`) | `flex items-baseline gap-[6px]` |
| O-6 | o `white-space:nowrap` da **linha de recente** | `:319` (primeira declaração) | `whitespace-nowrap` |
| O-7 | a **coluna do meio** e a **linha de título** da recente | `:321` / `:322` | `flex min-w-0 flex-col gap-[4px]` / `flex min-w-0 items-center gap-[8px]` |
| O-8 | o `nowrap` e o `cursor:pointer` do **chip** | `:346` | `whitespace-nowrap` + `cursor-pointer` |
| O-9 | o **invólucro do grupo** da atividade | `flex column` (`:352`) | `flex flex-col` |
| O-10 | as medidas do **vazio da atividade** | `padding:30px 0; text-align:center; 13px #71717A` (`:380`) | `py-[30px] text-center text-[13px] text-pub-txt-4` |

---

## Divergências declaradas, com a restrição concreta

⚠️ **Estas são as mais importantes da fase para o usuário ler.**

| # | O protótipo / o plano diz | O que foi entregue | Restrição concreta |
|---|---|---|---|
| **D-1** | `<Unlink size={16} strokeWidth={2.55} />`, `<BarChart3 …>`, `<Upload …>`, `<Sparkles …>`, `<Package …>`, `<Check …>` (prescrição do plano) | **os SEIS desenhados à mão**, com os `d` do protótipo em `viewBox="0 0 16 16"` e os `stroke-width` medidos (1.7 / 1.7 / 1.7 / 1.6 / 1.6 / 2), **sem conversão ×1,5** | As seis geometrias do Lucide são OUTRAS, medidas uma a uma em `node_modules/lucide-react/dist/esm/icons/`: `unlink` são dois arcos de raio 5 com quatro marcas de tique e **nenhuma diagonal** (o protótipo tem a corrente partida **mais** `M2 2l12 12`, que é o que diz "desconectado"); `bar-chart-3` é apelido de `chart-column`, cujo eixo é um **"L" com canto arredondado** (`M3 3v16a2 2 0 0 0 2 2h16`) — o protótipo desenha **só a base** (`M2.5 13.5h11`) e três barras de outra altura; `upload` tem uma **bandeja** (`M21 15v4a2 2 0 0 1-2 2H5…`) onde o protótipo tem uma linha reta (`M3 13h10`); `sparkles` são **quatro formas** (estrela de junções arredondadas + dois traços + um `<circle>`), o protótipo são duas estrelas de um traço; `package` tem o **vinco lateral** (`m7.5 4.27 9 5.15`) e cantos arredondados; `check` do Lucide é `M20 6 9 17l-5-5`, de proporção diferente do `M3.5 8.5l3 3 6-7`. Mesma saída do **D-1 do 179-07**: quando o protótipo desenha svg próprio, o Lucide não é substituto. O gate trava os 6 `d` por igualdade de string, os `stroke-width` por conjunto, e as asserções negativas `M21 15v4`, `<circle>` = 0 e `lucide` ausente. |
| **D-2** | o CTA do banner é `<button>` (`:36`) | `<a href>`, e **só quando `link_reconexao` existe** | Botão sem destino é clique mudo (EXMP-03). Sem o link, o banner **continua explicando o problema**, que é o essencial do aviso. O `flex items-center` é do `<a>`: o `<button>` centraliza o texto por padrão do UA, um `<a>` de 34px não (D-9 do 179-06). |
| **D-3** | a faixa é `<a href="Desempenho.dc.html">` (`:286`) e tem a coluna final **"Ver desempenho →"** (`:308`) com `border-left` | `<section>`, **sem link, sem a coluna final e sem o `style-hover` de borda** | **A tela Desempenho é a Fase 182 — não existe.** `desempenho.url` vem `null` do servidor de propósito (179-03, a mutação M11 daquele plano reprova quem o preencher), e destino inventado é pior que destino nenhum (EXMP-03). O `style-hover` (`border-color:#3F3F46`) também não nasce: hover de borda num elemento que não é clicável **promete clique**. ✅ **CONFIRMADO PELO USUÁRIO em 12/10/2026** (*"faixa com os 3 KPIs reais, sem link"*), levado com alternativas e aprovado; vira link na Fase 182. Gate: a tag é `section`, zero `<a>`, zero `<button>`, zero `href=`, `Ver desempenho` ausente e nenhum `route(` nas 5 fontes. |
| **D-4** | são **QUATRO** KPIs: `Publicações criadas`, **`Vendas`**, `Publicações com venda`, `Criativos com IA` (`:554`) | **TRÊS**, sem "Vendas" — e sem "Visitas" e sem "Conversão" | Nenhum dos três tem fonte (medido no 179-03): `sold_quantity` do acervo é **acumulado da vida do anúncio**, não do período; `visitas` é **NULL em 1.374.527 linhas**; e **não existe tabela de pedidos**. Número sem fonte é número inventado (VG-18, EXMP-01). Gate: asserções NEGATIVAS de `Vendas`/`Visitas`/`Conversão` rodadas **sobre o payload real de 3 KPIs**, a contagem de células travada em 3, e `KPIS` sem as três chaves. A mutação **M26** (acrescentar um KPI de Vendas) reprova em 9 testes. |
| **D-5** | "Desempenho", "Publicações recentes" e "Atividade recente" são `<span>` (`:291`, `:315`, `:343`) | `<h2 className="m-0 …">` nos três | O protótipo escreve `<h2 style="margin:0">` nos **outros quatro** cards da MESMA página (`:43`, `:168`, `:237`, `:260`) — ele é inconsistente aqui. Com `m-0` e o corpo/peso declarados o **pixel é idêntico** (`<h2>` e `<span>` dentro de um `flex flex-col` são os dois itens de flex), e a `<section>` passa a ter **nome acessível**: um `<span>` deixaria três regiões sem rótulo para leitor de tela. |
| **D-6** | — | **DUAS PROPS ADITIVAS** nos componentes de identidade | **`GlyphDaFase`: `tamanho="recentes"`** — o protótipo desenha o glyph da linha com caixa `14×13` e retângulos `8×10` de `raio 2` e `borda 1.3px` (`Visao Geral.dc.html:324`/`:325`), contra caixa `18×18`, retângulo `10×13`, raio `2.5` e borda `1.5` do tamanho `lista` (medido em `Publicacoes v2.dc.html:225-231`). **`SeloDeStatusDaPublicacao`: `corpo`** — o status da linha mede **12,5px** (`:328`) contra os 13px da tabela de Publicações (`Publicacoes v2.dc.html:246`). Os dois estão certos: cada um foi medido onde está, e FID-03 proíbe arredondar meio pixel. Molde: a prop `letra` do `TileDoProduto` (Fase 178). ⚠️ **O default é byte-idêntico** (`cn('flex items-center gap-[7px]', tamanhoDoTexto, 'font-medium', cor)` produz a MESMA string de hoje), e **só 3 linhas foram removidas** nos dois arquivos, todas exigidas pela prop opcional. As mutações **M33** e **M34** (voltar ao tamanho/corpo antigos) reprovam. |
| **D-7** | o tipo da linha vem de `<SeloDoTipo tipo={tipo} />` (prescrição do plano) | um `<span className="text-pub-fase1 font-semibold\|font-normal">` | O protótipo (`:326`) desenha o tipo como texto de **peso 600/400** na cor `#D4D4D8`, no corpo de **11,5px herdado da meta**, **sem losango** e **sem a tag "Catálogo"**. O `SeloDoTipo` é 13px em `text-pub-txt`, com losango amarelo de 7px e layout em **COLUNA** (`inline-flex flex-col gap-[3px]`) — três divergências de uma vez, e a coluna quebraria a linha única de `gap:6px` com os `·` entre as partes. O canal de informação é **o mesmo que o próprio componente nomeia como essencial** ("o peso É a informação aqui, 400 contra 600"): aqui ele é reproduzido; o enfeite, não. **Nenhuma geometria de glyph é redesenhada** neste arquivo (travado por gate). A mutação **M36** reprova. |
| **D-8** | o status da linha é um **dot redondo de 7px** em três cores (`:328`, `ST` em `:562`) | a forma do `SeloDeStatusDaPublicacao` (círculo cheio / duas barras / triângulo / anel aberto) | IDENT-04 — *"nunca depender só de cor"* — é requisito do design system **aprovado na Fase 177** e vale para as 4 telas que usam o componente; trocar por um dot faria desta a única tela do módulo em que o status é só cor. ⚠️ **O TEXTO do status — que é a informação — é idêntico nos dois** ("Ativa", "Em revisão", "Com erro"), e as cores de texto batem exatamente com o `ST` do protótipo (`#4ADE80`/`#A1A1AA`/`#F87171`). Gate: as 4 formas são DIFERENTES entre si e o corpo é 12,5px. |
| **D-9** | o evento de criativos mostra **6 miniaturas de 34px** (`:369`-`:371`) | **não nascem** — fica o `detalhe` escrito ("6 imagens geradas") | `atividadeDaEquipe()` entrega `"N imagens geradas"` em `detalhe` e **nenhuma URL de imagem** (179-03): o binário do criativo é endereçado por `publicador/produtos/{produto}/acervo/{criativo}/imagem`, **dentro do editor** — território de outro dev nesta fase. Seis quadrados coloridos fingindo foto seriam enfeite afirmando dado (EXMP-01). Gate: `w-[34px]`/`h-[34px]` ausentes e zero `<img>`, com o número de imagens **presente**. |
| **D-10** | cada evento pode ter um **link de ação** (`:373`, `href="#"`) | **não nasce** | O shape de `atividadeDaEquipe()` é `{tipo, quem, quando_iso, titulo, detalhe}` — **não tem destino**. `href="#"`, que é o que o protótipo usa, é clique mudo (EXMP-03). Gate: zero `<a>` e zero `href=` na atividade, mais a ausência de `Ver criativos`/`Ver publicações`/`Criar combo`/`Corrigir`. |
| **D-11** | são **CINCO** chips: Tudo / Publicações / Criativos / Produtos / **Sistema** (`:592`) | **QUATRO** | O chip "Sistema" filtraria `sync`, e **não existe fonte de EVENTO de sincronização** neste sistema: o que existe é um **carimbo** (`coletado_em`), que já é a linha de Sincronização da Saúde da conta. Filtro que nunca pode ter conteúdo é enfeite (EXMP-01). O 4º chip ("Produtos") nasce porque tem **quarta fonte real** (`produto_recebido`, medida no 179-03). "Publicações" leva `conferiu` junto, como o `AT.pub = ['pub','phase','err']` do protótipo (`:586`). Gate: 4 `<button>`, os 4 rótulos por igualdade, `Sistema` ausente do HTML e de `CHIPS_ATIVIDADE`. A mutação **M23** reprova em 7 testes. |
| **D-12** | a descrição do banner é *"O token da conta expirou **há 2 h**. …"* (`:594`) | **sem o prazo** | `banner()` devolve `{chave, link_reconexao}` e a Fase 177-03 travou a **ausência** de `renova_em`/`expires_at` no shape. Dizer "há 2 h" seria número inventado (VG-18). Gate: contra-prova dupla — `há 2 h` e `expirou há` **ausentes** do HTML e da constante. |
| **D-13** | `ICONES_ATIVIDADE` (o trio `fundo`/`borda`/`cor`) mora em `recentes.js` (prescrição do plano) | mora em **`AtividadeRecente.jsx`** | `tailwind.config.js:12` varre `**/*.jsx` e **não** `*.js`. Classe escrita num `.js` não é varrida, não gera CSS e **não existe para a tela**: os quatro ícones circulares sairiam **sem fundo, sem borda e sem tinta**, com todos os testes de render passando. Achado medido no 179-06, com gate próprio desde o 179-07 — aqui ele está em **16 padrões com isca**. |
| **D-14** | `KPIS` carrega `unidade` e o protótipo a calcula (`:554`) | a unidade vai para o **`title`** da célula, não para a tela | O protótipo **calcula** `k.unit` mas **nunca o renderiza**: a célula (`:294-:306`) desenha só `label`, `value`, o delta e a sparkline — `k.unit` é campo morto no mock. Não há **tamanho, cor nem posição medidos** para a unidade, e inventá-los é redesenho (a régua do usuário). No `title` ela acrescenta informação sem inventar layout: `3 packs`, `12 de 150`. ⚠️ Em aberto para o usuário (pergunta 1). |
| **D-15** | o tempo da linha é absoluto: `"há 12 min"`, `"Ontem, 17:48"`, `"8 out"` (`:572`) | sempre relativo (`haQuanto`) | `haQuanto` **já existe** em `tempo.js` e não se reescreve (IDENT-08, e é `key_link` do plano). O servidor manda `quando` em ISO; o formato absoluto exigiria uma segunda régua de data no cliente. `quando` nulo ou impossível ⇒ a linha sai **sem a hora**, nunca com "Invalid Date". |
| **D-16** | a faixa e a barra sempre existem | a faixa **não nasce sem nenhum KPI**, e a sparkline **não nasce sem série** | Um cabeçalho "Desempenho · Últimos 30 dias" sobre nada afirma que a medição existe e deu vazio; um trilho de 64×26 vazio afirma uma curva que ninguém mediu (VG-18, mesmo critério do D-10 do 179-07). `serie: null` chega **de propósito** do servidor em `publicacoes_com_venda` — nenhum `<svg>` é o resultado CERTO. |
| **D-17** | `Últimos 30 dias` é literal (`periodNames`, `:596`) | `subtituloDoDesempenho(dias)`, que **lê o payload** | Com `dias: 30` a string sai **idêntica** ao literal. Cravar "30" no texto e receber `dias: 7` seria número inventado. |
| **D-18** | `rotuloDoGrupo` poderia usar `new Date(chave)` | lê a data por **REGEX** `^(\d{4})-(\d{2})-(\d{2})$`, com faixa de mês e dia | `new Date('2026-13-01')` é `Invalid Date`, que interpolado escreve **"Invalid Date"** na tela; e `new Date('2026-10-08').getDate()` devolve **7** em BRT (deslocamento de fuso do parser de data-only). Esta é a única tela da fase que formata data. A mutação **M38** (voltar ao `new Date`) reprova em 7 testes. |

---

## ⚠️ Uma guarda de segurança que o GATE encontrou (Rule 2)

**`textoSeguro(NaN)` devolve a STRING `'NaN'`**, porque `typeof NaN === 'number'` e `String(NaN)` é
`'NaN'`. Com isso um `titulo: NaN` do servidor escreveria **"NaN"** na linha da publicação, e um
`quem: NaN` escreveria **"NaN publicou …"** — número inventado na cara do usuário (VG-18). `Infinity`
tem o mesmo problema.

Conserto: a guarda `finito()` **pré-filtra** antes do `textoSeguro`/`textoUtil`, em vez de
reimplementá-los (IDENT-08), e cobre `titulo`, `rotulo_fase`, `tipo`, `ml_item_id`, `produto_nome`,
`produto_id`, `fase`, `quem`, a `chave` do grupo, a `chave` do KPI, `tipo`/`quem`/`titulo`/`detalhe`
do evento e o `chipAtivo`. É a mesma família do `textoUtil(7)` do 179-06/179-07 e do `idUtil` de
`AbasDoHeader.jsx`. Commit `696522ea`; a mutação **M39** reprova em 6 testes.

⚠️ **Como ele apareceu:** `JSON.stringify(NaN)` é `'null'`, então a mensagem do teste adverso dizia
`titulo = null` enquanto o valor era `NaN`. Mensagem de asserção construída com `JSON.stringify`
**mente sobre `NaN`, `Infinity` e `undefined`**.

---

## ⚠️ Os dois erros de MEDIDOR desta sessão (e o conserto)

As **sete armadilhas** que o 179-06 e o 179-07 deixaram escritas foram todas **evitadas**: o `content`
é lido cru, nada é contado no documento inteiro, nenhuma classe foi para o `.js`, o escape do seletor
emite `\\?`, o zero suprimido pelo cssnano é aceito, o byte do alfa é usado como prova, e **toda**
localização sai de índice absoluto (uma árvore de elementos, não `indexOf`). Mas **dois novos**
apareceram.

**1. `/href="…"/` sem o `\s` é VAZIA POR CONSTRUÇÃO.** A mutação **M04** (`href={dados.href}` →
`data-href={dados.href}`) **SOBREVIVEU** na primeira rodada: `data-href="/reconectar"` casa com
`/href="\/reconectar"/`. ⚠️ **Substring casa com SUFIXO de atributo, não só com prefixo de classe.**
Conserto: `\shref="…"` nas duas asserções positivas, mais a negativa de `data-href`. Commit
`c144fc3f`.

**2. Token SÓLIDO do Tailwind não compila para hex.** A sonda acusou **25 falsas faltas** — todas as
classes de token sem modificador de opacidade. O CSS escreve
`.bg-pub-seg{--tw-bg-opacity: 1;background-color:rgb(38 38 38 / var(--tw-bg-opacity, 1))}`, em
**decimal**. O hex de 8 dígitos é só do **modificador** (`bg-pub-ok/[0.12]` → `#22c55e1f`). Conserto:
um helper `solido(prop, r, g, b)` para os 25, e o hex apenas nas 7 tintas com alfa.

**3. E um terceiro, na própria campanha:** a mutação **M33** (`tamanho="recentes"` → `"lista"`)
"sobreviveu" porque a string aparece **também num comentário de divergência** (`:38`), e o
`str.replace(de, para, 1)` trocou o comentário. ⚠️ **Contagem igual a 1 na fonte sem comentário não
garante que a troca caia em código.** Conserto: o aplicador percorre as ocorrências e escolhe a
primeira que **não** está em linha de comentário. Reaplicada, M33 reprova em 2 testes.

---

## A prova por mutação — 40 aplicadas, 40 reprovaram, 0 sobreviveram

Processo por mutação: contagem de ocorrências travada **ANTES** na fonte **sem comentário** (≠1 →
recusa), aplicação **na linha de código**, **impressão de QUAL linha mudou**, execução do gate,
restauração de cópia pristina e conferência por `sha256` — idênticos no fim das duas rodadas:
`recentes.js` **`3e3e13d68ff3d788`** · `BannerDeIntegracao.jsx` **`76ab98d4cec1c923`** ·
`FaixaDeDesempenho.jsx` **`1c7a60a7a5022e38`** · `PublicacoesRecentes.jsx` **`6919a8595d2eae63`** ·
`AtividadeRecente.jsx` **`321656d6deb7023e`**.

| # | Mutação | Arquivo:linha | Falhas |
|---|---|---|---|
| M01 | `h-[34px] w-[34px]` → `h-9 w-9` no tile do banner | banner:57 | 2 |
| M02 | `banner` nulo passa a renderizar | recentes:168 | 4 |
| M03 | o CTA do banner nasce sem `link_reconexao` | banner:75 | 2 |
| M04 | `href` → `data-href` no CTA (⚠️ **sobreviveu na 1ª rodada**) | banner:77 | 2 |
| M05 | `text-[20px]` → `text-xl` no valor do KPI | faixa:142 | 4 |
| M06 | `width={64}` → `{80}` na sparkline | faixa:165 | 2 |
| M07 | `viewBox="0 0 100 34"` → `"0 0 100 26"` | faixa:167 | 2 |
| M08 | `stroke-2` → `stroke-[1.6]` na linha | faixa:177 | 2 |
| M09 | área `fill-pub-accent/[0.10]` → `/[0.12]` | faixa:172 | 2 |
| M10 | as cores de ↑ e ↓ trocadas | faixa:149 | 2 |
| M11 | desenha flecha com `delta: 0` | recentes:276 | 2 |
| M12 | mostra `0` com `valor: null` | recentes:272 | 4 |
| M13 | a faixa vira `<a href>` | faixa:98,183 | 2 |
| M14 | `grid-cols-[40px_…]` → `38px` na recente | pubs:107 | 2 |
| M15 | `tamanho={40}` → `{34}` no tile | pubs:115 | 2 |
| M16 | tira o `bg-pub-row-new` da linha nova | pubs:109 | 2 |
| M17 | tira o `BadgeNova` | pubs:122 | 2 |
| M18 | `font-pub-mono` → `font-pub` no MLB (⚠️ **prova o prefixo**) | pubs:140 | 2 |
| M19 | `h-[30px] w-[30px]` → `h-8 w-8` no ícone do evento | ativ:219 | 3 |
| M20 | `left-[14.5px]` → `left-[15px]` no fio | ativ:212 | 4 |
| M21 | desenha fio **também** no último evento | recentes:549 | 4 |
| M22 | `tracking-[0.08em]` → `tracking-wider` no rótulo do grupo | ativ:192 | 2 |
| M23 | **5 chips**, acrescentando "Sistema" | recentes:405 | 7 |
| M24 | `HOJE` → `Hoje` | recentes:424 | 6 |
| M25 | as aspas CURVAS do vazio viram retas | recentes:300 | 3 |
| M26 | acrescenta um **KPI de Vendas** | recentes:284 | 9 |
| M27 | `gap-[14px]` → `gap-[12px]` na linha | pubs:107 | 2 |
| M28 | `rounded-full` → `rounded-pub-chip` no chip | ativ:174 | 4 |
| M29 | `items-baseline` → `items-center` na linha do valor | faixa:141 | 2 |
| M30 | `tracking-[-0.02em]` → `[-0.03em]` no valor | faixa:142 | 2 |
| M31 | o vazio das recentes perde o `title` com o motivo | pubs:169 | 2 |
| M32 | a sparkline perde o `preserveAspectRatio` | faixa:168 | 2 |
| M33 | o glyph volta ao `tamanho="lista"` (⚠️ **recusada e reescrita**) | pubs:133 | 2 |
| M34 | o status volta aos 13px (sem a prop `corpo`) | pubs:145 | 2 |
| M35 | grade de 3 KPIs com **4** trilhas | faixa:73 | 2 |
| M36 | o tipo da recente perde o peso 600 | recentes:359 | 2 |
| M37 | o alvo do evento perde o peso 600 | ativ:241 | 3 |
| M38 | `rotuloDoGrupo` volta a usar `new Date` | recentes:445 | 7 |
| M39 | a guarda de `NaN` volta a deixar passar | recentes:70 | 6 |
| M40 | `top-[32px]` → `top-[30px]` no fio | ativ:212 | 2 |

**Zero sobreviventes.** As duas que sobreviveram na primeira rodada (**M04** e **M33**) não eram
equivalentes: a primeira era **lacuna de cobertura real** (o gate não exigia o espaço antes de
`href=`) e a segunda era **erro do aplicador** (trocou um comentário).

---

## A sonda do CSS construído — 119 classes, 14 iscas

`npm run build` verde (31,9 s). O CSS do app é `assets/app-BZLDipaL.css` (**219.054 bytes**), lido
pela entrada `resources/js/app.jsx` → `css[0]` **do JSON do manifest** (`public/build/manifest.json`),
nunca por grep. `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` presente no manifest
(`assets/VisaoGeral-C5KAvguH.js`) — **página fora do manifest SOME da tela**.

A sonda compara o **VALOR** da declaração, com o seletor escapado por `\\?` antes de cada caractere
especial, o negative-lookahead `(?![\w\\-])` contra o casamento por prefixo, e as **duas** formas de
cor tratadas separadamente: `rgb(R G B / var(…))` para o token sólido e **hex de 8 dígitos** para o
modificador de opacidade.

**Os grupos conferidos:** 3 raios `rounded-pub-*` + 4 `rounded-[Npx]` · 15 tokens de cor sólidos
(`bg-`/`text-`/`border-`) · **as 7 tintas com alfa pelo BYTE** (`#ef444466` = 0.4 · `#ef444412` =
0.07 · `#ef444424` = 0.14 · `#facc151f` = 0.12 · `#facc1559` = 0.35 · `#facc151a` = **0.10** ·
`#22c55e1f` = 0.12 · `#22c55e59` = 0.35) · 10 corpos de texto (10/11/11.5/12/12.5/13/13.5/14/15/20) ·
3 `grid-cols-[…]` · 24 medidas de altura/largura/posição (inclusive `left-[14.5px]`, `top-[32px]`,
`w-px`, `border-[1.3px]`) · 10 paddings e 6 `gap-[…]` · os 2 `tracking-[…]` · `tabular-nums` ·
`leading-none` · `leading-[1.4]` · `truncate` · `font-pub-mono` · `fill-none` · `stroke-2` ·
`stroke-current` · 2 `stroke-pub-*` · `fill-pub-accent/[0.10]` · `overflow-visible` ·
`place-items-center` · `items-baseline` · `items-end` · `rounded-full` · `hover:bg-pub-hover-2`.

**As 14 iscas saíram AUSENTES:** `text-[20.5px]` · `text-[11.6px]` · `h-[33px]` · `w-[13.7px]` ·
`rounded-[7.5px]` · `border-[1.2px]` · `left-[14.6px]` · `top-[31px]` · `tracking-[-0.025em]` ·
`bg-pub-row-newer` · `bg-pub-accent-sunk` · `fill-pub-accent/[0.12]` · `bg-pub-ok/[0.13]` ·
`grid-cols-[41px_minmax(0,1fr)_112px_104px]`.

⚠️ **Uma isca teve de ser trocada:** `w-[13px]` saiu **PRESENTE** — a classe existe no projeto, usada
por outro componente. **Isca tem de ser uma classe que não existe em lugar nenhum**, não só nos meus
arquivos.

---

## Como o gate foi escrito — "o que precisaria estar errado para ele falhar?"

**A localização é uma ÁRVORE, não um `indexOf`.** `arvore(html)` devolve
`{nome, bruto, classes, filhos, indice, inicio, fim}` com **posições absolutas**, e todo recorte sai
delas. Esta tela é feita de **irmãos idênticos por natureza** — 5 linhas de recente, N eventos, 3
KPIs, 4 chips — e `html.indexOf(tagDeAbertura)` devolveria sempre o primeiro (foi o que fez quatro
gates do 179-07 medirem a linha do Mercado Livre acreditando medir a do ERP).

| O quê | Quantas |
|---|---|
| elemento de topo em cada bloco | **1** |
| filhos do banner | **3** (tile, coluna, CTA) e **2** `<path>` no svg |
| `<div>` na faixa | **1 + nº de KPIs** (o bloco do título e uma célula por KPI) |
| `<svg>` na faixa | **1 + nº de KPIs COM série** (o ícone + as sparklines) |
| linhas de recente | **5** |
| `BadgeNova` por linha | **[1, 0, 0, 0, 0]** (só a `nova`) |
| chips | **4** (nunca os 5 do protótipo) |
| fios por grupo | **[2, 0]** para grupos de **[3, 1]** eventos |
| ícone circular por evento | **1**, e os 4 trios conferidos por conjunto |
| `<circle>` no HTML | **0** (os ícones são à mão; o Lucide desenharia um) |
| `.map()` por arquivo | **4 / 0 / 1 / 1 / 3** |

**A localização nunca usa a medida conferida:** as células de KPI são os filhos diretos da faixa
**menos o primeiro**; as linhas de recente, os filhos diretos da `<section>` **depois do cabeçalho**;
os chips, **todos** os `<button>`; os eventos, os filhos do grupo **depois do rótulo**; o ícone de um
evento, o filho com `rounded-full`; o texto de qualquer nó, `textoDireto(html, no)` pelas posições
absolutas.

**Iscas em quatro pontos:** (1) os 16 padrões da varredura de classe-no-`.js` têm de **achar** coisa
nos quatro `.jsx`; (2) a varredura de REND-04 tem de **achar** o padrão proibido num trecho falso
(`lista.map((x) => (x.chave === filtro ? …))`); (3) `SeloExemplo.jsx` e `dadosDeExemplo.js` **existem
no disco**, então a varredura negativa mede algo; (4) a sonda do CSS tem 14 classes que não existem.
E o recorte da célula de `publicacoes_com_venda` prova que **contém** o rótulo antes de exigir que
**não** contenha dígito — sem isso, um recorte vazio diria "sem zero" por construção.

**As asserções negativas são negativas de propósito:** uma positiva ("o bloco é real") seguiria verde
se uma fase futura acrescentasse a pilha de exemplo. São negativas: `SeloExemplo`, `dadosDeExemplo`,
`Vendas`/`Visitas`/`Conversão`, `Sistema`, `Ver desempenho`, `há 2 h`, aspas retas, `route(`,
`href="#"`, `w-[34px]`, `<img>`, `<circle>`, `lucide`, `M21 15v4`, `M3 3v16`, hex literal, escala
nomeada de texto, `ecf-*`, `font-display`, breakpoint e `dangerouslySetInnerHTML`.

---

## Casos adversos (REND-01/02) — a tela preta de 07/10

**Render REAL** (esbuild + `react-dom/server`), nunca leitura de fonte, com
`doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)` **e** a confirmação de que o bloco não
desmontou (o `<h2>` presente) em todas as formas. ⚠️ `Invalid Date` entra na lista porque esta é a
única tela da fase que **formata data**.

A bateria `ADVERSOS` tem **15 formas** (`{}`, `{a:1}`, `[]`, `[1]`, `null`, `undefined`, `'x'`, `''`,
`'   '`, `7`, `0`, `true`, `false`, `NaN`, `Infinity`) e é aplicada a:

- `banner` / `desempenho` / `recentes` / `atividade` **inteiros**
- `banner` `{}`, sem `chave`, com `chave` desconhecida, `'constructor'`, `7`, `{}` → **não renderiza**
- `kpis` / `itens` / `grupos` / `eventos` nulos, string, número, objeto, `[]`, `[null]`, `['x']`,
  `[7]`, `[{}]`, `[[]]`, `[true]`, `[NaN]`
- **cada campo** do KPI (`chave`/`valor`/`de`/`delta`/`serie`), do item (os 13) e do evento (os 5)
  como objeto, nulo, ausente e as outras 12 formas — **um por vez** e **todos ao mesmo tempo**
- `serie` com string, objeto, `null`, `NaN`, `Infinity` e `['1','2']` dentro → **nenhum `<svg>`**
- `quem` como string, `{}`, `{tipo:{},nome:{}}`, `{nome:'  '}`, array e nulo
- `quando` / `quando_iso` em 11 formas impossíveis → **sem hora, nunca "Invalid Date"**
- `chave` do grupo em 10 formas impossíveis → **`SEM DATA`**
- `tipo` do evento em `'constructor'`, `'toString'`, `'__proto__'`, `'valueOf'` → **ícone padrão, e
  nunca `className={undefined}`**
- `urlPublicacoes` / `chipInicial` objeto, número, array, `''`, `'   '`, `true` → **nenhum
  `href="[object Object]"`, `href="7"` nem `href=""`**
- **nenhuma prop** nos quatro

---

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `node --test tests/js/publicador-visao-geral-recentes.test.js` | **119 / 119 pass / 0 fail** |
| 2 | `npm run test:js` | **3508 testes / 3507 pass / 1 fail** — o pré-existente `estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido"). ⚠️ **Baseline medida ANTES deste plano: 3389 / 3388 / 1**, mesmo nome e mesma causa. Não corrigido, de propósito |
| 3 | `publicador-identidade-nova-render` + `publicador-identidade-render` + `publicador-vocabulario-novo` + este | **254 + 119 = 373 / 373 pass / 0 fail** (com as duas props aditivas no lugar) |
| 4 | `npm run build` | verde, 31,9 s; `VisaoGeral.jsx` no manifest **pelo JSON** |
| 5 | sonda do CSS construído | **119 classes com o VALOR certo, 14 iscas ausentes** |
| 6 | mutações | **40 aplicadas, 40 reprovaram, 0 sobreviveram** (2 sobreviveram na 1ª rodada e eram defeito real); `sha256` idêntico ao pristino no fim |
| 7 | assertions removidas no range **deste plano** (`49ce247a..HEAD`) | **ZERO** (isca: **354** acrescentadas no mesmo range) |
| 8 | território de NINGUÉM (`898d68c2..HEAD`) | **diff vazio** em `app/Support/Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`, `Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa`, `database` |
| 9 | território de OUTRO PLANO (`49ce247a..HEAD`) | **diff vazio** em `app`, `routes`, `tailwind.config.js`, `resources/js/Pages`, `header/`, `PainelVisaoGeral.jsx`, `tempo.js`, `visaogeral/jornada.js`, `JornadaDosProdutos.jsx`, `acoes.js`, `OQueFazerAgora.jsx`, `SaudeDaConta.jsx`, `PublicacoesNoMercadoLivre.jsx`, `composer.json/lock`, `package.json/lock`, `ROADMAP.md`, `REQUIREMENTS-v26.md` |
| 10 | **ISCA** do mesmo range nos MEUS caminhos | **NÃO vazio**: 8 arquivos, **2.908 inserções**, 3 remoções — o vazio dos gates 8/9 não é por construção |
| 11 | os 25 caminhos dos gates por `test -e` | **todos EXISTEM** no disco |
| 12 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | **vazio** (nenhuma deleção) |
| 13 | `git status --short \| grep tests/js` | **vazio** (nenhuma fixture órfã; os stubs do harness são apagados pelo `after()`, e um stub órfão de uma execução interrompida foi removido à mão) |
| 14 | T-179-SC (pacotes) | **nenhum pacote instalado**; diff vazio nos 4 manifests; gate que confere cada import contra o `package.json`. ⚠️ E **nenhum import de `lucide-react`** nos 5 arquivos: os 6 ícones são à mão |
| 15 | PHP | **não tocado** — `git diff --stat 49ce247a..HEAD -- app routes database` **vazio**. A suíte PHP não foi rodada porque nada de PHP mudou (as 2.376 linhas de `app/` no range da FASE são dos planos 179-01 a 179-05, confirmado por `git diff --stat 898d68c2..HEAD`) |
| 16 | diff nos componentes de identidade | **3 linhas removidas**, todas exigidas pelas props opcionais (a assinatura, o JSDoc do `tamanho` e a linha do `cn()`); o default é byte-idêntico, provado pelos 254 testes de identidade |

---

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-37 Tampering (tela preta de 07/10) | **mitigado** | `objetoUtil` na fronteira de todo nível; item/grupo/evento/KPI nulos **descartados**; chave desconhecida ignorada no banner e **mantida** no KPI e no evento; render REAL com 15 formas por campo e `doesNotMatch(/\[object Object\]\|NaN\|undefined\|Invalid Date/)` |
| T-179-38 Tampering (`NaN` no `path` da sparkline) | **mitigado** | as 3 guardas (`n===0`, `n===1`, valor não-finito); o `d` comparado **em string** com a saída da função pura; `doesNotMatch(/NaN/)` com série de 1 ponto. Sem a guarda o navegador descartaria o `path` **em silêncio** |
| T-179-39 Tampering (data impossível) | **mitigado** | `rotuloDoGrupo` e `horaDoEvento` leem por **REGEX**, nunca `new Date`; `haQuanto` já devolve `null`; `Invalid Date` na varredura negativa; M38 reprova |
| T-179-40 Spoofing (dado inventado) | **mitigado** | KPI sem valor mostra `—` com o motivo no `title`; **nenhum** KPI de venda/visita/conversão (negativa sobre o payload real); miniaturas de criativo **não nascem**; o `0` medido **aparece**; M12/M26 reprovam |
| T-179-41 Spoofing (destino inventado) | **mitigado** | a faixa é `<section>` (zero `href=`, `Ver desempenho` ausente); CTA do banner só com `link_reconexao`; sem `urlPublicacoes` a linha é `<div>` e o link não nasce; `urlSegura` recusa objeto, número e vazio; nenhum `route(` nas 5 fontes; M03/M04/M13 reprovam |
| T-179-42 Tampering (`ReferenceError` no bundle) | **mitigado** | contagem de `.map()` travada por arquivo (**com o `.map()` ANINHADO da atividade**) e varredura dos corpos de callback com isca; `premium` e `href` passaram a viajar **no item** por causa deste gate |
| T-179-43 Repudiation (bloco real marcado como exemplo) | **mitigado** | asserção negativa de `SeloExemplo`, `dadosDeExemplo` e da palavra `Exemplo` nas 5 fontes, com isca de que os dois arquivos existem — **VG-20** |
| T-179-SC Supply chain | **mitigado** | **nenhum pacote**; diff vazio nos 4 manifests; gate que confere cada import contra o `package.json`; e **nenhum import de `lucide-react`** nestes 5 arquivos |

---

## Known Stubs

**Nenhum.** Nenhum número, rótulo ou destino destes quatro blocos é constante decorativa: os números
saem dos payloads do 179-03, os rótulos são literais de UI medidos no protótipo (e nenhum deles
afirma um dado que não existe) e todo destino vem do servidor — ausente o destino, o elemento **não é
renderizado** em vez de virar clique mudo.

As três peças que o protótipo tem e que **não nascem** (miniaturas de criativo, link por evento e chip
"Sistema") estão declaradas acima com a restrição concreta de cada uma, e são **ausências
deliberadas**, não pendências de implementação: nenhuma delas tem fonte no servidor hoje.

## Threat Flags

Nenhuma. O plano é 100% cliente: `git diff --stat 49ce247a..HEAD -- app routes database` é **vazio**.

---

## Perguntas em aberto para o usuário

1. **A unidade do KPI ficou só no `title`** (D-14). O protótipo calcula `"de 150"` e `"packs"` mas
   **não os desenha** em lugar nenhum da célula — não há tamanho, cor nem posição medidos para eles.
   Hoje passar o mouse sobre a célula mostra `12 de 150` / `3 packs`. Se você quiser a unidade
   **visível** ao lado do valor, é uma decisão de desenho nova (e uma linha de mudança), não uma
   medida do protótipo.
2. **O delta negativo sai `↓ -2`**, com o sinal repetido, porque é o que o protótipo renderiza
   (`delta: (d>0?'+':'')+d`, `:555`, com a flecha separada em `:299`). Se preferir `↓ 2`, é uma linha.
3. **O status da linha mantém a FORMA** em vez do dot de 7px do protótipo (D-8), porque IDENT-04 é
   requisito aprovado na Fase 177 e o componente serve 4 telas. O texto é idêntico. Se você quiser o
   dot aqui, dá pra fazer por mais uma prop aditiva.
4. **A faixa de Desempenho não é clicável** (D-3) — confirmado por você em 12/10. Vira link na Fase
   182, quando a tela existir.
5. ⚠️ **NADA foi visto renderizado.** Não há navegador neste ambiente e o banco local não tem empresa
   com `ml_token`. Toda a conferência é de teste automatizado, de sonda sobre o CSS construído e de
   mutação. **A validação visual é do usuário, em produção, na conta 459.**

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/visaogeral/recentes.js` — FOUND (554 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/BannerDeIntegracao.jsx` — FOUND (85 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/FaixaDeDesempenho.jsx` — FOUND (185 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesRecentes.jsx` — FOUND (178 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/AtividadeRecente.jsx` — FOUND (271 linhas)
- `tests/js/publicador-visao-geral-recentes.test.js` — FOUND (1.594 linhas)
- commits `4e59677e`, `4d12c4f6`, `8d195bf5`, `696522ea`, `6a06081f`, `c144fc3f` — FOUND em `git log`
- os cinco arquivos de código idênticos ao commit depois das 40 mutações (`sha256` conferido em cada
  rodada e no fim)
