---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 08
subsystem: publicador-aprovacao-painel
tags: [tela, react, coluna-de-fotos, marcadores, fidelidade, mutacao]
requires:
  - "188-03 (FotosDoPainelService: o payload da coluna, os marcadores e os ordinais)"
  - "188-05 (as rotas de escrita: `fotos.sem-alerta` e `imagem.aprovar`)"
  - "188-07 (a casca do painel: o slot `colunaDeFotos`, `ColunaDoPainel` e `escreverNoPainel`)"
provides:
  - "resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx — a coluna de fotos do painel"
  - "resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx — a imagem 1:1, os 4 selos e os marcadores x/y"
  - "resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx — a grade de 6 colunas e as 5 bordas"
  - "resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx — as acoes da foto e as verificacoes"
affects:
  - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx (o slot `colunaDeFotos`, a selecao viva e as setas ←/→)"
  - "tests/js/publicador-vocabulario-novo.test.js (ARQUIVOS_NOVOS)"
  - "tests/js/publicador-fila-fechamento-187.test.js (JSX_DEPOIS_DA_187)"
  - "tests/js/publicador-fila-filtros-187.test.js (o total de `useState` virou LISTA DE NOMES)"
tech-stack:
  added: []
  patterns:
    - "coordenada de marcador por `style`, nunca por classe concatenada"
    - "ordinal LIDO do servidor, nunca recontado na tela"
    - "borda/anel resolvidos no cliente porque o payload esta preso na foto 0"
    - "escrita so pelo helper unico `escreverNoPainel` (versao_esperada sempre)"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx
    - tests/js/publicador-painel-fotos-188.test.js
    - tests/gates/contagens-188-08.txt
  modified:
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-fechamento-187.test.js
    - tests/js/publicador-fila-filtros-187.test.js
    - .planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-FID01.md
decisions:
  - "PERGUNTA-188-A aplicada (hibrido): cabecalho `VERIFICACAO AUTOMATICA · APOIO` UMA vez + o ordinal por item, separado da frase"
  - "PERGUNTA-188-D aplicada: aviso FIXO na coluna com `com_alerta_restantes`, SEM setimo toast"
  - "a borda de selecao segue a imagem SELECIONADA, nao a foto 0 (divergencia declarada do prototipo)"
  - "o rotulo de VARIACAO de `:623` NAO e desenhado: a chave do grupo e canonica (`COLOR=id:52049`), nao um nome — PERGUNTA-188-E DEVOLVIDA"
metrics:
  started: 2026-10-19
  completed: 2026-10-19
  partida: 4bcf236e
  commits: 4
---

# Fase 188 Plano 08: A coluna de fotos do painel Summary

A metade esquerda do painel de 1140px entregue na tela: o progresso nas três cores, "Aprovar N sem
alerta" com o N do servidor, a imagem 1:1 com os quatro selos e os **marcadores numerados nas
coordenadas x/y reais por `style`**, a grade de **6 colunas** com a precedência de **cinco** bordas
e o anel só na selecionada, e o bloco da imagem selecionada com o cabeçalho
`VERIFICAÇÃO AUTOMÁTICA · APOIO` uma vez + o **ordinal lido do servidor** ligando cada verificação
ao marcador certo.

---

## A PARTIDA

```
PARTIDA = 4bcf236e   docs(188-05): a SUMMARY do plano 05, a §M do FID01, e o ponteiro :1404
                     corrigido para :1402
```

⚠️ Capturada na **primeira ação** da wave, e usada **só como referência de leitura** — nunca como
extremo de range (ver *Território*, abaixo).

---

## AS BASELINES — na partida e no fim

| medida | na PARTIDA (4bcf236e) | no FIM | leitura |
|---|---|---|---|
| `--suite js` | **5568 / 5567 pass / 1 fail / 0 skipped** | **5632 / 5631 pass / 1 fail / 0 skipped** | +64, e a **mesma** falha pré-existente |
| PHP `tests/Feature/Publicador/Aprovacao` | **301 passed (5367 assertions) / 0 falhas** | **301 passed (5367 assertions) / 0 falhas** | **IDÊNTICO** |
| `npm run build` | exit **0** | exit **0** | — |
| MANIFEST (pelo JSON) | **495 entradas / 202 páginas** | **495 / 202** | nenhuma página nova |
| `--autoteste` | **20 casos / 0 divergência** | — | colado abaixo |
| protótipo | **1432 linhas**, `sha256 68624883ce35547e…` | idem | não se moveu |

### ⚠️ A conta do +64 fecha inteira, arquivo por arquivo

```
+32  tests/js/publicador-painel-fotos-188.test.js        (o gate NOVO deste plano)
+28  tests/js/publicador-vocabulario-novo.test.js        (569 -> 597: 4 arquivos x 7 sub-testes)
+ 4  tests/js/publicador-rename-em-analise-ml.test.js    (1 sub-teste por .jsx de aprovacao/)
───
+64  5568 -> 5632
```

⚠️ Os 7 sub-testes por arquivo no GATE-03 são os **6** do laço "arquivo por arquivo" **mais 1** do
laço de *"lucro líquido"* — conferido lendo os nomes dos sub-testes no log da suíte **depois** de ela
terminar, nunca durante.

### ⚠️⚠️ A WAVE 188-05 JÁ HAVIA FECHADO ANTES DA MINHA PARTIDA — nada veio dela no meio

O coordenador confirmou, e a medição bate: a PARTIDA `4bcf236e` **é** o commit de fechamento da
188-05, e o PHP de `Publicador/Aprovacao` mediu **301 / 5367 / 0 falhas** nas **duas** pontas, com
59 s e 162 s de duração. ➡️ **Nenhuma contagem PHP mudou por paralelismo**, e não houve nenhuma
falha da corrida de `storage/framework/testing` para atribuir (nenhum `UnexpectedValueException` em
`FilesystemIterator`, nenhuma falha em `SalvarEAprovarDoPainelTest` ou `EscritaDeImagemDoPainelTest`).
⚠️ As duas falhas conhecidas e alheias de `tests/Feature/Publicador/CockpitDaConta Test.php:966`/`:1318`
(**Fase 179**, fora de `Aprovacao/`) **não apareceram** nesta janela — elas oscilam com a hora do dia
e o recorte medido não as inclui.

### ⚠️ A única falha JS é a pré-existente, e ela NÃO foi tocada

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
             tests/js/estrutura-grade-glide.test.js  (Fase 87)
```

---

## ⚠️⚠️ A BORDA DE SELEÇÃO SEGUE A IMAGEM SELECIONADA, NÃO A FOTO 0

**O aviso do coordenador estava certo, e a causa é MEDIDA, não do protótipo:**

| lado | `arquivo:linha` | o que está lá |
|---|---|---|
| o servidor | `app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php:310` | `'fotos' => $this->fotos->para($oferta)` — **sem o `$selecionado`** |
| o default | `app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php:266` | `para(PubAprovacaoOferta $oferta, int $selecionado = 0)` |
| o protótipo | `design_handoff_publicador/Aprovacao.dc.html:1293` | `imgs[Math.min(selIdx, imgs.length - 1)]` — ele **é** dinâmico lá, mas o documento é estático |

➡️ `borda`, `anel`, `selecionada` e `selecionada_indice` do payload estão **presos no índice 0**.
Usá-los crus deixaria o anel amarelo na **primeira** miniatura para sempre, qualquer que fosse a foto
aberta.

**O que foi feito:** a seleção viva mora na página (`fotoAberta`, `Aprovacao.jsx`) e a borda é
resolvida na tela por `chaveDaBorda()` (`GradeDeMiniaturas.jsx:94`), **espelhando
`FotosDoPainelService::borda()` ramo por ramo** (`FotosDoPainelService.php:345`-`:364`).
⚠️ **Nenhum serviço da 186/187/188 foi alterado** — território somente leitura.
⚠️ **UM VOCABULÁRIO SÓ:** há asserção lendo as cinco constantes `BORDA_*` do **PHP** e comparando o
conjunto com o da tela (`deepEqual`), mais a prova de que as cinco dão **cinco classes distintas** —
um mapa que devolvesse a mesma cor para tudo passaria a primeira asserção sozinha.
⚠️ E a **fixture DISCRIMINA de propósito**: ela carrega `borda: 'selecionada'` e `anel: true` na foto
0, com a seleção viva no índice **1**. Há duas asserções travando isso: *"a miniatura 0 ficou com o
anel — a tela está lendo o `anel` PRESO do payload"* e *"a fixture deixou de carregar o `borda`
preso na foto 0 — ela não discrimina mais"*.

---

## ⚠️⚠️ O ORDINAL DO MARCADOR É O MESMO DO ITEM — LIDO, NUNCA RECONTADO

A prova não é "o código lê a prop": é **ler os dois números do HTML gerado, na mesma fixture**.

| o que é lido | de onde | medido |
|---|---|---|
| o número **sobre a foto** | o texto dentro do `<span>` cujo `style` é `left:37.5%;top:12.25%` e `left:80%;top:66.5%` | `[1, 2]` |
| o número **do item** | o texto dentro do `<span class="… flex-none text-[12px] font-bold …">` | `[1, 2]` |
| a igualdade | `assert.deepEqual(numerosDoItem, numerosDoMarcador)` | **iguais** |

⚠️ **E a prova de que é LIDO e não CONTADO:** uma segunda fixture com ordinais **`7` e `4`** (fora de
ordem e fora de `1..N`). A tela escreve `7` e `4` nos dois lugares; uma renumeração sairia `1, 2`.
Há mutação (`xi`) que troca `ordinal` por `1` fixo — e ela **MORREU**.

⚠️ **A assimetria declarada da §K está travada:** com `x` nulo no primeiro alerta, o marcador
sobrevivente continua escrevendo **`2`**, e não `1`. Renumerar faria o texto dizer "3" com o marcador
escrito "4", que é o erro pelo qual **a pessoa reprova a foto errada**.

⚠️ A armadilha de raiz que a wave 188-03 achou (`PubAprovacaoImagem::alertas()` sem `orderBy`, com
MariaDB e SQLite devolvendo ordens diferentes) está **normalizada no servidor** (`porId()`): a tela
**não reordena nada**.

---

## ⚠️ x/y SÃO PERCENTUAL, E VÃO POR `style`

`style={{ left: \`${marcador.x}%\`, top: \`${marcador.y}%\` }}` (`ImagemGrandeDoPainel.jsx:282`).

| caso | medido |
|---|---|
| `x: 37.5, y: 12.25` | `style="left:37.5%;top:12.25%"` |
| `x: 80, y: 66.5` | `style="left:80%;top:66.5%"` |
| `null` · `undefined` · `NaN` · `'50'` · `{toString:null}` · `[]` · `-0.01` · `100.01` · `1e308` · `Infinity` | **nenhum marcador**, e nada derruba |
| as duas pontas `0` e `100` | **desenham** (a isca que impede o bloco de ser vazio por construção) |

⚠️ **`typeof NaN === 'number'`** — a guarda é `Number.isFinite` + faixa, e não `typeof`. É a **segunda
tranca** da mesma porta: o servidor já saneia em `noQuadro()`.

⚠️ **O gate da forma ERRADA é na FONTE SEM COMENTÁRIO**, e isso é consequência de um achado desta
wave (abaixo): a sonda é `/(left|top)-\[\$\{/` com **isca nas duas direções** — ela acusa
`` className={`left-[${x}%]`} ``, **não** acusa `` style={{ left: `${x}%` }} `` e **não** acusa a
classe FIXA legítima `left-[10px]` de `:597`.

---

## ⚠️⚠️ ACHADO DE FERRAMENTA: O SCANNER DO TAILWIND LÊ **COMENTÁRIO**

A classe de exemplo que eu escrevi **na prosa** do componente — no docblock que explica por que a
coordenada **não** pode ir por classe — **virou regra de CSS de verdade**.

```
antes:  .left-\[37\.5\%\]{left:37.5%}   PRESENTE no CSS construído, 233 029 bytes
depois: ausente                          233 022 bytes   (o exemplo virou `left-[…%]`)
```

⚠️⚠️ **Duas consequências, e as duas mudam onde o gate pode morar:**
1. **Sonda de ausência de classe tem de ser na FONTE SEM COMENTÁRIO, nunca no CSS construído** — no
   CSS, o próprio docblock que PROÍBE a classe a faz existir, e o gate reprovaria o conserto. É a
   família *"palavra no docblock reprova pelo próprio conserto"*, com uma roupa nova: aqui ela
   reprova pelo **build**.
2. **`grid-cols-6` NÃO serve de isca no CSS**: ele **EXISTE** no CSS construído (outros donos) e
   compila para o **MESMO** valor que `grid-cols-[repeat(6,minmax(0,1fr))]`. ➡️ A isca do quase-igual
   da grade mora na **FONTE**. As quatro iscas confirmadas **ausentes** do CSS nesta wave:
   `bg-pub-isca-188-08`, `text-[97.5px]`, `w-[1993px]`, `top-[62.5%]`.

Registrado na `188-FID01.md` §N.

---

## ⚠️⚠️ A PRECEDÊNCIA DE CINCO BORDAS — provada COM CONFLITO, e as DUAS omissões do REVS-09

| caso | esperado | por que o caso existe |
|---|---|---|
| selecionada **E** com alerta | `selecionada` | **o conflito**: um estado por vez passaria com a ordem invertida |
| selecionada **E** aprovada **E** com alerta | `selecionada` | o conflito triplo |
| aprovada **E** com alerta | `alerta` | o 2º conflito |
| pendente **E** com alerta | `alerta` | — |
| aprovada sem alerta | `aprovada` | — |
| pendente sem alerta | `pendente` | — |
| `rejected` · `regenerating` · `replaced` · `removed` · chave nova · `null` · `undefined` | `neutra` | **o 5º ramo** |

**AS DUAS OMISSÕES DO REVS-09, declaradas com `arquivo:linha`:**

| # | o que o requisito omite | onde ele está | onde o protótipo tem |
|---|---|---|---|
| 1 | o **5º ramo** `#3F3F46` (`pub-line-off`) | `.planning/REQUIREMENTS-v27.md:596` lista **quatro** bordas | `Aprovacao.dc.html:1113` (`: '#3F3F46'`) |
| 2 | o **anel**, box-shadow **ADICIONAL** à borda | idem — ele não lista anel nenhum | `:1113` (`ring: sel ? '0 0 0 2px #FACC15' : 'none'`) |

⚠️ **O anel sai por `ring-2 ring-pub-accent`, e não por `shadow-[…#FACC15]`:** TOK-15 proíbe hex na
tela. Medido no CSS construído que a equivalência é exata —
`--tw-ring-shadow: … 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color)` com
`--tw-ring-color: rgb(250 204 21 / …)` e o offset default de `0px`.

⚠️ **O anel existe SÓ na selecionada:** `assert.deepEqual(comAnel, [1])` com a seleção no índice 1,
sobre as quatro miniaturas.

---

## ⚠️ A GRADE DE 6 COLUNAS, por IGUALDADE e com isca do quase-igual

```
grid grid-cols-[repeat(6,minmax(0,1fr))] gap-[6px]
```

Conferida por `assert.deepEqual` do **CONJUNTO** de classes (nunca `includes`, que casa prefixo **e**
sufixo de outro nome e já mordeu cinco vezes neste projeto), mais três asserções na fonte:
`grid-cols-6` **não** entra, `grid-cols-[repeat(6,1fr)]` **não** entra, e a isca prova que a sonda do
`grid-cols-6` acusa `className="grid grid-cols-6 gap-2"` e **não** acusa a classe certa.
⚠️ Mutação `iv` (`grid-cols-6`) **MORREU**.

---

## ⚠️⚠️ PERGUNTA-188-A — A TERCEIRA DERROTA DO PROTÓTIPO, aplicada

**Resposta do usuário em 18/10/2026, opção (c), HÍBRIDO:** o cabeçalho
`VERIFICAÇÃO AUTOMÁTICA · APOIO` **uma vez** no bloco da imagem selecionada, **e** cada item mantendo
**o número que o liga ao marcador x/y**.

**A razão escrita pelo usuário:** *o cabeçalho é o que diz **APOIO** — sem ele a verificação
automática pode ser lida como **VEREDICTO**, que é o que REVS-10 e a D7 querem impedir; e o ordinal é
**funcional** (liga o item ao marcador), não enfeite.*

| lado | `arquivo:linha` | o que está lá |
|---|---|---|
| o literal **existe** | `Aprovacao.dc.html:369` | `VERIFICAÇÃO AUTOMÁTICA · APOIO`, com `font-size:11px` e `letter-spacing:0.08em` — ⚠️ **mas é a revisão detalhada (E4)**, da Fase 190/191 |
| o painel **escreve** | `Aprovacao.dc.html:633` | `'Verificação automática' + a.n + ' · '` + texto, **minúsculo e sem cabeçalho** |
| o nosso | `BlocoDaImagemSelecionada.jsx:76` | o cabeçalho **uma vez** + o ordinal como **elemento próprio** |

⚠️ **A forma COLADA do `:633` NÃO foi reproduzida.** O `a.n` do protótipo está em **`:1108`** (e
**não** em `:1111`, como a prosa do plano dizia — `:1111` é o `ref`/`refSlot`, assunto do modal de
regeneração da Fase 192). Gate de ausência com **isca nas duas direções**: a sonda acusa
`'Verificação automática' + a.ordinal` **e** `` `Verificação automática${ordinal}` ``, e **não** acusa
o número desenhado separado. Mutação `viii` (a forma colada) **MORREU**.

⚠️ **Cabeçalho + caixa só com `verificacoes` não-vazia** — com `[]`, `null`, `'x'`, `7`,
`{toString:null}`, `[{toString:null}]` e `[{texto:'   '}]` o HTML sai **vazio**. Mutação `ix` (o
cabeçalho nascendo com a lista vazia) **MORREU**.

### As TRÊS derrotas anteriores do protótipo, para a próxima fase não reabrir nenhuma

| # | o protótipo queria | o projeto ganhou | `arquivo:linha` dos dois lados |
|---|---|---|---|
| 1 | `' d'` abreviado | `'3 dias'` por extenso | o `' d'` do protótipo × o vocabulário travado da Fase 186 |
| 2 | *"N ofertas aprovadas"* no toast do lote | o vocabulário travado de VOC-08 | `Aprovacao.dc.html:1167` × `vocabularioDoPortao.jsx:213`-`:220` |
| 3 | a frase minúscula sem cabeçalho | cabeçalho **uma vez** + ordinal separado | `:633` (e `:369`, que é E4) × `BlocoDaImagemSelecionada.jsx:76` |

---

## ⚠️⚠️ PERGUNTA-188-D — A QUARTA DERROTA DO PROTÓTIPO, aplicada nesta wave

**Resposta do usuário em 19/10/2026:** **texto FIXO na coluna de fotos. SEM sétimo toast.**

| lado | `arquivo:linha` | o que está lá |
|---|---|---|
| o protótipo | `Aprovacao.dc.html:1151` | `approveSafe(set)` → `this.toast('Fotos sem alerta aprovadas', 'Fotos com alerta automático continuam para conferência individual')` — ⚠️ **`:1151` CONFIRMADO por `sed`** |
| o vocabulário travado | `vocabularioDoPortao.jsx:213`-`:220` | **SEIS** toasts, e **nenhum** deles serve para *"aprovei 3 e sobrou 1 com alerta"* |
| o nosso | `ColunaDeFotos.jsx:108`-`:110` | `'1 foto ainda com alerta · confira uma a uma'` / `'3 fotos ainda com alerta · confira uma a uma'` |

**As razões do usuário, escritas no docblock:**
1. ⚠️ **A informação é ESTADO, não EVENTO.** *"Sobrou 1 com alerta"* é verdade até alguém agir; toast
   some em ~3,8 s. A decisão 8 pede que **a tela diga quem ficou de fora e por quê**, e aviso que
   desaparece não cumpre isso.
2. ⚠️ **"Toast + texto fixo" foi OFERECIDO e RECUSADO**, porque poria o MESMO número em **duas
   fontes** — e número repetido em dois lugares já divergiu neste projeto (é a razão da decisão 9, as
   duas margens, e das decisões 9/16 da fila, em que o usuário recusou conta divergente da lista
   **duas** vezes).
3. Não criar a 7ª frase mantém o vocabulário travado fechado — o mesmo motivo da 2ª derrota.

**O que está provado, com os números medidos:**

| exigência | prova |
|---|---|
| o número vem do **servidor** | `com_alerta_restantes = 3` na fixture, com **1** única foto `com_alerta` na lista → a tela diz **"3 fotos"**, e há asserção de que ela **não** diz "1 foto" |
| singular × plural | `1 foto ainda com alerta …` × `3 fotos ainda com alerta …`, e asserção de que o singular **não** sai no plural |
| ausência com 0 | `0`, `-1`, `null`, `undefined`, `NaN`, `'2'`, `{toString:null}`, `[]` → **nenhum aviso**; e a isca com `9` prova que o controle nasce |
| cor não é o único sinal | o aviso leva **glifo** (`!` em badge redondo) **e** texto — há asserção pelo glifo |
| **nenhum sétimo toast** | `assert.deepEqual` das **seis** chaves de `TOASTS`, mais a ausência de `Fotos sem alerta aprovadas` em `vocabularioDoPortao.jsx` **e** nos meus quatro `.jsx` |

⚠️ **Eu não havia escrito nenhum sétimo toast** — quando a resposta chegou, a wave estava no primeiro
componente, e o aviso nasceu já na forma decidida. Nada precisou ser retirado.
⚠️ **O layout medido não foi encolhido para caber:** a faixa de `:588` continua `justify-between`
com o progresso à esquerda e o botão à direita, e o aviso é **linha própria** logo abaixo, no mesmo
idioma da nota de conjunto de `:592` (`margin-top:-6px`). Mutação `x` (contar na tela) **MORREU**.

---

## ⚠️⚠️ PERGUNTA-188-E — DEVOLVIDA: o rótulo de VARIAÇÃO **não é derivável** da chave do grupo

**O briefing decidiu:** *"derive da CHAVE DO GRUPO, que já chega legível. O conjunto traz `GENERAL`
ou a forma `COR:Azul` — então "Cor · Azul" é derivável e verdadeiro"*, com a trava explícita:
*"Se a chave do grupo não bastar para algum caso real, PARE e devolva — não improvise."*

➡️ **Eu medi, e a premissa do formato é falsa.** A chave do grupo **não é** `COR:Azul`:

| o que a chave é | `arquivo:linha` | medido |
|---|---|---|
| ou a constante da galeria geral | `app/Models/PubAprovacaoConjunto.php:42` | `'GENERAL'` |
| ou `ChaveCanonica::combinacao()` | `app/Support/Publicador/Variacao/ChaveCanonica.php:39`-`:48` | `"{eixo}={valor}"`, vários juntados por **`\|`** |
| e o VALOR é canônico, não nome | `ChaveCanonica.php:24`-`:35` | `id:{value_id do ML}` **ou** `txt:{minúsculo, sem acento}` |
| a chave do EIXO é a do schema | `ResolvedorGruposImagem.php:32`-`:50` | `COLOR`, `SIZE`, ou `~custom` |
| e o texto cru no banco | `database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php:187` | `string('grupo_chave', 600)->default('GENERAL')` |

➡️ **Na prática a chave é `COLOR=id:52049`.** Derivar *"Cor · Azul"* dela exigiria (a) traduzir
`COLOR` → `Cor`, que é **tradução minha**, e (b) traduzir `id:52049` → `Azul`, que é
**impossível sem o `value_name`**. O nome legível existe em
`ResolvedorGruposImagem::rotuloDoGrupo()` (`:166`) — e **`App\Support\Publicador` é território
VETADO** nesta fase, exatamente como o briefing já antecipava (*"o único que existe está em
território VETADO"*).

**O que eu fiz:** a linha de `:623` sai **só com o tipo** (`Gerada por IA` / `Original`), **sem
separador pendurado**, e **a chave canônica NÃO vai para a tela** — há asserção de que nem
`COLOR=id:52049` nem `GENERAL` aparecem no HTML (*jargão não vai para a tela*).
⚠️ **Não inventei rótulo que o dado não sustenta**, que era a trava principal do briefing. O
precedente citado (a Fase 187 derivando `Capa`/`Foto N`) **não se aplica**: ali o dado existia e só
faltava o rótulo; aqui o dado **não existe no payload**.

```
PERGUNTA-188-E: ABERTA — dono: USUÁRIO — o rótulo de VARIAÇÃO de `:623` não é derivável.
  As opções, todas fora do que esta wave pode decidir:
  (a) `FotosDoPainelService::imagem()` passar a expor um `grupo_rotulo` legível — exige alterar o
      serviço da wave 188-03 (hoje somente leitura) e, por baixo, ler
      `ResolvedorGruposImagem::rotuloDoGrupo()`, que está em território VETADO;
  (b) deixar a linha só com o tipo, como está agora (nada é afirmado a mais, e a divergência fica
      declarada);
  (c) usar o booleano que JÁ chega (`grupo_geral`) e cunhar um rótulo novo para os dois casos —
      ⚠️ é frase NOVA, e as quatro derrotas do protótipo desta milestone foram TODAS decisão do
      usuário.
  APLICAR EM: 188-09 (se (a)), 188-13 (a ressalva, se ficar aberta).
```

---

## ⚠️ `✓ {quem}` (`:630`) — OMISSÃO DE DADO, declarada

O protótipo mostra *"Aprovada por X · data"* (`:1110`, `by`). `FotosDoPainelService::imagem()` expõe
**23 chaves** (`FotosDoPainelService.php:561`-`:588`) e **nenhuma** delas é `aprovada_por` ou
`aprovada_em`. ➡️ O elemento **não é desenhado**, e nada foi inventado no lugar: um `✓ Aprovada` sem o
nome só repetiria o selo de estado que já está sobre a imagem grande. Acrescentar as colunas ao
payload exigiria **alterar o serviço da 188-03**, que é somente leitura.

---

## ⚠️ "⤢ Ampliar" (189) e "✦ Gerar novamente com IA" (192) — NÃO desenhados, com gate de ausência

| botão | `arquivo:linha` do protótipo | fase dona |
|---|---|---|
| `⤢ Ampliar` | `:607` ⚠️ (o plano dizia `:608`, que é um **`</span>` puro**) | **189** (lightbox) |
| `✦ Gerar novamente com IA` | `:606` ⚠️ (o plano dizia `:607`) | **192** (modal de regeneração) |

⚠️ O gate de ausência roda com **as props preenchidas** (`assert.ok(completo.length > 1000)` antes),
para não ser *gate sobre zero casos*. E há sonda de `onClick` vazio, com isca nas duas direções —
`onClick` vazio é a mesma dívida que botão sem destino, com outra roupa.

⚠️ **"Gerando nova versão…" (`:605`) SIM é desta fase** — é **ESTADO**, não ação. O spinner usa
`animate-pub-spin` (os keyframes e a curva) **com a duração do protótipo por `style`**, porque os
dois valores são **diferentes e medidos**:

```
prototipo  :605          animation:spin .8s linear infinite
token      animate-pub-spin   animation:spin .7s linear infinite   (medido no CSS construido)
```

`duration-[Nms]` e `animate-[…]` arbitrários **não compilam** neste projeto (o `duration-[180ms]`
segue devolvendo `null`, com `duration-200` como controle).
⚠️ **O movimento no navegador NÃO é provado por esta fase** — o gate prova a fonte e o CSS, nunca a
animação rodando.

---

## ⚠️ "Aprovar N sem alerta" — o número do botão é o MESMO que a ação aprova

| exigência | prova |
|---|---|
| o rótulo é o do **servidor** | `sem_alerta.rotulo` → `>Aprovar 2 sem alerta<` |
| a tela **não reconta** | asserções de que ela **não** diz `Aprovar 3` (= `pendentes`) nem `Aprovar 4` (= `total`) |
| `null` com N = 0 **ou** fora do `live` | `sem_alerta: null` → **nenhum botão** (o `hasSafe` de **`:1305`**, e não `:1311`) |
| sem empresa / oferta / versão | **nenhum botão** — nunca escrita sobre versão que a tela não viu |

⚠️ A wave 188-05 mediu `quantidade = 3 → aprovadas = 3, grupos_percorridos = 2,
com_alerta_restantes = 1`, com a ação percorrendo **todos** os conjuntos (decisão 5: o bloqueio de
fotos cobre todos os grupos; aprovação cor por cor foi RECUSADA). ➡️ O N do botão e o N da ação têm
**uma fonte só**: `BloqueiosDaOfertaService::contagemDeFotos()`.
⚠️ Mutação `vii` (o rótulo contando as `pendentes`, inclusive as com alerta) **MORREU**.

---

## ⚠️⚠️ AS DUAS ESCRITAS VÃO PELO HELPER ÚNICO, E `versao_esperada` ENTRA SEMPRE

```
ROTA_SEM_ALERTA      = 'mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta'   {company, oferta}
ROTA_APROVAR_IMAGEM  = 'mlb.anuncios.meus.aprovacao.painel.imagem.aprovar'     {company, oferta, imagem}
```

Provadas **no CLIQUE**, com o `router` do stub **gravando** as chamadas (`renderToStaticMarkup`
**não dispara `onClick`**, e a tela preta da 187-06 foi no clique):

```
post  /mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta{"company":7,"oferta":12}
      corpo.versao_esperada = 3
post  /mlb.anuncios.meus.aprovacao.painel.imagem.aprovar{"company":7,"oferta":12,"imagem":901}
      corpo.versao_esperada = 3
```

⚠️ **Por que é o critério 17 e não estilo:** `versao_esperada` é **opcional no corpo**, e omiti-la faz
`AprovacaoDaOfertaService::exigirVersao()` receber `null` e **LIBERAR** a escrita — literalmente
*"sobrescrever em silêncio"*, numa tela em que aprovar **enfileira e não há desfazer**.
⚠️ `{imagem}` é `pub_aprovacao_imagens.id` (a linha de **REVISÃO**), nunca `pub_imagens.id`
(`routes/mlb_anuncios.php:556`-`:558`) — há asserção de que o id enviado é o `imagem.id`.
⚠️ **Nenhum `router.post` direto** nos quatro `.jsx`, com **isca do recorte**: o helper de verdade
(`PainelDeRevisao.jsx`) **usa** `router.post`, e a sonda recortada aos meus arquivos **não** o acusa.
O gate de lista CONSTANTE dos arquivos que escrevem mora no **188-12**, como o plano prescreve.
⚠️ **O painel APROVA (e aprovar ENFILEIRA), NÃO publica** — a escrita no Mercado Livre é da **Fase
193**, e está declarado no docblock da coluna.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `escritasDaColuna(null)` LANÇAVA — e era no CLIQUE**

- **Found during:** tarefa 3 (o gate de render adverso)
- **Issue:** a assinatura era `escritasDaColuna({ empresaId = null, … } = {})`. Desestruturação com
  default só cobre `undefined`; com **`null`** ela estoura em *"Cannot read properties of null
  (reading 'empresaId')"*. A TELA PRETA voltou **seis** vezes neste projeto e uma delas foi **no
  clique** — esta era exatamente no clique.
- **Fix:** a guarda virou `objetoDoPainel(pedido)`, **antes de tudo**.
- **Files modified:** `resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx`
- **Commit:** `6ea63331` (o conserto), achado pelo gate de `39541bd9`

**2. [Rule 2 - Correctness] `id = 0` DISPARAVA ESCRITA**

- **Found during:** tarefa 3 (o laço de ids adversos)
- **Issue:** `inteiroDoPainel` aceita `0`, e `0` é um id **plausível** para quem lê: a escrita saía
  para `{imagem: 0}`, rota que não existe. Numa tela em que aprovar enfileira e não há desfazer,
  **escrita disparada sobre id inválido é pior que nenhuma** — a pessoa vê a ação acontecer e nada
  muda.
- **Fix:** entrou `idDoPainel()`, que exige inteiro **POSITIVO**, aplicado a `empresaId`, `ofertaId`,
  `versao` e `imagem`.
- **Files modified:** `ColunaDeFotos.jsx`
- **Commit:** `6ea63331`

**3. [Rule 3 - Gate alheio] O SEXTO gate da família dos TOTAIS**

- **Found during:** a medição final de `--suite js` (2 falhas, acima da 1 esperada)
- **Issue:** `tests/js/publicador-fila-filtros-187.test.js:684` afirmava
  `assert.equal(usos.length, 2)` sobre a **contagem** de `useState(` na página — e o número **já
  havia subido de 1 para 2** no plano 187-09. O `fotoAberta` desta wave o reprovava.
- **Fix:** virou `ESTADO_LOCAL_DA_PAGINA`, **lista CONSTANTE de nomes** com a fase dona ao lado e
  `deepEqual` nas **duas** direções — mais forte que o total numa direção que ele **não cobria** (o
  total aceitava a REMOÇÃO do `menuAberto` compensada pela criação de outro estado). Com **sonda
  não-cega antes do `deepEqual`** e com a conferência de que a forma lida vê **todos** os `useState(`
  do arquivo. O invariante do caso (*nenhum FILTRO mora em `useState`*) continua provado pelo laço
  dos seis nomes de filtro.
- ⚠️ **NENHUM NÚMERO FOI SUBIDO:** `187-06 filtros-render` segue em `tests=29,19` e **remedido em
  29/29**.
- **Commit:** `e69f7584`

### ⚠️ TRÊS furos na PRÓPRIA SONDA (o 5º, 6º e 7º desta fase)

| # | o furo | o que ele fazia |
|---|---|---|
| 1 | `els[0]` media a **GRADE**, não a miniatura | a família dos **quatro** gates deste projeto que mediam o **IRMÃO ERRADO**; agora acha por `aspect-square` |
| 2 | o bloco de `fotos` adverso exigia HTML vazio para **objeto comum** | **REPROVAVA o comportamento correto** (objeto sem `imagens` é payload de galeria vazia, e aí o certo é o **vazio honesto**) — é a família *"gate mais largo que o invariante"*, que nesta árvore já barrou um deploy |
| 3 | `split(/\r?\n/)` no protótipo dá **1433**, não 1432 | a string vazia depois do `\n` final; a asserção reprovava por um motivo que **não é o arquivo** |

---

## A TABELA FID-01, por SCRIPT — `cursor`, `white-space` e `text-overflow` em COLUNAS PRÓPRIAS

> **Diretório citado:** `design_handoff_publicador/` (⚠️ há **DUAS** cópias do pacote com conteúdo
> diferente — esta é a de `Aprovacao.dc.html` com **1432 linhas** e
> `sha256 68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2`, conferido por
> `sha256sum` nesta wave). O pacote **não é versionado**: o bloco do gate que depende dele degrada
> para **`skip` EXPLÍCITO e NOMEADO**.

```
cursor:pointer          PRESENTE em  6 : 590 606 607 614 626 627
cursor:pointer          AUSENTE  em 20 : 589 592 594 596 597 598 599 600 601 603
                                         605 610 612 615 616 617 621 623 630 633
white-space:nowrap      PRESENTE em 13 : 590 598 599 600 601 603 605 606 607 623 626 627 630
white-space:nowrap      AUSENTE  em  2 : 589 597   <- as DUAS que a tabela dizia ter
text-overflow:ellipsis  PRESENTE em  1 : 623       (+ o wrapper `min-width:0` na MESMA linha)
```

⚠️ **O `nowrap` de `:589` e `:597` foi correção de VALOR da §C, e ela se confirmou:** `:589` não tem
`nowrap`, e o de `:597` está nos **FILHOS** (`:598`-`:601`), não no wrapper.
⚠️ **Os wrappers que a tabela OMITIA, todos reproduzidos:** `:604` (`right:10px;bottom:10px`, C11w —
sem ele os botões do overlay não têm posição nenhuma), `:622` (C21w), `:625` (C24w) e o wrapper
interno de `:623` com `min-width:0` (**sem ele o título NÃO trunca**).

### ⚠️ Os ponteiros RECONFIRMADOS por `sed`, com o número certo (§N do FID-01)

| o que a prosa do plano dizia | real | o que está DE FATO lá |
|---|---|---|
| `:606` / `:607` / `:608` (os três do overlay) | **`:605` / `:606` / `:607`** | `:608` é um **`</span>` puro** |
| `hasSafe` em `:1311` | **`:1305`** | `:1311` é o `selActions` |
| `isPending && inf.live` em `:1314` | **`:1311`** | `:1314` é `const S = this.state; const o = all.find(…)` |
| o `a.n` do item em `:1111` | **`:1108`** | `:1111` é `ref`/`hasRef`/`refSlot`/`refLabel` |
| `:1151` (o par de toast) | **`:1151`** | ✓ **CONFIRMADO** (`approveSafe`, com as duas frases) |

⚠️ **Quinta confirmação consecutiva da lição da §J** — e, de novo, **o ponteiro errado caiu em cima
de outro assunto real** (`:1111` é o modal da Fase 192; `:1311` é o `selActions`).
⚠️ **E o `<interfaces>` do plano divergia da §C do próprio FID-01** nos três do overlay: quem tinha
razão era a **§C**.

---

## As LISTAS CONSTANTES da Fase 187 que eu declarei

| lista | arquivo | o que entrou | número remedido |
|---|---|---|---|
| `ARQUIVOS_NOVOS` | `tests/js/publicador-vocabulario-novo.test.js` | os **4** `.jsx` | `vocabulario-tamanhos` **597** (era `>=569`), forma `>=`, **medido** |
| `JSX_DEPOIS_DA_187` | `tests/js/publicador-fila-fechamento-187.test.js` | os **4** `.jsx` | `187-11 fechamento-arquivos-e-manifestos` **6/6 — INALTERADO** (lista nominal, não total) |
| `ESTADO_LOCAL_DA_PAGINA` | `tests/js/publicador-fila-filtros-187.test.js` | `fotoAberta` | `187-06 filtros-render` **29/29 — INALTERADO** |

**As duas listas que eu NÃO precisei tocar, e isso é MEDIDO, não presumido:**

- `SLOTS_DE_NO_DEPOIS_DA_187` (nas **três** pontas): a coluna entra pelo slot `colunaDeFotos`, que
  **já existia** desde o 188-07, e eu **não abri `isValidElement(<slot>)` novo** na página — há
  asserção lendo os slots da página e cobrando que `colunaDeFotos` **não** esteja entre eles.
- `QUEM_USA_SHADOW_PUB_PAINEL`: **nenhum** dos meus `.jsx` escreve `shadow-pub-painel` — há asserção
  recortada à lista constante dos quatro arquivos.

⚠️ **Nenhum número foi subido, e nada foi revertido para contagem.**

---

## O manifesto, a bijeção e o homônimo

`tests/gates/contagens-188-08.txt`:

```
coluna-de-fotos       js  tests/js/publicador-painel-fotos-188.test.js      ::  tests=32,28
vocabulario-tamanhos  js  tests/js/publicador-vocabulario-novo.test.js      ::  tests=>=597
```

- **`vocabulario-tamanhos` em LINHA PRÓPRIA**, forma `>=`, **N medido nesta wave** (597 = 569 + 4 × 7)
  — a regra é *quem roda, declara*. O proibido é só embuti-lo num id que rode **outros** arquivos.
- **Os DOIS regimes de `coluna-de-fotos`, medidos por sonda de `mtime`:** pristino **32 / 30 pass /
  0 skipped**, mutado **28 / 25 pass / 1 skipped** (a camada de CSS construído cai em `skip` NOMEADO
  quando a fonte é mais nova que o CSS). ⚠️ E `cp -p` **preserva o `mtime`**, então a restauração
  devolve o regime pristino — conferido na mesma sonda.
- **Bijeção id ↔ `<verify>`, nas duas direções:** os dois ids aparecem em `<verify>` do
  `188-08-PLAN.md` (`coluna-de-fotos` nas três tarefas, `vocabulario-tamanhos` na tarefa 3), e todo
  id usado por um `<verify>` daquele plano está declarado no manifesto. **Zero id órfão.**
- **Nenhum id com mais de TRÊS métodos:** os dois ids são `js` e listam **um arquivo cada** (dois ids
  sobre o mesmo arquivo dariam a MESMA contagem duas vezes e nenhum mediria a sua metade).
- ⚠️⚠️ **HOMÔNIMO:** o `--filter` do PHPUnit é regex **não ancorado**, mas **só existe no modo
  `php`** — e este manifesto **não tem id `php`**. ➡️ Em vez de uma sonda **mais larga que o
  invariante** (o erro que a 188-07 mediu, com **82 homônimos FALSOS**), o gate prova o que é
  **verdade**: todo nome de teste do arquivo termina em **`188_08`**, e há asserção cobrando a
  **ausência** de id `php` — se um dia entrar, aquela sonda vira a de `--filter`.
- ⚠️ **O furo do `/test_[A-Za-z0-9_]+/` da 188-05 não se repetiu:** o expansor de **alternância**
  (`test_(a|b)` → `test_a`, `test_b`) está escrito e **testado contra um caso que DEVE casar** e
  contra um sem alternância.

### Os ids das waves anteriores, RE-RODADOS

```
188-01  tokens-e-tamanhos                   # tests  9   PASSOU   (inalterado)
188-01  vocabulario-tamanhos                # tests 597  PASSOU   (forma >=)
188-01  fid01-remedida                      Tests: 1 passed (13 assertions)  PASSOU  (apos a §N)
188-07  casca-do-painel                     # tests 45   PASSOU   (inalterado)
188-07  vocabulario-tamanhos                # tests 597  PASSOU   (forma >=)
188-08  coluna-de-fotos                     # tests 32   PASSOU
188-08  vocabulario-tamanhos                # tests 597  PASSOU
187-06  filtros-render                      # tests 29   PASSOU   (apos a cirurgia)
187-11  fechamento-arquivos-e-manifestos    # tests  6   PASSOU   (inalterado)
```

### ⚠️ `--autoteste` — 20 casos, 0 divergência (saída COLADA)

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

---

## A MUTAÇÃO — 12 mutações, **TODAS MORRERAM**

Scripts: `mutar-188-08.sh` (driver, com o `sha256` dos **dois** arquivos impresso antes do veredicto)
e `mutar-188-08.mjs` (o motor). ⚠️ **O nome carrega o plano nos dois**, porque o scratchpad é
**COMPARTILHADO** entre subagentes — na Fase 187 um `mutar.sh` genérico foi **sobrescrito** pela wave
vizinha e a campanha teve de ser refeita inteira.

```
=== CAMPANHA DE MUTAÇÃO 188-08 ===
  (i)    MORREU  linha ~282 · # tests 28 · exit 1 · a coordenada x/y por CLASSE concatenada
  (ii)   MORREU  linha ~92  · # tests 28 · exit 1 · a precedência da borda INVERTIDA (alerta × aprovada)
  (iii)  MORREU  linha ~94  · # tests 28 · exit 1 · o 5º ramo da borda REMOVIDO
  (iv)   MORREU  linha ~112 · # tests 28 · exit 1 · `grid-cols-6` em vez do `repeat(6,minmax(0,1fr))`
  (v)    MORREU  linha ~210 · # tests 28 · exit 1 · o badge `!` TAMBÉM em `approved`
  (vi)   MORREU  linha ~44  · # tests 28 · exit 1 · `title=""` no botão invisível da miniatura
  (vii)  MORREU  linha ~294 · # tests 28 · exit 1 · o rótulo contando as PENDENTES (com alerta)
  (viii) MORREU  linha ~243 · # tests 28 · exit 1 · o ordinal COLADO na frase (a forma do `:633`)
  (ix)   MORREU  linha ~224 · # tests 28 · exit 1 · o cabeçalho desenhado com a lista VAZIA
  (x)    MORREU  linha ~289 · # tests 28 · exit 1 · o aviso das restantes CONTANDO na tela
  (xi)   MORREU  linha ~257 · # tests 28 · exit 1 · o ordinal RENUMERADO na tela (1..N)
  (xii)  MORREU  linha ~194 · # tests 28 · exit 1 · o anel em TODAS as miniaturas
TODAS MORRERAM (12)
```

⚠️ **O `# tests 28` em TODAS as doze é a prova de que o arquivo CARREGOU** e que a morte é da
asserção, não do módulo: 28 é exatamente o **segundo regime** declarado no manifesto.
⚠️ A receita, ponto por ponto: **commit ANTES** (`39541bd9`); `sha256` contra `git show HEAD:` com
`\r` tirado dos dois lados **antes** de mutar; ocorrência **travada em 1 ANTES** (a substituição é por
**STRING EXATA**, nunca regex — o escape de shell/heredoc mastiga `\\` e backtick, e as âncoras deste
plano têm os dois); **aplicação provada por DIVERGÊNCIA de `sha256`** (a guarda *"a âncora antiga
sumiu"* ABORTA em réplica que contém a âncora); **linha conferida depois**; restauração **DA CÓPIA**
em `finally`, com `sha256` de volta igual ao do HEAD. ⚠️ **`git checkout` NUNCA.**
⚠️ **Nenhuma sobrevivente para investigar.**
⚠️ **`npm run build` rodou DEPOIS da mutação** (o `cp` da restauração atualizaria o `mtime` e a camada
de CSS pularia), com `git diff HEAD -- resources/` **vazio** antes do build — a wave 188-05 já havia
fechado e não deixou `.jsx` meio-salvo.

---

## O TERRITÓRIO — por ESCOPO DO ASSUNTO, nunca por RANGE

⚠️ *"Prova por RANGE"* **não passa nesta árvore** (a 187-11 mediu 111 commits no range, **43** da
sessão paralela). ⚠️ **E não se confere o AUTOR:** as três máquinas desta árvore commitam com o
**mesmo** `user.name` (`MB.ECF-100376`).

```
=== ANTI-FANTASMA (test -e em CADA alvo, ANTES do diff) ===
  fantasmas=0
  isca do anti-fantasma OK (o caminho inventado nao existe)

=== OS COMMITS DO ASSUNTO (188-08) ===
  39541bd9 test(188-08): o gate da coluna de fotos ...
  6ea63331 feat(188-08): a coluna de FOTOS do painel ...
  commits do assunto: 2            (+ e69f7584 e o docs desta SUMMARY, medidos depois)

=== PASSADA 1 — a UNIAO dos arquivos tocados pelos commits do assunto ===
  resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx
  resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx
  resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx
  resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx
  resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
  tests/gates/contagens-188-08.txt
  tests/js/publicador-fila-fechamento-187.test.js
  tests/js/publicador-painel-fotos-188.test.js
  tests/js/publicador-vocabulario-novo.test.js

=== PASSADA 2 — numstat de CADA commit, RECORTADO aos 20 alvos vetados ===
  acusados=0

=== ISCA DO RECORTE (as duas direcoes) ===
  isca positiva OK: a sonda VE ColunaDeFotos.jsx
  isca positiva OK: a sonda VE Aprovacao.jsx
  isca positiva OK: a sonda VE publicador-vocabulario-novo.test.js
```

⚠️ **`numstat` VAZIO** em `QuadradosDeFoto.jsx`, `vocabularioDoPortao.jsx`, `andamentoDoPortao.jsx`,
`SeloDeAndamentoInterno.jsx`, `CascaDeModal.jsx`, `ToastDaFila.jsx`, `PublicadorHeader.jsx`,
`AppLayout.jsx`, `publicador-header-zindex-ordem.test.js`, `estrutura-meus-anuncios.test.js`, nos
**três** serviços de `Painel/`, em `routes/mlb_anuncios.php`, em
`MlbPublicadorAprovacaoController.php`, no `ROADMAP.md`, no `STATE.md`, na `188-REGUA.md`, na
`188-COBERTURA.md` e no `188-08-PLAN.md`.
⚠️ **`REUSAR NÃO É EDITAR`:** `vocabularioDoPortao.jsx` é **importado** (os literais travados
`BLOQUEADO_PREFIXO` e `ABREVIACAO_DO_BLOQUEIO.sem_fotos`) e **não** é alterado.
⚠️ **`188-FID01.md` foi ESTENDIDO** (§N), que é o que a régua permite — e `fid01-remedida` segue
**verde** depois disso.

---

## Known Stubs

Nenhum. Os três lugares em que a tela **não desenha** algo do protótipo são **ausências declaradas
com dono**, não stubs:

| o que não nasce | por quê | dono |
|---|---|---|
| `⤢ Ampliar` / `✦ Gerar novamente com IA` | botão sem destino é D4 violado | Fases **189** / **192** |
| `Reprovar` sem handler | nasce com prop; `onReprovar = null` hoje | plano **188-12** (o modal) |
| `✓ {quem}` | o payload **não expõe** `aprovada_por` | ver *Omissão de dado*, acima |
| o rótulo de **variação** | a chave do grupo é canônica, não um nome | **PERGUNTA-188-E**, usuário |

---

## ⚠️ O QUE NÃO É REGRESSÃO MINHA, mas FOI MEDIDO e precisa de dono

**`186-01 gate-rename` e `186-01 rename-e-reapontes` REPROVAM — e já reprovavam antes desta wave.**

```
186-01  gate-rename          contagens aceitas: 58   # tests 84   REPROVOU
186-01  rename-e-reapontes   contagens aceitas: 820  # tests 846  REPROVOU
```

A aritmética prova que não é meu: `tests/js/publicador-rename-em-analise-ml.test.js` tem **1
sub-teste por `.jsx` de `aprovacao/`**, então sem os meus **4** arquivos ele mediria **80** e **842**
— ainda **≠ 58** e **≠ 820**. ➡️ As duas contagens são **TOTAIS FIXOS sobre varredura de diretório**,
a classe frágil que a régua nomeia, e elas quebraram quando a **Fase 187** acrescentou os seus ~20
`.jsx`.

⚠️ **Eu NÃO subi os números e NÃO toquei o manifesto da 186** (ela está **em produção**, e o conserto
certo é do lado dela: converter os dois ids para `>=` ou para lista constante). ⚠️ **A régua de "nada
quebrou" é a SUÍTE**, e ela está em **1 falha** — a pré-existente da Fase 87. **Devolvido como
achado, não consertado.**

---

## ⚠️ O QUE ESTA WAVE **NÃO PODE AFIRMAR**

- **Não vi tela renderizada.** Não há navegador nesta máquina, o banco local não tem empresa com
  `ml_token` (a rota dá **404**), `pub_aprovacao_ofertas` está vazia e a fila só nasce na Fase 194.
  ➡️ **Todo render é `esbuild` + `react-dom/server`: "a tela mostra X" significa "o HTML gerado contém
  X".**
- **O movimento do spinner não é provado** — a duração `.8s` está na fonte e no `style`; a animação
  rodando é comportamento de navegador.
- **As setas ←/→** usam `resolverAtalhoDoPainel()` (função pura, provada no 188-07) ligada por
  `onTrocarImagem`; o `addEventListener` em si **não** é provado por esta fase.
- **A conferência visual da Fase 187 pelo usuário segue ABERTA** — não a presumo feita.

---

## Threat Flags

Nenhuma. Esta wave não introduz endpoint, caminho de auth, acesso a arquivo nem mudança de schema:
ela **chama** duas rotas que o plano 188-05 já criou (com `throttle` e `whereNumber` próprios) e lê um
payload que o 188-03 já saneou. O `threat_model` do plano está coberto:

| Threat ID | disposição | como ficou |
|---|---|---|
| T-188-70 (campo adverso derrubando a página) | **mitigado** | 23 campos × 11 adversos + 12 campos de topo × 11 + props + **no clique** |
| T-188-71 (coordenada por classe) | **mitigado** | `style={{…}}` + gate na FONTE com isca nas duas direções; mutação `i` morreu |
| T-188-72 (selo vazio ou afirmando aprovação) | **mitigado** | texto não-vazio nos **6** estados; chave desconhecida cai no **NEUTRO**, nunca no verde |
| T-188-73 (coordenada fora de 0–100) | **mitigado** | `Number.isFinite` + faixa **no cliente também** |
| T-188-74 ("Aprovar N" aprovando mais do que diz) | **mitigado** | o N é o do servidor; asserções de que não é `pendentes` nem `total`; mutação `vii` morreu |
| T-188-75 (botão de outra fase sem destino) | **mitigado** | gate de ausência com props **preenchidas** + sonda de `onClick` vazio |
| T-188-SC (instalação de pacote) | **mitigado** | `git numstat` **vazio** em `package.json` e `package-lock.json` — esta wave não instala nada |

---

## Commits

| hash | tipo | o que entrou |
|---|---|---|
| `6ea63331` | `feat(188-08)` | os 4 `.jsx` da coluna, o slot na página, a seleção viva, e as duas listas constantes (`ARQUIVOS_NOVOS`, `JSX_DEPOIS_DA_187`) |
| `39541bd9` | `test(188-08)` | o gate (32 casos, dois regimes) e `contagens-188-08.txt` |
| `e69f7584` | `test(188-08)` | o **sexto** gate da família dos totais: o `useState` da página virou lista de nomes |

⚠️ **Nada foi deployado, nada foi empurrado, nenhum comando tocou a VPS, e a API real do Mercado
Livre não foi chamada.** ⚠️ **Nenhuma migration** — esta fase não tem.

---

## TDD Gate Compliance

⚠️ **Declaração honesta:** as três tarefas do plano estão marcadas `tdd="true"`, e eu **não separei
os gates RED e GREEN por tarefa** — os componentes foram escritos, medidos e consertados num ciclo
só, e o commit de teste (`39541bd9`) veio **depois** do de implementação (`6ea63331`). ➡️ **A sequência
de commits NÃO tem um `test(...)` antes do `feat(...)`.**

O que **de fato** aconteceu, e que é a substância do RED: o gate, ao ser escrito, **reprovou a
implementação em 8 casos**, e **dois** daqueles eram **defeitos reais de produção** (o
`escritasDaColuna(null)` lançando no clique e o `id = 0` disparando escrita) — os dois estão
documentados em *Deviations* e consertados no código, não na asserção. Os outros três eram furos na
própria sonda, também consertados do lado do teste. ⚠️ **Registrado aqui em vez de maquiado**, porque
o valor da régua é saber qual gate rodou primeiro.

---

## Self-Check: PASSADO

```
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx
FOUND: tests/js/publicador-painel-fotos-188.test.js
FOUND: tests/gates/contagens-188-08.txt
FOUND: 6ea63331   FOUND: 39541bd9   FOUND: e69f7584
```
