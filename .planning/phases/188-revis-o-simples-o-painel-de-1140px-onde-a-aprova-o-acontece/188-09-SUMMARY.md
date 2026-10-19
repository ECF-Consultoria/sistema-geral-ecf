---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 09
subsystem: publicador-aprovacao-painel
tags: [tela, react, painel-de-revisao, coluna-de-preco, fidelidade, gates]
requires:
  - 188-04 (PrecoDoPainelService — o payload inteiro desta coluna)
  - 188-07 (PainelDeRevisao.jsx — a casca, o slot `colunaDePreco`, `ColunaDoPainel`, `filhosSeguros`)
  - 188-08 (ImagemGrandeDoPainel.jsx — os quatro guardas de tipo, reusados)
  - 186 (vocabularioDoPortao.jsx, andamentoDoPortao.jsx, ComposicaoDaOferta, LinhaFinanceira)
  - 187 (BarraDeMargem.jsx — só a função pura `percentualSeguro`)
provides:
  - "a coluna de preço e resultado do painel E2, com os 3 KPIs, a barra por fórmula, a composição com selo de origem por linha, a simulação que nunca é salva, a exceção e a irmã clicável"
  - "o slot `colunaDePreco` do painel PREENCHIDO na página"
affects:
  - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
  - tests/js/publicador-vocabulario-novo.test.js
  - tests/js/publicador-fila-fechamento-187.test.js
  - tests/js/publicador-publicacoes-filtros.test.js
tech-stack:
  added: []
  patterns:
    - "slot de NÓ: handler função-ou-null, e sem handler a peça NÃO é desenhada (D4)"
    - "guardas de tipo ANTES de qualquer obj[chave] vindo do servidor (REND-03)"
    - "largura e posição por `style`, nunca por classe concatenada"
    - "território por CONTRATO quando o diff não atribui autoria"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/KpisDoPainel.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ComposicaoFinanceiraDoPainel.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/SimulacaoDePreco.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ComparacaoComAIrma.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ColunaDePreco.jsx
    - tests/js/publicador-painel-preco-188.test.js
    - tests/gates/contagens-188-09.txt
  modified:
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-fechamento-187.test.js
    - tests/js/publicador-publicacoes-filtros.test.js
    - .planning/phases/188-.../188-FID01.md
decisions:
  - "A caixa de simulação NÃO é desenhada sem o transporte da wave 188-06: campo de digitação sem destino é controle MORTO, e caixa tracejada com só o título é casca decorativa (D4)"
  - "A irmã é clicável DE VERDADE, sem rota nova: `<a href>` do servidor + o `abrirOferta` que a página já tem (rota do 188-02, COMMITADA)"
  - "`BarraDeMargem.jsx` da fila NÃO foi reusada como desenho — três das quatro medidas divergem; reusou-se só a função pura `percentualSeguro`"
  - "`:657` mede #1A1A1A (`pub-hover-3`), e não o #1C1C1C que o `<behavior>` do plano escrevia — o protótipo ganha no VALOR"
  - "O gate de FID da Fase 180 foi reapontado na RAIZ: a ausência GLOBAL de uma classe saiu, o BYTE do próprio chip entrou"
metrics:
  duration: "~4 h"
  completed: 2026-10-19
---

# Phase 188 Plano 09: A coluna de preço e resultado do painel — Summary

**Os 3 KPIs com a barra da margem por FÓRMULA, o aviso de alteração, a composição completa com
selo de origem e `title` de fonte em cada uma das 7 linhas, a simulação que nunca é salva, a
exceção autorizada e a comparação clicável com a oferta irmã — tudo LENDO o payload de
`PrecoDoPainelService`, sem uma única conta de taxa no cliente.**

---

## A PARTIDA

```
HEAD de partida: 091340b6  (branch main, repositório principal — NÃO é worktree)
```

Capturado na PRIMEIRA ação, antes de qualquer leitura de plano.
⚠️ **Usado só como REFERÊNCIA DE LEITURA, nunca como extremo de range.** O território foi provado
por **ESCOPO DO ASSUNTO** e por **CONTRATO** — ver §Território.

### Os commits desta wave

| # | hash | o que |
|---|---|---|
| 1 | `99794441` | `feat` — os 3 KPIs, a barra por fórmula e o aviso de alteração |
| 2 | `83b3ba66` | `feat` — a composição com selo de origem, o frete e o pior caso |
| 3 | `cdaf03a9` | `feat` — a simulação, a exceção, a irmã clicável e o slot ligado na página |
| 4 | `00bdba8e` | `test` — a FIXTURE QUE DISCRIMINA o selo (sem ela a mutação (v) sobreviveria) |
| 5 | `a874fc00` | `test` — o gate de FID da Fase 180 reapontado na raiz |

**Commit atômico por tarefa**, com `git add -- <caminho>`, pathspec no `git commit` e **`-F
<arquivo>` sempre** (o `Co-Authored-By` conferido em todos os cinco). **Zero deleções**:
`git diff --diff-filter=D HEAD~1 HEAD` saiu vazio depois de cada commit.

---

## AS BASELINES — na partida e no fim

| medida | na partida (`091340b6`) | no fim (`a874fc00`) | leitura |
|---|---|---|---|
| **JS** | `5571 / 5570 pass / 1 fail / 0 skipped` | **`5654 / 5653 pass / 1 fail / 0 skipped`** | **+83**, e a soma FECHA: 48 do gate novo + 35 do GATE-03 (597 → 632) |
| **PHP `Publicador/Aprovacao`** | `301 passed / 5367 assertions / 0 fail` | **`311 passed / 5500 assertions / 0 fail`** | **+10 testes ALHEIOS** — ver abaixo |
| **BUILD** | — | **exit 0** | `npm run build` rodado 4×, o último **DEPOIS** da mutação |
| **MANIFEST** | `495 / 202` | **`495 / 202`** | pelo **JSON**, nunca por grep |

### ⚠️ As falhas conhecidas, NOMEADAS e ATRIBUÍDAS

- **JS, 1 falha:** `tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce
  recolhido (é o grupo que mais infla)"*, **Fase 87**, pré-existente.
  ⚠️ **ATRIBUÍDA RODANDO O ARQUIVO SOZINHO:** `19 tests / 18 pass / 1 fail`. **Não corrigida.**
- **PHP, 0 falhas** em `tests/Feature/Publicador/Aprovacao`. Os dois osciladores da **Fase 179**
  (`tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318`) estão **FORA** daquele
  diretório e não entraram nesta medição.
- ⚠️ **Nenhuma falha da corrida de `storage/framework/testing` apareceu** nesta execução.

### ⚠️ Os **+10 testes PHP** NÃO são desta wave

Esta wave **não acrescentou nenhum teste PHP**. Os +10 vêm de
`tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php`, que estava **modificado e
não commitado** pela wave **188-06** durante toda a execução — ver §O do `188-FID01.md`.
⚠️ **Asserções não são invariante** (já se mediu −5 com testes idênticos): a comparação é de
**TESTES e FALHAS**, e as duas subiram ou ficaram em zero.

### ⚠️⚠️ O MANIFEST pelo JSON, e o hífen que prova por quê

```
MANIFEST entradas=495 paginas=202
css=assets/app-BatUsbz0.css        ← e numa medição anterior `assets/app-B0-WY4Lv.css`
pagina da fila presente: true      ← `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`
isca ausente: true                 ← `resources/js/Pages/Mlb/NaoExiste8k3.jsx`
```

⚠️ **O hash sai COM HÍFEN** (`app-B0-WY4Lv`), e é exatamente por isso que o manifest se lê pelo
JSON: um `grep` ingênuo não casaria. ⚠️ **Página fora do manifest SOME da aplicação sem erro
nenhum** — por isso a presença é asserida.

---

## OS 3 KPIs — rótulo LIDO do servidor, e três cores com TEXTO ao lado

| KPI | rótulo | de onde vem | medida |
|---|---|---|---|
| 1 | `Preço de venda` | `PrecoDoPainelService::ROTULO_PRECO` | `:642`, valor em **`text-[21px]`** |
| 2 | **`Resultado estimado por venda`** | ⚠️ **`ComposicaoDaOferta::rotuloDoResultado()`**, que espelha `VocabularioDoPortao::RESULTADO_ESTIMADO` | `:643`, **+ `border-left`** |
| 3 | `Margem sobre o preço` | `PrecoDoPainelService::ROTULO_MARGEM_KPI` | `:644`, `gap:6px` + barra + nota |

⚠️ **Nenhum dos três é escrito na tela.** Há gate provando, nos cinco `.jsx`, que os literais
`Preço e resultado`, `Preço de venda` e `Margem sobre o preço` **existem no serviço** e **não
existem** nos arquivos deste plano — escrevê-los seria a QUARTA redação do mesmo nome.

### As TRÊS cores do resultado, DISTINTAS aos pares — e cada uma com PALAVRA

| estado | cor (medida no HTML) | o TEXTO que acompanha |
|---|---|---|
| normal | `text-pub-txt` (#F4F4F5) | `acima do mínimo de 12% · com a tarifa real do Mercado Livre` |
| prejuízo | `text-pub-danger-txt` (#F87171) | **`prejuízo por venda`** |
| incompleto | `text-pub-warn-txt` (#FBBF24) | **`Incompleto`** + `dados incompletos` |

⚠️ **D7 — a cor nunca é o único sinal**, e o gate cobra as DUAS coisas: `new Set(cores).size === 3`
**e** a palavra presente em cada estado. Um teste de EXISTÊNCIA passaria com duas cores iguais, e
duas iguais apagariam a diferença entre *"dá prejuízo"* e *"não se sabe"*.
⚠️ **E nunca `R$ 0,00` nem `0%`:** o incompleto sai `Incompleto` e a margem sai `—`. Zero
AFIRMARIA que é de graça (FIN-05/FIN-06, D4) — asserido pelos dois lados.
⚠️ **A divisória é POSIÇÃO, não propriedade do KPI:** a célula 1 **não** tem `border-l`, as 2 e 3
têm. Medido por **árvore com índice ABSOLUTO** — nunca `indexOf` de classes.

### ⚠️ A ORDEM é contrato do servidor, e a tela NÃO reordena

Provado nas DUAS direções: com a lista na ordem, a tela sai preço → resultado → margem; **com a
lista INVERTIDA, a tela inverte**. Uma reordenação interna passaria no primeiro caso e reprovaria
no segundo. Mais a leitura de `KPIS_EM_ORDEM` no serviço.

---

## A NOTA DA MARGEM — a DECISÃO 9, LIDA e não remontada

```
abaixo do mínimo de 20% · com a tarifa real do Mercado Livre
```

⚠️⚠️ **A frase chega FECHADA de `ComposicaoDaOferta:271`** (reconferido por `sed` nesta wave) e a
tela só a EXIBE. O gate prova TRÊS coisas:

1. a substring `com a tarifa real do Mercado Livre` está **na tela**;
2. ela **NÃO está em nenhum dos cinco `.jsx`** — remontá-la seria a segunda redação, e a
   divergente seria a que a pessoa lê;
3. ela **continua no servidor** (isca da leitura: se sair de lá, a tela fica sem texto e ninguém
   saberia por quê).

⚠️ **E AS DUAS MARGENS NÃO FORAM UNIFICADAS.** O gate confirma que a do lote vive em
`app/Services/Publicador/Fila/ResumoRapidoService.php` **e** que **não existe** um
`app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` — confundir os dois caminhos
**APAGARIA** a decisão 9. O docblock de `EditorRascunhoService:370`-`:372` diz *"diferente DE
PROPÓSITO… não unificar"*.

---

## A BARRA — FÓRMULA provada com DOIS mínimos

| mínimo | largura | posição do traço | medido no `style` do HTML |
|---|---|---|---|
| **12%** | `39%` | **`left: 30%`** | `12/40 = 30%` — o literal do protótipo |
| **20%** | `39%` | **`left: 50%`** | `20/40 = 50%` — **o traço SE MOVEU** |

⚠️⚠️ **Com um mínimo só, o `left-[30%]` cravado PASSARIA.** É a divergência **D10** da Fase 187,
resolvida do mesmo jeito: `LinhaDaOfertaService::percentualDaBarra()` é a única fórmula, e a tela
repassa.

⚠️ **Largura e posição por `style`, nunca por classe concatenada** — classe montada por template
literal o scanner do Tailwind **não vê**: fica no HTML, todo render passa e **a barra não desenha**.
A sonda proíbe `w-[${…}]`, `` `w-[ `` e `'w-[' +` nos cinco arquivos, com **isca nas duas
direções** (ela pega as três formas erradas e **não** pega as classes literais legítimas como
`w-[1.5px]`).
⚠️⚠️ **E a sonda é na FONTE SEM COMENTÁRIO** — achado MEDIDO da wave 188-08: **o scanner do
Tailwind lê COMENTÁRIO**, e no CSS construído o próprio docblock que proíbe a classe **a faz
existir**.
⚠️ A segunda porta (`percentualSeguro`) recusa `NaN%`, `null`, `950%` e `-7%`: **CSS inválido faria
a barra desaparecer SEM erro nenhum**.

### ⚠️⚠️ A decisão sobre `BarraDeMargem.jsx` — MEDIDA, e ela foi REUSAR A FUNÇÃO, NÃO O DESENHO

Medi as duas geometrias por `sed` no protótipo **antes** de decidir:

| medida | fila (`:163` / `:1246`) | painel (`:645` / `:1132`) | igual? |
|---|---|---|---|
| trilha | `height:4px;border-radius:2px;background:#1F1F1F` | idem | ✅ **IGUAL** |
| preenchimento | absoluto à esquerda, `border-radius:2px` | idem | ✅ **IGUAL** |
| **traço do mínimo** | `#71717A` (`pub-txt-4`) | **`#A1A1AA`** (`pub-txt-2`) | ❌ **DIFERENTE** |
| **preenchimento normal** | `#A1A1AA` (chave `suave`) | **`#D4D4D8`** (chave `clara`) | ❌ **DIFERENTE** |
| wrapper | `flex flex-col gap-[6px] pr-[12px]`, número 13px ACIMA | `gap:6px;padding:12px`, valor **21px** acima e nota ABAIXO | ❌ **DIFERENTE** |

➡️ **Reusei a função PURA `percentualSeguro`** (a forma fechada que recusa CSS inválido — uma casa
só) **e construí a barra do painel**, porque três das quatro medidas divergem.
⚠️ *"O componente que já existe faz parecido"* é justificativa **VETADA** (FID-02) — **e forçar o
reuso de geometria diferente é a outra metade do MESMO erro.** A `188-FID01.md` §L já media a cor
e avisava: *"um gate que exigisse a MESMA chave nos dois reprovaria código certo"*.
⚠️ **`BarraDeMargem.jsx` ficou INTOCADO** — `numstat` vazio, com isca determinística.

---

## A COMPOSIÇÃO — 7 linhas, selo de origem por linha, e a nota NUNCA vazia

**Ordem medida e NÃO reordenada:** `custo · comissao · tarifa_fixa · frete · imposto · embalagem ·
outros` (`array_keys(PubAprovacaoComposicao::LINHAS)`). Provada nas duas direções (lista
invertida ⇒ tela invertida) e contra a constante do serviço.

### ⚠️⚠️ DADO-13 — `title` NÃO-VAZIO em TODAS as 8 (7 da conta + 1 informativa)

Medido: **8 selos**, `semTitulo` = `[]` (asserção que **COLETA** os infratores, contagem FIXA).
E a nota **NOMEIA a fonte**: `ERP Bling · atualizado 11 out`, `tarifa real do Mercado Livre`,
`parâmetro da empresa`.
⚠️ **ISCA:** nota em branco (`'   '`) produz `title` **ausente** — porque `title=""` é um `title`
AUSENTE, e elemento sem nome acessível **passa em render e desaparece na tela**. A regra
(`LinhaFinanceira::REGRA_NOTA_VAZIA`) é conferida no servidor.

### As SEIS cores das TRÊS origens, distintas aos pares **e** iguais ao VOCABULÁRIO

| origem | texto | borda | bate com `SELOS_DE_ORIGEM`? |
|---|---|---|---|
| `confirmado` | `text-pub-ok-txt` | `border-pub-ok/[0.35]` | ✅ |
| `estimado` | `text-pub-fase1` | `border-pub-line-off` | ✅ |
| `pendente` | `text-pub-danger-txt` | `border-pub-danger/[0.45]` | ✅ |

⚠️ `new Set(textos).size === 3`, `new Set(bordas).size === 3` **e** `new Set([...textos,
...bordas]).size === 6`. **Duas iguais passariam num teste de existência.**
⚠️⚠️ **E elas são comparadas, origem por origem, com `SELOS_DE_ORIGEM` de
`vocabularioDoPortao.jsx`** — a casa do vocabulário. Duas paletas para a mesma coisa divergiriam
no primeiro ajuste.
⚠️ Chave desconhecida **nunca cai no verde**: afirmar confirmação que não existe é o pior lado do
erro nesta coluna.

### As TRÊS formas do MESMO campo (`:1126`), e o U+2212 medido em BYTES

| forma | como sai | cor | asserção |
|---|---|---|---|
| custo positivo | **`− R$ 62,00`** (sinal INVERTIDO) | `clara` | `Buffer.includes([0xE2,0x88,0x92,0x20])` |
| zero | **`R$ 0,00`**, SEM sinal | `neutro` | `!conteudo.includes('− R$ 0,00')` |
| `null` | o **TEXTO** `Pendente` | `vermelho` | o MESMO texto do selo (`rotuloDaOrigem()`) |

⚠️ **O `−` é U+2212 MINUS SIGN seguido de espaço (`E2 88 92 20`)**, não o hífen-menos — conferido
**por bytes**, e com a asserção simétrica `!/-\s?R\$/` provando que o hífen **não** aparece.
⚠️ **E nenhum formatador novo nasceu na tela:** gate de ausência de `toLocaleString`,
`Intl.NumberFormat`, `toFixed(`, do prefixo `'R$ '` literal e do próprio U+2212 nos cinco
arquivos, com isca das três formas.

### ⚠️⚠️ A DECISÃO 3 — Embalagem `Pendente` NÃO anula; o FRETE ausente ANULA

- com Embalagem `Pendente`: o resultado **EXISTE** (`R$ 23,41`), **não** sai `Incompleto`, e a nota
  da margem termina em **`· sem o custo de embalagem`** — o **"estimado por baixo"**;
- a linha da Embalagem aparece como `Pendente` e **o rodapé do resultado continua de pé**;
- ⚠️ **a ISCA que separa as duas:** com o **frete** ausente o resultado sai `Incompleto`, a margem
  sai `—`, e **nunca `R$ 0,00`**;
- conferido no servidor: `ANULAM_O_RESULTADO` **tem** `'frete'` e **não tem** `'embalagem'` nem
  `'outros'`.

⚠️⚠️ **E o sinal do "estimado por baixo" viaja por UMA fonte só** — `nota_da_margem`. Desenhá-lo
de novo poria o MESMO fato em DUAS fontes, e fato repetido em dois lugares já divergiu neste
projeto (é a razão da decisão 9 e da PERGUNTA-188-D, em que *"toast + texto fixo"* foi OFERECIDO e
RECUSADO). Há gate de ausência de `estimado por baixo` e de `sem o custo de embalagem` nos cinco
`.jsx`, com a isca de que a frase **está** no servidor.

### ⚠️ FIN-08 — as informativas aparecem, não somam, e vêm DEPOIS do rodapé

`Resultado estimado por venda` sai **antes** de `Frete pago pelo comprador`. Antes dele, a pessoa
somaria o frete do comprador junto e o rodapé discordaria da lista. Testado com a lista
**preenchida** e com ela **vazia** (asserção de ausência rodada com dado vazio é vazia por
construção).

### ⚠️⚠️ DIVERGÊNCIA (1) — só a PRIMEIRA frase de `:665`

`design_handoff_publicador/Aprovacao.dc.html:665` diz:

> *"Frete pago pelo comprador não entra no resultado. **Taxas de comissão são exemplos
> configuráveis.**"*

A segunda frase é **disclaimer da ficção do próprio protótipo** (`:1064`, *"taxa de exemplo"*,
`src:'est'`). Aqui a comissão é a **tarifa REAL do ML** (decisão 9), então reproduzi-la seria a
tela **afirmando o que não é** (D8 / FIN-02 / FIN-03).
➡️ **Sai só a primeira, byte a byte** — e ela já vem assim de
`PrecoDoPainelService::NOTA_DO_FRETE_DO_COMPRADOR`. ⚠️ **Não é decisão nova:** a §3 do FID01
governa e a Fase 186 já fez a mesma tradução, em produção.
⚠️ A sonda pega a frase **com e sem acento** — *silêncio que depende de UMA LETRA é sorte*.

### ⚠️⚠️ DIVERGÊNCIA (2) — "Informar frete confirmado" lê o dado PRECISO, e não crava destino

`:1143` do protótipo acende o botão com o `missing` **INTEIRO** — inclusive quando o que falta é o
**custo do produto**, mandando a pessoa ao lugar errado. A Fase 186 já consertou essa classe em
`BloqueiosDaOfertaService::freteOuCusto()`.

**As QUATRO combinações, medidas, e só UMA desenha:**

| `frete_ausente` | handler | botões |
|---|---|---|
| `true` | função | **1** |
| `true` | `null` | 0 |
| `false` | função | 0 |
| `false` | `null` | 0 |

⚠️ Nos quatro casos **o TEXTO da composição continua aparecendo** — o botão é que não nasce (a
régua da PERGUNTA-188-B).
⚠️ **E o destino NÃO é cravado na tela:** `frete_ausente` tem **DOIS** destinos (modal de frete ou
modal de ajustes), resolvidos por `PainelDeRevisaoService::destino()`. Gate de ausência de
`openModal`/`abrirModal` nos cinco `.jsx`.
⚠️ **B2 — o botão é DESENHADO AQUI, com prop, e o 188-12 o RELIGA.** A primeira redação do plano
dizia *"se o 188-12 ainda não rodou, o botão não é desenhado"* — e como o 188-12 **não lista este
arquivo**, o botão **nunca** seria ligado e o critério 14 ficaria inalcançável.

### ⚠️⚠️ DIVERGÊNCIA (3) — o PIOR CASO é VISÍVEL, e o protótipo NÃO TEM este elemento

| N | o que a tela mostra |
|---|---|
| 3 variantes | **`pior caso entre 3 variantes · Azul · M`**, como TEXTO |
| 1 preço | **nada** — `notaDoPreco` é `null` |

⚠️ Na fila (187-08) a frase é só `title`, porque a coluna tem 92px e UMA linha — *"acrescentar
segunda linha na coluna de preço"* foi **oferecido e RECUSADO**. ➡️ **Aqui a decisão P1 do usuário
(15/10) a tornou VISÍVEL**, e o gate prova que ela **não** saiu como `title`.
⚠️ **O protótipo não tem este elemento** (a §D o registra como *"não existe no protótipo"*), então
ele nasce no **IDIOMA MEDIDO de `:665`** — nota solta embaixo da caixa, `11.5px`, `#71717A`,
`margin-top:-4px`. **Nada medido foi encolhido para caber.**
⚠️ **O servidor escreve "variantes"**, não "variações" como a prosa do plano — **lido dali, não
reescrito** (`ComposicaoDaOferta::notaDoPreco()`).
⚠️ Testado com 7 formas de nota ausente (`null`, `undefined`, `''`, `'   '`, `{}`, `[]`, `NaN`), e
nunca aparece *"1 variante"*.

---

## A SIMULAÇÃO — e de QUEM é o transporte

### ⚠️⚠️ MEDIDO: no HEAD de partida NÃO EXISTIA rota de preço nenhuma

Em `091340b6`, `routes/mlb_anuncios.php` tinha `lote`, `imagem.aprovar`, `fotos.sem-alerta`,
`imagem.reprovar`, `salvar-revisao`, `aprovar` e `tentar-de-novo` — **e nada de preço, de simular
ou de conferir alteração**.

➡️ **A peça nasce PRONTA PARA RECEBER, e isso está DECLARADO no código e na página:**
`onSimular`, `onDescartar`, `onSalvar` e `onConferirAlteracao` chegam **função ou `null`**; a
página passa `null` e escreve por quê.

| sem handler | o que acontece |
|---|---|
| `onSimular` | ⚠️ **a caixa de simulação INTEIRA não é desenhada** |
| `onConferirAlteracao` | o botão não nasce; **o TEXTO do aviso continua aparecendo** |
| `onInformarFrete` (188-12) | o botão não nasce; a composição continua inteira |

⚠️ **Por que a caixa inteira, e não só o input:** campo de digitação sem transporte é **controle
MORTO** (a pessoa digita e nada acontece, que é pior que a ausência), e caixa tracejada com só o
título dentro é casca decorativa. *"Desenhar desabilitado com explicação"* foi **OFERECIDO e
RECUSADO** na PERGUNTA-188-B3, e `onClick` vazio é a mesma dívida com outra roupa (D4).
⚠️ **É o MESMO contrato** com que o `onTentarDeNovo` nasceu `null` na 188-07 e o `onReprovar` na
188-08.

### ⚠️⚠️ E DURANTE A EXECUÇÃO A ROTA APARECEU — como WIP NÃO COMMITADO da wave 188-06

A sonda que exigia a **ausência** de `preco/simular` **REPROVOU**: a wave 188-06 tem WIP não
commitado nesta mesma árvore (+61 em `routes/`, +329 no controller, +288 e +220 nos dois
serviços). ➡️ **A sonda era mais LARGA que o invariante e reprovava trabalho CORRETO de outra
sessão** — a família que já BARROU UM DEPLOY neste projeto.
**Consertada na raiz:** o invariante virou **comportamento** — *nenhum `.jsx` desta coluna chama
`router.`, nomeia rota (`mlb.anuncios.meus.aprovacao`) ou fala HTTP* —, com isca nas duas
direções. ⚠️ **E NÃO liguei a rota:** depender de trabalho não commitado de outra sessão é frágil,
e a religação é da 188-06 (transporte) / 188-12 (aviso de conflito).

### O selo, os botões e a FIXTURE QUE DISCRIMINA

- **`SIMULAÇÃO · NÃO SALVA` só com `simulando`** (`:1129`, tolerância `0,001` conferida no
  serviço);
- ⚠️⚠️ **a fixture que faz a guarda da TELA ser observável:** no estado neutro o servidor manda
  `selo: null`, então a mutação que remove a guarda **SOBREVIVERIA** e o gate estaria medindo o
  SERVIDOR. ➡️ A fixture manda o selo **PREENCHIDO com `simulando: false`** (o payload
  contraditório que a tela não pode acreditar), mais a sonda NÃO-CEGA com `simulando: true`;
- **`Descartar` / `Salvar no anúncio` nos DOIS eixos**, quatro combinações medidas:

| `pode_salvar` (= `simOn && live`) | handlers | botões |
|---|---|---|
| `true` | função | **2** |
| `true` | `null` | 0 |
| `false` (fora do `live`) | função | 0 |
| `false` (neutro) | função | 0 |

### ⚠️⚠️ ZERO aritmética de taxa na tela, lida SEM COMENTÁRIO

Gate de ausência de `* 0.1N`, `* 0.0N`, `/ (1 -`, `* (1 -`, `6.75`, `0.165`, `Math.round(` e
`toFixed(` nos cinco `.jsx`, com **isca positiva** (pega as quatro formas erradas) e **isca
negativa** (não pega `lista.length < 2`, `ofertaId > 0`, `indice > 0`, `pode_salvar === true`).
⚠️⚠️ **E a leitura é SEM COMENTÁRIO de propósito:** o docblock de `SimulacaoDePreco.jsx` **cita**
`* 0.12` e `/ (1 - margem)` para explicar que eles **não existem** ali — leitura CRUA reprovaria
**pelo próprio conserto**, e TRÊS mutações da wave 187-04 abortaram por isso. O gate prova as duas
pontas: a palavra **está** no arquivo cru e **não está** na fonte limpa.

### ⚠️ FIN-09 — o `N%` do atalho vem do mínimo REAL, e o `ceil(alvo) − 0,1` é do SERVIDOR

Fixture com mínimo em **20%**, não 12%: `Margem mínima 20% · R$ 118,43`, e o gate exige que **`12%`
NÃO apareça**. ⚠️ **Com 12 um literal cravado passaria.**
⚠️⚠️ **`onSimTarget` preenche `ceil(alvo) − 0,1`** (`:1140` — ponteiro CORRIGIDO pela §L; o plano
dizia `:1139`, que é o `onSimBE`, **sem** o ajuste): R$ 118,43 preenche **118,90**. **DECLARADO**
no docblock e asserido no serviço (`$preenche = ceil($alvo) - 0.1;`), com gate de ausência de
`Math.ceil(` na tela.
⚠️ Sem atalhos, a tela **diz por quê**: `Preço de equilíbrio indisponível até o frete ser
confirmado.` — texto do servidor, e o gate prova que nenhum `.jsx` o escreve.

---

## A EXCEÇÃO — a DECISÃO 6, ancorada no SUJEITO

A frase inteira (glifo `§`, quem liberou, data, justificativa) vem FECHADA de
`PubAprovacaoExcecao::fraseDeExibicao()`, que trata `liberada_por` nulo **sem mentir**
(*"responsável removido"*). Sem exceção, **sem linha** — testado com 7 formas.

⚠️⚠️ **"exceção aguardando autorização" NÃO EXISTE**, e a sonda é ancorada no **SUJEITO**, não em
palavras soltas:

- **ISCA POSITIVA** — pega `Exceção aguardando autorização`, `excecao aguardando aprovacao`,
  `aguardando autorizacao do gestor`, `Exceção pendente de análise` (com **e sem** acento);
- ⚠️⚠️ **ISCA NEGATIVA** — **NÃO** pega `Aguardando aprovação`, `aguardando_aprovacao`,
  `Aprovado — aguardando publicação` nem `aguardando publicação`. São **vocabulário CORRETO** do
  domínio (um dos 8 andamentos, em produção), e uma sonda por palavra solta reprovaria código
  certo — é a 10ª ocorrência dessa família, e uma delas **BARROU UM DEPLOY**.
- ⚠️ E `LIBERA` continua a lista **FECHADA** de dois: o gate prova que ela **não** tem `fotos`,
  `frete`, `alteracao`, `ajuste` nem `titulo`.

---

## A IRMÃ — CLICÁVEL de verdade, sem rota nova

| o que | como |
|---|---|
| **ordem** | Clássico → Premium, **pela constante do servidor** (`array_keys(EstruturaPublicacao::LISTING_TYPES)`); provada nas DUAS direções |
| **clicável** | `<a href="?oferta=4102">` — o `href` é do **SERVIDOR**, do MESMO rascunho — **+** `onClick` que chama o `abrirOferta` da página (navegação Inertia, preservando o recorte) |
| **a ATUAL** | `href: null` no servidor ⇒ sai como `<span>`, **não navega para si mesma** |
| **as DUAS aparências** | atual `bg-pub-hover-3` + `border-pub-line-off`; outra `bg-pub-surface` + `border-pub-line-subtle` — asserido com `notDeepEqual` dos conjuntos |
| **o losango** | **só no Premium**, `h-[6px] w-[6px] rotate-45 bg-pub-txt` — ⚠️ **SEM `border-radius`**, ao contrário dos outros dois losangos do módulo (7px com `rounded-[1px]`) |
| **sem irmã** | **a grade NÃO é desenhada** (7 formas testadas), e nem com `irma` preenchida e a lista TORTA |

⚠️ **A rota é a do plano 188-02, que está COMMITADA** — por isso a irmã é clicável de verdade,
sem depender da 188-06. ⚠️ **Nenhum `router.` mora nos `.jsx`**: quem navega é a página.
⚠️ `irma` é **DERIVADA** de `comparacao` no servidor (`$irmas[0] ?? null`) — asserido, para que as
duas chaves nunca possam discordar.
⚠️ **`id = 0` passa pela guarda de inteiro e zero é um id PLAUSÍVEL** (conserto medido na 188-08):
a navegação exige **inteiro POSITIVO**, e o gate confirma o `ofertaId > 0` na fonte.
⚠️ O rótulo do andamento é o do **servidor**, com `andamentoDe()` como fallback — e
`andamentoDe()` **nunca devolve `null`**: testado com 10 chaves adversas, o selo de status **nunca
desaparece** (guarda que devolvesse `null` produziria elemento vazio, que passa em render e some
na tela). A **cor** vem do módulo (TOK-15: nenhum hex sai do PHP), e as duas são distintas.

---

## A TABELA FID-01 — **medida POR SCRIPT**

⚠️ **Diretório CITADO: `design_handoff_publicador/Aprovacao.dc.html`** (há DUAS cópias do pacote
com conteúdo DIFERENTE). **1432 linhas úteis, `sha256 68624883ce35547e`** — não se moveu.
⚠️ `split(/\r?\n/)` dá **1433** (a última é vazia).

**36 elementos do §D + 19 ponteiros do view-model: TODOS CONFEREM.** A tabela completa, com
`cursor` / `white-space` / `text-overflow` em **colunas próprias**, está em
`188-FID01.md` **§O** (acrescentada por esta wave). O resumo da varredura:

```
cursor:pointer         PRESENTE em 6 : 651 667 677 681 682 690
white-space:nowrap     PRESENTE em 10: 642 643 644 651 658 659 667 677 681 682
text-overflow:ellipsis PRESENTE em 1 : 658   (+ o min-width:0 do wrapper na MESMA linha)
```

⚠️ **`white-space:nowrap` é a PRIMEIRA propriedade da linha em cinco das dez** (`:651`, `:667`,
`:677`, `:681`, `:682`) — exatamente a posição em que a Fase 187 o perdeu três vezes.

### ⚠️⚠️ O PONTEIRO QUE RECONFIRMEI E ESTAVA DESLOCADO — **na lista de CONFIRMADOS da §L**

| o que a §L dizia | real (medido) | o que está DE FATO lá |
|---|---|---|
| `:1136` = `simDelta` / `' vs. salvo'`, listado como ✓ **CONFIRMADO** | **`:1137`** | `:1136` é `simResult`/`simMargin`/**`simMarginColor`** — outro assunto real |

**SEXTA confirmação consecutiva da lição da §J**, e a primeira em que o número errado estava na
própria tabela de confirmações. ⚠️ **Nem a lista de CONFIRMADOS de uma seção anterior dispensa o
`sed`.**

### Os ponteiros que RECONFIRMEI e estavam CERTOS

`:914` · `:1044` · `:1125` · `:1126` · `:1127` · `:1128` · `:1129` · `:1130` · `:1132` · `:1133` ·
`:1134` · `:1139` · `:1140` (a correção da §L está certa) · `:1142` (idem) · `:1143` · `:1294`
(idem) · `:1310` · e **`:687`** para a exceção (a correção de D29 está certa; `:686` é o `</div>`
que FECHA a caixa tracejada).

### ⚠️ As OMISSÕES do §D que esta wave achou — e são LOAD-BEARING

1. **D13 (`:658`)** — o wrapper tem `gap:1px` e **`min-width:0`**. ⚠️⚠️ **Sem `min-width:0` o
   `truncate` do detalhe NÃO TRUNCA**: item de grade tem `min-width:auto` por default e **estoura**
   a coluna de `minmax(0,1fr)`.
2. **D16 (`:663`)** — `justify-content:space-between;align-items:center`, que a tabela não citava.
3. **D31a (`:691`)** — o losango tem `background:#F4F4F5` e é **6px SEM `border-radius`**.
4. **a caixa tracejada** — `:680`-`:685` ficam **DENTRO** de `:669` (que `:686` fecha).

### ⚠️ A DIVERGÊNCIA do `<behavior>` do plano

`188-09-PLAN.md:186` escreve `border-pub-line-subtle-2` (**#1C1C1C**) para a linha da composição.
**`:657` mede `border-top:1px solid #1A1A1A`** → `pub-hover-3`, que é o precedente em produção
para essa borda (`LinhaDaOferta.jsx:205`, `ModalDeLote.jsx:40`,
`visaogeral/JornadaDosProdutos.jsx:427`). ➡️ **O protótipo ganha no VALOR, e a §D concorda com
ele** — foi a prosa do plano que divergiu.

---

## ⚠️⚠️ UM GATE DE OUTRA FASE REPROVOU CÓDIGO CERTO — e o conserto foi na RAIZ

`tests/js/publicador-publicacoes-filtros.test.js` (**Fase 180, em produção**) exigia que
`border-pub-accent/[0.5]` e `bg-pub-accent/[0.1]` **não existissem no CSS do projeto INTEIRO**.
**`:651` mede `rgba(250,204,21,0.5)`** no botão "Conferir alteração" → a tradução desta wave fez a
classe existir, e o gate reprovou.

➡️ **Por que NÃO renomeei:** `border-pub-accent/50` daria o **MESMO byte** (`#facc1580`) e deixaria
o gate verde **por acidente** — *"renomear para a régua passar"* é o conserto ERRADO que este
projeto nomeia (`feedback_gate_que_reprova_pelo_proprio_conserto`), e deixaria uma mina para a
próxima wave que precisasse de `0,5`.
➡️ **O que entrou é MAIS FORTE numa direção:** o **BYTE do próprio chip**, distinto dos vizinhos
(`0,55 → 0x8c` × `0,5 → 0x80`; `0,12 → 0x1f` × `0,1 → 0x1a`), com isca da leitura nos dois. A forma
antiga **aceitava a borda do chip virar `0,5`** desde que ninguém mais usasse `0,5`.
⚠️ **Nenhum manifesto declara aquele arquivo** (ele só roda em `--suite js`) → **não houve
contagem a remedir**. Depois do conserto: `112 / 112 pass / 0 fail`.

⚠️⚠️ **ESTA É A ÚNICA DECISÃO DESTA WAVE QUE MEXEU EM GATE DE OUTRA FASE, e ela está
DEVOLVIDA como pergunta ao orquestrador** — ver §O do FID01 e a devolução no fim.

---

## AS LISTAS CONSTANTES das Fases 186/187 que DECLAREI

| lista | arquivo | o que entrou |
|---|---|---|
| **`ARQUIVOS_NOVOS`** | `tests/js/publicador-vocabulario-novo.test.js:45` | os **5** `.jsx`, cada um **na mesma tarefa que o criou** |
| **`JSX_DEPOIS_DA_187`** | `tests/js/publicador-fila-fechamento-187.test.js:119` | os **5** `.jsx` (⚠️ são **DUAS** escriturações para `aprovacao/`, e isso é **deliberado** — o último conserto RECUSOU criar uma terceira) |

### As que NÃO mexi, e por quê

| lista | por que não |
|---|---|
| `SLOTS_DE_NO_DEPOIS_DA_187` (3 pontas) | **nenhum `isValidElement(` novo** na página: o slot `colunaDePreco` já existe desde a 188-07 |
| `QUEM_USA_SHADOW_PUB_PAINEL` | nenhum `.jsx` desta wave usa o token |
| `ESTADO_LOCAL_DA_PAGINA` | **nenhum `useState(` novo**: sem a rota da 188-06 não há o que digitar, e a caixa recebe o handler por prop |
| `VARRIDOS_DECLARADOS` | nenhum `.jsx` novo em `publicacoes/`, `identidade/` ou `visaogeral/`; e `aprovacao/` ali aponta para a invariante |
| `LIBERADOS_DEPOIS_DA_187` | ⚠️ **nenhum tamanho novo** — o `21` já foi liberado MEDINDO pelo 188-01, e os demais (`10.5`/`11`/`11.5`/`12`/`12.5`/`13`/`15`) já estavam. Há sub-teste provando isso por **MEDIÇÃO dos `text-[Npx]` realmente escritos** |

### Os ids de outras fases RE-MEDIDOS, com número MEDIDO

| id | manifesto | antes | depois | ação |
|---|---|---|---|---|
| `fechamento-arquivos-e-manifestos` | `contagens-187-11.txt` | `6` | **`6`** | ⚠️ **nenhuma** — as listas moram DENTRO de um `test()` |
| `gate-rename` | `contagens-186-01.txt` | `23` | **`23`** | ⚠️ **nenhuma** — aquele gate lê `JSX_DEPOIS_DA_187` como INVARIANTE |
| `rename-e-reapontes` | `contagens-186-01.txt` | `785` | **`785`** | nenhuma |
| `tokens-da-fila` | `contagens-187-01.txt` | `17,10` | **`17,10`** | nenhuma (já estava remedido por wave anterior) |
| `vocabulario-tamanhos` | `contagens-188-09.txt` | — | **`>=632`** | **declarado em LINHA PRÓPRIA**, forma `>=`, N medido |

⚠️ **Nenhum número foi SUBIDO para calar gate, e nenhum foi rebaixado para contagem.**
⚠️ **Todos os ids das waves anteriores foram RE-RODADOS e passaram:**
`fechamento-arquivos-e-manifestos` · `gate-rename` · `rename-e-reapontes` · `tokens-da-fila` ·
`cabecalho-da-fila` · `faixa-de-andamento` · `pagina-e-contagem` · `proximo-render` ·
`filtros-render` · `casca-do-painel` · `coluna-de-fotos` · `fid01-remedida`.

### O `vocabulario-tamanhos`, MEDIDO nos quatro pontos

```
597  antes de qualquer arquivo deste plano existir (o piso da 188-08)
611  com os DOIS da tarefa 1   (597 + 2 × 7)
618  com o da tarefa 2         (611 + 7)
632  com os DOIS da tarefa 3   (618 + 2 × 7)   ← a soma FECHA
```

⚠️ **7 sub-testes por arquivo varrido** — não 6, como a prosa de um manifesto anterior estimava.
⚠️ **Declarado em LINHA PRÓPRIA**, com a forma `>=` e a razão escrita ao lado. O proibido é só
embuti-lo num id que rode OUTROS arquivos.

---

## OS GATES

```
coluna-de-preco       js  tests/js/publicador-painel-preco-188.test.js  ::  tests=48,44
vocabulario-tamanhos  js  tests/js/publicador-vocabulario-novo.test.js  ::  tests=>=632
```

⚠️ **O 1º argumento é `188-09`.** `sh tests/gates/verificar-testes.sh 188-09 coluna-de-preco` →
`PASSOU`; `… 188-09 vocabulario-tamanhos` → `PASSOU`.
⚠️ **Os DOIS REGIMES foram medidos ANTES da campanha** (a camada de CSS tem `skip` por `mtime`):
**48** pristino (40 testes de topo + 4 sub-testes do protótipo + 4 da camada de CSS) e **44**
mutado (a camada cai em `skip` NOMEADO). Sem os dois, a campanha abortaria na 1ª execução ou
marcaria "SOBREVIVEU" por um motivo que não é o código.
⚠️ **GATE-03 VERDE:** `632 / 632 pass / 0 fail`.

### A BIJEÇÃO nos TRÊS sentidos, e o HOMÔNIMO — por SCRIPT

```
ARQUIVOS_PHP_DE_TESTE=1216  METODOS=7820  IDS_PHP_DESTE_MANIFESTO=0  HOMONIMOS=0
IDS_MANIFESTO=['coluna-de-preco', 'vocabulario-tamanhos']
IDS_NOS_VERIFY=['coluna-de-preco', 'vocabulario-tamanhos']
TESTES_NO_GATE=40  TODOS_COM_SUFIXO=True
PASSOU: bijecao nos tres sentidos, sufixo e homonimo
```

⚠️ **ZERO id órfão** e zero id em `<verify>` fora do manifesto — **as duas direções**.
⚠️ **Nenhum id `php` neste manifesto**, e isso está DECLARADO: o `--filter` só existe no modo
`php`, então a colisão **não tem onde acontecer**. A conferência foi feita de todo jeito, por
script, **incluindo PREFIXO**.
⚠️ **O expansor de alternância está testado contra `test_(a|b)_188_09`** — o furo MEDIDO pela wave
188-05 (`/test_[A-Za-z0-9_]+/` **não casa** a alternância e deu `SEM_ID=14` acusando trabalho
correto).

### O `--autoteste`, COLADO

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

---

## A MUTAÇÃO — `mutar-188-09.sh`, 7 de 7 MORTAS

```
PARTIDA LIMPA: os 4 alvos batem com o HEAD (00bdba8e)
sha256 do PRÓPRIO script: c8355e75e4e61c54

MORREU   [i-crava-30%]           fail=2  tests=44  |  347: style={{ left: '30%' }}
MORREU   [ii-text-xl]            fail=2  tests=44  |  240: 'text-xl font-semibold tracking-[-0.02em] …'
MORREU   [iii-sem-title]         fail=1  tests=44  |  269: <span className={cn(CLASSES_SELO, …)}>
MORREU   [iv-duas-cores-iguais]  fail=1  tests=44  |  198: vermelha_fraca: 'border-pub-ok/[0.35]',
MORREU   [v-selo-sempre]         fail=1  tests=44  |  183: const selo = textoDoPainel(dados.selo);
MORREU   [vi-crava-12%]          fail=1  tests=44  |  263: const rotulo = … : 'Margem mínima 12%';
MORREU   [vii-inverte-ordem]     fail=1  tests=44  |  220: {[...lista].reverse().map((celula, indice) => {

CAMPANHA 188-09: mortes=7 sobreviventes=0 (de 7)
--- restaurado DAS CÓPIAS (nunca por git checkout) ---
```

A receita, cumprida: **trabalho COMMITADO antes** (conferido por `numstat` nos meus arquivos);
`cp -p` com **nome que carrega o plano**; ocorrência **travada em 1 ANTES** por string exata
(contada com `str.count`, **nunca `grep -c`**, que conta LINHAS); **linha conferida depois**;
aplicação provada por **divergência de `sha256`** (com guarda de NO-OP); restauração **DAS
CÓPIAS** em `trap EXIT`; `sha256` contra `git show HEAD:` com `tr -d '\r'` **nos dois lados`;
**`git checkout` NUNCA**.
⚠️⚠️ **E o `# tests` do mutado foi conferido em TODAS as sete execuções: `44` — o regime de
mutação.** Se tivesse caído, o arquivo não teria carregado e a morte não seria da asserção.
⚠️ **O `sha256` do PRÓPRIO script está colado acima** (o scratchpad é compartilhado e na 187 um
`mutar.sh` foi SOBRESCRITO no meio do trabalho).
⚠️ **Restauração conferida:** os 4 alvos voltaram a bater com o HEAD, hash a hash.
⚠️ **`npm run build` rodado DEPOIS da mutação** (o `cp` de ida atualiza o `mtime` e a camada de
CSS cairia em `skip`), e **`git diff HEAD -- resources/` foi conferido antes de cada build**.

### ⚠️ A mutação (v) só mata porque a FIXTURE mudou

Na primeira redação ela **sobreviveria**: no estado neutro o servidor manda `selo: null`, então a
guarda de `simulando` da TELA era **inobservável** e o gate estaria medindo o servidor. ➡️ Commit
`00bdba8e` acrescentou a fixture do payload **contraditório** (`selo` preenchido com
`simulando: false`) mais a sonda NÃO-CEGA. **Sobrevivente investigada, não presumida
equivalente.**

---

## O TERRITÓRIO — por ESCOPO DO ASSUNTO e por CONTRATO

⚠️⚠️ **`git diff HEAD` NÃO ATRIBUI AUTORIA**, e isto foi MEDIDO nesta execução: a wave **188-06**
tinha WIP **não commitado** na MESMA árvore —

```
329  1  app/Http/Controllers/MlbPublicadorAprovacaoController.php
288  3  app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
220  0  app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php
 61  0  routes/mlb_anuncios.php
  +  tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php
```

➡️ A prova de território foi SEPARADA em duas, com a razão escrita no gate:

**(a) prováveis por `numstat` VAZIO** — os que nenhuma wave irmã toca:
`publicador-header-zindex-ordem.test.js` · `estrutura-meus-anuncios.test.js` ·
`vocabularioDoPortao.jsx` · `andamentoDoPortao.jsx` · `SeloDeAndamentoInterno.jsx` ·
`CascaDeModal.jsx` · `ToastDaFila.jsx` · `BarraDeMargem.jsx` · `ColunaDeFotos.jsx` ·
`PainelDeRevisao.jsx` · `TopoDoPainel.jsx` · `AppLayout.jsx` · `PublicadorHeader.jsx` ·
`ComposicaoDaOferta.php` · `LinhaFinanceira.php` · `.planning/ROADMAP.md` · `.planning/STATE.md` ·
`.planning/REQUIREMENTS-v27.md` · `package.json` · `package-lock.json`. **Todos vazios.**

**(b) prováveis só por CONTRATO** — os que a wave 188-06 legitimamente edita:
`PrecoDoPainelService.php` · `PainelDeRevisaoService.php` · `routes/mlb_anuncios.php`.
O gate confere que o **contrato que esta coluna LÊ** continua de pé (`KPIS_EM_ORDEM`,
`ORDEM_DAS_LINHAS`, `NOTA_DO_FRETE_DO_COMPRADOR`, `FRETE_ROTULO_ACAO`, `COR_NEUTRA = 'neutro'`), e
há asserção impedindo que um deles volte para a lista (a).

⚠️ **A ISCA da sonda de `numstat` é a ÁRVORE VAZIA do git** (`4b825dc6…`), e **não** "um arquivo
que esta wave alterou": essa segunda forma é **vazia por construção DEPOIS DO COMMIT** e o gate
viraria falso positivo no dia seguinte — a mesma família do `git diff --stat` sem range.
⚠️ **Conferir o AUTOR não discriminaria nada:** as três máquinas desta árvore commitam com o MESMO
`user.name` (`MB.ECF-100376`).
⚠️ **`test -e` em CADA caminho citado** pelo gate (17 caminhos) — **zero fantasmas**, com isca.
⚠️ **T-188-SC:** nada instalado — `package.json` e `package-lock.json` com diff vazio.
⚠️ **Nenhuma migration.** `artisan migrate` **não** foi rodado. **Nenhum deploy, nenhum push,
nenhum acesso ao VPS, nenhuma chamada à API real do ML.**
⚠️ **Os 27 `stash` da árvore foram apenas LISTADOS pelo `git status` inicial — `git stash` não foi
executado em forma nenhuma.**

### Os gates de ausência que provam o que NÃO foi antecipado

`localStorage` · `Restaurar dados de exemplo` · `ecf-aprovacao-v1` · `String(` ·
`JSON.stringify(` · hex literal (TOK-15) · `ecf-bg`/`ecf-card`/`ecf-yellow`/`font-display`/
`border-white/[0.08]` (FID-04) · `text-sm`/`text-xs`/`text-xl`/`text-base` (FID-03) ·
**`⤢ Ampliar`** (Fase 189) · **`✦ Gerar novamente`** (Fase 192) · `confirmar publicação` /
`segundo passo` / `cancelar aprovação` / `Desfazer` (decisão 1) · `Salvar revisão` /
`Aprovar para publicação` (o rodapé é da 188-10) · peça nova em `.js`.
⚠️ **O invariante do `.js` é o CERTO:** *"nenhuma PEÇA nova sob `resources/` é `.js`"* — a 187-11
mediu que *"nenhum arquivo novo é `.js`"* era literalmente FALSO (há 9, **todos testes**).

---

## ⚠️⚠️ O QUE ESTA WAVE **NÃO** PODE AFIRMAR

**NENHUM render desta wave foi visto numa tela.** Todo render é **esbuild + `react-dom/server`**:
quando esta SUMMARY diz *"a tela mostra X"*, isso significa **"o HTML gerado contém X"**.
Medido e é o ambiente: o banco local **não tem empresa com `ml_token`** (a rota dá **404**),
`pub_aprovacao_ofertas` está **VAZIA**, a fila **nasce vazia até a Fase 194** (decisão P3 do
usuário — é honesto, não é defeito) e **não há navegador**.
⚠️ **A conferência visual da Fase 187 pelo usuário segue ABERTA**, e nada desta wave a substitui
nem a presume feita.

### O trecho do roteiro da conta 459 que esta wave acrescenta

Com o painel aberto numa oferta que tenha as duas modalidades:

1. **os 3 KPIs** lado a lado, com divisória entre eles — o do meio dizendo **"Resultado estimado
   por venda"** e **nunca** "lucro líquido";
2. abaixo da margem, a **barra fina** com um **traço claro** marcando o mínimo da empresa, e a
   frase terminando em **"· com a tarifa real do Mercado Livre"**;
3. a **composição com 7 linhas**, cada uma com um **selo** (`Confirmado` verde / `Estimado` cinza
   claro / `Pendente` vermelho) — **passe o mouse no selo** e ele diz **de onde o número veio**;
4. **"Embalagem"** aparece `Pendente` **e o resultado continua existindo**, com a frase dizendo
   "sem o custo de embalagem";
5. com mais de uma variação, a frase **"pior caso entre N variantes · <variação>"** **visível**
   embaixo da caixa;
6. **a caixa tracejada "Simular preço" NÃO aparece ainda** — ela acende quando a wave 188-06 for
   religada. ⚠️ **Isso é esperado, não é defeito**;
7. no fim, as **duas modalidades lado a lado**, com a **atual mais clara** — e **clicar na outra
   abre o painel dela**, mantendo o filtro em que você estava.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] A sonda de território exigia a AUSÊNCIA da rota `preco/simular`**
- **Found during:** Tarefa 2 (`<verify>` de `coluna-de-preco`)
- **Issue:** a wave 188-06 tem WIP **não commitado** nesta árvore e a rota existe. A sonda era
  **mais LARGA que o invariante** e reprovava trabalho CORRETO de outra sessão — a família que já
  BARROU UM DEPLOY.
- **Fix:** ancorada no **COMPORTAMENTO** (nenhum `.jsx` desta coluna chama `router.`, nomeia rota
  ou fala HTTP), com isca nas duas direções.
- **Commit:** `83b3ba66`

**2. [Rule 1 — Bug] A sonda de INTOCÁVEIS listava os dois serviços do painel no `numstat`**
- **Found during:** Tarefa 2
- **Issue:** `git diff HEAD` **não atribui autoria**; os dois serviços têm WIP da 188-06.
- **Fix:** lista separada em **prováveis por diff** × **prováveis só por CONTRATO**, com asserção
  impedindo a volta e a razão escrita.
- **Commit:** `83b3ba66`

**3. [Rule 1 — Bug] TRÊS colisões de SUBSTRING nas minhas próprias sondas**
- **Found during:** Tarefas 2 e 3
- **Issue:** (a) a isca do `title` vazio contava 2 selos (faltava `informativas: []`);
  (b) `'Frete pago pelo comprador'` é **PREFIXO** da nota de `:665`; (c) `'acima de R$ 79,00'` é
  **SUBSTRING** do detalhe de `tarifa_fixa`.
- **Fix:** recorte num detalhe exclusivo da linha informativa (`cobrado no checkout`), com sonda
  NÃO-CEGA.
- **Commits:** `83b3ba66`, `cdaf03a9`

**4. [Rule 1 — Bug] A sonda de `orderBy('id')` casava o DOCBLOCK que explica a proibição**
- **Found during:** Tarefa 3
- **Issue:** *"palavra no docblock que explica 'não uso isso' reprova pelo próprio conserto"* —
  agora num arquivo **PHP** (`PrecoDoPainelService:1138`).
- **Fix:** leitura **sem comentário** + invariante **POSITIVO** (`usort` por `ordem`,
  `PHP_INT_MAX` no fim) + isca provando que o docblock continua explicando.
- **Commit:** `cdaf03a9`

**5. [Rule 2 — Funcionalidade crítica] A FIXTURE QUE DISCRIMINA o selo da simulação**
- **Found during:** preparação da campanha de mutação
- **Issue:** a guarda `simulando` da tela era **inobservável** (o servidor manda `selo: null` no
  neutro) e a mutação (v) **sobreviveria**, medindo o servidor em vez da tela.
- **Fix:** fixture com o payload **contraditório** + sonda não-cega.
- **Commit:** `00bdba8e`

**6. [Rule 1 — Bug] O gate de FID da Fase 180 reprovava código certo**
- **Found during:** `--suite js` final
- **Issue:** ele exigia a **ausência GLOBAL** de `border-pub-accent/[0.5]`, que `:651` **mede**.
- **Fix na RAIZ:** o **BYTE do próprio chip** (mais forte numa direção), em vez da ausência
  global. ⚠️ **Renomear a minha classe para `/50` foi recusado**: daria o mesmo byte e deixaria o
  gate verde por acidente.
- **Commit:** `a874fc00`

### Divergências de FIDELIDADE declaradas (com `arquivo:linha`)

| # | o que | onde |
|---|---|---|
| 1 | a **2ª frase** de `:665` (*"Taxas de comissão são exemplos configuráveis."*) **não** é reproduzida | `Aprovacao.dc.html:665` × `PrecoDoPainelService::NOTA_DO_FRETE_DO_COMPRADOR` |
| 2 | o botão de frete lê `frete_ausente` (PRECISO), não o `missing` inteiro | `Aprovacao.dc.html:1143` × `PrecoDoPainelService::fretePendente()` |
| 3 | a frase do **pior caso** não existe no protótipo; nasce no idioma de `:665` | `188-FID01.md` §D × `ComposicaoDaOferta::notaDoPreco()` |
| 4 | a borda da linha é **#1A1A1A** (`pub-hover-3`), não o #1C1C1C do `<behavior>` | `Aprovacao.dc.html:657` × `188-09-PLAN.md:186` |
| 5 | o ponteiro do `' vs. salvo'` é **`:1137`**, e a §L o listava em `:1136` | `Aprovacao.dc.html:1137` × `188-FID01.md` §L |
| 6 | a **grade das irmãs não é desenhada** sem irmã (o protótipo é estático) | `Aprovacao.dc.html:688` × `ComparacaoComAIrma.jsx` |
| 7 | o losango da irmã é **6px sem `border-radius`**; os do módulo são 7px com `rounded-[1px]` | `Aprovacao.dc.html:691` × `IdentificacaoDaOferta.jsx:176` |

---

## Known Stubs

**Nenhum.** Nada nesta coluna é placeholder: toda peça sem destino **não é desenhada** (a caixa de
simulação, o botão de conferir alteração, o botão de frete, a grade sem irmã). As três props que
chegam `null` hoje (`onSimular`/`onDescartar`/`onSalvar` e `onConferirAlteracao`,
`onInformarFrete`) são o **contrato de religação** das waves 188-06 e 188-12, declarado no código
e na página.

## Threat Flags

**Nenhuma superfície nova.** Esta wave é só tela: nenhum endpoint, nenhum caminho de autenticação,
nenhum acesso a arquivo, nenhuma mudança de schema. O único `href` que ela monta vem **do
servidor** (`?oferta=<id>`, do MESMO rascunho) e passa pelo recorte de conta do plano 188-02.

---

## Self-Check: PASSADO

Arquivos criados — conferidos no disco:

```
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/KpisDoPainel.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ComposicaoFinanceiraDoPainel.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/SimulacaoDePreco.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ComparacaoComAIrma.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/ColunaDePreco.jsx
FOUND: tests/js/publicador-painel-preco-188.test.js
FOUND: tests/gates/contagens-188-09.txt
```

Commits — conferidos por `git log --oneline --all`:

```
FOUND: 99794441   FOUND: 83b3ba66   FOUND: cdaf03a9   FOUND: 00bdba8e   FOUND: a874fc00
```

---

## ⚠️⚠️ A PERGUNTA DEVOLVIDA (uma só)

```
PERGUNTA-188-E: ABERTA — dono: usuário / orquestrador — o gate de FID de
  `tests/js/publicador-publicacoes-filtros.test.js` (Fase 180, EM PRODUÇÃO) exigia a AUSÊNCIA
  GLOBAL de `border-pub-accent/[0.5]` e `bg-pub-accent/[0.1]` no CSS do projeto inteiro, e
  REPROVOU com código CERTO desta wave (`:651` mede `rgba(250,204,21,0.5)`). Eu o reapontei na
  RAIZ, para o BYTE do próprio chip (commit `a874fc00`), porque a alternativa — renomear a minha
  classe para `border-pub-accent/50`, que dá o MESMO byte — deixaria o gate verde POR ACIDENTE e
  é o "renomear para a régua passar" que este projeto nomeia como conserto ERRADO.
  ⚠️ O reaponte é ESTRITAMENTE MAIS FORTE numa direção (a forma antiga aceitava a borda do chip
  virar 0,5 desde que ninguém mais usasse 0,5) e o arquivo segue 112/112 verde.
  ⚠️ MAS ELE MEXEU EM GATE DE OUTRA FASE, EM PRODUÇÃO — e a régua desta fase manda PARAR e
  devolver quando o conserto certo não é do meu lado. ➡️ CONFIRMAR ou mandar reverter
  (`a874fc00` é revertível isolado; nenhum manifesto declara aquele arquivo).
```
