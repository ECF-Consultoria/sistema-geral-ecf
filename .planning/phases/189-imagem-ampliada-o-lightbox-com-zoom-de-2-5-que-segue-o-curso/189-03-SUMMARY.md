---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 03
subsystem: publicador-aprovacao
tags: [lightbox, portal, z-index, profundidade, marcadores, miniaturas, zoom, gate-09, mutacao]
wave: 2
requires: [189-REGUA.md, 189-FID01.md, 189-CONTRATO-188.md, 189-BASELINES.md, ImagemGrandeDoPainel, GradeDeMiniaturas, PainelDeRevisao]
provides:
  - resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx (10 funções puras + 5 peças + 3 handlers, todos named export; default POR PORTAL em `z-[41]`)
  - tests/js/publicador-lightbox-casca-189.test.js (27 casos, 2 ids)
  - tests/gates/contagens-189-03.txt (2 ids, bijeção exata, zero órfão)
  - a entrada em `ARQUIVOS_NOVOS` e em `JSX_DEPOIS_DA_187` (as DUAS escriturações)
affects: [189-04, 189-05, 189-06, 189-07, 189-08]
tech-stack:
  added: []
  patterns: [portal-com-guarda-de-ambiente, named-exports-contra-o-ssr, contraprova-dupla, profundidade-nao-aparencia, lista-constante, isca-nas-duas-direcoes, escopo-do-assunto, import-em-vez-de-redesenhar, estilo-por-style-nunca-por-classe]
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx
    - tests/js/publicador-lightbox-casca-189.test.js
    - tests/gates/contagens-189-03.txt
  modified:
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-fechamento-187.test.js
decisions:
  - "O lightbox é `z-[41]`, provado pela ORDEM (header 30 < backdrop 39 < painel 40 < LIGHTBOX 41 < modal 42 < toast 45) e com o `90` do protótipo RECUSADO para esta peça"
  - "A contraprova do portal é DUPLA: `render(composto) === ''` E a raiz com o palco DE VERDADE dentro saindo > 1000 bytes com 5 marcas das peças"
  - "A PROFUNDIDADE foi medida no protótipo por contagem de aberturas/fechamentos: marcadores DENTRO da camada do zoom, setas FILHAS do palco, faixa IRMÃ da caixa"
  - "`font-[800]` e não `font-extrabold` no marcador — a FID-01 §B7 prescreve o nomeado e o GATE-03 o PROÍBE; valor computado IDÊNTICO"
  - "As setas NÃO nascem com UMA foto — D4, e o protótipo nunca expressa esse caso (silêncio, não conflito). Reversível num ternário"
  - "`onAlternarZoom` NÃO é prop deste componente: a lateral chega como NÓ pronto, e declará-la aqui seria entrada morta"
  - "O MOVIMENTO do cursor na tela NÃO é provado — só a função `origemDoZoom`. Declarado no docblock, no gate e aqui"
  - "O critério `grep -c \"aprovacao/LightboxDaImagem.jsx\"` do plano é FALSO NA PARTIDA (o arquivo usa `${DIR_APROV}/`) — declarado, não ajustado"
metrics:
  duration: ~4h
  completed: 2026-10-20
  tasks: 2
  commits: 3
  mutacoes: 7
---

# Fase 189 Plano 03: o `LightboxDaImagem.jsx` — a casca de tela cheia por portal Summary

A **metade esquerda do E3** num `.jsx` novo: as **10 funções puras** do zoom (a origem pelo cursor, a
volta na galeria, o clamp, o rótulo, o cursor e a contagem), a **casca por portal** em `z-[41]`, o
**palco** com a imagem 1:1 de até 78vh, a **camada do zoom** com a transição inteira por `style`, os
**marcadores numerados** nas coordenadas do servidor, a faixa de **miniaturas** e as **setas** ← → de
18px. A lateral de 320px fica num **slot** (é o 189-05). Nenhum serviço nem `.jsx` das Fases
186/187/188 foi alterado — o que elas desenham é **IMPORTADO**.

---

## §1 — A PARTIDA, e as baselines RE-MEDIDAS

```
PARTIDA:  ddfbf091  (ddfbf091a189cb07b0f958089b9d0fa7a403a3ba) — 2026-10-20T15:17:03Z
          ⚠️ referência de LEITURA, NUNCA extremo de range (o território vai por ESCOPO DO ASSUNTO)
COMMITS:  59446cdf (feat, tarefa 1) · 9e54a884 (test, RED da tarefa 2) · d8b30fdf (feat, GREEN)
HEAD:     d8b30fdf
```

| número | baseline do briefing | medido na PARTIDA | medido no FIM | veredicto |
|---|---|---|---|---|
| JS — testes | 5874 | **5874** | **5908** | **ACIMA** (+34) — exatamente os meus: 27 do gate novo + 7 sub-testes do `vocabulario-novo` |
| JS — pass | 5873 | **5873** | **5907** | ACIMA (+34) |
| JS — fail | 1 | **1** | **1** | **IGUAL** — a MESMA falha herdada, nomeada na §2 |
| JS — skipped | 0 | **0** | **0** | IGUAL |
| PHP (recorte) — testes | 2595 | **2595** | **2595** | **IGUAL** (este plano não toca PHP) |
| PHP (recorte) — asserções | 23877 | **23878** | **23878** | ⚠️ +1 sobre o briefing — **asserção NÃO é invariante** (a régua do harness diz isso na fonte); comparam-se **testes e falhas** |
| PHP (recorte) — falhas | 0 | **0** | **0** | IGUAL |
| MANIFEST | 498 / 203 | — | — | **NÃO RE-MEDIDO**: este plano **não roda `npm run build`** (é do 189-04), então o manifest não pode ter mudado |
| PROTÓTIPO | 1432 linhas / `68624883ce35547e` | **sha IDÊNTICO** | **sha IDÊNTICO** | IGUAL — ver a ressalva de MÉTODO abaixo |

**O comando de cada linha:**

| chave | comando |
|---|---|
| `PARTIDA` | `git rev-parse --short HEAD` — **a primeira ação desta sessão** |
| JS | `sh tests/gates/verificar-testes.sh --suite js` |
| PHP | `sh tests/gates/verificar-testes.sh --suite php` (ele roda `tests/Unit/Publicador` + `tests/Feature/Publicador`) |
| PROTÓTIPO | `sha256sum design_handoff_publicador/Aprovacao.dc.html \| cut -c1-16` |

### ⚠️⚠️ A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA

`artisan test` **aborta com Fatal de memória** em `Illuminate\Http\Testing\FileFactory.php:87`, a ~6%
de 10.653 testes, e **`-d memory_limit=-1` NÃO resolve** (o estouro é em processo **FILHO**).
➡️ **A medida é o RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`), que é o que o
`--suite php` do harness roda. **Isto está declarado, não contornado.**

### ⚠️ A ressalva de MÉTODO no protótipo: `1432` × `1433` não é mudança

Medido:

```
wc -l < design_handoff_publicador/Aprovacao.dc.html   → 1432
split(/\r?\n/).length (node)                          → 1433
última linha do split                                 → "" (vazia)
sha256 | cut -c1-16                                   → 68624883ce35547e  (IDÊNTICO)
```

`wc -l` conta **terminadores de linha**; o `split` devolve um elemento vazio a mais porque o arquivo
**termina com newline**. ➡️ **O documento NÃO se moveu** (o `sha256` prova), e a divergência é de
MÉTODO DE MEDIDA. ⚠️ Foi por isso que o caso `LB-189-15` cobra `linhas.length >= 1432` e não a
igualdade: uma igualdade com `1432` medida por `split` REPROVARIA um arquivo intacto.

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

### 2.2 — PHP: o recorte está em **0 falhas**

A falha alheia que a `189-BASELINES.md` §3.3 registrou
(`FotosDoPainelServiceTest::test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03`)
**não aparece** nesta medição — o recorte está em `2595 / 23878 / 0`. ⚠️ **Não a consertei**; ela
deixou de falhar por fora desta wave, e o registro de que existiu fica na `189-BASELINES.md`.

### 2.3 — Fora do recorte, nomeadas e NÃO medidas aqui

`Tests\Unit\CalcularFaixaTest` (9) · `CompanyServiceTypeTest::test_service_type_aceita_polo` (1, a
armadilha **enum + CHECK do SQLite** já na memória do projeto) ·
`Phase39\MercadoLivreSugadoresProviderTest` (2). Os dois de `CockpitDaContaTest` **oscilam com a hora
do dia** (provado em waves anteriores). **Nenhuma é desta fase e nenhuma foi corrigida.**

---

## §3 — ⚠️⚠️ O QUE MEDI DE NOVO NOS DOIS ARQUIVOS QUE A OUTRA SESSÃO ACABOU DE MEXER

O briefing avisou que `31b73ed9` e `927117b0` entraram durante a wave 189-02 e tocam vizinhos meus.
**Medi os dois antes de concluir qualquer coisa.**

### 3.1 — `header/PublicadorHeader.jsx` (o dono do `backdrop-filter`)

```
git show --stat 31b73ed9  → "fix(publicador): header global cola no topo, sem a faixa de 24px ao rolar"
                            PublicadorHeader.jsx | 11 +++++++----   (e 13 outros arquivos)
```

**MEDIDO AGORA, na linha `:174`:**

```
<header className="sticky -top-6 z-30 -mx-6 -mt-6 min-w-[1240px] border-b border-pub-line-subtle-2
  bg-pub-page/[0.86] font-pub text-pub-txt backdrop-blur-[14px] backdrop-saturate-[1.4]">
```

➡️ **O `backdrop-filter` CONTINUA LÁ** (`backdrop-blur-[14px]` + `backdrop-saturate-[1.4]`), e o
docblock daquele arquivo (`:36`-`:37`) declara textualmente que ele **cria containing block para
`position: fixed`**. ➡️ **A razão do portal segue válida, agora por medição e não por herança do
briefing.** O que mudou foi `top-0` → **`-top-6`** (o `<main>` do `AppLayout` tem `p-6` e o sticky
cola na borda do CONTEÚDO) e o `z-30` **não se moveu**.
⚠️ **Eu não editei esse arquivo** — ele está na lista do PI (§7).

### 3.2 — `tests/js/publicador-fila-zindex-187.test.js`

```
git show 31b73ed9 -- tests/js/publicador-fila-zindex-187.test.js  → 10 linhas, 5 +/5 -
```

**MEDIDO: a mudança é SÓ nas ÂNCORAS de texto**, em três lugares:
`/<header className="sticky top-0 z-(\d+)/` → `/<header className="sticky -top-6 z-(\d+)/` ·
`top-[108px]` → `top-[84px]` · e a **isca `zzz-`** reescrita junto.
➡️ **A ORDEM de z-index NÃO mudou, e nenhum número z mudou.** Os valores que o meu docblock cita
foram **re-medidos no disco hoje**, um por um:

| peça | arquivo:linha | z (medido 20/10) |
|---|---|---|
| header global | `header/PublicadorHeader.jsx:174` | `z-30` |
| `BarraDeSelecao` | `aprovacao/BarraDeSelecao.jsx:58` | `z-[35]` |
| backdrop do painel | `aprovacao/PainelDeRevisao.jsx:154` | `z-[39]` |
| painel | `aprovacao/PainelDeRevisao.jsx:164` | `z-[40]` |
| **lightbox (esta wave)** | `aprovacao/LightboxDaImagem.jsx:424` | **`z-[41]`** |
| casca de modal | `aprovacao/CascaDeModal.jsx:54` | `z-[42]` |
| toast | `aprovacao/ToastDaFila.jsx:69` | `z-[45]` |

E os dois gates de camada ficaram **VERDES com o meu `.jsx` no disco**:
`publicador-fila-zindex-187` **6/6/0** · `publicador-header-zindex-ordem` **7/7/0** (⚠️ este é
**INTOCÁVEL**, nasceu de um bug relatado pelo usuário em 12/10, e a outra sessão o editou — **eu não**).

### 3.3 — `useAcervoDaConta.js` (`927117b0`)

Território **vetado** e **não tocado**; está na lista do PI (§7). Nada do meu plano o lê.

---

## §4 — ⚠️⚠️ O PORTAL: a armadilha central da wave, resolvida

### 4.1 — As named exports, em LISTA CONSTANTE

O renderizador de **servidor** do React **não renderiza portal** (*"Portals are not currently
supported by the server renderer"*). ➡️ Um teste de render do **composto** veria **HTML VAZIO** e
**passaria por omissão** — gate VERDE provando NADA, e sem deixar sinal.

**A solução é a da Fase 178, repetida pela 187-09 e pela 188-07:** toda peça interna é **named
export**, exercida DIRETAMENTE. A lista é **CONSTANTE** (nunca `>= N`: **piso não prova PRESENÇA**):

```
PECAS    = [RaizDoLightbox, PalcoDoLightbox, MarcadoresDoLightbox, MiniaturasDoLightbox, SetasDoLightbox]  (5)
HANDLERS = [aoMoverOCursor, aoClicarNaSeta, aoClicarNaMiniatura]                                            (3)
PURAS    = [ZOOM_NORMAL, ZOOM_AMPLIADO, origemDoZoom, moverNoLightbox, indiceNoLightbox,
            rotuloDoZoom, classeDoCursorDoZoom, estiloDoZoom, alternarZoom, contagemDoLightbox]            (10)
```

Os handlers são exportados porque **`renderToStaticMarkup` não dispara `onClick`** — e a tela preta da
187-06 foi **no clique**.

### 4.2 — A CONTRAPROVA, em DUAS partes (e o conserto MEDIDO dela)

```js
// (a) o composto sai VAZIO — o esperado, e a guarda é o que faz sair '' em vez de LANÇAR
assert.equal(render(LIGHTBOX.default, PROPS_ABERTO), '');

// (b) e a raiz, exercida direto COM O PALCO DE VERDADE dentro, sai CHEIA
assert.ok(raiz.length > 1000);
for (const marca of ['w-[min(78vh,100%)]', 'h-[26px]', 'h-[56px]', 'h-[44px]', 'z-[41]']) {
    assert.ok(raiz.includes(marca));
}
```

⚠️⚠️ **CONSERTO MEDIDO (e a razão vale mais que o número).** A redação do RED montava a raiz com um
filho-**STUB** (`<span>palco</span>`) e cobrava `> 200`. **MEDIDO: 139 bytes** — e **102 deles eram a
própria string de `CLASSES_RAIZ`**. Ou seja, o limiar media **o tamanho do LITERAL**, não a árvore
portada: era quase um gate vazio por construção. ➡️ Trocado pelo **palco DE VERDADE** com `> 1000`
**mais cinco marcas das peças**. O invariante não mudou (*"o conteúdo portado existe quando exercido
direto"*); **a sonda ficou mais FORTE** — um palco que devolvesse `null` agora REPROVA, e a mutação
**M2** confirmou isso (ela matou LB-189-16 **e** LB-189-17).

### 4.3 — A guarda de ambiente, e por que não é `!document`

```
grep -cF "typeof document === 'undefined'" LightboxDaImagem.jsx → 3
```
`typeof document === 'undefined'` e **não** `!document`: a segunda forma **LANÇA** no mesmo lugar que
se quer evitar. O caso `LB-189-25` prova `assert.doesNotThrow(...)` **e** que a guarda está na fonte.

### 4.4 — O `font-pub` na raiz do portado, com a razão no docblock

Conteúdo portado **não herda a fonte**: ele sai da subárvore do módulo e volta a herdar do
`document.body`, que é `font-sans` (Inter). ➡️ `CLASSES_RAIZ` declara **`font-pub text-pub-txt`**, e o
docblock explica que é **REND-05**, não decoração. ⚠️ `CascaDeModal.jsx` **não** declara `font-pub` e
**está certo** (ela não é portada, e herda) — isso também está no docblock, para ninguém "consertar"
aquele arquivo.
A mutação **M3** (tirar `font-pub`) **matou LB-189-17**.

### 4.5 — Os três motivos, declarados no docblock

Medido no arquivo: o bloco `⚠️⚠️ O PORTAL — OS TRÊS MOTIVOS` traz (1) **por que portal** (o
`backdrop-filter` do header cria containing block; e peça "irmã do `<main>`" seria impossível sem
tocar o `AppLayout.jsx`, **INTOCÁVEL**); (2) **por que as named exports** (o SSR não desenha portal →
o teste passaria por omissão); (3) **por que `font-pub` na raiz** (REND-05).

---

## §5 — ⚠️⚠️ O REMAPEAMENTO DE Z-INDEX, PROVADO PELA ORDEM

O protótipo deu **`z-index:90`** a esta peça (`:727`), entre o painel (`70`) e o modal (`100`).
➡️ **`z-[41]`**, preservando a **ORDEM RELATIVA** medida no disco (tabela em §3.2):

```
header 30  <  BarraDeSelecao 35  <  backdrop 39  <  painel 40  <  LIGHTBOX 41  <  modal 42  <  toast 45
```

**Por que exatamente entre 40 e 42:** ACIMA do painel porque o lightbox o cobre; ABAIXO da casca de
modal porque o "Reprovar" do lightbox abre o modal **POR CIMA** dele (`:1330`) e o `Escape` fecha
**modal → lightbox → painel** (`:1014`, re-medido por `sed`).

**A sonda recusa o número que o protótipo deu A ESTA PEÇA**, e **não** uma lista larga:

```js
assert.ok(classes.has('z-[41]'));
for (const proibido of ['z-[90]', 'z-90', 'z-[70]', 'z-[100]', 'z-[120]', 'z-[50]']) {
    assert.equal(classes.has(proibido), false);
}
```

⚠️ **Por que não uma lista larga:** a 187-09 mediu que lista larga **REPROVA CÓDIGO CERTO** — `45` é o
número do **POPOVER** no protótipo e o toast legitimamente ficou em `z-[45]`.
⚠️ **E a isca que distingue INVERSÃO de TROCA DE NÚMERO:** a mutação **M2** trocou `z-[41]` por
`z-[90]` — o número que o protótipo deu a esta peça — e **morreu** em LB-189-17 (e em LB-189-16, pela
marca na contraprova). Uma troca para um número que **preservasse a ordem** (ex. `z-[41]` → `z-[41]`)
não é mutação; uma que **invertesse** a ordem (`z-[90]`, acima do modal `42`) é exatamente o que M2
exerceu. ➡️ O gate mede a **ORDEM**, não a tabela.
⚠️ `z-[41]` **já existe** em `resources/js/Components/PlanejadorFull/Gaveta.jsx` — outro módulo, outra
tela. **Não é colisão**, e está escrito no docblock que **isca de "z-[41] inexistente no projeto"
seria FALSA**. Não a escrevi.

---

## §6 — ⚠️⚠️ A PROFUNDIDADE MEDIDA (não a aparência)

Medida **por script** no protótipo, contando aberturas/fechamentos de `:726` a `:746`:

```
 726 prof  0 ->  1 | <sc-if value="{{ LB.open }}">
 727 prof  1 ->  2 | <div …z-index:90…grid-template-columns:minmax(0,1fr) 320px>      ← RAIZ
 728 prof  2 ->  3 | <div …position:relative…padding:24px 70px>                        ← PALCO
 729 prof  3 ->  3 | <div …font-size:13px…><span …>{{posLabel}} · {{label}}</span>…>   ← filho do palco
 730 prof  3 ->  4 | <div onMouseMove …width:min(78vh, 100%)…cursor:{{LB.cursor}}>     ← CAIXA, filha do palco
 731 prof  4 ->  5 | <div …transform:{{LB.tf}}…transition:transform .15s>              ← CAMADA, filha da caixa
 732 prof  5 ->  5 | <image-slot … fit="contain">                                      ← filho da CAMADA
 733 prof  5 ->  5 | <sc-for …><span …width:26px…>{{pin.n}}</span></sc-for>            ← MARCADORES, filhos da CAMADA
 734 prof  5 ->  4 | </div>                                                            ← fecha a camada
 735 prof  4 ->  3 | </div>                                                            ← fecha a caixa
 736 prof  3 ->  4 | <div …display:flex;gap:6px>                                       ← FAIXA, IRMÃ da caixa
 741 prof  3 ->  3 | <button …left:16px;top:50%…font-size:18px>←</button>              ← SETA, filha do PALCO
 742 prof  3 ->  3 | <button …right:16px…>→</button>                                   ← SETA, filha do PALCO
 743 prof  3 ->  2 | </div>                                                            ← fecha o palco
 744 prof  2 ->  3 | <aside …background:#0D0D0D…>                                      ← LATERAL, IRMÃ do palco
```

### As três consequências, e por que cada uma passaria CALADA sem a medida

| fato | o que a aparência não pega |
|---|---|
| os **marcadores** são filhos da **CAMADA DO ZOOM** | é o que os faz **ESCALAREM** com o `transform`. Irmãos da camada ficariam **PARADOS** enquanto a imagem amplia — **apontando para o defeito ERRADO**, com todo teste de render passando |
| as **setas** são filhas do **PALCO** (`position:relative`) | na RAIZ — que é `position:fixed`, logo ancestral posicionado — o `right-[16px]` se mediria sobre a **viewport inteira** e a seta → sairia **debaixo da lateral de 320px** |
| a **faixa de miniaturas** é **IRMÃ** da caixa, e a **lateral** é **IRMÃ** do palco | é a mesma exposição do rodapé do painel (`:710` fecha, `:712` reabre): dentro do `children` ele **rolaria para fora da tela** |

### E o grid `minmax(0,1fr) 320px`: a profundidade, não a aparência

O grid vive na **RAIZ** (`:727`), e as suas DUAS colunas são o **palco** (`:728`) e a **lateral**
(`:744`) — **IRMÃS**, no mesmo nível. ➡️ O `RaizDoLightbox` recebe o palco por `children` e a lateral
por **slot irmão**, e o caso `LB-189-18` prova por **ÁRVORE COM ÍNCICE ABSOLUTO** (nunca
`indexOf(tagDeAbertura)`, que **pega o irmão errado** — quatro gates deste projeto caíram nisso):

```js
assert.equal(camada.profundidade, caixa.profundidade + 1);       // a camada é FILHA da caixa
for (const m of marcadores) assert.ok(m.profundidade > camada.profundidade);
for (const s of setas)      assert.equal(s.profundidade, palco.profundidade + 1);
assert.equal(faixa.profundidade, caixa.profundidade);            // IRMÃS
assert.equal(caixa.profundidade, palco.profundidade + 1);
```

---

## §7 — PI(189-03) — a saída COLADA, e o LIMITE do recorte DECLARADO

Receita da `189-REGUA.md` §5, por script (`pi-189-03.sh` no scratchpad).
⚠️ `git diff --numstat -- <alvos>` "sai vazio" é **PROIBIDO** como gate: depois do commit ele compara
a ÁRVORE com o índice e sai vazio **SEMPRE**.

```
=== 1. test -e em cada alvo ===
ALVOS=33
FANTASMAS=0

=== 2. commits do escopo (189-03), pelo ASSUNTO ===
d8b30fdf|feat(189-03): o lightbox POR PORTAL em z-[41] — a profundidade, nao a aparencia
9e54a884|test(189-03): o portal, a profundidade e os ordinais do marcador — RED medido
59446cdf|feat(189-03): as funcoes puras do lightbox — a volta, o clamp e a origem do zoom
COMMITS=3

=== 3. passada 1 (commit por commit) ===
  resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx
  tests/gates/contagens-189-03.txt
  tests/js/publicador-fila-fechamento-187.test.js
  tests/js/publicador-lightbox-casca-189.test.js
  tests/js/publicador-vocabulario-novo.test.js
CAMINHOS_P1=5

=== 4. passada 2 (independente) ===
CAMINHOS_P2=5
AS_DUAS_PASSADAS=IDENTICAS

=== 5. intersecao alvos x caminhos tocados ===
INTERSECAO=[]

=== 6. ISCA (a): a sonda VE ===
COMMITS_DA_ISCA_188_07=6
ISCA_A=ACUSOU (188-07 tocou PainelDeRevisao.jsx, como tem de ser)

=== 7. ISCA (b): o recorte nao vaza ===
ISCA_B=NAO VAZOU (nenhum assunto de 186/187/188/190/261019/261020 no escopo)

=== 8. o LIMITE do recorte (sobre-captura e sub-captura), medido ===
SOBRE_CAPTURA_EVITADA (commits que o --grep pegaria pelo CORPO e o assunto NAO): 0
AUTOR (nao discrimina: as tres maquinas usam o mesmo user.name):
  MB.ECF-100376

PI(189-03): PASSOU sobre 33 alvos, 3 commits do escopo, intersecao VAZIA.
```

### ⚠️⚠️ O LIMITE desta ferramenta, declarado por escrito

1. **SOBRE-CAPTURA pelo CORPO — evitada por construção.** O `git log --grep` lê a **mensagem
   INTEIRA**: nesta milestone `(186-01)` casou **5** commits só pelo corpo, **dois com assunto
   `docs(188-13)`**. ➡️ O meu recorte é sobre **`%s`** (o assunto). Medido agora: o `--grep` por
   `(189-03)` pegaria **0** commits a mais que o assunto — mas isso é o estado de HOJE, e a diferença
   crescerá quando as waves 189-04..189-08 citarem este plano no corpo delas.
2. **SUB-CAPTURA por escopo alheio — NÃO coberta.** Commit que tocou os meus arquivos **sem**
   `(189-03)` no assunto **não aparece**. Os dois commits de outra sessão (`31b73ed9`, `927117b0`)
   são exatamente dessa classe, e foi por isso que os medi à mão na §3.
3. **O AUTOR NÃO DISCRIMINA — medido.** `git log --format='%an' -20 | sort -u` devolve **UM** nome
   (`MB.ECF-100376`): as três máquinas do projeto usam o **mesmo `user.name`**. ➡️ Conferir autor é
   inútil aqui, e a régua já o proíbe.
4. ➡️ **Melhor que range, não perfeito.** Um range cru (`ddfbf091..HEAD`) arrastaria os commits da
   outra sessão; o escopo do assunto não — mas também não enxerga o que ela fez nos meus vizinhos.

### Os arquivos que eu EDITEI (e por isso **não** estão no PI)

| arquivo | o que entrou | removeu algo? |
|---|---|---|
| `resources/js/Components/.../LightboxDaImagem.jsx` | **CRIADO** (890 linhas) | — |
| `tests/js/publicador-lightbox-casca-189.test.js` | **CRIADO** (1501 linhas, 27 casos) | — |
| `tests/gates/contagens-189-03.txt` | **CRIADO** | — |
| `tests/js/publicador-vocabulario-novo.test.js` | **+33 linhas** (`ARQUIVOS_NOVOS`) | **0 remoções** |
| `tests/js/publicador-fila-fechamento-187.test.js` | **+6 linhas** (`JSX_DEPOIS_DA_187`) | **0 remoções** |

`git diff --diff-filter=D HEAD~1 HEAD` → **nenhuma deleção** nos três commits.

---

## §8 — ⚠️ O QUE **NÃO SE PROVA** SEM NAVEGADOR, DECLARADO

**NÃO HÁ NAVEGADOR nesta máquina.** O `mysqld` local está **parado**, o banco não tem empresa com
`ml_token` (a rota dá **404**), `pub_aprovacao_ofertas` está **vazia** e a fila só ganha oferta na
**Fase 194**. ➡️ Neste documento, *"a tela mostra X"* significa **SEMPRE** *"o HTML gerado contém X"*.
⚠️ **Eu não vi tela renderizada.** As conferências visuais das Fases **187 e 188** seguem **ABERTAS**
e **separadas** — nenhuma é presumida feita.

| afirmação | PROVADA? | como / por que não |
|---|---|---|
| a origem do `transform` a partir das coordenadas do cursor | **SIM** | `origemDoZoom` — função pura, 1 caso exato + 2 extremos + clamp nos 4 lados + 8 caixas inválidas + 7 coordenadas adversas + isca do mesmo lado |
| a decisão *"só move a origem com zoom > 1"* (`:1329`) | **SIM** (função) | `aoMoverOCursor(bom, 1) === null` e `(bom, 2.5) === {x:25,y:25}` |
| **o `onMouseMove` DISPARAR** | **NÃO** | não há navegador. **Travado por IGUALDADE DE FONTE** (`FONTE.includes('onMouseMove')`), e o teste **declara que é proxy de FONTE e NÃO prova comportamento** (a 188-07 e a 189-01 fizeram o mesmo) |
| **a imagem ACOMPANHAR o cursor** | **NÃO** | é o runtime do navegador. Conferência visual da conta **459** (plano 189-08) |
| **a animação de 150 ms** | **NÃO** | o `style` está provado; a ANIMAÇÃO não. O CSS construído é do **189-04** |
| o `cursor: crosshair`/`zoom-out` **aparecer** | **NÃO** | a CLASSE está provada nos dois estados; o cursor do SO não |
| o `z-[41]` **cobrir o painel na tela** | **NÃO** | a ORDEM relativa das classes está provada; o empilhamento pintado não |

⚠️ **Premissa inobservável se TRAVA, não se descarta** — é o que a 188-07 e a 189-01 fizeram, e está
repetido aqui com a ressalva escrita **dentro** do caso de teste.

---

## §9 — FID-01 §B RE-MEDIDA **POR SCRIPT** (o diretório citado)

**Como medida: `SCRIPT`** — `fid01-189-03.cjs`, escrito **por ferramenta** no scratchpad (⚠️ **nunca
heredoc**: o heredoc do shell **come a barra invertida** e a regex sai quebrada — medido na wave
189-01). Protótipo: **`design_handoff_publicador/Aprovacao.dc.html`** (⚠️ **cite o diretório**:
`new_layout_publicador/Aprovacao.dc.html` **NÃO EXISTE**, medido pela wave 1), `sha256`
**`68624883ce35547e`**.

```
ATRIBUTOS_NA_FAIXA_726_743=12          PONTEIROS_CITADOS=12          NAO_CITADOS_NA_FAIXA=[]
VARREDURA_cursor=730 738 741 742       VARREDURA_white_space_nowrap=(nenhuma)
VARREDURA_text_overflow=(nenhuma)      VARREDURA_font_size=11.5px 13px 18px
ISCA_727_presente=true   ISCA_L9999_ausente=true   ISCA_cursor_de_730={{ LB.cursor }}
ISCA_CODIGO_ve=true      ISCA_CODIGO_nao_ve_comentario=true
```

| ponteiro | 1ª propriedade do `style` | **cursor** | **white-space** | **text-overflow** | classes | no código |
|---|---|---|---|---|---|---|
| `:727` | `position:fixed` | — | — | — | 8 | **TODAS (189-03)** |
| `:728` | `position:relative` | — | — | — | 8 | **TODAS (189-03)** |
| `:729#1` | `display:flex` | — | — | — | 5 | **TODAS (189-03)** |
| `:729#2` | `color:#F4F4F5` | — | — | — | 2 | **TODAS (189-03)** |
| `:730` | `position:relative` | **`{{ LB.cursor }}`** | — | — | 9 | **TODAS (189-03)** |
| `:731` | `position:absolute` | — | — | — | 2 | **TODAS (189-03)** |
| `:733` | `position:absolute` | — | — | — | 14 | **TODAS (189-03)** |
| `:736` | `display:flex` | — | — | — | 2 | **TODAS (189-03)** |
| `:738#1` | `position:relative` | — | — | — | 8 | **TODAS (189-03)** |
| `:738#2` | `position:absolute` | **`pointer`** | — | — | 5 | **TODAS** (`border-none`, `bg-transparent` **IMPORTADAS do 188-08**) |
| `:741` | `position:absolute` | **`pointer`** | — | — | 13 | **TODAS (189-03)** |
| `:742` | `position:absolute` | **`pointer`** | — | — | 13 | **TODAS (189-03)** |

**Cruzamento com a `189-FID01.md` §A** (que mediu a faixa INTEIRA `726`-`772`): `cursor:` nas linhas
`730 738 741 742` **745 756 762 763 766 767` — as 4 primeiras são as minhas, as 6 restantes são da
LATERAL (189-05); `white-space:nowrap` e `text-overflow` **nenhum na minha faixa** (os 6 `nowrap` são
todos da lateral); `font-size` na minha faixa é `11.5 13 18`, e os outros três (`9.5 12 12.5`) são da
lateral. ➡️ **Consistente, por subconjunto.**

### ⚠️ ACHADO DE MEDIÇÃO — a 1ª redação do meu script ACUSAVA TRABALHO CERTO

A 1ª passada deu `CLASSES_AUSENTES_DO_CODIGO=[:738#2: border-none, bg-transparent]`. ➡️ **O código
está certo**: essas duas classes vêm de `GradeDeMiniaturas.CLASSES_BOTAO`, **IMPORTADO** — é a
**régua §2** (*"peça que a 188 já desenha é IMPORTADA"*). Era o **SCRIPT** que estava procurando só
no arquivo da 189. Corrigido para varrer o recorte dos **três** arquivos (o meu + os dois do 188-08),
**declarando na coluna de onde cada classe vem**. ⚠️ É a família *"gate mais largo/estreito que o
invariante"* — **quatorze ocorrências nesta milestone, e uma BARROU UM DEPLOY**.

### As TRÊS divergências desta wave, com `arquivo:linha`

| # | onde | a tabela/plano diz | o disco/o gate diz | tipo | razão |
|---|---|---|---|---|---|
| D1 | `LightboxDaImagem.jsx:429` (`CLASSES_MARCADOR`) | `189-FID01.md` §B7: **`font-extrabold`** | **`font-[800]`** | **FORMA, valor IDÊNTICO** | o GATE-03 **PROÍBE** a escala nomeada (`publicador-vocabulario-novo.test.js:1001`, `/font-(thin\|extralight\|light\|extrabold\|black)\b/`). O protótipo `:733` pede `font-weight:800` e as DUAS formas o entregam. Precedente em produção: `ImagemGrandeDoPainel.jsx:170` (188-08) e `AnelDeProgresso.jsx` |
| D2 | `LightboxDaImagem.jsx:708` (`SetasDoLightbox`) | o protótipo desenha as setas **sempre** (`:741`/`:742` sem condição) | **não nascem** com `total <= 1`, nem sem handler | **CONDIÇÃO** | **D4 — *"nada nasce decorativo"***, e `onClick` vazio é a mesma dívida. ⚠️ **Não é conflito com o protótipo:** ele é estático (`hint-placeholder-count="6"`) e **NUNCA EXPRESSA** o caso de uma foto só — é o **silêncio** dele. Com 1 foto o `% n` devolveria a própria foto e a seta **não faria nada visível**. Precedente em produção: `GradeDeMiniaturas` (188-08) não desenha a casca da grade vazia. ⚠️ **Reversível num ternário** se o usuário preferir a seta inerte |
| D3 | `LightboxDaImagem.jsx:838` (assinatura do default) | o `189-03-PLAN.md` lista **`onAlternarZoom`** entre as props | **não é prop** deste componente | **OMISSÃO deliberada** | o botão "Ampliar 2,5×"/"Reduzir" (D-189-3a) mora na **LATERAL**, que chega aqui como **NÓ pronto** — quem a constrói (189-06) passa o handler direto ao `LateralDoLightbox` (189-05). Declará-la aqui seria **entrada morta**, e *"entrada morta é linha morta"*. ➡️ Os **rótulos** saem daqui (`ROTULO_AMPLIAR`/`ROTULO_REDUZIR`, named export) para **não haver segunda cópia** |

⚠️ **Nenhuma das três é um QUINTO conflito protótipo × regra do projeto.** D1 é forma com valor
idêntico; D2 é silêncio do protótipo preenchido por regra do projeto com precedente em produção; D3 é
arquitetura de slot. **O placar de derrotas do protótipo segue em 4** (todas por decisão do usuário).
➡️ **Nada foi devolvido ao usuário por conflito.**

---

## §10 — A REGRA ÚNICA DE REUSO: o que foi IMPORTADO, e o que NÃO foi

**IMPORTADO** (régua §2 + inventário `189-CONTRATO-188.md`):

| de | o que | inventário | por quê |
|---|---|---|---|
| `GradeDeMiniaturas.jsx` | `chaveDaBorda`, `classeDaBorda`, `CLASSES_ANEL` | **INV-09** | a precedência de **cinco** ramos espelha `FotosDoPainelService::borda()`. Duas cópias divergiriam, e aqui a divergência é *"a pessoa reprova a foto errada"* |
| `GradeDeMiniaturas.jsx` | `CLASSES_BOTAO`, `CLASSES_FOTO`, `TITULO_PADRAO` | — | `:738#2` e o `title` de três degraus. ⚠️ `CLASSES_BOTAO` traz `p-0` **a mais** que a §B10 — acréscimo do 188-08 (um `<button>` tem padding default). **Importar > redesenhar** |
| `ImagemGrandeDoPainel.jsx` | `CLASSES_PLACEHOLDER` | **INV-11** | o estado declarado de `url_arquivo` nula. **O MESMO** do painel, nunca `<img src="">` |
| `ImagemGrandeDoPainel.jsx` | `CLASSES_IMAGEM` | — | `:732` `fit="contain"` |
| `ImagemGrandeDoPainel.jsx` | `marcadoresDesenhaveis`, `classeDaCor`, `FUNDO_DA_COR` | — | o saneamento das coordenadas **preservando o ordinal**, e o mapa de cor por severidade |
| `ImagemGrandeDoPainel.jsx` | `textoDoPainel`, `inteiroDoPainel`, `objetoDoPainel`, `listaDoPainel`, `noQuadro`, `PONTO_MINIMO`, `PONTO_MAXIMO` | — | as guardas de tipo. Uma segunda cópia delas é uma segunda chance de errar |

**NÃO importado, com a razão MEDIDA:**

| o que | razão |
|---|---|
| `CLASSES_MARCADOR` do painel | `:733` é **26px / 11,5px / SEM halo**; `:596` é **24px / 11px / COM `box-shadow`**. A §B7 manda explicitamente **não reusar**, e há asserção `notDeepEqual` provando que não reusei, mais uma que recusa qualquer `shadow-*` no meu marcador |
| `CLASSES_MINIATURA` do painel | aqui **56×56 `rounded-pub-control-sm`** em `flex`; no painel **`aspect-square rounded-[8px]`** em grade de 6 colunas. **A REGRA se importa, a classe de caixa não** (INV-09) |

### ⚠️⚠️ E a trava do AVISO-189-1: a borda NUNCA lê o `borda`/`anel` CRUS

`FotosDoPainelService::para($oferta)` é chamado **SEM índice** (`PainelDeRevisaoService:301`), então
`selecionado = 0` e a `borda` sai `selecionada` **sempre na imagem 0**. ➡️ A seleção é a **DO
LIGHTBOX**, e o caso `LB-189-23` prova:

- a precedência **COM CONFLITO**: a foto 0 é `pending` **E** `com_alerta` **E** selecionada → sai
  **AMARELA** (`border-pub-accent`). *Um estado por vez passaria com a ordem invertida.*
- o **5º ramo**: `regenerating` e `rejected` → `border-pub-line-off`;
- o **anel ANDA**: com `indice: 2`, a miniatura 0 **perde** o `ring-2` e a 2 **ganha** — e a fixture
  tem `anel: true` **só na imagem 0**, então ler o cru **reprovaria aqui**.

---

## §11 — ⚠️⚠️ O ORDINAL: o número sobre a foto é O MESMO da lista

`PubAprovacaoImagem::alertas()` **não tem `orderBy`**, e o ordinal **é** a posição na lista — MariaDB
e SQLite devolviam ordens DIFERENTES, e **nenhuma sonda pegava, porque texto e marcador concordavam
entre si apontando para o ponto ERRADO**. ➡️ **O ordinal é LIDO do servidor, NUNCA recontado.**

**O caso: `LB-189-22 — os NÚMEROS dos marcadores são OS MESMOS `ordinal` da lista de verificações 189_03`**

```js
const noHtml = numerosDosMarcadores(html);                       // lidos do HTML renderizado
const daLista = VERIFICACOES_DA_FOTO_1.map(v => v.ordinal).filter(Number.isInteger);
assert.deepEqual(daLista, [4, 2]);   // ⚠️ a FIXTURE DISCRIMINA
assert.deepEqual(noHtml, daLista);
assert.deepEqual(noHtml, MARCADORES_DA_FOTO_1.map(m => m.ordinal));
```

⚠️⚠️ **A fixture usa os ordinais `4` e `2`, de propósito e fora da ordem.** *"Contar na tela"* daria
`1, 2` e esta asserção **pegaria**; com ordinais `1, 2` ela **não distinguiria LER de RECONTAR**.
A mutação **M5** (`{marcador.ordinal}` → `{marcador.ordinal + 1}`) **matou exatamente LB-189-22**.
⚠️ E há isca provando que a sonda de leitura **VÊ** um número diferente quando ele é diferente
(`ordinal: 7` → `[7]`): sem ela, o `deepEqual` passando seria indistinguível de leitura cega.

---

## §12 — GATE-09 na FONTE, com `duration-200` como CONTROLE

```js
const PROIBIDAS = [
    ['duration arbitrária', /duration-\[/],  ['delay arbitrário', /delay-\[/],
    ['ease arbitrário', /ease-\[/],           ['a classe `transition-transform`', /transition-transform\b/],
];
assert.deepEqual(PROIBIDAS.filter(([, re]) => re.test(FONTE_LIGHTBOX)).map(([n]) => n), []);

// ⚠️⚠️ O CONTROLE: a escala NOMEADA compila, e a sonda NÃO a proíbe
assert.doesNotMatch('duration-200', /duration-\[/);
assert.doesNotMatch('ease-in-out', /ease-\[/);

// ⚠️⚠️ ISCA: as QUATRO regexes VEEM a forma errada
const vistas = PROIBIDAS.filter(([, re]) => re.test(SINTETICO)).map(([n]) => n);
assert.equal(vistas.length, 4);
```

⚠️⚠️ **A LEITURA É SEM COMENTÁRIO, e isso não é zelo:** o docblock do `.jsx` **CITA as três formas
proibidas** para explicar por que não as usa. Ler o cru **reprovaria o próprio conserto** — é a
família medida em `feedback_gate_que_reprova_pelo_proprio_conserto`.
⚠️ **E a ausência sozinha seria satisfeita por um arquivo SEM transição:** há asserção exigindo que
`transitionProperty`, `transitionDuration`, `transitionTimingFunction` e `'150ms'` **estejam** na
fonte. A mutação **M4** (`'150ms'` → `'200ms'`) matou **LB-189-7, LB-189-9 e LB-189-19**.
⚠️ **A curva é `ease`, não o `cubic-bezier(0.4,0,0.2,1)`** da classe `transition-transform`: o
protótipo `:731` é `transition:transform .15s`, e `ease` é o padrão do CSS. Trocar a curva é FID-02
(*"o Tailwind não tem esse passo"*).
⚠️ **E as coordenadas vão por `style`, NUNCA por classe:** gate proibindo `left-[${`/`top-[${`, com
isca sintética. A mutação **M7** (trocar o `style` por classe montada) matou **LB-189-10 e LB-189-21**.

---

## §13 — AS LISTAS CONSTANTES: as que TOQUEI, e as que NÃO, com a razão

### ⚠️⚠️ As DUAS que toquei — e a 1ª redação do meu manifesto estava ERRADA

| lista | arquivo | o que entrou |
|---|---|---|
| `ARQUIVOS_NOVOS` | `tests/js/publicador-vocabulario-novo.test.js` | **uma** entrada `${DIR_APROV}/LightboxDaImagem.jsx` + bloco de comentário no padrão (por que `.jsx`, os tamanhos usados e de onde vêm) |
| `JSX_DEPOIS_DA_187` | `tests/js/publicador-fila-fechamento-187.test.js` | **uma** linha com a fase e o plano dono |

⚠️⚠️ **ACHADO: a 1ª redação do `contagens-189-03.txt` afirmava que `JSX_DEPOIS_DA_187` era só dos
`.jsx` da Fase 187 e que pôr o meu lá REPROVARIA.** **MEDIDO e FALSO:** aquela lista **INCLUI os 19
`.jsx` da Fase 188** (`:122`-`:157`) e o `deepEqual` dela varre
`readdirSync(aprovacao/)` contra as **TRÊS** listas. O docblock do arquivo (`:113`-`:115`) prescreve
exatamente o que eu fiz: *"QUEM CRIAR `.jsx` EM `aprovacao/` ACRESCENTA UMA LINHA ABAIXO … **e** também
a `ARQUIVOS_NOVOS`. Faltando um dos dois, o gate REPROVA"*. ➡️ **Corrigi o manifesto pela medição**,
e o gate ficou **6/6/0**. ⚠️ **São DUAS escriturações e isso é DELIBERADO — um conserto da 188 RECUSOU
criar a terceira, e este plano NÃO a criou.**

### As que **NÃO** mexi, com a razão medida (declarar por escrito é parte da régua)

| lista | arquivo | razão |
|---|---|---|
| `SLOTS_DE_NO_DEPOIS_DA_187` (3 pontas, com anti-deriva) | `publicador-fila-*` | este plano **não toca** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`. O slot `lateral` é prop **LOCAL** do componente, não slot da PÁGINA — quem liga o lightbox à página é o **189-06** |
| `ESTADO_LOCAL_DA_PAGINA` | `publicador-fila-filtros-187.test.js` | nenhum `useState` novo na página. **O lightbox é CONTROLADO**: recebe `aberto`/`indice`/`zoom`/`origem` e devolve por callback; o estado mora no painel e é o **189-06** quem o cria |
| `QUEM_USA_SHADOW_PUB_PAINEL` | `publicador-fila-tokens-187.test.js` | nenhum arquivo deste plano escreve `shadow-pub-painel`. O protótipo `:727` **só** declara `background` — o lightbox não tem sombra. E há asserção recusando qualquer `shadow-*` no meu marcador |
| `LIBERADOS_DEPOIS_DA_187` | `publicador-fila-tokens-187.test.js` | o `18` **já foi liberado** pela wave **189-01** (`:603`-`:615`), em linha própria com a medida. Este plano é o **primeiro a USAR** o valor (as setas de `:741`/`:742`) e **não acrescenta nada**. ⚠️ `19`, `21.5`, `23`, `25` e `26` seguem BARRADOS, e liberar "por precaução" é o que a régua proíbe. Gate verde: **17/17/0** |
| `VARRIDOS_DECLARADOS` | `publicador-rename-em-analise-ml.test.js` | MEDIDO: declara TRÊS diretórios sob `Components/Mlb/Publicador/` (`publicacoes`, `identidade`, `visaogeral`) e **não** inclui `tests/js` nem `aprovacao/`. O `.test.js` novo não entra nela |
| `FONTES_DO_PLANO` dos 9 gates de ausência de "Ampliar" | vários | ⚠️ **oito das nove** são recortadas à lista do próprio plano dono (A2 do inventário). O meu `.jsx` **não escreve "Ampliar"** em código: só `ROTULO_AMPLIAR = 'Ampliar 2,5×'`, que é o rótulo do botão de ZOOM (D-189-3a, **entregue** nesta fase) e **não** o "⤢ Ampliar" do painel. Nenhuma das nove lista o meu arquivo — medido: os 6 gates vizinhos ficaram verdes |

### Os ids REMEDIDOS, com número **MEDIDO** (nenhum "subido")

| id | antes | depois | por quê |
|---|---|---|---|
| `lightbox-casca` | — (nasceu `15` na tarefa 1) | **`27`** | **MEDIDO**: a tarefa 2 acrescenta 12 casos ao MESMO arquivo. RED `27/15/12` → GREEN `27/27/0`. **Não é subir número para passar**: o RED foi commitado em separado como prova de que os 12 não são vazios |
| `vocabulario-tamanhos` | `>=681` (189-01/189-02, INV-12) | **`>=688`** | **MEDIDO antes e depois**: `# tests 681` → `# tests 688` = `681 + 7`, o passo estrutural de **7 sub-testes por arquivo varrido**. O piso sobe porque a varredura cobre **um arquivo a mais**, não por conveniência |

⚠️ **Nenhuma contagem de manifesto ALHEIO foi tocada.** Os gates vizinhos rodaram com o `tests=` que
eles já declaram (§15).

---

## §14 — A BIJEÇÃO, o HOMÔNIMO e o `--autoteste`

### Bijeção id ↔ `<verify>`, nos DOIS sentidos, por script (`bijecao-189-03.cjs`)

```
IDS_MANIFESTO=[lightbox-casca, vocabulario-tamanhos]
IDS_VERIFY=[lightbox-casca, vocabulario-tamanhos]
PRIMEIRO_ARG_NU_NO_PLANO=0
ORFAOS=[]
VERIFY_SEM_ID=[]
MAX_METODOS_POR_ID=0
ISCA_EXPANSOR_regex_ingenua=0          ← ⚠️ /test_[A-Za-z0-9_]+/ casa ZERO em `test_(a|b)`
ISCA_EXPANSOR_expandido=3              ← o expansor ABRE a alternância
IDS_PHP=[]
CASOS_NO_GATE=27
CASOS_SEM_SUFIXO=[]
ISCA_SUFIXO_acusou=1
ARQUIVOS_DE_TESTE_JS=175
HOMONIMOS=[]
ISCA_HOMONIMO_o_nome_sabido_aparece_em=1
---
BIJECAO(189-03): PASSOU. Zero orfao, zero verify sem id, zero caso sem sufixo, zero homonimo.
```

**O mapa id → `<verify>`**, conferido linha a linha: tarefa 1 → `lightbox-casca` ·
`vocabulario-tamanhos` ; tarefa 2 → `lightbox-casca`. **Zero órfão, zero `<verify>` sem id.**

⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA está no script com isca nos DOIS lados**, mesmo este plano não tendo
id `php`: a regex ingênua devolve **0** num filtro `test_(a|b)`, e foi esse furo que produziu
`SEM_ID=14` **acusando trabalho correto** na wave 189-01.

### HOMÔNIMO — **este plano não tem id `php`**, e isso é declarado

`IDS_PHP=[]`. ➡️ O `--filter` do PHPUnit **não roda** sobre este plano, então **não há homônimo de
`--filter` a conferir** (é o `--filter` que SOMA contagens). A régua do sufixo foi cumprida de todo
modo: os **27** casos terminam em **`189_03`**, e a varredura dos **175** arquivos `.test.js` do
projeto devolveu **`HOMONIMOS=[]`** — com **isca** provando que a sonda VÊ (o nome sabido aparece em
**1** arquivo, o meu).

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

⚠️ **O 1º argumento é `189-03`**, nunca `03` (que resolveria `contagens-186-03.txt`, a fase ERRADA, em
silêncio). O script da bijeção cobra isso: `PRIMEIRO_ARG_NU_NO_PLANO=0`.

---

## §15 — OS GATES: os meus e a NÃO-REGRESSÃO dos vizinhos

```
sh tests/gates/verificar-testes.sh 189-03 lightbox-casca        → # tests 27  # pass 27  # fail 0  PASSOU
sh tests/gates/verificar-testes.sh 189-03 vocabulario-tamanhos  → # tests 688 (>=688)     # fail 0  PASSOU
```

| gate vizinho | resultado | por que importa |
|---|---|---|
| `publicador-fila-fechamento-187.test.js` | **6 / 6 / 0** | o `deepEqual` de `JSX_DEPOIS_DA_187` × `readdirSync(aprovacao/)` × `ARQUIVOS_NOVOS` — **o GATE-03 verde** |
| `publicador-vocabulario-novo.test.js` | **688 / 688 / 0** | GATE-03 + FID-03/FID-04 sobre o arquivo novo |
| `publicador-painel-fotos-188.test.js` | **32 / 32 / 0** | os 2 gates de ausência de "⤢ Ampliar" (`:639`/`:640`) e o de "Gerar novamente" (`:641`) — **intactos** |
| `publicador-painel-casca-188.test.js` | **45 / 45 / 0** | ⚠️ o `:1035` varre a FONTE de `PainelDeRevisao.jsx` por `/Ampliar/` — **eu não toquei aquele arquivo** |
| `publicador-fila-zindex-187.test.js` | **6 / 6 / 0** | a ordem de camadas (⚠️ **mudou por fora da fase**, §3.2) |
| `publicador-header-zindex-ordem.test.js` | **7 / 7 / 0** | ⚠️ **INTOCÁVEL** (nasceu de bug relatado em 12/10). A outra sessão o editou; **eu não** |
| `publicador-fila-tokens-187.test.js` | **17 / 17 / 0** | `LIBERADOS_DEPOIS_DA_187` (o `18` da 189-01, intacto) e TOK-15 |

⚠️ **Nenhum manifesto alheio foi remedido.** O `casca-188:1035` **não** reprovou, e a razão é que o
meu componente **não exige nenhuma prop em `PainelDeRevisao.jsx`** — quem acrescenta o fio é o
**189-06**, e é lá que a armadilha do A2 se resolve.

---

## §16 — A CAMPANHA DE MUTAÇÃO: 7 mutações, **0 sobreviventes**

Script **`mutar-189-03.sh`** (⚠️ **o nome carrega o plano**: o scratchpad é COMPARTILHADO entre
subagentes paralelos, e na Fase 187 o script de uma wave foi **SOBRESCRITO** pela outra e a campanha
foi **REFEITA INTEIRA**). Protocolo: **commitado ANTES** (`d8b30fdf`) · `cp -p` da cópia · ocorrência
**travada em 1 ANTES** por **string exata** e `indexOf` (⚠️ **`grep -c` conta LINHAS**) · linha
conferida **depois** · aplicação por **divergência de `sha256`** · restauração **DA CÓPIA** em
`finally` (⚠️ **`git checkout` NUNCA**) · `sha256` final contra `git show HEAD:` com `\r` removido.

```
SHA_PRISTINO = SHA_DO_HEAD = SHA_COPIA = 530a4d50e08f5382
PRISTINO: tests=27 pass=27 fail=0
```

| # | mutação | ocorr. | `# tests` do mutado | mortos | esperados casaram |
|---|---|---|---|---|---|
| **M1** | a VOLTA vira CLAMP (`((i+d)%n+n)%n` → `Math.min(Math.max(i+d,0),n-1)`) | 1 | **27** (= pristino) | LB-189-4, LB-189-24 | **sim** |
| **M2** | `z-[41]` → **`z-[90]`** (o número do protótipo PARA ESTA PEÇA) | 1 | **27** | LB-189-16, LB-189-17 | **sim** (LB-189-17) |
| **M3** | `font-pub` sai da RAIZ do portal (REND-05) | 1 | **27** | LB-189-17 | **sim** |
| **M4** | `'150ms'` → `'200ms'` (GATE-09 no valor) | 1 | **27** | LB-189-7, LB-189-9, LB-189-19 | **sim** |
| **M5** | o ordinal é RECONTADO (`{ordinal}` → `{ordinal + 1}`) | 1 | **27** | LB-189-22 | **sim** |
| **M6** | a seta volta a nascer com UMA foto (`<= 1` → `<= 0`) | 1 | **27** | LB-189-24 | **sim** |
| **M7** | a coordenada sai por CLASSE em vez de `style` | 1 | **27** | LB-189-10, LB-189-21 | **sim** |

```
RESTAURADO_DA_COPIA: sha=530a4d50e08f5382
CONTRA_git_show_HEAD: IDENTICO
CAMPANHA(189-03): 7 mutações · 0 sobrevivente(s) · 0 que não carregou/carregaram
```

⚠️⚠️ **O `# tests` do MUTADO foi conferido IGUAL ao do pristino (27) nas SETE** — se tivesse caído, o
arquivo **não teria carregado** (`SyntaxError`) e a morte **não seria da asserção**. Era a conferência
que faltava em campanhas anteriores desta milestone.
⚠️ **Nenhuma sobrevivente, e nenhuma morreu pela asserção ERRADA** — o script compara os mortos com a
lista `deveMatar` de cada mutação e **reprovaria** se a interseção fosse vazia (foi exatamente o que
aconteceu com a 189-01: a mutação dela matava o gate pela asserção errada e **não provava nada**).
⚠️ **M2 matou DOIS casos**, e isso é correto: a contraprova reforçada do LB-189-16 passou a cobrar
`raiz.includes('z-[41]')`, então trocar o número também a mata. **Não é falso positivo.**

### ⚠️ `npm run build` **NÃO** foi rodado, e é de propósito

O `189-03-PLAN.md` `<verification>` item 4 é explícito: *"Este plano **não** roda `npm run build` nem a
campanha de mutação: são do 189-04"*, e a `189-COBERTURA.md` confirma (*"189-04 … CSS das classes
novas, build e a mutação"*). ⚠️ **Rodei a mutação mesmo assim** porque o gate deste plano **não tem
camada de CSS** (ele mede FONTE + render), então a armadilha do `cp` atualizando o `mtime` e a camada
de CSS **pulando** não se aplica — e um gate sem prova de que não é vazio é pior que nenhum gate.
➡️ **O build e o GATE-09 no CSS CONSTRUÍDO seguem sendo do 189-04**, com o meu arquivo já commitado.
⚠️ E o briefing avisa: *"com `.jsx` alheio meio-salvo, NÃO builde"* — razão a mais para não buildar
numa árvore compartilhada com outra sessão ativa.

---

## §17 — AS FUNÇÕES PURAS: cada caso do `<behavior>`, medido

| função | caso do plano | medido |
|---|---|---|
| `ZOOM_AMPLIADO` / `ZOOM_NORMAL` | `2.5` / `1` | ✅ |
| `origemDoZoom` | `{clientX:150,clientY:50,caixa:{left:100,top:0,width:200,height:200}}` → `{x:25,y:25}` | ✅ |
| `origemDoZoom` | fora da caixa **CLAMPA** em 0 e 100 (quatro lados) | ✅ |
| `origemDoZoom` | `width`/`height` `0`, negativos, `NaN`, string, objeto, `caixa` nula → `null`, **nunca lança** | ✅ (8 caixas + 7 coordenadas + 8 movimentos inteiros) |
| `moverNoLightbox` | `(0,-1,5)` → **4** · `(4,1,5)` → **0** (DÁ A VOLTA) · `total 0` → `null` | ✅ |
| `indiceNoLightbox` | `(7,3)` → **2** · `(0,0)` → **`null`** (fecha) · adverso com `total>0` → **0** | ✅ |
| `rotuloDoZoom` | `(1)` → `'Ampliar 2,5×'` · `(2.5)` → `'Reduzir'` | ✅ |
| `classeDoCursorDoZoom` | `(1)` → `'cursor-crosshair'` · `(2.5)` → `'cursor-zoom-out'`, **literais de um mapa** | ✅ |
| `estiloDoZoom` | `{zoom:2.5,x:30,y:70}` → os **5** campos exatos · x/y adversos → `'50% 50%'` | ✅ |
| `alternarZoom` | `(1)` → `2.5` · `(2.5)` → `1` | ✅ |
| `contagemDoLightbox` | `(1,6)` → `'2 de 6'` | ✅ |

### Os TRÊS acréscimos defensivos, declarados no docblock

1. **`origemDoZoom` CLAMPA em 0..100** e devolve `null` com caixa inválida. O protótipo não clampa
   (o cursor está dentro da caixa por construção). ⚠️ `NaN%` num `transformOrigin` **não lança**, mas
   é o caminho da **TELA PRETA** (`textoSeguro(NaN)` → `'NaN'`).
2. **`moverNoLightbox` usa `((i+d)%n+n)%n`** e não `(i+d+n)%n`: a forma do protótipo quebra para
   `d < -n`. Para `d = ±1` as duas dão o MESMO resultado.
3. **`estiloDoZoom` trava o `scale` nas DUAS constantes** em vez de interpolar o valor cru de `:1326`
   (`'scale(' + S.lbZoom + ')'`): zoom adverso viraria `scale(NaN)`.

---

## §18 — A TELA PRETA: render adverso, no CLIQUE e no MOVIMENTO

A tela preta voltou **SEIS vezes** nesta milestone, e a última foi **NO CLIQUE**.
⚠️ `String(valor)`, `hasOwnProperty` e **qualquer `obj[chave]`** **LANÇAM** com `{toString: null}`;
`textoSeguro(NaN)` devolve `'NaN'`; **prop de NÓ chegando objeto comum MATA A PÁGINA**.

| cobertura | o que foi exercido |
|---|---|
| **render, campo a campo** | **23 campos** × **15 valores podres** (`null`, `undefined`, `NaN`, `Infinity`, `0`, `-1`, `''`, `'x'`, `7`, `true`, `false`, `{}`, `[]`, `{toString:null}`, `{valueOf:null}`) = **345** renders, cada um com `doesNotThrow` + `doesNotMatch(/\[object Object\]\|NaN\|undefined\|Invalid Date/)` |
| **a coleção inteira e o índice** | 15 × 15 combinações |
| **item adverso NO MEIO** da coleção | 15 |
| **zoom e origem** | 15 × 7 |
| **a prop INTEIRA nula** | as **5** peças com `{}` e com `undefined` |
| **o slot `lateral`** | 8 valores adversos (objeto comum, string, número, array, função, `{toString:null}`) → `isValidElement` e nada mais |
| ⚠️⚠️ **NO CLIQUE** | `aoClicarNaSeta` e `aoClicarNaMiniatura` com 8 entradas adversas cada — `renderToStaticMarkup` **não dispara `onClick`** |
| ⚠️⚠️ **NO MOVIMENTO** | `aoMoverOCursor` com **17 eventos podres** (sem `currentTarget`, com ele não-objeto, sem `getBoundingClientRect`, com ele devolvendo `null`/`{}`/caixa de largura 0, `clientX: NaN`…) |
| **`aberto` exatamente `true`** | 10 valores truthy/falsy — `'1'`, `1` e `{}` **abririam por acidente** |

⚠️ **ISCA DO MESMO LADO, obrigatória:** `assert.ok(bom.length > 500)` com a fixture BOA. Sem ela,
**todos** os `doesNotMatch` acima estariam passando sobre **HTML vazio** — gate vazio por construção.
⚠️ **Nenhuma mensagem de asserção usa `String()` nem `JSON.stringify()`** sobre o valor adverso
(`String({toString:null})` **LANÇA dentro do próprio gate**); os marcadores adversos são identificados
pelo **índice na lista**, não pelo valor. E há asserção provando que `String(` **não voltou** ao `.jsx`
(gate da 187-04).

---

## §19 — OS PONTEIROS RECONFIRMADOS POR `sed`

⚠️ A notação `:N` é **EXCLUSIVA do protótipo**; linha de outro arquivo vai como `L<N>`.

| ponteiro | o que a régua/FID-01 diz | medido por `sed` | veredicto |
|---|---|---|---|
| `:727` | fundo `rgba(5,5,5,0.94)` + grid `minmax(0,1fr) 320px` + `z-index:90` | **confirmado byte a byte** | ✅ |
| `:728` | palco `relative`, `gap:14px`, `padding:24px 70px` | confirmado | ✅ |
| `:729` | `{{LB.posLabel}} · {{LB.label}}` + `{{LB.count}}` | confirmado | ✅ |
| `:730` | `width:min(78vh, 100%)`, `aspect-ratio:1/1`, `cursor:{{LB.cursor}}`, `onMouseMove` | confirmado | ✅ |
| `:731` | `transition:transform .15s` | confirmado | ✅ |
| `:732` | `<image-slot … fit="contain">` | confirmado | ✅ |
| `:733` | marcador `width:26px`, `font-size:11.5px`, **sem** `box-shadow` | confirmado | ✅ |
| `:736` | `display:flex;gap:6px` | confirmado | ✅ |
| `:738` | miniatura `56×56`, raio `7px`, borda `1.5px` + botão invisível | confirmado | ✅ |
| `:741`/`:742` | setas `44px`, `margin-top:-22px`, **`font-size:18px`** | confirmado | ✅ |
| `:1014` | `Escape`: `modal → lb → menu → panelId` | confirmado (a cadeia literal) | ✅ |
| `:1016` | com o lightbox aberto, ←/→ vão **para ele** | confirmado | ✅ |
| `:1319` | `onZoom` abre com `Math.min(S.pImg, rv.imgs.length-1)` e `lbZoom: 1` | confirmado | ✅ |
| `:1321` | `(lb.i + d + n) % n` — **DÁ A VOLTA** — e `lbZoom: 1` | confirmado | ✅ |
| `:1324` | `Math.min(lb.i, ord.length-1)`; `i < 0` → fechado | confirmado | ✅ |
| `:1326` | `count`, `zoomLabel`, `tf`, `origin`, `cursor` | confirmado | ✅ |
| `:1327` | clique na miniatura: `i = j`, `lbZoom: 1` | confirmado | ✅ |
| `:1328` | `lbZoom > 1 ? 1 : 2.5` | confirmado | ✅ |
| `:1329` | **só com zoom > 1**; `Math.round((clientX - r.left)/r.width*100)` | confirmado | ✅ |
| `PainelDeRevisao.jsx:154`/`:164` | `z-[39]` / `z-[40]` | confirmado | ✅ |
| `CascaDeModal.jsx:54` | `z-[42]` | confirmado | ✅ |
| `ToastDaFila.jsx:69` | `z-[45]` | confirmado | ✅ |
| `header/PublicadorHeader.jsx:174` | `z-30` + `backdrop-blur-[14px]` | ⚠️ **RE-MEDIDO após `31b73ed9`** — `top-0` virou `-top-6`; o `z-30` e o `backdrop-filter` **não mudaram** | ✅ |
| `GradeDeMiniaturas.jsx:90`/`:101`/`:118` | `chaveDaBorda` / `classeDaBorda` / `CLASSES_ANEL` | confirmado (named exports) | ✅ |
| `ImagemGrandeDoPainel.jsx:159`/`:170` | `CLASSES_PLACEHOLDER` / `CLASSES_MARCADOR` | confirmado | ✅ |
| `publicador-vocabulario-novo.test.js:1001` | o GATE-03 proíbe `font-extrabold` | confirmado — é a razão de **D1** | ✅ |
| `publicador-fila-fechamento-187.test.js:119`-`:158` | `JSX_DEPOIS_DA_187` | ⚠️ **MEDIDO: inclui os 19 `.jsx` da 188** — o oposto do que o meu manifesto afirmou primeiro | ✅ (corrigido) |

**Nenhum ponteiro deslocado nesta wave.** ⚠️ O único "número que mudou" foi o `1432` × `1433` do
protótipo, que é **método de medida** (§1) e não deslocamento.

---

## §20 — Deviations from Plan

### Auto-fixed / declarados (nenhum pediu permissão: são Regras 1-3 ou divergência declarada)

**1. [Rule 3 - Bloqueio] `JSX_DEPOIS_DA_187` tinha de receber o `.jsx`, e o meu manifesto afirmava o contrário**
- **Found during:** tarefa 1, logo depois de criar o `.jsx`
- **Issue:** o `deepEqual` de `publicador-fila-fechamento-187.test.js` varre `readdirSync(aprovacao/)`
  contra as TRÊS listas; sem a entrada ele ficaria **VERMELHO no meio do plano** (e árvore vermelha no
  HEAD pode **BARRAR DEPLOY**). A 1ª redação do `contagens-189-03.txt` afirmava, por herança do
  briefing, que aquela lista era só da Fase 187 e que pôr o meu lá reprovaria.
- **Fix:** medi (`grep -n -A 40 'JSX_DEPOIS_DA_187'`), vi os 19 `.jsx` da 188 lá e o docblock
  `:113`-`:115` prescrevendo as duas escriturações; acrescentei a linha e **reescrevi a nota do
  manifesto pela medição**.
- **Files:** `tests/js/publicador-fila-fechamento-187.test.js`, `tests/gates/contagens-189-03.txt`
- **Commit:** `59446cdf`

**2. [Rule 1 - Gate frouxo] a contraprova do portal media o LITERAL, não a árvore**
- **Found during:** GREEN da tarefa 2
- **Issue:** `render(RaizDoLightbox, {children: <span>palco</span>}).length > 200` deu **139 bytes**,
  dos quais **102 eram a própria string de `CLASSES_RAIZ`**.
- **Fix:** a contraprova passa a montar o **palco DE VERDADE** (`> 1000` + 5 marcas das peças). O
  invariante não mudou; a sonda ficou mais forte, e a mutação M2 confirmou.
- **Files:** `tests/js/publicador-lightbox-casca-189.test.js`
- **Commit:** `d8b30fdf`

**3. [Medição] o script da FID-01 acusava trabalho CERTO em `:738#2`**
- Corrigido no script (não no código): o recorte passou a incluir os dois arquivos do 188-08 de onde
  a peça **IMPORTA**, **declarando na coluna** de onde cada classe vem (§9).

**3b. [Medição] ⚠️ um `node -e` acusou os 21 imports como MORTOS — e a régua já proíbe `node -e`**
- **Found during:** a conferência final de *"entrada morta é linha morta"*.
- **Issue:** um `node -e` com `new RegExp('\\\\b' + n + '\\\\b')` dentro de string **dupla do bash**
  devolveu `IMPORTS_MORTOS` com **todos os 21** nomes. ➡️ **FALSO**: a barra invertida foi comida pelo
  shell e a regex saiu quebrada — é a **mesma família** do heredoc que *"come a barra invertida"*,
  medida na wave 189-01.
- **Fix:** reescrito como **script `.cjs` escrito por ferramenta** no scratchpad
  (`imports-mortos-189-03.cjs`), **com isca nos dois lados** (um nome que SABIDAMENTE está no corpo →
  `true`; um inventado → `false`). Medido: `BLOCO_DE_IMPORTS_BYTES=543 · CORPO_BYTES=12968 ·
  IMPORTS=21 · IMPORTS_MORTOS=[]`. **Nenhuma entrada morta.**
- ⚠️ **Nada no código mudou** — o erro era 100% da ferramenta de medida. É a razão pela qual a régua
  diz *"nunca `node -e`, nunca heredoc com regex"*, e eu tropecei nela mesmo tendo-a lido.

**4. [Divergência declarada] as três de §9 (D1 `font-[800]`, D2 as setas com 1 foto, D3 `onAlternarZoom`)**

**5. [Critério FALSO NA PARTIDA, declarado e NÃO ajustado]**
`grep -c "aprovacao/LightboxDaImagem.jsx" tests/js/publicador-vocabulario-novo.test.js` = **0**, não 1.
**MEDIDO:** o mesmo grep devolve **0** para `aprovacao/ColunaDeFotos.jsx` e
`aprovacao/PainelDeRevisao.jsx`, **que estão lá** — porque o arquivo escreve TODA entrada como
`${DIR_APROV}/…`. ➡️ O invariante de verdade (*"declarado exatamente UMA vez"*) foi provado pela forma
REAL: `grep -c '${DIR_APROV}/LightboxDaImagem.jsx'` = **1**, e o caso `LB-189-13` conta as ocorrências
na fonte **sem comentário** (= 1) com isca do leitor. **O critério não foi "ajustado para passar": foi
declarado FALSO e o invariante foi provado.**

### TDD — o desvio, declarado

A tarefa 1 saiu em **UM** commit (`feat`), **sem um `test(...)` de RED separado**: as funções e o gate
delas foram escritos na mesma passada. ⚠️ A prova de que aquele gate **não é vazio** veio depois, pela
**campanha de mutação** (§16: M1, M4 e M7 matam casos da tarefa 1).
A tarefa 2 seguiu o ciclo: **`test(189-03)` `9e54a884` com RED MEDIDO e DISCRIMINANTE**
(`27 / 15 pass / 12 fail` — falham exatamente os 12 novos) → **`feat(189-03)` `d8b30fdf`** com
`27 / 27 / 0`. ➡️ **Gate sequence no `git log`: `test(189-03)` existe e `feat(189-03)` vem depois.**
Não houve `refactor`.

### Nada foi devolvido ao usuário

Nenhum QUINTO conflito protótipo × regra do projeto apareceu; nenhum gate alheio reprovou; o meu
componente **não exige prop em `PainelDeRevisao.jsx`** (então o `casca-188:1035` não foi provocado); e
nada exigiu tocar `AppLayout.jsx`, `PublicadorHeader.jsx` ou serviço/`.jsx` das Fases 186/187/188.

---

## §21 — Known Stubs

| stub | arquivo | razão / dono |
|---|---|---|
| o slot `lateral` chega **`null`** em todo uso deste plano | `LightboxDaImagem.jsx:838` | **de propósito**: a lateral de 320px é o **189-05** (`LateralDoLightbox.jsx`), e quem a monta é o **189-06**. O slot aceita `isValidElement` e nada mais; o gate prova os dois lados (adverso → nada; elemento legítimo → passa) |
| nenhum consumidor do componente | — | o fio (estado, teclado, foco, o "⤢ Ampliar" do painel) é o **189-06**; as ações são o **189-07**. ⚠️ **Isto não é stub de dado**: não há valor vazio chegando à UI, e nenhuma peça deste plano é desenhada com dado falso |
| a prop `lightbox.originais[...]` da wave 189-02 | — | ⚠️ **NÃO consumida aqui, e isso é MEDIDO**: zero menção no `189-03-PLAN.md`. Quem a consome é a **189-05/189-06**. **Não a liguei "por adiantamento"** |

⚠️ **Nenhum valor hardcoded vazio flui para render**, nenhum texto *"não disponível"*/*"em breve"*/
*"TODO"*/*"FIXME"*, e nenhum botão com `onClick` vazio ou `disabled` decorativo (o gate prova que as
setas **não nascem** sem destino).

---

## §22 — Threat Flags

Nenhuma superfície de segurança nova: este plano é **100% tela**, não cria rota, não lê banco, não
chama serviço, não instala pacote.

```
PI(189-03) sobre package.json e package-lock.json → INTERSECAO=[] (T-189-SC)
```

| threat do plano | disposição | como ficou |
|---|---|---|
| **T-189-20** (campo adverso derrubando a página) | **mitigado** | §18 — render real com 5 classes de valor adverso, **e no clique e no movimento**; guarda de tipo antes de todo `obj[chave]`; zero `String(` |
| **T-189-21** (lightbox preso ao containing block do header) | **mitigado** | portal para `document.body` (§4) + **PI** do `PublicadorHeader.jsx` (§7) + a re-medição do `backdrop-filter` (§3.1) |
| **T-189-23** (número do marcador ≠ o da lista) | **mitigado** | §11 — os dois lidos do MESMO `ordinal`, comparados **no HTML**, com fixture que DISCRIMINA (`4, 2`) e mutação M5 matando |
| **T-189-25** (coordenada por classe concatenada) | **mitigado** | `style` + gate proibindo `left-[${`/`top-[${` com isca; mutação M7 matando |
| **T-189-SC** (npm installs) | **mitigado** | nada instalado; PI sobre os dois arquivos de pacote |

⚠️ **Aprovar ENFILEIRA; a escrita no Mercado Livre é da Fase 193.** Este plano **não cria rota
nenhuma** e **não faz requisição ao ML** — ele não chama HTTP de forma alguma.
⚠️ **A Fase 192 ("✦ Gerar novamente com IA") NÃO foi antecipada**, e o "Comparar lado a lado"
(Fase 190) também não.

---

## §23 — O que a próxima wave precisa saber

| wave | o que consumir, e a armadilha |
|---|---|
| **189-04** | o `.jsx` está commitado em `d8b30fdf`. ⚠️ **O GATE-09 no CSS CONSTRUÍDO e o `npm run build` são seus** — eu provei na FONTE. ⚠️ **Rode o build DEPOIS de qualquer mutação** (o `cp` atualiza o `mtime` e a camada de CSS **PULA**, dando `skipped` que **não é regressão**), e **confira `git diff HEAD -- resources/` antes**: com `.jsx` alheio meio-salvo, **NÃO builde**. ⚠️ As classes novas a conferir no CSS: `z-[41]`, `grid-cols-[minmax(0,1fr)_320px]`, `bg-[rgba(5,5,5,0.94)]`, `w-[min(78vh,100%)]`, `h-[26px]`, `h-[56px]`, `rounded-pub-control-sm`, `h-[44px]`, `-mt-[22px]`, `text-[18px]`, `cursor-crosshair`, `cursor-zoom-out`. ⚠️ **As OITO normalizações do CSS construído** (learnings §3): o cssnano **come o zero da frente**, o escape é **GERAL**, opacidade é **hex de 8 dígitos** em `background`/`border` mas **`rgb(… / .18)`** em `--tw-ring-color`. ⚠️ E **`rgba(5,5,5,0.94)`** pode sair normalizado — meça, não presuma |
| **189-05** | **IMPORTE** `ROTULO_AMPLIAR` / `ROTULO_REDUZIR` / `rotuloDoZoom` / `alternarZoom` / `ZOOM_AMPLIADO` deste arquivo — **não redigite o literal**. ⚠️ A lateral entra pelo slot `lateral` do `RaizDoLightbox` (ou do default), que aceita **`isValidElement` e nada mais**. ⚠️ `onAlternarZoom` **não é prop do lightbox** (D3): você o recebe direto de quem monta a lateral. ⚠️ **O cabeçalho APOIO**: `CABECALHO_DAS_VERIFICACOES` é named export em `BlocoDaImagemSelecionada.jsx:73` (INV-07) — **importe**. ⚠️ `CLASSES_ITEM` (`:130`) tem `items-start` e o item do LIGHTBOX **NÃO** tem (`:760#1` × `:633`): **não reusar cru** |
| **189-06** | o lightbox é **CONTROLADO**: ele recebe `aberto`/`indice`/`zoom`/`origem` e devolve **PEDIDOS** `{indice, zoom}` por `onMover`/`onSelecionar`, e `{x,y}` por `onMoverOrigem`. ⚠️ **O pedido NÃO traz `origem`**, e isso é o contrato: trocar de foto **zera o zoom e PRESERVA a origem**. ⚠️ `resolverAtalhoDoPainel` **não tem** parâmetro de lightbox (INV-01/A7) — você o acrescenta. ⚠️ **ARMADILHA A2:** `casca-188:1035` varre a FONTE de `PainelDeRevisao.jsx` por `/Ampliar/` **sem comentário** — uma prop `onAmpliar` ali **REPROVARIA**. Use outro nome (ex. `onAbrirLightbox`). ⚠️ E `publicador-painel-fotos-188.test.js:639`/`:640` são as **duas únicas** linhas a inverter; `:641` (Gerar novamente) e `:1361` (asserção POSITIVA do protótipo) **não se tocam** |
| **189-07** | `escritasDaColuna({empresaId, ofertaId, versao, onConflito})` já aceita os três **por parâmetro** (INV-03/A6) — **chame com o contexto do LIGHTBOX**. ⚠️ O `ModalDeReprovarImagem` já está **FORA** do `<aside>` (INV-04/A1): **não mova montagem nenhuma**. ⚠️ O `z-[42]` da casca fica **acima** do meu `z-[41]`, e os dois são **irmãos na raiz** — o modal aberto pelo lightbox **aparece** |
| **189-08** | ⚠️ Registre as **três divergências** de §9 e a ressalva de que o **MOVIMENTO não é provado** (§8). ⚠️ `PERGUNTA-VISUAL-189` segue **ABERTA**, e as conferências visuais das Fases **187 e 188** também, **separadas** |

---

## Self-Check: PASSED

**Arquivos criados — `test -e`:**
```
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx   (890 linhas)
FOUND: tests/js/publicador-lightbox-casca-189.test.js                          (1501 linhas, 27 casos)
FOUND: tests/gates/contagens-189-03.txt                                        (2 ids)
FOUND: .planning/phases/189-…/189-03-SUMMARY.md                                (este arquivo)
```

**Commits — `git log --oneline --all | grep -q`:**
```
FOUND: 59446cdf  feat(189-03): as funcoes puras do lightbox — a volta, o clamp e a origem do zoom
FOUND: 9e54a884  test(189-03): o portal, a profundidade e os ordinais do marcador — RED medido
FOUND: d8b30fdf  feat(189-03): o lightbox POR PORTAL em z-[41] — a profundidade, nao a aparencia
```

**Mínimos de linha do plano:** `LightboxDaImagem.jsx` **890 >= 220** ✅ ·
`publicador-lightbox-casca-189.test.js` **1501 >= 260** ✅

**Gates:** `lightbox-casca` **27/27/0** ✅ · `vocabulario-tamanhos` **688 (>=688)** ✅ ·
`--autoteste` **20/0** ✅ · **PI(189-03)** interseção VAZIA, 2 iscas exercidas ✅ ·
**bijeção** zero órfão ✅ · **mutação** 7/0 sobreviventes ✅ ·
**6 gates vizinhos** verdes, nenhuma contagem alheia remedida ✅

**Suítes:** JS **5908 / 5907 / 1 / 0** (a única falha é a herdada da Fase 87, atribuída por execução
ISOLADA) · PHP recorte **2595 / 23878 / 0** — re-medido no FIM, **idêntico à PARTIDA**, como tem de
ser (este plano não toca um único arquivo PHP). ⚠️ A suíte PHP **completa não é medível** nesta
máquina (Fatal de memória em processo FILHO; `-d memory_limit=-1` não resolve).

**Entrada morta:** `IMPORTS=21 · IMPORTS_MORTOS=[]`, com isca nos dois lados ✅
