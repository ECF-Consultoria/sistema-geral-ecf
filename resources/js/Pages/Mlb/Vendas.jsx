import AppLayout from '@/Layouts/AppLayout';
import { router, useForm } from '@inertiajs/react';
import { fmtBRL } from '@/Components/TicketMedioChart';
import { useState, useEffect } from 'react';
import {
    PieChart, Pie, Cell, BarChart, Bar, LineChart, Line,
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    ReferenceLine, LabelList
} from 'recharts';
import {
    RefreshCw, X, ShoppingCart, Package, TrendingUp,
    CheckCircle2, XCircle, ExternalLink
} from 'lucide-react';
import { cn } from '@/lib/utils';

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
function mlbUrl(c) { return `https://produto.mercadolivre.com.br/${c.replace('MLB','MLB-')}`; }

const chartCfg = {
    tooltip: { contentStyle: { background:'#0f1116', border:'1px solid rgba(255,255,255,0.07)', borderRadius:10, fontSize:12 }, labelStyle: { color:'#9ba0aa' } },
    axis:    { tick: { fill:'#6a6f79', fontSize:11 } },
};

function KpiCard({ title, value, sub, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow:  { text:'text-ecf-yellow',   bg:'bg-ecf-yellow/10',   border:'border-ecf-yellow/20'   },
        green:   { text:'text-emerald-400',  bg:'bg-emerald-500/10',  border:'border-emerald-500/20'  },
        red:     { text:'text-red-400',      bg:'bg-red-500/10',      border:'border-red-500/20'      },
        blue:    { text:'text-blue-400',     bg:'bg-blue-500/10',     border:'border-blue-500/20'     },
        purple:  { text:'text-purple-400',   bg:'bg-purple-500/10',   border:'border-purple-500/20'   },
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-2xl p-5">
            <div className="flex items-center justify-between mb-3">
                <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide">{title}</p>
                <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center border', c.bg, c.border)}>
                    <Icon size={15} className={c.text} />
                </div>
            </div>
            <p className={cn('font-display font-extrabold text-3xl tracking-tight', c.text)}>{value}</p>
            {sub && <p className="text-white/30 text-xs mt-1">{sub}</p>}
        </div>
    );
}

/* ── Modal de Sync Total ── */
function SyncModal({ empresasSync, mesRef, onClose }) {
    const hoje = new Date().toISOString().slice(0, 10);
    // Sync travado ao mês exibido — janelas largas (ano/histórico) inflavam o
    // vendas_qty com totais cumulativos. O período é sempre o mês selecionado.
    const [anoMes, mesNum] = mesRef.split('-');
    const ultimoDiaMes     = new Date(Number(anoMes), Number(mesNum), 0).getDate();
    const ehMesAtual       = mesRef === hoje.slice(0, 7);
    const dateFrom         = `${mesRef}-01`;
    const dateTo           = ehMesAtual ? hoje : `${mesRef}-${String(ultimoDiaMes).padStart(2, '0')}`;

    const { post, processing } = useForm({
        date_from: dateFrom,
        date_to:   dateTo,
    });

    const empresasList = Object.entries(empresasSync ?? {});

    function submit(e) {
        e.preventDefault();
        post(route('mlb.sync-vendas-pub'), { onSuccess: onClose });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-md p-6">
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <h2 className="text-white font-bold text-base flex items-center gap-2">
                            <RefreshCw size={16} className="text-ecf-yellow" />
                            Sync Total de Vendas
                        </h2>
                        <p className="text-white/40 text-[12px] mt-0.5">
                            Busca vendas de todas as suas contas no Mercado Livre
                        </p>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>

                {empresasList.length === 0 ? (
                    <p className="text-white/40 text-[13px] py-4 text-center">
                        Nenhuma empresa com Cust ID atribuída a você.
                    </p>
                ) : (
                    <>
                        <div className="mb-4 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                            <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-2">Contas que serão sincronizadas</p>
                            <div className="space-y-1">
                                {empresasList.map(([id, nome]) => (
                                    <div key={id} className="flex items-center gap-2 text-[12px]">
                                        <div className="w-1.5 h-1.5 rounded-full bg-ecf-yellow/60 shrink-0" />
                                        <span className="text-white/70">{nome}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <form onSubmit={submit} className="space-y-4">
                            {/* Período travado ao mês exibido */}
                            <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-1">Período</p>
                                <p className="text-white text-[13px] font-semibold">{nomeMes(mesRef)}</p>
                                <p className="text-white/30 text-[11px] mt-0.5">
                                    {dateFrom.split('-').reverse().join('/')} a {dateTo.split('-').reverse().join('/')}
                                    {' · '}sincroniza só as vendas deste mês
                                </p>
                            </div>

                            <div className="flex gap-3">
                                <button type="submit" disabled={processing}
                                    className="flex-1 h-10 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] disabled:opacity-50 hover:bg-ecf-yellow/90 transition-colors flex items-center justify-center gap-2">
                                    <RefreshCw size={14} className={processing ? 'animate-spin' : ''} />
                                    {processing ? 'Sincronizando…' : 'Sincronizar Tudo'}
                                </button>
                                <button type="button" onClick={onClose}
                                    className="h-10 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </>
                )}
            </div>
        </div>
    );
}

/* ── Tooltip customizado para o Pie ── */
function PieTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;
    const d = payload[0];
    return (
        <div className="rounded-xl border border-white/[0.08] bg-[#0f1116] px-3 py-2 text-[12px]">
            <p style={{ color: d.payload.fill }} className="font-bold">{d.name}</p>
            <p className="text-white/80">{fmt(d.value)} anúncio(s)</p>
        </div>
    );
}

const MESES_PT_ABREV = { '01':'Jan','02':'Fev','03':'Mar','04':'Abr','05':'Mai','06':'Jun','07':'Jul','08':'Ago','09':'Set','10':'Out','11':'Nov','12':'Dez' };
function abrevMes(ym) { const [y,m] = (ym||'').split('-'); return `${MESES_PT_ABREV[m]||m}/${(y||'').slice(2)}`; }

const COLORS_PUB = ['#ffe600','#22c55e','#3b82f6','#f97316','#a855f7','#ec4899','#14b8a6','#f43f5e'];

function TooltipTicket({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    return (
        <div style={{ background:'#0f1116', border:'1px solid rgba(255,255,255,0.08)', borderRadius:10, padding:'8px 12px', fontSize:12 }}>
            <p className="text-white/50 text-[11px] mb-1">{label}</p>
            {payload.map((p, i) => (
                <p key={i} style={{ color: p.color }} className="font-bold">{p.name}: {fmtBRL(p.value)}</p>
            ))}
        </div>
    );
}

function TicketChart({ ticketData, nomeMes, mesRef }) {
    if (!ticketData) return null;

    const { tipo, porPub, ticketGeral, evolucao } = ticketData;

    // ── Visão Geral (Admin/Gestor): ranking de publicadores no mês ──────────
    if (tipo === 'geral') {
        if (!porPub?.length) return (
            <div className="card-ecf rounded-2xl p-5 mb-6">
                <p className="text-white font-semibold text-sm mb-1">Ticket Médio por Publicador</p>
                <p className="text-white/30 text-[12px]">Sem dados de ticket para o período selecionado. Rode o sync de vendas com preço.</p>
            </div>
        );

        return (
            <div className="card-ecf rounded-2xl p-5 mb-6">
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <p className="text-white font-semibold text-sm">Ticket Médio por Publicador</p>
                        <p className="text-white/30 text-[11px] mt-0.5">{nomeMes(mesRef)} · preço líquido médio por unidade vendida</p>
                    </div>
                    {ticketGeral > 0 && (
                        <div className="text-right">
                            <p className="text-white/30 text-[11px]">Ticket geral</p>
                            <p className="text-ecf-yellow font-display font-bold text-xl">{fmtBRL(ticketGeral)}</p>
                        </div>
                    )}
                </div>
                <div className="space-y-3">
                    {porPub.map((p, i) => {
                        const pct = ticketGeral > 0 ? Math.min((p.ticket / ticketGeral) * 100, 150) : 100;
                        const cor = COLORS_PUB[i % COLORS_PUB.length];
                        return (
                            <div key={p.nome}>
                                <div className="flex items-center justify-between text-[12px] mb-1">
                                    <div className="flex items-center gap-2">
                                        <span className="w-2 h-2 rounded-full shrink-0" style={{ background: cor }} />
                                        <span className="text-white/70 font-medium">{p.nome}</span>
                                    </div>
                                    <div className="flex items-center gap-4 text-[11px]">
                                        <span className="text-white/40">{p.qty} un · {fmtBRL(p.bill)}</span>
                                        <span className="font-bold w-24 text-right" style={{ color: cor }}>{fmtBRL(p.ticket)}</span>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="flex-1 h-2 bg-white/10 rounded-full overflow-hidden">
                                        <div className="h-full rounded-full transition-all" style={{ width: `${Math.min(pct, 100)}%`, background: cor }} />
                                    </div>
                                    {ticketGeral > 0 && (
                                        <span className={cn('text-[10px] w-12 text-right font-semibold',
                                            p.ticket >= ticketGeral ? 'text-emerald-400' : 'text-white/30')}>
                                            {p.ticket >= ticketGeral ? '+' : ''}{Math.round(((p.ticket / ticketGeral) - 1) * 100)}%
                                        </span>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    }

    // ── Visão Individual (Publicador): evolução mensal ──────────────────────
    if (!evolucao?.length) return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <p className="text-white font-semibold text-sm mb-1">Meu Ticket Médio</p>
            <p className="text-white/30 text-[12px]">Sem histórico de ticket ainda. Rode o sync de vendas com preço.</p>
        </div>
    );

    const chartData = evolucao.map(e => ({ mes: abrevMes(e.mes), ticket: e.ticket, qty: e.qty }));
    const maxTicket = Math.max(...evolucao.map(e => e.ticket));
    const avgTicket = evolucao.reduce((s, e) => s + e.ticket, 0) / evolucao.length;

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-center justify-between mb-4">
                <div>
                    <p className="text-white font-semibold text-sm">Meu Ticket Médio</p>
                    <p className="text-white/30 text-[11px] mt-0.5">Evolução mensal · preço líquido médio por unidade vendida</p>
                </div>
                {ticketGeral > 0 && (
                    <div className="text-right">
                        <p className="text-white/30 text-[11px]">Mês atual</p>
                        <p className="text-ecf-yellow font-display font-bold text-xl">{fmtBRL(ticketGeral)}</p>
                    </div>
                )}
            </div>
            <ResponsiveContainer width="100%" height={220}>
                <LineChart data={chartData} margin={{ top: 8, right: 16, left: 0, bottom: 8 }}>
                    <CartesianGrid stroke="rgba(255,255,255,0.04)" strokeDasharray="4 4" />
                    <XAxis dataKey="mes" tick={{ fill:'#6a6f79', fontSize:11 }} axisLine={false} tickLine={false} />
                    <YAxis tickFormatter={v => `R$${Number(v).toLocaleString('pt-BR',{maximumFractionDigits:0})}`}
                        tick={{ fill:'#6a6f79', fontSize:11 }} axisLine={false} tickLine={false} width={60} />
                    <Tooltip content={<TooltipTicket />} />
                    <ReferenceLine y={avgTicket} stroke="rgba(255,255,255,0.15)" strokeDasharray="4 4"
                        label={{ value: `Média ${fmtBRL(avgTicket)}`, fill:'rgba(255,255,255,0.3)', fontSize:10, position:'insideTopRight' }} />
                    <Line type="monotone" dataKey="ticket" name="Ticket médio" stroke="#ffe600"
                        strokeWidth={2} dot={{ fill:'#ffe600', r:4 }} activeDot={{ r:6 }}>
                        <LabelList dataKey="ticket" position="top"
                            formatter={v => `R$${Number(v).toLocaleString('pt-BR',{maximumFractionDigits:0})}`}
                            style={{ fill:'rgba(255,255,255,0.5)', fontSize:9 }} />
                    </Line>
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}

export default function Vendas({ kpis, porEmpresa, topMlbs, lista, mesRef, meses, empresasSync, isGeral = false, ticketData = null, lojasVenderam = [], lojasNaoVenderam = [], publicadores = [], pubFiltro = null }) {
    const k = kpis ?? {};
    const [syncModal, setSyncModal] = useState(false);
    const [busca, setBusca] = useState('');
    const [filtro, setFiltro] = useState('todos'); // todos | vendidos | nao_vendidos
    const [verLojasVenderam, setVerLojasVenderam] = useState(false);
    const [verLojasNaoVenderam, setVerLojasNaoVenderam] = useState(false);

    const { props } = typeof window !== 'undefined' ? { props: {} } : { props: {} };

    // Publicador selecionado (modo geral): permite alternar entre consolidado e individual.
    const pubSelecionado = publicadores.find(p => String(p.id) === String(pubFiltro)) ?? null;

    function navegar(params) {
        router.get(route('mlb.vendas'),
            { mes: mesRef, pub: pubFiltro || undefined, ...params },
            { preserveState: true, preserveScroll: true });
    }
    function handleMes(e) { navegar({ mes: e.target.value }); }
    function handlePub(e) { navegar({ pub: e.target.value || undefined }); }

    const pieData = [
        { name: 'Vendidos',      value: k.vendidos  ?? 0, fill: '#22c55e' },
        { name: 'Não Vendidos',  value: k.nVendidos ?? 0, fill: '#3b3f4a' },
    ];

    const listaFiltrada = (lista ?? []).filter(p => {
        const matchBusca = !busca ||
            p.mlb_code.toLowerCase().includes(busca.toLowerCase()) ||
            (p.empresa ?? '').toLowerCase().includes(busca.toLowerCase());
        const matchFiltro = filtro === 'todos'
            || (filtro === 'vendidos'    && p.vendido)
            || (filtro === 'nao_vendidos' && !p.vendido);
        return matchBusca && matchFiltro;
    });

    return (
        <AppLayout title="Vendas">
            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">
                        {isGeral
                            ? (pubSelecionado ? `Vendas · ${pubSelecionado.nome}` : 'Vendas · Geral')
                            : 'Vendas'}
                    </h1>
                    <p className="text-white/40 text-sm mt-0.5">
                        {isGeral
                            ? (pubSelecionado
                                ? `Visão individual de ${pubSelecionado.nome}`
                                : 'Visão consolidada de todos os publicadores')
                            : 'Acompanhe quais anúncios venderam e as quantidades'}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {isGeral && (
                        <select value={pubFiltro ?? ''} onChange={handlePub}
                            className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer max-w-[200px]">
                            <option value="">Todos os publicadores</option>
                            {publicadores.map(p => (
                                <option key={p.id} value={p.id}>{p.nome}</option>
                            ))}
                        </select>
                    )}
                    <select value={mesRef} onChange={handleMes}
                        className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer">
                        {(meses ?? []).map(m => (
                            <option key={m} value={m}>{nomeMes(m)}</option>
                        ))}
                    </select>
                    {!isGeral && (
                        <button
                            onClick={() => setSyncModal(true)}
                            className="h-9 px-5 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] flex items-center gap-2 hover:bg-ecf-yellow/90 transition-colors">
                            <RefreshCw size={14} /> Sync Total
                        </button>
                    )}
                </div>
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard title="Registrados"  value={fmt(k.total)}            sub="anúncios no mês"        icon={Package}      color="yellow" />
                <KpiCard title="Vendidos"     value={fmt(k.vendidos)}         sub={`${k.conversao ?? 0}% conversão`} icon={CheckCircle2} color="green" />
                <KpiCard title="Não Vendidos" value={fmt(k.nVendidos)}        sub="sem registro de venda"  icon={XCircle}      color="red" />
                <KpiCard title="Unidades"     value={fmt(k.unidades)}         sub="peças vendidas"         icon={ShoppingCart} color="blue" />
                <KpiCard title="Faturamento"  value={k.faturamento > 0 ? fmtBRL(k.faturamento) : '—'} sub="líquido total" icon={TrendingUp} color="green" />
                <KpiCard title="Ticket Médio" value={k.ticketMedioGeral > 0 ? fmtBRL(k.ticketMedioGeral) : '—'} sub="preço médio/unidade" icon={ShoppingCart} color="purple" />
            </div>

            {/* Gráficos */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-6">

                {/* Donut — conversão */}
                <div className="card-ecf rounded-2xl p-5 lg:col-span-2 flex flex-col">
                    <p className="text-white font-semibold text-sm mb-1">Conversão de Vendas</p>
                    <p className="text-white/30 text-[11px] mb-4">{nomeMes(mesRef)}</p>
                    <div className="flex-1 flex items-center justify-center">
                        <div className="relative">
                            <ResponsiveContainer width={200} height={200}>
                                <PieChart>
                                    <Pie
                                        data={pieData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={60}
                                        outerRadius={90}
                                        paddingAngle={2}
                                        dataKey="value"
                                    >
                                        {pieData.map((entry, i) => (
                                            <Cell key={i} fill={entry.fill} />
                                        ))}
                                    </Pie>
                                    <Tooltip content={<PieTooltip />} />
                                </PieChart>
                            </ResponsiveContainer>
                            {/* Centro */}
                            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                                <div className="text-center">
                                    <p className="text-emerald-400 font-display font-extrabold text-2xl">{k.conversao ?? 0}%</p>
                                    <p className="text-white/30 text-[10px] uppercase tracking-wide">vendido</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center justify-center gap-6 mt-2">
                        <div className="flex items-center gap-2 text-[12px]">
                            <div className="w-2.5 h-2.5 rounded-full bg-emerald-500" />
                            <span className="text-white/60">Vendidos <span className="text-emerald-400 font-bold">{fmt(k.vendidos)}</span></span>
                        </div>
                        <div className="flex items-center gap-2 text-[12px]">
                            <div className="w-2.5 h-2.5 rounded-full bg-white/20" />
                            <span className="text-white/60">Não vendidos <span className="text-white/50 font-bold">{fmt(k.nVendidos)}</span></span>
                        </div>
                    </div>
                </div>

                {/* Bar — por empresa */}
                <div className="card-ecf rounded-2xl p-5 lg:col-span-3">
                    <p className="text-white font-semibold text-sm mb-1">Vendas por Empresa</p>
                    <p className="text-white/30 text-[11px] mb-4">unidades vendidas</p>
                    {(porEmpresa ?? []).length === 0 ? (
                        <div className="flex items-center justify-center h-48 text-white/20 text-sm">Sem dados de venda</div>
                    ) : (
                        <ResponsiveContainer width="100%" height={220}>
                            <BarChart
                                data={(porEmpresa ?? []).slice(0, 8).map(e => ({
                                    nome:     e.nome.length > 14 ? e.nome.slice(0, 14) + '…' : e.nome,
                                    vendidos: e.vendidos,
                                    nao:      e.nVendidos,
                                    unidades: e.unidades,
                                }))}
                                margin={{ top: 4, right: 4, left: 0, bottom: 40 }}
                            >
                                <CartesianGrid stroke="rgba(255,255,255,0.04)" strokeDasharray="4 4" />
                                <XAxis dataKey="nome" {...chartCfg.axis} axisLine={false} tickLine={false} angle={-30} textAnchor="end" interval={0} />
                                <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={28} />
                                <Tooltip {...chartCfg.tooltip} />
                                <Bar dataKey="vendidos" name="Vendidos"     stackId="a" fill="#22c55e" radius={[0,0,0,0]} />
                                <Bar dataKey="nao"       name="Não vendidos" stackId="a" fill="#3b3f4a" radius={[4,4,0,0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    )}
                </div>
            </div>

            {/* Ticket Médio */}
            <TicketChart ticketData={ticketData} nomeMes={nomeMes} mesRef={mesRef} />

            {/* Lojas com/sem venda */}
            {(lojasVenderam.length > 0 || lojasNaoVenderam.length > 0) && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

                    {/* Lojas que venderam */}
                    <div className="card-ecf rounded-2xl p-5">
                        <div className="flex items-center justify-between mb-4">
                            <p className="text-white font-semibold text-sm">
                                Lojas com Venda
                                <span className="ml-2 px-2 py-0.5 rounded-full text-[10px] bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 font-bold">{lojasVenderam.length}</span>
                            </p>
                            <div className="flex items-center gap-3">
                                <span className="text-white/30 text-[11px]">{nomeMes(mesRef)}</span>
                                {lojasVenderam.length > 0 && (
                                    <button
                                        onClick={() => setVerLojasVenderam(v => !v)}
                                        className="h-7 px-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-400 text-[11px] font-semibold hover:bg-emerald-500/20 transition-colors"
                                    >
                                        {verLojasVenderam ? 'Ocultar' : 'Ver lojas'}
                                    </button>
                                )}
                            </div>
                        </div>
                        {lojasVenderam.length === 0 ? (
                            <p className="text-white/25 text-[12px] text-center py-4">Nenhuma loja vendeu ainda</p>
                        ) : verLojasVenderam ? (
                            <div className="space-y-2.5">
                                {lojasVenderam.map((l, i) => (
                                    <div key={i} className="flex items-center justify-between gap-3">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0" />
                                            <span className="text-white/80 text-[13px] truncate">{l.nome}</span>
                                        </div>
                                        <div className="flex items-center gap-3 shrink-0 text-[11px]">
                                            <span className="text-white/35">{l.qty} un.</span>
                                            {l.billing > 0 && (
                                                <span className="text-ecf-yellow font-semibold">{fmtBRL(l.billing)}</span>
                                            )}
                                            {l.ticket > 0 && (
                                                <span className="text-white/50 w-20 text-right">T. {fmtBRL(l.ticket)}</span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-white/25 text-[12px] text-center py-4">Clique em "Ver lojas" para exibir</p>
                        )}
                    </div>

                    {/* Lojas que não venderam */}
                    <div className="card-ecf rounded-2xl p-5">
                        <div className="flex items-center justify-between mb-4">
                            <p className="text-white font-semibold text-sm">
                                Lojas sem Venda
                                <span className="ml-2 px-2 py-0.5 rounded-full text-[10px] bg-red-500/15 text-red-400 border border-red-500/30 font-bold">{lojasNaoVenderam.length}</span>
                            </p>
                            <div className="flex items-center gap-3">
                                <span className="text-white/30 text-[11px]">{nomeMes(mesRef)}</span>
                                {lojasNaoVenderam.length > 0 && (
                                    <button
                                        onClick={() => setVerLojasNaoVenderam(v => !v)}
                                        className="h-7 px-3 rounded-lg border border-red-500/30 bg-red-500/10 text-red-400 text-[11px] font-semibold hover:bg-red-500/20 transition-colors"
                                    >
                                        {verLojasNaoVenderam ? 'Ocultar' : 'Ver lojas'}
                                    </button>
                                )}
                            </div>
                        </div>
                        {lojasNaoVenderam.length === 0 ? (
                            <p className="text-emerald-400 text-[12px] text-center py-4">Todas as lojas venderam! 🎉</p>
                        ) : verLojasNaoVenderam ? (
                            <div className="space-y-2">
                                {lojasNaoVenderam.map((l, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <span className="w-1.5 h-1.5 rounded-full bg-white/20 shrink-0" />
                                        <span className="text-white/40 text-[13px] truncate">{l.nome}</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-white/25 text-[12px] text-center py-4">Clique em "Ver lojas" para exibir</p>
                        )}
                    </div>
                </div>
            )}

            {/* Top MLBs por unidades */}
            {(topMlbs ?? []).length > 0 && (
                <div className="card-ecf rounded-2xl p-5 mb-6">
                    <p className="text-white font-semibold text-sm mb-1">Top Anúncios por Unidades Vendidas</p>
                    <p className="text-white/30 text-[11px] mb-4">anúncios com mais unidades no período</p>
                    <ResponsiveContainer width="100%" height={220}>
                        <BarChart
                            data={(topMlbs ?? []).map(m => ({
                                mlb:      m.mlb_code.replace('MLB', ''),
                                unidades: m.unidades,
                                empresa:  m.empresa,
                            }))}
                            margin={{ top: 4, right: 4, left: 0, bottom: 40 }}
                        >
                            <CartesianGrid stroke="rgba(255,255,255,0.04)" strokeDasharray="4 4" />
                            <XAxis dataKey="mlb" {...chartCfg.axis} axisLine={false} tickLine={false} angle={-30} textAnchor="end" interval={0} />
                            <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={28} />
                            <Tooltip {...chartCfg.tooltip} formatter={(v, n, p) => [v + ' un.', p.payload.empresa]} />
                            <Bar dataKey="unidades" name="Unidades" fill="#8b5cf6" radius={[4,4,0,0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}

            {/* Tabela completa */}
            <div className="card-ecf rounded-2xl p-5">
                <div className="flex items-center justify-between mb-4 gap-3 flex-wrap">
                    <p className="text-white font-semibold text-sm">
                        Todos os Anúncios
                        <span className="text-white/30 font-normal ml-2 text-[12px]">{listaFiltrada.length} exibidos</span>
                    </p>
                    <div className="flex items-center gap-2 flex-wrap">
                        {/* Filtro status */}
                        <div className="flex rounded-xl overflow-hidden border border-white/[0.08]">
                            {[
                                { key: 'todos',       label: 'Todos' },
                                { key: 'vendidos',    label: 'Vendidos' },
                                { key: 'nao_vendidos',label: 'Não vendidos' },
                            ].map(f => (
                                <button key={f.key} onClick={() => setFiltro(f.key)}
                                    className={cn(
                                        'h-8 px-3 text-[12px] font-semibold transition-colors',
                                        filtro === f.key
                                            ? 'bg-ecf-yellow text-[#252525]'
                                            : 'text-white/40 hover:text-white/70 bg-transparent'
                                    )}>
                                    {f.label}
                                </button>
                            ))}
                        </div>
                        {/* Busca */}
                        <input type="text" value={busca} onChange={e => setBusca(e.target.value)}
                            placeholder="Buscar MLB ou empresa…"
                            className="h-8 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/80 focus:outline-none placeholder:text-white/20 min-w-[180px]" />
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-white/[0.06]">
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Anúncio</th>
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Empresa</th>
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Data</th>
                                <th className="text-center text-white/40 font-semibold py-2 pr-4">Status</th>
                                <th className="text-center text-white/40 font-semibold py-2 pr-4">Unid.</th>
                                <th className="text-right text-white/40 font-semibold py-2 pr-4">Preço unit.</th>
                                <th className="text-right text-white/40 font-semibold py-2">Fat. líquido</th>
                            </tr>
                        </thead>
                        <tbody>
                            {listaFiltrada.map(p => (
                                <tr key={p.id} className="border-b border-white/[0.03] hover:bg-white/[0.02]">
                                    <td className="py-2.5 pr-4">
                                        <a href={mlbUrl(p.mlb_code)} target="_blank" rel="noopener noreferrer"
                                            className="text-purple-400 font-mono text-[12px] hover:text-purple-300 flex items-center gap-1">
                                            {p.mlb_code} <ExternalLink size={10} />
                                        </a>
                                    </td>
                                    <td className="py-2.5 pr-4 text-white/70 max-w-[180px] truncate">{p.empresa}</td>
                                    <td className="py-2.5 pr-4 text-white/40 text-[12px]">{p.data}</td>
                                    <td className="py-2.5 pr-4 text-center">
                                        {p.vendido ? (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-[10px] font-bold">
                                                <CheckCircle2 size={9} /> Vendido
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-white/[0.04] border border-white/[0.08] text-white/30 text-[10px] font-bold">
                                                <XCircle size={9} /> Não vendido
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2.5 pr-4 text-center">
                                        {p.vendas_qty > 0 ? (
                                            <span className="text-emerald-400 font-bold text-[13px]">{fmt(p.vendas_qty)}</span>
                                        ) : (
                                            <span className="text-white/20">—</span>
                                        )}
                                    </td>
                                    <td className="py-2.5 pr-4 text-right text-[12px]">
                                        {p.preco_unitario != null ? (
                                            <span className="text-white/70">R$ {Number(p.preco_unitario).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
                                        ) : <span className="text-white/20">—</span>}
                                    </td>
                                    <td className="py-2.5 text-right text-[12px]">
                                        {p.net_billing != null ? (
                                            <span className="text-ecf-yellow font-semibold">R$ {Number(p.net_billing).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
                                        ) : <span className="text-white/20">—</span>}
                                    </td>
                                </tr>
                            ))}
                            {listaFiltrada.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="py-12 text-center text-white/20">
                                        Nenhum anúncio encontrado.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Modal Sync */}
            {syncModal && (
                <SyncModal
                    empresasSync={empresasSync}
                    mesRef={mesRef}
                    onClose={() => setSyncModal(false)}
                />
            )}
        </AppLayout>
    );
}
