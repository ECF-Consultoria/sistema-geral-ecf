---
phase: 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev
plan: 01
subsystem: api
tags: [laravel, metrics, adman, shopee, cache-versioning, tdd]

# Dependency graph
requires:
  - phase: 101-admanmetricdiffservice-v18-0
    provides: "AdmanMetricDiffService::compute() com resolveField()/resolveMargemPct() e cache versionado"
  - phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o
    provides: "ShopeeMetricDiffService espelhando o contrato do Adman, margem sempre null"
  - phase: 110-fix-margem-adman-preferir-fallback-local-deterministico-blin
    provides: "MARGEM_COBERTURA_MINIMA e o histórico de bumps de cache do diff service"
provides:
  - "prev_value exposto nas 3 métricas de ambas as fontes (Adman + Shopee), sem gate de comparison_mode"
  - "diff_pp (pontos percentuais) exposto só em contribution_margin_pct, gateado por previous_equal_length_window + value/prev_value numéricos"
  - "quality.diff_pp_disponivel — indicador informativo de cobertura, só no Adman"
  - "cache versionado adman:diff:v6: e shopee:diff:v2: — shape antigo nunca é servido pós-deploy"
  - "instrumentação pronta para a Fase 119 consumir diff_pp na nota de margem, condicionado ao gate do probe (Plano 117-02)"
affects: [119-fase-consumo-diff-pp-nota-margem, 121-agregacao-cobertura-carteira, 123-ui-diff-pp]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "diff_pp gateado por comparison_mode + ambos operandos numéricos (mesmo padrão do gate adman_diff já existente)"
    - "chave AUSENTE (não null) para campos que não se aplicam à métrica — diff_pp só em contribution_margin_pct"
    - "bump de cache versionado com comentário datado no histórico (v5→v6, v1→v2)"
    - "indicador de qualidade informativo (diff_pp_disponivel) que NÃO influencia a política de TTL"

key-files:
  created:
    - .planning/phases/117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev/deferred-items.md
  modified:
    - app/Services/Metrics/AdmanMetricDiffService.php
    - app/Services/Metrics/ShopeeMetricDiffService.php
    - tests/Feature/V18/AdmanMetricDiffServiceTest.php
    - tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php

key-decisions:
  - "margemNula() da Shopee dividida em margemValorNula()/margemPctNula() (Rule 1 — o <action> literal do plano contradizia o must_have/D-06 e a asserção de shape do próprio plano)"
  - "quality.diff_pp_disponivel entra SÓ no AdmanMetricDiffService — Shopee nunca teria diff_pp, indicador seria ruído puro (decisão já registrada no plano, D-08)"
  - "prev_value NÃO é gateado por comparison_mode; só diff_pp é gateado (D-05/D-07)"
  - "as 14 falhas em Desempenho + 3 em Portfolio + 2 em Carteira encontradas no gate de não-regressão são PRÉ-EXISTENTES (confirmado bit-a-bit contra o código anterior à Fase 117) — documentadas em deferred-items.md, não corrigidas (fora de escopo)"

requirements-completed: [MPP-01, MPP-02, MPP-03, MPP-05, MPP-06]

# Metrics
duration: ~90min
completed: 2026-07-28
---

# Phase 117 Plan 01: Contrato aditivo prev_value/diff_pp Summary

**`AdmanMetricDiffService` e `ShopeeMetricDiffService` passam a expor `prev_value` (3 métricas) e `diff_pp` em pontos percentuais (só `contribution_margin_pct`), com cache versionado (`v6`/`v2`) e zero mudança de comportamento para consumidores existentes — caso âncora `27.47`/`24.08` → `diff_pp=3.39` provado sem derivar de `diff_pct=14.09`.**

## Performance

- **Duration:** ~90 min
- **Completed:** 2026-07-28
- **Tasks:** 3/3 completas
- **Files modified:** 4 (2 services + 2 test suites) + 1 arquivo novo (deferred-items.md)

## Accomplishments
- `AdmanMetricDiffService::resolveField()`/`resolveMargemPct()` expõem `prev_value` incondicional (D-05) e `diff_pp` gateado por `previous_equal_length_window` + `value`/`prev_value` numéricos (D-07)
- `quality.diff_pp_disponivel` — indicador informativo de cobertura, sem alterar `status` nem a política de TTL (D-08)
- `ShopeeMetricDiffService` espelha `diff_pp = null` sempre (Shopee não tem CMV), com `prev_value` real em `revenue`/`investment` (custo zero, já calculado)
- Cache bumpado `adman:diff:v5:`→`v6:` e `shopee:diff:v1:`→`v2:` — provado em teste que shape antigo nunca é servido pós-bump
- Caso âncora MPP-06 provado: `value=27.47` + `prev=24.08` ⇒ `diff_pp=3.39`, `diff_pct` inalterado em `14.09`
- Gate de não-regressão (Task 3): auditados todos os call-sites de `metrics.*` fora de `Services/Metrics/` — 100% acesso nominal (`['key'] ?? null`), nenhum posicional/`array_keys()` estrito/`count()`. `diff_pp` confirmado não-consumido por ninguém (`app/` e `resources/js/`)

## Task Commits

1. **Task 1: Shape aditivo no AdmanMetricDiffService** - `3137814a` (feat)
2. **Task 2: diff_pp null simétrico e prev_value no ShopeeMetricDiffService** - `00dcfdf9` (feat)
3. **Task 3: Gate de não-regressão** - sem commit de código (task só de auditoria; achados registrados em `deferred-items.md`, incluído no commit final de metadados)

## Files Created/Modified
- `app/Services/Metrics/AdmanMetricDiffService.php` - `resolveField()`/`resolveMargemPct()` ganham `prev_value`/`diff_pp`; `emptyMetrics()` deixa de ser uniforme; `buildQuality()` ganha `diff_pp_disponivel`; cache `v6`
- `app/Services/Metrics/ShopeeMetricDiffService.php` - `calcularRevenue()`/`calcularInvestimento()` ganham `prev_value`; `margemNula()` dividida em `margemValorNula()`/`margemPctNula()`; cache `v2`
- `tests/Feature/V18/AdmanMetricDiffServiceTest.php` - shape assertion atualizada + 8 cenários novos (âncora, gate D-07, prev_value nas 3 métricas, bump de cache, `diff_pp_disponivel` x2, `emptyMetrics()`)
- `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` - shape assertion atualizada + 5 cenários novos (diff_pp sempre null, prev_value real, prev_value null-aware, bump de cache, guarda do placeholder Fase 109)
- `.planning/phases/117-.../deferred-items.md` - 19 falhas de teste pré-existentes (não causadas por esta fase) documentadas com prova de não-regressão

## Decisions Made

- **Divisão de `margemNula()` (Shopee) em duas funções** — o texto literal do `<action>` do plano (Task 2, item 1) instruía uma única `margemNula()` com `diff_pp` sempre presente, que seria reusada tanto para `contribution_margin_value` quanto `contribution_margin_pct`. Isso contradiz diretamente o `must_haves.truths` ("`contribution_margin_value` NÃO tem a chave `diff_pp`") e a asserção de shape do próprio plano/VALIDATION.md (`contribution_margin_value` ⇒ 4 chaves, sem `diff_pp`). Apliquei Rule 1 (bug de especificação interna) e segui a versão repetida 3× (CONTEXT D-06 + decisões item 2 do PLAN + VALIDATION shape test) em vez do snippet abreviado — criei `margemValorNula()` (4 chaves) e `margemPctNula()` (5 chaves, com `diff_pp` sempre null).
- **Dois testes com `Http::fake()` duplo divididos em métodos separados** — durante a Task 1, os cenários `test_p` e `quality_diff_pp_disponivel` originalmente chamavam `fakeAdmanEndpoints()`/`Http::fake()` duas vezes no mesmo teste (uma vez por cenário). Descobri que o Laravel NÃO substitui um fake anterior — os stubs se acumulam e o primeiro registrado ainda casa primeiro (confirmado empiricamente, 2 testes vermelhos). Corrigido dividindo cada um em dois métodos de teste isolados (`test_p`/`test_p2`, e os dois `quality_diff_pp_disponivel_*`), cada um com seu próprio `Http::fake()` fresco — mesmo padrão já usado pelos testes existentes do arquivo (nenhum teste pré-existente chamava `fakeAdmanEndpoints()` duas vezes no mesmo método).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug de especificação interna] `margemNula()` dividida em `margemValorNula()`/`margemPctNula()`**
- **Found during:** Task 2
- **Issue:** O `<action>` do plano (item 1) instruía uma única `margemNula()` retornando 5 chaves (incluindo `diff_pp`) reusada para `contribution_margin_value` E `contribution_margin_pct` — mas isso violaria o `must_have.truths` ("`contribution_margin_value` NÃO tem a chave `diff_pp`") e a asserção de shape explícita repetida no CONTEXT (D-06) e no VALIDATION.md
- **Fix:** Criadas duas funções: `margemValorNula()` (4 chaves, sem `diff_pp`, usada por `contribution_margin_value`) e `margemPctNula()` (5 chaves, `diff_pp` sempre null, usada por `contribution_margin_pct`)
- **Files modified:** app/Services/Metrics/ShopeeMetricDiffService.php
- **Verification:** `test_shape_identico_ao_adman` e `test_margem_diff_pp_sempre_null` (novo) provam o shape correto por métrica
- **Committed in:** 00dcfdf9 (Task 2 commit)

**2. [Rule 1 - Bug de teste] Cenários com `Http::fake()` duplicado no mesmo teste**
- **Found during:** Task 1 (durante execução dos testes, 2 falhas)
- **Issue:** `Http::fake()` chamado 2x no mesmo método de teste NÃO substitui o fake anterior — os stubs se acumulam e o primeiro registrado casa primeiro, mascarando o segundo cenário
- **Fix:** Dividido cada teste afetado em dois métodos independentes, cada um com seu próprio `Http::fake()` fresco
- **Files modified:** tests/Feature/V18/AdmanMetricDiffServiceTest.php
- **Verification:** Todos os 25 testes do arquivo verdes após a correção
- **Committed in:** 3137814a (Task 1 commit)

---

**Total deviations:** 2 auto-fixed (2 bugs de especificação/teste, Rule 1)
**Impact on plan:** Ambas as correções foram necessárias para que os testes provassem o comportamento correto especificado pelo próprio plano (CONTEXT/VALIDATION). Nenhum scope creep — nenhuma funcionalidade nova além do que o plano pediu.

## Issues Encountered

**Gate de não-regressão (Task 3) encontrou 19 testes vermelhos fora do escopo desta fase** (14 em `--filter=Desempenho`, 3 em `--filter=Portfolio`, 2 adicionais em `--filter=Carteira` — com sobreposição das 2 falhas de Portfolio já contadas). Investigação: para cada grupo, revertida temporariamente a implementação dos dois service files (`git show a166f8da:<path>`, o commit imediatamente anterior ao início desta fase) e re-executada a MESMA suíte — resultado idêntico byte-a-byte (`14 failed, 91 passed (366 assertions)` em ambos os casos para Desempenho; mesmas 3 falhas de Portfolio; mesmas 2 falhas de Carteira). Isso confirma que são **falhas pré-existentes**, não regressões desta fase. Duas delas (`DesempenhoPeriodoOficialTest` e `CarteiraPeriodoDiffTest`) parecem ter causa raiz identificável: os testes assumem um `calculated_fallback` de margem % que foi deliberadamente removido pelo hotfix `a413e823` (2026-07-24), anterior a esta fase. Documentado integralmente em `deferred-items.md` — nenhuma correção aplicada (fora de escopo, `SCOPE BOUNDARY` do executor).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `prev_value`/`diff_pp` disponíveis nas duas fontes de métrica, prontos para consumo pela Fase 119 — **bloqueado** até o gate do probe (Plano 117-02) aprovar (D-12)
- Cache versionado (`v6`/`v2`) garante que nenhum consumidor em produção verá o shape antigo após o deploy
- **Nota operacional de deploy:** o bump `v5→v6` (Adman) e `v1→v2` (Shopee) esfria o cache do diff no dia do deploy — a 1ª carga pós-deploy da Carteira dispararia N chamadas Adman ao vivo. Mitigação: rodar `php artisan adman:warm-diff` e `php artisan shopee:warm-diff` logo após o deploy. **Deploy não executado nesta sessão** — autorização é do usuário.
- 19 falhas de teste pré-existentes fora de escopo documentadas em `deferred-items.md` para tratamento futuro (dívida técnica, não bloqueante para esta fase)

---
*Phase: 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev*
*Completed: 2026-07-28*

## Self-Check: PASSED

- FOUND: app/Services/Metrics/AdmanMetricDiffService.php
- FOUND: app/Services/Metrics/ShopeeMetricDiffService.php
- FOUND: tests/Feature/V18/AdmanMetricDiffServiceTest.php
- FOUND: tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php
- FOUND: .planning/phases/117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev/deferred-items.md
- FOUND: commit 3137814a (Task 1)
- FOUND: commit 00dcfdf9 (Task 2)
