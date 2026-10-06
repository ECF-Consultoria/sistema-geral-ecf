import { Trash2 } from 'lucide-react';
import CartaoVolume from '@/Components/Portal/Estrutura/Produtos/CartaoVolume';
import FaixaCalculados from '@/Components/Portal/Estrutura/Produtos/FaixaCalculados';
import { Obrigatorio, PilulaLogistica, QuadroFotoProduto } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { cn } from '@/lib/utils';

// ─── Um bloco por variação (REF-2, 167-19) ──────────────────────────────────
//
// Miniatura, Ref + selo de logística, Ref · Eixo · Valor · Custo em linha,
// "Excluir variação" à direita, os volumes em cartões (2 por linha) e a faixa de
// calculados (só leitura). Só a Ref e o nome do produto são obrigatórios; o
// Eixo é lista fechada e o Valor é texto livre (D-20).

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 py-0 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-8';
const ROTULO = 'mb-1 block text-[13px] font-medium text-white/80';

export default function CartaoVariacao({ variacao, ficha, vocabulario, podeExcluir, onExcluir }) {
    const k = variacao._k;
    const gravada = !! variacao.id;
    const caixas = ficha.caixasEdit(variacao);
    const oferta = variacao.oferta;

    return (
        <section id={`variacao-${k}`} className="rounded-[10px] border border-white/[0.06] bg-white/[0.02] p-4 lg:px-4 lg:pb-3 lg:pt-3" data-variacao-form>
            <div className="lg:flex lg:items-start lg:gap-5">
                <div className="flex items-center gap-4 lg:w-[410px]">
                    <QuadroFotoProduto nome={variacao.nome} tamanho="mini" />
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="truncate text-[17px] font-semibold text-white">{variacao.codigo || 'Nova variação'}</span>
                            {gravada && <PilulaLogistica chave={variacao.logistica ?? 'pendente'} rotulos={vocabulario?.logisticas} />}
                        </div>
                        {oferta && (
                            <p className="mt-1 text-[12px] text-white/55">
                                Oferta {oferta.sku}
                                {oferta.anuncios > 0 ? ` · ${oferta.anuncios} ${oferta.anuncios === 1 ? 'anúncio' : 'anúncios'}` : ''}
                                {' · '}
                                <a href={route('portal.auth.estrutura.lista', { q: oferta.sku })} className="text-white/70 underline-offset-2 hover:text-white hover:underline">Ver na Lista SKUs</a>
                            </p>
                        )}
                    </div>
                </div>

                <div className="mt-3 grid grid-cols-2 gap-3 lg:mt-0 lg:max-w-[775px] lg:flex-1 lg:grid-cols-[203fr_168fr_185fr_158fr] lg:gap-5">
                    <div>
                        <label className={ROTULO} htmlFor={`ref-${k}`}>Ref <Obrigatorio /></label>
                        <input id={`ref-${k}`} className={CAMPO} value={variacao.codigo} onChange={(e) => ficha.alterar(k, 'codigo', e.target.value)} placeholder="código" />
                    </div>
                    <div>
                        <label className={ROTULO} htmlFor={`eixo-${k}`}>Eixo</label>
                        <select id={`eixo-${k}`} className={CAMPO} value={variacao.eixo_rotulo ?? ''} onChange={(e) => ficha.alterar(k, 'eixo_rotulo', e.target.value)}>
                            <option value="">—</option>
                            {ficha.eixos.map((r) => <option key={r} value={r}>{r}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={ROTULO} htmlFor={`valor-${k}`}>Valor</label>
                        <input id={`valor-${k}`} className={CAMPO} value={variacao.valor ?? ''} onChange={(e) => ficha.alterar(k, 'valor', e.target.value)} placeholder="ex.: Natural" />
                    </div>
                    <div>
                        <label className={ROTULO} htmlFor={`custo-${k}`}>Custo (R$)</label>
                        <input id={`custo-${k}`} className={cn(CAMPO, 'tabular-nums')} inputMode="decimal" value={variacao.custo ?? ''} onChange={(e) => ficha.alterar(k, 'custo', e.target.value)} placeholder="0,00" />
                    </div>
                </div>

                {podeExcluir && (
                    <button type="button" onClick={() => onExcluir(variacao)} data-acao="excluir-variacao"
                        className="mt-3 inline-flex min-h-[44px] items-center gap-2 text-[14px] font-medium text-red-300 hover:text-red-200 lg:ml-auto lg:mt-5 lg:min-h-0">
                        <Trash2 size={16} /> Excluir variação
                    </button>
                )}
            </div>

            <div className="mt-3 lg:mt-1 lg:grid lg:grid-cols-[84px_minmax(0,1fr)]">
                <span className="mb-2 block text-[15px] font-semibold text-white lg:mb-0 lg:pt-2.5">Volumes</span>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {caixas.map((caixa, i) => (
                        <CartaoVolume key={i} variacao={variacao} indice={i} caixa={caixa} ficha={ficha} />
                    ))}
                </div>
            </div>

            <div className="mt-3 lg:mt-2">
                <FaixaCalculados variacao={variacao} ficha={ficha} vocabulario={vocabulario} />
            </div>

            {ficha.erros[k] && <p role="alert" className="mt-3 text-[12px] text-red-300" data-erro-variacao>{ficha.erros[k]}</p>}
        </section>
    );
}
