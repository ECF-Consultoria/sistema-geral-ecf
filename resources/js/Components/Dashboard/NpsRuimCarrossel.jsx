import { useRef } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * NpsRuimCarrossel — card "NPS ruim" (Fase 97 Plan 04, DASH-97-5).
 *
 * Carrossel horizontal das respostas de nota baixa (`nps_ruins`, injetado
 * pelo DashboardController — Plan 97-02: nota <=3 na escala 1-5, já sem
 * invalidadas da Fase 96). Cada card linka "Abrir NPS completo →" pra
 * `nps.index` filtrado pela empresa da resposta.
 */
export default function NpsRuimCarrossel({ respostas = [] }) {
    const scrollRef = useRef(null);
    const lista = Array.isArray(respostas) ? respostas : [];

    const scrollBy = (delta) => {
        scrollRef.current?.scrollBy({ left: delta, behavior: 'smooth' });
    };

    return (
        <div className="card-ecf rounded-2xl p-6 h-full flex flex-col">
            <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
                <div className="flex items-center gap-2.5">
                    <p className="text-white font-display font-extrabold text-lg tracking-tight">NPS ruim</p>
                    <span
                        className={cn(
                            'text-[11px] font-bold rounded-full px-2 py-0.5',
                            lista.length > 0 ? 'bg-red-500/15 text-red-400' : 'bg-emerald-500/15 text-emerald-400',
                        )}
                    >
                        {lista.length} {lista.length === 1 ? 'resposta' : 'respostas'}
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    <Link
                        href={route('nps.index')}
                        className="text-[12px] font-semibold text-blue-400 hover:text-blue-300 transition-colors whitespace-nowrap"
                    >
                        Ver respostas completas →
                    </Link>
                    {lista.length > 0 && (
                        <div className="flex gap-1 shrink-0">
                            <button
                                type="button"
                                onClick={() => scrollBy(-290)}
                                aria-label="Anterior"
                                className="w-7 h-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/50 hover:text-white transition-colors flex items-center justify-center"
                            >
                                <ChevronLeft size={14} />
                            </button>
                            <button
                                type="button"
                                onClick={() => scrollBy(290)}
                                aria-label="Próximo"
                                className="w-7 h-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/50 hover:text-white transition-colors flex items-center justify-center"
                            >
                                <ChevronRight size={14} />
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {lista.length === 0 ? (
                <div className="flex-1 flex items-center justify-center py-9 text-center">
                    <p className="text-white/30 text-sm">Nenhuma nota baixa no período.</p>
                </div>
            ) : (
                <div ref={scrollRef} className="flex gap-3 overflow-x-auto pb-1 scroll-smooth items-stretch">
                    {lista.map((n) => {
                        // Cor da nota computada AQUI, DENTRO do callback do .map()
                        // — pitfall Rollup (Fase 97): nunca herdar flag/cor de
                        // variável de escopo externo. Nota <=2 é mais grave (vermelho
                        // forte) que 3 (laranja) dentro do próprio bucket "ruim" (<=3).
                        const notaGrave = (n.nota ?? 0) <= 2;
                        return (
                            <div
                                key={n.survey_id ?? `${n.company_id}-${n.data}`}
                                className="flex-none w-[270px] rounded-xl border border-white/[0.08] bg-white/[0.02] p-3.5 flex flex-col gap-2.5"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-white/85 text-[13px] font-bold truncate">{n.company_name ?? '—'}</p>
                                        <p className="text-white/30 text-[11px]">{n.data ?? '—'}</p>
                                    </div>
                                    <div
                                        className={cn(
                                            'shrink-0 w-8 h-8 rounded-lg flex items-center justify-center text-[15px] font-extrabold tabular-nums',
                                            notaGrave ? 'bg-red-500/15 text-red-400' : 'bg-orange-500/15 text-orange-400',
                                        )}
                                    >
                                        {n.nota ?? '—'}
                                    </div>
                                </div>
                                <p className="text-white/60 text-[12px] italic leading-snug line-clamp-3 min-h-[3em]">
                                    {n.comment ? `"${n.comment}"` : 'Sem comentário registrado.'}
                                </p>
                                <div className="flex gap-3 text-[10.5px] text-white/40 mt-auto">
                                    <span>Analista<br /><b className="text-white/70 font-semibold">{n.analista ?? '—'}</b></span>
                                    <span>Estrateg.<br /><b className="text-white/70 font-semibold">{n.estrategista ?? '—'}</b></span>
                                </div>
                                <Link
                                    href={route('nps.index', { empresa_id: n.company_id })}
                                    className="text-[12px] font-semibold text-blue-400 hover:text-blue-300 transition-colors"
                                >
                                    Abrir NPS completo →
                                </Link>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
