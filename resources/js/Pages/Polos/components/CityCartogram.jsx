import { useMemo } from 'react';
import { shapeForPolo } from './cityShapes';
import { formatCurrency, cn } from '@/lib/utils';

/**
 * CityCartogram — cartograma de cidades escaladas por faturamento (quick 260619-fq7).
 *
 * Recebe os itens de distribuição de faturamento (já ordenados por valor desc) e
 * renderiza cada cidade como sua silhueta real (IBGE via cityShapes.js), ESCALADA
 * pelo faturamento. Diferente do CityGauge (que enche por %), aqui o que varia é
 * o TAMANHO da cidade: área visual ∝ faturamento do polo.
 *
 * Cada cidade é preenchida SÓLIDA na cor do polo (POLO_PALETTE), com contorno e
 * glow no estilo do CityGauge. Cidades lado a lado, alinhadas pela base (baseline
 * comum), com valor (BRL) + % do total abaixo.
 *
 * Props:
 *   itens     : { polo, cor, faturamento, share }[] — ordenado por faturamento desc
 *   maxHeight : altura em px da MAIOR cidade (default 130)
 *   className : classes extras para o wrapper
 */
export default function CityCartogram({ itens = [], maxHeight = 130, className }) {
    // Calcula escala e dimensões de cada item de forma memorizada
    const cidades = useMemo(() => {
        const maxFat = Math.max(...itens.map((i) => i.faturamento || 0), 0);

        // Sem dados: retornar lista vazia (estado vazio tratado no render)
        if (maxFat === 0) return [];

        return itens.map((i) => {
            // Escala linear baseada na RAIZ QUADRADA do faturamento:
            // para a ÁREA crescer proporcional ao faturamento, o fator de escala
            // linear deve ser sqrt(fat/maxFat) — ex: cidade com 1/4 do fat fica
            // com 1/2 do tamanho linear (e 1/4 da área).
            const scale = Math.sqrt((i.faturamento || 0) / maxFat);

            // Piso visual: cidades muito pequenas ganham no mínimo 18% do tamanho
            // para não sumirem completamente. A maior sempre fica com scale=1.
            const scaleVis = Math.max(scale, 0.18);

            const shape = shapeForPolo(i.polo);

            // Altura renderizada em px = maxHeight * scaleVis
            // Largura é automática via viewBox (mantém aspect-ratio do contorno).
            // Escolha simples: altura fixa escalada, largura = auto. O que importa
            // é que o tamanho visual reflita sqrt(fat), não a precisão do aspect-ratio.
            const heightPx = maxHeight * scaleVis;

            return { ...i, scale, scaleVis, shape, heightPx };
        });
    }, [itens, maxHeight]);

    // Estado vazio: retornar null (a rose já exibe "Sem faturamento")
    if (cidades.length === 0) return null;

    return (
        <div className={cn('flex items-end gap-5 overflow-x-auto pb-2', className)}>
            {cidades.map((c) => {
                const slug = String(c.polo).toLowerCase()
                    .normalize('NFD').replace(/[̀-ͯ]/g, '')
                    .replace(/[^a-z0-9]/g, '');
                const glowId = `ccarto-glow-${slug}`;
                const pad    = 6;

                return (
                    /* Coluna por cidade: svg acima + labels abaixo, alinhada pela base */
                    <div
                        key={c.polo}
                        className="flex flex-col items-center gap-1.5 hover:bg-white/[0.03] rounded-lg px-2 py-1 transition-colors shrink-0"
                        title={`${c.polo} · ${formatCurrency(c.faturamento)} · ${c.share.toFixed(1)}%`}
                    >
                        {c.shape ? (
                            /* Silhueta real da cidade (IBGE) escalada por scaleVis */
                            <svg
                                viewBox={`${-pad} ${-pad} ${c.shape.w + pad * 2} ${c.shape.h + pad * 2}`}
                                height={c.heightPx}
                                style={{ width: 'auto', display: 'block' }}
                                aria-hidden="true"
                            >
                                <defs>
                                    <filter id={glowId} x="-30%" y="-30%" width="160%" height="160%">
                                        <feDropShadow
                                            dx="0" dy="0"
                                            stdDeviation="2.5"
                                            floodColor={c.cor}
                                            floodOpacity="0.6"
                                        />
                                    </filter>
                                </defs>

                                {/* Leito (fundo levemente visível dentro da silhueta) */}
                                <path
                                    d={c.shape.path}
                                    fill="rgba(255,255,255,0.04)"
                                    fillRule="evenodd"
                                />

                                {/* Preenchimento sólido na cor do polo (fillRule=evenodd
                                    para respeitar furos internos — ex: São Bento do Sul) */}
                                <path
                                    d={c.shape.path}
                                    fill={c.cor}
                                    fillRule="evenodd"
                                    opacity="0.88"
                                />

                                {/* Contorno com glow */}
                                <path
                                    d={c.shape.path}
                                    fill="none"
                                    stroke={c.cor}
                                    strokeWidth="1.4"
                                    strokeLinejoin="round"
                                    fillRule="evenodd"
                                    filter={`url(#${glowId})`}
                                />
                            </svg>
                        ) : (
                            /* Fallback para polo sem contorno mapeado: marcador circular
                               escalado pelo faturamento, mesma identidade visual */
                            <svg
                                width={c.heightPx * 0.7}
                                height={c.heightPx * 0.7}
                                style={{ display: 'block' }}
                                aria-hidden="true"
                            >
                                <defs>
                                    <filter id={glowId} x="-50%" y="-50%" width="200%" height="200%">
                                        <feDropShadow
                                            dx="0" dy="0"
                                            stdDeviation="3"
                                            floodColor={c.cor}
                                            floodOpacity="0.55"
                                        />
                                    </filter>
                                </defs>
                                <circle
                                    cx={c.heightPx * 0.35}
                                    cy={c.heightPx * 0.35}
                                    r={c.heightPx * 0.3}
                                    fill={c.cor}
                                    opacity="0.88"
                                    filter={`url(#${glowId})`}
                                />
                                <circle
                                    cx={c.heightPx * 0.35}
                                    cy={c.heightPx * 0.35}
                                    r={c.heightPx * 0.3}
                                    fill="none"
                                    stroke={c.cor}
                                    strokeWidth="1.4"
                                />
                            </svg>
                        )}

                        {/* Labels: valor BRL + share % */}
                        <div className="flex flex-col items-center gap-0.5 min-w-0">
                            <span className="text-white font-semibold text-[11px] tabular-nums whitespace-nowrap">
                                {formatCurrency(c.faturamento)}
                            </span>
                            <span className="text-white/40 text-[10px] tabular-nums">
                                {c.share.toFixed(1)}%
                            </span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
