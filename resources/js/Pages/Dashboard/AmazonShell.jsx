import { Head, Link } from '@inertiajs/react';
import { Construction, BarChart3 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

/**
 * Phase 58 v13.0 DASH-03 — Shell "Dashboard Amazon".
 *
 * Renderizado pelo DashboardController::amazon (Plan 58-01). Layout espelha
 * ShopeeShell.jsx — muda apenas icone de marca e label (código agnóstico
 * do marketplace concreto). Ver justificativa da duplicação (em vez de
 * extração para componente comum) no Plan 58-02 Task 2: v14+ pode divergir
 * (Amazon tem SP-API, Shopee tem Open Platform, KPIs vão diferir).
 */
export default function AmazonShell({ marketplace, label }) {
    return (
        <AppLayout title={`Dashboard ${label}`}>
            <Head title={`${label} em desenvolvimento`} />

            <div className="p-6 space-y-6">
                {/* Header com marca */}
                <div className="flex items-center gap-3">
                    <img src="/images/icons8-amazon.svg" className="w-8 h-8" alt={label} />
                    <h1 className="text-white text-2xl font-display font-bold">
                        Dashboard {label}
                    </h1>
                    <span className="ml-auto text-xs bg-white/[0.08] text-white/60 px-2 py-1 rounded-full">
                        Em desenvolvimento
                    </span>
                </div>

                {/* Grid de KPI cards placeholder — mockup, sem dados reais */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {['GMV', 'Vendas', 'ROAS', 'Sellers'].map((kpi) => (
                        <div
                            key={kpi}
                            className={cn(
                                'bg-ecf-card border border-white/[0.06] rounded-xl p-4'
                            )}
                        >
                            <div className="text-white/40 text-xs">{kpi}</div>
                            <div className="text-white/20 text-2xl font-bold mt-2">—</div>
                            <div className="text-white/30 text-[10px] mt-1">
                                Aguardando integração
                            </div>
                        </div>
                    ))}
                </div>

                {/* Card explicativo com CTA para o Dashboard ECF Consolidado */}
                <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-8 text-center">
                    <Construction size={48} className="mx-auto text-ecf-yellow mb-4" />
                    <h2 className="text-white text-lg font-semibold mb-2">
                        Integração {label} em desenvolvimento
                    </h2>
                    <p className="text-white/60 text-sm max-w-md mx-auto mb-6">
                        Estamos preparando o pipeline de dados {label} — API, sync,
                        métricas agregadas. Enquanto isso, o Dashboard ECF Consolidado
                        já mostra os resultados totais das empresas.
                    </p>
                    <Link
                        href={route('ecf.dashboard')}
                        className={cn(
                            'inline-flex items-center gap-2 rounded-lg px-4 py-2.5',
                            'bg-ecf-yellow text-[#252525] text-[13px] font-semibold',
                            'hover:bg-ecf-yellow/90 transition-colors'
                        )}
                    >
                        <BarChart3 size={16} />
                        Ver Dashboard ECF Consolidado
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}
