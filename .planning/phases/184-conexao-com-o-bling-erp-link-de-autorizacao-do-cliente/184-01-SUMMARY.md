---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 01
subsystem: Bling ERP — base de dados e configuração
tags: [bling, oauth, migration, mariadb, config]
requires: []
provides:
  - tabela bling_conexoes (uma conexão por empresa, token cifrado em repouso)
  - tabela bling_vinculos (produto/variação do Bling ↔ variação do Portal)
  - model BlingConexao (cast encrypted, $hidden, estaConfigurado())
  - model BlingVinculo (constantes de situação e formato)
  - config/services.php: entrada 'bling' (client_id/client_secret/redirect)
affects:
  - database/migrations
  - app/Models
  - config/services.php
  - .env.example
tech-stack:
  added: []
  patterns:
    - "Token OAuth guardado com cast encrypted + $hidden, copiado literalmente de MlToken"
    - "Migration idempotente (hasTable) provada no MariaDB local, não só no SQLite"
key-files:
  created:
    - database/migrations/2026_10_13_100000_create_bling_conexoes_table.php
    - database/migrations/2026_10_13_100100_create_bling_vinculos_table.php
    - app/Models/BlingConexao.php
    - app/Models/BlingVinculo.php
    - tests/Feature/Bling/BlingEsquemaTest.php
  modified:
    - config/services.php
    - .env.example
decisions:
  - "Molde de token copiado literalmente de MlToken (cast encrypted + $hidden), sem desenho novo"
  - "dono_estoque_ml nasce nesta fase com default 'nenhum', sem consumidor — preparo para a Fase 3 do seed"
  - "bling_produto_id nasce em bling_vinculos porque a Fase 185 precisa dele para o kit (D-04)"
metrics:
  duration: "~35 min"
  completed: 2026-10-13
---

# Phase 184 Plan 01: Base de dados e configuração do Bling ERP Summary

Duas tabelas novas (`bling_conexoes`, `bling_vinculos`), dois models com token cifrado em repouso
(molde copiado de `MlToken`), e a entrada `bling` em `config/services.php` com degradação explícita
quando o `.env` está vazio — sem nenhuma chamada à API real do Bling.

## O que foi entregue

1. **Migration `bling_conexoes`** (BLING-01): uma conexão por empresa (`unique company_id`), seis
   valores de `status` (incluindo `aguardando_cliente` da D-01), tokens em `TEXT`, e `conectado_por`
   com `nullable()` explícito antes de `nullOnDelete()`. Provada no MariaDB local: `migrate` →
   `rollback` → `migrate`, os três exit code 0.
2. **Migration `bling_vinculos`** (BLING-02): vínculo pelo `bling_produto_id` (nunca pelo SKU),
   `variacao_id` nullable antes de `nullOnDelete()`, e os quatro índices nomeados. O unique
   `(company_id, bling_produto_id)` foi provado disparando o erro **1062** no MariaDB local (linha de
   prova inserida e apagada na mesma sessão).
3. **`BlingConexao`** (BLING-03): `$casts` com `'access_token' => 'encrypted'` e
   `'refresh_token' => 'encrypted'`, `$hidden` nos dois, e `estaConfigurado(): bool` que lê
   `services.bling.client_id/client_secret/redirect` sem lançar exceção quando faltam.
4. **`BlingVinculo`**: constantes `SITUACAO_*` e `FORMATO_*`, relação `variacao()` →
   `EstruturaProdutoVariacao`.
5. **`config/services.php`** (BLING-04): entrada `'bling'` com as três chaves via `env()`, sem valor
   padrão (credencial nunca versionada).
6. **`.env.example`**: bloco `BLING_CLIENT_ID`/`BLING_CLIENT_SECRET`/`BLING_REDIRECT_URI`, as três
   vazias, com o caminho no painel do Bling e o aviso de "um callback por app".
7. **`tests/Feature/Bling/BlingEsquemaTest.php`**: 5 casos — linha crua sem o token em claro
   (`DB::table`, asserção negativa), cast nos dois sentidos pelo model, `toArray()` sem os tokens, e
   `estaConfigurado()` nos dois estados (`false` sem exceção / `true`).

## Prova no MariaDB local

`bling_conexoes` — `SHOW CREATE TABLE`:
```
UNIQUE KEY `blcon_company_uq` (`company_id`),
KEY `bling_conexoes_conectado_por_foreign` (`conectado_por`),
KEY `blcon_bling_emp_idx` (`bling_empresa_id`),
KEY `blcon_status_idx` (`status`),
CONSTRAINT `bling_conexoes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
CONSTRAINT `bling_conexoes_conectado_por_foreign` FOREIGN KEY (`conectado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
```
`access_token` e `refresh_token` confirmados como `text` (não `varchar`).

`bling_vinculos` — `SHOW CREATE TABLE`:
```
UNIQUE KEY `blvin_company_prod_uq` (`company_id`,`bling_produto_id`),
UNIQUE KEY `blvin_variacao_uq` (`variacao_id`),
KEY `blvin_pai_idx` (`bling_pai_id`),
KEY `blvin_situacao_idx` (`situacao`),
CONSTRAINT `bling_vinculos_variacao_id_foreign` FOREIGN KEY (`variacao_id`) REFERENCES `estrutura_produto_variacoes` (`id`) ON DELETE SET NULL
```
Inserir duas linhas com o mesmo par `(company_id, bling_produto_id)` devolveu
`SQLSTATE[23000]: ... 1062 Duplicate entry '16-teste-1062' for key 'blvin_company_prod_uq'` — linha de
prova apagada em seguida (0 restantes).

`artisan migrate:status`: as duas migrations em `Ran`, nenhuma `Pending`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - bloqueio] Alinhamento de `$casts` quebrava o gate literal do plano**
- **Encontrado durante:** Task 3
- **Problema:** segui a convenção do projeto de alinhar `=>` em colunas (como em `MlToken` e em
  outros models), mas o `acceptance_criteria` da Task 3 exige o grep literal
  `grep -n "'access_token' => 'encrypted'" app/Models/BlingConexao.php` — com alinhamento de múltiplos
  espaços o grep não casa.
- **Fix:** removido o alinhamento em `$casts` de `BlingConexao` (uma única linha por chave, um espaço
  antes/depois de `=>`), mantendo o `$fillable` e o resto do arquivo no padrão normal do projeto.
- **Arquivos modificados:** `app/Models/BlingConexao.php`
- **Commit:** `46886c88` (mesma task, antes do commit final)

Nenhum outro desvio. Plano executado como escrito.

## Known Stubs

Nenhum. Esta tarefa só cria schema, models e config — não há UI nem fluxo com dado vazio renderizado.

## Self-Check: PASSED

Arquivos:
- FOUND: database/migrations/2026_10_13_100000_create_bling_conexoes_table.php
- FOUND: database/migrations/2026_10_13_100100_create_bling_vinculos_table.php
- FOUND: app/Models/BlingConexao.php
- FOUND: app/Models/BlingVinculo.php
- FOUND: tests/Feature/Bling/BlingEsquemaTest.php

Commits:
- FOUND: 19f873b0 (Task 1 — migration bling_conexoes)
- FOUND: 8f1dfc67 (Task 2 — migration bling_vinculos)
- FOUND: 46886c88 (Task 3 — models, config, .env.example, teste)

`artisan test --filter=Bling`: 5 passed, exit 0.
`git diff --stat 092f43a2..HEAD -- database/migrations app/Models config tests/Feature/Bling`: 6
arquivos, 422 inserções, 0 deleções — nenhum arquivo fora do escopo da Task 3.
