---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 02
subsystem: database
tags: [laravel, eloquent, migrations, mariadb, sqlite, snapshot, auditoria]

requires:
  - phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
    provides: molde de snapshot congelado por competência (desempenho_company_score_snapshots)

provides:
  - "Tabela fechamento_snapshots — congelamento por (company_id, mes_referencia)"
  - "Tabela fechamento_grupo_snapshots — congelamento por (company_group_id, mes_referencia)"
  - "Tabela fechamento_reconsolidacoes — auditoria de reconsolidação (D-12): motivo NOT NULL, snapshot_anterior em JSON"
  - "Models FechamentoSnapshot, FechamentoGrupoSnapshot, FechamentoReconsolidacao"

affects: [137-05, fechamento-writer, fechamento-tela-admin]

tech-stack:
  added: []
  patterns:
    - "Nome de tabela curto de propósito quando o unique composto estouraria 64 caracteres no MariaDB"
    - "FK opcional: ->nullable() sempre ANTES de ->constrained()->nullOnDelete() na mesma cadeia"
    - "Colunas de lista fixa como string(), nunca enum() — CHECK do SQLite dos testes quebra ao surgir valor novo"
    - "$table explícito quando o nome da classe é português e o pluralizador do Eloquent erraria (Reconsolidacao -> Reconsolidacaos)"

key-files:
  created:
    - database/migrations/2026_09_02_100004_create_fechamento_snapshots_table.php
    - database/migrations/2026_09_02_100005_create_fechamento_grupo_snapshots_table.php
    - database/migrations/2026_09_02_100006_create_fechamento_reconsolidacoes_table.php
    - app/Models/FechamentoSnapshot.php
    - app/Models/FechamentoGrupoSnapshot.php
    - app/Models/FechamentoReconsolidacao.php
    - tests/Feature/Phase137/Phase137SnapshotSchemaTest.php
  modified: []

key-decisions:
  - "fechamento_reconsolidacoes grava dado de BANCO (não só Log) quando alguém reconsolida uma competência — divergência deliberada do molde do Desempenho, porque o valor aqui entra em cobrança (D-12)"
  - "valor_faixa_e_piso carrega a distinção 'a partir de R$ X' vs. valor fechado — sem ela o congelado prometeria preço fechado onde o contrato prevê piso (última faixa de Gestão/Brigada)"
  - "FechamentoReconsolidacao precisou de \$table explícito: o pluralizador do Eloquent não conhece pt-BR e gerava 'fechamento_reconsolidacaos'"

patterns-established:
  - "Snapshot de fechamento segue o mesmo molde do snapshot de desempenho (Fase 122), mas com auditoria de reconsolidação em tabela própria"

requirements-completed: [D-11, D-12]

duration: ~35min
completed: 2026-09-02
---

# Phase 137 Plan 02: Snapshots de Fechamento + Auditoria de Reconsolidação Summary

**Três tabelas novas (`fechamento_snapshots`, `fechamento_grupo_snapshots`, `fechamento_reconsolidacoes`) e seus models — o lugar onde o fechamento mensal congela por empresa e por grupo, com trilha de auditoria obrigatória para refazer.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 3/3
- **Files modified:** 7 (todos criados, nenhum modificado)

## Accomplishments
- `fechamento_snapshots`: congelamento por `(company_id, mes_referencia)`, com faixa aplicada, cobrança mensal, evolução (subiu/desceu/manteve) e a marcação `valor_faixa_e_piso` para a última faixa de Gestão/Brigada.
- `fechamento_grupo_snapshots`: mesma forma agregada por `(company_group_id, mes_referencia)`, com `empresas_count`, `empresa_ancora_id` e `tabelas_divergentes` (alimenta o banner âmbar do UI-SPEC).
- `fechamento_reconsolidacoes`: 1 linha por reconsolidação, `motivo` NOT NULL e `snapshot_anterior` em JSON preservando o payload completo (`empresas` + `grupos`) — D-12 revisado.
- Índices nomeados à mão em todas as três tabelas; nenhum ultrapassa 64 caracteres (armadilha do MariaDB documentada na memória do projeto).
- Toda FK opcional (`company_group_id`, `servico_id`, `empresa_ancora_id`, `reconsolidado_por`) segue a ordem `->nullable()->constrained()->nullOnDelete()`.
- Nenhuma coluna de lista fixa virou `enum()`.
- Teste de schema cobre: existência das 3 tabelas com todas as colunas; unique por competência (empresa e grupo) estourando `QueryException`; cascade de exclusão de empresa verificado por reconsulta ao banco (`DB::table(...)->count()`, nunca stdout); round-trip de `snapshot_anterior` como array aninhado; `estado` aceitando valor arbitrário (prova de que não é `enum`).

## Task Commits

Each task was committed atomically:

1. **Tarefa 1: Migrations dos dois snapshots congelados** - `41b8e57d` (feat)
2. **Tarefa 2: Migration da auditoria de reconsolidação (D-12)** - `3a8708aa` (feat)
3. **Tarefa 3: Models dos snapshots e teste de schema** - `e76da9ae` (feat)

_Nenhuma task TDD — plano `type="auto"` puro._

## Files Created/Modified
- `database/migrations/2026_09_02_100004_create_fechamento_snapshots_table.php` - tabela de congelamento por empresa
- `database/migrations/2026_09_02_100005_create_fechamento_grupo_snapshots_table.php` - tabela de congelamento por grupo
- `database/migrations/2026_09_02_100006_create_fechamento_reconsolidacoes_table.php` - auditoria de reconsolidação
- `app/Models/FechamentoSnapshot.php` - model + constantes `ORIGEM_CONSOLIDAR_MES`/`ESTADO_*`
- `app/Models/FechamentoGrupoSnapshot.php` - model, relações `grupo()`/`empresaAncora()`
- `app/Models/FechamentoReconsolidacao.php` - model, `\$table` explícito, relação `autor()`
- `tests/Feature/Phase137/Phase137SnapshotSchemaTest.php` - 6 testes de schema

## Decisions Made
- **`FechamentoReconsolidacao::\$table` explícito.** O pluralizador do Eloquent gerou `fechamento_reconsolidacaos` a partir do nome da classe (não reconhece pt-BR). Descoberto pelo teste falhando com `no such table`; corrigido declarando `protected $table = 'fechamento_reconsolidacoes'`.
- Demais decisões seguiram o plano exatamente como escrito (nomes de tabela/coluna, índices, ordem `nullable()`/`nullOnDelete()` já vinham especificados).

## Deviations from Plan

None - plan executado exatamente como escrito, com uma correção pontual (Rule 1 - bug) documentada acima (nome de tabela do model `FechamentoReconsolidacao`), já embutida na Tarefa 3 e coberta pelo próprio teste de schema.

## Issues Encountered

`tests/Feature/FechamentoMigrationTest.php` (teste pré-existente, não desta fase, checando colunas `service_type`/`contract_start`/`contract_end`/`additional_service` em `companies`) falha independentemente das migrations desta fase — confirmado removendo temporariamente as migrations novas (desta fase e da 137-01) e reproduzindo a mesma falha. Não é regressão deste plano; documentado em `.planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/deferred-items.md`. Fora do filtro de gate da fase (`Phase122|Phase136|Phase137`).

## Next Phase Readiness
- As três tabelas e os três models estão prontos para o writer do plano 05 (`FechamentoSnapshotWriter`), que fará o cálculo e a escrita real (upsert + trava de congelamento + gravação em `fechamento_reconsolidacoes` antes de sobrescrever).
- `FechamentoRecebido` permanece intocada, como exigido.
- Nenhum bloqueio conhecido para os planos seguintes da fase.

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-02*

## Self-Check: PASSED

Todos os 7 arquivos criados confirmados no disco; os 3 commits de task (`41b8e57d`, `3a8708aa`, `e76da9ae`) confirmados em `git log`.
