---
phase: 180-publicacoes-lista
plan: 06
subsystem: ui
tags: [publicador, publicacoes, busca, abas-de-status, toggle-de-visao, fidelidade, render-adverso, mutacao, sonda-css]

requires:
  - phase: 180-publicacoes-lista
    plan: "01"
    provides: "`15.5` em `TAMANHOS_PERMITIDOS` — e o PRIMEIRO uso de `text-[15.5px]` no código é o `<input>` deste plano (o 180-01 só liberou o valor); mais os tokens `pub-table` (14px), `pub-sub` (10px), `pub-control-sm` (7px), `pub-seg`, `pub-seg-ativo`, `pub-hover-2`, `pub-danger`"
  - phase: 180-publicacoes-lista
    plan: "04"
    provides: "as props `abas` (6 entradas ORDENADAS, `comDot` em camelCase já com o `> 0` embutido), `contagens` (7 eixos de status + `rascunhos`), `filtros.status` e `filtros.visao`"
  - phase: 180-publicacoes-lista
    plan: "05"
    provides: "o molde do harness de render real (esbuild + `react-dom/server`, bundles antes do primeiro `test()`), o `descreve()` em vez de `String()` na mensagem de asserção, a sonda de CSS com as 7 normalizações, e o diretório `publicacoes/` ABERTO no gate de vocabulário com a contagem em 2"
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "`STATUS_OPCOES` em `MeusAnuncios.jsx:47`-`:52` — os rótulos pt-BR de `acionaveis` e `encerrados` que este plano REUSA em vez de inventar; e `SUBABAS` (`:31`), o segmentado que LIST-25 dissolve"
provides:
  - "`publicacoes/BuscaDePublicacoes.jsx` — o input de 54px/raio 14 com foco amarelo e ring de alfa 0,18, a lupa 18×18 com o viewBox do protótipo, e os dois estados da direita (resumo + 'Limpar' com busca; dica da tecla '/' sem busca). Exports `fraseDoResumo`, `temBusca` e os 5 literais"
  - "`publicacoes/AbasDeStatus.jsx` — o trilho de 6 opções com a ORDEM e os RÓTULOS do servidor, `comDot` sem recálculo, 'Rascunhos' integrada (LIST-25) com divisória declarada, e a OPÇÃO FORA DO TRILHO para `acionaveis`/`encerrados`. Exports `montarOpcoes`, `ROTULOS_FORA_DO_TRILHO`, `CLASSE_DO_TRILHO`, `CLASSE_ATIVA`, `CLASSE_INATIVA`, `CHAVE_DA_FONTE`, `ARIA_DA_FONTE`"
  - "`publicacoes/LinhaDeControles.jsx` — a linha `abas | ordenação + toggle`, com `role=\"group\"` + `aria-pressed` (NUNCA um 2º radiogroup) e o rótulo de ordenação que diz a VERDADE. Exports `OPCOES_DE_VISAO`, `ORDENACAO_PADRAO`, `VISAO_POR_PRODUTO`, `VISAO_LISTA`, `ARIA_DO_TOGGLE`"
  - "`tests/js/publicador-publicacoes-busca.test.js` — 114 testes: literais byte a byte (com o code point do `…`), geometria por igualdade de conjunto, os 6 irmãos localizados por ÁRVORE com índices absolutos, render adverso (17 formas × cada prop × cada campo) e sonda de CSS construído com 2 iscas"
  - "a contagem de `publicacoes/` ELEVADA de 2 para 5 no gate de vocabulário, com os 3 nomes declarados"
  - "as 3 DECISÕES que o controller e o briefing delegaram, tomadas e justificadas (abaixo)"
affects: [180-07, 180-10, 180-11, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "`montarOpcoes()` pura e exportada: as flags do trilho (`ativa`, `separador`, `natureza`) nascem FORA do JSX, lendo só o vizinho anterior da própria lista pelo 3º argumento do `.map()` — e o JSX fica com `{opcoes.map((item) => opcao(item))}`, com `opcao()` declarado DENTRO do componente (a forma de `AbasDoHeader.pilula()`, que é a proteção contra a eliminação de variável de escopo pelo Rollup)"
    - "`Fragment` com `key` em vez de `<span>` embrulhando o par divisória+botão: envelope extra viraria o item flex do trilho e mudaria o `gap:2px` do protótipo"
    - "A classe do trilho tem UMA casa: `CLASSE_DO_TRILHO`/`CLASSE_ATIVA`/`CLASSE_INATIVA` são exportadas de `AbasDeStatus.jsx` e IMPORTADAS por `LinhaDeControles.jsx` — o protótipo diz que os dois trilhos são idênticos (`:83` = `:92`), e o gate prova a igualdade em vez de a semelhança. Os dois arquivos são `.jsx`, logo o scanner do Tailwind vê o literal"
    - "`blocoEm(html, pos, tag)` contando PROFUNDIDADE: é o que torna a localização por árvore honesta quando os irmãos são idênticos — fatiar 'do começo da tag até o próximo `</div>`' fecharia no filho"
    - "Gate estrutural contra o Rollup: `assert.match(FONTE, /\\{opcoes\\.map\\(\\(item\\) => opcao\\(item\\)\\)\\}/)` — JSX inline no callback reprova, o que `renderToStaticMarkup` jamais pegaria"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/BuscaDePublicacoes.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/AbasDeStatus.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/LinhaDeControles.jsx
    - tests/js/publicador-publicacoes-busca.test.js
    - .planning/phases/180-publicacoes-lista/180-06-SUMMARY.md
  modified:
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "⚠️ `acionaveis`/`encerrados`: o trilho ganha uma 7ª opção À FRENTE de 'Todas', marcada, com o rótulo que a tela de HOJE já usa ('Acionáveis'/'Encerrados', `MeusAnuncios.jsx:47`/`:51`) e o número de `contagens`. Não é aba inventada — é a chave que o servidor está aplicando, com o nome que ele já tem na tela. Status DESCONHECIDO não ganha opção nenhuma (lista fechada de 2)"
  - "⚠️ Toggle de visão = `role=\"group\"` + `aria-pressed`, NUNCA um 2º `role=\"radiogroup\"`: `publicador-paginas-anuncios-render.test.js:354` assere `length === 1` na página, e um segundo reprovaria aquele gate, que não é desta fase. ➡️ O plano 180-10 NÃO precisa reapontar nada"
  - "⚠️ O rótulo da ordenação é prop e o DEFAULT é 'Mais graves primeiro', não o 'Mais recentes primeiro' do protótipo: o servidor ordena por `orderByDesc('severidade')` (D-12) e a própria tela de hoje chama isso de 'ordenação fixa por gravidade'. Rótulo que mente é pior que rótulo diferente do protótipo"
  - "'Rascunhos' entra no MESMO trilho com divisória antes dela — DESVIO declarado de fidelidade (o protótipo não tem a opção). O dado, a query (`sub`) e o contador (`subTotais`/`contagens.rascunhos`) ficam como hoje; só o atalho mudou de lugar"
  - "A opção de visão NÃO leva `display:flex` nem `gap`: o protótipo declara `display:flex` no botão de ABA (`:85`) e NÃO no de visão (`:96`). A tabela FID-01 dizia 'igual à aba'; o protótipo ganha"
  - "`font-weight` do toggle é FIXO em 500 (`:96`). O '600 quando ativa' do briefing é dos CHIPS de triagem (`seg()`, `:535`), que são do plano 180-07"
  - "A CONTAGEM do resumo nunca é feita no cliente: divergência declarada com o protótipo (`:626` usa `list.length`), porque a página é paginada de 50 em 50 sobre dezenas de milhares de linhas (D-05)"
  - "`SubAbasDePublicacoes.jsx`, a rota `historico`, `AnunciosHistorico.jsx` e os testes deles ficaram INTOCADOS — provado por diff vazio em dois ranges"

metrics:
  duration: "~2h (2026-10-13)"
  completed: 2026-10-13
  tasks: 3
  commits: 4
  testes-js-novos: 132
  mutacoes: "10/10 pegas"
---

# Fase 180 Plano 06: busca central, abas de status e toggle de visão — Summary

A barra de comando da tela de Publicações, medida no protótipo e não na tabela: a busca de 54px, o
trilho de 6 opções com "Rascunhos" dentro (LIST-25) e o toggle `Por produto | Lista`. As **três
decisões** que o controller e o briefing delegaram estão tomadas, justificadas e travadas por gate.

## O que foi feito

| Task | O que | Commit | Arquivos |
|---|---|---|---|
| 1 | `BuscaDePublicacoes.jsx` | `da365486` | +221 / **-0** |
| 2 | `AbasDeStatus.jsx` + `LinhaDeControles.jsx` | `f3092c45` | +424 / **-0** |
| 3 | o gate (114 testes) + `publicacoes/` de 2 → 5 no vocabulário | `baf7f757` | +1445 / -0 e +21 / **-11** |

Total do plano (`68ea21d8..HEAD`, só os meus 5 arquivos): **+2111 / -11**.
**Zero PHP, zero migration, zero pacote, zero deleção** — provado por `git diff --numstat` vazio em
`app/`, `database/`, `routes/`, `composer.json` e `package.json`.

---

## (1) ⚠️ AS TRÊS DECISÕES DELEGADAS

### 1a. `comDot` em camelCase — CONFERIDO no controller, não presumido

`MlbAnuncioController.php:888`-`:897` manda `'comDot' => …` em **camelCase**, com o comentário
explicando que snake faria o componente ler `undefined` e nunca desenhar o ponto. O componente lê
`aba.comDot === true` e **não recalcula** de `total > 0`.

➡️ Travado por **dois** testes e **uma** mutação:
- `total: 5, comDot: false` **não** desenha dot (se alguém recalcular, reprova);
- `com_dot: true` em snake_case **não** liga nada, e a fonte não contém a string `com_dot`;
- mutação **v** (`comDot: Number(aba.total) > 0`) reprovou em **9** asserções.

### 1b. ⚠️ `acionaveis` / `encerrados` — A DECISÃO, E O PORQUÊ

**O problema medido:** `filtros.status` pode valer `acionaveis` (o **DEFAULT** de `meus()`) ou
`encerrados`, e **nenhum dos dois tem aba** nas 6 que o servidor manda. Os dois são destinos vivos:
`CockpitDaContaService.php:291` monta `?status=acionaveis` e `:86` monta `?status=encerrados`.
O briefing proibiu as duas saídas óbvias: **nem trilho sem opção marcada, nem aba que o servidor
não conhece.**

**O que decidi:** quando `ativa` não está entre as `abas` **e** é uma das duas chaves da lista
fechada, o trilho ganha uma opção **à frente de "Todas"**, marcada, com divisória depois dela.

**Por que isto não é inventar aba:**

| o que poderia ser invenção | o que é, medido |
|---|---|
| a chave | `acionaveis`/`encerrados` são valores que `escopo()` aceita e que `contagens` conta (`MlbAnuncioController.php:866`/`:870`) |
| o rótulo | **"Acionáveis"** e **"Encerrados"** são os rótulos que a tela de HOJE já usa, em `STATUS_OPCOES` (`MeusAnuncios.jsx:47` e `:51`) — não escrevi nenhum texto novo |
| o número | sai de `contagens[chave]`, prop que o controller já manda; **sem `contagens` a opção nasce SEM número**, nunca com 0 inventado |
| o destino | o clique devolve a `chave` ao `onTrocar`, como qualquer outra opção — nenhum botão sem destino |

**E o caso que fica sem marcação, de propósito:** `status` **desconhecido** (um `?status=arquivados`
inventado na URL) **não** ganha opção e **nenhuma** aba fica marcada. Marcar algo ali seria afirmar
um recorte que a lista não está aplicando. A proibição do briefing vale para os dois casos REAIS do
sistema, e esses dois estão cobertos.

➡️ 6 sub-testes + mutação **viii** (tirar `acionaveis` da lista), que reprovou em **6** asserções.

### 1c. "Com erro" — nada a fazer, e está certo assim

A aba vem do servidor com `chave = com_erro` e rótulo **"Com erro"** da tela. O zero honesto é
aceito (decisão do usuário em 13/10/2026): com `total: 0` a aba mostra **0 sem ponto vermelho**,
porque `comDot` chega `false`. ⚠️ **Não medi a tela renderizada** e **não medi produção** — o
`ml_acervo_itens` local está vazio, então não sei se abre em 0 na conta real. O comportamento com 0
está provado por teste; o valor em produção, não.

---

## (2) ⚠️ O CONFLITO DO `role="radiogroup"` — DECIDIDO, PARA O 180-10 NÃO DESCOBRIR DEPOIS

**Medido:** `tests/js/publicador-paginas-anuncios-render.test.js:354` tem

```js
assert.equal((html.match(/role="radiogroup"/g) ?? []).length, 1, 'esperado UM radiogroup de sub-abas');
```

e esse 1 é o radiogroup de `SubAbasDePublicacoes`. Um segundo radiogroup na página reprovaria aquele
gate, que **não é desta fase**.

➡️ **DECISÃO: `role="group"` + `aria-pressed` nos dois botões do toggle.** Não briga com gate
nenhum. ⚠️ **O plano 180-10 NÃO precisa reapontar aquela asserção** — a alternativa foi recusada.

Travado por: 4 sub-testes (0 radiogroup nos 3 renders **com props cheias**, 0 nas 3 fontes, 1
`aria-pressed="true"` e 1 `"false"`) e mutação **x** (`role="group"` → `role="radiogroup"`), que
reprovou em **7** asserções.

O trilho das abas usa `role="tablist"` + `role="tab"` + `aria-selected` (como o plano pediu) — e
`tablist` não tem contagem travada em gate nenhum da página, conferido por `grep` em `tests/js/`.

---

## (3) ⚠️ A ORDENAÇÃO: O PROTÓTIPO MENTE SOBRE O SISTEMA

| onde | o que faz | o que escreve |
|---|---|---|
| protótipo (`:529`, `:93`) | `sort((a,b) => b.d.localeCompare(a.d))` — por DATA | "Mais recentes primeiro" |
| sistema (`MlbAnuncioController::meus()`, `:701`-`:704`) | `orderByDesc('severidade')` → `nota_ecf IS NULL ASC` → `nota_ecf` → `ml_item_id` — por **GRAVIDADE** (D-12) | — (não manda rótulo) |

A própria tela de hoje chama isso de **"ordenação fixa por gravidade"** (`MeusAnuncios.jsx:21` e
`:526`).

➡️ **DECISÃO: `ordenacaoRotulo` é prop (texto de fora, nunca literal cravado) e o DEFAULT é
"Mais graves primeiro".** O vocabulário é o da própria tela ("gravidade"), não inventado. Quando o
servidor passar a mandar o rótulo, ele ganha — há teste provando que a prop vence o default.

⚠️ **O literal "Mais recentes primeiro" está proibido na fonte por asserção**, e a mutação **vii**
(trocar o default por ele) reprovou em **4** asserções. O critério 13 fica preservado: a ordenação
do servidor **não mudou**; o que mudou é o rótulo passar a descrevê-la.

---

## (4) A tabela FID-01, conferida ITEM A ITEM

Medido com `grep -n` e leitura de `new_layout_publicador/Publicacoes v2.dc.html`, confirmando cada
linha pela **semântica**.

> ⚠️ **PLACAR: 27 medidas conferidas, 5 divergências — todas de OMISSÃO da tabela, todas a favor do
> protótipo.** O histórico era ~102 a 0 contra a tabela; nos planos 180-01/180-05 ela quase não
> perdeu (10/10 e 23/24). Aqui ela volta a errar por **omissão**, não por valor: nenhum número que
> ela trouxe estava errado.

### 4a. A busca central

| onde (semântica) | linha MEDIDA | medida do protótipo | bate com a tabela? |
|---|---|---|---|
| wrapper | **:68** | `position:relative;display:flex;align-items:center` | sim |
| lupa | **:69** | `18×18 viewBox "0 0 16 16"`, `position:absolute;left:18px`, **`fill:none`**, `stroke:#A1A1AA`, `stroke-width:1.6`, **`stroke-linecap:round`**, `pointer-events:none` | ⚠️ **omitiu `fill:none` e `stroke-linecap:round`** |
| `<input>` | **:70** | `width:100%;height:54px;padding:0 220px 0 50px;border:1px solid #2E2E2E;border-radius:14px;background:#111111;`**`font-family:inherit`**`;font-size:15.5px;color:#F4F4F5;outline:none;transition:border-color .15s, box-shadow .15s` | ⚠️ **omitiu `font-family:inherit`** (ver 4d) |
| foco | **:70** | `border-color:#FACC15;box-shadow:0 0 0 3px rgba(250,204,21,0.18)` | sim |
| placeholder | **:70** | `Buscar por produto, título, SKU ou MLB…` (um `…`, U+2026) | sim, byte a byte |
| bloco da direita | **:71** | `position:absolute;right:12px;display:flex;align-items:center;gap:10px` | sim |
| resumo | **:73** | `font-size:12.5px;color:#A1A1AA` | sim |
| "Limpar" | **:74** | `height:28px;padding:0 10px;1px #262626;raio 7px;#0D0D0D;12px;#A1A1AA;`**`cursor:pointer`** | ⚠️ **omitiu `cursor:pointer`** (4ª vez na fase) |
| dica | **:77** | `display:flex;align-items:center;gap:6px;font-size:12px;color:#8A8A90` + "Pressione" … "para buscar" | sim |
| tecla "/" | **:77** | `'Geist Mono',monospace;11.5px;2px 7px;1px #2E2E2E;border-bottom-width:2px;raio 5px;#A1A1AA;#0D0D0D` | sim |
| frase do resumo | **:626** | `N publicação\|publicações · M produto\|produtos` | sim |

### 4b. Trilho de status, linha de controles e toggle

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| coluna externa | **:82** | `display:flex;flex-direction:column;gap:12px` | sim — **mas NÃO é deste plano** (ver 4e) |
| linha de controles | **:82** | `display:flex;align-items:center;justify-content:space-between;gap:16px` | sim |
| trilho | **:83** | `display:flex;align-items:center;gap:2px;padding:3px;background:#161616;border-radius:10px` | sim |
| botão de aba | **:85** | `display:flex;align-items:center;gap:7px;height:30px;padding:0 12px;border:none;border-radius:7px;font-size:13px;font-weight:500;`**`cursor:pointer`** | ⚠️ **omitiu `cursor:pointer`** |
| aba ativa / inativa | **:532** | `#262626` + `inset 0 0 0 1px #333333` + `#F4F4F5` × `transparent` + `none` + `#A1A1AA` | sim |
| dot | **:86** + **:532** | `6×6;border-radius:50%;background:#EF4444`, e `hasDot: k === 'error' && counts.error > 0` | sim |
| contagem | **:88** | `font-size:12px;color:#8A8A90;font-variant-numeric:tabular-nums` | sim |
| **bloco da direita** | **:90** | `display:flex;align-items:center;gap:10px` | ⚠️ **a tabela NÃO TROUXE este bloco** |
| rótulo da ordenação | **:93** | `font-size:12.5px;color:#8A8A90` | sim |
| trilho do toggle | **:92** | **idêntico** ao `:83` | sim |
| opção de visão | **:96** | `height:30px;padding:0 12px;border:none;border-radius:7px;font-size:13px;font-weight:500;cursor:pointer` — ⚠️ **SEM `display:flex`, SEM `gap`** | ⚠️ **a tabela disse "igual à aba"** — ver 4c |
| rótulos do toggle | **:536** | `[['group','Por produto'],['list','Lista']]`, `group` é o default | sim |

### 4c. ⚠️ A divergência que importa: `display:flex` na opção de visão

A tabela FID-01 diz *"opção de visão | igual à aba, **sem** o `gap-[7px]`"*. Medido: o protótipo
declara `display:flex;align-items:center` no botão de **ABA** (`:85`) e **não** no de **visão**
(`:96`) — ali o `<button>` centraliza o próprio texto por conta. Obedecer a tabela acrescentaria
duas classes que o protótipo não tem.

➡️ **O protótipo ganha.** A opção de visão é `h-[30px] cursor-pointer rounded-pub-control-sm
px-[12px] text-[13px] font-medium` + estado, e há **asserção negativa explícita** travando a
ausência de `flex` e de `gap-[7px]` nos dois sentidos.

### 4d. O que foi medido e NÃO virou classe, com o motivo escrito

- **`font-family:inherit` do `<input>` (`:70`)** — o *preflight* do Tailwind já emite
  `input { font-family: inherit }`. Classe seria redundante; a medida está no cabeçalho do arquivo
  para ninguém achar que foi esquecida.
- **`font-weight:600` quando ativa** — ⚠️ o briefing atribuiu isso ao toggle, e **não é dele**. A
  varredura mostra `font-weight:{{ o.weight }}` só nos **chips** (`seg()`, `:535`, com
  `weight: 600 : 400` e fundo `rgba(250,204,21,0.16)`), que são do plano **180-07**. O toggle é
  `font-weight:500` fixo (`:96`), nas duas opções — travado por asserção (`font-medium` aparece
  exatamente **8** vezes: 6 abas + 2 opções).
- **`rgba(250,204,21,0.12)` + borda `rgba(250,204,21,0.55)` nos chips** — idem: é do 180-07. O
  protótipo mede `rgba(250,204,21,0.16)` e **sem borda** no `seg()` (`:535`); quem desenhar os chips
  confira isso lá, porque o briefing trouxe `0.12` e uma borda que não achei no `seg()`.

### 4e. O que fica REGISTRADO para o plano 180-10

A **coluna externa de `gap:12px`** (`:82`) envolve a linha de controles **e** a linha de chips (que é
do 180-07). Compor a página é do 180-10, então ela não mora em nenhum dos meus arquivos. A medida
completa, para ele:

```jsx
<div className="flex flex-col gap-[12px]">
    <LinhaDeControles … />
    {/* a linha de chips do plano 180-07 */}
</div>
```

---

## (5) O que o 180-10 e o 180-11 ligam

```jsx
<BuscaDePublicacoes
    valor={busca}
    onMudar={(e) => setBusca(e.target.value)}
    onLimpar={() => navegar({ busca: '', page: 1 })}
    onTecla={aoTeclarNaBusca}        // ↓/Enter — plano 180-11
    refDoInput={refDaBusca}          // atalho "/" — plano 180-11
    resumo={null}                    // ⚠️ ver (5a)
/>

<LinhaDeControles
    abas={<AbasDeStatus
        abas={abas}                  // a prop do servidor, SEM transformação
        ativa={filtros.status}
        contagens={contagens}        // ⚠️ obrigatória para o caso `acionaveis`/`encerrados`
        onTrocar={(chave) => chave === 'rascunhos'
            ? trocarSubAba('rascunhos')   // LIST-25: a query de hoje, D-14
            : navegar({ status: chave, sub: 'publicados', page: 1 })}
    />}
    visao={filtros.visao}
    onTrocarVisao={(v) => navegar({ visao: v, page: 1 })}
    ordenacaoRotulo={null}           // sem rótulo do servidor → "Mais graves primeiro"
/>
```

### 5a. ⚠️ `resumo` da busca: a fonte NÃO existe hoje — e isto é MEDIÇÃO

O controller **não** manda `{publicacoes, produtos}` para a busca. O que existe é
`anuncios.total` (do paginator) e `triagem.total`. ⚠️ **LIST-22 diz que o total dos chips vem de
`triagem.total` e NUNCA da soma dos chips** — e o mesmo cuidado vale aqui: `anuncios.total` é o
total do recorte ATUAL, que é o número certo para "N publicações", mas **"N produtos" não tem
fonte** (os `grupos` são só os da página de 50).

➡️ **Enquanto o 180-10 não tiver os dois, passe `resumo={null}`:** com busca ativa aparece o
"Limpar" sozinho, que é honesto e está testado. ⚠️ **Nunca contar no cliente** (D-05) e **nunca
mandar só um dos dois achando que o componente preenche o outro** — ele desenha só o pedaço medido,
sem separador sobrando (testado), mas uma frase "2 publicações" sem os produtos é meia-verdade que
merece decisão de produto, não de código.

### 5b. ⚠️ O que o 180-10 **NÃO** deve fazer

- **Não** reapontar `publicador-paginas-anuncios-render.test.js:354` — a decisão do `role="group"`
  evitou o conflito.
- **Não** apagar `SubAbasDePublicacoes.jsx` ao remover o mount: `AnunciosHistorico.jsx` o usa, e o
  conteúdo do Histórico é insumo da **Fase 183**.
- **Não** passar `abas` transformado: a ORDEM é do servidor e o componente não reordena (testado por
  `montarOpcoes`).

---

## (6) ⚠️ Os nomes para `ARQUIVOS_NOVOS` — e a contagem ELEVADA

A contagem de `publicacoes/` no gate de vocabulário subiu de **2 → 5**, com os 3 nomes declarados:

```
resources/js/Components/Mlb/Publicador/publicacoes/BuscaDePublicacoes.jsx
resources/js/Components/Mlb/Publicador/publicacoes/AbasDeStatus.jsx
resources/js/Components/Mlb/Publicador/publicacoes/LinhaDeControles.jsx
```

➡️ **O plano 180-07 em diante tem de ELEVAR de 5** junto com os arquivos dele. Deixar o número
parado é o que denuncia o esquecimento — e arquivo fora da lista fica **sem** conferência contra
`text-sm`, `ecf-*`, `font-display`, hex literal e breakpoint.

O `15.5` **já estava** em `TAMANHOS_PERMITIDOS` (plano 180-01) — **conferido, não presumido**: a
lista tem `15.5` e o `<input>` deste plano é o **primeiro uso** de `text-[15.5px]` no código, como o
comentário daquele plano previu.

### O par `+assert` / `-assert`

| arquivo | linhas | `+assert` | `-assert` | leitura |
|---|---|---|---|---|
| `tests/js/publicador-vocabulario-novo.test.js` | +21 / **-11** | **0** | **0** | as 11 linhas removidas são **comentário** (9) e **reaponte de valor** (2: o `2,` e a mensagem). **Nenhuma linha removida contém a palavra `assert`** — conferido por `grep -c`. O nome do teste virou "os 5 arquivos", o valor virou `5`, e os 18 sub-testes novos (3 arquivos × 6) passaram a rodar |
| `tests/js/publicador-publicacoes-busca.test.js` | +1445 / **-0** (novo) | — | — | — |
| os 3 componentes | +845 / **-0** | — | — | — |

É o caso que o learnings chama de *"gate vazio ao contrário"*: um `grep '^-' | grep assert`
acusaria remoção onde não houve.

---

## (7) "O que precisaria estar errado no código para este gate falhar?" — por teste

| teste (sub-testes) | falha se… |
|---|---|
| `os literais da busca estão byte a byte` (5) | alguém parafrasear o placeholder, **trocar o `…` por três pontos** (asserção sobre o **code point** U+2026, não sobre a aparência), mudar "Pressione"/"para buscar"/"Limpar", errar o plural, ou deixar os dois estados da direita aparecerem juntos |
| `os literais do trilho e do toggle` (6) | parafrasear um dos 6 rótulos, trocar a ORDEM, perder o contador de Rascunhos, deixar `under_review`/`active`/`gold_pro` ou uma chave crua vazar para a tela, inverter "Por produto"/"Lista", ou **cravar "Mais recentes primeiro"** (que mente) |
| `a geometria da busca` (7) | qualquer medida trocada (54→52, raio 14→10, 15.5→`text-base`), **qualquer classe A MAIS** (igualdade de conjunto), o `viewBox` virar o do Lucide, ou o `stroke-width` sair de 1.6 |
| `a geometria do trilho, do dot e da contagem` (5) | idem, nas 6 abas (**uma asserção por irmão**); a divisória de Rascunhos sumir, duplicar, ou nascer **depois** da opção |
| `a geometria da linha e do toggle` (6) | idem; mais a opção de visão **ganhar** `flex`/`gap-[7px]` (que o protótipo não declara) ou o peso virar 600 |
| `a aba ativa é a certa` (6) | `bg-pub-seg` na aba **ERRADA** — rodado para cada um dos 6 valores de `ativa`, com asserção na tag de **cada** uma das 6, e com a trava de que há exatamente **1** `bg-pub-seg` e **1** `aria-selected="true"`. "Existe um `bg-pub-seg` no HTML" passaria com a marca em qualquer irmã |
| `o dot sai de comDot` (4) | recalcular o dot de `total > 0` (`total: 5, comDot: false`), ler `com_dot` em snake_case, o dot vazar para outra aba, ou a contagem de dots sair de 1 |
| `total nulo não vira número` (3) | `null` virar "0" ou "—" (a asserção compara o **bloco inteiro** do botão, não procura `/0/`), `0` deixar de aparecer, ou string/`NaN`/`Infinity`/fracionário/negativo virarem contagem |
| `acionaveis/encerrados ganham opção À FRENTE` (6) | o trilho ficar sem opção marcada no DEFAULT da tela; o rótulo deixar de ser o de hoje; o número não vir de `contagens`; inventar número sem `contagens`; inventar opção para status **desconhecido**; ou duplicar quando a aba já existe |
| `ZERO radiogroup novo` (4) | o toggle nascer como 2º `role="radiogroup"` e reprovar o gate da página — com `=` no regex, porque `/radiogroup/` casaria num `aria-label` |
| `botão sem destino não nasce` (6) | desenhar "Limpar" sem handler (ou desabilitado — `/disabled=/` **com o `=`**), desenhar trilho vazio como moldura, desenhar aba sem rótulo legível, ou duplicar chave repetida |
| `render adverso` (11) | qualquer prop ou campo chegando objeto/`NaN`/nulo/ausente e sendo renderizado como texto — a tela preta de 07/10/2026. Inclui `ref` de **string** (removido no React 18, **LANÇA**) e a prop inteira nula |
| `D-11 / REND-04` (15) | `router.*`/`axios`/`fetch(`/import do Inertia nascer num dos 3; `new Date()`/`Date.now()`/`toLocale`; `addEventListener` (o atalho é do 180-11); `String(valor)`/`JSON.stringify`; hex literal; **JSX inline no `.map()`**; mais de um `<button` por arquivo; ou a classe do trilho ser copiada em vez de importada |
| `as classes GERAM CSS com o valor certo` (8) | classe presente com VALOR errado: o token `pub-hover-2` mudar de cinza, o raio 14 do `pub-table` mudar, **o alfa do ring sair de 0,18**, ou o `inset 0 0 0 1px #333333` do segmentado mudar |

### E o que NÃO tem gate — declarado, não maquiado

- **A fonte do `resumo` da busca.** Ver (5a): `publicacoes` tem candidato (`anuncios.total`),
  `produtos` **não tem**. O componente devolve `null` e nada aparece. Não há teste que diga "o
  servidor deveria mandar isto".
- **A eliminação de variável pelo Rollup não é reproduzível no `renderToStaticMarkup`.** Ver (9),
  mutação **vi**: o que existe é o gate **estrutural** sobre a forma do `.map()`. A lacuna está
  escrita, não presumida equivalente.
- **Nenhuma tela renderizada foi vista.** Não abri navegador, não deployei, não toquei no VPS e
  **não li produção** (o `ml_acervo_itens` local está vazio). Tudo aqui é render de servidor + CSS
  construído.

---

## (8) As provas de CSS construído — e a OITAVA mentira do medidor

Build final: `assets/app-D3uKapGc.css`, **220.454 bytes** (era 220.446 no build anterior a este
plano: **+8**, consistente com o pouco que nasceu — quase toda classe que usei já tinha dono).

| classe | regra MEDIDA no CSS | armadilha que ela demonstra |
|---|---|---|
| `h-[54px]` | `height:54px` | (v) seletor **escapado** (`.h-\[54px\]`) |
| `rounded-pub-table` | `border-radius:14px` | — |
| `text-[15.5px]` | `font-size:15.5px` | (vii) escapar só a barra leria `[15.5px]` como classe de caracteres |
| `pl-[50px]` / `pr-[220px]` | `padding-left:50px` / `padding-right:220px` | — |
| `focus:border-pub-accent` | `border-color:`**`rgb(250 204 21 / var(--tw-border-opacity, 1))`** | (i) token sólido **não** sai em hex |
| **`focus:ring-pub-accent/[0.18]`** | `--tw-ring-color:`**`rgb(250 204 21 / .18)`** | ⚠️ **(iii)+(iv) numa forma NOVA — ver abaixo** |
| `focus:ring-[3px]` | `0 0 0 calc(3px + var(--tw-ring-offset-width))` | (vi) na variante o **prefixo faz parte do nome** |
| `stroke-pub-neutral-txt` | `stroke:`**`#a1a1aa`** | (ii) `stroke`/`fill` sólidos SAEM em hex literal |
| `bg-pub-hover-2` | `rgb(22 22 22 / …)` | (i) |
| `shadow-pub-seg-ativo` | `--tw-shadow: inset 0 0 0 1px #333333` | — |
| `bg-pub-seg` / `bg-pub-danger` | `rgb(38 38 38 …)` / `rgb(239 68 68 …)` | (i) |
| `border-b-2` | `border-bottom-width:2px` | — |
| `font-pub-mono` | `font-family:Geist Mono,…` | (iv) `font-pub` casa DENTRO de `font-pub-mono` numa busca de substring |
| `text-[12px]` × `text-[12.5px]` | `font-size:12px` × `font-size:12.5px` | (iv) `text-[12px]` casa dentro de `text-[12.5px]` — aqui cada regra é lida e comparada |
| **ISCA** `bg-pub-isca-6k2` | **AUSENTE**, e a string não aparece no CSS | — |
| **ISCA** `text-[97.5px]` | **AUSENTE**, idem | — |

As duas iscas foram medidas como **inexistentes no repo inteiro** (`grep -rn` em `resources/`,
`app/`, `tests/`, `tailwind.config.js` → 0 ocorrências) **antes** de serem usadas.

### ⚠️ A OITAVA mentira: o ring NÃO sai em hex de 8 dígitos

O plano prescrevia: *"para o ring, **leia o hex de 8 dígitos e confira o byte do alfa**"*.
**Medido: não existe hex de 8 dígitos aqui.** O Tailwind emite

```
--tw-ring-color: rgb(250 204 21 / .18)
```

— **decimal**, e com o zero da frente **comido pelo cssnano** (armadilha iv, num lugar novo). A
prescrição vinha da experiência do plano 180-05, em que `border-pub-accent/[0.35]` **saiu**
`#facc1559`. ➡️ **A forma do modificador de opacidade depende da PROPRIEDADE:** `border-color`
compila para hex-8, `--tw-ring-color` compila para `rgb(… / .18)`. O gate lê a forma **real** e
recusa explicitamente `.2` e `.15` — que é o que distingue 0,18 de 0,2.

### ⚠️ E a limitação ESTRUTURAL da sonda, reconfirmada

As mutações **i** (`h-[54px]` → `h-[52px]`) e **ii** (`/[0.18]` → `/[0.2]`) foram pegas pelo gate de
**HTML** (igualdade de conjunto), **não** pelo de CSS — exatamente como no plano 180-05, e por dois
motivos independentes:

1. **o detector de build velho DISPARA:** mutar o `.jsx` deixa o arquivo mais novo que o CSS, e o
   bloco entra em `skip` com a razão escrita (o `pass` cai de 391 para 382 — foi medido ao vivo na
   campanha, e é a prova de que o detector não é decorativo);
2. mesmo com rebuild, `h-[52px]` e `/[0.2]` poderiam ter **outro dono** no repositório.

➡️ **Os dois gates são necessários e não se substituem.** Quem protege a fidelidade DESTES arquivos
é a igualdade de conjunto de classes no HTML; o de CSS protege a camada de tokens e a matemática do
Tailwind.

---

## (9) As 10 mutações — 10/10 PEGAS, 0 sobreviventes

Contagem do alvo **travada em 1** antes de aplicar (recusa se ≠1), **qual linha mudou** conferida
depois, teste designado rodado, restauração por cópia pristina conferida por `sha256`.
⚠️ **ANSI removido da saída ANTES de filtrar o veredicto** — é a armadilha que fez a campanha
inteira do 180-03 parecer verde sem provar nada.

Base da campanha: **391 pass / 0 fail** nos dois gates.

| # | mutação | arquivo:linha | reprovou em | asserções |
|---|---|---|---|---|
| i | `h-[54px]` → `h-[52px]` | `BuscaDePublicacoes.jsx:186` | `input :70` (igualdade de conjunto) | 2 |
| ii | `ring-pub-accent/[0.18]` → `/[0.2]` | `BuscaDePublicacoes.jsx:186` | `input :70` | 2 |
| iii | `…` → `...` no placeholder | `BuscaDePublicacoes.jsx:61` | `o placeholder … com o \`…\` de UM caractere` | 2 |
| iv | `item.ativa === true` → `!== true` (marca a aba errada) | `AbasDeStatus.jsx:196` | `a aba ativa é a certa` (os 6) + geometria + `total` nulo + fora do trilho | **15** |
| v | `comDot` recalculado de `Number(total) > 0` | `AbasDeStatus.jsx:148` | `o dot sai de comDot` + contagem + render adverso | **9** |
| vi | `.map((item) => opcao(item))` → `opcao(item, ativa)` | `AbasDeStatus.jsx:248` | `REND-04 — o .map() chama helper` | 2 |
| vii | `ORDENACAO_PADRAO` → `'Mais recentes primeiro'` | `LinhaDeControles.jsx:98` | `o rótulo da ordenação diz a VERDADE` + `rótulo :93` | 4 |
| viii | tirar `acionaveis` de `ROTULOS_FORA_DO_TRILHO` | `AbasDeStatus.jsx:82` | `acionaveis/encerrados ganham opção À FRENTE` (todos) | **6** |
| ix | tirar `cursor-pointer` do botão de aba | `AbasDeStatus.jsx:210` | `os 6 botões :85` + `total` nulo (bloco literal) | 4 |
| x | `role="group"` → `role="radiogroup"` | `LinhaDeControles.jsx:167` | `ZERO radiogroup novo` + geometria do toggle | **7** |

**0 recusadas, 0 sobreviventes.** `sha256` final idêntico ao pristino nos três arquivos:
`BuscaDePublicacoes.jsx` `c2267f4c4558…`, `AbasDeStatus.jsx` `9d134cf13cf6…`,
`LinhaDeControles.jsx` `2083034249b1…`.

### ⚠️ A mutação vi e a LACUNA, escrita em vez de presumida

O plano avisava que mover a flag para fora do callback **não reprova** em
`renderToStaticMarkup`, e isso está certo: o defeito do Rollup só aparece no bundle de produção.
**Não montei um teste que importa o bundle do Vite e renderiza** — fica como lacuna declarada.

O que existe no lugar, e que a mutação vi **provou não ser decorativo**:
1. o gate **estrutural** sobre a forma literal do `.map()` (JSX inline reprova);
2. a forma do código: `opcao()`/`opcaoDeVisao()` são **declarações de função dentro do componente**,
   que é a forma de `AbasDoHeader.pilula()` e `AbasDaConta.botaoAba()` — as duas que este projeto já
   adotou **depois** do `ReferenceError` em tela;
3. a trava de **um** `<button` por arquivo, que impede os 6 blocos copiados.

### ⚠️ Nota honesta sobre o relatório da mutação viii

A mutação viii **apaga uma linha**, e o comparador linha-a-linha do script reportou "linhas
alteradas = 82..252" — é **deslocamento**, não 170 edições. O que vale é o alvo, cuja **contagem
estava travada em 1** e cuja posição foi medida em `:82` antes de aplicar.

---

## (10) Verificação

| gate | resultado |
|---|---|
| `node --test` no gate novo | **114 testes / 114 pass / 0 fail / 0 skipped** |
| `node --test` no vocabulário | **277 / 277 pass / 0 fail**. O **+18** (3 arquivos × 6 sub-testes do laço por arquivo) é INFERIDO do delta da suíte cheia (+132 = 114 + 18), não medido antes no arquivo isolado |
| `npm run test:js` **ANTES** (medido antes de tocar em nada) | **3828 / 3827 pass / 1 fail / 0 skipped** — bate com o briefing |
| `npm run test:js` **DEPOIS** | **3960 / 3959 pass / 1 fail / 0 skipped** — delta **+132 testes, +132 pass** (114 do gate novo + 18 do vocabulário) |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido") — **NÃO corrigida**, é conhecida e intencional |
| `npm run build` | exit **0**, 37,4 s (rodado DEPOIS da campanha de mutação, para o bloco de CSS não ficar em `skip`) |
| manifest **pelo JSON** (`--input-type=commonjs`) | **478 entradas** (idênticas às 478 do plano 180-05), `resources/js/app.jsx` → `assets/app-hm__hrOI.js`, css `assets/app-D3uKapGc.css`; **195 páginas** `.jsx`; 11 entradas casando `/publicador/i`; `MeusAnuncios.jsx` e `AnunciosHistorico.jsx` **presentes** — nenhuma página perdida |
| `test -e` nos **23** alvos de território | **23/23 existem** — zero caminho fantasma. Usei os caminhos CERTOS registrados pelos planos anteriores: `Components/Publicador/Mesa` (**sem** o `Mlb`), `Pages/Mlb/AnunciosHistorico.jsx` (**fora** de `Publicador/`), `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `.planning/ROADMAP.md`. ⚠️ **E corrigi dois do briefing**: `AcervoTriagemService` e `PublicacoesDaListaService` vivem em **`app/Services/Publicador/`**, não em `app/Services/Mlb/` |
| território RANGE A (`f23f610d..HEAD`) — o que ninguém da fase toca | **0 arquivos** em `identidade/`, `header/`, `visaogeral/`, `Components/Publicador/Mesa`, `Editor.jsx`, `AnunciosHistorico.jsx`, `anuncioHistoricoUtils.js`, `AppLayout.jsx`, `app/Support/Publicador`, `MlPublicacaoService.php`, **`SubAbasDePublicacoes.jsx`**, `routes/mlb_anuncios.php`, `HistoricoAnunciosTest.php`, `estrutura-anuncios-historico.test.js`, **`estrutura-meus-anuncios.test.js`**, **`publicador-paginas-anuncios-render.test.js`** |
| território RANGE B (`68ea21d8..HEAD`) — o que OUTRO plano possui | **0** em `MeusAnuncios.jsx`, `MlbAnuncioController.php`, `app/Services/Publicador/` (os dois serviços), `tailwind.config.js`, `REQUIREMENTS-v26.md`, `CabecalhoDePublicacoes.jsx`, `FaixaDeSucesso.jsx` |
| **CONTROLE** do gate de território | o MESMO comando vê `251/0`, `221/0`, `173/0`, `1445/0` e `21/11` nos meus 5 arquivos — logo não é vazio por construção |
| PHP / migrations / pacotes | `git diff --numstat 68ea21d8..HEAD -- app/ database/ routes/ composer.json package.json` → **vazio**. A suíte PHP de ~7 min **não foi rodada, por desnecessária** (diff vazio é a prova, não o veredicto do runner) |
| árvore | limpa nos meus alvos (`git status --short -- resources/js tests/js app/` → vazio) |

⚠️ **Gate de território: RANGE B acusou `.planning/ROADMAP.md` (+21/-2) e NÃO fui eu** — é o commit
`933b8702` (`docs(184): plano da fase em 13 planos e 8 ondas`), da **sessão paralela da Fase 184**,
que também commitou `83702806` e `e79bf0b5` **entre** os meus três. É o **quarto** plano seguido em
que o gate por range acusa trabalho legítimo de outra pessoa; o learnings já avisa que `ROADMAP.md`,
`REQUIREMENTS-v26.md` e `STATE.md` não pertencem a plano nenhum. **Conferi o autor e o hash antes de
acusar**, como ele manda.

Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma migration, nenhum pacote, nenhuma
leitura de produção.

---

## Self-Check: PASSED

| o que | resultado |
|---|---|
| os 5 arquivos declarados existem no disco (`test -f`) | **5/5 FOUND** |
| os 4 commits existem (`git log --oneline --all \| grep`) | **4/4 FOUND** |
| delecoes nos 4 commits (`git diff --diff-filter=D HEAD~1 HEAD`) | **0 em todos** |
| arquivo gerado/nao rastreado deixado para tras (`git status --short` nos meus diretorios) | **nenhum** |
