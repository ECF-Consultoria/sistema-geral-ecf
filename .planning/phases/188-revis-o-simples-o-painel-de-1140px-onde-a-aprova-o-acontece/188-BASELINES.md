# 188-BASELINES — as quatro baselines da Fase 188, medidas ANTES de editar

> **Os outros 12 planos LEEM daqui.** O plano 188-01 é o único que re-mede.
> *"Um fechamento que afirma número velho é pior que um fechamento sem número"* — e o mesmo vale
> para a abertura: se estas linhas estiverem erradas, os 12 planos seguintes medem contra
> referência falsa.
>
> ⚠️ **REGISTRADO, NÃO AJUSTADO.** Contagem **ACIMA** da referência da 187 é **esperada** (a sessão
> paralela acrescenta testes); **ABAIXO** é regressão. A referência não se "ajusta".

---

## §1 — AS LINHAS DE FORMA FIXA, com o comando que produziu cada uma

Todas medidas em **19/10/2026**, nesta máquina, **antes de qualquer edição de arquivo deste plano**
(a ordem real foi: `rev-parse` → suíte PHP → build → manifest → protótipo → suíte JS → `--autoteste`
→ **só então** a primeira edição).

```
PARTIDA:   db9b1ff9ad9f5c270fd0e2f91a245da82f9329d4   (curto: db9b1ff9)
COMMIT:    db9b1ff9          ← as 6 medições abaixo rodaram NESTE HEAD
BUILD:     exit 0 · 79 s
MANIFEST:  495 entradas / 202 paginas / isca ausente / controle presente
JS:        5489 / 5488 pass / 1 fail / 0 skipped
PHP:       2493 / 21915 / 0
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

| linha | comando exato |
|---|---|
| `PARTIDA` | `git rev-parse --short HEAD` — **a primeira ação da sessão** |
| `COMMIT` | idem (igual à partida: nenhuma edição precedeu as medições) |
| `BUILD` | `T0=$(date +%s); npm run build; echo $?; T1=$(date +%s); echo $((T1-T0))` |
| `MANIFEST` | `node <script>.cjs public/build/manifest.json` — **pelo JSON**, nunca por grep |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` |
| `PROTOTIPO` | `test -e … && wc -l < … && sha256sum …` |

### ⚠️ O `MANIFEST` é lido pelo JSON, e a razão é medida

`node --input-type=commonjs` (ou arquivo `.cjs`), porque o `package.json` é `"type":"module"`.
**Nunca por `grep`:** o hash deste build saiu com **hífen** — `assets/app--5yuaubs.js` — e um
`grep -E "app-[A-Za-z0-9]*\.js"` **não casaria**.

| sonda | resultado |
|---|---|
| **ISCA** `resources/js/Pages/Mlb/NaoExiste9z7.jsx` | **ausente** ✓ (se saísse presente, a leitura é cega) |
| **CONTROLE** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` | **presente** ✓ |
| **CONTROLE** `resources/js/app.jsx` | **presente** ✓ |
| `resources/css/app.css` **como chave** | **ausente** — e está certo (divergência **N5** da 187) |
| o CSS por `manifest['resources/js/app.jsx'].css` | `["assets/app-DtSI74zM.css"]` ✓ |

⚠️ **Página React fora do manifest SOME da aplicação, sem erro nenhum.** É por isso que o controle
positivo existe: um manifest que perdesse a `Aprovacao.jsx` passaria num gate que só contasse
entradas.

### ⚠️ O protótipo **não se moveu**

`sha256` completo: `68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2` — os 16
primeiros hexas batem com o esperado (`68624883ce35547e`), e as **1432** linhas também.
➡️ **A tabela FID-01 inteira continua medindo o MESMO documento.**
⚠️ **Há duas cópias do pacote de handoff na árvore** (`design_handoff_publicador/` e
`new_layout_publicador/`) **com conteúdo diferente**; `find . -maxdepth 3 -name Aprovacao.dc.html`
devolve **só** `./design_handoff_publicador/Aprovacao.dc.html`. **Cite sempre o diretório.**
⚠️ **Nenhum dos dois é versionado (`??`)** — gate que dependa de lê-los **degrada para `skip`
EXPLÍCITO e NOMEADO**, nunca para "conferido".

---

## §2 — A TABELA MECÂNICA: baseline da 187 × re-medido agora × veredicto

Referência: fim da Fase 187, commit **`6ba51bd6`**, 18/10/2026 (`188-REGUA.md` §8).

| número | 187 (`6ba51bd6`) | agora (`db9b1ff9`) | veredicto | razão |
|---|---|---|---|---|
| BUILD exit | 0 | **0** | **IGUAL** | — |
| BUILD segundos | (não registrado) | **79 s** | **NOVO** | a 187 não registrou o tempo; fica como primeira medida |
| MANIFEST entradas | 495 | **495** | **IGUAL** | nenhum `.jsx` novo entrou ainda — este plano não cria componente |
| MANIFEST páginas | 202 | **202** | **IGUAL** | idem |
| JS testes | 5489 | **5489** | **IGUAL** | ⚠️ **não é abaixo** — a régua só reprova ABAIXO |
| JS pass | 5488 | **5488** | **IGUAL** | — |
| JS fail | 1 | **1** | **IGUAL** | a pré-existente, nomeada em §3 |
| JS skipped | 0 | **0** | **IGUAL** | — |
| PHP testes | 2493 | **2493** | **IGUAL** | — |
| PHP asserções | 21.920 | **21.915** | ⚠️ **−5** | **DIVERGÊNCIA DECLARADA** — ver §2.1 |
| PHP falhas | 2 | **0** | ⚠️ **MELHOR** | as 2 alheias estão **VERDES hoje** — ver §3 |
| PROTÓTIPO linhas | 1432 | **1432** | **IGUAL** | — |
| PROTÓTIPO sha256 | `68624883ce35547e` | **`68624883ce35547e`** | **IGUAL** | — |

### §2.1 — ⚠️ As **5 asserções a menos** no PHP: o que foi DESCARTADO por medição

**Não é regressão de teste, e isso foi PROVADO, não presumido:**

```
git log --oneline 6ba51bd6..db9b1ff9                    → 8 commits, TODOS `docs(…)`
git log --oneline --name-only 6ba51bd6..db9b1ff9 -- tests/   → (vazio)
```

➡️ **ZERO commits tocaram `tests/` entre a referência da 187 e a PARTIDA.** Então a diferença
**não pode** ser teste removido, asserção apagada ou mudança de suíte — a contagem de **testes é
idêntica** (2493) e o conteúdo de `tests/` é byte-a-byte o mesmo.

**A causa que sobra, e ela é a mesma de §3:** os dois testes de **fronteira de dia** percorrem um
**ramo diferente** conforme a hora, e ramo diferente exercita número diferente de asserções. Com
eles **vermelhos** (18/10) a suíte contou 21.920; com eles **verdes** (hoje) contou 21.915.
Medido hoje, isolado: os dois juntos passam com **42 asserções**.

⚠️ **Régua para os 12 planos seguintes:** o número de asserções do PHP **não é invariante** nesta
suíte enquanto esses dois casos existirem. ➡️ **Compare TESTES e FALHAS; trate asserções como
informativo.** O harness já faz exatamente isso: ele tem `REF_PHP_TESTS` e **não** tem referência de
asserções.

---

## §3 — ⚠️⚠️ AS FALHAS QUE **NÃO SÃO DESTA FASE** — nomeadas por SEMÂNTICA

### As 2 do PHP (Fase 179, **em produção**) — e **hoje elas estão VERDES**

Localizadas por semântica (nunca por número de linha), no arquivo
**`tests/Feature/Publicador/CockpitDaContaTest.php`**:

| # | método (linha da declaração) | a asserção de fronteira (linha) | o que ela compara |
|---|---|---|---|
| 1 | `test_publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias` (`:941`) | `:966` | `assertSame(12, $kpi['serie'][29], 'a última posição é HOJE')` |
| 2 | `test_evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo` (`:1308`) | `:1318` | `assertSame(['hoje', now()->subDays(3)->toDateString(), 'sem_data'], …)` |

**Conferido individualmente hoje:**
```
artisan test --filter="test_(publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias|evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo)"
  ✓ publicacoes criadas mede as duas janelas e a serie de trinta dias     4.07s
  ✓ evento sem data vai para o ultimo grupo nunca para o topo             0.11s
  Tests: 2 passed (42 assertions)
```

➡️ ⚠️ **O verde de hoje CONFIRMA o diagnóstico da régua em vez de contradizê-lo:** elas foram
**vermelhas 16/10, verdes 17/10, vermelhas 18/10 e verdes 19/10 — sem uma linha de código mudar**
(provado em §2.1: nenhum commit tocou `tests/`). É **fragilidade de FRONTEIRA DE DIA**: o arquivo
consulta o relógio **53** vezes (`grep -cE "now\(\)|today\(\)|Carbon::now|setTestNow"`), e o último
commit dele é literalmente *"ancora o relogio no caso de agrupamento da Atividade"* — alguém já
consertou **um** caso e deixou estes dois.

⚠️ **NÃO consertar. NÃO contar como regressão. NÃO criar `REF_PHP_FALHAS`** (o harness declara, na
própria fonte, que esse limiar **não deve passar a existir**).

⚠️ **O caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE** — é o
**nono caminho fantasma** desta milestone. Conferido agora com `test -e`: o real é **fora** de
`Aprovacao/`.

### ⚠️⚠️ A RÉGUA, ATUALIZADA PELA MEDIÇÃO DE HOJE

A régua herdada dizia *"2 nesta família é o antes"*. **Hoje o antes é `0`.** Para não virar um
limiar que perdoa falha nova, a régua dos 12 planos seguintes é:

1. **Falhas PHP `0`** é o estado medido na abertura — **é o alvo.**
2. Se uma das **duas** da tabela acima voltar a vermelho, **é a oscilação conhecida** — registre e
   siga; **não é regressão sua**.
3. ⚠️ **Falha em QUALQUER OUTRO teste, ou mais de 2 falhas, É DA FASE** — investigue, não registre.
4. ⚠️ **Testes PHP abaixo de 2493 é regressão**, sempre.

### A 1 do JS (Fase 87) — pré-existente, aceita pelo harness

Nomeada por semântica pela própria saída do `--suite js`:
```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
```
➡️ `tests/js/estrutura-grade-glide.test.js:122` (confirmado por `grep -rn` do título).
**Não corrigir** — é o `REF_JS_FALHAS=1` cravado no harness.
⚠️ **Mais de 1 falha JS, ou falha em outro arquivo, É DA FASE.**

---

## §4 — O HARNESS ESTÁ SADIO NESTA MÁQUINA: `--autoteste` 20/0

Sem esta prova, o próprio conserto do harness vira o próximo caso do learnings §4.
⚠️ **Rodado ANTES de qualquer edição.** Saída integral:

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

⚠️ **Os casos 15 e 16 são o que guarda o 1º argumento.** `sh … verificar-testes.sh 01 <id>` resolve
`contagens-186-01.txt` — **a fase ERRADA, em silêncio**. A compatibilidade é **DE PROPÓSITO** (a
Fase 186 está em produção e seus 163 ids são chamados pelo número nu). ➡️ **Nesta fase o 1º
argumento é sempre `188-NN`.**

---

## §5 — O que este plano **NÃO** mediu, e por quê

| não medido | razão |
|---|---|
| estado de produção / VPS | **proibido** nesta fase; e a leitura de produção pode estar bloqueada |
| tela renderizada no navegador | **não há navegador**; o banco local não tem empresa com `ml_token` (a rota dá **404**) e `pub_aprovacao_ofertas` está **vazia** |
| migrations pendentes | ⚠️ **esta fase NÃO tem migration** e há pendente de **outra milestone** — `artisan migrate` **não foi e não deve ser** rodado |
| "árvore limpa" por `git status` | **ABORTA** pelos `??` de outras sessões (há mais de 30) — a conferência é `git diff HEAD` **nos arquivos deste plano** |
