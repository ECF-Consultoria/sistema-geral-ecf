---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 12
subsystem: publicador-aprovacao-painel
tags: [modal, frete, reprovar-imagem, concorrencia, col-04, inertia-invalid, fid-01, mutacao]
requires:
  - "188-05 (a rota de reprovar imagem e o formato `{message, regra}` da recusa)"
  - "188-06 (a prévia do frete, as rotas de preço/frete/conferir, o payload `modais.reprovar.defeitos`)"
  - "188-07 (o helper único `escreverNoPainel` e a casca do painel por portal)"
  - "188-08 (o `onReprovar` do bloco da imagem e a `url_arquivo` da coluna de fotos)"
  - "188-09 (o `onInformarFrete`, o `onSimular`/`onSalvar`/`onDescartar` e o `onConferirAlteracao`)"
  - "188-11 (a casca importada, o slot `modais` e os dois primeiros modais)"
provides:
  - "ModalDeFrete.jsx — MOD-03, com a prévia do servidor"
  - "ModalDeReprovarImagem.jsx — MOD-04, com o submeter VERMELHO"
  - "AvisoDeVersaoMudou.jsx — COL-04, o aviso de concorrência"
  - "PainelDeRevisao.jsx — o slot `aviso` e o caminho do 422 pelo evento `invalid`"
affects:
  - "Pages/Mlb/Publicador/Aprovacao.jsx (desvio declarado: a fiação dos 9 handlers)"
  - "tests/js/publicador-painel-preco-188.test.js (gate da 188-09, remedido)"
  - "tests/js/publicador-fila-filtros-187.test.js (ESTADO_LOCAL_DA_PAGINA)"
tech-stack:
  added: []
  patterns:
    - "o 422 CRU do painel chega pelo evento `inertia:invalid`, lendo o CAMPO `regra`"
    - "prévia e simulação chegam pelo FLASH (as actions são dual-path), sem axios"
    - "a casca da 187 COMPOSTA a partir dos named exports quando a cor do submeter difere"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/ModalDeFrete.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ModalDeReprovarImagem.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/AvisoDeVersaoMudou.jsx
    - tests/js/publicador-painel-modais-b-188.test.js
    - tests/js/publicador-painel-modal-reprovar-188.test.js
    - tests/js/publicador-painel-aviso-versao-188.test.js
    - tests/gates/contagens-188-12.txt
  modified:
    - resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-fechamento-187.test.js
    - tests/js/publicador-fila-filtros-187.test.js
    - tests/js/publicador-painel-preco-188.test.js
decisions:
  - "O 422 do painel NÃO chega pelo `onError` do Inertia — ele é resposta CRUA, e o caminho é o evento `inertia:invalid`. Conserto 100% no cliente; o servidor da wave 188-05 NÃO foi tocado"
  - "O helper lê o CAMPO `regra`, e a busca por substring na mensagem fica como fallback declarado para a sacola `errors`"
  - "A prévia do frete e a simulação de preço chegam pelo FLASH: as actions são dual-path e pelo Inertia o ramo é o `back()->with(...)`"
  - "A casca do MOD-04 é COMPOSTA a partir dos named exports (as duas alternativas foram medidas e descartadas) — `numstat` ZERO nela"
  - "A lista dos 11 do plano foi remedida: só TRÊS chamam o helper; os outros OITO delegam por prop"
metrics:
  duration: "~1 turno (o mesmo do plano 11)"
  completed: 2026-10-19
---

# Fase 188 Plano 12: Os dois últimos modais e o aviso de concorrência

Informar frete (com a prévia que o servidor calcula e manda pelo flash) e reprovar imagem (com o
submeter vermelho e os cinco efeitos que ficam no servidor) fecham os quatro modais; e o aviso de
concorrência acende nos dois gatilhos — inclusive no do **422, que até esta wave nunca chegava à
tela**, porque as recusas do painel são resposta CRUA e o `onError` do Inertia não é chamado para
elas.

## PARTIDA

```
PARTIDA = daca92fe  (a MESMA do plano 11 — os dois planos foram feitos no mesmo turno)
```

## ⚠️ O QUE O PLANO 11 ENTREGOU, E O QUE EU MUDEI DELE

| o 11 entregou | o 12 mudou |
|---|---|
| `ModalDeAjuste.jsx` (MOD-01) | **nada** — `numstat` dele neste plano é VAZIO |
| `ModalDeExcecao.jsx` (MOD-02) | **nada** — `numstat` VAZIO |
| o slot **`modais`** em `PainelDeRevisao.jsx`, **IRMÃO do `<aside>`** | **usado como está**, e ele passou a receber os **quatro** modais (os dois do 11 + os dois do 12) |
| o `escreverNoPainel` com `onError` + busca por **substring** da regra na mensagem | ⚠️⚠️ **MUDADO:** ganhou o caminho do evento **`inertia:invalid`** (que é o único que de fato dispara para as recusas deste painel) e passou a ler o **CAMPO `regra`**; a substring ficou como **fallback declarado** |
| `modalDoPainel` em `ESTADO_LOCAL_DA_PAGINA`, com `item`/`texto`/`responsavelId`/`justificativa` | ⚠️ **ESTENDIDO:** ganhou `valor`, `origem`, `imagemId`, `motivo` e **`versaoVista`** (ver a premissa travada) |
| `onConflito: null` nas quatro escritas da página | ⚠️ **LIGADO** a `registrarConflito`, e entrou um segundo estado, `conflitoDoPainel` |
| os handlers da coluna de preço em `null` (declarado como dívida do 12) | ⚠️⚠️ **LIGADOS** — os cinco (`onSimular`, `onDescartar`, `onSalvar`, `onConferirAlteracao`, `onInformarFrete`) |
| `onReprovar: null` na coluna de fotos (declarado como dívida do 12) | ⚠️ **LIGADO** a `abrirModalDeReprovar` |
| o gate da 188-09 que cobrava `onSimular={null}` — **não tocado pelo 11** (tentei e o gate reprovou com razão: aquele handler é desta wave) | ⚠️ **REMEDIDO AQUI**, com contagem `48,44` INALTERADA |

⚠️ **Nada do plano 11 foi reescrito.** O que mudou nele foi **acréscimo** (o caminho do `invalid` no
helper, que é arquivo do 188-07 e está no `files_modified` dos planos 11 **e** 12).

## BASELINES — re-medidas, com as falhas NOMEADAS e ATRIBUÍDAS

| medida | na PARTIDA | DEPOIS do 11 | DEPOIS do 12 | leitura |
|---|---|---|---|---|
| JS | **5710** / 1 fail | **5774** / 1 fail | **5848** / 5847 pass / **1 fail** / 0 skipped | ✅ acima da baseline |
| PHP `tests/Feature/Publicador/Aprovacao` | **319** / **5616** / **0** | 319 / 5616 / 0 | **319** / **5616** / **0** | ✅ inalterado |
| BUILD | exit **0** | exit 0 | exit **0** | ✅ |
| MANIFEST (pelo JSON) | **495** / **202** | 495 / 202 | **495** / **202** | ✅ |
| PROTÓTIPO | 1432 linhas, `sha256 68624883ce35547e…` | idem | idem | ✅ não se moveu |

⚠️ **A ÚNICA falha JS é a PRÉ-EXISTENTE, ATRIBUÍDA POR EXECUÇÃO ISOLADA:**
`tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce recolhido"*, **Fase
87**. Isolada: **19 / 18 pass / 1 fail**. Não corrigida.
⚠️ **ZERO falha PHP em `Aprovacao/`** (a régua daquele recorte é `0`). As duas alheias conhecidas
(`tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318`, **Fase 179**) vivem **FORA** de
`Aprovacao/` e não entraram nesta medição.
⚠️ **Nada afirmado sobre tela renderizada** — todo render é **esbuild + `react-dom/server`**: *"a tela
mostra X"* significa **"o HTML gerado contém X"**. ⚠️ A conferência visual da Fase 187 **segue
ABERTA**.

## O QUE FOI ENTREGUE

| tarefa | commit | o que |
|---|---|---|
| 1 | `94628844` | `ModalDeFrete.jsx` (MOD-03) + gate `modal-de-frete` + manifesto + as duas listas |
| 2 | `216836dd` | `ModalDeReprovarImagem.jsx` (MOD-04) + gate `modal-de-reprovar` |
| 3 | `31f1a760` | `AvisoDeVersaoMudou.jsx` (COL-04) + o slot `aviso` + o caminho do `invalid` + a fiação dos 9 handlers + gate `aviso-de-versao` + o remédio da 188-09 |

## ⚠️⚠️ A DECISÃO 2: O DESCASAMENTO `{message, regra}` × BUSCA NA MENSAGEM — OS DOIS LADOS MEDIDOS

A wave 188-06 declarou o descasamento e **passou a decisão adiante**. Medi os dois lados, e **o
achado é MAIOR que o descasamento de formato**.

### O lado do SERVIDOR (medido em `MlbPublicadorAprovacaoController`)

```php
private function recusaDoPainel(RegraViolada $e): JsonResponse
{
    return response()->json([
        'message' => $e->getMessage(),
        'regra' => $e->regra,
    ], 422);
}
```

⚠️ **O id da regra NÃO está dentro da mensagem** — ele está num **CAMPO PRÓPRIO**, e o docblock do
método diz por quê: *"é por ele que a tela decide onde marcar o erro… Devolver só a mensagem
obrigaria a tela a casar texto em português"*. A mensagem de versão divergente é
`'Esta oferta mudou enquanto você revisava (você viu a versão %d, a atual é %d). Confira de novo antes de gravar.'`
— **sem** o id.

### O lado do CLIENTE (medido em `PainelDeRevisao.jsx`)

O helper procurava `texto.includes(REGRA_VERSAO_DIVERGENTE)` sobre os valores da sacola `errors`.

### ⚠️⚠️ E O ACHADO QUE MUDA A DECISÃO: **o `onError` NUNCA SERIA CHAMADO**

Medido em `node_modules/@inertiajs/core/dist/index.esm.js` (versão **2.3.21**):

| onde | o que faz |
|---|---|
| `:1912`-`:1913` | `Response.process()` desvia para `handleNonInertiaResponse()` quando a resposta **não tem o header `x-inertia`** |
| `:1947`-`:1959` | `handleNonInertiaResponse()` dispara **`inertia:invalid`** e, se ninguém cancelar, **MOSTRA O MODAL DE ERRO BRUTO** |
| `:1916`-`:1922` | `onError` só é chamado **dentro** do ramo Inertia, a partir de `page.get().props.errors` |
| `:1373`-`:1381` | `onGlobalEvent` **cancela** o evento quando o callback devolve `false` |

➡️ **As recusas deste painel são `response()->json([...], 422)` CRUAS: elas NÃO são resposta Inertia.**
Pelo `onError` elas **nunca chegariam**, e a pessoa veria o **modal de erro bruto do Inertia em cima
do painel**. ⚠️ **Isso é "engolir" com outra roupa: o aviso de concorrência nunca acenderia.**

### A decisão, e por que NÃO foi preciso PARAR e devolver

➡️ **O conserto é 100% do lado do CLIENTE. O servidor da wave 188-05 NÃO foi tocado** (`numstat`
VAZIO em `MlbPublicadorAprovacaoController.php`):

1. o helper registra um ouvinte de `invalid` **só pela duração da requisição** (removido no
   `onFinish` — ouvinte global permanente pegaria o `invalid` de **qualquer outra tela**);
2. ele lê o **CAMPO `regra`** do corpo, chama `onConflito(message)` e **cancela o modal** devolvendo
   `false` (*"dois sinais para o mesmo fato treinam a pessoa a ignorar os dois"*);
3. ⚠️ recusa **sem `regra`** **NÃO** é cancelada — **erro inesperado não pode virar silêncio**, e o
   modal do Inertia é o sinal certo para ele. **As duas pontas estão provadas.**
4. no `onError` (o caminho da sacola `errors`, para o dia em que a recusa virar validação) o **CAMPO
   `regra` é lido PRIMEIRO**, e a busca por substring fica como **fallback declarado** — *"substring
   em mensagem de erro quebra quando alguém reescreve o texto"*, e este projeto já tem **cinco** casos
   de `includes` casando prefixo errado.

⚠️⚠️ **A SAÍDA DE SERVIDOR FOI CONSIDERADA E NÃO APLICADA, e fica como PERGUNTA no fechamento:**
trocar `recusaDoPainel()` por `back()->withErrors([...])` faria o `onError` funcionar, **mas**
(a) é **editar um controller da wave 188-05 que já está commitado**, e (b) `recusaDoPainel()` é
chamado por **NOVE** actions — o formato `{message, regra}` é o que o módulo inteiro usa. ➡️ **Não
decidi isso sozinho.** Ver as PERGUNTAS ABERTAS no fim.

⚠️ **Nota sobre o gate da 188-07** (`casca-do-painel`, `45,41`, **INALTERADO**): a fixture dele monta
a mensagem **COM o id da regra prefixado** (`'COL-04-VERSAO-DIVERGENTE: Esta oferta mudou…'`), que
**não é o que o servidor devolve**. Ela passa pelo **FALLBACK**, e por isso o gate segue verde. ⚠️ Não
toquei naquele arquivo (`numstat` VAZIO): mexer mudaria a contagem dele e ele não está nos meus
`files`. **Fica declarado para o fechamento (188-13): aquela fixture não discrimina o formato real.**

## ⚠️⚠️ A DECISÃO 1: OS HANDLERS DA COLUNA DE PREÇO, LIGADOS

As waves 9 e 10 **não ligaram, de propósito e declarado**. Esta wave ligou **os cinco**, cada um com a
guarda `podeEscreverNoPainel` (empresa **E** oferta **E** versão) — **sem os três o handler sai `null`
e o controle não nasce** (D4: nada de `onClick` vazio, nada de `disabled` decorativo):

| handler | rota | como |
|---|---|---|
| `onConferirAlteracao` | `…painel.conferir-alteracao` | escrita pelo helper |
| `onInformarFrete` | — | abre o **MOD-03** |
| `onSimular` | `…painel.preco.simular` | **LEITURA** pelo helper; o resultado volta no **flash** |
| `onDescartar` | `…painel.preco.simular` com `preco: ''` | idem (volta à simulação NEUTRA) |
| `onSalvar` | `…painel.preco` | escrita pelo helper, com o `preenche` que o servidor devolveu |
| `onReprovar` (coluna de fotos) | `…painel.imagem.reprovar` | abre o **MOD-04**; a escrita leva o `{imagem}` no **caminho** |

⚠️ **As rotas EXISTEM e estão COMMITADAS** (wave 6, `1f2dbe44`) — há asserção lendo
`routes/mlb_anuncios.php` **pelo NOME** de cada uma: handler apontando para rota inexistente é botão
sem destino com outra roupa.
⚠️ **A assimetria que a wave 10 declarou está RESOLVIDA:** a pendência de `alteracao_nao_conferida`
(que era dela) e o botão dos KPIs agora chamam **o mesmo método** no servidor **e** existem os dois.

### ⚠️ E O GATE DA 188-09 FOI REMEDIDO, NÃO AFROUXADO

Aquele gate cobrava `onSimular={null}` na página, com a mensagem *"se a wave 188-06 fechou, religue
pelo helper"*. ⚠️ Manter a cobrança faria o executor seguinte **DESLIGAR o handler para o gate
passar** — o anti-padrão *"gate que reprova pelo próprio conserto"*.

➡️ As asserções apontam para o estado **MEDIDO** (os cinco com a guarda), e o invariante continua
medido em **três** lugares: (a) **D4** pelo caso `semTransporte`, que é por **prop direta** e não
depende da página; (b) **critério 17** pelo helper; (c) **D4 de novo** pela guarda.
**Contagem `48,44` INALTERADA.**

⚠️ **Tentei remediá-lo no plano 11 e o gate reprovou com razão** — naquele momento a página ainda
tinha `onSimular={null}`, porque aqueles handlers são desta wave. **A mudança foi revertida e feita
aqui.** É a prova de que a sonda mede o estado real.

## ⚠️⚠️ COMO A PRÉVIA E A SIMULAÇÃO CHEGAM: PELO **FLASH**, e isso foi MEDIDO

As duas actions de LEITURA são **dual-path** por construção (plano 188-06):

```php
return $request->expectsJson()
    ? response()->json($previa)
    : back()->with('painel_frete_previa', $previa);
```

Medido em `@inertiajs/core` 2.3.21 (`dist/index.esm.js:2287`-`:2289`): o Inertia manda
`Accept: text/html, application/xhtml+xml` **e** `X-Requested-With: XMLHttpRequest`. E
`Request::expectsJson()` exige `acceptsAnyContentType()` **ou** `wantsJson()` — **os dois falsos** com
aquele `Accept`.

➡️ **Pelo Inertia o ramo é o `back()->with(...)`**, e a prévia/simulação chegam pelo **FLASH**
(`painel_frete_previa` / `painel_simulacao`), exatamente como `flash.lote_aprovado` da Fase 187.
⚠️ **ZERO `axios`, ZERO `fetch`, ZERO estado novo para a prévia** — há asserção na página.
⚠️ **E `router.post` tem `preserveState: true` por DEFAULT** (`:2382`), medido também: o estado local
do modal **sobrevive** à volta, e **o campo digitado não é zerado a cada tecla**.
⚠️ **E o modal só fecha se a escrita SAIU** (`if (disparou) fecharModalDoPainel()`): fechar antes faria
a pessoa achar que pediu quando nada foi enviado.

## OS QUATRO MODAIS — a casca IMPORTADA, e o único que a COMPÕE

| modal | plano | casca | `font-pub` no corpo | submeter ativo |
|---|---|---|---|---|
| MOD-01 ajuste | 188-11 | **importada** (default) | ✅ | `bg-pub-accent` |
| MOD-02 exceção | 188-11 | **importada** (default) | ✅ | `bg-pub-accent` |
| MOD-03 frete | 188-12 | **importada** (default) | ✅ | `bg-pub-accent` |
| MOD-04 reprovar | 188-12 | ⚠️ **COMPOSTA** dos named exports | ✅ | ⚠️ **`bg-pub-danger`** (`#EF4444`, `:1403`) |

⚠️ `CascaDeModal.jsx` sai dos **dois** planos com `numstat` **VAZIO** (isca determinística da **ÁRVORE
VAZIA do git**, `4b825dc642cb6eb9a060e54bf8d69288fbee4904`).
⚠️ O gate da **ausência de segunda casca** do plano 188-11 **continua VERDE** com os três `.jsx` novos
no disco, e a sonda **ancorada** segue distinguindo `w-[560px]` de `max-w-[560px]` (que existe
legitimamente em `ToastDaFila.jsx:69`, **NO AR**).

### ⚠️⚠️ Por que o MOD-04 COMPÕE — e por que NÃO foi preciso PARAR

O plano manda **PARAR e devolver** *"se a casca não permitir sobrepor sem editá-la"*. **Medi as três
saídas ANTES de decidir:**

| saída | o que acontece | veredicto |
|---|---|---|
| (a) passar `rotuloDoBotao` ao default | a cor ativa dela é a **CONSTANTE** `CLASSES_SUBMETER_ATIVO = 'bg-pub-accent …'`, e `:1403` quer `#EF4444` → teria de **editar arquivo em produção** | ❌ |
| (b) pôr o botão vermelho no `children` | `children` vai para dentro do `CLASSES_CORPO` (`padding:16px 20px`) → o botão perderia o `border-top`, o fundo `pub-sunken` e a sangria do rodapé de `:888` — **geometria errada com render verde** | ❌ |
| **(c) COMPOR dos named exports** | é **exatamente para isso que eles existem** (o docblock dela: *"E AS PEÇAS INTERNAS SAEM COMO NAMED EXPORTS (REND-05)"*) | ✅ |

➡️ O MOD-04 importa **nove** peças (`CLASSES_BACKDROP`, `CLASSES_CAIXA`, `TopoDoModal`,
`CLASSES_CORPO`, `CLASSES_RODAPE`, `CLASSES_BOTAO`, `CLASSES_CANCELAR`, `CLASSES_SUBMETER`,
`ROTULO_CANCELAR`). ⚠️ **NENHUMA medida é redeclarada** — há asserção coletando as nove medidas da
casca e exigindo que nenhuma apareça na fonte. **Isto NÃO é uma segunda casca: é a MESMA casca,
composta.** E o gate confere, no HTML, que o backdrop sai com `z-[42]` e `pt-[96px]`, a caixa com
`w-[560px]` e `shadow-pub-modal`, e que o **rodapé de `:888` existe** com `border-t` + `bg-pub-sunken`
— que é exatamente o que a saída (b) perderia.

## OS CINCO EFEITOS DE REPROVAR — todos do servidor

| efeito | onde vive |
|---|---|
| tira a foto do conjunto final **NA HORA** | `RevisaoDeImagemService::reprovar()` (Fase 186) |
| manda para **"Fora da publicação"** | idem |
| registra como **reprovada**, com o motivo | idem |
| **PROMOVE A PRÓXIMA A PRINCIPAL** se era a principal | idem |
| **REABRE a aprovação de TODAS as ofertas do conjunto** | idem |

⚠️ **Numa TRANSAÇÃO ÚNICA** (há asserção lendo `DB::transaction` no serviço). ➡️ **A tela só colhe o
motivo e CHAMA** — o pedido que sai do clique é **`{motivo}`** e nada mais (medido). ⚠️ Há sonda
provando que a tela **não fala** de nenhum dos cinco (`principal`, `promov`, `reabr`, `fora da
publica`, `conjunto.`), **cada regex com o seu caso que DEVE casar**.
⚠️ **Reimplementar qualquer um criaria a segunda verdade, e a divergente só apareceria quando alguém
reprovasse a PRINCIPAL** — o caso mais raro e o mais caro.
⚠️ **Mas a tela DIZ o que vai acontecer:** a nota de `:821` (*"A imagem sai do conjunto final e fica
registrada como reprovada."*) é **literal CONGELADO no gate**, com **isca do quase-igual** (sem o
ponto final). **É ela que faz a pessoa entender que não há desfazer.**

## O FRETE: as 3 origens, a prévia e os TRÊS defeitos que o gate achou no meu código

### As TRÊS origens — CHAVE no corpo, RÓTULO na tela

| chave (viaja no corpo) | rótulo (vai para a tela) |
|---|---|
| `cotacao_transportadora` | `Cotação da transportadora` |
| `tabela_cliente` | `Tabela do cliente` |
| `simulador` | `Simulador de frete` |

⚠️ **Lista FECHADA**, por **igualdade de LISTA** e **NA ORDEM**, com o espelho conferido contra
`PubAprovacaoFrete::ORIGENS` **chave por chave E rótulo por rótulo**, mais a prova de que o PHP tem
**exatamente três** (fechada nas **duas** pontas).
⚠️⚠️ **O corpo leva a CHAVE, nunca a FRASE** — a frase do protótipo dá **422**
(`REGRA_ORIGEM_DESCONHECIDA`), e há caso lendo o pedido que sai do clique. ⚠️ Origem adversa (12
formas, inclusive a FRASE e o `prod`) cai na **PRIMEIRA**, com a isca da outra direção.

### A PRÉVIA: quatro cores DISTINTAS, e o texto como sinal principal

| estado | `cor` do servidor | classe | texto |
|---|---|---|---|
| sem valor | `neutro` | `text-pub-txt-4` | `Informe o valor para ver o resultado` |
| prejuízo | `vermelho` | `text-pub-danger-txt` | `Resultado R$ -12,40 · margem -8,1%` |
| abaixo do mínimo | `ambar` | `text-pub-warn-txt` | `Resultado R$ 8,10 · margem 5,0%` |
| acima | `verde` | `text-pub-ok-txt` | `Resultado R$ 41,20 · margem 25,4%` |

⚠️ **`new Set(cores).size === 4`** — duas iguais apagariam o sinal. ⚠️ E o **TEXTO** é medido junto,
porque **D7 diz que cor nunca é o único sinal**. ⚠️ Mais a prova de que a tela **não DECIDE** a cor: a
ordem dos ramos é do servidor (**prejuízo vence margem baixa**, e invertê-la **pintaria de âmbar um
prejuízo**).
⚠️ **ZERO aritmética de taxa e ZERO data no cliente:** ONZE formas vetadas, lidas **SEM comentário**,
com a prova de que a **prosa CRUA cita os números** (senão a isca da leitura sem comentário ficaria
vazia e o gate passaria a reprovar pelo próprio conserto).
⚠️ **`equilibrio`/`precoAlvo` são PROIBIDOS na fonte** — a prévia **não os calcula**, e o servidor
declara por quê: *"número plausível e errado é pior que número ausente"*.

### ⚠️⚠️ OS TRÊS DEFEITOS REAIS NO MEU CÓDIGO, achados pelo próprio gate

**1. `valorDoFrete('-5')` devolvia `5`.** O `replace` de não-dígitos **come o menos**, e o valor
negativo entrava como **POSITIVO**. ⚠️ `PrecoDoPainelService::sanear():741` recusa o sinal (e o `−`
U+2212) **ANTES** de limpar, e o docblock dele chama isso de **"divergência (3)"**: o `num()` do
protótipo (`:1048`) **TEM** esse defeito, e **o produto o conserta**. ➡️ A função virou **espelho de
`sanear()` passo por passo**, com asserção lendo o PHP para travar a regra.

**2. `'1.2.3'` passava.** `parseFloat` devolveria `1.2`; o servidor recusa por `is_numeric`
(`sanear():750`). ➡️ A tela habilitaria o botão para um valor que volta **422**. ⚠️ **São duas leituras
do MESMO campo, e a divergente é a que decide se o botão acende** — a classe de defeito mais caro
desta tela.

**3. A prévia NEUTRA do servidor não aparecia.** Eu escondia a prévia inteira com valor inválido.
➡️ **São DUAS coisas diferentes:** a **neutra** (`preenchido !== true`) **TEM de aparecer** — ela *é* o
estado "sem valor" do critério 14 —, e a **preenchida** só vale **enquanto o valor digitado ainda é o
que ela mediu** (exibir o resultado de um valor antigo seria a tela **afirmando um número que não é
mais o da tela**). ⚠️ **E nunca `R$ 0,00`:** zero afirmaria que o frete é de graça (D4), e há asserção
explícita.

## O AVISO DE CONCORRÊNCIA (COL-04 / critério 17)

### Os dois gatilhos

| gatilho | de onde vem | o que a tela mostra |
|---|---|---|
| **1** — o payload trouxe versão diferente da que a tela viu | `versaoVista` guardada quando o modal abriu × `painel.versao` | `Esta oferta mudou enquanto você revisava. A versão em vigor agora é a N. Recarregue a revisão antes de gravar.` |
| **2** — uma escrita voltou **422 de versão divergente** | `onConflito` do helper, pelo evento `invalid` | ⚠️ **a mensagem do SERVIDOR**, que diz as **DUAS** versões — e ela **não é remontada no cliente** |

⚠️ **O aviso é CAIXA BLOQUEANTE, não toast.** Toast tem vocabulário **TRAVADO com gate** (VOC-08,
**SEIS** frases FECHADAS, e o usuário **RECUSOU o sétimo em 19/10**) e **some em ~3,8 s**: *"um aviso
de concorrência que desaparece é um aviso que não houve"*. ➡️ Há sonda provando que **nenhuma** das
seis frases aparece, que o aviso **não importa nem monta `ToastDaFila`**, e que **não há
`setTimeout`/`setInterval`**.

### ⚠️ O MOLDE VISUAL: medido e DECLARADO

⚠️ **COL-04 NÃO TEM ELEMENTO NO PROTÓTIPO** — medido. ➡️ Foi reusado o molde do **aviso de alteração
de preço** (`:649`-`:651`: `background:#14120A` = `pub-row-new`, borda `rgba(250,204,21,0.35)` =
`pub-accent/[0.35]`, raio 9px, 12,5px, glifo `↻`, texto em `flex:1` e um botão à direita), **por
SEMELHANÇA DE FUNÇÃO**: os dois dizem *"algo mudou, confira antes de gravar"*.
⚠️ **As classes são IMPORTADAS de `KpisDoPainel.jsx`, não copiadas** — copiá-las criaria a segunda
cópia das mesmas medidas, e a divergente seria vista como **duas caixas âmbares diferentes na mesma
tela**. Há asserção comparando o conjunto renderizado com o **literal daquele molde**, e outra
provando que **nenhuma** das seis medidas é redeclarada.
⚠️ **O que DISTINGUE os dois avisos é o TEXTO e o RÓTULO DO BOTÃO** (`Recarregar a revisão` ×
`Conferir alteração`) — e há asserção de que o rótulo **não** é o do outro. **D7: cor — e glifo —
nunca são o único sinal.**

### ⚠️ A TELA NÃO REABRE NADA

*"REABRE as verificações afetadas"* é efeito que a **Fase 186 já tem**
(`AprovacaoDaOfertaService::registrarMudanca()`, BLOQ-05/BLOQ-06). ➡️ **A tela recarrega e mostra o
estado REAL.** ⚠️ Cinco formas de "reabertura" são **proibidas** na fonte (`reabr`, `reopen`,
`aguardando_aprovacao`, `andamento =`, `bloqueios =`), com isca, e a declaração está no docblock.

### ⚠️⚠️ A PREMISSA INOBSERVÁVEL — **TRAVADA, não descartada**

O **gatilho 1 na PÁGINA** só é alcançável **enquanto um modal está aberto** — é o único momento em que
a tela **GUARDA** a versão que viu (`versaoVista`) e pode receber um payload novo por baixo. **Sem
modal aberto o painel vem fresco do servidor a cada abertura e a cada ↑/↓: não há estado velho sobre o
que avisar**, e o aviso **não acende por construção**.

➡️ **Como ficou travado em vez de descartado:**
- há caso provando que, com as **duas versões IGUAIS**, o aviso **NÃO é desenhado** (e a mutação
  *(vi) acende com iguais* **MORREU**);
- o **componente** é provado nos **DOIS** gatilhos, pela função **PURA** `avisoDeVersao()`;
- ⚠️ **o RUNTIME do recarregamento parcial NÃO é provado — não há navegador nesta máquina**, e isso
  está escrito no docblock da página e aqui.

### ⚠️ VERSÃO ADVERSA NÃO ACENDE

13 formas × 3 combinações (`versaoVista`, `versaoAtual`, as duas). ⚠️ **`typeof NaN === 'number'`**, e
**`0` é versão plausível** que passaria por uma guarda de "inteiro" — a guarda é `Number.isInteger`
**mais** `> 0`. ⚠️ **ZERO `String(`** na comparação e na mensagem (`String({toString: null})` **LANÇA**,
e foi assim que 5 blocos de teste morreram **dentro da mensagem de asserção** no plano 180-05).
⚠️ **Acender sem motivo treina a pessoa a ignorá-lo**, e aí ele deixa de servir exatamente quando
precisa.

## ⚠️⚠️ O GATE DE QUE TODA ESCRITA PASSA PELO HELPER — e a LISTA do plano REMEDIDA

O defeito que isto conserta: **`versao_esperada` é OPCIONAL no corpo** (`188-05`), então **omiti-la faz
`exigirVersao()` receber `null` e LIBERAR a escrita** — literalmente *"sobrescrever em silêncio"*.
⚠️ O gate do helper (188-07) roda **antes de os arquivos que escrevem existirem**: era **gate sobre
ZERO casos**. ➡️ **O gate de verdade mora aqui.**

⚠️⚠️ **E A LISTA DOS ONZE DO PLANO FOI REMEDIDA — por MEDIÇÃO, não por cópia.** Dos onze, **só TRÊS**
chamam o helper:

| papel | arquivos | quantos |
|---|---|---|
| **chamam o helper** | `ColunaDeFotos.jsx`, `RodapeDoPainel.jsx`, `PendenciasDoPainel.jsx` | **3** |
| **delegam por prop** (`onAprovar`, `onReprovar`, `onInformarFrete`, `onSimular`, `onConferir`, `onSubmeter`) | `BlocoDaImagemSelecionada.jsx`, `ComposicaoFinanceiraDoPainel.jsx`, `SimulacaoDePreco.jsx`, `KpisDoPainel.jsx`, `ModalDeAjuste.jsx`, `ModalDeExcecao.jsx`, `ModalDeFrete.jsx`, `ModalDeReprovarImagem.jsx` | **8** |

➡️ **O invariante ficou mais FORTE que o do plano, em TRÊS partes:**
1. **nenhum** dos onze faz chamada de rede (uma asserção **COLETANDO** os infratores, nunca uma por
   arquivo — que faria a contagem crescer com a lista);
2. os **três** que escrevem passam pelo helper;
3. os **oito** que delegam **recebem handler como prop** — ⚠️ se um deles parasse de receber, ele não
   estaria delegando: estaria **não fazendo nada**, e o botão seria **MORTO** (D4).

⚠️ **Exigir o helper nos onze reprovaria OITO arquivos CORRETOS**, e o "conserto" natural seria **mover
a escrita para dentro deles** — o oposto do critério 17.

⚠️⚠️ **E A PORTA DOS FUNDOS FOI FECHADA:** nenhum **outro** `.jsx` de `aprovacao/` escreve. A **única**
exceção é `PainelDeRevisao.jsx` (a **casa** do helper), e ela é **PROVADA** (ele tem o helper), não
afirmada. ➡️ Arquivo novo que passe a escrever **reprova** até entrar na lista.

### As iscas, nas quatro direções

| isca | o que prova |
|---|---|
| (a) a sonda ACHA um `router.post` onde ele EXISTE (`PainelDeRevisao.jsx`) | as seis regex não estão cegas |
| (a') `router . post(` casa; `router.get(` **não** | tolera espaço, e mede ESCRITA, não leitura |
| (b) os **11** caminhos conferidos no disco por `test -e` **ANTES** de concluir | *"lista que não resolve é varredura CEGA, não tudo certo"* |
| (c) `PainelDeRevisao.jsx` **pode** ter `router.post` e **não está** na lista | a sonda não reprova a casa do helper |
| ⚠️ (d) **a PÁGINA também pode** | ela tem um `router.post` **legítimo** em `:690` — a **aprovação em LOTE da Fase 187**, EM PRODUÇÃO, que manda o próprio mapa `versoes`. Uma sonda de *"zero `router.post` na página"* **REPROVA código certo** |

## ⚠️ AS SONDAS MINHAS QUE NASCERAM ERRADAS — e o gate as achou

| # | sonda | o defeito | conserto |
|---|---|---|---|
| 1 | o `src` da miniatura | a **fixture** usava a forma do STUB de `route()` (`/nome{"produto":77}`), e o `"` dela sai **escapado** como `&quot;` no HTML — reprovava por causa da FIXTURE | fixture com a forma de **PRODUÇÃO** |
| 2 | a lista "SEM imagem" | `{toString: null}` **É** um objeto, logo um payload de imagem (adverso, mas payload): o modal **DEVE** abrir com o placeholder. A sonda reprovava comportamento **CERTO** | tirado da lista, com a razão escrita; o caso dele vive no bloco da TELA PRETA |
| 3 | o docblock do `font-pub` | a regex exigia a frase **inteira numa linha**, e o markdown a quebra | regex no **invariante** (`não herda fonte`) |
| 4 | a lista das classes do input | faltava `rounded-[8px]` na **expectativa** (o código a tem; `:808` é `border-radius:8px`) | expectativa completada |
| 5 | a lista dos **11** | o plano a chamava de *"os que escrevem"*; **só 3 escrevem** | dividida em **dois papéis MEDIDOS**, com o invariante mais forte |

⚠️ **Em todos, o conserto foi na RAIZ (o que a sonda mede), nunca "baixar a régua".**

## A TABELA FID-01 — medida por SCRIPT

⚠️ **COMO MEDI: SCRIPT.** `python -I` lendo o protótipo em array e conferindo **cada linha citada por
marca semântica** (32 checagens, **32 CONFIRMADAS**), mais uma **varredura independente por
propriedade**. ⚠️ **O DIRETÓRIO:** `find . -maxdepth 3 -name Aprovacao.dc.html` devolve **só**
`./design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, `sha256 68624883ce35547e…`).

### `cursor` / `white-space` / `text-overflow` em COLUNAS PRÓPRIAS

| elemento | linha | `cursor` | `white-space` | `text-overflow` |
|---|---|---|---|---|
| MOD-03 `<sc-if>` | `:806` | — | — | — |
| MOD-03 `<label>` valor | `:807` | — | — | — |
| MOD-03 wrapper + `R$` + input | `:808` | — | — | — |
| MOD-03 `<label>` origem | `:810` | — | — | — |
| MOD-03 `<select>` | `:811` | — | — | — |
| MOD-03 prévia | `:813` | — | — | — |
| MOD-04 `<sc-if>` | `:815` | — | — | — |
| MOD-04 miniatura | `:816` | — | — | — |
| MOD-04 wrapper + **chip** | `:817` | **`pointer`** | **`nowrap`** | — |
| MOD-04 `<label>` motivo | `:818` | — | — | — |
| MOD-04 `<textarea>` | `:819` | — | — | — |
| MOD-04 `</label>` | `:820` | — | — | — |
| MOD-04 nota | `:821` | — | — | — |
| molde do aviso (COL-04) | `:649` | — | — | — |
| glifo do molde | `:650` | — | — | — |
| botão do molde | `:651` | **`pointer`** | **`nowrap`** | — |

⚠️ **`text-overflow:ellipsis` não existe em NENHUM dos 16.** ⚠️ **A varredura NÃO está cega:** há
asserção de que ela **acha** `cursor:pointer` em `:779` e `white-space:nowrap` em `:890` — sem esse
controle as listas de AUSENTE seriam vazias **por construção**.
⚠️ **E `white-space:nowrap` é a PRIMEIRA propriedade do `<button>` do chip de `:817`** — a posição em
que a Fase 187 o perdeu três vezes. Há asserção na posição.

### ⚠️⚠️ ONDE O FID01 OU O PLANO DIVERGIAM — e o ACHADO NOVO é da §F.4

| o que o documento dizia | real (medido) | o que está DE FATO na linha citada | classe |
|---|---|---|---|
| ⚠️⚠️ §F.4 e o `<interfaces>` do `188-12-PLAN.md`: o `sub`, os chips `'; '` e o **`btnBg: ok ? '#EF4444'`** em **`:1404`** | **`:1403`** | **`:1404` é `if (k === 'regen') { … }`** — ramo da **Fase 192**, que esta fase **não antecipa** | REFERÊNCIA (−1) |
| §F.3: as **três origens** em **`:1403`** | **`:1401`** | `:1403` é o `return` do ramo `reject` | REFERÊNCIA (−2) |
| §F.3: `previewColor` em **`:1402`** | **`:1401`** | `:1402` é a abertura do ramo `reject` (o `> 2` do MOD-04) | REFERÊNCIA (−1) |
| §F.3: a prévia (`fin(…, {freight: v})`) em **`:1402`** | **`:1400`** | idem acima | REFERÊNCIA (−2) |
| §F.3: o `<label>`/input em **`:805`**/**`:806`** | **`:807`**/**`:808`** | `:805` é um `</sc-if>` e `:806` é o `<sc-if>` do MOD-03 | REFERÊNCIA (já corrigida na §F.3) |
| §F.4: a miniatura em **`:814`** | **`:816`** | `:814` é um `</sc-if>` e `:815` é o `<sc-if>` do MOD-04 | REFERÊNCIA (já corrigida na §F.4) |
| §F.4: a nota em **`:820`** | **`:821`** | `:820` é o `</label>` | REFERÊNCIA (já corrigida na §F.4) |

⚠️ **A primeira é ACHADO NOVO desta wave**, e é a **OITAVA** confirmação da lição da §J: `:1404` é
**legítimo e load-bearing** (é o ramo `regen`), então **quem lê a §F.4 sem medir acha que está no
lugar certo**. Está registrada na **§Q** do FID01 (escrita no fechamento do plano 11).
⚠️ As três seguintes **CONFIRMAM a wave 188-06** pela segunda vez.

### ⚠️ O que a §F.3 PRESCREVE e o que o produto fez

A §F.3 diz *"No produto: `ComposicaoFinanceiraService::para($oferta, …)` com o frete simulado"*. ⚠️ A
wave 188-06 já mediu que **aquela assinatura não existe** (ela força o **PREÇO**, não o frete) e
resolveu trocando a **LINHA** e somando como o DTO soma, com a equivalência **travada por asserção num
PONTO FIXO**. ➡️ **Esta wave só LÊ o resultado**, e há asserção de que a tela não calcula nada.

## AS LISTAS CONSTANTES — declaradas, e as que NÃO mexi

| lista | esta wave |
|---|---|
| `ARQUIVOS_NOVOS` (`publicador-vocabulario-novo.test.js:45`) | ⚠️ **+3** (`ModalDeFrete.jsx`, `ModalDeReprovarImagem.jsx`, `AvisoDeVersaoMudou.jsx`) |
| `JSX_DEPOIS_DA_187` (`publicador-fila-fechamento-187.test.js`) | ⚠️ **+3** — **a SEGUNDA escrituração, DELIBERADA** (o conserto da família **RECUSOU** criar uma terceira) |
| `ESTADO_LOCAL_DA_PAGINA` (`publicador-fila-filtros-187.test.js`) | ⚠️ **+1** (`conflitoDoPainel`) — **desvio DECLARADO**: sem ele o **422 seria ENGOLIDO**, que é o que o critério 17 proíbe. E ele **não** vai para a URL |
| `SLOTS_DE_NO_DEPOIS_DA_187` (**TRÊS** pontas: `publicador-fila-cabecalho-187`, `publicador-fila-proximo-187`, `tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php`) | **NÃO mexi**: esta wave **não abre `isValidElement(` novo NA PÁGINA**. Os slots `modais` e `aviso` nascem em `PainelDeRevisao.jsx` e viajam por **expressão**. ⚠️ **Há asserção comparando o conjunto da página com a UNIÃO das DUAS listas**, lida do literal — **e não com uma lista cravada**, que foi o furo *"sonda mais ESTREITA que o invariante"* medido na 188-11 (`proximo`/`rodape`/`tabela` estão **NO AR** desde a Fase 187) |
| `QUEM_USA_SHADOW_PUB_PAINEL` (`publicador-fila-tokens-187.test.js`) | **NÃO mexi**: nenhum `.jsx` desta wave usa `shadow-pub-painel`. ⚠️ A sombra dos modais é `shadow-pub-modal`, e ela vive **DENTRO** de `CascaDeModal.jsx`, que esta wave **importa/compõe** e **não edita** (`numstat` ZERO, há asserção nos três gates) |
| `LIBERADOS_DEPOIS_DA_187` | **NÃO mexi**: **NENHUM tamanho novo**. Os `text-[Npx]` escritos são `11.5 · 12 · 12.5 · 13 · 14`, e os cinco já estavam na lista |
| `VARRIDOS_DECLARADOS` | **NÃO mexi**: nenhum `.jsx` novo em `publicacoes/`, `identidade/` ou `visaogeral/` |

⚠️ **GATE-03 verde:** `vocabulario-tamanhos` medido em **681** (660 + 3 × 7). A soma fecha, e a forma é
**`>=`** porque a contagem **cresce por construção**.

⚠️ **DESVIO DECLARADO da ordem mecânica (§13):** os **TRÊS** `.jsx` nasceram **juntos**, e a razão é
mecânica: **a única coisa que pega COLISÃO DE NOME de variável na página é o `npm run build`** —
medido na wave 188-10, em que `noRodape` já existia (`RodapeDaFila`, Fase 187, **EM PRODUÇÃO**) e
**nenhum teste** pegou, só o esbuild. E o build precisa da página **inteira** fiada. ➡️ **O que a §13
existe para garantir continua garantido:** os três entraram em `ARQUIVOS_NOVOS` **antes do primeiro
`<verify>`**, e o `deepEqual` de `DIRETORIOS_FECHADOS` ficou **VERDE do começo ao fim**. **Três `.jsx`
numa escrituração, três ids em três tarefas.**

## A BIJEÇÃO, O MANIFESTO E O HARNESS

⚠️ **DESVIO DECLARADO: TRÊS arquivos de gate, um por id** (o mesmo do plano 11). O plano lista **um**
arquivo e **três** ids; no modo `js` o harness roda o **ARQUIVO INTEIRO**, e três ids sobre o mesmo
arquivo dariam a **MESMA contagem três vezes**, com nenhum medindo o seu terço.

| id | arquivo | contagem MEDIDA (pristino, mutado) |
|---|---|---|
| `modal-de-frete` | `tests/js/publicador-painel-modais-b-188.test.js` (nome do plano) | `tests=22,18` |
| `modal-de-reprovar` | `tests/js/publicador-painel-modal-reprovar-188.test.js` (**nome novo**) | `tests=19,17` |
| `aviso-de-versao` | `tests/js/publicador-painel-aviso-versao-188.test.js` (**nome novo**) | `tests=12,10` |
| `vocabulario-tamanhos` | `tests/js/publicador-vocabulario-novo.test.js` (**linha própria**, UM arquivo) | `tests=>=681` |

⚠️ **Os DOIS REGIMES foram MEDIDOS pelas DUAS pontas**: `mtime` da fonte adiantado por `touch` e
devolvido por `touch -d`, com o original capturado por `stat -c %y` **ANTES**. `cp -p` preserva o
`mtime`; o `cp` de ida não, e é por isso que `npm run build` vem **DEPOIS** da mutação.
⚠️ **BIJEÇÃO nos DOIS sentidos, por script, nos três gates:** zero id órfão, zero id de `<verify>` sem
declaração, zero id repetido, **um id por arquivo de gate** (asserção coletando), **zero gate
fantasma** e **todo** nome de teste terminando em `188_12`.
⚠️ **Nenhum id `php`** — e **há asserção cobrando a ausência**, declarada em vez de presumida: o
`--filter` do PHPUnit (regex **não ancorado**) só existe no modo `php`, então **a colisão de homônimo
não tem onde acontecer**.
⚠️ **O EXPANSOR DE ALTERNÂNCIA está testado** nos três gates contra `test_(a|b)_188_12` — o furo
MEDIDO pela wave 188-05, que deu `SEM_ID=14` **acusando trabalho CORRETO**.

### `--autoteste` — COLADO

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

### Os ids de OUTRAS waves, re-rodados DEPOIS desta

| id | fase | leitura |
|---|---|---|
| `casca-do-painel` | 188-07 | PASSOU — `45,41` **INALTERADO** (o caso dele exercita o `onError`, que continua existindo e continua não engolindo nada) |
| `coluna-de-fotos` | 188-08 | PASSOU |
| `coluna-de-preco` | 188-09 | PASSOU — `48,44` **INALTERADO** depois do remédio |
| `pendencias-e-rodape` | 188-10 | PASSOU — `42,37` **INALTERADO** |
| `modal-de-ajuste` · `modal-de-excecao` | 188-11 | PASSOU — `27,23` e `23,20` **INALTERADOS** |
| `fechamento-arquivos-e-manifestos` | 187-11 | PASSOU — `6` |
| `gate-rename` | 186-01 | PASSOU — `23` |
| `territorio-e-fid` | 187-11 | PASSOU |
| `filtros-na-url` | 187-06 | PASSOU |
| `cabecalho-da-fila` | 187-04 | PASSOU |
| `proximo-render` | 187-05 | PASSOU |
| `selecao-na-url` | 187-09 | PASSOU |

⚠️ **Nenhum número foi SUBIDO para calar gate, e nenhum foi rebaixado para contagem.**

## A MUTAÇÃO — `mutar-188-12.sh`, 12 mutações, ZERO sobrevivente

⚠️ **O trabalho estava COMMITADO antes** (`sha256` contra `git show HEAD:<arquivo>`, com `tr -d '\r'`
nos **dois** lados, nos **quatro** arquivos). ⚠️ **Nome que carrega o plano**, porque o scratchpad é
**COMPARTILHADO** entre subagentes paralelos. `sha256` do script: `e82b6e015069994c9d257602…`.
⚠️ **`git checkout` NUNCA**; restauração **DAS CÓPIAS** em `trap EXIT`.

```
PRISTINO frete   : # tests 22 # pass 22 # fail 0 # skipped 0
PRISTINO reprovar: # tests 19 # pass 19 # fail 0 # skipped 0
PRISTINO aviso   : # tests 12 # pass 12 # fail 0 # skipped 0

[i-motivo-maior-igual-2]   MORREU (linha 219) — # tests 17 # fail 1
[ii-botao-amarelo]         MORREU (linha 205) — # tests 17 # fail 1
[iii-chip-substitui]       MORREU (linha 234) — # tests 17 # fail 1
[iv-previa-velha]          MORREU (linha 242) — # tests 18 # fail 1
[v-engole-o-422]           MORREU (linha 319) — # tests 12 # fail 1
[vi-acende-com-iguais]     MORREU (linha 121) — # tests 10 # fail 1
[vii-duas-origens]         MORREU (linha 123) — # tests 18 # fail 3
[viii-menos-comido]        MORREU (linha 199) — # tests 18 # fail 1
[ix-regra-por-substring]   MORREU (linha 325) — # tests 12 # fail 1
[x-sem-versao-esperada]    MORREU (linha 344) — # tests 12 # fail 1
[xi-abre-sem-imagem]       MORREU (linha 398) — # tests 17 # fail 1
[xii-nota-encurtada]       MORREU (linha 147) — # tests 17 # fail 3

RESTAURADO OK: ModalDeFrete.jsx · ModalDeReprovarImagem.jsx · AvisoDeVersaoMudou.jsx · PainelDeRevisao.jsx
```

⚠️ **O `# tests` do MUTADO é IGUAL ao regime mutado do manifesto** nos três gates (18 · 17 · 10) — e
foi assim que o manifesto ganhou os dois regimes, **não ao contrário**.
⚠️ Ocorrência **travada em 1 ANTES** por **string exata e de UMA LINHA** (por `grep -o`, que conta
**ocorrências**; `grep -c` contaria **LINHAS** — e padrão **multi-linha** é tratado por `grep` como N
padrões independentes, achado da campanha do 188-11), **linha conferida DEPOIS**, e aplicação provada
por **divergência de `sha256`** (NO-OP abortaria).
⚠️ **As duas mutações mais importantes são a (v) e a (x):** *(v)* tirar o ouvinte de `invalid` **mata o
aviso** — ela MORRE, e é a prova de que o caminho novo é o que de fato carrega o 422; *(x)* tirar o
`versao_esperada` do corpo **MORRE**, e é a prova de que a chave viaja **sempre**.

## A TELA PRETA, O ROLLUP E O TERRITÓRIO

⚠️ **Render adverso REAL** com **18 formas** por campo — nos três `.jsx`, em cada campo, em cada item
de lista (origens, defeitos, campos da prévia, campos da imagem), na **prop inteira**, nos handlers,
**e TAMBÉM NO CLIQUE** (o submeter, o cancelar, o backdrop, o `stopPropagation` do corpo, os chips e
todos os `onChange`), com `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`.
⚠️ **NUNCA `String()` nem `JSON.stringify()` na mensagem de asserção** — eles **lançam/mentem** com
`{toString: null}`. ⚠️ E `String(` está **PROIBIDO** na fonte dos três.
⚠️ **E `src=""` é proibido explicitamente** no bloco adverso do MOD-04: **string vazia num `src` faz o
navegador RECARREGAR A PÁGINA** (IMG-13).
⚠️ **O filho OBJETO COMUM passando pela casca** é provado nos três gates (conserto medido na 187-09 —
*"Objects are not valid as a React child"*).
⚠️ **ROLLUP:** as flags vivem **DENTRO** do callback dos `.map()` (origens, chips), e o **bundle DE
VERDADE** renderiza os três `<option>` e os três chips.
⚠️ **TERRITÓRIO:** 15 a 17 formas vetadas por arquivo, **cada regex com o seu caso que DEVE casar**;
recorte à **lista CONSTANTE** de cada tarefa (varrer `app/` **barrou um deploy** na Fase 186). Inclui
`localStorage`, `String(`, `JSON.stringify(`, hex cru (TOK-15), FID-03/04/05, a Fase **189**
(`Ampliar`), a Fase **192** (`Gerar novamente com IA`), o segundo passo da **decisão 1**, *"lucro
líquido"*, `duration-[Nms]`, `new Date(` (COL-06) e `setTimeout(`/`setInterval(` (o aviso é
bloqueante).
⚠️ **E a prova de que a leitura SEM COMENTÁRIO é o que salva:** a prosa CRUA do MOD-04 **diz** *"não há
desfazer"* e a fonte sem comentário **não** — asserção nas duas pontas. Idem para os números de taxa
no MOD-03.
⚠️ **T-188-SC:** `package.json` e `package-lock.json` com `numstat` **VAZIO**.
⚠️ **INTOCÁVEIS com `numstat` VAZIO:** `CascaDeModal.jsx`, `ToastDaFila.jsx`, `vocabularioDoPortao.jsx`,
`BarraDeMargem.jsx`, `publicador-header-zindex-ordem.test.js`, **`AppLayout.jsx`**,
**`PublicadorHeader.jsx`**, e os três do `.planning/` (GATE-07: `ROADMAP.md`, `STATE.md`,
`REQUIREMENTS-v27.md`). ⚠️ **E o ACERVO**: seis formas vetadas no MOD-04, com isca, mais `numstat` em
`ArquivosDeImagem.php`.

## TERRITÓRIO — por ESCOPO DO ASSUNTO

⚠️ **A prova é por ESCOPO DO ASSUNTO `(188-12)`, nunca por RANGE** — a 187-11 mediu 111 commits no
range, **43 da sessão paralela**. ⚠️ **E o AUTOR NÃO discrimina:** as três máquinas desta árvore
commitam com o MESMO `user.name` (`MB.ECF-100376`).

```
94628844  feat(188-12): o modal de frete …
216836dd  feat(188-12): reprovar imagem …
31f1a760  feat(188-12): o aviso de concorrencia …
```

⚠️ **ANTI-FANTASMA (`test -e`) em CADA caminho citado pelos três gates** — incluindo os **11** de
`QUEM_ESCREVE`, **antes** de qualquer conclusão. Há asserção extra provando que
`app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` **continua não existindo** (o real é
`app/Services/Publicador/Fila/ResumoRapidoService.php`, **sem** o `Aprovacao/`).

## O ROTEIRO DA CONTA 459 — o trecho desta wave

⚠️ **Nada aqui foi visto numa tela.** Quando a fila tiver oferta (Fase 194):

1. abrir uma oferta com **frete pendente** e clicar **"Informar frete confirmado"** na linha de frete
   → o **MOD-03** abre com o `R$` colado à esquerda do campo e a frase *"Informe o valor para ver o
   resultado"*;
2. digitar `18,50` → a prévia passa a dizer `Resultado R$ … · margem …%`, **na cor** que o servidor
   mandou; apagar tudo → **volta à frase neutra** (e **nunca** `R$ 0,00`);
3. digitar `-5` → o botão "Confirmar frete" fica **apagado** (o sinal é recusado, igual ao servidor);
4. trocar **"Origem do valor"** para *"Tabela do cliente"* e confirmar → a versão da oferta
   **incrementa**, e a linha de frete passa a dizer a **fonte e a data**;
5. na coluna de fotos, selecionar uma foto **pendente** e clicar **"Reprovar"** → o **MOD-04** abre com
   a miniatura de 120px, o sub `{rótulo} · Gerada por IA|Original` e a nota *"A imagem sai do conjunto
   final e fica registrada como reprovada."*;
6. clicar **dois chips de defeito** → o motivo acumula com **`; `** entre eles (e **não** apaga o que
   já estava escrito); com **2** caracteres o botão fica apagado, com **3** habilita, e ele é
   **VERMELHO**, não amarelo;
7. ⚠️ **a prova de concorrência exige DUAS pessoas** (ou duas abas): abrir a mesma oferta nas duas,
   confirmar o frete na primeira, e então clicar em qualquer ação na segunda → a segunda mostra a
   **caixa âmbar** com a mensagem do servidor dizendo **as duas versões**, e o botão **"Recarregar a
   revisão"**. ⚠️ **A caixa NÃO desaparece sozinha.**

## Known Stubs

Nenhum. Os quatro modais e o aviso estão ligados ponta a ponta (payload do servidor → tela → rota pelo
helper único), e nenhum campo nasce com valor fixo.

⚠️ **O que continua `null` DE PROPÓSITO, e tem dono:**

| prop | onde | dono / razão |
|---|---|---|
| `onTentarDeNovo` | `IdentificacaoDaOferta` | a rota existe (188-05) e a página a liga; segue como está |
| o botão **"Abrir revisão detalhada ↗"** de `:558` | — | **PERGUNTA-188-B3**: ele **não é desenhado** nesta fase (nasce na **Fase 190/191**). *"Desenhar desabilitado com explicação"* foi **OFERECIDO e RECUSADO** |
| os destinos de `ajuste_em_aberto` e `titulo_longo` | `PendenciasDoPainel` | **PERGUNTA-188-B**: ficam `null` e **sem botão** enquanto a rota da revisão detalhada não existir; **o TEXTO continua aparecendo** |

## Threat Flags

Nenhuma superfície nova. As escritas usam rotas que **já existiam** (planos 188-05/06), com
`versao_esperada` sempre no corpo pelo helper único. ⚠️ **E uma observação de SEGURANÇA que é ganho:**
o ouvinte de `invalid` **cancela** o modal de erro bruto do Inertia **só** quando a recusa traz
`regra` — ou seja, só para as recusas **deste** painel. Erro de servidor genérico **continua
aparecendo**.

## ⚠️ PERGUNTAS ABERTAS — devolvidas, não decididas

```
PERGUNTA-188-E: ABERTA — dono: usuário / Fase 188-13 — O FORMATO DA RECUSA DO PAINEL.
  `MlbPublicadorAprovacaoController::recusaDoPainel()` devolve
  `response()->json(['message', 'regra'], 422)` — resposta CRUA, não-Inertia. MEDIDO em
  `@inertiajs/core` 2.3.21: o `onError` do Inertia NUNCA é chamado para ela; o caminho é o
  evento `inertia:invalid`, que por default MOSTRA O MODAL DE ERRO BRUTO.
  ⚠️ A wave 188-12 consertou 100% no CLIENTE (ouvinte de `invalid` por requisição, lendo o
  CAMPO `regra`), e NÃO tocou o servidor.
  ➡️ A pergunta: vale trocar `recusaDoPainel()` por `back()->withErrors([...])`, que faria o
  `onError` funcionar nativamente? ⚠️ Custo: é editar um controller da wave 188-05 já
  commitado, e `recusaDoPainel()` é chamado por NOVE actions — o formato `{message, regra}` é
  o que o módulo inteiro usa, e o `regra` é o id ESTÁVEL que a tela lê (o docblock dele diz
  que devolver só a mensagem obrigaria a tela a casar texto em português).
  ⚠️ NÃO decidi sozinho. O conserto do cliente está no ar e provado; a troca de formato é
  decisão de arquitetura.

PERGUNTA-188-F: ABERTA — dono: Fase 188-13 — A FIXTURE DO GATE `casca-do-painel` (188-07) NÃO
  DISCRIMINA O FORMATO REAL. Ela monta a mensagem COM o id da regra prefixado
  (`'COL-04-VERSAO-DIVERGENTE: Esta oferta mudou…'`), e o servidor NÃO faz isso — o id vive
  num campo próprio. Ela passa pelo FALLBACK por substring, então o gate segue VERDE.
  ⚠️ Não toquei naquele arquivo (`numstat` VAZIO): mexer mudaria a contagem dele (`45,41`) e
  ele não está nos `files` deste plano. O invariante dele ("o 422 não é engolido") está
  coberto, com mais força, por `aviso-de-versao`.
```

⚠️ **E NÃO houve quinto conflito protótipo × regra do projeto.** As duas divergências desta wave
(a nota do protótipo de `:804`, no plano 11, e o `kindValue` de `:1399`) são **prosa de documento** e
**fonte de dado** — nenhuma das duas é rótulo de interface disputado.

## ⚠️⚠️ UM ACHADO A MAIS, do gate `fid01-remedida`: **A NOTAÇÃO DE PONTEIRO DO FID01 É EXCLUSIVA DO PROTÓTIPO**

Ao estender o `188-FID01.md` com a §R, o id `fid01-remedida` (`contagens-188-01.txt`) **REPROVOU com
razão**: ele valida que **todo ponteiro na notação de dois-pontos daquele documento aponta para DENTRO
do protótipo** (`FechamentoDeBaselines188Test:420`, com `LINHAS_DO_PROTOTIPO = 1432`), e eu havia
citado **nove** linhas do `@inertiajs/core` na MESMA notação.

➡️ **Conserto: linha de arquivo que NÃO é o protótipo vai como `L<N>`**, com o arquivo nomeado ao
lado. ⚠️⚠️ **E a segunda redação, que EXPLICAVA o achado, reprovou de novo** — porque a própria prosa
escrevia um daqueles ponteiros por extenso, entre acentos graves, e a sonda o casou. **É a mesma
família do docblock que fechou ele mesmo no plano 11: prosa que cita sintaxe precisa escapar a
sintaxe.** ⚠️ `fid01-remedida` saiu **VERDE** (1 passed / 13 assertions), como a régua exige.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] TRÊS arquivos de gate, um por id**
- **Issue:** o plano lista **um** arquivo e **três** ids de `<verify>`; no modo `js` o harness roda o
  ARQUIVO INTEIRO.
- **Fix:** um arquivo por tarefa, declarado no cabeçalho de `contagens-188-12.txt`, com asserção nos
  três gates provando **um id por arquivo** e **zero gate fantasma**.

**2. [Rule 3 - Mecânica] os TRÊS `.jsx` nasceram juntos**
- **Issue:** a ordem mecânica da §13 pede um por tarefa, mas **só o `npm run build` pega colisão de
  nome de variável na página**, e o build precisa da página inteira fiada.
- **Fix:** os três em `ARQUIVOS_NOVOS` **antes do primeiro `<verify>`**; o `deepEqual` de
  `DIRETORIOS_FECHADOS` ficou VERDE do começo ao fim. Três ids em três tarefas.

**3. [Rule 1 - Bug] `valorDoFrete('-5')` devolvia `5`, e `'1.2.3'` passava**
- **Fix:** espelho de `PrecoDoPainelService::sanear()` passo por passo, com asserção lendo o PHP.

**4. [Rule 1 - Bug] a prévia NEUTRA do servidor não aparecia**
- **Fix:** `valida` passou a distinguir prévia **neutra** (sempre aparece) de **preenchida** (só com o
  valor ainda válido).

**5. [Rule 2 - Alcançabilidade] a fiação dos 9 handlers na página `Aprovacao.jsx`**
- **Issue:** **nenhuma** wave de 11 a 13 tem a página no `files_modified`.
- **Fix:** o fio entra aqui, DECLARADO, pelo mesmo precedente das waves 188-09/10/11.

**6. [Rule 1 - Gate alheio] a 188-09 cobrava `onSimular={null}`**
- **Fix:** asserções remedidas para o estado MEDIDO (os cinco com guarda); o D4 segue provado por prop
  direta. Contagem `48,44` INALTERADA.

**7. [Rule 1 - Sonda própria] cinco sondas minhas nasceram erradas**
- **Fix:** ver a seção própria. Todas consertadas na RAIZ.

### Auth Gates

Nenhum.

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/aprovacao/ModalDeFrete.jsx` — **FOUND**
- `resources/js/Components/Mlb/Publicador/aprovacao/ModalDeReprovarImagem.jsx` — **FOUND**
- `resources/js/Components/Mlb/Publicador/aprovacao/AvisoDeVersaoMudou.jsx` — **FOUND**
- `tests/js/publicador-painel-modais-b-188.test.js` — **FOUND**
- `tests/js/publicador-painel-modal-reprovar-188.test.js` — **FOUND**
- `tests/js/publicador-painel-aviso-versao-188.test.js` — **FOUND**
- `tests/gates/contagens-188-12.txt` — **FOUND**
- commit `94628844` — **FOUND**
- commit `216836dd` — **FOUND**
- commit `31f1a760` — **FOUND**
- `sh tests/gates/verificar-testes.sh 188-12 modal-de-frete` — **PASSOU** (22)
- `sh tests/gates/verificar-testes.sh 188-12 modal-de-reprovar` — **PASSOU** (19)
- `sh tests/gates/verificar-testes.sh 188-12 aviso-de-versao` — **PASSOU** (12)
- `sh tests/gates/verificar-testes.sh 188-12 vocabulario-tamanhos` — **PASSOU** (681)
