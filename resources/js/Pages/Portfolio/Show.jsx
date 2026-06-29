import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    ArrowLeft, Search, TrendingUp, TrendingDown, Target, AlertTriangle,
    Trophy, Briefcase, Building2, ShoppingCart, Award, Users, Minus,
} from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact, formatPercent } from '@/lib/utils';
import SparklineCrescimento from '@/Components/Carteira/SparklineCrescimento';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis, Tooltip,
    CartesianGrid, Legend,
    RadialBarChart, RadialBar, PolarAngleAxis,
    RadarChart, Radar, PolarGrid, PolarRadiusAxis,
} from 'recharts';

/**
 * Carteira — redesign 2026-06-19 conforme briefing-carteira-analistas-ui.md.
 *
 * Foco em decisao: card principal com faturamento em destaque, KPIs acionaveis
 * (sem TACOS medio), grafico de evolucao com tooltip rico, tabela orientada a
 * acao (Status + Acao recomendada derivados no backend).
 */

// ── Labels / classes auxiliares ──────────────────────────────────────────────

const STATUS_LABEL = {
    saudavel: 'Saudável',
    atencao:  'Atenção',
    critico:  'Crítico',
};

const STATUS_CLS = {
    saudavel: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
    atencao:  'bg-amber-500/15 text-amber-300 border-amber-500/25',
    critico:  'bg-red-500/15 text-red-300 border-red-500/25',
};

const ROLE_LABEL = {
    consultor:  'Analista',
    mentor:     'Estrategista',
    admin:      'Admin',
    analista:   'Analista',
    estrategista: 'Estrategista',
};

const CLASSIF_LABEL = {
    excelente: 'Excelente',
    bom:       'Bom',
    atencao:   'Atenção',
    critico:   'Crítico',
};

const CLASSIF_CLS = {
    excelente: 'text-emerald-300',
    bom:       'text-sky-300',
    atencao:   'text-amber-300',
    critico:   'text-red-300',
};

const CLASSIF_BG = {
    excelente: 'bg-emerald-500/10 border-emerald-500/30',
    bom:       'bg-sky-500/10 border-sky-500/30',
    atencao:   'bg-amber-500/10 border-amber-500/30',
    critico:   'bg-red-500/10 border-red-500/30',
};

// Explicações curtas pra tooltip de cada métrica (hover no card).
const METRIC_HELP = {
    score: 'Score 0-100 ponderado: 30% crescimento ajustado + 20% empresas crescendo + 20% atingimento de meta + 15% recuperação + 10% cobertura Ads + 5% qualidade (NPS+reuniões). Categorias sem dado têm o peso redistribuído.',
    crescimento_ajustado: 'Crescimento dos últimos 30d vs os 30d anteriores. Calculado pela soma diária de revenue × revenue_prev_period (Adman) — sem depender de cache histórico do nosso DB.',
    empresas_crescendo: 'Quantas das empresas elegíveis tiveram revenue atual > revenue do período anterior (segundo o revenue_prev_period reportado pela Adman).',
    meta: 'Atingimento da meta da carteira. Prioridade: PortfolioGoal de revenue ativo. Se não houver, soma das metas individuais (Goal de revenue por empresa) ativas — basta cadastrar 1 meta numa empresa pra entrar.',
    recuperacao: 'Das empresas que estavam em queda nos 15-30d anteriores (revenue < revenue_prev_period), quantas voltaram a crescer nos últimos 15d.',
    qualidade: 'NPS médio das respostas dos últimos 30d (escala 1-5) + número de reuniões realizadas + % absenteísmo (consultor ou estrategista ausente).',
    prioridade_do_dia: 'Empresas que aparecem em pelo menos 1 alerta acionável (grant expirando ≤30d, em queda MoM, ou sem Ads). Distinct.',
    investimento_ads: 'Total de ad_spend nas empresas da carteira nos últimos 30d. Vem do cache Adman (chave investment) com fallback SUM DB.',
    meta_carteira_kpi: 'Atingimento da meta de revenue da carteira inteira (PortfolioGoal). Se não tem PortfolioGoal mas tem metas individuais, o widget Performance abaixo soma as metas das empresas.',
    empresas_kpi: 'Total de empresas ATIVAS vinculadas a você (consultor ou estrategista) na carteira.',
    faturamento_total: 'Soma do revenue das empresas da carteira nos últimos 30d (mês vigente). Vem do cache Adman gross com fallback SUM DB.',
};

// ── KPI compacto ─────────────────────────────────────────────────────────────

function KpiCard({ label, value, sub, icon: Icon, accent = 'text-white/85', help, onClick, children }) {
    const clickable = !!onClick;
    return (
        <Card
            className={cn(
                'bg-ecf-card/60 border-white/[0.06]',
                clickable && 'cursor-pointer hover:border-white/20 transition-colors'
            )}
            onClick={onClick}
            title={help}
        >
            <CardContent className="p-4">
                <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wide">
                    {Icon && <Icon size={12} />}
                    {label}
                    {help && <span className="text-white/30 text-[9px] cursor-help">ⓘ</span>}
                </div>
                <div className={cn('mt-1.5 text-2xl font-bold tabular-nums', accent)}>{value}</div>
                {sub && <div className="text-white/40 text-[11px] mt-0.5">{sub}</div>}
                {children}
            </CardContent>
        </Card>
    );
}

// ── Chip ──────────────────────────────────────────────────────────────────────

function Chip({ children, tone = 'neutral' }) {
    const tones = {
        positive: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
        negative: 'bg-red-500/15 text-red-300 border-red-500/25',
        neutral:  'bg-white/10 text-white/80 border-white/15',
        meta:     'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30',
    };
    return (
        <span className={cn('inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full border', tones[tone])}>
            {children}
        </span>
    );
}

// ── Section completa de performance com Radial + Radar ──────────────────────

const SCORE_COLOR = {
    excelente: '#10b981',
    bom:       '#3b82f6',
    atencao:   '#f59e0b',
    critico:   '#ef4444',
};

function PerformanceSection({ data, comparacao }) {
    const m = data.metricas;
    const cor = SCORE_COLOR[data.classificacao] ?? '#3b82f6';

    // Pontuações 0-100 por dimensão pra alimentar o radar
    // Quick 260623 v3: dimensão "Execução" (cobertura Ads) descontinuada —
    // dados de "sem Ads" eram ruidosos (cache MISS gerava falso positivo)
    // e premissa errada na carteira ECF (que sempre tem Ads em 100%).
    const dimensoes = [
        { dim: 'Crescimento', valor: clamp01to100(m.crescimento_ajustado_pct, -20, 20), bruto: m.crescimento_ajustado_pct, sufixo: '%' },
        { dim: 'Empresas crescendo', valor: m.empresas_em_crescimento.pct, bruto: m.empresas_em_crescimento.pct, sufixo: '%' },
        { dim: 'Meta',        valor: m.atingimento_meta.pct !== null ? Math.min(100, m.atingimento_meta.pct) : 0, bruto: m.atingimento_meta.pct, sufixo: '%' },
        { dim: 'Recuperação', valor: m.recuperacao.pct ?? 0, bruto: m.recuperacao.pct, sufixo: '%' },
        { dim: 'Qualidade',   valor: m.qualidade.avg_nps !== null ? (m.qualidade.avg_nps / 5) * 100 : 0, bruto: m.qualidade.avg_nps, sufixo: '/5' },
    ];

    // Dado pra Radial (apenas 1 valor — o score)
    const radialData = [{ name: 'Score', value: data.score, fill: cor }];

    return (
        <Card className={cn('border', CLASSIF_BG[data.classificacao] ?? 'border-white/[0.06]')}>
            <CardContent className="p-4 md:p-5">
                <div className="flex items-center justify-between mb-3">
                    <div className="flex items-center gap-2">
                        <Award size={16} className={CLASSIF_CLS[data.classificacao]} />
                        <h3 className="text-white text-sm font-semibold cursor-help" title={METRIC_HELP.score}>
                            Performance do profissional <span className="text-white/30 text-[10px]">ⓘ</span>
                        </h3>
                    </div>
                    <div className="text-right">
                        <div className={cn('text-[11px] font-semibold', CLASSIF_CLS[data.classificacao])}>
                            {comparacao && `${comparacao.classificacao_top} de ${comparacao.tamanho_amostra} ${comparacao.cargo_label.toLowerCase()}s`}
                        </div>
                    </div>
                </div>

                {/* Grid principal: Radial (score) + Radar (6 dimensões) */}
                <div className="grid grid-cols-1 md:grid-cols-[260px_1fr] gap-4">
                    {/* Radial score */}
                    <div className="relative rounded-xl bg-white/[0.02] border border-white/[0.04] p-3 flex items-center justify-center">
                        <div className="h-56 w-full relative">
                            <ResponsiveContainer width="100%" height="100%">
                                <RadialBarChart
                                    cx="50%"
                                    cy="50%"
                                    innerRadius="70%"
                                    outerRadius="100%"
                                    barSize={18}
                                    data={radialData}
                                    startAngle={90}
                                    endAngle={-270}
                                >
                                    <PolarAngleAxis type="number" domain={[0, 100]} tick={false} />
                                    <RadialBar
                                        dataKey="value"
                                        cornerRadius={10}
                                        background={{ fill: 'rgba(255,255,255,0.05)' }}
                                    />
                                </RadialBarChart>
                            </ResponsiveContainer>
                            {/* texto centralizado */}
                            <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                                <div className={cn('text-5xl font-extrabold tabular-nums leading-none', CLASSIF_CLS[data.classificacao])}>
                                    {Math.round(data.score)}
                                </div>
                                <div className="text-white/40 text-[11px] mt-0.5">de 100 pts</div>
                                <div className={cn('text-[12px] font-semibold mt-1.5', CLASSIF_CLS[data.classificacao])}>
                                    {CLASSIF_LABEL[data.classificacao]}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Radar das 6 dimensões */}
                    <div className="rounded-xl bg-white/[0.02] border border-white/[0.04] p-3">
                        <div className="h-56 w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <RadarChart data={dimensoes} margin={{ top: 10, right: 30, bottom: 10, left: 30 }}>
                                    <PolarGrid stroke="rgba(255,255,255,0.08)" />
                                    <PolarAngleAxis
                                        dataKey="dim"
                                        tick={{ fill: 'rgba(255,255,255,0.55)', fontSize: 10 }}
                                    />
                                    <PolarRadiusAxis
                                        domain={[0, 100]}
                                        tick={false}
                                        axisLine={false}
                                    />
                                    <Radar
                                        name="Pontuação"
                                        dataKey="valor"
                                        stroke={cor}
                                        fill={cor}
                                        fillOpacity={0.35}
                                        dot={{ r: 3, fill: cor }}
                                    />
                                    <Tooltip
                                        cursor={false}
                                        contentStyle={{
                                            backgroundColor: 'rgba(15,16,22,0.95)',
                                            border: '1px solid rgba(255,255,255,0.12)',
                                            borderRadius: 8,
                                            fontSize: 12,
                                        }}
                                        formatter={(value, name, props) => {
                                            const bruto = props?.payload?.bruto;
                                            const suf   = props?.payload?.sufixo;
                                            if (bruto === null || bruto === undefined) return ['sem dado', 'Valor'];
                                            return [`${typeof bruto === 'number' ? bruto.toFixed(1) : bruto}${suf} (${Math.round(value)} pts)`, 'Valor'];
                                        }}
                                        labelStyle={{ color: 'rgba(255,255,255,0.9)' }}
                                    />
                                </RadarChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>

                {/* Cards detalhados embaixo */}
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mt-3">
                    <MiniMetric
                        label="Cresc. ajustado"
                        value={m.crescimento_ajustado_pct !== null
                            ? `${m.crescimento_ajustado_pct >= 0 ? '+' : ''}${m.crescimento_ajustado_pct.toFixed(1)}%`
                            : '—'}
                        sub="30d vs 30d-prev"
                        help={METRIC_HELP.crescimento_ajustado}
                    />
                    <MiniMetric
                        label="Crescendo"
                        value={`${m.empresas_em_crescimento.count}/${m.empresas_em_crescimento.total}`}
                        sub={`${m.empresas_em_crescimento.pct.toFixed(0)}% da carteira`}
                        help={METRIC_HELP.empresas_crescendo}
                    />
                    <MiniMetric
                        label="Meta"
                        value={m.atingimento_meta.pct !== null ? `${m.atingimento_meta.pct.toFixed(0)}%` : '—'}
                        sub={m.atingimento_meta.pct !== null
                            ? `${formatCurrencyCompact(m.atingimento_meta.realized_value)} / ${formatCurrencyCompact(m.atingimento_meta.target_value)}${m.atingimento_meta.origem?.startsWith('empresas') ? ' (metas individuais)' : ''}`
                            : 'sem meta'}
                        help={METRIC_HELP.meta}
                    />
                    <MiniMetric
                        label="Recuperação"
                        value={m.recuperacao.pct !== null ? `${m.recuperacao.recuperadas}/${m.recuperacao.em_queda}` : '—'}
                        sub={m.recuperacao.pct !== null ? `${m.recuperacao.pct.toFixed(0)}% recuperadas` : 'nenhuma em queda'}
                        help={METRIC_HELP.recuperacao}
                    />
                    <MiniMetric
                        label="NPS"
                        value={m.qualidade.avg_nps !== null ? `${m.qualidade.avg_nps.toFixed(1)}/5` : '—'}
                        sub={`${m.qualidade.meetings} reun. · ${m.qualidade.absenteismo_pct ?? 0}% abs.`}
                        help={METRIC_HELP.qualidade}
                    />
                </div>

                {!data.tem_base_comparativa && (
                    <p className="mt-3 text-white/40 text-[11px]">
                        <AlertTriangle size={10} className="inline mr-1" />
                        Carteira com menos de 5 empresas elegíveis — score calculado mas comparação com pares pode ser instável.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

// Clamp + normalize (ex: crescimento -20% → 0pts, 0% → 50, +20% → 100)
function clamp01to100(value, min, max) {
    if (value === null || value === undefined) return 0;
    const v = Math.max(min, Math.min(max, value));
    return ((v - min) / (max - min)) * 100;
}

function MiniMetric({ label, value, sub, help }) {
    return (
        <div className="rounded-lg bg-white/[0.04] border border-white/[0.06] px-3 py-2 group relative" title={help}>
            <div className="text-white/45 text-[10px] uppercase tracking-wide flex items-center gap-1">
                {label}
                {help && <span className="text-white/30 text-[9px] cursor-help">ⓘ</span>}
            </div>
            <div className="text-white/90 text-base font-bold tabular-nums leading-tight mt-0.5">{value}</div>
            <div className="text-white/35 text-[10px] mt-0.5">{sub}</div>
        </div>
    );
}

// ── Card de métrica de performance (legado — mantido pra fallback) ───────────

function PerformanceCard({ label, value, sub, tone = 'neutral' }) {
    const toneCls = {
        good:    'text-emerald-300',
        neutral: 'text-white/85',
        bad:     'text-red-300',
    }[tone];
    return (
        <div className="rounded-lg bg-white/[0.04] border border-white/[0.06] p-3">
            <div className="text-white/50 text-[10px] uppercase tracking-wide">{label}</div>
            <div className={cn('mt-1 text-xl font-bold tabular-nums leading-tight', toneCls)}>{value}</div>
            <div className="text-white/40 text-[10px] mt-0.5">{sub}</div>
        </div>
    );
}

// ── Linha de comparação (card lateral) ───────────────────────────────────────

function ComparacaoLinha({ label, status, valor, media }) {
    const cfg = {
        acima:    { cls: 'text-emerald-300', Icon: TrendingUp,   txt: 'acima' },
        na_media: { cls: 'text-white/60',    Icon: Minus,        txt: 'média' },
        abaixo:   { cls: 'text-red-300',     Icon: TrendingDown, txt: 'abaixo' },
    }[status] ?? { cls: 'text-white/60', Icon: Minus, txt: '—' };
    const { Icon } = cfg;
    return (
        <div className="flex items-center justify-between gap-2 text-[12px]" title={`Equipe: ${media}`}>
            <span className="text-white/70 truncate">{label}</span>
            <span className="flex items-center gap-1.5 shrink-0">
                <span className="text-white/85 tabular-nums">{valor}</span>
                <span className={cn('inline-flex items-center gap-0.5 text-[10px] font-semibold', cfg.cls)}>
                    <Icon size={11} /> {cfg.txt}
                </span>
            </span>
        </div>
    );
}

// ── Tooltip custom do gráfico ────────────────────────────────────────────────

function ChartTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const ponto = payload[0]?.payload ?? {};
    const realizado     = ponto.realizado;
    const metaAcumulada = ponto.meta_acumulada;
    const projecao      = ponto.projecao;
    const saldo = (realizado !== null && metaAcumulada !== null)
        ? realizado - metaAcumulada
        : null;
    // Date label: YYYY-MM-DD -> DD/MM
    const dateLabel = label ? label.slice(8, 10) + '/' + label.slice(5, 7) : '';
    return (
        <div className="rounded-lg border border-white/15 bg-ecf-bg/95 backdrop-blur px-3 py-2 text-[12px] shadow-xl">
            <div className="text-white/90 font-semibold mb-1">{dateLabel}</div>
            {realizado !== null && (
                <div className="flex items-center justify-between gap-4">
                    <span className="text-emerald-300">Realizado</span>
                    <span className="text-white/90 tabular-nums">{formatCurrencyCompact(realizado)}</span>
                </div>
            )}
            {metaAcumulada !== null && (
                <div className="flex items-center justify-between gap-4">
                    <span className="text-ecf-yellow">Meta</span>
                    <span className="text-white/90 tabular-nums">{formatCurrencyCompact(metaAcumulada)}</span>
                </div>
            )}
            {projecao !== null && projecao !== realizado && (
                <div className="flex items-center justify-between gap-4">
                    <span className="text-white/50">Projeção</span>
                    <span className="text-white/80 tabular-nums">{formatCurrencyCompact(projecao)}</span>
                </div>
            )}
            {saldo !== null && (
                <div className="mt-1 pt-1 border-t border-white/10 flex items-center justify-between gap-4">
                    <span className="text-white/60">Saldo</span>
                    <span className={cn('tabular-nums', saldo >= 0 ? 'text-emerald-300' : 'text-red-300')}>
                        {saldo >= 0 ? '+' : ''}{formatCurrencyCompact(saldo)}
                    </span>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────

export default function PortfolioShow({
    portfolio_user,
    companies,
    goals,
    summary,
    period,
    available_periods,
    has_metric_data = true,
    alertas = { grants_expirando_30d: [], empresas_em_queda: [], empresas_sem_ad_spend: [], top_3_revenue: [] },
    revenue_timeseries = [],
    // meta_carteira removida (Plan 48-01) — usar meta_carteira_calculada baseada em Goals individuais
    meta_carteira_calculada = { target_value: null, realized_value: 0, achieved_pct: null, restante: null, has_goal: false },
    periodo_amostra = { from_label: '—', to_label: '—', mes_label: '—', is_mes_atual: true },
    prioridade_do_dia = 0,
    prioridade_lista = [],
    performance_profissional = null,
    comparacao_contextual = null,
    // Props diferenciados por cargo (Plan 48-01)
    cargo_slug = null,
    sugador_counters = null,
    ppa_counters = null,
}) {
    const isAdmin = portfolio_user.role === 'admin'
        || (typeof window !== 'undefined' && window.location.pathname.includes('/admin/'));

    // ── Estado local ──
    const [busca, setBusca] = useState('');
    const [sortCol, setSortCol] = useState('revenue');
    const [sortDir, setSortDir] = useState('desc');
    const [prioridadeOpen, setPrioridadeOpen] = useState(false);

    // ── Período ──
    const applyPeriod = (p) => {
        const base = portfolio_user.id && isAdmin && typeof window !== 'undefined' && window.location.pathname.includes('/admin/')
            ? route('portfolio.show', portfolio_user.id)
            : route('portfolio.own');
        router.get(base, { period: p }, { preserveState: true });
    };

    // ── Empresas filtradas + ordenadas ──
    const empresasView = useMemo(() => {
        const term = busca.trim().toLowerCase();
        let arr = (companies || []).filter(c => {
            if (term && !c.name.toLowerCase().includes(term)) return false;
            return true;
        });
        arr = [...arr].sort((a, b) => {
            const av = a[sortCol] ?? 0;
            const bv = b[sortCol] ?? 0;
            if (typeof av === 'string') return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            return sortDir === 'asc' ? av - bv : bv - av;
        });
        return arr;
    }, [companies, busca, sortCol, sortDir]);

    const toggleSort = (col) => {
        if (sortCol === col) setSortDir(d => d === 'asc' ? 'desc' : 'asc');
        else { setSortCol(col); setSortDir('desc'); }
    };

    // ── Helpers ──
    const cargoLabel = ROLE_LABEL[portfolio_user.role] ?? portfolio_user.role;
    const growthPct  = summary?.revenue_growth_pct;
    const growthTone = growthPct === null || growthPct === undefined
        ? 'neutral'
        : growthPct >= 0 ? 'positive' : 'negative';
    const adCoveragePct = summary?.total_companies > 0
        ? Math.round(((companies || []).filter(c => (c.ad_spend ?? 0) > 0).length / summary.total_companies) * 100)
        : 0;

    return (
        <AppLayout title={`Carteira — ${portfolio_user.name}`}>
            <main className="p-4 md:p-6 max-w-[1400px] mx-auto space-y-6">

                {/* ── 1. Topo ───────────────────────────────────────────────── */}
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div className="flex items-center gap-3">
                        {/* Hotfix 2026-06-19 — voltar dava 404 quando admin estava
                            em /admin/users/{id}/portfolio (rota /admin nao existe).
                            Agora: admin volta pra listagem Carteiras (/portfolio,
                            que pra admin renderiza Carteiras.jsx consolidado);
                            profissional volta pra /dashboard. */}
                        <Link
                            href={isAdmin && typeof window !== 'undefined' && window.location.pathname.includes('/admin/')
                                ? route('portfolio.own')
                                : '/dashboard'}
                            className="inline-flex items-center justify-center h-9 w-9 rounded-lg border border-white/10 text-white/70 hover:bg-white/[0.05]"
                            title="Voltar"
                        >
                            <ArrowLeft size={16} />
                        </Link>
                        <div>
                            <h1 className="text-white text-xl md:text-2xl font-semibold leading-tight">
                                Carteira — {portfolio_user.name}
                            </h1>
                            <div className="text-white/40 text-[12px] mt-0.5">
                                Período vigente: {periodo_amostra.from_label} a {periodo_amostra.to_label} · {periodo_amostra.mes_label}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Select value={period} onValueChange={applyPeriod}>
                            <SelectTrigger className="w-48 h-9">
                                <SelectValue placeholder="Período" />
                            </SelectTrigger>
                            <SelectContent>
                                {(available_periods || []).map(p => (
                                    <SelectItem key={p} value={p}>
                                        {new Date(p + '-01').toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {/* Botão de criar meta de carteira removido (Plan 48-01/48-02 — metas são por empresa, não por carteira) */}
                    </div>
                </div>

                {/* ── 2. Card principal de faturamento ──────────────────────── */}
                <Card className="relative overflow-hidden border-white/[0.08]">
                    <div
                        className="absolute inset-0 opacity-90"
                        style={{
                            background: 'radial-gradient(ellipse at top left, rgba(255,230,0,0.18), transparent 55%), linear-gradient(135deg, #1a1c24 0%, #0a0b10 100%)',
                        }}
                    />
                    <CardContent className="relative p-6 md:p-8">
                        <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                            {/* Pessoa */}
                            <div className="flex items-center gap-3">
                                <div className="h-12 w-12 rounded-xl bg-white/10 border border-white/15 flex items-center justify-center">
                                    <Building2 className="h-6 w-6 text-ecf-yellow" />
                                </div>
                                <div>
                                    <div className="text-white/60 text-[12px] uppercase tracking-wide">Pessoa</div>
                                    <div className="text-white text-base font-semibold">{portfolio_user.name}</div>
                                    <div className="text-white/50 text-[12px]">{cargoLabel} — {summary?.total_companies ?? 0} empresas</div>
                                </div>
                            </div>

                            {/* Faturamento em destaque */}
                            <div className="text-right md:text-right">
                                <div className="text-white/60 text-[12px] uppercase tracking-wide cursor-help" title={METRIC_HELP.faturamento_total}>Faturamento ⓘ</div>
                                <div className="text-white text-4xl md:text-5xl font-bold tabular-nums leading-tight mt-1">
                                    {formatCurrencyCompact(summary?.total_revenue ?? 0)}
                                </div>
                                <div className="flex flex-wrap justify-start md:justify-end gap-1.5 mt-2">
                                    {growthPct !== null && growthPct !== undefined && (
                                        <Chip tone={growthTone}>
                                            {growthPct >= 0 ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
                                            {growthPct >= 0 ? '+' : ''}{growthPct.toFixed(1)}% vs anterior
                                        </Chip>
                                    )}
                                    {/* Chip de meta baseado nas metas individuais das empresas (meta_carteira_calculada) */}
                                    {meta_carteira_calculada.has_goal && meta_carteira_calculada.achieved_pct !== null && (
                                        <Chip tone="meta">
                                            <Target size={11} /> Meta empresas {meta_carteira_calculada.achieved_pct.toFixed(0)}%
                                        </Chip>
                                    )}
                                    {performance_profissional && (
                                        <Chip tone={
                                            performance_profissional.classificacao === 'excelente' ? 'positive'
                                            : performance_profissional.classificacao === 'critico' ? 'negative'
                                            : 'neutral'
                                        }>
                                            <Award size={11} /> Score {performance_profissional.score} · {CLASSIF_LABEL[performance_profissional.classificacao]}
                                            {comparacao_contextual && (
                                                <span className="opacity-70">· {comparacao_contextual.classificacao_top}</span>
                                            )}
                                        </Chip>
                                    )}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* ── 3. KPIs secundários (4 acionáveis — sem TACOS médio) ───── */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <KpiCard
                        label="Empresas"
                        value={summary?.total_companies ?? 0}
                        sub="na carteira"
                        icon={Building2}
                        help={METRIC_HELP.empresas_kpi}
                    />
                    {/* Bloco diferenciado por cargo:
                        - analista → Sugadores (pendentes / resolvidos)
                        - estrategista → PPAs (em andamento / concluídos no mês)
                        - fallback (admin ou sem cargo) → Score do profissional */}
                    {cargo_slug === 'analista' && sugador_counters !== null ? (
                        <KpiCard
                            label="Sugadores"
                            value={sugador_counters.pendentes}
                            sub={`pendentes · ${sugador_counters.resolvidos} resolvidos`}
                            icon={AlertTriangle}
                            accent={sugador_counters.pendentes > 0 ? 'text-red-300' : 'text-emerald-300'}
                        />
                    ) : cargo_slug === 'estrategista' && ppa_counters !== null ? (
                        <KpiCard
                            label="PPAs"
                            value={ppa_counters.em_andamento}
                            sub={`em andamento · ${ppa_counters.concluidos_mes} concluídos este mês`}
                            icon={Briefcase}
                            accent="text-sky-300"
                        />
                    ) : (
                        <KpiCard
                            label="Score"
                            value={performance_profissional?.score ?? '—'}
                            sub={performance_profissional?.classificacao
                                ? (CLASSIF_LABEL[performance_profissional.classificacao] ?? performance_profissional.classificacao)
                                : 'sem dados'}
                            icon={Award}
                            accent={performance_profissional?.classificacao
                                ? CLASSIF_CLS[performance_profissional.classificacao]
                                : 'text-white/85'}
                        />
                    )}
                    <KpiCard
                        label="Prioridade do dia"
                        value={prioridade_do_dia}
                        sub={prioridade_do_dia === 0
                            ? 'tudo em ordem'
                            : (prioridadeOpen ? 'clique pra recolher' : 'clique pra ver lista')}
                        icon={AlertTriangle}
                        accent={prioridade_do_dia === 0 ? 'text-emerald-300' : 'text-amber-300'}
                        help={METRIC_HELP.prioridade_do_dia}
                        onClick={prioridade_do_dia > 0 ? () => setPrioridadeOpen(o => !o) : undefined}
                    >
                        {prioridadeOpen && prioridade_lista.length > 0 && (
                            <div className="mt-2 pt-2 border-t border-white/[0.06] space-y-1 max-h-40 overflow-y-auto">
                                {prioridade_lista.map(p => (
                                    <Link
                                        key={p.id}
                                        href={route('companies.show', p.id)}
                                        onClick={(e) => e.stopPropagation()}
                                        className="block text-[11px] hover:text-ecf-yellow"
                                    >
                                        <span className="text-white/80 truncate inline-block max-w-[120px] align-middle">{p.name}</span>
                                        <span className="text-amber-300/70 ml-1 text-[10px]">{(p.motivos || []).join(' · ')}</span>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </KpiCard>
                    <KpiCard
                        label="Investimento Ads"
                        value={formatCurrencyCompact(summary?.total_ad_spend ?? 0)}
                        sub={`${adCoveragePct}% das empresas`}
                        icon={ShoppingCart}
                        help={METRIC_HELP.investimento_ads}
                    />
                </div>

                {/* ── 3.5 Performance do profissional (redesign visual 2026-06-23)
                    Radial chart (score 0-100) + Radar (6 dimensões) + cards menores. */}
                {performance_profissional && (
                    <PerformanceSection
                        data={performance_profissional}
                        comparacao={comparacao_contextual}
                    />
                )}

                {/* ── 4. Gráfico de evolução ─────────────────────────────────── */}
                {revenue_timeseries.length > 0 && (
                    <Card className="bg-ecf-card/60 border-white/[0.06]">
                        <CardContent className="p-4 md:p-5">
                            <div className="flex items-center justify-between mb-3">
                                <div>
                                    <div className="text-white/90 text-sm font-semibold">Evolução do faturamento</div>
                                    <div className="text-white/40 text-[11px]">Realizado acumulado vs Meta linear · projeção em pontilhado</div>
                                </div>
                            </div>
                            <div className="h-64 md:h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={revenue_timeseries} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
                                        <XAxis
                                            dataKey="date"
                                            tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 10 }}
                                            tickFormatter={(d) => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : ''}
                                            stroke="rgba(255,255,255,0.1)"
                                        />
                                        <YAxis
                                            tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 10 }}
                                            tickFormatter={(v) => formatCurrencyCompact(v)}
                                            stroke="rgba(255,255,255,0.1)"
                                        />
                                        <Tooltip content={<ChartTooltip />} />
                                        <Legend
                                            wrapperStyle={{ paddingTop: 8 }}
                                            formatter={(value) => <span className="text-white/70 text-[12px]">{value}</span>}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="realizado"
                                            name="Realizado"
                                            stroke="#10b981"
                                            strokeWidth={2.5}
                                            dot={false}
                                            activeDot={{ r: 5 }}
                                            connectNulls={false}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="meta_acumulada"
                                            name="Meta"
                                            stroke="#ffe600"
                                            strokeWidth={2}
                                            strokeDasharray="4 4"
                                            dot={false}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="projecao"
                                            name="Projeção"
                                            stroke="rgba(255,255,255,0.4)"
                                            strokeWidth={1.5}
                                            strokeDasharray="2 4"
                                            dot={false}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ── 5+6. Tabela + painel lateral ────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
                    {/* Tabela de empresas */}
                    <Card className="bg-ecf-card/60 border-white/[0.06]">
                        <CardContent className="p-4 md:p-5">
                            {/* Barra de filtros (hotfix 2026-06-19 — filtro de Status
                                removido junto com as colunas Status e Acao por pedido
                                do usuario; busca + contador preservados). */}
                            <div className="flex flex-col md:flex-row md:items-center gap-2 mb-3">
                                <div className="flex-1 relative">
                                    <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/40" />
                                    <Input
                                        value={busca}
                                        onChange={e => setBusca(e.target.value)}
                                        placeholder="Buscar empresa…"
                                        className="pl-8 h-9 text-[13px]"
                                    />
                                </div>
                                <div className="text-white/40 text-[11px] tabular-nums whitespace-nowrap">
                                    {empresasView.length} de {companies?.length ?? 0}
                                </div>
                            </div>

                            {/* ── Cards mobile (< md) — empilhados, sem overflow horizontal ──────── */}
                            <div className="md:hidden space-y-2">
                                {empresasView.length === 0 && (
                                    <p className="text-center text-white/40 py-8">
                                        Nenhuma empresa encontrada com os filtros.
                                    </p>
                                )}
                                {empresasView.map(c => {
                                    const grantInativo = c.grant_status !== 'active';
                                    const grantVencendo = c.grant_status === 'active'
                                        && c.grant_days_remaining !== null
                                        && c.grant_days_remaining !== undefined
                                        && c.grant_days_remaining <= 15;
                                    return (
                                        <div
                                            key={`m-${c.id}`}
                                            className="p-3 border border-white/[0.06] rounded-lg bg-white/[0.01]"
                                        >
                                            {/* Linha 1: nome + status + crescimento */}
                                            <div className="flex items-center gap-2 flex-wrap mb-1.5">
                                                <Link
                                                    href={route('companies.show', c.id)}
                                                    className="text-white/90 hover:text-ecf-yellow text-[13px] font-medium flex-1 min-w-0 truncate"
                                                >
                                                    {c.name}
                                                </Link>
                                                {c.status && (
                                                    <span className={cn(
                                                        'inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded border shrink-0',
                                                        STATUS_CLS[c.status] ?? 'bg-white/10 text-white/60 border-white/20'
                                                    )}>
                                                        {STATUS_LABEL[c.status] ?? c.status}
                                                    </span>
                                                )}
                                                <div className="shrink-0">
                                                    <SparklineCrescimento queda_mom_pct={c.queda_mom_pct} />
                                                </div>
                                                {grantInativo && (
                                                    <span className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded border bg-red-500/15 text-red-300 border-red-500/25 shrink-0">
                                                        <AlertTriangle size={9} /> Grant vencido
                                                    </span>
                                                )}
                                                {!grantInativo && grantVencendo && (
                                                    <span className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded border bg-amber-500/15 text-amber-300 border-amber-500/25 shrink-0">
                                                        <AlertTriangle size={9} /> Grant {c.grant_days_remaining}d
                                                    </span>
                                                )}
                                            </div>
                                            {/* Linha 2: faturamento + meta */}
                                            <div className="flex items-center gap-3 text-[12px] mb-1">
                                                <span className="text-white/90 font-semibold tabular-nums">
                                                    {c.revenue !== null ? formatCurrencyCompact(c.revenue) : '—'}
                                                </span>
                                                {c.meta_achieved_pct !== null && (
                                                    <span className="text-white/50 tabular-nums">
                                                        Meta {c.meta_achieved_pct.toFixed(0)}%
                                                    </span>
                                                )}
                                            </div>
                                            {/* Linha 3: margem + ads */}
                                            <div className="flex items-center gap-3 text-[11px] text-white/50 tabular-nums mb-1">
                                                {c.contribution_margin_pct !== null && c.contribution_margin_pct !== undefined && (
                                                    <span>Margem {formatPercent(c.contribution_margin_pct)}</span>
                                                )}
                                                <span>Ads {(c.ad_spend ?? 0) > 0 ? formatCurrencyCompact(c.ad_spend) : 'R$ 0,00'}</span>
                                            </div>
                                            {/* Linha 4: ação recomendada (texto completo) */}
                                            {c.acao_recomendada && (
                                                <p className="text-[11px] text-white/50 leading-snug mt-1">
                                                    {c.acao_recomendada}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            {/* ── Tabela desktop (≥ md) — 8 colunas com Status + Ação + Crescimento ── */}
                            <div className="hidden md:block overflow-x-auto -mx-1">
                                <table className="w-full text-[13px]">
                                    <thead>
                                        <tr className="text-white/50 text-[11px] uppercase tracking-wide">
                                            <th className="text-left font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('name')}>Empresa</th>
                                            <th className="text-left font-medium px-2 py-2">Status</th>
                                            <th className="text-right font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('revenue')}>Faturamento</th>
                                            <th className="text-right font-medium px-2 py-2">Meta</th>
                                            <th className="text-right font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('contribution_margin_pct')}>Margem</th>
                                            <th className="text-right font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('ad_spend')}>Ads</th>
                                            <th className="text-left font-medium px-2 py-2">Ação</th>
                                            <th className="text-right font-medium px-2 py-2">Crescimento</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {empresasView.map(c => {
                                            const grantInativo = c.grant_status !== 'active';
                                            const grantVencendo = c.grant_status === 'active'
                                                && c.grant_days_remaining !== null
                                                && c.grant_days_remaining !== undefined
                                                && c.grant_days_remaining <= 15;
                                            return (
                                            <tr key={c.id} className="border-t border-white/[0.06] hover:bg-white/[0.02]">
                                                <td className="px-2 py-2">
                                                    <div className="flex items-center gap-2">
                                                        <Link
                                                            href={route('companies.show', c.id)}
                                                            className="text-white/90 hover:text-ecf-yellow truncate inline-block max-w-[160px] md:max-w-none"
                                                        >
                                                            {c.name}
                                                        </Link>
                                                        {grantInativo && (
                                                            <span
                                                                title="Grant vencido ou inativo"
                                                                className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded border bg-red-500/15 text-red-300 border-red-500/25 shrink-0"
                                                            >
                                                                <AlertTriangle size={9} /> Grant vencido
                                                            </span>
                                                        )}
                                                        {!grantInativo && grantVencendo && (
                                                            <span
                                                                title={`Grant expira em ${c.grant_days_remaining} dia(s)`}
                                                                className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded border bg-amber-500/15 text-amber-300 border-amber-500/25 shrink-0"
                                                            >
                                                                <AlertTriangle size={9} /> Grant {c.grant_days_remaining}d
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                {/* Status — reintroduzido (removido em hotfix 2026-06-19; dado já existia no payload) */}
                                                <td className="px-2 py-2">
                                                    {c.status ? (
                                                        <span className={cn(
                                                            'inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded border',
                                                            STATUS_CLS[c.status] ?? 'bg-white/10 text-white/60 border-white/20'
                                                        )}>
                                                            {STATUS_LABEL[c.status] ?? c.status}
                                                        </span>
                                                    ) : (
                                                        <span className="text-white/30 text-[11px]">—</span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2 text-right text-white/90 tabular-nums">
                                                    {c.revenue !== null ? formatCurrencyCompact(c.revenue) : '—'}
                                                </td>
                                                <td className="px-2 py-2 text-right text-white/70 tabular-nums">
                                                    {c.meta_achieved_pct !== null ? `${c.meta_achieved_pct.toFixed(0)}%` : '—'}
                                                </td>
                                                <td className="px-2 py-2 text-right text-white/70 tabular-nums">
                                                    {c.contribution_margin_pct !== null && c.contribution_margin_pct !== undefined
                                                        ? formatPercent(c.contribution_margin_pct)
                                                        : '—'}
                                                </td>
                                                <td className="px-2 py-2 text-right text-white/70 tabular-nums">
                                                    {(c.ad_spend ?? 0) > 0 ? formatCurrencyCompact(c.ad_spend) : 'R$ 0,00'}
                                                </td>
                                                {/* Ação recomendada — reintroduzida (removida em hotfix 2026-06-19; dado já existia no payload) */}
                                                <td className="px-2 py-2 max-w-[120px]">
                                                    {c.acao_recomendada ? (
                                                        <span
                                                            title={c.acao_recomendada}
                                                            className="block truncate text-[11px] text-white/60"
                                                        >
                                                            {c.acao_recomendada}
                                                        </span>
                                                    ) : (
                                                        <span className="text-white/30 text-[11px]">—</span>
                                                    )}
                                                </td>
                                                {/* Crescimento MoM — badge colorido SparklineCrescimento */}
                                                <td className="px-2 py-2 text-right">
                                                    <SparklineCrescimento queda_mom_pct={c.queda_mom_pct} />
                                                </td>
                                            </tr>
                                            );
                                        })}
                                        {empresasView.length === 0 && (
                                            <tr>
                                                <td colSpan={8} className="text-center text-white/40 py-8">
                                                    Nenhuma empresa encontrada com os filtros.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Painel lateral estratégico */}
                    <div className="space-y-3">
                        {/* Top 3 */}
                        <Card className="bg-ecf-card/60 border-white/[0.06]">
                            <CardContent className="p-4">
                                <div className="flex items-center gap-2 text-white/90 text-sm font-semibold mb-3">
                                    <Trophy size={14} className="text-ecf-yellow" /> Top faturamento
                                </div>
                                {alertas.top_3_revenue.length === 0 && (
                                    <p className="text-white/40 text-[12px]">Sem dados no período.</p>
                                )}
                                <ol className="space-y-1.5">
                                    {alertas.top_3_revenue.map((c, i) => (
                                        <li key={c.id} className="flex items-center gap-2">
                                            <span className="w-5 h-5 rounded-full bg-ecf-yellow/15 text-ecf-yellow text-[10px] font-bold flex items-center justify-center">
                                                {i + 1}
                                            </span>
                                            <Link href={route('companies.show', c.id)} className="flex-1 text-white/85 text-[12px] hover:text-ecf-yellow truncate">
                                                {c.name}
                                            </Link>
                                            <span className="text-white/60 text-[11px] tabular-nums">{formatCurrencyCompact(c.revenue)}</span>
                                        </li>
                                    ))}
                                </ol>
                            </CardContent>
                        </Card>

                        {/* Comparação contextual com pares (quick 260623 — justo,
                            baseado em crescimento/execução/meta, NÃO em faturamento bruto). */}
                        {comparacao_contextual && performance_profissional && (
                            <Card className="bg-ecf-card/60 border-white/[0.06]">
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between mb-3">
                                        <div className="flex items-center gap-2 text-white/90 text-sm font-semibold">
                                            <Users size={14} className="text-sky-300" /> Comparação
                                        </div>
                                        <span className="text-white/40 text-[10px]">
                                            vs {comparacao_contextual.tamanho_amostra} {comparacao_contextual.cargo_label.toLowerCase()}s
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        <ComparacaoLinha
                                            label="Score"
                                            status={comparacao_contextual.relativo.score}
                                            valor={`${performance_profissional.score} pts`}
                                            media={`${comparacao_contextual.medianas.score?.toFixed(1) ?? '—'} pts`}
                                        />
                                        <ComparacaoLinha
                                            label="Crescimento ajustado"
                                            status={comparacao_contextual.relativo.crescimento_ajustado_pct}
                                            valor={performance_profissional.metricas.crescimento_ajustado_pct !== null
                                                ? `${performance_profissional.metricas.crescimento_ajustado_pct >= 0 ? '+' : ''}${performance_profissional.metricas.crescimento_ajustado_pct.toFixed(1)}%`
                                                : '—'}
                                            media={comparacao_contextual.medianas.crescimento_ajustado_pct !== null
                                                ? `${comparacao_contextual.medianas.crescimento_ajustado_pct.toFixed(1)}%`
                                                : '—'}
                                        />
                                        <ComparacaoLinha
                                            label="Empresas crescendo"
                                            status={comparacao_contextual.relativo.empresas_em_crescimento_pct}
                                            valor={`${performance_profissional.metricas.empresas_em_crescimento.pct.toFixed(0)}%`}
                                            media={comparacao_contextual.medianas.empresas_em_crescimento_pct !== null
                                                ? `${comparacao_contextual.medianas.empresas_em_crescimento_pct.toFixed(0)}%`
                                                : '—'}
                                        />
                                        <ComparacaoLinha
                                            label="Atingimento meta"
                                            status={comparacao_contextual.relativo.atingimento_meta_pct}
                                            valor={performance_profissional.metricas.atingimento_meta.pct !== null
                                                ? `${performance_profissional.metricas.atingimento_meta.pct.toFixed(0)}%`
                                                : '—'}
                                            media={comparacao_contextual.medianas.atingimento_meta_pct !== null
                                                ? `${comparacao_contextual.medianas.atingimento_meta_pct.toFixed(0)}%`
                                                : '—'}
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Alertas estratégicos */}
                        <Card className="bg-ecf-card/60 border-white/[0.06]">
                            <CardContent className="p-4">
                                <div className="flex items-center gap-2 text-white/90 text-sm font-semibold mb-3">
                                    <AlertTriangle size={14} className="text-amber-300" /> Ações estratégicas
                                </div>
                                <div className="space-y-2 text-[12px]">
                                    {alertas.grants_expirando_30d.length > 0 && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-white/70">Grants expirando ≤30d</span>
                                            <span className="text-amber-300 font-semibold tabular-nums">{alertas.grants_expirando_30d.length}</span>
                                        </div>
                                    )}
                                    {alertas.empresas_em_queda.length > 0 && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-white/70">Empresas em queda</span>
                                            <span className="text-red-300 font-semibold tabular-nums">{alertas.empresas_em_queda.length}</span>
                                        </div>
                                    )}
                                    {alertas.grants_expirando_30d.length === 0 &&
                                     alertas.empresas_em_queda.length === 0 && (
                                        <p className="text-emerald-300/70 text-[12px]">Nenhuma pendência crítica.</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Bloco "Metas (admin)" removido — portfolio_goals foi removido do payload (Plan 48-01/48-02) */}
                    </div>
                </div>
            </main>

        </AppLayout>
    );
}
