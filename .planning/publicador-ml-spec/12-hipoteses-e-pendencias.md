# 12 — Hipóteses e pendências

Tudo o que está marcado **[HIP]** na especificação está listado aqui, com a forma de validar.

**Regra para o Claude Code:** implementar cada item de forma **configurável** (flag, tabela de configuração ou função isolada), para que a decisão possa mudar sem refatoração. Atualizar este arquivo à medida que as hipóteses forem resolvidas.

## Fase 0 — Sondagem (fazer antes de codar as regras)

O ambiente em que esta especificação foi escrita **não conseguiu chamar a API do ML** (bloqueio de rede) e várias páginas pt_br da documentação estavam inacessíveis. Parte das regras veio de páginas en_us ou es_ar, de 2022–2024. Por isso:

1. Criar um script de sondagem (CLI) que, com o token de uma conta real, salve em `fixtures/ml/` as respostas JSON de:
   - `GET /users/me` (tags → H-01)
   - `GET /users/{id}/shipping_preferences`
   - `GET /users/{id}/available_listing_types?category_id=…`
   - Para **3–4 categorias** (Cadeiras para Escritório; uma de ferramenta com voltagem; uma de vestuário; uma de autopeças):
     - `GET /categories/{id}`
     - `GET /categories/{id}/attributes`
     - `GET /categories/{id}/technical_specs/input`
     - `GET /categories/{id}/sale_terms`
   - `GET /sites/MLB/domain_discovery/search?q=cadeira escritorio executiva`
   - `GET /sites/MLB/listing_prices?price=150&category_id=…&listing_type_id=gold_special`
2. Rodar `POST /items/validate` com variações deliberadas do payload (com e sem `title`, com e sem `family_name`, com `variations`, sem fotos, com N/A em obrigatório, com dimensões de embalagem em formatos diferentes) e salvar **todas** as respostas de erro.
3. Usar essas fixtures como base dos testes de nível I (`11`).

## Resultado da sondagem (atualizado em 01/10/2026)

A sonda é o comando `php artisan publicador:sondar` (`app/Console/Commands/PublicadorSondar.php`). Ela só lê e só valida: recusa `POST /items` antes de sair. As fixtures ficam em `tests/fixtures-ml/sondagem/`, e não em `fixtures/ml/`: o repositório tem `tests/Fixtures` e `tests/fixtures`, que no Windows são a mesma pasta.

- **Parte pública (0a):** feita em 01/10, com o token do aplicativo. Foram 44 respostas, todas HTTP 200, em `publico/`. As categorias saíram do `domain_discovery`:

  | Categoria | Caminho |
  |---|---|
  | **MLB193945** Cadeiras para Escritório | Casa, Móveis e Decoração › Móveis para Casa › Cadeiras, Sofás e Banquetas |
  | **MLB189007** Furadeiras › De Mão | Ferramentas › Ferramentas Elétricas › Perfuração |
  | **MLB31447** Camisetas e Regatas | Calçados, Roupas e Bolsas |
  | **MLB47097** Pastilhas de Freios | Acessórios para Veículos › Peças de Carros e Caminhonetes › Freios |

- **Parte da conta (0b):** feita em 01/10, na produção, com a empresa **#459 "Dev 02 Testes API"**. Foram 105 respostas em `conta/`. A conta do ML é a **MGSTOREL** (1555596317). É a mesma da antiga Dev 02 Teste (#356) — e **é uma loja real, não um usuário de teste do ML**. A 1ª rodada partia do payload legado; numa conta UP tudo parou no erro de `family_name`. A 2ª rodada parte da base do modelo da conta.

**Regra observada que muda a validação (H-24):** o `/items/validate` devolve **HTTP 400 mesmo quando todas as causas são `warning`**. Nesta conta sempre vêm dois avisos (`4053 shipping.lost_me1_by_user` e `350 item.shipping.mandatory_free_shipping`), então nunca houve 204. **"Válido" = nenhuma causa com `type = error`**, não "status 204". O Anunciar antigo já decide assim.

| ID | Status | Evidência | Decisão |
|---|---|---|---|
| H-01 | **Confirmada: UP** | Tags da conta: `business`, `eshop`, **`user_product_seller`**, `messages_as_seller`, `normal`. Em 10/07 a mesma conta era clássica: migrou. | O builder **UP é o caminho principal**. O legado continua para contas clássicas. |
| H-02 | **Confirmada** | No UP, `title` dá **`body.invalid_fields`** ("The fields [title] are invalid") e a falta de `family_name` dá **369** `body.required_fields` | O builder UP **nunca** manda `title`. A flag `up_send_title` deixa de ser necessária: a API proíbe. |
| H-03 | **Confirmada** | **126** `item.category_id.invalid` — "Make sure you're posting in a leaf category" | Bloquear categoria que não é folha (L2) |
| H-04 | **Confirmada no validate** | `condition: new` + `ITEM_CONDITION` = 2230582 (Recondicionado) + garantia de 90 dias passou só com avisos (cadeira, furadeira). O valor só existe em algumas categorias. | Recondicionado = `condition new` + `ITEM_CONDITION` 2230582, oferecido **só quando o valor existir** no schema. Conferir na criação (E2E). |
| H-05 | **Confirmada (opcional)** | Sem embalagem: passou sem nenhum aviso de embalagem. `"500"`/`"15"` sem unidade: **aviso 306** `item.attributes.omitted` — o valor é **descartado em silêncio**. | Embalagem opcional. Se informada, **sempre com unidade** (L1 bloqueia número sem unidade, senão o ML joga fora). |
| H-06 | **Confirmada: N/A não vale em obrigatório** | Cadeira: **100** `field.constraint.violated` ("BACKREST_HEIGHT is a required attribute … cannot be not applicable"). Camiseta (GENDER): **2516**. INMETRO não é obrigatório na cadeira. | V-ATT-06 BLOCKER: N/A só em opcional |
| H-07 | **Confirmada** | Texto livre em `REQUIRES_ASSEMBLY` (`BOOLEAN_INPUT`, `allow_custom_value=false`) → **3510** `invalid.item.attribute.values`. Texto livre em `BRAND` da pastilha (`COMBO`, `allow_custom_value=true`) → aceito. | Com `values[]` e `allow_custom_value=false` → só `value_id`. Com `true` → id ou texto livre. Sem `values[]` → texto livre. |
| H-08 | **Parcial** | `/attributes/conditional` aceita o payload UP (`family_name`, sem `title`) e o legado com `variations`. Em todos devolveu `{"required_attributes": []}` — nenhum condicional disparou nestas 4 categorias. | Mandar o payload da 1ª variante ativa (UP) ou o item inteiro (legado). Falta um caso que dispare. |
| H-09 | **Confirmada** | `GET /categories/{id}/sale_terms` existe. `WARRANTY_TYPE` é lista: **2230280** Garantia do vendedor, **2230279** Garantia de fábrica, **6150835** Sem garantia. `WARRANTY_TIME` é `number_unit` em dias/meses/anos. Igual nas 4 categorias. | Ler sempre do schema; mandar `value_id` |
| H-10 | **Confirmada (nesta conta)** | `shipping_options/free`: a R$ 50 e R$ 78,99 vem `free_shipping_by_meli: true` com desconto de 30%; a **R$ 79,00** vira `discount.type: mandatory` com 50%. No `validate` a R$ 150 sem frete grátis, o ML responde **aviso 350** "Mandatory free shipping added": ele **liga sozinho**, não recusa. | O limite vem da API (`discount.type`, `free_shipping_by_meli`). Nunca fixar 79 no código. Mostrar como aviso. |
| H-11 | **Confirmada (não existe)** | `settings.restrictions` e `settings.tags` vazios na cadeira | Ignorar na Fase 1 |
| H-13 | **Sem sinal** | `catalog_domains.compatibilities` = `[]` até na pastilha de freio. `HAS_COMPATIBILITIES` é `read_only`. | Continua fora da Fase 1. Como detectar a exigência fica em aberto. |
| H-14 | **Confirmada (fórmula)** | `listing_prices` da cadeira, a R$ 150 no Clássico, dá **16,50 (11%)**, o mesmo do print. Premium: 21,00 (14%). `coverage.all_country.list_cost` já vem **com o desconto aplicado** (R$ 150: `promoted_amount` 42,70 × 50% = 21,35), como no print (124,70 → 62,35). | "Você recebe" = preço − `sale_fee_amount` − `list_cost`, exibido como estimativa |
| H-15 | **Confirmada** | `EMPTY_GTIN_REASON` com **17055158–17055161** em cadeira, furadeira e camiseta. A pastilha nem tem o atributo (GTIN é `read_only`). | Ler do schema |
| H-16 | **Confirmada** | `settings.catalog_domain` traz o domínio (`MLB-OFFICE_CHAIRS`…), igual ao `domain_id` do `domain_discovery`. Também existe `GET /catalog_domains/{id}`. | Usar `settings.catalog_domain` |
| H-17 | **Parcial (UP)** | No UP, um atributo customizado `{"name": "Estampa", "value_name": "Lisa"}` junto com os eixos da categoria nos atributos do item passou só com avisos (cadeira, furadeira). O legado com 3+ eixos não pôde ser testado: a conta é UP. | UI limita a 3 eixos (configurável) |
| H-19 | **Confirmada** | `/users/{id}/items/search?seller_sku=` devolve `{seller_id, results: [ids], paging}`. Já roda em produção no "Importar" do Mapeamento. | Reconciliação por SKU |
| H-21 | **Confirmada** | Na cadeira, `UPHOLSTERY_MATERIAL` tem `allow_variations` **e** `defines_picture`, assim como `COLOR`. O "Azul / Couro" do print é Cor × Material. | O grupo de imagem é o par Cor+Material quando os dois são eixos |
| H-24 | **Confirmada (pior que o previsto)** | Avisos voltam num **400**, não num 204 (ver a regra acima) | V-REM-01 = "nenhuma causa `error`" |
| H-26 | **Sem conta para testar** | A conta de teste é UP: qualquer `variations` dá **374** ("The field variations is invalid with family name"). Na 1ª rodada, sem `available_quantity` no item, o ML também o pediu (369). | Precisa de uma conta **clássica** para fechar. Até lá: enviar a soma (decisão provisória). |
| H-27 | **Refutada como sinal** | `relevance` 1 em cerca de 55% dos atributos, inclusive em muitos `hidden` | O destaque vem do grupo `MAIN` do `technical_specs`, não do `relevance` |
| H-28 | **Confirmada (parcial)** | Só o **GTIN** tem `used_hidden`, nas 4 categorias | Ocultar GTIN quando a condição for usado |
| H-18, H-22, H-23, H-25 | **Pendente (E2E)** | Exigem criar anúncio | — |

### Achados que a especificação não previa

| ID | Achado | Consequência |
|---|---|---|
| N-01 | `max_variations_allowed` **diverge**: `/categories/MLB31447` diz **100** e `/catalog_domains/MLB-T_SHIRTS` diz **250** (a doc [ML·S7] diz 250 em Moda) | Usar o **menor** (o da categoria) até um `validate` provar o contrário. **[HIP]** |
| N-02 | `settings.shipping_modes` **não existe** (vem `null`) nas 4 categorias. Existe `shipping_options` (`carrier`, `custom`; na pastilha, só `custom`). | Os modos de envio saem da conta (`shipping_preferences`) e do `validate`, não da categoria. Corrige `03` §2. |
| N-03 | **Categorias espelho:** MLB193945 tem `mirror_slave_categories: [MLB193964]`. O caminho do print (Indústria e Comércio › … › Cadeiras para Escritório) é provavelmente o espelho. | Em qual das duas publicar: **[HIP]**. Por ora, a que o `domain_discovery` devolve. |
| N-04 | `technical_specs/input` lista **todos** os atributos (zero de fora), inclusive os `hidden`, nos grupos `MAIN`, `LEGAL`/`PRODUCT_REGISTRIES`, `FISCAL_INFO`, `PRICING` e `OTHER`. Lá as tags são **lista de strings**; em `/attributes` são **objeto**. `attribute_group_id` é `OTHERS` em todos. | O agrupamento da tela vem só do `technical_specs`. O classificador normaliza os dois formatos de tag. |
| N-05 | Há mais componentes que os 3 documentados: `COLOR_INPUT` (junta `COLOR` + `MAIN_COLOR` num componente), `NUMBER_UNIT_INPUT`, `NUMBER_INPUT`, `BOOLEAN_INPUT`, `PICTURE_INPUT`, `GRID_INPUT`, `GRID_ROW_INPUT`, `TEXT_OUTPUT`. `ui_config` traz `allow_custom_value`, `allow_filtering`, `hint`, `tooltip` e `example`. | O componente continua escolhido pelo `value_type` (`03` §5), mas um componente pode ter **mais de um atributo**. |
| N-06 | Há `value_type` fora da tabela do `03` §5: `picture_id` (`SEC_STAMP`, `REGULATORY_INFORMATION_QR_CODE`), `grid_id` e `grid_row_id`. | `picture_id` fica na Fase 2 (é `hidden` ou opcional nas 4). A grade continua detectada e bloqueada. |
| N-07 | Nenhum `new_required` nas 4 categorias. `conditional_required`: `GTIN` + `EMPTY_GTIN_REASON` (e `UNITS_PER_PACK` na camiseta). | — |
| N-08 | **Autopeças:** `GTIN` é `read_only` + `hidden`, e `VEHICLE_TYPE` é `required` **e** `fixed`. Nenhum `allow_variations`. `max_title_length` = **200**. | `fixed`/`read_only` vencem `required` (papel SYSTEM: não pedir, não enviar) |
| N-09 | Na furadeira, `POWER` é `variation_attribute` (dado da variante), não do produto (o `03` §9 previa produto). `VOLTAGE` tem `allow_variations` e **não** tem `defines_picture`, como previsto. | O algoritmo do `03` §4 já cobre |
| N-10 | `settings.item_conditions` varia por categoria (pastilha: só `new`). `minimum_price` = **8** na camiseta e 0 nas outras. | Ler da categoria (já previsto) |
| N-11 | O `domain_discovery` devolve atributos inferidos (furadeira: `VOLTAGE` = 127V) | Sugestões com `origem=inferred` (RN-20) |
| N-12 | A cadeira MLB193945 tem **exatamente** os atributos do print: `BACKREST_HEIGHT`, `SEAT_DEPTH`, `OFFICE_CHAIR_WIDTH`, `MAX_CHAIR_HEIGHT` (`number_unit`, unidades `"`/cm/ft/m/mm) e `REQUIRES_ASSEMBLY`, `IS_GAMER`, `IS_ERGONOMIC`, `IS_SWIVEL`, `INCLUDES_ASSEMBLY_MANUAL` (`boolean`: 242084 Não / 242085 Sim), todos `required`. | A fixture `schema_cadeira` do `11` é esta categoria, de verdade |
| N-13 | **RN-03 confirmada no MLB:** conta UP + `variations` → **374** `body.invalid_fields` | A guarda do builder UP (TC-90) é regra [ML], não só [ARQ] |
| N-14 | **Limite do `family_name`:** passou de 60 → **462** `item.family_name.length_invalid` ("over of 60 character") nas categorias com `max_title_length` 60. Na pastilha (`max_title_length` 200), **140 caracteres passaram**. | O limite é o `max_title_length` da categoria. O teto de 120 do [ML·S8] **não apareceu** no `validate`: fica configurável (`up_family_name_max`, padrão `null`) até a criação real confirmar. |
| N-15 | Estoque 0 passou no `validate` (só avisos) | V-VAR-12 continua BLOCKER por decisão [ARQ] (item nasce pausado), não por regra do ML |
| N-16 | O `lost_me1_by_user` agora vem como **`warning`** (em 10/07 bloqueava o `validate` desta conta) | A decisão D7 (lista de falsos positivos) não é necessária hoje; decide-se pelo `type` que o ML manda |
| N-18 | **Contas de clientes (01/10, leitura do `/users/me` de 33 contas com token válido, autorizada pelo usuário):** as **33 são User Products** (zero no modelo antigo), e **23 das 33 têm `warehouse_management`** (várias também `multiwarehouse`). O perfil público (`GET /users/{id}` com app token) **não traz `tags`** — só o token da conta mostra o modelo. | O legado não tem conta para testar (H-26/TC-93 seguem abertos), e hoje nenhum cliente o usa. **RN-04 / V-VAR-20 ("bloquear `warehouse_management` na Fase 1") bloquearia ~70% dos clientes:** antes de decidir, conferir no `validate` de uma conta com a tag o que o ML exige no estoque. **[HIP]** |
| N-17 | `references` de atributo apontam o **índice no payload** (`item.attributes[16].values`) | O mapeador de erros precisa do payload enviado para achar o atributo — mais um motivo para guardá-lo (V11) |

### Catálogo de erros reais (base do dicionário do `09` §3)

Observados no `/items/validate` em 01/10/2026 (`conta/categorias/*/validate_*.json`). Alguns códigos diferem dos documentados (**3705**, não 3715, para título curto; **7711**, não 7710, para dígito verificador errado).

| `cause_id` | `code` | Tipo | Gatilho observado | `references` |
|---|---|---|---|---|
| 369 | `body.required_fields` | error | UP sem `family_name`; item sem `available_quantity` | `body` |
| — | `body.invalid_fields` (em `error`, sem `cause`) | error | UP com `title` | — |
| 374 | `body.invalid_fields` | error | UP com `variations` | `field.invalid` |
| 126 | `item.category_id.invalid` | error | Categoria que não é folha | `item.category_id` |
| 173 | `item.listing_type_id.requiresPictures` | error | Sem fotos | `item.listing_type_id`, `item.pictures` |
| 109 | `item.price.invalid` | error | Preço abaixo do `minimum_price` | `item.category_id` (!) |
| 462 | `item.family_name.length_invalid` | error | `family_name` acima do `max_title_length` | `item.family_name` |
| 3705 | `item.title.minimum_length` | error | `family_name` "Cadeira" | `item.title` |
| 100 | `field.constraint.violated` | error | N/A em obrigatório | — |
| 2516 | `error.item.attribute.business_conditional.value_name` | error | N/A em GENDER (camiseta) | `item.name` |
| 3510 | `invalid.item.attribute.values` | error | Texto livre em lista fechada | `item.name` |
| 3708 | `item.attribute.number_invalid_format` | error | `number_unit` sem unidade ou com unidade inválida (mensagem em pt com exemplo) | `item.attributes` |
| 344 | `item.attributes.normalizable.invalid` | error | Idem (acompanha o 3708) | `item.attributes` |
| 154 | `item.attributes.invalid_length` | error | Valor > 255 | `item.attributes` |
| 394 | `item.attribute.values.name.invalid` | error | Idem (acompanha o 154) | `item.attributes.values.name` |
| 7711 | `item.attribute.product_identifier.invalid_format` | error | GTIN com dígito verificador errado | `item.attributes[n].values` |
| 2610 | `missing.fashion_grid.grid_id.values` | error | Moda sem `SIZE_GRID_ID` (Fase 2) | `item.attributes` |
| 306 | `item.attributes.omitted` | warning | Embalagem sem unidade — **valor descartado** | `item.attributes` |
| 303 | `item.attributes.ignored` | warning | Atributo `read_only` enviado (GTIN da pastilha) | `item.attributes` |
| 3704 | `item.attribute.missing_catalog_required` | warning | Falta atributo `catalog_required` (exposição) | `item.attributes` |
| 2511 | `create.item.attribute.business_conditional` | warning | ML acrescenta `AGE_GROUP` sozinho (camiseta) | `item.attributes` |
| 350 | `item.shipping.mandatory_free_shipping` | warning | Preço ≥ R$ 79: o ML **liga** o frete grátis | — |
| 4053 | `shipping.lost_me1_by_user` | warning | Sempre, nesta conta | `user.shipping_preferences.modes` |
| 4056 | `shipping.lost_me2_by_catalog` | warning | Junto do 126 | `catalog.shipping_preferences.modes` |

| ID | Hipótese | Por que importa | Como validar | Decisão provisória |
|---|---|---|---|---|
| **H-01** | A conta dos prints já está no modelo **User Products** (a UI mostra uma variação única como card) | Define qual PayloadBuilder é o caminho principal | `GET /users/me` → tag `user_product_seller` | Implementar os dois builders; o caminho principal é definido pela tag |
| **H-02** | No UP do MLB, `title` **não** deve ser enviado (o ML gera a partir do `family_name`). Fontes oficiais divergem: a página de preço por variação diz "não enviar"; um FAQ diz que o integrador envia | Erro 400 ou título indesejado | `/items/validate` com e sem `title` numa conta UP | Flag `up_send_title` (padrão `false`); se o ML exigir, enviar `title = family_name` |
| **H-03** | Só é possível publicar em categoria folha | Validação V-CAT-01 | Tentar validar com uma categoria intermediária | Bloquear não folha |
| **H-04** | Recondicionado = `condition` (qual valor?) + atributo `ITEM_CONDITION` (id do valor "Recondicionado" no MLB) | Payload correto | `GET /categories/{id}/attributes` → procurar ITEM_CONDITION; validar | Mapeamento em configuração |
| **H-05** | `SELLER_PACKAGE_HEIGHT/WIDTH/LENGTH/WEIGHT` são exigidos em algumas situações (ME2); formato: inteiro, cm e g. Relatos de integradores citam os erros `missing.seller.package.dimensions` e `item.attribute.invalid.format.seller.package.dimensions` | Bloqueio na publicação | `/items/validate` sem e com dimensões, com `"12 cm"` e com `"12"` | Campos de embalagem na etapa E10, opcionais até a confirmação |
| **H-06** | Atributo **obrigatório** pode receber "Não se aplica"? O print mostra N/A em INMETRO, que não estava marcado como obrigatório | Regra V-ATT-06 | `/items/validate` com N/A num `required` | Não permitir N/A em obrigatório |
| **H-07** | Não há tag que indique "aceita valor customizado"; a regra deriva do `value_type` | Componentes de UI | Validar `value_name` livre em `string` com `values[]` | `list`/`boolean` = só id; os demais = livre |
| **H-08** | `/attributes/conditional` com variações: qual payload enviar? | Condicionais em variantes | Testar com payload legado com variações e com payload UP de uma variante | Legado: item completo; UP: primeira variante ativa |
| **H-09** | Endpoint `GET /categories/{id}/sale_terms` e o id de "Sem garantia" no MLB | Garantia | Sondagem | Valores de garantia em configuração |
| **H-10** | Limite de frete grátis obrigatório no MLB (fontes não oficiais citam R$ 79) | Exibição e regra | `shipping_options/free` com preços diferentes; tag `mandatory_free_shipping` | Não fixar valor; refletir a API |
| **H-11** | O aviso legal da categoria (NR/MTE) está disponível via API | UX | Procurar em `settings.restrictions` / `tags` | Ignorar na Fase 1 |
| **H-12** | Endpoint de preços de atacado / por quantidade (provável `/items/{id}/prices/...`) | Fase 2 | Abrir pt_br "Preços por quantidade" num navegador | Fora da Fase 1 |
| **H-13** | Compatibilidades de autopeças (veículos) têm API própria | Categorias de autopeças | Pesquisar a doc "compatibilidades" | Fora da Fase 1; categorias que exigem compatibilidade devem ser **detectadas** |
| **H-14** | "Você recebe" = preço − `sale_fee_amount` − `list_cost` | Preview | Comparar com o simulador do ML em 5 casos (o print bate: 150 − 16,50 − 62,35 = 71,15) | Exibir como "estimativa" |
| **H-15** | IDs de EMPTY_GTIN_REASON no MLB iguais aos da doc (17055158–61) | Payload | `/categories/{id}/attributes` | Ler sempre do schema; nunca fixar |
| **H-16** | Como obter o `domain_id` de uma categoria escolhida manualmente | Família UP / catálogo | Verificar se `/categories/{id}` traz `settings.catalog_domain` ou equivalente | Usar `catalog_domain` quando existir |
| **H-17** | Não há limite documentado de número de eixos | UI | Validar um payload com 3+ eixos | UI limita a 3 (configurável) |
| **H-18** | Chave de agrupamento da família UP: `family_name` + domínio + vendedor (+ marca/modelo?) | Famílias quebradas | Criar 2 itens de teste e conferir `family_id` | Manter todos os atributos PRODUCT idênticos |
| **H-19** | Busca de anúncios da conta por SKU (`/users/{id}/items/search?seller_sku=`) | Reconciliação e aviso de SKU duplicado | Sondagem | Implementar atrás de uma interface; fallback: anúncios recentes |
| **H-20** | Regra de fundo branco na capa vale para o MLB | Aviso V-IMG-09 | Doc pt_br de moderação de imagens | Só aviso |
| **H-21** | Na categoria de cadeiras, "Material" (do card "Azul / Couro") é eixo e tem `defines_picture`? | Grupos de imagem | Schema real | Seguir as tags |
| **H-22** | `picture_id` enviado via upload não expira | Reuso de uploads | Reusar um id antigo em `/items/validate` | Reenviar se der `picture_not_found` (204) |
| **H-23** | No UP, a descrição é por item (enviar para cada um) | Publicação | Criar a família de teste e conferir | Enviar para cada item |
| **H-24** | `/items/validate` devolve warnings em 204? | Exibição de avisos | Sondagem | Tratar warnings só na criação |
| **H-25** | `POST /items` não tem chave de idempotência | Duplicidade | Doc / suporte ML | Reconciliação por SKU |
| **H-26** | No legado com variações, `available_quantity` no nível do item deve ser omitido ou ser a soma | Payload | `/items/validate` | Enviar a soma |
| **H-27** | Semântica do campo `relevance` (1 = principal?) | Recomendados | Comparar com os grupos do technical_specs | Usar o grupo do technical_specs para destacar |
| **H-28** | Comportamento de `used_hidden` | UI para usados | Schema real | Ocultar quando condição = usado |

## Riscos de prazo (20/10/2026)

| Risco | Mitigação |
|---|---|
| A conta estar no UP e a Fase 1 focar no legado | Resolver H-01 no **dia 1** |
| Erros reais diferentes dos documentados | Dicionário de erros configurável + exibição da mensagem original |
| Categorias de moda e autopeças (tabela de medidas / compatibilidades) | Detectar e bloquear com mensagem; não tentar suportar parcialmente |
