# Phase 76: Responsáveis por serviço — company_users com dimensão de serviço (fundação v16.0) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** `.planning/milestones/v16.0-brief.md` (DEC-A) + mapeamento dirigido do código.

<domain>
## Phase Boundary

Fundação da milestone v16.0 (v2): dar à pivot `company_users` uma **dimensão de serviço**, para que uma mesma empresa (ML + Shopee) tenha responsáveis diferentes por canal, **sem quebrar o comportamento atual** (carteira, pendências, notificações e — crítico — o bônus). Esta fase NÃO mexe no NPS/bônus (Phases 79/80); só habilita o modelo e corrige a atribuição por-serviço.

**IN SCOPE:**
1. Migration: adicionar `servico_id` (nullable, FK `servicos`) à pivot `company_users`; unique passa a `(company_id, user_id, role, servico_id)`. Data-migration das linhas existentes (DEC-A1).
2. `Company`/`User`: relações service-aware + accessors **consolidados** que preservam o comportamento atual quando o serviço é ignorado.
3. Reescrever a **atribuição por-serviço**: `ShopeeEmpresasController::bulkAssign` grava com o `servico_id` do serviço Shopee da empresa; `CompanyController` (bulkAssign/update sync) grava com o `servico_id` do serviço ML/performance. **Corrige o risco da Phase 75** (hoje o bulkAssign apaga por `(company_id, role)` e sobrescreveria o responsável do outro serviço).
4. Atualizar os ~15 pontos de leitura de responsável: por padrão **consolidado** (comportamento de hoje); por-serviço só onde a Phase 78 vai precisar.

**OUT (fases seguintes):** Setor Shopee + Felipe/Gustavo (77); Comercial/aba Shopee UI (78); NPS multi-modelo + snapshot de atribuições (79); reescrita do bônus + relatórios (80).
</domain>

<decisions>
## Implementation Decisions

### DEC-A1 — Migração das linhas de `company_users` (INVARIANTE: leitura consolidada inalterada)
- `servico_id` BIGINT nullable + FK `servicos` (nullOnDelete). Novo unique `(company_id, user_id, role, servico_id)`. **Cross-driver** (SQLite dos testes enforça constraints — ver [[project_enum_setor_sqlite_check]]).
- Data-migration idempotente: para cada linha, `servico_id` = `servico_id` do contrato ATIVO de setor `performance` da empresa quando existir; senão **NULL** (consolidado/legado). Nunca inventar serviço.
- `servico_id` NULL = responsável consolidado da empresa (retrocompat). Valor = responsável daquele serviço.

### DEC-A2 — Leitura consolidada preserva o bônus (não regredir)
- `Company::consultor()/estrategista()` continuam retornando o(s) responsável(is) **consolidados** por padrão (ignoram `servico_id`) — resultado idêntico ao de hoje (tipicamente 1/empresa). Adicionar variantes **service-aware** (ex.: por `servico_id`/setor) para a aba Shopee (78) e a atribuição.
- Os ~15 leitores atuais devem manter o MESMO resultado — validar por teste de regressão. NÃO reescrever lógica de bônus aqui.

### DEC-A3 — Atribuição por-serviço (escrita)
- `ShopeeEmpresasController::bulkAssign`: resolver o `servico_id` do contrato Shopee ativo e gravar/apagar a pivot filtrando por `(company_id, role, servico_id=shopee)` — não tocar linhas de outros serviços. Manter o guard anti-IDOR da Phase 75.
- `CompanyController` (bulkAssign :683-700, update sync :617-628): gravar com `servico_id` do contrato performance/ML (ou consolidado/NULL para empresas ML puras — decidir sem quebrar o fluxo ML atual). Atribuir Shopee **nunca** apaga o responsável ML e vice-versa.

### Claude's Discretion
- Accessor consolidado com `distinct` por (user_id, role) vs todas as linhas.
- Nomes dos métodos service-aware.
- Coluna `servico_id` (FK) — preferir sobre `setor` string; confirmar na pesquisa qual gera menos atrito.
- Como o `CompanyController` resolve o `servico_id` ML (contrato performance ativo vs NULL consolidado) para empresas ML puras.
</decisions>

<constraints>
## Constraints
- **Testes em `tests/Feature/V16/`** (NÃO `tests/Feature/Phase76/` — colide com Phase77-81 do anunciar-ml). Ex.: `tests/Feature/V16/ResponsaveisPorServicoTest.php`.
- **Backward-compat obrigatório**: nenhum leitor consolidado muda de resultado (teste de regressão do bônus/carteira). Migration reversível/idempotente e cross-driver (MySQL prod + SQLite testes).
- Dev em paralelo (anunciar-ml/polos em origin/main) — reconciliar antes de deploy.
- pt-BR nos comentários; sem libs novas.
</constraints>

<canonical_refs>
## Canonical References
- `.planning/milestones/v16.0-brief.md` — diagnóstico + DEC-A/DEC-B + âncoras.
- Pivot: `database/migrations/2026_04_26_152217_create_company_users_table.php` (+ `2026_05_07_000005`, `2026_05_22_200001`).
- `app/Models/Company.php:157-179` (users/consultor/estrategista); `app/Models/User.php:195-218` (companies/consultorCompanies/estrategistaCompanies).
- `app/Http/Controllers/CompanyController.php` — usersPorCargo :206-222, pendências :189-199, bulkAssign :683-700, update sync :617-628, listagem :158-159, show :453-454.
- `app/Http/Controllers/ShopeeEmpresasController.php` — index :61-71, pendências :86-110, bulkAssign :160-186 (guard IDOR :168-172).
- `ContratoServico.php` + `Company::contratosServico() :242`, `scopeActive`; `Servico.php:52-158` (SETOR_PERFORMANCE / SETOR_SHOPEE).
- Leitores a validar (regressão): `PortfolioController.php:517-518/615-616/1406`, `PpaController.php:45`, `PortfolioGoal.php:98-101`, `DashboardController.php:680-688/908-909/931-932`, `NpsDispararMensal.php:198-206`, `Goal.php:33` + `CalculateGoalResults.php:98-99`, `DesempenhoScoreService.php:354-373` (carteira; NÃO tocar score aqui), `NpsPendingService.php:157`, `Sugador.php:147-152`, `SugadorController.php:93-193`, `EmpresaAnaliseEcfController.php:24`.
</canonical_refs>

<deferred>
## Deferred
- NPS multi-modelo, snapshot de atribuições, reescrita do bônus → Phases 79/80.
- `leader_id` por serviço → liderança via `setor_lideres`; reavaliar na 77/80 se necessário.
</deferred>

---
*Phase: 76 — v16.0 (v2) fundação*
