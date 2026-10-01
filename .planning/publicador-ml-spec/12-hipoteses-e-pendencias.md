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

- **Parte da conta (0b):** pendente. Conta de teste definida pelo usuário: empresa **#459 "Dev 02 Testes API"**. Os tokens só se descriptografam na produção, então a 0b roda lá.

| ID | Status | Evidência | Decisão |
|---|---|---|---|
| H-01 | **Pendente (0b)** | — | — |
| H-02 | **Pendente (0b)** | Cenários `base_legado` / `base_up` / `base_title_e_family` | — |
| H-03 | **Parcial** | O pai de cada folha tem `listing_allowed=false` e filhos (`pai_*.json`) | Bloquear categoria que não é folha; o erro do `validate` sai na 0b (`categoria_nao_folha`) |
| H-04 | **Parcial** | `ITEM_CONDITION` (lista, `hidden`) existe nas 4 categorias, mas o valor **Recondicionado (2230582)** só aparece em cadeira e furadeira. Camiseta tem só Novo/Usado e pastilha só Novo. | Oferecer recondicionado **só quando o valor existir** no schema. Qual `condition` acompanha: 0b (`recondicionado`) |
| H-05 | **Pendente (0b)** | Cenários `sem_embalagem` / `embalagem_sem_unidade` | Indício de 10/07: `"500 g"` e `"15 cm"` passaram |
| H-06 | **Pendente (0b)** | Cenário `na_em_obrigatorio`. INMETRO (`INMETRO_CERTIFICATION_REGISTRATION_NUMBER`) **não é obrigatório** na cadeira — o N/A do print está num opcional. | — |
| H-07 | **Resolvida pelos dados** | `technical_specs/input` traz `ui_config.allow_custom_value` por componente (`COMBO` true ou false; `TEXT_INPUT` false) | Com `values[]` e `allow_custom_value=false` → só `value_id`. Com `true` → id ou texto livre. Sem `values[]` → texto livre. |
| H-08 | **Pendente (0b)** | `conditional_base_legado` / `conditional_variacoes_legado_soma` | — |
| H-09 | **Confirmada** | `GET /categories/{id}/sale_terms` existe. `WARRANTY_TYPE` é lista: **2230280** Garantia do vendedor, **2230279** Garantia de fábrica, **6150835** Sem garantia. `WARRANTY_TIME` é `number_unit` em dias/meses/anos. Igual nas 4 categorias. | Ler sempre do schema; mandar `value_id` |
| H-10 | **Pendente (0b)** | `shipping_options_free_{50,78_99,79,150}` | — |
| H-11 | **Confirmada (não existe)** | `settings.restrictions` e `settings.tags` vazios na cadeira | Ignorar na Fase 1 |
| H-13 | **Sem sinal** | `catalog_domains.compatibilities` = `[]` até na pastilha de freio. `HAS_COMPATIBILITIES` é `read_only`. | Continua fora da Fase 1. Como detectar a exigência fica em aberto. |
| H-14 | **Parcial** | `listing_prices` da cadeira, a R$ 150 no Clássico, dá **16,50 (11%)**, o mesmo do print. Premium: 21,00 (14%). | O `list_cost` do frete sai na 0b |
| H-15 | **Confirmada** | `EMPTY_GTIN_REASON` com **17055158–17055161** em cadeira, furadeira e camiseta. A pastilha nem tem o atributo (GTIN é `read_only`). | Ler do schema |
| H-16 | **Confirmada** | `settings.catalog_domain` traz o domínio (`MLB-OFFICE_CHAIRS`…), igual ao `domain_id` do `domain_discovery`. Também existe `GET /catalog_domains/{id}`. | Usar `settings.catalog_domain` |
| H-17 | **Pendente (0b)** | Cenário `variacoes_eixo_customizado` (eixo da categoria + `{name, value_name}`) | — |
| H-19 | **Indício forte** | `/users/{id}/items/search?seller_sku=` já roda em produção no "Importar" do Mapeamento (learnings do portal §27). Forma da resposta: 0b. | — |
| H-21 | **Confirmada** | Na cadeira, `UPHOLSTERY_MATERIAL` tem `allow_variations` **e** `defines_picture`, assim como `COLOR`. O "Azul / Couro" do print é Cor × Material. | O grupo de imagem é o par Cor+Material quando os dois são eixos |
| H-24, H-26 | **Pendente (0b)** | `variacoes_legado_{soma,zero,sem_qtd}` | — |
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
