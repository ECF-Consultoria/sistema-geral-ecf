---
phase: 106-fix-timeout-mes-fechado-warm-cache-v18-0
plan: 01
subsystem: backend — cache/warm de Desempenho
tags: [cache, artisan-command, tdd, performance]
dependency-graph:
  requires: []
  provides:
    - "DesempenhoScoreService::isCached(User, Carbon): bool"
    - "WarmDesempenhoCache aquece 2 alvos (mes corrente + last_closed_month) + options --mes/--user"
  affects:
    - "PerformanceController (Plan 106-02) vai consumir isCached() pra decidir quente/frio"
tech-stack:
  added: []
  patterns:
    - "Cache::has() reusando a MESMA chave pública de cacheKey() — nunca duplicar convenção de chave"
    - "Validação de --mes por regex + range explícito (Carbon::createFromFormat não rejeita overflow de mês)"
key-files:
  created:
    - tests/Unit/DesempenhoScoreServiceCacheTest.php
    - tests/Feature/Phase106/WarmDesempenhoCacheTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - app/Console/Commands/WarmDesempenhoCache.php
decisions:
  - "Validação de --mes: regex captura o mês em grupo E valida range 1-12 explicitamente, porque Carbon::createFromFormat('Y-m','2026-13') faz overflow silencioso pra '2027-01' em vez de lançar exception — o regex sozinho (\\d{2}) não pega isso."
metrics:
  duration: "~35min"
  completed: "2026-07-21"
---

# Phase 106 Plan 01: Warm de 2 alvos + wrapper isCached Summary

Estendeu `WarmDesempenhoCache` para aquecer mês corrente + último mês fechado numa única execução, e adicionou `DesempenhoScoreService::isCached()` como gancho de "está pronto?" sem custo de compute() — as duas peças de backend puro que o Plan 106-02 (degradação graciosa no controller) vai consumir.

## O que foi feito

### Task 1 — Wrapper `isCached()` no `DesempenhoScoreService`
- Adicionado `public function isCached(User $user, Carbon $mes): bool` logo após `cacheKey()` — reusa a MESMA chave pública (`Cache::has($this->cacheKey(...))`), NUNCA chama `compute()`/`computeCached()`.
- `git diff` confirma que o service ganhou SÓ este método — `compute`, `computeCached`, `cacheKey` e toda a régua de cálculo permaneceram intocados.
- Teste: `tests/Unit/DesempenhoScoreServiceCacheTest.php` — 5 casos: cache vazio (false), cache populado via `Cache::put` na chave de `cacheKey()` pra mês corrente e mês fechado (true), e prova de zero-compute (`Http::preventStrayRequests()` SEM fake não estoura ao chamar `isCached` num user frio, cobrindo os dois formatos de chave `current_month`/`YYYY-MM`).
- Commit: `cd6ffac` — `feat(106-01): wrapper isCached() no DesempenhoScoreService`

### Task 2 — `WarmDesempenhoCache` aquece 2 alvos + options
- Injetado `MetricPeriodResolver` no construtor.
- `handle()`: sem `--mes`, monta 2 alvos — `Carbon::now()->startOfMonth()` (comportamento original) + `last_closed_month` resolvido via `MetricPeriodResolver` (nunca fixado — recalculado a cada execução, evitando o Pitfall 1 do research: rollover de mês na janela sem cron).
- `--mes=YYYY-MM`: aquece SÓ a competência pedida, sem tocar o mês corrente. Validação dupla — regex de formato E range explícito do mês (1-12), porque `Carbon::createFromFormat('Y-m', '2026-13')` faz overflow silencioso para `2027-01` em vez de lançar exceção (achado durante o RED — não estava no plano original, mas é consequência direta do comportamento documentado do `<action>` da task).
- `--user=*` (array): filtro trocado de `where('id', (int)$id)` para `whereIn('id', array_map('intval', (array)$ids))` — array vazio é falsy, preserva o comportamento agendado sem `--user`.
- Loop de `$users` aninhado dentro de `foreach ($mesesAlvo as $mesReferencia)`, mantendo try/catch, `Log::warning` e contadores OK/FAIL por (user, mês).
- Teste: `tests/Feature/Phase106/WarmDesempenhoCacheTest.php` — 5 casos: 2 alvos aquecidos p/ todos os users elegíveis (chaves distintas via `isCached`), `--mes` isola a competência, `--user` restringe aos IDs pedidos, `--mes` inválido (formato) e `--mes` fora de range (`2026-13`) retornam `FAILURE` sem 500.
- Commit: `bb46d7b` — `feat(106-01): WarmDesempenhoCache aquece 2 alvos + options --mes/--user`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `--mes` com mês fora de 01-12 não era rejeitado**
- **Found during:** Task 2, RED (teste `test_option_mes_fora_do_intervalo_valido_retorna_failure`)
- **Issue:** O plano especificava validar `--mes` só via `preg_match('/^\d{4}-\d{2}$/', ...)`. Esse regex aceita `'2026-13'` (13 são 2 dígitos). `Carbon::createFromFormat('Y-m', '2026-13')` não lança exceção — faz overflow silencioso pro ano seguinte (`'2027-01'`), o que aqueceria a competência ERRADA em vez de falhar.
- **Fix:** Regex passou a capturar o grupo do mês (`/^\d{4}-(\d{2})$/`) e validar explicitamente `1 <= mês <= 12` antes de chamar `Carbon::createFromFormat`.
- **Files modified:** `app/Console/Commands/WarmDesempenhoCache.php`
- **Commit:** `bb46d7b`

Nenhum outro desvio — as demais tasks seguiram o plano à risca.

## Fronteira preservada (verificação SC4)

`git diff` de `DesempenhoScoreService.php` mostra APENAS o método `isCached` adicionado — `compute()`, `computeCached()`, `cacheKey()`, `computeNpsWindow()` e toda a régua de nota permanecem byte-a-byte idênticos. Testes de nota (`JanelaNpsBonusTest`) seguem 100% verdes (5/5), confirmando que a Fase 105/v18 não regrediu.

## Verificação executada

- `DesempenhoScoreServiceCacheTest` — 5/5 verde
- `WarmDesempenhoCacheTest` — 5/5 verde
- Ambas as suítes rodando juntas — 10/10 verde
- `JanelaNpsBonusTest` (regressão v18/Fase 105) — 5/5 verde, sem regressão de números/régua
- `git diff app/Services/DesempenhoScoreService.php` — confirma isolamento do wrapper `isCached`

## Nota fora de escopo (não corrigido — Scope Boundary)

`tests/Feature/PerformanceCargoFilterTest.php` (5 dos 6 casos) falha tanto isoladamente quanto em conjunto — pré-existente, **não introduzido por este plano**. Confirmado via `git log` que o arquivo não foi tocado por nenhum commit desta sessão (último commit relevante é anterior, `096d72f`). A suíte não usa `Http::fake()`, então depende de rede real (Adman/ML) — falha por ambiente, não por regressão de código. `PerformanceController.php` está fora do escopo deste plano (é o Plan 106-02) e não foi modificado. Não corrigido, conforme regra de escopo do executor (só corrigir o que é causado pelas mudanças da task atual).

## Self-Check: PASSED

- `app/Services/DesempenhoScoreService.php` — FOUND
- `app/Console/Commands/WarmDesempenhoCache.php` — FOUND
- `tests/Unit/DesempenhoScoreServiceCacheTest.php` — FOUND
- `tests/Feature/Phase106/WarmDesempenhoCacheTest.php` — FOUND
- Commit `cd6ffac` — FOUND
- Commit `bb46d7b` — FOUND
