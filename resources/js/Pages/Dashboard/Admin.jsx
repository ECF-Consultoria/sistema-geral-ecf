import AppLayout from '@/Layouts/AppLayout';
import { router, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
    XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, LabelList
} from 'recharts';
import {
    Star, Users, Award,
    DollarSign, BarChart2, Clock, Tv, ChevronDown, X, Trophy, ChevronRight, TrendingUp
} from 'lucide-react';
import { formatCurrency, formatPercent, cn } from '@/lib/utils';
import { SourceBadge } from '@/Components/ui/source-badge';
// Phase 72 Plan 03 v15.0 — Widget de empresas pendentes de NPS no mês corrente
import NpsPendingWidget from '@/Components/Nps/NpsPendingWidget';


const PERIOD_OPTIONS = [
    { value: '1', label: 'Último dia' },
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '180', label: 'Últimos 6 meses' },
];

function ECFSelect({ value, onChange, placeholder, options }) {
    return (
        <div className="relative">
            <select
                value={value || ''}
                onChange={e => onChange(e.target.value)}
                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all cursor-pointer"
            >
                {placeholder && <option value="">{placeholder}</option>}
                {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
        </div>
    );
}

// Phase 18 W5-T4 — Badge "Cust ID Inválido" inline. Renderiza apenas
// quando status === 'invalido' (demais valores ficam neutros). Sem emoji
// por convenção do projeto; tooltip pt-BR via title nativo HTML.
function CustIdInvalidoBadge({ status }) {
    if (status !== 'invalido') return null;
    return (
        <span
            title="Cust ID corrompido — Adman não reconhece. Conectar OAuth ML ou ajustar cadastro."
            className="inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/20 tracking-wide"
        >
            Cust ID Inválido
        </span>
    );
}

function KpiCard({ title, value, sub, icon: Icon, color = 'yellow', empty = false }) {
    const colors = {
        yellow: { text: 'text-ecf-yellow', bg: 'bg-ecf-yellow/10', border: 'border-ecf-yellow/20', dot: 'bg-ecf-yellow' },
        green:  { text: 'text-emerald-400', bg: 'bg-emerald-500/10', border: 'border-emerald-500/20', dot: 'bg-emerald-400' },
        red:    { text: 'text-red-400', bg: 'bg-red-500/10', border: 'border-red-500/20', dot: 'bg-red-400' },
        blue:   { text: 'text-blue-400', bg: 'bg-blue-500/10', border: 'border-blue-500/20', dot: 'bg-blue-400' },
        purple: { text: 'text-purple-400', bg: 'bg-purple-500/10', border: 'border-purple-500/20', dot: 'bg-purple-400' },
    };
    const c = colors[color];

    return (
        <div className="card-ecf rounded-2xl p-5 flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <p className="text-white/50 text-[12px] font-semibold tracking-wide uppercase">{title}</p>
                <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center', c.bg, 'border', c.border)}>
                    <Icon size={15} className={c.text} />
                </div>
            </div>
            <div>
                <p className={cn('font-display font-extrabold text-3xl tracking-tight', empty ? 'text-white/20' : c.text)}>
                    {empty ? '—' : value}
                </p>
                {sub && <p className="text-white/30 text-xs mt-1">{sub}</p>}
            </div>
        </div>
    );
}

const chartStyle = {
    tooltip: { contentStyle: { background: '#0f1116', border: '1px solid rgba(255,255,255,0.07)', borderRadius: 10, fontSize: 12 }, labelStyle: { color: '#9ba0aa' } },
    grid: { stroke: 'rgba(255,255,255,0.04)', strokeDasharray: '4 4' },
    axis: { tick: { fill: '#6a6f79', fontSize: 11 } },
};

/* ─── main ─────────────────────────────────────────────── */
// roleLabel: legacy — usado APENAS no card de ranking (que ainda recebe
// `u.role` do backend via buildRanking). O mapeamento `tipoLabel` para a
// taxonomia nova (cargo no setor Performance) foi movido para
// resources/js/Pages/Portfolio/Carteiras.jsx (quick 260610-lj6).
const roleLabel = { consultor: 'Analista', mentor: 'Estrategista' };

export default function AdminDashboard({
    stats = {},
    revenue_chart = [],
    tacos_chart = [],
    nps_distribution = { promotores: 0, neutros: 0, detratores: 0 },
    performance_equipe = [],
    period = '30',
    filters = {},
    users = [],
    analistas = [],
    estrategistas = [],
    combinacoes = [],
    companies_list = [],
    grupos_list = [],
    companies_performance = [],
    ranking = [],
    adman_last_sync = null,
    // Phase 72 Plan 03 v15.0 — lista de empresas pendentes de NPS este mês
    // (injetada pelo DashboardController via NpsPendingService::forCarteira)
    nps_pendentes = [],
}) {
    const [tvMode, setTvMode] = useState(false);

    // Phase 61-05 (DASH-04/DATA-05) — legenda multi-fonte no header.
    // Guard `sourceCounts &&` mantém backward compat quando flag OFF (undefined).
    const sourceCounts = stats?.source_counts ?? null;

    const applyFilter = (key, value) => {
        router.get(route('dashboard'), {
            ...filters,
            period,
            [key]: value || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const s = {
        total_companies:         stats.total_companies ?? 0,
        avg_tacos:               stats.avg_tacos ?? 0,
        avg_nps:                 stats.avg_nps ?? 0,
        absenteeism_rate:        stats.absenteeism_rate ?? 0,
        total_revenue:           stats.total_revenue ?? 0,
        total_ad_investment_30d: stats.total_ad_investment_30d ?? 0,
        total_net_billing:       stats.total_net_billing ?? 0,
        total_sold_quantity:     stats.total_sold_quantity ?? 0,
        total_ad_spend:          stats.total_ad_spend ?? 0,
        avg_margin:              stats.avg_margin ?? 0,
        avg_profit_share:        stats.avg_profit_share ?? 0,
    };

    const noData = s.total_companies === 0;

    const npsTotal = (nps_distribution.promotores ?? 0) + (nps_distribution.neutros ?? 0) + (nps_distribution.detratores ?? 0);
    const npsScore = npsTotal > 0
        ? Math.round(((nps_distribution.promotores - nps_distribution.detratores) / npsTotal) * 100)
        : 0;

    // Phase 31 (Plan 05 — D-09): escala 1-5 substituiu 0-10. O Pie do widget
    // continua consumindo o shape {promotores, neutros, detratores} (mapeado no
    // DashboardController para 5=promotor, 4=neutro, 1-3=detrator) mas os rotulos
    // visuais agora refletem a nova escala: Excelente (5) / Bom (4) / Ruim (1-3).
    const npsData = [
        { name: 'Excelente (5)', value: nps_distribution.promotores ?? 0, color: '#19e06a' },
        { name: 'Bom (4)',       value: nps_distribution.neutros ?? 0,    color: '#ffe600' },
        { name: 'Ruim (1-3)',    value: nps_distribution.detratores ?? 0, color: '#ff4d4d' },
    ];
    const npsDataFilled = npsTotal === 0 ? [{ name: 'Sem dados', value: 1, color: 'rgba(255,255,255,0.06)' }] : npsData;

    // (Quick task 260610-f69) Listas dos selects derivam de `analistas` /
    // `estrategistas` vindos do backend — não mais do `users.role` legacy.
    // Cross-filter: quando um lado está selecionado, o outro lista apenas
    // profissionais que coabitam ao menos uma empresa via `combinacoes`.
    const analistasFiltrados = filters.estrategista_id
        ? analistas.filter(a => combinacoes.some(c =>
            String(c.analista_id) === String(a.id) &&
            String(c.estrategista_id) === String(filters.estrategista_id)
          ))
        : analistas;

    const estrategistasFiltrados = filters.consultor_id
        ? estrategistas.filter(e => combinacoes.some(c =>
            String(c.estrategista_id) === String(e.id) &&
            String(c.analista_id) === String(filters.consultor_id)
          ))
        : estrategistas;

    const periodLabel = PERIOD_OPTIONS.find(p => p.value === period)?.label ?? '';

    /* ── TV MODE ─────────────────────────────────────────── */
    if (tvMode) {
        return (
            <div className="fixed inset-0 bg-[#050507] z-50 flex flex-col gap-4 p-6">
                {/* Header */}
                <div className="flex items-center justify-between shrink-0">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2.5">
                            <div className="w-9 h-9 rounded-xl bg-ecf-yellow flex items-center justify-center">
                                <span className="text-[#252525] font-display font-extrabold text-base">E</span>
                            </div>
                            <div>
                                <p className="text-white font-display font-extrabold text-xl tracking-tight">ECF Consultoria</p>
                                <p className="text-white/30 text-xs">{periodLabel} · {new Date().toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' })}</p>
                            </div>
                        </div>
                        {/* Gradient separator */}
                        <div className="h-8 w-[2px] bg-ecf-grad opacity-60 rounded-full" />
                        <p className="text-ecf-yellow/60 text-sm font-semibold tracking-widest uppercase">Dashboard Operacional</p>
                    </div>
                    <button
                        onClick={() => setTvMode(false)}
                        className="flex items-center gap-2 text-white/40 hover:text-white text-sm border border-white/10 hover:border-white/20 rounded-xl px-3 py-2 transition-all"
                    >
                        <X size={14} /> Sair do modo TV
                    </button>
                </div>

                {/* Top gradient line */}
                <div className="h-[2px] bg-ecf-grad rounded-full shrink-0" />

                {/* KPIs */}
                <div className="grid grid-cols-5 gap-3 shrink-0">
                    {[
                        { title: 'Empresas', value: s.total_companies, icon: Users, color: 'blue', sub: 'Carteira ativa Performance' },
                        { title: 'TACOS Médio', value: formatPercent(s.avg_tacos), icon: BarChart2, color: 'yellow', sub: 'Últimos 30 dias · Adman' },
                        { title: 'NPS Score', value: (s.avg_nps ?? 0).toFixed(2), icon: Star, color: 'green', sub: `Média ${(s.avg_nps ?? 0).toFixed(2)}/5` },
                        { title: 'Invest. Ads (30d)', value: formatCurrency(s.total_ad_investment_30d), icon: TrendingUp, color: 'red', sub: 'Últimos 30 dias · Adman' },
                        { title: 'Faturamento', value: formatCurrency(s.total_revenue), icon: DollarSign, color: 'purple', sub: 'Últimos 30 dias · Adman' },
                    ].map(k => <KpiCard key={k.title} {...k} empty={noData} />)}
                </div>

                {/* Charts */}
                <div className="grid grid-cols-3 gap-3 flex-1 min-h-0">
                    <div className="col-span-2 card-ecf rounded-2xl p-5 flex flex-col">
                        <p className="text-white/60 text-xs font-semibold tracking-wide uppercase mb-4">Faturamento</p>
                        <div className="flex-1">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={revenue_chart}>
                                    <defs>
                                        <linearGradient id="tvRev" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#ffe600" stopOpacity={0.25} />
                                            <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid {...chartStyle.grid} />
                                    <XAxis dataKey="date" {...chartStyle.axis} />
                                    <YAxis {...chartStyle.axis} tickFormatter={v => formatCurrency(v)} width={90} />
                                    <Tooltip {...chartStyle.tooltip} formatter={v => [formatCurrency(v), 'Faturamento']} />
                                    <Area type="monotone" dataKey="revenue" stroke="#ffe600" strokeWidth={2} fill="url(#tvRev)" dot={false} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </div>

                    <div className="flex flex-col gap-3">
                        <div className="card-ecf rounded-2xl p-5 flex flex-col flex-1 min-h-0">
                            <div className="flex items-center justify-between mb-3">
                                <p className="text-white/60 text-xs font-semibold tracking-wide uppercase">NPS</p>
                                <span className={cn('text-lg font-display font-extrabold', npsScore >= 50 ? 'text-green-400' : npsScore >= 0 ? 'text-ecf-yellow' : 'text-red-400')}>
                                    {npsTotal > 0 ? npsScore : '—'}
                                </span>
                            </div>
                            <div className="flex-1">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie data={npsDataFilled} cx="50%" cy="50%" innerRadius="38%" outerRadius="72%" dataKey="value" strokeWidth={0}>
                                            {npsDataFilled.map((e, i) => <Cell key={i} fill={e.color} />)}
                                        </Pie>
                                        {npsTotal > 0 && <Tooltip {...chartStyle.tooltip} />}
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                        </div>

                        <div className="card-ecf rounded-2xl p-5 flex flex-col flex-1 min-h-0">
                            <p className="text-white/60 text-xs font-semibold tracking-wide uppercase mb-3">TACOS</p>
                            <div className="flex-1">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={tacos_chart}>
                                        <CartesianGrid {...chartStyle.grid} />
                                        <XAxis dataKey="date" {...chartStyle.axis} />
                                        <YAxis {...chartStyle.axis} tickFormatter={v => `${v}%`} />
                                        <Tooltip {...chartStyle.tooltip} formatter={v => [`${v}%`, 'TACOS']} />
                                        <Bar dataKey="tacos" fill="#ffe600" radius={[3, 3, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    /* ── NORMAL MODE ─────────────────────────────────────── */
    return (
        <AppLayout title="Dashboard">
            <div className="space-y-6 max-w-[1400px]">

                {/* Filters bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <ECFSelect
                        value={period}
                        onChange={v => applyFilter('period', v)}
                        options={PERIOD_OPTIONS}
                    />
                    <ECFSelect
                        value={filters.company_id || ''}
                        onChange={v => applyFilter('company_id', v)}
                        placeholder="Todas as empresas"
                        options={(companies_list || []).map(c => ({ value: String(c.id), label: c.name }))}
                    />
                    {grupos_list.length > 0 && (
                        <ECFSelect
                            value={filters.group_id || ''}
                            onChange={v => applyFilter('group_id', v)}
                            placeholder="Todos os grupos"
                            options={grupos_list.map(g => ({ value: String(g.id), label: g.name }))}
                        />
                    )}
                    <ECFSelect
                        value={filters.consultor_id || ''}
                        onChange={v => applyFilter('consultor_id', v)}
                        placeholder="Todos analistas"
                        options={analistasFiltrados.map(u => ({ value: String(u.id), label: u.name }))}
                    />
                    <ECFSelect
                        value={filters.estrategista_id || ''}
                        onChange={v => applyFilter('estrategista_id', v)}
                        placeholder="Todos estrategistas"
                        options={estrategistasFiltrados.map(u => ({ value: String(u.id), label: u.name }))}
                    />

                    <div className="ml-auto flex items-center gap-2">
                        {/* Phase 61-05 — Legenda `stats.source_counts` (DASH-04/DATA-05).
                            Ordem canônica ML → Agregado → Adman → Sem integração. */}
                        {sourceCounts && (
                            <div className="flex items-center gap-2 text-[11px] text-white/50 flex-wrap" data-testid="dashboard-source-legend">
                                <span className="uppercase tracking-wider">Fontes:</span>
                                {sourceCounts.ml > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="ml" />{sourceCounts.ml}</span>}
                                {sourceCounts.unified > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="unified" />{sourceCounts.unified}</span>}
                                {sourceCounts.adman > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="adman" />{sourceCounts.adman}</span>}
                                {sourceCounts.none > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="none" />{sourceCounts.none}</span>}
                            </div>
                        )}
                        {/* Badge D-1 da Adman — substitui o antigo botão "Sincronizar agora"
                            (Phase 16, SC-5/SC-7). Sync é automático 1×/dia às 11h BRT. */}
                        <div
                            title="Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT. Sincronização automática diária às 11h."
                            className="inline-flex items-center gap-1.5 h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white/50 text-[12px]"
                        >
                            <Clock size={12} />
                            {adman_last_sync
                                ? `Atualizado em ${adman_last_sync.label} · D-1 da Adman`
                                : 'D-1 da Adman · sem sync ainda'}
                        </div>
                        <button
                            onClick={() => setTvMode(true)}
                            className="flex items-center gap-1.5 h-9 px-3 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all"
                        >
                            <Tv size={13} /> Modo TV
                        </button>
                    </div>
                </div>

                {/* Empty state banner */}
                {noData && (
                    <div className="rounded-2xl border border-ecf-yellow/10 bg-ecf-yellow/[0.03] px-5 py-4 flex items-center gap-3">
                        <div className="w-1.5 h-8 bg-ecf-grad rounded-full" />
                        <div>
                            <p className="text-white/80 text-sm font-semibold">Nenhum dado disponível</p>
                            <p className="text-white/30 text-xs mt-0.5">Cadastre empresas e configure a API Adman para ver as métricas aqui.</p>
                        </div>
                    </div>
                )}

                {/* KPI Cards — visão geral */}
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                    <KpiCard title="Empresas" value={s.total_companies} sub="Carteira ativa Performance" icon={Users} color="blue" empty={noData} />
                    <KpiCard title="TACOS Médio" value={formatPercent(s.avg_tacos)} sub="Últimos 30 dias · Adman" icon={BarChart2} color="yellow" empty={noData} />
                    <KpiCard title="NPS Médio" value={(s.avg_nps ?? 0).toFixed(2)} sub={`Média ${(s.avg_nps ?? 0).toFixed(2)}/5`} icon={Star} color="green" empty={noData} />
                    <KpiCard title="Invest. Ads (30d)" value={formatCurrency(s.total_ad_investment_30d)} sub="Últimos 30 dias · Adman" icon={TrendingUp} color="red" empty={noData} />
                    <KpiCard title="Faturamento Total" value={formatCurrency(s.total_revenue)} sub="Últimos 30 dias · Adman" icon={DollarSign} color="purple" empty={noData} />
                </div>

                {/* Charts row 1 */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    {/* Revenue */}
                    <div className="lg:col-span-2 card-ecf rounded-2xl p-6">
                        <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1">Faturamento</p>
                        <p className="text-white font-display font-extrabold text-lg mb-5 tracking-tight">Evolução no período</p>
                        {revenue_chart.length === 0 ? (
                            <div className="h-[240px] flex items-center justify-center">
                                <p className="text-white/20 text-sm">Sem dados para exibir</p>
                            </div>
                        ) : (
                            <ResponsiveContainer width="100%" height={240}>
                                <AreaChart data={revenue_chart}>
                                    <defs>
                                        <linearGradient id="revGrad" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#ffe600" stopOpacity={0.2} />
                                            <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid {...chartStyle.grid} />
                                    <XAxis dataKey="date" {...chartStyle.axis} />
                                    <YAxis {...chartStyle.axis} tickFormatter={v => formatCurrency(v)} width={85} />
                                    <Tooltip {...chartStyle.tooltip} formatter={v => [formatCurrency(v), 'Faturamento']} />
                                    <Area type="monotone" dataKey="revenue" stroke="#ffe600" strokeWidth={2} fill="url(#revGrad)" dot={false} activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }} />
                                </AreaChart>
                            </ResponsiveContainer>
                        )}
                    </div>

                    {/* Performance da equipe (substituiu NPS Distribuição em 2026-06-23).
                        BarChart horizontal: 1 barra por membro do setor Performance,
                        comprimento proporcional ao score 0-100. Click leva pra carteira
                        individual. Cores por classificação (excelente/bom/atenção/crítico). */}
                    <div className="card-ecf rounded-2xl p-6">
                        {/* Cabeçalho clicável — navega para /performance via Inertia Link */}
                        <Link
                            href={route('performance.index')}
                            className="group block mb-5"
                        >
                            <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1 flex items-center gap-1.5 group-hover:text-white/70 transition-colors">
                                <Award size={12} /> Desempenho da equipe
                            </p>
                            <div className="flex items-baseline gap-2">
                                <p className="text-white font-display font-extrabold text-lg tracking-tight group-hover:text-ecf-yellow transition-colors">Score por membro</p>
                                <span className="text-white/40 text-[11px]">
                                    · {performance_equipe.length} {performance_equipe.length === 1 ? 'pessoa' : 'pessoas'}
                                </span>
                                <ChevronRight size={14} className="text-white/20 group-hover:text-ecf-yellow/60 transition-colors ml-auto self-center" />
                            </div>
                        </Link>
                        {performance_equipe.length === 0 ? (
                            <div className="h-[260px] flex items-center justify-center">
                                <p className="text-white/20 text-sm">Sem dados de score</p>
                            </div>
                        ) : (
                            <div style={{ height: Math.max(200, performance_equipe.length * 36) }}>
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={performance_equipe}
                                        layout="vertical"
                                        margin={{ top: 5, right: 50, left: 0, bottom: 5 }}
                                        onClick={(e) => {
                                            const id = e?.activePayload?.[0]?.payload?.id;
                                            if (id) router.visit(route('portfolio.show', id));
                                        }}
                                    >
                                        <XAxis type="number" domain={[0, 100]} hide />
                                        <YAxis
                                            type="category"
                                            dataKey="name"
                                            width={130}
                                            tick={{ fill: 'rgba(255,255,255,0.6)', fontSize: 11 }}
                                            axisLine={false}
                                            tickLine={false}
                                        />
                                        <Tooltip
                                            cursor={{ fill: 'rgba(255,255,255,0.04)' }}
                                            contentStyle={{
                                                backgroundColor: 'rgba(15,16,22,0.95)',
                                                border: '1px solid rgba(255,255,255,0.12)',
                                                borderRadius: 8,
                                                fontSize: 12,
                                                color: 'rgba(255,255,255,0.9)',
                                            }}
                                            labelStyle={{ color: 'rgba(255,255,255,0.95)', fontWeight: 600, marginBottom: 4 }}
                                            itemStyle={{ color: 'rgba(255,255,255,0.85)' }}
                                            formatter={(value, _name, props) => {
                                                const c = props?.payload?.classificacao ?? '';
                                                return [`${Math.round(value)} pts · ${c}`, 'Score'];
                                            }}
                                        />
                                        <Bar
                                            dataKey="score"
                                            radius={[4, 4, 4, 4]}
                                            cursor="pointer"
                                        >
                                            {performance_equipe.map((m, i) => (
                                                <Cell key={i} fill={
                                                    m.classificacao === 'excelente' ? '#10b981'
                                                    : m.classificacao === 'bom'       ? '#3b82f6'
                                                    : m.classificacao === 'atencao'   ? '#f59e0b'
                                                    : '#ef4444'
                                                } />
                                            ))}
                                            <LabelList
                                                dataKey="score"
                                                position="right"
                                                fill="rgba(255,255,255,0.85)"
                                                fontSize={11}
                                                formatter={(v) => Math.round(v)}
                                            />
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>
                </div>

                {/* Charts row 2 */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {/* TACOS */}
                    <div className="card-ecf rounded-2xl p-6">
                        <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1">TACOS</p>
                        <p className="text-white font-display font-extrabold text-lg mb-5 tracking-tight">Média por período</p>
                        {tacos_chart.length === 0 ? (
                            <div className="h-[200px] flex items-center justify-center">
                                <p className="text-white/20 text-sm">Sem dados para exibir</p>
                            </div>
                        ) : (
                            <ResponsiveContainer width="100%" height={200}>
                                <BarChart data={tacos_chart}>
                                    <CartesianGrid {...chartStyle.grid} />
                                    <XAxis dataKey="date" {...chartStyle.axis} />
                                    <YAxis {...chartStyle.axis} tickFormatter={v => `${v}%`} />
                                    <Tooltip {...chartStyle.tooltip} formatter={v => [`${v}%`, 'TACOS']} />
                                    <Bar dataKey="tacos" fill="#ffe600" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </div>

                    {/* Companies table */}
                    <div className="card-ecf rounded-2xl p-6">
                        <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1">Empresas</p>
                        <p className="text-white font-display font-extrabold text-lg mb-5 tracking-tight">Performance por empresa</p>
                        <div className="space-y-2.5 max-h-[200px] overflow-y-auto">
                            {(companies_performance || []).length === 0 ? (
                                <p className="text-white/20 text-sm text-center py-6">Nenhuma empresa com dados</p>
                            ) : (
                                companies_performance.map(c => (
                                    <div key={c.id} className="flex items-center justify-between py-2.5 border-b border-white/[0.04] last:border-0">
                                        <div>
                                            {/* Phase 18 W5-T4 — Badge "Cust ID Inválido" inline ao lado do nome */}
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <p className="text-white/80 text-[13px] font-semibold">{c.name}</p>
                                                <CustIdInvalidoBadge status={c.cust_id_status} />
                                                {/* Phase 61-05 (DATA-05) — badge de fonte por linha; guard `c.source &&` mantém backward compat flag OFF */}
                                                {c.source && <SourceBadge variant={c.source} />}
                                            </div>
                                            <p className="text-white/30 text-xs mt-0.5">{c.consultor ?? '—'} / {c.estrategista ?? '—'}</p>
                                        </div>
                                        <div className="text-right space-y-0.5">
                                            <p className="text-[11px] text-white/30">
                                                TACOS <span className="text-ecf-yellow font-bold">{c.tacos ? formatPercent(c.tacos) : '—'}</span>
                                            </p>
                                            <p className="text-[11px] text-white/30">
                                                Fat <span className="text-blue-400 font-bold">{c.revenue ? formatCurrency(c.revenue) : '—'}</span>
                                            </p>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>

                {/* Quick 260623 — "Ranking Analistas e Mentores" removido: widget
                    legado usava avg_nps como métrica única; substituído pelo
                    "Desempenho da equipe" (BarChart por score acima). Cobertura
                    completa do ranking continua disponível em /performance. */}

                {/* Phase 72 Plan 03 v15.0 — Widget de empresas pendentes de NPS este mês.
                    Recebe lista via prop nps_pendentes (Plan 72-02 injection). Empty state
                    tratado dentro do próprio widget — sempre é seguro renderizar. */}
                <NpsPendingWidget pendentes={nps_pendentes ?? []} />

            </div>
        </AppLayout>
    );
}
