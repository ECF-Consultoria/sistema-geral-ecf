---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 04
subsystem: publicador-aprovacao
tags: [pagina, manifest-vite, faixa-de-andamento, margem-minima, fidelidade, render-adverso, territorio, mutacao]
requires:
  - "187-01 (os 5 tokens, os 2 tamanhos — incluindo o `22` do número —, o harness `187-NN` e a tabela FID-01 do E1)"
  - "187-02 (a rota `mlb.anuncios.meus.aprovacao`, o contador do servidor, a faixa de 9 e a margem mínima com origem)"
  - "187-03 (`publicacoes/AbasDaPagina.jsx` — IMPORTADO, nunca recriado)"
provides:
  - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx — A PÁGINA da fila (e a entrada dela no manifest do Vite)"
  - "resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx — H1 + abas com a da fila ATIVA + a linha da margem mínima"
  - "resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx — os 9 cards filtráveis"
  - "a prop `etapa`, SANEADA contra a lista fechada das 9 chaves (base do filtro do 187-06)"
  - "os 4 slots nomeados e VAZIOS da ordem vertical de FILA-01 (`proximo`, `filtros`, `tabela`, `rodape`)"
affects:
  - "app/Http/Controllers/MlbPublicadorAprovacaoController.php (a `etapa` saneada — adição)"
  - "tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php (7 métodos novos + 1 cirurgia com veto)"
  - "tests/js/publicador-vocabulario-novo.test.js (3 declarações + 4 reapontes com veto)"
  - "tests/js/publicador-header-nas-paginas.test.js (desvio: a página entrou na tabela + 1 reaponte com veto)"
  - "tests/gates/contagens-187-02.txt (número RE-MEDIDO: 7 → 8 asserções)"
tech-stack:
  added: []
  patterns:
    - "`font-[800]` (valor arbitrário) para o peso 800 medido no protótipo, porque o GATE-03 veta a escala NOMEADA `font-extrabold`"
    - "lookup de total por `Map.get` — `obj[chaveAdversa]` LANÇA com `{toString:null}`"
    - "os 9 cards são ESTRUTURA (vêm do módulo da 186) e o `total` é DADO (vem do servidor); total não afirmável sai `—`, nunca `0`"
    - "slot de nó por `isValidElement`; slot sem nó não desenha casca, borda nem \"nenhum item\""
    - "a etapa é lista FECHADA lida de `PubAprovacaoOferta::ANDAMENTOS`, com `is_string` ANTES de `trim`"
key-files:
  created:
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx
    - tests/js/publicador-fila-cabecalho-187.test.js
    - tests/gates/contagens-187-04.txt
  modified:
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-header-nas-paginas.test.js
    - tests/gates/contagens-187-02.txt
decisions:
  - "A raiz do protótipo (`:25`, `min-height:100vh;background:#0A0A0A`) NÃO foi copiada: lá aquele div É o documento, aqui a tela vive dentro do `AppLayout` (território proibido) e as DUAS páginas irmãs que estão no ar também não a declaram. O `min-width:1240px` FOI preservado."
  - "O peso 800 do ⓘ entra por `font-[800]`, não por `font-extrabold`: o protótipo ganha no VALOR e o GATE-03 ganha na ESCALA NOMEADA."
  - "O separador `·` sai SEM espaço literal em volta: o espaçamento é o `gap:8px` do bloco pai, e o `<behavior>` do plano pedia `\" · \"`."
  - "A metade \"a LISTA honra a etapa\" NÃO é provada neste plano — não existe lista no payload, e o gate seria sobre ZERO casos."
metrics:
  commits: 6
  duracao: "~3 h"
  mutacoes: "18 aplicadas, 18 mortas, 0 sobreviventes"
---

# Fase 187 Plano 04: A página da fila "Aguardando aprovação" — Summary

A rota do 187-02 virou TELA: casca, cabeçalho com a aba da fila ativa, a linha
"ⓘ Margem mínima: 12% · Toda oferta precisa de aprovação humana antes de publicar"
com o valor do servidor, e a faixa de 9 cards que filtra pela URL — com a janela
do manifest do Vite FECHADA e a contagem da faixa provada sobre os DEMAIS filtros.

---

## 1. ⚠️⚠️ A JANELA FECHOU — e a primeira prova dela era VAZIA POR CONSTRUÇÃO

**O que estava aberto:** a rota `mlb.anuncios.meus.aprovacao` (187-02) respondia e
a aba (187-03) levava a ela, mas `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`
**não existia**. E `resources/views/app.blade.php:38` monta
`@vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])` —
então um GET **sem** `X-Inertia` naquela rota errava no manifest do Vite.

**A página existe e está no manifest, MEDIDO pelo JSON:**

```
resources/js/Pages/Mlb/Publicador/Aprovacao.jsx  ->  assets/Aprovacao-rN1LDZgK.js
resources/js/Pages/Mlb/MeusAnuncios.jsx          ->  assets/MeusAnuncios-kwlLybv1.js
resources/js/Pages/Mlb/NaoExiste9z7.jsx          ->  AUSENTE  (a isca)
manifest: 491 entradas / 201 paginas   (era 489 / 200)
```

### ⚠️⚠️ ACHADO: a asserção que o 187-02 pediu quase nasceu vazia

A primeira redação de `test_rota_da_fila_resolve_o_componente_da_pagina_187_04`
provava *"o GET sem `X-Inertia` deu 200"* e tinha uma ISCA registrando uma rota
com componente INEXISTENTE para exigir que ela **quebrasse**.

➡️ **A isca NÃO quebrou.** Ou seja: naquele caminho, o `200` **não distinguia
nada** — era gate vazio por construção, a armadilha nº 4 da lista.

**A prova virou DIRETA, e em três camadas:**

| camada | asserção | por que ela não pode passar de graça |
|---|---|---|
| manifest | a chave da página existe no JSON | isca `NaoExiste9z7.jsx` sai ausente |
| HTML | o HTML da rota **contém o arquivo construído** `assets/Aprovacao-rN1LDZgK.js`, lido do próprio manifest | se o `@vite` não resolvesse a página, o nome com hash não estaria lá |
| mecanismo | `app(Illuminate\Foundation\Vite::class)('…/NaoExiste9z7.jsx')` **LANÇA** | medido: `ViteException :: Unable to locate file in Vite manifest` |
| isca extra | o bundle da **LISTA** (`MeusAnuncios-kwlLybv1.js`) **NÃO** entra no HTML da fila | prova que o `@vite` monta a página PEDIDA, e não "todas" |

E o componente é lido direto do objeto de página (`->json('component')` ===
`'Mlb/Publicador/Aprovacao'`). ⚠️ **`AssertableInertia` não entra:** medido nesta
sessão, ele reprova esta resposta com *"Not a valid Inertia response"* — usá-lo
faria o gate reprovar o código certo.

---

## 2. As baselines RE-MEDIDAS

### Antes (commit de partida `a47a8c39`, primeira ação do executor)

```
COMMIT:   a47a8c39
JS:       4994 / 4988 pass / 1 fail / 5 skipped
PHP:      2373 / 20478 / 0
MANIFEST: 489 entradas / 200 paginas / Aprovacao.jsx AUSENTE / isca ausente
CSS:      assets/app-M2A6TYMm.css
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e  ✓ bate com o 187-01
```

### Depois (HEAD `dc1ef635`)

```
JS:       5130 / 5124 pass / 1 fail / 5 skipped
PHP:      2380 / 20654 / 0
MANIFEST: 491 entradas / 201 paginas / Aprovacao.jsx PRESENTE / isca ausente
CSS:      assets/app-qfnqKgCx.css (227.650 bytes)
BUILD:    exit 0 (~32 s)
```

### ⚠️ As duas diferenças contra o que o orquestrador anunciou

1. **As "2 falhas do PHP" não existiam** — confirmado: a baseline deu
   **2373 / 20478 / 0**, e o fechamento deu **2380 / 20654 / 0**. Nenhuma falha
   em nenhuma das duas pontas. (O `187-BASELINES.md` §2.2 registra 1 falha
   herdada no commit `75b9f82f`; ela já não existe em `a47a8c39`.)
2. **A falha única do JS é a pré-existente da Fase 87**
   (`not ok 191 - Características secundárias nasce recolhido`, em
   `estrutura-grade-glide.test.js`). **NÃO corrigida**, de propósito.

⚠️ **Nenhum `REF_PHP_FALHAS` foi criado.** As duas réguas do harness
(`REF_JS_TESTS=4836`, `REF_JS_FALHAS=1`, `REF_PHP_TESTS=2346`) ficaram
**intocadas** — as contagens subiram sozinhas e passar ACIMA é o comportamento
esperado.

---

## 3. ⚠️⚠️ OS 5 TOKENS DA WAVE 1 **NÃO ACORDARAM** — e isso é MEDIDO, não esquecimento

O orquestrador disse *"você provavelmente é esse plano"*. **Não é**, e a medida é
direta:

```
$ sh tests/gates/verificar-testes.sh 187-01 tokens-da-fila
# tests 16 / # pass 11 / # fail 0 / # skipped 5     → PASSOU
```

E as cinco classes, lidas no CSS construído pelo JSON:

| token | classe | no CSS |
|---|---|---|
| `colors.pub.sunken-2` | `bg-pub-sunken-2` | **AUSENTE** |
| `colors.pub.float` | `bg-pub-float` | **AUSENTE** |
| `colors.pub.float-line` | `border-pub-float-line` | **AUSENTE** |
| `backgroundImage.pub-anel-parcial` | `bg-pub-anel-parcial` | **AUSENTE** |
| `boxShadow.pub-flutuante` | `shadow-pub-flutuante` | **AUSENTE** |

**Por que nenhuma acorda aqui, medido no próprio arquivo de gate do 187-01
(`TOKENS_NOVOS[].medidoEm`):** os cinco pertencem a elementos que **não são** o
cabeçalho nem a faixa —

- `sunken-2` → `:124`, a **sub-linha de conjunto de fotos** (a TABELA, waves 7/8);
- `float` e `float-line` → `:897`/`:898` (a **barra flutuante de lote**, wave 9),
  `:905` (o **toast**) e `:82`/`:95` (os **popovers de filtro**, wave 6);
- `pub-anel-parcial` → `:1241`/`:194` (o **anel parcial** da tabela);
- `shadow-pub-flutuante` → os dois popovers, a barra de lote e o toast.

➡️ **Nenhum deles seria honesto nesta tela.** Forçar um só para "acordar o skip"
seria usar um nome mentindo sobre o uso — exatamente o que a camada `pub-*`
existe para impedir (é o veto declarado de `neutral-dot`/`txt-4`). Os skips
**continuam nomeados** e acordam nas waves 6, 7/8 e 9.

⚠️ **E um token NOVO acordou, mas não é dos cinco:** `font-[800]` (valor
arbitrário, não token) compila para `font-weight:800` — ver a divergência D-187-04-B.

---

## 4. A tabela FID-01 da MINHA tela, medida POR SCRIPT

**Protótipo:** `design_handoff_publicador/Aprovacao.dc.html` (diretório
`design_handoff_publicador/`), 1432 linhas, sha256 `68624883ce35547e`.
**Recorte:** linhas `:25`-`:48` (24 linhas) — a raiz, o `<main>`, o cabeçalho e a
faixa de 9. Script derivado do `187-BASELINES.md` §5, com as linhas próprias de
`min-height`, `min-width`, `border-color`, `border-bottom`, `padding-bottom` e
`margin-bottom` (sem elas `height:` casa dentro de `min-height:` e por aí vai).

| propriedade | valores distintos no recorte |
|---|---|
| `font-size` | `10px` `11.5px` `12px` `12.5px` `14px` `22px` `28px` |
| `font-weight` | `500` `600` `700` **`800`** |
| `border-radius` | `10px` `50%` `999px` |
| `height` | `7px` `16px` `20px` `40px` |
| `min-height` | `28px` `100vh` |
| `width` | `7px` `16px` |
| `min-width` | `0` `22px` `1240px` |
| `gap` | `6px` `8px` `14px` `16px` `20px` `24px` |
| `padding` | `0 7px` `11px 12px` `24px 36px 120px` |
| `padding-bottom` | **`12px`** (⚠️ só a FILA tem — D2) |
| `background` | `#0A0A0A` `#1F1F1F` `#FACC15` + `{{ s.bg }}` `{{ s.dot }}` |
| `border` | `1px solid {{ s.border }}` |
| `border-color` | `#3F3F46` (no `style-hover` do card) |
| `border-bottom` | `1px solid #1F1F1F` `2px solid #FACC15` `2px solid transparent` |
| `color` | `#0A0A0A` `#3F3F46` `#71717A` `#8A8A90` `#D4D4D8` `#F4F4F5` + `{{ s.countColor }}` `{{ s.labelColor }}` |
| `grid-template-columns` | **`repeat(9,minmax(0,1fr))`** |
| `letter-spacing` | `-0.02em` `-0.025em` |
| `line-height` | `1` `1.2` |
| `z-index` | `50` ⚠️ **não copiado — D1** |
| `white-space` | `nowrap` |
| `font-variant-numeric` | `tabular-nums` |
| `text-align` | `left` |
| `flex` | `none` |
| `margin` / `margin-bottom` | `0` `0 auto` / `-1px` |
| `display` | `flex` `grid` |
| `align-items` | `center` `flex-end` `flex-start` |
| `justify-content` | `space-between` |
| `flex-direction` | `column` |
| `place-items` | `center` |
| `viewBox` | **(vazio)** ⚠️ nenhum SVG neste recorte |
| ⚠️ **`cursor`** | **`pointer`, e só ele. Ver a coluna própria.** |

### ⚠️⚠️ `cursor` — COLUNA PRÓPRIA

```
cursor:pointer ocorrencias   1
cursor (todas) ocorrencias   1
cursor fora de <button>      0
linhas varridas              24
```

| linha | elemento | o que é | implementado |
|---|---|---|---|
| `:46` | `<button>` dentro de `<sc-for hint-placeholder-count="9">` | **cada um dos 9 cards** da faixa | ✅ `cursor-pointer` nos 9, com gate de ausência coletando infratores |

⚠️ **Zero ocorrência fora de `<button>` neste recorte** (o único `cursor:pointer`
do E1 fora de botão é `:133`, a linha da tabela — outra wave). ⚠️ E o
**cabeçalho** tem `cursor` **ZERO vezes** (`:30`-`:41`): há gate de AUSÊNCIA
nele, com controle positivo medindo as **16** ocorrências do E1 inteiro — a mesma
sonda, no mesmo arquivo, acha as 16 e não acha nenhuma no cabeçalho.

### As sete propriedades do CSS construído, conferidas pelo JSON

| classe | regra MEDIDA no `app-qfnqKgCx.css` |
|---|---|
| `grid-cols-[repeat(9,minmax(0,1fr))]` | `grid-template-columns:repeat(9,minmax(0,1fr))` |
| `font-[800]` | `font-weight:800` |
| `tracking-[-0.025em]` | `letter-spacing:-.025em` ⚠️ zero comido pelo cssnano |
| `tracking-[-0.02em]` | `letter-spacing:-.02em` |
| `leading-[1.2]` | `line-height:1.2` ⚠️ SEM unidade (`1.2px` seria 1,2 pixel) |
| `bg-pub-raised-2` / `bg-pub-surface` | `rgb(24 24 24 /…)` / `rgb(17 17 17 /…)` ⚠️ token sólido sai em `rgb()` DECIMAL |
| `hover:border-pub-line-off` | seletor `.hover\:border-pub-line-off:hover` ⚠️ variante, não a classe nua |

---

## 5. ⚠️⚠️ A FAIXA CALCULA SOBRE OS **DEMAIS** FILTROS — provado nas DUAS direções

`AndamentoDaOfertaService::faixaDeAndamento()` (Fase 186) **foi CHAMADO**, não
reimplementado: a cadeia é

```
MlbPublicadorAprovacaoController::index
  → FilaDeAprovacaoService::faixa($base)         (187-02, uma linha)
      → AndamentoDaOfertaService::faixaDeAndamento($base)   (186, os 9 recortes)
```

E o gate de território prova `numstat` **VAZIO** nos dois arquivos
(`AndamentoDaOfertaService.php` e `Fila/FilaDeAprovacaoService.php`).

| direção | o que foi medido | resultado |
|---|---|---|
| **1 — a etapa NÃO move a faixa** | 8 ofertas (uma por estado); as 9 entradas comparadas **byte a byte** entre o GET sem `?etapa` e os GETs com `?etapa=aprovado`, `aguardando_aprovacao`, `falha` e `todas` | **idênticas** nas quatro |
| **2 — um filtro da `$base` MOVE a faixa** | outra conta, com 1 oferta: card `todas` = **1**, soma = **2** (contra soma **16** da primeira) | **diferentes** |

⚠️ **A direção 2 é o que impede o gate de ser vazio.** Uma faixa CONGELADA (um
array fixo) passaria na direção 1 sem reclamar. ➡️ A âncora positiva
(`assertGreaterThan(0, $somaSemEtapa)`) vem **antes** de qualquer negativa.

⚠️ **E a mutação M4 confirma:** trocar `$fila->faixa($base)` por
`$fila->faixa((clone $base)->where('andamento', $this->etapaSaneada($request)))`
— exatamente o sintoma clássico — **MORREU**.

### ⚠️ O que este plano DELIBERADAMENTE NÃO prova, e por quê

**Que `?etapa=` recorta A LISTA.** **Não existe lista no payload deste plano** (a
tabela é dos planos 187-07/08; o filtro de responsável é do 187-06). O
`<behavior>` do plano pedia *"`?responsavel=N` muda os dois"* — escrever isso hoje
seria gate sobre ZERO casos, e **pior**: passaria sempre, e viraria falso positivo
na wave que trouxesse o filtro de verdade. ➡️ **Ausência DECLARADA**, escrita no
docblock do método e aqui.

---

## 6. O ⓘ e a margem mínima

| exigência | como ficou | gate |
|---|---|---|
| `<span>` redondo de **16px** com a letra **`i`** em peso **800** (D4) | `grid h-[16px] w-[16px] place-items-center rounded-full bg-pub-line-subtle text-[10px] font-[800] text-pub-fase1` + o texto `i` | igualdade de CONJUNTO de classes por árvore + `>i</span>` no HTML + **zero `<svg>`** + zero `lucide-react` |
| **não é ícone do Lucide** | nenhum import de ícone no arquivo | ausência medida |
| o valor vem do servidor, nunca de literal | `margem_minima.valor`; `20` e `8,5` conferidos (decimal em **vírgula**) | gate de ausência do literal `12` com **dois controles**: `0.12`/`12%`/`{margem: 12}`/`12.0` são ACUSADOS, e `"…:120"`/`1.12`/`112`/`"12.345.678/0001-99"` **não** |
| **ausente não imprime "0%" nem `·` solto** | 13 formas adversas de `margemMinima` → a linha INTEIRA não aparece | + **ISCA INVERTIDA**: com valor medido a linha aparece inteira (prefixo, `12%`, `·`, frase, ⓘ) |
| a `origem` diz de onde o número vem (FIN-09) | `title` por origem, em pt-BR, **diferente** para `config` e `parametro_da_empresa` | `assertNotEqual` dos dois títulos; origem desconhecida aparece **COMO CHEGOU**; origem não-string não vira `title` nenhum |

⚠️ **O zero NÃO desliga o bloqueio:**
`ComposicaoFinanceiraService::margemMinimaComOrigem()` cai na config quando o
parâmetro é zero — então zero na prop é dado que **não deveria existir**, e a
linha não aparece em vez de afirmar "0%", uma mentira sobre o dinheiro do
cliente. **O resolvedor foi CHAMADO** (via `FilaDeAprovacaoService::margemMinima`,
187-02), e o território prova `ComposicaoFinanceiraService.php` **VAZIO**.

---

## 7. ⚠️ As DIVERGÊNCIAS, com `arquivo:linha`

### D-187-04-A — o separador `·` **não tem espaço** em volta (`Aprovacao.dc.html:40`)

O `<behavior>` do plano pede a linha *"byte a byte"* como
`"Margem mínima: " + valor + " · " + frase`. **Medido:**

```
Margem mínima: <span style="color:#F4F4F5;font-weight:600">12%</span><span style="color:#3F3F46">·</span>Toda oferta precisa …
```

Não há espaço literal nenhum. O espaçamento de 8px vem do `gap:8px` do bloco PAI
(`:38`), que é `display:flex` e transforma cada nó de texto em **item anônimo** —
são **cinco** itens flex. ➡️ **O protótipo ganha:** escrever os espaços daria 8px
de gap **mais** dois espaços tipográficos. Gate com as duas asserções (o protótipo
tem `</span><span …>·</span>Toda oferta` e **não** tem `" · Toda oferta"`).

### D-187-04-B — o peso **800** entra por `font-[800]`, não por `font-extrabold` (`:39`)

O plano prescreve `font-extrabold`. ⚠️ Mas
`tests/js/publicador-vocabulario-novo.test.js` (GATE-03, Fase 177) **PROÍBE** a
escala nomeada — `assert.doesNotMatch(fonte, /font-(thin|extralight|light|extrabold|black)\b/)`
— em **todo** arquivo de `ARQUIVOS_NOVOS`. E o E1 tem `800` **medido**
(`187-BASELINES.md` §5: `font-weight | 500 600 700 800`).

➡️ **`font-[800]`**: o MESMO peso computado, por valor arbitrário, sem a escala
nomeada que o gate veta e **sem arredondar para `font-bold` (700)**, que seria
FID-03 literal. ⚠️ Valor arbitrário que **não compila** é o precedente medido de
`duration-[Nms]`, então há asserção no **CSS construído**:
`font-[800] → font-weight:800`, com controle de valor recusando `700`.
**Mutação M7** (`font-[800]` → `font-bold`): **MORREU**.

### D-187-04-C — a raiz do protótipo (`:25`) **não existe** nesta montagem

O plano prescreve `min-h-screen min-w-[1240px] bg-pub-page` para a raiz. ⚠️ No
protótipo aquele `<div>` **é o documento** — uma página HTML solta. Aqui a tela
vive dentro do `AppLayout`, que já dá altura e cor de fundo, e **`AppLayout.jsx` é
território PROIBIDO** nesta fase.

**Medido nas duas páginas irmãs que estão NO AR:**

| página | `-mx-6` | `min-h-screen` | `bg-pub-page` |
|---|---|---|---|
| `Pages/Mlb/Publicador/VisaoGeral.jsx:75`-`:76` | ✅ | **não** | **não** |
| `Pages/Mlb/MeusAnuncios.jsx` | ✅ | **não** | **não** |

➡️ Segui a montagem das irmãs e **preservei o `min-width:1240px`** no container do
corpo, exatamente como elas (FID-05). `bg-pub-page` (#0A0A0A) daria a ESTA tela um
fundo diferente de todas as outras do módulo, e `min-h-screen` somaria 100vh ao
header que vem acima — **nenhum dos dois é conferível nesta máquina**. ⚠️ Há gate
de AUSÊNCIA das duas classes nesta página **com o precedente das duas irmãs
medido ao lado**, para que ninguém as "conserte" de volta sem decisão nova.

### D-187-04-D — SETE dos doze ponteiros de linha do plano estavam DESLOCADOS

Medidos com `grep -n` nesta sessão:

| elemento | plano | **MEDIDO** |
|---|---|---|
| raiz (`min-height:100vh`) | `:25` | `:25` ✓ |
| `<main>` | `:29` | `:29` ✓ |
| `<header>` do bloco | `:30` | `:30` ✓ |
| coluna do H1 | `:31` | `:31` ✓ |
| H1 "Publicações" | `:32` | `:32` ✓ |
| bloco da DIREITA | `:37` | **`:38`** ⚠️ |
| o ⓘ | `:38` | **`:39`** ⚠️ |
| o valor `12%` e o `·` | `:39` | **`:40`** ⚠️ |
| grid da faixa | `:43` | **`:44`** ⚠️ |
| `<button>` do card | `:45` | **`:46`** ⚠️ |
| rótulo + dot do card | `:46` | **`:47`** ⚠️ |
| número do card | `:47` | **`:48`** ⚠️ |
| cores ativo/inativo (`stages`) | `:1217` | `:1217` ✓ |

(O `187-BASELINES.md` D4 já havia corrigido o ⓘ para `:39`; os outros seis são novos.)

### D-187-04-E — ⚠️ a OITAVA normalização do medidor de CSS construído

O helper `seletorDe()` herdado das waves anteriores escapava apenas `[].:/%`, e
com ele `grid-cols-[repeat(9,minmax(0,1fr))]` saía **AUSENTE** no CSS — embora a
classe esteja lá, compilada, com o seletor
`.grid-cols-\[repeat\(9\,minmax\(0\,1fr\)\)\]` (medido no `app-C34qCe1a.css`).
➡️ O gate **reprovava o código CERTO**, e o conserto barato seria apagar a
asserção do grid — perdendo justamente a classe mais cheia de metacaracteres da
tela. **O escape virou GERAL** (tudo que não é `[A-Za-z0-9_-]`), com controle
próprio. Vale para qualquer `calc(…)`, `repeat(…,…)`, `minmax(…)`.

---

## 8. ⚠️ Os dois furos das PRÓPRIAS sondas, achados pelos controles

| furo | o que acontecia | como foi achado | conserto |
|---|---|---|---|
| `includes('Todas')` acusava a flag **`ehTodas`** | o gate de "nenhum dos 9 rótulos copiado na fonte" reprovava o código CERTO | o próprio `assert.deepEqual([...])` apontou `['Todas']` | sonda virou `contarLiteralCompleto` com `\p{L}` (forma copiada do gate dos eixos), **com auto-teste nas duas direções**: acha `Todas` como palavra, **não** acha dentro de `ehTodas` |
| a sonda do `12` como percentual não casava `{ margem: 12 }` | o CONTROLE POSITIVO reprovou: `12` seguido de ` }` não estava na classe de terminadores | o controle `const p = { margem: 12 };` | `}` entrou na classe `[;,)\]}]` — é a forma mais comum de literal em objeto de props no JSX |

Mais um, no gate ALHEIO (ver §11): a sonda da pílula de `publicador-header-nas-paginas.test.js`.

**São a família "armadilha 3" da lista:** *teste toda regex contra um caso que
DEVE casar*. Os três foram pegos pelos controles, não por sorte.

---

## 9. Os gates — os cinco ids, todos pelo harness

⚠️ Todo veredicto saiu de `sh tests/gates/verificar-testes.sh`. **Nenhum
`node --test` nem `artisan test` foi lido como gate** (§4 casos 12 e 13); as
invocações diretas durante o desenvolvimento serviram só de iteração.

```
tests/gates/contagens-187-04.txt
  cabecalho-da-fila    js   publicador-fila-cabecalho-187.test.js                      tests=99,85    PASSOU
  faixa-de-andamento   js   publicador-fila-cabecalho-187.test.js                      tests=99,85    PASSOU
  pagina-e-contagem    js   publicador-fila-cabecalho-187.test.js + vocabulario-novo    tests=535,521  PASSOU
  pagina-rota          php  6 métodos                                tests=6 assertions=170          PASSOU
  bijecao-manifesto    php  test_bijecao_do_manifesto_187_04         tests=1 assertions=5            PASSOU
tests/gates/contagens-187-02.txt
  bijecao-manifesto    php  test_bijecao_do_manifesto_187_02         tests=1 assertions=8 (era 7)    PASSOU
```

**DOIS REGIMES medidos** (não precaução): o arquivo de gate tem **duas** camadas
de CSS construído (uma do cabeçalho, uma da faixa), e editar qualquer fonte as
deixa mais novas que o `app-*.css`. Pristino **99** / mutado **70** com duas
fontes, e **85** depois que a PÁGINA entrou em `FONTES_DO_CSS`.

⚠️ **A redundância declarada:** `cabecalho-da-fila` e `faixa-de-andamento`
apontam para o MESMO arquivo com a MESMA contagem. Os dois ids existem porque o
`<verify>` das tarefas 1 e 2 do plano os nomeia e **as duas tarefas escrevem no
mesmo arquivo de gate** (é o que o plano manda em `<files>`). Dois arquivos de
teste duplicariam os helpers de árvore e o render adverso — duas verdades para a
mesma sonda. **O que discrimina as tarefas é o COMMIT, não o id.** Está escrito no
cabeçalho do manifesto.

### A bijeção ids ↔ métodos, nos dois sentidos

- **JS:** a bijeção ids ↔ **arquivos** vive no gate do 187-03 e segue valendo; aqui
  o arquivo de gate deste plano está declarado em três ids e o `--suite js`
  confirma que ele roda.
- **PHP:** `test_bijecao_do_manifesto_187_04` — **1 caso / 5 asserções**. Universo
  = os métodos `_187_04` do arquivo, lidos do disco; filtros = as linhas `php` do
  manifesto; **uma** asserção de contagem fixa coletando infratores nos dois
  sentidos (método SEM ID / ID ÓRFÃO), com isca de que os dois lados têm conteúdo.

---

## 10. ⚠️⚠️ A OITAVA ocorrência de "gate mais largo que o invariante" — e a cirurgia com veto

`test_bijecao_do_manifesto_187_02` varria **todos** os métodos `test_*` dos dois
arquivos e exigia o sufixo `_187_02` em **todos** eles. No instante em que esta
wave acrescentou métodos `_187_04` **ao mesmo arquivo** (o plano 187-04 manda
fechar ali a pendência da asserção de componente resolvido), aquele gate passaria
a **reprovar trabalho CORRETO**.

**O que mudou:**
1. o universo varrido é recortado pelo **SUFIXO** `_187_02`;
2. a mecânica foi **extraída** para `bijecaoDoManifesto($manifesto, $arquivos, $sufixo)`
   — a wave 4 **reusa o mesmo medidor** em vez de copiá-lo (duas cópias
   divergiriam, e a 2ª seria a que ninguém conferiria);
3. ⚠️ **o buraco que o recorte abriria está FECHADO**: um método com sufixo de um
   plano **sem manifesto** ficaria fora das duas bijeções. Quem o pega é
   **`test_todo_metodo_tem_sufixo_de_plano_declarado_187_04`**, que varre o arquivo
   INTEIRO e exige que todo sufixo visto (`_187_NN`) tenha
   `tests/gates/contagens-187-NN.txt` no disco — com isca de um manifesto
   inexistente (`contagens-187-99.txt`).

⚠️ **O número do manifesto do 187-02 foi RE-MEDIDO: 7 → 8 asserções**, com a razão
escrita no próprio manifesto. **Não é "ajustar número para passar":** o que o gate
prova ficou **estritamente maior** (antes exigia um sufixo fixo; agora exige
sufixo de plano **com manifesto**), e a mudança está em diff visível.

**Três outras cirurgias com veto, da mesma família, em `publicador-vocabulario-novo.test.js`:**

| onde | antes | agora |
|---|---|---|
| "os 3 arquivos da Fase 186 estão na lista" | contava `startsWith(DIR_APROV).length === 3` — media o **DIRETÓRIO** achando que media a **FASE** | `DA_FASE_186` com os 3 NOMES, infratores coletados, + caminho fantasma, + a prova de que `CabecalhoDaFila.jsx` está em `ARQUIVOS_NOVOS` e **fora** de `DA_FASE_186` |
| `VOC-03` ("lucro líquido") | `ARQUIVOS_DO_PORTAO.length === 3` | os 3 NOMES da 186 + `length >= 3` + a PÁGINA da fila na varredura |
| `D2` (status do ML em `aprovacao/`) | varria só o DIRETÓRIO | + sub-teste varrendo a **PÁGINA** (que não mora nele), com âncora positiva e **isca** plantando um `'Pausada'` sintético |

É a **mesma** cirurgia que o 187-03 fez em `DA_FASE_180`, pelo mesmo motivo
medido: contagem de diretório obriga **todo plano da fase seguinte** a subir o
número — a régua que sobe a cada plano, e que aceitaria a REMOÇÃO de um arquivo da
fase antiga compensada pela criação de um da nova.

---

## 11. Desvios do plano

### Regra 3 (bloqueio) — `tests/js/publicador-header-nas-paginas.test.js`

**Não estava no plano.** Aquele gate faz `deepEqual` da pasta
`Pages/Mlb/Publicador/` contra a tabela dele (*"as 6 páginas do Publicador estão
todas aqui — nenhuma entra calada"*), e a página nova **reprovava a suíte inteira**
(`--suite js` deu 2 falhas). ⚠️ O gate reprovou por um motivo **LEGÍTIMO**, e a
resposta é a declaração — não o afrouxamento.

**O que entrou:**
1. `Aprovacao.jsx` na tabela, com `aba: 'publicacoes'` (decisão do 187-02: a fila é
   aba INTERNA da tela de Publicações, e `CabecalhoPublicadorService::ABAS` é lista
   fechada de cinco). ⚠️ **Com isso a PÁGINA ganhou RENDER REAL**, com `cabecalho`
   adverso campo por campo — era o único pedaço desta wave que o gate do próprio
   plano provava só pela FONTE.
2. a página passa **`liberada`** ao header: aquele gate exige, para toda página com
   header, o selo *"Publicação ainda não liberada"* na linha 1 quando a conta está
   travada (REG-01, contrato da Fase 177). ⚠️ **O controller AINDA NÃO manda
   `liberada`**, e o default `true` é o MESMO do `PublicadorHeader` — **nada muda de
   comportamento hoje**. É ponto de extensão DECLARADO (a trava de conta pesa na
   APROVAÇÃO, que é do 187-09/193), não dado inventado.
3. ⚠️ **reaponte com veto na sonda da pílula**: ela exigia que NADA seguisse o
   literal `"Publicações"` no header, e isso valia **por acidente** — nenhuma das 6
   páginas tinha a aba Publicações como **ATIVA**. A fila tem, e a aba ativa ganha o
   **sublinhado** amarelo, um `<span aria-hidden="true">` **vazio** logo depois do
   rótulo. A sonda o confundia com a pílula de contador e **reprovava o código
   certo**. O invariante não foi afrouxado, ficou mais preciso (*o que não pode
   aparecer é `<span>` com TEXTO*), com **isca nas duas direções**: aceita o
   sublinhado, **recusa** uma pílula com `"0"`.

### Regra 3 — a `etapa` no controller (fora de `files_modified`)

O `<action>` da Tarefa 3 pede explicitamente *"o invariante de FILA-03, no servidor
(`FilaDeAprovacaoService` + **a action**)"*, mas o `files_modified` do plano não
lista o controller. ⚠️ **`FilaDeAprovacaoService` é somente leitura** (serviço da
wave 2), então a `etapa` nasceu **na action**, de forma **aditiva**: uma constante,
um método privado e uma chave no payload. A `faixa` **não mudou** — ela já era
contada sobre a `$base`, e o que este plano acrescentou foi a **prova**.

### Nenhum outro desvio

Nenhuma WIP alheia precisou do procedimento de reconstrução por âncora (a árvore
tinha só `??` de outras sessões, e todos os commits saíram por **pathspec**).
Nenhum `git add -A`, `stash`, `reset`, `checkout`, `clean` ou `amend`.
Nenhuma migration. Nenhum `pint`. Nenhum deploy, push ou comando no VPS.
Nenhuma chamada à API real do Mercado Livre (o gate do 187-02 prova ZERO, com isca).

---

## 12. A campanha de MUTAÇÃO — 18 aplicadas, **18 mortas, 0 sobreviventes**

Protocolo: commit **ANTES**; `cp` para o scratchpad; ocorrência **travada em 1**
(com a linha conferida e impressa); restauração **DA CÓPIA** em `trap EXIT`;
`sha256` contra `git show HEAD:` **antes e depois**. ⚠️ **`git checkout` NUNCA.**

| # | mutação | arquivo:linha | gate | resultado |
|---|---|---|---|---|
| M1 | `repeat(9,…)` → `repeat(8,…)` | `FaixaDeAndamento.jsx:140` | faixa | **MORREU** |
| M2 | tira o `gap-[16px]` do `<main>` | `Aprovacao.jsx:217` | página | **MORREU** |
| M3 | dot de `todas` → `bg-pub-accent` | `FaixaDeAndamento.jsx:108` | faixa | **MORREU** |
| M4 | faixa contada sobre o recorte da ETAPA | `Controller:155` | pagina-rota | **MORREU** |
| M5 | tira a PÁGINA de `ARQUIVOS_NOVOS` | `vocabulario-novo:233` | página | **MORREU** |
| M6 | tira a guarda `is_string` | `Controller:98` | pagina-rota | **MORREU** |
| M7 | `font-[800]` → `font-bold` | `CabecalhoDaFila.jsx:186` | cabeçalho | **MORREU** |
| M8 | tira o `cursor-pointer` dos 9 cards | `FaixaDeAndamento.jsx:142` | faixa | **MORREU** |
| M9 | clique manda o RÓTULO para a URL | `FaixaDeAndamento.jsx:207` | faixa | **MORREU** |
| M10 | zero deixa de sair em `txt-off` | `FaixaDeAndamento.jsx:184` | faixa | **MORREU** |
| M11 | margem ausente inventa `0%` | `CabecalhoDaFila.jsx:150` | cabeçalho | **MORREU** |
| M12 | tira o `pb-[12px]` (D2) | `CabecalhoDaFila.jsx:185` | cabeçalho | **MORREU** |
| M13 | a aba ATIVA vira a da LISTA | `CabecalhoDaFila.jsx:220` | cabeçalho | **MORREU** |
| M14 | slot sem `isValidElement` | `Aprovacao.jsx:177` | página | **MORREU** |
| M15 | dot copiado à mão em vez de lido do módulo | `FaixaDeAndamento.jsx:180` | faixa | **MORREU** |
| M16 | inventa a 6ª aba no menu global | `Aprovacao.jsx:208` | página | **MORREU** |
| M17 | o clique não manda a etapa para a URL | `Aprovacao.jsx:187` | página | **MORREU** |
| M18 | tira um método do manifesto (ele nunca roda) | `contagens-187-04.txt:99` | bijeção | **MORREU** |

### ⚠️⚠️ O ACHADO DO M5 — a predição do plano estava INVERTIDA

O plano dizia: *"tirar a página do `ARQUIVOS_NOVOS` tem de reprovar pelo GATE-03
**só nos diretórios fechados** — se NÃO reprovar, é a prova de que a declaração
explícita era necessária"*.

**Ela reprovou** — mas **NÃO** pelo `deepEqual` de `DIRETORIOS_FECHADOS` (o plano
estava certo nessa parte: `Pages/Mlb/Publicador/` **não** está na lista). Ela
morreu por **`not ok 26 — VOC-03 "lucro líquido" não existe em nenhum arquivo novo
do portão`**, porque eu acrescentei a página à varredura de VOC-03 **por nome**.

➡️ **A conclusão prática:** a declaração explícita **era** necessária (o diretório
não a cobre), **e agora ela tem guarda mecânica semântica** — a página não pode
sair da lista em silêncio. Sem o acréscimo em VOC-03, a única coisa que mudaria
seria a **contagem** do gate, e contagem é o tipo de kill que o executor seguinte
"conserta" subindo o número.

### Três mutações ABORTARAM antes de rodar — e isso é a trava funcionando

| tentativa | por que abortou |
|---|---|
| `ativa="aprovacao"` em `CabecalhoDaFila.jsx` | **2 ocorrências** (uma no docblock, uma no JSX) — reaplicada com a âncora indentada do JSX |
| `aba="publicacoes"` em `Aprovacao.jsx` | **2 ocorrências** (docblock + JSX) — reaplicada com a linha inteira da tag |
| `font-\[800\]` (regex no lugar de literal) | **0 ocorrências** — o `grep -F` não casa escape de regex |

⚠️ E a primeira tentativa do M5 abortou com *"aparece 13 vezes"*: o guard contava
**LINHAS** com `grep -c -F` sobre uma âncora **multi-linha**. Reaplicada com âncora
de uma linha. **A trava de ocorrência pegou os quatro casos** — e um deles
(`ativa="aprovacao"` no docblock) é exatamente a família *"comentário que explica
casa com o gate que conta"*.

---

## 13. Território — `test -e` antes de cada diff, por ESCOPO DE ASSUNTO

6 commits com o escopo `(187-04)` em `a47a8c39..HEAD`. **Arquivos tocados (10):**

```
app/Http/Controllers/MlbPublicadorAprovacaoController.php
resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx
resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx
resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php
tests/gates/contagens-187-02.txt
tests/gates/contagens-187-04.txt
tests/js/publicador-fila-cabecalho-187.test.js
tests/js/publicador-header-nas-paginas.test.js   ← desvio Regra 3 (§11)
tests/js/publicador-vocabulario-novo.test.js
```

**VAZIO (os 22 caminhos, todos com `test -e` antes):** os arquivos da 186 em
`Aprovacao/` (incluindo `AndamentoDaOfertaService.php` e
`ComposicaoFinanceiraService.php`), `Fila/FilaDeAprovacaoService.php`, os três
`.jsx` do portão, **todo o diretório `publicacoes/`** (a aba do 187-03 incluída),
`MeusAnuncios.jsx`, `AppLayout.jsx`, `app/Services/Creative`, `app/Mcp`,
`app/Support/Publicador`, `MlPublicacaoService.php`,
`publicador-header-zindex-ordem.test.js`, `estrutura-meus-anuncios.test.js`,
`ROADMAP.md`, `STATE.md`, os 4 arquivos de dependência (T-187-SC) e
`database/migrations`.

⚠️ **A fronteira, declarada:** **esta tela APROVA (e aprovar ENFILEIRA); ela NÃO
publica.** A escrita no Mercado Livre é da **Fase 193**. E **neste plano a página
só LÊ**: zero `router.post/put/patch/delete`, zero `axios`, zero `fetch(`, zero
`<form`, zero `localStorage` — com gate de ausência nas nove formas e o
`router.get` do filtro provado presente. A primeira escrita chega no **187-09**.

⚠️ **D1 preservado:** o protótipo monta o header em `z-index:50` (`:25`) e o
projeto manda (`filtros 10 < header 30 < menu lateral = painel 40`). O header não
foi tocado, e `publicador-header-zindex-ordem.test.js` tem `numstat` **ZERO**.

---

## 14. O D2 nas duas direções, com isca

⚠️ **Nenhum status do Mercado Livre aparece na minha tela.** O gate do 187-03
varre o diretório `aprovacao/` (que agora inclui os meus dois componentes) e esta
wave acrescentou o sub-teste que varre a **PÁGINA** — que não mora nele e era,
portanto, o arquivo com mais chance de receber um `"Ativa"`/`"Pausada"` copiado da
lista.

| camada | prova |
|---|---|
| âncora positiva | a leitura da página acha `"Aguardando aprovação"` — ela não está cega |
| negativa | nenhum dos `ROTULOS_DO_ML` aparece na página (infratores coletados) |
| **isca** | a MESMA sonda, sobre a MESMA página **+ um `'Pausada'` plantado**, ACUSA |
| controle positivo (do 187-03) | os 4 rótulos do ML estão em `SeloDeStatusDaPublicacao.jsx`, onde eles moram |

E o sentido inverso continua: os 8 rótulos internos não aparecem em
`publicacoes/`, com a exceção nomeada de UM rótulo em UM arquivo (a aba, 187-03).

Na faixa há ainda a prova de que **as 5 chaves INGLESAS do protótipo**
(`all`, `awaiting`, `ecf_review`, `changes`, `published`) **não marcam card
nenhum** — nada de aceitar "as duas formas" num `||`, que foi achado como defeito
GRAVE na verificação da Fase 186. E no servidor elas caem no default `todas`.

---

## 15. O que este plano NÃO entregou, de propósito

- **A tabela, os filtros, o "PRÓXIMO A REVISAR", o lote e o rodapé** — os quatro
  slots nascem **nomeados e VAZIOS**, e o gate prova que, sem eles, o HTML tem
  apenas cabeçalho e faixa.
- **O estado VAZIO da fila.** Decisão 16 do usuário: sem filtro, *"ainda não há
  ofertas aguardando aprovação"*, **sem botão**; com filtro, o literal do protótipo
  com "Limpar filtros". ➡️ Os dois são do **187-09**, e esta página **não escreve
  vazio nenhum** — há gate de ausência de cinco literais, com controle positivo.
  ⚠️ Escrever *"Nenhuma oferta neste filtro"* sem filtro é a mentira que aquela
  decisão proíbe.
- **O sticky dos filtros** (`top-[108px]`, nunca `top-0`) — registrado no docblock
  da página porque é ela que compõe, mas é do **187-06**.
- **Nenhum token novo, nenhum tamanho novo.** Os 5 tokens da wave 1 seguem em skip
  nomeado (§3) e o `22` do número já estava liberado.
- **Nenhum dado de exemplo.** A fila nasce VAZIA (nada cria `PubAprovacaoOferta`
  hoje; quem passa a criar é a **Fase 194**), o contador é **0** real, e a faixa
  **não é escondida** quando tudo é zero.

---

## 16. ⚠️ O que eu NÃO posso afirmar

**Não vi esta tela renderizada.** O banco local não tem empresa com `ml_token` e
`ml_acervo_itens` está vazia — a rota dá **404** nesta máquina. Tudo nesta SUMMARY
é `renderToStaticMarkup` + CSS construído lido do manifest + `artisan test`. A
conferência visual é do usuário, **em produção, na conta 459**.

O que a wave 4 acrescentou de render REAL: a página montada com o header global e
`cabecalho` adverso campo por campo (via o gate de outra fase, §11) — antes dela,
só a FONTE da página era medida.

---

## 17. Pendências NOMEADAS para as waves seguintes

1. **A metade de FILA-03 que falta** (*"`?etapa=` recorta a LISTA"* e
   *"`?responsavel=N` move os dois"*) nasce no plano que trouxer a lista
   (**187-06/07/08**). A `etapa` já chega saneada à tela.
2. **`liberada` no payload da fila.** A página já a repassa ao header; o controller
   ainda não a manda (default `true`, o mesmo do header). Quem precisar do selo de
   conta travada na fila liga a prop no `index()`.
3. **O estado vazio** (os dois literais honestos) — **187-09**.
4. **Os 5 tokens da wave 1** acordam nas waves **6** (popovers), **7/8** (tabela e
   anel parcial) e **9** (barra de lote e toast). O skip é nomeado e por token.

---

## 18. Uma pergunta para o usuário

⚠️ **Não é bloqueio — a wave está completa e verde.** Mas a resposta muda a wave 6:

> O `?etapa=` hoje só **acende o card** — ele não recorta consulta nenhuma, porque
> a lista da fila ainda não existe. Quando a tabela chegar (187-07/08), a etapa
> `publicado` deve recortar **"Publicado hoje"** (o recorte DO DIA, como a faixa
> conta) ou **todos os publicados**? A faixa e a lista discordariam na primeira
> opção se a lista mostrasse o histórico inteiro — e discordariam no número, que é
> exatamente o tipo de divergência que o usuário já recusou duas vezes nesta
> milestone.

---

## Self-Check: PASSADO

Arquivos criados (todos conferidos com `test -e`):

```
FOUND  resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
FOUND  resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx
FOUND  resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx
FOUND  tests/js/publicador-fila-cabecalho-187.test.js
FOUND  tests/gates/contagens-187-04.txt
```

Commits (`git log a47a8c39..HEAD`):

```
FOUND  bf22d958  test(187-04): o gate do cabecalho da fila — 6 blocos, duas divergencias medidas (RED)
FOUND  2f46f49c  feat(187-04): o cabecalho da fila — o H1 da lista, a aba ATIVA e a margem minima (GREEN)
FOUND  69743b3c  test(187-04): o gate da faixa de 9 — dot LIDO do modulo e flags no `.map()` (RED)
FOUND  b79dd102  feat(187-04): a faixa de 9 cards — dot LIDO do modulo, zero em txt-off (GREEN)
FOUND  1617d0b2  feat(187-04): a PAGINA da fila — a janela fechou, e a faixa conta sobre os DEMAIS filtros
FOUND  dc1ef635  fix(187-04): a pagina entra no gate de render das paginas do Publicador
```

Integridade pós-mutação: `sha256` dos 9 arquivos deste plano **idêntico** a
`git show HEAD:<arquivo>`, e `git diff HEAD` **vazio** em todos eles.

## TDD Gate Compliance

As duas tarefas com `tdd="true"` seguiram RED → GREEN, com o RED **medido** antes
do código existir (`bf22d958`: 1 teste / 0 pass / 1 fail; `69743b3c`: 48 testes /
47 pass / 1 fail). Não houve commit `refactor(…)`: nenhum dos dois componentes
precisou de limpeza depois do GREEN.
