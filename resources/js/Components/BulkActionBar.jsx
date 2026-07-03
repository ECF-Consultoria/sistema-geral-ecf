import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * BulkActionBar — barra flutuante de ações em lote (aparece quando há seleção).
 * Presentacional/reutilizável: mostra "N selecionadas" + um slot de ações + limpar.
 * As ações (popovers de valor, "editar vários") são passadas como children pelo pai.
 * Tema ecf-*; ancorada no rodapé (não empurra o layout).
 */
export default function BulkActionBar({ count = 0, onClear, children, busy = false }) {
    if (!count) return null;
    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-5 z-40 flex justify-center px-4 animate-in fade-in slide-in-from-bottom-4 duration-200">
            <div className="pointer-events-auto flex max-w-[95vw] items-center gap-2 overflow-x-auto rounded-2xl border border-white/[0.1] bg-ecf-card/95 px-3 py-2 shadow-2xl shadow-black/60 ring-1 ring-black/20 backdrop-blur">
                <span className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-ecf-yellow/15 px-2.5 py-1.5 text-[12px] font-bold tabular-nums text-ecf-yellow">
                    {count} selecionada{count === 1 ? '' : 's'}
                </span>
                <div className="h-5 w-px shrink-0 bg-white/10" />
                <div className={cn('flex items-center gap-1.5', busy && 'pointer-events-none opacity-50')}>
                    {children}
                </div>
                <div className="h-5 w-px shrink-0 bg-white/10" />
                <button type="button" onClick={onClear} title="Limpar seleção"
                    className="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1.5 text-[12px] text-white/50 transition hover:bg-white/[0.06] hover:text-white">
                    <X size={13} /> Limpar
                </button>
            </div>
        </div>
    );
}
