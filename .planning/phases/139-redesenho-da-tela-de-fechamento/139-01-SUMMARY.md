---
phase: 139-redesenho-da-tela-de-fechamento
plan: 01
subsystem: api
tags: [laravel, eloquent, fechamento, faixas-de-faturamento, comparativo-mensal]

# Dependency graph
requires:
  - phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
    provides: FechamentoFaixaResolver, FechamentoRollupService, FechamentoSnapshot, fechamento_snapshots
  - phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
    provides: FechamentoGrupoSnapshot, fechamento_grupo_snapshots, precedência de tabela grupo→empresa→serviço
provides:
  - "FechamentoComparativoService — leitura sem N+1 do fechamento congelado do mês anterior (empresa e grupo)"
  - "faixa_ordem_anterior/valor_faixa_anterior/subiu_de_faixa/ganho_faixa em toda linha de companies (ao vivo e congelado, empresa e grupo)"
affects: [139-02, 139-03, 139-04, 139-05, 139-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Leitura em massa antes do laço (1 query) no lugar de query dentro de foreach — mesmo padrão de FechamentoRollupService"
    - "Derivação centralizada (fechamentoDerivarUpgrade) para chaves calculadas repetidas em múltiplos array literais — evita divergência entre caminhos"

key-files:
  created:
    - app/Services/Fechamento/FechamentoComparativoService.php
    - tests/Feature/Phase139/Phase139ComparativoFaixaTest.php
  modified:
    - app/Http/Controllers/AdminController.php

key-decisions:
  - "Ramo AO VIVO mantém fallback (rollup do mês anterior classificado na mesma tabela) só quando não há snapshot; ramo CONGELADO nunca recalcula (D-11 da Fase 137)"
  - "subiu_de_faixa/ganho_faixa centralizados em fechamentoDerivarUpgrade() privado, chamado nos 5 literais, para nunca divergir entre os caminhos"
  - "Consulta de FechamentoGrupoSnapshot que rodava dentro do foreach de grupos virou leitura única antes do laço (elimina N+1 identificado no plano)"
  - "ganho_faixa nunca é 0 no lugar de null — zero significa 'subiu e não mudou de preço', null significa 'não sabemos'"

patterns-established:
  - "FechamentoComparativoService: serviço puro de leitura do mês anterior, sem estado, reusável por qualquer consumidor futuro que precise comparar competências"

requirements-completed: [D-04]

# Metrics
duration: ~35min
completed: 2026-09-04
---

# Phase 139 Plan 01: Comparativo de faixa (mês anterior) Summary

**`FechamentoComparativoService` novo lê o fechamento congelado do mês anterior sem N+1, e as quatro chaves `faixa_ordem_anterior`/`valor_faixa_anterior`/`subiu_de_faixa`/`ganho_faixa` passam a existir nos cinco array literais de linha de `AdminController::fechamento()` (empresa ao vivo, empresa congelada — dois literais, grupo ao vivo, grupo congelado).**

## Performance

- **Duration:** ~35 min (exploração do controller + implementação + testes)
- **Completed:** 2026-09-04T13:31:59Z
- **Tasks:** 3/3
- **Files modified:** 3 (1 criado — serviço, 1 criado — teste, 1 modificado — controller)

## Accomplishments
- `FechamentoComparativoService::anterioresPorEmpresa()`/`anterioresPorGrupo()` — 1 consulta cada, nunca uma por empresa/grupo, filtrando por `origem = consolidar_mes` (mesma trava de "competência fechada" usada no resto do controller).
- As quatro chaves do widget "Subiram de faixa" chegam nos **cinco** array literais confirmados pelo plan-checker (empresa ao vivo linha ~343, empresa congelada snapshot-ausente ~420 e snapshot-presente ~450, grupo ao vivo ~628, grupo congelado ~741) — nenhum morreu no último trecho.
- N+1 eliminado: a consulta de `FechamentoGrupoSnapshot::query()` que rodava dentro do `foreach ($porGrupo` de `fechamentoAgregarGruposAoVivo` agora roda 1 vez antes do laço.
- Trava de teste comparando ao vivo × congelado no MESMO cenário (empresa que sobe de faixa e linha de grupo), exatamente o mecanismo que o plano pediu para pegar um caminho esquecido.

## Task Commits

1. **Tarefa 1: FechamentoComparativoService — leitura do mês anterior sem N+1** - `bd1fca7b` (feat, TDD)
2. **Tarefa 2: Emitir os quatro dados nos quatro caminhos de montagem de linha** - `7b4112cb` (feat)
3. **Tarefa 3: Trava dos quatro caminhos via HTTP** - `53463172` (test, TDD)

**Plan metadata:** (este commit, incluído no fechamento do plano)

## Files Created/Modified
- `app/Services/Fechamento/FechamentoComparativoService.php` — leitura sem N+1 do fechamento congelado do mês anterior, por empresa e por grupo.
- `app/Http/Controllers/AdminController.php` — injeção do serviço no construtor; `fechamentoDerivarUpgrade()` privado novo; as quatro chaves nos cinco literais de linha; N+1 de grupo eliminado.
- `tests/Feature/Phase139/Phase139ComparativoFaixaTest.php` — 12 testes (7 unitários do serviço + 5 via HTTP cobrindo os quatro caminhos).

## Decisions Made
- Reaproveitar a variável `$ordemAnterior` já existente na empresa ao vivo (usada para `evolucao`) em vez de duplicar a lógica — trocando sua fonte primária pela leitura do `FechamentoComparativoService` (que filtra por `origem = consolidar_mes`, diferente da consulta antiga que não filtrava).
- Extrair `fechamentoDerivarUpgrade()` como método privado central para as duas chaves DERIVADAS (`subiu_de_faixa`/`ganho_faixa`), reduzindo o risco de divergência entre os cinco pontos de montagem — a origem dos dois dados PRIMÁRIOS (`faixa_ordem_anterior`/`valor_faixa_anterior`) continua explícita em cada um dos cinco literais, conforme o plano exigiu.

## Deviations from Plan

None — plano executado exatamente como escrito. A extração de `fechamentoDerivarUpgrade()` não é uma mudança de escopo: o plano já descrevia a MESMA regra de derivação repetida nos cinco literais ("mesma regra nos quatro"); centralizá-la é uma decisão de implementação que reduz risco, não altera comportamento nem omite nenhuma das quatro chaves em nenhum dos cinco literais.

## Issues Encountered
None.

## User Setup Required
None — nenhuma configuração externa. Não houve deploy (fora do escopo deste plano e das travas da fase).

## Next Phase Readiness
- `FechamentoComparativoService` e as quatro chaves estão prontos para o plano 139-02 consumir na prop `totais` (total a receber, variação, números dos upgrades).
- Nenhum arquivo JSX foi tocado neste plano — `Financeiro.jsx` continua no estado da Fase 138, aguardando os planos 02-06.
- Gate `Phase122|Phase136|Phase137|Phase138|Phase139`: **288 testes / 1528 asserções / 0 falhas** (era 276/1452 antes deste plano).

---
*Phase: 139-redesenho-da-tela-de-fechamento*
*Completed: 2026-09-04*

## Self-Check: PASSED

Todos os arquivos criados/modificados e os 3 commits de task foram confirmados por
reconsulta (`git log --oneline --all` + verificação de existência de arquivo).
