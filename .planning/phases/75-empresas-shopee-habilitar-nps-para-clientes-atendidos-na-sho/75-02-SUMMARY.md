---
phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
plan: 02
subsystem: auth
tags: [permissions, rbac, shopee, catalog, phpunit]

# Grafo de dependências
requires:
  - phase: 75-01
    provides: "Constante Servico::SETOR_SHOPEE + serviço 'Shopee' semeado (gatilho da aba)"
provides:
  - "Permission key 'shopee.empresas' no catálogo estático Permissions"
  - "Grupo 'Shopee' em Permissions::catalog(), agregado por all()/isValid()"
  - "Gate único da aba Empresas da Shopee (rota + menu), herdado por admin e atribuível ao Setor Shopee"
affects: [75-04 rotas shopee.empresas.*, 75-05 menu AppLayout, SetorController]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Registro de key: constante SCREAMING_SNAKE_CASE + entrada no catalog(); all()/isValid() agregam sozinhos"

key-files:
  created:
    - tests/Feature/Phase75/Phase75PermissaoCatalogoTest.php
  modified:
    - app/Support/Permissions.php

key-decisions:
  - "Key granular shopee.empresas — NÃO reusa core.empresas (T-75-04): não concede acesso ao CRUD core"
  - "Admin herda a key via short-circuit isAdmin() — nenhuma concessão manual necessária"

patterns-established:
  - "Grupo 'Shopee' no catálogo espelhando o shape de 'Publicações (MLB)'"

requirements-completed: [DEC-3]

# Métricas
duration: 2min
completed: 2026-07-14
---

# Phase 75 Plan 02: Permission key `shopee.empresas` Summary

**Registra a permission key `shopee.empresas` sob um novo grupo "Shopee" no catálogo estático `Permissions`, criando o gate único da aba Empresas da Shopee — herdado por admin e atribuível ao Setor Shopee.**

## Performance

- **Duration:** ~2 min
- **Started:** 2026-07-14T14:02:18Z
- **Completed:** 2026-07-14T14:04:23Z
- **Tasks:** 2
- **Files modified:** 2 (1 criado, 1 modificado)

## Accomplishments
- Constante `Permissions::SHOPEE_EMPRESAS = 'shopee.empresas'` adicionada após as constantes `MLB_*`.
- Novo grupo `'Shopee'` em `catalog()` com a entrada `Shopee · Empresas`, agregado automaticamente por `all()`/`isValid()`.
- Legenda de prefixos do docblock atualizada com `shopee.*`.
- Suíte `Phase75PermissaoCatalogoTest` (3 casos) provando catálogo, agregação e herança de admin — verde.

## Task Commits

Cada tarefa comitada atomicamente (fluxo TDD):

1. **Tarefa 1: Teste do catálogo (RED)** - `d08382a` (test)
2. **Tarefa 2: Constante + grupo "Shopee" (GREEN)** - `2f15b77` (feat)

## Files Created/Modified
- `tests/Feature/Phase75/Phase75PermissaoCatalogoTest.php` - 3 testes: all()/isValid(), grupo no catalog(), herança de admin.
- `app/Support/Permissions.php` - constante `SHOPEE_EMPRESAS`, grupo `'Shopee'` no `catalog()`, legenda de prefixos.

## Decisions Made
- Nenhuma decisão nova — seguiu o plano e as DEC-3/DEC-4. Key granular `shopee.empresas` deliberadamente separada de `core.empresas` (mitiga T-75-04, Elevation of Privilege).

## Deviations from Plan
None - plan executed exactly as written.

(Ajuste menor não-estrutural: legenda de prefixos do docblock recebeu a linha `shopee.*` para manter a documentação coerente — dentro do escopo da tarefa.)

## Issues Encountered
- `php` não estava no PATH; usado o binário do XAMPP em `/c/xampp/php/php.exe` para rodar `artisan test`. Sem impacto no resultado.

## Threat Model Compliance
- **T-75-04 (Elevation of Privilege):** mitigado — key granular não reusa `core.empresas`; nenhum acesso ao CRUD core concedido.
- **T-75-05 (Spoofing):** coberto — `isValid()` valida contra `all()`, que agrega a nova key automaticamente.

## User Setup Required
None - sem configuração de serviço externo. O usuário criará o Setor Shopee manualmente via `/setores` e poderá atribuir a key `shopee.empresas`.

## Next Phase Readiness
- Key disponível para os gates de rota (Plan 04) e do menu (Plan 05).
- Admin já vê a aba automaticamente; Setor Shopee poderá receber a key.

## Self-Check: PASSED

---
*Phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho*
*Completed: 2026-07-14*
