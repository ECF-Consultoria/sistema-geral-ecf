---
tipo: quick
slug: fix-anuncios-publicacao-ui
data: 2026-07-23
status: complete
---

# Resumo — Fix publicação /mlb/anuncios (erro 369) + 3 ajustes de UI

Tudo em `resources/js/Pages/Mlb/AnunciarML.jsx` (wizard individual). Build OK.

## 1. Preço por variação (bug de publicação — erro 369)

`montarPayload()` omitia `price` de cada variação (para evitar o erro 357
`item.variations.price.different`), mas o ML **exige** `price` dentro de cada variação
em várias categorias/contas → `POST /items` recusava com **369 body.required_fields**
(`variations[0] does not contains ... [attribute_combinations, available_quantity, price]`).

**Fix:** cada variação passa a enviar `price` = preço do item. Como todas herdam o
MESMO valor, não reabre o 357. Mata o 369.

O caminho "Em massa" (grade) monta produto simples sem `variations` — não afetado.

## 2. Tipo de anúncio — só Clássico e Premium

O endpoint `tiposDeAnuncio()` devolve a lista completa do ML (`/sites/MLB/listing_types`:
Premium, Diamante, Clássico, Ouro, Prata, Bronze, Grátis). O front agora **filtra** para
só `gold_special` (Clássico) e `gold_pro` (Premium) — os únicos que usamos.

## 3. Características secundárias — sem "opcional", aberta por padrão

- Removido o selo "opcional".
- `secundariasAbertas` nasce `true` (seção visível por padrão, não oculta). Continua
  colapsável, mas tratada como se fosse obrigatória (sem rotular como tal).

## 4. Aviso de fotos removido

Removido o texto "As fotos deste anúncio vêm das variações… Não é preciso imagem
principal aqui." Quando há variações, apenas não mostra o campo de URL da imagem principal.

## Verificação

- `npm run build` ✓ (AnunciarML recompilado, sem erros).
- Falta o usuário validar no localhost: publicar anúncio com variação (sem 369),
  conferir dropdown de tipo (2 opções), seção secundárias aberta sem selo, etapa 5 sem aviso.

## Deploy

NÃO deployado (aguarda autorização + validação local).
