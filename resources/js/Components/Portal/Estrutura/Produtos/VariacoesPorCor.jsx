import { Plus } from 'lucide-react';
import CartaoVariacao from '@/Components/Portal/Estrutura/Produtos/CartaoVariacao';
import Explicacao from '@/Components/Explicacao';
import GaleriaVariacao from '@/Components/Portal/Estrutura/Produtos/GaleriaVariacao';
import { contagemDoEixo, gruposPorCor, rotuloDoNovo } from '@/lib/produtosEstrutura';

// ─── Cor E tamanho (28/10/2026): as variações agrupadas pela cor ─────────────
//
// O usuário pediu mais de um eixo por variação ("verdpm: Cor Verde, Tamanho 34-39", não "Outro:
// Verde · 34-39"). Com o 2º eixo, cada cor é um bloco: o nome da cor, as FOTOS da cor (uma
// galeria só, que mora na 1ª variação dela) e, dentro, uma LINHA por tamanho (29/10/2026: a mesma
// linha que abre das variações de um eixo só) com Ref, estoque, custo e caixa.

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 py-0 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-9';
const ROTULO = 'mb-1 block text-[12.5px] font-medium text-white/70';
const BOTAO_TRACEJADO = 'flex h-11 w-full items-center justify-center gap-2 rounded-[10px] border border-dashed border-white/[0.14] text-[14px] text-white/75 hover:bg-white/[0.03] hover:text-white lg:h-9';
const SELECT = 'h-9 rounded-lg border border-white/20 bg-black/40 px-2.5 text-[13.5px] text-white focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';

const minusculo = (t) => String(t ?? '').toLowerCase();

/**
 * Os eixos do produto, no cabeçalho da seção: "Varia por [Cor]" e "também por [—]". O 1º eixo vale
 * para o produto inteiro (vai para todas as variações); "—" no 2º o tira (com a pergunta de sempre).
 */
export function EixosDoProduto({ ficha }) {
    const primeiro = ficha.primeira.eixo_rotulo ?? '';
    const segundo = ficha.segundoEixo;
    const opcoesDoSegundo = ficha.eixos.filter((r) => r !== primeiro);

    return (
        <div className="flex flex-wrap items-center gap-2 text-[13px] text-white/65" data-eixos-do-produto data-segundo-eixo={segundo ? undefined : ''}>
            <span className="flex items-center gap-1"><label htmlFor="eixo-1">Varia por</label><Explicacao texto={ficha.explicacoes?.eixo} nome="Varia por" /></span>
            <select id="eixo-1" className={SELECT} value={primeiro} onChange={(e) => ficha.definirEixo('eixo_rotulo', e.target.value)}>
                <option value="">—</option>
                {ficha.eixos.filter((r) => r !== segundo).map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
            <label htmlFor={segundo ? 'eixo-2' : 'segundo-eixo'}>e também por</label>
            <select id={segundo ? 'eixo-2' : 'segundo-eixo'} className={SELECT} value={segundo} onChange={(e) => ficha.definirSegundoEixo(e.target.value)}>
                <option value="">{segundo ? '— (tirar)' : '— (só um eixo)'}</option>
                {opcoesDoSegundo.map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
        </div>
    );
}

/** Um bloco por cor, com as fotos da cor e os tamanhos dentro. */
export default function VariacoesPorCor({ ficha, onExcluir, onNovaNaCor, onNovaCor, abertas, onAlternar, heranca }) {
    const grupos = gruposPorCor(ficha.vars);
    const rotulo1 = ficha.primeira.eixo_rotulo || 'Cor';
    const rotulo2 = ficha.segundoEixo;

    return (
        <div className="space-y-3" data-variacoes-por-cor>
            {grupos.map((g) => {
                const chaves = g.vars.map((v) => v._k);
                const ultima = g.vars[g.vars.length - 1];

                return (
                    <section key={g.chave} className="rounded-xl border border-white/[0.1] bg-white/[0.015] p-3 lg:p-4" data-grupo-cor={g.valor}>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="w-full sm:w-[280px]">
                                <label htmlFor={`cor-${g.chave}`} className={ROTULO}>{rotulo1}</label>
                                <input id={`cor-${g.chave}`} className={CAMPO} value={g.valor} placeholder={minusculo(rotulo1) === 'cor' ? 'ex.: Verde' : ''}
                                    onChange={(e) => ficha.alterarVarias(chaves, 'valor', e.target.value)} data-campo-cor />
                            </div>
                            <p className="pb-2 text-[13px] text-white/50">{contagemDoEixo(g.vars.length, rotulo2)}</p>
                        </div>

                        <GaleriaVariacao variacao={g.dona} titulo={`Fotos da ${minusculo(rotulo1)}`}
                            aoMudar={(imagens) => ficha.definirImagens(g.dona._k, imagens)} />

                        <div className="mt-3 space-y-2">
                            {g.vars.map((v) => (
                                <CartaoVariacao key={v._k} variacao={v} ficha={ficha} dentroDaCor rotuloDoSegundo={rotulo2} rotuloDoEixo={rotulo1}
                                    fotosDaCor={g.dona.imagens} podeExcluir={!! v.id || ficha.vars.length > 1} onExcluir={onExcluir}
                                    aberta={abertas.has(v._k) || !! ficha.erros[v._k]} onAlternar={onAlternar} heranca={heranca} />
                            ))}
                        </div>

                        <button type="button" onClick={() => onNovaNaCor(g.valor, ultima._k)} data-acao="novo-na-cor" className={`mt-2 ${BOTAO_TRACEJADO}`}>
                            <Plus size={14} /> {rotuloDoNovo(rotulo2)}{g.valor ? ` em ${g.valor}` : ''}
                        </button>
                    </section>
                );
            })}

            <button type="button" onClick={onNovaCor} data-acao="nova-cor" className={BOTAO_TRACEJADO}>
                <Plus size={14} /> {rotuloDoNovo(rotulo1)}
            </button>
        </div>
    );
}
