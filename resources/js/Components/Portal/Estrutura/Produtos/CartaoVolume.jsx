import { Trash2 } from 'lucide-react';
import { MEDIDAS } from '@/Components/Portal/Estrutura/Produtos/useFichaProduto';
import { cn } from '@/lib/utils';

// ─── Um volume (caixa) da variação, em cartão (REF-2, 167-19) ───────────────
// Só coleta as medidas; peso total, cubagem e frete são do servidor (D-28).

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-8';

export default function CartaoVolume({ variacao, indice, caixa, ficha }) {
    return (
        <div className="rounded-[10px] border border-white/[0.08] bg-black/20 px-3 py-2.5" data-volume-cartao>
            <div className="mb-1.5 flex items-center justify-between">
                <span className="text-[15px] font-semibold text-white">Volume {indice + 1}</span>
                <button type="button" onClick={() => ficha.removerCaixa(variacao, indice)} aria-label={`Remover volume ${indice + 1}`}
                    className="grid h-8 w-8 place-items-center rounded-lg text-red-300/80 hover:bg-white/[0.06] hover:text-red-200">
                    <Trash2 size={16} />
                </button>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {MEDIDAS.map((m) => (
                    <div key={m.chave}>
                        <label className="mb-1 block text-[12px] text-white/60" htmlFor={`cx-${variacao._k}-${indice}-${m.chave}`}>{m.rotulo}</label>
                        <input id={`cx-${variacao._k}-${indice}-${m.chave}`} className={cn(CAMPO, 'tabular-nums')} inputMode="decimal"
                            value={caixa[m.chave]} onChange={(e) => ficha.mudarCaixa(variacao, indice, m.chave, e.target.value)} />
                    </div>
                ))}
            </div>
        </div>
    );
}
