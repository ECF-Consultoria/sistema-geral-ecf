# Itens DEVOLVIDOS da Fase 189 — fora do escopo de quem os achou

> ⚠️ Este arquivo é **aditivo**. Cada entrada traz **quem achou**, **como foi medido** e **por que
> não foi consertado ali**. ⚠️ **Nada aqui foi consertado pelo plano que o achou** — a régua é
> *"só auto-conserte o que o SEU trabalho causou"*, e todos os itens abaixo são **pré-existentes**.
>
> ⚠️ Com as Fases **190 e 191 ⛔ FORA DE ESCOPO desde 20/10/2026** (decisão 20 do ROADMAP, commit
> `0607f5a9`), item sem dono é escrito **"sem dono (190/191 fora de escopo em 20/10/2026)"** —
> **nunca** *"fica para a Fase 190"*.

---

## D-189-07-A — ✅ **RESOLVIDO em 20/10/2026** · eram **CINCO** usos-antes-da-declaração (TDZ) em `Aprovacao.jsx`

> ✅ **FECHADO pela quick `261020-tdz`** (autorizada pelo usuário), commits **`ee201392`** (o gate que
> faltava) e **`85c3b46b`** (o conserto). ⚠️ **O título original dizia QUATRO; eram CINCO** — ver a
> correção logo abaixo. O corpo original fica preservado adiante, como registro do que a 189-07 mediu.
>
> ### ⚠️⚠️ A CORREÇÃO: o QUINTO, e por que ele era invisível
>
> **`podeEscreverNoPainel`** — declarada em **`:1107`** e lida EAGER **QUATRO vezes**
> (**`:1013`**, **`:1015`**, **`:1016`**, **`:1017`**), dentro do `const colunaDePreco`, na forma
>
> ```jsx
> onConferirAlteracao={!podeEscreverNoPainel ? null : () => escreverDireto(…)}
> ```
>
> — o **TESTE do ternário**, que é avaliado **na hora**. ⚠️ **Ele é TDZ por direito próprio**, não
> "um dependente", como a redação original deste item supunha.
>
> ⚠️⚠️ **POR QUE NENHUMA SONDA O VIA, medido:** a regex da sonda da 189-07 é
> `[A-Za-z_$][\w$]*=\{nome\}` ou `\{\.\.\.nome\}`. Rodada contra as três formas:
>
> | forma | a sonda |
> |---|---|
> | `onReprovar={abrirModalDeReprovar}` | **CASA** |
> | `onConferirAlteracao={!podeEscreverNoPainel ? null : 1}` | **NÃO VÊ** |
> | `onInformarFrete={abrirModalDoPainel === null ? 1 : 2}` | **NÃO VÊ** |
>
> ➡️ **O quinto era invisível para a sonda que achou os outros quatro.** ⚠️ **E pelo mesmo motivo,
> `abrirModalDoPainel` tinha DUAS leituras eager** (`:1014`, antes da de `:1080` que o item original
> cita) — a primeira também na forma de teste de ternário.
>
> ### ⚠️⚠️ A CAUSA RAIZ, e ela vale mais que o defeito
>
> **Nenhum gate deste projeto jamais instanciou o `default export` da página.** Todos montam a página
> por esbuild e exercitam **só os named exports** — o componente nunca era renderizado. ➡️ **O defeito
> morava no único lugar que nenhuma sonda olhava**, e foi por isso que **três waves** (188-09, 188-11,
> 188-12) o publicaram sem que nada acusasse. ⚠️ **O gate que faltava agora existe:**
> `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js` (id `pagina-aprovacao-tdz`, manifesto
> `tests/gates/contagens-261020-01.txt` — **o primeiro manifesto de QUICK do projeto**), com **7 casos**
> que renderizam a página de verdade.
>
> ### O conserto, e a prova
>
> **Quatro blocos mudaram de lugar** (M1–M4), movendo as **declarações** para antes do primeiro
> construtor de slot. ⚠️ **Mover os LEITORES foi medido e RECUSADO**: tocaria menos linhas (~83 contra
> 126), mas deixaria a **ordem de declaração quebrada**, e a próxima wave que acrescentasse um atributo
> eager perto dos slots **reintroduziria o defeito**.
>
> | prova | resultado |
> |---|---|
> | **RED antes** | `7 / 2 pass / 5 fail`, com a mensagem real do V8 para **cada um dos cinco** |
> | o movimento é **permutação pura** | `sha256` do multiset de linhas **idêntico** antes e depois (`4a5a99fd…`) |
> | **nenhuma TDZ nova** | para cada bloco movido, todo identificador que ele lê foi provado declarado **antes** da posição nova — **e o veredicto veio por RENDER**, não por leitura |
> | render adverso | **88 renders** (10 campos × 8 valores + o `painel` inteiro), todos sem lançar |
> | mutação | **6/6 MORTAS**, cada uma devolvendo UMA declaração à posição antiga, e o gate **nomeando o símbolo**; `# tests = 7` igual ao pristino nas seis |
>
> ⚠️ **A armadilha que quase fez o render adverso ser gate vazio:** `PainelDeRevisao` devolve
> `createPortal(…)` e `renderToStaticMarkup` **não desenha portal** — medido, o HTML da página tem
> **exatamente o mesmo tamanho** com `painel: null` e com payload cheio (`28119` nos dois). O gate
> resolveu com **duas montagens**: a real (prova *"não lança"*) e um espão que desenha os slots inline,
> com isca de não-vacuidade (`28167 → 30588`, **+2421 bytes de slot**).
>
> ### ⚠️ E o próprio veto deste item reprovou pelo conserto — consertado na raiz
>
> O caso `publicador-lightbox-acoes-189.test.js:1097` ficou **vermelho** (`25/24/1`) no instante do
> conserto, porque provava não-cegueira **pela existência dos defeitos reais**
> (`assert.ok(antesDaHora.length > 0)`). **Ele era membro da família que o próprio comentário dele dizia
> evitar.** Corrigido pelo orquestrador no commit **`62e5efae`**, com **duas** mudanças de raiz:
> a prova de não-cegueira foi para **cebo sintético** (não desaparece quando o código está certo), e
> a **lista de permissão SAIU** — porque com os cinco consertados ela virava **ponto cego**, deixando
> passar **regressão** em qualquer um dos cinco nomes. ⚠️ **O cebo foi provado por mutação nas duas
> direções** (regex cega → morre; regex larga → morre), com `# tests = 25` nas duas.
>
> ⚠️ **LIMITE DA SONDA, registrado no próprio arquivo de gate:** ela é **por LINHA**, então não
> distingue `prop={nome}` escrito **dentro de uma arrow na MESMA linha**. Medido: a página real não tem
> essa forma, logo **hoje não há falso positivo**. A prova larga é a do gate da quick, que **renderiza**.
>
> ### ⚠️⚠️ O QUE MUDOU A URGÊNCIA — e não foi decisão nossa
>
> O commit **`c810a8ac`**, de **outra sessão** (quick `261020-oeg`, AG-03), entrou **durante** esta quick
> e **liga o produtor que enfileira oferta na aprovação**. ➡️ **A primeira oferta real deixou de depender
> só da Fase 194** — ou seja, o caminho que faria a tela ficar preta **deixou de ser hipotético**.
> ⚠️ **O conserto está provado mas NÃO foi deployado.** A decisão de deploy é do usuário.

<details>
<summary>▶ o registro ORIGINAL da 189-07, preservado (dizia QUATRO)</summary>


**Achado por:** plano **189-07**, 20/10/2026, por script (`tdz-real-189-07.cjs`).
**Dono provável:** waves **188-08**, **188-09** e **188-12** (é delas cada um dos quatro fios).
**Gravidade:** ⚠️⚠️ **TELA PRETA** — `ReferenceError: Cannot access 'X' before initialization`.

**O que foi medido.** O corpo de `export default function Aprovacao(…)` lê **quatro** `const` em
posição **EAGER** (prop de JSX atribuída direto a um `const` do corpo, fora de qualquer arrow) antes
de elas serem inicializadas:

| símbolo | onde é LIDO | onde é DECLARADO | fio |
|---|---|---|---|
| `abrirModalDeReprovar` | `onReprovar={abrirModalDeReprovar}`, dentro do `const colunaDeFotos` | **depois** | 188-12 (B2) |
| `registrarConflito` | `onConflito={registrarConflito}`, no mesmo `const colunaDeFotos` | **depois** | 188-12 (COL-04) |
| `abrirModalDoPainel` | `onAbrirModal={abrirModalDoPainel}` | **depois** | 188-11 |
| `precoComSimulacao` | `preco={precoComSimulacao}`, dentro do `const colunaDePreco` | **depois** | 188-09 |

**Por que é defeito e não estilo.** Em JavaScript um `const` não inicializado está na **TDZ**: a
leitura **LANÇA**. E a leitura é EAGER — `<ColunaDeFotos onReprovar={abrirModalDeReprovar} />` compila
para `jsx(ColunaDeFotos, { onReprovar: abrirModalDeReprovar, … })`, que avalia o identificador **na
hora**. ➡️ **No instante em que `painel.fotos` (ou `painel.preco`) não é nulo — ou seja, no instante
em que o painel de revisão abre com payload — a página inteira lança.**

**A prova, em duas partes (nenhuma presumida):**
1. **A semântica da linguagem**, medida (`tdz-189-07.mjs`): o padrão exato
   `const a = x === null ? null : { p: b }; const b = () => {};` **não lança** no ramo em que a JSX
   não é avaliada e **LANÇA `ReferenceError: Cannot access 'b' before initialization`** no ramo em
   que ela é.
2. **A ocorrência no arquivo**, medida por script sobre a fonte **sem comentário**: as quatro linhas
   acima, com a posição de leitura **antes** da posição de declaração. A sonda tem **isca nas duas
   direções**: ela casa `onReprovar={alvo}` e `{...alvo}` (EAGER) e **não** casa
   `onReprovar={() => alvo(1)}` (diferida, legítima).

**Por que NENHUM gate pegou.** Medido: **ninguém renderiza o `default export` da página.** Os gates
de `tests/js/` montam a página por `esbuild` e exercem **só named exports**
(`MOD_PAGINA.subDoToastDoLote(…)` etc.). O componente nunca é instanciado, e o TDZ só acontece na
instanciação.

**Por que o 189-07 NÃO consertou.** O conserto é uma **reordenação de quatro blocos** de um arquivo de
~1.700 linhas que pertence a três waves da Fase 188 — fora do que este plano causou (régua de escopo:
*"só auto-fixe o que o SEU trabalho causou"*) e fora do que ele pode decidir sozinho. ⚠️ As edições do
189-07 **não acrescentaram um quinto caso**, e isso está medido e **travado por gate**:
`publicador-lightbox-acoes-189.test.js` tem um caso de **VETO POR SUBCONJUNTO** — ele **ACUSA um
quinto** e **fica VERDE quando os quatro forem consertados** (âncora em número que muda é frágil, e
gate que reprova pelo próprio conserto treina a ajustar número).

**O que o conserto precisa.** Mover as declarações de `abrirModalDoPainel`, `abrirModalDeReprovar`,
`registrarConflito` e `precoComSimulacao` (e as delas dependentes: `podeEscreverNoPainel`,
`modalAberto`, `fecharModalDoPainel`, `simulacaoDoFlash`) para **antes** de `const colunaDeFotos` /
`const colunaDePreco`. ⚠️ **E criar o gate que faltava:** um render do `default export` da página com
um `painel` de payload — hoje **nada** o instancia.

**Dono:** **a devolver ao usuário / orquestrador** (não há fase dona: 190/191 fora de escopo em
20/10/2026, e a 188 está encerrada).

---

</details>

## D-189-07-B — gates da 188 que medem **WIP**, não território

**Achado por:** a **189-06** (os dois primeiros) e a **189-07** (o terceiro, que **se concretizou**).

| gate | o que ele mede | o efeito |
|---|---|---|
| `tests/js/publicador-painel-preco-188.test.js` | `git diff --numstat HEAD -- ColunaDeFotos.jsx` | reprova **antes** do commit, verde **depois** |
| `tests/js/publicador-painel-rodape-188.test.js` | idem | idem |
| `tests/js/publicador-painel-casca-188.test.js:1079` | `git status --porcelain -- database/migrations` | ⚠️ **reprova por arquivo UNTRACKED de OUTRA SESSÃO** |

⚠️ **O terceiro aconteceu de verdade em 20/10/2026, durante a 189-07.** A outra sessão criou
`database/migrations/2026_10_20_100000_create_pub_agente_tables.php` (17:09:56, AG-01 do Agente ECF
Publicador) e o gate do **188-07** passou a REPROVAR com a mensagem *"migration apareceu — esta fase
NÃO tem migration"*. Medido: `casca-do-painel` **45/45/0** às 17:0x (antes do arquivo), **45/44/1**
depois, e **45/45/0** de novo quando a outra sessão o commitou (`1253cc86`).
➡️ **Não é regressão de ninguém:** a Fase 189 não tem migration e a 189-07 não criou nenhuma.
**Não consertado:** são gates de fase **EM PRODUÇÃO**, fora do território desta fase.

---

## D-189-07-C — ponteiros de `.planning/` do `189-FID01.md` §G, apodrecidos **de novo**

**Achado por:** a **189-06** (o primeiro) e a **189-07** (o segundo, novo).

| ponteiro escrito no §G | onde está HOJE (reconfirmado por `sed`) |
|---|---|
| `.planning/ROADMAP.md` **L5038** (`MOD-06 (1) \| Phase 190`) | **L5132** — igual ao que a 189-06 mediu, **não** se moveu de novo |
| `.planning/ROADMAP.md` **L4730** (`Requirements: … MOD-06`) | ⚠️ **L4752** — **novo apodrecimento**, e `grep -n "Requirements:.*MOD-06"` devolve **ZERO** na forma citada |

⚠️ **Não consertado:** `189-FID01.md` é lido por **CINCO gates** e o 189-07 não o reescreve (a régua
permite **ESTENDER**, não reescrever). ➡️ O **189-08** registra, e a regra fica: **nunca usar número
de linha de artefato `.planning/` sem RECONFIRMAR por `sed`.**

---

## D-189-07-D — itens já devolvidos por waves anteriores, **reconfirmados intactos**

| item | dono do achado | estado |
|---|---|---|
| `tests/js/publicador-lightbox-casca-189.test.js:1352` — `includes('createPortal')` mais largo que o invariante | 189-04 | **ABERTO**, decisão do **189-08**. A 189-07 **não reabriu** |
| `tests/gates/contagens-186-02.txt:83` — `voc-07-gate-03 … tests=399,399` num arquivo que mede **695** | 189-03 / 189-06 | ⚠️ **CONSERTADO pelo orquestrador em 20/10** (reancorado em `tests=>=399`). A 189-07 **não encostou** |
| `refLabel` (protótipo `:1111`) sem fonte na prop do 189-02 | 189-05 | **DEVOLVIDO**. 1 linha **naquele serviço**, e a régua §3 manda **PARAR**. **Não tocado** |
| "Comparar lado a lado na revisão detalhada" (**AMPL-05**) | 189-05 | **SEM DONO** (190/191 fora de escopo em 20/10/2026) |
| "✦ Gerar novamente com IA" (**AMPL-07**) | 189-05 / 189-06 | segue com a **Fase 192**, que virou a **ÚLTIMA** da milestone |
| linha **"Variação"** não desenhada (INV-10 / A3) | 188-08 / 189-05 | **decidido pelo usuário em 19/10**: seguir sem o nome, sem vazar a chave canônica. **Não reaberto** |

---

# ═══ O FECHAMENTO (189-08) DECIDIU ═══ — 20/10/2026

> ⚠️ **Aditivo.** Nada acima foi apagado ou reescrito. ⚠️ Toda pendência tem **DONO**, e **dono
> indefinido não é dono**. ⚠️ Com as Fases **190 e 191 ⛔ FORA DE ESCOPO**, item sem dono é escrito
> **"sem dono (190/191 fora de escopo em 20/10/2026)"**.

## D-189-08-A — `publicador-lightbox-casca-189.test.js:1352` — ✅ **DECIDIDO: NÃO consertar**

**O que é:** `assert.ok(FONTE_LIGHTBOX.includes('createPortal'), …)` — um mutante que trocasse o
import por função local **mantém o identificador** e o gate **passa**.

**A decisão, com a razão MEDIDA nesta wave:**
1. ⚠️⚠️ **O furo que ela deixava JÁ ESTÁ FECHADO**, e eu medi onde:
   `tests/js/publicador-lightbox-camadas-189.test.js:726`-`:733` (189-04, `86b887bf`) trava a premissa
   pelas **quatro formas EXATAS** — `import { createPortal } from 'react-dom';`,
   `return createPortal(`, `document.body,` e `typeof document === 'undefined'` —, **cada uma casando
   1 vez**, com a isca construída **do mutante M1 de verdade** e uma asserção que **registra** que a
   sonda larga continuaria verde. ➡️ O `:1352` é **REDUNDÂNCIA LARGA, não FURO.**
2. Editá-lo mudaria o `tests=27` que o `contagens-189-03.txt` declara, numa wave **fechada**, e **sem
   ganhar invariante nenhum**.
3. Removê-lo é a família do **"conserto barato"** (tirar asserção da lista).

➡️ **Dono: nenhum pendente** — o invariante tem dono (`camadas-e-css`) e está provado.
⚠️ **Fica NOMEADO** como a **15ª** ocorrência de *"gate mais largo que o invariante"* nesta
milestone — e uma dessa família **já barrou um deploy**.

## D-189-08-B — a família *"gate mede WIP, não território"*: são **24 pontos em 7 arquivos**, não 3

⚠️⚠️ **O registro do `D-189-07-B` dizia TRÊS. Medido agora: são 24 pontos de chamada em 7 arquivos**
(`preco`, `rodape`, `aviso-versao`, `modais-a`, `modais-b`, `modal-excecao`, `modal-reprovar`), **todos
com o 2º argumento ausente** → `HEAD` → comparam a **ÁRVORE DE TRABALHO**. Mais
`casca-188:1084` e `:1097` (`git status --porcelain`).

**O diagnóstico PRECISO, e é pior e melhor do que o registro dizia:**
- **pior:** depois do commit o `numstat` sai **VAZIO SEMPRE** → o gate **reprova ANTES** do commit e
  fica **verde DEPOIS**: mede **WIP**, não território;
- **pior ainda:** ⚠️ cada ponto **TEM** isca de não-cegueira (`numstatDe(rel, ARVORE_VAZIA)`), e ela
  passa — **mas a isca é ELA MESMA mais larga que o invariante**: prova que **o pathspec resolve**,
  **não** que um commit que tocasse o arquivo seria pego. ➡️ *"Isca que prova que a sonda vê ALGO não
  prova que ela vê A AMEAÇA."*
- **melhor:** ⚠️⚠️ **o conserto de RAIZ já está no código.** O helper é `numstatDe(rel, base)` e
  **aceita a base**: passar a **PARTIDA do plano** transforma os 24 pontos em gate de **território**.
  ⚠️ E **`casca-188:549` JÁ USA a forma certa** (`git diff --numstat HEAD~1 HEAD`, um **RANGE**) — a
  forma correta era **conhecida no mesmo arquivo**.

➡️ **DECIDIDO: declarar, NÃO consertar.** São gates de fase **EM PRODUÇÃO** (188), fora do
`files_modified` deste plano e fora do que a `189-REGUA.md` §8 autoriza.
➡️ **Dono: usuário / orquestrador** — é quem pode autorizar mexer em gate de fase em produção.

⚠️ **Estado atual do `casca-188:1097`, medido:** `git status --porcelain -- database/migrations` sai
**VAZIO** (a outra sessão commitou a migration em `1253cc86`) ➡️ **o gate está VERDE de novo**.
⚠️⚠️ **E isto CORRIGE o briefing desta wave**, que listava aquela migration como ainda **untracked**.

## D-189-08-C — os ponteiros do `189-FID01.md` §G apodreceram **UMA TERCEIRA VEZ**

**Reconfirmado por `sed` em 20/10 à noite, depois dos commits de escopo do orquestrador:**

| ponteiro do §G | onde está AGORA |
|---|---|
| `ROADMAP.md` **L5038** | ⚠️ **L5038 está VAZIA**; a linha está em **L5132** (a 189-06 mediu `L5081`, a 189-07 mediu `L5132`) |
| `ROADMAP.md` **L4730** | **L4752** (hoje a L4730 é outro texto) |
| `grep -n "Requirements:.*MOD-06"` | ⚠️⚠️ **ZERO** — o arquivo tem `**Requirements**:`, com os asteriscos **ANTES** dos dois-pontos ➡️ **a forma citada é gate vazio por construção** |

⚠️ **E um TERCEIRO apodrecimento, de natureza diferente:** o §G atribui o **MOD-06** à **Fase 190**,
que foi **CANCELADA** em 20/10. ➡️ O ponteiro **mudou de verdade**, não só de linha.

➡️ **DECIDIDO: NÃO reescrever o `189-FID01.md`** — **CINCO** gates o leem e um tem **isca por BYTES
da §G**. A régua permite **ESTENDER**. ➡️ **Dono: usuário / orquestrador.**
⚠️ **A regra que fica:** nesta milestone **`:N` é EXCLUSIVO do protótipo**; linha de outro arquivo vai
como **`L<N>`** — e **nunca** se usa número de linha de artefato `.planning/` sem **RECONFIRMAR por
`sed`**.

## D-189-08-D — `refLabel`: o placeholder da caixa da original — **sem dono**

O protótipo `:755` passa `placeholder="{{ LB.refLabel }}"` e `:1111` define
`refLabel: m.ref ? this.im(m.ref, D).label : ''` — **o placeholder é o RÓTULO da original**. A prop
`lightbox.originais` do 189-02 traz `disponivel`, `url_arquivo` e `quantas`, **não o rótulo**.
**Conserto: UMA linha** no `LightboxDoPainelService` (ele **já carrega** a original para montar a URL).
⚠️ **Não é conflito protótipo × regra — é lacuna de DADO**, a mesma classe do rótulo de variação.
**Impacto:** aparece **só** quando o arquivo da original está ausente.
➡️ **Dono: sem dono (190/191 fora de escopo em 20/10/2026) — devolvido ao usuário / orquestrador.**

## D-189-08-E — o rótulo de **"Variação"**: o CUSTO FUNCIONAL, escrito

**Decidido pelo usuário em 19/10** (seguir sem o nome, sem vazar a chave canônica). O que faltava era
o **custo**, e ele fica aqui:

⚠️⚠️ **Num produto com 3 cores, as fotos das três aparecem JUNTAS na mesma grade, sem dizer qual é de
qual cor.** Quem revisa vê 12 fotos e **não sabe** que as 4 primeiras são da preta.
➡️ Não é defeito de código: é **lacuna de DADO decidida**. O conserto é tornar público (ou espelhar)
`ResolvedorGruposImagem::rotuloDoGrupo`, hoje `private static` em território VETADO.
➡️ **Dono: sem dono (190/191 fora de escopo em 20/10/2026).**
⚠️ **E o `A4` segue aberto:** o docblock do 188-08 manda *"ver a divergência escrita em `VARIACAO`"* e
**não há `VARIACAO` naquele arquivo** — erro de **REFERÊNCIA dele**.

## D-189-08-F — os **SEIS** caminhos FANTASMA que o anti-fantasma pegou nesta wave

| caminho CONSTRUÍDO a partir do NOME | caminho **REAL** |
|---|---|
| `app/Support/Publicador/ArquivosDeImagem.php` | `app/Support/Publicador/Imagem/ArquivosDeImagem.php` |
| `app/Support/Publicador/ResolvedorGruposImagem.php` | `app/Support/Publicador/Imagem/ResolvedorGruposImagem.php` |
| `app/Services/Mlb/Publicacao/PublicacaoService.php` | `app/Services/Publicador/PublicacaoService.php` |
| `resources/js/Pages/Mlb/PainelCriativosIa.jsx` | `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` |
| `resources/js/Components/Mlb/KitCriativosGrade.jsx` | `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` |
| `app/Services/Publicador/Imagem/ImagemAssetService.php` | `app/Services/Publicador/ImagemAssetService.php` |

⚠️⚠️ **A LIÇÃO: *um NOME não é um CAMINHO*.** A régua §8 e o `CLAUDE.md` citam **classes**; dois dos
três de `App\Support\Publicador` moram num **subdiretório** e o terceiro (`EditorEmUso.php`) não.
Um veto escrito a partir do nome é **gate sobre ZERO casos**, e passa **VERDE para sempre**.
➡️ **Dono: nenhum** (os seis foram corrigidos **na lista deste plano**, antes de qualquer diff).
⚠️ **Total da milestone: 18 fantasmas, TRÊS de briefing do orquestrador.**
⚠️⚠️ **E o 3º da tabela é relevante para a Fase 193:** o `POST /items` do Publicador está em
`app/Services/Publicador/PublicacaoService.php:442` — **não** em `app/Services/Mlb/Publicacao/`.

## D-189-08-G — ⚠️ o critério de aceitação `grep -c "a definir" = 0` é **ARMADILHA POR CONSTRUÇÃO**

Escrever **a própria regra** na seção de perguntas (*"X não é dono"*, com X sendo aquela expressão)
faz o `grep` devolver **≥1 mesmo com todas as perguntas tendo dono**. ➡️ É a família *"palavra no
comentário reprova pelo próprio conserto"* / *"o grep que casa com o comentário"*.
➡️ **O equivalente HONESTO está no gate**, por linha: cada `PERGUNTA-*` casa `dono:\s*\S` e **não**
casa `dono:\s*a\s+definir`. **Medido: 7 de 7 com dono, 0 indefinidos.**
➡️ **Dono: nenhum** (registrado e substituído pelo equivalente honesto).

## D-189-08-H — ⚠️ `conta **459**` NÃO casa `conta\s+459` — face NOVA da família do `\s+`

Medido ao escrever este fechamento: o gate do roteiro reprovou **duas vezes**, e as duas razões são
de forma, não de conteúdo:
1. o recorte de seção devolve o **CORPO**, não o cabeçalho ➡️ *"conta 459"* estava **só no título**;
2. depois de pôr no corpo, `a conta **459**` **continuou não casando** — os **asteriscos do markdown
   ficam ENTRE as duas palavras**, e `\s+` não os atravessa.

➡️ **É face nova** da família *"a frase quebra no markdown e o gate não a acha"*. **Dono: nenhum**
(resolvido escrevendo o número **sem negrito**, com a razão no próprio documento).
