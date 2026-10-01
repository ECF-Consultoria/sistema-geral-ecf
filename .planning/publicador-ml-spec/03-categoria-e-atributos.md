# 03 — Categoria e atributos

## 1. Ideia central

O sistema **não sabe de antemão** quais campos uma categoria tem. Ele **pergunta à categoria** e monta o formulário. Uma cadeira de escritório pede altura do encosto e "é giratória" [MAT]. Uma furadeira pede voltagem e potência. Uma camiseta pede tamanho com tabela de medidas. Tudo isso vem das respostas da API.

## 2. O CategorySchema

Objeto montado e cacheado a partir de 4 fontes:

| Fonte | Endpoint | O que fornece | Etiqueta |
|---|---|---|---|
| Configurações | `GET /categories/{id}` | `path_from_root`, `children_categories`, `settings` (ver abaixo) | [ML·S2] |
| Atributos | `GET /categories/{id}/attributes` | Lista completa: `id`, `name`, `value_type`, `values[]`, `allowed_units[]`, `default_unit`, `tags`, `hierarchy`, `relevance`, `attribute_group_id`, `value_max_length` | [ML·S3] |
| Estrutura do formulário | `GET /categories/{id}/technical_specs/input` | `groups[]` → `components[]` (`component`, `label`, `ui_config.hint`) → `attributes[]` | [ML·S3] |
| Termos de venda | `GET /categories/{id}/sale_terms` | Tipos e prazos de garantia válidos | [HIP·H-09] (endpoint não confirmado) |

**Campos de `settings` usados pelo Publicador** [ML·S2]:

| Campo | Uso |
|---|---|
| `listing_allowed` | Pode publicar nesta categoria? |
| `max_title_length` | Limite do título / family_name |
| `max_pictures_per_item` | Limite de fotos por item |
| `max_pictures_per_item_var` | Limite de fotos por variação |
| `max_variations_allowed` | Limite de variações (legado) |
| `max_description_length` | Limite da descrição |
| `item_conditions` | Condições aceitas |
| `buying_modes` | Modos de compra aceitos |
| `currencies` | Moedas aceitas |
| `minimum_price` / `maximum_price` | Faixa de preço |
| `shipping_modes` | Modos de envio aceitos |
| `catalog_domain` | Domínio de catálogo |
| `restrictions`, `status`, `tags` | Restrições e estado da categoria |

**Cache [ARQ]:** TTL de 24h por `category_id`. Grava-se o `schema_hash` (hash do JSON normalizado das 4 fontes). Antes de publicar, se o snapshot tiver mais de 24h, o schema é rebuscado e, se o hash mudar, o rascunho é revalidado.

**Download da árvore inteira:** `GET /sites/MLB/categories/all` (gzip, com cabeçalhos `X-Content-Created` e `X-Content-MD5`) [ML·S2]. É útil para navegação offline, mas não é obrigatório.

## 3. Tags de atributo e o que significam para o Publicador

Fonte: [ML·S3] (páginas en_us/MLB 08/2023 e es_ar 05/2024).

| Tag | Significado oficial | Papel no Publicador [ARQ] |
|---|---|---|
| `required` | Precisa ser preenchido para publicar | Obrigatório sempre |
| `catalog_required` | Necessário para identificar o produto no catálogo | Tratar como obrigatório se também `required`; senão "fortemente recomendado" |
| `conditional_required` | Obrigatório dependendo de outros dados (AR, BR, MX). Verificar via `POST /attributes/conditional` | Obrigatório **se** o endpoint devolver |
| `new_required` | Obrigatório só para `condition = new` | Obrigatório condicionado à condição |
| `allow_variations` | O item pode variar por este atributo | **Candidato a eixo de variação**. Não aceita N/A |
| `variation_attribute` | Pode ter valor diferente por variação | **Dado por variante** (SKU, GTIN, SIZE_GRID_ROW_ID…) |
| `defines_picture` | Define quais fotos aparecem (ex.: Cor) | **Chave de agrupamento de imagens** (`06`) |
| `fixed` | Valor fixo da categoria, preenchido pelo sistema | Não editável, não exibido |
| `hidden` | Não aparece no formulário do ML, mas pode ser enviado via API | Seção "Avançado", recolhida |
| `used_hidden` | Oculto no fluxo de usados | Ocultar quando `condition = used` [HIP] |
| `read_only` | Uso interno; o vendedor não pode enviar | **Nunca enviar** |
| `inferred` | Valor inferido; não modificável | **Nunca enviar** |
| `multivalued` | Aceita vários valores separados por vírgula | Componente multivalor |
| `product_pk` | Parte da chave do produto | Mesmo valor em todas as variantes de uma família UP [ARQ] |
| `grid_template_required` | Necessário para buscar/criar tabela de medidas | Sinal de que a categoria usa tabela de medidas → Fase 2 |
| `others`, `restricted_values` | Uso interno | Ignorar na UI; preservar se vierem do ML |

> **Atenção:** um atributo pode ter **várias tags ao mesmo tempo**. COLOR, por exemplo, pode ser `required` + `allow_variations` + `defines_picture`. A classificação abaixo resolve essas combinações.

## 4. Classificação de cada atributo (algoritmo) [ARQ]

Para cada atributo `a` do schema, calcula-se um **papel** e uma **obrigatoriedade efetiva**:

```
se a.tags contém read_only ou inferred ou fixed     → papel = SYSTEM (não editável, não enviado pelo usuário)
senão se a.id ∈ eixos escolhidos no rascunho        → papel = VARIATION_AXIS
senão se a.tags contém variation_attribute          → papel = VARIANT_DATA     (valor por variante)
senão                                               → papel = PRODUCT          (valor único do produto)

obrigatoriedade efetiva:
  REQUIRED     se required
             ou (new_required e condition = new)
             ou (conditional_required e a.id ∈ resposta de /attributes/conditional)
  RECOMMENDED  se catalog_required (sem required) ou relevance = 1  [HIP: semântica de relevance]
  OPTIONAL     caso contrário
```

| Papel | Onde o valor fica | Exemplo [MAT] |
|---|---|---|
| PRODUCT | `draft_attribute_values` (escopo produto) | Marca ECF, Modelo Executiva, Altura do encosto |
| VARIATION_AXIS | `variation_axes` + `axis_values` | Cor (Azul), Material (Couro) no card "Azul / Couro" |
| VARIANT_DATA | `variant_attribute_values` | SKU "CAD", Código universal 7896553367645 |
| SYSTEM | Não armazenado como entrada do usuário | — |

**Regras de transição de papel:**

- Se um atributo `allow_variations` **não** for escolhido como eixo, ele vira PRODUCT. Se for `required`, precisa de um valor único no produto.
- Se for escolhido como eixo, todo valor que ele tinha no nível do produto é movido para o eixo, como primeiro valor.
- `VARIANT_DATA` num produto **sem eixos** é armazenado na variante única (há sempre ≥ 1 variante).

## 5. Tipos de valor e componentes de UI

| `value_type` | Componente [ARQ] | Formato enviado | Fonte |
|---|---|---|---|
| `string` | Texto com autocomplete de `values[]` | `value_id` se escolheu sugestão, senão `value_name` | [ML·S3] |
| `number` | Numérico | `value_name: "12"` | [ML·S3] |
| `number_unit` | Numérico + seletor de `allowed_units` (padrão `default_unit`) | `value_name: "23 cm"` | [ML·S3][MAT] |
| `boolean` | Sim/Não | `value_id` do valor correspondente em `values[]` | [ML·S3][MAT] |
| `list` | Select com `values[]` | `value_id` (pode acompanhar `value_name`) | [ML·S3] |
| `color` (quando vier) | Select de cores | `value_id` | [ML·S3, AR] |
| `product_identifier` | Texto com validação GTIN | `value_name` | [ML·S4] |
| `grid_id` / `grid_row_id` | — (Fase 2) | — | [ML·S6] |

O componente é escolhido pelo **`value_type`**, não pelo `component` do technical_specs, porque só três nomes de componente estão documentados (`TEXT_INPUT`, `COMBO`, `TEXT_OUTPUT`) [ML·S3]. O `component` e o `ui_config.hint` servem como dica de rótulo e texto de ajuda.

**"Não se aplica"** [ML·S3]:

- Exibir o checkbox apenas para atributos **não obrigatórios** e **não eixo**. Para obrigatórios, ver **H-06**: o print mostra o N/A em INMETRO, que não estava marcado como obrigatório.
- Enviar `{"id": "...", "value_id": "-1", "value_name": null}`.
- Depois que um N/A é enviado, ele só pode ser trocado por um valor real.

**Valores customizados [ARQ + HIP]:**

- `list` aceita só valores da lista [ML·S3].
- `string`/`number` com sugestões aceitam `value_name` livre.
- Não há tag documentada que diga "aceita valor livre" (**H-07**). Regra: `list` e `boolean` → só `value_id`; demais tipos → livre.

## 6. Atributos condicionais [ML·S3]

`POST /categories/{CATEGORY_ID}/attributes/conditional`. O corpo é o **payload do item como está** (título, categoria, preço, condição, `listing_type_id`, atributos). A resposta lista os atributos que passaram a ser obrigatórios:

```json
{"required_attributes":[{"id":"GTIN","name":"Código universal de producto"}]}
```

Quando chamar [ARQ]:

- Ao concluir E3.
- Ao mudar qualquer atributo PRODUCT que seja `required` ou que tenha sido devolvido em consultas anteriores.
- Ao mudar a condição.
- Sempre na validação L3.

Usar debounce de 1–2 s e guardar a resposta com o `draft_revision`.

No modelo com variações, o payload enviado ao endpoint é o da **primeira variante ativa** (UP) ou o item legado completo [HIP·H-08: comportamento com variações não documentado].

## 7. GTIN e EMPTY_GTIN_REASON [ML·S4]

**Formato do GTIN:**

- Só dígitos, com 8, 12, 13 ou 14 dígitos (o doc também cita 10).
- Dígito verificador GS1 válido.
- Sem zeros reservados.
- Aceita vários GTINs separados por vírgula.

**Obrigatoriedade** conforme a tag do GTIN na categoria:

| Tag | GTIN obrigatório |
|---|---|
| `required` | Sempre |
| `new_required` | Quando a condição é nova |
| `conditional_required` | Quando o endpoint de condicionais devolver; nesse caso, pode-se mandar **EMPTY_GTIN_REASON** no lugar |

**Motivos de ausência** (ids da página AR; confirmar no MLB, **H-15**):

| Motivo | id |
|---|---|
| Artesanal | 17055158 |
| Kit | 17055159 |
| Não registrado | 17055160 |
| Outro | 17055161 |

**Com variações:** GTIN e EMPTY_GTIN_REASON são dados **por variante** (`variation_attribute`).

**Erros:** 7810 (falta condicional), 7710 (checksum), 7711 (formato) [ML·S4/S11].

## 8. Domínio × categoria

- `domain_id` (ex.: `MLB-OFFICE_CHAIRS`) agrupa produtos do mesmo tipo e é usado por catálogo, tabelas de medidas e família UP. `category_id` (ex.: `MLB…`) é a folha da árvore usada no item.
- Guardar os dois [ARQ]. O `domain_id` vem do `domain_discovery` [ML·S1]; se a categoria foi escolhida manualmente, ver **H-16** (como obter o domínio de uma categoria).

## 9. Exemplos das três categorias do briefing

| Categoria | Atributos típicos | Provável classificação [HIP: confirmar na Fase 0] |
|---|---|---|
| Ferramenta elétrica | Marca, Modelo, **Voltagem**, Potência | BRAND/MODEL = PRODUCT required; VOLTAGE `allow_variations` (127V/220V) → candidato a eixo, **sem** `defines_picture` (fotos iguais); POWER `number_unit` PRODUCT |
| Vestuário | Marca, **Cor**, **Tamanho**, Material | COLOR eixo com `defines_picture`; SIZE eixo + tabela de medidas (Fase 2); MATERIAL PRODUCT |
| Autopeça | Marca, Modelo, Compatibilidade, Aplicação | Compatibilidade = API própria (**H-13**); Aplicação/posição costuma ser atributo de lista PRODUCT |

O ponto é que **o mesmo algoritmo da seção 4** produz os três formulários. Nenhuma das categorias tem código específico.
