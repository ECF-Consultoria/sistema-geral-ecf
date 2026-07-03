import { cn } from '@/lib/utils';

/**
 * SegBar — barra segmentada clicável + legenda (estilo "barra de linguagens" do GitHub).
 * Densa e escaneável: cada segmento/rótulo é um filtro acionável. Reutilizável.
 * segmentos: [{ label, count, cor (css/hex), active, onClick }].
 */
export default function SegBar({ segmentos = [], total, className }) {
    const soma = total ?? segmentos.reduce((a, s) => a + (s.count || 0), 0);
    const comValor = segmentos.filter((s) => s.count > 0);
    return (
        <div className={cn('space-y-2', className)}>
            <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-white/[0.05]">
                {soma > 0 && comValor.map((s) => (
                    <button key={s.value ?? s.label} type="button" onClick={s.onClick} title={`${s.label}: ${s.count}`}
                        style={{ width: `${(s.count / soma) * 100}%`, backgroundColor: s.cor || '#6b7280' }}
                        className={cn('h-full transition-all hover:opacity-80', s.active && 'ring-2 ring-inset ring-white/80')} />
                ))}
            </div>
            <div className="flex flex-wrap gap-x-1.5 gap-y-1">
                {segmentos.map((s) => (
                    <button key={s.value ?? s.label} type="button" onClick={s.onClick} disabled={!s.count && !s.active} title={`Filtrar: ${s.label}`}
                        className={cn('inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[11px] transition-colors',
                            (!s.count && !s.active) ? 'cursor-default' : 'hover:bg-white/[0.06]',
                            s.active && 'bg-ecf-yellow/10 ring-1 ring-ecf-yellow/50',
                            !s.count && 'opacity-40')}>
                        <span className="h-2 w-2 shrink-0 rounded-sm" style={{ backgroundColor: s.cor || '#6b7280' }} />
                        <span className="text-white/70">{s.label}</span>
                        <span className="font-semibold tabular-nums text-white/90">{s.count ?? 0}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}
