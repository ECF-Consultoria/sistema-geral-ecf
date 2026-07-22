import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { ShoppingBag, Package, Receipt, Store, TrendingUp, Megaphone, Percent } from 'lucide-react';
import { ComposedChart, Area, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';

const SHOPEE = '#ee4d2d';
const ADS = '#22d3ee'; // ciano — linha de Investimento Ads (contrasta com o laranja Shopee)

const fmtBRL = (n) => (Number(n) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const fmtInt = (n) => (Number(n) || 0).toLocaleString('pt-BR');
// TACoS chega como razão (0–1); null quando não há faturamento/Ads → "—".
const fmtPct = (n) => (n == null ? '—' : (Number(n) * 100).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%');
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fmtBR = (iso) => (iso ? new Date(iso + 'T00:00:00').toLocaleDateString('pt-BR') : '—');
const fmtDayShort = (iso) => (iso ? new Date(iso + 'T00:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : '');

// Presets do seletor de período (datas locais, sem shift de timezone).
function buildPresets() {
    const today = new Date();
    const y = today.getFullYear();
    const m = today.getMonth();
    const startMonth = new Date(y, m, 1);
    const startPrevMonth = new Date(y, m - 1, 1);
    const endPrevMonth = new Date(y, m, 0);
    const last30 = new Date(today);
    last30.setDate(today.getDate() - 29);
    const startYear = new Date(y, 0, 1);
    return [
        { key: 'mes',        label: 'Mês atual',       from: isoDate(startMonth),     to: isoDate(today) },
        { key: 'mesPassado', label: 'Mês passado',     from: isoDate(startPrevMonth), to: isoDate(endPrevMonth) },
        { key: '30d',        label: 'Últimos 30 dias', from: isoDate(last30),         to: isoDate(today) },
        { key: 'ano',        label: 'Este ano',        from: isoDate(startYear),      to: isoDate(today) },
    ];
}

function go(from, to) {
    router.get(route('shopee.dashboard'), { from, to }, { preserveState: true, preserveScroll: true, replace: true });
}

function KpiCard({ icon: Icon, label, value, sub, accent }) {
    return (
        <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-4">
            <div className="flex items-center gap-2 text-white/40 text-xs">
                <Icon size={13} style={accent ? { color: SHOPEE } : undefined} />
                {label}
            </div>
            <div className="text-white text-2xl font-bold mt-2">{value}</div>
            {sub && <div className="text-white/30 text-[10px] mt-1">{sub}</div>}
        </div>
    );
}

export default function ShopeeShell({ label = 'Shopee', period, bounds, kpis, series = [], porLoja = [], ads_disponivel = false }) {
    const presets = buildPresets();
    const activeKey = presets.find((p) => p.from === period?.from && p.to === period?.to)?.key;
    const semDadosNenhum = !bounds?.max;
    const semDadosNoPeriodo = !semDadosNenhum && (!series || series.length === 0);

    return (
        <AppLayout title={`Dashboard ${label}`}>
            <Head title={`Dashboard ${label}`} />

            <div className="p-6 space-y-6">
                {/* Header */}
                <div className="flex flex-wrap items-center gap-3">
                    <img src="/images/shopee-icon.svg" className="w-8 h-8" alt={label} />
                    <div>
                        <h1 className="text-white text-2xl font-display font-bold leading-tight">Dashboard {label}</h1>
                        <p className="text-white/30 text-[11px]">
                            {kpis?.lojas ?? 0} loja{(kpis?.lojas ?? 0) !== 1 ? 's' : ''} conectada{(kpis?.lojas ?? 0) !== 1 ? 's' : ''}
                            {' · '}dados exclusivos da Shopee
                        </p>
                    </div>
                    <span
                        className="ml-auto text-[11px] px-2.5 py-1 rounded-full font-medium"
                        style={{ backgroundColor: `${SHOPEE}1a`, color: SHOPEE }}
                    >
                        {fmtBR(period?.from)} — {fmtBR(period?.to)}
                    </span>
                </div>

                {/* Seletor de período */}
                <div className="flex flex-wrap items-center gap-2">
                    {presets.map((p) => (
                        <button
                            key={p.key}
                            onClick={() => go(p.from, p.to)}
                            className={cn(
                                'px-3 py-1.5 rounded-lg text-[12px] font-medium border transition-colors',
                                activeKey === p.key
                                    ? 'text-white border-transparent'
                                    : 'text-white/50 border-white/[0.08] hover:text-white hover:border-white/20'
                            )}
                            style={activeKey === p.key ? { backgroundColor: `${SHOPEE}26`, borderColor: `${SHOPEE}55`, color: SHOPEE } : undefined}
                        >
                            {p.label}
                        </button>
                    ))}
                    <div className="flex items-center gap-1.5 ml-auto">
                        <input
                            type="date"
                            value={period?.from ?? ''}
                            max={period?.to}
                            onChange={(e) => e.target.value && go(e.target.value, period?.to)}
                            className="bg-white/[0.04] border border-white/[0.08] rounded-lg px-2 py-1 text-[12px] text-white/80 outline-none focus:border-white/20"
                        />
                        <span className="text-white/25 text-xs">até</span>
                        <input
                            type="date"
                            value={period?.to ?? ''}
                            min={period?.from}
                            onChange={(e) => e.target.value && go(period?.from, e.target.value)}
                            className="bg-white/[0.04] border border-white/[0.08] rounded-lg px-2 py-1 text-[12px] text-white/80 outline-none focus:border-white/20"
                        />
                    </div>
                </div>

                {/* Estado: nenhum dado sincronizado ainda */}
                {semDadosNenhum ? (
                    <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-8 text-center">
                        <ShoppingBag size={40} className="mx-auto mb-3" style={{ color: SHOPEE }} />
                        <h2 className="text-white text-lg font-semibold mb-1">Ainda não há dados sincronizados</h2>
                        <p className="text-white/50 text-sm max-w-md mx-auto">
                            Assim que o sync da Shopee rodar (ou o backfill for executado), o faturamento das lojas
                            conectadas aparece aqui.
                        </p>
                    </div>
                ) : (
                    <>
                        {/* KPIs */}
                        <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4">
                            <KpiCard icon={TrendingUp} label="Faturamento (GMV)" value={fmtBRL(kpis?.revenue)} accent sub="soma dos pedidos" />
                            <KpiCard icon={ShoppingBag} label="Pedidos" value={fmtInt(kpis?.orders)} />
                            <KpiCard icon={Package} label="Itens vendidos" value={fmtInt(kpis?.items)} />
                            <KpiCard icon={Receipt} label="Ticket médio" value={fmtBRL(kpis?.ticket_medio)} />
                            <KpiCard icon={Store} label="Lojas conectadas" value={fmtInt(kpis?.lojas)} />
                            <KpiCard
                                icon={Megaphone}
                                label="Investimento Ads"
                                value={ads_disponivel ? fmtBRL(kpis?.ad_spend) : '—'}
                                sub={ads_disponivel ? 'gasto com anúncios' : 'Shopee só fornece Ads dos últimos 6 meses'}
                            />
                            <KpiCard
                                icon={Percent}
                                label="TACoS"
                                value={ads_disponivel ? fmtPct(kpis?.tacos) : '—'}
                                sub={ads_disponivel ? 'ads ÷ faturamento' : 'sem Ads no período'}
                            />
                        </div>

                        {/* Gráfico diário */}
                        <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-4">
                            <div className="flex items-center justify-between mb-3">
                                <div className="text-white/60 text-[13px] font-semibold">
                                    {ads_disponivel ? 'Faturamento e Ads por dia' : 'Faturamento por dia'}
                                </div>
                                {ads_disponivel && (
                                    <div className="flex items-center gap-3 text-[11px]">
                                        <span className="flex items-center gap-1.5 text-white/40">
                                            <span className="w-2.5 h-1 rounded-full" style={{ backgroundColor: SHOPEE }} /> Faturamento
                                        </span>
                                        <span className="flex items-center gap-1.5 text-white/40">
                                            <span className="w-2.5 h-1 rounded-full" style={{ backgroundColor: ADS }} /> Ads
                                        </span>
                                    </div>
                                )}
                            </div>
                            {semDadosNoPeriodo ? (
                                <div className="h-[220px] flex items-center justify-center text-white/30 text-sm">
                                    Sem vendas nesse período — experimente outro intervalo.
                                </div>
                            ) : (
                                <div style={{ width: '100%', height: 260 }}>
                                    <ResponsiveContainer>
                                        <ComposedChart data={series} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                                            <defs>
                                                <linearGradient id="shopeeFill" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stopColor={SHOPEE} stopOpacity={0.35} />
                                                    <stop offset="100%" stopColor={SHOPEE} stopOpacity={0.02} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" vertical={false} />
                                            <XAxis dataKey="date" tickFormatter={fmtDayShort} tick={{ fill: 'rgba(255,255,255,0.35)', fontSize: 11 }} axisLine={false} tickLine={false} minTickGap={24} />
                                            <YAxis yAxisId="left" tickFormatter={(v) => (v >= 1000 ? `${Math.round(v / 1000)}k` : v)} tick={{ fill: 'rgba(255,255,255,0.35)', fontSize: 11 }} axisLine={false} tickLine={false} width={44} />
                                            {ads_disponivel && (
                                                <YAxis yAxisId="right" orientation="right" tickFormatter={(v) => (v >= 1000 ? `${Math.round(v / 1000)}k` : v)} tick={{ fill: 'rgba(255,255,255,0.35)', fontSize: 11 }} axisLine={false} tickLine={false} width={44} />
                                            )}
                                            <Tooltip
                                                contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.1)', borderRadius: 10, fontSize: 12 }}
                                                labelStyle={{ color: 'rgba(255,255,255,0.6)' }}
                                                labelFormatter={(d) => fmtBR(d)}
                                                formatter={(v, name) => {
                                                    if (name === 'revenue') return [fmtBRL(v), 'Faturamento'];
                                                    if (name === 'ad_spend') return [fmtBRL(v), 'Investimento Ads'];
                                                    return [fmtInt(v), name];
                                                }}
                                            />
                                            <Area yAxisId="left" type="monotone" dataKey="revenue" stroke={SHOPEE} strokeWidth={2} fill="url(#shopeeFill)" />
                                            {ads_disponivel && (
                                                <Line yAxisId="right" type="monotone" dataKey="ad_spend" stroke={ADS} strokeWidth={2} dot={false} />
                                            )}
                                        </ComposedChart>
                                    </ResponsiveContainer>
                                </div>
                            )}
                        </div>

                        {/* Quebra por loja */}
                        {porLoja.length > 0 && (
                            <div className="bg-ecf-card border border-white/[0.06] rounded-xl p-4">
                                <div className="text-white/60 text-[13px] font-semibold mb-3">Por loja no período</div>
                                <div className="space-y-1.5">
                                    {porLoja.map((l) => (
                                        <div key={l.company_id} className="flex items-center gap-3 py-1.5 border-b border-white/[0.04] last:border-0">
                                            <span className="w-1.5 h-1.5 rounded-full shrink-0" style={{ backgroundColor: SHOPEE }} />
                                            <span className="text-white/80 text-[13px] flex-1 truncate">{l.name}</span>
                                            <span className="text-white/40 text-[11px] hidden sm:inline">{fmtInt(l.orders)} ped.</span>
                                            {ads_disponivel && (
                                                <>
                                                    <span className="text-[11px] w-24 text-right" style={{ color: ADS }}>
                                                        Ads {fmtBRL(l.ad_spend)}
                                                    </span>
                                                    <span className="text-white/50 text-[11px] w-16 text-right">
                                                        TACoS {fmtPct(l.tacos)}
                                                    </span>
                                                </>
                                            )}
                                            <span className="text-white text-[13px] font-medium w-28 text-right">{fmtBRL(l.revenue)}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
