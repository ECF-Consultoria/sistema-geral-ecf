---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 04
subsystem: publicador-aprovacao
tags: [gates, z-index, tailwind, css-construido, build, mutacao, lightbox]
requires:
  - "189-03 — `LightboxDaImagem.jsx` commitado (`d8b30fdf`), com o gate `lightbox-casca` 27/27/0"
  - "188-07 — `PainelDeRevisao.jsx` com backdrop `z-[39]` e painel `z-[40]`"
  - "187-09 — `CascaDeModal.jsx` `z-[42]`, `ToastDaFila.jsx` `z-[45]`, `BarraDeSelecao.jsx` `z-[35]`"
  - "189-01 — o `18` liberado em `LIBERADOS_DEPOIS_DA_187` (as setas ← →)"
provides:
  - "a ORDEM de camadas provada pelo COMPORTAMENTO (TERR-06), com isca de inversão E de troca de número"
  - "o GATE-09 em DUAS camadas: FONTE sem comentário e CSS construído, com `duration-200` de controle"
  - "as 43 classes do lightbox conferidas PELO CORPO DA REGRA no bundle, com as oito normalizações"
  - "o `npm run build` desta fase, com o MANIFEST re-medido pelo JSON (498 / 203)"
  - "a campanha de mutação do `LightboxDaImagem.jsx`: 8 mutações, 0 sobreviventes"
affects:
  - "nenhum arquivo de produção — este plano só PROVA (PI(189-04) com interseção VAZIA)"
tech-stack:
  added: []
  patterns:
    - "ordem de z-index como CADEIA de elos com razão escrita, não como tabela de números"
    - "premissa inobservável travada por igualdade de FONTE, com o teste DECLARANDO que é proxy"
    - "camada de CSS com guarda de `mtime` → dois regimes de contagem no manifesto"
key-files:
  created:
    - "tests/js/publicador-lightbox-camadas-189.test.js (923 linhas, 11 casos top-level + 5 sub-testes)"
    - "tests/gates/contagens-189-04.txt (1 id, `camadas-e-css`, `tests=16,11`)"
  modified: []
decisions:
  - "o `90` do protótipo é recusado POR PEÇA (`DO_PROTOTIPO = { lightbox: 90 }`), nunca por lista larga"
  - "a sonda de CSS procura `font-[800]` (a forma da FONTE) e NÃO `font-extrabold` — as duas existem no bundle com o MESMO valor"
  - "o caso `ZERO entrada MORTA` e o proxy do portal ficam FORA da camada de CSS, para não sobreviverem à mutação"
metrics:
  duration: "~3h40 (20/10/2026)"
  completed: "2026-10-20"
---

# Phase 189 Plan 04: A ordem das camadas, o GATE-09 e o CSS construído — Summary

Prova, **fora do render**, o que o 189-03 desenhou: a cadeia de empilhamento
`header 30 < barra 35 < backdrop 39 < painel 40 < LIGHTBOX 41 < modal 42 < toast 45` lida das sete
fontes, o `90` do protótipo recusado **por peça**, o GATE-09 na **FONTE** e no **CSS construído** com
`duration-200` de controle, as **43 classes** do lightbox conferidas **pelo corpo da regra** no bundle
do `npm run build`, e a campanha de mutação — que **sobreviveu uma vez, foi investigada, e o furo que
ela acusou virou caso novo**.

---

## §1 — A PARTIDA, e as baselines

```
PARTIDA:  fdc88da6  — confere com o esperado pelo briefing
          ⚠️ capturada na PRIMEIRA ação, e usada APENAS como referência de LEITURA:
             o território vai por ESCOPO DO ASSUNTO, NUNCA por extremo de range
COMMITS:  06e6c206 (test, tarefa 1) · 9052db72 (feat, tarefa 2) · 86b887bf (fix, o conserto
          do sobrevivente M1)
HEAD:     86b887bf
```

| número | baseline (189-03 fim) | medido nesta wave | veredicto |
|---|---|---|---|
| JS — testes | 5908 | **5924** | **ACIMA** (+16) — exatamente os meus 16 |
| JS — pass | 5907 | **5923** | ACIMA (+16) |
| JS — fail | 1 | **1** | **IGUAL** — a MESMA falha herdada, nomeada e atribuída em §2 |
| JS — skipped | 0 | **0** | IGUAL |
| PHP **RECORTE** — testes | 2595 | **2595** | **IGUAL** (este plano não toca um arquivo PHP) |
| PHP **RECORTE** — asserções | 23878 | **23878** | IGUAL |
| PHP **RECORTE** — falhas | 0 | **0** | IGUAL |
| MANIFEST | 498 / 203 | **498 / 203** | **IGUAL** — re-medido PELO JSON depois de **4** builds |
| PROTÓTIPO | `1432` linhas / `68624883ce35547e` | **sha IDÊNTICO** | IGUAL (ressalva de MÉTODO abaixo) |

**O comando de cada linha:**

| chave | comando |
|---|---|
| `PARTIDA` | `git rev-parse --short HEAD` — **a primeira ação desta sessão** |
| JS | `sh tests/gates/verificar-testes.sh --suite js` |
| PHP | `sh tests/gates/verificar-testes.sh --suite php` (ele roda `tests/Unit/Publicador` + `tests/Feature/Publicador`) |
| MANIFEST | `node --input-type=commonjs` lendo `public/build/manifest.json` **pelo JSON** |
| PROTÓTIPO | script em arquivo sobre `design_handoff_publicador/Aprovacao.dc.html` |

### ⚠️⚠️ A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA — declarado, não contornado

`artisan test` **aborta com Fatal de memória** em `Illuminate\Http\Testing\FileFactory.php:87` a ~6%
de 10.653 testes, e **`-d memory_limit=-1` NÃO resolve** (o estouro é em processo **FILHO**: há
testes que disparam `artisan test` como subprocesso, e o filho nasce com 512M).
➡️ **A medida é o RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`), que é o que o
`--suite php` do harness roda. **Está declarado, não contornado.**

### A ressalva de MÉTODO no protótipo: `1432` × `1433` **não é** mudança

```
wc -l (terminadores)       → 1432
split(/\r?\n/).length      → 1433        ← o arquivo TERMINA com newline
última linha do split      → ""
sha256 | cut -c1-16        → 68624883ce35547e   (IDÊNTICO à referência)
```

⚠️ `wc -l` conta **terminadores**; o `split` devolve um elemento vazio a mais. **É MÉTODO DE MEDIDA**,
e o `sha256` prova que o documento não se moveu.

### ⚠️ Uma honestidade sobre a ordem das medições

A suíte JS **não** foi medida no instante exato da PARTIDA: a primeira medição foi **depois** da
tarefa 1 + camada de CSS (`5923`, com 15 casos) e a segunda no FIM (`5924`, com 16). ➡️ **A
aritmética fecha nas duas pontas** contra a baseline do 189-03 (`5908 + 15 = 5923` e
`5908 + 16 = 5924`), o que prova que ela estava intacta na partida. A falha e o `skipped` são os
mesmos nas três medições.

---

## §2 — AS FALHAS QUE **NÃO SÃO DESTA FASE**, atribuídas por execução ISOLADA

⚠️⚠️ Nenhuma foi corrigida, nenhuma conta como regressão, e **NÃO existe `REF_PHP_FALHAS`** — o
próprio `verificar-testes.sh` declara, na fonte, que esse limiar não deve passar a existir.

### 2.1 — JS, a única: `tests/js/estrutura-grade-glide.test.js` (Fase 87)

Caso **"Características secundárias nasce recolhido (é o grupo que mais infla)"**.
**Atribuída por execução ISOLADA**, como a régua exige:

```
node --test --test-reporter=tap tests/js/estrutura-grade-glide.test.js
→ # tests 19 · # pass 18 · # fail 1 · # skipped 0
```

É a pré-existente herdada, e é o `REF_JS_FALHAS=1` cravado no harness. **NÃO corrigida.**

### 2.2 — PHP: o recorte está em **0 falhas**, e isso é notável

⚠️ A medição do recorte rodou **em paralelo** com o gate PHP `186-09 transversais` da campanha de
mutação — exatamente a corrida de `storage/framework/testing` que a régua avisa produzir falhas que
**passam em isolamento**. ➡️ **Não houve nenhuma**: `2595 / 23878 / 0`. Não há o que atribuir.

### 2.3 — Fora do recorte, nomeadas e NÃO medidas aqui

`Tests\Unit\CalcularFaixaTest` (9, `ArgumentCountError` no `AdminController` que ganhou DI) ·
`CompanyServiceTypeTest::test_service_type_aceita_polo` (1, a armadilha **enum + CHECK do SQLite** já
na memória do projeto) · `Phase39\MercadoLivreSugadoresProviderTest` (2) · os dois de
`tests/Feature/Publicador/CockpitDaContaTest.php` (Fase 179, `:966`/`:1318`, **FORA** de `Aprovacao/`),
que **oscilam com a hora do dia**. **Nenhuma é desta fase, nenhuma foi corrigida, e nenhuma conta como
regressão.**

⚠️ **E `${TMPDIR:-/tmp}/ultima-suite-php.log` NÃO foi lido durante a execução** (caminho FIXO e
COMPARTILHADO; uma wave leu e reportou `0 falhas` quando eram 11). Os números vêm da linha de forma
fixa que o harness imprime.

---

## §3 — ⚠️⚠️ O `npm run build`: a árvore conferida ANTES, e o MANIFEST pelo JSON

### 3.1 — A conferência que vem ANTES do build

O briefing é explícito: a árvore é compartilhada com **outra sessão ativa**, e buildar com `.jsx`
alheio meio-salvo gera artefato de um estado meio-feito. **Medido imediatamente antes de cada build:**

```
git diff HEAD --stat -- resources/   → VAZIO
git status --short -- resources/     → VAZIO (nem untracked)
```

⚠️ **E "árvore limpa" por `git status` geral ABORTARIA** (a árvore tem 40+ `??` de outra sessão e
documentos soltos na raiz) — por isso a conferência é `git diff HEAD` **nos arquivos que importam**,
não um veredicto global.
⚠️ **A árvore tem 27 `stash` e eu apenas os LISTEI** — nenhuma forma de `git stash` foi executada
(`refs/stash` é COMPARTILHADO entre worktrees; `pop` aplicaria WIP de outra sessão).

### 3.2 — O MANIFEST, lido **PELO JSON**

```
ENTRADAS                                      498     (referência: 498 — IGUAL)
PAGINAS (resources/js/Pages/**)               203     (referência: 203 — IGUAL)
CSS de `resources/js/app.jsx`                 assets/app-CL-Xc-QC.css
PÁGINA `Pages/Mlb/Publicador/Aprovacao.jsx`   presente
ISCA  `Pages/Mlb/NaoExiste9z7.jsx`            ausente
```

⚠️⚠️ **A LEITURA PELO JSON NÃO É ZELO — ela se provou nesta wave.** O hash do CSS saiu
**`app-CL-Xc-QC.css`**, **com HÍFEN no meio**. Um `grep` ingênuo de `app-[A-Za-z0-9]*\.css` **não
casaria** e a camada de CSS inteira teria virado `skip` em silêncio. O briefing avisava que o hífen é
*"sorteio do build"*; nesta rodada o sorteio deu hífen.

### 3.3 — ⚠️⚠️ O INVARIANTE FALSO, re-medido: componente novo **NÃO** entra no manifest

| medida | valor |
|---|---|
| `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` no manifest | **presente** |
| `resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx` no manifest | **AUSENTE** |
| entradas do manifest com `/aprovacao/` no caminho | **0** |

➡️ **O que se confere no manifest é a PÁGINA.** Componente aninhado é **fundido no chunk dela** — e o
chunk `assets/Aprovacao-D_A6s1G3.js` cresceu para **104,86 kB** com o lightbox dentro. ⚠️ Cobrar o
componente no manifest seria um gate que **reprova trabalho certo**, e há asserção no gate fixando
este invariante com a mensagem *"remeça antes de cobrar"*.

### 3.4 — Os QUATRO builds, e por que foram quatro

| # | quando | por quê |
|---|---|---|
| 1 | início da tarefa 2 | gerar o bundle com o lightbox para **MEDIR** as formas reais antes de escrever regex |
| 2 | depois de medir o regime MUTADO por `touch` | o `touch` deixou a fonte mais nova que o CSS e derrubou a camada |
| 3 | depois do conserto do sobrevivente M1 | o gate ganhou um caso e os regimes mudaram (`15,10` → `16,11`) |
| 4 | **DEPOIS da campanha de mutação** | ⚠️ **obrigatório**: o `cp` da restauração **atualiza o `mtime`** e a camada de CSS **PULARIA** |

⚠️ Em todos os quatro: `npm run build` **exit 0**, e o manifest re-lido pelo JSON deu **498 / 203**.

---

## §4 — ⚠️⚠️ AS 12 CLASSES DA §23 CONFERIDAS NO CSS CONSTRUÍDO, com a normalização de cada uma

A §23 da `189-03-SUMMARY.md` é o handoff direto para esta wave e lista 12 classes. **Todas as 12
foram conferidas pelo CORPO DA REGRA** (presença não é fidelidade), e a tabela diz **qual das oito
normalizações** se aplicou:

| # | classe (da §23) | corpo MEDIDO no bundle | normalização aplicada |
|---|---|---|---|
| 1 | `z-[41]` | `z-index:41` | **N5** — o seletor sai `.z-\[41\]` (colchetes escapados) |
| 2 | `grid-cols-[minmax(0,1fr)_320px]` | `grid-template-columns:minmax(0,1fr) 320px` | **N8** — escape **GERAL**: parênteses e vírgulas. Sem ela a regra sai AUSENTE estando presente |
| 3 | `bg-[rgba(5,5,5,0.94)]` | **`background-color:#050505f0`** | **N3** — ⚠️⚠️ **HEX DE 8 DÍGITOS**, e **não** `rgba(…)`. O alfa `0xF0` = 240 PROVA o `0,94` (`0,94 × 255 = 239,7`) |
| 4 | `w-[min(78vh,100%)]` | `width:min(78vh,100%)` | **N8** — parênteses e vírgula |
| 5 | `h-[26px]` | `height:26px` | **N5** |
| 6 | `h-[56px]` | `height:56px` | **N5** |
| 7 | `rounded-pub-control-sm` | `border-radius:7px` | — (token de raio, valor direto) |
| 8 | `h-[44px]` | `height:44px` | **N5** |
| 9 | `-mt-[22px]` | `margin-top:-22px` | **N4/N5** — negativo dentro do valor arbitrário |
| 10 | `text-[18px]` | `font-size:18px` | **N5** — o único tamanho NOVO da fase, liberado pela 189-01 |
| 11 | `cursor-crosshair` | `cursor:crosshair` | — |
| 12 | `cursor-zoom-out` | `cursor:zoom-out` | — |

### ⚠️ A #3 era a que o 189-03 avisou para **MEDIR, não presumir** — e ele estava certo

> *"⚠️ E `rgba(5,5,5,0.94)` pode sair normalizado — meça, não presuma"* (§23).

**Medido: sai `#050505f0`.** Uma sonda que esperasse `rgba(5,5,5,.94)` reportaria **falsa falta** numa
regra presente e correta. É a terceira normalização em ato, e ela já foi a **8ª mentira do medidor**
desta milestone — que veio **da prescrição**, não do executor.

### A lista completa: **43 com valor + 15 só de presença**

Além das 12 da §23 e das 19 da lista (b) do plano, a lista constante cobre os tokens sólidos
(`text-pub-txt`, `text-pub-txt-2`, `text-pub-txt-off`, `text-pub-page`, `bg-pub-hover`,
`bg-pub-hover-2`, `bg-pub-surface`, `border-pub-page`, `border-pub-line-strong`), o `font-pub`, os
dois raios (`rounded-pub-panel` 12px, `rounded-pub-control-sm` 7px), o `aspect-square`, os dois
`-translate-*-1/2` e as bordas. **N1 em ato:** token sólido compila `rgb(R G B / var(--tw-…-opacity, 1))`
**em DECIMAL**, nunca hex — esperar hex produziu **25 falsas faltas** nesta milestone.

### ⚠️ ZERO entrada morta, e isso amarra a lista ao ARQUIVO

A lista constante é conferida contra os **TOKENS DE CLASSE DA FONTE** do lightbox, por **pertencer ao
conjunto** — ⚠️ **nunca por `includes` da string**: `'border'` é substring de `'border-2'`,
`'border-pub-page'` e `'border-[1.5px]'`, e um `includes` daria verde para uma classe que o arquivo
não tem. **Medido: `tokens.size` > 40, `mortas = []`**, com isca nos dois lados (`z-[41]` está;
`font-extrabold` e `w-[1993px]` não estão).

---

## §5 — ⚠️⚠️ A SONDA DE AUSÊNCIA MORA NA FONTE SEM COMENTÁRIO, NUNCA NO CSS — e a razão

**O fato medido (learnings §4d.1, wave 188-08):** o `content` do Tailwind varre o arquivo como
**TEXTO, não como código**. Uma classe escrita na **PROSA de um docblock** (`left-[37.5%]`,
justamente no comentário que explicava **por que NÃO** usá-la) **virou regra de CSS de verdade**, e o
bundle **encolheu 7 bytes** quando ela foi neutralizada.

➡️ **A consequência inverte onde a sonda pode morar**, e o GATE-09 ficou com **duas camadas de papéis
DIFERENTES que não se trocam:**

| camada | o que ela prova | por que não pode ser a outra |
|---|---|---|
| **FONTE sem comentário** | *"o `LightboxDaImagem.jsx` **não escreve** `duration-[`/`delay-[`/`ease-[`"* | no CSS essa prova é **impossível**: o próprio docblock que PROÍBE a forma **a faria existir**, e o gate **reprovaria o conserto** |
| **CSS construído** | *"a forma arbitrária **não compila** neste projeto, e a NOMEADA compila"* | na fonte isso não se vê: a classe fica no HTML e **todo render passa** |

**A prova de que ler sem comentário é obrigatório, dentro do próprio gate:** há asserção exigindo que
as três formas proibidas **APAREÇAM no arquivo CRU** — o docblock do lightbox as cita em `:364`-`:381`
e `:455`-`:462` para explicar por que não as usa. Se um dia essa asserção falhar, o docblock perdeu a
explicação, e o gate passou a ser satisfeito **pelo motivo errado**.

⚠️ **E este arquivo de gate vive em `tests/js/`, que o `content` do `tailwind.config.js` NÃO varre**
(`./resources/js/**/*.jsx` + os blades — **conferido na config, não presumido**): os comentários
*deste* arquivo não geram CSS.

### O GATE-09 no CSS, com o CONTROLE — a saída COLADA

```
duration-200        → transition-duration:.2s       ← O CONTROLE (N4: o cssnano comeu o zero de 0.2s)
duration-[150ms]    → AUSENTE
duration-[180ms]    → AUSENTE
delay-[30ms]        → AUSENTE
ease-[ease]         → AUSENTE
ocorrências de `.duration-\[` como SELETOR no bundle inteiro → 0
```

⚠️ **Sem o controle a sonda não distingue** *"o arbitrário não compila"* de *"a sonda está cega"*.
⚠️ **E `transition-transform` EXISTE** no bundle (41 ocorrências em `resources/`, de outros donos),
com `cubic-bezier(.4,0,.2,1)` e `.15s` — a **curva ERRADA** para o `:731`, que é
`transition:transform .15s` com o `ease` padrão do CSS. ➡️ **No CSS não há como provar que ESTE
arquivo não a usa**; quem prova é a camada de fonte.

---

## §6 — ⚠️⚠️ A FORMA `font-[800]`, E A PREVISÃO DO BRIEFING QUE SAIU **FALSA**

O briefing prescrevia:

> *"No CSS construído as duas formas dão o MESMO valor: se a sua sonda procurar a classe NOMEADA, ela
> **NÃO acha**. Meça a forma que está na FONTE."*

**MEDIDO, e a segunda metade está errada:**

```
font-[800]       → font-weight:800     PRESENTE
font-extrabold   → font-weight:800     PRESENTE
grep -rF 'font-extrabold' resources/   → 135 ocorrências
```

➡️ **A sonda que procurasse a forma NOMEADA NÃO falharia — ela passaria VERDE pelo motivo ERRADO.**
`font-extrabold` tem **135 donos** fora do Publicador, então a regra existe no bundle **independentemente
do lightbox**: o gate ficaria verde mesmo que o marcador tivesse perdido o peso.

⚠️ **Isto é a limitação estrutural do learnings §3 em ato**, e é mais perigoso que a previsão do
briefing: *"a sonda de CSS **não protege a fidelidade de UM arquivo**"*, porque (a) o CSS é do último
build e (b) **a mesma classe pode ter outro dono**. ➡️ Por isso:
1. a sonda procura **`font-[800]`**, a forma que está na FONTE (D1 do 189-03: o GATE-03 fecha a escala
   nomeada em 400/500/600/700, e o arbitrário entrega o MESMO peso computado — precedente em produção
   em `ImagemGrandeDoPainel.jsx:170` e `AnelDeProgresso.jsx`);
2. a lista constante é amarrada aos **TOKENS DA FONTE** (§4);
3. há um caso **declarando por asserção** que as duas formas existem com o mesmo valor, para que o dia
   em que isso mudar exija uma medição nova e não um palpite.

⚠️ **O protótipo pede `font-weight:800` (`:733`, re-medido por script nesta wave) e as duas formas o
entregam.** A divergência da `189-FID01.md` §B7 (que escreve `font-extrabold`) é **por VALOR
IDÊNTICO** e já estava declarada pelo 189-03 — **não é um quinto conflito protótipo × regra do
projeto**, e não foi tratada como tal.

---

## §7 — ⚠️⚠️ O Z-INDEX PROVADO PELA **ORDEM**, com as DUAS iscas opostas

### A cadeia, lida das SETE fontes (nenhum número escrito à mão no gate)

```
header 30  <  BarraDeSelecao 35  <  backdrop 39  <  painel 40  <  LIGHTBOX 41  <  modal 42  <  toast 45
```

```
header     ocorrencias=1  z=30  resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
barra      ocorrencias=1  z=35  .../aprovacao/BarraDeSelecao.jsx
backdrop   ocorrencias=1  z=39  .../aprovacao/PainelDeRevisao.jsx
painel     ocorrencias=1  z=40  .../aprovacao/PainelDeRevisao.jsx
lightbox   ocorrencias=1  z=41  .../aprovacao/LightboxDaImagem.jsx
modal      ocorrencias=1  z=42  .../aprovacao/CascaDeModal.jsx
toast      ocorrencias=1  z=45  .../aprovacao/ToastDaFila.jsx
```

⚠️ Cada âncora **casa EXATAMENTE uma vez** ou o extrator **REPROVA** — extração que devolve `null` em
silêncio numa renomeação é o jeito clássico de um gate virar decoração.

### Os SEIS elos, cada um com a razão escrita **na tela**

| elo | por que, na tela |
|---|---|
| header < barra | a barra de lote flutua ACIMA do header: ela é a ação do que está selecionado |
| barra < backdrop | o backdrop do painel escurece a fila INTEIRA, barra incluída |
| backdrop < painel | o painel fica acima do próprio backdrop que o escurece |
| painel < **lightbox** | o lightbox COBRE o painel: é a imagem em tela cheia |
| **lightbox** < modal | o "Reprovar" DENTRO do lightbox abre o modal POR CIMA dele — abaixo, **a pessoa clica e nada aparece** (T-189-22) |
| modal < toast | o toast é o aviso do que acabou de acontecer, e nada pode cobri-lo |

### O `90` recusado **POR PEÇA**, nunca por lista larga

```js
const DO_PROTOTIPO = { lightbox: 90 };
assert.notEqual(lightbox, DO_PROTOTIPO.lightbox, …);
```

⚠️ **Por que não lista larga:** a 187-09 mediu que
`![44, 45, 50, 60, 100, 120].includes(valor)` **REPROVOU CÓDIGO CERTO** — `45` é o número que o
protótipo deu ao **POPOVER**, e o toast legitimamente ficou em `z-[45]`. Essa família já reprovou
trabalho correto três vezes nesta milestone e **BARROU UM DEPLOY**. O invariante de verdade é *"a peça
não herdou o número que o protótipo deu A ELA"*.
⚠️ **E `z-[41]` já existe** em `resources/js/Components/PlanejadorFull/Gaveta.jsx:66` — outro módulo,
outra tela. **Não é colisão**, e isca de *"z-[41] inexistente no projeto"* seria **FALSA**: não a
escrevi. ⚠️ **`z-[90]` também existe** (1 ocorrência em `resources/`, medida) — isca sobre ele seria
igualmente falsa.

### ⚠️⚠️ AS DUAS ISCAS OPOSTAS — e a segunda é a que falta na maioria dos gates de z

| direção | fonte sintética | veredicto esperado | medido |
|---|---|---|---|
| **INVERSÃO** | lightbox em `z-[43]` (acima do modal `42`) | **REPROVA**, acusando **NOMEADAMENTE** o elo lightbox→modal | ✅ 1 infração, e a mensagem casa `lightbox(43) tem de ficar ABAIXO de modal(42)` |
| **TROCA DE NÚMERO** | painel `z-[38]` e backdrop `z-[37]` | **NÃO reprova** (a ordem se mantém) | ✅ 0 infrações |

⚠️ **Sem a segunda, o gate estaria medindo a TABELA e não a ORDEM** — e seria ele a ensinar o executor
seguinte a mexer na régua quando ela incomodasse. A isca também confere que o par sintético continua
**acima da barra `z-[35]`**, senão ela passaria por omissão em vez de por ordem preservada.

### A isca da forma que o Tailwind **não compila**

`z-41`/`z-42`/`z-39` **sem colchete** não casam nenhuma das três âncoras (41/42/39 não existem na
escala nomeada, que vai de 10 em 10) — e, **na outra direção**, as MESMAS três formas **COM** colchete
casam 1 vez cada. Sem a segunda metade, *"nenhuma casou"* seria satisfeito por uma âncora quebrada.

### `publicador-header-zindex-ordem.test.js` — **INTOCADO e VERDE**

```
node --test --test-reporter=tap tests/js/publicador-header-zindex-ordem.test.js
→ # tests 7 · # pass 7 · # fail 0 · # skipped 0
```

⚠️ Ele é **INTOCÁVEL** (nasceu de bug relatado pelo usuário em 12/10/2026, consertado em produção) e
está na lista da **PI(189-04)**, com interseção VAZIA. O meu gate **não o edita** e **não duplica o
invariante dele** (`header < menu = painel`): este guarda a cadeia da APROVAÇÃO. ⚠️ **A âncora do
header aqui recorta `<header className="sticky … z-NN` com `[^"]*?` no meio**, de propósito: a outra
sessão trocou `top-0` por `-top-6` **por fora desta fase**, e o offset **não é** o invariante desta
cadeia.

---

## §8 — A TABELA FID-01 §B RE-MEDIDA **POR SCRIPT**, com as três propriedades em COLUNAS PRÓPRIAS

⚠️ **Medido POR SCRIPT** (`fid-189-04.cjs`, escrito por ferramenta), **não por leitura**.
⚠️ **O DIRETÓRIO citado:** `design_handoff_publicador/Aprovacao.dc.html`. **Medido:
`new_layout_publicador/Aprovacao.dc.html` NÃO EXISTE** (`test -e` → `false`). O arquivo é **somente
leitura e NÃO versionado** → o script degrada para **`SKIP` EXPLÍCITO e NOMEADO** se ele faltar.

| id | linha#occ | `cursor` | `white-space` | `text-overflow` | estilo literal do protótipo |
|---|---|---|---|---|---|
| B1 | `:727#1` | — | — | — | `position:fixed;inset:0;z-index:90;background:rgba(5,5,5,0.94);display:grid;grid-template-columns:minmax(0,1fr) 320px` |
| B2 | `:728#1` | — | — | — | `position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:24px 70px` |
| B3 | `:729#1` | — | — | — | `display:flex;align-items:center;gap:10px;font-size:13px;color:#A1A1AA` |
| B4 | `:729#2` | — | — | — | `color:#F4F4F5;font-weight:600` |
| B5 | `:730#1` | **`{{ LB.cursor }}`** | — | — | `position:relative;width:min(78vh, 100%);aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#141414;color:#52525B;cursor:{{ LB.cursor }}` |
| B6 | `:731#1` | — | — | — | `position:absolute;inset:0;transform:{{ LB.tf }};transform-origin:{{ LB.origin }};transition:transform .15s` |
| B7 | `:733#1` | — | — | — | `position:absolute;left:{{ pin.left }};top:{{ pin.top }};transform:translate(-50%,-50%);width:26px;height:26px;border-radius:50%;background:{{ pin.bg }};color:#0A0A0A;border:2px solid #0A0A0A;display:grid;place-items:center;font-size:11.5px;font-weight:800;pointer-events:none` |
| B8 | `:736#1` | — | — | — | `display:flex;gap:6px` |
| B9 | `:738#1` | — | — | — | `position:relative;width:56px;height:56px;border-radius:7px;overflow:hidden;background:#161616;color:#52525B;border:1.5px solid {{ t.tBorder }};box-shadow:{{ t.ring }}` |
| B10 | `:738#2` | **`pointer`** | — | — | `position:absolute;inset:0;border:none;background:transparent;cursor:pointer` |
| B11 | `:741#1` | **`pointer`** | — | — | `position:absolute;left:16px;top:50%;width:44px;height:44px;margin-top:-22px;border:1px solid #2E2E2E;border-radius:50%;background:#111111;color:#F4F4F5;font-size:18px;cursor:pointer` |
| B12 | `:742#1` | **`pointer`** | — | — | `position:absolute;right:16px;top:50%;width:44px;height:44px;margin-top:-22px;border:1px solid #2E2E2E;border-radius:50%;background:#111111;color:#F4F4F5;font-size:18px;cursor:pointer` |

**As três propriedades, nas DUAS direções:**

```
CURSOR:         deve_ter=[B5,B10,B11,B12]   FALTANDO=[]   SOBRANDO=[]
WHITE-SPACE:    as peças da §B que o declaram = []   (a tabela diz: NENHUMA — confere)
TEXT-OVERFLOW:  as peças da §B que o declaram = []   (a tabela diz: NENHUMA — confere)
PONTEIROS_SEM_ESTILO = []                   ← ZERO ponteiro deslocado
```

⚠️ **ZERO ponteiro deslocado** — a 189-01 havia achado 3 (MOD-06 `:821-:831` → `:866-:875`, apontando
para **outro modal**) e a 189-02 outros 5. **Os 12 da §B estão todos no lugar**, e cada um foi
reconfirmado individualmente. Iscas do extrator exercidas nos dois lados (acha onde SEI que tem;
**não** acha a 9ª ocorrência de `:727`; **não** acha estilo numa linha vazia).

⚠️ **Nada em `189-FID01.md` foi alterado** (ele é extensível, mas não precisou de extensão: a §B está
correta, e a única divergência — `font-extrabold` × `font-[800]` — já estava declarada pelo 189-03).

### A medida que amarra o protótipo ao CSS construído

```
B1 z-index do PROTÓTIPO     = 90            → o remapeamento do projeto é 41 (§7)
B6 transition do PROTÓTIPO  = "transform .15s"  → `ease` é o padrão do CSS, NÃO o cubic-bezier do Tailwind
B5 cursor dinâmico          = "{{ LB.cursor }}" → DUAS classes LITERAIS, nunca concatenadas
B7 font-weight do PROTÓTIPO = 800           → `font-[800]` (§6)
B5 width do PROTÓTIPO       = "min(78vh, 100%)"  ⚠️ COM espaço
```

⚠️⚠️ **A última linha virou a ISCA DO QUASE-IGUAL, e é a mais informativa das nove:** o protótipo
escreve `min(78vh, 100%)` **com espaço**, e espaço dentro de valor arbitrário **QUEBRA a classe do
Tailwind**. **Medido no bundle:**

```
w-[min(78vh, 100%)]  → AUSENTE      (a forma COM espaço não compila)
w-[min(78vh,100%)]   → width:min(78vh,100%)   (a forma SEM espaço compila)
```

➡️ Há asserção nos **dois** sentidos. A caixa 1:1 de até 78vh perderia a largura **com todo render
passando**, e isso agora reprova.

---

## §9 — AS ISCAS DE AUSÊNCIA, **MEDIDAS** — e as SEIS que foram REJEITADAS por medição

A régua é brutal aqui: isca de ausência tem de ser classe inexistente **no projeto INTEIRO**, e o que
*"parecia inventado"* já saiu PRESENTE mais de uma vez.

**As NOVE aceitas** (ausentes do bundle **e** com 0 ocorrências em `resources/`):

```
bg-pub-isca-189-04 · text-[97.5px] · w-[1993px] · top-[62.5%] · z-[43]
px-[71px] · -mt-[23px] · gap-[14.5px] · w-[min(78vh, 100%)]
```

**As SEIS rejeitadas, por MEDIÇÃO** — cada uma seria uma isca FALSA:

| candidata | por que foi rejeitada |
|---|---|
| `w-[13px]` | `width:13px` **existe** (o briefing já avisava) |
| `h-[64px]` | `height:64px` **existe** |
| `left-[30%]` | `left:30%` **existe** |
| `grid-cols-6` | **existe**, de outros donos, e compila para o **MESMO valor** que `grid-cols-[repeat(6,minmax(0,1fr))]` |
| **`z-[90]`** | **existe** — 1 ocorrência em `resources/`. Isca sobre o número do protótipo seria FALSA |
| **`text-[17px]`** | **existe** — 22 ocorrências. ⚠️ Ou seja: *"arredondar o 18 para 17"* **compilaria**, e é só a camada de FONTE (FID-02/FID-03) que o barra |

⚠️ **E a sonda NÃO-CEGA antes de cada `deepEqual`:** há asserção exigindo que o controle
(`text-[13px]`) e um arbitrário com colchete (`z-[41]`) sejam **ACHADOS** — sem isso, *"todas as iscas
ausentes"* seria satisfeito por um `regraDe` que devolve `null` sempre.

---

## §10 — A CAMPANHA DE MUTAÇÃO: **duas rodadas**, e a 1ª mudou o resultado

Scripts **`mutar-189-04.sh`** + **`mutacoes-189-04.cjs`** — ⚠️ **o nome carrega o PLANO**: o
scratchpad é COMPARTILHADO entre subagentes paralelos, e na Fase 187 um `mutar.sh` genérico foi
**SOBRESCRITO** no meio do trabalho e a campanha foi **REFEITA INTEIRA**. Os dois imprimem o próprio
`sha256` antes de qualquer veredicto.

**Protocolo cumprido:** commitado **ANTES** (`9052db72` / `86b887bf`) · `cp -p` da cópia · ocorrência
travada em **1 ANTES** por **STRING EXATA** e `indexOf` (⚠️ **`grep -c` conta LINHAS**) · **linha
conferida DEPOIS** · aplicação por **divergência de `sha256`** · restauração **DA CÓPIA** em
`trap EXIT INT TERM` (⚠️ **`git checkout` NUNCA**) · `sha256` final contra `git show HEAD:` com
`tr -d '\r'` **nos dois lados**.

```
mutar-189-04.sh     = a8232fbe7c2eccf1
mutacoes-189-04.cjs = 875fd418d6d961d9
SHA_DISCO(sem \r) = SHA_HEAD(sem \r) = SHA_PRISTINO = SHA_COPIA = 530a4d50e08f5382
PRISTINO: 189-03 lightbox-casca # tests 27 PASSOU · 189-04 camadas-e-css # tests 16 PASSOU
```

**As 8 âncoras, travadas em 1 ocorrência ANTES:**

```
M1  ocorrencias_DE=1  linha=2    M5  ocorrencias_DE=1  linha=131
M2  ocorrencias_DE=1  linha=425  M6  ocorrencias_DE=1  linha=622
M3  ocorrencias_DE=1  linha=424  M7  ocorrencias_DE=1  linha=257
M4  ocorrencias_DE=1  linha=424  M8  ocorrencias_DE=1  linha=673
CONFERIR: as 8 ancoras travadas em 1 ocorrencia.
```

### RODADA 2 (final) — 8 mutações, **0 sobreviventes**, **0 que não carregaram**

| # | mutação | `# tests` 189-03 | `# tests` 189-04 | mortos |
|---|---|---|---|---|
| **M1** | `createPortal` vira passagem direta (o lightbox deixa de PORTAR) | **27** PASSOU | **11** **REPROVOU** | *PROXY DE FONTE — o lightbox PORTA para o `document.body`* |
| **M2** | `font-pub` sai da RAIZ do portal (REND-05) | **27** REPROVOU | **11** REPROVOU | LB-189-17 · *ZERO entrada MORTA* |
| **M3** | `z-[41]` → **`z-[43]`** (acima da casca de modal) | **27** REPROVOU | **11** REPROVOU | LB-189-16, LB-189-17 · *a cadeia*, *ISCA NA OUTRA DIREÇÃO*, *ZERO entrada MORTA* |
| **M4** | `z-[41]` → **`z-[90]`** (o número do protótipo PARA ESTA PEÇA) | **27** REPROVOU | **11** REPROVOU | LB-189-16, LB-189-17 · *a cadeia*, *o lightbox recusa o 90*, *ISCA NA OUTRA DIREÇÃO*, *ZERO entrada MORTA* |
| **M5** | a transição sai do `style` e vai por `className` com `duration-[150ms]` | **27** REPROVOU | **11** REPROVOU | LB-189-7, LB-189-9, LB-189-19 · *GATE-09 na FONTE* |
| **M6** | a coordenada do marcador por **CLASSE CONCATENADA** | **27** REPROVOU | **11** PASSOU | LB-189-10, LB-189-21 |
| **M7** | `moverNoLightbox` **CLAMPA** em vez de dar a volta | **27** REPROVOU | **11** PASSOU | LB-189-4, LB-189-24 |
| **M8** | a borda da miniatura lê o `borda` **CRU** do servidor (AVISO-189-1) | **27** REPROVOU | **11** PASSOU | LB-189-23 |

```
RESTAURADO_DA_COPIA : sha=530a4d50e08f5382
CONTRA_git_show_HEAD: IDENTICO
CAMPANHA(189-04): 8 mutações · 0 sobrevivente(s) · 0 que não carregaram
```

⚠️⚠️ **O `# tests` DO MUTADO foi conferido nas OITO, nos DOIS gates:** `27` no 189-03 (que não tem
camada de CSS, regime único) e `11` no 189-04 — **o regime MUTADO declarado no manifesto**, porque
mutar a fonte a deixa mais nova que o bundle e a camada de CSS faz `skip`. Se qualquer contagem
caísse para fora desses valores, o arquivo **não teria carregado** (`SyntaxError`) e a morte **não
seria da asserção**. O script reprovaria.

⚠️ **M3 e M4 matam DOIS casos no 189-03 e TRÊS/QUATRO no 189-04, e isso é correto**, não falso
positivo: a contraprova do LB-189-16 cobra `raiz.includes('z-[41]')`, e do meu lado a cadeia, o mapa
`DO_PROTOTIPO` e a lista de tokens da fonte medem três coisas distintas sobre o mesmo defeito.

### ⚠️⚠️ RODADA 1: **M1 SOBREVIVEU nos DOIS gates** — e investigar mudou o resultado

A régua manda: *"sobrevivente é INVESTIGADA"*, e *"mutação que SOBREVIVE não é 'equivalente' até você
provar que é"*. **Investiguei, e as DUAS razões foram medidas:**

**(a) Nenhum teste de RENDER pode vê-la.** O composto tem a guarda de ambiente
`if (typeof document === 'undefined') return null;`, que no render de **SERVIDOR** dispara **ANTES**
de o `createPortal` ser alcançado. O HTML é `''` **com e sem** portal. ➡️ É uma **premissa
inobservável sem navegador**, e a régua manda **travá-la por igualdade de FONTE, declarando no teste
que é proxy de fonte e NÃO prova comportamento**.

**(b) A sonda de fonte que existia era mais LARGA que o invariante.** O 189-03 cobra
`FONTE_LIGHTBOX.includes('createPortal')`
(`tests/js/publicador-lightbox-casca-189.test.js:1352`, reconfirmado por `sed`), e **o mutante MANTÉM
o identificador**:

```
MUTADO M1: includes("createPortal")                     = true     ← a sonda LARGA fica VERDE
MUTADO M1: includes("import { createPortal } from ...") = false    ← a agulha EXATA acusa
MUTADO M1: includes("return createPortal(")             = true
```

É a família da **SUBSTRING**, que neste projeto já mordeu quatro gates.

**O conserto, e de que lado ele ficou:** o caso novo vive **no MEU gate** (`86b887bf`), travando a
premissa pelas **formas EXATAS** — o import, a chamada `return createPortal(`, o destino
`document.body,` e a guarda de ambiente, **cada uma casando 1 vez**. A isca é construída com o
**mutante M1 de verdade** e exige que ele seja acusado **exatamente** pela agulha do import, com
asserção registrando que a sonda larga continuaria verde.
⚠️⚠️ **NADA do 189-03 foi editado** — mexer no gate alheio mudaria o `tests=27` que o manifesto dele
declara, e a régua proíbe remedir contagem alheia. **O achado sobre o `:1352` fica NOMEADO aqui, para
o fechamento (189-08) decidir.** ⚠️ E o gate dele **não reprovou**: ele passou **mais largo do que
devia**, que é a outra metade do mesmo prejuízo.

⚠️ **E a mutação que NÃO reprova quando deveria também é informação** — foi exatamente o caso.

---

## §11 — AS LISTAS CONSTANTES: as que mexi (**nenhuma**) e as que NÃO, com a razão MEDIDA

### ⚠️ **ZERO lista constante alheia tocada**, e a razão de cada uma

| lista | arquivo | razão **medida** |
|---|---|---|
| `ARQUIVOS_NOVOS` | `publicador-vocabulario-novo.test.js` | este plano **não cria `.jsx`**. O `LightboxDaImagem.jsx` já entrou nela na wave 189-03; o único arquivo novo é um `.test.js`. Acrescentá-lo seria **entrada MORTA** |
| `JSX_DEPOIS_DA_187` | `publicador-fila-fechamento-187.test.js` | mesma razão. ⚠️ São **DUAS** escriturações por `.jsx` novo e isso é **DELIBERADO** (um conserto da 188 **RECUSOU** criar a terceira, e eu também não a criei); sem `.jsx` novo, **nenhuma** se move. Gate conferido: **6/6/0** |
| `LIBERADOS_DEPOIS_DA_187` | `publicador-fila-tokens-187.test.js` | o **`18`** já foi liberado pela via canônica na wave **189-01** e **USADO** pela 189-03. Este plano só o **CONFERE no CSS** (`text-[18px]` → `font-size:18px`) e **não acrescenta nada**. ⚠️ `19`, `21.5`, `23`, `25` e `26` **seguem BARRADOS**, e liberar "por precaução" é o que a régua proíbe. Gate: **17/17/0** |
| `SLOTS_DE_NO_DEPOIS_DA_187` | `publicador-fila-*` | este plano **não toca** `Pages/Mlb/Publicador/Aprovacao.jsx` — ele só a **LÊ** no manifest do Vite. Quem liga o lightbox à página é o **189-06** |
| `ESTADO_LOCAL_DA_PAGINA` | `publicador-fila-filtros-187.test.js` | nenhum `useState` novo na página: este plano não escreve componente nenhum |
| `QUEM_USA_SHADOW_PUB_PAINEL` | `publicador-fila-tokens-187.test.js` | nenhum arquivo deste plano escreve `shadow-pub-painel`; o lightbox não tem sombra no protótipo (`:727` só declara `background`, re-medido em §8) |
| `VARRIDOS_DECLARADOS` | `publicador-rename-em-analise-ml.test.js` | MEDIDO: declara TRÊS diretórios sob `Components/Mlb/Publicador/` e **não** inclui `tests/js`. O `.test.js` novo não entra nela |
| `FONTES_DO_PLANO` dos 9 gates de ausência de "Ampliar" (+2 PHP) | vários | nenhum deles lista arquivo deste plano, e este plano **não escreve "Ampliar"** em código nenhum. As duas únicas linhas a inverter (`publicador-painel-fotos-188.test.js:639`/`:640`) são do **189-06**; `:641` (Gerar novamente) e `casca-188:1035` **não se tocam** — e o `:1035`, que varre a FONTE de `PainelDeRevisao.jsx` por `/Ampliar/`, **não foi provocado** |
| `tailwind.config.js` | — | **não tocado**: nenhum token novo e nenhum tamanho novo. O `content` foi apenas **LIDO**, para provar que `tests/js` não é varrido |

### Os ids REMEDIDOS, com número **MEDIDO** (nenhum "subido")

| id | antes | depois | por quê |
|---|---|---|---|
| `camadas-e-css` | nasceu **`8`** na tarefa 1 | **`16,11`** | **MEDIDO**: a tarefa 2 acrescenta **2** casos só-de-fonte + 1 caso pai de CSS + **5** sub-testes. Os **DOIS REGIMES** vêm da guarda de `mtime`, e os dois foram medidos (pristino **16/16/0**; mutado **11/10/0** com **`# skipped 1`**, reproduzido por `touch` com o `sha256` conferido IDÊNTICO antes e depois) |

⚠️ **Os dois REDs que provam que o número não foi "subido para passar":**
`# tests 15 · # pass 13 · # fail 2` (a camada de CSS falhando antes de o tamanho da lista constante
ser remedido — **43**, não 44) e a **rodada 1 da mutação**, que acusou o furo do portal.
⚠️ **ZERO contagem de manifesto alheio remedida.**

### ⚠️ `vocabulario-tamanhos`: em LINHA PRÓPRIA, com N **MEDIDO** — e **sem linha de id**, com razão

```
vocabulario-tamanhos  →  MEDIDO 688   (forma `>=688`, dona: wave 189-03)
sh tests/gates/verificar-testes.sh 189-03 vocabulario-tamanhos
→ # tests 688 · # pass 688 · # fail 0 · # skipped 0   PASSOU
```

⚠️⚠️ **Por que ele NÃO ganhou linha de id no `contagens-189-04.txt`:** a régua pede esse id *"em todo
manifesto **QUE O RODA**"*, e **este plano não o roda**. Declará-lo como id aqui criaria um **ÓRFÃO** —
id no manifesto fora de todo `<verify>` é **gate sobre ZERO casos**, que a mesma régua proíbe, e a
bijeção reprovaria. ➡️ A declaração vai em **linha própria no manifesto, como MEDIÇÃO**, com a razão
escrita: aquele arquivo faz **7 sub-testes por arquivo varrido** nos diretórios fechados, e este plano
**não cria `.jsx`** — o piso **não se move** e continua sob a guarda do dono (189-03).

---

## §12 — A BIJEÇÃO, o HOMÔNIMO e o `--autoteste`

### Bijeção id ↔ `<verify>`, nos DOIS sentidos, por script (`bijecao-189-04.cjs`)

```
IDS_MANIFESTO=[camadas-e-css]
IDS_VERIFY=[camadas-e-css]
CHAMADAS_A_MANIFESTO_ALHEIO=[186-09 transversais, 186-09 transversais, --suite js]
PRIMEIRO_ARG_NU_NO_PLANO=0
ORFAOS=[]
VERIFY_SEM_ID=[]
MAX_METODOS_POR_ID=0
ISCA_EXPANSOR_regex_ingenua=0          <- /test_[A-Za-z0-9_]+/ casa ZERO em test_(a|b|c)
ISCA_EXPANSOR_expandido=3              <- o expansor ABRE a alternancia
IDS_PHP=[]
CASOS_TOP_LEVEL_NO_GATE=11  (+ 5 sub-testes = 16 no TAP)
CASOS_SEM_SUFIXO=[]
ISCA_SUFIXO_acusou=1
ARQUIVOS_DE_TESTE_JS=176
HOMONIMOS=[]
HOMONIMOS_DE_SUBTESTE_medidos_e_LEGITIMOS=27
ISCA_HOMONIMO_o_nome_sabido_aparece_em=1
---
BIJECAO(189-04): PASSOU. Zero orfao, zero verify sem id, zero caso sem sufixo, zero homonimo.
```

**O mapa id → `<verify>`**, conferido linha a linha: tarefa 1 → `camadas-e-css` ; tarefa 2 →
`camadas-e-css` (+ `186-09 transversais` e `--suite js`, que são id/modo de **OUTROS** manifestos).
**Zero órfão, zero `<verify>` sem id.** ⚠️ O 1º argumento é **`189-04`**, nunca `04`
(`PRIMEIRO_ARG_NU_NO_PLANO=0`).

⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA está no script com isca nos dois lados**, mesmo este plano não tendo
id `php`: a regex ingênua devolve **0** num filtro `test_(a|b|c)` e o expansor devolve **3** — foi
esse furo que produziu `SEM_ID=14` **acusando trabalho correto** na wave 189-01.

### HOMÔNIMO — **este plano não tem id `php`**, e isso é declarado

`IDS_PHP=[]`. ➡️ O `--filter` do PHPUnit **não roda** sobre este plano, então **não há homônimo de
`--filter` a conferir** (é o `--filter` que SOMA contagens). A régua do sufixo foi cumprida: os **11**
casos top-level terminam em **`189_04`**, e a varredura dos **176** `.test.js` do projeto devolveu
**`HOMONIMOS=[]`**, com isca provando que a sonda VÊ (o nome sabido aparece em **1** arquivo, o meu).

### ⚠️⚠️ ACHADO: **27 homônimos FALSOS** na 1ª rodada do meu próprio script

A 1ª redação contava **TODO** `test('…')`, inclusive os `await contexto.test(…)` — os **SUB-TESTES**.
Resultado: **5 falsos `CASOS_SEM_SUFIXO`** e **27 falsos `HOMONIMOS`**, todos nomes de sub-teste que o
projeto **REUSA DE PROPÓSITO** nos gates da 187 e da 188 (*"o CSS lido é o do manifest, e a PÁGINA
está no manifest"*, *"⚠️ ISCA: a sonda reporta AUSENTE o que não existe…"*).

➡️ **É a família dos "82 homônimos FALSOS" que a régua nomeia, reproduzida no meu detector.** A régua
do sufixo existe por causa de **COLISÃO DE FILTRO**, e filtro nenhum vê sub-teste: o modo `js` do
harness roda o **ARQUIVO INTEIRO**. O invariante é sobre o nome **top-level**, que é o que carrega o
sufixo e o que aparece no roll-up do TAP. **Consertei o script e DECLAREI a contagem dos 27 como
legítima**, em vez de "limpar" nomes que o projeto reusa por convenção.

⚠️ **E um segundo furo do meu próprio script, acusado e consertado:** o `(\S+)` da extração de
`<verify>` engolia o `</automated>` e a crase de fim de linha do markdown, e o id saía como
`camadas-e-css</automated>` → **dois falsos `VERIFY_SEM_ID`**. É a mesma família do
*"escape do shell mastiga"*.

### `--autoteste`: **20 casos, 0 divergência** — COLADO

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

⚠️ **Nenhum gate deste plano chama `node --test` ou `artisan test` direto** — o reporter padrão do
Node v24 emite **`ℹ`**, não `#`, e `grep -E "Tests:|OK"` **PASSA COM TESTE FALHANDO**. As invocações
diretas aqui foram só **MEDIÇÃO** (contagem e nomes dos casos mortos na campanha de mutação), nunca
veredicto de gate.

---

## §13 — OS GATES: os meus e a NÃO-REGRESSÃO dos vizinhos

```
sh tests/gates/verificar-testes.sh 189-04 camadas-e-css  → # tests 16 · # pass 16 · # fail 0 · # skipped 0  PASSOU
                                                            (contagens aceitas: 16,11)
```

| gate vizinho | manifesto dono | resultado | por que importa |
|---|---|---|---|
| `186-09 transversais` | 186-09 | **12 passed / 258 assertions** | ⚠️ **exatamente o critério de aceitação do plano** (`tests=12 assertions=258`); os gates da 186 varrem `aprovacao/` |
| `189-03 lightbox-casca` | 189-03 | **27 / 27 / 0** | o gate do arquivo que eu PROVO, com a contagem que **ele** declara |
| `189-03 vocabulario-tamanhos` | 189-03 | **688 / 688 / 0** | GATE-03 + FID-03/FID-04; piso `>=688` **intacto** |
| `187-11 fechamento-arquivos-e-manifestos` | 187-11 | **6 / 6 / 0** | o `deepEqual` de `JSX_DEPOIS_DA_187` × `readdirSync(aprovacao/)` × `ARQUIVOS_NOVOS` |
| `187-01 tokens-da-fila` | 187-01 | **17 / 17 / 0** | `LIBERADOS_DEPOIS_DA_187` (o `18`) e TOK-15 |
| `187-06 filtros-zindex` | 187-06 | **6 / 6 / 0** | a ordem de camadas da FILA — o gate que é o MODELO do meu |
| `188-07 casca-do-painel` | 188-07 | **45 / 45 / 0** | ⚠️ o `:1035` varre a FONTE de `PainelDeRevisao.jsx` por `/Ampliar/` — **não provocado** |
| `188-08 coluna-de-fotos` | 188-08 | **32 / 32 / 0** | os 2 gates de ausência de "⤢ Ampliar" (`:639`/`:640`) e o de "Gerar novamente" (`:641`) — **intactos** |
| `publicador-header-zindex-ordem.test.js` | *(nenhum manifesto o declara)* | **7 / 7 / 0** | ⚠️ **INTOCÁVEL** — medido direto, e na PI com interseção VAZIA |

⚠️ **Nenhum manifesto alheio foi remedido:** cada vizinho rodou com o `tests=` que ele já declara.
⚠️ **Dois ids que o briefing/herança sugeriam estar em outro manifesto, MEDIDOS no lugar certo:**
`tokens-da-fila` mora em `contagens-187-01.txt` (não em `-09`) e `fechamento-arquivos-e-manifestos`
em `contagens-187-11.txt` (não em `-01`). Chamá-los com a fase errada **ABORTA** com
*"id não está em …"* — o que é o comportamento certo do harness, e por isso foram re-resolvidos.

---

## §14 — PI(189-04) — a saída COLADA, e o LIMITE do recorte DECLARADO

⚠️ **`git diff --numstat -- <alvos>` "sai vazio" é PROIBIDO como gate**: depois do commit ele compara
a ÁRVORE com o índice e sai vazio **SEMPRE** — gate vazio por construção. A prova é pelos **COMMITS
DO ESCOPO**, recortados no **ASSUNTO**.

```
ALVOS=26  FANTASMAS=[]

COMMITS_DO_ESCOPO_NO_ASSUNTO=3
  86b887bf  fix(189-04): o furo que o SOBREVIVENTE M1 acusou — o portal travado por FONTE
  9052db72  feat(189-04): o GATE-09 e as 43 classes no CSS CONSTRUIDO, com as oito normalizacoes
  06e6c206  test(189-04): a cadeia de camadas pela ORDEM e o GATE-09 na FONTE

LIMITE_MEDIDO: --grep(mensagem inteira)=3  assunto=3  so_no_CORPO=[]

PASSADA1_CAMINHOS=2  PASSADA2_CAMINHOS=2  DIVERGENCIA=[]
CAMINHOS_TOCADOS=["tests/gates/contagens-189-04.txt","tests/js/publicador-lightbox-camadas-189.test.js"]

INTERSECAO=[]

ISCA_A1 (188-07) sobre PainelDeRevisao.jsx  -> commits=6  ACUSA=true
ISCA_A2 (188-08) sobre contagens-188-08.txt -> commits=4  ACUSA=true
ISCA_B  o escopo (189-04) nao casa assunto de outra fase -> vazamentos=[]

VIZINHOS (medidos A MAO, para a SUB-captura):
  MEU 86b887bf  fix(189-04): o furo que o SOBREVIVENTE M1 acusou …   toca_alvos=[]
  MEU 9052db72  feat(189-04): o GATE-09 e as 43 classes no CSS …     toca_alvos=[]
  MEU 06e6c206  test(189-04): a cadeia de camadas pela ORDEM …       toca_alvos=[]
      fdc88da6  docs(189-03): a SUMMARY do lightbox …               toca_alvos=[]
      d8b30fdf  feat(189-03): o lightbox POR PORTAL em z-[41] …     toca_alvos=["…/LightboxDaImagem.jsx","tests/gates/contagens-189-03.txt","tests/js/publicador-lightbox-casca-189.test.js"]
      9e54a884  test(189-03): o portal, a profundidade e os ordinais …  toca_alvos=["tests/js/publicador-lightbox-casca-189.test.js"]

PI(189-04): PASSOU — intersecao VAZIA, duas passadas concordam, duas iscas exercidas.
```

**Os 26 alvos** (todos com `test -e` → existe; **zero fantasma**): os seis `.jsx`/`.test.js` da cadeia
de z (`PublicadorHeader`, `AppLayout`, `CascaDeModal`, `ToastDaFila`, `LightboxDaImagem`,
`PainelDeRevisao`), `Pages/Mlb/Publicador/Aprovacao.jsx`,
`publicador-header-zindex-ordem.test.js`, `publicador-lightbox-casca-189.test.js`,
`contagens-189-03.txt`, os seis gates de lista constante, `estrutura-meus-anuncios.test.js`
(D-11/D-22), `tailwind.config.js`, **`package.json` e `package-lock.json`** (T-189-SC) e os cinco
documentos de `.planning/` (`ROADMAP.md`, `REQUIREMENTS-v27.md`, `STATE.md`, `189-REGUA.md`,
`189-COBERTURA.md`, `189-CONTRATO-188.md`).

### ⚠️⚠️ O LIMITE DO RECORTE, DECLARADO — as três faces

| face | o que foi medido nesta wave |
|---|---|
| **SOBRE-captura** | `git log --grep` lê a **MENSAGEM INTEIRA**. Medido: `--grep=3` × `assunto=3` → **`so_no_CORPO=[]`**. ⚠️ **O limite não disparou AQUI, mas é real** (com `(186-01)` casaram **5** commits só pelo CORPO) — por isso o recorte do script é no **`%s`**, não no `--grep` |
| **SUB-captura** | commit que tocou um alvo **SEM** o escopo no assunto **não é visto**. ➡️ Os **vizinhos foram medidos À MÃO** e a saída mostra o risco **concretizado**: `d8b30fdf` e `9e54a884` **tocaram três alvos meus** — e são do **189-03**, não meus. Sem a medição à mão, a PI não saberia distinguir |
| **AUTOR** | **as três máquinas usam o mesmo `MB.ECF-100376`**. ➡️ **autor NUNCA foi critério**, em nenhuma passada |

⚠️ **E a PARTIDA (`fdc88da6`) foi usada APENAS como referência de leitura** — nunca como extremo de
range.

```
PI(189-04) sobre package.json e package-lock.json → INTERSECAO=[] (T-189-SC)
```

---

## §15 — OS PONTEIROS RECONFIRMADOS POR `sed`

| ponteiro citado | conteúdo MEDIDO por `sed -n 'Np'` | veredicto |
|---|---|---|
| `header/PublicadorHeader.jsx:174` | `<header className="sticky -top-6 z-30 … backdrop-blur-[14px] backdrop-saturate-[1.4]">` | ✅ **`-top-6`** (a outra sessão trocou o `top-0`, por fora da fase) e o `z-30` **NÃO** mudou. ⚠️ **O `backdrop-filter` continua lá → a razão do portal segue válida** |
| `aprovacao/BarraDeSelecao.jsx:58` | `'fixed bottom-[24px] left-1/2 z-[35] …` | ✅ |
| `aprovacao/PainelDeRevisao.jsx:154` | `export const CLASSES_BACKDROP = 'fixed inset-0 z-[39] bg-black/[0.55]';` | ✅ |
| `aprovacao/PainelDeRevisao.jsx:164` | `'fixed inset-y-0 right-0 z-[40] flex w-[min(1140px,calc(100vw-100px))] flex-col '` | ✅ |
| `aprovacao/CascaDeModal.jsx:54` | `export const CLASSES_BACKDROP = 'fixed inset-0 z-[42] flex items-start justify-center bg-black/[0.65] pt-[96px]';` | ✅ |
| `aprovacao/ToastDaFila.jsx:69` | `'fixed left-1/2 top-[124px] z-[45] flex max-w-[560px] …` | ✅ ⚠️ `w-[560px]` é **SUBSTRING** de `max-w-[560px]` — a âncora deste gate é `top-[124px] z-[(\d+)]`, não a largura |
| `aprovacao/LightboxDaImagem.jsx:424` | `export const CLASSES_RAIZ = 'fixed inset-0 z-[41] grid grid-cols-[minmax(0,1fr)_320px] '` | ✅ |
| `publicador-lightbox-casca-189.test.js:1352` | `assert.ok(FONTE_LIGHTBOX.includes('createPortal'), …)` | ✅ existe — e é **o achado do §10** (mais largo que o invariante) |
| protótipo `:727`, `:728`, `:729`, `:730`, `:731`, `:733`, `:736`, `:738`, `:741`, `:742` | §8 — **12 ponteiros, 0 deslocados** | ✅ |

---

## §16 — O QUE **NÃO SE PROVA** SEM NAVEGADOR, DECLARADO

⚠️⚠️ **Eu NÃO vi tela renderizada.** Não há navegador, o `mysqld` local está **PARADO**
(`tasklist | grep mysqld` conferido), o banco não tem empresa com `ml_token` (a rota dá **404**),
`pub_aprovacao_ofertas` está **vazia** e a fila só ganha oferta na **Fase 194**.
➡️ **"A tela mostra X" aqui significa "o CSS/HTML gerado contém X".**

| afirmação | provado? | como, ou por que não |
|---|---|---|
| a classe arbitrária **existe no CSS com o valor certo** | **SIM** | corpo da regra no bundle (§4), com as oito normalizações |
| a ORDEM de empilhamento está monotônica **no código** | **SIM** | a cadeia lida das sete fontes, com as duas iscas (§7) |
| as três formas de transição **não compilam** neste projeto | **SIM** | CSS construído, com `duration-200` de controle (§5) |
| o lightbox **de fato cobre** o painel na tela | **NÃO** | depende de contexto de empilhamento em runtime. O que se prova é o número e a ordem |
| o `createPortal` **de fato porta** o nó para `document.body` | **NÃO** | ⚠️ **premissa inobservável**: a guarda de ambiente retorna `null` no render de servidor **antes** de o portal ser alcançado. **TRAVADA por igualdade de FONTE**, com o teste DECLARANDO que é proxy (§10) |
| o zoom **acompanha o cursor** e a animação de `.15s` acontece | **NÃO** | a **FUNÇÃO** está provada pelo 189-03; **o evento disparar, a imagem acompanhar o cursor e a animação NÃO estão** |
| a fonte Geist chega ao conteúdo portado | **NÃO** | prova-se que `font-pub` está na raiz do portal e compila para `font-family:Geist…`; a herança em runtime, não |

⚠️ **As conferências visuais das Fases 187 E 188 seguem ABERTAS e SEPARADAS** — nenhuma é presumida
feita. `PERGUNTA-VISUAL-189` segue **ABERTA** (dono: usuário), e o roteiro é do **189-08**.
⚠️ **Aprovar ENFILEIRA; a escrita no Mercado Livre é da Fase 193.** Este plano **não cria rota
nenhuma**, não lê banco, não chama serviço e **não faz requisição ao ML**.
⚠️ **A Fase 192 ("✦ Gerar novamente com IA") NÃO foi antecipada**, e o "Comparar lado a lado"
(Fase 190) também não. ⚠️ **Esta fase não tem migration e `artisan migrate` NÃO foi executado.**
⚠️ **Nada foi deployado, nada foi empurrado para o remoto, nenhum comando tocou a VPS.**

---

## §17 — Deviations from Plan

### Divergências e auto-correções (nenhuma pediu permissão: são Regras 1-3 ou divergência declarada)

**1. [Rule 1 - Gate frouxo, achado por MUTAÇÃO SOBREVIVENTE] o portal não estava provado em lugar nenhum**
- **Found during:** rodada 1 da campanha de mutação (tarefa 2)
- **Issue:** M1 sobreviveu nos DOIS gates. Medido: (a) nenhum render pode vê-la (a guarda de ambiente
  curta-circuita antes do `createPortal`); (b) a sonda do 189-03 (`:1352`) usa
  `includes('createPortal')`, que o mutante **satisfaz** mantendo o identificador.
- **Fix:** caso novo **no meu gate**, travando a premissa pelas formas EXATAS, **declarando que é
  PROXY DE FONTE**, com isca construída com o mutante de verdade. Rodada 2: **M1 MORREU**.
- **Files:** `tests/js/publicador-lightbox-camadas-189.test.js`, `tests/gates/contagens-189-04.txt`
- **Commit:** `86b887bf`

**2. [Divergência declarada] a previsão do briefing sobre `font-extrabold` saiu FALSA**
- O briefing dizia que a sonda procurando a forma NOMEADA *"NÃO acha"*. **MEDIDO: ela ACHA**
  (`font-weight:800`, 135 donos em `resources/`) — ou seja, passaria **verde pelo motivo errado**,
  que é pior. §6. **Nada mudou no código**; a sonda procura `font-[800]` e há asserção declarando as
  duas formas.

**3. [Divergência declarada] `bg-[rgba(5,5,5,0.94)]` sai como HEX DE 8 DÍGITOS**
- Medido: `background-color:#050505f0`, não `rgba(…)`. Era exatamente o *"meça, não presuma"* da §23.
  A regex do gate foi escrita **depois** da medição. §4.

**4. [Rule 1 - furo do meu próprio script] 27 homônimos FALSOS + 5 falsos `CASOS_SEM_SUFIXO`**
- A 1ª redação da bijeção contava sub-testes. ➡️ São nomes que o projeto **REUSA de propósito**, e
  filtro nenhum os vê. Script consertado para contar o **top-level**, com a contagem dos 27
  **DECLARADA como legítima**. §12. **Nada no código de teste mudou por causa disso.**

**5. [Rule 1 - furo do meu próprio script] o `(\S+)` engolindo `</automated>`**
- Dois falsos `VERIFY_SEM_ID`. Consertado por recorte em caracteres legítimos de id. §12.

**6. [Rule 3 - bloqueio de medição] `node -e` com aspas aninhadas falhou**
- Uma medição de agulhas por `node -e` saiu com erro de escape do shell — **a própria família que a
  régua proíbe**. Reescrita como script `.cjs` em arquivo (`agulhas-portal-189-04.cjs`). **Nada no
  código mudou**; o erro era 100% da ferramenta de medida.

**7. [Critério do plano remedido pela MEDIÇÃO, declarado]** o critério de aceitação citava a lista (b)
com 19 classes e a §23 com 12. **A lista constante medida tem 43 com valor + 15 só de presença** —
porque a conferência é amarrada aos **TOKENS DA FONTE** (zero entrada morta), e a fonte escreve 58
classes. **Não é inflação**: cada entrada é conferida contra o arquivo.

**8. [Declaração, não desvio] `vocabulario-tamanhos` sem linha de id**
- O briefing pede "linha própria"; a régua escopa "em todo manifesto **que o roda**". Este plano não o
  roda → linha de id criaria **ÓRFÃO**. Declarado em linha própria **como MEDIÇÃO** (688), e rodado
  pelo manifesto do dono. §11.

**9. [Ordem mecânica remedida] `camadas-e-css` de `8` para `16,11`**
- MEDIDO em três pontos, com **dois REDs** registrados. §11. **Não é "subir número para passar".**

### TDD — o desvio, declarado

A tarefa 1 saiu em **UM** commit (`test(189-04)` `06e6c206`), **sem um RED separado**: o código sob
teste (`LightboxDaImagem.jsx`) **já estava commitado pelo 189-03**, então não havia implementação a
escrever depois do teste. ⚠️ **A prova de que aquele gate não é vazio veio pela campanha de mutação**
(§10: M1, M2, M3, M4 e M5 matam casos dele). A camada de CSS da tarefa 2 **teve RED medido**
(`# tests 15 · # pass 13 · # fail 2`). ➡️ **Gate sequence no `git log`:** `test(189-04)` existe,
`feat(189-04)` vem depois, e um `fix(189-04)` fecha o furo que a mutação acusou. Não houve `refactor`.

### Nada foi devolvido ao usuário

Nenhum **QUINTO conflito protótipo × regra do projeto** apareceu (a divergência `font-extrabold` ×
`font-[800]` é **por valor idêntico** e já estava declarada pelo 189-03) · **nenhum gate alheio
reprovou** · **o CSS saiu certo SEM mexer na fonte do `LightboxDaImagem.jsx`** (a PI prova interseção
vazia) · e **não houve WIP alheio em `resources/`** em nenhum dos quatro builds.

---

## §18 — Known Stubs

**Nenhum.** Este plano **não escreve componente, não renderiza nada, não lê banco e não cria rota** —
ele só mede. Varredura dos arquivos criados/modificados: zero `=[]`/`={}`/`=null`/`=""` fluindo para
render, zero *"não disponível"*/*"em breve"*/*"TODO"*/*"FIXME"*, zero `onClick` vazio, zero `disabled`
decorativo.

⚠️ Os stubs **declarados pelo 189-03** seguem valendo e seguem sendo dos planos donos: o slot
`lateral` chega `null` (é o **189-05**), o componente não tem consumidor (é o **189-06**/**189-07**), e
a prop `lightbox.originais[...]` da 189-02 não é consumida (é a **189-05**/**189-06**). **Eu não liguei
nenhum deles "por adiantamento".**

---

## §19 — Threat Flags

Nenhuma superfície de segurança nova: este plano é **100% gate**. Não cria rota, não lê banco, não
chama serviço, não instala pacote, não toca `app/`.

```
PI(189-04) sobre package.json e package-lock.json → INTERSECAO=[] (T-189-SC)
```

| threat do plano | disposição | como ficou |
|---|---|---|
| **T-189-22** (ordem de camadas quebrada — modal de reprovar atrás do lightbox) | **mitigado** | §7 — cadeia de 6 elos lida das 7 fontes, com razão escrita por elo, isca de INVERSÃO e isca de TROCA DE NÚMERO; o `90` recusado POR PEÇA; mutações M3 e M4 matando. ⚠️ O **contexto de empilhamento** do modal é prova do **189-07** |
| **T-189-24** (transição que não compila com teste verde) | **mitigado** | §5 — GATE-09 na FONTE **e** no CSS, com `duration-200` de controle e a asserção que exige a transição por `style`; mutação M5 matando |
| **T-189-26** (sonda de CSS cega afirmando presença) | **mitigado** | §4/§9 — escape **GERAL**, as oito normalizações **medidas**, 9 iscas **medidas** (6 candidatas rejeitadas por existirem), sonda não-cega antes de cada `deepEqual`, e a lista amarrada aos **tokens da FONTE** |
| **T-189-27** (conclusão de mutação com script sobrescrito por agente paralelo) | **mitigado** | §10 — `mutar-189-04.sh` + `mutacoes-189-04.cjs` com o plano no nome, e o `sha256` **dos próprios scripts** impresso antes de qualquer veredicto |
| **T-189-SC** (npm installs) | **mitigado** | nada instalado; PI sobre os dois arquivos de pacote |

**Nenhuma superfície NOVA** de rede, auth, acesso a arquivo ou schema foi introduzida → **sem
`threat_flag`**.

---

## §20 — O que a próxima wave precisa saber

| wave | o que consumir, e a armadilha |
|---|---|
| **189-05** | o bundle está construído e **as 43 classes do lightbox estão provadas no CSS**. ⚠️ **As classes NOVAS da lateral de 320px NÃO estão** — você precisa de `npm run build` **depois** de criar o `LateralDoLightbox.jsx`, e a sua camada de CSS precisa de `FONTES_DO_CSS` com o **seu** arquivo. ⚠️ **MEÇA cada forma antes de escrever a regex:** nesta wave `rgba(5,5,5,0.94)` saiu **`#050505f0`** (hex de 8 dígitos) contra a expectativa. ⚠️ **ISCAS: `z-[90]`, `text-[17px]`, `w-[13px]`, `h-[64px]`, `left-[30%]` e `grid-cols-6` EXISTEM** — não use nenhuma. As nove que medi como ausentes estão em §9 |
| **189-06** | a cadeia de z está provada e **o `z-[41]` é intocável sem remedir o gate `camadas-e-css`**. ⚠️ Se você acrescentar prop ao `PainelDeRevisao.jsx`, **`casca-188:1035` varre a FONTE dele por `/Ampliar/`** — uma prop `onAmpliar` ali **REPROVARIA**; use `onAbrirLightbox`. ⚠️ E as duas linhas a inverter em `publicador-painel-fotos-188.test.js` são **`:639`/`:640`** e só elas |
| **189-07** | o `z-[42]` da casca fica **acima** do `z-[41]` do lightbox, **provado pela cadeia** — o modal aberto pelo lightbox **aparece**. ⚠️ Se você MOVER a montagem de algum modal, **remeça o gate `camadas-e-css`**: ele lê `CascaDeModal.jsx:54` por âncora |
| **189-08** | ⚠️ **TRÊS coisas para registrar:** (1) o achado do **`publicador-lightbox-casca-189.test.js:1352`** — `includes('createPortal')` é **mais largo que o invariante** e o mutante M1 o satisfaz; o conserto ficou do lado do 189-04 e **o `:1352` NÃO foi editado**; (2) a previsão do briefing sobre `font-extrabold` saiu **FALSA** (§6) e isso vale para o learnings; (3) `rgba(…)` em `background` **normaliza para hex de 8 dígitos** (§4) — é uma 9ª face da 3ª normalização. ⚠️ E registre que **o MOVIMENTO do zoom e o PORTAL em runtime NÃO são provados** (§16), e que `PERGUNTA-VISUAL-189` e as conferências visuais das Fases **187 e 188** seguem **ABERTAS e SEPARADAS** |

---

## Self-Check: PASSED

**Arquivos criados — `test -e`:**
```
FOUND: tests/js/publicador-lightbox-camadas-189.test.js   (923 linhas, 11 casos top-level + 5 sub-testes)
FOUND: tests/gates/contagens-189-04.txt                   (1 id: camadas-e-css, tests=16,11)
FOUND: .planning/phases/189-…/189-04-SUMMARY.md           (este arquivo)
```

**Commits — `git log --oneline --all | grep -q`:**
```
FOUND: 06e6c206  test(189-04): a cadeia de camadas pela ORDEM e o GATE-09 na FONTE
FOUND: 9052db72  feat(189-04): o GATE-09 e as 43 classes no CSS CONSTRUIDO, com as oito normalizacoes
FOUND: 86b887bf  fix(189-04): o furo que o SOBREVIVENTE M1 acusou — o portal travado por FONTE
```

**Mínimo de linha do plano:** `publicador-lightbox-camadas-189.test.js` **923 >= 200** ✅

**Gates:** `camadas-e-css` **16/16/0** (regimes `16,11`) ✅ · `186-09 transversais` **12/258** ✅ ·
`189-03 lightbox-casca` **27/27/0** ✅ · `189-03 vocabulario-tamanhos` **688** ✅ ·
`187-01 tokens-da-fila` **17/17/0** ✅ · `187-06 filtros-zindex` **6/6/0** ✅ ·
`187-11 fechamento` **6/6/0** ✅ · `188-07 casca-do-painel` **45/45/0** ✅ ·
`188-08 coluna-de-fotos` **32/32/0** ✅ · `publicador-header-zindex-ordem` **7/7/0** (INTOCADO) ✅ ·
`--autoteste` **20/0** ✅ · **PI(189-04)** interseção VAZIA, 2 passadas concordam, 2 iscas
exercidas ✅ · **bijeção** zero órfão / zero homônimo ✅ · **mutação** 8 mutações, **0 sobreviventes**
(1 sobrevivente na rodada 1, **investigado e consertado**) ✅

**Build:** `npm run build` **exit 0** (4 vezes, a última **DEPOIS** da mutação) · MANIFEST pelo JSON
**498 / 203** · PÁGINA `Aprovacao.jsx` presente · isca `NaoExiste9z7.jsx` ausente · componente
`LightboxDaImagem.jsx` **ausente do manifest** (invariante re-medido) ✅

**Suítes:** JS **5924 / 5923 / 1 / 0** (a única falha é a herdada da Fase 87, atribuída por execução
**ISOLADA**: 19/18/1) · PHP **RECORTE** **2595 / 23878 / 0** — ⚠️ a suíte PHP **completa NÃO é
medível** nesta máquina (Fatal de memória em processo **FILHO**; `-d memory_limit=-1` não resolve).

**Protótipo:** `sha256` **IDÊNTICO** (`68624883ce35547e`) · **12 ponteiros da §B, 0 deslocados** ·
`new_layout_publicador/Aprovacao.dc.html` **NÃO EXISTE** (medido) ✅

**Território:** `git diff HEAD -- resources/` **VAZIO** antes de cada build · **27 `stash` apenas
LISTADOS** · nenhuma lista constante alheia tocada · nenhuma contagem de manifesto alheio remedida ·
nenhum `git add -A`, `git stash`, `git reset`, `git checkout`, `git clean` ou `--amend` · nenhum
`pint`, nenhum worktree, nenhum `state.advance-plan`, nenhum deploy, nenhum push, nenhum comando na
VPS ✅
