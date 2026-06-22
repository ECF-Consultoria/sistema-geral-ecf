import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { cn, formatCurrency } from '@/lib/utils';
import { STATUS_META } from './statusMeta';
import StatusBadge from './StatusBadge';

/**
 * RankingProgresso — ranking denso de "% da meta" por polo (estilo barras de
 * progresso). Substitui a grade de DonutCard/CityGauge: lê mais rápido e ordena
 * por desempenho. Cada linha abre o PoloDrawer (onPolo). A cor da barra é a do
 * STATUS (saúde vs meta), não a do polo — o ponto à esquerda carrega a cor do polo.
 *
 * Props:
 *   polos     : polos visíveis ({ polo, faturamento, meta, pct, status, ativos, empresas })
 *   corDoPolo : mapa polo → cor (POLO_PALETTE) — usado no ponto de identidade
 *   onPolo    : (polo) => void — abre o drawer
 *   fechado   : mês fechado (CSV)? alertas indisponíveis → exibe "—"
 */
export default function RankingProgresso({ polos = [], corDoPolo = {}, onPolo, fechado = false }) {
    // Anima a largura das barras 0 → pct na montagem
    const [mounted, setMounted] = useState(false);
    useEffect(() => {
        const id = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(id);
    }, []);

    const ord = useMemo(
        () => [...polos].sort((a, b) => (b.pct ?? 0) - (a.pct ?? 0)),
        [polos],
    );

    return (
        <div className={cn('space-y-0.5', ord.length > 12 && 'max-h-[560px] overflow-y-auto pr-1')}>
            {ord.map((p) => {
                const cor   = STATUS_META[p.status]?.cor ?? '#94a3b8';
                const pct   = Number(p.pct) || 0;
                const width = mounted ? Math.min(pct, 100) : 0;
                const alertas = fechado
                    ? null
                    : (p.empresas ?? []).filter((e) => e.ads_desligado === true || e.problema === true).length;

                return (
                    <button
                        key={p.polo}
                        type="button"
                        onClick={() => onPolo?.(p)}
                        title={`Ver empresas do polo ${p.polo}`}
                        className="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-white/[0.03] focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40"
                    >
                        {/* Identidade do polo */}
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: corDoPolo[p.polo] ?? '#fff' }} />
                        <span className="w-32 shrink-0 truncate text-sm text-white/85 sm:w-36">{p.polo}</span>

                        {/* Barra de progresso (cap neon quando passa de 100%) */}
                        <div className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                            <div
                                className="h-full rounded-full transition-[width] duration-700 ease-out"
                                style={{
                                    width: `${width}%`,
                                    background: `linear-gradient(90deg, ${cor}cc, ${cor})`,
                                    boxShadow: `0 0 12px ${cor}33`,
                                }}
                            />
                            {pct > 100 && (
                                <span className="absolute right-0 top-0 h-full w-1 bg-white shadow-[0_0_8px_#fff]" />
                            )}
                        </div>

                        {/* Faturado / meta (escondido em telas estreitas) */}
                        <span className="hidden w-40 shrink-0 text-right text-xs tabular-nums text-white/65 sm:inline">
                            {formatCurrency(p.faturamento)} / {formatCurrency(p.meta)}
                        </span>

                        {/* % da meta */}
                        <span className="w-12 shrink-0 text-right text-sm font-semibold tabular-nums" style={{ color: cor }}>
                            {pct.toFixed(0)}%
                        </span>

                        {/* Status + alertas */}
                        <span className="hidden shrink-0 md:inline"><StatusBadge status={p.status} /></span>
                        {alertas > 0 && (
                            <span className="inline-flex shrink-0 items-center gap-1 text-[10px] text-rose-300" title="Empresas com ADS desligado ou problema">
                                <AlertTriangle size={11} /> {alertas}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
