import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import {
    ArrowLeft, Trophy, ChevronDown, TrendingUp, Building2,
    CheckSquare, Users, CheckCircle2, XCircle
} from 'lucide-react';
import { cn, formatCurrency, formatPercent } from '@/lib/utils';

const PERIOD_OPTIONS = [
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '90', label: 'Últimos 90 dias' },
    { value: '180', label: 'Últimos 6 meses' },
];

const roleLabel = { consultor: 'Analista', mentor: 'Mentor' };

const ppaStatusLabel = { completed: 'Concluído', active: 'Em andamento', draft: 'Rascunho' };
const ppaStatusColor = { completed: 'text-emerald-400', active: 'text-ecf-yellow', draft: 'text-white/40' };

function SummaryCard({ label, value, color = 'text-white', sub }) {
    return (
        <div className="card-ecf rounded-2xl p-5">
            <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-2">{label}</p>
            <p className={cn('font-display font-extrabold text-2xl leading-none', color)}>{value ?? '—'}</p>
            {sub && <p className="text-white/30 text-[11px] mt-1.5">{sub}</p>}
        </div>
    );
}

function NpsTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20">—</span>;
    const color = value >= 9 ? 'text-emerald-400' : value >= 7 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-bold text-[13px]', color)}>{value}</span>;
}

function GrowthTag({ value }) {
    if (value === null || value === undefined) return <span className="text-white/20">—</span>;
    const color = value > 10 ? 'text-emerald-400' : value > 0 ? 'text-ecf-yellow' : 'text-red-400';
    return <span className={cn('font-semibold text-[13px]', color)}>{value > 0 ? '+' : ''}{value}%</span>;
}

export default function PerformanceShow({ profile_user, companies = [], summary = {}, period = '30' }) {
    const applyPeriod = (value) => {
        router.get(route('performance.show', profile_user.id), { period: value }, { preserveState: true });
    };

    return (
        <AppLayout title={`Desempenho — ${profile_user.name}`}>
            <div className="space-y-5 max-w-[1200px]">

                {/* Breadcrumb + Header */}
                <div className="flex items-center justify-between flex-wrap gap-3">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => router.visit(route('performance.index'))}
                            className="flex items-center gap-1.5 text-white/40 hover:text-white text-[13px] transition-colors"
                        >
                            <ArrowLeft size={14} /> Ranking
                        </button>
                        <span className="text-white/20">/</span>
                        <div className="flex items-center gap-2.5">
                            <div className="w-8 h-8 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                                <Users size={14} className="text-ecf-yellow/70" />
                            </div>
                            <div>
                                <p className="text-white font-display font-bold text-base leading-tight">{profile_user.name}</p>
                                <p className="text-white/40 text-[11px]">{roleLabel[profile_user.role] || profile_user.role} · {summary.companies_count} empresa{summary.companies_count !== 1 ? 's' : ''}</p>
                            </div>
                        </div>
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

                {/* Cards de resumo */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <SummaryCard
                        label="NPS Médio"
                        value={summary.avg_nps ?? '—'}
                        color={summary.avg_nps >= 9 ? 'text-emerald-400' : summary.avg_nps >= 7 ? 'text-ecf-yellow' : summary.avg_nps != null ? 'text-red-400' : 'text-white/30'}
                    />
                    <SummaryCard
                        label="Faturamento Total"
                        value={summary.total_revenue ? formatCurrency(summary.total_revenue) : '—'}
                        color="text-blue-400"
                    />
                    <SummaryCard
                        label="TACOS Médio"
                        value={summary.avg_tacos != null ? `${summary.avg_tacos}%` : '—'}
                        color={summary.avg_tacos > 10 ? 'text-red-400' : 'text-ecf-yellow'}
                    />
                    <SummaryCard
                        label="Reuniões"
                        value={summary.total_meetings}
                        color="text-white/70"
                        sub={`no período`}
                    />
                </div>

                {/* Tabela por empresa */}
                {companies.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-12 text-center">
                        <Building2 size={32} className="mx-auto mb-3 text-white/20" />
                        <p className="text-white/40 text-sm">Nenhuma empresa na carteira deste profissional</p>
                    </div>
                ) : (
                    <div className="card-ecf rounded-2xl overflow-hidden">
                        <div className="flex items-center gap-2 px-5 py-4 border-b border-white/[0.06]">
                            <Building2 size={14} className="text-white/40" />
                            <p className="text-white/50 text-sm">Carteira de clientes</p>
                        </div>

                        {/* Header */}
                        <div className="grid grid-cols-[1fr_5rem_5rem_5rem_5rem_5rem_5rem_6rem] gap-2 px-5 py-3 border-b border-white/[0.04] text-white/30 text-[11px] font-semibold uppercase tracking-wide">
                            <span>Empresa</span>
                            <span className="text-right">NPS</span>
                            <span className="text-right">TACOS</span>
                            <span className="text-right">Fat.</span>
                            <span className="text-right">Cresc.</span>
                            <span className="text-right">Reuniões</span>
                            <span className="text-right">Absent.</span>
                            <span className="text-right">PPA</span>
                        </div>

                        <div className="divide-y divide-white/[0.03]">
                            {companies.map(c => (
                                <div
                                    key={c.id}
                                    className="grid grid-cols-[1fr_5rem_5rem_5rem_5rem_5rem_5rem_6rem] gap-2 px-5 py-3.5 items-center hover:bg-white/[0.02] transition-colors"
                                >
                                    <div>
                                        <p className="text-white font-semibold text-[13px] truncate">{c.name}</p>
                                        <p className="text-white/30 text-[11px]">{c.nps_responses} resp. NPS</p>
                                    </div>

                                    <div className="text-right">
                                        <NpsTag value={c.avg_nps} />
                                    </div>

                                    <div className="text-right">
                                        {c.tacos != null
                                            ? <span className={cn('font-semibold text-[13px]', c.tacos > 10 ? 'text-red-400' : 'text-ecf-yellow')}>{formatPercent(c.tacos)}</span>
                                            : <span className="text-white/20">—</span>}
                                    </div>

                                    <div className="text-right">
                                        {c.revenue != null
                                            ? <span className="text-blue-400 font-semibold text-[12px]">{formatCurrency(c.revenue)}</span>
                                            : <span className="text-white/20">—</span>}
                                    </div>

                                    <div className="text-right">
                                        <GrowthTag value={c.revenue_growth} />
                                    </div>

                                    <div className="text-right">
                                        <span className="text-white/60 font-semibold text-[13px]">{c.total_meetings}</span>
                                    </div>

                                    <div className="text-right">
                                        {c.absenteeism_rate != null ? (
                                            <span className={cn('font-semibold text-[13px]',
                                                c.absenteeism_rate > 20 ? 'text-red-400' : c.absenteeism_rate > 10 ? 'text-ecf-yellow' : 'text-emerald-400')}>
                                                {formatPercent(c.absenteeism_rate)}
                                            </span>
                                        ) : <span className="text-white/20">—</span>}
                                    </div>

                                    <div className="text-right">
                                        {c.ppa_status ? (
                                            <span className={cn('text-[11px] font-semibold', ppaStatusColor[c.ppa_status] || 'text-white/30')}>
                                                {ppaStatusLabel[c.ppa_status] || c.ppa_status}
                                            </span>
                                        ) : <span className="text-white/20 text-[11px]">—</span>}
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
