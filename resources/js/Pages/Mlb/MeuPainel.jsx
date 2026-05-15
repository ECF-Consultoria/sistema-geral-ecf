import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { TicketIndividualChart } from '@/Components/TicketMedioChart';
import { BarChart, Bar, AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell } from 'recharts';
import { Target, CheckCircle, Clock, TrendingUp, MessageSquare, ShoppingCart, Package, CheckCheck, AlertTriangle } from 'lucide-react';
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
function fmtDec(n) { return Number(n ?? 0).toFixed(1).replace('.', ','); }

function KpiCard({ title, value, sub, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow: { text:'text-ecf-yellow', bg:'bg-ecf-yellow/10', border:'border-ecf-yellow/20' },
        green:  { text:'text-emerald-400', bg:'bg-emerald-500/10', border:'border-emerald-500/20' },
        orange: { text:'text-orange-400', bg:'bg-orange-500/10', border:'border-orange-500/20' },
        purple: { text:'text-purple-400', bg:'bg-purple-500/10', border:'border-purple-500/20' },
        blue:   { text:'text-blue-400',   bg:'bg-blue-500/10',   border:'border-blue-500/20'   },
        teal:   { text:'text-teal-400',   bg:'bg-teal-500/10',   border:'border-teal-500/20'   },
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

const chartCfg = {
    tooltip: { contentStyle: { background:'#0f1116', border:'1px solid rgba(255,255,255,0.07)', borderRadius:10, fontSize:12 }, labelStyle: { color:'#9ba0aa' } },
    grid:    { stroke:'rgba(255,255,255,0.04)', strokeDasharray:'4 4' },
    axis:    { tick: { fill:'#6a6f79', fontSize:11 } },
};

const PRIORIDADE_STYLE = {
    '1 Urgente': 'bg-red-500/20 text-red-400 border-red-500/30',
    '2 Alto':    'bg-orange-500/20 text-orange-400 border-orange-500/30',
    '3 Média':   'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    '4 Baixa':   'bg-white/5 text-white/40 border-white/10',
};

function ProblemasSection({ problemas, onResolverAnuncio }) {
    if (!problemas) return null;
    const { empresas, anuncios } = problemas;
    if (!empresas?.length && !anuncios?.length) return null;
    const total = (empresas?.length ?? 0) + (anuncios?.length ?? 0);

    return (
        <div className="rounded-2xl border border-red-500/25 bg-red-500/[0.03] p-5 mb-6">
            <div className="flex items-center gap-2 mb-4">
                <div className="w-7 h-7 rounded-lg bg-red-500/15 border border-red-500/30 flex items-center justify-center shrink-0">
                    <AlertTriangle size={13} className="text-red-400" />
                </div>
                <div>
                    <p className="text-white font-semibold text-sm leading-none">Problemas em Aberto</p>
                    <p className="text-red-400/70 text-[11px] mt-0.5">{total} item{total !== 1 ? 's' : ''} precisam de atenção</p>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Contas */}
                {empresas?.length > 0 && (
                    <div>
                        <p className="text-white/30 text-[10px] font-semibold tracking-widest uppercase mb-2">
                            Contas ({empresas.length})
                        </p>
                        <div className="space-y-2">
                            {empresas.map(e => (
                                <div key={e.id} className="rounded-xl border border-red-500/20 bg-red-500/5 p-3">
                                    <div className="flex items-center justify-between gap-2 mb-1">
                                        <span className="text-white font-medium text-[13px] truncate">{e.nome}</span>
                                        {e.prioridade && (
                                            <span className={cn('shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded border', PRIORIDADE_STYLE[e.prioridade] ?? PRIORIDADE_STYLE['4 Baixa'])}>
                                                {e.prioridade}
                                            </span>
                                        )}
                                    </div>
                                    {e.nota && (
                                        <p className="text-white/60 text-[12px] mt-0.5">↳ {e.nota}</p>
                                    )}
                                    <p className="text-white/25 text-[10px] mt-1.5">{e.projeto} · {e.em}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Anúncios */}
                {anuncios?.length > 0 && (
                    <div>
                        <p className="text-white/30 text-[10px] font-semibold tracking-widest uppercase mb-2">
                            Anúncios ({anuncios.length})
                        </p>
                        <div className="space-y-2">
                            {anuncios.map(a => (
                                <div key={a.id} className="rounded-xl border border-orange-500/20 bg-orange-500/5 p-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 mb-1">
                                                <span className="text-white font-medium text-[13px] truncate">{a.empresa}</span>
                                                <a
                                                    href={`https://produto.mercadolivre.com.br/${a.mlb_code.replace('MLB', 'MLB-')}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="shrink-0 text-purple-400 font-mono text-[11px] hover:text-purple-300 transition-colors"
                                                >
                                                    {a.mlb_code}
                                                </a>
                                            </div>
                                            {a.nota && (
                                                <p className="text-white/60 text-[12px] mt-0.5">↳ {a.nota}</p>
                                            )}
                                            <p className="text-white/25 text-[10px] mt-1.5">Publicado em {a.data_pub} · Marcado em {a.em}</p>
                                        </div>
                                        {onResolverAnuncio && (
                                            <button
                                                onClick={() => onResolverAnuncio(a.id)}
                                                className="shrink-0 flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors"
                                            >
                                                <CheckCheck size={11} /> Marcar resolvido
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function MeuPainel({ kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef, meses, problemas, ticketEvolucao = [], ticketAtual = 0 }) {
    const k = kpis ?? {};

    const handleMes = (e) => {
        router.get(route('mlb.meu-painel'), { mes: e.target.value }, { preserveState: true });
    };

    function resolverComentario(id) {
        router.patch(route('mlb.resolver', id), {}, { preserveScroll: true });
    }

    function handleResolverAnuncio(id) {
        router.patch(route('mlb.problema', id), {}, { preserveScroll: true });
    }

    const statusColors = { above:'#22c55e', ontrack:'#eab308', below:'#ef4444' };
    const barColor = statusColors[k.status_classe] || '#8b5cf6';

    return (
        <AppLayout title="Meu Painel">
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Meu Painel</h1>
                    <p className="text-white/40 text-sm mt-0.5">Acompanhe sua produtividade em tempo real</p>
                </div>
                <select
                    value={mesRef}
                    onChange={handleMes}
                    className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    {(meses ?? []).map(m => (
                        <option key={m} value={m}>{nomeMes(m)}</option>
                    ))}
                </select>
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
                <KpiCard title="Meta do Mês"       value={fmt(k.meta)}                           sub="anúncios esperados"              icon={Target}      color="yellow" />
                <KpiCard title="Feito"             value={fmt(k.feito)}                          sub={`${fmtDec(k.percentual)}% da meta`} icon={CheckCircle} color="green" />
                <KpiCard title="Faltam"            value={fmt(k.faltantes)}                      sub={`${k.dias_uteis_restantes} dias úteis`} icon={Clock}  color="orange" />
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                <KpiCard title="Anúncios Vendidos" value={fmt(k.vendas)}                         sub={`${fmtDec(k.conversao_vendas)}% conversão`} icon={ShoppingCart} color="blue" />
                <KpiCard title="Unidades Vendidas" value={fmt(k.vendas_qty)}                     sub="total de peças vendidas"         icon={Package}     color="teal" />
                <KpiCard title="Ritmo Necessário"  value={`${fmtDec(k.media_diaria_alvo)}/dia`} sub="para bater a meta"               icon={TrendingUp}  color="purple" />
            </div>

            {/* Progresso */}
            <div className="card-ecf rounded-2xl p-5 mb-6">
                <div className="flex items-center justify-between mb-2">
                    <div>
                        <p className="text-white font-semibold text-sm">Progresso da Meta</p>
                        <p className="text-white/40 text-xs mt-0.5">
                            {fmt(k.feito)} de {fmt(k.meta)} anúncios · {fmtDec(k.percentual)}%
                        </p>
                    </div>
                    <span className={cn('px-2.5 py-0.5 rounded-full text-[10px] font-bold border tracking-wide uppercase', {
                        'bg-emerald-500/15 text-emerald-400 border-emerald-500/30': k.status_classe === 'above',
                        'bg-yellow-500/15 text-yellow-400 border-yellow-500/30':    k.status_classe === 'ontrack',
                        'bg-red-500/15 text-red-400 border-red-500/30':             k.status_classe === 'below',
                    })}>
                        {k.status}
                    </span>
                </div>
                <div className="h-3 bg-white/10 rounded-full overflow-hidden mt-3">
                    <div
                        style={{ width:`${Math.min(k.percentual ?? 0, 100)}%`, background: barColor }}
                        className="h-full rounded-full transition-all"
                    />
                </div>
                <div className="flex justify-between text-[11px] text-white/30 mt-1.5">
                    <span>0</span>
                    <span>Projeção: {fmt(k.projecao)}</span>
                    <span>Meta: {fmt(k.meta)}</span>
                </div>
            </div>

            {/* Problemas em aberto */}
            <ProblemasSection problemas={problemas} onResolverAnuncio={handleResolverAnuncio} />

            {/* Gráfico + Top empresas */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-6">
                <div className="card-ecf rounded-2xl p-5 lg:col-span-3">
                    <p className="text-white/50 text-[10px] font-semibold tracking-widest uppercase mb-0.5">Publicações</p>
                    <p className="text-white font-display font-extrabold text-base tracking-tight mb-4">Evolução Diária · {nomeMes(mesRef)}</p>
                    <ResponsiveContainer width="100%" height={200}>
                        <AreaChart data={evolucaoDiaria ?? []} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                            <defs>
                                <linearGradient id="gradMeuPainel" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%"  stopColor="#ffe600" stopOpacity={0.45} />
                                    <stop offset="95%" stopColor="#ffe600" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="transparent" />
                            <XAxis dataKey="data" {...chartCfg.axis} axisLine={false} tickLine={false} />
                            <YAxis {...chartCfg.axis} axisLine={false} tickLine={false} width={28} />
                            <Tooltip {...chartCfg.tooltip} />
                            <Area type="monotone" dataKey="total" name="Publicações" stroke="#ffe600" strokeWidth={2.5} fill="url(#gradMeuPainel)" dot={false} activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>

                <div className="card-ecf rounded-2xl p-5 lg:col-span-2">
                    <p className="text-white font-semibold text-sm mb-4">Top 5 Empresas</p>
                    <div className="space-y-3">
                        {(topEmpresas ?? []).map((e, i) => (
                            <div key={i}>
                                <div className="flex justify-between text-[13px] mb-1">
                                    <span className="text-white/70 truncate max-w-[160px]">{e.empresa}</span>
                                    <span className="text-ecf-yellow font-bold">{fmt(e.total)}</span>
                                </div>
                                <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                    <div
                                        style={{ width:`${((e.total / (topEmpresas[0]?.total || 1)) * 100).toFixed(1)}%`, background:'#8b5cf6' }}
                                        className="h-full rounded-full"
                                    />
                                </div>
                            </div>
                        ))}
                        {(!topEmpresas || topEmpresas.length === 0) && (
                            <p className="text-white/20 text-sm text-center py-6">Sem dados para este mês</p>
                        )}
                    </div>
                </div>
            </div>

            {/* Ticket Médio */}
            <div className="mb-6">
                <TicketIndividualChart evolucao={ticketEvolucao} ticketAtual={ticketAtual} />
            </div>

            {/* Feedbacks do líder */}
            {feedbacks?.length > 0 && (
                <div className="card-ecf rounded-2xl p-5">
                    <div className="flex items-center gap-2 mb-4">
                        <MessageSquare size={16} className="text-blue-400" />
                        <p className="text-white font-semibold text-sm">Feedbacks do Líder</p>
                        <span className="px-2 py-0.5 rounded-full text-[10px] bg-blue-500/15 text-blue-400 border border-blue-500/30 font-bold">
                            {feedbacks.length}
                        </span>
                    </div>
                    <div className="space-y-3">
                        {feedbacks.map(fb => (
                            <div key={fb.id} className="rounded-xl border border-blue-500/20 bg-blue-500/5 p-3">
                                <div className="flex justify-between items-start mb-1.5">
                                    <span className="text-white font-medium text-[13px]">{fb.empresa}</span>
                                    <a
                                        href={`https://produto.mercadolivre.com.br/${fb.mlb_code.replace('MLB','MLB-')}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-purple-400 font-mono text-[11px] hover:text-purple-300"
                                    >
                                        {fb.mlb_code}
                                    </a>
                                </div>
                                <p className="text-white/70 text-[13px]">💬 {fb.comentario}</p>
                                <div className="flex items-center justify-between mt-1.5">
                                    <p className="text-white/30 text-[11px]">
                                        ↩ {fb.comentario_autor} · {fb.comentario_em}
                                    </p>
                                    <button
                                        onClick={() => resolverComentario(fb.id)}
                                        className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors"
                                    >
                                        <CheckCheck size={10} /> Marcar resolvido
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
