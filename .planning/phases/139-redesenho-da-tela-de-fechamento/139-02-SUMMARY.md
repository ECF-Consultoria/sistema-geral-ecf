---
phase: 139-redesenho-da-tela-de-fechamento
plan: 02
subsystem: api
tags: [laravel, eloquent, fechamento, totais, widget-total-a-receber]

# Dependency graph
requires:
  - phase: 139-01
    provides: "FechamentoComparativoService — leitura sem N+1 do fechamento congelado do mês anterior (empresa e grupo)"
provides:
  - "totalCobrancaDoMesAnterior() em FechamentoComparativoService — total do mês passado, distinguindo 'não fechado' de 'fechado em zero'"
  - "prop `totais` na resposta de Inertia de AdminController::fechamento(), nos dois ramos (ao vivo e congelado)"
affects: [139-03, 139-04, 139-05, 139-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "fechamentoTotais() somado sobre as MESMAS linhas de $dadosPorId já agregadas por grupo — nunca uma consulta paralela ao banco (T-139-05)"
    - "SUM(CASE WHEN ...) no banco pra combinar contagem de existência + soma condicional numa única consulta (evita 3ª query)"

key-files:
  created:
    - tests/Feature/Phase139/Phase139TotaisFechamentoTest.php
  modified:
    - app/Services/Fechamento/FechamentoComparativoService.php
    - app/Http/Controllers/AdminController.php

key-decisions:
  - "total_a_receber/faturamento_gerado nunca somam cobranca_mensal/faturamento null como zero — a linha some da soma silenciosamente seria a mesma classe de erro proibida pelo D-05; ela é nomeada em empresas_sem_valor_definido quando o estado é sem_tabela"
  - "total_e_piso é OR de todas as linhas somadas (não só da maior) — uma única linha em faixa-piso já torna o total inteiro um piso, disciplina de fmtValorFaixa"
  - "upgrades_ganho_parcial sinaliza quando alguma linha subiu de faixa sem termos o valor da faixa anterior — a tela mostra 'no mínimo' em vez de esconder a lacuna"
  - "fechamentoTotais() chamado logo após o Passo 3 (agregação por grupo), antes da progressão (Passo 4) — companies só tem linhas top-level nesse ponto, todas já com conta_no_total resolvido"

patterns-established:
  - "totalCobrancaDoMesAnterior(): SELECT com COUNT(*) + SUM(CASE WHEN company_group_id IS NULL THEN ...) resolve existência e soma condicional na mesma query"

requirements-completed: [D-01, D-04]

# Metrics
duration: ~50min
completed: 2026-09-04
---

# Phase 139 Plan 02: Prop `totais` do widget "Total a receber" Summary

**A prop `totais` chega na resposta de `AdminController::fechamento()`, nos dois ramos (ao vivo e congelado), somada sobre as mesmas linhas de `$dadosPorId` que a tela lista — nunca sobre a chave morta `cobranca_mensal_grupo`, que continua não existindo em `app/`.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-09-04
- **Tasks:** 3/3
- **Files modified:** 3 (1 modificado — serviço, 1 modificado — controller, 1 criado — teste)

## Accomplishments

- `FechamentoComparativoService::totalCobrancaDoMesAnterior()` — soma `cobranca_mensal` das linhas de GRUPO + linhas de EMPRESA sem grupo do mês anterior, sem dobrar a cobrança de quem está em grupo; devolve `fechado=false, total=null` quando o mês anterior nunca foi fechado (nunca `0.0` nesse caso), e `fechado=true, total=0.0` quando fechou só com linhas de `cobranca_mensal` null. No máximo 2 consultas (usa `SUM(CASE WHEN company_group_id IS NULL THEN cobranca_mensal ELSE 0 END)` pra resolver existência + soma na mesma query).
- `AdminController::fechamentoTotais()` (privado) — monta as 11 chaves da prop `totais` a partir de `$dadosPorId` já agregado (Passo 3), filtrando por `conta_no_total !== false`. `total_a_receber` e `faturamento_gerado` nunca tratam `null` como zero; `empresas_sem_valor_definido` conta as linhas em estado `sem_tabela`; `total_e_piso` marca o total inteiro quando qualquer linha somada é faixa-piso; `upgrades_quantidade`/`upgrades_ganho_total`/`upgrades_ganho_parcial` derivam de `subiu_de_faixa`/`ganho_faixa` (Fase 139 Plano 01).
- Prop `'totais' => $totais` emitida em `Inertia::render('Admin/Financeiro', ...)` — como `fechamentoTotais()` é chamado uma única vez em `fechamento()` antes da bifurcação de render (o próprio `$dadosPorId` já reflete o ramo ao vivo ou congelado escolhido no Passo 2), os dois ramos chegam com a mesma prop, sem duplicar lógica.
- 14 testes novos em `Phase139TotaisFechamentoTest.php` (5 unitários do serviço + 9 via HTTP), cobrindo os 8 itens do `<behavior>` da Tarefa 3: as 11 chaves, soma exata de 3 empresas, grupo contado uma vez, `sem_tabela` fora da soma mas nomeado, `total_e_piso`, mês anterior nunca fechado (variação null), mês anterior fechado (variação exata), competência corrente fechada batendo com o ramo aberto, e upgrades batendo com as linhas que subiram.

## Task Commits

1. **Tarefa 1: Total do mês passado no FechamentoComparativoService** - `e3a87cb1` (feat, TDD)
2. **Tarefa 2: Prop `totais` na resposta da tela** - `b24e52cb` (feat)
3. **Tarefa 3: Trava dos totais nos dois ramos** - `58b0b9e1` (test, TDD)

**Plan metadata:** (este commit, incluído no fechamento do plano)

## Files Created/Modified

- `app/Services/Fechamento/FechamentoComparativoService.php` — `totalCobrancaDoMesAnterior(string $mesReferenciaStr): array`.
- `app/Http/Controllers/AdminController.php` — `fechamentoTotais()` privado novo; chamada em `fechamento()` logo após o Passo 3; `'totais'` no array do `Inertia::render`.
- `tests/Feature/Phase139/Phase139TotaisFechamentoTest.php` — 14 testes (5 unitários + 9 via HTTP).

## Decisions Made

- **Ordem de execução dentro de `fechamento()`:** `fechamentoTotais($dadosPorId, ...)` chamado imediatamente após o Passo 3 (agregação por grupo), antes do Passo 4 (progressão histórica). Nesse ponto `$dadosPorId` só tem linhas top-level (empresas sem grupo + linhas de grupo) — as empresas-membro de um grupo estão aninhadas em `filhas`, não como chaves de topo — então o filtro `conta_no_total !== false` do plano é uma trava defensiva: na prática todas as linhas de topo já chegam com `conta_no_total = true` nesta etapa.
- **`SUM(CASE WHEN ...)` em vez de 2 consultas separadas para "existe fechamento" + "soma sem grupo":** reduz de 3 para 2 consultas totais no método (a 2ª é a soma de `FechamentoGrupoSnapshot`), cumprindo o limite do `<behavior>` da Tarefa 1.
- **`empresas_com_cobranca` conta `cobranca_mensal > 0`** (não `!== null`) — uma linha com `cobranca_mensal = 0.0` (ex.: contrato mensal com valor R$0, caso raro mas possível via `CobrancaCalculator`) não é uma "empresa com cobrança" pro propósito do widget.

## Deviations from Plan

None — plano executado exatamente como escrito. O único ajuste foi de redação: o comentário original que citaria a chave morta *literalmente* (`cobranca_mensal_grupo`) foi reescrito para descrever o conceito sem repetir a string, porque a verificação do próprio plano (`grep -c "cobranca_mensal_grupo" ... retorna 0`) checa a ocorrência literal no arquivo — não é uma mudança de comportamento, só evita que o comentário-alerta se torne um falso positivo do grep que ele mesmo descreve.

## Issues Encountered

None.

## User Setup Required

None — nenhuma configuração externa. Não houve deploy (fora do escopo deste plano e das travas da fase).

## Next Phase Readiness

- A prop `totais` está pronta para os planos 03-06 consumirem no widget "Total a receber" e no widget "Subiram de faixa" do `Financeiro.jsx`.
- Nenhum arquivo JSX foi tocado neste plano — a tela continua no estado da Fase 138 até os próximos planos.
- Gate `Phase122|Phase136|Phase137|Phase138|Phase139`: **302 testes / 1584 asserções / 0 falhas** (era 288/1528 antes deste plano — +14 testes/+56 asserções, exatamente os testes novos deste plano).

---
*Phase: 139-redesenho-da-tela-de-fechamento*
*Completed: 2026-09-04*

## Self-Check: PASSED

Arquivos criados/modificados e os 3 commits de task confirmados por reconsulta (`git log --oneline` + verificação de existência de arquivo).
