---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 04
subsystem: ui
tags: [publicador, header-global, sticky, atalhos-de-teclado, fidelidade-de-pixel, render-real, node-test]

# Grafo de dependências
requires:
  - "177-01: camada `pub-*` no `theme.extend` (cores, `font-pub`/`font-pub-mono`, raios `pub-*`, `shadow-pub-cta`) — consumida só por nome"
  - "177-02: `GlyphDaFase` (tamanho `marca`) — a marca REUSA o glyph, não o reimplementa"
  - "177-03: a prop `cabecalho` (`CabecalhoPublicadorService::para()`), que pode chegar NULA"
  - "Fase 172: `textoSeguro()` de `Components/Mlb/Publicador/BarraDaConta.jsx` — importado, não recriado"
  - "Fase 164: rota `mlb.anuncios.publicador.produtos` — destino real do CTA até a Fase 178"
provides:
  - "`Components/Mlb/Publicador/header/PublicadorHeader.jsx`: casca sticky, container 1240/1600/36px, marca, lado direito completo (busca de 250px com ⌘K, Configurações de 34px, separador, CTA amarelo com badge N) e o slot `acoes`"
  - "`Components/Mlb/Publicador/header/atalhosDoHeader.js`: `ignoraAtalho()`, `criarOuvinteDeAtalhos()` e `useAtalhosDoHeader()` (⌘K/Ctrl+K, N e Esc)"
  - "`tests/js/publicador-header-render.test.js`: 72 testes — render real com `cabecalho` completo/nulo/parcial/objeto + FID-01 travado elemento por elemento"
  - "Pontos de montagem prontos para as abas (177-05) e para o seletor de empresa + pill de conexão (177-06)"
affects: [177-05, 177-06, 177-07, 177-08, 177-09, 178]

# Rastreio técnico
tech-stack:
  added: []   # nenhum pacote instalado; `lucide-react` já era dependência
  patterns:
    - "Header que neutraliza o `p-6` do `<main>` por conta própria (`-mx-6 -mt-6`), sem prop de layout e sem tocar no AppLayout"
    - "Atalho global com guarda larga: `canvas`/`[role=\"grid\"]` além de INPUT/TEXTAREA/SELECT, porque a grade do AnunciarMassa recebe foco sem ser input"
    - "Ouvinte de teclado registrado UMA vez, com as funções chegando por `ref` em vez de dependência do efeito"
    - "Botão que ainda não tem a tela final aponta para o destino de hoje (EXMP-03), e sem destino usável fica `disabled` com `title` que explica"
    - "Gate de fidelidade por ELEMENTO (tag extraída + contagem), nunca por `html.includes()` global"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
    - resources/js/Components/Mlb/Publicador/header/atalhosDoHeader.js
    - tests/js/publicador-header-render.test.js
  modified:
    - tests/js/publicador-vocabulario-novo.test.js   # só a constante ARQUIVOS_NOVOS (+4 linhas)

key-decisions:
  - "Onde o plano divergiu do protótipo, o PROTÓTIPO venceu: `title=\"Configurações da empresa\"` (dc.html:99, o plano prescrevia \"da conta\") e `Search strokeWidth={1.6}` (dc.html:95, o bloco de classes do plano omitia e cairia no 2 do Lucide)"
  - "`whitespace-nowrap` nos quatro controles da linha 1 porque os quatro têm `white-space:nowrap` no protótipo (dc.html:25/94/99/103) — o plano não listou"
  - "A chave da conta passa por `textoSeguro(..., null)`, não por `String()`: chave objeto não pode montar destino inventado em `route()`"
  - "Sem conta usável (cabecalho nulo ou chave inválida), busca e CTA ficam `disabled` e Configurações vira `<span aria-disabled>` — nunca `href` para `undefined`, nunca clique mudo"
  - "O gate de fidelidade foi REESCRITO depois que 4 mutações passaram: conferir se a classe existe \"em algum lugar\" do HTML não prova nada quando quatro controles compartilham a mesma classe"

patterns-established:
  - "Prova por mutação antes de aceitar o gate: 39 mutações no arquivo real, 39 reprovadas, restauração conferida por sha256"
  - "Asserção de fidelidade = tag de abertura extraída por regex + lista de classes exigidas + contagem exata das classes compartilhadas"

requirements-completed: [HEAD-01, HEAD-02, HEAD-03, HEAD-08, HEAD-11, HEAD-12, HEAD-13, HEAD-14, HEAD-15, FID-01, FID-03, FID-04, FID-05, REND-01, REND-02, REND-03, REND-04, GATE-03, GATE-05]

# Métricas
duration: ~55min
completed: 2026-10-12
---

# Fase 177 Plano 04: a casca do PublicadorHeader global — Summary

**O header global do Publicador nasceu sticky dentro do `<main>`, com a geometria medida no protótipo (container de 1240px, conteúdo de 1600px com 36px de padding, linha 1 de 60px, nav de 48px), a marca reusando o glyph de Fase 2, o lado direito inteiro na ordem exata — busca de 250px com ⌘K, Configurações de 34px fora das abas, separador e CTA amarelo com o badge "N" — e dois botões que já levam a um destino REAL; sobrevive a `cabecalho` nulo, parcial e com campo objeto, e a fidelidade está travada elemento por elemento por um gate que só foi aceito depois de 39 mutações reprovarem.**

## Performance

- **Duração:** ~55 min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 3 criados, 1 modificado (só uma constante)
- **Commits:** `19da0a31` (Task 1), `86a0fbb4` (Task 2), `2b9bfb80` (Task 3) + o desta SUMMARY
- **Linhas no meu range (`0686ff83..HEAD`):** 966 adições, **0 remoções**, **0 arquivos deletados**

## Baselines — medidas por mim, hoje, antes e depois

| Suíte | Antes (medido agora) | Depois | Leitura |
|---|---|---|---|
| JS inteiro (`npm run test:js`) | **2340 · 2339 pass · 1 fail** | **2424 · 2423 pass · 1 fail** | +84 testes (72 do render do header + 12 do vocabulário sobre os 2 arquivos novos) |
| Os dois gates deste plano | — | **145 · 145 pass · 0 fail** | — |

O número de partida bate exatamente com o que o 177-02 deixou (2340). **O único fail é o mesmo
pré-existente** `tests/js/estrutura-grade-glide.test.js` → *"Características secundárias nasce
recolhido (é o grupo que mais infla)"*, de outro território e de outra fase — **não corrigido de
propósito**.

PHP não foi tocado (`git diff --name-only 0686ff83..HEAD -- app routes tests/Feature` é vazio), então
as baselines do PHPUnit não foram re-executadas: nada que elas leem mudou.

## Tabela FID-01 — protótipo × implementado, linha a linha

Protótipo = `new_layout_publicador/PublicadorHeader.dc.html` (linha citada em cada item) +
README §0. A coluna "Implementado" é o valor **real** que está no arquivo.

| # | Dimensão | Protótipo (`PublicadorHeader.dc.html`) | Implementado |
|---|---|---|---|
| 1 | largura | container `min-width:1240px` (:21) · conteúdo `max-width:1600px` (:22) · busca `250px` (:94) · Configurações `34px` (:99) · separador `1px` (:102) · tecla `min-width:20px` (:97) | `min-w-[1240px]` · `max-w-[1600px]` · `w-[250px]` · `w-[34px]` · `w-px` · `min-w-[20px]` |
| 2 | altura | linha 1 `60px` (:23) · nav `48px` (:109) · marca/busca/config/CTA `34px` (:25/94/99/103) · tecla `20px` (:97) · separador `20px` (:102) | `h-[60px]` · `h-[48px]` · `h-[34px]` (4×, contagem travada) · `h-[20px]` · `h-[20px]` |
| 3 | grid | `display:flex; align-items:center; justify-content:space-between` (:23) | `flex items-center justify-between` |
| 4 | spacing | `margin:0 auto` no container (:22) · `margin:0 4px` no separador (:102) | `mx-auto` · `mx-[4px]` |
| 5 | padding | container `0 36px` (:22) · marca `0 10px 0 4px` (:25) · busca `0 6px 0 12px` (:94) · CTA `0 14px 0 12px` (:103) · tecla N `1px 5px` (:105) | `px-[36px]` · `pl-[4px] pr-[10px]` · `pl-[12px] pr-[6px]` · `pl-[12px] pr-[14px]` · `px-[5px] py-px` |
| 6 | gap | linha 1 `20px` (:23) · esquerda `10px` (:24) · direita `8px` (:93) · marca `9px` (:25) · busca `10px` (:94) · teclas `3px` (:97) · CTA `7px` (:103) · nav `2px` (:109) | `gap-5` · `gap-[10px]` · `gap-2` · `gap-[9px]` · `gap-[10px]` · `gap-[3px]` · `gap-[7px]` · `gap-[2px]` |
| 7 | tipografia — família | UI Geist (:21) · teclas e badge N em Geist Mono (:97/105) | `font-pub` **uma vez**, no `<header>` · `font-pub-mono` 3× (⌘, K, badge N) |
| 8 | tipografia — tamanho | marca `14px` (:30) · busca `13px` (:94) · teclas `10.5px` (:97) · CTA `13px` (:103) · badge N `10.5px` (:105) | `text-[14px]` · `text-[13px]` · `text-[10.5px]` · `text-[13px]` · `text-[10.5px]` |
| 9 | tipografia — peso | marca `600` (:30) · CTA `600` (:103) · busca sem peso declarado = 400 (:94) | `font-semibold` · `font-semibold` · sem classe de peso |
| 10 | tipografia — tracking | marca `-0.01em` (:30) | `tracking-[-0.01em]` |
| 11 | raio | marca/busca/config/CTA `9px` (:25/94/99/103) · tecla ⌘K `5px` (:97) · badge N `4px` (:105) | `rounded-pub-control` (4×, contagem travada) · `rounded-[5px]` · `rounded-pub-badge` |
| 12 | bordas | header `1px #1C1C1C` (:21) · busca `1px #222222` (:94) · tecla `1px #2E2E2E` (:97) · CTA sem borda (:103) | `border-b border-pub-line-subtle-2` (exato) · `border border-pub-line` ⚠️ **divergência declarada** · `border border-pub-line-strong` (exato) · sem borda |
| 13 | sombras | CTA `0 0 0 1px rgba(250,204,21,.25), 0 6px 20px rgba(250,204,21,.12)` (:103) · header sem sombra (:21) | `shadow-pub-cta` (o token é byte a byte esse valor) · nenhuma |
| 14 | cores | fundo `rgba(10,10,10,0.86)` + `saturate(140%) blur(14px)` (:21) · texto `#F4F4F5` (:21) · busca `#111111`/`#8A8A90` (:94) · CTA `#FACC15` com texto `#0A0A0A` (:103) · hover CTA `#EAB308` (:103) · hover marca `#141414` (:25) · hover Configurações `#161616` (:99) · hover busca borda `#3F3F46` e texto `#D4D4D8` (:94) · separador "/" `#333333` (:32) · badge N `rgba(10,10,10,0.14)` (:105) · tecla fundo `#161616`, texto `#A1A1AA` (:97) | `bg-pub-page/[0.86] backdrop-blur-[14px] backdrop-saturate-[1.4]` · `text-pub-txt` · `bg-pub-surface text-pub-txt-3` · `bg-pub-accent text-pub-page` · `hover:bg-pub-accent-hover` · `hover:bg-pub-hover` · `hover:bg-pub-hover-2` · `hover:border-pub-line-off hover:text-pub-fase1` · `stroke-pub-seg-ring` · `bg-pub-page/[0.14]` · `bg-pub-hover-2 text-pub-txt-2` — **todos exatos, por nome de token** |
| 15 | alinhamento | tudo centrado na vertical · texto da busca à esquerda com `flex:1` (:96) · botão `text-align:left` (:94) | `items-center` · `flex-1 text-left` · `text-left` |
| 16 | ícones | busca 14px stroke 1.6 (:95) · Configurações 17px stroke 1.4 (:100) · "+" 14px stroke 2 (:104) · separador "/" 16×22 stroke 1.4 `M11 3L5 19` (:32) | Lucide `Search size={14} strokeWidth={1.6}` · `Settings size={17} strokeWidth={1.4}` · `Plus size={14} strokeWidth={2}` · SVG inline idêntico (exceção a TOK-14, declarada) |
| 17 | badges | tecla "N" em mono 10.5px sobre `rgba(10,10,10,0.14)`, raio 4px, `margin-left:2px` (:105) | `ml-[2px] rounded-pub-badge bg-pub-page/[0.14] px-[5px] py-px font-pub-mono text-[10.5px]` |
| 18 | tabelas | — | n/a |
| 19 | estados | hover em marca/busca/config/CTA · header fixo no scroll (README §0) | as classes `hover:` acima · `sticky top-0 z-50` |
| 20 | densidade / posição dos CTAs | CTA primário no CANTO DIREITO da linha 1, depois do separador · Configurações FORA das abas (:99-106) | ordem travada no teste: busca → Configurações → separador → CTA |

### Divergências declaradas (FID-02 — restrição concreta, por escrito)

As seis que o plano previu:

| Item | Protótipo | Implementado | Restrição concreta |
|---|---|---|---|
| Props do componente | `active`, `mlConnected`, `erpConnected` (script, :168) | `aba` + `cabecalho` + `acoes` + `liberada` | Os três booleanos são mock. O estado real das integrações chega do servidor num objeto só (177-03); `aba` continua prop própria porque é a página que sabe em que aba está. **Informação idêntica, forma diferente.** |
| Borda da busca e do separador vertical | `#222222` (:94, :102) | `border-pub-line` (`#262626`) | `#222222` **não está na tabela de tokens do README**; TOK-15 proíbe hex literal fora da camada. Diferença de 4 unidades de luminância numa borda de 1px. Para o valor exato, o caminho é acrescentar o token na camada — não escrever o hex na tela. |
| Separador "/" | SVG inline (:32) | SVG inline, idêntico | TOK-14 manda preferir o Lucide "quando existe equivalente"; não existe barra inclinada fina de separação no Lucide 1.11. Exceção nominal. |
| Slot `acoes` | não existe | fim da `<nav>` (linha 2), à direita | HEAD-11 exige que o slot de ações por página sobreviva. Na linha 1 mudaria a geometria que o protótipo fixa; a linha 2 tem espaço livre e a ação é de página, como as abas. Sem `acoes`, **nenhum `<div>` é renderizado** (teste conta os `<div>`). |
| `AvisoContaTravada` no header | não existe | `variante="selo"`, componente atual, sem redesenho | HEAD-11 exige a capacidade; o componente não tem contraparte no protótipo e o redesenho dele não está em nenhuma fase desta milestone. Reusar é preservar; redesenhar seria escopo inventado. |
| Modal "Nova publicação" | abre modal de 620px (:132-164) | navega para a seleção de produtos da conta | **Fase 178**, por sequência do roadmap. O CTA **não fica morto** (EXMP-03) e a assinatura de `abrirNovaPublicacao` já é a que a 178 vai reaproveitar. |

E as **quatro que eu encontrei medindo** — nas quatro, **o protótipo venceu o plano**:

| # | O plano prescrevia | O protótipo tem | Implementado |
|---|---|---|---|
| 1 | `title="Configurações da conta"` | `title="Configurações da empresa"` (**:99**) | `"Configurações da empresa"`, em `title` **e** `aria-label` (texto idêntico, IDENT-07) |
| 2 | `<Search size={14} />` no bloco de classes (cairia no stroke 2 default do Lucide) — ainda que a própria tabela FID-01 do plano dissesse "stroke 1.6" | `stroke-width:1.6` (**:95**) | `Search size={14} strokeWidth={1.6}` |
| 3 | nada sobre `white-space` | `white-space:nowrap` na marca, na busca, em Configurações e no CTA (**:25, :94, :99, :103**) | `whitespace-nowrap` nos quatro |
| 4 | `<span className="flex gap-[3px]">` nas teclas; nenhum anti-encolhimento | o grupo das teclas é content-sized ao lado de um `flex:1` (**:96/97**) | **acrescentei** `flex-none` ao grupo de teclas, aos ícones, aos quatro controles da linha 1 e ao grupo da direita — nenhuma diferença visual a 1440px, mas impede que o nome longo da empresa (plano 177-06) esprema o lado direito. Adição defensiva, não adaptação de estilo. |

## O que ficou como PONTO DE MONTAGEM (e não como stub disfarçado)

| Ponto | Onde | Quem liga |
|---|---|---|
| Seletor de empresa (tile + nome + código + dropdown de 360px) e pill de conexão (+ popover de 320px) | comentário na esquerda da linha 1 | **177-06** |
| As 5 abas em pills de 34px, com ícone, contador e dot vermelho | `<div className="flex min-w-0 items-center gap-[2px]" />` dentro da `<nav>` | **177-05** |
| `fecharTudo` é um `noop` documentado | `PublicadorHeader.jsx` | **177-06** (liga os dois menus no Esc) |
| `aba` chega mas ainda não é consumida | assinatura do componente | **177-05** (é a aba ativa das pills) |
| `abrirNovaPublicacao` navega em vez de abrir modal | `PublicadorHeader.jsx` | **Fase 178** |

Nenhum deles mente na tela: **nada aparece vazio ou com "0"**. O que ainda não existe simplesmente
não é desenhado, e o que não tem destino fica `disabled` dizendo por quê.

⚠️ **O header ainda não está em nenhuma página** — a troca das 9 páginas é dos planos 177-07/08.
`git diff --name-only b36037f9..HEAD -- resources/js/Pages` é **vazio**, de propósito. Por isso
**nada do que `BarraDaConta`/`AbasDaConta` entregam desapareceu**: as duas continuam no ar, intactas.

## Onde o `font-pub` foi aplicado

No `<header>`, **uma vez** (`assert.equal((html.match(/font-pub[\s"]/g)).length, 1)`). A família
cobre toda a subárvore do header, inclusive os 7 componentes de identidade, que **não declaram
família** de propósito (decisão do 177-02). As páginas aplicam o `font-pub` no contêiner do corpo
quando trocarem (177-07/08) — sem isso, o corpo continua herdando Inter e a fidelidade de fonte
morre em silêncio.

## O que foi acrescentado ao gate de vocabulário

Em `tests/js/publicador-vocabulario-novo.test.js`, a constante `ARQUIVOS_NOVOS` ganhou:

```
resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
resources/js/Components/Mlb/Publicador/header/atalhosDoHeader.js
```

(com um `DIR_HEADER` ao lado do `DIR` da identidade). **Nada mais mudou nesse arquivo.** A lista é
constante de propósito: se eu tivesse esquecido, o gate passaria **por omissão**, sem acusar nada —
e com ela os dois arquivos novos passaram a ser conferidos contra `text-sm`, `ecf-*`, `font-display`,
`border-white/[0.08]`, hex literal e breakpoint novo (12 testes a mais).

## Prova por mutação — 39 mutações, 39 reprovadas

Primeira rodada: **22 mutações, 18 reprovaram e 4 PASSARAM.** As quatro que passaram expuseram um
gate fraco de verdade, não um detalhe:

| Mutação que passou | Por que o gate não viu |
|---|---|
| CTA com `rounded-lg` em vez de `rounded-pub-control` | `html.includes('rounded-pub-control')` continuava verdadeiro — a marca, a busca e Configurações ainda tinham a classe |
| `<header>` sem `font-pub` | `includes('font-pub')` casava `font-pub-mono` das teclas |
| separador "/" com traço `2` | `match(/stroke-width="1\.4"/)` casava o ícone de Configurações |
| `<nav>` com `gap-1` | o `<div>` de montagem das abas ainda tinha `gap-[2px]` |

O gate foi **reescrito**: cada controle tem a tag de abertura extraída por regex e conferida contra
a sua lista de classes, mais contagens exatas das classes compartilhadas (4× `rounded-pub-control`,
4× `h-[34px]`, 3× `font-pub-mono`, 3× `text-[10.5px]`, 1× `font-pub`) e os ícones conferidos pela
classe que o Lucide emite (`lucide-search`, `lucide-settings`, `lucide-plus`).

Segunda rodada, com o gate novo: as **4 que furaram + 13 novas = 17, todas reprovaram**. Somando a
primeira rodada: **39 mutações, 39 reprovaram, 0 furos.** Os arquivos foram restaurados de cópia
pristina e a restauração conferida por **sha256** (`OK` nos dois), com os gates verdes depois.

Amostra do que reprova hoje: `h-[60px]`→`h-16` · `text-[13px]`→`text-sm` · sumir com `-mx-6 -mt-6`
· `w-[250px]`→`w-64` · `px-[36px]`→`px-9` · `rounded-pub-control`→`rounded-lg` só no CTA · header
sem `font-pub` · `backdrop-blur-[14px]`→`backdrop-blur` · hex `#FACC15` no lugar do token ·
`textoSeguro`→`String` na chave · CTA sem `disabled` · Configurações virando link sempre · badge "N"
vazio · `gap-[2px]`→`gap-1` na nav · separador vertical removido · `text-pub-page`→`text-pub-txt` no
CTA (TOK-06) · marca com glyph à mão em vez do `GlyphDaFase` · `Search` sem o stroke 1.6 ·
`Settings` 17→18 · nav 48→44px · slot de ações removido · `aria-label` diferente do `title` ·
`ignoraAtalho` sem `canvas` · sem `[role=grid]` · sem `SELECT` · N sem a guarda · hook sem
`removeEventListener` · Esc que não avisa o chamador.

## Os atalhos — o que o protótipo faz e o que foi endurecido

O protótipo (`:194-203`) olha só `['INPUT','TEXTAREA']`. Aqui a guarda é maior **por um motivo
concreto**: `AnunciarMassa` é uma das 9 páginas e usa `@glideapps/glide-data-grid`, que desenha num
`<canvas>` e **recebe foco sem ser um `<input>`**. Com a guarda do protótipo, apertar "n" no meio da
edição da grade navegaria para fora da tela e o usuário perderia o que estava digitando (T-177-15).
Por isso `ignoraAtalho()` cobre `INPUT`/`TEXTAREA`/`SELECT`, `isContentEditable` e, via `closest()`,
`canvas, [role="grid"], [contenteditable="true"]` — e devolve `true` para entrada nula, de outro
tipo ou sem `tagName` (na dúvida, não dispara).

⌘K/Ctrl+K vale **mesmo digitando** (é busca global, como no protótipo); "N" só fora de campo; "Esc"
avisa o chamador. O ouvinte é registrado **uma vez** e removido no unmount — as funções chegam por
`ref`, porque `abrirNovaPublicacao` é recriada a cada render e, nas dependências do efeito, o
ouvinte seria removido e registrado de novo em todo render (o header vive em 9 páginas).

**`criarOuvinteDeAtalhos` é um export a mais** do que os dois que o plano previu. Motivo: o
`node --test` deste projeto roda sem jsdom e efeito de React não roda em render estático — sem esse
export, "as três teclas funcionam" só poderia ser afirmado por leitura de fonte. Com ele, as três
teclas são exercitadas de verdade (8 testes), e o registro/remoção do ouvinte fica coberto por
asserção de fonte via `lerSemComentarios`.

## Decisões técnicas

1. **`-mx-6 -mt-6` dentro do próprio header.** O `<main>` tem `p-6`; o header precisa encostar nas
   bordas para cumprir FID-05. A alternativa seria uma prop no `AppLayout`, que D2 proíbe. `sticky
   top-0` continua valendo porque o scrollport é o `<main>` (`overflow-y-auto`), não a janela.
   `git diff b36037f9..HEAD -- resources/js/Layouts/AppLayout.jsx` é **vazio**.
2. **`textoSeguro(chave, null)` em vez de `String(chave ?? '')`.** A chave vira parâmetro de URL em
   `route()`. Com `String()`, uma chave objeto montaria `/publicador/empresas/[object Object]` — um
   destino inventado. Com `null`, o header assume "não há conta" e desabilita com explicação.
3. **Nenhum `.map()` neste arquivo** (REND-04 atendido por construção): não há lista na casca, e as
   flags do protótipo viraram ternários locais.
4. **Nenhum pacote instalado** (T-177-SC). `Search`, `Settings` e `Plus` saem do `lucide-react` que
   já era dependência. Sem componente de tooltip: `title` + `aria-label` com o **mesmo** texto, e
   nenhuma informação mora só no tooltip.

## Verificação executada

| # | Verificação | Resultado |
|---|---|---|
| 1 | `node --test tests/js/publicador-header-render.test.js tests/js/publicador-vocabulario-novo.test.js` | **145 pass · 0 fail** |
| 2 | `npm run test:js` | **2424 · 2423 pass · 1 fail** (o mesmo fail pré-existente da baseline) |
| 3 | `git diff --stat b36037f9..HEAD -- resources/js/Layouts/AppLayout.jsx` | **vazio** (D2/HEAD-14) |
| 4 | `git diff --stat b36037f9..HEAD -- resources/js/Pages/Mlb/Publicador/Editor.jsx resources/js/Components/Publicador/Mesa` | **vazio** (REG-03) |
| 5 | `git diff --name-only b36037f9..HEAD -- resources/js/Pages` | **vazio** (nenhuma página trocada neste plano) |
| 6 | `git diff --stat b36037f9..HEAD -- database app/Support/Publicador` | **vazio** |
| 7 | `git diff --name-only 0686ff83..HEAD -- app routes tests/Feature tailwind.config.js resources/views/app.blade.php resources/js/Components/Mlb/Publicador/identidade` | **vazio** — os meus 3 commits só tocam os 4 arquivos do plano |
| 8 | `git diff --diff-filter=D --name-only 0686ff83..HEAD` | **vazio** — nenhum arquivo deletado |

`npm run build` **não** foi rodado: é do plano 177-09, e nenhuma página consome o header ainda.

⚠️ **Não vi tela renderizada.** O que foi verificado é HTML de `react-dom/server` e classe por
classe contra as medidas do protótipo. **A conferência visual é do usuário, em produção, na conta
459** — inclusive o efeito do `backdrop-filter` com o `sticky` dentro do `<main>`, que só se julga
olhando.

## Para o próximo plano (177-05)

- Monte as pills no `<div className="flex min-w-0 items-center gap-[2px]" />` dentro da `<nav>` —
  o gap de 2px já é o do protótipo e a `<nav>` já tem `h-[48px]` e `justify-between`.
- A aba ativa é `aba`, que já chega na assinatura.
- **Acrescente os arquivos novos ao `ARQUIVOS_NOVOS`** de `publicador-vocabulario-novo.test.js`
  (`DIR_HEADER` já existe) — sem isso o gate passa por omissão.
- O contador de Publicações pode ser `null` (acervo nunca coletado): **não desenhe a pílula** e
  **nunca escreva `0`** — zero diria "não há", quando o certo é "não sabemos".
- A contagem `h-[34px]` do meu gate é **4** (marca, busca, Configurações, CTA). As pills de 34px vão
  mudar esse número: atualize a asserção junto, não a apague.
- `rounded-pub-control` tem contagem **4** pelo mesmo motivo.

## Observação operacional

As fixtures temporárias dos testes de render (`tests/js/.header-inertia-stub-*.mjs`) são apagadas
pelo `after()` do `node --test`, mas **ficam para trás quando o módulo de teste falha antes de
carregar** — foi o que aconteceu durante a prova por mutação. Não há regra no `.gitignore` para elas
(o molde da Fase 172 tem o mesmo comportamento). Apaguei as que sobraram; quem rodar mutação nestes
arquivos deve conferir `git status --short | grep tests/js` antes de commitar.

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx` — FOUND (213 linhas)
- `resources/js/Components/Mlb/Publicador/header/atalhosDoHeader.js` — FOUND (142 linhas)
- `tests/js/publicador-header-render.test.js` — FOUND (607 linhas, 72 testes)
- `tests/js/publicador-vocabulario-novo.test.js` — FOUND (modificado: +4 linhas)
- Commits `19da0a31`, `86a0fbb4`, `2b9bfb80` — FOUND em `git log`
