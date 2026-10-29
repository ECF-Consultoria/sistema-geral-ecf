import Explicacao from '@/Components/Explicacao';
import { estoqueDoProduto } from '@/lib/estoqueDoProduto';
import { cn } from '@/lib/utils';

// ─── "Estoque do produto": a soma das variações (09/10/2026) ────────────────
//
// Com várias variações mostra a soma delas, só leitura; com UMA, nada (ver N19
// abaixo — o estoque fica só na variação). A conta é de `lib/estoqueDoProduto.js`.
// Texto neutro: estoque é do produto do cliente.
//
// 29/10/2026: saiu dos dados gerais, onde tinha cara de campo e parecia editável
// (revisão do Gemini), e virou uma linha de texto no pé das variações.

// Demo 23/10 (N19): com UMA variação o campo daqui era o MESMO dado do "Estoque" do cartão da
// variação — o cliente via dois campos de estoque no produto novo e digitava num achando que era
// outro. O estoque mora na variação (`estrutura_produto_variacoes.estoque`; o servidor não tem estoque
// de produto), então com uma variação este bloco some. Com várias, segue a soma só leitura.
export default function EstoqueDoProduto({ ficha, className }) {
    const estoque = estoqueDoProduto(ficha.vars);
    const explicacao = ficha.explicacoes?.estoque_produto;

    if (estoque.total <= 1) return null;

    return (
        <p className={cn('flex flex-wrap items-center gap-1.5 text-[12.5px] text-white/50', className)} data-estoque-produto="soma">
            <span id="ficha-estoque-rotulo">Estoque do produto</span>
            <Explicacao texto={explicacao} nome="Estoque do produto" />
            <output aria-labelledby="ficha-estoque-rotulo" aria-describedby="ficha-estoque-ajuda"
                className={cn('font-semibold tabular-nums', estoque.valor ? 'text-white/85' : 'text-white/35')}>
                {estoque.valor || '—'}
            </output>
            <span id="ficha-estoque-ajuda">soma das variações{estoque.parcial ? ` · ${estoque.parcial}` : ''}</span>
        </p>
    );
}
