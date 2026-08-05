---
phase: 123-telas-e-relat-rios-v21-0
plan: 04
subsystem: desempenho
tags: [react, inertia, tailwind, phpunit, node-test, desempenho-por-empresa]

# Dependency graph
requires:
  - phase: 123-01
    provides: "desempenhoLabels.js (formatadores + 3 funções puras de particionamento) — fonte única de texto e regra desta wave"
  - phase: 123-02
    provides: "PerformanceController::show() com empresas_score/tem_detalhe_empresas/empresas_score_resumo"
provides:
  - "Card de margem de Performance/Show.jsx sem jargão de API (UIEM-01), preservando o número que produz a nota (D-04)"
  - "EmpresasScoreTabela.jsx — componente reutilizável de duas seções, denominador explícito, formato antes→depois e selo Shopee (D-05/D-06/D-07), pronto para o Plano 05 reusar no Relatório de Bonificação"
  - "Seção 'Empresas da carteira' em Performance/Show.jsx com aviso explícito de ausência (D-03)"
  - "RetrocompatSnapshotAntigoTest — prova de UIEM-03/D-11 contra payload de snapshot anterior à Fase 120"
affects: [123-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Componentes de apresentação puros (sem router/usePage/fetch) em resources/js/Components/Desempenho/ para serem reusados por mais de uma tela da mesma fase"
    - "Ramificação de UI (particionamento, colapso, caso-toda-Shopee) sempre delegada às funções puras de desempenhoLabels.js — nunca reimplementada no JSX consumidor, travado por grep negativo nos testes estruturais"

key-files:
  created:
    - resources/js/Components/Desempenho/EmpresasScoreTabela.jsx
    - tests/Feature/Phase123/RetrocompatSnapshotAntigoTest.php
    - tests/js/estrutura-performance-show.test.js
  modified:
    - resources/js/Pages/Performance/Show.jsx

key-decisions:
  - "Título do card de margem via MARGEM_CARD_TITULO/MARGEM_CARD_SUBLABEL + fraseVarMargemPp condicional (só quando var_margem_pp existe) — nunca dois textos concatenados incondicionalmente, para não produzir frase vazia/estranha em payload legado"
  - "Título fmtMargemAntesDepois() vai no <td> (não num <div> interno) — leitura literal do 'Pôr title={...} na célula' do plano; só se aplica ao formato padrão, nunca ao selo Shopee (que já tem seu próprio title)"
  - "CelulaMargem recebe ocultarSeloIndividual (decidido uma única vez por carteiraTodaShopeeNaEntrada sobre a seção 'entraram') em vez de recalcular o caso-toda-Shopee por linha — evita qualquer chance de a checagem divergir entre linhas da mesma tabela"

requirements-completed: [UIEM-01, UIEM-02, UIEM-03]

# Metrics
duration: ~21min
completed: 2026-08-04
---

# Phase 123 Plan 04: Empresas da carteira em Performance/Show.jsx (UIEM-01/UIEM-02/UIEM-03) Summary

**Card de margem sem `percentageMargin` no sublabel (frase em pontos percentuais condicional, D-04 preservada) + componente `EmpresasScoreTabela` reutilizável (formato `14,1% → 12,0%  −2,1`, denominador explícito, selo Shopee que degrada para aviso único) + aviso explícito de ausência em `Performance/Show.jsx`, provado contra snapshot anterior à Fase 120 por 4 testes de retrocompatibilidade.**

## Performance

- **Duration:** ~21 min
- **Started:** 2026-08-04T14:05:00Z
- **Completed:** 2026-08-04T14:26:00Z
- **Tasks:** 3 completed
- **Files modified:** 4 (3 criados, 1 modificado)

## Accomplishments
- UIEM-01 fechada: o card de margem de `Performance/Show.jsx` não cita mais `percentageMargin` (nome de campo de API) — sublabel passa a ser `MARGEM_CARD_SUBLABEL` + a frase em linguagem simples de `fraseVarMargemPp()` quando o shadow já gravou `var_margem_pp` para a competência. O `valor` do card **continua** `formatPercent(c.var_margem_pct)` (D-04) — nenhum número em destaque contradiz a `nota_final` exibida ao lado
- `EmpresasScoreTabela.jsx` — componente novo, puro (sem `router`/`usePage`/fetch), com duas seções ("Entraram na conta (N)" / "Não entraram (M)") cujo denominador vem sempre de `resumo`, nunca recontado de `linhas.length`; margem no formato antes→depois + variação em pp travado pela D-05; segunda seção nasce colapsada quando `deveColapsarNaoEntraram()` diz que sim, e some por completo quando `resumo.nao_entraram === 0`
- Selo Shopee (D-07) com o tratamento do caso "carteira inteira" (Matheus Estrela): selo por linha no caso comum, aviso único acima da tabela + texto "sem dado de margem" nas células quando `carteiraTodaShopeeNaEntrada()` é `true` — nunca a empresa Shopee excluída do denominador
- Seção "Empresas da carteira" encaixada em `Performance/Show.jsx`, dentro do ramo `!semCarteira`, logo após "Info carteira": renderiza `EmpresasScoreTabela` quando `tem_detalhe_empresas` é `true`, ou uma caixa de aviso explícita (nunca silenciosa) quando é `false` — texto varia por `modoAtivo` (em curso vs. mês fechado sem consolidação)
- UIEM-03/D-11 fechada por `RetrocompatSnapshotAntigoTest` (4 testes): payload de snapshot sem `var_margem_pp` e sem `empresas_score` na raiz do `breakdown_json` renderiza 200 sem inventar chave; caso pós-Fase 120 com `empresas_score: []` presente comporta-se identicamente; modo "Em curso" sem carteira não quebra

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Card de margem sem jargão + retrocompatibilidade do payload (UIEM-01/UIEM-03/D-11)** - `af255874` (feat)
2. **Task 2: Componente EmpresasScoreTabela — duas seções, denominador explícito, formato antes→depois e selo Shopee** - `a1e276fe` (feat)
3. **Task 3: Encaixe da seção "Empresas da carteira" em Show.jsx + aviso de ausência (D-03)** - `34284c61` (feat)

_Nenhuma task teve TDD explícito (`tdd="true"` não estava setado no plano); todas seguiram implementação direta + teste na mesma task, conforme especificado._

## Files Created/Modified
- `resources/js/Pages/Performance/Show.jsx` - Card de margem reescrito (título/sublabel sem jargão, valor/trendDir preservados); "Como interpretar" sem abreviações (`Var. Margem` → `variação da margem`); defaults defensivos `empresas_score`/`empresas_score_resumo`/`tem_detalhe_empresas`; nova seção "Empresas da carteira" (lista ou aviso) após "Info carteira", dentro do ramo `!semCarteira`
- `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` - Componente novo: duas seções com denominador explícito, margem `antes → depois + variação`, selo Shopee com degradação para carteira inteira, segunda seção colapsável, motivos de `quality.motivos` sempre iterados como array
- `tests/Feature/Phase123/RetrocompatSnapshotAntigoTest.php` - 4 testes: snapshot pré-Fase 120 sem `var_margem_pp`/`empresas_score`; prova de que o backend não inventa chave; `empresas_score: []` presente comporta-se igual à ausência; "Em curso" sem carteira não quebra
- `tests/js/estrutura-performance-show.test.js` - 6 gates estruturais via `lerSemComentarios`: ausência de `percentageMargin`, uso de `EmpresasScoreTabela`/`tem_detalhe_empresas`/`empresas_score_resumo`, textos do aviso vindos do módulo compartilhado, anti-hardcode do texto de ausência, D-04 preservada (`formatPercent(c.var_margem_pct)`), denominador explícito presente no componente novo

## Decisions Made
- Sublabel do card de margem composto (`MARGEM_CARD_SUBLABEL` + frase condicional), nunca concatenação incondicional — payload sem `var_margem_pp` mostra só o texto legado, sem espaço sobrando nem frase vazia
- `title={fmtMargemAntesDepois(...)}` posicionado na própria `<td>` (não num `<div>` interno), lendo literalmente a instrução "pôr title na célula" — aplicado só ao formato padrão, nunca ao selo Shopee (que já carrega seu próprio `title`)
- Decisão de "carteira toda Shopee" (`carteiraTodaShopeeNaEntrada`) calculada uma única vez sobre a seção "entraram" e passada como prop `ocultarSeloIndividual` para cada célula de margem — evita qualquer risco de a checagem divergir linha a linha

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `EmpresasScoreTabela` pronta para o Plano 05 reusar na linha expansível do Relatório de Bonificação (D-08) — props `linhas`/`resumo`/`className`, sem nenhuma suposição de tela específica
- `git diff --stat app/` vazio nas 3 tasks — nenhum arquivo de backend tocado, confirmando que esta wave foi puramente de front (backend já entregue no Plano 02)
- `--filter=RetrocompatSnapshotAntigoTest` 4/4, `--filter=Phase123` 35/35, `--filter=Phase120` 18/18, `--filter=Desempenho` 14 failed/101 passed (baseline exata, zero regressão nova); `npm run test:js` 119 pass/1 fail (mesma falha pré-existente e não relacionada em `estrutura-grade-glide.test.js`, documentada desde o 123-01-SUMMARY.md); `npm run build` sem erro nas 3 tasks

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*

## Self-Check: PASSED

Os 3 arquivos criados confirmados em disco (`EmpresasScoreTabela.jsx`, `RetrocompatSnapshotAntigoTest.php`, `estrutura-performance-show.test.js`); os 3 commits de task (`af255874`, `a1e276fe`, `34284c61`) confirmados em `git log --oneline --all`.
