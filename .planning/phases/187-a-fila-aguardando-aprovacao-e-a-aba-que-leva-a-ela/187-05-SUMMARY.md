---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 05
subsystem: publicador-aprovacao
tags: [fila-de-aprovacao, proximo-a-revisar, fidelidade, render-adverso, mutacao]
requires:
  - "FilaDeAprovacaoService (187-02) — paraAConta() e ordenar()"
  - "BloqueiosDaOfertaService::para() (186) — aprovavel e a contagem de bloqueios"
  - "PubAprovacaoOferta::LIVE / ORDEM / ANDAMENTOS (186)"
  - "Pages/Mlb/Publicador/Aprovacao.jsx (187-04) — o slot `proximo`"
provides:
  - "ProximoARevisarService — a escolha literal de TRÊS degraus, a frase e o rótulo de espera"
  - "ProximoARevisarService::rotuloDaEspera() — a fórmula de `:1054`, pública para o 187-08"
  - "aprovacao/ProximoARevisar.jsx — a faixa amarelada de 46px"
  - "a prop `proximo_a_revisar` no payload da fila"
affects:
  - "app/Http/Controllers/MlbPublicadorAprovacaoController.php (a action da fila ganha 1 prop)"
  - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx (o slot `proximo` passou a ser preenchido)"
  - "tests/js/publicador-vocabulario-novo.test.js (ARQUIVOS_NOVOS +1)"
tech-stack:
  added: []
  patterns:
    - "slot de NÓ (187-04) + prop de DADO própria: dois nomes, um significado cada"
    - "gate ESTRUTURAL de caminho vivo (contagem de `return` + posição do handler)"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php
    - resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx
    - tests/Unit/Publicador/Aprovacao/ProximoARevisarServiceTest.php
    - tests/js/publicador-fila-proximo-187.test.js
    - tests/gates/contagens-187-05.txt
  modified:
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "Os TRÊS degraus implementados; a ORDEM dos três é delegada a ordenarFila(), nunca reescrita"
  - "O rótulo da espera é ' d' (protótipo), não ' dias' (prosa do plano)"
  - "O 'Revisar agora' navega para a própria fila com ?oferta=<id> — rota que existe e responde 200"
  - "O servidor NÃO manda miniatura: o acervo é território proibido, e o lugar vazio É o protótipo"
metrics:
  duration: "~5 h"
  completed: 2026-10-16
---

# Fase 187 Plano 05: "PRÓXIMO A REVISAR" Summary

A faixa amarelada que decide qual oferta o operador abre primeiro, com a regra literal de **três
degraus** do protótipo — e os dois fallbacks que o README esqueceu, sem os quais ela desapareceria
a partir do segundo dia de uso.

---

## 1. As baselines RE-MEDIDAS antes de editar

> ⚠️ Medidas **nesta máquina, nesta sessão, no commit de partida `1dceb28f`**, antes de qualquer
> edição. **Registradas, não ajustadas.**

```
COMMIT DE PARTIDA: 1dceb28f
JS  (antes): 5134 / 5128 pass / 1 fail / 5 skipped
PHP (antes): 2380 / 20654 / 0
BUILD (antes de editar, 1ª rodada): exit 0 em 59 s
MANIFEST (depois): 494 entradas / 202 paginas / isca ausente / CSS assets/app-B1UJaYUf.css
```

### A diferença contra a referência do briefing — registrada

| régua | briefing (15/10) | MEDIDO (16/10, `1dceb28f`) | leitura |
|---|---|---|---|
| JS | 5130 / 5124 pass / 1 fail / 5 skip | **5134** / 5128 / 1 / 5 | **+4 testes**: a régua subiu sozinha (sessão paralela). Acima é esperado. |
| PHP | 2380 / 20654 / 0 | **2380 / 20654 / 0** | bate exatamente |

⚠️ **A falha herdada do PHP DESAPARECEU, e isso é progresso, não mistério.** O
`187-BASELINES.md` §2.2 registrou `2346 / 20243 / 1` (o gate de território por range da Fase 186
acusando a sessão paralela). No commit de partida desta wave a suíte PHP sai com **0 falhas** —
o conserto foi o commit `a3169a03`, já citado no próprio `FilaDeAprovacaoRotaTest`.

⚠️ **A única falha do JS continua sendo a pré-existente da Fase 87**, localizada por SEMÂNTICA:
`not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)` em
`tests/js/estrutura-grade-glide.test.js`. **Não corrigida, de propósito**, e nenhum
`REF_PHP_FALHAS` foi criado.

### Depois

```
JS  (depois): 5218 / 5212 pass / 1 fail / 5 skipped     (+84)
PHP (depois): 2396 / 20804 / 0                          (+16 testes, +150 asserções)
BUILD (final): exit 0 em 26 s
```

⚠️ **O +84 do JS está contabilizado, e sobra 1 que eu NÃO explico:** o arquivo novo mede **76**
testes com o CSS fresco (67 + os 9 da camada de CSS construído) e
`publicador-vocabulario-novo.test.js` foi de **436 para 443** (+7, medido trocando o arquivo pelo
de `HEAD` e de volta, com `sha256` conferido). 76 + 7 = **83**. A diferença de 1 não vem da sessão
paralela — o único commit dela no range é `d188306e`, que toca **só**
`.planning/coordenacao/261016-revshare-postgres-redis-fechados.md`. **Registro a sobra em vez de
arredondar o número**: o que importa está medido — a suíte CRESCEU, as falhas continuam **1** (a
mesma), e os `skipped` continuam **5** (os cinco tokens do 187-01, corretamente em skip).

---

## 2. ⚠️⚠️ A REGRA DE TRÊS DEGRAUS — provada um a um, por fixture que FORÇA a queda

**O literal, medido com `grep -n` em `design_handoff_publicador/Aprovacao.dc.html:1220`:**

```js
nextO = live.filter(o => o.status === 'awaiting' && !o.touched).sort((a,b) => b.waitMin - a.waitMin)[0]
     || live.filter(o => !o.touched)[0]
     || live[0];
```

### A divergência do README, com `arquivo:linha`

| fonte | o que diz |
|---|---|
| `design_handoff_publicador/README-v2.md:170` | **só o 1º degrau** — *"a oferta `awaiting` não tocada há mais tempo na fila"* |
| `design_handoff_publicador/Aprovacao.dc.html:1220` | **os três**, na classe `Component`, que é a especificação de comportamento |

➡️ **O protótipo ganhou.** Sem os dois fallbacks, a faixa **desapareceria exatamente quando toda
a fila já foi tocada** — o estado normal a partir do segundo dia de uso, quando há MAIS trabalho
em aberto, não menos.

### Cada degrau, isolado por fixture

| degrau | teste | o que a fixture FORÇA |
|---|---|---|
| **1** | `test_degrau_1_vence_a_aguardando_nao_tocada_de_maior_espera_187_05` | 3 `aguardando_aprovacao` não tocadas com esperas diferentes; vence a mais antiga (13/10 contra 14/10 e 15/10) |
| **2** | `test_degrau_2_vence_a_primeira_ao_vivo_nao_tocada_187_05` | a única `aguardando_aprovacao` está **tocada** → o degrau 1 fica VAZIO; vence a `em_revisao_ecf` não tocada, **mesmo esperando menos** que a `ajustes_solicitados` tocada |
| **3** | `test_degrau_3_vence_a_primeira_ao_vivo_quando_todas_foram_tocadas_187_05` | **todas** tocadas → degraus 1 e 2 vazios; vence a PRIMEIRA de `ordenarFila()` (a `aguardando_aprovacao`, mesmo esperando **um dia menos** que a de ajustes, porque `ORDEM` a põe no degrau 1 e a de ajustes no 2) |

E as iscas que provam o SENTIDO, não só o silêncio:

- `test_aguardando_tocada_nao_vence_o_degrau_1_e_destocada_passa_a_vencer_187_05` — **destocar** a
  de maior espera muda a escolha. Sem isso, "a tocada perdeu" não distinguiria o filtro de uma
  ordem favorável.
- `test_sem_oferta_ao_vivo_devolve_nulo_e_os_cinco_estados_de_fora_nunca_vencem_187_05` — um caso
  por estado FORA de `LIVE` (os 5, com a contagem lida de `ANDAMENTOS` menos `LIVE`), asserção
  **ÚNICA** de contagem fixa coletando infratores, e **isca invertida**: com um estado de `LIVE` a
  mesma montagem ACHA a próxima.

### ⚠️ A ORDEM dos três degraus é DELEGADA — e isto é decisão medida, não descuido

O protótipo reordena por `waitMin` no 1º degrau e usa a **ordem de declaração do array de mock**
nos degraus 2 e 3 (`live = all.filter(o => o.inf.live)`, `:1218`, sobre `this.OFF`). **Aqui a
ordem dos três é a de `AndamentoDaOfertaService::ordenarFila()`**, via
`FilaDeAprovacaoService::ordenar()`:

1. `ordenarFila()` já ordena por degrau de `ORDEM` e, **dentro do degrau, maior espera primeiro**
   (nulo no fim). Como os três estados de `LIVE` caem em `ORDEM` 1, 1 e 2, filtrar as
   `aguardando_aprovacao` não tocadas e tomar a **primeira** devolve, por construção, a de
   **maior espera** — que é o 1º degrau literal. (Provado pelo teste do degrau 1.)
2. "A ordem da fonte" no banco é **indefinida**: copiá-la seria copiar o indefinido.
3. Reordenar aqui criaria **duas ordens da mesma fila**, e a faixa apontaria para uma oferta que a
   tabela mostra noutro lugar — sem nenhum teste de render acusar.

Há gate lendo o serviço **sem comentário** e proibindo `->sort(`, `->sortBy(`, `->sortByDesc(`,
`orderBy(`, os literais dos 3 estados pt-BR **e** as 3 chaves inglesas do protótipo, com controle
positivo (a sonda ACHA os literais plantados) e asserção POSITIVA de delegação (`ordenar(` e
`->para(` presentes).

---

## 3. O estado: SINGULAR, plural e o ZERO

`Aprovacao.dc.html:1222`:
`nextO.inf.approvable ? 'Pronto para aprovar' : nextO.inf.B.length + (nextO.inf.B.length > 1 ? ' pendências' : ' pendência')`

| caso | esperado | medido |
|---|---|---|
| `aprovavel = true`, 0 bloqueios | `Pronto para aprovar` | ✅ |
| 1 bloqueio | `1 pendência` (**SINGULAR**) | ✅ |
| 2 bloqueios | `2 pendências` | ✅ |
| 7 bloqueios | `7 pendências` | ✅ |
| **o ZERO** | `Pronto para aprovar`, e **nunca** `0 pendência` | ✅ |

⚠️ **A divergência registrada:** o critério 6 da fase escreveu só *"N pendências"*. O protótipo
tem as **duas** formas e ganhou.

⚠️ **E o zero tem trava explícita** (`$aprovavel || $pendencias === 0`), com a razão escrita: para
oferta ao vivo `aprovavel = ao_vivo && bloqueios === []`, então zero ⟺ aprovável — e a
**equivalência é ASSERTADA**, não presumida, em
`test_a_integracao_real_com_bloqueios_entrega_aprovavel_e_bloqueios_187_05`. Se aquele contrato
divergir no futuro, a tela diz "Pronto para aprovar", nunca uma contagem que mente.

⚠️ **A isca do singular:** `assertStringContainsString('1 pendência', '1 pendências')` é
**verdadeiro** — é a prova de que um `str_contains` ingênuo não distinguiria as duas formas, e de
que o caso do singular usa igualdade estrita.

---

## 4. A tabela FID-01 da faixa — MEDIDA POR SCRIPT

> ⚠️ **Medida por script, não por leitura.** O script está em
> `scratchpad/tabela-fid.cjs` e lê o **diretório `design_handoff_publicador/`** —
> `design_handoff_publicador/Aprovacao.dc.html`, 1432 linhas, sha256 `68624883ce35547e`.
> ⚠️ **`new_layout_publicador/` é o protótipo ERRADO para esta fase.**

| elemento | linha MEDIDA | protótipo (saída do script) | classe prescrita |
|---|---|---|---|
| `<sc-if>` | `:53` | `value="{{ Q.hasNext }}"` — a faixa INTEIRA sai | `proximo === null → return null` |
| faixa | `:54` | `display:flex;align-items:center;gap:14px;padding:10px 12px;border:1px solid rgba(250,204,21,0.35);border-radius:12px;background:#14120A` | `flex items-center gap-[14px] rounded-pub-panel border border-pub-accent/[0.35] bg-pub-row-new px-[12px] py-[10px]` |
| miniatura | `:55` | `position:relative;width:46px;height:46px;border-radius:9px;overflow:hidden;flex:none;background:#1A1A1A;color:#52525B` | `relative h-[46px] w-[46px] flex-none overflow-hidden rounded-pub-control bg-pub-hover-3 text-pub-txt-off` |
| coluna | `:56` | `display:flex;flex-direction:column;gap:3px;min-width:0` | `flex min-w-0 flex-col gap-[3px]` |
| eyebrow | `:57` | `font-size:10.5px;font-weight:700;letter-spacing:0.08em;color:#FACC15` | `text-[10.5px] font-bold tracking-[0.08em] text-pub-accent` |
| nome | `:58` | `font-size:14px;font-weight:600` | `text-[14px] font-semibold` |
| sub | `:59` | `font-size:12.5px;color:#A1A1AA` | `text-[12.5px] text-pub-txt-2` |
| estado | `:61` | `margin-left:auto;display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:500;color:{{binding}};white-space:nowrap` | `ml-auto flex items-center gap-[7px] whitespace-nowrap text-[12.5px] font-medium` + `text-pub-ok-txt`/`text-pub-warn-txt` |
| dot | `:61` | `width:7px;height:7px;border-radius:50%;background:{{binding}}` | `h-[7px] w-[7px] rounded-full` + `bg-pub-ok-txt`/`bg-pub-warn-txt` |
| botão | `:62` | `white-space:nowrap;height:36px;padding:0 16px;border:none;border-radius:9px;background:#FACC15;color:#0A0A0A;font-size:13px;font-weight:600;cursor:pointer` + hover `#EAB308` | `h-[36px] cursor-pointer whitespace-nowrap rounded-pub-control bg-pub-accent px-[16px] text-[13px] font-semibold text-pub-page hover:bg-pub-accent-hover` |

### ⚠️ `cursor` — COLUNA PRÓPRIA (medida por script no recorte `:53`-`:63`)

| medida | valor |
|---|---|
| `cursor:pointer` | **1** ocorrência |
| `cursor` (todas) | **1** |
| `onClick=` | **1** |
| `<button` | **1** |

| linha | elemento | tem `cursor:pointer`? |
|---|---|---|
| `:54` | a faixa (`<div>`) | **não** — e não é clicável |
| `:55` | a miniatura (`<span>`) | **não** |
| `:61` | o estado (`<span>`) | **não** |
| ⚠️ **`:62`** | **o `<button>` "Revisar agora"** | **SIM — o único do recorte** |

➡️ A regra do E1 é uniforme (nenhum elemento com `onClick` fica sem cursor), e na implementação
há **1 botão e exatamente 1 `cursor-pointer`**, com asserção PRÓPRIA além da igualdade de
conjunto. **Mutação M2 (tirar o `cursor-pointer`) REPROVOU.**

### ⚠️⚠️ As divergências da tabela do plano — DEZ, todas por REFERÊNCIA ou OMISSÃO

| # | divergência | plano | MEDIDO |
|---|---|---|---|
| 1-6 | os seis primeiros ponteiros | `:53`-`:58` | **`:54`-`:59`** — deslocados em **+1** |
| **7** | o ponteiro do **estado + dot** | `:59` | **`:61`** — deslocado em **+2** (`:60` é o `</div>` que fecha a coluna) |
| **8** | o ponteiro do **botão** | `:60` | **`:62`** — deslocado em **+2** |
| **9** | ⚠️ **a classe do botão OMITE `whitespace-nowrap`** | `h-[36px] cursor-pointer rounded-pub-control …` | o protótipo declara `white-space:nowrap` como a **PRIMEIRA** propriedade de `:62`. "Revisar agora" quebraria em duas linhas dentro de 36px fixos. **Acrescentado**, e a mutação M3 (tirá-lo) REPROVOU |
| **10** | os ponteiros de `nextO`/`descr`/`next` | `:1222`/`:1223`/`:1224` | **`:1220`/`:1221`/`:1222`** — deslocados em **−2** |

➡️ **8 de 9 ponteiros de elemento estavam errados, e a classe do botão estava incompleta.** É
literalmente o modo de falhar que a tabela FID-01 existe para evitar: *ela não erra VALOR, erra
por OMISSÃO e por REFERÊNCIA.*

### Omissões DECLARADAS (o que eu não reproduzi, e por quê)

- **O `<span style="position:absolute;inset:0">` dentro da miniatura (`:55`)** — ele não pinta
  nada (sem fundo, sem conteúdo, sem borda). Um elemento que não pinta é decorativo, e **nada
  nasce decorativo** (D4). **Não reproduzido, declarado aqui.**
- **`border:none` do botão (`:62`)** — o preflight do Tailwind já zera `border-width` em todos os
  elementos; a classe seria ruído sem efeito computado.
- **`h-full w-full object-cover` na `<img>`** — **TRADUÇÃO declarada, não medida**: o protótipo
  usa `<image-slot>`, elemento da ferramenta de desenho sem CSS próprio. O recorte é o que
  `MiniaturaDoProduto.jsx:50` já faz neste módulo.

### D1 (z-index) e D2 (geometria do `<main>`)

**Não incidem nesta wave:** a faixa não declara `z-index` nenhum (a varredura por script saiu
vazia para `z-index` no recorte) e o `<main>` é do 187-04, intocado.

---

## 5. ⚠️ Para onde o botão "Revisar agora" aponta HOJE — e por quê

**Destino:** a **própria fila, com a oferta na URL** —
`route('mlb.anuncios.meus.aprovacao', { company })` + `?etapa=<atual>&oferta=<id>`.

| pergunta | resposta medida |
|---|---|
| A rota existe? | **Sim** — `routes/mlb_anuncios.php:501`, `name('meus.aprovacao')`, e `FilaDeAprovacaoRotaTest` prova **200** nela |
| É `href="#"`? | **Não.** Gate de ausência de `href="#"`, `onClick={() => {}}`, `disabled`, `aria-disabled` e `pointer-events-none`, com controle da sonda |
| É decorativo? | **Não**, e isso é provado ESTRUTURALMENTE (ver abaixo) |
| Sem empresa na prop? | O handler é `null` e **o botão não é desenhado** — nunca uma rota inventada |
| O filtro atual se perde? | **Não**: a `etapa` viaja junto, e o servidor a saneia contra a lista fechada das 9 chaves |

⚠️ **O que o destino AINDA NÃO FAZ, dito sem maquiagem:** o painel de revisão é a **Fase 188** e
a revisão detalhada a **190/191**; **nenhuma das duas existe**. O realce da linha correspondente
(`bg-pub-hover-3`) é do plano **187-08**, e a tabela da fila **ainda não está nesta tela**. ➡️
**Hoje o clique recarrega a mesma fila com `?oferta=<id>` na URL, e o efeito visível chega no
187-08.** O destino é REAL (rota que responde 200, com recorte de conta aplicado e o filtro
preservado), não um placeholder — mas quem clicar hoje não vê diferença na tela. **Quem troca o
destino quando o painel existir é a PÁGINA** (`irParaOferta`), não o componente: a faixa não sabe
de rota nenhuma.

⚠️ **O plano DEFINIU este destino** (`<action>` da tarefa 2: *"nesta fase o 'Revisar agora' navega
para a fila com a oferta na URL (`?oferta=<id>`, COL-03)"*), então não houve pergunta a devolver.
A alternativa — não desenhar o botão — reprovaria o critério 6, que exige a faixa com o botão
amarelo.

### ⚠️⚠️ O gate que nasceu da mutação: "destino REAL" ≠ "literal presente"

As asserções de fonte provavam que `oferta: ofertaId` **existe** no arquivo, não que ela é
**alcançada**. Medido em 16/10: inserir `return () => {};` antes do handler deixa o `router.get`
inteiro em **código morto** — o botão é desenhado, o clique não faz nada, e **todas** as
asserções de literal continuam verdes. Botão decorativo passando por gate de literal.

➡️ O gate novo é **estrutural** sobre o corpo de `irParaOferta`: exatamente **2** `return` (o
`null` sem empresa e o handler) **e** o handler sendo a **última coisa** do corpo. ⚠️ **Com DUAS
iscas**, porque o código morto tem duas formas e **cada uma é pega por uma asserção diferente**:

| forma | o que faz | quem pega |
|---|---|---|
| **A** | troca o handler por um vazio e rebaixa o original a `const` | a **regex estrutural** (a contagem continua 2) |
| **B** | **insere** um `return () => {};` antes, deixando o handler inalcançável | a **contagem** (a regex continua casando) |

Cada asserção sozinha deixaria passar uma das duas. **Mutações M14 (forma A) e M15 (forma B)
REPROVARAM.**

---

## 6. O que NÃO foi desenhado, e por quê

- **A miniatura (a foto).** O servidor **não manda** `miniatura`: a capa vem do acervo, e
  `app/Services/Publicador/ImagemAssetService.php` / `Acervo*` são **território PROIBIDO** (a v28
  acabou de virar o acervo para o R2 **em produção**). ➡️ A faixa desenha **o lugar vazio que o
  próprio protótipo desenha** (`:55`, `background:#1A1A1A;color:#52525B` com
  `<image-slot placeholder=" ">` dentro) — não é um placeholder meu. O campo é **ponto de extensão
  declarado, com os DOIS ramos testados**: `https://` desenha a foto no mesmo quadrado de 46px;
  **qualquer outra coisa** (14 endereços, incluindo `javascript:` e `data:`) desenha o lugar vazio
  e **zero `src=`**. Nunca `<img src="undefined">`.
- **Nenhum estado vazio.** Os **dois** vazios honestos são **decisão 16 do usuário** e são do
  plano **187-09**. Há gate de ausência dos 6 literais (`Nenhuma oferta neste filtro`,
  `ainda não há ofertas aguardando aprovação`, `Limpar filtros`…) com controle positivo.
- **Nenhum "confirmar publicação" / "desfazer" / "segundo passo" / "cancelar aprovação"** — e
  nenhum estado do Mercado Livre. Medido com `grep -nE` **com bordas de palavra** nos 4 arquivos:
  **zero**. ⚠️ A primeira medição, **sem** bordas, acusou 4 e 2 e 2 e 1 — porque `ativo` casa
  dentro de **`decorativo`/`decorativa`/`negativa`**. É a armadilha nº 4 do learnings (*substring
  casa com prefixo*) acontecendo **dentro da própria verificação**, e foi pega pela segunda
  medição.
- **Nenhum token novo.** As 9 classes de cor/raio usadas são `pub-*` que já existem
  (`row-new`, `accent`, `accent-hover`, `page`, `hover-3`, `txt-off`, `txt-2`, `ok-txt`,
  `warn-txt`, `rounded-pub-panel`, `rounded-pub-control`). ⚠️ **Os 5 tokens em skip do 187-01
  continuam em skip, CORRETAMENTE** — nenhum deles foi usado "para acordar o skip": eles foram
  medidos em peças das waves 6-9 (barra de lote, toast, popover, anel da tabela), e usar um aqui
  seria nome mentindo sobre o uso.
- **Nenhuma escrita.** A faixa só LÊ. A tela **APROVA (e aprovar enfileira), ela NÃO publica** —
  a escrita no Mercado Livre é da **Fase 193**.

---

## 7. ⚠️ A divergência do rótulo da espera: `' d'`, não `' dias'`

| fonte | o que diz | `arquivo:linha` |
|---|---|---|
| `<action>` do plano 187-05 | *"rótulo humano PRONTO … (`'2 h'`, `'3 dias'`)"* | `187-05-PLAN.md`, tarefa 1 |
| protótipo | `m < 60 ? m + ' min' : m < 1440 ? Math.round(m/60) + ' h' : Math.round(m/1440) + ' d'` | **`Aprovacao.dc.html:1054`** |

➡️ **O protótipo ganhou nos RÓTULOS**, e a razão prática é mais forte que a regra: o **mesmo**
`wait()` é consumido pela coluna de espera da linha da tabela (`:1247`), que é do plano
**187-08**. Dois rótulos para a mesma medida na mesma tela seriam **duas verdades** — por isso
`rotuloDaEspera()` é **pública**, para o 187-08 **chamar** em vez de copiar.

⚠️ **Fica como pergunta aberta ao usuário**, não como decisão minha fechada: *"aguardando há 3 d"*
é o desenho, mas a régua *"jargão/abreviação não explicada não vai para a tela"* é dele. **Se ele
preferir "3 dias", a troca é de UMA linha** (`ProximoARevisarService::rotuloDaEspera`) e o teste
nomeia os 8 pontos da fórmula.

Os 8 pontos medidos: `0 → "0 min"` · `1 → "1 min"` · `59 → "59 min"` · `60 → "1 h"` ·
`120 → "2 h"` · `1439 → "24 h"` · `1440 → "1 d"` · `4320 → "3 d"`. E a **espera DESCONHECIDA**
(`entrou_na_fila_em` nulo) **sai da frase** — não vira zero nem infinito.

⚠️ **A conta é por TIMESTAMP**, não por `diffInMinutes()`: entre o Carbon 2 e o 3 aquele método
mudou de inteiro para float e o sinal passou a depender do 2º argumento. É a mesma aritmética que
`ordenarFila()` usa para desempatar a espera.

---

## 8. A frase descritiva — ordem literal e CODEPOINTS

`Aprovacao.dc.html:1221`-`:1222`:

```
<Modalidade> · <Unidade|Combo ×N> · aguardando há <espera> · responsável <nome>
<produto.nome> — <alvo.titulo>
```

| medida | codepoint | prova |
|---|---|---|
| separador da frase | **U+00B7** (` · `, **com** espaços) | medido por `codePointAt`, e controle no protótipo |
| travessão do nome | **U+2014** (` — `), nunca hífen | idem; mutação M6 (PHP) REPROVOU |
| sinal do combo | **U+00D7** (`Combo ×2`), nunca a letra `x` | idem; mutação M9 (PHP) REPROVOU |

Resultados medidos: `Clássico · Unidade · aguardando há 2 h · responsável Ana Ribeiro` ·
`Premium · Combo ×2 · aguardando há 2 h · responsável Ana Ribeiro` ·
`Clássico · Unidade · aguardando há 2 h` (sem responsável — **a frase não inventa**) ·
`Clássico · Unidade` (sem espera) · `Unidade · aguardando há 2 h` (listing desconhecido).

⚠️ **O mapa de modalidade é LIDO, não cunhado:** `EstruturaPublicacao::LISTING_TYPES` invertido +
`EstruturaAnuncio::TIPOS` — o par que `FamiliaDeFasesService:530`-`:531` manda usar. `free`,
`silver` e `bronze` devolvem `null` e o trecho **sai da frase**; nunca viram "Clássico porque é o
mais comum" (precedente do módulo, `CockpitDaContaService:458`). Com controle positivo: os dois
tipos conhecidos SAEM nomeados.

⚠️ **DIVERGÊNCIA DECLARADA no formato:** `PubProduto::ehKit()` exige **também**
`produto_base_id` não-nulo, e eu **não** o consulto. O que a frase descreve é *quantas unidades
vão nesta oferta*, que é `quantidade_kit` e só — um kit cadastrado sem base ainda vende 2 unidades
por venda, e dizer "Unidade" ali seria **afirmação falsa sobre o que o comprador recebe**. É
também o que o protótipo mede (`set.units > 1`).

⚠️ **E os literais da frase moram SÓ no serviço:** há gate provando que nenhum dos 7
(`aguardando há`, `Pronto para aprovar`, `pendência`, `Combo ×`, `Unidade`, `Clássico`,
`Premium`) aparece no `.jsx`, com **controle positivo no serviço**. Zero `new Date()`,
`toLocaleDateString`, `Intl.` ou `date-fns` no componente.

---

## 9. O custo: `para()` chamado UMA vez

`test_bloqueios_para_e_chamado_uma_unica_vez_187_05` monta **8 ofertas (6 ao vivo)** e conta as
chamadas com um **espião por SUBCLASSE** de `BloqueiosDaOfertaService`:

```
chamadas = 1
ofertasVistas = [<id da escolhida>]
isca: a fila tem 8 linhas e 6 ao vivo
```

⚠️ **O espião é subclasse, não mock de string:** renomear `para()` na Fase 186 quebra o teste em
vez de deixá-lo passar calado. E ele **não substitui a integração**:
`test_a_integracao_real_com_bloqueios_entrega_aprovavel_e_bloqueios_187_05` exercita o `para()`
**de verdade** e compara `aprovavel` e `count(bloqueios)` com o payload — para as chaves não
serem invenção coerente entre espião e serviço.

**Mutação M8 (chamar `para()` por linha da fila) REPROVOU.**

### ⚠️ Zero rede — mas com a honestidade do que isso prova

`Http::fake()` + `Http::preventStrayRequests()` em todo o arquivo, e
`Http::assertNotSent(url contém mercadolibre.com)` **com isca** (uma chamada explícita FAZ a
asserção reprovar).

⚠️⚠️ **E o gate do 187-02 `test_a_fila_nao_chama_o_mercado_livre_187_02` CONTINUA VERDE — mas não
porque a action nunca possa chamar o ML.** Medido: a fixture daquele teste cria o `PubRascunho`
**sem `categoria_id`**, e `ComposicaoFinanceiraService::tarifaDoMl()` (`:462`-`:471`) devolve
"sem fonte" **sem sair para a rede** quando a categoria é vazia. ➡️ **Esta wave é a que abre a
porta para a action chamar o Mercado Livre**, por decisão do usuário de 15/10 (tarifa
pré-aquecida pelo job do 187-10; "limitar N consultas por abertura" foi **oferecido e RECUSADO**).
Está escrito no docblock do controller, para ninguém ler aquele gate como "a fila nunca chama o
ML".

---

## 10. O slot `proximo` preenchido SEM quebrar o contrato do 187-04

O 187-04 declarou 4 slots de **NÓ** e tem **dois** gates (um `.js`, um PHP) exigindo exatamente
**4** `isValidElement(` na página. Trocar o slot de nó por dado derrubaria para 3 e **reprovaria
trabalho CORRETO** daquela wave — a família *"o gate é mais largo que o invariante"*.

➡️ **Duas props, UM significado cada:**

| prop | significado |
|---|---|
| `proximo` | **slot de NÓ** (contrato do 187-04). Quem compõe um nó, manda o nó, e ele **sobrepõe** |
| `proximo_a_revisar` | **o DADO do servidor** (esta wave) |

```jsx
const noProximo = isValidElement(proximo)
    ? proximo
    : <ProximoARevisar proximo={proximo_a_revisar} onRevisar={irParaOferta(empresaId, etapaAtiva)} />;
```

⚠️ **Nunca uma prop com dois sentidos** — aceitar duas formas para a mesma coisa foi achado como
**defeito grave** na verificação da Fase 186.

Medido: `isValidElement(` = **4**; os 4 slots nomeados; a ordem vertical de FILA-01 por POSIÇÃO
(`<CabecalhoDaFila` → `<FaixaDeAndamento` → `{noProximo}` → `{noFiltros}` → `{noTabela}` →
`{noRodape}`); **1** `<ProximoARevisar`; zero `localStorage`/`sessionStorage`; zero
`router.post/put/patch/delete`, `axios`, `fetch(`, `<form`. **Todos os gates do 187-04 e do
187-02 continuam verdes** (ver §13).

Diff da página: **+49 / −3**, e as 3 remoções são reapontes nomeados (o slot `proximo` que era
`: null`, o `ativa={chaveSegura(etapa) ?? 'todas'}` que virou `ativa={etapaAtiva}` e uma linha de
`@param`). Controller: **+18 / −0** (adição pura). `publicador-vocabulario-novo.test.js`:
**+14 / −0** (adição pura).

---

## 11. O GATE-03 e o `ARQUIVOS_NOVOS`

`resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx` foi **declarado** em
`ARQUIVOS_NOVOS`.

⚠️ **E a declaração é OBRIGATÓRIA, não cortesia — medido:** com o arquivo no disco e **sem** a
linha, `node --test` sobre `publicador-vocabulario-novo.test.js` sai com **2 falhas** (o
`assert.deepEqual(noDisco, declarados)` de `DIRETORIOS_FECHADOS`, porque `DIR_APROV` é diretório
FECHADO). Medido trocando o arquivo pelo de `HEAD` e restaurando **da cópia**, com `sha256`
conferido.

**GATE-03 VERDE: 443 testes / 443 pass / 0 fail** (era 436 → **+7**, os 6 sub-testes de
vocabulário do arquivo novo + 1 do `deepEqual` do diretório).

### Os três gates transversais que agora cobrem o arquivo novo — provados com ISCA

| gate | isca plantada no meu `.jsx` | resultado |
|---|---|---|
| **D2** — nenhum status do ML em `aprovacao/` | `Pausada` | **1 teste REPROVOU** ✅ |
| **D2** — idem | `Em análise pelo Mercado Livre` | **1 teste REPROVOU** ✅ |
| **VOC-03** — "lucro líquido" proibido | `lucro líquido` | **1 teste REPROVOU** ✅ |
| CONTROLE (arquivo limpo) | — | **0 reprovações** ✅ |

Restauração conferida por `sha256` contra `git show HEAD:`.

Tamanhos de texto usados: `10.5`, `12.5`, `13`, `14` — **os quatro já estavam** em
`TAMANHOS_PERMITIDOS`. **Nenhum valor novo foi liberado**, nenhum removido.

---

## 12. A bijeção ids ↔ métodos

`tests/gates/contagens-187-05.txt` — **4 ids**, todos MEDIDOS:

| id | modo | alvo | contagem |
|---|---|---|---|
| `proximo-servico` | php | 14 métodos | `tests=14 assertions=90` |
| `territorio` | php | `test_territorio_do_plano_187_05` | `tests=1 assertions=53` |
| `bijecao-manifesto` | php | `test_bijecao_do_manifesto_187_05` | `tests=1 assertions=7` |
| `proximo-render` | js | `publicador-fila-proximo-187.test.js` + `publicador-vocabulario-novo.test.js` | `tests=519,510` |

`test_bijecao_do_manifesto_187_05` falha nos **dois** sentidos (método sem id → **nunca roda**;
id órfão → gate sobre ZERO casos), com **asserção ÚNICA** de contagem fixa coletando infratores,
e **fecha o buraco do recorte por sufixo**: todo método do arquivo tem de ter sufixo `_187_NN`
**com manifesto no disco**. Iscas: os dois lados não-vazios, e a sonda do sufixo recusa
`test_sem_sufixo` e aceita `test_com_sufixo_187_05`.

⚠️ **Dois regimes no `proximo-render`** (`519,510`), e são MEDIDOS: a camada de CSS construído
entra em `skip` NOMEADO quando o `app-*.css` é mais antigo que os `.jsx`, e **mutar um `.jsx` o
deixa mais novo**. Pristino: 519 / 519 pass / 0 skip. Mutado: 510 / 509 pass / **1 skip**. Sem os
dois regimes a campanha abortaria na 1ª execução ou marcaria "SOBREVIVEU" por um motivo que não é
o código.

---

## 13. A campanha de MUTAÇÃO — 26 mutações, 0 sobreviventes (depois de 2 consertos)

Receita, na ordem: **commit ANTES** · `cp` para o scratchpad · ocorrência **travada em 1** antes
de aplicar · linha conferida depois · restauração **DA CÓPIA** em `trap EXIT` · `sha256` contra
`git show HEAD:`. ⚠️ **`git checkout` NUNCA.**

### PHP — o serviço (9 mutações)

| # | mutação | veredicto |
|---|---|---|
| M1 | tirar o **2º degrau** | REPROVOU (1 de 13) |
| M2 | tirar o **3º degrau** | REPROVOU |
| M3 | inverter o `!tocado` do degrau 1 | REPROVOU (2 falhas) |
| **M4** | tirar o recorte de `LIVE` de dentro de `escolher()` | ⚠️ **SOBREVIVEU** — ver abaixo |
| M5 | trocar o **SINGULAR** por plural | REPROVOU |
| M6 | travessão U+2014 → hífen | REPROVOU |
| M7 | `' d'` → `' dias'` | REPROVOU |
| M8 | chamar `para()` por LINHA da fila | REPROVOU |
| M9 | `Combo ×` → `Combo x` | REPROVOU |

### ⚠️⚠️ A sobrevivente INVESTIGADA — e era lacuna de cobertura REAL

**M4 passou com 13 de 13 verdes.** Causa medida: todos os casos chegavam a `escolher()` por
`aoVivo()`, que já recorta `LIVE` **no SQL** — a guarda de dentro **nunca era exercitada**.

⚠️ **Não é equivalência:** `escolher()` é método **público**, e `ordenarFila()` devolve a fila
**inteira** ordenada. Uma wave futura que chamasse `escolher()` com a fila inteira pegaria a
`falha` — que é o degrau **0** de `ORDEM`, o mais urgente — e a faixa ofereceria "Revisar agora"
numa oferta que não é revisável nem aprovável.

➡️ **Caso novo** `test_escolher_recusa_oferta_fora_de_live_mesmo_recebendo_a_fila_inteira_187_05`:
mede a **armadilha** (a `falha` É a primeira da fila ordenada), prova que `escolher()` devolve a
viva, e tem **isca invertida** (coleção sem nenhuma ao vivo → `null`, não a `falha`). Manifesto
re-medido **13/85 → 14/90**. **M4 re-rodada: REPROVOU** (1 de 14).

### JS — a faixa e a página (17 mutações)

| # | mutação | veredicto |
|---|---|---|
| M1 | miniatura 46px → os **44px** da linha da oferta | REPROVOU |
| M2 | tirar o `cursor-pointer` do botão | REPROVOU |
| M3 | tirar o `whitespace-nowrap` do botão (**a omissão da tabela**) | REPROVOU |
| M4 | raio da faixa 12px → 9px | REPROVOU |
| M5 | opacidade da borda 0.35 → 0.45 | REPROVOU (4 falhas) |
| M6 | eyebrow `text-[10.5px]` → `text-sm` | REPROVOU (5 falhas) |
| M7 | desenhar a faixa sem `proximo` (casca amarela vazia) | REPROVOU |
| M8 | desenhar o botão sem `onRevisar` função | REPROVOU (5 falhas) |
| M9 | desenhar o dot **sem** o texto (cor como único sinal) | REPROVOU |
| M10 | `aprovavel` por truthiness | REPROVOU |
| M11 | aceitar qualquer endereço na miniatura (`javascript:`/`data:`) | REPROVOU |
| M12 | aceitar `id` não-inteiro (destino inventado) | REPROVOU |
| M13 | tirar a oferta da URL | REPROVOU |
| **M14** | **código MORTO forma A** (handler substituído) | ⚠️ ABORTOU por âncora errada; **depois do gate novo: REPROVOU** |
| **M15** | **código MORTO forma B** (`return` inserido antes) | **REPROVOU** (gate novo) |
| M16 | não passar o dado do servidor para a faixa | REPROVOU |
| M17 | montar rota sem `empresaId` | REPROVOU |

**Restauração conferida por `sha256` contra `git show HEAD:` nos dois arquivos, nas duas
campanhas.**

---

## 14. Verificação

| item | resultado |
|---|---|
| `187-05 proximo-servico` | **PASSOU** — 14 passed (90 assertions) |
| `187-05 territorio` | **PASSOU** — 1 passed (53 assertions) |
| `187-05 bijecao-manifesto` | **PASSOU** — 1 passed (7 assertions) |
| `187-05 proximo-render` | **PASSOU** — 519 tests / 0 fail |
| `npm run build` | **exit 0** em 26 s |
| manifest (pelo JSON, com isca) | `Aprovacao.jsx` **PRESENTE** · `MeusAnuncios.jsx` **PRESENTE** · isca `NaoExiste9z7.jsx` **AUSENTE** |
| `--suite js` | 5218 / 5212 pass / **1 fail** (a pré-existente da Fase 87) / 5 skipped |
| `--suite php` | **2396 / 20804 / 0** — +16 testes (os 16 métodos desta wave), +150 asserções, **zero** falhas |
| `187-02` (4 ids) | **todos PASSARAM** — 10/46, 7/53, 8/102, 1/8 |
| `187-03 abas-da-pagina` | **PASSOU** — 111 |
| `187-03 cabecalho-sem-subtitulo` | **PASSOU** — 86 |
| ⚠️ `187-03 montagem-e-territorio` | **REPROVOU** — 554 contra 518,508 esperados. ⚠️ **JÁ ESTAVA STALE ANTES DE MIM** (ver §15) |
| `187-04 cabecalho-da-fila` | **PASSOU** — 99 |
| ⚠️ `187-04 pagina-e-contagem` | **REPROVOU** — 542 contra 535,521. ⚠️ **Causado por mim, 0 testes falhando** (ver §15) |
| `187-04 pagina-rota` | **PASSOU** — 6 passed (170 assertions) |
| `187-04 bijecao-manifesto` | **PASSOU** — 1 passed (5 assertions) |
| território | o gate por ESCOPO DE ASSUNTO passou: **nada** fora dos 9 arquivos permitidos, e os **41** caminhos proibidos com `test -e` antes de cada um |
| chamada à API real do ML | **zero**, com isca nas duas suítes |

---

## 15. ⚠️⚠️ PARA O ORQUESTRADOR: dois gates ALHEIOS com contagem velha

**Nenhum dos dois é falha de teste. Nos dois casos o veredicto é `0 fail` e o que não casa é a
CONTAGEM declarada no manifesto de OUTRA wave.** ⚠️ **Eu NÃO toquei nos dois manifestos** —
`tests/gates/contagens-187-03.txt` e `contagens-187-04.txt` estão fora do território deste plano.

### 15.1 `187-04 pagina-e-contagem` — 535,521 → **542,528** (causado por mim)

Aquele id roda `publicador-fila-cabecalho-187.test.js` **+ `publicador-vocabulario-novo.test.js`**.
Declarar `ProximoARevisar.jsx` em `ARQUIVOS_NOVOS` (obrigatório — §11) acrescentou **+7**
sub-testes ao segundo arquivo. `99 + 443 = 542`.

### 15.2 `187-03 montagem-e-territorio` — 518,508 → **554,544** (⚠️ JÁ ESTAVA STALE)

**Medido, não presumido:** troquei `publicador-vocabulario-novo.test.js` pelo arquivo de `HEAD`
(antes da minha edição) e rodei o id: **547** testes — já diferente de 518. Restaurei da cópia e
conferi o `sha256`. ➡️ A defasagem é da wave **187-04**, que também acrescentou arquivos a
`ARQUIVOS_NOVOS`. **Não é regressão desta wave.**

### A causa COMUM, e a recomendação

Os dois ids **embutem `publicador-vocabulario-novo.test.js` na contagem de outra coisa** — e
aquele arquivo tem uma contagem que **depende de quantos arquivos existem no projeto**. É
exatamente a armadilha do `learnings §4b.2`, e ela vai **reprovar de novo em TODA wave de 6 a 11
que criar componente em `aprovacao/`**.

| opção | o que fazer | custo |
|---|---|---|
| **A (recomendada)** | dar a `publicador-vocabulario-novo.test.js` um **id PRÓPRIO** (ele é o único gate cuja contagem cresce com o projeto) e tirá-lo dos ids `pagina-e-contagem` e `montagem-e-territorio` | 3 linhas em 2 manifestos; **para o sangramento de vez** |
| **B** | subir os números **MEDINDO** (542,528 e 554,544) e **remover os antigos**, como manda o §4b.2 | 4 linhas; **volta a reprovar na wave 6** |

⚠️ **Eu não escolhi por conta própria** porque os dois arquivos são de outras waves e o briefing
é explícito: *"se um gate alheio reprovar… PARE e devolva a pergunta"*. Os números acima estão
MEDIDOS e prontos para qualquer das duas opções.

---

## 16. Deviations from Plan

### Auto-fixed / decididos com o protótipo

**1. [FID-01] Os OITO ponteiros de elemento da tabela do plano estavam errados**
- Encontrado em: tarefa 2
- Medido: `:53`-`:60` no plano × **`:54`-`:59`, `:61` e `:62`** no protótipo — +1 nos seis
  primeiros e **+2** no estado e no botão (`:60` é o `</div>` que fecha a coluna).
- Mais os de `nextO`/`descr`/`next`: `:1222`/`:1223`/`:1224` no plano × **`:1220`/`:1221`/`:1222`**
  medidos.
- Commits: `2c127b47` (as medidas certas no docblock) e `a96716f3` (o conserto dos dois últimos,
  que eu mesmo errei na primeira redação).

**2. [FID-01 — OMISSÃO] A classe do botão não tinha `whitespace-nowrap`**
- O protótipo declara `white-space:nowrap` como a **primeira** propriedade de `:62`. "Revisar
  agora" quebraria em duas linhas num botão de 36px fixos. Acrescentado, com asserção própria e
  mutação (M3) provando que a sua ausência reprova.

**3. [Protótipo × plano] O rótulo da espera é `' d'`, não `' dias'`** — ver §7. Divergência
registrada; **pergunta aberta ao usuário**, com a troca custando 1 linha.

**4. [Decisão de arquitetura, declarada] A ordem dos três degraus é delegada a `ordenarFila()`**
em vez de reordenada como no mock do protótipo — ver §2. Razão: a ordem da consulta ao banco é
indefinida, e duas ordens da mesma fila divergiriam.

**5. [Declarado] `quantidade_kit` sem `ehKit()`** — ver §8.

**6. [Declarado] Omissões de fidelidade**: o `<span position:absolute;inset:0>` que não pinta
nada, o `border:none` que o preflight já dá, e a tradução `object-cover` do `<image-slot>` — §4.

**7. [Rule 2] O slot `proximo` ficou como NÓ e o dado entrou em prop própria** para não derrubar
de 4 para 3 a contagem de `isValidElement` que **dois** gates do 187-04 cobram — §10.

### Lacunas de cobertura que a MUTAÇÃO achou (as duas consertadas, não silenciadas)

**8. `escolher()` sem teste para a guarda de `LIVE`** (M4 sobreviveu) — §13. Caso novo, manifesto
re-medido 13/85 → 14/90. Commit `a44c2d9b`.

**9. O destino do botão provado por LITERAL e não por ALCANCE** (M14) — §5. Gate estrutural novo
com **duas** iscas, manifesto re-medido 518,509 → 519,510. Commit `5cc8880c`.

### Erro de medição meu, pego pela segunda medição

**10. A sonda de ausência sem borda de palavra acusou inocentes**: `ativo` casa dentro de
**`decorativo`/`decorativa`/`negativa`**, e a primeira passada pelos literais transversais
reportou 4/2/2/1 "infrações" que não existiam. A medição com `\b` deu **zero**. É a armadilha nº 4
do learnings acontecendo dentro da própria verificação — §6.

---

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano.

⚠️ **Uma mitigação ACRESCENTADA além do registro** (Rule 2): o campo `miniatura` passa por um
filtro `https://` antes de virar `src` — `javascript:` e `data:` num `src` vindo do servidor são
execução de conteúdo de terceiro na nossa página. É o mesmo filtro de
`MiniaturaDoProduto.jsx:21` (`capaSegura`), com 14 endereços recusados sob teste e controle
positivo. Não estava no threat register; está agora.

⚠️ **T-187-51 (DoS na API do ML)**: mitigado com `para()` chamado UMA vez, provado por spy. ⚠️ **E
registrado que esta wave é a que ABRE a porta para a action chamar o ML** — ver §9.

---

## Known Stubs

| stub | arquivo | razão / quem resolve |
|---|---|---|
| `miniatura` nunca preenchida pelo servidor | `ProximoARevisarService::para()` | o acervo é território proibido (a v28 virou para o R2 em produção). O componente desenha o lugar vazio **do protótipo** e aceita a prop. Quem preenche é a wave que montar as miniaturas da tabela (**187-07/08**). |
| `?oferta=<id>` não tem efeito VISÍVEL hoje | `Pages/Mlb/Publicador/Aprovacao.jsx` (`irParaOferta`) | a tabela da fila ainda não existe nesta tela; o foco da linha é do **187-08** e o painel de revisão é a **Fase 188**. O destino é REAL (200), com recorte de conta e filtro preservado. |

⚠️ **Nenhum dos dois impede o objetivo deste plano** (a faixa escolhe e mostra a oferta certa), e
os dois estão nomeados na tela por ausência, nunca por esboço visível.

---

## Self-Check: PASSED

| arquivo criado | linhas | existe? |
|---|---|---|
| `app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php` | 418 | ✅ (min_lines 60) |
| `resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx` | 219 | ✅ (min_lines 60) |
| `tests/Unit/Publicador/Aprovacao/ProximoARevisarServiceTest.php` | 1093 | ✅ |
| `tests/js/publicador-fila-proximo-187.test.js` | 1240 | ✅ |
| `tests/gates/contagens-187-05.txt` | 115 | ✅ |

| commit | assunto |
|---|---|
| `bf06c9f0` | `test(187-05)`: o vermelho dos três degraus (RED medido: *"Class ProximoARevisarService does not exist"*) |
| `4ed23c96` | `feat(187-05)`: `ProximoARevisarService` (GREEN) |
| `a44c2d9b` | `test(187-05)`: a lacuna que a mutação M4 achou |
| `2c127b47` | `feat(187-05)`: a faixa, o slot e a prop do servidor |
| `5cc8880c` | `test(187-05)`: a 2ª lacuna (destino no caminho VIVO) |
| `a96716f3` | `fix(187-05)`: os ponteiros `:61`/`:62` |

Os seis conferidos por `git log --oneline --all | grep`. ✅

⚠️ **Nenhuma linha removida de teste alheio:** `publicador-vocabulario-novo.test.js` é **+14 / −0**
e o controller é **+18 / −0**. As únicas 3 remoções do plano estão em
`Pages/Mlb/Publicador/Aprovacao.jsx`, e são reapontes nomeados (§10).

⚠️ **Nenhuma migration** — esta fase não tem, e `artisan migrate` **não foi rodado**.
⚠️ **Nenhum deploy, nenhum push, nada no VPS, nenhuma chamada à API real do Mercado Livre.**
⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não tem empresa com
`ml_token` e a rota dá **404** aqui. A conferência é do usuário, **em produção, conta 459**.
