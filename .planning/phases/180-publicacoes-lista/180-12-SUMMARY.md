---
phase: 180-publicacoes-lista
plan: 12
subsystem: Publicador — tela de Publicações
tags: [fechamento-medido, fid-01, auditoria-de-gate, baselines, territorio, criterio-13, checkpoint-humano]

requires:
  - phase: 180-publicacoes-lista
    plan: "01..11"
    provides: "as 13 SUMMARYs da fase (os 12 planos originais + os corretivos 04B e 10B), as 7 tabelas FID-01, as 126 mutações e os 13 gates"
  - phase: 179-visao-geral-cockpit
    plan: "10"
    provides: "o MOLDE do fechamento medido: FID-01 consolidada, divergências por TIPO de restrição, o rastro dos REQ-IDs, as baselines re-medidas e o checkpoint humano com as perguntas em aberto"
provides:
  - "`180-FECHAMENTO.md` — as 8 seções (a-h) do fechamento medido da Fase 180"
  - "as 4 baselines RE-MEDIDAS nesta sessão (build, manifest pelo JSON, 4481 JS, 2085 PHP)"
  - "a sonda de CSS com 54 casos (presença E VALOR), 3 iscas e 1 controle positivo"
  - "a re-medição das 37 referências `arquivo:linha` do protótipo: 5 DIVERGÊNCIAS que nenhum plano pegou"
  - "a SUPERAÇÃO do critério 13 escrita por extenso, com a data, as 3 opções, a medição e as 2 frases de guarda"
  - "a lista PRONTA do insumo da fase futura do Histórico, com `arquivo:linha`"
  - "o 7º uso morto de classe de transição, ACHADO aqui (`ease-[…]` em `Components/Portal/TrilhoDock.jsx`)"
  - "o rastro dos 25 REQ-IDs e dos 16 critérios, sem nenhum 'parcialmente'"
  - "o roteiro de 11 passos e as 11 perguntas em aberto para o checkpoint humano"

affects: [181-publicacoes-painel, 182-desempenho, 183-historico-no-layout-novo]

tech-stack:
  added: []
  patterns:
    - "⚠️ Re-medir as REFERÊNCIAS `arquivo:linha` que as SUMMARYs afirmaram, não só os valores: 5 das 37 divergiram (180-05 ×3, 180-06 ×2), e nenhum plano as pegou. O padrão da milestone se refina: a tabela não erra VALOR — erra por OMISSÃO e por REFERÊNCIA"
    - "⚠️ CONTAR TESTES, não linhas de `assert`, e dizer que a comparação antes×depois é ESTÁTICA: rodar o gate no commit de partida leria os componentes de HOJE e produziria falhas falsas"
    - "⚠️ Varredura de classe morta com os COMENTÁRIOS REMOVIDOS: 2 das 2 ocorrências de `duration-[` nos arquivos da fase são o docblock que EXPLICA que o arquivo não usa a classe — um grep cru reprovaria o código certo"
    - "⚠️ Gate de território em árvore COMPARTILHADA é COMMIT POR COMMIT, nunca por range — e o discriminador é o ESCOPO DO ASSUNTO, nunca `%an` (as duas sessões usam o mesmo `user.name`)"

key-files:
  created:
    - .planning/phases/180-publicacoes-lista/180-FECHAMENTO.md
    - .planning/phases/180-publicacoes-lista/180-12-SUMMARY.md
  modified:
    - .planning/STATE.md

key-decisions:
  - "⚠️ NADA de código foi tocado. Este plano MEDE. A medida que reprovou (`border-pub-neutral-ring` AUSENTE do CSS) foi REGISTRADA e a execução PAROU ali — é uma divergência de `identidade/`, território proibido, e consertá-la não é deste plano"
  - "⚠️ O gate frágil que eu achei (3 asserções ancoradas no PRIMEIRO `<div>` do fixture) foi REGISTRADO, não consertado: a instrução desta rodada é medir, e mexer num gate para 'melhorar' é exatamente o que produz o número que muda quando o trabalho certo é feito"
  - "A contagem de `cursor:pointer` tem DOIS números (11 pelo contador corrido das SUMMARYs, 16 pela soma dos itens por tabela). Registrei os dois e disse qual é qual, em vez de escolher o que soa melhor: a divergência é no CONTADOR do 180-07, não nas medidas"
  - "`ROADMAP.md` e `REQUIREMENTS-v26.md` NÃO tocados, de propósito, com o texto pronto para colar registrado no fechamento. As três ferramentas de marcação (`state.advance-plan`, `roadmap update-plan-progress`, `requirements.mark-complete`) NÃO foram rodadas"

metrics:
  duration: "~1h50 (2026-10-14)"
  completed: 2026-10-14
  tasks: 3
  commits: 1
  medidas-fid01-reconferidas: 37
  divergencias-novas-achadas: 5
  casos-da-sonda-de-css: 54
---

# Fase 180 Plano 12: o fechamento MEDIDO da tela de Publicações — Summary

**O último dos 13 planos.** Ele não implementa nada: **re-mede**. Nas três fases anteriores foi
exatamente neste plano que apareceram as divergências que ninguém mais tinha visto — **~78 só na
Fase 179, e as 8 que reprovaram eram erro do MEDIDOR**. Aqui achei **5 divergências de referência**
que nenhum dos 13 planos pegou, **1 uso morto de classe de transição** que o `deferred-items.md`
declarava não ter varrido, **1 gate frágil** e **1 medidor meu que mentiu**.

**Artefato:** `.planning/phases/180-publicacoes-lista/180-FECHAMENTO.md` (8 seções, a-h).

---

## ⚠️ ANTES DE QUALQUER NÚMERO

**NENHUMA TELA RENDERIZADA FOI VISTA — em nenhum dos 13 planos.** O banco local não tem empresa com
`ml_token` (`meus()` faz `abort_unless`), `ml_acervo_itens` está **vazia** e a leitura de produção
está **bloqueada**. ➡️ **Por isso a validação visual é um CHECKPOINT HUMANO, em produção, na conta
459.** Nada aqui afirma o contrário.

---

## (1) As 4 baselines — RE-MEDIDAS, não copiadas

| baseline | referência | **MEDIDO AGORA** | veredicto |
|---|---|---|---|
| `npm run build` | exit 0 | **exit 0, 31,70 s** | ✅ |
| manifest **pelo JSON** | 479 / 196 | **479 entradas / 196 páginas** | ✅ exato |
| `npm run test:js` | 4481 / 4480 / 1 fail | **4481 / 4480 pass / 1 fail / 0 skipped** | ✅ exato |
| `artisan test tests/{Feature,Unit}/Publicador` | 2085 / 15.960 / 0 | **2085 passed / 15.960 assertions / 0 falhando** (565,74 s) | ✅ exato |

- ⚠️ `MeusAnuncios.jsx` → `assets/MeusAnuncios-Dkr5s0y-.js` — **o hash tem HÍFEN no fim**. É por isso
  que o manifest se confere pelo JSON: **página React fora do manifest SOME da aplicação**. A
  **ISCA** (uma entrada inexistente) saiu ausente ⇒ a sonda não está cega.
- ⚠️ **A única falha de JS é a pré-existente** `tests/js/estrutura-grade-glide.test.js:122` (a linha
  da declaração `test()`; a asserção que falha é a `:123`, com `expected:
  /RECOLHIDOS_INICIAIS = \[G_SECUND\]/`). **A referência do briefing está CERTA.** NÃO corrigida.
- ⚠️ **A suíte PHP foi medida SÓ na faixa do Publicador**, e isso é declarado: a Fase 184 commita PHP
  na mesma árvore, e rodar o resto mediria o trabalho dela.

---

## (2) A sonda de CSS — presença **E VALOR**, com isca

**54 casos. 53 OK. 1 ausência, que é uma divergência CONFIRMADA.**
CSS: `public/build/assets/app-3tlHZ44i.css`, **222.857 bytes**.

As **sete normalizações** do learnings foram todas exercitadas e todas se confirmaram:

| forma | medida real |
|---|---|
| token sólido → `rgb()` **decimal** | `bg-pub-row-sel` → `rgb(34 29 7 / var(--tw-bg-opacity, 1))` |
| `stroke`/`fill` sólidos → **hex literal** | `stroke-pub-page` → `stroke:#0a0a0a` |
| opacidade em `border`/`background` → **hex de 8 dígitos** | `border-pub-accent/[0.35]` → **`#facc1559`** · `bg-pub-accent/[0.16]` → **`#facc1529`** · `/[0.12]` → **`#facc151f`** · `border-…/[0.55]` → **`#facc158c`** |
| opacidade em `--tw-ring-color` → **`rgb(… / .18)` decimal** | `focus:ring-pub-accent/[0.18]` → **`rgb(250 204 21 / .18)`** |
| cssnano come o zero da frente, **inclusive após o menos** | `tracking-[-0.025em]` → `letter-spacing:**-.025em**` · `shadow-[…rgba(0,0,0,0.6)]` → `rgba(0,0,0,**.6**)` |
| seletor **escapado** | `.text-\[13\.5px\]`, `.grid-cols-\[40px_minmax\(300px\,1fr\)…\]` |
| na variante o **prefixo faz parte do nome** | `.hover\:bg-pub-hover-toast:hover` |

⚠️ **E eu escapei TODOS os metacaracteres, não só a barra** — era exatamente o buraco em que
`shadow-[0_16px_40px_rgba(0,0,0,0.6)]` saía AUSENTE com a regra presente e correta. Aqui ele saiu
**presente, com o valor certo**.

**As 3 ISCAS** (`bg-pub-isca-f12z`, `text-[88.5px]`, `h-[57px]`), **provadas inexistentes no repo
inteiro por `grep -rF` antes do uso**, saíram **AUSENTES**. O **controle positivo** (`duration-200`)
saiu **presente** (`transition-duration:.2s`).

### ⚠️ A ÚNICA AUSÊNCIA — e ela é uma divergência real, não erro do medidor

```
*** AUSENTE    border-pub-neutral-ring   (esperava conter "rgb(95 95 104")
```

Investigada em vez de presumida:

```
tailwind.config.js:208        'neutral-ring': '#5F5F68',          <- o token EXISTE
grep -rn "pub-neutral-ring" resources/js   ->  0 usos             <- ORFÃO
SeloDeStatusDaPublicacao.jsx:62  border-pub-neutral-dot (#71717A) <- o que o selo usa de verdade
```

➡️ **Reconfirmei, de forma independente, a 1ª das duas divergências de `identidade/` que o 180-08
mediu.** O protótipo (`:250`) quer `#5F5F68`; o código usa `#71717A`. O plano 180-01 criou o token
**exatamente para este anel** e ele ficou **órfão**, porque consertar exige editar `identidade/` —
**território proibido, por instrução escrita, duas vezes.** ⚠️ **REGISTRADO E PAREI.** Não consertei.

---

## (3) ⚠️ AS 5 DIVERGÊNCIAS QUE SÓ ESTE FECHAMENTO PEGOU

Re-medi **as 37 referências `arquivo:linha`** que as 13 SUMMARYs afirmaram sobre o protótipo,
**ancorando na SEMÂNTICA e nunca no número**. **32 batem; 5 divergem:**

| quem | o que | afirmou | MEDIDO | o VALOR está certo? |
|---|---|---|---|---|
| 180-05 | container do `<main>` | `:30` | **`:29`** | ✅ sim, byte a byte |
| 180-05 | botão "Atualizar" | `:39` | **`:40`** | ✅ sim |
| 180-05 | spinner | `:41` | **`:42`** | ✅ sim |
| 180-06 | trilho de status | `:83` | **`:84`** (o do toggle em `:94`, não `:92`) | ✅ sim |
| 180-06 | botão de aba | `:85` | **`:86`** | ✅ sim |

⚠️ **ZERO divergências de VALOR.** Imprimi as 5 linhas reais e conferi: o código está certo nas
cinco. ➡️ **É a §1 do learnings em ação, cometida pelos planos 180-05 e 180-06** — e o padrão da
milestone se **refina**: *a tabela não erra VALOR; erra por **OMISSÃO** e por **REFERÊNCIA**.*
(O 180-09 já havia corrigido as dele por iniciativa própria, no commit `b2bf9a04`, e as dele batem.)

### O placar final da FID-01

| | |
|---|---|
| medidas conferidas pelos 7 planos de desenho | **173** (10 + 24 + 27 + 34 + 40 + 31 + 7) |
| divergências que os planos declararam | **39** |
| divergências que **só este fechamento** pegou | **+5** |
| **total da fase** | **44** |
| a favor do protótipo | **43** |
| ⚠️ em que o **protótipo PERDEU** | **1** |

### ⚠️ A ÚNICA em que o protótipo perdeu — confirmada por leitura

`Publicacoes v2.dc.html:566`:

```js
byProd[pid].sort((a, b) => b.ph - a.ph || (a.t === 'premium' ? -1 : 1) || b.d.localeCompare(a.d))
```

**Dois defeitos mecânicos:** o termo do meio devolve `-1` ou `1` e **nunca `0`** ⇒ o **terceiro termo
é INALCANÇÁVEL**; e para dois clássicos `cmp(a,b)` e `cmp(b,a)` devolvem os dois `1` ⇒ **comparador
INCONSISTENTE**, que daria ordem instável entre requests. ➡️ **O 180-04 implementou a INTENÇÃO**
(fase DESC → Premium antes → data DESC) com tie-break estável. **Confirmo a decisão.** É a primeira e
única vez na milestone v26.0, e é sobre um **defeito do mock**, não sobre aparência.

### `cursor:pointer` — a omissão mais repetida, e a contagem que NÃO fecha

**31 ocorrências no protótipo** (linhas medidas uma a uma). **21 `cursor-pointer` no código**, nos 14
`.jsx` de `publicacoes/`. ⚠️ **O contador corrido das SUMMARYs chega a 11; a soma dos itens por
tabela dá 16.** A divergência está no **CONTADOR** (o 180-07 escreve ao mesmo tempo "5 vezes nesta
tabela" e "a quinta vez na fase", desalinhando a numeração dali para frente). **Registrei os dois e
disse qual é qual.** O veredicto não muda: o protótipo ganhou nas 16.

---

## (4) O audit de gate vazio — as 9 famílias, e o que eu achei

**13 gates, `test -e` em cada um: 13/13 existem, 0 caminho fantasma.** Resultado por família:
**0 achados reais em 8 famílias**, a 9ª (ANSI) **não se aplica** (nenhum gate lê saída de runner).

### ⚠️ ACHADO 1 — um gate FRÁGIL, registrado e NÃO consertado

3 asserções localizam por `HTML_X.indexOf('<div')`, ou seja, medem o **elemento RAIZ** do fixture.
É determinístico hoje, **mas se alguém embrulhar o componente num `<div>` novo, o gate passará a
medir o WRAPPER em silêncio** — é a família 6 com outra roupa.
**Casos:** `publicador-publicacoes-filtros.test.js:545`, `:760`, `:1397`.
⚠️ **NÃO consertei:** a instrução desta rodada é MEDIR, e mexer num gate para "melhorar" é o caminho
mais curto para o número que muda quando o trabalho certo é feito. **Recomendação para a Fase 181:**
ancorar no `role`/`aria` ou no texto único.

### ⚠️ ACHADO 2 — O MEU PRÓPRIO MEDIDOR MENTIU, e foi vazio AO CONTRÁRIO

A minha 1ª sonda da família 4 (`new RegExp` com `\b` em template literal) era um `grep -E` no shell.
**Ela devolveu TODAS as 24 linhas** — acusaria tudo. Causa: no GNU `grep -E`, `\\b` é ambíguo (barra
escapada + `b`, ou borda de palavra) e o padrão degenerou.
➡️ **Refeita em ARQUIVO (`.cjs`), com ISCA.** Resultado: **0 suspeitas em 24 template literais**
(todos usam DUPLO escape, que é correto), **e a isca achou 2 defeitos numa linha com `` `\b${x}\b` ``**
⇒ a sonda não está cega. **É a lição "regex em ARQUIVO, nunca no shell" cometida por mim, neste
fechamento, e registrada em vez de maquiada.**
**Placar da rodada: 1 medidor meu mentiu, 0 defeitos de código.**

### ACHADO 3 — a 6ª e a 7ª vez do grep que casa com o COMENTÁRIO

| gate ingênuo | devolveria | a verdade |
|---|---|---|
| `grep -c SubAbasDePublicacoes MeusAnuncios.jsx == 0` | **3** → reprovaria | as 3 são **COMENTÁRIO** (`:52`, `:61`, `:1099`) — as frases ANTI-REVERSÃO. **Zero mount, zero import** |
| `grep -c 'duration-\[' LinhaDeGrupoDeProduto.jsx == 0` | **2** → reprovaria | as 2 são o **DOCBLOCK** (`:165`, `:168`) que EXPLICA que o arquivo não usa a classe |
| `grep -c 'href="#"' publicacoes/ == 0` | **1** → reprovaria | é **comentário** em `CelulasDaLinha.jsx:947` |

➡️ Medi os três **com os comentários removidos**: **0, 0 e 0**. E a **ISCA DA LEITURA** confirma que a
limpeza não esvaziou os arquivos (17/17 ainda contêm `export`).

---

## (5) ⚠️ O 7º USO MORTO DE CLASSE DE TRANSIÇÃO — achado aqui

O `deferred-items.md` dizia literalmente: *"⚠️ Conferir também `delay-[...]` e `ease-[...]`, que caem
na mesma ambiguidade — **não varri o repositório inteiro por eles**."*

**Eu varri:**

| arquivo | classe | gera CSS? |
|---|---|---|
| `Components/Mlb/Publicador/LinhaDeProduto.jsx:40` | `duration-[2000ms]` | ❌ AUSENTE (já registrado) |
| `Pages/Performance/Index.jsx` ×5 | `duration-[900ms]` | ❌ AUSENTE (já registrado) |
| ⚠️ **`Components/Portal/TrilhoDock.jsx`** | **`ease-[cubic-bezier(.2,.8,.2,1)]`** | ❌ **AUSENTE — NOVO** |
| `delay-[…]` | — | **0 usos no repositório** |

**O controle positivo (`duration-200` → `transition-duration:.2s`) está presente** ⇒ a sonda não está
cega, e a ausência é real. ⚠️ **Não consertei:** `Components/Portal/` não é território desta fase.
**Fica como a 7ª dívida nomeada.**

**Nos arquivos DA FASE: ZERO** (17 arquivos, medidos sem comentário).

---

## (6) Os gates de território — `test -e` em cada alvo, prova COMMIT POR COMMIT

**25 alvos. 25/25 EXISTEM. ZERO caminho fantasma.** (Usei os caminhos CERTOS que os 6 fantasmas
anteriores da milestone deixaram registrados.)

**22 com 0 arquivo. 3 ACUSADOS — e os 3 são da Fase 184:**

| alvo | numstat | commit | escopo |
|---|---|---|---|
| `database/migrations` | +75/−0, +60/−0 | `8f1dfc67`, `19f873b0` | **`feat(184-01)`** |
| `routes/mlb_anuncios.php` | **+43 / −0** | `279e8fac` | **`feat(184-11)`** |
| `routes/web.php` | **+12 / −0** | `73ba3520` | **`feat(184-07)`** |

⚠️ **As três são SÓ ADIÇÕES (`−0`)** ⇒ **`routes/mlb_anuncios.php` não perdeu uma linha** e a rota
`historico` está viva em `:473`. **Era exatamente o item que o usuário NÃO autorizou remover.**

⚠️ **O discriminador é o ESCOPO DO ASSUNTO (`180-*` × `184-*`), NUNCA `%an`:** as duas sessões usam o
mesmo `user.name`, e um gate por autor é **vazio por construção** nesta árvore.

- **CONTROLE POSITIVO:** o MESMO comando vê **15** arquivos em `publicacoes/`, 1 em
  `MeusAnuncios.jsx`, 1 no controller, 3 em `app/Services/Publicador`, 1 em `tailwind.config.js`
  ⇒ os 22 zeros não são comando quebrado.
- **`identidade/` com 0 arquivos** — o 180-09 escolheu o **desvio declarado** em vez da prop aditiva.
  **Nem a prop entrou.**
- **`.planning/ROADMAP.md` (+186/−2) e `STATE.md` (+215/−0) aparecem e NÃO são violação** — nenhum
  dos dois pertence a plano nenhum. **`REQUIREMENTS-v26.md`: diff VAZIO na fase inteira.**
- **0 DELEÇÕES em TODA a fase**, conferido commit por commit com `--diff-filter=D`.

### Os três gates do ERP — verdes e intocados, do NOSSO lado

| gate | diff na fase | execução |
|---|---|---|
| `publicador-visao-geral-acoes.test.js` | **0** | **214 / 214 / 0** |
| `CabecalhoDoPublicadorTest.php` | **0** | ✅ nos 2085/0 da suíte |
| `CockpitDaContaTest.php` | **0** | ✅ idem |

**E a prova ESTRUTURAL:** `grep` por `MeusAnuncios|CelulasDaLinha|resources/js|atalhosDaLista` nos
dois PHP devolve **0** ⇒ não há caminho por onde esta fase os quebrasse.

---

## (7) As 4 cirurgias — conferidas uma a uma, CONTANDO TESTES

⚠️ **O gate "asserções removidas = ZERO" tem DUAS cegueiras** e as duas valem aqui: é **vazio ao
contrário** (reaponte gera `-assert` por construção) **e é CEGO para teste instalado por chamada de
fábrica** (linha sem a palavra `assert` que leva 6 testes).

**Só TRÊS gates perderam linha em toda a fase** — e `+assert > -assert` nos três:

```
estrutura-meus-anuncios.test.js              +510 / -12    +assert 56  -assert 2
publicador-paginas-anuncios-render.test.js  +1036 / -21    +assert 160 -assert 7
publicador-vocabulario-novo.test.js          +168 /  -3    +assert 6   -assert 0
```

Os outros 9 gates: **−0 linhas**.

### ⚠️ A medida que de verdade mede: NENHUM GATE PERDEU TESTE

| gate | `test()` em `f23f610d` | AGORA | Δ | runtime hoje |
|---|---|---|---|---|
| `estrutura-meus-anuncios` | 18 | **24** | **+6** | 30 / 30 / 0 |
| `paginas-anuncios-render` | 9 | **16** | **+7** | 119 / 119 / 0 |
| `vocabulario-novo` | 7 | **7** | **0** | 344 / 344 / 0 |
| `publicacoes-cabecalho` | 0 | **11** | **+11** | 74 / 74 / 0 |
| `publicacoes-tabela` | 0 | **19** | **+19** | 130 / 130 / 0 |
| `meus-anuncios-sku-render` | 12 | **12** | **0** | 10 / 10 / 0 |

⚠️ **LIMITAÇÃO DECLARADA:** a comparação é **ESTÁTICA**. **Não dá para rodar o gate em `f23f610d`** —
ele leria os componentes de HOJE e produziria falhas falsas. **Digo em vez de fingir que rodei.**

### A chamada de FÁBRICA — a cegueira, medida

**`estrutura-meus-anuncios.test.js`: fábricas ANTES=3, AGORA=2.** ⚠️ **Uma foi removida**
(`travaVocabularioVisual('…/MeusAnuncios.jsx')`) e **ela instalava 6 testes / 18 asserções** — a linha
**não contém `assert`**, logo o gate de `-assert` **não a mostraria**. ➡️ **Declarada pelo 180-10 §2 e
compensada** (teste nomeado com as 6 regras + os 6 sub-testes do gate NOVO, que é mais duro). **E o Δ
do arquivo é +6, não −6.**

### As 3 linhas de `vocabulario-novo` — a mais perigosa de ler errado

```
- test('GATE-03 — nenhum arquivo de `identidade/` ou `visaogeral/` fica FORA de ARQUIVOS_NOVOS', …
+ test('GATE-03 — nenhum arquivo de `identidade/`, `visaogeral/` ou `publicacoes/` fica FORA …', …
```

**Parece um teste APAGADO. Foi RENOMEADO** — prova: **7 `test()` antes e 7 depois**, runtime 331 →
**344**. As outras duas são a lista `TAMANHOS_PERMITIDOS` (+`15.5`) e `DIRETORIOS_FECHADOS` (+`DIR_PUB`).
➡️ **É exatamente por isso que se conta TESTE e não linha.**

### D-11 (×2) e D-22 — INTOCADOS

Identificados por **SEMÂNTICA** (`estrutura-meus-anuncios.test.js:250`, `:256`, `:285` — as
referências do briefing bateram desta vez) e **0 linha no diff da fase inteira**.

---

## (8) O resumo de mutação da fase

**126 aplicadas · 126 pegas · 1 recusada e refeita · 2 sobreviventes (as duas investigadas e
convertidas em cobertura real) · 13 controles negativos recusados com `sha256` intacto.**

⚠️ **Nas duas sobreviventes a causa era LACUNA DE COBERTURA REAL** — a 4ª e a 5ª vez na milestone:

| # | o que ela provou que faltava | fechada? |
|---|---|---|
| **180-04 (j)** | o fixture tinha o termo no `ml_item_id`, então o 2º braço da busca casava sozinho: **o caso provava que a busca acha por CÓDIGO MLB, não por nome de produto** — e era o único gate de LIST-03 | ✅ fixture refeito, mutação `j2` **reprovou** |
| **180-10 (vii)** | **no render de servidor `colapsados` é SEMPRE `[]`**, então o `\|\|` devolvia `true` de qualquer jeito: **a regra do LIST-09 não era observável em render nenhum** | ✅ virou a função pura `grupoAberto()`, 7 casos; a mutação refeita **na regra** reprovou |

➡️ **RECEITA:** *regra de UI com duas entradas que o render estático não distingue → extraia para
FUNÇÃO PURA e teste as combinações.* **Nenhuma sobrevivente ficou como dívida.**

---

## (9) A cobertura — 25/25 REQ-IDs e 16/16 critérios, sem "parcialmente"

⚠️ **São 25 e não 24:** o `LIST-25` entrou **entre o 23 e o 24** em 11/10/2026.

- **25 REQ-IDs com veredicto.** 18 **ATENDIDO**, 7 **ATENDIDO COM DESVIO DECLARADO**, **0 NÃO
  ATENDIDO**, **0 "parcialmente"**.
- **16 critérios com veredicto.** Nenhum descoberto.
- ⚠️ **O critério 13 é o caso especial:** **ATENDIDO COM DESVIO DECLARADO em UM ITEM SÓ** (as
  sub-abas "No ar | Histórico", superadas em 13/10/2026). **Os OUTROS SETE itens que ele nomeia estão
  ATENDIDOS LIMPO**, cada um com `teste:caso` — conferi item por item no fechamento §e2: banner
  âmbar · "Ainda não coletamos…" · aviso de SKU · o DADO de Publicados|Rascunhos · paginação de 50 ·
  ordenação por gravidade · D-11. **Desvio num item não afrouxou os outros sete.**
- ⚠️ **LIST-25 está ATENDIDO nas DUAS metades:** o "No ar|Histórico" (o atalho saiu, **o dado ficou
  alcançável pela rota preservada**) e o "Publicados|Rascunhos" (o atalho mudou de lugar; `sub`, a
  query e o contador ficaram — `'rascunhos' => $totalDeRascunhos` é a **mesma variável** nas duas
  props). **A guarda do LIST-25 foi cumprida: a fase PAROU e perguntou.**
- ⚠️ **Nenhum REQ-ID ficou descoberto.** O único cujo **TEXTO** precisa de conserto é o **LIST-20**,
  que descreve um caminho **medido como errado** (duas provas independentes) — texto pronto em §f2.

---

## (10) A superação do critério 13 — escrita por extenso

**Está no fechamento §b2**, no mesmo padrão do D-8 da Fase 179, com os **quatro** elementos
obrigatórios: **a DATA** (13/10/2026) · **a PERGUNTA com as TRÊS opções e o aviso literal**
(*"a rota fica órfã, sem nenhum link vivo para ela"*) · **a MEDIÇÃO que motivou a pergunta** (a tabela
`meus()` × `historico()` com `arquivo:linha`, provando que são **conjuntos diferentes** e que
`SubAbasDePublicacoes.jsx:97` era o **único link vivo**) · **a ESCOLHA dele**
(*"Sai agora, Histórico vira fase própria"*).

**Mais as duas frases de guarda, em destaque: NÃO RESTAURAR e NÃO APAGAR.**

⚠️ **Esta milestone tem DUAS exceções explícitas do usuário à regra-mestra "melhorar sem regredir"**,
as duas autorizadas por escrito: os dois blocos da Visão Geral (**12/10**) e o "No ar | Histórico"
(**13/10**). E as decisões de **14/10** (o 2º "Atualizar" fora, o SKU por variação de volta, e
"Em revisão + com erro: o status real do ML ganha" — **nada implementado, de propósito**) estão
registradas nos desvios 21, 22 e 24.

---

## (11) As pendências de marcação — registradas, NÃO executadas

| ferramenta | o que ela faz de verdade | rodei? |
|---|---|---|
| `gsd-sdk query state.advance-plan` | **corrompeu o `STATE.md` SEIS vezes** | ❌ |
| `roadmap update-plan-progress` | **reporta sucesso e NÃO escreve nada** | ❌ |
| `requirements.mark-complete` | devolve **`not_found`** para IDs de `REQUIREMENTS-vNN.md` | ❌ |
| `REQUIREMENTS-v26.md` | **não tem checkbox nenhum** (os requisitos são bullets) | — |

➡️ **O que existe para marcar são o Coverage Map do `ROADMAP.md` e a linha `**Plans**:`.**
⚠️ **ESTE PLANO NÃO TOCOU NENHUM DOS DOIS** — diff **vazio** nos dois no range da fase. **O texto
pronto para colar está no fechamento §f2.** Precisam ser marcados **À MÃO por quem fechar.**

---

## (12) Verificação

| gate | resultado |
|---|---|
| `node --test` nos 4 gates do `<verify>` da Task 1 | ✅ `publicador-vocabulario-novo` **344/344/0** · `estrutura-meus-anuncios` **30/30/0** · `publicador-paginas-anuncios-render` **119/119/0** · `publicador-header-zindex-ordem` **7/7/0** |
| `npm run test:js` | **4481 / 4480 pass / 1 fail / 0 skipped** — a falha é a **pré-existente**, não corrigida |
| `artisan test tests/{Feature,Unit}/Publicador` | **2085 passed / 15.960 assertions / 0 falhando** |
| `npm run build` + manifest pelo JSON | exit **0** · **479 / 196**, `MeusAnuncios.jsx` **PRESENTE** |
| sonda de CSS (54 casos, 3 iscas, 1 controle) | **53 OK, 1 ausência = divergência confirmada de `identidade/`** |
| protótipo no disco | `sha256 6dcbb2f9…` — **o mesmo que o 180-01 mediu**, logo não mudou na fase |
| `test -e` nos 25 alvos de território | **25/25**, zero caminho fantasma |
| território, **commit por commit** | 3 acusados, **os 3 da Fase 184**; **0 deleções em toda a fase** |
| `test -e .planning/STATE.md` | existe |
| `STATE.md` aditivo, por `numstat` **no commit** | **0 remoções** (ver o Self-Check) |
| `ROADMAP.md` / `REQUIREMENTS-v26.md` | **INTOCADOS** — diff vazio na fase inteira; `git status` limpo |
| arquivos de código tocados por este plano | **ZERO** |

⚠️ **Nenhuma linha de código de produção foi tocada.** `files_modified` deste plano são
**`.planning/STATE.md`** e os **dois artefatos novos**. Nada deployado, nada empurrado, nenhum comando
no VPS, nenhuma leitura de produção, nenhuma migration, nenhum pacote.

---

## Known Stubs

**Nenhum stub novo.** As ausências desta tela são **medidas e declaradas**, não placeholders:

| o que não aparece | por quê | onde |
|---|---|---|
| a **faixa de sucesso** | `faixaSucesso` é `null` SEMPRE (3 caminhos medidos) — ausência de **FONTE** no sistema | fechamento §e1 LIST-02 + pergunta **E** |
| o chip **"Responsável"** | `responsavel.opcoes` não tem fonte ⇒ com `[]` o chip não é desenhado | §e1 LIST-07 + pergunta **F** |
| a **margem** da célula Preço | a tarifa do ML não está no banco → Fase 181. **O VALOR do preço já chega** | §b item 6 |
| o **badge NOVA** / `bg-pub-row-new` | só acendem no DIA CIVIL da publicação (régua medida) | §e1 LIST-11 |
| a **barra de ação em massa** | Fase 181. ⚠️ **O checkbox marca e pinta, e não leva a ação** — decisão dele de 14/10 | pergunta **G** |
| os **rótulos F1/F2** do mapa de cobertura | `identidade/` é território proibido | §b item 9 + pergunta **H** |
| o **anel** de "Em revisão" e o **"Com erro"** preenchido | idem ⚠️ **o token `pub-neutral-ring` existe e está ÓRFÃO: 0 usos, 0 CSS** | §a4 + pergunta **H** |

## Threat Flags

**Nenhuma superfície nova.** Este plano escreve **só documentação de planejamento** e um bloco no
`STATE.md`. Zero rota, endpoint, caminho de auth, acesso a arquivo ou mudança de schema.
**Nenhum segredo, nenhum token, nenhum dado de cliente** nos artefatos — só números e caminhos do
próprio projeto (T-180-12-I: **accept**).
**T-180-12-T** (tampering no `STATE.md`): mitigado — bloco **aditivo** à mão, `state.advance-plan`
**não executado**, **0 remoções** provadas por `numstat` no commit.
**T-180-12-R** (afirmar o que não mediu): mitigado — cada veredicto tem **onde foi medido**, as
baselines estão com os números literais, **"parcialmente" não foi usado**, e as **7 limitações** do
que os gates NÃO provam estão escritas no fim do fechamento.
**T-180-SC:** `package.json`, `package-lock.json`, `composer.json`, `composer.lock` e
`database/migrations` **saíram com 0 desta fase** (as 2 migrations do range são `feat(184-01)`).

---

## Self-Check: PASSED

| o que | resultado |
|---|---|
| `.planning/phases/180-publicacoes-lista/180-FECHAMENTO.md` | **FOUND** — as 8 seções (a-h), ≥ 200 linhas |
| `.planning/phases/180-publicacoes-lista/180-12-SUMMARY.md` | **FOUND** |
| `.planning/STATE.md` | **FOUND**, bloco **ADITIVO** à mão, **0 remoções** |
| as 4 baselines re-medidas | **4/4**, todas batendo com a referência |
| as 37 referências do protótipo reconferidas | **32 batem, 5 divergem** (todas de referência, **0 de valor**) |
| 25/25 REQ-IDs e 16/16 critérios com veredicto | **sim**, sem nenhum "parcialmente" |
| cada divergência com `arquivo:linha` | **sim** |
| cada desvio declarado com quem decidiu | **26 itens, todos com fonte numa SUMMARY** |
| gate vazio por construção achado | **1 fragilidade declarada + 1 medidor MEU que mentiu**, os dois registrados |
| sobreviventes de mutação investigadas | **2/2**, as duas eram lacuna real e as duas foram fechadas |
| `ROADMAP.md` / `REQUIREMENTS-v26.md` intocados, com o texto pronto registrado | **sim** |
| `state.advance-plan` / `roadmap update-plan-progress` / `requirements.mark-complete` | **nenhum executado** |
| arquivos de código de produção tocados | **ZERO** |
| scripts de medição deixados no repositório | **nenhum** — os 7 vivem no scratchpad da sessão |
| ⚠️ nenhuma afirmação de ter visto tela renderizada | **confirmado** |
