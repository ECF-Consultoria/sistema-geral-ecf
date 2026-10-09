---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 01
subsystem: database
tags: [migration, mariadb, publicador, portal-estrutura]
requires: []
provides:
  - estrutura_produto_variacoes.estoque (int unsigned NULL)
  - estrutura_produtos.descricao (text NULL)
  - pub_produtos.estrutura_produto_id (bigint unsigned NULL, unique pubprod_eprod_uq, FK pubprod_eprod_fk SET NULL)
affects: [176-02, 176-03]
key-files:
  created:
    - database/migrations/2026_10_08_150000_add_estoque_to_estrutura_produto_variacoes.php
    - database/migrations/2026_10_08_150100_add_descricao_to_estrutura_produtos.php
    - database/migrations/2026_10_08_150200_add_estrutura_produto_id_to_pub_produtos.php
    - tests/Feature/Publicador/MigracoesDaFase172Test.php
    - .planning/phases/176-ficha-do-portal-completa-ate-o-publicador/176-BASELINE-TESTES.md
  modified:
    - app/Models/EstruturaProdutoVariacao.php
    - app/Models/EstruturaProduto.php
    - tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/SchemaDosProdutosTest.php
decisions:
  - "estoque NULL = não informado, 0 = sem estoque (sem default, cast integer)"
  - "pub_produtos.estrutura_produto_id com SET NULL: apagar o produto do Portal solta o pub_produto e preserva rascunho/histórico"
requirements: [FP176-04]
completed: 2026-10-08
---

# Phase 176 Plan 01: colunas aditivas da ficha completa

Três colunas anuláveis (estoque da variação, descrição do produto e vínculo pub_produto -> produto do Portal com unique e FK SET NULL), provadas no MariaDB 10.4 local por `--path` (ida, volta, ida) e em SQLite de arquivo com guarda.

## Tarefas e commits

| Task | Commit | Conteúdo |
|---|---|---|
| 1 | c3f3297e | `docs(176): baseline de testes antes da fase` (commit só do baseline) |
| 2 | ver `git log` ("feat(176-01): colunas aditivas...") | 3 migrations, fillable/casts, testes de schema |
| 3 | ver `git log` ("docs(176): prova das migrations no MariaDB") | seção "Prova no MariaDB 10.4 (176-01)" |

## Baseline (antes da fase)

G1 567/3264 ok; G2 256/877 ok; G3 217/1416 ok; G4 592/4283 com 14 falhas, todas em `Estrutura/Sugestoes/*` (arquivos em edição pela outra sessão, não desta fase); G5 1303 testes, 2 falhas antigas conhecidas.

## Testes rodados

`MigracoesDaFase172Test` + `MigracoesDaFaseDetectamMariaDbTest` + `SchemaDosProdutosTest`: 22 testes, 97 asserções, OK.

## Deviations from Plan

Nenhuma de regra. Observações:

- O MariaDB local está vazio em `estrutura_produtos`, `estrutura_produto_variacoes`, `pub_produtos` e `pub_rascunhos` (contagens 0 antes e depois), então "contagens iguais" é verdadeira mas pouco informativa; o comportamento com linhas é provado em `MigracoesDaFase172Test`.
- Primeira versão do `guarda-sqlite172.php` tinha erro de sintaxe (heredoc); a guarda bloqueou o encadeado como deveria, e refiz o arquivo. Nesse intervalo só um arquivo SQLite vazio do scratchpad foi tocado.
- Os testes novos passaram já na primeira execução porque as migrations foram escritas antes de rodá-los (sem ciclo RED observado).

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos e commits conferidos; STATE.md e ROADMAP.md intocados.
