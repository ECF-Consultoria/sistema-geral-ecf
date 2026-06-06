import {
    ResponsiveContainer, LineChart, Line,
    XAxis, YAxis, CartesianGrid, Tooltip, Legend,
} from 'recharts';

/**
 * EvolucaoEmpresaChart — evolução 12 meses com duplo eixo Y (Phase 25).
 *
 * Cópia adaptada de PainelExecutivo/components/HistoricoChart (Phase 24).
 * Diferenças: eixo direito é Investimento ADS (azul) em vez de Sellers Ativos;
 * os dados vêm de sellerMetricasMensal (tgmv_lc + inv_pads).
 *
 * Props:
 *   data — array de { timMonthId, tgmv_lc, inv_pads, ... } (default [])
 */

// ─── Helpers locais ──────────────────────────────────────────────────────────

// Lookup de abreviações pt-BR para meses
const MESES_PT = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/**
 * Converte timMonthId 'YYYYMM' para label pt-BR 'mes/AA'.
 * Ex: '202605' → 'mai/26' | '202412' → 'dez/24'
 * Fallback: retorna o próprio input se inválido.
 */
const fmtMesAno = (timMonthId) => {
    if (!timMonthId || timMonthId.length !== 6) return timMonthId ?? '';
    const ano = timMonthId.slice(0, 4);
    const mes = parseInt(timMonthId.slice(4, 6), 10);
    if (isNaN(mes) || mes < 1 || mes > 12) return timMonthId;
    return `${MESES_PT[mes - 1]}/${ano.slice(2)}`;
};

/**
 * Formata valor BRL abreviado para os eixos Y.
 * Ex: 42000000 → "R$ 42M" | 40000 → "R$ 40k"
 */
const fmtAxisShort = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        notation:              'compact',
        maximumFractionDigits: 0,
    }).format(v).replace(' mi', 'M').replace(' mil', 'k');

/**
 * Formata valor BRL completo para tooltip (sem abreviação).
 * Ex: 2700000 → "R$ 2.700.000"
 */
const fmtTooltipMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        maximumFractionDigits: 0,
    }).format(v ?? 0);

// ─── Tooltip customizado pt-BR ────────────────────────────────────────────────

function CustomTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;

    return (
        <div className="bg-[#0b0c10] border border-white/[0.1] rounded-md p-3 text-xs space-y-1.5">
            <div className="font-semibold text-white/70 mb-1">{label}</div>
            {payload.map((entry) => (
                <div key={entry.dataKey} className="flex items-center gap-2">
                    <span
                        className="inline-block w-2 h-2 rounded-full shrink-0"
                        style={{ backgroundColor: entry.color }}
                    />
                    <span style={{ color: entry.color }}>
                        {entry.name}: {fmtTooltipMoney(entry.value)}
                    </span>
                </div>
            ))}
        </div>
    );
}

// ─── Componente principal ─────────────────────────────────────────────────────

export default function EvolucaoEmpresaChart({ data = [] }) {
    // Empty state quando não há dados de evolução
    if (data.length === 0) {
        return (
            <div className="h-72 flex items-center justify-center text-white/30 text-sm">
                Sem dados de evolução disponíveis.
            </div>
        );
    }

    // Plano 03 Phase 25 (2026-06-05): API retorna campos em camelCase
    // (tgmvLc + invPads, não tgmv_lc + inv_pads) e valores como STRING.
    // Bug do smoke do usuário: gráfico zerado pois leitura snake_case era undefined.
    const numOuZero = (v) => {
        if (v === null || v === undefined || v === '') return 0;
        const n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    };

    const dataMapeada = data.map((d) => ({
        mes:          fmtMesAno(d.timMonthId),
        gmv:          numOuZero(d.tgmvLc),
        investimento: numOuZero(d.invPads),
    }));

    return (
        <ResponsiveContainer width="100%" height={300}>
            <LineChart data={dataMapeada} margin={{ top: 5, right: 20, left: 10, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#ffffff10" />

                <XAxis
                    dataKey="mes"
                    tick={{ fill: '#ffffff60', fontSize: 11 }}
                    axisLine={{ stroke: '#ffffff15' }}
                    tickLine={false}
                />

                {/* Eixo esquerdo: GMV (amarelo ECF) */}
                <YAxis
                    yAxisId="left"
                    tickFormatter={fmtAxisShort}
                    tick={{ fill: '#ffe600cc', fontSize: 11 }}
                    axisLine={false}
                    tickLine={false}
                    width={70}
                />

                {/* Eixo direito: Investimento ADS (azul) */}
                <YAxis
                    yAxisId="right"
                    orientation="right"
                    tickFormatter={fmtAxisShort}
                    tick={{ fill: '#60a5facc', fontSize: 11 }}
                    axisLine={false}
                    tickLine={false}
                    width={70}
                />

                <Tooltip content={<CustomTooltip />} />

                <Legend
                    wrapperStyle={{ fontSize: 12, paddingTop: 8 }}
                />

                {/* Linha GMV — eixo esquerdo, amarelo ECF */}
                <Line
                    yAxisId="left"
                    type="monotone"
                    dataKey="gmv"
                    name="GMV"
                    stroke="#ffe600"
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 4 }}
                />

                {/* Linha Investimento ADS — eixo direito, azul */}
                <Line
                    yAxisId="right"
                    type="monotone"
                    dataKey="investimento"
                    name="Investimento ADS"
                    stroke="#60a5fa"
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 4 }}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}
