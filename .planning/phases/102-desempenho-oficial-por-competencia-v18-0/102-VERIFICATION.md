---
phase: 102-desempenho-oficial-por-competencia-v18-0
verified: 2026-07-20T18:39:48Z
status: passed
score: 10/10 must-haves verificados
overrides_applied: 0
---

# Fase 102: Desempenho oficial por competência (v18.0) — Relatório de Verificação

**Goal da Fase:** `DesempenhoScoreService` consome `MetricPeriodResolver` + `AdmanMetricDiffService`; o ranking oficial de bônus usa a competência do mês fechado (julho paga junho); `var_margem_pct` usa o diff pronto da Adman — preservando score único e as invariantes de elegibilidade da v17.0.
**Verificado:** 2026-07-20
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Postura adotada

Verificação adversarial com rigor máximo (fase que muda o número do bônus paga). Toda a suíte de testes relevante foi **executada por mim**, não aceita por relato do SUMMARY. A aritmética da âncora Carlos foi **refeita manualmente** (não apenas lida). O boundary da fase foi checado via `git show --stat` de cada commit de produção. Uma alegação de "falha pré-existente" (`PerformanceCargoFilterTest`) foi **checada empiricamente** revertendo temporariamente `DesempenhoScoreService.php` ao estado pré-fase e re-rodando o teste.

## Goal Achievement

### Observable Truths (Success Criteria do ROADMAP, 5/5 + must_haves dos planos)

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | `DesempenhoScoreService` consome `MetricPeriodResolver` — var. faturamento/margem usam `period.current_*`/`period.baseline_*`, não `now()`/`startOfMonth` inline | ✓ VERIFIED | `computeVarFaturamento` (linhas 834-843) e `computeVarMargem` (linha 951, delega a `admanDiffService->compute($company, $periodo)`) leem exclusivamente de `$periodo`; `git show 5ce65b2` remove 187 linhas incluindo todo o bloco `$hoje`/`$ehMesEmCurso`/`$inicioMes`/`$inicioAnter` inline de `computeVarFaturamento`. |
| 2 | Ranking oficial de bônus em julho/2026 usa competência junho/2026 fechada (atual `01/06..30/06` vs `02/05..31/05`) | ✓ VERIFIED | `test_resolve_last_closed_month_produz_janelas_da_regua_nova` (rodado, verde) assevera exatamente `current_start=2026-06-01`, `current_end=2026-06-30`, `baseline_start=2026-05-02`, `baseline_end=2026-05-31`. `test_compute_oficial_rotula_competencia_junho_pagamento_julho_e_bate_com_compute_direto` prova `computeOficial(User)` == `compute($u, Carbon('2026-06-01'))` (mesma `nota_final`, dois caminhos) — rodado, verde. |
| 3 | `var_margem_pct` usa `percentageMargin.diff` via `AdmanMetricDiffService` quando disponível; fallback calculado só quando ausente, marcado | ✓ VERIFIED | `computeVarMargem` (linha 942-964) delega 100% a `AdmanMetricDiffService::compute()`, lê só `metrics['contribution_margin_pct']['diff_pct']`, nunca recalcula guard. Testes `test_var_margem_pct_usa_adman_diff_quando_janela_igual_e_diff_presente` e `..._cai_no_calculated_fallback_quando_diff_ausente` provam os dois ramos do gate — rodados, verdes. |
| 4 | Retorno adiciona `periodo` (janelas) e `bonus` (`payment_month`/`competence_month`); score único preservado | ✓ VERIFIED | `compute()` linhas 416-434 retorna os dois blocos. `test_shape_periodo_bonus_mes_fechado_traz_competencia_e_pagamento` + `..._mes_corrente_traz_nulls` — rodados, verdes. `test_user_misto_performance_e_shopee_produz_score_unico_official` prova ausência de score por marketplace. |
| 5 | Leitura operacional (mês em curso) segue disponível marcada operacional/parcial; `financial_metrics_eligible`/`score_status` intactos | ✓ VERIFIED | `computeScoreStatus` (linhas 514-525) INTOCADO (zero-diff confirmado — não aparece em nenhum diff de commit da fase). `test_user_so_shopee_permanece_blocked_com_nota_null` e a suíte `DesempenhoElegibilidadeTest` (10 testes, verde) provam blocked/partial/official preservados. |
| 6 (plan) | Âncora Carlos: golden derivado da régua nova, aritmética verificável, não escolhido pra passar | ✓ VERIFIED | Refiz a conta manualmente: margem baseline 2000/10000×100=20,00%; margem atual 2152,70/10300×100=20,90% (exato); diff=(20,90-20,00)/20,00×100=+4,50% → régua_margem=5pts; nota=(4,25+4+5)/3=4,4166...→4,42. Bate exatamente com o comentário do teste e com o resultado do `assertEqualsWithDelta`. |
| 7 (plan) | Os ~140 linhas de guards duplicados saem do `DesempenhoScoreService` | ✓ VERIFIED | `git show 5ce65b2 -- app/Services/DesempenhoScoreService.php` remove 187 linhas, incluindo blocos `margemAtual`/`margemAnterior`/`margem_dias`/`diasComMargem*PorEmpresa`. `grep -n "margem_dias\|margemAtual\|margemAnterior\|diasComMargem"` no arquivo atual só encontra referências em comentário — zero duplicação de código. |
| 8 (plan) | `computeNpsMedio` permanece zero-diff | ✓ VERIFIED | Nenhum commit da fase toca o corpo de `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` (confirmado via `git show <commit> -- ... \| grep`). `BonusDualPathRegressaoTest` (só a asserção de chave v4→v5 mudou, resto idêntico) roda verde. |
| 9 (plan) | Cache v5 com `period_key`; `NpsController` usa helper `cacheKey()` | ✓ VERIFIED | `cacheKey()` público (linha 259-266) usado por `computeCached()` (linha 231) e por `NpsController::bustarCacheDoBonus` (linha 1663-1668, `$scoreService->cacheKey($userId, $mesCompletado)` — sem hardcode). `test_cache_key_operacional_nao_colide_com_chave_de_mes_fechado_do_mesmo_mes` prova a separação. |
| 10 (plan) | Isolamento HTTP total — nenhum teste do edit-set faz requisição real à Adman | ✓ VERIFIED | Todos os arquivos que alcançam `compute()`/`computeCached()` com empresa `custId`'d usam `Http::preventStrayRequests()`+`Http::fake()`. Os 2 arquivos sem o guard (`BonusDualPathRegressaoTest`, `NpsInvalidacaoRespostaTest`) foram inspecionados linha-a-linha: nenhum chama `compute()`/`computeCached()` com empresa `adman_account_id` preenchida (fábrica `CompanyFactory` seta `adman_account_id=null` por padrão → `AdmanMetricDiffService::compute()` retorna `emptyMetrics()` sem tentar HTTP). |

**Score:** 10/10 truths verificadas

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/DesempenhoScoreService.php` | `computeOficial` + `resolvePeriodo` + janelas via resolver + margem via diff service | ✓ VERIFIED | Todos os métodos presentes e com corpo substantivo (não stub); wiring confirmado (injeção de `MetricPeriodResolver`/`AdmanMetricDiffService` no construtor, linhas 110-111). |
| `app/Http/Controllers/NpsController.php` (1 método) | `bustarCacheDoBonus` usa `cacheKey()` | ✓ VERIFIED | Linha 1663-1668, sem hardcode de versão. |
| `tests/Feature/V18/DesempenhoPeriodoOficialTest.php` | Prova competência fechada + gate adman_diff + faturamento por janela | ✓ VERIFIED | 6 testes, todos rodados e verdes. |
| `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` | Shape periodo/bonus + separação de chave operacional vs oficial | ✓ VERIFIED | 10 testes, todos rodados e verdes. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `DesempenhoScoreService` | `MetricPeriodResolver` | `resolvePeriodo()` → `resolve(['period_key'=>...])` | ✓ WIRED | Confirmado nos testes de janela exata e no código (linha 285-286). |
| `DesempenhoScoreService` | `AdmanMetricDiffService` | `computeVarMargem` → `compute(company, periodo)` | ✓ WIRED | Confirmado no gate `adman_diff`/`calculated_fallback` (2 testes dedicados, verdes). |
| `NpsController::bustarCacheDoBonus` | `DesempenhoScoreService::cacheKey()` | chamada direta via `app()` | ✓ WIRED | `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` roda verde com a chave v5 seedada manualmente e o `forget()` confirmado nos testes que checam `Cache::has()` após invalidar/revalidar. |

### Behavioral Spot-Checks (execução própria)

| Suíte | Comando | Resultado | Status |
|-------|---------|-----------|--------|
| Phase74 + V16 (ComparacaoContextualBlocked/DesempenhoElegibilidade) + V18 | `phpunit tests/Feature/Phase74 tests/Feature/V16/ComparacaoContextualBlockedTest.php tests/Feature/V16/DesempenhoElegibilidadeTest.php tests/Feature/V18` | 70/70 verde, 305 assertions | ✓ PASS |
| BonusDualPathRegressaoTest + DesempenhoElegibilidadeTest + NpsInvalidacaoRespostaTest + DesempenhoScoreSnapshotTest + DesempenhoEvolucaoTest | `phpunit ...` | 42/42 verde, 258 assertions | ✓ PASS |
| V16 + V18 + Phase96 + Portfolio (regressão ampla) | `phpunit tests/Feature/V16 tests/Feature/V18 tests/Feature/Phase96 tests/Feature/Portfolio` | 226/226 verde, 1178 assertions | ✓ PASS |
| `DesempenhoMetadadosCacheTest` (--testdox) | `phpunit --filter DesempenhoMetadadosCacheTest` | 10/10 verde | ✓ PASS |
| Falhas pré-existentes (`PerformanceCargoFilterTest`, `CompanyServiceTypeTest`) — validação empírica | Reverti `DesempenhoScoreService.php` ao HEAD pré-fase (`git checkout b7ba116 --`), rodei `PerformanceCargoFilterTest` + `CompanyServiceTypeTest` | Mesmas 6 falhas idênticas ANTES da fase — confirma que NÃO são regressão da Fase 102. Arquivo restaurado e verificado `git diff HEAD` = 0. | ✓ PASS (confirma alegação do SUMMARY) |

### Fronteira (boundary check — ponto de atenção 8)

`git show --stat` de todos os 7 commits de código da fase (5ce65b2, c270a71, 2055161, 6fdf1b6, c845436, 88b50f0, 99dfbbb) confirma:
- **Produção:** só `app/Services/DesempenhoScoreService.php` + `app/Http/Controllers/NpsController.php` (1 método, +9/-4 linhas).
- **Testes:** só arquivos do `files_modified` declarado nos planos, exceto 3 arquivos fora do edit-set (`PerformanceIndexMetadadosTest.php`, `DesempenhoScoreSnapshotTest.php`, `DesempenhoEvolucaoTest.php`) — corrigidos como regressões reais causadas DIRETAMENTE pela mudança de assinatura de `compute()`, devidamente documentados como deviation no SUMMARY 102-01 (Rule 1/Rule 3), e re-verificados por mim (rodados, verdes).
- **Nenhum toque** em `PerformanceController.php`, `CarteiraContextService.php`, `MetricPeriodResolver.php` ou `AdmanMetricDiffService.php` — confirmado por ausência nos diffs.

### Requirements Coverage

| Requirement | Plano de origem | Descrição | Status | Evidência |
|-------------|------------------|-----------|--------|-----------|
| BON-01 | 102-01 | Janelas via `MetricPeriodResolver` | ✓ SATISFIED | Guards inline removidos; testes de janela exata verdes. |
| BON-02 | 102-01/102-02 | Competência do mês fechado (julho paga junho) | ✓ SATISFIED | `computeOficial` + teste `computeOficial == compute(junho)` verde. |
| BON-03 | 102-01 | `var_margem_pct` via `AdmanMetricDiffService` com gate + fallback marcado | ✓ SATISFIED | Delegação total confirmada; 2 testes de gate verdes. |
| BON-04 | 102-02 | Shape expõe `periodo`/`bonus`; score único preservado | ✓ SATISFIED | Blocos presentes no retorno; teste de shape verde. |
| BON-05 | 102-02 | Invariantes v17 (`financial_metrics_eligible`/`score_status`) intactas | ✓ SATISFIED | `computeScoreStatus` zero-diff; testes blocked/official verdes. |

Nenhuma requirement órfã — todas as 5 (BON-01..05) mapeadas no ROADMAP aparecem em `requirements:` dos 2 planos.

### Anti-Patterns Found

Nenhum bloqueador. Único `TODO` encontrado em `DesempenhoScoreService.php` (linha 773, "TODO Plan 74-09") é **pré-existente** (introduzido na Fase 74, confirmado via `git log -S`), referencia trabalho formal de follow-up (Plan 74-09) e não foi tocado por esta fase — não conta como débito novo desta fase.

### Human Verification Required

Nenhum item — toda a verificação foi possível via código + testes executados. Validação numérica em produção (comparar bônus v17 vs v18 por profissional) é recomendação de DEPLOY-TIME documentada no SUMMARY 102-02, fora do escopo de verificação de código desta fase (é decisão de negócio, não um gap técnico).

### Gaps Summary

Nenhum gap. Todas as truths do ROADMAP e dos 2 planos foram verificadas com evidência direta de código + execução própria dos testes (não apenas leitura do SUMMARY). A recalibração da âncora Carlos (4.08→4.42) foi conferida algebricamente de forma independente e bate exatamente com o valor testado. O boundary da fase foi respeitado (só `DesempenhoScoreService.php` + 1 método do `NpsController.php` em produção). Isolamento HTTP total confirmado — nenhum teste faz requisição real à Adman.

---

_Verified: 2026-07-20T18:39:48Z_
_Verifier: Claude (gsd-verifier)_
