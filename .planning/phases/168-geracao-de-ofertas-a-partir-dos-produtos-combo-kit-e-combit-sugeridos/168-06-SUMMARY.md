---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 06
subsystem: portal-estrutura
tags: [schema, migration, semente, mariadb, tipos, pares]
requires: ["168-01", "168-02"]
provides:
  - 4 tabelas novas (tipos, pares, ajuste por produto, descarte), só aditivas
  - semente idempotente de 27 tipos e 18 pares
  - 4 models (EstruturaTipoProduto, EstruturaTipoPar, EstruturaProdutoGeracao, EstruturaSugestaoDescartada)
  - config/estrutura_geracao.php com a lista APROVADA (D-21, D-22, D-23)
affects: [168-07, 168-16]
key-files:
  created:
    - database/migrations/2026_10_07_100000_create_estrutura_geracao_tables.php
    - database/migrations/2026_10_07_100100_semear_estrutura_tipos_e_pares.php
    - app/Models/EstruturaTipoProduto.php
    - app/Models/EstruturaTipoPar.php
    - app/Models/EstruturaProdutoGeracao.php
    - app/Models/EstruturaSugestaoDescartada.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/TiposEParesTest.php
  modified:
    - config/estrutura_geracao.php
    - tests/Unit/PortalEstrutura/Geracao/CatalogoDaEcfTest.php
    - tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php
    - .planning/phases/168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos/168-BASELINE-TESTES.md
key-decisions:
  - "D-22 no lugar do D-13: a semente grava as quantidades como a planilha usa (cadeira combo 2,4,6,8 e combit 2,4,6; banqueta 2,3,4 e 2; banco, cabeceira, criado-mudo, mesa-lateral 2 e 2; prateleira 2,3 e 2; cama combo 2 e combit nenhum; mesa '0' e '0')"
  - "par guardado não ordenado (tipo_a_id <= tipo_b_id); direção do Combit remapeada para a, b ou ambos; par do mesmo tipo (cama + cama) sem direção = só Kit"
  - "ajuste por produto em tabela própria 1:1 (PK produto_id), sem ALTER em tabela da 167"
requirements-completed: [PR168-04, PR168-13]
metrics:
  completed: 2026-10-07
---

# Phase 168 Plan 06: Banco da fase Summary

Quatro tabelas novas, todas aditivas e idempotentes (hasTable, reposição de índice e FK pelo nome, `emMysql()` para mysql e mariadb), com semente de 27 tipos e 18 pares lida do config aprovado; provado no SQLite e no MariaDB 10.4 local com `--path`.

## Tarefas

| Task | Commit | Resultado |
|---|---|---|
| 1 | `6f97d355` | config com a lista aprovada, migration das 4 tabelas, 4 models |
| 2 | `33d96043` | semente por `insertOrIgnore`, `TiposEParesTest` (10 testes), `MigracoesDaFaseDetectamMariaDbTest` com as 2 migrations |
| 3 | `6220c2ca` | prova no MariaDB registrada no baseline |

## Lista aprovada gravada

- 18 pares (D-21), cada um com a direção do Combit; os 5 que só aparecem em trios ficaram fora.
- Quantidades do D-22 (acima); tipo `cama` ganhou a palavra `bicama`; tipo novo `beliche` (palavras beliche e treliche, ordem 21) (D-23).
- Total semeado: 27 tipos (26 + beliche) e 18 pares.

## Testes

- `SchemaDosProdutosTest`, `CatalogoDaEcfTest`, `TipoDoProdutoTest`: 33 testes, exit 0.
- `TiposEParesTest`, `MigracoesDaFaseDetectamMariaDbTest`, `OfertaExcluidaNoPortalTest`, `ExclusaoDaEmpresaPreservaHistoricoTest`: 29 testes, 218 asserções, exit 0 (as FKs novas não quebram a exclusão da empresa).

## Prova no MariaDB 10.4 local

- Só `migrate --path=` e `migrate:rollback --path=`; `migrate:status --path=` antes e depois de cada comando (Pending -> Ran e Ran -> Pending conforme esperado). Nenhum rollback deixou de achar a migration da fase.
- `estrutura_ofertas`: 13 antes, 13 depois.
- Semente re-executada: 27 tipos / 18 pares antes e depois. Ciclo completo (rollback das duas, migrate das duas): sem 1059, 1553 nem 1830; as duas `Ran` ao fim, tabelas aplicadas.
- DDL confirmado: `epg_tipo_fk ... ON DELETE SET NULL`, `epg_produto_fk ... ON DELETE CASCADE`, `tipo_id bigint(20) unsigned DEFAULT NULL`, `etp_slug_uq`, `etpar_uq`, `etpar_b_idx`, `esd_company_chave_uq`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `CatalogoDaEcfTest::test_quantidades_literais` travava o D-13**
- **Found during:** Task 1
- **Issue:** o teste esperava as quantidades antigas (cadeira 2,4,6 etc.), que o D-22 aprovado pelo usuário substitui.
- **Fix:** trocado por `test_quantidades_como_a_planilha_usa` (D-22) e acrescentado `test_lista_aprovada_de_pares_e_tipos` (18 pares, bicama, beliche).
- **Files modified:** tests/Unit/PortalEstrutura/Geracao/CatalogoDaEcfTest.php
- **Commit:** 6f97d355

Fora isso, plano executado como escrito. Nenhum checkpoint, nenhum auth gate.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum: as tabelas novas não abrem endpoint; ajuste e descarte têm FK cascade em `company_id`; tipos e pares são globais, sem dado de cliente.

## Self-Check: PASSED

Arquivos e commits `6f97d355`, `33d96043`, `6220c2ca` conferidos. STATE.md e ROADMAP.md intocados.
