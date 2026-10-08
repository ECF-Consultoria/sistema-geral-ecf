# Phase 172: Ficha do portal completa até o Publicador - Context

**Gathered:** 2026-10-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Pedido do usuário (08/10, palavras dele): "tudo que for preenchido [na ficha do portal] tem que ser conectado
com o Publicador, ou seja, a partir do momento que eu sincronizar o portal com o Publicador, todas as
informações do portal vão para o Publicador. Temos a base toda de dados lá. É só transferir."

Hoje a ficha do produto do portal (`/portal/estrutura/produtos/{produto}`, Fase 167 + ficha rica de 07/10)
guarda categoria, família, ambientes, variações (eixo/valor, SKU = `codigo`, custo), volumes (medidas/peso),
ficha técnica (`estrutura_produto_atributos`) e imagens por variação (`estrutura_produto_variacao_imagens`).
O "Sincronizar do Portal" do Publicador (`PublicadorSincronizaPortalService`) só cria um `pub_produtos` por
`estrutura_ofertas` com SKU e nome — **nada** do produto chega ao rascunho.

Esta fase entrega:
1. **Estoque por variação** na ficha do portal (a ficha não tem estoque em lugar nenhum hoje).
2. **Descrição do produto** na ficha do portal (texto livre do cliente; também não existe hoje).
3. **Sincronizar completo**: categoria, ficha técnica, variações (eixo/valor, SKU, estoque), imagens por
   variação, medidas/peso (pacote) e descrição passam do portal para o rascunho do Publicador.
4. **Descrição do cliente como matéria-prima do MAG T8** no Publicador: o texto do cliente alimenta o prompt de
   descrição MAG T8 que já existe (`AnaliseAnuncioService::promptDescricao`), não vai cru para o anúncio.

**Fica FORA:**
- Campo Modelo sem repetir palavras do título (trabalho direto paralelo, sem schema).
- Renomear "Sugestões de ofertas" → "Planejamento" e melhorar as regras do gerador (trabalho direto paralelo).
- IA no Planejamento (2ª etapa, decisão do usuário 08/10).
- Publicar automaticamente. Publicação real segue manual, e E2E só na #459.
</domain>

<decisions>
## Implementation Decisions

### Ficha do portal
- **D-01 — Estoque é POR VARIAÇÃO.** Inteiro ≥ 0, anulável (nulo = "não informado", diferente de 0 = "sem
  estoque"). Mora em `estrutura_produto_variacoes` (coluna nova, anulável, aditiva). Aparece na linha de cada
  variação da ficha, ao lado de SKU/custo.
- **D-02 — Descrição é POR PRODUTO.** Texto livre anulável em `estrutura_produtos` (coluna nova, aditiva).
  Bloco próprio na ficha, rótulo neutro "Descrição do produto", com orientação ao cliente do que escrever
  (uso, diferenciais, cuidados, o que vem na caixa) — sem citar canal de venda.
- **D-03 — Regra de SIGILO vale para os dois campos.** Nada de "mercado", "anúncio", "publicar", "MLB" no
  rótulo, ajuda, placeholder, erro ou JSON. O teste `assertSemOrigem`
  (`tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php`) deve cobrir os campos novos.
- **D-04 — Migrations só aditivas, colunas anuláveis, sem default que reescreva linha.** Provar no MariaDB local
  com `--path` (learnings §6). Contar linhas de `estrutura_produtos`/`estrutura_produto_variacoes` em prod
  antes e depois do deploy.

### Sincronizar portal → Publicador
- **D-05 — "Só preenche o vazio" (decisão do usuário).** O Sincronizar preenche no rascunho o que está em
  branco; o que a equipe já editou no Publicador (título, ficha, fotos, preço, estoque, descrição) NUNCA é
  sobrescrito. Produto que ainda não tem rascunho chega completo. Reexecutar o Sincronizar é idempotente.
- **D-06 — Cores viram VARIAÇÕES de UM rascunho (decisão do usuário).** As N ofertas Simples de um mesmo
  produto do portal (uma por variação, via `estrutura_ofertas.variacao_id`) entram como N variações de um
  único rascunho, cada uma com seu SKU (`SELLER_SKU` = `codigo` da variação), estoque e fotos. Hoje existe um
  `pub_produtos` por oferta (`oferta_id` unique) — a pesquisa deve propor como agrupar sem quebrar os
  `pub_produtos`/rascunhos que já existem em prod (#459 tem rascunhos ancorados em produtos 1–4) e sem
  apagar histórico. **Mudança em `pub_produtos` (dado em prod) é exatamente o motivo desta fase ser GSD.**
- **D-07 — Combo, Kit e Combit: o que dá para derivar (decisão do usuário).**
  - Combo (mesmo produto ×N): herda categoria, ficha, fotos, medidas e descrição do produto; estoque =
    ⌊estoque da variação ÷ N⌋.
  - Kit / Combit: categoria e ficha técnica do produto PRINCIPAL (a pesquisa define "principal" — sugestão: o
    de maior custo, ou o lado que não repete no par de tipo); fotos de todos os componentes; estoque =
    menor ⌊estoque do componente ÷ quantidade⌋; descrições de todos juntas como matéria-prima do MAG T8.
  - Composição lida de `EstruturaOfertaComponente`.
- **D-08 — Mapeamentos do portal para o rascunho:**
  - `categoria_ml_id` → `pub_rascunhos.categoria_id` (e o schema da categoria carregado como hoje).
  - `estrutura_produto_atributos` → `pub_rascunho_atributos` com `origem = 'portal'`. Lista vem com
    `valor_id`; **multivalor está gravado como NOMES unidos por `' | '` com `valor_id` nulo** — o
    Sincronizar tem de resolver os ids pelo nome contra o schema da categoria (docblock de
    `FichaTecnicaDoProduto` já avisa). Número+unidade → `value_number`/`value_unit`.
  - Variações: eixo/valor → `pub_eixos`/`pub_eixo_valores`/`pub_variantes`; `codigo` → `SELLER_SKU` da
    variante; estoque → `pub_variantes.estoque`.
  - Volumes → `SELLER_PACKAGE_*` (somar volumes quando houver mais de um, como a 167 faz para o frete).
  - Imagens por variação → `pub_imagens` + `pub_imagem_atribuicoes` (grupo da variação), copiando o arquivo
    do disco privado do portal; deduplicar por `sha256` para o reSincronizar não duplicar.
  - Descrição do cliente → guardada como matéria-prima (não vai direto para `pub_rascunhos.descricao`); ver D-09.
- **D-09 — Descrição no Publicador:** a descrição do cliente aparece no editor como referência e alimenta o
  prompt MAG T8 de descrição existente (junto da ficha técnica como `specs`). Se `pub_rascunhos.descricao`
  estiver vazio, o editor oferece/gera a descrição tratada (padrão do Modelo: job na fila `high`, resultado em
  cache, a TELA aplica pelo caminho normal de edição — learnings `publicador-ml.md` §10). Prompts MAG T8
  intocados no texto; só muda a entrada.

### Claude's Discretion
- Onde guardar a descrição crua do cliente do lado do Publicador (ler ao vivo do produto, como
  `DadosEfetivosService` já faz com título/preço, é o caminho preferido — evita cópia defasada).
- Se o MAG T8 da descrição dispara sozinho ao abrir o rascunho vazio ou só por botão.
- Feedback do Sincronizar (resumo "N produtos, M variações, K fotos trazidas; X campos mantidos porque já
  estavam preenchidos").
</decisions>

<canonical_refs>
## Canonical References

- `.planning/learnings/publicador-ml.md` (§9 travas do rascunho, §10 IA via cache, §11)
- `.planning/learnings/` §6 (MariaDB × SQLite), §10 (`git commit -- <caminhos>`), §34 (ficha rica)
- `.planning/phases/167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas/167-CONTEXT.md`
- `.planning/phases/168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos/168-CONTEXT.md`
- `.planning/phases/164-*` (Publicador no sistema interno — `pub_produtos`, rascunho ancorado)
</canonical_refs>

<code_context>
## Existing Code Insights

- Portal: `app/Http/Controllers/PortalEstruturaProdutosController.php` (`ficha` :104, `renderFicha` :530,
  `gravarFichaTecnica` :369), `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx`,
  `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js` / `useFichaTecnica.js`,
  `app/Services/Portal/Estrutura/Produtos/FichaTecnicaDoProduto.php`, `ProdutoCadastroService.php`.
- Schema portal: `2026_10_06_100000_create_estrutura_produtos_tables.php`,
  `2026_10_07_100200_create_estrutura_produto_atributos_table.php`,
  `2026_10_07_100300_create_estrutura_produto_variacao_imagens_table.php`.
- Publicador: `app/Services/Publicador/PublicadorSincronizaPortalService.php` (:23-60),
  `MlbPublicadorEntradaController.php` (:131-151), `EditorRascunhoService.php` (`abrir` :64-91,
  `comEfetivos` :411), `DadosEfetivosService.php` (:44-65), `RascunhoRepository.php`,
  `MigracaoAnunciarAntigo.php` (:87-151 — já mapeia atributos/pacote/estoque/fotos do formato antigo; bom
  modelo), `PublicacaoService.php` (estoque `available_quantity` :335, descrição :531).
- Schema Publicador: `2026_10_01_200000_create_publicador_tables.php` (`pub_variantes.estoque` :131,
  `pub_rascunhos.descricao` :48), `2026_10_02_100000_create_pub_produtos_table.php`,
  `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php`.
- IA: `app/Services/Ia/AnaliseAnuncioService.php` (`descricao` :83, `promptDescricao` :418),
  `app/Jobs/Publicador/GerarPalavrasChaveIaJob.php`, `app/Services/Publicador/PalavrasChaveService.php`.
</code_context>

<specifics>
## Specific Ideas

- Loja de teste: #459 "Dev 02 Testes API" — produtos 3–12 com ficha técnica preenchida (dois conjuntos), 0
  imagens. Conta de cliente: nunca publicar; E2E só na #459 com confirmação a cada `POST /items`.
- Piloto do Publicador: `publicador.empresas_piloto` (padrão `[459]`).
</specifics>

<deferred>
## Deferred Ideas

- IA no Planejamento (sugerir composições).
- Sincronizar no sentido inverso (Publicador → portal).
- Avisar a equipe quando o cliente alterar a ficha depois do Sincronizar.
</deferred>
