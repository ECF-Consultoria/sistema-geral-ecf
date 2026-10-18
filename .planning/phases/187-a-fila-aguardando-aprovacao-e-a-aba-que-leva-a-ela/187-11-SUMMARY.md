---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 11
subsystem: publicador-fila-de-aprovacao
tags: [fechamento, baselines, territorio, fid-01, mutacao, gates, medicao-datada]
requires:
  - "187-01 a 187-10 — as dez SUMMARYs, os dez manifestos e o 187-BASELINES.md"
  - "186 — o FechamentoDaFase186Test, cujas onze armadilhas este arquivo herda e RE-MEDE"
provides:
  - "187-FECHAMENTO.md — a medição datada da fase: 15 critérios, 4 baselines, território, FID-01, mutação, perguntas"
  - "tests/Feature/Publicador/Aprovacao/FechamentoDaFase187Test.php — 10 métodos em 4 ids de <=3"
  - "tests/js/publicador-fila-fechamento-187.test.js — a contagem FIXA dos arquivos da fase, medida UMA vez"
  - "tests/gates/contagens-187-11.txt — 5 ids (o 11º manifesto da fase)"
  - "learnings §4c.3 e §4c.4 — dois casos novos, por bloco ADITIVO"
affects:
  - ".planning/ROADMAP.md (bloco aditivo: Coverage Map da Fase 187 + a linha **Plans**)"
  - ".planning/phases/187-.../deferred-items.md (as 6 dívidas da fase, com DONO)"
tech-stack:
  added: []
  patterns:
    - "gate de FORMA sobre documento de medição datada: ele exige a forma, NUNCA o número"
    - "gate ANTI-FANTASMA: cada caminho citado como 'território intocado' é conferido no disco"
    - "T-187-SC pela FORMA (import), não por numstat de range — o range não discrimina sessão paralela"
    - "território por ESCOPO DO ASSUNTO, em duas passadas independentes, com isca nas duas direções"
    - "lista CONSTANTE em vez de piso, quando o invariante é PRESENÇA de um item específico"
key-files:
  created:
    - .planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela/187-FECHAMENTO.md
    - tests/Feature/Publicador/Aprovacao/FechamentoDaFase187Test.php
    - tests/js/publicador-fila-fechamento-187.test.js
    - tests/gates/contagens-187-11.txt
  modified:
    - .planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela/deferred-items.md
    - .planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md
    - .planning/ROADMAP.md
decisions:
  - "O gate NÃO usa range de git, e a razão foi MEDIDA nesta fase (não herdada): o range tem 43 commits da sessão paralela e acusa 12 dos 60 alvos"
  - "O gate transversal NÃO varre `app/` inteiro — recorte à lista CONSTANTE da fase, provado com isca (a varredura larga da 186 barrou um deploy)"
  - "As duas sobreviventes de mutação consertadas por lista CONSTANTE e por invariante de comportamento — NUNCA elevando o piso"
  - "Nenhum critério foi reescrito para caber: 12 OK, 3 RESSALVA, 0 REPROVA"
metrics:
  duration: "~1 sessão"
  completed: "2026-10-18"
  commits: 8
  gates: "5 ids / 10 métodos PHP + 6 testes JS"
  mutations: "11 mutações, 13 execuções, 0 sobreviventes ao fim"
---

# Fase 187 Plano 11: O fechamento medido — Summary

Os **15 critérios** da Fase 187 provados um por um em linha de forma fixa (**12 OK, 3 RESSALVA,
0 REPROVA**), as **quatro baselines RE-MEDIDAS** no commit do fechamento, o território conferido por
**escopo do assunto** em duas passadas independentes, a **FID-01 consolidada** com `SCRIPT`/`LEITURA`
declarado por plano, e **nove achados que só o fechamento viu** — inclusive que a *"Prova 1 por
range"* prescrita pelo próprio plano **não pode passar** nesta fase.

---

## 1. O que este plano NÃO fez, e é a parte mais importante

⚠️⚠️ **Nenhum critério foi reescrito para caber.** Três não passaram integralmente e receberam
**RESSALVA com a razão e o dono** — nunca uma redação nova que os tornasse verdadeiros.

⚠️ **NÃO deployei, NÃO dei push, NÃO toquei no VPS, NÃO rodei `artisan migrate`.**
⚠️ **NÃO rodei `state.advance-plan`**, e `STATE.md` e `REQUIREMENTS-v27.md` têm `numstat` **vazio**.
⚠️ **NÃO consertei** nenhuma falha alheia: as duas de `CockpitDaContaTest` (Fase 179), a de
`estrutura-grade-glide.test.js` (Fase 87) e a de `VinculoDuravelDaOriginalTest::terr_11` (Fase 186)
ficaram como estavam, **nomeadas**.
⚠️ **NÃO criei `REF_PHP_FALHAS`**, e não deve passar a existir.

---

## 2. Os três ACHADOS que contradizem o próprio plano, registrados e não contornados

### 2.1 ⚠️⚠️ A "Prova 1 — diff VAZIO por RANGE" **não pode passar nesta fase**

Rodei o que o plano prescrevia, de propósito. Medido:

```
COMMITS_NO_RANGE = 111   COMMITS_DA_FASE = 68   ALHEIOS = 43
RANGE_CRU_ALVOS_ACUSADOS = 12   (de 60)
```

Os 12 acusados são **todos** da sessão paralela (escopos `197`-`201`, `v27`, `v28`, `portal`,
`quick-261016-*`, `186-06`). ➡️ Escrever esse gate deixaria **12 vermelhos**, e o conserto barato
seria **tirar alvos da lista** ou **estreitar o range** — o treinamento que o learnings §4b.2 manda
evitar. **Registrei a medida e substituí a prova por uma que mede o invariante certo** (escopo do
assunto, duas passadas, 0 violações, isca nas duas direções).

### 2.2 ⚠️⚠️ Quatro CAMINHOS FANTASMAS — e o `ResumoRapidoService` existe

| caminho CITADO | caminho **REAL** |
|---|---|
| `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` | `app/Services/Publicador/Fila/ResumoRapidoService.php` |
| `app/Services/Publicador/SimuladorVoceRecebe.php` | `app/Support/Publicador/Validacao/SimuladorVoceRecebe.php` |
| `app/Services/Publicador/AnaliseAlavancasService.php` | `app/Services/Publicador/Alavancas/AnaliseAlavancasService.php` |
| `resources/js/Pages/Mlb/Publicador/useCriativosDoPublicador.js` | `resources/js/Components/Publicador/useCriativosDoPublicador.js` |

⚠️⚠️ **CORREÇÃO AO BRIEFING:** ele diz que o fantasma do `ResumoRapidoService` tem como real o
`FilaDeAprovacaoService.php`. **A primeira metade está certa, a segunda não.** O
`ResumoRapidoService` **existe** e **não é** o `FilaDeAprovacaoService`: é o serviço da **visão
rápida do lote**, aquele cujo `ResumoRapidoService:384-391` a **decisão 9 do usuário** cita como o
lugar onde a margem usa o **parâmetro da Precificação** e não a tarifa real do ML. **Confundi-los
apagaria uma decisão do usuário.**

➡️ E mais **dois** caminhos do próprio briefing eram fantasmas: `CockpitDaContaTest.php` **não** está
em `Aprovacao/` (**nono** fantasma da milestone), e ele usa o relógio **52** vezes, não 54.

### 2.3 ⚠️ O "Teste 4" do plano é literalmente FALSO

O plano pede *"nenhum arquivo novo da fase é `.js` — todos são `.jsx`"*. Há **9** `.js` novos, e
**todos** são testes (`tests/js/*.test.js`). ➡️ **O invariante certo, e o que implementei:** *nenhuma
peça nova sob `resources/` é `.js`* — porque o `content` do Tailwind varre `**/*.jsx` e **não**
`**/*.js`, então classe num `.js` não compilaria, **com todo teste verde**.

---

## 3. As quatro baselines, RE-MEDIDAS

```
COMMIT: 6ba51bd6
BUILD: exit 0 em 63s
MANIFEST: 495 entradas / 202 paginas / isca ausente
JS: 5489 / 5488 pass / 1 fail / 0 skipped
PHP: 2493 / 21920 / 2
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e
PROTOTIPO design_handoff_publicador/Publicacoes v2.dc.html: 659 linhas sha256 56e6c194b896151c
PROTOTIPO new_layout_publicador/Publicacoes v2.dc.html: 656 linhas sha256 6dcbb2f9ff8782e0
```

⚠️ **RE-MEDIDAS, não copiadas.** Contra a referência das waves 09/10 (`7791d7aa`: PHP 2483 / 21818 /
0 · JS 5483 / 5482 pass / 1 fail / 0 skipped), o delta é **+10 testes PHP / +102 asserções** e **+6
testes JS** — **exatamente** o que este plano acrescenta, e nada mais.

⚠️ **As 2 falhas PHP são as DUAS alheias previstas**, e nenhuma outra: `CockpitDaContaTest:966`
(esperava `12`, obteve `0`) e `:1318` (esperava `'hoje'`, obteve `'ontem'`), localizadas por
**semântica**. ⚠️ **A falha PHP da baseline de partida (`VinculoDuravelDaOriginalTest::terr_11`)
DESAPARECEU** — registrado, não investigado: não é território deste plano.

Ver `187-FECHAMENTO.md` §2 e §2.1 para a tabela mecânica completa contra o `187-BASELINES.md`.

⚠️ **Os três protótipos têm `sha256` IDÊNTICO ao registrado no `187-BASELINES.md`** — **nenhum se
moveu durante a fase**, e os dois do `design_handoff_publicador/` batem também com o fechamento da
Fase 186.

⚠️ **As duas falhas PHP de `tests/Feature/Publicador/CockpitDaContaTest.php` (Fase 179) são
fragilidade de FRONTEIRA DE DIA**: vermelhas na wave 08, verdes nas 09 e 10, vermelhas outra vez
hoje. ⚠️ **Nenhum arquivo de cockpit está no range de commits desta fase** (medido). **A régua para
quem medir depois: 2 é o "antes" herdado; mais de 2, ou um teste DIFERENTE, É da fase.**

---

## 4. Os 15 critérios

| veredicto | quantos | quais |
|---|---|---|
| **OK** | **12** | 01, 04, 05, 06, 07, 08, 09, 10, 11, 13, 14, 15 |
| **RESSALVA** | **3** | **02** (decisão 17, dono Fase 194) · **03** (a fila nasce vazia; dono do dado: Fase 194) · **12** (runtime do timer não provado — não há navegador) |
| **REPROVA** | **0** | — |

⚠️ **São 15, não 17** — contados por mim no `ROADMAP.md`:
`grep -cE "^  [0-9]+\. "` devolve **15**, numerados 1..15 sem buraco. **17 é a contagem da 186.**

---

## 5. A mutação deste plano, e as duas sobreviventes

**11 mutações · 13 execuções · 0 sobreviventes ao fim.** Scripts com nome que **carrega o plano**
(`mutar-187-11.sh`, `sha-187-11.sh`) — a lição de §6.1 do fechamento.

| sobrevivente | o que era | conserto |
|---|---|---|
| **M5** — tirar o `dono:` da **PERGUNTA-17** | ⚠️ **LACUNA REAL.** As asserções eram **PISO**, e com 13 perguntas perder uma deixava 12 — acima de todos os pisos. **Piso não prova PRESENÇA** | **lista CONSTANTE** das 13 perguntas obrigatórias, infratores coletados, isca de id inventado. ⚠️ **NÃO elevei o piso** |
| **M6** — tirar uma menção a "conta 459" | a mutação estava **mal apontada** (`conta 459` aparece 3 vezes). Mas a investigação achou o furo: **o gate provava uma MENÇÃO, não o ROTEIRO** | os **8 itens numerados de 1 a 8 sem buraco**, recortados à §11, mais o aviso "não há desfazer". Reapontada como **M6b**, morre |

⚠️ **E o gate novo reprovou código CERTO três vezes** — gate mais LARGO que o invariante (contava
itens numerados no documento inteiro: 23 em vez de 8), recorte **vazio por construção** (`.` sob a
flag `/s` engole o documento) e gate mais ESTREITO que o invariante (espaço literal numa frase que o
markdown **quebra**). **As três com isca escrita ao lado, nas duas direções.**

⚠️ **E um defeito meu que o gate pegou ANTES de qualquer mutação:** `str_contains` acusava os 9
métodos **cobertos** como *"sem id no manifesto"*, porque os filtros são **alternações**. O invariante
não é "o nome está escrito no manifesto", é **"algum filtro RODA este método"**.

---

## 6. Deviations from Plan

### Auto-fixed Issues

**1. [Regra 1 - Bug] O gate de cobertura de id usava `str_contains` com filtro por alternação**
- **Found during:** tarefa 2, primeira rodada do gate
- **Issue:** os 9 métodos cobertos eram acusados como "sem id"
- **Fix:** `preg_match` de cada filtro contra cada nome de método, com isca de método inventado
- **Commit:** `f056b52a`

**2. [Regra 1 - Bug] `CRIT-15` dizia "10 de 10 por SCRIPT" e contradizia a §5 (8 SCRIPT + 2 SEM-PROTOTIPO)**
- **Fix:** corrigido para o que foi **medido**, não o contrário
- **Commit:** `f056b52a`

**3. [Regra 1 - Bug] O recorte de seção usava `.` sob a flag `/s` e devolvia o documento inteiro**
- **Fix:** `[^\n]*` no cabeçalho, `[\s\S]*?` no corpo, com isca nas duas direções
- **Commit:** `eb989104`

**4. [Regra 2 - Funcionalidade crítica ausente] Piso não provava PRESENÇA de pergunta específica**
- **Found during:** a campanha de mutação (M5 sobreviveu)
- **Fix:** lista CONSTANTE das 13 perguntas obrigatórias
- **Commit:** `eb989104`

**5. [Regra 2 - Funcionalidade crítica ausente] O gate provava MENÇÃO à conta 459, não o ROTEIRO**
- **Found during:** a investigação da M6
- **Fix:** 8 itens numerados sem buraco, recortados à §11, mais o aviso "não há desfazer"
- **Commit:** `eb989104`

### ⚠️ Desvios DECLARADOS da prescrição do plano

| prescrição | o que foi feito, e por quê |
|---|---|
| *"Teste 2: diff VAZIO no range da fase"* | **substituída** pela prova por **escopo do assunto** + o **gate anti-fantasma** perene. A prescrição é **impossível** nesta fase (§2.1), e o conserto barato dela seria encolher a lista de alvos |
| *"Teste 4: nenhum arquivo novo da fase é `.js`"* | **estreitada** ao invariante real (`resources/`), com isca. Como escrita, é falsa (§2.3) |
| *"Teste 5: `numstat` ZERO nos 4 arquivos de dependência"* | **medido** (vazio nos quatro, no range inteiro) **e registrado**; o **gate perene** é pela **FORMA** (import), porque `numstat` de range não discrimina a sessão paralela |
| `tests/js/publicador-vocabulario-novo.test.js` listado em `<files>` | **NÃO modificado.** Este plano **não cria nenhum `.jsx`**, então não há o que declarar em `ARQUIVOS_NOVOS`. Mexer nele sem invariante por trás seria mudança sem razão — e ele é o arquivo de contagem mais frágil da fase |
| *"Teste 3: a contagem FIXA em `ARQUIVOS_NOVOS`"* | medida por **DOIS caminhos independentes** que bateram (**20 = 20**): git `--diff-filter=A` sob `resources/` e as entradas da Fase 187 naquela lista. A asserção lê o **disco** e confere a **declaração** — em vez de ler o texto de outro teste como se fosse fonte |

### Nenhuma arquitetural (Regra 4). Nenhum gate de autenticação. Nenhum pacote instalado.

---

## 7. O que o ORQUESTRADOR ainda tem de fazer

| item | por quê |
|---|---|
| `.planning/STATE.md` — bloco **aditivo** à mão | `state.advance-plan` corrompeu o STATE deste projeto **seis** vezes. **Eu não toquei no arquivo** |
| `.planning/REQUIREMENTS-v27.md` — checkboxes à mão | `requirements.mark-complete` devolve `not_found` para IDs de `REQUIREMENTS-vNN.md`. **Eu não toquei no arquivo** |
| apresentar ao usuário o **roteiro de 8 itens da conta 459** | a conferência visual é **dele**, é **em produção**, e **segue PENDENTE** |

⚠️ O **Coverage Map da Fase 187** e a linha **`Plans`** do `ROADMAP.md` **já foram escritos**, como
**bloco ADITIVO** (`numstat`: **44 inserções / 0 remoções**), com a forma **"em produção
(18/10/2026, commit `7791d7aa`)"** — e **não** a forma *"NÃO DEPLOYADO"* da Fase 186, que deixou de
ser verdade.

---

## 8. Known Stubs

**Nenhum.** Este plano não escreve código de produção: ele escreve **um documento de medição** e
**dois arquivos de gate** que leem o disco.

⚠️ O que **parece** stub e não é: a **fila nasce vazia** (decisão P3 do usuário — quem cria oferta é
a **Fase 194**) e os **4 slots nomeados** da página, todos preenchidos ao longo das waves 05 a 09.

---

## 9. Self-Check: PASSED

**Arquivos criados — conferidos no disco:**

```
FOUND: .planning/phases/187-.../187-FECHAMENTO.md              (1017 linhas)
FOUND: .planning/phases/187-.../187-11-SUMMARY.md
FOUND: tests/Feature/Publicador/Aprovacao/FechamentoDaFase187Test.php  (844 linhas)
FOUND: tests/js/publicador-fila-fechamento-187.test.js                 (317 linhas)
FOUND: tests/gates/contagens-187-11.txt                                 (5 ids)
```

**Commits — conferidos por `git log --oneline --all | grep`:**

```
FOUND: cde70dfb  docs(187-11) fechamento parte 1
FOUND: b4100387  docs(187-11) fechamento parte 2 (FID-01)
FOUND: ce7ff302  docs(187-11) fechamento parte 3 (mutacao, manifestos, perguntas)
FOUND: 8bc75ffc  test(187-11) os gates do fechamento
FOUND: f056b52a  fix(187-11) o gate pegou um defeito MEU
FOUND: eb989104  test(187-11) as duas sobreviventes investigadas
FOUND: 6ba51bd6  docs(187-11) mutacao deste plano + learnings §4c + ROADMAP aditivo
FOUND: 541ef4b6  docs(187-11) a procedencia de cada fato do ambiente
```

**Os 5 gates deste plano, rodados pelo harness:**

```
fechamento-15-criterios            PASSOU   (3 passed / 27 assertions)
territorio-e-fid                   PASSOU   (3 passed / 32 assertions)
fechamento-perguntas               PASSOU   (3 passed / 32 assertions)
fechamento-fronteira               PASSOU   (1 passed /  6 assertions)
fechamento-arquivos-e-manifestos   PASSOU   (6 tests / 6 pass / 0 fail / 0 skipped)
```

**O harness:** `AUTOTESTE: 20 casos, 0 divergencia.`

**Território:** `ALVOS=60 FANTASMAS=0 COMMITS_DA_FASE=68 PASSADA1_VIOLACOES=0 PASSADA2_VIOLACOES=0`

**Mutação:** `13 execucoes · 2 sobreviventes INVESTIGADAS · 0 sobreviventes ao fim`, com `sha256` da
árvore batendo com `git show HEAD:` nos **quatro** arquivos depois de **cada** rodada.

**Marcação à mão:** `ROADMAP.md` **44 inserções / 0 remoções** · learnings **47 inserções / 0
remoções** · `STATE.md` e `REQUIREMENTS-v27.md` **intocados**, e `state.advance-plan` **não
executado**.
