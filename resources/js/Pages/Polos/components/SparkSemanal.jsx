import { useId } from 'react';
import { formatCurrency } from '@/lib/utils';

// Geometria do mini-gráfico (coordenadas do viewBox)
const W = 280, H = 92;
const PADX = 10;
const FAT_TOP = 8,  FAT_BASE = 54;   // faixa da área de faturamento
const ADS_BASE = 88, ADS_TOP = 62;   // faixa das barras de ADS (sobem do base)

/**
 * SparkSemanal — micro-série intramês do faturamento semanal (Semana 1–4) como
 * área amarela + barras de investimento em ADS (sky) abaixo. Eleva as
 * mini-barras planas do drawer a uma leitura de tendência macro→micro.
 *
 * Props:
 *   semanas  : [{ semana, de, ate, faturamento, ads }]
 *   total    : faturamento total do mês
 *   totalAds : investimento total em ADS do mês
 *   fechado  : mês fechado (CSV)? ADS não disponível → nota em vez de barras zeradas
 */
export default function SparkSemanal({ semanas = [], total = 0, totalAds = 0, fechado = false }) {
    const uid = useId().replace(/[:]/g, '');
    const n   = semanas.length;

    const semFaturamento = n === 0 || semanas.every((s) => (s.faturamento ?? 0) <= 0);
    if (semFaturamento) {
        return (
            <p className="text-white/40 text-xs">Sem faturamento semanal disponível.</p>
        );
    }

    const xAt    = (i) => (n === 1 ? W / 2 : PADX + (i / (n - 1)) * (W - PADX * 2));
    const maxFat = Math.max(...semanas.map((s) => s.faturamento ?? 0), 1);
    const maxAds = Math.max(...semanas.map((s) => s.ads ?? 0), 1);
    const yFat   = (v) => FAT_BASE - ((v ?? 0) / maxFat) * (FAT_BASE - FAT_TOP);

    const pts      = semanas.map((s, i) => [xAt(i), yFat(s.faturamento)]);
    const linePath = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' ');
    const areaPath = `${linePath} L ${pts[pts.length - 1][0].toFixed(1)} ${FAT_BASE} L ${pts[0][0].toFixed(1)} ${FAT_BASE} Z`;

    const temAds = totalAds > 0;

    return (
        <div>
            <svg viewBox={`0 0 ${W} ${H}`} width="100%" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                <defs>
                    <linearGradient id={`${uid}-fill`} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%"   stopColor="#ffe600" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#ffe600" stopOpacity="0" />
                    </linearGradient>
                </defs>

                {/* Barras de ADS (sky) — só quando há investimento */}
                {temAds && semanas.map((s, i) => {
                    const h = ((s.ads ?? 0) / maxAds) * (ADS_BASE - ADS_TOP);
                    if (h <= 0) return null;
                    return (
                        <rect
                            key={`ads-${i}`}
                            x={xAt(i) - 3} y={ADS_BASE - h} width={6} height={h}
                            rx={1.5} fill="#38bdf8" opacity="0.75"
                        />
                    );
                })}

                {/* Área + linha de faturamento */}
                <path d={areaPath} fill={`url(#${uid}-fill)`} />
                <path d={linePath} fill="none" stroke="#ffe600" strokeWidth="2" strokeLinejoin="round"
                      strokeLinecap="round" />

                {/* Pontos com halo */}
                {pts.map((p, i) => (
                    <g key={`pt-${i}`}>
                        <circle cx={p[0]} cy={p[1]} r={5.5} fill="#ffe600" opacity="0.2" />
                        <circle cx={p[0]} cy={p[1]} r={2.6} fill="#ffe600" />
                    </g>
                ))}
            </svg>

            {/* Rótulos das semanas */}
            <div className="mt-1 flex justify-between px-1 text-[10px] text-white/35">
                {semanas.map((s) => <span key={s.semana}>S{s.semana}</span>)}
            </div>

            {/* Rodapé: total + ADS */}
            <div className="mt-2 flex items-center justify-between border-t border-white/[0.06] pt-2">
                <span className="text-white/40 text-[11px]">Total do mês</span>
                <span className="font-semibold text-xs tabular-nums text-ecf-yellow">{formatCurrency(total)}</span>
            </div>
            <div className="mt-1 flex items-center justify-between">
                <span className="text-white/40 text-[11px]">Investimento ADS</span>
                {temAds ? (
                    <span className="font-semibold text-xs tabular-nums text-sky-400">{formatCurrency(totalAds)}</span>
                ) : (
                    <span className="text-[11px] text-white/30">{fechado ? 'sem dado no mês fechado' : '—'}</span>
                )}
            </div>
        </div>
    );
}
