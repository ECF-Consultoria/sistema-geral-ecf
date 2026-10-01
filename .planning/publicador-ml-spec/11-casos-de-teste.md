# 11 — Casos de teste (J)

## Convenções

- **Modelo:** `L` = legado, `UP` = User Products, `A` = ambos (rodar duas vezes, uma com cada PayloadBuilder).
- **Nível:**
  - `U` = unitário. Funções puras: classificação, combinações, grupos de imagem, montador, validações L1/L2.
  - `I` = integração com a API do ML mockada a partir das **fixtures reais da Fase 0**.
  - `E` = ponta a ponta com conta de teste (anúncio real, depois fechado).
- O **montador de payload deve ser testável sem rede**: dado `(draft, schema, account_model)`, ele retorna um `PayloadPlan` determinístico. Usar snapshots de JSON.

**Fixtures sugeridas:**

| Fixture | Conteúdo |
|---|---|
| `schema_cadeira` | Categoria do print; principais BRAND/MODEL; numéricos `number_unit`; booleanos |
| `schema_furadeira` | VOLTAGE `allow_variations` sem `defines_picture` |
| `schema_camiseta` | COLOR `allow_variations` + `defines_picture`; SIZE + tabela de medidas |
| `schema_gtin_condicional` | GTIN `conditional_required` |

---

## 1. Produto e variações

| ID | Cenário | Dados | Resultado esperado | Modelo | Nível |
|---|---|---|---|---|---|
| TC-01 | **Produto sem variações** | `schema_cadeira`, 0 eixos, preço 150, estoque 1, SKU CAD, GTIN válido, 2 fotos gerais | 1 variante `__single__`. L: 1 payload **sem** `variations`, SKU e GTIN em `attributes`. UP: 1 payload com `family_name`, sem `variations` | A | U |
| TC-02 | **Uma dimensão** | `schema_furadeira`, eixo VOLTAGE {127V, 220V} | 2 variantes. L: `variations` com 2 `attribute_combinations` de 1 elemento, preços iguais. UP: 2 payloads, mesmo `family_name`, VOLTAGE diferente | A | U |
| TC-03 | **Duas dimensões** | `schema_camiseta` (sem tabela de medidas no mock), COLOR {Preto, Branco, Azul} × SIZE {P, M, G} | 9 variantes, ordem Preto/P, Preto/M, Preto/G, Branco/P… Chaves únicas | A | U |
| TC-04 | **Três dimensões** | COLOR(2) × SIZE(3) × eixo customizado "Estampa"(2) | 12 variantes; o eixo customizado sai como `{name, value_name}` | A | U |
| TC-05 | Combinação desativada | TC-03 com Azul/G desativada | 8 variantes no payload; Azul/G mantida no banco com dados | A | U |
| TC-06 | **Variação duplicada (valor)** | Adicionar "M" e depois " m " no eixo SIZE | O segundo valor é rejeitado com V-VAR-03; nenhuma combinação nova | A | U |
| TC-07 | **Variação duplicada (lista × texto)** | COLOR com `value_id` Preto e o texto livre "preto" | A UI sugere o id; ao forçar, V-VAR-03 bloqueia | A | U |
| TC-08 | Explosão de combinações | 15 cores × 8 tamanhos = 120 com `max_variations_allowed = 100` | Aviso antes de gerar; V-VAR-08 bloqueia até restarem ≤ 100 ativas (L). No UP, não se aplica (só aviso de N chamadas) | A | U |
| TC-09 | Adicionar eixo preservando dados | TC-02 com preços e estoques preenchidos; adicionar eixo COLOR {Preto} | Opção "copiar dados": 127V/Preto herda os dados de 127V | A | U |
| TC-10 | Remover valor com variante publicada | Variante com `ml_item_id`; remover o valor | A variante vira órfã; não é apagada; V-VAR-17 bloqueia até a decisão | A | U |
| TC-11 | Dois eixos customizados | Criar "Estampa" e "Acabamento" customizados | V-VAR-02 bloqueia o segundo | A | U |
| TC-12 | Eixo customizado com nome de atributo existente | Customizado "Cor" numa categoria que tem COLOR | V-VAR-02 bloqueia, sugerindo usar COLOR | A | U |
| TC-13 | Atributo que vira eixo | COLOR preenchido como Azul no produto; depois COLOR vira eixo | O valor Azul é movido para o eixo; não resta linha PRODUCT (V-VAR-10) | A | U |
| TC-14 | Preço diferente no legado | 2 variantes com 150 e 165 | V-VAR-11 BLOCKER no L; válido no UP | A | U |
| TC-15 | Uma única variante ativa no legado | Eixo COLOR com 2 valores, 1 desativado | Payload simples sem `variations`, COLOR em `attributes` (RN-50) | L | U |

## 2. Estoque e SKU

| ID | Cenário | Dados | Resultado esperado | Modelo | Nível |
|---|---|---|---|---|---|
| TC-20 | **Estoque inválido: negativo** | -1 | V-VAR-12 (L1) | A | U |
| TC-21 | Estoque inválido: decimal | 2,5 | V-VAR-12 | A | U |
| TC-22 | Estoque zero numa variante ativa | 0 | V-VAR-12 com a mensagem "desative a variante" | A | U |
| TC-23 | Estoque acima do limite | 100000 | V-VAR-12 | A | U |
| TC-24 | SKU repetido | Duas variantes com SKU "CAD" | V-VAR-13 | A | U |
| TC-25 | SKU vazio | — | V-VAR-13 | A | U |

## 3. Atributos

| ID | Cenário | Dados | Resultado esperado | Modelo | Nível |
|---|---|---|---|---|---|
| TC-30 | **Atributo obrigatório faltando** | `schema_cadeira` sem MODEL | V-ATT-01 aponta "Modelo" na etapa E3 | A | U |
| TC-31 | Obrigatório numérico com unidade | "Altura do encosto" = 23, unidade vazia | Usa `default_unit`; payload `"23 cm"` | A | U |
| TC-32 | **Atributo condicional** | `schema_gtin_condicional`; mock `/attributes/conditional` → `[GTIN]`; sem GTIN | V-ATT-08 BLOCKER; ao informar EMPTY_GTIN_REASON = "Outro", libera | A | I |
| TC-33 | Condicional reavaliado | Mudar BRAND depois de resolvido o condicional | Nova chamada ao endpoint (debounce); resultado associado à nova revisão | A | I |
| TC-34 | `new_required` × usado | Atributo `new_required` vazio com condition = used | Não bloqueia; mudar para new → bloqueia | A | U |
| TC-35 | **Atributo inválido: valor fora da lista** | `list` com `value_id` inexistente | V-ATT-03 | A | U |
| TC-36 | Atributo inválido: unidade não permitida | "60 pol" quando `allowed_units` = cm, m | V-ATT-04 | A | U |
| TC-37 | Atributo inválido: texto longo | 300 caracteres | V-ATT-05 | A | U |
| TC-38 | GTIN com checksum errado | 7896553367646 | V-VAR-14 (L1) | A | U |
| TC-39 | "Não se aplica" em opcional | INMETRO N/A | Payload `{"id":…,"value_id":"-1","value_name":null}` | A | U |
| TC-40 | "Não se aplica" em eixo | Tentar N/A em COLOR que é eixo | A UI não oferece; via API interna → V-VAR-06 | A | U |
| TC-41 | `read_only` no payload | O schema tem um atributo `read_only` com valor vindo de catálogo | O montador **não** inclui o atributo | A | U |
| TC-42 | Plausibilidade | Altura máxima 22 < encosto 23 (dados do print) | V-ATT-11 WARNING, não bloqueia | A | U |
| TC-43 | Recondicionado | condition = refurbished, garantia 30 dias | V-CND-02 BLOCKER; com 90 dias, payload com `ITEM_CONDITION` | A | U |

## 4. Imagens

| ID | Cenário | Dados | Resultado esperado | Modelo | Nível |
|---|---|---|---|---|---|
| TC-50 | **Múltiplas imagens** | 0 eixos, 8 fotos gerais, `max_pictures_per_item = 12` | Ordem preservada; L: `pictures` com 8; UP: idem | A | U |
| TC-51 | Excesso de imagens | 13 fotos com limite 12 | V-IMG-07 / V-IMG-06 BLOCKER, listando as excedentes | A | U |
| TC-52 | **Imagens por variação** (`defines_picture`) | TC-03; Preto: 2 fotos, Branco: 1, Azul: 1; geral: 1 | Preto/P, Preto/M e Preto/G com `picture_ids` idênticos [Preto1, Preto2, Geral1]; L: união com 5 ids, ordem: Preto1, Preto2, Branco1, Azul1, Geral1 | A | U |
| TC-53 | **Variação sem imagem** (grupo `defines_picture` vazio) | TC-52 sem fotos para Azul | V-IMG-05 BLOCKER: "Adicione ao menos 1 foto para a cor Azul" | A | U |
| TC-54 | Variação sem imagem própria e sem `defines_picture` | TC-02 (voltagem); só galeria geral | Válido: as duas variantes recebem a galeria geral | A | U |
| TC-55 | Sem nenhuma foto | 0 fotos | V-IMG-04 BLOCKER | A | U |
| TC-56 | Imagem pequena | 400×600 | V-IMG-03 BLOCKER | A | U |
| TC-57 | Formato inválido | .webp | V-IMG-01 BLOCKER | A | U |
| TC-58 | Imagem duplicada | Mesmo arquivo enviado 2 vezes | Um `image_asset` (sha256); atribuições múltiplas permitidas | A | U |
| TC-59 | Falha de upload | Mock `/pictures/items/upload` → 400 | `upload_status = failed`; V-IMG-08 bloqueia a publicação; mensagem por foto | A | I |
| TC-60 | Fotos por variante sem `defines_picture` | TC-02 + toggle "fotos por variante"; 127V com 1 foto, 220V sem foto, geral vazia | 220V → V-IMG-04 BLOCKER | A | U |

## 5. Categoria

| ID | Cenário | Dados | Resultado esperado | Modelo | Nível |
|---|---|---|---|---|---|
| TC-70 | **Categoria com estrutura diferente** | O mesmo fluxo com `schema_cadeira`, `schema_furadeira` e `schema_camiseta` | Formulários diferentes, gerados pelo mesmo código; nenhuma condição `if category ==` no código | A | U |
| TC-71 | Troca de categoria | Rascunho completo da cadeira → trocar para outra categoria que tem BRAND/MODEL e não tem "Altura do encosto" | BRAND/MODEL mantidos como `migrated` (a revisar); os demais descartados e listados; combinações regeneradas se os eixos deixaram de ser elegíveis | A | U |
| TC-72 | Categoria não folha | Escolher uma categoria com filhos | V-CAT-01 | A | U |
| TC-73 | `listing_allowed = false` | Mock | V-CAT-02 | A | U |
| TC-74 | Categoria exige tabela de medidas | `schema_camiseta` real | V-CAT-04 com a mensagem da Fase 2 | A | U |
| TC-75 | Schema mudou entre o rascunho e a publicação | Fixture com hash diferente | Revalidação automática; a publicação só segue sem bloqueantes | A | I |

## 6. Erros da API e publicação

| ID | Cenário | Dados | Resultado esperado | Modelo | Nível |
|---|---|---|---|---|---|
| TC-80 | **Erro retornado pela API** | Mock `POST /items` → 400 com `item.attributes.missing_required` ([MODEL]) | `publication_item = FAILED`; issue mapeada para o campo Modelo; mensagem em PT; resposta bruta salva | A | I |
| TC-81 | Erro em variação (índice) | Mock 400 com `references` `item.variations[2]...` | Mapeado para a 3ª variante **do payload** (não do banco) via mapa índice → `variant_id` | L | I |
| TC-82 | Warning na criação | 201 com `cause` warning 382 | Item `CREATED` + warning visível | A | I |
| TC-83 | Falha parcial UP | 3 variantes; a 2ª retorna 400 | Status `PARTIALLY_PUBLISHED`; itens 1 e 3 `CREATED` com `ml_item_id`; "retentar" reenvia só a 2ª | UP | I |
| TC-84 | Timeout após o envio | `POST /items` sem resposta | `UNKNOWN`; reconciliação por SKU encontra o item → adota o id, **sem** reenviar | A | I |
| TC-85 | Timeout sem item criado | Igual, mas a busca por SKU volta vazia | Reenvio único | A | I |
| TC-86 | 429 | Mock 429, 429, 201 | Backoff e sucesso; 3 tentativas registradas | A | I |
| TC-87 | Token expirado | Mock 401 → refresh → 201 | Refresh 1 vez, com lock; o novo refresh_token é salvo | A | I |
| TC-88 | `invalid_grant` | Refresh falha | Conta marcada para reconexão; publicação `FAILED` com mensagem clara | A | I |
| TC-89 | Modelo da conta mudou | O rascunho foi validado como L; na publicação a conta tem `user_product_seller` | Aborta; volta à revisão com os payloads UP | A | I |
| TC-90 | Envio de `variations` em conta UP | Forçar o builder L numa conta UP (teste negativo) | Impedido pela guarda RN-03 antes da chamada | UP | U |
| TC-91 | Falha na descrição | Item 201; descrição 400 | Item `CREATED`, `description_status = FAILED`, ação de reenvio; item não recriado | A | I |
| TC-92 | `/items/validate` 204 | Payload válido | V-REM-01 ok; publicação liberada | A | I |
| TC-93 | Ponta a ponta legado | Conta de teste sem UP, produto TC-03 reduzido | Anúncio criado, status lido e fechado ao final | L | E |
| TC-94 | Ponta a ponta UP | Conta UP, 2 cores | 2 itens com o mesmo `family_id` (`GET /user-products/{id}`) | UP | E |

## 7. Título e condições de venda

| ID | Cenário | Resultado esperado | Nível |
|---|---|---|---|
| TC-100 | Título com 61 caracteres (limite 60) | V-TIT-01 | U |
| TC-101 | Título "Cadeira frete grátis" | V-TIT-02 WARNING | U |
| TC-102 | UP com `family_name` > 120 | V-TIT-01 (e o erro 462 se passar) | U |
| TC-103 | Tipo de anúncio não disponível | V-SAL-01 | I |
| TC-104 | Preço abaixo do mínimo da categoria | V-SAL-03 | U |
| TC-105 | Simulação do print: 150, `gold_special`, frete 62,35, tarifa 16,50 | "Você recebe" = 71,15 | U |
