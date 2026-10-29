import { Plus } from 'lucide-react';
import CartaoVariacao from '@/Components/Portal/Estrutura/Produtos/CartaoVariacao';
import EstoqueDoProduto from '@/Components/Portal/Estrutura/Produtos/EstoqueDoProduto';
import VariacoesPorCor, { EixosDoProduto } from '@/Components/Portal/Estrutura/Produtos/VariacoesPorCor';
import { rotuloDoNovo } from '@/lib/produtosEstrutura';

// ─── Seção das variações: "Cores e fotos" (29/10/2026) ──────────────────────
//
// O miolo do cadastro. No topo, o que é IGUAL em todas (custo e caixa, `heranca`):
// digitado uma vez. Embaixo, uma linha por variação (foto, nome, Ref, estoque), que
// abre para o resto. Com dois eixos (cor E tamanho), cada cor é um bloco com as fotos
// dela e os tamanhos dentro. O nome da seção segue o eixo ("Cores e fotos").

export default function CoresDoProduto({ ficha, heranca, abertas, onAlternar, onExcluir, onNovaVariacao, onNovaNaCor, onNovaCor, frase = null }) {
    const varias = ficha.vars.length > 1;
    const rotulo = ficha.primeira.eixo_rotulo || '';

    return (
        <section id="secao-cores" className="scroll-mt-4 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5" data-secao-variacoes>
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3">
                <div className="min-w-0">
                    <h2 className="text-[19px] font-bold text-white">{heranca.nomeDasVariacoes}</h2>
                    <p className="mt-0.5 text-[13.5px] text-white/65">{frase ?? 'Cada variação tem a sua referência, estoque e fotos. O resto vem do produto.'}</p>
                </div>
                <div className="ml-auto"><EixosDoProduto ficha={ficha} /></div>
            </div>

            <fieldset disabled={ficha.salvando} className="mt-4 min-w-0 border-0 p-0" data-campos-variacoes>
                {/* O que vale para todas: o custo digitado uma vez. A caixa comum mora em Medidas e envio. */}
                {varias && (
                    <div className="mb-3 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border border-white/[0.08] bg-[#14161d] px-4 py-3" data-igual-em-todas>
                        <span className="text-[11.5px] font-semibold uppercase tracking-[0.07em] text-white/40">Igual em todas</span>
                        <label className="flex items-center gap-2.5 text-[13px] text-white/70" htmlFor="custo-comum">
                            Custo
                            <span className="relative">
                                <span aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[12.5px] text-white/40">R$</span>
                                <input id="custo-comum" className="h-9 w-[150px] rounded-lg border border-white/20 bg-black/40 pl-9 pr-3 text-[14px] tabular-nums text-white focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                    inputMode="decimal" value={heranca.custoComum} placeholder="0,00" onChange={(e) => heranca.mudarCustoComum(e.target.value)} data-campo="custo-comum" />
                            </span>
                        </label>
                        <span className="text-[13px] text-white/65">
                            Caixa {heranca.caixaComumTexto}{' '}
                            <a href="#secao-medidas" onClick={(e) => { e.preventDefault(); document.getElementById('secao-medidas')?.scrollIntoView({ behavior: 'smooth' }); }}
                                className="text-white underline decoration-white/30 underline-offset-[3px] hover:decoration-white">editar em Medidas e envio</a>
                        </span>
                        <span className="ml-auto text-[12.5px] text-white/45" data-custo-vale-para>
                            custo de {heranca.herdeirosDoCusto.length} de {ficha.vars.length} · caixa de {heranca.herdeirosDaCaixa.length} de {ficha.vars.length}
                        </span>
                    </div>
                )}

                {ficha.segundoEixo ? (
                    <VariacoesPorCor ficha={ficha} onExcluir={onExcluir} onNovaNaCor={onNovaNaCor} onNovaCor={onNovaCor}
                        abertas={abertas} onAlternar={onAlternar} heranca={heranca} />
                ) : (
                    <>
                        <div className="space-y-2">
                            {ficha.vars.map((v) => (
                                <CartaoVariacao key={v._k} variacao={v} ficha={ficha} rotuloDoEixo={rotulo}
                                    podeExcluir={!! v.id || ficha.vars.length > 1} onExcluir={onExcluir}
                                    aberta={abertas.has(v._k) || !! ficha.erros[v._k]} onAlternar={onAlternar} heranca={heranca} />
                            ))}
                        </div>
                        <button type="button" onClick={onNovaVariacao} data-acao="nova-variacao"
                            className="mt-2 flex h-11 w-full items-center justify-center gap-2 rounded-[10px] border border-dashed border-white/[0.14] text-[14px] text-white/75 hover:bg-white/[0.03] hover:text-white lg:h-9">
                            <Plus size={14} /> {rotuloDoNovo(rotulo)} <span className="text-white/40">(já vem com custo e caixa)</span>
                        </button>
                    </>
                )}
            </fieldset>

            <EstoqueDoProduto ficha={ficha} className="mt-3" />
        </section>
    );
}
