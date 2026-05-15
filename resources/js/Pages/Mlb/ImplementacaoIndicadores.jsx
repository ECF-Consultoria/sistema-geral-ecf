import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    BarChart, Bar, XAxis, YAxis, Tooltip,
    PieChart, Pie, Cell, ResponsiveContainer,
} from 'recharts';
import {
    Target, CheckCircle2, TrendingUp, AlertTriangle, Clock,
    Building2, X, ArrowLeft, Check, Minus, BarChart2, Users, ChevronRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const STATUS_CONFIG = {
    concluida:    { label: 'Concluídas',    color: '#22c55e', cls: 'text-emerald-400 bg-emerald-500/10 border border-emerald-500/20' },
    em_andamento: { label: 'Em Andamento',  color: '#ffe600', cls: 'text-ecf-yellow bg-ecf-yellow/10 border border-ecf-yellow/20' },
    parada:       { label: 'Parada',        color: '#f97316', cls: 'text-orange-400 bg-orange-500/10 border border-orange-500/20' },
    nao_iniciada: { label: 'Não Iniciada',  color: '#6b7280', cls: 'text-white/40 bg-white/5 border border-white/10' },
};

const ESTAGIO_COLORS = {
    'Não Listado': 'text-white/30 bg-white/[0.04]',
    'Estágio 1':   'text-blue-300 bg-blue-500/10',
    'Estágio 2':   'text-violet-300 bg-violet-500/10',
    'Estágio 3':   'text-amber-300 bg-amber-500/10',
    'Concluido':   'text-emerald-300 bg-emerald-500/10',
};

function pctColor(pct) {
    if (pct === 100) return '#22c55e';
    if (pct >= 75)   return '#3b82f6';
    if (pct >= 50)   return '#ffe600';
    if (pct > 0)     return '#f97316';
    return '#6b7280';
}

function KpiCard({ icon: Icon, label, value, sub, iconColor = 'text-ecf-yellow', iconBg = 'bg-ecf-yellow/10 border-ecf-yellow/20' }) {
    return (
        <div className="card-ecf rounded-2xl p-5 flex items-start gap-4">
            <div className={cn('w-10 h-10 rounded-xl border flex items-center justify-center shrink-0', iconBg)}>
                <Icon size={18} className={iconColor} />
            </div>
            <div className="min-w-0">
                <p className="text-white/40 text-[12px]">{label}</p>
                <p className="text-white font-bold text-2xl leading-tight mt-0.5">{value}</p>
                {sub && <p className="text-white/30 text-[11px] mt-0.5">{sub}</p>}
            </div>
        </div>
    );
}

function StatusBadge({ status }) {
    const cfg = STATUS_CONFIG[status];
    if (!cfg) return null;
    return (
        <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full', cfg.cls)}>
            {cfg.label}
        </span>
    );
}

function ProgressBar({ pct, feitos, total }) {
    const color = pctColor(pct);
    return (
        <div className="flex items-center gap-2">
            <div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden min-w-[60px]">
                <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full transition-all" />
            </div>
            <span className="text-white/40 text-[11px] shrink-0 w-10 text-right">{feitos}/{total}</span>
        </div>
    );
}

const DiffTooltip = ({ active, payload, label }) => {
    if (!active || !payload?.length) return null;
    return (
        <div style={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.07)', borderRadius: 8, padding: '8px 12px', fontSize: 12 }}>
            <p className="text-white/60 text-[11px] mb-1">{label}</p>
            <p className="text-orange-400 font-semibold">{payload[0]?.value}% pendente</p>
        </div>
    );
};

const PieTooltip = ({ active, payload }) => {
    if (!active || !payload?.length) return null;
    const d = payload[0];
    return (
        <div style={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.07)', borderRadius: 8, padding: '8px 12px', fontSize: 12 }}>
            <p className="text-white/60 text-[11px] mb-0.5">{d.name}</p>
            <p className="text-white font-bold">{d.value} empresa{d.value !== 1 ? 's' : ''}</p>
        </div>
    );
};

function TarefaModal({ item, empresas, onClose, onVerEmpresa }) {
    const pendentes = useMemo(() =>
        empresas.filter(e => {
            const it = e.itens.find(i => i.id === item.id);
            return it && !it.feito;
        }),
        [item.id, empresas]
    );
    const concluidas = item.total - pendentes.length;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" onClick={onClose}>
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[85vh] flex flex-col" onClick={e => e.stopPropagation()}>
                {/* Header */}
                <div className="flex items-start justify-between p-5 border-b border-white/[0.06] shrink-0">
                    <div className="min-w-0 pr-3">
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider mb-1">Empresas pendentes</p>
                        <h2 className="text-white font-bold text-base leading-snug">{item.titulo}</h2>
                        <div className="flex items-center gap-3 mt-2">
                            <span className="text-orange-400 text-[12px] font-semibold">
                                {pendentes.length} pendente{pendentes.length !== 1 ? 's' : ''} ({item.pct_pendente}%)
                            </span>
                            <span className="text-white/20 text-[11px]">·</span>
                            <span className="text-emerald-400 text-[12px] font-semibold">
                                {concluidas} concluída{concluidas !== 1 ? 's' : ''} ({item.pct_concluido}%)
                            </span>
                        </div>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition-colors shrink-0">
                        <X size={16} />
                    </button>
                </div>

                {/* Barra de progresso geral */}
                <div className="px-5 py-3 border-b border-white/[0.06] shrink-0">
                    <div className="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                        <div
                            className="h-full rounded-full bg-emerald-500/70 transition-all"
                            style={{ width: `${item.pct_concluido}%` }}
                        />
                    </div>
                </div>

                {/* Lista de empresas pendentes */}
                <div className="flex-1 overflow-y-auto">
                    {pendentes.length === 0 ? (
                        <div className="px-5 py-12 text-center">
                            <CheckCircle2 size={28} className="text-emerald-400 mx-auto mb-2" />
                            <p className="text-emerald-300 font-semibold text-sm">Todas concluíram esta tarefa!</p>
                        </div>
                    ) : (
                        <div>
                            {pendentes.map((empresa, idx) => (
                                <button
                                    key={empresa.id}
                                    onClick={() => onVerEmpresa(empresa)}
                                    className={cn(
                                        'w-full flex items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-white/[0.03]',
                                        idx < pendentes.length - 1 && 'border-b border-white/[0.04]'
                                    )}
                                >
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 mb-1">
                                            <span className="text-white text-[13px] font-medium truncate">{empresa.nome}</span>
                                            <StatusBadge status={empresa.status} />
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-white/40 text-[11px]">{empresa.responsavel}</span>
                                            <span className="text-white/20 text-[10px]">·</span>
                                            <span className="text-white/40 text-[11px]">
                                                {empresa.progresso.pct}% geral ({empresa.progresso.feitos}/{empresa.progresso.total})
                                            </span>
                                            {empresa.ultimo_acesso ? (
                                                <>
                                                    <span className="text-white/20 text-[10px]">·</span>
                                                    <span className="text-white/30 text-[11px]">
                                                        {empresa.dias_sem === 0 ? 'Hoje' : `${empresa.dias_sem}d sem acesso`}
                                                    </span>
                                                </>
                                            ) : (
                                                <>
                                                    <span className="text-white/20 text-[10px]">·</span>
                                                    <span className="text-white/30 text-[11px]">Nunca acessou</span>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                    <ChevronRight size={14} className="text-white/20 shrink-0" />
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function EmpresaModal({ empresa, onClose }) {
    const feitos = empresa.itens.filter(i => i.feito).length;
    const total  = empresa.itens.length;
    const pct    = total > 0 ? Math.round(feitos / total * 100) : 0;
    const color  = pctColor(pct);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" onClick={onClose}>
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[88vh] flex flex-col" onClick={e => e.stopPropagation()}>
                {/* Header */}
                <div className="flex items-start justify-between p-5 border-b border-white/[0.06] shrink-0">
                    <div>
                        <h2 className="text-white font-bold text-base">{empresa.nome}</h2>
                        <div className="flex items-center gap-2 mt-1.5 flex-wrap">
                            <StatusBadge status={empresa.status} />
                            {empresa.estagio && (
                                <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full', ESTAGIO_COLORS[empresa.estagio] ?? 'text-white/30 bg-white/[0.04]')}>
                                    {empresa.estagio}
                                </span>
                            )}
                        </div>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition-colors shrink-0">
                        <X size={16} />
                    </button>
                </div>

                {/* Progresso */}
                <div className="px-5 py-3 border-b border-white/[0.06] shrink-0">
                    <div className="flex items-center justify-between mb-2">
                        <span className="text-white/40 text-[12px]">Progresso</span>
                        <span className="font-bold text-sm" style={{ color }}>{pct}%</span>
                    </div>
                    <div className="h-2 bg-white/10 rounded-full overflow-hidden">
                        <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: color }} />
                    </div>
                    <p className="text-white/30 text-[11px] mt-1">{feitos} de {total} tarefas concluídas</p>
                </div>

                {/* Info */}
                <div className="px-5 py-3 border-b border-white/[0.06] shrink-0 grid grid-cols-2 gap-y-3">
                    <div>
                        <p className="text-white/30 text-[11px]">Responsável</p>
                        <p className="text-white/70 text-[13px] font-medium mt-0.5">{empresa.responsavel}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[11px]">Início da Implementação</p>
                        <p className="text-white/70 text-[13px] font-medium mt-0.5">{empresa.criado_em}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[11px]">Último Acesso</p>
                        <p className="text-white/70 text-[13px] font-medium mt-0.5">
                            {empresa.ultimo_acesso
                                ? (empresa.dias_sem === 0 ? 'Hoje' : `Há ${empresa.dias_sem} dia${empresa.dias_sem !== 1 ? 's' : ''} (${empresa.ultimo_acesso})`)
                                : 'Nunca acessou'}
                        </p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[11px]">Estágio Empresa</p>
                        <p className="text-white/70 text-[13px] font-medium mt-0.5">{empresa.estagio ?? '—'}</p>
                    </div>
                </div>

                {/* Checklist */}
                <div className="flex-1 overflow-y-auto p-5">
                    <p className="text-white/30 text-[11px] font-semibold uppercase tracking-wider mb-3">Checklist de Tarefas</p>
                    <div className="space-y-2">
                        {empresa.itens.map(item => (
                            <div key={item.id} className={cn(
                                'flex items-center gap-3 px-3 py-2 rounded-lg border',
                                item.feito
                                    ? 'border-emerald-500/15 bg-emerald-500/[0.04]'
                                    : 'border-white/[0.05] bg-white/[0.02]'
                            )}>
                                <div className={cn(
                                    'w-5 h-5 rounded-full flex items-center justify-center shrink-0',
                                    item.feito ? 'bg-emerald-500/20' : 'bg-white/[0.05]'
                                )}>
                                    {item.feito
                                        ? <Check size={11} className="text-emerald-400" />
                                        : <Minus size={11} className="text-white/20" />
                                    }
                                </div>
                                <span className={cn('text-[13px]', item.feito ? 'text-emerald-300' : 'text-white/50')}>
                                    {item.titulo}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function ImplementacaoIndicadores({ total, media_progresso, status_counts, dificuldades, empresas }) {
    const [filtroStatus, setFiltroStatus]         = useState('todos');
    const [filtroResponsavel, setFiltroResponsavel] = useState('todos');
    const [filtroPct, setFiltroPct]               = useState('todos');
    const [busca, setBusca]                       = useState('');
    const [empresaModal, setEmpresaModal]         = useState(null);
    const [itemModal, setItemModal]               = useState(null);

    const responsaveis = useMemo(() => {
        const unique = [...new Set(empresas.map(e => e.responsavel).filter(r => r && r !== '—'))];
        return unique.sort();
    }, [empresas]);

    const filtradas = useMemo(() => empresas.filter(e => {
        if (filtroStatus !== 'todos' && e.status !== filtroStatus) return false;
        if (filtroResponsavel !== 'todos' && e.responsavel !== filtroResponsavel) return false;
        if (busca && !e.nome.toLowerCase().includes(busca.toLowerCase())) return false;
        if (filtroPct !== 'todos') {
            const p = e.progresso.pct;
            if (filtroPct === '0'     && p !== 0)               return false;
            if (filtroPct === '1-50'  && (p < 1 || p > 50))    return false;
            if (filtroPct === '51-99' && (p < 51 || p >= 100)) return false;
            if (filtroPct === '100'   && p !== 100)             return false;
        }
        return true;
    }), [empresas, filtroStatus, filtroResponsavel, busca, filtroPct]);

    const pieData = useMemo(() =>
        Object.entries(STATUS_CONFIG)
            .map(([key, cfg]) => ({ name: cfg.label, value: status_counts[key] || 0, color: cfg.color }))
            .filter(d => d.value > 0),
        [status_counts]
    );

    // Top 8 por pendência para o gráfico
    const diffChartData = useMemo(() =>
        dificuldades.slice(0, 8).map(d => ({
            titulo: d.titulo.length > 24 ? d.titulo.substring(0, 22) + '…' : d.titulo,
            pct_pendente: d.pct_pendente,
        })).reverse(),
        [dificuldades]
    );

    const topDificuldade = dificuldades[0];
    const maisConcluidaItem = [...dificuldades].sort((a, b) => b.pct_concluido - a.pct_concluido)[0];

    return (
        <AppLayout title="Indicadores de Implementação">
            <div className="max-w-7xl mx-auto space-y-6">

                {/* Header */}
                <div className="flex items-start gap-3">
                    <Link
                        href={route('mlb.implementacao.index')}
                        className="mt-1 p-1.5 text-white/30 hover:text-white/70 transition-colors shrink-0"
                    >
                        <ArrowLeft size={18} />
                    </Link>
                    <div>
                        <h1 className="text-white font-display font-bold text-xl">Indicadores de Implementação</h1>
                        <p className="text-white/40 text-[13px] mt-0.5">
                            Acompanhe o andamento, gargalos e eficiência do processo de implementação
                        </p>
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4">
                    <KpiCard
                        icon={Building2}
                        label="Total"
                        value={total}
                        sub="em implementação"
                    />
                    <KpiCard
                        icon={CheckCircle2}
                        label="Concluídas"
                        value={status_counts.concluida || 0}
                        iconColor="text-emerald-400"
                        iconBg="bg-emerald-500/10 border-emerald-500/20"
                        sub={total ? `${Math.round((status_counts.concluida || 0) / total * 100)}% do total` : '—'}
                    />
                    <KpiCard
                        icon={TrendingUp}
                        label="Em Andamento"
                        value={status_counts.em_andamento || 0}
                        iconColor="text-blue-400"
                        iconBg="bg-blue-500/10 border-blue-500/20"
                        sub="acessaram recentemente"
                    />
                    <KpiCard
                        icon={AlertTriangle}
                        label="Paradas"
                        value={status_counts.parada || 0}
                        iconColor="text-orange-400"
                        iconBg="bg-orange-500/10 border-orange-500/20"
                        sub=">7 dias sem acesso"
                    />
                    <KpiCard
                        icon={Clock}
                        label="Não Iniciadas"
                        value={status_counts.nao_iniciada || 0}
                        iconColor="text-white/40"
                        iconBg="bg-white/5 border-white/10"
                        sub="0% de progresso"
                    />
                    <KpiCard
                        icon={Target}
                        label="Progresso Médio"
                        value={`${media_progresso}%`}
                        iconColor="text-violet-400"
                        iconBg="bg-violet-500/10 border-violet-500/20"
                        sub="média de conclusão"
                    />
                </div>

                {/* Charts Row */}
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">

                    {/* Status Distribution */}
                    <div className="card-ecf rounded-2xl p-5">
                        <div className="flex items-center gap-2 mb-5">
                            <div className="w-7 h-7 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                                <BarChart2 size={13} className="text-ecf-yellow" />
                            </div>
                            <div>
                                <p className="text-white font-semibold text-sm leading-none">Distribuição por Status</p>
                                <p className="text-white/30 text-[11px] mt-0.5">{total} empresa{total !== 1 ? 's' : ''} cadastradas</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-6">
                            <div className="w-36 h-36 shrink-0">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie
                                            data={pieData}
                                            cx="50%"
                                            cy="50%"
                                            innerRadius={44}
                                            outerRadius={66}
                                            paddingAngle={3}
                                            dataKey="value"
                                            strokeWidth={0}
                                        >
                                            {pieData.map(entry => (
                                                <Cell key={entry.name} fill={entry.color} />
                                            ))}
                                        </Pie>
                                        <Tooltip content={<PieTooltip />} />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="flex-1 space-y-3">
                                {Object.entries(STATUS_CONFIG).map(([key, cfg]) => {
                                    const count = status_counts[key] || 0;
                                    const pct   = total ? Math.round(count / total * 100) : 0;
                                    return (
                                        <div key={key}>
                                            <div className="flex items-center justify-between mb-1">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="w-2 h-2 rounded-full shrink-0" style={{ background: cfg.color }} />
                                                    <span className="text-white/60 text-[12px]">{cfg.label}</span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-white/30 text-[11px]">{pct}%</span>
                                                    <span className="text-white text-[12px] font-bold w-4 text-right">{count}</span>
                                                </div>
                                            </div>
                                            <div className="h-1 bg-white/[0.06] rounded-full overflow-hidden">
                                                <div
                                                    className="h-full rounded-full transition-all"
                                                    style={{ width: `${pct}%`, background: cfg.color }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    {/* Top Dificuldades Chart */}
                    <div className="card-ecf rounded-2xl p-5">
                        <div className="flex items-center gap-2 mb-5">
                            <div className="w-7 h-7 rounded-lg bg-orange-500/10 border border-orange-500/20 flex items-center justify-center">
                                <AlertTriangle size={13} className="text-orange-400" />
                            </div>
                            <div>
                                <p className="text-white font-semibold text-sm leading-none">Tarefas com Maior Dificuldade</p>
                                <p className="text-white/30 text-[11px] mt-0.5">% de empresas que não concluíram cada tarefa</p>
                            </div>
                        </div>
                        <div className="h-52">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={diffChartData}
                                    layout="vertical"
                                    margin={{ left: 0, right: 24, top: 0, bottom: 0 }}
                                >
                                    <XAxis
                                        type="number"
                                        domain={[0, 100]}
                                        tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.3)' }}
                                        tickFormatter={v => `${v}%`}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="titulo"
                                        width={130}
                                        tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.5)' }}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <Tooltip content={<DiffTooltip />} cursor={{ fill: 'rgba(255,255,255,0.03)' }} />
                                    <Bar dataKey="pct_pendente" fill="#f97316" radius={[0, 3, 3, 0]} barSize={10} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>

                {/* Destaques */}
                {(topDificuldade || maisConcluidaItem) && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {topDificuldade && (
                            <div className="rounded-2xl border border-orange-500/20 bg-orange-500/[0.04] p-4">
                                <p className="text-orange-400/70 text-[11px] font-semibold uppercase tracking-wider mb-1">Principal Gargalo</p>
                                <p className="text-white font-bold text-base">{topDificuldade.titulo}</p>
                                <p className="text-orange-300 text-[13px] mt-1">
                                    <span className="font-bold">{topDificuldade.pct_pendente}%</span> das empresas não concluíram esta tarefa
                                </p>
                                <p className="text-white/30 text-[11px] mt-0.5">{topDificuldade.pendentes} de {topDificuldade.total} empresas ainda pendentes</p>
                            </div>
                        )}
                        {maisConcluidaItem && (
                            <div className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.04] p-4">
                                <p className="text-emerald-400/70 text-[11px] font-semibold uppercase tracking-wider mb-1">Tarefa Mais Concluída</p>
                                <p className="text-white font-bold text-base">{maisConcluidaItem.titulo}</p>
                                <p className="text-emerald-300 text-[13px] mt-1">
                                    <span className="font-bold">{maisConcluidaItem.pct_concluido}%</span> das empresas já concluíram esta tarefa
                                </p>
                                <p className="text-white/30 text-[11px] mt-0.5">{maisConcluidaItem.feitos} de {maisConcluidaItem.total} empresas concluídas</p>
                            </div>
                        )}
                    </div>
                )}

                {/* Análise Completa de Dificuldades */}
                <div className="card-ecf rounded-2xl p-5">
                    <div className="flex items-center gap-2 mb-5">
                        <div className="w-7 h-7 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center">
                            <AlertTriangle size={13} className="text-red-400" />
                        </div>
                        <div>
                            <p className="text-white font-semibold text-sm leading-none">Análise Completa de Dificuldades</p>
                            <p className="text-white/30 text-[11px] mt-0.5">Clique em qualquer tarefa para ver quais empresas ainda não concluíram</p>
                        </div>
                    </div>

                    <div className="space-y-1">
                        {dificuldades.map((item, idx) => (
                            <button
                                key={item.id}
                                onClick={() => setItemModal(item)}
                                className="w-full flex items-center gap-3 px-2 py-2 rounded-xl hover:bg-white/[0.04] transition-colors group text-left"
                            >
                                <span className="text-white/20 text-[11px] w-4 shrink-0 text-right">{idx + 1}</span>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between mb-1.5">
                                        <span className="text-white/70 text-[12px] truncate pr-2 group-hover:text-white/90 transition-colors">
                                            {item.titulo}
                                        </span>
                                        <div className="flex items-center gap-4 shrink-0">
                                            <span className="text-orange-400 text-[12px] font-semibold w-20 text-right">
                                                {item.pct_pendente}% pendente
                                            </span>
                                            <span className="text-emerald-400 text-[12px] font-semibold w-24 text-right hidden sm:block">
                                                {item.pct_concluido}% concluído
                                            </span>
                                            <span className="text-white/30 text-[11px] w-12 text-right">
                                                {item.feitos}/{item.total}
                                            </span>
                                            <ChevronRight size={13} className="text-white/10 group-hover:text-white/40 transition-colors shrink-0" />
                                        </div>
                                    </div>
                                    <div className="h-1.5 bg-white/[0.06] rounded-full overflow-hidden">
                                        <div
                                            className="h-full rounded-full transition-all bg-emerald-500/60"
                                            style={{ width: `${item.pct_concluido}%` }}
                                        />
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Filtros + Lista de Empresas */}
                <div className="card-ecf rounded-2xl overflow-hidden">
                    {/* Header + Filtros */}
                    <div className="p-4 border-b border-white/[0.06]">
                        <div className="flex items-center gap-2 mb-3">
                            <div className="w-6 h-6 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center">
                                <Users size={12} className="text-white/40" />
                            </div>
                            <p className="text-white font-semibold text-sm">Empresas em Implementação</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                type="text"
                                value={busca}
                                onChange={e => setBusca(e.target.value)}
                                placeholder="Buscar empresa..."
                                className="h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 w-44"
                            />
                            <select
                                value={filtroStatus}
                                onChange={e => setFiltroStatus(e.target.value)}
                                className="h-8 px-2 rounded-lg border border-white/[0.08] bg-[#0f1116] text-white/70 text-[12px] focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="todos">Todos os status</option>
                                {Object.entries(STATUS_CONFIG).map(([k, v]) => (
                                    <option key={k} value={k}>{v.label}</option>
                                ))}
                            </select>
                            {responsaveis.length > 0 && (
                                <select
                                    value={filtroResponsavel}
                                    onChange={e => setFiltroResponsavel(e.target.value)}
                                    className="h-8 px-2 rounded-lg border border-white/[0.08] bg-[#0f1116] text-white/70 text-[12px] focus:outline-none focus:border-ecf-yellow/40"
                                >
                                    <option value="todos">Todos os responsáveis</option>
                                    {responsaveis.map(r => <option key={r} value={r}>{r}</option>)}
                                </select>
                            )}
                            <select
                                value={filtroPct}
                                onChange={e => setFiltroPct(e.target.value)}
                                className="h-8 px-2 rounded-lg border border-white/[0.08] bg-[#0f1116] text-white/70 text-[12px] focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="todos">Todo progresso</option>
                                <option value="0">Não iniciado (0%)</option>
                                <option value="1-50">1% – 50%</option>
                                <option value="51-99">51% – 99%</option>
                                <option value="100">Concluído (100%)</option>
                            </select>
                            <span className="text-white/30 text-[12px] ml-auto">
                                {filtradas.length} empresa{filtradas.length !== 1 ? 's' : ''}
                            </span>
                        </div>
                    </div>

                    {/* Tabela */}
                    <table className="w-full">
                        <thead>
                            <tr className="border-b border-white/[0.06]">
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider">Empresa</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden sm:table-cell">Status</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden md:table-cell">Responsável</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider">Progresso</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden lg:table-cell">Último Acesso</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtradas.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center text-white/30 text-[13px]">
                                        Nenhuma empresa encontrada.
                                    </td>
                                </tr>
                            )}
                            {filtradas.map((empresa, idx) => (
                                <tr
                                    key={empresa.id}
                                    onClick={() => setEmpresaModal(empresa)}
                                    className={cn(
                                        'border-b border-white/[0.04] cursor-pointer transition-colors hover:bg-white/[0.025]',
                                        idx === filtradas.length - 1 && 'border-b-0'
                                    )}
                                >
                                    <td className="px-4 py-3">
                                        <span className="text-white text-[13px] font-medium">{empresa.nome}</span>
                                    </td>
                                    <td className="px-4 py-3 hidden sm:table-cell">
                                        <StatusBadge status={empresa.status} />
                                    </td>
                                    <td className="px-4 py-3 hidden md:table-cell">
                                        <span className="text-white/50 text-[12px]">{empresa.responsavel}</span>
                                    </td>
                                    <td className="px-4 py-3 min-w-[160px]">
                                        <ProgressBar
                                            pct={empresa.progresso.pct}
                                            feitos={empresa.progresso.feitos}
                                            total={empresa.progresso.total}
                                        />
                                    </td>
                                    <td className="px-4 py-3 hidden lg:table-cell">
                                        <span className="text-white/40 text-[12px]">
                                            {empresa.ultimo_acesso
                                                ? (empresa.dias_sem === 0
                                                    ? 'Hoje'
                                                    : `${empresa.dias_sem}d atrás`)
                                                : 'Nunca acessou'
                                            }
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {itemModal && (
                <TarefaModal
                    item={itemModal}
                    empresas={empresas}
                    onClose={() => setItemModal(null)}
                    onVerEmpresa={e => { setItemModal(null); setEmpresaModal(e); }}
                />
            )}
            {empresaModal && (
                <EmpresaModal empresa={empresaModal} onClose={() => setEmpresaModal(null)} />
            )}
        </AppLayout>
    );
}
