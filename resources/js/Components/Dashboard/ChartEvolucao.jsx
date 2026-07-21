import { useMemo, useState } from 'react';
import {
    AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, ReferenceDot,
} from 'recharts';
import { formatCurrency, formatPercent, cn } from '@/lib/utils';

// Fase 97 Plan 04 (DASH-97-4) — abas do gráfico "Evolução no período".
// D4 (CONTEXT travado com o usuário 2026-07-17): apenas Faturamento e
// Margem — o mockup oferecia TACOS/ADS também, mas o usuário fechou o
// escopo do gráfico só nessas duas métricas.
const METRICS = {
    faturamento: {
        label: 'Faturamento',
        dataKey: 'revenue',
        color: '#ffe600',
        gradientId: 'chartEvolucaoRevenue',
        unit: 'diário',
        format: (v) => formatCurrency(v),
    },
    margem: {
        label: 'Margem',
        dataKey: 'margin',
        color: '#34d399',
        gradientId: 'chartEvolucaoMargin',
        unit: 'média ponderada',
        format: (v) => formatPercent(v),
    },
};

// Tooltip customizado (recharts `content=`) — mostra data, valor formatado na
// unidade da aba ativa e "+X% vs média" da própria série filtrada (não é um
// componente por-item de lista, então o pitfall Rollup do .map() não se
// aplica aqui; é um único ponto do hover).
function EvolucaoTooltip({ active, payload, label, metric }) {
    if (!active || !payload?.length) return null;
    const value = payload[0]?.value ?? 0;
    const avg = payload[0]?.payload?.__avg ?? 0;
    const relPct = avg ? ((value - avg) / avg) * 100 : 0;
    const positivo = relPct >= 0;
    return (
        <div className="rounded-lg border border-white/10 bg-[#0a0d13] px-3 py-2 shadow-xl min-w-[120px]">
            <p className="text-white/50 text-[10px] font-semibold">{label}</p>
            <p className="text-white text-[15px] font-extrabold tracking-tight my-0.5 tabular-nums">{metric.format(value)}</p>
            <p className={cn('text-[10px] font-semibold', positivo ? 'text-emerald-400' : 'text-red-400')}>
                {positivo ? '+' : ''}{relPct.toFixed(0)}% vs média
            </p>
        </div>
    );
}

/**
 * ChartEvolucao — "Evolução no período" (Fase 97 Plan 04, DASH-97-4).
 *
 * Abas Faturamento/Margem consumindo `revenue_chart`/`margin_chart` (série
 * diária do recorte já aplicado, injetados pelo DashboardController). Hover
 * interativo via recharts (Tooltip customizado + ReferenceDot Pico/Menor) —
 * preferido a SVG manual do mockup (Claude's Discretion do CONTEXT): recharts
 * já é o padrão do projeto e dá o mesmo controle de hover/marcações.
 */
export default function ChartEvolucao({
    revenueChart = [],
    marginChart = [],
    periodLabel = '',
    companiesCount = 0,
    companyName = null,
}) {
    const [tab, setTab] = useState('faturamento');
    const metric = METRICS[tab];
    const rawData = tab === 'faturamento' ? revenueChart : marginChart;

    // Média da série ativa (base do "+X% vs média" do tooltip) + índices de
    // Pico/Menor. Não é um valor por-item de lista renderizada via .map (é
    // um cálculo agregado único), então fica fora do pitfall Rollup.
    const { data, peakIndex, lowIndex } = useMemo(() => {
        if (!rawData.length) return { data: [], peakIndex: -1, lowIndex: -1 };
        const values = rawData.map(d => Number(d[metric.dataKey]) || 0);
        const media = values.reduce((a, b) => a + b, 0) / values.length;
        let peak = 0, low = 0;
        values.forEach((v, i) => {
            if (v > values[peak]) peak = i;
            if (v < values[low]) low = i;
        });
        const enriched = rawData.map(d => ({ ...d, __avg: media }));
        return { data: enriched, peakIndex: peak, lowIndex: low };
    }, [rawData, metric.dataKey]);

    const escopo = companyName
        ? companyName
        : `${companiesCount} ${companiesCount === 1 ? 'empresa' : 'empresas'}`;
    const subtitle = `${metric.label} ${metric.unit} · ${periodLabel} · ${escopo}`;

    return (
        <div className="card-ecf rounded-2xl p-6">
            <div className="flex items-start justify-between gap-3 flex-wrap mb-1">
                <div>
                    <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1">Evolução no período</p>
                    <p className="text-white/30 text-[12px]">{subtitle}</p>
                </div>
                <div className="flex bg-white/[0.03] border border-white/[0.08] rounded-xl p-0.5 gap-0.5">
                    {Object.entries(METRICS).map(([key, m]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={cn(
                                'px-3.5 py-1.5 rounded-lg text-[12.5px] font-bold transition-colors',
                                tab === key ? 'bg-white/[0.08] text-white' : 'text-white/40 hover:text-white/70',
                            )}
                        >
                            {m.label}
                        </button>
                    ))}
                </div>
            </div>

            {data.length === 0 ? (
                <div className="h-[240px] flex items-center justify-center">
                    <p className="text-white/20 text-sm">Sem dados para exibir</p>
                </div>
            ) : (
                <ResponsiveContainer width="100%" height={240}>
                    <AreaChart data={data} margin={{ top: 22, right: 12, left: 0, bottom: 0 }}>
                        <defs>
                            <linearGradient id={metric.gradientId} x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor={metric.color} stopOpacity={0.22} />
                                <stop offset="95%" stopColor={metric.color} stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid stroke="rgba(255,255,255,0.04)" strokeDasharray="4 4" vertical={false} />
                        <XAxis dataKey="date" tick={{ fill: '#6a6f79', fontSize: 11 }} />
                        <YAxis
                            tick={{ fill: '#6a6f79', fontSize: 11 }}
                            tickFormatter={metric.format}
                            width={tab === 'faturamento' ? 85 : 55}
                        />
                        <Tooltip content={<EvolucaoTooltip metric={metric} />} cursor={{ stroke: metric.color, strokeOpacity: 0.3 }} />
                        <Area
                            type="monotone"
                            dataKey={metric.dataKey}
                            stroke={metric.color}
                            strokeWidth={2.5}
                            fill={`url(#${metric.gradientId})`}
                            dot={false}
                            activeDot={{ r: 4, fill: metric.color, strokeWidth: 0 }}
                            style={{ filter: `drop-shadow(0 0 6px ${metric.color}) drop-shadow(0 0 10px ${metric.color}66)` }}
                        />
                        {peakIndex >= 0 && (
                            <ReferenceDot
                                x={data[peakIndex].date}
                                y={data[peakIndex][metric.dataKey]}
                                r={4}
                                fill="#0b0e14"
                                stroke={metric.color}
                                strokeWidth={2}
                                label={{ value: 'Pico', position: 'top', fill: metric.color, fontSize: 10, fontWeight: 700 }}
                                isFront
                            />
                        )}
                        {lowIndex >= 0 && lowIndex !== peakIndex && (
                            <ReferenceDot
                                x={data[lowIndex].date}
                                y={data[lowIndex][metric.dataKey]}
                                r={4}
                                fill="#0b0e14"
                                stroke="#f87171"
                                strokeWidth={2}
                                label={{ value: 'Menor', position: 'bottom', fill: '#f87171', fontSize: 10, fontWeight: 700 }}
                                isFront
                            />
                        )}
                    </AreaChart>
                </ResponsiveContainer>
            )}
        </div>
    );
}
