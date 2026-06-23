import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Link, router, useForm } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    ArrowLeft, Search, TrendingUp, TrendingDown, Target, AlertTriangle,
    Trophy, Briefcase, Building2, Plus, Pencil, Trash2, ShoppingCart, Award, Users, Minus,
} from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact, formatPercent } from '@/lib/utils';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis, Tooltip,
    CartesianGrid, Legend,
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

// ── KPI compacto ─────────────────────────────────────────────────────────────

function KpiCard({ label, value, sub, icon: Icon, accent = 'text-white/85' }) {
    return (
        <Card className="bg-ecf-card/60 border-white/[0.06]">
            <CardContent className="p-4">
                <div className="flex items-center gap-2 text-white/50 text-[11px] uppercase tracking-wide">
                    {Icon && <Icon size={12} />}
                    {label}
                </div>
                <div className={cn('mt-1.5 text-2xl font-bold tabular-nums', accent)}>{value}</div>
                {sub && <div className="text-white/40 text-[11px] mt-0.5">{sub}</div>}
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

// ── Card de métrica de performance ───────────────────────────────────────────

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
    portfolio_goals,
    goals,
    summary,
    period,
    available_periods,
    has_metric_data = true,
    portfolio_goal_metrics,
    alertas = { grants_expirando_30d: [], empresas_em_queda: [], empresas_sem_ad_spend: [], top_3_revenue: [] },
    revenue_timeseries = [],
    meta_carteira = { target_value: null, realized_value: 0, achieved_pct: null, restante: null, has_goal: false },
    periodo_amostra = { from_label: '—', to_label: '—', mes_label: '—', is_mes_atual: true },
    prioridade_do_dia = 0,
    performance_profissional = null,
    comparacao_contextual = null,
}) {
    const isAdmin = portfolio_user.role === 'admin'
        || (typeof window !== 'undefined' && window.location.pathname.includes('/admin/'));

    // ── Estado local ──
    const [busca, setBusca] = useState('');
    const [sortCol, setSortCol] = useState('revenue');
    const [sortDir, setSortDir] = useState('desc');

    // ── Forms admin (criar/editar/remover meta carteira) ──
    const [goalOpen, setGoalOpen] = useState(false);
    const [editGoalOpen, setEditGoalOpen] = useState(false);
    const [editingGoal, setEditingGoal] = useState(null);

    const goalForm = useForm({
        role: portfolio_user.role !== 'admin' ? portfolio_user.role : 'consultor',
        metric: 'revenue', target_value: '', value_type: 'currency',
        aggregation: 'sum', period_type: 'monthly', description: '',
    });
    const editGoalForm = useForm({
        target_value: '', value_type: 'currency',
        aggregation: 'sum', period_type: 'monthly', description: '',
    });

    const submitGoal = (e) => {
        e.preventDefault();
        goalForm.post(route('portfolio.goals.store', portfolio_user.id), {
            onSuccess: () => { goalForm.reset(); setGoalOpen(false); },
        });
    };
    const openEditGoal = (g) => {
        setEditingGoal(g);
        editGoalForm.setData({
            target_value: g.target_value, value_type: g.value_type,
            aggregation: g.aggregation, period_type: g.period_type,
            description: g.description || '',
        });
        setEditGoalOpen(true);
    };
    const submitEditGoal = (e) => {
        e.preventDefault();
        editGoalForm.put(route('portfolio.goals.update', editingGoal.id), {
            onSuccess: () => setEditGoalOpen(false),
        });
    };
    const removeGoal = (id) => {
        if (confirm('Remover esta meta de carteira?')) {
            router.delete(route('portfolio.goals.destroy', id));
        }
    };

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
                        {isAdmin && (
                            <Button size="sm" variant="outline" onClick={() => setGoalOpen(true)}>
                                <Plus size={14} className="mr-1" /> Meta
                            </Button>
                        )}
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
                                <div className="text-white/60 text-[12px] uppercase tracking-wide">Faturamento</div>
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
                                    {meta_carteira.has_goal && meta_carteira.achieved_pct !== null && (
                                        <Chip tone="meta">
                                            <Target size={11} /> Meta {meta_carteira.achieved_pct.toFixed(0)}%
                                        </Chip>
                                    )}
                                    {meta_carteira.has_goal && meta_carteira.restante !== null && meta_carteira.restante > 0 && (
                                        <Chip tone="neutral">
                                            {formatCurrencyCompact(meta_carteira.restante)} restante
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
                    />
                    <KpiCard
                        label="Meta da carteira"
                        value={meta_carteira.has_goal && meta_carteira.achieved_pct !== null
                            ? `${meta_carteira.achieved_pct.toFixed(0)}%`
                            : '—'}
                        sub={meta_carteira.has_goal
                            ? `${formatCurrencyCompact(meta_carteira.realized_value)} / ${formatCurrencyCompact(meta_carteira.target_value)}`
                            : 'sem meta cadastrada'}
                        icon={Target}
                        accent={meta_carteira.achieved_pct !== null && meta_carteira.achieved_pct >= 80
                            ? 'text-emerald-300'
                            : meta_carteira.achieved_pct !== null && meta_carteira.achieved_pct < 50
                                ? 'text-red-300'
                                : 'text-white/85'}
                    />
                    <KpiCard
                        label="Prioridade do dia"
                        value={prioridade_do_dia}
                        sub={prioridade_do_dia === 0 ? 'tudo em ordem' : 'exigem ação'}
                        icon={AlertTriangle}
                        accent={prioridade_do_dia === 0 ? 'text-emerald-300' : 'text-amber-300'}
                    />
                    <KpiCard
                        label="Investimento Ads"
                        value={formatCurrencyCompact(summary?.total_ad_spend ?? 0)}
                        sub={`${adCoveragePct}% das empresas`}
                        icon={ShoppingCart}
                    />
                </div>

                {/* ── 3.5 Performance do profissional ─────────────────────────────
                    Quick 260623 — 6 cards conforme metodologia-desempenho-carteira.md.
                    Mede evolução + execução, NÃO faturamento bruto. */}
                {performance_profissional && (
                    <Card className={cn('border', CLASSIF_BG[performance_profissional.classificacao] ?? 'border-white/[0.06]')}>
                        <CardContent className="p-4 md:p-5">
                            <div className="flex items-center justify-between mb-4">
                                <div className="flex items-center gap-2">
                                    <Award size={16} className={CLASSIF_CLS[performance_profissional.classificacao]} />
                                    <h3 className="text-white text-sm font-semibold">Performance do profissional</h3>
                                </div>
                                <div className="text-right">
                                    <div className={cn('text-2xl font-bold tabular-nums leading-none', CLASSIF_CLS[performance_profissional.classificacao])}>
                                        {performance_profissional.score} <span className="text-[10px] text-white/40 font-normal">pts</span>
                                    </div>
                                    <div className={cn('text-[11px] font-semibold', CLASSIF_CLS[performance_profissional.classificacao])}>
                                        {CLASSIF_LABEL[performance_profissional.classificacao]}
                                        {comparacao_contextual && ` · ${comparacao_contextual.classificacao_top} de ${comparacao_contextual.tamanho_amostra} ${comparacao_contextual.cargo_label.toLowerCase()}s`}
                                    </div>
                                </div>
                            </div>

                            {/* 6 cards */}
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                                <PerformanceCard
                                    label="Crescimento ajustado"
                                    value={performance_profissional.metricas.crescimento_ajustado_pct !== null
                                        ? `${performance_profissional.metricas.crescimento_ajustado_pct >= 0 ? '+' : ''}${performance_profissional.metricas.crescimento_ajustado_pct.toFixed(1)}%`
                                        : '—'}
                                    sub="30d vs 30d anteriores"
                                    tone={performance_profissional.metricas.crescimento_ajustado_pct !== null
                                        ? (performance_profissional.metricas.crescimento_ajustado_pct >= 5 ? 'good'
                                            : performance_profissional.metricas.crescimento_ajustado_pct >= -5 ? 'neutral' : 'bad')
                                        : 'neutral'}
                                />
                                <PerformanceCard
                                    label="Empresas em crescimento"
                                    value={`${performance_profissional.metricas.empresas_em_crescimento.count}/${performance_profissional.metricas.empresas_em_crescimento.total}`}
                                    sub={`${performance_profissional.metricas.empresas_em_crescimento.pct.toFixed(0)}% da carteira`}
                                    tone={performance_profissional.metricas.empresas_em_crescimento.pct >= 60 ? 'good'
                                        : performance_profissional.metricas.empresas_em_crescimento.pct >= 40 ? 'neutral' : 'bad'}
                                />
                                <PerformanceCard
                                    label="Atingimento de meta"
                                    value={performance_profissional.metricas.atingimento_meta.pct !== null
                                        ? `${performance_profissional.metricas.atingimento_meta.pct.toFixed(0)}%`
                                        : '—'}
                                    sub={performance_profissional.metricas.atingimento_meta.pct !== null
                                        ? `${formatCurrencyCompact(performance_profissional.metricas.atingimento_meta.realized_value)} / ${formatCurrencyCompact(performance_profissional.metricas.atingimento_meta.target_value)}`
                                        : 'sem meta cadastrada'}
                                    tone={performance_profissional.metricas.atingimento_meta.pct !== null
                                        ? (performance_profissional.metricas.atingimento_meta.pct >= 80 ? 'good'
                                            : performance_profissional.metricas.atingimento_meta.pct >= 50 ? 'neutral' : 'bad')
                                        : 'neutral'}
                                />
                                <PerformanceCard
                                    label="Empresas recuperadas"
                                    value={performance_profissional.metricas.recuperacao.pct !== null
                                        ? `${performance_profissional.metricas.recuperacao.recuperadas}/${performance_profissional.metricas.recuperacao.em_queda}`
                                        : '—'}
                                    sub={performance_profissional.metricas.recuperacao.pct !== null
                                        ? `${performance_profissional.metricas.recuperacao.pct.toFixed(0)}% recuperadas`
                                        : 'nenhuma estava em queda'}
                                    tone={performance_profissional.metricas.recuperacao.pct !== null
                                        ? (performance_profissional.metricas.recuperacao.pct >= 60 ? 'good'
                                            : performance_profissional.metricas.recuperacao.pct >= 30 ? 'neutral' : 'bad')
                                        : 'neutral'}
                                />
                                <PerformanceCard
                                    label="Ações executadas"
                                    value={performance_profissional.metricas.execucao_ads.pct !== null
                                        ? `${performance_profissional.metricas.execucao_ads.ativaram}/${performance_profissional.metricas.execucao_ads.oportunidades}`
                                        : '—'}
                                    sub={performance_profissional.metricas.execucao_ads.pct !== null
                                        ? `${performance_profissional.metricas.execucao_ads.pct.toFixed(0)}% Ads ativados`
                                        : 'nenhuma oportunidade'}
                                    tone={performance_profissional.metricas.execucao_ads.pct !== null
                                        ? (performance_profissional.metricas.execucao_ads.pct >= 60 ? 'good'
                                            : performance_profissional.metricas.execucao_ads.pct >= 30 ? 'neutral' : 'bad')
                                        : 'neutral'}
                                />
                                <PerformanceCard
                                    label="Qualidade (NPS)"
                                    value={performance_profissional.metricas.qualidade.avg_nps !== null
                                        ? `${performance_profissional.metricas.qualidade.avg_nps.toFixed(1)}/5`
                                        : '—'}
                                    sub={`${performance_profissional.metricas.qualidade.meetings} reuniões · ${performance_profissional.metricas.qualidade.absenteismo_pct ?? 0}% absent.`}
                                    tone={performance_profissional.metricas.qualidade.avg_nps !== null
                                        ? (performance_profissional.metricas.qualidade.avg_nps >= 4 ? 'good'
                                            : performance_profissional.metricas.qualidade.avg_nps >= 3 ? 'neutral' : 'bad')
                                        : 'neutral'}
                                />
                            </div>

                            {!performance_profissional.tem_base_comparativa && (
                                <p className="mt-3 text-white/40 text-[11px]">
                                    <AlertTriangle size={10} className="inline mr-1" />
                                    Carteira com menos de 5 empresas elegíveis — score calculado mas comparação com pares pode ser instável.
                                </p>
                            )}
                        </CardContent>
                    </Card>
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

                            {/* Tabela compacta — sem Status/Acao; grant vencido como badge inline na coluna Empresa */}
                            <div className="overflow-x-auto -mx-1">
                                <table className="w-full text-[13px]">
                                    <thead>
                                        <tr className="text-white/50 text-[11px] uppercase tracking-wide">
                                            <th className="text-left font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('name')}>Empresa</th>
                                            <th className="text-right font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('revenue')}>Faturamento</th>
                                            <th className="text-right font-medium px-2 py-2">Meta</th>
                                            <th className="text-right font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('contribution_margin_pct')}>Margem</th>
                                            <th className="text-right font-medium px-2 py-2 cursor-pointer" onClick={() => toggleSort('ad_spend')}>Ads</th>
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
                                                            className="text-white/90 hover:text-ecf-yellow truncate inline-block max-w-[200px] md:max-w-none"
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
                                            </tr>
                                            );
                                        })}
                                        {empresasView.length === 0 && (
                                            <tr>
                                                <td colSpan={5} className="text-center text-white/40 py-8">
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
                                        <ComparacaoLinha
                                            label="Execução Ads"
                                            status={comparacao_contextual.relativo.execucao_ads_pct}
                                            valor={performance_profissional.metricas.execucao_ads.pct !== null
                                                ? `${performance_profissional.metricas.execucao_ads.pct.toFixed(0)}%`
                                                : '—'}
                                            media={comparacao_contextual.medianas.execucao_ads_pct !== null
                                                ? `${comparacao_contextual.medianas.execucao_ads_pct.toFixed(0)}%`
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
                                    {alertas.empresas_sem_ad_spend.length > 0 && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-white/70">Sem Ads (oportunidade)</span>
                                            <span className="text-sky-300 font-semibold tabular-nums">{alertas.empresas_sem_ad_spend.length}</span>
                                        </div>
                                    )}
                                    {alertas.grants_expirando_30d.length === 0 &&
                                     alertas.empresas_em_queda.length === 0 &&
                                     alertas.empresas_sem_ad_spend.length === 0 && (
                                        <p className="text-emerald-300/70 text-[12px]">Nenhuma pendência crítica.</p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Metas (admin) */}
                        {isAdmin && portfolio_goals?.length > 0 && (
                            <Card className="bg-ecf-card/60 border-white/[0.06]">
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between mb-2">
                                        <div className="flex items-center gap-2 text-white/90 text-sm font-semibold">
                                            <Target size={14} className="text-ecf-yellow" /> Metas
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        {portfolio_goals.map(g => (
                                            <div key={g.id} className="rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2 text-[12px]">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-white/80 truncate">{g.metric_label}</span>
                                                    <div className="flex items-center gap-1">
                                                        <button onClick={() => openEditGoal(g)} className="p-1 text-white/40 hover:text-white" title="Editar">
                                                            <Pencil size={11} />
                                                        </button>
                                                        <button onClick={() => removeGoal(g.id)} className="p-1 text-white/40 hover:text-red-400" title="Remover">
                                                            <Trash2 size={11} />
                                                        </button>
                                                    </div>
                                                </div>
                                                <div className="text-white/50 text-[11px] mt-0.5">
                                                    {g.value_type === 'currency' ? formatCurrencyCompact(g.target_value) : `${g.target_value}%`}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </main>

            {/* Modal: criar meta (admin) */}
            <Dialog open={goalOpen} onOpenChange={setGoalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Nova meta de carteira</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitGoal} className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Métrica</Label>
                            <Select value={goalForm.data.metric} onValueChange={v => goalForm.setData('metric', v)}>
                                <SelectTrigger><SelectValue placeholder="Selecione…" /></SelectTrigger>
                                <SelectContent>
                                    {Object.entries(portfolio_goal_metrics || {}).map(([k, label]) => (
                                        <SelectItem key={k} value={k}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Valor alvo</Label>
                            <Input type="number" step="0.01" min="0" value={goalForm.data.target_value} onChange={e => goalForm.setData('target_value', e.target.value)} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Descrição (opcional)</Label>
                            <Textarea rows={2} value={goalForm.data.description} onChange={e => goalForm.setData('description', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setGoalOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={goalForm.processing}>Criar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal: editar meta (admin) */}
            <Dialog open={editGoalOpen} onOpenChange={setEditGoalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Editar meta</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEditGoal} className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Valor alvo</Label>
                            <Input type="number" step="0.01" min="0" value={editGoalForm.data.target_value} onChange={e => editGoalForm.setData('target_value', e.target.value)} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Descrição (opcional)</Label>
                            <Textarea rows={2} value={editGoalForm.data.description} onChange={e => editGoalForm.setData('description', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditGoalOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={editGoalForm.processing}>Salvar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
