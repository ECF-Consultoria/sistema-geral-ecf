---
phase: 58
name: Dashboard ECF agregado + shells por marketplace
milestone: v13.0
captured: 2026-07-03
requirements: [DASH-01, DASH-02, DASH-03]
---

# Phase 58 — CONTEXT

## Domain

Cria a família de dashboards multi-marketplace: `/dashboard/ecf` (agregado através de marketplaces), `/dashboard/mercadolivre` (filter ML) e shells dedicados `/dashboard/shopee` + `/dashboard/amazon` (mockup "em desenvolvimento"). Reorganiza o item Dashboard do sidebar para refletir a nova hierarquia.

**Reality check da Phase 57:** hoje 126 empresas no total, todas com `marketplace='meli'` (0 Shopee, 0 Amazon integradas). Isso significa que `/dashboard/ecf` e `/dashboard/mercadolivre` retornam dados IDÊNTICOS por enquanto — a distinção é semântica e prepara terreno pra v14+ (quando Shopee/Amazon integrarem de verdade).

## Canonical refs

- [app/Http/Controllers/DashboardController.php](../../../app/Http/Controllers/DashboardController.php) — 857 linhas, métodos `index()`, `adminDashboard()`, `userDashboard()`. Renderiza `Dashboard/Admin` ou `Dashboard/User`.
- [resources/js/Pages/Dashboard/Admin.jsx](../../../resources/js/Pages/Dashboard/Admin.jsx) — página real do dashboard admin
- [resources/js/Pages/Dashboard/User.jsx](../../../resources/js/Pages/Dashboard/User.jsx) — página real do dashboard user
- [resources/js/Layouts/AppLayout.jsx](../../../resources/js/Layouts/AppLayout.jsx) — NAV_TREE (Phase 56 v13.0) — item `Dashboard` dentro do grupo Mercado Livre precisa mudar
- [routes/web.php](../../../routes/web.php) linhas ~134-148 — grupo auth+verified com `Route::get('/dashboard', ...)` atual
- [app/Models/CompanyMarketplace.php](../../../app/Models/CompanyMarketplace.php) — model N:N da Phase 57 (para filter por marketplace quando fizer sentido migrar do flat)
- [.planning/phases/57-modelo-de-dados-multi-marketplace/PHASE-SUMMARY.md](../57-modelo-de-dados-multi-marketplace/PHASE-SUMMARY.md) — descoberta: 126 meli / 0 shopee / 0 amazon

## Locked decisions

### 1. Estratégia de rotas — rotas dedicadas com controller filter

**Decisão:** Criar 4 rotas novas nomeadas com métodos separados no `DashboardController`. Rota `/dashboard` atual **permanece** como canonical fallback (deep links/bookmarks antigos continuam funcionando; nenhum item do menu aponta pra ela mais).

```php
// Grupo auth+verified em routes/web.php
Route::get('/dashboard/ecf',          [DashboardController::class, 'ecf'])->name('ecf.dashboard');
Route::get('/dashboard/mercadolivre', [DashboardController::class, 'mercadolivre'])->name('mercadolivre.dashboard');
Route::get('/dashboard/shopee',       [DashboardController::class, 'shopee'])->name('shopee.dashboard');
Route::get('/dashboard/amazon',       [DashboardController::class, 'amazon'])->name('amazon.dashboard');
// Legacy — permanece para compat de deep links
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
```

**Racional:**
- Rotas dedicadas com controller methods = terreno pronto pra agregação real quando Shopee/Amazon integrarem
- Deep links antigos `/dashboard` continuam funcionando (nenhum item do menu aponta pra ela após Phase 58, mas navegador history + bookmarks preservados)
- Nome canonical do rota da milestone v13.0: `ecf.dashboard` (agregado) e `mercadolivre.dashboard` (filter ML)

### 2. DashboardController — 4 métodos novos, reusam o pipeline atual

**Decisão:** Adicionar 4 métodos ao `DashboardController` que reusam a lógica interna existente com um filter novo por marketplace slug.

```php
public function ecf(Request $request)
{
    // Agregado através de marketplaces — hoje == index() sem filter,
    // no futuro (v14+) JOIN company_marketplaces GROUP BY marketplace
    return $this->index($request);
}

public function mercadolivre(Request $request)
{
    // Filter ML: usa coluna flat companies.marketplace='meli' hoje
    // por performance; migrar pra whereHas('marketplaces', ...) quando
    // consumidores existentes forem migrados (v14+).
    $request->merge(['marketplace' => 'meli']);
    return $this->index($request);
}

public function shopee(Request $request)
{
    // Shell renderiza direto — NAO passa pelo pipeline normal (evita
    // dashboard vazio com KPIs zerados confusos)
    return Inertia::render('Dashboard/ShopeeShell', [
        'marketplace' => 'shopee',
        'label'       => 'Shopee',
    ]);
}

public function amazon(Request $request)
{
    return Inertia::render('Dashboard/AmazonShell', [
        'marketplace' => 'amazon',
        'label'       => 'Amazon',
    ]);
}
```

**Filter marketplace na query — implementação:**

O método `index()` já existe (`adminDashboard()` + `userDashboard()`). Adicionar filter opcional no `Request $request` que quando presente, restringe as queries de `Company::` a `where('marketplace', $filter)`.

Ponto de mudança em `adminDashboard()` (linha ~50-617 do controller): identificar a query base de `Company::` e adicionar `->when($request->marketplace, fn($q, $mp) => $q->where('marketplace', $mp))`. Hoje isso retorna os mesmos 126 (todas meli), no futuro filtra corretamente.

**Racional:**
- Reusa 100% do pipeline atual — zero risco de regressão
- Filter por coluna flat (`companies.marketplace`) = performance (índice existente); trocar pra `whereHas('marketplaces')` fica pra v14+ quando o modelo N:N estiver totalmente adotado
- Shells renderizam direto (bypass do pipeline) para evitar KPIs zerados confusos

### 3. Componentes Shell dedicados

**Decisão:** Criar 2 componentes React novos: `Dashboard/ShopeeShell.jsx` e `Dashboard/AmazonShell.jsx`. Cada um é um mockup visual do que o dashboard vai ser quando Shopee/Amazon integrar — KPI cards vazios/placeholders + mensagem clara "Integração em desenvolvimento".

**Design proposto** (consistente com design system ECF + Construction icon já usado no `EmDesenvolvimento.jsx`):

```jsx
// Estrutura visual — Dashboard/ShopeeShell.jsx (Amazon idem)
<AppLayout title="Dashboard Shopee">
    <Head title="Shopee em desenvolvimento" />
    <div className="p-6 space-y-6">
        {/* Header com marca */}
        <div className="flex items-center gap-3">
            <img src="/images/shopee-icon.svg" className="w-8 h-8" alt="Shopee" />
            <h1 className="text-white text-2xl font-display font-bold">
                Dashboard Shopee
            </h1>
            <span className="ml-auto text-xs bg-white/[0.08] text-white/60 px-2 py-1 rounded-full">
                Em desenvolvimento
            </span>
        </div>

        {/* KPI cards placeholder (mockup, sem dados reais) */}
        <div className="grid grid-cols-4 gap-4">
            {['GMV', 'Vendas', 'ROAS', 'Sellers'].map(kpi => (
                <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-4">
                    <div className="text-white/40 text-xs">{kpi}</div>
                    <div className="text-white/20 text-2xl font-bold mt-2">—</div>
                    <div className="text-white/30 text-[10px] mt-1">
                        Aguardando integração
                    </div>
                </div>
            ))}
        </div>

        {/* Card explicativo com CTA */}
        <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-8 text-center">
            <Construction size={48} className="mx-auto text-ecf-yellow mb-4" />
            <h2 className="text-white text-lg font-semibold mb-2">
                Integração Shopee em desenvolvimento
            </h2>
            <p className="text-white/60 text-sm max-w-md mx-auto mb-6">
                Estamos preparando o pipeline de dados Shopee — API, sync,
                métricas agregadas. Enquanto isso, o Dashboard ECF Consolidado
                já mostra os resultados totais das empresas.
            </p>
            <Link href={route('ecf.dashboard')}
                className="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 bg-ecf-yellow text-[#252525] text-[13px] font-semibold">
                <BarChart3 size={16} />
                Ver Dashboard ECF Consolidado
            </Link>
        </div>
    </div>
</AppLayout>
```

**Racional:**
- Mockup KPI cards vazios = **preview** do que vai existir; ajuda user a antecipar
- Mensagem clara + CTA para ECF Consolidado = evita ficar preso na tela
- Reusa design tokens `ecf-*` e ícone das marcas (público via `/images/*.svg` — Phase 56 já subiu)

**Amazon idem** — mesmo layout, ícone `/images/icons8-amazon.svg`, label "Amazon", texto adaptado.

### 4. NAV_TREE — renomear "Dashboard" → "Mercado Livre" + adicionar "ECF Consolidado"

**Decisão:** Modificar o grupo Mercado Livre no `NAV_TREE` de `AppLayout.jsx`:

- **Adicionar** no TOPO do grupo: novo item `ECF Consolidado` apontando para `ecf.dashboard`
- **Renomear** item existente `Dashboard` → `Mercado Livre` (label), routeName vira `mercadolivre.dashboard`
- Item `Dashboard` (canonical rota `dashboard`) SAI do menu (mas rota continua ativa pra compat)

Depois da mudança:

```javascript
{
    group: 'Mercado Livre',
    icon: Store,
    iconSrc: '/images/mercado-livre-87.svg',
    defaultOpen: true,
    children: [
        // ── Secao Performance ─────────────────────────
        // NOVO Phase 58 — agregado atraves de marketplaces
        { label: 'ECF Consolidado', routeName: 'ecf.dashboard', page: 'Dashboard/Admin', icon: PieChart, permission: 'core.dashboard' },
        // MUDADO Phase 58 — antes era { label: 'Dashboard', routeName: 'dashboard' }
        { label: 'Mercado Livre', routeName: 'mercadolivre.dashboard', page: 'Dashboard/Admin', icon: LayoutDashboard, permission: 'core.dashboard' },
        // ... resto continua igual
    ],
}
```

**Sobre o campo `page`:** `Dashboard/Admin` é o mesmo componente renderizado por index/ecf/mercadolivre (é o dashboard real). O highlight de "página ativa" no sidebar continua funcionando via `isActive('Dashboard/Admin')`. Se quisermos highlight diferenciado por rota, teríamos que criar componentes Inertia dedicados — decidimos **não** fazer isso (over-engineering).

**Racional:**
- "ECF Consolidado" no topo comunica visualmente que o agregado é a visão de mais alto nível
- "Mercado Livre" (dentro do grupo ML) reforça a semântica de filter por marketplace
- Zero item redundante — apenas 1 dashboard por semantic (ECF, ML, Shopee, Amazon)

### 5. Item existente `Dashboard` (rota `dashboard`) — SAI do menu, rota permanece

**Decisão:** Após Phase 58, nenhum item do sidebar aponta pra `route('dashboard')`. Rota permanece registrada em `routes/web.php` para:

- Deep links antigos (compartilhados por email, docs internos)
- Bookmarks pessoais dos usuários
- `route('dashboard')` chamadas dentro de JSX/PHP existente (várias telas fazem redirect pra `dashboard` em fluxos de logout/auth)

**Sem migração** dos `route('dashboard')` no codebase — permanecem funcionando com a rota atual (canonical fallback).

## Escopo — O que ENTRA

1. **routes/web.php**: adicionar 4 rotas GET nomeadas dentro do grupo auth+verified
2. **DashboardController**: adicionar 4 métodos (`ecf`, `mercadolivre`, `shopee`, `amazon`)
3. **DashboardController::adminDashboard() + userDashboard()**: adicionar filter opcional `?marketplace=<slug>` nas queries base de Company (uma linha `when($request->marketplace, ...)`)
4. **resources/js/Pages/Dashboard/ShopeeShell.jsx** (NOVO): mockup KPI cards + CTA "Ver Dashboard ECF"
5. **resources/js/Pages/Dashboard/AmazonShell.jsx** (NOVO): mesmo layout adaptado
6. **resources/js/Layouts/AppLayout.jsx**: NAV_TREE — adicionar `ECF Consolidado`, renomear/repointar `Dashboard`
7. **Testes**:
   - Feature test das 4 rotas novas (assertLessThan 500)
   - Feature test do filter `?marketplace=meli` no ecf.dashboard passar
   - Feature test de shell rendering (renderiza `Dashboard/ShopeeShell` sem erro)
8. **UAT em prod**: 4 rotas navegáveis, sidebar atualizado, shells renderizam

## Escopo — O que NÃO ENTRA

- **Refactor do pipeline do DashboardController** — código existente permanece; só adicionamos filter opcional
- **Substituição de `route('dashboard')` no codebase** — rota canonical mantida como fallback
- **Agregação real com `whereHas('marketplaces', ...)`** — filter usa coluna flat; migração pra pivot fica pra v14+ quando refatorarmos AdmanService/Sugadores
- **Página Dashboard/Admin.jsx dividida em N variantes** — mesma página serve ECF/ML por ora
- **Componentes específicos "Dashboard ECF" com breakdown por marketplace** — quando Shopee/Amazon integrar, aí sim
- **UI mostrando "ECF vs ML" comparativo** — se relevante, milestone futura
- **Testes E2E do dashboard completo** — smoke suficiente pra Phase 58

## Deferred ideas

- **Dashboard ECF com breakdown "resultado por marketplace"** — quando Shopee/Amazon integrar, adicionar section "Distribuição por marketplace" no ECF Consolidado
- **Migração dos consumidores para query via pivot** — quando refatorar AdmanService/Sugadores em milestone v14+
- **Componentes Dashboard/EcfConsolidadoPage.jsx dedicado** — se ECF divergir muito de ML na UI, criar componente próprio
- **Toggle rápido no header** entre ECF ↔ ML — se user pedir depois

## Code context

**Arquivos que serão modificados:**
- `routes/web.php` — 4 rotas novas
- `app/Http/Controllers/DashboardController.php` — 4 métodos + filter opcional
- `resources/js/Layouts/AppLayout.jsx` — NAV_TREE (grupo Mercado Livre)

**Arquivos que serão criados:**
- `resources/js/Pages/Dashboard/ShopeeShell.jsx`
- `resources/js/Pages/Dashboard/AmazonShell.jsx`
- `tests/Feature/Phase58/DashboardRoutesTest.php`
- `tests/Feature/Phase58/DashboardFilterTest.php`
- `tests/Feature/Phase58/DashboardShellsTest.php`

**Padrões a preservar:**
- Design tokens `ecf-*` (dark theme, ecf-yellow para CTA)
- `cn()` utility para composição de classes
- Wrapper `AppLayout` em todas páginas
- pt-BR nos labels e comentários
- Rotas dentro do grupo `auth+verified` (padrão do sistema)

## Risk summary

| Risco | Severidade | Mitigação |
|-------|------------|-----------|
| Filter `?marketplace=` quebra queries existentes | Baixa | Filter é OPCIONAL (`when()`) — sem query param, comportamento idêntico ao atual |
| Renomear "Dashboard" → "Mercado Livre" confunde users existentes | Média | Rota `dashboard` continua ativa; users que digitam URL direto continuam funcionando |
| ShopeeShell/AmazonShell parecem "prontos" mas não têm dados | Média | Cards mostram "—" + label "Aguardando integração" + CTA para ECF Consolidado |
| Deep link `/dashboard` sem item no menu — user perdido | Baixa | ECF Consolidado no topo do grupo ML é o novo canonical do menu; rota antiga permanece pra compat |

## Success criteria

1. `GET /dashboard/ecf` retorna 200 com dashboard funcional (mesmo output que /dashboard atual hoje)
2. `GET /dashboard/mercadolivre` retorna 200 com filter=meli aplicado (mesmo output hoje pra 100% empresas meli)
3. `GET /dashboard/shopee` renderiza `Dashboard/ShopeeShell` com header, KPI cards vazios "—", mensagem e CTA
4. `GET /dashboard/amazon` idem para Amazon
5. Sidebar mostra `ECF Consolidado` + `Mercado Livre` como sub-items do grupo Mercado Livre (não mais "Dashboard")
6. `GET /dashboard` (canonical antigo) continua funcionando para deep links
7. Zero regressão nas rotas existentes (Sugadores, /performance, /grants, etc)
8. Testes Phase58 verdes: 4 rotas novas + filter + shells
