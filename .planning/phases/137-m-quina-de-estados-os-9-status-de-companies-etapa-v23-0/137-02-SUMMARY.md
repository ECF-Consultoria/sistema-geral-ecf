---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 02
subsystem: database
tags: [laravel, eloquent, migration, mariadb, companies, state-machine]

# Dependency graph
requires:
  - phase: 137-01
    provides: "Ambiente Inertia destravado (npm build) + baseline de testes verde registrada antes de qualquer migration desta fase"
provides:
  - "Coluna `companies.etapa` (string 40, nullable, sem default, indexada) em MariaDB local"
  - "9 constantes `Company::ETAPA_*` + `Company::ETAPAS` (ordem canônica do §10)"
  - "Docblocks de desambiguação `status` × `etapa` no model `Company`"
  - "`etapa` em `$fillable`, fora do `logOnly` do activitylog"
  - "tests/Unit/Phase137/CompanyEtapaConstantesTest.php — 6 testes provando valores/ordem/schema/fillable"
affects: [137-03, 137-04, 137-05, 137-06, 137-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration puramente aditiva (Schema::table + coluna nullable sem default + índice), down() simétrico derrubando só a coluna nova — precedente para as próximas migrations desta fase"
    - "Constantes de domínio public const + array agregador (ETAPAS) referenciando as constantes, não repetindo strings"

key-files:
  created:
    - database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php
    - tests/Unit/Phase137/CompanyEtapaConstantesTest.php
  modified:
    - app/Models/Company.php

key-decisions:
  - "Migration só cria a coluna companies.etapa (nullable, sem default) + índice — nenhum backfill de dado dentro da migration (backfill é comando Artisan do plano 137-05, D-04/D-05)"
  - "etapa entra em \$fillable mas fica FORA do logOnly do activitylog — histórico de transição vive em tabela dedicada do plano 137-03 (D-15/D-16), não no log automático"
  - "Valor da etapa 9 é deliberadamente 'em_operacao', igual à chave que CompanyController já expõe hoje — Fase 142 troca a fonte sem trocar o nome"

patterns-established:
  - "Constantes de domínio ETAPA_* + array ETAPAS: fonte única para as Fases 138-143, sem string literal duplicada em nenhum consumidor futuro"

requirements-completed: [ETAPA-01]

# Metrics
duration: ~25min
completed: 2026-09-01
---

# Phase 137 Plan 02: Migration + vocabulário `companies.etapa` Summary

**Coluna `companies.etapa` aditiva (nullable, sem default, indexada) em MariaDB local + 9 constantes `Company::ETAPA_*`/`ETAPAS` na ordem do §10, com desambiguação `status`×`etapa` documentada no model e provada por teste.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 2/2 completos
- **Files modified:** 3 (1 migration nova, 1 teste novo, 1 model modificado)

## Accomplishments

- `companies.etapa` existe em MariaDB local: `varchar(40)`, `Null=YES`, `Default=NULL`, índice `companies_etapa_index` — confirmado por reconsulta direta ao banco (`SHOW COLUMNS`/`SHOW INDEX`), nunca por stdout do `migrate`.
- `status` permanece **exatamente** como estava (`varchar(255)`, default `'ativo'`) — confirmado antes e depois da migration, e depois do ciclo `rollback` → `migrate` completo.
- Os 9 valores e a ordem canônica do §10 vivem em `Company::ETAPA_*` + `Company::ETAPAS`, provados por 6 testes unitários.
- Docblocks de desambiguação `status` × `etapa` (D-02) escritos no model, com o dado medido (128 `ativo` / 52 `pendente`) e a origem do `pendente` (`ComercialController.php:594`).
- `etapa` está em `$fillable` (necessário para o serviço de transição do plano 137-03 gravar via Eloquent) mas **fora** do `logOnly` do activitylog — decisão travada por D-15/D-16.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Migration aditiva de `companies.etapa`** - `8fbc3589` (feat)
2. **Task 2: Vocabulário dos 9 status e desambiguação `status`×`etapa`** - `d9f1472d` (feat)

_Este plano não teve tasks TDD — cada arquivo de teste nasceu junto com a implementação que prova (`tdd_mode` desligado nesta fase, conforme `137-VALIDATION.md`)._

## Files Created/Modified

- `database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php` — cria `companies.etapa` (string 40, nullable, sem default, `after('status')`) + índice `companies_etapa_index`; `down()` derruba só a coluna.
- `app/Models/Company.php` — 9 constantes `ETAPA_*` + `ETAPAS`; docblocks de desambiguação `status`×`etapa`; `'etapa'` adicionado a `$fillable` com comentário sobre o ponto único de escrita autorizado (Fase 137-03).
- `tests/Unit/Phase137/CompanyEtapaConstantesTest.php` (novo) — 6 testes: contagem de 9 elementos, ordem posicional completa, valor de `ETAPA_EM_OPERACAO`, existência da coluna no schema, `etapa` nula por padrão numa `Company` recém-criada, `etapa` presente em `$fillable`.

## Decisions Made

Nenhuma decisão nova além das já travadas no `137-CONTEXT.md` (D-01, D-02, D-03, D-07). Este plano só **implementou** o que já estava decidido — nenhuma revisão de escopo foi necessária.

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered

None. O ciclo `migrate` → `rollback --step=1` → `migrate` foi executado e verificado por reconsulta direta ao MariaDB local em cada etapa (schema de `etapa` e de `status` conferidos nos três momentos), conforme exigido pelo `137-CONTEXT.md` e pela disciplina de `.planning/learnings/desempenho-bonificacao.md` §4/§6.

O `php artisan migrate` também aplicou duas migrations pendentes não relacionadas a este plano (`2026_08_31_170000_add_tipo_to_desempenho_metricas_manuais` e `2026_09_01_100000_add_contrato_junto_com_servico_id_to_servicos_table`), ambas já commitadas por trabalho anterior nesta árvore compartilhada — comportamento esperado de `artisan migrate` (roda todo o pendente), não uma ação deste plano.

## Verificação de regressão (fim de wave)

- `Quick run command` (`tests/Unit/Phase137`): **OK (6 tests, 8 assertions)**. `tests/Feature/Phase137` ainda não existe (nasce nos planos seguintes) — comportamento esperado.
- `Baseline command` (`Phase37CompaniesPerformanceFilterTest` + `CompanyControllerResponsavelPerformanceTest`): **OK (24 tests, 44 assertions)** — número idêntico à baseline registrada em `137-BASELINE-TESTES.md` antes da migration. A coluna nova não moveu a tela de `/companies`.
- Reconsulta final ao banco: `companies.etapa` nullable/sem default/indexada confirmada; 180 empresas na base local, 0 com `etapa` preenchida (esperado — backfill é o plano 137-05).

## Next Phase Readiness

Fundação pronta para os planos 137-03 (serviço único de transição + histórico), 137-04 (pendência paralela) e 137-05 (backfill): a coluna existe, o vocabulário de 9 valores está travado por constantes e provado por teste, e o ponto de escrita único já está documentado no `$fillable` como superfície a fechar pelo plano 137-06.

Nenhum bloqueio. Timestamps `120000`/`130000` seguem livres para as migrations dos planos 137-03/137-04, conforme reservado por este plano.

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

Todos os arquivos criados/modificados confirmados no disco (`database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php`, `tests/Unit/Phase137/CompanyEtapaConstantesTest.php`, `app/Models/Company.php`) e todos os commits confirmados em `git log` (`8fbc3589`, `d9f1472d`, `6bb67ef6`).
