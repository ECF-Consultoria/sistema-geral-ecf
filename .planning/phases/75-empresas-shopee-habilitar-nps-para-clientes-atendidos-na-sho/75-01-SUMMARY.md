---
phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
plan: 01
subsystem: database
tags: [laravel, migration, enum, sqlite, mysql, seed, servico, shopee]

# Dependency graph
requires:
  - phase: 37-*
    provides: coluna servicos.setor + constantes SETOR_* + scope porSetor (molde de setor por contrato de serviço)
provides:
  - "Valor 'shopee' aceito no enum servicos.setor (MySQL) e na coluna (SQLite tests)"
  - "Constante Servico::SETOR_SHOPEE + entrada em SETORES + label pt-BR + helper isShopee()"
  - "Serviço 'Shopee' ativo semeado idempotentemente no catálogo"
affects:
  - "75-02+ (ShopeeEmpresasController filtra por contrato de setor shopee)"
  - "ComercialController (serviço Shopee aparece no wizard)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migração de enum cross-driver: MySQL ALTER MODIFY ENUM; SQLite string sem CHECK"
    - "Seed de catálogo idempotente via firstOrCreate por nome"

key-files:
  created:
    - database/migrations/2026_07_14_100001_add_shopee_to_servicos_setor_enum.php
    - database/migrations/2026_07_14_100002_seed_servico_shopee.php
    - tests/Feature/Phase75/Phase75MigracaoSeedTest.php
  modified:
    - app/Models/Servico.php

key-decisions:
  - "Branch SQLite do enum usa string()->change() sem CHECK (encerra a classe de bug polos/shopee/próximos marketplaces)"
  - "Seed via firstOrCreate por nome (NÃO updateOrCreate) para preservar valor_padrao editado via UI"
  - "Timestamp do seed (100002) > enum (100001) para evitar Data truncated no deploy MySQL"

patterns-established:
  - "Enum widening cross-driver: split por getDriverName() — nunca pular o SQLite quando testes precisam persistir o valor"
  - "Seed de serviço idempotente com nome exato para não casar prefixos ML de servicoDisparaImplementacao"

requirements-completed: [DEC-1]

# Metrics
duration: ~12min
completed: 2026-07-14
---

# Phase 75 Plan 01: Fundação de dados do setor Shopee Summary

**Enum servicos.setor aceita 'shopee' em MySQL e SQLite, constante Servico::SETOR_SHOPEE exposta, e serviço "Shopee" semeado idempotentemente no catálogo — a raiz que torna "empresa atendida na Shopee" representável via contrato de serviço.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-07-14
- **Completed:** 2026-07-14
- **Tasks:** 3
- **Files modified:** 4

## Accomplishments
- Migração de enum cross-driver que **NÃO pula o SQLite** (desvio deliberado do analog de 'polos'), provando empiricamente o fix do Pitfall 1 (SQLite enforça o CHECK constraint).
- `Servico::SETOR_SHOPEE` + entrada em `SETORES` + label pt-BR "Shopee" + helper `isShopee()`.
- Migração de seed idempotente do serviço "Shopee" (`firstOrCreate` por nome), com timestamp posterior ao enum (Pitfall 2).
- Suíte `Phase75MigracaoSeedTest` verde (3 casos, 9 asserções): persistência cross-driver, idempotência do seed e exposição da constante/label.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Teste RED de migração + seed** - `b350745` (test)
2. **Tarefa 2: Constante SETOR_SHOPEE + migração de enum cross-driver** - `df163bb` (feat)
3. **Tarefa 3: Migração de seed idempotente do serviço "Shopee"** - `5dbbdca` (feat)

_Nota: Tarefa 1 é o RED do ciclo TDD; Tarefas 2 e 3 são o GREEN incremental (A+C na 2, B na 3)._

## Files Created/Modified
- `database/migrations/2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` - Adiciona 'shopee' ao enum (MySQL ALTER MODIFY; SQLite string sem CHECK).
- `database/migrations/2026_07_14_100002_seed_servico_shopee.php` - Semeia o serviço "Shopee" ativo idempotentemente (firstOrCreate por nome).
- `app/Models/Servico.php` - Constante `SETOR_SHOPEE`, entrada em `SETORES`, label pt-BR e helper `isShopee()`.
- `tests/Feature/Phase75/Phase75MigracaoSeedTest.php` - Prova cross-driver do enum + idempotência do seed + constante/label.

## Decisions Made
- **Branch SQLite via `string()->change()` sem CHECK** (decisão travada do planner, RESEARCH Open Question 1): encerra de vez a classe de bug polos/shopee/próximos marketplaces; a paridade de domínio com produção fica garantida pelo enum MySQL. Validado rodando o teste ANTES de seguir, conforme exigido.
- **`firstOrCreate` (não `updateOrCreate`)**: preserva `valor_padrao` editado manualmente via UI em re-runs.
- **Ordem de migrações**: seed (100002) depois do enum (100001) — confirmado com `migrate:fresh` em SQLite (exit 0, ambas DONE na ordem correta).

## Deviations from Plan

None - plan executado exatamente como escrito. O "desvio" do branch SQLite já estava travado no próprio plano (decisão do planner), não é uma deviation de execução.

## Issues Encountered
- `php artisan migrate:fresh --env=testing` caiu no MySQL local (127.0.0.1:3306), que está indisponível (MariaDB local corrompido — nota de memória do projeto). Contornado provando a ordem enum→seed com `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate:fresh` (exit 0) e pela suíte `RefreshDatabase` (que roda 100% em SQLite `:memory:` via phpunit.xml). Nenhum impacto no resultado.

## User Setup Required
None - nenhuma configuração de serviço externo. Em produção (MySQL), a migração de enum roda via `migrate --force` no deploy (fora de escopo desta plan — sem deploy).

## Next Phase Readiness
- Fundação de dados pronta: empresas Shopee já são representáveis via contrato de serviço de setor `shopee`.
- Próximos plans desta phase (controller/aba/rotas/permission/menu Shopee) podem filtrar por `Servico::SETOR_SHOPEE` e o serviço já aparece no wizard do Comercial.

## Self-Check: PASSED

- Todos os 4 arquivos criados/modificados existem em disco.
- Todos os 3 commits de tarefa (`b350745`, `df163bb`, `5dbbdca`) existem no histórico.

---
*Phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho*
*Completed: 2026-07-14*
