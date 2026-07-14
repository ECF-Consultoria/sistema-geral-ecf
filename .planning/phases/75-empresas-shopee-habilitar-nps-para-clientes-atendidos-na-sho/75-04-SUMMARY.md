---
phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
plan: 04
subsystem: api
tags: [shopee, laravel, inertia, rbac, nps, company_users, idor-guard, tdd]

# Dependency graph
requires:
  - phase: 75-01
    provides: "Servico::SETOR_SHOPEE + enum 'shopee' no schema (gatilho por contrato)"
  - phase: 75-02
    provides: "permission key shopee.empresas no catálogo estático Permissions"
provides:
  - "ShopeeEmpresasController (index enxuto ML-free + bulkAssign com guard anti-IDOR)"
  - "Rotas shopee.empresas.index / shopee.empresas.bulk-assign gated por permission:shopee.empresas"
  - "Payload de empresas Shopee sem cust_id/adman/ml/token/grants; pendências DEC-2"
  - "Prova (teste) de que o motor NPS gera link para empresa Shopee sem métrica (DEC-5)"
affects: [75-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Controller espelho enxuto: reusa esqueleto do CompanyController sem injetar deps ML/métrica (construtor vazio)"
    - "Guard de escopo anti-IDOR via closure de validação em ids.* reusando o builder do index (fail-closed 422)"
    - "Teste de props Inertia sem asset buildado: requisição XHR (X-Inertia header) → JSON props, sem render de blade"

key-files:
  created:
    - app/Http/Controllers/ShopeeEmpresasController.php
    - tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php
    - tests/Feature/Phase75/Phase75NpsShopeeTest.php
  modified:
    - routes/web.php

key-decisions:
  - "Guard anti-IDOR (W1/T-75-10) com semântica fail-closed: qualquer ID fora do escopo shopee derruba o request inteiro (422), nenhum pivot sincronizado"
  - "Testes de listagem via Inertia XHR (JSON) para não depender do asset Shopee/Empresas.jsx (que é o Plan 75-05)"

patterns-established:
  - "empresasShopeeBaseQuery() compartilhado entre index e o guard do bulkAssign — 'estar na aba' e 'poder ser atribuída' usam o mesmo critério"

requirements-completed: [DEC-2, DEC-4, DEC-5]

# Metrics
duration: 15min
completed: 2026-07-14
---

# Phase 75 Plan 04: Backend da aba Empresas Shopee Summary

**ShopeeEmpresasController ML-free — lista empresas por contrato de setor `shopee`, payload sem métrica/cust_id/grant, pendências mínimas pro NPS, atribuição no pivot com guard anti-IDOR, sob RBAC dedicado `permission:shopee.empresas`; e prova de que o motor NPS gera link para empresa Shopee sem métrica.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-14T14:16:56Z
- **Completed:** 2026-07-14T14:31:25Z
- **Tasks:** 3
- **Files modified:** 4 (3 criados, 1 modificado)

## Accomplishments
- `ShopeeEmpresasController@index`: filtro `whereHas contratosServico ativo setor=SETOR_SHOPEE`, SEM `whereDoesntHave('mlbEmpresa')` (multi-marketplace aparece nas duas abas), construtor vazio (sem Adman/ML/Drive), payload enxuto e pendências DEC-2 (`sem_responsavel` / `sem_contato` / `empresa_nova`).
- `ShopeeEmpresasController@bulkAssign`: sincroniza pivot `company_users` (`consultor`/`estrategista`) com guard de escopo anti-IDOR (W1/T-75-10) — `ids.*` só aceita empresa com contrato shopee ativo, senão 422 fail-closed.
- Rotas `shopee.empresas.*` gated por `permission:shopee.empresas` (nunca `core.empresas`).
- 16 cenários de teste da aba (filtro, multi-marketplace, payload enxuto, pendências, atribuição, guard IDOR, gate RBAC) + 2 do NPS Shopee — todos verdes.

## Task Commits

1. **Task 1: Testes da aba Shopee + NPS (RED)** — `85c4698` (test)
2. **Task 2: ShopeeEmpresasController (index + pendências + bulkAssign)** — `71eee66` (feat)
3. **Task 3: Rotas shopee.empresas.* gated** — `27d52c3` (feat)

_TDD: Task 1 escreveu a suíte RED; Tasks 2–3 levaram ao GREEN._

## Files Created/Modified
- `app/Http/Controllers/ShopeeEmpresasController.php` — index enxuto ML-free + bulkAssign com guard de escopo shopee.
- `routes/web.php` — import do controller + grupo `permission:shopee.empresas` com index/bulk-assign.
- `tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php` — 16 cenários (aba/pendências/atribuição/IDOR/gate).
- `tests/Feature/Phase75/Phase75NpsShopeeTest.php` — 2 cenários (motor NPS gera link p/ empresa Shopee sem métrica).

## Decisions Made
- **Guard anti-IDOR fail-closed:** optei por rejeitar o request inteiro (422) quando qualquer `company_id` está fora do escopo shopee, em vez de atribuir só os válidos. Semântica mais previsível e defensável; o teste dedicado assere que nem a empresa válida do mesmo request é atribuída.
- **`empresasShopeeBaseQuery()` compartilhado** entre `index` e o guard do `bulkAssign` — garante que o critério de "aparecer na aba" e "poder ser atribuída" nunca divirjam.
- **`update()` por linha NÃO implementado** (era opcional no plano) — `bulkAssign` cobre a atribuição; a rota `shopee.empresas.update` foi omitida por não ser necessária (evita superfície extra).

## Deviations from Plan

### Ajustes de execução (não alteram escopo)

**1. [Rule 3 - Blocking] Testes de listagem via Inertia XHR em vez de render blade**
- **Found during:** Task 2 (verificação do index)
- **Issue:** `app.blade.php` faz `@vite("resources/js/Pages/{$page['component']}.jsx")`, exigindo `Shopee/Empresas.jsx` no manifest Vite. Essa página é o Plan 75-05 (frontend); este plan é backend-only e não builda assets. Um `$this->get('/shopee/empresas')` normal estourava "Unable to locate file in Vite manifest".
- **Fix:** Helper `getEmpresas()` faz a requisição como Inertia XHR (`X-Inertia` + `X-Inertia-Version` resolvido do middleware) → resposta JSON com as props, sem render de blade. `payloadCompanies()` passou a ler `props.companies` do JSON.
- **Files modified:** tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php
- **Verification:** 16/16 verdes.
- **Committed in:** `71eee66` (Task 2)

**2. [Observação DEC-5] Phase75NpsShopeeTest nasceu GREEN (não RED)**
- O plano previa RED em Task 1 "por rota/controller ausentes". A suíte de NPS não depende do controller/rota novos — ela prova que o motor NPS EXISTENTE (`nps.generate`) já gera link para empresa Shopee sem métrica (DEC-5, motor intocado). Passar desde o início é o resultado correto e desejado. Apenas `Phase75ShopeeEmpresasTest` estava em RED legítimo (404 rota ausente).

---

**Total deviations:** 1 ajuste de teste (blocking) + 1 observação de intenção.
**Impact on plan:** Nenhum scope creep. Backend entregue exatamente como especificado; motor NPS não tocado (DEC-5).

## Issues Encountered
- **Falhas pré-existentes no filtro `Companies`** durante a regressão. Confirmadas independentes deste plan (falham identicamente com o `CompanyController.php` do commit anterior ao 75-04). Registradas em `deferred-items.md`; NÃO corrigidas (SCOPE BOUNDARY). Regressão `--filter=Nps`: 157 passed, zero falhas.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- Backend pronto para o Plan 75-05 (página React `Shopee/Empresas.jsx`): props `companies` (enxuto), `estrategistas`, `analistas`, `grupos`; endpoints `shopee.empresas.bulk-assign` e `nps.generate` para os botões de atribuir/gerar NPS.
- Nenhum bloqueio.

## Self-Check: PASSED

Todos os arquivos criados existem e os 3 commits de task estão no histórico:
- `app/Http/Controllers/ShopeeEmpresasController.php` ✓
- `tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php` ✓
- `tests/Feature/Phase75/Phase75NpsShopeeTest.php` ✓
- `.planning/.../75-04-SUMMARY.md` ✓
- Commits `85c4698`, `71eee66`, `27d52c3` ✓

---
*Phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho*
*Completed: 2026-07-14*
