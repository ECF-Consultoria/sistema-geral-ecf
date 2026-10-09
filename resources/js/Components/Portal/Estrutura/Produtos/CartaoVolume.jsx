import { Trash2 } from 'lucide-react';
import Explicacao from '@/Components/Explicacao';
import { RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { MEDIDAS } from '@/Components/Portal/Estrutura/Produtos/useFichaProduto';
import { cn } from '@/lib/utils';

// ─── Um volume (caixa) da variação, em cartão (REF-2, 167-19) ───────────────
// Só coleta as medidas; peso total, cubagem e frete são do servidor (D-28).
// Cada medida leva o "o que é isto?" (`ficha.explicacoes`, textos do servidor).
//
// "Usar as mesmas medidas do produto fora da caixa" (09/10/2026): só com UM volume
// e com comprimento, largura e altura do produto preenchidos. Marcada, o volume
// recebe as medidas do produto e acompanha o que se muda nelas; comprimento,
// largura e altura ficam travados aqui. O peso continua obrigatório e editável
// (vem o do produto, se houver).

/** Medida → chave da explicação dela. */
const EXPLICACAO_DA_MEDIDA = { c: 'comprimento', l: 'largura', a: 'altura', kg: 'peso' };

/** As medidas que a caixa marcada trava (o peso, não). */
const TRAVADAS = ['c', 'l', 'a'];

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 py-0 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-8';

export default function CartaoVolume({ variacao, indice, caixa, ficha }) {
    const explicacoes = ficha.explicacoes ?? {};
    const mesmas = indice === 0 && ficha.mesmasMedidas ? ficha.mesmasMedidas(variacao) : { disponivel: false, marcada: false };
    const idMesmas = `mesmas-${variacao._k}`;

    return (
        <div className="rounded-[10px] border border-white/[0.08] bg-black/20 px-3 py-2.5 lg:py-2" data-volume-cartao>
            <div className="mb-1.5 flex items-center justify-between lg:mb-0.5">
                <span className="text-[15px] font-semibold text-white">Volume {indice + 1}</span>
                <button type="button" onClick={() => ficha.removerCaixa(variacao, indice)} aria-label={`Remover volume ${indice + 1}`}
                    className="grid h-8 w-8 place-items-center rounded-lg lg:-my-1 text-red-300/80 hover:bg-white/[0.06] hover:text-red-200">
                    <Trash2 size={16} />
                </button>
            </div>
            {mesmas.disponivel && (
                <div className="mb-2 flex items-center gap-1" data-mesmas-medidas={mesmas.marcada ? 'marcada' : 'desmarcada'}>
                    <label htmlFor={idMesmas} className="flex min-h-[44px] cursor-pointer items-center gap-2 text-[13px] text-white/80 lg:min-h-0">
                        <input id={idMesmas} type="checkbox" checked={mesmas.marcada} data-acao="mesmas-medidas"
                            onChange={(e) => ficha.marcarMesmasMedidas(variacao, e.target.checked)}
                            className="h-4 w-4 rounded border-white/30 bg-black/40 text-ecf-yellow focus:ring-0 focus:ring-offset-0" />
                        Usar as mesmas medidas do produto fora da caixa
                    </label>
                    <Explicacao texto={explicacoes.mesmas_medidas} nome="Usar as mesmas medidas do produto fora da caixa" />
                </div>
            )}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {MEDIDAS.map((m) => {
                    const travada = mesmas.marcada && TRAVADAS.includes(m.chave);

                    return (
                        <div key={m.chave}>
                            <RotuloComExplicacao className="block text-[12px] leading-4 text-white/60" htmlFor={`cx-${variacao._k}-${indice}-${m.chave}`}
                                explicacao={explicacoes[EXPLICACAO_DA_MEDIDA[m.chave]]} nome={m.rotulo}>{m.rotulo}</RotuloComExplicacao>
                            <input id={`cx-${variacao._k}-${indice}-${m.chave}`} className={cn(CAMPO, 'tabular-nums', travada && 'cursor-not-allowed text-white/60')} inputMode="decimal"
                                value={caixa[m.chave]} readOnly={travada} aria-readonly={travada || undefined} data-medida-travada={travada ? m.chave : undefined}
                                onChange={(e) => { if (! travada) ficha.mudarCaixa(variacao, indice, m.chave, e.target.value); }} />
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
