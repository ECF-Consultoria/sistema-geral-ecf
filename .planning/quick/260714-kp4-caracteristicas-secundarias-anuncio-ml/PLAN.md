---
id: 260714-kp4
slug: caracteristicas-secundarias-anuncio-ml
date: 2026-07-14
status: complete
---

# Características secundárias no wizard de /mlb/anuncios

## Objetivo

O wizard de "Anunciar Mercado Livre" só exibia os atributos **obrigatórios** da
categoria (Ficha técnica). O Mercado Livre também oferece a seção "Características
secundárias" (atributos opcionais/complementares) — que não existia no nosso módulo.

## Escopo

Só frontend — o backend (`MlCatalogoMetaService::atributos()` +
`MlbAnuncioController::atributos()`) já retorna **todos** os atributos da categoria
sem filtrar; o wizard é que só consumia os `required`.

## Tarefas

1. `import` do ícone `ChevronDown` (lucide-react).
2. `useState` `secundariasAbertas` (recolhido por padrão).
3. `useMemo` `opcionais` — filtra atributos NÃO obrigatórios, NÃO de variação,
   NÃO grade de moda, NÃO ocultos/read-only, excluindo CATALOG_PRODUCT_ID / GTIN /
   SELLER_SKU (já tratados em outros lugares). + contador `opcionaisPreenchidos`.
4. Seção colapsável **não-bloqueante** dentro da etapa 1 (Ficha técnica), abaixo dos
   obrigatórios, reusando o mesmo componente `Campo` + select/input.

Os valores preenchidos entram no payload automaticamente: `montarPayload` varre
`Object.entries(valores)` genericamente — nenhuma mudança no backend nem no payload.

## Arquivos

- `resources/js/Pages/Mlb/AnunciarML.jsx` (único)
