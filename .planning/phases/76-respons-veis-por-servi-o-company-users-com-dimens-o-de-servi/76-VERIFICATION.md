---
phase: 76-respons-veis-por-servi-o-company-users-com-dimens-o-de-servi
verified: 2026-07-14T00:00:00Z
status: passed
score: 6/6 must-haves verificados
overrides_applied: 0
re_verification:
  previous_status: none
  note: "Verificação inicial (goal-backward)."
human_verification:
  - test: "Rodar `php artisan migrate` no VPS (MySQL) e conferir `SHOW CREATE TABLE company_users`"
    expected: "Coluna servico_id presente + FK para servicos (nullOnDelete) + unique (company_id,user_id,role,servico_id)"
    why_human: "O branch MySQL da FK (DB::getDriverName()==='mysql') NÃO é exercitado pelos testes locais em SQLite :memory:. Cross-driver comprovado só no lado SQLite."
notes:
  - "Falha pré-existente e fora de escopo: Tests\\Feature\\PublicacaoDesempenhoRouteTest > user com mlb dashboard acessa rota e recebe 200 (403≠200). Documentada em deferred-items.md; nenhum arquivo de produção que afete a rota /publicacao/desempenho foi tocado pelos commits v16."
  - "Status marcado 'passed' (goal comprovado). Item human_verification é validação MySQL pós-deploy — padrão para migration cross-driver, não é gap."
---

# Phase 76: Responsáveis por serviço — company_users com dimensão de serviço Verification Report

**Phase Goal:** `company_users` ganha dimensão de serviço (`servico_id`); atribuição de responsáveis vira por-serviço (atribuir Shopee NÃO apaga o responsável ML — corrige risco da Phase 75); e TODO o comportamento consolidado (carteira/pendências/notificações/bônus) permanece IDÊNTICO (regressão provada). NÃO reescreve bônus/NPS (Phase 79/80).
**Verified:** 2026-07-14
**Status:** passed (com nota de verificação manual MySQL pós-deploy)
**Re-verification:** No — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | DEC-A1: `servico_id` na pivot + unique 4-col; múltiplos NULL coexistem | ✓ VERIFIED | Migration `2026_07_14_000001` linhas 32-51 (ADD COLUMN + swap unique). `MigrationCompanyUsersServicoTest` 3 casos verdes: coluna existe, 2 linhas NULL coexistem (count=2), mesmo servico_id não-NULL lança QueryException |
| 2 | DEC-A1: data-migration idempotente — ML→servico performance; sem performance→NULL | ✓ VERIFIED | `migrarLinhasExistentes()` (linhas 90-117): join contratos_servico+servicos where ativo && setor='performance', MIN() determinístico, UPDATE whereNull (idempotente). `DataMigrationServicoTest` 4 casos verdes incl. idempotência (2ª passada retorna 0) e "sem performance permanece NULL" |
| 3 | DEC-A2: invariante consolidado — consultor()/estrategista()/carteira retornam MESMO resultado com linha Shopee; bônus não dobra | ✓ VERIFIED | `Company::consultor()/estrategista()` com `->distinct('users.id')` (linhas 173-189, sem withPivot('servico_id')); `User::companies()/consultorCompanies()/estrategistaCompanies()` com `->select('companies.*')->distinct('companies.id')` (withPivot/withTimestamps removidos p/ não furar distinct). `CarteiraBonusNaoDobraTest` (2), `ResponsaveisConsolidadoInvarianteTest` (2), `LeitoresConsolidadoRegressaoTest` (4) — todos verdes com linha Shopee de assigned_at/timestamps divergentes |
| 4 | DEC-A3: isolamento — atribuir Shopee não apaga ML e vice-versa; guard IDOR preservado | ✓ VERIFIED | `ShopeeEmpresasController::bulkAssign` resolve servico_id Shopee ativo e delete/attach escopado por (company_id, role, servico_id shopee), guard IDOR :168-172 intacto. `CompanyController::bulkAssign` (:734-754) e `update` sync (:625-650) escopados por `servicoPerformanceAtivoId()` (whereNull no slot consolidado); detach() total eliminado. `AtribuicaoPorServicoIsolamentoTest` 5 casos verdes (Shopee↛ML, ML↛Shopee, update sync↛Shopee, NULL não duplica, IDOR 422 sem gravar) |
| 5 | Regressão: Portfolio/Desempenho/Nps sem novas falhas (só a pré-existente fora de escopo) | ✓ VERIFIED | Portfolio 29 passed (372 assertions); Nps 157 passed (1011); Desempenho 55 passed + 1 falha PublicacaoDesempenhoRouteTest confirmada pré-existente (deferred-items.md; git stash reproduz antes das edições) |
| 6 | Nenhum escopo das fases 77-80 vazou (sem rewrite de NPS/bônus) | ✓ VERIFIED | `git diff --stat 31a71de^..48e6b89 -- app/ database/`: apenas CompanyController, ShopeeEmpresasController, Company, User + 1 migration (263 inserções). Nenhum arquivo de produção Nps/Goal/Bonus/Desempenho/Portfolio tocado nos commits v16 |

**Score:** 6/6 truths verificados

### Required Artifacts

| Artifact | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php` | Migration cross-driver: coluna + FK (MySQL) + swap unique + data-migration | ✓ VERIFIED | 4 passos + down() reverso + migrarLinhasExistentes() público/idempotente |
| `app/Models/Company.php` | consultor()/estrategista() dedup + variantes *DoServico() | ✓ VERIFIED | distinct('users.id'); consultorDoServico/estrategistaDoServico presentes |
| `app/Models/User.php` | companies()/*Companies() dedup por companies.id | ✓ VERIFIED | select('companies.*')->distinct('companies.id'); withPivot/withTimestamps removidos |
| `app/Http/Controllers/ShopeeEmpresasController.php` | bulkAssign escopado servico_id shopee + guard IDOR | ✓ VERIFIED | delete/attach por (company_id, role, servico_id shopee); guard :168-172 intacto |
| `app/Http/Controllers/CompanyController.php` | bulkAssign + update sync escopados performance/consolidado | ✓ VERIFIED | servicoPerformanceAtivoId(); detach() total eliminado; whereNull no slot NULL |
| `tests/Feature/V16/*` | 6 arquivos de teste (migration, data-migration, invariante, carteira, isolamento, regressão) | ✓ VERIFIED | 20 passed (63 assertions) |

### Key Link Verification

| From | To | Via | Status | Detalhes |
|------|----|----|--------|----------|
| ShopeeEmpresasController::bulkAssign | company_users | attach com servico_id shopee resolvido de contratos_servico | ✓ WIRED | join s.setor=SETOR_SHOPEE; continue se sem contrato (sem linha órfã) |
| CompanyController escritas | company_users | servicoPerformanceAtivoId() → servico_id ou NULL | ✓ WIRED | whereNull/where por servico_id no delete escopado |
| User::companies() (carteira bônus) | company_users | select('companies.*')->distinct('companies.id') | ✓ WIRED | dedup provado com linha Shopee divergente |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Suite V16 completa | `php artisan test tests/Feature/V16` | 20 passed (63 assertions) | ✓ PASS |
| Regressão Portfolio | `php artisan test --filter=Portfolio` | 29 passed (372) | ✓ PASS |
| Regressão Nps | `php artisan test --filter=Nps` | 157 passed (1011) | ✓ PASS |
| Regressão Desempenho | `php artisan test --filter=Desempenho` | 55 passed, 1 pré-existente fora de escopo | ✓ PASS |

### Anti-Patterns Found

Nenhum. Sem stubs, sem TODO/FIXME/XXX introduzidos nos arquivos de produção da fase. `continue` no bulkAssign Shopee é guard intencional (não gravar linha órfã), documentado em pt-BR.

### Human Verification Required

#### 1. Validação MySQL da FK servico_id (pós-deploy VPS)

**Test:** Rodar `php artisan migrate` no VPS (MySQL/MariaDB) e executar `SHOW CREATE TABLE company_users`.
**Expected:** Coluna `servico_id` presente; FK para `servicos` com `ON DELETE SET NULL`; unique `(company_id, user_id, role, servico_id)`.
**Why human:** O branch `if (DB::getDriverName() === 'mysql')` que adiciona a FK NÃO é exercitado pelos testes locais (SQLite `:memory:`). O lado SQLite (cross-driver) está comprovado; a FK MySQL só pode ser validada no ambiente real. Documentado em 76-01-SUMMARY.md e 76-RESEARCH.md.

### Gaps Summary

Nenhum gap bloqueante. Todos os 6 truths (DEC-A1, DEC-A2, DEC-A3, regressão, contenção de escopo) estão verificados por código + testes verdes. A única pendência é a validação manual da FK MySQL pós-deploy — padrão esperado para migration cross-driver e não bloqueia o goal da fase (comprovável em SQLite). A falha `PublicacaoDesempenhoRouteTest` é pré-existente, não relacionada a `company_users`, e está registrada em deferred-items.md.

---

_Verified: 2026-07-14_
_Verifier: Claude (gsd-verifier)_
