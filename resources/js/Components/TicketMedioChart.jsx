import {
    LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
    ResponsiveContainer, ReferenceLine, LabelList
} from 'recharts';
import { cn } from '@/lib/utils';

const MESES_PT_ABREV = { '01':'Jan','02':'Fev','03':'Mar','04':'Abr','05':'Mai','06':'Jun','07':'Jul','08':'Ago','09':'Set','10':'Out','11':'Nov','12':'Dez' };
export function abrevMes(ym) { const [y,m]=(ym||'').split('-'); return `${MESES_PT_ABREV[m]||m}/${(y||'').slice(2)}`; }
export function fmtBRL(n)    { return `R$ ${Number(n??0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})}`; }

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

/**
 * Visão Geral (Admin/Gestor): ranking de ticket por publicador no mês
 * Props: ticketPorPub [{ nome, ticket, bill, qty }], ticketGeral, subtitulo
 */
export function TicketGeralChart({ ticketPorPub = [], ticketGeral = 0, subtitulo = '' }) {
    if (!ticketPorPub.length) return (
        <div className="card-ecf rounded-2xl p-5">
            <p className="text-white font-semibold text-sm mb-1">Ticket Médio por Publicador</p>
            <p className="text-white/30 text-[12px] mt-2">
                Sem dados ainda. Rode o sync de vendas para puxar os preços da Adman.
            </p>
        </div>
    );

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-start justify-between mb-5">
                <div>
                    <p className="text-white font-semibold text-sm">Ticket Médio por Publicador</p>
                    {subtitulo && <p className="text-white/30 text-[11px] mt-0.5">{subtitulo}</p>}
                </div>
                {ticketGeral > 0 && (
                    <div className="text-right">
                        <p className="text-white/30 text-[11px]">Ticket geral</p>
                        <p className="text-ecf-yellow font-display font-bold text-2xl">{fmtBRL(ticketGeral)}</p>
                    </div>
                )}
            </div>
            <div className="space-y-4">
                {ticketPorPub.map((p, i) => {
                    const cor = COLORS_PUB[i % COLORS_PUB.length];
                    const pct = ticketGeral > 0 ? Math.min((p.ticket / ticketGeral) * 100, 100) : 100;
                    const diff = ticketGeral > 0 ? Math.round(((p.ticket / ticketGeral) - 1) * 100) : 0;

                    return (
                        <div key={p.nome}>
                            <div className="flex items-center justify-between text-[12px] mb-1.5">
                                <div className="flex items-center gap-2">
                                    <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: cor }} />
                                    <span className="text-white/80 font-semibold">{p.nome}</span>
                                </div>
                                <div className="flex items-center gap-3 text-[11px]">
                                    <span className="text-white/35">{p.qty} un · {fmtBRL(p.bill)}</span>
                                    <span className="font-bold text-[13px]" style={{ color: cor }}>{fmtBRL(p.ticket)}</span>
                                    {ticketGeral > 0 && (
                                        <span className={cn('text-[10px] font-bold w-10 text-right',
                                            diff >= 0 ? 'text-emerald-400' : 'text-red-400')}>
                                            {diff >= 0 ? '+' : ''}{diff}%
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                <div className="h-full rounded-full transition-all"
                                    style={{ width: `${pct}%`, background: cor }} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Visão Individual (Publicador): evolução mensal do ticket
 * Props: evolucao [{ mes, ticket, qty }], ticketAtual
 */
export function TicketIndividualChart({ evolucao = [], ticketAtual = 0 }) {
    if (!evolucao.length) return (
        <div className="card-ecf rounded-2xl p-5">
            <p className="text-white font-semibold text-sm mb-1">Meu Ticket Médio</p>
            <p className="text-white/30 text-[12px] mt-2">
                Sem histórico ainda. Rode o sync de vendas para puxar os preços da Adman.
            </p>
        </div>
    );

    const chartData  = evolucao.map(e => ({ mes: abrevMes(e.mes), ticket: e.ticket, qty: e.qty }));
    const avgTicket  = Math.round(evolucao.reduce((s, e) => s + e.ticket, 0) / evolucao.length);

    return (
        <div className="card-ecf rounded-2xl p-5">
            <div className="flex items-start justify-between mb-5">
                <div>
                    <p className="text-white font-semibold text-sm">Meu Ticket Médio</p>
                    <p className="text-white/30 text-[11px] mt-0.5">Preço líquido médio por unidade vendida · últimos meses</p>
                </div>
                {ticketAtual > 0 && (
                    <div className="text-right">
                        <p className="text-white/30 text-[11px]">Este mês</p>
                        <p className="text-ecf-yellow font-display font-bold text-2xl">{fmtBRL(ticketAtual)}</p>
                    </div>
                )}
            </div>
            <ResponsiveContainer width="100%" height={200}>
                <LineChart data={chartData} margin={{ top:16, right:16, left:0, bottom:4 }}>
                    <CartesianGrid stroke="rgba(255,255,255,0.04)" strokeDasharray="4 4" />
                    <XAxis dataKey="mes" tick={{ fill:'#6a6f79', fontSize:11 }} axisLine={false} tickLine={false} />
                    <YAxis tickFormatter={v => `R$${Number(v).toLocaleString('pt-BR',{maximumFractionDigits:0})}`}
                        tick={{ fill:'#6a6f79', fontSize:11 }} axisLine={false} tickLine={false} width={62} />
                    <Tooltip content={<TooltipTicket />} />
                    <ReferenceLine y={avgTicket} stroke="rgba(255,255,255,0.12)" strokeDasharray="4 4"
                        label={{ value:`Média ${fmtBRL(avgTicket)}`, fill:'rgba(255,255,255,0.25)', fontSize:10, position:'insideTopRight' }} />
                    <Line type="monotone" dataKey="ticket" name="Ticket médio" stroke="#ffe600"
                        strokeWidth={2.5} dot={{ fill:'#ffe600', r:4, strokeWidth:0 }} activeDot={{ r:6 }}>
                        <LabelList dataKey="ticket" position="top"
                            formatter={v => `R$${Number(v).toLocaleString('pt-BR',{maximumFractionDigits:0})}`}
                            style={{ fill:'rgba(255,255,255,0.45)', fontSize:9 }} />
                    </Line>
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
