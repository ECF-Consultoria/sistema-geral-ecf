import { AlertTriangle, Check, CheckCircle2, ChevronDown } from 'lucide-react';
import Problemas from '../Problemas';
import { cn } from '@/lib/utils';

// Até este tanto de pendências a lista aparece inteira; acima, só a primeira e "e mais N" (abre no clique).
const PENDENCIAS_A_VISTA = 3;

// ─── Base dos painéis da mesa de anúncio (UI-SPEC §8.4, passo a passo de 03/10/2026) ──
//
// Cada card da mesa é o conteúdo de UMA etapa do trilho e se apresenta num
// painel: título, apoio, chip de estado, as pendências que o servidor aponta
// para a etapa e, no fim, o rodapé de navegação que a página injeta.
// Cards de apresentação pura: recebem o contrato `m` e nunca chamam rota.
// Estado calmo: vazio obrigatório = borda âmbar; vermelho só para valor que o
// servidor recusou. Nada pisca e nada recolhe.

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

/**
 * As pendências que o servidor aponta para a etapa. Poucas: a lista inteira. Muitas (a
 * ficha com 10 campos vazios): a primeira mensagem e "e mais N" — os campos já mostram
 * o próprio estado em âmbar, a lista é o complemento, não o alarme. Abre no clique.
 */
function PendenciasDaEtapa({ problemas }) {
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
 * Painel de uma etapa: section + cabeçalho (título 24px, apoio, chip) + pendências
 * da etapa + conteúdo + rodapé. `problemas` são os que o servidor aponta para as
 * seções desta etapa (a lista só aparece se houver algum). O título recebe o foco
 * quando a pessoa troca de etapa (tabIndex -1; a página chama `focus()`).
 */
export function PainelDaEtapa({ id, titulo, apoio, chip, problemas = [], rodape = null, children }) {
    return (
        // scroll-mt: ao trocar de etapa a página rola até aqui; a barra (56) e o trilho (87) ficam fixos por cima em ≥ sm, só a barra (2 linhas) abaixo.
        <section id={id} aria-labelledby={`${id}-titulo`} className="scroll-mt-[96px] rounded-xl border border-white/[0.08] bg-ecf-card p-6 max-sm:p-4 sm:scroll-mt-[152px]" data-card={id}>
            {/* Em tela estreita o chip desce para baixo do título; senão ele espremeria o título numa coluna. */}
            <header className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between sm:gap-x-6">
                <div className="min-w-0 sm:flex-1">
                    <h2 id={`${id}-titulo`} tabIndex={-1} className="font-display text-[24px] font-bold leading-tight text-white outline-none">{titulo}</h2>
                    {apoio && <p className="mt-1 max-w-[72ch] text-[13px] font-normal text-white/55">{apoio}</p>}
                </div>
                {chip && <div className="shrink-0 sm:pt-1.5">{chip}</div>}
            </header>

            {problemas.length > 0 && (
                <div className="mt-5 rounded-[10px] border border-amber-400/20 bg-amber-400/[0.05] p-3" data-pendencias-etapa={problemas.length}>
                    <PendenciasDaEtapa problemas={problemas} />
                </div>
            )}

            <div className="mt-6">{children}</div>
            {rodape}
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
