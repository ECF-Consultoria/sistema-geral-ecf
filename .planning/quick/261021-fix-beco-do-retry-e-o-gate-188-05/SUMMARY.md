---
quick_id: 261021-fix
status: complete
---

# `261021-fix` — o beco do retry, o motivo que voltava atrás, e o gate vermelho da 188

> ⚠️ **Nota de autoria.** O executor desta quick **não pôde escrever este arquivo** (a ferramenta de
> escrita recusou: *"subagents should return findings as text, not write report files"*). **O
> orquestrador o persistiu** a partir do relatório integral dele, **acrescentando** a §13b — a
> verificação independente e o conserto do gate de janela deslizante, que era dono dele. Onde o texto
> diz *"medi"*, é o executor; o que é do orquestrador está marcado.

**PARTIDA:** `ddbfa230` · **HEAD do executor:** `c7a8dc63` · **HEAD depois do orquestrador:** `d1cd7713`

| commit | de quem | o quê |
|---|---|---|
| `1f0d35e4` | executor | `docs` — o artefato de planejamento, três itens / três provas |
| `d3f4bb81` | executor | `feat` — decisões 25 e 26 no portão, com a **razão reescrita** |
| `4aee7add` | executor | `feat` — decisão 26 na action: o motivo sobrevive ao rollback, transação **intacta** |
| `c7a8dc63` | executor | `test` — a fixture da 188 publicável **por método** + os seis casos novos |
| `d1cd7713` | **orquestrador** | `fix(gates)` — o gate de território da 188-07, auto-invalidante por janela |

---

## §1 Os três itens, e por que eram três

| item | origem | estado |
|---|---|---|
| **1** | **decisão 25** do usuário | ✅ `FALHA` entra em `JA_PASSARAM_PELO_PORTAO`, **e a razão do docblock foi reescrita** |
| **2** | **decisão 26** do usuário | ✅ o motivo da recusa **sobrevive ao rollback**, com a transação **não dividida e não removida** |
| **3** | gate `188-05 tentar-de-novo` **vermelho** | ✅ fixture publicável **por método**, `EXIT_REAL` **1 → 0** |

⚠️ **Ressalva de forma, declarada:** os itens 1 e 2 compartilham **um arquivo** (a constante é o item 1;
o método novo é o item 2). `git commit -- <pathspec>` é por **arquivo**, e separar hunks exigiria
`git add -p` (interativo, indisponível) ou `git reset` (**proibido**). ➡️ O corte ficou por **unidade
coerente de arquivo**: serviço, action, teste.

---

## §2 A medição que derrubou a razão escrita no código

`PubAprovacaoOferta::TRANSICOES` — varrido, não lido:

```
FALHA      => [PUBLICANDO]
PUBLICANDO => [PUBLICADO, FALHA]
APROVADO   => [PUBLICANDO, AGUARDANDO_APROVACAO]
PUBLICADO  => []
```

Quem aponta para `falha` é **só** `publicando`; quem aponta para `publicando` são `aprovado` e `falha`.
➡️ **A única entrada no ciclo `{publicando, falha}` vinda de fora é `aprovado`.** Logo **tudo que está
em `falha` JÁ FOI APROVADO**, e `mover(FALHA)` preserva `versao_aprovada`/`aprovado_por`/`aprovado_em`.

⚠️ A frase que estava no docblock — *"incluí-la faria uma oferta que falhou arrastar a irmã para a fila
sem a irmã ter sido reconferida"* — **é FALSA para este estado**. Ela foi **reescrita com a medição
dentro**, e a frase antiga fica **uma vez, marcada como falsa**.

⚠️ **Por que não há gate de AUSÊNCIA da frase antiga:** citá-la **é** o conserto — um gate de ausência
**reprovaria pelo próprio conserto**. A âncora é *"aparece 1 vez + a marca `ERA FALSA PARA ESTE
ESTADO`"*.

---

## §3 Item 1 — a decisão 25, e a prova de que ela **não** afrouxa a 23

`irma-em-falha-conta` → **`EXIT_REAL=0`, 3 passed (61 assertions)**

- a constante por **igualdade de conjunto** (`assertEqualsCanonicalizing`) **+ `assertCount(4)`** —
  ⚠️ porque a igualdade canônica **não acusa duplicata**, e sozinha seria fraca;
- **isca (−) um por um:** `aguardando_aprovacao`, `em_revisao_ecf`, `ajustes_solicitados` e
  `preparando` **não podem** estar lá;
- o **grafo por alcançabilidade** asseverado, com `publicado` terminal por estrutura;
- **isca (+)** irmã em `falha` → **enfileira**, com a prova **crua do banco** de que ela foi aprovada;
  ⚠️ **e o item que a aprovação DELA criou é posto fora de `VIVOS` antes** — senão a outra **ANEXA** e
  o caso mediria o ramo do anexo, **que nunca barra** (seria vazio por construção);
- **isca (−)** irmã em `aguardando_aprovacao` → **barra**, com `'Premium'` (nunca `gold_pro` cru);
- **isca (+)** produto de **um** alvo ativo — o caminho do agente — **continua enfileirando**.

### ⚠️ O impacto nas waves anteriores: medido, e é **ZERO**

`193-01` **3/50** · `193-02` **3/71 · 2/25 · 3/36 · 1/23** · `193-03` **3/38 · 3/50 · 3/39 · 3/31 ·
2/22** — **todos verdes, contagem idêntica**.

➡️ **A decisão 25 mudou o resultado de ZERO teste anterior, e isso É a prova de que ela não afrouxou a
decisão 23:** os casos daquelas waves põem a irmã em `aguardando_aprovacao` ou **inativa**, **nunca** em
`falha`.

---

## §4 Item 2 — a decisão 26, com a transação intacta

`motivo-da-recusa-registrado` → **`EXIT_REAL=0`, 2 passed (41 assertions)**

A memória do processo sobrevive ao rollback; a gravação acontece **no `catch`**, depois do rollback:

- o **`422` continua saindo**, com `regra` estável e o **motivo real** na mensagem;
- **a linha sobreviveu**, provada em forma **COLETORA** (`assertSame([...antes, esperada], eventos)`),
  que prova de uma vez que ela **existe**, é **uma só**, é a **última** e **nada mais mudou** —
  ⚠️ **sem depender de contagem de eventos** (o `193-02` mediu uma contagem subindo **26→27 sem defeito
  nenhum**);
- **ator Sistema** (`autor_id` nulo, tipo `sys` — nunca `error`, cujo rótulo é *"Falha na publicação"*,
  e **nada falhou ao publicar**);
- **a transação continua fazendo o trabalho dela:** a linha inteira da oferta **byte a byte intacta** e
  **zero item de fila vivo**;
- a **de-duplicação contra a última linha** (item `193-H`) nas **duas metades**: clique repetido sem
  fato no meio **não** escreve; com um fato no meio, o mesmo motivo **volta a entrar**;
- **isca (−)** no retry que **passa** (302): **não existe** linha de recusa.

⚠️⚠️ **Não há nenhuma asserção de nível de transação.** `assertGreaterThan(0, $transactionLevel)` é
**vazia por construção** — o `RefreshDatabase` abre transação própria e o nível já vem 1. A transação é
medida por **estrutura** (uma `DB::transaction(` no recorte do método, e a posição da gravação depois do
`catch`) **e** por **comportamento**.

---

## §5 Item 3 — o gate vermelho, consertado **por método**

```
ANTES:   EXIT_REAL=1   Tests: 2 failed (2 assertions)
DEPOIS:  EXIT_REAL=0   Tests: 2 passed (32 assertions)
```

**Causa medida:** a fixture **nunca foi publicável** — duas modalidades **ativas**, **mesmo título**,
**sem** conferência L3, **sem** conta liberada (fail-closed). Invisível enquanto o retry era só
transição de estado; com o retry **enfileirando de verdade**, ela **recusa**.

⚠️ **Recusar é o comportamento CERTO:** mover sem enfileirar deixaria a oferta `publicando` ligada ao
item terminado, e o reconciliador a fecharia como `falha` no minuto seguinte — **flapping, com a edição
bloqueada**. ➡️ **O obsoleto é o `assertRedirect()`, não as asserções de invariante** (*"zero escrita no
ML"*, *"sem MLB"*), que ficaram **intocadas**.

### A forma, provada pelo diff: **75 inserções / ZERO remoções**

| proibição | prova |
|---|---|
| mexer no `cenario()` (9 métodos, fase em produção) | ele está em `:179-313`; **nenhum** dos 5 pedaços cai lá — e **zero remoções** no arquivo inteiro |
| tocar qualquer **sonda** | as três nomeadas, **intocadas** — de novo, zero remoções |
| auxiliar compartilhado | chamado por **2** métodos (os dois vermelhos); os outros **7 não o veem** |

**Quatro ingredientes, cada um medido como NECESSÁRIO pela mutação:** conta liberada · **alvo** da irmã
desativado (`pub_rascunho_alvos.ativo`, a **própria âncora** da guarda) · títulos distintos (V-TIT-04) ·
`PubValidacao` **L3** com a `revisao` do rascunho **relido** (o Eloquent **não** lê o default do banco).

### ⚠️ A contagem **não** mudou — e isso foi investigado, não assumido

| evidência | medição |
|---|---|
| o gate mudou de estado | `EXIT_REAL` **1 → 0**: a edição tomou efeito |
| o manifesto | `tests=2 assertions=32`, declarado em **19/10** com o gate **verde** |
| o vermelho | `2 failed (2 assertions)` — abortava na **primeira** asserção |
| o verde | `2 passed (32 assertions)` — as 32 voltaram a rodar |
| o diff | **+75 / −0**, **zero asserção acrescentada**: só fixture |

➡️ ⚠️ **O número é idêntico POR CONSTRUÇÃO, e essa é a prova mais forte de que o conserto restaurou o
comportamento original em vez de reescrever a expectativa. Remedir seria escrever o mesmo número de
volta** → `contagens-188-05.txt` fica com `numstat` **vazio**, declarado.

⚠️ **A decisão 25 não conserta este caso** (a irmã está em `aguardando_aprovacao`), e a mutação que
devolve a irmã para **ativa** **mata** o gate mesmo com a decisão 25 aplicada: **a independência é
medida, não afirmada**.

---

## §6 Os ids alheios, antes e depois

| id | antes | depois | |
|---|---|---|---|
| `186-04 unica-porta` | 43 | 43 | ✅ |
| `186-04 oferta-do-portao` | 67 | 67 | ✅ |
| `186-09 transversais` | 259 | 259 | ✅ |
| `186-09 andamento-um-lugar-so` | 21 | 21 | ✅ |
| `186-09 sem-segundo-passo` | 53 | 53 | ✅ |
| `186-09 bijecao-e-homonimo` | 31 | 31 | ✅ |
| `188-05 aprovar-e-enfileirar` | 85 | 85 | ✅ |
| `188-05 ausencia-de-segundo-passo` | 17 | 17 | ✅ |

⚠️ O `186-04` **não poderia** ter subido: a camada B é `glob('…/Aprovacao/*.php')` **não recursivo** e
esta quick **não cria** arquivo ali — **se tivesse subido, era PARADA**.

---

## §7 O manifesto da quick — o **segundo** de quick do projeto

`tests/gates/contagens-261021-01.txt`, chamado **`261021-01`**, com a prova medida ao vivo de que a
forma com **letra não funciona**:

```
261021-fix  ->  EXIT_REAL=2   ABORTOU: manifesto nao existe: …/contagens-186-261021-fix.txt
261021-01   ->  EXIT_REAL=0   Tests: 3 passed (61 assertions)
```

⚠️ `verificar-testes.sh:122` resolve por **dois grupos de dígitos**; letra cai no `else` e monta prefixo
**`186-`**, de fase **em produção**. ⚠️⚠️ **A lição do pipe foi paga ao vivo outra vez:** a primeira
leitura desta mesma isca, com `| head -3` na frente, devolveu **`EXIT_REAL=0`**; o real é **2**.

Bijeção: `SEM_ID=0` · `ORFAOS=0` · `IDS_REPETIDOS=0` · `HOMONIMOS=0` · sufixo `_261021_fix` nos 6
métodos · **nenhum id com mais de 3 métodos** · **terceira** bijeção id ↔ `<verify>` do `PLAN.md` ·
expansor de alternância **iscado nas duas direções antes de valer**.

⚠️ **O nome alheio da isca (−) entra por VARIÁVEL** — como literal, ganharia **forma de declaração** e o
gate `186-09 bijecao-e-homonimo` **reprovaria**. Foi o furo 1 da wave anterior.

⚠️ **A contagem do id de bijeção foi CHUTADA (31, copiada da wave anterior), o harness REPROVOU nomeando
a divergência, e foi remedida para 25.** **O harness funcionando é parte da prova.**

`--autoteste`: **20 casos, 0 divergência**, `EXIT_REAL=0`.

---

## §8 ⚠️ Os furos nas **próprias** sondas do executor

**1 — a sonda da transação lia a CAUDA DO ARQUIVO e acusava o trabalho correto.**
`strstr($limpo, 'public function tentarDeNovo(')` devolve **da assinatura até o fim do arquivo**, e
`tentarDeNovo` **não é o último método**. Resultado: `1 failed` com *"a transação foi DIVIDIDA ou
REMOVIDA"*. ➡️ Família *"gate mais largo que o invariante"*. ⚠️ **O conserto errado seria elevar 1 → 3**
— número que mudaria sozinho no próximo método que outra sessão acrescentasse. ➡️ **Conserto na raiz:**
`trechoDoMetodo()` recorta até a assinatura **seguinte**, com **isca (+)** e **isca (−)**; sem as duas,
`1` seria falso positivo de **recorte vazio**. A mutação que reintroduz o furo **mata** o gate.

**2 —** a contagem do id de bijeção foi **chutada**; o harness reprovou e ela foi remedida.
**3 —** o filtro de medição tinha `test_` **duplicado** (`test_(a|test_b)`) e rodou **um** método em vez
de dois. ➡️ **A contagem que vale é a do harness.**
**4 —** o `exit` lido **depois de um pipe** deu `0` onde o real era `2`.

---

## §9 Critérios do briefing que foram rodados e achados **vazios, fracos ou falsos**

| critério | veredicto |
|---|---|
| *"REMEÇA `contagens-188-05.txt`"* | ⚠️⚠️ **premissa desmentida pela medição** — o id voltou ao valor que já estava lá (§5) |
| `assertGreaterThan(0, $transactionLevel)` | ⚠️ **vazia por construção** → nenhuma foi escrita |
| *"constante por igualdade de conjunto"* | ✅ mas **fraca sozinha**: não acusa duplicata → `assertCount(4)` |
| *"a razão do docblock reescrita"* | ✅ mas um gate de **ausência** da frase antiga seria *"reprova pelo próprio conserto"* |
| *"irmã em `falha` enfileira"* | ✅ mas **vazio** sem matar o item que a aprovação **dela** criou |
| *"a linha existe depois do rollback"* | ✅ mas **vazio** sem a isca (−) do retry que **passa** |
| *"o histórico tem a linha"* por contagem | ⚠️ **refém da contagem** → forma **coletora** desde o início |
| gate dos **4 literais proibidos** | ⚠️ **não entrou, declarado**: é dos planos que têm as listas constantes; um quarto gate sobre 2 arquivos não acrescenta prova |
| gate **`js`** · `dropForeign → dropIndex` · sonda de `PUT /items` | ⚠️ **não entraram**: gates sobre **zero casos** no território desta quick |

---

## §10 Mutação: **9 mutações, zero sobreviventes**

`mutar-261021-fix.sh` + o trocador, **os dois com `sha256` impresso antes de qualquer veredicto e
reconferido depois com o mesmo valor**; ocorrência **travada em 1 antes** (aborta com 0 ou 2+); linha
divergente impressa depois; aplicação por **divergência de `sha256`**; restauração **da cópia** em
`trap EXIT`; `sha256` final contra `git show HEAD:` com `tr -d '\r'`. **`git checkout` nunca.**

⚠️ Houve **validação seca** das 9 âncoras antes (aplicar → restaurar sem rodar gate). **Sem isso, âncora
morta daria "morreu" falso.**

| mutação | veredicto |
|---|---|
| `FALHA` **sai** da constante | **MORREU**, nomeando o estado |
| um **quinto** estado entra | **MORREU** em **dois** gates |
| a gravação **volta para dentro** da transação | **MORREU** em **dois** gates |
| a gravação é **removida** | **MORREU** em dois gates |
| de-duplicação contra **todo** o histórico | **MORREU** |
| conta volta a **não** estar liberada | **MORREU** |
| a irmã volta a ficar **ativa** | **MORREU** |
| a conferência **L3 sai** | **MORREU** |
| o recorte volta a ser a **cauda do arquivo** | **MORREU** |

⚠️⚠️ **Em todas as 9 o `# tests` do mutado bate com o do pristino**, logo **cada arquivo carregou** e cada
morte é **da asserção**, não de falha de **boot**.

### ⚠️ Os dois "passou" que **não** são sobreviventes — investigados

1. tirar `FALHA` **não** mata o `193-02 guarda-modalidade-irma`: aquele caso põe a irmã em
   `aguardando_aprovacao` ou **inativa**, **nunca** em `falha` — **inobservável por construção** para
   ele, **e é exatamente por isso que a decisão 25 não afrouxa a 23**.
2. a de-dup contra todo o histórico **não** mata o `193-04 retry-republica`: aquele caso tem **uma**
   recusa só, e com uma linha as duas formas **coincidem**.

---

## §11 ⚠️⚠️ OUTRA SESSÃO ESTAVA RODANDO SUÍTES PHPUNIT NESTA ÁRVORE — provado por linha de comando

O briefing manda provar isolamento. O executor provou, **e o resultado foi o contrário do esperado**:

```
php.exe vivos = 7   TODOS de um scratchpad que NÃO é o desta sessão
  11:56  --filter Publicador|Portal|Pub|Estrutura|Mlb|Creative|…   <- É O RECORTE
  12:07  tests_Feature_Publicador.xml
  12:13  tests_Feature_V16.xml
  12:15  tests_Unit_Phase134.xml
  processos NOVOS nascendo a cada poucos segundos -> campanha ATIVA, não zumbis
```

⚠️ **Nenhum número de suíte foi produzido naquela janela** — *"contagem de suíte com outra suíte ao lado
não é medição"*. O executor **esperou com um monitor de laço de 15 s** até `LIVRE` e **só então** mediu.

⚠️⚠️ **E a lição que vale para quem fechar a fase:** `node.exe` aparecia **16 vezes por NOME**, e a
inspeção de **cada linha de comando** mostrou Adobe CC, dois runtimes do Codex e MCP via `npx-cli.js` —
**zero** era `node --test`. **Filtro por nome acusa falso.**

⚠️⚠️ **E o mais importante:** a FACE 2 do território veio **vazia** — **o vizinho não commitou nada** —
**mas estava rodando suíte**. ➡️ ***"Não commitou" ≠ "não está trabalhando"***: o território por commit
**não vê** esse tipo de atividade.

---

## §12 Baselines, com HEAD nas duas pontas e isolamento provado

```
PARTIDA   ddbfa230        HEAD   c7a8dc63   (conferido no INÍCIO e no FIM de cada rodada)
BUILD     exit REAL 0 · 103 s
MANIFEST  500 / 204       (controle PRESENTE, isca AUSENTE)
PHP       2692 / 24988 / 0   (exit REAL 0)   <- ⚠️ O RECORTE VOLTOU A ZERO FALHAS
JS        6031 / 6029 / 2 / 0 (exit REAL 1)  <- ⚠️ a 2ª falha é ALHEIA — §13
PROTOTIPO 1432 l. · sha256 68624883ce35547e
```

| linha | antes | agora | atribuição |
|---|---|---|---|
| **PHP testes** | 2686 | **2692** | **+6 MEUS**, e a aritmética fecha: os 6 métodos do arquivo novo, por `git log --diff-filter=A` |
| **PHP falhas** | **2** | **0** | ⚠️⚠️ **as duas falhas do item 3 estão consertadas** |
| **JS testes** | 6031 | **6031** | ✅ idêntico — zero teste JS tocado |
| **MANIFEST** | 500/204 | **500/204** | ✅ e o invariante **real** provado: `numstat` **vazio** em `resources/js/` |

⚠️⚠️ **Nenhum `REF_PHP_FALHAS` foi criado** — um limiar aceito seria a porta dos fundos que o harness
existe para fechar. ⚠️ **A suíte PHP completa segue não medível nesta máquina** (Fatal de memória em
processo **filho**; `-d memory_limit=-1` não resolve): o número é o **recorte**, e isso está dito.

---

## §13 ⚠️⚠️ A devolução: um gate alheio **auto-invalidante por janela deslizante**

`tests/js/publicador-painel-casca-188.test.js` montava a lista de commits por **`git log -80`**, e a
isca de não-cegueira exigia ao menos um commit `(188-0N)` nessa janela:

```
posição do commit (188-0N) mais recente:
   na PARTIDA  ->  77   (DENTRO da janela: margem de 3)   -> gate VERDE
   agora       ->  81   (FORA da janela)                  -> gate VERMELHO
```

➡️ **O gate reprovava por ANDAR O REPOSITÓRIO**, não por nada feito ao invariante dele. Os 4 commits da
quick foram a **última gota** — **qualquer 4 commits de qualquer sessão** teriam o mesmo efeito.

O executor mediu que o **invariante real estava intacto** (as duas passadas de território não acusaram
nada) e **devolveu sem consertar**, porque é gate de fase **em produção** e fora do território dele.
**Estava certo.**

## §13b ⚠️ O conserto, feito pelo orquestrador (`d1cd7713`) — e era pior que o vermelho

O orquestrador conferiu a medição (**posição 81, janela 80**) e, ao ler o caso inteiro, mediu **uma
consequência que o vermelho escondia**:

> `const meus = log.filter(c => c.assunto.includes('(188-07)'))`

Com a janela passando dos commits da fase, **`meus` ficava VAZIO** — e as **duas passadas de território**
passavam a asseverar `[] === []`. ➡️ **As provas ficavam VAZIAS POR CONSTRUÇÃO: verdes, e sem medir
nada.** **Foi a isca de não-cegueira que pegou, e é exatamente para isso que ela existe.**

**O conserto não foi alargar a janela** (`-80` → `-300`): isso seria **elevar um número** — o hábito que
esta milestone consertou **dezesseis** vezes na raiz e **zero** por elevação — e **só adiaria** a mesma
falha. ➡️ **A fonte dos commits do assunto passou a ser `--grep` SEM JANELA**, que **não pode deslizar**.

⚠️ **A sobre-captura do `--grep` é inofensiva aqui, de propósito:** ele lê a mensagem inteira, mas o
filtro seguinte é por **`%s`**, então commit que só cita o escopo no **corpo** é descartado. **A
sobre-captura amplia o candidato, nunca o veredicto.** A janela continua existindo **só** para a isca
geral de *"o `git log` não veio vazio"*, que é sobre o **repositório** e não sobre a fase.

⚠️ **E foi acrescentada a asserção que faltava:** `meus.length >= 1`. Sem ela, as duas passadas podem
voltar a ficar vazias por construção **sem que nada acuse** — era essa a lacuna estrutural, não só a
janela.

**Resultado:** `casca-do-painel` **45 / 45 / 0, `EXIT_REAL=0`**. A contagem seguiu **45**, logo
`contagens-188-07.txt` **não precisou de edição**.

---

## §14 O que esta quick **não** fez (é a régua, não omissão)

Nenhuma migration · `artisan migrate` não rodado · nenhum `.jsx` · nenhuma rota, estado, rótulo, toast
ou `regra` novos · a transação **não** foi dividida nem removida · a de-duplicação **não** mudou · nenhum
serviço das Fases 186-189 alterado · **o `cenario()` compartilhado e todas as sondas intocados** (provado
por **zero remoções**) · `ROADMAP`/`STATE`/`REQUIREMENTS` e os artefatos da 193 intocados ·
`state.advance-plan` não rodado · nenhum deploy, push, VPS ou chamada à API real do ML.

⚠️ **Não se afirma ter visto tela renderizada**, nem publicação real, nem o cron rodando: não há
navegador, nenhuma empresa tem `ml_token` de verdade e `pub_aprovacao_ofertas` está **vazia em
produção**. A prova é **rota + `Http::fake` + `preventStrayRequests()` + `Queue::fake()`**.

---

## §15 Superfície de dado ampliada, declarada

A linha de histórico da recusa do retry passa a **sobreviver ao rollback**. Ela é **texto de tela**, com
**ator Sistema**, e o conteúdo é **o motivo da própria fila** — nunca `regra` crua, nunca `ml_causa`,
nunca segredo. ⚠️ **A tabela continua APPEND-ONLY:** a de-duplicação **lê antes de inserir**; não apaga,
não edita, não oculta.
