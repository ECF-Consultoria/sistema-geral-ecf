import { Plus } from 'lucide-react';
import CartaoVariacao from '@/Components/Portal/Estrutura/Produtos/CartaoVariacao';
import GaleriaVariacao from '@/Components/Portal/Estrutura/Produtos/GaleriaVariacao';
import { contagemDoEixo, gruposPorCor, rotuloDoNovo } from '@/lib/produtosEstrutura';

// ─── Cor E tamanho (28/10/2026): as variações agrupadas pela cor ─────────────
//
// O usuário pediu mais de um eixo por variação ("verdpm: Cor Verde, Tamanho 34-39", não "Outro:
// Verde · 34-39"). Com o 2º eixo, cada cor é um bloco: o nome da cor, as FOTOS da cor (uma
// galeria só, que mora na 1ª variação dela) e, dentro, um cartão por tamanho com Ref,
// estoque, custo e caixa. Sem o 2º eixo a ficha continua com um cartão por variação.

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 py-0 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-8';
const ROTULO = 'block text-[13px] font-medium text-white/80';
const BOTAO_TRACEJADO = 'flex h-11 w-full items-center justify-center gap-2 rounded-[10px] border border-dashed border-white/[0.14] text-[14px] text-white/80 hover:bg-white/[0.03] lg:h-8';

const minusculo = (t) => String(t ?? '').toLowerCase();

/**
 * Os eixos do produto. Com um eixo só: "Também varia por" liga o 2º (as variações passam a ser
 * agrupadas pela cor). Com os dois: "Varia por [Cor] e por [Tamanho]", e "—" no 2º o tira.
 */
export function EixosDoProduto({ ficha }) {
    const primeiro = ficha.primeira.eixo_rotulo ?? '';
    const segundo = ficha.segundoEixo;
    const opcoesDoSegundo = ficha.eixos.filter((r) => r !== primeiro);

    if (! segundo) {
        return (
            <div className="mt-2.5 flex flex-wrap items-center gap-2 text-[13px] text-white/70" data-segundo-eixo>
                <label htmlFor="segundo-eixo">Também varia por</label>
                <select id="segundo-eixo" className={`${CAMPO} w-auto lg:w-[180px]`} value="" onChange={(e) => ficha.definirSegundoEixo(e.target.value)}>
                    <option value="">— (só um eixo)</option>
                    {opcoesDoSegundo.map((r) => <option key={r} value={r}>{r}</option>)}
                </select>
                <span className="text-white/45">ex.: cor e tamanho</span>
            </div>
        );
    }

    return (
        <div className="mt-3 flex flex-wrap items-center gap-2 text-[14px] text-white/80" data-eixos-do-produto>
            <label htmlFor="eixo-1">Varia por</label>
            <select id="eixo-1" className={`${CAMPO} w-auto lg:w-[160px]`} value={primeiro} onChange={(e) => ficha.definirEixo('eixo_rotulo', e.target.value)}>
                <option value="">—</option>
                {ficha.eixos.filter((r) => r !== segundo).map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
            <label htmlFor="eixo-2">e por</label>
            <select id="eixo-2" className={`${CAMPO} w-auto lg:w-[160px]`} value={segundo} onChange={(e) => ficha.definirSegundoEixo(e.target.value)}>
                <option value="">— (tirar)</option>
                {opcoesDoSegundo.map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
        </div>
    );
}

/** Um bloco por cor, com as fotos da cor e os tamanhos dentro. */
export default function VariacoesPorCor({ ficha, vocabulario, onExcluir, onNovaNaCor, onNovaCor }) {
    const grupos = gruposPorCor(ficha.vars);
    const rotulo1 = ficha.primeira.eixo_rotulo || 'Cor';
    const rotulo2 = ficha.segundoEixo;

    return (
        <div className="mt-4 space-y-3" data-variacoes-por-cor>
            {grupos.map((g) => {
                const chaves = g.vars.map((v) => v._k);
                const ultima = g.vars[g.vars.length - 1];

                return (
                    <section key={g.chave} className="rounded-[12px] border border-white/[0.1] bg-white/[0.015] p-3 lg:p-4" data-grupo-cor={g.valor}>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="w-full sm:w-[280px]">
                                <label htmlFor={`cor-${g.chave}`} className={ROTULO}>{rotulo1}</label>
                                <input id={`cor-${g.chave}`} className={CAMPO} value={g.valor} placeholder={minusculo(rotulo1) === 'cor' ? 'ex.: Verde' : ''}
                                    onChange={(e) => ficha.alterarVarias(chaves, 'valor', e.target.value)} data-campo-cor />
                            </div>
                            <p className="pb-1 text-[13px] text-white/55">{contagemDoEixo(g.vars.length, rotulo2)}</p>
                        </div>

                        <GaleriaVariacao variacao={g.dona} titulo={`Fotos da ${minusculo(rotulo1)}`}
                            aoMudar={(imagens) => ficha.definirImagens(g.dona._k, imagens)} />

                        <div className="mt-3 space-y-2">
                            {g.vars.map((v) => (
                                <CartaoVariacao key={v._k} variacao={v} ficha={ficha} vocabulario={vocabulario} dentroDaCor rotuloDoSegundo={rotulo2}
                                    fotosDaCor={g.dona.imagens} podeExcluir={!! v.id || ficha.vars.length > 1} onExcluir={onExcluir} />
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
