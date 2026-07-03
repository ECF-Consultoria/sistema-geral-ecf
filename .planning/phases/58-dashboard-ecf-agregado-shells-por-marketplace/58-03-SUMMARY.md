---
phase: 58-dashboard-ecf-agregado-shells-por-marketplace
plan: 03
subsystem: ui
tags: [react, inertia, laravel, nav-tree, e2e-smoke, dashboard, multi-marketplace]

# Dependency graph
requires:
  - phase: 58-dashboard-ecf-agregado-shells-por-marketplace
    provides: "4 rotas nomeadas (ecf.dashboard, mercadolivre.dashboard, shopee.dashboard, amazon.dashboard) + 4 metodos DashboardController (Plan 58-01)"
  - phase: 58-dashboard-ecf-agregado-shells-por-marketplace
    provides: "Componentes Dashboard/ShopeeShell.jsx e Dashboard/AmazonShell.jsx no manifest Vite (Plan 58-02)"
provides:
  - "NAV_TREE de AppLayout.jsx atualizado: item 'ECF Consolidado' (route ecf.dashboard) no topo do grupo Mercado Livre + item 'Dashboard' renomeado para 'Mercado Livre' (route mercadolivre.dashboard)"
  - "Smoke test E2E (tests/Feature/Phase58/DashboardNavigationSmokeTest.php) validando os 4 caminhos completos route -> controller -> componente Inertia + rota legacy"
  - "Fechamento da Phase 58: 36 tests verdes (20 Phase57 + 16 Phase58), zero regressao"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "AssertableInertia com manifest Vite ja populado (pos Plan 58-02) — full-page GET sem headers X-Inertia funciona normalmente com assertInertia(), diferente da tecnica assertJson()+X-Inertia usada no Plan 58-01 quando os componentes JSX ainda nao existiam"

key-files:
  created:
    - tests/Feature/Phase58/DashboardNavigationSmokeTest.php
  modified:
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Item canonical 'Dashboard' (routeName='dashboard') removido do NAV_TREE (sai do menu) mas rota permanece registrada em routes/web.php para deep links/bookmarks (CONTEXT §5) — nao migrado neste plan"
  - "Itens estaticos Shopee/Amazon do sidebar (badge 'Em breve' apontando para /em-desenvolvimento) NAO tocados nesta plan — decisao consciente, ver secao abaixo"

patterns-established:
  - "Smoke E2E de navegacao: valida route->controller->componente via AssertableInertia sem depender de valores de KPI (deferido para UAT)"

requirements-completed: [DASH-01, DASH-02, DASH-03]

# Metrics
duration: ~15min
completed: 2026-07-03
---

# Phase 58 Plan 03: NAV_TREE + Smoke E2E Summary

**NAV_TREE do AppLayout.jsx costurado com as rotas do Plan 58-01 (item "ECF Consolidado" no topo do grupo Mercado Livre + rename "Dashboard"→"Mercado Livre") e smoke test E2E de 5 assertions validando os 4 caminhos completos route→controller→componente Inertia, fechando a Phase 58 com 36 tests verdes (20 Phase 57 + 16 Phase 58) e zero regressão.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-03T18:20:00-03:00 (aprox.)
- **Completed:** 2026-07-03T18:25:00-03:00 (aprox.)
- **Tasks:** 3 (2 com commit + 1 gate de validação sem mudança de arquivo)
- **Files modified:** 2 (1 modificado + 1 novo)

## Accomplishments
- `AppLayout.jsx`: item `ECF Consolidado` adicionado no topo do grupo Mercado Livre (`routeName: 'ecf.dashboard'`, ícone `PieChart` já importado, `permission: 'core.dashboard'`).
- `AppLayout.jsx`: item antigo `Dashboard` renomeado para `Mercado Livre` (`routeName: 'dashboard'` → `mercadolivre.dashboard`), mantendo ícone `LayoutDashboard` e `permission: 'core.dashboard'`.
- Rota canonical `dashboard` removida do menu (nenhum item aponta mais para ela) mas permanece ativa em `routes/web.php` para deep links/bookmarks legados.
- `npm run build` (Vite) executado com sucesso — 0 erros, manifest atualizado.
- `tests/Feature/Phase58/DashboardNavigationSmokeTest.php` criado com 5 tests: `ecf.dashboard` e `mercadolivre.dashboard` renderizando `Dashboard/Admin`; `shopee.dashboard`/`amazon.dashboard` renderizando os shells com props `marketplace`/`label` corretas; rota legacy `/dashboard` ainda navegável.
- Gate final de zero regressão confirmado: **Phase 57 = 20/20 verdes**, **Phase 58 = 16/16 verdes** (5 rotas + 4 filter + 2 shells backend do Plan 58-01 + 5 navegação E2E deste plan).
- `php artisan route:list --path=dashboard` confirma as 5 rotas nomeadas ativas com os métodos corretos do `DashboardController`.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Atualizar NAV_TREE em AppLayout.jsx** - `48890bd` (feat)
2. **Task 2: Smoke test E2E de navegação** - `a45c838` (test)
3. **Task 3: Gate final de zero regressão** - sem commit adicional (apenas validação; nenhum arquivo alterado)

**Plan metadata:** (commit deste SUMMARY + STATE/ROADMAP, feito a seguir)

## Files Created/Modified
- `resources/js/Layouts/AppLayout.jsx` - NAV_TREE: 2 items no topo do grupo Mercado Livre (ECF Consolidado novo + Dashboard renomeado para Mercado Livre); comentários pt-BR `// NOVO Phase 58 DASH-01` e `// MUDADO Phase 58 DASH-02` marcando as mudanças.
- `tests/Feature/Phase58/DashboardNavigationSmokeTest.php` - 5 tests Feature validando navegação E2E das 4 rotas novas + rota legacy, usando `AssertableInertia` para checar componente e props.

## NAV_TREE — Antes/Depois (grupo Mercado Livre, topo da seção Performance)

**Antes:**
```javascript
{ label: 'Dashboard',   routeName: 'dashboard',           page: 'Dashboard',    icon: LayoutDashboard, permission: 'core.dashboard' },
{ label: 'Desempenho',  routeName: 'performance.index',   page: 'Performance',  icon: Trophy,          permission: 'core.performance' },
```

**Depois:**
```javascript
// NOVO Phase 58 DASH-01 — agregado atraves de marketplaces
{ label: 'ECF Consolidado', routeName: 'ecf.dashboard',          page: 'Dashboard/Admin', icon: PieChart,        permission: 'core.dashboard' },
// MUDADO Phase 58 DASH-02 — antes era { label: 'Dashboard', routeName: 'dashboard' }; rota legacy segue registrada para deep links (CONTEXT §5).
{ label: 'Mercado Livre',   routeName: 'mercadolivre.dashboard', page: 'Dashboard/Admin', icon: LayoutDashboard, permission: 'core.dashboard' },
{ label: 'Desempenho',  routeName: 'performance.index',   page: 'Performance',  icon: Trophy,          permission: 'core.performance' },
```

Nenhum outro item do grupo Mercado Livre (Desempenho, Empresas, Carteira, Sugadores, Metas, PPA, dividers, seções Dados Estratégicos e Polos) foi tocado. Nenhum import novo foi necessário — `PieChart` já estava importado de `lucide-react` (linha 9) desde a Phase 56.

## Decisions Made
- Item canonical `Dashboard` (routeName `dashboard`) sai do sidebar — decisão travada no CONTEXT §5, sem alteração. Rota `routes/web.php` permanece intocada e ativa; nenhuma referência `route('dashboard')` no restante do codebase (redirects de logout/auth, logo do sidebar) foi migrada — comportamento intencional documentado no CONTEXT.
- Itens estáticos "Shopee" e "Amazon" no nível raiz do sidebar (badge "Em breve", apontando para `/em-desenvolvimento`) **não foram tocados** nesta plan — decisão consciente confirmada no CONTEXT §escopo. As novas rotas `/dashboard/shopee` e `/dashboard/amazon` (Plan 58-01/58-02) só são acessíveis por navegação direta (URL) ou por link/CTA dentro dos próprios shells; adicionar itens de sidebar dedicados fica para decisão futura no próximo touchpoint UAT.
- Smoke test usa `AssertableInertia` (não `assertJson()`+headers manuais como no Plan 58-01) — viável agora porque o manifest Vite já contém os componentes reais `Dashboard/ShopeeShell` e `Dashboard/AmazonShell` desde o Plan 58-02, permitindo full-page GET sem simular headers `X-Inertia`.

## Deviations from Plan

None - plan executado exatamente como escrito. Nenhuma correção de bug, nenhuma funcionalidade crítica faltante identificada, nenhuma decisão arquitetural nova necessária.

## Issues Encountered

- `php` não estava no PATH do shell Git Bash usado pela execução (apenas `npm`/`node` estavam). Resolvido localmente exportando `PATH="/c/xampp/php:$PATH"` para rodar `php artisan test` e `php artisan route:list` — não é uma mudança de código, apenas ajuste de ambiente da sessão de execução; não requer nenhuma ação do usuário nem alteração de configuração do projeto.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Preparo para UAT em produção

Após deploy, o usuário deve validar visualmente as 4 URLs abaixo (autenticado como admin):

1. `https://admin.ecfconsultoria.com.br/dashboard/ecf` — Dashboard ECF Consolidado (sidebar: "ECF Consolidado", topo do grupo Mercado Livre)
2. `https://admin.ecfconsultoria.com.br/dashboard/mercadolivre` — Dashboard Mercado Livre com filtro ML aplicado (sidebar: "Mercado Livre", logo abaixo do ECF Consolidado)
3. `https://admin.ecfconsultoria.com.br/dashboard/shopee` — Shell "Dashboard Shopee" (mockup KPI cards vazios + CTA para ECF Consolidado) — acessível apenas via URL direta (sem item de sidebar dedicado)
4. `https://admin.ecfconsultoria.com.br/dashboard/amazon` — Shell "Dashboard Amazon" idem
5. `https://admin.ecfconsultoria.com.br/dashboard` — rota legacy, deve continuar funcionando (compat de deep link), mas SEM item correspondente no sidebar

## Next Phase Readiness
- Phase 58 fechada: DASH-01, DASH-02 e DASH-03 completos do ponto de vista E2E (rota + controller + componente + item de menu + teste).
- Nenhum blocker conhecido. Nenhuma dependência pendente para milestones futuras.
- v14+ (quando Shopee/Amazon integrarem de verdade): revisitar se os shells devem ganhar itens de sidebar dedicados, e migrar filtro `?marketplace=` de coluna flat para `whereHas('marketplaces', ...)` (decisão já documentada no CONTEXT da Phase 58 e Phase 57).

## Known Stubs
Nenhum stub novo introduzido nesta plan. Os shells Shopee/Amazon (stubs intencionais documentados no Plan 58-02) permanecem sem item de sidebar dedicado — comportamento consciente, não uma lacuna de implementação.

## Self-Check: PASSED

Arquivos criados e commits referenciados verificados como existentes:
- `resources/js/Layouts/AppLayout.jsx` — FOUND (modificado)
- `tests/Feature/Phase58/DashboardNavigationSmokeTest.php` — FOUND
- Commits `48890bd`, `a45c838` — FOUND em `git log --oneline`

---
*Phase: 58-dashboard-ecf-agregado-shells-por-marketplace*
*Completed: 2026-07-03*
