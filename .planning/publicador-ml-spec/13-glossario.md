# 13 — Glossário

| Termo | Definição |
|---|---|
| **MLB** | Site do Mercado Livre Brasil (prefixo dos IDs). |
| **Item / anúncio** | Recurso `/items` publicado. No legado, contém variações; no UP, representa uma única variante. |
| **Categoria** (`category_id`) | Folha da árvore de categorias em que o item é publicado. Define atributos e limites. |
| **Domínio** (`domain_id`) | Tipo de produto (ex.: `MLB-OFFICE_CHAIRS`). Usado por catálogo, tabelas de medidas e famílias. |
| **CategorySchema** | Objeto interno com settings, atributos (com tags), technical_specs e sale_terms de uma categoria. |
| **Atributo** | Característica do produto (`BRAND`, `COLOR`…), com `value_type` e tags. |
| **Tag de atributo** | Marcador que define o comportamento do atributo: `required`, `allow_variations`, `defines_picture`, etc. |
| **technical_specs** | Estrutura de grupos e componentes que espelha o formulário do Seller Center. |
| **Atributo condicional** | Atributo que vira obrigatório dependendo de outros valores; consultado em `/attributes/conditional`. |
| **N/A / Não se aplica** | `value_id "-1"` com `value_name null`. |
| **GTIN / Código universal** | EAN/UPC/JAN/ISBN/ITF-14 do produto. |
| **EMPTY_GTIN_REASON** | Motivo informado quando o GTIN é condicional e o produto não tem um. |
| **SELLER_SKU** | Atributo com o SKU do vendedor; por variante. |
| **Eixo de variação** | Atributo (`allow_variations`) pelo qual o produto varia. |
| **Valor de eixo** | Um dos valores de um eixo (Preto, Branco…). |
| **Combinação** | Um valor de cada eixo (Preto / P). |
| **Variante** | Combinação + dados próprios (preço, estoque, SKU, GTIN, fotos). Produto simples = 1 variante `__single__`. |
| **Chave canônica** (`combination_key`) | Identidade normalizada da combinação, ex.: `COLOR=id:52049\|SIZE=txt:m`. |
| **Variante órfã** | Variante cuja chave deixou de existir após uma mudança de eixos; mantida até decisão do usuário. |
| **Eixo customizado** | Eixo fora dos atributos da categoria (`name` + `value_name`); máx. 1. |
| **Grupo de imagem** | Conjunto de fotos compartilhado pelas variantes com o mesmo valor nos eixos `defines_picture`. |
| **Galeria geral** | Fotos válidas para todas as variantes. |
| **defines_picture** | Tag que obriga variantes com o mesmo valor desse atributo a terem as mesmas fotos. |
| **Modelo legado** | Publicação com `variations[]` num único item; preço igual em todas. |
| **User Products (UP)** | Modelo "preço por variação": cada variante é um item; itens agrupados por `family_name`. |
| **family_name** | Nome comum da família UP; base para o título gerado pelo ML. |
| **user_product_id** (MLBU…) | Identificador do produto físico do vendedor no UP; o estoque fica nele. |
| **family_id** | Identificador da família, calculado pelo ML. |
| **`user_product_seller`** | Tag da conta que obriga o modelo UP para novos anúncios. |
| **listing_type_id** | `gold_special` (Clássico), `gold_pro` (Premium). |
| **ME2** | Mercado Envios 2 (`shipping.mode = "me2"`). |
| **sale_terms** | Termos de venda, como garantia (`WARRANTY_TYPE`, `WARRANTY_TIME`). |
| **PayloadPlan** | Saída do montador: lista de payloads a enviar, com o mapa para variantes e fotos. |
| **L1 / L2 / L3** | Camadas de validação: campo / rascunho / remota. |
| **Reconciliação** | Verificar no ML se um item foi criado após um envio de resultado desconhecido, antes de reenviar. |
| **Fase 0** | Sondagem da API real para resolver hipóteses e gerar fixtures. |
