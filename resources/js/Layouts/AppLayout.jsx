import { Link, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import {
    LayoutDashboard, Building2, Users, CalendarCheck,
    Star, Target, FileText, ChevronLeft, ChevronRight, ChevronDown,
    LogOut, User, Menu, X, Trophy, Briefcase, ShieldCheck,
    BarChart2, LineChart, PlusCircle, Clock, ClipboardCheck, LayoutList, Store, ShoppingCart, BookOpen, FolderKanban, SlidersHorizontal,
    AlertTriangle, ListChecks, FileBarChart, Banknote, Package2, ScrollText,
    Code2, Crown, Shield, Send, Link2, TrendingUp, Settings, Inbox, PieChart
} from 'lucide-react';
import { cn } from '@/lib/utils';
import NotificationBell from '@/Components/NotificationBell';

/**
 * Árvore de navegação com suporte a grupos colapsáveis.
 * Cada entrada é UM item de topo (link direto) OU um grupo { group, icon, children }.
 * Regras de gating (permission/excludeRoles) são idênticas ao array plano anterior.
 *
 * Grupo especial "Empresas": o header navega para companies.index ao clicar no label,
 * mas o chevron expande/recolhe os filhos independentemente.
 */
const NAV_TREE = [
    // ── Item de topo ────────────────────────────────────────────────────────
    { label: 'Dashboard', routeName: 'dashboard', page: 'Dashboard', icon: LayoutDashboard, permission: 'core.dashboard' },

    // ── Grupo: Dados Estratégicos ────────────────────────────────────────────
    {
        group: 'Dados Estratégicos',
        icon: LineChart,
        children: [
            // Phase 24 — Painel Executivo. Apenas admin (excludeRoles).
            { label: 'Painel Executivo',       routeName: 'painel-executivo.index', page: 'PainelExecutivo',     icon: LineChart,      excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            // Phase 27 — Concentração e Forecast 90d. Apenas admin (excludeRoles).
            { label: 'Concentração e Previsão', routeName: 'concentracao.index',    page: 'Concentracao',        icon: TrendingUp,     excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
            // Phase 23 — Alertas Estratégicos. excludeRoles esconde publicador/analista/gestor/lider.
            { label: 'Alertas Estratégicos',   routeName: 'alertas.index',          page: 'AlertasEstrategicos', icon: AlertTriangle,  showBadge: 'alertas_criticos_count', excludeRoles: ['publicador', 'analista', 'gestor', 'lider'] },
            { label: 'Grants',                 routeName: 'grants.index',           page: 'Grants',              icon: ShieldCheck,    permission: 'core.grants' },
        ],
    },

    // ── Item de topo ────────────────────────────────────────────────────────
    { label: 'Carteira', routeName: 'portfolio.own', page: 'Portfolio', icon: Briefcase, permission: 'core.carteira' },

    // ── Item de topo: Empresas (link direto; "Pendências" é só uma aba, não vira sub-item) ──
    { label: 'Empresas', routeName: 'companies.index', page: 'Companies', icon: Building2, permission: 'core.empresas' },

    // ── Itens de topo ───────────────────────────────────────────────────────
    // Phase 37 Plan 37-07 (REQ-37-09) — item "Serviços" removido do nivel raiz;
    // movido para dentro do grupo Comercial abaixo (junto com HubSpot Line Items
    // e Grupos) pq a manutencao do catalogo eh atribuicao do setor Comercial.
    { label: 'Usuários',            routeName: 'users.index',             page: 'Users',            icon: Users,        permission: 'core.usuarios' },
    { label: 'Setores',             routeName: 'admin.setores.index',     page: 'Admin/Setores',    icon: Shield,       permission: 'sistema.setores' },
    { label: 'Enviar notificação',  routeName: 'notificacoes.nova',       page: 'Notificacoes/Nova', icon: Send,        permission: 'notificacoes.criar' },
    { label: 'Reuniões',            routeName: 'meetings.index',          page: 'Meetings',         icon: CalendarCheck, permission: 'core.reunioes' },
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
            { label: 'Emails enviados',  routeName: 'nps.emails-enviados.index',  page: 'Nps/EmailsEnviados',  icon: Inbox,    excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
        ],
    },
    { label: 'Metas',               routeName: 'goals.index',             page: 'Goals',            icon: Target,       permission: 'core.metas' },
    { label: 'PPA',                 routeName: 'ppa.index',               page: 'Ppa',              icon: FileText,     permission: 'core.ppa' },
    { label: 'Sugadores',           routeName: 'sugadores.index',         page: 'Sugadores',        icon: AlertTriangle, permission: 'core.sugadores', showBadge: 'sugadores_pendentes' },
    { label: 'Desempenho',          routeName: 'performance.index',       page: 'Performance',      icon: Trophy,       permission: 'core.performance' },

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
            // Phase 36 Plan 36-01 — apontamos direto pra /comercial/empresas/novo (cadastro).
            // A listagem antiga (/comercial/empresas) foi descontinuada; a rota nomeada
            // 'comercial.empresas' agora redireciona pra cá. Trocamos label pra
            // 'Cadastrar empresa' (clareza) e routeName pra 'comercial.empresas.novo'
            // pra evitar o hop do redirect 302.
            { label: 'Cadastrar empresa', routeName: 'comercial.empresas.novo', page: 'Comercial/NovaEmpresa', icon: Building2, permission: 'comercial.cadastrar_empresa' },
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

    // ── Grupo: Publicações MLB ───────────────────────────────────────────────
    {
        group: 'Publicações',
        icon: BarChart2,
        children: [
            { label: 'Pub · Dashboard', routeName: 'mlb.dashboard',    page: 'Mlb/Dashboard',    icon: BarChart2,      permission: 'mlb.dashboard' },
            { label: 'Treinamentos',    routeName: 'mlb.treinamentos', page: 'Mlb/Treinamentos', icon: BookOpen,       permission: 'mlb.treinamento' },
            { label: 'Meu Painel',      routeName: 'mlb.meu-painel',   page: 'Mlb/MeuPainel',    icon: LayoutList,     permission: 'mlb.meu_painel', excludeRoles: ['admin'] },
            { label: 'Publicação',      routeName: 'mlb.publicacoes',  page: 'Mlb/Publicacoes',  icon: PlusCircle,     permission: 'mlb.publicacoes' },
            { label: 'Vendas',          routeName: 'mlb.vendas',       page: 'Mlb/Vendas',       icon: ShoppingCart,   permission: 'mlb.vendas' },
            { label: 'Histórico',       routeName: 'mlb.historico',    page: 'Mlb/Historico',    icon: Clock,          permission: 'mlb.historico' },
            { label: 'Revisão',         routeName: 'mlb.revisao',      page: 'Mlb/Revisao',      icon: ClipboardCheck, permission: 'mlb.revisao' },
            { label: 'Empresas',        routeName: 'mlb.empresas',     page: 'Mlb/Empresas',     icon: Store,          permission: 'mlb.empresas' },
            { label: 'Metas',           routeName: 'mlb.metas.index',  page: 'Mlb/Metas',        icon: SlidersHorizontal, permission: 'mlb.metas' },
        ],
    },

    // ── Grupo: Polos (movido de Publicações) ────────────────────────────────
    {
        group: 'Polos',
        icon: ListChecks,
        children: [
            { label: 'Onboarding',     routeName: 'mlb.implementacao.index', page: 'Mlb/Implementacao',   icon: ListChecks,   permission: 'mlb.implementacao' },
            // Empresas Polos por fase M (M0–M4) — grid de cards com filtro e contagem por M
            { label: 'Empresas',       routeName: 'mlb.polos-empresas',      page: 'Polos/EmpresasPorM',  icon: Building2,    permission: 'mlb.projetos' },
            // Phase 38 — Faturamento por Polo vs Meta (admin-only, dados ECF Drive)
            { label: 'Faturamento Polos', routeName: 'polos.index', page: 'Polos/Index', icon: PieChart, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
        ],
    },

    // ── Grupo: Administrativo ────────────────────────────────────────────────
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

    /**
     * Regra de visibilidade de um item de menu (idêntica ao gating anterior).
     * Retorna false se a role do usuário está em excludeRoles, ou se a permission
     * requerida não consta na lista de permissions do usuário.
     */
    const itemVisivel = (item) => {
        if (item.excludeRoles?.includes(mainRole)) return false;
        return item.permission ? permissions.includes(item.permission) : true;
    };

    /**
     * Filtra a NAV_TREE respeitando as regras de permissão.
     * Itens de topo: mesma regra de gating de sempre.
     * Grupos: filtra os filhos pela mesma regra; descarta o grupo inteiro se nenhum filho for visível.
     * Grupo com permission própria (ex: "Empresas"): descarta se itemVisivel falhar no grupo em si.
     */
    const filteredTree = useMemo(() => {
        return NAV_TREE.reduce((acc, entry) => {
            if (entry.group) {
                // Grupos com permission própria (ex: grupo "Empresas") são verificados também
                if (entry.permission && !itemVisivel(entry)) return acc;
                const filhos = entry.children.filter(itemVisivel);
                if (filhos.length > 0) acc.push({ ...entry, children: filhos });
            } else {
                if (itemVisivel(entry)) acc.push(entry);
            }
            return acc;
        }, []);
    }, [mainRole, permissions]); // eslint-disable-line react-hooks/exhaustive-deps

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
            if (entry.group && entry.children.some(c => (pageComponent || '').startsWith(c.page))) {
                saved[entry.group] = true;
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

    const isActive = (page) => (pageComponent || '').startsWith(page);

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
                            className="h-7 w-auto object-contain"
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
                                <entry.icon className={cn('shrink-0', active ? 'text-ecf-yellow' : 'text-white/40')} size={17} />
                                {(!collapsed || mobile) && <span className="truncate">{entry.label}</span>}
                                {entry.showBadge && badgeCounters[entry.showBadge] > 0 && (!collapsed || mobile) && (
                                    <span className="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500/20 border border-red-500/30 text-red-300 text-[10px] font-bold shrink-0">
                                        {badgeCounters[entry.showBadge] > 99 ? '99+' : badgeCounters[entry.showBadge]}
                                    </span>
                                )}
                                {active && (!collapsed || mobile) && !entry.showBadge && (
                                    <span className="ml-auto w-1.5 h-1.5 rounded-full bg-ecf-yellow shrink-0" />
                                )}
                            </Link>
                        );
                    }

                    // ── Grupo colapsável ─────────────────────────────────────
                    const isOpen     = !!openGroups[entry.group];
                    // Grupo marcado como ativo se algum filho corresponde à rota atual
                    const groupActive = entry.children.some(c => isActive(c.page));

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
                                <entry.icon className={cn('shrink-0', groupActive ? 'text-ecf-yellow' : 'text-white/40')} size={17} />
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
                                    {entry.children.map(child => {
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
