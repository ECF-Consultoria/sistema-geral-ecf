import { LineChart, Line, ResponsiveContainer } from 'recharts';
import { cn } from '@/lib/utils';

/**
 * SparklineCrescimento — indicador visual de crescimento MoM por empresa.
 *
 * Props:
 *   queda_mom_pct {number|null} — percentual crescimento/queda vs período anterior
 *     (positivo = crescimento, negativo = queda)
 *   data {Array<{date:string, value:number}>?} — série histórica opcional.
 *     Se fornecida (>= 2 pontos), renderiza mini LineChart Recharts (60×28).
 *     Se ausente, renderiza badge textual compacto com seta + percentual.
 *
 * Threshold de cor (±2%):
 *   > +2%  → verde emerald-400
 *   < -2%  → vermelho red-400
 *   −2% a +2% → cinza white/40
 *   null   → cinza (sem dado)
 */

// Retorna { stroke, textCls, arrow } com base no threshold ±2%.
function getColor(pct) {
    if (pct === null || pct === undefined) {
        return {
            stroke: 'rgba(255,255,255,0.25)',
            textCls: 'text-white/40',
            arrow: null,
        };
    }
    if (pct > 2) {
        return {
            stroke: '#34d399',                              // emerald-400
            textCls: 'text-emerald-400',
            arrow: '↑',
        };
    }
    if (pct < -2) {
        return {
            stroke: '#f87171',                              // red-400
            textCls: 'text-red-400',
            arrow: '↓',
        };
    }
    return {
        stroke: 'rgba(255,255,255,0.4)',
        textCls: 'text-white/40',
        arrow: '→',
    };
}

export default function SparklineCrescimento({ queda_mom_pct, data }) {
    const { stroke, textCls, arrow } = getColor(queda_mom_pct);
    const temSerie = Array.isArray(data) && data.length >= 2;

    // Formata percentual com 1 decimal e sinal explícito.
    const label = queda_mom_pct !== null && queda_mom_pct !== undefined
        ? `${queda_mom_pct >= 0 ? '+' : ''}${queda_mom_pct.toFixed(1)}%`
        : '—';

    if (temSerie) {
        // Mini LineChart Recharts: 60×28, sem eixos, sem grid, sem tooltip.
        return (
            <div className="flex items-center gap-1.5">
                <div style={{ width: 60, height: 28 }}>
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data}>
                            <Line
                                type="monotone"
                                dataKey="value"
                                stroke={stroke}
                                strokeWidth={1.5}
                                dot={false}
                                isAnimationActive={false}
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </div>
                <span className={cn('text-[11px] font-semibold tabular-nums', textCls)}>
                    {label}
                </span>
            </div>
        );
    }

    // Sem série histórica: badge textual compacto.
    return (
        <span className={cn('inline-flex items-center gap-0.5 text-[11px] font-semibold tabular-nums', textCls)}>
            {arrow && <span aria-hidden="true">{arrow}</span>}
            {label}
        </span>
    );
}
