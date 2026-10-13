---
phase: 180-publicacoes-lista
plan: 01
subsystem: ui
tags: [tailwind, design-tokens, publicador, publicacoes, fidelidade, mutacao, sonda-css]

requires:
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "a camada `pub-*` em `tailwind.config.js` e o gate `tests/js/publicador-tokens-do-publicador.test.js`, que importa o config de verdade"
  - phase: 179-visao-geral-cockpit
    provides: "a varredura de hex órfão com isca (`HEX_DO_PROTOTIPO`, `hexComNome()`, `orfaos()`, `HEX_ISCA`, `RE_HEX6`), a igualdade de conjunto em `colors.pub` e a receita de provar token que nenhuma tela usa ainda (Tailwind por postcss sobre conteúdo sintético)"
provides:
  - "`colors.pub['row-checked']` = #18160C — fundo da linha MARCADA, o 3º dos cinco fundos de `Publicacoes v2.dc.html:553`"
  - "`colors.pub['row-focus']` = #1C1C1C — fundo da linha com FOCO; nome NOVO para um hex que só tinha nome de BORDA"
  - "`colors.pub['line-off-2']` = #2A2A2A — borda OFF dos chips de filtro (`:608`)"
  - "`colors.pub['neutral-ring']` = #5F5F68 — anel aberto de 'Em revisão' na célula Status (`:250`)"
  - "`colors.pub['hover-toast']` = #26220F — `style-hover` do '×' da faixa de sucesso (`:62`)"
  - "`15.5` em `TAMANHOS_PERMITIDOS` — o `<input>` da busca central de 54px (`:70`)"
  - "varredura com isca provando 0 hex de `Publicacoes v2.dc.html` sem nome na camada (34 de 34 nomeados)"
  - "conferência de que os CINCO fundos de linha do ternário de `:553` existem e são CINCO VALORES DISTINTOS"
affects: [180-02, 180-03, 180-04, 180-05, 180-06, 180-07, 180-08, 180-09, 180-10, 180-11, 180-12, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "Órfão de SEMÂNTICA, não de hex: `#1C1C1C` já tinha nome (`line-subtle-2`) mas só como BORDA; ganhou `row-focus` para o uso de FUNDO. Duplicata dentro da camada é o que a camada existe para absorver (precedente declarado `neutral-dot`/`txt-4`); nas telas é o que TOK-15 proíbe"
    - "Sonda de CSS sobre conteúdo SINTÉTICO é obrigatória para token que nenhuma tela usa: medido nesta rodada que os 5 tokens novos emitem ZERO regra no CSS de produção (JIT só varre `.jsx`), com controle mostrando que token antigo aparece — grep no bundle teria 'provado' nada"
    - "Prova de INÉRCIA da camada global por sha256 do CSS construído com e sem os tokens novos, com controle que altera um token EXISTENTE e muda o hash"
    - "`skip` com razão escrita (via `contexto.skip()`) em vez de `return` calado, quando a fonte de verdade não é versionada — e o `skipped 1` aparece no sumário do `node --test`, então não se esconde"
    - "`test -e` em cada caminho de território ANTES de acreditar em diff vazio: 2 dos 15 alvos desta rodada eram caminhos inexistentes"

key-files:
  created:
    - .planning/phases/180-publicacoes-lista/180-01-SUMMARY.md
  modified:
    - tailwind.config.js
    - tests/js/publicador-tokens-do-publicador.test.js
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "Os 4 hex e os 4 números de linha foram MEDIDOS no protótipo com varredura própria, e pela PRIMEIRA VEZ na milestone a tabela do plano estava inteiramente certa: 0 divergências em 4 hex + 4 linhas + 2 medidas de tamanho"
  - "`row-focus` CRIADO (5º token) — decisão tomada e justificada abaixo; a alternativa era as telas escreverem `bg-pub-line-subtle-2` numa linha de tabela"
  - "`18px` deliberadamente NÃO liberado: as 3 declarações estão dentro do `<aside>` de 460px (`:310`-`:402`), que é a Fase 181"
  - "A liberação de `15.5` é um ENFRAQUECIMENTO de um gate de permit-list e NENHUM gate a pega — o que a protege é a medida escrita ao lado; declarado abaixo em vez de maquiado"

metrics:
  duration: "~2h20 (2026-10-13, 16:52→19:12 UTC)"
  completed: 2026-10-13
  tasks: 3
  commits: 3
  tokens-novos: 5
  mutacoes: "6/6 pegas"
---

# Fase 180 Plano 01: Tokens e vocabulário da tela de Publicações — Summary

Os quatro hex órfãos de `Publicacoes v2.dc.html` ganharam nome, mais um quinto token que era
órfão de **semântica** e não de hex (`row-focus`), e o tamanho `15.5px` da busca central foi
liberado — com os cinco tokens provados no **CSS construído com o valor certo** por sonda sobre
conteúdo sintético, e com a camada global provada **inerte** por sha256.

## O que foi feito

| Task | O que | Commit | Arquivos |
|---|---|---|---|
| 1 | 5 tokens em `colors.pub`, cada um com a defesa escrita contra FID-02 ao lado | `5972353b` | `tailwind.config.js` (+42 / **-0**) |
| 2 | `PUB_ESPERADOS` 38→43, `HEX_DO_PROTOTIPO_PUBLICACOES` (34 hex), 3 testes novos, `15.5` liberado | `65f98b67` | tokens test (+227 / -0), vocabulário (+24 / -1) |
| 3 | 6 mutações — **nenhum arquivo alterado** (6/6 pegas, árvore restaurada), logo nada a commitar | — | — |
| — | esta SUMMARY + bloco aditivo no `STATE.md` | ver abaixo | `.planning/` |

## (1) A tabela FID-01, conferida item a item

Varredura própria com `#[0-9A-Fa-f]{6}(?![0-9A-Fa-f])`, linha a linha, índice vindo do `forEach`:
**34 hex distintos, 30 já com nome, 4 órfãos.** Cada um confirmado pela **semântica**, imprimindo o
contexto da linha — não pelo número.

| hex | linha do plano | linha MEDIDA | semântica confirmada no protótipo | nome criado | divergência |
|---|---|---|---|---|---|
| `#18160C` | 553 | **553** | `bg: isSel ? '#221D07' : isFocus ? '#1C1C1C' : checked ? '#18160C' : p.isNew ? '#14120A' : '#111111'` — o 3º do ternário dos cinco fundos | `pub-row-checked` | **nenhuma** |
| `#2A2A2A` | 608 | **608** | `const on = (b) => [b ? 'rgba(250,204,21,0.55)' : '#2A2A2A', b ? 'rgba(250,204,21,0.12)' : '#111111'];` — borda OFF dos chips | `pub-line-off-2` | **nenhuma** |
| `#5F5F68` | 250 | **250** | `isPending` → `border:1.6px solid #5F5F68;border-right-color:transparent` — o anel aberto de "Em revisão" | `pub-neutral-ring` | **nenhuma** |
| `#26220F` | 62 | **62** | `style-hover="background:#26220F"` no botão "×" `title="Dispensar"` da faixa de sucesso | `pub-hover-toast` | **nenhuma** |

| medida | linha do plano | linha MEDIDA | ocorrências | onde | ação | divergência |
|---|---|---|---|---|---|---|
| `15.5px` | 70 | **70** | **1** | `<input ... height:54px ... font-size:15.5px>` da busca central | liberado | **nenhuma** |
| `18px` | 374 | **374** (+ 375, 376) | **3** | Vendas/Visitas/Conversão, dentro do `<aside>` de `:310`-`:402` | **NÃO** liberado (Fase 181) | **nenhuma** |

Tamanhos presentes no protótipo, medidos: 10 · 10.5 · 11.5 · 12 · 12.5 · 13 · 13.5 · 14 · 15 ·
**15.5** · 17 · **18** · 20 · 28. Bate com o plano. `11px` segue na lista permitida e não tem uso
aqui — nada removido.

> ⚠️ **PLACAR: 0 divergências em 10 medidas.** É a **primeira vez na milestone** que a tabela
> derivada do protótipo não perdeu dele (o histórico era ~102 a 0 contra a tabela). Isso **não**
> revoga a regra — significa que o planejador desta fase mediu com script em vez de estimar.
> As divergências que eu achei estão na seção "Divergências e armadilhas", e nenhuma é da tabela FID-01.

## A decisão sobre `pub-row-focus` — CRIADO

`#1C1C1C` **não** era órfão de hex: já tinha nome na camada, `line-subtle-2`. Era órfão de **uso**.

Medidas que sustentam a decisão (as 6 ocorrências de `#1C1C1C` no protótipo):

| linha | uso | token certo |
|---|---|---|
| 357, 358, 359 | `border-top: 1px solid #1C1C1C` — divisórias do painel | `line-subtle-2` (borda) |
| 603 | `border: '1px solid #1C1C1C'` — separador dos irmãos | `line-subtle-2` (borda) |
| **553** | `isFocus ? '#1C1C1C'` — **fundo** da linha com foco | **`row-focus`** |
| 405 | `background:#1C1C1C` — fundo da barra flutuante de ação em massa | ver Débito abaixo |

**Por que criar:** sem `row-focus`, os planos de JSX desta fase escreveriam
`bg-pub-line-subtle-2` numa linha de tabela — um nome **mentindo sobre o uso**, que não é violação
de fidelidade (o valor é idêntico) mas é exatamente o tipo de encosto que faz o redesenho derivar.
**Por que é legítimo:** duplicata dentro da camada já é padrão **declarado por escrito** no próprio
config, no comentário de `neutral-dot` (que repete o hex de `txt-4`): *"Duplicata DENTRO da camada é
o que a camada existe para absorver; nas telas é o que TOK-15 proíbe."*
**Custo:** zero no CSS — provado por sha256 (seção abaixo).

## (2) "O que precisaria estar errado no código para este gate falhar?"

Respondido para **cada** teste novo, com a mutação que comprova.

### Teste A — `todo hex do protótipo de Publicações tem nome na camada (TOK-15)`

| asserção | falha se… | comprovada por |
|---|---|---|
| `length === 34` | alguém mexer em `HEX_DO_PROTOTIPO_PUBLICACOES` sem remedir | MUT 5 (apagar uma entrada → 33) |
| `orfaos(34) === []` | qualquer um dos 34 hex perder o nome — token removido, renomeado, **ou com o valor trocado** (o hex antigo deixa de existir na camada e volta a ser órfão) | MUT 1, 2, 3, 4 |
| `nomes.get(hex) === 'pub-…'` (×4) | o hex estar nomeado mas pelo token **ERRADO** — ex.: renomear `row-checked`. ⚠️ A varredura de órfão sozinha **passaria** nesse caso | é a asserção que fecha o furo que o 179-04 identificou |
| os 5 fundos `deepEqual` + `new Set().size === 5` | qualquer um dos cinco mudar, **ou dois colapsarem no mesmo valor** (alguém "simplificando" `row-checked` para igualar `row-new`) | MUT 1 faz exatamente isso: `row-checked` = `row-new` → `size` cai para 4 |

### Teste B — `a varredura de Publicações acusa o hex-isca`

Falha se `hexComNome()` ou `orfaos()` quebrarem de modo a reportar **tudo** como nomeado — por
exemplo trocando a igualdade por `includes` (substring casa com prefixo), ou fazendo `orfaos()`
devolver `[]` sempre. **Sem este teste, o "0 órfãos" do Teste A passaria por construção com o
medidor quebrado.** A 2ª asserção (`!hexComNome().has(HEX_ISCA)`) falha se alguém acrescentar
`#ABCDEF` à camada, o que tornaria a isca não-isca.

### Teste C — `a tabela de hex de Publicações não envelheceu`

| asserção | falha se… | comprovada por |
|---|---|---|
| `deepEqual` do CONJUNTO de hex | o protótipo ganhar ou perder um hex, ou a tabela ficar desatualizada | MUT 5 |
| a LINHA de cada hex | a primeira ocorrência de qualquer hex **mudar de linha** | **MUT 6** (608→607), que reprova **só aqui** — é a prova de que a conferência de linha não é enfeite |
| nenhum hex de 3 ou 8 dígitos | o protótipo adotar forma curta ou com alfa, que a varredura **não vê** — ela cegaria em silêncio | — |
| `cru.length > 60000` | a leitura vir truncada; sem ela, arquivo vazio passaria nas duas asserções de "nenhum hex curto" pelo motivo errado | — |
| o ramo `skip` | — | **provado ao vivo**: movi o protótipo do disco e o teste saiu `# SKIP new_layout_publicador/Publicacoes v2.dc.html ausente nesta máquina…`, com `skipped 1` no sumário (13 testes / 12 pass / 0 fail / **1 skipped**). Protótipo restaurado **idêntico** (sha256 `6dcbb2f9…` antes e depois) |

### ⚠️ E o que NÃO tem gate — declarado, não maquiado

Acrescentar `15.5` a `TAMANHOS_PERMITIDOS` **enfraquece** aquele gate em exatamente um valor, e
**nenhum teste pega uma permit-list larga demais** — por construção. O que protege a decisão é
(a) a medida escrita ao lado (`font-size:15.5px` aparece **1 vez**, em `:70`, medido, não presumido),
(b) o `18` deliberadamente de fora com o motivo escrito, e (c) o fato de **nenhum valor ter sido
removido** (20 → 21, provado por diff de conjunto). Não existe gate aqui e eu não finjo que existe.

## (3) O par `+assert` / `-assert`, por arquivo

| arquivo | linhas | `+assert` | `-assert` | leitura |
|---|---|---|---|---|
| `tailwind.config.js` | +42 / **-0** | — | — | só adição; `colors.ecf`, `fontFamily.sans` e `fontFamily.display` provados intactos pelo gate |
| `tests/js/publicador-tokens-do-publicador.test.js` | +227 / **-0** | **16** | **0** | nenhuma asserção removida, nem reapontada |
| `tests/js/publicador-vocabulario-novo.test.js` | +24 / **-1** | **0** | **0** | a única linha removida é o literal do `Set`, reapontado |

Sobre a linha removida no vocabulário — é exatamente o caso que o learnings chama de *"gate vazio ao
contrário"*: um `grep '^-'` acusaria remoção onde não houve. Provado por diff de conjunto:
`REMOVIDOS: []`, `ACRESCENTADOS: [15.5]`, 20 → 21 valores.

## As provas de CSS construído

### Sonda sobre conteúdo sintético — 0 falhas

⚠️ **Por que sintético:** medido nesta rodada que **nenhum `.jsx` usa os 5 tokens novos** (0
ocorrências), logo o JIT **não emite regra nenhuma** para eles e um grep no bundle de produção
provaria nada. Confirmado no CSS real (`assets/app-DrQVJJBj.css`, 219.509 bytes):

```
pub-row-checked: 0   pub-row-focus: 0   pub-line-off-2: 0   pub-neutral-ring: 0   pub-hover-toast: 0
CONTROLE (tokens antigos que as telas já usam):  pub-row-sel: 1   pub-surface: 1   pub-accent: 1
```

Rodando o Tailwind por postcss com o `content` apontado para um `.jsx` sintético, **o corpo da
regra** (não a presença da classe):

| classe | CSS emitido | hex |
|---|---|---|
| `bg-pub-row-checked` | `background-color: rgb(24 22 12 / var(--tw-bg-opacity, 1))` | `#18160C` ✓ |
| `bg-pub-row-focus` | `background-color: rgb(28 28 28 / var(--tw-bg-opacity, 1))` | `#1C1C1C` ✓ |
| `border-pub-line-off-2` | `border-color: rgb(42 42 42 / var(--tw-border-opacity, 1))` | `#2A2A2A` ✓ |
| `border-pub-neutral-ring` | `border-color: rgb(95 95 104 / var(--tw-border-opacity, 1))` | `#5F5F68` ✓ |
| `hover:bg-pub-hover-toast` | `.hover\:bg-pub-hover-toast:hover { … rgb(38 34 15 …) }` | `#26220F` ✓ |
| `text-[15.5px]` | `.text-\[15\.5px\] { font-size: 15.5px }` | ✓ |
| `bg-pub-row-sel` / `bg-pub-row-new` (controles) | `rgb(34 29 7)` / `rgb(20 18 10)` | ✓ |
| **ISCA** `bg-pub-naoexiste-9z` | **ausente**, e a string não aparece em nenhum lugar do CSS | ✓ |
| **CONTROLE de valor** | `bg-pub-row-checked` **não** casa com o rgb de `#14120A` (`row-new`) | ✓ |

A isca foi medida como **inexistente no projeto inteiro** (0 ocorrências em `*.jsx`/`*.js`/`*.php`/`*.css`)
antes de ser usada — é a lição do `w-[13px]`, que parecia inventada e saiu presente.

### sha256: a camada global ficou inerte

| variante | sha256 do CSS construído com o conteúdo REAL |
|---|---|
| **A** config de hoje (43 tokens) | `ae9130002d9501284f65dae22e5dc3ce5d7475bda22d83631916bcfa8991ad8e` |
| **B** sem os 5 novos (= `f23f610d`) | `ae9130002d9501284f65dae22e5dc3ce5d7475bda22d83631916bcfa8991ad8e` |
| **C** CONTROLE: `pub.surface` → `#112233` | `c535842bbf09b69242f8fcac078cfea3247eb57ecad37478778b32102a753da3` |

**A == B** byte por byte (257.894 bytes) — os 5 tokens não mudaram **uma vírgula** do CSS que o
sistema já construía. **C != A** — o controle prova que o medidor não está cego, e é ele que torna a
igualdade A==B significativa (T-180-01-T do threat model: o config é lido pelo build de todo o sistema).

## As 6 mutações — 6/6 pegas

Contagem do alvo travada em **1** antes de cada aplicação, linha alterada conferida depois,
restauração por cópia pristina conferida por sha256.

| # | mutação | linha | testes que reprovaram |
|---|---|---|---|
| 1 | `pub.row-checked` → `#14120A` (o `row-new`) | 179 | `a camada nova existe inteira…` + `todo hex… (TOK-15)` |
| 2 | `pub.line-off-2` → `#2E2E2E` (o FID-02 que o comentário proíbe) | 149 | idem |
| 3 | `pub.neutral-ring` → `#71717A` (o `neutral-dot`) | 208 | idem |
| 4 | apagar a linha de `row-checked` do config | 179 | + `nenhum token de colors.pub passa por omissão` |
| 5 | apagar `#18160C` de `HEX_DO_PROTOTIPO_PUBLICACOES` | 335 | `todo hex… (TOK-15)` + `a tabela… não envelheceu` |
| 6 | **`'#2A2A2A': 608` → `607`** | 338 | **só** `a tabela… não envelheceu` |

**0 recusadas** (todos os alvos tinham ocorrência única), **0 sobreviventes** — nada a investigar.
Restauração final: `tailwind.config.js` `9fc5d7f3…`, tokens test `5f9b321b…`, idênticos ao pristino.

## Divergências e armadilhas desta rodada

1. ⚠️ **A sonda de CSS mentiu — pela QUINTA vez na milestone, e de novo por erro do medidor.** Ela
   reportou `text-[15.5px]` → **REGRA AUSENTE**. Investiguei em vez de presumir: a regra estava
   presente e correta (`.text-\[15\.5px\] { font-size: 15.5px }`). A causa: eu escapei a barra do
   seletor com `seletor.replace(/[\\]/g, '\\\\')`. Para `.hover\:bg-…` isso funciona; para
   `.text-\[15\.5px\]` o resultado `\\[15\\.5px\\]` é *"barra literal seguida de CLASSE DE
   CARACTERES"* e a regex deixa de casar. É a **54-falsas-faltas do learnings, reproduzida numa
   forma nova**. Conserto: escapar **todos** os metacaracteres (`/[.*+?^${}()|[\]\\]/g`).
   **Placar: 1 falha na rodada, 1 do medidor, 0 do código.**
2. ⚠️ **Dois dos meus 15 caminhos de gate de território NÃO EXISTIAM** — pegos pelo `test -e`, que é
   justamente a disciplina que o learnings manda aplicar antes de acreditar num diff vazio:
   `resources/js/Components/Mlb/Publicador/Mesa` (o real é `resources/js/Components/Publicador/Mesa`)
   e `resources/js/Components/Mlb/Publicador/PublicadorHeader.jsx` (o real é
   `…/Publicador/header/PublicadorHeader.jsx`). **Terceiro e quarto caminho de gate errado da
   milestone.** Refeitos nos caminhos reais: 0 arquivos.
3. ⚠️ **A baseline JS do briefing está defasada:** ele diz `3737 / 3736 pass / 1 fail`; **medi
   `3739 / 3738 pass / 1 fail` ANTES de tocar em qualquer coisa.** +2 testes. A falha é a mesma
   pré-existente. Quem comparar contra 3737 vai achar regressão onde não há.
4. **`#1C1C1C` tem um 2º uso como FUNDO** que este plano **não** nomeou: `:405`, a barra flutuante
   de ação em massa (`background:#1C1C1C;border:1px solid #333333;border-radius:12px`). Não criei um
   terceiro nome para o mesmo hex por disciplina de escopo — ver Débito abaixo.

## Débito deixado de propósito

- **`#1C1C1C` como fundo da barra flutuante de ação em massa (`Publicacoes v2.dc.html:405`)** não
  tem nome de superfície. Quem desenhar essa barra tem três saídas e **nenhuma é usar `row-focus`**
  (seria nome mentindo sobre o uso, o mesmo erro que `row-focus` existe para evitar): criar
  `pub-bar-float`, usar `line-subtle-2` assumindo que ali é "superfície escura genérica", ou medir
  de novo e decidir. **Recomendo criar o nome**, no plano que montar a barra.
- **`18px` fora da lista permitida**, de propósito: Fase 181. Quem desenhar o painel de 460px
  acrescenta com a medida dele (`:374`-`:376`, 3 ocorrências).

## Verificação

| gate | resultado |
|---|---|
| `node --test` nos dois gates | **258 testes / 258 pass / 0 fail / 0 skipped** |
| `tests/js/publicador-tokens-do-publicador.test.js` isolado | 13 / 13 pass (0 skip — o protótipo está no disco, o detector rodou de verdade) |
| `npm run test:js` ANTES | 3739 / 3738 pass / **1 fail** |
| `npm run test:js` DEPOIS | **3742 / 3741 pass / 1 fail / 0 skipped** — delta **+3 testes, +3 pass**, a falha segue sendo a pré-existente `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido"), **não corrigida** |
| `npm run build` | exit **0** |
| manifest **pelo JSON** (`--input-type=commonjs`) | 478 entradas; `resources/js/app.jsx` → `assets/app-CvCMf5Bd.js`, css `assets/app-DrQVJJBj.css`; **13** entradas do Publicador — nenhuma página perdida |
| `git diff --numstat f23f610d..HEAD -- tailwind.config.js` | `42  0` — **0 linhas removidas** |
| PHP | **diff vazio** em `app/`, `database/`, `routes/` no range dos meus commits — suíte de 7min30 não rodada, por desnecessária |
| território proibido (15 alvos, range `7a6a694a..HEAD`) | **0 arquivos** em todos; **CONTROLE**: o mesmo gate dá 1 em `tailwind.config.js` e 2 em `tests/js`, logo não é vazio |
| range provado vivo | `f23f610d..HEAD` = 6 commits / 16 arquivos |
| árvore | limpa nos meus alvos (`git status --short` vazio para eles) |

Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma migration, nenhum pacote.
⚠️ **Nenhuma tela foi vista renderizada** — não há navegador neste ambiente, e nada aqui afirma o contrário.

## Self-Check: PASSED

- `.planning/phases/180-publicacoes-lista/180-01-SUMMARY.md` — FOUND
- `tailwind.config.js` com os 5 tokens — FOUND (43 chaves em `colors.pub`, confirmadas por `import()` do config real)
- `5972353b` — FOUND no `git log`
- `65f98b67` — FOUND no `git log`
- `tests/js/publicador-tokens-do-publicador.test.js` com `HEX_DO_PROTOTIPO_PUBLICACOES` — FOUND
- `tests/js/publicador-vocabulario-novo.test.js` com `15.5` — FOUND
