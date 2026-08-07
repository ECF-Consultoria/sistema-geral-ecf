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

## Deploy

**DEPLOYADO 260807** — deploy isolado `ece12384..f1461653` (push FF + `deploy.sh`),
sem migrations. Saiu isolado porque a VPS já estava em `ece12384` = `origin/main`.

`deploy.sh` estourou o timeout de 10 min do lado do cliente, mas **completou** —
o `chown -R www-data:www-data` sobre a árvore inteira consumiu ~9 min entre o
`view:cache` (13:18:52 UTC) e o `supervisorctl restart` (~13:27:27 UTC), que é a
última linha do script. Timeout do cliente não é sinal de deploy incompleto:
verificar sempre pelo estado da VPS, nunca pelo stdout que morreu junto.

Conferido em produção por reconsulta:

- HEAD `f146165`; caches `config.php`/`routes-v7.php` regravados às 13:18:52.
- Workers `ecf-worker_00`/`_01` **RUNNING** (a prova de que a última linha rodou);
  árvore em `www-data:www-data`.
- Bundle **buildado na VPS** (`assets/Index-DHwlQcGP.js`) contém
  `performance.show",{user:s.id,...paramsDoMes()}` e o padrão antigo
  (`performance.show",<var>.id`) está **ausente**; `assets/Show-DrtDKRJF.js`
  contém `performance.index",r&&!i?.is_current_month?{mes:r}:{}`.
- Smoke: `/login` 200, `/performance` 302, `/performance/20?mes=2026-06` 302
  (redirect ao login sem sessão — nenhum 500). Zero `production.ERROR` novo.
