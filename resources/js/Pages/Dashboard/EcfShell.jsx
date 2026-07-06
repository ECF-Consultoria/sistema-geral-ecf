import { Head, Link } from '@inertiajs/react';
import {
    Sparkles,
    LayoutDashboard,
    Store,
    ShoppingCart,
    Package2,
    ArrowRight,
    TrendingUp,
    DollarSign,
    Target,
    Building2,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

/**
 * Phase 58 v13.0 DASH-01 — Shell "Dashboard ECF" (em construcao).
 *
 * Renderizado por DashboardController::ecf. Bypass do pipeline agregado —
 * a agregacao real cross-marketplace fica pra v14+ quando Shopee/Amazon
 * integrarem (hoje 0 empresas com 2+ marketplaces). Objetivo desta tela:
 * comunicar a visao unificada + rotear pro dashboard existente por
 * marketplace, evitando colisao de active-state com /dashboard/mercadolivre.
 */
export default function EcfShell() {
    const kpisFuturos = [
        { label: 'GMV Consolidado', icon: DollarSign, hint: 'Soma através de marketplaces' },
        { label: 'ROAS Global',     icon: TrendingUp, hint: 'Retorno consolidado' },
        { label: 'Vendas Totais',   icon: Target,     hint: 'Pedidos ML + Shopee + Amazon' },
        { label: 'Sellers Ativos',  icon: Building2,  hint: 'Empresas em operação' },
    ];

    return (
        <AppLayout title="Dashboard ECF">
            <Head title="Dashboard ECF — em construção" />

            <div className="p-6 max-w-6xl mx-auto space-y-8">
                {/* Header hero com selo "Em construção" */}
                <div className="relative overflow-hidden bg-gradient-to-br from-ecf-card via-ecf-card to-black/40 border border-white/[0.08] rounded-2xl p-8">
                    {/* Glow decorativo */}
                    <div className="absolute -top-24 -right-24 w-64 h-64 bg-ecf-yellow/10 rounded-full blur-3xl pointer-events-none" />

                    <div className="relative flex items-start gap-4">
                        <div className="flex-shrink-0 w-14 h-14 rounded-xl bg-ecf-yellow/15 border border-ecf-yellow/30 flex items-center justify-center">
                            <Sparkles size={28} className="text-ecf-yellow" />
                        </div>

                        <div className="flex-1">
                            <div className="flex items-center gap-3 flex-wrap">
                                <h1 className="text-white text-3xl font-display font-bold">
                                    Dashboard ECF
                                </h1>
                                <span className="text-[11px] uppercase tracking-wider bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30 px-2.5 py-1 rounded-full font-semibold">
                                    Em construção
                                </span>
                            </div>
                            <p className="text-white/70 text-base mt-2 max-w-2xl leading-relaxed">
                                Visão unificada da sua carteira inteira — GMV, vendas e ROAS somados
                                através de <strong className="text-white">todos os marketplaces</strong> das
                                empresas atendidas (Mercado Livre, Shopee, Amazon) numa única tela.
                            </p>
                            <p className="text-white/50 text-sm mt-3 max-w-2xl">
                                A agregação real será liberada quando integrarmos Shopee e Amazon.
                                Enquanto isso, use o dashboard por marketplace abaixo.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Preview do que vem: grid de KPIs futuros */}
                <div>
                    <h2 className="text-white/80 text-sm font-semibold uppercase tracking-wider mb-3">
                        Prévia — indicadores consolidados
                    </h2>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        {kpisFuturos.map(({ label, icon: Icon, hint }) => (
                            <div
                                key={label}
                                className={cn(
                                    'relative bg-ecf-card/60 border border-white/[0.05] rounded-xl p-4',
                                    'backdrop-blur-sm'
                                )}
                            >
                                <div className="flex items-center justify-between">
                                    <Icon size={16} className="text-white/30" />
                                    <span className="text-[9px] uppercase tracking-wider text-white/25 font-semibold">
                                        Em breve
                                    </span>
                                </div>
                                <div className="text-white/25 text-2xl font-bold mt-3 tabular-nums">
                                    —
                                </div>
                                <div className="text-white/40 text-xs mt-1">{label}</div>
                                <div className="text-white/25 text-[10px] mt-0.5">{hint}</div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Atalhos por marketplace — "enquanto isso" */}
                <div>
                    <h2 className="text-white/80 text-sm font-semibold uppercase tracking-wider mb-3">
                        Enquanto isso, veja por marketplace
                    </h2>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {/* Card Mercado Livre — ativo, CTA principal */}
                        <Link
                            href={route('mercadolivre.dashboard')}
                            className={cn(
                                'group bg-ecf-card border border-white/[0.08] rounded-xl p-5',
                                'hover:border-ecf-yellow/40 hover:bg-ecf-card/80 transition-all'
                            )}
                        >
                            <div className="flex items-center gap-3 mb-3">
                                <img
                                    src="/images/mercado-livre-87.svg"
                                    alt="Mercado Livre"
                                    className="w-8 h-8"
                                />
                                <span className="text-[10px] uppercase tracking-wider bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 px-2 py-0.5 rounded-full font-semibold">
                                    Ativo
                                </span>
                            </div>
                            <h3 className="text-white text-base font-semibold">
                                Dashboard Mercado Livre
                            </h3>
                            <p className="text-white/50 text-xs mt-1 mb-4">
                                Dados reais das empresas ML — vendas, ROAS, sellers, alertas.
                            </p>
                            <span className="inline-flex items-center gap-1.5 text-ecf-yellow text-xs font-semibold group-hover:gap-2 transition-all">
                                Abrir dashboard
                                <ArrowRight size={12} />
                            </span>
                        </Link>

                        {/* Card Shopee — shell em desenvolvimento */}
                        <Link
                            href={route('shopee.dashboard')}
                            className={cn(
                                'group bg-ecf-card/60 border border-white/[0.06] rounded-xl p-5',
                                'hover:border-white/[0.15] hover:bg-ecf-card/80 transition-all'
                            )}
                        >
                            <div className="flex items-center gap-3 mb-3">
                                <img
                                    src="/images/shopee-icon.svg"
                                    alt="Shopee"
                                    className="w-8 h-8 opacity-60"
                                />
                                <span className="text-[10px] uppercase tracking-wider bg-white/[0.05] text-white/50 border border-white/[0.08] px-2 py-0.5 rounded-full font-semibold">
                                    Em breve
                                </span>
                            </div>
                            <h3 className="text-white/70 text-base font-semibold">
                                Dashboard Shopee
                            </h3>
                            <p className="text-white/40 text-xs mt-1 mb-4">
                                Preview da integração — pipeline de dados em desenvolvimento.
                            </p>
                            <span className="inline-flex items-center gap-1.5 text-white/50 text-xs font-semibold group-hover:text-white/70 group-hover:gap-2 transition-all">
                                Ver preview
                                <ArrowRight size={12} />
                            </span>
                        </Link>

                        {/* Card Amazon — shell em desenvolvimento */}
                        <Link
                            href={route('amazon.dashboard')}
                            className={cn(
                                'group bg-ecf-card/60 border border-white/[0.06] rounded-xl p-5',
                                'hover:border-white/[0.15] hover:bg-ecf-card/80 transition-all'
                            )}
                        >
                            <div className="flex items-center gap-3 mb-3">
                                <img
                                    src="/images/icons8-amazon.svg"
                                    alt="Amazon"
                                    className="w-8 h-8 opacity-60"
                                />
                                <span className="text-[10px] uppercase tracking-wider bg-white/[0.05] text-white/50 border border-white/[0.08] px-2 py-0.5 rounded-full font-semibold">
                                    Em breve
                                </span>
                            </div>
                            <h3 className="text-white/70 text-base font-semibold">
                                Dashboard Amazon
                            </h3>
                            <p className="text-white/40 text-xs mt-1 mb-4">
                                Preview da integração — pipeline de dados em desenvolvimento.
                            </p>
                            <span className="inline-flex items-center gap-1.5 text-white/50 text-xs font-semibold group-hover:text-white/70 group-hover:gap-2 transition-all">
                                Ver preview
                                <ArrowRight size={12} />
                            </span>
                        </Link>
                    </div>
                </div>

                {/* Rodape informativo */}
                <div className="flex items-center gap-2 text-white/40 text-xs">
                    <LayoutDashboard size={12} />
                    <span>
                        A visão consolidada será liberada com a integração completa dos
                        marketplaces (roadmap v14+).
                    </span>
                </div>
            </div>
        </AppLayout>
    );
}
