---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 13
subsystem: Publicador — portão de aprovação (fechamento da fase)
tags: [fechamento, gates, mutacao, territorio, fid-01, baselines, checkpoint-humano]
requires:
  - 188-01..188-12 (as 12 SUMMARYs, os 12 manifestos, a 188-REGUA, a 188-FID01, a 188-BASELINES, a 188-COBERTURA)
  - 187-FECHAMENTO.md (o modelo de forma e rigor)
provides:
  - "188-FECHAMENTO.md — os 20 critérios em FORMA FIXA, com placar e dono em cada ressalva"
  - "deferred-items.md — 12 dívidas, todas com DONO"
  - "FechamentoDaFase188Test — a FORMA do documento, o anti-fantasma e a TERCEIRA bijeção sobre os 13 pares"
  - "contagens-188-13.txt — 3 ids de 3 métodos, zero órfão"
  - "learnings §4d — os quatro achados novos da fase"
  - "ROADMAP: Coverage Map da Fase 188, bloco ADITIVO"
affects:
  - "Fase 189 (gate de entrada: exige os 13 SUMMARYs e o 188-FECHAMENTO.md)"
  - "Fases 190/191, 192, 193, 194 (avisos na §13)"
tech-stack:
  added: []
  patterns:
    - "forma fixa lida por gate (CRIT-NN, ALVO-INTOCADO, FID-01-PLANO-NN, PERGUNTA-*, PLACAR-188)"
    - "território por ESCOPO DO ASSUNTO, duas passadas, isca nas três direções"
    - "lista CONSTANTE em vez de piso (piso NÃO prova presença)"
    - "recorte de seção com [^\\n]* e [\\s\\S]*?, nunca `.` com /s"
key-files:
  created:
    - .planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-FECHAMENTO.md
    - .planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/deferred-items.md
    - tests/Feature/Publicador/Aprovacao/FechamentoDaFase188Test.php
    - tests/gates/contagens-188-13.txt
  modified:
    - .planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md
    - .planning/ROADMAP.md
decisions:
  - "PERGUNTA-188-E: MANTER o conserto do cliente; `recusaDoPainel` tem 18 chamadas, não nove"
  - "PERGUNTA-188-F: registrar o fallback da fixture do `casca-do-painel`, não consertar agora"
  - "O Coverage Map sai como 'Entregue no CÓDIGO (20/10/2026) — NÃO DEPLOYADO', forma diferente da das 186/187"
metrics:
  duration: "~1 turno"
  completed: 2026-10-20
---

# Fase 188 Plano 13: O fechamento medido — Summary

Os **20 critérios** (contados por mim, não presumidos) em **FORMA FIXA** com placar **18 OK / 2
RESSALVA / 0 REPROVA**, as **6 baselines RE-MEDIDAS** no commit dos gates com a procedência de cada
número, o território por **escopo do assunto** em duas passadas, a **FID-01 consolidada** das §J a
§R com a autoridade declarada quando o documento se contradiz, **130 mutações** na fase com cada
sobrevivente investigada, as **53** ids em 13 manifestos com **zero órfão** nas três bijeções, e as
**15 perguntas todas com dono** — mais o roteiro de **16 itens** da conta 459, que **nada neste
documento afirma ter sido conferido**.

## PARTIDA e commits

```
PARTIDA (primeira acao da sessao):  cc98a86d   (= o esperado pelo briefing)
e7ad966b  test(188-13)  os gates do fechamento            <- as suites rodaram NESTE HEAD
90dfa946  docs(188-13)  o fechamento medido + deferred + learnings §4d
c9c3753d  docs(188-13)  a campanha de mutacao + o Coverage Map ADITIVO
```

⚠️ **Os gates foram commitados ANTES de medir** (`e7ad966b`), para que `COMMIT:` e as duas contagens
de suíte sejam do **mesmo HEAD**. Os dois commits posteriores são **somente documento** e **não podem
mover as contagens** — provado: os três ids continuam reportando **3/24, 3/26, 3/20** depois deles.

## As 6 linhas RE-MEDIDAS (commit `e7ad966b`)

```
BUILD:     exit 0 em 43 s
MANIFEST:  495 entradas / 202 paginas / isca ausente / controle presente
JS:        5848 / 5847 pass / 1 fail / 0 skipped
PHP:       2577 / 23666 / 2
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
RECORTE:   tests/Feature/Publicador/Aprovacao -> 328 / 5686 / 0   (wave 12: 319 / 5616 / 0)
```

⚠️ **O delta do recorte é `+9 testes / +70 asserções` — EXATAMENTE os 9 métodos deste plano
(24+26+20), e nada mais.**

### ⚠️⚠️ DOIS ACHADOS QUE CONTRADIZEM A EXPECTATIVA ÓBVIA

**1. Os 19 `.jsx` desta fase produziram ZERO entradas novas no manifest.** Eu esperava `+19`.
**Medido: 495 → 495.** O `laravel-vite-plugin` declara como *inputs* só `app.jsx` e `app.css`; as
páginas aparecem porque são importadas dinamicamente; e **componente aninhado é FUNDIDO no chunk da
página**, sem chave própria. ➡️ ⚠️ ***"o componente novo está no manifest" é um invariante FALSO neste
projeto.*** O que o manifest prova é que **a PÁGINA existe e compila** — por isso o controle positivo
é a `Aprovacao.jsx`.

**2. O hash do `app` NÃO saiu com hífen nesta rodada** (`assets/app-CgmGEINw.js`), embora na abertura
da fase tenha saído (`assets/app--5yuaubs.js`). ⚠️ **Isso FORTALECE a regra de ler pelo JSON em vez
de enfraquecê-la:** o hífen é **do sorteio do build**, então um `grep` que funcione hoje **quebra
amanhã sem ninguém mexer em nada**.

## As falhas — nomeadas por SEMÂNTICA, atribuídas por ISOLAMENTO, não corrigidas

| # | o teste | arquivo | fase dona | atribuição |
|---|---|---|---|---|
| 1 | *publicacoes criadas mede as duas janelas e a serie de trinta dias* | `tests/Feature/Publicador/CockpitDaContaTest.php` (`:966`) | **179**, em produção | **falha SOZINHA** (`2 failed, 7 assertions`) |
| 2 | *evento sem data vai para o ultimo grupo nunca para o topo* | idem (`:1318`) | **179**, em produção | idem |
| 3 | *Características secundárias nasce recolhido* | `tests/js/estrutura-grade-glide.test.js:122` | **87** | **19 / 18 / 1** em execução isolada |

⚠️⚠️ **As duas do PHP voltaram a vermelho entre a abertura (19/10, `0` falhas) e o fechamento
(20/10, `2`) — E ISSO CONFIRMA O DIAGNÓSTICO: o dia virou.** Elas são **fronteira de dia**, e já
foram vermelhas 16/10, verdes 17/10, vermelhas 18/10, verdes 19/10.
⚠️ **As outras duas conhecidas (do Portal, de ORDEM/CORRIDA) não apareceram nesta rodada.**
⚠️ **Nenhum OUTRO teste falhou.** ⚠️ **`REF_PHP_FALHAS` NÃO foi criado** — o alvo continua `0`, e o
`2` é **declarado**, não aceito como limiar.
⚠️⚠️ **E o caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE** —
conferido com `test -e`. É o nono dos dez fantasmas da milestone, e **veio de briefing do
orquestrador**.

## Os 20 critérios — CONTADOS, não presumidos

```
awk '/^### Phase 188:/,/^### Phase 189:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "   -> 20
numeros: 1..20, sem buraco, sem repeticao
ISCA do awk: a Fase 187 devolve 15 e a 186 devolve 17 (as entradas sao VIZINHAS: 4376, 4485, 4594)
```

**Minha medição bate com a do planejador: 20.**

**PLACAR-188: OK=18 RESSALVA=2 REPROVA=0**

| veredicto | quais | dono |
|---|---|---|
| **OK** (18) | 01, 03, 04, 05, 06, 07, 08, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 | — |
| **RESSALVA** (2) | **02** e **09** | **Fase 190/191** nas duas, por **DECISÃO DO USUÁRIO** |
| **REPROVA** (0) | — | — |

⚠️ **As duas ressalvas são a MESMA fronteira** (a tela de revisão detalhada), e nenhuma é trabalho
faltando dentro desta fase: *"desenhar desabilitado com explicação"* foi **oferecido e RECUSADO**,
porque **botão sem destino é D4 violado**.

⚠️⚠️ **E eu procurei o caso do critério 7 da Fase 186** (aquele que **teve** de ser reescrito porque
**contradizia o 6** e era **aritmeticamente impossível**): **nada dessa natureza existe nos 20 da
188.** Se existisse, estaria escrito e **devolvido ao usuário**, não consertado por conta própria.

## Território — por ESCOPO DO ASSUNTO, e o range CRU medido

```
ALVOS = 53   FANTASMAS = 0   COMMITS_DO_ASSUNTO = 64
PASSADA1_VIOLACOES = 0    (laco com `git show --name-only` por commit)
PASSADA2_VIOLACOES = 0    (uma passada de `git log --name-only` + awk)
ISCA A (alvo que a fase TOCOU no veto)      -> 6 commits        a varredura VE
ISCA B (o veto contra os commits ALHEIOS)   -> 161 acertos      o veto CASA
ISCA C (test -e num caminho inventado)      -> ausente          o anti-fantasma VE
T-188-SC por DUAS vias: FORMA (so App\, Illuminate\, react, react-dom, @inertiajs/react, @/ e
          relativos) + numstat VAZIO nos 4 arquivos de pacote
```

### ⚠️ DOIS ACHADOS DE TERRITÓRIO, e os dois CORRIGEM o briefing

**1. O RANGE CRU, nesta fase, NÃO acusa:** 64 commits no range, **5 alheios** (`coordenacao`×3,
`189`×1, `186-01`×1) e **ZERO alvos acusados**. ⚠️ O briefing dizia *"2 a 3 alheios por wave"*.
➡️ ⚠️ **Mas o range CONTINUA não servindo, e a razão é de construção, não de resultado:** ele sai
limpo **por sorte do que a sessão paralela escolheu tocar nestes dois dias**. Na Fase 187 o mesmo
gate teria **12 vermelhos**. **Por isso o gate perene deste plano não usa git nenhum.**

**2. O ESCOPO DO ASSUNTO tem um LIMITE medido, e eu registro em vez de esconder:** o commit
`091340b6` **é trabalho desta fase** (o reaponte dos dois gates de total fixo do `186-01`) e carrega,
**de propósito**, o escopo `(186-01)`, porque edita um gate daquela fase. ➡️ **Ele NÃO é capturado
pelo filtro `(188…)`.** *"Escopo do assunto"* é **melhor** que o range, **não perfeito**.

### A contagem FIXA de arquivos, medida UMA vez

**82** arquivos novos nos 64 commits: **12** `.php` (3 de produção + 9 de teste) · **19** `.jsx`
(todos de produção, todos em `aprovacao/`) · **29** `.md` · **12** `.txt` · **10** `.js`.
⚠️ **O invariante dos `.js` é *"nenhuma PEÇA nova sob `resources/` é `.js`"***, e **não** *"nenhum
arquivo novo é `.js`"* — que é **literalmente FALSO**: há **10**, e **todos são testes**.
⚠️ `.jsx` em `aprovacao/`: **40** = 21 (186/187) + 19 (188).

## A FID-01 consolidada — e a AUTORIDADE quando o documento se contradiz

**13 linhas: 12 por SCRIPT, 1 SEM-PROTOTIPO (o próprio fechamento), ZERO por LEITURA.**

⚠️ **O que eu RE-MEDI:** o protótipo (`find` + `test -e` + `wc -l` + `sha256sum` + contagem por
registros: **1432**, `68624883ce35547e`, **não se moveu**), as duas cópias do pacote de handoff, e as
**seções e ordinais** do `188-FID01.md`. ⚠️ **Os ponteiros individuais das §J a §R eu NÃO re-medi um
por um** — registro **com a fonte declarada** (a wave que os mediu, por `sed`/script), em vez de
afirmá-los como medição minha.

### ⚠️⚠️ ACHADO NOVO: a `188-FID01.md` tem DUAS seções `§P`, as duas dizendo "SÉTIMA VEZ"

A sequência real é **§K(03) · §L(04) · §M(05) · §N(08) · §O(09) · §P(06) · §P(10) · §Q(11) ·
§R(12)** — **NOVE** confirmações da lição da §J, com **duas** compartilhando letra e ordinal.
⚠️ **Registrado, NÃO consertado:** aquele arquivo não está nos meus `files`, e mexer nele mudaria a
contagem do gate `fid01-remedida` (`1/13`). **Dono: Fase 189**; a próxima letra livre é **`§S`**.

### A autoridade, medida três vezes

| contradição | quem estava certo | por quê |
|---|---|---|
| o `<interfaces>` do `188-08-PLAN.md` × a **§C** | **a §C** | ela mediu por script |
| a lista de **CONFIRMADOS da §L** × a **§O** | **a §O** | havia ponteiro errado **DENTRO** dos "confirmados" |
| a **§E** × a **§L** e a **§O** (as três citam `:1143`) | **§L e §O** | a §E dizia os 4 pares de cor, que estão em `:1307` — **desvio de +164** |

➡️ **A regra: quando duas seções citam a MESMA linha para assuntos DIFERENTES, uma está errada, e o
único juiz é o `sed`.** ⚠️ **E a notação de dois-pontos é EXCLUSIVA do protótipo** — linha de outro
arquivo vai como `L<N>` (o `fid01-remedida` acusou **nove** ponteiros do Inertia, e a redação que
EXPLICAVA o achado reprovou **de novo**).

### As QUATRO derrotas do protótipo, registradas JUNTAS — placar ~146 a 4

Todas por **conflito entre regras do projeto resolvido PELO USUÁRIO**, **nenhuma por erro dele**:
`' d'` × `'3 dias'` (aqui o protótipo **ganhou**; perdeu a prosa do plano) · o toast do lote mantendo
o **vocabulário travado** contra `:1167` · o cabeçalho **"VERIFICAÇÃO AUTOMÁTICA · APOIO"** uma vez
**+ o ordinal** (o literal em maiúsculas só existe em `:369`, a revisão detalhada; o painel escreve
minúsculo em `:633`) · o aviso de foto como **alerta FIXO, sem sétimo toast** (*"as duas coisas"* foi
**oferecido e RECUSADO**: poria o mesmo número em duas fontes).

⚠️ **E duas divergências que NÃO são derrota:** o rodapé (o protótipo tem **as duas formas** — `:1238`
é a do produto — e o **critério 10 desempatou**) e o `animate-pub-spin` (`.8s` × `.7s`: a wave usa a
**classe** e põe a duração **por `style`**, porque `duration-[Nms]` **não compila** neste projeto).

## A mutação consolidada — 130 na fase, 0 sobreviventes ao fim

| plano | mut | mortas | sobrev. |
|---|---|---|---|
| 01 | 8 | 7 | 1 (controle por construção; **mas a investigação achou defeito real na MENSAGEM**) |
| 02 | 10 | 9 | 1 (**GAP REAL** — a decisão do usuário estava protegida só pela ausência de 2 arms) |
| 03 | 9 | 8 | 1 (**INOBSERVÁVEL** por construção; premissa **TRAVADA**) |
| 04 | 10 | 10 | 0 (1 na 1ª: era **o SCRIPT**, NO-OP) |
| 05 | 7 | 7 | 0 (1 aborto legítimo: âncora em 2 lugares) |
| 06 | 8 | 8 | 0 |
| 07 | 20 | 20 | 0 |
| 08 | 12 | 12 | 0 |
| 09 | 7 | 7 | 0 (1 na 1ª redação: a **FIXTURE** não discriminava) |
| 10 | 8 | 8 | 0 (1 na 1ª: a **TAUTOLOGIA DE LITERAL**) |
| 11 | 10 | 10 | 0 |
| 12 | 12 | 12 | 0 |
| **13** | **9** | **9** | **0** |
| **TOTAL** | **130** | **130** | **0 ao fim** · 5 eventos, **4 investigados até a causa** |

### A campanha DESTE plano: 9 de 9 mortas, em duas rodadas

Rodada 1: **7 mortas, 2 ABORTOS da trava**. Rodada 2 (âncoras desambiguadas): **as 2 morrem**.

⚠️⚠️ **Os dois abortos são o comportamento CERTO — e o da M5 é um ACHADO:** a âncora
`FID-01-PLANO-09: SCRIPT` casava **DUAS** vezes — a linha de forma fixa **e a linha da TABELA DESTA
PRÓPRIA CAMPANHA, que a descreve**. ➡️ **É a família *"o texto que EXPLICA casa com o padrão que ele
explica"* acontecendo DENTRO da campanha de mutação.** A M3 abortou com **0 ocorrências** (erro meu
de transcrição) — e **âncora que não casa mutaria NADA, e o `SOBREVIVEU` não valeria nada**.
⚠️ **A trava de ocorrência (`== 1`) ficou INTACTA.**

⚠️ **E as M6 e M7 são as duas que a Fase 187 mediu como SOBREVIVENTES** — elas morrem aqui porque o
gate nasceu com o conserto delas embutido (**lista CONSTANTE** de perguntas; **roteiro numerado sem
buraco** em vez de menção).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug na minha própria sonda] "conta 459" vivia só no CABEÇALHO da §11**
- **Found during:** tarefa 2, ao medir o gate `fechamento-criterios`
- **Issue:** o recorte de seção devolve o **CORPO** (sem a linha do cabeçalho), e a frase *"conta
  459"* estava **só no título**. O gate reprovou — **corretamente**.
- **Fix:** a frase entrou no **corpo** da §11, que é onde o roteiro de fato vive. ⚠️ **Eu NÃO
  afrouxei o gate para ele casar o cabeçalho** — o invariante é *"o roteiro nomeia a conta"*, e
  roteiro é o corpo.
- **Commit:** `90dfa946`

**2. [Rule 3 - Blocker de ferramenta] o heredoc colapsou `\\` em `\` e quebrou o script de homônimo**
- **Found during:** tarefa 1
- **Issue:** `php -l` acusou `unexpected token "%"` na linha 17 de um script que estava sintaticamente
  correto — porque o `'\\'` da linha 12 virou `'\'` e desalinhou o parser. ⚠️ **É exatamente a
  armadilha escrita na `188-REGUA.md` §6**, e eu caí nela.
- **Fix:** o script foi reescrito com a ferramenta `Write`. ➡️ **Regex em ARQUIVO escrito por
  ferramenta**, nunca por heredoc, nunca por `node -e`.
- **Commit:** (ferramenta de medição, no scratchpad — não versionada)

**3. [Rule 1 - Bug no meu mutador] `exec()` do PHP PERDE o `\n` final e a guarda de PARTIDA abortou**
- **Found during:** tarefa 2, 1ª execução da campanha
- **Issue:** o `sha256` do disco nunca batia com o de `git show HEAD:` num arquivo que `git status` e
  `git diff --numstat` reportavam **limpo** — porque `exec()` devolve **linhas num array** e o
  `implode("\n")` **perde o `\n` final**.
- **Fix:** normalização **SIMÉTRICA** nos dois lados (`\r` fora **e** `rtrim` do `\n`), com
  `shell_exec`. ⚠️ **Afrouxar a guarda teria rodado a campanha inteira sem saber se o disco batia com
  o commit** — é a terceira vez nesta milestone que o defeito está no **mutador**, não na asserção.
- **Commit:** `c9c3753d` (registrado no documento)

### Divergências DECLARADAS, não consertadas

**1. ⚠️⚠️ CONTRADIÇÃO ENTRE O PLANO E O BRIEFING, sobre o `ROADMAP.md`.** O `<verification>` item 5
do `188-13-PLAN.md` exige `git diff --numstat` **VAZIO** em `.planning/ROADMAP.md` (GATE-07, *"não
pertence a plano nenhum"*), e o briefing do orquestrador **manda escrever** o Coverage Map e a linha
`**Plans**:`, com a forma exata. ➡️ **Segui a instrução do orquestrador e DECLAREI o conflito**, no
documento (§12) e aqui. **Medido: `+73 / −1`, e a única remoção é o placeholder `**Plans**: TBD`.**
⚠️ **Nenhum gate deste plano lê o `ROADMAP.md`**, então a edição não pode "fazer passar" nada.

**2. ⚠️ CORREÇÃO AO BRIEFING: a lista de commits dos DEZ gates de total fixo omitia `61e84710`.** O
briefing nomeia **nove** gates (5 da 187 + 2 da 186 + 1 da 188 + 1 da 180) e afirma **DEZ**. ➡️ **São
DEZ, e o número estava certo; a lista, não.** O décimo é `61e84710` (wave **188-01**, a contagem fixa
de `publicador-fila-tokens-187.test.js:415`), e os ordinais dos próprios commits confirmam:
`e69f7584` diz **"SEXTO"**, `091340b6` traz **dois**, e `a874fc00` diz **"NONO"**.

**3. ⚠️ CORREÇÃO AO BRIEFING: o range cru desta fase não acusa nada.** O briefing dizia *"2 a 3
alheios por wave"*; medido no range da fase inteira: **5 alheios e 0 alvos acusados**.

**4. A `188-FID01.md` tem DUAS seções `§P`** — registrado, não consertado (dono: Fase 189).

### Pergunta devolvida: nenhuma nova ao usuário

As **188-E** e **188-F** chegaram **decididas pelo orquestrador**, e eu as **registrei com a razão e
com o que foi recusado** — porque *"decidida sem a razão e sem o recusado"* é **pior que aberta**.
⚠️ **Eu NÃO as reabri.**

## Known Stubs

Nenhum. Este plano não escreve código de produção — só documento, gate e manifesto.

## Threat Flags

Nenhuma. Nenhum endpoint, rota, caminho de auth, acesso a arquivo ou mudança de schema foi
introduzido. Todas as asserções do gate são **leitura de arquivo**; **nenhuma toca o banco**.

## O que nenhum executor pode afirmar, e eu não afirmo

- ⚠️⚠️ **NINGUÉM VIU TELA RENDERIZADA.** Todo render da fase é `esbuild` + `react-dom/server`.
- ⚠️ **Nenhum número veio de produção.** O estado das Fases 186/187 em produção está registrado **com
  a fonte declarada** (o orquestrador), não como medição minha.
- ⚠️ **O `mysqld` local não está em execução** nesta sessão — os fatos sobre o banco local vêm das
  waves 01-04, **com a fonte declarada**.
- ⚠️ **A fase NÃO foi deployada**, e o roteiro da conta 459 **só é executável depois de um deploy que
  o usuário autorize**.

## O que este plano NÃO fez, de propósito

Não deployou · não deu `push` · não tocou no VPS · não rodou `artisan migrate` (**esta fase não tem
migration**) · não rodou `pint` · não criou worktree · não rodou `state.advance-plan` · não tocou em
`STATE.md` nem em `REQUIREMENTS-v27.md` (`numstat` **VAZIO** nos dois) · não alterou nenhum serviço
ou `.jsx` das Fases 186/187/188 · não alterou os gates recém-consertados · não chamou a API real do
Mercado Livre · **não rodou `git stash` em forma nenhuma** (os **27** desta árvore foram **apenas
LISTADOS**) · e **não usou `git checkout`, `git reset`, `git clean`, `git add -A` nem `--amend`**.

## ONDE ESTE PLANO PAROU

**Tarefas 1 e 2 COMPLETAS e commitadas.** A **tarefa 3 é o checkpoint humano BLOQUEANTE**: a
conferência visual da conta 459, que depende de **(a)** um deploy que o usuário autorize e **(b)** uma
oferta na fila, que é da **Fase 194**.

## Self-Check: PASSED

```
FOUND: 188-FECHAMENTO.md            (1382 linhas; min_lines do plano: 400)
FOUND: deferred-items.md            (205 linhas)
FOUND: FechamentoDaFase188Test.php  (809 linhas; min_lines do plano: 250)
FOUND: contagens-188-13.txt         (63 linhas, 3 ids)
FOUND: learnings §4d                (706 linhas no arquivo)
FOUND: ROADMAP.md                   (+73/-1, bloco ADITIVO)
FOUND: e7ad966b   FOUND: 90dfa946   FOUND: c9c3753d
GATES: fechamento-territorio 3/24 · fechamento-criterios 3/26 · fechamento-manifestos 3/20  PASSOU
--autoteste: 20 casos, 0 divergencia
HOMONIMO: ARQUIVOS=909 METODOS=7830 HOMONIMOS=0 PREFIXOS=0 (DECLARADOS_EXPANDIDOS=75, SEM_ID=0)
BIJECAO id<->verify: 13 pares, 0 orfaos, 0 nao-declarados
numstat dos meus arquivos depois do commit: VAZIO
GATE-07: STATE.md e REQUIREMENTS-v27.md com numstat VAZIO
```
