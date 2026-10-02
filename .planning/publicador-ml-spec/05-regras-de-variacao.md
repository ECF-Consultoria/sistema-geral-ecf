# 05 — Regras de variação (F)

## 1. Conceitos

| Termo | Definição | Exemplo |
|---|---|---|
| **Eixo** | Atributo pelo qual o produto varia. Precisa ter a tag `allow_variations` [ML·S7] | Cor, Tamanho |
| **Valor de eixo** | Um valor possível do eixo | Preto, Branco, Azul |
| **Combinação** | Um valor escolhido de **cada** eixo | Preto / P |
| **Variante** | A combinação mais seus dados próprios (preço, estoque, SKU, GTIN, fotos) | Preto / P, R$ 89,90, 10 un., SKU CAM-PT-P |

O usuário define **eixos e valores**; o sistema **gera** as combinações. O usuário nunca digita combinações à mão.

## 2. Geração das combinações [ARQ]

```
combinações = produto cartesiano(valores do eixo 1 × valores do eixo 2 × … × valores do eixo n)
```

| Eixos | Valores | Combinações |
|---|---|---|
| 0 | — | 1 (variante `__single__`) |
| 1 (Cor) | Preto, Branco, Azul | 3 |
| 2 (Cor × Tamanho) | 3 × 3 | 9 |
| 3 (Cor × Tamanho × Voltagem) | 3 × 3 × 2 | 18 |

- A ordem das combinações segue a ordem dos eixos (`position`) e dos valores. É estável: o primeiro eixo varia mais devagar.
- **Combinações que não existem na realidade** (ex.: Azul só existe em P e M) são **desativadas** (`enabled = false`), não apagadas. Assim, se o usuário reativá-las, os dados digitados voltam.
- **Limite de explosão** (legado): o número de variantes **ativas** deve ser ≤ `max_variations_allowed` (em geral 100; 250 em Moda, Acessórios de Celular e Autopeças) [ML·S7]. A UI avisa **antes** de gerar quando o produto cartesiano passa do limite, e permite gerar e desativar o excedente.
- **Número de eixos:** não há limite oficial documentado (**H-17**). O limite prático é o número de atributos `allow_variations` da categoria, mais 1 eixo customizado. [ARQ]: a UI permite até 3 eixos por padrão (configurável).

## 3. Chave canônica e unicidade [ARQ]

**Normalização de um valor:**

```
normalize(v) =
  se v tem value_id:  "id:" + value_id
  senão:              "txt:" + lower(remove_acentos(colapsa_espaços(trim(value_name))))
```

**Chave da combinação:**

```
combination_key = join("|", para cada eixo ordenado por attribute_id (customizado = "~custom"):
                              attribute_id + "=" + normalize(valor))
```

Exemplo: `COLOR=id:52049|SIZE=txt:m`

**Por que ordenar por `attribute_id` e não pela posição:** mudar a ordem visual dos eixos não muda a identidade da combinação.

**Garantias:**

1. Dentro de um eixo, não pode haver dois valores com o mesmo `normalize` ("M", " m ", "m" são o mesmo valor). A segunda inserção é rejeitada com mensagem.
2. `unique(draft_id, combination_key)` no banco.
3. Um valor de lista escolhido por `value_id` e o mesmo texto digitado como customizado (ex.: id 52049 "Preto" e "preto" livre) são **conflito**. A UI deve sugerir o `value_id` quando o texto livre bater com um `values[].name` da lista (comparação normalizada). Assim se evita duplicidade semântica.

## 4. Regeneração quando os eixos mudam [ARQ]

| Mudança | Efeito |
|---|---|
| Adiciona valor a um eixo | Novas chaves são criadas (`enabled = true`, dados vazios); as existentes ficam intactas |
| Remove valor de um eixo | Variantes com esse valor ficam `orphaned = true` e `enabled = false`; dados preservados até salvar/descartar |
| Adiciona eixo novo | **Todas** as chaves mudam. Oferecer ao usuário: "copiar os dados de cada variante antiga para as novas que a contêm" (ex.: Preto → Preto/P, Preto/M, Preto/G), ou começar vazio |
| Remove eixo | Chaves colapsam (Preto/P, Preto/M → Preto). Se houver conflito de dados (preços ou estoques diferentes), a UI mostra e o usuário escolhe; estoque **não** é somado automaticamente |
| Variante com `ml_item_id` / `ml_variation_id` (já publicada) | **Nunca** apagar no rascunho; marcar órfã e exigir ação explícita (edição pós-publicação está fora da Fase 1) |

## 5. O que pertence ao produto e o que pertence à variante

| Dado | Produto | Variante | Fonte |
|---|---|---|---|
| Categoria, condição, tipo de anúncio, envio, garantia, descrição | ✔ | | [ML·S10] |
| Título / family_name | ✔ (único para a família) | | [ML·S8][ARQ] |
| Atributos PRODUCT (Marca, Modelo, ficha técnica) | ✔ | | [ML·S3] |
| Valores dos eixos (Cor, Tamanho) | | ✔ | [ML·S7] |
| SKU (`SELLER_SKU`) | | ✔ | [ML·S7] |
| GTIN / EMPTY_GTIN_REASON | | ✔ | [ML·S4/S7] |
| Outros `variation_attribute` | | ✔ | [ML·S3] |
| Estoque | | ✔ | [ML·S7/S8] |
| Preço | | ✔ (legado: igual em todas) | [ML·S7/S8] |
| Fotos | galeria geral | grupo de imagem | `06` |

**Regra de família no UP [ARQ]:** todos os itens da família compartilham **exatamente** os mesmos atributos PRODUCT, o mesmo `family_name`, a mesma categoria e a mesma condição. Só os eixos e os VARIANT_DATA diferem. Isso evita que o ML separe a família: mudar BRAND ou MODEL pode mover o item para outra família [ML·S8]. A chave exata de agrupamento é **H-18**.

## 6. Preço

| Modelo | Regra |
|---|---|
| Legado | Todas as variantes ativas com **o mesmo preço**; o ML exibe e cobra o maior [ML·S7]. A UI mostra **um** campo de preço e propaga o valor; a validação L2 bloqueia se houver diferença (que só acontece por erro). |
| UP | Preço por variante [ML·S8]. A UI mostra o preço na grade de variantes com ação "aplicar a todas". |

Para os dois: decimal com 2 casas, > 0, ≥ `settings.minimum_price` (erro 109) e < 9.999.999.999 (erro 129) [ML·S11].

## 7. Estoque

- Inteiro ≥ 0, ≤ 99.999 para `gold_special` / `gold_pro` [ML·S19].
- **0 numa variante ativa: bloqueado [ARQ].** O ML só cria com estoque 0 em fulfillment/Flex, e o item nasce pausado [ML·S10]. Para "não vender esta combinação", desative a variante.
- Usados em moda/esportes: estoque deve ser 1 [ML·S10].
- Contas com tag `warehouse_management`: estoque por depósito, **sem** `available_quantity` [ML·S20]. Na Fase 1, bloquear com mensagem.

## 8. SKU [ML·S7 + ARQ]

- Enviado como atributo **`SELLER_SKU`** da variante (no legado, em `variations[].attributes`; no UP, em `attributes` do item). `seller_custom_field` fica para uso interno: não usar como SKU [ML·S7].
- **Obrigatoriedade [ARQ]:** obrigatório no Publicador, mesmo que o ML não exija, porque é a chave de reconciliação e idempotência (`09` §5).
- **Unicidade:** bloqueante dentro do rascunho; aviso se já existir outro anúncio ativo da conta com o mesmo SKU (L3, via busca da conta, **H-19**).
- **Geração sugerida (opcional):** `<SKU-base>-<sigla eixo 1>-<sigla eixo 2>`, editável.

## 9. Mapeamento para a API

### Legado [ML·S7]

- Os eixos vão em `variations[].attribute_combinations` como `{id, value_id}` (lista) ou `{id, value_name}` (livre). Eixo customizado vai como `{name, value_name}`, sem `id`.
- VARIANT_DATA vai em `variations[].attributes`.
- Atributos PRODUCT vão em `attributes` do item.
- `picture_ids` por variação; `pictures` do item = união (ver `06`).
- **Produto sem eixos:** item **sem** `variations`; `price` e `available_quantity` no item; SKU e GTIN em `attributes` do item.
- **Uma única combinação ativa** com eixos [ARQ]: publicar como item simples, com os valores dos eixos como atributos do item. O ML já converte monovariantes em itens simples no Global Selling [ML·S7/GS]; padronizar evita ambiguidade.

### User Products [ML·S8]

- Um `POST /items` por variante ativa.
- Cada item recebe: os atributos PRODUCT, os valores dos eixos como `attributes` normais (eixo customizado: `{name, value_name}`), os VARIANT_DATA, o próprio `price`, `available_quantity` e `pictures`, e o **mesmo `family_name`**.
- **Sem `variations`.**
- Produto sem eixos: 1 item com `family_name`.

## 10. Validações de variação

| ID | Regra | Severidade | Etiqueta |
|---|---|---|---|
| V-VAR-01 | Todo eixo deve ser um atributo `allow_variations` da categoria (ou o único eixo customizado) | BLOCKER | [ML·S7] |
| V-VAR-02 | No máximo 1 eixo customizado; nome não pode coincidir com `name` ou `id` de atributo da categoria | BLOCKER | [ML·S7] |
| V-VAR-03 | Valores repetidos (normalizados) no mesmo eixo | BLOCKER | [ARQ] |
| V-VAR-04 | `combination_key` duplicada | BLOCKER | [ML·S7] |
| V-VAR-05 | Toda variante ativa tem valor para **todos** os eixos | BLOCKER | [ML·S7] |
| V-VAR-06 | Nenhum eixo com N/A (`-1`) | BLOCKER | [ML·S3] |
| V-VAR-07 | Valor de eixo do tipo `list` deve existir em `values[]` | BLOCKER | [ML·S3] |
| V-VAR-08 | Nº de variantes ativas ≤ `max_variations_allowed` (legado) | BLOCKER | [ML·S7] |
| V-VAR-09 | ≥ 1 variante ativa | BLOCKER | [ARQ] |
| V-VAR-10 | Atributo eixo não pode ter valor no nível do produto | BLOCKER | [ARQ] |
| V-VAR-11 | Legado: preços iguais em todas as variantes ativas | BLOCKER | [ML·S7] |
| V-VAR-12 | Estoque inteiro de 1 a 99.999 em variante ativa | BLOCKER | [ML·S19][ARQ] |
| V-VAR-13 | SKU presente e único no rascunho | BLOCKER | [ARQ] |
| V-VAR-14 | GTIN válido (GS1) quando informado; GTIN ou EMPTY_GTIN_REASON quando exigido | BLOCKER | [ML·S4] |
| V-VAR-15 | Mesmo GTIN em duas variantes diferentes | WARNING | [ARQ] |
| V-VAR-16 | Toda variante ativa tem ≥ 1 foto resolvida | BLOCKER | [ML·S7] |
| V-VAR-17 | Variante órfã pendente de decisão | BLOCKER | [ARQ] |
| V-VAR-18 | Eixo obrigatório (`required` + `allow_variations`) não escolhido como eixo e sem valor no produto | BLOCKER | [ML·S3] |
| V-VAR-19 | UP: `family_name` idêntico e atributos PRODUCT idênticos em todos os itens | BLOCKER | [ARQ] |
| V-VAR-20 | Conta com `warehouse_management` | BLOCKER (Fase 1) | [ML·S20] |

## 11. Combinações inválidas — resumo

Uma combinação é inválida se:

- repete outra (V-VAR-04);
- tem eixo sem valor (V-VAR-05);
- usa N/A (V-VAR-06);
- usa um valor fora da lista (V-VAR-07);
- é órfã (V-VAR-17);
- ou, na categoria de tabela de medidas, o tamanho não corresponde a uma linha da tabela (Fase 2) [ML·S6].

Combinação **desativada** não é inválida: ela simplesmente não é publicada.
