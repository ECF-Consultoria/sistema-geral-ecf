---
phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
plan: 01
subsystem: database
tags: [laravel, eloquent, migration, phpunit, tdd, fechamento]

# Dependency graph
requires:
  - phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
    provides: "EmpresaFaixaFaturamento, ServicoFaixaFaturamento e FechamentoFaixaResolver::paraEmpresa()/classificar()"
provides:
  - "Tabela grupo_faixas_faturamento (índice gff_grupo_ordem_unq, idempotente)"
  - "Model GrupoFaixaFaturamento com LogsActivity"
  - "FechamentoFaixaResolver::paraGrupo() e degrau de grupo em paraEmpresa()"
  - "Shape único de 8 chaves (origem, servico_*, grupo_*, herdada_de_*, faixas) sempre presentes"
  - "Precedência final D-01: grupo → empresa → serviço"
affects: [138-03, 138-04, 138-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Tabela de faixa por grupo é o 3º caso do padrão ALL-OR-NOTHING (servico → empresa → grupo)"
    - "Shape de retorno do resolver sempre com todas as chaves presentes (nunca precisa de coalesce no consumidor)"

key-files:
  created:
    - database/migrations/2026_09_03_120001_create_grupo_faixas_faturamento_table.php
    - app/Models/GrupoFaixaFaturamento.php
    - tests/Feature/Phase138/Phase138GrupoFaixaResolverTest.php
  modified:
    - app/Services/Fechamento/FechamentoFaixaResolver.php

key-decisions:
  - "grupo_faixas_faturamento espelha o desenho de empresa_faixas_faturamento (company_group_id NOT NULL + cascadeOnDelete, índice único curto gff_grupo_ordem_unq, migration idempotente)"
  - "paraEmpresa() ganhou um degrau 0 (tabela de grupo) antes da exceção por empresa — grupo vence mesmo quando a empresa-membro tem tabela própria cadastrada"
  - "paraGrupo() anexa herdada_de_company_id/herdada_de_company_name só quando a tabela do grupo NÃO existe — nunca quando existe, matando a herança invisível"
  - "Shape de retorno ganhou 4 chaves novas (grupo_id, grupo_nome, herdada_de_company_id, herdada_de_company_name) mantendo as 4 antigas com o mesmo significado — consumidores da Fase 137 (ConsolidarMesFechamento, AdminController) continuam válidos sem alteração"

patterns-established:
  - "Pattern: precedência de faixa em 3 níveis centralizada só no resolver — nenhum consumidor reimplementa a ordem"

requirements-completed: [D-01]

# Metrics
duration: ~25min
completed: 2026-09-03
---

# Phase 138 Plan 01: Tabela do Grupo e Precedência no Resolver Summary

**Tabela `grupo_faixas_faturamento` + `FechamentoFaixaResolver::paraGrupo()` implementando a precedência final grupo → empresa → serviço (D-01), com herança da âncora explicitada quando o grupo não tem tabela própria.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-09-03 (medição de contexto no PLAN/CONTEXT)
- **Completed:** 2026-09-03
- **Tasks:** 3 (Tarefa 3 é a trava de regressão, coberta pela verificação da Tarefa 2)
- **Files modified:** 4 (1 migration nova, 1 model novo, 1 teste novo, 1 arquivo existente ampliado)

## Accomplishments
- Migration `grupo_faixas_faturamento` criada seguindo o molde literal de `empresa_faixas_faturamento` (mesmas 3 armadilhas de MariaDB tratadas: índice `gff_grupo_ordem_unq` com nome curto, `company_group_id` NOT NULL com `cascadeOnDelete()`, migration idempotente com guard `Schema::hasTable`)
- Model `GrupoFaixaFaturamento` com `LogsActivity` + `logFillable()` (conferido linha a linha contra `EmpresaFaixaFaturamento` — auditoria da faixa de grupo funciona desde o primeiro registro, sem repetir o gap de `BonusFaixa` da Fase 74)
- `FechamentoFaixaResolver::paraEmpresa()` ganhou o degrau de grupo como primeiro passo: grupo com tabela cadastrada vence a exceção por empresa e a tabela de serviço
- `FechamentoFaixaResolver::paraGrupo(CompanyGroup $grupo, ?Company $ancora)` novo: tabela própria do grupo quando houver, senão delega para `paraEmpresa($ancora)` e marca `herdada_de_company_id`/`herdada_de_company_name`
- Shape de retorno ampliado para 8 chaves sempre presentes (4 novas: `grupo_id`, `grupo_nome`, `herdada_de_company_id`, `herdada_de_company_name`), mantendo compatibilidade total com os dois consumidores atuais (`ConsolidarMesFechamento`, `AdminController`)
- Ciclo TDD completo: RED (7 testes falhando — 6 erros de chave/método ausente + 1 falha de precedência) → GREEN (7/7 verdes, 69 asserções novas)

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Migration e model** - `171f378a` (test — commitado junto com o início do ciclo TDD da Tarefa 2, sem código de comportamento próprio para separar)
2. **Tarefa 2 (RED): Teste do degrau de grupo** - `4bdf4776` (test)
3. **Tarefa 2 (GREEN): paraGrupo() + degrau de grupo** - `277fb7f9` (feat)
4. **Tarefa 3: Trava de não-regressão** - coberta pela mesma rodada de verificação da Tarefa 2 (nenhum código adicional necessário — gate já fechou em 0 falhas)

_Nota: a Tarefa 1 foi commitada como `test(...)` porque a migration e o model, sozinhos, não mudam comportamento observável do sistema — só passam a existir quando o resolver os consome na Tarefa 2. Rotulado `test` por serem prerequisitos do ciclo TDD que segue, não `feat` isolado._

## Files Created/Modified
- `database/migrations/2026_09_03_120001_create_grupo_faixas_faturamento_table.php` - cria a tabela, índice único nomeado, idempotente
- `app/Models/GrupoFaixaFaturamento.php` - model com `LogsActivity`, `scopeOrdenadas()`, relação `grupo()`
- `app/Services/Fechamento/FechamentoFaixaResolver.php` - degrau de grupo em `paraEmpresa()`, método `paraGrupo()` novo, shape unificado de 8 chaves
- `tests/Feature/Phase138/Phase138GrupoFaixaResolverTest.php` - 7 testes cobrindo todos os casos do bloco `<behavior>` do plano

## Decisions Made
- Índice único chamado `gff_grupo_ordem_unq` (nome já definido no plano, usado literalmente — evita o erro 1059 do MariaDB para nomes de índice acima de 64 caracteres)
- `company_group_id` ficou NOT NULL com `cascadeOnDelete()` (mesma decisão de design de `empresa_faixas_faturamento.company_id` — linha de faixa de grupo apagado não tem valor de auditoria)
- O degrau de grupo em `paraEmpresa()` foi implementado como o PRIMEIRO passo, antes até da consulta da exceção por empresa — evita fazer uma query desnecessária em `empresa_faixas_faturamento` quando o grupo já resolveu a resposta
- `paraGrupo()` reusa `paraEmpresa($ancora)` internamente ao invés de duplicar a lógica de resolução da âncora — quando chamado, a checagem de grupo dentro de `paraEmpresa($ancora)` já vai bater "sem tabela" (mesmo grupo que acabamos de checar), então não há dupla-checagem inconsistente

## Deviations from Plan

None - plano executado exatamente como escrito. As 8 chaves do shape, a ordem de precedência e o comportamento de `paraGrupo()` seguem literalmente o bloco `<behavior>` da Tarefa 2.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- `GrupoFaixaFaturamento` e `FechamentoFaixaResolver::paraGrupo()` prontos para os planos 03 (comando de fechamento), 04 (writer/snapshot) e 06 (CRUD/tela) consumirem
- O plano 06 pode usar `herdada_de_company_name` diretamente para exibir de qual empresa a tabela foi herdada quando o grupo não tem tabela própria — sem precisar calcular a âncora de novo
- Nenhum consumidor da Fase 137 foi alterado neste plano — `ConsolidarMesFechamento` e `AdminController` continuam lendo as mesmas 4 chaves de sempre sem quebrar
- Plano 138-02 (outro executor, em paralelo) não teve nenhum arquivo em comum com este plano — sem conflito de merge esperado

---
*Phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa*
*Completed: 2026-09-03*

## Self-Check: PASSED

Todos os 5 arquivos-chave e os 3 commits do plano foram confirmados no disco/histórico do git.
