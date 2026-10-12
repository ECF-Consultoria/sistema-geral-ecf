---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 01
subsystem: ui
tags: [tailwind, design-tokens, geist, publicador, blade, node-test]

# Grafo de dependências
requires: []
provides:
  - "Camada de design `pub-*` no `theme.extend` do Tailwind: 35 cores, 8 raios, 6 sombras, `animate-pub-spin` e `ease-pub-painel`"
  - "`font-pub` (Geist) e `font-pub-mono` (Geist Mono) como famílias nomeadas, sem trocar o `sans` global"
  - "Geist 400/500/600/700 e Geist Mono 400/500 carregadas por `<link>` ao lado de Inter/Manrope"
  - "Gate `tests/js/publicador-tokens-do-publicador.test.js` que importa o config de verdade e reprova adaptação de hex, regressão dos `ecf-*` e remoção do `<link>`"
  - "As 4 baselines de teste medidas antes de qualquer edição (régua de GATE-04 da fase)"
affects: [177-02, 177-04, 177-05, 177-06, 177-07, 177-08, 177-09, 178, 179, 180, 181, 182]

# Rastreio técnico
tech-stack:
  added: []   # nenhum pacote instalado nesta fase — a Geist entra por <link>
  patterns:
    - "Camada de token por módulo: prefixo próprio (`pub-*`) convivendo com a camada legada (`ecf-*`), ambas aditivas no mesmo `theme.extend`"
    - "Gate de configuração por `import()` real do módulo em vez de regex sobre o texto"
    - "Tinta (cor translúcida) por modificador de opacidade do token, não por token separado"

key-files:
  created:
    - tests/js/publicador-tokens-do-publicador.test.js
  modified:
    - tailwind.config.js
    - resources/views/app.blade.php

key-decisions:
  - "A camada `pub-*` é aditiva e contraria o `CLAUDE.md` de propósito (D3, decisão do usuário de 11/10/2026): os `ecf-*` continuam intactos e o gate prova isso por import real"
  - "As tintas do protótipo (`rgba(250,204,21,0.12-0.16)` etc.) não ganham token: saem do modificador de opacidade do próprio token — mesmo valor computado, um nome só"
  - "O gate carrega o `tailwind.config.js` por um hook de resolve do Node em vez de alterar o `import` do config, porque esse arquivo alimenta o build de TODO o sistema e o gate de build pertence ao plano 177-09"
  - "`neutral-dot` repete o hex de `txt-4` (`#71717A`) de propósito: usos diferentes, descritos separados no README"
  - "Nenhum `fontWeight` customizado: 400/500/600/700 já são `font-normal/medium/semibold/bold` de fábrica"

patterns-established:
  - "Gate de vocabulário por objeto: ler configuração com `await import()` e comparar valor com valor; `grep` neste projeto conta comentário e passa pelo motivo errado"
  - "Asserção sobre blade roda na fonte SEM comentário, porque o comentário que documenta um `<link>` costuma citar o próprio termo procurado"
  - "Gate novo é validado por mutação deliberada antes de ser aceito: teste que não reprova nada é pior que teste ausente"

requirements-completed: [TOK-01, TOK-02, TOK-03, TOK-04, TOK-05, TOK-06, TOK-07, TOK-08, TOK-09, TOK-10, TOK-11, TOK-12, TOK-13, TOK-14, TOK-15, FID-03, FID-04, GATE-03, GATE-04, GATE-06]

# Métricas
duration: 38min
completed: 2026-10-12
---

# Fase 177 Plano 01: Camada de tokens do Publicador e as fontes Geist — Summary

**O módulo Publicador passou a ter um design system próprio — 35 cores, 8 raios, 6 sombras, a curva do painel e as fontes Geist declaradas uma única vez com prefixo `pub-` — sem que um único token `ecf-*`, o `sans` ou o `display` do resto do sistema fosse tocado, e com um gate que importa a configuração de verdade para provar as duas metades dessa frase.**

## Performance

- **Duração:** 38 min
- **Tarefas:** 3 de 3
- **Arquivos:** 2 modificados, 1 criado
- **Commits:** `563d591c`, `b3f827ef` (+ o desta SUMMARY)

## As 4 baselines — medidas por mim, em 2026-10-12, ANTES de qualquer edição

Medidas em sequência, com a árvore no commit `490216f9` e **sem nenhuma edição em andamento**.

| # | Suíte | Comando | Medido | Declarado no plano | Bate? |
|---|---|---|---|---|---|
| 1 | JS inteiro | `npm run test:js` | **2210 testes · 27 suites · 2209 pass · 1 fail** | 2210 / 2209 / 1 | sim |
| 2 | PHP do Publicador | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1761 testes · 0 fail · 10813 asserções · 45 deprecations** | 1761 / 0 | sim |
| 3 | `Phase134` | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase134` | **37 testes · 0 fail · 152 asserções · 40 deprecations** | não medida antes | — |
| 4 | `Phase135` | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase135` | **243 testes · 0 fail · 1122 asserções · 92 deprecations** | não medida antes | — |

**O único fail do JS é pré-existente e fora desta fase:** `tests/js/estrutura-grade-glide.test.js` →
*"Características secundárias nasce recolhido (é o grupo que mais infla)"*, que espera
`RECOLHIDOS_INICIAIS = [G_SECUND]` e encontra `RECOLHIDOS_INICIAIS = []` em
`GradeAnuncioGlide.jsx`. **Não corrigido de propósito** — é de outro território e de outra fase.

**Phase134 e Phase135 estavam verdes antes da edição.** Não existe fail pré-existente ali para
confundir com regressão desta fase. Esta fase não tocou em PHP, então nenhuma das duas foi
re-executada depois (nada que elas leem mudou: `tailwind.config.js` e `app.blade.php` não entram em
asserção de PHPUnit, e `tests/Feature` é território do plano 177-03, rodando em paralelo).

### Depois das edições

| Suíte | Antes | Depois | Leitura |
|---|---|---|---|
| JS inteiro | 2210 · 2209 pass · **1 fail** | 2216 · 2215 pass · **1 fail** | +6 testes (o gate novo), o fail segue sendo o `estrutura-grade-glide`, sozinho |

## Tabela FID-01 — protótipo × implementado

Protótipo = `new_layout_publicador/README.md`. **Nenhum valor foi arredondado para o passo do
Tailwind e nenhum foi adaptado a um token que já existia.**

### Fonte (TOK-01)

| Protótipo | Implementado | Classe de consumo |
|---|---|---|
| Geist 400/500/600/700 para UI | `fontFamily.pub = ['Geist', 'Inter', …sans]` | `font-pub` |
| Geist Mono 400/500 para SKU, MLB, códigos, atalhos | `fontFamily['pub-mono'] = ['Geist Mono', …mono]` | `font-pub-mono` |
| "ao lado de Inter/Manrope, sem trocar o `sans` global" | `<link>` novo no blade; `fontFamily.sans[0] === 'Inter'` e `display[0] === 'Manrope'` intactos; `<body class="font-sans antialiased">` intacto | — |
| pesos 400/500/600/700 | **nenhum `fontWeight` customizado** — são `font-normal`/`medium`/`semibold`/`bold` de fábrica | — |

### Cores (TOK-04..TOK-07) — 35 tokens, hex idêntico ao README

| Protótipo (README) | Token | Valor |
|---|---|---|
| bg/page — página e header | `pub-page` | `#0A0A0A` |
| bg/surface — cards, tabela, painel, inputs | `pub-surface` | `#111111` |
| bg/sunken — cabeçalho de tabela, rodapés, sub-listas | `pub-sunken` | `#0D0D0D` |
| bg/raised `#151515`–`#181818` | `pub-raised` / `pub-raised-2` | `#151515` / `#181818` |
| bg/hover `#141414` / `#161616` / `#1A1A1A` | `pub-hover` / `pub-hover-2` / `pub-hover-3` | `#141414` / `#161616` / `#1A1A1A` |
| bg/active-seg `#262626` + `inset 0 0 0 1px #333333` | `pub-seg` / `pub-seg-ring` (+ `shadow-pub-seg-ativo`) | `#262626` / `#333333` |
| border/default | `pub-line` | `#262626` |
| border/strong — inputs, botões secundários | `pub-line-strong` | `#2E2E2E` |
| border/subtle `#1F1F1F` / `#1C1C1C` | `pub-line-subtle` / `pub-line-subtle-2` | `#1F1F1F` / `#1C1C1C` |
| border/control-off — checkbox vazio, célula tracejada | `pub-line-off` | `#3F3F46` |
| text/primary · secondary · tertiary · quaternary · disabled | `pub-txt` · `pub-txt-2` · `pub-txt-3` · `pub-txt-4` · `pub-txt-off` | `#F4F4F5` · `#A1A1AA` · `#8A8A90` · `#71717A` · `#52525B` |
| accent · accent/hover | `pub-accent` · `pub-accent-hover` | `#FACC15` · `#EAB308` |
| accent/row-selected · accent/row-new | `pub-row-sel` · `pub-row-new` | `#221D07` · `#14120A` |
| fase1 `#D4D4D8` / `#E4E4E7` | `pub-fase1` / `pub-fase1-2` | `#D4D4D8` / `#E4E4E7` |
| success dot/texto | `pub-ok` / `pub-ok-txt` | `#22C55E` / `#4ADE80` |
| warning dot/texto | `pub-warn` / `pub-warn-txt` | `#F59E0B` / `#FBBF24` |
| danger dot/texto/texto-em-caixa | `pub-danger` / `pub-danger-txt` / `pub-danger-txt-2` | `#EF4444` / `#F87171` / `#FCA5A5` |
| neutral-status dot/texto — "Em revisão" | `pub-neutral-dot` / `pub-neutral-txt` | `#71717A` / `#A1A1AA` |
| accent/tint `rgba(250,204,21,0.12–0.16)`, `rgba(239,68,68,0.10–0.13)`, `rgba(34,197,94,0.12)`, `rgba(245,158,11,0.12)` | **sem token** — modificador de opacidade do próprio token | `bg-pub-accent/[0.14]` → `rgb(250 204 21 / 0.14)` (conferido no CSS gerado) |

### Raios (TOK-08) e sombras (TOK-09)

| Protótipo | Token | Valor |
|---|---|---|
| card 16px | `rounded-pub-card` | `16px` |
| tabela de Publicações 14px | `rounded-pub-table` | `14px` |
| painéis 12px · sub-cards 10px | `rounded-pub-panel` · `rounded-pub-sub` | `12px` · `10px` |
| botões/inputs 9px e 7px | `rounded-pub-control` · `rounded-pub-control-sm` | `9px` · `7px` |
| chips 6px · badges/checkbox 4px | `rounded-pub-chip` · `rounded-pub-badge` | `6px` · `4px` |
| popover | `shadow-pub-popover` | `0 24px 60px rgba(0,0,0,0.7)` |
| painel lateral | `shadow-pub-painel` | `-24px 0 64px rgba(0,0,0,0.6)` |
| modal | `shadow-pub-modal` | `0 30px 80px rgba(0,0,0,0.8)` |
| CTA primário do header | `shadow-pub-cta` | `0 0 0 1px rgba(250,204,21,0.25), 0 6px 20px rgba(250,204,21,0.12)` |
| aba ativa · segmented ativo | `shadow-pub-aba-ativa` · `shadow-pub-seg-ativo` | `inset 0 0 0 1px #262626` · `inset 0 0 0 1px #333333` |

### Movimento (TOK-12)

| Protótipo | Token | Valor |
|---|---|---|
| spinner `spin .7s linear infinite` | `animate-pub-spin` | `spin .7s linear infinite` (keyframe `spin` padrão do Tailwind, não se declarou outro) |
| painel lateral `.28s cubic-bezier(.2,.8,.2,1)` | `ease-pub-painel` | `cubic-bezier(.2,.8,.2,1)` |

### O que o plano deliberadamente NÃO tokenizou

- **Escala de espaçamento (TOK-10)** e **alturas de controle (TOK-11)**: o plano não os prescreve na
  camada, e FID-03 manda escrever a medida exata na tela (`h-[54px]`, `gap-[18px]`) em vez de
  arredondar. Os passos 10/14/18/22/28/36 px saem por valor arbitrário nas telas.
- **`fontWeight`**: os quatro pesos do README já existem de fábrica. O gate reprova se alguém
  inventar um passo.
- **Tiles `oklch` (TOK-13)** e **ícones (TOK-14)**: calculados por produto / vindos do `lucide-react`,
  não são valor fixo de tema.

## Realizações

1. **`tailwind.config.js` — 107 linhas, todas adição.** A chave `pub` em `colors`, duas chaves em
   `fontFamily`, oito raios em `borderRadius`, a chave nova `boxShadow` com seis sombras,
   `animation['pub-spin']` e a chave nova `transitionTimingFunction`. Nada removido, renomeado ou
   reordenado: os `ecf-*`, as cores shadcn `hsl(var(--…))`, os keyframes e as animações existentes
   ficaram byte a byte.
2. **`resources/views/app.blade.php` — 9 linhas de adição.** Um `<link>` novo logo abaixo do de
   Inter/Manrope, com `display=swap`, no mesmo host que a página já usava.
3. **`tests/js/publicador-tokens-do-publicador.test.js` — 6 testes, 274 linhas.** Importa o config
   de verdade e confere: os 35 hex da camada nova; os 8 pares de `colors.ecf` **mais o conjunto
   exato de chaves** (a camada antiga não pode ganhar nem perder token); `sans`/`display` globais;
   `font-pub`/`font-pub-mono`; os 8 raios, as 6 sombras, `pub-spin` e a curva do painel; os dois
   `<link>` de fonte com `display=swap` e `rel="stylesheet"`; o `<body class="font-sans antialiased">`;
   e a ausência de `fontWeight` customizado.

## O gate foi provado por mutação — 8 de 8 reprovaram

Teste que para de provar algo é pior que teste ausente, então o gate foi atacado antes de ser
aceito. Cada mutação foi escrita no arquivo real, o gate rodado, o arquivo restaurado, e a
restauração conferida byte a byte por `sha256` no fim (confirmada idêntica; `git diff` dos dois
arquivos commitados voltou vazio).

| Mutação | Resultado |
|---|---|
| M1 `pub-accent` "adaptado" para o amarelo `ecf` (`#FACC15` → `#ffe600`) | reprovou |
| M2 um hex da camada `ecf-*` alterado | reprovou |
| M3 `sans` global trocado para Geist | reprovou |
| M4 `pub-table` arredondado para o passo do Tailwind (`14px` → `0.75rem`) | reprovou |
| M5 `pub-spin` removido | reprovou |
| **M6 `<link>` da Geist APAGADO mantendo o comentário que cita "Geist"** | **reprovou** |
| M7 `display=swap` tirado do `<link>` da Geist | reprovou |
| M8 `<body>` perdeu o `font-sans` (Geist viraria global) | reprovou |

**A M6 é a que justifica a arquitetura do gate.** O comentário em pt-BR que documenta o `<link>`
contém a palavra "Geist" — um `grep`/`includes` no arquivo cru teria passado com o `<link>` apagado,
exatamente o gate falso que o `_fonte.js` existe para impedir. Por isso as asserções do blade rodam
na fonte **sem comentário**, e o próprio gate confere que a limpeza aconteceu (`BLADE_CRU` contém
`{{--`, `BLADE` não).

## Verificação

| Item | Resultado |
|---|---|
| `node --test tests/js/publicador-tokens-do-publicador.test.js` | **6 pass · 0 fail** |
| `npm run test:js` | **2216 · 2215 pass · 1 fail** — mesmo fail pré-existente da baseline, sozinho |
| `git diff --numstat b36037f9..HEAD` nos 3 arquivos | `9/0` · `107/0` · `274/0` — **390 adições, 0 remoções** |
| `git diff b36037f9..HEAD \| grep '^-[^-]'` nos 2 arquivos editados | **nenhuma linha removida** |
| Escopo: `app/`, `routes/`, `tests/Feature/` no meu range | **vazio** — nada do território do 177-03 |
| `npm run build` | **não executado** — o plano não o exige e o plano 177-09 é o único a mexer em `public/build` |

### Prova extra: os tokens geram utilitário de verdade

Token que não gera classe é token que não existe para as telas. O Tailwind CLI foi rodado com o
config real e saída no scratchpad (**sem tocar em `public/build`**), com o `--content` apontando para
uma sonda que usa as classes novas. As 13 saíram, com os valores certos:

- `.bg-pub-page` → `rgb(10 10 10 …)` = `#0A0A0A`
- `.text-pub-txt` → `rgb(244 244 245 …)` = `#F4F4F5`
- `.font-pub` → `Geist, Inter, ui-sans-serif, …`
- `.bg-pub-accent\/\[0\.14\]` → `rgb(250 204 21 / 0.14)` — a tinta sai do modificador, como planejado
- `.rounded-pub-table` → `14px` · `.ease-pub-painel` → `cubic-bezier(.2,.8,.2,1)` · `.animate-pub-spin` → `spin .7s`
- e, do outro lado da fronteira, **`.bg-ecf-bg` → `rgb(5 5 7 …)` = `#050507` e `.font-sans` → `Inter, …`**, inalterados

## Desvios do plano

### 1. [Rule 3 — Bloqueio] O `tailwind.config.js` não é importável por Node puro

- **Onde:** Task 2, no `<verify>` do próprio plano, e na premissa da Task 3.
- **Problema:** o comando de verificação do plano (`node --input-type=module -e "… await import('./tailwind.config.js') …"`)
  falha com `ERR_MODULE_NOT_FOUND: Cannot find module '…/node_modules/tailwindcss/defaultTheme'`.
  O `tailwind.config.js` faz `import defaultTheme from 'tailwindcss/defaultTheme'`; o `tailwindcss`
  3.4.19 **não tem mapa `exports`**, então o Tailwind resolve esse especificador pelo resolvedor CJS
  (que aceita extensão implícita) mas o ESM do Node exige o `.js`. **É pré-existente** — nenhum teste
  do repositório importava o config até agora, então nunca tinha aparecido.
- **Correção:** o gate ensina o resolvedor do Node a achar o arquivo real, via
  `module.registerHooks()` + `createRequire().resolve('tailwindcss/defaultTheme')`. O que se importa
  continua sendo **o config de verdade**, sem cópia nem transformação do código dele.
- **O que foi deliberadamente NÃO feito:** acrescentar `.js` ao `import` do config. Seria uma linha,
  mas é uma mudança **não aditiva** num arquivo que alimenta o build de todo o sistema, e este plano
  **não roda `npm run build`** (o gate de build é do 177-09) — eu estaria mexendo no build sem o gate
  que o cobre, e rodar o build aqui escreveria em `public/build`, território de outro plano e de
  outra sessão. Ficou registrado para quem for mexer: **hoje o `tailwind.config.js` só é importável
  por Node com o hook de resolve**, e qualquer gate futuro sobre ele precisa do mesmo preâmbulo.
- **Efeito colateral conhecido:** o gate exige **Node ≥ 22.15** (`module.registerHooks`). A máquina
  de dev está em v24.14.0 e o projeto não tem `.nvmrc`. Se alguém rodar em Node mais antigo, o gate
  **falha alto**, com mensagem explícita, em vez de passar vazio.

### 2. Commits por tarefa em vez do commit único do `<output>`

O bloco `<output>` do plano descreve um commit único com os três arquivos. Commitei por tarefa
(`563d591c` para a camada + a fonte, `b3f827ef` para o gate, e este para a SUMMARY), conforme o
protocolo de commit atômico por tarefa. Mensagem do plano preservada no commit da Task 2. Pathspec
no `add` **e** no `commit`, mensagem por `-F`, nenhum `git add -A`/`stash`/`reset`/`checkout`/`--amend` —
a árvore é compartilhada com outro dev ativo e com o plano 177-03 em paralelo.

## Pendências e observações para as fases seguintes

### 1. `#18160C` (linha marcada) não tem token — e vai faltar na tela de Publicações

A tabela "Cores (dark mode)" do README, que é a fonte de TOK-04..TOK-07 e da lista prescritiva deste
plano, **não inclui `#18160C`**. Ele aparece só na seção da tela Publicações, em "Fundos de linha:
selecionada `#221D07` · foco do teclado `#1C1C1C` · **marcada `#18160C`** · nova `#14120A` · padrão
`#111111`". Os outros quatro já têm token (`pub-row-sel`, `pub-line-subtle-2`, `pub-row-new`,
`pub-surface`); o da linha **marcada** (checkbox) não.

**Não inventei o token**, porque a instrução é seguir a tabela prescritiva e não improvisar. Mas
TOK-15 proíbe hex literal nas telas, então **o plano que redesenhar a tabela de Publicações precisa
acrescentar `'row-checked': '#18160C'` à camada** (um token novo é adição e o gate atual não impede).
Fica anotado aqui para não ser descoberto como "hex solto" na revisão.

### 2. Sem tokens de espaçamento e de altura

TOK-10 e TOK-11 não entraram na camada (o plano não os prescreve). As telas vão escrever
`h-[54px]`, `h-[34px]`, `gap-[18px]`, `px-[36px]` etc. como valor arbitrário — que é o que FID-03
manda. Se as fases seguintes acharem isso repetitivo, a decisão de tokenizar espaçamento é **do
usuário**, não do executor.

### 3. Nada visual foi conferido

**Não vi nenhuma tela renderizada.** Esta fase não produziu interface: ela declara tokens, carrega
fonte e cria um gate. A prova que tenho é a do CSS gerado pelo Tailwind CLI (valores corretos) e a do
gate (valores corretos no config). **A conferência visual é do usuário, em produção, na conta 459**,
e só faz sentido a partir do plano que usar as classes novas numa página.

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano. O `<link>` da Geist aponta
para `fonts.googleapis.com`, o **mesmo host** que `app.blade.php` já usava para Inter/Manrope desde
sempre — não acrescenta origem de terceiro (T-177-01, disposição `accept`). T-177-02 (`mitigate`)
está coberto: o gate prova por import real que `colors.ecf`, `fontFamily.sans` e `fontFamily.display`
ficaram idênticos. T-177-SC está vazio por construção: **nenhum pacote foi instalado**,
`package.json` e `composer.json` não foram tocados.

## Known Stubs

Nenhum. Esta fase não renderiza nada e não tem caminho de dados.

## Self-Check: PASSED

- Os 3 arquivos de código e esta SUMMARY existem em disco.
- Os commits `563d591c` e `b3f827ef` existem no `git log`.
- `package.json`, `package-lock.json`, `composer.json` e `composer.lock` **intactos** no range
  `b36037f9..HEAD` — nenhum pacote instalado, como o `<threat_model>` exige.
- `git diff --numstat b36037f9..HEAD` nos 3 arquivos: `9/0`, `107/0`, `274/0` — 390 adições, 0 remoções.
