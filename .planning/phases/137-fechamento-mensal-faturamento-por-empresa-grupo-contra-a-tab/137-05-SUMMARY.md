---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 05
subsystem: database
tags: [laravel, artisan, snapshot, fechamento, cobranca, company-group]

requires:
  - phase: 137-02
    provides: "fechamento_snapshots, fechamento_grupo_snapshots, fechamento_reconsolidacoes (schema + models)"
  - phase: 137-03
    provides: "FechamentoFaixaResolver::paraEmpresa()/classificar() e FechamentoRollupService::janela()/porEmpresa()"
provides:
  - "FechamentoSnapshotWriter — único ponto de escrita dos snapshots, upsert+prune em transação, trava de congelamento que exige --motivo= para reconsolidar"
  - "Comando fechamento:consolidar-mes — fecha a competência por empresa e por grupo do Comercial (CompanyGroup), com faixa da soma para o grupo e gate de cobertura mínima de faturamento"
  - "Comando read-only fechamento:verificar-consolidacao --json — 5 classes de inconsistência, veredito só pelo exit code"
affects: [137-06, 137-07, 137-08, 137-09, 137-10]

tech-stack:
  added: []
  patterns:
    - "Writer idempotente upsert+prune em DB::transaction, com lockForUpdate() na checagem de congelamento (molde de CompanyScoreSnapshotWriter, adaptado)"
    - "Reconsolidação exige motivo explícito e grava payload anterior em tabela de auditoria ANTES da sobrescrita — divergência deliberada do molde do Desempenho"
    - "Comando read-only com --json + exit code como único contrato de verificação (nunca stdout)"

key-files:
  created:
    - app/Services/Fechamento/FechamentoSnapshotWriter.php
    - app/Console/Commands/ConsolidarMesFechamento.php
    - app/Console/Commands/VerificarConsolidacaoFechamento.php
    - tests/Feature/Phase137/Phase137ConsolidarMesTest.php
    - tests/Feature/Phase137/Phase137VerificarConsolidacaoTest.php
  modified:
    - .planning/STATE.md

key-decisions:
  - "Reconsolidar competência já fechada exige --motivo= explícito — sem ele, o writer lança RuntimeException e nada muda (D-12 revisado, divergência deliberada do molde do Desempenho, que ignora a trava em silêncio)"
  - "Grupo é sempre CompanyGroup (company_group_id); parent_company_id nunca entra em nenhuma agregação do comando (D-08/D-09)"
  - "Faturamento e faixa do grupo são a soma das linhas de empresa JÁ calculadas — nunca uma segunda query — e a tabela da empresa-âncora (maior faturamento) classifica a soma (D-10)"
  - "Estados sem_integracao/sem_faturamento/sem_tabela nunca gravam valor_faixa=0 — ausência de dado é estado visível, nunca aproximado"
  - "Rollup do mês anterior é computado UMA vez para todas as empresas (não por empresa) para evitar N+1 no cálculo de evolução"

requirements-completed: [D-08, D-09, D-10, D-11, D-12]

duration: ~90min
completed: 2026-09-03
---

# Phase 137 Plan 05: Writer + comandos de fechamento mensal Summary

**FechamentoSnapshotWriter com trava de congelamento que exige motivo para reconsolidar, comando `fechamento:consolidar-mes` que fecha empresa+grupo do Comercial com faixa da soma, e comando read-only `fechamento:verificar-consolidacao --json` com 5 classes de inconsistência.**

## Performance

- **Duration:** ~90 min
- **Completed:** 2026-09-03
- **Tasks:** 3
- **Files modified:** 5 (3 produção + 2 teste)

## Accomplishments

- `FechamentoSnapshotWriter::sync()` — único ponto de escrita de `fechamento_snapshots` e `fechamento_grupo_snapshots`, upsert+prune em transação, trava de congelamento com `lockForUpdate()` que **recusa** reconsolidar sem `--motivo=` explícito (D-12 revisado) e grava o payload anterior completo (`empresas`+`grupos`) em `fechamento_reconsolidacoes` antes de sobrescrever.
- `fechamento:consolidar-mes` — fecha uma competência por empresa (faturamento ML+Shopee via `FechamentoRollupService`, faixa via `FechamentoFaixaResolver`, evolução contra o mês anterior pela MESMA tabela) e por grupo do Comercial (`CompanyGroup`, soma das empresas-membro, faixa da âncora sobre a soma, `tabelas_divergentes` quando os membros resolvem tabelas diferentes). `parent_company_id` nunca agrega (D-08/D-09). Gate de cobertura mínima de 0,7 recusa persistir amostra degradada. Suporta `--se-ausente` e `--por=`.
- `fechamento:verificar-consolidacao --json` — comando read-only que detecta `SEM_SNAPSHOT`, `LINHAS_ORFAS`, `DIVERGENCIA_SOMA_GRUPO`, `DIVERGENCIA_CONTAGEM` e `ORIGEM_NAO_CONGELADA`, sempre por reconsulta direta às tabelas de snapshot. Exit code 0 só com zero inconsistências.

## Task Commits

Cada tarefa seguiu RED→GREEN (TDD), confirmado por remoção temporária do arquivo de produção antes de cada commit de teste:

1. **Tarefa 1: FechamentoSnapshotWriter**
   - `5604127c` test(137-05): adiciona teste falho do FechamentoSnapshotWriter (RED)
   - `8af5cf98` feat(137-05): implementa FechamentoSnapshotWriter (GREEN)
2. **Tarefa 2: Comando fechamento:consolidar-mes**
   - `8b8be181` test(137-05): adiciona teste falho do comando fechamento:consolidar-mes (RED)
   - `4169a41f` feat(137-05): implementa comando fechamento:consolidar-mes (GREEN)
3. **Tarefa 3: Comando read-only fechamento:verificar-consolidacao**
   - `353cf6ec` test(137-05): adiciona teste falho do comando fechamento:verificar-consolidacao (RED)
   - `defae9af` feat(137-05): implementa comando read-only fechamento:verificar-consolidacao (GREEN)

**Plan metadata:** (este commit — docs: complete plan)

## Files Created/Modified

- `app/Services/Fechamento/FechamentoSnapshotWriter.php` — writer único das duas tabelas de snapshot, com trava de congelamento e auditoria de reconsolidação.
- `app/Console/Commands/ConsolidarMesFechamento.php` — fecha a competência (empresa + grupo), gate de cobertura, exit code reflete falha real por empresa.
- `app/Console/Commands/VerificarConsolidacaoFechamento.php` — conferência read-only, `--json`, exit code é o veredito.
- `tests/Feature/Phase137/Phase137ConsolidarMesTest.php` — 16 testes (writer + comando).
- `tests/Feature/Phase137/Phase137VerificarConsolidacaoTest.php` — 9 testes (5 classes de inconsistência + read-only + json parseável).
- `.planning/STATE.md` — bloco "Posição paralela — Fase 137" atualizado (edição aditiva, sem tocar no `## Current Position` de outra sessão).

## Decisions Made

- Trava de congelamento SEMPRE checada contra `FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES`, independente do `$origem` recebido em `sync()` — única origem que existe nesta fase, mas deixa a API pronta para uma futura origem provisória sem reabrir a trava.
- `--se-ausente` é verificado ANTES de qualquer cálculo pesado (early-return), não no Passo 7 como o texto do plano descrevia literalmente — evita computar rollup/faixa/grupo para todas as empresas só para descartar o resultado quando a competência já está fechada. Comportamento observável é idêntico ao especificado.
- Evolução do mês anterior usa o rollup já computado UMA vez para toda a base (não uma query por empresa) — reforça o mitigation do threat T-137-19 (DoS por N+1), que o plano já registrava como requisito.
- Estado do grupo segue a mesma precedência de estado da empresa-âncora (`sem_faturamento`/`sem_tabela`/`ok`), porque é a tabela dela que classifica a soma.

## Deviations from Plan

None — plano executado como escrito. As únicas adaptações foram de eficiência de implementação (checagem antecipada de `--se-ausente`, rollup do mês anterior computado uma única vez), documentadas acima como decisões, sem mudar nenhum comportamento observável especificado no `<behavior>` das tarefas.

## Issues Encountered

Durante a Tarefa 1, os comentários explicativos do writer citavam literalmente `updateOrCreate` e `createFromFormat('Y-m',` como exemplos do que NUNCA fazer — o que fazia os greps de aceitação (`grep -c "updateOrCreate"` / `grep -c "createFromFormat('Y-m',"`) contarem essas ocorrências em comentário como se fossem uso real. Reescrito para descrever o padrão proibido sem usar o literal exato, preservando a intenção didática do comentário. Resolvido antes do commit — não afetou nenhum teste.

## User Setup Required

None — nenhuma configuração de serviço externo.

## Next Phase Readiness

- `fechamento:consolidar-mes` e `fechamento:verificar-consolidacao` estão prontos para uso operacional via CLI (produção fora do escopo deste subagente — nenhum deploy/execução em VPS foi feita).
- Wave 3 (este plano) está completa e sozinha — não há outro plano paralelo pendente nesta wave.
- Próxima wave depende de UI/relatório (137-06 em diante) para expor esses dados na tela administrativa — o writer e os comandos já produzem o dado congelado com `valor_faixa_e_piso` gravado, que é o requisito explícito da wave 5 (PDF/email) segundo o prompt de execução.
- Nenhum bloqueio conhecido para a próxima wave.

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-03*

## Self-Check: PASSED

Todos os 6 arquivos criados/modificados confirmados presentes no disco; todos os 6 hashes de
commit (5604127c, 8af5cf98, 8b8be181, 4169a41f, 353cf6ec, defae9af) confirmados em `git log`.
