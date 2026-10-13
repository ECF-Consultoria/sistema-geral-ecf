---
phase: 180-publicacoes-lista
plan: 05
subsystem: ui
tags: [publicador, publicacoes, cabecalho, faixa-de-sucesso, fidelidade, render-adverso, mutacao, sonda-css]

requires:
  - phase: 180-publicacoes-lista
    plan: 01
    provides: "os 5 tokens novos de `colors.pub` e o `15.5` em `TAMANHOS_PERMITIDOS`; deste plano usei `pub-row-new` (já existia) e **`pub-hover-toast`, que nasceu no 180-01 e cujo PRIMEIRO uso é este arquivo** (o CSS tinha 0 regra para ele antes de hoje)"
  - phase: 172-publicador-etapa-1-navegacao
    provides: "`textoUtil`/`textoSeguro` em `Components/Mlb/Publicador/BarraDaConta` — uma regra, uma casa"
  - phase: 179-visao-geral-cockpit
    provides: "o molde do harness de render real (esbuild + `react-dom/server`, bundles antes do primeiro `test()`) e o padrão de SVG inline com o viewBox do protótipo"
provides:
  - "`publicacoes/CabecalhoDePublicacoes.jsx` — H1 + subtítulo + '● Sincronizado há X' + slot `acaoAtualizar`, mais o export nomeado `BotaoAtualizarDoCabecalho` (geometria do protótipo, destino da página)"
  - "`publicacoes/FaixaDeSucesso.jsx` — a faixa `#14120A` com as duas linhas, 'Localizar na lista' e o '×', mais os exports `fraseDaFaixa` e os literais"
  - "`tests/js/publicador-publicacoes-cabecalho.test.js` — 72 testes: literais, geometria por igualdade de conjunto, render adverso (17 formas × cada prop × cada campo), sonda de CSS construído com 2 iscas, e o gate contra classe do Tailwind em `.js`"
  - "o diretório `publicacoes/` ABERTO no gate de vocabulário (`DIR_PUB` em `ARQUIVOS_NOVOS` e em `DIRETORIOS_FECHADOS`, com a contagem travada em 2)"
  - "a CONCLUSÃO MEDIDA sobre a fonte da `faixaSucesso`: ela NÃO EXISTE hoje (pendência nomeada abaixo)"
affects: [180-04, 180-06, 180-10, 180-11, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "Slot + geometria na mesma casa: o contrato do cabeçalho é `acaoAtualizar: ReactNode` (quem sabe o destino é a página), mas a GEOMETRIA do botão mora no `.jsx` desta fase como export nomeado — senão as 5 medidas do spinner e os 2 rótulos ficariam fora do `content` do Tailwind e fora do alcance do gate"
    - "`descreve(valor)` em vez de `String(valor)` na mensagem de asserção: `String({toString:null})` LANÇA e derrubava o próprio gate; `JSON.stringify` transforma `NaN`/`Infinity` em `'null'`"
    - "Localização por ÁRVORE obrigatória quando há irmão decorativo ANTES do texto: `lastIndexOf('<span', posDoTexto)` pega o DOT, não a span de sincronização"
    - "Contagem de uma declaração do protótipo como gate de fidelidade (`flex-none` = 1, `cursor-pointer` = 2): pega tanto a falta quanto o ACRÉSCIMO por hábito"
    - "`contexto.skip()` com razão escrita quando o CSS construído é mais antigo que os componentes (comparação de `mtime`), em vez de reprovar o código certo ou aprovar o errado"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes.jsx
    - resources/js/Components/Mlb/Publicador/publicacoes/FaixaDeSucesso.jsx
    - tests/js/publicador-publicacoes-cabecalho.test.js
    - .planning/phases/180-publicacoes-lista/180-05-SUMMARY.md
  modified:
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "A fonte da `faixaSucesso` foi MEDIDA e NÃO EXISTE: zero `session()->put/flash` com resumo de publicação, zero chave desse tipo na lista branca de `flash` do `HandleInertiaRequests`, e a publicação é assíncrona (202 + job). Logo `resumo` é `null` sempre e a faixa nunca aparece — pendência nomeada, NUNCA resumo de exemplo"
  - "'Localizar na lista' só nasce com handler E `busca_sugerida` E `ml_item_id` em forma de STRING fechada — contrato declarado para o 180-11, que é quem liga o destino. Sem os três, o botão não é desenhado (não desabilitado)"
  - "`BotaoAtualizarDoCabecalho` é export NOMEADO e NÃO tem `router.*`: D-11 segue com UM `router.post` (`meus.atualizar`), que continua na página"
  - "6 `flex-none` do meu PRIMEIRO rascunho foram REMOVIDOS: o protótipo declara `flex:none` uma vez só em todo o bloco (`:54`). Foi a única divergência real desta rodada e ela era MINHA, não da tabela do plano"
  - "`cursor-pointer` MANTIDO nos 3 botões contra a tabela FID-01, que o omitiu: o protótipo declara `cursor:pointer` em `:39`, `:61` e `:62`"
  - "`disabled` + `cursor-wait` no 'Atualizar' são ACRÉSCIMO ao protótipo, justificado por LIST-23 (o cooldown de 30 s é comportamento de hoje), não por 'ficou parecido com o sistema'"

metrics:
  duration: "~2h (2026-10-13)"
  completed: 2026-10-13
  tasks: 3
  commits: 4
  testes-js-novos: 86
  mutacoes: "8/8 pegas"
---

# Fase 180 Plano 05: cabeçalho de Publicações e faixa de sucesso — Summary

Os dois blocos do topo da tela nova, fiéis ao protótipo medida por medida, com a conclusão
**medida** de que a faixa de sucesso **não tem fonte hoje** — e por isso nasce pronta e invisível,
sem um único número inventado.

## O que foi feito

| Task | O que | Commit | Arquivos |
|---|---|---|---|
| 1 | `CabecalhoDePublicacoes.jsx` + `BotaoAtualizarDoCabecalho` | `38974455` | +189 / **-0** |
| 2 | `FaixaDeSucesso.jsx` + `fraseDaFaixa` | `94c587b8` | +231 / **-0** |
| 3 | o gate (72 testes) + `publicacoes/` no vocabulário | `d4477abc` | +1056 / -0 e +32 / **-2** |
| — | ⚠️ correção de fidelidade que EU causei: 6 `flex-none` a menos + 2 gates novos | `4082606a` | +63 / -14 |

Total do plano (`cffbda9f..HEAD`): **4 arquivos, +1557 / -2**. Zero PHP, zero migration, zero pacote,
zero deleção.

---

## (1) A tabela FID-01, conferida ITEM A ITEM

Medido com `grep -n` em `new_layout_publicador/Publicacoes v2.dc.html` (656 linhas), confirmando
cada linha pela **semântica** e não pelo número.

> ⚠️ **PLACAR: 24 medidas conferidas, 1 divergência — e ela é de OMISSÃO da tabela, a favor do
> protótipo.** É a segunda vez na milestone que a tabela do plano quase não perde: o histórico era
> ~102 a 0 contra ela. A diferença segue sendo a mesma do 180-01 — este planejador mediu.
>
> ⚠️ **E a divergência que mais importou nesta rodada não foi da tabela: foi MINHA.** Ver (1c).

### 1a. Cabeçalho

| onde (semântica) | linha MEDIDA | medida do protótipo | bate com a tabela? |
|---|---|---|---|
| container do `<main>` | **:30** | `padding:28px 36px 110px;min-width:0;max-width:1600px;margin:0 auto;display:flex;flex-direction:column;gap:18px` | ⚠️ **a tabela OMITIU o `min-width:0`** — ver (1b) |
| `<header>` do bloco | **:31** | `display:flex;align-items:flex-end;justify-content:space-between;gap:24px` | sim |
| coluna do título | **:32** | `display:flex;flex-direction:column;gap:6px` | sim |
| H1 "Publicações" | **:33** | `margin:0;font-size:28px;line-height:1;font-weight:600;letter-spacing:-0.025em` | sim |
| subtítulo | **:34** | `font-size:13.5px;color:#A1A1AA` + o literal "Ofertas ativas e históricas desta empresa no Mercado Livre" | sim (literal byte a byte) |
| bloco da direita | **:36** | `display:flex;align-items:center;gap:10px` | sim |
| "Sincronizado {X}" | **:37** | `gap:6px;font-size:12.5px;color:#A1A1AA;padding-right:4px` | sim |
| dot verde | **:38** | `width:6px;height:6px;border-radius:50%;background:#22C55E` — **sem `flex:none`** | sim |
| botão "Atualizar" | **:39** | `36px;padding:0 14px;1px solid #2E2E2E;raio 9px;#111111;13px/500;#F4F4F5;gap:7px;`**`cursor:pointer`** · hover `#161616` | ⚠️ **a tabela omitiu o `cursor:pointer`** |
| spinner | **:41** | `13×13;1.6px #3F3F46;border-top-color:#FACC15;spin .7s` — **sem `flex:none`** | sim |
| ícone de refresh | **:44** | `14×14 viewBox="0 0 16 16" fill:none stroke:currentColor stroke-width:1.6` | sim |
| rótulos do botão | **:619** | `S.syncing ? 'Atualizando…' : 'Atualizar'` | sim |

### 1b. Faixa de sucesso

| onde (semântica) | linha MEDIDA | medida do protótipo | bate? |
|---|---|---|---|
| a faixa | **:53** | `gap:14px;padding:12px 14px 12px 16px;background:#14120A;border:1px solid rgba(250,204,21,0.35);border-radius:12px` | sim |
| círculo do check | **:54** | `26×26;50%;#FACC15;display:grid;place-items:center;`**`flex:none`** | sim |
| o check | **:55** | `13×13 viewBox 16;fill:none;stroke:#0A0A0A;stroke-width:2.2` | sim |
| coluna do texto | **:57** | `gap:2px;flex:1;min-width:0` — **sem `text-overflow`** | sim |
| linha 1 | **:58** | `font-size:13.5px;font-weight:600` | sim |
| linha 2 | **:59** | `font-size:12.5px;color:#A1A1AA` | sim |
| "Localizar na lista" | **:61** | `32px;0 14px;border:none;raio 8px;#FACC15;#0A0A0A;12.5px/500;`**`cursor:pointer`** — sem `flex:none` | ⚠️ `cursor:pointer` omitido na tabela |
| o "×" | **:62** | `32×32;raio 8px;transparent;#8A8A90;`**`cursor:pointer`**`;grid;place-items:center` · hover `#26220F` · `title="Dispensar"` | ⚠️ idem |
| ícone do "×" | **:63** | `14×14 viewBox 16;fill:none;stroke:currentColor;stroke-width:1.7` | sim |

**A divergência da tabela, nomeada:** `cursor:pointer` aparece **3 vezes** no bloco (`:39`, `:61`,
`:62`) e a tabela FID-01 não o trouxe em nenhum dos três. O protótipo ganha: as três classes estão
no código e travadas por gate (mutação viii). É a mesma falta que já está registrada no learnings
como débito de `SeletorDeEmpresaDoHeader.jsx:171`.

**O `min-width:0` do `<main>`** fica registrado aqui **para o plano 180-10**, que é o dono do
container: a classe completa é
`mx-auto flex min-w-0 max-w-[1600px] flex-col gap-[18px] px-[36px] pb-[110px] pt-[28px]`.
Este plano **não** desenha o container (compor a página é do 180-10).

### 1c. ⚠️ A divergência que era MINHA: 6 `flex-none` a mais

Varredura de `flex:none` em `:30`-`:66`: **uma ocorrência, só no círculo do check (`:54`)**.

Meu primeiro rascunho tinha posto `flex-none` em **seis** lugares — dot, spinner, `<svg>` de
refresh, botão "Atualizar", "Localizar na lista" e "×" — "por segurança". Isso é exatamente a
adaptação que o usuário recusou em 11/10/2026, e nenhuma delas era necessária: o `<header>` é
`space-between` e a coluna do título não tem `flex:1` (logo não há pressão sobre a direita), e na
faixa é a coluna de texto (`flex:1;min-width:0`) que absorve a sobra.

Corrigido no commit `4082606a`, com **dois gates novos** que travam a decisão nos dois sentidos:
a contagem de `flex-none` tem de ser **1** nos dois arquivos somados (e **0** no cabeçalho), e a de
`cursor-pointer` tem de ser **2** na faixa. Mutações **vii** (acrescentar `flex-none` ao dot) e
**viii** (tirar `cursor-pointer` do "Localizar") provam que esses dois gates não são decorativos.

Também **não** entrou `truncate` na linha 2: o protótipo não declara `text-overflow` em nenhuma das
duas linhas. Elipse ali seria melhoria não pedida numa fase de fidelidade.

---

## (2) ⚠️ A FONTE DA `faixaSucesso` — MEDIDA, e ela NÃO EXISTE

Isto é a pendência central deste plano, e é **medição**, não suposição:

| o que procurei | comando | resultado |
|---|---|---|
| `session()->put(` com algo de publicação em `app/` | `grep -rn "session()->put(" app/ \| grep -i "pub\|anuncio\|public"` | **0 ocorrências** |
| `session()->flash`/`->with('…')` nos controllers do Publicador e nos serviços de publicação | `grep -rn` em `MlbPublicador*.php`, `app/Services/Publicador/`, `app/Services/Mlb/Publicacao/` | só flashes de **tarefa** (`MlbPublicadorTarefasController`), nenhum de publicação |
| `session`/`flash` em `PublicacaoService.php` | `grep -n "session\|flash"` | **0** |
| `session`/`flash` em `PublicarAnuncioMlJob.php` | `grep -n "session\|flash"` | **0** |
| chave de flash na lista branca do Inertia | `HandleInertiaRequests.php:64-98` | 10 chaves, **nenhuma** de publicação |
| como a publicação termina | `MlbPublicadorController::publicar()` (`:258-268`) | `->responder(…, 202)` + `PublicacaoService::iniciar()` — **assíncrono**, via fila |

➡️ **Conclusão: `faixaSucesso` é `null` SEMPRE e a faixa nunca aparece na tela de hoje.**

Três razões independentes, e qualquer uma basta:
1. ninguém grava o resumo em sessão;
2. mesmo que gravasse, a chave morreria no caminho — a lista branca do `HandleInertiaRequests` é
   fechada, e esse defeito já aconteceu neste projeto duas vezes (os comentários de
   `nps_link_existente` e `portal_codigo_enviado`, `:68-88`, documentam exatamente isso);
3. a publicação é **assíncrona** — quando o usuário volta para a lista, o job pode nem ter rodado,
   então "N publicações criadas agora" não é um fato disponível no retorno.

**O que isso NÃO autoriza:** fabricar o resumo. O layout fica pronto e o componente devolve `null`.
⚠️ **O protótipo traz "2 publicações criadas agora · Poltrona Beny" e isso é dado MOCK** — o gate
assere que a string `Poltrona Beny` **não** existe em nenhum dos dois arquivos.

**O caminho nomeado para quem for ligar a fonte** (plano futuro, não este):
`pub_publicacoes.concluida_em` + `pub_publicacao_itens` já têm tudo (quantos itens, qual produto,
qual fase, qual tipo, qual ator). Falta (a) uma chave de flash nova **declarada no
`HandleInertiaRequests`** e (b) decidir o que a tela mostra quando a fila ainda não concluiu — que é
uma decisão de produto, não de código.

---

## (3) "Localizar na lista" — contrato declarado, nunca botão morto

O handler é do plano **180-11** (`<o_mecanismo_do_estado_na_url>`, item **(e)**). O que este plano
entrega é a **trava**: o botão só é desenhado quando as **três** condições valem.

```
podeLocalizar = typeof onLocalizar === 'function'
             && soString(resumo.busca_sugerida) !== null
             && soString(resumo.ml_item_id)     !== null
```

E ele chama `onLocalizar(busca, item)` com os dois valores já **aparados e conferidos como string**.

⚠️ `soString()` é estrita de propósito: `textoUtil(7)` devolve `'7'` e `textoSeguro(NaN)` devolve a
string `'NaN'` (porque `typeof NaN === 'number'`) — as duas já montaram rota inválida e rótulo sujo
nesta milestone. Há caso de teste com `ml_item_id: 1234567890` **numérico** provando que o botão
**não nasce**.

Mesma regra para o "×" (sem `onDispensar`, não existe) e para o "Atualizar" (sem `onClick` função,
não existe).

---

## (4) "Nunca medimos" ≠ "medimos e é zero", e a data por REGEX

O bloco "● Sincronizado X" **não aparece** quando:
`sincronizacao` é `null`/não-objeto/array · `nunca_coletado === true` · `rotulo` não é string útil ·
ou o `rotulo` **tem cara de data crua** (`/\d{4}-\d{2}-\d{2}/`).

Nunca aparece "Sincronizado há —" nem "Sincronizado nunca". E o botão "Atualizar", que é quem
**resolve** a falta de coleta, continua — testado.

⚠️ **Zero `new Date()`, zero `Date.now()`, zero `toLocale*`** nos dois arquivos, travado por
asserção. O `rotulo` chega pronto do servidor. A guarda de ISO existe porque, se o servidor mandar
o carimbo cru no lugar do rótulo humano, a tela diria "Sincronizado 2026-10-13T12:00:00Z" — jargão
de máquina, que a regra permanente do projeto proíbe. Mutação **v** (passar a montar com
`new Date()`) reprova em **11** asserções.

---

## (5) "O que precisaria estar errado no código para este gate falhar?" — por teste

| teste | falha se… |
|---|---|
| `os literais de tela estão byte a byte` (6 sub) | alguém parafrasear o H1, o subtítulo do README, o prefixo "Sincronizado", "Atualizar"/"Atualizando…", "Localizar na lista" ou `title="Dispensar"`; ou fixar o nome de produto do protótipo no código; ou errar o plural |
| `a geometria do cabeçalho` (11 sub) | qualquer medida trocada (28→24, 13.5→`text-sm`, raio 9→outro), **qualquer classe A MAIS** (igualdade de conjunto), o elemento mudar de tag, o `viewBox` virar o do Lucide, ou o `stroke-width` sair de 1.6 |
| `flex:none aparece UMA vez` | alguém "proteger" dot/spinner/ícone/botões com `flex-none` — **foi o que meu 1º rascunho fez** (mutação vii) |
| `cursor-pointer nos 3 botões` | alguém seguir a tabela FID-01 em vez do protótipo (mutação viii) |
| `a geometria da faixa` (5 sub) | idem; mais o raio 8 LITERAL virar `rounded-pub-control-sm` (7px) ou `rounded-pub-sub` (10px) — asserção negativa explícita nos dois |
| `nenhum enfeite: botão sem destino não nasce` (5 sub) | desenhar "Localizar" sem handler, sem `busca_sugerida`, sem `ml_item_id`, ou com `ml_item_id` numérico; desenhar o "×" sem `onDispensar`; desenhar "Atualizar" sem `onClick` |
| `"nunca medimos" não é "medimos e é zero"` (4 sub) | tirar a guarda de `nunca_coletado` (mutação vi), aceitar rótulo não-string, deixar passar um ISO cru, ou montar a data no cliente (mutação v) |
| `a faixa não existe sem resumo` (4 sub) | tirar o `return null` (mutação iv), aceitar `total` como string/0/negativo/fracionário/`NaN`/`Infinity`, preencher pedaço ausente com "—", deixar separador duplo ou sobrando, ou desenhar a linha 2 vazia |
| `render adverso` (6 sub) | qualquer prop ou campo chegando como objeto/`NaN`/ausente e sendo renderizado como texto — a tela preta de 07/10/2026 |
| `D-11` (7 sub) | `router.*`, `axios`, `fetch(`, import de `@inertiajs/react`, um `.map()` novo sem reler a regra do Rollup, ou recriar o `textoUtil` em vez de importar da casa dele; **e** a página perder (ou duplicar) o `router.post` de `meus.atualizar` |
| `nenhum .js de publicacoes/ carrega classe` (3 sub) | um `.js` do diretório ganhar classe do Tailwind — ela **não gera CSS** e não existe para a tela, com os testes de render passando |
| `as classes GERAM CSS com o valor certo` (10 sub) | classe presente com VALOR errado: outro hex no fundo, alfa 0.3 em vez de 0.35, 14px no subtítulo, letter-spacing perdido, hover do "×" sem regra |
| `GATE-03 … FORA de ARQUIVOS_NOVOS` | arquivo novo em `publicacoes/` entrar sem ser declarado — e aí ficaria **sem conferência** contra `text-sm`, `ecf-*`, hex e breakpoint |
| `os 2 arquivos da Fase 180 estão na lista` | alguém esquecer de elevar a contagem ao acrescentar arquivo — é a trava contra passar **por omissão** |

### E o que NÃO tem gate — declarado, não maquiado

- **A existência da fonte da `faixaSucesso` não tem gate.** Não existe teste que diga "o servidor
  deveria mandar isto e não manda" — a ausência de fonte é um fato do sistema, não um defeito do
  código deste plano. O que protege é esta SUMMARY e o `null` por padrão.
- **A sonda de CSS não distingue classe de arquivo.** Ver a armadilha medida em (7).

---

## (6) O par `+assert` / `-assert`

| arquivo | linhas | `+assert` | `-assert` | leitura |
|---|---|---|---|---|
| `tests/js/publicador-vocabulario-novo.test.js` | +32 / **-2** | **1** | **0** | as 2 linhas removidas são **reapontes**: o literal de `DIRETORIOS_FECHADOS` (que ganhou `DIR_PUB`) e o NOME do teste (que ganhou "`publicacoes/`"). Nenhuma asserção saiu; nada saiu de `ARQUIVOS_NOVOS`; a asserção dos **12** da Fase 179 está intacta |
| `tests/js/publicador-publicacoes-cabecalho.test.js` | +1078 / **-0** (arquivo novo) | — | — | — |
| os 2 componentes | +447 / **-0** | — | — | — |

É o caso que o learnings chama de *"gate vazio ao contrário"*: um `grep '^-' \| grep assert`
acusaria remoção onde não houve. Conferido por `git diff | grep -E "^-" | grep -v "^---"` — só as
duas linhas acima, e nenhuma contém `assert`.

---

## (7) As provas de CSS construído — e a sonda que mentiu de novo

Build final: `assets/app-D3BwW9ez.css`, **219.741 bytes** (era 219.501 antes do plano: **+240**,
consistente com as 2 regras que nasceram hoje).

| classe | regra MEDIDA no CSS | armadilha que ela demonstra |
|---|---|---|
| `text-[13.5px]` | `font-size:13.5px` | (v) seletor **escapado** (`.text-\[13\.5px\]`) |
| `text-[28px]` | `font-size:28px` | — |
| `tracking-[-0.025em]` | `letter-spacing:`**`-.025em`** | (iv) o cssnano come o zero **depois do menos** |
| `h-[36px]` | `height:36px` | — |
| `bg-pub-row-new` | `rgb(20 18 10 / var(--tw-bg-opacity, 1))` | (i) token sólido **não** sai em hex, sai decimal |
| `border-pub-accent/[0.35]` | `border-color:`**`#facc1559`** | (iii) opacidade em **hex de 8 dígitos**; `0x59` = 89 = `round(0.35×255)`. `0.3` daria `0x4d` |
| `hover:bg-pub-hover-toast` | `.hover\:bg-pub-hover-toast:hover { … rgb(38 34 15 …) }` | (vi) na variante o **prefixo faz parte do nome** |
| `stroke-pub-page` | `stroke:`**`#0a0a0a`** | (ii) `stroke`/`fill` sólidos SAEM em hex literal |
| `rounded-[8px]` / `rounded-pub-panel` | `border-radius:8px` / `12px` | — |
| **ISCA** `bg-pub-isca-9q7` | **AUSENTE**, e a string não aparece no CSS | — |
| **ISCA** `text-[99.5px]` | **AUSENTE**, idem | — |

**As duas iscas foram medidas como inexistentes no repo inteiro** antes de serem usadas
(`grep -rn` em `resources/`, `app/`, `tests/`, `tailwind.config.js` → 0 ocorrências) — é a lição do
`w-[13px]`, que parecia inventado e saiu presente.

### ⚠️ A sonda mentiu — 6ª vez na milestone, 6ª vez por erro do MEDIDOR

A primeira sonda que escrevi (num `node -e`, justamente o que o learnings proíbe) reportou:

```
text-[13.5px] -> 0        h-[36px] -> 3        text-[28px] -> 0
```

As duas primeiras são **falsas**: a regra de `text-[13.5px]` estava presente e correta, e o `3` do
`h-[36px]` era casamento espúrio. Causa: no `-e` com aspas duplas do shell, `\\\\[` chegou ao
RegExp como `\[` (classe de caracteres escapada = `[` literal), e não como `\\[` (barra invertida
literal + `[`) — então a sonda procurava `.text-[13.5px]` **sem** as barras que o CSS realmente
escreve. Conserto: sonda em **ARQUIVO**, escapando **todos** os metacaracteres.
**Placar da rodada: 2 falsas faltas, as duas do medidor, 0 do código.**

### ⚠️ E uma limitação ESTRUTURAL da sonda, medida e declarada

As mutações **ii** (`bg-pub-row-new` → `bg-pub-surface`) e **iii** (`/[0.35]` → `/[0.3]`) foram
pegas pelo gate de **HTML** (igualdade de conjunto de classes), **não** pelo gate de CSS, ao
contrário do que o plano previu. Dois motivos, os dois reais:

1. **O CSS reflete o último build, não a fonte.** Mutar um `.jsx` sem rebuildar não muda o CSS.
2. **As duas classes têm OUTRO dono no repo** — `bg-pub-row-new` em
   `visaogeral/PublicacoesRecentes.jsx:109` e `border-pub-accent/[0.35]` em
   `visaogeral/AtividadeRecente.jsx:70`. A regra continuaria no CSS mesmo se este plano parasse de
   usá-la.

➡️ **O que o gate de CSS protege de verdade** é a camada de tokens e a matemática do Tailwind (o
valor do token mudar, a opacidade arredondar diferente, o cssnano passar a emitir outra forma).
Quem protege a fidelidade **deste arquivo** é a igualdade de conjunto de classes no HTML. Os dois
são necessários e não são substitutos — e a única classe cuja regra existe **só por causa deste
plano** é `hover:bg-pub-hover-toast` (era **0** no build de ontem, medido pelo 180-01).

---

## (8) As 8 mutações — 8/8 pegas

Contagem do alvo travada em **1** antes de aplicar (recusa se ≠1), **qual linha mudou** conferida
depois, teste designado rodado, restauração por cópia pristina conferida por `sha256`.
⚠️ **ANSI removido da saída ANTES de filtrar o veredicto** — é a armadilha que fez a campanha
inteira do 180-03 parecer verde sem provar nada.

| # | mutação | arquivo:linha | reprovou em | asserções |
|---|---|---|---|---|
| i | `text-[13.5px]` → `text-sm` no subtítulo | `CabecalhoDePublicacoes.jsx:175` | **gate de vocabulário** (`text-[Xpx] na lista… e nenhuma escala nomeada`) **e** `subtítulo :34` | 4 |
| ii | `bg-pub-row-new` → `bg-pub-surface` | `FaixaDeSucesso.jsx:193` | `a faixa :53` (igualdade de conjunto) | 2 |
| iii | `border-pub-accent/[0.35]` → `/[0.3]` | `FaixaDeSucesso.jsx:193` | `a faixa :53` | 2 |
| iv | tirar o `return null` de `resumo` nulo | `FaixaDeSucesso.jsx:181` | `resumo nulo/ausente → HTML VAZIO` **e** `sem contagem confiável…` | 3 |
| v | `rotulo` montado com `new Date()` | `CabecalhoDePublicacoes.jsx:76` | 11 asserções, entre elas `⚠️ DATA POR REGEX` e o render adverso | **11** |
| vi | tirar a guarda de `nunca_coletado` | `CabecalhoDePublicacoes.jsx:74` | `nunca_coletado = true → o bloco não aparece` | 2 |
| vii | **acrescentar** `flex-none` ao dot | `CabecalhoDePublicacoes.jsx` (dot) | `dot verde :38` **e** `flex:none aparece UMA vez` | 3 |
| viii | tirar `cursor-pointer` do "Localizar" | `FaixaDeSucesso.jsx` (botão) | `"Localizar na lista" :61` **e** `cursor-pointer nos 3 botões` | 4 |

**0 recusadas, 0 sobreviventes** — nada a investigar.
`sha256` final idêntico ao pristino: `CabecalhoDePublicacoes.jsx` `fd39bbcf76da…`,
`FaixaDeSucesso.jsx` `ddca491fc784…`.

> ⚠️ **Efeito colateral da campanha, e ele é EVIDÊNCIA:** restaurar os arquivos atualiza o `mtime`,
> e aí o bloco de CSS construído entra em `skip` com a razão escrita ("o CSS construído é mais
> antigo que os componentes — rode `npm run build`"), caindo de 72 para 62 testes no arquivo. Foi a
> prova **ao vivo** de que o detector de build velho funciona, e não um `assert.ok(true)` calado.

---

## ⚠️ PARA O PLANO 180-10: os nomes que ele tem de pôr em `ARQUIVOS_NOVOS`

O 180-10 acrescenta à lista os arquivos de **toda** a fase. **Os deste plano, explicitamente**, para
ele não esquecer (esquecer faz o gate passar **por omissão**, e o arquivo esquecido fica sem
conferência de vocabulário):

```
resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes.jsx
resources/js/Components/Mlb/Publicador/publicacoes/FaixaDeSucesso.jsx
```

Os dois **já estão** em `ARQUIVOS_NOVOS`, `DIR_PUB` **já está** em `DIRETORIOS_FECHADOS`, e a
contagem da fase está travada em **2**. ➡️ **O 180-06 em diante tem de ELEVAR esse número** junto
com os arquivos dele; deixá-lo parado é o que denuncia o esquecimento.

## ⚠️ PARA O PLANO 180-10 / 180-11: o contrato que eles ligam

```jsx
<CabecalhoDePublicacoes
    sincronizacao={sincronizacao ?? null}
    acaoAtualizar={<BotaoAtualizarDoCabecalho
        atualizando={atualizando}
        cooldown={cooldown}
        onClick={atualizarAgora}   // o router.post FICA na página (D-11)
    />}
/>
<FaixaDeSucesso
    resumo={faixaSucesso ?? null}  // hoje: SEMPRE null — ver (2)
    onLocalizar={(busca, item) => navegar({ busca, sel: item, visao: 'grupo', page: 1, /* filtros no default */ })}
    onDispensar={() => setFaixaVisivel(false)}
/>
```

- `BotaoAtualizarDoCabecalho` é **export nomeado** de `CabecalhoDePublicacoes.jsx`. ⚠️ **Não** é o
  `BotaoAtualizar` local de `MeusAnuncios.jsx:58` (que o 180-10 recomenda não redesenhar, e que vai
  para o `acoes` do header global) — o nome é diferente de propósito, para não haver colisão de
  import.
- `onLocalizar` recebe `(busca, item)` **já conferidos como string aparada**.
- O container do `<main>` é do 180-10 — medida completa em (1b).
- O `min-w-0` do `<main>` está na medida porque a tabela do plano o omitiu.

---

## Verificação

| gate | resultado |
|---|---|
| `node --test` nos dois gates | **331 testes / 331 pass / 0 fail / 0 skipped** |
| `npm run test:js` **ANTES** (medido antes de tocar em nada) | **3742 / 3741 pass / 1 fail / 0 skipped** — bate com o briefing |
| `npm run test:js` **DEPOIS** | **3828 / 3827 pass / 1 fail / 0 skipped** — delta **+86 testes, +86 pass** (72 do gate novo + 14 do vocabulário: 2 arquivos × 6 sub + 1 de diretório + 1 de contagem) |
| a falha única | a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido") — **NÃO corrigida**, é conhecida e intencional |
| `npm run build` | exit **0**, 27,7 s |
| manifest **pelo JSON** (`--input-type=commonjs`) | **478 entradas** (idênticas às 478 de antes do plano), `resources/js/app.jsx` → `assets/app-CmXJi1zQ.js`, css `assets/app-D3BwW9ez.css`; **195 páginas** `.jsx`; 11 entradas casando `/publicador/i`, entre elas as 7 páginas de `Pages/Mlb/Publicador/` — **nenhuma página perdida** |
| `test -e` nos 16 alvos de território | **16/16 existem** — zero caminho fantasma nesta rodada (usei os caminhos CERTOS registrados pelo 180-01/180-03: `Components/Publicador/Mesa` **sem** o `Mlb`, `header/PublicadorHeader.jsx` **dentro** de `header/`, `Pages/Mlb/AnunciosHistorico.jsx` **fora** de `Publicador/`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`) |
| território RANGE A (`f23f610d..HEAD`) — o que ninguém da fase toca | **0 arquivos** em `identidade/`, `header/`, `visaogeral/`, `Components/Publicador/Mesa`, `Editor.jsx`, `AnunciosHistorico.jsx`, `anuncioHistoricoUtils.js`, `AppLayout.jsx`, `app/Support/Publicador`, `MlPublicacaoService.php` |
| território RANGE B (`cffbda9f..HEAD`) — o que OUTRO plano possui | **0 arquivos** em `MeusAnuncios.jsx`, `MlbAnuncioController.php`, `AcervoTriagemService.php`, `PublicacoesDaListaService.php`, `tailwind.config.js`, `ROADMAP.md`, `REQUIREMENTS-v26.md` |
| **CONTROLE** do gate de território | o MESMO comando vê `204/0`, `243/0`, `1078/0` e `32/2` nos meus 4 arquivos — logo não é vazio por construção |
| PHP | `git diff --numstat cffbda9f..HEAD -- app/ database/ routes/` → **vazio**. Suíte de ~7 min **não rodada, por desnecessária** (diff vazio é a prova, não o veredicto do runner) |
| range provado vivo | `cffbda9f..HEAD` = **4 commits / 4 arquivos** |
| árvore | limpa nos meus alvos |

⚠️ **Gate de território: RANGE A acusou `.planning/ROADMAP.md` (+72/-2) e NÃO fui eu** — é dos
commits `7a6a694a` e `32e64439` do planejador da fase. No MEU range (`cffbda9f..HEAD`) o ROADMAP e o
`REQUIREMENTS-v26.md` dão **0**. É o terceiro plano seguido em que o range da FASE acusa trabalho
legítimo do plano dono, exatamente como o learnings avisa.

Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma migration, nenhum pacote,
`gsd-sdk query state.advance-plan` **não** executado (bloco aditivo à mão no `STATE.md`),
`ROADMAP.md` e `REQUIREMENTS-v26.md` **não** tocados.
⚠️ **Nenhum número de PRODUÇÃO foi medido** — leitura de produção segue bloqueada nesta sessão; a
conclusão sobre a `faixaSucesso` é do **código e do schema**, não do banco de produção.
⚠️ **Nenhuma tela foi vista renderizada** — não há navegador neste ambiente, e nada aqui afirma o
contrário. O que existe é HTML de `renderToStaticMarkup` e o corpo das regras do CSS construído.

## Débito deixado de propósito

- **A faixa de sucesso não tem fonte** — ver (2). Quem ligar precisa de uma chave nova de flash
  **declarada no `HandleInertiaRequests`** e de uma decisão de produto sobre a fila assíncrona.
- **`disabled` + `cursor-wait` no "Atualizar"** são acréscimo ao protótipo, justificado por LIST-23
  (o cooldown de 30 s é comportamento de hoje, em `MeusAnuncios.jsx:327-337`). Se o 180-10 decidir
  que o cooldown sai, estas duas classes saem com ele — e o gate pede reaponte explícito.
- **O container do `<main>` não existe ainda** (é do 180-10). Até ele chegar, os dois componentes
  não têm pai com as medidas de `:30`.

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes.jsx` — FOUND (204 linhas)
- `resources/js/Components/Mlb/Publicador/publicacoes/FaixaDeSucesso.jsx` — FOUND (243 linhas)
- `tests/js/publicador-publicacoes-cabecalho.test.js` — FOUND (1078 linhas)
- `tests/js/publicador-vocabulario-novo.test.js` com `DIR_PUB` — FOUND
- `.planning/phases/180-publicacoes-lista/180-05-SUMMARY.md` — FOUND
- `38974455` — FOUND no `git log`
- `94c587b8` — FOUND no `git log`
- `d4477abc` — FOUND no `git log`
- `4082606a` — FOUND no `git log`
