---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 07
subsystem: publicador / fila de aprovação
tags: [tabela, grid-11-colunas, tres-niveis, galeria-de-fotos, mini-quadrados, pub-sunken-2]
requires:
  - 186 (BloqueiosDaOfertaService::contagemDeFotos, PubAprovacaoConjunto/Imagem/ImagemAlerta)
  - 187-01 (os 5 tokens da fila e os 2 tamanhos liberados — aqui acorda o `pub-sunken-2`)
  - 187-02 (FilaDeAprovacaoService::paraAConta/ordenar/faixa, a rota)
  - 187-04 (a página e os 4 slots nomeados — aqui o `tabela` é preenchido)
  - 187-06 (FiltrosDaFilaService::aplicar/panoramaDaConta)
  - 177 (identidade/TileDoProduto.jsx — `iniciais()` e `tomDoProduto()`, IDENT-08)
provides:
  - GaleriaDaOfertaService (a galeria final ORDENADA, provada contra o bloqueio)
  - GrupoDaFilaService (a árvore de 3 níveis + `recortarPelaEtapa`, o TERCEIRO recorte)
  - TabelaDaFila.jsx com o GRID DE 11 COLUNAS exportado (o 187-08 importa, não recopia)
  - LinhaDeProdutoDaFila.jsx, SubLinhaDeConjunto.jsx, QuadradosDeFoto.jsx
  - a prop `grupos` e o slot `linhaDaOferta` nomeado e VAZIO (para o 187-08)
affects:
  - MlbPublicadorAprovacaoController (a prop `grupos` e a `lista` recortada pela etapa)
  - Pages/Mlb/Publicador/Aprovacao.jsx (slot `tabela` preenchido; par nó × dado)
  - tests/js/publicador-vocabulario-novo.test.js (ARQUIVOS_NOVOS +4)
tech-stack:
  added: []
  patterns:
    - duas leituras da mesma verdade + gate de COMPARAÇÃO, quando a fonte é um método privado intocável
    - slot de função (`linhaDaOferta`) declarado na wave que cria a casca, porque a wave seguinte não pode editá-la
    - grid de tabela como CONSTANTE EXPORTADA, para cabeçalho e linha não divergirem
    - contagem de asserções independente do estado do repositório (sem `return` antecipado em gate de território)
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php
    - app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php
    - resources/js/Components/Mlb/Publicador/aprovacao/TabelaDaFila.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/LinhaDeProdutoDaFila.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/SubLinhaDeConjunto.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/QuadradosDeFoto.jsx
    - tests/Unit/Publicador/Aprovacao/GrupoDaFilaServiceTest.php
    - tests/js/publicador-fila-tabela-187.test.js
    - tests/gates/contagens-187-07.txt
  modified:
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "A galeria é IDÊNTICA ao que o bloqueio conta, e o 4º estado do quadrado vive no componente: alargá-la exigiria mexer na Fase 186 e quebraria a verdade declarada do próprio plano"
  - "O rótulo da foto é `Capa`/`Foto N`, derivado de `posicao` — o `label` do protótipo é descrição humana que este sistema não guarda"
  - "`compartilhado` conta as ofertas DO RASCUNHO, não as filtradas: filtrar por Premium não desfaz o compartilhamento"
  - "A frase do cadastro só aparece quando é VERDADE: categoria + ficha com atributo + nenhum a revisar"
  - "`pub-float`/`pub-float-line` NÃO acordam aqui: medido no config, eles são da barra de lote e do toast (187-09)"
  - "O grid de 11 colunas é CONSTANTE EXPORTADA; o 187-08 importa em vez de recopiar"
  - "`recortarPelaEtapa` nasceu neste plano (Regra 3): o terceiro recorte não existia e o risco é a etapa `publicado`"
metrics:
  tarefas: 3
  commits: 3
  ids_de_gate: 9
  mutacoes: "7 aplicadas, 7 mortas, 0 sobreviventes"
  completed: 2026-10-16
---

# Fase 187 Plano 07: A tabela da fila, níveis 1 e 2 — Summary

A tabela nasceu com o **grid literal de 11 colunas** (constante exportada, idêntica no cabeçalho e
pronta para a linha do 187-08), a **linha de produto de 52px** e a **sub-linha de conjunto de 38px**
com os mini-quadrados — e a galeria que os alimenta é **provada idêntica** ao que o bloqueio de
fotos conta, em seis cenários, porque a definição dela mora num método privado e intocável.

---

## 1. As baselines, REMEDIDAS neste HEAD

⚠️⚠️ **As baselines da wave 6 saíram de validade no meio desta wave.** A outra máquina enviou **8
commits** (as quicks `261016-lu1`, `-mgv`, `-mwe`, `-imr` e a aba "Criadas" do Portal) e o
orquestrador integrou dois merges — a partida passou de `963e55d0` para **`07d87a56`**. Remedidas,
**não ajustadas**:

| suíte | wave 6 (`963e55d0`) | **MEDIDO em `07d87a56`** | depois deste plano |
|---|---|---|---|
| PHP (`tests/Unit/Publicador` + `tests/Feature/Publicador`) | 2414 / 21119 / 0 | **2414 / 21119 / 0** | **2430 / 21239 / 0** |
| JS (`tests/js/**`) | 5285 / 5280 pass / 1 fail / 4 skip | **5303 / 5298 pass / 1 fail / 4 skip** | **5354 / 5349 pass / 1 fail / 4 skip** |

⚠️ **Por que o PHP não mudou com os 8 commits da outra máquina:** o `--suite php` roda **apenas**
`tests/Unit/Publicador` e `tests/Feature/Publicador`, e as suítes novas dela
(`Quick261016Lu1/Mgv/Mwe`, `Unit/Quick261016Mwe`) estão **fora** desses dois diretórios. O JS subiu
18 (os dois `tests/js/quick-261016-*.test.js`).

⚠️ A única falha é a **PRÉ-EXISTENTE** `tests/js/estrutura-grade-glide.test.js`
(*"Características secundárias nasce recolhido"*, Fase 87) — **não corrigida**, e **não** existe
`REF_PHP_FALHAS`. O `+51` do JS é 27 (o gate novo) + 24 (o GATE-03, que faz 6 sub-testes por arquivo
novo × 4 arquivos).

**Harness:** `sh tests/gates/verificar-testes.sh --autoteste` → **20 casos, 0 divergência**.

---

## 2. ⚠️⚠️ A tabela FID-01, medida por SCRIPT — e o plano errava em 11 de 17

**Medida por SCRIPT**, não por leitura. O varredor (o mesmo do `187-BASELINES.md` §5, com `padding-right`,
`margin-left`, `overflow`, `text-align`, `position`, `flex` e `place-items` acrescentados) rodou
sobre os **dois recortes desta fase** do **`design_handoff_publicador/Aprovacao.dc.html`** — ⚠️ **cite
o DIRETÓRIO: há duas cópias do pacote com conteúdo DIFERENTE, e `new_layout_publicador/` é a versão
ANTIGA e ERRADA para esta fase.**

### Saída do script no recorte deste plano (`:108`-`:131`, 24 linhas)

| propriedade | valores distintos |
|---|---|
| `font-size` | `10px` `11px` `11.5px` `12px` `14px` |
| `font-weight` | `500` `600` `800` |
| `border-radius` | `3px` `4px` `7px` `8px` `14px` `999px` |
| `height` | `10px` `16px` `22px` `26px` `34px` `38px` `52px` |
| `width` | `10px` `16px` `34px` |
| `gap` | `3px` `6px` `8px` `10px` `12px` |
| `padding` | `0` `2px 6px` `0 8px` `0 10px` `0 16px` `0 16px 0 62px` |
| `padding-right` | `14px` |
| `margin-left` | `4px` `auto` |
| `background` | `#0D0D0D` `#0F0F0F` `#111111` `#151515` + 3 por binding |
| `border` | `1px solid #262626` `1px solid #2E2E2E` + 2 por binding |
| `border-color` | `#3F3F46` (só em `style-hover`) |
| `border-bottom` | `1px solid #1F1F1F` |
| `border-top` | `1px solid #1C1C1C` + `{{ g.border }}` |
| `color` | `#0A0A0A` `#3F3F46` `#71717A` `#8A8A90` `#A1A1AA` `#D4D4D8` + 2 por binding |
| **`grid-template-columns`** | **`32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px`** |
| `letter-spacing` | `0.06em` |
| **`cursor`** | **`pointer`** — ver a coluna própria |
| **`white-space`** | **`nowrap`** — ver a coluna própria |
| `overflow` | `hidden` |
| `text-align` | `right` |
| `flex` | `none` |
| `font-family` | `Geist Mono',monospace` |
| `align-items` / `place-items` | `center` / `center` |
| `min-height` · `line-height` · `z-index` · `transition` · `animation` · `text-overflow` · `font-variant-numeric` · `position` · `viewBox` | **(nenhum neste recorte)** — varredura vazia aqui é MEDIDA, não descuido |

### ⚠️⚠️ `cursor` — COLUNA PRÓPRIA (5 ocorrências nos dois recortes desta fase)

Neste projeto o `cursor:pointer` foi omitido **ONZE VEZES** numa única fase. As 5 dos meus recortes,
uma a uma, medidas por script:

| linha | elemento | de quem é |
|---|---|---|
| **`:110`** | o **checkbox do CABEÇALHO** (16×16) | **187-07** ✔ |
| **`:130`** | **"Revisar fotos do conjunto"** | **187-07** ✔ |
| `:133` | ⚠️ **A LINHA INTEIRA da tabela** — o único `cursor:pointer` do E1 que **não** está num `<button>`, e o mesmo elemento que carrega o grid de 11 colunas | 187-08 |
| `:134` | o checkbox da LINHA (16×16) | 187-08 |
| `:177` | o CTA por linha | 187-08 |

### ⚠️⚠️ `white-space` — COLUNA PRÓPRIA (12 ocorrências / 14 declarações)

| linha | elemento | de quem é | o plano prescrevia? |
|---|---|---|---|
| **`:129`** | o pill da aprovação compartilhada | **187-07** ✔ | sim ✔ |
| **`:130`** | "Revisar fotos do conjunto" — ⚠️ **é a PRIMEIRA propriedade da linha** | **187-07** ✔ | ⚠️ **NÃO — OMISSÃO** |
| `:139` | o título da oferta | 187-08 | sim (via `truncate`) |
| `:140` | a meta da oferta | 187-08 | sim (via `truncate`) |
| `:149` | o formato (`Unidade · 1 un.`) | 187-08 | sim |
| `:152` | `r.photos` | 187-08 | ⚠️ **NÃO — OMISSÃO** |
| `:154` | `r.photosSub` | 187-08 | ⚠️ **NÃO — OMISSÃO** |
| `:158` | `r.result` | 187-08 | ⚠️ **NÃO — OMISSÃO** |
| `:167` **×2** | `respShort` + `wait` | 187-08 | parcial |
| `:170` **×2** | a pill de andamento | 186 (somente leitura) | n/a |
| `:174` | a sub-linha do andamento | 187-08 | sim (via `truncate`) |
| `:177` | o CTA | 187-08 | sim |

`text-overflow:ellipsis`: **4** ocorrências, todas no 187-08 (`:139`, `:140`, `:167`, `:174`).

### ⚠️⚠️ Os 11 ponteiros DESLOCADOS do plano (medidos com `awk`/`sed`)

| elemento | plano | **MEDIDO** |
|---|---|---|
| `<section>` da tabela | `:104` | **`:108`** |
| cabeçalho (o grid) | `:105` | **`:109`** |
| checkbox do cabeçalho | `:106` | **`:110`** |
| Preço / Resultado (`text-align:right`) | `:107` | **`:111`** |
| borda entre grupos (`g.border`) | `:109` | **`:114`** |
| linha de produto (52px) | `:110` | **`:115`** |
| tile 34px | `:111` | **`:116`** |
| nome + tag PRODUTO | `:112` | **`:117`** |
| SKU | `:113` | **`:118`** |
| "N ofertas" | `:114` | **`:119`** |
| **o agrupamento (`order`/`byP`/`sets`)** | `:1266`-`:1273` | **`:1256`-`:1262`** |
| `sq()` | `:1116`-`:1122` | **`:1115`-`:1122`** (o ternário do `title` é `:1117`) |
| frase do cadastro | `:120` | `:120` ✓ |
| sub-linha … "Revisar fotos" | `:124`-`:130` | iguais ✓ |
| grid da LINHA da oferta | `:133` | `:133` ✓ |

Detalhando o agrupamento: `order`/`byP` → **`:1256`-`:1257`** · `setKeys.sort(units)` → **`:1258`**
(plano `:1269`) · `reduce` do "N ofertas" → **`:1259`** (plano `:1270`) · `border: gi ? …` →
**`:1260`** (plano `:1270`) · Clássico antes de Premium → **`:1261`** (plano `:1272`) ·
`shared: setOffers(sk).length > 1` → **`:1262`** (plano `:1272`).

### ⚠️⚠️ TRÊS omissões de ELEMENTO/VALOR na tabela do plano

1. **`white-space:nowrap` no botão de `:130`** — e é a **PRIMEIRA** propriedade da linha. Sem ela,
   "Revisar fotos do conjunto" quebraria em duas linhas dentro de um botão de 26px fixos.
2. **O WRAPPER do nome (`:117`)** — o plano prescreve o nome como `text-[14px] font-semibold` e
   esquece que `:117` é um `<span>` `display:flex;align-items:center;gap:8px` que embrulha o nome
   **e** a tag PRODUTO. Sem ele a tag cairia no `gap:12px` da linha, não no `gap:8px` dela.
3. **`font-extrabold` prescrito para o checkbox do cabeçalho** — e o **GATE-03 PROÍBE** a escala
   nomeada em todo arquivo de `ARQUIVOS_NOVOS`. **Medido: reprovou na 1ª redação.** A casa escreve
   **`font-[800]`** (`CabecalhoDaFila.jsx:186`, wave 4; `PopoverDeFiltro.jsx:181`, wave 6).

### ⚠️ E são **8** rótulos no cabeçalho, não 9

Medido em `:111`: `<button>` + `<span></span>` + **8** rótulos (`Oferta`, `Modalidade`, `Fotos`,
`Preço`, `Resultado/venda`, `Margem`, `Responsável`, `Andamento`) + `<span></span>` = **11 células**.
O plano escreveu *"os 9 rótulos"*. O teste foi corrigido para 8, e ele lê os rótulos **do módulo** e
os confere **contra `:111`**.

---

## 3. ⚠️⚠️ O GRID DE 11 COLUNAS — o literal exato, provado idêntico

```
grid-cols-[32px_24px_minmax(170px,1fr)_104px_118px_92px_108px_76px_112px_164px_96px]
```

É uma **constante exportada** (`TabelaDaFila.jsx:GRID_DAS_11_COLUNAS`), e isso é decisão: o plano
187-08 monta a linha da oferta com a **MESMA string importada**, em vez de recopiá-la. Duas cópias
ficariam livres para divergir numa correção feita de um lado só, e o sintoma seria o cabeçalho
desalinhado das linhas — visível, mas ninguém saberia qual dos dois está certo.

**Como ela é provada (não "está escrita"):**

1. As trilhas são **LIDAS do protótipo** (`grid-template-columns:` por regex) e a varredura é
   exigida **não-vazia** — isca invertida: se a leitura viesse vazia, tudo abaixo compararia a minha
   string consigo mesma.
2. ⚠️ **Ela aparece DUAS vezes no protótipo** (`:109` cabeçalho e `:133` linha), e o teste cobra
   exatamente **2**.
3. A constante é conferida por **TRANSFORMAÇÃO** (`trilha.replace(/ /g, '_')`), não por eu ter
   digitado as duas iguais.
4. No cabeçalho renderizado, por **IGUALDADE DE CONJUNTO de classes** — ⚠️ **nunca `includes`**:
   `substring` casa com prefixo (`z-3` dentro de `z-30`, `text-[11px]` dentro de `text-[11.5px]`) e
   já mordeu este projeto quatro vezes, inclusive pelo FIM do nome de outra classe.
5. **Isca do quase-igual**: a variante com `108px` na 4ª trilha é exigida **ausente**.
6. No **CSS CONSTRUÍDO**, com o valor compilado conferido trilha por trilha — ⚠️ e é aqui que a
   **8ª normalização** importa: o grid tem `(`, `,` e `)`, e com o escape parcial das waves 1-3 ele
   sairia **AUSENTE estando presente**.

**Mutação `104px` → `108px`: MORREU.**

---

## 4. As alturas, o recuo e os quadrados

| peça | medida do protótipo | classe | prova |
|---|---|---|---|
| linha de produto | `:115` **52px** | `h-[52px]` | render + CSS construído |
| sub-linha de conjunto | `:124` **38px** | `h-[38px]` | render + CSS construído |
| cabeçalho | `:109` **38px** | `h-[38px]` | render + CSS construído |
| recuo da sub-linha | `:124` `padding:0 16px 0 **62px**` | `pl-[62px] pr-[16px]` | ⚠️ **assimétrico**: o teste exige `px-[16px]` **AUSENTE** |
| quadrado da sub-linha | `:127` **10px** | `h-[10px] w-[10px]` | mapa FECHADO e literal |
| quadrado da linha | `:153` **12px** | `h-[12px] w-[12px]` | mapa FECHADO e literal |

⚠️ O `pl-[62px]` não é número solto: **16 (padding) + 34 (tile) + 12 (gap) = 62**, que é o que alinha
a sub-linha embaixo do nome do produto.

⚠️ **Nenhuma classe montada por concatenação.** `h-[${n}px]` o scanner do Tailwind **não vê**: a
classe fica no HTML, todo teste de render passa e **o quadrado sai sem tamanho na tela**. Os dois
tamanhos são literais num mapa fechado, e há gate proibindo a forma interpolada nos quatro arquivos.

**Mutações `pl-[62px]`→`pl-[60px]` e quadrado `10px`→`12px`: MORRERAM.**

---

## 5. Os 4 estados do mini-quadrado, com o `title` de cada um

Literais de `sq()` (`:1118`-`:1121`), **na ordem do ternário** — e a ordem importa:

| # | estado | fundo | borda | `title` (literal de `:1117`) |
|---|---|---|---|---|
| 1 | `approved` | `bg-pub-ok` | `border-[1.5px] border-pub-ok` | `<rótulo> · aprovada` |
| 2 | `regenerating` | `bg-transparent` | `border-[1.5px] border-dashed border-pub-txt-4` | `<rótulo> · a conferir` ⚠️ **D7** |
| 3 | com alerta | `bg-pub-danger/[0.3]` | `border-[1.5px] border-pub-danger` | `<rótulo> · com alerta` |
| 4 | o resto | `bg-transparent` | `border-[1.5px] border-pub-warn-txt` | `<rótulo> · a conferir` |

⚠️⚠️ **`regenerating` vem ANTES do alerta** (`:1119` antes de `:1120`): uma foto sendo regerada
**com** alerta é **TRACEJADA**, não vermelha. Avaliar o alerta primeiro pintaria de vermelho uma foto
que vai ser substituída em segundos — e ninguém precisa conferir o que vai ser trocado (IMG-09). Há
asserção própria para esse caso cruzado.

**A prova de que NENHUM quadrado sai sem `title`:** o teste conta os `title="…"` do HTML e exige que
o número seja **igual ao número de quadrados**, e que **nenhum** esteja vazio — ⚠️ `title=""` é
`title` **ausente**, e aí a cor viraria o único sinal (VOC-05). Com `titulo` chegando objeto, vazio
ou ausente, o atributo **não é escrito** (nunca `title="undefined"`, nunca `[object Object]`).

**Mutação (tracejado → sólido): MORREU.**

### ⚠️⚠️ O estado `regenerando` NÃO é alcançável pelo servidor — e isso é DECISÃO, não lacuna

`PubAprovacaoImagem::NO_CONJUNTO_FINAL` é **`[pending, approved]`**
(`app/Models/PubAprovacaoImagem.php:44`), e `noConjuntoFinal()` exige estado nessa lista
(`:106`-`:112`). Então **a galeria final nunca contém `regenerating`**.

Alargar a galeria para fabricar o quadrado tracejado:
(a) faria a contagem dos quadrados **divergir** de `BloqueiosDaOfertaService::contagemDeFotos()` —
quebrando a verdade declarada do próprio plano (*"os mini-quadrados contam o MESMO que o bloqueio
conta"*); e (b) exigiria alterar `PubAprovacaoImagem`, que é **Fase 186, território INTOCÁVEL**.

➡️ **Não é escolha entre dois caminhos válidos, é um caminho só** (decisão do orquestrador,
16/10/2026). O ramo existe no componente com o literal do protótipo e é conferido por **props
diretas** — gate com casos, não gate sobre zero. Está escrito no docblock de
`GaleriaDaOfertaService` e de `QuadradosDeFoto.jsx` para ninguém "consertar" depois.

---

## 6. ⚠️⚠️ A galeria provada IDÊNTICA ao bloqueio — o gate central deste plano

A definição de "galeria final" e a ordem dos grupos vivem em `galeriaDoRascunho()`, um método
**PRIVADO** de `BloqueiosDaOfertaService` (`app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php:325`),
e aquele arquivo é **intocável**. Copiar o corpo criaria duas verdades, e a divergência apareceria na
tela como *"5 de 5 fotos aprovadas"* **com um bloqueio de fotos de pé** — o pior tipo de bug de dado,
porque **parece completo** (é o que o docblock de `contagemDeFotos()` já escrevia).

**A saída, em três movimentos:** ler pelos **modelos** (API pública) · usar as **constantes**
públicas (nunca literais — há gate de ausência de `'approved'`, `'pending'` e `'GENERAL'` no
arquivo) · **PROVAR POR COMPARAÇÃO** os quatro totais em **6 cenários**:

| cenário | o que ele discrimina |
|---|---|
| vazio | rascunho sem conjunto nenhum (IMG-12) |
| tudo aprovado | `aprovadas === total` |
| pendente no `GENERAL` | o grupo geral |
| pendente em OUTRO grupo | ⚠️ a união de **todos** os grupos (decisão de 14/10: aprovação cor por cor foi RECUSADA) |
| com alerta | ⚠️ alerta em PENDENTE conta; o MESMO alerta em APROVADA **não** |
| com regenerando | o estado que **sai** da galeria |

⚠️ **Com ISCA INVERTIDA:** pelo menos um cenário tem foto. Sem ela, `0 === 0` seis vezes passaria
medindo NADA — e é exatamente o tipo de sobrevivente que a Fase 186 mediu.

**Os 5 estados de revisão, declarados um a um**, mais as **outras duas condições** de
`noConjuntoFinal()` (`posicao` nula e `fora = true`), também com isca invertida (a galeria do
rascunho COM conjuntos é não-vazia).

**O custo:** `3` consultas FIXAS (conjuntos · imagens por `whereIn` · alertas por eager loading),
medidas por `DB::getQueryLog()` e provadas **imóveis** quando a fixture passa de 1 para 9 imagens em
5 grupos. ⚠️ `temAlerta()` faria **uma consulta por imagem** — o N+1 clássico; há gate de ausência da
**chamada** (`->temAlerta(`).

---

## 7. A tradução de granularidade (3 níveis), com `arquivo:linha`

| nível do protótipo | o que é aqui | medido em |
|---|---|---|
| **produto** (tag PRODUTO, "N ofertas") | a **FAMÍLIA**: o produto base + os kits dele | `PubProduto::familia()` — `app/Models/PubProduto.php:121` (`produto_base_id ?? id`) |
| **conjunto de fotos** ("Unidade" / "Combo ×N") | o **PRODUTO** (base ou kit) e o rascunho dele, **1:1** | `pub_rascunhos.produto_id` é UNIQUE (`pubr_produto_uq`, `database/migrations/2026_10_01_200000_create_publicador_tables.php:37`) |
| **oferta** (Clássico / Premium) | o **ALVO** do rascunho; a oferta do portão é extensão **1:1** | `pub_aprovacao_ofertas.alvo_id` UNIQUE (`pubapof_alvo_uq`, `…_100200_…:297`) |

⚠️ **O `grupo_chave` de `pub_aprovacao_conjuntos` é OUTRO EIXO** — é o grupo de **VARIAÇÃO**
(`GENERAL` ou a chave da variante), **não** Unidade/Combo. Um rascunho tem **N** conjuntos de
variação, e o bloqueio cobre **todos**.

**As regras, com os ponteiros corrigidos:**

| regra | linha MEDIDA | prova |
|---|---|---|
| grupos pela ordem de **primeira aparição** na lista já ordenada | `:1256`-`:1257` | isca: a oferta do produto alfabeticamente posterior **sobe** quando a espera dela sobe |
| conjuntos por **unidades crescentes** | `:1258` | isca: o kit de 3 é criado ANTES do de 2, e a saída é Unidade → ×2 → ×3 |
| "N ofertas" somando os conjuntos, com **singular** | `:1259` | `3 ofertas` no grupo de 3; `contagemDeOfertas(1)` = `1 oferta` |
| **borda superior** só do 2º grupo em diante | `:1260` | 1 grupo → 0 bordas · 2 → 1 · 3 → 2, e o **índice é a trava** (dado forjado não dá borda ao primeiro) |
| **Clássico antes de Premium** | `:1261` | isca: o Premium é criado PRIMEIRO |
| `compartilhado` = conjunto com mais de uma oferta | `:1262` | o pill só com `true`; truthiness (`'sim'`, `1`, `{}`) **não** vale |
| `label` Unidade/Combo ×N de `quantidade_kit` | `:1262` | 5 casos, com isca nas DUAS direções |
| `progress` = `x/y aprovadas` e `photos` = `N fotos (M geradas por IA)` | `:1262` | LIDOS da galeria, nunca recontados |

**Mutação (ordem Clássico/Premium invertida): MORREU** (`tres-niveis` reprovou com 1 failed/2 passed).

### `compartilhado` conta as ofertas DO RASCUNHO, não as filtradas

`setOffers(sk)` do protótipo (`:1054`) varre **todas** as ofertas do conjunto, **sem filtro** — e
isso é a parte que importa: o pill é um fato sobre o RASCUNHO (as duas ofertas leem o MESMO conjunto;
não existe coluna onde pudessem divergir). Contá-lo sobre a lista filtrada faria o pill
**desaparecer** quando alguém filtrasse por "Premium", como se a aprovação deixasse de ser
compartilhada por causa de um filtro. ➡️ **UMA** consulta agregada por `rascunho_id`.

### ⚠️ Divergência declarada: `quantidade_kit`, não `ehKit()`

`PubProduto::ehKit()` (`app/Models/PubProduto.php:107`) exige **também** `produto_base_id` não-nulo,
e aqui ele **não** é consultado. O rótulo descreve *"quantas unidades vão nesta oferta"*, que é
`quantidade_kit` e só: um kit de 2 cadastrado **sem base** ainda vende 2 unidades por venda, e dizer
"Unidade" ali seria afirmação **FALSA** sobre o que o comprador recebe. É também o que o protótipo
mede (`set.units > 1`, `:1262`), e é a **MESMA** decisão que
`ProximoARevisarService::formato()` já tomou e declarou. Há gate de ausência da **chamada**
(`->ehKit(`) e isca nas duas direções (kit de 2 sem base → "Combo ×2"; base com 1 unidade →
"Unidade").

---

## 8. A frase do cadastro — e a fonte que a torna verdadeira

*"Cadastro completo · categoria e ficha técnica organizadas"* (`:120`) é **afirmação sobre o
cadastro**. **Confirmado pelo usuário em 15/10/2026:** medir no rascunho; não sendo verdade, **a
frase NÃO aparece**.

**A fonte, medida no schema:** `pub_rascunhos.categoria_id` preenchido **E** pelo menos um
`pub_rascunho_atributos` **E** nenhum deles com `revisar = true`. A família só é "completa" quando
**TODOS** os rascunhos dela estão — o grupo é UM cabeçalho, e afirmar pelo conjunto o que vale para
parte dele seria a mesma mentira.

⚠️ *"Sem atributo nenhum"* conta como **FALSO de propósito**: não há de onde afirmar que a ficha está
organizada.

**As três maneiras de ser falsa, cada uma medida** — e o caso **VERDADEIRO ao lado como ISCA
INVERTIDA**: sem ele, um serviço que devolvesse sempre `null` passaria em três dos quatro. No
componente, a asserção de ausência roda com as props **PREENCHIDAS**, e há gate de que nenhum texto
genérico ("pendente", "—") ocupa o lugar dela. O literal mora **só no serviço**; há gate de ausência
dele no JSX.

---

## 9. Quais tokens ACORDARAM, com o CSS conferido pelo JSON

| token | medido em | nesta wave | prova no CSS construído |
|---|---|---|---|
| **`pub-sunken-2`** (`#0F0F0F`) | `tailwind.config.js` aponta **`Aprovacao.dc.html:124`** — exatamente a sub-linha | ⚠️ **ACORDOU** | `rgb(15 15 15` + provado **DIFERENTE** de `pub-sunken` (`rgb(13 13 13`) e `pub-raised` (`rgb(21 21 21`) |
| `shadow-pub-flutuante` | `:82`/`:95` (popovers) | já acordado na wave 6 | — |
| **`pub-float`** (`#1C1C1C`) | ⚠️ **`:897`, a BARRA DE LOTE** | **continua dormindo** | gate exige `bg-pub-float` **AUSENTE** do CSS |
| **`pub-float-line`** (`#333333`) | ⚠️ **`:905`, o TOAST** | **continua dormindo** | gate exige `border-pub-float-line` **AUSENTE** |
| `pub-anel-parcial` | `:1241` (o anel `partial`) | **é do 187-08** | — |

### ⚠️⚠️ A correção do briefing: `pub-float`/`pub-float-line` **não** são desta wave

O briefing dizia que os dois *"foram medidos na SUB-LINHA (187-08)"*. **Medido no
`tailwind.config.js`, isso está errado** — o comentário que os criou (plano 187-01) diz, literal:

> `// ── Flutuantes (TOK-05) — Fase 187, plano 01 ──`
> `// O FUNDO e a BORDA dos elementos que flutuam sobre a fila:`
> `// a barra de ação em lote (Aprovacao.dc.html:897, position:fixed;bottom:24px) e o toast (:905, top:124px).`

`:897` e `:905` são **wave 9**. ➡️ Eles **não** foram acordados, pela régua do próprio briefing
(*"NÃO use token fora do lugar medido só para zerar skip — a wave 4 recusou isso e estava certa"*), e
há **gate provando que continuam ausentes do CSS**, com **isca lendo a camada** para provar que os
dois **EXISTEM** (o `skip` não é "token inexistente"). ⚠️ **O orquestrador aceitou esta correção.**

**Manifest pelo JSON** (`node`, nunca grep: o hash sai com HÍFEN) e `manifest['resources/js/app.jsx'].css[0]`
(⚠️ `resources/css/app.css` **não** é chave — divergência N5). A página está no manifest, com isca de
chave inexistente. `npm run build` → **exit 0**.

---

## 10. `ARQUIVOS_NOVOS`, o GATE-03 e a bijeção

**`ARQUIVOS_NOVOS` recebeu os QUATRO `.jsx`** desta wave — sem isso o GATE-03 passaria **por
omissão** para eles, e `DIR_APROV` está em `DIRETORIOS_FECHADOS` (o `deepEqual(noDisco, declarados)`
reprova na hora, que é o comportamento certo). ⚠️ **`LinhaDaOferta.jsx`, `AnelDeProgresso.jsx` e
`BarraDeMargem.jsx` (187-08) NÃO foram pré-declarados:** declarar antes de o arquivo existir
**LANÇA** no carregamento do módulo, porque `FONTES` lê todos os caminhos.

**GATE-03 (`vocabulario-tamanhos`, id próprio do `contagens-187-01.txt`, forma `>=443`):**
**485 / 485 pass / 0 fail**. ⚠️ Ele **não** foi embutido em id composto nenhum deste plano — a
contagem dele **cresce por construção**, e embuti-lo foi o que fez a wave 5 reprovar DOIS ids de
outras waves.

### A bijeção ids ↔ métodos, nos DOIS sentidos

16 métodos `_187_07`, **9 ids**, nenhum método de fora e nenhum id órfão — provado por script, com
**UMA asserção por sentido** coletando infratores, e **isca** provando que a comparação sabe recusar.

⚠️ **O plano prescrevia 3 ids** (`galeria-da-oferta`, `grupo-da-fila`, `tabela-niveis-1-e-2`).
**`grupo-da-fila` NÃO existe**, e é decisão MEDIDA: ele cobriria os **8** métodos PHP da tarefa 2
numa alternância só, e alternância grande **equivale a filtro de CLASSE** — que passa dos 120 s do
shell e o helper **RECUSA**. Os 8 viraram **três** ids por assunto, e os 5 da tarefa 1 viraram
**dois**. Está declarado no cabeçalho do manifesto.

| id | modo | tests / assertions |
|---|---|---|
| `galeria-da-oferta` | php | 3 / 16 |
| `galeria-contra-o-bloqueio` | php | 2 / 7 |
| `tres-niveis` | php | 3 / 19 |
| `unidade-e-combo` | php | 2 / 11 |
| `cadastro-e-sublinha` | php | 3 / 14 |
| `lista-pela-etapa` | php | 1 / 12 |
| `territorio-tabela` | php | 1 / 32 |
| `bijecao-manifesto-07` | php | 1 / 9 |
| `tabela-niveis-1-e-2` | js | **27** (pristino) / **19** (mutado) |

⚠️ **Nenhum id usa `>=`:** a contagem destes arquivos não cresce por construção, e a igualdade exata é
mais forte e é o padrão.

---

## 11. A campanha de mutação — 7 aplicadas, 7 mortas, 0 sobreviventes

**Protocolo:** commit **ANTES** (`bd137b64`) · `cp` para o scratchpad · ocorrência **travada em 1
ANTES** (recusa se ≠ 1) · **linha conferida DEPOIS** · restauração **DA CÓPIA** em `trap EXIT` ·
`sha256` contra `git show HEAD:` · ⚠️⚠️ **`git checkout` NUNCA**.

| # | mutação | arquivo | gate | resultado |
|---|---|---|---|---|
| 1 | `104px` → `108px` na 4ª trilha do grid | `TabelaDaFila.jsx` | `tabela-niveis-1-e-2` | **MORREU** |
| 2 | `pl-[62px]` → `pl-[60px]` | `SubLinhaDeConjunto.jsx` | `tabela-niveis-1-e-2` | **MORREU** |
| 3 | quadrado de 10px → 12px | `QuadradosDeFoto.jsx` | `tabela-niveis-1-e-2` | **MORREU** |
| 4 | tirar `compartilhado` da condição do pill | `SubLinhaDeConjunto.jsx` | `tabela-niveis-1-e-2` | **MORREU** |
| 5 | tracejado do regenerando → sólido | `QuadradosDeFoto.jsx` | `tabela-niveis-1-e-2` | **MORREU** |
| 6 | dar borda superior ao primeiro grupo | `TabelaDaFila.jsx` | `tabela-niveis-1-e-2` | **MORREU** |
| 7 | inverter a ordem Clássico/Premium | `GrupoDaFilaService.php` | `tres-niveis` | **MORREU** |

### ⚠️ A FIXTURE QUE DISCRIMINA — a lição medida na wave 6

As duas sobreviventes da wave 6 **não** eram falha da asserção, eram da **FIXTURE** (com uma oferta
só, "a conta" e "as ao vivo" davam o mesmo número). Aqui as fixturas foram montadas para discriminar:

- **dois grupos** (para a borda entre eles existir) e um terceiro no caso da contagem;
- conjuntos com **UMA** e com **DUAS** ofertas (para o pill ter as duas direções);
- quadrados nos **quatro** estados, incluindo o cruzado `regenerating` **com** alerta;
- um grupo **sem** a frase do cadastro, ao lado de um **com**;
- no `lista-pela-etapa`: **duas** ofertas `publicado`, uma de **HOJE** e uma de **ONTEM** — sem a de
  ontem, o recorte do dia e o recorte só por andamento dariam o **MESMO** número;
- no custo: ofertas `preparando`/`publicado` ao lado das ao vivo, e 2 ofertas do **mesmo** rascunho;
- no `Unidade/Combo`: o kit de 2 **sem** base (onde `ehKit()` diz FALSE) e a base **com** 1 unidade.

### ⚠️ Dois abortos do protocolo que foram ACERTOS

1. **A trava de ocorrência recusou a mutação 1 na primeira tentativa** (`minmax(170px,1fr)_104px_118px`
   aparecia **2×**: no docblock e na constante). O alvo foi estreitado para incluir a aspa de abertura
   (`'grid-cols-[…`), que é único. ⚠️ Mutação que troca 2 lugares mede outra coisa.
2. **O `sha256` acusou divergência na mutação 7** — e era **falso positivo MEU**: `GrupoDaFilaService.php`
   está em **CRLF** e `git show HEAD:` devolve **LF**. Conferido: `tr -d '\r' | sha256sum` ==
   `git show HEAD: | sha256sum` (`ced257d66ba3d96c`), e `git diff HEAD` dos 4 arquivos mutados saiu
   **vazio**. ➡️ A verificação certa em árvore CRLF é `git diff HEAD`, não `sha256` cru.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Regra 3 - Bloqueio] `recortarPelaEtapa()` nasceu neste plano, porque o TERCEIRO recorte não existia**

- **Found during:** Tarefa 3, ao ligar a `lista` no controller.
- **Issue:** o payload da tabela precisa da `lista` (`base` + etapa, `:1214`), e esse recorte **não
  existia em lugar nenhum**: `AndamentoDaOfertaService::faixaDeAndamento()` conta os 9 cards mas não
  devolve consulta, e `FiltrosDaFilaService::exibidasNaEtapa()` só **LÊ** o número da faixa. Os dois
  arquivos são território proibido.
- **Fix:** `GrupoDaFilaService::recortarPelaEtapa()`, com **gate comparando o `count()` dela ao total
  do card correspondente nas 9 chaves**, com isca invertida. Escrevê-lo no controller faria a regra
  morar na action, longe de todo gate.
- **Por que isso importa:** na etapa `publicado` o card conta **"publicado HOJE"**
  (`whereDate('updated_at', hoje)`), e uma segunda regra mostraria na tabela publicações de **ontem**
  com o card dizendo outro número. **O usuário recusou número divergente entre conta e lista DUAS
  vezes** (decisões 9 e 16).
- **Files:** `app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php`, `…/MlbPublicadorAprovacaoController.php`
- **Commit:** `bd137b64`

**2. [Regra 1 - Bug no meu próprio gate] A sonda de território casava a VARIÁVEL, não a CHAMADA**

- **Found during:** Tarefa 2, no `territorio-tabela`.
- **Issue:** o gate proibia a string nua `temAlerta` e **reprovou o código CERTO** — a variável local
  `$temAlerta` de `GaleriaDaOfertaService::doRascunho()` contém aquela substring. O invariante não é
  "a palavra não aparece", é **"não se CHAMA `temAlerta()` por imagem"** (N+1). O gate largo me
  empurrava para o conserto errado: renomear a variável para o gate passar.
- **Fix:** a sonda ancora no **operador de chamada** (`->temAlerta(`, `->ehKit(`), com **isca nas duas
  direções** (pega a chamada, **não** pega a variável) e uma asserção de que a variável que motivou o
  estreitamento **ainda existe** — senão a correção seria sobre um caso que não existe mais.
- **Commit:** `9aca3eba`

**3. [Regra 1 - Bug no meu próprio gate] `includes('border-pub-line ')` casava o `<section>`**

- **Found during:** Tarefa 3, no gate de render.
- **Issue:** a casca da tabela carrega `border border-pub-line bg-pub-surface` (`:108`), então aquela
  substring existe **sempre** — o gate media a casca, não o embrulho do grupo. Família *"substring
  casa com prefixo"* / *"gate mais largo que o invariante"*.
- **Fix:** contagem por **CONJUNTO de classes** (`border-t` **+** `border-pub-line`), com isca
  provando que a sonda **separa** a casca do embrulho (a casca tem `border-pub-line` e **não** tem
  `border-t`). E a regra ganhou as três contagens (1, 2 e 3 grupos) mais as duas direções do dado.
- **Commit:** `bd137b64`

**4. [Regra 1 - Bug no meu próprio gate] "todo `.map()` recebe índice" era mais largo que o invariante**

- **Found during:** Tarefa 3.
- **Issue:** reprovou o `.map()` dos 8 rótulos do cabeçalho, que varre uma **CONSTANTE do módulo**
  cuja chave do React é o próprio rótulo (único por construção) e que **não tem índice para que
  serviria**.
- **Fix:** o índice passou a ser cobrado **só dos `.map()` sobre dado do SERVIDOR** (identificados
  pelos nomes `cruGrupo`/`cruConjunto`/`cruOferta`), e o **invariante de verdade** (cada corpo de
  `.map()` declara as próprias `const`) virou asserção própria, com isca nas duas direções.
- **Commit:** `bd137b64`

**5. [Regra 1 - Fragilidade por construção] A contagem do gate de território dependia de quantos commits existiam**

- **Found during:** a re-rodada de todos os gates depois da mutação.
- **Issue:** **26** asserções antes de commitar e **70** depois, por causa de um `return` antecipado
  quando ainda não havia commit no escopo (e de um `assertFileExists` **por caminho** nos ~40
  proibidos). O manifesto reprovava, e o conserto barato seria "ajustar o número".
- **Fix (na RAIZ, não no número):** nenhuma asserção dentro de condicional · o `test -e` virou **UMA**
  asserção coletando fantasmas, com isca · a prova de "range não vazio" virou asserção **coletada**
  em vez de `return`, com isca nas duas direções. Agora são **32** com ou sem commit no escopo.
- **Por que isso importa:** é a **NONA** ocorrência da família *"contagem que depende de quantos
  arquivos/tabelas/commits existem"* nesta milestone, e como as outras oito foi consertada na raiz.
- **Commit:** `963009d6`

**6. [Regra 3 - Gate alheio] Colisão de PREFIXO no `--filter`, achada por um gate da Fase 186**

- **Found during:** a suíte PHP completa.
- **Issue:** `FechamentoDaFase186Test::os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos`
  reprovou: existe `test_todo_metodo_do_plano_07_tem_id_no_manifesto_e_todo_id_casa_um_metodo` (sem
  sufixo) em `SemLiteralDeTaxaTest.php` — **Fase 186, plano 07** — e o meu era **SUPERSTRING** dele. O
  `--filter` do PHPUnit é regex **NÃO ANCORADO**, então rodar o id da 186 casaria os DOIS e **SOMARIA**
  as contagens: o manifesto daquela fase (**em produção**) passaria a reprovar por causa desta wave.
- **Fix:** **sufixo próprio ao método NOVO** (`test_bijecao_do_manifesto_da_tabela_187_07`), que é
  exatamente o que aquele gate manda, e **nunca** elevar a contagem do manifesto alheio.
- **Nota para o 187-08 e seguintes:** ⚠️ a colisão só aparece porque as duas fases numeram planos de
  01 a 09+ — **todo `_0N` desta fase tem um homônimo possível na 186**.
- **Commit:** `bd137b64`

**7. [Regra 3 - Gate alheio] `font-extrabold` prescrito pelo plano é PROIBIDO pelo GATE-03**

- **Found during:** Tarefa 3, na primeira rodada do GATE-03 (485 testes, 1 falha).
- **Issue:** a tabela FID-01 do plano prescreve `font-extrabold` para o checkbox do cabeçalho, e o
  GATE-03 proíbe a escala nomeada em todo arquivo de `ARQUIVOS_NOVOS`.
- **Fix:** **`font-[800]`** — o MESMO peso computado, por fora da escala nomeada. É o que
  `CabecalhoDaFila.jsx:186` (wave 4) e `PopoverDeFiltro.jsx:181` (wave 6) já fazem, pelo mesmo motivo.
  Há gate de ausência de `font-extrabold` nos quatro arquivos, com o controle de que o 800 **existe**.
- **Commit:** `bd137b64`

### Desvio de FORMA do plano, declarado

**As tarefas 1 e 2 saíram num commit só (`9aca3eba`).** O plano pôs os casos das duas no **MESMO**
arquivo (`GrupoDaFilaServiceTest.php`), e separá-las exigiria um commit com o teste **VERMELHO** sem
o código que o faz passar — pior: com `GrupoDaFilaService` inexistente, a suíte **erraria** no HEAD, e
a Fase 187 está **no ar parcial**. O orquestrador proibiu explicitamente deixar um RED como último
commit. As duas tarefas estão separadas no corpo da mensagem e nos gates (ids distintos).

**A ligação do controller ficou na tarefa 3, não na 2.** O plano a listava na tarefa 2, mas o
consumidor (`TabelaDaFila.jsx` + a página) nasce na 3: ligar antes deixaria uma prop no payload que
ninguém lê. Assim **todo commit é auto-consistente** e nada inerte vai para produção.

---

## Known Stubs

**Nenhum.** Varredura feita nos 6 arquivos criados/alterados, procurando `=[]`, `={}`, `=null`, `=""`
que cheguem ao render, e os literais "not available", "coming soon", "placeholder", "TODO", "FIXME".

⚠️ **O que PARECE stub e não é — são slots NOMEADOS e VAZIOS (D4), com os dois ramos testados:**

| slot / prop | de quem é | o que acontece hoje |
|---|---|---|
| `linhaDaOferta` | **187-08** | sem a função, **NENHUMA** casca de linha de oferta é desenhada (gate prova: HTML tem cabeçalho + produto + sub-linha e **zero** `min-h-[64px]`) |
| `rodape` | 187-09 | sem nó, nada é desenhado |
| `vazio` | 187-09 | sem nó, nada é desenhado — ⚠️ **esta tabela não escreve vazio NENHUM** (decisão 16) |
| `onMarcarVisiveis` | 187-09 | sem handler, o checkbox do cabeçalho **não é desenhado**; a célula 1 fica vazia e as 11 trilhas continuam |
| `onRevisarFotos` | 188 | sem handler, o atalho **não é desenhado** |

Cada um deles tem **as duas direções** medidas: com a função/nó, a peça aparece com a geometria
literal; sem, ela é **ausente** — e a asserção de ausência roda com as **props PREENCHIDAS**, nunca
sobre render vazio.

---

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano. Os três limites declarados
foram mitigados e medidos:

| Threat ID | mitigação | prova |
|---|---|---|
| T-187-71 (galeria divergir do bloqueio) | comparação dos 4 totais em 6 cenários, com isca invertida | `galeria-contra-o-bloqueio` |
| T-187-72 (3 `.map()` aninhados) | render adverso em 20 formas × cada campo dos 3 níveis + flag dentro de cada callback + `doesNotMatch` dos 4 marcadores, com isca do medidor | `tabela-niveis-1-e-2` |
| T-187-73 (consultas por grupo) | 3 consultas FIXAS na galeria, imóveis de 1 para 9 imagens em 5 grupos | `galeria-contra-o-bloqueio` |
| T-187-74 ("Cadastro completo" sem fonte) | a frase só quando é VERDADE; 3 modos de ser falsa + isca invertida | `cadastro-e-sublinha` |
| T-187-75 ("Restaurar dados de exemplo") | gate de ausência sobre a lista CONSTANTE de 4 arquivos, com isca lendo o protótipo (`:200`) | `tabela-niveis-1-e-2` |
| T-187-SC (npm/composer) | nada instalado; os 4 arquivos de dependência estão na lista de PROIBIDOS do gate de território | `territorio-tabela` |

⚠️ **A fronteira desta tela, reafirmada:** ela **APROVA (e aprovar ENFILEIRA); ela NÃO publica.** A
escrita no Mercado Livre é da **Fase 193**. Neste plano **nada é escrito**: zero `router.put/patch/delete`,
zero `axios`, zero `fetch(`, zero `<form`, e os dois serviços novos só **LEEM**.

⚠️ **Zero chamada ao Mercado Livre sai da montagem da árvore**: `GrupoDaFilaService::montar()` não
chama `BloqueiosDaOfertaService::para()`, e há gate com espião contando **ZERO**, com **isca provando
que o espião conta** (ele é chamado à mão depois e o contador vai a 1).

---

## Território — o que foi medido como INTOCADO

Os commits do escopo `(187-07)` (`9aca3eba`, `bd137b64`, `963009d6`) foram conferidos contra ~40
caminhos proibidos, **com `test -e` em cada um antes** (caminho fantasma "provaria" território
intocado medindo NADA — aconteceu seis vezes nesta milestone) e com **isca de prefixo de segmento**
(`app/Mcp` não pode casar `app/McpOutraCoisa.php`).

⚠️ **A partida é `07d87a56`, remedida** — com a velha (`963e55d0`) o range abraçaria os 8 commits da
outra máquina e o gate acusaria a sessão paralela. **O autor NÃO discrimina** (as três sessões usam o
mesmo `user.name`): o discriminador é o **ESCOPO**, com isca nas duas direções (não casa `(187-06)`,
não casa `(187-08)`, não casa `fix(261016-mwe)`).

Intocados e medidos: os 10 de `app/Services/Publicador/Aprovacao/` fora de `Fila/` · os 3 serviços de
`Fila/` das waves 2/5/6 · os 8 componentes de `aprovacao/` já no ar · `publicacoes/` · `identidade/` ·
`Components/Publicador/Mesa/` · `AppLayout.jsx` · `Editor.jsx` · `app/Services/Creative/` ·
`Criativos/` · `ImagemAssetService.php` · `app/Support/Publicador` · `app/Mcp` · `app/Services/Portal` ·
os 6 gates intocáveis/de outras waves · os 4 arquivos de dependência · `database/migrations` ·
`tailwind.config.js` · `.planning/ROADMAP.md` · `.planning/STATE.md`.

⚠️ **Nenhuma migration** — esta fase não tem, e `artisan migrate` **não** foi rodado (há migration
pendente de outra milestone).

---

## O que este plano NÃO fez, de propósito

- **A linha da oferta (as 11 células)** — é o **187-08**. O slot nasceu **nomeado e vazio**, e o gate
  prova que sem nó **não há casca de linha nenhuma**.
- **O rodapé, o estado vazio, a barra de lote e os toasts** — são o **187-09**. Nenhum provisório de
  nenhum deles.
- **O realce da linha em foco e os 5 fundos de linha** — 187-08.
- **A miniatura da foto** — o acervo é território proibido (a v28 virou o acervo para o R2 em
  produção em 16/10). `TileDoProduto` desenha o tile de iniciais, que é o que o protótipo desenha.
- **Nenhum token novo** — `pub-sunken-2` já existia desde o 187-01; só acordou.
- **`pub-float`/`pub-float-line`** — continuam dormindo, com gate provando. Ver §9.
- **Nenhuma tela renderizada conferida por mim:** ⚠️ o banco local **não tem empresa com `ml_token`**
  (a rota dá **404** aqui). **A conferência visual é do usuário, em produção, na conta 459.**

---

## Self-Check

```
FOUND: app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php
FOUND: app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/TabelaDaFila.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/LinhaDeProdutoDaFila.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/SubLinhaDeConjunto.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/QuadradosDeFoto.jsx
FOUND: tests/Unit/Publicador/Aprovacao/GrupoDaFilaServiceTest.php
FOUND: tests/js/publicador-fila-tabela-187.test.js
FOUND: tests/gates/contagens-187-07.txt
FOUND: 9aca3eba  feat(187-07): a galeria ordenada provada contra o bloqueio, e os tres niveis da tabela
FOUND: bd137b64  feat(187-07): a tabela da fila — o grid de 11 colunas, a linha de produto e a sub-linha
FOUND: 963009d6  fix(187-07): a contagem do gate de territorio deixou de depender de quantos commits existem
```

## Self-Check: PASSED
