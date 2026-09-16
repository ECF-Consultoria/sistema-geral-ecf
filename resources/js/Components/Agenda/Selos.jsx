import { Building2, CalendarDays } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PLATAFORMAS, TIPOS, corDoEvento } from './agenda';

/** Por onde se entra: "Google Meet", "Microsoft Teams", "Presencial"… */
export function SeloPlataforma({ evento, className }) {
    const plataforma = PLATAFORMAS[evento?.plataforma];

    if (! plataforma) {
        return evento?.vinculo?.sem_convite
            ? <span className={cn('inline-flex items-center gap-1 text-white/40', className)}><CalendarDays size={11} /> Só no sistema</span>
            : null;
    }

    const Icone = plataforma.icone;

    return (
        <span className={cn('inline-flex min-w-0 items-center gap-1', className)}>
            <Icone size={11} className="shrink-0" />
            <span className="truncate">
                {evento.plataforma === 'presencial' && evento.local ? evento.local : plataforma.rotulo}
            </span>
        </span>
    );
}

/** De qual cliente é o evento. */
export function SeloEmpresa({ evento, className }) {
    if (! evento?.vinculo?.empresa) return null;

    return (
        <span className={cn('inline-flex min-w-0 items-center gap-1', className)} title={evento.vinculo.empresa}>
            <Building2 size={11} className="shrink-0" />
            <span className="truncate">{evento.vinculo.empresa}</span>
        </span>
    );
}

/** O tipo, com a cor que ele tem na grade. */
export function SeloTipo({ evento, className }) {
    const rotulo = TIPOS[evento?.tipo]?.rotulo ?? 'Google Agenda';

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full border border-white/[0.08] bg-white/[0.03] px-2 py-0.5 text-[11px] text-white/65', className)}>
            <span className={cn('h-2 w-2 rounded-full', corDoEvento(evento).ponto)} />
            {rotulo}
        </span>
    );
}
