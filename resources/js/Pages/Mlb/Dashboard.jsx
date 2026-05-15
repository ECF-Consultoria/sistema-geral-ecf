import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { TicketGeralChart } from '@/Components/TicketMedioChart';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip,
    AreaChart, Area, ResponsiveContainer, Cell,
    PieChart, Pie, Legend,
} from 'recharts';
import { Target, CheckCircle, TrendingUp, Clock, ShoppingBag, Building2, ListChecks, AlertTriangle, Layers, Tv, X } from 'lucide-react';
import { cn } from '@/lib/utils';

const PROJETO_COR = {
    'POLOS':      { border: 'border-ecf-yellow/30',   bg: 'bg-ecf-yellow/10',   text: 'text-ecf-yellow',  hex: '#ffe600' },
    'Assessoria': { border: 'border-blue-500/30',     bg: 'bg-blue-500/10',     text: 'text-blue-400',    hex: '#3b82f6' },
    'Incubadora': { border: 'border-purple-500/30',   bg: 'bg-purple-500/10',   text: 'text-purple-400',  hex: '#a855f7' },
    'Implantação':{ border: 'border-emerald-500/30',  bg: 'bg-emerald-500/10',  text: 'text-emerald-400', hex: '#22c55e' },
    'Outros':     { border: 'border-white/10',        bg: 'bg-white/5',         text: 'text-white/50',    hex: '#6b7280' },
};
function getCor(nome) {
    return PROJETO_COR[nome] ?? PROJETO_COR['Outros'];
}

function DistribuicaoTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;
    const d = payload[0].payload;
    return (
        <div style={{ background:'#0f1116', border:'1px solid rgba(255,255,255,0.07)', borderRadius:10, fontSize:12, padding:'8px 12px' }}>
            <p className="text-white/60 text-[11px] mb-0.5">{d.nome}</p>
            <p className="text-white font-bold text-sm">{d.total} empresa{d.total !== 1 ? 's' : ''}</p>
            <p className="text-white/50 text-[11px]">{d.pct}% do total</p>
        </div>
    );
}

function DistribuicaoChart({ distribuicao, totalEmpresas }) {
    if (!distribuicao?.length) return null;
    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-center gap-2 mb-5">
                <div className="w-7 h-7 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                    <Building2 size={13} className="text-ecf-yellow" />
                </div>
                <div>
                    <p className="text-white font-semibold text-sm leading-none">Distribuição de Empresas por Tipo</p>
                    <p className="text-white/30 text-[11px] mt-0.5">{totalEmpresas} empresa{totalEmpresas !== 1 ? 's' : ''} no total</p>
                </div>
            </div>
            <div className="flex flex-col md:flex-row items-center gap-6">
                <div className="shrink-0 w-44 h-44">
                    <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                            <Pie data={distribuicao} cx="50%" cy="50%" innerRadius={52} outerRadius={76} paddingAngle={3} dataKey="total" strokeWidth={0}>
                                {distribuicao.map(entry => (
                                    <Cell key={entry.nome} fill={getCor(entry.nome).hex} />
                                ))}
                            </Pie>
                            <Tooltip content={<DistribuicaoTooltip />} />
                        </PieChart>
                    </ResponsiveContainer>
                </div>
                <div className="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-3 w-full">
                    {distribuicao.map(item => {
                        const cor = getCor(item.nome);
                        return (
                            <div key={item.nome} className={cn('rounded-xl border px-4 py-3', cor.border, cor.bg)}>
                                <div className="flex items-center justify-between mb-2">
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2 h-2 rounded-full shrink-0" style={{ background: cor.hex }} />
                                        <span className={cn('text-[12px] font-semibold', cor.text)}>{item.nome}</span>
                                    </div>
                                    <span className={cn('text-[13px] font-bold', cor.text)}>{item.pct}%</span>
                                </div>
                                <div className="h-1 bg-white/10 rounded-full overflow-hidden">
                                    <div className="h-full rounded-full" style={{ width: `${item.pct}%`, background: cor.hex }} />
                                </div>
                                <p className="text-white/30 text-[11px] mt-1.5">{item.total} empresa{item.total !== 1 ? 's' : ''}</p>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

const ESTAGIO_COR = {
    'Não Listado': '#6b7280',
    'Estágio 1':   '#ffe600',
    'Estágio 2':   '#f97316',
    'Estágio 3':   '#3b82f6',
    'Concluido':   '#22c55e',
    'Churn':       '#ef4444',
    'Finalizado':  '#a855f7',
    'Problema':    '#f43f5e',
};
const CORES_EXTRAS = ['#14b8a6', '#06b6d4', '#ec4899', '#84cc16', '#fb923c', '#8b5cf6', '#d946ef', '#0ea5e9'];

function getEstagioHex(nome, idx) {
    return ESTAGIO_COR[nome] ?? CORES_EXTRAS[idx % CORES_EXTRAS.length];
}

function EstagioTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;
    const d = payload[0].payload;
    return (
        <div style={{ background:'#0f1116', border:'1px solid rgba(255,255,255,0.07)', borderRadius:10, fontSize:12, padding:'8px 12px' }}>
            <p className="text-white/60 text-[11px] mb-0.5">{d.estagio}</p>
            <p className="text-white font-bold text-sm">{d.total} empresa{d.total !== 1 ? 's' : ''}</p>
            <p className="text-white/50 text-[11px]">{d.pct}% do total</p>
        </div>
    );
}

function EstagiosChart({ estagiosDistrib, totalTodasEmpresas }) {
    if (!estagiosDistrib?.length) return null;
    const [aberto, setAberto] = useState(null);

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-center gap-2 mb-5">
                <div className="w-7 h-7 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
                    <Layers size={13} className="text-purple-400" />
                </div>
                <div>
                    <p className="text-white font-semibold text-sm leading-none">Empresas por Estágio</p>
                    <p className="text-white/30 text-[11px] mt-0.5">{totalTodasEmpresas} empresa{totalTodasEmpresas !== 1 ? 's' : ''} no total · clique no estágio para ver as empresas</p>
                </div>
            </div>
            <div className="flex flex-col md:flex-row items-start gap-6">
                <div className="shrink-0 w-44 h-44">
                    <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                            <Pie
                                data={estagiosDistrib}
                                cx="50%" cy="50%"
                                innerRadius={52} outerRadius={76}
                                paddingAngle={3}
                                dataKey="total"
                                nameKey="estagio"
                                strokeWidth={0}
                            >
                                {estagiosDistrib.map((entry, i) => (
                                    <Cell key={entry.estagio} fill={getEstagioHex(entry.estagio, i)} />
                                ))}
                            </Pie>
                            <Tooltip content={<EstagioTooltip />} />
                        </PieChart>
                    </ResponsiveContainer>
                </div>
                <div className="flex-1 space-y-1 w-full">
                    {estagiosDistrib.map((item, i) => {
                        const hex     = getEstagioHex(item.estagio, i);
                        const isOpen  = aberto === item.estagio;
                        const temLista = (item.empresas ?? []).length > 0;

                        return (
                            <div key={item.estagio}>
                                {/* Linha clicável */}
                                <button
                                    type="button"
                                    onClick={() => temLista && setAberto(isOpen ? null : item.estagio)}
                                    className={cn(
                                        'w-full rounded-lg px-2 py-1.5 transition-colors text-left',
                                        temLista ? 'hover:bg-white/[0.04] cursor-pointer' : 'cursor-default'
                                    )}
                                >
                                    <div className="flex items-center justify-between text-[12px] mb-1">
                                        <div className="flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full shrink-0" style={{ background: hex }} />
                                            <span className="text-white/70 font-medium">{item.estagio}</span>
                                            {temLista && (
                                                <span className="text-white/25 text-[10px]">
                                                    {isOpen ? '▲' : '▼'}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-3 text-[11px]">
                                            <span className="font-bold" style={{ color: hex }}>{item.total}</span>
                                            <span className="text-white/40 w-10 text-right font-semibold">{item.pct}%</span>
                                        </div>
                                    </div>
                                    <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                        <div className="h-full rounded-full transition-all" style={{ width: `${item.pct}%`, background: hex }} />
                                    </div>
                                </button>

                                {/* Lista de empresas expansível */}
                                {isOpen && (
                                    <div className="mt-1 mb-2 ml-4 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                        <div className="flex flex-wrap gap-1.5">
                                            {(item.empresas ?? []).map((nome, j) => (
                                                <span key={j}
                                                    className="px-2 py-0.5 rounded-md text-[11px] border border-white/[0.08] text-white/60">
                                                    {nome}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

const MESES_PT = {
    '01':'Janeiro','02':'Fevereiro','03':'Março','04':'Abril',
    '05':'Maio','06':'Junho','07':'Julho','08':'Agosto',
    '09':'Setembro','10':'Outubro','11':'Novembro','12':'Dezembro',
};

function nomeMes(ym) {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    return `${MESES_PT[m]} / ${y}`;
}

function fmt(n) { return Number(n ?? 0).toLocaleString('pt-BR'); }
function fmtDec(n) { return Number(n ?? 0).toFixed(1).replace('.', ','); }

function KpiCard({ title, value, sub, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow: { text:'text-ecf-yellow', bg:'bg-ecf-yellow/10', border:'border-ecf-yellow/20' },
        green:  { text:'text-emerald-400', bg:'bg-emerald-500/10', border:'border-emerald-500/20' },
        blue:   { text:'text-blue-400',   bg:'bg-blue-500/10',   border:'border-blue-500/20' },
        purple: { text:'text-purple-400', bg:'bg-purple-500/10', border:'border-purple-500/20' },
        orange: { text:'text-orange-400', bg:'bg-orange-500/10', border:'border-orange-500/20' },
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-2xl p-5">
            <div className="flex items-center justify-between mb-4">
                <p className="text-white/50 text-[11px] font-semibold tracking-wide uppercase">{title}</p>
                <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center border', c.bg, c.border)}>
                    <Icon size={15} className={c.text} />
                </div>
            </div>
            <p className={cn('font-display font-extrabold text-3xl tracking-tight', c.text)}>{value}</p>
            {sub && <p className="text-white/30 text-xs mt-1">{sub}</p>}
        </div>
    );
}

function ProgressBar({ pct, classe }) {
    const colors = { above:'#22c55e', ontrack:'#eab308', below:'#ef4444' };
    return (
        <div className="h-2 bg-white/10 rounded-full overflow-hidden mt-2">
            <div style={{ width:`${Math.min(pct,100)}%`, background: colors[classe] || '#8b5cf6' }} className="h-full rounded-full transition-all" />
        </div>
    );
}

function StatusBadge({ status, classe }) {
    const styles = {
        above:   'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
        ontrack: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30',
        below:   'bg-red-500/15 text-red-400 border-red-500/30',
    };
    return (
        <span className={cn('inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border tracking-wide uppercase', styles[classe])}>
            {status}
        </span>
    );
}

const chartCfg = {
    tooltip: { contentStyle: { background:'#0f1116', border:'1px solid rgba(255,255,255,0.07)', borderRadius:10, fontSize:12 }, labelStyle: { color:'#9ba0aa' } },
    grid:    { stroke:'rgba(255,255,255,0.04)', strokeDasharray:'4 4' },
    axis:    { tick: { fill:'#6a6f79', fontSize:11 } },
};

function ListagemCard({ listagem }) {
    if (!listagem) return null;
    const { total, listadas, nao_listadas, por_projeto, sem_sku } = listagem;
    const pct = total > 0 ? Math.round(listadas / total * 100) : 0;
    const [semSkuAberto, setSemSkuAberto] = useState(false);

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            {/* Header */}
            <div className="flex items-center gap-2 mb-5">
                <div className="w-7 h-7 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
                    <ListChecks size={13} className="text-blue-400" />
                </div>
                <div>
                    <p className="text-white font-semibold text-sm leading-none">Listagem de Empresas</p>
                    <p className="text-white/30 text-[11px] mt-0.5">Empresas com SKU vinculado</p>
                </div>
            </div>

            <div className="flex flex-col lg:flex-row gap-6">
                {/* Coluna esquerda: KPIs + barra global */}
                <div className="lg:w-56 shrink-0 space-y-3">
                    <div className="rounded-xl border border-white/[0.07] bg-white/[0.02] px-4 py-3 flex items-center justify-between">
                        <span className="text-white/50 text-[12px]">Total</span>
                        <span className="text-white font-bold text-xl">{total}</span>
                    </div>
                    <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 flex items-center justify-between">
                        <span className="text-emerald-400/70 text-[12px]">Listadas</span>
                        <span className="text-emerald-400 font-bold text-xl">{listadas}</span>
                    </div>
                    <div className="rounded-xl border border-red-500/20 bg-red-500/5 px-4 py-3 flex items-center justify-between">
                        <span className="text-red-400/70 text-[12px]">Não Listadas</span>
                        <span className="text-red-400 font-bold text-xl">{nao_listadas}</span>
                    </div>

                    {/* Barra global */}
                    <div className="pt-1">
                        <div className="flex justify-between text-[11px] text-white/40 mb-1.5">
                            <span>Nível de listagem</span>
                            <span className="text-emerald-400 font-semibold">{pct}%</span>
                        </div>
                        <div className="h-2 bg-white/10 rounded-full overflow-hidden">
                            <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: '#22c55e' }} />
                        </div>
                        <div className="flex justify-between text-[10px] text-white/20 mt-1">
                            <span>0</span>
                            <span>{total}</span>
                        </div>
                    </div>
                </div>

                {/* Coluna direita: breakdown por projeto + sem SKU */}
                <div className="flex-1 space-y-4">
                    {/* Breakdown */}
                    <div className="space-y-2.5">
                        {(por_projeto ?? []).map(p => {
                            const cor = getCor(p.nome);
                            return (
                                <div key={p.nome}>
                                    <div className="flex items-center justify-between text-[12px] mb-1">
                                        <div className="flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full shrink-0" style={{ background: cor.hex }} />
                                            <span className="text-white/70 font-medium">{p.nome}</span>
                                            <span className="text-white/30 text-[11px]">({p.total})</span>
                                        </div>
                                        <div className="flex items-center gap-3 text-[11px]">
                                            <span className="text-emerald-400">{p.listadas} com SKU</span>
                                            {p.nao_listadas > 0 && (
                                                <span className="text-red-400">{p.nao_listadas} sem</span>
                                            )}
                                            <span className="text-white/40 w-8 text-right font-semibold">{p.pct}%</span>
                                        </div>
                                    </div>
                                    <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                        <div
                                            className="h-full rounded-full transition-all"
                                            style={{ width: `${p.pct}%`, background: cor.hex }}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Empresas sem SKU — colapsável */}
                    {sem_sku?.length > 0 && (
                        <div>
                            <button
                                onClick={() => setSemSkuAberto(v => !v)}
                                className="flex items-center gap-2 text-[11px] text-white/40 hover:text-white/70 transition-colors"
                            >
                                <span className="px-2 py-0.5 rounded-md bg-red-500/10 border border-red-500/20 text-red-400 font-semibold">
                                    {sem_sku.length} sem SKU
                                </span>
                                <span className="text-white/25">{semSkuAberto ? '▲ ocultar' : '▼ ver empresas'}</span>
                            </button>

                            {semSkuAberto && (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {sem_sku.map((e, i) => {
                                        const cor = getCor(e.projeto);
                                        return (
                                            <span key={i} className={cn('px-2 py-0.5 rounded-lg text-[11px] border', cor.border, cor.text)}>
                                                {e.nome}
                                            </span>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function AlertasCard({ alertas }) {
    if (!alertas || (alertas.empresas === 0 && alertas.anuncios === 0)) return null;
    return (
        <div className="rounded-2xl border border-red-500/25 bg-red-500/[0.04] px-5 py-4 mb-6 flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <div className="flex items-center gap-2 shrink-0">
                <AlertTriangle size={15} className="text-red-400" />
                <span className="text-red-400 font-semibold text-sm">Alertas de Problemas</span>
            </div>
            <div className="flex items-center gap-4 flex-wrap">
                <div className="flex items-center gap-2.5 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-2">
                    <span className="text-red-400 font-extrabold text-xl leading-none">{alertas.empresas}</span>
                    <span className="text-red-400/70 text-[12px] leading-tight">conta{alertas.empresas !== 1 ? 's' : ''}<br/>com problema</span>
                </div>
                <div className="flex items-center gap-2.5 rounded-xl border border-orange-500/20 bg-orange-500/10 px-4 py-2">
                    <span className="text-orange-400 font-extrabold text-xl leading-none">{alertas.anuncios}</span>
                    <span className="text-orange-400/70 text-[12px] leading-tight">anúncio{alertas.anuncios !== 1 ? 's' : ''}<br/>com problema</span>
                </div>
            </div>
        </div>
    );
}

function AtrasoChart({ relatorioAtrasos }) {
    if (!relatorioAtrasos) return null;
    const { concluidos_atrasados, pendentes_atrasados, por_empresa } = relatorioAtrasos;
    const total = concluidos_atrasados + pendentes_atrasados;
    if (total === 0) return null;

    const chartData = por_empresa.map(e => ({
        nome:       e.nome.length > 16 ? e.nome.slice(0, 16) + '…' : e.nome,
        concluidos: e.concluidos,
        pendentes:  e.pendentes,
    }));

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-start justify-between mb-5">
                <div>
                    <p className="text-white font-semibold text-sm">Relatório de Atrasos de SKU</p>
                    <p className="text-white/30 text-[11px] mt-0.5">SKUs com prazo vencido — concluídos em atraso vs ainda pendentes</p>
                </div>
                <div className="flex items-center gap-4">
                    <div className="text-center">
                        <p className="text-orange-400 font-display font-bold text-2xl">{concluidos_atrasados}</p>
                        <p className="text-white/30 text-[10px]">Concluídos<br/>c/ atraso</p>
                    </div>
                    <div className="w-px h-10 bg-white/[0.08]" />
                    <div className="text-center">
                        <p className="text-red-400 font-display font-bold text-2xl">{pendentes_atrasados}</p>
                        <p className="text-white/30 text-[10px]">Pendentes<br/>em atraso</p>
                    </div>
                </div>
            </div>

            {chartData.length > 0 && (
                <ResponsiveContainer width="100%" height={Math.max(chartData.length * 24, 80)}>
                    <BarChart
                        data={chartData}
                        layout="vertical"
                        barSize={10}
                        margin={{ top: 0, right: 24, left: 4, bottom: 0 }}
                    >
                        <CartesianGrid stroke="rgba(255,255,255,0.04)" horizontal={false} />
                        <XAxis type="number" allowDecimals={false}
                            tick={{ fill:'#6a6f79', fontSize:10 }} axisLine={false} tickLine={false} />
                        <YAxis type="category" dataKey="nome" width={105}
                            tick={{ fill:'rgba(255,255,255,0.55)', fontSize:10 }} axisLine={false} tickLine={false} />
                        <Tooltip
                            contentStyle={{ background:'#0f1116', border:'1px solid rgba(255,255,255,0.08)', borderRadius:10, fontSize:11 }}
                            labelStyle={{ color:'rgba(255,255,255,0.6)' }}
                        />
                        <Legend iconSize={8} wrapperStyle={{ fontSize:10, color:'rgba(255,255,255,0.35)', paddingTop:6 }} />
                        <Bar dataKey="concluidos" name="Concluídos c/ atraso" fill="#f97316" radius={[0,2,2,0]} stackId="a" />
                        <Bar dataKey="pendentes"  name="Pendentes em atraso"  fill="#ef4444" radius={[0,2,2,0]} stackId="a" />
                    </BarChart>
                </ResponsiveContainer>
            )}
        </div>
    );
}

export default function MlbDashboard({ kpisGerais, ranking, evolucaoDiaria, evolucaoMensal, metaGeral, mesRef, meses, distribuicao, totalEmpresas, listagem, alertas, estagiosDistrib, totalTodasEmpresas, ticketPorPub = [], ticketGeral = 0, relatorioAtrasos = null }) {
    const k = kpisGerais ?? {};
    const [tvMode, setTvMode] = useState(false);

    const handleMes = (e) => {
        router.get(route('mlb.dashboard'), { mes: e.target.value }, { preserveState: true });
    };

    const rankColors = (feito, metaInd) => feito >= metaInd ? '#22c55e' : feito >= metaInd * 0.85 ? '#eab308' : '#ef4444';

    /* ── TV MODE ──────────────────────────────────────────── */
    if (tvMode) {
        const pct = k.percentual ?? 0;
        const progressColor = k.status_classe === 'above' ? '#22c55e' : k.status_classe === 'ontrack' ? '#eab308' : '#ef4444';
        return (
            <div className="fixed inset-0 bg-[#050507] z-50 flex flex-col gap-4 p-6 overflow-hidden">
                {/* Header */}
                <div className="flex items-center justify-between shrink-0">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2.5">
                            <div className="w-9 h-9 rounded-xl bg-ecf-yellow flex items-center justify-center">
                                <span className="text-[#252525] font-display font-extrabold text-base">E</span>
                            </div>
                            <div>
                                <p className="text-white font-display font-extrabold text-xl tracking-tight">Controle de Publicações</p>
                                <p className="text-white/30 text-xs">{nomeMes(mesRef)} · {new Date().toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' })}</p>
                            </div>
                        </div>
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

                <div className="h-[2px] bg-ecf-grad rounded-full shrink-0" />

                {/* KPIs */}
                <div className="grid grid-cols-5 gap-3 shrink-0">
                    {[
                        { title: 'Meta da Equipe',  value: fmt(k.meta),      icon: Target,      color: 'yellow' },
                        { title: 'Anúncios Feitos', value: fmt(k.feito),     icon: CheckCircle, color: 'green',  sub: `${fmtDec(k.percentual)}% da meta` },
                        { title: 'Vendas no Mês',   value: fmt(k.vendas),    icon: ShoppingBag, color: 'blue',   sub: `${fmtDec(k.conversao_vendas)}% conversão` },
                        { title: 'Faltam',          value: fmt(k.faltantes), icon: Clock,       color: 'orange', sub: `${k.dias_uteis_restantes} dias úteis` },
                        { title: 'Projeção',        value: fmt(k.projecao),  icon: TrendingUp,  color: 'purple', sub: 'no ritmo atual' },
                    ].map(card => <KpiCard key={card.title} {...card} />)}
                </div>

                {/* Progresso geral */}
                <div className="card-ecf rounded-2xl px-5 py-3 shrink-0">
                    <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-3">
                            <p className="text-white/60 text-xs font-semibold tracking-wide uppercase">Progresso da Equipe</p>
                            <span className="text-white/30 text-xs">
                                Média atual: <b className="text-white">{fmtDec(k.media_diaria_atual)}/dia</b> · Alvo: <b className="text-white">{fmtDec(k.media_diaria_alvo)}/dia</b>
                            </span>
                        </div>
                        <StatusBadge status={k.status} classe={k.status_classe} />
                    </div>
                    <div className="h-3 bg-white/10 rounded-full overflow-hidden">
                        <div className="h-full rounded-full transition-all" style={{ width: `${Math.min(pct, 100)}%`, background: progressColor }} />
                    </div>
                    <div className="flex justify-between text-[10px] text-white/20 mt-1">
                        <span>0</span><span>Meta: {fmt(k.meta)}</span>
                    </div>
                </div>

                {/* Charts */}
                <div className="grid grid-cols-3 gap-3 flex-1 min-h-0">
                    {/* Evolução Diária */}
                    <div className="col-span-2 card-ecf rounded-2xl p-5 flex flex-col">
                        <p className="text-white/60 text-xs font-semibold tracking-wide uppercase mb-1">Publicações</p>
                        <p className="text-white font-display font-extrabold text-base tracking-tight mb-3">Evolução Diária · {nomeMes(mesRef)}</p>
                        <div className="flex-1">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={evolucaoDiaria ?? []} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="tvGradDiario" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%"  stopColor="#ffe600" stopOpacity={0.35} />
                                            <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid stroke="rgba(255,255,255,0.04)" strokeDasharray="4 4" />
                                    <XAxis dataKey="data" tick={{ fill: '#6a6f79', fontSize: 11 }} axisLine={false} tickLine={false} />
                                    <YAxis tick={{ fill: '#6a6f79', fontSize: 11 }} axisLine={false} tickLine={false} width={28} />
                                    <Tooltip contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.07)', borderRadius: 10, fontSize: 12 }} labelStyle={{ color: '#9ba0aa' }} />
                                    <Area type="monotone" dataKey="total" name="Publicações" stroke="#ffe600" strokeWidth={2.5} fill="url(#tvGradDiario)" dot={false} activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </div>

                    {/* Ranking */}
                    <div className="card-ecf rounded-2xl p-5 flex flex-col">
                        <p className="text-white font-semibold text-sm mb-1">Ranking do Mês</p>
                        <p className="text-white/30 text-xs mb-4">Meta por pessoa</p>
                        <div className="space-y-3 flex-1 overflow-y-auto">
                            {(ranking ?? []).map((r, i) => (
                                <div key={r.id}>
                                    <div className="flex justify-between items-center mb-1">
                                        <span className="text-white/80 text-[13px] font-medium">{i + 1}. {r.nome}</span>
                                        <span className="text-white text-[13px] font-bold">
                                            {fmt(r.feito)} {r.meta > 0 && <span className="text-white/30 font-normal text-[11px]">/ {fmt(r.meta)}</span>}
                                        </span>
                                    </div>
                                    {r.meta > 0 ? (
                                        <div className="h-2 bg-white/10 rounded-full overflow-hidden">
                                            <div style={{ width: `${Math.min((r.feito / r.meta) * 100, 100)}%`, background: rankColors(r.feito, r.meta) }} className="h-full rounded-full" />
                                        </div>
                                    ) : (
                                        <div className="h-2 bg-white/[0.04] rounded-full" />
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    /* ── NORMAL MODE ─────────────────────────────────────── */
    return (
        <AppLayout title="Publicação · Dashboard">
            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Controle de Publicações</h1>
                    <p className="text-white/40 text-sm mt-0.5">Visão consolidada da equipe · Meta total: {fmt(metaGeral)}/mês</p>
                </div>
                <div className="flex items-center gap-2">
                    <select
                        value={mesRef}
                        onChange={handleMes}
                        className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                    >
                        {(meses ?? []).map(m => (
                            <option key={m} value={m}>{nomeMes(m)}</option>
                        ))}
                    </select>
                    <button
                        onClick={() => setTvMode(true)}
                        className="flex items-center gap-1.5 h-9 px-3 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20 transition-all"
                    >
                        <Tv size={13} /> Modo TV
                    </button>
                </div>
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <KpiCard title="Meta da Equipe"  value={fmt(k.meta)}      sub="soma das metas individuais"               icon={Target}    color="yellow" />
                <KpiCard title="Anúncios Feitos"  value={fmt(k.feito)}     sub={`${fmtDec(k.percentual)}% da meta`}       icon={CheckCircle} color="green" />
                <KpiCard title="Vendas no Mês"    value={fmt(k.vendas)}    sub={`${fmtDec(k.conversao_vendas)}% conversão`} icon={ShoppingBag} color="blue" />
                <KpiCard title="Faltam"           value={fmt(k.faltantes)} sub={`${k.dias_uteis_restantes} dias úteis`}   icon={Clock}     color="orange" />
                <KpiCard title="Projeção"         value={fmt(k.projecao)}  sub="no ritmo atual"                           icon={TrendingUp} color="purple" />
            </div>

            {/* Alertas de problemas */}
            <AlertasCard alertas={alertas} />

            {/* Ticket Médio por Publicador */}
            <TicketGeralChart
                ticketPorPub={ticketPorPub}
                ticketGeral={ticketGeral}
                subtitulo={`${nomeMes(mesRef)} · preço líquido médio por unidade vendida`}
            />

            {/* Progresso geral */}
            <div className="card-ecf rounded-2xl p-5 mb-6">
                <div className="flex items-center justify-between mb-2">
                    <div>
                        <p className="text-white font-semibold text-sm">Progresso da Equipe</p>
                        <p className="text-white/40 text-xs mt-0.5">
                            Média atual: <b className="text-white">{fmtDec(k.media_diaria_atual)}/dia</b> ·
                            Alvo: <b className="text-white">{fmtDec(k.media_diaria_alvo)}/dia</b>
                        </p>
                    </div>
                    <StatusBadge status={k.status} classe={k.status_classe} />
                </div>
                <ProgressBar pct={k.percentual} classe={k.status_classe} />
                <div className="flex justify-between text-[11px] text-white/30 mt-1.5">
                    <span>0</span><span>Meta: {fmt(k.meta)}</span>
                </div>
            </div>

            {/* Gráficos */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-6">
                {/* Evolução diária */}
                <div className="card-ecf rounded-2xl p-5 lg:col-span-3">
                    <p className="text-white/50 text-[10px] font-semibold tracking-widest uppercase mb-0.5">Publicações</p>
                    <p className="text-white font-display font-extrabold text-base tracking-tight mb-4">Evolução Diária · {nomeMes(mesRef)}</p>
                    <ResponsiveContainer width="100%" height={220}>
                        <AreaChart data={evolucaoDiaria ?? []} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                            <defs>
                                <linearGradient id="gradDiario" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%"  stopColor="#ffe600" stopOpacity={0.45} />
                                    <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="transparent" />
                            <XAxis dataKey="data" {...chartCfg.axis} axisLine={false} tickLine={false} />
                            <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={28} />
                            <Tooltip {...chartCfg.tooltip} />
                            <Area type="monotone" dataKey="total" name="Publicações" stroke="#ffe600" strokeWidth={2.5} fill="url(#gradDiario)" dot={false} activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>

                {/* Ranking */}
                <div className="card-ecf rounded-2xl p-5 lg:col-span-2">
                    <p className="text-white font-semibold text-sm mb-1">Ranking do Mês</p>
                    <p className="text-white/30 text-xs mb-4">Meta por pessoa</p>
                    <div className="space-y-3">
                        {(ranking ?? []).map((r, i) => (
                            <div key={r.id}>
                                <div className="flex justify-between items-center mb-1">
                                    <span className="text-white/80 text-[13px] font-medium">
                                        {i + 1}. {r.nome}
                                    </span>
                                    <span className="text-white text-[13px] font-bold">{fmt(r.feito)} {r.meta > 0 && <span className="text-white/30 font-normal text-[11px]">/ {fmt(r.meta)}</span>}</span>
                                </div>
                                {r.meta > 0 ? (
                                    <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                        <div
                                            style={{ width:`${Math.min((r.feito/r.meta)*100,100)}%`, background: rankColors(r.feito, r.meta) }}
                                            className="h-full rounded-full"
                                        />
                                    </div>
                                ) : (
                                    <div className="h-1.5 bg-white/[0.04] rounded-full" />
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Gráfico vendas por publicador */}
            <div className="grid grid-cols-1 lg:grid-cols-8 gap-4 mb-6">
                <div className="card-ecf rounded-2xl p-5 lg:col-span-3">
                    <p className="text-white/50 text-[10px] font-semibold tracking-widest uppercase mb-0.5">Equipe</p>
                    <p className="text-white font-display font-extrabold text-base tracking-tight mb-4">Vendas por Publicador · {nomeMes(mesRef)}</p>
                    <ResponsiveContainer width="100%" height={220}>
                        <BarChart data={(ranking ?? []).map(r => ({ nome: r.nome.split(' ')[0], feito: r.feito, vendas: r.vendas }))} barSize={18} barCategoryGap="30%" margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                            <CartesianGrid stroke="transparent" />
                            <XAxis dataKey="nome" {...chartCfg.axis} axisLine={false} tickLine={false} />
                            <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={28} />
                            <Tooltip {...chartCfg.tooltip} />
                            <Bar dataKey="feito"  name="Publicados" fill="#ffe600" fillOpacity={0.5} radius={[3,3,0,0]} />
                            <Bar dataKey="vendas" name="Vendidos"   fill="#22c55e" fillOpacity={0.9} radius={[3,3,0,0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>

                {/* Conversão de Vendas */}
                <div className="card-ecf rounded-2xl p-5 lg:col-span-2 flex flex-col">
                    <p className="text-white/50 text-[10px] font-semibold tracking-widest uppercase mb-0.5">Vendas</p>
                    <p className="text-white font-display font-extrabold text-base tracking-tight mb-2">Conversão de Vendas</p>
                    <p className="text-white/30 text-xs mb-3">{nomeMes(mesRef)}</p>
                    <div className="relative" style={{ height: 160 }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={[
                                        { name: 'Vendidos',     value: Math.max(k.vendas ?? 0, 0.001) },
                                        { name: 'Não vendidos', value: Math.max((k.feito ?? 0) - (k.vendas ?? 0), 0.001) },
                                    ]}
                                    cx="50%" cy="50%"
                                    innerRadius={52} outerRadius={68}
                                    startAngle={90} endAngle={-270}
                                    dataKey="value"
                                    strokeWidth={0}
                                    isAnimationActive={false}
                                >
                                    <Cell fill="#22c55e" />
                                    <Cell fill="rgba(255,255,255,0.08)" />
                                </Pie>
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                            <span className="text-emerald-400 font-display font-extrabold text-2xl leading-none">
                                {fmtDec(k.conversao_vendas)}%
                            </span>
                            <span className="text-white/40 text-[10px] font-semibold tracking-widest uppercase mt-1">
                                VENDIDO
                            </span>
                        </div>
                    </div>
                    <div className="flex items-center justify-center gap-5 mt-3 text-xs text-white/60">
                        <span className="flex items-center gap-1.5">
                            <span className="w-2 h-2 rounded-full bg-emerald-400 inline-block" />
                            Vendidos {fmt(k.vendas ?? 0)}
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="w-2 h-2 rounded-full bg-white/20 inline-block" />
                            Não vendidos {fmt(Math.max((k.feito ?? 0) - (k.vendas ?? 0), 0))}
                        </span>
                    </div>
                </div>

                <div className="card-ecf rounded-2xl p-5 lg:col-span-3">
                    <p className="text-white/50 text-[10px] font-semibold tracking-widest uppercase mb-0.5">Anúncios</p>
                    <p className="text-white font-display font-extrabold text-base tracking-tight mb-4">Evolução Histórica</p>
                    <ResponsiveContainer width="100%" height={220}>
                        <AreaChart data={evolucaoMensal ?? []} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                            <defs>
                                <linearGradient id="gradPub" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%"  stopColor="#ffe600" stopOpacity={0.45} />
                                    <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="transparent" />
                            <XAxis dataKey="mes" {...chartCfg.axis} axisLine={false} tickLine={false} />
                            <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={36} />
                            <Tooltip {...chartCfg.tooltip} formatter={(v, n) => [fmt(v), n]} />
                            <Area type="monotone" dataKey="total"  name="Anúncios" stroke="#ffe600" strokeWidth={2.5} fill="url(#gradPub)" dot={false} activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }} />
                            <Area type="monotone" dataKey="vendas" name="Vendas"   stroke="#22c55e" strokeWidth={2}   fill="none" strokeDasharray="4 2" dot={false} activeDot={{ r: 4, fill: '#22c55e', strokeWidth: 0 }} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            </div>

            {/* Distribuição de empresas */}
            <DistribuicaoChart distribuicao={distribuicao} totalEmpresas={totalEmpresas ?? 0} />

            {/* Empresas por estágio */}
            <EstagiosChart estagiosDistrib={estagiosDistrib} totalTodasEmpresas={totalTodasEmpresas ?? 0} />

            {/* Listagem de empresas (com vs sem SKU) */}
            <ListagemCard listagem={listagem} />

            {/* Relatório de Atrasos */}
            <AtrasoChart relatorioAtrasos={relatorioAtrasos} />

            {/* Tabela detalhe por publicador */}
            <div className="card-ecf rounded-2xl p-5">
                <p className="text-white font-semibold text-sm mb-4">Detalhamento por Publicador</p>
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-white/[0.06]">
                                {['Publicador','Meta','Publicados','Vendidos','Unidades','Faltam','Projeção','Status'].map(h => (
                                    <th key={h} className="text-left text-white/40 font-semibold py-2 pr-4">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {(ranking ?? []).map(r => (
                                <tr key={r.id} className="border-b border-white/[0.03] hover:bg-white/[0.02]">
                                    <td className="py-3 pr-4 text-white font-medium">{r.nome}</td>
                                    <td className="py-3 pr-4 text-white/50">{fmt(r.meta)}</td>
                                    <td className="py-3 pr-4 text-white font-bold">{fmt(r.feito)}</td>
                                    <td className="py-3 pr-4 text-emerald-400 font-bold">{fmt(r.vendas)}</td>
                                    <td className="py-3 pr-4 text-teal-400">{fmt(r.vendas_qty ?? 0)} un.</td>
                                    <td className="py-3 pr-4 text-white/50">{fmt(Math.max(r.meta - r.feito, 0))}</td>
                                    <td className="py-3 pr-4 text-white/50">
                                        {fmt(Math.round((r.feito / Math.max(k.dias_uteis_decorridos,1)) * k.dias_uteis_total))}
                                    </td>
                                    <td className="py-3">
                                        <StatusBadge
                                            status={r.feito >= r.meta ? 'Acima' : r.feito >= r.meta * 0.85 ? 'No alvo' : 'Abaixo'}
                                            classe={r.feito >= r.meta ? 'above' : r.feito >= r.meta * 0.85 ? 'ontrack' : 'below'}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
