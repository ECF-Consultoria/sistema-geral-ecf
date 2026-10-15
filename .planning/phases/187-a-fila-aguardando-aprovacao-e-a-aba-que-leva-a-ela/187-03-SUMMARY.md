---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 03
subsystem: publicador-publicacoes
tags: [abas, cabecalho, fidelidade, render-adverso, territorio, mutacao]
requires:
  - "187-01 (os 5 tokens, os 2 tamanhos, o harness `187-NN` e a tabela FID-01 do E1)"
  - "187-02 (a rota `mlb.anuncios.meus.aprovacao` e a prop `aguardando_aprovacao`)"
provides:
  - "resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx — as duas abas da seção A1"
  - "o slot `abas` em `CabecalhoDePublicacoes.jsx` (e o cabeçalho sem subtítulo, com `border-b`)"
  - "a montagem em `MeusAnuncios.jsx`, com os dois contadores reais do servidor"
affects:
  - "a tela de Publicações, que está EM PRODUÇÃO"
  - "tests/js/publicador-publicacoes-cabecalho.test.js (6 reapontes com veto)"
  - "tests/js/publicador-vocabulario-novo.test.js (declaração + 3 reapontes com veto)"
tech-stack:
  added: []
  patterns:
    - "aba ativa como `<span>` sem `href` (não navega para si mesma — D4)"
    - "aba inativa como `<Link>` do Inertia, que renderiza `<a href>` sem violar o D-11"
    - "sem destino conhecido a aba deixa de ser link e NÃO ganha `href=\"#\"`"
    - "`0` medido desenha a pílula; ausente/nulo/objeto/NaN NÃO desenham nada"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx
    - tests/js/publicador-abas-da-pagina-187.test.js
    - tests/gates/contagens-187-03.txt
  modified:
    - resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes.jsx
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - tests/js/publicador-publicacoes-cabecalho.test.js
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "A pílula amarela é da ABA, não do ESTADO: ela é o sinal de atenção da fila e o protótipo a pinta só lá"
  - "Chave `ativa` desconhecida NÃO marca aba nenhuma (precedente de `AbasDeStatus.jsx`), em vez de inventar uma ativa"
  - "O gate D2 da Fase 186 ganhou UMA exceção de UM arquivo e UM rótulo, e virou TRÊS asserções onde havia UMA"
metrics:
  commits: 6
  duracao: "~2 h"
  concluido_em: 2026-10-15
---

# Fase 187 Plano 03: As duas abas da seção A1 Summary

As duas abas no cabeçalho de Publicações — a ativa como `<span>` que não navega para si mesma, a
inativa como `<Link>` com a rota que o 187-02 criou e uma pílula amarela com o número **real** do
servidor —, o cabeçalho sem subtítulo e com `border-bottom`, e a lista intocada, com 10 mutações
mortas e 0 sobreviventes.

---

## 1. As baselines RE-MEDIDAS — e as duas que o briefing errou

| medida | briefing deste executor | **MEDIDO AGORA** (commit de partida `be122baf`) | depois do plano |
|---|---|---|---|
| JS | `4852 / 4846 pass / 1 fail / 5 skip` | **`4862 / 4856 pass / 1 fail / 5 skipped`** | **`4994 / 4988 pass / 1 fail / 5 skipped`** |
| PHP | `2373 / 20.473 / 2` | **`2373 / 20478 / 0`** | **`2373 / 20478 / 0`** (idêntico — este plano não toca PHP) |
| `--autoteste` | 16 casos | **16 casos, 0 divergência** | 16 casos, 0 divergência |
| manifest | — | — | **489 entradas / 200 páginas / isca ausente** |
| CSS construído | `app-BZcBiEAW.css`, 227.384 bytes (187-01) | — | **`app-M2A6TYMm.css`, 227.403 bytes (+19)** |

Todas por `sh tests/gates/verificar-testes.sh --suite <js\|php>`. **Nenhuma veio de pipeline
artesanal** (`node --test | grep "^# tests"` nunca passa no Node v24; `artisan test | grep Tests:`
passa com teste falhando).

### 1.1 ⚠️ A única falha de JS é a PRÉ-EXISTENTE, e NÃO foi corrigida

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
```
`tests/js/estrutura-grade-glide.test.js` (Fase 87), a mesma que `REF_JS_FALHAS=1` já aceita desde a
Fase 186. Localizada por semântica, não por número de linha.

### 1.2 ⚠️⚠️ AS 2 FALHAS DE PHP QUE O BRIEFING ANUNCIOU **NÃO EXISTEM MAIS**

O briefing avisou: *"as 2 falhas de PHP NÃO são da fase"*. **Medido no commit de partida, antes de
qualquer edição minha: `2373 / 20478 / 0` — ZERO falhas.** A falha herdada que o `187-BASELINES.md`
§2.2 registrou (`VinculoDuravelDaOriginalTest::terr_11`, o gate de território por RANGE da Fase 186
acusando `feat(196)` da sessão paralela) **passou a passar** — compatível com o commit `a3169a03`
(`fix(186-06): o gate acusava o OUTRO DEV de mexer no proprio arquivo dele`), que está no histórico.

➡️ **Nada foi consertado por mim, nenhum `REF_PHP_FALHAS` foi criado**, e o número a usar como
"antes" desta wave é **`2373 / 20478 / 0`**, não `2373 / 20473 / 2`. Este plano **não escreve uma
linha de PHP** (há asserção provando que nenhum commit dele toca `.php`).

### 1.3 Os 5 tokens da wave 1 **continuam em skip** — e isso é medido, não esquecido

O briefing esperava que esta wave acordasse os skips nomeados por token do 187-01. **Não acorda, e
não deveria**: os 5 tokens (`bg-pub-sunken-2`, `bg-pub-float`, `border-pub-float-line`,
`bg-pub-anel-parcial`, `shadow-pub-flutuante`) foram medidos no **E1** (`Aprovacao.dc.html`), que é a
tela da FILA — waves 4 e seguintes. A seção A1 não usa nenhum deles. Os 5 skips seguem nomeados na
suíte (`skipped 5`), com a razão escrita.

**O que de fato NASCEU no CSS com este plano** (medido: zero outros `.jsx` as usam):

| classe | regra emitida |
|---|---|
| `mb-[-1px]` | `margin-bottom:-1px` |
| `border-b-[2px]` | `border-bottom-width:2px` |

E as outras medidas da A1, conferidas no CSS pelo **JSON do manifest** (`manifest['resources/js/app.jsx'].css[0]`, nunca por grep — o hash sai com hífen):
`min-w-[22px]` → `min-width:22px` · `text-pub-page` → `rgb(10 10 10 / …)` ·
`border-pub-line-subtle` → `rgb(31 31 31 / …)` · `gap-[14px]` → `gap:14px`.
**Isca `.bg-pub-isca-187-03` ausente do CSS.**

⚠️ O `22` e o `9.5` liberados na wave 1 são de **`TAMANHOS_PERMITIDOS`**, que é lista de
**`font-size`**. O `22` da pílula é `min-width:22px` — outra propriedade, outra lista. Nenhum dos dois
foi tocado por este plano, e nenhum tamanho novo foi liberado: `14`, `12` e `11.5` já estavam lá.

---

## 2. A tabela FID-01 da seção A1 — MEDIDA POR SCRIPT, com `cursor` em coluna própria

⚠️ **DECLARO COMO MEDI: por SCRIPT**, não por leitura. O script é a varredura do
`187-BASELINES.md` §5 adaptada à faixa `:31`-`:37`, mais um extrator de `style="…"` por linha e por
filho aninhado. Os dois estão em
`…/scratchpad/187-03/varrer-a1.sh` e `…/scratchpad/187-03/fid01.py`.

⚠️ **O DIRETÓRIO, citado:** `design_handoff_publicador/Publicacoes v2.dc.html`, **659 linhas, sha256
`56e6c194b896151c`** — a **AUTORIDADE** da seção A1. A outra cópia
(`new_layout_publicador/Publicacoes v2.dc.html`, 656 linhas, `6dcbb2f9ff8782e0`) é a versão **ANTIGA**,
com subtítulo e sem aba nenhuma.

| elemento | protótipo (`design_handoff_publicador/Publicacoes v2.dc.html`) | classe EXATA implementada | `cursor` |
|---|---|---|---|
| `<header>` `:31` | `display:flex;align-items:flex-end;justify-content:space-between;gap:24px;border-bottom:1px solid #1F1F1F` | `flex items-end justify-between gap-[24px] border-b border-pub-line-subtle` | **(nenhum)** |
| coluna do H1 `:32` | `display:flex;flex-direction:column;gap:14px` | `flex flex-col gap-[14px]` | **(nenhum)** |
| H1 `:33` | `margin:0;font-size:28px;line-height:1;font-weight:600;letter-spacing:-0.025em` | `m-0 text-[28px] font-semibold leading-none tracking-[-0.025em]` (não mudou) | **(nenhum)** |
| trilho das abas `:34` | `display:flex;gap:20px;margin-bottom:-1px` | `flex gap-[20px] mb-[-1px]` | **(nenhum)** |
| aba `:35`/`:36` (base) | `white-space:nowrap;display:flex;align-items:center;gap:8px;height:40px;font-size:14px` | `flex h-[40px] items-center gap-[8px] whitespace-nowrap text-[14px]` | **(nenhum)** |
| aba ATIVA `:35` | `border-bottom:2px solid #FACC15;font-weight:600` — **e NENHUM `color`** | `border-b-[2px] border-pub-accent font-semibold` | **(nenhum)** |
| aba INATIVA `:36` | `border-bottom:2px solid transparent;font-weight:500;color:#8A8A90` | `border-b-[2px] border-transparent font-medium text-pub-txt-3` | **(nenhum)** |
| contador simples `:35` | `font-size:12px;color:#71717A` | `text-[12px] text-pub-txt-4` | **(nenhum)** |
| pílula `:36` | `min-width:22px;height:20px;padding:0 7px;border-radius:999px;background:#FACC15;color:#0A0A0A;display:grid;place-items:center;font-size:11.5px;font-weight:700` | `grid h-[20px] min-w-[22px] place-items-center rounded-full bg-pub-accent px-[7px] text-[11.5px] font-bold text-pub-page` | **(nenhum)** |
| bloco da direita `:39` | `display:flex;align-items:center;gap:10px` | `flex items-center gap-[10px]` (**não mudou**) | **(nenhum)** |

### ⚠️ `cursor` — a coluna própria, e aqui ela é sobre o EXCESSO

```
faixa :31-:37    cursor:pointer  -> 0
faixa :31-:37    cursor (todas)  -> 0
faixa :31-:37    href=           -> 1       onClick= -> 0
```
**Zero ocorrências.** No E1 (`Aprovacao.dc.html`) são 16 e a regra lá é uniforme (*"nenhum elemento
com `onClick=` está sem `cursor:pointer`"*); **na A1 o único elemento clicável é o `<a>` da aba
inativa, e ele NÃO declara cursor** — pega o do navegador. ➡️ **Nenhum `cursor-pointer` foi
acrescentado**, com gate de AUSÊNCIA na fonte e no HTML, e **controle positivo** no
`CabecalhoDePublicacoes.jsx` vizinho, que TEM o cursor porque o protótipo o declara em `:42`.

### ⚠️ A medida que a tabela do plano OMITIU: a aba ATIVA não tem `color`

Medido: as três cores da A1 são `#8A8A90` (aba inativa), `#71717A` (contador simples) e `#0A0A0A`
(texto da pílula). **A aba ativa HERDA.** Pôr cor nela seria excesso — e a tabela deste projeto erra
por omissão **e** por excesso.

---

## 3. O contador vem da PROP, e nada é somado no cliente

| prova | onde |
|---|---|
| a página passa a prop NUA | `assert.equal(m[1].trim(), 'aguardando_aprovacao')` sobre o valor extraído de `aguardando={…}` |
| nenhum operador de soma dentro do slot | `+`, `reduce`, `filter`, `length`, `Object.values`, `Object.keys` proibidos **dentro do valor passado** |
| a página não nomeia os estados internos da fila | `(FONTE_PAGINA.match(/andamento/g) ?? []).length === 0`, **com isca** provando que a sonda acha a palavra quando ela existe |
| a prop está DECLARADA | `/aguardando_aprovacao\s*=\s*null/` na assinatura — consumi-la sem declarar a deixaria `undefined` para sempre, com a pílula nunca desenhada e nenhum teste de render reclamando |
| o contador da OUTRA aba também é real | `publicadas={numeroSeguro(objetoSeguro(subTotais).publicados)}` |
| nenhum número do protótipo cravado | ausência de `>24<`, `=24` e `=6` na fonte do componente, **com isca** (`'<span>24</span>'` casa a sonda) |
| o número do servidor é o do serviço | `PubAprovacaoOferta::LIVE`, lido do modelo dentro de `contadorDaAba()` (187-02) |

E o **zero**: `contadorMedido(0)` devolve `0` e **desenha a pílula** (`>0</span>`), porque zero medido
é um fato e a fila nasce vazia até a Fase 194. Ausente/nulo/objeto/array/string/`NaN`/`Infinity`/
fracionado/negativo devolvem `null` e **nenhuma pílula é desenhada** — em vez de um "0" que ninguém
mediu. **A aba não é escondida em nenhum caso.**

---

## 4. A mutação do `href` para `#` — e as outras nove

**Protocolo:** commit ANTES (`3fe9655d`) · `cp` dos três arquivos para o scratchpad · ocorrência
**travada em 1 ANTES** de substituir · linha conferida DEPOIS · restauração **DA CÓPIA** em
`trap EXIT` · **`git checkout` nunca** · `sha256` da árvore contra `git show HEAD:` no fim.

| # | mutação | gate | resultado |
|---|---|---|---|
| 1 | o `<header>` perde o `border-b` | `cabecalho-sem-subtitulo` | ✔ MORTA |
| 2 | a coluna do título volta para `gap-[6px]` | `cabecalho-sem-subtitulo` | ✔ MORTA |
| 3 | a aba ativa perde o peso 600 | `abas-da-pagina` | ✔ MORTA |
| 4 | a pílula perde o fundo amarelo (vira `<span>` sem fundo) | `abas-da-pagina` | ✔ MORTA |
| **5** | ⚠️ **o `href` da aba vira `'#'`** | `montagem-e-territorio` | **✔ MORTA** |
| 6 | o contador vira o literal `6` do protótipo | `montagem-e-territorio` | ✔ MORTA |
| 7 | o trilho perde o `mb-[-1px]` | `abas-da-pagina` | ✔ MORTA |
| 8 | a aba ativa ganha `href` (navega para si mesma — D4) | `abas-da-pagina` | ✔ MORTA |
| 9 | o subtítulo VOLTA ao cabeçalho | `cabecalho-sem-subtitulo` | ✔ MORTA |
| 10 | contador ausente passa a desenhar um `0` inventado | `abas-da-pagina` | ✔ MORTA |

```
CAMPANHA: 10 morta(s), 0 sobrevivente(s)
--- sha256: arvore × git show HEAD: ---
  OK  resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx
  OK  resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes.jsx
  OK  resources/js/Pages/Mlb/MeusAnuncios.jsx
```

A mutação **5** é a que importa: ela é a prova mecânica de que **a aba não aponta para o vazio**.

---

## 5. A LISTA NÃO MUDOU (ABA-06 / critério 4)

| o que | prova |
|---|---|
| busca, filtros, agrupamento, painel, lote, atalhos, header | os 13 outros `.js/.jsx` de `publicacoes/` com **diff VAZIO** no range `be122baf..HEAD` **filtrado pelo escopo `(187-03)`** |
| os 13 componentes continuam MONTADOS | `<BuscaDePublicacoes`, `<AbasDeStatus`, `<LinhaDeControles`, `<ChipsDeFiltro`, `<ChipsDeTriagem`, `<CabecalhoDaTabela`, `<LinhaDePublicacao`, `<LinhaDeGrupoDeProduto`, `<RodapeDaTabela`, `<VaziosDaLista`, `<RascunhosPainel`, `<ModalDetalheAnuncio`, `<PublicadorHeader` + `interpretarTecla` e `idsNaOrdemVisual` |
| D-11 intacto | sem `router.put/delete/patch`, sem `axios`, sem `fetch(`, sem `<form`, sem `router.visit(`, e **exatamente UM** `router.post` → `mlb.anuncios.meus.atualizar` |
| D-22 intacto | `de 86` no dono (`publicacoes/CelulasDaLinha.jsx`) e `de 100` em lugar nenhum |
| `estrutura-meus-anuncios.test.js` e `publicador-header-zindex-ordem.test.js` | **`numstat` ZERO** · e o primeiro roda verde: **30/30** |
| `identidade/`, `visaogeral/`, `header/`, `aprovacao/`, `Publicador/Mesa/`, `Editor.jsx`, `AnunciarML.jsx`, `AppLayout.jsx` | diff VAZIO |
| `app/` inteiro | diff VAZIO (+ asserção de que **nenhum commit deste plano toca `.php`**) |
| `package.json`, `package-lock.json`, `composer.json`, `composer.lock` | diff VAZIO (T-187-SC) |
| camada nova de `z-index` | nenhuma: `z-*` ausente do componente novo (a A1 não tem `z-index` no protótipo; a ordem travada `filtros 10 < header 30 < menu/painel 40` não foi tocada) |
| `localStorage`/`sessionStorage` | ausentes |

⚠️ **Como o gate de território evita acusar a sessão paralela:** o range `be122baf..HEAD` é
**filtrado pelo escopo do assunto `(187-03)`**, e **o autor NÃO é conferido** (`user.name` é o mesmo
nas três sessões). Há âncora positiva (`commits >= 1`), `test -e` em cada caminho (6 caminhos
fantasma neste projeto) e **isca** provando que o mesmo medidor ACHA os arquivos deste plano. No
range existe um commit alheio (`dc8fe723 docs(200)`) e ele é **ignorado pelo filtro de escopo** —
que é exatamente o furo que o `187-BASELINES.md` §2.2 documentou.

---

## 6. `ARQUIVOS_NOVOS`, o GATE-03 e os reapontes

`AbasDaPagina.jsx` foi declarado em `ARQUIVOS_NOVOS` **no mesmo plano que o cria** — `DIR_PUB` está
em `DIRETORIOS_FECHADOS` e o GATE-03 faz `deepEqual(noDisco, declarados)`.
**GATE-03 verde: 399 → 407 casos, 0 falha** (+6 do laço por arquivo, +2 dos sub-testes novos do eixo
D2).

**Nenhuma asserção do tipo "os N arquivos da Fase 187 estão na lista" foi criada** — a contagem fixa
da fase entra UMA vez, no 187-10.

### Os reapontes, todos com VETO escrito e `+assert > -assert` no MESMO teste

| arquivo | asserção de antes | reaponte | o veto |
|---|---|---|---|
| cabeçalho | classes do `<header>` | `+ border-b border-pub-line-subtle` | sem a borda a aba ativa não tem linha para cobrir com o `mb-[-1px]` |
| cabeçalho | `['flex','flex-col','gap-[6px]']` | `gap-[14px]` | `gap-[6px]` era a medida do subtítulo colado no H1 |
| cabeçalho | `includes('Ofertas ativas…')` | ausência no HTML **e** no arquivo + `MOD.SUBTITULO === undefined`, com ISCA | literal morto volta à tela por engano |
| cabeçalho | "subtítulo `:34`" | o nó depois do H1 é o TRILHO, por árvore, dentro da coluna do título | a `<span>` não existe mais; manter o teste nela o deixaria **vazio por construção** (`primeiraTagApos` passaria a achar a `<span>` do "Sincronizado X") |
| cabeçalho | "bloco da direita `:36`" | `:39`, + ausência de `pb-[12px]` com isca | referência era da cópia ANTIGA; e a direita NÃO muda |
| cabeçalho | CSS "(v) subtítulo" (`text-[13.5px]`) | CSS da borda `border-pub-line-subtle` | `text-[13.5px]` é usada por **43** outros `.jsx`: o caso continuaria VERDE para sempre provando um fato verdadeiro sobre um elemento que este arquivo não desenha mais |
| vocabulário | `filter(startsWith(DIR_PUB)).length === 15` | `DA_FASE_180.length === 15` + os NOMES, infratores coletados, `existsSync` contra fantasma | contava o DIRETÓRIO, não a fase: todo plano da 187 teria de **subir dois números de uma fase FECHADA**, e a régua aceitaria a REMOÇÃO de um arquivo da 180 compensada pela criação de um da 187 |
| vocabulário | `…length === 16` (diretório + página) | `DA_FASE_180 ∪ {PAGINA}` + asserção de que `AbasDaPagina.jsx` está em `ARQUIVOS_NOVOS` e FORA de `DA_FASE_180` | sem a segunda, voltar ao `startsWith` não moveria número nenhum e o reaponte seria revogado em silêncio |
| vocabulário | D2 — ver §8 | exceção de UM arquivo e UM rótulo + 2 sub-testes novos | ver §8 |

**CONTEI TESTES, não linhas:** nenhum dos reapontes removeu um teste; cada um ganhou asserções.
`publicador-publicacoes-cabecalho.test.js` foi de **82 → 86** casos;
`publicador-vocabulario-novo.test.js` de **399 → 407**.

---

## 7. Os gates, o manifesto e a bijeção

```
abas-da-pagina             PASSOU   (111 pristino / 101 mutado)
cabecalho-sem-subtitulo    PASSOU   ( 86 pristino /  76 mutado)
montagem-e-territorio      PASSOU   (518 pristino / 508 mutado)
AUTOTESTE: 16 casos, 0 divergencia
```

Todos por `sh tests/gates/verificar-testes.sh 187-03 <id>`. **Nunca `node --test` direto.**

**Os DOIS regimes são MEDIDOS**, não precaução: as duas camadas de CSS construído pulam (com `skip`
NOMEADO) quando o `app-*.css` é mais antigo que as fontes — e **mutar uma fonte a deixa mais nova**.
Sem os dois números a campanha de mutação abortaria na 1ª execução ou marcaria "SOBREVIVEU" por um
motivo que não é o código.

**A bijeção ids ↔ arquivos de teste** vale nos DOIS sentidos, com o lado *"o que este plano toca"*
**medido no GIT** pelo escopo do assunto — nunca por lista à mão. Ela é **um número FIXO de 7
sub-testes que coleta infratores em laço**, então acrescentar id ao manifesto **não move contagem
nenhuma** — e é por isso que ela não precisou de id próprio, ao contrário do 187-02 (lá a bijeção era
uma asserção por LINHA do manifesto, e a contagem do id dependia do próprio manifesto).

⚠️ **`tests/js/estrutura-meus-anuncios.test.js` NÃO está no manifesto, de propósito**: este plano não
o toca, e declará-lo criaria id apontando para arquivo intocado — gate sobre ZERO casos, que a própria
bijeção reprovaria com razão.

---

## 8. ⚠️⚠️ PERGUNTA EM ABERTO — o gate D2 da Fase 186 reprovou, e eu fiz a cirurgia

**O que aconteceu.** `tests/js/publicador-vocabulario-novo.test.js` tem o gate
**`D2 — NENHUM rótulo de andamento INTERNO aparece em publicacoes/`**, da Fase 186 (**em produção**,
deploy `a4c4187f`). Ele reprovou `AbasDaPagina.jsx` pelo literal **"Aguardando aprovação"** — que
**ABA-01/ABA-02 exigem** (`REQUIREMENTS-v27.md:310`-`:317`).

**Por que decidi que é PROXY LARGO, e não conflito real.** O D2, no texto:

> *"O **andamento interno** (8 estados da ECF) nunca aparece **na lista de Publicadas**; […] Isto é
> **regra de dado, não de tela** (AND-02)."* — `REQUIREMENTS-v27.md:51`-`:53`

O gate implementou a regra por um **proxy de tela**: *"em nenhum arquivo de `publicacoes/`"*. O
rótulo em `AbasDaPagina.jsx` é a **NAVEGAÇÃO PARA o outro eixo**, no cabeçalho — não um dado de
linha da lista. O proxy ficou mais largo que o invariante e passou a reprovar justamente o elemento
que **separa** os dois eixos.

**O que fiz — e é ENDURECIMENTO, não afrouxamento.** Onde havia UMA asserção agora há TRÊS:

1. a exceção, de **UM arquivo e UM rótulo**, com o veto escrito no código;
2. **os outros 7 rótulos internos continuam PROIBIDOS em `AbasDaPagina.jsx`** (sub-teste próprio,
   com controle positivo exigindo que o rótulo da aba ESTEJA lá — senão a exceção perde a razão);
3. **"Aguardando aprovação" continua PROIBIDA nos outros 15 arquivos** de `publicacoes/`, coletando
   infratores e provando que sobraram corpos para varrer.

➡️ **A PERGUNTA:** confirma a leitura de que o D2 é regra de **dado** (o rótulo na linha da lista) e
não de **arquivo** (o rótulo em qualquer `.jsx` de `publicacoes/`)? Se a resposta for "o gate está
certo como estava", então **ABA-01/ABA-02 precisam de uma decisão nova** — a aba não pode existir com
esse rótulo nesse diretório, e o lugar do componente teria de mudar (por exemplo, um diretório
`publicacoes/abas/` ou `aprovacao/`). **Não mudei o lugar por conta própria**, porque mover o
componente de diretório é decisão estrutural.

---

## 9. Divergências medidas, com `arquivo:linha`

| # | o quê | referência do plano/briefing | **MEDIDO** |
|---|---|---|---|
| **N1** | ⚠️ o **docblock de `CabecalhoDePublicacoes.jsx` citava o protótipo ERRADO** (`new_layout_publicador/`, a versão com subtítulo e sem aba). **CORRIGIDO**: as 11 referências de linha foram remedidas na cópia de autoridade | `CabecalhoDePublicacoes.jsx:8`-`:21` | o deslocamento entre as cópias é **+3** de `:39` para baixo, e o conteúdo daquele trecho é **byte a byte igual** |
| **N2** | o bloco da direita (`gap:10px`) | o plano diz `Publicacoes v2.dc.html:37` | **`:39`** — `:37` é o `</div>` de fechamento do trilho |
| **N3** | o `padding-bottom:12px` da FILA | o plano diz `Aprovacao.dc.html:37` | **`:38`**; e `padding-bottom` **não existe em nenhuma linha** das DUAS cópias de `Publicacoes v2.dc.html` (`grep -n` sai vazio) |
| **N4** | as 2 falhas de PHP do briefing | `2373 / 20.473 / 2` | **`2373 / 20478 / 0`** — ver §1.2 |
| **N5** | a baseline de JS do briefing | `4852 / 4846 / 1 / 5` | **`4862 / 4856 / 1 / 5`** |
| **N6** | "os tokens que saíram do skip" | o briefing espera que esta wave acorde os 5 | **nenhum**: os 5 são do E1 (waves 4+). O que nasceu no CSS foram `mb-[-1px]` e `border-b-[2px]` — ver §1.3 |
| **N7** | `MeusAnuncios.jsx` = **1 hunk** | tabela de território do plano | **3 hunks**, estruturalmente obrigatórios (import, prop na assinatura, montagem) e **1 remoção** — a própria linha reescrita. O gate conta **remoções**, não hunks: *"exatamente 1 hunk"* seria um gate que reprova pelo próprio conserto |
| **N8** | `--autoteste` com 14 casos | o plano herdou o número do 187-01 | **16** (o briefing acertou) |
| **N9** | `empresaId` pode ser nulo | o plano prescreve `route(…)` direto | a página guarda com a MESMA forma que o `router.get` da busca usa (`MeusAnuncios.jsx:446`); sem destino a aba deixa de ser link e **não** ganha `href="#"`. A sonda do gate foi afrouxada de `hrefAprovacao={route(` **colado** para `hrefAprovacao={` + a chamada de rota: exigir a forma colada **proibiria a guarda certa** |

---

## 10. Desvios do plano (Regras 1-3)

### `[Regra 3 - Bloqueio]` O gate D2 da Fase 186 reprovava o deliverable obrigatório
Ver §8. **Devolvido como pergunta na SUMMARY e no hand-off**, com a cirurgia feita de forma que
ENDURECE o gate. Não houve escolha de "esconder a aba" nem de "mudar o rótulo".

### `[Regra 3 - Bloqueio]` Duas contagens de `publicador-vocabulario-novo.test.js` mediam o DIRETÓRIO
As asserções de **15** e **16** quebravam só por existir um arquivo novo da Fase 187 em
`publicacoes/`. Reapontadas para a lista NOMEADA `DA_FASE_180` — ver §6.

### `[Regra 2 - Correção]` O docblock do cabeçalho citava a cópia errada do protótipo
Ver N1. Deixá-lo apontando para `new_layout_publicador/` faria o próximo executor medir fidelidade
contra a versão que **tem subtítulo e não tem aba**.

### `[Regra 2 - Correção]` `aria-current="page"` na aba ativa
Acrescentado: o trilho é navegação, e a aba ativa precisa se anunciar como a página atual. Não está
no protótipo (que é HTML de maquete), e não altera nenhuma classe.

### `[Regra 1 - Bug evitado]` A asserção `!html.includes('gap-[6px]')` NÃO foi escrita
Ela seria **falsa por construção**: a `<span>` do "Sincronizado X" tem `gap-[6px]` de propósito
(`:40` do protótipo de autoridade) e continua tendo. O que prova a troca é a igualdade de CONJUNTO
no elemento certo.

### `[Regra 1 - Bug evitado]` O fixture de `href` do gate não leva aspas
`renderToStaticMarkup` escapa `"` como `&quot;` dentro de atributo, e a 1ª versão do gate comparava
a string CRUA contra a ESCAPADA — reprovando o código certo.

---

## 11. O que este plano NÃO fez, de propósito

- **Nenhuma página da fila.** A rota `mlb.anuncios.meus.aprovacao` responde (187-02) e a **página é
  da wave 4**. ⚠️ A janela em que um GET sem `X-Inertia` nessa rota erra no manifest do Vite
  **continua aberta, e é aceita**: a página importa `AbasDaPagina`, então inverter as waves
  quebraria. **Não deployar entre esta wave e a seguinte.**
- **Nada escondido, nada desabilitado, nenhum `href="#"`.** A aba aponta para a rota real desde já.
- **Nenhum token novo, nenhum tamanho novo, nenhuma animação.** `14`, `12` e `11.5` já estavam em
  `TAMANHOS_PERMITIDOS`; as cores vêm de `pub-accent`, `pub-page`, `pub-txt-3`, `pub-txt-4` e
  `pub-line-subtle`, todos pré-existentes.
- **Nenhum PHP, nenhuma migration, nenhum `artisan migrate`.**
- **Nenhum deploy, nenhum push, nenhum toque no VPS, nenhuma chamada ao ML.**
- **O `BotaoAtualizarDoCabecalho` continua órfão declarado** (decisão da 180-10) — não é escopo.
- **Nenhum `state.advance-plan`, nenhum toque em `ROADMAP.md`/`REQUIREMENTS-v27.md`/`STATE.md`.**

⚠️ **Eu NÃO vi tela renderizada.** O banco local não tem empresa com `ml_token` e
`ml_acervo_itens` está vazia: a rota de Publicações devolve **404** nesta máquina. Tudo aqui é
`renderToStaticMarkup` + CSS construído — **estrutura e literal, nunca aparência**. A conferência
visual é do usuário, em produção, na conta 459.

---

## 12. Commits

| hash | assunto |
|---|---|
| `2f40c771` | `test(187-03)`: os gates das duas abas da secao A1 — RED |
| `b060d89a` | `feat(187-03)`: AbasDaPagina — as duas abas da secao A1, com a geometria literal (GREEN) |
| `00b28a05` | `test(187-03)`: AbasDaPagina declarada em ARQUIVOS_NOVOS, e dois reapontes com veto |
| `127e0f41` | `test(187-03)`: a cirurgia no gate do cabecalho — 6 reapontes com veto (RED) |
| `e64069f0` | `feat(187-03)`: o cabecalho perde o subtitulo e ganha a borda e o slot das abas (GREEN) |
| `3fe9655d` | `feat(187-03)`: a montagem das abas na pagina, o manifesto dos gates e a bijecao |

Partida: **`be122baf`**. Todos por **pathspec**, com `-m`/`-F` antes do `--`. Nenhum `git add -A`,
nenhum `stash`, nenhum `reset`, nenhum `checkout`, nenhum `clean`, nenhum `amend`.

⚠️ **Não houve WIP alheio em nenhum dos meus arquivos** (`git diff HEAD -- <arquivo>` vazio antes de
cada edição), então o procedimento de reconstrução por âncora de texto do 187-02 **não foi
necessário**. A árvore tem arquivos `??` de outra sessão, e nenhum deles foi tocado.

## Gate de TDD

| gate | commit |
|---|---|
| RED (tarefa 1) | `2f40c771` — `caminho inexistente: .../AbasDaPagina.jsx — gate mediria NADA` |
| GREEN (tarefa 1) | `b060d89a` |
| RED (tarefa 2) | `127e0f41` — 8 casos reprovando pelos motivos certos |
| GREEN (tarefa 2) | `e64069f0` |
| GREEN (tarefa 3) | `3fe9655d` |

⚠️ A tarefa 3 não tem commit RED separado: os gates dela nasceram no `2f40c771` e **já estavam
vermelhos** desde então (seções 7 e 9 do arquivo de gate). Quebrá-los num commit só para ter um RED
próprio seria cerimônia, não prova.

## Self-Check: PASSED

- 8/8 arquivos declarados existem no disco (`test -f` em cada um)
- 6/6 commits existem em `git log --all`
- `AbasDaPagina.jsx` com **206 linhas** (o `min_lines` do plano era 60)
- 3/3 gates do manifesto PASSAM pelo harness; `--autoteste` 16/0
- suite JS **acima** da baseline, com a falha única sendo a pré-existente da Fase 87
- `sha256` dos 3 arquivos de produção igual a `git show HEAD:` depois da campanha de mutação
