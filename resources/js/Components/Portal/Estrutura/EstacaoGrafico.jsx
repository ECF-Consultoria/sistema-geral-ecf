import { useMemo } from 'react';
import { BarChart3, Loader2 } from 'lucide-react';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

// ─── Clássico × Premium, dia a dia ──────────────────────────────────────────
//
// A comparação que o método pede, com espaço para existir: as visitas de cada
// lado (a soma dos anúncios daquele tipo) nos últimos 30 dias, e os totais de
// 7 dias com as vendas. Sob demanda: são várias chamadas ao ML por anúncio.
// As vendas chegam por último (os pedidos da loja): o gráfico aparece antes,
// e a linha de vendas se completa quando elas chegam.

const COR = { classico: '#38bdf8', premium: '#ffe600' };
const n = (x) => (x === null || x === undefined ? '—' : x.toLocaleString('pt-BR'));

export default function EstacaoGrafico({ oferta, metricas, conectado, vocabulario }) {
    const { estado, carregar } = metricas;
    const tipoDe = useMemo(() => Object.fromEntries(oferta.anuncios.filter((a) => a.codigo_mlb).map((a) => [a.codigo_mlb, a.tipo])), [oferta]);

    const { serie, totais } = useMemo(() => {
        const mapa = estado?.metricas;
        if (! mapa) return { serie: [], totais: null };
        const porDia = new Map();
        const totais = { classico: { visitas: 0, visitas_30d: 0, vendas: 0, vendas_ok: true }, premium: { visitas: 0, visitas_30d: 0, vendas: 0, vendas_ok: true } };
        for (const [mlb, m] of Object.entries(mapa)) {
            const tipo = tipoDe[mlb];
            if (! tipo) continue;
            const t = totais[tipo];
            t.visitas += m.visitas ?? 0;
            t.visitas_30d += m.visitas_30d ?? 0;
            if (m.vendas === null || m.vendas === undefined) t.vendas_ok = false; else t.vendas += m.vendas;
            for (const p of m.serie ?? []) {
                const d = porDia.get(p.data) ?? { data: p.data, classico: 0, premium: 0 };
                d[tipo] += p.visitas;
                porDia.set(p.data, d);
            }
        }

        return { serie: [...porDia.values()].sort((a, b) => a.data.localeCompare(b.data)), totais };
    }, [estado, tipoDe]);

    if (! conectado) return null;
    if (! oferta.anuncios.some((a) => a.codigo_mlb)) return null;

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4" data-grafico>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-[13.5px] font-semibold text-white">Visitas e vendas · {vocabulario.tipos_curtos.classico} × {vocabulario.tipos_curtos.premium}</h4>
                {estado === null && (
                    <button type="button" onClick={carregar} data-acao="ver-metricas"
                        className="inline-flex items-center gap-1.5 rounded-xl border border-ecf-yellow/40 px-3 py-1.5 text-[12.5px] text-ecf-yellow hover:bg-ecf-yellow/10">
                        <BarChart3 size={14} /> Ver os últimos 30 dias
                    </button>
                )}
                {estado === 'lendo' && <span className="inline-flex items-center gap-1.5 text-[12px] text-white/45"><Loader2 size={13} className="animate-spin" /> Lendo no Mercado Livre…</span>}
            </div>

            {estado?.erro && <p className="mt-2 text-[12px] text-red-300/80">{estado.erro}</p>}

            {totais && (
                <>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2" data-totais>
                        {['classico', 'premium'].map((tipo) => (
                            <div key={tipo} className="rounded-xl border border-white/[0.06] px-3 py-2" data-total={tipo}>
                                <p className="flex items-center gap-1.5 text-[12px] font-semibold" style={{ color: COR[tipo] }}>
                                    <span className="inline-block h-2 w-2 rounded-full" style={{ background: COR[tipo] }} /> {vocabulario.tipos[tipo]}
                                </p>
                                <p className="mt-0.5 text-[12.5px] text-white/75">
                                    7 dias: <strong className="text-white">{n(totais[tipo].visitas)}</strong> visitas ·{' '}
                                    <strong className="text-white">{totais[tipo].vendas_ok && estado.vendas_prontas ? n(totais[tipo].vendas) : '…'}</strong> vendas
                                    {totais[tipo].visitas > 0 && totais[tipo].vendas_ok && estado.vendas_prontas && (
                                        <span className="text-white/45"> · {((totais[tipo].vendas / totais[tipo].visitas) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% de conversão</span>
                                    )}
                                </p>
                                <p className="text-[11.5px] text-white/40">30 dias: {n(totais[tipo].visitas_30d)} visitas</p>
                            </div>
                        ))}
                    </div>
                    {! estado.vendas_prontas && (
                        <p className="mt-2 inline-flex items-center gap-1.5 text-[11.5px] text-white/45" data-vendas-pendentes>
                            <Loader2 size={12} className="animate-spin" /> As vendas dos 7 dias estão sendo lidas dos pedidos da loja…
                        </p>
                    )}
                    {serie.length > 1 && (
                        <div className="mt-3 h-52" data-serie-oferta>
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={serie} margin={{ top: 8, right: 8, bottom: 0, left: -18 }}>
                                    <CartesianGrid stroke="rgba(255,255,255,0.05)" vertical={false} />
                                    <XAxis dataKey="data" tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 11 }} tickLine={false} axisLine={false}
                                        tickFormatter={(d) => d.slice(8, 10) + '/' + d.slice(5, 7)} minTickGap={24} />
                                    <YAxis tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 11 }} tickLine={false} axisLine={false} allowDecimals={false} />
                                    <Tooltip contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.1)', borderRadius: 8, fontSize: 12 }}
                                        labelFormatter={(d) => d.split('-').reverse().join('/')}
                                        formatter={(v, chave) => [v, vocabulario.tipos[chave]]} />
                                    <Legend formatter={(chave) => <span className="text-[12px] text-white/70">{vocabulario.tipos[chave]}</span>} />
                                    <Line type="monotone" dataKey="classico" stroke={COR.classico} strokeWidth={2} dot={false} />
                                    <Line type="monotone" dataKey="premium" stroke={COR.premium} strokeWidth={2} dot={false} />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                    {estado.limitado && <p className="mt-1 text-[11px] text-white/35">Considerando os 30 primeiros anúncios desta oferta.</p>}
                </>
            )}
        </div>
    );
}
