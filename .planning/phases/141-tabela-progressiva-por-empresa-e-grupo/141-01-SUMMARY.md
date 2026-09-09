---
phase: 141-tabela-progressiva-por-empresa-e-grupo
plan: 01
subsystem: database
tags: [laravel, eloquent, migrations, fechamento, tabela-progressiva]

# Dependency graph
requires:
  - phase: 137
    provides: "FechamentoRollupService::porEmpresa() (D-05, D-06, D-07) e ServicoFaixaFaturamento"
provides:
  - "Coluna servicos.usa_tabela_progressiva — resposta oficial para 'este serviço é cobrado por tabela progressiva?'"
  - "FechamentoRollupService::plataformasElegiveis(Company) — subconjunto de ['ml','shopee'] por contrato ativo com tabela"
  - "FechamentoRollupService::porEmpresa(..., somenteContratadas: bool) — recorte opt-in por plataforma contratada"
  - "Chave plataformas_consideradas em todo retorno de porEmpresa()"
affects: [141-03, 141-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Elegibilidade de plataforma por serviço: OU entre texto livre (plataforma) e enum confiável (setor), nunca uma fonte só decidindo por exclusão"
    - "Recorte opt-in em serviço existente via parâmetro booleano com guard de InvalidArgumentException, sem quebrar assinatura/chamadores atuais"

key-files:
  created:
    - database/migrations/2026_09_09_150001_add_usa_tabela_progressiva_to_servicos_table.php
    - tests/Feature/Phase141/Phase141ServicoTabelaSchemaTest.php
    - tests/Feature/Phase141/Phase141ElegibilidadePlataformaTest.php
  modified:
    - app/Models/Servico.php
    - app/Services/Fechamento/FechamentoRollupService.php

key-decisions:
  - "Default da coluna é false: na dúvida, um serviço NÃO entra na soma de faturamento (segura o dinheiro, nunca infla cobrança)"
  - "Backfill liga true só para servico_id com pelo menos 1 linha em servico_faixas_faturamento (Gestão, Gestão de ADS Shopee, Brigada) — Mentoria continua false"
  - "Critério de plataforma é em OU (não elseif): o mesmo serviço pode habilitar ML e Shopee ao mesmo tempo, porque o campo plataforma é texto livre e pode trazer as duas"
  - "somenteContratadas=false é o default — nenhum chamador atual muda de comportamento; quem liga o recorte é o plano 141-03"

requirements-completed: [TPE-01]

duration: ~35min
completed: 2026-09-09
---

# Fase 141 Plano 01: Elegibilidade de plataforma por tabela progressiva Summary

**Coluna `servicos.usa_tabela_progressiva` (com backfill) + `FechamentoRollupService::plataformasElegiveis()`/`porEmpresa(somenteContratadas:)` para recortar o faturamento só das plataformas com serviço contratado e cobrado por tabela — sem mudar nenhum valor cobrado hoje.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 2/2 completas
- **Files modified:** 4 (1 migration nova, 1 model, 1 service, 2 arquivos de teste novos)

## Accomplishments
- `servicos.usa_tabela_progressiva` existe, nasce `false` por padrão, e o backfill liga `true` exatamente para os serviços que já têm faixa cadastrada (D-02/D-04) — auditável via `activity_log` (T-141-01).
- `FechamentoRollupService::plataformasElegiveis(Company)` resolve, a partir dos contratos ATIVOS da empresa, quais plataformas (`ml`/`shopee`) entram na soma — em OU entre `plataforma` (texto) e `setor` (enum), documentado no mesmo espírito de `FechamentoFaixaResolver::escolherServicoCandidato()`.
- `porEmpresa()` ganhou o parâmetro opt-in `somenteContratadas` (default `false`): ligado, zera para `null` o lado não elegível ANTES de somar o total — nunca soma e depois subtrai.
- Toda resposta de `porEmpresa()` agora traz `plataformas_consideradas` — no modo atual `['ml','shopee']` (o que já era considerado), no modo novo o resultado real de `plataformasElegiveis()`.
- Nenhum chamador do rollup foi tocado: `ConsolidarMesFechamento`, `AdminController::fechamento()` e `EnviarRelatorioFechamentoJob` continuam chamando com 2 argumentos — confirmado por `grep -rn "porEmpresa(" app/`.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Coluna `usa_tabela_progressiva` em `servicos`** - `2d9a2f07` (feat)
2. **Tarefa 2: Rollup recorta o faturamento pelas plataformas contratadas** - `3ef58eba` (feat)

_Nenhuma tarefa era TDD com ciclo RED/GREEN separado — os testes foram escritos e verificados junto de cada tarefa `auto` marcada `tdd="true"`, num commit único por tarefa (mesmo padrão de commits anteriores da Fase 137/141 no repositório)._

## Files Created/Modified
- `database/migrations/2026_09_09_150001_add_usa_tabela_progressiva_to_servicos_table.php` - Coluna booleana idempotente + backfill a partir de `servico_faixas_faturamento`
- `app/Models/Servico.php` - `usa_tabela_progressiva` em `$fillable`/`$casts`, com comentário documentando que é a resposta OFICIAL (D-02/D-04)
- `app/Services/Fechamento/FechamentoRollupService.php` - `plataformasElegiveis()` novo + `porEmpresa(..., somenteContratadas: bool = false)` + chave `plataformas_consideradas` em todo retorno
- `tests/Feature/Phase141/Phase141ServicoTabelaSchemaTest.php` - Schema, backfill, idempotência da migration, mass assignment (5 testes)
- `tests/Feature/Phase141/Phase141ElegibilidadePlataformaTest.php` - Os 3 casos do must_have (Mentoria-only, Gestão+Shopee, Gestão-only com métrica Shopee avulsa) + contrato inativo + modo desligado intocado + guard de exceção + `plataformasElegiveis()` isolado (10 testes)

**Plan metadata:** (este commit, junto com STATE.md/ROADMAP.md)

## Decisions Made
- Default `false` da coluna nova é o default SEGURO pela ótica de cobrança: um serviço não classificado nunca entra na soma por engano (ver comentário da migration).
- O critério de elegibilidade por plataforma usa OU (não `elseif`) entre `plataforma` (texto manual, pode trazer as duas plataformas no mesmo campo) e `setor` (enum, rede de segurança) — decisão registrada em comentário no próprio método, ecoando a disciplina já usada em `FechamentoFaixaResolver`.
- `somenteContratadas` é opt-in com guard de `InvalidArgumentException` quando `$companies` não é informado — falha explícita em vez de silenciosamente considerar tudo elegível.

## Deviations from Plan

None - plano executado exatamente como escrito. Nenhuma tarefa exigiu fix de Rule 1/2/3/4; a árvore compartilhada já tinha os commits dos planos 141-02/141-03 quando o gate completo foi rodado, o que é esperado (fases irmãs em paralelo, não uma dependência ou regressão deste plano).

## Issues Encountered

O comando `plink`/`pscp`/`deploy.sh` e o `artisan migrate` contra o banco local foram evitados conforme travas do plano — a migration foi validada só via `RefreshDatabase` (SQLite) nos testes, nunca rodada manualmente contra o MySQL local nem produção.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- A capacidade de recorte por plataforma existe e está testada, mas **nada a liga ainda** — `somenteContratadas` continua `false` em todos os 4 call-sites reais (`ConsolidarMesFechamento`, `AdminController::fechamento()` ×2, `EnviarRelatorioFechamentoJob`). O plano 141-03 é quem liga, atrás da flag de corte.
- ⚠️ Ao ligar o recorte no 141-03, lembrar a armadilha do rollup ser chamado DUAS vezes (competência atual + mês anterior, ver instrução do orquestrador): `somenteContratadas` precisa ir `true` nas DUAS chamadas, senão a comparação de faixa entre os dois meses fica assimétrica e gera aviso falso de mudança de faixa (Fase 138).
- Serviços de catálogo em produção ainda não têm `usa_tabela_progressiva` conferido além do backfill automático — a tela administrativa que edita essa coluna manualmente é fase própria (fora de escopo aqui).

---
*Phase: 141-tabela-progressiva-por-empresa-e-grupo*
*Plan: 01*
*Completed: 2026-09-09*

## Self-Check: PASSED

Todos os 6 arquivos declarados (migration, model, service, 2 testes, este SUMMARY) e os 2 commits de tarefa (`2d9a2f07`, `3ef58eba`) foram confirmados presentes na árvore/git log.
