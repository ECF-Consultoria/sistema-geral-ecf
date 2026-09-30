---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 04
subsystem: desempenho-bonificacao
tags: [laravel, inertia, react, desempenho, bonificacao, ranking, user_setores]

# Dependency graph
requires:
  - phase: 159-01
    provides: "Schema de user_setores aceita dois cargos no mesmo setor (unique (user_id, setor_id, cargo_id))"
provides:
  - "App\\Support\\CargosDesempenho — fonte única de 'quais cargos de Desempenho a pessoa tem', com desempate determinístico do cargo principal (is_principal, depois menor id)"
  - "PerformanceController::index/show, RelatorioBonificacaoController::montarLinhas, BonusAuditoriaController::index e PortfolioController (2 ocorrências) resolvem cargo pela mesma fonte"
  - "Pessoa com dois cargos aparece em CADA aba de cargo que tem (ranking e relatório), com a MESMA nota e a MESMA posição geral"
affects: [159-05, 159-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Classe App\\Support\\*Desempenho — fonte única de metadado de EXIBIÇÃO/FILTRO, nunca do cálculo (motor fica em DesempenhoScoreService, intocado)"
    - "Posição do ranking calculada ANTES do filtro por cargo, para não recalcular quando a pessoa aparece em mais de uma aba"

key-files:
  created:
    - app/Support/CargosDesempenho.php
    - tests/Feature/Phase159/CargosDesempenhoTest.php
    - tests/Feature/Phase159/RankingDoisCargosTest.php
    - .planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/deferred-items.md
  modified:
    - app/Http/Controllers/PerformanceController.php
    - app/Http/Controllers/RelatorioBonificacaoController.php
    - app/Http/Controllers/BonusAuditoriaController.php
    - app/Http/Controllers/PortfolioController.php

key-decisions:
  - "Desempate do cargo principal: is_principal primeiro, depois a linha de MENOR id — nunca a ordem de retorno do banco (MariaDB não garante ordem estável sem ORDER BY explícito)"
  - "Filtro por cargo no ranking/relatório: pessoa com dois cargos aparece em cada aba que tem, com cargo_slug/cargo_label sobrescritos pelo cargo DA ABA; sem filtro, aparece uma vez com o rótulo combinado ('Analista · Estrategista')"
  - "Posição geral do ranking é calculada ANTES do filtro por cargo (sort + posicao), preservando a mesma posição nas duas abas"
  - "PortfolioController::renderPortfolio — comparação com pares e contadores sugador/ppa passam a seguir o cargo PRINCIPAL determinístico, deixando de ser arbitrário (era a primeira linha que o banco retornasse)"
  - "User::cargoDesempenhoSlug()/dimensaoNpsDesempenho() NÃO tocados — D-09 é medição, fora deste plano"

requirements-completed: [SC4, SC5, D-05]

# Metrics
duration: 15min
completed: 2026-09-30
---

# Fase 159 Plano 04: Ranking e Relatório de Bonificação nas duas abas (D-05) Summary

**`App\Support\CargosDesempenho` unifica a resolução de cargo em 4 controllers (Performance, RelatorioBonificacao, BonusAuditoria, Portfolio), fazendo quem tem dois cargos aparecer em cada aba com a mesma nota e a mesma posição — sem tocar uma linha de `DesempenhoScoreService`.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-09-30T17:35:02-03:00
- **Completed:** 2026-09-30T17:50:00-03:00
- **Tasks:** 3 (todas em ciclo TDD RED→GREEN)
- **Files modified:** 8 (4 criados, 4 modificados)

## Accomplishments
- `App\Support\CargosDesempenho` criada: `porUsuario()`/`doUsuario()` resolvem cargos numa única query (`user_setores → cargos`), com desempate determinístico do principal (`is_principal` DESC, `id` ASC); `rotulo()` monta o texto de exibição com/sem filtro de aba
- `PerformanceController::index()` troca o `$cargosPorUser` (keyBy sem ORDER BY, cargo arbitrário) por `CargosDesempenho::porUsuario()`; posição geral continua calculada ANTES do filtro; payload ganha `cargos_slugs`; a aba filtrada sobrescreve `cargo_slug`/`cargo_label` com o cargo DA ABA
- `PerformanceController::show()` e `RelatorioBonificacaoController::montarLinhas()` seguem o mesmo padrão — elegíveis do relatório sem filtro são TODAS as pessoas com cargo; com filtro, só quem TEM o cargo pedido
- `BonusAuditoriaController::index()` e as duas ocorrências de `->value('c.slug')` em `PortfolioController` (`renderCarteiraProfissional`, `renderPortfolio`) migradas para a mesma fonte; comparação com pares e contadores sugador/ppa passam a seguir o cargo PRINCIPAL determinístico
- 15 testes novos (7 em `CargosDesempenhoTest`, 8 em `RankingDoisCargosTest`), todos verdes; nenhuma regressão nas suítes sentinela (`PerformanceCargoFilterTest`, `PerformanceSimuladorPropsTest`, `RelatorioBonificacaoTest`, `Phase123/RelatorioBonificacaoEmpresasTest`, `Phase123/AuditoriaBonusNotaEmpresaTest`, `Portfolio/RenderPortfolioTest`, `PortfolioShopeeCarteiraTest`)
- SC5 garantido: `git diff origin/main -- app/Services/DesempenhoScoreService.php app/Services/Desempenho/` vazio; `Phase118/NpsPorEmpresaContratoTest` verde; `Phase119` com exatamente as 17/29 falhas herdadas da baseline (D-12) — nem mais, nem menos

## Task Commits

Cada task seguiu o ciclo TDD RED→GREEN, commitado atomicamente:

1. **Task 1: Fonte única dos cargos de Desempenho por pessoa**
   - RED: `f1f08070` (test) — 7 testes, `CargosDesempenho` ainda não existe
   - GREEN: `fce76011` (feat) — classe criada, os 7 testes passam
2. **Task 2: Ranking e Relatório de Bonificação nas duas abas com a mesma nota (D-05)**
   - RED: `c1e2e2b4` (test) — 7 testes do `<behavior>`, 4 falham (cargo arbitrário do `keyBy` antigo)
   - GREEN: `70268a4a` (feat) — `PerformanceController` e `RelatorioBonificacaoController` ajustados, os 7 testes passam
3. **Task 3: Auditoria de bônus e Portfolio com cargo determinístico + guarda do SC5**
   - RED: `56c457ec` (test) — teste 8, falha (cargo arbitrário)
   - GREEN: `24f04ebe` (feat) — `BonusAuditoriaController` e `PortfolioController` ajustados, teste 8 passa; guarda do SC5 confirmada

**Plan metadata:** commit separado a seguir (docs: complete plan), junto com este SUMMARY.

## Files Created/Modified
- `app/Support/CargosDesempenho.php` - fonte única de cargos de Desempenho por pessoa (`porUsuario`, `doUsuario`, `rotulo`)
- `tests/Feature/Phase159/CargosDesempenhoTest.php` - 7 testes: desempate determinístico, ordem canônica, dedup entre setores, rótulo
- `tests/Feature/Phase159/RankingDoisCargosTest.php` - 8 testes: ranking (4), relatório (3), auditoria (1) — cenário A/D/E (D com os dois cargos)
- `app/Http/Controllers/PerformanceController.php` - `index()`/`show()` usam `CargosDesempenho`; payload ganha `cargos_slugs`
- `app/Http/Controllers/RelatorioBonificacaoController.php` - `montarLinhas()` usa `CargosDesempenho`; elegibilidade por cargo via `slugs`
- `app/Http/Controllers/BonusAuditoriaController.php` - `index()` usa `CargosDesempenho`; payload ganha `cargos_slugs`
- `app/Http/Controllers/PortfolioController.php` - as duas ocorrências de `->value('c.slug')` (linhas ~991 e ~2259 do estado pré-plano) trocadas por `CargosDesempenho::doUsuario()`
- `.planning/phases/159-.../deferred-items.md` - 3 falhas pré-existentes de `tests/Feature/Phase61/` (fora de escopo, confirmadas não relacionadas)

## Decisions Made
- **Desempate do principal:** `is_principal` DESC, depois `id` ASC (linha mais antiga) — nunca a ordem de retorno do banco. Repetir a leitura 3× dá o mesmo resultado (testado).
- **Filtro de aba sobrescreve cargo exibido:** nas linhas que passam pelo filtro `?cargo=`, `cargo_slug`/`cargo_label` passam a refletir o cargo DA ABA (não o principal da pessoa) — é o que faz D aparecer como "Analista" na aba analista e "Estrategista" na aba estrategista, sempre com a mesma `nota_final`.
- **Posição geral antes do filtro:** o `sort`+`posicao` do ranking roda sobre o conjunto COMPLETO, antes de qualquer filtro por cargo — por isso a posição de D é a mesma (2) nas duas abas, refletindo o ranking geral, não um recálculo por aba.
- **Portfolio (renderPortfolio):** comparação com pares e contadores sugador/ppa passam a usar o cargo PRINCIPAL (determinístico) em vez do primeiro valor que o banco retornasse — comportamento muda de arbitrário para determinístico, mas a lógica downstream (`if ($cargoSlug === 'analista')` etc.) não foi alterada.
- **`User::cargoDesempenhoSlug()`/`dimensaoNpsDesempenho()` intocados** — são o ramo legado do NPS (D-09), fora do escopo deste plano; acrescentar desempate ali mexeria em bônus sem decisão travada.

## Deviations from Plan

None - plano executado exatamente como escrito. As 3 falhas de `tests/Feature/Phase61/` descobertas durante a verificação foram investigadas (não corrigidas, por estarem fora de escopo) e confirmadas pré-existentes por comparação direta: revertido temporariamente `PortfolioController.php` ao estado anterior à Task 3 (via `git checkout -- <arquivo>`, sem `git stash`) e reexecutada a suíte — mesmas 3 falhas, byte a byte. Documentado em `deferred-items.md`.

## Issues Encountered
None além do item de escopo documentado acima.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## TDD Gate Compliance

As 3 tasks têm `tdd="true"` no frontmatter do plano e seguiram o ciclo completo:

- Task 1: `test(159-04)` RED (`f1f08070`, 7/7 falham) → `feat(159-04)` GREEN (`fce76011`, 7/7 passam). Sem REFACTOR.
- Task 2: `test(159-04)` RED (`c1e2e2b4`, 4/7 falham) → `feat(159-04)` GREEN (`70268a4a`, 7/7 passam). Sem REFACTOR.
- Task 3: `test(159-04)` RED (`56c457ec`, 1/1 falha) → `feat(159-04)` GREEN (`24f04ebe`, 1/1 passa). Sem REFACTOR.

Todos os gates RED e GREEN confirmados por execução real do PHPUnit entre os commits.

## Next Phase Readiness
- As quatro telas de desempenho/bônus resolvem cargo por uma fonte única e determinística; qualquer plano futuro que precise "quais cargos a pessoa tem" deve usar `CargosDesempenho` em vez de reimplementar a query.
- `User::dimensaoNpsDesempenho()` (ramo legado do NPS, D-09) segue pendente de medição — fora do escopo deste plano, registrado em `159-CONTEXT.md`.
- Nenhum bloqueio identificado para os planos seguintes da fase (159-05 em diante — junção das contas do Danilo, D-06).

---
*Phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo*
*Completed: 2026-09-30*

## Self-Check: PASSED

- Todos os 9 arquivos citados (criados/modificados) confirmados presentes no disco
- Todos os 6 commits citados confirmados em `git log --oneline`: `f1f08070`, `fce76011`, `c1e2e2b4`, `70268a4a`, `56c457ec`, `24f04ebe`
