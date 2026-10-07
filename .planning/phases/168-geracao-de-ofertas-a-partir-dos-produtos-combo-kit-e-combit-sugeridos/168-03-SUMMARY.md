---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 03
subsystem: portal-estrutura
tags: [geracao, chave-de-composicao, quantidades, variacoes]
requires: [168-01]
provides:
  - ChaveDeComposicao (de, valida, itens)
  - Quantidades (ler, paraTexto, doTipo, efetivas)
  - VariacoesEmParalelo (casar)
affects: [168-07, 168-09, 168-11]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/ChaveDeComposicao.php
    - app/Services/Portal/Estrutura/Geracao/Quantidades.php
    - app/Services/Portal/Estrutura/Geracao/VariacoesEmParalelo.php
    - tests/Unit/PortalEstrutura/Geracao/ChaveDeComposicaoTest.php
    - tests/Unit/PortalEstrutura/Geracao/QuantidadesTest.php
    - tests/Unit/PortalEstrutura/Geracao/VariacoesEmParaleloTest.php
  modified: []
key-decisions:
  - "Quantidade 'nenhuma' é '0' (ConvertEmptyStringsToNull transforma '' em null)"
requirements-completed: [PR168-03, PR168-04, PR168-06]
metrics:
  completed: 2026-10-07
---

# Phase 168 Plan 03: Chave, quantidades e variações em paralelo Summary

Três classes puras (sem banco, config ou relógio) com teste antes do código: chave canônica 'v12*1+v30*4' ordenada por id, leitura de quantidades por tipo/produto com mensagem fixa, e casamento de variações por eixo+valor e depois por posição, nunca cartesiano.

## Resultados

- Os 3 testes juntos: 44 testes, 84 asserções, exit 0.
- Mitigações T-168-06 (regex ancorada com `/D` + teto de 100 caracteres) e T-168-07 (máx. 8 itens, inteiros 2..999) aplicadas.

## Commits

- `1d1392a9` feat(168): chave canônica da composição e leitura de quantidades
- 421f2408 feat(168): variações casam em paralelo, nunca em produto cartesiano

## Deviations from Plan

- Um caso do meu próprio teste ('2,5' como "decimal") estava errado, pois é lista válida (2 e 5); trocado por '2.5'. Sem mudança de código de produção.
- Regex da chave ganhou o modificador `D`, para que `$` não aceite quebra de linha final.

## Self-Check: PASSED
