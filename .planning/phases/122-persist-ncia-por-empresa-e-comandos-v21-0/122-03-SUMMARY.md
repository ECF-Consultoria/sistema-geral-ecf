---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plan: 03
subsystem: desempenho-persistencia
tags: [snapshot, cron, feature-flag, gate, tdd-nao-aplicavel]

requires:
  - phase: 122-01
    provides: "CompanyScoreSnapshotWriter::sync() e a tabela desempenho_company_score_snapshots"
  - phase: 122-02
    provides: "margem_amostra com sub-chave 'legado' — base que o gate FIXMARG-03 passa a escolher explicitamente"
provides:
  - "Os três comandos do fechamento (consolidar-mes, snapshot-scores, warm-cache) gravam linhas por empresa via CompanyScoreSnapshotWriter::sync()"
  - "breakdown_json do snapshot mensal E diário passam a conter empresas_score"
  - "Gate FIXMARG-03 escolhe a base da cobertura pela feature flag metrics.performance_company_first_score (D-122-05), não pelo shape do relatório"
  - "Log::error de recusa do gate registra base_gate/cobertura_pp/cobertura_legado"
affects: [122-04, 122-05, "qualquer plano futuro que consulte desempenho_company_score_snapshots em produção"]

tech-stack:
  added: []
  patterns:
    - "comandos wrappeados chamam CompanyScoreSnapshotWriter::sync() guardados por array_key_exists('score_status_por_empresa', $result) — sinal canônico de que o shadow rodou (D-05 da Fase 121)"
    - "gate de qualidade de amostra lê a base pela feature flag, não pelo shape do payload (D-122-05)"

key-files:
  created:
    - tests/Feature/Phase122/GateFixmarg03BaseTest.php
    - tests/Feature/Phase122/ComandosGravamEmpresasTest.php
  modified:
    - app/Console/Commands/ConsolidarMesDesempenho.php
    - app/Console/Commands/SnapshotDesempenhoScores.php
    - app/Console/Commands/WarmDesempenhoCache.php

key-decisions:
  - "D-122-05/D-122-06/D-122-07 implementadas literalmente conforme 122-03-PLAN.md"
  - "Posição exata da chamada ao writer dentro do try/catch por profissional (propagado do plan-check) — falha do writer não aborta o lote"

requirements-completed: [SNAP-01, SNAP-03]

duration: ~50min
completed: 2026-08-03
---

# Phase 122 Plan 03: Comandos ligados à persistência por empresa e base do gate FIXMARG-03 Summary

Os três comandos do fechamento (`consolidar-mes`, `snapshot-scores`, `warm-cache`) agora chamam `CompanyScoreSnapshotWriter::sync()` depois de gravar seu snapshot agregado, populando `desempenho_company_score_snapshots` com `origem` distinta por comando; o gate FIXMARG-03 passa a escolher explicitamente entre a cobertura legada e a cobertura em pontos percentuais conforme a feature flag `metrics.performance_company_first_score` (hoje `false` — zero mudança de comportamento em produção).

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-03T14:44:00Z (aprox.)
- **Completed:** 2026-08-03T15:05:00Z (aprox.)
- **Tasks:** 3 (todas concluídas)
- **Files modified:** 5 (3 comandos, 2 suítes novas)

## Accomplishments
- `ConsolidarMesDesempenho` injeta `CompanyScoreSnapshotWriter` e chama `sync()` com `origem='consolidar_mes'` **depois** do `updateOrCreate` bem-sucedido, nunca no caminho de recusa do gate nem em `sem_carteira` (D-122-06) — a origem `consolidar_mes` ignora a trava de congelamento do writer de propósito, porque reconsolidar mês fechado é caminho oficial (SNAP-06)
- Gate FIXMARG-03 lê `margem_amostra['legado']` quando a flag está desligada (comportamento de hoje, byte a byte, confirmado pela suíte `Phase110` intocada) e `margem_amostra` (pp) quando a flag está ligada (D-122-05) — `Log::error` de recusa passa a registrar `base_gate`/`cobertura_pp`/`cobertura_legado` para o rollout enxergar o impacto da troca de régua sem precisar rodar nada
- `SnapshotDesempenhoScores` passa a chamar `compute()` com `incluirEmpresasScore: true` (D-122-07) — `breakdown_json` da row **diária** ganha `empresas_score` (SNAP-01) — e grava as linhas por empresa da competência corrente com `origem='snapshot_diario'`, protegido pela trava de congelamento do writer contra `--data=` retroativo
- `WarmDesempenhoCache` injeta o writer e grava `origem='warm_cache'` dentro do mesmo `try/catch` que já envolve o laço por profissional; a trava de congelamento preserva intacta qualquer competência já fechada por `consolidar_mes`, mesmo sob reprocessamento a cada 8 minutos
- 12 testes novos: `GateFixmarg03BaseTest` (5, mocka `DesempenhoScoreService::compute()` por inteiro para controlar `margem_amostra`) e `ComandosGravamEmpresasTest` (7, mocka `CompanyScoreService::computeEmpresasScore()` — padrão `Phase120/AgregacaoProfissionalTest`) — todas as asserções por reconsulta ao banco, nenhuma por stdout

## Task Commits

1. **Task 1: ConsolidarMesDesempenho grava as linhas por empresa e escolhe a base do gate** — `f75e81ee` (feat)
2. **Task 2: Snapshot diário e warm gravam as linhas da competência que computam** — `e7c683c8` (feat)
3. **Task 3: Suítes dos comandos e da base do gate** — `e57c30d7` (test)

## Files Created/Modified
- `app/Console/Commands/ConsolidarMesDesempenho.php` — injeta `CompanyScoreSnapshotWriter`; gate FIXMARG-03 lê `margem_amostra['legado']` ou `margem_amostra` conforme a flag; chama `sync()` após persistência bem-sucedida; docblock ganha seção SNAP-03/D-122-05/D-122-06
- `app/Console/Commands/SnapshotDesempenhoScores.php` — `compute()` com shadow ligado; chama `sync()` com `origem='snapshot_diario'`; docblock atualizado
- `app/Console/Commands/WarmDesempenhoCache.php` — injeta o writer (3º parâmetro promovido); chama `sync()` dentro do laço com `origem='warm_cache'`; docblock corrigido (não é mais verdade que "este comando NÃO grava snapshot" — agora grava a tabela por empresa)
- `tests/Feature/Phase122/GateFixmarg03BaseTest.php` — 5 testes da base do gate (flag desligada/ligada, fallback sem sub-chave `legado`, log de contexto)
- `tests/Feature/Phase122/ComandosGravamEmpresasTest.php` — 7 testes dos três comandos (grava, idempotência, poda, `empresas_score` no breakdown diário, trava de congelamento no warm, sem_carteira não grava)

## Decisions Made
- **D-122-05 aplicada literalmente** — a base do gate segue `config('metrics.performance_company_first_score')`, com fallback para `margem_amostra` quando a sub-chave `legado` não existe (payload de cache antigo/shadow desligado)
- **D-122-06 aplicada literalmente** — recusa do gate nunca chama o writer; validado em `GateFixmarg03BaseTest` (cenário 2: linha por empresa pré-existente permanece com `updated_at` intocado)
- **D-122-07 aplicada literalmente** — `snapshot-scores` roda agora com o shadow sempre ligado; custo aceito (T-122-14) porque os diffs por empresa vêm do cache que o warm já mantém quente

## Deviations from Plan

Nenhuma nos arquivos de produção — os três comandos foram alterados exatamente como o plano descreve, sem Rule 1/2/3/4 aplicada. A única adaptação foi de **escopo de teste** (não de produção), documentada abaixo em "Issues Encountered".

## Issues Encountered

**Armadilha SQLite pré-existente exposta pelos testes novos (já registrada em `122-01-SUMMARY.md` como risco para a Wave 2).** `DesempenhoScoreSnapshot::updateOrCreate(['user_id','mes_referencia'=>$mesStr])` dentro de `ConsolidarMesDesempenho` busca com `mes_referencia` CRU — sob SQLite (só testes) o cast `date` do model grava a coluna como datetime completo, e a busca da 2ª chamada nunca casa, tentando um `INSERT` que colide com a `UNIQUE(user_id, ref_date, mes_referencia)` da row mensal já gravada pela 1ª chamada. Isso derruba QUALQUER teste que rode `desempenho:consolidar-mes` duas vezes sobre a mesma competência sob SQLite — inclusive testes que já existiam antes desta fase (confirmado isolando: 8 falhas em `--filter=Phase74`, todas contidas no conjunto de 14 falhas já confirmado como baseline pré-existente em `--filter=Desempenho`; nenhuma delas é nova). Em MariaDB de produção a coluna `DATE` trunca a hora e o comando funciona normalmente — não é uma regressão desta task, e mexer nesse `updateOrCreate` é decisão própria fora do escopo deste plano (o orquestrador confirmou explicitamente: "mexer no `updateOrCreate` do fechamento mensal é mudança de comportamento de congelamento de bônus").

Meus testes 2 e 3 de `ComandosGravamEmpresasTest` (idempotência e poda) precisam rodar `consolidar-mes` duas vezes sobre a mesma competência para provar o comportamento do **writer novo**. Para não esbarrar na armadilha acima (que não tem relação com o que estou testando), adicionei um workaround **só de teste**, documentado inline no próprio arquivo: apaga a row AGREGADA (`desempenho_score_snapshots`, não a por-empresa) entre as duas execuções, permitindo que o `updateOrCreate` externo tente um `INSERT` limpo em vez de colidir. A tabela que este plano realmente entrega (`desempenho_company_score_snapshots`) nunca é tocada por esse workaround — ela é gravada pelo `CompanyScoreSnapshotWriter::sync()` normalmente, exercitando o comportamento real de idempotência/poda sob teste.

**Fixture "vínculo mínimo" precisou usar o setor Shopee, não Performance.** Uma tentativa inicial de usar `Servico::SETOR_PERFORMANCE` para o vínculo mínimo (só para satisfazer `sem_carteira=false`) acidentalmente ativava o próprio gate FIXMARG-03 real (empresa elegível a margem Adman, sem `AdmanMetric` nenhum → cobertura=0 → degradada), recusando o congelamento antes de sequer chegar no writer — mesmo padrão do cenário "empresa sem margem" do `Phase110/ConsolidarMesMargemResilienteTest`. Corrigido trocando para `Servico::SETOR_SHOPEE` (cobertura=1.0/n_elegivel=0, nunca aciona o gate — mesmo padrão do teste "só-Shopee não é degradado" daquela suíte). Documentado inline no helper `criarVinculoMinimo()`.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Os três comandos estão prontos para produção: com a flag `metrics.performance_company_first_score` desligada (estado atual), o comportamento observável de `consolidar-mes` no gate/snapshot agregado é byte a byte o de antes — só passa a existir a tabela nova por empresa como efeito colateral aditivo
- `desempenho_company_score_snapshots` já recebe dados reais assim que os comandos rodarem em produção (SNAP-03 fechado) — Plano 05 pode agora construir o comando verificador que compara a tabela nova contra o `breakdown_json` legado
- Pendência que este plano NÃO resolve (fora de escopo, decisão própria): o `updateOrCreate` de `DesempenhoScoreSnapshot` em `ConsolidarMesDesempenho` continua com a busca crua de `mes_referencia`, que só se manifesta como bug em ambiente de teste SQLite — recomenda-se avaliar o mesmo fix de `whereDate()` do 122-01 numa sessão dedicada, já que agora existem MAIS testes (Phase110 + Phase122) documentando o sintoma

## Self-Check: PASSED

- FOUND: app/Console/Commands/ConsolidarMesDesempenho.php
- FOUND: app/Console/Commands/SnapshotDesempenhoScores.php
- FOUND: app/Console/Commands/WarmDesempenhoCache.php
- FOUND: tests/Feature/Phase122/GateFixmarg03BaseTest.php
- FOUND: tests/Feature/Phase122/ComandosGravamEmpresasTest.php
- FOUND commit f75e81ee (Task 1)
- FOUND commit e7c683c8 (Task 2)
- FOUND commit e57c30d7 (Task 3)

---
*Phase: 122-persist-ncia-por-empresa-e-comandos-v21-0*
*Completed: 2026-08-03*
