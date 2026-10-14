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

// 184-10 (CONTEXT §4.1): com UMA variação, se ela está vinculada ao Bling o campo do
// produto é o MESMO dado travado do cartão da variação — reusa o ramo de só-leitura que já
// existia para a soma, só com outro texto de ajuda. Leitura defensiva (`?.`): o bloco
// `bling` pode faltar numa recarga parcial do Inertia.
function formatarSaldoLidoEm(iso) {
    if (! iso) return null;
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return null;

    return data.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function EstoqueDoProduto({ ficha }) {
    const estoque = estoqueDoProduto(ficha.vars);
    const explicacao = ficha.explicacoes?.estoque_produto;
    const unica = Array.isArray(ficha.vars) && ficha.vars.length === 1 ? ficha.vars[0] : null;
    const travadoPeloBling = unica?.bling?.estoque_travado ?? false;

    if (estoque.editavel && ! travadoPeloBling) {
        return (
            <div className="mt-3 max-w-[240px]" data-estoque-produto="unica">
                <RotuloComExplicacao className={ROTULO} htmlFor="ficha-estoque" explicacao={explicacao} nome="Estoque do produto">Estoque do produto (un.)</RotuloComExplicacao>
                <input id="ficha-estoque" className={cn(CAMPO, 'tabular-nums')} inputMode="numeric" value={estoque.valor}
                    onChange={(e) => ficha.alterar(estoque.chave, 'estoque', e.target.value)} placeholder="0" />
            </div>
        );
    }

    if (travadoPeloBling) {
        const atualizadoEm = formatarSaldoLidoEm(unica?.bling?.saldo_lido_em);

        return (
            <div className="mt-3 max-w-[240px]" data-estoque-produto="bling">
                <RotuloComExplicacao como="span" id="ficha-estoque-rotulo" className={ROTULO} explicacao={explicacao} nome="Estoque do produto">Estoque do produto (un.)</RotuloComExplicacao>
                <output aria-labelledby="ficha-estoque-rotulo" aria-describedby="ficha-estoque-ajuda"
                    className={cn(CAMPO, 'flex items-center border-white/10 bg-white/[0.03] tabular-nums', ! estoque.valor && 'text-white/30')}>
                    {estoque.valor || '—'}
                </output>
                <p id="ficha-estoque-ajuda" className="mt-1 text-[12px] text-white/55">
                    este número vem do Bling da empresa{atualizadoEm ? ` · atualizado em ${atualizadoEm}` : ''}
                </p>
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
