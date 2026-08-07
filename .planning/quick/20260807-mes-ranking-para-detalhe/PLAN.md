---
task_id: 260807-e0p
slug: mes-ranking-para-detalhe
date: 2026-08-07
mode: quick
---

# Quick: mês selecionado acompanha o clique do ranking

## Problema

Em `/performance` o admin escolhe a competência no dropdown de mês
(`mes_selecionado`). Ao clicar num profissional, a linha navega para
`/performance/{user}` **sem query** — `resolveContextoPeriodo()` cai no ramo
default (mês em curso) e a tela de detalhe abre num mês diferente do que o
usuário acabou de selecionar. Ele precisa reescolher o mês no dropdown do Show.

O backend já aceita `?mes=YYYY-MM` em `show()` (mesmo helper
`resolveContextoPeriodo()` do ranking) — falta só o front propagar.

## Escopo

1. `resources/js/Pages/Performance/Index.jsx` — o clique na linha do ranking
   passa `?mes=YYYY-MM` para `performance.show`.
2. `resources/js/Pages/Performance/Show.jsx` — o botão "Ranking" (voltar) leva
   o mês de volta para `performance.index`, fechando o ida-e-volta.

## Decisão de borda

`?mes=` só é anexado quando o mês **não** é o corrente.

`MetricPeriodResolver::resolve()` trata `current_month` e `YYYY-MM` por ramos
diferentes: o primeiro é `mode=operational` com baseline de janela parcial
equivalente; o segundo é o ramo de mês nomeado. Mandar `?mes=2026-08` no mês
corrente trocaria o modo da tela sem o usuário ter pedido nada. Omitir no mês
em curso mantém o comportamento atual byte a byte e ainda entrega o que foi
pedido (o filtro só "existe" quando difere do default).

## Fora de escopo

- `?cargo=` e `?contexto=` — o Show não lê nenhum dos dois.
- Ranking de Polos/Publicação (`isPolos`) — não usa `performance.show`.

## Verificação

- `/performance?mes=2026-06` → clicar numa linha → URL `/performance/{id}?mes=2026-06`,
  dropdown do Show em junho/2026.
- `/performance` (mês em curso) → clicar → URL `/performance/{id}` sem query.
- No Show em junho/2026 → "Ranking" → `/performance?mes=2026-06`.
- `npm run build`.
