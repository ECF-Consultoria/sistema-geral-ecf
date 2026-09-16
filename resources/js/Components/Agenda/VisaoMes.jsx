import { addDays, isSameDay, isSameMonth } from 'date-fns';
import { cn } from '@/lib/utils';
import { corDoEvento, eventosDoDia, horaCurta, intervaloDaVisao, ymd } from './agenda';

const SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
const POR_DIA = 3;

/**
 * O mês em grade: até três compromissos por dia e "+N" para o resto. Clicar no
 * número (ou no "+N") abre o dia; clicar num compromisso abre o detalhe.
 */
export default function VisaoMes({ ancora, eventos = [], aoAbrirEvento, aoClicarDia, destaque = null }) {
    const [inicio, fim] = intervaloDaVisao('mes', ancora);
    const semanas = Math.round((fim - inicio) / (7 * 86400000));
    const dias = Array.from({ length: semanas * 7 }, (_, i) => addDays(inicio, i));
    const hoje = new Date();

    return (
        <div className="overflow-hidden rounded-xl border border-white/[0.06] bg-white/[0.015]">
            <div className="grid grid-cols-7 border-b border-white/[0.06]">
                {SEMANA.map((d) => (
                    <p key={d} className="py-2 text-center text-[11px] font-medium text-white/40">{d}</p>
                ))}
            </div>
            <div className="grid grid-cols-7">
                {dias.map((dia, i) => {
                    const doDia = eventosDoDia(eventos, dia);
                    const doMes = isSameMonth(dia, ancora);
                    const ehHoje = isSameDay(dia, hoje);

                    return (
                        <div
                            key={ymd(dia)}
                            onClick={() => aoClicarDia?.(dia)}
                            className={cn(
                                'min-h-[112px] cursor-pointer border-white/[0.05] p-1.5 transition-colors hover:bg-white/[0.02]',
                                i % 7 !== 0 && 'border-l',
                                i >= 7 && 'border-t',
                                ! doMes && 'bg-black/20',
                            )}
                        >
                            <span
                                className={cn(
                                    'mb-1 grid h-6 w-6 place-items-center rounded-full text-[12px] tabular-nums',
                                    ehHoje ? 'bg-ecf-yellow font-bold text-ecf-bg' : doMes ? 'text-white/75' : 'text-white/25',
                                )}
                            >
                                {dia.getDate()}
                            </span>
                            <div className="space-y-0.5">
                                {doDia.slice(0, POR_DIA).map((evento) => (
                                    <button
                                        key={evento.id}
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            aoAbrirEvento?.(evento);
                                        }}
                                        className={cn(
                                            'flex w-full items-center gap-1 truncate rounded px-1 py-[2px] text-left text-[11px] text-white/80 hover:bg-white/[0.06]',
                                            destaque && ! destaque(evento) && 'opacity-35',
                                            evento.sua_resposta === 'declined' && 'line-through opacity-50',
                                        )}
                                        title={evento.titulo}
                                    >
                                        <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', corDoEvento(evento).ponto)} />
                                        {! evento.dia_inteiro && (
                                            <span className="shrink-0 tabular-nums text-white/45">{horaCurta(new Date(evento.inicio))}</span>
                                        )}
                                        <span className="truncate">{evento.titulo}</span>
                                    </button>
                                ))}
                                {doDia.length > POR_DIA && (
                                    <p className="px-1 text-[10.5px] font-medium text-white/40">+{doDia.length - POR_DIA} mais</p>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
