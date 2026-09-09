---
phase: 141-tabela-progressiva-por-empresa-e-grupo
plan: 03
subsystem: database
tags: [laravel, eloquent, migration, artisan-command, fechamento, cobranca]

# Dependency graph
requires:
  - phase: 141-tabela-progressiva-por-empresa-e-grupo (planos 01/02)
    provides: FechamentoFaixaResolver::paraEmpresa() (origem 'grupo'|'propria'|'servico'), schema de empresa_faixas_faturamento (Fase 137)
provides:
  - "Coluna origem + servico_origem_id em empresa_faixas_faturamento (ORIGEM_MANUAL/ORIGEM_CONTRATO/ORIGEM_PRESUMIDA_SERVICO)"
  - "Os dois pontos de escrita existentes (cadastro manual, confirmação de contrato) carimbando a procedência certa"
  - "Comando fechamento:materializar-tabelas (dry-run por padrão, --aplicar grava, --json para conferência) — a ponte que copia a tabela do serviço para as empresas que hoje dependem dela"
affects: [141-04, 141-05, 141-06, 141-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Procedência de dado como coluna carimbada explicitamente no ponto de escrita, nunca inferida por ausência de outra coisa"
    - "Comando de materialização dry-run-first com guard de idempotência checado dentro da própria transação de escrita"

key-files:
  created:
    - database/migrations/2026_09_09_150002_add_origem_to_empresa_faixas_faturamento_table.php
    - app/Console/Commands/MaterializarTabelasFechamento.php
    - tests/Feature/Phase141/Phase141ProcedenciaTabelaTest.php
    - tests/Feature/Phase141/Phase141MaterializarTabelasTest.php
  modified:
    - app/Models/EmpresaFaixaFaturamento.php
    - app/Http/Controllers/FechamentoController.php
    - app/Http/Controllers/TabelasContratoController.php

key-decisions:
  - "presumida_servico é uma origem distinta de propria/manual/contrato — o plano 141-06 (fora deste plano) usa essa distinção para impedir que tabela_confirmada() devolva true para uma presunção"
  - "servico_origem_id não tem foreign key — é carimbo de procedência, não restrição relacional (evita armadilhas de MariaDB 1830/1553 que não existiriam de qualquer forma sem FK)"
  - "Guard de idempotência do comando roda DENTRO da transação por empresa, checando EmpresaFaixaFaturamento::where()->exists() de novo antes de criar — nunca confia só no balde calculado no início da rodada"

requirements-completed: [TPE-05, TPE-06]

duration: 21min
completed: 2026-09-09
---

# Fase 141 Plano 03: Procedência da tabela + ponte de materialização Summary

**Coluna `origem` em `empresa_faixas_faturamento` (manual/contrato/presumida_servico) + comando `fechamento:materializar-tabelas` que copia, para as 127 empresas hoje classificadas pela tabela do serviço, essa mesma tabela como tabela própria — sem mudar nenhuma cobrança.**

## Performance

- **Duration:** 21 min (16:43 → ~17:04 -03:00, 2026-09-09)
- **Tasks:** 2/2 completos
- **Files modified:** 5 (3 criados, 2 modificados na Tarefa 1) + 2 criados na Tarefa 2 = 7 no total

## Accomplishments
- Toda tabela de empresa agora carrega de onde veio: `EmpresaFaixaFaturamento::ORIGEM_MANUAL` (cadastro humano), `ORIGEM_CONTRATO` (confirmação da leitura do Clicksign, Fase 140) ou `ORIGEM_PRESUMIDA_SERVICO` (copiada da tabela do serviço nesta fase).
- Os dois pontos de escrita existentes (`FechamentoController::salvarFaixasEmpresa()` e `TabelasContratoController::confirmar()`) carimbam a procedência certa explicitamente — não dependem do default do banco.
- `fechamento:materializar-tabelas` classifica cada empresa ativa em quatro baldes (materializar / já tem própria / pelo grupo / continua sem tabela), mostra o que faria em dry-run (padrão) e só grava com `--aplicar` — os valores gravados são idênticos aos que já estavam sendo aplicados, então nenhuma cobrança muda.
- Idempotência provada: rodar `--aplicar` duas vezes seguidas não duplica nem sobrescreve; empresa com tabela própria (de qualquer origem) ou classificada por grupo nunca é tocada.
- Nenhuma linha de `fechamento_snapshots`/`fechamento_grupo_snapshots` é lida ou escrita pelo comando — mês já fechado (D-11 da Fase 137) não é reescrito por esta ponte.

## Task Commits

1. **Tarefa 1: Procedência da tabela da empresa** - `73d8c4fc` (feat)
2. **Tarefa 2: Comando `fechamento:materializar-tabelas`** - `8fda8a44` (feat)

_Nota de processo: para cada tarefa TDD, o teste foi escrito e confirmado falhando (RED) ANTES da implementação, mas commitado junto com a implementação verde num único commit `feat` — não em dois commits separados `test`/`feat`. Decisão pragmática: árvore compartilhada com outra sessão ativa no mesmo repositório tornava reescrita de histórico (`git commit --amend`/rebase) arriscada; o ciclo RED→GREEN foi seguido de fato (comandos rodados e confirmados), só a granularidade do commit é que ficou em um só. Sem impacto em correção — os dois commits contêm teste + implementação juntos e passam._

## Files Created/Modified
- `database/migrations/2026_09_09_150002_add_origem_to_empresa_faixas_faturamento_table.php` - Migration idempotente: `origem` string(20) default 'manual', `servico_origem_id` unsignedBigInteger nullable sem FK
- `app/Models/EmpresaFaixaFaturamento.php` - Constantes `ORIGEM_MANUAL`/`ORIGEM_CONTRATO`/`ORIGEM_PRESUMIDA_SERVICO`, colunas no `$fillable`, docblock explicando a semântica de cada procedência
- `app/Http/Controllers/FechamentoController.php` - `salvarFaixasEmpresa()` grava `origem => ORIGEM_MANUAL` explicitamente
- `app/Http/Controllers/TabelasContratoController.php` - `confirmar()` grava `origem => ORIGEM_CONTRATO`
- `app/Console/Commands/MaterializarTabelasFechamento.php` - Comando `fechamento:materializar-tabelas` (dry-run padrão, `--aplicar`, `--json`)
- `tests/Feature/Phase141/Phase141ProcedenciaTabelaTest.php` - 4 testes: default da coluna, cadastro manual, confirmação de contrato, migration idempotente
- `tests/Feature/Phase141/Phase141MaterializarTabelasTest.php` - 7 testes: dry-run não grava, `--aplicar` copia com procedência certa, tabela própria não é tocada, tabela por grupo não é tocada, empresa sem tabela aparece no relatório, idempotência, snapshots intocados

## Decisions Made
- `servico_origem_id` sem foreign key — é um carimbo de procedência (de qual serviço a tabela foi copiada), não uma restrição relacional. Decisão do próprio plano (não uma escolha minha): evita FK apontando para `servicos` sem propósito funcional, e de quebra evita as armadilhas de MariaDB (`nullOnDelete` sem `nullable`, erro 1830; alterar índice usado por FK, erro 1553) que nem se aplicariam aqui.
- O comando materializa apenas o balde `origem === 'servico'` do resolver — empresas com `origem === 'grupo'` ou `'propria'` são deliberadamente ignoradas, e empresas com resolver `null` só entram no relatório nominal (não são gravadas com nada).
- Guard de idempotência re-checa `EmpresaFaixaFaturamento::where('company_id', ...)->exists()` DENTRO da transação de cada empresa, não confiando só no balde calculado antes do loop — cobre o caso de duas rodadas concorrentes ou de uma linha criada por outro caminho entre o cálculo e a gravação.

## Deviations from Plan

None - plano executado como escrito. (Ver nota de processo em "Task Commits" acima sobre granularidade dos commits TDD — não é desvio de escopo ou comportamento, só de como os commits foram agrupados.)

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- A coluna `origem` e o comando de materialização estão prontos para os planos seguintes da Fase 141:
  - **141-04** pode agora ligar a flag que tira a tabela do serviço de circulação como régua, sabendo que a materialização (quando rodada em produção pelo 141-07) preserva a cobrança de hoje.
  - **141-06** (tela) tem o dado (`origem === 'presumida_servico'`) para forçar `tabela_confirmada = false` e reaproveitar `TabelaPresumidaBadge`/`TabelaPresumidaAviso` — **esta parte NÃO foi feita aqui**, porque `AdminController.php` (onde vive `fechamentoTabelaConfirmada()`) não está nos `files_modified` deste plano. Fica registrado para quem executar o 141-06: sem esse tratamento, as tabelas materializadas por este plano apareceriam como confirmadas.
  - **141-07** é quem roda `fechamento:materializar-tabelas --aplicar` contra produção, sob gate humano — este plano NUNCA tocou produção (proibido pelas travas do prompt).
- Verificação `grep -rn "EmpresaFaixaFaturamento::create\|EmpresaFaixaFaturamento::where" app/` confirma exatamente os três lugares de escrita esperados: cadastro manual, confirmação de contrato, comando novo (mais os `where()` de leitura pré-existentes no resolver e no `removerFaixasEmpresa()`).
- Gate `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Quick260909"` rodado após as duas tarefas: **477 testes / 2318 asserções / 0 falhas** — idêntico ao baseline informado, sem regressão.
- Gate específico do plano `--filter="Phase141|Phase137|Phase140"`: **270 testes / 1143 asserções / 0 falhas**.

---
*Phase: 141-tabela-progressiva-por-empresa-e-grupo*
*Completed: 2026-09-09*

## Self-Check: PASSED

Todos os 7 arquivos criados/modificados confirmados por leitura direta do filesystem; os dois commits de tarefa (`73d8c4fc`, `8fda8a44`) confirmados por `git log --oneline --all`.
