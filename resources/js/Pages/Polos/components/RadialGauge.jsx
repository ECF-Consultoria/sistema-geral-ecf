/**
 * RadialGauge — arco radial fino (gauge 0–100) em SVG, com gradiente amarelo ECF
 * e glow. Embute o DNA radial dos antigos DonutCards de forma sutil, atrás do
 * número de "% Geral da meta" no HeroKpi.
 *
 * Props:
 *   pct  : percentual (0–100+; clampado em 100 no preenchimento)
 *   size : diâmetro em px (default 92)
 *   cor  : cor base do arco (default ecf-yellow)
 */
export default function RadialGauge({ pct = 0, size = 92, cor = '#ffe600' }) {
    const stroke = 7;
    const c      = size / 2;
    const r      = (size - stroke) / 2;
    const circ   = 2 * Math.PI * r;
    const p      = Math.max(Math.min(Number(pct) || 0, 100), 0);
    const dash   = (p / 100) * circ;

    // ID único por instância (isola gradiente/filtro entre cards)
    const uid    = `rg-${Math.round(c)}-${Math.round(dash)}`;

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
            <defs>
                <linearGradient id={`${uid}-grad`} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%"   stopColor="#ffe600" />
                    <stop offset="100%" stopColor="#f5d400" />
                </linearGradient>
                <filter id={`${uid}-glow`} x="-40%" y="-40%" width="180%" height="180%">
                    <feDropShadow dx="0" dy="0" stdDeviation="3" floodColor={cor} floodOpacity="0.55" />
                </filter>
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
                    filter={`url(#${uid}-glow)`}
                />
            )}
        </svg>
    );
}
