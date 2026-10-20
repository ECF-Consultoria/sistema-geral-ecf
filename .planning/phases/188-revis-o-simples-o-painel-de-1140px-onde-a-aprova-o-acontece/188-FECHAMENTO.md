# Fase 188 — O FECHAMENTO MEDIDO

> ⚠️⚠️ **Este documento é MEDIÇÃO DATADA, não gate perene.** Cada número vale para o commit escrito
> ao lado dele e **não deve ser "atualizado" por sessão futura** — quem quiser o número de hoje,
> **mede hoje**. Os três gates PHP deste plano (`fechamento-territorio`, `fechamento-criterios`,
> `fechamento-manifestos`) exigem que o documento **exista** e conferem a **FORMA** das linhas; eles
> **não conferem nenhum número de suíte, de build ou de manifest**. Isso é de propósito: régua que
> acompanha o documento é régua que o executor seguinte ajusta.
>
> **Medido em 19-20/10/2026**, na máquina de desenvolvimento (`C:/xampp`), pelo executor do plano
> **188-13**.
>
> ⚠️ **A ORDEM REAL DESTA SESSÃO, porque ela é a procedência de tudo o que vem abaixo:**
> `git rev-parse` (a **PARTIDA**, `cc98a86d`, primeira ação) → leitura dos 12 SUMMARYs, da
> `188-REGUA.md`, do `188-FID01.md`, do `188-BASELINES.md`, do `188-COBERTURA.md` e do
> `187-FECHAMENTO.md` → contagem dos critérios → protótipo → território (anti-fantasma, duas
> passadas, iscas) → homônimo → `--autoteste` → **escrita e COMMIT dos gates** → **só então** as
> suítes, o build e o manifest → mutação → este documento.
> ⚠️ **Os gates foram commitados ANTES de medir**, para que `COMMIT:` e as duas contagens de suíte
> sejam do **mesmo HEAD**. Os commits posteriores a ele são **SOMENTE DOCUMENTO** (`.planning/` e
> `learnings`), e por isso **não podem mover** as contagens — os três ids deste plano conferem
> **forma**, sobre listas fixas, e não dependem do texto do documento.

---

## ⚠️⚠️ §0 — A FASE **NÃO ESTÁ DEPLOYADA**, e isso muda o que este documento pode afirmar

⚠️⚠️ **A Fase 188 está entregue no CÓDIGO e NÃO FOI DEPLOYADA.** Nada nesta fase deu `push`, rodou
`deploy.sh` ou tocou no VPS — e **nenhum executor dos 13 planos o fez**.

| fase | estado | procedência do estado |
|---|---|---|
| **186** | **em produção** | commit `a4c4187f` — registrado pela `188-REGUA.md` §1; ⚠️ **não medido por mim** (leitura de produção não foi tentada nesta sessão) |
| **187** | **em produção (18/10/2026)** | commit `7791d7aa` — idem, medido pelo orquestrador na VPS com autorização do usuário, **não por este executor** |
| **188** | ⚠️ **Entregue no CÓDIGO (20/10/2026) — NÃO DEPLOYADO** | medido por mim: `git log` local, 64 commits de escopo `(188…)`; **zero** comando de deploy nesta sessão |

➡️ No **Coverage Map** do `ROADMAP.md`, a Fase 188 é **"Entregue no CÓDIGO (20/10/2026) — ⚠️ NÃO
DEPLOYADO"**. ⚠️⚠️ **Esta forma é DELIBERADAMENTE DIFERENTE da das Fases 186 e 187**, que dizem *"em
produção"*. Copiar a forma delas afirmaria um deploy que não houve — e é a ameaça **T-188-C5**
(Repudiation) do próprio plano. **Há gate sobre isto** (`fechamento-territorio`).

⚠️⚠️ **E A CONFERÊNCIA VISUAL DA FASE 187 (A FILA) CONTINUA ABERTA.** Ela é **em produção, na conta
459**, roteiro de 8 itens em `187-FECHAMENTO.md` §11. ⚠️ **Esta fase não a substitui e não a presume
feita** — e há gate exigindo que `PERGUNTA-VISUAL-187` continue `ABERTA` neste documento.

⚠️ **Esta fase NÃO tem migration**, e `artisan migrate` **não foi e não deve ser** rodado (há
migration pendente de **outra** milestone na árvore).

---

## ⚠️⚠️ §1 — NENHUM EXECUTOR DESTA FASE VIU TELA RENDERIZADA

Esta seção abre o documento, antes de qualquer número, pela mesma razão que abriu os das Fases 186 e
187: sem ela as 12 SUMMARYs desta fase **parecem relatos de tela**, e **não são**.

⚠️⚠️ **Todo render desta fase foi `esbuild` + `react-dom/server`. Onde esta fase diz *"a tela mostra
X"*, leia **"o HTML gerado contém X"**.**

| fato | como foi medido | quem mediu | consequência |
|---|---|---|---|
| o banco local **não tem empresa com `ml_token`** → a rota de Publicações dá **404** | `meus()` faz `abort_unless(…, 404)` | **os executores das waves 01-04** — eu **NÃO** pude reconfirmar (ver abaixo) | nenhuma página da fase abre nesta máquina |
| **`pub_aprovacao_ofertas` está VAZIA**, e **nada nesta fase cria oferta** | contagem no banco local pelas waves; quem cria a primeira é a **Fase 194** | waves 01-04 | **a fila nasce vazia, e isso é HONESTO** (decisão **P3** do usuário) — **não** é defeito |
| **não há navegador** | todo render é `esbuild` + `react-dom/server` com fixture sintético; todo CSS é o construído em `public/build/assets/*.css` | **medido por mim** | nenhum `useEffect`, `setTimeout`, `onKeyDown` ou `onClick` do browser rodou |
| a suíte PHP roda em **SQLite em memória** | `phpunit.xml` | **medido por mim** | armadilha de MariaDB não aparece aqui — e **esta fase não tem migration**, então não há o que aparecer |
| **o `mysqld` local não está em execução** nesta sessão | `tasklist \| grep mysqld` → **nada** | **medido por mim** | **não há banco local a consultar**: registro os dois primeiros fatos **com a fonte declarada**, em vez de afirmá-los como medição minha |
| a **leitura de produção** não foi tentada | o classificador barra `plink`/`tinker` e barra o MCP em subagente; e esta fase **proíbe** tocar o VPS | **medido por mim** (nenhum comando de produção foi emitido) | **nenhum número desta fase veio de produção** |

➡️ E as limitações nomeadas, que **seguem valendo** e aparecem como **RESSALVA** nos critérios:

- **O runtime dos atalhos de teclado e das transições NÃO é provado.** Provadas, pela **função
  pura**: a escolha do próximo id (pulando `preparando`), as pontas nulas, o `Esc`, o clique fora e a
  duração por `style`. O que acontece no navegador **não foi observado por ninguém**. ⚠️ A premissa
  que o runtime não podia provar foi **TRAVADA** no gate (188-07), não descartada.
- **Nenhum clique de browser foi disparado.** Onde o clique importava, o handler foi **exportado
  como função pura** e exercido pelo gate (padrão da 187-08/09) — o que prova a **função**, não o
  **evento**.
- **Nenhuma oferta de verdade foi aberta.** Todo dado é **fixture** (factory/seed de teste).

---

## §2 — AS 6 LINHAS DE FORMA FIXA, **RE-MEDIDAS NESTA SESSÃO**

⚠️ **RE-MEDIDAS, não copiadas das 12 SUMMARYs nem do `188-BASELINES.md`.** O fechamento da Fase 186
teve de refazer exatamente isto: *"um fechamento que afirma número velho é pior que um fechamento sem
número."*

```
PARTIDA:   cc98a86d383371ceaba1d46531d63002b68460a7   (curto: cc98a86d) — primeira acao da sessao
COMMIT:    e7ad966b          <- as medicoes de suite/build/manifest abaixo rodaram NESTE HEAD
BUILD:     exit 0 em 43 s
MANIFEST:  495 entradas / 202 paginas / isca ausente / controle presente
JS:        5848 / 5847 pass / 1 fail / 0 skipped
PHP:       2577 / 23666 / 2
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

⚠️ **E o RECORTE do diretório, medido separadamente para ser comparável com a wave 12:**
`tests/Feature/Publicador/Aprovacao` → **328 testes / 5686 asserções / 0 falhas** (a wave 12 mediu
**319 / 5616 / 0**). ➡️ **O delta é `+9 testes / +70 asserções`, que é EXATAMENTE o que este plano
acrescenta** (os 9 métodos em três ids de `24 + 26 + 20 = 70` asserções) — **e nada mais**.

### A procedência de cada linha — qual comando, em que commit

| linha | comando exato |
|---|---|
| `PARTIDA` | `git rev-parse --short HEAD` — **a primeira ação da sessão**, antes de qualquer leitura |
| `COMMIT` | `git rev-parse --short HEAD` **depois** do commit dos gates deste plano |
| `BUILD` | `T0=$(date +%s); npm run build; echo $?; T1=$(date +%s); echo $((T1-T0))` |
| `MANIFEST` | `node <script>-188-13.cjs public/build/manifest.json` — **pelo JSON**, nunca por `grep` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` |
| `PROTOTIPO` | `find . -maxdepth 3 -name Aprovacao.dc.html` → `test -e` → `wc -l` → `sha256sum` |

⚠️ **O `MANIFEST` é lido pelo JSON, e a razão é MEDIDA**: o hash do bundle **pode sair com hífen**
(na abertura da fase o `app` saiu `assets/app--5yuaubs.js`), e um `grep -E "app-[A-Za-z0-9]*\.js"`
**não casaria**. ⚠️ **Nesta rodada ele NÃO saiu com hífen** (`assets/app-CgmGEINw.js`) — o que **não
enfraquece a regra, fortalece**: o hífen é **do sorteio do build**, então um `grep` que funcione hoje
**quebra amanhã sem ninguém mexer em nada**. `node --input-type=commonjs` (ou arquivo `.cjs`), porque
o `package.json` é `"type":"module"`. `sha256` do `manifest.json` desta rodada: **`9c909eba3f848466`**.

| sonda do manifest | resultado |
|---|---|
| **ISCA** `resources/js/Pages/Mlb/NaoExiste9z7.jsx` | **ausente** ✓ (se saísse presente, a leitura estaria cega) |
| **CONTROLE** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` | **presente** ✓ — é a prova de build de que a página do painel **existe e compila** |
| **CONTROLE** `resources/js/app.jsx` | **presente** ✓ |
| `resources/css/app.css` **como chave** | **ausente** — e está **certo** (divergência **N5** da 187, reconfirmada) |

⚠️⚠️ **Página React fora do manifest SOME da aplicação, sem erro nenhum.** É por isso que o controle
positivo existe: um manifest que perdesse a `Aprovacao.jsx` passaria num gate que só contasse
entradas.

### ⚠️ O protótipo **não se moveu** — e o diretório é CITADO

`find . -maxdepth 3 -name Aprovacao.dc.html` devolve **só**
`./design_handoff_publicador/Aprovacao.dc.html`. ⚠️ **Há DOIS pacotes de handoff na árvore**
(`design_handoff_publicador/` e `new_layout_publicador/`) **com conteúdo diferente** — por isso o
diretório é sempre citado.

`sha256` completo: `68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2`; **1432**
linhas por `wc -l` **e** por contagem de registros (`NR`). ➡️ **A tabela FID-01 inteira continua
medindo o MESMO documento**, nas três medições da Fase 187 e nas sete desta fase.
⚠️ **Nenhum dos dois pacotes é versionado (`??`)** — gate que dependa de lê-los **degrada para `skip`
EXPLÍCITO e NOMEADO**, nunca para "conferido".
⚠️ **E `split(/\r?\n/)` sobre este arquivo dá 1433, não 1432** (a última linha termina com `\n`, e o
`split` produz um elemento vazio no fim). Medido na fase; é por isso que a contagem oficial é `wc -l`.

### A tabela mecânica contra o `188-BASELINES.md` — uma linha por número

Referência: abertura da fase, commit **`db9b1ff9`**, 19/10/2026 (`188-BASELINES.md` §1).
⚠️ **ACIMA é ESPERADO** (as 12 waves acrescentam testes, e a sessão paralela também); **ABAIXO é
regressão.** A referência **não se "ajusta"**.

| número | abertura (`db9b1ff9`) | re-medido (`e7ad966b`) | veredicto | razão |
|---|---|---|---|---|
| `BUILD` exit | 0 | **0** | **IGUAL** | — |
| `BUILD` segundos | 79 s | **43 s** | **INFORMATIVO** | tempo é da máquina, não régua |
| `MANIFEST` entradas | 495 | **495** | **OK — IGUAL** | ⚠️ **ACHADO: os 19 `.jsx` desta fase produziram ZERO entradas novas** — eles são **COMPONENTES**, não pontos de entrada do Vite; entram **dentro do chunk da página**. Ver abaixo |
| `MANIFEST` páginas | 202 | **202** | **OK — IGUAL** | esta fase **não cria página**: a `Aprovacao.jsx` é da **Fase 187** |
| `MANIFEST` isca | ausente | **ausente** | **OK** | a leitura não está cega |
| `MANIFEST` controle | presente | **presente** | **OK** | a página do painel **compila** → `assets/Aprovacao-R2BalJaL.js` |
| `JS` testes | 5489 | **5848** | **OK — ACIMA** | **+359**: os 10 `.test.js` das waves 01 e 07-12 mais o crescimento por construção do `vocabulario-novo` (de 548 para 681) |
| `JS` passando | 5488 | **5847** | **OK — ACIMA** | — |
| `JS` falhas | 1 | **1** | **OK — IGUAL** | a **mesma**, pré-existente da **Fase 87**, nomeada por SEMÂNTICA e **atribuída por execução ISOLADA** em §2.1 |
| `JS` skipped | 0 | **0** | **OK** | nenhuma camada dormindo |
| `PHP` testes | 2493 | **2577** | **OK — ACIMA** | **+84**: os 9 arquivos de teste da fase, os 9 métodos deste plano e os da sessão paralela |
| `PHP` asserções | 21.915 | **23.666** | **INFORMATIVO** | ⚠️ **não é invariante nesta suíte** — ver abaixo |
| `PHP` falhas | 0 | **2** | ⚠️ **RESSALVA — oscilação CONHECIDA** | ⚠️ **são EXATAMENTE as DUAS da Fase 179** (`CockpitDaContaTest`, fronteira de dia), **e o dia virou**: a abertura mediu em **19/10** e esta medição é de **20/10**. **Atribuídas por execução ISOLADA** (`2 failed, 7 assertions`), nas linhas `:966` e `:1318`. ⚠️ **Nenhum OUTRO teste falhou** — e passar de 2, ou falhar um teste diferente, **seria da fase** |
| `PROTÓTIPO` linhas | 1432 | **1432** | **OK** | não se moveu |
| `PROTÓTIPO` sha256 | `68624883ce35547e` | **`68624883ce35547e`** | **OK** | não se moveu |

⚠️⚠️ **O ACHADO DO MANIFEST, e ele vale registrado porque contradiz a expectativa óbvia:** eu esperava
**+19** entradas, uma por `.jsx` novo. **Medido: ZERO.** O `laravel-vite-plugin` declara como *inputs*
só `app.jsx` e `app.css`; as **páginas** aparecem porque são importadas dinamicamente; e **componente
aninhado é FUNDIDO no chunk da página**, sem chave própria. ➡️ ⚠️ **Consequência para quem escrever
gate: "o componente novo está no manifest" é um invariante FALSO neste projeto.** O que o manifest
prova é que **a PÁGINA existe e compila** — e é por isso que o controle positivo é a
`Aprovacao.jsx`, não um dos 19.

⚠️ **A suíte PHP INTEIRA foi medida, não só o diretório** — a linha `PHP:` é `tests/Unit/Publicador` +
`tests/Feature/Publicador` pelo `--suite php`, e o recorte de `Aprovacao` está acima. **As duas são
comparáveis só com a sua própria referência.**

⚠️ **O número de ASSERÇÕES do PHP não é invariante nesta suíte.** O `188-BASELINES.md` §2.1 já o
mediu e provou: os dois casos de **fronteira de dia** percorrem **ramos diferentes** conforme a hora,
e ramo diferente exercita número diferente de asserções. ➡️ **Compare TESTES e FALHAS; trate
asserções como informativo.** O harness faz exatamente isso: tem `REF_PHP_TESTS` e **não** tem
referência de asserções. ⚠️ **E NÃO existe `REF_PHP_FALHAS` — ele não deve passar a existir.**

---

## §2.1 — ⚠️⚠️ AS **QUATRO** FALHAS QUE VÃO E VÊM SEM NINGUÉM MEXER EM CÓDIGO

⚠️⚠️ **DUAS das quatro APARECERAM na medição deste plano — e isso CONFIRMA o diagnóstico em vez de
contradizê-lo.** A abertura da fase mediu `0` falhas em **19/10**; esta medição é de **20/10**, e as
duas que voltaram a vermelho são **exatamente** as de **fronteira de dia**. **O dia virou.** As
outras duas (as do Portal) **não apareceram** nesta rodada. Elas estão nomeadas aqui porque **vão
poluir toda baseline futura**, e porque a régua de quem medir depois depende de saber quais são.

| # | o teste, por SEMÂNTICA | arquivo | fase dona | classe | dono do conserto |
|---|---|---|---|---|---|
| 1 | *publicacoes criadas mede as duas janelas e a serie de trinta dias* (`:941`, asserção em `:966`) | **`tests/Feature/Publicador/CockpitDaContaTest.php`** | **179**, em produção | **fronteira de dia** | quem mantiver a Fase 179 |
| 2 | *evento sem data vai para o ultimo grupo nunca para o topo* (`:1308`, asserção em `:1318`) | **idem** | **179**, em produção | **fronteira de dia** | quem mantiver a Fase 179 |
| 3 | um caso de `SincronizaPortalCompletoTest` | `tests/Feature/…/SincronizaPortalCompletoTest.php` | **outra sessão** | **ordem / corrida** | quem mantiver aquela suíte |
| 4 | outro caso de `SincronizaPortalCompletoTest` | idem | **outra sessão** | **ordem / corrida** | idem |

⚠️⚠️ **O CAMINHO `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE — é
FANTASMA.** Conferido nesta sessão com `test -e`: o real é
**`tests/Feature/Publicador/CockpitDaContaTest.php`**, **fora** de `Aprovacao/`. É o **nono** dos dez
caminhos fantasmas desta milestone, e ele **veio de briefing do orquestrador** — a razão de a régua
exigir `test -e` em **cada** caminho citado, **inclusive os citados pelo orquestrador**.

**A prova de que as duas primeiras não são de código, e ela é medida, não presumida:**
`git log --name-only` entre as medições das waves **não acusa nenhum commit tocando `tests/`** nos
intervalos em que o veredicto delas mudou. Elas foram **vermelhas 16/10, verdes 17/10, vermelhas
18/10, verdes 19/10 e vermelhas OUTRA VEZ em 20/10** — **sem uma linha de código mudar**. O arquivo
consulta o relógio **52-53** vezes, e um dos últimos commits dele é literalmente *"ancora o relogio
no caso de agrupamento da Atividade"*: alguém já consertou **um** caso desta família e deixou
**estes dois**.

⚠️ **ATRIBUÍDAS POR EXECUÇÃO ISOLADA nesta sessão** — e isto é o que separa *"é delas"* de *"é
interação com a minha fase"*:

```
artisan test --filter="test_(publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias
                           |evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo)"
  Tests:  2 failed (7 assertions)
  a asserção que estoura:  CockpitDaContaTest.php:1318
    assertSame(['hoje', now()->subDays(3)->toDateString(), 'sem_data'], array_column($grupos,'chave'))
```

➡️ **Elas falham SOZINHAS.** ⚠️ **Não são regressão desta fase, e NÃO foram corrigidas** — é arquivo
de **outra fase, em produção**, e o conserto certo é de quem for dono daquela suíte.

**O conserto certo (recomendação, não execução):** ancorar o relógio nos dois casos
(`Carbon::setTestNow` no corpo do teste), **sem mexer no serviço**.

### ⚠️ As duas de `SincronizaPortalCompletoTest` são de ORDEM, e a hipótese do listener é HIPÓTESE

A wave 188-07 mediu **11 falhas** numa rodada, e **10 delas passavam em ISOLAMENTO**: é **corrida de
`storage/framework/testing`** entre **duas suítes PHPUnit simultâneas** (ver §14, item 7).
⚠️ **A hipótese de que a causa é um listener** — três arquivos registram, só um chama
`flushEventListeners()` — **é HIPÓTESE, e os dois experimentos que a testariam saíram VERDES.**
➡️ **NÃO a afirme como diagnóstico.** ⚠️ **E `$this->setUp()` chamado no meio de um caso derruba o
`RefreshDatabase`** — falha de ordem **nem sempre** é sensibilidade de listener.

### A única falha JS — pré-existente da Fase 87, aceita pelo harness

```
not ok … - Características secundárias nasce recolhido (é o grupo que mais infla)
```
➡️ `tests/js/estrutura-grade-glide.test.js:122`, localizada por **SEMÂNTICA** (`grep -rn` do título
do `test()`), nunca por número de linha. ⚠️ **ATRIBUÍDA POR EXECUÇÃO ISOLADA**: o arquivo sozinho
reporta **19 testes / 18 passando / 1 falhando**, o que prova que a falha é **dele** e não de
interação. **Não corrigir** — é o `REF_JS_FALHAS=1` cravado no harness, e o arquivo está na lista de
**ALVO-INTOCADO** (`numstat` zero, medido).

### ⚠️ A RÉGUA, para quem medir depois

1. **Falhas PHP `0`** foi o estado medido na abertura (19/10); no fechamento (20/10) foram **`2`**, e
   **as duas são as de fronteira de dia**. ⚠️ **O alvo continua `0`** — o `2` é **declarado**, não
   aceito como limiar.
2. Se uma das **quatro** da tabela voltar a vermelho, **é a oscilação conhecida**: registre e siga.
3. ⚠️ **Falha em QUALQUER OUTRO teste, ou mais de 4 nesta família, É DA FASE** — investigue, não
   registre. ⚠️ **E atribua por EXECUÇÃO ISOLADA**, nunca pela suíte inteira.
4. ⚠️ **Testes PHP abaixo de 2493, ou JS abaixo de 5489, é regressão**, sempre.
5. ⚠️⚠️ **NÃO criar `REF_PHP_FALHAS`.** O harness declara, na própria fonte, que esse limiar **não
   deve passar a existir** — criá-lo para calar estes dois casos seria **abrir a porta dos fundos que
   o harness existe para fechar**.

---

## §3 — O TERRITÓRIO, PELOS DOIS CAMINHOS

### ⚠️⚠️ §3.0 — O AUTOR NÃO DISCRIMINA. O ESCOPO DO ASSUNTO DISCRIMINA.

```
COMMITS_DO_ASSUNTO = 64   (assunto `feat|fix|test|docs(188)` ou `(188-NN)`)
ESCOPOS VIZINHOS QUE **NAO** ENTRARAM = coordenacao(11), v27(10), learnings(10), 187-NN,
                                        186-NN, 184, polos, 189
```

⚠️ `git log --format=%an` devolve **um valor só** (`MB.ECF-100376`) para as três máquinas desta
árvore. Um gate comparando `%an` é **vazio por construção** aqui — é a família *"gate vazio por
construção"* do learnings. ➡️ **O autor não foi conferido, de propósito.**

⚠️⚠️ **E O ESCOPO DO ASSUNTO TEM UM LIMITE MEDIDO, que registro em vez de esconder:** o commit
`091340b6` **é trabalho desta fase** (o reaponte dos dois gates de total fixo do `186-01`) e carrega,
**de propósito**, o escopo `(186-01)`, porque edita um gate daquela fase. ➡️ **Ele NÃO é capturado
pelo filtro `(188…)`.** A conclusão não muda — ele não toca alvo vetado nenhum, e está declarado em
§9 —, mas *"escopo do assunto"* **não é completo**: é **melhor** que o range, não **perfeito**.

### ⚠️ §3.1 — PROVA ZERO: o RANGE CRU, medido — e nesta fase ele **NÃO acusa**

Rodei de propósito o que o plano da 187-11 prescrevia como "Prova 1":

```
COMMITS_NO_RANGE (db9b1ff9..HEAD) = 64
DO_ASSUNTO_NO_RANGE              = 59
ALHEIOS_NO_RANGE                 =  5   (coordenacao x3, 189 x1, 186-01 x1)
RANGE_CRU_ALVOS_ACUSADOS         =  0
```

⚠️⚠️ **ACHADO, e ele CORRIGE o briefing desta sessão:** o orquestrador disse *"nesta fase os ranges
trouxeram 2 a 3 alheios por wave"*. **Medido no range da fase inteira: 5 commits alheios, e ZERO
alvos acusados** — muito melhor que na Fase 187, onde o range tinha 111 commits, **43 alheios**, e
acusava **12 dos 60 alvos**.
➡️ ⚠️ **Mas o range continua NÃO SERVINDO como gate, e a razão é de construção, não de resultado:**
ele sai limpo aqui **por sorte do que a sessão paralela escolheu tocar nestes dois dias**, não por
desenho. No dia em que ela mexer no Creative Engine dentro do range, o gate fica vermelho por
trabalho alheio, e o conserto barato passa a ser **tirar alvos da lista** ou **estreitar o range** —
exatamente o treinamento que o learnings §4b.2 manda evitar. ⚠️ **Por isso o gate PERENE deste plano
não usa git nenhum**: ele é o **ANTI-FANTASMA**.

### ⚠️ §3.2 — PROVA 1 e PROVA 2: por ESCOPO DO ASSUNTO, duas passadas independentes

```
ALVOS = 53   FANTASMAS = 0   COMMITS_DO_ASSUNTO = 64
PASSADA1_VIOLACOES = 0   (laco com `git show --name-only --format=` por commit)
PASSADA2_VIOLACOES = 0   (uma passada de `git log --name-only --format='@@%h %s'` + awk)
```

⚠️ **`test -e` em CADA um dos 53 alvos ANTES de qualquer diff** — o anti-fantasma vem primeiro,
sempre, porque `git diff -- <caminho inexistente>` sai **VAZIO** e vazio é **indistinguível de
"intocado"**.

⚠️ **ISCA NAS DUAS DIREÇÕES** (sem ela, *"0 violações"* é indistinguível de sonda morta — e a
pergunta *"o que precisaria estar errado no mundo para este gate falhar?"* teria a resposta
**"nada"**, que é ACHADO):

| isca | medida | leitura |
|---|---|---|
| **A** — pôr no veto um alvo que a fase **TOCOU** (`MlbPublicadorAprovacaoController.php`) | disparou em **6** commits da fase | a varredura **VÊ** |
| **B** — rodar o veto contra os commits **ALHEIOS** (os de fora do escopo, nos últimos 400) | **161** acertos | o veto **CASA** o que ele descreve |
| **C** — `test -e` num caminho inventado (`app/Mcp/NaoExiste9z7/`) | **ausente** | o anti-fantasma **VÊ** |

⚠️ **A contagem de asserções do gate de território NÃO depende de quantos commits existem:** ele
**não chama git**. O anti-fantasma é **UMA** asserção coletando os fantasmas; os arquivos da fase são
**lista CONSTANTE**; e a prova de que a lista não está vazia é asserção **coletada**, nunca `return`
antecipado. ➡️ **A contagem é a MESMA com zero e com N commits no escopo** — era a **nona** ocorrência
da família *"contagem que depende de quantos arquivos/commits existem"*, consertada na raiz na 187-07.

### ⚠️⚠️ §3.3 — OS CAMINHOS FANTASMAS: **DEZ** na milestone, e **TRÊS** de briefing do orquestrador

| # | caminho CITADO (fantasma) | caminho **REAL**, medido |
|---|---|---|
| 5 | `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` | **`app/Services/Publicador/Fila/ResumoRapidoService.php`** |
| 6 | `app/Services/Publicador/SimuladorVoceRecebe.php` | **`app/Support/Publicador/Validacao/SimuladorVoceRecebe.php`** |
| 7 | `app/Services/Publicador/AnaliseAlavancasService.php` | **`app/Services/Publicador/Alavancas/AnaliseAlavancasService.php`** |
| 8 | `resources/js/Pages/Mlb/Publicador/useCriativosDoPublicador.js` | **`resources/js/Components/Publicador/useCriativosDoPublicador.js`** |
| 9 | `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` | **`tests/Feature/Publicador/CockpitDaContaTest.php`** |
| 10 | achado pela própria sonda da wave 188-06 | registrado na §P do `188-FID01.md` |

⚠️⚠️ **O MAIS PERIGOSO É O #5, E ELE SE REPETIU NESTA FASE:** a wave **188-06** escreveu
`…/Aprovacao/Fila/ResumoRapidoService.php` **na própria lista de vetados**, *logo depois de ler o
aviso sobre ele*. ⚠️ **A confusão apagaria a DECISÃO 9 DO USUÁRIO:** `ResumoRapidoService`
**existe**, em `app/Services/Publicador/Fila/`, e **não é** o `FilaDeAprovacaoService` — é o serviço
da **visão rápida do lote**, aquele cujo `ResumoRapidoService:384-391` a decisão 9 cita como o lugar
onde a margem usa o **parâmetro da Precificação** (e não a tarifa real do ML). **São dois arquivos
com dois papéis; confundi-los apagaria uma decisão do usuário.**
⚠️ **Conferido nesta sessão, com `test -e` nos dois caminhos.**

⚠️⚠️ **E O BRIEFING DO ORQUESTRADOR ERROU DUAS VEZES NESTA FASE, as duas corrigidas por MEDIÇÃO dos
executores — registro porque a instrução do orquestrador é tão falível quanto o resto:**
1. ele leu `COR:Azul` **dentro de um COMENTÁRIO** do protótipo e mandou derivar o rótulo dele;
2. ele disse **"quatro gates"** da família dos totais quando eram **CINCO** (o quinto é o **gêmeo em
   PHP**, que só apareceu quando a suíte PHP terminou).
➡️ **E nesta wave ele errou uma terceira:** a lista de commits do conserto dos dez gates omite
`61e84710` (ver §9). **A régua é `test -e` e `sed` em tudo — inclusive no que o orquestrador afirma.**

### §3.4 — A LISTA DOS 53 ALVOS (conferida no disco, uma por uma)

<!-- ⚠️ Linhas de FORMA FIXA: o prefixo abre a linha, e o gate `fechamento-territorio` as lê
     ancoradas em `^`. Menção no MEIO de uma frase NÃO conta. NÃO editar sem rodar
     `sh tests/gates/verificar-testes.sh 188-13 fechamento-territorio`. -->

ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/LinhaFinanceira.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/VocabularioDoPortao.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/Fila/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx
ALVO-INTOCADO: app/Services/Creative/
ALVO-INTOCADO: app/Services/Publicador/Criativos/
ALVO-INTOCADO: app/Http/Controllers/MlbPublicadorCriativoController.php
ALVO-INTOCADO: app/Console/Commands/LimparReferenciasCriativos.php
ALVO-INTOCADO: app/Models/MlAnuncioCriativoKit.php
ALVO-INTOCADO: resources/js/Pages/Mlb/AnunciarML.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/Publicador/Editor.jsx
ALVO-INTOCADO: resources/js/Components/Publicador/Mesa/
ALVO-INTOCADO: resources/js/Components/Publicador/useCriativosDoPublicador.js
ALVO-INTOCADO: app/Support/Publicador/
ALVO-INTOCADO: app/Support/Publicador/Validacao/SimuladorVoceRecebe.php
ALVO-INTOCADO: app/Support/Publicador/Imagem/ArquivosDeImagem.php
ALVO-INTOCADO: app/Services/Mlb/Publicacao/MlPublicacaoService.php
ALVO-INTOCADO: resources/js/Layouts/AppLayout.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
ALVO-INTOCADO: app/Services/Bling/
ALVO-INTOCADO: app/Services/Portal/
ALVO-INTOCADO: app/Mcp/
ALVO-INTOCADO: app/Services/Publicador/ImagemAssetService.php
ALVO-INTOCADO: app/Services/Publicador/Fila/ResumoRapidoService.php
ALVO-INTOCADO: app/Services/Publicador/DadosEfetivosService.php
ALVO-INTOCADO: app/Services/Publicador/Alavancas/AnaliseAlavancasService.php
ALVO-INTOCADO: app/Models/EstruturaPrecificacaoParametros.php
ALVO-INTOCADO: app/Services/Publicador/EditorRascunhoService.php
ALVO-INTOCADO: tests/js/estrutura-meus-anuncios.test.js
ALVO-INTOCADO: tests/js/publicador-header-zindex-ordem.test.js
ALVO-INTOCADO: tests/js/estrutura-grade-glide.test.js
ALVO-INTOCADO: database/migrations/
ALVO-INTOCADO: package.json
ALVO-INTOCADO: package-lock.json
ALVO-INTOCADO: composer.json
ALVO-INTOCADO: composer.lock
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/identidade/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/visaogeral/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/header/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/

⚠️ **`ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` NÃO estão na lista, e isso é DELIBERADO**
(GATE-07): eles **não pertencem a plano nenhum** — quem os edita é o orquestrador, e um gate de
território que os incluísse **acusaria o orquestrador**.
⚠️ **`tests/js/publicador-header-zindex-ordem.test.js` e `tests/js/estrutura-meus-anuncios.test.js`
(D-11/D-22) estão na lista, com `numstat` ZERO medido** — o critério 1 exige o teste de z-index
**intacto**, e ele está.

### §3.5 — T-188-SC: a fase **não instalou nada**, provado por DUAS vias

| via | medida |
|---|---|
| **FORMA** (o gate que vale, e não depende de git) | os **3** `.php` de produção usam **só** `App\` e `Illuminate\`; os **19** `.jsx` importam **só** `react`, `react-dom`, `@inertiajs/react`, o alias `@/` e caminhos relativos — **nenhum nome de pacote novo** |
| `numstat` no range | `package.json`, `package-lock.json`, `composer.json`, `composer.lock` → **VAZIO nos quatro** |

⚠️ **A via da FORMA é a que vale como gate:** `numstat` de range **não discrimina a sessão paralela**;
o import está **dentro** dos arquivos desta fase. ⚠️ **E nenhum `npm install`/`composer require` foi
emitido nesta sessão** — se um pacote tivesse falhado, a régua manda **PARAR e devolver um
checkpoint de verificação humana**, nunca instalar um nome parecido.

### §3.6 — A contagem FIXA de arquivos da fase, medida **UMA vez AQUI**

⚠️ **UMA asserção, aqui** — e **não** uma por plano: contagem que sobe a cada wave é frágil por
construção e **treina o executor seguinte a ajustar réguas quando elas incomodam**.

| medida | número | como |
|---|---|---|
| arquivos **novos** nos 64 commits do assunto | **82** | `git show --diff-filter=A --name-only` por commit, `sort -u` |
| ↳ `.php` | **12** | **3 de produção** + 9 de teste |
| ↳ `.jsx` | **19** | **todos de produção**, todos em `aprovacao/` |
| ↳ `.md` | **29** | planejamento (13 planos, 12 SUMMARYs, RÉGUA, FID01, BASELINES, COBERTURA) |
| ↳ `.txt` | **12** | os manifestos `contagens-188-01..12.txt` |
| ↳ `.js` | **10** | **todos em `tests/js/`** |
| `.jsx` no diretório `aprovacao/` | **40** | 21 (Fases 186/187) + **19** (Fase 188), `deepEqual` contra o disco |

⚠️⚠️ **O INVARIANTE DOS `.js` É *"nenhuma PEÇA nova sob `resources/` é `.js`"*** — e **não**
*"nenhum arquivo novo é `.js`"*, que a 187-11 mediu ser **literalmente FALSO**. Medido nesta fase:
há **10** arquivos `.js` novos, e **todos são testes** (`tests/js/*.test.js`), que o `content` do
Tailwind **não varre e não precisa varrer**. ➡️ Escrever o invariante errado seria **gate mais largo
que o invariante** — a família de **catorze** ocorrências desta fase (§14, item 10).

---

## §4 — OS **20** CRITÉRIOS DE SUCESSO, UM POR UM

⚠️⚠️ **São 20, CONTADOS POR MIM** no `ROADMAP.md`, em 20/10/2026:

```
awk '/^### Phase 188:/,/^### Phase 189:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "
  -> 20
numeros extraidos: 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20   (sem buraco, sem repeticao)
```

⚠️ **E a isca do `awk`, medida**: o mesmo comando sobre a Fase **187** devolve **15** e sobre a
**186** devolve **17**. As três entradas são **vizinhas** no arquivo (linhas 4376, 4485, 4594, e a
189 em 4671), e um `awk` com a fase errada traz a contagem errada **em silêncio**. **Minha medição
bate com a do planejador: 20.**

⚠️⚠️ **A FORMA FIXA É O QUE IMPEDE O "AJUSTE" SILENCIOSO.** Critério que não passa recebe
**RESSALVA** ou **REPROVA** **com a razão e com o dono** — e **nunca é reescrito para caber**. (Na
Fase 186 o critério 7 **foi** reescrito, e com razão declarada: ele **contradizia o 6** e era
**aritmeticamente impossível**. ⚠️ **Nada dessa natureza apareceu na 188** — eu procurei: os 20
critérios são mutuamente consistentes e nenhum é aritmeticamente impossível. Se aparecesse, estaria
escrito aqui e **devolvido ao usuário**, não consertado por conta própria.)

CRIT-01: OK — o painel é `w-[min(1140px,100vw-100px)]` com `#0D0D0D`, borda esquerda `#2E2E2E`, sombra `-24px 0 64px rgba(0,0,0,0.6)` e backdrop `rgba(0,0,0,0.55)`, desenhado **POR PORTAL** (`createPortal` com guarda `typeof document === 'undefined'`, porque o render de SERVIDOR não desenha portal e o teste veria **vazio e passaria POR OMISSÃO**) e **acima do header sem alterá-lo**: `backdrop z-[39] < painel z-[40]`, remapeados pela **ORDEM RELATIVA** e nunca copiados do protótipo (lá é 69/70), com `publicador-header-zindex-ordem.test.js` **intacto** (`numstat` ZERO, medido) e VERDE — gate casca-do-painel, 45,41 + gate tokens-e-tamanhos, 9
CRIT-02: RESSALVA — `Esc` e clique fora fecham, `↑/↓` navegam a fila **pulando `preparando`** (a lista vem do SERVIDOR em `navegacao.ids`, nunca filtrada no cliente), `←/→` trocam a foto, e o topo de 54px traz **"REVISÃO SIMPLES"**, **"x de N na fila"**, **↑ ↓** e **×**; ⚠️ **a ressalva é DECISÃO DO USUÁRIO, não achado meu:** o **quinto** item do critério — o botão **"Abrir revisão detalhada ↗"** (`:558`) — **NÃO é desenhado nesta fase**, porque a tela de destino não existe (PERGUNTA-188-B3, 18/10/2026; *"desenhar desabilitado com explicação"* foi **OFERECIDO e RECUSADO**; **dono: Fase 190/191**). ⚠️ **Segunda ressalva, de ambiente: o RUNTIME do teclado não é provado** — não há navegador; a **função pura** está provada e a premissa ficou **TRAVADA** no gate — gate navegacao-e-payload, 2/50 + gate casca-do-painel, 45,41
CRIT-03: OK — as duas colunas têm o **mesmo peso** (`grid-cols-2` de larguras iguais, fotos à esquerda e preço à direita), e **não foram reequilibradas**: é decisão de desenho declarada no README, com gate ancorado na **igualdade das duas medidas** e isca do quase-igual (uma coluna maior reprova) — gate casca-do-painel, 45,41
CRIT-04: OK — a coluna de fotos traz **"x de y aprovadas"** de **UMA contagem só** (nunca uma segunda definição), a imagem **1:1** com **#n**, **★ PRINCIPAL**, **✦ Gerada por IA**/**Original**, o estado com rótulo **NUNCA vazio** (chave desconhecida cai no neutro), os **marcadores numerados em PERCENTUAL** (`decimal(5,2)`, saneados e clampados nas duas pontas), a grade de **6 colunas** por `repeat(6,minmax(0,1fr))` com borda de **CINCO** ramos e precedência provada por **CONFLITO**, e as verificações automáticas sob o cabeçalho **"VERIFICAÇÃO AUTOMÁTICA · APOIO"** escrito **UMA vez**, com **o número do marcador** em cada item (PERGUNTA-188-A, opção (c), usuário, 18/10/2026) — gate galeria-e-marcadores, 3/84 + gate borda-e-estados, 3/59 + gate verificacoes-e-ordinal, 3/113 + gate coluna-de-fotos, 32,28
CRIT-05: OK — **"Aprovar N sem alerta"** aprova **só as pendentes SEM alerta automático**, percorrendo **TODOS os grupos** do rascunho (`PubAprovacaoConjunto::doRascunho()`, com fixture de 2+ grupos), a contagem do botão é **IGUAL** ao que a ação aprova (mutação que contava as pendentes COM alerta **MORREU**), e a tela **diz quantas continuam a conferir** — decisão 8 do usuário: *"tudo de uma vez, e a tela diz quem ficou de fora"*; aprovar silenciosamente só as que passam foi **RECUSADO** — gate sem-alerta-e-contagem, 3/48 + gate imagem-aprovar-e-sem-alerta, 3/49 + gate coluna-de-fotos, 32,28
CRIT-06: OK — os **3 KPIs** na ordem com os rótulos **LIDOS dos serviços da Fase 186** (⚠️ **"Resultado estimado por venda"**, nunca *"lucro líquido"* — gate de ausência recortado à **lista CONSTANTE** dos arquivos da fase, porque varrer `app/` **barrou um deploy** na 186), o aviso de alteração não conferida, a **composição das 7 linhas em ORDEM EXPLÍCITA com selo de origem por linha** e nota **não vazia**, **"Informar frete confirmado"** quando falta, a **simulação com o selo "SIMULAÇÃO · NÃO SALVA"**, a comparação **clicável** com a irmã na ordem Clássico → Premium, a barra da margem por **FÓRMULA** com o traço no mínimo **REAL**, e a frase **"· com a tarifa real do Mercado Livre"** **LIDA** de `ComposicaoDaOferta` (decisão 9: há **DUAS** margens de propósito, e **não se unificam**) — gate kpis-e-incompleto, 3/101 + gate composicao-e-origem, 3/89 + gate coluna-de-preco, 48,44
CRIT-07: OK — a simulação **nunca é persistida nem enviada ao ML** (o serviço de preço **não escreve nada e não congela**, provado com isca), só pode ser salva com a oferta em `live`, e salvar **incrementa a versão**, **limpa o aviso** de alteração e **registra no histórico** — os três efeitos medidos **SEPARADAMENTE**, porque juntos um deles pode faltar e o teste passar — gate simulacao-sem-salvar, 3/77 + gate simular-sem-gravar, 2/25 + gate preco-salvo-e-versao, 3/38 + gate coluna-de-preco, 48,44
CRIT-08: OK — **"Aprovar para publicação"** sai com o **atributo `disabled=`** enquanto houver bloqueio — gate com `/disabled=/` e **nunca** `/disabled/`, que casaria `disabled:opacity-40` —, o `aprovavel` é **LIDO do servidor** e nunca recalculado no cliente (mutação que o recalculava **MORREU**), e a trava **REAL** é o servidor: `aprovar()` recusa nos três eixos mesmo chamando a rota direto — gate rodape-e-aprovavel, 2/43 + gate aprovar-e-enfileirar, 3/85 + gate pendencias-e-rodape, 42,37
CRIT-09: RESSALVA — **cada pendência tem o seu botão** e ele leva ao lugar certo: fotos abre **a primeira com alerta ou a primeira pendente** (`foco_imagem_id`), frete abre o modal de frete, **prejuízo e margem** abrem a exceção, e **alteração de preço confere SEM alterar o preço**; os **NOVE** destinos são resolvidos **no servidor** e a correção é **nula em TODAS fora do `live`**; ⚠️ **a ressalva é a PERGUNTA-188-B, decisão do usuário:** `ajuste_em_aberto` e `titulo_longo` têm `destino = null` e **SEM BOTÃO** enquanto a rota da revisão detalhada não existir (**o TEXTO do bloqueio continua aparecendo**) — **botão sem destino é D4 violado**; **dono: Fase 190/191** — gate pendencias-e-destinos, 2/45 + gate pendencias-e-rodape, 42,37
CRIT-10: OK — sem bloqueio o rodapé mostra o literal verde **"Fotos, preço e conteúdo conferidos"** (expectativa **CONGELADA no gate** por igualdade de STRING, com isca do quase-igual — ver §14, item 3); com bloqueio, o motivo **em vermelho** por `resumoDoRodape()`, que é **o primeiro abreviado + ` +N`** e **LIDO**, nunca remontado com o `join(' · ')` do protótipo (mutação que o remontava **MORREU**) — gate rodape-e-aprovavel, 2/43 + gate pendencias-e-rodape, 42,37
CRIT-11: OK — a exceção libera **SÓ `loss` e `margin`** e **recusa os seis outros** — com **isca POR BLOQUEIO**, não por lista de nomes (mutação que mexeu só no **comportamento** de `libera()`, sem tocar a constante, **MORREU**) —, exige **responsável + justificativa de pelo menos 10 caracteres** (`9/10/11` medidos, `>=` e não `>`), e fica no histórico como **"§ Exceção autorizada por X · data · justificativa"**; ⚠️ **"exceção aguardando autorização" NÃO EXISTE**, com gate ancorado no **sujeito** — gate excecao-travas, 3/40 + gate modal-de-excecao, 23,20
CRIT-12: OK — pedir ajuste move as ofertas afetadas para `changes` conforme o **escopo** (`offer` / `set` / `product`), com a caixa **"Encaminhar para"** mostrando o destino, e **resolver o último ajuste aberto devolve a oferta para `awaiting`** (provado nos **dois** sentidos, na MESMA fixture); `publicando` e `publicado` **não vão** para `changes`; escopo inválido, âncora incoerente e texto vazio **recusam sem gravar nada**; ⚠️ **premissa TRAVADA e declarada:** `pub_rascunhos.produto_id` é **UNIQUE**, então `set` e `product` alcançam **o mesmo conjunto hoje** — gate ajuste-por-escopo, 3/46 + gate resolver-ajuste, 2/30 + gate modal-de-ajuste, 27,23
CRIT-13: OK — reprovar imagem **exige motivo** (sem motivo **não envia**, e o piso do servidor é **não-vazio**; a tela cobra `> 2` e o cruzamento com o `>= 10` da justificativa da exceção está medido), tira a foto do conjunto final **na hora**, manda para **"Fora da publicação"**, **promove a próxima a principal se era a principal** e **reabre a aprovação de TODAS as ofertas do conjunto** — os **cinco** efeitos, cada um com isca; imagem de outra conta ou em `regenerando` é **recusada** — gate imagem-reprovar, 3/58 + gate modal-de-reprovar, 19,17
CRIT-14: OK — confirmar frete grava **valor + origem** (cotação / tabela do cliente / simulador; origem fora das três e valor inválido **recusam sem gravar**) e **incrementa a versão**; a **prévia do resultado e da margem aparece ANTES de confirmar**, **não grava** e bate com a composição **no ponto fixo** (a mutação que somava o frete **dobrado** MORREU); ⚠️ a prévia chega **PELO FLASH** do Inertia, não por `axios`/`fetch`, e a **prévia NEUTRA** (*"Informe o valor para ver o resultado"*) **TEM de aparecer** — ela *é* o estado "sem valor", e **nunca `R$ 0,00`**, que afirmaria frete de graça — gate frete-confirmado, 3/44 + gate modal-de-frete, 22,18
CRIT-15: OK — os quatro modais usam a **casca comum IMPORTADA** da Fase 187 (`CascaDeModal.jsx`: **560px**, topo **96px**, backdrop `rgba(0,0,0,0.65)`, `max-height: calc(100vh - 140px)`, raio 16px) — ⚠️ **NUNCA RECRIADA**: `numstat` ZERO nela, com gate de **ausência de segunda casca** procurando as **MEDIDAS** (duas cascas divergiriam, e a divergente seria descoberta por alguém vendo dois raios diferentes na mesma tela) —, e a raiz do portal **declara a fonte do módulo por conta própria** (`font-pub text-pub-txt`), porque **conteúdo portado NÃO herda fonte** (REND-05, medido na Fase 178); o submeter **VERMELHO** do MOD-04 foi obtido **COMPONDO os named exports**, com as duas alternativas (editar a casca / pôr o botão no `children`) **medidas e descartadas** — gate modal-de-ajuste, 27,23 + gate modal-de-excecao, 23,20 + gate modal-de-frete, 22,18 + gate modal-de-reprovar, 19,17
CRIT-16: OK — a revisão parcial fica **NO SERVIDOR** (**zero `localStorage`**, com gate de ausência e isca; filtros, oferta aberta e seleção vivem na **URL**), e salvar move `awaiting → ecf_review` **pela única porta** (`AndamentoDaOfertaService::mover`, nunca por `update`/`save` do campo) com o registro **"Salvou revisão · x de y fotos aprovadas"**, em que o `x de y` vem de **contagem de fotos** e não de número solto; salvar de novo em `ecf_review` **registra sem mover**, e fora do `live` **recusa** — gate salvar-revisao, 3/52 + gate pendencias-e-rodape, 42,37
CRIT-17: OK — a `version` viaja **SEMPRE** no corpo de toda escrita (`versao_esperada`; a mutação que a omitia **MORREU**), o servidor **recusa com 422** quando ela divergiu, e a tela **AVISA** em vez de sobrescrever em silêncio; ⚠️⚠️ **e isto só funciona por causa de um achado que salvou o critério:** as recusas são `response()->json(['message','regra'], 422)` **CRUAS**, e **o `onError` do Inertia NUNCA é chamado para elas** (medido em `@inertiajs/core` 2.3.21: a resposta sem o header `x-inertia` cai em `handleNonInertiaResponse()`, dispara `inertia:invalid` e **mostra o modal de erro bruto**) — sem o conserto, **o aviso NUNCA acenderia**; o helper ouve `invalid` **só pela duração da requisição**, lê o **CAMPO `regra`**, chama `onConflito` e **cancela o modal**, e **recusa sem `regra` continua mostrando o modal** (erro inesperado não pode virar silêncio) — gate preco-salvo-e-versao, 3/38 + gate aviso-de-versao, 12,10
CRIT-18: OK — **nenhum status do Mercado Livre na tela** (conferido por ausência, com isca) e **nada escreve no ML nesta fase**: o painel **APROVA, e aprovar ENFILEIRA** — a escrita no ML é da **Fase 193**, e a fronteira está **DECLARADA** no docblock do controller, no comentário das rotas e nos arquivos de teste; ⚠️ **a régua honesta NÃO é "zero requisição"**, e isto é desenho: as 3 rotas de imagem têm **zero requisição literal**, mas `aprovar`/`salvar-revisao`/`tentar-de-novo` **LEEM a tarifa real** no GET público de `listing_prices` (decisão 9, cache de 1 h pré-aquecido) — a autorização é por **lista CONSTANTE**, e a **outra face** é conferida (a tarifa **FOI** consultada, senão o `assertSame([])` seria verdadeiro por o fake nem ter sido tocado); **nenhum `MLB…` gravado**; e o **segundo passo não existe** (gate de ausência dos quatro rótulos, decisão 1) — gate custo-do-painel, 2/24 + gate aprovar-e-enfileirar, 3/85 + gate ausencia-de-segundo-passo, 1/17 + gate simular-sem-gravar, 2/25
CRIT-19: OK — render adverso **REAL** (`esbuild` + `react-dom/server`) com **18 formas** por campo (`null`, `undefined`, `0`, `1`, `-1`, `NaN`, `Infinity`, `''`, `'   '`, texto, `true`, `false`, `{}`, `[]`, `[{}]`, **`{toString: null}`**, **`{valueOf: null}`**, função) em **todos** os campos novos, em cada item de lista, na **prop inteira**, nos handlers **e TAMBÉM NO CLIQUE** (`renderToStaticMarkup` **não dispara `onClick`**), com `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`; ⚠️ **guarda de TIPO antes de qualquer `obj[chave]`/`hasOwnProperty`** — os dois **LANÇAM** com `{toString: null}` e derrubam a página inteira; **`String(` e `JSON.stringify(` PROIBIDOS** na fonte e **nunca** na mensagem de asserção (eles lançam/mentem); **`src=""` proibido** (string vazia num `src` faz o navegador **RECARREGAR A PÁGINA**); e **nenhuma flag fora do callback do `.map()`**, porque o Rollup elide variável de escopo do componente — gate casca-do-painel, 45,41 + gate coluna-de-fotos, 32,28 + gate coluna-de-preco, 48,44 + gate pendencias-e-rodape, 42,37 + gate modal-de-ajuste, 27,23 + gate modal-de-frete, 22,18
CRIT-20: OK — a tabela **FID-01** do painel e dos 4 modais existe em `188-FID01.md` (§A a §R), foi medida contra `design_handoff_publicador/Aprovacao.dc.html` (sha256 `68624883ce35547e`, **1432** linhas, **não se moveu**) e **DECLARA COMO MEDIU**: **13 de 13 por SCRIPT ou SEM-PROTOTIPO, ZERO por LEITURA** (§5 deste documento), com `cursor`/`white-space`/`text-overflow` em **colunas próprias**, as divergências classificadas em **OMISSÃO / REFERÊNCIA / VALOR** (placar da re-medição: **20 REFERÊNCIA · 11 OMISSÃO · 2 VALOR**, e **nenhuma de MEDIDA**) e os ponteiros **RE-MEDIDOS por `sed`** em **nove** waves — gate fid01-remedida, 1/13 + gate vocabulario-tamanhos, >=681

### Placar

PLACAR-188: OK=18 RESSALVA=2 REPROVA=0

| veredicto | quantos | quais | dono de cada ressalva |
|---|---|---|---|
| **OK** | **18** | 01, 03, 04, 05, 06, 07, 08, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 | — |
| **RESSALVA** | **2** | **02** · **09** | **Fase 190/191** nas duas (a tela de revisão detalhada), por **DECISÃO DO USUÁRIO** (PERGUNTA-188-B3 e PERGUNTA-188-B) |
| **REPROVA** | **0** | — | — |

⚠️ **Nenhum `REPROVA`.** As duas ressalvas são **fronteiras do desenho decididas pelo usuário**, com
dono nomeado — **nunca trabalho faltando dentro desta fase**. ⚠️ **E as duas são a MESMA fronteira**:
o botão e os dois destinos levam à tela que a **Fase 190/191** vai criar; *"desenhar desabilitado com
explicação"* foi **oferecido e RECUSADO**, porque **botão sem destino é D4 violado**.

⚠️ **As ressalvas de AMBIENTE que NÃO viraram veredicto de critério**, porque nenhum critério as
cobra, e que valem para a leitura dos 20:
- **a fila nasce VAZIA até a Fase 194** (decisão **P3**: é honesto, **não** é defeito) — então
  *"o painel abre com dado real"* vale para o **CONTRATO do servidor**, nunca para uma oferta de
  verdade na tela;
- **nenhum executor viu tela renderizada** (§1);
- **"⤢ Ampliar" (Fase 189) e "✦ Gerar novamente com IA" (Fase 192) NÃO são desenhados**, com **gate
  de ausência** nos dois — e a 189 **já está planejada** e vai **inverter** o gate de ausência do
  Ampliar (§13).

---

## §5 — A TABELA FID-01 CONSOLIDADA, COM `SCRIPT` OU `LEITURA` DECLARADO

> *"Tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é."* — medido neste
> projeto em 13/10/2026.
>
> ⚠️⚠️ **A tabela NÃO erra VALOR — erra por OMISSÃO e por REFERÊNCIA.** Placar da re-medição do
> 188-01: **20 `REFERÊNCIA` · 11 `OMISSÃO` · 2 `VALOR`**, e **nenhuma de MEDIDA** (todos os
> `font-size`, `height`, `padding`, `border-radius` e cores conferidos **batem**). Por isso a
> conferência é por **SEMÂNTICA**, **nunca** pelo número de linha que o plano citou.

<!-- ⚠️ Forma fixa lida pelo gate `fechamento-territorio`. Uma linha por plano, 01 a 13. -->

FID-01-PLANO-01: SCRIPT — a re-medição completa de §B..§F em **três passadas** (colunas · ponteiros · omissão), faixas EM ESCOPO `547-722`, `775-820` e `888-890`, placar **100 certos / 15 deslocados / 0 score-zero** e **33** divergências classificadas; ⚠️ **a passada 1 sozinha era CEGA PARA OMISSÃO** (só comparava linhas que a tabela já mapeia) — foi a passada 3 que achou os **11** wrappers, e é por isso que ela existe
FID-01-PLANO-02: SCRIPT — a região do **VIEW-MODEL** (`:1076`-`:1320`), que **NENHUMA das três passadas do 188-01 tinha coberto**: **6 ponteiros deslocados, 3 confirmados**, todos por REFERÊNCIA; registrada como a **§J** nova do `188-FID01.md`
FID-01-PLANO-03: SCRIPT — a coluna de fotos (§C) e mais **3** ponteiros do view-model + **1** fora do protótipo; **os três ponteiros que a prosa do plano citava estavam TODOS deslocados** — a lição da §J confirmada **na primeira tentativa de usá-la** (§K)
FID-01-PLANO-04: SCRIPT — a coluna de preço (§D), mais **3** ponteiros do view-model reconfirmados por `sed`; o `onSimTarget` com `ceil(target) − 0,1` é **`:1140`** e **não** `:1139` (que é o `onSimBE`, o atalho de **EQUILÍBRIO**, sem o ajuste de 10 centavos) — e a **cor da barra do painel NÃO é a da FILA**, medido nos dois pontos (§L)
FID-01-PLANO-05: SCRIPT — a escrita de imagem e a aprovação; **1** ponteiro do view-model e **TRÊS achados de FERRAMENTA**; inclui a **quarta** ocorrência de *"furo na própria sonda"*, num script de apoio (§M)
FID-01-PLANO-06: SCRIPT — os quatro payloads dos modais e a prévia do frete; **CINCO** ponteiros deslocados e **DEZESSEIS** confirmados, o plano **DIVERGIA** do FID01, **a assinatura que a §F.3 prescreve NÃO EXISTE**, e um **ACHADO DE SCHEMA que derruba uma fixture PRESCRITA** (§P, a do 188-06)
FID-01-PLANO-07: SCRIPT — a casca do painel (§B), o topo de 54px e o z-index; **quatro OMISSÕES NOVAS** do §B medidas nesta wave, e o primeiro achado da própria sonda foi **a frase quebrada em duas linhas**
FID-01-PLANO-08: SCRIPT — a coluna de fotos na tela (§C); **4** ponteiros, a conferência de `cursor`/`white-space`/`text-overflow` **coluna por coluna**, e ⚠️⚠️ **O ACHADO DE FERRAMENTA QUE MUDA ONDE A ISCA PODE MORAR: o SCANNER DO TAILWIND LÊ COMENTÁRIO** (§N, e §14 item 2)
FID-01-PLANO-09: SCRIPT — a coluna de preço na tela (§D); as **omissões LOAD-BEARING** do §D, uma **divergência do `<behavior>` do plano resolvida a favor do PROTÓTIPO**, **quatro furos na própria sonda numa só wave**, e ⚠️ **o ponteiro errado estava DENTRO da lista de "confirmados" da §L** (§O)
FID-01-PLANO-10: SCRIPT — as pendências e o rodapé (§E); **SEIS** ponteiros deslocados, ⚠️⚠️ **a §E CONTRADIZENDO a §L e a §O do MESMO documento**, a **estrutura medida por PROFUNDIDADE** (o rodapé é **IRMÃO** do corpo, não filho) e a **TAUTOLOGIA DE LITERAL** achada por mutação sobrevivente (§P, a do 188-10, e §14 itens 3 e 4)
FID-01-PLANO-11: SCRIPT — os modais de ajuste e exceção (§F.1/§F.2); **19** ponteiros confirmados por script, **duas divergências do MOD-02 a favor do PRODUTO**, **três achados de ferramenta**, e ⚠️ **o backdrop do modal abre na RAIZ, FORA do `<aside>`** — e o ponteiro errado estava na **§F.4**, que é a prescrição do plano seguinte (§Q)
FID-01-PLANO-12: SCRIPT — os modais de frete e reprovar e o aviso de concorrência (§F.3/§F.4); **32** checagens confirmadas por script, e ⚠️⚠️ **o achado maior da fase, que NÃO é de ponteiro: o `onError` do Inertia NUNCA é chamado para as recusas deste painel** (§R)
FID-01-PLANO-13: SEM-PROTOTIPO — plano de **FECHAMENTO**: não há tela nem `.dc.html` a medir. O que foi medido por **SCRIPT** nesta wave foi o **próprio protótipo** (`find` + `test -e` + `wc -l` + `sha256sum`: **1432** linhas, `68624883ce35547e`, **não se moveu**) e a **consolidação** das §J a §R, com o que eu **re-medi** marcado em §5.1

⚠️ **13 linhas: 12 por SCRIPT e 1 SEM-PROTOTIPO** — e a única sem protótipo é o **fechamento**, onde
não existe `.dc.html` a medir. ⚠️ **NENHUMA por LEITURA.**

### §5.1 — ⚠️⚠️ A FID-01 **CONTRADIZ A SI MESMA**, e qual seção é a AUTORIDADE

| o que eu RE-MEDI nesta wave | resultado |
|---|---|
| o arquivo do protótipo: `find`, `test -e`, `wc -l`, `sha256sum`, contagem por registros | **1432 linhas**, `68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2` — **não se moveu** |
| há **duas** cópias do pacote de handoff, com conteúdo diferente | **confirmado**; `find . -maxdepth 3 -name Aprovacao.dc.html` devolve **só** a de `design_handoff_publicador/` |
| as seções do `188-FID01.md` e os seus ordinais | ⚠️ **ACHADO NOVO — ver abaixo** |
| os ponteiros individuais das §J a §R | **NÃO re-medidos por mim**, um por um: registro **com a fonte declarada** (a wave que os mediu, por `sed`/script), em vez de afirmá-los como medição minha |

⚠️⚠️ **ACHADO NOVO DESTE FECHAMENTO: a `188-FID01.md` tem DUAS seções rotuladas `§P`, e as DUAS
dizem "SÉTIMA VEZ".** Medido: a seção da wave **188-06** e a da wave **188-10** levam **o mesmo
rótulo de letra** e **o mesmo ordinal**. ➡️ A sequência real de confirmações é
**§K(03) · §L(04) · §M(05) · §N(08) · §O(09) · §P(06) · §P(10) · §Q(11) · §R(12)** — **NOVE
confirmações da lição da §J**, com **duas** compartilhando a letra `P` e o ordinal "sétima".
⚠️ **Registrado, NÃO consertado:** o `188-FID01.md` não está nos meus `files`, e mexer nele mudaria a
contagem do gate `fid01-remedida` (`1/13`), que **não é desta wave**. **Dono: a Fase 189**, que já
vai estender aquele documento.

⚠️⚠️ **E QUANDO O DOCUMENTO SE CONTRADIZ, A AUTORIDADE É A SEÇÃO QUE MEDIU POR `sed`, NA ORDEM
CRONOLÓGICA — e o único juiz é o `sed`.** Medido na fase, três vezes:

| contradição medida | quem estava certo | por quê |
|---|---|---|
| o `<interfaces>` do `188-08-PLAN.md` × a **§C** | **a §C** | ela mediu por script; o plano herdou prosa |
| a lista de **CONFIRMADOS da §L** × a medição da **§O** | **a §O** | ⚠️ havia ponteiro **errado DENTRO** da lista de "confirmados" |
| a **§E** × a **§L** e a **§O** (as três citam `:1143`) | **a §L e a §O** (`:1143` é o `freightMissing`) | a §E dizia que eram os 4 pares de cor do botão, que estão em **`:1307`** — **desvio de +164 linhas** |

➡️ ⚠️ **A regra, escrita para quem herdar:** *quando DUAS seções deste documento citarem a MESMA
linha para assuntos DIFERENTES, uma delas está errada — e nenhuma vale sem `sed`.* ⚠️ E repare no
padrão que a §J descreveu e que se repetiu **nove** vezes: o ponteiro errado **cai em cima de outro
assunto REAL e load-bearing**, então quem o lê sem medir **acha que está no lugar certo**.

⚠️ **A NOTAÇÃO DE DOIS-PONTOS É EXCLUSIVA DO PROTÓTIPO.** No `188-FID01.md`, `` `:N` `` significa
*"linha do protótipo"* — e o gate `fid01-remedida` valida que **todo** ponteiro assim aponta para
dentro das **1432** linhas. A primeira redação da §R citou o `@inertiajs/core` na mesma notação e o
gate **acusou NOVE ponteiros fora do protótipo**; a segunda redação, que **explicava** o achado,
reprovou **de novo**, porque a prosa escrevia um daqueles números por extenso entre acentos graves.
➡️ **Linha de arquivo que NÃO é o protótipo vai como `L<N>`**, com o arquivo nomeado ao lado. É a
família *"prosa que cita sintaxe precisa escapar a sintaxe"*.

### ⚠️ A REGRA DE OURO, e a exceção declarada

**Quando a tabela discordar do protótipo, MEÇA no `.dc.html` e o PROTÓTIPO GANHA** — em **VALORES**,
**RÓTULOS** e **ORDEM**; a divergência vai registrada com `arquivo:linha`.

⚠️⚠️ **EXCEÇÃO: ele NUNCA ganha nos NÚMEROS de dinheiro.** As taxas de `fin()` são **ficção
declarada** do próprio protótipo (*"taxa de exemplo"*, `src:'est'`), e com as fontes reais o `o1`
**inverte de aprovável para bloqueado**. ➡️ A regra vale para a **FÓRMULA**, os **RÓTULOS** e a
**ORDEM**, **nunca** para os **NÚMEROS**.
⚠️ **E a seção F do README é PROSA**, que já contradisse `info()` (chamou de *"margem baixa"* um caso
que dá **prejuízo** pela cadeia exclusiva): **só `info()` decide bloqueio.**

### ⚠️⚠️ AS **QUATRO** DERROTAS DO PROTÓTIPO NESTA MILESTONE — registradas JUNTAS

Sozinha, cada uma parece arbitrária. **Juntas, elas delimitam a exceção à regra de ouro** — e
⚠️ **nenhuma é erro do protótipo: as quatro são conflito entre DUAS REGRAS DO PROJETO, resolvido
PELO USUÁRIO.**

| # | o que o protótipo diz | o que ficou | a razão do usuário |
|---|---|---|---|
| **1** | `' d'` abreviado para a espera (`:1054`) | ⚠️ aqui o protótipo **GANHOU**: ficou o `' d'`. Quem perdeu foi a **prosa do plano**, que dizia `'3 dias'` por extenso | está na lista para o conjunto ficar completo e ninguém trocar os casos; `min`/`h` seguem abreviados **de propósito** |
| **2** | *"N ofertas aprovadas"* no toast do lote (`:1167`) | **`TOASTS.aprovado`** — *"Aprovado — aguardando publicação"*, com a contagem no sub | adotar a frase dele criaria uma **7ª frase fora do vocabulário travado** dos 6 `TOASTS` (VOC-08, com gate). **O vocabulário é o invariante; a frase é redação** |
| **3** | *"Verificação automática"* com o ordinal **COLADO** e **sem** cabeçalho (`:633`, minúsculo) | **cabeçalho "VERIFICAÇÃO AUTOMÁTICA · APOIO" UMA vez + o NÚMERO DO MARCADOR em cada item** (PERGUNTA-188-A, opção (c), 18/10/2026) | ⚠️ **o cabeçalho é o que diz APOIO** — sem ele a verificação automática pode ser lida como **VEREDICTO**, que é o que REVS-10 e a D7 querem impedir; e o ordinal é **FUNCIONAL** (liga o item ao marcador x/y), não enfeite. ⚠️ O literal em MAIÚSCULAS só existe em **`:369`**, que é a **revisão detalhada (E4)**; o **painel** escreve minúsculo em **`:633`**. Aplicada em **188-03** (o dado) e **188-08** (o rótulo) |
| **4** | o aviso de foto como **evento** | **alerta FIXO na coluna, SEM sétimo toast** | ⚠️ **é ESTADO, não evento** — e **toast some em ~3,8 s**. *"As duas coisas"* foi **OFERECIDO e RECUSADO**, porque poria **o mesmo número em duas fontes** |

➡️ **O placar da fidelidade nesta milestone é ~146 a 4.** ⚠️ A regra *"o protótipo ganha"* **não vale
contra um vocabulário de produto já travado e gateado**, nem contra uma regra de produto que o
usuário decidiu — e essas são as **únicas** exceções exercidas.

### ⚠️ Duas divergências que **NÃO** são derrota do protótipo, e vale dizer por quê

1. **O rodapé: `resumoDoRodape()` (o primeiro abreviado + ` +N`) × o `join(' · ')` de `:1297`.**
   ⚠️ **Não é decisão nova e não é derrota:** o **critério 10 do ROADMAP já manda assim**, e a wave
   188-10 mediu que **o protótipo tem as DUAS formas** — `:1238`, a linha da **FILA** no **MESMO**
   arquivo, é **exatamente** a forma do produto. ➡️ **O protótipo é internamente inconsistente, e o
   critério desempatou.** **Não houve nada a devolver.**
2. **`animate-pub-spin` não é o spinner do protótipo** (`.8s` lá, `.7s` no token). A wave 188-08 usa
   a **classe** (pelos keyframes e pela curva) e põe a **duração do protótipo por `style`**, porque
   `duration-[Nms]` e `animate-[…]` arbitrários **não compilam** neste projeto. ⚠️ **Um gate que
   exigisse a mesma duração nos dois reprovaria código certo.**

### ⚠️ A LACUNA DO RÓTULO DE VARIAÇÃO — decidida pelo usuário, com o custo funcional escrito

**MEDIDO:** a chave do grupo que o payload entrega é **CANÔNICA** (ex.: `COLOR=id:52049`,
`cor=preto`), e o **nome legível** só existe em `ResolvedorGruposImagem::rotuloDoGrupo()`, que é
**`private static`** e vive em **território VETADO** (`App\Support\Publicador`, da outra sessão).
➡️ **O usuário decidiu seguir SEM o nome e anotar a lacuna.**

⚠️⚠️ **O CUSTO FUNCIONAL REAL, escrito para ninguém o descobrir na tela:** revisando um produto com
**3 cores**, as fotos aparecem **juntas, sem dizer qual é de qual cor**. O bloqueio de fotos cobre
**todos** os grupos (decisão 5), então a pessoa **precisa** conferir todos — mas **não sabe qual
grupo é qual**.
⚠️ **E a wave 188-08 PROVOU que a chave canônica NÃO VAZA para a tela** (gate de ausência, com
isca) — a lacuna é *"falta o nome"*, **não** *"aparece jargão"*.
➡️ **Dono: a fase que puder coordenar com o outro dev** (`PERGUNTA-ROTULO-DE-VARIACAO`, §8).

---

## §6 — A CAMPANHA DE MUTAÇÃO CONSOLIDADA

### A disciplina, igual nos treze planos

1. **COMMITAR ANTES de mutar** — mutação sobre trabalho não commitado é risco sem necessidade;
2. `cp -p` do arquivo para o **scratchpad**, com **NOME QUE CARREGA O PLANO**;
3. ocorrência **TRAVADA EM 1 ANTES** de substituir (aborta se diferente de 1), por **string exata**;
4. **linha conferida DEPOIS** de aplicar;
5. aplicação provada por **DIVERGÊNCIA de `sha256`** (nunca por *"a âncora antiga sumiu"*);
6. restauração **DA CÓPIA**, em `trap EXIT` / `finally`;
7. `sha256` da árvore contra `git show HEAD:`, com `tr -d '\r'` **nos dois lados**, **antes e depois**;
8. ⚠️ **`# tests` do MUTADO conferido IGUAL ao regime declarado** — se cair, o arquivo **não
   carregou** e o veredicto **não vale**;
9. ⚠️⚠️ **`git checkout` NUNCA** — ele restaura o estado **COMMITADO** e já comeu **90 linhas** de
   gate não commitado nesta milestone.

⚠️⚠️ **O NOME ÚNICO NÃO É ZELO:** o scratchpad é **COMPARTILHADO** entre subagentes lançados em
paralelo pela mesma sessão, e na Fase 187 o `mutar.sh` de uma wave foi **SOBRESCRITO** pela outra no
meio do trabalho, obrigando a **refazer a campanha inteira**. ➡️ **Conclusão de mutação tirada com
script de nome genérico, em paralelo, NÃO VALE.** Nesta fase **todas as treze** waves usaram
`mutar-188-NN.sh` e imprimiram o `sha256` do **próprio script** antes do veredicto.

⚠️ **E `grep -c` conta LINHAS, não ocorrências** — a trava de ocorrência é por `grep -o | wc -l`,
`str.count` ou `substr_count`. ⚠️ **Padrão MULTI-LINHA não é travável por `grep`** (ele o trata como
N padrões independentes), e por isso **toda âncora é de UMA LINHA** — ou, quando precisa ser
multi-linha, a trava é por `substr_count` (foi o caso da **MUT iv** do 188-05).

### Uma linha por plano

| plano | mutações | mortas | sobreviventes | observação |
|---|---|---|---|---|
| **188-01** | **8** | 7 | **1 por DESENHO** | a sobrevivente era **controle por construção** (o invariante é *"nenhum COMMIT de escopo `(188-01)` acrescenta chave"*, não *"o arquivo no disco nunca muda"*), **e a tese foi PROVADA** gerando o diff real e alimentando o analisador. ⚠️ **Mas a investigação achou OUTRO defeito, REAL:** o analisador **nomeava a chave errada** — ancorado em `^\s*`, numa linha com duas chaves devolvia a **legítima** em vez da inventada. **Mensagem que nomeia a coisa errada é como o executor seguinte conserta a coisa errada** |
| **188-02** | **10** | 9 | **1, INVESTIGADA → GAP REAL** | a **(vii)** (esvaziar o call site de `SEM_ROTA_ATE_A_FASE_190`) sobreviveu: sem o `return null` antecipado, `destino()` cai no `match`, **que não tem arm** para as duas chaves, e o `default => null` devolvia **o mesmo `null`**. ➡️ **A decisão do usuário na PERGUNTA-188-B estava protegida SÓ pela ausência acidental de dois arms.** Conserto na OUTRA direção (*ter destino ⟺ não estar na lista e não ser `em_preparacao`*), e a **(viii)** morre. ⚠️ A (vii) **continua sobrevivendo de propósito** — e volta a ser load-bearing sozinha quando a 190/191 escrever o arm |
| **188-03** | **9** | 8 | **1, INVESTIGADA → INOBSERVÁVEL** | a **(ii-b)** (badge `!` também em `rejected`): `noConjuntoFinal()` admite só `pending ∪ approved`, então **`rejected` nunca chega à galeria** e o ramo não tem como ser exercitado. ⚠️ **A premissa ficou TRAVADA** (`NO_CONJUNTO_FINAL` comparada por igualdade com `[pending, approved]`): **no dia em que ela ganhar `rejected`, o caso REPROVA** — gate que dispara **na hora**, em vez de uma nota que ninguém lê |
| **188-04** | **10** | **10** | **0** (1 na 1ª rodada) | ⚠️ a sobrevivente da 1ª rodada era **o SCRIPT**: a mutação **acrescentava um método morto** e deixava o real **INTACTO** — o `sha256` divergia (o arquivo mudou) mas o **comportamento não**. **NO-OP.** Corrigida para trocar o **CORPO** do método, ela **MORREU** |
| **188-05** | **7** | **7** | **0** | ⚠️ a **MUT iv ABORTOU** na 1ª tentativa, e **o aborto é o comportamento CERTO**: a âncora aparecia **DUAS** vezes (em `aprovar()` e em `aprovarEmLote()`) e a guarda recusou. **Âncora ambígua mutaria o método errado e o veredicto não valeria nada.** Refeita com âncora **multi-linha** desambiguada e trava por `substr_count` |
| **188-06** | **8** | **8** | **0** | a **(v)** foi desenhada para matar o **COMPORTAMENTO** de `libera()`, **sem tocar a constante `LIBERA`** — mutar a lista a mataria numa asserção de **lista**, que *não é prova de comportamento*; quem a pegou foi a **isca por bloqueio** |
| **188-07** | **20** | **20** | **0** | a maior campanha da fase; inclui a isca que distingue **INVERSÃO** de simples **troca de número** no z-index |
| **188-08** | **12** | **12** | **0** | `# tests 28` em **todas as doze** — a prova de que o arquivo **CARREGOU** e que a morte é da asserção, não do módulo |
| **188-09** | **7** | **7** | **0** (1 na 1ª redação) | ⚠️ a **(v)** só mata **porque a FIXTURE mudou**: no estado neutro o servidor manda `selo: null`, então a guarda da TELA era **inobservável** e o gate estaria medindo **o servidor**. Entrou a fixture do payload **contraditório** (`selo` preenchido com `simulando: false`) |
| **188-10** | **8** | **8** | **0** (1 na 1ª rodada) | ⚠️⚠️ a sobrevivente **(vi)** achou **DEFEITO REAL na sonda**: a **TAUTOLOGIA DE LITERAL** (§14, item 3). Conserto em **duas camadas**, e os regimes subiram de `41,36` para `42,37` — ⚠️ **e o +1 é um CASO NOVO, não número subido para calar gate** |
| **188-11** | **10** | **10** | **0** | o `# tests` do mutado é **igual ao regime mutado do manifesto** nos dois gates (`23` e `20`) — ⚠️ **e foi assim que o manifesto ganhou os dois regimes, não ao contrário** |
| **188-12** | **12** | **12** | **0** | ⚠️ as duas mais importantes: **(v)** tirar o ouvinte de `invalid` **mata o aviso** — ela MORRE, e é a prova de que o caminho novo é o que de fato carrega o 422; **(x)** tirar o `versao_esperada` do corpo **MORRE**, provando que a chave viaja **sempre** |
| **188-13** (este) | **9** | **9** | **0** | ver §6.2 |
| **TOTAL** | **130** | **130** | **0 ao fim** | **5 eventos de sobrevivência**, dos quais **4 foram INVESTIGADOS até a causa** e **1 era controle por construção** |

⚠️ **Somando a milestone:** 132 mutações na Fase 187 + **130** nesta fase. ⚠️ **E "mutação que NÃO
reprova quando deveria também é informação"** — nesta fase a informação foi, uma vez, sobre **o
próprio script** (188-04) e, uma vez, sobre **a fixture** (188-09).

### ⚠️ Cada sobrevivente INVESTIGADA — e o que cada uma mudou

| sobrevivente | a causa, medida | o conserto |
|---|---|---|
| **188-01 M3** — injetar chave de token **no disco, sem commit** | **controle por construção**: o invariante é sobre **COMMIT**, não sobre o disco. A forma larga **reprovaria as Fases 189-194** | nenhum no invariante; **mas** a mensagem do analisador passou a nomear a chave **certa** (varredura global na linha) |
| **188-02 (vii)** — esvaziar o call site da constante | o `match` **não tem arm** para `ajuste_em_aberto`/`titulo_longo`, e o `default => null` dá o mesmo resultado: **semanticamente equivalente HOJE** | o laço fechado na **outra direção** (*ter destino ⟺ não estar na lista*), com os **nove** destinos COLETADOS num mapa e **isca nas duas direções**. ⚠️ **Nada de destino especulativo** — isso anteciparia a Fase 190/191 |
| **188-03 (ii-b)** — badge `!` também em `rejected` | **INOBSERVABILIDADE por construção**: `rejected` nunca chega à galeria final | a **premissa TRAVADA** por igualdade de conjunto; o badge é **LIDO** de `com_alerta`, nunca recomputado (recomputar seria a **segunda definição**) |
| **188-04 (ii)** — "o frete não anula" | ⚠️ **era o SCRIPT**: NO-OP (método morto acrescentado, o real intacto) | a mutação passou a trocar o **CORPO** do método |
| **188-10 (vi)** — trocar o literal verde por um **sinônimo** | ⚠️⚠️ **TAUTOLOGIA**: a asserção lia o literal **do mesmo módulo** que renderizou o HTML — trocar o literal muda **os dois lados juntos**. A pergunta *"o que precisaria estar errado no mundo?"* tinha a resposta **"nada"**. **O mesmo furo estava em SEIS outros literais** | **duas camadas**: expectativa **CONGELADA no gate** por igualdade de STRING (nunca `includes`) com **isca do quase-igual**, **mais** o cruzamento com o protótipo onde ele é o **DONO** do literal |

⚠️ **E o conserto de "piso" nunca é elevar o piso:** `>= N` **não prova PRESENÇA** — com 13 itens,
perder **um** deixava 12 e o piso passava. ➡️ **Lista CONSTANTE**, sempre.

### ⚠️ Os DOIS REGIMES de contagem, e qual manifesto os guarda

Oito ids de JS desta fase têm **duas** contagens (`tests=N,N-mutado`): a sonda com `skip` por `mtime`
reporta números **diferentes** com o arquivo pristino e com ele mutado. **Sem os dois regimes a
campanha abortaria na primeira execução** ou marcaria *"SOBREVIVEU"* por um motivo que **não é o
código**.

| manifesto | ids com dois regimes |
|---|---|
| `contagens-188-07.txt` | `casca-do-painel` (45,41) |
| `contagens-188-08.txt` | `coluna-de-fotos` (32,28) |
| `contagens-188-09.txt` | `coluna-de-preco` (48,44) |
| `contagens-188-10.txt` | `pendencias-e-rodape` (42,37) |
| `contagens-188-11.txt` | `modal-de-ajuste` (27,23) · `modal-de-excecao` (23,20) |
| `contagens-188-12.txt` | `modal-de-frete` (22,18) · `modal-de-reprovar` (19,17) · `aviso-de-versao` (12,10) |

⚠️⚠️ **E A ORDEM DO BUILD IMPORTA:** `npm run build` roda **DEPOIS** da mutação, **nunca antes de
medir o JS** — o `cp` da restauração **atualiza o `mtime`** e a camada de CSS **PULA**, dando
`skipped` que **NÃO é regressão**. ⚠️ **E `git diff HEAD -- resources/` é conferido ANTES do build**,
para não construir sobre `.jsx` meio-salvo de outra wave.

### §6.2 — A campanha DESTE plano: **9 mutações, 9 MORTAS, 0 sobreviventes**

⚠️ Auxiliares com **nome que carrega o plano**: `mutar-188-13.sh`, `homonimo-188-13.php`,
`alvos-188-13.txt`, `out-188-13-<rotulo>.txt`, `copia-188-13-<caminho>` — e o `sha256` do próprio
script impresso antes do veredicto, porque o scratchpad é **COMPARTILHADO**.

| # | mutação | gate | veredicto |
|---|---|---|---|
| **M1** | `CRIT-13: OK` vira **`CRIT-99: OK`** (quebra a numeração 01..20) | `fechamento-criterios` | **MORREU** |
| **M2** | `CRIT-09: RESSALVA` vira **`CRIT-09: REPROVA`** | `fechamento-criterios` | **MORREU** — o fechamento **PARA** com `REPROVA`, não o "registra" |
| **M3** | tirar o `dono:` da **`CRIT-02`** (a ressalva da decisão do usuário) | `fechamento-criterios` | **MORREU** |
| **M4** | um `ALVO-INTOCADO:` aponta para `app/Mcp/NaoExiste9z7/` (**caminho fantasma**) | `fechamento-territorio` | **MORREU** |
| **M5** | a linha do plano 09 da FID-01 declara **`LEITURA-RAPIDA`** (veredicto fora da lista fechada) | `fechamento-territorio` | **MORREU** (na 2ª tentativa — ver abaixo) |
| **M6** | tirar o `dono:` da **`PERGUNTA-17`** (a do bug latente da Fase 194) | `fechamento-criterios` | **MORREU** — ⚠️ é a mutação que **SOBREVIVEU na Fase 187** por o gate ser **PISO**; aqui a lista é **CONSTANTE** |
| **M7** | o item **12** do roteiro da conta 459 vira **11** (numeração duplicada) | `fechamento-criterios` | **MORREU** — ⚠️ é a **M6b** da 187: **menção** a "conta 459" não prova **roteiro** |
| **M8** | injetar no `contagens-188-13.txt` um id **sem `<verify>`** (id órfão) | `fechamento-manifestos` | **MORREU** |
| **M9** | embutir `publicador-vocabulario-novo.test.js` num **segundo id** do manifesto | `fechamento-manifestos` | **MORREU** |

```
RODADA 1  (mutar-188-13.php, sha256 14f8d2b5822e58e9)
  M1  MORREU      linha  506   gate=fechamento-criterios
  M2  MORREU      linha  502   gate=fechamento-criterios
  M3  ABORTOU     ocorrencias=0   (a trava exige exatamente 1)
  M4  MORREU      linha  410   gate=fechamento-territorio
  M5  ABORTOU     ocorrencias=2   (a trava exige exatamente 1)
  M6  MORREU      linha  924   gate=fechamento-criterios
  M7  MORREU      linha 1084   gate=fechamento-criterios
  M8  MORREU      linha   37   gate=fechamento-manifestos
  M9  MORREU      linha   37   gate=fechamento-manifestos
  -> 7 MORTAS · 0 SOBREVIVENTES · 2 ABORTOS DA TRAVA

RODADA 2  (mutar-188-13-m3m5.php, sha256 d3c51c66efa4ce43, ancoras DESAMBIGUADAS)
  M3  MORREU      linha  495   gate=fechamento-criterios
  M5  MORREU      linha  562   gate=fechamento-territorio

CAMPANHA 188-13: 9 mutacoes · 9 MORTAS · 0 SOBREVIVENTES
sha256 do PROPRIO script impresso antes de cada veredicto (o scratchpad e COMPARTILHADO)
sha256 arvore x `git show HEAD:` (normalizado nos DOIS lados), antes e depois de CADA rodada:
  OK  tests/gates/contagens-188-13.txt
  OK  .planning/phases/188-.../188-FECHAMENTO.md
restauracao DA COPIA em `register_shutdown_function`  ·  `git checkout` NUNCA
```

⚠️ **Nenhuma sobrevivente para investigar nesta wave.** ⚠️ **E as M6 e M7 são as duas que a Fase 187
mediu como SOBREVIVENTES**: elas morrem aqui porque o gate deste plano nasceu com o conserto delas
embutido (**lista CONSTANTE** de perguntas obrigatórias; **roteiro numerado sem buraco** em vez de
menção).

#### ⚠️⚠️ OS DOIS ABORTOS DA TRAVA SÃO O COMPORTAMENTO CERTO — e o da M5 é um ACHADO

| # | ocorrências | causa | leitura |
|---|---|---|---|
| **M3** | **0** | a âncora que eu escrevi **não existia** no documento — erro **meu** de transcrição | ⚠️ âncora que **não casa** mutaria **NADA**, e o `SOBREVIVEU` resultante **não valeria nada**. **A trava recusou antes de produzir um veredicto falso** |
| **M5** | **2** | ⚠️⚠️ `FID-01-PLANO-09: SCRIPT` aparece **DUAS** vezes: na **linha de forma fixa** (§5) **e na TABELA DESTA PRÓPRIA CAMPANHA**, que a **DESCREVE** | ⚠️⚠️ **É a família *"o texto que EXPLICA casa com o padrão que ele explica"* acontecendo DENTRO da campanha de mutação.** Mutar as duas de uma vez **não diria qual delas o gate mediu** |

➡️ **O conserto das duas foi ÂNCORA DESAMBIGUADA, nunca afrouxar a trava:** a M3 ganhou a âncora
**real** e a M5 ganhou o **sufixo que a distingue** da tabela (`… — a coluna de preço na tela`).
**Com elas, as duas MORREM.** ⚠️ **A trava de ocorrência (`== 1`) ficou INTACTA** — ela funcionou nos
dois abortos, que é exatamente para isso que existe.

#### ⚠️ E um defeito do MEU script, pego pela guarda de PARTIDA

A primeira execução **abortou na PARTIDA**, dizendo que o disco divergia do `HEAD` num arquivo que
`git status` e `git diff --numstat` reportavam **limpo**. ⚠️ **A causa era a FERRAMENTA, não o
arquivo:** `exec()` do PHP devolve a saída em **LINHAS num array**, e o `implode("\n")` **PERDE o
`\n` final** do arquivo — então o `sha256` do lado do `HEAD` **nunca** batia.
➡️ **Conserto: normalização SIMÉTRICA nos dois lados** (`\r` removido **e** `rtrim` do `\n` final),
com `shell_exec` em vez de `exec`. ⚠️ **Se eu tivesse "resolvido" afrouxando a guarda de partida, a
campanha inteira teria rodado sem saber se o disco batia com o commit** — e é precisamente essa
guarda que impede mutar sobre trabalho não commitado. **É a terceira vez nesta milestone que o
defeito estava no mutador, e não na asserção.**

---

## §7 — OS **13** MANIFESTOS, A BIJEÇÃO E O HOMÔNIMO

| manifesto | ids | manifesto | ids |
|---|---|---|---|
| `contagens-188-01.txt` | 4 | `contagens-188-08.txt` | 2 |
| `contagens-188-02.txt` | 9 | `contagens-188-09.txt` | 2 |
| `contagens-188-03.txt` | 4 | `contagens-188-10.txt` | 2 |
| `contagens-188-04.txt` | 4 | `contagens-188-11.txt` | 3 |
| `contagens-188-05.txt` | 7 | `contagens-188-12.txt` | 4 |
| `contagens-188-06.txt` | 7 | **`contagens-188-13.txt`** | **3** |
| `contagens-188-07.txt` | 2 | **TOTAL** | **53** |

⚠️ **Nenhum id com `tests=0`** (*"`tests=0` não é contagem, é número de fora"* — e o harness
**ABORTA** com ele). ⚠️ **Nenhum id repetido dentro do mesmo manifesto** (o harness pega o
**primeiro**, e o segundo **nunca roda**). ⚠️ **Nenhum id com mais de TRÊS métodos** — medido nos 13:
o maior tem **3**. ⚠️ **Todo método PHP dos arquivos novos da fase tem id** — `SEM_ID=0`, medido;
**teste sem id NUNCA RODA**, e é a armadilha 1 da Fase 186.

### ⚠️⚠️ A TERCEIRA BIJEÇÃO — id ↔ `<verify>` — sobre os TREZE pares, por SCRIPT

```
13 pares plano x manifesto medidos
ORFAOS (declarado no manifesto, fora de todo <verify>)  = 0
NAO_DECLARADOS (usado num <verify>, fora do manifesto)  = 0
```

⚠️⚠️ **Por que isto é gate e não zelo:** um id pode estar no manifesto, com contagem **medida**,
sobre métodos que **existem** — e **não ser rodado por `<verify>` nenhum**. ⚠️ **Ele não falha: ele
NUNCA RODA**, e é **indistinguível de um gate verde**. A revisão desta fase achou **nove** assim
(`img-02-img-03-sem-regressao`, `tentar-de-novo`, `simular-sem-gravar`, `payload-dos-modais` e
**cinco** do `188-02`), todos religados pelas waves. ➡️ **O plano 188-13 é o único lugar que vê os
treze pares**, e a asserção é **ÚNICA por direção, com os infratores COLETADOS** — uma por id faria a
contagem crescer com o manifesto.

⚠️ **O `NN` é ANCORADO na forma `188-NN`.** O número **nu** resolve `contagens-186-NN.txt` — **a fase
errada, em silêncio** (compatibilidade **DE PROPÓSITO**: a Fase 186 está em produção e seus 163 ids
são chamados pelo número nu; os casos **15 e 16** do `--autoteste` guardam isso). Há **três** iscas
sobre a sonda de verdade: id injetado **é visto**; id de outra wave **não casa**; o **número nu é
recusado**.

### ⚠️ `vocabulario-tamanhos`: em LINHA PRÓPRIA em quem o roda, em NENHUM id composto

```
DECLARAM em linha propria: 188-01, 188-07, 188-08, 188-09, 188-10, 188-11, 188-12   (SETE)
RODAM no <verify>:         os MESMOS sete
EMBUTIDO em id composto:   0
FORMA:                     `>=N`, com N MEDIDO na wave — 548, 569, 597, 632, 646, 660, 681
```

⚠️⚠️ **As duas exigências são OPOSTAS, e confundi-las foi erro de briefing nesta fase:** *(a)* todo
`<verify>` que usa o id tem a linha no manifesto da **sua** wave (senão o harness **ABORTA** —
medido: `verificar-testes.sh 188-07 vocabulario-tamanhos` → *"ABORTOU: manifesto nao existe"*);
*(b)* **nenhum** id que rode **outros** arquivos o inclui (foi o **id COMPOSTO** que fez a wave
187-05 reprovar **dois ids de outras waves** e exigir consertar **quatro** manifestos).
⚠️ **A regra é "quem RODA, declara" — não "todos declaram".** As waves **02 a 06** são de SERVIDOR,
não rodam o GATE-03, e **estão certas** em não declarar: um gate que exigisse a linha nos treze
**reprovaria cinco manifestos corretos**. (O **188-13** também não a declara, pela mesma razão.)
⚠️ **E a forma é `>=` com N MEDIDO, nunca copiado:** a contagem **cresce por construção** (um
sub-teste por arquivo varrido nos diretórios fechados) e **cada `.jsx` novo a aumenta** — de **548**
na wave 01 a **681** na wave 12, **+133 em sete medições**. Exigir igualdade seria a **nona**
ocorrência de *"contagem que depende de quantos arquivos existem"*.

### O harness, re-medido nesta sessão: `--autoteste` **20 casos, 0 divergência**

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

### A conferência de HOMÔNIMO, por script, nas DUAS direções, **com o EXPANSOR DE ALTERNÂNCIA**

```
ARQUIVOS=909  METODOS=7830  HOMONIMOS=0  PREFIXOS=0
DECLARADOS_EXPANDIDOS=75   DECLARADOS_SEM_METODO=0   SEM_ID=0
ISCA_A (nome inventado casa?)        = OK-nao-casa
ISCA_B (controle positivo existe?)   = OK-existe
ISCA_C (substring curta casa N)      = 9   (>1 prova que a sonda de substring VE)
```

⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA é obrigatório, e a razão é medida:** `/test_[A-Za-z0-9_]+/` **NÃO
casa** `test_(a|b|c)`, porque o nome completo do método **não aparece literalmente** no manifesto.
Uma sonda sem expansor reportou **`SEM_ID=14`** nesta milestone — **acusando trabalho CORRETO**. ➡️ O
expansor converte `PREFIXO(alt1|alt2)SUFIXO` nos nomes reais, e aqui ele produziu **75** nomes dos
ids `php` dos manifestos 01 a 06 (as waves 07-12 são só JS).

⚠️ **A varredura é sobre TODO `tests/`, não sobre um diretório** — o harness roda
`artisan test --filter=<regex>` **sem caminho**, e o `--filter` do PHPUnit é regex **NÃO ANCORADA**.
⚠️ **E a conferência de SUBSTRING é a que importa:** nome que é **prefixo** de outro casa os **DOIS**,
e o gate mede duas suítes achando que mede uma — aconteceu **duas vezes** na Fase 186.
⚠️ **As Fases 186 e 187 estão as duas EM PRODUÇÃO**, com 163 + 71 ids: método superstring de uma
delas faria o id daquela fase **SOMAR** e reprovar. **Medido: zero colisões.** Os 9 métodos deste
plano levam **`_188_13`** no nome.

---

## §8 — AS PERGUNTAS, **TODAS COM DONO**

<!-- ⚠️ Forma fixa lida pelo gate `fechamento-criterios`. "a definir" NÃO é dono. -->

PERGUNTA-188-A: RESPONDIDA — dono: usuário (18/10/2026) — opção **(c) HÍBRIDO**: no painel entra o cabeçalho "VERIFICAÇÃO AUTOMÁTICA · APOIO" **UMA vez**, e cada item mantém **o NÚMERO DO MARCADOR** que o liga ao ponto x/y na foto. **A razão do usuário:** o cabeçalho é o que diz **APOIO** — sem ele a verificação automática pode ser lida como **VEREDICTO**, que é o que REVS-10 e a D7 querem impedir; e o ordinal é **FUNCIONAL** (liga o item ao marcador), não enfeite. ⚠️ **TERCEIRA derrota do protótipo** (§5): o literal em maiúsculas só existe em `:369`, que é a **revisão detalhada**; o painel escreve minúsculo em `:633`. ⚠️ O ordinal é **o número do marcador**, e **não** o sufixo colado da frase de `:633`. APLICADA EM: 188-03 (o dado) e 188-08 (o rótulo) — **CRIT-04 sai OK**
PERGUNTA-188-B: RESPONDIDA — dono: usuário / Fase 190/191 — os destinos de correção de `ajuste_em_aberto` e `titulo_longo` ficam **`null` e SEM BOTÃO** enquanto a rota da revisão detalhada não existir; **o TEXTO do bloqueio continua aparecendo**. **Botão sem destino é D4 violado.** APLICADA EM: 188-02 (os destinos) e 188-10 (a ausência do botão) — **é a RESSALVA do CRIT-09**
PERGUNTA-188-B3: RESPONDIDA — dono: usuário (18/10/2026) / Fase 190/191 — o botão **"Abrir revisão detalhada ↗"** do topo de 54px (`:558`) **NÃO É DESENHADO** nesta fase: ele nasce na Fase 190/191, junto com a tela. **A razão do usuário:** é a mesma régua do aviso de tela vazia — **sem botão, porque não há o que fazer com ele**. ⚠️ *"Desenhar desabilitado com explicação"* foi **OFERECIDO e RECUSADO**; nada de `onClick` vazio, nada de `disabled` decorativo. APLICADA EM: 188-07 (a ausência, com gate) e 188-13 (a ressalva) — **é a RESSALVA do CRIT-02**
PERGUNTA-188-C: DECIDIDA — dono: usuário (19/10/2026) — **ACEITO O REUSO DA ROTA DE MINIATURA, COM O EFEITO COLATERAL.** **A razão dele:** *é o que o portão quer* — `AgendadorDaFila:336` diz textualmente *"Gente com o editor aberto: não publica por baixo dela"*, e **revisar é trabalhar no produto**. ⚠️ **"Servir a foto sem marcar" foi OFERECIDO e RECUSADO**, porque exigiria rota nova sobre `App\Support\Publicador`, território da **outra sessão**, e coordenação que **o ganho não paga**. ⚠️ **O efeito MEDIDO, que a decisão não muda:** carregar a miniatura chama `EditorEmUso::marcar()`, que grava cache por **3 minutos**, e isso é lido por **CINCO** consumidores — `AgendadorDaFila:337`, `PreparoIaDoRascunhoService:131`/`:180`/`:366`, `GarantiaPadrao:192` e `ExcluirProdutoService:195`. ⚠️ Mesmo aceito, **fica DECLARADO** no docblock do `FotosDoPainelService` e na SUMMARY do 188-03, com os cinco leitores nomeados **um por um** (há gate), porque a próxima pessoa que vir o agendamento parado tem de achar a causa **PELO GREP**, não por bisseção. APLICADA EM: 188-03 — e o **aviso à Fase 194 PERMANECE** (§13)
PERGUNTA-188-E: DECIDIDA — dono: orquestrador (20/10/2026) — **MANTER o conserto do CLIENTE; NÃO refatorar o servidor.** `back()->withErrors()` é a forma idiomática do Inertia e seria melhor a longo prazo, **mas `recusaDoPainel` tem 18 chamadas medidas, não nove**: trocar o formato em 18 pontos, na última wave da fase, para substituir algo **provado e no ar** (a mutação (v) do 188-12 MORRE), é **risco sem ganho**. ⚠️ A mutação *"(v) engole o 422"* **morre**, então o caminho do cliente é o que de fato carrega o aviso. ➡️ **PENDÊNCIA NOMEADA para fase futura ou quick dedicada**, com o trade-off escrito e o número **18** medido. Registrada em `deferred-items.md`
PERGUNTA-188-F: DECIDIDA — dono: orquestrador (20/10/2026) — **registrar, não consertar agora.** A fixture do gate `casca-do-painel` (188-07) monta a mensagem **com o id da regra PREFIXADO**, e o servidor **não faz isso**: aquele gate passa pelo **FALLBACK** por substring, não pelo caminho primário. ⚠️ **NÃO é buraco de cobertura** — o caminho primário (ler o **CAMPO `regra`**) está provado pelos gates da wave 12, **com mutação**. É **redundância que mede o caminho errado**. ➡️ **O conserto escrito, para quem o fizer:** a fixture deve refletir o que o servidor manda de verdade (`{message, regra}`, sem prefixo). ⚠️ **NÃO mexer no gate agora**: a contagem dele é `45,41` e ele **não é desta wave**. Registrada em `deferred-items.md`
PERGUNTA-VISUAL-188: ABERTA — dono: usuário — a **CONFERÊNCIA VISUAL DO PAINEL** na conta 459, roteiro de **16 itens** em §11. ⚠️ **Nada neste documento afirma que a tela foi conferida**, e o roteiro só é executável **depois de um deploy que o usuário autorize** — esta fase **não deployou, não deu push e não tocou no VPS**
PERGUNTA-VISUAL-187: ABERTA — dono: usuário — a **CONFERÊNCIA VISUAL DA FILA** (Fase 187) na conta 459, roteiro de **8 itens** em `187-FECHAMENTO.md` §11. ⚠️ **Esta fase NÃO a substitui e NÃO a presume feita** — são duas conferências, e a da fila veio primeiro
PERGUNTA-ROTULO-DE-VARIACAO: ABERTA — dono: fase que puder coordenar com o outro dev — **a tela não tem o nome legível da variação.** A chave do grupo é **CANÔNICA** (`COLOR=id:52049`) e o nome só existe em `ResolvedorGruposImagem::rotuloDoGrupo()`, que é **`private static`** em território **VETADO** (`App\Support\Publicador`). ⚠️ **O usuário decidiu seguir SEM o nome e anotar a lacuna.** ⚠️ **Custo funcional REAL:** revisando um produto com 3 cores, as fotos aparecem **juntas, sem dizer qual é de qual cor** — e o bloqueio cobre **todos** os grupos. ⚠️ A chave canônica **NÃO vaza** para a tela (provado pela 188-08, com isca)
PERGUNTA-APROVADA-POR: ABERTA — dono: Fase 190/191 — o **`✓ {quem}`** do protótipo (quem aprovou cada foto) **não é desenhado**: ele precisa de `aprovada_por`/`aprovada_em`, e o serviço de fotos da 188-03 **não os expõe**. ⚠️ Não bloqueia nada nesta fase: o estado da foto aparece, só o **autor** não
PERGUNTA-LER-SEM-COMENTARIOS: ABERTA — dono: orquestrador (quick dedicada) — **`lerSemComentarios` DESTRÓI 76% de `publicador-fila-filtros-187.test.js`**: o `'date-fns/*'` do `external` é lido como **abertura de comentário**, e o arquivo cai de **62.656 para 15.249 bytes**. ⚠️ **O helper NÃO foi consertado, e a razão é que DEZENAS de gates em produção dependem do comportamento atual** — há **três iscas invertidas** no repositório exigindo que ele **continue** mastigando o glob. ➡️ A regra viva é **leitura CRUA para globs**; o conserto é mudança de alcance, não desta fase
PERGUNTA-OSCILANTES: ABERTA — dono: quem mantiver a Fase 179 (2 casos) e quem mantiver a suíte do Portal (2 casos) — **ancorar o relógio** em `CockpitDaContaTest.php:966` e `:1318` (`Carbon::setTestNow` no corpo do teste, **sem mexer no serviço**), e **isolar a corrida de `storage/framework/testing`** nos dois casos de `SincronizaPortalCompletoTest`. ⚠️ **Nenhum dos quatro é desta fase**, e nenhum apareceu na medição deste plano — mas **vão poluir toda baseline futura**. ⚠️ **A hipótese do listener é HIPÓTESE** (os dois experimentos saíram verdes): **não a afirme como diagnóstico**
PERGUNTA-189: ABERTA — dono: usuário — **a outra sessão JÁ PLANEJOU a Fase 189** (8 planos em 7 waves, commit `44d67a46`) e deixou nota pedindo que, **ao fechar a 188, se execute a 189** nesta sessão (`.planning/coordenacao/261019-para-quem-executa-a-188-depois-execute-a-189.md`). ⚠️ **Registro o pedido; a decisão é do usuário, não minha.** ⚠️ O `189-01` tem **gate de entrada** que confere por `assertFileExists` os 13 SUMMARYs da 188 e o `188-FECHAMENTO.md`, e **PARA** se faltar algum
PERGUNTA-17: ABERTA — dono: Fase 194 — **BUG LATENTE:** o recorte da fila usa as duas âncoras com `AND` e `mlb_empresa_id` é **ANULÁVEL**, então oferta gravada com nulo numa conta que **tem** `MlbEmpresa` ficaria **INVISÍVEL** e a pílula diria `0` com trabalho esperando. **Hoje não é bug** (nada cria oferta). Duas saídas, e a 194 escolhe UMA: (a) preencher sempre as duas colunas ao criar a oferta — mais simples e **não toca código em produção**; ou (b) o recorte da Fase 186 vira tolerante a nulo. ⚠️ **Não bloqueia a 188**
PERGUNTA-13: ABERTA — dono: Fase 193 — `PUBLICADO => []` é **TERMINAL**: não há transição saindo de "Publicado". Mexer na foto de um anúncio que **JÁ ESTÁ NO AR** é outro fluxo (editar publicado), e quem decide é a Fase 193. ⚠️ **Não bloqueia a 188**

⚠️ **4 RESPONDIDAS/DECIDIDAS pelo usuário · 2 DECIDIDAS pelo orquestrador · 9 ABERTAS.**
⚠️ **Nenhuma das 9 abertas bloqueia a Fase 188**, e **nenhuma delas é desta fase para resolver**.
⚠️ **E nenhuma tem `a definir` como dono** — há gate sobre isto.

⚠️⚠️ **E eu NÃO reabri as duas que o orquestrador decidiu.** As **188-E** e **188-F** chegaram
decididas, com razão escrita e com o que foi **recusado** nomeado — e *"decidida sem a razão e sem o
recusado"* é **pior que aberta**, porque convida a reabrir. **A mutação (v) do 188-12 morre, e isso é
a prova de que a decisão 188-E está em cima de algo provado, não de algo presumido.**

---

## §9 — OS GATES **ALHEIOS** QUE ESTA FASE TOCOU, DECLARADOS

> ⚠️ **Expansão de território NÃO declarada é o que faz a próxima fase descobrir por acidente.**

### ⚠️⚠️ §9.1 — O MAIOR TEMA DESTA FASE: **DEZ GATES DE TOTAL FIXO foram REAPONTADOS** — e **NENHUM número foi subido**

| # | gate | fase dona | commit | o que entrou no lugar do total |
|---|---|---|---|---|
| **1** | `publicador-fila-tokens-187.test.js:415` — `assert.equal(agora.length, 23)` | **187** | `61e84710` | `LIBERADOS_DEPOIS_DA_187 = [21]`, **lista CONSTANTE** com o plano dono e a medida ao lado, e **igualdade de CONJUNTO** |
| **2 e 3** | o *"contrato de NO"* em **JS** — total de slots | **187** | `93de698d` | o **CONJUNTO DECLARADO** de slots |
| **4** | o fechamento da 187 contando **21 arquivos** | **187** | `b10c19cc` | a **INVARIANTE do diretório** |
| **5** | a isca invertida de `pub-painel` | **187** | `f7616e7f` | troca de precedente **+ veto**, e **continua invertida** |
| **6** | ⚠️ o **GÊMEO EM PHP** do contrato de NO — `FilaDeAprovacaoRotaTest.php:1674` | **187** | `12443bde` | o **conjunto declarado**, e a amarra ficou em **TRÊS pontas** |
| **7 e 8** | os dois gates do `186-01` — total sobre varredura | **186** | `091340b6` | **LISTA DE NOMES** |
| **9** | o `useState` da página contado por total | **188-08** | `e69f7584` | **LISTA DE NOMES** |
| **10** | a **AUSÊNCIA GLOBAL de uma classe de CSS** no gate de FID | **180** | `a874fc00` | o **BYTE do próprio chip** |

⚠️⚠️ **NENHUM número foi subido. Todos viraram lista CONSTANTE de nomes ou reaponte para a
invariante.** ⚠️ **E o conserto é revertível isolado** (cada um num commit próprio).

⚠️⚠️ **CORREÇÃO AO BRIEFING, medida:** a lista de commits que o orquestrador me passou tem **nove**
gates (5 da 187 + 2 da 186 + 1 da 188 + 1 da 180) e **omite `61e84710`**, que é o **primeiro** da
família e vem da wave 188-01. ➡️ **São DEZ, e o número do briefing estava certo; a lista, não.** Os
ordinais dos próprios commits confirmam: `e69f7584` diz **"SEXTO"** e `a874fc00` diz **"NONO"**, com
`091340b6` (dois gates) entre eles.

### ⚠️⚠️ §9.2 — AS TRÊS OBSERVAÇÕES QUE EXPLICAM POR QUE A LISTA É MAIS FORTE QUE O TOTAL

1. ⚠️⚠️ **O total ACEITA REMOÇÃO COMPENSADA POR CRIAÇÃO.** O `21` continuava `21` se alguém
   **APAGASSE** um arquivo e **CRIASSE** outro. A lista de nomes **não**: o apagado aparece como
   faltante **e** o criado como não declarado. **Duas acusações onde o total dava zero.**
2. ⚠️⚠️ **O GÊMEO EM PHP ERA MAIS FRACO QUE O DE JS, por motivo NÃO ÓBVIO: ele contava
   `isValidElement(` SEM CAPTURAR O ARGUMENTO.** Então era possível ter **as 4 chamadas certas** e um
   conjunto de slots **COMPLETAMENTE DIFERENTE** — e o gate ficava verde. ⚠️ **Não era frágil; era
   CEGA.** ➡️ O conserto **rebaixou a contagem a MEDIDOR DE CONSISTÊNCIA** (a contagem **bruta** × a
   **nomeada**), que é o que ela de fato mede.
3. ⚠️⚠️ **O conserto da 186 RECUSOU criar a TERCEIRA escrituração do diretório `aprovacao/`**, e
   apontou para a **invariante que já existia**. **A razão:** lista duplicada demais **recria o
   problema** — cada lugar esquecido é **uma reprovação de trabalho legítimo**. ⚠️ **E a escrituração
   DUPLA que existe hoje (`ARQUIVOS_NOVOS` + `JSX_DEPOIS_DA_187`) NÃO foi unificada, de propósito**,
   e fica registrada como dívida com dono (§10).

### ⚠️ §9.3 — A camada B do gate de andamento: a EXCEÇÃO DE EXPOSITORES, e quem a exerceu

⚠️ A **única** exceção de território já aberta nesta fase. A **wave 188-02** entrou na lista de
**EXPOSITORES** da camada B do gate de andamento **com PROVA nas DUAS pontas** (o expositor **tem**
de casar a sonda larga — senão a exceção é **linha morta** — **e** não pode ter nenhuma forma de
escrita) e **remediu `contagens-186-09.txt`**.

⚠️ **E as waves 188-04, 188-05 e 188-06 mediram que NÃO precisavam entrar, e registraram POR QUÊ** —
em vez de entrar "por segurança", que é como uma lista de exceções se torna porta dos fundos.
⚠️ **A exceção é conferida por COMPORTAMENTO, não por nome:** *"o que impede alguém de entrar nela?"*
tem resposta, e ela é executável.

### ⚠️ §9.4 — Gates alheios que reprovaram DENTRO da fase, e o conserto do lado CERTO

| quem reprovou | por quê | conserto |
|---|---|---|
| dois gates das waves 02/03, na wave **188-04** | um era **defeito real** da 188-02 (Regra 1); o outro **reprovava pelo PRÓPRIO CONSERTO** | feitos do lado certo, declarados na SUMMARY da 04 |
| três gates alheios, na wave **188-06** | **todos reprovavam pelo próprio conserto** | cirurgia declarada, com a razão de cada |
| `PainelDeRevisao.jsx` em `INTOCAVEIS` da 188-09, e o `onAbrirModal={null}` da 188-10 | a wave **188-11** precisava religar os botões | consertados do lado da 11, declarados |
| o `onSimular={null}` da 188-09 | a wave **188-12** religou a composição | consertado do lado da 12, declarado |
| ⚠️ **os CINCO gates de fechamento da Fase 187** | reprovavam porque o painel ganhou o **quinto slot** | ⚠️ a wave **188-07 NÃO consertou, e estava certa**: é teste **PHP da 187 em produção**, com id em manifesto da 187, e o diretório é **exatamente** onde a sessão paralela estava commitando. ➡️ Ela **reportou e devolveu**; a sessão paralela consertou (§9.1) |

---

## §10 — AS DÍVIDAS DESTA FASE

Registradas com **dono** em
`.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/deferred-items.md`.

| dívida | dono |
|---|---|
| **PERGUNTA-188-E** — trocar `recusaDoPainel()` (18 chamadas) por `back()->withErrors()`, a forma idiomática do Inertia | fase futura ou quick dedicada |
| **PERGUNTA-188-F** — a fixture do `casca-do-painel` reflete o servidor (sem o id prefixado) | fase futura (muda a contagem `45,41`) |
| o **rótulo legível de variação** (`rotuloDoGrupo` é `private static` em território vetado) | fase que puder coordenar com o outro dev |
| o **`✓ {quem}`** (precisa de `aprovada_por`/`aprovada_em`) | Fase 190/191 |
| `lerSemComentarios` **destruindo 76%** de `publicador-fila-filtros-187.test.js` (62.656 → 15.249 bytes) | orquestrador, quick dedicada |
| as **4 falhas que oscilam** (2 da Fase 179 + 2 do Portal) | quem mantiver cada suíte |
| a **escrituração DUPLA** de `aprovacao/` (`ARQUIVOS_NOVOS` + `JSX_DEPOIS_DA_187`), deliberadamente não unificada pelo conserto da 186 | orquestrador ou Fase 191 |
| as **DUAS seções `§P`** do `188-FID01.md`, com o mesmo ordinal | Fase 189 (ela já vai estender aquele documento) |
| o **custo** da composição por linha (pergunta P2 da 187) — **mitigado** pelo pré-aquecimento, **não eliminado** | operação |
| a dívida **DIV-01 da Fase 186** segue aberta, e **não é desta fase** | orquestrador ou Fase 191 |

---

## §11 — O CHECKPOINT HUMANO: O ROTEIRO DA CONFERÊNCIA VISUAL DA CONTA 459

⚠️⚠️ **Antes do roteiro, a frase que o precede: NENHUM EXECUTOR DESTA FASE VIU TELA RENDERIZADA**
(§1). ⚠️⚠️ **E esta fase NÃO deployou, NÃO deu push e NÃO tocou no VPS** — o roteiro abaixo só é
executável **depois de um deploy que o usuário autorize**.

⚠️ **A conferência é EM PRODUÇÃO, na conta 459** — a mesma conta da Fase 187.
⚠️ **A conferência visual da Fase 187 (a FILA) continua PENDENTE e SEPARADA** — roteiro de 8 itens em
`187-FECHAMENTO.md` §11, também na **conta 459**. **Esta fase não a substitui nem a presume feita.**

⚠️⚠️ **SEM OFERTA NA FILA, O PAINEL NÃO TEM O QUE ABRIR.** A **fila nasce vazia até a Fase 194**
(decisão **P3** do usuário: é **honesto**, não é defeito), e `pub_aprovacao_ofertas` está **vazia**.
➡️ **O caminho alternativo, para quando a fila está vazia — e é o caso de hoje:** o que dá para
conferir é que **a fila continua honesta** (o texto do vazio, **sem botão**) e que **nada regrediu**
na lista de Publicações. ⚠️ **O painel fica conferido junto com a Fase 194, e isso está registrado
como RESSALVA de ambiente em §4, não como OK.**

⚠️ **E o que NÃO procurar, porque esta fase deliberadamente não desenhou:** o botão **"Abrir revisão
detalhada ↗"** (Fase 190/191), o **"⤢ Ampliar"** (Fase 189) e o **"✦ Gerar novamente com IA"**
(Fase 192). **Os três têm gate de ausência**, e procurá-los seria procurar trabalho de outra fase.

**Os 16 itens, para quando houver oferta** — eles cobrem os critérios **1, 2, 3, 4, 5, 6, 7, 8, 9,
10, 12, 13, 14, 16 e 17**; o **18** é conferido **por ausência** (nenhum status do ML na tela) e os
**11, 15, 19 e 20** são conferidos **por teste**, não a olho.

1. Em `/mlb/anuncios/meus/459`, abrir **"Aguardando aprovação"** e clicar em **Revisar** numa oferta.
   O painel abre **à direita**, cobrindo a tela até ~100px da borda esquerda, **acima do header**.
2. Conferir que o **header global continua visível e funcionando** à esquerda, e que **nada do header
   mudou**.
3. Conferir o topo: **"REVISÃO SIMPLES"**, **"x de N na fila"**, as setas **↑ ↓** e o **×**.
   ⚠️ **NÃO procure "Abrir revisão detalhada ↗": ele NÃO existe nesta fase**, por decisão sua de
   18/10 (PERGUNTA-188-B3) — nasce na **Fase 190/191**, junto com a tela.
4. **Esc** fecha. Reabrir e **clicar fora** — fecha também. Reabrir e usar **↑/↓**: muda de oferta e
   **pula as que estão em "Preparando"**. **←/→** troca a foto.
5. Conferir que **as duas colunas têm o mesmo peso** (fotos à esquerda, preço à direita, mesma
   largura). ⚠️ Se parecer desequilibrado, **diga** — mas é decisão de desenho declarada no README.
6. Na coluna de fotos: **"x de y aprovadas"**, a imagem grande **quadrada** com **#n**, **★
   PRINCIPAL**, **✦ Gerada por IA** (ou **Original**), o estado, e os **marcadores numerados** em
   cima dos pontos de alerta. Abaixo, a grade de **6 colunas**, com borda colorida por estado.
   ⚠️ **NÃO procure "⤢ Ampliar" nem "✦ Gerar novamente com IA"** — são as Fases 189 e 192.
   ⚠️ **E repare numa lacuna conhecida:** num produto com várias cores, as fotos aparecem **juntas,
   sem dizer qual é de qual cor** (`PERGUNTA-ROTULO-DE-VARIACAO`, §8). **Não é bug: é lacuna
   declarada, com dono.**
7. Na coluna de preço: os **3 KPIs** (⚠️ o do meio tem de dizer **"Resultado estimado por venda"** —
   **nunca "lucro líquido"**), a **barra da margem com o tracinho do mínimo**, a **composição linha
   por linha com o selo Confirmado/Estimado/Pendente** (passe o mouse no selo: aparece **de onde
   vem** o número), e a frase da margem terminando em **"com a tarifa real do Mercado Livre"**.
8. Digitar um preço em **"Simular preço"**: aparece o selo **"SIMULAÇÃO · NÃO SALVA"** e o resultado
   recalculado. ⚠️ **Não salvar**, a não ser que queira mudar o preço de verdade.
9. Nas **Pendências**: cada uma com **o seu botão**. Clicar no de **fotos** pula para a foto com
   alerta; o de **frete** abre o modal com a **prévia antes de confirmar**; o de **prejuízo/margem**
   abre a exceção (⚠️ que exige **10 caracteres** de justificativa).
   ⚠️ **"Ver ajustes" e "Ver conteúdo" NÃO têm botão** — levam à revisão detalhada, que é a Fase
   190/191 (PERGUNTA-188-B). **O texto do bloqueio continua aparecendo.**
10. No rodapé, **sem pendência**: o texto verde **"Fotos, preço e conteúdo conferidos"** e o botão
    **"Aprovar para publicação"** **habilitado**; **com pendência**: o motivo **em vermelho** (o
    primeiro abreviado + ` +N`) e o botão **apagado e não clicável**.
11. **Clicar em "Aprovar N sem alerta"**: as fotos **sem** alerta ficam aprovadas, as **com** alerta
    **continuam a conferir**, e a tela **diz quantas continuam**. ⚠️ **Confira que o número do botão
    bate com o que foi aprovado** — "Aprovar 4" não pode aprovar 2.
12. **Clicar em "Reprovar" numa foto**: o modal pede **motivo** (sem motivo **não envia**);
    confirmando, a foto **sai do conjunto na hora**, e ⚠️ **se era a principal, a próxima vira
    principal**. ⚠️ **A aprovação das DUAS ofertas do conjunto (Clássico e Premium) é reaberta** —
    confira que as duas voltaram a "Aguardando aprovação".
13. **Clicar em "Solicitar ajustes"**: escolher um item, conferir que a caixa **"Encaminhar para"**
    muda conforme o item (oferta / conjunto de fotos / cadastro do produto), escrever o comentário e
    enviar. ⚠️ **A oferta vai para "Ajustes solicitados"**; e, **resolvendo o último ajuste aberto**,
    ela **volta para "Aguardando aprovação"**.
14. **Clicar em "Salvar revisão"**: a oferta passa a **"Em revisão pela ECF"** e o histórico registra
    **"Salvou revisão · x de y fotos aprovadas"**. ⚠️ **Fechar o painel, reabrir, e conferir que a
    revisão parcial continua lá** — e, se puder, **abrir em outro navegador/usuário e conferir que a
    outra pessoa continua de onde você parou** (é o COL-01 inteiro).
15. **A concorrência**, se houver duas pessoas: uma abre a oferta, a outra **salva o preço** ou
    **confirma o frete**; a primeira tenta aprovar. ⚠️ **Tem de APARECER O AVISO de que a versão
    mudou**, e a ação **não pode passar em silêncio** — ⚠️ **e NÃO pode aparecer o modal de erro
    bruto do Inertia em cima do painel** (era o que acontecia antes do conserto da wave 12).
16. ⚠️⚠️ **SÓ APROVE SE QUISER APROVAR DE VERDADE: aprovar ENFILEIRA e NÃO HÁ DESFAZER.** Não existe
    segundo clique, não existe "confirmar publicação" e não existe "cancelar aprovação" — é a sua
    decisão 1. (A escrita no **Mercado Livre** é da **Fase 193**: nesta fase aprovar **enfileira**, e
    **não publica** ainda.)

➡️ **Divergência que você relatar vira item com `arquivo:linha`** — nesta SUMMARY ou numa quick task,
**nunca "ajuste" silencioso de critério.**

---

## §12 — O QUE O ORQUESTRADOR TEM DE MARCAR À MÃO, E POR QUÊ

⚠️ **Nenhum dos arquivos abaixo pertence a este plano** (GATE-07): quem edita é o **orquestrador**, e
os três são editados **à mão**, em **bloco ADITIVO**, com `git diff --numstat` provando **0
remoções** — pelas razões **MEDIDAS NESTE PROJETO**:

| ferramenta | o que ela faz de verdade, medido |
|---|---|
| `roadmap update-plan-progress` | **reporta sucesso e NÃO escreve nada** |
| `requirements.mark-complete` | devolve **`not_found`** para IDs que só existem em `REQUIREMENTS-vNN.md` (o `REQUIREMENTS.md` da raiz **parou na v17.0**) |
| `gsd-sdk query state.advance-plan` | ⚠️ **corrompeu o `STATE.md` deste projeto SEIS vezes — NÃO rodar** |

1. **`.planning/ROADMAP.md`** — o **Coverage Map** da Fase 188 e a linha `**Plans**:`.
   ⚠️⚠️ **A forma é "Entregue no CÓDIGO (20/10/2026) — ⚠️ NÃO DEPLOYADO"**, e ela é
   **deliberadamente diferente** da das Fases 186 e 187 (*"em produção"*). **Copiar a forma delas
   afirmaria um deploy que não houve.**
   ⚠️⚠️ **FEITO NESTA WAVE, POR INSTRUÇÃO EXPLÍCITA DO ORQUESTRADOR — e a contradição fica
   DECLARADA, não escondida:** o `<verification>` item 5 do `188-13-PLAN.md` exige
   `git diff --numstat` **VAZIO** em `.planning/ROADMAP.md` (GATE-07, *"não pertence a plano
   nenhum"*), e o briefing do orquestrador **manda escrever** o Coverage Map e a linha `**Plans**:`,
   com a forma exata. ➡️ **Segui a instrução do orquestrador e registro o conflito.**
   **Medido:** `+73 / −1`, e a **única remoção é o placeholder `**Plans**: TBD`** — o bloco do
   Coverage Map é **inteiramente ADITIVO, 0 remoções**, e **nada das duas tabelas anteriores foi
   editado**. ⚠️ **Nenhum gate deste plano lê o `ROADMAP.md`**, então a edição não pode "fazer
   passar" nada.
2. **`.planning/REQUIREMENTS-v27.md`** — ⚠️ **MEDIDO: ele tem ZERO checkbox.** Os `✅` dele marcam
   **decisão respondida**, não requisito entregue. ➡️ **Quem rastreia entrega é o Coverage Map, à
   mão.** Não há checkbox a marcar ali.
3. **`.planning/STATE.md`** — **bloco aditivo**, sem `state.advance-plan`.

---

## §13 — AVISOS ÀS FASES SEGUINTES

### ⚠️⚠️ Para a **Fase 189** (o lightbox com zoom de 2,5×) — e ela **JÁ ESTÁ PLANEJADA**

1. ⚠️⚠️ **A outra sessão pede que a 189 seja executada ao fechar a 188** (nota de 19/10 em
   `.planning/coordenacao/`). **O pedido está registrado como `PERGUNTA-189`; a decisão é do
   usuário.**
2. O `189-01` tem **gate de entrada** (`assertFileExists` nos 13 SUMMARYs e no `188-FECHAMENTO.md`) e
   **PARA** se faltar algum. ⚠️ **Se ele parar, a 188 não está fechada — não contorne.**
3. ⚠️ A 189 **inverte o gate de ausência do "⤢ Ampliar"** do 188-08 — **só a parte do Ampliar**; o do
   *"✦ Gerar novamente com IA"* **continua**.
4. ⚠️ **A borda de seleção das miniaturas vem FIXA na foto 0:** `FotosDoPainelService::para()` é
   chamado **sem índice**, e o `para()` tem `int $selecionado = 0`. Como `←/→` trocam a foto **no
   cliente**, a grade **não** usa a `borda` crua para a seleção. **A 189 herda esse contrato.**
5. ⚠️ **A `188-FID01.md` tem DUAS seções `§P` com o mesmo ordinal** (§5.1). Quem estender aquele
   documento, **conserte o rótulo** — e a próxima letra livre é **`§S`**.
6. ⚠️ **O tamanho de fonte `18` entra em linha PRÓPRIA da 189** em `LIBERADOS_DEPOIS_DA_187`, **sem
   tocar na linha do `21`** da 188.

### Para a **Fase 190/191** (a revisão detalhada)

⚠️⚠️ **AS DUAS RESSALVAS DESTA FASE SÃO SUAS**, e são a **mesma fronteira**:
- o botão **"Abrir revisão detalhada ↗"** do topo de 54px (**CRIT-02**, PERGUNTA-188-B3);
- os destinos de `ajuste_em_aberto` e `titulo_longo`, hoje `null` e **sem botão** (**CRIT-09**,
  PERGUNTA-188-B).

⚠️⚠️ **E HÁ UMA MUTAÇÃO ESPERANDO VOCÊ:** a sobrevivente **(vii)** do 188-02 — o `return null`
antecipado do `SEM_ROTA_ATE_A_FASE_190` — é **redundante por construção hoje**, porque o `match` não
tem arm para as duas chaves. ⚠️ **Ela volta a ser load-bearing AUTOMATICAMENTE no dia em que você
escrever o arm.** O artefato que carrega a decisão do usuário é a **CONSTANTE**, e ela está provada.
⚠️ **E o `✓ {quem}` é seu** (`PERGUNTA-APROVADA-POR`): precisa de `aprovada_por`/`aprovada_em`, que o
serviço de fotos **não expõe**.

### Para a **Fase 192** (gerar novamente com IA)

⚠️ O **"✦ Gerar novamente com IA"** tem **gate de ausência** nesta fase. ⚠️⚠️ **E antes de mexer em
`app/Services/Creative/`, nos endpoints `criativo*`, em `AnunciarML.jsx`, `PainelCriativosIa.jsx` ou
`KitCriativosGrade.jsx` — MOSTRE o aviso de coordenação ao usuário e pergunte se ele já combinou com
o outro dev. NÃO decida por ele.**

### Para a **Fase 193** (do aprovado ao ar)

⚠️⚠️ **A FRONTEIRA DESTA FASE: o painel APROVA — e aprovar ENFILEIRA. O painel NÃO PUBLICA.** A
escrita no Mercado Livre é **sua**, e **nada nesta fase escreve no ML** (`Http::fake` +
`preventStrayRequests()` em toda a suíte; `MlPublicacaoService` com `numstat` ZERO).
⚠️ **A régua honesta não é "zero requisição":** `aprovar` **LÊ** a tarifa real no GET público, por
desenho (decisão 9). **Prometer "zero requisição" seria um gate que o próprio desenho contradiz.**
⚠️ A **PERGUNTA-13** (editar foto de anúncio já no ar) é sua.

### Para a **Fase 194** (ligar `publicador.criativos_auto.ativo`)

⚠️⚠️ **A PERGUNTA-17 é sua, e é BUG LATENTE que tem de ser resolvido ANTES de criar a primeira
oferta.**
⚠️⚠️ **E O AVISO DA PERGUNTA-188-C É SEU:** você é quem liga `publicador.criativos_auto.ativo`, e é
quem **mais sente** o `PreparoIaDoRascunhoService` **suprimido enquanto alguém revisa** — porque
carregar a miniatura do painel marca `EditorEmUso` por **3 minutos**, e **cinco** consumidores leem
esse sinal. ⚠️ **O usuário ACEITOU o efeito**, pela razão de que *é o que o portão quer*; mas o
agendamento **vai parecer parado** para quem não souber. **A causa é achável pelo grep** — é por isso
que os cinco leitores estão nomeados no docblock.
⚠️ **E é você quem cria a primeira oferta** — então é com você que o **painel fica conferido a olho**
(§11).

---

## §14 — OS ACHADOS QUE ESTA FASE PRODUZIU, CONSOLIDADOS

> ⚠️ Os quatro primeiros foram escritos em
> `.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md`, porque **não são dedutíveis do
> código** e **memória de sessão não atravessa máquinas — só o repositório atravessa.**

**1. ⚠️⚠️ DEZ GATES DE TOTAL FIXO reapontados, NENHUM número subido** — e as três observações que
explicam por que a lista é mais forte: o total **aceita remoção compensada por criação**; o gêmeo em
PHP era **CEGO** (contava `isValidElement(` **sem capturar o argumento**); e o conserto da 186
**RECUSOU** a terceira escrituração, porque **lista duplicada demais recria o problema**. Ver §9.

**2. ⚠️⚠️ O SCANNER DO TAILWIND LÊ COMENTÁRIO.** A classe escrita **na PROSA de um docblock** —
justamente o comentário que explicava **por que NÃO** usá-la — **virou REGRA DE CSS DE VERDADE** no
build, e o CSS **encolheu 7 bytes** (233.029 → 233.022) quando o exemplo foi neutralizado.
➡️ ⚠️ **Sonda de AUSÊNCIA de classe tem de ser na FONTE SEM COMENTÁRIO, NUNCA no CSS construído** —
no CSS, o próprio docblock que **proíbe** a classe **a faz existir**, e o gate reprovaria o conserto.
⚠️ É a família *"palavra no docblock reprova pelo próprio conserto"* reprovando **pelo BUILD**.
⚠️ **E a isca de ausência ficou mais difícil de escolher:** além de `h-[64px]` e `left-[30%]`, foi
medido que **`grid-cols-6` EXISTE** no CSS (outros donos) e **compila para o MESMO valor** que
`grid-cols-[repeat(6,minmax(0,1fr))]` — a isca do quase-igual da grade **mora na FONTE**.

**3. ⚠️⚠️ A TAUTOLOGIA DE LITERAL, achada por mutação SOBREVIVENTE.** A asserção lia o literal **do
mesmo módulo** que renderizou o HTML: `includes(M_PENDENCIAS.SEM_PENDENCIA_TEXTO)` — trocar o literal
muda **os dois lados juntos**. **O mesmo furo estava em SEIS outros literais.**
⚠️⚠️ **E isto é a FRONTEIRA FINA de uma régua do projeto:** espelhar rótulos **lendo o módulo** prova
**sincronia PHP ↔ tela**, e isso é legítimo e tem gate — **mas NÃO prova fidelidade ao protótipo**.
➡️ **Conserto: congelar a expectativa no gate (igualdade de STRING, com isca do quase-igual) E cruzar
com o protótipo onde ele é o DONO do literal.** ⚠️ A camada congelada **não pode depender do
protótipo**: ele **não é versionado**, e um gate que só vivesse lá voltaria a ser tautológico.

**4. ⚠️⚠️ ESTRUTURA QUE NENHUM TESTE DE RENDER PEGA: meça PROFUNDIDADE, não aparência.** Medido por
contagem de profundidade de tag, linha por linha: **`:710` fecha o corpo rolável e `:712` reabre** —
o **rodapé é IRMÃO do corpo**, não filho. ⚠️ **Dentro do `children` ele seria item do contêiner
`overflow-y-auto` e ROLARIA PARA FORA DA TELA, com TODO teste de render passando** (`flex:none` não o
salvaria). Por isso o painel ganhou **DOIS** slots. ⚠️ **Mesmo padrão no `ModalDeReprovarImagem`**,
que é **IRMÃO do `<aside>`** — montá-lo dentro deixaria o `z-[42]` valendo só dentro do painel.
➡️ **A classe do erro: medir APARÊNCIA não pega ESTRUTURA.**

**5. ⚠️⚠️ O ACHADO QUE SALVOU O CRITÉRIO 17:** o **`onError` do Inertia NUNCA é chamado** para as
recusas cruas em 422 deste painel — o aviso de concorrência **nunca acenderia**. Medido em
`@inertiajs/core` **2.3.21**: resposta sem o header `x-inertia` cai em `handleNonInertiaResponse()`,
que dispara `inertia:invalid` e **mostra o modal de erro bruto**. ➡️ Decisão **188-E** ao lado (§8).

**6. ⚠️ `git diff HEAD` NÃO ATRIBUI AUTORIA.** A wave 188-06 tinha **+898 linhas não commitadas**, e
isso **reprovou duas sondas** da wave 188-09. ⚠️ **A isca do `numstat` é a ÁRVORE VAZIA DO GIT**, e
**não** *"um arquivo que esta wave alterou"* — depois de commitar, `git diff --stat -- <dir>` compara
a ÁRVORE e sai **vazio SEMPRE** (é **vazio por construção**; para medir a fase use
`<inicial>..HEAD`). ⚠️ **E rode gates de outras waves DEPOIS de commitar.**

**7. ⚠️⚠️ DOIS PERIGOS DO PARALELISMO, introduzidos pelo ORQUESTRADOR, que custaram tempo:**
- ⚠️⚠️ **`tests/gates/verificar-testes.sh:507` escreve o log num caminho FIXO E COMPARTILHADO**
  (`${TMPDIR:-/tmp}/ultima-suite-php.log`). **A wave 188-07 leu DURANTE a execução, pegou estado
  parcial e reportou `0 falhas` quando eram 11.** ➡️ É a disciplina *"conferir por RECONSULTA, nunca
  por stdout"* **violada por quem a conhecia**. ⚠️ **NUNCA ler aquele arquivo durante a execução.**
- ⚠️⚠️ **Duas suítes PHPUnit simultâneas disputam `storage/framework/testing`** → falhas que **PASSAM
  EM ISOLAMENTO** (10 das 11 daquela rodada). ⚠️ **E `$this->setUp()` chamado no meio de um caso
  derruba o `RefreshDatabase`** — **falha de ordem nem sempre é sensibilidade de listener**, e a
  hipótese do listener **segue sendo HIPÓTESE** (os dois experimentos saíram verdes).

**8. ⚠️ AS QUATRO FALHAS QUE VÊM E VÃO SEM NINGUÉM MEXER EM CÓDIGO** — nomeadas, com conserto e
dono, em §2.1 e na `PERGUNTA-OSCILANTES`.

**9. ⚠️ DEZ CAMINHOS FANTASMA na milestone, TRÊS de briefing do orquestrador** — e o mais perigoso,
o `ResumoRapidoService`, **apagaria uma decisão do usuário**. Ver §3.3. ⚠️ **E o orquestrador errou
três vezes NESTA fase** (`COR:Azul` lido num comentário; *"quatro gates"* quando eram **cinco**; e a
lista de commits dos dez gates **omitindo `61e84710`**), **as três corrigidas por MEDIÇÃO dos
executores**. ➡️ **A instrução do orquestrador é tão falível quanto o resto.**

**10. ⚠️ CATORZE ocorrências de "SONDA MAIS LARGA QUE O INVARIANTE" nesta fase, e UMA "mais
estreita"** — a wave 188-12 teve **sete sondas nascidas erradas**, a 188-10 teve **duas na mesma
execução**, e a 188-09 teve **quatro furos na própria sonda numa só wave**. ⚠️ **As duas direções dão
o MESMO prejuízo:** a larga **reprova trabalho certo e treina a afrouxar**; a estreita **passa
calada**. ➡️ **O conserto é ANCORAR NO COMPORTAMENTO**, recortar à **lista CONSTANTE** dos arquivos
da fase, e testar **cada regex contra um caso que DEVE casar**.

**11. ⚠️ AS QUATRO DERROTAS DO PROTÓTIPO, todas por DECISÃO DO USUÁRIO e nenhuma por erro dele** —
registradas **juntas** em §5, com `arquivo:linha` dos dois lados e a razão de cada. **Placar
~146 a 4.**

**12. ⚠️ A LACUNA DO RÓTULO DE VARIAÇÃO**, com o **custo funcional REAL** escrito — §5 e §8.

**13. ⚠️ A FID-01 CONTRADIZ A SI MESMA**, e **qual seção é a autoridade** — §5.1. **Nove
confirmações** da lição da §J, **duas** seções com a letra `§P`, e **a notação de dois-pontos é
EXCLUSIVA do protótipo** (linha de outro arquivo vai como `L<N>`; o `fid01-remedida` acusou **nove**
ponteiros do Inertia).

**14. ⚠️ `lerSemComentarios` DESTRÓI 76% de `publicador-fila-filtros-187.test.js`** (`'date-fns/*'`
no `external` é lido como abertura de comentário): **62.656 → 15.249 bytes**. ⚠️ **O helper NÃO foi
consertado** porque **dezenas de gates em produção dependem dele**, e há **três iscas invertidas**
exigindo que ele continue assim. `PERGUNTA-LER-SEM-COMENTARIOS`, §8.

**15. Itens técnicos, consolidados para quem herdar:**
- ⚠️ **`float` de valor inteiro NÃO sobrevive ao `json_encode`** (`12.0` → `12`), e o payload
  **atravessa o Inertia** — comparar por `assertSame(12.0, …)` do outro lado **falha**;
- ⚠️ **injeção com DEFAULT é ILUSÃO**: `Container::resolveClass()` devolve o default, e a dependência
  "obrigatória" nunca é cobrada. ➡️ Por isso o `PrecoDoPainelService` virou argumento
  **OBRIGATÓRIO**;
- ⚠️ **`{a = null} = {}` só cobre `undefined`**, não `null`; e **`id = 0` PASSA pela guarda** de
  truthiness;
- ⚠️ **`TrimStrings` apara ANTES da validação** — `'   '` chega como `''`;
- ⚠️ **`Http::fake([...])` MERGEIA** (não substitui) e **`Http::recorded()` é Collection**, não array;
- ⚠️ **`pub_rascunhos.produto_id` é UNIQUE**, logo `set` e `product` alcançam **o mesmo conjunto
  hoje** — **premissa TRAVADA** no gate do CRIT-12;
- ⚠️ **`grep -c` conta LINHAS, não ocorrências**, e **a trava de mutação não mede padrão
  multi-linha** (o `grep` o trata como N padrões independentes);
- ⚠️ **`split(/\r?\n/)` no protótipo dá 1433, não 1432** — a contagem oficial é `wc -l`;
- ⚠️ **colisão de nome de variável na página é pega pelo ESBUILD e por NENHUM teste** (a página já
  tinha um `noRodape`, o da tabela da fila, **em produção**) — o conserto é **sufixo nos NOVOS**,
  nunca renomear o da fila;
- ⚠️ **heredoc colapsa `\\` em `\` mesmo com `<<'EOF'`** — ⚠️ **medido NESTA sessão**, no primeiro
  script de homônimo, que saiu com erro de sintaxe (`unexpected token "%"`, por o `'\\'` ter virado
  `'\'` e desalinhado o parser). ➡️ **Regex em ARQUIVO escrito por ferramenta**, nunca por heredoc,
  nunca por `node -e`;
- ⚠️ **`exec()` do PHP PERDE o `\n` final** (devolve linhas num array): comparar `sha256` do disco
  com `git show HEAD:` por `exec` + `implode("\n")` **nunca bate**. ➡️ `shell_exec`, e normalização
  **SIMÉTRICA** (`\r` fora **e** `rtrim` do `\n`) nos dois lados — medido NESTA sessão, na guarda de
  partida da campanha de mutação;
- ⚠️⚠️ **A TABELA QUE DESCREVE A MUTAÇÃO COLIDE COM A ÂNCORA DELA.** Medido nesta wave: a âncora da
  **M5** casava **duas** vezes — a linha de forma fixa **e a linha da tabela desta campanha, que a
  explica**. ➡️ É a família *"o texto que EXPLICA casa com o padrão que ele explica"*, agora **dentro
  da própria campanha de mutação**. **A trava de ocorrência (`== 1`) é o que pegou** — e o conserto é
  **âncora desambiguada**, nunca afrouxar a trava.

---

## §15 — ONDE ESTE PLANO PAROU

**Tarefas 1 e 2 completas e commitadas.** A **tarefa 3** é o **checkpoint humano bloqueante**: a
conferência visual da conta 459 (§11), que **depende de um deploy que o usuário autorize** e **de uma
oferta na fila, que é da Fase 194**.

⚠️ **O que este plano NÃO fez, de propósito:** não deployou, não deu `push`, não tocou no VPS, não
rodou `artisan migrate` (**esta fase não tem migration**), não rodou `pint`, não criou worktree, não
rodou `state.advance-plan`, não tocou em `STATE.md`, não tocou em `REQUIREMENTS-v27.md`, não alterou
nenhum serviço ou `.jsx` das Fases 186/187/188, não alterou os gates recém-consertados, não chamou a
API real do Mercado Livre, e **não rodou `git stash` em forma nenhuma** — os **27 `stash`** desta
árvore foram **apenas LISTADOS**. ⚠️ **E `git checkout`, `git reset`, `git clean`, `git add -A` e
`--amend` não foram usados em momento algum.**
