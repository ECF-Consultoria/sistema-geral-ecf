import { format, startOfDay } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { CalendarDays } from 'lucide-react';
import { cn } from '@/lib/utils';
import { corDoEvento, faixaDeHorario, ordenarPorInicio, primeiraMaiuscula, rotuloDoDia, TIPOS, ymd } from './agenda';
import { SeloEmpresa, SeloPlataforma } from './Selos';

/** A agenda em lista, um bloco por dia — boa para ler o mês que vem de uma vez. */
export default function VisaoLista({ eventos = [], aoAbrirEvento, destaque = null }) {
    if (eventos.length === 0) {
        return (
            <div className="grid place-items-center rounded-xl border border-dashed border-white/[0.08] py-16 text-center">
                <CalendarDays size={28} className="mb-2 text-white/20" />
                <p className="text-[13px] text-white/50">Nenhum compromisso nos próximos 30 dias.</p>
            </div>
        );
    }

    const grupos = [];
    ordenarPorInicio(eventos).forEach((evento) => {
        const dia = startOfDay(new Date(evento.inicio));
        const ultimo = grupos[grupos.length - 1];
        if (ultimo && ultimo.chave === ymd(dia)) {
            ultimo.eventos.push(evento);
        } else {
            grupos.push({ chave: ymd(dia), dia, eventos: [evento] });
        }
    });

    return (
        <div className="divide-y divide-white/[0.05] overflow-hidden rounded-xl border border-white/[0.06] bg-white/[0.015]">
            {grupos.map((grupo) => (
                <div key={grupo.chave} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:gap-6">
                    <div className="w-40 shrink-0">
                        <p className="text-[13px] font-semibold text-white/85">{rotuloDoDia(grupo.dia)}</p>
                        <p className="text-[11px] text-white/35">
                            {primeiraMaiuscula(format(grupo.dia, "EEEE, d 'de' MMMM", { locale: ptBR }))}
                        </p>
                    </div>
                    <div className="min-w-0 flex-1 space-y-1">
                        {grupo.eventos.map((evento) => (
                            <button
                                key={evento.id}
                                type="button"
                                onClick={() => aoAbrirEvento?.(evento)}
                                className={cn(
                                    'grid w-full grid-cols-[110px_minmax(0,1fr)] items-start gap-3 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-white/[0.04]',
                                    destaque && ! destaque(evento) && 'opacity-40',
                                )}
                            >
                                <span className="flex items-center gap-2 pt-0.5 text-[12px] tabular-nums text-white/55">
                                    <span className={cn('h-2 w-2 shrink-0 rounded-full', corDoEvento(evento).ponto)} />
                                    {faixaDeHorario(evento)}
                                </span>
                                <span className="min-w-0">
                                    <span className={cn(
                                        'block truncate text-[13px] font-medium text-white/90',
                                        evento.sua_resposta === 'declined' && 'line-through text-white/45',
                                    )}>
                                        {evento.titulo}
                                    </span>
                                    <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-0.5 text-[11.5px] text-white/40">
                                        {evento.tipo && <span>{TIPOS[evento.tipo]?.rotulo}</span>}
                                        <SeloEmpresa evento={evento} />
                                        <SeloPlataforma evento={evento} />
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
