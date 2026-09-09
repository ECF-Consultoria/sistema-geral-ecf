---
phase: 141-tabela-progressiva-por-empresa-e-grupo
plan: 02
subsystem: payments
tags: [cobranca, fechamento, feature-flag, tdd]

# Dependency graph
requires:
  - phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
    provides: "FechamentoFaixaResolver::classificar() (shape do array de classificação) e FechamentoSnapshot"
  - phase: 14
    provides: "CobrancaCalculator (legacy()/novo()) e o comando phase14:verificar-cobranca que os consome"
provides:
  - "FechamentoRegraTabela — interruptor fechamento_tabela_por_empresa_ativa, memoizado, nasce desligado"
  - "CobrancaCalculator::mensalidade() — valor da faixa, ou soma dos contratos mensais quando não há faixa, aditivo aos métodos existentes"
  - "FechamentoSnapshot::ESTADO_VALOR_FIXO — estado novo para empresa sem tabela progressiva"
affects: [141-03, 141-04, 141-05, 141-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Interruptor de flag memoizado por instância (Configuracao::get com default '0', propriedade ?bool, método esquecer())"
    - "Método aditivo em helper estático existente + extração de helper privado compartilhado (contratosMensaisElegiveis) para não duplicar filtro entre novo() e mensalidade()"

key-files:
  created:
    - app/Services/Fechamento/FechamentoRegraTabela.php
    - tests/Feature/Phase141/Phase141RegraTabelaFlagTest.php
    - tests/Feature/Phase141/Phase141MensalidadeCalculatorTest.php
  modified:
    - app/Support/CobrancaCalculator.php
    - app/Models/FechamentoSnapshot.php

key-decisions:
  - "mensalidade() devolve null (nunca 0.0) quando não há faixa E zero contratos mensais elegíveis — distinguido por lista vazia, não por soma > 0, para não confundir 'nenhum contrato' com 'um contrato de R$ 0,00'"
  - "novo() passou a reusar o mesmo filtro de contratos elegíveis via helper privado somaContratosMensais()/contratosMensaisElegiveis() — comportamento observável de novo() idêntico ao de antes deste plano (provado por teste lado a lado)"
  - "Nenhum consumidor foi ligado nesta plano — FechamentoRegraTabela e mensalidade() existem mas não são chamados por ninguém (confirmado por grep), por desenho: quem liga é o plano 141-03"

requirements-completed: [TPE-02, TPE-03, TPE-07]

# Metrics
duration: 20min
completed: 2026-09-09
---

# Phase 141 Plan 02: Interruptor da regra nova + CobrancaCalculator::mensalidade() Summary

**Flag `fechamento_tabela_por_empresa_ativa` (memoizada, nasce desligada) e `CobrancaCalculator::mensalidade()` que devolve só o valor da faixa — prova, em teste, que o caso BARAOSHOP cai de R$ 5.500 (regra atual) para R$ 3.000 (D-03), sem tocar em nenhum consumidor de produção.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-09T16:39:00-03:00 (aprox.)
- **Completed:** 2026-09-09T16:49:00-03:00
- **Tasks:** 2 completed
- **Files modified:** 5 (3 criados, 2 modificados)

## Accomplishments
- `FechamentoRegraTabela::ativa()` lê `configuracoes.fechamento_tabela_por_empresa_ativa`, memoiza por instância (1 consulta por 100 chamadas, provado por `DB::getQueryLog()`), nasce desligada, só `'1'` liga.
- `CobrancaCalculator::mensalidade()` implementa D-03 (mensalidade = valor da faixa, e só isso) de forma aditiva — `legacy()` e `novo()` seguem intocados e testados para continuar idênticos.
- Teste `caso_baraoshop_antes_e_depois_lado_a_lado` prova numericamente, no mesmo cenário: `novo()` = R$ 5.500,00 (fórmula atual, o bug) e `mensalidade()` = R$ 3.000,00 (fórmula nova, D-03).
- `FechamentoSnapshot::ESTADO_VALOR_FIXO = 'valor_fixo'` — estado novo para empresa sem tabela progressiva (Mentoria e os 29 contratos de valor fixo da Fase 140), distinto de `ESTADO_SEM_TABELA` (que hoje significa "tabela do serviço veio vazia", pendência real).

## Task Commits

Cada tarefa seguiu RED → GREEN (TDD):

1. **Tarefa 1: Interruptor `FechamentoRegraTabela`**
   - `30f9c833` (test) — teste falho: desligada por default, `'1'` liga, memoização, `esquecer()`
   - `591674be` (feat) — implementação: 5/5 verde
2. **Tarefa 2: `CobrancaCalculator::mensalidade()` + `ESTADO_VALOR_FIXO`**
   - `b4700ca8` (test) — teste falho: caso BARAOSHOP antes×depois, faixa presente, sem faixa (soma contratos), sem faixa e sem contrato (null), filtro ativo/tipo_unica, `legacy()`/`novo()` intactos, `ESTADO_VALOR_FIXO`
   - `5b7d7ede` (feat) — implementação: 7/7 verde

_Nenhum commit de metadados de plano separado — este SUMMARY e a atualização de STATE/ROADMAP/REQUIREMENTS vão no commit final `docs(141-02)`._

## Files Created/Modified
- `app/Services/Fechamento/FechamentoRegraTabela.php` - Leitor memoizado da flag `fechamento_tabela_por_empresa_ativa`; `esquecer()` para re-leitura
- `app/Support/CobrancaCalculator.php` - Novo `mensalidade()` (aditivo); `novo()` refatorado para reusar `contratosMensaisElegiveis()`/`somaContratosMensais()` sem mudar comportamento
- `app/Models/FechamentoSnapshot.php` - Constante `ESTADO_VALOR_FIXO = 'valor_fixo'`
- `tests/Feature/Phase141/Phase141RegraTabelaFlagTest.php` - 5 testes do interruptor
- `tests/Feature/Phase141/Phase141MensalidadeCalculatorTest.php` - 7 testes do cálculo novo (inclui comparação ANTES×DEPOIS)

## Decisions Made
- `mensalidade()` retorna `null` (não `0.0`) quando há zero contratos elegíveis, verificado por lista vazia (`contratosMensaisElegiveis() === []`), não por `soma > 0` — isso preserva a distinção entre "não sei quanto cobrar" e "um contrato legítimo de R$ 0,00" (edge case não coberto pelo `<behavior>` do plano, mas decorre diretamente da regra "nunca 0.0 nesse caso").
- Extraí `contratosMensaisElegiveis()` como helper privado único do filtro (ativo + servico carregado + `tipo_cobranca=mensal`), e fiz `novo()` e `mensalidade()` reusarem — evita duplicar a lógica de filtro em dois lugares, como o plano pediu explicitamente.
- Confirmei por `grep -rn` que não há nenhum consumidor de `FechamentoRegraTabela` nem `CobrancaCalculator::mensalidade()` fora das próprias definições (só uma menção em docblock) — cumpre a âncora do plano de que "nada é chamado ainda".

## Deviations from Plan

None - plano executado exatamente como escrito. O único ponto que exigiu decisão própria (distinção null vs. 0.0 quando a soma é exatamente zero por um contrato de R$0) está documentado acima em "Decisions Made", não é desvio de escopo.

## Issues Encountered
- `Phase14VerificarCobrancaTest::test_aborta_com_divergencia` falhou ao rodar `--filter="Phase141MensalidadeCalculatorTest|Phase14VerificarCobranca"` — **falha PRÉ-EXISTENTE**, listada explicitamente como não-minha no prompt de execução. Não toquei em `legacy()`/`novo()` (comportamento idêntico, provado por teste); a causa é alheia a este plano. Confirmado que o filtro combinado `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Quick260909` roda **477 testes / 2318 asserções / 0 falhas** — idêntico ao gate informado antes de eu começar.

## User Setup Required
None - nenhuma configuração externa. A flag `fechamento_tabela_por_empresa_ativa` nasce sem seed nenhuma; ligá-la em produção é decisão do plano 141-07.

## Next Phase Readiness
- `FechamentoRegraTabela` e `CobrancaCalculator::mensalidade()` estão prontos para o plano 141-03 consumir (é quem liga o interruptor no caminho de produção, condicionado a `ativa()`).
- `FechamentoSnapshot::ESTADO_VALOR_FIXO` está pronto para o writer do snapshot gravar quando `$classificacao === null`.
- Nenhum bloqueio. Gate de regressão (`Phase122|Phase136|...|Quick260909`) confirmado em 477/2318/0 antes de encerrar.

---
*Phase: 141-tabela-progressiva-por-empresa-e-grupo*
*Completed: 2026-09-09*

## Self-Check: PASSED

Todos os 5 arquivos criados/modificados encontrados no disco; os 4 commits de tarefa (30f9c833, 591674be, b4700ca8, 5b7d7ede) encontrados em `git log --all`.
