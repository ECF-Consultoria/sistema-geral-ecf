---
phase: 77
slug: setor-shopee-organizacional-cargos-e-usu-rios-felipe-gustavo
status: passed
verified: 2026-07-14
---

# Phase 77 — VERIFICATION

**Status: passed** (goal comprovado; verificação manual de dados reais Felipe/Gustavo em prod pós-deploy).

## Goal
Setor org "Shopee" (RBAC) com cargos analista/estrategista + permission `shopee.empresas`; Felipe (`consultor.02@ecfconsultoria.com.br`) estrategista + líder Shopee; Gustavo (`suporte.11@ecfconsultoria.com.br`) analista Shopee + Performance — via migration idempotente que pula usuários ausentes. ✅

## Evidências (DEC-77-1..4)
- **DEC-77-1** — migration idempotente `2026_07_14_120000_seed_setor_shopee_e_usuarios.php` (DB::table + guardas ->value/->exists). `down()` com deletes explícitos dos filhos por `setor_id` (W3). Idempotência provada (rodar 2× não duplica). ✅
- **DEC-77-2** — cargos `analista`/`estrategista` por `setor_id+slug` (duplicam Performance, esperado). ✅
- **DEC-77-3** — só `shopee.empresas` no setor (assert count exato = 1; T-77-01). ✅
- **DEC-77-4** — wiring por email idempotente: Felipe estrategista + líder (`isLider` true), `is_principal` condicional (W1 — não rouba principal existente, provado por `test_felipe_nao_rouba_principal`); Gustavo analista Shopee + Performance (só se existir); skip+`Log::warning` sem os emails. ✅

## Testes
`tests/Feature/V16/SetorShopeeSeedTest.php` → **9 passed (33 assertions)** (SQLite :memory:). Cobre: criação, permissão exclusiva, wiring Felipe/Gustavo, ≤1 principal, skip gracioso, idempotência 2×, down() preserva Performance.

## Contenção de escopo
Só `setores/cargos/setor_permissoes/user_setores/setor_lideres` (migration) + 1 teste. Nenhum arquivo `company_users`/empresa/NPS/bônus (76/78-80). ✅

## Pendente (manual, pós-deploy)
- MariaDB local indisponível → migration não rodada em MySQL localmente. Validar no VPS: `migrate` + em `/setores` conferir Shopee com Felipe (estrategista+líder) e Gustavo (analista). Confirmar que os emails reais existem no banco de prod (senão o wiring é pulado — re-rodar a migration após criar os usuários, ou vincular na UI).
