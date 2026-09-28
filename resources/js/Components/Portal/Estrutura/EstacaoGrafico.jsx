import { useMemo } from 'react';
import { BarChart3, Loader2 } from 'lucide-react';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

// ─── Clássico × Premium, dia a dia ──────────────────────────────────────────
//
// A comparação que o método pede, em DOIS painéis com nome — "Visitas por dia"
// e "Vendas por dia" —, cada um com Clássico e Premium (a soma dos anúncios
// daquele tipo), nos últimos 30 dias. Um painel só, com as visitas, não dizia
// qual das duas era (usuário, 28/09). Escalas separadas de propósito: visitas
// são centenas, vendas são unidades — no mesmo eixo, as vendas sumiriam.
//
// Sob demanda (várias chamadas ao ML por anúncio). As vendas chegam por
// último (os pedidos da loja): o painel de visitas aparece antes, e o de
// vendas espera por elas.

const COR = { classico: '#38bdf8', premium: '#ffe600' };
const n = (x) => (x === null || x === undefined ? '—' : x.toLocaleString('pt-BR'));
const pct = (vendas, visitas) => (visitas > 0 ? `${((vendas / visitas) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%` : '—');
const diaMes = (d) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;

/** Um painel: título, a unidade dele e as duas linhas. */
function Painel({ titulo, unidade, dados, vocabulario, ...props }) {
    return (
        <div className="rounded-xl border border-white/[0.06] p-3" {...props}>
            <p className="mb-1 text-[12.5px] font-semibold text-white">{titulo} <span className="font-normal text-white/40">· {unidade}</span></p>
            <div className="h-44">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={dados} margin={{ top: 8, right: 8, bottom: 0, left: -18 }}>
                        <CartesianGrid stroke="rgba(255,255,255,0.05)" vertical={false} />
                        <XAxis dataKey="data" tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 11 }} tickLine={false} axisLine={false} tickFormatter={diaMes} minTickGap={24} />
                        <YAxis tick={{ fill: 'rgba(255,255,255,0.4)', fontSize: 11 }} tickLine={false} axisLine={false} allowDecimals={false} />
                        <Tooltip contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.1)', borderRadius: 8, fontSize: 12 }}
                            labelFormatter={(d) => d.split('-').reverse().join('/')}
                            formatter={(v, chave) => [`${n(v)} ${unidade}`, vocabulario.tipos[chave]]} />
                        <Legend formatter={(chave) => <span className="text-[12px] text-white/70">{vocabulario.tipos[chave]}</span>} />
                        <Line type="monotone" dataKey="classico" stroke={COR.classico} strokeWidth={2} dot={false} />
                        <Line type="monotone" dataKey="premium" stroke={COR.premium} strokeWidth={2} dot={false} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

export default function EstacaoGrafico({ oferta, metricas, conectado, vocabulario }) {
    const { estado, carregar } = metricas;
    const tipoDe = useMemo(() => Object.fromEntries(oferta.anuncios.filter((a) => a.codigo_mlb).map((a) => [a.codigo_mlb, a.tipo])), [oferta]);

    const { visitas, vendas, totais } = useMemo(() => {
        const mapa = estado?.metricas;
        if (! mapa) return { visitas: [], vendas: [], totais: null };
        const vis = new Map();
        const ven = new Map();
        const vazio = () => ({ visitas: 0, visitas_30d: 0, vendas: 0, vendas_30d: 0 });
        const totais = { classico: vazio(), premium: vazio() };
        const somar = (mapaDia, data, tipo, valor) => {
            const d = mapaDia.get(data) ?? { data, classico: 0, premium: 0 };
            d[tipo] += valor;
            mapaDia.set(data, d);
        };
        for (const [mlb, m] of Object.entries(mapa)) {
            const tipo = tipoDe[mlb];
            if (! tipo) continue;
            const t = totais[tipo];
            t.visitas += m.visitas ?? 0;
            t.visitas_30d += m.visitas_30d ?? 0;
            t.vendas += m.vendas ?? 0;
            t.vendas_30d += m.vendas_30d ?? 0;
            for (const p of m.serie ?? []) somar(vis, p.data, tipo, p.visitas);
            for (const p of m.vendas_serie ?? []) somar(ven, p.data, tipo, p.vendas);
        }
        const ordenar = (mapaDia) => [...mapaDia.values()].sort((x, y) => x.data.localeCompare(y.data));

        return { visitas: ordenar(vis), vendas: ordenar(ven), totais };
    }, [estado, tipoDe]);

    if (! conectado) return null;
    if (! oferta.anuncios.some((a) => a.codigo_mlb)) return null;
    const prontas = estado?.vendas_prontas;

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4" data-grafico>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-[13.5px] font-semibold text-white">Últimos 30 dias · {vocabulario.tipos_curtos.classico} × {vocabulario.tipos_curtos.premium}</h4>
                {estado === null && (
                    <button type="button" onClick={carregar} data-acao="ver-metricas"
                        className="inline-flex items-center gap-1.5 rounded-xl border border-ecf-yellow/40 px-3 py-1.5 text-[12.5px] text-ecf-yellow hover:bg-ecf-yellow/10">
                        <BarChart3 size={14} /> Ver visitas e vendas
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
                                    <strong className="text-white">{prontas ? n(totais[tipo].vendas) : '…'}</strong> vendas
                                    {prontas && <span className="text-white/45"> · {pct(totais[tipo].vendas, totais[tipo].visitas)} de conversão</span>}
                                </p>
                                <p className="text-[11.5px] text-white/40">
                                    30 dias: {n(totais[tipo].visitas_30d)} visitas · {prontas ? n(totais[tipo].vendas_30d) : '…'} vendas
                                </p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-3 grid gap-3 xl:grid-cols-2">
                        {visitas.length > 1 && <Painel titulo="Visitas por dia" unidade="visitas" dados={visitas} vocabulario={vocabulario} data-serie-oferta="visitas" />}
                        {prontas && vendas.length > 1 && <Painel titulo="Vendas por dia" unidade="unidades vendidas" dados={vendas} vocabulario={vocabulario} data-serie-oferta="vendas" />}
                        {! prontas && (
                            <div className="grid place-items-center rounded-xl border border-dashed border-white/[0.08] p-6 text-center" data-vendas-pendentes>
                                <p className="inline-flex items-center gap-1.5 text-[12px] text-white/45">
                                    <Loader2 size={12} className="animate-spin" /> Vendas por dia: lendo os pedidos da loja…
                                </p>
                            </div>
                        )}
                    </div>
                    {estado.limitado && <p className="mt-1 text-[11px] text-white/35">Considerando os 30 primeiros anúncios desta oferta.</p>}
                </>
            )}
        </div>
    );
}
