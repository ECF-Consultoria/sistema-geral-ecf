---
task_id: 260807-e0p
slug: mes-ranking-para-detalhe
date: 2026-08-07
status: complete
commits:
  - 19f40236
---

# Resumo

> ⚠️ **A primeira versão foi ao ar QUEBRADA e o clique parou de navegar.**
> Ver "Incidente" no fim — a lição sobre verificação vale mais que a feature.

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

## Incidente — a v1 foi ao ar quebrada (mesmo dia)

**Sintoma:** clicar num profissional no ranking não fazia nada.

**Causa:** o helper `paramsDoMes()` foi declarado dentro de `PerformanceIndex`,
mas quem renderiza a linha clicável é **`RankingConsultoria`** — outro
componente de topo, outro escopo. O `onClick` lançava `ReferenceError` e a
navegação morria em silêncio. O `Show.jsx` nunca esteve quebrado (definição e
uso no mesmo componente), o que bate com o sintoma ser só no ranking.

**Correção:** o valor vai por **prop** (`mesDetalhe`), resolvido no
`PerformanceIndex` e declarado na assinatura do `RankingConsultoria`.

**Por que nada pegou — e é isso que interessa:**

1. **Não há ESLint neste projeto.** `no-undef` teria pego na hora de escrever.
2. **`npm run build` passa.** Bundler não é type-checker; identificador livre
   vira lookup de global e só falha em runtime, no clique.
3. **O grep no bundle buildado pareceu confirmação e era o contrário.** Eu
   assertei `performance.show",{user:s.id,...paramsDoMes()}` presente no
   `assets/Index-*.js` da VPS e li como prova de que funcionava. O nome
   `paramsDoMes` ter **sobrevivido literal à minificação** é justamente o sinal
   de que o esbuild não conseguiu resolvê-lo: identificador **ligado** é
   renomeado para nome curto. Consertado, o mesmo trecho ficou
   `performance.show",n?{user:s.id,mes:n}:{user:s.id}` — o `n` é a prova.

**Lição:** "o código novo está no bundle" só prova que o **deploy** funcionou,
nunca que a **mudança** funciona. São perguntas diferentes, e eu respondi a
segunda com evidência da primeira. Verificação de front que não exercita o
caminho do usuário não é verificação.

**Gate:** `tests/js/estrutura-performance-ranking.test.js` — não conta
ocorrências (gate falso da Fase 82): recorta `RankingConsultoria`, resolve os
identificadores que ele referencia contra os declarados em `PerformanceIndex`,
e falha **nomeando** o vazamento. Vale para qualquer reincidência, não só para
esta linha.

**A primeira versão do gate passava no código quebrado** — o regex que removia
acesso a propriedade (`\.\w+`) comia o identificador do spread `...paramsDoMes()`,
lendo o terceiro ponto como `.prop`. Corrigido com lookbehind `(?<!\.)`. Só
aceitei o gate depois de **reintroduzir o bug e ver o teste falhar** apontando
`paramsDoMes`. Gate que nunca foi visto falhando não é gate.

### Deploy do fix

**DEPLOYADO 260807** — `b08fa0ca..5a571e35`, `deploy.sh` com exit 0 e
"Pós-deploy OK" (rodado em **background** justamente por causa do timeout de
10 min registrado acima). VPS em `5a571e3`, workers RUNNING.

Conferido em produção pelo sinal certo desta vez: em `assets/Index-EZD2JqU3.js`
o handler é `performance.show",n?{user:s.id,mes:n}:{user:s.id}` — o `n` curto
prova identificador **ligado** — e `paramsDoMes` está **ausente de todo o
`public/build/assets/`**. Smoke: `/login` 200, `/performance` 302,
`/performance/20?mes=2026-06` 302.

**Sobre o log de erros:** o `grep -c production.ERROR` num `tail` cego não
serve de gate — a primeira leitura deu 0 só porque a janela caiu num intervalo
quieto, e a segunda deu 35 sem nada ter piorado. O que responde a pergunta é
recortar por **tempo**: zero `production.ERROR` após o corte do deploy
(13:43:54). O ruído é da API Adman (429/500) e de timeout de job, a 100–400/dia
na semana inteira — pré-existente e alheio a esta tarefa.

Falta a confirmação do usuário clicando na tela — é o único teste que exercita
o caminho real, e foi a sua ausência que deixou a v1 ir ao ar quebrada.
