import { Plus } from 'lucide-react';
import CampoFichaTecnica from '@/Components/Portal/Estrutura/Produtos/CampoFichaTecnica';
import CartaoVolume from '@/Components/Portal/Estrutura/Produtos/CartaoVolume';
import Explicacao from '@/Components/Explicacao';
import { ordenarMedidas } from '@/lib/fichaLayout';
import { fmtKg, textoFrete } from '@/lib/produtosEstrutura';
import { cn } from '@/lib/utils';

// ─── Medidas e envio (29/10/2026) ───────────────────────────────────────────
//
// O produto montado e a caixa de envio LADO A LADO, na mesma ordem de medidas (a
// queixa de 21/10, "não deixa claro qual é a medida do produto e qual é a do produto
// embalado", e o Gemini em 29/10: L-A-P × C-L-A induz erro). A caixa daqui é a COMUM:
// vale para todas as variações que a seguem; a variação com caixa própria a edita no
// cartão dela. Os números do frete são do servidor (D-28) e saem em português, sem
// ME1/ME2 nem "peso cubado".

/** Os números de envio de uma variação gravada, como o servidor os calculou. */
export function ResumoDoEnvio({ variacao, mexida = false, className }) {
    if (! variacao?.id) {
        return <p className={cn('text-[12.5px] text-white/50', className)} data-resumo-envio="novo">O peso considerado e o frete aparecem depois de salvar.</p>;
    }
    const real = variacao.peso_total;
    const cubado = variacao.peso_cubado;
    const fora = variacao.logistica === 'me1';
    const semCaixa = ! variacao.logistica || variacao.logistica === 'pendente';
    const frete = textoFrete(variacao.frete);
    const item = (rotulo, valor, apoio = null) => (
        <div className="min-w-0">
            <div className="text-[12px] text-white/45">{rotulo}</div>
            <div className="mt-0.5 font-display text-[16px] font-bold tabular-nums text-white">{valor || '—'}{apoio && <span className="ml-1.5 font-sans text-[12px] font-medium text-white/45">{apoio}</span>}</div>
        </div>
    );

    return (
        <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3', mexida && 'opacity-60', className)} data-resumo-envio={variacao.logistica ?? 'pendente'}>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {item('Peso real', fmtKg(real, 2))}
                {item('Peso pelo tamanho da caixa', fmtKg(cubado, 2), variacao.cubado_cobrado ? 'é o que vale' : null)}
                {fora || semCaixa ? item('Frete estimado', '—') : item('Frete estimado', frete.valor, frete.apoio)}
            </div>
            <p className="mt-2.5 border-t border-white/[0.06] pt-2.5 text-[12.5px] text-white/60">
                {semCaixa && 'Informe a caixa de envio para calcular o peso e o frete.'}
                {fora && 'Caixa grande: passa do tamanho do envio padrão. O frete desta peça é informado na Precificação, e o cálculo usa o maior dos dois pesos.'}
                {! semCaixa && ! fora && 'O frete usa o maior dos dois pesos: o real ou o pelo tamanho da caixa (comprimento × largura × altura ÷ 6.000).'}
                {mexida && <span className="ml-1 text-amber-200/90">Estes números são da caixa salva e recalculam ao salvar.</span>}
            </p>
        </div>
    );
}

export default function MedidasEEnvio({ ficha, heranca }) {
    const tecnica = ficha.tecnica;
    const medidas = ordenarMedidas(tecnica.medidas ?? []);
    const comMedidas = tecnica.temCategoria && medidas.length > 0;
    const caixas = heranca.caixasComuns;
    const referencia = heranca.referenciaDaCaixa;
    const vaiPara = heranca.herdeirosDaCaixa;
    const total = ficha.vars.length;

    return (
        <section id="secao-medidas" className="scroll-mt-4 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5" data-medidas-e-envio>
            <div className="flex items-center gap-1">
                <h2 className="text-[19px] font-bold text-white">Medidas e envio</h2>
                <Explicacao texto={ficha.explicacoes?.medidas_produto} nome="Medidas e envio" />
            </div>
            <p className="mt-0.5 text-[13.5px] text-white/65">O produto sozinho e a caixa que vai para o cliente. São medidas diferentes.</p>

            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                {/* Produto montado: campos da ficha técnica (mesmo estado e mesmo Salvar), em bloco próprio. */}
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.015] p-4" data-medidas-do-produto>
                    <p className="text-[14.5px] font-semibold text-white">Produto montado</p>
                    <p className="mt-0.5 text-[12.5px] text-white/50">Sem caixa nem embalagem. É a medida que o comprador vê.</p>
                    {comMedidas ? (
                        <fieldset disabled={ficha.salvando} className="mt-3 min-w-0 border-0 p-0" data-campos-medidas-do-produto>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                                {medidas.map((campo) => (
                                    <CampoFichaTecnica key={campo.id} campo={campo} atual={tecnica.valores[campo.id]}
                                        erro={tecnica.erros[campo.id]} onMudar={tecnica.mudar} explicacao={campo.explicacao} />
                                ))}
                            </div>
                        </fieldset>
                    ) : (
                        <p className="mt-3 text-[13px] text-white/50" data-medidas-sem-categoria>
                            {tecnica.temCategoria ? (tecnica.carregando ? 'Carregando as medidas da categoria…' : 'Esta categoria não pede medidas do produto.') : 'Escolha a categoria para ver as medidas do produto.'}
                        </p>
                    )}
                </div>

                {/* Caixa de envio comum: mexer aqui muda todas as variações que a seguem. */}
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.015] p-4" data-caixa-comum>
                    <p className="flex items-center gap-1 text-[14.5px] font-semibold text-white">
                        Caixa de envio <Explicacao texto={ficha.explicacoes?.volumes} nome="Caixa de envio" />
                    </p>
                    <p className="mt-0.5 text-[12.5px] text-white/50">Com o produto dentro. É ela que calcula o frete.</p>
                    <fieldset disabled={ficha.salvando} className="mt-3 min-w-0 space-y-2.5 border-0 p-0" data-campos-caixa-comum>
                        {caixas.map((caixa, i) => (
                            <CartaoVolume key={i} variacao={referencia} indice={i} caixa={caixa} ficha={ficha} idBase="cx-comum"
                                podeRemover={caixas.length > 1} onMudar={heranca.mudarCaixaComum} onRemover={heranca.removerCaixaComum} />
                        ))}
                        <button type="button" onClick={heranca.adicionarCaixaComum} data-acao="adicionar-volume"
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-dashed border-white/[0.16] px-3 text-[13px] text-white/75 hover:bg-white/[0.03] hover:text-white">
                            <Plus size={14} /> Adicionar caixa <span className="text-white/40">(produto que vai em mais de um volume)</span>
                        </button>
                    </fieldset>
                    {total > 1 && (
                        <p className="mt-2.5 text-[12.5px] text-white/50" data-caixa-vale-para>
                            Vale para {vaiPara.length} de {total} variações. Uma variação com caixa diferente? Abra ela em{' '}
                            <a href="#secao-cores" onClick={(e) => { e.preventDefault(); document.getElementById('secao-cores')?.scrollIntoView({ behavior: 'smooth' }); }}
                                className="text-white underline decoration-white/30 underline-offset-[3px] hover:decoration-white">{heranca.nomeDasVariacoes}</a>.
                        </p>
                    )}
                </div>
            </div>

            <ResumoDoEnvio variacao={referencia} mexida={!! referencia?.id && Array.isArray(referencia.volumes_digitados)} className="mt-4" />
        </section>
    );
}
