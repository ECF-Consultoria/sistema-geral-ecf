---
phase: 77-setor-shopee-organizacional-cargos-e-usu-rios-felipe-gustavo
plan: 01
subsystem: RBAC (setores/cargos/permissões)
tags: [migration, seed, rbac, shopee, idempotente, sqlite]
requires: [tabelas setores/cargos/user_setores/setor_lideres/setor_permissoes, App\Support\Permissions::SHOPEE_EMPRESAS]
provides: [Setor RBAC 'shopee', cargos analista/estrategista Shopee, permissão shopee.empresas no setor, wiring Felipe/Gustavo por email]
affects: [Fase 78 (Comercial/aba Shopee), Fases 79/80 (NPS/bônus por serviço)]
tech-stack:
  added: []
  patterns: [migration anônima DB::table idempotente + cross-driver, guardas ->value/->exists, wiring por email com skip+Log::warning]
key-files:
  created:
    - database/migrations/2026_07_14_120000_seed_setor_shopee_e_usuarios.php
    - tests/Feature/V16/SetorShopeeSeedTest.php
  modified: []
decisions: [DEC-77-1, DEC-77-2, DEC-77-3, DEC-77-4]
metrics:
  duration: ~15min
  completed: 2026-07-14
---

# Phase 77 Plan 01: Setor Shopee organizacional — cargos e usuários (Felipe/Gustavo) Summary

Seed idempotente do Setor organizacional "Shopee" (RBAC — tabela `setores`, eixo distinto do `servico.setor='shopee'`) com cargos `analista`/`estrategista`, permissão exclusiva `shopee.empresas`, e wiring por email de Felipe (estrategista + líder Shopee) e Gustavo (analista Shopee + Performance) — tudo via 1 migration cross-driver, provado por suite Feature SQLite verde (9 testes, 33 assertions).

## O que foi construído

- **Migration `2026_07_14_120000_seed_setor_shopee_e_usuarios.php`** (classe anônima, comentários pt-BR, dividers `// ─── ───`):
  - ETAPA 1 (DEC-77-1): setor `shopee` (nome 'Shopee', active, is_system=false) via `->value('id')`/`insertGetId`.
  - ETAPA 2 (DEC-77-2): cargos `estrategista` (ordem 1) e `analista` (ordem 0) escopados por `setor_id` (unique setor_id+slug — duplicar slugs do Performance é esperado).
  - ETAPA 3 (DEC-77-3): vincula SOMENTE `Permissions::SHOPEE_EMPRESAS` em `setor_permissoes` via `->exists()`.
  - ETAPA 4 (DEC-77-4): closures internas (`resolverUserId` com skip+`Log::warning`, `garantirUserSetor` idempotente por par, `temPrincipal`). Felipe → estrategista + `setor_lideres` (is_principal condicional W1). Gustavo → analista Shopee (is_principal=false) + analista Performance somente se setor+cargo Performance existirem (Performance vira principal se Gustavo for novo).
  - `down()`: deletes explícitos dos filhos por `setor_id=Shopee` (setor_lideres → user_setores → setor_permissoes → cargos → setores), nunca tocando o Performance (W3).

- **Suite `tests/Feature/V16/SetorShopeeSeedTest.php`** (namespace `Tests\Feature\V16`, RefreshDatabase, 9 testes):
  1. setor+cargos exatos; 2. permissão exclusiva (T-77-01, count=1); 3. wiring Felipe + `isLider()` + invariante ≤1 principal (W1); 3b. Felipe não rouba principal existente (W1); 4. Gustavo Shopee+Performance; 5. Gustavo sem Performance não cria linha extra; 6. skip gracioso sem emails; 7. idempotência 2x; 8. down() preserva membership Performance pré-existente (T-77-03).

## Decisões de implementação

- **is_principal Gustavo (Claude's Discretion):** Shopee sempre `false`; Performance vira principal apenas quando Gustavo é novo (sem principal prévio) e o setor/cargo Performance existem.
- **Busca Performance por slug** (`slug='performance'` + cargo `slug='analista'`), sem validar `active` — coerente com o escopo mínimo da fase.
- `garantirUserSetor` em linha existente só realinha `cargo_id` (nunca mexe em `is_principal`), preservando o invariante W1 na re-execução.

## Verificações

- `php artisan test tests/Feature/V16/SetorShopeeSeedTest.php` → 9 passed (33 assertions). ✅
- `php artisan migrate:status` → indisponível no MariaDB local (Connection refused, esperado por nota crítica W2); prova de up()/down() feita via SQLite RefreshDatabase.
- Grep `shopee.empresas`/`SHOPEE_EMPRESAS` na migration → vinculação presente (linhas 92/98). ✅
- `php -l` na migration → No syntax errors. ✅

## Deviations from Plan

None — plano executado exatamente como escrito. A única divergência de contagem: a suite reporta 9 testes (não 8) porque o teste 3b (`test_felipe_nao_rouba_principal`) foi implementado como método próprio, conforme especificado no plano (item 3b).

## Threat Flags

Nenhuma superfície de segurança nova além da já mapeada no `<threat_model>` (T-77-01 a T-77-04 mitigadas por teste; T-77-SC sem pacotes novos).

## Backend-only

Sem `npm run build`, sem deploy nesta fase (conforme constraints).

## Self-Check: PASSED

- Arquivos: migration, teste e SUMMARY presentes.
- Commits 6d1d47e (feat) e 4e4411a (test) encontrados no histórico.
