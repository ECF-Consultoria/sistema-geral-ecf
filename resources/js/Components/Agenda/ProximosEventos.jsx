import { startOfDay } from 'date-fns';
import { cn } from '@/lib/utils';
import { corDoEvento, distancia, faixaDeHorario, ordenarPorInicio, rotuloDoDia, tituloSemEmpresa, ymd } from './agenda';
import { SeloEmpresa, SeloPlataforma } from './Selos';

/**
 * A lista dos próximos compromissos, agrupada por dia ("Hoje", "Amanhã",
 * "qua., 17 de set.").
 *
 * `mostrarEmpresa` fica desligado na ficha do onboarding — lá todo evento é da
 * mesma empresa, e repetir o nome só ocupa linha.
 */
export default function ProximosEventos({
    eventos = [],
    limite = 3,
    aoAbrir,
    mostrarEmpresa = true,
    mostrarDistancia = true,
    vazio = 'Nenhum compromisso pela frente.',
    className,
}) {
    const agora = new Date();
    const lista = ordenarPorInicio(eventos).slice(0, limite);

    if (lista.length === 0) {
        return <p className={cn('py-3 text-center text-[12px] text-white/35', className)}>{vazio}</p>;
    }

    const grupos = [];
    lista.forEach((evento) => {
        const dia = startOfDay(new Date(evento.inicio));
        const chave = ymd(dia);
        const ultimo = grupos[grupos.length - 1];
        if (ultimo?.chave === chave) {
            ultimo.eventos.push(evento);
        } else {
            grupos.push({ chave, dia, eventos: [evento] });
        }
    });

    return (
        <div className={cn('space-y-2.5', className)}>
            {grupos.map((grupo) => (
                <div key={grupo.chave}>
                    <p className="mb-1 text-[10.5px] font-semibold uppercase tracking-wide text-white/35">
                        {rotuloDoDia(grupo.dia, agora)}
                    </p>
                    <div className="space-y-1">
                        {grupo.eventos.map((evento) => (
                            <button
                                key={evento.id}
                                type="button"
                                onClick={() => aoAbrir?.(evento)}
                                className="group flex w-full items-start gap-2.5 rounded-lg px-1.5 py-1.5 text-left transition-colors hover:bg-white/[0.04]"
                            >
                                <span className={cn('mt-1 h-[30px] w-[3px] shrink-0 rounded-full', corDoEvento(evento).barra)} />
                                <span className="min-w-0 flex-1">
                                    <span className="block text-[11px] tabular-nums text-white/45">{faixaDeHorario(evento)}</span>
                                    <span
                                        className="block truncate text-[12.5px] font-medium text-white/85 group-hover:text-white"
                                        title={evento.titulo}
                                    >
                                        {mostrarEmpresa ? evento.titulo : tituloSemEmpresa(evento)}
                                    </span>
                                    <span className="flex min-w-0 items-center gap-2 text-[11px] text-white/40">
                                        <SeloPlataforma evento={evento} className="max-w-[60%]" />
                                        {mostrarEmpresa && <SeloEmpresa evento={evento} className="min-w-0" />}
                                    </span>
                                </span>
                                {mostrarDistancia && (
                                    <span className="mt-0.5 shrink-0 text-[11px] text-white/40">{distancia(evento, agora)}</span>
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
