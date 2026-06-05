import {
    ResponsiveContainer, LineChart, Line,
    XAxis, YAxis, CartesianGrid, Tooltip, Legend,
} from 'recharts';

/**
 * HistoricoChart — evolução 12 meses com duplo eixo Y.
 * Phase 24 — recharts LineChart; GMV (eixo esquerdo, amarelo) + Sellers Ativos (eixo direito, branco).
 *
 * Props:
 *   data — array de { timMonthId, gmv, sellersAtivos, ... } (default [])
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
 * Formata valor BRL abreviado para o eixo Y esquerdo (GMV).
 * Ex: 42000000 → "R$ 42M" | 5000000 → "R$ 5M"
 */
const fmtAxisShort = (v) => {
    return new Intl.NumberFormat('pt-BR', {
        style:            'currency',
        currency:         'BRL',
        notation:         'compact',
        maximumFractionDigits: 0,
    }).format(v).replace(' mi', 'M').replace(' mil', 'k');
};

/**
 * Formata valor BRL completo para o tooltip (sem abreviação).
 * Ex: 42859191 → "R$ 42.859.191"
 */
const fmtTooltipMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:            'currency',
        currency:         'BRL',
        maximumFractionDigits: 0,
    }).format(v ?? 0);

/**
 * Formata contagem para o tooltip com separador de milhar pt-BR.
 * Ex: 1238 → "1.238"
 */
const fmtTooltipCount = (v) =>
    new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(v ?? 0);

// ─── Tooltip customizado ──────────────────────────────────────────────────────

function CustomTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;

    return (
        <div className="bg-[#0b0c10] border border-white/[0.1] rounded-md p-3 text-xs space-y-1">
            <div className="text-white/70 font-medium mb-1">{label}</div>
            {payload.map((entry) => (
                <div key={entry.name} className="flex items-center gap-2">
                    <span style={{ color: entry.color }}>■</span>
                    <span className="text-white/60">{entry.name}:</span>
                    <span style={{ color: entry.color }} className="font-semibold">
                        {entry.name === 'GMV'
                            ? fmtTooltipMoney(entry.value)
                            : fmtTooltipCount(entry.value)
                        }
                    </span>
                </div>
            ))}
        </div>
    );
}

// ─── Componente principal ─────────────────────────────────────────────────────

export default function HistoricoChart({ data = [] }) {
    // Empty state quando não há dados (ECF Drive offline ou período sem histórico)
    if (data.length === 0) {
        return (
            <div className="h-72 flex items-center justify-center text-white/30 text-sm">
                Sem dados de histórico disponíveis.
            </div>
        );
    }

    // Mapeia para adicionar o campo 'mes' legível no eixo X
    const dataMapeada = data.map((d) => ({ ...d, mes: fmtMesAno(d.timMonthId) }));

    return (
        <ResponsiveContainer width="100%" height={300}>
            <LineChart
                data={dataMapeada}
                margin={{ top: 5, right: 20, bottom: 5, left: 20 }}
            >
                {/* Grade de fundo translúcida */}
                <CartesianGrid strokeDasharray="3 3" stroke="#ffffff10" />

                {/* Eixo X — label do mês em formato pt-BR */}
                <XAxis
                    dataKey="mes"
                    tick={{ fill: '#ffffff60', fontSize: 11 }}
                    axisLine={{ stroke: '#ffffff15' }}
                    tickLine={false}
                />

                {/* Eixo Y esquerdo — GMV (amarelo ecf-yellow) */}
                <YAxis
                    yAxisId="left"
                    tick={{ fill: '#ffe600cc', fontSize: 11 }}
                    tickFormatter={fmtAxisShort}
                    axisLine={{ stroke: '#ffffff15' }}
                    tickLine={false}
                />

                {/* Eixo Y direito — Sellers Ativos (branco translúcido) */}
                <YAxis
                    yAxisId="right"
                    orientation="right"
                    tick={{ fill: '#ffffff80', fontSize: 11 }}
                    tickFormatter={(v) => v.toLocaleString('pt-BR')}
                    axisLine={{ stroke: '#ffffff15' }}
                    tickLine={false}
                />

                {/* Tooltip customizado pt-BR */}
                <Tooltip content={<CustomTooltip />} />

                {/* Legenda no topo */}
                <Legend
                    wrapperStyle={{ fontSize: 12, paddingBottom: 4 }}
                />

                {/* Linha GMV — eixo esquerdo, amarelo ecf */}
                <Line
                    yAxisId="left"
                    type="monotone"
                    dataKey="gmv"
                    name="GMV"
                    stroke="#ffe600"
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 4, strokeWidth: 0 }}
                />

                {/* Linha Sellers Ativos — eixo direito, branco */}
                <Line
                    yAxisId="right"
                    type="monotone"
                    dataKey="sellersAtivos"
                    name="Sellers Ativos"
                    stroke="#ffffff80"
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 4, strokeWidth: 0 }}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}
