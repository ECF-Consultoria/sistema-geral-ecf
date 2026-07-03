---
phase: 57
name: Modelo de dados multi-marketplace
milestone: v13.0
status: complete
completed_at: 2026-07-03
requirements_completed: [DATA-01, DATA-02, DATA-03]
plans_delivered: [57-01, 57-02, 57-03]
uat_status: aprovado
deployed_to_prod: true
---

# Phase 57 — PHASE-SUMMARY

**Milestone:** v13.0 Reorganização Multi-Marketplace
**Escopo:** Formalizar modelo N:N Company × Marketplace via tabela pivot nova, sem quebrar consumidores existentes.

## O que foi entregue

### Wave 1 — Schema + Model + Accessors + Backfill (commit `81f0fb4`)
- Migration `create_company_marketplaces_table` com colunas: id, company_id (FK cascade), marketplace ENUM('meli','shopee','amazon','magalu'), store_id, adman_id, is_primary, active, integracao_status ENUM('ativa','sem_token','erro','pendente'), timestamps + unique(company_id, marketplace) + index(marketplace, active)
- Model `CompanyMarketplace` (Eloquent, casts, BelongsTo company)
- Ajustes em `Company.php`:
  - Relacionamento `marketplaces()` HasMany
  - 4 helpers: `isInMarketplace`, `marketplacesAtivos`, `primaryMarketplace`, `storeIdFor`
  - 2 accessors legacy: `getAdmanAccountIdAttribute`, `getMlStoreIdAttribute` (leem pivot, fallback flat)
  - 2 mutators legacy: escrevem em ambos (pivot + coluna flat) via `updateOrCreate` com guard `$this->exists`
- Comando `companies:backfill-marketplaces --dry-run` idempotente (usa `getRawOriginal` para evitar recursão com accessors)
- Factories: `CompanyFactory` (nova) + `CompanyMarketplaceFactory`
- ADR-DATA-01 documenta modelo híbrido intencional
- **15 testes verdes** (helpers + accessors + backfill)

### Wave 2 — Smoke tests (commit incluído)
- DashboardSmokeTest (com pivot / sem pivot fallback)
- SugadoresSmokeTest (listagem + drilldown por empresa)
- PerformanceSmokeTest
- **5 smoke tests verdes; total 20 testes Phase57**

### Fix colateral (parte do commit Wave 1)
Migration `add_polos_to_servicos_setor_enum` (não-Phase 57, quick task 2026-07-03) tinha `ALTER COLUMN ENUM` MySQL-only quebrando SQLite in-memory dos testes. Guard `driverName === 'mysql'` adicionado com early-return. Comportamento em prod (MariaDB) inalterado.

### Wave 3 — Deploy + backfill em prod
- Deploy via `deploy.sh` — migration executou em 83.10ms
- Backfill dry-run: 126 empresas processadas, 126 primary previstas
- Backfill real: **126 rows criadas (100% success, 0 errors)**
- Validação: `CompanyMarketplace::count() = 126`, todos com `is_primary=true`
- UAT aprovado direto em prod (evidências em 57-UAT.md)

## Requirements cobertos

| REQ-ID | Descrição | Como validado |
|--------|-----------|---------------|
| DATA-01 | ADR + modelo N:N | ADR-DATA-01 escrito + migration criada + tabela em prod |
| DATA-02 | Migration + backfill 100% empresas | 126/126 empresas com row primary na pivot em prod |
| DATA-03 | Testes cobrindo queries críticas | 20 testes verdes (helpers + accessors + backfill + smoke em 3 rotas) |

## Arquivos criados/modificados

**Criados:**
- `database/migrations/2026_07_03_190000_create_company_marketplaces_table.php`
- `app/Models/CompanyMarketplace.php`
- `app/Console/Commands/BackfillCompanyMarketplaces.php`
- `database/factories/CompanyMarketplaceFactory.php`
- `database/factories/CompanyFactory.php` (nova — não existia)
- `.planning/adrs/DATA-01-multi-marketplace-model.md`
- `tests/Feature/Phase57/CompanyMarketplaceHelpersTest.php`
- `tests/Feature/Phase57/CompanyLegacyAccessorsTest.php`
- `tests/Feature/Phase57/BackfillMarketplacesTest.php`
- `tests/Feature/Phase57/DashboardSmokeTest.php`
- `tests/Feature/Phase57/SugadoresSmokeTest.php`
- `tests/Feature/Phase57/PerformanceSmokeTest.php`

**Modificados:**
- `app/Models/Company.php` (helpers + accessors + mutator adicionados após linha 328)
- `database/migrations/2026_07_03_113103_add_polos_to_servicos_setor_enum.php` (guard MySQL)

**Não modificado (por decisão):**
- `app/Services/AdmanService.php`
- `app/Services/SugadorAnalysisService.php`
- `app/Services/Sugadores/*`
- Comandos que leem `$company->marketplace` (DiagnoseCustId, MarkCustIdStatus, etc)

## Descoberta importante — estado real ≠ CONTEXT.md

Snapshot da Phase 18.5 registrava 169 empresas (135 meli + 33 shopee + 1 amazon).
Estado real em 2026-07-03:
- **126 empresas** total (redução; houve limpeza/consolidação entre 18.5 e 57)
- Distribuição na pivot: **126 meli, 0 shopee, 0 amazon** — o CSV Shopee/Amazon da Phase 18.5 parece ter sido revertido ou re-consolidado em ML
- `marketplaces_extras` em 39 empresas contém JSON `[]` (vazio) — nenhum extras válido para migrar

Backfill defaultou para 'meli' via fallback conforme design (`?? 'meli'`).
Quando alguma empresa realmente for Shopee/Amazon integrada, admin atualiza
via UI ou comando dedicado.

## Decisões implementadas

1. ✓ N:N vazio + helpers; schema legacy preservado em paralelo (JSON + colunas flat)
2. ✓ Store IDs `adman_account_id` e `ml_store_id` acessíveis via accessor com fallback
3. ✓ Testes: 3 grupos TDD (helpers, accessors, backfill) + 3 smoke tests

## Habilita phases

- **Phase 58** (Dashboard ECF agregado): pivot pronta — queries `JOIN company_marketplaces ON company_id GROUP BY marketplace` funcionam. Dashboard agregado através de marketplaces é query direta.
- **Phase 59** (Desacoplamento áreas transversais): independente de 57.

## Notas para consulta futura

- **Modelo híbrido intencional:** colunas flat legacy (`marketplace`, `marketplaces_extras`, `adman_account_id`, `ml_store_id`, `adman_store_id`) coexistem com pivot. Consumidores continuam usando accessors legacy; drop das colunas flat fica para milestone v14+.
- **Extensibilidade:** adicionar novo marketplace = adicionar ao ENUM da migration + slug em `BackfillCompanyMarketplaces::VALID_MARKETPLACES`.
- **Rollback:** `php artisan migrate:rollback --step=1` remove só a pivot; colunas flat intactas; consumidores continuam funcionando via fallback.
- **Performance:** accessors fazem +1 query por call quando pivot não é eager-loaded. Anotar como follow-up se profiling mostrar problema (mitigação: `->with('marketplaces')` em rotas críticas).
- **Fix colateral:** migration antiga `add_polos_to_servicos_setor_enum` agora tolera SQLite. Padrão útil para futuras migrations com sintaxe MySQL-only.
