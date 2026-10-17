---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 09
subsystem: publicador-fila-de-aprovacao
tags: [lote, toasts, rodape, estado-vazio, modal, portal, z-index, fila, aprovacao]
requires:
  - "app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php (Fase 186) — aprovarEmLote, a transação única"
  - "app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php (Fase 186) — o veredicto por oferta"
  - "app/Services/Publicador/Aprovacao/VocabularioDoPortao.php (Fase 186) — ABREVIACOES, INCOMPLETO"
  - "resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx (Fase 186) — os 6 TOASTS"
  - "app/Services/Publicador/Aprovacao/Fila/{FilaDeAprovacao,LinhaDaOferta}Service.php (187-02/08)"
  - "resources/js/Components/Mlb/Publicador/aprovacao/TabelaDaFila.jsx (187-07) — os slots `vazio` e `rodape`"
provides:
  - "LoteDaFilaService — o veredicto de TODAS as selecionadas e a aprovação só das aptas"
  - "A rota POST mlb.anuncios.meus.aprovacao.lote — a PRIMEIRA ESCRITA da fila"
  - "CascaDeModal.jsx — a casca comum dos modais, que a FASE 188 REUSA (não recria)"
  - "BarraDeSelecao / ModalDeLote / ToastDaFila / RodapeDaFila / VazioDaFila"
  - "A seleção em lote na URL (?sel=), saneada contra a conta"
affects:
  - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx — os 3 slots da tabela ligados + as 3 peças fixed"
  - "app/Http/Controllers/MlbPublicadorAprovacaoController.php — selecaoSaneada() + aprovarLote()"
  - "SEIS pendências das waves 4/6/7/8 invertidas (ver §pendências)"
tech-stack:
  added: []
  patterns:
    - "Sem portal: as peças `fixed` são irmãs do header, porque o render de SERVIDOR não desenha portal e o gate passaria POR OMISSÃO"
    - "Named exports das peças internas (REND-05), a solução da Fase 178 para o que o render de servidor não alcança"
    - "Timer tratado por FUNÇÃO PURA, com o runtime DECLARADO como não provado"
    - "Literais de tela montados no SERVIDOR e LIDOS pelo .jsx — uma verdade só"
key-files:
  created:
    - "app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php"
    - "resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx"
    - "resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx"
    - "resources/js/Components/Mlb/Publicador/aprovacao/ModalDeLote.jsx"
    - "resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx"
    - "resources/js/Components/Mlb/Publicador/aprovacao/RodapeDaFila.jsx"
    - "resources/js/Components/Mlb/Publicador/aprovacao/VazioDaFila.jsx"
    - "tests/Feature/Publicador/Aprovacao/LoteDaFilaTest.php"
    - "tests/js/publicador-fila-lote-187.test.js"
    - "tests/gates/contagens-187-09.txt"
  modified:
    - "routes/mlb_anuncios.php"
    - "app/Http/Controllers/MlbPublicadorAprovacaoController.php"
    - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx"
    - "tests/js/publicador-vocabulario-novo.test.js"
    - "tests/js/publicador-fila-zindex-187.test.js"
    - "tests/js/publicador-fila-{cabecalho,filtros,linha,proximo,tabela}-187.test.js"
    - "tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php"
    - "tests/gates/contagens-187-04.txt"
decisions:
  - "Aprovar JÁ PUBLICA, sem segundo clique: nenhum 'confirmar publicação', 'desfazer', 'segundo passo' nem 'cancelar aprovação' — gate de ausência com isca por FORMA, valendo para 187-194"
  - "Os DOIS vazios honestos (decisão 16 do usuário): 'neste filtro' + botão COM filtro; texto honesto e ZERO botão sem filtro"
  - "A casca de modal nasce nesta fase e a Fase 188 a REUSA — sem isso haveria duas cascas divergindo"
  - "Sem portal, e as três peças fixed são irmãs do header (o `<main>` do AppLayout é intocável)"
  - "O modal abre por estado LOCAL; a seleção vive na URL. 'Modal aberto' é gesto, não dado"
metrics:
  duration: "~1 sessão"
  completed: "2026-10-17"
  commits: 5
  mutacoes: "17 rodadas, 17 mortas, 0 sobreviventes"
---

# Fase 187 Plano 09: O lote, os toasts, o rodapé e os DOIS avisos de tela vazia — Summary

O lote da fila ficou operável e a tela ficou apresentável: `LoteDaFilaService` dá o
**veredicto de TODAS as selecionadas** (✓/× com o motivo de cada exclusão), a rota POST
`mlb.anuncios.meus.aprovacao.lote` é a **primeira escrita da fila** (no banco — a escrita no
ML é da Fase 193), e seis componentes novos desenham a barra flutuante, a casca de modal que
a Fase 188 reusará, o modal de conferência, o toast, o rodapé de legendas e **os dois avisos
de tela vazia honestos**.

---

## ⚠️ ANTES DE QUALQUER COISA: o que esta sessão NÃO viu

**Nenhum executor desta fase viu tela renderizada, e isso não é modéstia — é a condição do
ambiente**, re-medida aqui:

- o banco local **não tem empresa com `ml_token`** → `meus()` faz `abort_unless(…, 404)`;
- `pub_aprovacao_ofertas` está **vazia**, e **nada nesta fase cria oferta** (quem cria é a
  **Fase 194**, `AUTO-01`);
- **não há navegador**: todo render é `esbuild` + `react-dom/server` com fixture sintético, e
  todo CSS é o construído em `public/build/assets/*.css`;
- a leitura de produção está **bloqueada** nesta sessão.

➡️ **Toda afirmação deste documento sobre "a tela mostra X" significa "o HTML gerado contém
X".** A conferência visual é **em produção, na conta 459, depois de um deploy que SÓ O USUÁRIO
AUTORIZA** — e é o ponto de parada deste plano (§checkpoint).

---

## §baselines — RE-MEDIDAS no HEAD, não ajustadas

Partida: **`f75864d0`**, capturada na primeira ação. Referência do 187-08: **PHP 2445 /
21.463 / 2 falhas** e **JS 5409 / 5406 pass / 1 fail / 2 skipped**.

| suíte | ANTES (ref. 187-08) | DEPOIS (medido no fim) | leitura |
|---|---|---|---|
| PHP | 2445 / 21.463 / **2 falhas** | **2483 / 21.818 / 0 falhas** | +38 testes, +355 asserções, **as 2 falhas alheias ficaram VERDES** |
| JS | 5409 / 5406 pass / 1 fail / **2 skipped** | **5483 / 5482 pass / 1 fail / 0 skipped** | +74 testes, **os 2 skips ZERARAM** |

### As duas falhas PHP alheias — e elas ficaram verdes sozinhas

Eram `tests/Feature/Publicador/CockpitDaContaTest.php` (**Fase 179**, em produção), `:966` e
`:1318` — **fragilidade de FRONTEIRA DE DIA**. **Não as corrigi, não criei `REF_PHP_FALHAS`, e
não as contei como regressão minha.** Na medição final elas passaram, o que **confirma** o
diagnóstico de fronteira de dia (o relógio andou). O subagente do 187-10 mediu a mesma coisa.

⚠️ **A régua não foi afrouxada:** não existe `REF_PHP_FALHAS`, e a suíte PHP termina com
**zero** falhas. Contagem acima de 0 agora, ou falha num teste diferente, é regressão.

### A falha JS é a pré-existente, e continua

`tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce recolhido"*
(**Fase 87**). **Não corrigida**, de propósito: é a falha única que `REF_JS_FALHAS=1` aceita
desde a Fase 186.

### ⚠️⚠️ Os DOIS `skipped` zeraram, e eram exatamente os que esta wave devia zerar

Os dois blocos que pulavam eram os de `pub-float`/`pub-float-line` **dormindo**. Os dois
tokens foram declarados em `tailwind.config.js` para a **barra de lote** (`:897`) e o **toast**
(`:905`) — que são deste plano. A wave 4 recusou acendê-los fora do lugar medido e a wave 8
tinha gate provando que continuavam dormindo. **Esta wave é o lugar medido deles.**

Conferido no CSS construído **pelo JSON do manifest, nunca por grep**:

```
bg-pub-float            →  background-color: rgb(28 28 28 / var(--tw-bg-opacity, 1))   # #1C1C1C
border-pub-float-line   →  border-color:     rgb(51 51 51 / var(--tw-border-opacity,1))# #333333
ISCAS (ausentes, como devem): duration-[220ms] · bg-pub-isca-187-09 · w-[991px] · text-[98.5px] · h-[461px]
manifest: 495 entradas / 202 páginas · Aprovacao.jsx PRESENTE · isca NaoExiste9z7 AUSENTE
```

E há gate provando que eles aparecem **só** na barra e no toast (`deepEqual` da lista de
donos), porque token aceso fora do lugar medido é o que a wave 4 recusou.

---

## §FID-01 — a tabela, medida por SCRIPT

⚠️ **MEDIDO POR SCRIPT**, não por leitura: `python` lendo
**`design_handoff_publicador/Aprovacao.dc.html`** (1432 linhas, sha256 `68624883ce35547e`).
**Cito o diretório porque há DUAS cópias do pacote de handoff na árvore com conteúdo
DIFERENTE** (`design_handoff_publicador/` e `new_layout_publicador/`); este plano mediu a
primeira, e só ela. ⚠️ O pacote **não é versionado** — o gate que depende dele degrada para
`skip` explícito e nomeado.

`cursor`, `white-space` e `text-overflow` em **colunas próprias** (`cursor:pointer` já foi
omitido **onze** vezes numa fase deste projeto):

| # | elemento | `cursor` | `white-space` | `text-overflow` | demais propriedades |
|---|---|---|---|---|---|
| `:186` | vazio: caixa | — | — | — | `display:flex` `flex-direction:column` `align-items:center` `gap:8px` `padding:56px 24px` `text-align:center` |
| `:187` | vazio: título | — | — | — | `font-size:15px` `font-weight:600` |
| `:188` | vazio: botão "Limpar filtros" | **`pointer`** | — | — | `height:32px` `padding:0 14px` `border:1px solid #2E2E2E` `border-radius:8px` `background:#111111` `color:#F4F4F5` `font-size:12.5px` |
| `:191` | rodapé | — | — | — | `display:flex` `align-items:center` `justify-content:space-between` `height:42px` `padding:0 16px` `border-top:1px solid #1F1F1F` `background:#0D0D0D` `font-size:12px` `color:#71717A` |
| `:192` | rodapé: grupo de legendas | — | — | — | `display:flex` `align-items:center` `gap:14px` |
| `:193` | rodapé: item de legenda | — | — | — | `display:flex` `align-items:center` `gap:6px` |
| `:193` | legenda 1 · anel Não analisada | — | — | — | `width:10px` `height:10px` `border-radius:50%` `border:1.5px solid #FACC15` |
| `:194` | legenda 2 · anel Revisão parcial | — | — | — | `…` `border:1.5px solid #A1A1AA` `background:linear-gradient(90deg,#A1A1AA 50%,transparent 50%)` |
| `:195` | legenda 3 · anel Aprovada | — | — | — | `…` `background:#22C55E` |
| `:196` | legenda 4 · quadrado foto aprovada | — | — | — | `…` `border-radius:3px` `background:#22C55E` |
| `:197` | legenda 5 · quadrado a conferir | — | — | — | `…` `border-radius:3px` `border:1.5px solid #FBBF24` |
| `:198` | legenda 6 · quadrado com alerta | — | — | — | `…` `border-radius:3px` `border:1.5px solid #EF4444` `background:rgba(239,68,68,0.3)` |
| `:200` | rodapé: "Restaurar dados de exemplo" — **NÃO EXISTE no produto** | **`pointer`** | **`nowrap`** | — | `border:none` `background:transparent` `color:#71717A` `font-size:12px` `text-decoration:underline` `text-underline-offset:3px` |
| `:775` | modal: backdrop | — | — | — | `position:fixed` `inset:0` `z-index:100` `background:rgba(0,0,0,0.65)` `display:flex` `justify-content:center` `align-items:flex-start` `padding-top:96px` |
| `:776` | modal: caixa 560px | — | — | — | `width:560px` `max-height:calc(100vh - 140px)` `overflow-y:auto` `background:#111111` `border:1px solid #2E2E2E` `border-radius:16px` `box-shadow:0 30px 80px rgba(0,0,0,0.8)` `display:flex` `flex-direction:column` |
| `:777` | modal: topo | — | — | — | `display:flex` `align-items:flex-start` `justify-content:space-between` `gap:12px` `padding:18px 20px 14px` `border-bottom:1px solid #1F1F1F` |
| `:778` | modal: **WRAPPER** título/sub | — | — | — | `display:flex` `flex-direction:column` `gap:4px` |
| `:778` | modal: título | — | — | — | `font-size:16px` `font-weight:600` |
| `:778` | modal: sub | — | — | — | `font-size:12.5px` `color:#8A8A90` |
| `:779` | modal: botão × | **`pointer`** | **`nowrap`** | — | `width:30px` `height:30px` `border:none` `border-radius:7px` `background:transparent` `color:#A1A1AA` `font-size:16px` |
| `:781` | modal: corpo | — | — | — | `padding:16px 20px` `display:flex` `flex-direction:column` `gap:14px` |
| `:877` | lote: lista | — | — | — | `display:flex` `flex-direction:column` `border:1px solid #1F1F1F` `border-radius:10px` `overflow:hidden` |
| `:879` | lote: linha | — | — | — | `display:flex` `align-items:center` `gap:10px` `padding:10px 12px` `border-top:1px solid #1A1A1A` |
| `:880` | lote: glifo 18px | — | — | — | `width:18px` `height:18px` `border-radius:50%` `display:grid` `place-items:center` `font-size:10.5px` `font-weight:800` `flex:none` |
| `:881` | lote: **WRAPPER** nome+nota | — | — | — | `display:flex` `flex-direction:column` `gap:2px` **`min-width:0`** |
| `:881` | lote: nome | — | — | — | `font-size:13px` `font-weight:500` |
| `:881` | lote: versão | — | — | — | `color:#71717A` `font-weight:400` |
| `:881` | lote: nota (o motivo) | — | — | — | `font-size:12px` `color:{{ r.noteColor }}` |
| `:885` | lote: nota do modal | — | — | — | `font-size:12px` `color:#A1A1AA` |
| `:888` | modal: rodapé | — | — | — | `display:flex` `justify-content:flex-end` `gap:8px` `padding:14px 20px` `border-top:1px solid #1F1F1F` `background:#0D0D0D` |
| `:889` | modal: botão Cancelar | **`pointer`** | **`nowrap`** | — | `height:36px` `padding:0 14px` `border:1px solid #2E2E2E` `border-radius:9px` `background:transparent` `color:#D4D4D8` `font-size:13px` |
| `:890` | modal: botão de submeter | **`pointer`** | **`nowrap`** | — | `height:36px` `padding:0 16px` `border:none` `border-radius:9px` `font-size:13px` **`font-weight:600`** |
| `:897` | barra: casca flutuante | — | — | — | `position:fixed` `left:50%` `bottom:24px` `transform:translateX(-50%)` `z-index:60` `display:flex` `align-items:center` `gap:6px` `padding:6px 6px 6px 16px` `background:#1C1C1C` `border:1px solid #333333` `border-radius:12px` `box-shadow:0 16px 40px rgba(0,0,0,0.6)` |
| `:898` | barra: rótulo + divisor | — | **`nowrap`** | — | `font-size:13px` `font-weight:500` `padding-right:10px` `border-right:1px solid #333333` `margin-right:4px` |
| `:899` | barra: botão aprovar | **`pointer`** | **`nowrap`** | — | `height:32px` `padding:0 14px` `border:none` `border-radius:8px` `font-size:13px` `font-weight:600` |
| `:900` | barra: botão × | **`pointer`** | **`nowrap`** | — | `width:32px` `height:32px` `border:none` `border-radius:8px` `background:#2E2E2E` `color:#F4F4F5` |
| `:905` | toast: casca | — | — | — | `position:fixed` `left:50%` `top:124px` `transform:translateX(-50%)` `z-index:120` `display:flex` `align-items:center` `gap:12px` `padding:12px 16px` `background:#1C1C1C` `border:1px solid #333333` `border-radius:12px` `box-shadow:0 16px 40px rgba(0,0,0,0.6)` `max-width:560px` |
| `:906` | toast: dot 8px | — | — | — | `width:8px` `height:8px` `border-radius:50%` `flex:none` |
| `:907` | toast: **WRAPPER** texto+sub | — | — | — | `display:flex` `flex-direction:column` `gap:2px` |
| `:907` | toast: texto | — | — | — | `font-size:13.5px` `font-weight:600` |
| `:907` | toast: sub | — | — | — | `font-size:12px` `color:#A1A1AA` |

**`text-overflow` não aparece em nenhum dos 41 elementos** — o truncamento do nome e do motivo
no modal é `truncate` do Tailwind (que traz os três), e ele vem com `title` do texto inteiro.

---

## §divergências do plano — TODAS medidas, com `arquivo:linha`

### 1. ⚠️⚠️ CINCO omissões da tabela FID-01 do plano, achadas por script

| o que o plano OMITIU | onde | consequência se eu tivesse seguido a tabela |
|---|---|---|
| o **WRAPPER** `flex flex-col gap-[4px]` do par título/sub | `:778` | título e sub **lado a lado** no topo do modal |
| o **WRAPPER** `flex min-w-0 flex-col gap-[2px]` do nome+nota | `:881` | a linha não empilha nome e motivo, e **sem `min-width:0` o nome longo estoura** em vez de truncar |
| o **WRAPPER** `flex flex-col gap-[2px]` do texto+sub do toast | `:907` | texto e sub **lado a lado** |
| `white-space:nowrap` | `:898`, `:899`, `:900`, `:779`, `:889`, `:890` | rótulo e botões da barra quebrando em duas linhas |
| `cursor:pointer` | `:899`, `:900`, `:779`, `:889`, `:890` | cinco botões sem cursor de mão |
| `font-weight:600` no botão de submeter | `:890` | o botão de aprovar com peso de texto comum |

### 2. ⚠️⚠️ Os ponteiros de linha do plano erram — **9 de 41** elementos

Continuando a série medida wave a wave (9/16 · 3 · 7/12 · 8/9 · 13/20 · 11/17 · 19/25):

| o plano diz | o REAL é | delta |
|---|---|---|
| vazio em `:189`-`:194` | **`:185`-`:188`** | −4 a −6 |
| rodapé em `:196` | **`:191`** | −5 |
| grupo de legendas em `:197` | **`:192`** | −5 |
| as 6 legendas em `:198`-`:203` | **`:193`-`:198`** | −5 |
| "Restaurar dados de exemplo" em `:204` | **`:200`** | −4 |
| a nota do modal em `:884` | **`:885`** | +1 |

Os **VALORES** da tabela do plano estão certos; os **PONTEIROS**, não. Confirma o padrão: as
tabelas deste projeto erram **por omissão e por referência, quase nunca por valor**.

### 3. ⚠️⚠️ O `·` do rótulo da barra **NÃO é caractere** — é `border-right`

O plano descreve a barra como *"N selecionadas · Aprovar K para publicação · ×"*. **Medido em
`:898`:** o separador é `border-right:1px solid #333333` + `padding-right:10px` /
`margin-right:4px`. Um `·` literal ali desenharia **dois pontos médios ao lado de duas
bordas**. Há asserção contando **zero** `·` no HTML da barra, para o mal-entendido não virar
código.

### 4. ⚠️⚠️ "Irmãs do `<main>`" é IMPOSSÍVEL sem tocar o `AppLayout` — e o invariante foi preservado

O plano prescreve as três peças `fixed` como *"irmãs do `<main>`"*, como no protótipo (`:896`,
`:904`). `AppLayout.jsx:1055`-`:1057` renderiza
`<main className="flex-1 overflow-y-auto p-6 animate-fade-in">{children}</main>`, e **aquele
arquivo é INTOCÁVEL nesta fase** (D2). ➡️ Elas são **irmãs do `PublicadorHeader`** e ficam
**fora do container do corpo**, que é o que importa: `backdrop-filter` cria containing block
**só para DESCENDENTES**, e o header é o único elemento desta árvore que o tem.

**Medido na camada, não presumido:** `<main>` não cria containing block (`overflow-y-auto` e
`p-6` não criam), e o único `transform` da árvore é o keyframe `fade-in`
(`tailwind.config.js:288`), que roda **`0.3s` sem `fill-mode`** — depois disso o transform
sai. Há gate lendo as duas coisas, e o gate reprova se o `<main>` ganhar `backdrop-filter`.

### 5. Os ids do plano × os ids reais (e por quê)

| o plano prescreve | existe? | por quê |
|---|---|---|
| `lote-servidor` | **não** | cobriria 9 métodos numa alternância só; alternância grande equivale a filtro de CLASSE, passa dos 120 s e o helper **RECUSA**. Virou `lote-veredicto` + `lote-escrita` + `lote-rota` |
| `rodape-e-vazio` | **não** | seria um 2º id sobre o **MESMO** arquivo JS de `lote-render` → a mesma contagem duas vezes e **nenhum dos dois medindo a sua metade**: gate decorativo por construção |
| `lote-render` | **sim** | o id único do arquivo JS |

Mais `selecao-na-url` (nascido de uma mutação sobrevivente), `territorio-lote` e
`bijecao-manifesto-09`. **Nenhum método ficou de fora** — a bijeção é provada por script, nos
dois sentidos.

### 6. O toast do lote diverge do protótipo, de propósito

`:1167` usa como texto principal `"N ofertas aprovadas"`. Aqui o principal é o literal
**TRAVADO** `TOASTS.aprovado` (*"Aprovado — aguardando publicação"*, VOC-08) e a contagem
desce para o sub. Inventar um sétimo texto de toast criaria uma frase fora do vocabulário
travado, que tem gate de literal.

### 7. A legenda **não tem** o anel de FALHA nem o quadrado de REGENERANDO

**Medido** em `:193`-`:198`: são exatamente 6 entradas, embora os dois estados existam na
tabela. **Reproduzido e registrado**, não "consertado" — acrescentar entradas seria desenho
novo sem pedido. Há asserção de que a legenda tem **exatamente 6** e que nem "Falha na
publicação" nem "regenerando" aparecem.

### 8. `font-[800]` e não `font-extrabold` — o GATE-03 me reprovou, corretamente

`:880` é `font-weight:800`. A primeira redação usou `font-extrabold`, e o **GATE-03 reprovou**:
ele recusa a escala NOMEADA de peso fora de 400/500/600/700. `font-[800]` é o mesmo peso
computado por fora da escala, e é o que as outras seis peças de `aprovacao/` já escrevem.

### 9. O `whitespace-nowrap` que eu pus por SIMETRIA saiu

**Medido:** dos sete botões desta wave, `:188` é o **único SEM** `white-space:nowrap`. A
primeira redação o pôs ali por simetria com os vizinhos — **e o meu gate o exigia junto**, os
dois errados pelo mesmo motivo, e por isso nada acusou. **O protótipo ganha**: saiu, e a
asserção virou a negativa com o motivo medido escrito. *"Ficou mais parecido com o resto"* é
exatamente o que FID-02 recusa.

---

## §os DOIS avisos de tela vazia — decisão 16 do usuário

O protótipo tem **um só** (`:187` + `:188`), porque **no protótipo sempre existem ofertas** (a
semente do navegador cria três). No produto, **zero oferta sem filtro nenhum é um estado
REAL**.

| caso | título (literal) | corpo | botão |
|---|---|---|---|
| **COM** filtro, zero resultado | `Nenhuma oferta neste filtro` (`:187`) | — | **`Limpar filtros`** (`:188`) |
| **SEM** filtro, zero oferta | `Nenhuma oferta aguardando aprovação.` | `As ofertas entram nesta fila quando o sistema termina de preparar o anúncio. Enquanto isso, nada aqui é um erro.` | **NENHUM** |

**São DOIS textos diferentes, não um com variação cosmética**, e a prova é de três lados:

1. o literal do protótipo **não aparece** no caso sem filtro — ele **mentiria** (não há
   filtro);
2. **o botão NÃO EXISTE no segundo caso, mesmo COM handler** — `assert.deepEqual(botões, [])`.
   Botão "Limpar filtros" sem filtro nenhum **não teria o que limpar**, e botão que não faz
   nada ensina o usuário a desconfiar dos outros;
3. `assert.notEqual(comFiltro, semFiltro)` — se um refactor colapsasse os dois, isto acusa.

⚠️ **Precedente MEDIDO no próprio módulo**, citado no arquivo:
`publicacoes/VaziosDaLista.jsx` (Fase 180) resolveu este mesmo dilema na tela vizinha, e o
comentário dele diz textualmente *"seria MENTIR: o servidor disse que o RECORTE está vazio,
não que a empresa não tem anúncio."* Há asserção de que o precedente existe **e** de que o meu
arquivo o cita.

⚠️ `algumFiltro` é comparado **estritamente** (`=== true`): `'false'`, `1`, `0`, `{}`, `[]`,
`null`, `NaN` e `{toString:null}` **todos** caem no vazio honesto. Quando em dúvida a tela diz
a verdade mais fraca, não a mais específica.

⚠️⚠️ **E o segundo vazio vale a partir do dia 1, declarado:** nada nesta fase cria oferta —
quem cria é a **Fase 194** (`AUTO-01`: a oferta nasce em `preparando`). Até lá, **fila
honestamente vazia não é aba apontando para o vazio**: a aba leva a uma tela que **funciona**,
com contador real (que será 0 enquanto for 0). Isso está escrito no arquivo e tem gate.

---

## §o lote — a barra, o modal e a ausência do segundo passo

### A barra flutuante

- `3 selecionadas` / `1 selecionada` (**`> 1` é plural**, medido em `:1282` — zero sai no
  singular);
- `Aprovar K para publicação` — **o rótulo é esse e não muda**;
- **só aparece com seleção > 0**: sete payloads inválidos (incluindo `lote` nulo, `[]`,
  string, número, rótulo vazio) renderizam **string vazia**, com isca cega provando que ela
  desenha com payload válido;
- com **K=0** o botão fica apagado (`bg-pub-seg text-pub-txt-4`) **E `disabled`** — porque
  **cor nunca é o único sinal**;
- **sem handler, ZERO botão** — e foi este caso que achou um defeito meu (§achados).

### O modal lista TODAS, com ✓/× e o MOTIVO

*"Aprovar só as que passam, silenciosamente"* foi **RECUSADO** pelo usuário; a escolha foi
*"tudo de uma vez, e só libera com todas conferidas"*. A fixture do gate tem **uma apta e DUAS
inaptas, com motivos e cores DIFERENTES** (fixture que discrimina — na wave 6 as sobreviventes
eram falha da fixture, não da asserção):

- 3 glifos para 3 selecionadas, 1 verde e 2 cinzas, com asserção de que os de fora **não**
  ficaram verdes;
- as duas cores de motivo **diferentes entre si** (`notEqual` das classes);
- `truncate` **+ `title` do texto INTEIRO** nos três — truncar sem `title` deixaria
  **inalcançável** a frase que diz o que consertar;
- a nota de `:885` **byte a byte**, com o `✓` **U+2713**, e isca do quase-igual (`v`).

### Os DOIS caminhos do motivo — e eles não são variação cosmética

| caso | motivo | fonte |
|---|---|---|
| inapta **AO VIVO** | `Fica de fora: ` + as **ABREVIAÇÕES** dos bloqueios, por `", "` | `VocabularioDoPortao::ABREVIACOES`, **LIDO** |
| inapta **FORA do ao vivo** | `Fica de fora: ` + o **RÓTULO DO ANDAMENTO** em minúsculas | `PubAprovacaoOferta::ANDAMENTOS` via `rotulo()`, com **`mb_strtolower`** |

Uma oferta `publicado` **não tem bloqueio nenhum**: um serviço que só soubesse o primeiro
caminho mostraria `×` **sem motivo** exatamente nela. A isca põe os dois lados no **mesmo
caso**, com `assertNotSame` dos dois motivos — se um refactor os unificasse, acusa. E
`mb_strtolower` (não `strtolower`): "Aguardando aprovação" tem `ç` e `ã`.

⚠️ Chave sem abreviação cadastrada **não vira a chave crua na tela** (jargão é vetado): cai
em `MOTIVO_DESCONHECIDO` (`'pendência em aberto não identificada'`), nunca em
`"Fica de fora: "` sozinho, que é frase truncada.

### ⚠️⚠️ A ausência do segundo passo — T-187-93, com isca por FORMA

**Aprovar JÁ PUBLICA, sem segundo clique** (decisão 3, 14/10/2026). A sonda é ancorada no
**SUJEITO, com os acentos**:

```
/(confirmar\s+publica|confirme\s+a\s+publica|desfazer\s+(a\s+)?aprova
 |cancelar\s+(a\s+)?aprova|segundo\s+passo|revisar\s+e\s+aprovar)/iu
```

| isca | deve casar? | resultado |
|---|---|---|
| `Confirmar publicação` | **sim** | casa |
| `Cancelar aprovação` | **sim** | casa |
| `Desfazer aprovação` | **sim** | casa |
| `Revisar e aprovar` | **sim** | casa |
| `Aprovar para publicação` | **NÃO** | não casa |
| `Aguardando aprovação` | **NÃO** | não casa |
| `Aprovado — aguardando publicação` | **NÃO** | não casa |

⚠️ `aprovad` **não casa** "aprovação", e o par solto
`(aguardand|pendent) × (autoriza|aprovad)` casaria **10 ocorrências legítimas** da área.
⚠️ **E o rótulo CERTO é provado presente** em três lugares (fonte da barra, constante do
serviço, HTML renderizado) — negativa sozinha vira falso positivo no dia em que alguém apagar
a barra. O gate roda em **PHP** (sobre os 7 arquivos, sem comentário) **e em JS**, e vale para
as Fases **187-194**.

**E o protótipo também não tem segundo passo**, com asserção: a decisão 3 não é desvio do
desenho — ela **é** o desenho.

---

## §a escrita — a fronteira, e ela é provada por ausência de requisição

`aprovarEmLote` move o andamento, grava os três campos de aprovação, marca `tocado` e congela
a composição — **tudo no nosso banco**. O gancho `aoAprovar`, que fala com o Mercado Livre,
está **VAZIO** e é da **Fase 193**.

**Provado por AUSÊNCIA DE REQUISIÇÃO**, não por leitura de código: `Http::fake()` +
`preventStrayRequests()`, varrendo o histórico por `POST/PUT/PATCH/DELETE` → **lista vazia**.
⚠️ **Isca nas duas direções:** a tarifa (`/sites/MLB/listing_prices`) **É** consultada, então
a negativa **não** passou por *"nada foi registrado"* — que é o jeito clássico de um gate de
ausência virar decoração.

### ⚠️ A fronteira do D-11, declarada

`tests/js/estrutura-meus-anuncios.test.js` prova que **a LISTA de Publicações nunca escreve no
Mercado Livre**, e isso **continua verdade**: aquele gate é sobre os arquivos da lista, e
**este plano não os toca** (`numstat` ZERO, conferido). **A FILA escreve** — e nesta fase, só
no banco. **O D-11 não foi estendido à área nova nem afrouxado na lista.**

### ⚠️⚠️ A versão esperada: MEDIÇÃO, não precaução

`AprovacaoDaOfertaService:258` faz `$versao = (int) $oferta->versao` e passa isso para
`aprovar()` — a versão **do próprio modelo que ele acabou de receber**. No caminho do POST o
modelo é recém-carregado, então esperado e atual são **sempre iguais** e
`COL-04-VERSAO-DIVERGENTE` **nunca dispararia**. Delegar a conferência a ele seria **uma trava
que não trava**.

➡️ A versão que importa é a que a pessoa **VIU no modal**, vem no corpo do POST (`versoes`), e
é conferida em `LoteDaFilaService::aprovar()`. A recusa é **só dela** — as outras passam —, e
há **isca na outra direção** (sem o mapa, as duas passam), sem a qual uma implementação que
recusasse tudo passaria no caso principal.

### O gate de escrita da área nova

`role:admin` do grupo (sem middleware novo) · `abort_unless($company->mlToken !== null, 404)`
com a mensagem **byte a byte** igual à do GET (com isca) · as ofertas conferidas contra
`paraAConta()` **por CONSULTA**, nunca por `in_array` · CSRF normal ·
`throttle:30,1,publicador.aprovacao.lote` · `integer`+`min:1` na validação (`"3abc"` → 422,
nunca `3` em silêncio) · as inaptas conferidas **campo a campo** (andamento, versão, `tocado`,
`aprovado_por`), porque *"não mudou o andamento"* é mais fraco que *"ficou exatamente como
estava"*.

---

## §o portal — a armadilha central, e por que ela deixaria o gate VERDE

**É o furo mais perigoso deste plano**, e a razão é que ele **não deixa vermelho**: o
renderizador de **servidor** do React não desenha portal (*"Portals are not currently
supported by the server renderer"*), então um `createPortal` faria `renderToStaticMarkup`
devolver **string vazia** e **todos** os blocos de geometria passariam **POR OMISSÃO**.

- gate de ausência de `createPortal|react-dom/client|Dialog.Portal|Popover.Portal` nos 6
  arquivos, com isca cega provando que a sonda pega as formas;
- **contraprova do motivo**: `assert.ok(html.length > 100)` — sem ela, a negativa seria
  satisfeita por um arquivo que não renderiza nada;
- **mutação M12** (`import { createPortal }`) → **MORREU**.

⚠️ **Por que aqui não precisa de portal, e na Fase 178 precisava:** aquele modal vive
**dentro do header**, que tem `backdrop-blur-[14px]`; `backdrop-filter` cria containing block
**só para descendentes**. As minhas três peças são **irmãs** do header.

⚠️ **E as peças internas saem como NAMED EXPORTS** (REND-05) — a solução da Fase 178 para o
que o render de servidor não alcança: 15 nomes conferidos, e as duas peças internas
(`TopoDoModal`, `RodapeDoModal`) **renderizadas sozinhas**, porque named export que não pode
ser montado é só um símbolo exportado.

⚠️ **Os popovers da wave 6 não foram tocados** — eles são `absolute` dentro de pai `relative`
(medido), e não são o mesmo caso.

---

## §o z-index — remapeado preservando a ordem travada

A escala do protótipo é **incompatível** com a deste projeto (lá o header é 50; aqui é 30, e a
ordem é **travada**: `filtros 10 < header 30 < menu lateral = painel 40`).

| peça | protótipo | **daqui** | por quê |
|---|---|---|---|
| barra de lote | `:897` z-60 | **`z-[35]`** | acima do header `z-30`, **abaixo** do menu/painel `z-40` |
| modal | `:775` z-100 | **`z-[42]`** | acima do menu/painel, abaixo do toast |
| toast | `:905` z-120 | **`z-[45]`** | acima de tudo — é o aviso do que acabou de acontecer |

`publicador-fila-zindex-187.test.js` tinha a **prescrição PINADA** dessas peças, com asserção
de que os arquivos **não existiam** — exatamente para forçar a troca por LEITURA quando
nascessem. **Foi o que fiz:** a prescrição virou leitura da fonte, com âncora
`z-\[(\d+)\]` (as três usam valor arbitrário, porque 35/42/45 **não existem** na escala
nomeada do Tailwind), e isca provando que a âncora **não** casa `z-42` sem colchete.

⚠️ **E o nome real é `BarraDeSelecao.jsx`**, não `BarraDeLote.jsx` como a prescrição dizia.
Corrigi o nome; inventar um arquivo para casar uma string antiga seria criar arquivo para
alimentar um gate.

⚠️⚠️ `publicador-header-zindex-ordem.test.js` ficou **INTOCADO** (`numstat` ZERO), e há
asserção de que ele continua provando a ordem dele.

---

## §os toasts — função pura, e o runtime DECLARADO como não provado

- Os **6 literais** vêm de `vocabularioDoPortao.jsx` **IMPORTADOS**, com gate provando que
  **nenhum dos seis está escrito** nos meus arquivos (um literal aqui seria a segunda cópia);
- o travessão **U+2014** chega ao HTML, com asserção de que **não** virou hífen;
- `textoDoToast` nunca devolve a **chave crua** e **não LANÇA** com `{toString:null}`,
  `{hasOwnProperty:null}`, `Object.create(null)`, array, número, `NaN`;
- **nesta fase só `aprovado` dispara**, e a página dispara **exatamente** `['aprovado']`
  (`deepEqual`, não "contém") — negativa sozinha passaria se ninguém disparasse nada;
- **os DONOS dos outros cinco estão nomeados no arquivo**: `revisao_salva` /
  `ajuste_solicitado` / `foto_reprovada` → **188**; `gerando_com_ia` → **192**;
  `publicado` → **193**;
- ⚠️⚠️ *"Publicado e confirmado no Mercado Livre · MLB…"* **exige MLB confirmado** (AND-04 /
  VOC-08): afirmá-lo sem o MLB seria **mentir sobre o anúncio do cliente**. Gate provando que a
  página **não** o dispara;
- o toast só nasce quando **alguma** oferta foi de fato aprovada — "0 aprovadas" não é sucesso.

### ⚠️ O timer: 3800 ms pela FUNÇÃO PURA, e o que NÃO é provado

`agendarOToast(aoSumir, relogio, anterior)` recebe o relógio **por parâmetro** (injeção), e o
gate prova: o número é **3800**, a ordem é **cancelar → agendar** (sem o cancelamento, dois
toasts em sequência fariam o timer do primeiro apagar o segundo antes dos 3,8 s dele), que
**não** cancela timer inexistente, e que não LANÇA com nove relógios adversos. Iscas de **380**
e **38000**.

⚠️⚠️ **O RUNTIME DO TIMER NÃO É PROVADO, e isso está declarado no teste e no componente:**
não há navegador neste ambiente. Que o navegador honre o `setTimeout` é confiança no runtime,
não medição — e dizer isso é mais honesto que um teste com `setTimeout` de verdade, que mediria
o Node, não a tela.

⚠️ `duration-[Nms]`/`delay-[…]`/`ease-[…]` **não compilam** neste projeto: a transição vai por
`style={{ transitionDuration: '160ms' }}`, com `duration-200` como **controle** no gate (que
compila: `transition-duration:.2s`) e `duration-[220ms]` conferido **ausente**.

---

## §o rodapé — as 6 legendas, e a ausência com ISCA

As 6 entradas de `:193`-`:198`, por índice **ABSOLUTO** na árvore: 3 anéis (`rounded-full`) e
3 quadrados (`rounded-[3px]`), com asserção de que os quadrados **não** saíram redondos e de
que as **6 pinturas são todas diferentes** (`new Set(...).size === 6`) — se duas colapsassem, a
legenda explicaria duas coisas com o mesmo desenho.

⚠️ **A CAIXA dos literais importa e foi preservada:** os três primeiros com MAIÚSCULA (são
estados da **oferta**), os três últimos com minúscula (são estados de **foto**). Parece
inconsistência e **não é** — é o que separa as duas famílias na mesma linha. Há asserção de
que "Foto aprovada" e "A conferir" **não** aparecem.

### ⚠️⚠️ "Restaurar dados de exemplo" — a ausência, com as DUAS iscas

FILA-16 e critério 13. É recurso de **protótipo** (`:200`), junto com o `localStorage`
(`KEY='ecf-aprovacao-v1'`, `:914`).

- ausência na **TELA** e na **FONTE SEM COMENTÁRIO** (`token_get_all` no PHP; remoção de bloco
  e de `//` no JSX);
- ⚠️ **ISCA DO LEITOR**: o arquivo **BRUTO** do rodapé **cita a frase e o `localStorage` de
  propósito** — se a remoção de comentário parasse de funcionar, as negativas reprovariam. É
  só por causa dela que eu sei que não estão cegas. *(Ler o arquivo COM comentário faria o
  gate reprovar **pelo próprio conserto** — medido em 5 dos 12 planos da Fase 184.)*
- ⚠️ **ISCA CEGA**: a legenda "Não analisada" **continua presente** e o HTML tem > 400 bytes —
  sem isso as negativas passariam por ausência;
- e o **protótipo TEM** a frase, com asserção: a ausência aqui é **decisão**, não esquecimento
  do desenho.

**Zero `localStorage`/`sessionStorage`** nos 7 arquivos e na página. A seleção e os filtros
vivem **na URL**.

---

## §as SEIS pendências das waves 4/6/7/8 — invertidas, não afrouxadas

A suíte JS saiu com **10 falhas** depois do meu trabalho. **Nenhuma era regressão de
comportamento**: todas eram asserções **negativas** cuja **própria mensagem nomeava o plano
que as tornaria falsas**. É o **item 6 da régua** — *asserção escrita quando a peça ainda não
tinha dono* — e a pergunta que elas deveriam ter respondido é *"o desenho PROÍBE isso, ou eu
só não vi ainda?"*. **O desenho não proibia.**

| # | o que dizia | arquivo | inversão |
|---|---|---|---|
| 1 | `'a seleção em lote é do plano 187-09'` | `publicador-fila-linha-187.test.js:1348` | → `match(/onMarcarVisiveis=\{marcarVisiveis\}/)`. Sem o handler, **o checkbox do cabeçalho não é desenhado** |
| 2 | idem | `publicador-fila-tabela-187.test.js:1031` | idem — o slot nasceu **nomeado e vazio** no 187-07 para esta wave o preencher |
| 3 | `'neste plano ela só LÊ'` | `publicador-fila-cabecalho-187.test.js:1508` | → **"a ÚNICA escrita é o POST do lote"**: `match` da rota + `=== 1` na contagem de `router.post` + as outras 6 formas ainda proibidas |
| 4 | `'nesta wave ela só LÊ'` | `publicador-fila-proximo-187.test.js:935` | idem |
| 5 | `'neste plano ela só LÊ'` (PHP) | `FilaDeAprovacaoRotaTest.php:1705` | idem. **170 → 171 asserções**, MEDIDO, com o motivo escrito em `contagens-187-04.txt` |
| 6 | `'os tokens CONTINUAM dormindo'` | `-linha-187:1251` e `-tabela-187:1178` | → `notEqual(regraDe(...), null)` + os **donos medidos**. Aquele `=== null` era um **PROXY**: o invariante daquelas waves é *"os arquivos DELAS não usam o token"*, que continua |
| 7 | a **ORDEM VERTICAL** por posição de `{noRodape}` | `-cabecalho-187:1456` e `-proximo-187:834` | o rodapé **saiu do nível da página** porque no **protótipo ele vive DENTRO da `<section>`** (`:191` < `</section>` `:202`). A ordem **não mudou**; passou a ser medida **onde mora**, em `TabelaDaFila.jsx` — mais forte que a posição de um token |
| 8 | `useState` da página **=== 1** | `publicador-fila-filtros-187.test.js:695` | **=== 2**, os dois **NOMEADOS** um por um (não virou `>=`). O segundo é `modalDoLoteAberto`, e *"modal aberto"* é **gesto**, não dado — na URL, um link compartilhado **abriria um modal de aprovação na cara de outra pessoa**, numa ação que publica e não tem desfazer |

⚠️ **As OITO contagens de teste dos gates JS vizinhos ficaram intactas** — conferido rodando os
8 ids, não suposto. Só `contagens-187-04.txt` mudou (+1), com o motivo no manifesto.

---

## §as mutações — 17 rodadas, 17 mortas, 0 sobreviventes

⚠️⚠️ **A CAMPANHA FOI REFEITA INTEIRA, e a razão é de integridade:** o scratchpad é
**COMPARTILHADO** com o subagente do plano 187-10, e as duas primeiras rodadas usaram nomes
**genéricos** (`mutar.sh`, `de.txt`, `para.txt`, `out.txt`). O 187-10 mediu que o `mutar.sh`
dele **foi sobrescrito pelo meu** no meio do trabalho. ➡️ **Nenhum veredicto feito com script
possivelmente trocado vale.** A rodada definitiva usa `…-187-09-waveNOVE` em **todos** os
nomes, e rodou com o 187-10 **já fechado** (sem colisão possível).

**Conferido antes de refazer:** todos os arquivos de produção batem com `git show HEAD:` (o
protocolo faz `sha256` com `tr -d '\r'` nos dois lados **depois de cada restauração**, e
nenhuma divergiu). Nenhuma restauração botada deixou dano.

Protocolo: commit **antes** · `cp` para o scratchpad · ocorrência **travada em 1 ANTES** (por
`str.count()`, porque **`grep -F -c` conta LINHAS, não ocorrências** — isso abortou 4 mutações
da rodada 1) · **linha conferida depois** · restauração **DA CÓPIA** em `trap EXIT` ·
`sha256` contra `git show HEAD:`. ⚠️ **`git checkout` nunca.**

| # | mutação | gate | veredicto |
|---|---|---|---|
| M1 | aprovar as **inaptas** também (no veredicto) | `lote-veredicto` | MORREU |
| M2 | tirar o **motivo** das inaptas | `lote-veredicto` | MORREU |
| M3 | mandar o lote **sem conferir a conta** | `lote-rota` | MORREU |
| M4 | `3800` → `380` | `lote-render` | MORREU |
| M5 | subir a barra para `z-40` (cobriria o menu) | `lote-render` | MORREU |
| M6 | pôr de volta "Restaurar dados de exemplo" | `lote-render` | MORREU |
| M7 | usar o literal do **filtro** no vazio **sem** filtro | `lote-render` | MORREU |
| M8 | pôr o toast **dentro** do container do corpo | `lote-render` | MORREU |
| M9 | dar **botão** ao vazio sem filtro | `lote-render` | MORREU |
| M10 | ignorar a **versão esperada** (COL-04) | `lote-escrita` | MORREU |
| M11 | criar um **segundo passo** na aprovação | `territorio-lote` | MORREU |
| M12 | usar **PORTAL** na casca | `lote-render` | MORREU |
| M13 | **esconder as inaptas** do modal (o que o usuário recusou) | `lote-render` | MORREU |
| M14 | tirar o `disabled` do botão apagado (cor como único sinal) | `lote-render` | MORREU |
| M15 | tirar o `title` do motivo truncado | `lote-render` | MORREU |
| M16 | aceitar id de **outra conta** no `?sel=` | `selecao-na-url` | MORREU **(ver §achados)** |
| M17 | plural de "selecionada" em `>= 1` | `lote-veredicto` | MORREU |

### ⚠️ Uma mutação que NÃO reprovou quando eu esperava — e isso foi informação

Na rodada 2, **M1 medido contra `lote-escrita` SOBREVIVEU**, e isso **não era furo**:
`veredicto()` é a camada de **TELA**, e a trava de aprovação é `aprovarEmLote` (BLOQ-04, **no
servidor**). Mutar a camada de tela **não muda quem é aprovado** — que é exatamente o desenho
da Fase 186. O gate certo para aquela mutação é `lote-veredicto`, e contra ele ela **morre**.

---

## §achados — os gates meus que reprovaram código CERTO, e o que a mutação achou

### ⚠️⚠️ A mutação sobrevivente que virou teste de comportamento (M16)

**MEDIDO:** trocar `paraAConta(...)->whereIn('id', $pedidos)` por
`PubAprovacaoOferta::query()->whereIn(...)` **dentro de `selecaoSaneada()`** (o caminho do
**GET**) **SOBREVIVEU a todos os gates**. A razão é exata: `lote-rota` exercita o **POST**, e a
conferência do GET era um **`assert.match` sobre o TEXTO do controller** — e `match` casa a
**primeira** ocorrência, que é a do POST. O gate ficava verde.

➡️ **Proxy de fonte não prova comportamento.** Nasceu
`test_sel_da_URL_com_id_de_OUTRA_conta_e_descartado_187_09` (id próprio `selecao-na-url`),
provando que a oferta de outra conta **não entra em `selecionados`**, **não entra no `lote`** e
que **o TÍTULO dela não vaza para as props** (T-187-96 — vazamento de informação); mais o lixo
da querystring (`abc`, `0`, `-4`, `12x` — `(int) '3abc'` é `3` e `(int) ''` é `0`, e os dois
entrariam num `whereIn` com cast em vez de `ctype_digit`), a isca na outra direção (a seleção
legítima **passa**) e a prova de que **sem `?sel=` o painel é `null`**. E o proxy de fonte
virou **contagem exata de 2** (os dois caminhos).

### Três gates meus que reprovaram código CERTO — ancorados no COMPORTAMENTO

| gate | por que estava largo/estreito demais | conserto |
|---|---|---|
| a lista `[44,45,50,60,100,120]` de números do protótipo | **`45` é o número do POPOVER**, e o toast legitimamente ficou com `z-[45]` na escala daqui | conferência **peça por peça** contra o número que o protótipo deu **A ELA** (60/100/120) — mais forte, porque a lista perdoaria um sétimo número |
| `backdrop-filter` varrido de `<main` até o **fim do arquivo** | há um `backdrop-blur-md` em `AppLayout.jsx:1068`, **FORA** do `</main>` (`:1057`) — é o aviso de ticket do canto | recorte **entre `<main` e `</main>`**, com isca nas duas direções |
| todo callback de `.map()` tendo de abrir corpo | `.map((id) => inteiroSeguro(id))` lê **nome de MÓDULO**, que o Rollup **nunca** elide | expressão aceita quando **tudo** que ela lê é parâmetro ou nome de módulo |

E um quarto, estreito demais: `/backdrop-filter cria containing block/` **não casava** o texto
da página, que tem backtick no meio. **Gate mais estreito que o invariante é o espelho do mais
largo, e dá o mesmo prejuízo.**

### Dois defeitos meus achados pelo render adverso e pelo "sem handler"

| defeito | como apareceu | conserto |
|---|---|---|
| a barra desenhava o botão "Aprovar …" **`disabled` sem handler nenhum** — botão com rótulo e **sem destino** (D4) | o caso "sem handler, zero botão" | o botão exige o handler. ⚠️ `disabled` por **K=0** é outra coisa e **continua**: ali o destino existe e a tela está dizendo *"nenhuma das selecionadas pode ser aprovada"* |
| a casca aceitava **qualquer array** como `children` e **DERRUBOU** com `[{}]` (*"Objects are not valid as a React child"*) | render adverso | filtro **elemento a elemento**; `null`/string/número passam, objeto comum não |

---

## §render adverso — incluindo NO CLIQUE

**20 formas** (`{}`, `[]`, `null`, `undefined`, `NaN`, `0`, `''`, `'x'`, `-1`, `1.5`, `true`,
`false`, `{toString:null}`, `{hasOwnProperty:null}`, `Object.create(null)`, função, símbolo,
`[[]]`, `[{}]`) × **11 campos do lote** + **8 campos da linha** + o campo **AUSENTE** (não
`undefined` — ausente de verdade) + a **prop inteira** em todas as seis peças + os 3 campos do
toast. `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`, e **nunca
`String()`/`JSON.stringify()` na mensagem de asserção** — é exatamente a chamada que LANÇA com
`{toString:null}`. Iscas provando que a sonda de lixo pega `[object Object]`, `NaN` e
`undefined`.

⚠️⚠️ **E NO CLIQUE:** `renderToStaticMarkup` **não dispara `onClick`**, e a tela preta da wave
6 foi **no clique**. Os handlers são exercidos **diretamente**: `agendarOToast`,
`textoDoToast` e `subDoToastDoLote` (exportada da página) com 11 formas adversas cada, com
asserção de que a saída é **string** e **não vaza lixo**, e isca cega provando que o chamador
de handler chama de verdade.

⚠️ **Flag DENTRO do callback** em todo `.map()`/`.flatMap()` dos meus arquivos (o Rollup
elimina variável de escopo do componente e o `ReferenceError` **só aparece no bundle**).

---

## §ARQUIVOS_NOVOS e o GATE-03

Os **6** `.jsx` entraram em `ARQUIVOS_NOVOS` de `publicador-vocabulario-novo.test.js`. **Sem
isso o GATE-03 passaria POR OMISSÃO** para eles (foi verde em **506** na wave 8), e o
`deepEqual` de `DIRETORIOS_FECHADOS` reprovaria na hora.

**GATE-03 VERDE: 548 subtestes, 548 pass, 0 fail.** A contagem **cresce por construção** (um
subteste por arquivo varrido), e é por isso que a forma dele é `>=443` — ele **não** entrou em
id nenhum deste plano (embuti-lo num id composto foi o que fez a wave 5 reprovar dois ids
alheios).

---

## §os gates e a bijeção

| id | modo | contagem medida |
|---|---|---|
| `lote-veredicto` | php | 3 testes / 50 asserções |
| `lote-escrita` | php | 3 / 26 |
| `lote-rota` | php | 3 / 23 |
| `selecao-na-url` | php | 1 / 13 |
| `territorio-lote` | php | 1 / 17 |
| `bijecao-manifesto-09` | php | 1 / 11 |
| `lote-render` | js | **26 pristino / 19 mutado** |

**Os DOIS REGIMES são medidos, não precaução:** a camada de CSS é 1 `test()` com 7 subtestes
que **pula** quando o CSS construído é mais antigo que as 7 fontes. Sem os dois regimes a
campanha de mutação abortaria na 1ª execução ou marcaria "sobreviveu" por um motivo que **não
é o código**.

**Bijeção nos DOIS sentidos**, com contagem de forma **fixa** (por isso acrescentar o id
`selecao-na-url` **não** mexeu no número): método sem id **nunca roda**, id órfão é gate sobre
**zero** casos. Isca do nome que ninguém escreveu.

### Homônimo contra a Fase 186 — conferido por SCRIPT, a cada execução

O `--filter` do PHPUnit é regex **não ancorado**, e todo `_0N` desta fase tem homônimo possível
na 186 (**em produção**). Os **12** nomes deste arquivo foram varridos contra **todo** `test_*`
de `tests/`, nas **duas** direções (meu contém o alheio / alheio contém o meu):
**NENHUMA COLISÃO**. Com asserção de que a varredura achou **mais de 100** métodos alheios —
sem ela, uma varredura cega passaria. **Nenhum sufixo precisou mudar**, e se precisasse o
conserto seria sufixo no método **novo**.

**`--autoteste`: 20 casos, 0 divergência. As duas formas REPROVAM quando devem.**

---

## §território — por ESCOPO DO ASSUNTO, nunca por range

Nesta árvore **três** sessões commitam com o **mesmo `user.name`** — o **autor não discrimina
nada**. O discriminador é `(187-09)`. **5 commits** no escopo, **22 arquivos** tocados.

**Caminhos proibidos com `numstat` ZERO** (com `test -e` **antes**, porque caminho fantasma dá
diff vazio e o gate fica verde medindo nada — a v26.0 teve **seis**): os 10 serviços da 186
fora de `Fila/`, os 3 `.jsx` da 186 em `aprovacao/`, `MlPublicacaoService.php`,
`App\Support\Publicador`, `app/Services/Creative`, `app/Mcp`, `AppLayout.jsx`,
`publicador-header-zindex-ordem.test.js`, `estrutura-meus-anuncios.test.js`, e os **4 arquivos
de dependência** (T-187-SC: nada foi instalado).

⚠️ **O `test -e` deste gate achou um caminho FANTASMA meu** e me obrigou a corrigi-lo:
`MlPublicacaoService.php` **não** está em `app/Services/Publicador/` — ele está em
**`app/Services/Mlb/Publicacao/`**. É exatamente o motivo de aquela asserção existir.

⚠️ **O 187-10 rodou em paralelo e fechou.** O `MlbPublicadorAprovacaoController` recebeu +22/-0
dele em `faf45f74`, **antes** do meu segundo commit — conferido: nada meu foi perdido
(`selecaoSaneada`, `aprovarLote`, `'marcada' =>`, `lote_aprovado`, `recusadas_por_versao` todos
presentes). ⚠️ **`$mlbEmpresaId` existe DUAS vezes agora** (`:195` no `index`, `:559` no meu
`aprovarLote`): quem editar ali **não deve usar âncora por número de linha**. Eu não toquei em
`AquecimentoDeTarifasService`, `AquecerTarifasDaFilaJob`, `WarmTarifasDaFila` nem
`MlbAnuncioController`. **E não mexi no conserto que ele fez do `SyncQueue`.**

---

## Known Stubs

Nenhum. Todas as peças desta wave têm dado real do servidor ou são estáticas por natureza (a
legenda do rodapé). Os pontos **nomeados e ausentes** são de outras fases, e cada um tem gate
provando a ausência: `onRevisarFotos` (Fase 188), o estado `aberta` da linha (Fase 188), os 5
toasts das Fases 188/192/193, e o gancho `aoAprovar` (Fase 193, vazio e documentado na 186).

---

## Threat Flags

Nenhuma superfície nova além do `<threat_model>` do plano. A única escrita nova é o POST do
lote (T-187-91/92/96), coberta por `role:admin` + trava de `ml_token` + conferência de conta
por consulta + CSRF + throttle + validação tipada, com teste de comportamento para cada uma.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A barra desenhava botão sem destino**
- **Found during:** Tarefa 2, no caso "sem handler, zero botão"
- **Issue:** o botão "Aprovar …" era desenhado `disabled` quando `onAbrir` faltava — botão com
  rótulo e **sem destino nenhum** (D4, proibido três vezes neste projeto)
- **Fix:** o botão exige `typeof onAbrir === 'function'`; o `disabled` por **K=0** continua
- **Files modified:** `BarraDeSelecao.jsx`
- **Commit:** `3db1474d`

**2. [Rule 1 - Bug] A casca derrubava a página com `children = [{}]`**
- **Found during:** Tarefa 2, render adverso
- **Issue:** `Array.isArray(children)` aceitava qualquer array; `[{}]` fazia o React lançar
  *"Objects are not valid as a React child"* — a tela preta
- **Fix:** filtro **elemento a elemento**
- **Files modified:** `CascaDeModal.jsx`
- **Commit:** `3db1474d`

**3. [Rule 2 - Missing] O `?sel=` não tinha teste de COMPORTAMENTO (T-187-96)**
- **Found during:** campanha de mutação (M16 sobreviveu)
- **Issue:** a conferência do saneamento do GET era proxy de FONTE e casava a ocorrência do
  POST; id de outra conta no `?sel=` passaria sem gate
- **Fix:** teste de comportamento próprio + contagem exata de 2 no proxy
- **Files modified:** `LoteDaFilaTest.php`, `publicador-fila-lote-187.test.js`,
  `contagens-187-09.txt`
- **Commit:** `752feea4`

**4. [Rule 1 - Bug] Caminho fantasma no meu gate de território**
- **Found during:** Tarefa 1, `test -e` do próprio gate
- **Issue:** `app/Services/Publicador/MlPublicacaoService.php` não existe; o real é
  `app/Services/Mlb/Publicacao/MlPublicacaoService.php`
- **Fix:** caminho corrigido + 3 caminhos proibidos acrescentados
- **Commit:** `ef6518bf`

**5. [Rule 1 - Bug] `font-extrabold` reprovado pelo GATE-03**
- **Fix:** `font-[800]`, como as outras 6 peças de `aprovacao/`
- **Commit:** `3db1474d`

**6. [Rule 1 - Bug] `whitespace-nowrap` posto por simetria em `:188`**
- **Fix:** removido (o protótipo ganha); a asserção virou a negativa
- **Commit:** `2ca78a89`

### Gates alheios consertados do meu lado (pendências que a minha wave fechou)

As **8** inversões de §pendências. **Nenhuma régua foi afrouxada:** cada negativa virou uma
positiva estritamente mais forte, e a única contagem que mudou (`contagens-187-04.txt`,
170→171) foi **medida** e tem o motivo escrito no manifesto.

---

## Self-Check: PASSED

Arquivos criados — todos conferidos no disco:

```
FOUND: app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ModalDeLote.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/RodapeDaFila.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/VazioDaFila.jsx
FOUND: tests/Feature/Publicador/Aprovacao/LoteDaFilaTest.php
FOUND: tests/js/publicador-fila-lote-187.test.js
FOUND: tests/gates/contagens-187-09.txt
```

Commits — todos no HEAD:

```
FOUND: ef6518bf  feat(187-09): o lote da fila — o veredicto de TODAS as selecionadas e a PRIMEIRA escrita
FOUND: 3db1474d  feat(187-09): a barra, a casca, o modal, o toast, o rodape e os DOIS vazios honestos
FOUND: 213a41c1  fix(187-09): as SEIS pendencias das waves 4/6/7/8 invertidas — a wave 9 chegou
FOUND: 752feea4  test(187-09): a mutacao sobrevivente achou um PROXY DE FONTE — `?sel=` ganhou teste de comportamento
FOUND: 2ca78a89  fix(187-09): o `whitespace-nowrap` que eu pus por SIMETRIA sai — `:188` nao o tem
```

Gates: **7/7 passaram.** Suítes: **PHP 2483 / 21.818 / 0** e **JS 5483 / 5482 / 1 fail
(pré-existente) / 0 skipped**. `npm run build` exit 0; manifest lido **pelo JSON**, com isca.
Árvore de trabalho limpa.

---

## ⚠️⚠️ §checkpoint — A CONFERÊNCIA VISUAL É DO USUÁRIO, E ESTE PLANO PARA AQUI

`autonomous: false`. **Não presumi aprovação e não segui adiante.** Ver a mensagem de
devolução ao orquestrador para o roteiro completo da conta 459.
