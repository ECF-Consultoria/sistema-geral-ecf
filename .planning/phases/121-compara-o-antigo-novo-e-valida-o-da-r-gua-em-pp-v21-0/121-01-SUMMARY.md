---
phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
plan: 01
subsystem: database
tags: [laravel, eloquent, migration, php, phpunit, reflection, desempenho, shadow-flag]

# Dependency graph
requires:
  - phase: 120-agrega-o-profissional-feature-flag
    provides: "computeNotaFinalPorEmpresa()/computeScoreStatusPorEmpresa() + payload aditivo empresas_score no shadow de compute()"
provides:
  - "Payload de compute() ganha nota_final_por_empresa/score_status_por_empresa quando o shadow roda (D-05) — chaves condicionais, ausentes com shadow desligado"
  - "Duas tabelas insert-only (desempenho_comparador_profissionais/desempenho_comparador_empresas) para o comando comparador reconsultar"
  - "Models DesempenhoComparadorProfissional/DesempenhoComparadorEmpresa enxutos"
affects: [121-02, 121-03, 121-04, 121-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Chave aditiva condicional no payload (if $empresasScore !== null) — nunca sempre-presente-com-null, para não quebrar o teste dourado da Fase 120"
    - "Cálculo único do par novo (computeNotaFinalPorEmpresa/computeScoreStatusPorEmpresa) antes da bifurcação, reaproveitado pelo ramo da flag ligada e pela exposição condicional"
    - "Tabela insert-only decimal(14,6) para métricas de pp/percentual (evita fabricar falsa estabilidade por arredondamento em 2 casas)"

key-files:
  created:
    - database/migrations/2026_07_31_120000_create_desempenho_comparador_tables.php
    - app/Models/DesempenhoComparadorProfissional.php
    - app/Models/DesempenhoComparadorEmpresa.php
    - tests/Feature/Phase121/ShadowNotaNovaExpostaTest.php
    - tests/Feature/Phase121/ComparadorTabelasTest.php
  modified:
    - app/Services/DesempenhoScoreService.php

key-decisions:
  - "D-05 implementada literalmente: chaves novas SÓ existem quando $empresasScore !== null, nunca sempre-presentes com valor null — gate nº 4 do 121-VALIDATION.md exige isso para o teste dourado da Fase 120 continuar byte-idêntico"
  - "Par novo (nota/status por empresa) calculado UMA vez antes da bifurcação da flag, evitando dupla chamada aos métodos privados"
  - "D-91-01 (blocked força nota=null) replicada explicitamente sobre o par exposto, não só sobre o legado"

patterns-established:
  - "Modelos de auditoria insert-only gerados por comando NÃO usam LogsActivity (mesmo padrão das tabelas do probe da Fase 117 / BonusInvalidacao)"

requirements-completed: [ROLL-01]

duration: ~25min
completed: 2026-07-31
---

# Phase 121 Plan 01: Fundações do comparador (shadow expõe nota nova + tabelas insert-only) Summary

**`DesempenhoScoreService::compute()` passa a expor `nota_final_por_empresa`/`score_status_por_empresa` condicionalmente ao shadow (D-05), e duas tabelas insert-only (`desempenho_comparador_profissionais`/`desempenho_comparador_empresas`) ficam prontas para o comando comparador da Fase 121 gravar e reconsultar.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 3/3
- **Files modified:** 1
- **Files created:** 5

## Accomplishments
- `compute()` calcula `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` uma única vez antes da bifurcação da flag, com D-91-01 aplicada, e expõe o resultado em duas chaves aditivas no payload — SÓ quando o shadow roda (ausentes quando não roda)
- Teste dourado da Fase 120 (`PayloadBaselineFlagOffTest`) continua byte-idêntico, sem edição (`git diff` vazio) — 5/5 verde
- `--filter=Phase120` (18/18) e `--filter=Phase121` (8/8) verdes, `--filter=Desempenho` na baseline exata de 14 falhas pré-existentes
- Migration com as duas tabelas insert-only do comparador, no molde do probe da Fase 117 (decimal 14,6, sem tipo restrito por lista fixa, FK com cascade)
- Models `DesempenhoComparadorProfissional`/`DesempenhoComparadorEmpresa` enxutos, sem `LogsActivity`
- Suíte `ComparadorTabelasTest` prova round-trip de precisão decimal(14,6) na margem, round-trip de array em coluna JSON, e `status` aceitando string arbitrária

## Task Commits

Each task was committed atomically:

1. **Task 1: D-05 — o shadow expõe nota_final_por_empresa e score_status_por_empresa** - `f9c770c7` (feat)
2. **Task 2: Gate nº 4 — teste da aditividade da mudança do shadow** - `8343098d` (test)
3. **Task 3: Migration e models das duas tabelas insert-only do comparador** - `88e7838f` (feat)

_Task 1 é `tdd="true"` mas modifica um método existente (não cria feature nova isolada) — a suíte de aditividade (Task 2) que serve como o par RED/GREEN vivo veio na sequência, cobrindo o comportamento introduzido na Task 1._

## Files Created/Modified
- `app/Services/DesempenhoScoreService.php` - bifurcação de `compute()` calcula o par novo uma vez; payload ganha `nota_final_por_empresa`/`score_status_por_empresa` condicionais ao shadow
- `tests/Feature/Phase121/ShadowNotaNovaExpostaTest.php` - gate nº 4: ausência/presença/identidade-com-flag-ligada/blocked/sentinela de Reflection
- `database/migrations/2026_07_31_120000_create_desempenho_comparador_tables.php` - duas tabelas insert-only
- `app/Models/DesempenhoComparadorProfissional.php` - model por (run, profissional, competência)
- `app/Models/DesempenhoComparadorEmpresa.php` - model por (run, profissional, empresa, competência)
- `tests/Feature/Phase121/ComparadorTabelasTest.php` - existência das tabelas + precisão + round-trip JSON + status livre

## Decisions Made
- Nenhuma decisão nova além das já registradas no `121-01-PLAN.md` (`<design_decision>`) — plano executado conforme escrito.

## Deviations from Plan

**1. [Ajuste de discrição] Comentários da migration reescritos para não conter a substring literal `enum(`**
- **Found during:** Task 3, verificação do `<done>`
- **Issue:** Os comentários explicativos da migration mencionavam "nunca `enum()`" como texto — isso faz o próprio grep de aceite (`grep -n "enum(" ...`) encontrar essas linhas de comentário, mesmo sem nenhuma coluna `enum()` real no arquivo
- **Fix:** Reescrito para "STRING, nunca coluna de tipo restrito por lista fixa" nos dois pontos, preservando a explicação sem a substring literal
- **Files modified:** database/migrations/2026_07_31_120000_create_desempenho_comparador_tables.php
- **Verification:** `grep -n "enum(" ...` e `grep -n "nullOnDelete" ...` retornam 0 linhas (confirmado)
- **Committed in:** `88e7838f` (parte do commit da Task 3, arquivo criado já com a redação final)

---

**Total deviations:** 1 auto-fixed (ajuste de redação, sem mudança de comportamento)
**Impact on plan:** Nenhum impacto em código — só o texto do comentário mudou para o gate de aceite (`grep`) funcionar como o plano descreve.

## Issues Encountered
None.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- Fundações prontas para o Plano 02 (comando comparador) consumir: chaves `nota_final_por_empresa`/`score_status_por_empresa` no payload do shadow, e as duas tabelas para gravar/reconsultar
- Flag `metrics.performance_company_first_score` continua `false` — nada mudou em produção
- `run_id` (UUID, T-121-02) é responsabilidade do comando do Plano 02/03, não desta fundação

---
*Phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0*
*Completed: 2026-07-31*

## Self-Check: PASSED

- FOUND: app/Services/DesempenhoScoreService.php
- FOUND: tests/Feature/Phase121/ShadowNotaNovaExpostaTest.php
- FOUND: database/migrations/2026_07_31_120000_create_desempenho_comparador_tables.php
- FOUND: app/Models/DesempenhoComparadorProfissional.php
- FOUND: app/Models/DesempenhoComparadorEmpresa.php
- FOUND: tests/Feature/Phase121/ComparadorTabelasTest.php
- FOUND commit: f9c770c7
- FOUND commit: 8343098d
- FOUND commit: 88e7838f
