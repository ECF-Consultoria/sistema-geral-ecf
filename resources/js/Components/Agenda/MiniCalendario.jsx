import { addDays, addMonths, format, isSameDay, isSameMonth, startOfMonth, startOfWeek } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { primeiraMaiuscula, ymd } from './agenda';

const LETRAS = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

/**
 * O mês em miniatura: hoje em amarelo, o dia escolhido com anel, e um ponto
 * por cor de evento (no máximo três) nos dias que têm alguma coisa.
 *
 * Seis semanas sempre — o cartão não muda de altura ao trocar de mês.
 */
export default function MiniCalendario({
    mes,
    aoMudarMes,
    selecionado = null,
    aoSelecionar,
    marcas = new Map(),
    compacto = false,
    className,
}) {
    const hoje = new Date();
    const primeiro = startOfWeek(startOfMonth(mes), { weekStartsOn: 0 });
    const dias = Array.from({ length: 42 }, (_, i) => addDays(primeiro, i));

    return (
        <div className={cn('select-none', className)}>
            <div className="mb-2 flex items-center justify-between gap-2">
                <p className="text-[13px] font-semibold text-white/85">
                    {primeiraMaiuscula(format(mes, "MMMM 'de' yyyy", { locale: ptBR }))}
                </p>
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => aoMudarMes(addMonths(mes, -1))}
                        aria-label="Mês anterior"
                        className="grid h-7 w-7 place-items-center rounded-lg text-white/50 transition-colors hover:bg-white/[0.06] hover:text-white"
                    >
                        <ChevronLeft size={15} />
                    </button>
                    <button
                        type="button"
                        onClick={() => aoMudarMes(addMonths(mes, 1))}
                        aria-label="Próximo mês"
                        className="grid h-7 w-7 place-items-center rounded-lg text-white/50 transition-colors hover:bg-white/[0.06] hover:text-white"
                    >
                        <ChevronRight size={15} />
                    </button>
                </div>
            </div>

            <div className="grid grid-cols-7 text-center">
                {LETRAS.map((letra, i) => (
                    <span key={i} className="pb-1 text-[10.5px] font-medium text-white/35">{letra}</span>
                ))}

                {dias.map((dia) => {
                    const doMes = isSameMonth(dia, mes);
                    const ehHoje = isSameDay(dia, hoje);
                    const ehEscolhido = selecionado && isSameDay(dia, selecionado);
                    const pontos = marcas.get(ymd(dia)) ?? [];

                    return (
                        <button
                            key={ymd(dia)}
                            type="button"
                            onClick={() => aoSelecionar?.(dia)}
                            title={format(dia, "EEEE, d 'de' MMMM", { locale: ptBR })}
                            className={cn(
                                'group relative flex flex-col items-center justify-start rounded-lg transition-colors',
                                compacto ? 'h-8 pt-[3px]' : 'h-9 pt-1',
                                'hover:bg-white/[0.05]',
                            )}
                        >
                            <span
                                className={cn(
                                    'grid place-items-center rounded-full text-[12px] tabular-nums',
                                    compacto ? 'h-6 w-6' : 'h-[26px] w-[26px]',
                                    ehHoje && 'bg-ecf-yellow font-bold text-ecf-bg',
                                    ! ehHoje && ehEscolhido && 'ring-1 ring-ecf-yellow/70 text-ecf-yellow',
                                    ! ehHoje && ! ehEscolhido && (doMes ? 'text-white/75' : 'text-white/20'),
                                )}
                            >
                                {dia.getDate()}
                            </span>
                            {pontos.length > 0 && (
                                <span className="absolute bottom-[2px] flex gap-[3px]">
                                    {pontos.map((classe) => (
                                        <span key={classe} className={cn('h-[4px] w-[4px] rounded-full', classe, ! doMes && 'opacity-40')} />
                                    ))}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
