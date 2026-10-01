# 10 — Exemplos de payload

> **Todos os IDs abaixo são ilustrativos.** `category_id`, `value_id`, `picture id` e `value_id` de garantia **precisam vir das respostas reais da API** (Fase 0). Os formatos seguem a documentação oficial; as fontes estão indicadas em cada exemplo.

## 1. Upload de imagem [ML·S9]

```http
POST https://api.mercadolibre.com/pictures/items/upload
Authorization: Bearer <ACCESS_TOKEN>
Content-Type: multipart/form-data

file=@cadeira-preta-1.jpg
```

Resposta (resumo):

```json
{ "id": "123456-MLB78901234_102026", "variations": [ { "size": "1200x1200", "secure_url": "https://http2.mlstatic.com/..." } ] }
```

## 2. Atributos condicionais [ML·S3]

```http
POST https://api.mercadolibre.com/categories/MLB_CADEIRAS/attributes/conditional
```

```json
{
  "title": "Cadeira Escritório Executiva ECF Giratória",
  "category_id": "MLB_CADEIRAS",
  "price": 150,
  "currency_id": "BRL",
  "available_quantity": 1,
  "buying_mode": "buy_it_now",
  "condition": "new",
  "listing_type_id": "gold_special",
  "attributes": [
    { "id": "BRAND", "value_name": "ECF" },
    { "id": "MODEL", "value_name": "Executiva" }
  ]
}
```

Resposta:

```json
{ "required_attributes": [ { "id": "GTIN", "name": "Código universal de produto" } ] }
```

## 3. Legado — produto sem variações [ML·S10/S7]

```json
{
  "title": "Cadeira Escritório Executiva ECF Giratória Couro Azul",
  "category_id": "MLB_CADEIRAS",
  "price": 150.00,
  "currency_id": "BRL",
  "available_quantity": 1,
  "buying_mode": "buy_it_now",
  "listing_type_id": "gold_special",
  "condition": "new",
  "channels": ["marketplace"],
  "pictures": [ { "id": "PIC_AZUL_1" }, { "id": "PIC_GERAL_1" } ],
  "attributes": [
    { "id": "BRAND", "value_name": "ECF" },
    { "id": "MODEL", "value_name": "Executiva" },
    { "id": "COLOR", "value_id": "<id Azul>" },
    { "id": "BACKREST_HEIGHT", "value_name": "23 cm" },
    { "id": "IS_SWIVEL", "value_id": "<id Sim>" },
    { "id": "INMETRO_CERTIFICATION_REGISTRATION_NUMBER", "value_id": "-1", "value_name": null },
    { "id": "SELLER_SKU", "value_name": "CAD" },
    { "id": "GTIN", "value_name": "7896553367645" }
  ],
  "sale_terms": [
    { "id": "WARRANTY_TYPE", "value_name": "Garantia do vendedor" },
    { "id": "WARRANTY_TIME", "value_name": "30 dias" }
  ],
  "shipping": { "mode": "me2", "local_pick_up": false, "free_shipping": true }
}
```

Observações:

- Os ids de atributo como `BACKREST_HEIGHT` e `IS_SWIVEL` são **inventados** para ilustrar; os reais vêm de `/categories/{id}/attributes`.
- O exemplo mostra o N/A no formato oficial: `value_id "-1"` com `value_name null`.
- A descrição **não** vai aqui (ver §7) [ML·S10].
- Preferir `value_id` em `WARRANTY_TYPE` quando ele for conhecido (**H-09**).

## 4. Legado — duas dimensões (Cor × Tamanho), fotos por cor [ML·S7]

Cenário: Cor {Preto, Azul} × Tamanho {P, M}; a combinação Azul/M está desativada; COLOR tem `defines_picture`.

```json
{
  "title": "Camiseta Básica Algodão Marca X",
  "category_id": "MLB_CAMISETAS",
  "price": 59.90,
  "currency_id": "BRL",
  "available_quantity": 23,
  "buying_mode": "buy_it_now",
  "listing_type_id": "gold_pro",
  "condition": "new",
  "pictures": [
    { "id": "PIC_PRETO_1" }, { "id": "PIC_PRETO_2" },
    { "id": "PIC_AZUL_1" },
    { "id": "PIC_GERAL_MEDIDAS" }
  ],
  "attributes": [
    { "id": "BRAND", "value_name": "Marca X" },
    { "id": "MODEL", "value_name": "Básica" },
    { "id": "MATERIAL", "value_name": "Algodão" }
  ],
  "variations": [
    {
      "attribute_combinations": [ { "id": "COLOR", "value_id": "<id Preto>" }, { "id": "SIZE", "value_name": "P" } ],
      "price": 59.90, "available_quantity": 10,
      "picture_ids": ["PIC_PRETO_1", "PIC_PRETO_2", "PIC_GERAL_MEDIDAS"],
      "attributes": [ { "id": "SELLER_SKU", "value_name": "CAM-PT-P" }, { "id": "GTIN", "value_name": "7890000000017" } ]
    },
    {
      "attribute_combinations": [ { "id": "COLOR", "value_id": "<id Preto>" }, { "id": "SIZE", "value_name": "M" } ],
      "price": 59.90, "available_quantity": 8,
      "picture_ids": ["PIC_PRETO_1", "PIC_PRETO_2", "PIC_GERAL_MEDIDAS"],
      "attributes": [ { "id": "SELLER_SKU", "value_name": "CAM-PT-M" }, { "id": "GTIN", "value_name": "7890000000024" } ]
    },
    {
      "attribute_combinations": [ { "id": "COLOR", "value_id": "<id Azul>" }, { "id": "SIZE", "value_name": "P" } ],
      "price": 59.90, "available_quantity": 5,
      "picture_ids": ["PIC_AZUL_1", "PIC_GERAL_MEDIDAS"],
      "attributes": [ { "id": "SELLER_SKU", "value_name": "CAM-AZ-P" }, { "id": "GTIN", "value_name": "7890000000031" } ]
    }
  ]
}
```

Pontos a notar:

- O preço é igual em todas as variações (RN-45).
- `available_quantity` do item = soma das variações. Enviar ou omitir no item é **H-26**; a doc exige a quantidade por variação.
- As duas variantes pretas têm `picture_ids` idênticos (RN-61).
- `pictures` do item = união ordenada (`06` §6).
- Na Fase 1, moda com tabela de medidas fica bloqueada. Este exemplo ilustra apenas a estrutura de variação.

## 5. User Products — mesma família, 2 cores com preços diferentes [ML·S8]

Cenário: a cadeira do print em Azul/Couro e Preto/Couro. Eixos: COLOR (+ material, se for eixo na categoria).

**Item 1** (`POST /items`):

```json
{
  "family_name": "Cadeira Escritório Executiva ECF Giratória",
  "category_id": "MLB_CADEIRAS",
  "price": 150.00,
  "currency_id": "BRL",
  "available_quantity": 1,
  "buying_mode": "buy_it_now",
  "listing_type_id": "gold_special",
  "condition": "new",
  "pictures": [ { "id": "PIC_AZUL_1" }, { "id": "PIC_GERAL_1" } ],
  "attributes": [
    { "id": "BRAND", "value_name": "ECF" },
    { "id": "MODEL", "value_name": "Executiva" },
    { "id": "COLOR", "value_id": "<id Azul>" },
    { "id": "SELLER_SKU", "value_name": "CAD-AZ" },
    { "id": "GTIN", "value_name": "7896553367645" }
  ],
  "sale_terms": [
    { "id": "WARRANTY_TYPE", "value_name": "Garantia do vendedor" },
    { "id": "WARRANTY_TIME", "value_name": "30 dias" }
  ],
  "shipping": { "mode": "me2", "local_pick_up": false, "free_shipping": true }
}
```

**Item 2:** idêntico, exceto:

- `price: 165.00`, `available_quantity: 3`;
- `COLOR` = `<id Preto>`, `SELLER_SKU` = `CAD-PT`, GTIN próprio;
- `pictures` = fotos do grupo Preto + geral.

Pontos a notar:

- **Sem `title`** neste exemplo, seguindo a página de preço por variação [ML·S8]. Se a API do MLB exigir `title`, enviar `title = family_name` (**H-02**).
- **Sem `variations`** (RN-03).
- Mesmo `family_name`, mesma categoria, mesmos BRAND/MODEL (RN-46).

## 6. Recondicionado [ML·S10 + HIP·H-04]

```json
"condition": "new",
"attributes": [ { "id": "ITEM_CONDITION", "value_id": "<id Recondicionado>" } ],
"sale_terms": [ { "id": "WARRANTY_TYPE", "value_name": "Garantia do vendedor" }, { "id": "WARRANTY_TIME", "value_name": "90 dias" } ]
```

O valor de `condition` que acompanha `ITEM_CONDITION = Recondicionado` não está confirmado (**H-04**).

## 7. Descrição (após criar o item) [ML·S13]

```http
POST https://api.mercadolibre.com/items/MLB1234567890/description
```

```json
{ "plain_text": "Cadeira executiva giratória.\n\nEstrutura em aço, estofado em couro sintético.\nInclui manual de montagem." }
```

## 8. Validação [ML·S12]

```http
POST https://api.mercadolibre.com/items/validate
```

O corpo é idêntico ao do `POST /items`.

- Sucesso: `204 No Content`.
- Falha: corpo de erro padrão (`09` §1).

## 9. Tarifa e frete para "Você recebe" [ML·S14/S15]

```http
GET /sites/MLB/listing_prices?price=150&category_id=MLB_CADEIRAS&listing_type_id=gold_special&currency_id=BRL
GET /users/{SELLER_ID}/shipping_options/free?item_price=150&listing_type_id=gold_special&mode=me2&condition=new&dimensions=<AxLxC,peso>&verbose=true
```

```
voce_recebe = price − listing_prices.sale_fee_amount − shipping_options.coverage.all_country.list_cost
```

A fórmula é derivada [ARQ, H-14]. No print: 150 − 16,50 − 62,35 = 71,15 [MAT], que bate com a fórmula.

## 10. Erro típico [ML·S11]

```json
{
  "message": "Validation error", "error": "validation_error", "status": 400,
  "cause": [
    { "department": "items", "cause_id": 147, "type": "error",
      "code": "item.attributes.missing_required", "references": ["item.attributes"],
      "message": "The attributes [MODEL] are required for category MLB_CADEIRAS." },
    { "department": "items", "cause_id": 382, "type": "warning",
      "code": "item.category_id.migrated", "references": ["item.category_id"],
      "message": "Category migrated" }
  ]
}
```
