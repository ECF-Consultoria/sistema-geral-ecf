# Fase 187 — baselines medidas ANTES de editar, e a tabela FID-01 do E1

> ⚠️ **Tudo neste arquivo foi MEDIDO nesta máquina, nesta sessão, no commit de partida
> `75b9f82f`** — antes de qualquer edição de produção do plano 187-01. Medir junto com as
> próprias edições contamina o "antes" (já aconteceu nas Fases 177 e 186).
>
> ⚠️ **Contagem ACIMA da baseline é ESPERADA; ABAIXO é regressão.** A sessão paralela
> acrescenta testes e a régua sobe sozinha. NUNCA "ajustar" um número para baixo:
> registrar a diferença.

---

## 1. As linhas de forma fixa

```
COMMIT: 75b9f82f
BUILD: exit 0 em 162s
MANIFEST: 489 entradas / 200 paginas / isca ausente
JS: 4836 / 4835 pass / 1 fail / 0 skipped
PHP: 2346 / 20243 / 1
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e
PROTOTIPO design_handoff_publicador/Publicacoes v2.dc.html: 659 linhas sha256 56e6c194b896151c
PROTOTIPO new_layout_publicador/Publicacoes v2.dc.html: 656 linhas sha256 6dcbb2f9ff8782e0
```

### Como cada uma foi medida

| linha | comando |
|---|---|
| `COMMIT` | `git rev-parse --short HEAD` — **primeira ação do executor** |
| `BUILD` | `npm run build` cronometrado com `date +%s` |
| `MANIFEST` | `node <script>.cjs public/build/manifest.json`, lendo o JSON com `require` + `Object.prototype.hasOwnProperty.call` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` |
| `PROTOTIPO` | `wc -l` + `sha256sum`, com `test -e` antes |

⚠️ **JS e PHP foram medidos SÓ pelo harness.** `node --test | grep "^# tests"` NUNCA passa no
Node v24 e `artisan test | grep -E "Tests:|OK"` PASSA COM TESTE FALHANDO (learnings §4, casos 12
e 13). Nenhuma contagem deste arquivo veio de pipeline artesanal.

⚠️ **O `MANIFEST` foi lido pelo JSON, nunca por `grep`.** Medido nesta rodada: o hash do
`app.jsx` saiu **com HÍFEN** — `assets/app-Dv6-mbDf.js` — e um `grep -E "app-[A-Za-z0-9]*\.js"`
não casaria. A **isca** é a entrada inexistente `resources/js/Pages/Mlb/NaoExiste9z7.jsx`: ela
saiu **ausente**, então a sonda não está cega.

⚠️ E `resources/css/app.css` **não é chave do manifest** — o CSS entra por
`manifest['resources/js/app.jsx'].css = ["assets/app-BZcBiEAW.css"]`. Quem for conferir CSS
construído lê por aí, não por uma chave `resources/css/app.css` que não existe.

⚠️ **A suíte PHP foi rodada em BACKGROUND e nenhum arquivo de produção foi editado enquanto
ela rodava** (o PHPUnit carrega lazy; editar no meio contamina a medição — lição do 186-01).

---

## 2. As DUAS falhas nomeadas por SEMÂNTICA — nenhuma corrigida

### 2.1 JS — 1 falha, PRÉ-EXISTENTE, Fase 87

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
```

Localizada por semântica (`grep -rln` do texto do `test()`), não por número de linha:
**`tests/js/estrutura-grade-glide.test.js`**.

⚠️ **NÃO corrigida, de propósito.** É a falha única que `REF_JS_FALHAS=1` já aceita desde a
Fase 186, e consertá-la não é trabalho desta fase.

### 2.2 ⚠️⚠️ PHP — 1 falha que NÃO existia no fechamento da 186, e NÃO é desta fase

O fechamento da Fase 186 mediu, em 15/10/2026, **PHP 2346 / 20261 / 0**. Nesta sessão, no
commit `75b9f82f` e **antes de qualquer edição minha**, a medida é **2346 / 20243 / 1**:

```
FAILED  Tests\Feature\Publicador\Aprovacao\VinculoDuravelDaOriginalTest
        > terr 11 a retencao de 48h e o creative engine estao intocados por range
        tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php:410
        Failed asserting that two strings are identical.
        - ''
        + 'app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php'
```

**Causa medida, não presumida:**

```
$ git log --oneline 1c16d9e9..HEAD -- app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php
67d6a005 feat(196): fotos do Publicador gravam no disco imagens e leem do local como fallback
```

O gate é um **gate de território por RANGE** da Fase 186 (`PARTIDA = '1c16d9e9'`, declarada no
próprio arquivo de teste). A **sessão paralela** commitou a Fase 196 **dentro desse range** e
tocou um arquivo que, para a Fase 186, é território do outro dev. É **exatamente** o caso já
escrito no learnings §4 — *"o gate por range da FASE acusa o ORQUESTRADOR e as SESSÕES
PARALELAS"*: o recorte certo é por **ESCOPO DO ASSUNTO** (`feat(186-…)` × `feat(196)`), não por
range puro.

⚠️⚠️ **NÃO corrigida, e de propósito, por três razões:**

1. O arquivo acusado — `PublicadorCriativoReferenciaService.php` — está **explicitamente
   proibido** a esta fase (Creative Engine / retenção de 48 h, território do outro dev).
2. O teste é prova de uma fase **em produção** (a 186 foi deployada em `a4c4187f`). Reapontar
   um gate da 186 para "fazer passar" é a definição de *teste que para de provar algo*.
3. A queda de asserções (20261 → **20243**, 18 a menos) é consequência aritmética da falha: o
   método aborta na primeira asserção falha e perde as asserções seguintes do laço.

⚠️ **Consequência operacional para os dez planos seguintes:** `--suite php` sai com **exit 1**
neste commit, por essa falha herdada. **A régua de falhas do PHP NÃO foi afrouxada** — o
harness continua reprovando com `_f > 0`, porque criar um `REF_PHP_FALHAS` aceito seria abrir a
porta dos fundos que este plano existe para fechar. **A diferença é registrada aqui, não
silenciada.** Quem for comparar: `2346 / 20243 / 1` é o *antes* desta fase; subir asserções e
manter **1** falha é progresso; **2** falhas é regressão.

---

## 3. O estado do harness ANTES de eu mexer nele

```
$ sh tests/gates/verificar-testes.sh --autoteste | tail -2
AUTOTESTE: 14 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

E `REF_*` como estavam (medidos em `d4aeaa30`, 14/10/2026):
`REF_JS_TESTS=4482` · `REF_JS_FALHAS=1` · `REF_PHP_TESTS=2103`.

---

## 4. Os três protótipos — e qual é a autoridade

| arquivo | linhas | sha256 (16) | papel |
|---|---|---|---|
| `design_handoff_publicador/Aprovacao.dc.html` | 1432 | `68624883ce35547e` | ⚠️ **AUTORIDADE** da fila "Aguardando aprovação" (E1-E5) |
| `design_handoff_publicador/Publicacoes v2.dc.html` | 659 | `56e6c194b896151c` | ⚠️ **AUTORIDADE** da aba de Publicações (seção A) |
| `new_layout_publicador/Publicacoes v2.dc.html` | 656 | `6dcbb2f9ff8782e0` | versão ANTIGA — **a que está no ar** (Fase 180), e a **ERRADA** para esta fase |

Os três hashes estão aqui para que **nenhuma medida de fidelidade desta fase seja feita contra
um protótipo que se moveu no meio**. Os dois primeiros batem, byte a byte, com os hashes que o
fechamento da Fase 186 registrou em 15/10/2026.

### ⚠️ ACHADO NOVO — as duas cópias NÃO diferem "exatamente na seção A1"

O plano afirma que as duas `Publicacoes v2.dc.html` *"diferem exatamente na seção A1"*. Medido
por `diff`: são **4 hunks**, e dois deles são da **seção A2** do README (*"Renomear o status do
Mercado Livre"*), não da A1.

| hunk | linhas | seção | o que muda |
|---|---|---|---|
| 1 | `:31,32` × `:31,32` | A1 | `border-bottom:1px solid #1F1F1F` entra no `<header>`; `gap:6px` para **`gap:14px`** |
| 2 | `:34` × `:34,37` | A1 | o **subtítulo** (`font-size:13.5px`, *"Ofertas ativas e históricas…"*) é **substituído pelas DUAS ABAS** (`gap:20px`, `margin-bottom:-1px`, `border-bottom:2px solid #FACC15` na ativa) |
| 3 | `:458` × `:461` | **A2** | `ST.pending[0]`: `'Em revisão'` para **`'Em análise pelo Mercado Livre'`** |
| 4 | `:531` × `:534` | **A2** | `tabsDef`: `['pending', 'Em revisão']` para **`['pending', 'Em análise pelo ML']`** |

➡️ Quem implementar a aba (seção A) precisa dos **quatro**, não dos dois primeiros.

⚠️ E o docblock de `publicacoes/CabecalhoDePublicacoes.jsx` cita `new_layout_publicador/…` — é a
referência **certa** para o que está no ar e a **ERRADA** para esta fase.

---

# 5. A TABELA FID-01 — o E1 INTEIRO, medido por SCRIPT

> ⚠️⚠️ **MEDIDA POR SCRIPT, não por leitura.** Tabela de leitura não é confiável neste projeto
> (medido em 13/10/2026). O script está colado abaixo, inteiro, e a varredura cobre o **E1
> INTEIRO** — não só o que o plano 187-01 usa — porque é a referência que os **dez** planos
> seguintes consomem.
>
> **Protótipo:** `design_handoff_publicador/Aprovacao.dc.html`, linhas **`:26`-`:205`** (180
> linhas), sha256 `68624883ce35547e`. O recorte foi conferido: `:203`-`:204` fecham
> `</main></sc-if>` e `:206` abre o `isDetail` (E4). Bate com a seção **E1** do
> `README-v2.md:161`-`:202`.

### ⚠️ A REGRA DE OURO

**Quando a tabela discordar do protótipo, MEÇA no `.dc.html` e o PROTÓTIPO GANHA**; a divergência
vai na SUMMARY com `arquivo:linha`.

⚠️ **EXCEÇÃO DECLARADA:** o protótipo ganha na **FÓRMULA**, nos **RÓTULOS** e na **ORDEM** —
**NUNCA nos NÚMEROS**. As taxas de `fin()` são ficção do próprio protótipo
(`Aprovacao.dc.html:1064`-`:1066`, *"taxa de exemplo"*, `src:'est'`), e a seção F do
`README-v2.md` é **prosa**: só `info()` decide bloqueio.

### O script (colado inteiro)

```sh
#!/bin/sh
# Varredura FID-01 do E1 de design_handoff_publicador/Aprovacao.dc.html (linhas 26-205).
# Uso: sh varrer-e1.sh <arquivo.dc.html>
set -eu
ARQ="$1"
E1=$(mktemp); sed -n '26,205p' "$ARQ" > "$E1"
val() {   # $1 = rotulo exibido, $2 = regex ERE da propriedade
    printf '%-26s ' "$1"
    grep -oE "$2" "$E1" | sed -E "s/^[^:=]*[:=]\s*//; s/^[\"']//; s/[\"']$//" \
      | sed -E 's/^ +//; s/ +$//' | sort -u | tr '\n' '|' | sed 's/|$//'
    printf '\n'
}
val "font-size"             'font-size:[^;"]*'
val "font-weight"           'font-weight:[^;"]*'
val "border-radius"         'border-radius:[^;"]*'
val "height"                '(^|[;"])height:[^;"]*'
val "min-height"            'min-height:[^;"]*'
val "width"                 '(^|[;"])width:[^;"]*'
val "min-width"             'min-width:[^;"]*'
val "gap"                   '(^|[;"])gap:[^;"]*'
val "padding"               '(^|[;"])padding:[^;"]*'
val "background"            'background:[^;"]*'
val "border"                '(^|[;"])border:[^;"]*'
val "border-color"          'border-color:[^;"]*'
val "border-bottom"         'border-bottom:[^;"]*'
val "border-top"            'border-top:[^;"]*'
val "border-left"           'border-left:[^;"]*'
val "color"                 '(^|[;"])color:[^;"]*'
val "grid-template-columns" 'grid-template-columns:[^;"]*'
val "letter-spacing"        'letter-spacing:[^;"]*'
val "line-height"           'line-height:[^;"]*'
val "z-index"               'z-index:[^;"]*'
val "cursor"                'cursor:[^;"]*'
val "transition"            'transition:[^;"]*'
val "animation"             'animation:[^;"]*'
val "white-space"           'white-space:[^;"]*'
val "text-overflow"         'text-overflow:[^;"]*'
val "font-variant-numeric"  'font-variant-numeric:[^;"]*'
val "viewBox"               'viewBox="[^"]*'
val "stroke-width"          'stroke-width:[^;"]*'
val "stroke-width (attr)"   'stroke-width="[^"]*'
echo "--- contagens ---"
printf 'cursor:pointer ocorrencias   %s\n' "$(grep -oE 'cursor:pointer' "$E1" | wc -l | tr -d ' ')"
printf 'cursor (todas) ocorrencias   %s\n' "$(grep -oE 'cursor:[^;\"]*' "$E1" | wc -l | tr -d ' ')"
printf 'linhas do E1                 %s\n' "$(wc -l < "$E1" | tr -d ' ')"
rm -f "$E1"
```

⚠️ O `(^|[;"])` em `height`, `width`, `gap`, `padding`, `border` e `color` **não é zelo**: sem
ele `height:` casa dentro de `min-height:`/`line-height:`, `width:` dentro de `min-width:`/
`stroke-width:`, `color:` dentro de `background-color:`/`border-color:`, e `border:` dentro de
`border-top:`. É a família *"substring casa com prefixo"* do learnings §4 caso 4 — por isso
`min-height`, `min-width`, `border-color`, `border-bottom`, `border-top` e `border-left` têm
linha PRÓPRIA.

### A tabela (saída literal do script)

| propriedade | valores distintos no E1 |
|---|---|
| `font-size` | `9.5px` `10px` `10.5px` `11px` `11.5px` `12px` `12.5px` `13px` `13.5px` `14px` `15px` `22px` `28px` |
| `font-weight` | `500` `600` `700` `800` + 3 por binding (`{{ o.w }}`, `{{ r.modWeight }}`, `{{ r.titleWeight }}`) |
| `border-radius` | `1px` `2px` `3px` `4px` `6px` `7px` `8px` `9px` `10px` `12px` `14px` `50%` `999px` |
| `height` | `4px` `6px` `7px` `9px` `10px` `12px` `15px` `16px` `20px` `22px` `24px` `26px` `30px` `32px` `34px` `36px` `38px` `40px` `42px` `44px` `46px` `52px` `100%` |
| `min-height` | `28px` `64px` |
| `width` | `1.5px` `6px` `7px` `9px` `10px` `12px` `15px` `16px` `22px` `24px` `34px` `44px` `46px` `220px` `240px` `260px` `100%` + `{{ r.marginW }}` |
| `min-width` | `0` `22px` |
| `gap` | `1px` `2px` `3px` `4px` `5px` `6px` `7px` `8px` `10px` `12px` `14px` `16px` `20px` `24px` |
| `padding` | `0` `6px` `0 7px` `0 8px` `0 10px` `0 11px` `0 12px` `0 14px` `0 16px` `1px 5px` `2px 6px` `10px 12px` `11px 12px` `0 10px 0 32px` `0 16px 0 62px` `24px 36px 120px` `56px 24px` |
| `background` | `transparent` `#0D0D0D` `#0F0F0F` `#111111` `#14120A` `#151515` `#161616` `#1A1A1A` `#1F1F1F` `#22C55E` `#71717A` `#EAB308` `#F4F4F5` `#FACC15` `rgba(239,68,68,0.3)` `linear-gradient(90deg,#A1A1AA 50%,transparent 50%)` + **18** por binding |
| `border` | `none` `1px solid #262626` `1px solid #2E2E2E` `1px solid #3F3F46` `1px solid rgba(250,204,21,0.35)` `1.5px solid #A1A1AA` `1.5px solid #EF4444` `1.5px solid #FACC15` `1.5px solid #FBBF24` + **11** por binding |
| `border-color` | `#3F3F46` `#FACC15` (só em `style-hover`/`style-focus`) |
| `border-bottom` | `1px solid #1F1F1F` `2px solid #FACC15` `2px solid transparent` |
| `border-top` | `1px solid #1A1A1A` `1px solid #1C1C1C` `1px solid #1F1F1F` + `{{ g.border }}` |
| `border-left` | **(nenhum no E1)** — o `border-left:1px solid #2E2E2E` é do `<aside>` do E2, `:550` |
| `color` | `#0A0A0A` `#3F3F46` `#52525B` `#71717A` `#8A8A90` `#A1A1AA` `#D4D4D8` `#F4F4F5` `#FACC15` + **16** por binding |
| `grid-template-columns` | **`32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px`** (a linha da tabela, 11 colunas) e `repeat(9,minmax(0,1fr))` (a faixa de andamento, 9 cards) |
| `letter-spacing` | `-0.025em` `-0.02em` `0.05em` `0.06em` `0.08em` |
| `line-height` | `1` `1.2` |
| `z-index` | `45` `50` ⚠️ **ver D1** |
| ⚠️ **`cursor`** | **`pointer`, e SÓ ele. 16 ocorrências. Ver a coluna própria abaixo.** |
| `transition` | `background .15s` (1 ocorrência) |
| `animation` | `spin .8s linear infinite` ⚠️ **ver D-ANEL** |
| `white-space` | `nowrap` |
| `text-overflow` | `ellipsis` |
| `font-variant-numeric` | `tabular-nums` |
| `viewBox` | `0 0 16 16` (a lupa da busca) ⚠️ **ver D5** |
| `stroke-width` | `1.6` (em `style=`, não em atributo — o atributo sai vazio) |

### ⚠️⚠️ `cursor` — COLUNA PRÓPRIA

**Neste projeto o `cursor:pointer` foi omitido ONZE VEZES numa única fase.** A tabela de
fidelidade **não erra VALOR: erra por OMISSÃO e por REFERÊNCIA.** Por isso as 16 ocorrências do
E1 estão aqui uma por uma, medidas por script.

| linha | elemento | o que é |
|---|---|---|
| `:46` | `<button>` | cada um dos **9 cards** da faixa de andamento |
| `:62` | `<button>` | o CTA "Revisar" do card "Próxima da fila" |
| `:73` | `<button>` | cada opção do segmented de **modalidade** (3) |
| `:77` | `<button>` | cada opção do segmented de **formato** (3) |
| `:80` | `<button>` | o disparador do popover **Responsável** |
| `:84` | `<button>` | cada linha do popover de Responsável |
| `:93` | `<button>` | o disparador do popover **Motivo de bloqueio** |
| `:97` | `<button>` | cada linha do popover de Motivo de bloqueio |
| `:104` | `<button>` | "Limpar filtros" da barra (só com `anyFilter`) |
| `:110` | `<button>` | o **checkbox do cabeçalho** da tabela (16×16) |
| `:130` | `<button>` | "Revisar fotos do conjunto", na linha de grupo |
| ⚠️ **`:133`** | **`<div>`** | **A LINHA DA TABELA INTEIRA.** O único `cursor:pointer` do E1 que **não** está num `<button>` — e o mais fácil de omitir, porque quem monta a linha como `<div>` de grid esquece o cursor. É o mesmo elemento que carrega o `grid-template-columns` de 11 colunas. |
| `:134` | `<button>` | o **checkbox da linha** (16×16) |
| `:177` | `<button>` | o CTA por linha (`r.onCta`) |
| `:188` | `<button>` | "Limpar filtros" do estado VAZIO |
| `:200` | `<button>` | "Restaurar dados de exemplo", no rodapé |

⚠️ **E o inverso, medido:** **nenhum** elemento com `onClick=` no E1 está SEM `cursor:pointer`.
A regra é uniforme — então um elemento clicável sem cursor na implementação é divergência, nunca
"o protótipo também não tem".

---

## 5.1 As 10 divergências do plano — TODAS CONFIRMADAS, nenhuma "corrigida"

⚠️ Cada uma foi reconferida nesta sessão. **Nenhuma foi corrigida em direção ao protótipo**:
três delas são decisão do usuário ou invariante do projeto.

⚠️⚠️ **E as REFERÊNCIAS do plano estão deslocadas em 9 dos 16 ponteiros.** As corretas, medidas
com `grep -n`, estão na coluna da direita. É literalmente o modo de falhar que esta tabela
existe para evitar.

| # | divergência (confirmada) | ref. do plano | ⚠️ ref. MEDIDA |
|---|---|---|---|
| **D1** | ⚠️⚠️ **A escala de `z-index` do protótipo é INCOMPATÍVEL com a travada do projeto.** No E1 só aparecem `45` (popovers) e `50` (header). No arquivo inteiro: `40`, `44`, `45`, `50`, `60`, `69`, `70`, `90`, `100`, `120`. A ordem travada aqui é `filtros 10 < header 30 < menu lateral = painel 40` (`tests/js/publicador-header-zindex-ordem.test.js`), invariante medido do bug de 12/10. ➡️ **O remapeamento PRESERVA A ORDEM RELATIVA e NUNCA copia os números.** | `:27` (50), `:546` (44), `:88`/`:98` (45), `:897` (60), `:905` (120) | **`:26`** (50), `:546` (44), **`:82`/`:95`** (45), `:897` (60), `:905` (120). ⚠️ **E o plano OMITE cinco:** `:207` (40), `:549` (69), `:550` (70), `:727` (90), `:775` (100) |
| **D2** | O `<main>` da fila é `padding:24px 36px 120px; gap:16px`; o de Publicações é `padding:28px 36px 110px; gap:18px`. **NÃO harmonizar.** | `Aprovacao:29` × `Publicacoes v2:28` | `Aprovacao:29` ✓ × **`Publicacoes v2:29`** |
| **D3** | A linha com FOCO é `#1A1A1A` na fila e `#1C1C1C` na lista de Publicações. **NÃO unificar.** (Medido: o ternário da fila é `panelId → #221D07 : focusId → #1A1A1A : checked → #18160C : #111111` — **quatro** ramos; o de Publicações tem **cinco**, com `isNew → #14120A`.) | `Aprovacao:1249` × `Publicacoes v2:553` | **`Aprovacao:1250`** × **`Publicacoes v2:556`** |
| **D4** | O ⓘ da margem mínima **não é ícone**: é um `<span>` redondo de 16px com a letra `i`, `font-size:10px`, `font-weight:800`, `background:#1F1F1F`, `color:#D4D4D8`. | `:38` | **`:39`** (`:38` é o `<div>` que o embrulha) |
| **D5** | A lupa da busca é SVG inline com `viewBox="0 0 16 16"` e `stroke-width:1.6` — Lucide é `0 0 24 24` e exigiria `strokeWidth × 1,5`. (Medido: `<svg width="14" height="14">`, `<circle cx=7 cy=7 r=4.5>` + `<path d="M10.5 10.5L14 14">`, `stroke:#8A8A90`, `stroke-linecap:round`, `pointer-events:none`, `position:absolute;left:11px`.) | `:70` | **`:68`** |
| **D6** | O popover "Motivo de bloqueio" tem `hint-placeholder-count="6"` no template, mas `BLK` declara **8** motivos (`photos`, `alert`, `loss`, `margin`, `freight`, `adjust`, `change`, `failed`). **O DADO GANHA DO PLACEHOLDER.** | `:98` × `:973` | **`:96`** × `:973` ✓ |
| **D7** | O `title` do mini-quadrado em `regenerating` diz **"a conferir"** — o ternário de `sq()` só distingue `approved` e `com alerta` —, embora a borda seja **`1.5px dashed #71717A`**. **Reproduzir o literal e registrar.** | `:1116`-`:1122` | **`:1115`-`:1122`** (o `sq()` abre em `:1115`; o ternário do `title` é `:1117`; o ramo `regenerating` é `:1119`) |
| **D8** | `countLabel` é `list.length + ' de ' + all.length + ' ofertas'` — o **M** é o total de TODAS as ofertas da conta (inclusive `preparando` e `publicado`), não o das `live`. | `:1275` | **`:1276`** (e o consumo na tela é `:105`) |
| **D9** | `ids` (que o checkbox do cabeçalho marca) **inclui** as ofertas em `preparando`; só `_nav` as exclui (`this._nav = ids.filter(… status !== 'preparing')`). | `:1218` (`ids.push`) × `:1264` (`_nav`) | **`:1226`** × **`:1263`** |
| **D10** | O traço do mínimo na barra de margem está em `left:30%` **porque `MIN = 12` e `12/40 = 30%`**. A prescrição é a **FÓRMULA** `min(40,max(0,minimo))/40`, que dá 30% com 12 e continua certa com outro mínimo (FIN-09). (Medido: o `left:30%` aparece **3 vezes** — `:163` no E1, `:417` no E4 e `:645` no E2 — e `MIN = 12` está em `:914`.) | `:164` e `:914` | **`:163`** (+ `:417`, `:645`) e `:914` ✓ |

### ⚠️ Uma 11ª divergência, já declarada pelo plano e confirmada: o anel que gira

O protótipo diz `animation:spin **.8s** linear infinite` (`Aprovacao.dc.html:171`); o token
`animate-pub-spin` é **`.7s`**. ➡️ **Herdar a divergência declarada é o certo** — ela já está
escrita dentro de `SeloDeAndamentoInterno.jsx` (Fase 186, **somente leitura**). Criar um `.8s`
ao lado criaria **duas verdades**. ⚠️ Por isso este plano **NÃO criou token de animação**.

## 5.2 Divergências NOVAS, medidas nesta sessão

| # | o quê | `arquivo:linha` |
|---|---|---|
| **N1** | As duas cópias de `Publicacoes v2.dc.html` diferem em **4 hunks**, não só na seção A1: duas são da **A2** (o renome do status do ML). Ver §4 deste documento. | `new_layout_publicador/Publicacoes v2.dc.html:458`/`:531` × `design_handoff_publicador/Publicacoes v2.dc.html:461`/`:534` |
| **N2** | ⚠️⚠️ **Os 5 tokens novos NÃO podem compilar no CSS neste plano.** O `content` do Tailwind é `['./resources/js/**/*.jsx', …]` e este plano não escreve `.jsx` nenhum. **Prova medida:** depois de acrescentar os 5 tokens e rodar `npm run build`, o CSS saiu **byte-idêntico** — mesmo hash `app-BZcBiEAW.css`, mesmos **227.384 bytes**, e `grep -c` de cada uma das 5 classes = **0**. O precedente que fecha o raciocínio: `shadow-pub-painel` existe na camada desde a Fase 177, **0** `.jsx` o usam e ele **não** está no CSS. ➡️ O gate de CSS do plano virou **skip NOMEADO por token**, com controle positivo + isca + isca invertida, e **acorda sozinho** no plano que escrever a classe. | `tailwind.config.js:8`-`:13` (`content`) × `tests/js/publicador-fila-tokens-187.test.js` |
| **N3** | O `cursor:pointer` de `:133` está num **`<div>`**, não num `<button>` — o único do E1 fora de botão, e é a LINHA INTEIRA da tabela. O plano pedia "coluna própria" e estava certo: é esse o que se omite. | `Aprovacao.dc.html:133` |
| **N4** | `border-left` **não existe no E1** (a varredura saiu vazia). O `border-left:1px solid #2E2E2E` que se poderia esperar é do `<aside>` do **E2**. Varredura vazia aqui é medida, não descuido. | `Aprovacao.dc.html:550` |
| **N5** | `resources/css/app.css` **não é chave do `manifest.json`**. Quem conferir CSS construído tem de ler `manifest['resources/js/app.jsx'].css[0]`. Uma sonda que procurasse a chave `resources/css/app.css` pularia sempre, com razão falsa. | `public/build/manifest.json` |

---

## 6. O que este plano NÃO fez, de propósito

- **Nenhum token de animação.** O `.8s` do protótipo × `.7s` do `animate-pub-spin` é divergência
  **já declarada** em `SeloDeAndamentoInterno.jsx` (Fase 186, somente leitura). Dois tokens =
  duas verdades.
- **Nenhum token de raio.** Os valores que faltam no E1 (`8px`, `3px`, `2px`, `1px`) vão por
  classe arbitrária (`rounded-[8px]`), que é o que `publicacoes/VaziosDaLista.jsx` já faz. Token
  só quando o valor casa **exatamente** um `pub-*` existente.
- **Nenhum tamanho "por precaução".** Dos 13 valores de `font-size:` do E1, **11** já estavam em
  `TAMANHOS_PERMITIDOS`. Entraram **só** os 2 que faltavam. Tamanho liberado sem uso medido é o
  que torna o gate inútil.
- **Nenhuma tela.** Nada em `aprovacao/`, nada em `publicacoes/`.
- **Nenhum `REF_PHP_FALHAS`.** Ver §2.2.
