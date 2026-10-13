---
phase: 179-visao-geral-cockpit
plan: 04
subsystem: ui
tags: [tailwind, design-tokens, publicador, visao-geral, hachura, fidelidade, mutacao]

requires:
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "a camada `pub-*` em tailwind.config.js (35 cores, font-pub/pub-mono, 8 raios, 6 sombras, pub-spin, ease-pub-painel) e o gate `tests/js/publicador-tokens-do-publicador.test.js` que a importa de verdade"
provides:
  - "`colors.pub['hover-0']` = #121212 — hover da linha do painel de estágio"
  - "`colors.pub['raised-0']` = #131313 — fundo da ação RECOMENDADA de 'O que fazer agora'"
  - "`colors.pub['accent-sunken']` = #1F1B08 — fundo do ícone do evento de publicação na Atividade"
  - "`backgroundImage['pub-hachura']` — a hachura do segmento 'Sem publicação' da barra (VG-07)"
  - "varredura com isca que prova 0 hex de `Visao Geral.dc.html` sem nome na camada (31 de 31 nomeados)"
  - "igualdade de conjunto em `colors.pub` e em `backgroundImage` — token novo não passa mais por omissão"
affects: [179-06, 179-07, 179-08, 179-09, 179-10, 180-publicacoes, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "Tabela de medição LITERAL quando a fonte de verdade não é versionada: o protótipo é `??` no git status, então o gate carrega os 31 hex medidos e usa o arquivo em disco apenas como detector de desatualização — gate que depende só do arquivo não mede nada na máquina do outro dev"
    - "Varredura de órfão com ISCA obrigatória: a mesma função roda contra um hex sintético que TEM de ser reportado órfão, para distinguir 'o código está errado' de 'o medidor está quebrado'"
    - "Igualdade de CONJUNTO ao lado da conferência entrada-por-entrada: a tabela que só itera sobre si mesma aceita chave nova em silêncio e o token novo fica sem gate nenhum"
    - "Prova de INÉRCIA de camada global: roda o Tailwind com o conteúdo REAL duas vezes (com e sem os tokens novos) e compara o CSS por sha256, com controle que muda um token usado para provar que a igualdade não é vazia"
    - "Sonda de CSS pelo motor, não pelo bundle: token que nenhuma tela usa ainda NÃO aparece no CSS construído (JIT), então a prova de 'gera CSS com o valor certo' vem do Tailwind rodado sobre conteúdo sintético"

key-files:
  created: []
  modified:
    - tailwind.config.js
    - tests/js/publicador-tokens-do-publicador.test.js

key-decisions:
  - "Os valores foram MEDIDOS no protótipo, não copiados da tabela do plano — e as 5 referências de linha do plano estavam todas erradas (declaradas abaixo com arquivo:linha)"
  - "A tabela dos 31 hex é literal no gate porque `new_layout_publicador/` NÃO é versionado; o arquivo em disco entra só como detector de desatualização"
  - "Igualdade de conjunto em `colors.pub` acrescentada (Regra 2): o próprio plano nomeia 'passar por omissão' como ameaça T-179-23 mas não a fechava estruturalmente"
  - "`accent-sunken` ganha nome próprio em vez de `accent` com opacidade: no protótipo é tinta SÓLIDA (`IC.pub[0]`), não `rgba(250,204,21,…)`"

patterns-established:
  - "Mutação com contagem travada ANTES de aplicar (recusa se ≠1 no código), linha alterada conferida, restauração por sha256 — e mutação que prova a VARREDURA, não só a tabela"
  - "Controle de não-vacuidade para todo gate de diff: o mesmo range onde se ESPERA arquivos tem de devolver arquivos"

requirements-completed: [VG-07, TOK-04, TOK-15, FID-03, FID-04]

duration: ~50min
completed: 2026-10-12
---

# Fase 179 Plano 04: os 3 últimos hex da Visão Geral e a hachura ganham nome Summary

**Os 31 hex de `Visao Geral.dc.html` passaram a ter 100% de cobertura de token: os três que faltavam
(`#121212`, `#131313`, `#1F1B08`) e a hachura do segmento "Sem publicação" entraram na camada `pub-*`
por adição pura (21 linhas, 0 removidas), provados por sonda de CSS com isca, por 9 mutações e por uma
prova de inércia que mostra o CSS do sistema inteiro byte-idêntico.**

## Performance

- **Duração:** ~50 min
- **Commits:** `fc818f80` (RED), `9e2e2e3e` (GREEN) — este SUMMARY no commit de docs
- **Tarefas:** 2 de 2
- **Arquivos modificados:** 2 (`tailwind.config.js` +21/−0, o gate +242/−0)

## Os quatro tokens

| token | valor | onde o protótipo usa |
|---|---|---|
| `colors.pub['hover-0']` | `#121212` | `Visao Geral.dc.html:137` — `style-hover="background:#121212"` da linha de produto dentro do painel de estágio |
| `colors.pub['raised-0']` | `#131313` | `:516` — `bg: top ? '#131313' : '#111111'`, o fundo da ação **recomendada** (a primeira) de "O que fazer agora" |
| `colors.pub['accent-sunken']` | `#1F1B08` | `:588` — `IC = { pub: ['#1F1B08', …] }`, fundo do ícone circular do evento de **publicação** na Atividade |
| `backgroundImage['pub-hachura']` | `repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)` | `:479` — `barBg: k === 'none' ? …`, a hachura do segmento "Sem publicação" da barra de estágios (VG-07; `none` é rotulado "Sem publicação" em `:465`) |

`raised-0` e `hover-0` nomeiam tons **mais escuros** que `raised` (`#151515`) e `hover` (`#141414`) — o
sufixo `-0` existe para dizer isso. `accent-sunken` é tinta **sólida** no protótipo, não `accent` com
opacidade, e por isso não sai de `bg-pub-accent/[0.x]`.

## A varredura, com o resultado da isca

| medida | resultado |
|---|---|
| hex distintos em `Visao Geral.dc.html` | **31** (`#RRGGBB`; **0** de 3 dígitos, **0** de 8 dígitos — conferido) |
| já tinham nome na camada | **28** |
| ganharam nome neste plano | **3** — `#121212`, `#131313`, `#1F1B08` |
| órfãos depois do plano | **0** |
| **isca `#ABCDEF`** | **reportada como órfã** — a varredura sabe dizer "não" |
| entradas em `colors.pub` | 35 → **38** |

A isca não é decorativa: na **mutação 9** o próprio medidor foi quebrado (`orfaos()` passou a devolver
sempre `[]`) e o gate reprovou com *"a varredura não reportou o hex-isca #ABCDEF como órfão — quem está
quebrado é o medidor, não o config"*. Sem a isca, aquela mutação teria sobrevivido.

As duas cores da hachura (`#3F3F46` e `#262626`) **já tinham nome** (`pub-line-off` e `pub-seg`/`pub-line`);
o que não tinha nome era o gradiente.

## Prova de que as classes GERAM CSS, com o valor certo

⚠️ **O CSS construído não podia provar isso, e isso não é falha:** o JIT do Tailwind só emite a classe
que encontra em `resources/js/**/*.jsx`, e **nenhuma tela usa os 4 tokens ainda** (quem desenha são as
waves 2 e 3). Medido: as 4 classes novas aparecem **0 vezes** em `public/build/assets/app-Cg8qkwwN.css`,
enquanto `.bg-pub-hover{…rgb(20 20 20…)}` e `.bg-pub-surface` **aparecem** — o grep não é vazio por
construção, o bundle é que ainda não tem motivo para conter as novas.

A prova veio de rodar o **Tailwind de verdade** (mesmo `tailwind.config.js`, via `postcss`) sobre
conteúdo sintético e ler o **corpo** de cada regra emitida:

| classe | corpo emitido | veredito |
|---|---|---|
| `bg-pub-hover-0` | `background-color: rgb(18 18 18 / var(--tw-bg-opacity, 1))` | = `#121212` ✅ |
| `bg-pub-raised-0` | `rgb(19 19 19 / …)` | = `#131313` ✅ |
| `bg-pub-accent-sunken` | `rgb(31 27 8 / …)` | = `#1F1B08` ✅ |
| `bg-pub-hachura` | `background-image: repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)` | string inteira ✅ |
| `text-pub-accent-sunken` / `border-pub-raised-0` | `rgb(31 27 8 …)` / `rgb(19 19 19 …)` | o token serve a mais de um utilitário ✅ |

**As 4 iscas saíram ausentes:** `bg-pub-hover-9`, `bg-pub-hachura-xyz`, `z-37`, `bg-pub-naoexiste`.
**Normalizações aplicadas** (as três que fizeram a sonda mentir nesta milestone): cor sai em
`rgb(R G B / var(…))` **decimal** e não em hex; alfa pode sair em hex de 8 dígitos; seletor sai
escapado. **Controle de sensibilidade:** o comparador recusou **2 de 2** valores errados de propósito
(`#999999` para `hover-0`; `45deg` na hachura) — um comparador que sempre dissesse "sim" passaria em
todas as linhas da tabela acima.

**Nenhum token existente mudou de valor** — provado por três caminhos independentes:
1. `git diff --numstat` em `tailwind.config.js`: **21 inserções, 0 remoções** (nenhuma linha existente alterada);
2. a sonda mediu os já existentes e todos emitem o mesmo valor (`bg-pub-hover` → `rgb(20 20 20)`,
   `bg-pub-page` → `rgb(10 10 10)`, `bg-pub-accent` → `rgb(250 204 21)`, `bg-ecf-yellow` →
   `rgb(255 230 0)`, `bg-ecf-grad`, `bg-yellow-grad`, `rounded-pub-card` → `16px`, `shadow-pub-cta`,
   `font-pub` → Geist);
3. **prova de INÉRCIA:** o Tailwind rodado sobre o conteúdo **real** do projeto produz CSS
   **byte-idêntico** com e sem os 4 tokens — `sha256 1198cc36516d1b17`, **249.601 bytes** nos dois
   casos. **Controle:** trocar `pub-surface` (token que as telas usam) por `#AB12CD` **muda** o sha
   (`bfb288de9a431353`), então a igualdade não é vazia por construção.

`npm run build` **verde** (exit 0, 45,6s; as 2 ocorrências de "error" no log são os nomes de arquivo
`ErrorBarContext` e `FormErrorBanner`). Manifest conferido **pelo JSON**: **480 entradas**, **0 sem
`file`**, `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` presente.

## Mutações — 9 aplicadas, 9 reprovaram

Cada uma com a **contagem travada antes** (recusa se ≠1 linha de código; comentário contado separado),
a **linha alterada conferida**, e restauração por **sha256** (`b0c17a41c3be4813…`, idêntico nas 9).

| # | mutação | alvo casou | linha | casos que caíram |
|---|---|---|---|---|
| 1 | `hover-0` → `#141414` (o "parecido que já existe") | 1 código / 0 comentário | L123 | camada inteira + varredura |
| 2 | `raised-0` → `#151515` | 1 / 0 | L120 | camada inteira + varredura |
| 3 | `accent-sunken` → `#1A1A1A` | 1 / 0 | L150 | camada inteira + varredura |
| 4 | hachura: passo `3/6px` → `4/8px` | 1 / 0 | L235 | hachura (VG-07) |
| 5 | hachura: `135deg` → `45deg` (outra hachura) | 1 / 0 | L235 | hachura (VG-07) |
| 6 | chave `pub-hachura` apagada | 1 / 0 | L235 | hachura (VG-07) |
| 7 | `ecf.yellow` → `#ffe601` | 1 / 0 | L93 | **camada `ecf-*` intacta** — o gate antigo ainda morde |
| 8 | `'hover-0'` apagado | 1 / 0 | L123 | camada inteira + **omissão** + varredura, que relatou **exatamente `#121212`** como órfão |
| 9 | o **medidor** quebrado (`orfaos()` → `[]`), no arquivo de teste | 1 / 0 | L288 | varredura, pela **isca** |

**Nenhuma sobreviveu**, então não há lacuna de cobertura a investigar. As mutações 1–3 derrubam **dois**
gates diferentes (a tabela de valores e a varredura), e a 8 derruba **três** — era o ponto do plano.

## Divergências do plano — o protótipo ganhou

⚠️ **As 5 referências de linha da tabela do plano estavam erradas.** A **semântica** descrita conferiu
em todos os casos; só o número da linha não. Medido em `new_layout_publicador/Visao Geral.dc.html`:

| item | plano dizia | medido | o que há na linha que o plano citou |
|---|---|---|---|
| `#121212` | `:135` | **`:137`** | `:135` é um `<span>` com `color:#8A8A90` |
| `#131313` | `:512` | **`:516`** | `:512` é o `.filter(...).sort(...)` das ações |
| `#1F1B08` | `:353` | **`:588`** | `:353` é um label com `color:#71717A` |
| hachura | `:111` / `:504` | **`:479`** (única ocorrência) | `:111` é um `<sc-if>`; `:504` é a ação "pode estar dando prejuízo", sem gradiente |

⚠️ **O plano diz "33 entradas" em `colors.pub`** (no `<interfaces>` e no `<behavior>`). **São 35** —
e `PUB_ESPERADOS` também tinha 35. Depois deste plano: **38**. A frase "todas as 33 entradas que já
existiam seguem com o mesmo valor" foi cumprida sobre as **35** reais.

⚠️ **A `<verification>` 4 e 6 do plano dá FALSO-POSITIVO no range `898d68c2..HEAD`** — é a mesma
armadilha que o `179-03-SUMMARY.md` já registrou. Rodado como escrito, o item 4 acusa **2 asserções
removidas** e 9 linhas removidas em `tests/`, e o item 6 acusa `app`/`resources/js`; tudo em
`tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php` e nos serviços, pelos commits dos planos
**179-02B/179-03**, donos legítimos. No range **deste** plano (`180f7748..HEAD`): **0** asserções
removidas, **0** linhas removidas em `tests/`, `app`/`routes`/`resources/js` **vazios**.

## Desvios (Regras 1–3), todos aditivos

**1. [Regra 2 — funcionalidade crítica ausente] A tabela dos 31 hex é literal, e não só a leitura do arquivo.**
`new_layout_publicador/` **não é versionado** — aparece como `??` no `git status` e **não** há linha
dele no `.gitignore`. Nenhum gate deste projeto lia o protótipo do disco antes (as duas menções
existentes são prosa em comentário). Um gate que dependesse só do arquivo **deixaria de medir qualquer
coisa na máquina do outro dev**, que é a falha "gate vazio por construção" já conhecida aqui. Então: a
tabela literal morde sempre; quando o arquivo existe, o teste *"a tabela de hex do protótipo não
envelheceu"* prova que ela continua sendo o que o arquivo cru diz — conjunto de hex **e** linha da
primeira ocorrência de cada um. **Nesta máquina os dois rodaram.** Se o arquivo faltar, aquele teste
imprime `# … ausente` e segue; o caso de 0 órfãos **não** depende dele.

**2. [Regra 2] Igualdade de CONJUNTO em `colors.pub` e em `backgroundImage`.**
O plano manda acrescentar os 4 tokens a `PUB_ESPERADOS` porque "a tabela aceita chave nova em silêncio"
(ameaça **T-179-23**) — mas isso deixava o fechamento dependendo de disciplina: um token acrescentado ao
config e esquecido na tabela seguiria **sem conferência de valor nenhuma**. O teste novo *"nenhum token
de `colors.pub` passa por omissão"* fecha isso estruturalmente, e a **mutação 8** prova que morde. Mesmo
tratamento para `backgroundImage`, que é camada **compartilhada** com o resto do sistema. **Consequência
para quem vier depois:** token novo em `colors.pub` passa a exigir a entrada na tabela — que é
exatamente o contrato que o cabeçalho do gate já declara ("a lista literal… é o contrato das fases
seguintes").

**3. [Regra 2] `IMAGENS_ESPERADAS` fixa também `ecf-grad` e `yellow-grad`.**
Se o Publicador entra em `backgroundImage`, os dois gradientes do resto do sistema passam a precisar da
mesma proteção que `ECF_INTACTO` dá às cores — senão a camada compartilhada fica meio coberta.

**4. [Regra 2] Guardas de hex de 3 e de 8 dígitos.**
A varredura só conhece `#RRGGBB`. Se o protótipo passasse a usar `#fff` ou `#RRGGBBAA`, ela deixaria de
ver esses hex **em silêncio**. Duas asserções fecham isso (hoje: 0 e 0), com uma terceira provando que a
leitura não veio truncada (`> 60.000 bytes`).

Nenhum desvio de **Regra 4**: nada arquitetural. **Nenhum pacote instalado, nenhuma migration.**

## Para quem desenha — 179-06 / 179-07 / 179-08

As classes já existem e **nenhum de vocês precisa escrever hex**:

| use | para |
|---|---|
| `bg-pub-hover-0` | hover da linha de produto dentro do painel de estágio |
| `bg-pub-raised-0` | fundo da **primeira** ação (a recomendada) de "O que fazer agora" — as outras ficam em `bg-pub-surface` |
| `bg-pub-accent-sunken` | fundo do ícone circular do evento de **publicação** na Atividade (o ícone em si é `text-pub-accent`, a borda é `border-pub-accent/[0.35]`) |
| `bg-pub-hachura` | o segmento **"Sem publicação"** da barra de estágios (VG-07) |

⚠️ **Não** reaproveitem `bg-pub-hover`/`bg-pub-raised` no lugar dos `-0`: a diferença de 2 e 3 pontos é
visível num card de 1600px e "o token parecido que já existe" é justificativa **vetada** (FID-02).
⚠️ A hachura é `bg-pub-hachura` e **não** `bg-[repeating-linear-gradient(...)]` — a forma inline levaria
dois hex literais para dentro do JSX e o gate de vocabulário reprova.
⚠️ **A classe só vai aparecer no CSS depois que um `.jsx` a usar** (JIT por `content`). Se a hachura
"não aparecer" na tela, o primeiro suspeito é a classe montada por concatenação de string, que o scanner
do Tailwind não vê.

## Suítes

| suíte | antes | depois |
|---|---|---|
| gate do arquivo | 6 casos | **10 casos, 10 passam** (RED teve 4 falhando) |
| `npm run test:js` | **3019 / 3018 pass / 1 fail** | **3023 / 3022 pass / 1 fail** |
| PHP (`tests/{Unit,Feature}/Publicador`) | 1981 / 14702 / 0 | **não rodada — provado por diff vazio** |

A única falha do JS é a **pré-existente** `estrutura-grade-glide:123` ("Características secundárias
nasce recolhido"), idêntica antes e depois — **não corrigida**, está fora de escopo.

**PHP não foi tocado**, provado por `git diff --numstat 180f7748..HEAD -- app tests/Unit tests/Feature`
**vazio**, em vez de pagar os 19 minutos da suíte. **Controle:** o mesmo comando no range da fase
devolve **13** arquivos (o PHP dos planos irmãos), então o comando morde. Deprecations: inalteradas —
nenhum PHP novo, nenhum `@group` novo.

## Gates de território

| caminho | range | resultado |
|---|---|---|
| `app/Support/Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`, `Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa`, `database`, `resources/css`, `resources/views` | `898d68c2..HEAD` | **vazios** |
| `app`, `routes`, `resources/js` (donos: planos irmãos) | `180f7748..HEAD` | **vazios** |
| `package.json`, `package-lock.json`, `composer.json`, `composer.lock` | `898d68c2..HEAD` | **vazios nos 4** |
| arquivos deletados | `898d68c2..HEAD` | **0** |
| fixture órfã (`git status --short | grep tests/js`) | — | **0** |

**Os 14 caminhos foram conferidos por `test -e` antes de se acreditar no vazio** — todos existem.
**Controles de não-vacuidade:** o mesmo range em `tailwind.config.js tests/js` devolve **263 inserções
em 2 arquivos**; e `898d68c2..HEAD -- app resources/js` devolve **7 arquivos, 2222 inserções** (o
trabalho dos irmãos), provando que o range acusaria se eu tivesse tocado ali.

## Known Stubs

Nenhum. Este plano não cria componente, rota nem dado — só nomes na camada de estilo.

## Threat Flags

Nenhuma superfície nova: nenhum endpoint, nenhum caminho de auth, nenhum acesso a arquivo, nenhuma
mudança de schema. `T-179-22` (vazamento para fora do módulo) está coberto pela prova de inércia e pela
mutação 7; `T-179-23` (token sem gate) pela igualdade de conjunto e pela mutação 8; `T-179-24` (hex
órfão na tela) pela varredura com isca; `T-179-SC` pelos 4 arquivos de pacote com diff vazio.

## O que NÃO foi feito

- **Nenhuma tela foi vista renderizada** — não há navegador nesta sessão. Toda afirmação visual acima é
  sobre **valor de CSS emitido**, nunca sobre pixel observado.
- **Nada deployado, nada empurrado, nada no VPS.** Leitura de produção segue bloqueada nesta sessão.
- `ROADMAP.md` e `REQUIREMENTS-v26.md` **intactos**; `state.advance-plan` **não executado**.

## Self-Check: PASSED

- **Arquivos:** `tailwind.config.js`, `tests/js/publicador-tokens-do-publicador.test.js`,
  `179-04-SUMMARY.md`, `public/build/assets/app-Cg8qkwwN.css`, `public/build/manifest.json` — todos
  encontrados no disco.
- **Commits:** `fc818f80` (RED) e `9e2e2e3e` (GREEN) encontrados no `git log`; as duas âncoras de range
  (`898d68c2` da fase, `180f7748` do plano anterior) também.
- **Números reafirmados por reconsulta:** `git diff --numstat 898d68c2..HEAD` devolve
  `21 0 tailwind.config.js` e `242 0 tests/js/publicador-tokens-do-publicador.test.js`;
  `'pub-hachura':` presente no config.
