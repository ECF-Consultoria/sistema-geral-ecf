import { cn } from '@/lib/utils';
import { Minus, RefreshCw } from 'lucide-react';
import { haQuanto } from './tempo';

// Situação do Portal da empresa: sincronizado · novas · nunca · sem_portal.
export default function SeloPortal({ portal }) {
    const situacao = portal?.situacao ?? 'sem_portal';
    const base = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-1 text-[11px] font-bold';

    if (situacao === 'sincronizado') {
        const tempo = haQuanto(portal.sincronizado_em);
        return (
            <span className={cn(base, 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400')}>
                <RefreshCw className="h-3 w-3" aria-hidden="true" />
                <span>Sincronizado</span>
                {tempo && <span className="font-mono font-normal">{tempo}</span>}
            </span>
        );
    }

    if (situacao === 'novas') {
        const n = portal.novas ?? 0;
        return (
            <span className={cn(base, 'border-sky-500/25 bg-sky-500/[0.06] text-sky-200')}>
                <RefreshCw className="h-3 w-3" aria-hidden="true" />
                <span className="tabular-nums">{n}</span>
                <span>{n === 1 ? 'oferta nova' : 'ofertas novas'}</span>
            </span>
        );
    }

    if (situacao === 'nunca') {
        return (
            <span className={cn(base, 'border-sky-500/25 bg-sky-500/[0.06] italic text-sky-200')}>
                Nunca sincronizado
            </span>
        );
    }

    return (
        <span className={cn(base, 'border-white/[0.08] bg-white/[0.04] text-white/55')}>
            <Minus className="h-3 w-3" aria-hidden="true" />
            Sem Portal
        </span>
    );
}
