# Phase 134: "Meus Anúncios" — saúde analítica do anúncio publicado - Research

**Researched:** 2026-08-10
**Domain:** Integração de leitura com a API do Mercado Livre (multiget de itens, catálogo/buy box, visitas) + arquitetura de coleta assíncrona (job por empresa) + persistência de snapshot/série temporal
**Confidence:** HIGH nos três achados centrais (A-01, A-02, A-03) — medidos contra produção real, não estimados. MEDIUM/LOW em pontos específicos sinalizados inline (endpoint `/item/{id}/performance`, rate limit exato).

## Summary

Esta pesquisa gastou chamadas de leitura reais contra a API do Mercado Livre em produção (autorizado pelo usuário, D-11 respeitado — nenhum POST/PUT/DELETE) para responder às três perguntas que o `134-CONTEXT.md` deixou em aberto. O achado mais importante, e que muda o desenho da fase, é o **volume real**: as 74 empresas com token ML ativo em produção somam **406.932 itens ativos**, mas essa soma é dominada por **6 contas** (5 de autopeças + 1 de decoração) que sozinhas respondem por **78,5%** do total. A empresa mediana tem **~400 itens ativos**; a média é **~5.500** — o mesmo padrão de distorção por outlier que o projeto já documentou em `desempenho-bonificacao.md` (mediana vs. média), agora aplicado a capacidade de coleta, não a nota.

Essa distorção obriga a fase a **separar a coleta em dois níveis de custo**, não um só: campos "baratos" (status, estoque, vendas, tier, frete, tags, catálogo/buy-box-flag, variações) vêm de graça no multiget `/items?ids=` (lote de até **20 ids confirmado por erro real da API**, não suposição) e cobrem o acervo inteiro em minutos; campos "caros" (visitas via `/items/{id}/visits` e status de buy box via `/items/{id}/price_to_win`) são **1 chamada por item, sem lote** — confirmado empiricamente, inclusive contradizendo a documentação pública que promete multiget de até 20 ids para visitas (a API real recusou com `"maximum amount of items to query is 1"`). Rodar a camada cara para os ~150-180 mil itens `catalog_listing=true` do acervo, todo dia, para todas as contas, não é uma decisão de throttle fino — é uma decisão de escopo que o planner precisa tomar de olhos abertos.

O `health` do próprio ML (campo que o D-10 queria como "autoridade externa") **existe no payload mas está sempre `null`** — confirmado em itens de duas contas diferentes; a API pública que o substituiria (`/item/{id}/performance`) devolveu erro em ambas as tentativas feitas nesta pesquisa. A "saúde dupla" prevista em D-10 precisa de um fallback honesto para esse lado — ver Open Questions.

**Recomendação primária:** coletar diariamente, para o acervo inteiro (ativos + pausados), só a camada barata via multiget + varredura por `scroll_id` (o paging por `offset` estoura em ~1000 itens — confirmado por erro real); tratar visitas e buy-box como camada cara, rotativa/priorizada, e documentar essa fronteira explicitamente para o usuário — não silenciá-la dentro de "D-06 é diário".

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Varredura do acervo (IDs de todos os itens) | Backend / Job (queue worker) | — | HTTP externo de alto volume; nunca no caminho do request (D-05) |
| Coleta de campos "baratos" (multiget) | Backend / Job | Database (upsert) | Batch de 20 ids; grava snapshot corrente |
| Coleta de campos "caros" (visitas, buy box) | Backend / Job | Database (série diária) | 1 call/item; precisa de rotação/priorização por volume |
| Nota ECF (D-10) | Backend / PHP (no job de coleta) | — | Precisa ser coluna persistida e ordenável (D-12); calcular no JS no render não permite `ORDER BY` no banco |
| Triagem por motivo + ordenação por gravidade (D-09/D-12) | Backend / Controller (query) | Database (índices) | Determinístico a partir de colunas já persistidas — sem custo de API no request |
| Tela "Meus Anúncios" (leitura) | Frontend Server (SSR via Inertia) | Browser | Controller monta props do banco; zero chamada ao ML no render (D-05) |
| Botão "Atualizar agora" | Backend / Controller → Job | Browser (feedback otimista) | Enfileira o mesmo job de coleta da empresa; não bloqueia o request |
| Selo de defasagem (D-08) | Backend / Controller | Browser | Deriva de `collected_at` já no banco — sem chamada extra |
| Retenção da série diária (D-07) | Backend / Command agendado | Database | Molde: `mlb:sync-vendas-logs-cleanup` |

## User Constraints (from CONTEXT.md)

<user_constraints>

### Locked Decisions

- **D-01:** A tela lista **todos os itens da conta ML do cliente** (varredura via endpoint de busca de itens do vendedor), não apenas o que este módulo publicou.
- **D-02:** Escopo **por empresa**, com a mesma âncora `{company}` das rotas existentes (`wizard`, `massa`, `historico`), entrando pelo painel de cards de `/mlb/anuncios`. Descartado: indicador de saúde agregado por empresa no painel de cards.
- **D-03:** A tela carrega **só anúncios ativos por padrão**; pausados e encerrados ficam atrás de filtro. A coleta pode varrer a conta inteira.
- **D-04:** Cada anúncio traz **selo de origem com 3 valores**: (a) nasceu neste módulo (`MlAnuncioRascunho.ml_item_id`/`ml_item_id_classico`/`ml_item_id_premium`); (b) publicado pelo time (`Publicacao.mlb_code`); (c) legado do cliente. O join com `Publicacao` é decisão travada — traz também `vendas_qty` e `desconsiderado`.
- **D-05:** **Snapshot em tabela + comando artisan agendado.** A tela lê **exclusivamente do banco** — nenhuma chamada síncrona ao ML no caminho do request. Botão "Atualizar agora" enfileira job por empresa.
- **D-06:** Frequência **diária**, agendada logo após o `ml:sync` das 11:05 em `routes/console.php`.
- **D-07:** Persistência em **dois níveis**: (a) linha corrente por anúncio (upsert); (b) série diária enxuta (visitas, vendas, saúde) com retenção ~90 dias. Retenção e índices são requisito, não polimento.
- **D-08:** Degradação graciosa: coleta falha/velha → tela mostra **o último snapshot com selo de defasagem**, nunca tela em branco.
- **D-09:** Topo da tela é **triagem acionável**: "N anúncios precisam de você", agrupados por motivo (pausado, sem estoque, ficha incompleta, perdendo catálogo, foto insuficiente…), clicável.
- **D-10:** "Saúde" são **duas medidas lado a lado**: (a) health/quality do próprio ML; (b) nota ECF derivada de `mlAnuncioRegras.js`. **Pegadinha travada:** se exibida, a nota ECF **tem que fechar com a conta mostrada na própria tela** (caso `nps_medio` ≠ `pontos_componentes.nps`, ver `desempenho-bonificacao.md`). **Não recalibrar régua por conta própria** — reusar pesos de `calcularScore()`; peso novo = decisão explícita.
- **D-11:** A tela é **só leitura**. Permalink no ML + rascunho no wizard quando aplicável. **Zero write na API do ML.**
- **D-12:** Ordenação padrão **por gravidade do problema** — determinística, funciona no dia 1 mesmo sem visitas.
- **D-13:** **4 abas**, "Meus Anúncios" inicial: Meus Anúncios | Individual | Em massa | Histórico. Histórico sobrevive (base do "Anunciar semelhante em massa").
- **D-14:** Sub-abas **Publicados | Rascunhos** dentro de "Meus Anúncios". Botão "Publicar lote" (BULK-01) migra para a sub-aba Rascunhos, sai do aside do wizard.
- **D-15:** Módulo continua `role:admin`, sem item de menu. Filtro por `responsavel_id` segue dormente.
- **D-16:** "Saúde do anúncio" do wizard **fica intacta** no aside (`AnunciarML.jsx:2761`). Só o bloco de Rascunhos recentes (`AnunciarML.jsx:2831`) sai.

### Claude's Discretion

- Nome exato da tabela/migration, do comando artisan e do job.
- Throttle e paginação da coleta (rate limit / 429): replicar o padrão de delay escalonado de `publicarLote`, parâmetros a cargo do planner.
- Layout fino e escolha de gráfico para a série temporal (Recharts já está no projeto) — cabe ao `/gsd:ui-phase`.

### Deferred Ideas (OUT OF SCOPE)

- Write na API do ML (pausar, editar, mover anúncio) a partir da tela — fase própria, mesma conclusão de `260626-acoes-ml-mover-sgi-pausar-via-api.md`.
- Abrir o módulo ao time de publicação (`role:admin` → `permission:mlb.anunciar`).
- Indicador de saúde por empresa no painel de cards de `/mlb/anuncios`.
- Marcação local de "já tratado" / "ignorar".
- Meus Anúncios absorver o Histórico.

</user_constraints>

## Requisitos da fase (derivados do CONTEXT.md — fase avulsa, sem REQ-IDs de milestone)

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| D-01 | Varredura do acervo inteiro | Confirmado: `/users/{id}/items/search` funciona com token próprio; paginação por `offset` estoura em ~1000-1100 (erro real capturado); `search_type=scan`/`scroll_id` avança corretamente e é o mecanismo recomendado para qualquer conta (ver §2) |
| D-04 | Selo de origem (join `Publicacao`) | Confirmado o tipo de `mlb_code` (`string(20) unique`) compatível com o `id` retornado pela API (`MLBxxxxxxxxxx`); join direto viável |
| D-05/D-06 | Coleta assíncrona diária, zero chamada síncrona | Confirmado volume (§A-03) que torna a coleta "tudo-todo-dia" inviável para a camada cara; recomendação de coleta em 2 camadas de custo (§3) |
| D-07 | Persistência em 2 níveis + retenção ~90d | Dimensionado com dado real: ~500 mil linhas na tabela corrente (estimado a partir da proporção ativos/pausados observada), até ~45 milhões de linhas na série diária em regime — ver §4 |
| D-09 | Triagem por motivo | Mapeado motivo → campo de origem e custo (§ tabela de inventário de campos) |
| D-10 | Nota ECF + health do ML | `health` do ML confirmado sempre `null`; adaptação de `analisarAnuncio()` mapeada campo a campo com trade-off explícito (§6) |
| D-12 | Ordenação por gravidade | Confirmado que todos os motivos exceto "perdendo catálogo" dependem só da camada barata — determinístico no dia 1, como o D-12 pede |
| A-01 | Variações — 1 linha ou N? | Respondido com dado real de 5 contas de portes diferentes (§A-01) |
| A-02 | Catálogo/buy box estável? | Respondido: `price_to_win` funciona, é estável, mas 1 call/item (§A-02) |
| A-03 | Volume real | Medido: 406.932 itens ativos, 74 contas, distribuição por outlier (§A-03) |

---

## A-01 — Variações: uma linha com agregado + JSON, não N linhas nem tabela filha

**Pergunta:** anúncio com variações é uma linha ou N no snapshot?

**Método:** medi a % de itens com variação em 10 contas reais de portes diferentes (5 pequenas/médias + 5 grandes/autopeças) via multiget real.

| Empresa | Itens ativos | % com variação |
|---|---:|---:|
| BOX LISBOA | 472 | **70%** |
| DROSSI INTERIORES | 4.718 | 26% |
| GENUINEAUTOMOTIVE | 54.920 | 16% |
| DINMAP | 1.006 | 10% |
| EDUMAC PARTS | 57.752 | 10% |
| CAMILLO PARTS MATRIZ | 56.413 | 8% |
| LYAMDECOR | 41.476 | 6% |
| MPozenato | 8.317 | 4% |
| CAMILLOPARTSFILIALSCCAMILLO | 66.747 | 2% |
| DESK DESIGN | 9.011 | 0% |

Variação não é caso raro — chega a **70% do acervo** em contas de moda/acessórios. Qualquer desenho que trate variação como exceção quebra em produção.

**Confirmado por chamada real** (item `MLB5429036258`, 32 variações): cada variação carrega `id`, `price`, `available_quantity`, `sold_quantity`, `attribute_combinations` (ex.: COLOR, BAND_WIDTH), `picture_ids` — estoque e venda **são mesmo por variação**, como o CONTEXT.md previu.

**Achado que resolve o trade-off:** o item **também expõe `available_quantity`/`sold_quantity` no nível do item-pai como soma agregada das variações** — confirmado em 3 contas (DROSSI: item com 18 variações, `available_quantity=9000` no pai; DINMAP: item com 9 variações, `available_quantity=30, sold_quantity=69` no pai). O Mercado Livre já faz a agregação por nós.

### Opções avaliadas

| Opção | Prós | Contras |
|---|---|---|
| **N linhas (1 por variação)** | Granularidade máxima | D-09/D-12 operam no nível do anúncio (pausado, sem estoque, foto insuficiente são propriedades do item, não da variação); explode o volume da tabela corrente em até 32× para contas de moda; nenhum requisito da fase pede ação por variação (D-11 é leitura, sem edição de variação) |
| **Tabela filha (`ml_acervo_item_variacoes`)** | Normalizado, útil se um dia a tela precisar drill-down por variação | Over-engineering para o que a fase pede hoje; join extra em toda query de triagem/ordenação; nenhuma tela do escopo atual lista variação isoladamente |
| **1 linha por item + JSON de variações (agregado no topo)** ✅ | O ML já entrega o agregado de graça; ordenação/filtro (D-09/D-12) funcionam direto na linha do item, sem join; o projeto já usa `json` para payload completo em `ml_anuncio_rascunhos.payload` — padrão estabelecido | Se a tela algum dia precisar "qual variação específica está sem estoque", precisa abrir o JSON (aceitável — não é requisito desta fase) |

**Recomendação:** **1 linha por item**, com `available_quantity`/`sold_quantity` = os agregados que a própria API já devolve, `has_variations` (bool) e `variations` (json, payload bruto das variações para quem quiser abrir o detalhe). Mesma filosofia de `ml_anuncio_rascunhos.payload`. Confiança: **HIGH** (dado real, não suposição).

---

## A-02 — Catálogo/buy box: `price_to_win` funciona, é estável, mas custa 1 chamada por item

**Pergunta:** a API expõe de forma estável se o anúncio está ganhando/perdendo a buy box do catálogo?

**Confirmado por chamada real** contra item próprio com token do vendedor (`MLB5435316442`, `catalog_listing=true`):

```
GET /items/{id}/price_to_win?version=v2
```

```json
{
  "item_id": "MLB5435316442",
  "current_price": 28.12,
  "price_to_win": 28.12,
  "status": "winning",
  "consistent": true,
  "visit_share": "maximum",
  "competitors_sharing_first_place": 0,
  "reason": [],
  "catalog_product_id": "MLB39935553",
  "winner": { "item_id": "MLB5435316442", "price": 28.12 },
  "boosts": [
    {"id": "fulfillment", "status": "boosted", "description": "Envios Full"},
    {"id": "free_shipping", "status": "boosted", "description": "Frete grátis"},
    ...
  ]
}
```

- `status` é o campo que interessa à triagem D-09: valores documentados são `winning` / `sharing_first_place` / `losing` / `listed` [CITED: developers.mercadolivre.com.br/en_us/catalog-competition, confirmado o valor `winning` empiricamente].
- `catalog_listing` (bool) e `catalog_product_id` **já vêm de graça no multiget padrão** (`/items?ids=`) — não é preciso nenhuma chamada extra para saber SE o item participa de catálogo. Só para saber SE ele está ganhando/perdendo é que entra o `price_to_win`.
- Alternativa `/products/{catalog_product_id}` (campo `buy_box_winner`) foi testada e **devolveu `null`** para o produto do item de amostra — `price_to_win` é a fonte melhor e mais direta (dá o status relativo ao PRÓPRIO item, não exige comparar `item_id` manualmente).

**O custo:** `price_to_win` é **1 chamada por item, sem lote** — não há parâmetro de multiget documentado nem aceito. Medi `catalog_listing=true` em 11 contas:

| Empresa | Itens ativos | % catalog_listing=true |
|---|---:|---:|
| EDUMAC PARTS | 57.752 | **80%** |
| TRWCAMILLOPARTS | 42.298 | **80%** |
| CAMILLO PARTS MATRIZ | 56.413 | 66% |
| GENUINEAUTOMOTIVE | 54.920 | 64% |
| DINMAP | 1.006 | 6% |
| BOX LISBOA | 472 | 2% |
| CAMILLOPARTSFILIALSCCAMILLO | 66.747 | 0% |
| LYAMDECOR | 41.476 | 0% |
| MPozenato, DROSSI, DESK DESIGN | — | 0% |

Estimativa por amostra: só nas 4 contas grandes com % alto, **~150 mil itens `catalog_listing=true`** já somam. Rodar `price_to_win` para todos, todo dia, é **~150 mil chamadas HTTP extras/dia sem lote** — na faixa de 1-2 horas mesmo com o app operando perto do teto de rate limit (ver §3). Isso não invalida o endpoint (ele funciona, é estável, é exatamente o que o D-09 quer), mas **invalida "rodar para 100% do acervo todo dia" como premissa silenciosa**.

**Recomendação:** usar `price_to_win` como fonte de "perdendo catálogo" no D-09, mas tratá-lo como **camada cara e priorizável** (ver §3 e §4) — não incluir no lote diário-para-tudo junto com status/estoque/tier. Itens ainda não cobertos pela rotação aparecem como "não avaliado" no motivo de catálogo (nunca inventar um status). Confiança: **HIGH** no funcionamento do endpoint (chamada real); **MEDIUM** na % projetada para o acervo total (amostrado, não censo completo).

---

## A-03 — Volume real: 406.932 itens ativos, dominado por 6 contas (78,5%)

**Pergunta:** quantos itens ativos as contas conectadas de fato têm?

**Método:** consulta real via SSH à VPS de produção (`php artisan tinker`), somando `paging.total` de `GET /users/{id}/items/search?status=active` para as **74 empresas com `MlToken.status=active`** hoje. Nenhuma escrita, nenhum token impresso.

```
Total de empresas com token ML ativo: 74
TOTAL DE ITENS ATIVOS (soma de todas as contas): 406.932
```

Distribuição:

| Faixa | Nº de empresas | Itens ativos |
|---|---:|---:|
| 6 maiores (autopeças + 1 decoração) | 6 | 319.608 (**78,5%** do total) |
| Demais 68 empresas | 68 | 87.324 (21,5%) |

- **Empresa mediana: ~400 itens ativos** (posições 37-38 de 74, ordenadas).
- **Média: ~5.500 itens/empresa** — puxada para cima pelas 6 outliers, mais de 13× a mediana.
- **Maior conta isolada:** `CAMILLOPARTSFILIALSCCAMILLO`, 66.747 ativos (70.182 somando todos os status).
- **Menores:** 2 contas de teste com 0 itens.

Isso é **exatamente o padrão "média vs. mediana" que `desempenho-bonificacao.md` já documentou** para bônus (item "Lojão do Bras", outlier de +20.738% distorcendo a carteira). Aqui o efeito não é sobre nota — é sobre **capacidade de coleta**: dimensionar a coleta pela média (5.500 itens/empresa × 74 = a mesma soma, óbvio) esconde que **6 empresas sozinhas exigem desenho de paginação robusto** (offset não serve, ver §2) enquanto as outras 68 cabem folgadamente no paging simples.

**Implicação direta para D-05/D-06/D-07:**
1. A camada barata (multiget, 20 ids/call) cobre 406.932 itens em **~20.347 chamadas** — a ~25 req/s (ver §3), isso é **~14 minutos**. Perfeitamente viável, todo dia, para o acervo inteiro.
2. A camada cara (visitas: 1 call/item; buy box: 1 call/item só para `catalog_listing=true`) em volume pleno seria **centenas de milhares de chamadas/dia** — não cabe num job diário simples sem rotação. Ver §3/§4 para a proposta.
3. Ativos+pausados (a coleta "varre a conta inteira" por D-03) é maior que só ativos — na amostra de `CAMILLO PARTS MATRIZ`, pausados = 13.013 sobre 56.413 ativos (**+23%**). Extrapolando essa proporção, a tabela corrente fica na ordem de **~500 mil linhas**, não 407 mil.

Confiança: **HIGH** — números medidos diretamente contra produção nesta sessão, não estimados.

---

## 1. Inventário de campos da API — o que existe, endpoint exato, custo

Todos os campos abaixo foram **confirmados por chamada real** (não documentação) contra `BOX LISBOA` (company_id=195, token de produção), exceto onde marcado `[CITED]`/`[ASSUMED]`.

| Campo/recurso | Existe? | Endpoint | Multiget? | Limite | Confiança |
|---|---|---|---|---|---|
| `status` / `sub_status` | Sim | `GET /items?ids=` (multiget) | Sim | **20** (erro real: `"only allows 20 elements"`) | VERIFIED |
| `available_quantity` (estoque) | Sim | idem | Sim | 20 | VERIFIED — agregado quando há variações |
| `sold_quantity` (vendas acumuladas do anúncio, vitalício) | Sim | idem | Sim | 20 | VERIFIED — não é "vendas do dia" |
| `price` / `listing_type_id` (tier) | Sim | idem | Sim | 20 | VERIFIED — `gold_special`=Clássico, `gold_pro`=Premium, já mapeado em `mlAnuncioRegras.js` |
| `shipping.logistic_type` / `shipping.free_shipping` / `shipping.mode` | Sim | idem | Sim | 20 | VERIFIED |
| `tags` (array) | Sim | idem | Sim | 20 | VERIFIED |
| `catalog_listing` (bool) | Sim | idem | Sim | 20 | VERIFIED — de graça, sem custo extra |
| `catalog_product_id` | Sim | idem | Sim | 20 | VERIFIED |
| `variations[]` (id, price, available_quantity, sold_quantity, attribute_combinations, picture_ids) | Sim | idem | Sim | 20 | VERIFIED — item real com 32 variações capturado |
| `permalink` / `thumbnail` / `pictures[]` | Sim | idem | Sim | 20 | VERIFIED — `permalink` já vem PRONTO (com slug); não precisa reconstruir como `linkAnuncioMl()` faz para rascunhos internos |
| `attributes[]` (ficha técnica preenchida) | Sim | idem (campo do payload completo) | Sim | 20 | VERIFIED |
| `health` (score de qualidade do próprio ML) | **Campo existe, sempre `null`** | idem | — | — | VERIFIED em 2 itens de contas distintas — API de health está sendo descontinuada |
| `GET /items/{id}/health` (endpoint antigo) | **Não funcional** | — | Não | — | VERIFIED — 404 real: `"Items with buying mode 'buy_it_now' are not allowed"` (efetivamente morto para item comum) |
| `GET /items/{id}/performance` (plural, sucessor anunciado) | 404 | — | — | — | VERIFIED — erro real |
| `GET /item/{id}/performance` (singular) | Existe mas recusou o item testado | — | Não | — | VERIFIED — 400 real: `"Entity not calculated: Product items are not supported"` — ver Open Questions |
| Visitas por item | Sim, **mas 1 item/call** | `GET /items/{id}/visits?date_from&date_to` | **Não** (ver abaixo) | 1 | VERIFIED |
| `GET /visits/items?ids=` (multiget de visitas, prometido pela doc) | Existe mas **rejeitou lote** | — | **Não, na prática** | 1 | VERIFIED — erro real: `"maximum amount of items to query is 1"`, contradizendo a doc pública que promete até 20 |
| Catálogo/buy box | Sim, estável | `GET /items/{id}/price_to_win[?version=v2]` | Não | 1 | VERIFIED — ver §A-02 |

**Achado que muda a estimativa de custo:** a documentação pública (`developers.mercadolivre.com.br/pt_br/recurso-visits`) e o WebSearch inicial indicavam multiget de até 20 ids para visitas. **A chamada real recusou com limite 1.** Isso é exatamente o tipo de deriva que a doc não pega e só a chamada real revela — tratado como `[VERIFIED]` por ter sido confirmado empiricamente, sobrepondo o que a doc/WebSearch sugeriam.

## 2. Varredura do acervo (D-01) — `scroll_id`, não `offset`

Confirmado por chamada real contra `CAMILLO PARTS MATRIZ` (maior conta disponível para teste, 70.182 itens totais):

```
GET /users/{id}/items/search                    (offset paging, default)
GET /users/{id}/items/search?status=active       (filtro por status, funciona)
GET /users/{id}/items/search?search_type=scan    (scroll — recomendado)
```

- **`offset` estoura entre 1010 e 1100** (`offset=1001` OK, `offset=1010` OK, `offset=1100` → 400 `"Invalid limit and offset values"`; `offset≥10000` → erro explícito "must be less than 10000"). Na prática, **paging por offset só serve para contas com <~1000 itens** — a maioria das contas médias/pequenas nesta base, mas nenhuma das 6 grandes.
- **`search_type=scan` (scroll) avança corretamente**: testado por 3 páginas consecutivas de 50 ids cada — as 150 ids retornadas foram **todas distintas** (zero repetição), e o `scroll_id` mudou a cada chamada, confirmando que o cursor de fato progride.
- **TTL do `scroll_id`: 5 minutos** [CITED: developers.mercadolivre.com.br/en_us/items-and-searches, via WebSearch — não verificado em chamada real por não ter sido necessário esperar 5min]. Para uma conta de 66.747 itens em páginas de 50, são **~1.335 chamadas sequenciais** — se cada round-trip levar 200-400ms, a enumeração pura das IDs (sem nenhum processamento no meio) leva **4,5-9 minutos**, o que **pode estourar os 5 minutos do `scroll_id` nas contas maiores**. Recomendação: a fase de enumeração de IDs deve rodar em loop apertado, sem delay artificial entre páginas de scroll (o delay/throttle deve ficar reservado para a fase de detalhe, não para a enumeração); se ainda assim estourar, reiniciar o scroll do zero é seguro (idempotente, sem duplicidade — o próximo scroll simplesmente recomeça do topo).
- **`status=active` filtra corretamente no `paging.total`** — confirma que a coleta pode enumerar só ativos+pausados sem baixar encerrados, se a decisão for essa (D-03 permite variar).

**Recomendação:** usar sempre `search_type=scan`/`scroll_id`, mesmo para contas pequenas — evita a complexidade de decidir "essa conta cabe no offset, essa não" e evita o penhasco do erro 400 em ~1000 itens.

## 3. Rate limit e throttling

- **Nenhum header de rate limit foi observado** nas respostas reais (`X-RateLimit-*` ausente) — o app não pode se autorregular por header, só por tentativa/erro.
- O código já tem retry de 429 embutido em `MercadoLivreService::comRetry429()` — honra `Retry-After`, senão backoff exponencial (1s, 2s, 4s…) até `MAX_429_WAIT=8s`, `MAX_429_RETRIES=3`. Isso cobre qualquer chamada feita via `$ml->get()`/`$ml->post()` automaticamente — **reusar, não reimplementar**.
- Comentário existente no código (`SyncMlData.php`): "ML tem rate limit de 1500 req/min" — usado para o delay de 2s entre `SyncMlCompanyJob` no fan-out do `ml:sync`. [ASSUMED — comentário do código, não confirmado nesta sessão via doc oficial acessível].
- WebSearch (não verificado em doc oficial, WebFetch bloqueado por 403 no site da ML nesta sessão) sugere que o limite documentado é **"1500 requisições/minuto **por vendedor**"**, não por app inteiro [CITED, MEDIUM confiança — não confirmado via doc oficial acessível]. Se correto, é uma boa notícia: como cada empresa usa seu próprio `access_token`, o throttle pode ser mais folgado por empresa do que o padrão atual (2s entre empresas) sugere — mas **não mudar o padrão de delay já em produção sem confirmação**, dado que a fonte é MEDIUM.
- **Padrão recomendado para a coleta desta fase** (replica `publicarLote`/`SyncMlData`):
  - 1 job por empresa, delay escalonado entre empresas (padrão já em produção: `~2s × posição`).
  - Dentro do job: lotes de 20 ids no multiget, sem delay artificial entre lotes da camada barata (o app já tem margem — 20.347 chamadas em ~14min a 25/s).
  - Camada cara (visitas, `price_to_win`): pacing mais generoso (ex.: lote pequeno + sleep curto) e, dado o volume, **rotação/priorização** — não tentar cobrir 100% do acervo em toda execução diária.

## 4. Arquitetura de persistência (D-07) — dimensionamento com dado real

### Tabela corrente (linha por anúncio, upsert)

- Universo alvo (ativos + pausados, por D-03 "a coleta pode varrer a conta inteira"): estimado em **~500 mil linhas**, extrapolando a proporção pausados/ativos observada em `CAMILLO PARTS MATRIZ` (+23%) sobre os 406.932 ativos medidos.
- Índices necessários: `(company_id, ml_item_id)` único (chave de upsert); `(company_id, status)` para o filtro "só ativos" (D-03); `(company_id, motivo_severidade)` ou coluna derivada para D-12 se a ordenação por gravidade não for computável só por `ORDER BY status, nota_ecf` direto.
- **Pegadinha de MariaDB documentada em `desempenho-bonificacao.md` item 6, aplica-se aqui:** nome de índice composto em tabela de nome longo (ex.: `ml_acervo_item_metricas_diarias`) facilmente estoura 64 caracteres — nomear os índices manualmente na migration, não deixar o Laravel gerar o nome automático.

### Série diária (evolução — visitas, vendas, saúde)

- Se **todo** item coletado (~500 mil) ganhar 1 linha/dia, com retenção de 90 dias, o regime estacionário chega a **~45 milhões de linhas**. Isso é grande, mas gerenciável em MariaDB com PK composta `(company_id, ml_item_id, date)` — o ponto que **não pode ser esquecido é o índice e o comando de retenção**, exatamente como D-07 já avisa ("requisito, não polimento").
- **Redução recomendada para o planner considerar:** gravar linha na série diária só quando algum campo relevante (estoque, vendas, nota) **mudou** desde o dia anterior — corta drasticamente o volume em contas com catálogo estático (comum em autopeças, que já são as maiores). Isso é uma otimização, não uma decisão travada — o CONTEXT.md não fecha esse ponto.
- Molde do comando de retenção: `app/Console/Commands/SyncVendasLogsCleanup.php` (`mlb:sync-vendas-logs-cleanup`) — dois `--option`s (`--stale-hours`, `--keep-days`), populado com `where(...)->delete()` direto, sem soft delete. Replicar a forma para o comando novo (nome sugerido: `mlb:sync-acervo-cleanup`).

## 5. Nota ECF sobre item publicado (D-10) — trade-off completo

`analisarAnuncio()` (`mlAnuncioRegras.js:174`) foi escrita para o **estado do formulário do wizard**, não para o item já publicado. Mapeei campo a campo o que ela pede contra o que o payload do ML (multiget) entrega de graça:

| Sinal de `calcularScore()` (peso) | Precisa de quê | Vem de graça no multiget (camada barata)? |
|---|---|---|
| Título ≥20 chars (12) | `title` | **Sim** |
| Categoria escolhida (12) | `category_id` | **Sim** |
| Ficha obrigatória completa (20) | `attributes[]` do item **+ definição de obrigatórios da categoria** | `attributes[]` sim; definição de obrigatórios exige `GET /categories/{id}/attributes` — mas é **por categoria, não por item** (cacheável, poucas categorias por conta vs. milhares de itens — mesmo padrão que `MlCatalogoMetaService`/`nomeCategoria()` já usa) |
| Ficha opcional ≥60% (14) | idem | idem — cacheável por categoria |
| Ao menos 1 foto (16) | `pictures[]` / `variations[].picture_ids` | **Sim** |
| Descrição ≥120 chars (14) | **texto da descrição** | **NÃO** — `GET /items/{id}/description` é endpoint separado, **1 call/item, sem lote** (confirmado: o campo `descriptions` no item só traz metadado/lista, não o texto) |
| Dimensões completas (8) | `PACKAGE_HEIGHT/LENGTH/WEIGHT/WIDTH` como atributos | **Sim** — confirmado no payload real, vêm como `attributes[]` comuns |
| Preço definido (4) | `price` | **Sim** |

**6 dos 8 sinais (66 dos 100 pontos) são computáveis na camada barata + cache por categoria — sem chamada extra por item.** O único sinal genuinamente caro é **descrição** (14 pontos), que exigiria 1 chamada adicional por item, sem lote — o mesmo problema de escala de visitas/buy-box.

**Onde calcular:** **PHP, no job de coleta** — não no JS/render. D-12 exige ordenação por gravidade no banco (`ORDER BY`), o que só funciona se a nota for uma coluna persistida; calcular no navegador a cada render não permite paginar/ordenar no SQL. Isso não é uma preferência estética, é uma consequência direta de D-12.

**O risco que `desempenho-bonificacao.md` já documentou** (régua reimplementada duas vezes divergindo — `nps_medio` ≠ `pontos_componentes.nps`) **se aplica igualzinho aqui**: existirão DUAS implementações do "score de saúde" — a JS do wizard (formulário, tempo real) e a PHP nova (item publicado, batch). Mitigação recomendada:
1. Os **pesos numéricos** (12/12/20/14/16/14/8/4) devem viver em UM lugar citável nos dois arquivos — comentário cruzado no mínimo, idealmente um teste que os compara.
2. **Não incluir o sinal de descrição por padrão** na nota pós-publicação (é o único que custa 1 call/item extra) — isso não é uma continuação silenciosa da fórmula do wizard, é uma decisão explícita que falta tomar no planning (ver Open Questions). Se incluído, os 14 pontos entram; se não, redistribuir ou deixar a nota em base 86 documentada como tal.
3. Escrever um teste de regressão que roda o MESMO fixture de item pelos dois scorers (JS via `node --test`, já existe harness; PHP via PHPUnit) e assere que os dois concordam — operacionaliza a lição "a nota tem que fechar com a própria conta".

**Sobre a "saúde do ML" (metade esquerda do D-10):** como `health` está sempre `null` e `/item/{id}/performance` devolveu erro nos dois testes desta pesquisa, a fase **não pode prometer um número vindo do ML** hoje. Ver Open Questions — recomendo expor esse lado como "não disponível" com uma explicação, não simular um número.

## 6. Learnings obrigatórios — reflexo direto na fase

- **`.planning/learnings/desempenho-bonificacao.md`, item 1** (mediana vs. média, régua não se recalibra sozinha): aplicado ao dimensionamento de coleta em §A-03 — a distribuição por outlier das 74 contas é o motivo pelo qual "coletar tudo, todo dia, para tudo" não escala, e por que a decisão de tiering precisa ser explícita, não silenciosa.
- **item 6** (armadilhas MariaDB que o SQLite dos testes não pega — nome de índice >64 chars, `nullOnDelete` exige `nullable()`, dropar índice usado por FK): aplicado em §4 às migrations novas.
- **`.planning/todos/pending/260626-acoes-ml-mover-sgi-pausar-via-api.md`:** confirma que a conclusão "write na API do ML é fase separada, com confirmação dupla + `activity_log` + undo" já é precedente estabelecido — D-11 desta fase não está inventando uma trava nova, está seguindo uma já tomada. Quando (se) a fase de write for aberta no futuro para "Meus Anúncios" (pausar/mover direto da tela de triagem), ela deve seguir o mesmo roteiro de salvaguardas descrito nesse todo.

## 7. Mapeamento do código existente — confirmado contra o código real (2026-08-10)

Todos os pontos abaixo foram lidos e conferidos nesta sessão — nenhum número de linha do CONTEXT.md está desatualizado:

| Referência do CONTEXT.md | Confirmado? | Nota |
|---|---|---|
| `routes/mlb_anuncios.php` — grupo `role:admin`, prefixo `mlb/anuncios` | ✅ | Rota nova entra no mesmo grupo, padrão `Route::get('/meus/{company}', ...)->name('meus')` |
| `MlbAnuncioController::historico()` linha 203 | ✅ exato | Molde real: `loadMissing('mlToken')` + `abort_unless(...404)` + busca + `LengthAwarePaginator` + `Inertia::render` |
| `ModoAnuncioTabs.jsx` — array `MODOS` linhas 15-20 | ✅ | 3 itens hoje (`individual`, `massa`, `historico`); 4ª aba entra no topo do array, reordenando |
| `AnunciosHistorico.jsx` | ✅ | Usa `linkAnuncioMl()` de `anuncioHistoricoUtils.js` para reconstruir permalink — **para "Meus Anúncios" usar o `permalink` que a API já devolve pronto**, não reconstruir |
| `AnunciarML.jsx:2761` — "Saúde do anúncio" (fica) | ✅ exato | `{categoryId && (...)}`, bloco completo linhas 2761-2810 |
| `AnunciarML.jsx:2831` — "Rascunhos recentes" (sai) | ✅ exato | `{rascunhos.length > 0 && (...)}`, começa exatamente na linha 2831 |
| `mlAnuncioRegras.js` — `analisarAnuncio()` linha 174, `calcularScore()` linha 263 | ✅ exato | Ver §6 para adaptação campo a campo |
| `MlAnuncioRascunho.php` — `STATUS_PUBLICADO`, `ml_item_id`/`ml_item_id_classico`/`ml_item_id_premium`, `published_at` | ✅ | Confirmado fillable e casts |
| `MlPublicacaoService.php:80` (`/items/validate`) e `:112` (`POST /items`) | ✅ exato | Único ponto de escrita do módulo hoje — nada muda aqui nesta fase |
| `MercadoLivreService.php` | ✅ | `get()`/`post()`/`put()` com refresh automático de token + `comRetry429()` já cobrem tudo que a coleta nova precisa; **reusar, não reimplementar** cliente HTTP |
| `routes/console.php` — `ml:refresh-tokens` 08:00, `ml:sync` 11:05, `mlb:sync-vendas-logs-cleanup` 03:20 | ✅ | Também existe `adman:sync-margem` 11:20 (15min após `ml:sync`) — o novo `Schedule::command('mlb:sync-acervo')` cabe entre 11:05 e 11:20, ou depois, a critério do planner |
| `Publicacao.php` — scope `considerado()` linha 171 | ✅ exato | Regra confirmada: tela do D-01 **LISTA**, não conta — não aplicar `considerado()` na listagem |
| `MlAnuncioRascunho.php` | ✅ | (repetido acima) |

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|---|---|---|---|
| Cliente HTTP com refresh de token, retry 429, tratamento de 401 | Novo client HTTP para o ML | `MercadoLivreService::get()`/`post()` | Já cobre expiração, lock de concorrência, backoff — reimplementar duplica bugs já corrigidos |
| Paginação com scroll | Lógica de paginação do zero | Padrão `search_type=scan` + `scroll_id` já validado nesta pesquisa | Cursor tem TTL de 5min e semântica própria — errar aqui perde itens silenciosamente |
| Cálculo de saúde do anúncio | Nova fórmula de score | Pesos de `calcularScore()` (`mlAnuncioRegras.js:263`) portados para PHP | D-10 trava reuso dos pesos; nova fórmula = duas réguas divergentes (o próprio risco que `desempenho-bonificacao.md` documenta) |
| Retry/backoff de 429 | Sleep manual no job de coleta | `comRetry429()` (já embutido em `get()`/`post()`) | Já honra `Retry-After` e tem teto — evita travar o worker |
| Comando de retenção agendado | Lógica de cleanup do zero | Molde `SyncVendasLogsCleanup` (`mlb:sync-vendas-logs-cleanup`) | Padrão de `--stale-hours`/`--keep-days` já testado em produção |
| Link do anúncio no ML | Reconstruir permalink via `linkAnuncioMl()` | Usar o `permalink` que o próprio `GET /items` devolve | Para "Meus Anúncios" (itens reais da API), o permalink real já vem com slug — reconstruir é desnecessário e mais frágil |

**Key insight:** quase todo o "trabalho difícil" desta fase (autenticação, retry, paginação por scroll, fórmula de saúde) já tem molde no código do próprio módulo ou em serviços vizinhos. O risco real não é reinventar — é subestimar o volume (§A-03) e tratar a coleta como um problema homogêneo quando ele é bimodal (6 contas gigantes + 68 contas pequenas).

## Common Pitfalls

### Pitfall 1: job de coleta por empresa estoura o `retry_after` da fila e duplica execução
**O que dá errado:** se o job de uma conta grande (66 mil+ itens, camada cara incluída) levar mais que o `retry_after` configurado, o worker da fila pode pegar o MESMO job de novo antes do primeiro terminar — duplicando chamadas à API e updates concorrentes.
**Por que acontece:** já aconteceu neste projeto — `project_polos_sync_job_retry_after_bug.md` documenta exatamente esse padrão (`retry_after=90s << ~17min do job`). Confirmado nesta pesquisa: a config `database` usa `retry_after` default de **90s**; produção já corrigiu isso para a fila `redis` com `REDIS_QUEUE_RETRY_AFTER=2000` (33 min) — mas **33 minutos ainda pode não bastar** para a camada cara de uma conta de 66 mil itens com alto % `catalog_listing`.
**Como evitar:** (a) separar a camada barata (rápida, cabe em minutos) da camada cara (lenta) em jobs DIFERENTES, não um job monolítico; (b) considerar `ShouldBeUnique` no job (padrão já usado em `PublicarAnuncioMlJob`); (c) se a camada cara for paginada em jobs menores (por lote de N itens em vez de 1 job/empresa-inteira), o tempo por job fica previsível e curto.
**Sinais de alerta:** logs mostrando o mesmo `company_id` sendo processado duas vezes na mesma janela; itens com `collected_at` avançando de forma inconsistente.

### Pitfall 2: tratar `offset` como paginação universal
**O que dá errado:** código que pagina por `offset` sem trocar para `scroll_id` funciona nos testes (contas pequenas) e quebra em produção nas 6 contas grandes, silenciosamente (a partir de ~1000 itens, a API passa a devolver 400).
**Por que acontece:** a maioria das contas de teste/desenvolvimento tem poucos itens; o erro só aparece com dado de produção real — exatamente o motivo desta pesquisa ter sido instruída a medir contra produção.
**Como evitar:** usar `search_type=scan` desde o primeiro dia, para todas as contas, independente do tamanho.

### Pitfall 3: assumir que "health" do ML está disponível
**O que dá errado:** desenhar a UI da "saúde dupla" (D-10) esperando um número do lado do ML e descobrir em produção que o campo é sempre `null`.
**Por que acontece:** a doc pública ainda referencia `/health` e o conceito de "quality score"; a API real já descontinuou o campo no payload padrão e o endpoint substituto (`/item/{id}/performance`) não respondeu com dado utilizável nos 2 testes desta pesquisa.
**Como evitar:** tratar o lado "ML" do D-10 como indisponível por padrão nesta fase — ver Open Questions para o texto/fallback recomendado.

### Pitfall 4: nome de índice/tabela estourando 64 caracteres no MariaDB
**O que dá errado:** migration cria a tabela, mas falha ao nomear o índice composto automaticamente — fica com a tabela criada e a migration como `Pending`, sem o índice.
**Por que acontece:** documentado em `desempenho-bonificacao.md` item 6; o SQLite dos testes não reproduz esse limite.
**Como evitar:** nomear os índices manualmente nas migrations novas (`ml_acervo_itens`, `ml_acervo_item_metricas_diarias` — nomes já são longos, qualquer índice composto com esses prefixos passa fácil de 64 chars).

## Code Examples

### Multiget de 20 itens com os campos da camada barata (confirmado por chamada real)
```php
// Fonte: chamada real contra api.mercadolibre.com nesta pesquisa (2026-08-10)
$r = $ml->get($company, '/items', [
    'ids' => implode(',', $loteDe20Ids),
    'attributes' => 'id,status,sub_status,available_quantity,sold_quantity,'
        . 'shipping,listing_type_id,tags,catalog_listing,catalog_product_id,'
        . 'variations,title,price,permalink,thumbnail,category_id,attributes',
]);

foreach ($r as $wrapped) {
    // formato real confirmado: [{code:200, body:{...item...}}, ...]
    if (($wrapped['code'] ?? null) !== 200) {
        // item individual falhou dentro do lote — não aborta o lote inteiro
        continue;
    }
    $item = $wrapped['body'];
    // upsert em ml_acervo_itens...
}
```

### Enumeração do acervo via scroll (não offset)
```php
// Fonte: padrão validado nesta pesquisa contra CAMILLO PARTS MATRIZ (70.182 itens)
$scrollId = null;
do {
    $params = ['search_type' => 'scan', 'limit' => 50];
    if ($scrollId) {
        $params['scroll_id'] = $scrollId;
    }
    $r = $ml->get($company, "/users/{$mlUserId}/items/search", $params);
    $ids = $r['results'] ?? [];
    $scrollId = $r['scroll_id'] ?? null;
    // processa $ids...
} while (!empty($ids)); // scroll termina quando results vem vazio
```

### Buy box para itens `catalog_listing=true` (camada cara, priorizada)
```php
// Fonte: chamada real, item MLB5435316442 (2026-08-10)
$r = $ml->get($company, "/items/{$itemId}/price_to_win", ['version' => 'v2']);
$buyBoxStatus = $r['status'] ?? null; // 'winning' | 'sharing_first_place' | 'losing' | 'listed'
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| `GET /items/{id}/health` para score de qualidade | `GET /item/{id}/performance` (singular, anunciado) | Depreciação em curso [CITED, não datada com precisão] | Nesta pesquisa, nenhum dos dois devolveu dado utilizável para os itens testados — ver Open Questions |
| `GET /visits/items?ids=` multiget (documentado até 20 ids) | Recusado na prática, só aceita 1 id | Confirmado empiricamente nesta sessão, não documentado publicamente com essa restrição | Muda o custo de coleta de visitas de "barato em lote" para "caro por item" |

**Deprecado/desatualizado:**
- `health` no payload padrão de `/items` — sempre `null`, tratar como campo morto.
- Reconstrução manual de permalink (`linkAnuncioMl()`) — desnecessária para itens vindos direto da API (o `permalink` já vem pronto).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Rate limit real é "1500 req/min por vendedor" (não por app) | §3 | Se for por app (como o comentário do código sugere), o throttle entre empresas precisa ser mais conservador do que o dado sugere — não mudar o delay de 2s existente sem confirmar |
| A2 | TTL do `scroll_id` é 5 minutos | §2 | Se for diferente (maior ou menor), o desenho de "enumerar sem delay" pode ser desnecessariamente cauteloso ou insuficientemente cauteloso — não foi testado esperando o TTL real expirar |
| A3 | `/item/{id}/performance` é de fato o sucessor do `/health` mas só funciona para itens "não-Product" | §1, Open Questions | Se o endpoint simplesmente não estiver disponível para a conta/app deste projeto (permissão, escopo de token), a "saúde do ML" do D-10 fica permanentemente vazia, não é um problema temporário de um item de teste |
| A4 | A proporção pausados/ativos observada em 1 conta (23%) generaliza para as outras 73 | §4, §A-03 | Se a proporção variar muito por segmento, a estimativa de ~500 mil linhas na tabela corrente pode estar sub ou superestimada |
| A5 | % `catalog_listing=true` medida em amostras de 20-50 itens por conta representa a conta inteira | §A-02 | Amostra pequena por conta (não censo); a % real pode variar por categoria dentro da mesma conta |

## Open Questions

1. **O lado "ML" do D-10 (health/quality externo) não tem dado disponível hoje — o que exibir?**
   - O que sabemos: `health` no payload é sempre `null`; `/item/{id}/performance` devolveu erro nos 2 testes reais desta pesquisa (`"Product items are not supported"` para um item com `user_product_id`, e `/items/{id}/performance` plural deu 404 simples).
   - O que está incerto: se `/item/{id}/performance` funciona para itens do modelo "clássico" (sem `user_product_id`) — não testei essa variação por falta de item de amostra nesse formato nas contas usadas. Também não testei se o app tem o escopo/permissão necessário para esse endpoint (pode ser um endpoint que exige aprovação de acesso separada da ML).
   - Recomendação: o planner deve decidir entre (a) tentar `/item/{id}/performance` em um item clássico real antes de descartar de vez, ou (b) assumir que a "saúde do ML" fica ausente nesta fase e desenhar a UI para esse caso desde já (ex.: mostrar só a nota ECF, com nota explicando que o ML não expõe mais um score comparável) — **não simular ou aproximar um número**.

2. **A nota ECF pós-publicação inclui o sinal de descrição (14 pontos) ou não?**
   - O que sabemos: incluir custa 1 chamada extra por item, sem lote, na mesma faixa de custo de visitas/buy-box.
   - O que está incerto: se o ganho de sinal vale o custo de coleta, dado que a maioria dos outros 7 sinais (86 pontos) já é gratuita.
   - Recomendação: **decisão explícita do usuário no planning**, não herdar silenciosamente a fórmula de 100 pontos do wizard — D-10 exige isso textualmente.

3. **Qual o corte exato para "camada cara" ser coberta em rotação vs. diariamente?**
   - O que sabemos: full-coverage diário de visitas (407k) + buy-box (~150-180k) para todo o acervo está na faixa de horas, não minutos.
   - O que está incerto: o corte exato (por tamanho de conta? por % do acervo coberto por execução? por dias de rotação?) é uma decisão de produto/operação que a pesquisa não deve travar sozinha — dado que "Claude's Discretion" do CONTEXT.md já delega throttle/paginação ao planner.
   - Recomendação: o planner apresenta 1-2 opções concretas (ex.: "cobertura completa a cada 3 dias" vs. "top N itens por prioridade todo dia") com os números desta pesquisa, para decisão explícita — não assumir.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|---|---|---|---|---|
| API Mercado Livre (leitura, token OAuth por empresa) | Toda a fase | ✓ (confirmado nesta pesquisa contra produção) | — | — |
| `MlToken` ativo por empresa | Coleta por empresa | ✓ (74 empresas em produção hoje; 0 no banco local) | — | Empresas sem token simplesmente não entram na coleta (mesmo padrão de `ml:sync`) |
| Fila (queue worker) | Job de coleta assíncrono | ✓ (produção usa `redis`, `REDIS_QUEUE_RETRY_AFTER=2000`) | — | — |
| Recharts (série temporal) | UI da evolução (D-07b) | ✓ (já no `package.json`) | `^3.8.1` | — |

**Sem dependências faltando com bloqueio.**

## Validation Architecture

### Test Framework

| Property | Value |
|---|---|
| Framework backend | PHPUnit 11.x, `RefreshDatabase` (SQLite in-memory) + `Http::fake()` — molde: `tests/Feature/Phase86/HistoricoAnunciosTest.php` |
| Framework frontend (lógica pura) | `node --test` — molde: `tests/js/mlAnuncioRegras.test.js` |
| Config file | `phpunit.xml` (existente); nenhum config novo necessário |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=Phase134` (convenção `@group phase134` + diretório `tests/Feature/Phase134/`) |
| Full suite command | `C:\xampp\php\php.exe artisan test` — **atenção:** memória do projeto (`project_ppa_polos_e_problema_meta.md`) registra que a suíte sem filtro pode não concluir por timeout de 300s em `MercadoLivreAdsService`; preferir `--filter` durante o desenvolvimento e reservar a suíte completa para o gate final |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|---|---|---|---|---|
| D-01 | Tela lista itens que NÃO vieram deste módulo (acervo inteiro) | Feature | `php artisan test --filter=test_lista_itens_fora_do_modulo` | ❌ Wave 0 |
| D-04 | Selo de origem — 3 casos (ECF, time, legado) | Feature | `php artisan test --filter=test_selo_origem` | ❌ Wave 0 |
| D-05 | Zero chamada HTTP síncrona no request de leitura | Feature | `Http::fake()->assertNothingSent()` dentro do teste da rota GET | ❌ Wave 0 |
| D-08 | Snapshot velho → selo de defasagem, nunca tela vazia | Feature | `php artisan test --filter=test_degradacao_graciosa` | ❌ Wave 0 |
| D-09 | Triagem agrupa por motivo e filtra ao clicar | Feature | `php artisan test --filter=test_triagem_por_motivo` | ❌ Wave 0 |
| D-12 | Ordenação por gravidade determinística sem visitas | Unit | `php artisan test --filter=test_ordenacao_gravidade` | ❌ Wave 0 |
| D-14 | Sub-abas Publicados/Rascunhos + `publicarLote` migrado funciona | Feature | reusa/estende testes de `BULK-01` existentes | parcial — molde existe |
| D-16 | "Saúde do anúncio" permanece no wizard após remoção do bloco de Rascunhos | Estrutura (JS) | `node --test tests/js/estrutura-anunciar-ml.test.js` | ❌ Wave 0 — molde: `tests/js/estrutura-grade-glide.test.js` |
| D-10 (guarda de regressão) | Nota ECF do PHP bate com a mesma entrada no JS (`calcularScore`) | Unit (fixture compartilhado) | `node --test` + `php artisan test`, mesmo fixture JSON | ❌ Wave 0 |
| A-01 | Item com variação grava agregado corretamente | Unit | teste do job/serviço de upsert com fixture de item com `variations[]` | ❌ Wave 0 |

### Sampling Rate

- **Por commit de task:** `php artisan test --filter=Phase134` + `node --test tests/js/*anuncio*`
- **Por merge de wave:** suíte completa relevante ao módulo MLB (`--filter=Mlb` ou grupo equivalente)
- **Gate de fase:** suíte completa verde antes de `/gsd:verify-work`, respeitando a ressalva de timeout acima

### Wave 0 Gaps

- [ ] `tests/Feature/Phase134/MeusAnunciosTest.php` — cobre D-01, D-04, D-05, D-08, D-09
- [ ] `tests/Unit/Phase134/OrdenacaoGravidadeTest.php` — cobre D-12
- [ ] `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` — cobre a guarda de regressão do D-10 (comparação PHP × JS)
- [ ] `tests/js/estrutura-anunciar-ml.test.js` — cobre D-16 (bloco Saúde presente, bloco Rascunhos ausente do wizard)
- [ ] Fixtures de resposta real da API do ML (multiget, scroll, `price_to_win`) capturadas nesta pesquisa — usar como corpo de `Http::fake()` em vez de inventar shape

## Security Domain

`security_enforcement` ausente do `.planning/config.json` → tratado como habilitado.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---|---|---|
| V2 Authentication | Não (reusa sessão existente) | — |
| V3 Session Management | Não | — |
| V4 Access Control | **Sim** | Middleware `role:admin` já existente em `routes/mlb_anuncios.php`; nova rota entra no MESMO grupo — nenhuma superfície de acesso nova |
| V5 Input Validation | Sim | Rota de "Atualizar agora" recebe `company` via route model binding (não input livre); segue o padrão `abort_unless($company->mlToken !== null, 404, ...)` já usado em `wizard()`/`massa()`/`historico()` |
| V6 Cryptography | Não (nenhum segredo novo) | Token ML continua em `MlToken`, sem mudança de armazenamento nesta fase |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---|---|---|
| Vazamento de dado de outra empresa na listagem/triagem | Information Disclosure | Escopo por `company_id` em toda query, igual ao padrão já estabelecido em `historico()`/`massa()` — nunca confiar em filtro só no frontend |
| Job de coleta disparado para empresa arbitrária via "Atualizar agora" | Elevation of Privilege | Reusar o padrão de double-check já usado em `publicarLote` (T-80-02): validar que o `company_id` do request corresponde à empresa autorizada antes de enfileirar |
| Log de token de acesso ML | Information Disclosure | Seguir o padrão já documentado em `MercadoLivreService::fetchItemStatus()` — "NUNCA loga access_token, apenas company_id e mlb_id" |

## Sources

### Primary (HIGH confidence — chamadas reais contra a API em produção, 2026-08-10)
- `GET /users/{id}/items/search` (offset, status filter, scroll) — CAMILLO PARTS MATRIZ e outras 9 contas
- `GET /items?ids=` multiget (com e sem `attributes=`) — BOX LISBOA, CAMILLO PARTS MATRIZ, +8 contas para % variação/catálogo
- `GET /items/{id}/price_to_win` (v1 e v2) — MLB5435316442
- `GET /items/{id}/visits` e `GET /visits/items?ids=` — MLB3767118881
- `GET /items/{id}/health`, `GET /items/{id}/performance`, `GET /item/{id}/performance` — MLB3767118881, MLB5435316442
- `GET /products/{catalog_product_id}` — MLB39935553
- Banco de produção via `php artisan tinker` (SSH): `MlToken::where('status','active')` — 74 registros

### Secondary (MEDIUM confidence)
- [developers.mercadolivre.com.br/en_us/catalog-competition](https://developers.mercadolivre.com.br/en_us/catalog-competition) — via WebSearch (WebFetch bloqueado 403), valores de `status` cruzados com chamada real
- [developers.mercadolivre.com.br/pt_br/qualidade-das-publicacoes](https://developers.mercadolivre.com.br/pt_br/qualidade-das-publicacoes) — via WebSearch, aponta descontinuação de `/health`
- [developers.mercadolivre.com.br/pt_br/recurso-visits](https://developers.mercadolivre.com.br/pt_br/recurso-visits) — via WebSearch, **contradito** pela chamada real (doc promete multiget 20, API real aceita 1)
- [developers.mercadolivre.com.br/en_us/items-and-searches](https://developers.mercadolivre.com.br/en_us/items-and-searches) — via WebSearch, TTL de 5min do `scroll_id`

### Tertiary (LOW confidence)
- Rate limit "1500 req/min por vendedor" — WebSearch único, não verificado em doc oficial acessível nesta sessão (WebFetch bloqueado)

### Código do projeto (lido e confirmado nesta sessão)
- `routes/mlb_anuncios.php`, `app/Http/Controllers/MlbAnuncioController.php`, `resources/js/Pages/Mlb/{AnunciarML,ModoAnuncioTabs,AnunciosHistorico}.jsx`, `resources/js/lib/mlAnuncioRegras.js`, `resources/js/Pages/Mlb/anuncioHistoricoUtils.js`, `app/Services/{MercadoLivreService,Mlb/Publicacao/MlPublicacaoService}.php`, `app/Models/{MlAnuncioRascunho,Publicacao,MlbEmpresa,Company}.php`, `routes/console.php`, `app/Console/Commands/{SyncMlData,SyncVendasLogsCleanup}.php`, `database/migrations/{2026_07_10_120000_create_ml_anuncio_rascunhos_table,2026_05_22_100001_create_mlb_sync_vendas_logs_table}.php`, `config/queue.php`, `tests/Feature/Phase86/HistoricoAnunciosTest.php`
- `.planning/learnings/desempenho-bonificacao.md`, `.planning/todos/pending/260626-acoes-ml-mover-sgi-pausar-via-api.md`

## Metadata

**Confidence breakdown:**
- A-01 (variações): HIGH — medido em 10 contas reais, mecanismo de agregação confirmado no payload
- A-02 (catálogo/buy box): HIGH no funcionamento do endpoint; MEDIUM na % projetada (amostra, não censo)
- A-03 (volume): HIGH — soma real de `paging.total` das 74 contas de produção
- Inventário de campos (§1): HIGH — quase tudo confirmado por chamada real
- Rate limit exato (§3): LOW/MEDIUM — doc não acessível via WebFetch nesta sessão, só WebSearch
- Nota ECF / D-10 (§6): HIGH no mapeamento de campos disponíveis; a decisão de incluir descrição fica em Open Questions (não é fato a verificar, é escolha)

**Research date:** 2026-08-10
**Valid until:** ~30 dias para o inventário de campos/comportamento da API (histórico do projeto mostra a ML mudando endpoints sem aviso — `/health`→`/performance` é exemplo disso mesmo). O volume de itens (§A-03) é um retrato do dia da pesquisa — vai crescer; revalidar se a fase for replanejada depois de um hiato longo.
