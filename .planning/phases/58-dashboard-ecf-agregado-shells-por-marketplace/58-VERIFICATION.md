---
phase: 58-dashboard-ecf-agregado-shells-por-marketplace
verified: 2026-07-03T21:45:00Z
reverified: 2026-07-06
status: human_needed
score: 10/10 must-haves aceitos (1 override formalizado + 3 itens human_verification)
overrides_applied: 1
overrides:
  - must_have: "DASH-01 texto literal: /dashboard/ecf soma resultados através de marketplaces por empresa (ML+Shopee numa linha só)"
    reason: "Diferido pra v14+ quando Shopee/Amazon integrarem. Hoje 0 empresas têm mais de 1 marketplace ativo (Phase 57 SUMMARY: 126 meli / 0 shopee / 0 amazon), então a agregação real seria indistinguível do comportamento atual e não teria caso de teste. Rota/pipeline preparados via delegate a index() com filtro opcional; lógica whereHas('marketplaces', ...) + soma cross-marketplace documentada em 58-CONTEXT.md §2 racional + Deferred ideas. Requisito DASH-01 mantém-se como Complete no REQUIREMENTS.md porque o comportamento observável hoje é o esperado até o modelo N:N ter dados reais."
    accepted_by: "MB.ECF-100376 (dev.01@ecfconsultoria.com.br)"
    accepted_at: "2026-07-06"
human_verification_pending:
  - "Renderização visual do sidebar (grupo Mercado Livre — ECF Consolidado + Mercado Livre) em browser autenticado"
  - "Renderização visual dos shells /dashboard/shopee e /dashboard/amazon (header, KPI cards, CTA amarelo)"
  - "UAT em produção das 5 URLs (/dashboard/{ecf,mercadolivre,shopee,amazon,legacy}) em admin.ecfconsultoria.com.br após deploy"
gaps: []
gaps_resolved:
  - truth: "DASH-01 (texto literal REQUIREMENTS.md): '/dashboard/ecf' soma resultados através de marketplaces de todas as empresas atendidas; empresa em ML+Shopee soma ambos numa linha só; empresa só ML aparece com valores só ML"
    status: partial
    reason: "DashboardController::ecf() apenas delega para index() SEM nenhum filtro e SEM nenhuma lógica de agregação por marketplace. Não existe join/soma contra a tabela company_marketplaces (Phase 57) nem qualquer código que combine métricas de uma empresa em múltiplos marketplaces numa linha única. O comportamento hoje é 100% idêntico ao dashboard legacy porque as 126 empresas atuais são todas 'meli' — mas isso é coincidência de dados, não uma capacidade de agregação implementada. Se uma empresa tivesse hoje registros ML+Shopee simultâneos, o dashboard NÃO somaria os dois; ele usaria apenas a coluna flat `companies.marketplace` (valor único) para decidir se a empresa aparece ou não sob um filtro."
    artifacts:
      - path: "app/Http/Controllers/DashboardController.php"
        issue: "Método ecf() (linhas 69-74) e adminDashboard() (linhas 153-520) não contêm nenhuma lógica de soma/agregação cross-marketplace por empresa; usam apenas `where('marketplace', $marketplaceFilter)` (filtro binário, não soma)"
    missing:
      - "Lógica real de agregação por empresa através de company_marketplaces (whereHas/join + soma de métricas por marketplace) — documentada como 'v14+' em 58-CONTEXT.md §2, mas isso não estava explícito como redução de escopo no ROADMAP.md/REQUIREMENTS.md, que descrevem o comportamento de soma como entregável desta phase"
      - "Teste que exercite pelo menos 1 empresa com 2 marketplaces ativos (company_marketplaces) e valide que o dashboard soma os valores numa linha — não existe em nenhum dos 3 arquivos de teste da Phase 58"
---

# Phase 58: Dashboard ECF agregado + shells por marketplace — Relatório de Verificação

**Phase Goal:** Dashboard ECF (`/dashboard/ecf`) soma resultados através de marketplaces. Dashboards por marketplace (`/dashboard/{mercadolivre,shopee,amazon}`) — ML mantém funcionalidade atual, Shopee/Amazon shells.
**Verificado em:** 2026-07-03T21:45:00Z (revisado em 2026-07-06)
**Status:** human_needed (com 1 override aceito)
**Re-verificação:** Sim — override aceito 2026-07-06 formaliza redução de escopo v14+ documentada em CONTEXT §2

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | `GET /dashboard/ecf` retorna 200 e renderiza dashboard funcional | ✓ VERIFIED | `php artisan route:list --path=dashboard` mostra `dashboard/ecf` → `ecf.dashboard` → `DashboardController@ecf`. Teste `DashboardRoutesTest::test_dashboard_ecf_route_retorna_200` PASS |
| 2 | `GET /dashboard/mercadolivre` retorna 200 com filter=meli aplicado | ✓ VERIFIED | `DashboardController::mercadolivre()` (linha 80-87) faz `$request->merge(['marketplace' => 'meli'])` antes de delegar a `index()`. Teste `DashboardRoutesTest::test_dashboard_mercadolivre_route_retorna_200` PASS |
| 3 | `GET /dashboard/shopee` renderiza componente Inertia `Dashboard/ShopeeShell` | ✓ VERIFIED | `DashboardController::shopee()` (linha 93-99) retorna `Inertia::render('Dashboard/ShopeeShell', ['marketplace'=>'shopee','label'=>'Shopee'])`. Arquivo `resources/js/Pages/Dashboard/ShopeeShell.jsx` existe (74 linhas), presente no manifest Vite (`public/build/manifest.json:1844-1848`). Teste `DashboardShellsBackendTest::test_shopee_shell_renderiza_componente_e_props` + `DashboardNavigationSmokeTest::test_shopee_dashboard_renderiza_shell` PASS |
| 4 | `GET /dashboard/amazon` renderiza componente Inertia `Dashboard/AmazonShell` | ✓ VERIFIED | Idem, `DashboardController::amazon()` (linha 105-111), `AmazonShell.jsx` existe (76 linhas), manifest linha 1813-1817. Testes correspondentes PASS |
| 5 | Sidebar mostra `ECF Consolidado` + `Mercado Livre` no grupo Mercado Livre (não mais "Dashboard") | ✓ VERIFIED (código) / ? UNCERTAIN (visual) | `resources/js/Layouts/AppLayout.jsx` linhas 44-47: item `ECF Consolidado` (routeName `ecf.dashboard`, icon `PieChart`) adicionado no topo; item antigo `Dashboard` renomeado para `Mercado Livre` (routeName `mercadolivre.dashboard`). Grep `routeName: 'dashboard'` fora de comentário retorna 0 ocorrências (confirmado). Renderização visual real no browser não testada por grep — ver seção Human Verification |
| 6 | `GET /dashboard` (canonical antigo) continua funcionando para deep links | ✓ VERIFIED | `routes/web.php:138` mantém `Route::get('/dashboard', ...)->name('dashboard')` intocada. Teste `DashboardRoutesTest::test_dashboard_legacy_route_continua_ativa` + `DashboardNavigationSmokeTest::test_legacy_dashboard_ainda_navegavel` PASS |
| 7 | Zero regressão nas rotas/testes existentes (Phase 57 baseline) | ✓ VERIFIED | `php artisan test --filter=Phase57` → **20/20 passed** (26 assertions), rodado nesta verificação |
| 8 | Testes Phase58 verdes: 4 rotas novas + filter + shells + smoke E2E | ✓ VERIFIED | `php artisan test --filter=Phase58` → **16/16 passed** (62 assertions), rodado nesta verificação (5 DashboardRoutesTest + 4 DashboardFilterTest + 2 DashboardShellsBackendTest + 5 DashboardNavigationSmokeTest) |
| 9 | Whitelist em `?marketplace=` (nullable\|string\|in:meli,shopee,amazon) rejeita valores fora do whitelist | ✓ VERIFIED | `$request->validate(['marketplace' => 'nullable|string|in:meli,shopee,amazon'])` em `ecf()` (linha 71) e `mercadolivre()` (linha 84); `userDashboard()` valida via `in_array` explícito (linha 802). Teste `DashboardFilterTest::test_filter_marketplace_invalido_rejeitado` (payload `evil<script>`) retorna 422 — PASS |
| 10 | Requisitos DASH-01/02/03 rastreáveis via `requirements` frontmatter | ✓ VERIFIED | `58-01-PLAN.md` frontmatter: `requirements: [DASH-01, DASH-02, DASH-03]`; `58-02-PLAN.md`: `[DASH-03]`; `58-03-PLAN.md`: `[DASH-01, DASH-02, DASH-03]`. REQUIREMENTS.md marca as 3 como `Complete`, mapeadas para Phase 58 |
| 11 | **(REQUIREMENTS.md literal)** `/dashboard/ecf` **soma** resultados através de marketplaces por empresa (empresa ML+Shopee soma numa linha só) | ✗ FAILED | Ver gap detalhado abaixo — nenhuma lógica de agregação cross-marketplace existe; `ecf()` apenas delega ao pipeline sem filtro |

**Score:** 9/10 truths centrais do fluxo (rotas/shells/nav/regressão) verificadas; 1 gap de escopo na semântica de agregação do DASH-01.

### Required Artifacts

| Artifact | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `routes/web.php` | 4 rotas nomeadas + legacy preservada | ✓ VERIFIED | Linhas 138-145: `dashboard`, `ecf.dashboard`, `mercadolivre.dashboard`, `shopee.dashboard`, `amazon.dashboard` — todas dentro do grupo `auth+verified` |
| `app/Http/Controllers/DashboardController.php` | 4 métodos públicos + filtro whitelist | ✓ VERIFIED | `ecf()`, `mercadolivre()`, `shopee()`, `amazon()` presentes (linhas 69-111); filtro aplicado em `adminDashboard()` (linha 191) e `userDashboard()` (linha 802) |
| `resources/js/Pages/Dashboard/ShopeeShell.jsx` | Componente presentational com props marketplace/label | ✓ VERIFIED | 74 linhas, `export default function ShopeeShell({ marketplace, label })`, AppLayout+Head, 4 KPI cards, CTA `route('ecf.dashboard')`, ícone `/images/shopee-icon.svg` |
| `resources/js/Pages/Dashboard/AmazonShell.jsx` | Idem, espelhado | ✓ VERIFIED | 76 linhas, estrutura idêntica, ícone `/images/icons8-amazon.svg` |
| `tests/Feature/Phase58/DashboardRoutesTest.php` | 5 tests (4 rotas + legacy) | ✓ VERIFIED | 5 métodos `test_`, todos PASS |
| `tests/Feature/Phase58/DashboardFilterTest.php` | 4 tests whitelist | ✓ VERIFIED | 4 métodos `test_`, todos PASS |
| `tests/Feature/Phase58/DashboardShellsBackendTest.php` | 2 tests contrato Inertia | ✓ VERIFIED | 2 métodos `test_`, todos PASS |
| `tests/Feature/Phase58/DashboardNavigationSmokeTest.php` | 5 tests smoke E2E | ✓ VERIFIED | 5 métodos `test_`, todos PASS, usa `AssertableInertia` |
| `resources/js/Layouts/AppLayout.jsx` | NAV_TREE atualizado | ✓ VERIFIED | Linhas 44-47 — `ECF Consolidado` + `Mercado Livre` (renomeado), 0 ocorrência de `routeName: 'dashboard'` fora de comentário |

### Key Link Verification

| From | To | Via | Status | Detalhes |
|------|-----|-----|--------|----------|
| `routes/web.php` | `DashboardController::ecf\|mercadolivre\|shopee\|amazon` | `Route::get(...)` | ✓ WIRED | Confirmado via `route:list` — os 4 métodos resolvem corretamente |
| `DashboardController::mercadolivre` | `DashboardController::index` | `$request->merge(['marketplace' => 'meli'])` | ✓ WIRED | Linha 82, confirmado por teste `test_mercadolivre_dashboard_renderiza_componente_admin` (assertInertia component Dashboard/Admin) |
| `DashboardController::adminDashboard` | `Company::query` | `->where('marketplace', $marketplaceFilter)` quando presente | ✓ WIRED | Linha 191 (companiesQuery) + linha 519 (`allCompanies` via `->when()`) |
| `ShopeeShell.jsx` / `AmazonShell.jsx` | `route('ecf.dashboard')` | `<Link href={route('ecf.dashboard')}>` | ✓ WIRED | Confirmado em ambos os arquivos (linha 60/62 respectivamente) |
| `AppLayout.jsx` NAV_TREE | `route('ecf.dashboard')` / `route('mercadolivre.dashboard')` | item `routeName` | ✓ WIRED | Grep confirma presença; smoke E2E confirma que as rotas resolvem para os componentes certos |
| `DashboardController::ecf` | `company_marketplaces` (pivot Phase 57) | agregação cross-marketplace | ✗ NOT_WIRED | Não existe nenhuma chamada a `CompanyMarketplace`/`whereHas('marketplaces', ...)` em `ecf()` ou `adminDashboard()` — apenas a coluna flat é usada para filtro binário, não soma |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produz Dado Real | Status |
|----------|---------------|--------|-------------------|--------|
| `Dashboard/Admin.jsx` (via ecf/mercadolivre) | `companies`, `metrics` | `AdmanMetric::whereIn('company_id', ...)` — query real contra tabela `adman_metrics` | Sim | ✓ FLOWING |
| `ShopeeShell.jsx` / `AmazonShell.jsx` | `marketplace`, `label` | Constantes hardcoded no controller (`'shopee'`/`'Shopee'`, `'amazon'`/`'Amazon'`) | Sim (valores estáticos intencionais — mockup documentado) | ✓ FLOWING (mockup consciente, não stub oculto) |
| `DashboardController::ecf` | Agregação cross-marketplace por empresa | **Nenhuma fonte** — não há query que combine dados de `company_marketplaces` | Não | ✗ DISCONNECTED (funcionalidade descrita no requisito não implementada) |

### Behavioral Spot-Checks

| Comportamento | Comando | Resultado | Status |
|---------------|---------|-----------|--------|
| 5 rotas dashboard registradas | `php artisan route:list --path=dashboard` | `dashboard`, `dashboard/amazon`, `dashboard/ecf`, `dashboard/mercadolivre`, `dashboard/shopee` (+ `mlb/dashboard`, não relacionada) | ✓ PASS |
| Suite Phase 58 completa | `php artisan test --filter=Phase58` | 16 passed (62 assertions) | ✓ PASS |
| Suite Phase 57 (regressão) | `php artisan test --filter=Phase57` | 20 passed (26 assertions) | ✓ PASS |
| Manifest Vite contém os shells | `grep ShopeeShell\|AmazonShell public/build/manifest.json` | Ambos os chunks presentes (`ShopeeShell-DNc1FcwF.js`, `AmazonShell-DkY82DMB.js`) | ✓ PASS |
| Working tree limpo para arquivos da Phase 58 | `git status --short` + `git log` | Todos os 6 arquivos da phase committed (`9e9d9be`, `e0e5895`, `addcb82`, `48890bd`, `a45c838`); mudanças pendentes no working tree são de outra frente de trabalho (CompanyController, GoalController, etc — não relacionadas) | ✓ PASS |

### Probe Execution

Não aplicável — fase não declara probes dedicados (`scripts/*/tests/probe-*.sh`); verificação via suíte PHPUnit Feature cobre o contrato.

### Requirements Coverage

| Requirement | Plan de origem | Descrição | Status | Evidência |
|-------------|----------------|-----------|--------|-----------|
| DASH-01 | 58-01, 58-03 | `/dashboard/ecf` mostra KPIs consolidados somando empresa × marketplaces | ⚠ PARCIAL | Rota/controller/nav existem e funcionam (E2E OK), mas a **semântica de soma cross-marketplace por empresa** não está implementada — ver gap acima. Hoje indistinguível do legacy porque 100% das empresas são `meli` |
| DASH-02 | 58-01, 58-03 | `/dashboard/mercadolivre` mantém dashboard atual | ✓ SATISFIED | Delega 100% ao pipeline existente com filtro `marketplace=meli`; zero mudança de lógica; 20/20 testes Phase57 continuam verdes |
| DASH-03 | 58-01, 58-02, 58-03 | Shells Shopee/Amazon "em desenvolvimento" | ✓ SATISFIED | Componentes existem, renderizam via Inertia, build Vite limpo, testes E2E confirmam contrato |

Nenhum requisito órfão encontrado — os 3 IDs (DASH-01/02/03) aparecem no campo `requirements` de pelo menos um plan e em REQUIREMENTS.md com o mesmo mapeamento.

### Anti-Patterns Found

| Arquivo | Linha | Padrão | Severidade | Impacto |
|---------|-------|--------|------------|---------|
| `resources/js/Pages/Dashboard/ShopeeShell.jsx` / `AmazonShell.jsx` | 30/32 (comentário) | `placeholder` (comentário pt-BR) | ℹ️ Info | Documentado e intencional — mockup "em desenvolvimento" com CTA claro, não é stub silencioso |
| `app/Http/Controllers/DashboardController.php` | 817 | `// TODO Phase 18+` | ℹ️ Info | Pré-existente (não introduzido pela Phase 58), fora do escopo desta phase, referencia trabalho futuro nomeado ("Phase 18+") |

Nenhum `TBD`/`FIXME`/`XXX` sem referência formal encontrado nos arquivos modificados pela Phase 58.

## Human Verification Required

### 1. Renderização visual do sidebar (NAV_TREE)

**Teste:** Logar como admin em ambiente local/staging, abrir o sidebar e conferir o grupo "Mercado Livre".
**Esperado:** Primeiro item é "ECF Consolidado" (ícone PieChart) seguido de "Mercado Livre" (ícone LayoutDashboard); nenhum item chamado apenas "Dashboard" aparece mais.
**Por que humano:** Grep confirma a estrutura de dados do NAV_TREE, mas não valida renderização visual (ordem, ícones, destaque de item ativo) no browser real.

### 2. Renderização visual dos shells Shopee/Amazon

**Teste:** Navegar diretamente para `/dashboard/shopee` e `/dashboard/amazon` autenticado como admin.
**Esperado:** Header com logo da marca, 4 KPI cards com "—" e "Aguardando integração", card explicativo com ícone Construction e botão amarelo "Ver Dashboard ECF Consolidado" funcional.
**Por que humano:** Testes automatizados verificam o contrato Inertia (componente + props), não o resultado visual renderizado (CSS, responsividade, legibilidade).

### 3. UAT em produção das 5 URLs (já mapeado no 58-03-SUMMARY.md)

**Teste:** Após deploy, validar `/dashboard/ecf`, `/dashboard/mercadolivre`, `/dashboard/shopee`, `/dashboard/amazon`, `/dashboard` (legacy) em `admin.ecfconsultoria.com.br`.
**Esperado:** Todas navegáveis sem erro 500, sidebar correto, shells com CTA funcional.
**Por que humano:** Ambiente de produção (dados reais, cache, CDN de assets) não é replicado pelos testes locais.

## Gaps Summary

A phase entrega com solidez a família de rotas, os métodos de controller, os shells JSX, a atualização do NAV_TREE e a suíte de 36 testes verdes (20 Phase57 + 16 Phase58) — nenhuma regressão detectada. DASH-02 e DASH-03 estão genuinamente satisfeitos ponta a ponta.

**O gap real está em DASH-01**: o texto de REQUIREMENTS.md e o goal do ROADMAP.md descrevem explicitamente uma capacidade de **soma cross-marketplace por empresa** ("empresa em ML+Shopee soma ambos numa linha só"). O que foi implementado é uma rota que **delega sem filtro** ao pipeline existente — não há nenhuma lógica de agregação, join, ou soma contra a tabela `company_marketplaces` (criada na Phase 57 exatamente para isso). O 58-CONTEXT.md documenta essa decisão de forma transparente ("hoje isso retorna os mesmos 126 [registros], no futuro filtra corretamente" / "v14+ vai agregar via CompanyMarketplace pivot"), e a decisão é defensável dado que hoje 0 empresas têm mais de um marketplace ativo (Phase 57 SUMMARY). Mas isso configura uma redução de escopo do ROADMAP/REQUIREMENTS que não foi formalizada como override explícito em nenhum artefato de verificação — apenas como nota de rationale dentro do CONTEXT.md da própria phase, sem confirmação humana registrada nesse ponto específico.

**Isto parece intencional.** Para aceitar esse desvio, adicionar ao frontmatter deste VERIFICATION.md:

```yaml
overrides:
  - must_have: "DASH-01: /dashboard/ecf soma resultados através de marketplaces por empresa (ML+Shopee numa linha só)"
    reason: "Hoje 0 empresas têm mais de 1 marketplace ativo (Phase 57: 126 meli / 0 shopee / 0 amazon); agregação real fica para v14+ quando o modelo N:N tiver dados reais para agregar. Rota/pipeline preparados (delegam a index() com filtro opcional), lógica de soma cross-marketplace é trabalho futuro documentado em 58-CONTEXT.md §2."
    accepted_by: "{nome}"
    accepted_at: "{timestamp}"
```

Se o override for aceito, a phase deve ser re-verificada com o override no frontmatter para fechar com `status: passed` (mais os 3 itens de verificação humana, que empurrariam para `human_needed`).

---

*Verificado: 2026-07-03T21:45:00Z*
*Verificador: Claude (gsd-verifier)*
