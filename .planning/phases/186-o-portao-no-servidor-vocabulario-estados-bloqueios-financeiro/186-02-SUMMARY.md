---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 02
subsystem: publicador-portao-de-aprovacao
tags: [vocabulario, fidelidade, render-adverso, gate-por-omissao, pt-br]
requires:
  - "tailwind.config.js — a camada de tokens `pub-*` (TOK-04..TOK-08) e a animação `pub-spin`"
  - "tests/gates/verificar-testes.sh — o harness de gates do plano 186-01"
  - "tests/js/publicador-vocabulario-novo.test.js — o GATE-03, que este plano ESTENDE"
  - "resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx — o precedente do fallback"
provides:
  - "ANDAMENTOS — os 8 estados de andamento interno, em chaves pt-BR do banco (VOC-02/VOC-05)"
  - "andamentoDe() — o fallback que NUNCA devolve null (a correção do bug S3)"
  - "DO_PROTOTIPO / ORIGEM_DO_PROTOTIPO / BLOQUEIO_DO_PROTOTIPO — a camada de tradução, só para conferência FID-01"
  - "ORDEM_DA_FILA (AND-08), LIVE (AND-05), ctaDoAndamento (AND-10), FAIXA_DE_ANDAMENTO (AND-09)"
  - "SELOS_DE_ORIGEM (VOC-04), ABREVIACAO_DO_BLOQUEIO (BLOQ-12), ROTULO_DO_BLOQUEIO (BLOQ-03)"
  - "CRITERIOS (7), DEFEITOS (6), TOASTS (6) e os literais fixos do portão (VOC-03/VOC-08)"
  - "SeloDeAndamentoInterno — a pill de andamento, consumida pela Fase 187 (FILA-13)"
  - "`aprovacao/` dentro de DIRETORIOS_FECHADOS do GATE-03 (VOC-07)"
  - "O gate dos DOIS EIXOS (D2/AND-02) com isca nas duas direções"
affects:
  - "Fase 186, plano 04 — o PHP (`PubAprovacaoOferta`) tem de usar ESTAS 8 chaves pt-BR"
  - "Fase 186, plano 09 — o gate (g) compara as duas listas (PHP ↔ JS) uma contra a outra"
  - "Fase 187 — a coluna Andamento da tabela da fila monta `SeloDeAndamentoInterno`"
  - "tests/gates/contagens-186-01.txt — a contagem do gate do 186-01 SUBIU (ver Divergências)"
tech-stack:
  added: []
  patterns:
    - "Vocabulário único em pt-BR + camada de tradução só-para-conferência, com teste de bijeção"
    - "Fallback que devolve objeto desenhável, nunca `null` (a chave aparece como chegou)"
    - "Dois gates por elemento: CSS construído (valor) + HTML (igualdade de conjunto de classes)"
    - "Mutação com contagem de ocorrências travada antes e restauração por sha256"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx
    - tests/js/publicador-aprovacao-vocabulario.test.js
    - tests/gates/contagens-186-02.txt
  modified:
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "Um vocabulário só, em pt-BR: as 8 chaves de dado são as do banco; as inglesas do protótipo existem apenas em mapas de conferência, com bijeção provada"
  - "As chaves de BLOQUEIO também viraram pt-BR, num namespace ÚNICO compartilhado por `SHORT` e `BLK` — dar nome diferente ao mesmo conceito recriaria o bug S3 nos bloqueios"
  - "A bolinha/anel da pill herdam a cor do TEXTO (`bg-current`/`border-current`), porque é `m[1]` que o protótipo usa ali, não `m[4]`"
  - "`aprovacao/` entrou em DIRETORIOS_FECHADOS no plano que cria o primeiro arquivo dela, e a omissão foi medida por mutação"
metrics:
  duration: "~2h"
  completed: 2026-10-14
---

# Fase 186 Plano 02: O Vocabulário do Portão Summary

Os 8 estados de andamento interno, os 3 selos de origem financeira, os bloqueios, critérios,
defeitos e toasts do portão nascem como **um vocabulário só, em pt-BR**, com `andamentoDe()`
devolvendo sempre um objeto desenhável — e o diretório novo entra na lista fechada do GATE-03 no
mesmo plano que o cria, com a omissão provada por mutação.

## Commit de partida e commits deste plano

**Partida:** `28b45b40` (todo gate de diff deste plano usa o range `28b45b40..HEAD`).

| Commit | Tipo | Assunto |
|---|---|---|
| `946e7ac7` | test | gate RED do vocabulário do portão, com manifesto de contagem |
| `abb55345` | feat | os dois módulos do vocabulário do portão, em pt-BR |
| `906575f3` | test | gate RED do selo de andamento, render adverso e os dois eixos |
| `00454f13` | feat | SeloDeAndamentoInterno — forma + cor + texto, nunca só cor |
| `a10a8ffb` | test | VOC-07 — `aprovacao/` entra no GATE-03, e o gate dos DOIS EIXOS |

⚠️ **O range `28b45b40..HEAD` contém TAMBÉM 4 commits do plano 186-03**, que rodou em paralelo na
mesma árvore (`8db7c43d`, `27bd6d3e`, `27df50b9`, `f598bc0d` — models, migrations e testes de
Feature). **O discriminador é o ESCOPO DO ASSUNTO, nunca o autor** (todas as sessões usam o mesmo
`user.name`). Os arquivos dos meus 5 commits são **exatamente** os 6 de `files_modified`, e o
conjunto é **disjunto** do do 186-03: nenhum arquivo aparece nos dois.

## Gates — todos pelo harness do 186-01, nenhum `node --test` à mão

| Gate | Comando | Resultado |
|---|---|---|
| `vocabulario-modulos` | `sh tests/gates/verificar-testes.sh 02 vocabulario-modulos` | `# tests 180 / # pass 180 / # fail 0` |
| `vocabulario-render` | `sh tests/gates/verificar-testes.sh 02 vocabulario-render` | `# tests 180 / # pass 180 / # fail 0` |
| `voc-07-gate-03` | `sh tests/gates/verificar-testes.sh 02 voc-07-gate-03` | `# tests 399 / # pass 399 / # fail 0` |
| autoteste do harness | `sh tests/gates/verificar-testes.sh --autoteste` | `14 casos, 0 divergência` |
| suíte JS inteira | `sh tests/gates/verificar-testes.sh --suite js` | `4777 / 4776 pass / 1 fail / 0 skipped` |

A **única** falha da suíte é a pré-existente `tests/js/estrutura-grade-glide.test.js:122`
("Características secundárias nasce recolhido") — **não corrigida, como instruído**. A baseline do
186-01 era 4539/4538/1; os 4777 de agora somam os meus +238 e os do 186-03. **Contagem acima da
referência é esperada e passa; abaixo seria regressão.**

PHP não foi executado: **este plano não toca nenhum arquivo PHP** (a suíte PHP é do 186-03, rodando
em paralelo).

## Contagens — `tests/gates/contagens-186-02.txt`, com os dois regimes

| id | arquivo | pristino | mutado | o que o 2º regime é |
|---|---|---|---|---|
| `vocabulario-modulos` | `publicador-aprovacao-vocabulario.test.js` | **180** | **169** | CSS construído mais VELHO que os `.jsx` → o bloco "GATE-02 (CSS)" entra em `skip` NOMEADO por `mtime` e leva os **11** subtestes dele (180 − 169 = 11), com `# skipped 1` no lugar |
| `vocabulario-render` | o mesmo arquivo | **180** | **169** | idem |
| `voc-07-gate-03` | `publicador-vocabulario-novo.test.js` | **399** | **399** | este arquivo **não tem** sonda com `skip` por `mtime`; a coincidência foi MEDIDA, não presumida |

**Os dois regimes foram medidos de verdade**, não deduzidos: 180 logo após `npm run build`; 169
depois de um `touch` em `SeloDeAndamentoInterno.jsx` (com `sha256sum -c` conferindo que o conteúdo
não mudou). Travar só em 180 abortaria a 1ª mutação da campanha; não travar em nenhum dos dois
marcaria a campanha inteira como "SOBREVIVEU" por um motivo que não é o código.

**Histórico MEDIDO do número de `publicador-aprovacao-vocabulario.test.js`** (está escrito no
manifesto, para a elevação ser diff visível):

| regime | medição | por quê |
|---|---|---|
| RED da Task 1 | `# tests 1 / # fail 1` | os dois módulos movidos para fora da árvore; a guarda de `test -e` do topo lança antes de registrar teste nenhum |
| Task 1 verde | `# tests 113 / # fail 0` | módulos restaurados (`sha256sum -c` OK) |
| RED da Task 2 | `# tests 114 / # fail 1` | bloco do selo registrado, componente ainda ausente |
| Task 2 verde | `# tests 180 / # fail 0` | componente + bloco de CSS construído |

**Baseline de `publicador-vocabulario-novo.test.js` ANTES deste plano: 344, MEDIDA** (não deduzida)
rodando a versão do arquivo no commit `28b45b40`. Os 55 testes novos estão decompostos um por um no
manifesto: +18 do laço de 6 subtestes sobre os 3 arquivos novos, +1 do 4º diretório no laço de
`DIRETORIOS_FECHADOS`, +1 do sub-teste de contagem da Fase 186, +6 do auto-teste da sonda dos eixos,
+5 de "a leitura não é vazia", +10 do eixo interno, +7 do eixo do ML e +7 do bloco de VOC-03.
344 + 55 = 399. ✔

## ⚠️ VOC-07 — a prova de que `aprovacao/` entrou em `DIRETORIOS_FECHADOS`, com isca

**O que foi feito** em `tests/js/publicador-vocabulario-novo.test.js`:

1. `const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';` junto das outras
   constantes de diretório (:38, logo abaixo de `DIR_PUB`).
2. Os **3** arquivos em `ARQUIVOS_NOVOS` (:180-:182), com o motivo escrito ao lado.
3. `const DIRETORIOS_FECHADOS = [DIR, DIR_VG, DIR_PUB, DIR_APROV];` (:266) — **ADIÇÃO PURA: nada
   saiu da lista.**
4. Sub-teste de **CONTAGEM** `'os 3 arquivos da Fase 186 estão na lista'` (:372), no molde dos das
   Fases 179/180, com o aviso de que os planos seguintes **ELEVAM** o número.

**E a prova de que a adição é o que faz o gate morder — medida, não argumentada:**

| mutação | `DIR_APROV` em `DIRETORIOS_FECHADOS`? | arquivo forjado em `aprovacao/`? | resultado medido |
|---|---|---|---|
| M7 | **sim** (o estado entregue) | sim, com `text-sm` e hex cru | `# tests 399 / # pass 397 / # fail 2` → **o gate pega** |
| M8 | **não** (a linha 266 revertida) | **o mesmo arquivo forjado, ainda lá** | `# tests 398 / # pass 398 / # fail 0` → **VERDE E ERRADO** |

**M8 é a omissão, medida.** Sem o passo 3, um arquivo com `text-sm` e hex cru dentro de `aprovacao/`
passa sem nenhuma conferência de vocabulário e **nenhum número se move**. É por isso que a entrada
não esperou o fechamento da fase.

**Isca do próprio sub-teste de contagem:** ele monta um caminho forjado
(`${DIR_APROV}/ArquivoQueNinguemDeclarou.jsx`), prova que ele **não** está em `ARQUIVOS_NOVOS`, e
prova que a comparação usada pelo `deepEqual` do GATE-03 **distingue** a lista com um arquivo a mais
— senão a cobertura dependeria de ninguém nunca esquecer nada, que é a hipótese que o gate existe
para não precisar fazer.

**A lista completa dos 3, todos em `ARQUIVOS_NOVOS`:**
`aprovacao/andamentoDoPortao.jsx` · `aprovacao/vocabularioDoPortao.jsx` ·
`aprovacao/SeloDeAndamentoInterno.jsx`.

## ⚠️ Todo arquivo novo é `.jsx` — e a premissa foi medida

| arquivo | extensão | carrega classe de Tailwind? |
|---|---|---|
| `andamentoDoPortao.jsx` | `.jsx` | **sim** — as 4 classes × 8 estados |
| `vocabularioDoPortao.jsx` | `.jsx` | **sim** — as classes dos 3 selos de origem |
| `SeloDeAndamentoInterno.jsx` | `.jsx` | **sim** — a pill inteira |

Provas no código: o sub-teste `'nenhum `.js` em `aprovacao/`'` (lê o diretório do disco), o
`deepEqual(daFase186.filter(c => c.endsWith('.js')), [])` no GATE-03, e a **premissa medida**: o
`content` do `tailwind.config.js` varre `./resources/js/**/*.jsx` e **não** `*.js`.

**Mutação M9:** renomear `vocabularioDoPortao.jsx` → `.js` derruba o gate inteiro
(`# tests 1 / # fail 1`, a guarda de `test -e`), e o conteúdo voltou com `sha256` idêntico.

⚠️ **ARMADILHA ACHADA AO ESCREVER O GATE DA PREMISSA, e ela é da família do learnings §3:**
`lerSemComentarios()` remove blocos `/* … */`, e o glob do `content` é `'./resources/js/**/*.jsx'` —
**o `/**/` do meio dele CASA com o padrão de comentário de bloco**. A fonte "sem comentários" chegava
como `'./resources/js*.jsx'` e a asserção **reprovava o arquivo CERTO**. A leitura da premissa virou
**crua** (`fs.readFileSync`), com um comentário explicando por quê **e uma asserção de controle**
cobrando que o helper continue comendo o `/**/` — se um dia parar, o comentário fica desatualizado e
o gate avisa. (`tests/js/publicador-aprovacao-vocabulario.test.js`, bloco GATE-08.)

## ⚠️ A bijeção pt-BR ↔ `DO_PROTOTIPO`

**Decisão, e ela é a correção do achado S3:** as chaves de dado são **as do banco, em pt-BR**, nos
dois lados. As chaves inglesas do protótipo vivem **apenas** em mapas de conferência, e cada um tem
teste de bijeção (injetividade + sobrejetividade + origens iguais às medidas no protótipo).

| mapa | origens (protótipo) | destinos (nossas) | provado |
|---|---|---|---|
| `DO_PROTOTIPO` | 8, as de `STM` | 8, as de `ANDAMENTOS` | `8 ↔ 8`, sem destino repetido |
| `ORIGEM_DO_PROTOTIPO` | 3, as de `SRC` (`conf`/`est`/`miss`) | 3, as de `SELOS_DE_ORIGEM` | `3 ↔ 3` |
| `BLOQUEIO_DO_PROTOTIPO` | 11, a união de `SHORT` (9) e `BLK` (8) | 11, a união das nossas | `11 ↔ 11` |

`preparing→preparando` · `awaiting→aguardando_aprovacao` · `ecf_review→em_revisao_ecf` ·
`changes→ajustes_solicitados` · `approved→aprovado` · `publishing→publicando` · `failed→falha` ·
`published→publicado`.

**Por que o teste de bijeção não é decoração:** um mapa com 7 entradas deixaria um estado **sem
medição** contra `STM`; um mapa com dois destinos iguais mediria um estado **duas vezes** e outro
nenhuma. As duas coisas são gates que passam por omissão, e nada mais na suíte olharia para isso.

Asserções complementares no mesmo bloco: **nenhuma** das 8 chaves inglesas é chave de `ANDAMENTOS`,
de `ORDEM_DA_FILA` ou de `LIVE`; e as 8 chaves pt-BR são **exatamente** as 8 do PHP, na ordem do
protótipo (a ordem importa: a conferência FID-01 é lida linha a linha).

## ⚠️ `andamentoDe()` não devolve `null`, e o selo tem texto não-vazio

| entrada | `andamentoDe()` devolve | o selo renderiza |
|---|---|---|
| uma das 8 chaves pt-BR | a entrada de `ANDAMENTOS`, `conhecido: true` | o rótulo de VOC-02 |
| `'awaiting'`, `'ecf_review'`, … (as 8 do protótipo) | a **chave como chegou** no rótulo, forma e cor **neutras**, `abreRevisao: false` | **a chave, como texto não-vazio** |
| `'toString'`, `'constructor'`, `'__proto__'`, `'valueOf'`, `'hasOwnProperty'` | a chave como chegou, `conhecido: false` | a chave — **nunca uma função** |
| `null`, `undefined`, `42`, `NaN`, `''`, `'   '`, `[]`, `true`, `{toString:null}`, `{toString(){throw}}` | rótulo `'—'` | `'—'` |

**A asserção é de TEXTO NÃO-VAZIO DENTRO DO ELEMENTO**, não de ausência de exceção: um
`if (!entrada) return null` passa em teste de render ("não quebrou") e **desaparece na tela** — é
exatamente o modo de falhar que o S3 encontrou. Os 8 estados pt-BR e as 8 chaves inglesas têm **um
`test()` cada**, nunca um laço com 8 asserções dentro, para a **contagem** ser o sinal.

**Mutação M1** — trocar o fallback por `return null`: **`# fail 34`**. O gate não pega "um" sintoma:
pega 34.

Detalhes de disciplina aplicados: `textoSeguro` **não** é usado no rótulo de propósito
(`textoSeguro(NaN)` devolveria a string `'NaN'`); a guarda é `typeof !== 'string'` **antes** de
qualquer indexação, e o `hasOwnProperty.call` só roda depois dela (com a chave adversa ele LANÇA); e
toda mensagem de asserção usa `typeof` + descrição fixa, **nunca** `String()`/`JSON.stringify()` do
valor adverso.

## Tabela FID-01 — 14 medidas, **derivadas de SCRIPT**

**Como foi medido (declarado):** `grep -nE '\b(STM|SRC|SHORT|BLK|CRIT|DEF|ORD|ST)[[:space:]]*='`
seguido de `sed -n` nas faixas, sobre `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas), em
14/10/2026. **Nenhuma linha foi aceita pelo número do plano:** cada identificador foi localizado pela
**semântica** e as linhas conferidas. Resultado da conferência das referências que o plano citava:

| referência do plano | linha MEDIDA | confere? |
|---|---|---|
| `STM` :963-971 | 963-971 | ✔ |
| `CRIT` :961 | 961 | ✔ |
| `DEF` :962 | 962 | ✔ |
| `BLK` :973 | 973 | ✔ |
| `SHORT` :974 | 974 | ✔ |
| `SRC` :1125 | 1125 | ✔ |
| `ST` :1212 | 1212 | ✔ |
| `ORD` :1215 | 1215 | ✔ |
| `cta` :1240 | 1240 | ✔ |
| a pill | :170-:172 (achada por `grep -n stLabel`) | ✔ (o plano não a citava) |

### As 8 linhas de `STM` (:963-971)

| chave pt-BR | chave do protótipo | rótulo | texto | fundo | borda | dot (`m[4]`) |
|---|---|---|---|---|---|---|
| `preparando` | `preparing` | Preparando | `text-pub-neutral-txt` (#A1A1AA) | `bg-pub-hover-2` (#161616) | `border-pub-line-strong` (#2E2E2E) | `bg-pub-neutral-dot` (#71717A) |
| `aguardando_aprovacao` | `awaiting` | Aguardando aprovação | `text-pub-accent` (#FACC15) | `bg-pub-accent/[0.10]` | `border-pub-accent/[0.35]` | `bg-pub-accent` |
| `em_revisao_ecf` | `ecf_review` | Em revisão pela ECF | `text-pub-fase1` (#D4D4D8) | `bg-pub-hover-3` (#1A1A1A) | `border-pub-line-off` (#3F3F46) | `bg-pub-fase1` |
| `ajustes_solicitados` | `changes` | Ajustes solicitados | `text-pub-warn-txt` (#FBBF24) | `bg-pub-warn/[0.10]` | `border-pub-warn/[0.4]` | `bg-pub-warn` (#F59E0B) |
| `aprovado` | `approved` | Aprovado | `text-pub-ok-txt` (#4ADE80) | `bg-pub-ok/[0.10]` | `border-pub-ok/[0.35]` | `bg-pub-ok` (#22C55E) |
| `publicando` | `publishing` | Publicando | `text-pub-ok-txt` | `bg-pub-ok/[0.10]` | `border-pub-ok/[0.35]` | `bg-pub-ok` |
| `falha` | `failed` | Falha na publicação | `text-pub-danger-txt` (#F87171) | `bg-pub-danger/[0.10]` | `border-pub-danger/[0.4]` | `bg-pub-danger` (#EF4444) |
| `publicado` | `published` | Publicado | `text-pub-ok-txt` | `bg-pub-ok/[0.10]` | `border-pub-ok/[0.35]` | `bg-pub-ok` |

### As 3 linhas de `SRC` (:1125)

| chave pt-BR | chave do protótipo | rótulo | texto | borda |
|---|---|---|---|---|
| `confirmado` | `conf` | Confirmado | `text-pub-ok-txt` (#4ADE80) | `border-pub-ok/[0.35]` |
| `estimado` | `est` | Estimado | `text-pub-fase1` (#D4D4D8) | `border-pub-line-off` (#3F3F46) |
| `pendente` | `miss` | Pendente | `text-pub-danger-txt` (#F87171) | `border-pub-danger/[0.45]` |

**Total: 8 + 3 = 14 medidas.** **Nenhum token novo foi criado** (FID-08): os 14 hex já têm nome na
camada `pub-*`, e as tintas `rgba(…)` saem do modificador de opacidade do próprio token — mesmo valor
computado, com um nome só. **Zero hex literal no corpo dos 3 arquivos** (todos os hex deste SUMMARY e
dos comentários de código ficam fora do `lerSemComentarios`, e há asserção provando).

### Justificativa da contagem de medidas (FID-03)

As medidas de geometria **não** são arredondadas para a escala nomeada: `h-[22px]`, `px-[8px]`,
`gap-[6px]`, `text-[11.5px]`, `w-[6px]`/`h-[6px]`, `w-[9px]`/`h-[9px]`, `border-[1.5px]`, e o raio de
6px pelo token `rounded-pub-chip`. **`text-sm` seria 14px** e mudaria a pill — `11.5` já estava na
lista de `TAMANHOS_PERMITIDOS` do GATE-03 e **nenhum valor novo precisou ser liberado**, o que foi
conferido e não presumido.

## Os dois gates por elemento (GATE-02) — e eles não se substituem

**Eixo HTML:** igualdade de conjunto das classes da raiz, para cada um dos 8 estados (10 da base da
pill + fundo + borda + texto), mais o conjunto da bolinha (5 classes) e o do anel (8). A raiz é
localizada pela **posição 0** (o elemento mais externo), nunca pela lista de classes. Asserção extra:
`className` entra na raiz e **não come** nenhuma classe da pill.

**Eixo CSS:** 11 subtestes lendo o **corpo** da regra em `public/build/assets/app-BhcXX3y8.css`
(223.881 bytes, pelo **JSON** do manifest, nunca por grep — o hash sai com hífen). **As formas foram
MEDIDAS antes de escrever a regex**, e confirmam as normalizações do learnings §3:

| forma | medida |
|---|---|
| token sólido | `bg-pub-hover-2` → `--tw-bg-opacity: 1;background-color:rgb(22 22 22 / var(--tw-bg-opacity, 1))` — **decimal** |
| modificador de opacidade | `bg-pub-accent/[0.10]` → `background-color:#facc151a` — **hex de 8 dígitos**, minúsculo |
| idem, borda | `border-pub-ok/[0.35]` → `#22c55e59` · `border-pub-warn/[0.4]` → `#f59e0b66` |
| zero da frente comido | `animate-pub-spin` → `animation:spin .7s linear infinite` (**`.7s`**, não `0.7s`) |
| `currentColor` | `bg-current` → `background-color:currentColor` (sem variável de opacidade) |
| seletor escapado | `.h-\[22px\]`, com **todos** os metacaracteres escapados |

**Byte do alfa como asserção:** `1a` = 0.102 (o `0.10` do protótipo) e `1f` seria 0.12; `59` = 0.349
e `66` = 0.4. Há controle negativo cobrando que 0.35 **não** virou 0.4 e vice-versa — é esse byte que
distingue a borda de `aprovado` da de `falha`.

**Iscas do eixo CSS:** `bg-pub-isca-186a2` e `h-[223px]`, as duas **medidas como inexistentes** no
repo inteiro (`grep -rl` em `resources/`, `app/`, `tests/`, `tailwind.config.js`) antes de serem
usadas. ⚠️ `text-[97.5px]` **foi descartada como isca**: ela já aparece em
`publicador-publicacoes-busca.test.js`. Controle positivo: a sonda **acha** `h-[22px]`.

**Fechamento por omissão do eixo CSS:** um sub-teste varre **todas** as classes que os 8 estados
escrevem (lidas de `ANDAMENTOS`, não de uma cópia à mão) e exige que **cada uma gere CSS** — token
novo escrito num estado sem existir na camada geraria classe sem regra e a cor simplesmente não
apareceria. O número **39** está travado, com a aritmética escrita (21 de geometria + 18 de cor).

## O gate dos DOIS EIXOS (D2 / AND-02), com isca nas duas direções

| direção | diretório varrido | literais procurados | resultado |
|---|---|---|---|
| andamento interno **não** vaza para a lista | `publicacoes/` (15 arquivos) | os 8 rótulos de VOC-02 | **0 ocorrências** |
| status do ML **não** vaza para a fila | `aprovacao/` (3 arquivos) | `Ativa`, `Pausada`, `Com erro`, `Em análise pelo Mercado Livre`, `Em análise pelo ML` | **0 ocorrências** |

**Controles positivos (a negativa sozinha é metade do gate):**
- a MESMA sonda acha os **8** rótulos internos em `aprovacao/andamentoDoPortao.jsx`;
- a MESMA sonda acha os **4** rótulos do ML em `identidade/SeloDeStatusDaPublicacao.jsx`.
  ⚠️ O 5º (`Em análise pelo ML`, a forma curta da aba) **não** entra no controle porque ele vive no
  **PHP** (`MlbAnuncioController::meus()`): afirmar que estaria no `.jsx` seria um controle que passa
  pelo motivo errado.
- "a leitura de `publicacoes/` não está cega": o literal `Rascunhos`, que existe no **corpo** de
  `AbasDeStatus.jsx`, é achado pela mesma sonda.

**Mutações nas duas direções:**

| mutação | o que entrou | resultado |
|---|---|---|
| M4 | `rotulo: 'Pendente'` → `'Pausada'` em `vocabularioDoPortao.jsx` | **`# fail 2`** — MORTA |
| M6 | `const JARGAO_INTERNO = 'Aguardando aprovação'` em `publicacoes/VaziosDaLista.jsx` | **`# fail 2`** — MORTA |

**Decisões de sonda, com a medição ao lado:**
- a borda é de **letra/dígito Unicode** (`(?<![\p{L}\p{N}_])`), não `\b`: sem isso `Ativa` casaria
  dentro de `chaveAtiva`, `ehAtiva` e `estaAtiva` — **identificadores REAIS** de `AbasDeStatus.jsx` e
  `ChipsDeFiltro.jsx` —, e `Publicado` casaria dentro de `Publicados`/`Publicadas`;
- **"Em revisão pela ECF" CONTÉM "Em revisão"**: aqui a sonda procura o literal **completo**; a
  negativa curta é do gate do 186-01, que tem a lista de `QUALIFICADOS` e o auto-teste dela. **As
  duas regras NÃO foram duplicadas numa sonda só** — juntas ficariam ambíguas e nenhuma diria por que
  reprovou. ⚠️ `Em revisão pela ECF` e `Em revisão pelo Mercado Livre` **continuam** na lista de
  qualificados do 186-01 e **nenhum qualificado novo foi acrescentado**;
- leitura por `lerSemComentarios()`, e isto foi **medido**: as 6 ocorrências de
  `Ativa`/`Com erro`/`Em análise pelo ML` em `publicacoes/` estão **todas em comentário**
  (`AbasDeStatus.jsx`:16/:19/:25 e `CelulasDaLinha.jsx`:383/:386/:736). Um gate cru reprovaria a tela
  certa;
- **auto-teste da sonda** com 5 casos (acha o inteiro; não conta `chaveAtiva`; não conta
  `Publicados`; não confunde os dois "Em revisão"; respeita borda com acento em `publicação` ×
  `publicações`);
- **isca de caminho** `aprovacaoZZ9`, que não existe e nunca vai existir: ela prova que diretório
  ausente devolve `null` e cai em `skip` **NOMEADO**, em vez de uma lista vazia que pareceria
  "varrido e limpo".

## VOC-03 — "lucro líquido", nas duas formas, recortado à lista constante

A sonda procura `/lucro\s+l[ií]quido/i` **e** `/lucro_l[ií]quido/i` **somente** nos 3 arquivos novos.

⚠️ **O recorte é medição, não cautela:** existe um campo **real** `lucro_liquido` em
`EstruturaPrecificacaoParametros` — legítimo, do Portal, de outra fase. Uma sonda que varresse
`resources/` ou `app/` reprovaria aquele parâmetro antigo, e o executor seguinte "consertaria"
renomeando coisa que não é dele.

**Isca / controle positivo:** a mesma sonda **acha** `Resultado estimado por venda` e
`Margem de contribuição estimada` no corpo de `vocabularioDoPortao.jsx`. **Auto-teste:** as duas
regexes casam com exemplos forjados e **não** casam com o vocabulário travado.
**Mutação M5** — `RESULTADO_ESTIMADO` virando `'Lucro líquido por venda'`: **`# fail 3`**.

## Campanha de mutação — contagem travada antes, linha conferida, sha256, árvore limpa

Protocolo de cada mutação: `grep -c -F` da string **antes** (recusa se ≠ 1) → `sha256sum` antes →
substituição → conferência de que o arquivo mudou → rodar o gate → restaurar o `.bak` →
`sha256sum` depois, exigindo igualdade.

| # | mutação | alvo | gate | resultado |
|---|---|---|---|---|
| M1 | fallback de `andamentoDe()` volta a ser `return null` | `andamentoDoPortao.jsx` | render | **MORTA** `# fail 34` |
| M2 | paráfrase: `'Aguardando aprovação'` → `'Aguardando'` | `andamentoDoPortao.jsx` | render | **MORTA** `# fail 2` |
| M3 | `h-[22px]` → `h-[24px]` na pill | `SeloDeAndamentoInterno.jsx` | render | **MORTA** `# fail 11` |
| M4 | rótulo do ML entra em `aprovacao/` | `vocabularioDoPortao.jsx` | GATE-03 | **MORTA** `# fail 2` |
| M5 | `RESULTADO_ESTIMADO` vira "Lucro líquido por venda" | `vocabularioDoPortao.jsx` | GATE-03 | **MORTA** `# fail 3` |
| M6 | rótulo interno entra em `publicacoes/` | `publicacoes/VaziosDaLista.jsx` | GATE-03 | **MORTA** `# fail 2` |
| M7 | arquivo forjado em `aprovacao/`, lista fechada **com** `DIR_APROV` | — | GATE-03 | **MORTA** `# fail 2` |
| M8 | o **mesmo** forjado, lista fechada **sem** `DIR_APROV` | `publicador-vocabulario-novo.test.js` | GATE-03 | ⚠️ **SOBREVIVEU — POR CONSTRUÇÃO** (ver abaixo) |
| M9 | `vocabularioDoPortao.jsx` → `.js` | — | GATE-03 | **MORTA** `# tests 1 / # fail 1` |
| M10 | um dos 3 sai de `ARQUIVOS_NOVOS` | `publicador-vocabulario-novo.test.js` | GATE-03 | **MORTA** `# fail 5` |
| M11 | a contagem `3` da Fase 186 é afrouxada para `4` | `publicador-vocabulario-novo.test.js` | GATE-03 | **MORTA** `# fail 2` |

**A sobrevivente M8 foi investigada e NÃO é presumida equivalente — ela é o CONTROLE do plano.**
M7 e M8 são a mesma situação de mundo (um arquivo não declarado em `aprovacao/` com `text-sm` e hex
cru) com e sem a linha 266. M7 reprova; M8 fica `# pass 398 / # fail 0`. **A sobrevivência de M8 é a
medição do furo que VOC-07 fecha**, e é o que torna a entrega verificável em vez de argumentada.

**Restauração:** todas as 11 conferidas. As de arquivo (M9) por `sha256sum` do conteúdo; as de string
por `sha256sum` antes/depois; as de criação (M7/M8) por `ls` do diretório + `sha256` do arquivo de
teste. **Árvore conferida limpa** nos meus caminhos ao fim: `find` por `*.bak-mut`, `*Forjado*` e
`.tmp-baseline*` devolveu vazio, e `ls aprovacao/` mostra exatamente os 3 arquivos.

⚠️ **Limitação declarada do script de mutação:** o campo "linha mutada depois" é lido por
`grep -n -F` do texto NOVO, e em M1 o texto novo (`return null;`) **já existia** em outro lugar do
arquivo (dentro de `entradaDe`), então ele reportou a linha 296 em vez da 369. **A guarda que
importa — a contagem da string ORIGINAL travada em 1 antes — valeu nas 11.**

## Build e manifest

`npm run build` → exit 0 (rodado 3×: depois da Task 2, depois da campanha e no fim, para deixar o
regime **pristino**). Manifest lido **pelo JSON** (`node --input-type=commonjs`), nunca por grep:
`resources/js/app.jsx` → `assets/app-EVrrL0Z-.js` + `assets/app-BhcXX3y8.css`, e o CSS existe no
disco. **Isca do manifest:** `resources/js/Pages/Mlb/PaginaQueNaoExiste186.jsx` → ausente, enquanto
`resources/js/Pages/Mlb/MeusAnuncios.jsx` → presente.

## Território

| verificação | resultado |
|---|---|
| arquivos dos meus 5 commits | exatamente os **6** de `files_modified` |
| `database/migrations/` e testes de migration | **intocados** por mim (são do 186-03; aparecem no range por serem commits dele) |
| `app/Services/Creative/`, endpoints `criativo*`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` | diff **vazio** |
| `Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa/`, `App\Support\Publicador`, `MlPublicacaoService`, `AppLayout.jsx`, `Bling*`, `Services/Portal/` | diff **vazio** |
| `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v27.md`, `.planning/STATE.md` | diff **vazio** |
| `tests/js/estrutura-meus-anuncios.test.js` (D-11/D-22), `publicador-header-zindex-ordem.test.js` | diff **vazio** |
| `package.json` / `package-lock.json` (T-186-SC) | diff **vazio** — nenhum pacote instalado |
| deleções no range | **nenhuma** (`git diff --diff-filter=D` vazio) |
| `vendor/bin/pint`, worktree, `state.advance-plan`, deploy, push, VPS, migration, leitura de produção | **nada disso foi feito** |

Commits sempre com pathspec (`git add -- <caminho>` e `git commit -m/-F … -- <caminho>`), mensagens
por `-F` (acentuadas). **Nunca** `git add -A`, `git stash`, `git reset`, `git checkout`, `--amend`,
`git clean`. ⚠️ `tests/gates/contagens-186-03.txt` apareceu **modificado** durante o trabalho (é do
186-03) e **não foi tocado**.

## Deviations from Plan

### 1. [Rule 1 — correção de gate] A premissa do Tailwind lida CRUA

- **Found during:** Task 1
- **Issue:** `lerSemComentarios('tailwind.config.js')` come o `/**/` do glob
  `'./resources/js/**/*.jsx'` e devolve `'./resources/js*.jsx'`; a asserção da premissa reprovava o
  arquivo certo.
- **Fix:** leitura crua por `fs.readFileSync` + asserção de controle cobrando que o helper continue
  comendo o `/**/` (se parar, o gate avisa que o comentário ficou velho).
- **Files:** `tests/js/publicador-aprovacao-vocabulario.test.js`
- **Commit:** `946e7ac7`

### 2. [Decisão do `<objective>` sobre o `<behavior>`] `LIVE`, `ORDEM_DA_FILA` e `ctaDoAndamento` em pt-BR

- **Found during:** Task 1
- **Issue:** o `<behavior>` do plano dizia `LIVE` é `['awaiting','ecf_review','changes']` e
  `ctaDoAndamento('failed')`/`abreRevisao('preparing')` — **as chaves do protótipo**. O
  `<objective>` do MESMO plano decide o contrário, e explicitamente: "um vocabulário só, em pt-BR,
  igual ao do banco… as chaves inglesas existem apenas como mapa de conferência".
- **Fix:** tudo em pt-BR; as inglesas só em `DO_PROTOTIPO`, com bijeção provada. O `<behavior>` é a
  redação anterior ao achado S3, e seguir a letra dele recriaria exatamente o bug.
- **Files:** `aprovacao/andamentoDoPortao.jsx`

### 3. [Rule 2 — decisão de projeto estendida] As chaves de BLOQUEIO também viraram pt-BR

- **Found during:** Task 1
- **Issue:** o plano nomeia `ABREVIACAO_DO_BLOQUEIO` (`SHORT`, 9) e `ROTULO_DO_BLOQUEIO` (`BLK`, 8)
  pelas constantes do protótipo, cujas chaves são inglesas (`photos`, `nophoto`, `freight`…). A
  decisão de vocabulário do plano é "chaves em pt-BR nos dois lados".
- **Fix:** **um namespace único de 11 chaves pt-BR** servindo os dois mapas, com
  `BLOQUEIO_DO_PROTOTIPO` traduzindo as 11 do protótipo e teste de bijeção. Os 6 conceitos que
  aparecem nos dois mapas usam **a mesma chave** — há asserção cobrando isso, porque dar nome
  diferente ao mesmo conceito (`frete_pendente` × `frete_ou_custo_ausente`) seria o bug S3 nascendo
  nos bloqueios.
- **⚠️ Para o 186-04/05/09:** o PHP tem de usar **estas** 11 chaves. Elas estão listadas em
  `BLOQUEIO_DO_PROTOTIPO`.
- **Files:** `aprovacao/vocabularioDoPortao.jsx`

### 4. [Fidelidade — o protótipo ganha] A bolinha herda a cor do TEXTO, não `m[4]`

- **Found during:** Task 2
- **Issue:** a tabela do plano mapeia `m[4]` como "corDoDot", e o natural seria pintar a bolinha com
  ela. **Medido:** dentro da pill (`:171`/`:172`) a bolinha e o anel usam `r.stColor`, que é
  **`m[1]`, a cor do TEXTO**. `m[4]` é usada em outro lugar — nos cards da faixa de andamento
  (`:1217`, `dot: m[4]`), que são da Fase 187.
- **Fix:** `bg-current` / `border-current` na forma — mesmo valor computado da cor que a pill
  declara, com uma classe só e sem chance de os dois divergirem. O campo `dot` de `ANDAMENTOS`
  continua existindo, para a faixa.
- **Files:** `aprovacao/SeloDeAndamentoInterno.jsx`

### 5. [Divergência de fidelidade DECLARADA] `pub-spin` é `.7s` e o protótipo é `.8s`

- **Found during:** Task 2
- **Issue:** `Aprovacao.dc.html`:171 declara `animation:spin .8s linear infinite`; o token existente
  `pub-spin` do `tailwind.config.js` é `spin .7s linear infinite`.
- **Fix:** usado o token existente, **como o plano manda** (GATE-09: `duration-[Nms]` não compila
  neste projeto). O gate de CSS **trava o valor real** (`.7s`) e tem controle negativo contra `.8s`,
  para que mudar o token seja um diff visível. 100 ms num anel de 9px não é discernível; quem quiser
  o décimo exato tem de criar um token novo **de propósito**.
- **Files:** `aprovacao/SeloDeAndamentoInterno.jsx`

### 6. [Incoerência interna do plano, registrada] `files_modified` tem 6 caminhos, a `<verification>` fala em 5

- **Found during:** verificação final
- **Issue:** `.planning/phases/186-…/186-02-PLAN.md` lista **6** caminhos em `files_modified`
  (linhas 8-13) e a `<verification>` (linha 390) diz "exatamente os 5 arquivos de `files_modified`".
- **Fix:** nada a corrigir no código — os 5 commits tocam **exatamente os 6** caminhos declarados.
  Registrado para o plano não ser lido como se um arquivo estivesse sobrando.

## ⚠️ Divergências e pendências que o orquestrador precisa decidir

### D1 — `tests/gates/contagens-186-01.txt` ficou com a régua velha (ação NÃO aplicada)

**Medido, com os números exatos:**

| gate do 186-01 | manifesto diz | medido agora | veredicto do harness |
|---|---|---|---|
| `gate-rename` | `tests=55,55` | **58** (`# pass 58 / # fail 0`) | **REPROVA** pela contagem |
| `rename-e-reapontes` | `tests=817,809` | **820** (`# pass 820 / # fail 0`) | **REPROVA** pela contagem |

**Causa, e ela é consequência legítima desta entrega:** o bloco `ABA-04` de
`publicador-rename-em-analise-ml.test.js` tem **um subteste por arquivo varrido**, e `aprovacao/`
saiu de 0 para 3 arquivos. **Zero teste falhando** — só a régua está velha. O 186-01 já previu a
situação: ele deixou `aprovacao/` na lista de diretórios com o comentário *"Nasce no plano 186-02.
Enquanto não existir, vira `skip` NOMEADO"*.

**Por que eu NÃO corrigi:** `tests/gates/contagens-186-01.txt` **não está em `files_modified`** deste
plano. A instrução é parar e devolver a pergunta em vez de improvisar fora do território. **O modo de
falha é ALTO (o gate reprova), não silencioso** — ninguém vai confundir com verde.

**A correção, se autorizada, é de duas linhas** (elevação, com o motivo escrito ao lado):
`gate-rename … tests=55,58` e `rename-e-reapontes … tests=817,820` — mantendo os valores antigos
como aceitos, porque eles são os do regime anterior à criação de `aprovacao/`.

### D2 — Decisão de chaves de bloqueio a confirmar com o 186-04/05

As 11 chaves pt-BR de bloqueio (Deviation 3) foram **definidas por este plano**, que o
`<objective>` nomeia como "o dono do vocabulário do portão — e a camada de tradução é dele". Se o
186-04 preferir as inglesas do protótipo no PHP, a decisão tem de ser **invertida aqui**, e não
resolvida com um `||` aceitando as duas formas — aceitar as duas é exatamente o S3 com outra roupa.

## Known Stubs

Nenhum. Os 3 arquivos são vocabulário e render puros, sem fonte de dado pendente.
`SeloDeAndamentoInterno` **não é componente órfão**: o destino está declarado no cabeçalho dele e
nesta SUMMARY — a coluna Andamento da tabela da fila (FILA-13), Fase 187.

## Threat Flags

Nenhuma superfície de segurança nova. `T-186-04` (DoS pelo render) está mitigada e **medida** pelos
12 casos adversos; `T-186-05` (jargão na tela) pelo gate dos dois eixos e pelas chaves nunca
renderizadas; `T-186-06` (arquivo fora de `ARQUIVOS_NOVOS`) por VOC-07 com mutação; `T-186-SC`
(instalação de pacote) pelo diff vazio em `package.json`/`package-lock.json` no range.

## O que NÃO foi feito, de propósito

- **Nenhuma afirmação de tela renderizada vista.** Não há navegador aqui; o que se mediu é HTML de
  `renderToStaticMarkup` e o CSS construído. **A conferência visual é do usuário, em produção, na
  conta 459.**
- A falha pré-existente de `tests/js/estrutura-grade-glide.test.js:122` **não** foi corrigida.
- `tests/Feature/CompanyPortfolioAccessTest.php` (`??`, de 24/07, de outra sessão) **não** foi
  tocado.
- Nenhum deploy, push, comando no VPS, migration, leitura de produção ou `vendor/bin/pint`.

## Self-Check: PASSED

- Os 3 `.jsx` de `aprovacao/`, os 2 arquivos de teste, o manifesto e esta SUMMARY: **todos FOUND**
  por `test -f`.
- Os 5 commits (`946e7ac7`, `abb55345`, `906575f3`, `00454f13`, `a10a8ffb`): **todos FOUND** em
  `git log --oneline --all`.
- `min_lines` dos artifacts: `andamentoDoPortao.jsx` **370** (mínimo 80) ·
  `vocabularioDoPortao.jsx` **253** (mínimo 70) · `SeloDeAndamentoInterno.jsx` **100** (mínimo 50).
- `key_link` do plano (`DIRETORIOS_FECHADOS` ligando o teste ao diretório novo): presente em
  `tests/js/publicador-vocabulario-novo.test.js:266`.
