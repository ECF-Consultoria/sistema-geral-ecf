import { useMemo } from 'react';
import {
    LineChart, Line, XAxis, YAxis, CartesianGrid,
    Tooltip, ResponsiveContainer, ReferenceLine,
} from 'recharts';
import { Target } from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';

/*
 * ─── <GoalProgressPanel /> ──────────────────────────────────────────────────
 * Componente presentational reusável (Phase 62 / META-01).
 *
 * Apresenta UMA meta + progresso mensal numa única área visual: chart do
 * histórico de realização, percentual atingido no período mais recente, valor
 * absoluto da meta, valor absoluto realizado e status ("no alvo" / "aproximando"
 * / "distante").
 *
 * Consumido por Companies/Show.jsx (Plan 62-05) e Goals/Index.jsx (Plan 62-04).
 * Sem chamadas HTTP, sem navegação, sem efeitos externos — 100% controlado
 * por props.
 */

// Espelhado de App\Models\GoalResult::LOWER_IS_BETTER — manter sincronizado.
// Métricas onde "melhor" significa menor (TACOS, ACOS, absenteísmo, %
// produtos sem custo). Impacta cálculo de percent atingido e sinal do delta.
const LOWER_IS_BETTER = ['tacos', 'acos', 'absenteeism', 'products_without_cost'];

const periodLabel = { monthly: 'Mensal', quarterly: 'Trimestral', yearly: 'Anual' };

// Cores por status — Tailwind + tokens ecf-* do design system dark.
const STATUS_STYLE = {
    'no-alvo':     { pill: 'text-green-400 bg-green-400/10 border-green-400/20',    text: 'text-green-400',    stroke: '#4ade80', label: 'No alvo'          },
    'aproximando': { pill: 'text-ecf-yellow bg-ecf-yellow/10 border-ecf-yellow/20', text: 'text-ecf-yellow',   stroke: '#ffe600', label: 'Aproximando'      },
    'distante':    { pill: 'text-red-400 bg-red-400/10 border-red-400/20',          text: 'text-red-400',      stroke: '#f87171', label: 'Distante da meta' },
};

const chartStyle = {
    tooltip: {
        contentStyle: { background: '#0f1116', border: '1px solid rgba(255,255,255,0.08)', borderRadius: 10, fontSize: 12, color: '#e5e7eb' },
        labelStyle:   { color: '#9ba0aa' },
    },
    grid: { stroke: 'rgba(255,255,255,0.06)', strokeDasharray: '4 4' },
    axis: { tick: { fill: '#6a6f79', fontSize: 11 } },
};

// ─── Helpers puros ─────────────────────────────────────────────────────────

// Formata valor conforme value_type/metric — mesma regra de Goals/Index.jsx
// `formatGoalValue` (currency → R$; nps → decimal; demais → %).
function formatValue(v, goal) {
    const n = parseFloat(v);
    if (!Number.isFinite(n)) return '—';
    if (goal?.value_type === 'currency') return formatCurrency(n);
    if (goal?.metric === 'nps') return n.toFixed(1);
    return `${n}%`;
}

// Percentual atingido — para higher-is-better = realized/target; para
// lower-is-better = target/realized. Retorna null quando target inválido
// para evitar divisão por zero e classificação errônea.
function computePercent(realized, target, metric) {
    const r = parseFloat(realized);
    const t = parseFloat(target);
    if (!Number.isFinite(r) || !Number.isFinite(t) || t <= 0) return null;
    if (LOWER_IS_BETTER.includes(metric)) {
        if (r <= 0) return null;
        return (t / r) * 100;
    }
    return (r / t) * 100;
}

// Deriva status a partir de percent + flag `achieved` do backend.
// `achieved===true` sempre vence (verde). Sem percent → fallback amarelo
// para não pintar de vermelho quando o dado é inconclusivo.
function deriveStatus(percent, achieved) {
    if (achieved === true) return 'no-alvo';
    if (percent == null) return 'aproximando';
    if (percent >= 100) return 'no-alvo';
    if (percent >= 80) return 'aproximando';
    return 'distante';
}

// Formata "YYYY-MM" → "Mmm/YY" (Set/26) para exibição no chart. Aceita
// outros formatos degradando graciosamente (retorna string original).
function formatPeriod(period) {
    if (typeof period !== 'string') return String(period ?? '');
    const m = period.match(/^(\d{4})-(\d{2})/);
    if (!m) return period;
    const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    const idx = parseInt(m[2], 10) - 1;
    return `${meses[idx] ?? m[2]}/${m[1].slice(2)}`;
}

// ─── Componente principal ─────────────────────────────────────────────────

export default function GoalProgressPanel({ goal, results = [], compact = false, className }) {
    // Ordena ASC por period para renderização temporal correta no chart.
    // Componente aceita results fora de ordem — normalizamos aqui.
    const orderedResults = useMemo(() => {
        return [...(results ?? [])].sort((a, b) => String(a.period).localeCompare(String(b.period)));
    }, [results]);

    const isEmpty = orderedResults.length === 0;
    const last = isEmpty ? null : orderedResults[orderedResults.length - 1];

    const percent = last
        ? computePercent(last.realized_value, last.target_value ?? goal?.target_value, goal?.metric)
        : null;

    const status = deriveStatus(percent, last?.achieved);
    const statusStyle = STATUS_STYLE[status];

    // Dataset para Recharts — normaliza para números e mantém chave `period`.
    // `meta` sai como snapshot do target no período (útil quando target mudou
    // ao longo do tempo — LineChart mostra ambas via ReferenceLine).
    const chartData = useMemo(() => orderedResults.map(r => ({
        period:    formatPeriod(r.period),
        realizado: Number.isFinite(parseFloat(r.realized_value)) ? parseFloat(r.realized_value) : null,
        meta:      Number.isFinite(parseFloat(r.target_value))   ? parseFloat(r.target_value)   : null,
    })), [orderedResults]);

    const targetFormatted = formatValue(goal?.target_value, goal);
    const realizedFormatted = last ? formatValue(last.realized_value, goal) : '—';
    const percentDisplay = percent != null ? `${percent.toFixed(0)}%` : '—';
    const headerBadge = periodLabel[goal?.period_type] ?? '—';
    const chartHeight = compact ? 100 : 160;

    // Elemento de percentual — reusado entre modo compact e completo (mantido
    // como variável para preservar unicidade do data-testid no source).
    const percentEl = (
        <span
            data-testid="goal-progress-percentage"
            className={cn(
                compact ? 'text-2xl' : 'text-3xl',
                'font-extrabold leading-tight tabular-nums',
                statusStyle.text,
            )}
        >
            {percentDisplay}
        </span>
    );

    // Wrapper único do painel — o data-testid raiz aparece exatamente 1× no
    // source (necessário para os acceptance gates do Plan 62-02).
    return (
        <div
            data-testid="goal-progress-panel"
            data-status={status}
            className={cn(
                'card-ecf rounded-2xl p-5 flex flex-col gap-4',
                className,
            )}
        >
            {/* Cabeçalho: label da métrica + badge de período + status pill.
                Em empty state, o status pill fica oculto (não há dado). */}
            <div className="flex items-center justify-between gap-2">
                <div className="text-[11px] uppercase tracking-wide text-white/50 font-medium truncate">
                    {goal?.metric_label ?? goal?.metric ?? 'Meta'}
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    <span className="text-[11px] text-white/40 border border-white/[0.08] rounded-full px-2 py-0.5">
                        {headerBadge}
                    </span>
                    {!isEmpty && (
                        <span
                            data-testid="goal-progress-status"
                            className={cn(
                                'text-[11px] font-medium border rounded-full px-2 py-0.5',
                                statusStyle.pill,
                            )}
                        >
                            {statusStyle.label}
                        </span>
                    )}
                </div>
            </div>

            {isEmpty ? (
                // Empty state — sem results, não renderiza chart nem número.
                <div
                    data-testid="goal-progress-empty"
                    className="flex flex-col items-center justify-center py-8 gap-2 text-center"
                >
                    <Target size={compact ? 20 : 28} className="text-white/30" />
                    <p className="text-sm text-white/50">Sem dados de progresso ainda</p>
                    <p className="text-[11px] text-white/30">
                        Meta: <span className="text-white/70">{targetFormatted}</span>
                    </p>
                </div>
            ) : (
                <>
                    {/* Bloco central completo (não-compact): META + REALIZADO
                        + percentual grande em duas colunas para leitura rápida. */}
                    {!compact && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="flex flex-col">
                                <span className="text-[10px] uppercase tracking-wider text-white/40">Meta</span>
                                <span className="text-2xl font-extrabold text-white leading-tight tabular-nums">
                                    {targetFormatted}
                                </span>
                                <span className="text-[11px] text-white/50 mt-0.5">
                                    Realizado: <span className="text-white/80">{realizedFormatted}</span>
                                </span>
                            </div>
                            <div className="flex flex-col items-end justify-center">
                                <span className="text-[10px] uppercase tracking-wider text-white/40">Atingido</span>
                                {percentEl}
                            </div>
                        </div>
                    )}

                    {/* Modo compact: percentual + realizado inline num único header. */}
                    {compact && (
                        <div className="flex items-center justify-between">
                            {percentEl}
                            <span className="text-[11px] text-white/50">
                                {realizedFormatted} <span className="text-white/30">/ {targetFormatted}</span>
                            </span>
                        </div>
                    )}

                    {/* Chart do histórico — LineChart com linha realizado +
                        ReferenceLine tracejada indicando target atual. Cor da
                        série do realizado segue o status (verde/amarelo/vermelho). */}
                    <div data-testid="goal-progress-chart" className="w-full">
                        <ResponsiveContainer width="100%" height={chartHeight}>
                            <LineChart data={chartData} margin={{ top: 6, right: 8, left: 0, bottom: 0 }}>
                                <CartesianGrid {...chartStyle.grid} />
                                <XAxis dataKey="period" {...chartStyle.axis} interval="preserveStartEnd" />
                                <YAxis
                                    {...chartStyle.axis}
                                    width={compact ? 40 : 60}
                                    tickFormatter={(v) => {
                                        if (goal?.value_type === 'currency') return formatCurrency(v).replace('R$', '').trim();
                                        if (goal?.metric === 'nps') return Number(v).toFixed(1);
                                        return `${v}%`;
                                    }}
                                />
                                <Tooltip
                                    {...chartStyle.tooltip}
                                    formatter={(v, name) => [formatValue(v, goal), name === 'realizado' ? 'Realizado' : 'Meta']}
                                    labelFormatter={(l) => `Período: ${l}`}
                                />
                                <ReferenceLine
                                    y={parseFloat(goal?.target_value) || 0}
                                    stroke="#ffe600"
                                    strokeDasharray="4 4"
                                    strokeOpacity={0.6}
                                />
                                <Line
                                    type="monotone"
                                    dataKey="realizado"
                                    name="realizado"
                                    stroke={statusStyle.stroke}
                                    strokeWidth={2}
                                    dot={{ r: 3, fill: statusStyle.stroke }}
                                    activeDot={{ r: 5 }}
                                    isAnimationActive={false}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>

                    {/* Rodapé opcional: descrição da meta. Escondido no modo
                        compact para não poluir grids densas. */}
                    {!compact && goal?.description && (
                        <p className="text-[11px] text-white/40 leading-snug">
                            {goal.description}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
