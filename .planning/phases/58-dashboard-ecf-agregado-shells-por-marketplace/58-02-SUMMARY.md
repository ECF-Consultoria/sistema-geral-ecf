---
phase: 58-dashboard-ecf-agregado-shells-por-marketplace
plan: 02
subsystem: ui
tags: [react, inertia, jsx, dashboard, multi-marketplace, design-system-ecf]

# Dependency graph
requires:
  - phase: 58-dashboard-ecf-agregado-shells-por-marketplace
    provides: "rotas GET shopee.dashboard/amazon.dashboard + metodos DashboardController::shopee/amazon retornando Inertia::render('Dashboard/ShopeeShell'|'Dashboard/AmazonShell', ['marketplace', 'label']) (Plan 58-01)"
provides:
  - "resources/js/Pages/Dashboard/ShopeeShell.jsx — componente React shell para /dashboard/shopee"
  - "resources/js/Pages/Dashboard/AmazonShell.jsx — componente React shell para /dashboard/amazon"
  - "Manifest Vite atualizado (npm run build) — desbloqueia full-page navigation real das rotas shopee/amazon (antes so testadas via X-Inertia JSON no Plan 58-01)"
affects: [58-03-nav-tree]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Shell mockup 'em desenvolvimento' com KPI cards placeholder (valor em-dash + label 'Aguardando integracao') em vez de dashboard vazio/zerado — evita confundir usuario"
    - "Duplicacao intencional de 2 componentes quase identicos (ShopeeShell/AmazonShell) em vez de 1 componente parametrico — decisao explicita do CONTEXT §3 para permitir divergencia futura (SP-API vs Open Platform)"

key-files:
  created:
    - resources/js/Pages/Dashboard/ShopeeShell.jsx
    - resources/js/Pages/Dashboard/AmazonShell.jsx
  modified: []

key-decisions:
  - "Componentes 100% presentational (sem useState/useEffect) — props marketplace/label vem prontas do controller, sem logica de fetch"
  - "Duplicacao ShopeeShell/AmazonShell mantida conforme CONTEXT §3 (nao extrair componente comum agora)"

patterns-established:
  - "Shell de marketplace pendente: header com icone + label + badge 'Em desenvolvimento', grid 4 KPI cards placeholder, card CTA com Construction icon + Link para dashboard consolidado"

requirements-completed: [DASH-03]

# Metrics
duration: ~15min
completed: 2026-07-03
---

# Phase 58 Plan 02: Shells JSX Shopee/Amazon Summary

**2 componentes React presentational (`ShopeeShell.jsx` + `AmazonShell.jsx`) que renderizam mockup "em desenvolvimento" com KPI cards placeholder e CTA para o Dashboard ECF Consolidado, consumidos pelas rotas `/dashboard/shopee` e `/dashboard/amazon` já prontas no backend (Plan 58-01).**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-03T20:58:00Z (aprox.)
- **Completed:** 2026-07-03T21:13:26Z
- **Tasks:** 2
- **Files modified:** 2 (ambos novos)

## Accomplishments
- `Dashboard/ShopeeShell.jsx` criado: header com ícone `/images/shopee-icon.svg` + h1 "Dashboard Shopee" + badge "Em desenvolvimento"; grid de 4 KPI cards placeholder (GMV, Vendas, ROAS, Sellers) mostrando "—" e "Aguardando integração"; card explicativo com ícone `Construction` (lucide-react) e CTA `Link href={route('ecf.dashboard')}` estilizado com `bg-ecf-yellow`.
- `Dashboard/AmazonShell.jsx` criado espelhando o layout do Shopee, trocando apenas ícone (`/images/icons8-amazon.svg`) e mantendo os textos parametrizados via prop `{label}` — código agnóstico do marketplace concreto.
- `npm run build` (Vite) executado 2x (uma vez por task) — 0 erros, manifest atualizado com os 2 novos chunks JS.
- Ambos componentes seguem o design system ECF: tokens `bg-ecf-card`, `border-white/[0.06]`, `text-ecf-yellow`, utility `cn()` de `@/lib/utils`, wrapper `AppLayout`, tudo em pt-BR (comentários e textos).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Criar Dashboard/ShopeeShell.jsx** - `e0e5895` (feat)
2. **Task 2: Criar Dashboard/AmazonShell.jsx** - `addcb82` (feat)

**Plan metadata:** (commit deste SUMMARY + STATE/ROADMAP, feito a seguir)

## Files Created/Modified
- `resources/js/Pages/Dashboard/ShopeeShell.jsx` - Shell mockup "Dashboard Shopee" (74 linhas): header com marca, 4 KPI cards placeholder, card CTA para ECF Consolidado.
- `resources/js/Pages/Dashboard/AmazonShell.jsx` - Shell mockup "Dashboard Amazon" (76 linhas): layout espelhado, ícone e label parametrizados.

## Decisions Made
- Duplicação dos 2 shells em vez de extração para componente comum: decisão já travada no CONTEXT §3, mantida sem alteração — v14+ pode divergir (Amazon SP-API vs Shopee Open Platform terão KPIs e fluxos de integração diferentes).
- Componentes puramente presentational — nenhum `useState`/`useEffect`, conforme instrução explícita do plan (evita complexidade desnecessária para um mockup).

## Deviations from Plan

None - plan executado exatamente como escrito. Nenhuma correção de bug, nenhuma funcionalidade crítica faltante identificada, nenhuma decisão arquitetural nova necessária.

## Issues Encountered
None - build Vite passou sem erros/warnings em ambas as tasks.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- Plan 58-03 (NAV_TREE) pode agora referenciar `Dashboard/ShopeeShell` e `Dashboard/AmazonShell` livremente no campo `page` do NAV_TREE — ambos componentes existem no manifest Vite e renderizam sem erro.
- Os testes `DashboardRoutesTest::test_dashboard_shopee_route_retorna_200` e `test_dashboard_amazon_route_retorna_200` (Plan 58-01, via header `X-Inertia`) continuam válidos sem alteração — o manifest atualizado por este plan não quebra o contrato JSON testado.
- Navegação full-page real de `/dashboard/shopee` e `/dashboard/amazon` (sem header `X-Inertia`) agora funciona de ponta a ponta — antes do Plan 58-02 essa rota quebraria com "Unable to locate file in Vite manifest" (ver Plan 58-01 SUMMARY, Deviation #2).
- Nenhum blocker conhecido.

## Known Stubs
Nenhum stub silencioso. Os valores "—" (em-dash) e o texto "Aguardando integração" nos KPI cards são placeholders INTENCIONAIS e documentados — fazem parte do mockup "em desenvolvimento" especificado no CONTEXT §3, não vazamento de dados incompletos. Serão substituídos por KPIs reais quando Shopee/Amazon integrarem de fato (v14+).

## Self-Check: PASSED

Arquivos criados e commits referenciados verificados como existentes:
- `resources/js/Pages/Dashboard/ShopeeShell.jsx` — FOUND
- `resources/js/Pages/Dashboard/AmazonShell.jsx` — FOUND
- Commits `e0e5895`, `addcb82` — FOUND em `git log --oneline`

---
*Phase: 58-dashboard-ecf-agregado-shells-por-marketplace*
*Completed: 2026-07-03*
