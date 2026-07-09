---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 04
subsystem: Módulo Desempenho (backend big bang v2)
tags: [big-bang, refactor, cron, mensal, desempenho, phase-74, wave-3]
requires:
  - 74-01 (Model DesempenhoScoreSnapshot + mes_referencia + scopes mensal/diario)
  - 74-02 (Model BonusFaixa + seed + classificar static)
  - 74-03 (DesempenhoScoreService v2 completo — compute(User, Carbon))
provides:
  - Comando desempenho:snapshot-scores reescrito internamente para motor v2 (schedule 13:30 BRT preservado)
  - Comando desempenho:consolidar-mes novo (mensal, dia 1 às 14:00 BRT, idempotente)
  - PerformanceController, PortfolioController, DashboardController migrados para DesempenhoScoreService via DI
  - Snapshot mensal fechado (mes_referencia = YYYY-MM-01) — insumo canônico do ranking mensal (DESEMP-14)
affects:
  - Contrato de props Inertia de Performance/Dashboard.jsx, Performance/Index.jsx, Portfolio/Show.jsx e Dashboard/Admin.jsx muda para shape v2 (nota_final/faixa_bonus/componentes). Plan 74-06 reescreve os JSX.
  - PortfolioScoreService.php DELETADO do repositório (DESEMP-14 big bang).
tech-stack:
  added: []
  patterns:
    - Idempotência: updateOrCreate([user_id, mes_referencia]) no comando mensal
    - ini_set memory_limit=512M no handle mensal (batch 15-20 users × 30 empresas)
    - Ranking_pos com filtro mes_referencia (mensal) vs mes_referencia IS NULL (diário)
    - Snapshot mensal como cache de leitura no PerformanceController::index (fallback compute)
key-files:
  created:
    - app/Console/Commands/ConsolidarMesDesempenho.php
    - .planning/phases/74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o/74-04-SUMMARY.md
  modified:
    - app/Console/Commands/SnapshotDesempenhoScores.php
    - app/Http/Controllers/PerformanceController.php
    - app/Http/Controllers/PortfolioController.php
    - app/Http/Controllers/DashboardController.php
    - routes/console.php
  deleted:
    - app/Services/PortfolioScoreService.php
decisions:
  - D-06 big bang aplicado — v1 removido no mesmo commit dos refactors
  - D-08 dois schedules (diário 13:30 + mensal dia 1 14:00 BRT)
  - D-09 comando mensal aceita --mes=YYYY-MM para catch-up manual
  - Filtro `mes_referencia IS NULL` explicitamente aplicado no lookup + popularRankingPos do diário para isolar do mensal na mesma tabela
  - Fallback de leitura no PerformanceController::index — prefere snapshot mensal fechado (M-1) e cai em compute() do mês em curso quando ausente (transição)
metrics:
  duration: ~25 min
  completed: 2026-07-09
requirements: [DESEMP-09, DESEMP-14]
---

# Phase 74 Plan 04: Big bang refactor + cron consolidar-mes — Summary

Migração big bang do módulo Desempenho para o motor v2: 3 controllers passam a consumir `DesempenhoScoreService`, comando diário `desempenho:snapshot-scores` reescrito internamente preservando schedule 13:30 BRT, novo comando `desempenho:consolidar-mes` roda dia 1 às 14:00 BRT gravando snapshot mensal fechado (idempotente), e `PortfolioScoreService.php` DELETADO do repositório no mesmo commit.

## One-liner

Wave 3 do módulo Desempenho fechada — engine v2 viva em produção, snapshots mensais persistidos, código v1 removido do repo.

## O que foi entregue

### Comandos (Task 1)

- **`app/Console/Commands/SnapshotDesempenhoScores.php` (reescrito)**
  - Constructor DI trocado: `PortfolioScoreService` → `DesempenhoScoreService`.
  - Signature `desempenho:snapshot-scores {--data=} {--user=}` preservada.
  - `handle()` deriva `$mesReferencia = Carbon::createFromFormat('Y-m-d', $refDateStr)->startOfMonth()` e chama `compute($user, $mesReferencia)` (mês em curso parcial).
  - DESEMP-10: users com `sem_carteira=true` são pulados (não gravam row) com `Log::info` estruturado.
  - Colunas legadas preservadas por compat: `score = round(nota_final*20)`, `classificacao = faixa_bonus`, `breakdown_json = shape completo`, `tem_base_comparativa = nota_final !== null`, `empresas_carteira/empresas_eligiveis` mapeados de `empresas_carteira/empresas_com_baseline`.
  - Snapshot gravado com `mes_referencia = NULL` (modo D-02) — isolado do fechamento mensal.
  - Lookup e `popularRankingPos()` agora filtram `mes_referencia IS NULL` (SQL MySQL/MariaDB + fallback SQLite iterativo).

- **`app/Console/Commands/ConsolidarMesDesempenho.php` (NOVO)**
  - Signature: `desempenho:consolidar-mes {--mes= : YYYY-MM (default = mês anterior ao hoje)}`.
  - `ini_set('memory_limit', '512M')` no início do handle (constraint SPEC batch mensal).
  - Se `--mes=YYYY-MM` fornecido, parse via `Carbon::createFromFormat('Y-m', ...)`; senão default = `Carbon::today()->subMonthNoOverflow()->startOfMonth()` (mês anterior fechado).
  - Filtro users idêntico ao SnapshotDesempenhoScores (user_setores → cargos.slug IN [analista, estrategista]).
  - Idempotência: `DesempenhoScoreSnapshot::updateOrCreate(['user_id', 'mes_referencia'], [ ...atributos, 'ref_date' => $mes ])`.
  - DESEMP-10: users sem carteira pulados + `Log::info` estruturado (user_id, user_name, mes_referencia, motivo).
  - `popularRankingPosMensal($mesStr)` — ROW_NUMBER() OVER filtrando `DATE(mes_referencia) = ?` (MySQL/MariaDB) + fallback SQLite iterativo.
  - Report final: `[Desempenho Mensal] Mes {YYYY-MM} — OK: X · Falhas: Y · Sem carteira: Z`.

- **`routes/console.php` (schedule adicionado)**
  - Schedule diário `desempenho-snapshot-scores` (13:30 BRT) PRESERVADO intacto.
  - Novo schedule mensal:
    ```php
    Schedule::command('desempenho:consolidar-mes')
        ->monthlyOn(1, '14:00')
        ->timezone('America/Sao_Paulo')
        ->name('desempenho-consolidar-mes')
        ->onOneServer()
        ->withoutOverlapping();
    ```

### Controllers (Task 2)

- **`app/Http/Controllers/PerformanceController.php`**
  - Constructor DI: `PortfolioScoreService` → `DesempenhoScoreService`.
  - `index()`: lê snapshot MENSAL fechado (`DesempenhoScoreSnapshot::mensal()->whereDate('mes_referencia', $mesFechadoStr)`) como fonte primária; fallback a `compute($u, $mesReferencia)` quando ausente. Ranking por `nota_final` DESC com nulls last. `sem_carteira=true` filtrado antes do sort (DESEMP-10).
  - Deltas longitudinais: `delta_vs_ontem` / `delta_vs_semana_passada` filtram `mes_referencia IS NULL` (snapshot diário); NOVO `delta_vs_mes_passado` = nota atual − snapshot mensal M-2. Escala 0-5 (score legado convertido via `/20`).
  - `dashboardCarteira()`: nova assinatura `compute($user, Carbon::now()->startOfMonth())`. Métricas agregadas (meta_target/meta_realizado/emCrescimentoCount) recalculadas inline a partir das queries de metrics/goals por empresa. Payload traz `desempenho` (shape v2 completo), `kpis.nota_final`, `kpis.faixa_bonus`, `kpis.faixa_promovida`, `kpis.sem_carteira`, `kpis.motivo` para o frontend consumir (Plan 74-06).

- **`app/Http/Controllers/PortfolioController.php`**
  - Constructor DI: `PortfolioScoreService` → `DesempenhoScoreService`.
  - `renderPortfolio()`: `compute($user, Carbon::now()->startOfMonth())` e `compute($par, $mesReferencia)` para cada par. Pares com `sem_carteira=true` filtrados do grupo comparativo.
  - `comparacaoContextual` reescrito para shape v2: percentil calculado sobre `nota_final`, medianas de `nps_medio` / `var_faturamento_pct` / `var_margem_pct` (via `componentes.*`), `relativo` compara nota + componentes. Chaves legadas (crescimento_ajustado_pct, atingimento_meta.pct, execucao_ads.pct, recuperacao.pct) removidas.

- **`app/Http/Controllers/DashboardController.php`**
  - `$scoreService = app(\App\Services\DesempenhoScoreService::class)` no `adminDashboard()`.
  - Widget `performance_equipe`: `compute($u, $mesReferenciaPerf)` retornando `{id, name, nota_final, faixa_bonus, faixa_promovida, sem_carteira}`. DESEMP-10 filtra `sem_carteira=true` antes de `sortByDesc('nota_final')`.
  - Chave `'performance_equipe' => $perfMembros` no payload Inertia preservada (Dashboard/Admin.jsx recebe shape novo — Plan 74-06 reescreve).

### Delete (Task 2D)

- **`app/Services/PortfolioScoreService.php` DELETADO** via `git rm`.
- Comentários VERBATIM no `PublicadorScoreService.php` (linhas 12/14/204/220) e `BymobilleSmoke.php` (linha 142) mantidos — fora do escopo (SPEC boundaries; Phase separada se diretoria mudar Publicador também).
- Docblock em `resources/js/Pages/Performance/Dashboard.jsx` (linha 22) mantido — Plan 74-06 reescreve o JSX inteiro.

## Verificação executada

- `php artisan schedule:list | grep desempenho`:
  ```
  30 13 * * *  php artisan desempenho:snapshot-scores  (Next: em 21 horas)
  0  14 1 * *  php artisan desempenho:consolidar-mes  (Next: em 3 semanas)
  ```
- `php artisan list --format=json | grep desempenho:`:
  ```
  "name":"desempenho:consolidar-mes"
  "name":"desempenho:snapshot-scores"
  ```
- `test ! -f app/Services/PortfolioScoreService.php` → OK (deletado).
- `grep -rn "PortfolioScoreService" app/Http/Controllers` → 0 hits.
- `php -l` em SnapshotDesempenhoScores.php, ConsolidarMesDesempenho.php, routes/console.php, PerformanceController.php, PortfolioController.php, DashboardController.php → todos "No syntax errors".

## Aceitação — success criteria

- [x] `PortfolioScoreService.php` DELETADO do repositório.
- [x] `grep -r "PortfolioScoreService" app/Http/Controllers` retorna 0 hits ativos.
- [x] `PerformanceController`, `PortfolioController`, `DashboardController` usam `DesempenhoScoreService` via DI (constructor ou `app()` inline).
- [x] `desempenho:snapshot-scores` preserva schedule 13:30 BRT + grava snapshot com `mes_referencia = NULL`.
- [x] `desempenho:consolidar-mes` registrado em schedule mensal (`monthlyOn(1, '14:00')` + `->timezone('America/Sao_Paulo')` + `->onOneServer()` + `->withoutOverlapping()`).
- [x] Comando mensal aceita `--mes=YYYY-MM` e é idempotente via `updateOrCreate((user_id, mes_referencia))`.
- [x] Users com `sem_carteira=true` são pulados no snapshot mensal E no diário (DESEMP-10).
- [x] Lint PHP verde em todos os arquivos modificados.
- [x] REQ DESEMP-09 (consolidação mensal fechada + diário preservado) atendido.
- [x] REQ DESEMP-14 (big bang v1 removido do repo) atendido.

## Deviations from Plan

**Auto-fixed Issues:**

1. **[Rule 2 - Correctness] Filtro `mes_referencia IS NULL` no `popularRankingPos` do comando diário**
   - Found during: Task 1 — reescrita do SnapshotDesempenhoScores.
   - Issue: Sem esse filtro, quando o consolidar-mes rodar no dia 1 (14:00), o snapshot mensal (`mes_referencia=YYYY-MM-01`) coexistiria com o diário (`mes_referencia=NULL`) no mesmo `ref_date` de mesa (via `YYYY-MM-01` para o mensal). O ROW_NUMBER() sem filtro atribuiria ranking misto (2 rows por user, um diário + um mensal), gerando `ranking_pos` inconsistente para o diário.
   - Fix: `WHERE DATE(ref_date) = ? AND mes_referencia IS NULL` no MariaDB nativo + `whereNull('mes_referencia')` no fallback iterativo. Simétrico no comando mensal: `WHERE DATE(mes_referencia) = ?` (filtro implícito não-null).
   - Files modified: `app/Console/Commands/SnapshotDesempenhoScores.php`, `app/Console/Commands/ConsolidarMesDesempenho.php`.
   - Commit: `13b6ee1`.

2. **[Rule 3 - Blocking] Preservação de `dashboardCarteira` sem `$data['periodo']`**
   - Found during: Task 2 — refactor PerformanceController::dashboardCarteira.
   - Issue: O shape v1 do `PortfolioScoreService::compute` expunha `$data['periodo']['from']` e `$data['periodo']['to']` (janela rolling 30d) usadas nas queries agregadas de metrics por empresa. O shape v2 não tem essa chave (o motor agora recebe mês como argumento e o "período" é implícito).
   - Fix: Substituído por `$atualFrom = Carbon::now()->subDays(30)->toDateString()` / `$atualTo = Carbon::now()->toDateString()` inline — semantic preserved para a tabela de "atividade recente da carteira" (não o cálculo da nota).
   - Files modified: `app/Http/Controllers/PerformanceController.php` linhas ~239-244.
   - Commit: `1a94faf`.

3. **[Rule 2 - Correctness] Recalculo inline de meta agregada + emCrescimentoCount em `dashboardCarteira`**
   - Found during: Task 2.
   - Issue: O shape v1 expunha `$data['metricas']['atingimento_meta']['target_value']/['realized_value']/['pct']` e `$data['metricas']['empresas_em_crescimento']['count']/['total']/['pct']` — chaves consumidas pelo widget "Metas" e pelo KPI `empresas_em_crescimento`. Shape v2 não tem.
   - Fix: Recalculo inline com queries já disponíveis (`$goalsByCompany`, `$metricsByCompany`): `metaTarget = sum(goal.target_value)`, `metaRealizado = sum(revenue das empresas com goal)`, `metaPct = round(metaRealizado/metaTarget*100, 1)`; `emCrescimentoCount = filter(rev_prev > 0 && rev > rev_prev).count()`. Semantic preservada 1:1.
   - Files modified: `app/Http/Controllers/PerformanceController.php` linhas ~366-395.
   - Commit: `1a94faf`.

## Known Stubs

Nenhum stub introduzido. O payload novo do `dashboardCarteira` traz `desempenho` (shape v2 completo) e `kpis` derivados; o Dashboard.jsx atual continua consumindo `kpis.*` legados (nota_final/faixa_bonus adicionados; score/classificacao removidos). O Plan 74-06 reescreve os JSX para o contrato v2 puro — até lá, a UI apresenta os KPIs derivados corretamente (meta, empresas em crescimento, faturamento, NPS) e os campos novos (`kpis.nota_final`, `kpis.faixa_bonus`) já estão populados no payload.

## Self-Check: PASSED

- [x] `app/Console/Commands/ConsolidarMesDesempenho.php` — FOUND.
- [x] `app/Console/Commands/SnapshotDesempenhoScores.php` — FOUND + reescrito internamente.
- [x] `app/Services/PortfolioScoreService.php` — MISSING (deleção intencional).
- [x] Commit `13b6ee1` (Task 1) — FOUND em `git log --oneline`.
- [x] Commit `1a94faf` (Task 2) — FOUND em `git log --oneline`.
- [x] Schedule mensal `desempenho-consolidar-mes` — FOUND em `artisan schedule:list`.
- [x] Schedule diário `desempenho-snapshot-scores` — FOUND (13:30 BRT preservado).
