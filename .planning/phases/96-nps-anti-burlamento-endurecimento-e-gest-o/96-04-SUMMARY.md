---
phase: 96-nps-anti-burlamento-endurecimento-e-gest-o
plan: 04
subsystem: nps
tags: [laravel, eloquent, phpunit, tdd, bonus, dashboard, inertia]

# Dependency graph
requires:
  - phase: 96-nps-anti-burlamento-endurecimento-e-gest-o (plan 03)
    provides: "NpsResponse::scopeValida() (whereNull invalidated_at) — contrato aplicado nos 10 call-sites deste plano"
provides:
  - "10/10 call-sites de agregação NPS (bônus/dashboards/metas/página da empresa) excluem respostas com invalidated_at preenchido"
  - "Suíte NpsInvalidacaoCallSitesTest — checklist executável com 1 asserção de exclusão por call-site (#1-#10)"
  - "BonusDualPathRegressaoTest estendido com cenário dual-ramo (atribuições + legado) provando que a invalidação não regride o bônus"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Eager-load condicional via closure (`->with(['response' => fn ($q) => $q->valida()...])`) — reaproveitado em 7 dos 10 call-sites; a query só carrega a response quando ela NÃO está invalidada, e o código consumidor (foreach `if ($response === null) continue`, ternário `$response ? [...] : null`) já tratava null corretamente sem nenhuma mudança adicional"
    - "`->whereNull('r.invalidated_at')`/`->whereNull('invalidated_at')` direto na query quando é JOIN cru ou `NpsResponse::query()` solta (sem eager-load) — 3 call-sites (#1, #3, #9)"
    - "Reflection para testar métodos privados de controllers que retornam `Inertia\\Response` — helper `inertiaProps()` constrói uma `Request` com header `X-Inertia: true` e chama `$response->toResponse($request)->getData(true)['props']`, extraindo o payload sem depender do kernel HTTP completo"

key-files:
  created:
    - tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php
    - .planning/phases/96-nps-anti-burlamento-endurecimento-e-gest-o/deferred-items.md
  modified:
    - app/Services/DesempenhoScoreService.php
    - app/Http/Controllers/PerformanceController.php
    - app/Http/Controllers/DashboardController.php
    - app/Http/Controllers/PortfolioController.php
    - app/Jobs/CalculateGoalResults.php
    - app/Http/Controllers/CompanyController.php
    - tests/Feature/V16/BonusDualPathRegressaoTest.php

key-decisions:
  - "PortfolioController e PerformanceController receberam fixes SEPARADOS (call-sites #4 e #8) — confirmado no RESEARCH que não compartilham a mesma função de agregação NPS, apesar da semelhança superficial"
  - "buildRanking() (call-site #7) recebeu o fix mesmo estando atualmente sem nenhum caller ativo no código (dead code documentado inline como 'back-compat') — o plano exige os 10 call-sites sem exceção, e reativação futura não pode reintroduzir o vazamento"
  - "Testes de controllers privados (userDashboard, buildRanking, renderPortfolio) usam reflection + extração de props via Request com header X-Inertia, em vez de forçar rotas HTTP reais — evita montar cenários de permissão/role complexos (isLider, setoresLiderados) que não agregam sinal ao teste do call-site em si"
  - "3 asserções de #5/#6/#8 usam assertEquals em vez de assertSame — o payload passa por json_decode (toResponse()->getData(true)), que colapsa 5.0/4.0 em inteiro quando o valor é redondo; mesmo padrão já usado em NpsInvalidacaoRespostaTest (96-03)"

requirements-completed: [AB-96-3]

# Metrics
duration: ~110min
completed: 2026-07-17
---

# Phase 96 Plan 04: 10 Call-sites de Agregação Excluem Resposta Invalidada (AB-96-3) Summary

**scopeValida() aplicado nos 10 call-sites de leitura de NpsResponse (bônus, 3 dashboards, histórico de carteira, meta NPS, página da empresa) via eager-load condicional ou filtro direto na query, com 1 teste de exclusão dedicado por call-site e regressão completa do bônus (V16/Desempenho/Nps) 100% verde.**

## Performance

- **Duration:** ~110 min
- **Started:** 2026-07-17
- **Completed:** 2026-07-17
- **Tasks:** 3 completed (RED→GREEN em cada um dos 3 waves de call-sites)
- **Files modified:** 8 (6 de produção + 2 de teste)

## Accomplishments
- **Call-sites #1-#2 (bônus, `DesempenhoScoreService`)**: `notasPorAtribuicao()` (JOIN) ganha `->whereNull('r.invalidated_at')`; `notasLegado()` troca `->with('response')` por `->with(['response' => fn ($q) => $q->valida()])` — o `foreach` já pulava `response === null`, então passou a pular sozinho
- **Call-sites #3-#4 (widgets de carteira, `PerformanceController`)**: mesmo padrão espelhado em `notasNpsDoUsuarioPorResposta()` ramo A (JOIN) e ramo B (legado, preservando `->with(['answers','survey'])` aninhado)
- **Call-sites #5-#7 (dashboards, `DashboardController`)**: `adminDashboard()`/`userDashboard()`/`buildRanking()` — todos os 3 eager-loads de `NpsSurvey::with('response')` trocados pelo padrão de closure `valida()`
- **Call-site #8 (`PortfolioController`)**: "Histórico NPS mensal" — fix SEPARADO do `PerformanceController` (implementações diferentes, confirmado no RESEARCH), mesmo padrão de closure preservando `->with(['answers','survey'])`
- **Call-site #9 (`CalculateGoalResults::computeNps`)**: `->whereNull('invalidated_at')` direto na query `NpsResponse::query()` (sem eager-load — é query solta)
- **Call-site #10 (`CompanyController::show`)**: eager-load de `npsSurveys` filtra a response; builder do payload (`ternário $response ? [...] : null` já existente) passa a emitir `response: null` para a resposta invalidada — **fix 100% backend**, `Companies/Show.jsx` NÃO modificado (confirmado via `git status`)
- **Suíte `NpsInvalidacaoCallSitesTest`**: 10 testes, 1 por call-site, cada um provando a exclusão via cenário "válida + invalidada" com valores discrimináveis (ex.: se a invalidada ainda contasse, a média mudaria de X para Y)
- **`BonusDualPathRegressaoTest`** ganha `test_resposta_invalidada_nao_conta_em_nenhum_dos_dois_ramos` — prova que respostas VÁLIDAS continuam contando normalmente nos dois ramos (atribuições + legado) mesmo com invalidadas no cenário
- Regressão completa: `--filter=Nps` **293/293** passando, `--filter=V16` **160/160** passando, `--filter=Desempenho` **62/63** passando (1 falha pré-existente e não-relacionada, documentada em `deferred-items.md`)

## Task Commits

Cada task seguiu o ciclo RED → GREEN (TDD):

1. **Task 1: Call-sites #1-#4 (bônus) + regressão V16**
   - `a169f59` — `test(96-04)`: checklist call-sites #1-#4 — RED
   - `20bb2a8` — `feat(96-04)`: scopeValida() nos call-sites do bônus + regressão dual-path — GREEN
2. **Task 2: Call-sites #5-#9 (dashboards + metas)**
   - `01bb599` — `test(96-04)`: checklist call-sites #5-#9 — RED
   - `9e02da7` — `feat(96-04)`: scopeValida() nos call-sites de dashboards e metas — GREEN
3. **Task 3: Call-site #10 (página da empresa)**
   - `48566e3` — `test(96-04)`: checklist call-site #10 — RED
   - `73af8e5` — `feat(96-04)`: scopeValida() no call-site #10 (CompanyController::show) — GREEN

**Plan metadata:** (este commit, a seguir)

## Files Created/Modified
- `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php` - checklist executável dos 10 call-sites (novo)
- `.planning/phases/96-.../deferred-items.md` - falha pré-existente não-relacionada documentada (novo)
- `app/Services/DesempenhoScoreService.php` - `notasPorAtribuicao()`/`notasLegado()` filtram invalidated_at (#1, #2)
- `app/Http/Controllers/PerformanceController.php` - `notasNpsDoUsuarioPorResposta()` ramos A/B filtram (#3, #4)
- `app/Http/Controllers/DashboardController.php` - `adminDashboard()`/`userDashboard()`/`buildRanking()` filtram (#5, #6, #7)
- `app/Http/Controllers/PortfolioController.php` - "Histórico NPS mensal" filtra (#8)
- `app/Jobs/CalculateGoalResults.php` - `computeNps()` filtra (#9)
- `app/Http/Controllers/CompanyController.php` - eager-load `npsSurveys` filtra (#10)
- `tests/Feature/V16/BonusDualPathRegressaoTest.php` - cenário dual-ramo de regressão da invalidação

## Decisions Made
- `PortfolioController` e `PerformanceController` tratados como fixes independentes (call-sites #4 e #8) — o RESEARCH confirmou que não compartilham a mesma função de agregação NPS
- `buildRanking()` recebeu o fix apesar de hoje não ter caller ativo — o checklist do plano é exaustivo por design (Pitfall 4: "esquecer 1 call-site deixa a invalidação meio-contando"); reativação futura do widget não pode reintroduzir o vazamento
- Testes de métodos privados de controller (`userDashboard`, `buildRanking`, `renderPortfolio`) usam reflection + extração de props via `Request` com header `X-Inertia: true`, evitando montar cenários de permissão complexos (líder de setor, `setoresLiderados`) que não agregam sinal à prova de exclusão em si
- 3 asserções trocadas para `assertEquals` (em vez de `assertSame`) onde o valor passa por `json_decode` via `toResponse()->getData(true)` — `5.0` vira inteiro `5` sem `JSON_PRESERVE_ZERO_FRACTION`, mesmo padrão já documentado em `NpsInvalidacaoRespostaTest` (Plano 96-03)

## Deviations from Plan
None - plan executado exatamente como escrito. Os 10 call-sites do checklist (`<interfaces>`) foram todos endereçados; nenhuma funcionalidade adicional foi necessária.

## Issues Encountered
- `PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200` falha com 403 em vez de 200 — reproduzido em isolamento ANTES de qualquer mudança desta sessão, confirmando que é pré-existente e não uma regressão do Plano 96-04 (o teste não toca `NpsResponse`, invalidação, nem nenhum dos 6 arquivos de produção modificados). Documentado em `deferred-items.md`, fora do escopo deste plano (Rule: Scope Boundary).

## User Setup Required
None — nenhuma configuração de serviço externo necessária. As mudanças são puramente de leitura (filtro em queries já existentes), nenhuma migration nova.

## Next Phase Readiness
AB-96-3 (invalidação manual + consistência total nas agregações) está **completo**: fundação (Plano 96-03) + os 10 call-sites externos (este plano). A Fase 96 tem mais 1 plano pendente (96-05, conforme `96-05-PLAN.md` já presente no diretório) — não investigado nesta execução, fora do escopo deste plano.

Nenhum bloqueio identificado.

## Self-Check: PASSED

Arquivos criados confirmados em disco:
- `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php` — FOUND
- `.planning/phases/96-nps-anti-burlamento-endurecimento-e-gest-o/deferred-items.md` — FOUND

Commits confirmados via `git log --oneline`:
- `a169f59` (test, Task 1 RED) — FOUND
- `20bb2a8` (feat, Task 1 GREEN) — FOUND
- `01bb599` (test, Task 2 RED) — FOUND
- `9e02da7` (feat, Task 2 GREEN) — FOUND
- `48566e3` (test, Task 3 RED) — FOUND
- `73af8e5` (feat, Task 3 GREEN) — FOUND

Regressão confirmada:
- `--filter=NpsInvalidacaoCallSitesTest`: 10/10 passando (59 assertions)
- `--filter=Nps`: 293/293 passando (1901 assertions)
- `--filter=V16`: 160/160 passando (770 assertions)
- `--filter=Desempenho`: 62/63 passando (1 falha pré-existente não-relacionada — ver Issues Encountered)

---
*Phase: 96-nps-anti-burlamento-endurecimento-e-gest-o*
*Completed: 2026-07-17*
