# Phase 59 — Desacoplamento de áreas transversais — Fechamento

**Auditoria dos 3 controllers hotspot (Comercial/Company/Admin) encontrou zero acoplamento HIGH — apenas 2 inconsistências MEDIUM de naming `cust_id`, ambas corrigidas via accessor `Company::cust_id`, com regressão zero confirmada (delta = 0 vs. baseline em 955 testes) e Publicação transversal reforçada.**

## Plans executados

1. **Plan 01 — Audit + baseline** (`171620e`, `d742f21`)
   - Baseline capturado: 955 testes coletados, 4748 assertions, 63
     vermelhos pré-existentes (15 errors + 48 failures, 1 skipped), 892
     passaram. Phase 57 (20/20) e Phase 58 (16/16) confirmados verdes.
   - Scout dos 56 refs `marketplace|meli|mlb|Mlb|ml_store` em
     `ComercialController` (29), `CompanyController` (17),
     `AdminController` (10) — zero HIGH, apenas 2 MEDIUM (naming
     `cust_id` inconsistente) e 1 LOW (naming histórico `mlb.` em
     permission keys, deferred v14+).
   - Publicação (`pub.*`/`hasPubPermission()`) confirmada transversal via
     grep + leitura de `EnsurePermission.php`/`checkPubAccess()` — sem
     amarração a marketplace.

2. **Plan 02 — Fixes cirúrgicos** (`e816307`, `90a2afe`, `40e406c`)
   - `CompanyController::index()` — `'adman_account_id'` trocado de
     `$c->ml_store_id ?: $c->adman_account_id` para `$c->cust_id`
     (accessor canônico).
   - `AdminController::fechamento()`/`gerarRelatorioGeral()` — unificados
     para `$f->cust_id`, eliminando divergência entre as duas rotas.
   - Achado extra: a expressão manual usava ordem de prioridade INVERTIDA
     em relação ao accessor (corrigido em 2026-06-09 após bug real
     ADHARAPRINTSHOP/AVF_2K) — o fix corrige naming E ordem simultaneamente.
   - Smoke tests (Phase 57+58 = 36/36) confirmaram zero regressão nova.

3. **Plan 03 — Gate de regressão + confirmação Publicação** (`6052c88`)
   - Suite completa pós-fix: 955 testes, 4748 assertions, 15 errors, 48
     failures, 1 skipped — **IDÊNTICO ao baseline em todas as métricas
     (delta = 0)**. Phase 57 (20/20) e Phase 58 (16/16) preservados.
   - Publicação reforçada com evidência dinâmica: 6 suites de teste que
     exercitam módulo Publicação/`mlb.*` confirmadas verdes pós-fix.
   - `59-AUDIT.md` fechado com selo de encerramento.

## Requisitos fechados

| Requisito | Descrição | Fechado por |
|---|---|---|
| CROSS-01 | Mapa exaustivo de acoplamento ML nos 3 controllers hotspot | Plan 01 + Plan 02 |
| CROSS-02 | Publicação (`pub.*`) confirmada transversal | Plan 01 (grep) + Plan 03 (suite dinâmica) |
| CROSS-03 | Zero regressão na suite completa | Plan 03 (delta = 0 vs. baseline) |

## Deliverable canônico

`.planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md` —
rastreia baseline, scout completo (3 tabelas por controller), seção
Publicação, sumário de severidade, itens aplicados no Plan 02, e gate de
regressão + fechamento do Plan 03.

## Itens deferred v14+

- Migração completa para pivot N:N `whereHas('marketplaces', ...)` nos 3
  controllers hotspot — quando Shopee/Amazon integrarem de fato.
- Renomeação do prefixo `mlb.` → `pub.` nas permission keys — exige
  migração de dados gravados em `permissoes`/`cargo_permissoes`.

## Arquivos de produção modificados nesta phase

- `app/Http/Controllers/CompanyController.php` (Plan 02)
- `app/Http/Controllers/AdminController.php` (Plan 02)

Nenhum outro arquivo de produção foi tocado — escopo cirúrgico conforme
`59-CONTEXT.md §4`.

---
*Phase: 59-desacoplamento-de-reas-transversais*
*Fechada: 2026-07-06*
