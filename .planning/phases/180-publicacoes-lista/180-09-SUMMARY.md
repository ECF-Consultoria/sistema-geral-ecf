---
phase: 180-publicacoes-lista
plan: 09
subsystem: ui
tags: [publicador, publicacoes, linha-de-grupo, rodape, estados-vazios, fidelidade, render-adverso, mutacao, sonda-css, classe-morta]

requires:
  - phase: 180-publicacoes-lista
    plan: "01"
    provides: "os tokens `pub-raised` (#151515), `pub-hover-3` (#1A1A1A), `pub-sunken`, `pub-surface`, `pub-line`, `pub-line-strong`, `pub-line-subtle`, `pub-txt-3`, `pub-neutral-txt`, `pub-accent`, `pub-warn-txt`, os raios `pub-badge`/`pub-chip`/`pub-control-sm` e os tamanhos 10.5/11.5/12/12.5/13/15 em `TAMANHOS_PERMITIDOS`"
  - phase: 180-publicacoes-lista
    plan: "04"
    provides: "o shape de `grupos[i]` (as 11 chaves, com `cobertura` nas MESMAS 4 chaves de `MapaDeCobertura.jsx:24`-`:28`, `total_no_produto = max(cobertura.total, total_visivel)`, `sem_fase2` derivado das células de F2, `ver_produto_url` real e `criar_combo_url` com a limitação medida) e o `rodape` `{visiveis, total, produtos}` com `total` do paginator"
  - phase: 180-publicacoes-lista
    plan: "08"
    provides: "`CLASSE_DO_CARD` e `GRID_DA_TABELA` (que esta linha NÃO usa, por medição), `textoDaTela`/`caminhoSeguro` de `CelulasDaLinha.jsx`, o molde do harness (bundles antes do 1º `test()`, `blocoEm` com profundidade, `tagsInternas` que pulava o pai, sonda de CSS com parênteses escapados) e a contagem de `publicacoes/` em 11"
  - phase: 177-identidade-visual-do-publicador
    provides: "`MapaDeCobertura` (as 4 células F1/F2 × C/P, com `title` = `aria-label`) e `TileDoProduto` (a escada de raio por lado + a prop aditiva `letra` da Fase 178)"
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "os três vazios honestos de hoje (`MeusAnuncios.jsx:163`-`:191` e `:423`-`:434`) e o `decodificarRotuloPaginacao()` de `:267`, que existe para evitar `dangerouslySetInnerHTML` (T-134-20)"

provides:
  - "`LinhaDeGrupoDeProduto.jsx` — a linha de grupo de 58px em `flex` (NÃO herda o grid de 9 colunas), com chevron que gira, tile de 38px/raio 9/letra 12, tag PRODUTO, SKU em mono, 'Estoque N'/'Estoque —', o mapa de cobertura REUSADO, a contagem 'N publicações'/'x de N publicações' e os dois links só com destino"
  - "`RodapeDaTabela.jsx` — 44px, a frase 'N de M publicações · P produtos' com plural nos dois e cada pedaço omitido quando não foi medido, a legenda das 4 teclas e o nó da paginação de 50 renderizado como veio"
  - "`VaziosDaLista.jsx` — os 3 vazios honestos de hoje preservados byte a byte (com 2 correções de TEXTO medidas) + o título do protótipo para o recorte vazio sem busca, o aviso de SKU não coletado e o botão 'Limpar busca e filtros'"
  - "`noRenderizavel()` — a guarda de prop de NÓ que impede o `Objects are not valid as a React child` (a tela preta pela porta da prop de nó)"
  - "o gate `publicador-publicacoes-grupos.test.js` (81 testes) e `publicacoes/` de 11 → 14 no gate de vocabulário"
  - "a MEDIÇÃO de que `duration-[Nms]` NÃO COMPILA neste projeto (classe morta), com a causa reproduzida e os 6 usos mortos do repositório registrados em `deferred-items.md`"
affects: [180-10, 180-11, 180-12, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "duração fora da escala nomeada vai por `style={{ transitionDuration }}`, nunca por `duration-[Nms]` — o `tailwindcss-animate` torna a classe ambígua e o Tailwind não a gera"
    - "prop de NÓ (`ReactNode`) passa por guarda `isValidElement` antes de ser renderizada"
    - "contagem que entra em FRASE exige `typeof === 'number' && Number.isFinite` (mais estrito que `numeroMedido`, que aceita string numérica)"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/LinhaDeGrupoDeProduto.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/RodapeDaTabela.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/VaziosDaLista.jsx
    - tests/js/publicador-publicacoes-grupos.test.js
    - .planning/phases/180-publicacoes-lista/deferred-items.md
  modified:
    - tests/js/publicador-vocabulario-novo.test.js
    - .planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md

key-decisions:
  - "`MapaDeCobertura` entra como está (4 células seguidas, sem os rótulos F1/F2 de `:182`/`:183`): DESVIO DECLARADO, porque `identidade/` é território proibido nesta fase — e o plano admite explicitamente o desvio declarado como saída"
  - "`estoque: null` → 'Estoque —' (não a omissão do pedaço que o plano pedia): é o contrato que o próprio controller escreveu (`MlbAnuncioController.php:1446`-`:1450`) e a convenção de '—' = não medido do projeto"
  - "`sem_resultado` SEM termo legível cai no título do protótipo ('Nenhuma publicação encontrada'), não em 'Esta empresa não tem anúncios ativos' — cair no literal de empresa seria MENTIR sobre a causa do vazio"
  - "o link 'Criar combo' NASCE (o destino é real e já está em produção na Visão geral), e a limitação de não pré-selecionar o produto vai no `title`/`aria-label`, que é onde cabe sem quebrar a fidelidade do texto visível"
  - "o `.18s` do chevron vai por `style`: `duration-[180ms]` não compila neste projeto (medido no CSS construído e causa reproduzida)"
  - "a frase do rodapé leva o singular 'publicação' que o `footerLabel` do protótipo (`:644`) NÃO tem mas o `resultSummary` da mesma tela (`:626`) TEM — protótipo incoerente consigo mesmo, vale a intenção"

patterns-established:
  - "Gate de classe morta: `assert.equal(regraDe('duration-[180ms]'), null)` COM `duration-200` como controle — sem o controle o gate não distingue 'o arbitrário não compila' de 'a sonda está cega'"
  - "`temClasse(tag, classe)` em vez de `classesDa()` dentro de `filter`: `classesDa` ASSERTA o atributo e lança em `<div>` sem `class=`, reprovando o código certo"

requirements-completed: [LIST-09, LIST-16, LIST-17, LIST-23, EXMP-01]

duration: ~110min
completed: 2026-10-13
---

# Fase 180 Plano 09: linha de grupo, rodapé e vazios — Summary

**A visão "Por produto" ganhou a linha de grupo de 58px em `flex` (que NÃO herda o grid das 9
colunas), o rodapé de 44px com a paginação de 50 preservada e os quatro estados vazios — e a rodada
mediu uma CLASSE MORTA no projeto inteiro: `duration-[Nms]` não compila por causa do
`tailwindcss-animate`.**

## O que foi feito

| Task | O que | Commit | Linhas |
|---|---|---|---|
| 1 | `LinhaDeGrupoDeProduto.jsx` | `8312faa1` | +351 / **-0** |
| 2 | `RodapeDaTabela.jsx` + `VaziosDaLista.jsx` | `e0136fa1` | +343 / **-0** |
| — | correção das referências de linha medidas + o `footerLabel` de `:644` | `b2bf9a04` | +18 / -8 |
| 3 | o gate (81 testes), `publicacoes/` 11 → 14, as 8 mutações, `deferred-items.md` | `2542b954` | +1635 / -9 |
| — | learnings: `duration-[Nms]` e o 6º caminho fantasma | `43ef989c` | +25 / **-0** |

Total: **+2372 / -17**, em **7 arquivos**, todos `.jsx`/`.js`/`.md`.
**Zero PHP, zero migration, zero pacote, zero deleção** — provado **commit por commit** (§11).

---

## (1) ⚠️⚠️ A DECISÃO DO `MapaDeCobertura`: DESVIO DECLARADO, não prop aditiva

O plano ofereceu dois caminhos: **(a)** acrescentar a prop opcional `comRotuloDeFase` em
`identidade/MapaDeCobertura.jsx` (acréscimo aditivo), ou **(b)** registrar o desvio.

**A medida do protótipo** (`new_layout_publicador/Publicacoes v2.dc.html`, conferida por `awk` com
índices absolutos, não por leitura):

| onde | linha | o que o protótipo faz |
|---|---|---|
| bloco da cobertura | **`:180`** | `display:flex;align-items:center;gap:14px;margin-left:22px;padding-left:22px;border-left:1px solid #262626;height:30px` |
| o loop dos PARES | `:181` | `<sc-for list="{{ g.cov }}" as="c" hint-placeholder-count="2">` — **DOIS pares**, não quatro células |
| o par | **`:182`** | `display:flex;align-items:center;gap:5px` |
| o rótulo da fase | **`:183`** | `<span style="font-size:11.5px;font-weight:600;color:#A1A1AA;margin-right:2px">{{ c.phase }}</span>` |
| a célula | `:185` | `20×20`, raio 5, `10.5px/600` |

**O que `MapaDeCobertura` faz hoje:** as **quatro** células seguidas num `flex gap-[3px]`, sem
rótulo de fase (`MapaDeCobertura.jsx:23`-`:28` e `:40`-`:44`).

➡️ **Escolhi (b), o desvio declarado.** Motivos, na ordem:

1. **O briefing desta rodada lista `identidade/` como território PROIBIDO, duas vezes** — e a
   justificativa escrita é "as duas divergências medidas ali ficam para outra fase". O plano
   autoriza o toque; o briefing, que é a instrução mais recente, não. Em conflito, escolhi o lado
   que **não arrisca** os gates da Fase 177.
2. **O próprio plano nomeia o desvio declarado como saída aceitável:** *"mapa com 4 células seguidas
   é desvio declarado, não é erro de fidelidade escondido. Registre a escolha na SUMMARY."*
3. **A dívida já está registrada** no learnings §10 ("o conserto exige fase que possa tocar
   `identidade/`") — preservá-la por um plano é consistente, esconder não seria.

⚠️ **A receita para quem puder consertar** (10 minutos de trabalho): prop `comRotuloDeFase = false`
em `MapaDeCobertura`; quando `true`, quebrar `CELULAS` em dois pares por `fase`, cada par num
`<span className="flex items-center gap-[5px]">` com
`<span className="mr-[2px] text-[11.5px] font-semibold text-pub-neutral-txt">F1</span>`, e o
contêiner virando `gap-[14px]`. O default **tem** de sair byte a byte igual ao de hoje, com caso de
teste próprio — `publicador-identidade-nova-render.test.js` cobra o HTML atual.

⚠️ **O que NÃO foi perdido:** a informação da fase continua em quatro camadas (letra C/P, posição
F1-à-esquerda, borda sólida × tracejada e o `aria-label` `"Fase 2 · Clássico — não criada"`), que é
o que o componente documenta como projeto. **O que falta é o rótulo visual "F1"/"F2".**

---

## (2) A tabela FID-01, conferida ITEM A ITEM

Medido com `grep -n`/`awk` em `Publicacoes v2.dc.html` e confirmado pela **SEMÂNTICA**, procurando
explicitamente **o que a tabela NÃO trouxe**.

> ⚠️ **PLACAR: 31 medidas conferidas, 9 divergências — 8 de OMISSÃO e 1 de referência de linha,
> todas a favor do protótipo. Nenhum VALOR que a tabela trouxe estava errado.** É o quinto plano
> seguido com esse padrão (180-06: 5 omissões em 27; 180-07: 7 em 34; 180-08: 14 em 40).
>
> ⚠️ **Mais 3 divergências de PRESCRIÇÃO** (do plano sobre o repositório, não do protótipo): o
> `estoque` nulo (§4), o fallback do `sem_resultado` (§5) e `duration-[180ms]` (§3).

### 2a. A linha de grupo (`:173`-`:196`)

| onde (semântica) | linha MEDIDA | a tabela do plano | bate? |
|---|---|---|---|
| a linha | `:173` — `flex;align-items:center;gap:14px;height:58px;padding:0 16px 0 14px;border-top:{{g.borderTop}};background:#151515;cursor:pointer`, `style-hover #1A1A1A` | mediu tudo, mas a **coluna de classe OMITIU `cursor-pointer`** | ⚠️ **OMISSÃO 1** — `cursor-pointer` aplicado (é a **10ª** omissão dele na fase) |
| chevron | `:174` — `14×14 viewBox 0 0 16 16`, `flex:none;fill:none;stroke:#8A8A90;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;transform:…;transition:transform .18s`, path `M6 4l4 4-4 4` | a coluna de classe só trouxe `transition-transform duration-[180ms]` + `rotate-*` | ⚠️ **OMISSÃO 2** — `fill:none`, `stroke-linecap`, `stroke-linejoin`, `flex-none` e o `path` reproduzidos; e o `duration-[180ms]` **não compila** (§3) |
| tile | `:175` — `38×38`, `border-radius:9px`, **`font-size:12px`**, `font-weight:600`, `flex:none`, `margin-left:-4px` | mandou conferir o raio, **não falou do corpo de 12px** | ⚠️ **OMISSÃO 3** — a escada de `TileDoProduto` devolve `text-[11.5px]` para lado 38; resolvido com a prop aditiva `letra="text-[12px]"` (Fase 178), **sem tocar na escada** |
| coluna nome+sku | `:176` — `flex;flex-direction:column;gap:2px;min-width:0` | igual | ✓ |
| nome + tag | `:177` — nome `14px/600 letter-spacing:-0.01em gap:8px`; tag `10.5px/600 letter-spacing:0.06em #8A8A90 padding:2px 6px border:1px solid #262626 radius:4px` | igual | ✓ (e **nada** de `truncate`/`flex-none`, que o protótipo não declara — ver 2d) |
| "SKU · Estoque N" | `:178` — `12px #8A8A90`, SKU em `'Geist Mono'` `11.5px`, separador **U+00B7** | mediu, mas não disse o code point | ⚠️ **OMISSÃO 4** — `·` é **MIDDLE DOT (U+00B7)**, medido por code point; o gate asserta o ponto e proíbe o BULLET (U+2022) |
| bloco da cobertura | **`:180`** (o plano/eu dissemos `:179`) — `gap:14px;margin-left:22px;padding-left:22px;border-left:1px solid #262626;height:30px` | valores certos, **linha deslocada em 1** | ⚠️ **DIVERGÊNCIA 5** (de referência) — corrigida no commit `b2bf9a04` |
| par de fase | `:182`/`:183` (eu dissera `:181`/`:182`) | — | ⚠️ idem, corrigida |
| "Criar combo" | **`:190`**/`:191` (eu dissera `:187`/`:188`) — `gap:5px;font-size:12px;font-weight:500;color:#FACC15;padding:4px 9px;border-radius:6px;background:rgba(250,204,21,0.12)`; seta `11×11 viewBox 16` com **`stroke:currentColor`** | a tabela não trouxe o `stroke:currentColor` | ⚠️ **OMISSÃO 6** — sem ele a seta sairia preta sobre fundo escuro |
| contagem | **`:194`** (eu dissera `:190`) — `margin-left:auto;font-size:12.5px;color:#A1A1AA` | igual | ✓ (+ a regra do `ml-auto` quando a contagem não nasce, ver 2d) |
| "Ver produto" | **`:195`**/`:196` — `gap:5px;font-size:12.5px;font-weight:500;padding:6px 10px;border:1px solid #262626;border-radius:7px;background:#111111` | igual | ✓ |

### 2b. O rodapé (`:293`-`:300`)

| onde | linha MEDIDA | bate? |
|---|---|---|
| o rodapé | `:293` — `flex;align-items:center;justify-content:space-between;height:44px;padding:0 16px;border-top:1px solid #262626;background:#0D0D0D;font-size:12px;color:#8A8A90` | ✓ (confirmado por `grep -n` do literal, como o 180-08 pediu) |
| a frase | `:294` — `<span>{{ footerLabel }}</span>` | ✓ |
| a legenda | `:295` — `flex;align-items:center;gap:14px`; item `gap:5px`; tecla `'Geist Mono';padding:1px 6px;border:1px solid #2E2E2E;border-radius:4px;background:#111111;color:#A1A1AA` | ✓ — e **sem `font-size` própria**: a tecla herda os 12px do rodapé (⚠️ **OMISSÃO 7**: a tabela não disse que não há tamanho ali, e inventar um `text-[11.5px]` seria adaptar) |
| as 4 teclas | `:296`-`:299` — `/` buscar · `↑↓` navegar · `Enter` detalhes · `Esc` fechar | ✓ — `↑↓` são **U+2191 + U+2193**, assertados por code point |
| **o `footerLabel`** | **`:644`** — `list.length + ' de ' + all.length + ' publicações · ' + prodCount + (prodCount === 1 ? ' produto' : ' produtos')` | ⚠️ **DIVERGÊNCIA 8 — o protótipo é INCOERENTE CONSIGO MESMO**: aqui "publicações" nunca tem singular, mas o `resultSummary` da MESMA tela (`:626`) escreve `(list.length === 1 ? ' publicação' : ' publicações')`. ➡️ Implementei a **INTENÇÃO** (plural certo nos dois): "1 de 1 publicações" seria erro de português na tela |

### 2c. O vazio (`:286`-`:289`)

| onde | linha MEDIDA | bate? |
|---|---|---|
| a caixa | `:286` — `flex;flex-direction:column;align-items:center;gap:8px;padding:64px 24px;border-top:1px solid #1F1F1F;text-align:center` | ✓ |
| o título | `:287` — `15px/600`, literal "Nenhuma publicação encontrada" | ✓ (usado no recorte vazio SEM busca, §5) |
| a explicação | `:288` — `13px #A1A1AA max-width:380px text-wrap:pretty` | ✓ |
| o botão | `:289` — `margin-top:6px;height:32px;padding:0 14px;border:1px solid #2E2E2E;border-radius:8px;background:#111111;font-size:12.5px;**cursor:pointer**` | ⚠️ **OMISSÃO 9** — a coluna de classe do plano não trouxe o `cursor-pointer`; aplicado |

### 2d. O que foi medido e NÃO virou classe — com o motivo escrito

- **`truncate`/`overflow:hidden` no nome do produto:** o protótipo **não declara**. O `min-width:0`
  está lá (`:176`), mas sem `overflow` nada corta. Escrevi `truncate` na primeira versão e **removi
  por medição** — era "adaptar o layout novo ao sistema atual", exatamente o que a milestone proíbe.
  ⚠️ **Consequência real e declarada:** nome muito longo empurra a cobertura e os botões. Se o
  usuário reclamar, é decisão de produto (e aí entra no protótipo), não conserto silencioso.
- **`flex-none` nos dois links, na tag e na contagem:** idem — o protótipo não os tem. Só o
  **chevron** (`:174`) e o **tile** (`:175`) declaram `flex:none`, e os dois o têm.
- **`padding:0` do botão do vazio:** o preflight do Tailwind já emite `button{padding:0}` — classe
  seria redundante (mesma disciplina dos planos 180-07/08).
- **`border-radius:8px` do botão:** **não existe token de 8px** na camada (`pub-control-sm` é 7,
  `pub-control` é 9, `pub-sub` é 10). Usei `rounded-[8px]` e **não inventei token** —
  `tailwind.config.js` é território do plano 180-01.
- **`ml-auto` no "Ver produto" quando a contagem não nasce:** ACRÉSCIMO declarado. O
  `margin-left:auto` do protótipo mora na contagem (`:194`); se ela não é medida, sem isso o botão
  encostaria na cobertura e a linha pareceria outra. Tem caso de teste.

---

## (3) ⚠️⚠️ O ACHADO DA RODADA: `duration-[Nms]` NÃO COMPILA NESTE PROJETO

**Como apareceu:** a sonda de CSS reportou `duration-[180ms]` **AUSENTE**. Pelo learnings, sonda que
diz "ausente" é suspeita de erro de escape — mas aqui a sonda estava certa.

**O fato, medido no CSS construído:** não existe **nenhuma** regra `.duration-\[…\]` em
`public/build/assets/app-*.css` — **zero** ocorrências da string `duration-\[`. A escala NOMEADA
existe (`duration-75/150/200/300/500/700`).

**A causa, reproduzida** com `postcss([tailwind({content:[{raw:'…duration-[180ms]…'}], plugins:[animate]})])`:

```
warn - The class `duration-[180ms]` is ambiguous and matches multiple utilities.
```

O plugin **`tailwindcss-animate`** (`tailwind.config.js`, `plugins: [forms, animate]`) registra
`duration`, `delay` e `ease` como utilitários de **animação**; com dois utilitários disputando o
mesmo nome, o Tailwind **recusa** o valor arbitrário. Sem o plugin, compila.

⚠️ **Por que é grave:** é a mesma família do `content` que varre `*.jsx` e não `*.js` — **a classe
fica no HTML, todo teste de render passa, e a transição não acontece na tela.**

**O que fiz:** o chevron leva `style={{ transitionDuration: '180ms' }}` + `transition-transform`
(que compila). O gate prova as **duas** coisas: que o `style` está lá **e** que a classe morta não
voltou (`assert.equal(regraDe('duration-[180ms]'), null)` **com `duration-200` como controle** — sem
o controle o gate não distinguiria "o arbitrário não compila" de "a sonda está cega").

**O que NÃO fiz:** o conserto por token (`theme.extend.transitionDuration`) exige
`tailwind.config.js`, território do plano 180-01.

**Fora de escopo, registrado em `deferred-items.md` e no learnings §2b:** **6 usos mortos** no
repositório — `Components/Mlb/Publicador/LinhaDeProduto.jsx:40` (`duration-[2000ms]`) e cinco
`duration-[900ms]` em `Pages/Performance/Index.jsx` (`:1249`, `:1334`, `:1358`, `:1485`, `:1532`).
⚠️ **`delay-[…]` e `ease-[…]` caem na mesma ambiguidade e NÃO varri o repositório por eles.**

---

## (4) ⚠️ A PRESCRIÇÃO QUE RECUSEI (1): `estoque: null` mostra "Estoque —"

| fonte | o que manda |
|---|---|
| **o plano**, Task 1 | *"`estoque` nulo → a parte '· Estoque N' **não aparece** (não 'Estoque —', não 'Estoque 0')"* |
| **o briefing** desta rodada | *"`estoque` pode ser `null` → **'Estoque —'**, nunca zero inventado"* |
| **o controller**, `MlbAnuncioController.php:1446`-`:1450` | *"Pode ser `null`, e então a tela mostra **'Estoque —'**, que é honesto; nunca um zero inventado"* |

➡️ **Implementei "Estoque —".** As duas formas respeitam o `null` ≠ `0` que o plano defende; a
diferença é visual. Decidi pelo travessão porque:

1. **é o contrato que o servidor já escreveu no próprio código** (o comentário acima nasceu no plano
   180-04) e porque o briefing repete o mesmo;
2. **"—" = não medido já é convenção desta tela** (a coluna Preço fica inteira em "—" por lacuna de
   prop, 180-08 §6a);
3. **omitir não distingue** "não sabemos o estoque" de "esta linha não tem estoque" — e distinguir é
   justamente o ponto.

⚠️ `estoque: 0` → **"Estoque 0"**, e há caso de teste para cada um; a **mutação iv** (null passando a
mostrar "Estoque 0") reprova. No **grupo legado** a linha inteira não nasce (não há produto que ter
estoque).

---

## (5) ⚠️ A PRESCRIÇÃO QUE RECUSEI (2): `sem_resultado` sem termo NÃO cai em `sem_anuncios`

**A regra do plano:** *"`busca` que não é string → tratado como ausente, e o caso cai em
`sem_anuncios`, nunca imprimindo `[object Object]` dentro das aspas curvas."*

**O problema:** `sem_anuncios` tem o literal **"Esta empresa não tem anúncios ativos no Mercado
Livre."** Se a página mandou `sem_resultado`, o que o servidor disse é que **o recorte** está vazio
— e afirmar que a EMPRESA não tem anúncio ativo nessa situação é **mentira** (basta um filtro de
status restringindo).

➡️ **O que fiz:** `sem_resultado` sem termo legível usa o título do **protótipo** (`:287`),
**"Nenhuma publicação encontrada"** — que é exatamente o texto honesto para "seu filtro não devolveu
nada", e é o literal que o plano manda assertar. O objetivo do plano (**nunca** `[object Object]`
dentro das aspas curvas) é cumprido por construção: sem termo, **não há aspas**. Tem caso de teste
com `busca: {toString: null}`.

---

## (6) Os textos de vazio corrigidos — ANTIGO e NOVO, lado a lado

### 6a. `sem_anuncios` — correção OBRIGATÓRIA (EXMP-01), não melhoria

| | texto |
|---|---|
| **ANTES** (`MeusAnuncios.jsx:171`) | "Publique um anúncio nas abas **Individual ou Em massa**, ou veja os pausados/encerrados no **filtro de status**." |
| **AGORA** | "Publique um anúncio **na aba Produtos**, ou **troque a aba de status acima** para ver as **pausadas** e as **com erro**." |

**O que foi medido antes de reescrever:**

- as abas da conta hoje são **"Visão geral", "Produtos", "Publicações", "Alavancas"**
  (`header/AbasDoHeader.jsx:102`-`:106`) — **"Individual" e "Em massa" NÃO EXISTEM**;
- o próprio `Pages/Mlb/AnunciarMassa.jsx:637` escreve que *"a aba acesa continua sendo 'Produtos':
  'Em massa' deixou de ser aba"* — ou seja, publicar (individual ou em massa) **começa em Produtos**;
- o trilho de status tem **"Todas", "Ativas", "Pausadas", "Com erro", "Em revisão", "Rascunhos"**
  (`MlbAnuncioController.php:978`-`:986`).

⚠️ **Por que o novo texto NÃO diz "encerradas":** `encerrados` **não tem aba** no trilho — ele só
aparece quando já é o filtro ativo (`AbasDeStatus.jsx:ROTULOS_FORA_DO_TRILHO`). Mandar o usuário
procurar uma aba que não está lá seria repetir o erro que acabei de corrigir. **Os dois destinos
citados existem e estão visíveis.**

O gate tem asserção negativa de `/Em massa/` e `/abas Individual/` — o texto velho não volta por
"melhoria de copy".

### 6b. `sem_resultado` — o corpo agora diz os QUATRO identificadores

| | texto |
|---|---|
| **ANTES** (`MeusAnuncios.jsx:182`) | "A busca procura **no título do anúncio, no código MLB e no SKU**. Tente outro termo, ou troque o filtro de status." |
| **AGORA** (= protótipo `:288`) | "A busca considera **nome do produto, título do anúncio, SKU e código MLB**. Tente outro termo ou remova filtros." |

Desde o plano **180-03** a busca cobre os **quatro** identificadores. O texto antigo prometia menos
do que a busca faz — o do protótipo é o que fica verdade.

### 6c. O que NÃO mudou nem uma vírgula

- `nunca_coletado`: **"Ainda não coletamos os anúncios desta empresa."** + **"A primeira coleta roda
  automaticamente até amanhã, ou clique em Atualizar agora para adiantar."**
- `sem_resultado` (com termo): **`Nada encontrado para “{termo}”.`** — com as aspas **curvas**
  U+201C/U+201D, assertadas por **code point**.
- **o aviso de SKU não coletado**, byte a byte, inteiro: *"O SKU dos anúncios desta empresa ainda não
  foi coletado — por isso uma busca por SKU não acharia nada mesmo que o anúncio exista. Clique em
  Atualizar agora e busque de novo."* ⚠️ É o aviso que o critério 13 nomeia, e ele só aparece **no
  caso com busca** (mutação **viii** prova). E porque ele **manda clicar em "Atualizar agora"**, o
  gate exige que o botão (`acao`) esteja na tela — EXMP-01 vale para instrução, não só para botão.

---

## (7) A decisão do `criar_combo_url`: o link NASCE, e a ressalva é DITA

O briefing deixou em aberto: *"se você achar que o botão promete mais do que entrega, o honesto é
`null` — e aí ele não nasce"*.

**Os dois fatos medidos (plano 180-04):** o destino é **real e já está em produção** — é o MESMO que
a linha "Prontos para a Fase 2" da Visão geral usa (`PainelVisaoGeralService::linhaDeProdutos()`,
`:624`) — **mas não pré-seleciona o produto**, porque não existe rota GET que crie o combo de um
produto específico e `Produtos.jsx` lê `?filtro=`/`?fase=` da URL com a busca em estado local
(`:463`).

➡️ **Decisão: o link nasce.** Ele leva a tela de Produtos **já filtrada em quem ainda pode ganhar
Fase 2**, que é um passo real na direção da tarefa — não é um botão que não faz nada. **Matá-lo
tiraria do operador o único atalho existente.**

➡️ **E a ressalva é dita, não escondida:** o texto visível é o do protótipo byte a byte ("Fase 2 não
criada · Criar combo", que a fidelidade não deixa mudar), e o `title` = `aria-label` (IDENT-07, o
mesmo texto nos dois) diz: *"Fase 2 não criada — abre a lista de produtos sem Fase 2; o produto não
vem pré-selecionado"*. Tem caso de teste.

⚠️ **E o `E` do link está provado em três casos:** `sem_fase2: true` + url `null` → **sem** link (é o
caso REAL do servidor quando a conta não resolve); `sem_fase2: false` + url válida → **sem** link;
`true` + válida → **com** link. A **mutação vi** (ignorar a url) reprova.

---

## (8) "N produtos" TEM fonte no rodapé — a nota do briefing era de OUTRO componente

O briefing avisou que *"'N produtos' NÃO TEM FONTE (medido pelo 180-06)"*. **Medi antes de obedecer,
e a nota é de outro lugar:** o 180-06 §5a fala do **`resumo` da BUSCA** (`BuscaDePublicacoes`), onde
de fato não há fonte e o componente recebe `resumo={null}`.

**O `rodape.produtos` existe e é do servidor:** `MlbAnuncioController.php:1022`-`:1026` manda
`['visiveis' => $anuncios->count(), 'total' => $anuncios->total(), 'produtos' => count($produtoIdsDaPagina)]`.

⚠️ **A semântica, escrita para o 180-10 não se enganar:** `visiveis` e `produtos` são **da PÁGINA**
(os 50), `total` é do **recorte inteiro** (do paginator). A frase "50 de 248 publicações · 9
produtos" lê-se *"as 50 que você está vendo, de 248 no recorte, cobrem 9 produtos"* — coerente,
porque os dois números de página falam da mesma página. **Nada é contado no cliente (D-05).**

---

## (9) A regra do plural e o que acontece sem número

| situação | o que a tela diz |
|---|---|
| contagem `3` de `3` | "3 publicações" |
| contagem `1` de `1` | "1 publicação" |
| contagem `2` de `7` | "2 de 7 publicações" |
| contagem `1` de `7` | "1 de 7 publicações" (o plural segue o ÚLTIMO número, que é o que o leitor associa à palavra) |
| qualquer um dos dois não sendo número finito | **a contagem não aparece** |
| rodapé `{50, 248, 9}` | "50 de 248 publicações · 9 produtos" |
| rodapé `{1, 1, 1}` | "1 de 1 publicação · 1 produto" |
| rodapé sem `produtos` | "50 de 248 publicações" (sem separador solto) |
| rodapé sem `visiveis` | "248 publicações" — `total` sozinho é verdade |
| rodapé sem `total` | a parte das publicações **não** aparece — ⚠️ `visiveis` sozinho seria LIDO como total, e "50 publicações" quando existem 248 é meia-verdade |
| `rodape: null` | **só** a legenda das 4 teclas |

⚠️ **`contagemMedida()` é mais estrita que `numeroMedido()` de propósito:** lá a string `'7'` vira
`7`; aqui o número entra numa FRASE que afirma quantas publicações existem, e lavar string de
servidor quebrado em afirmação numérica é o avesso de "nunca medimos ≠ medimos e é zero". O contrato
do 180-04 manda `int`.

---

## (10) ⚠️ UM BUG DE RENDER ACHADO ANTES DO COMMIT — a tela preta pela porta da prop de NÓ

O contrato tem **duas props de `ReactNode`**: `paginacao` (rodapé) e `acao` (vazios). O plano manda
render adverso com **`paginacao` objeto**.

⚠️ **`{objeto}` dentro do JSX faz o React LANÇAR** `Objects are not valid as a React child` — ou
seja, a página inteira vai embora. É a tela preta de 07/10/2026 **pela porta da prop de nó**, e
nenhum dos guards de texto do projeto (`textoSeguro`, `textoDaTela`, `caminhoSeguro`) cobre esse
caminho, porque eles tratam **texto**, não **nó**.

➡️ Nasceu `noRenderizavel()` (`RodapeDaTabela.jsx`), que só devolve o nó quando é
`isValidElement`, array **inteiro** de elementos válidos, ou string não vazia. `boolean` e `number`
são recusados de propósito: `{false}` deixaria o lugar mudo sem ninguém saber por quê, e um número
solto seria um "7" perdido no rodapé. O `VaziosDaLista` **importa** a função — uma casa só.

---

## (11) "O que precisaria estar errado no código para este gate falhar?" — por bloco

| bloco (nº de casos) | o que precisaria estar errado |
|---|---|
| `os literais da linha de grupo` (4) | a tag virar "Produto"; o `·` virar "-" ou BULLET; o "Criar combo"/"Ver produto" serem reescritos; a contagem perder o "de" |
| `os literais do rodapé e dos vazios` (6) | o `↑↓` virar a palavra "setas"; qualquer um dos 3 textos honestos ser reescrito; o corpo do `sem_anuncios` voltar a apontar para "Individual/Em massa"; as aspas curvas virarem retas; **o aviso de SKU ser encurtado** |
| `a geometria da linha e os três irmãos` (3) | o `primeiro` deixar de tirar o `border-t` (linha dupla sob o cabeçalho) ou os outros perderem a borda; QUALQUER classe de QUALQUER das 14 peças mudar (igualdade de conjunto: classe a mais OU a menos reprova); o `cursor-pointer` cair |
| `o chevron gira` (3) | o chevron parar de girar (aberto e fechado iguais); o `rotate` ir para o lado errado; o `aria-expanded` não acompanhar; a linha perder `tabIndex`/`role`/nome acessível |
| `estoque` (3) | `null` escrever "Estoque 0" (zero inventado); `0` virar travessão (esconder medição real); string `'12'` ser lavada em número |
| `a contagem do grupo` (6) | o "x de N" virar sempre "N" (a tela mentiria sobre o total do produto); o singular cair; `NaN` escrever "NaN publicações"; o `ml-auto` não migrar para o botão sem contagem |
| `EXMP-01 — os dois links` (7) | um `href="#"` nascer; link sem destino ser desenhado; "Criar combo" ser oferecido a quem já tem Fase 2 **ou** a quem não tem destino; a ressalva sair do nome acessível |
| `o grupo legado` (3) | tile de iniciais de um nome que não é produto; a tag PRODUTO aparecer; "Estoque"/SKU nascerem vazios; um dos links ser oferecido; o mapa de cobertura sumir |
| `o rodapé e a paginação` (4) | a frase escrever "— de —"; pedaço sobrando com separador solto; `rodape: null` desenhar frase com buracos; **a paginação de 50 deixar de ser renderizada** (critério 13); nó inválido derrubar a tela |
| `os quatro estados vazios` (5) | `caso: null` desenhar caixa de 64px; caso desconhecido virar texto; **o aviso de SKU aparecer no `sem_anuncios`**; o botão "Limpar" nascer sem `onLimparTudo` |
| `IDENT-08 / T-134-20` (5) | alguém redesenhar as 4 células ou o tile aqui; copiar `oklch(`; usar `dangerouslySetInnerHTML`; hex literal; `text-sm`; `ecf-*`; `router.*`; `String()`/`JSON.stringify`; **redeclarar o literal do grid**; o `.map()` perder o corpo de bloco |
| `render adverso` (8) | qualquer uma das 11 chaves (ou a prop inteira) chegando objeto/`NaN`/nulo/ausente e virando texto; `cobertura` com chave errada; **`grupo` nulo** (derrubou o painel inteiro na Fase 178) |
| `as classes GERAM CSS` (10) | classe presente com VALOR errado: 58→56, o padding assimétrico virando simétrico, o `-ml-[4px]` perdendo o sinal, `bg-pub-raised` apontando para `raised-0`/`raised-2`, o alfa do chip saindo de 0,12, `[text-wrap:pretty]` não compilando, **`duration-[180ms]` voltando a ser classe** |

### E o que NÃO tem gate — declarado, não maquiado

- **A eliminação de variável pelo Rollup não é reproduzível em `renderToStaticMarkup`.** O que existe
  é gate **estrutural**: o único `.map()` com corpo de bloco dos 3 arquivos é o das teclas, a flag
  nasce dentro do callback, e há asserção de que `LinhaDeGrupoDeProduto` e `VaziosDaLista` **não
  mapeiam nada**. A lacuna está escrita, não presumida equivalente.
- **Nenhuma mutação prova a sonda de CSS isoladamente** — o valor só mudaria mexendo em
  `tailwind.config.js`, que é território do 180-01. O que protege a sonda são as **4 iscas**
  (`bg-pub-isca-9k4`, `h-[59px]`, `pl-[13px]`, `py-[63px]`, todas medidas com **0 ocorrências** no
  repositório antes do uso, e `h-[59px]` de propósito com a MESMA FORMA do `h-[58px]` real).
- **O rótulo "F1"/"F2" do mapa de cobertura** — desvio declarado (§1), não erro escondido.
- **O nome longo do produto não é truncado** (§2d) — é o que o protótipo declara.
- ⚠️ **Nenhuma tela renderizada foi vista.** Não abri navegador, não deployei, não toquei no VPS e
  **não li produção** (`ml_acervo_itens` local está vazia). Tudo aqui é render de servidor + CSS
  construído.

---

## (12) As 8 mutações — 8/8 PEGAS, 0 sobreviventes, 0 recusadas

Contagem de ocorrências travada **antes** de cada aplicação (recusa se ≠ 1), linha conferida,
restauração por **cópia pristina** e conferência por **sha256**, e o **ANSI removido** antes de
filtrar o veredicto.

| # | mutação | arquivo:linha | fail | quem reprovou |
|---|---|---|---|---|
| i | `h-[58px]` → `h-[56px]` | `LinhaDeGrupoDeProduto.jsx:284` | **4** | `o PRIMEIRO grupo não tem border-t` (conjunto de classes) + `a string do GRID não é redeclarada` |
| ii | `-ml-[4px]` removido | `:301` | **2** | `as peças internas têm as classes medidas` |
| iii | `border-t` também no primeiro | `:285` | **2** | `o PRIMEIRO grupo não tem border-t` |
| iv | `estoque: null` mostrando "0" | `:246` | **3** | `estoque: null → "Estoque —"` + `estoque que não é número não inventa valor` |
| v | "x de N" virando sempre "N" | `:121` | **7** | `a contagem diz "2 de 7"`, `2 de 7 →`, `1 de 7 →`, `nome acessível` |
| vi | `criar_combo_url` ignorado | `:257` | **2** | `sem_fase2: true + url nula → SEM link` |
| vii | aspas RETAS no `sem_resultado` | `VaziosDaLista.jsx:154` | **2** | `o sem_resultado diz o TERMO com aspas CURVAS` |
| viii | aviso de SKU no `sem_anuncios` | `:160` | **2** | `o aviso de SKU NÃO aparece no sem_anuncios` |

**sha256 antes = sha256 depois**, nos dois arquivos:
`LinhaDeGrupoDeProduto.jsx = bc2705a8…`, `VaziosDaLista.jsx = c8a80fc3…`.

### ⚠️ Nota honesta sobre a campanha

**A contagem caiu de 81 para 71 em TODA mutação, e isso é esperado, não falha:** ao tocar o
componente, ele fica mais novo que o build e o bloco de CSS **pula** (`skipped 1`), e aí os 10
sub-testes dele **não se registram**. O script **trava a contagem válida** em `(81, skip 0)` ou
`(71, skip 1)` — qualquer outra significa **arquivo que não carregou**, que é o modo de falhar em
silêncio que o learnings §4.11 descreve (o 180-08 viu 1 teste em vez de 107 por um `SyntaxError`).
A base rodou **verde** antes da primeira mutação, e o bloco de CSS foi conferido **verde** com build
fresco antes e depois da campanha.

⚠️ **E a campanha quase mentiu duas vezes, pelos motivos que o learnings prevê:**
1. a primeira rodada abortou no `print` por `UnicodeEncodeError` (stdout em cp1252) **depois** de
   restaurar o arquivo — resolvido com `PYTHONIOENCODING=utf-8`; a árvore ficou limpa e o sha
   conferido;
2. um patch em Python falhou no `assert` e o `echo` seguinte do shell imprimiu **"parte 1 ok"** —
   nada tinha sido aplicado. Conferi por `grep` no arquivo antes de seguir. **`&&` entre python e
   echo não existia: o shell segue depois de erro.**

---

## (13) ⚠️ QUATRO FUROS DO MEU PRÓPRIO GATE — todos reprovavam o código CERTO

| # | furo | como apareceu | conserto |
|---|---|---|---|
| 1 | **`classesDa()` dentro de `filter`** | `classesDa` **asserta** a presença de `class=` e lança em `<div>` sem classe (o wrapper do `acao`, o contêiner dos 3 grupos). **6 testes reprovaram o código certo** | nasceu `temClasse(tag, classe)`, que devolve `false` em vez de lançar — e ainda compara por **lista**, não por substring |
| 2 | **"controle" sem sentido** | `assert.equal(regraDe('ml-[4px]') === null, false, 'controle…')` — eu assertei que o `ml-[4px]` POSITIVO existe no projeto. Ele **não** existe, e o gate reprovou o código certo | virou o que interessa: `-ml-[4px]` tem `margin-left:-4px` e **não** `4px` (o SINAL é a informação) |
| 3 | **referências de linha deslocadas** | escrevi `:179`/`:181`/`:184`/`:187`/`:190`/`:191` de cabeça a partir de um `sed` de bloco. As medidas por `awk` com índices absolutos são `:180`/`:182`/`:185`/`:190`/`:194`/`:195` | corrigidas no commit `b2bf9a04` — é a §1 do learnings em ação, cometida por mim |
| 4 | **o 6º caminho fantasma** | o briefing cita `resources/js/Components/Mlb/SubAbasDePublicacoes.jsx`; o real é `resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx`. Pego pelo `test -e` **antes** de virar diff vazio provando território intocado | caminho certo no gate de território + acrescentado ao learnings §4.8 |

---

## (14) ⚠️ Os nomes para `ARQUIVOS_NOVOS` — e a contagem ELEVADA de 11 → 14

```
resources/js/Components/Mlb/Publicador/publicacoes/LinhaDeGrupoDeProduto.jsx
resources/js/Components/Mlb/Publicador/publicacoes/RodapeDaTabela.jsx
resources/js/Components/Mlb/Publicador/publicacoes/VaziosDaLista.jsx
```

➡️ **O plano 180-10 em diante tem de ELEVAR de 14** junto com os arquivos dele. Deixar o número
parado é o que denuncia o esquecimento — arquivo fora da lista fica **sem** conferência contra
`text-sm`, `ecf-*`, `font-display`, hex literal e breakpoint, e o gate passaria **por omissão**.

### O par `+assert` / `-assert`

| arquivo | linhas | `+assert` | `-assert` | leitura |
|---|---|---|---|---|
| `tests/js/publicador-vocabulario-novo.test.js` | +15 / **-7** | **0** | **0** | as 7 removidas são o nome do teste, 2 comentários, o valor `11,` e 3 linhas de mensagem. **Nenhuma contém a palavra `assert`** — conferido por `grep -c`. Os 18 sub-testes novos (3 × 6) passaram a rodar: **313 → 331** |
| `tests/js/publicador-publicacoes-grupos.test.js` | +1556 / **-0** (novo) | — | — | — |
| os 3 componentes | +704 / **-0** | — | — | nenhuma linha removida: os três são novos |

É o caso que o learnings chama de *"gate vazio ao contrário"*: um `grep '^-' | grep assert` acusaria
remoção onde não houve.

⚠️ **`tailwind.config.js` e `publicador-tokens-do-publicador.test.js` NÃO foram tocados** — nenhum
token novo nasceu nesta rodada.

---

## (15) Verificação

| gate | resultado |
|---|---|
| `node --test` no gate novo | **81 testes / 81 pass / 0 fail / 0 skipped** (com build fresco; sem build, 71 + `skipped 1` com a razão escrita) |
| `node --test` nos 3 gates do plano (grupos + vocabulário + identidade) | **476 / 476 pass / 0 fail / 0 skipped** |
| `publicador-identidade-nova-render.test.js` | **verde e INTOCADO** (`identidade/` com diff vazio nos dois ranges) |
| `estrutura-meus-anuncios.test.js` | **verde e INTOCADO** — a cirurgia nos gates da tela é do plano 180-10 |
| `npm run test:js` **ANTES** (medido antes de tocar em nada) | **4226 / 4225 pass / 1 fail / 0 skipped** — bate com o briefing |
| `npm run test:js` **DEPOIS** | **4325 / 4324 pass / 1 fail / 0 skipped** — delta **+99** (81 do gate novo + 18 do vocabulário), **medido, não inferido** |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido") — **NÃO corrigida**, é conhecida e intencional |
| `npm run build` | exit **0**, 38,6 s (rodado **depois** da campanha, para o bloco de CSS não ficar em `skip`) |
| manifest **pelo JSON** | **478 entradas** (idênticas às 478 dos planos 180-05/06/07/08), **195 páginas** `.jsx`, 11 casando `/publicador/i`; `MeusAnuncios.jsx`, `AnunciosHistorico.jsx`, `Publicador/Editor.jsx` e `Publicador/Produtos.jsx` **presentes** — nenhuma página perdida. CSS `assets/app-BiKK0DkT.css`, **222.837 bytes** (eram 222.332: **+505**, as classes novas) |
| `test -e` nos **22** alvos de território | **21/22 existem**; o 22º era caminho fantasma do briefing e foi corrigido (§13, furo 4) |
| território **RANGE A** (`f23f610d..HEAD`) | **VAZIO** em `identidade/`, `header/PublicadorHeader.jsx`, `visaogeral/`, `Components/Publicador/Mesa`, `Publicador/SubAbasDePublicacoes.jsx`, `Editor.jsx`, `AnunciosHistorico.jsx`, `anuncioHistoricoUtils.js`, `AppLayout.jsx`, `app/Support/Publicador`, `MlPublicacaoService.php`, `estrutura-meus-anuncios.test.js`, `publicador-paginas-anuncios-render.test.js`, `estrutura-anuncios-historico.test.js`, `publicador-header-zindex-ordem.test.js` |
| território **RANGE B** (`31a4fa67..HEAD`) | **só `.planning/ROADMAP.md` (1/1)**, de `d79918e3 docs(184-07)` — a **sessão paralela**. Vazio em `MeusAnuncios.jsx`, `MlbAnuncioController.php`, `app/Services/Publicador/`, `tailwind.config.js`, `publicador-tokens-do-publicador.test.js` e nos **11** arquivos de `publicacoes/` dos planos 180-05/06/07/08 |
| **CONTROLE** do gate de território | o MESMO comando vê `368/0`, `169/0`, `184/0`, `1556/0` e `15/7` nos meus 5 — logo não é vazio por construção |
| PHP / migrations / pacotes **NOS MEUS 5 COMMITS** | `git diff --numstat` em `app/ database/ routes/ composer.json composer.lock package.json package-lock.json` → **VAZIO nos cinco**. ⚠️ Conferido **commit por commit**, não pelo range |
| deleções nos meus 5 commits | **0 nos cinco** (`git diff --diff-filter=D`) |
| autor | ⚠️ **NÃO conferido de propósito**: as duas sessões rodam com o mesmo `user.name` e `%an` é **vazio por construção** aqui (learnings §4). O discriminador é o **assunto** (`180-*` × `184-*`) |

### ⚠️ A sessão paralela da Fase 184 — commits INTERCALADOS com os meus

Entre/ao redor dos meus cinco commits entraram **6** da outra sessão: `73ba3520`, `3a2dead8`,
`b81656cf`, `d79918e3` (184-07) e `0a3eaea7`, `9ed913cb` (184-08), com PHP, blade e rota. **Por isso
a prova de "zero PHP" é commit por commit**, e **a suíte PHP NÃO foi rodada**: ela mediria o trabalho
dela. ⚠️ `app/Services/Bling/ImportadorDoBling.php`, `BlingOAuthService.php` e `routes/web.php` estão
**modificados na árvore** por ela — **não toquei em nenhum**, e todo commit meu saiu com **pathspec**.

Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma migration, nenhum pacote, nenhuma
leitura de produção. `ROADMAP.md` e `REQUIREMENTS-v26.md` **não tocados** e `state.advance-plan`
**não executado** — o bloco do `STATE.md` é aditivo.

---

## (16) O que o 180-10 liga — e o que NÃO deve fazer

### O contrato, pronto para a composição

```jsx
{/* visão "Por produto" — um por grupo, na ORDEM do servidor */}
<LinhaDeGrupoDeProduto
    grupo={grupo}                              // o item de `grupos` (plano 180-04), inteiro
    aberto={!!busca || !colapsado[grupo.produto_id]}   // ⚠️ a BUSCA força aberto — a regra é SUA
    primeiro={indice === 0}
    onAlternar={() => alternarGrupo(grupo.produto_id)}
/>

<RodapeDaTabela
    rodape={rodape}                            // `{visiveis, total, produtos}`, como veio
    paginacao={<nav>…</nav>}                   // os `anuncios.links` + `decodificarRotuloPaginacao()`
/>

<VaziosDaLista
    caso={defasagem.nunca_coletado ? 'nunca_coletado' : (temBuscaOuFiltro ? 'sem_resultado' : 'sem_anuncios')}
    busca={busca}
    skuNaoColetado={skuNaoColetado}
    acao={<BotaoAtualizar … />}                // o aviso de SKU MANDA clicar nele
    onLimparTudo={limparBuscaEFiltros}
/>
```

- ⚠️ **A regra "a busca ativa FORÇA os grupos abertos" (LIST-09) mora NA PÁGINA.** A linha recebe
  `aberto` resolvido e **não** recalcula nem lê a busca — a regra tem uma casa só.
- ⚠️ **`caso: 'nunca_coletado'` SUBSTITUI a lista inteira** (não há snapshot nenhum). Os outros dois
  vão **dentro** do card, depois dos grupos e **antes** do rodapé — é a ordem do protótipo
  (`:285` vem antes de `:293`).
- ⚠️ **O `acao` não pode faltar quando `skuNaoColetado` é verdadeiro:** o aviso manda clicar em
  "Atualizar agora".
- ⚠️ **O banner de defasagem** (âmbar, "Última coleta há N dia(s)…") é SEU e vive **acima** da
  tabela — ele **nunca** esconde a lista. Não recriado aqui.
- ⚠️ **A paginação de 50 FICA** (critério 13): monte o nó com o `decodificarRotuloPaginacao()` que já
  existe em `MeusAnuncios.jsx:267` e passe pronto. **Nunca `dangerouslySetInnerHTML`** — o gate de
  vocabulário o proíbe nos 14 arquivos.
- ⚠️ **As três props do 180-08** (`rotulosDeMotivo`, `hoje`, `urlDoRascunho`) continuam pendentes —
  esquecer a terceira quebra LIST-23 em silêncio.
- ⚠️ **Eleve `ARQUIVOS_NOVOS` de 14** com os seus arquivos (§14).
- ⚠️ **Não** mexa em `identidade/` para "consertar" o mapa de cobertura (§1) nem em
  `tailwind.config.js` para criar o token de duração (§3) — os dois são desvios **declarados**.
- ⚠️ **Se precisar de transição com duração fora da escala nomeada, vá de `style`** — `duration-[…]`
  **não compila neste projeto** (§3).

---

## Self-Check: PASSED

| o que | resultado |
|---|---|
| `LinhaDeGrupoDeProduto.jsx` existe (≥110 linhas pedidas) | **FOUND — 368 linhas** |
| `RodapeDaTabela.jsx` existe | **FOUND — 169 linhas** |
| `VaziosDaLista.jsx` existe (≥90 linhas pedidas) | **FOUND — 184 linhas** |
| `tests/js/publicador-publicacoes-grupos.test.js` existe | **FOUND — 1556 linhas** |
| `.planning/phases/180-publicacoes-lista/deferred-items.md` existe | **FOUND** |
| `key_link` do plano (`from '../identidade/MapaDeCobertura'`) | **FOUND** — e assertado pelo gate |
| commit `8312faa1` | **FOUND** |
| commit `e0136fa1` | **FOUND** |
| commit `b2bf9a04` | **FOUND** |
| commit `2542b954` | **FOUND** |
| commit `43ef989c` | **FOUND** |
