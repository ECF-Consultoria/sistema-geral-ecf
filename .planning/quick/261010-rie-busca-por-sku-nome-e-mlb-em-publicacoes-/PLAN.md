---
phase: quick-261010-rie
quick_id: 261010-rie
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php
  - app/Models/MlAcervoItem.php
  - app/Services/Mlb/Acervo/SkusDoAnuncio.php
  - app/Services/Mlb/Acervo/MlAcervoService.php
  - app/Services/Publicador/AcervoTriagemService.php
  - app/Http/Controllers/MlbAnuncioController.php
  - resources/js/Pages/Mlb/MeusAnuncios.jsx
  - tests/Unit/Phase134/SkuDoAcervoTest.php
  - tests/Unit/Publicador/AcervoTriagemServiceTest.php
  - tests/Feature/Phase134/MeusAnunciosTest.php
  - tests/js/publicador-meus-anuncios-sku-render.test.js
autonomous: true
requirements: [RIE-01, RIE-02, RIE-03, RIE-04]
user_setup: []

must_haves:
  truths:
    - "A busca de Publicacoes acha o anuncio por SKU, por pedaco do nome e pelo codigo MLB — no MESMO campo."
    - "Nome e MLB continuam achando exatamente como hoje (nenhuma regressao)."
    - "A busca por SKU continua respeitando o escopo por empresa e o filtro de status."
    - "O filtro de status ganha a opcao propria `Em revisao`, que isola so `under_review`."
    - "`Acionaveis` continua trazendo ativos + pausados + em revisao — nada saiu."
    - "Buscar por SKU num acervo ainda sem SKU coletado NAO responde um 'nao achei' mudo: a tela diz que o SKU ainda nao foi coletado."
    - "Anuncio com variacoes guarda TODOS os SKUs distintos — nenhum SKU e inventado nem colapsado em um so."
    - "Nenhum caminho de escrita novo em `ml_acervo_itens`: o SKU entra pelo mesmo `processarLote()`/`AcervoEscritaLock` de hoje."
  artifacts:
    - path: "database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php"
      provides: "Coluna `skus` (longText NULL, ultima da tabela) com a tabela de decisao de schema no docblock"
      contains: "hasColumn"
    - path: "app/Services/Mlb/Acervo/SkusDoAnuncio.php"
      provides: "Extrator da LISTA de SKUs do item do multiget (pai + variacoes), mesma receita do Portal"
      contains: "SELLER_SKU"
    - path: "app/Services/Mlb/Acervo/MlAcervoService.php"
      provides: "`seller_custom_field` no multiget e `skus` gravado/atualizado pela camada barata"
      contains: "seller_custom_field"
    - path: "app/Services/Publicador/AcervoTriagemService.php"
      provides: "Busca cobrindo title + ml_item_id + skus, e o filtro `em_revisao`"
      contains: "em_revisao"
    - path: "resources/js/Pages/Mlb/MeusAnuncios.jsx"
      provides: "Opcao 'Em revisao', placeholder dos tres campos, SKU na linha e o vazio honesto da busca"
      contains: "Em revisao"
  key_links:
    - from: "app/Services/Mlb/Acervo/MlAcervoService.php"
      to: "app/Services/Mlb/Acervo/SkusDoAnuncio.php"
      via: "extrair() dentro de processarLote(), antes do upsert"
      pattern: "SkusDoAnuncio::extrair"
    - from: "app/Services/Mlb/Acervo/MlAcervoService.php"
      to: "ml_acervo_itens.skus"
      via: "`skus` na lista COLUNAS_CAMADA_BARATA (3o argumento do upsert)"
      pattern: "'skus'"
    - from: "app/Services/Publicador/AcervoTriagemService.php"
      to: "ml_acervo_itens.skus"
      via: "orWhere('skus','like',...) DENTRO do where(function...) da busca"
      pattern: "orWhere\\('skus'"
    - from: "app/Http/Controllers/MlbAnuncioController.php"
      to: "resources/js/Pages/Mlb/MeusAnuncios.jsx"
      via: "props `skus` por linha e `skuNaoColetado` para o vazio da busca"
      pattern: "skuNaoColetado"
---

<objective>
Duas falas literais do usuário, de 10/10:

- *"Quero poder buscar por SKU, Nome do Anuncio ou MLB"*
- *"Em revisão quero poder isolar"*

Hoje a busca de `/mlb/anuncios/meus/{company}` cobre **`title`** e **`ml_item_id`** e nada mais —
`ml_acervo_itens` **não tem coluna de SKU** e o multiget da camada barata **não pede**
`seller_custom_field`. Buscar por SKU não é um filtro faltando: é um dado que o acervo nunca
coletou. E o filtro de status tem 5 opções fechadas, nenhuma isolando `under_review` (que desde a
quick `261010-nke` só aparece diluído dentro de `Acionáveis`).

Purpose: um único campo de busca que encontra o anúncio pelos três identificadores que o usuário
tem na mão (SKU da planilha, nome do anúncio, código MLB), e um filtro que isola o que está em
revisão no Mercado Livre — sem mentir enquanto o SKU ainda não foi coletado.
Output: coluna `skus` alimentada pela coleta barata que já existe, busca cobrindo os três campos no
mesmo `where(function...)`, opção `Em revisão` acrescentada à lista fechada, e um estado vazio de
busca que explica em vez de dizer "não achei".
</objective>

<requisitos>
- **RIE-01** — A busca cobre **SKU**, **nome** (`title`) e **MLB** (`ml_item_id`) no mesmo campo,
  sem quebrar nome e MLB, sem furar o escopo por empresa e respeitando o filtro de status.
- **RIE-02** — O acervo passa a guardar o SKU do anúncio, coletado pela **camada barata que já
  existe** (nenhum caminho de escrita novo), inclusive para linhas já existentes.
- **RIE-03** — O filtro de status ganha a opção **`Em revisão`** isolando `under_review`,
  **acrescentada** à lista fechada; `Acionáveis` continua sendo o padrão e continua cobrindo
  `active` + `paused` + `under_review`.
- **RIE-04** — Busca por SKU num acervo ainda sem SKU coletado **não** responde um "não achei"
  mudo: a tela diz que o SKU ainda não foi coletado e oferece "Atualizar agora".
</requisitos>

<decisoes_travadas>
Decisões do usuário e do orquestrador, mais as de desenho tomadas aqui com a justificativa.
**Não revisitar.**

- **D-RIE-01 — A coluna guarda TODOS os SKUs distintos do anúncio, não "o" SKU.**
  `ml_acervo_itens` tem **uma linha por ANÚNCIO** (D-17) e, em anúncio com variações, **o SKU é
  por variação** (`AnunciosMercadoLivreService`, L632). Guardar um só exigiria escolher qual — e
  qualquer escolha mentiria sobre as outras, ou devolveria `null` e tornaria o anúncio inachável.
  Como o objetivo é **ACHAR o anúncio por qualquer um dos seus SKUs**, a coluna guarda a lista dos
  SKUs distintos: o do item-pai primeiro (quando existe), depois os das variações na ordem em que o
  ML devolve. A tela mostra o primeiro e, quando há mais de um, **diz quantos são** — nunca
  apresenta um SKU de variação como se fosse "o" SKU do anúncio.
- **D-RIE-02 — Três estados, nenhum inventado.** `skus = NULL` significa **"ainda não coletado"**
  (linha gravada antes desta mudança); `skus = []` significa **"coletado e o anúncio não tem
  SKU"**; lista não vazia é o dado. Mesma disciplina do D-18 do acervo (ausência de coleta é estado
  de primeira classe, não zero inventado) — e é o que torna o RIE-04 exato em vez de chutado.
- **D-RIE-03 — `longText` nullable, APPEND no fim da tabela, sem `after()`, sem índice.** É decisão
  de **deploy**, não de estilo: `ml_acervo_itens` tem hoje **1.080.206 linhas, 1.776 MB de dados e
  196 MB de índices** (InnoDB, medido na VPS em 10/10/2026 — a contagem de 879.479 de 20/08/2026 que
  está no doc do deadlock ficou para trás) e o worker do acervo escreve nela.

  ⚠️ **ATENÇÃO AO MOTOR — produção NÃO é MariaDB.** Medido na VPS em 10/10/2026:
  `SELECT VERSION()` = **`8.0.46-0ubuntu0.24.04.4`**, ou seja **MySQL 8.0.46**. O **MariaDB
  10.4.32 é o LOCAL** (XAMPP). Nenhum arquivo de `.planning/learnings/` registrava isso até hoje —
  sete deles chamam a produção de "MariaDB" —, então qualquer raciocínio de DDL herdado daqueles
  textos tem de ser reconferido contra o MySQL 8 antes de ser usado. No MySQL 8.0.46:
  - `JSON` é **tipo nativo**, sem CHECK `json_valid` (isso é MariaDB), e `ADD COLUMN ... JSON NULL`
    seria INSTANT;
  - **desde o 8.0.29** o instant add funciona em **qualquer posição**, então `after()` também seria
    INSTANT lá (a restrição "só na última coluna" é MariaDB e MySQL ≤ 8.0.28).

  **Por que a escolha continua sendo `longText` no fim, mesmo sem essas duas pressões:** é o que
  funciona igual nos **três** motores em jogo, sem depender de versão — a suíte roda em SQLite, a
  prova de migration roda no MariaDB local e o deploy acontece no MySQL 8. `longText` nullable é o mesmo
  `LONGTEXT NULL` em todos; a serialização é do cast `array` do Eloquent, como a aplicação já faz
  com `variations`/`tags`/`motivos`; e não há posição a negociar nem CHECK a criar. Escolha
  conservadora e portável, não uma otimização para um motor que não é o de produção.

  **Expectativa de deploy (MySQL 8.0.46):** `ALTER TABLE ml_acervo_itens ADD COLUMN skus LONGTEXT
  NULL` acrescenta a coluna **por metadado** (INSTANT) — sem reescrever as 1.080.206 linhas, sem
  copiar os 1,7 GB e sem travar escrita por tempo perceptível. Não há índice nem CHECK novo para
  forçar rebuild.
- **D-RIE-04 — SEM backfill, e isto é decisão, não esquecimento.** Linha existente fica com
  `skus = NULL` até a próxima passada da camada barata. Três razões:
  1. `mlb:sync-acervo` roda **todo dia às 11:35** e varre o acervo **inteiro** da conta — não é
     rotação por fatias, é cobertura completa diária (docblock de `MlAcervoService`).
  2. Com `skus` dentro de `COLUNAS_CAMADA_BARATA`, essa passada **atualiza** a linha existente
     (sem isso o `upsert` não tocaria na coluna — ver `<armadilhas>` 1).
  3. Um backfill teria de chamar o **mesmo multiget** (o SKU só existe na resposta da API): seria a
     própria varredura diária, de novo, ~20 mil chamadas, para antecipar menos de um dia.
  Quem não quer esperar tem o **"Atualizar agora"**, que já existe e cobre a empresa na hora. A
  janela em que o SKU ainda não existe é precisamente o que o RIE-04 cobre.
- **D-RIE-05 — A receita do SKU é reusada, não reinventada — com UMA diferença deliberada.**
  `AnunciosMercadoLivreService::skuDoAnuncio()` (L626/L632) é a fonte: atributo **`SELLER_SKU`**
  primeiro, **`seller_custom_field`** como fallback, mesma regra aplicada por variação.
  A diferença: lá, variações com SKUs **divergentes** devolvem `null` (uma linha da colagem = um
  SKU, então SKU ambíguo é pior que SKU nenhum); aqui, **todas** entram na lista, porque o propósito
  é busca. O arquivo original **não é tocado** — é gate de diff deste plano.
- **D-RIE-06 — Sem índice novo.** A busca já é `LIKE '%termo%'` (curinga à esquerda) em `title`, que
  **nenhum índice atende**; o que segura a query é o recorte por `company_id`, já indexado em
  `mai_company_item_unq`/`mai_company_status_idx`. Acrescentar `skus` ao mesmo `where(function...)`
  não muda o plano de execução — o scan da faixa da empresa é o mesmo, com uma comparação de string
  a mais por linha. Criar índice aqui seria **outra** operação de deploy em tabela grande, sem
  ganho: índice não serve curinga à esquerda. Os números medidos reforçam: a tabela já carrega
  **196 MB de índices** sobre 1.776 MB de dados, e um índice novo em 1.080.206 linhas é construção
  de índice no deploy — custo real, benefício zero para `LIKE '%…%'`.
- **D-RIE-07 — `Em revisão` ACRESCENTA, não substitui.** A lista fechada passa a ter 6 valores:
  `acionaveis` (padrão, intacto), `ativos`, `pausados`, `em_revisao` (novo), `encerrados`, `todos`.
  Desfazer a emenda de 10/08/2026 (`paused` no default) ou a de 10/10/2026 (`under_review` no
  default) deixaria o chip "Pausado" permanentemente em 0 — está justificado no comentário do
  controller e não se toca.
- **D-RIE-08 — Rótulo em pt-BR; `under_review` nunca na tela.** A opção se chama **"Em revisão"**.
  Regra permanente do projeto (feedback de 07/07/2026): jargão que não se explica não aparece na
  interface.
- **D-RIE-09 — Efeito nos chips (D-09) e na ordenação (D-12), confirmado no código.**
  - **Chips:** com `em_revisao` selecionado, o universo da triagem são só os `under_review`. Em
    `AnuncioSaudeService::triagem()`, `MOTIVO_PAUSADO` só sai com `status === 'paused'` e
    `MOTIVO_SEM_ESTOQUE` só com `status === 'active'` (conferido nas linhas ~125-131) — logo esses
    dois chips ficam **necessariamente em 0** nesse filtro, e isso é a **verdade** daquele universo,
    não um bug. Os chips de ficha/foto/catálogo continuam contando. A contagem dos outros filtros
    **não muda**: o arm novo do `match` não altera nenhum existente.
  - **Ordenação:** nenhuma mudança. `meus()` ordena por `severidade` → `nota_ecf IS NULL` →
    `nota_ecf` → `ml_item_id`, e **nenhum** desses critérios olha `status`. Vale igual para a opção
    nova, pelo mesmo motivo que valeu para `acionaveis` na `261010-nke`.
- **D-RIE-10 — O vazio da busca explica; não diz "não existe".** Quando há busca e o resultado é
  zero, a tela troca o texto de hoje ("Esta empresa não tem anúncios ativos no Mercado Livre") por
  um que diz **o que foi procurado e onde** (título, código MLB e SKU) e, se nenhuma linha da
  empresa tem `skus` coletado, acrescenta que **uma busca por SKU não acharia nada mesmo que o
  anúncio exista**, com o "Atualizar agora". Sem busca, o texto de hoje fica **literal**.
</decisoes_travadas>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md

Leitura OBRIGATÓRIA antes de desenhar qualquer coisa que escreva no acervo:
@.planning/debug/resolved/acervo-deadlock-upsert.md

Arquivos a ler antes de editar (nesta ordem):
@app/Services/Mlb/Acervo/MlAcervoService.php
@app/Services/Mlb/Acervo/AcervoEscritaLock.php
@app/Services/Mlb/Acervo/AnuncioSaudeService.php
@app/Models/MlAcervoItem.php
@app/Services/Publicador/AcervoTriagemService.php
@app/Http/Controllers/MlbAnuncioController.php
@resources/js/Pages/Mlb/MeusAnuncios.jsx

A receita do SKU (LER, NÃO EDITAR — é gate de diff):
@app/Services/Portal/Estrutura/AnunciosMercadoLivreService.php

Precedente do `seller_custom_field` no multiget:
@app/Services/Publicador/Alavancas/ProdutosDaContaService.php

Moldes:
@database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php
@database/migrations/2026_10_08_150100_add_descricao_to_estrutura_produtos.php
@tests/Unit/Phase134/ColetaAcervoTest.php
@tests/Unit/Phase134/SchemaAcervoTest.php
@tests/Unit/Publicador/AcervoTriagemServiceTest.php
@tests/Feature/Phase134/MeusAnunciosTest.php
@tests/js/publicador-acervo-render.test.js
@tests/js/estrutura-montar-kit-render.test.js
@tests/js/estrutura-meus-anuncios.test.js
</context>

<interfaces>
Contratos já extraídos do código — o executor não precisa caçar.

**`ml_acervo_itens` hoje** (colunas, na ordem): `id, company_id, ml_item_id, title, category_id,
status, sub_status, listing_type_id, price, available_quantity, sold_quantity, permalink,
thumbnail, fotos_count, has_variations, variations, catalog_listing, catalog_product_id, shipping,
tags, nota_ecf, nota_sinais, motivos, severidade, origem, rascunho_id, publicacao_vendas_qty,
publicacao_desconsiderado, health_ml, visitas_30d, buybox_status, performance_score,
performance_level, performance_acoes, detalhe_coletado_em, coletado_em, coleta_erro, created_at,
updated_at`. Índices: `mai_company_item_unq` (unique `company_id,ml_item_id`),
`mai_company_status_idx`, `mai_company_sev_nota_idx`, `mai_company_detalhe_idx`.
**Motores (medidos em 10/10/2026):** produção = **MySQL 8.0.46** (`8.0.46-0ubuntu0.24.04.4`,
InnoDB, **1.080.206 linhas / 1.776 MB de dados / 196 MB de índices**); local = **MariaDB
10.4.32** (XAMPP), onde a tabela existe e tem **0 linha**; suíte = **SQLite** in-memory.
**Três motores diferentes — nenhum prova o outro.**

**`MlAcervoService`** (`app/Services/Mlb/Acervo/MlAcervoService.php`):
- `private const ATRIBUTOS_MULTIGET = 'id,status,sub_status,available_quantity,sold_quantity,shipping,listing_type_id,tags,catalog_listing,catalog_product_id,variations,title,price,permalink,thumbnail,category_id,attributes,pictures,health'`
- `private const COLUNAS_CAMADA_BARATA = [...]` — 3º argumento do `upsert()`; **só** as colunas
  listadas são atualizadas em conflito.
- `private function processarLote(Company $company, array $ids, array $rascunhoPorMlItemId, array $publicacaoPorMlbCode, array $buyboxPorMlItemId): array`
  — monta `$linhas[]`, ordena por `(company_id, ml_item_id)` e grava dentro de
  `AcervoEscritaLock::naEmpresa($company->id, fn () => MlAcervoItem::upsert($linhas, ['company_id','ml_item_id'], self::COLUNAS_CAMADA_BARATA))`.
  É o **único** ponto de escrita da camada barata, usado pelos dois caminhos
  (`coletarCamadaBarata()` da varredura e `coletarItens()` do recém-publicado).
- Campos de array são gravados **já serializados** no array de `$linhas`
  (`'variations' => json_encode($variations)`, `'tags' => json_encode(...)`): **`upsert()` não
  aplica casts**.

**Receita do SKU** (`AnunciosMercadoLivreService::skuDoAnuncio()`, L626-L636) — copiar o
comportamento, não o arquivo:

    $doAtributo = fn (array $attrs) => collect($attrs)->firstWhere('id', 'SELLER_SKU')['value_name'] ?? null;
    $sku = $doAtributo($item['attributes'] ?? []) ?: ($item['seller_custom_field'] ?? null);
    // variações: a mesma regra por variação, em $v['attributes'] / $v['seller_custom_field']

**`AcervoTriagemService::escopo(Company $company, string $busca, string $statusFiltro): Builder`**:
- `match ($statusFiltro)`: `'acionaveis' => ['active','paused','under_review']`,
  `'ativos' => ['active']`, `'pausados' => ['paused']`, `'encerrados' => ['closed']`,
  `default => null` (`'todos'` não filtra).
- busca: `->when($busca !== '', fn ($q) => $q->where(function ($s) { $s->where('title','like',"%{$busca}%")->orWhere('ml_item_id','like',"%{$busca}%"); }))`
  — **agrupada**, e no MESMO builder que recebe o `whereIn('status', ...)`.
- `triagem()` e `comMotivos()`/`legadoEntre()` reusam `escopo()`; os dois últimos passam
  `'acionaveis'` fixo. `MlbPublicadorEntradaController:362` chama `triagem($company, '', 'acionaveis')`.

**`MlbAnuncioController::meus()`**:
- whitelist: `in_array($statusFiltro, ['acionaveis','ativos','pausados','encerrados','todos'], true)`,
  fora da lista cai em `'acionaveis'` (L474-477).
- `$anuncios = $acervoTriagem->escopo(...)->when(motivo)->when(comVenda)->orderByDesc('severidade')->orderByRaw('nota_ecf IS NULL ASC')->orderBy('nota_ecf')->orderBy('ml_item_id')->paginate(50)->withQueryString()`.
- `$anuncios->through(fn (MlAcervoItem $item) => ['ml_item_id' => ..., 'titulo' => ..., ...])` — é
  aqui que entra a prop nova por linha. `paginate()` já faz o `count`, então `$anuncios->total()`
  é de graça.
- props: `'filtros' => ['busca' =>, 'status' =>, 'motivo' =>, 'com_venda' =>]`.

**`MeusAnuncios.jsx`**:
- `STATUS_OPCOES` (L39-45) + o comentário **desatualizado** da L36 ("ativos + pausados").
- busca: `<form onSubmit={buscar}>` com `placeholder="Buscar por título ou id…"` (L392).
- linha da tabela: célula "Anúncio" com
  `<span className="line-clamp-2 block text-sm text-white" title={item.titulo}>{item.titulo}</span>`
  + `<SeloOrigem origem={item.origem} />` (L457-458).
- vazio: bloco `itens.length === 0` com "Esta empresa não tem anúncios ativos no Mercado Livre."
  (L411-418).
- `BotaoAtualizar({ atualizando, cooldown, onClick })` já existe e é reusável.
- **a tela não renderiza o status por linha** — nenhum `under_review` cru aparece hoje.
</interfaces>

<armadilhas>
Erros que este plano existe para evitar. Ler antes de escrever código.

1. **`skus` TEM de entrar em `COLUNAS_CAMADA_BARATA`.** O 3º argumento do `upsert()` é a lista do
   que é atualizado em conflito. Sem `skus` ali, a coluna só seria preenchida em linha **nova** e as
   1,08 milhão de linhas existentes **nunca** receberiam SKU — a busca por SKU ficaria permanentemente
   quebrada para o acervo inteiro e o "sem backfill" do D-RIE-04 cairia junto.
2. **Não tocar no resto do 3º argumento.** `buybox_status`, `visitas_30d`, `performance_*` e
   `detalhe_coletado_em` estão **fora** de propósito: entram ali e a camada CARA é apagada a cada
   passada diária (T-134-26). Acrescentar `skus` **não** autoriza mexer em mais nada da lista.
3. **Nenhum caminho de escrita novo no acervo.** O SKU entra no array de `$linhas` do
   `processarLote()` que já existe, logo passa de graça pelo `AcervoEscritaLock::naEmpresa()` e pela
   ordenação do lote. **Não** criar `upsert`/`update` novo, **não** mexer em `AcervoEscritaLock`,
   **não** afrouxar `tests/Unit/Phase134/SerializacaoEscritaAcervoTest.php` (se ele falhar, o
   desenho está errado — não o teste).
4. **`MlAcervoDetalheService` (camada cara) não escreve `skus`.** Não adicionar a coluna lá: as duas
   camadas escrevendo na mesma coluna é exatamente a geometria que produziu ~20 deadlocks/dia (E2 do
   debug doc).
5. **A busca tem de continuar DENTRO do `where(function ...)`.** Um `orWhere` solto sobe ao topo do
   WHERE e **anula o escopo por empresa** (T-134-01) — mesma pegadinha já travada em `historico()`.
   O ramo do SKU é um `orWhere` **a mais dentro do mesmo grupo**, nunca fora.
6. **Termo de busca com `"`, `[` ou `]` não entra no ramo do SKU.** `skus` é texto JSON
   (`["ABC-1","XYZ"]`): um termo com a pontuação do próprio JSON casaria com **toda** linha já
   coletada e devolveria o acervo inteiro como se fosse resultado de busca. Nesses casos o ramo do
   SKU é pulado e a busca segue valendo para título e MLB.
7. **Migration:** sem `after()` (D-RIE-03), **sem enum**, **sem `json()`**, idempotente por
   `Schema::hasColumn`, **sem try/catch em volta de DDL**, nullable (há dado em produção) e
   **nenhum índice** — portanto nenhum nome de índice para estourar o limite de 64 chars (erro
   1059 no MariaDB local; o MySQL 8 de produção tem o mesmo limite). `down()` também idempotente.
8. **O MariaDB local NÃO é o motor de produção, e o teste local NÃO prova produção.**
   Produção é **MySQL 8.0.46**; o local é **MariaDB 10.4.32**; a suíte é **SQLite**. Rodar a
   migration no MariaDB local prova que `up()`/`down()` executam e que a coluna nasce certa —
   **não** prova comportamento de DDL no MySQL 8 nem duração de nada. **Para esta migration o
   risco dessa diferença é baixo e por um motivo nomeável:** `ADD COLUMN` de uma coluna nullable,
   sem default, sem índice e sem CHECK é equivalente nos dois motores. Esse "baixo" vale **só**
   para este caso; qualquer DDL com índice, CHECK, enum, FK ou `change()` precisa ser reconferido
   contra o MySQL 8 antes de ir. A SUMMARY tem de declarar isso explicitamente, em vez de deixar o
   leitor supor que o teste local cobriu produção.
8b. **Duração local não é duração de produção, por dois motivos somados.** A tabela local tem
   **0 linha** e a de produção tem **1.080.206 linhas / 1.776 MB**; e o motor é outro. Nenhum número
   de tempo medido aqui pode ser apresentado como previsão de deploy.
9. **`upsert()` ignora casts.** Gravar `json_encode($skus)` à mão no array de `$linhas`, como
   `variations`/`tags` já fazem. O cast `'skus' => 'array'` no model serve para a **leitura**.
10. **Lista vazia ≠ NULL.** Item coletado sem nenhum SKU grava `json_encode([])`; `NULL` fica
    reservado para "linha nunca coletada desde esta mudança" (D-RIE-02).
11. **Gates de vocabulário visual valem para o markup novo.**
    `tests/js/estrutura-meus-anuncios.test.js` barra, em `MeusAnuncios.jsx`: `text-xs`,
    `text-[10px]`/`[12px]`/`[13px]`, `font-medium`, `font-bold` e **qualquer** classe utilitária com
    meio-passo (`mt-0.5`, `gap-1.5`, `px-2.5`…). Usar `text-[11px]`, `text-sm`, `font-semibold` e
    espaçamento inteiro.
12. **Nenhum teste chama a API real do ML.** `Http::fake` registrando **só** os endpoints usados — o
    `setUp` de `ColetaAcervoTest` avisa que `Http::fake()` vazio esconde chamada não prevista.
13. **No arquivo de render JS: montar TODOS os bundles ANTES do primeiro `test()`.** O `after()` do
    `node --test` já matou um arquivo inteiro sem nenhum teste falhar. E
    `assert.match(tag, /disabled/)` é **asserção vazia** (casa com o nome do componente, com
    comentário, com qualquer coisa): usar `/disabled=/`.
14. **Não tocar em precificação nem no card "Quanto você recebe"** (`7a6f752c`, `ad86541b`) — e não
    tocar em `app/Support/Publicador` nem em `app/Services/Portal/Estrutura` (gate de diff).
</armadilhas>

<tasks>

<task type="auto" tdd="true">
  <name>Tarefa 1: coluna skus, extrator e coleta do SKU pela camada barata</name>
  <files>database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php, app/Models/MlAcervoItem.php, app/Services/Mlb/Acervo/SkusDoAnuncio.php, app/Services/Mlb/Acervo/MlAcervoService.php, tests/Unit/Phase134/SkuDoAcervoTest.php</files>
  <behavior>
    **ANTES de editar qualquer arquivo**, medir e anotar os baselines que ainda não estão medidos
    (os de `Publicador` e JS já estão — ver `gates`):
    - `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase134 tests/Feature/Phase134`
    - `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase135`
    Registrar testes/falhas de cada um. Falha pré-existente aqui **não** se conserta neste plano —
    se conserta o registro dela.

    RED primeiro, em `tests/Unit/Phase134/SkuDoAcervoTest.php` (arquivo NOVO; molde:
    `ColetaAcervoTest` + `SchemaAcervoTest`, `RefreshDatabase`, `Http::fake` só dos endpoints usados):

    *Schema e model*
    - T1: `Schema::hasColumn('ml_acervo_itens', 'skus')` é verdadeiro.
    - T2: gravar `['A1','B2']` e reler devolve **array** (cast); `null` continua `null` (nunca `[]`
      por acidente).
    - T3: a fonte da migration **não** contém `after(` nem `->json(` — o gate que trava o D-RIE-03
      (ler o arquivo e assertar por regex, como `SchemaAcervoTest` faz com nomes de índice).

    *Extrator `SkusDoAnuncio::extrair()` — os ramos que o briefing exige*
    - T4: SKU pelo atributo `SELLER_SKU` (`attributes: [{id:'SELLER_SKU', value_name:'ABC-1'}]`)
      → `['ABC-1']`.
    - T5: SKU pelo **fallback** `seller_custom_field` quando não há atributo → `['XYZ-9']`.
    - T6: o atributo **vence** o `seller_custom_field` quando os dois existem e divergem.
    - T7: anúncio com variações e SKUs **diferentes** → **todos**, sem perder nenhum
      (`['V1','V2','V3']`), inclusive quando o pai não tem SKU (a lista começa pelas variações).
    - T8: variações com o **mesmo** SKU repetido → uma entrada só (distintos).
    - T9: pai COM SKU + variações com SKUs próprios → o do pai **primeiro**, variações depois.
    - T10: sem SKU em lugar nenhum → `[]`.
    - T11: `value_name` em formato inesperado (array/objeto) e `seller_custom_field` em
      branco/`'   '` → descartados, sem `Array to string conversion`.

    *Coleta*
    - T12: `ATRIBUTOS_MULTIGET` contém `seller_custom_field` (gate sobre a constante via Reflection,
      ou assertando a querystring da chamada registrada pelo `Http::fake`).
    - T13: `COLUNAS_CAMADA_BARATA` contém `'skus'` **e continua NÃO contendo** `buybox_status`,
      `visitas_30d`, `performance_score`, `performance_level`, `performance_acoes`,
      `detalhe_coletado_em` (armadilha 2).
    - T14: `coletarItens($company, ['MLB1'])` com o multiget devolvendo `SELLER_SKU` grava
      `skus = ['ABC-1']` na linha.
    - T15: **linha pré-existente com `skus = NULL` recebe o SKU** na coleta seguinte — prova
      executável do D-RIE-04; sem este teste a armadilha 1 passa silenciosa.
    - T16: item coletado **sem** SKU grava `[]`, nunca `NULL` (D-RIE-02).
    - T17: a coleta **não** apaga `buybox_status`/`visitas_30d` de linha pré-existente (regressão do
      T-134-26, re-travada agora que a lista do 3º argumento mudou).
  </behavior>
  <action>
    **1a. Migration** `database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php`.

    Docblock em pt-BR, **antes do código**, com a tabela de decisão de schema (molde:
    `2026_10_08_150100_add_descricao_to_estrutura_produtos.php` e a prosa da migration original do
    acervo):

    | Decisão | Valor | Por quê |
    |---|---|---|
    | coluna | `skus` | lista dos SKUs distintos do anúncio (pai + variações), não "o" SKU — D-RIE-01 |
    | tipo | `longText` nullable, sem default | conteúdo é JSON serializado pelo cast `array`; `longText` é o mesmo `LONGTEXT NULL` nos **três** motores em jogo (MySQL 8 em produção, MariaDB no local, SQLite nos testes) — `json()` não é portável do mesmo jeito: no MariaDB local ele vira `longtext` + CHECK `json_valid` |
    | posição | **última** (sem `after()`) | escolha portável: no MySQL 8.0.46 de produção o instant add funciona em qualquer posição (desde o 8.0.29), mas no MariaDB local só na última — entrar no fim vale nos dois, sem depender de versão |
    | NULL | "ainda não coletado" | `[]` é "coletado e sem SKU" — D-RIE-02 |
    | índice | nenhum | a busca é `LIKE '%termo%'`, que índice não atende; o recorte é `company_id`, já indexado — D-RIE-06 |
    | backfill | nenhum | a varredura diária cobre o acervo inteiro e `skus` está no 3º argumento do upsert — D-RIE-04 |

    Corpo: `up()` com
    `if (! Schema::hasColumn('ml_acervo_itens', 'skus')) { Schema::table('ml_acervo_itens', fn (Blueprint $t) => $t->longText('skus')->nullable()); }`;
    `down()` simétrico com `hasColumn` + `dropColumn`. **Sem** try/catch, **sem** `after()`, **sem**
    consulta a driver (não há ramo de SQLite a fazer: `longText` nullable existe nos dois).

    Fechar o docblock com a nota de deploy, explícita e **nomeando o motor**: **`ALTER TABLE
    ml_acervo_itens ADD COLUMN skus LONGTEXT NULL` é operação de deploy numa tabela grande** —
    1.080.206 linhas, 1.776 MB de dados, 196 MB de índices, InnoDB (medido em 10/10/2026).
    **Produção é MySQL 8.0.46**, não MariaDB (o MariaDB 10.4.32 é o local do XAMPP). Lá, coluna
    nullable sem default, sem índice e sem CHECK é acrescentada **por metadado (INSTANT)**: não
    reescreve as linhas, não copia os 1,7 GB e não trava escrita por tempo perceptível. Registrar
    também, no mesmo docblock, que **provar a migration no MariaDB local não prova DDL no MySQL 8** —
    para este caso a diferença é irrelevante porque `ADD COLUMN` nullable sem índice/CHECK é
    equivalente nos dois, e é exatamente essa equivalência que torna o teste local suficiente aqui
    (ver armadilha 8).

    **1b. `app/Services/Mlb/Acervo/SkusDoAnuncio.php`** (arquivo NOVO, `final class`):
    - `public static function extrair(array $item): array` — devolve `list<string>`.
    - Implementação: um `$doAtributo` que procura `SELLER_SKU` em `attributes` e lê `value_name`
      **só se for string ou número** (pode vir array/objeto); SKU do pai = atributo `?:`
      `seller_custom_field`; depois, para cada entrada de `$item['variations'] ?? []`, a **mesma**
      regra em `$v['attributes']` / `$v['seller_custom_field']`; `trim` em tudo, descartar vazio,
      `unique` preservando a ordem, `array_values`.
    - Docblock em pt-BR com as três coisas que precisam estar escritas:
      (a) a fonte da receita é `AnunciosMercadoLivreService::skuDoAnuncio()` (L626/L632) e ela
      **não** foi alterada; (b) a diferença deliberada — lá, variações divergentes devolvem `null`
      porque uma linha da colagem é um SKU só; aqui **todas** entram, porque o propósito é **achar o
      anúncio por qualquer um dos seus SKUs** (D-RIE-01/D-RIE-05); (c) por que uma lista cabe nesta
      linha: a mesma linha já guarda `variations` com o payload **bruto** das variações, que é
      ordens de grandeza maior que a lista de SKUs extraída dele.

    **1c. `app/Models/MlAcervoItem.php`**: `'skus'` no `$fillable` e `'skus' => 'array'` no
    `$casts`, com um comentário de uma linha sobre NULL vs `[]` (D-RIE-02).

    **1d. `app/Services/Mlb/Acervo/MlAcervoService.php`** — três edições cirúrgicas:
    - `ATRIBUTOS_MULTIGET`: acrescentar `seller_custom_field` ao fim da string, com comentário
      dizendo que é o fallback do SKU e que vem de graça no mesmo payload (precedente:
      `ProdutosDaContaService::ATRIBUTOS`). `attributes` e `variations` **já** são pedidos — é de lá
      que sai o resto da receita.
    - `COLUNAS_CAMADA_BARATA`: acrescentar `'skus'`, com comentário dizendo **por que é
      obrigatório** (sem isso, linha existente nunca recebe SKU e o "sem backfill" do D-RIE-04 cai).
      Não mexer em mais nada da lista.
    - `processarLote()`: dentro do `foreach ($bodies as $item)`, antes de montar `$linhas[]`,
      `$skus = SkusDoAnuncio::extrair($item);` e, no array da linha, `'skus' => json_encode($skus)`
      — logo depois de `'variations'`, perto do dado de onde o SKU sai. `json_encode` à mão porque
      `upsert()` não aplica casts (armadilha 9). Mesmo namespace: **não** adicionar `use` redundante.
    - **Nenhuma** outra mudança: nem `enumerarIds`, nem o `catch` de empresa, nem o lock, nem
      `coletarItens()`, nem `gravarSerieDiaria()`.

    **1e. Provar a migration no MariaDB local** (10.4.32, banco `ecf_admin`, tabela existe com 0
    linha), com `--path` e nos três passos, registrando a saída de cada um:
    `migrate --path=...` → `migrate:rollback --path=...` → `migrate --path=...`.
    Conferir por `SHOW COLUMNS FROM ml_acervo_itens LIKE 'skus'` (tem de aparecer como `longtext`
    NULL) e conferir que ela é a **última** coluna da tabela.
    ⚠️ Esta prova é de **execução**, não de produção: o local é MariaDB 10.4.32 e produção é MySQL
    8.0.46 (armadilha 8). Ela vale aqui porque `ADD COLUMN` nullable sem default/índice/CHECK é
    equivalente nos dois motores — registrar essa frase na SUMMARY, não deixá-la implícita.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase134</automated>
  </verify>
  <done>
    `SkuDoAcervoTest` inteiro verde; `tests/Unit/Phase134` sem regressão contra o baseline medido no
    início (inclusive `SerializacaoEscritaAcervoTest` e `ColetaAcervoTest`); ida/volta/ida da
    migration no MariaDB local sem erro, com `skus` listada como `longtext` NULL **no fim** da
    tabela; `grep -n "after(\|->json(" database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php`
    sem resultado; `git diff --stat 62d71b89..HEAD -- app/Support/Publicador app/Services/Portal/Estrutura`
    vazio.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 2: busca cobre SKU + nome + MLB, e Em revisao isola under_review</name>
  <files>app/Services/Publicador/AcervoTriagemService.php, app/Http/Controllers/MlbAnuncioController.php, tests/Unit/Publicador/AcervoTriagemServiceTest.php, tests/Feature/Phase134/MeusAnunciosTest.php</files>
  <behavior>
    RED primeiro. Depende da Tarefa 1 (a coluna tem de existir). **Edit** nos dois arquivos de teste
    existentes, nunca Write.

    `tests/Unit/Publicador/AcervoTriagemServiceTest.php` — `escopo()`:
    - T18: item com `skus = ['ABC-1']` é achado por `escopo($company, 'ABC-1', 'todos')`. **Hoje não
      é** — é o RED do RIE-01.
    - T19: achado por **pedaço** do SKU (`'BC-'`), como já vale para o título.
    - T20: **regressão** — busca por pedaço do `title` e busca pelo `ml_item_id` continuam achando,
      exatamente como antes.
    - T21: **escopo por empresa** — item de OUTRA empresa com o mesmo SKU **não** aparece (prova de
      que o `orWhere` novo ficou dentro do `where(function...)`, armadilha 5).
    - T22: a busca por SKU **respeita o filtro de status**: o mesmo SKU num item `closed` não
      aparece com `'acionaveis'` e aparece com `'todos'`/`'encerrados'`.
    - T23: linha com `skus = NULL` não é achada pelo SKU e não quebra a query — o caso que o RIE-04
      cobre na tela.
    - T24: termo contendo `"` (idem `[`) **não** devolve o acervo inteiro (armadilha 6) — montar
      duas linhas coletadas e assertar 0 resultado em vez de 2.
    - T25: `escopo($company, '', 'em_revisao')` devolve **só** o `under_review`.
    - T26: `'acionaveis'` continua devolvendo `active` + `paused` + `under_review` (nada saiu) e
      `ativos`/`pausados`/`encerrados`/`todos` seguem inalterados.
    - T27: chips com `em_revisao` — `triagem($company, '', 'em_revisao')` devolve `pausado = 0` e
      `sem_estoque = 0` (a verdade daquele universo, D-RIE-09) e conta normalmente um
      `ficha_incompleta` de item `under_review`.

    `tests/Feature/Phase134/MeusAnunciosTest.php` — a tela:
    - T28: `?busca=<SKU>` **sem** querystring de status acha o anúncio `under_review` com aquele SKU
      (coluna + busca + filtro padrão, o caminho completo do pedido do usuário).
    - T29: `?status=em_revisao` lista só os `under_review`; valor inválido (`?status=xpto`) continua
      caindo em `acionaveis`.
    - T30: a prop de cada linha traz `skus` como **array** (`[]`, nunca `null`, quando não coletado).
    - T31: **ordenação inalterada** com `?status=em_revisao` — dois `under_review` com severidades
      diferentes saem na ordem do D-12 (severidade desc, depois `nota_ecf`).
    - T32: busca que não acha nada **e** empresa sem nenhum `skus` coletado → prop
      `skuNaoColetado = true`.
    - T33: busca que não acha nada **mas** com ao menos uma linha com `skus` coletado →
      `skuNaoColetado = false`.
    - T34: busca que **acha** → `skuNaoColetado = false`.
  </behavior>
  <action>
    **2a. `AcervoTriagemService::escopo()`** — duas mudanças, as duas aditivas:
    - `match`: acrescentar `'em_revisao' => ['under_review'],` **sem alterar nenhum arm existente**.
      Comentário em pt-BR acima do arm, datado (10/10/2026): o usuário pediu para **isolar** o que
      está em revisão (*"Em revisão quero poder isolar"*); `acionaveis` segue sendo o padrão e segue
      cobrindo os três status (emendas de 10/08 e de 10/10 **intactas**); e o efeito nos chips
      (`MOTIVO_PAUSADO`/`MOTIVO_SEM_ESTOQUE` necessariamente 0 nesse universo, porque
      `AnuncioSaudeService::triagem()` só os emite para `paused`/`active` — é a verdade do filtro,
      não um bug) e na ordenação (nenhum: o D-12 é agnóstico de status).
    - busca: acrescentar, **dentro do mesmo `where(function ($s) ...)`**, um terceiro
      `->orWhere('skus', 'like', "%{$busca}%")`, **condicionado** a o termo não conter `"`, `[` nem
      `]` (armadilha 6 — guardar a condição numa variável local nomeada, ex. `$buscaCabeNoJson`, com
      o comentário explicando que a pontuação do JSON casaria com toda linha coletada). Comentário em
      pt-BR acima do grupo: a busca agora cobre os **três** identificadores que o usuário tem na mão
      (nome, código MLB e SKU), o agrupamento é o que preserva o escopo por empresa (T-134-01) e
      `skus` é texto JSON lido pelo cast `array` do model.
    - Atualizar o docblock de `escopo()` para dizer que a busca cobre três campos.

    **2b. `MlbAnuncioController::meus()`** — três mudanças:
    - whitelist: `['acionaveis','ativos','pausados','em_revisao','encerrados','todos']`. **Manter** o
      comentário existente e **acrescentar** (não reescrever) a nota de que `em_revisao` entrou em
      10/10/2026 a pedido do usuário, é opção NOVA, e que `acionaveis` segue o padrão.
    - `through()`: acrescentar `'skus' => $item->skus ?? []` — array sempre, nunca `null` (a tela não
      precisa distinguir; o aviso de cobertura é prop separada).
    - prop nova `skuNaoColetado`, calculada **só** no caminho de zero resultado com busca:

          $skuNaoColetado = $busca !== '' && $anuncios->total() === 0
              && ! MlAcervoItem::where('company_id', $company->id)->whereNotNull('skus')->exists();

      Comentário em pt-BR explicando as duas coisas: (a) **por que existe** — buscar SKU num acervo
      sem SKU coletado devolveria "não achei" como se o anúncio não existisse, e isso é mentira por
      omissão (RIE-04); (b) **por que é barata** — `$anuncios->total()` vem do `count` que o
      `paginate()` já fez, e o `exists()` só roda quando a busca não achou nada, nunca no caminho
      normal da tela. Incluir a prop no `Inertia::render`.
    - **Não** mexer em ordenação, `motivo`, `comVenda`, triagem, defasagem nem rascunhos.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador/AcervoTriagemServiceTest.php tests/Feature/Phase134 tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php</automated>
  </verify>
  <done>
    T18-T34 verdes; `tests/Feature/Phase134` e `AcervoTriagemServiceTest` sem regressão;
    `grep -n "orWhere(" app/Services/Publicador/AcervoTriagemService.php` mostra os `orWhere` da
    busca **dentro** do `where(function`; a lista fechada do controller tem **6** valores, com
    `acionaveis` ainda como default.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 3: a tela — Em revisao, placeholder dos tres, SKU na linha e o vazio honesto</name>
  <files>resources/js/Pages/Mlb/MeusAnuncios.jsx, tests/js/publicador-meus-anuncios-sku-render.test.js</files>
  <behavior>
    RED primeiro, em `tests/js/publicador-meus-anuncios-sku-render.test.js` (arquivo NOVO; molde:
    `tests/js/publicador-acervo-render.test.js` — esbuild + `react-dom/server`, render **real**).
    **Montar o bundle ANTES do primeiro `test()`** (armadilha 13), com `alias` para `@` e stubs de
    `@inertiajs/react` e `@/Layouts/AppLayout` (molde do `estrutura-montar-kit-render.test.js`);
    `external` para `react`, `react-dom/server`, `react/jsx-runtime`, `lucide-react`, `@radix-ui/*`,
    `clsx`, `tailwind-merge`, `class-variance-authority`.

    O arquivo passa a exportar dois componentes nomeados, para o teste desenhar sem montar a página
    inteira:
    - T35: `CelulaSku` com `skus: ['ABC-1']` → o texto `ABC-1` aparece; nada de `[object Object]`.
    - T36: `skus: ['V1','V2','V3']` → mostra o primeiro **e** diz que são 3 (nunca apresenta um SKU
      de variação como se fosse o único).
    - T37: `skus: []` → não desenha nada (sem rótulo "SKU" órfão).
    - T38: `skus: null`, `skus` **ausente** (`undefined`) e `skus: { foo: 'bar' }` (**objeto**,
      formato inesperado do servidor) → **não lança** e não imprime `[object Object]`.
    - T39: `skus: ['A', 123, null, '  ', { x: 1 }]` → desenha só o que é texto útil.
    - T40: `VazioDaListagem` sem busca → o texto de hoje, **literal** ("Esta empresa não tem
      anúncios ativos no Mercado Livre.").
    - T41: `VazioDaListagem` com `busca: 'ABC-1'` → diz o que foi procurado e que a busca cobre
      título, código MLB e SKU; **não** afirma que o anúncio não existe.
    - T42: `VazioDaListagem` com `busca` + `skuNaoColetado: true` → aparece a frase do SKU ainda não
      coletado + a instrução de "Atualizar agora" (RIE-04).
    - T43: `busca` como **objeto** → não lança, não imprime `[object Object]`.

    No gate de fonte que já existe (`tests/js/estrutura-meus-anuncios.test.js`), **nada a editar**:
    o markup novo tem de passar nos gates de vocabulário como está (armadilha 11). Se algum deles
    falhar, o errado é o markup.
  </behavior>
  <action>
    Editar `resources/js/Pages/Mlb/MeusAnuncios.jsx` (Edit, nunca Write):

    - **Comentário da L36 — corrigir.** Hoje diz "default virou 'acionaveis' (ativos + pausados)", o
      que ficou **falso** com a `261010-nke`. Passa a dizer: default `acionaveis` = ativos +
      pausados + **em revisão**, com as duas datas das emendas (10/08/2026 e 10/10/2026), e que a
      whitelist fechada do backend é `MlbAnuncioController::meus()` — agora com **6** valores.
    - **`STATUS_OPCOES`** — acrescentar `{ valor: 'em_revisao', label: 'Em revisão' }` entre
      `pausados` e `encerrados`. Rótulo em pt-BR; `under_review` **não** aparece na tela (D-RIE-08).
    - **Placeholder da busca** — `"Buscar por SKU, título ou código MLB…"` (os três que o backend
      cobre; nada de prometer o que não cobre).
    - **`CelulaSku({ skus })`** — `export function`, perto de `SeloOrigem`: filtra a lista para só
      strings não vazias; `[]` → `return null`; 1 item → `SKU {valor}`; N itens → `SKU {primeiro}
      +{N-1}`, com `title` dizendo `"{N} SKUs (um por variação): ..."`. Classes `text-[11px]` +
      `text-white/40` (gates: nada de `text-xs`, `font-medium`, meio-passo). Comentário curto
      explicando por que pode haver mais de um (D-RIE-01) e por que o filtro é defensivo (a lista vem
      do servidor e pode chegar em formato inesperado).
    - **Usar `CelulaSku`** na célula "Anúncio", logo abaixo do `<span>` do título, junto do
      `<SeloOrigem>`.
    - **`VazioDaListagem({ busca, skuNaoColetado, acao })`** — `export function` que substitui o
      bloco inline de `itens.length === 0`:
      - sem `busca` (ou `busca` não-string): o card de hoje, **texto literal**, intocado.
      - com `busca`: "Nada encontrado para …" + a linha dizendo que a busca cobre **título, código
        MLB e SKU** e sugerindo outro termo ou outro filtro de status.
      - `skuNaoColetado`: uma linha a mais, em destaque suave (`text-ecf-yellow/80`), dizendo que o
        SKU dos anúncios desta empresa **ainda não foi coletado** e que por isso uma busca por SKU
        não acharia nada **mesmo que o anúncio exista** — com a instrução de clicar em "Atualizar
        agora" e buscar de novo.
      - `acao`: nó React opcional (a página passa o `<BotaoAtualizar …/>` que já existe; o teste
        passa `null`).
    - Chamar
      `<VazioDaListagem busca={filtros.busca} skuNaoColetado={skuNaoColetado} acao={<BotaoAtualizar … />} />`
      no lugar do bloco antigo, lendo `skuNaoColetado` das props da página com default `false`.
    - **Nada mais.** Não tocar na tabela além da célula "Anúncio", não tocar em precificação, não
      renderizar status por linha.

    Depois: `npm run build` (obrigatório — `resources/` foi tocado) e conferir o manifest **pelo
    JSON**, nunca por grep (o hash sai com hífen). Hash de **antes**, já medido:
    `assets/MeusAnuncios-Dxunbzrh.js` — registrar o de **depois**.
  </action>
  <verify>
    <automated>node --test "tests/js/publicador-meus-anuncios-sku-render.test.js" "tests/js/estrutura-meus-anuncios.test.js"</automated>
  </verify>
  <done>
    T35-T43 verdes e `estrutura-meus-anuncios.test.js` sem regressão; `npm run build` concluído sem
    erro; o gate do manifest (comando em `gates`) imprime a entrada
    `resources/js/Pages/Mlb/MeusAnuncios.jsx` com o `file` novo; nenhuma classe proibida no markup
    novo.
  </done>
</task>

<task type="auto">
  <name>Tarefa 4: gates, SUMMARY e commit</name>
  <files>.planning/quick/261010-rie-busca-por-sku-nome-e-mlb-em-publicacoes-/SUMMARY.md</files>
  <action>
    Rodar **todos** os gates da seção `gates`, registrando **antes/depois** de cada um. Nenhuma
    conclusão por "deve estar ok": número medido ou não entra na SUMMARY.

    Escrever a SUMMARY (pt-BR), cobrindo **obrigatoriamente**:
    1. **O `ALTER TABLE` em tabela grande e o que esperar no deploy** — a sentença literal
       (`ALTER TABLE ml_acervo_itens ADD COLUMN skus LONGTEXT NULL`), o tamanho real da tabela
       (**1.080.206 linhas, 1.776 MB de dados, 196 MB de índices, InnoDB**, medidos em 10/10/2026),
       e o **motor correto, nomeado**: produção é **MySQL 8.0.46**, não MariaDB — o MariaDB 10.4.32 é
       o local do XAMPP. Expectativa: coluna nullable sem default/índice/CHECK entra **por metadado
       (INSTANT)**, sem reescrever linha, sem copiar os 1,7 GB e sem travar escrita por tempo
       perceptível. Explicar por que o tipo é `longText` no fim da tabela mesmo assim: portabilidade
       entre os três motores (MySQL 8 em produção, MariaDB no local, SQLite nos testes), não
       otimização para um motor que não é o de produção.
    1b. **Que o teste local NÃO prova produção** — e por que, neste caso, isso é aceitável:
       `ADD COLUMN` nullable sem índice/CHECK é equivalente nos dois motores. Dizer **explicitamente**
       que a equivalência é a razão, para ninguém generalizar; qualquer DDL futuro com índice, CHECK,
       enum, FK ou `change()` precisa ser reconferido contra o MySQL 8. E que nenhum tempo medido no
       local (tabela com 0 linha, motor diferente) serve como previsão de deploy.
    2. **Se o SKU precisa de backfill e por quê** — a resposta é **não**, com as três razões do
       D-RIE-04 (varredura diária cobre o acervo inteiro; `skus` está no 3º argumento do upsert, logo
       linha existente é atualizada; um backfill seria a própria varredura de novo, porque o SKU só
       vem do multiget). Registrar a janela: até a próxima passada diária, ou imediato por empresa
       via "Atualizar agora".
    3. **Como o SKU de anúncio com variações foi guardado** — a lista de **todos** os SKUs distintos
       (pai primeiro, variações depois), por que não foi colapsado em um só, e qual é a diferença
       deliberada em relação a `AnunciosMercadoLivreService::skuDoAnuncio()` (que devolve `null`
       quando as variações divergem). Dizer também o que a tela mostra quando há mais de um.
    4. **Efeito confirmado nos chips (D-09) e na ordenação (D-12)** da opção `Em revisão` —
       com o teste que prova cada um (T27 e T31).
    5. **Os três estados de `skus`** (`NULL` / `[]` / lista) e como o RIE-04 usa isso.
    6. Baselines antes/depois de **cada** suíte, incluindo `Phase134`/`Phase135` (que não estavam
       medidos), e o hash do `MeusAnuncios` no manifest antes/depois.
    7. **Perguntas abertas / fora de escopo** (seção `fora_de_escopo` abaixo).

    **NÃO afirmar ter visto tela renderizada.** Não há navegador nesta sessão: o que existe é render
    de componente no servidor (`react-dom/server`) e gates de fonte. A conferência visual é do
    usuário.

    Commit único no fim, com pathspec explícito em `git add --` **e** em `git commit -m "…" --`
    (árvore compartilhada). Mensagem multilinha acentuada por `-F <arquivo>` no scratchpad.
    Caminhos: os 11 de `files_modified` + os arquivos de
    `.planning/quick/261010-rie-busca-por-sku-nome-e-mlb-em-publicacoes-/` + os artefatos de
    `public/build/` alterados pelo `npm run build`.
    Mensagem sugerida: `feat(publicador): busca de Publicações cobre SKU, nome e MLB; "Em revisão" ganha filtro próprio`
    Terminar com `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador</automated>
  </verify>
  <done>
    Todos os gates registrados com número antes/depois; SUMMARY com os 7 itens acima; commit único
    feito por pathspec, sem `git add -A`, sem push, sem deploy.
  </done>
</task>

</tasks>

<gates>
Medir **antes** de editar e **depois**; registrar os dois números. Baseline já medido pelo
orquestrador, em árvore limpa:

| Gate | Antes | Depois (preencher) |
|---|---|---|
| `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1728 testes / 0 falha** | |
| `npm run test:js` | **2141 testes / 2140 pass / 1 fail** (`estrutura-grade-glide`, "Características secundárias nasce recolhido" — pré-existente, **NÃO corrigir**) | |
| `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase134 tests/Feature/Phase134` | **MEDIR na Tarefa 1** | |
| `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase135` | **MEDIR na Tarefa 1** | |

Gate do build (obrigatório — `resources/` é tocado):

    npm run build
    node --input-type=commonjs -e "const fs=require('fs');const m=JSON.parse(fs.readFileSync('public/build/manifest.json','utf8'));const k=Object.keys(m).filter(x=>/MeusAnuncios/.test(x));if(!k.length)throw new Error('MeusAnuncios fora do manifest');console.log(JSON.stringify(k.map(x=>[x,m[x].file])));"

Hash **antes** (medido): `resources/js/Pages/Mlb/MeusAnuncios.jsx` → `assets/MeusAnuncios-Dxunbzrh.js`.
Nunca conferir manifest por `grep` — o hash sai com hífen.

Gate de diff — **medido do ponto de partida, nunca da árvore** (`git diff --stat -- <dir>` depois de
commitar sai vazio por construção e não prova nada):

    git diff --stat 62d71b89..HEAD -- app/Support/Publicador app/Services/Portal/Estrutura   # deve ser VAZIO
    git diff --stat 62d71b89..HEAD -- app/Services/Mlb/Acervo app/Services/Publicador app/Http/Controllers resources/js/Pages/Mlb   # o escopo real

Migration no **MariaDB local (10.4.32)** — prova de execução, **não** de produção (produção é
**MySQL 8.0.46**; ver armadilha 8). Os três passos, com a saída de cada um registrada:

    C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php
    C:/xampp/php/php.exe artisan migrate:rollback --path=database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php
    C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php
    C:/xampp/mysql/bin/mysql.exe -u root ecf_admin -e "SHOW COLUMNS FROM ml_acervo_itens;"

⚠️ `php artisan migrate` **do zero** não roda no MariaDB 10.4 do XAMPP (learning da Agenda) — por
isso `--path`, sempre, contra o banco local que já tem a tabela.
⚠️ Não existe gate de produção aqui, e não deve existir: o executor é **bloqueado** de consultar
a VPS. Os números de produção (MySQL 8.0.46; 1.080.206 linhas / 1.776 MB / 196 MB de índices)
foram medidos pelo orquestrador em 10/10/2026 e entram na SUMMARY **como medição citada**, nunca
como algo que o executor conferiu.
</gates>

<fora_de_escopo>
Coisas que este plano **deliberadamente não faz**. Registrar na SUMMARY como pergunta ao usuário,
nunca implementar por conta própria.

1. **Backfill retroativo do SKU.** Decidido não fazer (D-RIE-04). Se o usuário quiser o SKU em todo
   o acervo **antes** da próxima varredura diária, o caminho é "Atualizar agora" por empresa — e um
   comando com `--dry-run` + a ordem materializar → conferir → comparar → aplicar só se ele pedir.
   **Pergunta:** há empresa em que esperar até as 11:35 de amanhã incomoda?
2. **Índice para a busca.** Não criado (D-RIE-06): `LIKE '%termo%'` não usa índice. Se a busca ficar
   lenta nas contas grandes, o caminho honesto é busca full-text ou prefixo (`LIKE 'termo%'`), que
   **muda o comportamento** — decisão do usuário, não do plano.
3. **Status `inactive` em algum filtro.** `encerrados` cobre só `['closed']`; item `inactive` só
   aparece em "Todos". Buraco **pré-existente**, não tocado — mudar `encerrados` alteraria o
   significado do rótulo.
4. **SKU na busca da Visão geral / de outras telas.** Só a aba Publicações foi pedida. `comMotivos()`
   e `legadoEntre()` continuam chamando `escopo()` com busca vazia.
5. **Mostrar o SKU no modal de detalhe (`ModalDetalheAnuncio.jsx`).** Não pedido; o SKU entra na
   linha da tabela, que é onde a busca é conferida. **Pergunta:** faz falta no detalhe?
6. **Coluna de status por linha na tabela.** Continua fora (a tela nunca mostrou status por linha);
   o isolamento do "Em revisão" é pelo filtro, como o usuário pediu.
7. **Precificação e o card "Quanto você recebe".** Entregues em `7a6f752c`/`ad86541b` — não tocar.
</fora_de_escopo>

<success_criteria>
- [ ] Coluna `skus` existe, nullable, **última** da tabela, sem `after()`, sem `json()`, sem índice,
      migration idempotente e sem try/catch em DDL.
- [ ] Ida → volta → ida da migration provada no MariaDB **local** com `--path`, **e** a ressalva
      escrita na SUMMARY: produção é **MySQL 8.0.46** (local é MariaDB 10.4.32, testes são
      SQLite), o teste local prova execução e não DDL de produção, e nenhum tempo medido aqui
      (0 linha, motor diferente) vale como previsão para as 1.080.206 linhas / 1,7 GB de lá.
- [ ] `seller_custom_field` no `ATRIBUTOS_MULTIGET` e `'skus'` em `COLUNAS_CAMADA_BARATA` — com teste
      provando que **linha pré-existente recebe o SKU** na coleta seguinte.
- [ ] Nenhuma coluna da camada CARA entrou no 3º argumento do upsert (T-134-26 re-travado).
- [ ] Nenhum `upsert`/`update` novo em `ml_acervo_itens`; toda escrita segue dentro de
      `AcervoEscritaLock::naEmpresa()`; `SerializacaoEscritaAcervoTest` verde sem edição.
- [ ] SKU extraído do atributo `SELLER_SKU` **e** do `seller_custom_field` **e** no caso de anúncio
      com variações — os três ramos com teste próprio, e nenhum SKU de variação perdido.
- [ ] A busca acha por SKU, por nome e por MLB no mesmo campo; nome e MLB **sem regressão**; escopo
      por empresa e filtro de status preservados; termo com pontuação de JSON não devolve o acervo.
- [ ] `em_revisao` isola `under_review`; `acionaveis` continua `active + paused + under_review`;
      lista fechada com 6 valores e `acionaveis` ainda como default.
- [ ] Chips e ordenação **confirmados** inalterados pela opção nova (T27 e T31), com o porquê escrito
      no código.
- [ ] Busca sem resultado em acervo sem SKU coletado mostra o aviso — a tela não mente dizendo que o
      anúncio não existe.
- [ ] Render REAL dos componentes novos com `skus`/`busca` **objeto, nulo e ausente**, todos os
      bundles montados antes do primeiro `test()`, nenhuma asserção vazia.
- [ ] Comentário desatualizado da L36 do `MeusAnuncios.jsx` corrigido.
- [ ] `tests/Unit/Publicador` + `tests/Feature/Publicador` de volta a **0 falha** (baseline 1728).
- [ ] `npm run test:js` com **exatamente 1** falha (a pré-existente).
- [ ] `Phase134` e `Phase135` medidos antes e depois, sem regressão.
- [ ] `npm run build` rodado e manifest conferido **pelo JSON**.
- [ ] `git diff --stat 62d71b89..HEAD -- app/Support/Publicador app/Services/Portal/Estrutura` vazio.
- [ ] Nenhum jargão na tela: "Em revisão", nunca `under_review`.
- [ ] Artefatos, comentários e commit em **pt-BR**.
</success_criteria>

<ambiente_e_commit>
- `php` NÃO está no PATH: usar `C:/xampp/php/php.exe`. Banco local: `C:/xampp/mysql/bin/mysql.exe`
  (é **MariaDB 10.4.32**, apesar do caminho dizer "mysql").
- ⚠️ **Produção é MySQL 8.0.46, não MariaDB** (medido na VPS em 10/10/2026). Vários arquivos de
  `.planning/learnings/` chamam a produção de "MariaDB" — o orquestrador vai registrar o learning
  que corrige isso. Não herdar conclusão de DDL daqueles textos sem reconferir (armadilha 8).
- **NÃO deployar, NÃO dar push, NÃO tocar no VPS.** O executor é bloqueado de consultar produção —
  nenhum passo deste plano depende disso. O usuário sobe tudo de uma vez depois.
- Árvore **COMPARTILHADA** com outro dev e outras sessões: `git add -- <caminho>` ANTES do commit
  **e pathspec também no `git commit -- <caminho>`**; o `-m` vem ANTES do `--`. Mensagem multilinha
  acentuada: `-F <arquivo>` no scratchpad.
- **NUNCA** `git add -A`, `git stash`, `git reset`, `git checkout`, `git commit --amend`.
- **NÃO** rodar `vendor/bin/pint`. **NÃO** criar worktree. **NÃO** usar Write em arquivo existente
  (usar Edit). **NÃO** tocar em `ROADMAP.md` nem rodar `gsd-sdk query state.advance-plan`.
- `tests/Feature/CompanyPortfolioAccessTest.php` aparece untracked e **não é desta tarefa** — não
  commitar.
- Commits terminam com `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
</ambiente_e_commit>

<output>
Criar `.planning/quick/261010-rie-busca-por-sku-nome-e-mlb-em-publicacoes-/SUMMARY.md` ao terminar.
</output>
