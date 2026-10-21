# Baselines da Fase 193

⚠️⚠️ **ESTE ARQUIVO NASCE INCOMPLETO DE PROPÓSITO.** As linhas de suíte **não foram medidas no
planejamento**, e a razão é medida, não preguiça: a **Fase 189 estava EM EXECUÇÃO** quando este
documento foi escrito (20/10/2026, HEAD `0607f5a9`, SUMMARYs 01-05 no disco e 06/07/08 pendentes), e
**duas suítes PHPUnit simultâneas disputam `storage/framework/testing`**, produzindo falhas que
**passam em isolamento** (§4d.4(b) do learnings). ➡️ **Contagem de suíte feita com outra suíte
rodando ao lado NÃO É MEDIÇÃO.**

➡️ **O `193-01` MEDE as linhas de suíte**, em isolamento, **depois** de a Fase 189 estar fechada, e
preenche este arquivo. ⚠️ **Nenhum plano "ajusta" baseline: ACIMA é esperado, ABAIXO é regressão.**

---

## §1 — A FORMA FIXA (o `193-01` preenche o que está como `A MEDIR`)

```
PARTIDA:   c4529cca   <- capturada na PRIMEIRA acao do 193-01, antes de qualquer leitura
COMMIT:    7a29677c   <- o commit da tarefa 2 (o de docs desta wave vem depois dele)
BUILD:     exit 0 · 121 s · "built in 1m 53s"
MANIFEST:  498 entradas / 203 paginas  (pelo JSON; controle `Aprovacao.jsx` PRESENTE, isca `NaoExiste9z7.jsx` AUSENTE)
JS:        6012 / 6011 pass / 1 fail / 0 skipped   (exit 0 — a falha e a pre-existente da Fase 87)
PHP:       2650 / 24278 / 0                        (exit 0 — o RECORTE, pelo --suite php)
RECORTE:   353 / 6035 / 0                          (exit 0 — tests/Feature/Publicador/Aprovacao)
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

⚠️⚠️ **COMO ESTAS LINHAS FORAM MEDIDAS — e por que a PRIMEIRA tentativa foi DESCARTADA**

Todas medidas em **20-21/10/2026**, **em ISOLAMENTO**, e o isolamento foi **provado por linha de
comando, nao por nome de processo**: dos 22 `node.exe` vivos na maquina, **zero** era
`node --test`, `vite` ou `npm` (eram Adobe CC, runtimes do Codex e servidores MCP), e `php.exe`
estava em **0** antes de cada rodada de PHP. **Nao houve build nem outra suite concorrente.**

⚠️⚠️ **A PRIMEIRA MEDICAO DO `--suite php` FOI INVALIDADA, E A CAUSA E MEDIDA:** ela rodou
19 min (23:26:41 → 23:45:48 UTC) e devolveu `2640 / 24105 / **4**`. As 4 falhas nao eram as
previstas na §4. ⚠️ **Durante aquela rodada, OUTRA SESSAO commitou `56ade5f0`** (AG-07 do Agente
ECF Publicador), que **criou** `app/Services/Publicador/Agente/LeituraDoAgente.php` e
**acrescentou 10 linhas** a `FechamentoDaFase186Test.php`, pondo aquele arquivo na lista de
expositores do gate `andamento-um-lugar-so`. ➡️ **A suite mediu uma arvore que se moveu por baixo
dela** — *"suite longa em arvore compartilhada mede um alvo que se move"*, agora com o alvo sendo
um **commit alheio no meio da rodada**. A prova de que era isso: o *stack trace* da falha aponta
`assertSame` na linha **903**, e o arquivo em HEAD tem a mesma linha na **913**.

➡️ **A medicao valida e a SEGUNDA**, com `HEAD` **conferido no inicio E no fim** (`7a29677c` nas
duas pontas, logo a arvore **nao** se moveu): `2650 / 24278 / **0**`, 861 s, `exit` real `0`.

⚠️ **O `exit` foi lido de `$?` IMEDIATAMENTE, nunca de um `tail`/pipe na frente** — e isso nao e
zelo: na primeira rodada o harness saiu **1** e a notificacao do processo em background reportou
**"exit code 0"**, porque o ultimo comando do composto era um `tail`, que sai sempre 0.

### A aritmetica do Δ — fechada, e cada parcela atribuida pelo commit que CRIOU o arquivo

| parcela | testes | atribuicao (`git log --diff-filter=A`, **nunca por range**) |
|---|---|---|
| fechamento da Fase 189 | **2640** | a medicao dele, em `189-FECHAMENTO.md` |
| `tests/Feature/Publicador/Agente/TelaDoAgenteTest.php` | **+6** | ⚠️ **ALHEIO** — criado por `56ade5f0` (AG-07, outra sessao) |
| `tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php` | **+4** | **MEU** — `7a29677c` |
| **esperado** | **2650** | **MEDIDO: 2650** ✅ |

**A procedência de cada linha — o comando exato:**

| linha | comando |
|---|---|
| `PARTIDA` / `COMMIT` | `git rev-parse --short HEAD` |
| `BUILD` | `T0=$(date +%s); npm run build; echo $?; T1=$(date +%s); echo $((T1-T0))` |
| `MANIFEST` | ⚠️ **pelo JSON** (`node --input-type=commonjs` ou arquivo `.cjs`), **NUNCA por grep** — o hash sai **com hífen** |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` |
| `RECORTE` | `artisan test tests/Feature/Publicador/Aprovacao` |
| `PROTOTIPO` | `find . -maxdepth 3 -name Aprovacao.dc.html` → `test -e` → `wc -l` → `sha256sum` |

---

## §2 — O QUE **FOI** MEDIDO NO PLANEJAMENTO (20/10/2026, HEAD `0607f5a9`)

| medida | valor | comando |
|---|---|---|
| HEAD | **`0607f5a9`** | `git rev-parse --short HEAD` |
| `manifest.json` entradas | **498** | `node --input-type=commonjs` lendo `public/build/manifest.json` |
| `manifest.json` páginas (`/Pages/`) | **203** | idem |
| controle `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` no manifest | **presente** | idem |
| protótipo `design_handoff_publicador/Aprovacao.dc.html` | **1432** linhas, `sha256 68624883ce35547e` | `wc -l` + `sha256sum` |
| `new_layout_publicador/Aprovacao.dc.html` | ⚠️ **NÃO EXISTE** (confirmado) | `test -e` |
| MariaDB local | ⚠️ **PARADO** | `tasklist \| grep mysqld` → **vazio** |
| `test -e` dos **26** caminhos citados no planejamento | **26/26 OK, ZERO fantasma** | laço de `test -e` |

⚠️ **Os números de suíte do briefing do orquestrador** (JS 5924/5923/1/0 · PHP recorte 2595/23.878/0 ·
manifest 498/203) **não foram reconferidos** por causa da Fase 189 em execução. O **manifest bate**;
os de suíte o `193-01` remede. ⚠️ **Eles serão MAIORES** — a 189 acrescenta testes.

---

## §3 — ⚠️⚠️ A SUÍTE PHP **COMPLETA** NÃO É MEDÍVEL NESTA MÁQUINA

`artisan test` **aborta com Fatal de memória** em
`vendor/.../Illuminate/Http/Testing/FileFactory.php:87`, a ~6% de 10.653 testes, e
⚠️ **`-d memory_limit=-1` NÃO resolve** (o estouro é em processo **FILHO**).
➡️ **Mede-se o RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`, o `--suite php`), e
**diz-se isso** — em vez de deixar o leitor supor que a suíte inteira passou.

---

## §4 — ⚠️ AS FALHAS CONHECIDAS — nenhuma é desta fase

### JS — **uma** falha aceitável

`tests/js/estrutura-grade-glide.test.js:122` (*"Características secundárias nasce recolhido"*, Fase
87). ⚠️ **NÃO corrigir** — é conhecida e intencional.

### PHP — fora do recorte, nenhuma desta fase

| teste | classe | atribuição |
|---|---|---|
| `CalcularFaixaTest` (**9**) | pré-existente | fora do recorte |
| `CompanyServiceTypeTest::test_service_type_aceita_polo` (**1**) | ⚠️ a armadilha **enum + CHECK do SQLite** | fora do recorte |
| `Phase39` (**2**) | pré-existente | fora do recorte |
| **2** de `tests/Feature/Publicador/CockpitDaContaTest.php` | ⚠️⚠️ **OSCILAM COM A HORA DO DIA (provado)** — fronteira de dia, `:966` e `:1318` | ⚠️ o caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` **NÃO EXISTE** (9º caminho fantasma da milestone, veio de briefing). O real é **fora** de `Aprovacao/` |
| **2** de `SincronizaPortalCompletoTest` | ⚠️ **ordem / corrida** de `storage/framework/testing` | passam em **isolamento** |

⚠️⚠️ **A RÉGUA, para quem medir depois:**
1. **ATRIBUIÇÃO DE FALHA EXIGE EXECUÇÃO ISOLADA** — rodar o arquivo **SOZINHO**. 10 das 11 falhas de
   uma rodada paralela da 188 passavam em isolamento.
2. **Falha que passa em isolamento NÃO conta como regressão** desta fase.
3. ⚠️⚠️ **NÃO criar `REF_PHP_FALHAS`** — um limiar de falhas aceito seria a porta dos fundos que o
   harness existe para fechar.
4. **Comparar TESTES e FALHAS; asserções são INFORMATIVAS** (os dois casos de fronteira de dia
   percorrem ramos diferentes conforme a hora, e ramo diferente exercita número diferente de
   asserções). O harness tem `REF_PHP_TESTS` e **não** tem referência de asserções.
5. ⚠️ **A hipótese do listener é HIPÓTESE** — os dois experimentos que a testariam saíram **verdes**.
   **Não afirmar como diagnóstico.** E **`$this->setUp()` chamado no meio de um caso derruba o
   `RefreshDatabase`**: falha de ordem **nem sempre** é sensibilidade de listener.

---

## §5 — ⚠️ "O COMPONENTE NOVO ESTÁ NO MANIFEST" É INVARIANTE **FALSO** NESTE PROJETO

Medido no fechamento da 188: **19 `.jsx` novos → ZERO entradas novas** no manifest (495 → 495). O
`laravel-vite-plugin` declara como *inputs* só `app.jsx` e `app.css`; **componente aninhado é FUNDIDO
no chunk da página**. ➡️ O que o manifest prova é que **a PÁGINA existe e compila** — e é por isso
que o controle positivo é a `Aprovacao.jsx`, com a **isca** `resources/js/Pages/Mlb/NaoExiste9z7.jsx`
ao lado.

⚠️ **E esta fase NÃO edita `.jsx` nenhum** (§1-M6 da `193-REGUA.md`): o `build` aqui é **só** prova
de não-regressão, e o número do manifest tem de ficar **IGUAL**. ⚠️ **Medido e IGUAL: 498 / 203.**

> ⚠️⚠️ **ATUALIZADO EM 21/10/2026 pelo orquestrador — O NÚMERO MUDOU, E NÃO FOI A 193.**
>
> **Medido agora: `500 entradas / 204 páginas`.** O `+2 / +1` é **TODO ALHEIO**, do Agente ECF Publicador de
> outra sessão: `resources/js/Pages/Mlb/Publicador/Agente.jsx`, **criado por `56ade5f0`**, explica **+1 entrada
> e +1 página**. ⚠️ **A 2ª entrada não foi isolada** — isolar exigiria o `manifest.json` antigo, e obtê-lo
> pediria `git checkout`, que é **proibido** nesta árvore. O que **está provado** é que **não é da 193**: os
> commits das waves tocam **zero** `resources/js/`, **zero** `.jsx` e **zero** `package*`/`vite.config.js`.
>
> ➡️ **A regra para as waves 03, 04 e 05 passa a ser:** o invariante **NÃO é** *"o manifest tem de ser
> 498/203"*, e sim **"a 193 não mexe no manifest"** — prove por **`numstat` vazio em `resources/js/`
> no SEU range**, não pelo número absoluto. ⚠️ **NÃO suba o número aqui para 500/204:** ele mudaria de novo
> no próximo `.jsx` que a outra sessão criar, e isso é a família *"âncora em número que muda legitimamente"*,
> com **dezesseis** ocorrências consertadas na raiz nesta milestone e **zero** por elevação de número.
>
> ⚠️ **E o controle que IMPORTA continua valendo e tem de ser rodado:** `Aprovacao.jsx` **PRESENTE** no
> manifest e a isca `NaoExiste9z7.jsx` **AUSENTE** — *"página fora do manifest SOME"* é o invariante real,
> e ele **não depende** da contagem total.

---

## §6 — ⚠️ AS FALHAS ATRIBUÍDAS POR EXECUÇÃO ISOLADA (preenchido pelo `193-01`)

⚠️ **A §4 previa falhas que NÃO apareceram** (`CalcularFaixaTest`, `CompanyServiceTypeTest` e
`Phase39` ficam fora do recorte; os dois de `CockpitDaContaTest` e os dois de
`SincronizaPortalCompletoTest` **passaram**). As que apareceram na rodada **descartada** são
outras, e cada uma foi atribuída **rodando o arquivo SOZINHO**:

| falha (por SEMÂNTICA, nunca por linha) | isolado | atribuição |
|---|---|---|
| `FechamentoDaFase186Test > a escrita de andamento mora num lugar so em app inteiro com isca` | **PASSA** (`1 passed / 21 assertions`, em **2** rodadas) | ⚠️ **ALHEIA:** acusava `app/Services/Publicador/Agente/LeituraDoAgente.php`, que era **untracked WIP de outra sessão** (`git status` → `??`) na hora da varredura. Quem a resolveu foi o commit **`56ade5f0`** daquela sessão, acrescentando o arquivo à lista de expositores **com prova**. É a família *"o gate é mais largo que o invariante"*, e o remédio aplicado foi o que o próprio docblock do gate prescreve |
| `PortalFotosTest > arquivo sumido e contado com o motivo` | **PASSA** | ordem / corrida de `storage/framework/testing` |
| `PortalFotosTest > arquivo que nao e imagem e contado como formato` | **PASSA** (os três no mesmo run isolado: `13 passed / 36 assertions`) | idem |
| `PortalFotosTest > publicacao que comeca durante a copia impede a atribuicao da foto` | **PASSA** | idem |

➡️ **Nenhuma é regressão desta fase**, e **nenhum `REF_PHP_FALHAS` foi criado**. ⚠️ Na medição
**válida** (HEAD estável nas duas pontas) o recorte deu **0 falhas** — não há nada a tolerar.

| JS — a única falha aceitável | isolado | atribuição |
|---|---|---|
| `estrutura-grade-glide.test.js` → *"Características secundárias nasce recolhido (é o grupo que mais infla)"* | **19 / 18 pass / 1 fail / 0 skipped** | **pré-existente, Fase 87.** O código tem `RECOLHIDOS_INICIAIS = []` e o teste espera `[G_SECUND]`. ⚠️ **NÃO corrigida** |

⚠️ **`0 skipped` nas duas pontas do JS** — a degradação para `skip` por `public/build/` ausente
**não** ocorreu, porque o `build` rodou **antes** e **sozinho**.

---

## §7 — ⚠️⚠️ AVISO PARA AS WAVES `193-02` A `193-05`: DOIS NÚMEROS DA RÉGUA FICARAM OBSOLETOS

A `193-REGUA.md` §5 manda conferir que `contagens-186-09.txt` **não mude**, com a instrução
*"mudou, PARE e devolva"*. ⚠️ **Ele mudou — e NÃO foi por trabalho desta fase.** O commit
**`56ade5f0`** (AG-07, outra sessão, 20/10/2026) remediu dois ids daquele manifesto, declarando
textualmente *"Remedido, nao ajustado"*:

| id de `contagens-186-09.txt` | antes | **agora** |
|---|---|---|
| `andamento-um-lugar-so` | `tests=1 assertions=20` | `tests=1` · **`assertions=21`** |
| `transversais` | `tests=12 assertions=258` | `tests=12` · **`assertions=259`** |

➡️ **A mudança é legítima** (o `+1` é o `assertFileExists` do **terceiro** expositor, que a própria
estrutura do gate cobra por expositor). ⚠️ **O que a próxima wave NÃO deve fazer é tratar
`21`/`259` como regressão** — o que está velho é a referência da régua, não o gate. ⚠️ **E não
deve "consertar" a régua tampouco:** `193-REGUA.md` e os `193-NN-PLAN.md` não pertencem a plano
nenhum. **Dono: usuário/orquestrador.**
