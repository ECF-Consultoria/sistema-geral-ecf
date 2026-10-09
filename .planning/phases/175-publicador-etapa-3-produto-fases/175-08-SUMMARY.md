---
phase: 175-publicador-etapa-3-produto-fases
plan: 08
subsystem: api
tags: [publicador, kits, fases, presenter, contagem, visao-geral, vinculo, escopo-por-conta, phpunit]

# Dependency graph
requires:
  - phase: 175-01
    provides: "colunas `produto_base_id`, `quantidade_kit`, `fase`, `estoque_calculado`, `kit_sugestao_recusada_em` e o unique `pubprod_base_qtd_uq`"
  - phase: 175-02
    provides: "`PubProduto::ehKit()/base()/kits()/familia()/proximaFase()` e o `CriarFaseService` (que é o contraponto do vínculo)"
  - phase: 175-03
    provides: "`SugestaoDeKitService::sugerirPara()` e `candidatosDaConta()` — quem DECIDE se um produto é kit de outro"
  - phase: 175-04
    provides: "a rota nomeada `mlb.anuncios.publicador.produto` (destino de `url_produto`) e o `FamiliaDeFasesService`"
  - phase: 175-05
    provides: "`PreviaDaFaseService::MAX_QUANTIDADE` e o padrão de recusa `RegraViolada` → 422 `{message, regra, campo}` do `MlbPublicadorFaseController`"
  - phase: 173 (Etapa 2)
    provides: "`PainelVisaoGeralService` (indicadores, oQueFazerAgora, situacaoProdutos, ultimasPublicacoes) e `ProgramasPublicadorService::contagemProdutos()`"
  - phase: 164 (Etapa 1)
    provides: "`ProgramasPublicadorService::produtosParaTela()/produtosQuery()` — o presenter da lista e o escopo por conta (D-13)"
provides:
  - "`produtosParaTela(?MlbEmpresa, ?Company, ?string $chaveConta = null)` com 9 chaves novas ADITIVAS: `fase`, `quantidade_kit`, `produto_base_id`, `eh_kit`, `rotulo_fase`, `url_produto`, `sugestao_kit`, `kits`, `base`"
  - "`contagemProdutos()` com o bucket aditivo `por_fase{sem_oferta, fase1_publicada, fase2_preparacao, fase2_publicada, fase3_mais}` que soma exatamente `todos`"
  - "`ProgramasPublicadorService::rotuloFase(?int): string` — fonte única de '1 unidade' / 'Kit N'"
  - "`ProgramasPublicadorService::STATUS_NO_AR` — as duas chaves de prontidão que valem 'tem anúncio no ar'"
  - "`App\\Services\\Publicador\\VinculoDeKitService::vincular()/desvincular()/recusar()` — grava três colunas e nada mais"
  - "3 endpoints: `publicador.vinculo.salvar` (PUT), `publicador.vinculo.remover` (DELETE), `publicador.vinculo.recusar` (POST)"
  - "`PainelVisaoGeralService::produtosPorFase()` — bloco NOVO, ao lado de `situacaoProdutos()` (que ficou intacto)"
  - "'Prontos para a Fase 2' contando base publicado SEM NENHUM kit, com destino `?filtro=publicados&fase=so_base`"
  - "`ultimasPublicacoes()` com `fase` e `rotulo_fase` por item"
  - "Prop nova `produtosPorFase` na Visão geral da conta"
affects: [175-10, 176]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Presenter em produção só cresce: chave nova é ADITIVA e as antigas têm gate de regressão por array literal (nunca comparado contra o próprio código)"
    - "Toda chave nova de presenter consumido por React tem prova de FORMATO (escalar quando é escalar) — a lição da tela preta de 07/10"
    - "Família resolvida EM MEMÓRIA sobre a coleção que a consulta de escopo já trouxe: o kit herda as âncoras do base, então os dois estão sempre no mesmo escopo"
    - "Contagem derivada tem FONTE ÚNICA: a Visão geral nunca reconta, lê a mesma contagem da lista — 'os números batem' por construção, não por coincidência"
    - "Serviço que só registra parentesco se defende com `getDirty()`: se alguém acrescentar uma escrita, a própria operação recusa"
    - "Mudança de semântica em presenter compartilhado tem degrau de compatibilidade: sem os dados novos, o número volta a ser o de hoje (a linha nunca desaparece)"
    - "Dependência circular entre serviços resolvida por `app()` sob demanda, documentada no ponto onde o ciclo se fecharia"

key-files:
  created:
    - app/Services/Publicador/VinculoDeKitService.php
    - tests/Feature/Publicador/ListaPorFaseTest.php
    - tests/Feature/Publicador/VinculoDeKitTest.php
  modified:
    - app/Services/Publicador/ProgramasPublicadorService.php
    - app/Services/Publicador/PainelVisaoGeralService.php
    - app/Http/Controllers/MlbPublicadorFaseController.php
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - routes/mlb_anuncios.php
    - tests/Feature/Publicador/MlbPublicadorProdutosTest.php

key-decisions:
  - "Os DOIS blocos convivem: `situacaoProdutos` (Etapa 2, em produção) continua intacto e `produtosPorFase` nasce ao lado. A §7 da spec manda substituir; a regra inviolável 'nada que existe pode sumir' vence. Se o usuário preferir substituir de fato, é um ajuste de uma linha no 175-10."
  - "`SugestaoDeKitService` é resolvido PREGUIÇOSAMENTE dentro de `produtosParaTela()`, não pelo construtor como o PLAN pedia: `SugestaoDeKitService::__construct` recebe `ProgramasPublicadorService`, e injetar o inverso fecha ciclo no container (recursão infinita no resolve)."
  - "Os endpoints de vínculo usam o produto DA URL (`produtoDaConta()`), não o `baseDaConta()` que o PLAN indicava: `baseDaConta()` devolve `$p->base ?? $p` e, num kit, desvincularia/recusaria o produto ERRADO."
  - "A família sai em memória, sem o `whereIn('produto_base_id')` que o PLAN previa: o kit herda as âncoras do base, então ele JÁ está na coleção que `produtosQuery()` trouxe. Zero consulta nova."
  - "`oferta` entrou no eager load de `produtosParaTela()`: `skuExibido()`/`nomeExibido()` liam a relação por linha (N+1 pré-existente) e o `base` de cada kit multiplicaria isso. Mesma saída, menos consultas."
  - "'Prontos para a Fase 2' tem degrau de compatibilidade: a regra nova só se aplica quando `$produtos` é a lista CIENTE DE FASE. Sem `eh_kit`, o número volta a ser `contagemProdutos['publicados']` — a linha nunca desaparece por falta de dado, e nenhuma suíte da Etapa 2 precisou mudar de expectativa."
  - "`por_fase['sem_oferta']` NÃO é o `sem_oferta` de cima: o de cima é `oferta_id === null` (indicador da Visão geral), o novo é 'sem nenhum anúncio no ar' (o rótulo 'Sem oferta' da §7). Nomes coincidem por vir de documentos diferentes; os dois números são diferentes de propósito."
  - "`VINC-05` e `VINC-06` (recusas que o PLAN não listava) fecham a cadeia pelo outro lado: produto que JÁ é base de outras fases, ou que JÁ está vinculado, não pode ser vinculado — senão nasce a cadeia de kits que a §2 proíbe."
  - "O vínculo escreve `estoque_calculado = false` explicitamente, mesmo sendo o default: é a afirmação da §6 ('o combo mantém o próprio estoque') e o que mantém o kit vinculado FORA do `RecalculoEstoqueDoKitService`."

patterns-established:
  - "Gate de regressão de contrato: `const CHAVES_DE_HOJE` num array literal no teste, não `array_keys()` do retorno — comparar com o próprio código não prova nada"
  - "Igualdade estrita de array inteiro em teste de presenter é incompatível com chave aditiva: provar bucket por bucket"
  - "Qualificar `pub_produtos.fase` em TODO select e apelidar na projeção (`as produto_fase`): `estrutura_ofertas.fase` é o tipo da oferta, e o MariaDB resolve `fase` calado e errado"

requirements-completed: [FASE-09, FASE-10, FASE-11]

# Metrics
duration: 35min
completed: 2026-10-09
---

# Fase 175 Plano 08: Impacto nas Etapas 1 e 2 (lado servidor) — Summary

**A lista de produtos e a Visão geral passaram a conhecer fases sem perder uma chave — 9 campos novos na lista, 5 buckets novos de contagem de fonte única, e o vínculo de combo que grava três colunas e prova, byte a byte, que não toca rascunho, estoque nem MLB.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-10-09 ~09:05 -03
- **Completed:** 2026-10-09 09:37 -03
- **Tasks:** 3 de 3
- **Files modified:** 9 (3 criados, 6 modificados)

## Accomplishments

- **A lista ficou ciente de fase sem custo de query.** `produtosParaTela()` ganhou 9 chaves e o número de consultas é **o mesmo com 3 e com 12 produtos** (provado por `DB::enableQueryLog()`); a família sai em memória e a sugestão de vínculo sai numa passada por conta. Como efeito colateral, um N+1 pré-existente (`skuExibido()` lendo `oferta` por linha) morreu.
- **Contagem por fase de fonte única.** `por_fase` nasce no MESMO laço de `contagemProdutos()` e a Visão geral só coloca rótulo em cima — o critério "a Visão geral por fase bate com a lista" é verdadeiro por construção. A soma dos 5 buckets é exatamente `todos`, com teste.
- **O vínculo de combo, com a prova que a §9 pede.** `PUT/DELETE vinculo` e `POST vinculo/recusar` no grupo `role:admin`, com throttle nomeado; o `base_id` (único id que vem do corpo nesta fase) passa pelo escopo da conta e dá **404 fora dele**. O rascunho do combo é comparado por snapshot antes/depois, e as linhas de `pub_publicacao_itens` também.
- **Nada da Etapa 1/2 regrediu.** 1096 → **1131 verdes** em `tests/{Feature,Unit}/Publicador`, zero falha. `VisaoGeralTest`, `ProgramaPublicadorTest` e `PainelVisaoGeralServiceTest` passaram **sem nenhuma edição**.

## Task Commits

1. **Task 1: produtosParaTela e contagemProdutos cientes de fase** — `4d942832` (test, RED) + `772b995c` (feat, GREEN)
2. **Task 2: VinculoDeKitService + 3 endpoints** — `c88d8907` (test, RED) + `05e47a8b` (feat, GREEN)
3. **Task 3: Visão geral por fase, sem perder o que já existe** — `4798a711` (test, RED) + `49bfe76b` (feat, GREEN)

_Os commits do 175-07 (`c1068931`, `70982cbb`, `64017899`, `c1fb52a8`) estão intercalados: a sessão rodou em paralelo e em arquivos disjuntos (ele é dono dos `.jsx`/`.js`, este plano só de PHP)._

## ⚠️ CONTRATO DE SERVIDOR PARA O 175-10

É isto que a tela vai consumir. **Formato explícito de cada chave nova**, porque um objeto renderizado como texto derrubou a página inteira em 07/10.

### 1. Cada item de `produtos` (prop da tela B e `produtos` do Editor)

As 14 chaves de antes continuam **exatamente iguais** (`id, sku, nome, origem, oferta_id, rascunho_id, status, status_rascunho, anuncios, parcial, atualizado_em, conta_nome, conta_diferente, liberada`). As novas:

| chave | tipo | valor |
|---|---|---|
| `fase` | `int` | número da fase do Publicador; produto que já existia = `1` |
| `quantidade_kit` | `int` | `1` no base, `>= 2` no kit |
| `produto_base_id` | `int \| null` | `null` no base **e** no kit cujo base foi apagado |
| `eh_kit` | `bool` | `produto_base_id !== null && quantidade_kit >= 2` |
| `rotulo_fase` | `string` | `'1 unidade'` ou `'Kit N'` (fonte: `ProgramasPublicadorService::rotuloFase()`) |
| `url_produto` | `string \| null` | URL da tela do Produto; `null` quando o chamador não passou a conta (hoje: só o Editor) |
| `kits` | `int[]` | ids dos kits deste base, em ordem de fase; `[]` no kit |
| `base` | `{id: int, sku: string, nome: string} \| null` | o base deste kit; `null` ⇒ **linha de topo** |
| `sugestao_kit` | `object \| null` | `{base_id: int, base_sku: string, base_nome: string, quantidade: int\|null, origem: 'portal'\|'sku'\|'nome', conflito_heuristica: bool}` |

**Como recuar os kits sob o base:** agrupe por `base.id` quando `base !== null`; `base === null` é linha de topo (inclusive o kit órfão, que continua com `rotulo_fase = 'Kit N'`).
**`sugestao_kit.quantidade` pode ser `null`** (casamento por SKU não traz o N) — é o campo que a pessoa preenche no diálogo.

### 2. `contagens` (prop da tela B)

Os 6 buckets de antes seguem iguais. Novo: `contagens.por_fase = {sem_oferta, fase1_publicada, fase2_preparacao, fase2_publicada, fase3_mais}`, todos `int`, somando `contagens.todos`.

⚠️ `por_fase.sem_oferta` ≠ `contagens.sem_oferta`. O de cima é "sem oferta do Portal"; o de dentro é "sem anúncio no ar".

### 3. Os 3 endpoints

| método | rota (nome Ziggy) | corpo | resposta |
|---|---|---|---|
| `PUT` | `mlb.anuncios.publicador.vinculo.salvar` | `{base_id: int, quantidade: int >= 2}` | `200 {produto: {...}}` |
| `DELETE` | `mlb.anuncios.publicador.vinculo.remover` | — | `200 {produto: {...}}` |
| `POST` | `mlb.anuncios.publicador.vinculo.recusar` | — | `200 {produto: {...}}` |

Params: `{conta}` (`empresa-N`/`company-N`) e `{produto}`.

`produto` da resposta: `{id, produto_base_id, quantidade_kit, fase, eh_kit, estoque_calculado, rotulo_fase, kit_sugestao_recusada_em, sugestao_kit}` — todos escalares, fora de `sugestao_kit`. Serve para atualizar a linha sem recarregar a lista.

**Erros:**
- `404` — produto da URL ou `base_id` fora da conta (D-13: nunca 403).
- `422 {message, errors:{quantidade|base_id}}` — validação (quantidade ausente ou `< 2`).
- `422 {message, regra, campo}` — regra de negócio:

| regra | mensagem | campo |
|---|---|---|
| `VINC-01` | Um produto não pode ser kit de si mesmo. Escolha o produto de 1 unidade. | `null` |
| `VINC-02` | O produto escolhido já é um kit. Vincule ao produto base (1 unidade). | `null` |
| `VINC-03` | Um kit tem 2 unidades ou mais. | `quantidade` |
| `VINC-04` | Já existe Kit N deste produto. | `quantidade` |
| `VINC-05` | Este produto já é o base de outras fases. Um kit não pode ter kits. | `null` |
| `VINC-06` | Este produto já está vinculado a outro produto base. Desfaça o vínculo antes. | `null` |

### 4. Visão geral

- `situacaoProdutos` — **inalterado** (4 buckets, mesmos rótulos). O novo bloco vai **abaixo** dele.
- `produtosPorFase` (prop NOVA) — `{sem_oferta, fase1_publicada, fase2_preparacao, fase2_publicada, fase3_mais}`, cada um `{numero: int, rotulo: string}`; rótulos: "Sem oferta", "Fase 1 publicada", "Fase 2 em preparação", "Fase 2 publicada", "Fase 3+".
- `oQueFazerAgora` — a linha "Prontos para a Fase 2" tem destino `{rota: 'mlb.anuncios.publicador.produtos', params: {conta, filtro: 'publicados', fase: 'so_base'}}`. **O 175-10 precisa aceitar `?fase=so_base`** no filtro da lista (`todas | so_base | so_kits`), senão o parâmetro é ignorado em silêncio.
- `ultimasPublicacoes.itens[]` — ganhou `fase: int` e `rotulo_fase: string`; nenhum campo saiu.

## Files Created/Modified

- `app/Services/Publicador/VinculoDeKitService.php` **(novo)** — `vincular()`, `desvincular()`, `recusar()`. Grava três colunas (+`estoque_calculado=false`) e nada mais; `getDirty()` é guarda viva do contrato.
- `app/Services/Publicador/ProgramasPublicadorService.php` — `produtosParaTela()` com as 9 chaves novas e o 3º parâmetro `$chaveConta`; `contagemProdutos()` com `por_fase`; `rotuloFase()` e `STATUS_NO_AR` públicos; `sugestoes()` preguiçoso.
- `app/Services/Publicador/PainelVisaoGeralService.php` — `produtosPorFase()` novo; `prontosParaFase2()` privado; `linhaDeProdutos()` com `$fase` opcional; `ultimasPublicacoes()` com `fase`/`rotulo_fase`.
- `app/Http/Controllers/MlbPublicadorFaseController.php` — `vincular()`, `desvincular()`, `recusarSugestao()`, `produtoDaConta()` e `produtoParaResposta()`; `baseDaConta()` delega ao novo helper (comportamento idêntico).
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — `$alvo['chave']` nos dois `produtosParaTela()` e a prop `produtosPorFase`.
- `routes/mlb_anuncios.php` — as 3 rotas, depois das do 175-05/06, no mesmo grupo `role:admin`.
- `tests/Feature/Publicador/ListaPorFaseTest.php` **(novo, 19 testes)** — gate de regressão da lista/contagem/Visão geral, prova de formato de cada chave nova, contagem de consultas.
- `tests/Feature/Publicador/VinculoDeKitTest.php` **(novo, 16 testes)** — rotas, escopo, as 6 recusas, e o byte a byte do rascunho/publicações.
- `tests/Feature/Publicador/MlbPublicadorProdutosTest.php` — **uma** asserção ajustada (ver Deviations).

## Como foi provado o que o plano exige

**"Produto que não é kit sai da lista exatamente como hoje":** três provas independentes.
1. `test_lista_mantem_todas_as_chaves_de_hoje_e_acrescenta_as_de_fase` compara as 14 chaves antigas contra um **array literal** e confere tipo por tipo; o produto é um base comum e sai com `fase=1, quantidade_kit=1, eh_kit=false, rotulo_fase='1 unidade', kits=[], base=null, produto_base_id=null, sugestao_kit=null` — ou seja, o default É o backfill e nada mudou de comportamento.
2. `test_a_tela_de_produtos_continua_respondendo_com_o_contrato_de_hoje` faz a requisição real da tela B e confere `produtos`/`contagens`.
3. `MlbPublicadorProdutosTest`, `ProgramaPublicadorTest`, `VisaoGeralTest` e `PainelVisaoGeralServiceTest` (as suítes das Etapas 1 e 2) passam — a última **sem nenhuma edição**, inclusive o teste que exige "Prontos para a Fase 2" na lista com o shape antigo.

**"O vínculo não toca rascunho/SKU/estoque/MLB":** `test_vincular_grava_so_as_tres_colunas_e_nao_toca_rascunho_estoque_publicacoes_nem_mlbs` compara, antes e depois:
- `RascunhoRepository::snapshot()` serializado (categoria, condição, atributos, eixos, variantes, preços, alvos, imagens, descrição, envio, garantia);
- todas as linhas de `pub_publicacao_itens` com `ml_item_id`, `payload`, `status` e `updated_at`;
- a linha inteira de `pub_rascunhos` (nem o `updated_at` dele muda);
- o `estoque` da variante do combo (11, intacto), o `sku`, o `nome`, a `origem`, o `oferta_id` e as âncoras.
Além disso, o próprio serviço recusa (`VINC-00`) qualquer coluna suja fora das quatro — uma escrita acrescentada no futuro não passa nem em produção.

## Decisions Made

Ver `key-decisions` no frontmatter. As duas que mais importam para quem vem depois:

1. **Os dois blocos convivem** (`situacaoProdutos` + `produtosPorFase`). Divergência deliberada da §7, já prevista pelo próprio PLAN.
2. **"Prontos para a Fase 2" tem degrau de compatibilidade.** A regra nova exige a lista ciente de fase; sem ela o número é o de hoje. Foi isso que permitiu mudar a semântica da linha sem editar a expectativa de nenhuma suíte da Etapa 2.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Injeção no construtor fecharia ciclo no container**
- **Found during:** Task 1
- **Issue:** O PLAN manda "injetar o serviço no construtor (hoje o construtor é vazio)". Mas `SugestaoDeKitService::__construct(private ProgramasPublicadorService $programas)` — o inverso já existe desde o 175-03. Injetar `SugestaoDeKitService` no construtor de `ProgramasPublicadorService` fecha um ciclo e o `app()` estoura em recursão infinita, derrubando **toda** tela do Publicador (o container resolve `ProgramasPublicadorService` em 6 controllers).
- **Fix:** propriedade preguiçosa `sugestoes()` com `app(SugestaoDeKitService::class)` memoizado, com o motivo escrito no ponto exato. Mesmo padrão que o 175-09 já usou entre `EditorRascunhoService` e `RecalculoEstoqueDoKitService`.
- **Files modified:** `app/Services/Publicador/ProgramasPublicadorService.php`
- **Verification:** suíte inteira do Publicador (1131 verdes); a tela B e a Visão geral respondem 200 nos testes de página.
- **Committed in:** `772b995c`

**2. [Rule 1 - Bug] `baseDaConta()` desvincularia o produto errado**
- **Found during:** Task 2
- **Issue:** O PLAN manda os três endpoints usarem "o `baseDaConta()` do 175-05 para o produto da URL". `baseDaConta()` devolve `$p->base ?? $p` (§3: abrir um kit leva à tela do base). Num `DELETE vinculo` de um kit, isso desfaria o vínculo **do base** (no-op) em vez do kit; e em `POST recusar` carimbaria o produto errado.
- **Fix:** `produtoDaConta()` extraído (resolver + `produtosQuery()->whereKey()` + 404) e usado pelos três endpoints; `baseDaConta()` passou a delegar a ele, com comportamento idêntico e um aviso no docblock.
- **Files modified:** `app/Http/Controllers/MlbPublicadorFaseController.php`
- **Verification:** `test_desvincular_zera_as_tres_colunas_e_nao_toca_rascunho_nem_mlb` (que desvincula um kit de verdade) + as 92 asserções de `TelaDoProdutoTest`/`CriarFaseEndpointTest`/`IaParaRascunhoTest` seguem verdes.
- **Committed in:** `05e47a8b`

**3. [Rule 2 - Missing critical] Duas recusas a mais no vínculo (`VINC-05`, `VINC-06`)**
- **Found during:** Task 2
- **Issue:** A lista de validações do PLAN fecha a cadeia só de um lado (base não pode ser kit). Faltavam: (a) vincular um produto que **já é base** de outras fases — o kit passaria a ter kits; (b) vincular um produto **já vinculado** — re-parentesco silencioso, trocando a família de um kit publicado.
- **Fix:** `VINC-05` e `VINC-06` com mensagem de usuário; a §2 proíbe cadeia e o `SugestaoDeKitService` já nunca sugere nesses casos — faltava o servidor recusar.
- **Files modified:** `app/Services/Publicador/VinculoDeKitService.php`
- **Verification:** `test_vincular_produto_que_ja_e_base_de_outras_fases_da_422` e `test_vincular_produto_ja_vinculado_da_422`.
- **Committed in:** `05e47a8b`

**4. [Rule 3 - Blocking] Uma asserção de `MlbPublicadorProdutosTest` era incompatível com chave aditiva**
- **Found during:** Task 1
- **Issue:** `test_contagem_produtos_do_service_classifica_por_status_e_sem_oferta` comparava `contagemProdutos([])` contra o **array inteiro** com `assertSame`. Qualquer chave nova — inclusive a que o PLAN manda acrescentar (`por_fase`) — reprova por construção, mesmo sem nada ter saído ou mudado de valor.
- **Fix:** a asserção virou "os 6 buckets existem e vêm zerados", bucket por bucket, com o motivo da mudança escrito no docblock do teste. **Nenhum valor esperado foi afrouxado**; o resto do teste ficou intacto.
- **Files modified:** `tests/Feature/Publicador/MlbPublicadorProdutosTest.php`
- **Verification:** o próprio teste + o gate novo em `ListaPorFaseTest`.
- **Committed in:** `772b995c`

**5. [Rule 2 - Missing critical] `oferta` no eager load da lista**
- **Found during:** Task 1
- **Issue:** `produtosParaTela()` não carregava `oferta`, mas `skuExibido()`/`nomeExibido()` a leem por produto — N+1 pré-existente de uma consulta por linha. A chave `base` nova multiplicaria isso.
- **Fix:** `'oferta'` acrescentado ao `with()`. Mesmos valores de saída, consultas constantes.
- **Files modified:** `app/Services/Publicador/ProgramasPublicadorService.php`
- **Verification:** `test_sugestao_sai_em_uma_passada_e_o_numero_de_consultas_nao_cresce_com_a_lista` (3 vs 12 produtos, mesmo número, < 20).
- **Committed in:** `772b995c`

### Divergências do PLAN que foram decisão, não correção

- **Família em memória** em vez do `whereIn('produto_base_id', $ids)` que o PLAN previa: o kit herda as âncoras do base, logo ele já veio na coleção de `produtosQuery()`. Zero consulta nova, mesmo resultado.
- **`situacaoProdutos` não foi substituída** — divergência da §7 já autorizada pelo próprio PLAN (nota no `objective`).

---

**Total deviations:** 5 auto-corrigidas (2× Rule 3, 2× Rule 2, 1× Rule 1) + 2 divergências de implementação.
**Impact on plan:** nenhuma ampliação de escopo. As duas Rule 3 eram bloqueios reais (recursão no container; asserção incompatível com o que o próprio PLAN manda acrescentar), a Rule 1 era bug de produto errado, e as Rule 2 fecham cadeia de kit e N+1.

## Issues Encountered

- **Nenhum bloqueio.** Uma expectativa aritmética errada no meu próprio teste (bucket `rascunho` com 5 e não 4) foi corrigida no teste — o código estava certo.
- **Pint não foi rodado de propósito.** `vendor/bin/pint --test` reprova este módulo **no baseline** (os arquivos intocados `CriarFaseService` e `SugestaoDeKitService` falham com os mesmos fixers: `braces_position`, `single_line_empty_body`, `fully_qualified_strict_types`...). Rodar o Pint reformataria o módulo inteiro, colidindo com as sessões paralelas do 175-07/175-10. O projeto não tem `pint.json` e o estilo é mantido à mão.
- **Sessão paralela:** o 175-07 commitou entre os meus commits. Todo commit foi por caminho (`git commit -m ... -- <arquivos>`), nenhum `git add -A`, e nenhum arquivo `.jsx`/`.js` foi tocado.

## Testes

| momento | comando | resultado |
|---|---|---|
| antes | `artisan test tests/Feature/Publicador tests/Unit/Publicador` | **1096 passed**, 0 failed |
| depois | idem | **1131 passed**, 0 failed (5581 asserções) |
| regressão vizinha | `--filter="Phase165\|Phase172\|Phase173\|Phase175"` | 151 passed, 1 incomplete (pré-existente) |
| outros consumidores | `tests/Feature/Mcp/LerTelaToolTest.php tests/Feature/Phase134/RascunhosMeusAnunciosTest.php` | 18 passed |

35 testes novos (19 + 16). **Nenhuma suíte da Etapa 1 ou 2 mudou de expectativa** — só a asserção de array inteiro descrita na deviation 4.

_A suíte do projeto INTEIRO não foi usada como gate: estoura memória na Fase 121 e acusa falhas pré-existentes fora deste escopo (`CalcularFaixaTest`, `ExampleTest`, `CompanyServiceTypeTest`). Nenhum teste chamou API real (`Http::fake()`; nas requisições de página as asserções filtram por `mercadolibre`, porque o `HandleInertiaRequests` busca os sinais do ECF Drive em toda página autenticada)._

## Roteiro clicável de validação em produção — conta #459 "Dev 02 Testes API"

A tela **ainda não mostra** a coluna Fases (é o 175-10). O que este plano exige conferir é que **nada mudou**.

1. Abrir `/mlb/anuncios` → aba Polos (ou a aba onde a #459 aparece) → **Dev 02 Testes API**.
2. **Visão geral:** conferir que todos os blocos continuam no lugar — indicadores do topo, "O que fazer agora", **"Situação dos produtos"** (os 4 números: Rascunho, Conferidos, Publicados, Com problema), "Últimas publicações", "Integrações", "Identidade" e "Quem publicou". Nenhum bloco pode ter desaparecido.
3. **A única diferença admissível:** a linha **"Prontos para a Fase 2"** pode mostrar um número MENOR (passou a excluir base que já tem kit). Hoje, **sem nenhum kit criado na conta, o número tem de ser exatamente o mesmo de antes** e igual ao "Publicados" da "Situação dos produtos". Se divergir sem existir kit, é regressão.
4. **Produtos:** conferir que os 12 produtos aparecem, com os mesmos chips de contagem (Todos / Rascunho / Conferidos / Publicados / Com problema), os mesmos status e os mesmos botões ("Continuar", "Abrir"). Nenhuma linha pode ter sumido nem mudado de status.
5. **Editor:** abrir um produto pelo "Continuar" e conferir que a faixa lateral de produtos continua listando todos.
6. O bloco **"Produtos por fase"** só aparece depois do 175-10 — a prop já vai no JSON desta entrega, mas ninguém a renderiza ainda. Não é erro não vê-la.

Nada aqui pede comando no servidor: é tudo clique.

## Next Phase Readiness

**Pronto para o 175-10** (dono de `Produtos.jsx`, `DialogoVincularKit.jsx` e do bloco "Produtos por fase"): o contrato completo está na seção "⚠️ CONTRATO DE SERVIDOR PARA O 175-10" acima.

Dois pontos de atenção para ele:
1. **O filtro `?fase=` precisa existir na lista** (`todas | so_base | so_kits`) — o destino de "Prontos para a Fase 2" já manda `fase=so_base`.
2. **O bloco novo vai ABAIXO do "Situação dos produtos"**, não no lugar dele. Se o usuário decidir substituir de fato, é remover a renderização do antigo no JSX — o servidor continua entregando os dois.

Nada foi deployado, nada foi enviado ao VPS, nenhum push foi feito.

---
*Phase: 175-publicador-etapa-3-produto-fases*
*Plan: 08*
*Completed: 2026-10-09*

## Self-Check: PASSED

- Arquivos criados conferidos no disco: `VinculoDeKitService.php`, `ListaPorFaseTest.php`, `VinculoDeKitTest.php`, este SUMMARY.
- Commits conferidos no histórico: `4d942832`, `772b995c`, `c88d8907`, `05e47a8b`, `4798a711`, `49bfe76b`.
