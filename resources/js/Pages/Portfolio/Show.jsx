import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Briefcase, TrendingUp, DollarSign, Percent, Plus, Pencil, Trash2,
    CheckCircle2, XCircle, Building2, Target, ShieldCheck, ChevronDown
} from 'lucide-react';
import { cn, formatCurrency, formatPercent } from '@/lib/utils';

const roleLabel = { consultor: 'Analista', mentor: 'Estrategista' };
const periodLabel = { monthly: 'Mensal', quarterly: 'Trimestral', yearly: 'Anual' };
const aggregationLabel = { avg: 'Média', sum: 'Soma' };

const grantStatusColor = {
    active: 'success',
    pending: 'secondary',
    expired: 'destructive',
};

function MetricCard({ label, value, sub, color = 'text-white' }) {
    return (
        <div className="card-ecf rounded-2xl p-5">
            <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-2">{label}</p>
            <p className={cn('font-display font-extrabold text-2xl leading-none', color)}>{value ?? '—'}</p>
            {sub && <p className="text-white/30 text-[11px] mt-1.5">{sub}</p>}
        </div>
    );
}

function AchievedBadge({ achieved, realized, target, valueType }) {
    if (realized === null || realized === undefined) return <span className="text-white/20">—</span>;
    const fmt = (v) => valueType === 'currency' ? formatCurrency(v) : `${Number(v).toFixed(2)}%`;
    return (
        <div className="flex items-center gap-1.5">
            {achieved
                ? <CheckCircle2 size={14} className="text-emerald-400 shrink-0" />
                : <XCircle size={14} className="text-red-400 shrink-0" />
            }
            <span className={cn('text-[12px] font-semibold', achieved ? 'text-emerald-400' : 'text-red-400')}>
                {fmt(realized)}
            </span>
            <span className="text-white/25 text-[11px]">/ {fmt(target)}</span>
        </div>
    );
}

const formatPeriod = (p) => {
    const [y, m] = p.split('-');
    return new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' })
        .format(new Date(+y, +m - 1));
};

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
}) {
    const [goalOpen, setGoalOpen] = useState(false);
    const [editGoalOpen, setEditGoalOpen] = useState(false);
    const [editingGoal, setEditingGoal] = useState(null);
    const isAdmin = portfolio_user.role === 'admin' || window.location.pathname.includes('/admin/');

    const goalForm = useForm({
        role: portfolio_user.role !== 'admin' ? portfolio_user.role : 'consultor',
        metric: '', target_value: '', value_type: 'currency',
        aggregation: 'avg', period_type: 'monthly', description: '',
    });

    const editGoalForm = useForm({
        target_value: '', value_type: 'currency',
        aggregation: 'avg', period_type: 'monthly', description: '',
    });

    const PERCENTAGE_ONLY = ['tacos', 'acos', 'revenue_growth', 'contribution_margin_pct'];
    const supportsValueType = (m) => m && !PERCENTAGE_ONLY.includes(m);

    const onMetricChange = (metric) => {
        goalForm.setData(prev => ({
            ...prev,
            metric,
            value_type: PERCENTAGE_ONLY.includes(metric) ? 'percentage' : 'currency',
        }));
    };

    const openEditGoal = (g) => {
        setEditingGoal(g);
        editGoalForm.setData({
            target_value: g.target_value,
            value_type: g.value_type,
            aggregation: g.aggregation,
            period_type: g.period_type,
            description: g.description || '',
        });
        setEditGoalOpen(true);
    };

    const submitGoal = (e) => {
        e.preventDefault();
        goalForm.post(route('portfolio.goals.store', portfolio_user.id), {
            onSuccess: () => { goalForm.reset(); setGoalOpen(false); },
        });
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

    const applyPeriod = (p) => {
        const base = isAdmin
            ? route('portfolio.show', portfolio_user.id)
            : route('portfolio.own');
        router.get(base, { period: p }, { preserveState: true });
    };

    // Agrupa empresas por role do usuário
    const byRole = companies.reduce((acc, c) => {
        if (!acc[c.role]) acc[c.role] = [];
        acc[c.role].push(c);
        return acc;
    }, {});

    // Metas por empresa, agrupadas por company_id
    const goalsByCompany = goals.reduce((acc, g) => {
        if (!acc[g.company_id]) acc[g.company_id] = [];
        acc[g.company_id].push(g);
        return acc;
    }, {});

    return (
        <AppLayout title={`Carteira — ${portfolio_user.name}`}>
            <div className="space-y-5 max-w-[1200px]">

                {/* Header */}
                <div className="flex items-center justify-between flex-wrap gap-3">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                            <Briefcase size={18} className="text-ecf-yellow/70" />
                        </div>
                        <div>
                            <p className="text-white font-display font-bold text-base">{portfolio_user.name}</p>
                            <p className="text-white/40 text-[12px]">
                                {roleLabel[portfolio_user.role] || portfolio_user.role} · {summary.total_companies} empresa{summary.total_companies !== 1 ? 's' : ''}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="relative">
                            <select
                                value={period}
                                onChange={e => applyPeriod(e.target.value)}
                                className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 transition-all cursor-pointer"
                            >
                                {available_periods.map(p => (
                                    <option key={p} value={p}>{formatPeriod(p)}</option>
                                ))}
                            </select>
                            <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                        </div>
                    </div>
                </div>

                {/* Aviso: sem dados de métricas para o período */}
                {companies.length > 0 && !has_metric_data && (
                    <div className="flex items-start gap-3 px-4 py-3 rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/[0.05]">
                        <TrendingUp size={15} className="text-ecf-yellow/60 mt-0.5 shrink-0" />
                        <p className="text-ecf-yellow/80 text-[13px]">
                            Sem dados de métricas para <strong>{formatPeriod(period)}</strong>.
                            Execute o sync do Adman ou selecione outro período.
                        </p>
                    </div>
                )}

                {/* Resumo da carteira */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <MetricCard
                        label="Faturamento Total"
                        value={summary.total_revenue ? formatCurrency(summary.total_revenue) : '—'}
                        color="text-blue-400"
                    />
                    <MetricCard
                        label="TACOS Médio"
                        value={summary.avg_tacos ? `${Number(summary.avg_tacos).toFixed(2)}%` : '—'}
                        color={summary.avg_tacos > 10 ? 'text-red-400' : 'text-emerald-400'}
                    />
                    <MetricCard
                        label="Margem de Contribuição"
                        value={summary.avg_margin ? `${Number(summary.avg_margin).toFixed(1)}%` : '—'}
                        color="text-cyan-400"
                    />
                    <MetricCard
                        label="Investimento Ads"
                        value={summary.total_ad_spend ? formatCurrency(summary.total_ad_spend) : '—'}
                        color="text-purple-400"
                    />
                </div>

                {/* Metas de carteira */}
                <div className="card-ecf rounded-2xl overflow-hidden">
                    <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06]">
                        <p className="text-white/50 text-sm flex items-center gap-2">
                            <Target size={14} className="text-ecf-yellow/60" /> Metas de Carteira
                        </p>
                        {isAdmin && (
                            <Button size="sm" onClick={() => setGoalOpen(true)}>
                                <Plus size={13} className="mr-1" /> Nova Meta
                            </Button>
                        )}
                    </div>

                    {portfolio_goals.length === 0 ? (
                        <div className="py-8 text-center text-white/25 text-sm">
                            Nenhuma meta de carteira definida
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 p-5">
                            {portfolio_goals.map(g => (
                                <div key={g.id} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-2">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="text-[11px] font-semibold text-white/40 uppercase tracking-wide">{g.metric_label}</p>
                                            <p className="text-[10px] text-white/25 mt-0.5">
                                                {roleLabel[g.role] || g.role} · {aggregationLabel[g.aggregation]} · {periodLabel[g.period_type]}
                                            </p>
                                        </div>
                                        {isAdmin && (
                                            <div className="flex gap-0.5">
                                                <button onClick={() => openEditGoal(g)} className="p-1 rounded hover:bg-white/10 text-white/30 hover:text-white transition-colors">
                                                    <Pencil size={12} />
                                                </button>
                                                <button onClick={() => removeGoal(g.id)} className="p-1 rounded hover:bg-red-500/10 text-red-400/30 hover:text-red-400 transition-colors">
                                                    <Trash2 size={12} />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                    {g.latest_result ? (
                                        <AchievedBadge
                                            achieved={g.latest_result.achieved}
                                            realized={g.latest_result.realized_value}
                                            target={g.latest_result.target_value}
                                            valueType={g.value_type}
                                        />
                                    ) : (
                                        <p className="text-white/30 text-[12px]">
                                            Meta: {g.value_type === 'currency' ? formatCurrency(g.target_value) : `${parseFloat(g.target_value)}%`}
                                            <span className="text-white/20 ml-1">(sem resultado calculado)</span>
                                        </p>
                                    )}
                                    {/* Baseline snapshot */}
                                    {g.baseline_value != null && (
                                        <div className="flex items-center gap-1.5 text-[10px] text-white/30">
                                            <TrendingUp size={10} className="text-white/20" />
                                            <span>Baseline ({g.baseline_period}):</span>
                                            <span className="text-white/50">
                                                {g.value_type === 'currency'
                                                    ? formatCurrency(g.baseline_value)
                                                    : `${parseFloat(g.baseline_value).toFixed(2)}%`}
                                            </span>
                                            {g.latest_result?.realized_value != null && (() => {
                                                const delta = g.latest_result.realized_value - g.baseline_value;
                                                const pct = g.baseline_value !== 0 ? (delta / Math.abs(g.baseline_value)) * 100 : null;
                                                const color = delta >= 0 ? 'text-emerald-400' : 'text-red-400';
                                                return pct != null ? (
                                                    <span className={color}>
                                                        {delta >= 0 ? '+' : ''}{pct.toFixed(1)}%
                                                    </span>
                                                ) : null;
                                            })()}
                                        </div>
                                    )}
                                    {g.latest_result && (
                                        <p className="text-white/20 text-[10px]">{g.latest_result.companies_count} empresa{g.latest_result.companies_count !== 1 ? 's' : ''} · {g.latest_result.period}</p>
                                    )}
                                    {g.description && <p className="text-white/30 text-[11px]">{g.description}</p>}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Empresas da carteira com metas */}
                {Object.entries(byRole).map(([role, roleCompanies]) => (
                    <div key={role} className="space-y-3">
                        <p className="text-white/50 text-[12px] font-semibold uppercase tracking-wider flex items-center gap-2">
                            <Building2 size={13} /> {roleLabel[role] || role} · {roleCompanies.length} empresa{roleCompanies.length !== 1 ? 's' : ''}
                        </p>
                        <div className="space-y-2">
                            {roleCompanies.map(c => (
                                <div key={c.id} className="card-ecf rounded-2xl overflow-hidden">
                                    {/* Company header */}
                                    <div className="grid grid-cols-[1fr_auto] gap-4 px-5 py-3.5 border-b border-white/[0.04]">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <div className="w-7 h-7 rounded-lg bg-white/[0.05] flex items-center justify-center shrink-0">
                                                <Building2 size={13} className="text-white/30" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-white font-semibold text-[13px] truncate">{c.name}</p>
                                                {c.grant_status && (
                                                    <div className="flex items-center gap-1 mt-0.5">
                                                        <ShieldCheck size={10} className="text-white/30" />
                                                        <Badge variant={grantStatusColor[c.grant_status]} className="text-[9px] px-1.5 py-0">
                                                            Grant {c.grant_status === 'active' ? 'ativo' : c.grant_status}
                                                            {c.grant_days_remaining !== null && c.grant_status === 'active' && (
                                                                <span className="ml-1 opacity-70">· {c.grant_days_remaining}d</span>
                                                            )}
                                                        </Badge>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                        {/* Métricas rápidas */}
                                        <div className="flex items-center gap-4 text-[12px] shrink-0">
                                            <div className="text-right">
                                                <p className="text-white/25 text-[10px]">TACOS</p>
                                                <p className={cn('font-semibold', c.tacos > 10 ? 'text-red-400' : 'text-ecf-yellow')}>
                                                    {c.tacos != null ? `${Number(c.tacos).toFixed(2)}%` : '—'}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-white/25 text-[10px]">Fat.</p>
                                                <p className="text-blue-400 font-semibold">
                                                    {c.revenue != null ? formatCurrency(c.revenue) : '—'}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-white/25 text-[10px]">Margem</p>
                                                <p className="text-cyan-400 font-semibold">
                                                    {c.contribution_margin_pct != null ? `${Number(c.contribution_margin_pct).toFixed(1)}%` : '—'}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-white/25 text-[10px]">Ads</p>
                                                <p className="text-purple-400 font-semibold">
                                                    {c.ad_spend != null ? formatCurrency(c.ad_spend) : '—'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Metas da empresa */}
                                    {(goalsByCompany[c.id] || []).length > 0 && (
                                        <div className="px-5 py-3 flex flex-wrap gap-2">
                                            {goalsByCompany[c.id].map(g => (
                                                <div key={g.id} className="text-[11px] rounded-lg border border-white/[0.07] bg-white/[0.02] px-2.5 py-1.5 flex items-center gap-2">
                                                    <span className="text-white/40">{g.metric_label}</span>
                                                    {g.latest_result ? (
                                                        <AchievedBadge
                                                            achieved={g.latest_result.achieved}
                                                            realized={g.latest_result.realized_value}
                                                            target={g.latest_result.target_value}
                                                            valueType={g.value_type}
                                                        />
                                                    ) : (
                                                        <span className="text-white/25">
                                                            {g.value_type === 'currency' ? formatCurrency(g.target_value) : `${g.target_value}%`}
                                                        </span>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            {/* Dialog: Nova meta de carteira */}
            <Dialog open={goalOpen} onOpenChange={setGoalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Nova Meta de Carteira</DialogTitle>
                        <DialogDescription>Meta para toda a carteira de {portfolio_user.name}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitGoal} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Role *</Label>
                                <Select value={goalForm.data.role} onValueChange={v => goalForm.setData('role', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="consultor">Analista</SelectItem>
                                        <SelectItem value="mentor">Estrategista</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Agregação *</Label>
                                <Select value={goalForm.data.aggregation} onValueChange={v => goalForm.setData('aggregation', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="avg">Média da carteira</SelectItem>
                                        <SelectItem value="sum">Soma da carteira</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Métrica *</Label>
                            <Select value={goalForm.data.metric} onValueChange={onMetricChange} required>
                                <SelectTrigger><SelectValue placeholder="Selecionar..." /></SelectTrigger>
                                <SelectContent>
                                    {Object.entries(portfolio_goal_metrics).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Valor Alvo *</Label>
                                <Input type="number" step="0.01" min="0" value={goalForm.data.target_value} onChange={e => goalForm.setData('target_value', e.target.value)} required />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Período *</Label>
                                <Select value={goalForm.data.period_type} onValueChange={v => goalForm.setData('period_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="monthly">Mensal</SelectItem>
                                        <SelectItem value="quarterly">Trimestral</SelectItem>
                                        <SelectItem value="yearly">Anual</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        {supportsValueType(goalForm.data.metric) && (
                            <div className="space-y-1.5">
                                <Label>Tipo de valor</Label>
                                <div className="flex rounded-lg border border-white/[0.08] overflow-hidden w-fit">
                                    {[['currency', 'R$'], ['percentage', '%']].map(([v, l]) => (
                                        <button key={v} type="button" onClick={() => goalForm.setData('value_type', v)}
                                            className={cn('flex items-center gap-1 px-3 py-1.5 text-[12px] font-medium transition-all',
                                                goalForm.data.value_type === v ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50 hover:text-white')}
                                        >{l}</button>
                                    ))}
                                </div>
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Descrição</Label>
                            <Textarea value={goalForm.data.description} onChange={e => goalForm.setData('description', e.target.value)} rows={2} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setGoalOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={goalForm.processing || !goalForm.data.metric || !goalForm.data.target_value}>
                                {goalForm.processing ? 'Salvando...' : 'Criar Meta'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Editar meta de carteira */}
            <Dialog open={editGoalOpen} onOpenChange={setEditGoalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Editar Meta de Carteira</DialogTitle>
                        <DialogDescription>{editingGoal?.metric_label}</DialogDescription>
                    </DialogHeader>
                    {editingGoal && (
                        <form onSubmit={submitEditGoal} className="space-y-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Agregação</Label>
                                    <Select value={editGoalForm.data.aggregation} onValueChange={v => editGoalForm.setData('aggregation', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="avg">Média</SelectItem>
                                            <SelectItem value="sum">Soma</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Período</Label>
                                    <Select value={editGoalForm.data.period_type} onValueChange={v => editGoalForm.setData('period_type', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="monthly">Mensal</SelectItem>
                                            <SelectItem value="quarterly">Trimestral</SelectItem>
                                            <SelectItem value="yearly">Anual</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Valor Alvo *</Label>
                                <Input type="number" step="0.01" min="0" value={editGoalForm.data.target_value} onChange={e => editGoalForm.setData('target_value', e.target.value)} required />
                            </div>
                            {supportsValueType(editingGoal.metric) && (
                                <div className="space-y-1.5">
                                    <Label>Tipo de valor</Label>
                                    <div className="flex rounded-lg border border-white/[0.08] overflow-hidden w-fit">
                                        {[['currency', 'R$'], ['percentage', '%']].map(([v, l]) => (
                                            <button key={v} type="button" onClick={() => editGoalForm.setData('value_type', v)}
                                                className={cn('flex items-center gap-1 px-3 py-1.5 text-[12px] font-medium transition-all',
                                                    editGoalForm.data.value_type === v ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50 hover:text-white')}
                                            >{l}</button>
                                        ))}
                                    </div>
                                </div>
                            )}
                            <div className="space-y-1.5">
                                <Label>Descrição</Label>
                                <Textarea value={editGoalForm.data.description} onChange={e => editGoalForm.setData('description', e.target.value)} rows={2} />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditGoalOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editGoalForm.processing}>{editGoalForm.processing ? 'Salvando...' : 'Atualizar'}</Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
