---
phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o
plan: 03
subsystem: api
tags: [shopee, desempenho, bonus, metrics, laravel, php-services, cron]

# Dependency graph
requires:
  - phase: 109-01
    provides: "ShopeeMetricDiffService::compute(), MetricDiffDispatcher::compute(company, periodo, source), CarteiraContextService com setor shopee elegível"
  - phase: 109-02
    provides: "Padrão fontesFinanceirasPorEmpresa() (desempate adman-vence) já aplicado na Carteira — espelhado aqui no Desempenho"
provides:
  - "DesempenhoScoreService roteando faturamento/margem por fonte financeira via MetricDiffDispatcher"
  - "margemPontos() — blend ponderado por contagem entre margem real (Adman) e placeholder=1.0 (Shopee), regressão zero para só-performance"
  - "score_status tolerante a Shopee (só-Shopee deixa de ser blocked/partial quando há faturamento com baseline)"
  - "cacheKey v10 (bump obrigatório — Shopee agora entra no score/ranking)"
  - "WarmShopeeDiffCache (shopee:warm-diff) agendado após o sync Shopee"
affects: [109-04-checkpoint]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Blend ponderado por contagem (margemPontos): numerador = pReal×nComMargemReal + placeholder×nShopeePlaceholder; denominador = nComMargemReal+nShopeePlaceholder — generaliza pra N fontes futuras sem refatorar a fórmula"
    - "Contador pós-invalidação recomputado a partir de $companies já filtrado (nunca reaproveitar contador cru pré-filtro de computeUniverso) — mesmo padrão de computeVarFaturamento/computeVarMargem, agora também aplicado ao denominador do blend de margem"
    - "cacheKey bump documentado inline no docblock (histórico de todos os bumps v1-v10) — nunca hardcode o formato da chave fora de cacheKey()"

key-files:
  created:
    - app/Console/Commands/WarmShopeeDiffCache.php
    - tests/Feature/DesempenhoShopeeScoreTest.php
    - tests/Feature/WarmShopeeDiffCacheCommandTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - app/Models/ShopeeMetric.php
    - routes/console.php
    - resources/js/Pages/Performance/Index.jsx
    - tests/Feature/V16/DesempenhoElegibilidadeTest.php
    - tests/Feature/V16/ComparacaoContextualBlockedTest.php
    - tests/Feature/V16/PerformanceIndexMetadadosTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
    - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php

key-decisions:
  - "margem placeholder=1.0 implementada EXATAMENTE como o <design_margem_placeholder> do plano — blend ponderado, não substituição/exclusão"
  - "computeNotaFinal/computeScoreStatus passam a receber margemPontos já calculado (não mais var_margem_pct bruto) — reguaMargem() só é chamada de dentro de margemPontos() agora"
  - "'blocked' deixou de ser sinônimo de 'só-Shopee' — hoje só ocorre com vínculo em setor sem QUALQUER fonte financeira (polos/publicação/outros); testes legados que codificavam Shopee=blocked foram migrados pra usar SETOR_POLOS como fixture do cenário blocked"
  - "componentes.var_margem_pct continua sendo a % REAL (nunca o placeholder) — só pontos_componentes.margem reflete o blend, preservando o significado do número exposto na UI/auditoria"

patterns-established:
  - "UI: quando var_margem_pct real é null mas pontos_componentes.margem não é, é sinal inequívoco de placeholder ativo (nShopeePlaceholder>0) — usado no Performance/Index.jsx pra trocar '—' por um indicador 'Shopee' sem precisar expor a fonte por linha"

requirements-completed: [SHOP-DES-01, SHOP-DES-02]

duration: ~35min
completed: 2026-07-23
---

# Phase 109 Plan 03: Shopee no Desempenho (score/ranking) com margem placeholder=1 Summary

**DesempenhoScoreService roteando faturamento/margem por fonte financeira via MetricDiffDispatcher, com margem placeholder=1.0 para empresas Shopee via blend ponderado por contagem (margemPontos), preservando regressão zero para quem é só-performance — cacheKey v10 + warm cache Shopee agendado.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 3/3 completos
- **Files modified:** 13 (3 criados, 10 modificados)

## Accomplishments

- `DesempenhoScoreService` injeta `MetricDiffDispatcher` (substitui `AdmanMetricDiffService` direto) — `computeUniverso()` monta o mapa `fontes` (company_id → financial_source vencedora, desempate travado: 'adman' vence quando a mesma empresa tem os dois vínculos elegíveis, texto idêntico ao Plano 02/Carteira) e `computeVarFaturamento`/`computeVarMargem` roteiam por essa fonte.
- Helper `margemPontos(?float $varMargemReal, int $nComMargemReal, int $nShopeePlaceholder): ?float` implementado exatamente como o `<design_margem_placeholder>` do plano: blend ponderado por contagem entre a margem real (régua'd) e o placeholder=1.0 das empresas Shopee. Só-performance (nShopeePlaceholder=0) devolve o valor IDÊNTICO a `reguaMargem($varMargemReal)` — regressão zero comprovada algebricamente e por teste dedicado.
- `computeScoreStatus`/`computeNotaFinal` passam a receber `margemPontos` já calculado (não mais `var_margem_pct` bruto) — `pontos_componentes.margem = margemPontos`; `componentes.var_margem_pct` continua expondo a % REAL, sem contaminação do placeholder.
- `nShopeePlaceholder` é recomputado a partir do `$companies` **já filtrado por `$invalidadas`** (paridade com `computeVarFaturamento`/`computeVarMargem`) — empresa Shopee invalidada na competência não infla o denominador do blend (testado com um cenário que discrimina entre o resultado correto 2.50 e o resultado com bug 2.00).
- `score_status='blocked'` deixou de ser sinônimo de "só-Shopee": com `financial_metrics_eligible=true` para Shopee (Fase 109-01), `vinculos_financeiros` conta o vínculo Shopee — `blocked` só ocorre hoje com vínculo em setor SEM qualquer fonte financeira (`polos`/`publicação`/`outros`).
- `cacheKey()` bumpado de `desempenho.compute.v9` para `desempenho.compute.v10` (Shopee agora entra no score/ranking — servir valores v9 do Redis continuaria omitindo Shopee do bônus). Todas as strings hardcoded (`Phase96`, `V18`) atualizadas no mesmo commit — `grep 'desempenho.compute.v9'` no repo retorna 0.
- `WarmShopeeDiffCache` (`shopee:warm-diff`) criado espelhando `WarmAdmanDiffCache`: aquece `ShopeeMetricDiffService::compute()` (mês atual + mês fechado) para empresas com token Shopee ERP ativo E/OU vínculo elegível em `company_users` no setor Shopee. Agendado às 11:35 (após `shopee:sync-ads` 11:30, antes do `adman:warm-diff` 11:40).
- Docblock de `ShopeeMetric` atualizado — deixa de dizer "EXCLUSIVAMENTE Dashboard Shopee".
- `Performance/Index.jsx`: coluna "Var Margem" mostra um indicador "Shopee" (em vez de "—" puro) quando `var_margem_pct` real é `null` mas `pontos_componentes.margem` não é (sinal inequívoco de placeholder ativo); label/tooltip do `score_status='blocked'` deixou de mencionar Shopee especificamente.

## Task Commits

1. **Task 1+2 (combinadas — dispatch por fonte + margem placeholder, ver Deviations)**: `DesempenhoScoreService` roteando por fonte, helper `margemPontos`, cacheKey v10, testes legados atualizados, `DesempenhoShopeeScoreTest` novo (7 testes) - `1c36a81c` (feat)
2. **Task 3**: `WarmShopeeDiffCache` + agendamento + docblock `ShopeeMetric` + ajuste UI ranking + `npm run build` + `WarmShopeeDiffCacheCommandTest` novo (3 testes) - `95ea74f2` (feat)

## Files Created/Modified

- `app/Services/DesempenhoScoreService.php` — dispatch por fonte (`MetricDiffDispatcher`), mapa `fontes`, helper `margemPontos`, `cacheKey` v10.
- `app/Console/Commands/WarmShopeeDiffCache.php` — warm cache do diff Shopee, espelha `WarmAdmanDiffCache`.
- `app/Models/ShopeeMetric.php` — docblock atualizado (alimenta Carteira + Desempenho, não só Dashboard).
- `routes/console.php` — agenda `shopee:warm-diff` às 11:35.
- `resources/js/Pages/Performance/Index.jsx` — indicador "Shopee" na coluna Var Margem quando placeholder ativo; label/tooltip de `blocked` corrigido.
- `tests/Feature/DesempenhoShopeeScoreTest.php` — 7 testes novos (dispatch faturamento, desempate, regressão zero, só-Shopee, misto blend, invalidação, cacheKey v10).
- `tests/Feature/WarmShopeeDiffCacheCommandTest.php` — 3 testes novos (token ativo, vínculo sem token, `--period`).
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php`, `ComparacaoContextualBlockedTest.php`, `PerformanceIndexMetadadosTest.php`, `tests/Feature/V18/DesempenhoMetadadosCacheTest.php`, `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — expectativas atualizadas pro comportamento novo (só-Shopee não é mais blocked; cenário "blocked" genuíno migrado pra setor Polos).
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — strings `desempenho.compute.v9` → `v10`.

## Decisions Made

- **Margem placeholder implementada exatamente como o design travado** — blend ponderado por contagem (`margemPontos`), não substituição nem exclusão. Decisão do usuário 2026-07-23, documentada no `109-CONTEXT.md`/`109-03-PLAN.md`.
- **`computeNotaFinal`/`computeScoreStatus` mudam de assinatura** (3º parâmetro passa a ser `margemPontos` já régua'd, não mais `var_margem_pct` bruto) — decisão explícita do plano, propagada para o teste de reflection `Phase74/DesempenhoScoreServiceTest::test_nota_final_aplica_reguas_1_5_e_media` (valores de entrada convertidos de % bruta pra pontos já régua'd; resultados numéricos idênticos, regressão zero confirmada).
- **`blocked` deixa de ser sinônimo de "só-Shopee"** — os testes legados que fixavam esse cenário (`DesempenhoElegibilidadeTest`, `ComparacaoContextualBlockedTest`, `PerformanceIndexMetadadosTest`) foram migrados para usar `Servico::SETOR_POLOS` (setor sem qualquer fonte financeira) como fixture do cenário genuinamente `blocked`, preservando a cobertura de regressão do status em si.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Test expectation] 8 testes legados (V16/V18/Phase74) codificavam "só-Shopee = blocked/nota null" — comportamento intencionalmente superado por esta fase**
- **Found during:** Verificação da regressão conhecida deixada pelo 109-01-SUMMARY.md ("Regressão Conhecida — Deferida aos Planos 02/03").
- **Issue:** `financial_metrics_eligible=true` para Shopee (Fase 109-01) + margem placeholder (Fase 109-03) mudam o `score_status` de "só-Shopee" de `blocked`/nota `null` para `official`/`partial` com nota real — os 8 testes asseravam o comportamento ANTIGO.
- **Fix:** Reescritos para refletir o comportamento novo (travado pelo design do plano): `DesempenhoElegibilidadeTest::test_so_shopee_recebe_blocked_...` virou `test_so_shopee_produz_score_official_com_margem_placeholder_1` (com fixture `ShopeeMetric` real); `vinculos_financeiros`/`vinculos_sem_fonte_financeira` recalculados nos cenários mistos/dedup; `ComparacaoContextualBlockedTest`/`PerformanceIndexMetadadosTest` migraram o fixture do cenário `blocked` de Shopee para `SETOR_POLOS`; `DesempenhoMetadadosCacheTest` reescrito com os novos valores esperados (`partial` sem baseline, `vinculos_financeiros=2` no misto); `Phase74/DesempenhoScoreServiceTest::test_nota_final_aplica_reguas_1_5_e_media` (chamada via reflection) teve os argumentos de margem convertidos de % bruta pra pontos já régua'd (mudança de assinatura de `computeNotaFinal`).
- **Files modified:** os 5 arquivos de teste listados acima.
- **Verification:** suíte de regressão ampla (`V16`, `V18`, `Phase74`, `Phase96`, `Phase106`, `Dashboard`, `DesempenhoShopeeScoreTest`, `WarmShopeeDiffCacheCommandTest`, `PortfolioShopeeCarteiraTest`, `CompanyPortfolioAccessTest`) — **329 testes, 0 falhas**.
- **Committed in:** `1c36a81c` (Task 1+2 commit)

### Combinação de tasks no commit (decisão pragmática, não Rule 1-4)

**Tasks 1 e 2 foram commitadas JUNTAS** (`1c36a81c`), não em 2 commits separados como o plano sugeria. Motivo: `computeVarMargem` (Task 1, adicionou o parâmetro `$fontes` e `n_com_margem_real`) e `margemPontos`/`computeNotaFinal`/`computeScoreStatus` (Task 2) foram escritos no mesmo bloco coeso de edição do arquivo — separar exigiria hunk-splitting manual arriscado do mesmo diff intercalado, mesmo padrão pragmático já documentado no 109-02-SUMMARY.md.

---

**Total deviations:** 1 auto-fixed (Rule 1, atualização de expectativa de teste explicitamente prevista pelo plano) + 1 decisão pragmática de agrupamento de commit (documentada, não uma correção de bug).
**Impact on plan:** Nenhum scope creep — o fix é exatamente o trabalho que o `109-01-SUMMARY.md` e o `109-03-PLAN.md` (Task 2, "Atualizar também os testes existentes que assumem só-Shopee = blocked/nota null") previram para este plano.

## Issues Encountered

- Ambiente local não tem MySQL/MariaDB rodando (`mysqld` não está no `tasklist` — problema de ambiente pré-existente e conhecido, ver memória do projeto `project_mariadb_local_corrompido`). O comando `shopee:warm-diff` foi validado indiretamente via `WarmShopeeDiffCacheCommandTest` (SQLite in-memory, `RefreshDatabase`) rodando o comando de ponta a ponta com `$this->artisan(...)->assertExitCode(0)` — cobertura equivalente à execução manual, sem depender do MySQL local.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. O cron do VPS já roda `schedule:run` a cada minuto (configuração existente); o novo agendamento `shopee:warm-diff` entra automaticamente no próximo deploy.

## Next Phase Readiness

- `DesempenhoScoreService` e `PortfolioController` (Plano 02) aplicam a MESMA regra de desempate de fonte — números batem entre Carteira, ranking e bônus.
- Regressão zero para só-performance confirmada algebricamente (`margemPontos` com `nShopeePlaceholder=0`) e por teste dedicado.
- ⚠️ **Pendência explícita do CONTEXT.md**: a VERIFICATION (Plano 04) deve sinalizar o impacto de margem=1 na média/ranking com números reais — margem=1 puxa a média para baixo em quem é só-Shopee ou misto (ex.: cenário "misto" testado nesta fase, a nota caiu de 3.00 [pré-Fase-109, se Shopee não contasse] para 2.50 [pós-Fase-109, com Shopee contando via placeholder]) — requer confirmação humana explícita antes de considerar fechado.
- Plano 04 (checkpoint) é o próximo passo — validação visual da carteira Shopee + confirmação numérica do impacto de margem=1 no ranking só-Shopee/misto.

---
*Phase: 109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o*
*Completed: 2026-07-23*

## Self-Check: PASSED

Todos os 8 arquivos declarados (criados/modificados) existem no disco; os 2 commits de task (`1c36a81c`, `95ea74f2`) existem no histórico git.
