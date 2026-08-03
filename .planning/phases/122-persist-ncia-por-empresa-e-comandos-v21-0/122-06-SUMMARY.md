---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plan: 06
subsystem: operacional
tags: [rollout, producao, desempenho, snapshot, mariadb]

requires:
  - phase: 122 (Plano 05)
    provides: "desempenho:verificar-consolidacao + runbook 122-ROLLOUT.md"
provides:
  - "Fase 122 em produção: tabela por empresa criada, 286 linhas de 2026-06 gravadas por consolidar_mes, verificação com exit 0"
  - "Medição da cobertura em pp: 0 de 11 profissionais abaixo de 0,7 (cenário de flag ligada)"
  - "Dois defeitos achados e corrigidos pelo próprio rollout (migration MariaDB 1059; falso positivo SEM_SNAPSHOT)"
affects: [123]

tech-stack:
  added: []
  patterns:
    - "Índice multi-coluna em tabela de nome longo precisa de nome EXPLÍCITO — MariaDB recusa identificador acima de 64 chars (1059) e o SQLite dos testes não pega"
    - "Verificador read-only deriva elegibilidade real do banco (company_users), nunca de compute() — chamar o serviço de nota bateria na Adman"

key-files:
  created: []
  modified:
    - database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php
    - app/Console/Commands/VerificarConsolidacaoDesempenho.php
    - tests/Feature/Phase122/VerificarConsolidacaoTest.php
    - .planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-ROLLOUT.md

requirements: [SNAP-06]
---

# Plano 06 — Rollout em produção e evidência

Checkpoint humano da fase. Deploy autorizado explicitamente pelo usuário em 2026-08-03; execução conduzida pelo orquestrador com a evidência coletada por reconsulta ao banco.

## Resultado

`desempenho:verificar-consolidacao --mes=2026-06` → **exit code 0**, 11 profissionais com snapshot, 286 linhas por empresa, todas `origem=consolidar_mes`. Flag `false` confirmada em produção. Notas e faixas de junho **idênticas** às de antes do rollout.

Evidência completa (tabelas por competência, notas, cobertura pp) em `122-ROLLOUT.md`, seção "Evidência da execução".

## Decisão de escopo do usuário

O runbook previa reconsolidar 2026-06, 2026-05 e 2026-04. A conferência prévia mostrou que **maio e abril nunca tiveram snapshot mensal** — rodar o comando lá criaria 22 registros de bônus inexistentes, calculados com regras que não valiam à época (mediana no faturamento entrou em 31/07) e com leituras de margem que sabidamente oscilam. Levado ao usuário, que decidiu: **só junho**.

## Dois defeitos que só o rollout revelaria

**1. Migration quebrou no MariaDB (erro 1059).** Nome auto-gerado do índice único com 75 caracteres, acima do limite de 64. Verde no SQLite dos testes, quebrado no deploy — e pior que uma falha limpa: a tabela ficou criada **sem o índice único** e a migration marcada `Pending`. Entre a falha e o conserto, o `warm-cache` (a cada 8 min) gravou 314 linhas nela; conferi antes de agir e havia **zero duplicatas** — a busca-e-atualiza do writer se sustentou mesmo sem o índice que deveria garanti-la. Tabela órfã dropada e recriada pela migration corrigida, o que também serviu de prova do fix contra o MariaDB real.

**2. O verificador reprovaria para sempre.** `SEM_SNAPSHOT` acusava quem é elegível pelo cargo mas tem carteira vazia — o Jhonathan (user 25, 0 empresas), que o `consolidar-mes` pula de propósito e contabiliza como "Sem carteira: 1". O exit code ficaria em 1 permanentemente, inutilizando como gate exatamente o comando criado para ser gate. Corrigido em D-122-12: checagem por `company_users` (read-only, sem tocar `compute()`/Adman), com `tem_carteira` exposto na saída para a ausência ficar auditável.

## Erro de método do próprio orquestrador, registrado

Na primeira verificação capturei `EXIT=$?` **depois de um pipe** (`... | tail -20`), lendo o exit code do `tail` e não do comando — que reportou 0 quando o real era 1. É a mesma classe de erro que esta fase inteira existe para evitar: confiar na saída em vez do contrato. Refeito sem pipe, o exit real apareceu e levou ao defeito nº 2.

## Verificação

- `desempenho:verificar-consolidacao --mes=2026-06` exit **0** (capturado sem pipe)
- 11 snapshots mensais e 286 linhas por empresa, conferidos por consulta ao banco
- 100% das linhas com `origem=consolidar_mes` — prova da trava de congelamento (D-122-02) contra o `warm-cache` de 8 em 8 minutos
- Cobertura pp: **0 de 11** abaixo de 0,7 — o risco que motivou D-122-04/05 não se concretiza
- `config('metrics.performance_company_first_score')` = `false`
- `--filter=Phase122` 49/49 verde

## Self-Check: PASSED

- [x] Deploy autorizado e executado
- [x] Competência reconsolidada no escopo decidido pelo usuário
- [x] Verificação por exit code e reconsulta ao banco, nunca por stdout
- [x] Evidência registrada em 122-ROLLOUT.md com os números exatos
- [x] Flag permanece `false`
