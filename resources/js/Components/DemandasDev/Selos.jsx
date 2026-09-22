import { cn } from '@/lib/utils';
import { PRIORIDADE_CURTA, PRIORIDADE_LABELS, SITUACAO_LABELS, STATUS_LABELS } from '@/lib/demandasDev';

// Mapas de classe moram AQUI (e não em lib/*.js): o Tailwind só varre arquivos .jsx.

// Cor de cada status. Os quatro de trabalho ativo vêm das variáveis --dd-* (app.css /
// light.css), validadas para daltonismo nos dois temas; backlog e encerrados são neutros.
export const STATUS_COR = {
    backlog:            'bg-white/30',
    a_fazer:            'bg-[var(--dd-afazer)]',
    em_desenvolvimento: 'bg-[var(--dd-dev)]',
    em_validacao:       'bg-[var(--dd-validacao)]',
    bloqueado:          'bg-[var(--dd-bloqueado)]',
    concluido:          'bg-emerald-500',
    cancelado:          'bg-white/20',
};

const PRIORIDADE_CLASSE = {
    0: 'bg-red-500/15 text-red-400 ring-1 ring-inset ring-red-500/30',
    1: 'bg-amber-500/15 text-amber-400',
    2: 'bg-white/[0.05] text-white/70',
    3: 'bg-white/[0.03] text-white/40',
};

const SITUACAO_CLASSE = {
    bloqueado:     'bg-orange-500/15 text-orange-400',
    atrasada:      'bg-red-500/15 text-red-400',
    prazo_proximo: 'bg-amber-500/15 text-amber-400',
    no_prazo:      'bg-emerald-500/10 text-emerald-400',
    sem_prazo:     'bg-white/[0.04] text-white/50',
    concluido:     'bg-sky-500/10 text-sky-400',
    cancelado:     'bg-white/[0.03] text-white/40 line-through',
};

export function StatusSelo({ status, className }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap text-[12.5px] text-white/80', className)}>
            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', STATUS_COR[status] ?? 'bg-white/30')} />
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
