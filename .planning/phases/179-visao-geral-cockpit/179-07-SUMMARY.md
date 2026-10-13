---
phase: 179-visao-geral-cockpit
plan: 07
subsystem: ui
tags: [react, publicador, visao-geral, acoes, severidade, saude-da-conta, publicacoes, fidelidade, tailwind, mutacao, rend-04, vg-18]

requires:
  - phase: 177-identidade-e-header
    provides: "TileDoProduto (props `raio`/`letra`), identidade.js (`tomDoProduto`), a camada de tokens `pub-*` e a asserção negativa do ERP só declarado (177-03)"
  - phase: 178-modal-nova-publicacao
    provides: "O molde do gate por igualdade de CONJUNTO de classes e o harness esbuild + react-dom/server"
  - phase: 179-visao-geral-cockpit
    provides: "179-02: o contrato `acoes` + a rota de adiar · 179-03: `saude`, `publicacoesMl`, `acervoImagens` · 179-04: o token `bg-pub-raised-0` · 179-05: as 23 props na página · 179-06: o achado do `content` do Tailwind e os 4 erros de medidor"
provides:
  - "resources/js/Components/Mlb/Publicador/visaogeral/acoes.js — literais dos TRÊS blocos + os view models puros (sem uma classe do Tailwind)"
  - "resources/js/Components/Mlb/Publicador/visaogeral/OQueFazerAgora.jsx — segmented, severidade, expandir, adiar"
  - "resources/js/Components/Mlb/Publicador/visaogeral/SaudeDaConta.jsx — status geral + 3 linhas + Atualizar com spinner"
  - "resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesNoMercadoLivre.jsx — total, barra de 4 faixas, legenda e acervo"
  - "tests/js/publicador-visao-geral-acoes.test.js — 214 testes, 42 mutações, 114 classes conferidas no CSS construído com 13 iscas"
affects: [179-09-composicao-da-pagina, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "⚠️ NENHUMA classe do Tailwind num `.js` de `resources/js` (o `content` varre `**/*.jsx`) — agora com gate de 14 padrões e isca, lendo o config CRU"
    - "Toda flag de render nasce em FUNÇÃO PURA e chega pelo view model: `aba.ativa` existe porque `aba.chave === filtro` dentro do `.map()` leria variável de escopo do componente (REND-04) — o gate pegou"
    - "Guarda de DESTINO e de PARÂMETRO mais estrita que guarda de TEXTO: `textoUtil(7)` devolve `'7'`, e isso inventava rota, parâmetro de URL e nome de ERP"
    - "Prop de ESTADO INICIAL (`expandidasIniciais`, `atualizandoInicialmente`) quando o estado só nasce de clique: sem ela a asserção negativa é vazia por construção"
    - "O modificador de opacidade do Tailwind compila para HEX DE 8 DÍGITOS, e o byte de alfa PROVA a opacidade — é o que distingue 0.13 de 0.12 no CSS construído"
    - "Localizar elemento pelo ÍNDICE ABSOLUTO de `tagsDe`, nunca por `html.indexOf(tagDeAbertura)`: dois elementos irmãos com a MESMA string de classes fazem o `indexOf` medir sempre o primeiro"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/visaogeral/acoes.js
    - resources/js/Components/Mlb/Publicador/visaogeral/OQueFazerAgora.jsx
    - resources/js/Components/Mlb/Publicador/visaogeral/SaudeDaConta.jsx
    - resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesNoMercadoLivre.jsx
    - tests/js/publicador-visao-geral-acoes.test.js
  modified: []

key-decisions:
  - "Os TRÊS ícones de severidade são desenhados à mão com o `path` do protótipo: o Lucide só dá o triângulo e o círculo em CONTORNO, e `fill` + `currentColor` escuro produziria uma borda de 2,7 num ícone de 17px (metade do ícone)"
  - "O dot do segmented usa a cor do TEXTO (`SEV[k][1]`, `:520`), não a tinta sólida que a tabela do plano prescreveu — e isso NÃO se uniformiza com a pílula da linha da Saúde (`:250`), que usa clara no texto e sólida no dot"
  - "O dot do status geral da Saúde também usa a cor CLARA (`healthColor` nos dois, `:238`), ao contrário da prescrição do plano"
  - "O ERP ganha nome `declarado ?? 'ERP'` E meta `Declarado: X` (as duas prescrições do plano, mesmo com a leve redundância): é a meta que carrega o enquadramento de D-1, e inventar outra frase afirmaria uma procedência que o dado não garante"
  - "A linha do ML ganha a pílula de status que a tabela FID-01 omitiu (`:250`) — mas SÓ ela: dar a pílula ao ERP é literalmente dizer 'Conectado', o que D-1 proíbe"
  - "A ação com destino `{acao: 'sincronizar'}` sai SEM CTA em vez de reusar `BotaoSincronizarPortal`: aquele componente carrega `ecf-yellow`/`border-white/[0.10]` (vetados por FID-04) e 40px de altura contra os 32 medidos"
  - "O número da ação é elemento IRMÃO do título, no mesmo corpo de 14px/600 e com `tabular-nums`: o servidor manda `titulo` e `numero` separados (179-02), o protótipo os concatena numa string"
  - "A barra e a legenda NÃO nascem sem faixa medida: um trilho cinza vazio afirmaria uma decomposição que ninguém mediu"
  - "O chevron é um `<button>` SEM handler próprio (o clique sobe para a linha) e `disabled` quando não há sub-item: o teclado alcança a gaveta sem alternar duas vezes, e não há botão mudo"

metrics:
  duration: "~4h"
  completed: 2026-10-13
  tasks: 3
  tests_added: 214
  mutations: 42
  mutations_caught: 42
  mutations_survived: 0
  classes_provadas_no_css: 114
  iscas_da_sonda: 13
  migrations: 0
  pacotes: 0
---

# Fase 179 Plano 07: os três blocos do meio do cockpit Summary

"O que fazer agora" com severidade, gaveta e adiamento que sobrevive à recarga; "Saúde da conta" com
o ERP que **nunca diz "Conectado"**; e "Publicações no Mercado Livre" com quatro faixas que **somam o
total** e nenhuma faixa "Com erro" inventada — reproduzidos do protótipo medida por medida, com **42
mutações reprovadas** e **114 classes conferidas pelo VALOR no CSS construído**.

## ⚠️ O que mais importa para o 179-09 (leia isto primeiro)

**1. Os QUATRO arquivos novos para `ARQUIVOS_NOVOS` de `tests/js/publicador-vocabulario-novo.test.js`:**

```
resources/js/Components/Mlb/Publicador/visaogeral/acoes.js
resources/js/Components/Mlb/Publicador/visaogeral/OQueFazerAgora.jsx
resources/js/Components/Mlb/Publicador/visaogeral/SaudeDaConta.jsx
resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesNoMercadoLivre.jsx
```

Somados aos **dois do 179-06** (`jornada.js` e `JornadaDosProdutos.jsx`), são **SEIS** nomes.
⚠️ **Esquecer faz o gate de vocabulário passar por OMISSÃO, sem acusar nada** — aquela constante é
fixa de propósito. E **`8.5` tem de entrar em `TAMANHOS_PERMITIDOS` no MESMO commit** (hoje a lista é
`10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34`); o protótipo
declara `font-size:8.5px` nas iniciais (`:191`, `:202`) e os componentes usam `text-[8.5px]`. Este
plano deixou o aviso **dentro do gate**, em três testes que viram reprovação se os arquivos entrarem
sem o `8.5` ou **pela metade**.

**2. As props que os três componentes esperam:**

| Componente | Props |
|---|---|
| `OQueFazerAgora` | `acoes` (payload do 179-02) · `contaChave` (string\|null) |
| `SaudeDaConta` | `saude` (payload do 179-03) |
| `PublicacoesNoMercadoLivre` | `publicacoesMl` · `acervoImagens` · `urlPublicacoes` (string\|null) |

⚠️ **`contaChave`** é `empresa.chave` (a mesma que a `AbasDaConta` usa). Sem ela o botão "Adiar até
amanhã" **não é renderizado** (botão sem destino é proibido) — o resto do bloco funciona.
⚠️ **`urlPublicacoes`** **não existe no payload** das 23 props: o 179-09 tem de montá-la
(`mlb.anuncios.publicador.publicacoes`, se o nome da rota existir — conferir). Sem ela o
"Ver todas →" não nasce; a legenda continua navegando, porque cada faixa traz a **sua** `url`.

**3. As duas props de estado inicial são SÓ do gate** (`expandidasIniciais`, `atualizandoInicialmente`):
o 179-09 **não as passa**. O padrão é o do protótipo — tudo fechado (`:390`, `open: {}`) e sem
spinner (`syncing: false`).

---

## FID-01 — as medidas: protótipo × implementado

Fonte: `new_layout_publicador/Visao Geral.dc.html`. ⚠️ **As linhas abaixo foram MEDIDAS no arquivo**;
as do `<action>` do plano estão deslocadas (ver a seção seguinte).

### "O que fazer agora" (`:165-:219`)

| # | Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|---|
| 1 | card | `bg #111111`, `1px #262626`, raio 16, `overflow:hidden` (`:165`) | `overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface` |
| 2 | cabeçalho | `padding:18px 20px 14px; gap:16px; space-between` (`:166`) | `flex items-center justify-between gap-[16px] px-[20px] pb-[14px] pt-[18px]` |
| **2b** | **coluna do título** | **`flex column; gap:4px` (`:167`)** | **`flex flex-col gap-[4px]`** — ⚠️ **AUSENTE da tabela do plano** |
| 3 | H2 | `17px/600; -0.01em; margin:0` (`:168`) | `m-0 text-[17px] font-semibold tracking-[-0.01em]` |
| 4 | subtítulo | `13px #8A8A90` (`:169`) | `text-[13px] text-pub-txt-3` |
| 5 | trilho do segmented | `gap:2px; padding:3px; bg #161616; 1px #1F1F1F; raio 10` (`:170`) | `flex items-center gap-[2px] rounded-pub-sub border border-pub-line-subtle bg-pub-hover-2 p-[3px]` |
| 6 | opção do segmented | `h:28px; padding:0 10px; raio 7; 12.5px/500; gap:6px; border:none; cursor:pointer; nowrap`; ativa `bg #262626` + `inset 0 0 0 1px #333333` + `#F4F4F5`; inativa `transparent` + `#A1A1AA` (`:172`) | `flex h-[28px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-pub-control-sm border-none px-[10px] text-[12.5px] font-medium` + `bg-pub-seg text-pub-txt shadow-pub-seg-ativo` / `bg-transparent text-pub-txt-2` — ⚠️ o `whitespace-nowrap` e o `cursor-pointer` **não estão** na linha 6 da tabela do plano |
| 7 | dot da opção | `6×6; 50%`, cor = **`SEV[k][1]`**, e **"Todas" NÃO tem dot** (`:173`, `:520`) | `h-[6px] w-[6px] rounded-full` + `bg-pub-danger-txt` / `bg-pub-warn-txt` / `bg-pub-accent` — ⚠️ **divergência V-1, abaixo** |
| 8 | contagem da opção | `#71717A; tabular-nums` (`:173`) | `tabular-nums text-pub-txt-4` |
| 9 | linha da ação | `border-top 1px #1F1F1F; bg {#131313 recomendada / #111111}` (`:176`) | `border-t border-pub-line-subtle` + `bg-pub-raised-0` / `bg-pub-surface` |
| 10 | grid da ação | `38px minmax(0,1fr) auto auto 28px; gap:14px; padding:14px 20px; cursor:pointer`; hover `#141414` (`:177`) | `grid cursor-pointer grid-cols-[38px_minmax(0,1fr)_auto_auto_28px] items-center gap-[14px] px-[20px] py-[14px] hover:bg-pub-hover` |
| 11 | tile do ícone | `38×38; raio 10; bg {tint}` (`:178`) | `grid h-[38px] w-[38px] place-items-center rounded-pub-sub` + tint |
| 12 | ícone crítico | `<path d="M8 1.5L15 14H1z" fill:#EF4444>` + `<path d="M8 6v3.5M8 11.6v.1" stroke:#0A0A0A 1.8 round>`, svg 17×17 `viewBox 0 0 16 16` (`:179`) | o MESMO svg, com `fill-pub-danger` e `fill-none stroke-pub-page` — ⚠️ **divergência D-1** |
| 13 | ícone atenção | `<circle r=6.5 fill:#F59E0B>` + `<path d="M8 4.8v3.6M8 10.9v.1">` (`:180`) | o MESMO svg, com `fill-pub-warn` — ⚠️ **D-1** |
| 14 | ícone oportunidade | 2 `path`, `fill:none; stroke:#FACC15; 1.8; round` (`:181`) | o MESMO svg, com `fill-none stroke-pub-accent` — ⚠️ **D-1** |
| **14b** | **coluna de texto** | **`flex column; gap:4px; min-width:0` (`:182`)** | **`flex min-w-0 flex-col gap-[4px]`** — ⚠️ **AUSENTE da tabela**; é o `min-width:0` dela que faz o `truncate` funcionar |
| 15 | linha de título | `flex; gap:8px; flex-wrap:wrap` (`:183`); título `14px/600` (`:184`) | `flex flex-wrap items-center gap-[8px]` + `text-[14px] font-semibold` |
| **15b** | **o número** | o protótipo o concatena DENTRO do título (`:515`) | elemento irmão, `text-[14px] font-semibold tabular-nums` — ⚠️ **divergência D-6** |
| 16 | badge de severidade | `10.5px/700; 0.06em; padding:2px 6px; raio 4; bg {tint}; color {cor}` (`:185`) | `rounded-pub-badge px-[6px] py-[2px] text-[10.5px] font-bold tracking-[0.06em]` + tint + cor |
| 17 | badge RECOMENDADO AGORA | `10.5px/600; 0.06em; padding:2px 6px; raio 4; 1px #3F3F46; #D4D4D8` (`:186`) | `rounded-pub-badge border border-pub-line-off px-[6px] py-[2px] text-[10.5px] font-semibold tracking-[0.06em] text-pub-fase1` |
| 18 | descrição | `12.5px #A1A1AA`, uma linha com ellipsis (`:188`) | `truncate text-[12.5px] text-pub-txt-2` |
| **18b** | **invólucro da pilha** | **`flex; align-items:center` (`:189`)** | **`flex items-center`** — ⚠️ **AUSENTE da tabela** |
| 19 | tile da pilha | `26×26; raio 7; margin-left:-7px; border 2px #111111; 8.5px/700`, até **3** (`:191`) | `<TileDoProduto tamanho={26} raio={7} letra="text-[8.5px]" className="-ml-[7px] border-2 border-pub-surface font-bold" />` |
| 20 | CTA | `h:32px; padding:0 14px; raio 8; 12.5px/600; nowrap`; recomendada `#FACC15` + `#0A0A0A` + `1px #FACC15`; outras `#161616` + `#F4F4F5` + `1px #2E2E2E` (`:193`) | `flex h-[32px] items-center whitespace-nowrap rounded-[8px] border px-[14px] text-[12.5px] font-semibold` + `border-pub-accent bg-pub-accent text-pub-page` / `border-pub-line-strong bg-pub-hover-2 text-pub-txt` |
| 21 | chevron | `28×28; #71717A`; svg 14×14 stroke **1.8**; `rotate(180deg)` aberto; `transition .2s` (`:194`/`:195`) | `grid h-[28px] w-[28px] cursor-pointer place-items-center border-none bg-transparent text-pub-txt-4` + `<ChevronDown size={14} strokeWidth={2.7} className="transition-transform duration-200" />` + `rotate-180` |
| 22 | sub-lista (contêiner) | `padding:0 20px 14px 72px; gap:2px` (`:198`) | `flex flex-col gap-[2px] pb-[14px] pl-[72px] pr-[20px] pt-0` — ⚠️ **D-7** |
| 22 | sub-lista (item) | `grid minmax(0,1fr) auto auto; gap:14px; h:40px; padding:0 12px; raio 8; bg #0D0D0D; 1px #1C1C1C` (`:200`) | `grid h-[40px] grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-[14px] rounded-[8px] border border-pub-line-subtle-2 bg-pub-sunken px-[12px]` |
| 22 | sub-lista (miolo) | span `flex; gap:10px; min-width:0` (`:201`); ini `22×22 raio 6 8.5px/700 flex:none` (`:202`); nome `13px/500` ellipsis (`:203`); meta `12px #8A8A90 nowrap` (`:204`); motivo `12px {cor} nowrap` (`:206`); `Abrir →` `12px/500 #A1A1AA nowrap` (`:207`) | `flex min-w-0 items-center gap-[10px]` · `<TileDoProduto tamanho={22} raio={6} letra="text-[8.5px]" className="font-bold" />` · `truncate text-[13px] font-medium` · `whitespace-nowrap text-[12px] text-pub-txt-3` · `whitespace-nowrap text-[12px]` + cor da severidade · `whitespace-nowrap text-[12px] font-medium text-pub-txt-2` |
| 22 | "Adiar até amanhã" | contêiner `flex; justify-end; padding-top:6px` (`:209`); botão `h:26px; padding:0 10px; raio 6; 12px #71717A; border:none; bg transparent`; hover `bg #1A1A1A` + `#D4D4D8` (`:210`) | `flex justify-end pt-[6px]` + `h-[26px] cursor-pointer whitespace-nowrap rounded-pub-chip border-none bg-transparent px-[10px] text-[12px] text-pub-txt-4 hover:bg-pub-hover-3 hover:text-pub-fase1` |
| — | vazio | `flex; gap:14px; padding:22px 20px; border-top 1px #1F1F1F` (`:216`); tile `38×38 raio 10 rgba(34,197,94,0.12)` (`:217`); svg 16×16 stroke **2** `#4ADE80` (`:218`); coluna `gap:2px`, `14px/600` e `12.5px #8A8A90` (`:219`) | `flex items-center gap-[14px] border-t border-pub-line-subtle px-[20px] py-[22px]` · `grid h-[38px] w-[38px] place-items-center rounded-pub-sub bg-pub-ok/[0.12]` · `<Check size={16} strokeWidth={3} className="text-pub-ok-txt" />` · `flex flex-col gap-[2px]` + `text-[14px] font-semibold` + `text-[12.5px] text-pub-txt-3` |

### "Saúde da conta" (`:235-:250`)

| Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|
| card | `bg #111111; 1px #262626; raio 16; overflow:hidden` (`:235`) | `overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface` |
| cabeçalho | `flex; center; space-between; padding:16px 18px 10px` (`:236`) | `flex items-center justify-between px-[18px] pb-[10px] pt-[16px]` |
| H2 | `15px/600; margin:0` (`:237`) | `m-0 text-[15px] font-semibold` |
| status geral | `flex; gap:6px; 12px/500; color {healthColor}` + dot `7×7 50% bg {healthColor}` (`:238`) | `flex items-center gap-[6px] text-[12px] font-medium` + cor + `h-[7px] w-[7px] rounded-full` + **a MESMA cor** (⚠️ **V-2**) |
| linha | `flex; gap:12px; padding:11px 18px; border-top 1px #1C1C1C; bg {tint}` (`:240`) | `flex items-center gap-[12px] border-t border-pub-line-subtle-2 px-[18px] py-[11px]` + `bg-pub-danger/[0.07]` ou `bg-pub-warn/[0.07]` |
| tile | `32×32; raio 8; 10.5px/700; flex:none`; ML `#FACC15`/`#0A0A0A`, outros `#1F1F1F`/`#D4D4D8` (`:241`, `:524-:529`) | `grid h-[32px] w-[32px] flex-none place-items-center rounded-[8px] text-[10.5px] font-bold` + `bg-pub-accent text-pub-page` / `bg-pub-line-subtle text-pub-fase1` |
| coluna | `flex column; gap:2px; flex:1; min-width:0` (`:242`) | `flex min-w-0 flex-1 flex-col gap-[2px]` |
| nome / meta | `13px/500` (`:243`) · `11.5px #8A8A90` ellipsis (`:244`) | `text-[13px] font-medium` · `truncate text-[11.5px] text-pub-txt-3` |
| tile da Sincronização | o CARACTERE **`⟳`** dentro do tile (`:531`, `health[2].tile = '⟳'`) | o mesmo caractere, com `aria-hidden` |
| botão "Atualizar" | `flex; gap:6px; h:28px; padding:0 10px; 1px #2E2E2E; raio 7; bg #111111; #D4D4D8; 12px; cursor:pointer; nowrap`; hover `border-color:#3F3F46` (`:246`) | `flex h-[28px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-pub-control-sm border border-pub-line-strong bg-pub-surface px-[10px] text-[12px] text-pub-fase1 hover:border-pub-line-off` |
| spinner | `11×11; 50%; border 1.5px #3F3F46; border-top-color #FACC15; animation spin .7s linear infinite` (`:247`) | `h-[11px] w-[11px] animate-pub-spin rounded-full border-[1.5px] border-pub-line-off border-t-pub-accent` |
| **pílula da linha** | **`flex; gap:6px; 12px/500; color {h.color}` + dot `7×7` `bg {h.dot}` (`:250`)** | **`flex items-center gap-[6px] text-[12px] font-medium` + `text-pub-ok-txt`/`text-pub-danger-txt` + dot `bg-pub-ok`/`bg-pub-danger`** — ⚠️ **AUSENTE da tabela do plano**, e **só na linha do ML** (ver D-2) |

### "Publicações no Mercado Livre" (`:256-:272`)

| Elemento | Protótipo (linha medida) | Implementado |
|---|---|---|
| card | `bg #111111; 1px #262626; raio 16; padding:16px 18px 18px; flex column; gap:14px` (`:256`) | `flex flex-col gap-[14px] rounded-pub-card border border-pub-line bg-pub-surface px-[18px] pb-[18px] pt-[16px]` |
| cabeçalho / H2 / "Ver todas →" | `flex; center; space-between` (`:257`) · `15px/600` (`:258`) · `12.5px/500 #A1A1AA nowrap` (`:259`) | `flex items-center justify-between` · `m-0 text-[15px] font-semibold` · `whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2` |
| linha do total | `flex; align-items:baseline; gap:8px` (`:260`) | `flex items-baseline gap-[8px]` — ⚠️ **AUSENTE da tabela** |
| total | `30px; line-height:1; 600; -0.03em; tabular-nums` (`:260`) | `text-[30px] font-semibold leading-none tracking-[-0.03em] tabular-nums` |
| split | `12.5px #8A8A90`, texto `ofertas · {pubSplit}` (`:260`, `:606`) | `text-[12.5px] text-pub-txt-3` + `{OFERTAS} · {split}` |
| barra | `flex; gap:2px; h:8px; raio 4; overflow:hidden; bg #1C1C1C` (`:261`) | `flex h-[8px] gap-[2px] overflow-hidden rounded-[4px] bg-pub-line-subtle-2` |
| faixa | `flex-grow:{n}; flex-basis:0; background:{color}` — **sem raio próprio** (`:262`) | `basis-0` + `style={{flexGrow}}` + `bg-pub-ok`/`bg-pub-warn`/`bg-pub-neutral-dot`/`bg-pub-line-off` |
| contêiner da legenda | `flex column; gap:2px` (`:263`) | `flex flex-col gap-[2px]` — ⚠️ **AUSENTE da tabela** |
| linha da legenda | `flex; gap:10px; h:30px; padding:0 8px; margin:0 -8px; raio 7; 13px #D4D4D8; nowrap`; hover `bg #1A1A1A` (`:265`) | `flex h-[30px] items-center gap-[10px] -mx-[8px] whitespace-nowrap rounded-pub-control-sm px-[8px] text-[13px] text-pub-fase1 hover:bg-pub-hover-3` |
| swatch / número | `8×8 raio 2 bg {color}` · `margin-left:auto; tabular-nums; 500; color {numColor}` (`:266`, `:536`) | `h-[8px] w-[8px] rounded-[2px]` + cor · `ml-auto font-medium tabular-nums` + `text-pub-txt` (`> 0`) / `text-pub-txt-off` (`0`) |
| acervo | `flex; center; gap:12px; padding:12px; raio 10; bg #0D0D0D; 1px #1C1C1C` (`:268`) | `flex items-center gap-[12px] rounded-pub-sub border border-pub-line-subtle-2 bg-pub-sunken p-[12px]` |
| mosaico | `grid repeat(3,14px); gap:3px`; 6 quadrados `14×14 raio 3`; hues **250, 70, 330, 160, #FACC15, 30** (`:269-:271`) | `grid grid-cols-[repeat(3,14px)] gap-[3px]` + `h-[14px] w-[14px] rounded-[3px]` + `tomDoProduto(semente).fundo` nos 5 e `bg-pub-accent` no **quinto** |
| textos do acervo | `flex column; gap:2px; flex:1`; `13px/500` e `11.5px #8A8A90` (`:272`) | `flex flex-1 flex-col gap-[2px]` + `text-[13px] font-medium` + `text-[11.5px] text-pub-txt-3` |

---

## ⚠️ As medidas em que o PROTÓTIPO venceu a tabela FID-01 do plano

**Placar: 16 divergências medidas, todas resolvidas a favor do protótipo.**

### 1. As 10 referências de linha deslocadas (`arquivo:linha`)

O plano foi escrito contra uma contagem de linhas diferente. As da sub-lista e as da aritmética
**estão certas**; o resto desloca.

| O plano cita | `Visao Geral.dc.html` tem | Elemento |
|---|---|---|
| `:162` | **`:165`** | card de "O que fazer agora" |
| `:163` | **`:166`** | cabeçalho |
| `:165` / `:166` | **`:168`** / **`:169`** | H2 e subtítulo |
| `:168` / `:170` / `:171` | **`:170`** / **`:172`** / **`:173`** | trilho, opção, dot + contagem |
| `:185` / `:186` / `:187` | **`:183`** / **`:185`** / **`:186`** | linha de título, badge de severidade, RECOMENDADO |
| `:189` / `:192` | **`:188`** / **`:191`** | descrição e tile da pilha |
| `:195` / `:196` | **`:193`** / **`:194`** | CTA e chevron |
| `:200-:212` | **`:198-:210`** | sub-lista |
| `:225-:255` | **`:235-:254`** | Saúde da conta (**+10**) |
| `:258-:281` | **`:256-:274`** | Publicações no ML (**−2**) |

As que o plano **acertou**: `:176`, `:177`, `:178`, `:179-:181` e `:216` (card de ações), e as três
faixas `:512`/`:513`/`:516` citadas no corpo do `<action>` (`sevCount`, `shown`, o `bg: top` e o
`open && items.length > 0`).

⚠️ **E as referências da ARITMÉTICA também estavam deslocadas** — o `<action>` cita `:423` para o
`SEV`, `:559` para o `actionsSub` e `:217` para o `VAZIO_TEXTO`; o arquivo tem **`:425`**, **`:603`**
e **`:219`**. ⚠️ **Eu errei as mesmas referências uma primeira vez** por contá-las a partir de uma
saída de `sed` deslocada, e só o `grep -n` as fixou: `SEV` **`:425`** · `actions`/`isTop` **`:514`**
· `sevLabel` **`:515`** · `bg: top`/`open &&` **`:516`** · `stopPropagation` **`:518`** ·
`sevTabs`/`dot` **`:520`** · `health[]` **`:524-:530`** (ML `:525-:526`, ERP `:527-:528`,
Sincronização `:529`) · `health[2].tile = '⟳'` **`:531`** · `healthIssue` **`:532`** ·
`pubBar` **`:536`**. Está na mesma classe dos três erros de medidor da seção seguinte: **contar não é
medir.**

### 2. As 2 medidas em que o VALOR da tabela estava errado

| # | A tabela do plano diz | Medido no protótipo | Entregue |
|---|---|---|---|
| **V-1** | dot do segmented na tinta SÓLIDA (`dot:'bg-pub-danger'` / `'bg-pub-warn'` / `'bg-pub-accent'`) | `dot: k === 'all' ? '' : this.SEV[k][1]` (`:520`) — `SEV[k][1]` é a cor **do texto**: `#F87171`, `#FBBF24`, `#FACC15` | `bg-pub-danger-txt` / `bg-pub-warn-txt` / `bg-pub-accent` |
| **V-2** | dot do status geral da Saúde em `bg-pub-ok`/`bg-pub-warn`/`bg-pub-danger` | `background:{{ healthColor }}` (`:238`) — a **MESMA** variável do texto, e `healthIssue` dá a cor CLARA (`:532`) | `bg-pub-ok-txt` / `bg-pub-warn-txt` / `bg-pub-danger-txt` |

⚠️ **V-1 e V-2 NÃO se uniformizam com a pílula da linha da Saúde** (`:250`), que usa `h.color` (clara)
no **texto** e `h.dot` (sólida) no **dot** — `#4ADE80` com `#22C55E`. São **três** regras de
dot/texto no mesmo cockpit, e cada uma foi medida onde está. Dois testes travam a não-uniformização.

### 3. As 7 medidas que a tabela OMITIU

| # | O que faltava | Protótipo | Entregue |
|---|---|---|---|
| O-1 | a **coluna do título** do cabeçalho | `flex column; gap:4px` (`:167`) | `flex flex-col gap-[4px]` |
| O-2 | a **coluna de texto** da ação (com o `min-width:0` que faz o `truncate` funcionar) | `flex column; gap:4px; min-width:0` (`:182`) | `flex min-w-0 flex-col gap-[4px]` |
| O-3 | o **invólucro da pilha** de afetados | `flex; align-items:center` (`:189`) | `flex items-center` |
| O-4 | o `white-space:nowrap` e o `cursor:pointer` da **opção do segmented** | `:172` | `whitespace-nowrap` + `cursor-pointer` |
| O-5 | a **pílula de status da linha** da Saúde | `flex; gap:6px; 12px/500` + dot `7×7` (`:250`) | só na linha do ML (ver D-2) |
| O-6 | a **linha do total** de Publicações | `flex; align-items:baseline; gap:8px` (`:260`) | `flex items-baseline gap-[8px]` |
| O-7 | o **contêiner da legenda** | `flex column; gap:2px` (`:263`) | `flex flex-col gap-[2px]` |

### 4. As 3 medidas que o plano mandou MEDIR — e o que a medição disse

| O plano pediu | Medido | Decisão |
|---|---|---|
| *"`SUBTITULO(n)` — no singular o protótipo não trata; **meça**"* | `acts.length + ' ações priorizadas…'` (`:603`) — **sempre plural**, inclusive "1 ações" | **plural mantido**, com teste que reprova quem "consertar" (`assert.ok(!A.SUBTITULO(1).includes('1 ação'))`) |
| *"o ícone da linha de sincronização é o caractere `⟳` — **meça**"* | `health[2].tile = '⟳'` (`:531`) — **é o caractere**, dentro do tile de 32px | **o caractere**, `aria-hidden`; e um teste trava a ausência de `RefreshCw` |
| *"se o Lucide não der o preenchido com o '!' escuro, **meça** e desenhe à mão"* | `<path d="M8 1.5L15 14H1z" fill:#EF4444>` sem contorno (`:179`); `<circle r=6.5 fill>` sem contorno (`:180`) | **os três desenhados à mão** (D-1) |

---

## Divergências declaradas, com a restrição concreta

| # | O protótipo / o plano diz | O que foi entregue | Restrição concreta |
|---|---|---|---|
| **D-1** | `<AlertTriangle size={17} strokeWidth={2.7} className="fill-pub-danger text-pub-page" />` e `<AlertCircle …>` (prescrição do plano) | os três ícones **desenhados à mão**, com o `path` do protótipo e `viewBox="0 0 16 16"` | O Lucide desenha o triângulo e o círculo em **CONTORNO**. Pedir `fill` + `currentColor` escuro dá um triângulo vermelho **com borda escura de 2,7** — num ícone de 17px isso é metade da peça, e o protótipo não tem borda nenhuma. O plano previu esta saída (*"se o Lucide não der o preenchido … desenhe o `<svg>` à mão com o `path` do protótipo"*). A geometria do Lucide também é outra (`TrendingUp` não é o mesmo traço de `:181`). Os três ficam num componente local (`IconeDaSeveridade`), em `viewBox` 16, **sem conversão de `strokeWidth`** — não há Lucide ali. |
| **D-2** | a linha do ERP tem `status: 'Conectado'`, `color: '#4ADE80'`, `dot: '#22C55E'` (`:527`) | o ERP **não tem pílula, não tem dot e não diz "Conectado"**; a pílula existe **só** na linha do ML | **O ERP não é integração neste sistema** — é DECLARADO (ficha de onboarding ou sync da planilha de Polos), medido e travado na Fase 177-03 com `assertArrayNotHasKey('conectado', $erp)`, e repetido no 179-03 (`problema` sempre `false`, mutações M04/M05). Quatro asserções **negativas** no gate: a linha não contém `Conectado`, nem `Desconectado`, nem `rounded-full`, nem `bg-pub-ok`; e com `declarado: null` o `geral` **continua** `tudo_conectado`. |
| **D-3** | a barra é `Ativas / Pausadas / **Com erro**` (`:536`) | **4 faixas** (`ativas/pausadas/em_revisao/encerradas`) que SOMAM o total, + a legenda **"Pedem ação"** | `MlAcervoItem.status` só assume `active|paused|under_review|closed` — **não existe status de erro** (medido na 177-03). O terceiro sinal real é `severidade > 0`, o MESMO número do dot da aba Publicações. Varredura por `Com erro` nas 4 fontes e no HTML: **ausente**. ⚠️ `outras` **não** virou faixa e **não** foi somado a "Encerradas" (teste com resíduo 3 e `faixas[3].total === 0`). |
| **D-4** | o acervo tem `Abrir →` (`:273`) | os dois números, **sem link** | Não existe tela de acervo **por conta**: ele é endereçado por `publicador/produtos/{produto}/acervo` e vive **dentro do editor**, território de outro dev nesta fase. `Abrir →` sem destino é botão mudo (EXMP-03). Teste: `Abrir →` **ausente** do card de Publicações. |
| **D-5** | o CTA de uma ação cujo destino é `{acao:'sincronizar'}` seria "Sincronizar" | **nenhum CTA** — a ação aparece com título, número, descrição e sub-lista | `destinoHref({acao:'sincronizar'})` é `null` por desenho do plano (*"não é link, é ação"*). O botão REAL é o `BotaoSincronizarPortal`, que **já existe** — mas carrega `ecf-yellow`, `border-white/[0.10]` e `h-10`, todos **vetados** por FID-04/FID-03 no layout novo, e reestilizá-lo seria mexer em componente de 3 outras telas. A ação de sincronizar é da tela de **Produtos**, onde aquele botão já vive (duas vezes). Teste: com `{acao:'sincronizar'}` o literal `>Sincronizar<` está **ausente** e a ação **continua na tela**. |
| **D-6** | o número vem concatenado dentro do título (`:515`, `cnt.ready + ' produtos prontos…'`) | o número é elemento **irmão** do título, no mesmo `text-[14px] font-semibold`, com `tabular-nums` | O servidor manda `titulo` e `numero` em campos **separados** (179-02: `titulo` é o `texto` da linha de hoje byte a byte, e `numero` é campo próprio). Concatenar produziria *"7 Anúncios pausados ou sem estoque"* — e interpolar número dentro de frase perde o `tabular-nums` que toda cifra desta camada tem. Renderizado: `7` e o título lado a lado no `gap:8px` do protótipo. |
| **D-7** | `padding:0 20px 14px 72px` na sub-lista | `pb-[14px] pl-[72px] pr-[20px] pt-0` (quatro lados explícitos) | `px-[20px] pl-[72px]` deixa as **duas** classes no HTML, e aí quem vence o lado esquerdo depende da ordem no CSS construído, não do `cn()`. Quatro lados explícitos não têm conflito. Valor computado idêntico, provado na sonda (`padding-left:72px`). |
| **D-8** | `SEVERIDADES` carrega `texto`, `tint` e `dot` em `acoes.js` (prescrição do plano) | `SEVERIDADES` só carrega `rotulo` e `ordem`; os **3 mapas de classe** moram no `.jsx` | `tailwind.config.js:12` varre `**/*.jsx` e **não** `*.js`. Classe escrita num `.js` não gera CSS: a tinta do ícone sairia **transparente** e o dot do segmented **invisível**, com todos os testes de render passando. Achado medido no 179-06; agora com gate de **14 padrões** + isca. |
| **D-9** | `SEM_MEDIDA` para `total` nulo, sem distinguir o motivo | **dois** textos de split: `nunca coletado` (total nulo) × `nenhuma ainda` (total 0, o literal do protótipo) | `ativas_pct` null tem **DOIS** significados (179-03, armadilha 3). O protótipo só conhece o segundo (`pubSplit: pubTotal ? … : 'nenhuma ainda'`, `:606`) porque lá o dado é fixo e sempre existe. O primeiro precisa de texto próprio, senão "nenhuma ainda" afirmaria zero onde nunca houve coleta. Teste com os dois lados e `assert.notEqual`. |
| **D-10** | a barra e a legenda sempre existem | **não nascem** quando `faixas` está vazio | Um trilho cinza de 8px sem faixa nenhuma afirma uma decomposição que ninguém mediu — o mesmo vício do `0` onde não houve medida (VG-18). Mutação M42 (`{true && (`) reprova. |
| **D-11** | o chevron é um `<span>` com um svg dentro (`:194`) | é um `<button type="button">` **sem handler próprio**, `disabled` sem sub-item, com `aria-expanded` e `title` | O cabeçalho inteiro da ação é o toggle (`:177`), e um `<div onClick>` é inalcançável pelo teclado. O `<button>` sem handler resolve: o clique dele (inclusive o gerado por Enter/Espaço) **sobe** para a linha, então não há alternância dupla. `disabled` quando `itens` está vazio porque um botão que não faz nada é mudo — e é o próprio `open && items.length > 0` do protótipo (`:516`) dito no DOM. |
| **D-12** | o `Abrir →` do sub-item é `href="#"` (`:207`) | aponta para o destino **da ação**, e sem destino **não nasce** | O contrato do sub-item é `{nome, meta, motivo}` — **não** tem URL própria (179-02). `href="#"` é destino morto. |

---

## Os três erros de MEDIDOR desta sessão (e o conserto)

⚠️ Os quatro erros que o 179-06 deixou escritos foram todos **evitados** (o `content` é lido cru; os
`tracking-[…]` aceitam o zero suprimido pelo cssnano; nada é contado no documento inteiro; nenhuma
classe foi para o `.js`). Mas **três novos** apareceram — a mesma classe de problema, por caminhos
diferentes.

**1. O CSS escapa o seletor com BARRA INVERTIDA.** A sonda acusou **54 falsas faltas** — todas as
classes de valor arbitrário. O CSS diz `.text-\[8\.5px\]`, `.bg-pub-danger\/\[0\.13\]`,
`.hover\:bg-pub-hover`. Um regex que só escapasse para o motor de expressão regular não casa nada.
Conserto: o construtor do seletor emite `\\?` **antes** de cada caractere especial.

**2. O modificador de opacidade compila para HEX DE 8 DÍGITOS**, não para `rgb(R G B / .13)`:
`bg-pub-danger/[0.13]` → `background-color:#ef444421`. E isso é **melhor** do que procurar o RGB,
porque o byte final **É** a opacidade (`0.13 × 255 = 33 = 0x21`; `0.12 × 255 = 31 = 0x1f`;
`0.07 × 255 = 18 = 0x12`) — é o que faz a sonda distinguir 0.13 de 0.12, exatamente o que as três
tintas de severidade exigem. Iscas `bg-pub-accent/[0.13]` e `bg-pub-danger/[0.12]`: **ausentes**.

**3. `html.indexOf(tagDeAbertura)` mede o elemento ERRADO quando dois irmãos têm a MESMA string de
classes.** As duas ações não recomendadas têm wrapper e grid idênticos; as linhas do ERP e da
Sincronização **sem tint** também. O `indexOf` devolvia sempre o **primeiro**, e com isso o gate do
`⟳` reprovava por motivo errado e os quatro do ERP **mediam a linha do ML**. Conserto: todo
localizador usa o **índice ABSOLUTO** que `tagsDe` já devolve.

### E uma lacuna de medidor que o próprio gate fechou

A asserção *"ação com `itens: []` não abre gaveta"* seria **VAZIA POR CONSTRUÇÃO**: com o estado
inicial `{}` (tudo fechado, como o protótipo), `Abrir →` está ausente de qualquer jeito, e nenhuma
mutação reprovaria. É a mesma lacuna que a mutação **M22 do 179-06** expôs — *"o guard só entra em
ação depois de um clique, e clique não existe sem navegador"*. Conserto: a prop de estado inicial
`expandidasIniciais` (padrão `defaultOpen` do React), que torna a asserção **real** — com a gaveta
pedida aberta nas DUAS ações, a sem item não nasce, e a contra-prova (dando um item à mesma ação)
abre. O mesmo vale para `atualizandoInicialmente` e o spinner, saída que o plano previu.

---

## ⚠️ Três guardas de segurança que o GATE encontrou (Rule 2)

Os três são a mesma lição do **D-8 do 179-06**: `textoUtil` aceita número, e número é texto plausível
mas **não** é destino, nem parâmetro, nem nome.

| # | O que acontecia | Conserto |
|---|---|---|
| 1 | `destino: {rota: 7}` → `textoUtil(7)` = `'7'` → `route('7', {})` → **href inventado** | `rota` tem de ser `typeof === 'string'` |
| 2 | `contaChave = 7` → o botão "Adiar" nascia e postava em `…/empresas/7/acoes/adiar` — **parâmetro de URL inventado** | `conta` passa por `urlSegura()` (string, aparada, não vazia) |
| 3 | `declarado: 7` → a tela dizia **"Declarado: 7"** — nome de ERP inventado | `declarado` tem de ser `typeof === 'string'` |

E uma **violação real de REND-04**, também achada pelo gate: `aba.chave === filtro` dentro do
`.map()` das abas lia `filtro`, variável de escopo do componente — exatamente a forma que o Rollup
elimina neste projeto (`feedback_rollup_map_scope_bug.md`). Virou `aba.ativa`, decidido em
`abasComContagem(contagens, escondidas, filtro)`, função **pura**.

---

## A prova por mutação — 42 aplicadas, 42 reprovaram, 0 sobreviveram

Processo por mutação: contagem de ocorrências travada **ANTES** na fonte **sem comentário** (≠1 →
recusa), aplicação, **impressão de QUAL linha mudou** (e recusa se o alvo só existe em comentário),
execução do gate, restauração de cópia pristina e conferência por `sha256` — idênticos no fim das
duas rodadas: `acoes.js` **`e4eafd0c18d3fee7`** · `OQueFazerAgora.jsx` **`c3d9fe7aa6240da7`** ·
`SaudeDaConta.jsx` **`f9d2a39e6042c05b`** · `PublicacoesNoMercadoLivre.jsx` **`552d951b6439488b`**.

| # | Mutação | Arquivo:linha | Falhas |
|---|---|---|---|
| M01 | `h-[28px]` → `h-7` na opção do segmented | jsx:183 | 3 |
| M02 | tira o `shadow-pub-seg-ativo` da opção ativa | jsx:185 | 4 |
| M03 | `gap-[2px]` → `gap-[3px]` no trilho | jsx:176 | 2 |
| M04 | `bg-pub-raised-0` → `bg-pub-surface` na recomendada | jsx:208 | 4 |
| M05 | tira o badge RECOMENDADO AGORA | jsx:246 | 2 |
| M06 | `recomendada` deixa de vir do servidor | acoes:254 | 7 |
| M07 | `grid-cols-[38px_…]` → `36px` | jsx:216 | 2 |
| M08 | `h-[38px]` → `h-10` no tile do ícone | jsx:220 | 2 |
| M09 | tinta de crítico `0.13` → `0.12` (**as 3 não se uniformizam**) | jsx:69 | 5 |
| M10 | `text-[10.5px]` → `text-[11px]` no badge (**prova o prefixo**) | jsx:239 | 2 |
| M11 | tira o `truncate` da descrição | jsx:253 | 2 |
| M12 | `-ml-[7px]` → `-ml-[6px]` na pilha | jsx:267 | 2 |
| M13 | tira o `border-2` do tile da pilha | jsx:267 | 2 |
| M14 | `h-[32px]` → `h-8` no CTA | jsx:278 | 2 |
| M15 | tira o `stopPropagation` do CTA | jsx:276 | 2 |
| M16 | abre a sub-lista mesmo com `itens: []` | acoes:257 | 4 |
| M17 | troca o nome da rota do adiar | jsx:92 | 2 |
| M18 | `pl-[72px]` → `pl-[70px]` | jsx:308 | 5 |
| M19 | `h-[7px]` → `h-[6px]` no dot do geral | saude:94 | 3 |
| M20 | dá `bg-pub-ok` ao tile do ERP | saude:71 | 4 |
| M21 | o ERP com `declarado: null` passa a ter `problema` | acoes:364 | 2 |
| M22 | `text-[30px]` → `text-3xl` no total | pubs:81 | 4 |
| M23 | `bg-pub-neutral-dot` → `bg-pub-txt-4` (**mesmo hex, nome outro**) | pubs:52 | 2 |
| M24 | três faixas em vez de quatro | acoes:384 | 17 |
| M25 | cinco quadrados no mosaico em vez de seis | pubs:120 | 6 |
| M26 | `imagens: null` passa a mostrar `0` | acoes:403 | 4 |
| M27 | o ERP ganha pílula de status | acoes:348 | 2 |
| M28 | o dot do segmented volta à tinta sólida (**V-1**) | jsx:83 | 2 |
| M29 | o dot do geral da Saúde volta à tinta sólida (**V-2**) | saude:49 | 4 |
| M30 | o rodapé do vazio crava "8h" em vez de ler o ISO (**VG-11**) | jsx:391 | 2 |
| M31 | a contagem do segmented sai das linhas visíveis | jsx:177 | 7 |
| M32 | "Todas" ganha um dot | acoes:55 | 4 |
| M33 | o triângulo de crítico vira contorno | jsx:104 | 2 |
| M34 | o chevron deixa de ser `disabled` sem sub-item | jsx:293 | 4 |
| M35 | o acervo ganha "Abrir →" sem destino | pubs:137 | 2 |
| M36 | "Com erro" volta como quarta faixa | acoes:385 | 5 |
| M37 | a célula amarela do mosaico vai para a 4ª posição | acoes:420 | 3 |
| M38 | a meta do ML inventa *"token renova em 4 h"* | acoes:331 | 5 |
| M39 | o `SUBTITULO` "conserta" o plural do protótipo | acoes:101 | 2 |
| M40 | `pedem_acao` passa a existir SEMPRE (zero inventado) | acoes:451 | 16 |
| M41 | o acervo nulo perde o `title` com o motivo | pubs:139 | 2 |
| M42 | a barra nasce sem faixa medida (trilho decorativo) | pubs:91 | 2 |

**Uma mutação foi RECUSADA e reescrita, não contada duas vezes:** a primeira forma de M25 tinha o
comentário `// hue  30` dentro do alvo, e o verificador de "alvo só em comentário" a barrou
corretamente. Reescrita como `SEMENTES_DO_MOSAICO.slice(0, 5)`, reprovou com 6 falhas.

**Zero sobreviventes.** As duas lacunas que o 179-06 identificou como classe de problema (estado que
só nasce de clique) foram fechadas **antes** da campanha, pelas duas props de estado inicial.

---

## A sonda do CSS construído — 114 classes, 13 iscas

`npm run build` verde (2 min 1 s). O CSS do app é `assets/app-CDaDTSGu.css` (**217.358 bytes**), lido
pela entrada `resources/js/app.jsx` → `css[0]` **do JSON do manifest**, nunca por grep (o hash tem
hífen). `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` presente no manifest
(`assets/VisaoGeral-DcL2WMTI.js`) — **página fora do manifest SOME da tela**.

A sonda compara o **valor** da declaração, não a presença da classe, com o regex em **arquivo**
(nunca `node -e`, nunca heredoc) e o negative-lookahead `(?![\w\\-])` que impede `text-[12px]` de
casar dentro de `text-[12.5px]` e `bg-pub-hover` dentro de `bg-pub-hover-3`.

**Os grupos conferidos:** 6 raios `rounded-pub-*` · 18 `bg-pub-*` · 7 `border-pub-*` + `border-t-pub-accent` ·
11 `text-pub-*` · `fill-pub-danger` · `fill-pub-warn` · `fill-none` · `stroke-pub-page` ·
`stroke-pub-accent` · `shadow-pub-seg-ativo` · **`animate-pub-spin`** (`spin .7s linear infinite`) ·
10 tamanhos de texto (inclusive **`text-[8.5px]`**) · 13 medidas de altura/largura · as 3
`grid-cols-[…]` · 5 `gap-[…]` · 7 paddings · `-ml-[7px]` · `-mx-[8px]` · `border-[1.5px]` ·
`border-2` · 4 `rounded-[Npx]` · `basis-0` · os 3 `tracking-[…]` · `tabular-nums` · `leading-none` ·
`truncate` · `transition-transform` · `duration-200` · **as 6 tintas com alfa em hex de 8 dígitos** ·
4 `hover:*`.

**As 13 iscas saíram AUSENTES:** `text-[8.6px]` · `text-[29px]` · `h-[39px]` · `pl-[70px]` ·
`-ml-[6px]` · `bg-pub-raised-9` · `rounded-pub-naoexiste` · `tracking-[0.07em]` ·
`grid-cols-[36px_…]` · `bg-pub-accent/[0.13]` · `bg-pub-danger/[0.12]` · `stroke-pub-accent-xyz` ·
`animate-pub-spin-lento`.

---

## Como o gate foi escrito — "o que precisaria estar errado para ele falhar?"

**A receita, em todos os elementos:** parte o HTML por elemento, extrai a tag de abertura, compara a
**lista de classes daquela tag por igualdade de CONJUNTO** (classe a mais reprova, a menos reprova,
trocada reprova) e trava **contagens exatas**:

| O quê | Quantas |
|---|---|
| `<section>` em cada bloco | **1** |
| opções do segmented | **4** |
| dots do segmented | **3** (⚠️ "Todas" não tem) |
| badge RECOMENDADO AGORA | **1** (nunca 2) |
| `<svg>` que não é do Lucide no card de ações | **3** (um ícone por ação) |
| chevrons | **1 por ação** |
| linhas da Saúde | **3** (nunca 4) |
| spinner quando `atualizando` | **1** |
| faixas da barra | **4** |
| linhas da legenda | **5** com `pedem_acao`, **4** sem |
| quadrados do mosaico | **6** (5 com tom calculado + 1 amarela, **a quinta**) |
| `.map()` nas 3 fontes | **4 / 1 / 3** |

**A localização nunca usa a medida conferida:** os blocos de ação saem do **texto do título**; as
opções do segmented, dos `<button>` **sem** `aria-expanded` e **sem** `title`; os chevrons, do
`aria-expanded`; as linhas da Saúde, do `<span>` do **nome**; as faixas da barra, do `style` com
`flex-grow`; o mosaico, da vizinhança do texto `Acervo de imagens`.

**Iscas em quatro pontos:** (1) a varredura de classe-no-`.js` tem de **achar** os padrões nos três
`.jsx`; (2) o helper `lerSemComentarios` tem de **continuar mastigando** o glob do config (se parar,
o teste avisa); (3) a varredura de REND-04 tem de **achar** o padrão proibido num trecho falso; (4)
a sonda do CSS tem 13 classes que não existem. E o recorte da pilha de 3 tiles prova que a ação tem
**6** itens — sem isso, um recorte vazio diria "3" por construção.

**Os números que não se uniformizam** são cobrados em pares: as 3 tintas (0.13/0.13/0.12, por classe
**e** pelo byte de alfa no CSS), e as 3 regras de dot/texto (V-1, V-2 e a pílula da linha), com
`doesNotMatch` em cada uma contra a forma da outra.

---

## Casos adversos (REND-01/02) — a tela preta de 07/10

**Render REAL** (esbuild + `react-dom/server`), nunca leitura de fonte, com
`doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)` **e** a confirmação de que o bloco não
desmontou (o `<h2>` de cada um presente) em todas as formas:

- `acoes` / `saude` / `publicacoesMl` / `acervoImagens` **inteiros** como `null`, `undefined`,
  string, número, `0`, `true`, `false`, `[]`, `{}`, `{a:1}` e `NaN`
- `acoes.acoes`, `saude.linhas` e `publicacoesMl.faixas` nulos, string, número, objeto, `[]`,
  `[null]`, `['x']`, `[7]`, `[{}]`, `[[]]`, `[true]`
- ação, linha e faixa de **chave desconhecida** → ignoradas (nunca uma 4ª linha de Saúde, nunca uma
  5ª faixa), **exceto** a ação, que **APARECE** com `CTA_PADRAO` (regressão por omissão é a pior)
- cada campo da ação (`chave`, `severidade`, `titulo`, `descricao`, `numero`, `recomendada`,
  `destino`, `itens`) como **objeto**, um por vez
- cada campo do sub-item (`nome`, `meta`, `motivo`) como objeto, **com a gaveta aberta**
- cada campo da linha da Saúde (`estado`, `conta_nome`, `conta_ml_id`, `problema`, `declarado`,
  `horas`, `defasado`, `nunca_coletado`, `atualizar_url`) como objeto
- `total`, `ativas_pct`, `outras`, `faixa.total`, `faixa.url`, `pedem_acao.total`, `pedem_acao.url`,
  `imagens` e `packs` **todos objeto ao mesmo tempo**
- **`pedem_acao` AUSENTE** (chave que não vem, não nula) e presente com zero medido
- `contaChave` e `urlPublicacoes` como objeto, número, array, `''`, `'   '` e `true` → **nenhum**
  `href="[object Object]"`, `href="7"` nem `href=""`
- **nenhuma prop** nos três componentes

---

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `node --test tests/js/publicador-visao-geral-acoes.test.js` | **214 / 214 pass / 0 fail** |
| 2 | `npm run test:js` | **3389 testes / 3388 pass / 1 fail** — o pré-existente `estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido"). ⚠️ **Baseline medida ANTES deste plano: 3175 / 3174 / 1**, mesmo nome e mesma causa. Não corrigido, de propósito |
| 3 | `publicador-vocabulario-novo` + `publicador-header-render` + `publicador-dashboard-v2` + `publicador-visao-geral-jornada` + `publicador-identidade-nova-render` | **530 / 530 pass / 0 fail** |
| 4 | `npm run build` | verde, 2 min 1 s; `VisaoGeral.jsx` no manifest **pelo JSON** |
| 5 | sonda do CSS construído | **114 classes com o VALOR certo, 13 iscas ausentes** |
| 6 | mutações | **42 aplicadas, 42 reprovaram, 0 sobreviveram, 1 recusada e reescrita**; `sha256` idêntico ao pristino no fim |
| 7 | assertions removidas no range **deste plano** (`8611eece..HEAD`) | **ZERO** (isca: **420** acrescentadas no mesmo range) |
| 8 | território de NINGUÉM (`898d68c2..HEAD`) | **diff vazio** em `app/Support/Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`, `Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa`, `database` |
| 9 | território de OUTRO PLANO (`8611eece..HEAD`) | **diff vazio** em `app`, `routes`, `tailwind.config.js`, `resources/js/Pages`, `identidade/`, `header/`, `PainelVisaoGeral.jsx`, `visaogeral/jornada.js`, `visaogeral/JornadaDosProdutos.jsx`, `composer.json/lock`, `package.json/lock` |
| 10 | **ISCA** do mesmo range nos MEUS caminhos | **NÃO vazio**: 5 arquivos, **3.637 inserções** — o vazio dos gates 8/9 não é por construção |
| 11 | os 19 caminhos dos gates por `test -e` | **todos EXISTEM** no disco |
| 12 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | **vazio** (nenhuma deleção) |
| 13 | `git status --short \| grep tests/js` | **vazio** (nenhuma fixture órfã; os stubs do harness são apagados pelo `after()`) |
| 14 | T-179-SC (pacotes) | **nenhum pacote instalado**; diff vazio nos 4 manifests; gate que confere cada import contra o `package.json` |
| 15 | PHP | **não tocado** — diff vazio em `app/`, `routes/`, `database/` nos dois ranges. A suíte PHP não foi rodada porque **nada de PHP mudou** |

---

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-30 Tampering (tela preta de 07/10) | **mitigado** | `normalizarAcoes`/`linhasDaSaude`/`resumoParaTela`/`acervoParaTela` na fronteira; ação, item, linha e faixa nulos **descartados**; chave desconhecida ignorada (menos a ação, que aparece); render REAL com cada campo objeto/nulo/ausente e `doesNotMatch(/\[object Object\]\|NaN\|undefined\|Invalid Date/)` |
| T-179-31 Tampering (chave arbitrária no POST) | **mitigado** | a `chave` sai da **própria ação renderizada**; nenhum `<input>` e nenhum `event.target.value` no arquivo (travado por teste); a validação dura é da action (lista fechada de 12, 179-05) |
| T-179-32 Spoofing (ERP dito conectado) | **mitigado** | só `Declarado: X` / `Não informado`; **quatro** asserções negativas (`Conectado`, `Desconectado`, `rounded-full`, `bg-pub-ok`); o ERP nunca move `geral`; M20/M21/M27 reprovam |
| T-179-33 Spoofing (número inventado) | **mitigado** | os dois casos opostos testados juntos nos 4 indicadores; `title` com o motivo; M26 e M41 reprovam. ⚠️ **E a sonda prova a opacidade pelo byte de alfa**, que é o que distingue 0.13 de 0.12 |
| T-179-34 DoS (coleta no request) | **mitigado** | `router.post` para a rota que **ENFILEIRA** (`mlb.anuncios.meus.atualizar`, Fase 134, `SyncMlAcervoCompanyJob` + `ShouldBeUnique`); botão desenhado **só** quando `atualizar_url` existe; `atualizar_url` é lido **só** na linha de Sincronização (teste com a URL plantada no ERP e no ML) |
| T-179-35 Spoofing (destino inventado) | **mitigado** | toda `url` vem do servidor; `urlSegura()` recusa objeto, número e vazio; `rota` exige string; sem destino o elemento é `<span>` ou **não nasce**; o acervo sai sem link |
| T-179-36 Tampering (`ReferenceError` no bundle) | **mitigado** | varredura REND-04 nas 3 fontes, com isca, **e ela pegou uma violação real** (`filtro` dentro do `.map()`), que virou `aba.ativa` no view model puro |
| T-179-SC Supply chain | **mitigado** | nenhum pacote; `lucide-react` já era dependência; gate que confere cada import contra o `package.json`; diff vazio nos 4 manifests |

---

## Known Stubs

**Nenhum.** Nenhum número, rótulo ou destino destes três blocos é constante decorativa: os números
saem dos payloads do 179-02/179-03, os rótulos são literais de UI medidos no protótipo (e nenhum
deles afirma um dado que não existe) e todo destino vem do servidor — ausente o destino, o elemento
**não é renderizado** em vez de virar clique mudo.

A única peça sem dado por trás é o **mosaico de 6 quadrados do acervo**, e ela é **ornamento
declarado** (`aria-hidden`, nenhum número, nenhum clique) — exatamente como o protótipo a desenha
(`:269-:271`) e como a ilustração do empty state da Jornada. Os dois números do acervo ao lado dela
são reais.

## Threat Flags

Nenhuma. O plano é 100% cliente: diff vazio em `app/`, `routes/` e `database/` nos dois ranges.

---

## Perguntas em aberto para o usuário

1. **A ação "Ofertas novas no Portal" fica sem botão** (D-5). O botão real existe
   (`BotaoSincronizarPortal`), mas com as classes do layout ANTIGO. Levá-lo para o layout novo é
   trabalho de um plano que possa tocar aquele arquivo — ele serve 3 outras telas. Hoje a ação
   aparece com número, título e descrição, e o botão está na tela de **Produtos**.
2. **A redundância da linha do ERP**: ela mostra o nome declarado no título da linha *e*
   `Declarado: {nome}` na meta (as duas prescrições do plano). Se preferir sem repetição, o título
   da linha pode voltar a ser fixo (`ERP`) e a meta carrega o nome — **uma linha de mudança**.
3. ⚠️ **NADA foi visto renderizado.** Não há navegador neste ambiente e o banco local não tem empresa
   com `ml_token`. Toda a conferência é de teste automatizado, de sonda sobre o CSS construído e de
   mutação. **A validação visual é do usuário, em produção, na conta 459.**

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/visaogeral/acoes.js` — FOUND (661 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/OQueFazerAgora.jsx` — FOUND (458 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/SaudeDaConta.jsx` — FOUND (204 linhas)
- `resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesNoMercadoLivre.jsx` — FOUND (203 linhas)
- `tests/js/publicador-visao-geral-acoes.test.js` — FOUND (2.111 linhas)
- commits `3ff3cbe0`, `6a958c13`, `69d4d412` — FOUND em `git log`
- os quatro arquivos de código idênticos ao commit depois das 42 mutações (`sha256` conferido em cada
  rodada e no fim)
