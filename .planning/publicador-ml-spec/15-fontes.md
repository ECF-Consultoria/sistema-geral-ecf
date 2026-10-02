# 15 — Fontes

Pesquisa feita em 01/10/2026. Várias páginas pt_br de `developers.mercadolivre.com.br` retornaram 403 ao acesso automatizado; nesses casos foram usadas as versões en_us do mesmo site ou as páginas equivalentes de outros sites do ML (Argentina, México, Global Selling).

A data é a "última atualização" exibida na página, quando havia.

| Código | Fonte | Data | Usada para |
|---|---|---|---|
| S1 | https://developers.mercadolivre.com.br/en_us/categories-attributes · https://developers.mercadolivre.com.br/en_us/set-categories-for-products | — | `domain_discovery` (preditor de categoria) |
| S2 | https://developers.mercadolivre.com.br/pt_br/categorias-e-atributos | 01/04/2025 | Árvore de categorias, `settings` |
| S3 | https://developers.mercadolivre.com.br/en_us/attributes · https://developers.mercadolibre.com.ar/es_ar/atributos | 29/08/2023 · 23/05/2024 | Tags, `value_type`, N/A, condicionais, technical_specs |
| S4 | https://developers.mercadolivre.com.br/en_us/product-identifiers | — | GTIN, EMPTY_GTIN_REASON, erros 7710/7711/7810 |
| S5 | https://developers.mercadolivre.com.br/en_us/catalog-required-listings · https://developers.mercadolibre.com.ar/en_us/catalog-listing · https://developers.mercadolivre.com.br/en_us/catalog-eligibility | 11/01/2024 · — · 13/01/2023 | Catálogo, marcas restritas |
| S6 | https://developers.mercadolivre.com.br/en_us/size-guide · https://developers.mercadolivre.com.br/pt_br/primeiros-pasos | 09/01/2024 · 25/05/2023 | Tabela de medidas |
| S7 | https://developers.mercadolivre.com.br/en_us/variations · https://global-selling.mercadolibre.com/devsite/variations-global-selling | 21/12/2022 · 12/12/2025 | Variações legado, preço igual, limites, SKU, `defines_picture` |
| S8 | https://developers.mercadolivre.com.br/en_us/user-products · https://developers.mercadolibre.com.ar/en_us/price-per-variation · https://global-selling.mercadolibre.com/devsite/user-products-cbt | 26/08/2024 · 26/08/2024 · 06/02/2026 | User Products, `family_name`, tags de conta |
| S9 | https://developers.mercadolivre.com.br/en_us/working-with-pictures · https://developers.mercadolibre.com.ar/en_us/image-moderation | 02/02/2024 · 03/10/2023 | Upload, especificações e moderação de imagem |
| S10 | https://developers.mercadolibre.com.ar/en_us/list-products | 09/09/2024 | Campos do item, condição, garantia, título, descrição separada |
| S11 | https://developers.mercadolivre.com.br/en_us/validations · https://global-selling.mercadolibre.com/devsite/validations-cbt | 23/08/2024 · 13/02/2025 | Formato de erro, cause_ids |
| S12 | https://developers.mercadolivre.com.br/en_us/listing-validator | 04/06/2023 | `POST /items/validate`; ausência de sandbox |
| S13 | https://developers.mercadolivre.com.br/en_us/item-description-2 | 21/12/2022 | Descrição em texto puro |
| S14 | https://developers.mercadolivre.com.br/en_us/fees-for-listing | — | `listing_prices` |
| S15 | https://developers.mercadolibre.com.mx/en_us/management-of-shippin-fees · https://developers.mercadolivre.com.br/en_us/mercadoenvios-mode-2 | 30/12/2025 · — | Frete grátis, `shipping_options/free`, ME2 |
| S16 | https://developers.mercadolivre.com.br/en_us/products-sync-listings | — | Status e sub_status |
| S17 | https://developers.mercadolivre.com.br/en_us/authentication-and-authorization | — | OAuth, refresh de uso único |
| S18 | https://developers.mercadolivre.com.br/en_us/products-receive-notifications | 23/06/2026 | Notificações |
| S19 | https://developers.mercadolivre.com.br/en_us/listing-types-item-upgrades-tutorial · https://global-selling.mercadolibre.com/devsite/listing-types-and-exposures | — | Tipos de anúncio, estoque máximo |
| S20 | https://developers.mercadolibre.com.ar/en_us/multi-origin-stock | 20/12/2024 | `warehouse_management` |

## Fontes não oficiais (somente como indício → [HIP])

| Código | Fonte | Data | Usada para |
|---|---|---|---|
| S21 | Artigos de integradores: ANYMARKET, UpSeller, E-commerce na Prática, Bling | 17/06/2026 · 20/04/2025 · 01/09/2025 · 28/10/2025 | Rollout do User Products no Brasil em ondas, sem data de obrigatoriedade |
| S22 | Ideris (relato de erro de dimensões de embalagem); issue pública no GitHub sobre o formato de SELLER_PACKAGE_*; CNN Brasil (limite de frete grátis de R$ 79, 2021) | 29/05/2026 · — · 2021 | H-05, H-10 |

## Materiais do usuário [MAT]

| Código | Material |
|---|---|
| M1 | Briefing (texto) |
| M2 | Print "Etapa 1 de 3" — busca no catálogo |
| M3 | Print "Etapa 1 de 3" — confirmação de categoria (Cadeiras para Escritório) |
| M4 | Print do bloco Título |
| M5 | PDF Etapa 2 — Dados do produto (01/10/2026 14:30) |
| M6 | PDF Etapa 3 — Condições de venda (01/10/2026 14:32) |
