---
quick_id: 261010-hiq
slug: fechar-os-dois-furos-do-publicador-kit-0
type: quick
date: 2026-10-10
status: concluido
commits:
  - 90890f31
  - e52025e3
  - 35863677
files_modified:
  - app/Services/Publicador/VinculoDeKitService.php
  - app/Models/PubProduto.php
  - app/Services/Publicador/CriarFaseService.php
  - app/Services/Publicador/FamiliaDeFasesService.php
  - resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx
  - resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx
  - tests/Feature/Publicador/CompostoDoPlanejamentoTest.php
  - tests/Feature/Publicador/VinculoDeKitTest.php
  - tests/Feature/Publicador/TelaDoProdutoTest.php
  - tests/Feature/Publicador/Concerns/CenarioPlanejamentoDaFase.php
  - tests/Unit/Publicador/CriarFaseServiceTest.php
  - tests/js/publicador-produtos-fases.test.js
  - tests/js/publicador-produtos-layout.test.js
---

# Fechar os dois furos do Publicador (Fases 172-175)

Dois furos de **coerência do que já existe** -- nenhuma funcionalidade nova. O
primeiro era uma porta aberta no servidor; o segundo tinha metade no servidor e
metade na tela, e consertar só uma metade teria piorado o problema.

---

## ⚠️ `CriarFaseServiceTest:190` era o bug capturado num teste existente

**Esta seção existe à parte de propósito: é a prova de que o conserto era
necessário, e ela desaparece se ficar diluída numa lista de "asserções
ajustadas".**

O teste `tests/Unit/Publicador/CriarFaseServiceTest.php:190`
(`test_kit_nasce_com_as_ancoras_do_base_e_a_proxima_fase`) criava um **Kit 3** e
afirmava, **verde e em produção**:

    $this->assertSame(2, $kit->fase, 'família só com a Fase 1: o kit é a Fase 2');

Ou seja: **o teste afirmava que um Kit 3 é a Fase 2.** Não era um teste
faltando -- era um teste que *documentava o bug como se fosse a regra*, com uma
mensagem explicando por quê. A segunda asserção do mesmo teste criava um **Kit 4**
e afirmava `fase === 3`, pelo mesmo motivo.

O teste foi renomeado para `test_kit_nasce_com_as_ancoras_do_base_e_a_fase_da_quantidade`
e agora afirma `3` e `4` -- os números que o cartão (`rotuloFase`) e a Visão geral
(`bucketDaFase`) já usavam para o mesmo produto.

---

## Furo 1 -- a recusa KIT-06 faltava no vínculo

`CriarFaseService::recusarComposto()` já recusava **criar fase** a partir de um
composto do Planejamento (Combo/Kit/Combit vindo do Portal), mas o endpoint de
**vincular** ainda aceitava um composto como **BASE**.

A sugestão automática nunca propõe isso, mas `base_id` é o **único id de entidade
que vem do corpo da requisição** nesta fase (D-13, documentado no próprio
docblock do `VinculoDeKitService`): **esconder não é impedir**.

### Onde a recusa entrou

Como a **PRIMEIRA** do `vincular()` -- antes do VINC-01 e, principalmente, antes
de `$base->familia()` e de qualquer escrita. Espelha a ordem que o
`CriarFaseService` já usa (lá o `recusarComposto()` roda antes do KIT-01). A
ordem relativa de VINC-01..VINC-06 entre si não mudou.

### Por que reusa `tipoComposto` / `motivoKit06` em vez de duplicar a regra

Porque **não é uma regra nova**: é a MESMA regra aplicada no outro caminho. Por
isso:

- o código lançado é **`KIT-06`**, não um `VINC-10` inventado;
- a detecção é `PlanejamentoDaFaseService::tipoComposto($base)` -- a lista de
  tipos não foi duplicada;
- a mensagem é `PlanejamentoDaFaseService::motivoKit06($tipo)` -- nenhum texto
  novo foi escrito, e o teste usa `assertSame` contra essa chamada (não
  `assertStringContainsString`), justamente para que a mensagem continue vindo
  de lá;
- **nenhum `use` novo** entrou no arquivo: `PlanejamentoDaFaseService` está no
  mesmo namespace (`App\Services\Publicador`).

O docblock da classe ganhou a linha do KIT-06 **no topo** da tabela de recusas
(espelhando a ordem de execução) e o `@throws` virou `KIT-06, VINC-01..VINC-06`.

### Por que ela vale SÓ para a base, e a pergunta do outro dev respondida por teste

KIT-06 afirma que o composto não é a **base** de uma família. Ele continua
podendo ser o produto **vinculado** (o kit). A recusa do outro lado **não existe e
não podia ser inventada** -- era exatamente a pergunta do outro dev na coordenação.

`test_composto_do_planejamento_continua_aceito_como_o_kit_vinculado` prova isso:
vincular um Combo do Planejamento a um grupo Simples **passa**, grava
`produto_base_id`, `quantidade_kit = 2`, `fase = 2`, e depois
`PlanejamentoDaFaseService::tipoComposto()` do combo devolve `null` -- ele virou
fase da família e deixa de ser lido como composto. Este teste já passava antes do
GREEN, e é o invariante que o conserto não podia quebrar.

### Os quatro testes (RED medido)

Rodada RED em `CompostoDoPlanejamentoTest`: **12 testes, 3 falhas** -- as três
que provam o furo, com o quarto (composto como kit) já verde:

| Teste | Prova |
|---|---|
| `test_vincular_a_um_composto_como_base_e_recusado_com_kit06_nos_tres_tipos` | serviço recusa `combo`/`kit`/`combit` com mensagem idêntica a `motivoKit06` |
| `test_vinculo_recusado_no_composto_nao_grava_nada` | `produto_base_id` NULL, `quantidade_kit` 1, zero kits no banco |
| `test_endpoint_do_vinculo_recusa_o_composto_como_base_com_422_e_a_regra` | PUT no endpoint do vínculo devolve 422 com `regra: KIT-06` |
| `test_composto_do_planejamento_continua_aceito_como_o_kit_vinculado` | o outro lado da regra NÃO existe |

---

## Furo 2 no servidor -- a fase deixou de ser cronológica

`PubProduto::proximaFase(array $fases)` devolvia `max($fases) + 1`: a ordem de
**criação**. O resto do módulo nunca leu `fase` assim, e isso está **medido no
código, não deduzido**:

- `ProgramasPublicadorService::bucketDaFase()` manda `fase >= 3` para
  `fase3_mais` e trata `fase 2` como o kit;
- `PainelVisaoGeralService` traz o comentário **literal**: *"Rotulado kits, nunca
  Fase 2: `quantidade_kit >= 2` inclui o kit de 3, que é Fase 3"*.

Como a quantidade é editável no painel "Criar Fase N", o bug era alcançável com
um clique: um **Kit 5** criado como primeiro kit da família nascia "Fase 2" -- o
cartão dizia "Kit 5" e a Visão geral contava o **mesmo produto** no bucket Fase 2.
E numa família com Kit 2 + Kit 4, `proximaQuantidade` sugeria 3 enquanto
`proximaFase` dava 4: o Kit 3 nascia "Fase 4" e, como `familia()` ordena por
`fase`, aparecia **depois** do Kit 4.

**Decisão do usuário (10/10/2026) que autoriza:** *"É metodologia e também ordem,
temos que fazer seguindo a ordem de Fase 1, Fase 2 e assim por diante."*

### A derivação nova

`PubProduto::faseDaQuantidade(int $quantidadeKit): int` = `max(1, $quantidadeKit)`.
Pura, no estilo do vizinho `proximaQuantidade`. O docblock dela registra as duas
provas medidas acima e a decisão do usuário -- porque "por que deixou de ser
cronológico" não é dedutível de uma linha de `max()`.

### Os três chamadores trocados

| Arquivo | Antes | Depois |
|---|---|---|
| `CriarFaseService::criarProdutoDoKit()` | `proximaFase($fasesDaFamilia)` | `faseDaQuantidade($quantidade)` |
| `VinculoDeKitService::vincular()` | `proximaFase($familia->pluck('fase')->all())` | `faseDaQuantidade($quantidade)` (já validado `>= 2` pelo VINC-03) |
| `FamiliaDeFasesService::proximaFase()` (o `numero` do botão) | `proximaFase($familia->pluck('fase')->all())` | `faseDaQuantidade($quantidadeSugerida)` |

No `FamiliaDeFasesService` a `quantidade_sugerida` foi extraída para uma variável
local **antes** do `return` e reusada nas duas chaves. Nada mais do método mudou
de valor nem de ordem de avaliação (`$composto`, `$habilitado`, `$doPlanejamento`,
`quantidades_do_planejamento` e o `match` do motivo são do outro dev). O nome do
método privado `proximaFase()` ficou como estava -- o docblock de
`MlbPublicadorFaseController:65` o cita e continua correto.

### Por que `proximaFase()` foi REMOVIDA e não deprecada

Deixar as duas conviverem convida a usar a errada de novo. Removida, quem for
esquecido nem compila -- e foi exatamente isso que apontou o chamador escondido em
`tests/Feature/Publicador/Concerns/CenarioPlanejamentoDaFase.php:157`
(`kitAntigo()`), trocado por `PubProduto::faseDaQuantidade($n)`.

### Só o PARÂMETRO saiu do CriarFaseService; a variável `$familia` ficou

Saíram a linha `@param list<int> $fasesDaFamilia`, o 4º parâmetro
`array $fasesDaFamilia` da assinatura de `criarProdutoDoKit()` e o argumento
`$familia->pluck('fase')->all()` da chamada.

**A variável `$familia` FICOU**, porque é usada na linha 197, em
`$this->recusarQuantidadeRepetida($familia, $quantidade)` -- a releitura sob a
trava do rascunho do base (WR-B02). Removê-la teria tirado a reconferência da
quantidade repetida de dentro da transação. Conferido por grep depois do GREEN:
as linhas 181, 195 e 197 seguem usando `familia()` / `$familia`.

---

## Furo 2 na tela -- o número que a pessoa lê antes de confirmar

### Por que a restrição "só backend" foi revista

`DialogoVincularKit.jsx` exportava `proximaFaseDaFamilia(produtos, baseId)` --
`Math.max(1, ...fases, 1) + 1`, o mesmo cronológico do PHP -- e alimentava **três**
pontos de `Produtos.jsx` (a faixa de sugestões, a prop do `PainelDoProdutoLateral`
e a prop do próprio diálogo).

Se o servidor passasse a derivar da quantidade e o espelho ficasse cronológico, o
diálogo diria **"Vincular como Fase 2"** e o banco gravaria **Fase 5**. Isso não
fecharia o furo: **moveria o furo para um lugar pior**, porque a pessoa leria o
número errado **ANTES de confirmar** -- e confirmaria com base nele.

### O que foi achado sobre o campo de unidades editável

Medido no código, e é o que decidiu o formato do rótulo: **no diálogo o campo de
unidades é editável ao vivo** (estado `quantidade` / `setQuantidade`, `autoFocus`,
inicializado por `quantidadeInicial(sugestao)`), e `quantidadeInicial` devolve
string **vazia** quando a sugestão não traz o N (casamento por SKU). Daí duas
consequências:

1. **O rótulo tem de seguir o campo, não uma prop.** Número que não acompanha o
   que a pessoa digitou é a mesma mentira em versão lenta. A linha
   `const fase = numeroSeguro(proximaFase) ?? 2;` virou
   `const fase = faseDoVinculo(quantidade);` -- o estado, ao vivo. E a prop
   `proximaFase` do diálogo foi **removida do contrato**: ela tinha virado fonte
   de mentira, um número calculado pela página que o campo podia contradizer no
   instante seguinte.
2. **Com o campo vazio não existe número honesto.** Antes a tela mostrava um `2`
   fixo; com a derivação crua mostraria "Fase 1", que é **pior** (Fase 1 é o
   base). O rótulo passou a sair **sem número** -- botão "Vincular como kit" e a
   frase "Só registra que ele é **uma fase** deste produto base" -- até a pessoa
   informar a quantidade. O botão já ficava desabilitado nesse estado com a
   explicação que existe: `podeEnviar` e `erroLocalDaQuantidade` **não foram
   tocados**.

### Duas funções puras, porque são duas perguntas diferentes

| Função | Devolve | Para quê |
|---|---|---|
| `faseDaQuantidade(quantidade)` | `number` | espelho fiel de `PubProduto::faseDaQuantidade()` (mesmo nome de propósito): 1 vira 1, 2 vira 2, 5 vira 5, `'5'` vira 5, 0 vira 1, -3 vira 1, e todo valor adverso (`null`, `undefined`, vazio, `'abc'`, objeto, array, `'2,5'`) vira 1 |
| `faseDoVinculo(quantidade)` | `?number` | o número que a TELA pode afirmar: inteiro `>= 2` devolve o degrau; qualquer outra coisa devolve **`null`** |

`faseDoVinculo` existe porque a tela precisa poder dizer "não sei": campo vazio,
`'1'`, 0, `null`, objeto e `'abc'` devolvem `null`; 2, 5 e `' 12 '` devolvem 2, 5
e 12.

As duas deixaram de precisar da lista de produtos e do `baseId`: **a fase do
vínculo depende só da quantidade vinculada**, nem da família nem do base. O
conserto da tela ficou mais simples do que o que existia.

### PainelDoProdutoLateral

Ali a quantidade **não** é editável (quem calcula é a página), então a prop
`proximaFase` continua existindo -- mas mudou de sentido: passou a ser a fase
derivada **ou `null`**. O fallback `: 2` saiu; com `null`, a frase sai sem número
e o botão diz "Vincular como kit". O JSDoc da prop foi atualizado.

### Produtos.jsx -- os três chamadores

- import: `{ faseDoVinculo }` no lugar de `{ proximaFaseDaFamilia }`;
- a faixa de sugestões: `faseDoVinculo(primeiraSugestao.quantidade)`, com duas
  formas de texto (com número e sem número). O ramo de vários produtos não mudou;
- a prop do painel: `faseDoVinculo(sugestaoSegura(produtoDoPainel)?.quantidade ?? null)`;
- a prop do diálogo: **removida**.

A variável `lista` **continua em uso** (`vazio`, `comSugestao`, paginação) -- só os
argumentos que eram dela saíram. E **nenhum dos três chamadores está dentro de um
`.map()`**, mantido assim de propósito: variável de escopo do componente usada
dentro de um `.map()` já foi eliminada pelo Rollup no bundle de produção neste
projeto (ReferenceError em produção, não no teste).

---

## Nada de migration e nada de backfill -- os números de produção

Consulta à VPS feita pelo coordenador em **10/10/2026** (`plink` + `artisan
tinker`), **não refeita aqui** (o executor é bloqueado em produção neste projeto):

- `pub_produtos` = **31** linhas;
- delas, **1 único kit**: `#32  sku=CAD+MESA-RD-CBT4-KIT2  fase=2  qtd=2  base=5`;
- `whereColumn('fase', '<>', 'quantidade_kit')` = **0**.

Logo **`fase = quantidade_kit` já valia para 100% do dado existente**. Consequência
direta, e é a justificativa de **não** ter escrito nenhum dos dois:

- **nenhuma migration** -- a coluna não muda de forma, só de regra de preenchimento;
- **nenhum comando de backfill** -- não há uma única linha divergente para corrigir;
- **nenhum rótulo e nenhuma contagem mudam** em produção: o Kit 2 existente
  continua Fase 2, no mesmo bucket, com o mesmo `rotuloFase`.

Gates conferidos: `git diff --stat -- routes database` vazio e
`git status --porcelain database/migrations` vazio.

---

## Cada asserção alterada: antes, depois e o porquê

### PHP

| Onde | Antes | Depois | Por quê |
|---|---|---|---|
| `CriarFaseServiceTest:190` (Kit 3) | `assertSame(2, $kit->fase, 'família só com a Fase 1: o kit é a Fase 2')` | `assertSame(3, ..., 'Kit 3 é a Fase 3: a fase vem da quantidade, não da ordem de criação')` | **era o bug capturado num teste existente** (seção própria acima) |
| `CriarFaseServiceTest:190` (Kit 4, última linha do mesmo teste) | `assertSame(3, ...->fase)` | `assertSame(4, ..., 'Kit 4 é a Fase 4')` | mesma causa: o 2º kit da família também é o degrau da quantidade **dele** |
| `CriarFaseServiceTest` ~linha 45 | teste inteiro `test_proxima_fase_e_a_maior_mais_um` (lista vazia vira 2, `[1]` vira 2, `[1,2]` vira 3, `[3,1,2]` vira 4, `[1,2,5]` vira 6) | `test_fase_da_quantidade_e_a_propria_quantidade` (1 vira 1, 2 vira 2, 5 vira 5, 0 vira 1, -3 vira 1) | o método testado **deixou de existir**; o teste antigo nem compilaria. A regra que ele provava (cronológica) é justamente a revogada |
| `CriarFaseServiceTest` docblock da classe | "helpers PUROS (`proximaFase` / `proximaQuantidade`)" | "(`faseDaQuantidade` / `proximaQuantidade`)" | comentário que cita método removido mente |
| `VinculoDeKitTest` ~linha 253 | `assertSame(2, (int) $combo->fase, 'fase = max(fase da família) + 1')` | `assertSame(2, ..., 'fase = quantidade: Kit 2 é a Fase 2')` | **o valor 2 ficou** (o vínculo é de 2 unidades): só a **mensagem** mentia |
| `CenarioPlanejamentoDaFase:157` | `PubProduto::proximaFase($grupo->familia()->pluck('fase')->all())` | `PubProduto::faseDaQuantidade($n)` | chamava o método removido; mesmo valor, porque lá a quantidade já é o degrau |
| `TelaDoProdutoTest` ~205 e ~264 | -- | -- | **conferidos, não editados**: já afirmavam `numero === quantidade_sugerida` (2 e 3) e seguem verdes |

**Testes PHP novos** (nenhum afrouxado, nenhum apagado):

- `test_primeiro_kit_de_cinco_unidades_nasce_na_fase_5` -- o caso que prova o bug;
- `test_familia_com_kit_2_e_kit_4_recebe_o_kit_3_na_fase_3_e_em_ordem` -- sugestão
  3, fase 3, e `familia()` na ordem 1, 2, 3, 4 tanto em `fase` quanto em
  `quantidade_kit`;
- `test_vincular_cinco_unidades_na_familia_sem_kit_nasce_na_fase_5` -- o endpoint
  devolve `fase 5` com `rotulo_fase 'Kit 5'` (antes: fase 2 **com** rótulo
  "Kit 5", dois números do mesmo produto);
- `test_familia_com_kit_2_e_kit_4_sugere_fase_3_e_nao_5` -- o `numero` do botão;
- os quatro do KIT-06 no vínculo.

**Não afrouxado**: a guarda `VINC-00` (as 4 colunas e nada mais) e o critério da
§9 (vincular não toca rascunho, estoque nem MLBs) continuam byte a byte como
estavam.

### JS

| Onde | Antes | Depois | Por quê |
|---|---|---|---|
| `publicador-produtos-fases.test.js:675` | desestruturava `proximaFaseDaFamilia` | desestrutura `faseDaQuantidade, faseDoVinculo` | o export mudou |
| `publicador-produtos-fases.test.js:711` | `'proximaFaseDaFamilia: espelha PubProduto::proximaFase (max + 1, mínimo 2)'` -- provava base sozinho vira 2, com Kit 2 vira 3, com Kit 2 e Kit 3 vira 4 | `'faseDaQuantidade / faseDoVinculo: espelham PubProduto::faseDaQuantidade (Kit N é a Fase N)'` -- as duas listas de valores da seção acima | **mudou de propósito**: era o espelho da regra revogada. ⚠️ Continua com dentes: se a regra voltasse a ser `max+1`, um primeiro kit de 5 daria 2 e as asserções de 5 e de `'5'` falhariam |
| `publicador-produtos-fases.test.js:755` (fábrica `props()`) | `proximaFase: 2` | removido | a prop deixou de existir no diálogo |
| `publicador-produtos-fases.test.js:785` | `'o botão diz a fase que vai nascer'`: render padrão casa Fase 2, `{proximaFase: 3}` casa Fase 3 | `'... derivada da QUANTIDADE'`: render padrão casa Fase 2, sugestão com `quantidade: 5` casa Fase 5, e `quantidade: null` casa "Vincular como kit" **e** não casa "Fase 1" | a prop saiu; o rótulo agora segue a quantidade, e o caso sem N é o que prova o `null` |
| `publicador-produtos-fases.test.js:795` (D23) | agulha `html.indexOf('Vincular como Fase')` | agulha `html.indexOf('Vincular como')` | com o campo vazio o botão diz "Vincular como kit". ⚠️ A asserção que importa, `/disabled=/`, **não foi afrouxada** -- e segue na forma com o `=`, nunca `/disabled/` cru, que casaria sempre com `disabled:opacity-40` |
| `publicador-produtos-fases.test.js:815` (modo recusar) | `doesNotMatch(/Vincular como Fase/)` | `doesNotMatch(/Vincular como/)` | **apertou**: é o que o modo recusar realmente não mostra, em nenhuma das duas formas |
| `publicador-produtos-fases.test.js:822 e 830` (adversos) | overrides `proximaFase: null` e `proximaFase: 'duas'` | removidos | a prop não existe mais. O `quantidade: {}` do 2º caso **ficou** e agora é exatamente a prova do caminho `faseDoVinculo` devolvendo `null` |
| `publicador-produtos-fases.test.js:870` (gate de fonte) | `assert.match(fonte, /proximaFaseDaFamilia/)` | `assert.match(fonte, /faseDoVinculo/)`, com o comentário acima reescrito ("vem da QUANTIDADE da sugestão, não da família nem da ordem") | a função mudou de nome e de regra |
| `publicador-produtos-layout.test.js` ~910, ~994, ~1004 | `proximaFase: 2` / `: 3` | -- | **conferidos, não editados**: a prop segue sendo um número, só mudou quem o calcula |
| `publicador-produtos-layout.test.js` ~1057-1058 | `{proximaFase: 'duas'}`, `{proximaFase: null}` | -- | **mantidos**, mas documentados: usam `sugestao: null`, nem chegam ao rótulo, e **não provam nada do caminho novo** |
| `publicador-produtos-layout.test.js` (caso novo) | -- | sugestão real com `proximaFase: null` casa "Vincular como kit" e não casa "Vincular como Fase" nem "virar Fase" | prova o `null` **com** sugestão, que é o único jeito de chegar ao rótulo |

**Desvio registrado (Regra 1 -- correção inline).** A asserção do caso novo do
layout começou como `doesNotMatch(/Fase 1/)`, conforme o `<behavior>` do plano, e
**falhou por culpa da agulha, não do código**: `/Fase 1/` cru casa com o **selo de
fase do próprio produto** no cabeçalho do painel (`rotulo_fase: '1 unidade'` rende
um span com texto "Fase 1" e `title="1 unidade"`), que não tem relação nenhuma com
o vínculo. Foi substituída por `doesNotMatch(/virar Fase/)` -- a frase do bloco
"Parece um kit" --, que continua com dentes: se o rótulo voltasse ao 2 fixo ou à
derivação crua, tanto `/Vincular como Fase/` quanto `/virar Fase/` casariam.

---

## Gates

| Gate | Baseline | Resultado |
|---|---|---|
| `phpunit tests/Unit/Publicador tests/Feature/Publicador` | 1684 passando / 0 falhando | **1692 testes, 10.380 asserções, 0 falhas** (+8 novos; 34 deprecations do PHPUnit, pré-existentes) |
| `npm run test:js` | 2122 testes / 2121 pass / 1 fail | **2122 testes, 27 suítes, 2121 pass, 1 fail** -- a falha é `tests/js/estrutura-grade-glide.test.js:122`, *"Características secundárias nasce recolhido (é o grupo que mais infla)"*, **PRÉ-EXISTENTE e não corrigida** |
| `npm run build` | -- | **concluído em 1m 01s**; conferido pelo **JSON** do manifest, nunca por grep (o hash pode conter hífen): a chave `resources/js/Pages/Mlb/Publicador/Produtos.jsx` existe, `file = assets/Produtos-zVKT9DFs.js`, e o arquivo existe no disco |
| `git diff --stat -- routes database` | -- | **vazio** |
| `git status --porcelain database/migrations` | -- | **vazio** (sem migration) |
| `vendor/bin/pint` | -- | **não rodado** de propósito (reprova o módulo no baseline) |

Contagem do JS lida pelos contadores `pass` / `fail` do `node --test`, nunca pelas
marcas de visto (que contam suítes e inflam).

### Greps de verificação

| Grep | Resultado |
|---|---|
| `grep -rn "PubProduto::proximaFase" app/ tests/` | nenhuma ocorrência |
| `grep -rn "proximaFaseDaFamilia" resources/ tests/js/` | nenhuma ocorrência |
| `grep -rln "faseDaQuantidade" app/` | `PubProduto`, `CriarFaseService`, `VinculoDeKitService`, `FamiliaDeFasesService` |
| `grep -n "KIT-06" app/Services/Publicador/VinculoDeKitService.php` | 4 (docblock, `@throws`, comentário e a recusa) |

As ocorrências de `proximaFase` que **sobraram e estão corretas**: o método
privado `FamiliaDeFasesService::proximaFase()` (nome inalterado), a citação dele
no docblock do `MlbPublicadorFaseController:65`, e a menção **histórica** no
docblock de `faseDaQuantidade` explicando por que a regra mudou.

---

## Commits

| Commit | Tarefa |
|---|---|
| `90890f31` | Tarefa 1 -- KIT-06 também no vínculo |
| `e52025e3` | Tarefa 2 -- a fase derivada da quantidade no servidor |
| `35863677` | Tarefa 3 -- o espelho na tela |

Um commit por tarefa (protocolo do executor GSD), todos por **pathspec explícito**:
`git add -- <caminhos>` seguido de `git commit -F <arquivo> -- <os mesmos caminhos>`,
nunca `git add -A`, porque a árvore é compartilhada com outro dev e com outras
sessões do Claude. `tests/Feature/CompanyPortfolioAccessTest.php` e os vários
`.planning/**` untracked ficaram intocados. Sem `git stash`, sem `git reset`, sem
`git checkout`, sem `--amend`, **sem push e sem deploy**. `public/build/` é
ignorado pelo `.gitignore` (linha 41) e não entra nos commits.

---

## Conferência visual -- pendente, do usuário

⚠️ **Não há navegador neste ambiente: nada aqui foi visto renderizado.** Os render
tests usam `react-dom/server` (`renderToStaticMarkup`) e provam o HTML gerado, não
a tela. O que falta conferir no navegador, com o usuário:

1. abrir a sugestão de kit de um produto **com** o N na sugestão: o botão diz
   "Vincular como Fase N" com o N certo;
2. abrir uma sugestão **sem** o N (casamento por SKU, campo vazio): o botão diz
   "Vincular como kit", sem número, e está desabilitado com a explicação;
3. **digitar** no campo de unidades e ver o rótulo do botão acompanhar ao vivo
   (2 vira "Fase 2", apagar vira "Vincular como kit", 5 vira "Fase 5");
4. criar um Kit 5 como primeira fase de uma família e conferir que o cartão e a
   Visão geral passaram a concordar.

---

## Self-Check: PASSED

- SUMMARY.md existe no caminho do bloco `<output>` do plano.
- Os tres commits existem no historico: `90890f31`, `e52025e3`, `35863677`.
- Os quatro arquivos de `app/` e os tres de `resources/` citados existem e contem os simbolos novos (`faseDaQuantidade`, `faseDoVinculo`, `KIT-06`).
