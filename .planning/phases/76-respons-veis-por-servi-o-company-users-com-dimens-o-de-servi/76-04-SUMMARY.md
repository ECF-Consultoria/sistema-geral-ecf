---
phase: 76-respons-veis-por-servi-o-company-users-com-dimens-o-de-servi
plan: 04
subsystem: testing
tags: [phpunit, sqlite, company_users, belongsToMany, distinct, regressao, bonus]

# Dependency graph
requires:
  - phase: 76-01
    provides: migration company_users.servico_id + swap do unique + data-migration
  - phase: 76-02
    provides: relações consolidadas com dedup (Company::consultor/estrategista, User::companies/*Companies)
  - phase: 76-03
    provides: escritas escopadas por servico_id (ShopeeEmpresas/Company controllers)
provides:
  - "Teste de regressão dos ~15 leitores consolidados (Grupo A/B) com linha Shopee simulada"
  - "Phase gate verde: suite V16 + filtros Portfolio/Desempenho/Nps sem novas falhas"
  - "Prova final do invariante DEC-A2 (bônus não dobra, responsável não troca)"
affects: [78-shopee-responsaveis, 80-desempenho-cache-bump]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Regressão por relação real: exercitar as queries que os leitores usam (não reimplementar lógica)"
    - "Vetor Pitfall 4: linha Shopee com assigned_at/timestamps divergentes fura distinct ingênuo"

key-files:
  created:
    - tests/Feature/V16/LeitoresConsolidadoRegressaoTest.php
  modified: []

key-decisions:
  - "Tarefa 2 (phase gate) é verificação-only: sem commit de código de produção"
  - "PublicacaoDesempenhoRouteTest (403≠200) confirmada pré-existente em deferred-items.md — fora de escopo"

patterns-established:
  - "Snapshot antes/depois da linha Shopee: capturar responsável consolidado e reasserir identidade após dup"
  - "W3: call-site que precisa do papel re-declara ->withPivot('role') sobre companies() com ->select('companies.*')"

requirements-completed: [DEC-A2]

# Metrics
duration: 12min
completed: 2026-07-14
---

# Phase 76 Plan 04: Regressão dos Leitores Consolidados Summary

**Teste de regressão final (LeitoresConsolidadoRegressaoTest) provando via as relações reais que os ~15 leitores Grupo A/B mantêm o responsável consolidado e a carteira do bônus 1× mesmo com a linha Shopee da Phase 78 simulada — DEC-A2 fechado.**

## Performance

- **Duration:** ~12 min
- **Completed:** 2026-07-14
- **Tasks:** 2 (1 teste + 1 phase gate de verificação)
- **Files modified:** 1 criado

## Accomplishments
- Regressão Grupo A: `Company::consultor()`/`estrategista()->first()` retornam o MESMO responsável antes e depois da linha Shopee (vetor de `NpsDispararMensal`).
- Regressão Grupo B: `User::companies()`/`consultorCompanies()->get()->count()` continuam 1× com `assigned_at` divergente (vetor do bônus `DesempenhoScoreService`/`PortfolioController`).
- `PortfolioGoal::getCarteira` (whereHas role) conta a empresa 1×.
- W3: `companies()->withPivot('role')->first()->pivot->role` continua resolvendo (`'consultor'`, não null) após o dedup com `->select('companies.*')`.
- Phase gate verde: suite V16 (20 passed) + filtros Portfolio (29), Nps (157) sem falhas; Desempenho (55 passed, 1 falha pré-existente registrada).

## Task Commits

1. **Task 1: Regressão Grupo A/B com linha Shopee simulada** - `48e6b89` (test)
2. **Task 2: Phase gate — suite completa de regressão verde** - sem commit (verificação-only, não edita código de produção)

## Files Created/Modified
- `tests/Feature/V16/LeitoresConsolidadoRegressaoTest.php` - 4 testes de regressão (Grupo A responsável consolidado; Grupo B carteira; PortfolioGoal whereHas; W3 pivot->role), 17 assertions.

## Decisions Made
- Tarefa 2 não gera commit de produção: é o gate de verificação da fase, conforme `<action>` do plano.
- Não reimplementar a lógica dos leitores — o teste exercita as relações/queries que eles consomem, mantendo 1 ponto de verdade (o dedup vive na relação, não no consumidor).

## Deviations from Plan

None - plan executado exatamente como escrito.

## Issues Encountered

- **Falha `PublicacaoDesempenhoRouteTest > user com mlb dashboard acessa rota e recebe 200` (403 ≠ 200)** no filtro `--filter=Desempenho`. Confirmada **pré-existente** e já registrada em `.planning/phases/76-.../deferred-items.md` — é permissão da rota `/publicacao/desempenho`, NÃO relacionada à dimensão de serviço em `company_users`. Fora de escopo desta fase (não corrigir, só confirmar pré-existência). Nenhum leitor consolidado regrediu.

## Phase Gate — Resultado

| Suite | Resultado |
|-------|-----------|
| `tests/Feature/V16` | 20 passed (63 assertions) |
| `--filter=Portfolio` | 29 passed (372 assertions) |
| `--filter=Desempenho` | 55 passed, 1 falha PRÉ-EXISTENTE (`PublicacaoDesempenhoRouteTest`) |
| `--filter=Nps` | 157 passed (1011 assertions) |

## Next Phase Readiness
- DEC-A2 fechado: comportamento consolidado idêntico ao de hoje, provado com cenário ML+Shopee.
- Blindagem pronta para a Phase 78 (duplicação real por serviço) e para o bump de cache do Desempenho na Phase 80.
- Sem deploy nesta fase (backend-only, sem libs novas).

## Self-Check: PASSED

- FOUND: `tests/Feature/V16/LeitoresConsolidadoRegressaoTest.php`
- FOUND: `.planning/phases/76-.../76-04-SUMMARY.md`
- FOUND: commit `48e6b89`

---
*Phase: 76-respons-veis-por-servi-o-company-users-com-dimens-o-de-servi*
*Completed: 2026-07-14*
