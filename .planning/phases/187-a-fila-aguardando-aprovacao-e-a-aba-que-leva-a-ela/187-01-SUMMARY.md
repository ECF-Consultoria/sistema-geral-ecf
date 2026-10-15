---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 01
subsystem: publicador-fila-de-aprovacao
tags: [baselines, gates, harness, tokens, tailwind, fidelidade, fid-01]
requires: []
provides:
  - "187-BASELINES.md — as 4 baselines medidas em 75b9f82f + a tabela FID-01 do E1 inteiro, por SCRIPT"
  - "o harness resolvendo `<fase>-<plano>` (187-NN) sem perder os 163 ids da Fase 186"
  - "tests/gates/contagens-187-01.txt — o primeiro manifesto da fase"
  - "5 tokens novos na camada pub-*: sunken-2, float, float-line, pub-anel-parcial, pub-flutuante"
  - "9.5 e 22 liberados em TAMANHOS_PERMITIDOS, com a varredura completa escrita ao lado"
affects:
  - "os dez planos seguintes da Fase 187 (todos chamam `verificar-testes.sh 187-NN <id>`)"
tech-stack:
  added: []
  patterns:
    - "gate de CSS em DUAS CAMADAS: config sempre + CSS com skip NOMEADO por token, que acorda sozinho"
    - "resolução de manifesto por `<fase>-<plano>` com compatibilidade retroativa provada por ISCA"
key-files:
  created:
    - .planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela/187-BASELINES.md
    - tests/gates/contagens-187-01.txt
    - tests/js/publicador-fila-tokens-187.test.js
  modified:
    - tests/gates/verificar-testes.sh
    - tailwind.config.js
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-tokens-do-publicador.test.js
decisions:
  - "O gate de CSS dos 5 tokens virou skip NOMEADO por token: medido que `content` só varre *.jsx e que o CSS sai BYTE-IDÊNTICO sem consumidor"
  - "NÃO criar REF_PHP_FALHAS para calar a falha herdada da sessão paralela — a régua não foi afrouxada"
  - "Nenhuma das 10 divergências do plano foi corrigida em direção ao protótipo"
metrics:
  duration: ~3h30
  completed: 2026-10-15
---

# Phase 187 Plano 01: As baselines, o harness da fase e os tokens da fila — Summary

Mediu o terreno antes de mexer nele e deixou de pé as três ferramentas dos dez planos
seguintes: o harness resolvendo `187-NN` **sem perder os 163 ids da Fase 186**, os 5 tokens da
fila na camada `pub-*`, `9.5`/`22` liberados com a varredura medida ao lado, e a **tabela FID-01
do E1 inteiro medida por SCRIPT** — com as 10 divergências confirmadas e **5 novas**, uma delas
invalidando o gate de CSS que o próprio plano pedia.

**Commit de partida: `75b9f82f`** (capturado na primeira ação). **Meu último commit: `3baa56ac`.**

---

## 1. ⚠️ AS 4 BASELINES — MEDIDAS ANTES DE EDITAR UM BYTE

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

| baseline | comando EXATO |
|---|---|
| `COMMIT` | `git rev-parse --short HEAD` — **primeira ação da sessão** |
| `BUILD` | `T0=$(date +%s); npm run build; echo "exit=$? em $(( $(date +%s) - T0 ))s"` |
| `MANIFEST` | `node <script>.cjs "C:/.../public/build/manifest.json"` com `require` + `Object.prototype.hasOwnProperty.call` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` (em **BACKGROUND**, sem editar produção enquanto rodava) |
| `PROTOTIPO` | `wc -l` + `sha256sum`, com `test -e` antes |

⚠️ **A suíte PHP rodou em background e nenhum arquivo de produção foi tocado enquanto ela
rodava** (lição do 186-01: o PHPUnit carrega lazy).
⚠️ **JS e PHP só pelo harness** — `node --test | grep "^# tests"` nunca passa no Node v24 e
`artisan test | grep "Tests:|OK"` passa com teste falhando (learnings §4, casos 12 e 13).
⚠️ **O MANIFEST pelo JSON, com isca.** Medido: o hash do `app.jsx` saiu **com hífen**
(`app-Dv6-mbDf.js`); a isca `resources/js/Pages/Mlb/NaoExiste9z7.jsx` saiu **ausente**.

### As duas falhas, nomeadas por SEMÂNTICA e NÃO corrigidas

| suíte | falha | arquivo (por `grep -rln` do texto do `test()`) | veredicto |
|---|---|---|---|
| JS | `not ok 191 - Características secundárias nasce recolhido` | `tests/js/estrutura-grade-glide.test.js` | **PRÉ-EXISTENTE (Fase 87). NÃO corrigida.** |
| PHP | `VinculoDuravelDaOriginalTest > terr 11 a retencao de 48h e o creative engine estao intocados por range` | `tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php:410` | ⚠️ **NOVA desde o fechamento da 186, e NÃO é minha. NÃO corrigida.** |

⚠️⚠️ **A falha de PHP é um gate de território por RANGE da Fase 186 acusando a SESSÃO PARALELA.**
Causa **medida**, não presumida:

```
$ git log --oneline 1c16d9e9..HEAD -- app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php
67d6a005 feat(196): fotos do Publicador gravam no disco imagens e leem do local como fallback
```

É o caso literal do learnings §4 (*"o gate por range da FASE acusa o ORQUESTRADOR e as SESSÕES
PARALELAS"*). **Não consertei**, por três razões: (1) o arquivo acusado é território proibido a
esta fase (Creative Engine / retenção de 48 h); (2) o teste é prova de uma fase **em produção**
(deploy `a4c4187f`) e reapontá-lo seria *teste que para de provar algo*; (3) a queda de
asserções (20261 → 20243) é aritmética da própria falha.

➡️ **E NÃO criei `REF_PHP_FALHAS`.** O harness continua reprovando com `_f > 0`. Criar um
limiar de falha aceito seria abrir exatamente a porta dos fundos que este plano existe para
fechar. **A diferença está registrada, não silenciada.** 👉 **Pergunta para o orquestrador no §9.**

---

## 2. O HARNESS — a saída do `--autoteste` e a prova dos 163 ids

### A mudança (mínima e aditiva)

Nova função `caminho_do_manifesto()` em `tests/gates/verificar-testes.sh`:

```sh
caminho_do_manifesto() {
    _fp=$(printf '%s' "$1" | sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p')
    if [ -n "$_fp" ]; then echo "${RAIZ}/tests/gates/contagens-${_fp}.txt"
    else                  echo "${RAIZ}/tests/gates/contagens-186-$1.txt"; fi
}
```

`MANIFESTO_FORCADO` **permanece** com precedência; manifesto inexistente continua abortando.
⚠️ `case` com glob não distingue `187-01` de `18-7-01`; o `sed` distingue — daí o `sed`.

### ⚠️ A saída do `--autoteste` (14 → 16 casos, 0 divergência)

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 5: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 6: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 7: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 8: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 9: No tests found. -> REPROVA (como esperado)
  OK   caso 10: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 11: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 12: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 13: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 14: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 15: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 16: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 16 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

**O que precisaria estar errado no mundo para cada caso novo falhar:**
- **caso 11** — alguém voltar a cravar a fase no caminho, ou o `contagens-187-01.txt` desaparecer.
- **caso 12 (ISCA)** — alguém "simplificar" a resolução exigindo sempre o prefixo da fase. Aí os
  163 ids da 186 param de resolver — e o caso reprova **antes** de o gate errado rodar calado.

### ⚠️ A PROVA de que os 163 ids da Fase 186 continuam funcionando

| prova | resultado |
|---|---|
| **(a) resolução dos 9 manifestos pelo número NU** — `sh … NN __id_que_nao_existe__` tem de abortar nomeando `contagens-186-NN.txt` | **9/9 OK** |
| **(b) bijeção dos 163 ids**, pela MESMA expressão do harness (`grep -vE '^\s*(#|$)' \| awk '$1==id'`) | **163 ids conferidos / 0 falhas** |
| **(c) ISCA de separação** — nenhum id da 186 cai no manifesto da 187 | 159 ids distintos na 186 × 3 na 187-01, **interseção `[]`** |
| **(d) os 6 ids `js` da 186, RODADOS nas DUAS formas** (`01 X` e `186-01 X`) | **12/12 `PASSOU`, saída idêntica** |
| **(e) 1 id `php` da 186, RODADO nas DUAS formas** (`08 bijecao-manifesto` / `186-08 …`) | **2/2 `Tests: 1 passed (36 assertions)` → `PASSOU`** |
| **(f) ISCA inversa** — `sh … 01 tokens-da-fila` | `ABORTOU: id 'tokens-da-fila' nao esta em …/contagens-186-01.txt` ✓ |

Os 6 `js` rodados: `01 gate-rename` (58) · `01 rename-e-reapontes` (820) · `02 vocabulario-modulos`
(180,169) · `02 vocabulario-render` (180,169) · `02 voc-07-gate-03` (399,399) · `09 transversais-render` (14).

### `REF_*` elevados MEDINDO, com os antigos REMOVIDOS

| constante | antes (d4aeaa30) | agora (medido em `75b9f82f`) |
|---|---|---|
| `REF_JS_TESTS` | ~~4482~~ | **4836** |
| `REF_JS_FALHAS` | 1 | **1** (não muda — a pré-existente nomeada) |
| `REF_PHP_TESTS` | ~~2103~~ | **2346** |

⚠️ Os antigos **saíram** (learnings §4b.2): mantê-los aceitos faria o gate passar se alguém
**apagasse** os 243 testes que a Fase 186 acrescentou.

### `tests/gates/contagens-187-01.txt` — 3 ids, todos MEDIDOS

| id | alvo | contagem medida |
|---|---|---|
| `tokens-da-fila` | `tests/js/publicador-fila-tokens-187.test.js` | `tests=16,9` |
| `vocabulario-tamanhos` | `tests/js/publicador-vocabulario-novo.test.js` | `tests=399` |
| `camada-de-tokens-177` | `tests/js/publicador-tokens-do-publicador.test.js` | `tests=13` |

⚠️ **Os DOIS regimes de `tokens-da-fila` foram MEDIDOS de verdade**, não presumidos — a camada de
CSS pula quando o CSS é mais antigo que o config, e mutar o config o deixa mais novo:
- pristino (CSS mais novo): **16 testes / 11 pass / 0 fail / 5 skipped**
- mutado (config mais novo): **9 testes / 7 pass / 0 fail / 2 skipped**

Os três gates rodados: `PASSOU` nos três. Não há gate `php` neste plano — ele não toca PHP, e
inventar um número seria pior que não ter.

---

## 3. OS 5 TOKENS — `arquivo:linha` do protótipo e por que não serve o parecido

**Protótipo de autoridade: `design_handoff_publicador/Aprovacao.dc.html`** (sha256
`68624883ce35547e`) — ⚠️ **nunca** `new_layout_publicador/`, que é a versão antiga.

| token | valor | medido em (⚠️ `grep -n`, não a linha do plano) | por que não serve o parecido |
|---|---|---|---|
| `colors.pub['sunken-2']` | `#0F0F0F` | **`:124`** ✓ — fundo da sub-linha de conjunto de fotos (faixa de 38px, `border-top:1px solid #1C1C1C`) | `sunken` é `#0D0D0D` (cabeçalho/rodapé da tabela) e `raised-0` é `#131313`: a sub-linha fica **ENTRE** os dois, e o protótipo pinta os três distintos |
| `colors.pub.float` | `#1C1C1C` | **`:897`** ✓ (barra flutuante de lote) e **`:905`** ✓ (toast) | o hex já tem **dois** nomes na camada, e nenhum serve: `line-subtle-2` é **BORDA** e `row-focus` é **LINHA DE TABELA** — precedente declarado de `neutral-dot`/`txt-4` |
| `colors.pub['float-line']` | `#333333` | **`:897`, `:898`** ✓ (divisor `border-right` do rótulo) e **`:905`** ✓ | `seg-ring` é o `inset 0 0 0 1px` da opção ativa do segmented — outro uso e outra propriedade |
| `backgroundImage['pub-anel-parcial']` | `linear-gradient(90deg,#A1A1AA 50%,transparent 50%)` | **`:1241`** ✓ (`PG.partial`) e **`:194`** ⚠️ (**o plano cita `:201`**, que é um `</div>`) | gradiente não tem token nenhum; escrito na tela viraria hex cru, que TOK-15 e o gate de vocabulário reprovam (precedente de `pub-hachura`) |
| `boxShadow['pub-flutuante']` | `0 16px 40px rgba(0,0,0,0.6)` | **`:82`, `:95`** ⚠️ (**o plano cita `:88`/`:98`**), **`:897`, `:905`** ✓ | `pub-popover` é `0 24px 60px rgba(0,0,0,0.7)` e `pub-painel` é `-24px 0 64px` — deslocamento, difusão e alfa diferentes; FID-03 proíbe arredondar |

⚠️ **Nenhum hex literal** foi escrito em arquivo de tela (este plano não escreve nenhum) e
**nenhum dos vetados** (`ecf-bg`, `ecf-card`, `ecf-yellow`, `font-display`,
`border-white/[0.08]`) foi usado — conferido por `grep` nos arquivos do plano.

### ⚠️ O que NÃO ganhou token, de propósito

- **Animação:** o `.8s` do protótipo (`:171`) × `.7s` do `animate-pub-spin` é divergência **já
  declarada** dentro de `SeloDeAndamentoInterno.jsx` (Fase 186, **somente leitura**). Herdar a
  divergência declarada é o certo; criar um `.8s` ao lado criaria **duas verdades**.
- **Raio:** os valores que faltam (`8px`, `3px`, `2px`, `1px`) vão por classe arbitrária
  (`rounded-[8px]`), que é o que `publicacoes/VaziosDaLista.jsx` já faz.

---

## 4. OS 2 TAMANHOS LIBERADOS — com a varredura COMPLETA ao lado

```
Varredura de `font-size:` no E1 de design_handoff_publicador/Aprovacao.dc.html
(linhas :26-:205, POR SCRIPT, 15/10/2026):
9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 22, 28.
Faltavam na lista: 9.5 e 22. Os outros ONZE já estavam.
```

| valor | onde aparece no E1 (⚠️ medido com `grep -n`) | ref. do plano |
|---|---|---|
| **`9.5px`** | **3 vezes**, todas glifo em círculo pequeno: iniciais do avatar de 22px do popover de Responsável (**`:86`**), glifo do anel de progresso de 16px (**`:135`** ✓), iniciais do avatar de 24px da coluna Responsável (**`:166`**) | `:91`/`:135`/`:168` — **2 das 3 deslocadas** |
| **`22px`** | **1 vez só**: o NÚMERO de cada um dos 9 cards da faixa de andamento (**`:48`**, com `line-height:1`, `font-weight:600`, `letter-spacing:-0.02em`, `font-variant-numeric:tabular-nums`) | `:49` — **é o `</button>`** |

**21 → 23 valores. Nada saiu.** Sem a liberação, o plano da faixa teria de arredondar para
`text-[24px]` ou `text-xl` (20px) — o que FID-03 proíbe.
⚠️ **E nada entrou "por precaução":** os onze valores restantes da varredura já estavam na lista.
Tamanho liberado sem uso medido é exatamente o que torna o gate inútil.

**A prova é MECÂNICA** (`TAM-187` em `publicador-fila-tokens-187.test.js`): contagem **FIXA**
(21 → 23) **mais** a inclusão do conjunto antigo no novo — porque `contagem += 2` sozinha
passaria se alguém trocasse dois valores por dois. Mais **ISCA INVERTIDA** (lista não-vazia,
com os marcos `8.5`/`13.5`/`15.5` das Fases 179/180) e um gate que exige a **medida escrita ao
lado**, citando o **diretório** do protótipo.

---

## 5. A TABELA FID-01 — E1 inteiro, 21 varreduras, POR SCRIPT

**Mora em `.planning/phases/187-…/187-BASELINES.md` §5**, com o **script colado inteiro** e a
**regra de ouro** escrita (o protótipo ganha; exceção declarada: ele ganha na FÓRMULA, nos
RÓTULOS e na ORDEM — **nunca nos NÚMEROS**, porque as taxas de `fin()` são ficção do próprio
protótipo, `:1064`-`:1066`, *"taxa de exemplo"*, `src:'est'`).

⚠️⚠️ **DECLARO que medi por SCRIPT**, não por leitura. O comando:

```sh
sh varrer-e1.sh "design_handoff_publicador/Aprovacao.dc.html"
# internamente: E1=$(mktemp); sed -n '26,205p' "$ARQ" > "$E1"
#               grep -oE "<propriedade>:[^;\"]*" "$E1" | sed … | sort -u
```

O recorte `:26`-`:205` (180 linhas) foi **conferido**: `:203`-`:204` fecham `</main></sc-if>` e
`:206` abre o `isDetail` (E4). Bate com a seção **E1** do `README-v2.md:161`-`:202`.

**As 21 varreduras pedidas, todas feitas:** `font-size` · `font-weight` · `border-radius` ·
`height`/`min-height` · `width` · `gap` · `padding` · `background` · `border` · `color` ·
`grid-template-columns` · `letter-spacing` · `z-index` · **`cursor`** · `transition` ·
`animation` · `white-space` · `text-overflow` · `font-variant-numeric` · `viewBox` +
`stroke-width`. **Mais 6 por conta própria** (`min-width`, `line-height`, `border-color`,
`border-bottom`, `border-top`, `border-left`), porque `height:` casa dentro de `min-height:`,
`width:` dentro de `stroke-width:`, `color:` dentro de `background-color:` e `border:` dentro de
`border-top:` — a família *"substring casa com prefixo"*.

### ⚠️⚠️ `cursor` — EM COLUNA PRÓPRIA

**16 ocorrências no E1, todas `cursor:pointer`, listadas uma por uma** na tabela: `:46` `:62`
`:73` `:77` `:80` `:84` `:93` `:97` `:104` `:110` `:130` **`:133`** `:134` `:177` `:188` `:200`.

➡️ **O achado: `:133` é o ÚNICO que não está num `<button>` — é um `<div onClick>`, e é A LINHA
INTEIRA DA TABELA** (o mesmo elemento que carrega o `grid-template-columns` de 11 colunas). É
exatamente o que se omite quando se monta a linha como `<div>` de grid.
➡️ **E o inverso, medido:** **nenhum** elemento com `onClick=` no E1 está sem `cursor:pointer`.
A regra é uniforme — logo, elemento clicável sem cursor na implementação é divergência, nunca
"o protótipo também não tem".

---

## 6. ⚠️ AS 10 DIVERGÊNCIAS — TODAS CONFIRMADAS, NENHUMA CORRIGIDA

Cada uma reconferida nesta sessão. **Nenhuma foi "corrigida" em direção ao protótipo.**

| # | confirmada? | ref. do plano | ⚠️ ref. MEDIDA |
|---|---|---|---|
| **D1** z-index incompatível | ✅ | `:27`, `:546`, `:88`/`:98`, `:897`, `:905` | **`:26`**, `:546` ✓, **`:82`/`:95`**, `:897` ✓, `:905` ✓ — ⚠️ **e o plano OMITE cinco:** `:207` (40), `:549` (69), `:550` (70), `:727` (90), `:775` (100) |
| **D2** `<main>` diferente | ✅ | `Aprov:29` × `Pub v2:28` | `Aprov:29` ✓ × **`Pub v2:29`** |
| **D3** foco `#1A1A1A` × `#1C1C1C` | ✅ (medido: a fila tem **4** ramos no ternário, Publicações tem **5**) | `:1249` × `:553` | **`:1250`** × **`:556`** |
| **D4** ⓘ é `<span>` com `i` | ✅ (`font-size:10px`, `bg #1F1F1F`, `color #D4D4D8`) | `:38` | **`:39`** (`:38` é o `<div>` que embrulha) |
| **D5** lupa SVG `0 0 16 16` | ✅ (`<svg width=14 height=14>`, `stroke-width:1.6`, `circle r=4.5`) | `:70` | **`:68`** |
| **D6** placeholder 6 × `BLK` 8 | ✅ (os 8: `photos`, `alert`, `loss`, `margin`, `freight`, `adjust`, `change`, `failed`) | `:98` × `:973` | **`:96`** × `:973` ✓ |
| **D7** `title` "a conferir" em `regenerating` | ✅ (borda `1.5px dashed #71717A`) | `:1116`-`:1122` | **`:1115`-`:1122`** (o `sq()` abre em `:1115`, o ternário é `:1117`, o ramo é `:1119`) |
| **D8** `countLabel` conta TODAS | ✅ | `:1275` | **`:1276`** (consumo na tela: `:105`) |
| **D9** `ids` inclui `preparando` | ✅ | `:1218` × `:1264` | **`:1226`** × **`:1263`** |
| **D10** `left:30%` por `MIN=12` | ✅ (o `left:30%` aparece **3×**: `:163`, `:417`, `:645`) | `:164` e `:914` | **`:163`** e `:914` ✓ |

⚠️⚠️ **A D1 ficou como está, e é a mais importante.** A escala do protótipo (`40 44 45 50 60 69
70 90 100 120`) é **incompatível** com a travada aqui (`filtros 10 < header 30 < menu lateral =
painel 40`, `tests/js/publicador-header-zindex-ordem.test.js`, invariante medido do bug de
12/10). ➡️ **O remapeamento PRESERVA A ORDEM RELATIVA e NUNCA copia os números.**

⚠️ **9 dos 16 ponteiros do plano estão deslocados.** As referências corretas estão acima e no
`187-BASELINES.md` §5.1. É literalmente o modo de falhar que a tabela existe para evitar — a
tabela **não erra VALOR: erra por OMISSÃO e por REFERÊNCIA**.

**11ª divergência, já declarada pelo plano e confirmada:** `animation:spin **.8s**`
(`Aprovacao.dc.html:171`) × `.7s` do `animate-pub-spin`. Herdada, não "corrigida".

---

## 7. ⚠️ DIVERGÊNCIAS NOVAS (5), com `arquivo:linha`

### N1 — As duas cópias de `Publicacoes v2.dc.html` NÃO diferem "exatamente na seção A1"

Medido por `diff`: **4 hunks**, e **dois são da seção A2** do README (*"Renomear o status do
Mercado Livre"*).

| hunk | linhas | seção | o que muda |
|---|---|---|---|
| 1 | `:31,32` × `:31,32` | A1 | entra `border-bottom:1px solid #1F1F1F`; `gap:6px` → **`gap:14px`** |
| 2 | `:34` × `:34,37` | A1 | o subtítulo `font-size:13.5px` dá lugar às **DUAS ABAS** (`gap:20px`, `margin-bottom:-1px`) |
| 3 | `:458` × **`:461`** | **A2** | `ST.pending[0]`: `'Em revisão'` → **`'Em análise pelo Mercado Livre'`** |
| 4 | `:531` × **`:534`** | **A2** | `tabsDef`: `['pending','Em revisão']` → **`['pending','Em análise pelo ML']`** |

➡️ Quem implementar a seção A precisa dos **quatro**, não dos dois primeiros.

### N2 — ⚠️⚠️ OS 5 TOKENS NÃO PODEM COMPILAR NESTE PLANO (e isso mudou o gate)

O plano pedia *"cada um dos 5 tokens novos tem regra no CSS construído"* e o `key_links` apontava
`pub-sunken-2|pub-float|pub-anel-parcial` em `app-*.css`. **É impossível neste plano**, e não por
descuido: o `content` do Tailwind é `['./resources/js/**/*.jsx', …]`
(`tailwind.config.js:8`-`:13`) e **este plano não escreve `.jsx` nenhum**.

**PROVA MEDIDA, em três camadas:**

1. O **precedente**: `shadow-pub-painel` existe na camada desde a Fase 177, **0** `.jsx` o usam
   e ele **não** está no CSS (`grep -c` = 0). Já `bg-pub-raised-0` tem 1 `.jsx` e **está** (= 1).
2. Depois de acrescentar os 5 tokens e rodar `npm run build`, o CSS saiu **BYTE-IDÊNTICO**:
   mesmo `assets/app-BZcBiEAW.css`, mesmos **227.384 bytes**.
3. `grep -c` de cada uma das 5 classes no CSS: **0, 0, 0, 0, 0**.

Escrever a asserção como o plano pedia a tornaria **vazia hoje e falso positivo depois** — é o
§4b.1 do learnings em estado puro.

➡️ **O gate virou DUAS CAMADAS:**
- **config, roda SEMPRE** — por `import()` do `tailwind.config.js` de verdade (nunca `grep`, que
  contaria a prosa em pt-BR que cita os próprios hex). Contagem fixa colhendo infratores, mais um
  gate dos **7 VIZINHOS** de que cada token foi distinguido (o modo de falhar mais provável:
  atropelar o vizinho de 2 pontos de distância), mais isca + controle positivo.
- **CSS, pula NOMEADO por token** — e **acorda sozinho** no plano que escrever a classe. Para não
  ser decorativo enquanto dorme, carrega **controle positivo** (`bg-pub-raised-0`, que a sonda TEM
  de achar com `rgb(19 19 19 /`), **isca** (`bg-pub-isca-187a`, `shadow-pub-isca-187a`,
  inexistentes) e **ISCA INVERTIDA** (o precedente `shadow-pub-painel`, que reprova se alguém
  passar a usá-lo — mandando trocar o precedente, de propósito).

👉 **O `key_links` do plano 187-01 fica insatisfeito por construção. Quem ativar o gate é o plano
que escrever a primeira classe.**

### N3 — `cursor:pointer` em `<div>`

`Aprovacao.dc.html:133` — o único `cursor:pointer` do E1 fora de `<button>`, e é a linha inteira
da tabela. O plano pediu coluna própria e estava certo: é esse o que se omite.

### N4 — `border-left` não existe no E1

A varredura saiu **vazia**. O `border-left:1px solid #2E2E2E` é do `<aside>` do **E2**
(`Aprovacao.dc.html:550`). Varredura vazia aqui é medida, não descuido.

### N5 — `resources/css/app.css` não é chave do `manifest.json`

O CSS entra por `manifest['resources/js/app.jsx'].css[0]`. Uma sonda que procurasse a chave
`resources/css/app.css` **pularia sempre, com razão falsa**.

---

## 8. `test -e` em TODOS os caminhos citados

**35 caminhos conferidos: 34 OK + 1 ausente** — e o ausente era `tests/gates/contagens-187-01.txt`,
que este plano **criou** (reconferido depois: OK). Cobre os 3 `.dc.html`, o `README-v2.md`, os 9
manifestos da 186, o harness, o `tailwind.config.js`, o `manifest.json`, o CSS construído, os 5
arquivos de teste, o teste PHP que falha, o serviço que ele acusa, os 2 diretórios de tela, os 3
módulos de leitura-apenas da 186 e **os caminhos de território proibido** (`app/Services/Creative`,
`app/Support/Publicador`, `resources/js/Components/Publicador/Mesa` — **sem** o `Mlb` —,
`Pages/Mlb/Publicador/Editor.jsx`, `app/Mcp`, `estrutura-meus-anuncios.test.js`).

---

## 9. ⚠️ PERGUNTAS E BANDEIRAS PARA O ORQUESTRADOR

1. ⚠️⚠️ **A suíte PHP tem 1 falha HERDADA e `--suite php` sai com exit 1 neste commit.** Não é
   minha, não é regressão desta fase, e **não a consertei** (território do outro dev + gate de uma
   fase em produção). **Decidir quem conserta:** o recorte do gate `terr_11` da Fase 186 por
   **escopo do assunto** (`feat(186-…)` × `feat(196)`) é a correção certa, mas mexer nele é entrar
   no território da 186. **Os dez planos seguintes vão ver esse vermelho.**
2. ⚠️ **O `key_links` do plano (`pub-sunken-2|pub-float|pub-anel-parcial` em `app-*.css`) é
   insatisfeito por construção** — ver N2. Sugiro reapontá-lo, nos planos seguintes, para o plano
   que escrever a primeira classe.
3. ⚠️ **O `must_haves.artifacts[0].contains` pede o literal `"PROTOTIPO Aprovacao.dc.html"`**, mas
   o corpo do plano prescreve `PROTOTIPO design_handoff_publicador/Aprovacao.dc.html:` — e o
   briefing manda **sempre citar o DIRETÓRIO** (há duas `Publicacoes v2.dc.html` com conteúdo
   diferente). **Mantive a forma COM diretório.** O `contains` literal não casa.
4. ⚠️ **A sessão paralela commitou DUAS vezes durante este plano:** `67d6a005 feat(196)` (antes do
   meu início, causa da falha PHP) e **`adb4609e feat(197)`** (depois do meu último commit,
   `3baa56ac`) — este último traz uma **migration nova pendente**
   (`database/migrations/2026_10_15_200000_create_acervo_imagens_table.php`) e mexe em
   `app/Support/Publicador/Imagem/`. **Eu não criei migration nenhuma.**
5. ⚠️ `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` **intocados**, como o briefing
   manda. **Não rodei `state.advance-plan`.**

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Regra 3 — Bloqueante] `publicador-tokens-do-publicador.test.js` teve de aprender os 5 tokens**

- **Found during:** Task 3 (GREEN), ao acrescentar os tokens ao `tailwind.config.js`.
- **Issue:** o arquivo **não estava** em `files_modified` do plano, mas faz
  `assert.deepEqual(Object.keys(pub).sort(), Object.keys(PUB_ESPERADOS).sort())` e o equivalente
  para `backgroundImage`. Acrescentar token sem entrar nas tabelas **reprova** aquele gate — que é
  da Fase 177 e está em produção.
- **Fix:** os 3 tokens de cor em `PUB_ESPERADOS`, `pub-anel-parcial` em `IMAGENS_ESPERADAS` e
  `pub-flutuante` em `SOMBRAS_ESPERADAS` (este último não tinha gate de conjunto, mas sem a
  entrada o token ficaria **sem gate nenhum** — o próprio arquivo diz *"todo token novo TEM de
  entrar na tabela com o valor medido"*). Cada um com o `arquivo:linha` do protótipo ao lado.
- **Files modified:** `tests/js/publicador-tokens-do-publicador.test.js` (+30/-0)
- **Commit:** `3baa56ac`
- **Conferido:** `camada-de-tokens-177` → 13 testes, 0 falha. ⚠️ **Conferi também que `float` e
  `float-line` NÃO quebram `hexComNome()`**: ele usa `if (!nomes.has(...))` e devolve o **primeiro**
  nome na ordem de declaração, então os dois hex duplicados continuam resolvendo para
  `line-subtle-2` e `seg-ring`. E `HEX_ISCA` é `#ABCDEF`, nenhum dos meus.

**2. [Regra 1 — Correção de desenho de gate] O gate de CSS dos 5 tokens virou skip NOMEADO**

- **Found during:** Task 3 (RED), ao medir o `content` do Tailwind e o precedente `shadow-pub-painel`.
- **Issue:** a asserção que o plano prescrevia é **vazia por construção hoje e falso positivo
  depois** (learnings §4b.1). Ver N2.
- **Fix:** duas camadas, com controle positivo + isca + isca invertida; o gate acorda sozinho.
- **Commit:** `d960cf70` (RED, com o achado escrito no docblock) e `3baa56ac`.

**3. [Regra 1 — Gate que reprovava pela própria prova] O escopo de `ANIM-187`**

- **Found during:** Task 3 (RED), 1ª execução.
- **Issue:** o gate *"nenhum arquivo deste plano escreve `duration-[`"* incluía o **próprio arquivo
  de teste**, que contém `duration-[160ms]` como **string de isca** → reprovava pela própria prova.
  É o padrão `feedback_gate_que_reprova_pelo_proprio_conserto` / `/disabled/` do learnings.
- **Fix:** escopo recortado em `tailwind.config.js` (o único arquivo deste plano que pode emitir
  classe), com a razão escrita e **4 iscas** garantindo que a sonda pega `duration-[`, `delay-[`,
  `ease-[` e **não** pega `duration-200 delay-150 ease-out`.
- **Commit:** `d960cf70`.

### Nenhuma arquitetural (Regra 4). Nenhum gate de autenticação. Nenhum pacote instalado.

---

## TDD Gate Compliance

| gate | commit | prova |
|---|---|---|
| **RED** | `d960cf70` `test(187-01): … — RED` | 16 testes / 7 pass / **4 fail** / 5 skipped — uma falha por entrega do plano |
| **GREEN** | `3baa56ac` `feat(187-01): … — GREEN` | 16 testes / **11 pass / 0 fail** / 5 skipped |
| REFACTOR | — | não necessário |

Sequência `test(...)` → `feat(...)` presente no `git log`, na ordem.

---

## Suítes: antes × depois

| suíte | ANTES (`75b9f82f`) | DEPOIS | leitura |
|---|---|---|---|
| JS | `4836 / 4835 pass / 1 fail / 0 skipped` | **`4852 / 4846 pass / 1 fail / 5 skipped`** | **+16** = exatamente o arquivo novo. A falha é a pré-existente. `--suite js` **exit 0**. |
| PHP | `2346 / 20243 / 1` | **`2347 / 20246 / 1`** | **+1 teste / +3 asserções sem eu tocar PHP** — é o `feat(197)` da sessão paralela, que commitou **durante** a medição. Mesma falha herdada. `--suite php` **exit 1** (ver §9.1). |
| `--autoteste` | `14 casos, 0 divergencia` | **`16 casos, 0 divergencia`** | os 2 casos novos, um deles ISCA. |

---

## Território e dependências (por RANGE, `75b9f82f..3baa56ac`)

⚠️ **O range é até o MEU último commit**, não até `HEAD`: a sessão paralela commitou `feat(197)`
depois, e o discriminador é o **ESCOPO DO ASSUNTO** (`feat(187-01)` × `feat(197)`) — **não o
autor**, que é o mesmo `user.name` nas duas sessões.

```
$ git diff --numstat 75b9f82f..3baa56ac
356  0  .planning/phases/187-…/187-BASELINES.md
 61  0  tailwind.config.js
 72  0  tests/gates/contagens-187-01.txt
 79  6  tests/gates/verificar-testes.sh
495  0  tests/js/publicador-fila-tokens-187.test.js
 30  0  tests/js/publicador-tokens-do-publicador.test.js
 37  1  tests/js/publicador-vocabulario-novo.test.js
```

**7 arquivos, nenhum fora do plano** (o único acréscimo é o do desvio 1, documentado acima).

| alvo | resultado |
|---|---|
| `package.json` `package-lock.json` `composer.json` `composer.lock` (T-187-SC) | **numstat VAZIO** — nada instalado |
| `app/` `resources/js/Components` `resources/js/Pages` `routes/` | **VAZIO** |
| `database/migrations` | **VAZIO** — ⚠️ esta fase não tem migration, e eu não criei nenhuma |
| `app/Services/Creative` · `app/Support/Publicador` · `Components/Publicador/Mesa` · `Pages/Mlb/Publicador/Editor.jsx` · `Layouts/AppLayout.jsx` · `app/Mcp` · `Services/Bling` · `Services/Portal` | **intocados** |
| `.planning/ROADMAP.md` · `.planning/STATE.md` · `.planning/REQUIREMENTS-v27.md` | **intocados** |
| `tests/js/estrutura-meus-anuncios.test.js` (D-11/D-22) | **intocado** |
| ⚠️ **ISCA INVERTIDA do range** | o range **ACHA** os 4 arquivos-chave (`tailwind.config.js`, o harness, o manifesto, o teste novo) — sem isso o "VAZIO" acima seria **vazio por construção** |

**Nada de deploy, push, VPS, leitura de produção, API real do ML, `pint`, worktree, `git stash`,
`git reset`, `git checkout`, `git clean`, `git add -A` ou `--amend`.** Todos os commits por
**pathspec**, com a mensagem acentuada por **`-F <arquivo>`**.
⚠️ `tests/Feature/CompanyPortfolioAccessTest.php` segue `??`, de 24/07, de outra sessão — **não
toquei**.
⚠️ **Não afirmo ter visto tela renderizada.** Este plano não desenha tela nenhuma; a conferência
visual é do usuário, em produção, na conta 459.

---

## Commits

| hash | assunto |
|---|---|
| `2dc03818` | `docs(187-01): as 4 baselines da fase, medidas ANTES de editar` |
| `d960cf70` | `test(187-01): o gate dos 5 tokens da fila e dos 2 tamanhos — RED` |
| `f086738b` | `feat(187-01): o harness resolve 187-NN e continua resolvendo os 163 ids da 186` |
| `3baa56ac` | `feat(187-01): os 5 tokens da fila, 9.5 e 22 liberados, e a tabela FID-01 do E1 — GREEN` |

## Known Stubs

Nenhum. Este plano não cria componente de tela nem fonte de dados.

---

## Self-Check: PASSED

Conferido por script, não por memória:

- **8/8 arquivos** citados existem no disco (`test -f`).
- **4/4 commits** existem no `git log --oneline --all`.
- **5/5 tokens** presentes em `tailwind.config.js` por `grep -F` do literal exato.
- `TAMANHOS_PERMITIDOS` na linha `:266` com `8.5, 9.5, 10, …, 20, 22, 24, …` — **23 valores**.
- `--autoteste` reconferido depois de tudo: **`16 casos, 0 divergencia`**.
- Os 3 gates do manifesto: **`PASSOU`** nos três.
- `test -e` em **35 caminhos**: 34 + o `contagens-187-01.txt` que este plano criou.
