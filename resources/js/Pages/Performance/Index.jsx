import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { Trophy, ChevronDown, TrendingUp, CheckSquare, ChevronRight } from 'lucide-react';
import { cn, formatPercent, formatCurrency } from '@/lib/utils';

const PERIOD_OPTIONS = [
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '90', label: 'Últimos 90 dias' },
    { value: '180', label: 'Últimos 6 meses' },
];

const roleLabel = { consultor: 'Analista', mentor: 'Mentor' };

function NpsTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value >= 9 ? 'text-emerald-400' : value >= 7 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-display font-extrabold text-lg', color)}>{value}</span>;
}

function GrowthTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value > 10 ? 'text-emerald-400' : value > 0 ? 'text-ecf-yellow' : 'text-red-400';
    const sign = value > 0 ? '+' : '';
    return <span className={cn('font-semibold text-[13px]', color)}>{sign}{value}%</span>;
}

function PpaTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20 font-bold">—</span>;
    const color = value >= 70 ? 'text-emerald-400' : value >= 40 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-semibold text-[13px]', color)}>{value}%</span>;
}

function MedalBadge({ idx }) {
    if (idx === 0) return <span className="text-ecf-yellow font-display font-extrabold text-xl">🥇</span>;
    if (idx === 1) return <span className="text-white/60 font-display font-extrabold text-xl">🥈</span>;
    if (idx === 2) return <span className="text-orange-400 font-display font-extrabold text-xl">🥉</span>;
    return <span className="text-white/25 font-display font-bold text-base w-7 text-center">{idx + 1}</span>;
}

export default function PerformanceIndex({ ranking = [], period = '30' }) {
    const applyPeriod = (value) => {
        router.get(route('performance.index'), { period: value }, { preserveState: true });
    };

    return (
        <AppLayout title="Desempenho">
            <div className="space-y-5 max-w-[1100px]">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                        <Trophy size={18} className="text-ecf-yellow/70" />
                        <p className="text-white/50 text-sm">Ranking de desempenho</p>
                    </div>
                    <div className="relative">
                        <select
                            value={period}
                            onChange={e => applyPeriod(e.target.value)}
                            className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 transition-all cursor-pointer"
                        >
                            {PERIOD_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                        <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                    </div>
                </div>

                {/* Legend */}
                <div className="flex flex-wrap gap-4 text-[11px] text-white/30">
                    <span className="flex items-center gap-1.5"><TrendingUp size={12} /> Crescim. = crescimento médio do faturamento</span>
                    <span className="flex items-center gap-1.5"><CheckSquare size={12} /> PPA = % empresas com PPA concluído</span>
                </div>

                {ranking.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-12 text-center">
                        <Trophy size={32} className="mx-auto mb-3 text-white/20" />
                        <p className="text-white/40 text-sm">Nenhum dado disponível para o período selecionado</p>
                    </div>
                ) : (
                    <div className="card-ecf rounded-2xl overflow-hidden">
                        {/* Table header */}
                        <div className="grid grid-cols-[2.5rem_1fr_5rem_5rem_5rem_5rem_5rem_5rem_5rem_2rem] gap-2 px-5 py-3 border-b border-white/[0.06] text-white/30 text-[11px] font-semibold uppercase tracking-wide">
                            <span>#</span>
                            <span>Nome</span>
                            <span className="text-right">NPS</span>
                            <span className="text-right">TACOS</span>
                            <span className="text-right">Fat. Total</span>
                            <span className="text-right">Crescim.</span>
                            <span className="text-right">PPA</span>
                            <span className="text-right">Reuniões</span>
                            <span className="text-right">Absent.</span>
                            <span />
                        </div>

                        <div className="divide-y divide-white/[0.04]">
                            {ranking.map((u, idx) => (
                                <div
                                    key={u.id}
                                    onClick={() => router.visit(route('performance.show', u.id))}
                                    className={cn(
                                        'grid grid-cols-[2.5rem_1fr_5rem_5rem_5rem_5rem_5rem_5rem_5rem_2rem] gap-2 px-5 py-4 items-center transition-colors hover:bg-white/[0.04] cursor-pointer',
                                        idx === 0 && 'bg-ecf-yellow/[0.03]'
                                    )}
                                >
                                    <div className="flex items-center justify-center">
                                        <MedalBadge idx={idx} />
                                    </div>

                                    <div>
                                        <p className="text-white font-semibold text-[13px]">{u.name}</p>
                                        <p className="text-white/30 text-[11px]">
                                            {roleLabel[u.role]} · {u.companies_count} empresa{u.companies_count !== 1 ? 's' : ''} · {u.nps_responses} resp.
                                        </p>
                                    </div>

                                    <div className="text-right">
                                        <NpsTag value={u.avg_nps} />
                                    </div>

                                    <div className="text-right">
                                        {u.avg_tacos != null
                                            ? <span className="text-ecf-yellow font-semibold text-[13px]">{formatPercent(u.avg_tacos)}</span>
                                            : <span className="text-white/20 font-bold">—</span>}
                                    </div>

                                    <div className="text-right">
                                        {u.total_revenue != null
                                            ? <span className="text-blue-400 font-semibold text-[12px]">{formatCurrency(u.total_revenue)}</span>
                                            : <span className="text-white/20 font-bold">—</span>}
                                    </div>

                                    <div className="text-right">
                                        <GrowthTag value={u.revenue_growth} />
                                    </div>

                                    <div className="text-right">
                                        <PpaTag value={u.ppa_completion_rate} />
                                    </div>

                                    <div className="text-right">
                                        <span className="text-white/60 font-semibold">{u.total_meetings}</span>
                                    </div>

                                    <div className="text-right">
                                        <span className={cn(
                                            'font-semibold text-[13px]',
                                            u.absenteeism_rate > 20 ? 'text-red-400' : u.absenteeism_rate > 10 ? 'text-ecf-yellow' : 'text-emerald-400'
                                        )}>
                                            {u.total_meetings > 0 ? formatPercent(u.absenteeism_rate) : '—'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-end">
                                        <ChevronRight size={14} className="text-white/20" />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
