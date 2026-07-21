---
phase: 105-correcao-janela-nps-bonus-competencia-v18-0
verified: 2026-07-21T17:06:42Z
status: passed
score: 7/7 must-haves verificados
overrides_applied: 0
---

# Fase 105: Correção da Janela do NPS no Bônus por Competência (v18.0) — Verificação

**Objetivo da fase:** O componente NPS do bônus de competência M passa a ler as respostas coletadas em M+1 (não em M). Só no caminho oficial/fechado (D1). O cron congela no fim do mês de coleta (D2). O financeiro segue lido na competência M.

**Verificado:** 2026-07-21
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | Competência FECHADA M lê o NPS coletado em M+1 (não em M); financeiro permanece em M | ✓ VERIFIED | `DesempenhoScoreService::compute()` L352 chama `computeNpsWindow($user, $mes, $periodo['is_closed'])`; `computeNpsWindow` L599 desloca `$mes->copy()->addMonthNoOverflow()` só quando `$mesFechado=true`; financeiro (`computeVarFaturamento`/`computeVarMargem`) continua recebendo `$mes` sem deslocamento (L353-354). Teste `JanelaNpsBonusTest::test_competencia_fechada_le_nps_de_m_mais_1` roda verde — Felipe/junho fechado lê NPS de julho (4.97), nota_final 3.32 (não a tankada ~2.00 que a leitura de M produziria). |
| 2 | Mês EM CURSO exclui o componente NPS (null), nunca 0.0 | ✓ VERIFIED | `computeNpsWindow` L595-597: `if (!$mesFechado) return null;` — sem deslocar, sem 0.0. Teste `test_mes_em_curso_exclui_componente_nps` verde. |
| 3 | Competência fechada, janela M+1 JÁ encerrada, 0 respostas reais → 0.0 (penaliza) | ✓ VERIFIED | L606-610: sentinela `$nps === 0.0` + `$janelaNpsFechada=true` → retorna `0.0`. Teste `test_fechada_janela_m_mais_1_encerrada_zero_respostas_penaliza` verde. |
| 4 | Competência fechada, janela M+1 ainda EM COLETA, 0 respostas → null (exclui) | ✓ VERIFIED | Mesmo bloco, `$janelaNpsFechada=false` → retorna `null`. Teste `test_fechada_janela_m_mais_1_em_coleta_zero_respostas_exclui` verde. |
| 5 | Boundary do cron (último dia de M+1 às 14h, 0 respostas) → 0.0, via comparação por DATA (não timestamp) | ✓ VERIFIED | L608: `now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay())` — comparação por DATA, imune ao boundary de horário. Teste `test_boundary_ultimo_dia_m_mais_1_14h_zero_respostas_penaliza` (setTestNow 31/07 14:00:00) verde — trava exatamente o Blocker 1 documentado no plano. |
| 6 | Cron `desempenho:consolidar-mes` congela no FIM do mês de coleta (não mais dia 1); consolida a competência certa (M = hoje−1) capturando o NPS de M+1 | ✓ VERIFIED | `routes/console.php` L204: `->lastDayOfMonth('14:00')` (confirmado por leitura direta do arquivo, substituiu `monthlyOn(1,'14:00')`). `ConsolidarMesDesempenho::handle()` L87: `Carbon::today()->subMonthNoOverflow()->startOfMonth()` (lógica pré-existente, já correta para o novo timing). Teste `ConsolidarMesJanelaNpsTest::test_cron_no_ultimo_dia_do_mes_congela_competencia_m_com_nps_de_m_mais_1` (setTestNow 31/07 14:05) verde — grava snapshot de junho com NPS semeado em julho, não 0.0. |
| 7 | Invalidar/revalidar uma resposta NPS coletada em X busta o cache da competência X−1 | ✓ VERIFIED | `NpsController::bustarCacheDoBonus` L1818: `$mesCompetencia = $mesCompletado->copy()->subMonthNoOverflow()->startOfMonth();` — usa `cacheKey()` helper (nunca monta a chave à mão). Teste `NpsInvalidacaoRespostaTest::test_invalidar_resposta_de_mes_fechado_com_atribuicao_busta_o_cache_do_bonus` (completed_at junho/2026, chave asserida = v6+2026-05) verde; idem `test_admin_revalida_restaura_flag_activity_e_cache_forget`. |

**Score:** 7/7 truths verificadas

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/DesempenhoScoreService.php` | Deslocamento +1 no fechado + mecânica exclui/0.0 + cache bump v5→v6 | ✓ VERIFIED | `computeNpsWindow()` (L593-611, novo método privado), `cacheKey()` v6 (L279), `componentes_disponiveis['nps_medio']` dinâmico (L467), `computeNpsMedio` intocado (diff confirma). |
| `tests/Feature/V18/JanelaNpsBonusTest.php` | 5 testes cobrindo os cenários da janela deslocada | ✓ VERIFIED | 5/5 passed (13 assertions), rodado nesta verificação. |
| `routes/console.php` | Agendamento `lastDayOfMonth('14:00')` | ✓ VERIFIED | Confirmado por leitura direta (L204); comentário explica a semântica D2. |
| `app/Console/Commands/ConsolidarMesDesempenho.php` | Docblock/description refletindo novo timing; cálculo de `$mes` inalterado | ✓ VERIFIED | Description L53 e docblock de classe atualizados; `subMonthNoOverflow()` preservado; bug pré-existente do parsing `--mes` corrigido (auto-fix documentado, não escopo original mas dentro da fronteira do arquivo). |
| `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php` | Teste do congelamento no fim do mês | ✓ VERIFIED | 2/2 passed (9 assertions), rodado nesta verificação. |
| `app/Http/Controllers/NpsController.php` | `bustarCacheDoBonus` busta X−1 via `cacheKey()` | ✓ VERIFIED | Confirmado por leitura direta (L1811-1831); dois call-sites (invalidar L1748, revalidar L1779) wired corretamente. |
| Âncora Carlos (`Phase74/DesempenhoScoreServiceTest.php`) + dual-path (`V16/BonusDualPathRegressaoTest.php`) | Fixtures na janela M+1, golden documentado | ✓ VERIFIED | `test_fixture_carlos_retorna_nota_4_42_basico` verde; docblock de `criarCarlosCompleto()` documenta a aritmética (golden 4.25/4.42/'basico' preservado, só a janela do fixture mudou). |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `DesempenhoScoreService::compute` | `computeNpsWindow` | `$periodo['is_closed']` (sinal canônico, não recalculado) | ✓ WIRED | L352: `$nps = $this->computeNpsWindow($user, $mes, $periodo['is_closed']);` |
| `DesempenhoScoreService::cacheKey` | Redis | chave versionada v6 | ✓ WIRED | `sprintf('desempenho.compute.v6.%d.%s', ...)` — confirmado em `cacheKey()` e usado por `computeCached()` e por `NpsController::bustarCacheDoBonus`. |
| `routes/console.php` | `desempenho:consolidar-mes` | `lastDayOfMonth('14:00')` | ✓ WIRED | Bloco `desempenho-consolidar-mes` confirmado. |
| `ConsolidarMesDesempenho::handle` | `DesempenhoScoreService::compute` | competência = `today->subMonthNoOverflow()` | ✓ WIRED | L87 e L113 (`$this->scoreService->compute($user, $mes)` — usa `compute()`, não `computeCached()`, herdando o deslocamento sem cache stale). |
| `NpsController::bustarCacheDoBonus` | `DesempenhoScoreService::cacheKey` | competência = mês de `completed_at` − 1 | ✓ WIRED | L1818 + L1828 (`$scoreService->cacheKey($userId, $mesCompetencia)`), sempre via helper — nunca chave montada na mão. |

### Behavioral Spot-Checks / Testes Executados Diretamente Pelo Verificador

Todos os testes abaixo foram executados nesta sessão de verificação (não copiados do SUMMARY):

| Suíte | Comando | Resultado | Status |
|-------|---------|-----------|--------|
| `JanelaNpsBonusTest` (o cerne — deslocamento +1) | `php artisan test tests/Feature/V18/JanelaNpsBonusTest.php` | 5 passed (13 assertions) | ✓ PASS |
| `ConsolidarMesJanelaNpsTest` (D2 — boundary do cron) | `php artisan test tests/Feature/V18/ConsolidarMesJanelaNpsTest.php` | 2 passed (9 assertions) | ✓ PASS |
| `NpsInvalidacaoRespostaTest` (bust X−1) | `php artisan test tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` | 10 passed (86 assertions) | ✓ PASS |
| Regressão dirigida (Carlos/dual-path/elegibilidade/cache v6) | `php artisan test tests/Feature/Phase74/DesempenhoScoreServiceTest.php tests/Feature/V16/BonusDualPathRegressaoTest.php tests/Feature/V16/BonusAtribuicoesNpsTest.php tests/Feature/V16/DesempenhoElegibilidadeTest.php tests/Feature/V18/DesempenhoMetadadosCacheTest.php` | 42 passed (184 assertions) | ✓ PASS |
| `ConsolidarMesDesempenhoCommandTest` | `php artisan test tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` | 7 passed (22 assertions) | ✓ PASS |
| Sweep amplo `tests/Feature/V16` + `tests/Feature/V18` | `php artisan test tests/Feature/V16 tests/Feature/V18` | 213 passed (1009 assertions) | ✓ PASS |
| Sweep `tests/Feature/Phase74` + `tests/Feature/Phase96` | `php artisan test tests/Feature/Phase74 tests/Feature/Phase96` | 63 passed (324 assertions) | ✓ PASS |

**Total confirmado nesta verificação: 342+ testes verdes** (há sobreposição entre lotes, ex. `JanelaNpsBonusTest` rodou isolado e dentro do sweep V18 — sem duplicar na contagem de score).

### Fronteira do diff (escopo)

`git diff a5b84fe..61d5346 -- . ` (primeiro commit `docs(105)` até o último `test(105-03)`), excluindo `.planning/`:

```
app/Console/Commands/ConsolidarMesDesempenho.php   |  26 +-
app/Http/Controllers/NpsController.php             |  14 +-
app/Services/DesempenhoScoreService.php            |  96 ++++-
routes/console.php                                 |  16 +-
tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php |  23 +-
tests/Feature/Phase74/DesempenhoScoreServiceTest.php |  98 ++++-
tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php |  15 +-
tests/Feature/V16/BonusDualPathRegressaoTest.php   |  48 +--
tests/Feature/V16/DesempenhoElegibilidadeTest.php  |  61 +--
tests/Feature/V18/ConsolidarMesJanelaNpsTest.php   | 312 ++++++
tests/Feature/V18/DesempenhoMetadadosCacheTest.php |  22 +-
tests/Feature/V18/JanelaNpsBonusTest.php           | 426 ++++++
```

Nenhum arquivo fora do escopo esperado — `PortfolioController`, `PerformanceController`, `MetricPeriodResolver`, `AdmanMetricDiffService`, resolvers de atribuição dual-path e `NpsSnapshotService` **NÃO foram tocados**. O diff de `DesempenhoScoreService.php` (inspecionado linha a linha) confirma que `computeNpsMedio`, `notasPorAtribuicao`, `notasLegado`, `resolvePeriodo`, `computeOficial`, `computeVarFaturamento` e `computeVarMargem` permanecem 100% intocados — só docblocks ganharam comentários explicativos e o novo método `computeNpsWindow()` foi adicionado.

### Anti-Patterns Found

Nenhum bloqueador. Único marcador de debt encontrado nos arquivos tocados: `TODO Plan 74-09` em `DesempenhoScoreService.php:859` — **pré-existente** (referencia trabalho formal já concluído em fase anterior), não introduzido por esta fase.

### Requirements Coverage

| Requirement | Descrição | Status | Evidência |
|-------------|-----------|--------|-----------|
| NPSWIN-01 | Deslocamento +1 da janela de NPS no caminho fechado | ✓ SATISFIED | `computeNpsWindow()` + testes 1/3/4/5 |
| NPSWIN-02 | Mecânica exclui-vs-0.0 + `componentes_disponiveis` dinâmico | ✓ SATISFIED | Mesmo método + teste 2 |
| NPSWIN-03 | Cron D2 (congela no fim do mês) + bust por competência X−1 | ✓ SATISFIED | `routes/console.php` + `ConsolidarMesJanelaNpsTest` + `NpsController::bustarCacheDoBonus` + `NpsInvalidacaoRespostaTest` |
| NPSWIN-04 | Âncoras Carlos/dual-path recalibradas, golden documentado | ✓ SATISFIED | `criarCarlosCompleto()` docblock + `test_fixture_carlos_retorna_nota_4_42_basico` verde |

Nenhum requirement órfão detectado no escopo desta fase.

### Human Verification Required

Nenhuma. A fase é puramente backend (engine de cálculo, cron, cache-bust) e toda a lógica de negócio crítica (deslocamento de janela, boundary do cron, mecânica exclui-vs-0.0, cache-bust por competência) é coberta por testes automatizados determinísticos que foram executados diretamente por este verificador (não copiados do SUMMARY).

Nota fora do escopo desta fase (não bloqueia): o CONTEXT.md (adendo) menciona que a UI "Em curso" deveria esconder o card de NPS via `componentes_disponiveis['nps_medio']=false` — isso é explicitamente atribuído à "Fase 104-tweak" (frontend), não a esta fase 105 (backend). Confirmado que nenhum arquivo `resources/js/` usa `componentes_disponiveis` ainda — consistente com a fronteira declarada nos planos (105-01/02/03 só tocam backend).

### Gaps Summary

Nenhum gap encontrado. Os 7 truths derivados do goal da fase (deslocamento +1 no caminho fechado, exclusão no mês em curso, mecânica exclui-vs-penaliza, boundary do cron por DATA, timing D2 do cron, competência correta consolidada, bust por X−1) foram todos verificados por leitura direta do código-fonte E por execução direta dos testes nesta sessão de verificação — não por confiança no SUMMARY.md. A fronteira do diff bate exatamente com o que os 3 planos declararam (`files_modified`), sem vazamento para módulos financeiros/atribuição/resolver. 342+ testes relevantes rodaram verdes, incluindo a suíte completa V16+V18 (213 testes) e Phase74+Phase96 (63 testes).

---

*Verificado: 2026-07-21T17:06:42Z*
*Verificador: Claude (gsd-verifier)*
