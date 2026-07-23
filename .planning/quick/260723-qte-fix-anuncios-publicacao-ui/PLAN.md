---
tipo: quick
slug: fix-anuncios-publicacao-ui
data: 2026-07-23
status: em-progresso
---

# Fix publicação /mlb/anuncios (erro 369) + 3 ajustes de UI

## Problema

Publicar anúncio **com variação** falhava no ML:

```
POST /items 400
code: body.required_fields (cause_id 369)
references: ["body.variations[0]"]
message: The variations[0] does not contains some or none of the
         following properties [attribute_combinations, available_quantity, price]
```

O front (`montarPayload()`) omitia `price` de cada variação de propósito (para não
disparar o erro 357 `item.variations.price.different`), mas essa categoria/conta
**exige** `price` dentro de cada variação → erro 369.

## Escopo (4 itens)

1. **Preço por variação** — `resources/js/Pages/Mlb/AnunciarML.jsx` (`montarPayload`):
   incluir `price` = preço do item em cada variação. Todas herdam o MESMO valor →
   mata o 369 sem reabrir o 357.

2. **Tipo de anúncio** — o endpoint `tiposDeAnuncio()` devolve a lista completa do ML
   (`/sites/MLB/listing_types`): Premium, Diamante, Clássico, Ouro, Prata, Bronze,
   Grátis. Filtrar no front para só **Clássico (gold_special)** e **Premium (gold_pro)**.

3. **Características secundárias** — remover o selo "opcional" e abrir a seção por
   padrão (não deixar oculta). Não rotular como obrigatória, mas tratar como se fosse
   (visível).

4. **Aviso de fotos** — remover o texto "As fotos deste anúncio vêm das variações…
   Não é preciso imagem principal aqui." (quando há variações, só não mostra o campo
   de URL da imagem principal).

## Verificação

- `npm run build` sem erros.
- Publicar (localhost) um anúncio com variação → sem erro 369.
- Dropdown "Tipo de anúncio" mostra só Clássico e Premium.
- Seção "Características secundárias" abre por padrão, sem selo "opcional".
- Etapa 5 sem o aviso de fotos.

## Sem deploy — parar após build/validação local.
