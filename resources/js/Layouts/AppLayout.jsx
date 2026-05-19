import { Link, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import {
    LayoutDashboard, Building2, Users, CalendarCheck,
    Star, Target, FileText, ChevronLeft, ChevronRight,
    LogOut, User, Menu, X, Trophy, Briefcase, ShieldCheck,
    BarChart2, PlusCircle, Clock, ClipboardCheck, LayoutList, Store, ShoppingCart, BookOpen, FolderKanban, SlidersHorizontal,
    AlertTriangle, ListChecks, FileBarChart, Banknote, Package2, ScrollText,
    Code2
} from 'lucide-react';
import { cn } from '@/lib/utils';

const DEFAULT_PUB_PERMS = {
    gestor:    ['dashboard', 'meu_painel', 'empresas', 'historico', 'treinamento', 'metas', 'sugadores'],
    lider:     ['dashboard', 'meu_painel', 'publicacoes', 'vendas', 'historico', 'revisao', 'empresas', 'treinamento', 'sugadores'],
    publicador:['meu_painel', 'publicacoes', 'vendas', 'historico'],
    analista:  ['empresas', 'historico', 'sugadores'],
};

const NAV_ITEMS = [
    // ── ECF Consultoria (visíveis por role principal) ──────────────────────
    { label: 'Dashboard',  routeName: 'dashboard',         page: 'Dashboard',   icon: LayoutDashboard, roles: ['admin', 'consultor', 'mentor'] },
    { label: 'Carteira',   routeName: 'portfolio.own',     page: 'Portfolio',   icon: Briefcase,       roles: ['consultor', 'mentor'] },
    { label: 'Empresas',   routeName: 'companies.index',   page: 'Companies',   icon: Building2,       roles: ['admin'] },
    { label: 'Usuários',   routeName: 'users.index',       page: 'Users',       icon: Users,           roles: ['admin'] },
    { label: 'Reuniões',   routeName: 'meetings.index',    page: 'Meetings',    icon: CalendarCheck,   roles: ['admin', 'consultor', 'mentor'] },
    { label: 'NPS',        routeName: 'nps.index',         page: 'Nps',         icon: Star,            roles: ['admin', 'consultor', 'mentor'] },
    { label: 'Metas',      routeName: 'goals.index',       page: 'Goals',       icon: Target,          roles: ['admin', 'consultor', 'mentor'] },
    { label: 'PPA',        routeName: 'ppa.index',         page: 'Ppa',         icon: FileText,        roles: ['admin', 'mentor'] },
    { label: 'Desempenho', routeName: 'performance.index',  page: 'Performance',     icon: Trophy,      roles: ['admin'] },
    { label: 'Grants',     routeName: 'grants.index',       page: 'Grants',          icon: ShieldCheck, roles: ['admin'] },
    { label: 'Sugadores',  routeName: 'sugadores.index',   page: 'Sugadores',   icon: AlertTriangle,   roles: ['admin', 'consultor', 'mentor'], pubPerm: 'sugadores', showBadge: 'sugadores_pendentes' },
    // ── Dev (interno) ───────────────────────────────────────────────────────
    { label: 'Log',             routeName: 'activity-log.index',  page: 'ActivityLog',         icon: ScrollText, roles: ['admin'], devSeparatorBefore: true },
    { label: 'Desenvolvimento', routeName: 'dev.desenvolvimento', page: 'Dev/Desenvolvimento', icon: Code2,      roles: ['admin'] },
    // ── Publicações MLB ──────────────────────────────────────────────────────
    { label: 'Pub · Dashboard', routeName: 'mlb.dashboard',    page: 'Mlb/Dashboard',    icon: BarChart2,      roles: ['admin'], pubPerm: 'dashboard',   mlbSeparatorBefore: true },
    { label: 'Projetos',        routeName: 'mlb.projetos',     page: 'Mlb/Projetos',     icon: FolderKanban,   roles: ['admin'], pubPerm: 'projetos' },
    { label: 'Treinamentos',    routeName: 'mlb.treinamentos', page: 'Mlb/Treinamentos', icon: BookOpen,       roles: ['admin'], pubPerm: 'treinamento' },
    { label: 'Meu Painel',      routeName: 'mlb.meu-painel',   page: 'Mlb/MeuPainel',    icon: LayoutList,     roles: [],        pubPerm: 'meu_painel',  excludeRoles: ['admin'] },
    { label: 'Publicação',      routeName: 'mlb.publicacoes',  page: 'Mlb/Publicacoes',  icon: PlusCircle,     roles: ['admin'], pubPerm: 'publicacoes' },
    { label: 'Vendas',          routeName: 'mlb.vendas',       page: 'Mlb/Vendas',       icon: ShoppingCart,   roles: ['admin'], pubPerm: 'vendas' },
    { label: 'Histórico',       routeName: 'mlb.historico',    page: 'Mlb/Historico',    icon: Clock,          roles: ['admin'], pubPerm: 'historico' },
    { label: 'Revisão',         routeName: 'mlb.revisao',      page: 'Mlb/Revisao',      icon: ClipboardCheck, roles: ['admin'], pubPerm: 'revisao' },
    { label: 'Empresas',        routeName: 'mlb.empresas',              page: 'Mlb/Empresas',       icon: Store,              roles: ['admin'], pubPerm: 'empresas' },
    { label: 'Implementação',   routeName: 'mlb.implementacao.index',   page: 'Mlb/Implementacao', icon: ListChecks,         roles: ['admin'], pubPerm: 'empresas' },
    { label: 'Metas',           routeName: 'mlb.metas.index',           page: 'Mlb/Metas',         icon: SlidersHorizontal,  roles: ['admin'], pubPerm: 'metas' },
    // ── Administrativo ──────────────────────────────────────────────────────
    { label: 'Empresas',   routeName: 'admin.empresas',   page: 'Admin/Empresas',   icon: Building2,    roles: ['admin'], adminSeparatorBefore: true },
    { label: 'Relatório',  routeName: 'admin.relatorio',  page: 'Admin/Relatorio',  icon: FileBarChart, roles: ['admin'] },
    { label: 'Fechamento', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote,     roles: ['admin'] },
    { label: 'Inventário', routeName: 'admin.inventario', page: 'Admin/Inventario', icon: Package2,     roles: ['admin'] },
];

const roleLabel    = { admin: 'Admin', consultor: 'Analista', mentor: 'Mentor' };
const pubRoleLabel = { gestor: 'Gestor Pub.', lider: 'Líder Pub.', publicador: 'Publicador', analista: 'Analista Pub.' };

export default function AppLayout({ children, title }) {
    const { auth, flash, asset_url, sugadores_pendentes } = usePage().props;
    const badgeCounters = { sugadores_pendentes: sugadores_pendentes ?? 0 };
    const { component: pageComponent } = usePage();
    const logoSrc = `${asset_url}/images/logo.png`;
    const user = auth?.user;

    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [toast, setToast] = useState(null);

    const pubRole  = user?.publication_role;
    const mainRole = user?.role;
    const isPurePublicador = pubRole && mainRole !== 'admin';

    const effectivePerms = useMemo(() => {
        const explicit = user?.publication_permissions;
        if (explicit !== null && explicit !== undefined) return explicit;
        return pubRole ? (DEFAULT_PUB_PERMS[pubRole] ?? []) : [];
    }, [user?.publication_permissions, pubRole]);

    const userNav = useMemo(() =>
        NAV_ITEMS.filter(n => {
            if (n.excludeRoles?.includes(mainRole)) return false;
            if (isPurePublicador) {
                return n.pubPerm ? effectivePerms.includes(n.pubPerm) : false;
            }
            const byRole    = n.roles?.includes(mainRole) ?? false;
            const byPubPerm = n.pubPerm ? effectivePerms.includes(n.pubPerm) : false;
            return byRole || byPubPerm;
        }),
        [mainRole, isPurePublicador, effectivePerms]
    );

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

    const SidebarInner = ({ mobile = false }) => (
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
                    <img
                        src={logoSrc}
                        alt="ECF Consultoria"
                        className="h-7 w-auto object-contain"
                        onError={e => { e.currentTarget.style.display = 'none'; }}
                    />
                ) : (
                    <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-ecf-yellow shrink-0">
                        <span className="text-[#252525] font-display font-bold text-sm">E</span>
                    </div>
                )}
            </div>

            {/* Nav */}
            <nav className="flex-1 py-3 px-2 space-y-0.5 overflow-y-auto">
                {userNav.map(item => {
                    const active = isActive(item.page);
                    return (
                        <div key={item.routeName}>
                            {!isPurePublicador && item.mlbSeparatorBefore && (!collapsed || mobile) && (
                                <div className="flex items-center gap-2 px-3 pt-4 pb-1.5">
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                    <span className="text-white/20 text-[10px] font-semibold uppercase tracking-wider">Publicações</span>
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                </div>
                            )}
                            {item.adminSeparatorBefore && (!collapsed || mobile) && (
                                <div className="flex items-center gap-2 px-3 pt-4 pb-1.5">
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                    <span className="text-white/20 text-[10px] font-semibold uppercase tracking-wider">Administrativo</span>
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                </div>
                            )}
                            {item.devSeparatorBefore && (!collapsed || mobile) && (
                                <div className="flex items-center gap-2 px-3 pt-4 pb-1.5">
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                    <span className="text-white/20 text-[10px] font-semibold uppercase tracking-wider">Dev</span>
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                </div>
                            )}
                            <Link
                                href={route(item.routeName)}
                                className={cn(
                                    'flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-medium transition-all duration-150',
                                    active
                                        ? 'bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20'
                                        : 'text-white/60 hover:text-white hover:bg-white/[0.05] border border-transparent'
                                )}
                            >
                                <item.icon className={cn('shrink-0', active ? 'text-ecf-yellow' : 'text-white/40')} size={17} />
                                {(!collapsed || mobile) && <span className="truncate">{item.label}</span>}
                                {item.showBadge && badgeCounters[item.showBadge] > 0 && (!collapsed || mobile) && (
                                    <span className="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500/20 border border-red-500/30 text-red-300 text-[10px] font-bold shrink-0">
                                        {badgeCounters[item.showBadge] > 99 ? '99+' : badgeCounters[item.showBadge]}
                                    </span>
                                )}
                                {active && (!collapsed || mobile) && !item.showBadge && (
                                    <span className="ml-auto w-1.5 h-1.5 rounded-full bg-ecf-yellow shrink-0" />
                                )}
                            </Link>
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
                                <p className="text-ecf-yellow text-[11px] font-medium mt-0.5">
                                    {user?.role === 'admin'
                                        ? 'Admin'
                                        : user?.publication_role
                                            ? pubRoleLabel[user.publication_role]
                                            : (user?.setor || roleLabel[user?.role])}
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
                <SidebarInner />
            </aside>

            {/* Mobile sidebar overlay */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 md:hidden">
                    <div
                        className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                        onClick={() => setMobileOpen(false)}
                    />
                    <aside className="absolute left-0 top-0 h-full w-64 z-10">
                        <SidebarInner mobile />
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
