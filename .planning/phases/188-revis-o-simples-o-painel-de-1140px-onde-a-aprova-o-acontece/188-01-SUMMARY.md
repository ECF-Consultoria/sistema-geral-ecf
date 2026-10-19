---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 01
subsystem: publicador-aprovacao
tags: [baselines, gates, fidelidade, tokens, tamanhos, fid-01, mutacao]
requires: []
provides:
  - "188-BASELINES.md — as 4 baselines medidas no HEAD de partida, que os outros 12 planos LEEM"
  - "`21` liberado em TAMANHOS_PERMITIDOS — o único tamanho novo da fase"
  - "188-FID01.md re-medido por script, com 33 divergências classificadas e a PERGUNTA-188-A aplicada"
  - "tests/gates/contagens-188-01.txt — 4 ids, bijeção provada nos três sentidos"
affects:
  - "tests/js/publicador-fila-tokens-187.test.js — contagem fixa trocada por lista CONSTANTE (declarado)"
tech-stack:
  added: []
  patterns:
    - "gate de FORMA sobre documento, com isca invertida em MEMÓRIA (nenhum arquivo do repo mutado)"
    - "território por ESCOPO DO ASSUNTO, nunca por range"
    - "degradação por razão NOMEADA em lista, nunca markTestSkipped no modo php do harness"
key-files:
  created:
    - .planning/phases/188-.../188-BASELINES.md
    - tests/js/publicador-painel-tokens-188.test.js
    - tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines188Test.php
    - tests/gates/contagens-188-01.txt
  modified:
    - .planning/phases/188-.../188-FID01.md
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-tokens-187.test.js
decisions:
  - "A contagem fixa `23` do gate TAM-187 foi trocada por lista CONSTANTE `LIBERADOS_DEPOIS_DA_187`, e NÃO elevada para 24"
  - "O invariante do bloco 4 é 'ESTE PLANO não adicionou token', por escopo do assunto — nunca 'a camada ficou idêntica à PARTIDA'"
  - "O escape da §I é um CABEÇALHO estrutural, não uma frase, porque a prosa que explica a regra citava a frase"
metrics:
  duration: "~3 h"
  completed: 2026-10-19
---

# Phase 188 Plan 01: as baselines, o tamanho 21 e a FID-01 re-medida — Summary

Mediu as quatro baselines da fase **antes de editar um byte**, liberou **`21`** (o único tamanho
novo, confirmado por script) consertando **na raiz** a contagem fixa da 187 que o bloqueava, e
re-mediu a tabela FID-01 **por script**, achando **33 divergências** e corrigindo um ponteiro que a
prosa herdada tinha errado.

---

## PARTIDA

```
PARTIDA: db9b1ff9   (db9b1ff9ad9f5c270fd0e2f91a245da82f9329d4)
```

**Capturada como a primeira ação da sessão**, por `git rev-parse --short HEAD`, antes de qualquer
leitura de plano e antes de qualquer edição.
⚠️ **Ela NÃO é extremo de range.** Nenhum gate deste plano a usa assim — a 187-11 mediu que range
não passa nesta árvore (111 commits, **43 da sessão paralela**, diff cru acusando **12 dos 60**
alvos vetados). Ela é **referência de LEITURA**, e foi usada exatamente para isso: a passada B do
bloco 4 lê `git show db9b1ff9:tailwind.config.js` para provar que os 6 tokens já existiam **com o
mesmo valor**. `git checkout` não foi usado em momento nenhum.

---

## §1 — AS 4 BASELINES, MEDIDAS (registro completo em `188-BASELINES.md`)

```
COMMIT:    db9b1ff9          ← as 6 medições rodaram NESTE HEAD, antes da 1ª edição
BUILD:     exit 0 · 79 s
MANIFEST:  495 entradas / 202 paginas / isca ausente / controle presente
JS:        5489 / 5488 pass / 1 fail / 0 skipped
PHP:       2493 / 21915 / 0
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

**Comparação com o fim da 187 (`6ba51bd6`):** MANIFEST **igual** (495/202) · JS **igual** nos quatro
números · PHP testes **igual** (2493) · PROTÓTIPO **igual** · asserções PHP **21.915 contra 21.920
(−5)** · falhas PHP **0 contra 2 (melhor)**.
⚠️ **Registrado, não ajustado.**

### ⚠️ A divergência de −5 asserções: descartada por MEDIÇÃO, não por suposição

```
git log --oneline 6ba51bd6..db9b1ff9                        → 8 commits, TODOS `docs(…)`
git log --oneline --name-only 6ba51bd6..db9b1ff9 -- tests/   → (vazio)
```

➡️ **ZERO commits tocaram `tests/`.** Então a diferença **não pode** ser teste removido nem asserção
apagada — e a contagem de testes é **idêntica**. A causa que sobra é o **ramo diferente** dos dois
casos de fronteira de dia (§2).
➡️ **Régua para os 12 planos:** compare **TESTES e FALHAS**; **asserções do PHP não são invariante**
nesta suíte. O harness já faz assim — tem `REF_PHP_TESTS` e **não** tem referência de asserções.

---

## §2 — ⚠️⚠️ AS FALHAS ALHEIAS, NOMEADAS — e hoje as duas do PHP estão **VERDES**

| # | arquivo | método (declaração) | asserção de fronteira | dono |
|---|---|---|---|---|
| 1 | `tests/Feature/Publicador/CockpitDaContaTest.php` | `test_publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias` (`:941`) | `:966` — `assertSame(12, $kpi['serie'][29], 'a última posição é HOJE')` | **Fase 179**, em produção |
| 2 | idem | `test_evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo` (`:1308`) | `:1318` — `assertSame(['hoje', now()->subDays(3)->toDateString(), 'sem_data'], …)` | **Fase 179**, em produção |
| 3 | `tests/js/estrutura-grade-glide.test.js:122` | *"Características secundárias nasce recolhido"* | — | **Fase 87** |

**Conferidas individualmente hoje:** as duas do PHP **PASSAM** (`2 passed, 42 assertions`).

⚠️⚠️ **O verde de hoje CONFIRMA o diagnóstico da régua em vez de contradizê-lo.** Vermelhas 16/10,
verdes 17/10, vermelhas 18/10, **verdes 19/10** — e §1 prova que **nenhum commit tocou `tests/`**
nesse intervalo. Logo a oscilação **não pode** ser mudança de código: é **fronteira de dia**. O
arquivo consulta o relógio **53** vezes (`grep -cE "now\(\)|today\(\)|Carbon::now|setTestNow"`) e o
último commit dele é literalmente *"ancora o relogio no caso de agrupamento da Atividade"* — alguém
já consertou **um** caso e deixou estes dois.

⚠️ **Nada foi consertado. Nada foi contado como regressão. `REF_PHP_FALHAS` NÃO foi criado.**
⚠️ **O caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE** — o nono
fantasma da milestone, conferido agora com `test -e`. O real é **fora** de `Aprovacao/`.

### A régua, ATUALIZADA pela medição de hoje

A régua herdada dizia *"2 nesta família é o antes"*. **Hoje o antes é `0`.** Para o teto não virar
um limiar que perdoa falha nova, a régua dos 12 planos seguintes é:

1. **Falhas PHP `0`** é o estado medido na abertura — **é o alvo**.
2. Se **uma das duas da tabela** voltar a vermelho, é a **oscilação conhecida** — registre e siga.
3. ⚠️ **Falha em QUALQUER OUTRO teste, ou mais de 2, É DA FASE** — investigue.
4. ⚠️ **Testes PHP abaixo de 2493, ou JS abaixo de 5489, é regressão.**

---

## §3 — O TAMANHO `21`: **CONFIRMADO** como o único ausente, e nada além dele

**Medido por script, antes de liberar nada:**

```
painel (:547-:721):  9.5 10 10.5 11 11.5 12 12.5 13 13.5 14 15 16 20 21
modais (:775-:905):  9.5 10 10.5 11 11.5 12 12.5 13 14 16
na lista hoje (23):  8.5 9.5 10 10.5 11 11.5 12 12.5 13 13.5 14 14.5 15 15.5 16 17 20 22 24 28 30 32 34
FALTAM: 21           → VEREDICTO: CONFIRMADO, só o 21
```

⚠️ **Nenhum outro valor foi liberado.** O gate tem **isca invertida**: `23` e mais cinco vizinhos
não medidos (`18`, `19`, `21.5`, `25`, `26`) **não podem** estar na lista.
**O uso é medido:** os 3 KPIs da coluna de preço (`:642`, `:643`, `:644`), cada um com
`font-size:21px;font-weight:600;letter-spacing:-0.02em;white-space:nowrap;font-variant-numeric:tabular-nums`.

⚠️ **ACHADO: o `white-space:nowrap` estava OMITIDO** na descrição desses KPIs no próprio plano
(`188-01-PLAN.md:194`). É a mesma omissão que a Fase 187 cometeu **sete** vezes, e por isso ela foi
escrita ao lado da liberação, no arquivo do gate.

---

## §4 — ⚠️⚠️ O CONSERTO NA RAIZ EM ARQUIVO DA FASE 187 — o item que mais precisa de revisão

**O problema, medido antes de editar:** `tests/js/publicador-fila-tokens-187.test.js:415` cobrava
`assert.equal(agora.length, 23)` por **contagem FIXA**. Acrescentar o `21` — o trabalho que este
plano existe para fazer — deixava **três asserções vermelhas** naquele gate, e com elas a árvore. E
**árvore vermelha pode barrar deploy**.

**Por que NÃO subi 23 → 24 (o conserto barato):**

| razão | onde está escrito |
|---|---|
| elevar régua repetidamente **treina o executor seguinte a ajustar réguas quando elas incomodam, e um dia ele ajusta uma que importava** | `tests/gates/verificar-testes.sh`, na justificativa da forma `>=`; e `feedback_gate_que_reprova_pelo_proprio_conserto` |
| é a família **"contagem que depende de quantos valores existem"**, com **nove** ocorrências anteriores nesta milestone, **todas consertadas na RAIZ** com lista CONSTANTE | `188-REGUA.md` §6, regra 5 |
| **o próprio autor do caso já sabia que a contagem não carregava o peso:** o comentário do `removidos` diz, desde 15/10, *"é esta asserção, e **não a contagem**, que impede trocar dois valores por outros dois"* | o próprio arquivo, `:425`-`:426` |

**O que entrou no lugar:** `LIBERADOS_DEPOIS_DA_187 = [21]` — lista **CONSTANTE**, com o plano dono
e a medida escritos ao lado — e a **igualdade de CONJUNTO** contra
`ANTES_DA_187 ∪ {9.5, 22} ∪ LIBERADOS_DEPOIS_DA_187`.

⚠️ **É MAIS FORTE que a contagem, não mais fraco:** valor que entre em `TAMANHOS_PERMITIDOS` sem
entrar na lista **continua reprovando** (porta dos fundos fechada), **e** agora o gate **NOMEIA**
cada acréscimo com o dono e a medição, em vez de só contar. A régua deixou de ser **fechada para as
Fases 189-194** e passou a exigir **medição nomeada**.

**O que foi preservado:**

- a contagem de `test()` daquele arquivo **não mudou** (16) — *"CONTE TESTES, não linhas"* —, então
  `tokens-da-fila` (`contagens-187-01.txt`, `tests=16,9`) **segue valendo sem retoque**;
- `ANTES_DA_187.length === 21` (o "antes" congelado), a entrada de `9.5`/`22`, a ausência de
  remoções e a isca invertida `>= 23` — **todas intactas**;
- **conferido verde** pelo harness, antes e depois.

⚠️⚠️ **E a mutação prova que não afrouxou:** tirar o `21` de `LIBERADOS_DEPOIS_DA_187` **mantendo-o**
em `TAMANHOS_PERMITIDOS` faz `tokens-da-fila` **MORRER** (mutação 7). Era essa a acusação que a
cirurgia precisava responder.

⚠️ **ESTE É O ITEM QUE O ORQUESTRADOR/USUÁRIO DEVE REVISAR PRIMEIRO.** É alteração em gate de fase
**em produção** que o plano não previu, feita porque o conserto prescrito pela doutrina do projeto
mora naquele arquivo e não havia como fazer o trabalho sem passar por ele. **Está num commit
próprio (`61e84710`) e o revert é de um commit.** Se a avaliação for outra, a alternativa que eu
**recusei** está nomeada acima.

---

## §5 — A FID-01 RE-MEDIDA: **por SCRIPT**, 100/115 nos ponteiros, **33 divergências**

**Declaro como medi: `SCRIPT`**, em três passadas, cada uma com isca nas duas direções. O
**diretório** é citado: `design_handoff_publicador/Aprovacao.dc.html` — `find . -maxdepth 3 -name
Aprovacao.dc.html` devolve **só** esse (há dois pacotes de handoff na árvore com conteúdo diferente).

| passada | o que fez | achou |
|---|---|---|
| 1 · colunas | `cursor` / `white-space` / `text-overflow` **em colunas próprias**, contra a varredura real por faixa | **3** |
| 2 · ponteiros | score por declarações CSS + busca de offset em `[-3..+3]` | **100 certos · 15 deslocados · 0 score-zero** |
| 3 · omissão | toda linha com `style="` em escopo que o documento **nunca cita** | **11** |

**Placar final: 20 `REFERÊNCIA` · 2 `VALOR` · 11 `OMISSÃO` = 33.**
⚠️ **Confirma a previsão da régua com precisão desconfortável** — *"erra por OMISSÃO e por
REFERÊNCIA, quase nunca por VALOR"*. **Nenhuma divergência de medida:** todos os `font-size`,
`height`, `padding`, `border-radius`, `gap` e hexadecimais conferidos batem.

**Os achados mais caros:**

- ⚠️ **C12/C13/C14 todos deslocados em +1**, e o ponteiro de **C14 (`:608`) apontava para um
  `</span>` PURO**. **A causa raiz:** o wrapper `:604` (`position:absolute;right:10px;bottom:10px`),
  que **ancora os três botões no canto inferior-direito**, estava **AUSENTE** do documento.
- ⚠️ **`:564` (`min-width:0`)** e **`:567`/`:680` (`flex-wrap:wrap`)** omitidos — os três são
  **load-bearing**: sem eles o título **não trunca** e os chips **vazam**.
- **F.3 inteira** deslocada em +2/+3; **F.4 miniatura** em +2; **F.2 caixa vermelha** em +1.
- **C1 `:589`** afirmava `white-space:nowrap` que a linha **não tem** (`VALOR`); **C12 `:605`**
  afirmava `cursor:pointer` num `<span>` **não interativo** (`VALOR`) — este arrastado do botão
  vizinho pelo próprio deslocamento.

### ⚠️⚠️ R20 — e foi o GATE que pegou, não eu

O ponteiro da **1ª derrota do protótipo** estava **ERRADO na prosa herdada**: dizia `:1045`, que é
**`pct(n)`**. A função `wait` (o `' d'`) está em **`:1054`** — medido por
`grep -n 'function wait'`. O `:1167` da 2ª derrota está **CERTO** (`bulkApprove`, com o toast
`' ofertas aprovadas'`).
➡️ **Eu ia propagar o `:1045` sem medir.** O gate `fid01-remedida` reprovou e me obrigou a medir. É a
prova de que *"o executor reconfere CADA ponteiro por `sed`"* não é zelo.

### ⚠️ Dois achados nas MINHAS PRÓPRIAS sondas

1. **A passada 1 era CEGA para omissão:** só comparava as colunas **nas linhas que a tabela já
   mapeia**, então elemento inteiro ausente era **invisível** — "gate sobre zero casos" com outra
   roupa. Foi a **passada 3** que achou os 11, e é a razão de ela existir.
2. **A passada 1 nasceu com QUATRO falsos positivos:** `split('|')` cru **quebra em `\|` escapado** e
   desloca as colunas seguintes. As duas linhas com `\|` do documento (**C26** e **E12**) produziam
   divergência onde não havia. **Consertado na SONDA, não no documento.**

---

## §6 — PERGUNTA-188-A: **APLICADA** (opção (c), híbrido, usuário, 18/10/2026)

**No painel entra o cabeçalho `VERIFICAÇÃO AUTOMÁTICA · APOIO` UMA VEZ, e cada item MANTÉM o número
que o liga ao marcador x/y.** A razão do usuário está escrita nas duas metades: o cabeçalho é o que
diz **APOIO** (sem ele a verificação pode ser lida como **VEREDICTO**, que é o que REVS-10 e a D7
impedem), e o ordinal é **FUNCIONAL** (liga o item ao marcador).

**A terceira derrota do protótipo, com `arquivo:linha` dos dois lados:**

| # | o protótipo escreve | ganhou |
|---|---|---|
| 1 | `' d'` abreviado — `Aprovacao.dc.html` **`:1054`** (`wait`) | `'3 dias'` por extenso, com `min`/`h` abreviados de propósito |
| 2 | *"N ofertas aprovadas"* — **`:1167`** (`bulkApprove`) | o vocabulário travado de `VocabularioDoPortao` |
| 3 | painel **sem** cabeçalho, prefixo inline minúsculo — **`:633`**; o literal em maiúsculas existe **mas em E4** — **`:369`** | **o cabeçalho também no painel**, por REVS-10 + D7 — **e o ordinal fica** |

⚠️ **As opções (a) e (b) ficaram registradas COM a razão de terem sido recusadas** — razão de opção
recusada é o que impede alguém de reabri-la.
⚠️ **A trava provisória CAIU:** o `188-03` entrega o dado **COM** o ordinal e o `188-08` **CRAVA** o
cabeçalho. **Nenhum dos dois para mais na tarefa 2.**
⚠️ **Não reproduzir o `Verificação automática2 · ` colado de `:633`** — o ordinal vira **o número do
marcador**.

**A medição, RE-CONFIRMADA por script:**

```
grep -n 'VERIFICAÇÃO AUTOMÁTICA' …/Aprovacao.dc.html   → 369   (UMA linha, dentro de E4)
grep -n 'Verificação automática' …/Aprovacao.dc.html    → 633   (UMA linha)
```

**Nenhuma das duas divergiu.**

---

## §7 — O MANIFESTO, A BIJEÇÃO E O HOMÔNIMO

**`tests/gates/contagens-188-01.txt` — 4 ids, todos medidos na hora:**

```
baselines-medidas     php  test_as_baselines…_188_01                ::  tests=1 assertions=7
tokens-e-tamanhos     js   tests/js/publicador-painel-tokens-188…   ::  tests=9
vocabulario-tamanhos  js   tests/js/publicador-vocabulario-novo…    ::  tests=>=548
fid01-remedida        php  test_a_tabela_fid01…_188_01              ::  tests=1 assertions=13
```

### ⚠️ `vocabulario-tamanhos` em LINHA PRÓPRIA, com o N **medido**

A convenção foi **medida, não deduzida**: `grep -l vocabulario-tamanhos tests/gates/contagens-*.txt`
devolve **DEZ** arquivos (`contagens-187-01.txt` e `-03` a `-11`) — **um por wave da 187 que roda o
GATE-03**. A regra é **quem roda, declara** (o `contagens-187-02.txt` não o declara, e está certo).
**N medido agora: `>=548`** (os dez da 187 cravaram `>=443`; a contagem **cresce por construção**).
⚠️ **O proibido é UMA coisa só: embuti-lo num id que rode OUTROS arquivos** — foi o id composto que
fez a wave 187-05 reprovar dois ids alheios. **Linha própria é o oposto disso.**

### A bijeção, por script, nos **TRÊS** sentidos — **0 problemas**

```
ids no manifesto: 4      métodos test_ no arquivo php: 2      ids chamados por <verify>: 4
· id → método existente ......... ok (nenhum id com mais de 3 métodos; os dois php têm 1 cada)
· método → id ................... ok (ZERO método sem id: método sem id NUNCA RODA)
· id → <verify> do plano ........ ok (ZERO ID ÓRFÃO)
· <verify> → manifesto .......... ok (nenhum id chamado e ausente, que ABORTARIA)
· alvos js → test -e ............ ok (zero fantasma)
· nenhum id repetido ............ ok (o harness pega o primeiro)
```

### Homônimo contra as Fases 186 e 187 (as duas **em produção**)

```
ARQUIVOS=1208   METODOS=7755   HOMONIMOS=0
```

Varrido nas **duas direções** (meu contém alheio / alheio contém o meu), **incluindo prefixo**, com
isca nas duas direções na própria sonda (acusa um nome que existe; não acusa
`test_nome_que_ninguem_escreveu_9z7_188_01`) — sem a isca, "0 colisões" poderia significar que ela
não leu método nenhum.

---

## §8 — `--autoteste`: **20 casos, 0 divergência** (saída colada)

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

⚠️ **Rodado ANTES da primeira edição e DE NOVO no fim.** O 1º argumento desta fase é sempre
`188-NN`: `01` nu resolveria `contagens-186-01.txt`, **a fase errada, em silêncio** — e os casos 15
e 16 são o que guarda isso.

---

## §9 — CAMPANHA DE MUTAÇÃO: **8 mutações, 7 MORRERAM, 1 sobreviveu por desenho, 0 divergentes**

**Script:** `mutar-188-01.sh` (+ `mutar-188-01-m5.sh`), **com o plano no nome** — e isso não é zelo:
o scratchpad é **compartilhado** entre subagentes paralelos, e na 187 um `mutar.sh` genérico foi
**sobrescrito** pela outra wave, obrigando a refazer a campanha. O `sha256` do script é impresso
junto do veredicto.
**Receita cumprida em todas:** trabalho **commitado antes** · `cp` para o scratchpad · ocorrência
**travada em 1 ANTES** · **linha conferida DEPOIS** · restauração **DA CÓPIA** em `trap EXIT` ·
`sha256` contra `git show HEAD:` **com `tr -d '\r'` nos dois lados**. ⚠️ **`git checkout` NUNCA.**

| # | mutação | gate | esperado | obtido |
|---|---|---|---|---|
| 1 | tirar o `21` de `TAMANHOS_PERMITIDOS` | `tokens-e-tamanhos` | MORRE | ✓ **MORREU** |
| 2 | `shadow-pub-painel` → `rgba(0,0,0,0.60)` (quase-igual) | `tokens-e-tamanhos` | MORRE | ✓ **MORREU** |
| 3 | injetar chave em `colors.pub` **no disco, sem commit** | `tokens-e-tamanhos` | **SOBREVIVE** | ✓ **SOBREVIVEU** |
| 4 | quebrar o analisador: `startsWith('+')` → `startsWith('-')` | `tokens-e-tamanhos` | MORRE | ✓ **MORREU** |
| 5 | a §B deixa de declarar `COMO MEDIDA` | `fid01-remedida` | MORRE | ✓ **MORREU** |
| 6 | a linha `PHP:` da baseline vira travessão | `baselines-medidas` | MORRE | ✓ **MORREU** |
| 7 | tirar o `21` de `LIBERADOS_DEPOIS_DA_187` | **`187-01 tokens-da-fila`** | MORRE | ✓ **MORREU** |
| 8 | classificação da §I.2 → `APROXIMADO` | `fid01-remedida` | MORRE | ✓ **MORREU** |

### ⚠️ A mutação 5 foi RECUSADA na 1ª tentativa — e a recusa é a receita funcionando

O padrão casava **5 vezes** (uma por seção §B..§F, com texto idêntico de propósito) e a trava de
"ocorrência == 1" **recusou**. Mutação que troca cinco coisas de uma vez **não diz qual delas o gate
mediu**. ➡️ Refeita em `mutar-188-01-m5.sh` com `0,/pat/s` (só a **primeira** ocorrência) e a trava
adaptada e **explícita**: 5 antes, 4 depois.

### ⚠️⚠️ A ÚNICA SOBREVIVENTE, **INVESTIGADA** — e a investigação achou um defeito real

**Por que a mutação 3 sobrevive, e por que é desenho:** o invariante do bloco 4 é *"nenhum **COMMIT**
de escopo `(188-01)` acrescenta chave"*, e **não** *"o arquivo no disco nunca muda"*. A segunda forma
é o **gate mais largo que o invariante** que **reprovaria as Fases 189-194** — a 11ª ocorrência dessa
família, e exatamente o que o plano mandou consertar.

**A tese foi PROVADA, não afirmada:** peguei o **mesmo conteúdo mutado**, gerei o **diff real** com
`git diff` e alimentei o analisador. Ele **ACUSA** → o gate **morreria** se aquilo fosse commitado em
`(188-01)`. E a mutação **4** prova que o analisador é **load-bearing**.

⚠️ **MAS A INVESTIGAÇÃO ACHOU OUTRA COISA — e ela foi consertada:** o analisador **nomeava a chave
errada**. Ancorado em `^\s*`, numa linha com **duas** chaves
(`'line-strong': '#2E2E2E', 'linha-inventada-188': '#ABCDEF',`) devolvia `["line-strong"]` — a chave
**legítima** — em vez da inventada. O **veredicto** ficava certo (lista não-vazia → morre), **só a
mensagem mentia**. ➡️ **Mensagem que nomeia a coisa errada é como o executor seguinte conserta a
coisa errada** — é a família do *"gate que empurrava para o conserto errado"* (§6, regra 8). A
varredura passou a ser **global na linha**, e o guard `['"[{]` continua impedindo casar **dentro** de
um valor (provado: `'caixa': 'max-height:calc(100vh - 140px)'` devolve `["caixa"]`, **nunca**
`height`). Commit próprio: `47fb22d1`.

---

## §10 — TERRITÓRIO, por **ESCOPO DO ASSUNTO** (nunca por range)

**Os 4 commits deste plano, todos com `(188-01)` no assunto:**

| commit | tarefa |
|---|---|
| `120b5b47` | 1 — as 4 baselines |
| `61e84710` | 2 — o `21` liberado + a cirurgia na contagem fixa da 187 |
| `e20a92d5` | 3 — a FID-01 re-medida + PERGUNTA-188-A |
| `47fb22d1` | achado da investigação de mutação (o analisador) |

⚠️ **RANGE NÃO FOI USADO como prova.** A 187-11 mediu que ele não passa nesta árvore, e o **AUTOR não
discrimina nada** (as três máquinas commitam com o mesmo `user.name`). O gate perene é o
**ANTI-FANTASMA** (`test -e`), aplicado a **cada** caminho citado.

**Vetados, conferidos VAZIOS por `git diff HEAD --numstat`:** `.planning/ROADMAP.md` ·
`.planning/REQUIREMENTS-v27.md` · `.planning/STATE.md` (GATE-07) ·
`tests/js/publicador-header-zindex-ordem.test.js` (critério 1) ·
`tests/js/estrutura-meus-anuncios.test.js` (D-11/D-22) · `app/Services/Creative/` · `AnunciarML.jsx`
· `resources/js/Components/Publicador/` · `app/Support/Publicador/` · `app/Mcp/` · `AppLayout.jsx` ·
**`tailwind.config.js`** · `188-REGUA.md` · `188-COBERTURA.md` · `188-01-PLAN.md` · os planos **08 a
12** do planejador.

⚠️ **Antes de editar o `188-FID01.md` conferi `git diff HEAD -- <arquivo>`: VAZIO** — nenhum WIP do
planejador foi sobrescrito.
⚠️ **`artisan migrate` NÃO foi rodado** (esta fase não tem migration e há pendente de outra
milestone). **Nenhum deploy, nenhum push, nenhum toque no VPS, nenhuma chamada à API do ML.**
⚠️ **Nenhum pacote instalado.** Os quatro arquivos de dependência estão intocados.
⚠️ **`git add -A`, `git stash`, `git reset`, `git checkout`, `git commit --amend`, `git clean`, `pint`
e worktree: NÃO usados.** Todos os commits por `-F <arquivo>` + pathspec após `--`, e o
`Co-Authored-By` foi **conferido presente** no primeiro.

---

## §11 — NADA REGREDIU: as suítes no fim

| número | PARTIDA (`db9b1ff9`) | FIM | veredicto |
|---|---|---|---|
| BUILD | exit 0 · 79 s | **exit 0 · 77 s** | ok |
| MANIFEST | 495 / 202 | **495 / 202** | igual (nenhum `.jsx` novo — este plano não cria componente) |
| JS | 5489 / 5488 / 1 / 0 | **5498 / 5497 / 1 / 0** | **+9**, exatamente os 9 sub-testes do gate novo |
| PHP | 2493 / 21915 / 0 | **2495 / 21935 / 0** | **+2 testes e +20 asserções**, exatamente os meus dois métodos (7 + 13) |

**A aritmética fecha nos dois lados, sem resto:**

```
JS  : 5489 + 9  (publicador-painel-tokens-188.test.js) = 5498  ✓
PHP : 2493 + 2  (os 2 métodos de FechamentoDeBaselines188Test) = 2495  ✓
      21915 + 7 (baselines-medidas) + 13 (fid01-remedida)      = 21935 ✓
```

**Falhas: `0` no PHP e `1` no JS** — a única é a pré-existente de `estrutura-grade-glide.test.js`
(Fase 87). ⚠️ **As duas da Fase 179 continuam VERDES**, e seguem **não consertadas**.
⚠️ **Nenhum número ficou ABAIXO da partida.** Nada regrediu.

### ⚠️ UM ACHADO DE AMBIENTE que vale para os 12 planos seguintes

Na primeira medição do fim a suíte JS deu **5491 / 5488 pass / 1 fail / 2 skipped** — **+2** em vez
de **+9**, com 2 skips novos. **Não era regressão, e a aritmética fecha exatamente:**

```
5489 (partida) + 9 (o gate novo) − 7 (publicador-fila-tokens-187.test.js caindo no 2º regime) = 5491
```

**A causa:** a campanha de mutação **restaura por `cp`**, e `cp` **atualiza o `mtime`**. Com
`tailwind.config.js` mais novo que o CSS construído, a camada de CSS daquele gate **PULA** — é
literalmente o *"dois regimes de contagem"* que o manifesto da 187 documenta (`tests=16,9`), e o
próprio skip diz o conserto: *"rode `npm run build`"*.
➡️ **`npm run build` (exit 0, 77 s) restaurou o 1º regime, e a suíte voltou a 5498 / 5497 / 1 / 0.**
⚠️ O gate `tokens-da-fila` ficou **verde o tempo todo** porque `tests=16,9` aceita **os dois
regimes** — desenho correto do autor da 187, e a razão de eu não ter notado antes de medir a suíte.
➡️ **Régua para quem rodar mutação nesta fase: rode `npm run build` DEPOIS da campanha, antes de
medir a suíte JS.** O CSS não mudou (o hash segue `app-DtSI74zM.css`); o que mudou foi só o `mtime`.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] A contagem fixa `23` do gate TAM-187 impedia liberar o `21`**
- **Found during:** Tarefa 2, antes de editar (medido, não descoberto pelo vermelho)
- **Issue:** `tests/js/publicador-fila-tokens-187.test.js:415`/`:416`/`:429` cobravam
  `agora.length === 23` por contagem fixa. O `21` levava a lista a 24 e **três asserções** daquele
  gate — de fase **em produção** — ficavam vermelhas, deixando a árvore vermelha.
- **Fix:** contagem fixa → lista **CONSTANTE** `LIBERADOS_DEPOIS_DA_187`, com igualdade de conjunto.
  Estritamente **mais forte**; contagem de `test()` **inalterada** (16), então o manifesto da 187
  **não** foi tocado.
- **Files modified:** `tests/js/publicador-fila-tokens-187.test.js`
- **Commit:** `61e84710`
- ⚠️ **Decisão que precisa de revisão humana** — ver §4. **Alternativa recusada:** subir 23 → 24.

**2. [Rule 1 - Bug] O analisador de diff do bloco 4 nomeava a chave errada**
- **Found during:** investigação da única sobrevivente da campanha de mutação
- **Fix:** varredura **global** na linha em vez de ancorada no começo. Veredicto já estava certo; a
  **mensagem** mentia.
- **Commit:** `47fb22d1`

**3. [Rule 1 - Bug] O escape da §I era uma FRASE, e a prosa que explica a regra a continha**
- **Found during:** Tarefa 3, pela própria isca invertida do gate
- **Issue:** o gate procurava *"nenhuma divergência nesta re-medição"* em qualquer lugar do corpo —
  e **o parágrafo que EXPLICA a regra cita a frase**, deixando o escape ligado para sempre. É a
  **regra 2 do learnings** acontecendo **dentro do gate escrito para evitar exatamente isso**.
- **Fix:** o escape passou a ser um **CABEÇALHO estrutural**, que a prosa não satisfaz por acidente.
  **E o caminho de escape ganhou isca própria** (item 2b), porque *"asserção vazia quando escrita
  vira falso positivo depois"*: sem ela, uma fase futura sem divergências seria **obrigada a
  inventar uma** para o gate passar.
- **Commit:** `e20a92d5`

**4. [Rule 3 - Resíduo do plano] O `<files>` da tarefa 2 não listava o manifesto que o seu
`<verify>` precisa**
- **Issue:** o plano só reescrevia `contagens-188-01.txt` na tarefa 3; o harness **ABORTARIA** na 2.
- **Fix:** as três tarefas **acrescentam** ids ao manifesto, cada uma **antes** do seu próprio
  verify. Declarado no cabeçalho do manifesto.

**5. [Rule 1 - Bug na minha própria sonda] `split('|')` cru quebrava em `\|` escapado**
- **Fix:** split que respeita a barra invertida. **Consertado na sonda, não no documento** — 4 falsos
  positivos retirados.

### Divergências medidas e DECLARADAS (não "consertadas")

| divergência | `arquivo:linha` | veredicto |
|---|---|---|
| asserções PHP 21.915 vs 21.920 (−5) | — | **declarada**; zero commits em `tests/`, logo é o ramo de relógio dos 2 casos da Fase 179 |
| as 2 falhas PHP estão **verdes** hoje (eram 2) | `CockpitDaContaTest.php:966` e `:1318` | **alheias**, Fase 179; régua atualizada em §2 |
| o `white-space:nowrap` dos 3 KPIs omitido no plano | `188-01-PLAN.md:194` | **escrito** ao lado da liberação |
| o ponteiro da 1ª derrota do protótipo | prosa herdada dizia `:1045`; é **`:1054`** | **R20**, corrigido |
| a frase do `:105` do plano chamando a `PARTIDA` de "início de escopo" | `188-01-PLAN.md:105` vs `:333` | **não editei o plano** (território do planejador); segui o `:333` e **declaro aqui** que a contradição permanece no arquivo |
| 33 divergências da FID-01 | `188-FID01.md` §I | **corrigidas**, o protótipo ganhou |

### Pergunta devolvida: nenhuma

Nenhum conflito novo protótipo × regra do projeto foi encontrado (seria o **quarto**, e aí eu pararia
sem decidir). A medição dos tamanhos **confirmou** que só o `21` falta. Não foi necessário sair do
território.

---

## Known Stubs

Nenhum. Este plano não produz tela nem componente — entrega documentos de medição e dois gates.

## Threat Flags

Nenhuma. Nenhum endpoint, rota, caminho de autenticação, acesso a arquivo ou mudança de schema foi
introduzido. `T-188-SC` (instalação de pacote) fica **mitigado por constatação**: nenhum dos quatro
arquivos de dependência foi tocado, e nenhum comando de instalação foi executado.

## O que nenhum executor pode afirmar, e eu não afirmo

⚠️ **Não vi tela renderizada.** Este plano não renderizou nada — nem por `esbuild` +
`react-dom/server`. O banco local não tem empresa com `ml_token` (a rota dá **404**),
`pub_aprovacao_ofertas` está **vazia** e a fila nasce vazia até a Fase 194.
⚠️ **A conferência visual da Fase 187 pelo usuário continua ABERTA** — nada aqui a substitui nem a
presume feita.

---

## Self-Check: PASSED

**Arquivos conferidos no disco** (`test -f`): `188-BASELINES.md` · `188-FID01.md` ·
`188-01-SUMMARY.md` · `tests/js/publicador-painel-tokens-188.test.js` ·
`tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines188Test.php` ·
`tests/gates/contagens-188-01.txt` · `tests/js/publicador-vocabulario-novo.test.js` ·
`tests/js/publicador-fila-tokens-187.test.js` — **8/8 FOUND**.

**Commits conferidos** (`git log --oneline --all | grep`): `120b5b47` · `61e84710` · `e20a92d5` ·
`47fb22d1` — **4/4 FOUND**.

**Anti-fantasma de todo caminho citado** (`test -e`): `design_handoff_publicador/Aprovacao.dc.html` ·
`tests/Feature/Publicador/CockpitDaContaTest.php` · `tests/js/estrutura-grade-glide.test.js` ·
`tests/gates/verificar-testes.sh` · `tests/gates/contagens-187-01.txt` · `tests/js/_fonte.js` ·
`tailwind.config.js` — **7/7 FOUND**. E
`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` **confirmado INEXISTENTE**, como
declarado (o nono fantasma da milestone).

**Coerência interna da §I do `188-FID01.md`, por script:** 20 linhas `R*` + 2 `V*` + 11 `O*` = **33**,
igual ao placar declarado; **5** declarações `COMO MEDIDA` (uma por seção §B..§F); **0**
inconsistências de coluna nas tabelas markdown do arquivo.

**Gates, todos verdes na última execução:** `baselines-medidas` · `tokens-e-tamanhos` ·
`vocabulario-tamanhos` · `fid01-remedida` · **`187-01 tokens-da-fila`** (fase em produção, intacta) ·
`--autoteste` **20/0**.

---

## ⚠️ STATE.md / ROADMAP.md / REQUIREMENTS: **NÃO atualizados, de propósito**

O fluxo padrão do executor pediria `state.advance-plan`, `state.update-progress`,
`roadmap.update-plan-progress` e `requirements.mark-complete`. **Nenhum foi executado**, e a razão
está escrita em dois lugares:

- `188-REGUA.md` §9 (**GATE-07**): *"NÃO tocar em `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md` nem
  `STATE.md` — nenhum dos três pertence a plano nenhum; quem edita é o orquestrador, à mão, em bloco
  ADITIVO"*;
- `188-REGUA.md` §10: *"**NÃO** rodar `state.advance-plan`"* — ele **corrompeu o `STATE.md` deste
  projeto seis vezes**.

⚠️ E há o registro de que **`REQUIREMENTS.md` da raiz parou na v17.0**: os IDs novos só existem em
`REQUIREMENTS-vNN.md`, então `requirements.mark-complete` daria `not_found` em silêncio.
➡️ **A atualização dos três é do orquestrador.** Os REQ-IDs deste plano, para ele marcar:
**FID-01, FID-02, FID-03, FID-04, GATE-02, GATE-14, VOC-07**.
