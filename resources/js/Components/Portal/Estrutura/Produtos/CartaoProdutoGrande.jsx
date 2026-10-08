import { Tag } from 'lucide-react';
import { CaminhoCategoria, EtiquetaUltimoAberto, LinhaVariacao, MenuDoProduto, PilulaFalta, QuadroFotoProduto, aoClicarNoCartao, classeDestaque } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { capaDoProduto } from '@/lib/imagensVariacao';
import { cn } from '@/lib/utils';

// ─── Cartão do produto — Visual grande (REF-1, 167-20) ──────────────────────
//
// Foto (quadro com iniciais, D-29), nome, "Família · Ambientes", categoria com a
// pílula "Falta" e TODAS as variações embaixo (D-28). Clicar abre a ficha pela
// URL; o nome é um link de verdade (Ctrl+clique abre em outra aba). Só exibe o
// que o servidor mandou.

export default function CartaoProdutoGrande({ produtoId, variacoes, vocabulario, consultando, onAbrir, destaque = null }) {
    const primeira = variacoes[0];
    const apoio = [primeira.familia, primeira.ambientes_texto].filter(Boolean).join(' · ');

    return (
        <article data-cartao-produto data-produto-id={produtoId} data-destaque={destaque ?? undefined} onClick={(e) => aoClicarNoCartao(e, () => onAbrir(produtoId))}
            className={cn('relative flex cursor-pointer flex-col rounded-[14px] border border-white/[0.08] bg-ecf-card px-5 pb-2 pt-5 transition-[border-color,box-shadow] duration-700 hover:border-white/[0.16]',
                classeDestaque(destaque))}>
            <EtiquetaUltimoAberto destaque={destaque} />
            <div className="flex gap-5">
                <QuadroFotoProduto nome={primeira.nome} foto={capaDoProduto(variacoes)} tamanho="cartao" />
                <div className="min-w-0 flex-1 pr-8">
                    <a href={route('portal.auth.estrutura.produtos.ficha', produtoId)}
                        className="block truncate text-[20px] font-semibold leading-7 text-white hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                        {primeira.nome}
                    </a>
                    <p className={apoio ? 'mt-2 truncate text-[16px] text-white/70' : 'mt-2 truncate text-[16px] text-white/40'}>{apoio || 'Sem família nem ambiente'}</p>
                    <div className="mt-5 flex flex-wrap items-center gap-x-2 gap-y-2">
                        <div className="flex min-w-0 max-w-full items-center gap-2">
                            <Tag size={16} className="shrink-0 text-white/50" aria-hidden="true" />
                            <CaminhoCategoria linha={primeira} curto className="min-w-0 text-[13px] text-white/70" />
                        </div>
                        <PilulaFalta variacoes={variacoes} rotulos={vocabulario?.pendencias} nome={primeira.nome} />
                    </div>
                </div>
            </div>
            <MenuDoProduto produtoId={produtoId} nome={primeira.nome} variacoes={variacoes} onAbrir={onAbrir} className="absolute right-3 top-4" />

            <ul className="mt-6 divide-y divide-white/[0.06] border-t border-white/[0.08]">
                {variacoes.map((v) => (
                    <LinhaVariacao key={v.id} variacao={v} vocabulario={vocabulario} consultando={consultando} modo="grande" />
                ))}
            </ul>
        </article>
    );
}
