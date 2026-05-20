import { Link, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import {
    LayoutDashboard, Building2, Users, CalendarCheck,
    Star, Target, FileText, ChevronLeft, ChevronRight,
    LogOut, User, Menu, X, Trophy, Briefcase, ShieldCheck,
    BarChart2, PlusCircle, Clock, ClipboardCheck, LayoutList, Store, ShoppingCart, BookOpen, FolderKanban, SlidersHorizontal,
    AlertTriangle, ListChecks, FileBarChart, Banknote, Package2, ScrollText,
    Code2, Crown, Shield
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Cada item de menu é resolvido por UMA permission key (ver app/Support/Permissions.php).
 * Substitui o gating anterior baseado em `roles[]` + `pubPerm`.
 * `excludeRoles` permite ocultar item pra role específica (ex: admin não vê "Meu Painel").
 */
const NAV_ITEMS = [
    // ── ECF Consultoria ─────────────────────────────────────────────────────
    { label: 'Dashboard',  routeName: 'dashboard',         page: 'Dashboard',   icon: LayoutDashboard, permission: 'core.dashboard' },
    { label: 'Carteira',   routeName: 'portfolio.own',     page: 'Portfolio',   icon: Briefcase,       permission: 'core.carteira' },
    { label: 'Empresas',   routeName: 'companies.index',   page: 'Companies',   icon: Building2,       permission: 'core.empresas' },
    { label: 'Usuários',   routeName: 'users.index',       page: 'Users',       icon: Users,           permission: 'core.usuarios' },
    { label: 'Reuniões',   routeName: 'meetings.index',    page: 'Meetings',    icon: CalendarCheck,   permission: 'core.reunioes' },
    { label: 'NPS',        routeName: 'nps.index',         page: 'Nps',         icon: Star,            permission: 'core.nps' },
    { label: 'Metas',      routeName: 'goals.index',       page: 'Goals',       icon: Target,          permission: 'core.metas' },
    { label: 'PPA',        routeName: 'ppa.index',         page: 'Ppa',         icon: FileText,        permission: 'core.ppa' },
    { label: 'Desempenho', routeName: 'performance.index', page: 'Performance', icon: Trophy,          permission: 'core.performance' },
    { label: 'Grants',     routeName: 'grants.index',      page: 'Grants',      icon: ShieldCheck,     permission: 'core.grants' },
    { label: 'Sugadores',  routeName: 'sugadores.index',   page: 'Sugadores',   icon: AlertTriangle,   permission: 'core.sugadores', showBadge: 'sugadores_pendentes' },
    // ── Meu Setor (líder) ───────────────────────────────────────────────────
    { label: 'Meu Setor',  routeName: 'lideranca.index',   page: 'Lideranca',   icon: Crown,           permission: 'lideranca.dashboard_setor', leadSeparatorBefore: true },
    // ── Dev (interno) ───────────────────────────────────────────────────────
    { label: 'Log',             routeName: 'activity-log.index',  page: 'ActivityLog',         icon: ScrollText, permission: 'sistema.activity_log',    devSeparatorBefore: true },
    { label: 'Desenvolvimento', routeName: 'dev.desenvolvimento', page: 'Dev/Desenvolvimento', icon: Code2,      permission: 'sistema.desenvolvimento' },
    { label: 'Setores',         routeName: 'admin.setores.index', page: 'Admin/Setores',       icon: Shield,     permission: 'sistema.setores' },
    // ── Publicações MLB ─────────────────────────────────────────────────────
    { label: 'Pub · Dashboard', routeName: 'mlb.dashboard',    page: 'Mlb/Dashboard',    icon: BarChart2,      permission: 'mlb.dashboard',     mlbSeparatorBefore: true },
    { label: 'Projetos',        routeName: 'mlb.projetos',     page: 'Mlb/Projetos',     icon: FolderKanban,   permission: 'mlb.projetos' },
    { label: 'Treinamentos',    routeName: 'mlb.treinamentos', page: 'Mlb/Treinamentos', icon: BookOpen,       permission: 'mlb.treinamento' },
    { label: 'Meu Painel',      routeName: 'mlb.meu-painel',   page: 'Mlb/MeuPainel',    icon: LayoutList,     permission: 'mlb.meu_painel',    excludeRoles: ['admin'] },
    { label: 'Publicação',      routeName: 'mlb.publicacoes',  page: 'Mlb/Publicacoes',  icon: PlusCircle,     permission: 'mlb.publicacoes' },
    { label: 'Vendas',          routeName: 'mlb.vendas',       page: 'Mlb/Vendas',       icon: ShoppingCart,   permission: 'mlb.vendas' },
    { label: 'Histórico',       routeName: 'mlb.historico',    page: 'Mlb/Historico',    icon: Clock,          permission: 'mlb.historico' },
    { label: 'Revisão',         routeName: 'mlb.revisao',      page: 'Mlb/Revisao',      icon: ClipboardCheck, permission: 'mlb.revisao' },
    { label: 'Empresas',        routeName: 'mlb.empresas',              page: 'Mlb/Empresas',       icon: Store,              permission: 'mlb.empresas' },
    { label: 'Implementação',   routeName: 'mlb.implementacao.index',   page: 'Mlb/Implementacao',  icon: ListChecks,         permission: 'mlb.implementacao' },
    { label: 'Metas',           routeName: 'mlb.metas.index',           page: 'Mlb/Metas',          icon: SlidersHorizontal,  permission: 'mlb.metas' },
    // ── Administrativo ──────────────────────────────────────────────────────
    { label: 'Empresas',   routeName: 'admin.empresas',   page: 'Admin/Empresas',   icon: Building2,    permission: 'admin.empresas',    adminSeparatorBefore: true },
    { label: 'Relatório',  routeName: 'admin.relatorio',  page: 'Admin/Relatorio',  icon: FileBarChart, permission: 'admin.relatorio' },
    { label: 'Fechamento', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote,     permission: 'admin.financeiro' },
    { label: 'Inventário', routeName: 'admin.inventario', page: 'Admin/Inventario', icon: Package2,     permission: 'admin.inventario' },
];

const roleLabel = { admin: 'Admin', consultor: 'Consultor', mentor: 'Mentor' };

export default function AppLayout({ children, title }) {
    const { auth, flash, asset_url, sugadores_pendentes } = usePage().props;
    const badgeCounters = { sugadores_pendentes: sugadores_pendentes ?? 0 };
    const { component: pageComponent } = usePage();
    const logoSrc = `${asset_url}/images/logo.png`;
    const user = auth?.user;

    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [toast, setToast] = useState(null);

    const mainRole    = user?.role;
    const permissions = auth?.permissions ?? [];

    /**
     * Filtra os itens do menu por permissão. Admin via short-circuit no backend
     * já recebe todas as keys, então a lógica frontend é uniforme.
     */
    const userNav = useMemo(() =>
        NAV_ITEMS.filter(n => {
            if (n.excludeRoles?.includes(mainRole)) return false;
            return n.permission ? permissions.includes(n.permission) : true;
        }),
        [mainRole, permissions]
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
                            {item.leadSeparatorBefore && (!collapsed || mobile) && (
                                <div className="flex items-center gap-2 px-3 pt-4 pb-1.5">
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                    <span className="text-white/20 text-[10px] font-semibold uppercase tracking-wider">Liderança</span>
                                    <div className="h-px flex-1 bg-white/[0.06]" />
                                </div>
                            )}
                            {item.mlbSeparatorBefore && (!collapsed || mobile) && (
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
