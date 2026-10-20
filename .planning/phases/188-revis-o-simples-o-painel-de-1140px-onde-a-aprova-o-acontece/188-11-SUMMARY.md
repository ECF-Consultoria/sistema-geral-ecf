---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 11
subsystem: publicador-aprovacao-painel
tags: [modal, ajuste, excecao, casca-compartilhada, font-pub, portal, fid-01, mutacao]
requires:
  - "188-06 (o payload `modais.ajuste.itens` e `modais.excecao.autorizadores`, e as rotas `…painel.ajuste` / `…painel.excecao`)"
  - "188-07 (a casca do painel por portal, o helper único `escreverNoPainel` e `resolverAtalhoDoPainel`)"
  - "188-10 (os destinos das pendências e o `onSolicitarAjustes` do rodapé)"
  - "187-09 (`CascaDeModal.jsx` — IMPORTADA, nunca recriada)"
provides:
  - "ModalDeAjuste.jsx — MOD-01, o modal Solicitar ajustes"
  - "ModalDeExcecao.jsx — MOD-02, o modal Solicitar exceção autorizada"
  - "PainelDeRevisao.jsx — o slot `modais`, IRMÃO do `<aside>`"
affects:
  - "Pages/Mlb/Publicador/Aprovacao.jsx (desvio declarado: a fiação)"
  - "tests/js/publicador-painel-preco-188.test.js (gate da 188-09, remedido)"
  - "tests/js/publicador-painel-rodape-188.test.js (gate da 188-10, remedido)"
  - "tests/js/publicador-fila-filtros-187.test.js (ESTADO_LOCAL_DA_PAGINA)"
tech-stack:
  added: []
  patterns:
    - "modal PURO e CONTROLADO: o formulário mora na PÁGINA, como em SimulacaoDePreco.jsx"
    - "`font-pub` declarado no CORPO de cada modal, porque o painel é PORTAL"
    - "expectativa de literal CONGELADA no gate, nunca lida do módulo testado"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/ModalDeAjuste.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/ModalDeExcecao.jsx
    - tests/js/publicador-painel-modais-a-188.test.js
    - tests/js/publicador-painel-modal-excecao-188.test.js
    - tests/gates/contagens-188-11.txt
  modified:
    - resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-fechamento-187.test.js
    - tests/js/publicador-fila-filtros-187.test.js
    - tests/js/publicador-painel-preco-188.test.js
    - tests/js/publicador-painel-rodape-188.test.js
decisions:
  - "A prosa do protótipo de `:804` NÃO vai para a tela — divergência declarada (D7: jargão; e é fala SOBRE o protótipo)"
  - "O detalhe do motivo da exceção é o TEXTO QUE O SERVIDOR ESCREVEU, não o `kindValue` montado no cliente — o `12%` de `:1399` é `MIN`"
  - "DOIS arquivos de gate, um por id: no modo `js` o harness roda o ARQUIVO inteiro"
  - "O slot `modais` é IRMÃO do `<aside>` — dentro do corpo ele ROLARIA, dentro do `<aside>` ficaria preso aos 1140px"
metrics:
  duration: "~1 turno"
  completed: 2026-10-19
---

# Fase 188 Plano 11: Os dois primeiros modais do painel — Solicitar ajustes e Solicitar exceção autorizada

Os modais MOD-01 e MOD-02 nascem **dentro da casca da Fase 187** (`CascaDeModal.jsx`, importada e nunca recriada), cada um declarando `font-pub` no corpo porque o painel desta fase é **portal** e conteúdo portado não herda fonte; o de ajuste lê do servidor os N+**sete** itens com destino e nota por escopo (âmbar só no produto) e concatena a sugestão **com um ponto**; o de exceção libera **só** os dois bloqueios financeiros, com justificativa `>= 10` provada em 9/10/11.

## PARTIDA

```
PARTIDA = daca92fe  (docs(188-10): a SUMMARY do plano 10 e a §P do FID01 …)
```

Capturada na PRIMEIRA ação do turno, por `git rev-parse HEAD`.

## BASELINES — re-medidas, com as falhas NOMEADAS e ATRIBUÍDAS

Medidas **na partida**, antes de qualquer edição, pelo harness (`verificar-testes.sh --suite js`) e
por `artisan test` com o ANSI tirado:

| medida | na PARTIDA | DEPOIS do plano 11 | leitura |
|---|---|---|---|
| JS | **5710** / 5709 pass / **1 fail** / 0 skipped | **5774** / 5773 pass / **1 fail** / 0 skipped | ✅ acima da baseline — contagem ACIMA é esperada |
| PHP `tests/Feature/Publicador/Aprovacao` | **319** passed / **5616** assertions / **0** falhas | **319** / **5616** / **0** | ✅ inalterado |
| BUILD (`npm run build`) | exit **0** | exit **0** | ✅ |
| MANIFEST (pelo JSON) | **495** entradas / **202** páginas | **495** / **202** | ✅ isca ausente, controle presente |
| PROTÓTIPO | 1432 linhas, `sha256 68624883ce35547e…` | idem | ✅ não se moveu |

⚠️ **A ÚNICA falha JS é a PRÉ-EXISTENTE, e ela foi ATRIBUÍDA POR EXECUÇÃO ISOLADA:**
`tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce recolhido (é o grupo
que mais infla)"*, da **Fase 87**. Medido isolado: **19 tests / 18 pass / 1 fail**. Não foi corrigida
(a régua manda não corrigir), e **não** é desta fase.
⚠️ **ZERO falha PHP em `Aprovacao/`** — a régua daquele recorte é `0`, e ela foi cumprida nos dois
pontos. As duas falhas alheias conhecidas (`tests/Feature/Publicador/CockpitDaConta Test.php:966`
e `:1318`, **Fase 179**) vivem **FORA** de `Aprovacao/` e não entraram nesta medição.
⚠️ **Nada afirmado sobre tela renderizada:** todo render deste plano é **esbuild +
`react-dom/server`**. *"A tela mostra X"* aqui significa **"o HTML gerado contém X"**. O banco local
não tem empresa com `ml_token` (a rota dá **404**), `pub_aprovacao_ofertas` está **vazia** e **não há
navegador**. ⚠️ A conferência visual da Fase 187 pelo usuário **segue ABERTA**.

## O QUE FOI ENTREGUE

| tarefa | commit | o que |
|---|---|---|
| 1 | `7fe1f7a3` | `ModalDeAjuste.jsx` (MOD-01) + gate `modal-de-ajuste` + manifesto + as duas listas |
| 2 | `9f5b05d7` | `ModalDeExcecao.jsx` (MOD-02) + o slot `modais` + a fiação na página + gate `modal-de-excecao` |

⚠️ **COMMIT ATÔMICO POR TAREFA**, com `git commit -F <arquivo> -- <caminhos>`, pathspec explícito,
`-m` nunca usado (uma wave da 187 usou `-m` e **perdeu o `Co-Authored-By``).

## A IMPORTAÇÃO DA CASCA — e o gate da outra ponta, ANCORADO

⚠️⚠️ **`CascaDeModal.jsx` é da Fase 187 (plano 09) e está EM PRODUÇÃO. Os dois modais a IMPORTAM.**
`numstat` dela neste plano: **VAZIO** (há asserção nos dois gates, com a **isca determinística da
ÁRVORE VAZIA do git** — `4b825dc642cb6eb9a060e54bf8d69288fbee4904` —, e **não** "um arquivo que esta
wave alterou", que seria vazio por construção depois do commit).

O gate da outra ponta prova que **não existe uma segunda casca**: nenhum `.jsx` de `aprovacao/` além
dela declara `pt-[96px]`, `max-h-[calc(100vh-140px)]`, `shadow-pub-modal` ou a largura de 560px.

⚠️⚠️ **A SONDA DA LARGURA É ANCORADA, e a armadilha foi MEDIDA:**

| forma | a sonda `(^|[\s'"`])w-\[560px\]` | por quê |
|---|---|---|
| `flex w-[560px] flex-col` | **CASA** (asserção positiva no gate) | é a forma que ela DEVE pegar |
| `max-w-[560px]` | **NÃO casa** (asserção negativa no gate) | ⚠️ `w-[560px]` é **substring** dele, e `max-w-[560px]` existe **legitimamente** em `ToastDaFila.jsx:69` (Fase 187, **NO AR**) |

Um `includes('w-[560px]')` acusaria o toast. É a quinta vez que `includes` morde por prefixo/sufixo
neste projeto, e as **duas direções** estão testadas contra a sonda **de verdade**.
⚠️ **ISCA INVERTIDA, também:** a lista de quem **importa** a casca não pode estar vazia (vazia
significa varredura cega, não *"tudo certo"*), e a sonda tem de achar as **quatro** marcas **DENTRO
da própria casca** — sem esse controle o `deepEqual` passaria com as quatro funções cegas.

## `font-pub` NO CORPO DE CADA MODAL, com a razão

⚠️ **Critério 15 / MOD-05 / REND-05.** Os dois modais declaram
`CLASSES_CORPO_DO_MODAL = 'flex flex-col gap-[14px] font-pub text-pub-txt'`, e a classe está na
**RAIZ** do corpo (não num filho): **é exatamente no limite do portal que a herança quebra**.

A razão está escrita no docblock dos dois, e o gate a cobra por **leitura CRUA** (o docblock é o
artefato):

> *"a casca é compartilhada e está em produção; a fonte é declarada AQUI para o modal continuar
> correto tanto dentro do portal do painel quanto fora dele"*

⚠️ **MEDIDO:** `CascaDeModal.jsx` **não** declara `font-pub` e está **CERTO** — ela é renderizada na
árvore da página e **herda** (ela não é portada: o docblock dela diz *"SEM PORTAL, E O MOTIVO É
MEDIDO"*). **Mas o painel desta fase É portal** (`createPortal` para o `document.body`), e um modal
aberto de dentro dele volta a herdar do `document.body`, que é `font-sans` (Inter).
⚠️ **Acrescentar `font-pub` à casca da 187 seria mudar arquivo em produção para resolver um problema
que é do CHAMADOR** — há asserção nos dois gates provando que ela **continua sem** a classe.
⚠️ E o CSS construído prova que `font-pub` **resolve a Geist**: sem isso a classe poderia existir e
não significar nada.

## A PROFUNDIDADE MEDIDA — o slot `modais` é IRMÃO do `<aside>`, não filho

⚠️⚠️ **Aviso de outra sessão CONFIRMADO, e é a MESMA classe de erro que fez o rodapé ganhar slot
próprio na 188-10.** Medido no protótipo: `:775` (o backdrop do modal) abre **na raiz da página**,
**FORA** do `<aside>` de `:550`-`:723`, com `position:fixed` e `z-index` acima do painel.

```
:550  <aside>                prof 1 → 2
:562  corpo rolável          prof 2 → 3   (flex:1; overflow-y:auto)
:710  </div>                 prof 3 → 2   ← FECHA o corpo
:712  rodapé de 66px         prof 2 → 3   ← IRMÃO do corpo (slot da 188-10)
:723  </aside>               prof 2 → 1
:775  backdrop do MODAL      ← FORA do <aside>, na RAIZ   ← slot `modais` desta wave
```

➡️ `RaizDoPainel` ganhou o **terceiro** slot (`modais`), renderizado **depois de `</aside>`**:

| se o modal fosse… | o que quebra |
|---|---|
| filho do `children` / do slot `pendencias` | seria item do contêiner `overflow-y-auto` de `:562` e **ROLARIA COM O CORPO** |
| filho do `<aside>` | ficaria limitado aos **1140px** do painel em vez de cobrir a tela |

⚠️ **As duas coisas aconteceriam com TODO teste de render passando.** A prova é por **ÁRVORE**
(índice do `</aside>` × índice de uma marca única no modal), **com as duas iscas**: o **rodapé**
continua DENTRO do `<aside>` e as **pendências** continuam dentro do corpo rolável — sem essas duas
pontas a asserção passaria com tudo jogado para fora.
⚠️ **O z-index NÃO foi remapeado:** o modal segue com o `z-[42]` da casca, e a ordem travada continua
`header(30) < backdrop do painel(39) < painel(40) < modal(42) < toast(45)`.
`tests/js/publicador-header-zindex-ordem.test.js` saiu com `numstat` **VAZIO**.

## OS SETE ITENS FIXOS — e a OITAVA confirmação de que a prosa do plano erra

⚠️⚠️ **SÃO SETE FIXOS + N IMAGENS, NÃO OITO.** A prosa do `188-11-PLAN.md` (`:153`) diz *"São 8
fixos + N imagens, e o 8º ALTERNA por `units > 1`"*. **MEDIDO por `sed` nesta wave:**

```
:1181  ord.map((id, i) => ({ label: 'Imagem #' + (i+1) …   ← o .map() das IMAGENS
:1182  return it.concat([
:1183  { label: 'Preço ' + this.modName(o), … }             ← o 1º FIXO
:1189  { label: set.units > 1 ? 'Composição do kit' : 'Categoria', … }  ← o 7º, o que ALTERNA
:1190  ]);                                                   ← FECHA o concat
```

Contagem literal de `{ label:` entre `:1183` e `:1189`: **7**.
➡️ A **`188-FID01.md` §F.1 tem razão** (e o `AJUSTE_ITENS_FIXOS` do serviço também, com o docblock já
declarando *"SÃO SETE, E ISSO FOI MEDIDO POR `sed`"*). **Esta é a oitava confirmação, nesta fase, de
que a prosa de um plano divergiu do FID01.**
⚠️ O gate lê a contagem **do protótipo E do serviço**, nunca da fixture: se um dia virar oito, os
três reprovam juntos.

⚠️⚠️ **E A TELA NÃO TEM A TABELA DE ESCOPOS.** Doze literais do servidor (`Conjunto de fotos`,
`Cadastro do produto`, `Este anúncio`, as três notas de escopo, `Frete do vendedor`,
`Custo do produto`, `Atributos e ficha técnica`, `Composição do kit`, `Categoria`, `Descrição`) são
**PROIBIDOS** na fonte do `.jsx`, **com isca do recorte nas duas direções**: os doze **ESTÃO** no
servidor (asserção lendo `PainelDeRevisaoService.php` e `PubAprovacaoAjuste.php`). Escrevê-los aqui
criaria a **segunda verdade** sobre "o que cada item afeta", e a divergente **moveria ofertas erradas
para `changes`**.

## OS TRÊS ESCOPOS, E O ÂMBAR SÓ NO PRODUTO

| escopo (chave do servidor) | destino | nota | cor do destino |
|---|---|---|---|
| `set` | `Conjunto de fotos (Clássico e Premium)` | `Ajuste no conjunto de fotos compartilhado` | `text-pub-fase1` (`#D4D4D8`) |
| `offer` | `Este anúncio (Clássico)` | `Ajuste específico desta oferta` | `text-pub-fase1` |
| **`product`** | `Cadastro do produto (todas as ofertas)` | `Ajuste no cadastro base: afeta todas as ofertas deste produto` | ⚠️ **`text-pub-warn-txt`** (`#FBBF24`) |

⚠️ **Os TRÊS foram renderizados e as cores comparadas** — um gate que olhasse um só passaria com a
cor FIXA. A mutação *(vi) âmbar em todos os escopos* **MORREU**.
⚠️⚠️ **A CHAVE É `product`, EM INGLÊS, e isso NÃO é descuido:** ela é lida de
`PubAprovacaoAjuste::ESCOPO_PRODUTO` (`app/Models/PubAprovacaoAjuste.php:59`), **em produção** desde
a Fase 186. O `prod` do protótipo é **RECUSADO** pelo servidor de propósito (`ESCOPO_DO_PROTOTIPO` é
mapa de conferência, não chave de dado). ➡️ **A CHAVE viaja, o RÓTULO fica no servidor**, e há
asserção provando que `'prod'` **não** aparece na tela.
⚠️ Sem destino a caixa *"Encaminhar para"* **não é desenhada** (D4), com a isca da outra direção (a
MESMA fixture COM destino desenha).

## A SUGESTÃO CONCATENA — E ACRESCENTA UM PONTO

`:1397` (medido; a §F.1 aponta `:1396`, que é a **abertura** `if (k === 'adjust')`):

```js
(F.text ? F.text + ' ' : '') + t + '.'
```

| entrada | sugestão | saída MEDIDA |
|---|---|---|
| `''` | `Cor diferente da original` | `Cor diferente da original.` |
| `A poltrona está torta.` | `Cor diferente da original` | `A poltrona está torta. Cor diferente da original.` |
| `Recorte ruim.` | `Peça ausente` | `Recorte ruim. Peça ausente.` |
| qualquer | adversa (9 formas) | `null` — nada acontece |

⚠️ **Contraintuitivo e fácil de omitir**, e está declarado no docblock: *"clicar apagou o que eu tinha
escrito"* é o defeito que a pessoa **nunca reporta** — ela só para de usar o chip.
⚠️ **E o CLIQUE é exercido de verdade**, pela árvore de elementos: `renderToStaticMarkup` **não
dispara `onClick`**. A mutação *(v) a sugestão SUBSTITUI* **MORREU**.

## TEXTO VAZIO NÃO ENVIA — AS DUAS PROVAS

`:1193`: `if (!(F.text || '').trim()) return;`

| entrada | botão | clique |
|---|---|---|
| `''` | `disabled=` + `bg-pub-seg` (apagado) | **não** chama `onSubmeter` |
| `'   '` (só espaços) | idem | idem |
| `'\n\t '` | idem | idem |
| `null` / `{}` / `42` | idem | idem |
| `'a poltrona veio torta'` | habilitado + `bg-pub-accent` | **chama**, com `{escopo, item, texto}` |

⚠️ **Só a primeira é cosmética** — a trava real é `REGRA_TEXTO_VAZIO` no servidor (plano 188-06).
⚠️ **`disabled=` por ATRIBUTO, e nunca `/disabled/`** (que casaria a classe `disabled:opacity-40`),
localizado no botão **DE SUBMETER** por **rótulo único** dentro de `botoesDe()`, **com a isca (c)**: o
`"Cancelar"` é provado **HABILITADO** no mesmo render — um gate que lesse o rodapé inteiro por regex
seria satisfeito pelo `disabled=` do botão errado.
⚠️ Teste com `'   '` e não só `''`: um `if (!texto)` passaria o primeiro e **aceitaria** os espaços.
A mutação *(iv) aceita só espaços* **MORREU**.

## A EXCEÇÃO: SÓ OS DOIS FINANCEIROS, COM ISCA POR BLOQUEIO NOS SETE OUTROS

⚠️⚠️ **Critério 11 / BLOQ-08.** A lista é a **FECHADA** de `PubAprovacaoExcecao::LIBERA`
(`resultado_negativo`, `margem_abaixo_do_minimo`), lida do PHP pelo gate.

| bloqueio | o modal abre? |
|---|---|
| `resultado_negativo` | **SIM** (isca da outra direção) |
| `margem_abaixo_do_minimo` | **SIM** (isca da outra direção) |
| `fotos_a_conferir` · `sem_fotos` · `alerta_em_foto_de_ia` · `frete_ausente` · `alteracao_nao_conferida` · `ajuste_em_aberto` · `titulo_longo` | **NÃO** — nem com casca vazia |
| o quase-igual: `resultado_negativo2` · `RESULTADO_NEGATIVO` · `resultado` · `margem` · `''` | **NÃO** |
| ⚠️ as chaves INGLESAS do protótipo: `loss` · `margin` | **NÃO** — o servidor as RECUSA de propósito |
| adversas: `null` `undefined` `42` `{}` `[]` `NaN` `{toString:null}` | **NÃO**, e nada lança |

⚠️ **Lista FECHADA e não lista de proibições**, e a razão está no modelo: *"uma lista de proibições
deixa permitido tudo que ninguém lembrou de proibir"* — e aqui *"permitido a mais"* significa
**publicar um anúncio que ninguém conferiu**, sem desfazer (T-186-17).
⚠️ E há asserção provando que os SETE estão **de fato fora** de `LIBERA`: sem isso a lista do gate
podia estar errada e o caso passaria **afirmando nada**.
A mutação *(vii) a exceção abre para `fotos_a_conferir`* **MORREU**.

## A JUSTIFICATIVA: `>= 10`, MEDIDA EM 9, 10 E 11, COM `trim()`

`:1398` (`ok = (F.reason || '').trim().length >= 10`) e `:1203` (`if (… length < 10) return;`) — os
dois medidos por `sed`. **`:1203` recusando com `< 10` é a prova de que 10 PASSA.**

| entrada | `justificativaValida` |
|---|---|
| `'123456789'` (9) | `false` |
| `'1234567890'` (**10**) | ⚠️ **`true`** |
| `'12345678901'` (11) | `true` |
| `'            ab'` (12 espaços + 2 letras) | `false` — ⚠️ `trim()` ANTES de contar |
| `'   1234567890   '` | `true` — o `trim()` não come caractere de verdade |
| `'          '` (10 espaços) | `false` |
| adversas (7 formas) | `false` |

⚠️ O mínimo é **LIDO** de `PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA` (há asserção comparando), e o
rótulo `Justificativa (mín. 10 caracteres)` é **MONTADO** com a constante — dois literais divergiriam
na primeira edição (há asserção cobrando a forma `${MINIMO_DA_JUSTIFICATIVA}` na fonte).
⚠️ A trava real é `REGRA_JUSTIFICATIVA_CURTA` no servidor; aqui o botão apaga **e** o clique não
dispara. A mutação *(iii) `> 10` em vez de `>= 10`* **MORREU**.

## OS AUTORIZADORES VÊM DO SERVIDOR — e os nomes do protótipo NÃO entram

⚠️ `:1399` traz `[{v:'RG', l:'Ricardo Gomes · gestor da conta'}, {v:'CM', l:'Carla Mendes ·
coordenação'}]`: dois nomes **inventados** com um **cargo inventado**. ⚠️ **Não existe dado de "cargo
na conta"** neste sistema (a pivot `company_users` tem papel por SERVIÇO, não "gestor desta conta
ML"), e escrever um cargo seria a tela **afirmando o que não é** — mesma classe das taxas fictícias
(D8). ➡️ Os seis literais (`Ricardo Gomes`, `Carla Mendes`, `gestor da conta`, `coordenação`,
`'RG'`, `'CM'`) são **PROIBIDOS** na fonte, com asserção.

A lista real vem de `modais.excecao.autorizadores` (plano 188-06), com `descricao` sendo o número
REAL de ofertas da pessoa naquela conta, e o rótulo é `nome · descricao` (medido por igualdade de
LISTA, com `value` e rótulo):

```
[{valor:'41', rotulo:'Marina Lopes · 12 ofertas nesta conta'},
 {valor:'58', rotulo:'Rafael Dias · 1 oferta nesta conta'}]
```

⚠️ **Lista VAZIA (e 9 outras formas adversas) → a tela DIZ isso e NÃO envia** (D4: `<select>` vazio é
um botão sem destino disfarçado). O modal **não** desaparece: o motivo e a justificativa continuam
aparecendo. ⚠️ `id = 0` e negativo são **recusados** — a guarda é inteiro **POSITIVO**, e `id = 0`
passava pela guarda de "inteiro" (é id plausível, e sairia numa rota que não existe). As mutações
*(ix) envia sem autorizador* e *(x) id zero vale* **MORRERAM**.

➡️ **NÃO foi preciso PARAR e devolver:** a lista **existe** no payload do servidor desde o plano
188-06, medida em `PainelDeRevisaoService::autorizadores()`.

## O MOTIVO DA EXCEÇÃO — e a DIVERGÊNCIA do `kindValue`

⚠️ **DIVERGÊNCIA DECLARADA, a favor do produto.** `:1399` monta `kindValue` **no cliente**:

```js
kindValue: F.kind === 'loss'
  ? this.brl(f.result) + ' por venda · margem ' + this.pct(f.margin)
  : 'Margem ' + this.pct(f.margin) + ' · mínimo 12%'
```

E o `12%` ali é **`this.MIN`**, não literal.

| fonte | o que produz |
|---|---|
| protótipo `:1399` | o texto montado **no cliente**, com `brl()`/`pct()` e `MIN` |
| produto | o **TEXTO QUE O SERVIDOR ESCREVEU** — `BloqueiosDaOfertaService.php:200`/`:207`: `'Resultado negativo: '.brl(…).' por venda'` e `'Margem de '.pct(…).' abaixo do mínimo de '…` |

➡️ **A tela LÊ o texto do bloqueio.** Razões: (a) montá-lo aqui seria a **segunda redação do mesmo
fato**; (b) a tela passaria a **formatar dinheiro e percentual**, que é exatamente o que D8/FIN-02
proíbem; (c) o mínimo sai **REAL** de graça. **A fixture do gate usa 17%** de propósito: se a tela
cravasse `12%`, o caso reprovaria — e há asserção de que `12%` **não** aparece na tela.
⚠️ Oito formas de formatação (`toFixed`, `toLocaleString`, `Intl.NumberFormat`, `'R$ '`, `` `R$ ``,
`+ '%'`, `* 100`, `/ 100`) são **PROIBIDAS** na fonte, **cada regex com o seu caso que DEVE casar**.
⚠️ O **RÓTULO** (`Resultado negativo` / `Margem abaixo do mínimo`) é **LIDO** de
`ROTULO_DO_BLOQUEIO` (`vocabularioDoPortao.jsx`, espelho de `PubAprovacaoExcecao::ROTULOS`) — uma
casa só, e há asserção provando que os dois **não** estão cravados na tela.

## A PROSA DE `:804` NÃO VAI PARA A TELA — DIVERGÊNCIA DECLARADA

| onde | o que diz |
|---|---|
| `design_handoff_publicador/Aprovacao.dc.html:804` | *"No protótipo, a exceção é registrada como autorizada pelo responsável escolhido."* |
| `ModalDeExcecao.jsx` (`NOTA`) | *"A exceção fica registrada no histórico desta oferta, com quem autorizou, a data e a justificativa."* |

⚠️ A do protótipo é **fala SOBRE o protótipo**, é jargão, e **D7 proíbe jargão na tela**. ⚠️ **Não é
o protótipo perdendo num rótulo de interface — é prosa de documento que nunca foi rótulo**, e já
estava registrada em `188-FID01.md` §F.2. As **duas pontas** são provadas: a dele **ausente** (na
tela e na fonte), a nossa **presente**. A mutação *(viii) a nota volta a ser a prosa do protótipo*
**MORREU** (com 3 falhas).

## "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO" NÃO EXISTE — gate ANCORADO NO SUJEITO

⚠️ **DADO-11 / decisão 6 do usuário:** a exceção só **REGISTRA** quem liberou. `PubAprovacaoExcecao`
**nasce valendo**.

⚠️⚠️ **E a sonda ANCORA NO SUJEITO, não em palavra solta.** *"Aguardando aprovação"* é vocabulário
**CORRETO** do domínio — `aguardando_aprovacao` é **um dos 8 andamentos** — e casa dezenas de
ocorrências legítimas na área: uma sonda por *"aguardando"* **reprovaria trabalho certo**.

| forma | a sonda |
|---|---|
| `a exceção aguardando o gestor` | **pega** (isca positiva) |
| `Aguardando autorização do responsável` | **pega** |
| `pendente de aprovação do gestor` | **pega** |
| `exceção solicitada, aguarde` | **pega** |
| `aguardando liberação` | **pega** |
| `enviada para aprovação` | **pega** |
| ⚠️ `Aguardando aprovação` · `aguardando_aprovacao` · `A oferta está aguardando aprovação` · `Aprovado — aguardando publicação` | **NÃO pega** (vocabulário LEGÍTIMO) |

⚠️ **Com a cedilha e os acentos:** `aprovad` **não** casa `aprovação`, e *"silêncio que depende de UMA
LETRA é sorte"*. As seis regex usam `(ç|c)[ãa]o`.
⚠️ E o gate roda sobre **dado PREENCHIDO** (há asserção de que o HTML tem mais de 80 caracteres):
gate de ausência sobre render vazio é vazio **por construção**.

## OS LITERAIS, CONGELADOS NO GATE — o conserto da sobrevivente da 188-10

⚠️⚠️ A expectativa de cada literal mora **no gate**, por **igualdade de STRING**, e **não** é lida do
módulo testado. Foi a mutação **SOBREVIVENTE** da wave 188-10: comparar o render com o literal **do
próprio módulo** muda os **dois lados juntos** — tautologia, e a resposta de *"o que precisaria estar
errado no mundo para este gate falhar?"* era **"nada"**.
⚠️ E ela **não depende do protótipo**, que **não é versionado**: um gate que só vivesse lá voltaria a
ser tautológico no dia em que o pacote não estivesse no disco. O protótipo entra como **CRUZAMENTO**.

| literal | congelado | isca do quase-igual que o gate RECUSA |
|---|---|---|
| título / botão (MOD-01) | `Solicitar ajustes` | — |
| `ROTULO_DO_ITEM` | `Item` | — |
| `ROTULO_DO_DESTINO` | `Encaminhar para` | `Encaminhar para:` |
| `ROTULO_DO_COMENTARIO` | `Comentário` | — |
| placeholder | `Descreva o que precisa ser corrigido` | `…corrigido.` |
| `PREFIXO_DA_SUGESTAO` | `+ ` (com espaço) | `+` |
| título (MOD-02) | `Solicitar exceção autorizada` | — |
| botão (MOD-02) | `Registrar exceção autorizada` | `Registrar exceção` (prefixo) |
| `ROTULO_DO_RESPONSAVEL` | `Responsável pela autorização` | — |
| `ROTULO_DA_JUSTIFICATIVA` | `Justificativa (mín. 10 caracteres)` | `(min. …)` sem acento · `caractere` no singular |
| placeholder (MOD-02) | `Ex.: oferta de entrada para ganhar relevância por 15 dias` | — |
| `SEPARADOR_DO_AUTORIZADOR` | ` · ` (U+00B7) | ` - ` |
| `SEM_AUTORIZADOR` | `Nenhuma pessoa desta conta pode autorizar a exceção. Peça acesso ao time do Publicador.` | — |
| `NOTA` | ver a seção da divergência de `:804` | — |

## OS HANDLERS LIGADOS, E OS DOIS GATES ALHEIOS REMEDIDOS

⚠️⚠️ **Dois gates de outras waves reprovaram POR CAUSA DO TRABALHO CERTO, e os dois foram
REMEDIDOS do lado deles — nunca afrouxados, e NENHUMA contagem foi subida:**

### (a) `188-09 coluna-de-preco` — `PainelDeRevisao.jsx` estava em `INTOCAVEIS`

Aquele gate media o `numstat` de `PainelDeRevisao.jsx`. Ele é arquivo de **wave IRMÃ**, está no
`files_modified` dos planos **188-11 e 188-12** (o slot `modais` dos quatro modais nasce lá), e
manter o diff dele ali fazia a sonda **reprovar trabalho correto de outra wave** — exatamente o que o
`colididos` daquele mesmo caso existe para impedir.
➡️ Movido para **`SOMENTE_POR_CONTRATO`** (3 → **4**), **com a prova do contrato acrescentada junto**:
`export function ColunaDoPainel`, `export function escreverNoPainel` e
`CHAVE_DA_VERSAO = 'versao_esperada'`. **Contagem INALTERADA: `48,44`.**

### (b) `188-10 pendencias-e-rodape` — cobrava `onAbrirModal={null}` e `onSolicitarAjustes={null}`

A 188-11 **religou** os dois, então o `null` deixou de ser o estado certo. ⚠️ Manter a cobrança faria
o executor seguinte **DESLIGAR o handler para o gate passar** — o anti-padrão *"gate que reprova pelo
próprio conserto"*.
➡️ As asserções apontam para o estado **MEDIDO** (`onAbrirModal={abrirModalDoPainel}` e o
`onSolicitarAjustes` com a guarda `=== null ? null :`), e o invariante **D4** continua provado por
**PROP DIRETA** no mesmo arquivo (os casos de `hasFix`/`live`, que não dependem da página).
**Contagem INALTERADA: `42,37`.**

⚠️ **O gate do `onSimular={null}` da 188-09 NÃO foi tocado nesta wave** — e isso é declarado: aqueles
handlers (`onSimular`/`onDescartar`/`onSalvar`/`onConferirAlteracao`/`onInformarFrete`) são a
**decisão 1 do briefing** e pertencem ao plano **188-12**. Tentei remedi-lo aqui e o gate **reprovou
com razão** (a página ainda tem `onSimular={null}`); a mudança foi **revertida** e vai no 12.

## ⚠️⚠️ DUAS SONDAS MINHAS NASCERAM ERRADAS — e as duas foram pegas pelo PRÓPRIO gate

### (i) A 14ª ocorrência de "sonda MAIS LARGA que o invariante"

Eu escrevi `assert.doesNotMatch(FONTE_PAGINA, /router\s*\.\s*post\(/)` no gate da 188-10. ⚠️ **A
página TEM um `router.post` legítimo em `:690`: a APROVAÇÃO EM LOTE da Fase 187**, que está **EM
PRODUÇÃO** e manda o seu próprio mapa `versoes` (o mecanismo de concorrência DELE).
➡️ **Consertada na RAIZ**, por ancoragem no comportamento: a sonda coleta as **ROTAS** dos
`router.post` e exige que a única seja `mlb.anuncios.meus.aprovacao.lote`. E ficou escrito no gate
que *"o gate de nenhum `router.post` direto mora no plano 188-12, com LISTA CONSTANTE dos 11
componentes — e a página não está nela"*.

### (ii) A primeira ocorrência na direção ESTREITA nesta fase

Eu cravei `['filtros', 'painelNo']` como o conjunto de slots de NÓ da página — e **reprovei
`proximo`, `rodape` e `tabela`, que estão NO AR desde a Fase 187**.
➡️ **Consertada na RAIZ**: a comparação é contra a **UNIÃO das DUAS listas declaradas no gate da
187** (`SLOTS_DE_NO_DA_187` + `SLOTS_DE_NO_DEPOIS_DA_187`), lida do literal, com sonda não-cega antes.

### (iii) E a trava de ocorrência do script de mutação acusou o PRÓPRIO SCRIPT

A mutação *(vi)* usava a expressão inteira (**3 linhas**) como string ANTES, e o harness **ABORTOU**
com *"a string ANTES aparece 3 vezes (esperado 1)"*. ⚠️ **O defeito era o SCRIPT, não o código:**
`grep` é **orientado a linha**, e um padrão multi-linha passado a `grep -o -F` é tratado como **três
padrões independentes**. ➡️ A mutação passou a ser de **uma linha**, e **MORREU**.

### (iv) E o docblock que descreve o achado de ferramenta FECHOU ELE MESMO

A primeira redação do docblock sobre `lerSemComentarios` escreveu a sequência de **fechamento de
bloco** por extenso, dentro de um bloco — **`SyntaxError: Unexpected token ':'`**, e o arquivo inteiro
deixou de carregar. **É a mesma família do defeito que ele descreve**, e ficou declarado no próprio
comentário.

## ⚠️⚠️ ACHADO DE FERRAMENTA: `lerSemComentarios` DESTRÓI 76% de um gate da 187

**MEDIDO nesta wave, e o gate o pegou pela asserção NÃO-CEGA:**

```
tests/js/publicador-fila-filtros-187.test.js
  cru                      62656 bytes
  depois do helper         15249 bytes   (−76%)
  'ESTADO_LOCAL_DA_PAGINA'  :716 no cru → AUSENTE depois do helper
```

**A causa:** aquele arquivo tem `'date-fns/*'` na lista `external` do esbuild (`:118`). O stripper de
bloco de `tests/js/_fonte.js` (`replace(/\/\*[\s\S]*?\*\//g, '')`) lê aquele `/`+`*` como **abertura
de comentário** e fecha no fechamento do **próximo JSDoc** (`:816`) — comendo 698 linhas.
⚠️ **`_fonte.js` NÃO foi consertado**, e isso é deliberado: ele é usado por **dezenas** de gates **em
produção**, e mexer nele agora mudaria o que **todos** eles medem. ➡️ O gate desta wave lê **aquele
arquivo** por leitura **CRUA sem linha de comentário**, com o helper local declarado e a razão
escrita.
⚠️ **Aviso para as waves 12 e 13:** todo gate desta fase que usa `external: [… 'date-fns/*',
'@radix-ui/*' …]` tem o mesmo `/`+`*` na própria fonte. **Gate que leia a SI MESMO com
`lerSemComentarios` perde tudo depois daquela linha.** Os gates desta wave leem a si mesmos por
`readFileSync` cru — de propósito.

## A TABELA FID-01 — medida por SCRIPT

⚠️ **COMO MEDI: SCRIPT.** `python -I` lendo o protótipo em array e conferindo **cada linha citada por
marca semântica**, mais uma **varredura independente por propriedade** (`cursor:pointer`,
`white-space:nowrap`, `text-overflow:ellipsis`) em faixa. ⚠️ **O DIRETÓRIO:**
`find . -maxdepth 3 -name Aprovacao.dc.html` devolve **só**
`./design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, `sha256 68624883ce35547e…`).

### `cursor` / `white-space` / `text-overflow` em COLUNAS PRÓPRIAS

| elemento | linha | `cursor` | `white-space` | `text-overflow` |
|---|---|---|---|---|
| MOD-01 `<sc-if>` | `:782` | — | — | — |
| MOD-01 `<label>` Item | `:783` | — | — | — |
| MOD-01 `<select>` | `:784` | — | — | — |
| MOD-01 caixa "Encaminhar para" | `:788` | — | — | — |
| MOD-01 `<label>` Comentário | `:791` | — | — | — |
| MOD-01 `<textarea>` | `:792` | — | — | — |
| MOD-01 wrapper + **chip** | `:794` | **`pointer`** | **`nowrap`** | — |
| MOD-02 `<sc-if>` | `:796` | — | — | — |
| MOD-02 caixa VERMELHA | `:797` | — | — | — |
| MOD-02 `<label>` Responsável | `:798` | — | — | — |
| MOD-02 `<select>` | `:799` | — | — | — |
| MOD-02 `<label>` Justificativa | `:801` | — | — | — |
| MOD-02 `<textarea>` | `:802` | — | — | — |
| MOD-02 `</label>` | `:803` | — | — | — |
| MOD-02 nota (prosa) | `:804` | — | — | — |
| casca backdrop | `:775` | — | — | — |
| casca caixa 560px | `:776` | — | — | — |
| casca topo | `:777` | — | — | — |
| casca wrapper título/sub | `:778` | — | — | — |
| casca × | `:779` | **`pointer`** | **`nowrap`** | — |
| casca corpo | `:781` | — | — | — |
| casca rodapé | `:888` | — | — | — |
| casca "Cancelar" | `:889` | **`pointer`** | **`nowrap`** | — |
| casca submeter | `:890` | **`pointer`** | **`nowrap`** | — |

⚠️ **`text-overflow:ellipsis` não existe em NENHUM dos 24 elementos** — e a varredura não está cega:
há asserção de que ela **acha** `cursor:pointer` em `:779` e `white-space:nowrap` em `:794`. Sem esse
controle as listas vazias seriam vazias **por construção**.
⚠️ **E `white-space:nowrap` é a PRIMEIRA propriedade do `<button>` do chip de `:794`** — exatamente a
posição em que a Fase 187 o perdeu **três** vezes. Há asserção na posição.

### Os 19 ponteiros do view-model, RECONFIRMADOS um por um

Todos **CONFIRMADOS** por marca semântica: `:1048` · `:1179` · `:1181` · `:1182` · `:1183` · `:1189`
· `:1190` · `:1193` · `:1203` · `:1395` · `:1396` · `:1397` · `:1398` · `:1399` · `:1400` · `:1401`
· `:1402` · `:1403` · `:1404`.

### ⚠️⚠️ ONDE O FID01 OU O PLANO DIVERGIAM — quatro, e todos MEDIDOS

| o que o documento diz | real (medido) | o que está DE FATO na linha citada | classe |
|---|---|---|---|
| `188-11-PLAN.md:153` — *"São **8 fixos** + N imagens, e o **8º** ALTERNA"* | **SETE**, em `:1183`-`:1189` | `:1181` é o `.map()` das imagens; `:1190` fecha o `concat` | **VALOR** |
| §F.1 — `destColor`/`destNote` em **`:1396`** | **`:1397`** | `:1396` é a abertura `if (k === 'adjust') { … }` | REFERÊNCIA (+1) |
| §F.1 — a concatenação da sugestão em **`:1396`** | **`:1397`** | idem acima | REFERÊNCIA (+1) |
| ⚠️ `188-12-PLAN.md` `<interfaces>` + §F.4 — `sub`, os chips `'; '` e o `btnBg: '#EF4444'` do reject em **`:1404`** | **`:1403`** | **`:1404` é `if (k === 'regen') { … }`** — assunto da **Fase 192** | REFERÊNCIA (−1) |

⚠️ A última é **ACHADO NOVO desta wave** e já está medido para o plano 12 (ver a §P do FID01, que
esta wave estende). ⚠️ E ela repete o padrão que a §J descreveu **oito** vezes: `:1404` é
**legítimo e load-bearing** (é o ramo `regen`), então quem lê a §F.4 sem medir acha que está no lugar
certo.
⚠️ **E a §F.3 também erra duas vezes** (as 3 origens em `:1403` e o `previewColor` em `:1402`),
exatamente como a wave 188-06 mediu — **reconfirmado aqui**: `:1403` é o ramo `reject` e `:1402` é o
`> 2` do MOD-04. **Os dois corretos são `:1401`.**

## AS LISTAS CONSTANTES — declaradas, e as que NÃO mexi

| lista | arquivo | esta wave |
|---|---|---|
| `ARQUIVOS_NOVOS` | `tests/js/publicador-vocabulario-novo.test.js:45` | ⚠️ **+2** (`ModalDeAjuste.jsx`, `ModalDeExcecao.jsx`), cada um na **MESMA tarefa que o cria** |
| `JSX_DEPOIS_DA_187` | `tests/js/publicador-fila-fechamento-187.test.js:119` | ⚠️ **+2** — **a SEGUNDA escrituração, e isso é DELIBERADO** (o conserto da família **RECUSOU** criar uma terceira) |
| `ESTADO_LOCAL_DA_PAGINA` | `tests/js/publicador-fila-filtros-187.test.js:716` | ⚠️ **+1** (`modalDoPainel`) — **desvio DECLARADO**, ver abaixo |
| `SLOTS_DE_NO_DEPOIS_DA_187` (TRÊS pontas: `publicador-fila-cabecalho-187`, `publicador-fila-proximo-187`, `tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php`) | — | **NÃO mexi**: esta wave **não abre `isValidElement(` novo NA PÁGINA**. O slot `modais` nasce em `PainelDeRevisao.jsx` e viaja por **expressão** (`modais={noModaisDoPainel}`). ⚠️ **Há asserção cobrando**, contra a UNIÃO das duas listas |
| `QUEM_USA_SHADOW_PUB_PAINEL` (`publicador-fila-tokens-187.test.js`) | — | **NÃO mexi**: nenhum `.jsx` desta wave usa `shadow-pub-painel`. ⚠️ A sombra dos modais é `shadow-pub-modal`, e ela vive **DENTRO** de `CascaDeModal.jsx`, que esta wave **importa** e **não edita** (`numstat` ZERO, há asserção) |
| `LIBERADOS_DEPOIS_DA_187` | — | **NÃO mexi**: **NENHUM tamanho novo**. Os `text-[Npx]` escritos são `11.5 · 12.5 · 13`, e os três já estavam na lista. O GATE-03 mede isso arquivo por arquivo |
| `VARRIDOS_DECLARADOS` | — | **NÃO mexi**: nenhum `.jsx` novo em `publicacoes/`, `identidade/` ou `visaogeral/` |

⚠️ **Por que `ESTADO_LOCAL_DA_PAGINA` MUDOU, e por que é um `useState` SÓ:** os modais desta fase são
**PUROS e CONTROLADOS** (o contrato de `SimulacaoDePreco.jsx`, que não guarda estado nenhum), então
alguém precisa ser o dono dos campos — e aquela lista é de **NOMES**: um `useState` por campo seria
cinco entradas para um gesto só. ⚠️ **"O modal está aberto" é GESTO, não DADO, e NÃO vai para a
URL**: pôr isso na querystring faria um link compartilhado abrir um modal de exceção **na cara de
outra pessoa**, numa tela em que **aprovar JÁ PUBLICA e não há desfazer**. É a MESMA decisão já tomada
para `modalDoLoteAberto` e para `fotoAberta`; a **OFERTA**, que é dado, continua na URL (`?oferta=`).
Há asserção de que `modal` **não** entra no montador de querystring.
⚠️ **GATE-03 verde:** `vocabulario-tamanhos` medido em **653** (tarefa 1) e **660** (tarefa 2) — a
soma fecha (646 + 7 + 7), e a forma é **`>=`** porque a contagem **cresce por construção** (7
sub-testes por arquivo varrido).

## A BIJEÇÃO, O MANIFESTO E O HARNESS

⚠️ **DESVIO DECLARADO: DOIS arquivos de gate, um por id.** O `188-11-PLAN.md` lista **um** arquivo e
**dois** ids de `<verify>`. No modo `js` o harness roda o **ARQUIVO INTEIRO**, e dois ids sobre o
mesmo arquivo dariam a **MESMA contagem duas vezes, com nenhum medindo a sua metade** — gate
decorativo **por construção** (medido na 187-08). Pior: a contagem do id da tarefa 1 ficaria **errada**
no instante em que a tarefa 2 acrescentasse testes.

| id | arquivo | contagem MEDIDA |
|---|---|---|
| `modal-de-ajuste` | `tests/js/publicador-painel-modais-a-188.test.js` (nome declarado pelo plano) | `tests=27,23` |
| `modal-de-excecao` | `tests/js/publicador-painel-modal-excecao-188.test.js` (**nome novo, declarado**) | `tests=23,20` |
| `vocabulario-tamanhos` | `tests/js/publicador-vocabulario-novo.test.js` (**linha própria**, UM arquivo) | `tests=>=660` |

⚠️ **Os DOIS REGIMES foram MEDIDOS pelas DUAS pontas**, não deduzidos: o `mtime` da fonte adiantado
por `touch` e devolvido por `touch -d`, com o original capturado por `stat -c %y` **ANTES**.
`cp -p` preserva o `mtime`; o `cp` de ida não, e é por isso que `npm run build` vem **DEPOIS** da
mutação.
⚠️ **BIJEÇÃO nos DOIS sentidos, por script, nos dois gates:** zero id órfão no manifesto, zero id de
`<verify>` sem declaração, zero id repetido, **um id por arquivo de gate** (asserção coletando os
infratores), e **todo** nome de teste terminando em `188_11`.
⚠️ **Nenhum id com mais de TRÊS métodos** (não há id `php` neste manifesto — e **há asserção
cobrando a ausência**, declarada em vez de presumida: o `--filter` do PHPUnit, que é regex **não
ancorado**, só existe no modo `php`, então a colisão de **homônimo não tem onde acontecer** aqui).
⚠️ **O EXPANSOR DE ALTERNÂNCIA está testado** contra `test_(a|b)_188_11` — o furo MEDIDO pela wave
188-05, em que `/test_[A-Za-z0-9_]+/` **não casa** a forma de alternância e deu `SEM_ID=14`
**acusando trabalho CORRETO**.

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

### Os ids de OUTRAS waves, re-rodados

| id | fase | contagem | leitura |
|---|---|---|---|
| `casca-do-painel` | 188-07 | PASSOU | inalterado |
| `coluna-de-fotos` | 188-08 | PASSOU | inalterado |
| `coluna-de-preco` | 188-09 | PASSOU | `48,44` — **inalterado** depois do remédio |
| `pendencias-e-rodape` | 188-10 | PASSOU | `42,37` — **inalterado** depois do remédio |
| `fechamento-arquivos-e-manifestos` | 187-11 | PASSOU | `6` — as listas moram DENTRO de um `test()` |
| `gate-rename` | 186-01 | PASSOU | `23` — lê `JSX_DEPOIS_DA_187` como INVARIANTE |
| `territorio-e-fid` | 187-11 | PASSOU | — |
| `filtros-na-url` · `territorio-filtros` | 187-06 | PASSOU | — |
| `pagina-e-contagem` | 187-04 | PASSOU | — |
| `cabecalho-da-fila` | 187-04 | PASSOU | — |
| `proximo-render` | 187-05 | PASSOU | — |
| `territorio-tabela` · `territorio-linha` · `territorio-lote` · `selecao-na-url` | 187-07/08/09 | PASSOU | — |

⚠️ **Nenhum número foi SUBIDO para calar gate, e nenhum foi rebaixado para contagem.**

## A MUTAÇÃO — `mutar-188-11.sh`, 10 mutações, ZERO sobrevivente

⚠️ **O trabalho estava COMMITADO antes** (conferido por `sha256` contra `git show HEAD:<arquivo>`,
com `tr -d '\r'` nos **dois** lados). ⚠️ **Nome que carrega o plano** (`mutar-188-11.sh`,
`copia-188-11-*.jsx`) porque o scratchpad é **COMPARTILHADO** entre subagentes paralelos — na Fase
187 o `mutar.sh` de uma wave foi **SOBRESCRITO** pela outra no meio do trabalho. `sha256` do script:
`4eae7e9ad37f5630865d980d…`. ⚠️ **`git checkout` NUNCA**; restauração **DA CÓPIA** em `trap EXIT`.

```
PRISTINO ajuste : # tests 27 # pass 27 # fail 0 # skipped 0
PRISTINO excecao: # tests 23 # pass 23 # fail 0 # skipped 0

[i-segunda-casca]           MORREU (linha 136) — # tests 23 # fail 1
[ii-sem-font-pub]           MORREU (linha 136) — # tests 23 # fail 1
[iii-maior-que-10]          MORREU (linha 189) — # tests 20 # fail 1
[iv-aceita-espacos]         MORREU (linha 209) — # tests 23 # fail 1
[v-sugestao-substitui]      MORREU (linha 223) — # tests 23 # fail 1
[vi-ambar-em-todos]         MORREU (linha 258) — # tests 23 # fail 1
[vii-abre-para-fotos]       MORREU (linha 134) — # tests 20 # fail 1
[viii-prosa-do-prototipo]   MORREU (linha 123) — # tests 20 # fail 3
[ix-envia-sem-autorizador]  MORREU (linha 365) — # tests 20 # fail 1
[x-id-zero-vale]            MORREU (linha 206) — # tests 20 # fail 1

RESTAURADO OK: resources/js/Components/Mlb/Publicador/aprovacao/ModalDeAjuste.jsx
RESTAURADO OK: resources/js/Components/Mlb/Publicador/aprovacao/ModalDeExcecao.jsx
```

⚠️ **O `# tests` do MUTADO é IGUAL ao regime mutado do manifesto** nos dois gates (`23` no ajuste,
`20` na exceção) — e **foi assim que o manifesto ganhou os dois regimes**, não ao contrário.
⚠️ A ocorrência foi **travada em 1 ANTES** por **string exata** (por `grep -o`, que conta
**ocorrências**; `grep -c` contaria **LINHAS**), a **linha foi conferida DEPOIS**, e a aplicação foi
provada por **divergência de `sha256`** (NO-OP abortaria).

## A TELA PRETA, O ROLLUP E O TERRITÓRIO

⚠️ **Render adverso REAL** (esbuild + `react-dom/server`) com **18 formas** por campo (`null`,
`undefined`, `0`, `1`, `-1`, `NaN`, `Infinity`, `''`, `'   '`, `'texto'`, `true`, `false`, `{}`,
`[]`, `[{}]`, `{toString: null}`, `{valueOf: null}`, função) em **todos** os campos dos dois modais e
de cada item/autorizador, a **prop inteira**, os handlers, **e TAMBÉM NO CLIQUE** (o chip, os dois
`onChange` e o `onSubmeter`), com `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`.
⚠️ **NUNCA `String()` nem `JSON.stringify()` na mensagem de asserção** — eles **lançam/mentem** com
`{toString: null}` e já mataram 5 blocos **DENTRO da mensagem** no plano 180-05. ⚠️ E `String(` está
**PROIBIDO** na fonte dos dois modais (há gate da 187-04 proibindo na página; aqui o veto é do
recorte deste plano). ⚠️ Por isso os campos controlados usam `value`+`onChange` **ou**
`defaultValue` — nunca `String(valor)` para montar o `value`.
⚠️ **O filho OBJETO COMUM passando pela casca** é provado nos dois gates: a casca **filtra filho a
filho** (conserto medido na 187-09 — *"Objects are not valid as a React child"*), e **prop de NÓ
chegando objeto comum MATA a página**.
⚠️ **ROLLUP:** as flags vivem **DENTRO** do callback dos `.map()` (itens, sugestões, autorizadores),
e o **bundle DE VERDADE** renderiza os `<option>` e os chips — o Rollup elimina variável de escopo do
componente lida dentro de `.map()`, **com todo teste de render passando no módulo não empacotado**.
⚠️ **TERRITÓRIO:** 15 (MOD-01) e 16 (MOD-02) formas vetadas — `localStorage`, "Restaurar dados de
exemplo", `String(`, `JSON.stringify(`, hex cru (TOK-15), FID-03 (`text-sm`/`xs`/`base`/`lg`/`xl`),
FID-04 (`ecf-*`, `font-display`, `border-white/[0.08]`), FID-05 (breakpoint), a Fase **189**
(`Ampliar`), a Fase **192** (`Gerar novamente com IA`), o segundo passo da decisão 1 (`confirmar
publica|segundo passo|cancelar aprova|desfazer`), *"lucro líquido"*, `duration-[Nms]` e `new Date(`
(COL-06) — **cada regex com o seu caso que DEVE casar**, porque regex que não casa o próprio caso é
**gate cego**. ⚠️ Recorte à **lista CONSTANTE** deste plano: varrer `app/` **barrou um deploy** na
Fase 186 (`lucro_liquido` é campo **legítimo** do Portal).
⚠️ **`rounded-[8px]` é valor ARBITRÁRIO de propósito:** 8px **não existe** na escala `rounded-pub-*`
(16 · 14 · 12 · 10 · 9 · 7 · 6 · 4), e *"usar o token parecido que já existe"* é justificativa
**VETADA** (FID-02). A classe **compila** (provado no CSS construído).
⚠️ **T-188-SC:** `package.json` e `package-lock.json` com `numstat` **VAZIO** — este plano **não
instala nada**.
⚠️ **INTOCÁVEIS com `numstat` VAZIO:** `CascaDeModal.jsx`, `ModalDeLote.jsx`, `ToastDaFila.jsx`,
`vocabularioDoPortao.jsx`, `andamentoDoPortao.jsx`, `SeloDeAndamentoInterno.jsx`, `BarraDeMargem.jsx`,
`publicador-header-zindex-ordem.test.js`, `estrutura-meus-anuncios.test.js`. ⚠️ **`AppLayout.jsx` e
`PublicadorHeader.jsx` intocados.** ⚠️ **`.planning/ROADMAP.md`, `STATE.md` e `REQUIREMENTS-v27.md`
intocados** (GATE-07).

## TERRITÓRIO — por ESCOPO DO ASSUNTO

⚠️ **A prova é por ESCOPO DO ASSUNTO `(188-11)`, nunca por RANGE** — a 187-11 mediu 111 commits no
range, **43 da sessão paralela**, e o diff cru **acusava 12 dos 60 alvos vetados**. ⚠️ **E o AUTOR
NÃO discrimina:** as três máquinas desta árvore commitam com o MESMO `user.name` (`MB.ECF-100376`).

```
7fe1f7a3  feat(188-11): o modal de ajustes …
9f5b05d7  feat(188-11): a excecao autorizada …
```

⚠️ **ANTI-FANTASMA (`test -e`) em CADA caminho citado pelos dois gates** — são **dez** fantasmas nesta
milestone, **três** vindos de briefing do orquestrador, e o décimo foi escrito por quem tinha acabado
de ler o aviso. Há asserção extra provando que
`app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` **continua não existindo** (o real é
`app/Services/Publicador/Fila/ResumoRapidoService.php`, **sem** o `Aprovacao/`).

## O ROTEIRO DA CONTA 459 — o trecho desta wave

⚠️ **Nada aqui foi visto numa tela.** Para o usuário conferir, quando a fila tiver oferta
(Fase 194):

1. abrir uma oferta **ao vivo** com **prejuízo** ou **margem abaixo do mínimo** e clicar no botão de
   correção **"Solicitar exceção"** da pendência → o modal abre com a **caixa vermelha** trazendo o
   rótulo e **o texto que o servidor escreveu** (com o mínimo REAL da conta, não `12%`);
2. no mesmo modal, digitar **9** caracteres → o botão "Registrar exceção autorizada" fica **apagado**;
   digitar o **10º** → habilita;
3. clicar **"Solicitar ajustes"** no rodapé de 66px → o modal de ajuste abre com o `<select>` trazendo
   **uma entrada por foto** e depois os **sete** itens fixos, nessa ordem;
4. trocar o item para **"Custo do produto"** → a caixa "Encaminhar para" passa a dizer
   *"Cadastro do produto (todas as ofertas)"* **em âmbar**, com a nota
   *"Ajuste no cadastro base: afeta todas as ofertas deste produto"*;
5. clicar num chip de sugestão **duas vezes seguidas** → o comentário **acumula**, com ponto no fim de
   cada frase (e **não** apaga o que já estava escrito);
6. apertar **`Esc`** com o modal aberto → fecha **o modal**, e o painel **continua aberto**.

## Known Stubs

Nenhum. Os dois modais estão ligados ponta a ponta (payload do servidor → tela → rota de escrita pelo
helper único), e nenhum campo nasce com valor fixo.

⚠️ **O que ainda é `null` DE PROPÓSITO, e tem dono:**

| prop | onde | dono |
|---|---|---|
| `onConflito` (nas quatro chamadas) | `Aprovacao.jsx` | **188-12, tarefa 3** — o AVISO na tela é dele. O helper registra o tratamento de erro de todo jeito e **nada é engolido** |
| `onSimular` · `onDescartar` · `onSalvar` · `onConferirAlteracao` · `onInformarFrete` | `ColunaDePreco` | **188-12** (decisão 1 do briefing) |
| `onReprovar` | `ColunaDeFotos` | **188-12, tarefa 2** |

## Threat Flags

Nenhuma superfície nova. As duas escritas deste plano usam rotas que **já existiam** (plano 188-06),
com `versao_esperada` sempre no corpo pelo helper único, e a lista de autorizadores é **filtrada pela
consulta** no servidor (`abort_unless(in_array(...), 404)`) — não há caminho em que um id alheio
chegue ao serviço de escrita.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] DOIS arquivos de gate, um por id**
- **Found during:** Tarefa 1
- **Issue:** o plano lista **um** arquivo de gate e **dois** ids de `<verify>`. No modo `js` o harness
  roda o ARQUIVO INTEIRO, e dois ids sobre o mesmo arquivo dariam a MESMA contagem duas vezes.
- **Fix:** um arquivo por tarefa, declarado no cabeçalho de `contagens-188-11.txt`, com asserção nos
  dois gates provando **um id por arquivo**.
- **Files:** `tests/js/publicador-painel-modal-excecao-188.test.js` (nome novo)

**2. [Rule 2 - Alcançabilidade] a fiação na página `Aprovacao.jsx`**
- **Found during:** Tarefa 2
- **Issue:** **nenhuma** wave de 11 a 13 tem a página no `files_modified`, e sem o fio os modais
  existiriam no disco e **não apareceriam na tela**.
- **Fix:** o fio entra aqui, DECLARADO, pelo MESMO precedente das waves 188-09 e 188-10. Nada mais da
  página foi tocado.
- **Files:** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`

**3. [Rule 1 - Gate alheio] `PainelDeRevisao.jsx` em `INTOCAVEIS` da 188-09**
- **Fix:** movido para `SOMENTE_POR_CONTRATO` (3 → 4), **com a prova do contrato junto**. Contagem
  `48,44` INALTERADA.

**4. [Rule 1 - Gate alheio] a 188-10 cobrava `onAbrirModal={null}`/`onSolicitarAjustes={null}`**
- **Fix:** asserções remedidas para o estado MEDIDO; o invariante D4 segue provado por **prop
  direta** no mesmo arquivo. Contagem `42,37` INALTERADA.

**5. [Rule 1 - Sonda própria] duas sondas minhas nasceram erradas**
- **Fix:** a de `router.post` sobre a página (mais LARGA que o invariante — a página tem o `post` do
  LOTE da 187) e a de slots de NÓ (mais ESTREITA — esquecia `proximo`/`rodape`/`tabela`), as duas
  consertadas na RAIZ. Ver a seção própria.

**6. [Rule 3 - Ferramenta] `lerSemComentarios` destrói o gate de filtros da 187**
- **Fix:** leitura CRUA sem linha de comentário, só para aquele arquivo, com a razão escrita.
  `_fonte.js` **não** foi tocado (dezenas de gates em produção dependem do que ele mede).

### Auth Gates

Nenhum.

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/aprovacao/ModalDeAjuste.jsx` — **FOUND**
- `resources/js/Components/Mlb/Publicador/aprovacao/ModalDeExcecao.jsx` — **FOUND**
- `tests/js/publicador-painel-modais-a-188.test.js` — **FOUND**
- `tests/js/publicador-painel-modal-excecao-188.test.js` — **FOUND**
- `tests/gates/contagens-188-11.txt` — **FOUND**
- commit `7fe1f7a3` — **FOUND**
- commit `9f5b05d7` — **FOUND**
- `sh tests/gates/verificar-testes.sh 188-11 modal-de-ajuste` — **PASSOU** (27)
- `sh tests/gates/verificar-testes.sh 188-11 modal-de-excecao` — **PASSOU** (23)
- `sh tests/gates/verificar-testes.sh 188-11 vocabulario-tamanhos` — **PASSOU** (660)
