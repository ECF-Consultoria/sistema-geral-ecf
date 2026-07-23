---
phase: 110-fix-margem-adman-preferir-fallback-local-deterministico-blin
plan: 01
subsystem: metrics
tags: [adman, margem, cache, tdd, desempenho, bonus]

# Dependency graph
requires:
  - phase: 101-admanmetricdiffservice-v18-0
    provides: "AdmanMetricDiffService::resolveField() + guards de dias-comuns (somasComGuards/fallbackMargemPct)"
  - phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o
    provides: "cacheKey v10 (Shopee no score/faturamento/margem)"
provides:
  - "contribution_margin_pct resolvido com prioridade local determinística + gate de cobertura por dias-com-linha"
  - "cacheKey do desempenho bumpada v10→v11"
affects: [110-02-consolidar-mes-desempenho, desempenho-bonus, transparencia-carteira]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate de cobertura mínima (80%) por dias-COM-LINHA (não dias-calendário) antes de confiar em fallback local"
    - "Helper compartilhado offsetsComunsComLinha() extraído de somasComGuards() — fonte única da mecânica de interseção de offsets"

key-files:
  created: []
  modified:
    - app/Services/Metrics/AdmanMetricDiffService.php
    - app/Services/DesempenhoScoreService.php
    - tests/Feature/V18/AdmanMetricDiffServiceTest.php
    - tests/Feature/DesempenhoShopeeScoreTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php

key-decisions:
  - "Prioridade da margem % invertida SÓ para contribution_margin_pct — revenue e contribution_margin_value continuam via resolveField() original (escopo estrito, sem regressão de faturamento/carteira)"
  - "Cobertura medida por dias-COM-LINHA (revenue como proxy de 'a empresa sincronizou nesse dia'), não por dias-calendário — evita penalizar empresa que não vende todo dia"
  - "Gate de 80% de cobertura mínima (MARGEM_COBERTURA_MINIMA) antes de confiar no local"
  - "Null EXPLÍCITO (sem fail-open) quando local insuficiente E ao-vivo indisponível — não polui n_com_margem_real"
  - "cacheKey bumpada v10→v11 porque o valor computado da margem muda"

patterns-established:
  - "resolveMargemPct(): wrapper específico por métrica quando a prioridade difere do resolveField() genérico"

requirements-completed: [FIXMARG-01, FIXMARG-02]

# Metrics
duration: ~35min
completed: 2026-07-23
---

# Phase 110 Plan 01: Fix margem Adman (fallback local determinístico) Summary

**`AdmanMetricDiffService::contribution_margin_pct` passa a preferir o `calculated_fallback` local determinístico (SUM local/SUM local) sobre o `.diff` nativo ao vivo da Adman quando a cobertura de dias-com-linha é ≥80%; ao-vivo vira último recurso e null explícito substitui o fail-open silencioso — cacheKey do desempenho bumpada v10→v11.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 2/2
- **Files modified:** 6

## Accomplishments
- `resolveMargemPct()` novo em `AdmanMetricDiffService` inverte a prioridade só para `contribution_margin_pct`: local determinístico primeiro (cobertura ≥80% dos dias-com-linha), `.diff` nativo ao vivo como último recurso, null explícito quando ambos faltam
- `coberturaMargem()` + helper compartilhado `offsetsComunsComLinha()` (extraído de `somasComGuards()`, sem query divergente) — denominador é dias-com-linha (via `revenue` como proxy de "empresa sincronizou nesse dia"), não dias-calendário
- 6 testes de comportamento cobrindo: cobertura suficiente prefere local mesmo com `.diff` presente e diferente; determinismo sob 3 chamadas com ao-vivo oscilando (rate-limit simulado); cobertura por dias-com-linha vs calendário (empresa que vende em dias alternados); cobertura insuficiente real cai pro ao-vivo; empresa 0/0 + ao-vivo indisponível = null explícito; revenue/margem R$ inalterados (escopo estrito)
- `DesempenhoScoreService::cacheKey()` bumpada `v10`→`v11` com comentário de versão; 6 strings hardcoded de teste atualizadas no mesmo commit (0 ocorrências de `compute.v10` restantes no repo)

## Task Commits

Each task was committed atomically:

1. **Task 1a (RED): testes de comportamento da prioridade local + cobertura** - `b75065a8` (test)
2. **Task 1b (GREEN): resolveMargemPct + coberturaMargem + offsetsComunsComLinha** - `d76725be` (feat)
3. **Task 2: bump cacheKey v10→v11 + strings de teste** - `fa0090e2` (chore)

_TDD: RED (test) → GREEN (feat) para a Task 1, conforme `tdd="true"` do plano._

## Files Created/Modified
- `app/Services/Metrics/AdmanMetricDiffService.php` — `MARGEM_COBERTURA_MINIMA` (0.8), `resolveMargemPct()`, `coberturaMargem()`, `offsetsComunsComLinha()` (refatorado de `somasComGuards()`)
- `app/Services/DesempenhoScoreService.php` — `cacheKey()` v10→v11 + comentário de versão
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` — 6 novos testes (cenários g-l) + 3 helpers de fixture (`periodoJanelaIgual30Dias`, `semearAdmanMetricAlternado`, `semearRevenueTodoDiaMargemParcial`)
- `tests/Feature/DesempenhoShopeeScoreTest.php` — string/nome de teste v10→v11
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` — 4 strings v10→v11
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — 2 strings v10→v11

## Decisions Made
- Escopo estrito: só `contribution_margin_pct` teve a prioridade invertida — `revenue` e `contribution_margin_value` continuam via `resolveField()` original (validado por teste de regressão explícito, cenário l).
- Denominador de cobertura usa `revenue` como proxy de "dia com linha" (não um campo de calendário) — reaproveita a mesma mecânica de guards já cicatrizada em `somasComGuards`, extraída para `offsetsComunsComLinha()` compartilhado (fonte única, sem query divergente).
- `value` do resultado preserva `marginPctAdman['value']` quando presente, mesmo no ramo local-preferido, para não rebaixar `quality.status` para `'missing'` indevidamente.

## Deviations from Plan

None - plan executado exatamente como especificado (interfaces, comportamento e testes seguiram o `110-01-PLAN.md` à risca).

## Issues Encountered

Durante a regressão ampla (V16/V18/Nps/Desempenho, 309 testes), 1 falha pré-existente e fora de escopo foi detectada: `Tests\Feature\PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200` (espera 200, recebe 403). Nenhum dos 6 arquivos deste plano tem relação com a rota `/publicacao/desempenho`, `EnsureUserHasRole` ou permissões MLB dashboard — confirmado via `git log` (última alteração nesses arquivos é de um commit anterior não-relacionado, `797366cb`). Não corrigido (Scope Boundary — fora do escopo do 110-01). Documentado em `.planning/phases/110-fix-margem-adman-preferir-fallback-local-deterministico-blin/deferred-items.md`.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `contribution_margin_pct` agora determinístico para empresas com boa cobertura local — a instabilidade documentada em `.planning/debug/margem-adman-diff-instavel.md` (root cause) deixa de afetar essas empresas.
- `PortfolioController::transparencia()` consome o MESMO `AdmanMetricDiffService::compute()` — o valor exibido nessa tela vai refletir o determinístico local após deploy. Verificação visual pendente (item INFO da `<verification>` do plano, não bloqueia esta entrega): conferir carteira do Luiz/Danilo pós-deploy.
- Plano 110-02 (blindar `ConsolidarMesDesempenho` com retry/reconciliação) segue independente, consumindo esta base.
- Falha pré-existente em `PublicacaoDesempenhoRouteTest` fica registrada em `deferred-items.md` para investigação futura fora da Fase 110.

---
*Phase: 110-fix-margem-adman-preferir-fallback-local-deterministico-blin*
*Completed: 2026-07-23*

## Self-Check: PASSED

- FOUND: app/Services/Metrics/AdmanMetricDiffService.php
- FOUND: app/Services/DesempenhoScoreService.php
- FOUND: tests/Feature/V18/AdmanMetricDiffServiceTest.php
- FOUND: tests/Feature/DesempenhoShopeeScoreTest.php
- FOUND: tests/Feature/V18/DesempenhoMetadadosCacheTest.php
- FOUND: tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
- FOUND commit: b75065a8 (test RED)
- FOUND commit: d76725be (feat GREEN)
- FOUND commit: fa0090e2 (chore cacheKey)
