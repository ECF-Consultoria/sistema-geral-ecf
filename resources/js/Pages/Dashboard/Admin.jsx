import AppLayout from '@/Layouts/AppLayout';
import { router, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    AreaChart, Area, PieChart, Pie, Cell,
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts';
import {
    Star, Users,
    DollarSign, BarChart2, Tv, X, TrendingUp,
    ExternalLink, Loader2, AlertTriangle,
} from 'lucide-react';
import { formatCurrency, formatPercent, cn } from '@/lib/utils';
import { SourceBadge } from '@/Components/ui/source-badge';
// Fase 97 Plan 03 (DASH-97-1/DASH-97-2) — painel de filtros rascunho→aplicar
// + chips + fix da navegação do marketplace. PERIOD_OPTIONS reexportado daqui
// para o modo TV (abaixo) e o header usarem o MESMO conjunto de períodos.
import FiltrosDashboard, { PERIOD_OPTIONS } from '@/Components/Dashboard/FiltrosDashboard';
// Fase 97 Plan 04 (DASH-97-4/5/6/7) — gráfico interativo Faturamento/Margem +
// cards "NPS ruim"/"Score da equipe"/"Novas empresas no mês".
import ChartEvolucao from '@/Components/Dashboard/ChartEvolucao';
import NpsRuimCarrossel from '@/Components/Dashboard/NpsRuimCarrossel';
import ScoreEquipe from '@/Components/Dashboard/ScoreEquipe';
import NovasEmpresas from '@/Components/Dashboard/NovasEmpresas';

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
    // Fase 97 Plan 01 — série diária de margem ponderada (mesmo eixo do
    // revenue_chart), consumida pela aba "Margem" do ChartEvolucao (Plan 97-04).
    margin_chart = [],
    nps_distribution = { positivas: 0, negativas: 0 },
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
    // Fase 97 Plan 02 (DASH-97-5) — respostas de NPS nota baixa do recorte
    // (já excluindo invalidadas, Fase 96). Usado aqui só para "M ruins" do
    // KPI de NPS; o carrossel completo é do Plan 97-04.
    nps_ruins = [],
    // Fase 97 Plan 02 (DASH-97-7) — cards das empresas com contrato ativo
    // iniciado no mês corrente (D3). Widget "Novas empresas no mês" (Plan 97-04).
    novas_empresas = [],
    // Fase 97 Plan 01 — nome da rota Inertia atual ('dashboard' ou
    // 'mercadolivre.dashboard'). Base do fix do bug do marketplace: navegar
    // sempre pela rota CORRENTE, nunca por uma rota fixa (Fase 97 Riscos §1).
    dashboard_route_name = 'dashboard',
}) {
    const [tvMode, setTvMode] = useState(false);

    // Fase 97 Plan 04 — estados REAIS de carregando/erro (sem toggle de
    // demo): `isNavigating` acompanha uma navegação Inertia em andamento
    // (aplicar filtro, remover chip); `navError` acompanha uma falha de
    // rede/servidor durante essa navegação. `router.on` já é o padrão do
    // projeto (ver resources/js/app.jsx — before/success globais).
    const [isNavigating, setIsNavigating] = useState(false);
    const [navError, setNavError] = useState(false);
    useEffect(() => {
        const offStart = router.on('start', () => { setIsNavigating(true); setNavError(false); });
        const offFinish = router.on('finish', () => setIsNavigating(false));
        const offError = router.on('error', () => setNavError(true));
        return () => { offStart(); offFinish(); offError(); };
    }, []);

    // Phase 61-05 (DASH-04/DATA-05) — legenda multi-fonte no header.
    // Guard `sourceCounts &&` mantém backward compat quando flag OFF (undefined).
    const sourceCounts = stats?.source_counts ?? null;

    // Fase 97 Plan 03 (DASH-97-2) — FIX do bug do marketplace: o antigo
    // `applyFilter` usava `route('dashboard')` fixo, então filtrar em
    // /dashboard/mercadolivre caía na dashboard genérica e perdia o recorte
    // `marketplace=meli` (Fase 97 CONTEXT Riscos §1). Agora navega SEMPRE
    // pela rota corrente (`dashboard_route_name`, vindo do backend) e
    // preserva `filters.marketplace` explicitamente — nunca inventado pelo
    // front, sempre o valor que o próprio backend validou (threat T-97-03-01).
    const applyFilters = (next) => {
        router.get(route(dashboard_route_name), {
            period: next.period,
            company_id: next.company_id || undefined,
            group_id: next.group_id || undefined,
            consultor_id: next.consultor_id || undefined,
            estrategista_id: next.estrategista_id || undefined,
            marketplace: filters.marketplace || undefined,
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

    const npsTotal = (nps_distribution.positivas ?? 0) + (nps_distribution.negativas ?? 0);
    const npsScore = npsTotal > 0
        ? Math.round(((nps_distribution.positivas - nps_distribution.negativas) / npsTotal) * 100)
        : 0;

    // Phase 73 Plan 01 v15.0 — buckets simplificados escala 1-5.
    // Backend (DashboardController) agora envia apenas positivas (nota >= 4)
    // e negativas (nota <= 3). Classificação Promotor/Neutro/Detrator (herança
    // NPS 0-10 clássico) foi removida.
    const npsData = [
        { name: 'Positivas (4-5)', value: nps_distribution.positivas ?? 0, color: '#19e06a' },
        { name: 'Negativas (1-3)', value: nps_distribution.negativas ?? 0, color: '#ff4d4d' },
    ];
    const npsDataFilled = npsTotal === 0 ? [{ name: 'Sem dados', value: 1, color: 'rgba(255,255,255,0.06)' }] : npsData;

    // Fase 97 Plan 03 (DASH-97-3) — "M ruins" do KPI de NPS. Usa a contagem
    // real de `nps_ruins` (Plan 97-02, já filtrado pelo recorte e sem
    // invalidadas) quando disponível; cai em `nps_distribution.negativas`
    // como fallback (compat com payloads antigos/flag OFF).
    const npsRuinsCount = Array.isArray(nps_ruins) ? nps_ruins.length : (nps_distribution.negativas ?? 0);

    const periodLabel = PERIOD_OPTIONS.find(p => p.value === period)?.label ?? '';

    // Fase 97 Plan 04 (DASH-97-4) — nome da empresa quando o filtro `company_id`
    // está ativo, para o subtítulo do ChartEvolucao ("... · Nome da Empresa"
    // em vez de "· N empresas"). `companies_list` já vem completo do backend.
    const empresaFiltrada = filters.company_id
        ? companies_list.find(c => String(c.id) === String(filters.company_id))
        : null;

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
                        { title: 'Margem méd.', value: formatPercent(s.avg_margin), icon: BarChart2, color: 'yellow', sub: 'Ponderada por faturamento' },
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

                    <div className="card-ecf rounded-2xl p-5 flex flex-col min-h-0">
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
                </div>
            </div>
        );
    }

    /* ── NORMAL MODE ─────────────────────────────────────── */
    return (
        <AppLayout title="Dashboard Mercado Livre">
            <div className="relative space-y-5 max-w-[1400px]">

                {/* Glow de identidade — brilho suave contido no container (não
                    vaza pra sidebar). Dá o "ar" da marca sem poluir. */}
                <div className="pointer-events-none absolute -top-20 right-4 h-72 w-72 rounded-full blur-[120px] opacity-[0.07] bg-ecf-yellow" />
                <div className="pointer-events-none absolute top-40 -left-10 h-64 w-64 rounded-full blur-[120px] opacity-[0.05] bg-blue-500" />

                {/* Barra de filtros + controles. Cabeçalho/ícone removidos a
                    pedido (2026-07-21) — a dashboard entra direto no conteúdo. */}
                <div className="relative flex items-center gap-3 flex-wrap">
                    <FiltrosDashboard
                        period={period}
                        filters={filters}
                        companiesList={companies_list}
                        gruposList={grupos_list}
                        analistas={analistas}
                        estrategistas={estrategistas}
                        combinacoes={combinacoes}
                        onApply={applyFilters}
                    />

                    <div className="flex items-center gap-2 shrink-0 ml-auto">
                        {/* Estado REAL de "carregando" (navegação Inertia em andamento). */}
                        {isNavigating && (
                            <div className="inline-flex items-center gap-1.5 h-9 px-3 rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/[0.06] text-ecf-yellow text-[12px] font-semibold">
                                <Loader2 size={12} className="animate-spin" />
                                Atualizando…
                            </div>
                        )}
                        {/* Phase 61-05 — Legenda multi-fonte (só com a flag ligada). */}
                        {sourceCounts && (
                            <div className="flex items-center gap-2 text-[11px] text-white/50 flex-wrap" data-testid="dashboard-source-legend">
                                <span className="uppercase tracking-wider">Fontes:</span>
                                {sourceCounts.ml > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="ml" />{sourceCounts.ml}</span>}
                                {sourceCounts.unified > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="unified" />{sourceCounts.unified}</span>}
                                {sourceCounts.adman > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="adman" />{sourceCounts.adman}</span>}
                                {sourceCounts.none > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="none" />{sourceCounts.none}</span>}
                            </div>
                        )}
                        {/* Indicador de atualização (D-1 Adman). */}
                        <div
                            title="Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT. Sincronização automática diária às 11h."
                            className="inline-flex items-center gap-1.5 h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white/50 text-[12px]"
                        >
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.7)]" />
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

                {/* Fase 97 Plan 04 — estado REAL de "erro" (sem toggle de demo):
                    quando uma navegação Inertia (aplicar filtro) falha de verdade
                    (`router.on('error')`), troca todo o conteúdo abaixo por um bloco
                    com "Tentar novamente" (recarrega o recorte atual via router.reload). */}
                {navError ? (
                    <div className="rounded-2xl border border-red-500/20 bg-red-500/[0.03] px-6 py-14 flex flex-col items-center text-center gap-3">
                        <div className="w-12 h-12 rounded-full bg-red-500/15 flex items-center justify-center">
                            <AlertTriangle className="text-red-400" size={22} />
                        </div>
                        <p className="text-white/85 text-[15px] font-bold">Não foi possível carregar os dados do recorte</p>
                        <p className="text-white/40 text-[13px] max-w-[380px]">
                            A conexão com a base do setor falhou ao aplicar o filtro. Os últimos dados exibidos podem estar desatualizados.
                        </p>
                        <button
                            type="button"
                            onClick={() => router.reload()}
                            className="mt-1 h-10 px-4 rounded-xl bg-ecf-yellow text-[#252525] text-[13px] font-bold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all"
                        >
                            Tentar novamente
                        </button>
                    </div>
                ) : (
                <div className={cn('space-y-6 transition-opacity', isNavigating && 'opacity-50 pointer-events-none')}>

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

                {/* KPI Cards — Fase 97 Plan 03 (DASH-97-3): 4 indicadores do
                    redesign, cada um com valor + variação vs. período anterior
                    (quando aplicável) + link para a área completa. Substitui a
                    grade antiga de 5 cards sem delta/link (modo TV mantém o
                    formato antigo — ver bloco `tvMode` acima). */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    {[
                        {
                            key: 'faturamento',
                            label: 'Faturamento total',
                            value: formatCurrency(s.total_revenue),
                            deltaPct: stats.total_revenue_delta_pct,
                            legendaSemDelta: 'vs período anterior',
                            href: route('companies.index'),
                            linkTitle: 'Performance por empresa',
                            topBar: '#60a5fa',
                        },
                        {
                            key: 'margem',
                            label: 'Margem contrib. média',
                            value: formatPercent(s.avg_margin),
                            deltaPp: stats.avg_margin_delta_pp,
                            legendaSemDelta: 'ponderada por faturamento',
                            href: route('performance.index'),
                            linkTitle: 'Margem por profissional (Área da equipe)',
                            topBar: '#34d399',
                        },
                        {
                            key: 'nps',
                            label: 'NPS médio',
                            value: (s.avg_nps ?? 0).toFixed(2),
                            sub: `${npsTotal} respostas · ${npsRuinsCount} ruins`,
                            href: route('nps.index'),
                            linkTitle: 'Respostas de NPS',
                            topBar: '#22c55e',
                        },
                        {
                            key: 'empresas',
                            label: 'Empresas ativas',
                            value: String(s.total_companies),
                            sub: `+${stats.novas_empresas_count ?? 0} novas no mês`,
                            href: route('companies.index'),
                            linkTitle: 'Cadastro de empresas',
                            topBar: '#ffe600',
                        },
                    ].map(k => {
                        // Cor/seta/rótulo do delta computados AQUI, DENTRO do
                        // callback do .map() — pitfall Rollup (Fase 97): nunca
                        // herdar flag derivada de variável de escopo externo.
                        let delta = null;
                        if (k.deltaPct !== undefined) {
                            const semBase = k.deltaPct === null || k.deltaPct === undefined;
                            const dir = semBase ? 'neutral' : (k.deltaPct >= 0 ? 'good' : 'bad');
                            delta = {
                                dir,
                                arrow: dir === 'good' ? '▲' : dir === 'bad' ? '▼' : '•',
                                label: semBase ? 'sem base' : `${k.deltaPct > 0 ? '+' : ''}${k.deltaPct.toFixed(1)}%`,
                            };
                        } else if (k.deltaPp !== undefined) {
                            const semBase = k.deltaPp === null || k.deltaPp === undefined;
                            const dir = semBase ? 'neutral' : (k.deltaPp >= 0 ? 'good' : 'bad');
                            delta = {
                                dir,
                                arrow: dir === 'good' ? '▲' : dir === 'bad' ? '▼' : '•',
                                label: semBase ? 'sem base' : `${k.deltaPp > 0 ? '+' : ''}${k.deltaPp.toFixed(1)} pp`,
                            };
                        }
                        const deltaColors = {
                            good: 'text-emerald-400 bg-emerald-500/10',
                            bad: 'text-red-400 bg-red-500/10',
                            neutral: 'text-white/50 bg-white/[0.06]',
                        };

                        return (
                            <div key={k.key} className="group relative card-ecf rounded-2xl p-4 overflow-hidden">
                                {/* Brilho blur de fundo na cor do KPI (2026-07-21) —
                                    substitui a antiga barra sólida de 2px por um glow
                                    suave que intensifica no hover. */}
                                <div
                                    className="pointer-events-none absolute -top-14 left-1/2 -translate-x-1/2 h-28 w-[78%] rounded-full blur-2xl opacity-[0.22] group-hover:opacity-45 transition-opacity duration-300"
                                    style={{ background: k.topBar }}
                                />
                                <div className="relative z-10 flex flex-col gap-2.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-white/40 text-[10.5px] font-bold uppercase tracking-wide">{k.label}</p>
                                        <Link
                                            href={k.href}
                                            title={k.linkTitle}
                                            className="w-5 h-5 rounded-md flex items-center justify-center text-white/30 hover:text-ecf-yellow hover:bg-white/[0.06] transition-colors shrink-0"
                                        >
                                            <ExternalLink size={12} />
                                        </Link>
                                    </div>
                                    <p className={cn('font-display font-extrabold text-2xl tracking-tight', noData ? 'text-white/20' : 'text-white')}>
                                        {noData ? '—' : k.value}
                                    </p>
                                    {!noData && (
                                        <div className="flex items-center gap-2 flex-wrap">
                                            {delta && (
                                                <span className={cn('inline-flex items-center gap-1 text-[11.5px] font-bold px-1.5 py-0.5 rounded-md', deltaColors[delta.dir])}>
                                                    {delta.arrow} {delta.label}
                                                </span>
                                            )}
                                            <span className="text-white/30 text-[11px]">{k.legendaSemDelta || k.sub}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* Fase 97 — "Evolução no período" em LARGURA TOTAL (mock): abas
                    Faturamento/Margem (D4) + hover (tooltip + Pico/Menor). O
                    BarChart horizontal antigo de "Desempenho da equipe" foi
                    REMOVIDO — o card "Score da equipe" (abaixo) é o substituto. */}
                <ChartEvolucao
                    revenueChart={revenue_chart}
                    marginChart={margin_chart}
                    periodLabel={periodLabel}
                    companiesCount={s.total_companies}
                    companyName={empresaFiltrada?.name ?? null}
                />

                {/* Fase 97 Plan 04 (DASH-97-5/DASH-97-6) — linha 2.1fr/1fr do mockup:
                    "NPS ruim" (carrossel) + "Score da equipe" (nota 0-5 + breakdown,
                    pior→melhor). */}
                <div className="grid grid-cols-1 lg:grid-cols-[2.1fr_1fr] gap-4 items-stretch">
                    <NpsRuimCarrossel respostas={nps_ruins} />
                    <ScoreEquipe membros={performance_equipe} />
                </div>

                {/* Fase 97 Plan 04 (DASH-97-7) — "Novas empresas no mês", largura
                    total e CONDICIONAL (o próprio componente retorna null se vazio). */}
                <NovasEmpresas empresas={novas_empresas} />

                {/* Fase 97 (rework 2026-07-21) — os widgets antigos "TACOS média
                    por período", "Performance por empresa" (tabela) e "Empresas
                    pendentes de NPS" foram REMOVIDOS desta dashboard para bater com
                    o mock: o dado de TACOS/empresa vive no drill-down (KPIs linkam
                    para /companies e /performance) e o NPS pendente foi superado
                    pelo card "NPS ruim". */}

                </div>
                )}

            </div>
        </AppLayout>
    );
}
