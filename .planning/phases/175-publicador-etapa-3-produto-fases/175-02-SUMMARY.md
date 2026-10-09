---
phase: 175-publicador-etapa-3-produto-fases
plan: 02
subsystem: api
tags: [publicador, clone, kit, fases, pub_rascunhos, pub_variante_eixo_valores, storage, transacao, eloquent]

# Grafo de dependências
requires:
  - phase: 175-01
    provides: "as cinco colunas de fase em `pub_produtos` (`produto_base_id`, `quantidade_kit`, `fase`, `estoque_calculado`, `kit_sugestao_recusada_em`), o índice `pubprod_base_ix` e o unique `pubprod_base_qtd_uq`"
  - phase: 164-publicador-interno
    provides: "`PubProduto`/`PubRascunho`, `RascunhoRepository` (inclusive `travar()`), `ImagemAssetService`, `RegraViolada` e o schema inteiro de `pub_*`"
provides:
  - "`App\\Services\\Publicador\\CriarFaseService::criar(PubProduto $base, array $dados): PubProduto` — o `PubProduto` do kit + o clone integral do rascunho do base, numa transação"
  - "Quatro recusas estáveis: `KIT-01` (base sem rascunho), `KIT-02` (base que já é kit), `KIT-03` (quantidade < 2), `KIT-04` (Kit N duplicado, com `contexto['campo'] = 'quantidade'`)"
  - "Remapeamento de `pub_variante_eixo_valores` (PK composta, sem `id`): a variante do kit nasce com a MESMA combinação, apontando para os eixos/valores NOVOS"
  - "Fotos do kit com BYTES próprios em `publicador/{rascunho_do_kit}/{sha}.ext` e `ml_picture_id`/`ml_url`/`upload_status` preservados — sem reupload e sem compartilhar arquivo com o base"
  - "`PubProduto`: relações `base()`/`kits()`, `familia()` em uma consulta, `ehKit()`, os casts das colunas de fase e os helpers puros `proximaFase()`/`proximaQuantidade()`"
  - "Seams `protected` (`copiarAlvos`, `copiarAtributos`, `copiarEixos`, `copiarVariantes`, `copiarImagens`) que permitem injetar falha no meio do clone em teste"
affects: [175-04, 175-05, 175-06, 175-07, PreviaDaFaseService, MlbPublicadorFaseController, PainelCriarFase.jsx, Produto.jsx, SugestaoDeKitService]

# Rastreio técnico
tech-stack:
  added: []
  patterns:
    - "Clone de rascunho por LINHA (não por `snapshot()`): preserva `chave_hash`, `combinacao_hash`, `removido` e `posicao`, que o snapshot não carrega"
    - "Mapas antigo→novo explícitos por tabela (alvos, eixos, valores de eixo, imagens) carregados entre os passos do clone"
    - "Rollback de DISCO depois do rollback de banco: cada `put()` entra numa lista e é apagado em `rescue` quando a transação cai"
    - "Passos do clone como métodos `protected`, para que o teste injete falha no meio por subclasse anônima"
    - "Prefixo próprio de id de regra (`KIT-xx`) para não colidir com os IDs de requisito da fase (`FASE-xx`)"

key-files:
  created:
    - app/Services/Publicador/CriarFaseService.php
    - tests/Unit/Publicador/CriarFaseServiceTest.php
    - tests/Feature/Publicador/CriarFaseCloneTest.php
  modified:
    - app/Models/PubProduto.php

key-decisions:
  - "A foto do kit ganha BYTES PRÓPRIOS. A §5 ao pé da letra (\"mesmo arquivo, sem reupload\") apagaria a foto do produto base: `ImagemAssetService::remover()` faz `Storage::delete($imagem->caminho)` antes do `$imagem->delete()`, e o caminho é namespaced por rascunho. Os ids do ML (`ml_picture_id`/`ml_url`/`upload_status`) vêm preservados — é a mesma conta, então o 'sem reupload' continua valendo sem compartilhar arquivo (T-175-04)"
  - "`dominio_id` e `schema_hash` copiados à mão: a §5 não os cita, e sem eles o kit renegocia o schema da categoria na primeira abertura (o `EditorRascunhoService::abrir()` regrava a categoria quando há `categoria_id` sem `schema_hash`)"
  - "`pub_variante_eixo_valores` remapeada eixo a eixo e valor a valor; remap que não resolve ESTOURA em vez de calar, porque variante sem combinação deixa a grade do editor inutilizável"
  - "Ids de regra são `KIT-01..KIT-04`, não `FASE-xx`: `FASE-01..FASE-11` já são os IDs de requisito desta fase e reusá-los em `RegraViolada` criaria colisão de vocabulário"
  - "Só o `GTIN` é esvaziado; `EMPTY_GTIN_REASON` é COPIADO. Sem GTIN, é o motivo que o ML pede — esvaziar os dois deixaria o kit sem GTIN e sem justificativa"
  - "`estoque_por_variante` ausente para uma variante grava NULL ('não informado'), nunca o estoque do base — que seria o número errado para um kit de N unidades"
  - "Corrida no unique `pubprod_base_qtd_uq` (QueryException 23000) volta como `RegraViolada KIT-04`, com a mesma mensagem de campo da conferência prévia"
  - "`const DISCO = 'local'` espelhada no serviço porque `ImagemAssetService::DISCO` é `private` e aquele arquivo não está no `files_modified` deste plano"

patterns-established:
  - "Commit intermediário que NÃO perde dado: a Task 2 entregou `copiarImagens()` recusando base COM foto, em vez de clonar sem foto — o RED da Task 3 ficou genuíno e nenhum commit da cadeia perde foto em silêncio"
  - "Prova do remapeamento por três ângulos independentes: linhas novas, pivô sem nenhum id do base, e combinação relida igual pelo `RascunhoRepository::snapshot()`"
  - "Fotos semeadas direto no disco + linha (`put()` + `create()`), nunca por `ImagemAssetService::receber()`, quando o teste precisa provar ZERO chamada ao ML"

requirements-completed: [FASE-05, FASE-06]

# Métricas
duration: ~80min
completed: 2026-10-08
---

# Fase 175 Plano 02: O clone — `CriarFaseService` + família no `PubProduto` — Resumo

**`CriarFaseService::criar()` cria o `PubProduto` do kit e copia o rascunho do base inteiro numa transação sob trava de linha — com `pub_variante_eixo_valores` remapeada, `dominio_id`/`schema_hash` copiados à mão e fotos de bytes próprios que preservam os ids do Mercado Livre —, e `PubProduto` passou a conhecer base, kits e família; a armadilha de perda de foto da §5 está fechada e provada por execução.**

## Performance

- **Duração:** ~80 min (leitura do plano + spec, baseline de 879, 3 tasks em RED/GREEN, suíte completa duas vezes)
- **Iniciado:** 2026-10-09T00:10Z (aprox.)
- **Concluído:** 2026-10-09T01:33Z
- **Tasks:** 3 de 3
- **Arquivos modificados:** 4 (3 criados, 1 editado)

## Realizações

- **O clone existe e é fiel.** `CriarFaseService::criar()` copia, do rascunho do base para o do kit: categoria, `dominio_id`, `schema_hash`, condição, `envio`, `garantia`, `modelo_publicacao`, `fotos_por_variante`, `incluir_geral_nas_variantes`, `identificacao`, atributos do produto, eixos + valores (com `chave_hash` e `removido`), variantes (com `combinacao_hash`, `ativa`, `orfa`, `posicao`), a **combinação de eixos remapeada**, atributos de variante, alvos, preços vazios, fotos e atribuições de foto. Tudo dentro de um `DB::transaction` que começa por `RascunhoRepository::travar()`.
- **A armadilha de perda de foto está fechada.** O teste `test_remover_foto_do_kit_nao_apaga_o_arquivo_do_base` executa `ImagemAssetService::remover()` na foto do KIT e confere, no disco, que o arquivo do BASE continua lá com os bytes originais. Com o caminho compartilhado que a §5 descreve ao pé da letra, esse teste falharia.
- **`pub_variante_eixo_valores` (a tabela que a §5 não cita) é remapeada e provada por três ângulos:** as linhas são novas, a pivô do kit não contém **nenhum** `eixo_id`/`eixo_valor_id` do base, e a combinação relida pelo `RascunhoRepository::snapshot()` é idêntica à do base, variante por variante.
- **`PubProduto` conhece a família:** `base()`, `kits()` (ordenado por fase), `familia()` em **uma** consulta nos dois sentidos, `ehKit()` (exige base E ≥ 2 unidades), os quatro casts que faltavam e os helpers puros `proximaFase()`/`proximaQuantidade()`.
- **Nada de histórico, nada de HTTP.** O kit nasce `DRAFT`, revisão 1, `step_state` NULL, `conta_checada_em` NULL, `oferta_id` NULL nos dois lugares, sem `pub_validacoes`, sem `pub_publicacoes` e sem kit de criativos. `Http::assertNothingSent()` cobra, em cada teste de clone, que o Mercado Livre não é chamado.
- **Rollback nos dois meios.** Falha no meio da transação não deixa `PubProduto`, rascunho, alvo nem eixo; falha depois das fotos também não deixa **arquivo órfão no disco** (os `put()` são anotados e apagados em `rescue`).

## Commits por task

1. **Task 1 (RED): helpers puros de família** — `c3e23999` (`test`)
2. **Task 1 (GREEN): `PubProduto` conhece a família** — `18eeac63` (`feat`)
3. **Task 2 (RED): prova do `CriarFaseService` antes de ele existir** — `7eb9ab50` (`test`)
4. **Task 2 (GREEN): kit + clone do rascunho em transação** — `b5585818` (`feat`)
5. **Task 3 (RED): prova das fotos do kit** — `e4d1640a` (`test`)
6. **Task 3 (GREEN): fotos com bytes próprios e ids do ML preservados** — `52cc2a10` (`feat`)

**Metadados do plano:** ver o commit `docs(175-02)` com esta SUMMARY.

Nenhum commit de REFACTOR: não houve limpeza a fazer depois do GREEN em nenhuma das três tasks.

## Arquivos criados/modificados

- `app/Services/Publicador/CriarFaseService.php` — **criado** (541 linhas, das quais ~95 de docblock de classe). `criar()` com as quatro recusas antes de qualquer escrita, a transação (travar → ler → gravar), e os passos `copiarAlvos` / `copiarAtributos` / `copiarEixos` / `copiarVariantes` / `copiarCombinacao` / `copiarAtributosDaVariante` / `copiarImagens` / `copiarAtribuicoes`. O docblock registra, numerada, a razão de cada uma das oito decisões não óbvias (incluindo o perigo de perda de foto, o `identificacao` morto e a proibição de `receber()`/`enviarAoMl()`).
- `app/Models/PubProduto.php` — **editado.** `$casts` novo (`fase`/`quantidade_kit` int, `estoque_calculado` bool, `kit_sugestao_recusada_em` datetime), `base()`, `kits()`, `ehKit()`, `familia()`, `proximaFase()`, `proximaQuantidade()`, e o bloco de docblock "Fases e kits" com a regra da §2 e o aviso em destaque da colisão `pub_produtos.fase` × `estrutura_ofertas.fase`. **Nenhum método existente foi alterado.**
- `tests/Unit/Publicador/CriarFaseServiceTest.php` — **criado.** 18 testes: 2 dos helpers puros (sem banco), 4 da família em Eloquent, 4 das recusas e 8 do clone (âncoras/fase, schema + ausência de histórico, alvos, atributos/GTIN/medidas, remapeamento da combinação, SKU/estoque/`publicada`, preços vazios, rollback total).
- `tests/Feature/Publicador/CriarFaseCloneTest.php` — **criado.** 6 testes com `RefreshDatabase`, `Storage::fake('local')` e `Http::fake()`: clone de base cheio (2 eixos, 3 valores, 2 variantes, 2 alvos, 3 fotos), caminho/bytes/ids das fotos, **o guarda-corpo do `remover()`**, atribuições, foto sem arquivo (H-22) e rollback de disco.

## Como o remapeamento de `pub_variante_eixo_valores` foi provado

`test_variantes_do_kit_tem_a_mesma_combinacao_apontando_para_os_eixos_novos`, em três etapas na mesma execução:

1. **As linhas são novas.** `$eixoBase->id !== $eixoKit->id`; os `chave_hash` dos valores são idênticos; e `array_intersect` entre os ids de `pub_eixo_valores` do base e do kit é `[]`.
2. **A pivô do kit não encosta no base.** Lida por `DB::table('pub_variante_eixo_valores')->whereIn('variante_id', <ids do kit>)`: 2 linhas (2 variantes × 1 eixo), `eixo_id` único e igual ao do kit, `array_intersect` dos `eixo_valor_id` com os valores do base `[]`, e `array_diff` contra os valores do kit também `[]` (nenhum id de fora).
3. **A combinação relida é a mesma.** `RascunhoRepository::snapshot()` nos dois rascunhos devolve `['COLOR=id:52049' => 'Preto', 'COLOR=id:52028' => 'Azul']` idêntico — é a leitura que a grade do editor usa.

No teste de clone cheio (2 eixos) a contagem fecha em 4 linhas de pivô para 2 variantes, confirmando que as duas dimensões vieram.

Além da prova por teste, o código **falha alto**: se um `eixo_id` ou `eixo_valor_id` não estiver no mapa, `copiarCombinacao()` lança `\RuntimeException` e a transação volta — nunca grava uma variante sem combinação.

## Como o teste da foto do base foi provado

`test_remover_foto_do_kit_nao_apaga_o_arquivo_do_base`:

1. Base cheio com 3 fotos reais no `Storage::fake('local')`, cada uma em `publicador/{rascunho_do_base}/{sha}.jpg`, com `ml_picture_id`/`ml_url` e `upload_status = uploaded`.
2. `criar()` → o kit recebe 3 linhas em `pub_imagens` com `caminho = publicador/{rascunho_do_kit}/{sha}.jpg` — **mesmo sha, caminho diferente** — e os mesmos ids do ML.
3. `app(ImagemAssetService::class)->remover($fotoDoKit)` — o caminho real do editor, sem simulação.
4. Asserções: o arquivo do kit **sumiu** (`assertMissing`), a linha do kit sumiu, e **o arquivo do base continua existindo com os bytes originais** (`assertExists($caminho, $bytes)` — 2º argumento é o conteúdo esperado, pegadinha §8 do learnings), a linha do base continua, o base segue com 3 fotos e o kit com 2.

Com o `caminho` compartilhado que a §5 descreve, o passo 4 falharia em `assertExists` e a linha do base ficaria apontando para arquivo inexistente.

O corolário ("sem reupload") é provado em `test_foto_do_kit_tem_caminho_proprio_com_os_mesmos_bytes_e_os_ids_do_ml` + `Http::assertNothingSent()` em todos os testes do arquivo.

## Testes

| Momento | Comando | Resultado |
|---|---|---|
| Antes | `artisan test tests/Feature/Publicador tests/Unit/Publicador` | **879 passed** (4358 asserções), 195s |
| Depois | mesmo comando | **934 passed** (4628 asserções), 195s |

**Zero falhas, zero skips.** Dos +55 testes, **24 são deste plano** (confirmado por `artisan test tests/Unit/Publicador/CriarFaseServiceTest.php tests/Feature/Publicador/CriarFaseCloneTest.php` → **24 passed, 178 asserções**); os outros 31 são do **175-03** (`SugestaoDeKitServiceTest`), que rodou em paralelo na mesma wave e commitou entre os meus commits (`c2fbcdb5`, `f22b3336`, `bcfc392f`, `6a596527`, `6cb611a3`).

Como `PubProduto` foi editado, as suítes de fora do Publicador que o consomem também rodaram:
`artisan test tests/Feature/Phase165 tests/Feature/Phase170 tests/Feature/Phase171 tests/Feature/Phase86 tests/Feature/PortalCliente/Estrutura/Produtos` → **402 passed, 1 incomplete** (o incomplete é pré-existente, não relacionado).

`C:/xampp/php/php.exe -l` limpo nos três arquivos novos e no `PubProduto`.

Nenhum arquivo `.jsx`/`.js` foi tocado: `npm run build` / `npm run test:js` não se aplicam a este plano.

## Decisões tomadas

- **Bytes próprios para a foto do kit, ids do ML preservados** (a decisão central — ver seção acima e o item 4 do docblock da classe).
- **Clone por LINHA, não por `snapshot()`.** O `RascunhoSnapshot` perde `chave_hash`, `combinacao_hash`, `removido`, `posicao` e a identidade das linhas de `pub_variante_eixo_valores` — justamente o que o clone precisa preservar. O passo 2 do plano sanciona as duas leituras ("o `snapshot()`/leitura das linhas DEPOIS"); ficou a leitura de linhas, e o `snapshot()` entrou só no TESTE, como oráculo independente (se o clone e o snapshot concordam, a grade do editor vai funcionar).
- **`EMPTY_GTIN_REASON` é copiado, não esvaziado.** O plano o nomeia junto do `GTIN` na lista de constantes; esvaziar os dois deixaria o kit sem GTIN **e** sem a justificativa que o ML exige nesse caso (H-15). Só o `GTIN` sai.
- **Medidas do pacote: lista explícita de 8 ids**, nunca regex sobre nome de atributo — os 4 `SELLER_PACKAGE_*` que o Publicador grava (seção EMBALAGEM do `ClassificadorAtributos`) e os 4 `PACKAGE_*` nativos do ML. Copiadas com `revisar = true`.
- **Estoque ausente grava NULL.** Se `estoque_por_variante` não traz a chave da variante, a variante do kit nasce com `estoque`/`estoque_depositos` NULL ("não informado"), nunca com o estoque do base.
- **SELLER_SKU: se quem chama não mandou SKU para a variante, a linha NÃO é criada.** Copiar o SELLER_SKU da unidade colidiria com o anúncio da Fase 1 no Mercado Livre. E se o base não tinha a linha mas o chamador mandou SKU, a linha é criada.
- **Fallback de SKU do produto.** `pub_produtos.sku` é NOT NULL; `$dados['sku']` vazio cai em `{sku do base}-KIT{N}` (o 175-05 valida `required` antes, então é só rede de segurança).
- **Seams `protected` nos passos do clone** (ver Desvios, item 2).

## Desvios do plano

### Ajustes automáticos

**1. [Rule 3 - Blocking] `ImagemAssetService::DISCO` é `private`**
- **Encontrado em:** Task 3
- **Problema:** O `<interfaces>` do plano diz "`ImagemAssetService::DISCO` é a constante do disco", mas no código ela é `private const DISCO = 'local'`. Chamar `ImagemAssetService::DISCO` do `CriarFaseService` é erro fatal. Tornar a constante pública exigiria editar `app/Services/Publicador/ImagemAssetService.php`, que **não está no `files_modified` deste plano** (e a restrição de árvore compartilhada proíbe tocar fora da lista).
- **Correção:** `private const DISCO = 'local'` espelhada no `CriarFaseService`, com comentário dizendo que espelha a de lá, que lá é privada, e que as duas têm de continuar iguais.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** `CriarFaseCloneTest` grava e lê no mesmo disco que o `ImagemAssetService::remover()` apaga — se os discos divergissem, `assertMissing` falharia.
- **Commitado em:** `b5585818`
- **Sugestão para um plano futuro:** promover `ImagemAssetService::DISCO` a `public` e passar o serviço a usá-la, eliminando a duplicata.

**2. [Rule 2 - Missing Critical] A falha do meio é injetada por seam `protected`, não por repositório falso**
- **Encontrado em:** Task 2
- **Problema:** O `<behavior>` pede "simule com um repositório que lança depois de copiar os eixos". Mas o `CriarFaseService` chama o `RascunhoRepository` **uma única vez** — `travar()`, que é o PRIMEIRO passo dentro da transação (exigência WR-B02 do próprio plano). Não existe chamada ao repositório depois dos eixos, logo não há como um repositório falso estourar ali.
- **Correção:** Os passos do clone viraram métodos `protected` (`copiarAlvos`, `copiarAtributos`, `copiarEixos`, `copiarVariantes`, `copiarImagens`) e o teste injeta a falha por **subclasse anônima** que sobrescreve `copiarVariantes()` — isto é, estoura exatamente "depois de copiar os eixos", com `PubProduto`, rascunho, alvos e atributos já gravados. A prova pedida (rollback total) saiu idêntica, e ficou mais forte: o mesmo mecanismo cobre o rollback de DISCO em `test_falha_depois_das_fotos_nao_deixa_arquivo_orfao_no_disco`.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`, `tests/Unit/Publicador/CriarFaseServiceTest.php`, `tests/Feature/Publicador/CriarFaseCloneTest.php`
- **Verificação:** `test_falha_no_meio_nao_deixa_nada_no_banco` compara as 4 contagens (produtos, rascunhos, eixos, alvos) antes e depois — iguais.
- **Commitado em:** `b5585818` / `7eb9ab50` / `52cc2a10`

**3. [Rule 2 - Missing Critical] Ids de regra `KIT-xx` em vez de `FASE-xx`**
- **Encontrado em:** Task 2
- **Problema:** `RegraViolada` recebe "o ID estável da spec". O vocabulário natural seria `FASE-01…`, mas `FASE-01` a `FASE-11` já são os **IDs de requisito** desta fase (frontmatter dos planos 175-01 a 175-10). Reusá-los faria `FASE-05` significar duas coisas diferentes no mesmo módulo — e a mensagem de erro da tela passaria a citar um id de requisito.
- **Correção:** prefixo `KIT-` (conferido livre em `app/`, nos planos da fase e no handoff): `KIT-01` base sem rascunho, `KIT-02` base que já é kit, `KIT-03` quantidade < 2, `KIT-04` Kit N duplicado.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** os quatro ids são asseverados por `assertSame` nos testes de recusa.
- **Commitado em:** `b5585818`

**4. [Rule 2 - Missing Critical] Corrida no unique volta como `KIT-04`, não como erro 500**
- **Encontrado em:** Task 2
- **Problema:** A conferência de "Kit N já existe" é um `SELECT` antes do `INSERT`. Em duas requisições simultâneas, as duas passam na conferência e a segunda estoura no `pubprod_base_qtd_uq` — `QueryException` crua, que no 175-05 viraria 500 em vez do 422 com mensagem de campo.
- **Correção:** `catch` em volta da transação converte `QueryException` com código `23000` em `RegraViolada('KIT-04', "Já existe Kit {N} deste produto.", ['campo' => 'quantidade'])` — a MESMA mensagem da conferência prévia. Mesmo padrão que `PubProduto::daOferta()` já usa para a corrida no `pubprod_oferta_uq`.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** indireta (o caminho determinístico é testado; a corrida não é reproduzível em teste de unidade). O código do caminho é o mesmo do `daOferta()`, que já tem teste próprio.
- **Commitado em:** `b5585818`

**5. [Rule 2 - Missing Critical] Remap que não resolve estoura em vez de calar**
- **Encontrado em:** Task 2
- **Problema:** Se um `eixo_id`/`eixo_valor_id` da pivô não estivesse no mapa, o `??` natural daria `null` e o `insert` gravaria NULL (SQLite) ou estouraria a FK com mensagem opaca (MariaDB) — nos dois casos o sintoma final seria "variante sem combinação", exatamente o que o plano quer impedir.
- **Correção:** `copiarCombinacao()` lança `\RuntimeException` nomeando a variante e o rascunho quando o mapa não fecha.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** o caminho felizes é coberto; o guard é defensivo (a FK torna o caso inalcançável hoje).
- **Commitado em:** `b5585818`

**6. [Rule 2 - Missing Critical] Foto com `caminho` apontando para arquivo ausente**
- **Encontrado em:** Task 3
- **Problema:** O plano trata dois casos (arquivo presente; `caminho` NULL). Falta o terceiro, que existe de verdade: `caminho` preenchido e arquivo **sumido do disco** (retenção, limpeza manual). Copiar o `caminho` nesse caso criaria no kit uma linha apontando para arquivo inexistente — o mesmo estado ruim que o plano quer evitar no base.
- **Correção:** a linha é copiada com `caminho = NULL` (igual ao caso H-22), com os ids do ML, sem lançar. Comentário explícito no código.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** o caminho de `caminho` NULL está testado (`test_foto_sem_arquivo_e_copiada_so_com_os_ids_do_ml_sem_lancar`); o de arquivo ausente usa o mesmo ramo de código.
- **Commitado em:** `52cc2a10`

**7. [Rule 2 - Missing Critical] Dedup por sha na cópia das fotos**
- **Encontrado em:** Task 3
- **Problema:** `pub_imagens` tem unique `(rascunho_id, sha256)`, mas `sha256` é anulável — um base vindo do Anunciar antigo pode ter duas linhas com `sha256` NULL e arquivos idênticos. O clone calcula o sha quando ele falta (precisa dele para nomear o arquivo), e nesse caso as duas cópias colidiriam no unique e derrubariam o clone inteiro.
- **Correção:** mapa `sha → imagem já criada`; a segunda ocorrência reaproveita a cópia e só acrescenta as atribuições dela.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** o caminho normal (3 shas distintos) está testado; o dedup é defensivo.
- **Commitado em:** `52cc2a10`

**8. [Rule 3 - Blocking] `copiarImagens()` da Task 2 recusa base COM foto**
- **Encontrado em:** Task 2
- **Problema:** As Tasks 2 e 3 editam o MESMO arquivo e o plano manda commitar por task. Um commit da Task 2 com as fotos **ausentes** publicaria um clone que perde foto em silêncio — o tipo de estado que esta fase existe para evitar.
- **Correção:** a Task 2 entregou `copiarImagens()` lançando `\RuntimeException` quando o base tem foto (documentado como "chega na Task 3"), de modo que nenhum commit da cadeia perde dado e o RED da Task 3 ficou genuíno (6 testes falhando por essa exceção). A Task 3 substituiu o corpo. Nenhuma rota chama o serviço ainda (ela chega no 175-05), então o estado intermediário nunca foi alcançável por usuário.
- **Arquivos modificados:** `app/Services/Publicador/CriarFaseService.php`
- **Verificação:** RED da Task 3 mostrou a mensagem da exceção; GREEN com 6/6.
- **Commitado em:** `b5585818` (guard) → `52cc2a10` (implementação)

---

**Total de desvios:** 8 ajustados automaticamente (2 blocking, 6 missing critical). **Nenhum desvio exigiu decisão arquitetural (Rule 4).**
**Impacto no plano:** nenhum alargamento de escopo. Os seis `must_haves.truths` saíram inteiros, os dois `artifacts` existem (o serviço com 541 linhas, acima do mínimo de 160) e os dois `key_links` estão no código com os padrões pedidos (`pub_variante_eixo_valores` e `publicador/`). Sem UI, sem rota, sem job, como o objetivo manda.

## Conformidade do gate TDD

As três tasks são `tdd="true"` e as três tiveram **RED antes de GREEN, em commits separados**:

| Task | RED | o que falhava no RED | GREEN |
|---|---|---|---|
| 1 | `c3e23999` | `BadMethodCallException: PubProduto::proximaQuantidade()` (2 testes) | `18eeac63` |
| 2 | `7eb9ab50` | `Class "App\Services\Publicador\CriarFaseService" not found` (12 testes) | `b5585818` |
| 3 | `e4d1640a` | `RuntimeException: A cópia das fotos ... ainda não está implementada` (6 testes) | `52cc2a10` |

Nenhum teste passou inesperadamente no RED. Nenhum commit de REFACTOR foi necessário.

Observação sobre a Task 1: o plano pede os **dois helpers puros PRIMEIRO**, e foi literalmente o que o `c3e23999` contém (só eles). Os testes de `ehKit`/`base`/`kits`/`familia`/casts, que precisam de banco, entraram junto do GREEN — eles nascem verdes porque descrevem relações Eloquent, não aritmética.

## Problemas encontrados

- **`git commit -- <paths> -m "..."` não funciona:** o `-m` depois do `--` é lido como pathspec. A ordem correta é `git commit -m "..." -- <paths>`. Como as mensagens aqui são multilinha com acentos, passei a usar `-F <arquivo de mensagem>` no scratchpad da sessão. (O primeiro commit errou nisso e nada foi gravado — só o `git add` tinha corrido.)
- **`$TMPDIR` no Git Bash do Windows não aponta para lugar gravável** (`/msg.txt: Permission denied`); usei o caminho absoluto do scratchpad da sessão.
- **`sed -i` com âncora `$` não casou** no `CriarFaseService.php` (terminadores de linha); a inserção do `use App\Models\PubImagem;` foi feita por edição pontual.
- **175-03 rodou em paralelo de verdade:** os commits dele (`c2fbcdb5`, `f22b3336`, `bcfc392f`, `6a596527`, `6cb611a3`) estão intercalados com os meus no log, e a contagem da suíte do Publicador inclui os 31 testes dele. Nenhum arquivo foi disputado: ele mexeu em `SugestaoDeKitService.php` e no teste dele; eu, nos quatro do meu `files_modified`. Todo commit saiu com `git add -- <caminho>` **e** pathspec no `git commit`.
- `tests/Feature/CompanyPortfolioAccessTest.php` continua untracked e **não é deste plano** — intocado.

## Divergências entre plano/spec e código (para o próximo plano)

1. **`ImagemAssetService::DISCO` é `private`** — o plano a descreve como acessível. Ver desvio 1.
2. **Não há chamada ao `RascunhoRepository` depois dos eixos** — o `<behavior>` da Task 2 supõe que haja. Ver desvio 2.
3. **`pub_rascunhos.identificacao` confirmado como coluna morta** — nada em `app/` lê ou grava. Copiado por fidelidade à §5, com o aviso registrado no docblock (item 7). Se algum plano futuro quiser aposentar a coluna, o clone é o **único** lugar que a escreve hoje.
4. **A §5 diz "imagens e atribuições (mesmo arquivo, sem reupload)" e isso está deliberadamente NÃO implementado ao pé da letra.** Quem reler a spec vai achar divergência: é intencional, está provado por teste e explicado no item 4 do docblock da classe e no `<threat_model>` (T-175-04). **Não "corrigir" para compartilhar caminho.**
5. **A §5 não cita `dominio_id`/`schema_hash`/`modelo_publicacao`/`pub_variante_eixo_valores`** — os quatro são copiados/remapeados aqui. Qualquer releitura da spec que "limpe" essas cópias quebra o kit.
6. **O kit nasce com `oferta_id` NULL, logo preço nulo NÃO herda da Precificação.** O 175-05 não deve mostrar sugestão de preço nem esperar efetivo: a §4 já manda campo vazio, e agora há a razão estrutural.

## Configuração manual necessária

Nenhuma. Nenhuma migration nova, nenhuma variável de ambiente, nenhum serviço externo, nenhuma chamada ao Mercado Livre. **Nada foi deployado, nada foi enviado ao VPS, nenhum push foi feito** — conforme as restrições.

## Prontidão para o próximo plano

**Pronto.** O 175-05 (prévia + endpoints) pode contar com:

- `CriarFaseService::criar(PubProduto $base, array $dados): PubProduto`, com `$dados = ['quantidade','sku','seller_skus','titulo_por_tipo','descricao','estoque_por_variante','ator']` — exatamente a assinatura que o `<interfaces>` do 175-05 declara;
- `RegraViolada` com `regra` em `KIT-01..KIT-04` e `contexto['campo'] = 'quantidade'` nos dois casos de campo (`KIT-03`, `KIT-04`) — é o que o `catch` do controller precisa para devolver 422 com erro no campo;
- âncoras garantidas pelo serviço (o controller **nunca** deve ler `mlb_empresa_id`/`company_id` do corpo — T-175-17);
- `PubProduto::proximaQuantidade(familia()->pluck('quantidade_kit')->all())` para a quantidade inicial do painel, e `proximaFase()` para o rótulo "Vincular como Fase N" do 175-04.

O 175-04 (tela do Produto) pode contar com `familia()`, `base()`, `kits()` e `ehKit()`.

O que **não** existe ainda, de propósito: rota, controller, prévia (SKU/título/descrição/estoque calculados), painel, e o disparo da capa no Creative Engine.

## Self-Check: PASSED

- Os 4 arquivos do `files_modified` existem no disco (3 criados, 1 editado).
- Os 6 hashes de commit por task existem em `git log` (`c3e23999`, `18eeac63`, `7eb9ab50`, `b5585818`, `e4d1640a`, `52cc2a10`).
- `artifacts`: `CriarFaseService.php` com **541** linhas (mínimo pedido: 160); `PubProduto.php` com relações, casts e helpers.
- `key_links`: `pub_variante_eixo_valores` aparece 4× e `publicador/` 4× em `CriarFaseService.php`.
- Nenhum stub: `copiarImagens()` está implementada (o guard temporário da Task 2 foi substituído no `52cc2a10`).
- Nenhum arquivo apagado em nenhum dos 6 commits (`git diff --diff-filter=D` vazio).
