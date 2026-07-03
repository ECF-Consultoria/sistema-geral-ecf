---
id: DATA-01
title: Modelo N:N hibrido para multi-marketplace
status: accepted
date: 2026-07-03
related_phases: [18.5, 34, 35, 57]
---

# ADR DATA-01 — Modelo N:N hibrido para multi-marketplace

## Contexto

Phase 18.5 (2026-06-03) introduziu `companies.marketplace` ENUM('meli','shopee','amazon')
como marketplace primario por empresa (default 'meli', backfill via
`dashboard:import-marketplace-from-csv`). Distribuicao: 135 meli + 33 shopee + 1 amazon.

Phase 34/35 (2026-06-12) introduziu `companies.marketplaces_extras` JSON nullable
para marketplaces adicionais (semantic multi-marketplace do lado HubSpot). Populada
via cadastro comercial e sync HubSpot.

8 consumidores no codebase leem `companies.marketplace` diretamente (AdmanService,
providers Sugador Adman/ML, comandos de diagnostico como DiagnoseCustId,
MarkCustIdStatus, AuditBillingDivergence, ImportMarketplaceFromCsv,
Sugadores Provider).

Milestone v13.0 precisa formalizar N:N para habilitar Phase 58 (Dashboard ECF
agregado atraves de marketplaces), MAS Shopee/Amazon ainda sao stubs sem
integracao real nesta milestone. Refactor completo (drop das colunas flat,
migracao de 8 consumidores) seria over-engineering e risco alto de regressao
em Sugadores/Dashboard.

## Decisao

Criar tabela pivot `company_marketplaces` com FK para companies. Backfill:
1 row primary por empresa (a partir de `companies.marketplace` ENUM) + rows
adicionais a partir de `companies.marketplaces_extras` JSON.

**Manter em paralelo** as colunas legacy:
- `companies.marketplace` ENUM
- `companies.marketplaces_extras` JSON
- `companies.adman_account_id`
- `companies.ml_store_id`
- `companies.adman_store_id`

Adicionar accessors/mutators no `Company.php`:
- `getAdmanAccountIdAttribute()` — le da pivot (row primary marketplace='meli'), fallback pra coluna flat
- `getMlStoreIdAttribute()` — idem
- `setAdmanAccountIdAttribute()` — escreve em ambos (pivot + coluna flat) via `updateOrCreate`
- `setMlStoreIdAttribute()` — idem

Consumidores existentes continuam usando `$company->adman_account_id`, `$company->ml_store_id`
e `$company->cust_id` sem refactor. Pivot vira fonte-de-verdade, colunas flat viram cache/backup.

## Consequencias positivas

- Phase 58 (Dashboard ECF agregado) tem estrutura N:N pronta para queries
  do tipo `JOIN company_marketplaces ON company_id GROUP BY marketplace`
- Zero blast radius nos 8 consumidores atuais
- Preparo para Shopee/Amazon integracao real (v14+): basta adicionar row na pivot
- Rollback facil: drop da pivot; colunas flat permanecem intactas; consumidores
  continuam funcionando (accessor cai no fallback)
- Extensibilidade: novo marketplace = adicionar ao ENUM + 1 linha no
  BackfillCompanyMarketplaces (sem tocar nos consumidores)

## Consequencias negativas

- Debito tecnico consciente: schema com 2 fontes de verdade em paralelo por 1-2 milestones
- Custo de +1 query por accessor call quando pivot nao esta eager-loaded
  (mitigar com `->with('marketplaces')` nas rotas criticas — anotar como
  follow-up se profiling mostrar problema)
- Drop das colunas flat fica pra milestone futura (v14+), apos refactor
  de todos os 8 consumidores

## Alternativas consideradas

**A. N:N completo + drop das colunas flat imediato**
- Rejeitado: over-engineering pra Shopee/Amazon que ainda sao stubs;
  risco alto de regressao em Sugadores/Dashboard (8 consumidores refatorar).

**B. So ADR sem migration**
- Rejeitado: nao habilita Phase 58; queries agregadas dependeriam de
  parsing de JSON `marketplaces_extras` (fragil e ineficiente).

**C. Manter modelo hibrido atual (marketplace ENUM + marketplaces_extras JSON)**
- Rejeitado: sem tabela formal, Phase 58 teria queries complexas;
  extras JSON sem constraints permite valores invalidos.

## Referencias

- `.planning/phases/57-modelo-de-dados-multi-marketplace/57-CONTEXT.md`
- `database/migrations/2026_06_02_190000_add_marketplace_to_companies.php` (Phase 18.5)
- `database/migrations/2026_06_12_300001_add_close_fields_to_companies_table.php` (Phase 34/35)
- `.planning/phases/56-menu-lateral-multi-marketplace-stubs-em-desenvolvimento/PHASE-SUMMARY.md`
- `.planning/REQUIREMENTS.md` § Milestone v13.0 — DATA-01, DATA-02, DATA-03
