/**
 * RadialGauge — arco radial fino (gauge 0–100) em SVG com gradiente amarelo ECF.
 * Embute o DNA radial dos antigos DonutCards de forma sutil, atrás do número de
 * "% Geral da meta" no HeroKpi.
 *
 * SEM glow (feDropShadow), de propósito: o arco encostava na borda do viewport
 * do SVG, então o halo era recortado em linha reta nos 4 lados e aparecia como um
 * QUADRADO luminoso em volta do anel. O raio ainda ganha 1px de folga para o
 * strokeLinecap redondo não raspar na borda.
 *
 * Props:
 *   pct  : percentual (0–100+; clampado em 100 no preenchimento)
 *   size : diâmetro em px (default 92)
 *   cor  : cor base do arco (default ecf-yellow)
 */
export default function RadialGauge({ pct = 0, size = 92, cor = '#ffe600' }) {
    const stroke = 7;
    const c      = size / 2;
    const r      = (size - stroke) / 2 - 1;
    const circ   = 2 * Math.PI * r;
    const p      = Math.max(Math.min(Number(pct) || 0, 100), 0);
    const dash   = (p / 100) * circ;

    // ID único por instância (isola o gradiente entre cards)
    const uid    = `rg-${Math.round(c)}-${Math.round(dash)}`;

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
            <defs>
                <linearGradient id={`${uid}-grad`} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%"   stopColor={cor} />
                    <stop offset="100%" stopColor={cor} stopOpacity="0.82" />
                </linearGradient>
            </defs>

            {/* Trilho (faltante) */}
            <circle cx={c} cy={c} r={r} fill="none" stroke="#2a2d36" strokeWidth={stroke} />

            {/* Arco preenchido (começa no topo, sentido horário) */}
            {dash > 0 && (
                <circle
                    cx={c} cy={c} r={r} fill="none"
                    stroke={`url(#${uid}-grad)`} strokeWidth={stroke} strokeLinecap="round"
                    strokeDasharray={`${dash} ${circ - dash}`}
                    transform={`rotate(-90 ${c} ${c})`}
                />
            )}
        </svg>
    );
}
