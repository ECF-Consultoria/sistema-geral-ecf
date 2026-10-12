---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 08
subsystem: ui
tags: [publicador, header-global, meus-anuncios, historico, anunciar-massa, sub-abas, render-real, prova-por-mutacao, node-test]

# Grafo de dependências
requires:
  - "177-03: a prop `cabecalho` (`CabecalhoPublicadorService::para()`) já chegava nas 3 páginas pelo `MlbAnuncioController` (`massa()`:237, `historico()`:434, `meus()`:621) — nenhuma linha de PHP foi tocada neste plano"
  - "177-04: a casca do `PublicadorHeader`, o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>` (HEAD-14) e o slot `acoes` na linha 2"
  - "177-05: as 4 abas-pílula, o contador que nunca vira `0` e o literal exato de D23"
  - "177-06: o seletor de empresa (dropdown de 360px) e a pill de conexão (popover de 320px)"
  - "Fase 172: o bloco `role=\"radiogroup\"` de `AbasDaConta.jsx` (88-123), que foi MOVIDO — não reescrito"
provides:
  - "`Components/Mlb/Publicador/SubAbasDePublicacoes.jsx`: as sub-abas No ar | Histórico em componente próprio, com a aparência de hoje e docblock dizendo que a remoção é da Fase 180 (LIST-25)"
  - "As 3 páginas de `/mlb/anuncios` com `PublicadorHeader` como primeiro filho do `AppLayout` e corpo intacto"
  - "`tests/js/publicador-paginas-anuncios-render.test.js`: 53 testes de render REAL das três páginas com `cabecalho` completo/nulo/ausente/objeto/string"
affects: [177-09, 178, 180, 181]

# Rastreio técnico
tech-stack:
  added: []   # T-177-SC: nenhum pacote instalado
  patterns:
    - "Componente movido com docblock de DATA DE VALIDADE: diz em que fase deve ser APAGADO e por que não foi redesenhado"
    - "Gate de aparência por igualdade da STRING INTEIRA do `className`, não por checklist de classes — checklist deixou passar `gap-1`→`gap-2`"
    - "Render real da PÁGINA (não do componente) para provar POSIÇÃO: header é o 1º filho do `AppLayout`, radiogroup está depois do `</header>`"
    - "Stub de esbuild para a grade em canvas (`@glideapps/glide-data-grid` importa CSS no topo do módulo e não roda em bundle ESM de Node)"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx
    - tests/js/publicador-paginas-anuncios-render.test.js
    - .planning/phases/177-tokens-fonte-geist-e-o-publicadorheader-global/deferred-items.md
  modified:
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - resources/js/Pages/Mlb/AnunciosHistorico.jsx
    - resources/js/Pages/Mlb/AnunciarMassa.jsx
    - tests/js/estrutura-meus-anuncios.test.js
    - tests/js/estrutura-anuncios-historico.test.js
    - tests/js/estrutura-anunciar-massa.test.js

key-decisions:
  - "NENHUMA asserção de gate precisou sair — as 6 de aparência do `estrutura-meus-anuncios.test.js` passaram nos TRÊS arquivos a que o helper se aplica, porque o corpo das 3 páginas ficou intacto (HEAD-15). Confirmado por EXECUÇÃO, não por leitura"
  - "`font-pub` NÃO foi aplicado ao contêiner do corpo das 3 páginas — decisão contrária a uma instrução recebida, com 3 provas no repositório. Ver a seção própria abaixo; é a única pergunta que devolvo"
  - "As sub-abas No ar | Histórico foram movidas com a aparência de HOJE (classes `ecf-*`), e o arquivo NÃO entra em `ARQUIVOS_NOVOS` do gate de vocabulário novo: é corpo antigo com remoção já autorizada para a Fase 180"
  - "T-177-31 conferido na FONTE do pacote, não por suposição: a grade desenha num `<canvas tabIndex={0}>` com a árvore `<table role=\"grid\">` DENTRO dele — `ignoraAtalho()` já cobria os dois alvos. Nenhum seletor novo"
  - "O gate de aparência virou igualdade da string inteira do `className` depois que a checklist de classes deixou passar `gap-1`→`gap-2`"

patterns-established:
  - "Prova por mutação em 4 rodadas: 50 mutações nos arquivos REAIS + 2 controles; 2 furos encontrados e fechados; restauração conferida por sha256"
  - "Mutação cujo alvo é string presente também em COMENTÁRIO mede a coisa errada: `String.replace` troca só a 1ª ocorrência, e o comentário vem antes do código"

requirements-completed: [HEAD-11, HEAD-14, HEAD-15, GATE-01, GATE-02, GATE-04, GATE-05, GATE-06, REG-01, REG-02, REG-05, FID-01, FID-02]

# Métricas
duration: ~75min
completed: 2026-10-12
---

# Fase 177 Plano 08: o header novo nas 3 páginas de `/mlb/anuncios` — Summary

**`MeusAnuncios`, `AnunciosHistorico` e `AnunciarMassa` passaram a montar o `PublicadorHeader` como primeiro filho do `AppLayout`, com o corpo byte a byte intacto; as sub-abas No ar | Histórico desceram para um componente próprio com a aparência de hoje e um docblock que diz em que fase devem ser APAGADAS; "Atualizar agora" e "‹ Voltar aos produtos" continuam nos seus slots; D-11, D-22 e D23 não foram tocados; e nenhuma das 6 asserções de aparência precisou sair — o que foi confirmado rodando, não lendo.**

⚠️ **Não vi tela renderizada.** O que verifiquei é HTML de `react-dom/server` e fonte. A conferência visual é do usuário, em produção, e é do plano 177-09.

## Performance

- **Duração:** ~75 min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 3 criados, 6 modificados
- **Commits:** `e919344d` (Task 1), `a0115de5` (Task 2), `5b6007d6` (Task 3) + o desta SUMMARY
- **Linhas no meu range (`93b33525..HEAD`):** 776 adições, 52 remoções, **0 arquivos deletados**

## Baselines — medidas por mim, hoje, antes e depois

| Suíte | Antes (medido agora) | Depois | Leitura |
|---|---|---|---|
| JS inteiro (`npm run test:js`) | **2631 · 2630 pass · 1 fail** | **2752 · 2751 pass · 1 fail** | +121 testes, dos quais **+64 são meus** (53 do render novo + 11 asserções de fonte). O resto é da wave **177-07**, que roda em paralelo nesta mesma árvore |
| Os 6 gates deste plano | — | **163 · 163 pass · 0 fail** | — |
| `tests/Feature/Phase134` + `Phase135` | **280 · 0 fail** | **280 · 0 fail** | idêntico; nenhuma linha de PHP foi tocada |

O número de partida (2631) bate exatamente com o que a wave 5 deixou. **O único fail é o mesmo
pré-existente** `tests/js/estrutura-grade-glide.test.js` → *"Características secundárias nasce
recolhido (é o grupo que mais infla)"*, de outro território e de outra fase — **não corrigido de
propósito**.

## Inventário REG-01 — as 3 páginas, antes e depois

Re-medido com `grep -rn '<BarraDaConta\|<AbasDaConta' resources/js/` (quem **monta**, não quem
menciona) — foi assim que a contagem de 9 páginas se confirmou: estas 3 + as 6 de
`Pages/Mlb/Publicador/` (wave 177-07).

| Página | Hoje (antes) | Depois |
|---|---|---|
| `MeusAnuncios.jsx` | `<BarraDaConta empresa={conta} acoes={<BotaoAtualizar …>} />` · `<AbasDaConta aba="publicacoes" conta={conta?.chave} companyId={empresa.id} subPublicacoes="meus" />` | `<PublicadorHeader aba="publicacoes" cabecalho={cabecalho ?? null} acoes={<BotaoAtualizar …>} />` (1º filho do `AppLayout`) + `<SubAbasDePublicacoes atual="meus" companyId={empresa.id} />` no topo do corpo |
| `AnunciosHistorico.jsx` | `<BarraDaConta empresa={conta} />` · `<AbasDaConta aba="publicacoes" … subPublicacoes="historico" />` | `<PublicadorHeader aba="publicacoes" cabecalho={cabecalho ?? null} />` (sem `acoes`) + `<SubAbasDePublicacoes atual="historico" companyId={empresa.id} />` |
| `AnunciarMassa.jsx` | `<BarraDaConta empresa={conta} />` · `<div className="mb-4"><AbasDaConta aba="produtos" … /> + Link "‹ Voltar aos produtos"</div>` | `<PublicadorHeader aba="produtos" cabecalho={cabecalho ?? null} acoes={o MESMO Link} />`; a `<div className="mb-4">` saiu junto com as abas |

**Páginas que NÃO entraram na troca, e por quê (re-medido, não suposto):**

| Página | Monta `BarraDaConta`/`AbasDaConta`? | Diff no range do plano |
|---|---|---|
| `Pages/Mlb/AnunciarML.jsx` (assistente antigo) | **Não** — só tem o comentário dizendo que não usa (`:2015`) e a trilha própria dele | `git diff --stat b36037f9..HEAD -- …/AnunciarML.jsx` → **vazio** ✔ |
| `Pages/Mlb/Publicador/Tarefas.jsx` | **Não** — tela GLOBAL, de todas as contas (`:14` "sem BarraDaConta"); não há conta para o header exibir | mesmo comando → **vazio** ✔ |

## Cirurgia nos gates — por asserção, medida por EXECUÇÃO

### Resultado: nenhuma asserção precisou sair. Nenhuma.

`node --test` nos 4 arquivos do plano, logo depois da troca do topo (Task 2, antes de eu acrescentar
qualquer asserção nova): **61 · 61 pass · 0 fail**. Não houve o que classificar em
*(a) reapontar · (b) remover · (c) corrigir* porque **nada falhou**.

### As 6 asserções de aparência de `estrutura-meus-anuncios.test.js`

O helper `travaVocabularioVisual()` aplica as seis a **três** arquivos: `MeusAnuncios.jsx`,
`RascunhosPainel.jsx` e `ModalDetalheAnuncio.jsx` — **18 testes**, todos verdes antes e depois.

| # | Asserção | Arquivos | Resultado | Classificação |
|---|---|---|---|---|
| 1 | `doesNotMatch(/\btext-xs\b/)` | os 3 | passou | **nenhuma ação** |
| 2 | `doesNotMatch(/text-\[1[023]px\]/)` | os 3 | passou | **nenhuma ação** |
| 3 | `match(/text-\[11px\]/)` + `match(/text-sm\b/)` + `match(/text-base\b/)` | os 3 | passou | **nenhuma ação** |
| 4 | `doesNotMatch(/font-medium/)` | os 3 | passou | **nenhuma ação** |
| 5 | `doesNotMatch(/font-bold/)` | os 3 | passou | **nenhuma ação** |
| 6 | `doesNotMatch(/…-\d+\.5\b/)` (meio-passo) | os 3 | passou | **nenhuma ação** |

**Por que passaram, e por que isso era o esperado:** GATE-02 manda remover asserção de aparência
antiga **só dos arquivos redesenhados**, e nesta fase **nenhum dos três é redesenhado** — o corpo
deles é das Fases 180/181 (HEAD-15). O único elemento novo que entrou nas 3 páginas é o
`<PublicadorHeader>`, que vive em **outro arquivo**; e o `className` do slot de ações é o do
`BotaoAtualizar`, que **não mudou uma letra**. O que SAIU das páginas (`<BarraDaConta>`,
`<AbasDaConta>` e o `<div className="mb-4">` do `AnunciarMassa`) não tinha nenhuma das classes
proibidas nem era a única fonte de `text-[11px]`/`text-sm`/`text-base`.

⚠️ `RascunhosPainel.jsx` e `ModalDetalheAnuncio.jsx` **não foram tocados** por este plano — e nem
precisaram ser: as 12 asserções deles continuam verdes.

### D-11 e D-22 — a prova de que ficaram

Nenhuma das duas foi tocada. `git diff 93b33525..HEAD -- tests/js/estrutura-meus-anuncios.test.js`
mostra apenas **adições** no fim do arquivo (e uma troca de uma asserção de aparência? **não** — ver
abaixo as 2 únicas alterações de linha existente deste plano, nenhuma delas em D-11/D-22).

| Asserção | Texto no arquivo | Estado | Mutação que a exercita |
|---|---|---|---|
| D-11 | `doesNotMatch(fonte, /router\.put\(/)` | intacta | `router.post(route(` → `router.put(route(` → **reprovada** |
| D-11 | `doesNotMatch(fonte, /router\.delete\(/)` | intacta | — |
| D-11 | `doesNotMatch(fonte, /router\.patch\(/)` | intacta | `router.post(route(` → `router.patch(route(` → **reprovada** |
| D-11 | `strictEqual(chamadasPost.length, 1)` + `match(/router\.post\(route\('mlb\.anuncios\.meus\.atualizar'/)` | intacta | as duas mutações acima a derrubam |
| D-22 | `match(fonte, /de 86/)` | intacta | `de 86` → `de 100` → **reprovada** |
| D-22 | `doesNotMatch(fonte, /\/ 100\b/)` | intacta | — |
| D-22 | `doesNotMatch(fonte, /\* 100 \/ 86/)` | intacta | — |
| D23 + rotas das abas | o literal `Disponível só para empresas cadastradas no sistema` e as 4 rotas de `ModoAnuncioTabs.jsx` | intactas | — (território de `ModoAnuncioTabs.jsx`, não tocado) |

### As 2 únicas alterações de linha existente (tudo o mais é adição)

| Arquivo · asserção | Classificação | Justificativa individual |
|---|---|---|
| `estrutura-anuncios-historico.test.js` → `assert.match(fonte, /grupos\.links/)` virou `/grupos\.links\b/` | **(c) corrigir o gate** (não o código) | Furo **PRÉ-EXISTENTE**, de 173-05, medido na minha prova por mutação: `grupos.linksQualquerCoisa` passava — a asserção estava quase vazia. Um caractere, no arquivo que eu já tinha aberto. **Não é regressão do 177-08** e está registrado em `deferred-items.md`. |
| `estrutura-meus-anuncios.test.js` → a asserção de aparência do componente movido deixou de ser **checklist de classes** e passou a ser **igualdade da string inteira** dos 4 `className` | **(c) corrigir o gate** | A checklist (`bg-ecf-card`, `text-[13px]`, `rounded-md`…) deixou passar `gap-1` → `gap-2` no trilho do segmentado: nenhuma das classes conferidas havia saído, e o gate não viu. Teste que para de provar algo é pior que teste ausente. Esta asserção é **nova neste plano**, então não houve perda de cobertura — ela nasceu fraca e foi endurecida antes do commit. |

### Asserções novas (adições)

**`estrutura-meus-anuncios.test.js`** (+5 testes) — é aqui que mora o gate da tela de Publicações, o
mesmo que guarda D-11/D-22; declarei este como "o lugar certo" para a asserção que o plano pediu:
1. a página monta `<PublicadorHeader>`, com `aba="publicacoes"` e `cabecalho={cabecalho ?? null}`, e
   **não** monta mais `<BarraDaConta>`/`<AbasDaConta>`;
2. `acoes` tem o MESMO `<BotaoAtualizar atualizando cooldown onClick={atualizarAgora} />` de antes;
3. **as sub-abas continuam montadas nas DUAS páginas** — `atual="meus"` aqui e `atual="historico"`
   em `AnunciosHistorico.jsx` (é a asserção que o plano pediu nominalmente);
4. `SubAbasDePublicacoes.jsx` mantém as **duas rotas** (`mlb.anuncios.meus` **e**
   `mlb.anuncios.historico`), o `role="radiogroup"`, os dois `role="radio"` saindo do mesmo helper e
   o par rótulo↔rota de cada uma (REG-05);
5. o componente continua com a **aparência de hoje** e **nenhum** token `pub-*`/`font-pub` (FID-02);
6. as sub-abas **Publicados | Rascunhos** (D-14) não foram tocadas — `SUBABAS`, as duas chaves e o
   `trocarSubAba`.

**`estrutura-anuncios-historico.test.js`** (+3): header montado com `cabecalho ?? null`; a página
continua **sem** `acoes=` (passar um slot vazio deixaria uma caixa órfã na linha 2); sub-abas
montadas com `historico` marcada.

**`estrutura-anunciar-massa.test.js`** (+3): header montado com `aba="produtos"`; o
"‹ Voltar aos produtos" está **dentro do bloco do `acoes`** com o destino
`route('mlb.anuncios.publicador.produtos', { conta: conta.chave })`; e os dois seletores da guarda do
atalho (`canvas` e `[role="grid"]`) continuam em `atalhosDoHeader.js` (T-177-31).

**`publicador-paginas-anuncios-render.test.js`** (NOVO, 53 testes) — **adição ao plano**, pelo
motivo de Regra 2 (verificação crítica ausente): o threat T-177-35 cobre as 3 páginas, mas o único
render real que existia (`publicador-meus-anuncios-sku-render.test.js`) monta **só**
`MeusAnuncios`, e só dois sub-componentes dele. Este arquivo renderiza **as três páginas inteiras**.

**`publicador-meus-anuncios-sku-render.test.js`** estava na lista `files_modified` do plano e
**não precisou de uma linha**: os 11 testes dele passaram sem toque, já provando que o bundle da
página com o header novo compila e que `CelulaSku`/`VazioDaListagem` seguem intactos.

## Prova por mutação — 50 mutações nos arquivos reais, 2 furos fechados

Quatro rodadas, sempre nos arquivos **reais**, com restauração de cópia pristina e conferência por
**sha256** (`OK` nos 4 arquivos em todas as rodadas). Dois **controles** (arquivo sem mudança) para
provar que o conjunto de gates passa quando deve passar.

| Rodada | Mutações | Reprovaram | Observação |
|---|---|---|---|
| 1 | 31 | 27 | 3 "furos" + 1 alvo não encontrado — **3 deles eram MUTAÇÃO ruim, não gate cego** (ver abaixo) |
| 2 | 10 + 1 controle | 9 | alvos corrigidos; 1 alvo ainda errado e 1 mutação parcial |
| 3 | 3 | 2 | **1 furo REAL**: `gap-1` → `gap-2` no trilho das sub-abas |
| 4 | 6 + 1 controle | 6 | depois do gate endurecido: todas reprovaram |

### A lição da rodada 1: alvo em comentário mede a coisa errada

Três "furos" da rodada 1 eram defeito **da minha mutação**:

| "Furo" | O que realmente aconteceu |
|---|---|
| `role="radiogroup"` → `role="group"` | a **1ª ocorrência** da string está no COMENTÁRIO do topo do arquivo ("bloco `role=\"radiogroup\"`, linhas 88-123"); `String.replace` troca só a primeira, então o CÓDIGO ficou intacto |
| `text-[13px]` → `text-[13.5px]` | idem — o comentário lista as classes preservadas |
| `grupos.links` → `grupos.linksX` | o arquivo tem **duas** ocorrências (`grupos.links?.length` e `grupos.links.map`); a troca parcial deixou a outra casando |

Com os alvos no código, as três **reprovaram**. (E a terceira revelou, de lambuja, que a asserção
original estava fraca de verdade: foi endurecida com `\b`.)

### O único furo REAL de gate: a checklist de classes

`gap-1` → `gap-2` no trilho do segmentado **passou**. A asserção conferia uma lista de classes, e
nenhuma das listadas havia saído. Virou **igualdade da string inteira** dos quatro `className`,
copiados de `AbasDaConta.jsx` menos o `mt-2`. Depois disso, as 6 mutações da rodada 4 reprovaram:
`gap-1`→`gap-2` · `p-1`→`p-2` · `ring-ecf-yellow`→`ring-pub-accent` · `text-white/70`→`text-white/60`
· `rounded-lg`→`rounded-xl` · `font-normal`→`font-medium`.

### Amostra do que reprova hoje

sub-abas removidas da tela · sub-aba marcada errada · rota do Histórico apontada para "No ar" ·
rótulo "No ar" renomeado · radiogroup→group · radios sem `role` · as duas marcadas ao mesmo tempo ·
guarda de Company removida · qualquer harmonização das 4 strings de classe · header movido **para
dentro** do container do corpo · `aba` ativa errada nas 3 · `cabecalho={cabecalho}` sem o `?? null` ·
"Atualizar agora" perdido · slot com botão mudo · breakpoints do corpo alterados nas duas páginas ·
`router.put`/`router.patch` (D-11) · "de 86"→"de 100" (D-22) · Publicados/Rascunhos (D-14) removidos
· destino do "Voltar aos produtos" inventado · rótulo do "Voltar" removido · paginação por lote
quebrada.

## Tabela FID-01 — a conferência desta troca

| # | Dimensão | Protótipo | Implementado (conferido por render real) |
|---|---|---|---|
| 1 | largura | header encosta nas bordas; conteúdo 1600px / 36px | `-mx-6` + `max-w-[1600px] px-[36px]` **na tag do `<header>`** (asserção por classe terminada em espaço/aspas — `top-0` é prefixo de `top-0.5`); corpo mantém `max-w-[1600px] px-4 sm:px-6 lg:px-8` nas 3 |
| 2 | altura | 60 + 48 | `h-[60px]` + `h-[48px]` (travados em 177-04/05; aqui só herdados) |
| 3 | grid | header acima do conteúdo | **1º filho do `AppLayout`**: a saída casa `/^<main data-titulo="[^"]*"><header /` e tem **exatamente um** `<header` |
| 4 | spacing | header colado no topo | `-mt-6` + `sticky top-0` na tag do header; `</header>` seguido IMEDIATAMENTE do container do corpo |
| 20 | densidade / CTAs | ações da página à direita da linha 2 | "Atualizar agora" e "‹ Voltar aos produtos" **dentro da `<nav>` do header** (HTML partido: `<nav aria-label="Abas da conta">…</nav>`), sem mudar os componentes; `AnunciosHistorico` **sem** o `<div>` do slot |

### Divergências declaradas (FID-02)

| Item | Hoje / Protótipo | Implementado | Restrição concreta |
|---|---|---|---|
| Sub-abas **No ar \| Histórico** | não existem no protótipo | movidas para o corpo, **com a aparência de hoje** (4 strings de `className` iguais às de `AbasDaConta`, menos o `mt-2`) | A remoção é autorizada só na **Fase 180** (LIST-25), com a guarda *o atalho sai, o dado não*: a 180 tem de provar que o filtro de status alcança o que "Histórico" alcança, e **parar e perguntar** se não alcançar. Redesenhá-las agora seria trabalho para algo que vai ser apagado; harmonizá-las seria FID-02 sem restrição. |
| Trilha `Publicador › Programa › Nome` | existe hoje; não existe no protótipo | **removida** — a única remoção de elemento deste plano | Marca "Publicador" + seletor de empresa do header cumprem a função (onde estou, como troco de conta), e o programa aparece no dropdown. Mesma justificativa da wave 177-07. |
| `mt-2` do radiogroup | existia em `AbasDaConta` | não veio | Era espaçamento EXTERNO, para separá-lo da `<nav>` logo acima dele. Quem posiciona agora é a página — o componente não sabe mais o que vem antes dele. |
| Corpo das 3 páginas, inclusive `sm:`/`lg:` | protótipo é desktop-first sem breakpoint | **intocado** | HEAD-15. O corpo de Publicações é das Fases 180/181. FID-05 ("nenhum breakpoint novo") vale para as telas redesenhadas. |
| "Atualizar agora" em dois lugares | — | no slot de ações **e** no rodapé do popover de conexão (e em 3 pontos do corpo) | Todos chamam a MESMA rota, que **enfileira** (D-05). Não é duplicação de regra, são atalhos para a mesma ação; o da página sai quando a Fase 180 redesenhar o corpo. |
| `font-pub` no corpo | — | **não aplicado** | Ver a seção abaixo. |

## ⚠️ `font-pub` no contêiner do corpo — decidi NÃO aplicar, e devolvo a pergunta

Recebi, como obrigatório, a instrução *"o contêiner da tela tem de aplicar `font-pub`, porque nem o
header nem os componentes de identidade declaram família"*. **Não apliquei**, e o motivo é que a
premissa está desatualizada. Três provas no repositório:

1. **O header JÁ declara a família.** `PublicadorHeader.jsx` tem `font-pub` na tag do `<header>`, e
   `publicador-header-render.test.js` trava a contagem em **exatamente 1** ocorrência. A família
   cobre toda a subárvore do header, inclusive os 7 componentes de identidade. A fidelidade de fonte
   do header **não depende da página**.
2. **O gate de tokens diz o contrário, por escrito.** `publicador-tokens-do-publicador.test.js`:
   *"O corpo da página segue em Inter: a Geist é opt-in por `font-pub`"*, com
   `<body class="font-sans antialiased">` travado. TOK-01 diz "ao lado de Inter/Manrope, **sem
   trocar o `sans` global**".
3. **HEAD-15 e o próprio plano proíbem.** O plano 177-08 diz "**Nenhum `className` do corpo é
   tocado**"; HEAD-15 diz que header novo com corpo antigo é estado "aceito e declarado" e que
   "**não autoriza harmonizar**". E o roteiro de conferência do 177-09 descreve a mudança de fonte
   como visível **no topo** ("a fonte do módulo mudou (Geist) — o texto fica visivelmente diferente
   do resto do sistema"), não na página inteira.

Some-se o fato concreto: **nestas 3 páginas não entrou nada do design system novo no corpo**. O
único elemento que desceu é `SubAbasDePublicacoes`, que é corpo antigo de propósito. Aplicar
`font-pub` ao container trocaria a fonte de três telas em produção — métrica de glifo diferente,
quebra de linha diferente em tabela — **sem nenhum elemento novo para servir**.

➡️ **Se a decisão for aplicar de todo jeito, é uma classe em três `<div>`** (os containers em
`MeusAnuncios.jsx:369`, `AnunciosHistorico.jsx:284`, `AnunciarMassa.jsx:673`), e eu faço na hora.
**Não decidi pelo usuário: estou devolvendo a pergunta.**

## T-177-31 — o atalho "N" e a grade em canvas, conferido na fonte do pacote

O plano mandava "conferir na prática" se `ignoraAtalho()` cobre o alvo que a grade entrega.
Conferido em `node_modules/@glideapps/glide-data-grid/dist/esm/internal/data-grid/data-grid.js`:

- o elemento focável é `<canvas data-testid="data-grid-canvas" tabIndex={0} onKeyDown=…>` (`:1156`);
- a árvore de acessibilidade é `<table role="grid">` (`:1054`) renderizada **DENTRO desse canvas**,
  com `<td>`/`<th>` focáveis.

Os dois alvos possíveis de um `keydown` na grade, portanto:

| Alvo | Casa o seletor? | Por quê |
|---|---|---|
| o próprio `<canvas>` | **sim** | `closest()` casa também o PRÓPRIO elemento; e `tagName === 'CANVAS'` não é preciso para isso |
| um `<td>` da árvore | **sim** | `closest('[role="grid"]')` acha a `<table>`, e `closest('canvas')` acha o canvas acima dela |

**Nenhum seletor novo foi preciso** e `atalhosDoHeader.js` **não foi alterado** (é arquivo do
`header/`, fora do meu escopo). A conta está escrita no comentário de `AnunciarMassa.jsx` e os dois
seletores estão travados por asserção em `estrutura-anunciar-massa.test.js`.

## As sub-abas continuam existindo — a prova

| Prova | Onde | O que mostra |
|---|---|---|
| fonte | `estrutura-meus-anuncios.test.js` | `<SubAbasDePublicacoes atual="meus" …>` em `MeusAnuncios.jsx` **e** `atual="historico"` em `AnunciosHistorico.jsx`; as **duas rotas** no componente |
| render real | `publicador-paginas-anuncios-render.test.js` | nas duas páginas sai **1** `role="radiogroup"`, com **exatamente 2** `role="radio"`, os rótulos "No ar" e "Histórico", `aria-checked="true"` no certo e `"false"` no outro — e o radiogroup está **depois do `</header>`**, nunca dentro dele |
| rotas | REG-05 | nenhuma rota removida: `mlb.anuncios.meus` e `mlb.anuncios.historico` seguem sendo os dois destinos |
| componente antigo | `publicador-barra-abas-render.test.js` **37 · 37 pass** | `AbasDaConta.jsx` segue INTACTO com o bloco dele, e o teste que confere "No ar"/"Histórico" lá dentro continua verde |

E o par **Publicados | Rascunhos** (D-14, outro par, dentro da própria página) continua onde estava:
provado por fonte (`SUBABAS`, as duas chaves, `trocarSubAba`) e por render (os rótulos e os
contadores `(3)`/`(1)` do `subTotais` saem no corpo).

## Onde o `font-pub` foi aplicado

**Em nenhum arquivo deste plano.** Ele continua declarado **uma vez**, na tag do `<header>` de
`PublicadorHeader.jsx` (plano 177-04) — e os 3 arquivos de página deste plano **não** o têm, de
propósito. `SubAbasDePublicacoes.jsx` tem uma asserção que **proíbe** `font-pub` e qualquer token
`pub-*` nele: é corpo antigo, segue em Inter. Ver a seção da pergunta devolvida acima.

## O que NÃO foi acrescentado ao gate de vocabulário novo (e por quê)

`SubAbasDePublicacoes.jsx` **não** entrou em `ARQUIVOS_NOVOS` de
`publicador-vocabulario-novo.test.js`, por instrução explícita do plano: ele é um pedaço do **corpo
antigo**, e o gate de vocabulário novo (que proíbe `ecf-*`, `text-sm`, `border-white/[0.08]`)
reprovaria justamente o que aqui é **obrigatório**. O arquivo também não foi tocado para evitar
colisão com a wave 177-07, que o está editando em paralelo nesta mesma árvore.

Nenhum outro arquivo novo foi criado em `resources/js/`, então não há mais nada a acrescentar
àquela lista.

## Verificação executada

| # | Verificação | Resultado |
|---|---|---|
| 1 | `npm run test:js` | **2752 · 2751 pass · 1 fail** (o mesmo fail pré-existente) |
| 2 | `node --test` nos 6 gates (os 4 do plano + o render novo + `publicador-barra-abas-render`) | **163 · 163 pass · 0 fail** |
| 3 | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase134 tests/Feature/Phase135` | **280 · 0 fail** (idêntico ao antes) |
| 4 | `grep -rn "import BarraDaConta\|import AbasDaConta" resources/js/Pages/Mlb/*.jsx` | **nenhum** (exit 1) |
| 5 | `git diff --stat b36037f9..HEAD -- resources/js/Pages/Mlb/AnunciarML.jsx resources/js/Pages/Mlb/Publicador/Tarefas.jsx` | **vazio** |
| 6 | `git diff --stat b36037f9..HEAD -- resources/js/Layouts/AppLayout.jsx` | **vazio** (D2/HEAD-14) |
| 7 | `git diff --stat b36037f9..HEAD -- database app/Support/Publicador resources/js/Layouts/AppLayout.jsx` | **vazio** |
| 8 | `git diff --name-only 93b33525..HEAD -- app routes database tests/Feature tailwind.config.js resources/views/app.blade.php resources/js/Components/Mlb/Publicador/{identidade,header} resources/js/Components/Publicador/Mesa` | **vazio** — meus 3 commits só tocam os 8 arquivos do plano (+ a SUMMARY e o `deferred-items.md`) |
| 9 | `git diff --diff-filter=D --name-only 93b33525..HEAD` | **vazio** — nenhum arquivo deletado |
| 10 | `grep -n "sticky" nas 3 páginas` | **nenhum** — nenhuma precisou de `top-[108px]` |
| 11 | `git status --short \| grep tests/js` | nenhuma fixture órfã (`.mjs`/stub) sobrou da prova por mutação |
| 12 | `npm run build` | **não rodado** — é do plano 177-09 |

## Deferred Issues

Registrados em `.planning/phases/177-…/deferred-items.md`:

1. `iniciais()` morto no escopo do módulo em `AnunciarMassa.jsx` (~:39) — **já era morto antes**
   da troca (conferido em `git show HEAD:…`), fora do escopo e não causado por este plano.
2. O furo pré-existente de `grupos.links` (173-05), que eu **corrigi** porque o arquivo já estava
   aberto e a correção é de um caractere — registrado lá para não ser lido como regressão minha.

## Para o próximo plano

**177-09 (build + conferência em produção):**
- As 9 páginas estão trocadas (6 da wave 177-07 + estas 3). `npm run build` é seu.
- Na conta 459, conferir que as sub-abas **No ar | Histórico** aparecem **abaixo** do header em
  Publicações e no Histórico, com a aparência antiga — é o estado declarado, não defeito.
- A pergunta do `font-pub` no corpo (seção acima) precisa de resposta antes da conferência visual:
  se a resposta for "aplicar", a fonte das 3 telas muda e o que você vai olhar é outra coisa.

**Fase 180 (corpo de Publicações):**
- `SubAbasDePublicacoes.jsx` é para **DELETAR**, não migrar nem redesenhar — o docblock dele diz
  isso, com a data e a referência ao LIST-25.
- A guarda do usuário é *o atalho sai, o dado não*: provar que o filtro de status alcança o que
  "Histórico" alcança, e **parar e perguntar** se não alcançar.
- Ao deletar, saem junto: o import e o uso nas 2 páginas, e os 3 testes de sub-abas (2 em
  `estrutura-meus-anuncios.test.js`, 1 em `estrutura-anuncios-historico.test.js`, mais o bloco do
  render). **A asserção das 4 strings de `className` morre com o arquivo** — não reaproveitar.
- `BarraDaConta.jsx` e `AbasDaConta.jsx` continuam no repositório, intactos e com os testes verdes.
  A limpeza deles é varredura separada, com a prova de que nenhuma página os monta (hoje:
  `grep -rn '<BarraDaConta\|<AbasDaConta' resources/js/` → as 6 da wave 177-07, se ainda houver).

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx` — FOUND (100 linhas)
- `tests/js/publicador-paginas-anuncios-render.test.js` — FOUND (402 linhas, 53 testes)
- `.planning/phases/177-…/deferred-items.md` — FOUND
- `resources/js/Pages/Mlb/MeusAnuncios.jsx` · `AnunciosHistorico.jsx` · `AnunciarMassa.jsx` — FOUND, modificados
- `tests/js/estrutura-meus-anuncios.test.js` · `estrutura-anuncios-historico.test.js` · `estrutura-anunciar-massa.test.js` — FOUND, modificados
- Commits `e919344d`, `a0115de5`, `5b6007d6` — FOUND em `git log`
