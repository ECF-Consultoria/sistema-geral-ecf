---
phase: 180-publicacoes-lista
plan: 03
subsystem: api
tags: [publicador, publicacoes, busca, filtros, sub-status, escopo-multiempresa, mutacao, lista-fechada]

requires:
  - phase: 180-publicacoes-lista
    plan: "02"
    provides: "`PublicacoesDaListaService::mlItemIdsPorProduto(Company, string): list<string>` — a busca por nome/SKU de produto que começa em `pub_produtos` e nunca toca o acervo — e a constante pública `TETO_IDS_POR_BUSCA` (2000)"
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "`ml_acervo_itens` com `sub_status` (json), `listing_type_id`, `catalog_listing`, `sold_quantity`; o `escopo()`/`triagem()` originais e os 25 testes de `MeusAnunciosTest` que servem de régua"
  - phase: 173-visao-geral-do-publicador
    provides: "o `AcervoTriagemService` extraído de `MlbAnuncioController::meus()`, o predicado `comVenda` (`sold_quantity > 0`) e `motivosDef()` como fonte única dos 5 motivos"
provides:
  - "`AcervoTriagemService::escopo(Company, string $busca, string $statusFiltro, ?PublicacoesDaListaService $lista = null): Builder` — a busca com QUATRO identificadores (título, MLB, SKU do anúncio e nome/SKU do PRODUTO) e o eixo de status `com_erro`"
  - "`AcervoTriagemService::filtros(Builder, array): Builder` — `tipo` (lista fechada), `catalogo`, `com_vendas` e `ml_item_ids`, composável com qualquer chave de aba"
  - "`AcervoTriagemService::triagem(Company, string, string, array $filtros = [], ?PublicacoesDaListaService $lista = null): array` — chips sobre o MESMO universo da listagem"
  - "`AcervoTriagemService::STATUS_COM_ERRO`, `TIPO_CLASSICO`, `TIPO_PREMIUM` e `SUB_STATUS_COM_ERRO` — constantes públicas de lista fechada"
  - "a conclusão MEDIDA de que `ml_acervo_itens` não tem coluna de data de publicação, com a sonda e a isca"
  - "14 casos de teste em `tests/Feature/Publicador/AcervoBuscaEFiltrosTest.php` cobrindo os 18 cenários do plano + 2 extras, com 12 mutações pegas"
affects: [180-04, 180-08, 180-09]

tech-stack:
  added: []
  patterns:
    - "Eixo de filtro que NÃO cabe no `match` existente aplicado POR FORA, em E: `com_erro` é `sub_status`, não `status`, e pô-lo no `match` exigiria devolver `null` — o mesmo valor que significa 'não filtra' no `todos`. Os 6 arms ficaram byte a byte iguais"
    - "`filtros()` separado de `escopo()` justamente para que as contagens das 7 abas possam ser calculadas sobre os DEMAIS filtros: `filtros(escopo($c, $busca, <chave da aba>), $filtros)->count()`"
    - "`[]` distinguido de `null` em filtro de lista: `whereIn(col, [])` gera `0 = 1` e é a verdade ('casou com zero'); tratar `[]` como 'sem filtro' mostraria o acervo inteiro"
    - "Bool ESTRITO (`=== true`) em filtro que vem de querystring: a string `'false'` é truthy em PHP e `if ($filtros['catalogo'])` ligaria o filtro ao contrário do pedido. Há caso de teste com a string literal"
    - "Lista fechada com a FONTE de cada valor escrita ao lado, e os valores reais deliberadamente EXCLUÍDOS também escritos — string que o ML não emite é um `LIKE` morto que dá falsa sensação de cobertura"
    - "Mutação de escopo por MOVIMENTAÇÃO DE BLOCO em vez de troca de receptor: trocar `$s->` por `$q->` daria 'Undefined variable' e reprovaria por construção, não pelo vazamento. Mover o bloco para fora do grupo produz código válido com o vazamento real"
    - "Gate de mutação que precisa capturar o VEREDICTO: o `grep` do primeiro laço comeu o `Tests:` por causa do ANSI e a rodada inteira saiu sem evidência nenhuma. Tirar o ANSI com `sed 's/\\x1b\\[[0-9;]*m//g'` ANTES do grep"

key-files:
  created:
    - tests/Feature/Publicador/AcervoBuscaEFiltrosTest.php
    - .planning/phases/180-publicacoes-lista/180-03-SUMMARY.md
  modified:
    - app/Services/Publicador/AcervoTriagemService.php

key-decisions:
  - "RECUSADA a prescrição de acrescentar `com_erro` ao `match` de status: ele teria de devolver `null`, exatamente o valor que no `'todos'` significa 'não filtra'. Quem lesse o arm concluiria que a aba mostra o acervo inteiro. Virou eixo próprio aplicado por fora, em E — e os 6 arms do `match` ficaram byte a byte iguais, como o próprio plano exige"
  - "SUBSTITUÍDA a mutação (h) do plano ('triagem() passando a receber também o filtro de status'): ela é incoerente com o código, porque `triagem()` JÁ recebe o status desde a Fase 173-02 e a justificativa escrita no plano é sobre o filtro de MOTIVO. No lugar: (h) `triagem()` deixando de repassar `$filtros` e (k) deixando de repassar `$lista` — as duas reprovam"
  - "`triagem()` ganhou TAMBÉM um 5º parâmetro `?PublicacoesDaListaService $lista` (além do 4º do plano): sem ele a tela mostraria 1 linha achada pelo nome do produto e os chips diriam 0. É a mesma razão do `$filtros`"
  - "`filtros()` NÃO ganhou parâmetro `periodo` — medido que `ml_acervo_itens` não tem coluna de data de publicação. Melhor não ter o parâmetro do que ter um que mente"
  - "`SUB_STATUS_COM_ERRO` saiu da ÚNICA fonte escrita do projeto (`publicador-ml-spec/09-tratamento-de-erros.md:134`, derivada de [ML·S16]), não de memória. Os 3 sub-status REAIS que a varredura dos fixtures de produção encontrou (`cart_eligible`, `paused_by_seller`, `invalid_kit`) ficaram FORA de propósito, com a razão escrita"
  - "O filtro de `tipo` ignora valor fora da lista fechada em vez de cair no Clássico; `listing_type_id` desconhecido (`free`, `silver`) não pertence a tipo nenhum"
  - "ZERO migration, ZERO índice, ZERO pacote, ZERO linha de frontend"

patterns-established:
  - "Fechamento aritmético da suíte como gate: 2025 + 14 = 2039 testes e 15.313 + 105 = 15.418 asserções fecham EXATAMENTE — nenhum teste existente perdeu asserção"
  - "Sonda de schema com ISCA de controle positivo: ao provar que 3 nomes de coluna NÃO existem, pedir na mesma consulta a contagem de uma coluna que SABEMOS existir. Sem a isca, 'tudo zero' pode ser consulta quebrada"
  - "Conferência de que a correção de comentário não deslocou as linhas de mutação já medidas: contagem de linhas igual (530 → 530) + comparação linha a linha das 11 linhas alvo"

requirements-completed: [LIST-03, LIST-05, LIST-07, LIST-21, LIST-22, LIST-23, EXMP-01]

duration: 36min
completed: 2026-10-13
---

# Fase 180 Plano 03: a busca por nome de produto, os filtros do acervo e a aba "Com erro" — Resumo

**A busca do acervo passou a achar o anúncio pelo NOME DO PRODUTO sem que nenhum dos três braços de 10/10 saísse, os filtros de tipo/catálogo/vendas/ids ganharam um método composável que permite contar as 7 abas sobre os DEMAIS filtros, e a aba "Com erro" nasceu do `sub_status` do Mercado Livre — lista fechada, forma confirmada pelo usuário, zero honesto aceito.**

## Desempenho

- **Duração:** ~36 min (`d5b95bb4` em 16:56:48 → fim em ~17:33)
- **Tarefas:** 3 de 3
- **Arquivos criados:** 1 (893 linhas de teste)
- **Arquivos modificados:** 1 (`AcervoTriagemService.php`, 264 → 530 linhas)
- **Commits:** 5 — `4a474df2` (Task 1), `31255d95` (Task 2), `d1721e22` (Task 3), `ffb50904` (correção de 2 referências `arquivo:linha`) + o desta SUMMARY

## O que foi feito

### Task 1 — a busca ganhou o 4º identificador (`4a474df2`)

`escopo()` recebeu um **4º parâmetro opcional** `?PublicacoesDaListaService $lista = null`. Quando ele chega e o termo não é vazio, os `ml_item_id` cujo PRODUTO casa com o termo entram como `orWhereIn` **dentro** do grupo da busca, ao lado de `title`, `ml_item_id` e `skus`. Lista vazia não acrescenta braço nenhum.

Sem o parâmetro, o comportamento é **byte a byte o de hoje** — é isso que mantém os 4 chamadores existentes (`MlbAnuncioController::meus()`, `CockpitDaContaService::resumoDePublicacoes()`, `CabecalhoPublicadorService`, `MlbPublicadorEntradaController`) e os 25 testes de `MeusAnunciosTest` verdes **sem uma linha editada neles**.

### Task 2 — os filtros e o eixo "Com erro" (`31255d95`)

- **`filtros(Builder $q, array $filtros): Builder`** — `tipo` (lista fechada `classico`/`premium` → `gold_special`/`gold_pro`), `catalogo`, `com_vendas` (`sold_quantity > 0`) e `ml_item_ids` (`null` = sem filtro, `[]` = zero linhas).
- **O eixo `com_erro`**, aplicado por fora do `match`, em E com ele.
- **`triagem()`** recebeu `array $filtros = []` e `?PublicacoesDaListaService $lista = null`, e repassa os dois.

### Task 3 — o teste (`d1721e22`)

`tests/Feature/Publicador/AcervoBuscaEFiltrosTest.php`, **arquivo NOVO**: 14 métodos, 105 asserções, cobrindo os 18 cenários do plano mais 2 fora dele. `tests/Feature/Phase134/MeusAnunciosTest.php` **não foi tocado** — ele é a régua.

---

## (1) A conclusão MEDIDA sobre a coluna de data de publicação

**`ml_acervo_itens` NÃO tem coluna de data de publicação.** Por isso `filtros()` **não ganhou** o parâmetro `periodo`.

Medido de duas formas independentes:

| fonte | `arquivo:linha` | o que diz |
|---|---|---|
| migration de criação | `database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php:84-88` | as únicas datas declaradas são `detalhe_coletado_em` (`:84`), `coletado_em` (`:85`) e os `timestamps()` (`:88`) |
| `$fillable` do model | `app/Models/MlAcervoItem.php:80-81` | as únicas duas entradas de data são `detalhe_coletado_em` e `coletado_em` |
| migration aditiva única | `database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php` | acrescenta só `skus` (longText) |
| schema vivo (MariaDB 10.4 local) | `information_schema.columns` | 4 colunas de data/hora: `detalhe_coletado_em`, `coletado_em`, `created_at`, `updated_at` |

**A sonda carregou ISCA** (controle positivo), porque "tudo zero" também é o resultado de uma consulta quebrada:

```
candidato                        existe
published_at                     0
date_created                     0
publicado_em                     0
ISCA coletado_em (tem de ser 1)  1   <- a sonda SABE achar coluna que existe
```

⚠️ `created_at` **não** serve: é a data em que a COLETA criou a linha, não a da publicação. Este projeto já foi mordido por exatamente isso (`created_at` é artefato de reimport em massa, learnings). O filtro "Publicada [hoje/7/30]" entra pelo mesmo caminho da Fase e do Responsável: `ml_item_ids`, resolvido no **plano 180-04** a partir de `pub_publicacoes.concluida_em`.

## (2) `SUB_STATUS_COM_ERRO` — a lista fechada, a fonte de cada valor e a contagem medida

**Forma CONFIRMADA PELO USUÁRIO em 13/10/2026**, não presumida. Foram-lhe apresentadas três opções (inclusive usar "Pedem ação" para casar com a Visão Geral), **com o aviso explícito de que o `sub_status` pode ficar em zero permanente e a aba existiria sem nunca mostrar nada**. Ele escolheu **"'Com erro' de verdade, do `sub_status` do ML"**. Isso está escrito no docblock da constante, no código, com a data.

### A lista

| valor | significado | fonte |
|---|---|---|
| `waiting_for_patch` | moderado por infração, esperando correção do vendedor | ⚠️ **MEDIDO EM PRODUÇÃO**: 7 anúncios da conta real da #459 em 03–07/10, 6 por `DOMAIN` e 1 por `LENGUAJE` — `.planning/learnings/publicador-ml.md:1057-1060` |
| `forbidden` | anúncio proibido pelo ML | `.planning/publicador-ml-spec/09-tratamento-de-erros.md:134` [ML·S16] |
| `held` | retido pelo ML | idem |
| `pending_documentation` | bloqueado até o vendedor enviar documento | idem |
| `warning` | advertência de infração pendente (o mais brando dos cinco) | idem |

A fonte única dos quatro últimos é a tabela de pós-publicação do próprio projeto, que manda **alertar** em todos eles. ⚠️ **Nenhum valor foi acrescentado de memória**: string que o ML não emite é um `LIKE` morto que dá falsa sensação de cobertura.

### Os sub-status reais que ficaram FORA de propósito

Varredura dos **fixtures de payload real** capturados na sondagem da Fase 134 (`tests/Fixtures/phase134/*.json`) — os únicos três valores de `sub_status` que aparecem em dado real:

| valor | ocorrências | por que ficou fora |
|---|---|---|
| `cart_eligible` | 3 | elegibilidade de carrinho — não é problema nenhum |
| `paused_by_seller` | 2 | é o vendedor pausando: o anúncio está exatamente como ele quis |
| `invalid_kit` | 2 (em itens `closed` e `inactive`) | configuração de kit/pack inválida, não recusa nem suspensão. Incluí-lo faria "Com erro" significar duas coisas |

Os três têm **caso de teste negativo nomeado**, para que a próxima sessão não os "acrescente por parecerem erro".

### ⚠️ A contagem no banco local — medida, e o que ela NÃO prova

```
SELECT COUNT(*) FROM ml_acervo_itens;  ->  0
```

**`ml_acervo_itens` está VAZIA no banco local** (`ecf_admin`, MariaDB 10.4.32; `information_schema.tables` confirma `table_rows = 0`, e as duas tabelas do acervo estão vazias).

➡️ **A distribuição de `sub_status` distinto é IMPOSSÍVEL de medir nesta máquina**, e isto não é a mesma coisa que "medimos e é zero". É "não há o que medir aqui". A leitura de produção está bloqueada nesta sessão, então **não há número de produção nesta SUMMARY**. O que está medido é:

- o **schema** (a coluna existe, é json, nullable, e o coletor a preenche: `MlAcervoService::ATRIBUTOS_MULTIGET` `:70` e o upsert `:434`, que grava `[]` quando o ML não manda nada);
- os **3 valores reais** dos fixtures de produção acima;
- o **1 valor medido em produção** (`waiting_for_patch`), pelos learnings.

⚠️ **Consequência honesta para o usuário:** é perfeitamente possível que a aba "Com erro" apareça em **0** na primeira vez que ele a abrir. **Isso é o que ele aceitou em 13/10** e não é bug, não é motivo para trocar a fonte e não deve ser "consertado" acendendo a aba a partir de `severidade`. O teste `test_com_erro_sem_nenhum_item_casavel_devolve_zero_sem_lancar` cobra esse zero **com o acervo CHEIO**, para que o zero não seja vazio por construção.

## (3) O impacto escrito — chips e ordenação, no formato das emendas de 10/08 e 10/10

Escrito no corpo de `escopo()`, ao lado das emendas anteriores:

**IMPACTO NOS CHIPS (D-09).** Com `com_erro` selecionado, `MOTIVO_PAUSADO` e `MOTIVO_SEM_ESTOQUE` podem ficar em **0 por construção**: `AnuncioSaudeService::triagem()` só carimba o primeiro quando `status === 'paused'` e o segundo quando `status === 'active'`, e um anúncio moderado costuma estar `under_review`. **Isso é a verdade daquele universo, não bug** — exatamente o que a emenda do `em_revisao` já tinha documentado para si. Ficha/catálogo/foto continuam contando.

**IMPACTO NA ORDENAÇÃO (D-12).** Nenhum: a ordenação é por `severidade`/`nota_ecf`/`ml_item_id`, agnóstica de status e de `sub_status`. ⚠️ Consequência registrada: um anúncio moderado pode ter `severidade = 0` e cair no **fim** da lista dentro da própria aba "Com erro" — a aba é o recorte, a gravidade é outra medida.

**IMPACTO DO 4º BRAÇO DA BUSCA.** Nenhum por si: ele só pode AUMENTAR o conjunto, e `triagem()` conta sobre o MESMO escopo (por isso recebeu o `$lista`). Era o que já valia para os três braços antigos.

**CONSEQUÊNCIA QUERIDA, registrada:** `com_erro` não amarra `status` nenhum, então um anúncio pode estar em **"Em revisão" E em "Com erro" ao mesmo tempo**. São eixos diferentes, não abas rivais — há asserção explícita para isso.

## (4) "O que precisaria estar errado para este gate falhar?" — por caso

| # | método | o que precisaria estar errado |
|---|---|---|
| 1 | `test_as_tres_emendas_continuam_intactas` | `paused` ou `under_review` fora do default; o arm `em_revisao` removido; `closed` entrando no default; qualquer dos 6 arms mudando de conteúdo; o termo de status viajando para o SQL; a lista fechada do controller desaparecendo |
| 2 | `test_busca_por_titulo_mlb_e_sku_continua_valendo` | qualquer dos 3 braços antigos removido ou atropelado pelo 4º; a guarda `$buscaCabeNoJson` removida (os 4 termos de pontuação devolveriam o acervo inteiro); a guarda passando a depender do 4º braço |
| 3 | `test_busca_acha_pelo_nome_do_produto` | o braço do produto ausente; o `$lista` sendo ignorado; o 4º parâmetro deixando de ser opcional (o caso sem ele exige o conjunto antigo); produto sem publicação inventando linha |
| 4 | `test_braco_do_produto_respeita_o_filtro_de_status` | o braço do produto saindo do E com o status (um `closed` apareceria em `acionaveis`) |
| 5 | `test_busca_por_produto_nao_vaza_entre_empresas` | o `orWhereIn` fora do grupo (empresa A veria linha da B); o escopo do `mlItemIdsPorProduto` afrouxado; o `company_id` ausente |
| 6 | `test_filtro_de_tipo_usa_lista_fechada` | valor fora da lista virando filtro ou caindo no Clássico; `gold_pro`/`gold_special` crus sendo aceitos como `tipo`; `listing_type_id` desconhecido entrando num dos dois tipos |
| 7 | `test_filtros_de_catalogo_e_com_vendas` | `>= 0` em vez de `> 0`; `sold_quantity` nulo contando como venda; dois filtros virando OU; truthiness em vez de `=== true` (a string `'false'` ligaria o filtro) |
| 8 | `test_ml_item_ids_vazio_nao_e_ml_item_ids_nulo` | `[]` tratado como "sem filtro" — o acervo INTEIRO apareceria quando o filtro de Fase/Responsável não casou nada |
| 9 | `test_com_erro_vem_de_sub_status` | "Com erro" derivado de `severidade`, de `motivos` ou do título; confundido com `under_review`; os 3 sub-status inofensivos entrando; a lista fechada mudando sem decisão; o eixo amarrado a um `status` |
| 10 | `test_com_erro_sem_nenhum_item_casavel_devolve_zero_sem_lancar` | exceção com `sub_status` nulo; contagem `null` em vez de `0`; o braço casando por acidente num acervo sem erro |
| 11 | `test_contagens_das_abas_sao_sobre_os_demais_filtros` | `filtros()` não sendo aplicado (os dois conjuntos de 7 números seriam idênticos); `filtros()` mexendo no eixo de status; qualquer das 14 contagens literais mudando |
| 12 | `test_triagem_total_nao_e_a_soma_dos_chips_e_respeita_os_filtros` | `total` passando a ser a soma dos chips (daria 3, não 2); `triagem()` ignorando `$filtros` ou `$lista`; chave desconhecida (`motivo`) entrando na triagem |
| 13 | `test_custo_da_busca_por_produto_nao_cresce_com_o_acervo` | o acervo entrando no SQL da busca por produto; um join do publicador a partir do acervo; mais de 2 consultas; o custo mudando com 400 linhas a mais; o `IN` passando do teto público |
| 14 | `test_assinatura_de_escopo_e_aditiva` | o 4º/5º parâmetro deixando de ser opcional; a ordem dos parâmetros mudando; o serviço ganhando dependência de construtor (e com ela o `app()` que o docblock proíbe) |

## Prova por mutação — 12 aplicadas, 12 pegas, 0 sobreviventes

Cada uma: trava de contagem num script escrito em **ARQUIVO** (`180-03-mutar.php`, recusa alvo com ocorrência ≠ 1), impressão de **qual linha** muda ANTES de aplicar, teste designado rodado **por método**, restauração por cópia pristina e conferência por `sha256` — **igual nas 12** (`40c00d82…93df`).

| # | mutação | linha | teste que reprovou | asserções até falhar |
|---|---|---|---|---|
| a | o bloco do `orWhereIn` do produto MOVIDO para fora do grupo | 251 | `test_busca_por_produto_nao_vaza_entre_empresas` | 1 |
| b | tirar `'under_review'` do arm `acionaveis` | 209 | `test_as_tres_emendas_continuam_intactas` | 2 |
| c | tirar o arm `'em_revisao'` | 212 | `test_as_tres_emendas_continuam_intactas` | 6 |
| d | `if ($buscaCabeNoJson)` → `if (true)` | 244 | `test_busca_por_titulo_mlb_e_sku_continua_valendo` | 4 |
| e | `sold_quantity > 0` → `>= 0` | 355 | `test_filtros_de_catalogo_e_com_vendas` | 2 |
| f | `ml_item_ids` vazio passando a "não filtrar" | 365 | `test_ml_item_ids_vazio_nao_e_ml_item_ids_nulo` | 1 |
| g | `com_erro` passando a filtrar por `severidade` | 292 | `test_com_erro_vem_de_sub_status` | 1 |
| h | **(substituída)** `triagem()` deixando de repassar `$filtros` | 437 | `test_triagem_total_nao_e_a_soma_dos_chips_e_respeita_os_filtros` | 5 |
| i | **extra** `tipo` fora da lista caindo no Clássico | 343 | `test_filtro_de_tipo_usa_lista_fechada` | 3 |
| j | **extra** `=== true` → truthiness | 350 | `test_filtros_de_catalogo_e_com_vendas` | 6 |
| k | **extra** `triagem()` deixando de repassar `$lista` | 437 | `test_triagem_total_nao_e_a_soma_dos_chips_e_respeita_os_filtros` | 10 |
| l | **extra** tirar `'waiting_for_patch'` da lista fechada | 102 | `test_com_erro_vem_de_sub_status` | 1 |

**As 11 linhas alvo foram conferidas uma a uma e todas são linha de CÓDIGO, nenhuma é comentário** — este arquivo tem comentários longos que citam os próprios identificadores (`under_review`, `em_revisao`, `paused`, `waiting_for_patch`), e um `replace` cego pegaria o comentário.

Duas das 12 foram abertas para inspeção do MOTIVO da reprovação, não só do veredicto:

- **(a)** reprovou com `company_id` **2** aparecendo na busca da empresa **1** — o vazamento literal, com a segunda conta cheia no banco. Não reprovou por "variável indefinida".
- **(g)** reprovou devolvendo `['MLB-CRITICO-SEM-ERRO']` no lugar de `['MLB-ERRO', 'MLB-ERRO-2']` — derivar de `severidade` troca os dois erros reais por um item que não tem erro nenhum.

### ⚠️ A mutação (h) do plano foi SUBSTITUÍDA, com motivo

O plano prescreve "(h) `triagem()` passando a receber também o filtro de status → caso 18 reprova". **A prescrição é incoerente com o código:** `triagem()` **já recebe** o `$statusFiltro` como 3º parâmetro desde a Fase 173-02, e o próprio docblock dela diz que o que fica de fora é o filtro de **MOTIVO** (a justificativa que o plano escreveu ao lado é sobre motivo, não sobre status). Aplicá-la literalmente não produziria mutação nenhuma.

No lugar entraram duas que são load-bearing de verdade — **(h)** `triagem()` deixando de repassar `$filtros` e **(k)** deixando de repassar `$lista` —, as duas pegas. O filtro de MOTIVO continua fora por construção: `$filtros` não tem chave `motivo` e há asserção provando que a chave é ignorada.

### ⚠️ A mutação (a) não pôde ser a troca de receptor que o plano desenhou

O plano desenha `(a) $s->orWhereIn(...)` → `$q->orWhereIn(...)`. Mas `$q` **não está no escopo** do closure interno: a troca produziria `Undefined variable $q` e um `Error` fatal — o teste reprovaria **por construção**, não pelo vazamento. A mutação aplicada **move o bloco inteiro para fora do grupo**, produzindo código válido com o vazamento real. É a diferença entre uma mutação que prova e uma que finge.

### ⚠️ A primeira rodada de mutações foi um gate VAZIO por construção

O primeiro laço filtrava o resultado com `grep -E "^  Tests:"`, e os códigos ANSI do `artisan test` fizeram o grep comer **todas** as 12 linhas de veredicto. O log saiu com "RESTAURADA sha256 OK" nas 12 e **zero evidência de reprovação** — ou seja, teria passado por "12 mutações pegas" sem ter medido nada. Refeito tirando o ANSI com `sed 's/\x1b\[[0-9;]*m//g'` antes do grep. É a mesma família das nove asserções que casam sempre.

## Gates rodados

| gate | resultado |
|---|---|
| baseline PHP medida **ANTES** de qualquer edição | **2025 passed / 15.313 assertions / 0 falhando** (411,47 s) — bate exatamente com o briefing |
| suíte `tests/{Unit,Feature}/Publicador` depois | **2039 passed / 15.418 assertions / 0 falhando** (344,21 s) |
| **fechamento aritmético** | 2025 + **14** = 2039 ✓ e 15.313 + **105** = 15.418 ✓ — nenhum teste existente perdeu asserção |
| `MeusAnunciosTest` (a régua), **sem edição no arquivo** | 25 passed / 110 assertions |
| `CockpitDaContaTest` + `VisaoGeralTest` | 72 passed / 2.551 assertions |
| `AcervoTriagemServiceTest` (Unit, as emendas de 10/08 e 10/10) | 17 passed / 46 assertions |
| `test -e` nos 22 alvos de território | **21/22 existem**; 1 FANTASMA (ver abaixo) |
| controle POSITIVO do gate de diff | o mesmo comando vê `277/11` e `893/0` nos meus 2 arquivos |
| território proibido, range do plano `d5b95bb4..HEAD` | **VAZIO** nos 14 caminhos, inclusive `resources/js`, `tests/js`, `resources/css`, `tailwind.config.js`, `MlbAnuncioController.php`, `PublicacoesDaListaService.php`, `MeusAnunciosTest.php`, `database/migrations`, `ROADMAP.md`, `REQUIREMENTS-v26.md` |
| território, range da FASE `f23f610d..HEAD` | acusa `ROADMAP.md`, `tailwind.config.js` e 2 arquivos de `tests/js` — **trabalho legítimo do 180-01**, por isso o range do plano é o que vale |
| arquivos tocados no range do plano | exatamente **2** |
| deleções no range do plano | **0** |
| migrations novas | **0** |
| `composer.json`/`composer.lock`/`package.json`/`package-lock.json` | **0** |
| asserções REMOVIDAS em `tests/` | **0** (enumeradas: 93 linhas `$this->assert` acrescentadas, 14 métodos novos, 0 removidas) |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| mutações | 12 aplicadas, 12 pegas, 0 sobreviventes, 0 recusadas; `sha256` igual nas 12 |
| linhas de mutação depois da correção de comentário | 530 → 530 linhas, as 11 linhas alvo **idênticas** uma a uma |

### ⚠️ O 5º caminho fantasma da milestone

`resources/js/Pages/Mlb/Publicador/AnunciosHistorico.jsx` — citado no briefing como território proibido — **NÃO EXISTE**. O real é **`resources/js/Pages/Mlb/AnunciosHistorico.jsx`** (fora de `Publicador/`), ao lado de `resources/js/Pages/Mlb/anuncioHistoricoUtils.js`. Gate escrito com o caminho do briefing daria diff vazio e "provaria" território intocado. Os dois caminhos reais foram conferidos por `test -e` e medidos como intocados. Os 3º e 4º fantasmas (do 180-01) foram usados nos caminhos CERTOS e também deram vazio.

## O que NÃO foi feito (e por quê)

- **Nenhuma linha de frontend.** `resources/js`, `tests/js` e `resources/css` dão diff vazio no range do plano — a suíte JS não foi rodada porque nada de JS foi tocado, e a baseline JS sobe sozinha com o trabalho do outro dev.
- **Nenhuma tela vista renderizada.** Não há nenhuma afirmação de render neste plano.
- **Nenhum número de produção medido.** Leitura de produção bloqueada. As 1.080.206 linhas / 1.776 MB do acervo são medição anterior, **citada e não remedida**; o que foi medido aqui é que o acervo **não aparece** no SQL da busca por produto.
- **A distribuição de `sub_status` não foi medida** — a tabela local tem 0 linhas (ver seção 2). Isto é "não há o que medir aqui", **não** "medido e é zero".
- **Nenhuma migration, nenhum índice, nenhum pacote.** `listing_type_id`, `catalog_listing` e `sold_quantity` seguem sem índice de propósito: são filtros dentro da fatia já restrita por `company_id`, como o `LIKE` de hoje.
- **O controller, o `historico()`, a Visão Geral e o editor não foram tocados.**
- **`ROADMAP.md` e `REQUIREMENTS-v26.md` não tocados** — a marcação é do fechamento da fase, e `requirements.mark-complete` devolve `not_found` para IDs de `REQUIREMENTS-vNN.md`.
- **`state.advance-plan` NÃO executado** (já corrompeu o `STATE.md` seis vezes); bloco aditivo à mão.
- **Nada deployado, nada empurrado, nenhum comando no VPS.**

## Pendências deixadas para quem consome (plano 180-04)

1. ⚠️ **O `comVenda` do controller precisa passar a rotear por `filtros()`.** Hoje `MlbAnuncioController::meus()` repete o predicado `->where('sold_quantity', '>', 0)` inline (Fase 173-02). `filtros()` é a definição única a partir de agora; deixar os dois é deixar o predicado escrito duas vezes.
2. ⚠️ **`com_erro` tem de entrar na lista fechada de status do controller.** Hoje ela tem 6 valores e coage o resto para `acionaveis` — sem o 7º valor, a aba nova nunca chega ao serviço. O teste das três emendas cobra a lista pela **existência** do `in_array` e pelo arm `em_revisao`, de propósito, para não reprovar esse acréscimo legítimo.
3. ⚠️ **Fase, Responsável e Período entram todos por `ml_item_ids`**, resolvidos a partir do 180-02 (`pub_produtos.fase`, `pub_publicacoes.ator`, `pub_publicacoes.concluida_em`). **Lembrar que `[]` ≠ `null`:** quando o filtro não casar nenhuma publicação, passar `[]` (zero linhas), nunca `null`.
4. **As 7 contagens de aba** se fazem com `filtros(escopo($c, $busca, <chave da aba>, $lista), $filtros)->count()` — a chave da PRÓPRIA aba, nunca a selecionada.
5. **`catalogo` e `com_vendas` exigem bool de verdade** (`$request->boolean()`): a string `'false'` é truthy em PHP e o filtro **não** liga por truthiness de propósito.
6. **O rótulo é da tela.** O servidor manda a chave `com_erro`; "Com erro" é texto de UI e não aparece em nenhum payload.
7. **A ordenação não foi tocada** — um anúncio moderado com `severidade = 0` cai no fim da própria aba "Com erro". Se o usuário reclamar, é decisão de produto, não bug.

## Self-Check: PASSED

| verificação | resultado |
|---|---|
| `app/Services/Publicador/AcervoTriagemService.php` existe | ENCONTRADO (530 linhas) |
| `tests/Feature/Publicador/AcervoBuscaEFiltrosTest.php` existe | ENCONTRADO (893 linhas) |
| `.planning/phases/180-publicacoes-lista/180-03-SUMMARY.md` existe | ENCONTRADO |
| commit `4a474df2` existe | ENCONTRADO |
| commit `31255d95` existe | ENCONTRADO |
| commit `d1721e22` existe | ENCONTRADO |
| commit `ffb50904` existe | ENCONTRADO |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| as 11 linhas de mutação conferem com o arquivo atual | 11/11 conferidas por `sed -n` depois da correção do docblock |
| árvore limpa depois das 12 mutações | `git status --short` vazio nos 2 arquivos; `sha256` do serviço de volta a `40c00d82…93df` nas 12 restaurações. ⚠️ O `sha256` ATUAL é `4b6d4caa…37ec`, diferente **de propósito**: a correção de 2 referências `arquivo:linha` (`ffb50904`) veio DEPOIS das mutações. As 11 linhas alvo foram reconferidas uma a uma e nenhuma deslocou (530 → 530 linhas) |
| `STATE.md` aditivo | `git diff --numstat` = **15 inserções / 0 remoções**, e `diff` prova que o arquivo final é byte a byte `backup + bloco`, nada mais |
