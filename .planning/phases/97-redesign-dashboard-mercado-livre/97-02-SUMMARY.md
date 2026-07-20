---
phase: 97-redesign-dashboard-mercado-livre
plan: 02
subsystem: dashboard
tags: [laravel, inertia, nps, desempenho-score, carteira-context, company-users]

# Dependency graph
requires:
  - phase: 97-01
    provides: "$companies (recorte já filtrado), revenueByCompany/tacosByCompany/marginByCompany por empresa, novasEmpresasCount (D3)"
provides:
  - "performance_equipe restrito ao recorte aplicado (company_id/consultor_id/estrategista_id/group_id/marketplace), com breakdown (carteira/nps/margem/faturamento/tacos)"
  - "nps_pendentes via NpsPendingService::forCompanies($companies) em vez de forCarteira (todas as empresas)"
  - "nps_ruins: respostas de nota <=3 do recorte, excluindo invalidadas (scopeValida, Fase 96)"
  - "novas_empresas: cards detalhados das empresas com contrato Performance ativo iniciado no mês (D3)"
affects: [97-03, 97-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Recorte de widgets via company_users: derivar user_id elegíveis com DB::table('company_users')->whereIn('company_id', $companies->pluck('id'))->whereIn('role', [...]) em vez de consultar todos os users do setor"
    - "Reuso de ids já calculados (novasEmpresasIds) para alimentar tanto o KPI (count) quanto os cards detalhados, evitando 2 fontes de verdade divergentes"
    - "nps_ruins reusa a MESMA query/closure $npsResponses/$notaDe já filtrada por ->principal() + ->valida() (Fase 96) — resposta invalidada tem response=null no eager-load e some do filtro sem checagem extra de invalidated_at"

key-files:
  created:
    - tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php
  modified:
    - app/Http/Controllers/DashboardController.php
    - tests/Feature/Phase72/DashboardPendencyPropsTest.php

key-decisions:
  - "Breakdown de performance_equipe usa pontos_componentes (0-5, mesma escala de nota_final) para 'nps'/'margem'/'faturamento'; 'tacos' fica SEMPRE null pois DesempenhoScoreService não calcula esse componente (mantido só por compat com o layout de 4 barras do mock — Plan 97-04 decide se oculta ou renomeia)"
  - "Régua de 'nota ruim' em nps_ruins é <=3 (escala 1-5 do sistema), a MESMA do bucket 'negativas' já existente — não o '<=5' do mockup (herança da escala 0-10 antiga)"
  - "Status do card novas_empresas (ramp-up/atencao/saudavel) derivado de faturamento_parcial+margem (decisão do executor, documentada em comentário pt-BR no controller — o mockup não define o cálculo)"
  - "novas_empresas reusa os MESMOS ids de novasEmpresasCount (Plan 97-01) filtrando a coleção $companies já carregada, em vez de rodar uma 2ª query Company — evita KPI e lista divergirem"

requirements-completed: [DASH-97-1, DASH-97-5, DASH-97-6, DASH-97-7]

# Metrics
duration: ~50min
completed: 2026-07-20
---

# Phase 97 Plan 02: Widgets do Dashboard respeitando o recorte + NPS ruim + novas empresas detalhadas Summary

**`DashboardController::adminDashboard` corrigiu os 2 widgets que ignoravam o recorte de filtros ("Score da equipe" e NPS pendentes), adicionou `nps_ruins` (nota <=3, excluindo invalidadas da Fase 96) e `novas_empresas` (cards detalhados por início de contrato D3) — fechando o backend do redesign da Dashboard ML.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-07-20T19:10:00Z (aprox., logo após 97-01)
- **Completed:** 2026-07-20T20:00:00Z (aprox.)
- **Tasks:** 2/2 completos
- **Files modified:** 3 (1 controller, 1 teste novo, 1 teste pré-existente ajustado)

## Accomplishments
- `performance_equipe` ("Score da equipe") agora só lista os analistas/estrategistas vinculados (via `company_users`) às empresas do recorte aplicado — antes ignorava `company_id`/`consultor_id`/`estrategista_id`/`group_id`/`marketplace` por completo e sempre retornava todo o setor Performance (CONTEXT Riscos §2). Sem filtro, continua cobrindo todo o universo (não regride a visão ampla).
- Cada item de `performance_equipe` ganhou `breakdown` (`carteira`, `nps`, `margem`, `faturamento`, `tacos`) para o card D1 do redesign — nota oficial 0-5 do `DesempenhoScoreService::computeCached`, com as chaves e a decisão sobre `tacos` (sempre `null`, componente inexistente no service) documentadas em comentário pt-BR no controller.
- `nps_pendentes` trocou `NpsPendingService::forCarteira()` (que para admin retornava TODAS as empresas do sistema, ignorando o filtro) por `forCompanies($companies)` — mesmo recorte já usado pelos demais widgets.
- `nps_ruins` (novo prop): respostas de nota baixa (`<=3`, escala 1-5 do sistema — não o `<=5` herdado da escala 0-10 do mockup) do recorte, reusando a MESMA query `$npsResponses` (já `->principal()` + response eager-loaded com `->valida()`, Fase 96) — resposta invalidada some automaticamente (response vem `null`). Cada item traz `company_id`, `company_name`, `data`, `nota`, `comment`, `analista`, `estrategista` e `survey_id` (link "Abrir NPS completo").
- `novas_empresas` (novo prop): cards das empresas com contrato Performance ativo iniciado no mês corrente (D3), reusando os MESMOS ids de `novasEmpresasCount` (Plan 97-01) para o KPI e a lista nunca divergirem. Campos: `id`, `name`, `grupo`, `status` (`ramp-up`/`atencao`/`saudavel`, decisão do executor documentada), `faturamento_parcial`, `tacos`, `analista`, `estrategista`.
- Suíte `tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php` (7 testes): recorte de `performance_equipe` (com e sem filtro), recorte de `nps_pendentes`, `nps_ruins` excluindo invalidada + incluindo nota baixa válida, recorte de `nps_ruins`, `novas_empresas` com dados completos, `novas_empresas` sem faturamento (`ramp-up`).

## Task Commits

Ambas as tasks foram implementadas e commitadas em um único commit atômico (mesmo método `adminDashboard`, mesmo arquivo de teste — mesmo padrão do Plan 97-01):

1. **Task 1 + Task 2: Score da equipe/NPS pendentes/NPS ruim/novas empresas respeitando o recorte** - `452287d` (feat)
2. **Deviation [Rule 1] — fix collateral em teste pré-existente** - `fcdbc68` (fix) — commitado ANTES do commit principal por causa de uma race de índice git com um processo concorrente (ver "Issues Encountered" abaixo); o conteúdo do commit principal (`452287d`) inclui novamente `DashboardPendencyPropsTest.php` porque a correção precisou ser reincluída após o índice ser reconciliado.

**Plan metadata:** (a ser commitado nesta etapa — docs: complete plan)

## Files Created/Modified
- `app/Http/Controllers/DashboardController.php` — `$perfMembrosQuery` restrito a `user_id` derivados de `company_users` do recorte; `map()` de `performance_equipe` ganhou `breakdown`; `forCarteira`→`forCompanies($companies)`; `nps_ruins` (novo, reusa `$npsResponses`/`$notaDe`); `$novasEmpresasIds`/`$novasEmpresasCount` (Plan 97-01) reusados para montar `novas_empresas` detalhado; ambos os novos props adicionados ao `Inertia::render`.
- `tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php` (novo) — 7 testes cobrindo os 4 comportamentos do plano.
- `tests/Feature/Phase72/DashboardPendencyPropsTest.php` — ajustado (Rule 1) para dar contrato Performance ativo às 2 empresas de fixture, mantendo a asserção original (`nps_pendentes` com 2 itens) sob o novo recorte `forCompanies`.

## Decisions Made
- `pontos_componentes` (0-5, já na mesma escala de `nota_final`) foi preferido a `componentes` (percentuais brutos) para o breakdown do card — visualmente consistente com a barra 0-5 do D1.
- `tacos` no breakdown fica sempre `null` com comentário explicando por quê (o `DesempenhoScoreService` não calcula esse componente — os 3 reais são NPS/faturamento/margem). Decisão de manter a chave por compat com o layout de 4 barras do mock, deixando pro Plan 97-04 decidir se oculta a barra ou renomeia para "Faturamento".
- `novas_empresas` reusa a coleção `$companies` já carregada (com `consultor`/`estrategista` eager-loaded) filtrando pelos mesmos ids do KPI, em vez de rodar uma 2ª query `Company` — evita 2 fontes de verdade e reduz custo.
- Régua de "nota ruim" em `nps_ruins`: `<=3` (mesma do bucket `negativas` já existente), não o `<=5` do mockup (herança da escala 0-10 antiga, que marcaria quase tudo como ruim na escala 1-5 atual).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `DashboardPendencyPropsTest` (Phase 72) quebrou com a correção do recorte de `nps_pendentes`**
- **Found during:** Task 1 (troca `forCarteira`→`forCompanies`)
- **Issue:** O teste `test_admin_dashboard_injeta_nps_pendentes_com_shape_completo` criava 2 `Company::factory()` puras (sem contrato) e esperava `nps_pendentes` com 2 itens — comportamento válido sob o bug antigo (`forCarteira` trazia TODAS as empresas do sistema para admin, ignorando qualquer critério de recorte), mas incompatível com o comportamento CORRETO que este plano introduz (`forCompanies($companies)` exige que a empresa esteja no universo Performance com contrato ativo).
- **Fix:** Adicionado 1 `Servico` (setor Performance) + 1 `ContratoServico` ativo para cada uma das 2 empresas de fixture, preservando a asserção original (2 pendentes) sob o recorte novo.
- **Files modified:** `tests/Feature/Phase72/DashboardPendencyPropsTest.php`
- **Verification:** `php artisan test --filter=DashboardPendencyPropsTest` → 4/4 verdes.
- **Committed in:** `452287d` (commit principal da task)

---

**Total deviations:** 1 auto-fixed (1 bug de regressão em teste pré-existente, causada pela correção intencional do plano)
**Impact on plan:** Nenhum scope creep — a correção era necessária para o próprio comportamento corrigido por este plano não regredir a suíte existente (verificação explícita do plano: "`php artisan test --filter=Dashboard` sem regressão nas suítes existentes").

## Issues Encountered

- **Race de índice git com processo concorrente:** durante a execução, um processo/agente concorrente (Fase 103, trabalhando em paralelo no mesmo working tree/índice git — ver nota do CLAUDE.md/PLAN "Trabalho paralelo de outro dev") estava fazendo `git add`/`git commit`/`git reset` na mesma janela de tempo. O primeiro `git add` desta sessão (`DashboardController.php` + `DashboardWidgetsRecorteTest.php`) foi capturado por um commit do OUTRO processo (`8239376 test(103-01): isolamento HTTP...`), que logo depois foi resetado por aquele processo (provavelmente ao perceber a contaminação), devolvendo meus arquivos ao working tree como não commitados — nenhum trabalho foi perdido. Ao tentar recommitar apenas `tests/Feature/Phase72/DashboardPendencyPropsTest.php` + `deferred-items.md`, o commit resultante (`fcdbc68`) capturou, por nova coincidência de timing, os 3 arquivos `tests/Feature/V16/Carteira*Test.php` do OUTRO processo em vez dos meus (que permaneceram no working tree). Não tentei nenhuma reescrita de histórico (proibido por instrução — sem `reset --hard`/`rebase`/`amend` de commits que não são estritamente "meu último commit em andamento", e especialmente arriscado com um processo concorrente ativo). Recommitei imediatamente depois, verificando `git diff --cached --name-only` ANTES de cada commit para garantir que só meus 4 arquivos estavam staged — o commit final `452287d` está correto e verificado (`git show --stat`). Resultado: o histórico tem um commit cosmeticamente "estranho" (`fcdbc68`, mensagem fala em `DashboardPendencyPropsTest` mas o diff mostra os arquivos V16/Carteira do outro processo) — mas nenhum conteúdo foi perdido ou duplicado; o trabalho de ambos os processos está corretamente presente na branch.
- Nenhum outro problema além do já documentado nas Deviations.

## User Setup Required
None — nenhuma configuração externa necessária. Backend-only; consumo dos novos props (`performance_equipe.breakdown`, `nps_ruins`, `novas_empresas`) fica para o Plan 97-04 (frontend).

## Next Phase Readiness
- `DashboardController::adminDashboard` está pronto para o Plan 97-04 consumir `nps_ruins` (carrossel "NPS ruim") e `novas_empresas` (cards "Empresas novas no mês") sem nenhuma mudança adicional de backend.
- `performance_equipe` mantém os campos legados (`score`, `classificacao`) para compat visual — Plan 97-04 pode migrar para `breakdown`/`nota_final` no seu próprio ritmo.
- Nenhuma migration nova; nenhuma mudança de schema.

---
*Phase: 97-redesign-dashboard-mercado-livre*
*Completed: 2026-07-20*

## Self-Check: PASSED

- FOUND: app/Http/Controllers/DashboardController.php
- FOUND: tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php
- FOUND: tests/Feature/Phase72/DashboardPendencyPropsTest.php
- FOUND: .planning/phases/97-redesign-dashboard-mercado-livre/97-02-SUMMARY.md
- FOUND: commit 452287d
- FOUND: commit fcdbc68
