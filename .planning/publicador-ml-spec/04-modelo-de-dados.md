# 04 — Modelo de dados (D)

Modelo **conceitual**: os nomes são sugestões em inglês (snake_case) para facilitar o código. Tipos são indicativos. O banco do seu sistema pode ser diferente, desde que as relações e as restrições sejam mantidas. [ARQ] em todo o arquivo, salvo indicação.

## 1. Diagrama de relações

```
ml_account 1──* listing_draft *──1 category_schema
                    │
                    ├──* draft_attribute_value          (escopo PRODUCT)
                    ├──* variation_axis 1──* axis_value
                    ├──* variant 1──* variant_axis_value  (*──1 axis_value)
                    │       └──* variant_attribute_value  (VARIANT_DATA: SKU, GTIN…)
                    ├──* image_asset 1──* image_assignment
                    ├──* validation_run 1──* validation_issue
                    └──* publication 1──* publication_item *──* variant
```

## 2. Entidades

### 2.1 `ml_account`

| Campo | Tipo | Observação |
|---|---|---|
| id | pk | |
| seller_id | bigint | ID do vendedor no ML |
| access_token / refresh_token | criptografado | O refresh é de uso único: salvar o novo a cada renovação [ML·S17] |
| token_expires_at | timestamp | |
| tags | json | Última leitura de `GET /users/{id}` |
| publishing_model | enum `LEGACY` \| `USER_PRODUCTS` | Derivado de `tags` [ML·S8] |
| shipping_modes | json | De `shipping_preferences` |
| checked_at | timestamp | |

### 2.2 `category_schema` (cache)

| Campo | Tipo | Observação |
|---|---|---|
| category_id | pk (string) | ex.: `MLB…` |
| domain_id | string null | |
| path_from_root | json | |
| settings | json | Bruto de `/categories/{id}` |
| attributes | json | Bruto de `/categories/{id}/attributes` |
| technical_specs_input | json | Bruto |
| sale_terms | json null | |
| schema_hash | string | sha256 do JSON normalizado |
| fetched_at | timestamp | TTL 24h |

Guardar o JSON **bruto** e derivar a classificação em código (função pura `classify(schema, draft)`). Assim o código pode ser corrigido sem rebuscar dados.

### 2.3 `listing_draft` (o "produto" a publicar)

| Campo | Tipo | Observação |
|---|---|---|
| id | pk | |
| ml_account_id | fk | |
| status | enum `DRAFT` \| `VALIDATED` \| `PUBLISHING` \| `PUBLISHED` \| `PARTIALLY_PUBLISHED` \| `FAILED` | Ver `02` §4 |
| revision | int | Incrementa a cada edição; validações referenciam a revisão |
| step_state | json | `{E2:"complete", E5:"stale", …}` |
| identification | json | `query_text`, `gtin`, `catalog_product_id` |
| category_id / domain_id | string | |
| schema_hash | string | Snapshot usado |
| condition | enum `new` \| `used` \| `refurbished` | Interno. O montador traduz `refurbished` (**H-04**) |
| title | string | Vira `title` ou `family_name` conforme o modelo |
| description | text | Texto puro |
| listing_type_id | string | `gold_special` / `gold_pro` |
| currency_id | string | `BRL` |
| shipping | json | `{mode, local_pick_up, free_shipping}` + embalagem se aplicável |
| warranty | json | `{type_value_id, time_value, time_unit}` |
| pictures_per_variant_enabled | bool | Só relevante quando não há eixo `defines_picture` (`06`) |
| created_by / timestamps | | |

### 2.4 `draft_attribute_value` (atributos do PRODUTO)

| Campo | Tipo | Observação |
|---|---|---|
| draft_id | fk | |
| attribute_id | string | ex.: `BRAND` |
| value_id | string null | `"-1"` = Não se aplica |
| value_name | string null | |
| value_number / value_unit | decimal / string null | Para `number_unit`; o montador gera `"23 cm"` |
| values_multi | json null | Para `multivalued` |
| source | enum `user` \| `inferred` \| `catalog` \| `migrated` | `inferred` = veio do `domain_discovery`; `migrated` = sobreviveu à troca de categoria |
| needs_review | bool | true para `inferred` / `migrated` até o usuário confirmar |

**Restrição:** `unique(draft_id, attribute_id)`. Um atributo que seja eixo **não pode** ter linha aqui.

### 2.5 `variation_axis`

| Campo | Tipo | Observação |
|---|---|---|
| id | pk | |
| draft_id | fk | |
| attribute_id | string null | null para eixo customizado |
| custom_name | string null | Só para eixo customizado (máx. 1 por rascunho) [ML·S7] |
| position | int | Ordem de exibição e de composição da chave do rótulo |
| defines_picture | bool | Copiado da tag no momento da criação |

**Restrição:** `unique(draft_id, attribute_id)`.

### 2.6 `axis_value`

| Campo | Tipo | Observação |
|---|---|---|
| id | pk | |
| axis_id | fk | |
| value_id | string null | id do ML quando escolhido da lista |
| value_name | string | Rótulo exibido / enviado se customizado |
| normalized_key | string | Ver `05` §3 |
| position | int | |

**Restrição:** `unique(axis_id, normalized_key)`.

### 2.7 `variant` (uma combinação)

| Campo | Tipo | Observação |
|---|---|---|
| id | pk | |
| draft_id | fk | |
| combination_key | string | Chave canônica (`05` §3). `"__single__"` para produto sem eixos |
| enabled | bool | Combinação desativada não é publicada |
| orphaned | bool | A chave deixou de existir após mudança de eixos; mantida para não perder dados ou IDs |
| price | decimal(12,2) | Sempre por variante. No legado, todos iguais |
| available_quantity | int | |
| position | int | |
| ml_item_id | string null | UP: um item por variante |
| ml_variation_id | bigint null | Legado: id da variação dentro do item |
| ml_user_product_id | string null | UP |

**Restrição:** `unique(draft_id, combination_key)`.

### 2.8 `variant_axis_value`

`(variant_id, axis_id, axis_value_id)`, com `unique(variant_id, axis_id)`. É a forma relacional da combinação; a `combination_key` é derivada daqui.

### 2.9 `variant_attribute_value` (VARIANT_DATA)

Mesma forma de `draft_attribute_value`, com `variant_id`. Conteúdo típico:

- `SELLER_SKU`
- `GTIN`
- `EMPTY_GTIN_REASON`
- `SIZE_GRID_ROW_ID` (Fase 2)
- outros atributos `variation_attribute` da categoria

**Restrições:**

- `unique(variant_id, attribute_id)`.
- SKU único **por rascunho**: `unique(draft_id, SELLER_SKU)` via índice ou validação. Unicidade por conta é aviso (L3).

### 2.10 `image_asset`

| Campo | Tipo | Observação |
|---|---|---|
| id | pk | |
| draft_id | fk | |
| storage_path | string | Arquivo no seu storage |
| sha256 | string | Deduplicação |
| mime / bytes / width / height | | Validações locais |
| ml_picture_id | string null | Retorno de `/pictures/items/upload` |
| upload_status | enum `pending` \| `uploaded` \| `failed` | |
| upload_error | json null | |

**Restrição:** `unique(draft_id, sha256)`.

### 2.11 `image_assignment`

| Campo | Tipo | Observação |
|---|---|---|
| image_id | fk | |
| scope | enum `GENERAL` \| `GROUP` | |
| group_key | string null | Ver `06` §3 (ex.: `COLOR=52049`) |
| position | int | Ordem dentro do escopo |

Uma mesma imagem pode estar em mais de um escopo (por exemplo, uma tabela de medidas na galeria geral e também num grupo).

### 2.12 `validation_run` / `validation_issue`

| validation_issue | Observação |
|---|---|
| run_id, draft_revision | A validação vale só para esta revisão |
| layer | `L1` \| `L2` \| `L3` |
| rule_id | ex.: `V-VAR-03` (ver `08`) |
| severity | `BLOCKER` \| `WARNING` \| `INFO` |
| target | Referência estruturada: `{"type":"variant","id":12,"field":"available_quantity"}` |
| message | Texto em PT para o usuário |
| ml_cause | json null | Causa original do ML quando L3 |

### 2.13 `publication` / `publication_item`

| publication | Observação |
|---|---|
| id, draft_id, draft_revision | |
| publishing_model | Snapshot no momento |
| status | `RUNNING` \| `PUBLISHED` \| `PARTIALLY_PUBLISHED` \| `FAILED` |
| idempotency_key | uuid gerado por tentativa de publicação |

| publication_item | Observação |
|---|---|
| publication_id | |
| variant_ids | json (legado: todas; UP: uma) |
| payload / payload_hash | Exatamente o que foi enviado |
| status | `PENDING` \| `SENT` \| `CREATED` \| `FAILED` \| `UNKNOWN` |
| http_status / response | Resposta bruta do ML |
| ml_item_id | Gravado **imediatamente** após 201 |
| warnings | `cause[]` com `type=warning` |
| description_status | `NONE` \| `PENDING` \| `SENT` \| `FAILED` |

`UNKNOWN` = timeout ou erro de rede após o envio. Exige reconciliação antes de reenviar (`09` §5).

## 3. Objetos de domínio derivados (não persistidos)

| Objeto | Função |
|---|---|
| `CategorySchema` (classificado) | `classify(schema_raw, draft) → { attributes: [{id, role, effective_requirement, ui}], limits, flags: {needs_size_grid, catalog_required} }` |
| `ResolvedVariant` | Variante + atributos herdados do produto + lista final de fotos (`06`) + label (`"Azul / Couro"`) |
| `PayloadPlan` | `{ model, items: [{ payload, variant_ids, picture_ids_needed }], descriptions }`. É gerado pelo PayloadBuilder e usado tanto na Revisão quanto na Publicação |

## 4. Por que este desenho

- **Variante sempre existe** (mesmo sem eixos). Isso elimina o "if tem variação" espalhado pelo código. Produto simples = 1 variante com chave `__single__`. Bate com a UI do ML, que mostra o produto simples como um card de variação [MAT].
- **Preço na variante.** Atende ao UP sem migração. O legado só exige igualdade.
- **Chave canônica.** Garante unicidade, permite regenerar combinações sem perder dados e serve de idempotência por variante.
- **Atribuição de imagem por grupo, não por variante.** Codifica a regra `defines_picture` no modelo, em vez de depender de validação.
