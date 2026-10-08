# Fase 172: Ficha do portal completa até o Publicador - Pesquisa

**Pesquisado em:** 2026-10-08
**Domínio:** Laravel 12 + Inertia/React; Mapeamento Estrutural (portal do cliente) -> Publicador ML interno (`pub_*`)
**Confiança geral:** ALTA no mapa do código (lido linha a linha neste worktree); MÉDIA nas decisões de desenho (D-06, multivalor, "principal" do Kit); BAIXA onde marcado `[ASSUMED]`.

<user_constraints>
## User Constraints (de 172-CONTEXT.md)

### Decisões travadas
- **D-01** Estoque POR VARIAÇÃO: inteiro >= 0, anulável (nulo = "não informado" != 0 = "sem estoque"). Coluna nova, anulável, aditiva em `estrutura_produto_variacoes`. Aparece na linha de cada variação, ao lado de SKU/custo.
- **D-02** Descrição POR PRODUTO: texto livre anulável em `estrutura_produtos` (coluna nova, aditiva). Bloco próprio na ficha, rótulo neutro "Descrição do produto", com orientação do que escrever (uso, diferenciais, cuidados, o que vem na caixa), sem citar canal de venda.
- **D-03** SIGILO vale para os dois campos: nada de "mercado", "anúncio", "publicar", "MLB" em rótulo, ajuda, placeholder, erro ou JSON. `assertSemOrigem` (`tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php`) deve cobrir os campos novos.
- **D-04** Migrations só aditivas, colunas anuláveis, sem default que reescreva linha. Provar no MariaDB local com `--path` (learnings §6). Contar linhas de `estrutura_produtos`/`estrutura_produto_variacoes` em prod antes e depois do deploy.
- **D-05** "Só preenche o vazio": o Sincronizar preenche o que está em branco; o que a equipe editou (título, ficha, fotos, preço, estoque, descrição) NUNCA é sobrescrito. Produto sem rascunho chega completo. Reexecutar é idempotente.
- **D-06** Cores viram VARIAÇÕES de UM rascunho: as N ofertas Simples de um mesmo produto do portal (uma por variação, via `estrutura_ofertas.variacao_id`) entram como N variações de um único rascunho, cada uma com seu SKU (`SELLER_SKU` = `codigo`), estoque e fotos. Pesquisa deve propor como agrupar sem quebrar `pub_produtos`/rascunhos existentes em prod (#459 tem rascunhos ancorados em produtos 1-4) e sem apagar histórico.
- **D-07** Combo/Kit/Combit: Combo (mesmo produto xN) herda categoria, ficha, fotos, medidas e descrição; estoque = piso(estoque da variação / N). Kit/Combit: categoria e ficha do produto PRINCIPAL (a pesquisa define); fotos de todos os componentes; estoque = menor piso(estoque do componente / quantidade); descrições de todos juntas como matéria-prima do MAG T8. Composição lida de `EstruturaOfertaComponente`.
- **D-08** Mapeamentos: `categoria_ml_id` -> `pub_rascunhos.categoria_id` (schema carregado como hoje); `estrutura_produto_atributos` -> `pub_rascunho_atributos` com `origem='portal'` (multivalor gravado como NOMES unidos por `' | '` com `valor_id` nulo: resolver ids pelo nome contra o schema; número+unidade -> `value_number`/`value_unit`); variações eixo/valor -> `pub_eixos`/`pub_eixo_valores`/`pub_variantes`, `codigo` -> `SELLER_SKU`, estoque -> `pub_variantes.estoque`; volumes -> `SELLER_PACKAGE_*` (somar volumes como a 167); imagens por variação -> `pub_imagens` + `pub_imagem_atribuicoes`, dedupe por `sha256`; descrição do cliente guardada como matéria-prima (não vai para `pub_rascunhos.descricao`).
- **D-09** Descrição no Publicador: a do cliente aparece no editor como referência e alimenta o prompt MAG T8 de descrição existente (junto da ficha como `specs`). Se `pub_rascunhos.descricao` vazio, o editor oferece/gera a descrição tratada (job na fila `high`, cache, a TELA aplica pelo caminho normal de edição - learnings `publicador-ml.md` §10). Prompts MAG T8 intocados; só muda a entrada.

### Critério de Claude (discretion)
- Onde guardar a descrição crua do cliente do lado do Publicador (preferido: ler ao vivo do produto, como `DadosEfetivosService` faz com título/preço).
- Se o MAG T8 da descrição dispara sozinho ao abrir o rascunho vazio ou só por botão.
- Feedback do Sincronizar (resumo "N produtos, M variações, K fotos trazidas; X campos mantidos porque já estavam preenchidos").

### Ideias adiadas (FORA DE ESCOPO)
- IA no Planejamento (sugerir composições); Sincronizar no sentido inverso (Publicador -> portal); avisar a equipe quando o cliente alterar a ficha depois do Sincronizar.
- Fora da fase também: campo Modelo sem repetir palavras do título, renomear Sugestões -> Planejamento (trabalho direto paralelo), publicar automaticamente (publicação segue manual; E2E só na #459).
</user_constraints>

<phase_requirements>
## Requisitos locais da fase (a fase não tem REQ-IDs; derivados do CONTEXT)

| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| FP172-01 | Estoque por variação na ficha do portal (coluna, validação, tela, JSON) | §Portal: estoque; Normalizador/Cadastro/Linhas/JS |
| FP172-02 | Descrição por produto na ficha do portal (coluna, endpoint, bloco) | §Portal: descrição |
| FP172-03 | Sigilo cobre os campos novos | §Sigilo; `assertSemOrigem` |
| FP172-04 | Migrations aditivas provadas no MariaDB | §Migrations |
| FP172-05 | Agrupamento D-06: N ofertas Simples -> 1 rascunho com N variantes, sem quebrar o que existe | §D-06 |
| FP172-06 | Sincronizar completo só-preenche-vazio e idempotente (categoria, ficha, variações, estoque, pacote, fotos) | §Pontos de escrita, §Categoria, §Multivalor, §Imagens, §Eixos, §Volumes |
| FP172-07 | Combo/Kit/Combit derivam o que der | §Combo/Kit/Combit |
| FP172-08 | Descrição do cliente como referência + matéria-prima do MAG T8 | §D-09 |
| FP172-09 | Isolamento entre empresas, idempotência, trava piloto | §Modelo de ameaças, §Piloto |

</phase_requirements>

## Resumo

A fase é, no fundo, escrever UM serviço novo (`PortalParaRascunhoService`) que lê o produto do portal e grava no rascunho `pub_*` **pelo mesmo caminho e pelas mesmas travas** que a IA já usa. Esse molde já existe e é a melhor referência do repositório: `IaParaRascunhoService` (`app/Services/Publicador/IaParaRascunhoService.php`). Ele já resolve "só preenche o vazio" (`sobTrava`, :278-295; `preenchido`, :636-641), grava atributos por chave (`mesclarAtributos`, :182), aplica variações (`aplicarVariacoes`, :333-404 via `salvarEixos` + `salvarVariantes`), pacote `SELLER_PACKAGE_*` (:584-597), e recusa rascunho publicado (`intocavel`, :77-83). O Sincronizar novo é a mesma coreografia com outra fonte (o portal, confiável) em vez da IA (não confiável).

O ponto de desenho difícil é o D-06. Recomendação: **um `pub_produtos` "grupo" por `estrutura_produtos`**, ligado por uma coluna nova anulável `pub_produtos.estrutura_produto_id` (unique, FK SET NULL), mantendo `oferta_id` como âncora (a oferta da 1ª variação). Os `pub_produtos` antigos (1 por oferta) ficam intactos; nenhum backfill. As variações viram variantes do único rascunho do grupo (`pubr_produto_uq` continua valendo). A ligação variante -> oferta (preço/título por variante) se resolve em leitura pelo `SELLER_SKU`, sem tabela nem coluna extra.

Três achados que mudam o plano: (1) o Publicador **não suporta atributo multivalor** hoje (`values_multi` existe no model mas nada lê/grava; o payload só emite um valor), então o multivalor do portal precisa de decisão; (2) o portal aceita **WebP** e o Publicador só aceita JPG/PNG >= 500 px (`ValidadorImagem`), então parte das fotos não vai passar e o resumo precisa dizer quais; (3) a trava piloto (`publicador.contas_liberadas`, antigo `empresas_piloto`) **não protege o Sincronizar hoje** (só foto no ML, conferência L3 e publicação) - o enriquecimento novo é local, mas a decisão de gatear precisa ser explícita.

**Recomendação principal:** novo `PortalParaRascunhoService` sobre o molde do `IaParaRascunhoService`; grupo por `estrutura_produto_id` (1 coluna nova em `pub_produtos`); fotos via `ImagemAssetService::receber` + `EditorRascunhoService::colocarFotoNoGrupo` sem subir ao ML; descrição via Job novo `GerarDescricaoIaJob` (arquivos novos, porque outra sessão mexe em `PalavrasChaveService`/`AnaliseAnuncioService`).

## Mapa de Responsabilidade Arquitetural

| Capacidade | Tier primário | Secundário | Racional |
|------------|---------------|------------|----------|
| Estoque/descrição na ficha | Browser (React) + API/Backend (validação) | Banco | Validação e sigilo no servidor; JS só exibe (PORTAL-02: nada de regra no JS) |
| Agrupar ofertas em 1 rascunho | API/Backend (serviço) | Banco (coluna + unique) | Unique no banco impede corrida; regra no serviço |
| Preencher rascunho só no vazio | API/Backend (serviço sob `lockForUpdate`) | — | Mesma trava de editor/IA/publicação (learnings §9) |
| Resolver ids de atributo/eixo | API/Backend contra `ml_categoria_schemas` | ML (app token, só leitura) | Schema é fonte única; nunca confiar no nome cru |
| Copiar imagens | API/Backend (disco `local` privado) | — | Disco privado nos dois lados; nada vai ao navegador |
| Descrição tratada (MAG T8) | Fila `high` (Job) + cache | Tela aplica | Evita segunda escrita concorrente no rascunho (learnings §10) |
| Mostrar "Descrição do cliente" | API (`estado()`) + React | — | Lida ao vivo do portal; sem cópia defasada |

## Pontos de leitura/escrita do rascunho (pergunta 2)

Caminho único de escrita - todo método abaixo termina em `tocar()` (sobe `revisao`, derruba VALIDATED -> DRAFT):

| Necessidade | Método (evidência) | Observação |
|---|---|---|
| Criar rascunho | `RascunhoRepository::criar` `RascunhoRepository.php:40-51` | Nasce com variante `__single__` SEM SKU e alvos da régua. `EditorRascunhoService::abrir` `:64-91` cria + grava `SELLER_SKU` do `skuExibido()` e chama `lerContaSeVencida` (HTTP ao ML) - **o Sincronizar não deve chamar `abrir`**; extrair `criarVazio(PubProduto)` (alvos + variante única) para os dois usarem |
| Trava | `RascunhoRepository::travar` `:174-177` + padrão `sobTrava` `IaParaRascunhoService.php:278-295` | Travar PRIMEIRO, ler snapshot DEPOIS, mesma transação; leitura de schema (pode ir ao ML) FORA da trava |
| Categoria | `EditorRascunhoService::trocarCategoria` `:139-173` | Reusar (pergunta 3) |
| Atributos | `RascunhoRepository::mesclarAtributos` `:138-149` | Grava só as chaves dadas. **Nunca** `gravarAtributos` (`:118-130`, apaga o que não vier) |
| Eixos | `EditorRascunhoService::salvarEixos` `:181-206` | Regenera variantes; copia dados do ancestral (armadilha, ver Pitfall 3) |
| Variantes (estoque/SKU/preço) | `EditorRascunhoService::salvarVariantes` `:212-240` | Chave ausente = intacta; campos: `estoque`, `precos`, `atributos`, `estoque_depositos` |
| Foto no grupo | `EditorRascunhoService::colocarFotoNoGrupo` `:265-278` | Já trava; "já está no grupo" não grava |
| Descrição/garantia/flags | `EditorRascunhoService::salvar` `:100-131` | `fotos_por_variante`, `incluir_geral`, `descricao` |
| Intocável | `IaParaRascunhoService::intocavel` `:77-83` | Por FATO (publicação rodando ou item CREATED), não só status (WR-B03) |

**Definição de "vazio" por campo (D-05):**

| Campo | Vazio quando | Onde medir |
|---|---|---|
| Categoria | `pub_rascunhos.categoria_id` NULL ou '' | `$r->categoria_id` |
| Atributo | ausente em `$snap->atributos[id]` OU sem `value_id`, sem `value_name` (trim) e sem `value_number` | `IaParaRascunhoService::preenchido` `:636-641` (copiar a regra; `value_id='-1'` "Não se aplica" conta como PREENCHIDO) |
| Variante: estoque | `pub_variantes.estoque` NULL (0 é preenchido) | `Variante->dados['estoque'] === null` |
| Variante: SKU | `atributos.SELLER_SKU` ausente/vazio **ou igual ao SKU de outra variante** (cópia do ancestral, Pitfall 3) | `Variante->dados['atributos']['SELLER_SKU']` |
| Eixo/valor | valor não existe no eixo (comparar `ChaveCanonica::texto(nome)`, não a chave `id:`/`txt:`) | snapshot `eixos` |
| Imagens | o GRUPO da variante tem zero `pub_imagem_atribuicoes` (não "a imagem não existe") | `RascunhoSnapshot->imagens` filtrado por `grupo` |
| SELLER_PACKAGE_* | atributo ausente/vazio (cada um separadamente) | idem atributos |
| Descrição | `pub_rascunhos.descricao` NULL/'' (trim) - **a fase não escreve aqui** (D-08); só a TELA aplica o texto tratado | `$r->descricao` |
| Garantia/título/preço | **a fase não toca** | — |

## D-06: como agrupar sem quebrar prod (pergunta 1)

**Fatos (evidência):**
- `pub_produtos.oferta_id` é unique (`pubprod_oferta_uq`, migration `2026_10_02_100000_create_pub_produtos_table.php`); o Sincronizar cria 1 produto por oferta (`PublicadorSincronizaPortalService.php:32-53`).
- `pub_rascunhos.produto_id` é NOT NULL + unique (`pubr_produto_uq`, `2026_10_02_100100_...php` passo 5): **um rascunho por `pub_produtos`**. Logo, N variações num rascunho exige UM `pub_produtos` para N ofertas.
- `PubProduto::skuExibido()`/`nomeExibido()` seguem a oferta ao vivo (`PubProduto.php:99-106`); `DadosEfetivosService::daProduto` lê título planejado e preço de UMA oferta (`DadosEfetivosService.php:29-65`) e `RascunhoSnapshot::comEfetivos` aplica o mesmo mapa de preço a TODAS as variantes (`RascunhoSnapshot.php:58-80`).
- `ProgramasPublicadorService::situacaoPortal` conta ofertas da empresa x `pub_produtos.oferta_id` distintos (`ProgramasPublicadorService.php:324-358`); com grupo, as demais ofertas do produto contariam como "novas" para sempre.
- A oferta Simples nasce 1:1 da variação e seu `sku` acompanha `variacao.codigo` (`ProdutoCadastroService.php` `sincronizarOferta` ~:240-255; `nomeDaOferta` :209-215 = "Produto — valor").
- Não há código que dependa de "1 oferta = 1 item publicado": o ML recebe itens por (tipo de anúncio x variante) a partir do snapshot (`pub_publicacao_itens.variante_chave`); `MigracaoAnunciarAntigo::planejar` só consulta `produto.oferta_id` (`MigracaoAnunciarAntigo.php:62`).

**Desenho recomendado ("produto-grupo"):**

1. **Migration A (única que toca tabela `pub_*` com dado):** `pub_produtos.estrutura_produto_id` `unsignedBigInteger NULL` + unique `pubprod_eprod_uq` + FK `pubprod_eprod_fk` -> `estrutura_produtos.id` **ON DELETE SET NULL** (coluna anulável: sem erro 1830). Sem backfill. Se o produto do portal for apagado, o `pub_produto` sobrevive como produto avulso com `oferta_id`/sku/nome próprios (mesma filosofia do D27).
2. O grupo é UM `pub_produtos` com `origem='portal'`, `estrutura_produto_id = P`, `oferta_id` = oferta da variação de menor `ordem` (âncora: mantém `produto->oferta`, a régua de MLB, título planejado e `Soltar...` funcionando sem mudança), `sku` = `estrutura_produtos.codigo` (grupo) ou o da âncora, `nome` = `estrutura_produtos.nome`.
3. `skuExibido()`/`nomeExibido()`: se `estrutura_produto_id` e produto carregado, devolver código/nome do PRODUTO (não "Produto — Preto" da âncora). Mudança pequena em `PubProduto.php`.
4. **Sincronizar (regra por produto do portal P com ofertas Simples):**
   - Se existe `pub_produtos.estrutura_produto_id = P`: usa.
   - Senão, se alguma oferta de P já tem `pub_produtos` legado COM rascunho **e** esse rascunho NÃO é intocável (sem publicação): **adotar** o mais antigo (UPDATE só de `estrutura_produto_id`; nenhuma linha apagada). A variante `__single__` dele vira ancestral das N variantes (o regenerador copia os dados; SKU duplicado é consertado, Pitfall 3).
   - Senão, se o legado tem publicação/itens CREATED (intocável): **não adotar**; criar o grupo novo e deixar o legado como está (aviso no resumo: "produto X já publicado como anúncio avulso; o grupo novo foi criado separado").
   - Outros legados de P sem rascunho: deixar; listar no resumo como "duplicados antigos" (a equipe arquiva; nenhuma exclusão automática).
   - Combos/Kits/Combits (oferta sem `variacao_id`) e ofertas antigas sem `variacao_id`: **continuam 1 `pub_produtos` por oferta** (fluxo atual), com `estrutura_produto_id` NULL; recebem o preenchimento derivado (D-07).
5. `situacaoPortal`: contar oferta como coberta se `pub_produtos.oferta_id` OU se a variação dela pertence a P com `pub_produtos.estrutura_produto_id = P`. (Ajuste de SQL + teste em `SincronizaPortalTest`.)
6. **Preço/título por variante:** `DadosEfetivosService` passa a devolver também `precos_por_variante` (SKU normalizado -> {tipo: preço}) lendo a Precificação da oferta de cada variante (casar `SELLER_SKU` da variante com `EstruturaOferta::normalizarSku`, escopado por `company_id`). `comEfetivos` ganha parâmetro opcional `$porVariante = []` (default compatível: callers atuais não mudam). Sem match, cai no preço do âncora (comportamento de hoje). Título: o da âncora (ruleset ECF proíbe cor no título, então o título planejado do 1º valor é o título do grupo; revisar se a aba Anúncios tiver títulos diferentes por variação).
7. **Alternativa descartada** (coluna `pub_variantes.oferta_id`): mais robusta se a equipe editar o SKU, mas é um 2º ALTER em tabela com dado em prod. Guardar como plano B; começar sem.
8. **Rollback:** `down()` solta FK -> unique -> coluna (nessa ordem, 1553). Linhas de grupo seguem válidas como produto de 1 oferta com N variantes; nenhum dado se perde. Código novo checa `Schema::hasColumn` apenas nos testes de migration, não em produção.

**Verificar em produção antes de implementar (somente leitura, `.vps_cmd.sh` LÊ):** `SELECT COUNT(*) FROM pub_produtos; ... WHERE origem='portal'`; quantos têm `pub_rascunhos`; quantos rascunhos têm `pub_publicacoes`/itens `CREATED`; `SELECT produto_id, COUNT(*) FROM estrutura_produto_variacoes WHERE company_id=459 GROUP BY produto_id`; e quais `pub_produtos.oferta_id` apontam para ofertas com `variacao_id` NOT NULL. `[ASSUMED]` que a #459 tem pub_produtos 1-4 sem publicação (CONTEXT diz "rascunhos ancorados em 1-4"); confirmar antes de decidir a adoção.

## Categoria (pergunta 3)

`EditorRascunhoService::trocarCategoria($r, $id)` (`:139-173`): lê o schema novo (e o anterior) FORA da trava via `CategorySchemaRepository::obter`, trava, relê, e `gravarCategoria` (`categoria_id`, `dominio_id`, `schema_hash` - `RascunhoRepository.php:315-319`). Com categoria anterior NULL não há migração de atributos (só `$r->categoria_id && ...` migra, `:157`), então **reusar direto**. Regras do Sincronizar: categoria só se `categoria_id` vazio e produto com `estadoCategoria()` ∈ {confirmada, nao_validada} (`EstruturaProduto.php`); se o rascunho já tem OUTRA categoria, manter e avisar. Erro do schema (`RegraViolada`) vira aviso no resumo e o resto do preenchimento que depende de schema (ficha, pacote, eixos) é pulado - igual ao `IaParaRascunhoService` (:123-127, :147-149).

Depois de setar a categoria, classificar com `ClassificadorAtributos` + `ContextoClassificacao($condicao, $idsDeEixo)` (como `schemaDoRascunho` `:522-535`) para só gravar atributo com `papel === PRODUCT` e `secao !== SECAO_EMBALAGEM` e que não seja eixo (`atributosDaIa` `:544-581` é o molde).

## Multivalor (pergunta 4) e resolução de valores

- Portal grava lista como NOMES unidos por `FichaTecnicaDoProduto::SEPARADOR = ' | '`, `valor_id` NULL (`FichaTecnicaDoProduto.php` método `multivalor`). Dividir por `' | '` e resolver cada nome contra `$def->valores` (`[{id,name}]`) comparando `ChaveCanonica::texto` (sem acento, minúsculo - o mesmo critério de `ValorAtributo::pelaLista`).
- Lista simples: o portal já guarda `valor_id`; confirmar que existe em `$def->valores` (o schema pode ter mudado); se não existir, tentar pelo nome; se ainda não e `!aceitaTextoLivre`, **pular com aviso** (nunca gravar valor fora da lista - erro 3510).
- Sim/Não (`boolean`): portal grava "Sim"/"Não" como texto; resolver pelo nome na lista de valores do atributo.
- Número+unidade: `value_number` = `valor` (string numérica), `value_unit` = unidade do portal se estiver em `$def->unidades` (comparação sem caixa), senão `unidadePadrao`.
- Texto: `value_name` (<= `maxLength`).
- **O Publicador não tem multivalor:** `PubRascunhoAtributo` casta `values_multi`, mas nenhum ponto lê ou escreve a coluna (grep em `app/`: só o cast) e `ValorAtributo::paraPayload` emite UM valor. Recomendação sem mexer no payload: gravar a **1ª opção** em `value_id/value_name`, **todos os ids** em `values_multi`, `revisar = true`, e contar no resumo "N campos com várias opções: confira". Suportar envio de `values` ao ML é trabalho do Publicador fora desta fase -> **Pergunta aberta 1**. `[ASSUMED]` que o ML aceita `values:[{id},{id}]` para atributo `multivalued` (não verificado nesta sessão).
- Origem: `'origem' => 'portal'`; `pub_rascunho_atributos.origem` é `varchar(10)` ('portal' cabe). Conferir se a tela/`CampoAtributo` trata origem desconhecida (hoje há 'user', 'ia', 'migrated', 'auto').

## Eixos de variação (pergunta 6)

| Eixo do portal (`EstruturaProdutoVariacao::EIXOS`) | Atributo ML | Observação (fixtures em `tests/fixtures-ml/sondagem/publico/categorias`) |
|---|---|---|
| cor | `COLOR` | `allow_variations` + `defines_picture`; valor livre aceito (`allow_custom_value: true`, learnings §11). **`MAIN_COLOR` é `variation_attribute` e lista FECHADA - o Sincronizar não preenche; o efeito do front (`tomDaCor`) preenche ao abrir** |
| tamanho | `SIZE` | só em algumas categorias (MLB31447 sim; MLB189007 não) |
| voltagem | `VOLTAGE` | MLB189007 sim, string com 21 valores |
| material | `MATERIAL` | MLB47097: tags `[]` = **não** é eixo permitido |
| sabor | `FLAVOR` `[ASSUMED]` | não conferido nas fixtures |
| outro | eixo customizado `~custom` (nome = rótulo) | no máximo um por rascunho (V-VAR-02) |

Regra: usar o atributo do ML **somente se** `$schema->atributo($id)?->podeSerEixo`; senão cair no eixo customizado com o rótulo do portal e `definesPicture=false` (a tela liga `fotos_por_variante` sozinha). `max_eixos` = `config('publicador.max_eixos', 3)`. Valor: `value_id` quando o nome casa com a lista do atributo (normalizado), senão texto livre se `aceitaTextoLivre`. Variações do produto com eixos diferentes entre si ou sem `valor` num produto com 2+ variações: usar o eixo dominante, pular as demais com aviso "revisar" (não dá para formar valor de eixo).

Idempotência do eixo: ao reexecutar, casar valor existente por `ChaveCanonica::texto(valueName)` (a equipe pode ter trocado `txt:preto` por `id:...`); só ACRESCENTAR valores que faltam (reenviar a lista de valores existentes + novos para `salvarEixos`, que regenera preservando dados); nunca remover valor/eixo. `IaParaRascunhoService::aplicarVariacoes` pula quando já há eixos (`:339`) - o Sincronizar precisa ser mais fino (acrescenta) porque o portal ganha cor nova com o tempo.

## Imagens (pergunta 5)

- Origem: `estrutura_produto_variacao_imagens.caminho` no disco `local` (`VariacaoImagensService::DISCO`, pasta `estrutura/{company}/produtos/{produto}/variacoes/{variacao}/uuid.ext`). Destino: `ImagemAssetService::receber($r, $conteudo, $nome)` (`ImagemAssetService.php:42-68`) - disco `local`, `publicador/{rascunho}/{sha}.ext`, **dedupe por sha256 no rascunho** (`:50`, unique `pubim_sha_uq`), L1 (JPG/PNG, <= 10 MB, lados >= 500 px).
- `receber()` chama `enviarAoMl` no fim (`:67`): para conta liberada isso sobe a foto ao ML durante o Sincronizar. Recomendação: acrescentar parâmetro `bool $enviar = true`; o Sincronizar passa `false` (foto fica `pending` e sobe em `enviarPendentes` antes da conferência/publicação). Mantém o Sincronizar 100% local.
- **WebP:** o portal aceita `image/webp` (`VariacaoImagensService::EXTENSAO_POR_MIME`); `ValidadorImagem::FORMATOS` não. Essas fotos devolvem problema bloqueante V-IMG-01 e **não entram**; reportar "K fotos WebP/pequenas não trazidas (peça JPG/PNG)". Converter para JPG com GD é opcional (decidir; `[ASSUMED]` GD disponível em prod).
- Grupo: depois de `salvarEixos`, calcular a chave com `ResolvedorGruposImagem::chaveDoGrupo($variante, $eixos, $fotosPorVariante)` (`ResolvedorGruposImagem.php`) sobre o snapshot - com eixo `COLOR` que `defines_picture` vira `COLOR=id:52049` ou `COLOR=txt:preto`; produto de variante única -> `GENERAL`. Usar o mesmo cálculo do resolvedor evita grupo "fantasma". Atribuir com `colocarFotoNoGrupo($r, $imagem, $grupo)` na ordem `ordem` do portal (capa = `ordem` 0); só se o grupo está vazio (D-05).
- Se há 2+ variantes e nenhum eixo `defines_picture`, ligar `fotos_por_variante=true` via `salvar` (a tela faz o mesmo - learnings §10) apenas quando o rascunho ainda não tem fotos.
- Limite `max_pictures_per_item` do schema: não estourar; o excedente fica de fora e entra no resumo (nunca cortar em silêncio, RN-63).
- Leitura do arquivo: `Storage::disk('local')->get($caminho)`; arquivo sumido (linha sem arquivo) = pular e contar.

## Volumes -> SELLER_PACKAGE_* (pergunta 7)

- Pacote da 167 (`LogisticaProduto::pacote`): C = maior comprimento, L = maior largura, A = SOMA das alturas, peso = SOMA dos pesos (kg). Reusar a função (não reimplementar): montar `volumes` como `[{c,l,a,kg}]` e chamar `LogisticaProduto::pacote()`.
- Mapeamento: `SELLER_PACKAGE_LENGTH` = C cm, `SELLER_PACKAGE_WIDTH` = L cm, `SELLER_PACKAGE_HEIGHT` = A cm, `SELLER_PACKAGE_WEIGHT` = kg x 1000 em **g inteiro**. Gravar como `value_name` "N cm"/"N g" (formato de `MigracaoAnunciarAntigo.php:115-120` e `IaParaRascunhoService::pacote` :584-597; a tela lê `value_name`, `ferramentas.js:48-70`). Só aceitam g e cm (learnings §10); se o atributo da categoria não tiver essas unidades, pular com aviso.
- `SELLER_PACKAGE_*` é atributo do RASCUNHO, mas o portal tem volumes POR variação: escolher o pacote mais conservador (maior peso real) entre as variações e marcar `revisar=true` quando os pacotes divergirem. Ignorar variação sem volumes. Só preencher cada atributo se vazio.

## Combo, Kit, Combit (pergunta 8)

- Dados: `EstruturaOfertaComponente(oferta_id, componente_id, quantidade)` (`app/Models/EstruturaOfertaComponente.php`); `componente_id` aponta para uma oferta Simples (que tem `variacao_id` -> variação -> produto). Unidades da oferta = soma das quantidades (docblock do model).
- **Combo** (1 componente, quantidade N): categoria, ficha, descrição e fotos do produto do componente; pacote = volumes da variação repetidos N vezes e empilhados por `LogisticaProduto::pacote` `[ASSUMED]` (o CONTEXT diz "herda medidas"; se o usuário quiser as medidas de 1 unidade, trocar); estoque = piso(estoque da variação / N); SKU da variante única = SKU da oferta combo.
- **Kit/Combit** (2+ componentes): "principal" = o componente cujo produto tem **maior custo total** (`custo da variação x quantidade`; empate -> menor `ordem` do componente). Racional: o CONTEXT sugere "maior custo"; o custo está em `estrutura_produto_variacoes.custo` e é a única medida de peso comercial disponível nos dados. Alternativa (lado do par): `estrutura_tipo_pares` da Fase 168 - **não investigada a fundo** -> Pergunta aberta 2. Categoria e ficha: do principal. Fotos: principal primeiro, depois os demais componentes, dedupe por sha. Estoque = menor piso(estoque do componente / quantidade); se algum componente tem estoque NULL, estoque do kit fica NULL (não inventar). Pacote: soma dos volumes de todos os componentes x quantidade. Descrição: concatenação "Nome: texto" dos componentes (matéria-prima do MAG T8, só leitura ao vivo).
- Nada disso é "grupo": um `pub_produtos` por oferta composta, como hoje.
- Componente sem `variacao_id` (oferta antiga) ou sem ficha: pular o campo e contar no resumo.
- Dados em prod: não há como saber daqui quais combos existem na #459; checar `SELECT fase, COUNT(*) FROM estrutura_ofertas WHERE company_id=459 GROUP BY fase`.

## D-09: descrição (pergunta 9)

- Entradas da IA (`AnaliseAnuncioService::descricao($produto, $loja, $specs, $analise)`, `AnaliseAnuncioService.php:83-90`, prompt em `:418-447`): `$produto` (nome), `$loja`, `$specs` (texto livre; `blocoSpecs` `:289-294` o embute como "Especificações Técnicas Reais do Produto (baseie-se nestes dados, não invente)"), `$analise` (array com `jtbd`/`puv`, opcional - sem eles o contexto some, `:424-426`). O MAG T8 completo (`GerarAnaliseAnuncioIaJob`) faz `analise()` -> `titulos()` -> `descricao()` -> ficha. **Mudar só a entrada = montar `$specs`** com: ficha técnica preenchida ("Material: Madeira"), medidas, e uma linha "Descrição fornecida pelo cliente: ...". Texto do prompt intocado.
- Dois caminhos, ambos baratos:
  1. **Novo (o que o D-09 pede):** `DescricaoIaService` + `GerarDescricaoIaJob` (fila `high`, `tries=1`, `timeout=300`, `failOnTimeout`, prazo menor que o timeout, `->onQueue('high')` no construtor - molde `GerarPalavrasChaveIaJob.php`), cache `publicador:descricao:{rascunho}` com `pedido` uuid e `status` rodando/pronto/erro, TTL 1800 s, `concluir()` ignora pedido velho (molde `PalavrasChaveService.php:60-95, 224-232`). O Job chama `analise()` e `descricao()` (2 chamadas) e **não grava** no rascunho; a tela aplica por `salvar({descricao})`. Limpar HTML como `IaParaRascunhoService::limparDescricao` (:644-650).
  2. **Reuso:** `MlbAnuncioController::iaAnaliseStorePublicador` (`:3475-3525`) já aceita `specs` (`max:8000`, :3406) mas o front não envia (`useIaDoPublicador.js` envia só `produto_id, produto, substituir`). Quando `specs` vier vazio, preencher no servidor com o mesmo montador -> o "Anunciar por IA" existente passa a usar ficha + descrição do cliente de graça. Truncar em 8000.
- **Colisão de arquivos:** no worktree há alterações de outra sessão em `AnaliseAnuncioService.php`, `PalavrasChaveService.php`, `GerarPalavrasChaveIaJob.php`, `usePublicador.js`, `ferramentas.js`, `MlbPublicadorController.php`, `TipoDoProduto.php`, `config/estrutura_geracao.php`. A fase deve usar **arquivos novos** (serviço, job, hook `useDescricaoIa.js`) e tocar o mínimo em arquivo compartilhado (`routes/web.php`, `EtapaDetalhes.jsx`, `EditorRascunhoService::estado`). Reler `git status` antes de cada plano.
- Tela: `EtapaDetalhes.jsx:160-176` (`function Descricao`, textarea `data-campo="descricao"`, `m.mudarRasc({descricao})`). Acrescentar acima do textarea um painel recolhível "Descrição do cliente" (somente leitura, vem de `estado().portal.descricao_cliente`, lido ao vivo via `produto->oferta->variacao->produto` / componentes) e, com `descricao` vazia, o botão "Gerar descrição com IA" (estado vindo do cache; ao `pronto`, preenche o textarea pelo `mudarRasc`). Disparo automático ao abrir: **não recomendado** (custa 2 chamadas de IA por abertura); só por botão. Decidir -> discrição.
- Sem Company/oferta (produto do Publicador) `descricao_cliente = null` e o botão usa só a ficha do rascunho.

## Lado do portal (pergunta 10)

**Estoque (FP172-01)** - molde: `custo`.
- Migration: `estrutura_produto_variacoes.estoque` `unsignedInteger NULL` `after('custo')`.
- `EstruturaProdutoVariacao`: `$fillable[]='estoque'`, cast `integer` (cuidado: cast de NULL permanece NULL).
- `NormalizadorDeLinha::normalizar` (`NormalizadorDeLinha.php`, campos em :53-69): novo campo `estoque` inteiro >= 0, máx razoável (ex. 99.999.999), `null` explícito = limpar e entra em `presentes`; chave ausente/'' = "não mexi". Mensagem de erro neutra.
- `ProdutoCadastroService` (criação ~:575-600, atualização ~:603-616): incluir `estoque` no `fill`; **na criação NÃO copiar a da 1ª variação** (ao contrário de eixo/custo/volumes em :578-580 e :596-600: estoque não é herdável).
- `ProdutoLinhas::linha` (`ProdutoLinhas.php`, array final): `'estoque' => $variacao->estoque`.
- JS: `CartaoVariacao.jsx` (grade `lg:grid-cols-[203fr_168fr_185fr_158fr]` -> 5 colunas, campo "Estoque (un.)" após Custo, `inputMode="numeric"`); `lib/produtosEstrutura.js`: `CAMPOS_EDITAVEIS` (:177), `linhaDoServidor` (texto), `linhaParaServidor` (:188-238, mesmo ramo do `custo`, incluindo `novaDeProdutoGravado`); `useFichaProduto.js`: `linhaEmBranco` e **`novaVariacao` (`...base` copia o estoque da 1ª - zerar `estoque: ''`)**.
- Planilha-modelo: **não** adicionar coluna. As 11 colunas espelham a aba "Produtos" da 3Planejamento "com os mesmos nomes e a mesma ordem" (`ModeloProdutosXlsx.php`, docblock) e há testes de contrato (`ModeloEImportacaoTest`). Estoque e descrição entram só pela ficha. -> Pergunta aberta 3 se o usuário quiser coluna opcional.
- Lista SKUs/Precificação: não exibem estoque de cadastro; o `estoque` do `EstruturaVisaoService` é o do acervo ML (outro conceito, tabela diferente) - sem colisão.

**Descrição (FP172-02)** - molde: ficha técnica.
- Migration: `estrutura_produtos.descricao` `text NULL` `after('categoria_ml_caminho')`; `$fillable`.
- Endpoint: `PUT /estrutura/produtos/{produto}/descricao` (nome `portal.auth.estrutura.produtos.descricao`, `whereNumber`, `throttle:30,1,...`, dentro do grupo portal existente - o módulo já está na allowlist de `RestringeDominioDoPortal`; checar o grupo antes). Controller: 404 de outra empresa ANTES da validação (molde `gravarFichaTecnica` `PortalEstruturaProdutosController.php:369-398`); `validate(['descricao' => 'nullable|string|max:5000'])`; serviço `DescricaoDoProduto::gravar` (trim, vazio -> NULL, `RegistroEstrutura::registrar`). O middleware `ConvertEmptyStringsToNull` torna '' em null = "limpar" aqui é intencional.
- `renderFicha` (`:530-547`): `'descricao' => $produto?->descricao`. Hook `useDescricaoProduto` gravado em `salvar()` logo depois de `tecnica.gravar(r.produtoId)` (`useFichaProduto.js` ~:278-289), mesmo tratamento de produto novo (só grava depois de existir id).
- Bloco "Descrição do produto" na ficha (junto da ficha técnica), texto de ajuda: "Conte para que serve, os diferenciais, os cuidados e o que acompanha o produto." Sem as palavras proibidas.
- **Sigilo (FP172-03):** estender `assertSemOrigem` (`FichaTecnicaDoProdutoTest.php`) para varrer o JSON de `ficha` (props Inertia) e a resposta do `PUT` com `/mercado|an[uú]ncio|publicar|\bmlb|\bml\b/iu` (mesma regex de `FichaTecnicaDaCategoria::TERMOS_PROIBIDOS`). Cuidado: o cliente digita a descrição - o teste usa texto neutro; não filtrar o que o cliente escreve. `ml_conectado` já existe nas props (`:541`) e é anterior à fase; não é rótulo do campo.

## Migrations (pergunta 11)

| # | Arquivo sugerido | Tabela | Mudança |
|---|---|---|---|
| 1 | `2026_10_08_15xxxx_add_estoque_to_estrutura_produto_variacoes.php` | `estrutura_produto_variacoes` | `estoque` int unsigned NULL after `custo` |
| 2 | `2026_10_08_15xxxx_add_descricao_to_estrutura_produtos.php` | `estrutura_produtos` | `descricao` text NULL |
| 3 | `2026_10_08_15xxxx_add_estrutura_produto_id_to_pub_produtos.php` | `pub_produtos` | col + `pubprod_eprod_uq` + `pubprod_eprod_fk` SET NULL |

Prefixo: já existe `2026_10_08_140000_semear_tipos_e_pares_de_banheiro.php` (outra sessão, não commitada); usar `150000+` e conferir `ls database/migrations | tail`.

Regras (learnings §6; molde `2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php`): cada DDL num `Schema::table` separado sob `hasColumn`/`hasIndex`/`hasForeignKey`; **sem** try/catch em volta de DDL; nome de índice/FK explícito e < 64 caracteres (erro 1059); `down()` solta FK -> unique -> coluna (erro 1553); detectar `mysql` OU `mariadb` (`emMysql()`, WR-B06); nada de `json`/`enum`/`timestamp()` solto; sem default (coluna anulável sem default não reescreve linha). Acrescentar os 3 arquivos à lista `MIGRACOES` de `MigracoesDaFaseDetectamMariaDbTest` (varredura anti `=== 'mysql'`).

**Prova (D-04):** `.env` do worktree aponta para o MariaDB local compartilhado; usar SOMENTE `migrate --path=<arquivo>` / `migrate:rollback --path=<arquivo>`, nunca `migrate` puro. Antes/depois: `migrate:status --path=`; contagem de `estrutura_produtos`, `estrutura_produto_variacoes`, `pub_produtos`, `pub_rascunhos` (iguais). Ciclo up -> down -> up. Para qualquer semente/conferência com dado, usar SQLite em arquivo no scratchpad **com `guarda-sqlite.php`** (padrão do `168-16-PLAN.md` passo 1: bootstrap do app, imprime `database.default` e nome do banco, `exit(1)` se não for sqlite/arquivo esperado, e `&&` encadeado com as mesmas variáveis). Testes: nenhum `->change()` (o `down()` de `2026_09_14_100000` quebra o rollback do SQLite - learnings publicador §9).

**Prod (contagens a registrar antes e depois do deploy):** `estrutura_produtos`, `estrutura_produto_variacoes`, `pub_produtos`, `pub_rascunhos`, `pub_variantes`, `pub_imagens` (leitura via `.vps_cmd.sh`; deploy só com autorização explícita). Deploy exige `migrate --force` + `sudo -u www-data php artisan queue:restart` (o Job novo roda na fila `high`; learnings §9).

## Pitfalls

1. **Gravar lista inteira apaga o que a pessoa fez no meio.** `gravarAtributos`/`gravarAlvos` regravam tudo (WR-B02). Usar só `mesclarAtributos`, `salvarVariantes` por chave, `colocarFotoNoGrupo`.
2. **Ler snapshot antes da trava.** Travar primeiro, snapshot depois (learnings §9). Ler schema (pode ir ao ML) antes, fora da trava.
3. **`salvarEixos` copia os dados do `__single__` para TODAS as variantes novas** (`RegeneradorVariantes::ancestral` `RegeneradorVariantes.php`; learnings §10 "copiaria SKU/GTIN/preço para as duas"). Resultado: o SKU da âncora aparece em todas as variantes e "só preenche vazio" pularia o SKU certo. Mitigação: (a) criar o rascunho do grupo sem SKU na `__single__`; (b) tratar SKU igual ao de outra variante como vazio; (c) conferir com teste de 3 cores -> 3 SKUs distintos.
4. **Dois efeitos gravando `atributos` da mesma variante se apagam** (learnings §11): no PHP, montar o array completo de dados da variante e chamar `salvarVariantes` UMA vez por rascunho.
5. **Idempotência do eixo por chave `id:`/`txt:`:** a equipe pode trocar o texto livre por valor da lista. Comparar por `ChaveCanonica::texto(nome)`.
6. **`MAIN_COLOR` não aceita nome livre** (learnings §11): não preencher; a tela infere.
7. **WebP e fotos < 500 px** não passam em `receber()`: contar e informar.
8. **Sincronizar síncrono estoura request** com muitos produtos (schema + imagens). Disparar um Job por produto/grupo (fila `high`) e mostrar o resumo por cache; `Cache::forever('publicador.portal_sincronizado_em.company-N')` já existe (`PublicadorSincronizaPortalService.php:55`). Em teste `QUEUE_CONNECTION=sync` roda inline. `[ASSUMED]` tamanho típico (dezenas de produtos).
9. **`situacaoPortal` com grupo:** sem o ajuste do item 5 de D-06 a tela mostra "novas" para sempre.
10. **Cast `integer` e `0`:** `estoque` 0 != NULL em PHP e JS (`'' -> null`, `0 -> 0`); testar `0` explicitamente (o "sem estoque" não pode virar "não informado").
11. **Teste que passa por ML sem `Http::fake` é intermitente** (learnings §9): faked `CategorySchemaRepository`/`MlCatalogoMetaService` em todos os testes novos.
12. **Suíte inteira estoura 512 MB:** rodar por pasta e redirecionar para arquivo; `| tail` engole o exit code.
13. **`git commit -- <caminho>` não pega arquivo novo:** `git add -- <caminho>` antes (learnings §9). Outra sessão edita a mesma árvore.

## Não reinventar (Don't Hand-Roll)

| Problema | Não construir | Usar | Por quê |
|---|---|---|---|
| "Só preenche o vazio" + trava | trava própria | molde `IaParaRascunhoService::sobTrava`/`intocavel`/`preenchido` | Corridas já resolvidas e testadas (`IaParaRascunhoTest`, `wr_b02`) |
| Categoria + hash do schema | UPDATE direto | `EditorRascunhoService::trocarCategoria` | Migra atributos, grava `schema_hash` |
| Classificar atributo (PRODUCT/eixo/embalagem) | regra de tag própria | `ClassificadorAtributos` | Mesmo algoritmo da tela |
| Valor de lista/unidade/número | parser próprio | regras de `ValorAtributo` (`pelaLista`, `unidade`) | Evita 3510/3708/344 |
| Pacote | soma própria | `LogisticaProduto::pacote` | Mesma conta da 167 (única) |
| Foto: dedupe, L1, disco | cópia de arquivo própria | `ImagemAssetService::receber` | sha256, limites, caminho |
| Grupo de foto | string montada à mão | `ResolvedorGruposImagem::chaveDoGrupo` | Chave idêntica à do resolvedor |
| Identidade de valor | `strtolower` | `ChaveCanonica::texto/valor/hash` | Acento, espaço, hash do índice |
| Fila de IA + cache por pedido | polling novo | molde `GerarPalavrasChaveIaJob` + `PalavrasChaveService` | Pedido velho não pisa no novo |
| Norm. de SKU | `LOWER` | `EstruturaOferta::normalizarSku` | Mesma regra de casamento do portal |

## Arquitetura (diagrama de fluxo)

```
Equipe clica "Sincronizar do Portal"  (POST .../empresas/{chave}/sincronizar, admin)
   |
   v
PublicadorSincronizaPortalService  --> ofertas da Company
   | 1) agrupa: Simples com variacao_id -> por estrutura_produto_id (grupo)
   |            Combo/Kit/Combit e antigas -> 1 por oferta
   | 2) cria/adota pub_produtos (unique estrutura_produto_id / oferta_id)
   v
[por produto] PreencherRascunhoDoPortalJob (fila high)
   v
PortalParaRascunhoService
   |-- criarVazio (se sem rascunho)               --> RascunhoRepository
   |-- schema (fora da trava) <-- CategorySchemaRepository <-- ML (app token, leitura)
   |-- sobTrava{ categoria -> trocarCategoria
   |             atributos portal -> mesclarAtributos (vazios)
   |             SELLER_PACKAGE_* -> mesclarAtributos (vazios)
   |             eixos (acrescenta) -> salvarEixos
   |             estoque/SKU por variante -> salvarVariantes (1 vez) }
   |-- fotos: Storage local (portal) -> ImagemAssetService::receber(enviar:false)
   |            -> colocarFotoNoGrupo (grupo vazio)
   v
Resumo (cache): N produtos, M variantes, K fotos; X mantidos; avisos (WebP, multivalor, pulados)

Editor: estado() --> portal.descricao_cliente (ao vivo) --> botão "Gerar descrição"
   --> GerarDescricaoIaJob (high) --> analise()+descricao() --> cache --> TELA aplica via salvar()
```

### Estrutura de arquivos proposta
```
app/Services/Publicador/PortalParaRascunhoService.php      # novo (molde IaParaRascunhoService)
app/Services/Publicador/PortalProdutoLeitor.php            # novo: lê produto/variações/fotos/composição do portal (escopo company)
app/Services/Publicador/DescricaoIaService.php             # novo (D-09)
app/Jobs/Publicador/PreencherRascunhoDoPortalJob.php       # novo
app/Jobs/Publicador/GerarDescricaoIaJob.php                # novo
app/Services/Portal/Estrutura/Produtos/DescricaoDoProduto.php  # novo (portal)
```
Tocar com cuidado: `PublicadorSincronizaPortalService`, `PubProduto`, `ProgramasPublicadorService::situacaoPortal`, `DadosEfetivosService`, `RascunhoSnapshot::comEfetivos`, `ImagemAssetService::receber`, `EditorRascunhoService::estado/abrir`, `MlbPublicadorEntradaController::sincronizar`, `MlbAnuncioController::iaAnaliseStorePublicador`.

## Piloto / trava (pergunta 13)

`publicador.empresas_piloto` virou `publicador.contas_liberadas` (`config/publicador.php:57-60`; `PUBLICADOR_CONTAS_LIBERADAS_COMPANIES`, com fallback para `PUBLICADOR_EMPRESAS_PILOTO`, default `459`; `ContasLiberadas::libera()` olha a ÂNCORA com token). **Hoje o Sincronizar não consulta essa trava:** `MlbPublicadorEntradaController::sincronizar` (`:131-151`) exige só admin e Company com portal. A trava vale para foto no ML (`ImagemAssetService::enviarAoMl` `:80-85`), conferência L3 e publicação (D21/D26).

Decisão proposta: o preenchimento é **local** (nenhuma chamada de escrita ao ML; só leitura de schema com app token), então segue o mesmo regime do editor. Para respeitar "o sync tem de ficar atrás do piloto", gatear o ENRIQUECIMENTO (não a criação de `pub_produtos`) por `ContasLiberadas::libera(PubProduto::ancoraComToken($empresa, $company))`; contas não liberadas continuam só criando produtos como hoje. -> **Pergunta aberta 4** (confirmar com o usuário; é a leitura mais conservadora). Fotos nunca sobem ao ML no Sincronizar (D26 continua mandando).

## Modelo de ameaças (esboço)

| Ameaça | STRIDE | Mitigação / teste |
|---|---|---|
| Sincronizar de uma empresa lê produto/foto de outra (IDOR por `estrutura_produto_id`/`variacao_id`) | Info disclosure | Toda consulta do portal escopada por `company_id` do `pub_produto`/`Company` resolvido no servidor; nunca por id do corpo. Teste: empresa A sincroniza com produto de B existente -> 0 linhas/fotos de B |
| Copiar arquivo de outra empresa por `caminho` forjado | Tampering | `caminho` vem do banco (linha com `company_id`=A), nunca da requisição; checar `str_starts_with($caminho, "estrutura/{$company}/")` antes de ler |
| Vazar origem do canal ao cliente (sigilo) | Info disclosure | Campos e erros novos neutros; `assertSemOrigem` cobre ficha/descrição/estoque; nada de `categoria_ml_*` novo no JSON do cliente além do que a 167 já devolve |
| Sobrescrever edição da equipe | Tampering | Só-vazio + trava + `intocavel`; teste: editar título/estoque/foto/atributo e reexecutar -> inalterado |
| Reexecução duplica variante/foto/atributo | Repudiation/Integrity | Unique `estrutura_produto_id`, `pubim_sha_uq`, `pubat_attr_uq`, `pubva_combo_uq`; teste: 2 execuções -> mesmas contagens, `revisao` não sobe na 2ª |
| Corrida de dois cliques no Sincronizar | Integrity | Unique + `catch 23000` (padrão `:47-52`); trava de linha; `Cache::lock` por empresa `[ASSUMED]` recomendável |
| Texto do cliente injeta instrução no prompt | Tampering | Vai como "dado" em `specs` com cabeçalho fixo; saída só aplicada pela TELA (nunca grava sozinha); `limparDescricao`; o ML recusa contato/link (V-DESC) na conferência |
| Foto maliciosa (não imagem) | Tampering | `receber()` valida por conteúdo (`finfo`, `getimagesizefromstring`) |
| Publicar sem querer | Elevation | Fase não chama `POST /items`; fotos `pending`; trava D21/D26 intacta |

ASVS: V4 (controle de acesso por empresa) e V5 (validação: `estoque`, `descricao`) aplicáveis; V2/V3/V6 não mudam.

## Ambiente

| Dependência | Uso | Disponível | Nota |
|---|---|---|---|
| PHP | testes/artisan | sim | `C:\xampp\php\php.exe` 8.2.12; `vendor/` com `--ignore-platform-reqs` (lock pede 8.4; não alterar lock) |
| MariaDB local | prova de migration | sim | 10.4, compartilhado (`ecf_admin`): só `--path` |
| SQLite | testes | sim | em memória (`phpunit.xml`) |
| Node | `npm run test:js` / `npm run build` | sim | 2 falhas antigas conhecidas |
| ML (app token) | leitura de schema | só com rede | testes usam fakes |
| GD / conversão WebP | opcional | `[ASSUMED]` | só se converter WebP |

## Arquitetura de validação (Nyquist)

**Baseline medido hoje (2026-10-08, HEAD `d52987de` + árvore suja de outras sessões), phpunit com SQLite em memória, redirecionado para arquivo, todos `exit 0`:**

| Grupo | Comando | Testes | Asserções |
|---|---|---|---|
| Publicador (feature) | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Publicador` | 565 | 3245 |
| Publicador (unit) | `... phpunit tests/Unit/Publicador` | 254 | 861 |
| Portal unit | `... phpunit tests/Unit/PortalEstrutura` | 205 | 1387 |
| Portal produtos | `... phpunit tests/Feature/PortalCliente/Estrutura/Produtos` | 226 | 1468 |
| PortalCliente inteiro | `... phpunit tests/Feature/PortalCliente` | **não medido hoje** (168 final: 510/3668; 94 s) - medir no Wave 0 | |
| JS | `npm run test:js` | exit 1 pelas 2 falhas antigas ("Características secundárias nasce recolhido", "FASES_TERMINAIS cobre as três fases...") | contagem: registrar no Wave 0 |

Regra: contagem >= baseline e nenhuma falha nova; as 2 falhas JS são o piso. Wave 0 grava `172-BASELINE-TESTES.md` no formato do `168-BASELINE-TESTES.md`.

### Requisito -> teste

| Req | Comportamento | Tipo | Comando | Arquivo |
|---|---|---|---|---|
| FP172-01 | `estoque` valida (>=0, int), 0 != null, não herda da 1ª variação, volta em `linhas` | feature+unit | `phpunit tests/Unit/PortalEstrutura/NormalizadorDeLinhaTest.php` e `tests/Feature/PortalCliente/Estrutura/Produtos/GravarLinhasTest.php` | existem (estender); Wave 0 |
| FP172-01 | JS: estoque em `linhaParaServidor`/`novaVariacao` zera | node:test | `node --test tests/js/estrutura-grid-produtos.test.js` (ou novo `estrutura-estoque-descricao.test.js`) | novo |
| FP172-02 | PUT descrição: 404 outra empresa antes de validar, max 5000, vazio limpa, auditoria | feature | `phpunit tests/Feature/PortalCliente/Estrutura/Produtos/DescricaoDoProdutoTest.php` | novo, Wave 0 |
| FP172-03 | Sigilo nos JSON/erros novos | feature | `phpunit tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php --filter SemOrigem` | existe (estender) |
| FP172-04 | Migrations aditivas, driver mariadb, up/down | feature | `phpunit tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` + prova manual `--path` registrada | existe (estender) |
| FP172-05 | N ofertas Simples -> 1 produto-grupo, 1 rascunho, N variantes; adoção de legado; legado publicado não adotado; `situacaoPortal` | feature | `phpunit tests/Feature/Publicador/SincronizaPortalTest.php` | existe + novo `SincronizaPortalCompletoTest.php` |
| FP172-06 | Só-vazio por campo; idempotência (2ª execução não muda nada nem sobe `revisao`); SKUs distintos; acrescenta cor nova | feature | `phpunit tests/Feature/Publicador/PortalParaRascunhoTest.php` | novo, Wave 0 |
| FP172-06 | Multivalor, lista, número+unidade, boolean | unit | `phpunit tests/Unit/Publicador/PortalValorDeAtributoTest.php` | novo |
| FP172-06 | Fotos: dedupe sha, WebP/pequena contadas, grupo vazio, `enviar:false` não chama ML | feature | `phpunit tests/Feature/Publicador/PortalFotosTest.php` | novo |
| FP172-07 | Combo piso(est/N); Kit mín piso; principal por maior custo; estoque NULL propaga | unit+feature | `phpunit tests/Unit/Publicador/ComposicaoDoPortalTest.php` | novo |
| FP172-08 | `estado().portal.descricao_cliente` ao vivo; Job grava só no cache; pedido velho não pisa | feature | `phpunit tests/Feature/Publicador/DescricaoIaTest.php` | novo (Http::fake ÚNICO, §5) |
| FP172-09 | IDOR entre empresas; gate de piloto; sync nunca chama `/items` nem sobe foto | feature | `phpunit tests/Feature/Publicador/PortalParaRascunhoTest.php --filter isolamento` | novo |
| Build | front compila | build | `npm run build` | — |

Amostragem: por commit `phpunit <arquivo tocado>`; por onda o grupo da pasta; portão da fase = os 5 grupos acima + `npm run test:js` (piso de 2 falhas) + `npm run build`. Mutação obrigatória em 2 testes-chave (§5 do learnings): tirar o "só-vazio" e a trava de `company_id` e ver o teste quebrar.

## Suposições (Assumptions Log)

| # | Afirmação | Seção | Risco se errada |
|---|---|---|---|
| A1 | #459 tem `pub_produtos` 1-4 sem publicação e nenhum com `estrutura_produto_id` | D-06 | Adoção do legado pode mexer em rascunho publicado -> checar em prod antes |
| A2 | ML aceita `values:[{id}...]` para atributo `multivalued` | Multivalor | Se não, multivalor fica só como 1ª opção + revisar |
| A3 | `FLAVOR` é o atributo de sabor | Eixos | Cai no eixo customizado, sem perda |
| A4 | Pacote de Combo = volumes x N empilhados | Combo | Medida de frete errada; confirmar com o usuário |
| A5 | "Principal" do Kit = maior custo total | Kit | Categoria/ficha do componente errado; revisar com a equipe |
| A6 | Sincronizar com muitos produtos precisa de Job | Pitfall 8 | Se poucos, síncrono basta (simplifica) |
| A7 | GD disponível em prod para converter WebP | Imagens | Só afeta a opção de converter |
| A8 | Título planejado da âncora serve ao grupo | D-06 | Título diverge se a aba Anúncios tem títulos por cor |
| A9 | Teto de 5000 caracteres da descrição do cliente | Portal | Ajustar |

## Perguntas abertas

1. **Multivalor no Publicador:** aceitar a degradação (1ª opção + `values_multi` + revisar) ou abrir trabalho no payload para enviar `values`? Recomendo degradar nesta fase.
2. **"Principal" do Kit/Combit:** maior custo (proposto) ou o lado que não repete no par de tipos da 168 (`estrutura_tipo_pares`)? Pedir confirmação.
3. **Planilha-modelo:** coluna opcional de Estoque? Recomendo NÃO (compatibilidade com a 3Planejamento).
4. **Piloto:** gatear o enriquecimento por `contas_liberadas` (proposto) ou liberar para qualquer admin como o editor?
5. **WebP:** só reportar (proposto) ou converter para JPG?
6. **Disparo da descrição:** só por botão (proposto) ou automático ao abrir rascunho vazio?

## Divisão sugerida em planos (ondas)

- **Wave 0 (sequencial, pequena):** baseline (`172-BASELINE-TESTES.md`), contagens de prod (leitura), `git status` das sessões paralelas, esqueleto dos testes novos (vermelhos).
- **Wave 1 (paralelizável, portal - sem Publicador):**
  - 172-01 Migrations 1 e 2 + prova `--path` + atualizar `MigracoesDaFaseDetectamMariaDbTest`.
  - 172-02 Estoque: Normalizador, Cadastro, Linhas, model, JS (CartaoVariacao/produtosEstrutura/useFichaProduto) + testes.
  - 172-03 Descrição: endpoint, serviço, bloco da ficha, hook + sigilo (`assertSemOrigem`) + testes.
- **Wave 2 (Publicador - base):**
  - 172-04 Migration 3 (`pub_produtos.estrutura_produto_id`) + prova + `PubProduto` (`skuExibido/nomeExibido`) + `situacaoPortal` + agrupamento/adoção no `PublicadorSincronizaPortalService` + `SincronizaPortalTest`.
  - 172-05 `PortalProdutoLeitor` + extrair `criarVazio` + `ImagemAssetService::receber(enviar:false)` + `comEfetivos($porVariante)`/`DadosEfetivosService`.
- **Wave 3 (preenchimento):**
  - 172-06 `PortalParaRascunhoService`: categoria, atributos (resolução de valores), pacote, eixos/variantes/estoque/SKU, só-vazio, idempotência.
  - 172-07 Fotos por grupo + resumo/feedback + Job por produto + gate do piloto + endpoint de resumo.
  - 172-08 Combo/Kit/Combit (composição, estoque, fotos, descrições).
- **Wave 4 (descrição e tela):**
  - 172-09 `DescricaoIaService` + `GerarDescricaoIaJob` + `specs` em `iaAnaliseStorePublicador` + `estado().portal`.
  - 172-10 UI do editor (painel "Descrição do cliente", botão, hook novo) e resumo do Sincronizar em `Produtos.jsx`; `npm run build`.
- **Wave 5:** verificação (baselines, mutações, prova de migration repetida, conferência visual em SQLite com guarda), learnings (`publicador-ml.md` nova seção), sem deploy sem autorização. Deploy: `migrate --force` + `queue:restart`; contagens antes/depois.

## Fontes

### Primárias (código lido neste worktree, ALTA)
- `PublicadorSincronizaPortalService.php`, `PubProduto.php`, `RascunhoRepository.php`, `EditorRascunhoService.php`, `IaParaRascunhoService.php`, `DadosEfetivosService.php`, `ImagemAssetService.php`, `MigracaoAnunciarAntigo.php`, `ProgramasPublicadorService.php`, `MlbPublicadorEntradaController.php`, `MlbAnuncioController.php:3395-3525`
- `app/Support/Publicador/{Variacao,Imagem,Schema,Validacao}/*`, `RascunhoSnapshot.php`
- `PortalEstruturaProdutosController.php`, `ProdutoCadastroService.php`, `NormalizadorDeLinha.php`, `ProdutoLinhas.php`, `FichaTecnicaDoProduto.php`, `FichaTecnicaDaCategoria.php`, `VariacaoImagensService.php`, `LogisticaProduto.php`, `ModeloProdutosXlsx.php`, models `Estrutura*`
- Migrations `2026_10_01_200000`, `2026_10_02_100000`, `2026_10_02_100100`, `2026_10_06_100000`, `2026_10_06_100100`, `2026_10_07_100200`, `2026_10_07_100300`
- `resources/js`: `EtapaDetalhes.jsx`, `CartaoVariacao.jsx`, `useFichaProduto.js`, `lib/produtosEstrutura.js`, `useIaDoPublicador.js`, `ferramentas.js`
- Fixtures `tests/fixtures-ml/sondagem/publico/categorias/*/atributos.json` (tags de COLOR/SIZE/VOLTAGE/MATERIAL/MAIN_COLOR)
- `.planning/learnings/publicador-ml.md` §9-§11, `168-BASELINE-TESTES.md`, `168-16-PLAN.md` (padrão `guarda-sqlite.php`)
- Execução local de testes em 2026-10-08 (tabela acima)

### Não verificado nesta sessão (BAIXA)
- Comportamento do ML para atributo multivalued, existência de `FLAVOR`, GD/WebP em prod, dados reais de prod (#459).

## Metadados
**Confiança:** stack/padrões ALTA (código lido); desenho D-06 MÉDIA-ALTA (depende da checagem em prod); combos/kits MÉDIA.
**Pacotes externos novos:** nenhum (a fase não instala pacotes) - auditoria de legitimidade não se aplica.
**Válido até:** 2026-10-15 (a árvore tem 3 sessões editando em paralelo; reler `git status` antes de planejar).
