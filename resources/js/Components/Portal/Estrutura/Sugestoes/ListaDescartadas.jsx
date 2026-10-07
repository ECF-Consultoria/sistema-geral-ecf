import { Loader2, Undo2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { composicaoEmLinha } from '@/lib/sugestoesEstrutura';
import { CaixaDeSelecao, SeloFase } from './PecasDaSugestao';

// ─── Aba "Descartadas" (UI-SPEC "Aba Descartadas", D-01) ────────────────────
//
// Linhas compactas num container (168-19, D-24): sem campos editáveis e sem logística. Restaurar devolve a sugestão à
// aba Sugestões só por decisão da pessoa; o que já virou oferta não volta (o servidor avisa).
// A marcação é desta aba (a página guarda uma seleção própria). Nada é calculado aqui.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';

/** '2026-10-07' ou ISO completo -> '07/10'. Sem data legível: null. */
export function diaMes(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));

    return m ? `${m[3]}/${m[2]}` : null;
}

function LinhaDescartada({ item, marcada, ocupada, bloqueado, onMarcar, onRestaurar }) {
    const quando = diaMes(item.descartada_em);
    const composicao = composicaoEmLinha(item.itens);
    const familia = item.familia?.nome;

    return (
        <article data-sugestao-descartada data-chave={item.chave}
            className={cn('flex min-w-0 flex-col gap-2 rounded-[10px] border bg-ecf-card p-3 xl:grid xl:grid-cols-[28px_96px_minmax(0,1fr)_150px_auto] xl:items-center xl:gap-4 xl:py-2.5', marcada ? 'border-ecf-yellow/40' : 'border-white/[0.07]')}>
            <label className="grid h-11 w-11 shrink-0 place-items-center xl:h-7 xl:w-7">
                <CaixaDeSelecao checked={marcada} disabled={bloqueado} onChange={() => onMarcar(item.chave)}
                    aria-label={`Marcar sugestão descartada ${item.nome}`} />
            </label>
            <div className="flex items-center gap-2">
                <SeloFase fase={item.fase} />
            </div>
            <div className="min-w-0">
                <p className="truncate text-[14px] text-white" title={composicao}>{composicao}</p>
                {familia && <p className="truncate text-[12px] text-white/50">{familia}</p>}
            </div>
            <p className="text-[12px] text-white/50">{quando ? `Descartada em ${quando}` : ''}</p>
            <button type="button" onClick={() => onRestaurar(item.chave)} disabled={ocupada || bloqueado} data-acao="restaurar"
                aria-label={`Restaurar sugestão ${item.nome}`}
                className={cn('inline-flex h-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-medium text-white/85 transition-colors hover:bg-white/[0.07] hover:text-white disabled:pointer-events-none disabled:opacity-40 xl:h-9', FOCO)}>
                {ocupada ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Undo2 size={14} aria-hidden="true" />}
                Restaurar
            </button>
        </article>
    );
}

export default function ListaDescartadas({ itens = [], marcadas = [], restaurando = new Set(), bloqueado = false, visitando = false, onMarcar, onRestaurar }) {
    return (
        <section className="mt-4" data-lista-descartadas>
            <p className="max-w-[900px] text-[14px] leading-relaxed text-white/70">
                Estas sugestões saíram da lista e não voltam sozinhas. Restaure as que quiser rever.
            </p>

            {itens.length === 0 ? (
                <div className="mt-6 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-estado-vazio>
                    <h2 className="text-[17px] font-semibold text-white">Nenhuma sugestão descartada</h2>
                </div>
            ) : (
                <div className={cn('mt-4 space-y-1.5 rounded-[12px] border border-white/[0.08] bg-ecf-card/60 p-1.5', visitando && 'opacity-60')} aria-busy={visitando}>
                    {itens.map((item) => (
                        <LinhaDescartada key={item.chave} item={item} marcada={marcadas.includes(item.chave)}
                            ocupada={restaurando.has(item.chave)} bloqueado={bloqueado} onMarcar={onMarcar} onRestaurar={onRestaurar} />
                    ))}
                </div>
            )}
        </section>
    );
}
