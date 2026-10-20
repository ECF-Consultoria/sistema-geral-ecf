---
quick_id: 261020-tdz
status: complete
---

# `261020-tdz` — as telas pretas por uso-antes-da-declaração em `Aprovacao.jsx`

> ⚠️ **Nota de autoria.** O executor desta quick não pôde escrever este arquivo (a ferramenta de
> escrita recusou: *"subagents should return findings as text"*). **O orquestrador o persistiu** a
> partir do relatório integral dele, **acrescentando** o que mediu por conta própria: a verificação
> independente do diagnóstico, o conserto do gate de veto da 189-07 e a suíte JS final. Onde o texto
> diz *"medi"*, é o executor; o que é do orquestrador está marcado.

**PARTIDA:** `bb11a70b` · **Eram CINCO, não quatro** · **Fase 188 em produção** (`e250f9fb`)

| commit | o quê | de quem |
|---|---|---|
| `ee201392` | `test` — o gate que FALTAVA (teste + manifesto + PLAN.md) | executor |
| `85c3b46b` | `fix` — os quatro blocos de volta para antes dos slots | executor |
| `62e5efae` | `fix(gates)` — o veto da 189-07, que reprovava pelo próprio conserto | orquestrador |

---

## 1. O defeito

O corpo de `export default function Aprovacao(…)` (`:312`) lia **cinco** `const` **antes** de elas
serem inicializadas, em posição avaliada na hora. Em JavaScript isso é **TDZ**: a leitura **lança**
`ReferenceError: Cannot access 'X' before initialization`. ➡️ **Tela preta no instante em que o painel
de revisão abre com conteúdo.**

| símbolo | declarada | lida eager em | forma |
|---|---|---|---|
| `podeEscreverNoPainel` | `:1107` | **`:1013`, `:1015`, `:1016`, `:1017`** | `{!nome ? null : …}` — **teste** de ternário |
| `abrirModalDoPainel` | `:1112` | **`:1014`** e `:1080` | teste de ternário **e** prop direta |
| `abrirModalDeReprovar` | `:1165` | `:977` | prop direta |
| `registrarConflito` | `:1231` | `:978` | prop direta |
| `precoComSimulacao` | `:1373` | `:1012` | prop direta |

**O mecanismo:** a linha `:965` é
`const colunaDeFotos = fotosDoPainel === null ? (…) : (<ColunaDeFotos … onReprovar={abrirModalDeReprovar} … />);`
— um **ternário avaliado na hora**. No ramo `null` a JSX não é avaliada e nada lança; no ramo com
fotos ela **é** avaliada e lança.

**Alcançabilidade** (`:883`): `fotosDoPainel = painelDeRevisao === null ? null : …` ➡️ o ramo perigoso
roda **exatamente quando o painel abre com payload**. ⚠️ **É por isso que o defeito estava invisível
em produção:** a fila está vazia, nenhum painel nunca abriu. Com `painel: null` a página renderiza
(`OK len=28119`), e o gate assere isso **antes** de qualquer veredicto, para o vermelho não ser lido
como ruído.

---

## 2. ⚠️⚠️ A CAUSA RAIZ — e ela vale mais que o defeito

**Nenhum gate deste projeto jamais instanciou o `default export` da página.** Todos montam a página
por esbuild e exercitam **só os named exports**. ➡️ **O defeito morava no único lugar que nenhuma
sonda olhava**, e foi por isso que **três waves** (188-09, 188-11, 188-12) o publicaram sem que nada
acusasse.

**O gate que faltava agora existe:** `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js`
(id `pagina-aprovacao-tdz`, **7 casos**), que renderiza a página de verdade.

---

## 3. ⚠️⚠️ ONDE O DIAGNÓSTICO DO ORQUESTRADOR ESTAVA INCOMPLETO

O orquestrador diagnosticou **quatro** e mediu três vezes. **Eram cinco.** O quinto,
**`podeEscreverNoPainel`**, é TDZ **por direito próprio** — não "um dependente", como o briefing e o
`D-189-07-A` supunham.

⚠️ **E ele era invisível para a sonda que achou os outros quatro.** Verificado pelo orquestrador,
rodando a regex da 189-07 (`[A-Za-z_$][\w$]*=\{nome\}` ou `\{\.\.\.nome\}`) contra as três formas:

| forma | a sonda |
|---|---|
| `onReprovar={abrirModalDeReprovar}` | **CASA** |
| `onConferirAlteracao={!podeEscreverNoPainel ? null : 1}` | **NÃO VÊ** |
| `onInformarFrete={abrirModalDoPainel === null ? 1 : 2}` | **NÃO VÊ** |

➡️ **Se o conserto tivesse seguido o briefing ao pé da letra, teria consertado quatro e deixado a
tela preta de pé.** É o oitavo erro de briefing do orquestrador nesta milestone, e **todos** foram
achados por medição de executor.

---

## 4. O RED, com a mensagem real do V8

Gate novo contra as fontes pristinas, **antes** de qualquer conserto: `# tests 7 / # pass 2 /
# fail 5`, `exit=1`.

```
"só fotos: ReferenceError: Cannot access 'abrirModalDeReprovar' before initialization",
"só preço: ReferenceError: Cannot access 'precoComSimulacao' before initialization",
"só pendências: ReferenceError: Cannot access 'abrirModalDoPainel' before initialization",
"tudo junto: ReferenceError: Cannot access 'abrirModalDeReprovar' before initialization"
```

**O método de provar um por vez, declarado:** isolamento por payload (3 dos 5) + **cascata por
render** (os outros 2 nunca são os primeiros a lançar — o primeiro do mesmo bloco os esconde).
Variantes geradas **fora do repo**, cada uma provada **permutação pura** por `sha256` do multiset de
linhas, e re-renderizadas.

---

## 5. O conserto — quatro blocos mudaram de lugar

Commit `85c3b46b`. `numstat` **160 / 126**; o movimento sozinho foi **126 / 126** e é **permutação
pura** — `sha256` do multiset de linhas **idêntico**: `4a5a99fd…`.

| bloco | linhas (pristino) | símbolos | → posição nova |
|---|---|---|---|
| M1 | 1104–1143 | `podeEscreverNoPainel`, `abrirModalDoPainel` | `:1002`, `:1007` |
| M2 | 1146–1206 | `abrirModalDeReprovar` | `:1058` |
| M3 | 1231–1238 | `registrarConflito` | `:1100` |
| M4 | 1360–1376 | `simulacaoDoFlash`, `previaDoFrete`, `precoComSimulacao` | `:1118`, `:1119`, `:1121` |

**A escolha, com a razão:** mover as **declarações** para cima, não os leitores para baixo.
⚠️ **Mover os leitores foi medido e funcionaria** (os três slots são consumidos só em `:1599`–`:1603`,
e tocaria ~83 linhas contra 126) — **recusado** porque deixaria a **ordem de declaração quebrada**, e
a próxima wave que acrescentasse um atributo eager perto dos slots **reintroduziria o defeito**.
⚠️ **A JSX eager NÃO foi trocada por função** (não foi necessário). **Nada mais do arquivo foi
tocado:** zero renomeação, zero `.jsx` de `aprovacao/`, zero serviço de `app/`.

### A prova de que não há TDZ nova

Para **cada** bloco movido, todo identificador que ele lê foi provado declarado **antes** da posição
nova (`empresaId`:344, `ofertaDoPainel`:853, `versaoDoPainel`:854, `flash`:312, `precoDoPainel`:884,
e as dependências internas entre os blocos). ⚠️ **E o veredicto de verdade veio por RENDER** —
`# fail 0` nas camadas que **instanciam** o componente com 6 payloads normais e 88 adversos. A tabela
estática é corroboração, não a prova.

---

## 6. O render adverso, e a armadilha que quase o tornou vazio

10 campos do `painel` × 8 valores (**objeto comum, objeto hostil `{toString:null}`, nulo, ausente via
`delete`, NaN, array, zero, string vazia**), mais o `painel` inteiro nos mesmos 8 = **88 renders**,
exigindo não lançar e `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`.

⚠️⚠️ **A armadilha:** `PainelDeRevisao` devolve `createPortal(…)` e `renderToStaticMarkup`
**não desenha portal**. **Medido: o HTML da página tem exatamente o mesmo tamanho com `painel: null`
e com payload cheio (`len=28119` nos dois).** ➡️ Um `doesNotMatch` sobre o HTML da página **não
mediria nada do painel**. Resolvido com **duas montagens**: a **real** (prova *"não lança"*,
independente do portal, porque a exceção ocorre no corpo de `Aprovacao()`) e um **espião** que
re-exporta os named exports do painel real e desenha os slots **inline**. Isca de não-vacuidade:
`28167 → 30588` (**+2421 bytes de slot**), e nos 63 casos adversos medidos isoladamente **zero**
produziram slot vazio.

---

## 7. ⚠️ O gate de veto da 189-07 reprovou pelo conserto — consertado na raiz

*(esta seção é do orquestrador)*

O caso `publicador-lightbox-acoes-189.test.js:1097` ficou **vermelho** (`25/24/1`) no instante do
conserto. **O executor não o editou e estava certo** — era artefato da Fase 189, fora da autorização
dele. Causa: ele provava não-cegueira **pela existência dos defeitos reais**
(`assert.ok(antesDaHora.length > 0)`), isto é, *"a sonda enxerga porque os defeitos ainda estão lá"*.
⚠️ **Ele era, ele mesmo, membro da família "gate que reprova pelo próprio conserto"** — a família que
o próprio comentário dele dizia evitar.

**Duas correções de raiz** (commit `62e5efae`), nenhuma por elevação de número:

1. **A prova de não-cegueira foi para CEBO SINTÉTICO.** A mesma varredura roda sobre uma fonte
   fabricada com um caso plantado — a prova **não desaparece quando o código está certo**.
2. ⚠️ **A LISTA DE PERMISSÃO SAIU**, e esse era um **segundo** defeito que ninguém tinha notado: com
   os cinco consertados, `HERDADOS` virava **ponto cego** — reintroduzir o defeito em qualquer um dos
   cinco nomes **passaria filtrado**. Agora a asserção é `antesDaHora` **vazia, sem exceção**, e por
   isso pega **regressão** nos cinco. Os nomes seguem listados só como **guarda de renome**.

⚠️ **E uma correção de desenho do próprio orquestrador, no meio do caminho:** a primeira redação
tinha **duas cópias** da regex (uma para a página real, uma para o cebo) — nisso o cebo provaria
*"uma regex desta forma funciona"* em vez de *"a regex que o gate usa funciona"*. **Unificado em uma
função só**, conferido por contagem (`1` ocorrência do mecanismo).

**O cebo foi provado por mutação nas duas direções**, com `mutar-veto-189-07-orq.sh`:

| mutação | resultado | `# tests` |
|---|---|---|
| regex **cega** (não casa nada) | **MORTA**, `exit=1` | 25 = pristino |
| regex **larga** (casa o nome em qualquer posição) | **MORTA**, `exit=1` | 25 = pristino |

Restauração **da cópia** em `trap EXIT`, `sha256` conferido igual à cópia **e** ao `git show HEAD:`.
⚠️ **E a guarda de ocorrência fez o trabalho dela:** na primeira execução as duas mutações
**ABORTARAM** em vez de aplicar às cegas — o Python do Windows não entende o caminho `/c/…` do shell.

⚠️⚠️ **LIMITE DA SONDA, descoberto escrevendo a isca errada e registrado no próprio arquivo de
gate:** ela é **por LINHA**, então **não distingue** `prop={nome}` escrito dentro de uma arrow **na
mesma linha** (`const a = () => <X y={nomeDeBaixo} />;` seria acusado como eager, embora seja
diferido). **Medido: a página real não tem essa forma**, logo hoje não há falso positivo. ⚠️ **Se
algum dia houver, a saída não é afrouxar esta sonda** — a prova larga é a do gate da quick, que
**renderiza**.

---

## 8. A convenção de manifesto — a primeira de QUICK do projeto

**A forma `261020-tdz` NÃO funciona.** `verificar-testes.sh:122` resolve o 1º argumento por
`sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p'` — **dois grupos de DÍGITOS**. Letra cai no `else`, que monta
`contagens-186-<arg>.txt`. Prova empírica (a mensagem de abort imprime o caminho montado):

```
### arg=261020-tdz   EXIT=2   ABORTOU: manifesto nao existe: …/contagens-186-261020-tdz.txt
### arg=261020-01    EXIT=2   ABORTOU: manifesto nao existe: …/contagens-261020-01.txt
```

➡️ **Escolha: `tests/gates/contagens-261020-01.txt`, chamado com `261020-01`** — par puramente
numérico, que casa a forma documentada e cujo caminho o harness monta **exatamente**. Nada ignorado em
silêncio. ⚠️ A alternativa de declarar o id dentro de um manifesto de fase foi **recusada**: os
manifestos das Fases 186–189 são prova de fases em produção. A razão está escrita dentro do arquivo.

**Escrituração conferida e não tocada:** `ARQUIVOS_NOVOS` / `JSX_DEPOIS_DA_187` são listas de arquivos
sob `resources/js`; esta quick **não cria `.jsx` nenhum**, só um `.test.js` em `tests/js/` — **nada a
acrescentar**, e o invariante *"nenhuma peça nova sob `resources/` é `.js`"* fica intacto.
`vocabulario-tamanhos` re-medido **695**, forma `>=`, não subiu.

---

## 9. Mutação do conserto — `mutar-261020-tdz.sh`

**6 de 6 MORTAS, zero sobreviventes.** Cinco devolvem **uma** declaração à posição antiga; a sexta
inverte o ternário de `colunaDeFotos` (o slot para de nascer). ⚠️ **Em todas, o gate NOMEOU o
símbolo**, com a linha de declaração e a de leitura. `# tests = 7` igual ao pristino nas seis.
⚠️ **A mutação de `podeEscreverNoPainel` é a mais valiosa: prova que o gate novo pega o caso que a
sonda da 189-07 não vê.**

### ⚠️ Furo na própria sonda, pego pela guarda do `# tests`

Na 1ª rodada, a mutação de `abrirModalDeReprovar` reportou `bloco = 6 linhas` — o bloco tem **41**. O
mutador fechava o bloco na *"primeira linha em branco"*, e aquele corpo de arrow **tem linhas em
branco dentro**. O corte no meio gerou `SyntaxError`, o arquivo **não carregou**, e o `# tests` caiu
de 7 para 1 — a guarda acusou, e aquela "morte" era **falsa**. Corrigido para fechar o bloco por
**balanceamento de `()[]{}`**.

---

## 10. Baselines

| suíte | PARTIDA | FIM |
|---|---|---|
| **JS** | 6005 / 6004 pass / 1 fail / 0 skipped, `exit=0` | **6012 / 6011 pass / 1 fail / 0 skipped, `exit=0`** |
| **PHP recorte** | 2609 / 23.931 / 0 | **2631 / 24.041 / 0**, `exit=0` |

⚠️ **O `6012 / 6011 / 1` é medição do ORQUESTRADOR, depois do conserto do veto.** O executor mediu
`6012 / 6010 / 2` e atribuiu corretamente a 2ª falha ao veto que ele não podia editar.
Δ JS **+7** = os 7 casos do arquivo novo. Nenhum teste desapareceu.

⚠️⚠️ **A suíte PHP completa NÃO é medível nesta máquina:** `artisan test` aborta com Fatal de memória
em `Illuminate\Http\Testing\FileFactory.php:87` a ~6% de 10.653 testes, e `-d memory_limit=-1` **não
resolve** (o estouro é em processo **FILHO**). Medido pelo **recorte**.

⚠️ **O Δ PHP de +22 testes / +110 asserções é TODO ALHEIO e está atribuído:** `git show --name-only`
dos dois commits desta quick devolve **zero arquivos `.php`**. A origem são os commits do agente de
outra sessão (`EncaminharParaAprovacaoTest.php`, `LimitesPausaERecuperacaoDoAgenteTest.php` e o
crescimento de `EntradaDoPreparoDoAgenteTest.php`).

### Falhas conhecidas, atribuídas por execução ISOLADA

| falha | isolada | atribuição |
|---|---|---|
| `tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce recolhido"* | **19 / 18 / 1** | **pré-existente (Fase 87)**, está na partida. **Não corrigida.** |

⚠️ **Uma medição foi DESCARTADA com a razão escrita:** a 1ª rodada após o conserto deu
`5955 / 5946 / 2 fail / 7 skipped` — 50 abaixo da partida e 7 `skipped` onde havia 0. Havia um recorte
PHP e duas execuções isoladas **em paralelo**, e três blocos que degradam para `skip` nomeado dependem
de `public/build/` existir. Remedida sem concorrência: `6012 / 0 skipped`. ⚠️ **Lição: suíte longa em
árvore compartilhada mede um alvo que se move.**

### Manifest do Vite

**498 / 203 antes e depois** — **igual**, como tinha de ficar (nenhum `.jsx` novo). Lido **pelo JSON**,
nunca por grep. `git diff HEAD --stat -- resources/` conferido **vazio** imediatamente antes do build;
`npm run build` `exit=0`, **depois** da mutação.

---

## 11. ⚠️⚠️ O QUE MUDOU A URGÊNCIA — e não foi decisão nossa

O commit **`c810a8ac`**, de **outra sessão** (quick `261020-oeg`, AG-03), entrou **durante** esta
quick e **liga o produtor que enfileira oferta na aprovação**. ➡️ **A primeira oferta real deixou de
depender só da Fase 194** — o caminho que faria a tela ficar preta **deixou de ser hipotético**.

⚠️ **O conserto está provado mas NÃO foi deployado**, e nenhuma autorização de deploy foi pedida. A
decisão é do usuário.

---

## 12. O que este trabalho NÃO prova

**Não há navegador nesta máquina.** *"A página renderiza"* significa **sempre** *"o render de
servidor do React executa o corpo do componente e devolve HTML sem lançar"*. ⚠️ **Não é afirmável**
que *"a tela aparece certa"* — e, por causa do portal, o HTML do painel **não sai** no render de
servidor da página (só no do espião). `mysqld` local **parado**; `pub_aprovacao_ofertas` vazia; a fila
real não foi exercida. **Nenhum deploy, push, VPS, API do ML ou migration.**

⚠️ **As conferências visuais das Fases 187 e 188 e a `PERGUNTA-VISUAL-189` seguem ABERTAS e
SEPARADAS.**

---

## 13. Outros furos de sonda achados e consertados pelo executor

1. fim de bloco pela *"primeira linha em branco"* → corte no meio, `SyntaxError`, morte falsa (§9)
2. **âncora de fim de bloco errada por um** — a conferência **abortou antes de cortar nada**
3. `git diff --name-only A..B` para medir commit alheio: o range **englobava os commits próprios**;
   corrigido por `git show --name-only`. Família *"diff com range errado não prova nada"*
4. `cmd | tail` devolve o exit do **`tail`** — sempre 0
5. scratchpad fora da árvore do repo **não resolve `node_modules`** para ESM
6. `export * from 'file:///…'` o esbuild **não resolve**
7. `data-slot-colunaDeFotos` com maiúscula deixa o atributo ambíguo no React
8. suíte longa em árvore compartilhada **mede alvo que se move** (§10)
