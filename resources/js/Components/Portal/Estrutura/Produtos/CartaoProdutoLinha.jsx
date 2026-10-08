import { Tag } from 'lucide-react';
import { CaminhoCategoria, EtiquetaUltimoAberto, LinhaVariacao, MenuDoProduto, PilulaFalta, QuadroFotoProduto, aoClicarNoCartao, classeDestaque } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { capaDoProduto } from '@/lib/imagensVariacao';
import { cn } from '@/lib/utils';

// ─── Cartão do produto — Lista (REF-3, 167-20) ──────────────────────────────
//
// O mesmo conteúdo do Visual grande em um cartão HORIZONTAL (não é tabela):
// foto e nome à esquerda; categoria e "Falta" no meio; variações empilhadas à
// direita; ⋮ no fim. No celular empilha. Só exibe o que o servidor mandou.

export default function CartaoProdutoLinha({ produtoId, variacoes, vocabulario, consultando, onAbrir, destaque = null }) {
    const primeira = variacoes[0];
    const apoio = [primeira.familia, primeira.ambientes_texto].filter(Boolean).join(' · ');

    return (
        <article data-cartao-produto data-produto-id={produtoId} data-destaque={destaque ?? undefined} onClick={(e) => aoClicarNoCartao(e, () => onAbrir(produtoId))}
            className={cn('relative grid cursor-pointer grid-cols-1 rounded-[12px] border border-white/[0.08] bg-ecf-card transition-[border-color,box-shadow] duration-700 hover:border-white/[0.16] lg:grid-cols-[minmax(0,0.92fr)_minmax(0,1fr)_minmax(0,1.21fr)_56px] lg:items-center',
                classeDestaque(destaque))}>
            <EtiquetaUltimoAberto destaque={destaque} />
            <div className="flex items-center gap-7 py-0.5 pl-4 pr-6">
                <QuadroFotoProduto nome={primeira.nome} foto={capaDoProduto(variacoes)} tamanho="linha" />
                <div className="min-w-0">
                    <a href={route('portal.auth.estrutura.produtos.ficha', produtoId)}
                        className="block truncate text-[18px] font-semibold text-white hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                        {primeira.nome}
                    </a>
                    <p className={apoio ? 'mt-1.5 truncate text-[15px] text-white/70' : 'mt-1.5 truncate text-[15px] text-white/40'}>{apoio || 'Sem família nem ambiente'}</p>
                </div>
            </div>

            <div className="flex items-center gap-3 px-4 py-3 lg:self-stretch lg:border-l lg:border-white/[0.06] lg:px-6">
                <Tag size={18} className="shrink-0 text-white/50" aria-hidden="true" />
                <CaminhoCategoria linha={primeira} curto className="min-w-0 flex-1 truncate text-[14px] text-white/80" />
                <PilulaFalta variacoes={variacoes} rotulos={vocabulario?.pendencias} nome={primeira.nome} />
            </div>

            <div className="px-4 lg:self-stretch lg:border-l lg:border-white/[0.06] lg:px-5 lg:py-0.5">
                <ul className="divide-y divide-white/[0.06]">
                    {variacoes.map((v) => (
                        <LinhaVariacao key={v.id} variacao={v} vocabulario={vocabulario} consultando={consultando} modo="lista" />
                    ))}
                </ul>
            </div>

            <div className="absolute right-2 top-2 lg:static lg:flex lg:justify-center">
                <MenuDoProduto produtoId={produtoId} nome={primeira.nome} variacoes={variacoes} onAbrir={onAbrir} />
            </div>
        </article>
    );
}
