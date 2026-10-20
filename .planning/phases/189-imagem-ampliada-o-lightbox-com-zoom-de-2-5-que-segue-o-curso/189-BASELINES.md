# 189-BASELINES.md — as baselines MEDIDAS da Fase 189 (o lightbox)

**Medidas em 20/10/2026**, no HEAD de partida, **antes de qualquer edição de código desta fase**.
**Plano:** 189-01 (wave 1 de 7).

> ⚠️⚠️ **REGISTRE, NÃO AJUSTE.** Contagem **ACIMA** da referência de 19/10 é **esperada** (a Fase 188
> fechou com 13 waves e a sessão paralela acrescentou testes). Contagem **ABAIXO** é **regressão**.
> ⚠️ Cada linha abaixo traz **o comando que a produziu** — é o que separa baseline **medida** de
> baseline **afirmada** (T-189-02, *Repudiation*).

---

## §1 — AS NOVE LINHAS DE FORMA FIXA

```
PARTIDA:     e250f9fb  (e250f9fba0a0b7309b2836142af6812d05f4616d) — referência de LEITURA, nunca extremo de range
COMMIT:      e250f9fb  — o HEAD em que as duas suítes e o build foram medidos
BUILD:       exit 0 · 85 s
MANIFEST:    498 entradas / 203 páginas (lido pelo JSON) · isca AUSENTE · controle PRESENTE
JS:          5871 / 5870 pass / 1 fail / 0 skipped
PHP:         2583 / 23684 / 1
PROTOTIPO:   design_handoff_publicador/Aprovacao.dc.html — 1432 linhas · sha256 68624883ce35547e
ENTRADA-188: 14 arquivos conferidos por `test -e`, todos PRESENTES (13 SUMMARY + 188-FECHAMENTO.md)
PERGUNTAS:   189-A RESPONDIDA · 189-B RESPONDIDA · 189-C RESPONDIDA · 189-D ABERTA (não bloqueia)
```

### O comando de cada linha

| chave | comando que a produziu |
|---|---|
| `PARTIDA` | `git rev-parse --short HEAD` e `git rev-parse HEAD` — **a primeira ação desta sessão** |
| `COMMIT` | `git rev-parse --short HEAD`, reconferido antes de cada suíte (nenhum commit entre as medições) |
| `BUILD` | `S=$(date +%s); npm run build; echo $?; E=$(date +%s); echo $((E-S))` |
| `MANIFEST` | `node scratchpad/manifest-189-01.cjs public/build/manifest.json` — lê o **JSON**, nunca `grep` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` (ele roda `tests/Unit/Publicador` + `tests/Feature/Publicador`) |
| `PROTOTIPO` | `wc -l < design_handoff_publicador/Aprovacao.dc.html` e `sha256sum … \| cut -c1-16` |
| `ENTRADA-188` | laço de `test -e` sobre a lista CONSTANTE dos 14 arquivos da Fase 188 |
| `PERGUNTAS` | `grep -nE 'PERGUNTA-189-[A-D]:' 189-REGUA.md` (§6) |

⚠️ **Por que o `MANIFEST` é lido pelo JSON e nunca por `grep`:** o hash do bundle sai **às vezes com
hífen** (`assets/app--5yuaubs.js`) e o `grep` não casa. Medido hoje: a página de controle é
`resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` → `assets/Aprovacao-BOrPh3wq.js`.

⚠️ **A isca e o controle do MANIFEST, os dois exercidos:**
`resources/js/Pages/Mlb/NaoExiste9z7.jsx` → **AUSENTE** (como tem de ser) ·
`resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` → **PRESENTE**.
Sem a isca, "o manifest tem a página" não distinguiria leitura certa de leitura cega.

---

## §2 — A TABELA MECÂNICA: referência de 19/10 × re-medido em 20/10

Referência: nota de coordenação `261019-para-quem-planejar-a-fase-189…` §8 (medida no fim da wave
188-02). **Uma linha por número.**

| número | 19/10 (referência) | 20/10 (re-medido) | veredicto |
|---|---|---|---|
| JS — testes | 5498 | **5871** | **ACIMA** (+373) — esperado: a 188 fechou 13 waves |
| JS — pass | 5497 | **5870** | **ACIMA** (+373) |
| JS — fail | 1 | **1** | **IGUAL** — a mesma falha herdada, nomeada na §3 |
| JS — skipped | 0 | **0** | **IGUAL** |
| PHP — testes | 2510 | **2583** | **ACIMA** (+73) |
| PHP — asserções | 22264 | **23684** | **ACIMA** (+1420) — ⚠️ asserção **não é invariante** (mediu-se −5 com testes idênticos); compara-se **testes e falhas** |
| PHP — falhas | 0 | **1** | ⚠️⚠️ **ACHADO** — falha **ALHEIA**, atribuída na §3.3. **NÃO é regressão desta fase** e **NÃO foi corrigida** |
| MANIFEST — entradas | 495 | **498** | **ACIMA** (+3) |
| MANIFEST — páginas | 202 | **203** | **ACIMA** (+1) |
| BUILD — exit | 0 | **0** | **IGUAL** |
| PROTÓTIPO — linhas | 1432 | **1432** | **IGUAL** |
| PROTÓTIPO — sha256 | `68624883ce35547e` | **`68624883ce35547e`** | **IGUAL** — a `189-FID01.md` mede o documento certo |

⚠️ **O briefing do orquestrador trazia OUTROS números de referência** (JS 5848/5847/1/0 · PHP inteira
2577/23666/2 · MANIFEST 495/202), medidos por ele horas antes. Os meus estão **acima** nos três —
e isso é **esperado**, não divergência: o outro dev enviou 6 commits com testes novos.

---

## §3 — AS FALHAS QUE **NÃO SÃO DESTA FASE**, nomeadas por SEMÂNTICA e **NÃO corrigidas**

⚠️⚠️ **Nenhuma delas foi consertada, nenhuma conta como regressão, e NÃO existe `REF_PHP_FALHAS`** —
o próprio `verificar-testes.sh` declara, na fonte, que esse limiar **não deve passar a existir**.
⚠️⚠️ **E a régua segue valendo: qualquer OUTRA falha, ou mais de uma no JS, É DA FASE.**

### 3.1 — JS, a única: `tests/js/estrutura-grade-glide.test.js` (Fase 87)

Caso **"Características secundárias nasce recolhido (é o grupo que mais infla)"**.
**Atribuída por execução ISOLADA**, como a régua exige:

```
node --test --test-reporter=tap tests/js/estrutura-grade-glide.test.js
→ # tests 19 · # pass 18 · # fail 1 · # skipped 0
```

É a pré-existente herdada, e é o `REF_JS_FALHAS=1` cravado no harness. **NÃO corrigir.**

### 3.2 — PHP: `tests/Feature/Publicador/CockpitDaContaTest.php` (Fase 179) está **VERDE hoje**

A dupla que **oscila com a hora do dia** (`:966` esperava `12` e obteve `0`; `:1318` esperava
`'hoje'` e obteve `'ontem'`) **passou** nesta medição:

```
C:/xampp/php/php.exe artisan test tests/Feature/Publicador/CockpitDaContaTest.php
→ Tests: 50 passed (2394 assertions)
```

⚠️ **O caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE** — é um dos
caminhos fantasma desta milestone. O real está **FORA** de `Aprovacao/`.

### 3.3 — ⚠️⚠️ PHP, a falha NOVA e ALHEIA: um ponteiro de LINHA do gate 188-03 **deslocou**

**O caso:** `tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php` →
`test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03`, falhando em
`FotosDoPainelServiceTest.php:609` (*"Failed asserting that two arrays are identical"*).

**Atribuída por execução ISOLADA** (falha também sozinha — **não** é ordem nem corrida):

```
C:/xampp/php/php.exe artisan test --filter=test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03
→ Tests: 1 failed (33 assertions)
```

**A causa, MEDIDA por script** (`scratchpad/deslocados-189-01.cjs`): aquele gate **fixa o número de
linha** dos cinco leitores de `EditorEmUso::` (o efeito colateral da PERGUNTA-188-C). Quatro
continuam certos; **um deslocou em +1**:

| ponteiro fixado no gate 188-03 | ocorrências REAIS hoje | veredicto |
|---|---|---|
| `app/Services/Publicador/Fila/AgendadorDaFila.php:337` | `337` | OK |
| `app/Services/Publicador/PreparoIaDoRascunhoService.php:131` | **`132`, `181`, `367`** | ⚠️ **DESLOCOU +1** |
| `app/Support/Publicador/GarantiaPadrao.php:192` | `192` | OK |
| `app/Services/Publicador/ExcluirProdutoService.php:195` | `195`, `260` | OK |
| `app/Http/Controllers/MlbPublicadorController.php:405` | `405` | OK |

Na linha fixada `:131` está hoje `$r = PubRascunho::where('produto_id', $pub->id)->first();`.

**Quem moveu, atribuído por `git log`:** o commit **`cd582f83`** —
*"feat(portal): título de venda separado do nome, montado com os termos da categoria"* (19/10/2026),
**do OUTRO dev**, acrescentou uma linha acima de `:131`.

⚠️⚠️ **É pré-existente no HEAD de partida e NÃO pode ter sido causada por esta fase:**
`git diff HEAD --stat -- app/ tests/ routes/ resources/ config/ database/` sai **VAZIO** — o plano
189-01 não editou **uma linha** de código de produção.

⚠️⚠️ **NÃO FOI CORRIGIDA, de propósito, e a razão é régua deste projeto:** o conserto é somar `+1`
num número **de um gate da Fase 188**, e *"ajustar contagem alheia"* é exatamente o que
`feedback_gate_que_reprova_pelo_proprio_conserto` proíbe fazer sem o dono decidir. ➡️ **Devolvido
como pergunta.**

⚠️ **E ela é uma instância da fragilidade que este projeto já catalogou:** ponteiro de **linha**
fixado num arquivo que **outra sessão edita** quebra sozinho. O conserto de RAIZ não é o `+1` — é o
gate procurar `EditorEmUso::` **por semântica** (a ocorrência existe no arquivo) em vez de por número
de linha. **Decisão do dono da 188, não minha.**

⚠️ **E o mesmo desvio +1 atinge o BRIEFING desta sessão**, que cita
`PreparoIaDoRascunhoService:131`/`:180`/`:366`. **Medido: `:132`/`:181`/`:367`.**

### 3.4 — As duas de `SincronizaPortalCompletoTest` **não apareceram**

O briefing avisa que elas surgem por **ordem/corrida** e passam em isolamento. Nesta medição da
suíte inteira elas **não falharam** — a única falha PHP é a da §3.3.

---

## §4 — ⚠️⚠️ O INVARIANTE FALSO QUE EU **MEDI**: componente novo **NÃO** entra no manifest

**Medido hoje, pelo JSON do `public/build/manifest.json`:**

```
COMPONENTE_NO_MANIFEST[PainelDeRevisao.jsx]      = NAO
COMPONENTE_NO_MANIFEST[ImagemGrandeDoPainel.jsx] = NAO
CONTROLE (resources/js/Pages/Mlb/Publicador/Aprovacao.jsx) = PRESENTE → assets/Aprovacao-BOrPh3wq.js
```

A Fase 188 criou **19 `.jsx`** e o manifest tem **498 entradas / 203 páginas** — e **nenhum** dos
componentes de `aprovacao/` está lá. ➡️ **Componente aninhado é FUNDIDO no chunk da PÁGINA;
só a PÁGINA aparece no manifest.**

⚠️⚠️ **Portanto: todo gate (desta régua ou de plano futuro) que afirme *"o componente novo entra no
manifest"* é FALSO** — ele é **vazio por construção** (se só conferir ausência) ou **REPROVA trabalho
correto** (se exigir presença). **O que se confere no manifest é a PÁGINA.** Os `.jsx` do lightbox
(`LightboxDaImagem.jsx`, `LateralDoLightbox.jsx`, planos 189-03/189-05) **não vão aparecer** — e isso
é o comportamento certo, não defeito.

---

## §5 — O GATE DE ENTRADA: a Fase 188 está FECHADA

Os **14** arquivos conferidos por `test -e` (lista **CONSTANTE** no gate, com os ausentes coletados
numa asserção **única** — nunca uma `assertFileExists` por arquivo, que é a armadilha que a 186-09
deixou em produção):

`188-01-SUMMARY.md` · `188-02` · `188-03` · `188-04` · `188-05` · `188-06` · `188-07` · `188-08` ·
`188-09` · `188-10` · `188-11` · `188-12` · `188-13-SUMMARY.md` · **`188-FECHAMENTO.md`**

**Resultado: 14 de 14 PRESENTES.** A execução da 189 **não** colide com a 188.

---

## §6 — O ESTADO DAS PERGUNTAS (lido de `189-REGUA.md` §6)

| pergunta | estado | consequência para esta fase |
|---|---|---|
| **PERGUNTA-189-A** | **RESPONDIDA** (usuário, 19/10) — cabeçalho "VERIFICAÇÃO AUTOMÁTICA · APOIO" **uma vez** na lateral | 189-05 pode executar |
| **PERGUNTA-189-B** | **RESPONDIDA** (usuário, 19/10) — fiel ao protótipo: o lightbox **guarda o próprio contexto** | 189-06 e 189-07 podem executar |
| **PERGUNTA-189-C** | **RESPONDIDA** (usuário, 19/10) — ao fechar, volta à foto de onde se abriu | 189-06 pode executar |
| **PERGUNTA-189-D** | **ABERTA — NÃO BLOQUEIA** (defaults D1/D2 declarados) | 189-06/189-07 aplicam o default e registram |

⚠️ Este plano **não depende** de nenhuma delas; o registro existe porque os planos **189-05** e
**189-06** PARAM se A ou B estiver ABERTA.

---

## §7 — O AMBIENTE, medido

| item | medida |
|---|---|
| `php` no PATH | **NÃO** — usa-se `C:/xampp/php/php.exe` |
| `mysqld` local | **NÃO está em execução** (`tasklist \| grep mysqld` vazio) — nada desta fase depende dele |
| `git stash list` | **27 entradas**, de outras sessões — **apenas LISTADAS**, `git stash` não foi rodado em forma nenhuma |
| untracked alheios | `tests/Feature/CompanyPortfolioAccessTest.php` e `tests/js/.pub-lote187-inertia-stub-9672.mjs` — **intocados** |
| `git diff HEAD` em `app/ tests/ routes/ resources/ config/ database/` | **VAZIO** no início desta wave |
| `design_handoff_publicador/Aprovacao.dc.html` | **existe** (1432 linhas) |
| `new_layout_publicador/Aprovacao.dc.html` | **NÃO existe** — aquela cópia do pacote **não tem** este arquivo; por isso o diretório é sempre CITADO |
| `--autoteste` | **20 casos, 0 divergência** |
