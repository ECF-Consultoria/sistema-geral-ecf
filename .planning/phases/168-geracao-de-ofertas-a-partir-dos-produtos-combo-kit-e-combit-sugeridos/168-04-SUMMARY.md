---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 04
subsystem: portal-estrutura
tags: [geracao, nomes, logistica, puro]
requires: [168-01]
provides: [NomesSugeridos, ConjuntoLogistico]
affects: [168-07]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/NomesSugeridos.php
    - app/Services/Portal/Estrutura/Geracao/ConjuntoLogistico.php
    - tests/Unit/PortalEstrutura/Geracao/NomesSugeridosTest.php
    - tests/Unit/PortalEstrutura/Geracao/ConjuntoLogisticoTest.php
decisions:
  - "Nome do Combo usa 'Kit {n} {Plural} {resto}' só quando os primeiros tokens normalizados do nome igualam nome/plural do tipo (sem prefixo solto)"
  - "Artigo no porquê do Combit pela última letra: a -> a, o -> o, outra -> sem artigo"
requirements: [PR168-09, PR168-10]
metrics:
  tasks: 2
  completed: 2026-10-07
---

# Phase 168 Plan 04: Nomes sugeridos e logística do conjunto Summary

Duas classes puras: `NomesSugeridos` (nome, SKU, avisos 60/120 e texto do porquê por fase) e `ConjuntoLogistico` (volumes × quantidade delegados a `LogisticaProduto::daVolumes`, lista de produtos sem medida e custo do conjunto).

## Tarefas

| Tarefa | Commit |
|--------|--------|
| 1. NomesSugeridos | fa070e71 |
| 2. ConjuntoLogistico | 84cb3941 |

## Verificação

Os dois testes juntos: 25 testes, 40 asserções, exit 0. `ConjuntoLogistico` não contém `fator_cubagem` nem `max(array_column`; `NomesSugeridos` não chama `config()`.

## Desvios do plano

Nenhum de comportamento. Os testes passaram na primeira rodada, depois de escritos antes do código, sem uma execução vermelha registrada à parte (as classes não existiam, então o vermelho era implícito).

## Pontos para os planos seguintes

- O porquê do Combo sem tipo e sem `quantidades_do_produto` cai em 'Mesmo produto em mais unidades. Quantidades: ...' (caso não especificado no plano).
- Nenhum stub; nenhuma superfície de ameaça nova.

## Self-Check: PASSED
