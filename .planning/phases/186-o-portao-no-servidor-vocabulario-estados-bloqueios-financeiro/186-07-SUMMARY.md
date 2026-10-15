---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 07
subsystem: publicador-aprovacao
tags: [financeiro, composicao, tarifa-ml, margem-minima, vocabulario, gates]
requires:
  - "186-06 (`pub_aprovacao_composicoes`, `pub_aprovacao_fretes`, `PubAprovacaoComposicao::linhaDeFrete()`)"
  - "186-02 (`PubAprovacaoOferta`, o invariante da única porta do `andamento`)"
  - "186-01 (`tests/gates/verificar-testes.sh`)"
provides:
  - "`ComposicaoFinanceiraService`: as 7 linhas, resultado, margem, equilíbrio, preço-alvo, simulação pura, comparação com a irmã e congelamento"
  - "`LinhaFinanceira` / `ComposicaoDaOferta`: os dois value objects, com a origem DERIVADA"
  - "`config('publicador.aprovacao.margem_minima_percentual')`: a ÚNICA configuração nova da milestone"
  - "`ComposicaoFinanceiraService::margemMinimaComOrigem()`: o resolvedor do mínimo, em UM lugar só"
affects:
  - "plano 186-08 (bloqueios `loss`/`margin` leem esta composição e este resolvedor)"
  - "plano 186-09 (a tela do painel financeiro)"
tech-stack:
  added: []
  patterns:
    - "value object `final readonly` com invariante garantido POR CONSTRUÇÃO (origem derivada)"
    - "tarifa do ML lida com cache de 1 h (o mesmo de `AnaliseAlavancasService`)"
    - "gate de ausência ancorado na FORMA decimal com borda nos dois lados + controle negativo e positivo"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/LinhaFinanceira.php
    - app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php
    - app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php
    - tests/Unit/Publicador/Aprovacao/ComposicaoFinanceiraServiceTest.php
    - tests/Feature/Publicador/Aprovacao/ComposicaoContraOPrototipoTest.php
    - tests/Feature/Publicador/Aprovacao/SemLiteralDeTaxaTest.php
    - tests/gates/contagens-186-07.txt
    - tests/fixtures-ml/sondagem/publico/categorias/MLB189007/listing_prices_gold_special_preco_baixo.json
  modified:
    - config/publicador.php
decisions:
  - "A margem mínima é o parâmetro da empresa quando > 0, senão a config nova — o ZERO não desliga o bloqueio (decisão do usuário, 14/10/2026)"
  - "Embalagem sem fonte é `Pendente` VISÍVEL que NÃO anula o resultado; Outros custos nasce R$ 0,00 `Confirmado` (decisão do usuário, 14/10/2026)"
  - "Preço por variante: manda o PIOR CASO (menor preço ativo), e a tela nomeia a variante"
  - "A nota da margem diz 'com a tarifa real do Mercado Livre'; a divergência com a visão rápida do lote é DESENHO, declarada no docblock"
  - "O gate `composicao` do plano virou 12 ids porque o filtro de 29 métodos passa dos 120 s do shell"
  - "A chave do estado da irmã é `andamento_da_irma` para não colidir com o invariante da única porta do 186-02"
metrics:
  commits: 7
  commit-inicial: 1a03b41f
  tasks: 3
  arquivos-criados: 8
  arquivos-modificados: 1
  metodos-de-teste: 53
  gates: 15
  mutantes: 14
  completed: 2026-10-15
---

# Phase 186 Plan 07: A composição financeira do portão — Summary

As 7 linhas de `fin()` com **fonte real por linha**, comissão e tarifa fixa **separadas** vindas de
`sale_fee_details` da API do Mercado Livre, o resultado que **nunca vira zero**, equilíbrio e
preço-alvo como **teto**, e a **única configuração nova da milestone** (a margem mínima, com
fallback deliberado).

## O que foi feito

| Task | O que | Commit |
|---|---|---|
| — | RED dos value objects (13 métodos, falhando) | `a7d827ea` |
| 1 | `config/publicador.php` (adição pura) + `LinhaFinanceira` + `ComposicaoDaOferta` | `27b752a9` |
| 2 | `ComposicaoFinanceiraService` + teste tabelado + fixture de preço baixo | `2568c289` |
| 3 | `SemLiteralDeTaxaTest` (gate das taxas + jargão) + a bijeção | `8af35b62` |
| — | Conserto da colisão com a única porta do `andamento` (186-02) | `34d699dc` |
| — | Os 2 sobreviventes da mutação, fechados | `e7767f7b` |

---

## ⚠️ AS 7 LINHAS → FONTE REAL · O QUE SIGNIFICA `0` · O QUE SIGNIFICA AUSÊNCIA

Cada fonte foi **conferida por `grep`** no repositório. `arquivo:linha` abaixo.

| linha | fonte real (`arquivo:linha`) | `0` significa | ausência significa |
|---|---|---|---|
| **`custo`** (Custo do produto) | `DadosEfetivosService::custosDoProduto()` — **`app/Services/Publicador/DadosEfetivosService.php:212`**, custo em `:193`/`:234`, origem em `:235`; **× `PubProduto::$quantidade_kit`** (`ComposicaoFinanceiraService.php:544`) | custo zero informado na Precificação → `Confirmado`, R$ 0,00 | a Precificação **está sem custo** → `Pendente` ⇒ **ANULA** o resultado |
| **`comissao`** (Comissão) | **API do ML** `/sites/MLB/listing_prices` → `sale_fee_details.percentage_fee` — **`ComposicaoFinanceiraService.php:501`/`:510`**; lida com cache de 1 h, o mesmo de `AnaliseAlavancasService:212` | preço × 0% (não observado nas fixtures) → `Confirmado` | resposta **sem** `sale_fee_details`/`percentage_fee`, ou API fora do ar → `Pendente` ⇒ **ANULA** |
| **`tarifa_fixa`** (Tarifa fixa por venda) | **API do ML** → `sale_fee_details.fixed_fee` — **`ComposicaoFinanceiraService.php:511`** | ⚠️ **ZERO MEDIDO** (preço acima do limiar do ML): `Confirmado` mostrando **R$ 0,00**, detalhe *"Não se aplica a este preço"* | campo ausente no bloco → `Pendente` ⇒ **ANULA** |
| **`frete`** (Frete pago pelo vendedor) | `DadosEfetivosService::fretesDoProduto()` — **`:248`**, origem viva em **`fretesDaLinha()`:269** (`digitado`/`conta`/`tabela`/`outro_tipo`); **vencido** por `PubAprovacaoFrete::vigenteDe()`; montagem **LIDA** de `PubAprovacaoComposicao::linhaDeFrete()` (`ComposicaoFinanceiraService.php:606`) | frete zero informado → `Confirmado`/`Estimado`, R$ 0,00 | `valor = null` na Precificação **e** nenhum informe → `Pendente` ⇒ **ANULA** |
| **`imposto`** (Impostos) | `custosDoProduto()['imposto']` — **`DadosEfetivosService.php:216`** (parâmetro da empresa) com a **exceção da oferta vencendo** em **`:227`/`:232`** | imposto 0% configurado → `Confirmado`, R$ 0,00 | empresa sem parâmetro fiscal → `Pendente` ⇒ **ANULA** |
| **`embalagem`** (Embalagem) | ⚠️ **MEDIDO: NÃO TEM FONTE NO REPOSITÓRIO** (`ComposicaoFinanceiraService.php:653`) | n/a — nunca chega a ter valor hoje | ⚠️ **EXCEÇÃO DELIBERADA:** `Pendente` **VISÍVEL** que **NÃO anula**; o resultado existe com `estimadoPorBaixo = true` |
| **`outros`** (Outros custos) | ⚠️ **MEDIDO: NÃO TEM FONTE** → **R$ 0,00 `Confirmado`**, nota *"Cadastro do produto"* (`ComposicaoFinanceiraService.php:667`) | ⚠️ é o caso **normal**: o cadastro existe e **afirma** que não há outros custos | n/a — a linha nunca nasce `Pendente` |
| **(8ª) `frete_comprador`** (Frete pago pelo comprador) | ⚠️ **sem fonte**, e é **informativa** (FIN-08) — `ComposicaoFinanceiraService.php:674` | n/a | `Pendente`, **fora** do total, **fora** de `incompleto` e **fora** de `estimadoPorBaixo` |

➡️ **Quem ANULA o resultado são só CINCO:** `custo`, `comissao`, `tarifa_fixa`, `frete`, `imposto`
(`PubAprovacaoComposicao::ANULAM_O_RESULTADO`, do plano 186-06). Testado **uma a uma**, e as duas que
**não** anulam também (`test_a_excecao_da_embalagem_mantem_o_resultado_e_marca_estimado_por_baixo`).

---

## ⚠️ O SPLIT DA TARIFA — provado com `arquivo:linha` da fixture

**Fixture REAL usada:** `tests/fixtures-ml/sondagem/publico/categorias/MLB189007/listing_prices_gold_special.json`
(captura de **2026-10-01**, `price: 150`):
`sale_fee_amount: 16.5` · `sale_fee_details: { fixed_fee: 0, gross_amount: 16.5, percentage_fee: 11 }`.

### A identidade `comm + fixed == gross_amount == sale_fee_amount`, nos DOIS regimes

| regime | fixture | `percentage_fee` | `fixed_fee` | `comm` | `fixed` | soma | `gross_amount` | `sale_fee_amount` |
|---|---|---|---|---|---|---|---|---|
| `fixed_fee == 0` | `…/MLB189007/listing_prices_gold_special.json` (real, 01/10) | 11 | 0 | **16,50** | **0,00** | **16,50** | 16,5 ✅ | 16,5 ✅ |
| `fixed_fee != 0` | `…/MLB189007/listing_prices_gold_special_preco_baixo.json` (**criada neste plano**) | 11 | 5 | **6,60** | **5,00** | **11,60** | 11,6 ✅ | 11,6 ✅ |

### ⚠️ `gross_amount` NÃO é somado como terceira parcela

`test_o_split_sao_duas_linhas_e_o_gross_amount_nao_entra_como_terceira_parcela` afirma
`assertNotSame(33.0, round($comissao->valor + $fixa->valor, 2))` — **33,00 é exatamente o que sairia
se `gross_amount` entrasse junto das partes**. O mutante **M03** (trocar `$tarifa['fixa']` por
`$tarifa['bruto']`) foi **MORTO** por esse gate.

### ⚠️ `listing_fee_details` É IGNORADO — o teste de 999

`test_listing_fee_details_e_ignorado_mesmo_valendo_999` injeta
`listing_fee_details = {fixed_fee: 999, gross_amount: 999, percentage_fee: 999}` e
`listing_fee_amount = 999` **na mesma resposta**, e exige `comm = 16,50` e `fixed = 0,00`.
⚠️ **Era imprescindível:** nas fixtures reais `sale_fee_details.fixed_fee` **e**
`listing_fee_details.fixed_fee` são **os dois zero**, então um teste sem o 999 passaria lendo o bloco
errado. Há **isca** afirmando que o 999 chegou ao stub. O mutante **M01**
(`sale_fee_details` → `listing_fee_details`) foi **MORTO**.

### ⚠️ `percentage_fee` é PONTO PERCENTUAL e VARIA

- `test_percentage_fee_e_ponto_percentual_e_nao_fracao`: com `price: 150` e `percentage_fee: 11` a
  comissão é **16,50**, e o teste recusa **0,17** e **1650,00**. Mutante **M02** (sem o `/100`):
  **MORTO**.
- `test_percentage_fee_variavel_por_categoria_o_servico_usa_o_que_chegou`: duas categorias REAIS com
  percentuais REAIS diferentes no mesmo tipo — **MLB189007 = 11** e **MLB31447 = 12** —, com isca
  afirmando que os dois são diferentes. **Inventário medido das 17 ocorrências:** `11 (×4)`,
  `12 (×4)`, `14 (×4)`, `15 (×2)`, `17 (×2)` nas fixtures reais do MLB, mais `36` na única de
  documentação (ARS/MLA).
- `test_resposta_sem_sale_fee_details_deixa_as_duas_linhas_pendentes_e_a_composicao_incompleta`:
  **ramo de ERRO declarado** — sem o bloco, sem `percentage_fee`, ou com HTTP 500, as duas linhas
  nascem `Pendente`, as **7 continuam existindo** (nada colapsa em uma só) e **nunca** há percentual
  fixo (T-186-27). Mutante **M04** (`fixed_fee: 0` tratado como ausente): **MORTO**.

### A fixture criada, com a origem declarada DENTRO do arquivo

`tests/fixtures-ml/sondagem/publico/categorias/MLB189007/listing_prices_gold_special_preco_baixo.json`
traz quatro campos de procedência no topo: `origem: "sintetica-fase-186-plano-07"`,
`por_que_existe`, `o_que_e_real` e `o_que_e_sintetico`.

⚠️ **Por que ela existe:** das 17 ocorrências medidas, **16 têm `fixed_fee: 0`**, e a única diferente
é **documentação ARGENTINA** — `tests/fixtures-ml/alavancas/doc/analise/listing_prices.json:2`
(`origem: "doc-oficial"`), `:9` (`currency_id: "ARS"`), `:22-25` (`fixed_fee: 200`,
`meli_percentage_fee: 13`, `percentage_fee: 36`), site **MLA**. Não é resposta real do Brasil.

⚠️ **O que é real nela:** a FORMA (chaves, aninhamento, tipos), copiada da captura de 01/10 —
inclusive o `listing_fee_details` zerado —, e o `percentage_fee: 11`, que é o valor **real** medido
para `MLB189007 + gold_special`. **O que é sintético:** `price: 60` e `fixed_fee: 5`, escolhidos. O
único compromisso é a identidade, conferida: `60 × 11/100 + 5 = 11,60`. O teste **assere** que o
campo `origem` diz `sintetica-fase-186-plano-07` — a fixture não pode virar "captura real" em
silêncio.

---

## ⚠️ O IMPOSTO USADO — valor e origem, e NENHUM 6%

**Valor: `19.0`. Origem: `EstruturaPrecificacaoParametros::PADROES['imposto']`**
(`app/Models/EstruturaPrecificacaoParametros.php:23`), que é o padrão real do sistema e o que
`DadosEfetivosService::custosDoProduto()` devolve quando a empresa não tem exceção.

- O cenário dos testes injeta `EstruturaPrecificacaoParametros::PADROES['imposto']` **por
  referência à constante**, nunca um literal
  (`ComposicaoContraOPrototipoTest.php`, método `cenario()`), e
  `test_as_sete_linhas_vem_das_fontes_reais_com_origem_e_nota_que_nomeia_cada_uma` **declara** o
  valor: `assertSame(19.0, EstruturaPrecificacaoParametros::PADROES['imposto'])` e
  `assertStringContainsString('19% do preço', …->detalhe)`.
- ⚠️ **Nenhum caso de teste usa 6% como "o padrão".** O `0.06` do README do desenho é um dos quatro
  literais **PROIBIDOS** pelo gate `sem-literal-de-taxa`.
- ⚠️ E está escrito no docblock do serviço que **`comissao_classico => 11.5` e
  `comissao_premium => 16.5`** (`EstruturaPrecificacaoParametros.php:21-22`) **NÃO são a fonte** —
  são os parâmetros com que o **Portal precifica**. São perto o bastante de 12/17 para alguém achar
  que achou a fonte, e por isso a negativa é explícita.

---

## ⚠️ A EXCEÇÃO DELIBERADA DA EMBALAGEM — testada NOS DOIS LADOS

**Decisão do usuário, 14/10/2026.** Escrita com data e razão em três lugares:
`ComposicaoDaOferta.php` (docblock da classe), `ComposicaoFinanceiraService::linhaDeEmbalagem()` e
`::linhaDeOutros()`.

**A razão:** não há fonte de embalagem **nem** de outros custos no repositório (medido). Pela regra
crua *"faltando qualquer item o resultado é `null`"*, **100%** das ofertas sairiam `Incompleto`, o
bloqueio de frete dispararia **sempre** e **nada poderia ser aprovado** — um portão que não deixa
nada passar.

### Os dois lados, no MESMO arquivo e no MESMO método

| arquivo | método | `pack` ausente | `freight` ausente |
|---|---|---|---|
| `tests/Unit/.../ComposicaoFinanceiraServiceTest.php` | `test_a_excecao_da_embalagem_mantem_o_resultado_e_marca_estimado_por_baixo` | `incompleto === false`, `resultado !== null`, `estimadoPorBaixo === true`, nota contém *"sem o custo de embalagem"*, `equilibrio`/`precoAlvo` **existem** | `incompleto === true`, `resultado === null` |
| `tests/Feature/.../ComposicaoContraOPrototipoTest.php` | `test_embalagem_sem_fonte_nao_anula_mas_frete_ausente_anula_no_mesmo_cenario` | idem, no caminho REAL (ML + Precificação) | `incompleto === true` e `total`/`resultado`/`equilibrio`/`precoAlvo` **todos `null`**; e `custo` ausente **também anula** |

O mesmo método unitário varre **as cinco** de `ANULAM_O_RESULTADO` (cada uma anula) **e as duas** que
não (cada uma marca `estimadoPorBaixo`). O mutante **M10** (uniformizar: toda pendência anula) foi
**MORTO** pelo gate `excecao-e-regra`.

### A dedução DERIVADA, registrada como tal

⚠️ `equilibrio` e `precoAlvo` **também existem** com `estimadoPorBaixo`. **É dedução do planejador,
não fala do usuário:** suprimi-los deixaria o atalho "preço para a margem mínima" (FIN-11)
**sempre vazio**, e ele é a ferramenta principal da tela. Está escrito assim no docblock de
`ComposicaoDaOferta`, e o corolário também: **os bloqueios `loss`/`margin` disparam sobre o valor
estimado por baixo** (plano 186-08) — o resultado é **otimista**, a margem **real** é menor, e com
aprovar-já-publica **sem desfazer**, não bloquear seria o erro grave.

---

## ⚠️ A MARGEM MÍNIMA — os QUATRO casos, cada um OBSERVÁVEL

```
margem mínima = EstruturaPrecificacaoParametros::daEmpresa($id)['margem_contribuicao']  quando > 0
              senão  config('publicador.aprovacao.margem_minima_percentual')   (padrão 12,0)
```

Resolvedor: **`ComposicaoFinanceiraService::margemMinimaComOrigem()`**
(`:184` lê a config, `:193` lê o parâmetro) — **em UM lugar só**. `margemMinimaDaEmpresa()` delega a
ele; nenhuma segunda cópia da regra existe.

⚠️ **O ZERO NÃO DESLIGA O BLOQUEIO.** `EstruturaPrecificacaoParametros::PADROES['margem_contribuicao']`
é **`0.0`** (asserido no teste), então **só-parâmetro** desligaria a trava em toda empresa que nunca
salvou a Calculadora de Custo, e **só-config** ignoraria quem configurou a própria exigência. **As
duas simplificações falham em silêncio** — e por isso existem os dois lados.

`test_os_quatro_casos_da_margem_minima_mudam_o_preco_alvo_e_a_nota_de_forma_observavel`:

| caso | `margem_contribuicao` | mínimo resolvido | origem gravada | observável |
|---|---|---|---|---|
| parâmetro > 0 | `15.0` | **15,0** | `parametro_da_empresa` | `precoAlvo` **maior**; frase *"mínimo de 15%"* |
| parâmetro = 0 | `0.0` | **12,0** | `config` | `precoAlvo` **igual aos outros dois da config**, e **não nulo** |
| parâmetro negativo | `-5.0` | **12,0** | `config` | `precoAlvo` igual ao do zero |
| empresa **sem linha** | (sem linha) | **12,0** | `config` | `precoAlvo` igual ao do zero |

E o fecho: `assertNotSame(precoAlvo do parâmetro, precoAlvo da config)` —
*"o parâmetro da empresa não está mudando nada, o resolvedor não está sendo usado"*. Mutante **M05**
(só-parâmetro): **MORTO**.

### O lado de onde o mínimo veio, GRAVADO

`congelar()` grava em **dois lugares**, e o teste `test_congelar_grava_o_minimo_da_epoca_e_de_qual_lado_do_fallback_veio`
afirma os dois:
- a coluna **`margem_minima_origem`** (`pub_aprovacao_composicoes`, do plano 186-06);
- dentro de **`linhas['resolucao']['margem_minima_origem']`** — *"é esse bloco que a auditoria lê"*.

E o invariante da auditoria: mudar a config **depois** do ato **não** muda o congelado
(`margemMinimaCongelada()` continua 20,0 com a config em 40,0) — T-186-29.

### Não há mais pendência de `margem_contribuicao`

Ela **virou a regra**, com fallback. A pergunta foi devolvida e respondida pelo usuário em
14/10/2026, e a escolha está no comentário de `config/publicador.php` e no bloco (b) do docblock do
serviço, com a data.

---

## ⚠️ AS DUAS MARGENS, DECLARADAS NO DOCBLOCK (decisão 9)

Bloco **(a-0)** do docblock de `ComposicaoFinanceiraService`:

- **o portão e o editor** usam a **tarifa REAL do ML** — esta classe e
  `EditorRascunhoService::simular()` (`SimuladorVoceRecebe::calcular` em
  **`app/Services/Publicador/EditorRascunhoService.php:427`** e em
  **`app/Services/Publicador/Alavancas/AnaliseAlavancasService.php:191`**);
- **a visão rápida do lote** usa o **parâmetro da Precificação** —
  **`app/Services/Publicador/Fila/ResumoRapidoService.php:384-391`** (⚠️ o caminho tem `Fila/`,
  conferido por leitura).

O docblock de **`EditorRascunhoService:371-373`** diz textualmente *"diferente DE PROPÓSITO […] São
perguntas diferentes; não unificar"*. ➡️ A escolha do número real do ML **não muda** (FIN-04), **mas
a tela nomeia a fonte**: a `notaDaMargem` do portão termina em **"· com a tarifa real do Mercado
Livre"** (asserido). E **está escrito no docblock** que o MESMO produto mostra número **diferente**
na visão rápida do lote, e que isso é **desenho, não bug** — sem esse texto a divergência chega ao
usuário como bug de dado.

**FIN-04 provado pelo delta:** `test_fin_04_o_painel_nao_discorda_do_card_do_editor_pela_parte_comum`
afirma que a tarifa e o frete são **os mesmos** nas duas contas e que
`voce_recebe − resultado == custo + imposto + embalagem + outros` — exatamente o que o card **não**
desconta —, com isca de que o delta não é zero.

---

## ⚠️ O GATE DE AUSÊNCIA DAS TAXAS — com os DOIS controles

`tests/Feature/Publicador/Aprovacao/SemLiteralDeTaxaTest.php`. A pergunta do learnings está
respondida no cabeçalho: *"alguém escrever a comissão como percentual fixo em vez de ler a API do
Mercado Livre, e o painel passar a discordar do card do editor sem ninguém ver."*

**A escolha, declarada:** nenhum dos quatro literais é exclusivo, então a sonda ancora na **FORMA
decimal com borda nos DOIS lados** — `/(?<![\d.])6\.75(?![\d])/` — e varre (1) a **lista CONSTANTE**
de 4 arquivos de produção **e** (2) o diretório `app/Services/Publicador/Aprovacao/` por glob.

| controle | entrada | resultado | por quê |
|---|---|---|---|
| **negativo (REPROVA)** | `'$taxa = 0.17;'` | **reprova** (`['0.17']`) | é o caso que o gate existe para pegar |
| **positivo (PASSA)** | `'$x = 26.754383;'` e `'26.754.383/0001-87'` | **passa** (`[]`) | `6.75` casa **dentro** do CNPJ de `app/Support/Cnpj.php:112` |
| colisões medidas | `0.125` · `10.12` · `2026-10.12` · `12px` · `120` · `2012` · `1.067` · `6.751` | **passam** | nenhuma é taxa |
| **deve-casar** (armadilha 3) | `$v = X;` e `round($p * X, 2)` para **cada** um dos 4 | **reprovam** | *toda* regex testada contra um caso que **DEVE** casar |

**Leitura sem comentários, com isca dos dois lados**
(`test_o_leitor_tira_os_comentarios_e_preserva_o_codigo`): uma amostra com os quatro literais
**dentro** de `//`, `/* */` e `/** */` sai limpa pelo tokenizador, **e** o código (`$semTaxa = 1;`,
`'preservado'`) **é preservado**; mais a isca inversa provando que a amostra **tinha** os quatro.
⚠️ Sem isso o gate reprovaria **pelo próprio conserto** — a prosa pt-BR cita *"Clássico 12%, Premium
17%"* justamente para explicar que não os usa (o padrão de 5 dos 12 planos da Fase 184).

**Jargão (VOC-03), nas TRÊS formas:** `lucro líquido`, `lucro liquido` (sem acento) e a **CHAVE**
`lucro_liquido`, varridas no texto **COMPLETO** (comentários incluídos — um docblock que escreva o
termo ensina o próximo leitor a usá-lo). Com **isca** provando que a sonda **ACHA** o campo legítimo
do Portal no arquivo que o tem (`EstruturaPrecificacaoParametros.php`), e com asserção de que ela
**não** reprova o vocabulário correto. Mutante **M07** (chave do jargão no serviço): **MORTO**.

**O `12` por gate POSITIVO** (ele é inutilizável como ausência: `12px`, `120`, `2012`):
- `preg_match('/m[íi]nimo de \d/u', $codigo) === 0` em `ComposicaoDaOferta.php`, **com isca** que
  acha o caso embutido (`"'abaixo do mínimo de 12%'"`) quando ele está lá;
- os **dois lados** do fallback presentes no serviço: `margem_contribuicao` **e**
  `config('publicador.aprovacao.margem_minima_percentual')` — achar só um significaria que alguém
  "simplificou".

**Seleção das chaves, por texto e por comportamento:**
- texto: há **UMA** chamada de `daEmpresa()` no serviço e ela é indexada em
  `['margem_contribuicao']` **na hora**, com isca nos dois sentidos (a sonda acha o caso certo e
  **não** aceita o array sem índice);
- comportamento: `test_a_composicao_seleciona_as_chaves_dos_parametros_e_nao_repassa_o_array`
  congela a composição e prova que `lucro_liquido`, `comissao_classico`, `comissao_premium` e
  `acrescimo` **não aparecem** no json de `linhas`, com isca de que a sonda acha a chave que **deve**
  estar lá.

**Controles positivos do serviço:** `SimuladorVoceRecebe`, `/sites/MLB/listing_prices`,
`sale_fee_details`, `percentage_fee`, `fixed_fee`, `gross_amount`, `sale_fee_amount`,
`custosDoProduto`, `fretesDoProduto`. Mutante **M06** (`0.17` escrito à mão): **MORTO**.

---

## ⚠️ O TESTE TABELADO — a procedência de cada número

**Declaração exigida (FID-02):** a tabela de esperados do teste tabelado é **de LEITURA do
`.dc.html` + conferência aritmética pela MESMA fórmula**, enquanto os **números de tarifa são de
FIXTURE REAL medida**.

Concretamente, em `ComposicaoContraOPrototipoTest::conferirEstado()`:

| o que | de onde |
|---|---|
| a **ORDEM** das 7 linhas | **LIDA** do `fin()` por regex sobre `design_handoff_publicador/Aprovacao.dc.html`, mapeada por `DO_PROTOTIPO` e comparada com `array_keys(PubAprovacaoComposicao::LINHAS)` |
| os **RÓTULOS** | **LIDOS** do mesmo bloco; o do sistema tem de ser **prefixo** do do protótipo (o `comm` do protótipo concatena o tipo de anúncio) |
| `price`, `freight`, `units` de cada estado `o1`..`o10` | **LIDOS** do `OFF` e do `SETS` do mesmo arquivo |
| `percentage_fee`, `fixed_fee` | **fixture REAL** `MLB189007/listing_prices_{gold_special,gold_pro}.json` |
| `imposto` | `EstruturaPrecificacaoParametros::PADROES['imposto']` = **19,0** |
| `mínimo` | `config('publicador.aprovacao.margem_minima_percentual')` |
| `total`, `resultado`, `somaFixa`, `vr`, `equilibrio`, `precoAlvo`, `margem` | **recalculados pela MESMA fórmula** dentro do teste |

⚠️ **Um `test()` por estado** (`test_estado_o1_…` .. `test_estado_o10_…`), **não** um loop — arquivo
que não carrega reporta zero falhas, e **a contagem é o sinal**. `Carbon::setTestNow()` ancora tudo
(REND-08). Sem o `.dc.html` no disco (o diretório **não é versionado**), cada estado dá **`skip`
EXPLÍCITO com a razão** — nunca "conferido".

⚠️ **O que este arquivo NÃO afirma:** os **números** do protótipo. A correção do critério 7 do
ROADMAP (commit `2bea797e`) está citada no cabeçalho, com o motivo: as taxas de `fin()` são **ficção
rotulada pelo próprio protótipo** (`:1064` *"taxa de exemplo"*, `:1066` *"exemplo"*, `src: 'est'`),
obedecê-las exigiria hardcodear `0.12`/`0.17`/`0.06`/`6.75`, e os dois gates da fase ficariam
**mutuamente exclusivos**. ⚠️ E a **seção F do README é PROSA**: nenhum esperado saiu dela — os
bloqueios são do plano 186-08.

---

## Outras decisões implementadas

**Preço por variante — o PIOR CASO (decisão 5).** `precoDoAlvo()` faz join com `pub_variantes`,
filtra `ativa = true` e `preco > 0`, ordena **ascendente** e pega o **primeiro**.
`test_preco_por_variante_manda_o_menor_ativo_e_a_tela_nomeia_a_variante`: três variantes
(529,90 · 449,90 · 999,90 **inativa**) ⇒ preço **449,90**, `precoDeVariante = 'Cor: Bege'`,
`ativos = 2`, nota *"pior caso entre 2 variantes · Cor: Bege"*; **com um preço só não há nota**.
Razão: **aprovar já publica e não há desfazer** — se a variante mais barata dá prejuízo, a oferta tem
de bloquear. Mutante **M09** (`orderByDesc`): **MORTO**.

**A simulação é PURA (FIN-11/FIN-12), com ISCA.**
`test_simular_nao_persiste_nada_e_a_isca_prova_que_a_contagem_sabe_subir` conta
`pub_aprovacao_composicoes` antes e depois de `simular()` (igual) **e** depois de `congelar()`
(**+1**) — sem a isca, um `count()` sempre igual passaria medindo nada. Mutante **M08** (simulação
persistindo): **MORTO**.

**FIN-13 — a irmã.** `comparacaoComAIrma()` liga por `rascunho_id` (o par é **estrutural**) e
devolve **`null`**, nunca zeros, quando não existe; testado nos **dois sentidos** (do Clássico se vê
o Premium e vice-versa).

**FIN-08 — a linha informativa.** Aparece como 8ª linha e **não** entra no `total`, provado por
remoção **e** com a informativa **carregando valor** (ver "Mutação" abaixo).

**O equilíbrio é TETO.** `c2` do protótipo reproduzido como `ceil($n * 100 - 1e-6) / 100`, com o
caso **discriminante** medido (ver abaixo).

**Desempenho (T-186-31).** A tarifa usa o cache de **1 h** de `publicador.alavancas.cache.tarifa`,
chaveado por categoria + tipo + preço em centavos. **Zero rede nos testes:**
`Http::preventStrayRequests()` + `Http::fake` alimentado pelas fixtures, com
`test_nenhuma_chamada_deste_plano_sai_para_a_rede_e_a_isca_prova_a_trava` afirmando que o único
caminho chamado é `/sites/MLB/listing_prices` e que o serviço **chamou** o ML (isca contra gate
vazio).

---

## ⚠️ A BIJEÇÃO ids ↔ MÉTODOS

`test_todo_metodo_do_plano_07_tem_id_no_manifesto_e_todo_id_casa_um_metodo`, por **script**, falhando
nos **dois sentidos**: método sem id (**nunca roda** — o sobrevivente da mutação do 186-03) e id
órfão (**gate sobre zero casos**). Com isca do casador nos dois sentidos (entende alternância, **não**
casa por prefixo) e piso de 40 métodos.

**Medição independente:** os três arquivos têm **53** métodos `test_`; a soma de `tests=` das **15**
linhas do manifesto é **53**. Fecha.

⚠️ **O nome leva o sufixo `_do_plano_07`, e isso é correção de um defeito MEDIDO:** o plano 186-06
tem um método de bijeção com o nome **genérico**, e o `--filter` do PHPUnit casa por nome em **toda**
a suíte. Rodado com o nome genérico, o gate devolveu **`2 passed (65 assertions)`** somando os dois
planos — a contagem do manifesto deixaria de medir este arquivo. É exatamente a armadilha
*"nome de método de guarda genérico COLIDE"*.

---

## ⚠️ A CAMPANHA DE MUTAÇÃO — 14 mutantes, 2 sobreviventes INVESTIGADOS

Disciplina: ocorrência **travada ANTES** (o script **recusa** se ≠ 1 — 0 recusados), **linha
conferida** e reportada, restauração por **sha256** dentro de **`finally`** (verificada: `git diff`
de `app/` e `config/` **vazio** depois da campanha).

| # | mutante | arquivo:linha | gate | 1ª rodada |
|---|---|---|---|---|
| M01 | lê `listing_fee_details` em vez de `sale_fee_details` | serviço:501 | `listing-fee-ignorado` | **MORTO** |
| M02 | `percentage_fee` como fração (sem `/100`) | serviço:581 | `split-da-tarifa` | **MORTO** |
| M03 | `gross_amount` como terceira parcela | serviço:596 | `split-da-tarifa` | **MORTO** |
| M04 | `fixed_fee: 0` tratado como ausente | serviço:589 | `split-da-tarifa` | **MORTO** |
| M05 | margem mínima só-parâmetro (o zero desligaria o bloqueio) | serviço:195 | `margem-minima` | **MORTO** |
| M06 | taxa `0.17` escrita à mão no lugar da API | serviço:581 | `sem-literal-de-taxa` | **MORTO** |
| M07 | jargão vazando como chave dos parâmetros | serviço:193 | `sem-literal-de-taxa` | **MORTO** |
| M08 | simulação PERSISTINDO | serviço:274 | `simulacao-e-congelamento` | **MORTO** |
| M09 | preço por variante pega o MAIOR | serviço:426 | `preco-de-variante` | **MORTO** |
| M10 | exceção da embalagem UNIFORMIZADA | composição:136 | `excecao-e-regra` | **MORTO** |
| M11 | linha informativa ENTRANDO na soma | composição:127 | `value-objects` | ⚠️ **SOBREVIVEU** |
| M12 | `c2` virou arredondamento em vez de TETO | composição:257 | `value-objects` | ⚠️ **SOBREVIVEU** |
| M13 | origem da linha vira parâmetro livre | `LinhaFinanceira`:69 | `value-objects` | **MORTO** |
| M14 | nota deixa de ser obrigatória | `LinhaFinanceira`:61 | `value-objects` | **MORTO** |

### ⚠️ Os dois sobreviventes eram LACUNA REAL — não defeito do mutante

**M11 — a informativa entrando na soma era INVISÍVEL.** O gate de FIN-08 usava a linha informativa
com `valor = null`, e `null ?? 0.0` soma **zero**: trocar o filtro por `array_values($linhas)` não
mudava o total. **Fechado** afirmando a mesma coisa com a informativa **carregando R$ 25,00** — o
caso que descontaria o frete duas vezes — mais `estimadoPorBaixo` **inalterado** (com o mutante, a
informativa nula marcava o resultado como estimado, que é afirmação falsa).

**M12 — o ponto cego era a própria isca.** Nos números do caso (soma fixa **250,90**, `vr` **0,30**,
mínimo **12%**) **teto e arredondamento dão o MESMO valor** (358,43 e 432,59), e as duas iscas
existentes mediam o **`$teto` DO TESTE**, não o `c2` da **produção**. **Fechado** com o caso
discriminante **medido**: soma fixa **70,01** com 11% + 19% e mínimo **10%** põe a terceira decimal
abaixo de 5 —

| | bruto | **teto (`c2`)** | `round()` |
|---|---|---|---|
| `equilibrio` | 100,01428571428573 | **100,02** | 100,01 |
| `precoAlvo` | 116,68333333333335 | **116,69** | 116,68 |

— e as asserções novas **provam a divergência** (`assertNotSame(round(...), ...)`), para o caso não
deixar de discriminar em silêncio.

**Reexecução dos dois com o conserto: MORTOS.** 14/14.

---

## Desvios do plano

### [Rule 3 — Bloqueio] O gate `composicao` virou 12 ids

**Achado na:** Task 2.
**Problema:** o `<verify>` pedia `sh tests/gates/verificar-testes.sh 07 composicao`, um único filtro
com os **29 métodos** do `ComposicaoContraOPrototipoTest`. **Medido:** uma alternância desse tamanho
equivale ao filtro de **classe** e **passa dos 120 s do shell** — a execução foi parar em background.
**Conserto:** 12 ids curtos (`split-da-tarifa`, `listing-fee-ignorado`, `tarifa-pendente`,
`sete-linhas`, `excecao-e-regra`, `margem-minima`, `simulacao-e-congelamento`, `irma-e-card`,
`preco-de-variante`, `tabelado-o1-a-o5`, `tabelado-o6-a-o10`, `zero-rede`).
**Por que NÃO é afrouxamento:** a própria `<regra_dos_gates>` do plano proíbe o filtro de classe por
esse exato motivo, e o helper **recusa** filtro que não comece por `test_`. **Nenhum método ficou de
fora** — a bijeção prova por script, nos dois sentidos, e a soma do manifesto (53) bate com a
contagem de métodos (53). Declarado no cabeçalho de `tests/gates/contagens-186-07.txt`.

### [Rule 1 — Bug] A chave `'andamento'` colidia com a única porta do 186-02

**Achado na:** verificação final (suíte completa: **1 failed**).
**Problema:** `test_o_servico_e_a_unica_porta_e_expoe_os_pontos_de_extensao_declarados`
(`tests/Unit/Publicador/Aprovacao/AndamentoDaOfertaServiceTest.php:883`) declara o invariante da
**única porta** do `andamento` com uma sonda **ampla de propósito**
(`/'andamento'\s*=>|->andamento\s*=[^=]/`) sobre **todo** o diretório do portão, mais um **contrato
exato** de dois arquivos. O autor daquele gate escreveu, no próprio comentário, que ele *"reprovou de
verdade e NÃO foi afrouxado"*. Minha `comparacaoComAIrma()` só **LÊ** o andamento, mas a chave
literal `'andamento' =>` do array de saída a fazia aparecer como **terceiro escritor**:
`[AndamentoDaOfertaService.php, ComposicaoFinanceiraService.php, PubAprovacaoOferta.php]`.
**Conserto, do LADO DE CÁ:** a chave virou **`andamento_da_irma`**. ⚠️ **O gate do 186-02 segue byte
a byte igual** (`git diff --stat` vazio nele) e continua pegando escrita de verdade. O nome novo
descreve melhor o valor (o estado da **IRMÃ**, não desta oferta), e o docblock registra o porquê com
`arquivo:linha` para ninguém renomear de volta.
**Depois:** 186-02 `1 passed (15 assertions)`; `irma-e-card` `2 passed (18 assertions)` — a **mesma**
contagem de antes.
⚠️ **PERGUNTA EM ABERTO para quem mantém o 186-02** (reportada, não decidida por mim): a sonda da
única porta não distingue **chave de array de saída** (leitura) de **escrita de estado**. O conserto
aqui foi local e não a toca, mas o próximo serviço do portão que precise expor o andamento vai bater
na mesma coisa. Se a intenção é permitir leitura, quem é dono daquele gate decide — eu não o
enfraqueci.

### [Nota de estrutura] Os casos do resolvedor foram testados na Task 2, não na Task 1

O `<behavior>` da Task 1 lista os casos da margem mínima, mas o `<action>` da mesma task diz
textualmente que *"o resolvedor mora no serviço de composição (Task 2)"*. Os casos foram testados
onde o resolvedor está (`ComposicaoContraOPrototipoTest`), com acesso a banco; o arquivo de Unit
ficou **puro** (sem `RefreshDatabase`), o que mantém os 13 métodos de value object rápidos.

### Nada mais

Nenhuma migration criada ou rodada. Nenhum pacote instalado. Nenhum serviço somente-leitura
alterado. Nenhuma chamada à API real do ML. Nenhuma leitura de produção. Nenhum deploy ou push.

---

## Território — conferido por RANGE `1a03b41f..HEAD`

`git diff 1a03b41f..HEAD --numstat -- <caminho>` com **0 linhas** em todos (com `test -e` antes):

| caminho | diff |
|---|---|
| `app/Support/Publicador/` (TERR-03) | **0** |
| `app/Services/Publicador/DadosEfetivosService.php` | **0** |
| `app/Support/Publicador/Validacao/SimuladorVoceRecebe.php` | **0** |
| `app/Services/Publicador/Alavancas/AnaliseAlavancasService.php` | **0** |
| `app/Models/EstruturaPrecificacaoParametros.php` | **0** |
| `app/Services/Publicador/EditorRascunhoService.php` | **0** |
| `app/Services/Publicador/Fila/ResumoRapidoService.php` | **0** |
| `app/Services/Creative/` | **0** |
| `resources/js/Pages/Mlb/Publicador/Editor.jsx` | **0** |
| `resources/js/Components/Publicador/Mesa/` | **0** |
| `app/Services/Mlb/Publicacao/MlPublicacaoService.php` | **0** |
| `resources/js/Layouts/AppLayout.jsx` | **0** |
| `.planning/ROADMAP.md` · `.planning/STATE.md` | **0** |
| `database/migrations/` | **0** — ⚠️ este plano **não tem migration** |

`config/publicador.php` é **ADIÇÃO PURA**: `git diff 1a03b41f..HEAD --numstat` = **33 adicionadas /
0 removidas**.

---

## Baselines medidas

| suíte | antes (informado no plano) | depois | veredicto |
|---|---|---|---|
| **PHP** (`--suite php`: `tests/Unit/Publicador` + `tests/Feature/Publicador`) | 2216 / 17.917 / 0 | **2269 / 18.717 / 0** | +53 testes, **0 falhas** |
| **JS** (`tests/js/**/*.test.js`, reporter `tap`) | 4777 / 4776 pass / 1 fail | **4777 / 4776 pass / 1 fail / 0 skipped** | **idêntica**; a falha é a pré-existente `tests/js/estrutura-grade-glide.test.js` (*"Características secundárias nasce recolhido"*, Fase 87) — **não corrigida**, como mandado |

**+53 testes** = exatamente os 53 métodos dos três arquivos novos.

⚠️ **Divergência de 1 asserção, declarada:** a soma de `assertions=` das 15 linhas do manifesto é
**799**, e a subtração contra a baseline informada daria **800**. Não investiguei: a baseline
`17.917` foi medida em **outro commit, por outra wave**, então a subtração não é régua confiável. A
**régua** são os 15 gates, cada um com número **EXATO** e todos `PASSOU`.

`sh tests/gates/verificar-testes.sh --autoteste`: **14 casos, 0 divergência.**

---

## Os 15 gates, medidos nesta máquina

| id | tests | assertions |
|---|---|---|
| `value-objects` | 13 | 121 |
| `split-da-tarifa` | 4 | 40 |
| `listing-fee-ignorado` | 1 | 7 |
| `tarifa-pendente` | 1 | 14 |
| `sete-linhas` | 3 | 47 |
| `excecao-e-regra` | 1 | 17 |
| `margem-minima` | 3 | 31 |
| `simulacao-e-congelamento` | 2 | 18 |
| `irma-e-card` | 2 | 18 |
| `preco-de-variante` | 2 | 13 |
| `tabelado-o1-a-o5` | 5 | 174 |
| `tabelado-o6-a-o10` | 5 | 175 |
| `zero-rede` | 1 | 4 |
| `sem-literal-de-taxa` | 9 | 93 |
| `bijecao-manifesto` | 1 | 27 |
| **total** | **53** | **799** |

⚠️ `tabelado-o1-a-o5` (174) e `tabelado-o6-a-o10` (175) diferem em **uma** asserção **de propósito**:
o estado `o4` tem `freight: null` e sai pelo **ramo curto** (propagação de `null`), que afirma menos
coisas que o ramo das invariantes. **Contagens iguais ali seriam o sinal** de que o `o4` parou de ser
o caso do frete ausente — está escrito no manifesto.

---

## Known Stubs

Nenhum stub introduzido. As duas linhas **sem fonte no repositório** (`embalagem` e `outros`) **não
são stubs escondidos**: são o comportamento **decidido pelo usuário** em 14/10/2026, visível na tela
(`Pendente` e `R$ 0,00 Confirmado`), com nota que nomeia a situação e com o resultado marcado
`estimadoPorBaixo`. Se um dia houver cadastro de custo de embalagem, a linha ganha fonte sem mudar a
régua.

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano. Dispositivos aplicados:
**T-186-26** (gate de ausência com borda nos dois lados + controle positivo de `SimuladorVoceRecebe`),
**T-186-27** (API fora do ar ⇒ `Pendente`, sem fallback numérico — testado com HTTP 500),
**T-186-28** (`simular()` puro, com isca), **T-186-29** (`margem_minima` + origem congeladas no ato),
**T-186-31** (cache de 1 h + `Http::preventStrayRequests()`), **T-186-SC** (nenhum pacote instalado).
**T-186-30** segue `accept`, pelo precedente já medido: o custo do cliente serve à tela interna atrás
da mesma permissão de publicação, e **nunca** ao portal do cliente.

## Conferência visual

⚠️ **Não há conferência de tela neste plano, e eu não posso afirmar ter visto tela renderizada.** A
composição é servidor; a tela é dos planos seguintes. A conferência visual é do usuário, em produção,
conta **459**.

---

## Self-Check: PASSED

**Arquivos (todos FOUND, com as linhas medidas):**
`LinhaFinanceira.php` 138 · `ComposicaoDaOferta.php` 299 · `ComposicaoFinanceiraService.php` **712**
(min_lines 180 ✅) · `ComposicaoFinanceiraServiceTest.php` 450 ·
`ComposicaoContraOPrototipoTest.php` **974** (min_lines 150 ✅) · `SemLiteralDeTaxaTest.php` 464 ·
`contagens-186-07.txt` 76 · `listing_prices_gold_special_preco_baixo.json` 40 ·
`config/publicador.php` 296 (2 ocorrências de `margem_minima`, `contains` ✅) · esta SUMMARY.

**Commits (todos FOUND em `git log --all`):**
`a7d827ea` (RED) · `27b752a9` (Task 1) · `2568c289` (Task 2) · `8af35b62` (Task 3) ·
`34d699dc` (colisão da única porta) · `e7767f7b` (sobreviventes da mutação).

**Gates:** 15/15 `PASSOU`, com os números do manifesto. `--autoteste`: 14 casos, 0 divergência.
**Suítes:** PHP 2269 / 18.717 / **0** · JS 4777 / 4776 pass / **1 fail pré-existente**.
**Mutação:** 14/14 **MORTOS** (os 2 sobreviventes eram lacuna real, fechados e reexecutados).
**Território:** 0 linhas de diff por range em todos os caminhos proibidos; **nenhuma migration**.
