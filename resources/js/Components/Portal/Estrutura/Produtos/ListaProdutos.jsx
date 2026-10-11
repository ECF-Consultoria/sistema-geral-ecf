import { useMemo } from 'react';
import CartaoProdutoGrande from '@/Components/Portal/Estrutura/Produtos/CartaoProdutoGrande';
import CartaoProdutoLinha from '@/Components/Portal/Estrutura/Produtos/CartaoProdutoLinha';

// ─── Produtos: a lista nos dois desenhos (167-16, 167-18/D-23, 167-20/D-26) ──
//
// Os MESMOS produtos em dois desenhos: "Visual grande" (cartões em 3 colunas a
// partir de 1440 px, 2 a partir de 768 px, 1 no celular, mesma altura por linha)
// e "Lista" (um cartão horizontal por produto, não é tabela). Clicar abre a
// ficha, que é o único lugar de editar. Só se mostra o que o servidor devolveu;
// nenhuma conta mora aqui. Linhas ainda não gravadas ficam fora da lista.
//
// 10/10/2026: cada cartão pode ser marcado (`selecionados`, um Set de ids de produto) para a
// exclusão em lote, e o menu ⋮ exclui um só (`onExcluir`). Quem guarda a seleção é a página.

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

export default function ListaProdutos({ linhas, vocabulario, consultando, modo = 'grande', onAbrir, voltouDe = null, destaqueForte = false,
    selecionados = null, onSelecionar = null, onExcluir = null }) {
    const produtos = useMemo(() => agruparPorProduto(linhas), [linhas]);

    if (produtos.length === 0) return null;

    const Cartao = modo === 'lista' ? CartaoProdutoLinha : CartaoProdutoGrande;

    return (
        <div data-lista-produtos data-modo={modo}
            className={modo === 'lista' ? 'space-y-3' : 'grid grid-cols-1 gap-x-4 gap-y-6 md:grid-cols-2 min-[1440px]:grid-cols-3'}>
            {produtos.map(({ produtoId, variacoes }) => (
                <Cartao key={produtoId} produtoId={produtoId} variacoes={variacoes} vocabulario={vocabulario} consultando={consultando} onAbrir={onAbrir}
                    destaque={produtoId === voltouDe ? (destaqueForte ? 'forte' : 'leve') : null}
                    selecionado={selecionados?.has?.(produtoId) ?? false} onSelecionar={onSelecionar} onExcluir={onExcluir} />
            ))}
        </div>
    );
}
