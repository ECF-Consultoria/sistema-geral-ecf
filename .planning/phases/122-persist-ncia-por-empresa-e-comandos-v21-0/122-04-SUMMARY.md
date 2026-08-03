---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plan: 04
subsystem: desempenho-persistencia
tags: [snapshot, invalidacao, bonus-auditoria, tdd]

requires:
  - phase: 122-01
    provides: "Tabela desempenho_company_score_snapshots e o model DesempenhoCompanyScoreSnapshot"
  - phase: 122-03
    provides: "Os três comandos do fechamento gravam linhas por empresa via CompanyScoreSnapshotWriter::sync() — pré-requisito do teste de round-trip"
provides:
  - "bustarCacheDaEmpresa() (BonusAuditoriaController) apaga desempenho_company_score_snapshots da competência, ao lado do snapshot mensal que já era apagado"
  - "A limpeza roda nos dois sentidos do toggle (invalidar E reativar)"
  - "Suíte InvalidacaoRemoveLinhasTest prova os 4 comportamentos + não-vazamento + coerência resumo/detalhe + round-trip com desempenho:consolidar-mes"
affects: [122-05, "qualquer plano futuro que compare desempenho_company_score_snapshots contra bonus_invalidacoes em produção"]

tech-stack:
  added: []
  patterns:
    - "Delete-only no controller: quem apaga é o toggle, quem regrava é sempre desempenho:consolidar-mes (SNAP-06) — nunca recompute inline em controller"
    - "Escopo de delete (competência) AND (company_id OU user_id afetado) — D-122-08"

key-files:
  created:
    - tests/Feature/Phase122/InvalidacaoRemoveLinhasTest.php
  modified:
    - app/Http/Controllers/BonusAuditoriaController.php

key-decisions:
  - "D-122-08/D-122-09 implementadas literalmente conforme 122-04-PLAN.md"
  - "Teste 7 (round-trip com desempenho:consolidar-mes) executado sem markTestSkipped — o Plano 03 já estava mergeado no momento desta execução"

requirements-completed: [SNAP-04]

duration: ~35min
completed: 2026-08-03
---

# Phase 122 Plan 04: Invalidação de empresa remove também as linhas por empresa Summary

`BonusAuditoriaController::bustarCacheDaEmpresa()` agora apaga as linhas de `desempenho_company_score_snapshots` da competência (empresa invalidada OU profissionais vinculados a ela), ao lado do snapshot mensal agregado que já era apagado — nos dois sentidos do toggle (invalidar e reativar) — fechando a divergência silenciosa entre o resumo mensal e o detalhe por empresa que a tela de auditoria e o Relatório de Bonificação passariam a expor.

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-03T15:10:00Z (aprox.)
- **Completed:** 2026-08-03T15:45:00Z (aprox.)
- **Tasks:** 2 (todas concluídas)
- **Files modified:** 2 (1 controller, 1 suíte nova)

## Accomplishments
- `bustarCacheDaEmpresa()` ganhou um segundo `delete()` sobre `DesempenhoCompanyScoreSnapshot`, travado em `mes_referencia = $competencia` e com escopo `company_id = $companyId OR user_id IN $userIds` (D-122-08) — mesma condição que garante que o detalhe por empresa nunca sobreviva ao resumo mensal apagado
- A limpeza corre automaticamente nos dois sentidos do `toggle()` (invalidar E reativar), sem precisar de nenhuma mudança na rota, validação ou fluxo de UI (D-122-09)
- Suíte `InvalidacaoRemoveLinhasTest` com 7 testes, todos por reconsulta ao banco: os 4 comportamentos do `<behavior>` da Task 1, não-vazamento entre competências (3 competências semeadas, só a invalidada é afetada), coerência resumo mensal/detalhe (ambos vazios simultaneamente, nunca um sem o outro) e um round-trip real com `desempenho:consolidar-mes` (mock de `CompanyScoreService::computeEmpresasScore()` devolvendo a carteira já sem a empresa invalidada, confirmando que a linha da empresa some após reconsolidar)
- Docblock do método reescrito em pt-BR explicando SNAP-04, D-122-08, D-122-09 e o contrato "controller só apaga, quem regrava é o `consolidar-mes`"

## Task Commits

1. **Task 1: bustarCacheDaEmpresa remove as linhas por empresa da competência** - `0ef39b6e` (feat)
2. **Task 2: Suíte de invalidação e coerência com a reconsolidação** - `2d9c2c04` (test)

**Plan metadata:** (este commit)

## Files Created/Modified
- `app/Http/Controllers/BonusAuditoriaController.php` — `use App\Models\DesempenhoCompanyScoreSnapshot;` adicionado; `bustarCacheDaEmpresa()` ganha o `delete()` por empresa/competência após o `delete()` mensal já existente; docblock do método expandido
- `tests/Feature/Phase122/InvalidacaoRemoveLinhasTest.php` — 7 testes: 4 comportamentos da Task 1, não-vazamento, coerência resumo/detalhe, round-trip com `desempenho:consolidar-mes`

## Decisions Made
Nenhuma decisão nova além das já registradas em D-122-08/D-122-09 no PLAN — aplicadas literalmente, sem ambiguidade a resolver durante a execução.

## Deviations from Plan

None - plano executado exatamente como escrito. `git diff` de `BonusAuditoriaController.php` toca só o bloco de `use` e o corpo/docblock de `bustarCacheDaEmpresa()`, conforme exigido pelo `<done>` da Task 1. `toggle()`, a validação, o flash e `index()` não foram alterados.

## Issues Encountered

Nenhum imprevisto de execução. O teste 7 (round-trip) pôde ser escrito por completo, sem `markTestSkipped`, porque o Plano 03 (que liga `desempenho:consolidar-mes` ao `CompanyScoreSnapshotWriter`) já estava mergeado (`122-03-SUMMARY.md` existente) no momento desta execução — a contingência prevista no PLAN não foi necessária.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `desempenho_company_score_snapshots` agora fica coerente com `bonus_invalidacoes` em todos os fluxos conhecidos: gravação pelos comandos (Plano 03) e limpeza pela invalidação/reativação (este plano)
- Plano 05 (comando verificador que compara a tabela nova contra o `breakdown_json` legado) pode contar com esta invariante: nenhuma linha órfã de empresa invalidada sobrevive na competência, e resumo/detalhe nunca ficam meio-apagados
- Baseline de testes intacta: `--filter=Phase122` 39/39 verde (32 pré-existentes + 7 novos), `--filter=BonusInvalidacao` 5/5 verde sem edição, `--filter=Desempenho` 14 failed/101 passed (idêntico à baseline), `--filter=Phase110` 2 failed/3 passed (falhas pré-existentes confirmadas, não mexidas), `--filter=Phase120` 18/18 verde

## Self-Check: PASSED

- FOUND: app/Http/Controllers/BonusAuditoriaController.php
- FOUND: tests/Feature/Phase122/InvalidacaoRemoveLinhasTest.php
- FOUND commit 0ef39b6e (Task 1)
- FOUND commit 2d9c2c04 (Task 2)

---
*Phase: 122-persist-ncia-por-empresa-e-comandos-v21-0*
*Completed: 2026-08-03*
