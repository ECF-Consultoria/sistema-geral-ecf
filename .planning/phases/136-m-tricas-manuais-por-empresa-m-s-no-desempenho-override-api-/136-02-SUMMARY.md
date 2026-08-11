---
phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-
plan: 02
subsystem: api
tags: [laravel, desempenho, lancamento-manual, activitylog, migration, phpunit]

# Dependency graph
requires: []
provides:
  - "Tabela desempenho_metricas_manuais (company_id, mes_referencia, metrica) com indice unico nomeado a mao"
  - "Model DesempenhoMetricaManual — whitelist canonica de metricas (METRICAS) + LogsActivity + ativasDaCompetencia()"
  - "CompanyScoreSnapshotWriter::competenciaConsolidada() — unico sinal de D-09 (por competencia, sem filtro por usuario)"
  - "StoreMetricaManualRequest — validacao admin-only do lancamento manual"
affects: ["136-03", "136-04", "136-05"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Indice unico nomeado a mao (dmm_company_mes_metrica_unique, 30 chars) para evitar erro 1059 do MariaDB"
    - "Helper estatico competenciaConsolidada() reaproveitando o mesmo sinal de origem='consolidar_mes' da trava de congelamento, mas por competencia (nao por usuario)"

key-files:
  created:
    - database/migrations/2026_08_11_150000_create_desempenho_metricas_manuais_table.php
    - app/Models/DesempenhoMetricaManual.php
    - app/Http/Requests/StoreMetricaManualRequest.php
    - tests/Feature/Phase136/MetricaManualTravaConsolidacaoTest.php
  modified:
    - app/Services/Desempenho/CompanyScoreSnapshotWriter.php

key-decisions:
  - "competenciaConsolidada() NAO filtra por user_id — a fronteira de D-09 e a competencia inteira, ao contrario da trava de congelamento do sync() (por usuario). Nenhuma flag paralela foi criada."
  - "valor e nullable no FormRequest porque a reversao para auto (D-02) submete ativo=false sem valor; a obrigatoriedade condicional (ativo=true exige valor) vive em withValidator()"
  - "Empresa inativa (active=false) e recusada no lancamento (T-136-06) via regra composta, nao via filtro de carteira — a ferramenta e admin-global por desenho"

requirements-completed: ["D-01", "D-02", "D-07", "D-09", "D-12"]

# Metrics
duration: 25min
completed: 2026-08-11
---

# Phase 136 Plan 02: Fundação de dados do lançamento manual (tabela, model, trava de consolidação, FormRequest) Summary

**Cria a tabela `desempenho_metricas_manuais` chaveada por (empresa, mês, métrica), o model com trilha de auditoria via `spatie/laravel-activitylog`, o helper `competenciaConsolidada()` que reaproveita o mesmo sinal da trava de congelamento (por competência, não por usuário) e o `StoreMetricaManualRequest` que valida o lançamento — sem controller, sem rota, sem tela.**

## Performance

- **Duration:** ~25 min (17:00 → 17:19 BRT, 2026-08-11)
- **Tasks:** 2/2
- **Files modified:** 4 criados, 1 modificado

## Accomplishments

- `desempenho_metricas_manuais` criada com índice único nomeado à mão (`dmm_company_mes_metrica_unique`, 30 caracteres — o auto-gerado teria 68, acima do limite de 64 do MariaDB e do erro 1059). Verificada rodando a migration contra o MySQL/MariaDB de desenvolvimento local (não só o SQLite dos testes) — a tabela e o índice existem de fato.
- `DesempenhoMetricaManual` carrega a whitelist canônica `METRICAS` (`faturamento`, `margem_cmv`), trilha de auditoria via `LogsActivity` (`logOnly` + `logOnlyDirty` + descrições pt-BR por evento) e `ativasDaCompetencia()` para o Plano 03 pré-carregar em lote sem N+1.
- `CompanyScoreSnapshotWriter::competenciaConsolidada()` é o único sinal de "competência fechada" que a Fase 136 reconhece — reaproveita `origem='consolidar_mes'` da trava de congelamento existente, mas sem o filtro `user_id` (escopo é a competência inteira, não o profissional).
- `StoreMetricaManualRequest` recusa: não-admin (`authorize()`), métrica fora da whitelist, valor negativo, valor acima do teto de `decimal(16,2)`, competência já consolidada (D-09) e empresa inativa (T-136-06). `valor` nullable com regra composta (obrigatório só quando `ativo=true`) suporta a reversão para `auto` sem redigitar nada (D-02).
- 13 testes novos em `MetricaManualTravaConsolidacaoTest` cobrindo D-09 (3 cenários da trava) e D-12 (trilha de auditoria com `valor_anterior`, `lancado_por`, `lancado_em` e as duas entradas em `activity_log`).

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration e model de desempenho_metricas_manuais, com trilha de auditoria** - `b7b67cd4` (feat)
2. **Task 2: Helper de competência consolidada, FormRequest de lançamento e suíte de trava** - `ea0c2b3c` (feat)

## Files Created/Modified

- `database/migrations/2026_08_11_150000_create_desempenho_metricas_manuais_table.php` - schema com `company_id`/`mes_referencia`/`metrica` string(20)/`valor`/`valor_anterior` nullable/`ativo`/`lancado_por` nullable+nullOnDelete/`lancado_em`, índice único nomeado à mão
- `app/Models/DesempenhoMetricaManual.php` - whitelist `METRICAS`, `LogsActivity`, relações `company()`/`lancadoPor()`, `ativasDaCompetencia()`
- `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` - novo método estático `competenciaConsolidada()`, resto do arquivo intocado
- `app/Http/Requests/StoreMetricaManualRequest.php` - `authorize()`, `rules()`, `withValidator()` (3 regras compostas), `messages()`, `mesReferencia()`
- `tests/Feature/Phase136/MetricaManualTravaConsolidacaoTest.php` - 13 testes (trava de consolidação, authorize, rules, regras compostas, auditoria)

## Decisions Made

- `competenciaConsolidada()` não filtra por `user_id` — fronteira de D-09 é a competência inteira, diferente da trava de congelamento por usuário do `sync()`. Nenhuma flag paralela foi criada; é o mesmo sinal `origem='consolidar_mes'`.
- `valor` é `nullable` no FormRequest; a obrigatoriedade condicional (`ativo=true` exige `valor`) vive em `withValidator()`, não em `rules()` — a reversão para `auto` (D-02) precisa submeter `ativo=false` sem valor.
- Empresa inativa é recusada por regra composta checando `Company::active`, não por filtro de carteira — a ferramenta é admin-global por desenho (T-136-06), decisão registrada no threat model do plano.
- Teste de `competenciaConsolidada()` para "só snapshot_diario/warm_cache" usa duas empresas diferentes (não duas linhas do mesmo `user_id`+`company_id`+`mes_referencia`) porque a chave única de `desempenho_company_score_snapshots` é `(user_id, company_id, mes_referencia)` — `origem` não faz parte dela, então duas origens diferentes para o mesmo trio colidiriam.

## Deviations from Plan

None — plano executado exatamente como escrito, incluindo os detalhes de `<interfaces>` (schema, índice, docblocks).

## Issues Encountered

Durante a verificação comportamental da Task 1 (antes de escrever a suíte automatizada), usei `php artisan tinker` sem `--env=testing` (que não tem efeito real neste projeto — não existe `.env.testing`) para confirmar `create()`/violação de unicidade. Isso rodou contra o banco MySQL/MariaDB de **desenvolvimento real** (`.env`), não um banco isolado, e criou uma empresa, um usuário e um lançamento fictícios ali, com as respectivas entradas em `activity_log`. Identificados pelos IDs retornados na própria sessão (`Company#398`, `User#33`, `DesempenhoMetricaManual#1`, `activity_log#2966/2967`) e removidos por `forceDelete()`/`delete()` na mesma sessão, antes de qualquer commit. Confirmado por reconsulta que a contagem voltou a zero. Nenhum dado real de produção foi tocado (é o `ecf_admin` local, não a VPS) e nada disso foi commitado — mas fica registrado porque a mesma armadilha se repete para qualquer plano futuro desta fase que precise de verificação manual via tinker: **usar sempre `php artisan test` (que lê `phpunit.xml`, SQLite `:memory:`) para qualquer verificação comportamental, nunca tinker sem isolamento explícito.**

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `DesempenhoMetricaManual::METRICAS`/`ativasDaCompetencia()` prontos para o Plano 03 (override do `MetricDiffDispatcher`) consumir sem redigitar a whitelist nem cair em N+1.
- `CompanyScoreSnapshotWriter::competenciaConsolidada()` disponível para qualquer código futuro que precise checar "esta competência já foi consolidada?" — não duplicar a query.
- **Pendência explícita do plano, registrada para antes do deploy:** a migration precisa subir contra o MariaDB de produção (não só o SQLite dos testes) — já verificado localmente contra o MySQL de desenvolvimento nesta sessão, mas a verificação final em produção continua sendo passo obrigatório do deploy, fora do escopo deste plano.
- Nenhum controller, rota ou tela criados neste plano — isso é o Plano 04 (rota/controller) e o Plano 05 (tela).

---
*Phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-*
*Completed: 2026-08-11*
