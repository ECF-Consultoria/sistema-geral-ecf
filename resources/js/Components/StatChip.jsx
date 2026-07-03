import { cn } from '@/lib/utils';

/**
 * StatChip — pílula de indicador clicável (label + contagem), com estado ativo (anel).
 * Reutilizável: dá o "clique no indicador → ação" dos dashboards acionáveis.
 * tone: 'red' | 'amber' | 'green' | 'yellow' | 'neutral'.
 */
const TONES = {
    red:     'text-red-300 border-red-500/25 bg-red-500/10 hover:bg-red-500/[0.16]',
    amber:   'text-amber-300 border-amber-500/25 bg-amber-500/10 hover:bg-amber-500/[0.16]',
    green:   'text-emerald-300 border-emerald-500/25 bg-emerald-500/10 hover:bg-emerald-500/[0.16]',
    yellow:  'text-ecf-yellow border-ecf-yellow/25 bg-ecf-yellow/10 hover:bg-ecf-yellow/[0.16]',
    neutral: 'text-white/70 border-white/10 bg-white/[0.04] hover:bg-white/[0.08]',
};

export default function StatChip({ label, count, tone = 'neutral', active = false, onClick, icon: Icon, title }) {
    // Contagem 0 = filtrar levaria a uma grade vazia → não clicável (a menos que já ativo, p/ togglear).
    const desabilitado = typeof onClick !== 'function' || (!count && !active);
    return (
        <button type="button" onClick={onClick} disabled={desabilitado} title={title ?? label}
            className={cn('inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12px] font-medium transition-all',
                TONES[tone] ?? TONES.neutral,
                active && 'ring-2 ring-ecf-yellow/70',
                !count && 'opacity-40',
                desabilitado ? 'cursor-default' : 'cursor-pointer')}>
            {Icon && <Icon size={12} className="shrink-0" />}
            <span className="truncate">{label}</span>
            <span className="font-bold tabular-nums">{count ?? 0}</span>
        </button>
    );
}
