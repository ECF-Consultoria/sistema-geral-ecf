---
phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
plan: 02
subsystem: nps
tags: [seed, migration, nps-templates, service-scopes, idempotencia]
requires:
  - "nps_templates / nps_template_questions / nps_template_options / nps_template_service_scopes (Phase 68 - 2026_07_07_100001)"
  - "servico shopee ativo (Phase 75 - 2026_07_14_100002)"
provides:
  - "Template NPS Shopee (is_default=false, active, envio_automatico_mensal, priority=10)"
  - "Scope NPS Shopee -> servico setor=shopee"
  - "Scopes NPS Padrao -> todos os servicos ativos setor=performance (habilita disparo estrito 79-03)"
affects:
  - "Disparo mensal estrito (Plano 79-03) — sem esses scopes empresas ML/Shopee ficariam sem NPS"
tech-stack:
  added: []
  patterns:
    - "Seed idempotente espelhando o molde 100004 (DB::table puro, DB::transaction, guards where->first/exists)"
    - "updateOrInsert por chave composta para scopes idempotentes (protegido por unique nps_tpl_scope_uniq)"
    - "down() no-op intencional para migrations de dados semanticos"
key-files:
  created:
    - "database/migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php"
    - "tests/Feature/V16/SeedNpsShopeeTest.php"
  modified:
    - "tests/Feature/Phase68/NpsSchemaTest.php"
    - "tests/Feature/Phase70/NpsTemplateCrudTest.php"
decisions:
  - "DEC-79-B: NPS Shopee com priority=10 (>0) e is_default=false — o bonus continua no NPS Padrao (DEC-79-E)"
  - "DEC-79-A: NPS Padrao linkado a TODOS os servicos ativos setor=performance"
  - "Guard A2: servico shopee ausente nao aborta o seed (Log::warning) — link performance e o critico"
metrics:
  duration: "~9min"
  completed: "2026-07-14"
  tasks: 2
  files: 4
---

# Phase 79 Plan 02: Seed NPS Shopee + link performance ao NPS Padrão — Summary

Seed idempotente que cria o modelo "NPS Shopee" espelhando o "NPS Padrão" (3 perguntas escala + 15 opções peso 1..5) com scope no serviço setor=shopee, e vincula o "NPS Padrão" a todos os serviços ativos setor=performance — condição indispensável para o disparo estrito do Plano 79-03 não deixar empresas ML/Shopee sem NPS.

## O que foi construído

- **Migration `2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php`** — dentro de `DB::transaction`, seguindo o molde da Phase 68 (`100004`):
  - **Passo A:** template `NPS Shopee` (guard por `nome`), `is_default=false`, `active=true`, `priority=10`, `envio_automatico_mensal=true`.
  - **Passo B:** 3 perguntas idempotentes por `(template_id, dimensao)` — estrategista (obrigatória, ordem 1), analista (opcional, ordem 2), empresa (obrigatória, ordem 3), tipo `escala`; 5 opções por pergunta (`question_id`, label 1..5, peso 1..5, ordem 1..5), guard por `(question_id, peso)`.
  - **Passo C:** scope `NPS Shopee → serviço setor=shopee` via `updateOrInsert`; guard `if ($servicoShopeeId)` com `Log::warning` se ausente (A2).
  - **Passo D:** `updateOrInsert` de scope `NPS Padrão → cada serviço ativo setor=performance` (DEC-79-A), com contagem logada.
  - `down()`: no-op intencional (dados semânticos).
- **Teste `tests/Feature/V16/SeedNpsShopeeTest.php`** — 5 casos: flags do template, perguntas+opções espelhando o molde, scope shopee, scopes performance (incluindo exclusão de serviço inativo), e idempotência (2ª execução afeta 0 rows).

## Verificação

- `tests/Feature/V16/SeedNpsShopeeTest.php` — **5 passed (33 assertions)**.
- Regressão `--filter=Nps` — **163 passed (1050 assertions)**.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Ajuste de premissa em 1 caso do teste de seed**
- **Encontrado em:** Tarefa 2 (execução GREEN)
- **Problema:** o teste `test_scope_liga_nps_shopee_ao_servico_shopee` criava um novo serviço shopee, mas o seed da Phase 75 (`2026_07_14_100002`) já semeia um serviço shopee ativo durante o `RefreshDatabase`. O seed resolvia `->value('id')` para o serviço da Phase 75, então o scope não apontava para o serviço recém-criado pelo teste.
- **Fix:** o teste agora resolve o serviço shopee ativo do mesmo jeito que o seed (query por setor/ativo) e só cria manualmente se ausente — robusto com ou sem o seed da Phase 75 na base de teste.
- **Arquivos:** `tests/Feature/V16/SeedNpsShopeeTest.php`
- **Commit:** 14ebcdf

**2. [Rule 1 - Bug] Testes NPS legados hardcodavam o baseline de templates semeados**
- **Encontrado em:** verificação de regressão (`--filter=Nps`)
- **Problema:** `Phase68/NpsSchemaTest::test_multiplos_templates_nao_default_coexistem_sem_erro` e `Phase70/NpsTemplateCrudTest::test_index_..._ordenados_...` assumiam que o único template semeado era o "NPS Padrão". Com o novo seed "NPS Shopee" (is_default=false), as contagens fixas (3) passaram a divergir (4). Não é bug do seed — o seed é aditivo e correto (DEC-79-B).
- **Fix:** ambos os testes passaram a computar o baseline dinamicamente (`count()` antes de adicionar fixtures), ficando robustos a seeds aditivos futuros. Assertivas de ordenação (`templates.0.is_default=true`) preservadas.
- **Arquivos:** `tests/Feature/Phase68/NpsSchemaTest.php`, `tests/Feature/Phase70/NpsTemplateCrudTest.php`
- **Commit:** 14ebcdf

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície de segurança nova introduzida (seed de dados backend-only, sem endpoints/rotas/auth).

## Self-Check: PASSED

- `database/migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php` — FOUND
- `tests/Feature/V16/SeedNpsShopeeTest.php` — FOUND
- Commit `362d2a3` (test RED) — FOUND
- Commit `14ebcdf` (feat GREEN + ajustes) — FOUND
