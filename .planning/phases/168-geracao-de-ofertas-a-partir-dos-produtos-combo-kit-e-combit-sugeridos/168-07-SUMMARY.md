---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 07
subsystem: portal-estrutura
tags: [gerador, combo, kit, combit, funcao-pura]
requires: ["168-03", "168-04"]
provides:
  - "GeradorDeSugestoes::gerar(array $retrato): list<array>"
affects: ["168-08", "168-10", "168-11"]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/GeradorDeSugestoes.php
    - tests/Unit/PortalEstrutura/Geracao/GeradorDeSugestoesTest.php
    - tests/Unit/PortalEstrutura/Geracao/GeradorRegrasDurasTest.php
    - tests/Unit/PortalEstrutura/Geracao/GabaritoDaGeracaoTest.php
key-decisions:
  - "Gerador 100% puro (sem DB/config/relógio); ordem estável: família normalizada, fase, chave"
  - "Combo usa Quantidades::efetivas do produto sobre o tipo; Combit só repete o lado dirigido pelo par"
requirements-completed: [PR168-01, PR168-02, PR168-03, PR168-15]
duration: ~25min
completed: 2026-10-07
---

# Phase 168 Plan 07: Gerador puro e gabarito sintético — Summary

`GeradorDeSugestoes::gerar($retrato)` decide Combo/Kit/Combit com as regras duras D-05..D-17 (família não nula, ambiente em comum, par de tipos na lista, Combit dirigido, só 2 itens, variações em paralelo, existente some e descartada volta marcada), e o gabarito sintético fixa 15 Combo, 5 Kit e 8 Combit (1 descartado).

## Commits

- `a787a27f` feat(168): gerador puro de sugestões com regras duras (gerador + 27 testes)
- `bdcbb729` test(168): gabarito sintético da geração: 7 testes do gabarito

## Verificação

- Rodada de entrada `tests/Unit/PortalEstrutura`: 153 testes, exit 0.
- `tests/Unit/PortalEstrutura/Geracao` ao final: 125 testes, 995 asserções, exit 0.
- O gerador não contém `config(`, `DB::`, `now(` nem `::query(`.

## Deviations from Plan

None - plano executado como escrito. Dois testes da T1 falharam na primeira rodada por erro do próprio teste (id digitado errado e sobrescrita de `par` aplicada também ao Combo); o gerador não mudou.

## Observações para os próximos planos

- Retrato `ambientes` é `id => nome`; a saída traz `ambientes` como lista de nomes em ordem alfabética (comuns, no Kit/Combit).
- `par` na saída é o par da lista como cadastrado (a/b/repete), não reorientado; `tipos` vem na ordem de orientação (ordem do tipo).
- Itens do Combit seguem a ordem do par orientado (não "fixo primeiro"); a chave é ordenada por variação de qualquer forma.
- Gabarito é sintético; a planilha real não foi lida.

## Self-Check: PASSED
