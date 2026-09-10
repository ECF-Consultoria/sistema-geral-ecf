---
phase: 142-cadastro-da-tabela-progressiva-no-contrato
plan: 01
subsystem: database
tags: [laravel, activitylog, fechamento, tabela-progressiva, auditoria]

requires:
  - phase: 141-tabela-de-faturamento-por-empresa
    provides: "as três origens (manual/contrato/presumida_servico) e a trava de precedência D-05"
  - phase: 140-conferencia-de-tabelas-lidas-do-clicksign
    provides: "TabelasContratoController::confirmar(), segundo gravador que este plano centraliza"
provides:
  - "GravarTabelaEmpresaService — porta única de escrita em empresa_faixas_faturamento, com trava de precedência e trilha de auditoria (log_name=faixa_faturamento_tabela)"
  - "tabela_faixas / tabela_faixas_e_de_hoje nas props de /administrativo/financeiro, nos dois ramos (ao vivo/congelado), por empresa e por grupo"
affects: [142-02, 142-03, 142-04]

tech-stack:
  added: []
  patterns:
    - "Porta única de escrita centralizando múltiplos controllers que gravam a mesma tabela — trava de precedência e auditoria moram uma vez só no serviço"
    - "Auditoria explícita via activity() quando delete de query builder não dispara eventos de model (LogsActivity não vê o delete)"

key-files:
  created:
    - app/Services/Fechamento/GravarTabelaEmpresaService.php
    - tests/Feature/Phase142/Phase142GravarTabelaEmpresaTest.php
    - tests/Feature/Phase142/Phase142FaixasProprasNasPropsTest.php
  modified:
    - app/Http/Controllers/FechamentoController.php
    - app/Http/Controllers/TabelasContratoController.php
    - app/Http/Controllers/AdminController.php

key-decisions:
  - "substituiu_confirmada = true sempre que a origem ANTERIOR era manual ou contrato, independente da origem nova (não só manual-sobre-contrato) — mesma regra geral, comportamento idêntico ao behavior do plano para os casos testados"
  - "A consulta MIN(origem) pré-existente (Fase 141, T-141-18) para procedencia_tabela foi eliminada e substituída pela leitura em lote de linhas completas que já alimenta tabela_faixas — reduz de 2 para 1 as consultas a empresa_faixas_faturamento no ramo congelado, sem mudar nenhum valor exposto"
  - "fechamentoAgregarGruposCongelados() deixou de receber procedenciaPorEmpresa por parâmetro; passou a reaproveitar procedencia_tabela/tabela_faixas já calculados em $dadosPorId[$ancoraId] pela função de empresa (mesmo instante, mesma leitura)"

patterns-established:
  - "Toda gravação/remoção de tabela de faixas por empresa passa por GravarTabelaEmpresaService::gravar()/remover() — próximo gravador (planos 02/03) deve chamar o serviço, nunca EmpresaFaixaFaturamento::where(...)->delete() direto"

requirements-completed: [D-01, DEC-TELAS]

duration: 35min
completed: 2026-09-10
---

# Phase 142 Plan 01: Porta única de escrita + exposição das linhas da tabela própria Summary

**`GravarTabelaEmpresaService` centraliza os dois gravadores existentes de `empresa_faixas_faturamento` com trava de precedência e trilha de auditoria explícita (fecha o buraco do delete de query builder que não disparava `LogsActivity`), e `/administrativo/financeiro` passa a expor `tabela_faixas` com as linhas reais da tabela própria, nos dois ramos, sem crescer o número de consultas por empresa.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-10 (ver commits)
- **Completed:** 2026-09-10T12:58Z
- **Tasks:** 3/3
- **Files modified:** 6 (3 criados, 3 modificados)

## Accomplishments

- `GravarTabelaEmpresaService::gravar()`/`remover()` — porta única de escrita, com a trava de precedência do D-05 da Fase 141 (`presumida_servico` nunca sobrescreve `manual`/`contrato`, lançando `\RuntimeException` sem alterar nenhuma linha) e trilha de auditoria em `activity_log` (`log_name = 'faixa_faturamento_tabela'`) com `antes`/`depois` da tabela inteira, `origem_anterior`, `origem_nova`, `feito_de` e causer.
- `FechamentoController::salvarFaixasEmpresa()`/`removerFaixasEmpresa()` e `TabelasContratoController::confirmar()` religados ao serviço — contrato HTTP e mensagens de flash inalterados byte a byte; as suítes das Fases 137/140/141 seguem verdes sem qualquer alteração de teste.
- `tabela_faixas` (linhas `ordem`/`limite_superior`/`valor`/`valor_e_piso`) e `tabela_faixas_e_de_hoje` (bool) adicionadas nas props de `/administrativo/financeiro`, por empresa e por grupo, nos dois ramos — só preenchidas quando a origem é `'propria'` (evita segunda fonte da mesma informação que já existe em `faixas_por_servico`/`faixas_por_grupo`).
- Ramo congelado lê a tabela cadastrada HOJE (nunca a do mês fechado, D-11 intocado) com UMA consulta em lote — e essa mesma leitura substituiu a consulta `MIN(origem)` separada que já existia (Fase 141, T-141-18) para `procedencia_tabela`, reduzindo de 2 para 1 as consultas a `empresa_faixas_faturamento` na montagem das props.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: GravarTabelaEmpresaService — a porta única de escrita, com trilha** (tdd)
   - `77ec1ab3` test(142-01): trava a precedência e a trilha de auditoria do GravarTabelaEmpresaService
   - `368d49ee` feat(142-01): GravarTabelaEmpresaService - porta única de escrita com trilha de auditoria
2. **Tarefa 2: religar os dois gravadores existentes ao serviço** - `a76afed3` refactor(142-01)
3. **Tarefa 3: expor as LINHAS da tabela própria nas props do Fechamento** - `2bd43bcf` feat(142-01)

_Nota: a Tarefa 1 é `tdd="true"` — commit `test(...)` (RED) seguido de `feat(...)` (GREEN), sequência confirmada no `git log`._

## Files Created/Modified

- `app/Services/Fechamento/GravarTabelaEmpresaService.php` — porta única de escrita: `gravar()` (all-or-nothing, trava de precedência, auditoria) e `remover()`
- `tests/Feature/Phase142/Phase142GravarTabelaEmpresaTest.php` — 14 testes cobrindo as 3 origens, a trava de precedência, all-or-nothing e a trilha de auditoria
- `tests/Feature/Phase142/Phase142FaixasProprasNasPropsTest.php` — 4 testes cobrindo `tabela_faixas` nos dois ramos e a consulta em lote
- `app/Http/Controllers/FechamentoController.php` — `salvarFaixasEmpresa()`/`removerFaixasEmpresa()` religados ao serviço
- `app/Http/Controllers/TabelasContratoController.php` — `confirmar()` religado ao serviço
- `app/Http/Controllers/AdminController.php` — `tabela_faixas`/`tabela_faixas_e_de_hoje` nos 4 métodos de montagem de props (empresa ao vivo/congelada, grupo ao vivo/congelado) + helper `fechamentoMapFaixasParaProps()`; consulta `MIN(origem)` duplicada removida dos 3 chamadores (`fechamento()`, relatório individual, relatório geral)

## Decisions Made

- **`substituiu_confirmada`** é derivado de uma regra única (`origem_anterior` era `manual` ou `contrato`, qualquer que seja a origem nova), em vez de casos especiais por combinação — mais simples e cobre exatamente os cenários do `<behavior>` do plano.
- **Eliminação da consulta duplicada de procedência** (Rule 1 — a Tarefa 3 pedia explicitamente "no máximo UMA consulta a `empresa_faixas_faturamento`" no teste (d), e a implementação inicial gerava 2 por reter a consulta `MIN(origem)` pré-existente da Fase 141 ao lado da nova leitura de linhas completas). A leitura em lote de linhas completas já contém a origem da primeira linha — usada para derivar `procedencia_tabela` internamente, eliminando a segunda consulta. `fechamentoAgregarGruposCongelados()` deixou de receber `$procedenciaPorEmpresa` por parâmetro, reaproveitando o que a função de empresa já calculou em `$dadosPorId[$ancoraId]` (mesmo padrão já usado para `tabela_faixas`). Os 3 pontos de chamada (`fechamento()`, relatório PDF individual, relatório PDF geral) tiveram a consulta removida; nenhum deles ficou sem a informação — ela agora é interna às duas funções congeladas.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Consulta duplicada a `empresa_faixas_faturamento` no ramo congelado**
- **Found during:** Tarefa 3, ao escrever o teste (d) do próprio plano ("no máximo UMA consulta")
- **Issue:** A implementação inicial adicionava uma nova consulta em lote (linhas completas para `tabela_faixas`) ao lado da consulta `MIN(origem)` já existente desde a Fase 141 para `procedencia_tabela` — 2 consultas à mesma tabela na montagem das props, quando o próprio plano especificava "no máximo UMA".
- **Fix:** A consulta de linhas completas passou a alimentar as duas necessidades (linhas e procedência derivada da primeira linha), e a consulta `MIN(origem)` antiga foi removida dos 3 chamadores. `fechamentoAgregarGruposCongelados()` parou de receber `procedenciaPorEmpresa` como parâmetro, reaproveitando o valor já calculado para a empresa-âncora.
- **Files modified:** `app/Http/Controllers/AdminController.php`
- **Verification:** `Phase142FaixasProprasNasPropsTest::congelado_le_a_tabela_propria_em_no_maximo_uma_consulta_para_o_lote` passa; suítes 137/138/141 de props seguem verdes sem alteração de teste.
- **Committed in:** `2bd43bcf` (commit único da Tarefa 3)

---

**Total deviations:** 1 auto-fixed (1 bug de query duplicada, corrigido antes de qualquer regressão chegar a existir em produção).
**Impact on plan:** Correção estritamente dentro do escopo da própria Tarefa 3 (o teste (d) especificado no plano). Nenhum scope creep.

## Issues Encountered

None além do já documentado em Deviations.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

- `GravarTabelaEmpresaService` está pronto para ser o TERCEIRO gravador (a ficha nova por empresa, planos 142-02/03) — qualquer escrita nova deve chamar `gravar()`/`remover()`, nunca `EmpresaFaixaFaturamento::where(...)->delete()` direto.
- `tabela_faixas` já chega nas props do Fechamento com dados reais — os planos 02/03 (ficha nova no contrato) e 04 (fechamento só leitura) podem consumir a mesma prop sem precisar reconstruir a tabela por aproximação.
- Nenhum comportamento de cobrança mudou neste plano — confirmado pelas 571 asserções de regressão sem alteração de valor de mensalidade/faixa/faturamento.
- **Gate de testes:** `553 → 571 testes` (18 novos: 14 da Tarefa 1 + 4 da Tarefa 3), `2642 → 2729 asserções`, **0 falhas**.

---
*Phase: 142-cadastro-da-tabela-progressiva-no-contrato*
*Completed: 2026-09-10*

## Self-Check: PASSED

Todos os arquivos criados e todos os hashes de commit (`77ec1ab3`, `368d49ee`, `a76afed3`, `2bd43bcf`) foram confirmados por reconsulta ao disco/git.
