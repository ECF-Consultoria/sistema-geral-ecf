import { Trash2 } from 'lucide-react';
import { RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { MEDIDAS } from '@/Components/Portal/Estrutura/Produtos/useFichaProduto';
import { cn } from '@/lib/utils';

// ─── Um volume (caixa) em cartão (REF-2, 167-19; 29/10/2026) ────────────────
// Só coleta as medidas; peso total, cubagem e frete são do servidor (D-28).
// Cada medida leva o "o que é isto?" (`ficha.explicacoes`, textos do servidor).
//
// 29/10/2026: o mesmo cartão serve à caixa COMUM (Medidas e envio, vale para todas as
// variações que a seguem) e à caixa PRÓPRIA de uma variação. Quem chama diz o que fazer
// ao mudar/remover (`onMudar`, `onRemover`); sem eles, mexe só na variação dada. A unidade
// fica dentro do campo ("cm", "kg"), não no rótulo nem numa segunda caixa.

/** Medida → chave da explicação dela. */
const EXPLICACAO_DA_MEDIDA = { c: 'comprimento', l: 'largura', a: 'altura', kg: 'peso' };
const NOME = { c: 'Comprimento', l: 'Largura', a: 'Altura', kg: 'Peso' };
const UNIDADE = { c: 'cm', l: 'cm', a: 'cm', kg: 'kg' };

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 py-0 pl-3 pr-10 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-9';

export default function CartaoVolume({ variacao, indice, caixa, ficha, onMudar = null, onRemover = null, podeRemover = true, idBase = null }) {
    const explicacoes = ficha.explicacoes ?? {};
    const base = idBase ?? `cx-${variacao._k}`;
    const mudar = onMudar ?? ((i, campo, valor) => ficha.mudarCaixa(variacao, i, campo, valor));
    const remover = onRemover ?? ((i) => ficha.removerCaixa(variacao, i));

    return (
        <div className="rounded-[10px] border border-white/[0.08] bg-black/20 px-3 py-2.5" data-volume-cartao>
            <div className="mb-1.5 flex min-h-8 items-center justify-between">
                <span className="text-[14px] font-semibold text-white">Caixa {indice + 1}</span>
                {podeRemover && (
                    <button type="button" onClick={() => remover(indice)} aria-label={`Remover caixa ${indice + 1}`}
                        className="grid h-8 w-8 place-items-center rounded-lg text-red-300/80 hover:bg-white/[0.06] hover:text-red-200">
                        <Trash2 size={15} />
                    </button>
                )}
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {MEDIDAS.map((m) => (
                    <div key={m.chave} className="min-w-0">
                        <RotuloComExplicacao className="mb-1 block text-[12.5px] leading-4 text-white/65" htmlFor={`${base}-${indice}-${m.chave}`}
                            explicacao={explicacoes[EXPLICACAO_DA_MEDIDA[m.chave]]} nome={NOME[m.chave]}>{NOME[m.chave]}</RotuloComExplicacao>
                        <div className="relative">
                            <input id={`${base}-${indice}-${m.chave}`} className={cn(CAMPO, 'tabular-nums')} inputMode="decimal"
                                value={caixa[m.chave]} onChange={(e) => mudar(indice, m.chave, e.target.value)} />
                            <span aria-hidden="true" className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12.5px] text-white/40">{UNIDADE[m.chave]}</span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
