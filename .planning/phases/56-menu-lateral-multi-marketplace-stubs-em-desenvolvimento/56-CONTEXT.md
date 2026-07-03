---
phase: 56
name: Menu lateral multi-marketplace + stubs "em desenvolvimento"
milestone: v13.0
captured: 2026-07-03
requirements: [NAV-01, NAV-02, NAV-03, NAV-04]
---

# Phase 56 — CONTEXT

## Domain

Reorganização visual da sidebar da app ECF Admin para refletir a estrutura multi-marketplace da ECF Consultoria (Mercado Livre, Shopee, Amazon, etc). Mudança visual imediata, zero risco de dados. Habilita phases 57/58/59 mas não depende delas.

## Canonical refs

- [resources/js/Layouts/AppLayout.jsx](../../../resources/js/Layouts/AppLayout.jsx) — 568 linhas; constante `NAV_TREE` (linhas 22-173) é o alvo principal
- [routes/web.php](../../../routes/web.php) — precisa adicionar rota `/em-desenvolvimento`
- [.planning/REQUIREMENTS.md](../../REQUIREMENTS.md) — seção "Milestone v13.0" traz NAV-01..04 canônicos
- [.planning/ROADMAP.md](../../ROADMAP.md) — seção `### Phase 56` (linha ~1315+)

## Locked decisions

### Estrutura do NAV_TREE — "achatar" dentro de um grupo Mercado Livre

**Decisão:** Grupo `Mercado Livre` (aberto por padrão) contém **todos** os items de Performance + Polos misturados no mesmo nível de children, com separador visual entre eles.

**Racional:**
- Sistema atual em `AppLayout.jsx` só suporta 1 nível de aninhamento (topo → grupo → filhos). Não há `subgroup` no NAV_TREE nem no `renderSidebar`.
- Implementar sub-grupos aninhados é escopo desproporcional (mudança estrutural em `renderSidebar` + testes de UX de scroll/expand) para uma phase visual.
- Achatar preserva a intenção do briefing (tudo de ML numa pasta só) sem tocar a estrutura de renderização.

**Como fazer no NAV_TREE (esboço):**

```javascript
{
    group: 'Mercado Livre',
    icon: Store,  // ou similar de marketplace
    defaultOpen: true,  // NOVO — hoje o auto-expand só acontece se rota atual bate
    children: [
        // ── Seção Performance ─────────────────────────
        { label: 'Dashboard',   routeName: 'dashboard',           page: 'Dashboard',    icon: LayoutDashboard, permission: 'core.dashboard' },
        { label: 'Desempenho',  routeName: 'performance.index',   page: 'Performance',  icon: Trophy,          permission: 'core.performance' },
        { label: 'Empresas',    routeName: 'companies.index',     page: 'Companies',    icon: Building2,       permission: 'core.empresas' },
        { label: 'Carteira',    routeName: 'portfolio.own',       page: 'Portfolio',    icon: Briefcase,       permission: 'core.carteira' },
        { label: 'Reuniões',    routeName: 'meetings.index',      page: 'Meetings',     icon: CalendarCheck,   permission: 'core.reunioes' },
        { label: 'Sugadores',   routeName: 'sugadores.index',     page: 'Sugadores',    icon: AlertTriangle,   permission: 'core.sugadores', showBadge: 'sugadores_pendentes' },
        { label: 'Metas',       routeName: 'goals.index',         page: 'Goals',        icon: Target,          permission: 'core.metas' },
        { label: 'PPA',         routeName: 'ppa.index',           page: 'Ppa',          icon: FileText,        permission: 'core.ppa' },
        // ── Separator visual ──────────────────────────
        { divider: 'Polos' },  // NOVO tipo de entry — renderSidebar renderiza label/hr
        // ── Seção Polos ───────────────────────────────
        { label: 'Onboarding',        routeName: 'mlb.implementacao.index', page: 'Mlb/Implementacao', icon: ListChecks, permission: 'mlb.implementacao' },
        { label: 'Empresas Polos',    routeName: 'mlb.polos-empresas',      page: 'Polos/EmpresasPorM', icon: Building2,  permission: 'mlb.projetos' },
        { label: 'Faturamento Polos', routeName: 'polos.index',             page: 'Polos/Index',        icon: PieChart,   excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
    ],
}
```

**Notas de implementação:**
- `defaultOpen: true` é um flag novo. Hoje o auto-expand acontece via `openGroups` inicial se a rota atual pertence ao grupo — precisa estender a lógica em `useState(() => {...})` para também respeitar `defaultOpen` quando o `sessionStorage` não tem preferência salva.
- `divider: 'Polos'` é entry novo (sem `label`/`routeName`/`group`). `renderSidebar` precisa aprender a renderizar: `<div className="pt-3 pb-1 px-3 text-[10px] uppercase tracking-wide text-white/40">Polos</div>` seguido de `<hr className="border-white/[0.06] mb-1" />` (ou similar).
- Permissões continuam idênticas ao NAV_TREE atual (nenhuma mudança de gating). O filtro `itemVisivel` (linhas 223-226) precisa ignorar entries do tipo `divider`.

### Dashboard item aponta pra rota `dashboard` atual

**Decisão:** O item `Dashboard` dentro do grupo Mercado Livre aponta para a rota nomeada `dashboard` (existente, sem mudança). Phase 58 vai:
- Criar `/dashboard/ecf` no topo (agregado)
- Aliasear `/dashboard` → `/dashboard/mercadolivre` (ou canonicalizar)

**Racional:** Lean, zero risco de link quebrado. Preservar a rota nomeada atual + o `page: 'Dashboard'` que casa com o componente Inertia.

### Grupo Publicações renomeado singular; sub-items MLB.* intactos

**Decisão:** Renomear label `Publicações` → `Publicação` (singular, alinhado com o briefing do usuário). Sub-items continuam apontando pra rotas `mlb.*`. Não move nada pra dentro nem pra fora do ML — grupo continua no seu lugar atual da NAV_TREE.

**Racional:**
- Contrato do briefing: Publicação é setor transversal → fica FORA da pasta ML. Atualmente já está.
- Sub-items MLB.* na prática são ML-only hoje. Phase 59 vai auditar e generalizar (CROSS-01/02).
- Fazer mais que rename agora seria antecipar Phase 59 e correr risco de rework.

### Stubs Shopee/Amazon com badge "Em breve"

**Decisão:** Items `Shopee` e `Amazon` aparecem na sidebar como **grupos vazios com badge cinza discreto "Em breve"** à direita do nome, ao lado do chevron. Clicar (ou expandir e clicar em qualquer sub-item futuro) leva à rota `/em-desenvolvimento`.

**Formato visual do badge:**
- Cor: cinza claro (`bg-white/[0.08] text-white/50`), similar aos badges existentes mas neutro
- Texto: `Em breve`
- Posição: entre o label e o chevron do grupo, `ml-2`
- Só visível quando sidebar não está `collapsed` e no mobile

**Estrutura NAV_TREE dos stubs:**

```javascript
{
    label: 'Shopee',
    routeName: 'em-desenvolvimento',
    routeParams: { marketplace: 'shopee' },
    page: 'EmDesenvolvimento',
    icon: ShoppingBag,  // ou similar
    badgeText: 'Em breve',  // NOVO campo — diferente de showBadge (contador dinâmico)
},
{
    label: 'Amazon',
    routeName: 'em-desenvolvimento',
    routeParams: { marketplace: 'amazon' },
    page: 'EmDesenvolvimento',
    icon: Package2,  // ou similar
    badgeText: 'Em breve',
},
```

- Item de TOPO (não grupo) — simplifica renderização. Se no futuro Shopee/Amazon virarem grupos com sub-items, converter então.
- `page: 'EmDesenvolvimento'` casa com o novo componente Inertia `resources/js/Pages/EmDesenvolvimento.jsx`.

### Rota `/em-desenvolvimento` + página placeholder

**Decisão:** Nova rota GET `/em-desenvolvimento` (com query param opcional `?marketplace=shopee|amazon`) renderiza componente `EmDesenvolvimento.jsx` com placeholder consistente com design system ECF (dark theme, tokens `ecf-*`, `DevCard` reusable).

**Componente stub — conteúdo mínimo:**
- Título: `Marketplace em desenvolvimento`
- Sub-título dinâmico: `Módulo Shopee em breve` / `Módulo Amazon em breve` (via query param)
- Ícone grande (marketplace) + texto explicativo pt-BR: "Este marketplace ainda não está integrado. Fique atento às próximas atualizações do sistema."
- Botão "Voltar ao Dashboard" que navega pra `route('dashboard')`

**Rota (em `routes/web.php`, grupo autenticado):**
```php
Route::get('/em-desenvolvimento', function () {
    return Inertia::render('EmDesenvolvimento', [
        'marketplace' => request('marketplace'),
    ]);
})->name('em-desenvolvimento');
```

Sem permissão específica (visível a todos autenticados) — segue o padrão do "Manual do Sistema".

## Escopo desta phase — o que ENTRA

1. Refactor de `NAV_TREE` em `AppLayout.jsx`:
   - Criar grupo `Mercado Livre` com sub-items achatados (Performance + separator + Polos)
   - Adicionar suporte a entry tipo `divider` no `renderSidebar`
   - Adicionar flag `defaultOpen: true` respeitado na inicialização de `openGroups`
   - Adicionar itens topo `Shopee` e `Amazon` com `badgeText: 'Em breve'`
   - Suportar renderização de `badgeText` (estático) diferente de `showBadge` (contador dinâmico)
   - Renomear label `Publicações` → `Publicação`
2. Criar rota `em-desenvolvimento` em `routes/web.php` (grupo autenticado)
3. Criar componente `resources/js/Pages/EmDesenvolvimento.jsx`
4. Remover items de topo que agora vivem dentro de ML (Dashboard, Carteira, Empresas, Reuniões, Metas, PPA, Sugadores, Desempenho) — verificar se algum é usado em outro lugar
5. Testar: sidebar renderiza, ML abre por padrão, stubs clicáveis funcionam, permissões continuam gatando corretamente
6. Update tests que asseguram presença/estrutura da sidebar (se existirem)

## Escopo desta phase — o que NÃO ENTRA

- **Sub-grupos aninhados** — não implementar `subgroup` no NAV_TREE. Achatar dentro do grupo ML é a decisão.
- **Nova rota `/dashboard/ecf`** — Phase 58.
- **Nova rota `/dashboard/mercadolivre`** — Phase 58.
- **Renomear rotas `mlb.*`** — Phase 59 se fizer sentido.
- **Auditar acoplamento ML de áreas transversais** (Usuários, Setores, Comercial, etc) — Phase 59.
- **Modelo de dados multi-marketplace** — Phase 57.

## Áreas transversais — permanecem como estão

Fora do escopo desta phase mas relevante para saber que continuam nos seus lugares atuais no NAV_TREE:
- `Dashboard` — MOVE pra dentro de ML/Performance (era top-level)
- `Carteira`, `Empresas`, `Reuniões`, `Metas`, `PPA`, `Sugadores`, `Desempenho` — MOVEM pra dentro de ML/Performance
- `Usuários`, `Setores`, `Enviar notificação`, `NPS`, `Meu Setor`, `Dev`, `Comercial`, `Administrativo`, `Manual do Sistema` — permanecem onde estão (top-level ou grupos)
- `Dados Estratégicos` (Painel Executivo, Concentração, Alertas, Grants) — permanecem onde estão (podem ser reavaliadas em Phase 59)

## Code context

**Arquivos que serão modificados:**
- `resources/js/Layouts/AppLayout.jsx` — refactor de `NAV_TREE`, extensão de `renderSidebar` para divider + badgeText + defaultOpen
- `routes/web.php` — nova rota `em-desenvolvimento`

**Arquivos que serão criados:**
- `resources/js/Pages/EmDesenvolvimento.jsx` — placeholder page
- (opcional) `tests/Feature/EmDesenvolvimentoRouteTest.php` — smoke da rota

**Padrões a preservar:**
- Design tokens `ecf-*` (dark theme, ecf-yellow para active)
- `cn()` utility (clsx + tailwind-merge) para composição condicional
- `DevCard` para páginas admin/dev (se apropriado no placeholder)
- Convenção de comentários pt-BR
- Convenção de nomenclatura: `label` em Português, `routeName` em kebab-case existente, `page` em PascalCase
- Convenção de `permission` (core.*, mlb.*, admin.*, sistema.*) — não introduzir novas

## Deferred ideas (não escopo desta phase)

- **Sidebar reordering das áreas transversais** — Publicação vem antes ou depois de Comercial? Ordem de grupos secundários pode ser ajustada em Phase 59 quando tiver decisão de generalização.
- **Ícones distintos para Shopee/Amazon** — hoje pode ser genérico; Phase futura de branding pode buscar ícones oficiais dos marketplaces.
- **Search/filter da sidebar** — não pedido; se app crescer, considerar.
- **Multi-marketplace toggle no header** — não pedido para v13.0; poderia ser feature complementar futura.

## Success criteria

Ao final desta phase o usuário deve poder:
1. Abrir qualquer página autenticada e ver a sidebar com estrutura nova (ML aberto, Publicação transversal, Shopee/Amazon com badge)
2. Clicar em `Shopee` → cair na rota `/em-desenvolvimento?marketplace=shopee` com placeholder consistente com design system
3. Clicar em `Amazon` → mesmo comportamento com sub-título Amazon
4. Ter zero item quebrado — todas as rotas `mlb.*`, `core.*`, `admin.*` continuam funcionando
5. Ver a permissão gating funcionando: usuário sem `core.dashboard` NÃO vê o item Dashboard dentro do grupo ML; se todo o ML for gated fora dele, grupo some inteiro (comportamento atual preservado)
