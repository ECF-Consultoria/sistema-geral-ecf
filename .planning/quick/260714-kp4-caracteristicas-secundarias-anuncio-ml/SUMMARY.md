---
id: 260714-kp4
slug: caracteristicas-secundarias-anuncio-ml
date: 2026-07-14
status: complete
---

# Resumo — Características secundárias no wizard de /mlb/anuncios

## O que foi feito

Adicionada a seção **"Características secundárias"** na etapa 1 (Ficha técnica) do
wizard `/mlb/anuncios`, espelhando a seção homônima do Mercado Livre. Recolhida por
padrão, opcional, não bloqueia a publicação.

## Mudanças (só frontend — `resources/js/Pages/Mlb/AnunciarML.jsx`)

- Import de `ChevronDown`.
- `useState secundariasAbertas` (colapso, default fechado).
- `useMemo opcionais`: `!required && !allow_variations && !hidden && !read_only`,
  exclui `SIZE_GRID_ID`/`*GRID*`, `CATALOG_PRODUCT_ID`, `GTIN`, `SELLER_SKU`.
- `useMemo opcionaisPreenchidos`: contador exibido no cabeçalho da seção.
- Bloco colapsável abaixo dos obrigatórios, reusando `Campo` + o mesmo select/input.

## Backend

Nenhuma alteração. `MlCatalogoMetaService::atributos()` já devolve todos os atributos;
`montarPayload` já varre `valores` genericamente → os opcionais preenchidos entram no
payload sozinhos.

## Verificação

- `npm run build` verde (`AnunciarML-WDlFYYEn.js` compilou, 16.37s).

## Pendências

- NÃO deployado (aguarda autorização).
- Validação em runtime na conta de teste ficou pendente (build OK; sem OAuth local).
