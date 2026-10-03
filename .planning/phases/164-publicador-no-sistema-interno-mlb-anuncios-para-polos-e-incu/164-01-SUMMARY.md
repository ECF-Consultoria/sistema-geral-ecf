---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 01
subsystem: publicador
tags: [migration, mariadb, pub_produtos, backfill, D15, D19, D27]
requires: []
provides:
  - "pub_produtos (âncora de produto do Publicador)"
  - "pub_rascunhos.produto_id NOT NULL único com FK; oferta_id legada dormente"
  - "PubProduto::conta()/ancoraComToken()/daOferta()/skuExibido()/nomeExibido()"
  - "PubRascunho::produto()/conta(); oferta() via hasOneThrough pelo produto"
affects: [164-02, 164-03]
tech-stack:
  added: []
  patterns: ["migration separada para tabela nova e ALTER+backfill", "hasIndex/hasForeignKey cross-driver"]
key-files:
  created:
    - database/migrations/2026_10_02_100000_create_pub_produtos_table.php
    - database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php
    - app/Models/PubProduto.php
    - tests/Feature/Publicador/MigracaoProdutoRascunhoTest.php
    - tests/Feature/Publicador/PubProdutoTest.php
    - .planning/phases/164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu/160-BASELINE-TESTES.md
  modified:
    - app/Models/PubRascunho.php
    - app/Services/Publicador/RascunhoRepository.php
key-decisions:
  - "D27 opção (a): pub_rascunhos.oferta_id vira coluna legada dormente; pubr_oferta_fk/uq intocados"
  - "Ponte: RascunhoRepository::criar(EstruturaOferta) grava produto_id (via PubProduto::daOferta) e ainda grava oferta_id até 164-02"
requirements-completed: [D15, D19, D27, SC5]
duration: n/d
completed: 2026-10-02
---

# Phase 164 Plan 01: pub_produtos e rascunho ligado ao produto Summary

Âncora de produto do Publicador (`pub_produtos`, oferta solta com SET NULL) e `pub_rascunhos.produto_id` NOT NULL com backfill idempotente que move o vínculo da oferta para o produto; baseline gravada antes de mexer e desenho conferido no MariaDB local.

## Commits

- `docs(160): baseline de testes antes de mexer no Publicador` (Tarefa 1) — `5741f5e8`
- `1a220ce6` feat(160): pub_produtos e rascunho ligado ao produto, com backfill dos rascunhos existentes (Tarefa 2)
- Tarefa 3: sem commit de código (evidência abaixo)

## Baseline (antes de mexer, HEAD b3c5cc53)

| Grupo | Testes | Asserções | Falhas |
|---|---|---|---|
| Publicador (Unit+Feature) | 230 | 952 | 0 |
| PortalCliente | 240 | 2123 | 0 |
| Phase75 | 43 | 151 | 0 |
| Phase76 | 23 | 103 | 0 |
| Phase77 | 33 | 92 | 0 |
| Phase134 | 24 | 107 | 0 |
| Soltos (IA, listagem, token) | 52 | 189 | 0 |
| test:js | 476 | n/d | 2 |

Falhas pré-existentes (test:js, não relacionadas): "Características secundárias nasce recolhido (é o grupo que mais infla)" e "FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha" (código tem 'Desistência', teste não).

## Depois da mudança

- `MigracaoProdutoRascunhoTest` + `PubProdutoTest`: 13 testes, 39 asserções, verde.
- Publicador (Unit+Feature): 243 testes, 991 asserções, verde (230 + 13).
- `tests/Feature/PortalCliente/Estrutura`: 97 testes, 1060 asserções, verde.

## Conferência no MariaDB local (Tarefa 3)

`2026_10_01_200000_create_publicador_tables` estava `Ran`. Rodados SÓ os dois `--path`; ambas `DONE` e `Ran` no `migrate:status` ([123] e [124]).

- `SHOW INDEX FROM pub_rascunhos`: `PRIMARY`, `pubr_produto_uq(produto_id)`, `pubr_oferta_uq(oferta_id)` (Null=YES).
- `SHOW INDEX FROM pub_produtos`: `pubprod_oferta_uq`, `pubprod_empresa_sku_ix(mlb_empresa_id, sku)`, `pubprod_company_ix`.
- `DELETE_RULE`: `pubprod_oferta_fk` = SET NULL; `pubprod_company_fk` = CASCADE; `pubprod_empresa_fk` = CASCADE; `pubr_oferta_fk` = CASCADE (intocada); `pubr_produto_fk` = CASCADE.
- information_schema: `produto_id` NOT NULL `bigint(20) unsigned`; `oferta_id` NULLABLE `bigint(20) unsigned`.
- `produto_id IS NULL` = 0; `oferta_id IS NOT NULL` = 0; `pub_rascunhos` = 0 linhas; `pub_produtos` = 0 linhas.

Nota: o MariaDB local não tinha rascunhos, então o backfill foi exercitado só no SQLite dos testes (não no MariaDB). O MariaDB local passou a ter `pub_rascunhos.produto_id NOT NULL`; checkouts antigos do piloto que criem rascunho sem produto quebram localmente; rascunhos legados (em produção) ficam com `oferta_id` NULL e só voltam a abrir pelo piloto do Portal depois de 164-02 Tarefa 1 (D27).

## Deviations from Plan

**1. [Rule 3 - Bloqueio] Teste de migration sem trait `DatabaseMigrations`**
- **Found during:** Tarefa 2
- **Issue:** `DatabaseMigrations` roda `migrate:rollback` no teardown e o `down()` da migration antiga `2026_09_14_100000_add_parent_id_to_company_groups_table` não roda em SQLite ("dropping foreign keys by name"), derrubando todos os testes.
- **Fix:** `MigracaoProdutoRascunhoTest` sem trait, com `$this->artisan('migrate')` no `setUp` (cada teste tem seu `:memory:`).
- **Files modified:** tests/Feature/Publicador/MigracaoProdutoRascunhoTest.php

**2. [Rule 3 - Bloqueio] `down()` da B: drop de FK por driver**
- **Issue:** SQLite não dropa FK por nome (e `dropColumn` falha com FK viva).
- **Fix:** `dropForeign('pubr_produto_fk')` no MariaDB e `dropForeign(['produto_id'])` (forma por coluna) no SQLite; a checagem `hasForeignKey` usa `PRAGMA foreign_key_list` no SQLite. Caminho verificado em MariaDB é o `up()`; o `down()` em MariaDB não foi executado (proibido rollback).

## Known Stubs

Nenhum.

## Threat Flags

Nenhum.

## Self-Check: PASSED

Arquivos criados presentes, commits `1a220ce6` e o da baseline existem, STATE.md e ROADMAP.md não tocados.
