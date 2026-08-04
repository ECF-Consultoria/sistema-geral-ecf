---
phase: 123-telas-e-relat-rios-v21-0
plan: 01
subsystem: desempenho
tags: [laravel, eloquent, react, node-test, desempenho-por-empresa]

# Dependency graph
requires:
  - phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
    provides: "tabela desempenho_company_score_snapshots persistida (CompanyScoreSnapshotWriter, scopes daCompetencia/doUsuario)"
provides:
  - "CompanyScoreSnapshotReader — fonte única de leitura de desempenho_company_score_snapshots (paraUsuario/paraUsuarios/resumo)"
  - "resources/js/lib/desempenhoLabels.js — vocabulário pt-BR, formatadores de margem/nota/motivos e regras puras de particionamento"
  - "Phase123TestCase — base de fixtures herdada pelas 5 suítes restantes da fase"
affects: [123-02, 123-03, 123-04, 123-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Ordenação canônica feita em PHP (nunca orderByDesc SQL) para não divergir null-first/null-last entre SQLite e MariaDB"
    - "Shape enxuto mapeado campo a campo (nunca toArray()) para não vazar colunas internas ao browser"
    - "Regras de particionamento de lista como funções puras em módulo JS separado do JSX, travadas por node --test"

key-files:
  created:
    - app/Services/Desempenho/CompanyScoreSnapshotReader.php
    - tests/Feature/Phase123/Phase123TestCase.php
    - tests/Feature/Phase123/CompanyScoreSnapshotReaderTest.php
    - resources/js/lib/desempenhoLabels.js
    - tests/js/desempenhoLabels.test.js
  modified: []

key-decisions:
  - "Ordenação da lista por empresa feita em PHP com sortBy() por tupla de desempate (null-last, nota decrescente, nome case-insensitive) — nunca em SQL, porque MariaDB e SQLite ordenam NULL de formas opostas em ORDER BY DESC"
  - "quality nulo no banco normalizado para o shape vazio esperado ({revenue_diff_source:null, margin_diff_source:null, margin_source:null, motivos:[]}) para o front nunca receber quality:null"
  - "fmtPp/fmtVarPct usam MINUS SIGN (U+2212) para negativo, não o hífen ASCII, seguindo o exemplo canônico do CONTEXT"

patterns-established:
  - "Reader de leitura pura (só SELECT + map, nunca services de compute) como fonte única compartilhada entre múltiplos controllers que precisam da 'mesma fonte de dados'"
  - "Módulo de labels/regras puras em resources/js/lib/ para telas sem harness de renderização de React — lógica de ramificação vira função testável por node --test em vez de ficar solta no JSX"

requirements-completed: [UIEM-01, UIEM-02, UIEM-04]

# Metrics
duration: 31min
completed: 2026-08-04
---

# Phase 123 Plan 01: Fundações compartilhadas (reader + labels) Summary

**`CompanyScoreSnapshotReader` (fonte única de leitura de `desempenho_company_score_snapshots`, sem HTTP, com ordenação canônica idêntica em SQLite/MariaDB) + `desempenhoLabels.js` (vocabulário pt-BR, formatador de margem `14,1% → 12,0%  −2,1` e as três regras de particionamento da lista) — as duas fundações que os planos 02-05 vão herdar.**

## Performance

- **Duration:** ~31 min
- **Started:** 2026-08-04T12:38:00Z
- **Completed:** 2026-08-04T13:09:06Z
- **Tasks:** 2 completed
- **Files modified:** 5 (todos criados, nenhum arquivo existente tocado)

## Accomplishments
- `CompanyScoreSnapshotReader` — único caminho de leitura de `desempenho_company_score_snapshots` para os 3 controllers da fase (`PerformanceController::show()`, `RelatorioBonificacaoController`, `BonusAuditoriaController`), com shape enxuto de 17 chaves e prova de zero chamada HTTP (`Http::fake()` + `Http::assertNothingSent()`)
- Ordenação canônica (nota decrescente, `null` sempre por último, desempate por nome) implementada em PHP para não divergir entre SQLite (testes) e MariaDB (produção) — a armadilha exata documentada no learnings §6
- `desempenhoLabels.js` concentra o texto final pt-BR das 3 telas: os 6 motivos de `quality.motivos` (com fallback legível para um 7º motivo futuro), o formato de margem "antes → depois + variação" travado pela D-05, e a frase sem jargão de UIEM-01
- Três funções puras de particionamento (`dividirPorEntrada`, `carteiraTodaShopeeNaEntrada`, `deveColapsarNaoEntraram`) travadas por teste com os dois casos reais nomeados no CONTEXT: Felipe (margem sobre 3 de 30 empresas) e Matheus Estrela (carteira toda-Shopee)
- `Phase123TestCase` pronta para ser herdada pelas 5 suítes restantes da fase (setor/cargos, `criarUserElegivel`, `adminLogado`, `darCarteira`, `seedMensal`, `seedLinha`, `seedLinhaShopee`)

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: CompanyScoreSnapshotReader + base de fixtures Phase123** - `46ea482a` (feat)
2. **Task 2: Módulo desempenhoLabels.js — vocabulário pt-BR e formatadores** - `159cd89f` (feat)

_Nenhuma task teve TDD explícito (`tdd="true"` não estava setado no plano); ambas seguiram implementação direta + teste na mesma task, conforme especificado._

## Files Created/Modified
- `app/Services/Desempenho/CompanyScoreSnapshotReader.php` - Leitura pura de `desempenho_company_score_snapshots`: `paraUsuario()`, `paraUsuarios()`, `resumo()`, ordenação canônica em PHP, mapeamento explícito de 17 chaves
- `tests/Feature/Phase123/Phase123TestCase.php` - Base abstrata de fixtures (setor/cargos, users elegíveis, carteira, snapshots mensal e por empresa, atalho Shopee) herdada pelas 6 suítes da fase
- `tests/Feature/Phase123/CompanyScoreSnapshotReaderTest.php` - 9 cenários: escopo por usuário/competência, shape de 17 chaves, ordenação com `null` por último, desempate por nome, `resumo()`, agrupamento de `paraUsuarios()`, competência vazia, `quality` nulo normalizado, ausência de chamada HTTP
- `resources/js/lib/desempenhoLabels.js` - Vocabulário pt-BR (motivos, status), formatadores (`fmtPctAbs`, `fmtPp`, `fmtVarPct`, `fmtNotaEmpresa`, `fmtMargemAntesDepois`, `fraseVarMargemPp`), constantes de texto das 3 telas, e as 3 funções puras de particionamento
- `tests/js/desempenhoLabels.test.js` - 19 testes `node --test`: exemplo canônico de margem, os 6 motivos com texto exato, fallback de slug desconhecido, sinais de `fmtPp`, as 3 saídas de `fraseVarMargemPp`, `ehPlaceholderShopee` nos 3 casos, e os casos reais Felipe/Matheus Estrela

## Decisions Made
- Ordenação da lista por empresa feita em PHP (`->sortBy()` com tupla de desempate), nunca `orderByDesc()` SQL — MariaDB e SQLite tratam `NULL` de forma oposta em `ORDER BY ... DESC` (learnings §6); a mesma query daria ordens diferentes entre teste e produção
- `quality` nulo no banco é normalizado no reader para o shape vazio esperado (`{revenue_diff_source: null, margin_diff_source: null, margin_source: null, motivos: []}`) — o front nunca recebe `quality: null` e tenta acessar `quality.motivos`
- `fmtPp`/`fmtVarPct` usam o caractere MINUS SIGN (U+2212) para valores negativos, não o hífen ASCII — segue literalmente o exemplo canônico do CONTEXT (`14,1% → 12,0%  −2,1`)
- Docblock do reader evita a string literal `toArray()` entre parênteses (reescrito como "nunca serializa o model inteiro") para não falhar o gate de aceite `grep -c 'toArray()'` que não distingue comentário de código

## Deviations from Plan

None - plan executado exatamente como escrito.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `CompanyScoreSnapshotReader` e `desempenhoLabels.js` prontos para os planos 02-05 consumirem nos 3 controllers e nas 3 telas
- `Phase123TestCase` pronta para ser herdada sem reescrita de fixture
- Verificado: nenhum arquivo de `CompanyScoreService.php`, `DesempenhoScoreService.php` ou `config/metrics.php` foi tocado (escopo de leitura pura preservado)
- `--filter=Phase122` (49/49) e `--filter=CompanyScoreSnapshotReaderTest` (9/9) verdes; `npm run test:js` 106 pass / 1 fail pré-existente e não relacionado (`estrutura-grade-glide.test.js`, arquivo intocado nesta sessão); `npm run build` sem erro

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*

## Self-Check: PASSED

Todos os 5 arquivos criados confirmados em disco por `[ -f ... ]`; os 2 commits de task (`46ea482a`, `159cd89f`) confirmados em `git log --oneline --all`.
