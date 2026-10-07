---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 02
subsystem: portal-estrutura
tags: [tipo-do-produto, vocabulario, pares, semente]
requires: ["168-01"]
provides:
  - config/estrutura_geracao.php com 26 tipos genéricos, limites e 3 pares seguros
  - TipoDoProduto (normalizar, palavras, inferir, efetivo)
  - lista-semente aprovada (D-21, D-22, D-23) para o 168-06 gravar
affects: [168-06]
key-files:
  created:
    - config/estrutura_geracao.php
    - app/Services/Portal/Estrutura/Geracao/TipoDoProduto.php
    - tests/Unit/PortalEstrutura/Geracao/TipoDoProdutoTest.php
    - tests/Unit/PortalEstrutura/Geracao/CatalogoDaEcfTest.php
  modified: []
key-decisions:
  - "D-21: 18 pares de tipo aprovados; 5 que só aparecem em trios ficam fora"
  - "D-22: quantidades como a planilha usa; SUBSTITUI o D-13 (semente deixa de ser o D-07 literal)"
  - "D-23: palavra bicama em cama; tipo novo beliche"
requirements-completed: [PR168-04, PR168-05, PR168-15]
metrics:
  completed: 2026-10-07
---

# Phase 168 Plan 02: Vocabulário de tipos e lista-semente Summary

Inferência de tipo por palavra-chave (casamento por token inteiro ou plural regular, frase mais longa vence, categoria antes do nome, ambíguo = sem tipo) e lista-semente de pares medida na planilha real e aprovada pelo usuário.

## Tarefas

| Task | Commit | Resultado |
|---|---|---|
| 1 | `37dae2f3` | config (26 tipos, 3 pares seguros), `TipoDoProduto`, 2 testes: 21 testes, 349 asserções, exit 0 |
| 2 | sem commit | roteiro local fora do repo, só contagens; roteiro e saídas apagados |
| 3 | este SUMMARY | decisão do usuário registrada abaixo |

## Cobertura medida (só contagens)

- 70 variações, 56 produtos.
- Antes: 53 com 1 tipo, 0 ambíguos, 3 sem tipo (2 beliches, 1 bicama).
- Depois do D-23 (em memória): 56 de 56.
- Composições: 44 Kits (37 de 2 itens, 7 de 3) e 39 Combits (25 de 2 itens, 14 de 3); nenhuma com produto sem tipo, ambíguo ou referência não resolvida.
- 23 pares de tipo usados; 18 aprovados (os 5 só de trios ficam fora).

## Lista aprovada

**Decisão do usuário, registrada no 168-CONTEXT.md como D-21, D-22 e D-23 (commit `f4df9120`). O D-22 SUBSTITUI o D-13: o 168-06 deve gravar no config/semente as quantidades abaixo, NÃO o D-07 literal (cadeira 2/4/6, banqueta 2/3/4, mesa 0).** O config atual NÃO foi alterado por este plano.

### 1. Pares (D-21), 18

- aparador + mesa — só Kit
- aparador + mesa-centro — só Kit
- aparador + mesa-lateral — repete: mesa-lateral
- aparador + rack — só Kit
- armario + prateleira — só Kit
- banco + mesa — repete: banco
- banqueta + mesa — repete: banqueta
- buffet + cristaleira — só Kit
- buffet + mesa — só Kit
- cabeceira + cama — repete: cabeceira
- cabeceira + criado-mudo — repete: criado-mudo
- cadeira + mesa — repete: cadeira
- cama + cama — só Kit
- cama + criado-mudo — repete: criado-mudo
- comoda + criado-mudo — só Kit
- comoda + guarda-roupa — só Kit
- comoda + prateleira — repete: prateleira
- mesa-centro + mesa-lateral — repete: mesa-lateral

Ficam FORA os 5 que só aparecem em trios: banco+cadeira, buffet+cadeira, cabeceira+comoda, cama+comoda, guarda-roupa+prateleira.

### 2. Quantidades (D-22): `qtd_combo` / `qtd_combit` (item repetido)

| Tipo | qtd_combo | qtd_combit |
|---|---|---|
| cadeira | 2,4,6,8 | 2,4,6 |
| banqueta | 2,3,4 | 2 |
| banco | 2 | 2 |
| prateleira | 2,3 | 2 |
| cabeceira | 2 | 2 |
| criado-mudo | 2 | 2 |
| mesa-lateral | 2 | 2 |
| cama | 2 | nenhum |
| mesa e todos os demais tipos | nenhum | nenhum |

### 3. Tipos (D-23)

- palavra `bicama` no tipo `cama`;
- tipo novo `beliche` (palavras `beliche`, `treliche`), ordem 21.

## Deviations from Plan

None - plano executado como escrito; a única mudança é a decisão do usuário (D-22 no lugar do D-13), que vai para o 168-06.

## Self-Check: PASSED
