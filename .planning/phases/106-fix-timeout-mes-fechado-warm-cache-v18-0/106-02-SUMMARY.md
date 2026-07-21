---
phase: 106-fix-timeout-mes-fechado-warm-cache-v18-0
plan: 02
subsystem: backend — PerformanceController (gate quente/frio)
tags: [cache, degradacao-graciosa, tdd, performance, queue]
dependency-graph:
  requires:
    - "DesempenhoScoreService::isCached(User, Carbon): bool (Plan 106-01)"
    - "WarmDesempenhoCache --mes=/--user=* (Plan 106-01)"
  provides:
    - "PerformanceController::index gate quente/frio em periodo fechado (SC2/SC3)"
    - "prop Inertia 'aquecendo' + linhas de ranking com 'calculando'"
    - "dispatch sob-demanda de desempenho:warm-cache com lock anti-empilhamento"
  affects:
    - "Performance/Index.jsx (Plan 106-03) vai consumir calculando/aquecendo"
tech-stack:
  added: []
  patterns:
    - "Cache::add(lockKey, true, ttl) como lock anti-duplicação (retorna false se já existe)"
    - "Artisan::queue(comando, params) para dispatch assíncrono a partir de um controller HTTP"
key-files:
  created:
    - tests/Feature/Phase106/PerformanceControllerWarmDegradationTest.php
  modified:
    - app/Http/Controllers/PerformanceController.php
decisions:
  - "Gate isolado dentro do map() do ranking — só nos 2 ramos que chamavam computeCached() em periodo fechado; caminho de snapshot mensal e modo em curso ficam intocados (fronteira do plano)."
  - "Dispatch do warm via Artisan::queue fica FORA do map (após montar $usuariosFrios), evitando side-effect (fila) dentro de um callback puro de transformação de dados."
metrics:
  duration: "~50min"
  completed: "2026-07-21"
---

# Phase 106 Plan 02: Gate quente/frio no PerformanceController Summary

Ligou a degradação graciosa (SC2/SC3) no `PerformanceController::index`: em período
FECHADO, profissional sem cache pronto (`isCached`) deixa de pagar o `computeCached()`
síncrono (~14 users × chamadas Adman/ML → timeout) e vira uma linha placeholder
`calculando:true`; o warm sob-demanda é disparado em background com lock
anti-empilhamento. Modo em curso e caminho de snapshot mensal permanecem intocados.

## O que foi feito

### Task 1 — Gate quente/frio + placeholder `calculando` + prop `aquecendo`
- Inserido, dentro do `map()` que monta cada linha do ranking, um gate ANTES das
  2 chamadas a `computeCached()` que rodam em período fechado (fallback de snapshot
  sem `componentes` + ramo "mês em curso OU sem snapshot"): quando
  `$periodoResolvido['is_closed'] && ! isCached($u, $mesReferencia)`, coleta o
  `$u->id` em `$usuariosFrios` e devolve uma linha placeholder — `calculando:true`,
  `nota_final:null`, `faixa_bonus:null`, `score_status:'calculando'`, componentes
  todos `null` — sem nunca chamar `computeCached()`.
- Linhas normais (quentes, em curso, snapshot mensal existente) ganham
  `'calculando' => false` para o front distinguir os dois estados de forma explícita.
- Prop `'aquecendo' => ! empty($usuariosFrios)` exposta no payload Inertia.
- Teste: `tests/Feature/Phase106/PerformanceControllerWarmDegradationTest.php` —
  3 casos: frio (placeholder + zero compute, provado via `isCached()` continuar
  `false` após a requisição + `Http::preventStrayRequests()`), quente (nota real do
  cache, sem recomputar), em curso (gate não atua, `aquecendo=false`).
- Commit: `7b42412` — `feat(106-02): gate quente/frio no PerformanceController (SC2/SC3)`

### Task 2 — Dispatch do warm sob-demanda com lock anti-duplicação
- Após montar `$usuariosFrios` (fora do `map()`, antes do `reject`/`sort`): se
  não vazio, adquire `Cache::add('desempenho.warm.lock.'.$mes, true, 3min)` e,
  só se o lock foi adquirido (retorno `true` do `add`), dispara
  `Artisan::queue('desempenho:warm-cache', ['--mes' => 'YYYY-MM', '--user' => [...]])`.
  Requests seguintes dentro da janela de 3min encontram o lock já ocupado e não
  disparam 2º job — evita empilhar a cada poll de 6s do front (T-106-03).
- `$usuariosFrios` (query interna já filtrada) e `$mesReferencia` (já validado por
  `preg_match` no bloco de resolução de mês) são os únicos insumos passados ao
  `Artisan::queue` — nunca valor cru de request (T-106-04).
- Teste: estendido o mesmo arquivo — 3 casos: 1 job enfileirado com `--mes`/`--user`
  corretos (`Queue::fake()` + reflection em `QueuedCommand::$data`, já que é
  `protected`), 2º request idêntico não duplica (lock), zero frio → zero job.
- Commit: `b39b7ef` — `feat(106-02): dispatch do warm sob-demanda com lock anti-duplicacao`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug de teste] Teste do user frio (Task 1) rodava o warm SINCRONAMENTE**
- **Found during:** Task 2, GREEN (rodada completa da suíte após ligar o dispatch)
- **Issue:** `phpunit.xml` define `QUEUE_CONNECTION=sync` — sem `Queue::fake()`,
  `Artisan::queue()` executa o `QueuedCommand` IMEDIATAMENTE dentro do mesmo
  processo/requisição (não vai pra tabela `jobs`). O teste de Task 1
  (`test_modo_fechado_user_frio_retorna_placeholder_calculando`, escrito ANTES do
  dispatch existir) passou a rodar o `desempenho:warm-cache` de verdade dentro da
  própria requisição assim que o dispatch da Task 2 entrou em produção — o que
  aqueceria o cache do user frio ali mesmo e mascararia a prova de "zero compute
  na requisição" (a asserção `isCached === false` quebrou).
- **Fix:** Adicionado `Queue::fake()` no teste de Task 1, isolando a asserção de
  "zero compute" do dispatch (já coberto pelos 3 testes dedicados de Task 2).
  Em produção o comportamento é o desejado — `QUEUE_CONNECTION=database` (padrão
  do projeto) processa o job de verdade em background via `queue:work`; o
  `sync` é só do ambiente de teste.
- **Files modified:** `tests/Feature/Phase106/PerformanceControllerWarmDegradationTest.php`
- **Commit:** `b39b7ef`

Nenhum outro desvio — o gate/dispatch seguiram o plano à risca.

## Fronteira preservada (verificação da `<verification>`)

`git diff 9232738..HEAD -- app/Http/Controllers/PerformanceController.php` (baseline
= commit imediatamente anterior ao início desta sessão) mostra 4 hunks: 2 linhas de
`use` no topo do arquivo (Artisan/Cache) e 3 blocos TODOS dentro de `index()`
(construção do gate/placeholder, dispatch com lock, prop `aquecendo` no payload).
`dashboardCarteira`, `show`, `indexPolos`, `evolucao` e `contextoFiltro` permanecem
byte-a-byte idênticos.

## Verificação executada

- `PerformanceControllerWarmDegradationTest` — 6/6 verde (Task 1 + Task 2)
- `JanelaNpsBonusTest` (regressão v18/Fase 105 — nota do bônus) — 5/5 verde, sem
  regressão de números/régua
- `PerformanceIndexMetadadosTest` (payload dos 6 metadados de elegibilidade do
  ranking) — 4/4 verde
- `PerformanceCargoFilterTest` — 5 falhas / 1 passa, IDÊNTICO ao estado
  pré-existente documentado no `106-01-SUMMARY.md` (falha por `sem_carteira=true`
  — o ramo legado do `CarteiraContextService` exige `contratos_servico` ativo, que
  esse teste antigo não cria; **não introduzido nem agravado por este plano** —
  confirmado comparando a mesma contagem de falhas antes/depois desta sessão)

## Self-Check: PASSED

- `app/Http/Controllers/PerformanceController.php` — FOUND
- `tests/Feature/Phase106/PerformanceControllerWarmDegradationTest.php` — FOUND
- Commit `7b42412` — FOUND
- Commit `b39b7ef` — FOUND
