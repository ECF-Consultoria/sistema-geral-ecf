import { AlertTriangle, Check, CheckCircle2, ChevronDown } from 'lucide-react';
import Problemas from '../Problemas';
import { cn } from '@/lib/utils';

// ─── Base dos cards da mesa de anúncio (UI-SPEC §8.4; Conceito E, 03/10/2026) ──
//
// Cada card da mesa é o FORMULÁRIO de um item da árvore (`ItemDoCentro.jsx` dá
// a moldura: trilha, título, pílula, ações, pendências). Cards de apresentação
// pura: recebem o contrato `m` e nunca chamam rota. Estado calmo: vazio
// obrigatório = borda âmbar; vermelho só para valor que o servidor recusou.

export { estadoDasSecoes } from '../apoio';

// Até este tanto de pendências a lista aparece inteira; acima, só a primeira e "e mais N" (abre no clique).
const PENDENCIAS_A_VISTA = 3;

/** Chip "Completo" (verde) ou "Falta 1" / "Faltam N" (neutro com ponto âmbar). */
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

/** Ponto de status da árvore: verde pronto, âmbar faltando, neutro quando ainda não dá para saber. */
export function PontoDeStatus({ faltam, pequeno = false, className }) {
    const tom = faltam === null || faltam === undefined ? 'bg-white/20' : (faltam === 0 ? 'bg-emerald-400' : 'bg-amber-400');
    const titulo = faltam === null || faltam === undefined ? 'Sem informação' : (faltam === 0 ? 'Pronto' : (faltam === 1 ? 'Falta 1' : `Faltam ${faltam}`));

    return <span className={cn('shrink-0 rounded-full', pequeno ? 'h-1.5 w-1.5' : 'h-2 w-2', tom, className)} title={titulo} aria-label={titulo} data-ponto={faltam === 0 ? 'pronto' : 'falta'} />;
}

/**
 * As pendências que o servidor aponta para um item. Poucas: a lista inteira. Muitas (a
 * ficha com 10 campos vazios): a primeira mensagem e "e mais N" — os campos já mostram
 * o próprio estado em âmbar, a lista é o complemento, não o alarme. Abre no clique.
 */
export function PendenciasDaSecao({ problemas }) {
    if (problemas.length <= PENDENCIAS_A_VISTA) return <Problemas problemas={problemas} />;
    const primeira = problemas.find((p) => p.severidade === 'BLOCKER') ?? problemas[0];
    const resto = problemas.length - 1;

    return (
        <details className="group">
            <summary className="flex cursor-pointer list-none items-start gap-2 rounded text-[13px] font-normal text-amber-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow [&::-webkit-details-marker]:hidden">
                <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
                <span className="min-w-0 flex-1">{primeira.mensagem} <span className="text-white/55">e mais {resto === 1 ? '1 pendência' : `${resto} pendências`}</span></span>
                <ChevronDown size={14} className="mt-0.5 shrink-0 text-white/55 transition-transform group-open:rotate-180" aria-hidden="true" />
            </summary>
            <div className="mt-3 border-t border-amber-400/15 pt-3"><Problemas problemas={problemas} /></div>
        </details>
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

/** Rótulo de campo fora dos tiles (11px caixa-alta, o padrão da mesa). */
export const ROTULO = 'mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';
