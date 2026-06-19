import { useMemo } from 'react';
import { shapeForPolo } from './cityShapes';

/**
 * CityGauge — medidor de % da meta no FORMATO DA CIDADE do polo (quick 260619-dce).
 *
 * Usa o contorno municipal real (IBGE, em cityShapes.js) como silhueta e "enche"
 * de baixo até o % da meta, animando na montagem. % no centro, contorno com glow.
 * Cor = cor do status do polo. Retorna null quando não há contorno (caller faz
 * fallback para o anel).
 *
 * Props:
 *   nome   : nome do polo/localidade (casado contra os aliases de cityShapes)
 *   pct    : % da meta (0–100+; clampado em 100 no preenchimento)
 *   cor    : cor do preenchimento/contorno (cor de status)
 *   height : altura renderizada em px (default 150)
 */
export default function CityGauge({ nome, pct = 0, cor = '#22c55e', height = 150, className }) {
    const shape = useMemo(() => shapeForPolo(nome), [nome]);
    if (!shape) return null;

    const { w, h, path } = shape;
    const pReal = Number(pct) || 0;                          // exibido no texto (pode passar de 100)
    const p     = Math.max(Math.min(pReal, 100), 0);         // usado no preenchimento (clampado)
    const pad   = 6;

    // Nível do preenchimento (sobe de baixo p/ cima)
    const fillTop = h * (1 - p / 100);
    const fillH   = (h + pad) - fillTop;

    // IDs únicos por polo (isolam clip/filtro entre os cards)
    const uid    = String(nome).toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '') || 'x';
    const clipId = `city-clip-${uid}`;
    const glowId = `city-glow-${uid}`;

    return (
        <svg
            viewBox={`${-pad} ${-pad} ${w + pad * 2} ${h + pad * 2}`}
            height={height}
            style={{ maxWidth: '100%' }}
            className={className}
            aria-hidden="true"
        >
            <defs>
                <clipPath id={clipId} clipRule="evenodd">
                    <path d={path} />
                </clipPath>
                <filter id={glowId} x="-30%" y="-30%" width="160%" height="160%">
                    <feDropShadow dx="0" dy="0" stdDeviation="2.5" floodColor={cor} floodOpacity="0.6" />
                </filter>
            </defs>

            {/* Leito (cidade vazia) */}
            <path d={path} fill="rgba(255,255,255,0.04)" fillRule="evenodd" />

            {/* Preenchimento até o % — clipado à silhueta, sobe ao montar */}
            <g clipPath={`url(#${clipId})`}>
                <rect x={-pad} y={fillTop} width={w + pad * 2} height={fillH} fill={cor} opacity="0.88">
                    <animate attributeName="y" from={h + pad} to={fillTop} dur="0.9s"
                             calcMode="spline" keyTimes="0;1" keySplines="0.22 1 0.36 1" fill="freeze" />
                    <animate attributeName="height" from="0" to={fillH} dur="0.9s"
                             calcMode="spline" keyTimes="0;1" keySplines="0.22 1 0.36 1" fill="freeze" />
                </rect>
            </g>

            {/* Contorno da cidade com glow */}
            <path d={path} fill="none" stroke={cor} strokeWidth="1.4" strokeLinejoin="round"
                  fillRule="evenodd" filter={`url(#${glowId})`} />

            {/* % da meta no centro (halo escuro p/ legibilidade sobre o preenchimento) */}
            <text x={w / 2} y={h / 2 - 2} textAnchor="middle" dominantBaseline="central"
                  fontSize="22" fontWeight="800" fontFamily="inherit"
                  fill="#fff" stroke="rgba(5,5,7,0.65)" strokeWidth="3.5" paintOrder="stroke">
                {pReal.toFixed(0)}%
            </text>
            <text x={w / 2} y={h / 2 + 13} textAnchor="middle" dominantBaseline="central"
                  fontSize="7.5" letterSpacing="0.8" fontFamily="inherit"
                  fill="rgba(255,255,255,0.7)" stroke="rgba(5,5,7,0.6)" strokeWidth="2.2" paintOrder="stroke">
                DA META
            </text>
        </svg>
    );
}
