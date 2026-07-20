---
phase: 103-carteira-por-periodo-v18-0
plan: 01
subsystem: api
tags: [carteira, portfolio, adman, metric-period-resolver, tdd, http-isolation]

# Dependency graph
requires:
  - phase: 100-metricperiodresolver-fundacao-de-periodo-v18-0
    provides: "MetricPeriodResolver::resolve() — resolvedor único de janelas current/baseline"
  - phase: 101-admanmetricdiffservice-v18-0
    provides: "AdmanMetricDiffService::compute() — diff de período pronto da Adman com gate + fallback guardado"
  - phase: 102-desempenho-oficial-por-competencia-v18-0
    provides: "Padrão de referência de consumo (resolvePeriodo + computeVarMargem) espelhado aqui"
provides:
  - "renderCarteiraProfissional() consumindo MetricPeriodResolver + AdmanMetricDiffService"
  - "payload.periodo com current_start/current_end/baseline_start/baseline_end/mode/comparison_mode/is_current_month/is_closed"
affects: [103-02, 104-ui-periodo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Http::preventStrayRequests() + Http::fake() determinístico em todo teste V16/V18 que alcança portfolio.show com empresa elegível"
    - "Diff service chamado incondicionalmente por empresa ELEGÍVEL (não gated por temMargemAtual/temMargemAnterior local) — tem sua própria leitura ao vivo + fallback independentes"

key-files:
  created:
    - tests/Feature/V18/CarteiraPeriodoDiffTest.php
  modified:
    - app/Http/Controllers/PortfolioController.php
    - tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php
    - tests/Feature/V16/CarteiraIndividualContextoTest.php
    - tests/Feature/V16/CarteirasConsolidadasContextoTest.php

key-decisions:
  - "margem_variacao_pct lê contribution_margin_value.diff_pct (profitMargin.diff) — NUNCA contribution_margin_pct (percentageMargin.diff, usado pela Fase 102 no score). Pitfall 1 do 103-RESEARCH.md."
  - "Diff service chamado para TODA empresa elegível, sem gate local por temMargemAtual/temMargemAnterior — o diff service tem seus próprios guards e sua própria leitura ao vivo, independentes de $atualPorEmpresa/$anteriorPorEmpresa"
  - "O bloco de dias-comuns + fallback manual (guards duplicados) foi REMOVIDO do controller — a variação de margem migrou 100% para AdmanMetricDiffService, seguindo o mesmo transplante da Fase 102"
  - "Display absoluto (margem_contribuicao/margem_contribuicao_anterior) permanece lendo os SUMs de janela sem o recorte de dias-comuns que existia antes — só a VARIAÇÃO trocou de fonte, conforme instrução literal do plano"

requirements-completed: [CAR-01, CAR-02, CAR-03]

duration: ~2h30
completed: 2026-07-20
---

# Phase 103 Plan 01: Carteira por período — carteira individual (v18.0) Summary

**`renderCarteiraProfissional()` migrado para `MetricPeriodResolver` (período) + `AdmanMetricDiffService` (variação de margem, campo `contribution_margin_value` — NUNCA `contribution_margin_pct`); mês fechado agora usa baseline janela-de-mesmo-tamanho; mês em curso permanece byte-idêntico.**

## Performance

- **Duration:** ~2h30
- **Tasks:** 3 (TDD RED→GREEN confirmado + isolamento HTTP de regressão)
- **Files modified:** 4 (1 produção + 3 teste)
- **Files created:** 1 (suíte V18 nova)

## Accomplishments

- `renderCarteiraProfissional()` resolve período via `MetricPeriodResolver::resolve()` — `?mes=YYYY-MM` traduzido 1:1 para `period_key` `closed_period`; ausente/mês corrente usa `current_month`. Nenhum `now()`/`subMonth()`/`endOfMonth()` inline restante para montar janela (grep de sanidade confirmado) — CAR-01.
- Variação de margem por empresa delegada a `AdmanMetricDiffService::compute()`, lendo **sempre** `contribution_margin_value.diff_pct` (mapeado de `profitMargin.diff`) — nunca `contribution_margin_pct` (`percentageMargin.diff`, métrica diferente usada pela Fase 102 no score). Testado explicitamente com os dois campos DISTINTOS (15.0 vs 99.0) provando a escolha correta — CAR-02, Pitfall 1.
- Bloco de ~60 linhas de dias-comuns + fallback manual (guards duplicados, fix Luiz/Tomelin/LOJASINVAL/AVF2K) removido do controller — os MESMOS guards já vivem dentro do `AdmanMetricDiffService` desde a Fase 101; agora só há uma cópia.
- Mês em curso permanece byte-idêntico: `comparison_mode=same_interval_previous_month` nunca satisfaz o gate `adman_diff`, então sempre cai em `calculated_fallback` — mesma matemática de antes.
- Mês fechado agora usa baseline janela-de-mesmo-tamanho (N dias imediatamente anteriores ao início do mês selecionado), não mais o mês calendário anterior completo — mudança de comportamento intencional (Pitfall 2), travada em teste golden (maio/2026 fechado → baseline 31/03..30/04).
- `payload.periodo` ganha `current_start`/`current_end`/`baseline_start`/`baseline_end`/`mode`/`comparison_mode`/`is_current_month`/`is_closed` do resolver, preservando 100% dos campos de display existentes (`em_curso`, `mes_selecionado`, `meses_disponiveis`, `range_atual`, `range_anterior` etc.) — CAR-03.
- Elegibilidade financeira v17 preservada: empresa Shopee-only (sem contrato Performance) continua com financeiro `null` e o diff service **nunca** é chamado para ela (early-return antes de alcançar o novo código) — provado com `Http::preventStrayRequests()` sem nenhum fake registrado (qualquer chamada HTTP faria o teste falhar).
- Isolamento HTTP adicionado aos 3 arquivos V16 de regressão que alcançam `renderCarteiraProfissional` (direta ou indiretamente via `renderCarteirasConsolidadas`), forçando `calculated_fallback` determinístico sem tocar rede.

## Task Commits

1. **Task 1: Suíte V18 RED (payload.periodo + campo correto + baseline + byte-idêntico + Shopee sem fonte)** — `d20d26d` (test)
2. **Task 2: Implementação — resolver + diff service em renderCarteiraProfissional** — `dc99a7c` (feat)
3. **Task 3: Isolamento HTTP nos 3 testes V16 de regressão** — bundled em `fcdbc68` (ver "Nota sobre commits" abaixo)

_TDD confirmado: Task 1 rodou RED de verdade — o teste D inicialmente coincidiu numericamente com o comportamento antigo (dados constantes por dia tornam a razão invariante ao tamanho da janela); a fixture foi redesenhada com um dia outlier (31/03) para forçar divergência real entre o baseline antigo (mês calendário) e o novo (janela-de-mesmo-tamanho), confirmando RED genuíno (20.0 antigo vs 9.41 esperado) antes da Task 2._

## Nota sobre commits (sessão paralela ativa)

Durante a Task 3, uma corrida de commits com a sessão paralela ativa (NPS/dashboard, ver `<constraints>` do plano) fez com que meu `git add` dos 3 arquivos V16 ficasse no índice compartilhado no momento em que a sessão paralela rodou seu próprio `git commit -m "..."` (commit `fcdbc68`, "fix(97-02): DashboardPendencyPropsTest..."), absorvendo minhas 3 edições de teste junto com a deles. Um commit anterior meu (Task 3, primeira tentativa) também absorveu acidentalmente 2 arquivos da sessão paralela (`DashboardController.php` + `DashboardWidgetsRecorteTest.php`) — corrigido imediatamente via `git reset --soft` + unstage seletivo (sem `git reset --hard`, sem `git clean`, sem tocar branch protegida), restaurando os arquivos da sessão paralela ao estado exato de antes (modificado/untracked, não commitado). Nenhum trabalho foi perdido de nenhum dos dois lados — confirmado via `git show --stat` em cada commit e `git diff` comparando conteúdo esperado vs commitado. O código de produção (`PortfolioController.php`, commit `dc99a7c`) e a suíte V18 nova (commit `d20d26d`) permaneceram intocados e isolados durante todo o processo.

**Verificação pós-correção:** `php artisan test --filter=Carteira` → 74 passed (mesma baseline de antes), suíte V18 6/6, os 3 arquivos V16 confirmados com `Http::preventStrayRequests()` presente via grep no HEAD final.

## Files Created/Modified

- `app/Http/Controllers/PortfolioController.php` — constructor injeta `MetricPeriodResolver`/`AdmanMetricDiffService`; bloco de datas inline substituído por `periodResolver->resolve()`; bloco de dias-comuns/fallback manual removido; `admanDiffService->compute()` chamado por empresa elegível; `payload.periodo` expõe as 4 datas + metadados do resolver.
- `tests/Feature/V18/CarteiraPeriodoDiffTest.php` — suíte nova (6 testes): payload.periodo mês em curso, payload.periodo mês fechado (golden baseline janela-de-mesmo-tamanho), campo correto (`contribution_margin_value` vs `_pct`), fallback calculado, byte-idêntico mês em curso, Shopee sem fonte não aciona diff service.
- `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` / `CarteiraIndividualContextoTest.php` / `CarteirasConsolidadasContextoTest.php` — `Http::preventStrayRequests()` + `Http::fake()` 404 nos 2 endpoints Adman no `setUp()`, puro isolamento de rede (nenhum assert de valor alterado).

## Decisions Made

- **Campo de margem travado:** `contribution_margin_value.diff_pct` (profitMargin.diff), nunca `contribution_margin_pct` (percentageMargin.diff). Testado com valores distintos (15.0 vs 99.0) provando a leitura correta.
- **Diff service chamado incondicionalmente para empresa elegível** — não gated por `temMargemAtual`/`temMargemAnterior` (que vêm de `$atualPorEmpresa`/`$anteriorPorEmpresa`, queries LOCAIS diferentes). O diff service tem sua própria leitura ao vivo + fallback independentes; gatear pelo estado local faria o Teste C (que não seeda nenhum `AdmanMetric` local, só depende do fake HTTP) falhar incorretamente.
- **Display absoluto preservado sem o recorte de dias-comuns**: `margem_contribuicao`/`margem_contribuicao_anterior` continuam lendo os SUMs de janela crus (sem a reagregação por dias-comuns que existia antes) — conforme instrução literal do plano ("só a VARIAÇÃO troca de fonte"). Documentado no código com comentário explicando a decisão.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug no próprio teste, não no código de produção] Teste D coincidia numericamente com o comportamento antigo**
- **Found during:** Task 1 (confirmação de RED)
- **Issue:** A fixture original do Teste D usava margem constante por dia (300/250) em ambas as janelas. Como a razão `SUM(margem_atual)/SUM(margem_anterior)` é invariante ao tamanho da janela quando os valores diários são constantes, o código ANTIGO (baseline = mês calendário anterior, truncado a 30 dias) e o NOVO (baseline = janela-de-mesmo-tamanho, 31 dias) produziam o MESMO resultado (+20,00%) por coincidência matemática — RED falso-positivo.
- **Fix:** Redesenhada a fixture com um dia outlier (31/03, margem=1000) que só a baseline NOVA inclui — old code nunca lê esse dia (só consulta abril inteiro), forçando divergência real (20.0 antigo vs 9.41 novo).
- **Files modified:** `tests/Feature/V18/CarteiraPeriodoDiffTest.php`
- **Verification:** RED confirmado antes da Task 2 (`Failed asserting that 20.0 matches expected 9.41`), GREEN confirmado depois.
- **Committed in:** `d20d26d`

**2. [Rule 1 - Ajuste de expectativa de teste] Total_faturamento do Teste E ajustado de 20000 para 19000 (artefato pré-existente do SQLite de teste)**
- **Found during:** Task 1 (confirmação de RED/GREEN do Teste E)
- **Issue:** `$atualPorEmpresa` (bloco NÃO tocado por esta fase) usa `whereBetween('reference_date', [...])` — coluna com cast `'date'` mas persistida como `'Y-m-d 00:00:00'`. No SQLite de teste, isso vira comparação de STRING, e `'2026-07-20 00:00:00' > '2026-07-20'` lexicograficamente — o último dia da janela cai fora do `whereBetween`, somando só 19 dos 20 dias seedados.
- **Fix:** Ajustado o valor esperado do teste para 19000 (comportamento REAL, idêntico antes/depois da migração — "byte-idêntico" significa mesmo número, não o número teoricamente correto de uma query diferente). Não é um bug desta fase — a query em questão não foi tocada pelo Task 2, e o mesmo artefato afeta produção MySQL/MariaDB de forma diferente (tipagem de coluna real evita o problema).
- **Files modified:** `tests/Feature/V18/CarteiraPeriodoDiffTest.php`
- **Verification:** Confirmado com script de debug isolado (`whereBetween` retornou 19 de 20 rows seedadas com datas idênticas às esperadas).
- **Committed in:** `d20d26d`

**Nenhuma mudança em código de produção fora do declarado no plano** — os 2 ajustes acima são exclusivamente refinamentos da fixture/expectativa de teste, feitos durante a confirmação de RED (antes da Task 2 sequer existir).

---

**Total deviations:** 2 (ambas em fixtures de teste, Rule 1 — nenhuma mudança de escopo de produção).
**Impact on plan:** Zero scope creep de produção. `renderPortfolio()` e `renderCarteirasConsolidadas()` permanecem intocados (fora do escopo desta plan — 103-02), `CarteiraContextService`/`MetricPeriodResolver`/`AdmanMetricDiffService`/`DesempenhoScoreService` intocados.

## Pontos obrigatórios do `<output>` do plano

**(a) Baseline do modo fechado MUDOU (consequência conhecida, igual à Fase 102):** qualquer `?mes=YYYY-MM` no passado agora compara contra a janela-de-mesmo-tamanho (N dias imediatamente anteriores ao início do mês selecionado), não mais o mês calendário anterior completo. Números de `margem_variacao_pct` exibidos para meses fechados vão mudar de valor em produção (não de estrutura). O modo mês em curso NÃO muda (já batia 100% com `current_month` antes da migração).

**(b) Validação numérica em produção PENDENTE:** não há checkpoint visual nesta fase (é a Fase 104 — UI de período). Os casos historicamente auditados (Tomelin/Gabriela/LOJASINVAL, ver `.planning/debug/resolved/`) foram testados apenas via fixture sintética nesta suíte — validação com dados reais de produção (comparar `margem_variacao_pct` da carteira individual pré/pós-deploy para essas empresas específicas em mês fechado) fica pendente para depois do deploy, quando solicitado pelo usuário.

**(c) `renderPortfolio()` permanece com período inline (known-gap fora de CAR-01):** a função usada pela auto-visualização (`/portfolio`, rota `own()` para não-admin) tem o MESMO padrão inline (`now()`/`subMonth()`/`endOfMonth()`) que existia em `renderCarteiraProfissional()` antes desta migração. Não foi tocada — está fora do escopo de `REQUIREMENTS-v18.md` CAR-01 (que cita só `renderCarteiraProfissional`/`renderCarteirasConsolidadas`), conforme Open Question 1 do `103-RESEARCH.md`, já resolvida como "fora de escopo" pelo orquestrador.

**(d) N+1 HTTP aceito como dívida técnica:** a carteira agora chama `AdmanMetricDiffService::compute()` por empresa elegível, e `PortfolioController` não tem cache próprio (diferente de `DesempenhoScoreService::computeCached`). Cold-cache pode disparar dezenas de chamadas HTTP síncronas para uma carteira com muitas empresas. Mitigado parcialmente pelo cache 24h interno do `AdmanMetricDiffService` (amortiza cargas subsequentes no mesmo dia) — só a PRIMEIRA carga do dia é lenta. Follow-up de batching fica para depois, se medição em produção acusar lentidão perceptível.

## Issues Encountered

- **Corrida de commits com sessão paralela** — ver "Nota sobre commits" acima. Resolvida sem perda de trabalho de nenhum dos dois lados, mas o histórico de commits da Task 3 ficou menos limpo do que o planejado (mudanças bundled num commit da sessão paralela em vez de um commit próprio). Não afeta o conteúdo final — apenas a granularidade do histórico.

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- **103-02** (carteira consolidada) pode prosseguir — `renderCarteirasConsolidadas()` continua com o modelo de período `?period=1/7/30/180` (dias rolantes, sem baseline), intocado por esta fase (Pitfall 4 do 103-RESEARCH.md: é uma ADIÇÃO de capacidade, não um refactor 1:1).
- **Fase 104** (UI de período) pode prosseguir — `payload.periodo` já expõe as 4 datas + metadados do resolver; nenhum seletor/toggle visual foi construído nesta fase (Pitfall 3, fronteira respeitada).
- **Regressão confirmada limpa** em: `tests/Feature/V16/Carteira*` (5 arquivos, 24 testes), `tests/Feature/V18/CarteiraPeriodoDiffTest` (6/6), `tests/Feature/CompanyPortfolioAccessTest`, `tests/Feature/Portfolio/RenderPortfolioTest`, `tests/Feature/V16/BonusDualPathRegressaoTest`, e demais arquivos com "Carteira" no nome (74/76 passed no filtro completo).
- **Falha pré-existente CONFIRMADA via baseline** (checkout do controller na versão anterior à Task 2, mesma falha idêntica): `tests/Feature/Phase61/PortfolioMultiFonteE2ETest` (2 testes, `flag on/off portfolio carteiras admin ... source counts`) — falha em `renderCarteirasConsolidadas()` (função NÃO tocada por este plano), causa raiz não investigada (fora do escopo desta fase).

## Self-Check: PASSED

- Arquivos claimados verificados via `[ -f ... ]`: todos os 5 encontrados (`PortfolioController.php`, `CarteiraPeriodoDiffTest.php`, `CarteiraFinanceiroElegibilidadeTest.php`, `CarteiraIndividualContextoTest.php`, `CarteirasConsolidadasContextoTest.php`).
- Commits claimados verificados via `git log --oneline --all | grep`: `d20d26d`, `dc99a7c`, `fcdbc68` — todos encontrados.
- Suíte V18 (`CarteiraPeriodoDiffTest`) re-executada no HEAD final: 6/6 verde.
- Suíte `--filter=Carteira` re-executada no HEAD final: 74 passed, 2 failed (ambos pré-existentes, confirmados via baseline antes da Task 2 — `Phase61\PortfolioMultiFonteE2ETest`, função `renderCarteirasConsolidadas` não tocada por este plano).

---
*Phase: 103-carteira-por-periodo-v18-0*
*Completed: 2026-07-20*
