# 07 — Regras de negócio (E)

Lista consolidada. Cada regra tem um ID estável para ser citada em código, testes e mensagens. Os detalhes estão nos arquivos indicados.

## Conta e modelo

| ID | Regra | Etiqueta |
|---|---|---|
| RN-01 | Toda publicação usa um token válido; o `refresh_token` é de uso único e o novo deve ser salvo de forma atômica a cada renovação | [ML·S17] |
| RN-02 | O modelo de publicação é decidido pela tag `user_product_seller` da conta, consultada no início do rascunho **e** imediatamente antes de publicar | [ML·S8][ARQ] |
| RN-03 | Se a conta tem `user_product_seller`, é proibido enviar `variations[]` | [ML·S8] |
| RN-04 | Contas com `warehouse_management` não usam `available_quantity` (bloqueado na Fase 1) | [ML·S20] |

## Categoria e atributos

| ID | Regra | Etiqueta |
|---|---|---|
| RN-10 | Publicar apenas em categoria com `listing_allowed = true` | [ML·S2/S11] |
| RN-11 | Publicar apenas em categoria folha | [HIP·H-03] |
| RN-12 | O formulário é derivado do CategorySchema; nenhum atributo de categoria é campo fixo | [ARQ] |
| RN-13 | Obrigatório = `required` ∪ (`new_required` ∧ condição nova) ∪ (`conditional_required` ∧ devolvido por `/attributes/conditional`) | [ML·S3] |
| RN-14 | Atributos `read_only`, `inferred` e `fixed` nunca são enviados pelo Publicador | [ML·S3] |
| RN-15 | "Não se aplica" = `value_id "-1"`, `value_name null`; não permitido em eixos de variação | [ML·S3] |
| RN-16 | Atributo `list`/`boolean` só aceita `value_id` de `values[]` | [ML·S3] |
| RN-17 | `number_unit` é enviado como `"<número> <unidade>"`, com unidade de `allowed_units` | [ML·S3] |
| RN-18 | Valor de atributo ≤ `value_max_length` (máx. 255, erro 154) | [ML·S3/S11] |
| RN-19 | Marca real ou "Genérica"; marcas restritas exigem autorização | [ML·S5/S10] |
| RN-20 | Atributos sugeridos por inferência ficam marcados para revisão até a confirmação do usuário | [ARQ] |
| RN-21 | A troca de categoria reaproveita só os atributos existentes e válidos no novo schema e lista ao usuário o que foi descartado | [ARQ] |
| RN-22 | O schema tem cache de 24h; antes de publicar, um schema vencido é rebuscado e, se mudou, o rascunho é revalidado | [ARQ] |

## Condição

| ID | Regra | Etiqueta |
|---|---|---|
| RN-30 | `condition` ∈ `settings.item_conditions` | [ML·S2] |
| RN-31 | "Recondicionado" é representado pelo atributo `ITEM_CONDITION`, não por um valor de `condition`; exige garantia ≥ 90 dias | [ML·S10][HIP·H-04 para o mapeamento exato] |
| RN-32 | Usados em moda/esportes: estoque = 1 | [ML·S10] |

## Variações

| ID | Regra | Etiqueta |
|---|---|---|
| RN-40 | Sempre existe ≥ 1 variante; produto simples = variante `__single__` | [ARQ] |
| RN-41 | Eixos só entre atributos `allow_variations`, mais no máximo 1 eixo customizado | [ML·S7] |
| RN-42 | As combinações são o produto cartesiano dos valores; combinações inexistentes são desativadas, não apagadas | [ARQ] |
| RN-43 | A combinação é identificada pela chave canônica; duplicatas são impossíveis | [ML·S7][ARQ] |
| RN-44 | Legado: variantes ativas ≤ `max_variations_allowed` | [ML·S7] |
| RN-45 | Legado: preço igual em todas as variantes ativas | [ML·S7] |
| RN-46 | UP: um item por variante ativa, todos com o mesmo `family_name` e os mesmos atributos de produto | [ML·S8][ARQ] |
| RN-47 | Estoque de variante ativa: inteiro de 1 a 99.999 | [ML·S19][ARQ] |
| RN-48 | SKU (`SELLER_SKU`) obrigatório e único no rascunho; `seller_custom_field` não é usado como SKU | [ML·S7][ARQ] |
| RN-49 | GTIN por variante; quando exigido condicionalmente, GTIN ou EMPTY_GTIN_REASON | [ML·S4] |
| RN-50 | Uma única variante ativa (legado) é publicada como item simples | [ARQ] |
| RN-51 | Atributo usado como eixo não tem valor no nível do produto | [ARQ] |

## Imagens

| ID | Regra | Etiqueta |
|---|---|---|
| RN-60 | Toda variante ativa precisa de ≥ 1 foto | [ML·S7] |
| RN-61 | Variantes com o mesmo valor de um eixo `defines_picture` têm exatamente as mesmas fotos (garantido pela atribuição por grupo) | [ML·S3/S7][ARQ] |
| RN-62 | Grupo `defines_picture` sem foto própria bloqueia a publicação | [ARQ] |
| RN-63 | Limites de fotos lidos da categoria; excedente bloqueia (não é cortado em silêncio) | [ML·S2][ARQ] |
| RN-64 | JPG/PNG, ≤ 10 MB, lados ≥ 500 px | [ML·S9] |
| RN-65 | Upload prévio via `/pictures/items/upload`; payloads referenciam ids | [ML·S9][ARQ] |
| RN-66 | Fotos do grupo vêm antes da galeria geral na lista de cada variante | [ARQ] |

## Título e descrição

| ID | Regra | Etiqueta |
|---|---|---|
| RN-70 | Título/family_name ≤ `max_title_length`; `family_name` ≤ 120 | [ML·S2/S8] |
| RN-71 | O título não contém contato, frete, parcelamento, condição, estoque ou símbolos | [ML·S10][MAT] |
| RN-72 | Descrição em texto puro, enviada após a criação do item via `POST /items/{id}/description` | [ML·S13/S10] |
| RN-73 | No UP, a mesma descrição é enviada para cada item da família | [ARQ][HIP·H-23] |

## Condições de venda

| ID | Regra | Etiqueta |
|---|---|---|
| RN-80 | O tipo de anúncio deve estar entre os disponíveis para a conta e a categoria | [ML·S19] |
| RN-81 | Preço ≥ `minimum_price` da categoria | [ML·S11] |
| RN-82 | Envio `me2` somente se habilitado na conta e na categoria | [ML·S15] |
| RN-83 | Frete grátis obrigatório acima do limite: respeitar o que a API indicar; nunca fixar o limite no código | [ML·S15][HIP·H-10] |
| RN-84 | Garantia via `sale_terms` (WARRANTY_TYPE + WARRANTY_TIME com unidade) | [ML·S10] |
| RN-85 | Tarifas e custo de frete são sempre consultados na API; nunca percentuais fixos | [ML·S14/S15][ARQ] |

## Publicação

| ID | Regra | Etiqueta |
|---|---|---|
| RN-90 | Publicar apenas com validação L1–L3 sem bloqueantes na revisão atual do rascunho | [ARQ] |
| RN-91 | Os payloads da Revisão e da Publicação vêm do mesmo montador (o que se vê é o que se envia) | [ARQ] |
| RN-92 | `ml_item_id` é gravado imediatamente após cada criação bem-sucedida | [ARQ] |
| RN-93 | Nunca reenviar um item com status `UNKNOWN` sem reconciliação por SKU | [ARQ] |
| RN-94 | Falha parcial no UP não desfaz os itens criados; a retentativa envia só os pendentes | [ARQ] |
| RN-95 | Não há sandbox: testes criam anúncios reais. Usar conta de teste e título "Item de teste - Não ofertar", e pausar/fechar após o teste | [ML·S12] |
