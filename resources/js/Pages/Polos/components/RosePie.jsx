import { formatCurrency, cn } from '@/lib/utils';

/**
 * RosePie — pizza nightingale rose em SVG puro (Phase 38 / quick 260619-dce).
 * Sem dependência de lib de gráfico (sem recharts, sem ECharts).
 *
 * Inspirada no demo ECharts roseType:'radius':
 *   - Ângulo de cada fatia proporcional ao valor (faturamento).
 *   - Raio de cada fatia proporcional ao valor (maior fatia = maior raio).
 *   - Leader lines com label do polo + valor + percentual esmaecidos.
 *   - Fundo escuro arredondado; glow via feDropShadow suave entre as fatias.
 *   - Cores vindas exclusivamente de slices[].color (POLO_PALETTE multicor).
 *
 * Props:
 *   slices    : Array<{ color: string, value: number, label?: string }>
 *               (value em qualquer escala — normalizado internamente)
 *   size      : diâmetro do disco em px (default 240)
 *   className : classes extras no container
 */
export default function RosePie({
    slices    = [],
    size      = 240,
    className,
}) {
    // ── Filtrar fatias com valor positivo ─────────────────────────────────────
    const valid = slices.filter((s) => (s.value ?? 0) > 0);
    if (valid.length === 0) return null;

    const total = valid.reduce((acc, s) => acc + s.value, 0);

    // ── Geometria base ────────────────────────────────────────────────────────
    // Margem extra para as leader lines não serem cortadas pelo viewBox
    const margem    = size * 0.52;               // espaço lateral para labels
    const R         = size / 2;                  // raio máximo do disco
    const rMin      = R * 0.42;                  // raio mínimo (menor fatia)
    const rMax      = R * 0.92;                  // raio máximo (maior fatia)
    const cx        = size / 2 + margem;         // centro X no viewBox
    const cy        = size / 2;                  // centro Y no viewBox
    const viewW     = size + margem * 2;         // largura total do SVG
    const viewH     = size;                      // altura total do SVG

    // Valores mín/máx para interpolação do raio
    const vMin = Math.min(...valid.map((s) => s.value));
    const vMax = Math.max(...valid.map((s) => s.value));
    const span = vMax - vMin;

    // Raio proporcional ao valor (nightingale: maior fatia = maior raio)
    const raioFatia = (v) => span === 0 ? rMax : rMin + ((v - vMin) / span) * (rMax - rMin);

    // ── Construção das fatias ─────────────────────────────────────────────────
    // Começar do topo (-90°) para replicar o demo
    let anguloAcum = -90;

    const segs = valid.map((s) => {
        const frac         = s.value / total;
        const anguloDeg    = frac * 360;
        const inicioRad    = (anguloAcum * Math.PI) / 180;
        const fimRad       = ((anguloAcum + anguloDeg) * Math.PI) / 180;
        const medioRad     = ((anguloAcum + anguloDeg / 2) * Math.PI) / 180;
        const r            = raioFatia(s.value);

        // Pontos do arco
        const x1 = cx + r * Math.cos(inicioRad);
        const y1 = cy + r * Math.sin(inicioRad);
        const x2 = cx + r * Math.cos(fimRad);
        const y2 = cy + r * Math.sin(fimRad);

        // Large arc flag (1 se o arco > 180°)
        const largeArc = anguloDeg > 180 ? 1 : 0;

        // Caminho SVG: centro → ponto inicial → arco → fechar
        const d = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`;

        // Ponto médio do arco (para a leader line)
        const xMed = cx + r * Math.cos(medioRad);
        const yMed = cy + r * Math.sin(medioRad);

        anguloAcum += anguloDeg;

        return {
            ...s,
            d,
            frac,
            xMed,
            yMed,
            medioRad,
            r,
        };
    });

    // ── Construção das leader lines e labels ──────────────────────────────────
    const comprimentoRadial = R * 0.14;    // comprimento do segmento radial
    const comprimentoHoriz  = R * 0.18;   // comprimento da "perna" horizontal

    const leaders = segs.map((s) => {
        const lado = Math.cos(s.medioRad) >= 0 ? 1 : -1; // 1=direita, -1=esquerda

        // Ponto de saída no raio externo
        const px1 = cx + s.r * Math.cos(s.medioRad);
        const py1 = cy + s.r * Math.sin(s.medioRad);

        // Ponto intermediário (joelho da leader line)
        const px2 = px1 + comprimentoRadial * Math.cos(s.medioRad);
        const py2 = py1 + comprimentoRadial * Math.sin(s.medioRad);

        // Ponto final (extremo horizontal)
        const px3 = px2 + comprimentoHoriz * lado;
        const py3 = py2;

        // Âncora do texto: start quando à direita, end quando à esquerda
        const ancora  = lado >= 0 ? 'start' : 'end';
        const textX   = px3 + 4 * lado;

        const pct       = (s.frac * 100).toFixed(1);
        const valorFmt  = formatCurrency(s.value);
        const labelTxt  = s.label ?? '';

        return { px1, py1, px2, py2, px3, py3, ancora, textX, pct, valorFmt, labelTxt, color: s.color };
    });

    // ── ID único do filtro para isolar glow por instância ────────────────────
    const filtroId = `rose-glow-${Math.random().toString(36).slice(2, 8)}`;

    return (
        <div className={cn('flex items-center justify-center', className)}>
            <svg
                viewBox={`0 0 ${viewW} ${viewH}`}
                width={viewW}
                height={viewH}
                style={{ maxWidth: '100%' }}
                aria-hidden="true"
            >
                <defs>
                    {/* Filtro de glow suave aplicado às fatias */}
                    <filter id={filtroId} x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur in="SourceAlpha" stdDeviation="3" result="blur" />
                        <feFlood floodColor="rgba(0,0,0,0.6)" result="color" />
                        <feComposite in="color" in2="blur" operator="in" result="shadow" />
                        <feOffset dx="0" dy="2" result="shadow-offset" />
                        <feMerge>
                            <feMergeNode in="shadow-offset" />
                            <feMergeNode in="SourceGraphic" />
                        </feMerge>
                    </filter>
                </defs>

                {/* Fundo escuro arredondado por trás do disco */}
                <rect
                    x={cx - R - 6}
                    y={cy - R - 6}
                    width={(R + 6) * 2}
                    height={(R + 6) * 2}
                    rx={R + 6}
                    ry={R + 6}
                    fill="rgba(15,17,22,0.85)"
                />

                {/* Grupo das fatias com glow */}
                <g filter={`url(#${filtroId})`}>
                    {segs.map((s, i) => (
                        <path
                            key={i}
                            d={s.d}
                            fill={s.color}
                            stroke="rgba(5,5,7,0.5)"
                            strokeWidth="1.5"
                            strokeLinejoin="round"
                        />
                    ))}
                </g>

                {/* Leader lines e labels */}
                {leaders.map((l, i) => (
                    <g key={i}>
                        {/* Linha radial + perna horizontal */}
                        <polyline
                            points={`${l.px1},${l.py1} ${l.px2},${l.py2} ${l.px3},${l.py3}`}
                            fill="none"
                            stroke="rgba(255,255,255,0.35)"
                            strokeWidth="1"
                        />
                        {/* Ponto de ancoragem */}
                        <circle cx={l.px3} cy={l.py3} r="2" fill="rgba(255,255,255,0.35)" />

                        {/* Label do polo (linha superior) */}
                        {l.labelTxt && (
                            <text
                                x={l.textX}
                                y={l.py3 - 7}
                                textAnchor={l.ancora}
                                fontSize="10"
                                fill="rgba(255,255,255,0.7)"
                                fontFamily="inherit"
                            >
                                {l.labelTxt}
                            </text>
                        )}

                        {/* Valor + percentual (linha inferior) */}
                        <text
                            x={l.textX}
                            y={l.py3 + 4}
                            textAnchor={l.ancora}
                            fontSize="9"
                            fill="rgba(255,255,255,0.45)"
                            fontFamily="inherit"
                        >
                            {l.valorFmt} · {l.pct}%
                        </text>
                    </g>
                ))}
            </svg>
        </div>
    );
}
