import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, Target, Building2, DollarSign, Percent, Users } from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';

const periodLabel = { monthly: 'Mensal', quarterly: 'Trimestral', yearly: 'Anual' };
const aggregationLabel = { avg: 'Média', sum: 'Soma' };
const roleLabel = { consultor: 'Analista', mentor: 'Mentor' };

const metricColor = {
    revenue:                 'text-green-400 bg-green-400/10 border-green-400/20',
    avg_ticket:              'text-teal-400 bg-teal-400/10 border-teal-400/20',
    net_billing:             'text-indigo-400 bg-indigo-400/10 border-indigo-400/20',
    tacos:                   'text-yellow-400 bg-yellow-400/10 border-yellow-400/20',
    acos:                    'text-orange-400 bg-orange-400/10 border-orange-400/20',
    contribution_margin:     'text-blue-400 bg-blue-400/10 border-blue-400/20',
    contribution_margin_pct: 'text-blue-300 bg-blue-300/10 border-blue-300/20',
    margin:                  'text-cyan-400 bg-cyan-400/10 border-cyan-400/20',
    nps:                     'text-emerald-400 bg-emerald-400/10 border-emerald-400/20',
    absenteeism:             'text-red-400 bg-red-400/10 border-red-400/20',
    revenue_growth:          'text-purple-400 bg-purple-400/10 border-purple-400/20',
    products_without_cost:   'text-orange-400 bg-orange-400/10 border-orange-400/20',
    ppa_completion:          'text-pink-400 bg-pink-400/10 border-pink-400/20',
};

function formatGoalValue(goal) {
    const v = parseFloat(goal.target_value);
    if (goal.value_type === 'currency') return formatCurrency(v);
    if (goal.metric === 'nps') return v.toFixed(1);
    return `${v}%`;
}

function ValueTypeToggle({ value, onChange }) {
    return (
        <div className="flex rounded-lg border border-white/[0.08] overflow-hidden w-fit">
            <button type="button" onClick={() => onChange('currency')}
                className={cn('flex items-center gap-1 px-3 py-1.5 text-[12px] font-medium transition-all',
                    value === 'currency' ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50 hover:text-white')}>
                <DollarSign size={11} /> R$
            </button>
            <button type="button" onClick={() => onChange('percentage')}
                className={cn('flex items-center gap-1 px-3 py-1.5 text-[12px] font-medium transition-all',
                    value === 'percentage' ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50 hover:text-white')}>
                <Percent size={11} /> %
            </button>
        </div>
    );
}

// ─── Seção: Metas por Empresa ──────────────────────────────────────────────

function EmpresasSection({ companies, metrics, percentage_only_metrics, can_manage }) {
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [selectedCompany, setSelectedCompany] = useState(null);

    const { data, setData, post, processing, reset } = useForm({
        company_id: '', metric: '', target_value: '', value_type: 'currency', period_type: 'monthly', description: '',
    });
    const editForm = useForm({ target_value: '', value_type: 'percentage', period_type: 'monthly', description: '' });

    const isPercentageOnly = (metric) => percentage_only_metrics.includes(metric);
    const supportsValueType = (metric) => metric && !isPercentageOnly(metric) && metric !== 'nps';

    const onMetricChange = (metric) => {
        setData(prev => ({
            ...prev, metric,
            value_type: isPercentageOnly(metric) || metric === 'nps' ? 'percentage' : 'currency',
        }));
    };

    const openCreate = (company) => {
        reset();
        setData('company_id', String(company.id));
        setSelectedCompany(company);
        setOpen(true);
    };

    const openEdit = (goal) => {
        setEditing(goal);
        editForm.setData({
            target_value: goal.target_value,
            value_type: goal.value_type || 'percentage',
            period_type: goal.period_type,
            description: goal.description || '',
        });
        setEditOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('goals.store'), { onSuccess: () => { reset(); setOpen(false); } });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('goals.update', editing.id), { onSuccess: () => setEditOpen(false) });
    };

    const remove = (id) => {
        if (confirm('Remover esta meta?')) router.delete(route('goals.destroy', id));
    };

    if (companies.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-24 gap-4">
                <div className="w-16 h-16 rounded-2xl bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                    <Target className="h-8 w-8 text-ecf-yellow/60" />
                </div>
                <div className="text-center">
                    <p className="text-white/70 font-semibold text-lg">Nenhuma empresa cadastrada</p>
                    <p className="text-white/30 text-sm mt-1">Cadastre empresas primeiro para definir metas personalizadas.</p>
                </div>
                <Button variant="outline" onClick={() => router.visit(route('companies.index'))}
                    className="border-white/10 text-white/60 hover:text-white hover:border-white/20">
                    <Building2 className="h-4 w-4 mr-2" /> Ir para Empresas
                </Button>
            </div>
        );
    }

    return (
        <>
            <div className="space-y-5">
                {companies.map(company => (
                    <div key={company.id} className="card-ecf rounded-2xl overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06]">
                            <div>
                                <p className="text-white font-display font-bold text-[15px] leading-tight">{company.name}</p>
                                <p className="text-white/30 text-xs mt-0.5">
                                    Analista: <span className="text-white/50">{company.consultor || '—'}</span>
                                    {' · '}
                                    Estrategista: <span className="text-white/50">{company.estrategista || '—'}</span>
                                </p>
                            </div>
                            {can_manage && (
                                <button onClick={() => openCreate(company)}
                                    className="flex items-center gap-1.5 h-8 px-3 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[12px] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all">
                                    <Plus size={13} /> Meta
                                </button>
                            )}
                        </div>
                        <div className="p-5">
                            {(company.goals || []).length === 0 ? (
                                can_manage ? (
                                    <button onClick={() => openCreate(company)}
                                        className="w-full flex items-center justify-center gap-2 py-6 rounded-xl border border-dashed border-white/[0.08] text-white/25 hover:text-white/40 hover:border-white/15 transition-colors text-sm">
                                        <Target size={15} /> Nenhuma meta — clique para adicionar
                                    </button>
                                ) : (
                                    <p className="text-center text-white/25 text-sm py-6">Nenhuma meta definida</p>
                                )
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    {company.goals.map(goal => (
                                        <div key={goal.id}
                                            className={cn('flex items-start justify-between rounded-xl border p-4',
                                                metricColor[goal.metric] || 'text-white/60 bg-white/[0.03] border-white/[0.08]')}>
                                            <div className="space-y-1.5 min-w-0">
                                                <p className="text-[11px] font-semibold tracking-wide uppercase opacity-70">{goal.metric_label}</p>
                                                <p className="font-display font-extrabold text-2xl leading-none">{formatGoalValue(goal)}</p>
                                                <div className="flex items-center gap-1.5 flex-wrap">
                                                    <span className="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-white/10">
                                                        {periodLabel[goal.period_type]}
                                                    </span>
                                                    {goal.value_type === 'currency' && (
                                                        <span className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/10">
                                                            <DollarSign size={9} /> R$
                                                        </span>
                                                    )}
                                                </div>
                                                {goal.description && (
                                                    <p className="text-[11px] opacity-60 leading-snug">{goal.description}</p>
                                                )}
                                            </div>
                                            {can_manage && (
                                                <div className="flex gap-0.5 shrink-0 ml-2">
                                                    <button onClick={() => openEdit(goal)}
                                                        className="p-1.5 rounded-lg hover:bg-white/10 transition-colors opacity-50 hover:opacity-100">
                                                        <Pencil size={13} />
                                                    </button>
                                                    <button onClick={() => remove(goal.id)}
                                                        className="p-1.5 rounded-lg hover:bg-red-500/20 transition-colors opacity-50 hover:opacity-100 text-red-400">
                                                        <Trash2 size={13} />
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {/* Dialog: Nova Meta de Empresa */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Nova Meta — {selectedCompany?.name}</DialogTitle>
                        <DialogDescription>Define um alvo personalizado para esta empresa.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Métrica *</Label>
                            <Select value={data.metric} onValueChange={onMetricChange} required>
                                <SelectTrigger><SelectValue placeholder="Selecionar métrica..." /></SelectTrigger>
                                <SelectContent>
                                    {Object.entries(metrics).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Valor Alvo *</Label>
                                <Input type="number" step="0.01" min="0"
                                    value={data.target_value}
                                    onChange={e => setData('target_value', e.target.value)} required />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Período *</Label>
                                <Select value={data.period_type} onValueChange={v => setData('period_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="monthly">Mensal</SelectItem>
                                        <SelectItem value="quarterly">Trimestral</SelectItem>
                                        <SelectItem value="yearly">Anual</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        {supportsValueType(data.metric) && (
                            <div className="space-y-1.5">
                                <Label>Tipo de valor</Label>
                                <ValueTypeToggle value={data.value_type} onChange={v => setData('value_type', v)} />
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Descrição / Observação</Label>
                            <Textarea value={data.description} onChange={e => setData('description', e.target.value)}
                                rows={2} placeholder="Ex: Meta ajustada por sazonalidade do setor" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing || !data.metric || !data.target_value}>
                                {processing ? 'Salvando...' : 'Criar Meta'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Editar Meta de Empresa */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Editar Meta</DialogTitle>
                        <DialogDescription>{editing?.metric_label}</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <form onSubmit={submitEdit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Valor Alvo *</Label>
                                    <Input type="number" step="0.01" min="0"
                                        value={editForm.data.target_value}
                                        onChange={e => editForm.setData('target_value', e.target.value)} required />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Período</Label>
                                    <Select value={editForm.data.period_type} onValueChange={v => editForm.setData('period_type', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="monthly">Mensal</SelectItem>
                                            <SelectItem value="quarterly">Trimestral</SelectItem>
                                            <SelectItem value="yearly">Anual</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            {supportsValueType(editing.metric) && (
                                <div className="space-y-1.5">
                                    <Label>Tipo de valor</Label>
                                    <ValueTypeToggle value={editForm.data.value_type} onChange={v => editForm.setData('value_type', v)} />
                                </div>
                            )}
                            <div className="space-y-1.5">
                                <Label>Descrição</Label>
                                <Textarea value={editForm.data.description}
                                    onChange={e => editForm.setData('description', e.target.value)} rows={2} />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editForm.processing}>
                                    {editForm.processing ? 'Salvando...' : 'Atualizar'}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Seção: Metas de Carteira ──────────────────────────────────────────────

function CarteirasSection({ users, portfolio_goal_metrics, portfolio_pct_metrics }) {
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [selectedUser, setSelectedUser] = useState(null);

    const portfolioForm = useForm({
        role: 'consultor', metric: '', target_value: '', value_type: 'currency',
        aggregation: 'avg', period_type: 'monthly', description: '',
    });

    const editForm = useForm({
        target_value: '', value_type: 'currency', aggregation: 'avg',
        period_type: 'monthly', description: '',
    });

    const isPctOnly = (metric) => portfolio_pct_metrics.includes(metric);
    const supportsVT = (metric) => metric && !isPctOnly(metric);

    const onMetricChange = (metric) => {
        portfolioForm.setData(prev => ({
            ...prev, metric,
            value_type: isPctOnly(metric) ? 'percentage' : 'currency',
        }));
    };

    const openCreate = (user) => {
        portfolioForm.reset();
        portfolioForm.setData('role', user.role);
        setSelectedUser(user);
        setOpen(true);
    };

    const openEdit = (goal) => {
        setEditing(goal);
        editForm.setData({
            target_value: goal.target_value,
            value_type: goal.value_type || 'currency',
            aggregation: goal.aggregation,
            period_type: goal.period_type,
            description: goal.description || '',
        });
        setEditOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        portfolioForm.post(route('portfolio.goals.store', selectedUser.id), {
            onSuccess: () => { portfolioForm.reset(); setOpen(false); },
        });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('portfolio.goals.update', editing.id), {
            onSuccess: () => setEditOpen(false),
        });
    };

    const remove = (id) => {
        if (confirm('Remover esta meta de carteira?')) router.delete(route('portfolio.goals.destroy', id));
    };

    if (users.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-24 gap-4">
                <div className="w-16 h-16 rounded-2xl bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                    <Users className="h-8 w-8 text-ecf-yellow/60" />
                </div>
                <p className="text-white/40 text-sm">Nenhum analista ou mentor cadastrado.</p>
            </div>
        );
    }

    return (
        <>
            <div className="space-y-5">
                {users.map(user => (
                    <div key={user.id} className="card-ecf rounded-2xl overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06]">
                            <div>
                                <p className="text-white font-display font-bold text-[15px] leading-tight">{user.name}</p>
                                <p className="text-white/30 text-xs mt-0.5">
                                    <span className={cn('px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                        user.role === 'mentor' ? 'bg-purple-500/15 text-purple-300' : 'bg-blue-500/15 text-blue-300')}>
                                        {roleLabel[user.role] ?? user.role}
                                    </span>
                                </p>
                            </div>
                            <button onClick={() => openCreate(user)}
                                className="flex items-center gap-1.5 h-8 px-3 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[12px] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all">
                                <Plus size={13} /> Meta de Carteira
                            </button>
                        </div>
                        <div className="p-5">
                            {(user.portfolio_goals || []).length === 0 ? (
                                <button onClick={() => openCreate(user)}
                                    className="w-full flex items-center justify-center gap-2 py-6 rounded-xl border border-dashed border-white/[0.08] text-white/25 hover:text-white/40 hover:border-white/15 transition-colors text-sm">
                                    <Target size={15} /> Nenhuma meta de carteira — clique para adicionar
                                </button>
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    {user.portfolio_goals.map(goal => (
                                        <div key={goal.id}
                                            className={cn('flex items-start justify-between rounded-xl border p-4',
                                                metricColor[goal.metric] || 'text-white/60 bg-white/[0.03] border-white/[0.08]')}>
                                            <div className="space-y-1.5 min-w-0">
                                                <p className="text-[11px] font-semibold tracking-wide uppercase opacity-70">{goal.metric_label}</p>
                                                <p className="font-display font-extrabold text-2xl leading-none">{formatGoalValue(goal)}</p>
                                                <div className="flex items-center gap-1.5 flex-wrap">
                                                    <span className="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-white/10">
                                                        {periodLabel[goal.period_type]}
                                                    </span>
                                                    <span className="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-white/10">
                                                        {aggregationLabel[goal.aggregation]}
                                                    </span>
                                                    {goal.value_type === 'currency' && (
                                                        <span className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/10">
                                                            <DollarSign size={9} /> R$
                                                        </span>
                                                    )}
                                                </div>
                                                {goal.description && (
                                                    <p className="text-[11px] opacity-60 leading-snug">{goal.description}</p>
                                                )}
                                            </div>
                                            <div className="flex gap-0.5 shrink-0 ml-2">
                                                <button onClick={() => openEdit(goal)}
                                                    className="p-1.5 rounded-lg hover:bg-white/10 transition-colors opacity-50 hover:opacity-100">
                                                    <Pencil size={13} />
                                                </button>
                                                <button onClick={() => remove(goal.id)}
                                                    className="p-1.5 rounded-lg hover:bg-red-500/20 transition-colors opacity-50 hover:opacity-100 text-red-400">
                                                    <Trash2 size={13} />
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {/* Dialog: Nova Meta de Carteira */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Nova Meta de Carteira — {selectedUser?.name}</DialogTitle>
                        <DialogDescription>Define uma meta agregada para a carteira deste profissional.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Papel *</Label>
                                <Select value={portfolioForm.data.role} onValueChange={v => portfolioForm.setData('role', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="consultor">Analista</SelectItem>
                                        <SelectItem value="mentor">Mentor</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Agregação *</Label>
                                <Select value={portfolioForm.data.aggregation} onValueChange={v => portfolioForm.setData('aggregation', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="avg">Média</SelectItem>
                                        <SelectItem value="sum">Soma</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Métrica *</Label>
                            <Select value={portfolioForm.data.metric} onValueChange={onMetricChange} required>
                                <SelectTrigger><SelectValue placeholder="Selecionar métrica..." /></SelectTrigger>
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
                                <Input type="number" step="0.01" min="0"
                                    value={portfolioForm.data.target_value}
                                    onChange={e => portfolioForm.setData('target_value', e.target.value)} required />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Período *</Label>
                                <Select value={portfolioForm.data.period_type} onValueChange={v => portfolioForm.setData('period_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="monthly">Mensal</SelectItem>
                                        <SelectItem value="quarterly">Trimestral</SelectItem>
                                        <SelectItem value="yearly">Anual</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        {supportsVT(portfolioForm.data.metric) && (
                            <div className="space-y-1.5">
                                <Label>Tipo de valor</Label>
                                <ValueTypeToggle value={portfolioForm.data.value_type} onChange={v => portfolioForm.setData('value_type', v)} />
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Descrição / Observação</Label>
                            <Textarea value={portfolioForm.data.description}
                                onChange={e => portfolioForm.setData('description', e.target.value)}
                                rows={2} placeholder="Ex: Meta de faturamento médio por carteira" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={portfolioForm.processing || !portfolioForm.data.metric || !portfolioForm.data.target_value}>
                                {portfolioForm.processing ? 'Salvando...' : 'Criar Meta'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Editar Meta de Carteira */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Editar Meta de Carteira</DialogTitle>
                        <DialogDescription>{editing?.metric_label}</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <form onSubmit={submitEdit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Valor Alvo *</Label>
                                    <Input type="number" step="0.01" min="0"
                                        value={editForm.data.target_value}
                                        onChange={e => editForm.setData('target_value', e.target.value)} required />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Período</Label>
                                    <Select value={editForm.data.period_type} onValueChange={v => editForm.setData('period_type', v)}>
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
                                <Label>Agregação</Label>
                                <Select value={editForm.data.aggregation} onValueChange={v => editForm.setData('aggregation', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="avg">Média</SelectItem>
                                        <SelectItem value="sum">Soma</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {supportsVT(editing.metric) && (
                                <div className="space-y-1.5">
                                    <Label>Tipo de valor</Label>
                                    <ValueTypeToggle value={editForm.data.value_type} onChange={v => editForm.setData('value_type', v)} />
                                </div>
                            )}
                            <div className="space-y-1.5">
                                <Label>Descrição</Label>
                                <Textarea value={editForm.data.description}
                                    onChange={e => editForm.setData('description', e.target.value)} rows={2} />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editForm.processing}>
                                    {editForm.processing ? 'Salvando...' : 'Atualizar'}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Seção: Minhas Metas de Carteira (read-only para mentor/analista) ─────────

function MinhasMetasCarteira({ goals }) {
    if (goals.length === 0) return null;
    return (
        <div className="card-ecf rounded-2xl overflow-hidden">
            <div className="px-5 py-4 border-b border-white/[0.06]">
                <div className="flex items-center gap-2">
                    <Target size={15} className="text-ecf-yellow/70" />
                    <p className="text-white font-display font-bold text-[15px]">Minhas Metas de Carteira</p>
                </div>
                <p className="text-white/30 text-xs mt-0.5">Metas atribuídas pelo administrador para a sua carteira</p>
            </div>
            <div className="p-5">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    {goals.map(goal => (
                        <div key={goal.id}
                            className={cn('rounded-xl border p-4',
                                metricColor[goal.metric] || 'text-white/60 bg-white/[0.03] border-white/[0.08]')}>
                            <div className="space-y-1.5 min-w-0">
                                <p className="text-[11px] font-semibold tracking-wide uppercase opacity-70">{goal.metric_label}</p>
                                <p className="font-display font-extrabold text-2xl leading-none">{formatGoalValue(goal)}</p>
                                <div className="flex items-center gap-1.5 flex-wrap">
                                    <span className="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-white/10">
                                        {periodLabel[goal.period_type]}
                                    </span>
                                    <span className="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-white/10">
                                        {aggregationLabel[goal.aggregation]}
                                    </span>
                                    {goal.value_type === 'currency' && (
                                        <span className="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/10">
                                            <DollarSign size={9} /> R$
                                        </span>
                                    )}
                                    <span className={cn('inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full',
                                        goal.role === 'mentor' ? 'bg-purple-500/20 text-purple-300' : 'bg-blue-500/20 text-blue-300')}>
                                        {roleLabel[goal.role] ?? goal.role}
                                    </span>
                                </div>
                                {goal.description && (
                                    <p className="text-[11px] opacity-60 leading-snug">{goal.description}</p>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

// ─── Página principal ──────────────────────────────────────────────────────

export default function Goals({
    companies = [], metrics = {}, percentage_only_metrics = [],
    can_manage = false, users = [], my_portfolio_goals = [],
    portfolio_goal_metrics = {}, portfolio_pct_metrics = [],
}) {
    const [tab, setTab] = useState('empresas');

    return (
        <AppLayout title="Metas">
            <div className="space-y-5 max-w-[1200px]">
                {/* Metas de carteira atribuídas (apenas mentor/analista) */}
                {!can_manage && my_portfolio_goals.length > 0 && (
                    <MinhasMetasCarteira goals={my_portfolio_goals} />
                )}

                {/* Tabs */}
                <div className="flex gap-1 p-1 rounded-xl bg-white/[0.04] border border-white/[0.06] w-fit">
                    <button
                        onClick={() => setTab('empresas')}
                        className={cn(
                            'flex items-center gap-2 px-4 py-2 rounded-lg text-[13px] font-semibold transition-all',
                            tab === 'empresas'
                                ? 'bg-ecf-yellow text-[#252525] shadow'
                                : 'text-white/50 hover:text-white'
                        )}
                    >
                        <Building2 size={14} /> Por Empresa
                    </button>
                    {can_manage && (
                        <button
                            onClick={() => setTab('carteiras')}
                            className={cn(
                                'flex items-center gap-2 px-4 py-2 rounded-lg text-[13px] font-semibold transition-all',
                                tab === 'carteiras'
                                    ? 'bg-ecf-yellow text-[#252525] shadow'
                                    : 'text-white/50 hover:text-white'
                            )}
                        >
                            <Users size={14} /> Por Carteira
                        </button>
                    )}
                </div>

                {tab === 'empresas' && (
                    <EmpresasSection
                        companies={companies}
                        metrics={metrics}
                        percentage_only_metrics={percentage_only_metrics}
                        can_manage={can_manage}
                    />
                )}

                {tab === 'carteiras' && can_manage && (
                    <CarteirasSection
                        users={users}
                        portfolio_goal_metrics={portfolio_goal_metrics}
                        portfolio_pct_metrics={portfolio_pct_metrics}
                    />
                )}
            </div>
        </AppLayout>
    );
}
