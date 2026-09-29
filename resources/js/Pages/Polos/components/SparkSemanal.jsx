import { useEffect, useId, useState } from 'react';
import { cn, formatCurrency } from '@/lib/utils';

// Geometria do mini-gráfico, em PIXELS. A altura é fixa e a largura é a do card.
//
// Antes o SVG tinha viewBox fixo de 280×92 com `width="100%"`: a altura crescia
// junto com a largura. Na gaveta do Painel Polos o card chegava a ~1.500 px (a
// gaveta ocupava a tabela inteira), o gráfico passava de 500 px de altura e cada
// ponto virava uma bola de 30 px. Agora a largura é medida e a geometria é
// recalculada nela, com a altura sempre em H: círculo continua redondo e traço
// continua fino em qualquer largura.
const H = 96;
const PADX = 14;
const FAT_TOP = 10, FAT_BASE = 58;   // faixa da área de faturamento
const ADS_BASE = 92, ADS_TOP = 66;   // faixa das barras de ADS (sobem do base)
const LARGURA_INICIAL = 280;         // até a 1ª medição (mesma proporção de antes)

/** Largura do elemento, acompanhando redimensionamento. Ref por callback: o nó só existe quando há dado. */
function useLargura() {
    const [el, setEl] = useState(null);
    const [w, setW]   = useState(LARGURA_INICIAL);

    useEffect(() => {
        if (!el) return undefined;
        const medir = () => {
            const l = Math.round(el.clientWidth);
            if (l > 0) setW(l);
        };
        medir();
        if (typeof ResizeObserver === 'undefined') return undefined;
        const ro = new ResizeObserver(medir);
        ro.observe(el);
        return () => ro.disconnect();
    }, [el]);

    return [setEl, w];
}

// Data de hoje no fuso de quem olha, no formato das semanas (YYYY-MM-DD).
function hojeIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const ddmm = (iso) => (iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}` : '');

/**
 * SparkSemanal — micro-série intramês do faturamento semanal (Semana 1–4) como
 * área amarela + barras de investimento em ADS (sky) abaixo.
 *
 * Semana que ainda não começou fica FORA da linha: a Adman devolve 0 para ela,
 * e desenhar esse 0 fazia a curva despencar no começo de todo mês, como se a
 * empresa tivesse parado de vender. A semana em curso aparece com ponto vazado
 * e o rótulo "parcial" — o valor dela ainda vai subir.
 *
 * Props:
 *   semanas  : [{ semana, de, ate, faturamento, ads }]
 *   total    : faturamento total do mês
 *   totalAds : investimento total em ADS do mês
 *   fechado  : mês fechado (CSV)? ADS não disponível → nota em vez de barras zeradas
 */
export default function SparkSemanal({ semanas = [], total = 0, totalAds = 0, fechado = false }) {
    const uid = useId().replace(/[:]/g, '');
    const [refLargura, W] = useLargura();
    const n = semanas.length;

    const semFaturamento = n === 0 || semanas.every((s) => (s.faturamento ?? 0) <= 0);
    if (semFaturamento) {
        return (
            <p className="text-white/40 text-xs">Sem faturamento semanal disponível.</p>
        );
    }

    const hoje    = hojeIso();
    const futura  = (s) => !fechado && !!s.de && s.de > hoje;
    const parcial = (s) => !fechado && !!s.de && !!s.ate && s.de <= hoje && s.ate > hoje;

    const xAt       = (i) => (n === 1 ? W / 2 : PADX + (i / (n - 1)) * (W - PADX * 2));
    const plotadas  = semanas.map((s, i) => ({ s, i })).filter(({ s }) => !futura(s));
    const maxFat    = Math.max(...plotadas.map(({ s }) => s.faturamento ?? 0), 1);
    const maxAds    = Math.max(...semanas.map((s) => s.ads ?? 0), 1);
    const yFat      = (v) => FAT_BASE - ((v ?? 0) / maxFat) * (FAT_BASE - FAT_TOP);

    const pts      = plotadas.map(({ s, i }) => ({ s, x: xAt(i), y: yFat(s.faturamento) }));
    const linePath = pts.map((p, k) => `${k === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
    const areaPath = pts.length > 1
        ? `${linePath} L ${pts[pts.length - 1].x.toFixed(1)} ${FAT_BASE} L ${pts[0].x.toFixed(1)} ${FAT_BASE} Z`
        : null;

    const temAds = totalAds > 0;

    return (
        <div ref={refLargura}>
            {/* width 100% (e não W): largura fixa em px travaria o card no tamanho antigo
                quando a janela encolhe, e o ResizeObserver nunca veria a largura menor. */}
            <svg width="100%" height={H} viewBox={`0 0 ${W} ${H}`} className="block overflow-visible">
                <defs>
                    <linearGradient id={`${uid}-fill`} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%"   stopColor="#ffe600" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#ffe600" stopOpacity="0" />
                    </linearGradient>
                </defs>

                {/* Linha de base da faixa de faturamento */}
                <line x1={PADX} x2={W - PADX} y1={FAT_BASE} y2={FAT_BASE} stroke="rgba(255,255,255,0.06)" />

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
                {areaPath && <path d={areaPath} fill={`url(#${uid}-fill)`} />}
                {pts.length > 1 && (
                    <path d={linePath} fill="none" stroke="#ffe600" strokeWidth="2" strokeLinejoin="round"
                          strokeLinecap="round" />
                )}

                {/* Pontos com halo; a semana em curso é vazada. O <title> é o tooltip nativo. */}
                {pts.map(({ s, x, y }) => (
                    <g key={`pt-${s.semana}`}>
                        <title>
                            {`Semana ${s.semana} (${ddmm(s.de)}–${ddmm(s.ate)})${parcial(s) ? ' · parcial' : ''}\n`
                                + `Faturamento: ${formatCurrency(s.faturamento ?? 0)}`
                                + (temAds ? `\nADS: ${formatCurrency(s.ads ?? 0)}` : '')}
                        </title>
                        <circle cx={x} cy={y} r={9} fill="transparent" />
                        <circle cx={x} cy={y} r={5.5} fill="#ffe600" opacity="0.2" />
                        {parcial(s)
                            ? <circle cx={x} cy={y} r={2.8} fill="#0f1116" stroke="#ffe600" strokeWidth="1.5" />
                            : <circle cx={x} cy={y} r={2.6} fill="#ffe600" />}
                    </g>
                ))}
            </svg>

            {/* Rótulos das semanas sob o x do seu ponto. As pontas ancoram para dentro
                ("S4 · parcial" centrado na última semana vazaria do card). */}
            <div className="relative mt-1 h-3.5 text-[10px] leading-none">
                {semanas.map((s, i) => {
                    const ancora = n > 1 && i === 0 ? { left: xAt(i) - 6 }
                        : n > 1 && i === n - 1 ? { right: W - xAt(i) - 6 }
                        : { left: xAt(i), transform: 'translateX(-50%)' };
                    return (
                        <span
                            key={s.semana}
                            className={cn('absolute whitespace-nowrap', futura(s) ? 'text-white/15' : 'text-white/35')}
                            style={ancora}
                        >
                            S{s.semana}{parcial(s) && <span className="text-white/25"> · parcial</span>}
                        </span>
                    );
                })}
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
