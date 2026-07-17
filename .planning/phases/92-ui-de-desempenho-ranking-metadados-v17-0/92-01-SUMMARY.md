---
phase: 92-ui-de-desempenho-ranking-metadados-v17-0
plan: 01
subsystem: desempenho (backend — PerformanceController/PortfolioController)
tags: [desempenho, elegibilidade, comparacaoContextual, ranking, inertia]

requires:
  - phase: 91-02
    provides: "DesempenhoScoreService v4 com score_status official/partial/blocked + 6 metadados de elegibilidade; pendência explícita da distorção null→0.0 no comparacaoContextual"
provides:
  - "Passthrough dos 6 metadados de elegibilidade (empresas_unicas, vinculos_servico, vinculos_financeiros, vinculos_sem_fonte_financeira, score_status, componentes_disponiveis) em cada linha do ranking `/performance`"
  - "Filtro de auditoria ?contexto=todos|performance|shopee em PerformanceController::index() — view-only, whitelist explícita, nunca chega ao DesempenhoScoreService"
  - "Correção da distorção A/B no comparacaoContextual de PortfolioController — blocked não vira 0.0 na comparação de pares, tamanho_amostra bate com a base da mediana, self-view do blocked não gera comparação fantasma"
affects:
  - "Fase 92 Plan 02 (frontend — badges de score_status, filtro ?contexto= na UI, consumo dos metadados no ranking)"

tech-stack:
  added: []
  patterns:
    - "contextoFiltro() view-only espelhado em 2 controllers (PortfolioController Fase 90, PerformanceController Fase 92) — nunca repassa o setor pro service de score"
    - "Guard de exclusão por score_status no mesmo continue que já filtra sem_carteira (evita duplicar a lógica de amostra em 2 lugares)"

key-files:
  created:
    - tests/Feature/V16/PerformanceIndexMetadadosTest.php
    - tests/Feature/V16/ComparacaoContextualBlockedTest.php
  modified:
    - app/Http/Controllers/PerformanceController.php
    - app/Http/Controllers/PortfolioController.php

key-decisions:
  - "Filtro ?contexto= no PerformanceController::index() só valida a whitelist e ecoa a prop 'contexto' — a filtragem visual real (linhas do ranking) fica a cargo do Plan 92-02 (client-side, usando os metadados já presentes no payload), evitando N+1 de CarteiraContextService::forUser() no loop de 11-20 users do ranking (recomendação do 92-RESEARCH.md)"
  - "Guard de blocked em comparacaoContextual entra no MESMO continue que já filtra sem_carteira (não um continue separado depois) — resolve Distorção A e B simultaneamente, sem tocar medianaPares/percentil/relativo"
  - "Self-view do próprio profissional blocked força comparacao_contextual=null via checagem explícita ANTES do if ($scoresPares->count() >= 2) — sem essa checagem, o guard de exclusão faria o próprio user cair no fallback $performanceProfissional e o ?? 0.0 defensivo seria acionado por um null real"

patterns-established: []

requirements-completed: [DESEMP-08]

duration: ~55min
completed: 2026-07-17
---

# Phase 92 Plan 01: Backend — passthrough de metadados + correção comparacaoContextual Summary

**PerformanceController repassa os 6 metadados de elegibilidade da Fase 91 no payload do ranking `/performance` (sem recomputar) e ganha o filtro `?contexto=` view-only; PortfolioController para de tratar um profissional `blocked` como nota 0.0 na comparação de pares, corrigindo a pendência formal registrada em 91-02-SUMMARY.md.**

## Performance

- **Duration:** ~55 min
- **Tasks:** 2/2 completas
- **Files modified:** 2 (PerformanceController.php, PortfolioController.php)
- **Files created:** 2 arquivos de teste (8 testes, 77 assertions)

## Accomplishments

- Ranking `/performance` (prop Inertia `ranking`) entrega `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`, `score_status` e `componentes_disponiveis` por linha — lidos direto do `$resultado` já calculado (cache v4 ou snapshot mensal), sem chamar o service de novo.
- Filtro `?contexto=todos|performance|shopee` adicionado a `PerformanceController::index()`, espelhando o padrão já existente em `PortfolioController::contextoFiltro()` (Fase 90). Comprovado por teste que `nota_final`/`score_status` são idênticos entre os 3 valores — o filtro nunca recalcula.
- Corrigida a distorção herdada da Fase 91 no `comparacaoContextual` (`PortfolioController` self-view): um profissional `blocked` (nota_final=null) não é mais tratado como 0.0 na comparação de pares, e `tamanho_amostra` passou a bater com a base real da mediana.
- Self-view do próprio profissional `blocked` não produz mais comparação numérica fantasma — `comparacao_contextual` fica `null`, e `performance_profissional.score_status` (já exposto na view) sinaliza o motivo para o frontend (Plan 92-02).
- Gate SC1 (ausência de score separado por marketplace) confirmado limpo: `grep -rin "score_shopee\|score_ml\|ranking_shopee\|ranking_ml" app/ resources/js/` → 0 ocorrências.

## Task Commits

Each task was committed atomically:

1. **Task 1: Passthrough dos 6 metadados + filtro ?contexto= view-only no PerformanceController::index()** - `6ac70de` (feat)
2. **Task 2: Corrigir comparacaoContextual — excluir blocked dos pares + tamanho_amostra + self-view** - `a3abb22` (fix)

_Nota: seguindo a diretriz do usuário de commit atômico por task (2 commits, não RED/GREEN separados) — cada commit já inclui teste + implementação verificados GREEN antes do commit, com verificação manual de RED (revert temporário) documentada abaixo._

## Files Created/Modified

- `app/Http/Controllers/PerformanceController.php` - passthrough dos 6 metadados no array do ranking (`$rankingRaw = $users->map(...)`); novo helper privado `contextoFiltro()`; prop `contexto` adicionada ao `Inertia::render`.
- `app/Http/Controllers/PortfolioController.php` - guard `score_status === 'blocked'` no loop que monta `$scoresPares` (mesmo `continue` do `sem_carteira`); guard de self-view que zera `$comparacaoContextual` quando o próprio profissional é `blocked`.
- `tests/Feature/V16/PerformanceIndexMetadadosTest.php` - 4 testes: SC2 (6 metadados presentes), SC2 blocked (permanece no ranking com score_status/vinculos_sem_fonte), SC3 (contexto view-only, valor inválido cai em 'todos'), SC1 (gate de ausência via leitura do arquivo do controller).
- `tests/Feature/V16/ComparacaoContextualBlockedTest.php` - 2 testes: Distorção A+B no mesmo cenário (blocked não vira 0.0, tamanho_amostra bate com a mediana real dos officials — oráculo calculado via `DesempenhoScoreService::compute()` direto, sem hardcode de números da régua), e self-view do próprio blocked (comparacao_contextual null + score_status disponível).

## Decisions Made

- Ver `key-decisions` no frontmatter acima.

## Deviations from Plan

None - plan executado exatamente como escrito. As 6 chaves, o helper `contextoFiltro()` e o guard duplo (amostra + self-view) seguem literalmente o `<interfaces>` e a `<action>` do 92-01-PLAN.md.

## Issues Encountered

Nenhum bloqueio. Validação extra feita por precaução: antes de commitar a Task 2, reverti temporariamente a correção do `PortfolioController.php` (via diff salvo em `scratchpad/`, não `git stash` — proibido em worktree) e confirmei que os 2 testes de `ComparacaoContextualBlockedTest` falham exatamente como esperado (RED real, não só teste mal escrito): `tamanho_amostra` batia 4 em vez de 3, e a self-view do blocked retornava um array de comparação em vez de `null`. Reapliquei o diff e confirmei GREEN antes do commit.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Regressão (verificação)

- `PerformanceIndexMetadadosTest` + `ComparacaoContextualBlockedTest`: 6/6 verde, 64 assertions.
- Gate SC1: `grep -rin "score_shopee\|score_ml\|ranking_shopee\|ranking_ml" app/ resources/js/` → 0 ocorrências.
- Regressão de domínio `--filter="Desempenho|Bonus|Portfolio"`: 104 testes, 4 falhas — **todas pré-existentes, nenhuma nova**:
  - `PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` — falha documentada desde 91-01-SUMMARY.md (permissão de rota `mlb.dashboard`, ortogonal a este plano).
  - `Phase61\PortfolioMultiFonteE2ETest` (2 testes) + `Phase61\PortfolioSourceEnrichmentTest` (1 teste) — `user_portfolios` size 0 vs 1. Confirmado pré-existente: revertendo temporariamente a mudança do `PortfolioController.php` (via diff, sem `git stash`) os mesmos 3 testes continuam falhando idênticos — não são causados por este plano nem tocam o bloco `comparacaoContextual`.

## Next Phase Readiness

Backend pronto para o Plan 92-02 (frontend): o payload do ranking já carrega os 6 metadados e a prop `contexto`; `performance_profissional.score_status` já chega ao `Portfolio/Show.jsx` para a mensagem de "nota ainda não é oficial" quando `comparacao_contextual` é `null`. Nenhum bloqueio conhecido.

---
*Phase: 92-ui-de-desempenho-ranking-metadados-v17-0*
*Completed: 2026-07-17*
