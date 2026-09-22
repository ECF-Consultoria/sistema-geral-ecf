import { cn } from '@/lib/utils';
import {
    PRIORIDADE_CLASSE, PRIORIDADE_CURTA, PRIORIDADE_LABELS,
    SITUACAO_CLASSE, SITUACAO_LABELS, STATUS_DOT, STATUS_LABELS,
} from '@/lib/demandasDev';

export function StatusSelo({ status, className }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap text-[12.5px] text-white/80', className)}>
            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', STATUS_DOT[status] ?? 'bg-white/30')} />
            {STATUS_LABELS[status] ?? status}
        </span>
    );
}

export function PrioridadeSelo({ prioridade, longo = false, className }) {
    return (
        <span
            title={PRIORIDADE_LABELS[prioridade]}
            className={cn('inline-flex items-center rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold', PRIORIDADE_CLASSE[prioridade], className)}
        >
            {longo ? PRIORIDADE_LABELS[prioridade] : PRIORIDADE_CURTA[prioridade]}
        </span>
    );
}

export function SituacaoSelo({ situacao, className }) {
    return (
        <span className={cn('inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-medium', SITUACAO_CLASSE[situacao], className)}>
            {SITUACAO_LABELS[situacao] ?? situacao}
        </span>
    );
}

// Campo rotulado — usado pelos formulários do módulo.
export function Campo({ label, erro, dica, children, className }) {
    return (
        <label className={cn('block space-y-1.5', className)}>
            <span className="text-[12px] font-medium text-white/60">{label}</span>
            {children}
            {dica && !erro && <span className="block text-[11.5px] text-white/40">{dica}</span>}
            {erro && <span className="block text-[11.5px] text-red-400">{erro}</span>}
        </label>
    );
}

export const inputClasse =
    'w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13.5px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/30';
