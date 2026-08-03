---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plan: 05
subsystem: desempenho-persistencia
tags: [verificacao, read-only, runbook, comando, tdd]

requires:
  - phase: 122-01
    provides: "Tabela desempenho_company_score_snapshots e o model DesempenhoCompanyScoreSnapshot"
  - phase: 122-03
    provides: "Os três comandos do fechamento gravam linhas por empresa via CompanyScoreSnapshotWriter::sync() — pré-requisito para o verificador ter o que reconsultar em produção"
  - phase: 122-04
    provides: "A invalidação de empresa mantém resumo mensal e detalhe por empresa coerentes — pré-requisito para a competência 100% consistente ser alcançável"
provides:
  - "desempenho:verificar-consolidacao --mes= — conferência READ-ONLY de uma competência por reconsulta às duas tabelas do fechamento, exit code binário (D-122-12)"
  - "122-ROLLOUT.md — runbook de rollout com backfill das 3 competências fechadas e verificação oficial pelo exit code (nunca pelo stdout do consolidar-mes)"
affects: ["122-06 (execução em produção deste runbook)"]

tech-stack:
  added: []
  patterns:
    - "verificador read-only: nenhuma escrita, gate de grep no <done> prova ausência de save/delete/insert/forget/create/updateOrCreate e de chamada ao service que monta o payload de nota"
    - "universo de user_id examinado = elegíveis (filtro canônico) UNIÃO quem tem qualquer dado gravado na competência — pega tanto SEM_SNAPSHOT quanto LINHAS_ORFAS de user não mais elegível"
    - "saída --json é o contrato; tabela/texto impresso é conveniência humana explicitamente rotulada como tal"

key-files:
  created:
    - app/Console/Commands/VerificarConsolidacaoDesempenho.php
    - tests/Feature/Phase122/VerificarConsolidacaoTest.php
    - .planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-ROLLOUT.md
  modified: []

key-decisions:
  - "D-122-10/D-122-11/D-122-12 implementadas literalmente conforme 122-05-PLAN.md"
  - "SEM_LINHAS só dispara quando breakdown_json.empresas_score é não-vazio E a tabela por empresa está vazia — row mensal legada (pré-shadow, sem chave empresas_score) com zero linhas por empresa NÃO é inconsistência, evita falso positivo em dado histórico"
  - "Universo de user_id examinado inclui não só elegíveis hoje, mas qualquer user_id com dado gravado na competência — pega LINHAS_ORFAS de quem perdeu elegibilidade depois da gravação"

requirements-completed: [SNAP-06]

duration: ~40min
completed: 2026-08-03
---

# Phase 122 Plan 05: Comando verificador (SNAP-06) e runbook de rollout Summary

`desempenho:verificar-consolidacao --mes=` confere uma competência do módulo Desempenho por RECONSULTA direta às duas tabelas do fechamento (`desempenho_score_snapshots` modalidade mensal e `desempenho_company_score_snapshots`), nomeando com `user_id` e nome quem cada uma das 5 inconsistências afeta e devolvendo exit code binário — nunca uma contagem anônima ou um texto que precise ser interpretado. O runbook `122-ROLLOUT.md` documenta o passo a passo do rollout (backfill das 3 competências fechadas + verificação oficial pelo exit code), sem executar nada em produção.

## Performance

- **Duration:** ~40 min
- **Started:** 2026-08-03T15:50:00Z (aprox.)
- **Completed:** 2026-08-03T16:30:00Z (aprox.)
- **Tasks:** 3 (todas concluídas)
- **Files modified:** 3 (1 comando novo, 1 suíte nova, 1 runbook novo)

## Accomplishments

- `VerificarConsolidacaoDesempenho` (`desempenho:verificar-consolidacao {--mes=} {--json}`) é 100% READ-ONLY (D-122-10) — provado por gate de grep no `<done>` (0 ocorrências de `->save(`/`->delete(`/`->insert(`/`->forget(`/`::create(`/`updateOrCreate(` fora de comentário, 0 ocorrências de `scoreService`/`compute(`) e por um teste dedicado que confere contagem de rows e `updated_at` antes/depois da execução
- Detecta as 5 inconsistências do `<behavior>`: `SEM_SNAPSHOT` (profissional elegível sem row mensal), `SEM_LINHAS` (row mensal com `empresas_score` não-vazio mas zero linhas por empresa), `LINHAS_ORFAS` (linhas por empresa sem row mensal), `DIVERGENCIA_EMPRESAS_SCORE` (contagem de linhas ≠ `count(breakdown_json['empresas_score'])`) e `ORIGEM_NAO_CONGELADA` (competência fechada com linha de origem diferente de `consolidar_mes`) — cada uma listada com `user_id` e nome, nunca como contagem anônima (D-122-11)
- `--json` imprime um JSON parseável e nada mais (`json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)`); modo padrão imprime uma tabela por profissional + bloco de inconsistências, explicitamente rotulada como "conveniência humana" — o `AVISO` final do próprio comando aponta que o exit code/`--json` é o contrato real
- Exit code é `SUCCESS` só com zero inconsistências, senão `FAILURE` (D-122-12) — testado com `--mes` de competência fechada e de mês em curso (`ORIGEM_NAO_CONGELADA` só se aplica a competência fechada, confirmado por teste dedicado)
- 9 testes novos em `VerificarConsolidacaoTest` — um por comportamento do `<behavior>` da Task 1 mais o teste de não-escrita — todos semeando as duas tabelas direto pelos models (sem rodar `consolidar-mes`), asserindo pelo modo `--json` (`json_decode` + `assertExitCode`), nunca por frase da tabela impressa
- `122-ROLLOUT.md` documenta as 7 seções: pré-condições (árvore compartilhada, flag desligada, GATE MPP-04 reprovado), deploy (migration aditiva + proibição de `cache:clear`), backfill das 3 competências fechadas (2026-06/05/04, mesma rodada do `run_id=03787204-51a7-49fb-8478-da56a5b07e2a` da Fase 121), verificação oficial pelo exit code do `verificar-consolidacao` (explicitamente ignorando a linha final do `consolidar-mes`), ação para `SEM_SNAPSHOT`, evidência SQL da troca de grandeza SNAP-05 sem tocar a Adman, e rollback

## Task Commits

1. **Task 1: Comando `desempenho:verificar-consolidacao`** — `33f3e194` (feat)
2. **Task 2: Suíte do verificador** — `7a581cd3` (test)
3. **Task 3: Runbook de rollout (122-ROLLOUT.md)** — `02fa5f6c` (docs)

## Files Created/Modified

- `app/Console/Commands/VerificarConsolidacaoDesempenho.php` — comando novo, read-only, docblock em pt-BR explicando cada uma das 5 inconsistências e sua ação operacional
- `tests/Feature/Phase122/VerificarConsolidacaoTest.php` — 9 testes: os 7 comportamentos do `<behavior>` (competência consistente, as 5 inconsistências, mês em curso não acionando `ORIGEM_NAO_CONGELADA`, `--json` parseável) + teste de não-escrita
- `.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-ROLLOUT.md` — runbook novo, 7 seções

## Decisions Made

- **D-122-10/D-122-11/D-122-12 aplicadas literalmente** — sem ambiguidade a resolver durante a execução
- **SEM_LINHAS condicionado a `breakdown_json.empresas_score` não-vazio** (não implementado no PLAN.md, decisão de implementação dentro da Task 1): uma row mensal legada (anterior ao shadow da Fase 120, sem a chave `empresas_score` no `breakdown_json`) e sem linha por empresa é o estado ESPERADO de dado histórico, não uma inconsistência — flagar isso geraria ruído em toda competência anterior a 2026-07 sem sinalizar nada acionável. A `DIVERGENCIA_EMPRESAS_SCORE` continua cobrindo o caso geral de contagens diferentes quando ambas são maiores que zero, ou quando a tabela tem linhas mas o breakdown não tem nenhuma (sinal real de inconsistência).
- **Universo de user_id examinado é elegíveis ∪ quem tem qualquer dado gravado na competência** (não implementado explicitamente no PLAN.md) — necessário para `LINHAS_ORFAS` capturar um profissional que perdeu a elegibilidade (mudou de cargo/setor) depois de ter linhas gravadas; restringir ao filtro de elegíveis atual esconderia esse caso.

## Deviations from Plan

Nenhuma nos arquivos de produção. As duas decisões de implementação acima preenchem lacunas que o `<behavior>` da Task 1 deixava em aberto (o texto do plano não especifica o que fazer com dado histórico sem shadow, nem o universo exato de users a examinar) — tratadas como parte natural da Task 1, não como Rule 1/2/3/4.

## Issues Encountered

**Verificação nº 3 do plano (`desempenho:verificar-consolidacao --mes=2026-06 --json` local sem exceção) não pôde ser feita direto contra o `.env` do projeto** porque `DB_CONNECTION=mysql` aponta para o MariaDB local, que está PARADO nesta sessão (confirmado: `tasklist | grep mysqld` sem saída — mesma armadilha de ambiente já registrada na memória do projeto). Contornado criando um SQLite temporário no scratchpad da sessão, migrando (`DB_CONNECTION=sqlite DB_DATABASE=<arquivo temporário> php artisan migrate --force`) e rodando o comando contra essa base vazia — Laravel/dotenv não sobrescreve variável de ambiente já setada no shell, então bastou exportar `DB_CONNECTION`/`DB_DATABASE` na chamada. Resultado: `{"usuarios": [], "inconsistencias": [], "resumo": {"total_usuarios": 0, "total_inconsistencias": 0}}`, exit 0, sem exceção, tanto em `--json` quanto no modo tabela — satisfaz o critério do plano ("base local pode estar vazia — o que importa é não estourar"). O arquivo SQLite temporário foi apagado ao final; nenhum artefato deixado no repositório.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. O comando é executável assim que o deploy da Fase 122 (Plano 06) subir a migration da tabela `desempenho_company_score_snapshots`.

## Next Phase Readiness

- `desempenho:verificar-consolidacao` está pronto para uso operacional assim que a Fase 122 for deployada — Plano 06 pode seguir o runbook `122-ROLLOUT.md` literalmente
- O runbook não executou nada em produção — todas as 7 seções são instrução, não ação; a seção 6 (evidência SQL SNAP-05) tem uma tabela vazia "a preencher na execução do Plano 06"
- Pendência que este plano NÃO resolve (fora de escopo, decisão própria confirmada pela armadilha de ambiente pré-existente): MariaDB local segue parado nesta sessão — qualquer execução manual futura do comando localmente (fora de teste) precisa do mesmo contorno via SQLite temporário, ou do MariaDB local restaurado
- Baseline de testes intacta: `--filter=Phase122` 48/48 verde (39 pré-existentes + 9 novos), `--filter=Desempenho` 14 failed/101 passed (idêntico à baseline), `--filter=Phase110` 2 failed/3 passed (falhas pré-existentes confirmadas, não mexidas), `--filter=Phase120` 18/18 verde

## Self-Check: PASSED

- FOUND: app/Console/Commands/VerificarConsolidacaoDesempenho.php
- FOUND: tests/Feature/Phase122/VerificarConsolidacaoTest.php
- FOUND: .planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-ROLLOUT.md
- FOUND commit 33f3e194 (Task 1)
- FOUND commit 7a581cd3 (Task 2)
- FOUND commit 02fa5f6c (Task 3)

---
*Phase: 122-persist-ncia-por-empresa-e-comandos-v21-0*
*Completed: 2026-08-03*
