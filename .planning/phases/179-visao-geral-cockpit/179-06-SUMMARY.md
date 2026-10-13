---
phase: 179-visao-geral-cockpit
plan: 06
subsystem: ui
tags: [react, publicador, visao-geral, jornada, anel, fidelidade, tailwind, mutacao, rend-04]

requires:
  - phase: 177-identidade-e-header
    provides: "TileDoProduto (props `raio`/`letra`), MapaDeCobertura, identidade.js e a camada de tokens `pub-*`"
  - phase: 178-modal-nova-publicacao
    provides: "O molde do gate por igualdade de CONJUNTO de classes, o harness esbuild + react-dom/server e o precedente do módulo puro + JSX"
  - phase: 179-visao-geral-cockpit
    provides: "179-01: o contrato `jornada` do servidor · 179-04: os tokens `pub-hover-0`/`pub-raised-0`/`pub-accent-sunken` e `bg-pub-hachura` · 179-05: a prop `jornada` chegando na página"
provides:
  - "resources/js/Components/Mlb/Publicador/visaogeral/jornada.js — literais, geometria do anel/barra e os 3 view models dos `.map()`"
  - "resources/js/Components/Mlb/Publicador/visaogeral/JornadaDosProdutos.jsx — o card da Jornada medida por medida"
  - "tests/js/publicador-visao-geral-jornada.test.js — 152 testes, 32 mutações, sonda de CSS com 54 classes e 12 iscas"
affects: [179-09-composicao-da-pagina, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "⚠️ NENHUMA classe do Tailwind pode morar num arquivo `.js` de `resources/js`: o `content` do `tailwind.config.js` varre `**/*.jsx` e NÃO `*.js` — classe escrita num `.js` não gera CSS e não existe para a tela"
    - "Módulo puro + view models: a lógica dos `.map()` sai do corpo do componente e vira função pura que recebe tudo por argumento (REND-04, `feedback_rollup_map_scope_bug.md`)"
    - "Gate por igualdade de CONJUNTO de classes, com a LOCALIZAÇÃO do elemento nunca usando a medida que está sendo conferida"
    - "Mutação que SOBREVIVE é lacuna de cobertura até prova em contrário — as 2 sobreviventes desta rodada eram as duas lacunas reais"
    - "Guarda de DESTINO mais estrita que guarda de TEXTO: número é nome de produto plausível, nunca URL"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/visaogeral/jornada.js
    - resources/js/Components/Mlb/Publicador/visaogeral/JornadaDosProdutos.jsx
    - tests/js/publicador-visao-geral-jornada.test.js
  modified: []

key-decisions:
  - "O painel nasce aberto por `contagens.ready > 0`, como o protótipo (`:390`/`:472`), e NÃO por `proximo_passo === 'ready'` como o plano prescreveu: um payload que dissesse `proximo_passo: 'none'` com 13 prontos fecharia o painel que VG-08 manda abrir"
  - "`border-pub-hover-3` (#1A1A1A) na divisória da linha do painel, não `border-pub-line-subtle-2` (#1C1C1C): medido em `:489`, como o próprio plano mandou conferir"
  - "`MapaDeCobertura` entra inteiro, com as 4 células seguidas: o protótipo separa F1 de F2 com rótulo próprio, mas o componente tem as 4 células como constante e o diretório `identidade` está vedado neste plano — a fase fica no nome acessível de cada célula"
  - "Os dois CTA do empty state e o CTA da linha do painel são `<a href>` e só existem com destino: `<button>` sem ação é botão mudo (EXMP-01)"
  - "`urlSegura()` recusa número, enquanto `textoUtil()` o aceita — `href=\"7\"` é destino morto e `src=\"7\"` é request inútil"
  - "TODA classe do Tailwind no `.jsx`; `jornada.js` fica sem nenhuma, por causa do `content` do config (achado desta sessão, não estilo)"

metrics:
  duration: "~3h"
  completed: 2026-10-13
  tasks: 3
  tests_added: 152
  mutations: 32
  mutations_caught: 32
  classes_provadas_no_css: 54
  iscas_da_sonda: 12
---

# Fase 179 Plano 06: Jornada dos produtos (cliente) Summary

O anel de 176px, os quatro estágios clicáveis com chevron entre eles, a barra de 8px com a hachura e o
painel de até 6 produtos — reproduzidos do protótipo com a geometria comparada **em números** com a
aritmética que a desenha, 32 mutações reprovadas e 54 classes conferidas **no CSS construído**.

## ⚠️ O ACHADO QUE MAIS IMPORTA PARA AS PRÓXIMAS WAVES

**`tailwind.config.js:8-13` varre `./resources/js/**/*.jsx` e NÃO `*.js`.**

Classe escrita num arquivo `.js` de `resources/js` **não é varrida, não gera CSS e portanto NÃO EXISTE
para a tela** — o mesmo efeito da classe montada por concatenação de string que o plano 179-04 avisou,
por outro caminho. O plano 179-06 mandava `jornada.js` carregar o "mapa fechado de cor lido dentro do
`.map()`"; se a cor do anel e a hachura da barra tivessem ficado lá, o anel sairia **sem cor nenhuma**
e ninguém acusaria: todos os testes de render passariam (a classe está no HTML), e só a tela mostraria
o problema.

➡️ **Os 4 mapas de classe deste bloco moram no `.jsx`.** O gate
`jornada.js — módulo PURO: nem React, nem JSX, nem classe do Tailwind` trava isso para frente, com 11
padrões (`bg-pub-`, `text-pub-`, `border-pub-`, `stroke-pub-`, `rounded-pub-`, `text-[N`, `h-[N`,
`w-[N`, `gap-[N`, `grid-cols-`, `hover:`) e lendo o `content` do config **cru**.

⚠️ **E uma segunda armadilha, de medidor:** `lerSemComentarios('tailwind.config.js')` **mastiga o
glob**. `'./resources/js/**/*.jsx'` contém um `/*` seguido de `*/`, e o helper remove blocos de
comentário — o glob chega ao teste como `'./resources/js*.jsx'`. O gate do `content` lê o arquivo com
`fs.readFileSync`, não pelo helper.

## FID-01 — as 36 medidas: protótipo × implementado

Fonte: `new_layout_publicador/Visao Geral.dc.html`. ⚠️ As linhas abaixo foram **medidas no arquivo**; as
do `<action>` do plano estão deslocadas (ver a seção seguinte).

| # | Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|---|
| 1 | card | `bg #111111`, `1px #262626`, raio 16, `padding:22px 24px 24px` (`:40`) | `rounded-pub-card border border-pub-line bg-pub-surface px-[24px] pb-[24px] pt-[22px]` |
| 2 | cabeçalho do card | `flex; items:flex-start; space-between; gap:20px; mb:22px` (`:41`) | `mb-[22px] flex items-start justify-between gap-[20px]` |
| 3 | coluna do título | `flex column; gap:4px` (`:42`) | `flex flex-col gap-[4px]` |
| 4 | H2 | `17px/600; ls -0.01em; margin:0` (`:43`) | `m-0 text-[17px] font-semibold tracking-[-0.01em]` |
| 5 | subtítulo | `13px #8A8A90` (`:44`) | `text-[13px] text-pub-txt-3` |
| **5b** | **agrupador da direita** | **`flex; items:center; gap:12px` (`:46`)** | **`flex items-center gap-[12px]`** — ⚠️ **medida AUSENTE da tabela do plano** |
| 6 | nota de período | `flex; gap:6px; 12px #71717A` + `title` (`:47`) | `flex items-center gap-[6px] text-[12px] text-pub-txt-4` + `title` literal |
| 7 | relógio da nota | 13×13, `viewBox 0 0 16 16`, stroke **1.5** (`:48`) | `<Clock size={13} strokeWidth={2.25} />` (**1,5 × 1,5**) |
| 8 | "Ver produtos" | `h30; pad 0 12px; 1px #2E2E2E; raio 8; 12.5px/500; gap 6px; nowrap` (`:49`) | `flex h-[30px] items-center gap-[6px] whitespace-nowrap rounded-[8px] border border-pub-line-strong px-[12px] text-[12.5px] font-medium` |
| 9 | seta do "Ver produtos" | 11×11, stroke **1.8** (`:50`) | `<ArrowRight size={11} strokeWidth={2.7} />` |
| 10 | grid anel + estágios | `184px minmax(0,1fr); gap:32px; items:center` (**`:77`**) | `grid grid-cols-[184px_minmax(0,1fr)] items-center gap-[32px]` |
| 11 | caixa do anel | `relative; 176×176` (**`:78`**) | `relative h-[176px] w-[176px]` |
| 12 | svg do anel | `176×176`, `viewBox "0 0 176 176"` (**`:79`**) | atributos idênticos, **sem classe** |
| 13 | trilho | `r=74; stroke #1C1C1C; width 14; fill none` (**`:80`**) | `<circle cx="88" cy="88" r="74" className="fill-none stroke-pub-line-subtle-2 stroke-[14]" />` |
| 14 | segmento | `r=74; rotate(-90 88 88); width 14; dasharray/offset/opacity; transition .2s` (**`:82`**) | `fill-none stroke-[14] transition-opacity duration-200` + cor + `style` com os 3 valores |
| 15 | cores do anel | `done #FACC15 · ready #E4E4E7 · draft #71717A · none #3F3F46` (**`:465-:468`, `:483`**) | `stroke-pub-accent · stroke-pub-fase1-2 · stroke-pub-txt-4 · stroke-pub-line-off` |
| 16 | centro | `inset:0; flex column; center; gap:2px` (**`:85`**) | `absolute inset-0 flex flex-col items-center justify-center gap-[2px]` |
| 17 | percentual | `34px/600; ls -0.03em; tabular-nums` (**`:86`**) | `text-[34px] font-semibold tracking-[-0.03em] tabular-nums` |
| 18 | "publicados" | `12px #8A8A90` (**`:87`**) | `text-[12px] text-pub-txt-3` |
| 19 | "N de M produtos" | `12px #D4D4D8; tabular-nums; mt:2px` (**`:88`**) | `mt-[2px] text-[12px] tabular-nums text-pub-fase1` |
| 20 | coluna direita | `flex column; gap:14px; min-width:0` (**`:91`**) | `flex min-w-0 flex-col gap-[14px]` |
| 21 | grid dos estágios | `repeat(4,minmax(0,1fr)); gap:12px` (**`:92`**) | `grid grid-cols-4 gap-[12px]` (valor computado idêntico, provado no CSS) |
| 22 | botão do estágio | `nowrap; relative; flex column; items:stretch; gap:10px; pad 14px 16px; 1px {border}; raio 12; bg {bg}; cursor:pointer; text-align:left; transition .15s`; hover `#3F3F46` (**`:94`**) | `relative flex cursor-pointer flex-col items-stretch gap-[10px] whitespace-nowrap rounded-pub-panel border px-[16px] py-[14px] text-left text-pub-txt transition-[background-color,border-color] duration-150` + `border-pub-line bg-pub-surface` / `border-pub-line-off bg-pub-raised-2` + `hover:border-pub-line-off` (**último na lista do `cn()`**) |
| 23 | overline do passo | `11px/600; ls 0.08em; #71717A; gap 8px` (**`:96`**) | `flex items-center gap-[8px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4` |
| 24 | amostra de cor | `10×10; raio 3; bg {color}`; só `none` com `1px solid #52525B` (**`:96`, `:475`**) | `h-[10px] w-[10px] rounded-[3px]` + cor; `none` ganha `border border-pub-txt-off` |
| 25 | badge PRÓXIMO PASSO | `10.5px/700; ls 0.06em; #0A0A0A sobre #FACC15; pad 2px 6px; raio 4` (**`:97`**) | `rounded-pub-badge bg-pub-accent px-[6px] py-[2px] text-[10.5px] font-bold tracking-[0.06em] text-pub-page` |
| 26 | número do estágio | `32px/600; line-height:1; ls -0.03em; tabular-nums` (**`:99`**) | `text-[32px] font-semibold leading-none tracking-[-0.03em] tabular-nums` |
| 27 | % do estágio | `12px #71717A`, `items:baseline; gap:8px` (**`:99`**) | `flex items-baseline gap-[8px]` + `text-[12px] text-pub-txt-4` |
| 28 | label e sub | `13.5px/600` e `12px #8A8A90`, `gap:2px` (**`:100`**) | `text-[13.5px] font-semibold` e `text-[12px] text-pub-txt-3`, em `flex flex-col gap-[2px]` |
| 29 | rodapé do estágio | `pt:10px; border-top 1px #1F1F1F; space-between; gap:8px` (**`:101`**) | `flex items-center justify-between gap-[8px] border-t border-pub-line-subtle pt-[10px]` |
| 30 | avatar da pilha | `24×24; raio 7; mr:-6px; border 2px {bgSolid}; 8.5px/700` (**`:104`**) | `<TileDoProduto tamanho={24} raio={7} letra="text-[8.5px]" className="-mr-[6px] border-2 font-bold border-pub-surface\|border-pub-raised-2" />` |
| 31 | "+N" | `h24; pad 0 6px 0 10px; 11px #8A8A90` (**`:106`**) | `grid h-[24px] place-items-center pl-[10px] pr-[6px] text-[11px] text-pub-txt-3` |
| 32 | CTA textual | `12px/600; gap:4px; nowrap`; amarelo no próximo passo, `#A1A1AA` nos outros (**`:108`, `:476`**) | `flex items-center gap-[4px] whitespace-nowrap text-[12px] font-semibold` + `text-pub-accent` \| `text-pub-txt-2` |
| 33 | chevron do CTA | 10×10, stroke **2**, `rotate(180deg)` quando aberto, transition .2s (**`:109`**) | `<ChevronDown size={10} strokeWidth={3} className="transition-transform duration-200 [rotate-180]" />` |
| 34 | seta entre estágios | `right:-13px; top:50%; mt:-7px; 14×14; z-index:2; #52525B`; svg 10×10 stroke **2.2** (**`:112`/`:113`**) | `absolute right-[-13px] top-1/2 z-[2] -mt-[7px] grid h-[14px] w-[14px] place-items-center text-pub-txt-off` + `<ChevronRight size={10} strokeWidth={3.3} />`; só nos **três** primeiros |
| 35 | barra segmentada | `flex; gap:3px; height:8px`; faixa `flex-grow:{n}; flex-basis:0; raio 3; opacity; transition .2s`; `none` com a hachura (**`:119`, `:121`**) | `flex h-[8px] gap-[3px]` + `basis-0 rounded-[3px] transition-opacity duration-200` com `style={{flexGrow,opacity}}` + `bg-pub-hachura` no `none` |

### O painel do estágio (`:128-:157`)

| Elemento | Protótipo | Implementado |
|---|---|---|
| contêiner | `mt:20px; 1px #1F1F1F; raio 12; bg #0D0D0D; overflow hidden` (`:128`) | `mt-[20px] overflow-hidden rounded-pub-panel border border-pub-line-subtle bg-pub-sunken` |
| cabeçalho | `flex; items:center; gap:12px; pad 12px 16px; border-bottom 1px #1F1F1F` (`:129`) | `flex items-center gap-[12px] border-b border-pub-line-subtle px-[16px] py-[12px]` |
| título / sub | `13.5px/600` · `12.5px #8A8A90` (`:130`/`:131`) | `text-[13.5px] font-semibold` · `text-[12.5px] text-pub-txt-3` |
| link | `nowrap; ml:auto; 12.5px/500; #A1A1AA` (`:132`) | `ml-auto whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2`, com `Ver todos os {n} →` acima de 6 e `Ver em Produtos →` até 6 (`:487`) |
| X de fechar | `26×26; raio 6; bg transparent; #8A8A90; cursor:pointer`; hover `#1F1F1F`; svg 12×12 stroke **1.8** (`:133`/`:134`) | `grid h-[26px] w-[26px] cursor-pointer place-items-center rounded-pub-chip border-none bg-transparent text-pub-txt-3 hover:bg-pub-line-subtle` + `<X size={12} strokeWidth={2.7} />` + `title`/`aria-label` |
| linha | `grid minmax(0,1.6fr) 130px 150px minmax(0,1.2fr) 150px; gap:16px; h:52px; pad 0 16px`; hover `#121212`; `border-top 1px #1A1A1A` da 2ª em diante (`:137`, `:489`) | `grid h-[52px] grid-cols-[minmax(0,1.6fr)_130px_150px_minmax(0,1.2fr)_150px] items-center gap-[16px] px-[16px] hover:bg-pub-hover-0` + `border-t border-pub-hover-3` |
| tile | `30×30; raio 7; 10px/600; flex:none` (`:139`) | `<TileDoProduto tamanho={30} raio={7} letra="text-[10px]" />` |
| nome / SKU / nota | `13.5px/500 truncate` · `Geist Mono 11.5px #8A8A90` · `12.5px #A1A1AA truncate` (`:140`/`:142`/`:153`) | `truncate text-[13.5px] font-medium` · `font-pub-mono text-[11.5px] text-pub-txt-3` · `truncate text-[12.5px] text-pub-txt-2` |
| cobertura | `flex; items:center; gap:5px`; célula 18×18 raio 4, `1px solid`/`1px dashed #3F3F46`, 10px/600 (`:143`, `:148`) | `flex items-center gap-[5px]` + `<MapaDeCobertura tamanho={18} />` — **divergência D-5, abaixo** |
| CTA da linha | `h30; pad 0 12px; 1px {border}; raio 7; 12.5px/600`, três tons (`:155`, `:490`) | `flex h-[30px] items-center whitespace-nowrap rounded-pub-control-sm border px-[12px] text-[12.5px] font-semibold` + `border-pub-accent bg-pub-accent text-pub-page` (none) / `border-pub-accent/[0.4] bg-pub-accent/[0.14] text-pub-accent` (ready) / `border-pub-line-strong bg-pub-surface text-pub-fase1` (draft+done) |

### O empty state (`:55-:73`)

| Elemento | Protótipo | Implementado |
|---|---|---|
| grade | `auto minmax(0,1fr); gap:28px; items:center; pad 20px 8px 8px` (`:55`) | `grid grid-cols-[auto_minmax(0,1fr)] items-center gap-[28px] px-[8px] pb-[8px] pt-[20px]` |
| ilustração | `relative; 132×110` (`:56`) | `relative h-[110px] w-[132px]` + `aria-hidden` |
| cartão de trás | `left:10 top:18; 78×86; raio 12; 1.5px dashed #3F3F46; bg #0D0D0D` (`:57`) | `absolute left-[10px] top-[18px] h-[86px] w-[78px] rounded-pub-panel border-[1.5px] border-dashed border-pub-line-off bg-pub-sunken` |
| cartão da frente | `left:34 top:6; 78×86; raio 12; 1.5px solid #2E2E2E; bg #161616; gap 6; pad 12` (`:58`) | `absolute left-[34px] top-[6px] flex h-[86px] w-[78px] flex-col gap-[6px] rounded-pub-panel border-[1.5px] border-pub-line-strong bg-pub-hover-2 p-[12px]` |
| as 3 barras | `100%×34 raio 6 #1F1F1F` · `70%×6 raio 3 #2E2E2E` · `45%×6 raio 3 #262626` (`:59`) | `h-[34px] w-full rounded-[6px] bg-pub-line-subtle` · `h-[6px] w-[70%] rounded-[3px] bg-pub-line-strong` · `h-[6px] w-[45%] rounded-[3px] bg-pub-line` |
| bolha amarela | `right:4 bottom:4; 30×30; raio 50%; #FACC15`; svg 14×14 stroke **2** `#0A0A0A` (`:61`/`:62`) | `absolute bottom-[4px] right-[4px] grid h-[30px] w-[30px] place-items-center rounded-full bg-pub-accent` + `<Plus size={14} strokeWidth={3} className="text-pub-page" />` |
| coluna de texto | `flex column; gap:8px; **max-width:560px**` (`:65`) | `flex max-w-[560px] flex-col gap-[8px]` — ⚠️ o plano pôs o `max-w` no TEXTO; o protótipo põe na COLUNA |
| título / texto | `16px/600` · `13.5px #A1A1AA; lh 1.5; text-wrap:pretty` (`:66`/`:67`) | `text-[16px] font-semibold` · `text-[13.5px] leading-[1.5] text-pub-txt-2 [text-wrap:pretty]` |
| os 2 botões | `flex; gap:8px; mt:6px`; `h34 pad 0 14px raio 8 #FACC15/#0A0A0A 13px/600` e `1px #2E2E2E bg #111111 13px/500` (`:68`-`:70`) | `mt-[6px] flex gap-[8px]` + os dois como `<a>` (**D-4**) com as classes equivalentes + `flex items-center` |

## ⚠️ As medidas em que o PROTÓTIPO venceu a tabela FID-01 do plano

**Placar: 31 divergências medidas, todas resolvidas a favor do protótipo.**

### 1. As 29 referências de linha deslocadas (`arquivo:linha`)

As linhas 1-9 da tabela do plano (`:40`-`:50`) **estão corretas**. De `:70` em diante, **todas** estão
deslocadas em 7 — o plano foi escrito contra uma versão do protótipo em que o empty state era 7 linhas
mais curto (ele cita `:54-:68`; o arquivo tem `:55-:73`).

| Plano cita | `Visao Geral.dc.html` tem | Elemento |
|---|---|---|
| `:70` | **`:77`** | grid anel + estágios |
| `:71` | **`:78`** | caixa do anel |
| `:72` | **`:79`** | svg do anel |
| `:73` | **`:80`** | trilho |
| `:75` | **`:82`** | segmento |
| `:78` | **`:85`** | centro |
| `:79` | **`:86`** | percentual |
| `:80` | **`:87`** | "publicados" |
| `:81` | **`:88`** | "N de M produtos" |
| `:83` | **`:91`** | coluna direita |
| `:85` | **`:92`** | grid dos estágios |
| `:87` | **`:94`** | botão do estágio |
| `:89` | **`:96`** | overline + amostra |
| `:90` | **`:97`** | badge PRÓXIMO PASSO |
| `:92` | **`:99`** | número e % |
| `:93` | **`:100`** | label e sub |
| `:94` | **`:101`** | rodapé |
| `:97` | **`:104`** | avatar da pilha |
| `:99` | **`:106`** | "+N" |
| `:101` | **`:108`** | CTA textual |
| `:102` | **`:109`** | chevron do CTA |
| `:104` | **`:112`/`:113`** | seta entre estágios |
| `:110` | **`:119`** | barra segmentada |
| `:128-:155` | **`:128-:157`** | painel do estágio |
| `:135` | **`:137`** | linha do painel |
| `:54-:68` | **`:55-:73`** | empty state |
| `:470-475` | **`:465-:468`** | os literais `SM` dos estágios |
| `:486` | **`:483`** | a aritmética do anel (`ring`) |
| `:482`, `:484` | **`:472`**, **`:476`** | a seleção (`sel`) e o `isHot`/`ctaColor` |

As únicas referências de aritmética que o plano acertou: **`:478`** (a pilha de 4 tiles e o `+N`) e
**`:479`** (o `grow` e o `barOpacity` da barra) — exatamente as duas armadilhas que a wave 1 mediu.

### 2. As 2 medidas em que o VALOR da tabela estava errado

| # | A tabela do plano diz | Medido no protótipo | Entregue |
|---|---|---|---|
| V-1 | linha do painel com `border-pub-line-subtle-2` (`#1C1C1C`) — com a ressalva *"meça `:135` antes de escolher"* | **`border: i ? '1px solid #1A1A1A' : 'none'`** (`:489`) | **`border-pub-hover-3`** |
| V-2 | `max-w-[560px]` no **texto** do empty state | `max-width:560px` na **coluna** (`:65`) | `flex max-w-[560px] flex-col gap-[8px]` na coluna |

### 3. As 2 medidas que a tabela OMITIU

| # | O que faltava | Protótipo | Entregue |
|---|---|---|---|
| O-1 | o **agrupador da direita** do cabeçalho (medida 5b) | `display:flex;align-items:center;gap:12px` (`:46`) | `flex items-center gap-[12px]` |
| O-2 | o `white-space:nowrap` do **botão do estágio** (linha 22 da tabela não o lista) | `white-space:nowrap` (`:94`) | `whitespace-nowrap` |

## Divergências declaradas, com a restrição concreta

| # | O protótipo / o plano diz | O que foi entregue | Restrição concreta |
|---|---|---|---|
| **D-1** | o estado inicial abre `ready` se `proximo_passo === 'ready' && ready > 0` (prescrição do plano) | `contagens.ready > 0 ? 'ready' : null` | O protótipo (`:390`, `:472`) começa com `stage: 'ready'` **fixo** e só o derruba quando o estágio está vazio — ele **não consulta** o `hot`. Com a prescrição do plano, um payload que dissesse `proximo_passo: 'none'` com 13 produtos prontos **fecharia** o painel que VG-08 manda nascer aberto. O plano não é mais autoridade que o dado. |
| **D-2** | `jornada.js` carrega "o mapa fechado de cor, lido dentro do `.map()`" | os 4 mapas de classe moram no **`.jsx`** | `tailwind.config.js:12` varre `*.jsx` e **não** `*.js`. Classe no `.js` não gera CSS: o anel sairia sem cor com todos os testes verdes. Ver a seção de abertura. |
| **D-3** | `jornada.js` é "módulo puro: nenhum import de React" | puro de React e de JSX, mas **importa `textoUtil` de `../BarraDaConta`** | Reimplementar a guarda de texto violaria IDENT-08 ("nenhuma tela reimplementa") e o próprio plano manda usar `textoSeguro`/`textoUtil` daquele arquivo para todo texto do servidor. O gate trava `assert.doesNotMatch(FONTE_JORNADA, /from 'react'/)` e `/<\/?[A-Za-z]/` (nenhum JSX). |
| **D-4** | os 2 botões do empty state são `<button>` (`:69`/`:70`); o CTA da linha é `<button>` (`:155`) | `<a href>` nos três, e **ausentes** quando não há destino | "Cadastrar produto" e "Enviar link do Portal ao cliente" vivem na tela de **Produtos**, e abrir o `ModalNovoProduto` daqui exigiria o contexto de programa que este bloco não recebe (e mexer em território de outro plano). EXMP-01: botão mudo é pior que botão ausente. Sem `urlProdutos` o empty state continua **explicando** — o texto fica, os CTA não. |
| **D-5** | `F1 [C][P]` e `F2 [C][P]` com rótulo próprio de 11px/600 e `margin-right:6px` entre os grupos; célula **18×18 raio 4, letra 10px/600** (`:145`-`:148`) | um `<MapaDeCobertura tamanho={18} />` com as **4 células seguidas**, `rounded-[5px]` e `text-[10.5px]` | O componente tem `CELULAS` como **constante de 4 itens** e editá-lo está **vedado** neste plano (o gate do diretório `identidade` compara classes por igualdade de conjunto, e o gate de território exige diff vazio). Partir em dois `MapaDeCobertura` é impossível. A informação de fase **não se perde**: cada célula já emite `title`/`aria-label` `"Fase 1 · Clássico — publicada"`, e a borda tracejada distingue existe × não existe. **Medidas que divergem: raio 5 (protótipo 4) e letra 10.5px (protótipo 10px), mais os rótulos F1/F2 e os 6px de respiro, que não aparecem.** Conserto, se o usuário quiser: prop opcional no `MapaDeCobertura` numa fase que possa tocar `identidade/`. |
| **D-6** | o rótulo do botão da linha sai do **estágio** (`m.rowCta`, `:489`) | sai do `cta_chave` do **item**, com o do estágio como fallback | O servidor do 179-01 manda `cta_chave` por item (contrato já fechado e testado). Os dois coincidem hoje; o fallback existe para `cta_chave` ausente ou desconhecido, e `CTA_CHAVE_DO_ESTAGIO` evita repetir os 4 literais. |
| **D-7** | o `→` do link do painel é texto do JSX, fora da interpolação (`:132`) | está **dentro** da string de `linkDoPainel()` | Texto renderizado idêntico; permite `assert.equal` da **string inteira** (`'Ver todos os 13 →'`), que é o que a mutação M23 reprova. |
| **D-8** | — (o plano manda `textoUtil` para todo campo) | `urlSegura()` **mais estrita** para `url`, `ver_todos_url` e `capa` | `textoUtil(7)` devolve `'7'`, e `7` é um **nome de produto** plausível — mas `href="7"` é destino morto e `src="7"` é request inútil. Rule 2: validação ausente. Descoberto pelo próprio gate RED. |
| **D-9** | — | o `<a>` do CTA leva `flex items-center`, que o `<button>` do protótipo não precisa | `<button>` centraliza o texto por padrão do UA; um `<a>` de `height:30px/34px` não. Sem isso o texto encosta no topo. `cursor-pointer` foi omitido nos `<a>`: é implícito em `a[href]`. |
| **D-10** | o plano fala de **quatro** `.map()` | são **cinco** (segmentos do anel, estágios, tiles, faixas, linhas) | O `.map()` dos segmentos do anel não está na lista do plano. A varredura de REND-04 cobre os 5, e um teste trava a **contagem** para que um 6º não passe calado. |

## Os literais, byte a byte

Todos os literais de tela deste bloco são **do cliente** (o servidor só manda `chave`, números, itens e
URLs — 179-01). Conferidos por `assert.equal` de **string inteira**, com **contra-prova** de que a mesma
frase **sem acento** está ausente do HTML:

| chave | `passo` | `rotulo` | `sub` | `cta` do cartão | `cta_chave` → rótulo da linha |
|---|---|---|---|---|---|
| `none` | `01` | Sem publicação | Aguardando a Fase 1 | Publicar | `publicar_fase1` → Publicar Fase 1 |
| `draft` | `02` | Em andamento | Rascunhos no Publicador | Continuar | `continuar` → Continuar |
| `ready` | `03` | Fase 1 publicada | Prontos para a Fase 2 | Criar combos | `criar_combo` → Criar combo |
| `done` | `04` | Fase 1 + Fase 2 | Metodologia completa | Ver | `ver_publicacoes` → Ver publicações |

Mais `TITULO`, `SUBTITULO`, `NOTA_PERIODO` (**VG-03**: `Estado atual · independe do período`),
`TITLE_NOTA_PERIODO`, `VER_PRODUTOS`, `PROXIMO_PASSO`, `PUBLICADOS`, `FECHAR`, os 4 títulos/subtítulos de
painel e os 4 literais do empty state.

## Os exports — nomes exatos

**`jornada.js`** (0 classes do Tailwind, 0 JSX, 0 `from 'react'`):

- constantes de geometria: `LADO_DO_ANEL` (176) · `RAIO_DO_ANEL` (74) · `LARGURA_DO_TRACO` (14) ·
  `GAP_DO_ANEL` (3) · `CIRCUNFERENCIA` (`2π·74`) · `MAX_TILES` (4)
- ordens: `ORDEM_ESTAGIOS` (`none,draft,ready,done`) · `ORDEM_ANEL` (`done,ready,draft,none`)
- literais: `ESTAGIOS` · `PAINEL` · `CTAS` · `TITULO` · `SUBTITULO` · `NOTA_PERIODO` ·
  `TITLE_NOTA_PERIODO` · `VER_PRODUTOS` · `PROXIMO_PASSO` · `PUBLICADOS` · `FECHAR` · `VAZIO_TITULO` ·
  `VAZIO_TEXTO` · `VAZIO_CTA_1` · `VAZIO_CTA_2`
- funções: **`segmentosDoAnel`** · **`faixasDaBarra`** · `restanteDaPilha` · `percentualSeguro` ·
  `numeroSeguro` · `textoDoAnel` · `tituloDoPainel` · `linkDoPainel` · `rotuloDoCta` ·
  `normalizarJornada` · `cartoesDosEstagios` · `cabecalhoDoPainel` · `linhasDoPainel`

**`JornadaDosProdutos.jsx`**: `export default function JornadaDosProdutos({ jornada, urlProdutos })`.

⚠️ **Para o 179-09:** a prop **`urlProdutos`** é string ou `null` e **não existe no payload do
controller** (as 23 props do 179-05 não a trazem). Quem compõe a página precisa montá-la — a rota é
`mlb.anuncios.publicador.produtos`. Sem ela, o botão "Ver produtos" e os dois CTA do empty state **não
são renderizados** (por decisão, EXMP-01), e o bloco continua funcionando.

## ⚠️ RECADO OBRIGATÓRIO PARA O PLANO 179-09

**Dois arquivos novos precisam entrar em `ARQUIVOS_NOVOS` de
`tests/js/publicador-vocabulario-novo.test.js`:**

```
resources/js/Components/Mlb/Publicador/visaogeral/jornada.js
resources/js/Components/Mlb/Publicador/visaogeral/JornadaDosProdutos.jsx
```

⚠️ **Esquecer faz o gate de vocabulário passar por OMISSÃO, sem acusar nada** — aquela constante é
fixa de propósito.

⚠️ E **`8.5` precisa entrar em `TAMANHOS_PERMITIDOS`** (hoje a lista é
`10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34`). O protótipo
declara `font-size:8.5px` nas iniciais do avatar (`:104`) e o componente usa `text-[8.5px]`; sem o
`8.5` na lista, acrescentar os dois arquivos **reprova o gate**. Os dois passos são do **mesmo commit**.

Este plano já deixou o aviso **dentro do gate**: o teste
`⚠️ 'text-[8.5px]' é usado — e a lista do gate de vocabulário ainda NÃO o aceita` reprova se os dois
arquivos entrarem na lista **sem** o `8.5`.

## A prova por mutação — 32 aplicadas, 32 reprovaram

Processo por mutação: contagem de ocorrências travada **ANTES** na fonte **sem comentário** (≠1 →
recusa; a única exceção declarada é `r="74"`, que é legitimamente 2× — trilho + segmento), aplicação,
**impressão de QUAL linha mudou**, execução do gate, restauração de cópia pristina e conferência por
`sha256` (`cc7a69ae2390c4da` no `.jsx` e `e2f4b1931b170f86` no `.js`, idênticos ao fim das 32 rodadas).
**Zero mutações recusadas por alvo em comentário.**

| # | Mutação | Arquivo:linha | Reprovou em |
|---|---|---|---|
| M01 | `h-[176px]` → `h-44` | jsx:219 | `10/11 — o grid de 184px e a caixa de 176×176` |
| M02 | `r="74"` → `r="70"` (trilho + segmento) | jsx:225,233 | `⚠️ 13 — CINCO <circle> DENTRO do anel` |
| M03 | `stroke-[14]` → `stroke-[12]` no segmento | jsx:241 | `14/15 — os 4 segmentos, com rotate(-90 88 88)` |
| M04 | tira o gap de 3px do dasharray | js:237 | `o dasharray do 1º é (comprimento − 3)` + os literais |
| M05 | `ORDEM_ANEL` → `ORDEM_ESTAGIOS` | js:61 | 10 testes, a começar por `⚠️ ORDEM_ANEL é OUTRA` |
| M06 | `text-[34px]` → `text-3xl` | jsx:248 | `16/17/18/19 — o centro` + FID-04 (escala nomeada) |
| M07 | `text-[32px]` → `text-[30px]` (**prova o prefixo**) | jsx:302 | `26/27 — número de 32px e % de 12px` |
| M08 | `gap-[12px]` → `gap-3` no grid dos estágios | jsx:260 | `20/21 — a coluna direita e o grid de 4 colunas` |
| M09 | `grid-cols-4` → `grid-cols-3` | jsx:260 | `20/21 — …` |
| M10 | tira o `tabular-nums` do percentual | jsx:248 | `16/17/18/19 — o centro` |
| M11 | `h-[8px]` → `h-2` na barra | jsx:371 | `35 — QUATRO faixas, uma barra, e a hachura` |
| M12 | `gap-[3px]` → `gap-[2px]` na barra | jsx:371 | `35 — …` |
| M13 | `bg-pub-hachura` → `bg-pub-line-off` no `none` | jsx:91 | `35 — …` + `a hachura é CLASSE de token` |
| M14 | `0.3` → `0.35` no anel | js:242 | `⚠️ os OUTROS TRÊS saem com 0.3` + o lido do HTML |
| M15 | `0.35` → `0.3` na barra | js:269 | `⚠️ as outras 3 faixas saem com 0.35 — não 0.3` |
| M16 | `rounded-pub-panel` → `rounded-lg` no botão | jsx:271 | `22 — o botão: base igual nos 4` |
| M17 | tira o `cursor-pointer` do botão | jsx:271 | `22 — …` |
| M18 | `bg-pub-raised-2` → `bg-pub-raised` no selecionado | jsx:273 | `22 — …` + `o painel do ready vem aberto` |
| M19 | quatro setas em vez de três | js:406 | `⚠️ 34 — TRÊS setas entre estágios, nunca 4` |
| M20 | tira o `letra="text-[8.5px]"` do avatar | jsx:323-329 | `29/30/31 — rodapé, avatares` + o aviso do 8.5 |
| M21 | tira o `border-2` do avatar | jsx:325 | `29/30/31 — …` |
| **M22** | **abre painel de estágio com total 0** | **jsx:133** | **⚠️ SOBREVIVEU na 1ª rodada — ver abaixo** |
| M23 | `Ver todos os {n} →` → `Ver todos →` | js:199 | `linkDoPainel` + `título, subtítulo e o link` |
| M24 | `tamanho={18}` → `{20}` no `MapaDeCobertura` | jsx:456 | `tile de 30px, nome, SKU em mono, cobertura de 18px` |
| M25 | `text-[11.5px]` → `text-[11px]` no SKU | jsx:444 | `tile de 30px, … SKU em mono` |
| M26 | `+N` passa a sair de `itens.length` | js:402 | `29/30/31 — … e o "+N"` |
| M27 | `border-pub-hover-3` → `border-pub-line-subtle-2` | jsx:427 | `a linha de 52px: a 1ª SEM divisória` |
| **M28** | **badge aparece em estágio vazio** | **js:401** | **⚠️ SOBREVIVEU na 1ª rodada — ver abaixo** |
| M29 | `percentualSeguro` passa a emitir `NaN%` | js:166 | `qualquer outra coisa vira "0%" — e nunca "NaN%"` |
| M30 | estágio de chave desconhecida vira 5º cartão | js:324 | 29 testes |
| M31 | `z-[2]` → `z-30` na seta | jsx:361 | `⚠️ 34 — TRÊS setas` + `o z-[2] é o único z do bloco` |
| M32 | `rotulo` do `none` sem acento | js:71 | `rotulo — igualdade de string INTEIRA` + a contra-prova |

### ⚠️ As 2 sobreviventes NÃO eram equivalentes — eram as 2 lacunas de cobertura reais

**M22** (`selecionado = estagioAberto`, sem o `contagens[...] > 0`) sobreviveu porque o estado inicial
**já nasce nulo** quando `ready` está vazio: o guard só entra em ação **depois de um clique**, e clique
não existe sem navegador. Fechada por leitura de fonte, com a condição inteira
(`⚠️ o guard de "só abre quem tem produto" existe no derivado, não só no estado inicial`).

**M28** (tirar o `&& cnt[k] > 0` de `ehProximoPasso`, `:476`) sobreviveu porque em **toda** fixture o
estágio do `proximo_passo` tinha produto. Fechada por render com `proximo_passo: 'draft'` e `draft`
vazio: o badge **tem** de desaparecer, e **volta** com produto (contra-prova do mesmo gatilho). O badge
"PRÓXIMO PASSO" sobre um card de **zero** produtos é CTA sem destino (EXMP-01).

Reaplicadas depois do conserto: **as duas reprovam**.

## A sonda do CSS construído — 54 classes, 12 iscas

`npm run build` verde (47,5 s). O CSS do app é `assets/app-CF_gc8So.css` (215.405 bytes), lido pela
entrada `resources/js/app.jsx` → `css[0]` **do JSON do manifest**, nunca por grep (o hash tem hífen).
`resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` presente no manifest
(`assets/VisaoGeral-QJ_evTV-.js`) — **página fora do manifest SOME da tela**.

A sonda compara o **valor** da declaração, não a presença da classe, com o regex em **arquivo** (nunca
`node -e`, nunca heredoc) e o escape de `[ ] ( ) . / % , : # +`. O negative-lookahead `(?![\w\\-])`
impede que `text-[11px]` case dentro de `text-[11.5px]` e que `z-[2]` case dentro de `z-[20]`.

**As 54 conferidas:** `stroke-[14]` · `fill-none` · `h-[176px]` · `w-[176px]` · os 5 `stroke-pub-*` ·
`text-[34px]` · `text-[32px]` · `text-[13.5px]` · `text-[12.5px]` · `text-[11.5px]` · `text-[10.5px]` ·
**`text-[8.5px]`** · `text-[17px]` · `tabular-nums` · os 3 `tracking-[…]` · `leading-none` ·
`leading-[1.5]` · as 4 `grid-cols-[…]`/`grid-cols-4` · `h-[8px]` · `gap-[3px]` · `basis-0` ·
**`bg-pub-hachura`** (string inteira do gradiente) · `bg-pub-raised-2` · `bg-pub-hover-3` · os 5
`rounded-pub-*` · `[text-wrap:pretty]` · `transition-[background-color,border-color]` ·
`transition-opacity` · `transition-transform` · `duration-150` · `duration-200` · `z-[2]` ·
`right-[-13px]` · `-mt-[7px]` · `-mr-[6px]` · `border-[1.5px]` · `w-[70%]` · `max-w-[560px]` ·
`hover:border-pub-line-off` · `hover:bg-pub-hover-0` · `hover:bg-pub-line-subtle`.

**As 12 iscas saíram AUSENTES:** `stroke-[140]` · `text-[8.6px]` · `bg-pub-hachura-xyz` · `z-[27]` ·
`h-[1760px]` · `rounded-pub-naoexiste` · `bg-pub-raised-9` · `duration-153` · `tracking-[0.07em]` ·
`max-w-[561px]` · `basis-1` · `stroke-pub-accent-xyz`.

### ⚠️ ERRO DO MEDIDOR N.º 3 DESTA SESSÃO (e o conserto)

A sonda acusou **3 falhas falsas** em `tracking-[-0.03em]`, `tracking-[0.08em]` e `tracking-[0.06em]`.
O motivo: **o cssnano TIRA o zero da frente** — o CSS construído diz `letter-spacing:-.03em`, não
`-0.03em`. O regex passou a aceitar `-0?\.03em`, e a isca `tracking-[0.07em]` prova que a regra ainda
**mede** (sai ausente). É a mesma classe das duas mentiras anteriores da sessão (formato
`rgb(R G B / var(…))` e alfa em hex de 8 dígitos): **o medidor errado, não o código**.

## Como o gate foi escrito — "o que precisaria estar errado para ele falhar?"

**Dois gates PRÓPRIOS saíram vazios por construção** e foram consertados ao fazer a pergunta:

1. **Contar `<circle>` no documento inteiro dá SEIS, não cinco.** O ícone `Clock` do Lucide (`:48`)
   desenha um `<circle cx="12" cy="12" r="10">` próprio. Um gate de `assert.equal(circles, 5)` sobre o
   HTML completo estaria **sempre errado**, e `assert.equal(circles, 6)` passaria com o anel desenhando
   só 4 (trilho esquecido) e um ícone extra. A contagem é feita **dentro** do `<svg>` do anel — e o
   anel é localizado como *"o único `<svg>` que não é ícone do Lucide"*, nunca pelo `width="176"` que
   está sendo conferido.
2. **O gate do `content` do Tailwind media NADA.** `lerSemComentarios('tailwind.config.js')` remove
   blocos `/* … */`, e `'./resources/js/**/*.jsx'` contém `/*` + `*/`: o glob chegava ao teste como
   `'./resources/js*.jsx'` e o `assert.match` reprovava pelo motivo errado. Lido cru.

**A receita, em todos os elementos:** parte o HTML por elemento, extrai a tag de abertura, compara a
**lista de classes daquela tag por igualdade de conjunto** (classe a mais reprova, a menos reprova,
trocada reprova), e trava **contagens exatas**: 1 `<section>` · 1 `<h2>` · **4** cartões · **3** setas
(nunca 4) · **5** `<circle>` no anel · **4** faixas de barra · **6** linhas de painel · **1** badge ·
**1** hachura · **5** `.map()` · `z-[2]` como único `z-*`.

**A localização nunca usa a medida conferida:** o rodapé do cartão é achado pelo span da pilha
(`class="flex"` exato); os cartões, por `<button>` que não tem `title="Fechar"`; as faixas da barra,
pelo `style` com `flex-grow`; as linhas do painel, pelas `<div>` do bloco **sem** `role="group"` (a
`MapaDeCobertura` também é uma `<div>`); o painel, pelo texto do título.

**A geometria é comparada em NÚMEROS** com a saída de `segmentosDoAnel` para a mesma entrada — e
também contra os **literais medidos** (`156.83 464.96`, `-159.83`), porque fórmula contra fórmula é gate
vazio (as duas mudam juntas). Com total 0: `doesNotMatch(/NaN|Infinity/)`.

**Os dois números que não se uniformizam** (0.3 no anel × 0.35 na barra) são cobrados **no mesmo
documento**, lidos do HTML: `opacity:0.3(?!5)` aparece 3× e `opacity:0.35` aparece 3×.

**REND-04 com isca:** a varredura dos 5 `.map()` tem um teste que **prova que ela acha** o padrão
proibido quando ele existe — sem isca, um `indexOf` que falhasse em silêncio deixaria o gate verdadeiro
por construção.

## Casos adversos (REND-01/02) — a tela preta de 07/10

**Render REAL** (esbuild + `react-dom/server`), nunca leitura de fonte, com
`doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)` **e** a confirmação de que o bloco não
desmontou (`>Jornada dos produtos<` presente) em **todas** as formas:

- topo: `pct`/`total`/`publicados`/`tem_produtos`/`proximo_passo` como objeto, string, `NaN`, nulo e ausente
- `estagios`: nulo · `undefined` · string · número · objeto · `[]` · `[null]` · `['x']` · `[7]` · `[{}]` · `[[]]`
- estágio de **chave desconhecida** → **ignorado**, sem 5º cartão, e o item dele **não vaza** para a tela
- `itens`: nulo · string · número · objeto · `[null,'x',9,[]]` · `[{}]`
- item com `nome`, `sku`, `nota`, `capa`, `url`, `cta_chave`, `cobertura` e `produto_id` **cada um objeto**
- `cobertura`: array · nula · string · `{f1_classico:'sim'}` → as 4 células `false`
- `ver_todos_url`: objeto · nula · `''` → sem link, e o `ml-auto` passa para o X
- **`jornada` inteira** nula · `undefined` · string · número · array · `true` · `{}` → cai no empty state
- **nenhuma prop**
- `urlProdutos` objeto, número, array, `''`, `'   '`, `true` → **nenhum** `href="[object Object]"`, `href="7"` nem `href=""`

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `node --test tests/js/publicador-visao-geral-jornada.test.js` | **152 / 152 pass / 0 fail** |
| 2 | `npm run test:js` | **3175 testes / 3174 pass / 1 fail** — o pré-existente `estrutura-grade-glide:123` ("Características secundárias nasce recolhido"). **Baseline medida ANTES: 3023 / 3022 / 1**, mesmo nome e mesma causa. Não corrigido, de propósito |
| 3 | `publicador-vocabulario-novo` + `publicador-identidade-nova-render` + `publicador-header-render` | verdes (dentro da suíte completa) |
| 4 | `npm run build` | verde, 47,5 s; `VisaoGeral.jsx` no manifest **pelo JSON** |
| 5 | sonda do CSS | **54 classes com o valor certo, 12 iscas ausentes** |
| 6 | mutações | **32 aplicadas, 32 reprovaram, 0 recusadas**; `sha256` idêntico ao pristino no fim |
| 7 | assertions removidas no range **deste plano** (`0edc4462..HEAD`) | **ZERO** |
| 8 | território de ninguém (`898d68c2..HEAD`) | **diff vazio** em `app/Support/Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`, `Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa`, `database` |
| 9 | território de outro plano (`0edc4462..HEAD`) | **diff vazio** em `app`, `routes`, `tailwind.config.js`, `resources/js/Pages`, `identidade/`, `header/`, `PainelVisaoGeral.jsx`, `composer.json/lock`, `package.json/lock` |
| 10 | **ISCA** do mesmo range nos meus caminhos | **NÃO vazio**: 3 arquivos, **2.880 inserções** — o vazio dos gates 8/9 não é por construção |
| 11 | os 17 caminhos dos gates por `test -e` | **todos EXISTEM** no disco |
| 12 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | **vazio** (nenhuma deleção) |
| 13 | `git status --short \| grep tests/js` | **vazio** (nenhuma fixture órfã; os stubs temporários do harness foram removidos) |
| 14 | T-179-SC (pacotes) | **nenhum pacote instalado**; diff vazio nos 4 manifests |

⚠️ **As 2 assertions que aparecem no range da FASE** (`898d68c2..HEAD`) são dos commits `3f708208`
(179-01) e `ab0470e7` (179-02B), confirmado por `git log -S`. **No range deste plano o número é zero.**

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-25 Tampering (tela preta de 07/10) | **mitigado** | `normalizarJornada` na fronteira + `textoSeguro` no render; estágio e item nulos descartados; chave desconhecida ignorada; render REAL com cada campo objeto/nulo/ausente e `doesNotMatch(/\[object Object\]\|NaN\|undefined/)` |
| T-179-26 Tampering (`ReferenceError` só no bundle) | **mitigado** | os 5 `.map()` leem só o próprio parâmetro e mapas de módulo; os 3 view models (`cartoesDosEstagios`, `cabecalhoDoPainel`, `linhasDoPainel`) existem para isso; gate por leitura de fonte **com isca** |
| T-179-27 Spoofing (destino inventado) | **mitigado** | toda `url` vem do servidor; `urlSegura()` recusa objeto, número e vazio; item sem `url` não renderiza CTA; nenhuma URL concatenada no JSX |
| T-179-28 Repudiation (número que não fecha) | **mitigado** | `percentualSeguro` nunca emite `NaN`; geometria comparada em números com `segmentosDoAnel` + literais medidos; `doesNotMatch(/NaN\|Infinity/)` com total 0 |
| T-179-29 Tampering (ordem de empilhamento) | **aceito e medido** | `z-[2]` é o **único** `z-*` do bloco (travado por teste) e está muito abaixo do header (30) |
| T-179-SC Supply chain | **mitigado** | nenhum pacote; `lucide-react` já era dependência; diff vazio nos 4 manifests |

## Known Stubs

**Nenhum.** Nenhum número, rótulo ou destino deste bloco é constante decorativa: os números saem do
payload do 179-01, os rótulos são literais de UI medidos no protótipo (e nenhum deles afirma um dado
que não existe) e todo destino é `url`/`ver_todos_url`/`urlProdutos` — ausente o destino, o CTA **não é
renderizado** em vez de virar clique mudo.

## Threat Flags

Nenhuma. O plano é 100% cliente: diff vazio em `app/`, `routes/` e `database/` no range da fase.

## Perguntas em aberto para o usuário

1. **O `MapaDeCobertura` perdeu os rótulos `F1`/`F2` visíveis** (D-5). Nas 4 células a fase continua no
   `title`/`aria-label` de cada uma e na posição, mas não há texto "F1"/"F2" na tela, e a célula tem
   raio 5 em vez de 4 e letra 10,5px em vez de 10. Consertar exige uma prop opcional no componente de
   identidade — **fase que possa tocar `identidade/`**, não esta.
2. **`urlProdutos` não existe no payload hoje.** O `179-09` tem de montá-la (`publicador.produtos`).
   Enquanto não vier, o botão "Ver produtos" e os dois CTA do empty state não aparecem — o texto
   explicativo do vazio continua.
3. ⚠️ **NADA foi visto renderizado.** Não há navegador neste ambiente e o banco local não tem empresa
   com `ml_token`. Toda a conferência é de teste automatizado, de sonda sobre o CSS construído e de
   mutação. **A validação visual é do usuário, em produção, na conta 459.**

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/visaogeral/jornada.js` — FOUND (450 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/JornadaDosProdutos.jsx` — FOUND (483 linhas)
- `tests/js/publicador-visao-geral-jornada.test.js` — FOUND (1.947 linhas)
- commits `b8f92fd9`, `504c6e17`, `50d58ddc`, `5f4519d4`, `59a87849` — FOUND em `git log`
- os dois arquivos de código idênticos ao commit depois das 32 mutações (`sha256` conferido em cada
  rodada e no fim: `cc7a69ae2390c4da` / `e2f4b1931b170f86`)
