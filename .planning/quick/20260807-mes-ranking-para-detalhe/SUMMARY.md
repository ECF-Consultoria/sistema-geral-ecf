---
task_id: 260807-e0p
slug: mes-ranking-para-detalhe
date: 2026-08-07
status: complete
commits:
  - 19f40236
---

# Resumo

O mês escolhido no dropdown de `/performance` agora acompanha o clique no
profissional, e volta junto pelo botão "Ranking".

## O que mudou

- `resources/js/Pages/Performance/Index.jsx` — helper `paramsDoMes()`; o
  `router.visit` da linha do ranking passou de `route('performance.show', u.id)`
  para `route('performance.show', { user: u.id, ...paramsDoMes() })`.
- `resources/js/Pages/Performance/Show.jsx` — `voltarAoRanking()` substitui o
  `router.visit(route('performance.index'))` cru do botão "Ranking".

Zero mudança de backend: `PerformanceController::show()` já resolvia `?mes=`
pela mesma `resolveContextoPeriodo()` do ranking (o contrato de período é
compartilhado desde a Fase 1 do plano de otimização de 2026-07-21). O que
faltava era só o front propagar.

## A decisão que importa

`?mes=` **só** é anexado quando o mês não é o corrente.

`MetricPeriodResolver::resolve()` despacha `current_month` e `YYYY-MM` por
ramos distintos: o primeiro é `mode=operational` com baseline de janela
parcial equivalente; o segundo é o ramo de mês nomeado, com `is_closed`
derivado de `fim do mês < hoje`. Mandar `?mes=2026-08` estando em agosto
resolveria pelo segundo ramo e trocaria o modo/baseline da tela sem o usuário
ter pedido nada. Omitindo no mês em curso, o comportamento default fica
idêntico ao de antes e o param só aparece quando de fato representa uma
escolha.

Mesma regra dos dois lados (ida e volta), para o par de telas não divergir.

## Fora de escopo

- `?cargo=` e `?contexto=` do ranking — a `Show` não lê nenhum dos dois.
- Ranking de Polos/Publicação (`isPolos`) — aquele ramo não navega para
  `performance.show`.

## Gates

- `npm run build` verde; `Pages/Performance/Index.jsx` e `Show.jsx` presentes
  no `public/build/manifest.json`.
- Sem migration, sem rota nova, sem mudança de autorização.

**NÃO deployado.**
