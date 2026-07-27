import { Link, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import {
    LayoutDashboard, Building2, Users, CalendarCheck,
    Star, Target, FileText, ChevronLeft, ChevronRight, ChevronDown,
    LogOut, User, Menu, X, Trophy, Briefcase, ShieldCheck,
    BarChart2, LineChart, PlusCircle, Clock, ClipboardCheck, LayoutList, Store, ShoppingCart, BookOpen, FolderKanban, SlidersHorizontal,
    AlertTriangle, ListChecks, FileBarChart, Banknote, Package2, ScrollText,
    Code2, Crown, Shield, Send, Link2, TrendingUp, Settings, Inbox, PieChart, EyeOff
} from 'lucide-react';
import { cn } from '@/lib/utils';
import NotificationBell from '@/Components/NotificationBell';
import ThemeToggle from '@/Components/ThemeToggle';

/**
 * Árvore de navegação com suporte a grupos colapsáveis.
 * Cada entrada é UM item de topo (link direto) OU um grupo { group, icon, children }.
 * Regras de gating (permission/excludeRoles) são idênticas ao array plano anterior.
 *
 * Grupo especial "Empresas": o header navega para companies.index ao clicar no label,
 * mas o chevron expande/recolhe os filhos independentemente.
 */
const NAV_TREE = [
    // ══════════════════════════════════════════════════════════════════════════
    // Phase 56 v13.0 — Reorganização Multi-Marketplace
    //
    // Grupo Mercado Livre (aberto por default via `defaultOpen`) consolida
    // Performance + Polos num unico agrupamento achatado. Items topo Shopee/
    // Amazon com badge estatico apontam pro stub /em-desenvolvimento. Grupo
    // Publicacao (renomeado singular) fica FORA da pasta ML por ser setor
    // transversal (atende todos marketplaces). Grupo Polos velho foi absorvido
    // pelo grupo ML — nao existe mais como grupo separado.
    //
    // Sub-grupos aninhados nao foram implementados (decisao locked em
    // 56-CONTEXT.md) — divider tipo `{ divider: 'Polos' }` faz a separacao
    // visual dentro dos children do grupo.
    // ══════════════════════════════════════════════════════════════════════════
    // ── Dashboard ECF (topo — agregado da empresa toda através de marketplaces) ─
    // Ajuste pós-UAT Phase 58: ECF Dashboard sai do grupo Mercado Livre e vira
    // item de topo (dashboard da empresa englobando TODOS os marketplaces —
    // ML/Shopee/Amazon). Renomeado de "ECF Consolidado" para "ECF Dashboard".
    // Ajuste pós-UAT 2: page: 'Dashboard/EcfShell' (não mais 'Dashboard/Admin')
    // para que active-state do sidebar diferencie de /dashboard/mercadolivre.
    // Ajuste pós-UAT 3 (2026-07-07): admin-only — Analista/Estrategista/Líder
    // não devem ver esse item; carteira própria é a fonte deles.
    { label: 'ECF Dashboard', routeName: 'ecf.dashboard', page: 'Dashboard/EcfShell', icon: PieChart, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },

    // ── Grupo: Gestão ECF (transversal — Phase 93 v17.0) ────────────────────
    // Carteira, Desempenho e Metas atendem multi-marketplace (ML + Shopee) e
    // multi-serviço — não são específicos do Mercado Livre, então saem do
    // grupo ML e viram um grupo próprio. Fica logo abaixo de "ECF Dashboard"
    // e acima de "Mercado Livre" (D-93-01). Aberto por default (D-93-03) por
    // ser onde analista/estrategista vivem. Ver plano canônico
    // `plano-carteira-desempenho-multi-servico.md` §5 "Ajustar menu".
    // Empresas PERMANECE no grupo Mercado Livre (decisão do plano canônico —
    // não movida aqui, ver comentário no grupo ML abaixo).
    {
        group: 'Gestão ECF',
        icon: FolderKanban,
        defaultOpen: true,
        children: [
            { label: 'Carteira',   routeName: 'portfolio.own',     page: 'Portfolio', icon: Briefcase, permission: 'core.carteira' },
            { label: 'Desempenho', routeName: 'performance.index', page: ['Performance/Index', 'Performance/Show', 'Desempenho/Configuracao'], icon: Trophy, permission: 'core.performance' },
            { label: 'Metas',      routeName: 'goals.index',       page: 'Goals',     icon: Target,    permission: 'core.metas' },
            // Auditoria de bônus (item 3/4 · 2026-07-21) — admin-only. Gate por
            // excludeRoles (mesmo padrão da Configuração NPS): esconde de todos
            // os papéis não-admin. A rota também é protegida por role:admin.
            { label: 'Auditoria de bônus', routeName: 'desempenho.auditoria-bonus', page: 'Desempenho/Auditoria', icon: Shield, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            // Fase 107 — Relatório de bonificação (admin-only, mesmo gate da Auditoria).
            { label: 'Relatório de bonificação', routeName: 'desempenho.relatorio-bonificacao', page: 'Desempenho/RelatorioBonificacao', icon: FileBarChart, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
        ],
    },

    {
        group: 'Mercado Livre',
        icon: Store,
        iconSrc: '/images/mercado-livre-87.svg',
        defaultOpen: true,
        children: [
            // ── Secao Performance ─────────────────────────
            // Ajuste pós-UAT Phase 58: dentro do grupo ML, item chama-se "Dashboard"
            // (contexto do grupo já implica Mercado Livre). Rota legacy `dashboard`
            // segue registrada para deep links (CONTEXT §5).
            // Ajuste UAT 2026-07-07: page virou array porque admin renderiza
            // Dashboard/Admin e analista/estrategista renderiza Performance/
            // Dashboard (branching no controller). Ambos devem highlightar
            // este item quando na rota /dashboard/mercadolivre.
            { label: 'Dashboard', routeName: 'mercadolivre.dashboard', page: ['Dashboard/Admin', 'Performance/Dashboard'], icon: LayoutDashboard, permission: 'core.dashboard' },
            // Phase 93 v17.0: Desempenho, Carteira e Metas foram movidos para o
            // grupo transversal "Gestão ECF" (acima) — atendem ML + Shopee, não
            // são exclusivos deste grupo. Empresas permanece aqui (decisão do
            // plano canônico — pergunta em aberto D-93, ver 93-01-PLAN.md).
            { label: 'Empresas',    routeName: 'companies.index',     page: 'Companies',    icon: Building2,       permission: 'core.empresas' },
            { label: 'Sugadores',   routeName: 'sugadores.index',     page: 'Sugadores',    icon: AlertTriangle,   permission: 'core.sugadores', showBadge: 'sugadores_pendentes' },
            { label: 'PPA',         routeName: 'ppa.index',           page: 'Ppa',          icon: FileText,        permission: 'core.ppa' },
            // ── Separator visual: Performance | Dados Estrategicos ─────
            // Feedback UAT 2026-07-03: dados vem do ECF Drive (fonte ML-only na
            // pratica), entao Dados Estrategicos e ML-especifico. Movido pra
            // dentro do grupo ML no lugar de ficar como grupo separado.
            { divider: 'Dados Estratégicos' },
            // ── Secao Dados Estrategicos ──────────────────
            // Phase 24 — Painel Executivo Carteira ECF (admin only)
            { label: 'Painel Executivo',        routeName: 'painel-executivo.index', page: 'PainelExecutivo',     icon: LineChart,      excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            // Phase 27 — Concentracao e Forecast 90d (admin only)
            { label: 'Concentração e Previsão', routeName: 'concentracao.index',    page: 'Concentracao',        icon: TrendingUp,     excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            // Phase 23 — Alertas Estrategicos (signals ECF Drive)
            { label: 'Alertas Estratégicos',    routeName: 'alertas.index',          page: 'AlertasEstrategicos', icon: AlertTriangle,  showBadge: 'alertas_criticos_count', excludeRoles: ['publicador', 'analista', 'gestor', 'lider'] },
            { label: 'Grants',                  routeName: 'grants.index',           page: 'Grants',              icon: ShieldCheck,    permission: 'core.grants' },
            // ── Separator visual: Dados Estrategicos | Polos ─────
            { divider: 'Polos' },
            // ── Secao Polos ───────────────────────────────
            // Painel Polos: aba unificada (operacional + financeiro admin-only) — quick 260630-edm.
            // Gate por permission (NÃO excludeRoles: bug conhecido esconde itens de admin com cargo de publicação).
            { label: 'Painel Polos',      routeName: 'mlb.polos-painel',        page: 'Polos/Painel',      icon: LayoutDashboard, permission: 'mlb.projetos' },
            { label: 'Onboarding',        routeName: 'mlb.implementacao.index', page: 'Mlb/Implementacao', icon: ListChecks, permission: 'mlb.implementacao' },
            { label: 'Empresas Polos',    routeName: 'mlb.polos-empresas',      page: 'Polos/EmpresasPorM', icon: Building2,  permission: 'mlb.projetos' },
            // Gate por permission dedicada mlb.faturamento_polos (concedida ao setor Polos).
            // Antes era excludeRoles admin-only; migrado p/ liberar o setor Polos ver o financeiro.
            { label: 'Faturamento Polos', routeName: 'polos.index',             page: 'Polos/Index',        icon: PieChart,   permission: 'mlb.faturamento_polos' },
        ],
    },

    // ── Stubs marketplaces em desenvolvimento (Phase 58 v13.0) ──────────────
    // Ajuste pós-UAT Phase 59: apontam pras rotas dedicadas /dashboard/shopee
    // e /dashboard/amazon (Phase 58 DASH-03) que renderizam ShopeeShell.jsx
    // e AmazonShell.jsx. Antes ambos apontavam pra em-desenvolvimento com
    // page: 'EmDesenvolvimento' idêntico — o que fazia isActive() casar os
    // dois simultaneamente. Agora cada um tem page própria.
    // Badge estatico "Em breve" via `badgeText` (distinto de `showBadge`).
    // Phase 75 Plan 75-05 (DEC-3) — o stub de topo "Shopee — Em breve" virou um
    // grupo real (espelhando "Mercado Livre"). Filho "Empresas" gate por
    // permission:shopee.empresas (admin + Setor Shopee); o Dashboard segue como
    // stub "Em breve" (rota shopee.dashboard já existe). itemVisivel() esconde
    // "Empresas" de quem não tem a key e o grupo some se nenhum filho for visível.
    {
        group: 'Shopee',
        icon: ShoppingCart,
        iconSrc: '/images/shopee-icon.svg',
        children: [
            { label: 'Empresas',  routeName: 'shopee.empresas.index', page: 'Shopee/Empresas',      icon: Building2,       permission: 'shopee.empresas' },
            { label: 'Dashboard', routeName: 'shopee.dashboard',      page: 'Dashboard/ShopeeShell', icon: LayoutDashboard, badgeText: 'Em breve' },
        ],
    },
    {
        label: 'Amazon',
        routeName: 'amazon.dashboard',
        page: 'Dashboard/AmazonShell',
        icon: Package2,
        iconSrc: '/images/icons8-amazon.svg',
        badgeText: 'Em breve',
    },

    // ── Itens de topo (setores transversais) ────────────────────────────────
    // Feedback UAT 2026-07-03: grupo "Dados Estrategicos" que ficava aqui foi
    // movido pra dentro do grupo Mercado Livre (secao ML-especifica — dados
    // vem do ECF Drive que hoje serve so ML).
    // Phase 37 Plan 37-07 (REQ-37-09) — item "Serviços" removido do nivel raiz;
    // movido para dentro do grupo Comercial abaixo.
    // Phase 56 v13.0: Dashboard, Carteira, Empresas, Reunioes, Metas, PPA,
    // Sugadores, Desempenho movidos pra dentro do grupo Mercado Livre.
    { label: 'Usuários',            routeName: 'users.index',             page: 'Users',            icon: Users,        permission: 'core.usuarios' },
    { label: 'Setores',             routeName: 'admin.setores.index',     page: 'Admin/Setores',    icon: Shield,       permission: 'sistema.setores' },
    // Feedback UAT 2026-07-03: Reunioes eh transversal (nao especifica de ML),
    // fica como item topo acima de "Enviar notificacao".
    { label: 'Reuniões',            routeName: 'meetings.index',          page: 'Meetings',         icon: CalendarCheck, permission: 'core.reunioes' },
    { label: 'Enviar notificação',  routeName: 'notificacoes.nova',       page: 'Notificacoes/Nova', icon: Send,        permission: 'notificacoes.criar' },
    // ── Grupo: NPS ───────────────────────────────────────────────────────────
    // Phase 32 — Plan 02: NPS vira grupo com sub-item "Configuração NPS" (admin only).
    // Phase 32 — Plan 04: sub-item "Emails enviados" (admin only) adicionado.
    {
        group: 'NPS',
        icon: Star,
        permission: 'core.nps',
        children: [
            { label: 'Pesquisas',        routeName: 'nps.index',                  page: 'Nps/Index',           icon: Star,     permission: 'core.nps' },
            { label: 'Configuração NPS', routeName: 'nps.configuracao.index',     page: 'Nps/Configuracao',    icon: Settings, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            { label: 'Envio automático', routeName: 'nps.envio-automatico.index', page: 'Nps/EnvioAutomatico', icon: Send,     excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            { label: 'Emails enviados',  routeName: 'nps.emails-enviados.index',  page: 'Nps/EmailsEnviados',  icon: Inbox,    excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
        ],
    },

    // ── Item de topo: Meu Setor (líder; admin excluído por ter visão global) ─
    { label: 'Meu Setor', routeName: 'lideranca.index', page: 'Lideranca', icon: Crown, permission: 'lideranca.dashboard_setor', excludeRoles: ['admin'] },

    // ── Grupo: Dev ──────────────────────────────────────────────────────────
    {
        group: 'Dev',
        icon: Code2,
        children: [
            { label: 'Log',            routeName: 'activity-log.index',  page: 'ActivityLog',        icon: ScrollText, permission: 'sistema.activity_log' },
            { label: 'Desenvolvimento', routeName: 'dev.desenvolvimento', page: 'Dev/Desenvolvimento', icon: Code2,     permission: 'sistema.desenvolvimento' },
            // Phase 42 D-02 / REQ-42-07: item de UI de onboarding (Plan 41-05) removido daqui.
            // Rota /dev/sugadores-ml-onboarding permanece acessivel via URL direta (role:admin) como ferramenta tecnica.
            { label: 'ML OAuth',       routeName: 'ml.oauth.index',      page: 'MlOAuth/Index',       icon: Link2,     permission: 'sistema.ml_oauth' },
            { label: 'Shopee OAuth',   routeName: 'shopee.oauth.index',  page: 'ShopeeOAuth/Index',   icon: Store,     permission: 'sistema.shopee_oauth' },
            // MVP Cargo Dev — painel de controle do Dev: cargo Dev + visibilidade de módulos (só o Dev vê).
            { label: 'Controle Dev', routeName: 'dev.modulos.index', page: 'Dev/Modulos', icon: EyeOff, devOnly: true },
        ],
    },

    // ── Grupo: Comercial ────────────────────────────────────────────────────
    // Phase 37 Plan 37-07 (REQ-37-09) — sidebar consolidada do setor Comercial.
    // Serviços migrou pra cá do nível raiz; HubSpot Line Items é UI nova admin
    // pra gerenciar mappings line_item → Servico (Plan 37-07 Task 1-2). Grupos
    // aponta para a aba Grupos da listagem (entregue no Plan 37-05).
    {
        group: 'Comercial',
        icon: Briefcase,
        children: [
            // Phase 37 Plan 37-05 — listagem unificada do Comercial (todos os setores)
            // com filtros snake_case empilháveis + 5 cards de pendência (apenas origem
            // HubSpot, REQ-37-10) + aba de Grupos integrada. Adicionado como PRIMEIRO
            // sub-item por ser a porta de entrada operacional do Comercial.
            { label: 'Empresas (todos os setores)', routeName: 'comercial.empresas.listagem', page: 'Comercial/EmpresasListagem', icon: Building2, permission: 'comercial.cadastrar_empresa' },
            // 'Cadastrar empresa' removido do dropdown: o cadastro já é acessível pelo
            // botão dentro da aba 'Empresas (todos os setores)'. A rota
            // 'comercial.empresas.novo' segue ativa (usada pelo botão da listagem).
            // Phase 37 Plan 37-07 — Grupos aponta para a mesma listagem unificada
            // com `?tab=grupos` (o helper de menu acima usa routeParams pra alimentar
            // Ziggy; segments fora do path viram query string automaticamente).
            { label: 'Grupos', routeName: 'comercial.empresas.listagem', routeParams: { tab: 'grupos' }, page: 'Comercial/EmpresasListagem', icon: ListChecks, permission: 'comercial.cadastrar_empresa' },
            // Phase 37 Plan 37-07 (REQ-37-09) — Serviços movido do nivel raiz.
            // Permission 'sistema.servicos' preservada para manter o gating de
            // catalogo (admin sempre; outros usuarios via setor_permissoes).
            { label: 'Serviços', routeName: 'servicos.index', page: 'Servicos', icon: Briefcase, permission: 'sistema.servicos' },
            // Phase 37 Plan 37-07 (REQ-37-02) — UI admin do mapping HubSpot.
            // excludeRoles mantém o sub-item invisível para todos os papeis
            // não-admin (mesmo pattern do "Configuração NPS" do grupo NPS).
            // Sem permission_key dedicado: defesa em profundidade no controller
            // via grupo de rotas role:admin.
            { label: 'HubSpot Line Items', routeName: 'sistema.hubspot-line-items.index', page: 'Sistema/HubspotLineItems', icon: Link2, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            // Item "Projetos" movido do grupo Polos para o Comercial (mesma rota/page/permission)
            { label: 'Projetos', routeName: 'mlb.projetos', page: 'Mlb/Projetos', icon: FolderKanban, permission: 'mlb.projetos' },
        ],
    },

    // ── Grupo: Publicação (setor transversal — atende todos marketplaces) ────
    // Phase 56 v13.0: renomeado de "Publicações" para "Publicação" (singular).
    // Sub-items ainda apontam pra rotas mlb.* — Phase 59 audita/generaliza.
    {
        group: 'Publicação',
        icon: BarChart2,
        children: [
            { label: 'Pub · Dashboard', routeName: 'mlb.dashboard',    page: 'Mlb/Dashboard',    icon: BarChart2,      permission: 'mlb.dashboard' },
            { label: 'Desempenho',      routeName: 'publicacao.desempenho.index', page: 'Performance', icon: Trophy, permission: 'mlb.meu_painel' },
            { label: 'Treinamentos',    routeName: 'mlb.treinamentos', page: 'Mlb/Treinamentos', icon: BookOpen,       permission: 'mlb.treinamento' },
            // Admin/Gestor/Líder usam esta tela em modo supervisão (seletor de publicador via ?pub=ID); publicador/analista veem o próprio painel.
            { label: 'Meu Painel',      routeName: 'mlb.meu-painel',   page: 'Mlb/MeuPainel',    icon: LayoutList,     permission: 'mlb.meu_painel' },
            { label: 'Publicação',      routeName: 'mlb.publicacoes',  page: 'Mlb/Publicacoes',  icon: PlusCircle,     permission: 'mlb.publicacoes' },
            { label: 'Vendas',          routeName: 'mlb.vendas',       page: 'Mlb/Vendas',       icon: ShoppingCart,   permission: 'mlb.vendas' },
            { label: 'Histórico',       routeName: 'mlb.historico',    page: 'Mlb/Historico',    icon: Clock,          permission: 'mlb.historico' },
            { label: 'Revisão',         routeName: 'mlb.revisao',      page: 'Mlb/Revisao',      icon: ClipboardCheck, permission: 'mlb.revisao' },
            { label: 'Empresas',        routeName: 'mlb.empresas',     page: 'Mlb/Empresas',     icon: Store,          permission: 'mlb.empresas' },
            { label: 'Metas',           routeName: 'mlb.metas.index',  page: 'Mlb/Metas',        icon: SlidersHorizontal, permission: 'mlb.metas' },
        ],
    },

    // ── Grupo: Administrativo ────────────────────────────────────────────────
    // Phase 56 v13.0: grupo Polos (que ficava aqui) foi absorvido pelo grupo
    // Mercado Livre no topo — usa divider visual `{ divider: 'Polos' }`.
    {
        group: 'Administrativo',
        icon: Shield,
        children: [
            { label: 'Empresas',   routeName: 'admin.empresas',   page: 'Admin/Empresas',   icon: Building2,    permission: 'admin.empresas' },
            { label: 'Relatório',  routeName: 'admin.relatorio',  page: 'Admin/Relatorio',  icon: FileBarChart, permission: 'admin.relatorio' },
            { label: 'Fechamento', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote,     permission: 'admin.financeiro' },
            { label: 'Inventário', routeName: 'admin.inventario', page: 'Admin/Inventario', icon: Package2,     permission: 'admin.inventario' },
        ],
    },

    // ── Item de topo: rodapé (sem permission = visível a todos autenticados) ─
    { label: 'Manual do Sistema', routeName: 'manual.index', page: 'Manual', icon: BookOpen },
];

const roleLabel = { admin: 'Admin', consultor: 'Consultor', mentor: 'Mentor' };

// Chaves de sessionStorage para preservar o estado da sidebar entre navegações.
// O AppLayout NÃO é um layout persistente do Inertia (cada página o renderiza no
// próprio JSX), então toda visita remonta o componente — o que zerava o scroll do
// nav e recolhia os grupos abertos, fazendo a barra "pular pro topo".
const SIDEBAR_GROUPS_KEY = 'ecf-sidebar-open-groups';
const SIDEBAR_SCROLL_KEY = 'ecf-sidebar-scroll';

export default function AppLayout({ children, title }) {
    const { auth, flash, asset_url, sugadores_pendentes, alertas_criticos_count } = usePage().props;
    const badgeCounters = {
        sugadores_pendentes:    sugadores_pendentes    ?? 0,
        alertas_criticos_count: alertas_criticos_count ?? 0,  // null vira 0 → badge some
    };
    const { component: pageComponent } = usePage();
    const logoSrc = `${asset_url}/images/logo.png`;
    const user = auth?.user;

    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [toast, setToast] = useState(null);

    const mainRole    = user?.role;
    const permissions = auth?.permissions ?? [];

    // MVP Cargo Dev — visibilidade de módulos no menu.
    // `isAdminDev` (users.is_dev, Fase 97): o cargo Dev vê TUDO, ignorando o gate.
    // `modulosOcultos`: route_prefixes marcados como ocultos na tela /dev/modulos.
    const isAdminDev     = auth?.is_admin_dev ?? false;
    const modulosOcultos = auth?.modulos_ocultos ?? [];

    // Conjunto de "papeis efetivos" do user: a role legacy (admin/consultor/mentor)
    // MAIS os cargos de PUBLICAÇÃO, mapeados pro short-form usado na NAV_TREE.
    // Sem isso, excludeRoles com cargos de publicação ('publicador','gestor',
    // 'lider','analista') nunca pegava — porque user.role é sempre consultor/
    // mentor/admin —, e telas como "Alertas Estratégicos" (grupo Dados
    // Estratégicos) vazavam pro publicador (que é role 'consultor').
    const CARGO_SHORT = {
        'gestor-de-publicacao': 'gestor',
        'lider-de-publicacao':  'lider',
        'publicador':           'publicador',
        'analista':             'analista',
    };
    const pubCargos = (auth?.setores ?? [])
        .map(s => CARGO_SHORT[s.cargo_slug] ?? s.cargo_slug)
        .filter(Boolean);
    const effectiveRoles = new Set([mainRole, ...pubCargos].filter(Boolean));

    /**
     * MVP Cargo Dev — uma rota está oculta quando casa com algum route_prefix
     * marcado como oculto (`auth.modulos_ocultos`). Entrada terminada em '.'
     * (ex.: 'admin.') é tratada como PREFIXO (esconde todo o namespace); as
     * demais exigem match exato do routeName.
     */
    const rotaOculta = (routeName) =>
        !!routeName && modulosOcultos.some(h => h.endsWith('.') ? routeName.startsWith(h) : routeName === h);

    /**
     * Regra de visibilidade de um item de menu.
     * Retorna false se QUALQUER papel efetivo do user está em excludeRoles, ou se
     * a permission requerida não consta na lista de permissions do usuário.
     */
    const itemVisivel = (item) => {
        // Dividers (labels de separacao dentro de grupos) sao sempre visiveis.
        // Introduzidos na Phase 56 v13.0 pra separar "Performance" de "Polos"
        // dentro do grupo Mercado Livre sem precisar de sub-grupos aninhados.
        if (item.divider) return true;
        // Itens exclusivos do Dev (ex.: a própria tela de controle de visibilidade).
        if (item.devOnly && !isAdminDev) return false;
        // Gate de visibilidade por módulo — Dev vê tudo; demais não veem os ocultos.
        if (!isAdminDev && rotaOculta(item.routeName)) return false;
        if (item.excludeRoles?.some(r => effectiveRoles.has(r))) return false;
        return item.permission ? permissions.includes(item.permission) : true;
    };

    /**
     * Filtra a NAV_TREE respeitando as regras de permissão.
     * Itens de topo: mesma regra de gating de sempre.
     * Grupos: filtra os filhos pela mesma regra; descarta o grupo inteiro se nenhum filho for visível.
     * Grupo com permission própria (ex: "Empresas"): descarta se itemVisivel falhar no grupo em si.
     */
    const filteredTree = useMemo(() => {
        // Remove dividers órfãos: um divider é órfão quando NÃO tem nenhum
        // item real (não-divider) entre ele e o próximo divider (ou o fim
        // da lista). Ajuste UAT 2026-07-07: divider "POLOS" ficava visível
        // pra Estrategista mesmo com todos os filhos abaixo dele gated.
        const removerDividersOrfaos = (children) => {
            const out = [];
            for (let i = 0; i < children.length; i++) {
                const item = children[i];
                if (!item.divider) { out.push(item); continue; }
                // Procura próximo item real entre este divider e o próximo divider
                let temRealAbaixo = false;
                for (let j = i + 1; j < children.length; j++) {
                    if (children[j].divider) break;
                    temRealAbaixo = true;
                    break;
                }
                if (temRealAbaixo) out.push(item);
            }
            return out;
        };

        return NAV_TREE.reduce((acc, entry) => {
            if (entry.group) {
                // Grupos com permission própria (ex: grupo "Empresas") são verificados também
                if (entry.permission && !itemVisivel(entry)) return acc;
                const filhos = removerDividersOrfaos(entry.children.filter(itemVisivel));
                // Phase 56 v13.0: se sobrou SO divider (sem items reais), esconder o grupo
                // — evita "grupo fantasma" com um label sem filhos abaixo.
                const filhosReais = filhos.filter(c => !c.divider);
                if (filhosReais.length > 0) acc.push({ ...entry, children: filhos });
            } else {
                if (itemVisivel(entry)) acc.push(entry);
            }
            return acc;
        }, []);
    }, [mainRole, permissions, pubCargos.join(','), isAdminDev, modulosOcultos.join(',')]); // eslint-disable-line react-hooks/exhaustive-deps

    /**
     * Estado de expansão dos grupos. Restaura os grupos abertos da sessão (para
     * sobreviver ao remount da navegação) e garante que o grupo da rota atual
     * comece aberto (auto-expand).
     */
    const [openGroups, setOpenGroups] = useState(() => {
        let saved = {};
        try {
            const raw = sessionStorage.getItem(SIDEBAR_GROUPS_KEY);
            if (raw) saved = JSON.parse(raw) || {};
        } catch { /* sessionStorage indisponível — ignora */ }
        NAV_TREE.forEach(entry => {
            if (entry.group) {
                // Auto-abrir grupo cuja rota atual esta ativa (comportamento historico).
                if (entry.children.some(c => c.page && (pageComponent || '').startsWith(c.page))) {
                    saved[entry.group] = true;
                }
                // Phase 56 v13.0: `defaultOpen: true` mantem grupo aberto na PRIMEIRA
                // visita (sem preferencia salva). Se o user fechou o grupo manualmente,
                // sessionStorage guardou false — respeitamos essa escolha.
                if (entry.defaultOpen && !(entry.group in saved)) {
                    saved[entry.group] = true;
                }
            }
        });
        return saved;
    });

    // Persiste os grupos abertos a cada mudança (sobrevive ao remount da navegação).
    useEffect(() => {
        try { sessionStorage.setItem(SIDEBAR_GROUPS_KEY, JSON.stringify(openGroups)); } catch { /* ignora */ }
    }, [openGroups]);

    /** Alterna abertura/fechamento de um grupo. */
    const toggleGroup = (groupLabel) => {
        setOpenGroups(prev => ({ ...prev, [groupLabel]: !prev[groupLabel] }));
    };

    // ── Preservação da posição de scroll do nav entre navegações ─────────────
    // Callback ref ESTÁVEL (useCallback []) para o React não redisparar a
    // restauração a cada re-render; restaura o scrollTop salvo só uma vez por
    // montagem (navRestoredRef). onScroll grava a posição na sessão.
    const navRestoredRef = useRef(false);
    const setNavEl = useCallback((el) => {
        if (!el || navRestoredRef.current) return;
        try {
            const v = sessionStorage.getItem(SIDEBAR_SCROLL_KEY);
            if (v) el.scrollTop = parseInt(v, 10) || 0;
        } catch { /* ignora */ }
        navRestoredRef.current = true;
    }, []);
    const handleNavScroll = useCallback((e) => {
        try { sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(e.currentTarget.scrollTop)); } catch { /* ignora */ }
    }, []);

    useEffect(() => {
        if (flash?.success || flash?.error) {
            setToast({ message: flash.success || flash.error, type: flash.success ? 'success' : 'error' });
            const t = setTimeout(() => setToast(null), 4500);
            return () => clearTimeout(t);
        }
    }, [flash]);

    // Aceita string (comportamento clássico) ou array — matcha se QUALQUER
    // entrada do array for prefixo do pageComponent atual. Necessário pra
    // itens do NAV que representam a mesma "área" mas renderizam páginas
    // diferentes por role (ex: Dashboard admin vs Performance/Dashboard
    // do analista/estrategista — ambos ativam o mesmo item Dashboard).
    const isActive = (page) => {
        if (!page) return false;
        const current = pageComponent || '';
        if (Array.isArray(page)) return page.some((p) => current.startsWith(p));
        return current.startsWith(page);
    };

    const initials = user?.name
        ? user.name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase()
        : '?';

    // Helper de RENDER (não é um componente): precisa ser invocado como função
    // — `renderSidebar({...})` — e NÃO como <Componente/>. Se fosse usado como
    // elemento JSX, o React o trataria como um tipo novo a cada render do
    // AppLayout (a função é recriada toda vez), remontando toda a sidebar e
    // zerando o scroll do nav sempre que um grupo era aberto/fechado.
    const renderSidebar = ({ mobile = false }) => (
        <div className={cn(
            'flex flex-col h-full transition-all duration-300',
            'bg-[#0b0c10] border-r border-white/[0.06]',
            mobile ? 'w-64' : collapsed ? 'w-16' : 'w-64',
        )}>
            {/* Top gradient line */}
            <div className="h-[3px] shrink-0 bg-ecf-grad" />

            {/* Logo */}
            <div className={cn(
                'flex items-center h-[60px] px-4 border-b border-white/[0.06] shrink-0',
                collapsed && !mobile ? 'justify-center' : 'gap-3'
            )}>
                {(!collapsed || mobile) ? (
                    <Link href={route('dashboard')} className="inline-flex items-center" aria-label="Ir para a página principal">
                        <img
                            src={logoSrc}
                            alt="ECF Consultoria"
                            className="ecf-logo h-7 w-auto object-contain"
                            onError={e => { e.currentTarget.style.display = 'none'; }}
                        />
                    </Link>
                ) : (
                    <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-ecf-yellow shrink-0">
                        <span className="text-[#252525] font-display font-bold text-sm">E</span>
                    </div>
                )}
            </div>

            {/* Nav — só o desktop persiste scroll (mobile é overlay efêmero) */}
            <nav
                ref={mobile ? undefined : setNavEl}
                onScroll={mobile ? undefined : handleNavScroll}
                className="flex-1 py-3 px-2 space-y-0.5 overflow-y-auto"
            >
                {filteredTree.map((entry, idx) => {
                    // ── Item de topo (link direto) ───────────────────────────
                    if (!entry.group) {
                        const active = isActive(entry.page);
                        return (
                            <Link
                                key={entry.routeName}
                                href={route(entry.routeName, entry.routeParams ?? {})}
                                className={cn(
                                    'flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-medium transition-all duration-150',
                                    active
                                        ? 'bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20'
                                        : 'text-white/60 hover:text-white hover:bg-white/[0.05] border border-transparent'
                                )}
                            >
                                {entry.iconSrc ? (
                                    // Phase 56 v13.0: SVG externo (logo de marca) — usado para
                                    // marketplaces (Mercado Livre, Shopee, Amazon) que ganham
                                    // identidade visual propria em vez de icone lucide generico.
                                    <img
                                        src={entry.iconSrc}
                                        alt=""
                                        aria-hidden="true"
                                        className="shrink-0 h-[18px] w-[18px] object-contain"
                                    />
                                ) : (
                                    <entry.icon className={cn('shrink-0', active ? 'text-ecf-yellow' : 'text-white/40')} size={17} />
                                )}
                                {(!collapsed || mobile) && <span className="truncate">{entry.label}</span>}
                                {entry.showBadge && badgeCounters[entry.showBadge] > 0 && (!collapsed || mobile) && (
                                    <span className="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500/20 border border-red-500/30 text-red-300 text-[10px] font-bold shrink-0">
                                        {badgeCounters[entry.showBadge] > 99 ? '99+' : badgeCounters[entry.showBadge]}
                                    </span>
                                )}
                                {entry.badgeText && !entry.showBadge && (!collapsed || mobile) && (
                                    // Phase 56 v13.0: badge estatico (ex: "Em breve") diferente do
                                    // showBadge (contador dinamico). Usado nos stubs Shopee/Amazon.
                                    <span className="ml-auto inline-flex items-center h-5 px-1.5 rounded-full bg-white/[0.08] border border-white/10 text-white/50 text-[10px] font-medium shrink-0">
                                        {entry.badgeText}
                                    </span>
                                )}
                                {active && (!collapsed || mobile) && !entry.showBadge && !entry.badgeText && (
                                    <span className="ml-auto w-1.5 h-1.5 rounded-full bg-ecf-yellow shrink-0" />
                                )}
                            </Link>
                        );
                    }

                    // ── Grupo colapsável ─────────────────────────────────────
                    const isOpen     = !!openGroups[entry.group];
                    // Grupo marcado como ativo se algum filho corresponde à rota atual.
                    // Dividers nao tem `page` — filtrar antes pra evitar coercao em isActive.
                    const groupActive = entry.children.some(c => c.page && isActive(c.page));

                    /**
                     * Ao clicar num grupo enquanto a sidebar está collapsed (desktop),
                     * expande a sidebar primeiro e depois abre o grupo.
                     */
                    const handleGroupClick = () => {
                        if (collapsed && !mobile) {
                            setCollapsed(false);
                            setOpenGroups(prev => ({ ...prev, [entry.group]: true }));
                        } else {
                            toggleGroup(entry.group);
                        }
                    };

                    return (
                        <div key={`group-${entry.group}-${idx}`}>
                            {/* Header do grupo (item principal): mantém o destaque "active" em caixa amarela */}
                            <button
                                onClick={handleGroupClick}
                                className={cn(
                                    'w-full flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-medium transition-all duration-150',
                                    groupActive
                                        ? 'bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20'
                                        : 'text-white/60 hover:text-white hover:bg-white/[0.05] border border-transparent'
                                )}
                            >
                                {entry.iconSrc ? (
                                    <img
                                        src={entry.iconSrc}
                                        alt=""
                                        aria-hidden="true"
                                        className="shrink-0 h-[18px] w-[18px] object-contain"
                                    />
                                ) : (
                                    <entry.icon className={cn('shrink-0', groupActive ? 'text-ecf-yellow' : 'text-white/40')} size={17} />
                                )}
                                {(!collapsed || mobile) && <span className="truncate flex-1 text-left">{entry.group}</span>}
                                {(!collapsed || mobile) && (
                                    <ChevronDown
                                        size={14}
                                        className={cn(
                                            'ml-auto shrink-0 transition-transform duration-200',
                                            isOpen ? 'rotate-180' : ''
                                        )}
                                    />
                                )}
                            </button>

                            {/* Filhos do grupo (visíveis somente quando aberto e não collapsed) */}
                            {isOpen && (!collapsed || mobile) && (
                                <div className="ml-3 border-l border-white/[0.06] pl-2 mt-0.5 space-y-0.5">
                                    {entry.children.map((child, childIdx) => {
                                        // Phase 56 v13.0: entry tipo `divider` renderiza um label
                                        // de secao dentro do grupo (sem link/hover). Introduzido
                                        // para separar Performance de Polos dentro do grupo ML.
                                        if (child.divider) {
                                            return (
                                                <div
                                                    key={`divider-${child.divider}-${childIdx}`}
                                                    className="pt-3 pb-1 px-3 text-[10px] uppercase tracking-wide text-white/40 select-none"
                                                >
                                                    {child.divider}
                                                </div>
                                            );
                                        }
                                        const childActive = isActive(child.page);
                                        return (
                                            <Link
                                                key={child.routeName + JSON.stringify(child.routeParams ?? {})}
                                                href={route(child.routeName, child.routeParams ?? {})}
                                                className={cn(
                                                    'flex items-center gap-3 rounded-[10px] px-3 py-2 text-[13px] font-medium transition-all duration-150',
                                                    // Sub-item ativo: SÓ a fonte amarela (sem caixa/borda/bg nem dot).
                                                    childActive
                                                        ? 'text-ecf-yellow'
                                                        : 'text-white/60 hover:text-white hover:bg-white/[0.05]'
                                                )}
                                            >
                                                <child.icon className={cn('shrink-0', childActive ? 'text-ecf-yellow' : 'text-white/40')} size={17} />
                                                <span className="truncate">{child.label}</span>
                                                {child.showBadge && badgeCounters[child.showBadge] > 0 && (
                                                    <span className="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500/20 border border-red-500/30 text-red-300 text-[10px] font-bold shrink-0">
                                                        {badgeCounters[child.showBadge] > 99 ? '99+' : badgeCounters[child.showBadge]}
                                                    </span>
                                                )}
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    );
                })}
            </nav>

            {/* User footer */}
            <div className="border-t border-white/[0.06] p-3 shrink-0">
                <div className={cn('flex items-center gap-3', collapsed && !mobile ? 'justify-center' : '')}>
                    <div className="w-8 h-8 rounded-full bg-ecf-yellow/20 border border-ecf-yellow/30 flex items-center justify-center shrink-0">
                        <span className="text-ecf-yellow text-xs font-bold">{initials}</span>
                    </div>
                    {(!collapsed || mobile) && (
                        <>
                            <div className="flex-1 min-w-0">
                                <p className="text-white text-[13px] font-semibold truncate leading-tight">{user?.name}</p>
                                <p className="text-ecf-yellow text-[11px] font-medium mt-0.5 truncate">
                                    {user?.is_admin
                                        ? 'Admin'
                                        : user?.setor_principal
                                            ? (user.setor_principal.cargo
                                                ? `${user.setor_principal.cargo} · ${user.setor_principal.nome}`
                                                : user.setor_principal.nome)
                                            : roleLabel[user?.role]}
                                </p>
                            </div>
                            <button
                                onClick={() => router.post(route('logout'))}
                                className="text-white/30 hover:text-white/60 transition-colors p-1 rounded"
                            >
                                <LogOut size={15} />
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );

    return (
        <div className="flex h-screen bg-[#050507] overflow-hidden">
            {/* Desktop sidebar */}
            <aside className={cn('hidden md:flex flex-col transition-all duration-300', collapsed ? 'w-16' : 'w-64')}>
                {renderSidebar({})}
            </aside>

            {/* Mobile sidebar overlay */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 md:hidden">
                    <div
                        className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                        onClick={() => setMobileOpen(false)}
                    />
                    <aside className="absolute left-0 top-0 h-full w-64 z-10">
                        {renderSidebar({ mobile: true })}
                    </aside>
                </div>
            )}

            {/* Main content */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
                {/* Header */}
                <header className="h-[60px] shrink-0 flex items-center justify-between px-5 border-b border-white/[0.06] bg-[#0b0c10]/60 backdrop-blur-sm relative">
                    {/* Bottom gradient accent */}
                    <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-ecf-grad opacity-40" />

                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setMobileOpen(true)}
                            className="md:hidden text-white/50 hover:text-white p-1.5 rounded-lg hover:bg-white/[0.05] transition-colors"
                        >
                            <Menu size={18} />
                        </button>
                        <button
                            onClick={() => setCollapsed(c => !c)}
                            className="hidden md:flex text-white/30 hover:text-white/60 p-1.5 rounded-lg hover:bg-white/[0.05] transition-colors"
                        >
                            {collapsed ? <ChevronRight size={16} /> : <ChevronLeft size={16} />}
                        </button>
                        {title && (
                            <h1 className="text-white font-display font-bold text-base tracking-tight">{title}</h1>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Sino de notificações — Phase 10 (SINO-01) */}
                        <NotificationBell />
                        {/* Alternância modo claro/escuro — ao lado do perfil */}
                        <ThemeToggle />
                        <Link
                            href={route('profile.edit')}
                            className="text-white/30 hover:text-white/60 p-1.5 rounded-lg hover:bg-white/[0.05] transition-colors"
                        >
                            <User size={16} />
                        </Link>
                    </div>
                </header>

                {/* Page content */}
                <main className="flex-1 overflow-y-auto p-6 animate-fade-in">
                    {children}
                </main>
            </div>

            {/* Toast */}
            {toast && (
                <div className={cn(
                    'fixed bottom-5 right-5 z-50 flex items-center gap-3 rounded-xl px-4 py-3 shadow-2xl text-sm font-semibold',
                    'border backdrop-blur-md',
                    toast.type === 'success'
                        ? 'bg-green-950/90 border-green-500/30 text-green-300'
                        : 'bg-red-950/90 border-red-500/30 text-red-300'
                )}>
                    <span>{toast.message}</span>
                    <button onClick={() => setToast(null)} className="opacity-60 hover:opacity-100 transition-opacity">
                        <X size={14} />
                    </button>
                </div>
            )}
        </div>
    );
}
