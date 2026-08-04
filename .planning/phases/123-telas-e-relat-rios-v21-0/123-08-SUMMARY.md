---
phase: 123-telas-e-relat-rios-v21-0
plan: 08
subsystem: desempenho-bonificacao
tags: [laravel, inertia, react, php-unit, node-test, snapshot-first, gap-closure]

# Dependency graph
requires:
  - phase: 123-telas-e-relat-rios-v21-0 (planos 01-07)
    provides: "CompanyScoreSnapshotReader, desempenho_company_score_snapshots, desempenhoLabels.js, Auditoria.jsx com coluna de nota por empresa"
provides:
  - "BonusAuditoriaController::index() snapshot-first para nota_final, mesmo padrão de RelatorioBonificacaoController::montarLinhas()"
  - "Flag nota_congelada por profissional no payload da Auditoria de Bônus"
  - "Selo visual 'recalculada agora' em NotaBadge quando a nota vem de recomputo ao vivo"
  - "NOTA_RECALCULADA_TEXTO/NOTA_RECALCULADA_TITULO em desempenhoLabels.js"
affects: [desempenho, bonificacao, auditoria-bonus]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Snapshot-first com fallback computeCached() — replicado do padrão já em produção em RelatorioBonificacaoController, não reinventado"
    - "Flag booleana explícita (nota_congelada) para o front distinguir safra de dado sem adivinhar pela ausência de campo"

key-files:
  created: []
  modified:
    - app/Http/Controllers/BonusAuditoriaController.php
    - tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php
    - resources/js/Pages/Desempenho/Auditoria.jsx
    - resources/js/lib/desempenhoLabels.js
    - tests/js/estrutura-auditoria-desempenho.test.js

key-decisions:
  - "Opção A do 123-REVIEW.md (snapshot-first) escolhida sobre a Opção B (só rótulo visual sem tocar a fonte) — alinha a origem do dado, não só o rótulo"
  - "Selo só aparece quando nota_congelada === false EXPLICITAMENTE, nunca quando o campo está ausente/undefined — evita alarme falso em payload antigo"
  - "Nenhuma régua, agregação ou flag de negócio tocada — metrics.performance_company_first_score continua false"

patterns-established:
  - "Guarda isset($snap->breakdown_json['componentes']) para snapshot truncado/de safra anterior — mesma guarda em dois controllers agora"

requirements-completed: [UIEM-04]

# Metrics
duration: ~15min
completed: 2026-08-04
---

# Phase 123 Plan 08: Fechamento do gap CR-02 (Auditoria de Bônus, safra da nota) Summary

**`BonusAuditoriaController::index()` passa a ler `nota_final` do fechamento congelado (snapshot-first, mesmo padrão de `RelatorioBonificacaoController`) e a tela ganha um selo visível sempre que a nota exibida ainda vem de recomputo ao vivo.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-08-04T18:38Z (aprox.)
- **Completed:** 2026-08-04T18:47:47Z
- **Tasks:** 2/2
- **Files modified:** 5

## Accomplishments
- Fechado o Gap 2 do `123-VERIFICATION.md` (CR-02 do `123-REVIEW.md`, Opção A escolhida): a Auditoria de Bônus não expõe mais `nota_final` recomputada ao vivo ao lado de `nota_empresa` congelada sem sinal — na tela que decide invalidação de empresa para pagamento de bônus.
- `BonusAuditoriaController::index()` só chama `computeCached()` quando a competência não tem snapshot mensal utilizável (sem row, ou `breakdown_json` sem a chave `componentes`) para aquele profissional.
- Toda linha de `profissionais` expõe `nota_congelada` (boolean), e a tela mostra o selo "recalculada agora" com tooltip explicativo (sem implicar erro de cálculo) exatamente quando `nota_congelada === false`.

## Task Commits

Each task was committed atomically:

1. **Task 1: BonusAuditoriaController snapshot-first para nota_final (CR-02, Opção A)** - `13805544` (fix)
2. **Task 2: Selo de safra na Auditoria.jsx (CR-02, complemento visual)** - `0c6afb29` (feat)

_Nenhuma task era `tdd="true"` na frontmatter do plano; Task 1 seguiu o mesmo ciclo escreve-teste-e-código dentro de um único commit por ser um fix pontual e coeso, seguindo o padrão já usado pelas suítes irmãs do módulo._

## Files Created/Modified
- `app/Http/Controllers/BonusAuditoriaController.php` — `index()` busca `DesempenhoScoreSnapshot::mensal()` numa única query fora do `map()`, usa `breakdown_json` do fechamento quando existe e tem `componentes`, cai em `computeCached()` só como fallback; devolve `nota_congelada` por profissional.
- `tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php` — 3 testes novos provando os cenários de safra (congelada / sem snapshot / snapshot truncado); docblock da classe atualizado (não descreve mais o comportamento antigo).
- `resources/js/Pages/Desempenho/Auditoria.jsx` — `NotaBadge` ganha a prop `congelada` e renderiza o selo quando `false`; `ProfissionalCard` passa `prof.nota_congelada`.
- `resources/js/lib/desempenhoLabels.js` — `NOTA_RECALCULADA_TEXTO`/`NOTA_RECALCULADA_TITULO`, mesmo padrão de nomenclatura do selo Shopee já existente no arquivo.
- `tests/js/estrutura-auditoria-desempenho.test.js` — 2 testes novos (gate estrutural: refere `congelada`/`nota_congelada`; anti-hardcode do texto do selo).

## Decisions Made
- Opção A do `123-REVIEW.md` (snapshot-first, alinhar a fonte) em vez da Opção B (só rótulo visual sem tocar `index()`) — corrige a causa, não só o sintoma, e é o mesmo padrão já validado em produção por `RelatorioBonificacaoController`.
- Selo condicionado a `congelada === false` explicitamente (nunca `undefined`/`null`) — payload antigo sem a chave (não deveria ocorrer após este plano, mas por segurança) não gera alarme falso.
- Nenhuma régua, agregação ou cálculo de negócio tocado; a flag `metrics.performance_company_first_score` permanece `false`, conforme os limites da fase.

## Deviations from Plan

None - plano executado exatamente como escrito. Ambas as tasks seguiram literalmente o `<action>` e o `<interfaces>` do `123-08-PLAN.md`, replicando o padrão já em produção sem inventar variação.

## Issues Encountered
None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Gap 2 (CR-02) do `123-VERIFICATION.md` fechado — mecânica e teste automatizado cobrindo os 3 cenários de safra.
- Gap 1 (CR-01, autorização de `PerformanceController::show()`) do mesmo `123-VERIFICATION.md` continua em aberto — não fazia parte do escopo deste plano (`123-08-PLAN.md` só cobre `requirements: [UIEM-04]`/CR-02).
- Verificação visual da Auditoria de Bônus com dado real de produção continua pendente (adiada para pós-deploy por decisão já documentada do usuário em `123-VERIFICATION.md`), não é bloqueador deste plano.
- `--filter=Phase123` (52/52), `--filter=Phase120` (18/18) e `npm run test:js` (126/127, mesma falha pré-existente e não relacionada em `estrutura-grade-glide.test.js`) reexecutados sem regressão; `--filter=Desempenho` na mesma baseline conhecida (14 failed/102 passed).

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*
