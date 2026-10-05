---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 01
subsystem: portal-cliente / mapeamento-estrutural
tags: [schema, migration, mariadb, estrutura, produtos]
requires: []
provides:
  - 6 tabelas estrutura_* do catálogo de produtos (famílias, ambientes, produtos, pivot, variações, volumes)
  - estrutura_ofertas.variacao_id (nullable, unique, FK SET NULL)
  - models EstruturaFamilia, EstruturaAmbiente, EstruturaProduto, EstruturaProdutoVariacao (EIXOS), EstruturaProdutoVolume
affects: [167-02..167-17]
tech-stack:
  added: []
  patterns: [migration idempotente com emMysql/hasIndex/hasForeignKey, nomes de índice/FK curtos e explícitos]
key-files:
  created:
    - .planning/phases/167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas/167-BASELINE-TESTES.md
    - database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php
    - database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php
    - app/Models/EstruturaFamilia.php
    - app/Models/EstruturaAmbiente.php
    - app/Models/EstruturaProduto.php
    - app/Models/EstruturaProdutoVariacao.php
    - app/Models/EstruturaProdutoVolume.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/SchemaDosProdutosTest.php
  modified:
    - app/Models/EstruturaOferta.php
    - tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php
key-decisions:
  - "FK eo_variacao_fk com nullOnDelete (coluna nullable, sem 1830; restrict daria 1451 na cascata de Company)"
  - "company_id denormalizado em estrutura_produto_variacoes para o unique (company_id, codigo) por empresa"
  - "Peso total e nº de volumes derivam dos volumes, não são colunas"
requirements-completed: [PR167-14, PR167-05, PR167-02]
duration: ~35min
completed: 2026-10-05
---

# Phase 167 Plan 01: Fundação do catálogo de produtos Summary

Seis tabelas novas do catálogo de produtos do Mapeamento mais o único ALTER em tabela viva (`estrutura_ofertas.variacao_id`, nullable, sem backfill), provado no MariaDB 10.4 local com rollback e re-up.

## Baseline (commit `a4e86b1b`, sozinho, antes de qualquer código)

| Grupo | Testes / asserções | Falhas |
|---|---|---|
| G1 `PortalCliente/Estrutura` | 83 / 724 | 0 |
| G2 Publicador (4 arquivos) | 22 / 118 | 0 |
| G3 Domínio + PortalSemAnunciar | 7 / 155 | 0 |
| G4 `tests/Feature/PortalCliente` | 231 / 1872 | 0 |
| G5 Exclusão/Oferta/MariaDb | 15 / 84 | 0 |
| G6 `test:js` | 958 | 2 pré-existentes (Características secundárias; FASES_TERMINAIS) |

## Commits

- `a4e86b1b` docs(167): baseline dos testes antes da fase
- `0acea532` feat(167): tabelas do catálogo de produtos do Mapeamento
- `2a3e8339` feat(167): estrutura_ofertas ganha o vínculo com a variação do produto

## Prova no MariaDB 10.4 local

`estrutura_ofertas` com 13 linhas antes e depois, 0 com `variacao_id`. `SHOW CREATE TABLE` mostra `UNIQUE KEY eo_variacao_uq` e `CONSTRAINT eo_variacao_fk ... ON DELETE SET NULL`; `eo_company_*` intactos. Rollback do ALTER e da criação e re-up das duas: DONE, sem 1059/1830/1553. Detalhe na seção própria do baseline. Tabelas ficam aplicadas no banco local.

## Verificação

`SchemaDosProdutosTest` (10 testes) + `MigracoesDaFaseDetectamMariaDbTest` (3) verdes; `PortalCliente/Estrutura` com 93+ testes, 0 falha (era 83: +10 do teste novo).

## Deviations from Plan

**1. [Rule 3 - Bloqueio, só no teste] drop/recreate de `estrutura_ofertas` no SQLite com FK ligada**
- O teste down()/up() falhava (`drop table estrutura_ofertas` com FK de componentes apontando). Corrigido com `PRAGMA defer_foreign_keys = ON` dentro do teste (a transação do RefreshDatabase torna `disableForeignKeyConstraints` inócuo). A migration não mudou; no MariaDB o DDL é in-place.
- Comentário da migration reescrito para não conter a palavra "try" (critério de aceite por grep).

Fora isso, plano executado como escrito.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do threat_model do plano (T-167-01..03 mitigados: nullable sem backfill + prova MariaDB; nullOnDelete; unique por empresa testado com duas empresas).

## Self-Check: PASSED
