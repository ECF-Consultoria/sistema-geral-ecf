import { ChevronRight } from 'lucide-react';
import { BotaoAcao } from './botoes';
import { ChipSecao, PendenciasDaSecao } from './comum';
import { cn } from '@/lib/utils';

// ─── Coluna central: a moldura do item selecionado (Conceito E, 03/10/2026) ──
//
// Trilha ("Variações › Variação 2 de 2"), título grande com a pílula de
// pendência, as ações do item à direita (ex.: Desativar / Excluir variação),
// as pendências que o servidor aponta para ele, o formulário e, no fim,
// "Próximo item" (secundário — o amarelo é do Inspetor). O título recebe o
// foco quando a pessoa troca de item (tabIndex -1; a página chama `focus()`).

/** Pílula do título: "Falta 1" / "Faltam N" em âmbar, ou o chip "Completo". */
function Pilula({ faltam }) {
    if (faltam === null || faltam === undefined) return null;
    if (faltam === 0) return <ChipSecao faltam={0} />;

    return (
        <span className="inline-flex items-center rounded-full border border-amber-400/30 bg-amber-400/15 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-amber-300" data-pilula-pendencia={faltam}>
            {faltam === 1 ? 'Falta 1' : `Faltam ${faltam}`}
        </span>
    );
}

/**
 * `trilha` = os níveis acima do item (ex.: ['Variações']); `rotuloDaTrilha` = o nome do item na
 * trilha quando difere do título (ex.: "Variação 2 de 2"); `acoes` = botões à direita;
 * `problemas` = pendências do item; `proximo` = `{ chave, titulo }` ou nulo; `onProximo(chave)`.
 */
export default function ItemDoCentro({ chave, trilha = [], rotuloDaTrilha = null, titulo, apoio = null, faltam = null, acoes = null, problemas = [], proximo = null, onProximo, children }) {
    const id = 'item-centro';

    return (
        // scroll-mt: ao trocar de item a página rola até aqui; a barra (56) fica fixa por cima.
        <section id={id} aria-labelledby={`${id}-titulo`} data-item-centro={chave} className="scroll-mt-16 rounded-xl border border-white/[0.08] bg-ecf-card p-6 max-sm:p-4">
            <header className="flex flex-col gap-3 border-b border-white/[0.06] pb-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                <div className="min-w-0 sm:flex-1">
                    <p className="flex flex-wrap items-center gap-1.5 text-[11px] text-white/55" data-trilha>
                        {trilha.map((t) => <span key={t} className="flex items-center gap-1.5">{t} <ChevronRight size={11} aria-hidden="true" /></span>)}
                        <span className="text-white/80">{rotuloDaTrilha ?? titulo}</span>
                    </p>
                    <h2 id={`${id}-titulo`} tabIndex={-1} className="mt-1 flex flex-wrap items-center gap-3 font-display text-[24px] font-bold leading-tight text-white outline-none">
                        <span>{titulo}</span>
                        <Pilula faltam={faltam} />
                    </h2>
                    {apoio && <p className="mt-1 max-w-[72ch] text-[13px] font-normal text-white/55">{apoio}</p>}
                </div>
                {acoes && <div className="flex flex-wrap items-center gap-2 sm:shrink-0" data-acoes-do-item>{acoes}</div>}
            </header>

            {problemas.length > 0 && (
                <div className="mt-4 rounded-[10px] border border-amber-400/20 bg-amber-400/[0.05] p-3" data-pendencias-item={problemas.length}>
                    <PendenciasDaSecao problemas={problemas} />
                </div>
            )}

            <div className="mt-6">{children}</div>

            <footer className={cn('mt-8 flex flex-wrap items-center gap-3 border-t border-white/[0.06] pt-4', proximo ? 'justify-end' : 'justify-start')} data-rodape-item>
                {proximo
                    ? (
                        <BotaoAcao onClick={() => onProximo(proximo.chave)} data-acao="proximo-item" title={`Abrir ${proximo.titulo}`}>
                            Próximo item: {proximo.titulo} <ChevronRight size={16} aria-hidden="true" />
                        </BotaoAcao>
                    )
                    : <span className="text-[13px] text-white/45">Último item do anúncio. Confira e publique no Inspetor.</span>}
            </footer>
        </section>
    );
}
