---
phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
plan: 02
subsystem: database
tags: [laravel, artisan-command, phpunit, mockery, desempenho, comparador, shadow-flag]

# Dependency graph
requires:
  - phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0 (Plano 01)
    provides: "nota_final_por_empresa/score_status_por_empresa condicionais ao shadow + tabelas desempenho_comparador_profissionais/desempenho_comparador_empresas"
provides:
  - "Comando `desempenho:comparar-score-empresa` — coleta a comparação nota antiga x nota nova por competência fixa"
  - "Uma única chamada de compute() por (profissional, competência), com incluirEmpresasScore: true (D-01, comparação justa)"
  - "Releitura interleaved do diff_pct nativo da margem, só na competência alvo, empresa por empresa"
  - "Linhas reconsultáveis nas duas tabelas do comparador — insumo do Plano 03 (decomposição) e Plano 04 (relatório/histograma)"
affects: [121-03, 121-04, 121-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dublê de serviço que ENVOLVE uma instância REAL via Mockery::mock($realService)->makePartial() — conta/intercepta chamadas sem perder comportamento real (usado pra provar D-01)"
    - "app()->forgetInstance() antes de reconstruir um dublê que envolve instância real, quando o mesmo helper é chamado múltiplas vezes no mesmo teste — evita Mockery encapsular um dublê anterior e colidir na geração de classe"
    - "run_id (UUID) + gerado_em compartilhados por toda a rodada, gravados em toda linha das duas tabelas (T-121-12)"
    - "Guard de re-execução do mesmo dia por competência alvo, com --force explícito para sobrepor (T-121-13)"

key-files:
  created:
    - app/Console/Commands/CompararScoreEmpresa.php
    - tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php
  modified: []

key-decisions:
  - "Releitura do MetricDiffDispatcher só ocorre quando competencia_alvo=true E fonte_financeira não é nula — nunca nas competências históricas (--historico), que só alimentam o histograma futuro via margem_var_pp já presente em empresas_score"
  - "Company::find() memoizado num mapa por escopo da rodada inteira (não por competência) — empresas se repetem entre carteiras de profissionais diferentes"
  - "Falha do dispatcher para uma empresa é capturada localmente (margem_diff_pct=null + motivo em quality_motivos) sem interromper a empresa seguinte nem o profissional"
  - "faixa_antiga_inicial/faixa_nova_inicial/decomposicao/maior_causa_delta ficam null neste plano — são do Plano 03 (BonusFaixa::classificar() direto, D-06)"

patterns-established:
  - "Dublê que envolve instância real para contar chamadas de serviço em Artisan Command, preservando o comportamento de verdade (em vez de recalcular a lógica de negócio no teste)"

requirements-completed: [ROLL-01]

# Metrics
duration: ~35min
completed: 2026-07-31
---

# Phase 121 Plan 02: Comando comparador (coleta — chamada única, interleaving, persistência) Summary

**Comando `desempenho:comparar-score-empresa` coleta nota antiga x nota nova por competência fixa, com uma única chamada de `compute()` por profissional e releitura interleaved do `diff_pct` nativo da margem, empresa por empresa, gravando tudo em duas tabelas insert-only antes de qualquer agregação.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 2/2
- **Files created:** 2
- **Files modified:** 0

## Accomplishments
- `app/Console/Commands/CompararScoreEmpresa.php` criado: `--mes` obrigatório (competência fixa YYYY-MM), `--historico=2` (0..6, trava de volume contra vetor de carga na Adman) e `--force`; cada competência resolvida via `MetricPeriodResolver` e validada por `comparison_mode === 'previous_equal_length_window'` antes de qualquer coleta
- D-01 (comparação justa): exatamente **uma** chamada de `compute($user, $mes, $periodo, incluirEmpresasScore: true)` por (profissional, competência) — `grep -c "scoreService->compute"` confirma 1 ocorrência no arquivo
- Invariante nº 2 (interleaving): a releitura do `diff_pct` nativo via `MetricDiffDispatcher` acontece imediatamente antes de persistir cada empresa, dentro do mesmo laço — nunca numa segunda passada; e só na competência alvo (as históricas usam `margem_var_pp`, já presente em `empresas_score`, sem custo extra de API)
- Guard de re-execução (T-121-13): segunda rodada no mesmo dia para a mesma competência alvo aborta com `FAILURE` e o `run_id` existente, a menos que `--force` seja passado (que registra aviso em log e grava novo `run_id`)
- Fail-open em dois níveis: por empresa (exceção do dispatcher não derruba as demais empresas nem o profissional) e por profissional (exceção grava `falhou=true`/`erro` e a rodada continua para os demais)
- `run_id` (UUID) e `gerado_em` compartilhados por toda a execução, gravados em toda linha das duas tabelas (T-121-12) — rastreabilidade de quando é o número
- T-121-10 respeitado: log/console só expõem contadores agregados (`OK`/`falhas`/`sem_carteira`) por competência — nota, delta e faixa por profissional nunca vão para log estruturado
- Suíte `CompararScoreEmpresaCommandTest` (6 testes) prova: chamada única com `incluirEmpresasScore: true`; sequência exata `diff(A), persist(A), diff(B), persist(B)` via eventos ordenados (nunca todas as persistências antes de todos os diffs); zero chamadas ao dispatcher nas competências históricas; persistência relida do banco (`::query()`) batendo com o payload real capturado; fail-open por profissional; guard de re-execução com `--force` gerando novo `run_id`
- `--filter=Phase121` 14/14 verde (8 herdados do Plano 01 + 6 novos); `--filter=Desempenho` 14 failed/100 passed (baseline exata, zero regressão)

## Task Commits

Each task was committed atomically:

1. **Task 1: O comando — competências fixas, chamada única por profissional, releitura interleaved e persistência** - `616f88d1` (feat)
2. **Task 2: Gate nº 1 — provar chamada única, interleaving e reconsultabilidade** - `022612e0` (test)

## Files Created/Modified
- `app/Console/Commands/CompararScoreEmpresa.php` - comando `desempenho:comparar-score-empresa`: resolve competências, itera profissionais elegíveis (mesmo filtro do `ConsolidarMesDesempenho`), chama `compute()` uma vez, releitura interleaved do `diff_pct` na competência alvo, persiste nas duas tabelas do comparador
- `tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php` - gate nº 1: 6 testes provando D-01, interleaving, restrição à competência alvo, persistência reconsultável, fail-open e guard de re-execução

## Decisions Made
- Nenhuma decisão nova além das já registradas no `121-02-PLAN.md` (`<design_decision>`) — plano executado conforme escrito. A única adaptação técnica foi no TESTE (ver Deviations abaixo), não no comando de produção.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `app()->forgetInstance(DesempenhoScoreService::class)` antes de reconstruir o dublê no helper de teste**
- **Found during:** Task 2, ao rodar o teste do guard de re-execução (que chama o helper `instalarDuble()` três vezes na mesma execução de teste)
- **Issue:** `Mockery::mock($realService)->makePartial()` envolvendo uma instância resolvida via `$this->app->make(DesempenhoScoreService::class)` — na segunda/terceira chamada do helper dentro do mesmo teste, o container ainda devolvia o dublê da chamada anterior (por causa do `$this->app->instance()` da chamada prévia), e o Mockery tentava encapsular um dublê já existente, gerando uma classe de mock composta com nome duplicado (`Mockery_5_Mockery_3_...`) — `Fatal error: Cannot redeclare ...::mockery_init()`
- **Fix:** Adicionado `$this->app->forgetInstance(DesempenhoScoreService::class);` imediatamente antes de `$this->app->make(...)` dentro do helper `instalarDuble()`, garantindo que cada chamada resolva uma instância REAL nova (não um dublê anterior) antes de envolvê-la
- **Files modified:** tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php (só o teste — nenhum arquivo de produção)
- **Verification:** `--filter=CompararScoreEmpresaCommandTest` 6/6 verde após o fix
- **Committed in:** `022612e0` (parte do commit da Task 2, arquivo criado já com o fix aplicado)

---

**Total deviations:** 1 auto-fixed (bug de infraestrutura de teste, sem mudança de comportamento de produção)
**Impact on plan:** Nenhum impacto no comando (`CompararScoreEmpresa.php`) — a correção foi inteiramente dentro do helper de teste, necessária para o Mockery não colidir ao reconstruir o dublê múltiplas vezes no mesmo método de teste.

## Issues Encountered
None além da deviation documentada acima.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- Comando de coleta pronto e provado — `desempenho_comparador_profissionais`/`desempenho_comparador_empresas` recebem linhas reconsultáveis com `nota_antiga`/`nota_nova`/`delta`, contadores por status e `margem_diff_pct` na competência alvo
- `faixa_antiga_inicial`/`faixa_nova_inicial`/`mudou_faixa`/`decomposicao`/`maior_causa_delta` seguem `null`/`false` — Plano 03 aplica `BonusFaixa::classificar()` direto (D-06) e a decomposição por componente/causa sobre este mesmo comando
- Plano 04 (relatório/histograma) consome as linhas históricas (`--historico`) já persistidas, via `margem_var_pp`, sem custo adicional de API
- Nenhuma flag de produção foi tocada; `metrics.performance_company_first_score` continua `false`

---
*Phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0*
*Completed: 2026-07-31*

## Self-Check: PASSED

- FOUND: app/Console/Commands/CompararScoreEmpresa.php
- FOUND: tests/Feature/Phase121/CompararScoreEmpresaCommandTest.php
- FOUND commit: 616f88d1
- FOUND commit: 022612e0
