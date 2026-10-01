# 01 — Visão geral (A)

## 1. O problema

O Publicador atual trata a publicação como **um formulário**. A publicação no Mercado Livre é na verdade um **processo dependente da categoria**, com três níveis de dados que se condicionam:

1. **Categoria**: define quais atributos existem, quais são obrigatórios, quais podem virar variação, quais definem imagem, limites de fotos e de título, tipos de anúncio e modos de envio.
2. **Estrutura de variação**: define quantos anúncios ou variações serão gerados e quais dados ficam no nível do produto ou da variação.
3. **Modelo de publicação da conta** (legado ou User Products): define como tudo isso vira chamadas à API.

Hoje esses níveis estão misturados. O resultado é:

- campos fixos que não existem em certas categorias;
- variações tratadas como listas soltas ("Cor: Preto/Branco") em vez de combinações;
- imagens sem vínculo determinístico com as combinações;
- validação só pelo erro da API;
- payload montado de forma ad hoc.

## 2. Objetivo

Reconstruir a lógica de publicação para que ela seja:

| Requisito | Como esta especificação garante |
|---|---|
| Estruturada | Etapas com contrato de entrada/saída (`02`) e entidades próprias (`04`) |
| Previsível | O mesmo rascunho gera sempre o mesmo payload (montador puro, sem efeitos colaterais) |
| Orientada pela categoria | *CategorySchema* dinâmico (`03`) |
| Correta com e sem variações | Modelo único de *eixos → combinações → variantes* (`05`) |
| Correta para imagens por variação | *Grupos de imagem* derivados de `defines_picture` (`06`) |
| Validada antes de publicar | 3 camadas: local, remota (`/attributes/conditional`, `/items/validate`) e revisão (`08`) |
| Compatível com a API | Estratégias de payload separadas por modelo (abaixo) |
| Fácil de evoluir | Regras de categoria vêm de dados; extensões entram como novos módulos |

## 3. Princípios de arquitetura [ARQ]

1. **Schema-driven.** Nenhum atributo de categoria vira coluna no banco ou campo fixo no código. Valores de atributos são linhas `(attribute_id, value_id, value_name, unit, escopo)`.
2. **Um modelo interno, duas saídas.** O rascunho sempre tem *produto + N variantes* (N ≥ 1; produto simples = 1 variante sem eixos). Um *PayloadBuilder* por modelo de publicação converte isso em chamadas.
3. **Snapshot do schema.** O rascunho registra a versão (hash) do schema da categoria usado. Antes de publicar, o schema é revalidado; se mudou, o rascunho é revalidado.
4. **Invalidação em cascata.** Mudar algo a montante (categoria, condição, eixos) invalida o que depende disso, de forma explícita e visível ao usuário (ver `02`).
5. **Validação local antes da remota, remota antes de publicar.** A API do ML é a autoridade final, mas o usuário não deve descobrir erros óbvios só no momento de publicar.
6. **Publicação idempotente e rastreável.** Cada tentativa guarda payload, resposta e IDs gerados. Retentativas não criam anúncios duplicados.

## 4. Os dois modelos de publicação do Mercado Livre

Este é o ponto mais importante para o desenho do código.

### 4.1 Modelo legado (anúncio com `variations[]`)

- Um anúncio (`POST /items`) contém um array `variations`. Cada variação tem `attribute_combinations`, `available_quantity`, `price`, `picture_ids` e `attributes` próprios (SELLER_SKU, GTIN) **[ML·S7]**.
- **O preço precisa ser igual em todas as variações**; o ML exibe e cobra o maior **[ML·S7]**.
- Limite de variações por anúncio: `max_variations_allowed`, em geral 100; 250 em Moda, Acessórios para Celular e Autopeças **[ML·S7]**.
- Título enviado pelo integrador.

### 4.2 Modelo User Products (UP, "preço por variação")

- Cada variação é um **anúncio independente** (`POST /items` por variação), com preço, estoque, fotos e atributos próprios **[ML·S8]**.
- Os anúncios de uma mesma "família" compartilham o campo **`family_name`**; o ML calcula o `family_id` e exibe os anúncios como seletores na mesma página de produto **[ML·S8]**.
- **Não é permitido enviar `variations[]`**; o envio retorna 400 **[ML·S8, GS]**.
- `family_name` pode ser alterado só enquanto não há vendas **[ML·S8]**. Tamanho máximo: 120 caracteres e ≤ `max_title_length` do domínio **[ML·S8]**.
- **Título**: as fontes oficiais divergem sobre enviá-lo ou deixar o ML gerar a partir do `family_name` → **H-02**.
- Estoque fica no *User Product* (`user_product_id`, prefixo MLBU), preço fica no item **[ML·S8]**.

### 4.3 Como decidir o modelo

| Sinal | Significado | Fonte |
|---|---|---|
| `GET /users/{seller_id}` → `tags` contém `user_product_seller` | Novos anúncios **devem** usar UP | [ML·S8] |
| Item com tag `user_product_listing` ou `family_name != null` | Anúncio já é UP | [ML·S8] |
| Tag `warehouse_management` | Estoque por depósito; não usar `available_quantity` | [ML·S20, AR] |

Situação no Brasil: a doc oficial (08/2024) diz "ativação progressiva no Brasil em 2025". Integradores (2025–2026) relatam ativação em ondas por vendedor e categoria **[HIP·S21]**. **Não há data de obrigatoriedade confirmada.** O print da Etapa 2 (variação única exibida como card) sugere que a conta usada já está no UP (**H-01**).

**Decisão [ARQ]:** o modelo é **detectado por conta** a cada publicação (consultando as tags) e gravado no snapshot da publicação. O usuário não escolhe o modelo. Os dois PayloadBuilders são implementados na Fase 1.

## 5. Visão do processo

```
Conta (token, tags, modelo, preferências de envio)
   │
Identificação (opcional: catálogo / GTIN)
   │
Categoria ──► CategorySchema (settings + atributos com tags + technical_specs + sale_terms)
   │
Características principais + Condição ──► (recalcula obrigatórios: new_required, condicionais)
   │
Estrutura de variação (eixos = atributos allow_variations)
   │
Combinações / Variantes (preço, estoque, SKU, GTIN por variante)
   │
Imagens (galeria geral + grupos definidos por defines_picture)
   │
Título / family_name
   │
Ficha técnica (demais atributos, condicionais, N/A, regulatória)
   │
Descrição
   │
Condições de venda (preço, tipo de anúncio, envio, garantia) + simulação de tarifas
   │
Validação (local → /attributes/conditional → /items/validate)
   │
Revisão
   │
Publicação (upload de fotos → POST /items ×1 ou ×N → POST description → registro)
   │
Pós-publicação (status, revisão/moderação, notificações)
```

O detalhamento e as dependências reais (que não são estritamente lineares) estão em `02-fluxo-de-publicacao.md`.

## 6. Fora de escopo da Fase 1 [ARQ]

- Publicação via catálogo (`catalog_listing: true`): detectar e informar.
- Tabela de medidas (moda): detectar e bloquear com mensagem.
- Preços de atacado: endpoint não confirmado (**H-12**).
- Compatibilidades de autopeças: API própria, não pesquisada aqui (**H-13**).
- Edição de anúncio já publicado: requer regras próprias (por exemplo, no legado um PUT sem os ids das variações **apaga** as variações omitidas **[ML·S7]**).
- Estoque multidepósito (`warehouse_management`).
