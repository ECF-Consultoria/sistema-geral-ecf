import { formatCurrency, cn } from '@/lib/utils';

/**
 * RosePie — pizza nightingale rose em SVG puro (Phase 38 / quick 260619-dce).
 * Sem dependência de lib de gráfico (sem recharts, sem ECharts).
 *
 * Inspirada no demo ECharts roseType:'radius':
 *   - Ângulo de cada fatia proporcional ao valor (faturamento).
 *   - Raio de cada fatia proporcional ao valor (maior fatia = maior raio).
 *   - Leader lines com label do polo + valor + percentual esmaecidos.
 *   - Fundo escuro arredondado; glow via filtro SVG suave entre as fatias.
 *   - Cores vindas exclusivamente de slices[].color (POLO_PALETTE multicor).
 *
 * Robustez (fix 260619-dce):
 *   - Coage value para número (faturamento pode chegar como string do JSON).
 *   - Mês sem faturamento (todos R$0) NÃO some: mostra placeholder "sem dados"
 *     (o Pie3D antigo desenhava um círculo cheio; aqui um anel neutro explícito).
 *   - Fatia única (≈360°) vira um círculo completo, não um arco degenerado.
 *
 * Props:
 *   slices    : Array<{ color: string, value: number|string, label?: string }>
 *   size      : diâmetro do disco em px (default 240)
 *   className : classes extras no container
 */
export default function RosePie({
    slices    = [],
    size      = 240,
    className,
}) {
    // ── Coerção numérica + filtro de positivos ────────────────────────────────
    // value pode vir como string (decimal do DB/JSON) — coage como o Pie3D fazia
    // implicitamente via Math.max. Sem isso, reduce vira concatenação e os paths
    // saem com NaN (invisíveis).
    const norm  = slices.map((s) => ({ ...s, value: Math.max(Number(s.value) || 0, 0) }));
    const valid = norm.filter((s) => s.value > 0);
    const total = valid.reduce((acc, s) => acc + s.value, 0);

    // ── Geometria base ────────────────────────────────────────────────────────
    const R    = size / 2;          // raio máximo do disco
    const rMin = R * 0.42;          // raio mínimo (menor fatia)
    const rMax = R * 0.92;          // raio máximo (maior fatia)

    // ── Estado vazio: nenhum faturamento no mês ───────────────────────────────
    // Em vez de sumir (retornar null), desenha um anel neutro com aviso — assim a
    // seção nunca fica em branco e o usuário entende que falta sincronizar.
    if (valid.length === 0 || total <= 0) {
        const c = size / 2;
        return (
            <div className={cn('flex items-center justify-center', className)}>
                <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size}
                     style={{ maxWidth: '100%' }} aria-hidden="true">
                    <rect x={c - R} y={c - R} width={R * 2} height={R * 2} rx={R} ry={R}
                          fill="rgba(15,17,22,0.85)" />
                    <circle cx={c} cy={c} r={rMax} fill="none"
                            stroke="rgba(255,255,255,0.12)" strokeWidth="10" strokeDasharray="4 8" />
                    <text x={c} y={c - 4} textAnchor="middle" fontSize="12"
                          fill="rgba(255,255,255,0.45)" fontFamily="inherit">Sem faturamento</text>
                    <text x={c} y={c + 14} textAnchor="middle" fontSize="11"
                          fill="rgba(255,255,255,0.30)" fontFamily="inherit">no mês selecionado</text>
                </svg>
            </div>
        );
    }

    // Margem extra para as leader lines não serem cortadas pelo viewBox
    const margem = size * 0.52;          // espaço lateral para labels
    const cx     = size / 2 + margem;    // centro X no viewBox
    const cy     = size / 2;             // centro Y no viewBox
    const viewW  = size + margem * 2;    // largura total do SVG
    const viewH  = size;                 // altura total do SVG

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
        const frac      = s.value / total;
        const anguloDeg = frac * 360;
        const inicioRad = (anguloAcum * Math.PI) / 180;
        const fimRad    = ((anguloAcum + anguloDeg) * Math.PI) / 180;
        const medioRad  = ((anguloAcum + anguloDeg / 2) * Math.PI) / 180;
        const r         = raioFatia(s.value);

        // Fatia única (≈360°): um arco de 360° tem ponto inicial == final e some.
        // Renderiza como círculo completo (flag 'full').
        const full = anguloDeg >= 359.9;

        // Pontos do arco
        const x1 = cx + r * Math.cos(inicioRad);
        const y1 = cy + r * Math.sin(inicioRad);
        const x2 = cx + r * Math.cos(fimRad);
        const y2 = cy + r * Math.sin(fimRad);

        // Large arc flag (1 se o arco > 180°)
        const largeArc = anguloDeg > 180 ? 1 : 0;

        // Caminho SVG: centro → ponto inicial → arco → fechar
        const d = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`;

        anguloAcum += anguloDeg;

        return { ...s, d, full, frac, medioRad, r };
    });

    // ── Construção das leader lines e labels ──────────────────────────────────
    const comprimentoRadial = R * 0.14;    // comprimento do segmento radial
    const comprimentoHoriz  = R * 0.18;    // comprimento da "perna" horizontal

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
        const ancora = lado >= 0 ? 'start' : 'end';
        const textX  = px3 + 4 * lado;

        const pct      = (s.frac * 100).toFixed(1);
        const valorFmt = formatCurrency(s.value);
        const labelTxt = s.label ?? '';

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
                    {/* Filtro de glow/sombra suave aplicado às fatias */}
                    <filter id={filtroId} x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur in="SourceAlpha" stdDeviation="3" result="blur" />
                        <feFlood floodColor="rgba(0,0,0,0.6)" result="color" />
                        <feComposite in="color" in2="blur" operator="in" result="shadow" />
                        <feOffset in="shadow" dx="0" dy="2" result="shadow-offset" />
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
                        s.full ? (
                            <circle
                                key={i}
                                cx={cx}
                                cy={cy}
                                r={s.r}
                                fill={s.color}
                                stroke="rgba(5,5,7,0.5)"
                                strokeWidth="1.5"
                            />
                        ) : (
                            <path
                                key={i}
                                d={s.d}
                                fill={s.color}
                                stroke="rgba(5,5,7,0.5)"
                                strokeWidth="1.5"
                                strokeLinejoin="round"
                            />
                        )
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
