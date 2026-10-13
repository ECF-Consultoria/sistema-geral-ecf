---
phase: 180-publicacoes-lista
plan: 02
subsystem: api
tags: [publicador, publicacoes, eloquent, joins, escopo-multiempresa, mutacao, custo-de-consulta]

requires:
  - phase: 172-publicador-etapa-1-navegacao
    provides: "o 4-join `pub_publicacao_itens -> pub_publicacoes -> pub_rascunhos -> pub_produtos` já exercitado em `MlbAnuncioController::historico()` (`:305-311`), com o grupo de escopo por empresa e o filtro `status = CREATED`"
  - phase: 175-fases-e-kits-no-publicador
    provides: "`pub_produtos.fase`, `quantidade_kit`, `estoque_calculado`, `produto_base_id` (migration `2026_10_08_120000_add_fases_to_pub_produtos.php`)"
  - phase: 179-visao-geral-cockpit
    provides: "os literais de autor desconhecido como FONTE ÚNICA (`PainelVisaoGeralService::QUEM_EQUIPE_SEM_NOME`/`QUEM_CLIENTE`/`QUEM_ORIGEM_ANTIGA`/`QUEM_SEM_AUTOR`) e o padrão de resolver autor em lote, sem `User::find()` por linha"
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "`MapaDeCobertura.jsx` e as 4 chaves de célula que este serviço tem de produzir"
provides:
  - "`PublicacoesDaListaService::metodologiaPorMlItemId(Company, array $mlItemIds): array` — mapa `ml_item_id => {produto_id, produto_nome, produto_sku, fase, quantidade_kit, estoque, autor_nome, autor_tipo, publicada_em, publicacao_id}`, UMA consulta, sem tocar `ml_acervo_itens`"
  - "`PublicacoesDaListaService::mlItemIdsPorProduto(Company, string $termo): list<string>` — a busca por nome/SKU de produto no servidor, começando em `pub_produtos`, com teto de 2000 ids"
  - "`PublicacoesDaListaService::coberturaPorProduto(Company, array $produtoIds): array` — `produto_id => {f1_classico, f1_premium, f2_classico, f2_premium, total}`, UMA consulta agregada"
  - "`PublicacoesDaListaService::TETO_IDS_POR_BUSCA` = 2000, público para o consumidor poder cobrá-lo em teste"
  - "a prova medida de que o caminho descrito no LIST-20 está ERRADO, com as duas provas de código"
  - "18 casos de teste em `tests/Feature/Publicador/PublicacoesDaListaTest.php`, com gate de SQL sem `ml_acervo_itens`, gate de custo que não cresce com o acervo e 10 mutações pegas"
affects: [180-03, 180-04, 180-08, 180-09, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "Escopo multi-empresa por SUBCONSULTA em vez de `->value('id')`: resolve a âncora `MlbEmpresa` dentro do mesmo SQL (UMA consulta) e cobre TODAS as âncoras da empresa — `mlb_empresas.company_id` não é unique, então `value('id')` escolhe uma arbitrária e esconde o produto ancorado na segunda"
    - "Reuso de regra de exibição SEM consulta extra: dois `leftJoin` + `PubProduto` não persistido com `setRelation('oferta'|'estruturaProduto')` permitem chamar `nomeExibido()`/`skuExibido()` (fonte única da regra) a custo zero de query"
    - "Gate de custo em DUAS dimensões: contagem de consulta por `assertCount` E varredura do SQL exigindo que a tabela grande NÃO apareça — contar consulta não prova que o acervo de 1.080.206 linhas ficou fora"
    - "Gate de contagem medido na SEGUNDA chamada seguida, para que cache vivo não faça o custo parecer menor (na Fase 178, 12 produtos custaram menos que 6)"
    - "Teste de `LIKE` que caracteriza o DRIVER em vez de fingir que SQLite e MySQL são iguais: caixa em termo ASCII é real nos dois; acento é do collation e o teste cobra o que cada banco realmente faz"
    - "Mutação com trava de contagem num script escrito em ARQUIVO (`mutar.php`), que recusa alvo com ocorrência != 1 e imprime QUAL linha mudou antes de aplicar"

key-files:
  created:
    - app/Services/Publicador/PublicacoesDaListaService.php
    - tests/Feature/Publicador/PublicacoesDaListaTest.php
    - .planning/phases/180-publicacoes-lista/180-02-SUMMARY.md
  modified: []

key-decisions:
  - "O LIST-20 descreve o caminho ERRADO e isso foi RECONFERIDO no código: `ml_acervo_itens.rascunho_id` aponta para `ml_anuncio_rascunhos` (assistente antigo), não para `pub_rascunhos`. O serviço usa a ponte real, `pub_publicacao_itens.ml_item_id`"
  - "RECUSADA a prescrição de resolver a âncora com `MlbEmpresa::...->value('id')`: ela custaria uma 2ª consulta (o mesmo plano exige UMA) e perderia a 2ª âncora da empresa. Virou subconsulta — superconjunto estrito, todas as linhas da MESMA `company_id`"
  - "RECUSADA a prescrição de devolver `data_get($ator,'nome')` sempre que for string não vazia: o `ator` do CLIENTE pelo Portal TEM nome gravado e T-173-09 fixou que nome/id de cliente não saem do servidor. Cliente vira o literal `Cliente`, de fonte única"
  - "`autor_tipo` mantém os DOIS valores do contrato (`pessoa`/`desconhecido`) — `Cliente` é rótulo genérico, do mesmo balde de `Equipe`, não nome de pessoa; nenhum plano consumidor precisa mudar"
  - "`produto_nome`/`produto_sku` saem de `nomeExibido()`/`skuExibido()`, não da coluna crua: em produto de origem `portal` a coluna é snapshot do nascimento, e a tela de Publicações mostraria nome velho enquanto Produtos mostra o novo"
  - "A busca cobre também `estrutura_ofertas.nome/sku` e `estrutura_produtos.nome/codigo` — ACRÉSCIMO ao prescrito, pela mesma razão: sem isso a busca não acha o que a tela mostra"
  - "`estoque` sai sempre `null` e a chave existe desde já: `estoque_calculado` é BOOLEANO, e a quantidade é do acervo (plano 180-04 preenche)"
  - "`coberturaPorProduto` devolve entrada para TODO id pedido (`total => 0`, 4 células `false`) — é verdade medida, não 'não sabemos', e id de outra empresa fica indistinguível de id inexistente (não confirma existência alheia)"
  - "ZERO migration, ZERO índice novo — a tabela de índices do plano foi reconferida contra as migrations e 5 das 6 referências estavam certas (2 números de linha divergiram em 1)"

patterns-established:
  - "Gate de território com DOIS ranges: o range da FASE (`f23f610d..HEAD`) acusou `tailwind.config.js` e `ROADMAP.md` — trabalho LEGÍTIMO do plano 180-01. O range deste plano (`b9737194..HEAD`) é o que mede território proibido"
  - "Controle POSITIVO em gate de diff vazio: o mesmo comando rodado sobre os arquivos do plano TEM de mostrar numstat — sem isso 'vazio' pode ser comando quebrado"
  - "Fechamento aritmético da suíte como gate: 2007 + 18 = 2025 testes e 14.983 + 330 = 15.313 asserções fecham EXATAMENTE, o que prova que nenhum teste existente perdeu asserção"

requirements-completed: [LIST-20, LIST-13, LIST-21, LIST-12, EXMP-01]

duration: 73min
completed: 2026-10-13
---

# Fase 180 Plano 02: `PublicacoesDaListaService` — Resumo

**O 4-join que leva um `ml_item_id` do acervo até o produto que o originou, a fase/kit em que nasceu e quem o publicou — numa consulta só, sem o acervo de 1.080.206 linhas entrar no SQL — mais a busca no sentido inverso e o mapa de cobertura F1/F2 × Clássico/Premium.**

## Desempenho

- **Duração:** ~73 min (b9737194 em 16:18:50 → fim em ~17:32)
- **Tarefas:** 3 de 3
- **Arquivos criados:** 2 (551 linhas de serviço, 899 de teste)
- **Arquivos modificados:** 0
- **Commits:** 4 (`dd64181a` Task 1, `249cbfad` Task 2, `96cd1b7d` correção de 3 referências `arquivo:linha` do docblock, + o desta SUMMARY)

## O que foi feito

### 1. `metodologiaPorMlItemId(Company, array $mlItemIds)`

UMA consulta: `whereIn('pub_publicacao_itens.ml_item_id', $ids)` + os 3 joins + `status = CREATED` + o grupo de escopo, com dois `leftJoin` de exibição. Devolve mapa indexado por `ml_item_id`.

- `ml_item_id` sem linha em `pub_publicacao_itens` fica **ausente** do mapa — é o Legado, e quem escreve a palavra é a tela.
- Mesmo `ml_item_id` em duas publicações: vence `concluida_em` mais recente, com `pub_publicacoes.id` como desempate.
- Entrada suja (`null`, número, array, string vazia, só-espaços) é descartada antes do bind; lista toda inválida devolve `[]` **sem consultar**.

### 2. `mlItemIdsPorProduto(Company, string $termo)`

Começa em `pub_produtos` (lado pequeno) e desce pelas FKs. Termo vazio/só-espaços devolve `[]` sem consultar. Teto de `TETO_IDS_POR_BUSCA` = 2000 com `distinct()`.

### 3. `coberturaPorProduto(Company, array $produtoIds)`

UMA consulta agregada (`groupBy` produto + fase + `listing_type_id` com `COUNT(*)`). `listing_type_id` desconhecido e `fase` fora de `{1,2}` contam no `total` e **não acendem célula nenhuma**.

---

## (1) A tabela de índices, RECONFERIDA contra as migrations

| aresta do join | coluna | índice | `arquivo:linha` medido | o plano dizia |
|---|---|---|---|---|
| acervo ↔ publicador | `pub_publicacao_itens.ml_item_id` | `pubpi_mlb_ix` | `2026_10_01_200000_create_publicador_tables.php:259` | `:260` — **divergiu em 1** |
| item → publicação | `pub_publicacao_itens.publicacao_id` | FK `pubpi_publicacao_fk` | `2026_10_01_200000_create_publicador_tables.php:239` | `:239` ✓ |
| publicação → rascunho | `pub_publicacoes.rascunho_id` | FK `pubpu_rascunho_fk` | `2026_10_01_200000_create_publicador_tables.php:221` | `:221` ✓ |
| rascunho → produto | `pub_rascunhos.produto_id` | unique `pubr_produto_uq` | `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:95` | sem linha ✓ |
| escopo do produto | `pub_produtos.company_id` | `pubprod_company_ix` | `2026_10_02_100000_create_pub_produtos_table.php:69` | `:70` — **divergiu em 1** |
| escopo do acervo | `ml_acervo_itens.(company_id, ml_item_id)` | unique `mai_company_item_unq` | `2026_08_11_090000_create_ml_acervo_itens_table.php:94` | `:94` ✓ |

**Conclusão confirmada: as 6 arestas já são indexadas. NENHUMA migration, NENHUM índice novo.** As duas divergências são de número de linha, não de existência — exatamente o que a §1 do learnings prevê ("as referências de linha envelhecem e deslocam; confirme pela semântica").

Aresta extra que o plano não listou e este serviço usa: `pub_produtos.oferta_id` → `estrutura_ofertas.id` e `pub_produtos.estrutura_produto_id` → `estrutura_produtos.id`, as duas FKs (`pubprod_oferta_*`/`pubprod_estrutura_produto_*`) criadas com as colunas. São `leftJoin` sobre chave primária do lado alvo — o lado dirigente continua sendo `pub_produtos`.

## (2) A divergência medida: LIST-20 × o caminho real

**LIST-20 descreve:** `ml_acervo_itens.rascunho_id` → `pub_rascunhos.produto_id` → `pub_produtos`.

**Está errado.** `ml_acervo_itens.rascunho_id` aponta para `ml_anuncio_rascunhos`, o assistente **antigo**. As duas provas, reconferidas:

1. `database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php:55-61` — o comentário da própria coluna: *"rascunho_id SEM foreignId/constrained de propósito: ... criaria dependência de ordem de drop entre **ml_anuncio_rascunhos** e esta tabela"*.
2. `app/Services/Mlb/Acervo/MlAcervoService::mapaRascunhos()` (`:522`) monta o mapa `ml_item_id → rascunho_id` a partir de `MlAnuncioRascunho::where('company_id', ...)` (`:526`) — não de `PubRascunho`.

**O caminho real**, o mesmo que `MlbAnuncioController::historico()` (`:305-311`) já percorre:

```
pub_publicacao_itens.ml_item_id            <- a ponte com o acervo
  -> pub_publicacoes  ON id = publicacao_id
  -> pub_rascunhos    ON id = rascunho_id
  -> pub_produtos     ON id = produto_id
WHERE pub_publicacao_itens.status = 'CREATED'
  AND (pub_produtos.company_id = ? OR pub_produtos.mlb_empresa_id IN (SELECT id FROM mlb_empresas WHERE company_id = ?))
```

## (3) O shape EXATO dos 3 retornos — o que 180-03/04/08/09 ligam

### `metodologiaPorMlItemId(Company $company, array $mlItemIds): array`

Chave do mapa: a **string** `ml_item_id`. Cada valor, com as 10 chaves **nesta ordem** (travado por `assertSame` sobre `array_keys`):

| chave | tipo | nota |
|---|---|---|
| `produto_id` | `int` | |
| `produto_nome` | `string` | de `PubProduto::nomeExibido()` — oferta/grupo vivo antes da coluna |
| `produto_sku` | `string` | de `PubProduto::skuExibido()` |
| `fase` | `int` | `pub_produtos.fase` (número da fase do Publicador) |
| `quantidade_kit` | `int` | |
| `estoque` | **sempre `null`** | ⚠️ o 180-04 preenche de `ml_acervo_itens.available_quantity` |
| `autor_nome` | `string` | nome real OU um dos 4 literais; **nunca** `'—'`, `null` nem `''` |
| `autor_tipo` | `string` | só `'pessoa'` ou `'desconhecido'` |
| `publicada_em` | `?string` | ISO 8601 ou `null` |
| `publicacao_id` | `int` | |

⚠️ **`ml_item_id` ausente do mapa é a resposta para "Legado".** Não existe entrada com `fase = 0` nem string `'Legado'` vinda do servidor.

Todos os valores são escalares ou `null` — varrido por asserção, não por inspeção.

### `mlItemIdsPorProduto(Company $company, string $termo): list<string>`

Lista de strings, únicas, no máximo `PublicacoesDaListaService::TETO_IDS_POR_BUSCA` (= 2000). Termo vazio/só-espaços → `[]` sem consultar. **A ordem não é contrato.**

Uso previsto pelo 180-03: `orWhereIn('ml_item_id', $ids)` **dentro** do grupo da busca do acervo.

### `coberturaPorProduto(Company $company, array $produtoIds): array`

Chave: `int` `produto_id`. **Todo id pedido recebe entrada** (inclusive id de outra empresa e id inexistente — os dois com `total => 0`). Cada valor, nesta ordem:

`['f1_classico' => bool, 'f1_premium' => bool, 'f2_classico' => bool, 'f2_premium' => bool, 'total' => int]`

As 4 primeiras são **exatamente** as de `resources/js/Components/Mlb/Publicador/identidade/MapaDeCobertura.jsx:24-28` — conferidas no componente, não presumidas. Mapeamento: `gold_special` = Clássico, `gold_pro` = Premium; **qualquer outro valor conta no `total` e não acende célula**, e `fase` fora de `{1,2}` idem.

## (4) "O que precisaria estar errado para este gate falhar?" — por caso novo

| # | caso | o que precisaria estar errado |
|---|---|---|
| 1 | `test_metodologia_vem_do_dado_real` | o join errado (nome/SKU/fase de outro produto), o acervo entrando no SQL, mais de 1 consulta, chave fora do contrato, entrada para `ml_item_id` sem publicação, campo objeto, ou token no payload |
| 2 | `test_custo_nao_cresce_com_o_tamanho_do_acervo` | a consulta ler o acervo (2.000 linhas a mais mudariam a contagem ou o resultado), ou cache escondido fazendo a 2ª chamada custar menos |
| 3 | `test_autor_nunca_e_inventado` | qualquer dos 5 shapes de `ator` cair no literal errado, devolver `'—'`/`''`/`null`, herdar o nome de outra publicação, ou vazar o nome do cliente |
| 4 | `test_mesmo_anuncio_em_duas_publicacoes_vence_a_mais_recente` | ordenação ascendente, ordenação por id em vez de data, ou duas entradas para o mesmo `ml_item_id` |
| 5 | `test_entrada_suja_nao_lanca_e_consulta_so_o_id_valido` | sanitização ausente (número/array virando bind), exceção, ou consulta disparada com lista toda inválida |
| 6 | `test_status_que_nao_e_created_fica_fora` | o filtro `status = CREATED` ausente ou trocado por outro status |
| 7 | `test_nome_do_produto_de_portal_segue_a_oferta_viva` | ler a coluna crua em vez de `nomeExibido()`, ou pagar uma 2ª consulta para ler a oferta |
| 8 | `test_busca_acha_pelo_nome_do_produto` | busca por título em vez de produto, vazamento da empresa B, termo interpolado em vez de bind, ou mais de 1 consulta |
| 9 | `test_busca_ignora_caixa_e_o_acento_depende_do_collation` | normalização em PHP (que quebraria o termo acentuado), ou `LIKE` só no SKU |
| 10 | `test_busca_com_termo_vazio_nao_consulta` | a guarda do termo vazio ausente (o `LIKE '%%'` devolveria a empresa inteira **e** apareceria no log) |
| 11 | `test_busca_ignora_status_que_nao_e_created` | o filtro de status ausente no caminho da busca (é um caminho diferente do da metodologia) |
| 12 | `test_busca_respeita_o_teto_de_dois_mil_ids` | `limit` ausente ou acima de 2000 |
| 13 | `test_cobertura_por_produto_acende_so_as_celulas_reais` | chave com nome diferente das 4 do componente, tipo desconhecido caindo no Clássico, fase 7 acendendo célula, produto alheio trazendo cobertura, ou mais de 1 consulta |
| 14 | `test_cobertura_soma_o_total_e_custa_uma_consulta_so` | o `total` não somar os itens, ou o custo crescer com o número de produtos pedidos |
| 15 | `test_cobertura_com_entrada_suja_nao_lanca` | `'abc'`/`null`/`0`/negativo virando bind, ou exceção |
| 16 | `test_escopo_por_empresa_nao_vaza` | o `OR` do escopo desagrupado, a subconsulta da âncora sem filtro, ou o escopo ausente em qualquer dos 3 métodos |
| 17 | `test_empresa_sem_ancora_mlb_resolve_pelo_company_id` | a subconsulta da âncora sem `where company_id` (produto de âncora alheia entraria), ou uma 2ª consulta para resolver a âncora |
| 18 | `test_nome_de_cliente_nunca_sai_no_payload` | devolver `ator.nome` para `equipe = false` — exatamente o que o plano prescrevia |

## Prova por mutação — 10 aplicadas, 10 pegas

Cada uma: trava de contagem (recusa se o alvo aparece ≠ 1 vez), impressão de **qual linha** mudou, teste designado rodado por **método**, restauração por cópia pristina e conferência por `sha256` (`cf62a849…4704`, igual nas 10 restaurações).

| # | mutação | linha | teste que reprovou |
|---|---|---|---|
| a | `->where(function ($q) use ($company) {` → `->orWhere(…)` | 393 | `test_escopo_por_empresa_nao_vaza` |
| b | tirar `status = PubPublicacaoItem::CREATED` | 392 | `test_status_que_nao_e_created_fica_fora` |
| c | `'gold_pro' => self::TIPO_PREMIUM,` → `'gold_premium' => …` | 323 | `test_cobertura_por_produto_acende_so_as_celulas_reais` |
| d | `QUEM_SEM_AUTOR` → `QUEM_EQUIPE_SEM_NOME` | 484 | `test_autor_nunca_e_inventado` |
| e | `if ($termo === '') {` → `if (false) {` | 212 | `test_busca_com_termo_vazio_nao_consulta` |
| f | `orderByDesc('pub_publicacoes.concluida_em')` → `orderBy(…)` | 149 | `test_mesmo_anuncio_em_duas_publicacoes_vence_a_mais_recente` |
| g | tirar `->limit(self::TETO_IDS_POR_BUSCA)` | 233 | `test_busca_respeita_o_teto_de_dois_mil_ids` |
| h | `nomeExibido()` → coluna crua `$linha->produto_nome` | 165 | `test_nome_do_produto_de_portal_segue_a_oferta_viva` |
| i | **obedecer a prescrição do plano** e devolver `ator.nome` ao cliente | 494 | `test_nome_de_cliente_nunca_sai_no_payload` |
| j | subconsulta da âncora sem `->where('company_id', …)` | 396 | `test_empresa_sem_ancora_mlb_resolve_pelo_company_id` |

As 7 primeiras são as do plano; **h/i/j são extras**, criadas para guardar as três divergências deliberadas abaixo — sem elas, as decisões não teriam gate. **Nenhuma sobreviveu**, logo não houve sobrevivente a investigar.

## Divergências do plano — recusadas e justificadas

### 1. A âncora `MlbEmpresa` por subconsulta, não por `->value('id')`

O plano prescrevia `MlbEmpresa::where('company_id', $company->id)->value('id')`, como `historico()` faz. Recusado por duas razões medidas:

- é uma **segunda consulta**, e o mesmo plano exige (e o teste cobra) UMA por método;
- `mlb_empresas.company_id` **não é unique** (`2026_05_25_100002_add_company_id_to_mlb_empresas.php:18-19` cria a coluna com FK `nullOnDelete` e sem unique). Com `value('id')`, empresa com duas `MlbEmpresa` tem o produto ancorado na segunda **invisível**.

A subconsulta é superconjunto estrito e **todas** as linhas que ela acrescenta são da mesma `company_id` — o escopo não afrouxa, e a mutação (j) prova que tirar o `where` dela reprova. Empresa sem âncora nenhuma: a subconsulta sai vazia e o braço não casa nada, igual ao braço ausente do `historico()` (caso 17).

### 2. Nome do CLIENTE não sai do servidor (T-173-09)

O plano prescrevia `autor_nome = data_get($ator, 'nome')` sempre que fosse string não vazia. Mas `PublicacaoService::atorParaGravar()` (`:758-761`) grava `['equipe' => bool, 'id' => int, 'nome' => string]` **também para o cliente do Portal** — e a Fase 179 fixou (T-173-09, `PainelVisaoGeralService:158-164`) que nome/id de cliente não saem para a tela. Obedecer vazaria nome de cliente numa tela interna.

Decisão: `equipe !== true` com `id` presente → literal `PainelVisaoGeralService::QUEM_CLIENTE`. **`autor_tipo` continua com só dois valores** (`pessoa`/`desconhecido`), porque `Cliente` é rótulo genérico do mesmo balde de `Equipe`/`Origem antiga` — **nenhum plano consumidor precisa mudar o contrato**.

### 3. Nome/SKU de exibição, e a busca cobrindo-os

`pub_produtos.nome` é snapshot do nascimento em produto `origem = portal` (`PubProduto::daOferta()` copia da oferta). Devolver a coluna crua faria a tela de Publicações mostrar o nome velho enquanto Produtos mostra o novo. Dois `leftJoin` + `PubProduto` não persistido com `setRelation` permitem chamar `nomeExibido()`/`skuExibido()` — a fonte única da regra — **sem consulta extra** (provado no caso 7). Pela mesma razão a busca cobre `estrutura_ofertas.nome/sku` e `estrutura_produtos.nome/codigo`: sem isso ela não acharia o que a tela mostra.

## Decisão sobre acento na busca — medida, não presumida

O plano afirma que "ignora acento e caixa" é do collation do banco, e que normalizar em PHP daria **menos** resultados. Confirmado e implementado assim. Mas o SQLite dos testes **não** emula isso, e isso foi **medido**:

- `LIKE` do SQLite dobra **só ASCII**: `'poltrona'` e `'POLTRONA'` acham `'Poltrona Oslo'`;
- `'luminaria'` **não** acha `'Luminária'` no SQLite; `'Luminária'` acha.

Em vez de escrever um caso que falharia (ou de fingir equivalência), o teste `test_busca_ignora_caixa_e_o_acento_depende_do_collation` assere o que é real nos dois bancos e **caracteriza o driver** no caso do acento, com a razão escrita na mensagem da asserção. Em MySQL/MariaDB (`utf8mb4_0900_ai_ci`/`utf8mb4_general_ci`) o mesmo teste cobra a busca accent-insensitive pelo outro braço do `if`.

⚠️ **Isso NÃO foi medido em produção** — a leitura de produção está bloqueada nesta sessão. O que está medido é o comportamento do SQLite e a prescrição do plano sobre o collation de produção.

## Gates rodados

| gate | resultado |
|---|---|
| suíte `tests/{Feature,Unit}/Publicador` | **2025 passed / 15.313 assertions / 0 falhando** (410s) |
| baseline medida ANTES de qualquer edição | 2007 passed / 14.983 assertions / 0 falhando (432s) |
| fechamento aritmético | 2007 + **18** = 2025 ✓ e 14.983 + **330** = 15.313 ✓ — nenhum teste existente perdeu asserção |
| `test -e` nos 15 alvos de território | 15/15 EXISTEM — **zero caminho fantasma** |
| controle positivo do gate de diff | o mesmo comando vê `551/0` e `899/0` nos meus 2 arquivos |
| território proibido, range do plano `b9737194..HEAD` | **VAZIO** |
| território proibido, range da fase `f23f610d..HEAD` | acusou `tailwind.config.js` e `ROADMAP.md` — **trabalho legítimo do 180-01**, por isso o range do plano é o que vale |
| migrations novas em `b9737194..HEAD` | **0** |
| deleções em `b9737194..HEAD` | **0** |
| `resources/js`, `tests/js`, `resources/css` | intocados (dentro do range do plano) |
| mutações | 10 aplicadas, 10 pegas, sha256 igual nas 10 restaurações |

## O que NÃO foi feito (e por quê)

- **Nenhuma linha de frontend.** Este plano é só servidor e teste; `resources/js` está no diff vazio.
- **Nenhum número de produção medido.** A leitura de produção está bloqueada nesta sessão. As 1.080.206 linhas / 1.776 MB de `ml_acervo_itens` são a medição anterior, citada pelo plano, **não remedida aqui** — o que foi medido é que o acervo **não aparece** no SQL gerado.
- **Nenhuma tela vista renderizada.** Não há afirmação de render neste plano.
- **Nenhuma migration, nenhum índice, nenhum pacote.**
- **`STATE.md`** atualizado por bloco aditivo à mão (`advance-plan` não foi rodado — já corrompeu o arquivo seis vezes). `ROADMAP.md` e `REQUIREMENTS-v26.md` **não** tocados: a marcação é do fechamento da fase.

## Pendências deixadas para quem consome

1. **`estoque` chega `null` de propósito** — o 180-04 tem de preenchê-lo de `ml_acervo_itens.available_quantity`, que ele já tem na mão. Se ninguém preencher, a linha de grupo mostra "Estoque —", que é honesto, não um zero inventado.
2. **"Legado" é AUSÊNCIA de entrada no mapa** — quem desenha a palavra é a tela (180-04/08). Não há string `'Legado'` vinda do servidor.
3. **Margem/Preço não vêm daqui** (LIST-12) — é a Fase 181, assíncrona. Nenhum campo deste serviço carrega preço.
4. **`TETO_IDS_POR_BUSCA` é público** para o 180-03 poder cobrar o teto no próprio teste em vez de repetir o número.
5. **A dívida de N+1 da Fase 179** (`PainelVisaoGeralService::resolverAtor()`) continua aberta; este serviço **não** a repete (nenhum `User::find()`), mas também não a conserta — não é território deste plano.

## Self-Check: PASSED

| verificação | resultado |
|---|---|
| `app/Services/Publicador/PublicacoesDaListaService.php` existe | ENCONTRADO (551 linhas) |
| `tests/Feature/Publicador/PublicacoesDaListaTest.php` existe | ENCONTRADO (899 linhas) |
| `.planning/phases/180-publicacoes-lista/180-02-SUMMARY.md` existe | ENCONTRADO |
| commit `dd64181a` existe | ENCONTRADO |
| commit `249cbfad` existe | ENCONTRADO |
| commit `96cd1b7d` existe | ENCONTRADO |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| as 10 linhas de mutação reportadas acima conferem com o arquivo atual | 10/10 conferidas por `grep -n` **depois** da correção do docblock (que trocou 5 linhas por 5 e não deslocou nada) |
| `STATE.md` aditivo | `git diff --numstat` = **15 inserções / 0 remoções** |
| `ROADMAP.md` / `REQUIREMENTS-v26.md` | diff vazio no range do plano — a marcação é do fechamento da fase |
