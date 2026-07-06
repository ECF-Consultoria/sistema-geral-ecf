# Milestones

## v13.0 Reorganizacao Multi-Marketplace (Shipped: 2026-07-06)

**Phases:** 4 (56, 57, 58, 59) | **Plans:** 8 total | **Deployed:** admin.ecfconsultoria.com.br

Preparação da arquitetura ECF Admin pra suportar múltiplos marketplaces (Mercado Livre + futuros Shopee/Amazon) sem quebrar o sistema atual 100% ML. Trilha completa: sidebar reorganizada → modelo N:N formalizado → dashboard multi-marketplace → desacoplamento cirúrgico das áreas transversais.

**Key accomplishments:**

- **Phase 56 — Menu lateral multi-marketplace + stubs "em desenvolvimento"** — `AppLayout.jsx` reorganizado com pasta "Mercado Livre" (aberta) consolidando Performance + Dados Estratégicos + Polos; itens Shopee/Amazon no topo com badge "Em breve" + logos SVG das marcas; grupo Publicação fora de ML (transversal); rotas/página `EmDesenvolvimento.jsx`; extensões novas em NAV_TREE (`divider`, `badgeText`, `defaultOpen`, `iconSrc`). UAT aprovado em prod após 3 hotfixes visuais.
- **Phase 57 — Modelo de dados multi-marketplace (N:N)** — Nova tabela pivot `company_marketplaces` (id, company_id FK, marketplace ENUM, store_id, adman_id, is_primary, active, integracao_status). `Company::marketplaces()` HasMany + 4 helpers + accessors legacy com fallback flat que preservam contrato com AdmanService/Sugadores. Backfill idempotente: **126 rows criadas (100% success)**. Schema legacy preservado em paralelo. ADR-DATA-01. 20 testes verdes. Reality check: 126 meli / 0 shopee / 0 amazon.
- **Phase 58 — Dashboard ECF agregado + shells por marketplace** — 4 rotas nomeadas (`ecf.dashboard`, `mercadolivre.dashboard`, `shopee.dashboard`, `amazon.dashboard`) + 4 métodos no `DashboardController` + filtro `?marketplace=` validado por whitelist `in:meli,shopee,amazon`. Componentes React dedicados: `Dashboard/EcfShell.jsx` (aspirational "em construção" com hero card + prévia KPIs + 3 cards de atalho por marketplace), `ShopeeShell.jsx`, `AmazonShell.jsx`. NAV_TREE atualizado (ECF Dashboard topo + Mercado Livre▾Dashboard). 16 testes Phase 58 verdes. Agregação real cross-marketplace formalmente deferida pra v14+ (0 empresas com 2+ marketplaces).
- **Phase 59 — Desacoplamento cirúrgico de áreas transversais** — Scout revelou que 6 das 7 áreas nominais do ROADMAP já eram transversais (0 refs a ML). Foco em 3 hotspots reais (Comercial 29 / Company 17 / Admin 10 refs). Audit classificou apenas 2 itens MED (`fix Phase 59`), zero HIGH. Fixes aplicados: `CompanyController.php` e `AdminController.php` (2 ocorrências) unificam resolução `cust_id` via accessor canônico — corrige naming E ordem invertida (bug real). Publicação (`pub.*`) confirmed transversal via grep + suite dinâmica. Regressão delta = 0 vs baseline (955 tests, 4748 assertions).

**Requirements delivered:** DATA-01/02/03 (Phase 57) + DASH-01/02/03 (Phase 58) + CROSS-01/02/03 (Phase 59).

**Deferred to v14+:**
- Agregação real cross-marketplace no ECF Dashboard (só útil quando >0 empresas com 2+ marketplaces reais)
- Migração completa pra pivot N:N `whereHas('marketplaces', ...)` em todas queries transversais
- Refactor de MlbController separando transversal vs. ML-específico (184 refs, mas todas legítimas hoje)
- Integração real de dados Shopee e Amazon (APIs, syncs, métricas)

**Known deferred items at close:** 66 (see STATE.md § Deferred Items — v13.0 close) — dívida técnica herdada de milestones v9/v10/v11/v12 nunca formalmente fechados, não específica de v13.0.

**Git tag:** v13.0

---
