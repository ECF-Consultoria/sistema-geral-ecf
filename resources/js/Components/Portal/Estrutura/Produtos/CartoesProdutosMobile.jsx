import { useMemo, useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ESTILO_LOGISTICA, renderFrete } from '@/lib/produtosEstrutura';

// ─── Produtos no celular: lista de cartões (167-16) ─────────────────────────
//
// Abaixo de 768 px a grade em células não serve com o dedo. Cada produto vira um
// cartão (nome, "Família · Ambiente(s)", categoria) com as variações embaixo
// (Ref · Valor · logística · frete). Tocar abre o formulário de baixo. Só se
// mostra o que o servidor devolveu; nenhuma conta mora aqui.
// Linhas ainda não gravadas ficam fora: no celular só se grava pelo formulário.

const AVISO = 'Para cadastrar muitos produtos de uma vez, use o computador ou a planilha.';

/** Agrupa as linhas gravadas por produto, na ordem em que chegaram. */
export function agruparPorProduto(linhas) {
    const grupos = new Map();
    (linhas ?? []).forEach((l) => {
        if (! l.id || ! l.produto_id) return;
        if (! grupos.has(l.produto_id)) grupos.set(l.produto_id, []);
        grupos.get(l.produto_id).push(l);
    });

    return [...grupos.entries()].map(([produtoId, variacoes]) => ({ produtoId, variacoes }));
}

export default function CartoesProdutosMobile({ linhas, vocabulario, onAbrir }) {
    const [avisoVisivel, setAvisoVisivel] = useState(true);
    const produtos = useMemo(() => agruparPorProduto(linhas), [linhas]);
    const logisticas = vocabulario?.logisticas ?? {};

    return (
        <div className="space-y-3" data-cartoes-produtos>
            {avisoVisivel && (
                <div role="status" className="flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/60" data-aviso-mobile>
                    <span>{AVISO}</span>
                    <button type="button" onClick={() => setAvisoVisivel(false)} aria-label="Dispensar aviso"
                        className="grid h-6 w-6 shrink-0 place-items-center text-white/35 hover:text-white">
                        <X size={13} />
                    </button>
                </div>
            )}

            {produtos.map(({ produtoId, variacoes }) => {
                const primeira = variacoes[0];
                const apoio = [primeira.familia, primeira.ambientes_texto].filter(Boolean).join(' · ');

                return (
                    <article key={produtoId} className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4" data-cartao-produto>
                        <button type="button" onClick={() => onAbrir(produtoId)}
                            className="block min-h-[44px] w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                            <span className="block break-words text-[15px] font-semibold text-white">{primeira.nome}</span>
                            <span className="mt-0.5 block text-[12px] text-white/60">{apoio || 'Sem família nem ambiente'}</span>
                            <span className="mt-0.5 block text-[12px] text-white/45">{primeira.categoria || 'Sem categoria'}</span>
                        </button>

                        <ul className="mt-3 divide-y divide-white/[0.06] border-t border-white/[0.06]">
                            {variacoes.map((v) => {
                                const chave = v.logistica ?? 'pendente';

                                return (
                                    <li key={v.id}>
                                        <button type="button" onClick={() => onAbrir(produtoId)} data-variacao-cartao
                                            className="flex min-h-[44px] w-full flex-wrap items-center gap-x-2 gap-y-1 py-2 text-left text-[13px] text-white/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                                            <span className="font-mono">{v.codigo}</span>
                                            {v.valor ? <span className="text-white/60">· {v.valor}</span> : null}
                                            <span className={cn('whitespace-nowrap rounded-full px-2 py-1 text-[12px]', ESTILO_LOGISTICA[chave] ?? ESTILO_LOGISTICA.pendente)}>
                                                {logisticas[chave] ?? chave}
                                            </span>
                                            <span className="min-w-0 text-white/60">{renderFrete(v)}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </article>
                );
            })}
        </div>
    );
}
