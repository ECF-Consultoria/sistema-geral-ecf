---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 01
subsystem: publicador
tags: [vocabulario, gates, baselines, rename, tdd]
requires: []
provides:
  - "tests/gates/verificar-testes.sh — o único caminho pelo qual a Fase 186 lê saída de teste"
  - "tests/gates/contagens-186-01.txt — manifesto de contagens do plano 01"
  - "186-BASELINES.md — as 4 baselines da fase, medidas no commit de partida"
  - "O vocabulário 'Em análise pelo Mercado Livre' / 'Em análise pelo ML' nos 3 rótulos renderizados"
affects:
  - "Todos os outros 8 planos da Fase 186 (eles chamam o harness em vez de node --test / artisan test)"
tech-stack:
  added: []
  patterns:
    - "Gate de teste via harness versionado com --autoteste obrigatório"
    - "Contagem esperada em manifesto versionado, com dois regimes por gate de JS"
    - "Asserção negativa por recorte ao corpo (remoção dos qualificados), nunca grep cego"
key-files:
  created:
    - tests/gates/verificar-testes.sh
    - tests/gates/contagens-186-01.txt
    - tests/js/publicador-rename-em-analise-ml.test.js
    - .planning/phases/186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro/186-BASELINES.md
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx
    - resources/js/Components/Mlb/Publicador/visaogeral/acoes.js
    - resources/js/Components/Mlb/Publicador/publicacoes/AbasDeStatus.jsx
    - tests/js/publicador-identidade-nova-render.test.js
    - tests/js/publicador-paginas-anuncios-render.test.js
    - tests/js/publicador-publicacoes-busca.test.js
    - tests/js/publicador-publicacoes-tabela.test.js
    - tests/js/publicador-visao-geral-acoes.test.js
    - tests/js/publicador-visao-geral-recentes.test.js
decisions:
  - "O rótulo da aba vive no CONTROLLER, não em AbasDeStatus.jsx — o briefing apontava o arquivo errado porque o grep casou com um comentário"
  - "O gate do diff de acoes.js é 'diff filtrado sai vazio, com isca', não 'numstat = 1 1' — o numstat real ficou 14 1 por causa do comentário, que é trabalho certo"
  - "Os dois regimes de contagem de JS (817 / 809) são MEDIDOS, por causa da sonda de CSS com skip por mtime"
metrics:
  duration: "~3h"
  completed: 2026-10-14
  tasks: 3
  commits: 4
---

# Fase 186 Plano 01: Rename "Em revisão" → "Em análise pelo ML" + baselines e harness de gates — Summary

O rename do vocabulário do status do Mercado Livre em **três** rótulos renderizados (não dois), com
as **quatro baselines da fase medidas ANTES de qualquer edição** e o **harness de gates** que os
outros 8 planos vão usar — criado porque 18 dos 26 gates automatizados da fase estavam quebrados
por dois bugs do jeito PADRÃO de rodar teste neste projeto, em direções opostas.

## Commits

| # | hash | o que |
|---|---|---|
| 1 | `6e9c5149` | `feat(186-01)` harness de gates com autoteste + as 4 baselines |
| 2 | `da26aa08` | `test(186-01)` gate de ausência (RED: 55 testes, 48 pass, 7 fail) |
| 3 | `060aacdc` | `feat(186-01)` os três rótulos + as seis asserções reapontadas (GREEN) |
| 4 | — | `docs(186-01)` esta SUMMARY |

Commit de partida de **todo** gate de diff deste plano: **`d4aeaa30`**. Todos usam
**`d4aeaa30..HEAD`** — ⚠️ `git diff --stat -- <dir>` **sem range** compara a árvore e sai **vazio
por construção**, provando nada.

---

## 1. As quatro baselines — MEDIDAS ANTES DE EDITAR UM BYTE

⚠️ Este plano rodou **sozinho**, isolado dos planos 186-02 e 186-03 da mesma wave. Medir junto com
as edições dos outros contamina o "antes" — foi o que aconteceu na Fase 177. Mais: a suíte de PHP
(≈10 min) rodou em **background** e **nenhuma edição de produção foi feita enquanto ela rodava**,
porque o PHPUnit carrega os arquivos de forma lazy e editar no meio contaminaria a medição.

Arquivo: `.planning/phases/186-.../186-BASELINES.md`, em **linha de forma fixa conferível por
regex** (não em prosa — prosa pode ser inventada e nenhum gate notaria).

| baseline | valor medido | comando |
|---|---|---|
| **COMMIT** | `d4aeaa30` | `git rev-parse --short HEAD` |
| **BUILD** | `exit 0 em 58s` | `npm run build` com `date +%s` antes/depois |
| **MANIFEST** | `479 entradas / 196 paginas / isca ausente` | `node` sobre `public/build/manifest.json` **pelo JSON** |
| **JS** | `4482 / 4481 pass / 1 fail / 0 skipped` | `sh tests/gates/verificar-testes.sh --suite js` |
| **PHP** | `2103 / 16034 / 0` | `sh tests/gates/verificar-testes.sh --suite php` |

**JS e PHP bateram com a referência de 14/10 exatamente.** A única falha de JS é a pré-existente,
localizada por **SEMÂNTICA** (`grep -rln` pelo nome do teste, não pelo número da linha):

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
  -> tests/js/estrutura-grade-glide.test.js
```

⚠️ **NÃO foi corrigida.** É dívida anterior à fase e não pertence a nenhum plano dela.

**ISCA do manifest:** `resources/js/Pages/Mlb/NaoExiste9z7.jsx` → **ausente**. Sem ela, as 8 linhas
de "página presente" não provariam que a sonda enxerga.

### Depois do trabalho (para comparar)

| suíte | antes | depois | leitura |
|---|---|---|---|
| JS | 4482 / 4481 pass / 1 fail / 0 skipped | **4539 / 4538 pass / 1 fail / 0 skipped** | **+57 testes**, nenhuma falha nova, a pré-existente intacta |
| PHP | 2103 / 16034 / 0 | **2103 / 16034 / 0** | idêntico — o rename é só rótulo e nenhum teste de PHP assere o valor do `rotulo` |
| BUILD | exit 0 em 58s | **exit 0 em 31s** | manifest reconferido pelo JSON, isca ausente |

O `+57` fecha exatamente: **55** do arquivo de gate novo + **1** veto novo em
`publicador-publicacoes-busca.test.js` + **1** veto novo em
`publicador-paginas-anuncios-render.test.js`. ⚠️ **CONTAGEM DE TESTES, não de linhas.**

---

## 2. O harness de gates e a saída do `--autoteste`

`tests/gates/verificar-testes.sh` é o **único** caminho pelo qual esta fase lê saída de teste.
Ele existe porque **confirmei os dois bugs por medição própria nesta máquina**, em 14/10/2026:

**G1 — `node --test | grep "^# tests"` NUNCA PASSA.** Reporter padrão do Node v24.14.0 emite
`ℹ tests N` (U+2139), não `#`. Medido com `cat -A`:

```
$ node --test ok.test.js 2>&1 | tail -6 | cat -A
M-bM-^DM-9 pass 2$
M-bM-^DM-9 fail 0$
```

**G2 — `artisan test` precisa de ANSI removido ANTES do filtro.** A linha real do Collision nesta
máquina, capturada ao rodar o teste de verdade:

```
  [90mTests:[39m    [32;1m1 passed[39;22m[90m (22 assertions)[39m
```

➡️ `grep -E "Tests:[[:space:]]+1 passed \(22 assertions\)"` **não casa nela** com os escapes no
meio. Não é cosmético: é a diferença entre medir e não medir.

### Saída do `--autoteste` — as duas formas REPROVAM quando devem

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 5: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 6: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 7: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 8: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 9: No tests found. -> REPROVA (como esperado)
  OK   caso 10: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 11: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 12: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 13: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 14: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 14 casos, 0 divergencia. As duas formas REPROVAM quando devem.
EXIT=0
```

**14 casos**, não 8: os **8 exigidos** pelo plano (casos 1-3 e 5-9), mais **2 iscas de forma** e
**4 de manifesto incompleto**, que acrescentei porque sem elas o próprio conserto deste arquivo
viraria o próximo caso da lista do learnings:

- **caso 4 (isca G1):** o caso que PASSA com `tap` tem de **REPROVAR** sem ele. Se esta isca
  passasse, o gate teria deixado de depender do `tap` e viraria outra coisa.
- **caso 10 (isca ANSI):** o caso que PASSA limpo tem de **REPROVAR** sem `limpar_ansi`. É a prova
  de que a limpeza não é cosmética.
- **casos 11-14:** manifesto sem `tests=`, com `tests=0`, sem `assertions=` no modo php, e com id
  ausente → todos **ABORTAM** (exit 2), nunca "passam por omissão". É a trava contra "deixa o
  número para depois".

⚠️ Os **casos 1-4 rodam `node --test` de verdade** (arquivos temporários, um verde e um vermelho) —
não é string sintética. Os **casos 5-10** usam o **envelope ANSI real capturado desta máquina**,
com só a linha `Tests:` variada, então o caminho de limpeza de ANSI é exercido contra bytes reais.

### As travas do manifesto

`tests/gates/contagens-186-01.txt`, três gates:

| id | modo | alvo | contagens |
|---|---|---|---|
| `gate-rename` | js | o arquivo de gate novo | `tests=55,55` |
| `rename-e-reapontes` | js | os 7 arquivos de JS tocados | `tests=817,809` |
| `abas-do-servidor` | php | `test_as_seis_abas_vem_na_ordem_do_servidor` | `tests=1 assertions=22` |

⚠️ **Os DOIS REGIMES de `rename-e-reapontes` são MEDIDOS, nenhum é chute**, e o motivo apareceu na
prática durante a execução: o teste *"FID — as classes GERAM CSS com o valor certo"* entra em
`SKIP` por **`mtime`** quando o CSS construído fica mais velho que os componentes, e leva **8
subtestes** consigo:

```
ok 42 - FID — as classes GERAM CSS com o valor certo (não só existem) # SKIP o CSS construído
     (assets/app-Bn2hY86e.css) é mais antigo que os componentes — rode `npm run build`
```

- **817** = CSS mais novo que os componentes (logo após `npm run build`)
- **809** = CSS mais velho (1 skipped no lugar dos 8 subtestes)

Travar só em 817 **abortaria a 1ª mutação da campanha**; não travar em nenhum marcaria a campanha
inteira como "SOBREVIVEU" por um motivo que não é o código.

⚠️ O helper **recusa filtro de CLASSE inteira** no modo PHP (exige nome começando com `test_`):
`artisan test --filter=<Classe>` passa dos 120 s do shell deste ambiente.

### Veredicto dos três gates

```
gate 'gate-rename' (js): 1 arquivo(s), contagens aceitas: 55,55
# tests 55 · # pass 55 · # fail 0 · # skipped 0            -> PASSOU

gate 'rename-e-reapontes' (js): 7 arquivo(s), contagens aceitas: 817,809
# tests 817 · # pass 817 · # fail 0 · # skipped 0          -> PASSOU

gate 'abas-do-servidor' (php): --filter=test_as_seis_abas_vem_na_ordem_do_servidor
  Tests:    1 passed (22 assertions)                       -> PASSOU
```

---

## 3. Os TRÊS rótulos (não dois)

| # | arquivo | antes | depois |
|---|---|---|---|
| 1 | `resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx` (`STATUS.em_revisao.rotulo`) | `'Em revisão'` | **`'Em análise pelo Mercado Livre'`** |
| 2 | `app/Http/Controllers/MlbAnuncioController.php` (`$abas`, chave `em_revisao`) | `'Em revisão'` | **`'Em análise pelo ML'`** |
| 3 | `resources/js/Components/Mlb/Publicador/visaogeral/acoes.js` (`FAIXAS.em_revisao`) | `'Em revisão'` | **`'Em análise pelo Mercado Livre'`** |

A 3ª é **decisão do usuário de 14/10/2026**: *é uma string só, e deixar um lugar dizendo "Em
revisão" enquanto o estado interno da ECF passa a ser "Em revisão pela ECF" recria exatamente a
ambiguidade que o rename existe para matar.*

⚠️ **A CHAVE `em_revisao` continua intacta nos três (TERR-08):** `?status=em_revisao` na URL, filtro
salvo, a validação de `escopo()` e os testes de comportamento dependem dela. O gate novo tem uma
asserção dedicada a isso. **T-186-01 mitigado:** zero diff na validação de `status`.

**Prova de conclusão:** `grep -rn "Em revisão" app/ resources/js/` acha **somente comentário** —
nenhum rótulo. Os comentários que sobraram são: os registros de decisão intocáveis
(`MlbAnuncioController.php:534,538`, `AcervoTriagemService.php:187,276`,
`CelulasDaLinha.jsx:391`) e os comentários novos que explicam o próprio rename.

### `AbasDeStatus.jsx` — diff 100% COMENTÁRIO, provado com isca

```
git diff d4aeaa30..HEAD -- <AbasDeStatus.jsx> | grep -E '^[+-]' | grep -v '^[+-][+-]' \
  | grep -vE '^[+-][[:space:]]*(//|\*|/\*)' | grep -vE '^[+-][[:space:]]*$'
  -> VAZIO (diff é 100% comentário)
ISCA (sem o filtro): 18 linhas  -> o comando NÃO está cego
```

⚠️ Ele **TEM** de estar em `files_modified` — e está. Sem isso o gate de território
(`--name-only` igual a `files_modified`) reprovaria o plano **por ele ter obedecido à própria ação**
de atualizar o comentário.

### `acoes.js` — o gate NÃO é `numstat = 1 1`

```
git diff d4aeaa30..HEAD -- <acoes.js> | grep -E '^[+-]' | grep -v '^[+-][+-]' \
  | grep -vE 'Em (revisão|análise)' | grep -vE '^[+-][[:space:]]*(//|\*|/\*)' | grep -vE '^[+-][[:space:]]*$'
  -> VAZIO  (PASSOU)
ISCA (sem o filtro): 15 linhas  -> o comando NÃO está cego
```

⚠️⚠️ **O `numstat` real ficou `14  1`, não `1  1`** — exatamente o que o plano previu ao recusar
aquele número como gate: **ele muda quando o trabalho certo é feito** (o comentário obrigatório ao
lado da mudança já o quebra), e número que muda com o trabalho certo faz o executor seguinte
ajustá-lo para passar. O gate que **mede o que importa** é o diff filtrado com isca, e ele passou.

**Resto de `visaogeral/`:** `git diff d4aeaa30..HEAD --name-only -- visaogeral/ ":(exclude)…/acoes.js"`
→ **VAZIO**, com `test -e` confirmado nos dois alvos (o diretório e o `acoes.js`). Isca: sem o
`exclude`, o mesmo comando devolve `acoes.js`. **TERR-05 preservado.**

---

## 4. As SEIS asserções reapontadas COM VETO (GATE-12)

Localizadas por **SEMÂNTICA** (texto do `test()`), nunca pelo número de linha. **Cirurgia é
reaponte com veto, nunca remoção:** cada uma passou a provar o rótulo NOVO **e** que o antigo não
voltou — ficou **mais forte**, não mais fraca.

| # | arquivo | o que era | o que virou | `-assert` / `+assert` | `-test` / `+test` |
|---|---|---|---|---|---|
| 1 | `publicador-identidade-nova-render.test.js` | `html.includes('Em revisão')` | `includes('Em análise pelo Mercado Livre')` **+ veto** `!includes('Em revisão')` | **1 / 2** | 1 / 1 (só o TÍTULO do teste mudou) |
| 2 | `publicador-paginas-anuncios-render.test.js` | fixture `rotulo: 'Em revisão'` | `rotulo: 'Em análise pelo ML'` **+ teste de veto novo** recortado à opção do trilho | **0 / 3** | 0 / 1 |
| 3 | `publicador-publicacoes-busca.test.js` | fixture `rotulo: 'Em revisão'` (de onde `ROTULOS_6` deriva) | `rotulo: 'Em análise pelo ML'` **+ teste de veto novo** no HTML do trilho | **0 / 2** | 0 / 1 |
| 4 | `publicador-publicacoes-tabela.test.js` | `under_review: ['Em revisão', …]` | `['Em análise pelo Mercado Livre', …]` **+ veto** na célula de `under_review` | **0 / 1** | 0 / 0 |
| 5 | `publicador-visao-geral-acoes.test.js` | `deepEqual(A.FAIXAS, { … em_revisao: 'Em revisão' … })` | o valor novo **+ veto** `!Object.values(A.FAIXAS).includes('Em revisão')` | **0 / 1** | 0 / 0 |
| 6 | `publicador-visao-geral-recentes.test.js` | `html.includes('>Em revisão<')` | `includes('>Em análise pelo Mercado Livre<')` **+ veto** `!includes('>Em revisão<')` | **1 / 2** | 0 / 0 |
| | **total** | | | **2 / 11** | **2 / 4** (nenhum teste removido) |

`+assert >= -assert` em **todos os seis**. ⚠️ `git diff | grep '^-assert'` acusaria "remoção" onde
houve **só reaponte** — a prova real é o par `-`/`+` **no mesmo teste**, enumerado acima, mais o
delta de contagem de testes (`+57`, todos explicados).

⚠️ **O veto usa recorte EXCLUSIVO, provado.** "Em revisão" é **SUBSTRING** de dois literais
legítimos — um gate cego reprovaria a tela certa:

| literal qualificado | onde foi MEDIDO (por script) |
|---|---|
| `Em revisão pela ECF` | `design_handoff_publicador/Aprovacao.dc.html:966` (`ecf_review`) e `:1212` (lista `ST`) — é o estado INTERNO da ECF, VOC-02, plano 186-02 |
| `Em revisão pelo Mercado Livre` | `design_handoff_publicador/Publicacoes v2.dc.html:440` (`reason` de `p5`) e `:462` (mapa `HELP`) — é o MOTIVO do status, texto de apoio |

Onde o veto é textual (`#2`, `#3`, `#6`) ele usa **`>Em revisão<`**, com os **dois delimitadores de
nó** — exclusivo do rótulo antigo, porque nenhum dos dois qualificados acima casa com ele. Onde é
estrutural (`#5`), o `deepEqual` do mapa **inteiro** já é o veto.

---

## 5. O gate de ausência: recorte ao corpo, isca, e auto-teste da sonda

`tests/js/publicador-rename-em-analise-ml.test.js` — **55 testes**, 260 linhas.

**Resposta de GATE-01, escrita no cabeçalho do arquivo:** *"o que precisaria estar errado no código
para este gate falhar?"* → **um rótulo de tela voltar a dizer "Em revisão" sem dizer de quem é a
revisão.** Nada mais o faz falhar: comentário não conta, literal qualificado não conta.

A técnica é **recorte ao corpo por REMOÇÃO dos qualificados** (não lookahead, que erraria por
sobreposição): apaga os legítimos do texto por `split`/`join` — sem regex, então literal com
parêntese não vira metacaractere por acidente — e **só então** procura o cru.

**Auto-teste da sonda (6 casos), o que distingue "o código está certo" de "a sonda está cega":**
qualificado passa · cru reprova · rótulo novo passa · `Em revisão pelo Mercado Livre` passa · o caso
**misto** (qualificado ANTES e cru DEPOIS) acha **exatamente um**, no lugar certo · a sonda conta
**todas** as ocorrências, não só a primeira.

**Outras travas, cada uma contra um gate vazio já medido neste projeto:**

- **`lerSemComentarios()`** (`tests/js/_fonte.js`): a prosa pt-BR deste projeto cita os próprios
  identificadores. Gate cru contaria os comentários de `AbasDeStatus.jsx:19` e
  `CelulasDaLinha.jsx:391` e passaria/reprovaria pelo motivo errado. **O RED provou que não conta**
  (ver abaixo: só 2 arquivos acusaram, e são os 2 que tinham o literal em CÓDIGO).
- **Piso de 30 arquivos varridos** (35 medidos: 15 + 8 + 12): varredura que acha **0** arquivo
  passaria **por ausência**. Há também um teste que exige que cada um dos 3 diretórios de hoje
  tenha contribuído com ao menos um arquivo.
- **ISCA DE CAMINHO `…/aprovacaoZZ9/`**: diretório que não existe e nunca vai existir, para provar
  que caminho ausente cai em **`skip` NOMEADO** e não é confundido com "varrido e limpo".
  `aprovacao/` (do plano 186-02) ainda não existe e é exigido como "declarado: varrido OU pulado".
- **Controle POSITIVO junto da negativa** (negativa sozinha é metade do gate) e **a chave
  `em_revisao` exigida intacta** nos três arquivos.
- `String.raw` em `new RegExp`, **todos** os metacaracteres escapados, mensagem de asserção **sem**
  `String()`/`JSON.stringify()` de valor adverso, e **montagem TODA antes do primeiro `test()`**.

### RED → GREEN (o gate não é vazio, e eu medi os dois lados)

```
RED  (commit da26aa08, antes do rename):  # tests 55 · # pass 48 · # fail 7
  not ok identidade/SeloDeStatusDaPublicacao.jsx — sem "Em revisão" cru
  not ok visaogeral/acoes.js — sem "Em revisão" cru
  not ok identidade/SeloDeStatusDaPublicacao.jsx diz "Em análise pelo Mercado Livre"
  not ok visaogeral/acoes.js diz "Em análise pelo Mercado Livre"
  not ok app/Http/Controllers/MlbAnuncioController.php diz "Em análise pelo ML"
  (+ 2 suítes-pai)

GREEN (commit 060aacdc, depois do rename): # tests 55 · # pass 55 · # fail 0
```

⚠️ O RED acusou **exatamente 2** achados de literal cru — `SeloDeStatusDaPublicacao.jsx` e
`acoes.js` — e **não** `AbasDeStatus.jsx` nem `CelulasDaLinha.jsx`, que também continham a string
mas **em comentário**. É a prova, por medição, de que o recorte por `lerSemComentarios` funciona.

---

## 6. Campanha de mutação — 3 mutações, uma por rótulo

Contagem travada **ANTES** (recusa se ≠ 1), linha mutada confirmada **depois**, restauração por
**cópia pristina** conferida por **`sha256`** — nunca `git checkout`.

| # | arquivo | linha | contagem antes | veredicto | restauração |
|---|---|---|---|---|---|
| 1 | `identidade/SeloDeStatusDaPublicacao.jsx` | 44 | 1 (travado) | **MORREU** em `gate-rename` | sha256 `ca7496db…` IGUAL |
| 2 | `app/Http/Controllers/MlbAnuncioController.php` | 995 | 1 (travado) | **MORREU** em `gate-rename` | sha256 `c08e4399…` IGUAL |
| 3 | `visaogeral/acoes.js` | 546 | 1 (travado) | **MORREU** em `gate-rename` | sha256 `e3ebeaeb…` IGUAL |

**Zero sobreviventes** — então não houve nada a investigar. Árvore conferida limpa depois
(`git diff --name-only -- app/ resources/` vazio).

**Prova extra dos dois regimes:** a mutação #1 rodada contra `rename-e-reapontes` reprovou com
`# tests 817 · # pass 807 · # fail 10` — ou seja, reprovou **pelo `# fail`**, que é o motivo certo,
e **não** por contagem, que é o motivo errado. Era exatamente para isso que os dois regimes estavam
no manifesto.

⚠️ **O que NÃO mata a mutação #2, de propósito:** o gate de PHP `abas-do-servidor` **sobreviveria**
a ela, porque `test_as_seis_abas_vem_na_ordem_do_servidor` assere as **CHAVES** e o **shape**
(`['chave','rotulo','total','comDot']`), **nunca o valor do `rotulo`**. Isso é correto — e é por
isso que a contagem dele (`1 / 22`) não se move com o rename. Quem mata a #2 é o controle positivo
do `gate-rename`. Registro aqui para ninguém "reforçar" o teste de PHP e acoplar comportamento a
rótulo.

---

## 7. Tabela FID-01 — derivada de SCRIPT

⚠️ **DECLARAÇÃO OBRIGATÓRIA: esta tabela é derivada de SCRIPT, não de leitura.** O script é
`diff` entre as duas versões do protótipo mais `grep -n` dos dois literais. Tabela de leitura não é
confiável: neste projeto ela erra por **OMISSÃO** e por **REFERÊNCIA**, nunca por valor.

Comando, com `test -e` em cada caminho antes de medir:

```
diff "new_layout_publicador/Publicacoes v2.dc.html" "design_handoff_publicador/Publicacoes v2.dc.html"
grep -n "Em revis\|Em análise" <cada um dos 3 .dc.html>
```

| # | dimensão | valor do protótipo | onde (MEDIDO) | implementado |
|---|---|---|---|---|
| 1 | literal do status | `'Em análise pelo Mercado Livre'` | `design_handoff_publicador/Publicacoes v2.dc.html`**:461**, `ST.pending` — hunk **3** do diff | `SeloDeStatusDaPublicacao.jsx` `STATUS.em_revisao.rotulo` ✅ |
| 2 | literal da aba | `'Em análise pelo ML'` | `design_handoff_publicador/Publicacoes v2.dc.html`**:534**, `tabsDef` — hunk **4** do diff | `MlbAnuncioController::meus()` `$abas` ✅ |
| 3 | chave de dado | inalterada (`pending` no protótipo ↔ `em_revisao` aqui) | — | chave `em_revisao` intacta nos 3 ✅ |
| 4 | tudo o mais do selo | inalterado | `Publicacoes v2.dc.html`:246-253 | **0** linhas de classe/medida no diff — o diff filtrado de `acoes.js` sai vazio e o do selo só tem o literal + comentário ✅ |
| 5 | faixa da Visão Geral | ⚠️ **FORA DO PROTÓTIPO** — decisão do usuário, 14/10/2026 | a barra de publicações do protótipo (`new_layout_publicador/Visao Geral.dc.html`**:536**, `pubBar`) só desenha `Ativas / Pausadas / Com erro` — **nunca teve esta faixa** | `visaogeral/acoes.js` `FAIXAS.em_revisao` ✅ |

**A medida #5 é marcada como fora do protótipo de propósito**, para a tabela não mentir sobre a
fonte: ela vem de decisão do usuário, não de medição de design.

---

## 8. ⚠️ Divergências medidas — todas com `arquivo:linha`

### D1 — O rótulo da aba vive no CONTROLLER, não em `AbasDeStatus.jsx` (corrige o critério 1 da fase)

O critério 1 do ROADMAP/briefing diz que o rótulo da aba está em
`resources/js/Components/Mlb/Publicador/publicacoes/AbasDeStatus.jsx`. **Está errado, e o protótipo
não tem culpa:** aquele componente **não carrega rótulo nenhum** — o docblock dele diz *"A ORDEM e
os RÓTULOS vêm do servidor (`MlbAnuncioController::meus()`, prop `abas`), nunca de uma lista
local"*.

**Causa-raiz, medida:** o `grep` do briefing casou com o **comentário** de
`AbasDeStatus.jsx:19`, que listava os 5 rótulos do protótipo em **prosa**. É a armadilha do próprio
learnings (busca que lê comentário), e ficou **registrada no código**, ali, para não se repetir.

O rename aconteceu em **`app/Http/Controllers/MlbAnuncioController.php:982`** (linha original;
`:995` depois do comentário novo).

### D2 — O diff dos protótipos tem **4** hunks, não 5 — e o rename é o **3º e 4º**, não o 4º e 5º

O plano diz: *"o rename é a mudança #4 e #5 dos 5 hunks que o `diff` dos dois protótipos devolve
(as outras três — borda do header, `gap`, abas — são da Fase 187)"*. **MEDIDO: são 4 hunks.**

| hunk | linhas | o que é |
|---|---|---|
| 1 | `31,32c31,32` | header ganha `border-bottom:1px solid #1F1F1F` **e** o `gap` do bloco de título vai de `6px` para `14px` — **duas** mudanças no **mesmo** hunk (Fase 187) |
| 2 | `34c34,37` | a frase descritiva vira o par de abas "Publicadas no Mercado Livre" / "Aguardando aprovação" (Fase 187) |
| 3 | `458c461` | **o rename do selo** (`ST.pending`) |
| 4 | `531c534` | **o rename da aba** (`tabsDef`) |

O plano contou **mudanças** (3 da Fase 187 + 2 do rename = 5) onde o `diff` devolve **hunks** (2 + 2
= 4). Substância idêntica, contagem diferente. Registrado porque um gate que conferisse "5 hunks"
reprovaria por motivo nenhum.

### D3 — ⚠️ Existem DUAS versões de `Publicacoes v2.dc.html`, com conteúdo DIFERENTE

| caminho | linhas | sha256 (12) | 5º rótulo |
|---|---|---|---|
| `new_layout_publicador/Publicacoes v2.dc.html` | 656 | `6dcbb2f9ff87` | `:531` → `'Em revisão'` |
| `design_handoff_publicador/Publicacoes v2.dc.html` | 659 | `56e6c194b896` | `:534` → `'Em análise pelo ML'` |

Elas **coexistem no disco**. As linhas medidas no cabeçalho de `AbasDeStatus.jsx`
(`:83/:85/:86/:88/:531/:532`) são da versão **`new_layout_`** (a da Fase 180); na versão nova elas
estão **+3** por causa dos dois hunks do header. **Citar "`Publicacoes v2.dc.html`:534" sem dizer
QUAL diretório é ambíguo** — e foi por isso que eu rodei `test -e` e `sha256sum` nos dois antes de
medir qualquer coisa. Ficou registrado no comentário do arquivo.

### D4 — `new_layout_publicador/Visao Geral.dc.html` **tem** o literal, em outro construto

O plano diz que esse protótipo *"não tem esse rótulo"*. **Medido: ele tem**, em
`new_layout_publicador/Visao Geral.dc.html:562`:

```
const ST = { active: ['Ativa', …], pending: ['Em revisão', …], error: ['Com erro', …] };
```

O que **não** tem é na **faixa** — `pubBar`, em **`:536`**, só desenha `Ativas / Pausadas / Com
erro`. ➡️ A **substância** da medida #5 (FID-01) está certa — o rename da faixa vem de decisão do
usuário, não do protótipo —, mas a **frase** do plano é imprecisa. **O protótipo ganha da tabela**
pela regra do projeto; aqui o protótipo diz `'Em revisão'` **para o selo da lista**, e a **decisão
explícita do usuário de 14/10** é o que vale para a **faixa**. Não "corrigi" nada em direção ao
protótipo: a decisão do usuário está acima dele.

### D5 — O 7º caminho fantasma, pego antes de virar medida

`design_handoff_publicador/Visao Geral.dc.html` **NÃO EXISTE**. O real é
`new_layout_publicador/Visao Geral.dc.html` (620 linhas, sha256 `bd3fee75…`). **Os protótipos vivem
em DOIS diretórios e eles não têm os mesmos arquivos.** Rodei `test -e` em **cada** caminho citado,
antes de usar.

### D6 — São **8** páginas do Publicador no manifest, não 7

O plano manda conferir *"as 7 páginas do Publicador"*. `ls resources/js/Pages/Mlb/Publicador/*.jsx`
→ **8**. A 8ª é `Editor.jsx`, **território do outro dev**: foi **conferida no manifest** (leitura
pura) e **não** tocada. Todas as 8 presentes; isca ausente.

### D7 — `numstat` de `acoes.js` é `14 1`, não `1 1`

Já detalhado na §3. O gate correto (diff filtrado + isca) passou; o número `1 1` do plano é um
número que **muda quando o trabalho certo é feito**.

---

## 9. Território e ambiente

`git diff d4aeaa30..HEAD --name-only` (⚠️ **com RANGE**) é **exatamente igual** a `files_modified`
— 14 arquivos, conferido por `diff` de listas ordenadas: **IGUAL**.

**Intocado, conferido um a um por `git diff --name-only -- <alvo>`:**
`app/Services/Creative/` · `resources/js/Components/Publicador/Mesa/` ·
`resources/js/Pages/Mlb/Publicador/Editor.jsx` · `app/Services/Mlb/Publicacao/MlPublicacaoService.php` ·
`resources/js/Layouts/AppLayout.jsx` · `app/Services/Bling/` · `app/Models/Bling*` (0) ·
`app/Services/Portal/` · `app/Support/Publicador/` ·
`app/Services/Publicador/Criativos/` · `resources/js/Pages/Mlb/AnunciarML.jsx` ·
`tests/js/estrutura-meus-anuncios.test.js` (**D-11/D-22 intocáveis**) ·
`tests/js/estrutura-grade-glide.test.js` · `tests/js/publicador-vocabulario-novo.test.js`
(**é do 186-02 — não encostei**) · `.planning/ROADMAP.md` · `.planning/STATE.md` ·
`.planning/REQUIREMENTS-v27.md`.

**T-186-SC mitigado:** `git diff d4aeaa30..HEAD -- package.json package-lock.json composer.json
composer.lock` → **vazio**. **Este plano não instalou pacote nenhum.**

**Disciplina de árvore compartilhada:** todo commit por **pathspec** (`git add -- <caminhos>` e
`git commit -F <arquivo> -- <caminhos>`, com `-F` antes do `--`). **Nunca** `git add -A`,
`git stash`, `git reset`, `git checkout`, `git commit --amend`, `git clean`. Nenhum deploy, nenhum
push, nada no VPS. Nenhuma leitura de produção.

**Banco:** não subi banco e não rodei migration (migration é do plano **186-03**). Os testes usam
**SQLite em memória** (`phpunit.xml`); `tasklist | grep mysqld` → **vazio** (MariaDB desligado).

⚠️ **NENHUM EXECUTOR VIU TELA RENDERIZADA.** Não há navegador aqui. O que os gates de JS medem é
HTML de `renderToStaticMarkup` — string em memória, sem CSS aplicado e sem layout. Toda afirmação
deste plano sobre "a tela mostra X" significa **"o HTML gerado contém X"**. A conferência visual é
do **usuário**, em **produção**, na **conta 459**, depois de um deploy que **só ele autoriza**.

---

## 10. Deviations from Plan

### `[Rule 3 - Blocking]` A ordem TDD obriga o gate da Tarefa 2 a rodar depois da Tarefa 3

O `<verify>` da Tarefa 2 é `sh tests/gates/verificar-testes.sh 01 gate-rename`, e o `<done>` pede
*"a varredura passa nos diretórios existentes"*. **Isso é impossível no fim da Tarefa 2 por
construção:** a tarefa é `tdd="true"`, e o gate só pode passar depois do rename, que é a Tarefa 3.

**O que fiz:** segui o ciclo TDD de verdade — commit `test(...)` com o gate em **RED** medido e
registrado (`da26aa08`: 55/48/7, com a lista dos 7 `not ok`), e commit `feat(...)` com o
**GREEN** (`060aacdc`: 55/55/0). O `<verify>` da Tarefa 2 rodou **depois** do GREEN e passou.
Registro o RED na §5 porque **é ele a prova de que o gate não é vazio** — um gate que nasce verde
não provou nada.

### `[Rule 2 - Crítico]` O `--autoteste` ganhou 6 casos além dos 8 pedidos

Os 8 do plano cobrem as duas **formas**, mas não cobriam **as próprias regras do harness**.
Acrescentei: **2 iscas** (sem `tap` o caso verde tem de reprovar; sem `limpar_ansi` o caso que passa
tem de reprovar) e **4 de manifesto incompleto** (sem `tests=`, `tests=0`, sem `assertions=` no modo
php, id ausente → todos ABORTAM). Sem elas, as duas travas centrais do harness — a limpeza de ANSI e
a recusa de manifesto incompleto — **não eram medidas por nada**, e quem as removesse não veria
vermelho.

### `[Rule 2 - Crítico]` `Em revisão pelo Mercado Livre` entrou na lista de qualificados

A varredura que o plano manda fazer achou um **segundo** literal qualificado legítimo, além de
`Em revisão pela ECF`: **`Em revisão pelo Mercado Livre`**, medido em
`design_handoff_publicador/Publicacoes v2.dc.html:440` (`reason`) e `:462` (mapa `HELP`) — é o
**motivo** do status, texto de apoio que esta milestone pode trazer. Sem ele na lista, o gate
reprovaria a tela certa no momento em que o motivo chegasse ao código. Está no arquivo com a
medição ao lado e com a regra escrita: **acrescentar ali é afrouxar o gate**.

### `[Rule 2 - Crítico]` Vetos novos nos dois arquivos de fixture

As ocorrências #2 e #3 da tabela da §4 eram **fixtures de entrada**, sem asserção própria — trocar o
valor não deixaria rastro conferível. Acrescentei **um teste de veto em cada**, recortado à **opção
do trilho** (`role="tab"`), provando que o rótulo novo chegou a **UMA** opção e que o antigo chegou a
**ZERO**. É o que torna o reaponte real em vez de cosmético.

### Logs do harness saem no diretório temporário do SO, não no repo

A primeira versão do `--suite` gravava `tests/gates/.ultima-suite-*.log` **dentro do repo**. Mudei
para `$TMPDIR`: a árvore é **compartilhada** com outro dev e com outra sessão, e `.gitignore` não
está em `files_modified` deste plano. Zero arquivo novo não rastreado foi deixado na árvore.

---

## 11. O que ficou para depois

| item | de quem é |
|---|---|
| `tests/js/estrutura-grade-glide.test.js` — "Características secundárias nasce recolhido" falhando | **dívida anterior à fase**, de nenhum plano dela. Não corrigi, por instrução expressa |
| `aprovacao/` ainda não existe — o gate de ausência o declara como `skip` NOMEADO | **plano 186-02**. Quando ele nascer, o gate o varre sozinho, sem edição |
| `Em revisão pela ECF` (VOC-02) ainda não está no código — só na lista de qualificados do gate | **plano 186-02** |
| Migration e banco | **plano 186-03**. Este plano não subiu banco e não rodou migration |
| Os 2 hunks de header do protótipo novo (borda, `gap`, par de abas) | **Fase 187** — fora do escopo, e `files_modified` prova que não foram tocados |
| `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md`, `STATE.md` | **não pertencem a nenhum plano** — intocados, conferido por diff |
| Conferência VISUAL dos três rótulos | **do usuário**, em produção, conta 459, depois de um deploy que só ele autoriza |

## 12. Critérios da fase fechados por este plano

- **Critério 1** — fechado, **com a correção medida** de onde vive o rótulo da aba (divergência D1).
- **Critério 2** — fechado **LIMPO, sem ressalva**: as **TRÊS** ocorrências renderizadas foram
  renomeadas e o gate de ausência cobre os **quatro** diretórios.
- **Critérios 14 e 16** — a **metade de medição**: as 4 baselines estão escritas em forma fixa e a
  régua de gates (harness + manifesto + autoteste) foi aplicada pela primeira vez na fase.
- **Critério 17** — fechado **em parte**: a tabela FID-01 do rename, **declarada como de SCRIPT**,
  com a medida #5 marcada como **fora do protótipo**.

Requisitos tocados: **VOC-01, ABA-03, ABA-04, TERR-05, TERR-08, GATE-04, GATE-11, GATE-12, GATE-13,
GATE-14, FID-01, FID-02**.

---

## Self-Check: PASSED

| conferência | resultado |
|---|---|
| os 4 arquivos criados existem no disco | **FOUND** (×4) |
| os 3 commits existem (`git log --oneline --all`) | **FOUND** `6e9c5149` · `da26aa08` · `060aacdc` |
| `min_lines` do gate de rename (≥ 90) | **260 linhas** |
| `min_lines` do harness (≥ 60) | **422 linhas** |
| `186-BASELINES.md` contém "commit de partida" | **sim** |
| `<automated>` da Tarefa 1, literal | **PASSOU** (rodado antes do commit, com `COMMIT:` = `HEAD` do momento = `d4aeaa30`) |
| `<automated>` da Tarefa 2, literal | **PASSOU** (depois do GREEN — ver deviation Rule 3) |
| `<automated>` da Tarefa 3, literal | **PASSOU** |
| gate de território (`--name-only` com RANGE = `files_modified`) | **IGUAL** |
| T-186-SC (nenhum install) | **diff vazio** em `package*.json` / `composer*.json` |
| árvore limpa depois da campanha de mutação | **sim**, sha256 conferido nos 3 |
