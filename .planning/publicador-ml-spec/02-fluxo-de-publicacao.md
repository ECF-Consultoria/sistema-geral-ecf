# 02 — Fluxo de publicação (B + C)

## 1. Sequência

O fluxo é apresentado como um **wizard**, mas o rascunho é governado por um **grafo de dependências**. O usuário pode voltar a qualquer etapa. Uma alteração invalida apenas o que depende dela (seção 3).

| # | Etapa | Corresponde no Seller Center [MAT] |
|---|---|---|
| E0 | Contexto da conta | (implícito) |
| E1 | Identificação do produto | Etapa 1: busca no catálogo |
| E2 | Categoria | Etapa 1: Confirme a categoria |
| E3 | Características principais e condição | Etapa 2: Características principais / Condição |
| E4 | Estrutura de variação (eixos) | Etapa 2: Variações e fotos → "Adicionar variações" |
| E5 | Combinações e dados por variante | Etapa 2: cards de variação (estoque, código universal, SKU) |
| E6 | Imagens | Etapa 2: fotos dentro de Variações e fotos |
| E7 | Título / family_name | Etapa 2: Título |
| E8 | Ficha técnica | Etapa 2: Informação regulatória + Características secundárias |
| E9 | Descrição | Etapa 2: Descrição |
| E10 | Condições de venda | Etapa 3 |
| E11 | Validação | (ao clicar Anunciar) |
| E12 | Revisão | (nossa) |
| E13 | Publicação | Anunciar |
| E14 | Pós-publicação | (nossa) |

**Por que esta ordem [ARQ]:**

- **Condição (E3) antes da ficha técnica (E8).** Atributos `new_required` só são obrigatórios quando `condition = new` [ML·S3].
- **Eixos (E4) antes da ficha técnica (E8).** Um atributo escolhido como eixo sai do nível do produto. Se a ficha técnica for preenchida antes, haverá conflito de valores.
- **Combinações (E5) antes das imagens (E6).** Os grupos de imagem dependem dos valores dos eixos que têm `defines_picture`.
- **Ficha técnica (E8) depois das principais (E3).** Atributos condicionais dependem de valores já preenchidos; o endpoint `/attributes/conditional` recebe o item inteiro [ML·S3].
- **Título (E7) depois das principais.** Ele deve refletir marca, modelo e características, e no modelo UP o `family_name` agrupa as variantes.

---

## 2. Etapas detalhadas

### E0 — Contexto da conta

| | |
|---|---|
| **Objetivo** | Garantir que a conta pode publicar e descobrir o modelo de publicação. |
| **Informações** | `access_token` válido; `GET /users/{id}` (tags: `user_product_seller`, `warehouse_management`); `GET /users/{id}/shipping_preferences` (modos de envio); restrições da conta. |
| **Dependências** | Conta ML conectada via OAuth. |
| **Regras** | Token dura 6h; o `refresh_token` é de **uso único** e cada renovação devolve um novo, que precisa ser salvo [ML·S17]. O modelo é UP se `user_product_seller` estiver presente [ML·S8]. ME2 só pode ser usado se habilitado nas preferências [ML·S15]. |
| **Validações** | Token renovável; conta não bloqueada; pelo menos um modo de envio disponível. |
| **Resultado** | `AccountContext { seller_id, publishing_model: LEGACY|USER_PRODUCTS, shipping_modes[], tags[], checked_at }` |

### E1 — Identificação do produto (opcional na Fase 1)

| | |
|---|---|
| **Objetivo** | Tentar casar o produto com o catálogo do ML ou obter atributos sugeridos. |
| **Informações** | Texto livre (nome, marca, modelo) **ou** GTIN. |
| **Dependências** | E0. |
| **Regras** | Busca de catálogo: `GET /products/search?status=active&site_id=MLB&q=…` ou `product_identifier=<GTIN>` [ML·S5]. Anúncio de catálogo não pode ter variações [ML·S5]. Domínios `catalog_required` podem exigir catálogo [ML·S5]. |
| **Validações** | GTIN: só dígitos, 8/12/13/14 dígitos e dígito verificador GS1 [ML·S4]. |
| **Resultado** | `Identification { query_text, gtin?, catalog_product_id?, catalog_strategy? }`. Na Fase 1, se o usuário escolher um produto de catálogo ou o domínio for `catalog_required`, mostrar o aviso "publicação via catálogo ainda não suportada" [ARQ]. |

### E2 — Categoria

| | |
|---|---|
| **Objetivo** | Definir a categoria folha e carregar o **CategorySchema**. |
| **Informações** | Sugestões de `GET /sites/MLB/domain_discovery/search?q=<título>&limit=…` (até 8; cada uma com `domain_id`, `category_id`, `category_name` e `attributes` inferidos) [ML·S1]. Navegação manual na árvore (`/sites/MLB/categories`, `/categories/{id}` com `children_categories` e `path_from_root`) [ML·S2]. |
| **Dependências** | E0; texto de E1 (ou título provisório). |
| **Regras** | A categoria deve ser **folha** (`children_categories` vazio) [HIP·H-03, prática padrão]. `settings.listing_allowed` deve ser `true` [ML·S2/S11: erro 126]. Ao confirmar, carregar o schema (ver `03`). Os `attributes` inferidos pelo `domain_discovery` entram como **sugestões** (`source = inferred`), editáveis [ARQ]. |
| **Validações** | Folha; `listing_allowed`; `item_conditions` contém alguma condição; moeda BRL em `currencies`; detectar exigências da Fase 2 (tabela de medidas, catálogo obrigatório) e bloquear com mensagem [ARQ]. |
| **Resultado** | `category_id`, `domain_id`, `path_from_root`, `schema_hash`. |

### E3 — Características principais e condição

| | |
|---|---|
| **Objetivo** | Fixar a identidade do produto (marca, modelo e demais atributos do grupo principal) e a condição. |
| **Informações** | Atributos do grupo principal do `technical_specs/input` (tipicamente BRAND e MODEL) [ML·S3][MAT]; condição. |
| **Dependências** | E2. |
| **Regras** | BRAND: marca real ou "Genérica" [ML·S10][MAT]. Marcas restritas só para lojas oficiais ou vendedores certificados [ML·S5]. Condição na API: `new` / `used` / `not_specified`; "Recondicionado" = atributo `ITEM_CONDITION`, com garantia mínima de 90 dias [ML·S10] (mapeamento exato em **H-04**). A condição precisa estar em `settings.item_conditions` [ML·S2]. |
| **Validações** | Obrigatórios do grupo preenchidos; tipo de valor correto; condição permitida. |
| **Resultado** | Atributos de produto (escopo PRODUCT) + `condition` (+ `ITEM_CONDITION`). **Dispara** o recálculo do conjunto de obrigatórios (`new_required`, condicionais). |

### E4 — Estrutura de variação (eixos)

| | |
|---|---|
| **Objetivo** | Definir **por quais atributos** o produto varia e quais valores cada eixo tem. |
| **Informações** | Lista de atributos elegíveis = atributos da categoria com tag `allow_variations` [ML·S3/S7]. Para cada eixo escolhido, os valores (`value_id` da lista do ML, ou `value_name` customizado quando o tipo permitir). |
| **Dependências** | E2 (schema); E3 (um atributo já preenchido no produto que vire eixo tem o valor movido para o eixo). |
| **Regras** | 0 eixos = produto simples. Eixos aceitam qualquer número de valores ≥ 1. No máximo **1 eixo customizado** (fora do schema), e ele não pode duplicar um atributo da categoria [ML·S7, legado]. Eixo não aceita N/A [ML·S3]. Um atributo que vira eixo **deixa de existir no nível do produto** [ARQ]. Detalhes em `05`. |
| **Validações** | Eixos elegíveis; sem valores repetidos (após normalização) dentro do eixo; produto do nº de valores ≤ `max_variations_allowed` no legado [ML·S7]. |
| **Resultado** | `VariationAxis[]` com `AxisValue[]`, em ordem. |

### E5 — Combinações e dados por variante

| | |
|---|---|
| **Objetivo** | Gerar as combinações e coletar os dados próprios de cada uma. |
| **Informações** | Produto cartesiano dos valores dos eixos. Por variante: ativa/inativa, preço, estoque, SKU, GTIN ou EMPTY_GTIN_REASON, demais atributos `variation_attribute`. |
| **Dependências** | E4. |
| **Regras** | A combinação é identificada por uma **chave canônica** (ver `05`). Combinações que não existem no estoque real são **desativadas**, não apagadas [ARQ]. Legado: preço igual em todas [ML·S7]. UP: preço livre por variante [ML·S8]. SKU = atributo `SELLER_SKU` por variante [ML·S7]. GTIN por variante quando houver variações [ML·S4]. |
| **Validações** | Chaves únicas; ≥ 1 variante ativa; estoque inteiro de 1 a 99999 [ML·S19] (0 → ver V-VAR-12 em `05`); SKU único no rascunho; GTIN válido ou motivo de ausência quando exigido. |
| **Resultado** | `Variant[]`, com `combination_key` e dados. |

### E6 — Imagens

| | |
|---|---|
| **Objetivo** | Associar imagens ao produto e às variantes de forma determinística. |
| **Informações** | Arquivos enviados pelo usuário; atribuição a "Galeria geral" ou a um **grupo de imagem**. |
| **Dependências** | E5 (grupos derivados das variantes) e E2 (limites e tags `defines_picture`). |
| **Regras** | Ver `06`. Resumo: se algum eixo tem `defines_picture`, as imagens são atribuídas **por valor desse eixo**, e todas as variantes com o mesmo valor compartilham as mesmas fotos [ML·S3/S7]. Toda variante precisa de ≥ 1 foto [ML·S7]. Limites lidos da categoria. |
| **Validações** | Formato JPG/PNG, ≤ 10 MB, lado mínimo 500 px [ML·S9]; limites por item e por variação; grupo sem foto bloqueia. |
| **Resultado** | `ImageAsset[]` + `ImageAssignment[]`; lista final de fotos resolvida por variante (calculada, não armazenada). |

### E7 — Título / family_name

| | |
|---|---|
| **Objetivo** | Definir o nome do anúncio (legado) ou da família (UP). |
| **Informações** | Texto do usuário (pode ser pré-sugerido a partir de marca + modelo + principais atributos [ARQ]). |
| **Dependências** | E2 (`max_title_length`); E3. |
| **Regras** | Comprimento ≤ `max_title_length` da categoria [ML·S2] (60 na categoria do print [MAT]). Proibido: contato, frete grátis, parcelamento, condição (novo/usado), menção a estoque, pontuação e símbolos desnecessários [ML·S10][MAT]. UP: o texto vira `family_name` (≤ 120 e ≤ `max_title_length`) [ML·S8]; enviar ou não `title` é **H-02**. No modelo UP, o mesmo `family_name` vale para todas as variantes [ARQ]. |
| **Validações** | Tamanho; termos proibidos (lista configurável, aviso); não vazio. |
| **Resultado** | `draft.title` (único campo interno; o montador decide se vira `title` ou `family_name`). |

### E8 — Ficha técnica

| | |
|---|---|
| **Objetivo** | Preencher todos os atributos de produto restantes: obrigatórios, condicionais, recomendados e regulatórios. |
| **Informações** | Grupos do `technical_specs/input` exceto o principal [ML·S3][MAT]; atributos de `/attributes` que não aparecem nos grupos (por exemplo `hidden`) ficam numa seção "Avançado" [ARQ]. |
| **Dependências** | E3, E4 (eixos saem daqui), E5. |
| **Regras** | Obrigatório = tag `required`, ou `new_required` com condição nova, ou devolvido por `POST /categories/{id}/attributes/conditional` [ML·S3]. "Não se aplica" → `value_id: "-1"`, `value_name: null` [ML·S3]. `read_only`, `fixed` e `inferred` não são editáveis [ML·S3]. `number_unit` → `"<número> <unidade>"` com unidade de `allowed_units` [ML·S3]. Dimensões da embalagem (`SELLER_PACKAGE_*`) entram aqui ou em E10 (**H-05**). |
| **Validações** | Obrigatórios preenchidos ou N/A quando permitido; tipos; tamanho ≤ `value_max_length` (erro 154 com > 255) [ML·S11]; valores de lista válidos. Consultar condicionais com debounce, após mudanças relevantes [ARQ]. |
| **Resultado** | Atributos de produto completos + `missing_recommended[]` (afeta exposição: tag `incomplete_technical_specs`) [ML·S3]. |

### E9 — Descrição

| | |
|---|---|
| **Objetivo** | Texto descritivo. |
| **Regras** | Texto puro (sem HTML), quebras com `\n`, enviado **depois** da criação do item via `POST /items/{id}/description` com `plain_text` [ML·S13/S10]. Limite: `max_description_length` da categoria [ML·S2] (erro 3707) [ML·S11]. Opcional [MAT]. |
| **Validações** | Remover HTML; tamanho; aviso para contato ou links (lista configurável) [ARQ]. |
| **Resultado** | `draft.description`. |

### E10 — Condições de venda

| | |
|---|---|
| **Objetivo** | Preço, tipo de anúncio, envio, retirada e garantia, com simulação de quanto o vendedor recebe. |
| **Informações** | Tipos disponíveis: `GET /users/{id}/available_listing_types?category_id=` [ML·S19] (Clássico = `gold_special`, Premium = `gold_pro`). Tarifa: `GET /sites/MLB/listing_prices?price=&category_id=&listing_type_id=` [ML·S14]. Custo do frete grátis: `GET /users/{id}/shipping_options/free?…` (`list_cost`) [ML·S15]. Garantia: `sale_terms` (WARRANTY_TYPE, WARRANTY_TIME) [ML·S10]. |
| **Dependências** | E2, E5 (preços por variante), E0 (modos de envio). |
| **Regras** | Legado: um preço único (todas as variantes iguais) [ML·S7]. UP: preço por variante, com "aplicar a todos" [ARQ]. Preço ≥ mínimo da categoria (erro 109) [ML·S11]. Envio: `mode: me2` se disponível [ML·S15]. Frete grátis obrigatório acima de um limite (tag `mandatory_free_shipping`) [ML·S15]; o valor exato é **H-10**, então exibir o que a API retornar em vez de fixar. Retirada → `shipping.local_pick_up`. Garantia de recondicionado ≥ 90 dias [ML·S10]. "Você recebe" = preço − `sale_fee_amount` − `list_cost` [ARQ: fórmula derivada, ver **H-14**]. Atacado: Fase 2 (**H-12**). |
| **Validações** | Tipo de anúncio disponível para a conta e a categoria; preço numérico > 0 com 2 casas; garantia com tempo e unidade quando o tipo exigir. |
| **Resultado** | `listing_type_id`, preços nas variantes, `shipping`, `sale_terms`, `fee_preview` (informativo, não persistido como verdade). |

### E11 — Validação

Ver `08-validacao-pre-publicacao.md`. Três camadas: (L1) campo, (L2) regras do rascunho, (L3) remota (`/attributes/conditional` + `POST /items/validate` para **cada payload** que será enviado) [ML·S12]. Resultado: lista de `ValidationIssue` com severidade. Publicação liberada **somente sem bloqueantes**.

### E12 — Revisão

| | |
|---|---|
| **Objetivo** | Mostrar ao usuário exatamente o que será publicado. |
| **Informações** | Para cada anúncio que será criado (1 no legado; N no UP): título/family_name, categoria, preço, estoque, fotos na ordem final, atributos, avisos. |
| **Regras** | A revisão é gerada **a partir dos payloads finais** (mesmo montador da publicação), não a partir do formulário [ARQ]. Assim o que se vê é o que se envia. |
| **Resultado** | Confirmação explícita do usuário. |

### E13 — Publicação

Ordem de execução [ARQ]:

1. Revalidar o token (renovar se faltar menos de 10 min).
2. Reconsultar as tags da conta. Se o modelo mudou, **abortar** e voltar à revisão.
3. Verificar o `schema_hash`. Se o schema mudou, revalidar (E11).
4. Fazer upload das imagens pendentes (`POST /pictures/items/upload`) e gravar `ml_picture_id` [ML·S9].
5. Montar os payloads com IDs de foto.
6. Para cada payload: `POST /items` → gravar `ml_item_id` imediatamente → `POST /items/{id}/description` se houver descrição.
7. Atualizar o status da publicação: `PUBLISHED`, `PARTIALLY_PUBLISHED` ou `FAILED` (ver `09`).

**Resultado:** `Publication` + `PublicationItem[]` com IDs do ML, payload enviado e resposta recebida.

### E14 — Pós-publicação

| | |
|---|---|
| **Objetivo** | Saber o estado real do anúncio. |
| **Regras** | `GET /items/{id}` logo após a criação. Status possíveis: `active`, `paused`, `under_review`, `inactive`, `closed`, `payment_required`, com `sub_status` [ML·S16]. Assinar o tópico `items` (responder 200 em até 500 ms) e, no UP, `user-products-families` [ML·S18]. |
| **Resultado** | Status sincronizado e alertas para `under_review` / `poor_quality_thumbnail` / `incomplete_technical_specs`. |

---

## 3. Matriz de invalidação [ARQ]

| Quando muda… | Invalida | Comportamento |
|---|---|---|
| Categoria (E2) | **Tudo** de E3 a E12 | Reaproveitar valores cujo `attribute_id` exista no novo schema e cujo valor seja válido (marcados como "a revisar"); descartar o resto com aviso listando o que se perdeu. Eixos não elegíveis no novo schema são removidos. |
| Condição (E3) | Conjunto de obrigatórios (E8), garantia (E10) | Recalcular `new_required` e condicionais. |
| Marca/Modelo/atributos principais (E3) | Condicionais (E8), título sugerido (E7) | Reconsultar `/attributes/conditional`. |
| Adicionar/remover eixo (E4) | Combinações (E5), grupos de imagem (E6) | Regenerar combinações preservando os dados das chaves que continuam existindo (ver `05`). |
| Adicionar/remover valor de eixo (E4) | E5, E6 | Mesma regra: novas chaves nascem vazias, chaves extintas viram órfãs. |
| Variantes ativas (E5) | Preço (E10), validação | Recontar limites. |
| Modelo de publicação da conta (E0) | Revisão (E12) | Remontar payloads e exigir nova revisão. |
| Qualquer coisa após E11 | Resultado de E11 | O resultado da validação vale apenas para o `draft_revision` em que foi gerado. |

## 4. Estados do rascunho

```
DRAFT ──(validar)──► VALIDATED ──(confirmar revisão)──► PUBLISHING ──► PUBLISHED
  ▲                      │                                  │
  └──(qualquer edição)───┘                                  ├──► PARTIALLY_PUBLISHED ──(retentar falhas)──► PUBLISHING
                                                            └──► FAILED ──(editar)──► DRAFT
```

Cada etapa também guarda seu próprio status (`pending | complete | invalid | stale`) para o wizard mostrar onde há pendências.
