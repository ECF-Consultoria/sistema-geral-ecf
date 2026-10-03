import { Check, CheckCircle2, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Base dos cards da mesa de anúncio (UI-SPEC §8.4) ───────────────────────
//
// Cards de apresentação pura: recebem o contrato `m` e nunca chamam rota.
// Estado calmo: vazio obrigatório = borda âmbar; vermelho só para valor que o
// servidor recusou. Nada pisca e o card nunca recolhe sozinho.

export { estadoDasSecoes } from '../apoio';

/** Chip do cabeçalho: "Completo" (verde) ou "Falta 1" / "Faltam N" (neutro com ponto âmbar). */
export function ChipSecao({ faltam }) {
    if (faltam === 0) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-emerald-400" data-chip-secao="completo">
                <CheckCircle2 size={12} /> Completo
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-2 rounded-full bg-white/[0.04] px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-white/55" data-chip-secao={faltam}>
            <span className="h-1.5 w-1.5 rounded-full bg-amber-400" /> {faltam === 1 ? 'Falta 1' : `Faltam ${faltam}`}
        </span>
    );
}

/** Card da mesa: section + cabeçalho (ícone, título, apoio, chip) + chevron. Abre/fecha só por clique. */
export function CardMesa({ id, icone: Icone, titulo, apoio, chip, aberto = true, onAlternar, children }) {
    return (
        <section id={id} className="scroll-mt-20 rounded-xl border border-white/[0.08] bg-ecf-card p-6" data-card={id}>
            <div className="flex items-start gap-3">
                {Icone && (
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-white/[0.08] bg-white/[0.04] text-white/70">
                        <Icone size={16} />
                    </span>
                )}
                <div className="min-w-0 flex-1">
                    <h3 className="text-[15px] font-bold text-white">{titulo}</h3>
                    {apoio && <p className="text-[13px] font-normal text-white/55">{apoio}</p>}
                </div>
                {chip && <div className="shrink-0">{chip}</div>}
                <button type="button" onClick={onAlternar} aria-expanded={aberto} aria-controls={`${id}-corpo`}
                    aria-label={aberto ? `Recolher ${titulo}` : `Expandir ${titulo}`}
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-white/55 hover:bg-white/[0.05] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    <ChevronDown size={16} className={cn('transition-transform', aberto && 'rotate-180')} />
                </button>
            </div>
            {aberto && <div id={`${id}-corpo`} className="mt-6">{children}</div>}
        </section>
    );
}

/**
 * Um campo em "tile": rótulo 11px no topo; borda âmbar só se OBRIGATÓRIO vazio (o campo
 * que o ML não exige fica neutro — docx §6); vermelha só com recusa do servidor.
 */
export function Tile({ rotulo, preenchido = false, obrigatorio = true, problema = null, children }) {
    return (
        <div className={cn('h-full rounded-[10px] border bg-white/[0.03] p-3',
            problema ? 'border-red-500/30' : (preenchido || ! obrigatorio ? 'border-white/[0.08]' : 'border-amber-400/50'))}
            data-tile={preenchido ? 'ok' : (obrigatorio ? 'vazio' : 'opcional')}>
            <div className="mb-1 flex items-start justify-between gap-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                <span className="min-w-0 flex-1">{rotulo}</span>
                {preenchido && <Check size={14} className="shrink-0 text-emerald-400" aria-label="Preenchido" />}
            </div>
            {children}
        </div>
    );
}
