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
PARTIDA:   A MEDIR    <- `git rev-parse --short HEAD`, a PRIMEIRA acao do 193-01, antes de qualquer leitura
COMMIT:    A MEDIR
BUILD:     A MEDIR    <- so para NAO-REGRESSAO: esta fase nao edita .jsx
MANIFEST:  A MEDIR
JS:        A MEDIR
PHP:       A MEDIR    <- o RECORTE (tests/Unit/Publicador + tests/Feature/Publicador), pelo --suite php
RECORTE:   A MEDIR    <- tests/Feature/Publicador/Aprovacao, medido separadamente
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

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
de não-regressão, e o número do manifest tem de ficar **IGUAL**.
