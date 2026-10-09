import { RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { estoqueDoProduto } from '@/lib/estoqueDoProduto';
import { cn } from '@/lib/utils';

// ─── "Estoque do produto" nos dados gerais da ficha (09/10/2026) ─────────────
//
// Com UMA variação o campo é o estoque dela (o mesmo dado do cartão da variação);
// com várias, mostra a soma das variações, só leitura. A conta é de
// `lib/estoqueDoProduto.js`. Texto neutro: estoque é do produto do cliente.

const CAMPO = 'h-11 lg:h-9 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO = 'block text-[13px] font-medium text-white/80';

export default function EstoqueDoProduto({ ficha }) {
    const estoque = estoqueDoProduto(ficha.vars);
    const explicacao = ficha.explicacoes?.estoque_produto;

    if (estoque.editavel) {
        return (
            <div className="mt-3 max-w-[240px]" data-estoque-produto="unica">
                <RotuloComExplicacao className={ROTULO} htmlFor="ficha-estoque" explicacao={explicacao} nome="Estoque do produto">Estoque do produto (un.)</RotuloComExplicacao>
                <input id="ficha-estoque" className={cn(CAMPO, 'tabular-nums')} inputMode="numeric" value={estoque.valor}
                    onChange={(e) => ficha.alterar(estoque.chave, 'estoque', e.target.value)} placeholder="0" />
            </div>
        );
    }

    return (
        <div className="mt-3 max-w-[240px]" data-estoque-produto="soma">
            <RotuloComExplicacao como="span" id="ficha-estoque-rotulo" className={ROTULO} explicacao={explicacao} nome="Estoque do produto">Estoque do produto (un.)</RotuloComExplicacao>
            <output aria-labelledby="ficha-estoque-rotulo" aria-describedby="ficha-estoque-ajuda"
                className={cn(CAMPO, 'flex items-center border-white/10 bg-white/[0.03] tabular-nums', ! estoque.valor && 'text-white/30')}>
                {estoque.valor || '—'}
            </output>
            <p id="ficha-estoque-ajuda" className="mt-1 text-[12px] text-white/55">
                soma das variações abaixo{estoque.parcial ? ` · ${estoque.parcial}` : ''}
            </p>
        </div>
    );
}
