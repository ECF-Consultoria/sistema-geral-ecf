# Fase 180 — Publicações: o FECHAMENTO MEDIDO

**Escrito em 14/10/2026, depois dos 13 planos** (os 12 originais + os corretivos `04B` e `10B`),
**126 mutações** e **173 medidas de fidelidade** conferidas contra `Publicacoes v2.dc.html`.

**Commit de partida da fase:** `f23f610d`.
**Protótipo conferido no disco:** `new_layout_publicador/Publicacoes v2.dc.html`,
657 linhas / 68.259 bytes, `sha256 6dcbb2f9ff8782e0f76408640dd26cf0d63dd1671f184d037b2e170a60d5532b`
— **o mesmo sha256 que o plano 180-01 mediu em 13/10**, logo o protótipo não mudou durante a fase.

---

## ⚠️ ANTES DE QUALQUER NÚMERO: NENHUM EXECUTOR VIU TELA RENDERIZADA

**Em nenhum dos 13 planos.** Não é modéstia, é a condição do ambiente, e ela foi remedida aqui:

| fato | medida |
|---|---|
| o banco local não tem empresa com `ml_token` | `meus()` faz `abort_unless($company->mlToken !== null, 404)` — a rota devolveria 404 |
| `ml_acervo_itens` está vazia | 0 linhas (medido pelo plano 180-03 por `information_schema`) |
| a leitura de produção está bloqueada | nesta sessão e nas 13 anteriores |
| não há navegador no ambiente | todo render é `esbuild` + `react-dom/server` com fixture sintético |

➡️ **Por isso a validação visual é um CHECKPOINT HUMANO, em produção, na conta 459.** O roteiro está
no fim deste documento. **Nada aqui afirma ter visto a tela.**

---

## (f) AS BASELINES — re-medidas por mim, não copiadas

Todas as quatro foram rodadas **nesta sessão**, depois do último commit da fase (`e8bd4c4f`).

| baseline | referência das SUMMARYs | **MEDIDO AGORA** | veredicto |
|---|---|---|---|
| `npm run build` | exit 0, 22–52 s | **exit 0, 31,70 s** | ✅ |
| manifest **pelo JSON** | 479 entradas / 196 páginas | **479 entradas / 196 páginas** | ✅ exato |
| `npm run test:js` | 4481 / 4480 pass / 1 fail / 0 skipped | **4481 / 4480 pass / 1 fail / 0 skipped** | ✅ exato |
| `artisan test tests/{Feature,Unit}/Publicador` | 2085 / 15.960 asserções / 0 falhando | **2085 passed / 15.960 assertions / 0 falhando**, 565,74 s | ✅ exato |

### O manifest, pelo JSON — nunca por grep

```
ENTRADAS=479
PAGINAS=196
PRESENTE resources/js/app.jsx                              -> assets/app-BjoGwmHO.js
PRESENTE resources/js/Pages/Mlb/MeusAnuncios.jsx           -> assets/MeusAnuncios-Dkr5s0y-.js
PRESENTE resources/js/Pages/Mlb/AnunciosHistorico.jsx      -> assets/AnunciosHistorico-Cg-Gt4Sf.js
PRESENTE resources/js/Pages/Mlb/Publicador/Editor.jsx      -> assets/Editor-BO1UqSql.js
PRESENTE resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx  -> assets/VisaoGeral-BEQosFUd.js
PRESENTE resources/js/Pages/Mlb/Publicador/Produtos.jsx    -> assets/Produtos-Bv__QHlm.js
PRESENTE resources/js/Pages/Mlb/Publicador/Configuracoes.jsx -> assets/Configuracoes-DuFLcp2_.js
ISCA ausente, ok  resources/js/Pages/Mlb/NaoExiste9z7.jsx
ENTRADAS_CASANDO_PUBLICADOR=11
```

⚠️ **O hash de `MeusAnuncios` é `Dkr5s0y-` — com HÍFEN no fim.** É exatamente por isso que o manifest
se confere pelo JSON: um `grep` por `MeusAnuncios-[A-Za-z0-9]*\.js` pode não casar, e
**página React fora do manifest SOME da aplicação**. A **isca** (uma entrada que não existe) saiu
ausente ⇒ a sonda não está cega.

### ⚠️ A única falha de JS — nomeada, localizada por SEMÂNTICA e NÃO corrigida

```
ℹ tests 4481   ℹ pass 4480   ℹ fail 1   ℹ skipped 0   ℹ todo 0   ℹ suites 27
test at tests\js\estrutura-grade-glide.test.js:122:1
  → assertion em :123,  expected: /RECOLHIDOS_INICIAIS = \[G_SECUND\]/
```

É a **pré-existente** *"Características secundárias nasce recolhido"*. ⚠️ **A referência do briefing
(`:122`) está CERTA** — é a linha da declaração `test(...)`; `:123` é a linha da asserção que falha.
As duas apontam para o mesmo teste, identificado pela semântica (o `expected` é o literal
`RECOLHIDOS_INICIAIS = [G_SECUND]`). **Não corrigida, de propósito.** Nenhuma outra falha, **0 skipped**.

### ⚠️ A suíte PHP: medi SÓ a faixa do Publicador, e isso é declarado

`tests/Feature/Publicador` + `tests/Unit/Publicador`: **2085 passed / 15.960 assertions / 0 falhando**.

⚠️ **O resto da suíte PHP é da outra sessão.** A Fase 184 (Bling) commitou **PHP, duas migrations e
rotas** na MESMA árvore, com commits **intercalados** entre os desta fase (§g). Rodar a suíte inteira
mediria o trabalho dela, e o fechamento aritmético não fecharia. **O que eu afirmo é a faixa do
Publicador; sobre `tests/Feature/Bling/*` eu não afirmo nada.**

As **54 `PHPUnit Deprecations`** por `@group phaseNNN` (convenção de 75 arquivos deste projeto) são
pré-existentes e fora de escopo — o runner só as lista com `--display-deprecations`, e eu não o pedi.

---

## (a) A TABELA FID-01 CONSOLIDADA — re-medida NO PROTÓTIPO, pela semântica

### O placar da fase

| plano | medidas conferidas | divergências declaradas pelo plano | natureza |
|---|---|---|---|
| 180-01 | 10 | **0** | — (o planejador mediu por script) |
| 180-05 | 24 | 1 | omissão (`cursor:pointer`, 3 ocorrências) |
| 180-06 | 27 | 5 | todas de omissão |
| 180-07 | 34 | 7 | todas de omissão |
| 180-08 | 40 | 14 | todas de omissão (+2 de prescrição) |
| 180-09 | 31 | 9 | 8 de omissão + 1 de referência (+3 de prescrição) |
| 180-11 | 7 (teclado) | 3 | 1 de omissão + 1 de `preventDefault` + 1 de consequência |
| **total** | **173** | **39** | |

➡️ **E eu achei 5 que NENHUM plano pegou** (seção seguinte). **Total da fase: 44 divergências.**
**43 a favor do protótipo. UMA em que o protótipo PERDEU** (o comparador do grupo, §a3).

### a1. ⚠️ AS 5 DIVERGÊNCIAS DE REFERÊNCIA QUE SÓ ESTE FECHAMENTO PEGOU

Re-medi, **ancorando na semântica e nunca no número**, as 37 referências `arquivo:linha` que as 13
SUMMARYs afirmaram sobre o protótipo. **32 batem; 5 divergem** — e **nenhuma divergência é de VALOR**:

| quem afirmou | o que | afirmou | **MEDIDO** | o valor está certo? |
|---|---|---|---|---|
| 180-05 | container do `<main>` | `:30` | **`:29`** | ✅ `padding:28px 36px 110px;min-width:0;max-width:1600px;margin:0 auto;display:flex;flex-direction:column;gap:18px` |
| 180-05 | botão "Atualizar" | `:39` | **`:40`** | ✅ `height:36px;padding:0 14px;border:1px solid #2E2E2E;border-radius:9px;background:#111111;font-size:13px;font-weight:500;color:#F4F4F5;cursor:pointer` |
| 180-05 | spinner | `:41` | **`:42`** | ✅ `13×13;border:1.6px solid #3F3F46;border-top-color:#FACC15;animation:spin .7s` |
| 180-06 | trilho de status | `:83` | **`:84`** (e o do toggle em `:94`, não `:92`) | ✅ `gap:2px;padding:3px;background:#161616;border-radius:10px` |
| 180-06 | botão de aba | `:85` | **`:86`** | ✅ `gap:7px;height:30px;padding:0 12px;border:none;border-radius:7px;font-size:13px;font-weight:…` |

➡️ **É a §1 do learnings em ação, cometida pelos planos 180-05 e 180-06.** As referências envelhecem
e deslocam. ⚠️ **Mas o padrão da milestone se confirma mais uma vez: a tabela não erra VALOR — erra
por OMISSÃO e por REFERÊNCIA.** As 5 são divergências **de documentação**, não de implementação:
**o código está certo nas cinco.**

⚠️ **E o 180-09 já havia corrigido as suas próprias por iniciativa própria** (commit `b2bf9a04`:
`:179→:180`, `:181→:182`, `:187→:190`, `:190→:194`) — as dele batem agora.

### a2. As âncoras load-bearing, re-medidas uma a uma

| bloco | onde (semântica) | linha MEDIDA | medida do protótipo | implementado | veredicto |
|---|---|---|---|---|---|
| cabeçalho | container do `<main>` | `:29` | `28px 36px 110px` · `min-w:0` · `max-w:1600px` · `gap:18px` | `px-[36px] pt-[28px] pb-[110px] min-w-0 max-w-[1600px] gap-[18px]` — CSS ✅ | ATENDIDO |
| cabeçalho | H1 "Publicações" | `:33` | `28px/1/600`, `letter-spacing:-0.025em` | `text-[28px]` ✅ · `tracking-[-0.025em]` → `letter-spacing:-.025em` ✅ | ATENDIDO |
| cabeçalho | subtítulo | `:34` | `13.5px #A1A1AA` + literal | `text-[13.5px]` → `font-size:13.5px` ✅ | ATENDIDO |
| faixa | a faixa | `:53` | `#14120A` + borda `rgba(250,204,21,0.35)` | `bg-pub-row-new` → `rgb(20 18 10)` ✅ · `border-pub-accent/[0.35]` → **`#facc1559`** (`0x59`=89=round(0.35×255)) ✅ | ATENDIDO |
| faixa | círculo do check | `:54` | `flex:none` — **a ÚNICA ocorrência no bloco `:30`-`:66`** | medido: **13 `flex:none` no protótipo inteiro, 1 no bloco** (`:54`) ✅ | ATENDIDO |
| busca | `<input>` de 54px | `:70` | `54px` · raio 14 · `15.5px` · ring `rgba(250,204,21,0.18)` | `h-[54px]`✅ `rounded-pub-table`→14px✅ `text-[15.5px]`✅ `focus:ring-pub-accent/[0.18]`→**`rgb(250 204 21 / .18)`**✅ | ATENDIDO |
| trilho | segmentado | `:84` / `:94` | `gap:2px;padding:3px;#161616;raio 10` | `shadow-pub-seg-ativo` → `inset 0 0 0 1px #333333` ✅ · `bg-pub-seg` → `rgb(38 38 38)` ✅ | ATENDIDO |
| chips | opção segmentada ATIVA (`seg()`) | `:535` | `rgba(250,204,21,0.16)`, peso 600, **SEM borda** | `bg-pub-accent/[0.16]` → **`#facc1529`** ✅ | ATENDIDO |
| chips | chip-toggle ATIVO (`on()`) | `:608` | `rgba(250,204,21,0.12)` + borda `0.55`, peso **não muda** | `bg-pub-accent/[0.12]` → **`#facc151f`** ✅ · `border-pub-accent/[0.55]` → **`#facc158c`** ✅ | ATENDIDO |
| chips | borda OFF do chip | `:608` | `#2A2A2A` | `border-pub-line-off-2` → `rgb(42 42 42)` ✅ | ATENDIDO |
| popover | os dois popovers | `:127` / `:146` | `top:38px`, raio 10, `220px`/`190px`, sombra `0 16px 40px rgba(0,0,0,0.6)` | `w-[220px]`✅ `w-[190px]`✅ `shadow-[0_16px_40px_rgba(0,0,0,0.6)]` → `0 16px 40px rgba(0,0,0,.6)` ✅ | ATENDIDO |
| popover | `z-index:40` / backdrop `z-index:30` | `:127` / `:307` | 40 / 30 | **`z-10` nos dois** | **DESVIO DECLARADO** (§b) |
| tabela | o GRID das 9 colunas | `:163` = `:202` | `40px minmax(300px,1fr) 136px 104px 136px 112px 68px 150px 64px` | CSS: `grid-template-columns:40px minmax(300px,1fr) 136px 104px 136px 112px 68px 150px 64px` ✅ **byte a byte** | ATENDIDO |
| tabela | as duas alturas | `:618` | `compact ? '50px' : '62px'` | `min-h-[62px]`→62px ✅ `min-h-[50px]`→50px ✅ | ATENDIDO |
| tabela | os CINCO fundos | `:553` | `#221D07 / #1C1C1C / #18160C / #14120A / #111111` | **5 `rgb()` DISTINTOS no CSS**: `34 29 7` · `28 28 28` · `24 22 12` · `20 18 10` · `17 17 17` ✅ | ATENDIDO |
| tabela | recuo agrupado | `:545` | `grouped ? '30px' : '0px'` | implementado ✅ | ATENDIDO |
| tabela | borda da linha | `:202` | `border-top:1px solid #1F1F1F` | `border-pub-line-subtle` → `rgb(31 31 31)` ✅ | ATENDIDO |
| grupo | linha de grupo | `:173` | `height:58px`, `flex`, `#151515`, hover `#1A1A1A` | `h-[58px]`✅ `bg-pub-raised`→`rgb(21 21 21)`✅ `hover:bg-pub-hover-3`→`rgb(26 26 26)`✅ | ATENDIDO |
| grupo | tile de 38px | `:175` | 38px, raio 9, **corpo 12px**, `margin-left:-4px` | `-ml-[4px]` → **`margin-left:-4px`** (o SINAL é a informação) ✅ | ATENDIDO |
| grupo | bloco da cobertura | `:180` | `gap:14px;margin-left:22px;padding-left:22px;border-left:1px #262626;height:30px` | implementado ✅ | ATENDIDO |
| grupo | o **rótulo F1/F2** do par | `:183` | `<span 11.5px/600 #A1A1AA margin-right:2px>{{ c.phase }}</span>`, com o loop em **2 PARES** (`hint-placeholder-count="2"`, `:181`) | `MapaDeCobertura` entra com **4 células seguidas, sem rótulo** | **DESVIO DECLARADO** (§b) |
| rodapé | o rodapé | `:293` | `height:44px;padding:0 16px;border-top:#262626;background:#0D0D0D;12px #8A8A90` | `h-[44px]` → `height:44px` ✅ | ATENDIDO |
| vazio | a caixa | `:286` | `padding:64px 24px`, `gap:8px`, `border-top:#1F1F1F` | `py-[64px]` → `padding-top:64px;padding-bottom:64px` ✅ | ATENDIDO |
| vazio | o título | `:287` | `15px/600` "Nenhuma publicação encontrada" | implementado ✅ | ATENDIDO |
| status | anel de "Em revisão" | `:250` | `border:1.6px solid #5F5F68` | **`border-pub-neutral-dot` = `#71717A`** | **DIVERGÊNCIA** (§a4) |
| status | ícone de "Com erro" | `:249` | triângulo **PREENCHIDO** `#EF4444` com "!" escuro | `AlertTriangle` do Lucide, **contorno** | **DIVERGÊNCIA** (§a4) |

### a3. ⚠️ A ÚNICA MEDIDA EM QUE O PROTÓTIPO PERDEU — confirmada

`Publicacoes v2.dc.html:566`, lido por extenso:

```js
const prod = prodMap[pid];
const pubs = byProd[pid].sort((a, b) => b.ph - a.ph || (a.t === 'premium' ? -1 : 1) || b.d.localeCompare(a.d));
```

**Dois defeitos mecânicos, os dois confirmados por leitura:**

1. **O terceiro termo é INALCANÇÁVEL por construção.** `(a.t === 'premium' ? -1 : 1)` devolve `-1` ou
   `1` e **nunca `0`** ⇒ o `||` nunca chega ao `localeCompare`. **O desempate por data nunca roda.**
2. **O comparador é INCONSISTENTE.** Para dois clássicos, `cmp(a,b)` e `cmp(b,a)` devolvem os dois
   `1`. Replicar isso daria **ordem instável entre requests** — exatamente o que o tie-break do D-12
   existe para evitar.

➡️ **O plano 180-04 implementou a INTENÇÃO** (fase DESC → Premium antes → data DESC) com um
comparador consistente + `ml_item_id ASC` como tie-break estável, e escreveu o motivo no docblock de
`gruposPorProduto()`. ⚠️ **Esta é a PRIMEIRA e ÚNICA divergência da milestone v26.0 em que o
protótipo não ganha** — e é sobre um **defeito mecânico do mock**, não sobre aparência. **Confirmo a
decisão.**

### a4. ⚠️ AS DUAS DIVERGÊNCIAS DE `identidade/` — RECONFIRMADAS POR MEDIÇÃO PRÓPRIA

Medi no código, não copiei da SUMMARY do 180-08:

| medida | o protótipo | o código | prova |
|---|---|---|---|
| anel de "Em revisão" | `:250` → `border:1.6px solid #5F5F68` | `SeloDeStatusDaPublicacao.jsx:62` → `border-pub-neutral-dot` = **`#71717A`** | 18 pontos mais CLARO |
| ícone de "Com erro" | `:249` → triângulo **preenchido** `#EF4444` | `SeloDeStatusDaPublicacao.jsx:54` → `AlertTriangle` do Lucide (**contorno**) | a forma do status mais grave é contorno onde o protótipo quer massa |

⚠️⚠️ **E a sonda de CSS deste fechamento PROVOU a primeira delas de forma independente:** das 54
medidas, a **única** ausência foi `border-pub-neutral-ring` — **REGRA AUSENTE do CSS construído**.
A causa, medida:

```
tailwind.config.js:208   'neutral-ring': '#5F5F68',     <- o token EXISTE
grep -rn "pub-neutral-ring" resources/js  ->  0 usos    <- ORFÃO: nenhum .jsx o usa
```

➡️ O plano 180-01 criou `pub-neutral-ring` **exatamente para este anel** (está escrito no comentário
do config) e ele ficou **órfão de uso**, porque consertar exige editar `identidade/` — **território
proibido nesta fase, por instrução escrita, duas vezes**. **As duas divergências ficam para uma fase
que possa tocar `identidade/`.** O conserto é aditivo nos dois casos (trocar uma classe; trocar um
ícone por SVG inline).

### a5. ⚠️ `cursor:pointer` — a omissão mais repetida, e a contagem que NÃO fecha

**Medido no protótipo: 31 ocorrências de `cursor:pointer`**, nas linhas
`:40 :61 :62 :74 :86 :96 :106 :112 :115 :118 :122 :129 :141 :148 :157 :164 :173 :202 :203 :214 :289 :315 :317 :319 :349 :350 :393 :407 :408 :409 :410`.

**O contador corrido das SUMMARYs chega a 11** (o 180-09 chama a sua linha de grupo de "a 10ª
omissão dele na fase" e o botão do vazio é a 11ª). ⚠️ **Mas a soma dos itens por tabela dá 16**
(180-05: 3 · 180-06: 2 · 180-07: 5 · 180-08: 4 · 180-09: 2). ➡️ **A divergência é no CONTADOR, não
nas medidas:** o 180-07 escreve ao mesmo tempo *"omitido CINCO vezes nesta tabela"* e *"a quinta vez
na fase"*, o que desalinha a numeração dali para frente. **Registro os dois números e digo qual é
qual, em vez de escolher o que soa melhor.** O veredicto não muda: **o protótipo ganhou nas 16, e a
classe está no código** — medi **21 `cursor-pointer`** nos 14 `.jsx` de `publicacoes/`:

```
AbasDeStatus 2 · BuscaDePublicacoes 2 · CabecalhoDaTabela 1 · CabecalhoDePublicacoes 2
CelulasDaLinha 1 · ChipsDeFiltro 6 · FaixaDeSucesso 3 · LinhaDeControles 1
LinhaDeGrupoDeProduto 1 · LinhaDePublicacao 1 · VaziosDaLista 1          = 21
```

### a6. A incoerência do protótipo com ele mesmo — confirmada

```
:644  footerLabel:    … + ' publicações · ' + prodCount + (prodCount === 1 ? ' produto' : ' produtos')
:626  resultSummary:  … + (list.length === 1 ? ' publicação' : ' publicações') + ' · ' + …
```

**Na mesma tela**, `footerLabel` nunca flexiona "publicações" e `resultSummary` flexiona.
O 180-09 implementou a **intenção** (plural certo nos dois) — *"1 de 1 publicações"* seria erro de
português na tela. **Confirmo.**

---

## (b) A VARREDURA DE DESVIOS DECLARADOS — lista FECHADA

⚠️ **Desvio declarado é aceitável; desvio escondido não.** Todo item abaixo tem SUMMARY que o declara.

| # | desvio | quem decidiu | onde está declarado |
|---|---|---|---|
| 1 | **"Rascunhos" entra no trilho de status** (o protótipo não tem a opção) | **usuário, 11/10/2026** (LIST-25) | 180-06 §1b/decisões |
| 2 | ⚠️ **"No ar \| Histórico" REMOVIDO**, rota ÓRFÃ | **usuário, 13/10/2026** | **§b2** (por extenso) |
| 3 | **os chips de triagem** (não existem no protótipo) | critério 14 do ROADMAP + LIST-22 | 180-07 §5 |
| 4 | **a escala de z-index**: protótipo header 50 / popover 40 / backdrop 30 → aqui **popover e backdrop em `z-10`** | 180-07, por colisão medida (header é `z-30`, menu lateral do `AppLayout` é `z-40`; transpor o 40 cru inverteria a ordem que `publicador-header-zindex-ordem.test.js` trava) | 180-07 decisões + §4c |
| 5 | **o rótulo de ordenação**: "Mais graves primeiro", não "Mais recentes primeiro" | 180-06, porque o servidor ordena por `severidade` (D-12) e **rótulo que mente é pior que rótulo diferente** | 180-06 §3 |
| 6 | **a célula Preço mostra `—` na MARGEM** (o valor já chega) | LIST-12 (a tarifa do ML não está no banco) → Fase 181 | 180-04B §1 · 180-08 §4 |
| 7 | **o painel de 460px e a barra de lote ausentes** | Fase 181 | 180-10 §5 pendências 9 |
| 8 | **"Exportar CSV" ausente** | **usuário, 11/10/2026** (dispensado) | REQUIREMENTS-v26 |
| 9 | **`MapaDeCobertura` com 4 células seguidas**, sem os rótulos F1/F2 (o protótipo faz 2 pares com rótulo, `:181`-`:183`) | 180-09 §1, escolha (b): `identidade/` é território proibido | 180-09 §1, com a receita de conserto |
| 10 | **o comparador do grupo**: a INTENÇÃO, não o comparador quebrado | 180-04 §7 | **§a3** |
| 11 | **a CONTAGEM do resumo não é feita no cliente** (o protótipo usa `list.length`, `:626`) | 180-06, por D-05 (paginação de 50 sobre dezenas de milhares) | 180-06 decisões |
| 12 | **o `.18s` do chevron vai por `style`**, não por `duration-[180ms]` | 180-09 §3: **a classe NÃO COMPILA** neste projeto | **§h** |
| 13 | **a opção de visão NÃO leva `display:flex` nem `gap`** (a tabela do plano dizia "igual à aba") | 180-06 §4c: o protótipo declara `display:flex` em `:86` (aba) e **não** em `:96` (visão) | 180-06 §4c |
| 14 | **o span do sufixo do chip existe SEMPRE, inclusive vazio** | 180-07: é item flex e o `gap:6px` conta com ele — omiti-lo estreitaria o chip em 6px | 180-07 decisões |
| 15 | **`rel="noreferrer"` ACRESCENTADO** ao botão do ML (o protótipo, `:272`, não o tem) | 180-08, T-180-08-T: sem ele a aba do ML ganha `window.opener` | 180-08 §4 |
| 16 | **`disabled` + `cursor-wait` no "Atualizar"** (acréscimo) | 180-05, por LIST-23 (o cooldown de 30 s é comportamento de hoje) | 180-05 débito |
| 17 | **`ml-auto` migra para "Ver produto"** quando a contagem não nasce | 180-09 §2d, acréscimo declarado | 180-09 §2d |
| 18 | **debounce de 400 ms na busca** (o protótipo usa `<form>` + Enter) | 180-10 desvio 8: o `BuscaDePublicacoes` não tem form e LIST-18 dá outro papel ao Enter | 180-10 §8 |
| 19 | **guarda de MODIFICADOR nos atalhos** (o protótipo não a tem) | 180-11, por colisão medida com o ⌘K/Ctrl+K e o N do `PublicadorHeader` | 180-11 §1 |
| 20 | **`move()` parte de `foco ?? sel`**, invertendo a prioridade do protótipo (`selId \|\| focusId`) | 180-11 §3: aqui `sel` não se move com as setas, e partir dele prenderia o foco para sempre | 180-11 §3 |
| 21 | ⚠️ **o 2º botão "Atualizar" SAIU da página** (o protótipo tem o botão no cabeçalho, `:40`) | **usuário, 14/10/2026** | **§b4** |
| 22 | ⚠️ **o SKU por VARIAÇÃO voltou à linha** (o protótipo não desenha SKU na linha) | **usuário, 14/10/2026** | **§b4** |
| 23 | **o rótulo "SKU" fica** (o protótipo mostra o código cru em `:178`/`:382`) | 180-10B: ali a COLUNA identifica; aqui o código ficaria colado ao MLB, e código nu é jargão | 180-10B §3 |
| 24 | **"Em revisão" + com erro: o status real do ML ganha** — nada implementado | **usuário, 14/10/2026** | **§b4** |
| 25 | **o nome longo do produto NÃO é truncado** na linha de grupo | 180-09 §2d: o protótipo não declara `overflow`; truncar seria "adaptar" | 180-09 §2d |
| 26 | **`CelulaSku` e `rotacaoN` e `subTotais` e `BotaoAtualizarDoCabecalho` ficam ÓRFÃOS DECLARADOS** | 180-10 §4 / 180-10B §2: apagar exigiria **REMOVER asserções** do gate dono, e o padrão do projeto é reaponte com veto | 180-10 §4 · 180-10B §2 |

⚠️ **Nenhum item desta lista é "descoberta" deste fechamento: todos os 26 estão numa SUMMARY.**
Não achei desvio escondido.

---

## (b2) ⚠️⚠️ A SUPERAÇÃO DO CRITÉRIO 13 — "No ar | Histórico"

> **O critério 13 do ROADMAP nomeia "sub-abas No ar|Histórico" na lista do que NÃO pode regredir.
> ESSE ITEM FOI SUPERADO, por decisão informada do usuário.** Os quatro elementos, por extenso —
> **sem eles, uma sessão futura lê isto como perda acidental e "restaura".**

### 1. A DATA

**13/10/2026.**

### 2. A PERGUNTA, as TRÊS OPÇÕES e o AVISO LITERAL

Ao usuário foram apresentadas **três** opções. A que ele escolheu veio acompanhada do aviso literal:

> *"a tela nova fica mais limpa já nesta fase, mas o conteúdo do Histórico fica inacessível até a
> fase que o reconstruir; **a rota fica órfã, sem nenhum link vivo para ela**"*

### 3. A MEDIÇÃO QUE MOTIVOU A PERGUNTA (a guarda do LIST-25 disparando)

A guarda obrigatória do LIST-25 é *"o atalho sai, o DADO não"*. Ela **DISPAROU**: `meus()` e
`historico()` leem **tabelas diferentes** e são **conjuntos diferentes**, e
`SubAbasDePublicacoes.jsx:97` era o **único link vivo** para a rota.

| | `meus()` (esta tela) | `historico()` |
|---|---|---|
| função | `MlbAnuncioController.php` | **`:323`-`:478`** |
| tabela | `ml_acervo_itens` | `pub_publicacao_itens` ⨝ `pub_publicacoes` ⨝ `pub_rascunhos` ⨝ `pub_produtos` (**`:342`-`:344`**) |
| unidade | o **anúncio** | o **LOTE** (`categoria + dia de conclusão`), reconstruído em PHP em **`:408`-`:418`** — ⚠️ **não existe coluna de lote** |
| busca | `title` / `ml_item_id` / `skus` | `payload->family_name` / `pub_produtos.sku` (**`:359`-`:360`**) |
| exclusivos | — | o **payload ENVIADO** ao ML (`family_name` **`:429`**, `price` **`:430`**), a **foto** de `pub_imagens.ml_url` (**`:385`-`:404`**), `sku_origem` por `PubProduto::skuExibido()` (**`:432`**), `pode_duplicar` (**`:443`**), `total_lotes` (**`:474`**) |

➡️ **São conjuntos DIFERENTES, não recortes um do outro.** O filtro de status desta tela **não
alcança nada** da coluna da direita. **Por isso a fase PAROU e perguntou**, como o LIST-25 manda.

### 4. A ESCOLHA DELE

> **"Sai agora, Histórico vira fase própria."**

➡️ **LIST-25 vence o critério 13 NESTE ITEM.**

### ⚠️ AS DUAS FRASES DE GUARDA

> ### **NÃO RESTAURAR**
> **Não restaure** o segmentado "No ar | Histórico" achando que foi perdido por engano. **Foi
> removido de propósito, em 13/10/2026, por pedido escrito do usuário, entre TRÊS opções, com o
> aviso de que a rota ficaria órfã.** Restaurar exige **decisão NOVA dele**.

> ### **NÃO APAGAR**
> **Não apague** a rota, o `historico()`, o `AnunciosHistorico.jsx`, o `anuncioHistoricoUtils.js`,
> o `SubAbasDePublicacoes.jsx` nem os testes deles. **A rota ficou órfã DE PROPÓSITO** e o conteúdo
> é o **insumo da fase futura do Histórico**. Ele autorizou tirar o **controle**, não perder o
> **conteúdo**.

### ⚠️ É O MESMO PADRÃO DO D-8 DA FASE 179

Esta milestone tem **DUAS** exceções explícitas do usuário à regra-mestra *"melhorar sem regredir"*,
as duas autorizadas por escrito:

1. **12/10/2026** — os dois blocos que saíram da Visão Geral (learnings §10: *"não recriar sem
   decisão NOVA dele"*);
2. **13/10/2026** — o "No ar | Histórico" (aqui).

⚠️ **E a OUTRA metade do LIST-25 está satisfeita, não superada:** o segmentado separado
"Publicados | Rascunhos" saiu, mas **o DADO ficou** — `'rascunhos' => $totalDeRascunhos` aparece
**DUAS** vezes no controller (em `subTotais` e em `contagens`), **é literalmente a mesma variável**,
`sub` segue na URL e `trocarSubAba` segue indo ao servidor. **Só o atalho mudou de lugar.**

### A PROVA, MEDIDA AGORA (não copiada)

| alvo | `test -e` | diff `f23f610d..HEAD` | prova |
|---|---|---|---|
| `routes/mlb_anuncios.php` | existe | **+43 / −0**, de `279e8fac feat(184-11)` — **NÃO é desta fase** | `:473` → `Route::get('/historico/{company}', [MlbAnuncioController::class, 'historico'])->name('historico')` **VIVO**; **0 deleções** |
| `resources/js/Pages/Mlb/AnunciosHistorico.jsx` | existe | **0** | `:7` importa e `:292` **monta** `SubAbasDePublicacoes` |
| `resources/js/Pages/Mlb/anuncioHistoricoUtils.js` | existe | **0** | — |
| `resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx` | existe | **0** | **o COMPONENTE está intocado** |
| `tests/Feature/Phase86/HistoricoAnunciosTest.php` | existe | **0** | — |
| `tests/js/estrutura-anuncios-historico.test.js` | existe | **0** | **11 / 11 pass / 0 fail** |

**E a prova SEMÂNTICA do controller** (diff vazio ali seria mentira — o plano 180-04 o edita):

```
public function historico(      -> 1 ocorrência
$chaveLote                      -> 4
'pode_duplicar' => false        -> 1
payload->family_name            -> 2
pub_publicacao_itens            -> 15
total_lotes                     -> 1
```

### ⚠️ E A ARMADILHA QUE ESTE FECHAMENTO PEGOU: o grep que casa com o COMENTÁRIO

`grep -c SubAbasDePublicacoes resources/js/Pages/Mlb/MeusAnuncios.jsx` devolve **3**. Um gate
`== 0` **reprovaria o código CERTO**. Medido linha a linha, **as três são COMENTÁRIO**:

```
:52    // 1. O segmentado "No ar | Histórico" (`<SubAbasDePublicacoes atual="meus">`)
:61    //    `anuncioHistoricoUtils.js`, o componente `SubAbasDePublicacoes.jsx`
:1099  {/* ⚠️ Aqui ficava `<SubAbasDePublicacoes atual="meus" …>`, o */}
```

➡️ **Zero mount, zero import. As três ocorrências são as frases ANTI-REVERSÃO.** É a 6ª vez nesta
milestone em que um `grep` casa com o comentário que explica a regra.

---

## (b3) O INSUMO DA FASE FUTURA DO HISTÓRICO — a lista PRONTA

⚠️ **Este plano NÃO toca o `ROADMAP.md`.** Devolvo a lista; quem registra a fase é o orquestrador.

**Tudo o que SÓ o `historico()` alcança e que ficou inacessível pela tela:**

| o que | `arquivo:linha` | por que só existe lá |
|---|---|---|
| o **agrupamento por LOTE** | `MlbAnuncioController.php:408`-`:418` (`$chaveLote`) | ⚠️ **não existe coluna de lote**: a chave é reconstruída em PHP de `categoria + dia de conclusão` |
| o **payload ENVIADO** ao ML — `family_name` | `:429` | é o que foi mandado, não o que o ML devolveu |
| o **payload ENVIADO** ao ML — `price` | `:430` | idem |
| a **FOTO** de `pub_imagens.ml_url` | `:385`-`:404` | o acervo não guarda a imagem que o Publicador enviou |
| `sku_origem` por `PubProduto::skuExibido()` | `:432` | o SKU **de origem** do produto, não o da variação |
| `pode_duplicar` | `:443` | o gate de "Anunciar semelhante" a partir do lote |
| `total_lotes` | `:474` | a contagem de lotes do recorte |
| a **busca** por `payload->family_name` / `pub_produtos.sku` | `:359`-`:360` | a busca desta tela não olha o payload enviado |
| o **4-join** inteiro | `:342`-`:344` | `pub_publicacao_itens` ⨝ `pub_publicacoes` ⨝ `pub_rascunhos` ⨝ `pub_produtos` |

➡️ **A fase futura do Histórico devolve o ACESSO a este conteúdo no layout novo.** Hoje a página
**continua abrindo por URL direta** (`/mlb/anuncios/historico/459`) — só não tem link.

---

## (c) O AUDIT DE GATE VAZIO — "o que precisaria estar errado para este gate falhar?"

Varri as **nove famílias** do learnings §4 sobre os **13 gates** da fase (10 de JS + 3 de PHP).
⚠️ **`test -e` em cada um ANTES de acreditar em qualquer resultado: 13/13 existem, 0 caminho fantasma.**

| família | achados | detalhe |
|---|---|---|
| 1. `/disabled/` sem o `=` | **0** | os gates usam `/disabled=/` com o `=`, como o learnings manda |
| 2. `doesNotMatch(/0%/)` casando em `100%` | **0** | — |
| 3. `git diff --stat --` **depois de commitar** (compara a árvore, vazio SEMPRE) | **0** | nenhum gate usa `--stat` sem range; os gates de diff usam `<inicial>..HEAD` |
| 4. `new RegExp(\`\b…\`)` em template literal (ali `\b` é BACKSPACE) | **0 em 24 template literais** | todos usam **DUPLO** escape (`\\s`, `\\w`, `\\{`), que é correto. ⚠️ **ISCA: a minha sonda achou 2 defeitos numa linha-isca com `` `\b${x}\b` `` ⇒ ela não está cega** |
| 5. `/href="/` sem `\s` casando em `data-href` | **0 reais** | há `/href="#"/` e `/href="\/p\/2"/`; medi que **`data-href` tem 0 ocorrências** em `publicacoes/` e em `MeusAnuncios.jsx` ⇒ nenhum caminho de falso positivo |
| 6. `html.indexOf('<tag')` pegando o IRMÃO errado | **0 reais, 1 fragilidade declarada** | 20 usos, ancorados em literais únicos (`<div role="tablist"`, `role="group"`, `role="menu"`, `<h2`, `<div class="mt-[12px]`) ou em "o PRIMEIRO `<div>` do fixture", que é determinístico. ⚠️ **Fragilidade declarada abaixo** |
| 7. `includes('classe')` com substring/prefixo | **0** | os usos flagrados são `classesDa(tag).includes(...)` — **lista**, logo igualdade exata — ou literais exclusivos (`font-pub-mono">—`). O 180-07 já tinha consertado os 4 furos desta família nele mesmo |
| 8. ANSI comendo o veredicto | **não se aplica aos gates** | **nenhum** dos 13 gates lê saída de `artisan test`/`npm`. As **campanhas de mutação** o removiam com `sed 's/\x1b\[[0-9;]*m//g'` a partir do 180-04 (o 180-03 foi mordido e registrou) |
| 9. asserção de ausência rodada com dado VAZIO | **0** | padrão da fase: a negativa roda com `ITEM_CHEIO` / props cheias, **com ISCA provando que a positiva ESTÁ lá** |

### ⚠️ O QUE EU ACHEI: um gate frágil, e um MEDIDOR MEU que mentiu

**1. FRAGILIDADE DECLARADA (não conserto — território de teste de outro plano, e a instrução desta
rodada é MEDIR, não corrigir):** os gates que localizam por `HTML_X.indexOf('<div')` medem o
**elemento RAIZ** do fixture. Isso é determinístico hoje, **mas se alguém embrulhar o componente num
`<div>` novo, o gate passará a medir o WRAPPER em silêncio** — é a família 6 com outra roupa.
➡️ **Recomendação para a Fase 181:** ancorar no `role`/`aria` ou no texto único, nunca no "primeiro
`<div>`". **Casos:** `publicador-publicacoes-filtros.test.js:545`, `:760`, `:1397`.

**2. ⚠️ O MEU PRÓPRIO MEDIDOR MENTIU, e foi vazio AO CONTRÁRIO — casou TUDO.** A minha primeira
sonda da família 4 era `grep -E 'new RegExp\(`[^`]*[^\\]\\b'` no shell. **Ela devolveu as 24 linhas**,
ou seja, acusaria todas. Causa: no `grep -E` do GNU, `\\b` é ambíguo (barra escapada + `b`, ou borda
de palavra) e o padrão degenerou. ➡️ **Refeito em ARQUIVO (`.cjs`), com isca** — e aí o resultado foi
**0 suspeitas em 24**, com a isca achando 2. **É a lição do learnings §3 ("regex em ARQUIVO, nunca
no shell") cometida por mim, neste fechamento, e registrada em vez de maquiada.**
**Placar da rodada: 1 medidor meu mentiu, 0 defeitos de código.**

---

## (d) O RESUMO DE MUTAÇÃO DA FASE

| plano | aplicadas | pegas | recusadas | sobreviventes | controles negativos recusados |
|---|---|---|---|---|---|
| 180-01 | 6 | 6 | 0 | 0 | — |
| 180-02 | 10 | 10 | 0 | 0 | — |
| 180-03 | 12 | 12 | 0 | 0 | — |
| 180-04 | 13 | 13 | 0 | **1 → investigada** | 1 |
| 180-04B | 7 | 7 | 0 | 0 | 1 |
| 180-05 | 8 | 8 | 0 | 0 | — |
| 180-06 | 10 | 10 | 0 | 0 | — |
| 180-07 | 11 | 11 | 0 | 0 | — |
| 180-08 | 9 | 9 | 0 | 0 | 3 |
| 180-09 | 8 | 8 | 0 | 0 | — |
| 180-10 | 16 | 16 | **1 → refeita** | **1 → investigada** | — |
| 180-10B | 8 | 8 | 0 | 0 | 3 |
| 180-11 | 8 | 8 | 0 | 0 | 5 |
| **TOTAL** | **126** | **126** | **1** | **2** | **13** |

### AS DUAS SOBREVIVENTES — investigadas, não presumidas equivalentes

⚠️ **Nas duas a sobrevivente era LACUNA DE COBERTURA REAL.** É a 4ª e a 5ª vez na milestone.

| # | mutação | o que ela PROVOU que faltava | foi fechada? |
|---|---|---|---|
| **180-04 (j)** | tirar o 4º argumento do `escopo()` — **desligar a busca por nome de produto** | O fixture usava `ml_item_id = 'MLB-OSLO'` e o termo era `'Oslo'`: **o 2º braço da busca (`ml_item_id LIKE`) casava sozinho.** O caso provava que a busca acha por CÓDIGO MLB, não por nome de produto — e era o **único** gate de que o trabalho do 180-03 chega à tela (**LIST-03**) | ✅ **SIM.** `ml_item_id` → `MLB7000000001`, título `'Assento decorativo veludo cinza'`, SKU `'XYZ-1'` — **nenhum** dos outros 3 identificadores contém o termo. Refeita (`j2`), **reprovou** |
| **180-10 (vii)** | inverter a regra da busca (`const aberto = buscaAtiva` → `!buscaAtiva`) | **No render de servidor `colapsados` é SEMPRE `[]`** (estado inicial), então `!colapsados.includes(x)` é sempre verdadeiro e o `\|\|` devolve `true` de qualquer jeito. **A regra do LIST-09 não era observável em render nenhum** — nem com busca, nem sem | ✅ **SIM.** Virou a função pura `grupoAberto(buscaAtiva, colapsados, chave)`, com 7 casos. Refeita **na regra**, reprovou; e a mutação **xvi** (sempre `true`) também |

➡️ **RECEITA, válida daqui para frente:** *regra de UI com duas entradas que o render estático não
distingue → extraia para FUNÇÃO PURA e teste as combinações.* **Nenhuma sobrevivente foi deixada
como dívida.**

### A recusa e os 13 controles negativos

- **180-10 (iv)** foi **RECUSADA** na 1ª rodada: `skuNaoColetado={skuNaoColetado}` tinha **2**
  ocorrências (a do card e a do adaptador `VazioDaListagem`). Refeita com a indentação que a torna
  única. **É a §6.1 do learnings funcionando.**
- **13 controles negativos** (alvos com 2, 2, 2, 6, 8, 8, 8, 9, 9, 10, 17, 20 ocorrências e **1
  inexistente**) foram **RECUSADOS com `sha256` INTACTO** ⇒ a trava de contagem **não é decorativa**.

### ⚠️ As três armadilhas de medição que as campanhas pegaram (e que mudam a receita)

1. **ANSI (180-03):** a campanha filtrava o veredicto por `grep` e os códigos de cor comeram **as 12
   linhas de resultado**. O log saiu "verde" **sem ter provado nada**. ➡️ `sed` do ANSI **antes** do
   grep, em todas as campanhas seguintes.
2. **Contagem de testes (180-08):** um `sed` de renome pegou a **declaração** da função junto com os
   call sites → `SyntaxError` → **1 teste em vez de 107, com 0 falhas.** ➡️ comparar a **CONTAGEM**.
3. ⚠️ **DOIS REGIMES de contagem (180-10B):** a sonda de CSS entra em `skip` por `mtime`, e **mutar
   o arquivo o deixa mais novo** ⇒ o mesmo gate reporta **119 com o arquivo pristino e 114 com ele
   mutado** (noutro, **130 × 118**). Travar na contagem "normal" fez a 1ª execução abortar; **sem**
   a trava, a campanha **inteira** marcaria "SOBREVIVEU" por um motivo que não é o código.
   ➡️ **Medir as DUAS contagens antes da campanha.** É a armadilha que se esconde **dentro** da
   defesa contra as outras.
4. ⚠️ **O guard da contagem CEGO (180-10):** o reporter prefixa com `ℹ` (UTF-8 de 3 bytes) e o
   `grep -E '^. tests [0-9]+$'` **não casava** — as 15 primeiras mutações saíram com `TESTS=0` e a
   campanha "parecia" ter medido. ➡️ **âncora na PALAVRA, nunca na coluna.** (Usei a forma corrigida
   neste fechamento: `grep -E '(^|[^a-z])tests [0-9]+$'`.)

---

## (e) A COBERTURA — os 25 REQ-IDs e os 16 CRITÉRIOS

⚠️ **São 25 e não 24.** O `LIST-25` entrou **entre o 23 e o 24** em 11/10/2026 (decisão do usuário) e
a linha `**Requirements**: LIST-01..25` do ROADMAP está certa; o que ficou defasado foi a *contagem*
citada em prosa pelos planos. **Veredicto só pode ser ATENDIDO, ATENDIDO COM DESVIO DECLARADO ou NÃO
ATENDIDO. Nenhum "parcialmente".**

### e1. Os 25 REQ-IDs LIST

| id | onde foi implementado | onde foi provado | veredicto |
|---|---|---|---|
| **LIST-01** | `publicacoes/CabecalhoDePublicacoes.jsx` + `sincronizacao` em `MlbAnuncioController` | `publicador-publicacoes-cabecalho.test.js` (**74/74**) · `PublicacoesDaTelaTest::sincronizacao_manda_rotulo_humano_e_nunca_data_crua` | **ATENDIDO COM DESVIO DECLARADO** (desvio 21: o botão da PÁGINA saiu por decisão do usuário de 14/10; o do header global ficou, e o "● Sincronizado há X" ficou) |
| **LIST-02** | `publicacoes/FaixaDeSucesso.jsx` + `faixaSucesso` | `publicador-publicacoes-cabecalho.test.js` (`a faixa não existe sem resumo`) · `PublicacoesDaTelaTest::faixa_de_sucesso_e_nula` · `paginas-anuncios-render` (`a LIGAÇÃO é real: com resumo do servidor a faixa aparece`) | **ATENDIDO** — o layout está pronto, ligado e provado nos dois sentidos. ⚠️ **`faixaSucesso` é `null` SEMPRE** (3 caminhos medidos) ⇒ **a faixa nunca aparece hoje**. É ausência de FONTE no sistema, não lacuna desta fase, e **nenhum resumo de exemplo foi fabricado** (EXMP-01). Pergunta aberta no checkpoint |
| **LIST-03** | `AcervoTriagemService::escopo()` 4º braço + `PublicacoesDaListaService::mlItemIdsPorProduto()` + `BuscaDePublicacoes.jsx` | `AcervoBuscaEFiltrosTest::test_busca_acha_pelo_nome_do_produto` · `PublicacoesDaTelaTest::busca_por_nome_de_produto_chega_a_tela` (**a mutação (j) que sobreviveu e foi consertada**) · CSS: `h-[54px]`, `rounded-pub-table`→14px, `focus:ring-pub-accent/[0.18]`→`rgb(250 204 21 / .18)` | **ATENDIDO** |
| **LIST-04** | `BuscaDePublicacoes.jsx` (`fraseDoResumo`, `temBusca`) | `publicador-publicacoes-busca.test.js` (**114/114**): os dois estados da direita, nunca juntos | **ATENDIDO** — com `resumo={null}` aparece o "Limpar" sozinho, que é honesto. ⚠️ **"N produtos" da BUSCA não tem fonte** (medido no 180-06 §5a); o do **rodapé** tem (`rodape.produtos`) e está na tela |
| **LIST-05** | `publicacoes/AbasDeStatus.jsx` + `abas`/`contagens` (1 consulta agregada + 1 para `com_erro`) | `publicador-publicacoes-busca.test.js` (`a aba ativa é a certa` ×6, `o dot sai de comDot`) · `PublicacoesDaTelaTest::contagens_sao_sobre_os_demais_filtros` + `agregado_bate_com_o_servico_nas_sete_chaves` | **ATENDIDO COM DESVIO DECLARADO** (desvio 1: "Rascunhos" entra no trilho, LIST-25) |
| **LIST-06** | `publicacoes/LinhaDeControles.jsx` | `publicador-publicacoes-busca.test.js` (`o rótulo da ordenação diz a VERDADE`, `ZERO radiogroup novo`) | **ATENDIDO COM DESVIO DECLARADO** (desvio 5: "Mais graves primeiro", porque o servidor ordena por `severidade` — D-12 — e rótulo que mente é pior) |
| **LIST-07** | `publicacoes/ChipsDeFiltro.jsx` + `MenuDeFiltro.jsx` + os 7 params saneados no controller | `publicador-publicacoes-filtros.test.js` (**112/112**) · CSS pelo **BYTE do alfa**: `#facc1529` × `#facc151f` × `#facc158c` | **ATENDIDO COM DESVIO DECLARADO** (desvio 4: z-index como ORDEM, `z-10`). ⚠️ **O chip "Responsável" NÃO NASCE**: `responsavel.opcoes` não tem fonte ⇒ com `[]` o chip não é desenhado (EXMP-01). Pergunta aberta |
| **LIST-08** | `publicacoes/CabecalhoDaTabela.jsx` (`GRID_DA_TABELA`, UMA casa) | `publicador-publicacoes-tabela.test.js` (**130/130**) · CSS: `grid-template-columns:40px minmax(300px,1fr) 136px 104px 136px 112px 68px 150px 64px` **byte a byte**, com o vizinho `140px` provado AUSENTE | **ATENDIDO** |
| **LIST-09** | `publicacoes/LinhaDeGrupoDeProduto.jsx` + `grupos` do controller + `grupoAberto()` | `publicador-publicacoes-grupos.test.js` (**81/81**) · `estrutura-meus-anuncios.test.js` (`a regra de LIST-09 nas QUATRO combinações`) · `PublicacoesDaTelaTest::grupo_filtrado_mostra_x_de_n` | **ATENDIDO COM DESVIO DECLARADO** (desvio 9: `MapaDeCobertura` com 4 células seguidas, sem os rótulos F1/F2) |
| **LIST-10** | `publicacoes/LinhaDePublicacao.jsx` + `CelulasDaLinha.jsx` (visão Lista) | `publicador-publicacoes-tabela.test.js` (`a célula Publicação` ×6) · `PublicacoesDaTelaTest::visao_lista_nao_manda_grupo` | **ATENDIDO** |
| **LIST-11** | `CelulasDaLinha.jsx` (`CelulaPublicacao`) + `preco`/`nova` no item (180-04B) | `publicador-publicacoes-tabela.test.js` (`copiar o MLB` ×5, inclusive a **assimetria** do `linecap` em `:215` × `:216`) · `PublicacoesDaTelaTest::nova_e_publicada_hoje_e_nunca_a_data_da_coleta` | **ATENDIDO** — ⚠️ o badge NOVA e o `bg-pub-row-new` só acendem no DIA CIVIL da publicação (régua medida por triangulação); o timer de 1,3 s é provado pelo **literal**, não pelo runtime |
| **LIST-12** | `CelulasDaLinha.jsx` (`CelulaPreco`) + `'preco' => $item->price` (180-04B) | `publicador-publicacoes-tabela.test.js` (`o — da margem` com o item **CHEIO**, `Prejuízo e vírgula`) · `PublicacoesDaTelaTest::preco_zero_e_medida_e_nulo_e_ausencia` + `item_nao_tem_margem_nem_lucro` | **ATENDIDO COM DESVIO DECLARADO** (desvio 6: a 2ª linha — "Margem x%" / "▲ Prejuízo" — fica em `—` porque a **tarifa do ML não está no banco**; é a camada assíncrona da Fase 181, exatamente como LIST-12 escreve). ⚠️ `preco: null` → `—` e `preco: 0` → `R$ 0,00`, **provados diferentes** |
| **LIST-13** | `CelulasDaLinha.jsx` (`CelulaPublicada`) + `autor_nome`/`autor_tipo` de `PublicacoesDaListaService` | `publicador-publicacoes-tabela.test.js` (`o autor` ×7, com 5 linhas e 5 autores distintos) · `PublicacoesDaListaTest::test_autor_nunca_e_inventado` + `test_nome_de_cliente_nunca_sai_no_payload` | **ATENDIDO** — os 4 literais são TEXTO; `iniciais('Autor não registrado')` = `'AN'` foi **recusado** (pareceria um nome); data por **REGEX**, zero `new Date(` |
| **LIST-14** | `CelulasDaLinha.jsx` (`CelulaAcoes`) | `publicador-publicacoes-tabela.test.js` (`as ações` ×6, com ISCA provando que com permalink real o botão ESTÁ lá) | **ATENDIDO COM DESVIO DECLARADO** (desvio 15: `rel="noreferrer"` acrescentado; e o chevron **cede o lugar** quando "Abrir rascunho" existe, porque 3×28px+gap não cabem em 64px) |
| **LIST-15** | `LinhaDePublicacao.jsx` (`fundoDaLinha`) + os 5 tokens do 180-01 | `publicador-publicacoes-tabela.test.js` (`os CINCO fundos` ×5, `62 × 50` ×4) · CSS: **5 `rgb()` distintos**, com `Map` provando que não há repetição | **ATENDIDO** |
| **LIST-16** | `publicacoes/RodapeDaTabela.jsx` + `rodape` do controller | `publicador-publicacoes-grupos.test.js` (`o rodapé e a paginação` ×4) · `PublicacoesDaTelaTest::rodape_bate_com_o_paginator` + `paginacao_preserva_todos_os_filtros_nos_links` | **ATENDIDO** — a paginação de 50 é renderizada **como veio**, com `decodificarRotuloPaginacao()` preservado e endurecido; **zero `dangerouslySetInnerHTML`** |
| **LIST-17** | `publicacoes/VaziosDaLista.jsx` | `publicador-publicacoes-grupos.test.js` (`os quatro estados vazios` ×5, aspas CURVAS por code point) · `publicador-meus-anuncios-sku-render.test.js` (**10/10, diff VAZIO**) | **ATENDIDO** — os 3 vazios honestos ficaram; **2 textos foram CORRIGIDOS por medição** (`sem_anuncios` apontava para abas "Individual/Em massa" que **não existem**; `sem_resultado` prometia 3 identificadores e a busca agora tem 4) |
| **LIST-18** | `publicacoes/atalhosDaLista.js` (6 funções puras) + `MeusAnuncios.jsx` | `publicador-publicacoes-atalhos.test.js` (**28/28**): a cascata do Escape, os limites que não circulam, a guarda de modificador | **ATENDIDO** — ⚠️ o comportamento é provado pela **função pura** (decisão de cada tecla) e pelo **literal** (presença da peça); **o runtime é conferência visual** |
| **LIST-19** | `navegar()` com os 12 params + `selecionar()`/`limparSelecao()` | `publicador-publicacoes-atalhos.test.js` (caso 8 itens 6 e 7: `page: 1` existe **e vem ANTES** do `...params`) · `PublicacoesDaTelaTest::filtros_voltam_na_prop` + `sel_so_sobrevive_com_a_forma` | **ATENDIDO** — e o 180-11 achou um **bug latente NOVO**: como `navegar()` zera `page`, clicar numa linha da página 7 jogaria o usuário na página 1; `selecionar()` passou a mandar `page: paginaAtual` |
| **LIST-20** | `PublicacoesDaListaService::metodologiaPorMlItemId()` (4-join, UMA consulta) + `CelulasDaLinha.jsx` (`CelulaMetodologia`) | `PublicacoesDaListaTest` (**18 casos**) · `publicador-publicacoes-tabela.test.js` (`a metodologia` ×6, com o título cheio dos termos e `fase: null` → **Legado**) | **ATENDIDO** — ⚠️ **o caminho que o LIST-20 descreve está ERRADO** e isso foi medido duas vezes: `ml_acervo_itens.rascunho_id` aponta para `ml_anuncio_rascunhos` (assistente **antigo**), não para `pub_rascunhos`. A ponte real é `pub_publicacao_itens.ml_item_id`. **O requisito está atendido no ESPÍRITO; o texto dele precisa ser corrigido** (texto pronto em §f2) |
| **LIST-21** | `AcervoTriagemService` (os 3 braços antigos + o 4º; o arm `em_revisao`) | `AcervoBuscaEFiltrosTest::test_as_tres_emendas_continuam_intactas` + `test_busca_por_titulo_mlb_e_sku_continua_valendo` · `MeusAnunciosTest` **25/25, sem uma linha editada** | **ATENDIDO** |
| **LIST-22** | `publicacoes/ChipsDeTriagem.jsx` + `triagem` do controller | `publicador-publicacoes-filtros.test.js` (`LIST-22` ×6, com o número localizado **no nó do título** por árvore) · `AcervoBuscaEFiltrosTest::test_triagem_total_nao_e_a_soma_dos_chips` | **ATENDIDO** — o total é **sempre** `triagem.total`; a mutação (iv) do 180-07 (somar os chips) reprovou em **11** asserções |
| **LIST-23** | preservação item a item; `D-11` (um `router.post`, para a rota que **enfileira**) | `estrutura-meus-anuncios.test.js` (`180: critério 13`, **8 casos**) · as 3 asserções nominais `D-11`×2 e `D-22` **INTOCADAS** (`:250`, `:256`, `:285`, diff vazio) | **ATENDIDO COM DESVIO DECLARADO** — o desvio é **UM ITEM SÓ**: as sub-abas "No ar\|Histórico" (§b2). ⚠️ **Os outros SETE itens que o critério 13 nomeia estão ATENDIDOS LIMPO** — ver e2 |
| **LIST-24** | a Nota ECF migrou para o `title` **e** um `<span class="sr-only">` de `CelulasDaLinha.jsx` | `publicador-publicacoes-tabela.test.js` (`a Nota ECF` ×4) · `estrutura-meus-anuncios.test.js` `D-22` (reapontado de ARQUIVO, com as negativas da página **intactas**) | **ATENDIDO** — `de 86`, nunca `de 100`, nunca dividido/multiplicado pela base. ⚠️ **`aria-label` foi RECUSADO** com motivo medido: ele SUBSTITUI o nome acessível e apagaria "Pausada"; o `sr-only` é lido **em acréscimo** |
| **LIST-25** | o trilho com "Rascunhos" + o mount do segmentado removido | `estrutura-meus-anuncios.test.js` (`180: "No ar \| Histórico" NÃO está mais…` + `180: o que SÓ o Histórico alcança continua no servidor` + `180: Publicados \| Rascunhos`) · `paginas-anuncios-render.test.js` (`>Publicados<` ausente do corpo) | **ATENDIDO NAS DUAS METADES** — (1) **"No ar\|Histórico"**: o atalho saiu, **o dado ficou alcançável pela rota preservada** (§b2, 6 alvos com `test -e` e diff 0 + prova semântica do controller); (2) **"Publicados\|Rascunhos"**: o atalho mudou de lugar, **`sub`, a query e o contador ficaram** — `'rascunhos' => $totalDeRascunhos` é a MESMA variável nas duas props. **A guarda do LIST-25 foi cumprida: a fase PAROU e perguntou** |

➡️ **25/25 com veredicto. 0 "parcialmente". 0 NÃO ATENDIDO.**

### e2. ⚠️ O CRITÉRIO 13, ITEM POR ITEM — o desvio num item NÃO afrouxa os outros sete

| item do critério 13 | prova | veredicto |
|---|---|---|
| 1. banner de defasagem (**âmbar, nunca vermelho**) | `estrutura-meus-anuncios.test.js` caso 1 (os 3 tons do âmbar no CSS construído); mutações **ii** (remover) e **iii** (`pub-danger` em vez de `pub-warn`) do 180-10 **pegas** | **ATENDIDO** |
| 2. "Ainda não coletamos os anúncios desta empresa" | `publicador-publicacoes-grupos.test.js` (`nunca_coletado`, byte a byte) + `publicador-meus-anuncios-sku-render.test.js` | **ATENDIDO** |
| 3. aviso de **SKU ainda não coletado** | `estrutura-meus-anuncios.test.js` caso 3; mutação **iv** do 180-10 e **viii** do 180-09 (o aviso aparecendo no `sem_anuncios`) **pegas**; e o gate exige que o botão "Atualizar agora" esteja na tela, porque o aviso manda clicar nele | **ATENDIDO** |
| 4. o **DADO** de Publicados\|Rascunhos com contadores | `estrutura-meus-anuncios.test.js` (`180: Publicados \| Rascunhos`); `'rascunhos' => $totalDeRascunhos` ×2, **mesma variável** | **ATENDIDO** |
| 5. **paginação de 50** | `publicador-publicacoes-grupos.test.js` (`o rodapé e a paginação`); `PublicacoesDaTelaTest::paginacao_preserva_todos_os_filtros_nos_links`; mutação (a) do 180-04 (tirar `->withQueryString()`) **pega** | **ATENDIDO** |
| 6. **ordenação por gravidade** (D-12) | `AcervoBuscaEFiltrosTest` (a ordenação **não foi tocada**: `severidade` → `nota_ecf IS NULL` → `nota_ecf` → `ml_item_id`); mutação **vii** do 180-06 (o rótulo "Mais recentes primeiro") **pega** em 4 asserções | **ATENDIDO** |
| 7. **D-11** — a tela nunca escreve no ML | `estrutura-meus-anuncios.test.js:250` e `:256`, **INTOCADAS, 0 caractere no diff da fase**; `D-11 endurecido` (sem `useForm`/`axios`/`fetch`/`<form>`/`router.visit`); **zero `router` no módulo de teclado**; mutação **vi** do 180-10 (`router.patch`) **pega** | **ATENDIDO** |
| 8. **sub-abas No ar\|Histórico** | §b2 | ⚠️ **SUPERADO por decisão informada do usuário em 13/10/2026** |

### e3. Os 16 critérios de sucesso do ROADMAP

| # | critério | onde foi provado | veredicto |
|---|---|---|---|
| 1 | cabeçalho: H1 + subtítulo + "● Sincronizado há X" + "Atualizar" com spinner | `publicador-publicacoes-cabecalho.test.js` 74/74 | **ATENDIDO COM DESVIO DECLARADO** (desvio 21, 14/10) |
| 2 | faixa de sucesso ao voltar do Publicador, com "Localizar na lista" | gate nos dois sentidos + `faixa_de_sucesso_e_nula` | **ATENDIDO** (o layout; ⚠️ `faixaSucesso` é `null` SEMPRE ⇒ **não aparece hoje**, por falta de FONTE — pergunta aberta) |
| 3 | busca de 54px, raio 14, ring `0.18`, acha **pelo nome do produto**, no servidor | CSS + `test_busca_acha_pelo_nome_do_produto` + `busca_por_nome_de_produto_chega_a_tela` | **ATENDIDO** |
| 4 | abas com contagem sobre os **demais** filtros + os 6 chips + "Limpar filtros" | `contagens_sao_sobre_os_demais_filtros` + `publicador-publicacoes-filtros.test.js` 112/112 | **ATENDIDO** (⚠️ o chip "Responsável" não nasce: sem fonte) |
| 5 | o grid literal + 62/50 + os 5 fundos distintos | CSS byte a byte + `os CINCO fundos` | **ATENDIDO** |
| 6 | linha de grupo de 58px com todas as peças + colapso + busca força aberto | `publicador-publicacoes-grupos.test.js` 81/81 + `grupoAberto()` 7 casos | **ATENDIDO COM DESVIO DECLARADO** (desvio 9) |
| 7 | **Lista** mostra linhas planas com tile e nome do produto no meta | `a célula Publicação` + `visao_lista_nao_manda_grupo` | **ATENDIDO** |
| 8 | metodologia do dado real, **nada inferido do título** | `PublicacoesDaListaTest` 18 casos + `a metodologia` ×6 | **ATENDIDO** (⚠️ o TEXTO do critério descreve o caminho errado — §f2) |
| 9 | copiar vira check por 1,3 s + badge NOVA + avatar/data/responsável + autor dito | `copiar o MLB` + `o autor` ×7 + `nova_e_publicada_hoje` | **ATENDIDO** (o timer pelo **literal**) |
| 10 | Preço: "Margem x%"/"▲ Prejuízo" quando chega e **`—`** enquanto não chega | `o — da margem` com item CHEIO + `preco_zero_e_medida_e_nulo_e_ausencia` | **ATENDIDO COM DESVIO DECLARADO** (desvio 6) |
| 11 | os atalhos `/`, ↑/↓, j/k, Enter, Esc em cascata, ↓/Enter na busca | `publicador-publicacoes-atalhos.test.js` 28/28 | **ATENDIDO** (runtime = conferência visual) |
| 12 | recarregar com filtros devolve o mesmo estado | `filtros_voltam_na_prop` + `sel_so_sobrevive_com_a_forma` + o `page` preservado no `selecionar()` | **ATENDIDO** (a SELEÇÃO volta; o **foco do teclado** não, por desenho) |
| 13 | **nada regrediu** (8 itens) | **e2** | **ATENDIDO COM DESVIO DECLARADO** — **1 item de 8**, superado em 13/10 |
| 14 | chips de triagem dos 5 motivos + "Não avaliado", total de `triagem.total` | `LIST-22` ×6 + `test_triagem_total_nao_e_a_soma_dos_chips` | **ATENDIDO** |
| 15 | Nota ECF continua "de 86" | `a Nota ECF` ×4 + `D-22` | **ATENDIDO** |
| 16 | o plano traz a tabela de conferência item a item contra o `.dc.html` | 7 tabelas FID-01 nos planos 01/05/06/07/08/09/11, **173 medidas**, + este fechamento | **ATENDIDO** |

➡️ **16/16 com veredicto. 0 "parcialmente".**

---

## (g) OS GATES DE TERRITÓRIO — `test -e` em CADA alvo, prova COMMIT POR COMMIT

### ⚠️ A árvore é COMPARTILHADA, e isso muda a forma da prova

A **Fase 184 (Bling)** rodou na **mesma árvore**, com commits **INTERCALADOS** entre os desta fase, e
**com PHP, migrations, rotas e blade**. ⚠️⚠️ **E o AUTOR não discrimina nada:** as duas sessões usam
o **mesmo `user.name`** (`MB.ECF-100376`), logo `git log --format=%an` devolve um valor só.
➡️ **O discriminador real é o ESCOPO DO ASSUNTO** (`180-*` × `184-*`), e um gate que compare `%an`
com um nome esperado é **vazio por construção** nesta árvore.

### RANGE A — o que NINGUÉM da fase pode tocar (`f23f610d..HEAD`)

**25 alvos, `test -e` em cada um: 25/25 EXISTEM. ZERO caminho fantasma** (usei os caminhos CERTOS que
os planos anteriores registraram: `Components/Publicador/Mesa` **sem** o `Mlb`,
`app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `Pages/Mlb/AnunciosHistorico.jsx` **fora** de
`Publicador/`, `Publicador/SubAbasDePublicacoes.jsx` **dentro** de `Publicador/`, os serviços em
`app/Services/Publicador/`, o roadmap em `.planning/ROADMAP.md`).

**22 com 0 arquivo. 3 ACUSADOS — e os 3 são da Fase 184, provado commit por commit:**

| alvo | numstat | commit | escopo |
|---|---|---|---|
| `database/migrations` | +75/−0 e +60/−0 | `8f1dfc67`, `19f873b0` | **`feat(184-01)`** — Bling |
| `routes/mlb_anuncios.php` | **+43 / −0** | `279e8fac` | **`feat(184-11)`** — Bling |
| `routes/web.php` | **+12 / −0** | `73ba3520` | **`feat(184-07)`** — Bling |

⚠️ **E as três são SÓ ADIÇÕES (`−0`).** ➡️ **`routes/mlb_anuncios.php` não perdeu uma linha**, e a
rota `historico` está viva em `:473`. **Era exatamente o item que o usuário NÃO autorizou remover**, e
um gate que medisse só o range deixaria a dúvida no ar.

**0 em:** `Editor.jsx` · `Components/Publicador/Mesa` · `app/Support/Publicador` ·
`MlPublicacaoService.php` · `AppLayout.jsx` · `header/` (e `header/PublicadorHeader.jsx`) ·
`SubAbasDePublicacoes.jsx` · `VisaoGeral.jsx` · `visaogeral/` · **`identidade/`** ·
`package.json` · `package-lock.json` · `composer.json` · `composer.lock` ·
`AnunciosHistorico.jsx` · `anuncioHistoricoUtils.js` · `HistoricoAnunciosTest.php` ·
`estrutura-anuncios-historico.test.js` · `publicador-header-zindex-ordem.test.js` ·
`publicador-visao-geral-acoes.test.js` · `CabecalhoDoPublicadorTest.php` · `CockpitDaContaTest.php` ·
`MeusAnunciosTest.php`.

⚠️ **`identidade/` saiu com 0 arquivos** — e isso é o **caso especial do plano**, resolvido pelo lado
MAIS seguro: o 180-09 escolheu o **desvio declarado** em vez da prop aditiva em `MapaDeCobertura.jsx`.
**Nem a prop entrou. `identidade/` está byte a byte como antes da fase.**

### CONTROLE POSITIVO — o MESMO comando VÊ o trabalho da fase

| alvo | arquivos |
|---|---|
| `resources/js/Components/Mlb/Publicador/publicacoes` | **15** |
| `resources/js/Pages/Mlb/MeusAnuncios.jsx` | 1 |
| `app/Http/Controllers/MlbAnuncioController.php` | 1 |
| `app/Services/Publicador` | 3 |
| `tailwind.config.js` | 1 |

➡️ **Logo os 22 zeros não são comando quebrado.**

### `.planning/` — DECLARADO COMO NÃO-VIOLAÇÃO

```
186  2  .planning/ROADMAP.md
215  0  .planning/STATE.md
```

⚠️ **Nenhum dos dois pertence a plano nenhum.** O `ROADMAP.md` é do orquestrador (registrando a Fase
183 **durante** a fase) e da sessão paralela (marcando os checkboxes da 184); o `STATE.md` recebeu os
blocos aditivos dos 13 planos. **`REQUIREMENTS-v26.md` não aparece: diff VAZIO na fase inteira.**

### DELEÇÕES — a varredura completa

**0 deleções em TODA a fase**, conferido **commit por commit** (`--diff-filter=D` em cada um dos
commits de `f23f610d..HEAD`). **Nenhum arquivo foi apagado por ninguém.**

### Os três gates do ERP — VERDES e INTOCADOS, conferidos do NOSSO lado

A Fase 184 garantiu do lado dela com uma chave irmã. **Do nosso lado:**

| gate | diff na fase | execução |
|---|---|---|
| `tests/js/publicador-visao-geral-acoes.test.js` | **0** | **214 / 214 pass / 0 fail** |
| `tests/Feature/Publicador/CabecalhoDoPublicadorTest.php` | **0** | ✅ dentro dos **2085/0 falhando** da suíte do Publicador |
| `tests/Feature/Publicador/CockpitDaContaTest.php` | **0** | ✅ idem |

**E a prova ESTRUTURAL:** `grep` por `MeusAnuncios|CelulasDaLinha|resources/js|atalhosDaLista` nos
dois arquivos PHP devolve **0 ocorrências** ⇒ **não há caminho por onde esta fase os quebrasse.**

### Gates vizinhos — verdes e intocados

`publicador-tokens-do-publicador.test.js` **13/13** · `publicador-identidade-nova-render.test.js`
**64/64** · `publicador-header-zindex-ordem.test.js` **7/7** ·
`publicador-meus-anuncios-sku-render.test.js` **10/10, diff VAZIO** ·
`estrutura-anuncios-historico.test.js` **11/11**.

---

## (c2) A CIRURGIA DE GATE — as 4 declaradas, conferidas UMA A UMA

⚠️⚠️ **O gate "asserções removidas = ZERO" tem DUAS cegueiras, e as duas valem aqui:**
(1) é **vazio ao contrário** — reaponte de valor gera `-assert` por construção;
(2) é **CEGO para teste instalado por CHAMADA DE FÁBRICA** — uma linha removida **sem a palavra
`assert`** pode levar **6 testes**. ➡️ **CONTEI TESTES, não linhas.**

### Quais gates perderam linha — e SÓ TRÊS perderam

| gate | +linhas | **−linhas** | +assert | **−assert** | `+ > −`? |
|---|---|---|---|---|---|
| `estrutura-meus-anuncios.test.js` | 510 | **12** | **56** | **2** | ✅ |
| `publicador-paginas-anuncios-render.test.js` | 1036 | **21** | **160** | **7** | ✅ |
| `publicador-vocabulario-novo.test.js` | 168 | **3** | **6** | **0** | ✅ |
| `publicador-publicacoes-cabecalho.test.js` | 1162 | **0** | 178 | 0 | ✅ |
| `publicador-publicacoes-tabela.test.js` | 2094 | **0** | 419 | 0 | ✅ |
| `publicador-tokens-do-publicador.test.js` | 227 | **0** | 16 | 0 | ✅ |
| `publicador-meus-anuncios-sku-render.test.js` · `estrutura-grade-glide` · `header-zindex-ordem` · `visao-geral-acoes` · `estrutura-anuncios-historico` · `identidade-nova-render` | 0 | **0** | 0 | 0 | — |

⚠️ **Nota honesta:** a `publicador-publicacoes-cabecalho.test.js` aparece com **−0** porque **nasceu
nesta fase** (180-05). A **CIRURGIA 4** do 180-11 nela mudou **uma linha de LEITURA** (`readFileSync`
cru → `lerSemComentarios()`), que no range da fase inteira se dissolve no `+1162/−0` do arquivo novo.
**O `-assert` dela é 0 e o `+assert` é 178** — nenhuma asserção saiu.

### ⚠️ A medida que de verdade mede: NENHUM GATE PERDEU TESTE

| gate | `test()` em `f23f610d` | `test()` AGORA | **Δ** | `assert.` ANTES | AGORA | runtime hoje |
|---|---|---|---|---|---|---|
| `estrutura-meus-anuncios.test.js` | 18 | **24** | **+6** | 43 | **97** | **30 / 30 / 0** |
| `publicador-paginas-anuncios-render.test.js` | 9 | **16** | **+7** | 38 | **191** | **119 / 119 / 0** |
| `publicador-vocabulario-novo.test.js` | 7 | **7** | **0** | 50 | **56** | **344 / 344 / 0** |
| `publicador-publicacoes-cabecalho.test.js` | 0 | **11** | **+11** | 0 | **158** | **74 / 74 / 0** |
| `publicador-publicacoes-tabela.test.js` | 0 | **19** | **+19** | 0 | **388** | **130 / 130 / 0** |
| `publicador-meus-anuncios-sku-render.test.js` | 12 | **12** | **0** | 25 | **25** | **10 / 10 / 0** |

➡️ **Nenhum Δ negativo. Nenhuma asserção perdida em nenhum arquivo.**

⚠️ **LIMITAÇÃO DECLARADA:** a comparação "antes × depois" é **ESTÁTICA** (declarações `test()` e
chamadas `assert.`). **Não dá para rodar o gate em `f23f610d`**: ele leria os componentes de HOJE e
produziria falhas falsas. **Digo isso em vez de fingir que rodei.**

### A chamada de FÁBRICA — a cegueira, medida

```
estrutura-meus-anuncios.test.js:  chamadas de fabrica ANTES=3  AGORA=2
```

⚠️ **UMA fábrica foi removida** — `travaVocabularioVisual('resources/js/Pages/Mlb/MeusAnuncios.jsx')`
— e **ela instalava 6 testes (18 asserções)**. A linha removida **não contém a palavra `assert`** ⇒
o gate de `-assert` **não a mostraria**. ➡️ **Está declarada pelo 180-10 §2 e compensada** pelo teste
nomeado `180: MeusAnuncios.jsx saiu do vocabulário ANTIGO` (as 6 regras com veto escrito em cada uma)
**mais** os 6 sub-testes do gate NOVO (`ARQUIVOS_NOVOS`), que é mais duro. **E o Δ do arquivo é +6, não −6.**

### As 12 linhas removidas de `estrutura-meus-anuncios.test.js`, ENUMERADAS

| linha removida | para onde foi |
|---|---|
| `travaVocabularioVisual('…/MeusAnuncios.jsx');` | a fábrica acima (6 testes → teste nomeado + GATE-03) |
| `assert.match(fonte, /de 86/);` | **reaponte de ARQUIVO**: `match(fonteCelulas, /de 86/)` + `match(/Nota ECF \${nota} de 86/)` + **4 negativas de endurecimento** |
| `test('177-08: as sub-abas No ar \| Histórico continuam montadas nas DUAS páginas (LIST-25)', …)` | o teste `180: "No ar \| Histórico" NÃO está mais em MeusAnuncios` — **negativa com veto** + **3 asserções NOVAS** (a rota, o controller, a página do Histórico). ⚠️ **É a decisão do usuário de 13/10: a prescrição anterior é hoje o OPOSTO do exigido** |
| `assert.match(fonte, /<SubAbasDePublicacoes atual="meus" …\/>/);` | idem (`doesNotMatch(/<SubAbasDePublicacoes/)` + `doesNotMatch(/SubAbasDePublicacoes/)`) |
| 2 asserções do par rótulo↔rota de "No ar"/"Histórico" | idem |
| `test('177-08: as sub-abas Publicados \| Rascunhos (D-14) NÃO foram tocadas…', …)` | o teste `180: Publicados \| Rascunhos`, **ENDURECIDO** (recortado à OPÇÃO do trilho por `role="tab"`, não ao HTML inteiro) |
| 5 linhas de **COMENTÁRIO** | reescritas |

### As 21 de `publicador-paginas-anuncios-render.test.js`

Todas são **reapontes de VALOR POR PÁGINA** ou **entradas de array** — e o 180-10 as enumerou:
`headers: 1` → `headers: 2` em `MeusAnuncios` e `1` nas outras duas (o `CabecalhoDePublicacoes` monta
um `<header>` semântico, que é o protótipo `:31`); o literal do container → por página; a saída de
`MeusAnuncios` do array `COM_SUBABAS` (**4 sub-testes**, compensados pelo teste nominal
`180: MeusAnuncios NÃO tem mais o segmentado`, com as props **CHEIAS**); e as 4 asserções de
`Publicados`/`Rascunhos`/`(3)`/`(1)`, **duas VETADAS** (a forma com parênteses era do controle que
saiu) e **o contador reaparecendo na forma nova**.

### As 3 de `publicador-vocabulario-novo.test.js` — as três são REAPONTE, e eu conferi o par `+`

```
- 8.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34,
+ 8.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 15.5, 16, 17, 20, 24, 28, 30, 32, 34,
- const DIRETORIOS_FECHADOS = [DIR, DIR_VG];
+ const DIRETORIOS_FECHADOS = [DIR, DIR_VG, DIR_PUB];
- test('GATE-03 — nenhum arquivo de `identidade/` ou `visaogeral/` fica FORA de ARQUIVOS_NOVOS', …
+ test('GATE-03 — nenhum arquivo de `identidade/`, `visaogeral/` ou `publicacoes/` fica FORA de ARQUIVOS_NOVOS', …
```

⚠️ **A terceira é a mais perigosa de ler errado:** parece que **um teste foi APAGADO**. **Não foi —
foi RENOMEADO**, e a prova é a contagem: **7 `test()` antes e 7 depois**, com o runtime subindo de
331 para **344**. ➡️ **É exatamente por isso que se conta TESTE e não linha.**

### A CIRURGIA 4 — o gate que reprovava o CÓDIGO CERTO

O bloco `TAILWIND` de `publicador-publicacoes-cabecalho.test.js` proíbe classe nos `.js` de
`publicacoes/`. Ele estava **vazio por construção** até o 180-11 (o diretório não tinha `.js`).
**Ao ganhar alvo real, reprovou — e reprovou o arquivo que OBEDECE a regra:** ele lia a fonte
**CRUA**, e o cabeçalho do `atalhosDaLista.js` traz a frase que **explica por que o arquivo não tem
classe nenhuma** — e essa frase contém a palavra `className`.

**A escolha certa foi corrigir a LEITURA** (`lerSemComentarios()`), não dobrar o código ao gate:
`-assert = 0`, `+assert = 5`, **incluindo uma ISCA DA LEITURA** que prova que a troca não cegou o
gate. ⚠️ **É a 5ª vez nesta milestone que um `grep` que casa com comentário produz desvio** — e é a
mesma classe do que eu mesmo encontrei em §b2 (`SubAbasDePublicacoes` ×3, todas comentário) e em §h
(`duration-[` ×2, todas comentário).

---

## (h) AS DÍVIDAS NOMEADAS — deixadas DE PROPÓSITO

⚠️ **Para nenhuma sessão futura "consertar" por engano.**

| # | o que esta fase NÃO fez | por quê / para onde vai |
|---|---|---|
| 1 | o **painel de inspeção de 460px**, a **rentabilidade por venda**, a **barra de ações em lote** e o **pausar/reativar** | **Fase 181.** `sel` na URL e `limparSelecao()` já são o contrato dela |
| 2 | a **margem** da célula Preço (fica em `—`) | **Fase 181** — a tarifa do ML não está no banco. ⚠️ **O VALOR do preço já chega** (180-04B); o que falta é a 2ª linha |
| 3 | a aba **"Desempenho"** | **Fase 182**, por decisão do usuário em 11/10/2026 |
| 4 | o **acesso ao Histórico** no layout novo | **Fase futura do Histórico** (insumo pronto em §b3). ⚠️ **A remoção do atalho FOI FEITA em 13/10, por decisão informada.** O que ficou pendente é devolver o ACESSO. **A rota, o `historico()`, a página e os testes NÃO foram apagados** (§b2/§g) |
| 5 | o **`MapaDeCobertura` em dois pares com rótulo F1/F2** | desvio declarado (§b item 9). Receita de conserto de 10 min no 180-09 §1; exige fase que possa tocar `identidade/` |
| 6 | o **anel de "Em revisão"** com `#5F5F68` e o **"Com erro"** preenchido | §a4. ⚠️ **O token `pub-neutral-ring` já existe e está ÓRFÃO** (0 usos ⇒ 0 CSS, medido aqui). Exige fase que possa tocar `identidade/` |
| 7 | o **`cursor-pointer` que falta** em `SeletorDeEmpresaDoHeader.jsx:171` | **dívida da Fase 179, NÃO é desta fase** — território do header |
| 8 | o **N+1 em `PainelVisaoGeralService::resolverAtor()`** | dívida da Fase 179. ⚠️ **REGISTRO QUE ESTA FASE NÃO O REPETIU:** `PublicacoesDaListaService` lê `ator.nome` do json e tem **ZERO `User::find()`** por linha (conferido) |
| 9 | ⚠️ **os 7 usos mortos de `duration-[…]`/`ease-[…]` FORA desta fase** | ver abaixo — **e eu achei o 7º** |
| 10 | `filtros_ativos` vindo do servidor (hoje `algumFiltroAtivo` é derivado **no CLIENTE**) | dívida do 180-07/180-10: exige editar o controller. **A regra pode divergir do que o servidor aplica** |
| 11 | `responsavel.opcoes` (a lista de responsáveis **não existe como prop**) | caminho nomeado: `PainelVisaoGeralService::resolverAtor()` já separa equipe de cliente e já tem os nomes. **Falta a prop** |
| 12 | `sub_status` achatado + mapa de rótulos em **pt-BR** (para mostrar QUAL erro) | Fase 181. ⚠️ `waiting_for_patch` na tela é jargão — regra permanente |
| 13 | a **chave de flash** declarada no `HandleInertiaRequests` (para a faixa de sucesso) | ausência de fonte no sistema + **decisão de produto** sobre a fila assíncrona |
| 14 | o **token `pub-bar-float`** para o `#1C1C1C` da barra flutuante (`:407`) | ⚠️ **NÃO usar `pub-row-focus`** — seria nome mentindo sobre o uso. Quem desenhar a barra (Fase 181) cria o nome |
| 15 | a **consolidação do `idsPorMetodologia()`** dentro de `PublicacoesDaListaService` | hoje a cadeia de joins existe em **TRÊS** lugares (o serviço, `historico()` e o helper privado do controller) |
| 16 | o **`escapar o primeiro `<div>`** nos 3 gates de `filtros` | §c, fragilidade declarada |

### ⚠️⚠️ `duration-[Nms]` / `ease-[…]` NÃO COMPILAM — e eu achei o 7º uso morto

**O fato, re-medido no CSS construído desta sessão** (`app-3tlHZ44i.css`, 222.857 bytes):

```
AUSENTE-ok     duration-[180ms]
AUSENTE-ok     duration-[2000ms]
AUSENTE-ok     duration-[900ms]
AUSENTE-ok     ease-[cubic-bezier(.2,.8,.2,1)]     <- NOVO
VALOR-ok       duration-200  {transition-duration:.2s}      <- CONTROLE: a escala NOMEADA compila
```

⚠️ **Sem o controle, o gate não distinguiria "o arbitrário não compila" de "a sonda está cega".**
A causa (reproduzida pelo 180-09): o plugin **`tailwindcss-animate`** registra `duration`, `delay` e
`ease` como utilitários de **animação**, e o Tailwind recusa o valor arbitrário como **ambíguo**.
➡️ **A classe fica no HTML, todo teste de render passa, e a transição não acontece na tela.**

**Nos ARQUIVOS DA FASE: ZERO** — medido nos 17 arquivos, **sem comentário**:

```
TOTAL_CRU=2   TOTAL_SEM_COMENTARIO=0
  LinhaDeGrupoDeProduto.jsx  cru=2  sem_comentario=0
  ISCA_DA_LEITURA_sem_export_depois_da_limpeza=0 (tem de ser 0)
```

⚠️ **As 2 ocorrências cruas são o DOCBLOCK que EXPLICA que o arquivo não usa a classe**
(`:165` e `:168`). **Um gate cru reprovaria o código certo** — é a mesma família da CIRURGIA 4.

**FORA da fase — os 7 usos mortos, NÃO consertados:**

| arquivo | linha | classe morta | registrado? |
|---|---|---|---|
| `Components/Mlb/Publicador/LinhaDeProduto.jsx` | 40 | `duration-[2000ms]` | ✅ `deferred-items.md` |
| `Pages/Performance/Index.jsx` | 1249, 1334, 1358, 1485, 1532 | `duration-[900ms]` ×5 | ✅ `deferred-items.md` |
| ⚠️ **`Components/Portal/TrilhoDock.jsx`** | — | **`ease-[cubic-bezier(.2,.8,.2,1)]`** | ⚠️ **NÃO** — **ACHADO NESTE FECHAMENTO** |

➡️ **O `deferred-items.md` dizia literalmente *"conferir também `delay-[…]` e `ease-[…]` … não varri
o repositório inteiro por eles"*. Eu varri: existe **1** uso de `ease-[…]`, no `TrilhoDock.jsx` do
**Portal**, e ele **não gera CSS** (medido como AUSENTE, com o controle `duration-200` presente).
**`delay-[…]`: 0 usos no repositório.** ⚠️ **Não consertei** — `Components/Portal/` não é território
desta fase. **Fica como dívida nomeada, a 7ª.**

---

## (f2) ⚠️ AS PENDÊNCIAS DE MARCAÇÃO — as ferramentas FALHAM EM SILÊNCIO

**Nenhuma das três foi executada por este plano, e isso é deliberado:**

| ferramenta | o que ela faz de verdade |
|---|---|
| `gsd-sdk query state.advance-plan` | ⚠️ **já corrompeu o `STATE.md` deste projeto SEIS vezes.** NÃO RODADO |
| `roadmap update-plan-progress` | ⚠️ **reporta SUCESSO e NÃO ESCREVE NADA** nesta milestone. NÃO RODADO |
| `requirements.mark-complete` | ⚠️ devolve **`not_found`** para IDs de `REQUIREMENTS-vNN.md`. NÃO RODADO |
| `REQUIREMENTS-v26.md` | ⚠️ **não tem checkbox nenhum** — os requisitos são **bullets**. **Não há o que marcar** |

➡️ **O que existe para marcar são o Coverage Map do `ROADMAP.md` e a linha `**Plans**:` da Fase 180.**
⚠️ **ESTE PLANO NÃO TOCA `ROADMAP.md` NEM `REQUIREMENTS-v26.md`** — a instrução desta fase é não
tocá-los, e eles **não estão em `files_modified`**. **Precisam ser marcados À MÃO por quem fechar.**

### O TEXTO PRONTO PARA COLAR no `.planning/ROADMAP.md`, na entrada da Fase 180

Trocar a linha `**Plans**: TBD` por:

```
**Plans**: 13/13 concluídos — 180-01 (tokens) · 180-02 (PublicacoesDaListaService) ·
180-03 (busca por produto, filtros, aba "Com erro") · 180-04 (meus(): props, abas, grupos) ·
180-04B (preço, nova, com_erro no item) · 180-05 (cabeçalho e faixa) ·
180-06 (busca, abas, toggle) · 180-07 (chips e popovers) · 180-08 (tabela e as 7 células) ·
180-09 (linha de grupo, rodapé, vazios) · 180-10 (composição + cirurgia nos gates) ·
180-10B (as duas decisões do usuário de 14/10) · 180-11 (atalhos e estado na URL) ·
180-12 (fechamento medido — `180-FECHAMENTO.md`).
**Status**: código completo, 25/25 REQ-IDs e 16/16 critérios com veredicto medido.
**Critério 13 atendido COM DESVIO DECLARADO em UM item**: as sub-abas "No ar | Histórico" foram
removidas por decisão informada do usuário em **13/10/2026**, entre três opções, com o aviso de que
a rota ficaria órfã — ver `180-FECHAMENTO.md` §b2. Os outros SETE itens do critério 13 estão
atendidos limpo. **AGUARDA checkpoint humano em produção (conta 459).**
```

### O TEXTO PRONTO PARA COLAR em `.planning/REQUIREMENTS-v26.md`

⚠️ **Não há checkbox a marcar.** O que falta é **corrigir o TEXTO do LIST-20**, que descreve um
caminho **medido como errado** (duas provas independentes no 180-02). Acrescentar ao bullet:

```
  ⚠️ **CORREÇÃO MEDIDA NA FASE 180 (13/10/2026):** o caminho descrito acima está ERRADO.
  `ml_acervo_itens.rascunho_id` aponta para `ml_anuncio_rascunhos` (o assistente ANTIGO), não para
  `pub_rascunhos` — provado em dois lugares: o comentário da própria coluna
  (`2026_08_11_090000_create_ml_acervo_itens_table.php:55-61`) e o
  `MlAcervoService::mapaRascunhos()` (`:522`-`:526`), que monta o mapa a partir de
  `MlAnuncioRascunho`, não de `PubRascunho`. **A ponte real é
  `pub_publicacao_itens.ml_item_id` → `pub_publicacoes` → `pub_rascunhos` → `pub_produtos`,
  com `status = CREATED`** — a mesma que `MlbAnuncioController::historico()` já percorre.
  Implementado assim em `PublicacoesDaListaService::metodologiaPorMlItemId()` (UMA consulta).
```

E, na contagem em prosa, onde se lê "LIST-01..24" ou "24 requisitos": **são 25** — o `LIST-25`
entrou **entre o 23 e o 24** em 11/10/2026.

---

## A ÁRVORE E OS ÓRFÃOS

| conferência | resultado |
|---|---|
| `git status --short` nos MEUS alvos (`resources/js`, `tests/js`, `app`, `database`, `routes`, `tailwind.config.js`) | **3 arquivos modificados, NENHUM meu**: `app/Http/Middleware/RestringeDominioDoPortal.php`, `app/Services/Bling/BlingOAuthService.php`, `routes/web.php` — **todos da sessão paralela da Fase 184**, em trabalho vivo |
| `.planning/ROADMAP.md`, `REQUIREMENTS-v26.md`, `STATE.md` | **sem modificação pendente** |
| arquivos de `publicacoes/` no disco | **15** (14 `.jsx` + `atalhosDaLista.js`) — + `MeusAnuncios.jsx` + `components/BotaoAtualizarAcervo.jsx` = **17 arquivos de frontend na fase** |
| scripts de medição deste fechamento | **nenhum no repositório** — os 7 vivem no scratchpad da sessão |
| migrations na fase | **0 desta fase** (as 2 do range são `feat(184-01)`) |
| pacotes na fase | **0** — `package.json`, `package-lock.json`, `composer.json`, `composer.lock` todos com diff **vazio** |

**Nada deployado. Nada empurrado. Nenhum comando no VPS. Nenhuma leitura de produção.**

---

# ⚠️ CHECKPOINT — A CONFERÊNCIA VISUAL, EM PRODUÇÃO, NA CONTA 459

⚠️⚠️ **Estes passos são para o USUÁRIO executar.** Nenhum executor pôde fazê-los: o banco local não
tem empresa com `ml_token`, `ml_acervo_itens` está vazia e a leitura de produção está bloqueada.

**URL:** `/mlb/anuncios/meus/459`

## O roteiro — 11 passos

| # | o que fazer | o que tem de acontecer |
|---|---|---|
| 1 | olhar a **busca central** e clicar nela | **54px** de altura, raio 14, e no **foco** a borda fica **amarela** com um **halo** de 3px. Digitar o **NOME DE UM PRODUTO** (não o título do anúncio, não o MLB) **acha o anúncio** — era o que não funcionava antes |
| 2 | passar pelas **abas de status** | Todas · Ativas · Pausadas · **Com erro** · Em revisão · **Rascunhos**, cada uma com a contagem. ⚠️ **"Com erro" pode abrir em 0 — é o ESPERADO**, decisão sua de 13/10 (o `sub_status` do ML pode estar vazio na conta). **Não é bug e não é motivo para trocar a fonte** |
| 3 | conferir que a tela abre em **Por produto** | é o default. A **linha de grupo** traz chevron, tile, nome + tag "PRODUTO", "SKU · Estoque" e o **mapa F1/F2 × Clássico/Premium**. Clicar em **Lista** mostra **linhas planas** |
| 4 | ⚠️ olhar a **célula Preço** | o **valor** agora chega (ex.: `R$ 309,90`). A 2ª linha mostra **"Margem x%"** ou **"▲ Prejuízo"** **se** o dado chegar, e **"—"** enquanto não chega. ⚠️ **Nunca um número inventado, nunca `R$ 0,00` no lugar de ausência** — é isto que eu preciso que você confirme com o olho |
| 5 | ⚠️ olhar o **SKU por variação** na linha | `MLB… [copiar] · Nome do produto · SKU FON-001-P +1`, em Geist Mono. ⚠️⚠️ **E A PERGUNTA É SE ELE CABE:** com título longo **e** nome de produto longo, na coluna de 300px. **Só o seu olho diz.** O `flex-none` garante que o SKU não é o primeiro a ser cortado — é tudo o que o render de servidor consegue afirmar |
| 6 | ⚠️ contar os botões **"Atualizar"** | tem de haver **UM só**, o **"Atualizar agora"** do cabeçalho preto do Publicador. O da página saiu (sua decisão de 14/10). O **"● Sincronizado há X"** continua |
| 7 | clicar no ícone de **copiar** ao lado do MLB | vira um **check verde** e volta sozinho em ~**1,3 s** |
| 8 | ⚠️ procurar **"No ar \| Histórico"** | **NÃO ESTÁ MAIS LÁ** (sua decisão de 13/10). ⚠️ **E conferir que a rota não foi apagada:** abrir `/mlb/anuncios/historico/459` **direto na barra de endereços** — **a página tem de abrir** |
| 9 | olhar o resto da barra | **"Rascunhos"** no MESMO trilho das abas; o **banner de defasagem** em **âmbar** (nunca vermelho) quando houver; a **paginação de 50** no rodapé, e trocar de página **mantém os filtros** |
| 10 | ⚠️ testar os **atalhos** | **`/`** põe o cursor na busca · **↑/↓** e **j/k** movem a linha em foco (fundo `#1C1C1C`) · **Enter** abre o detalhe · **Esc** fecha em cascata (menu → sai da busca → limpa a seleção). ⚠️ **NADA disto é provado por teste de runtime** — é conferência visual |
| 11 | aplicar filtros e **recarregar a página (F5)** | tem de voltar **no mesmo estado**: os 12 parâmetros estão na URL. E **clicar numa linha da página 7 não deve pular para a página 1** |

---

# ⚠️ PERGUNTAS EM ABERTO — TODAS para o usuário. NENHUMA decidida aqui

## A. A fase do Histórico — **com que prioridade?**

Você autorizou tirar "No ar | Histórico" em **13/10/2026** sabendo que a rota ficaria órfã. **Nada
foi apagado** — a página ainda abre por URL direta. A seção **(b3)** traz a lista do que **SÓ** o
`historico()` alcança: o **agrupamento por LOTE** (que não existe como coluna no banco), o **payload
ENVIADO** ao ML, a **foto** que o Publicador subiu, o `sku_origem` e o `pode_duplicar`.
➡️ **Quer essa fase na fila agora? Antes ou depois da 181 e da 182?**

## B. O `ROADMAP.md` e o `REQUIREMENTS-v26.md`

O texto pronto está em **(f2)**. As três ferramentas de marcação **falham em silêncio** e nenhuma foi
rodada. ➡️ **Quer que alguém cole, ou você marca?** (E a correção do texto do **LIST-20**, que
descreve um caminho medido como errado.)

## C. Algum **desvio declarado** da seção (b) que você prefira diferente?

São **26**, cada um com quem decidiu. Os que mais mudam o que você vê: a **ordenação rotulada como
"Mais graves primeiro"** (o protótipo diz "Mais recentes primeiro", mas o sistema ordena por
gravidade), os **chips de triagem** que o protótipo não tem, e o **`MapaDeCobertura` com 4 células
seguidas** em vez de dois pares com rótulo F1/F2.

## D. ⚠️ O `Escape` tem um OVERLAP não consertado

Há **dois ouvintes de janela** nesta tela: o do `PublicadorHeader` (Fase 177, em produção e aprovado)
e o da lista. ➡️ **Com um popover DO HEADER aberto e uma linha selecionada, um `Escape` fecha o
popover E limpa a seleção.** Consertar exige editar `atalhosDoHeader.js` — **território proibido**.
⚠️ **Não é regressão:** antes desta fase não havia seleção para perder. **Incomoda?**

## E. ⚠️ A faixa de sucesso NUNCA aparece

`faixaSucesso` é **`null` SEMPRE**, medido por **três caminhos independentes**: ninguém grava o resumo
em sessão; a lista branca de `flash` do `HandleInertiaRequests` tem **9 chaves e nenhuma de
publicação** (chave fora da lista **morre no caminho em silêncio** — este projeto já foi mordido duas
vezes); e a publicação termina **assíncrona** (202 + `PublicarRascunhoJob`). O layout está pronto e
ligado, e **nada foi fabricado**. ➡️ **Ligar exige uma chave de flash NOVA declarada no
`HandleInertiaRequests` E uma decisão sua: o que a tela mostra quando a fila ainda não concluiu?**

## F. ⚠️ O chip "Responsável" não nasce

`responsavel.opcoes` **não tem fonte** — o servidor recebe e aplica os ids, mas não manda a lista de
responsáveis. Com `[]` o chip **não é desenhado** (é honesto: popover vazio é botão sem destino).
➡️ **Quer o chip? Então alguém precisa criar a prop** (o caminho existe:
`PainelVisaoGeralService::resolverAtor()` já separa equipe de cliente e já tem os nomes).

## G. ⚠️ O checkbox marca, pinta a linha, e **não leva a ação nenhuma**

A barra flutuante de ação em massa é a **Fase 181**. Você **escolheu deixar o checkbox ligado** em
14/10. ➡️ **Confirma que fica assim até a 181?**

## H. As três divergências que ficam para uma fase que possa tocar `identidade/`

1. o **`MapaDeCobertura` sem os rótulos F1/F2** (o protótipo faz dois pares, `:181`-`:183`);
2. o **anel de "Em revisão"** com `#71717A` em vez do `#5F5F68` medido (`:250`) — ⚠️ **e o token
   certo já existe e está ÓRFÃO**: `pub-neutral-ring`, com **0 usos e 0 regra no CSS**;
3. o **"Com erro"** com triângulo de **contorno** em vez de **preenchido** (`:249`).

➡️ **Quer uma fase pequena só para `identidade/`, ou isso entra na 181?**

## I. Os 7 usos mortos de `duration-[…]` / `ease-[…]` FORA desta fase

Transições que **silenciosamente não acontecem**: 1 em `LinhaDeProduto.jsx`, 5 em
`Pages/Performance/Index.jsx` e ⚠️ **1 em `Components/Portal/TrilhoDock.jsx` que eu achei agora**
(`ease-[cubic-bezier(.2,.8,.2,1)]`, o primeiro `ease-[…]` do repositório).
➡️ **Conserto em quick task, ou deixa?**

## J. As duas da Fase 177 que seguem para o seu olho

**`top-[108px]` × `109px`** e a **espessura dos traços dos ícones**.

## K. ⚠️ A lacuna do `skus`

O controller faz `'skus' => $item->skus ?? []` — ele **colapsa "nunca coletado" (`null`) em "coletado
e vazio" (`[]`)**. Para a linha os dois casos são idênticos (nada desenhado), e quem distingue é a
flag `skuNaoColetado`, que já vive na tela. ➡️ **Consertar exige editar o controller. Vale?**

---

## ⚠️ O QUE ESTE FECHAMENTO NÃO PROVA — escrito, não maquiado

1. ⚠️⚠️ **NENHUMA TELA RENDERIZADA FOI VISTA, em nenhum dos 13 planos.** Não abri navegador, não
   deployei, não toquei no VPS, não li produção.
2. ⚠️ **Teclado, foco, `preventDefault`, debounce, `preserveState`, rolagem, timer de 1,3 s e
   `title` (tooltip)** são provados pela **FUNÇÃO PURA** (a decisão) e pelo **LITERAL** (a presença
   da peça), **nunca pelo runtime** — `renderToStaticMarkup` não despacha evento nem executa efeito.
3. ⚠️ **A eliminação de variável de escopo pelo Rollup** não é reproduzível em
   `renderToStaticMarkup`. A proteção é **estrutural** (toda flag nasce dentro do callback; zero
   `.map()` em 3 dos arquivos) e está **declarada, não provada**.
4. ⚠️ **A comparação de contagem de testes "antes × depois" é ESTÁTICA**, não runtime (§c2).
5. ⚠️ **A suíte PHP fora de `tests/{Unit,Feature}/Publicador` não foi medida** — é da outra sessão.
6. ⚠️ **Nenhum número de PRODUÇÃO foi medido.** As 1.080.206 linhas / 1.776 MB de `ml_acervo_itens`
   são medição anterior **citada, não remedida**; a tabela local tem **0 linhas**.
7. ⚠️ **A sonda de CSS não protege a fidelidade de UM arquivo** (learnings §3): ela prova que a
   classe EXISTE com o valor certo; quem prova que o ELEMENTO a tem é o gate de **HTML** (igualdade
   de conjunto de classes). **Os dois são necessários e não se substituem.**
