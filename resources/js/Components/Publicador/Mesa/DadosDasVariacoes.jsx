import { useState } from 'react';
import { Plus, RotateCcw } from 'lucide-react';
import EditorDeEixos from '../EditorDeEixos';
import { eixosComValores, eixosSemValor } from '../ferramentas';
import CartaoVariante from './CartaoVariante';
import NovaVariacao from './NovaVariacao';
import { BotaoAcao } from './botoes';
import { LINK, Secao } from './comum';

// ─── Variações, PRIMEIRA seção de Detalhes (D1, Fase 169, 07/10/2026) ───────
//
// Até 07/10 esta seção (então chamada "Fotos e variações") morava em Detalhes
// e incluía as fotos de cada variação; a Fase 169 (169-04) moveu ela inteira
// para a etapa Imagens — e com ela foram, por engano, estoque/SKU/código
// universal (EAN)/AGID/MPN e os demais atributos extras da variação, que não
// são fotos. O usuário relatou a regressão em produção: "Criou a etapa de
// imagens mas levou alguns campos que não devia [...] Todas esses campos
// deveriam ter ficado na etapa detalhes".
//
// Fix: a GESTÃO da variação (criar, tirar, trazer de volta, editar eixos) e
// os DADOS de cada uma (estoque, SKU, código, atributos extras —
// `CartaoVariante.jsx`) voltam para Detalhes, como PRIMEIRA seção — antes da
// ficha técnica, porque é aqui que nasce a variação que a ficha e as fotos
// vão usar. As FOTOS ficam só na etapa Imagens (`FotosEVariacoes.jsx` →
// `CartaoFotosVariante.jsx`).
//
// Os efeitos do anúncio inteiro (EAN automático, "fotos por variação", cor
// principal) continuam em `useEfeitosDasVariacoes` (FotosEVariacoes.jsx), que
// o Editor chama sempre, em qualquer etapa — nada disso muda aqui.

/**
 * Tirar a variação: com um eixo, o valor sai (a variação vira órfã e guarda os dados); com
 * mais eixos, ela é desativada. Nulo = não dá (publicada, ou o produto sem variação).
 */
export const acaoDeTirar = (m, v, eixos) => {
    if (v.publicada || Object.keys(v.valores ?? {}).length === 0) return null;
    if (eixos.length !== 1) return () => m.mudarVar(v.chave, { ativa: false });
    const eixo = eixos[0];

    return () => m.salvarEixos(eixosSemValor(eixos, eixo.chave, v.valores?.[eixo.chave]?.nome ?? ''));
};

export default function DadosDasVariacoes({ m }) {
    const [nova, setNova] = useState(false);
    const [avancado, setAvancado] = useState(false);
    const { estado, schema } = m;
    const eixos = estado.eixos ?? [];
    const atuais = m.variantes.filter((v) => ! v.orfa);
    const orfas = m.variantes.filter((v) => v.orfa);
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const limites = schema?.limites ?? {};
    const restauravel = (v) => Object.keys(v.valores ?? {}).length > 0 && Object.keys(v.valores).every((c) => eixos.some((e) => e.chave === c));

    return (
        <Secao id="variacoes" titulo="Variações"
            descricao="Se o produto tem cores, voltagens ou tamanhos, cada um é uma variação com o próprio estoque e código. As fotos de cada uma ficam na etapa Imagens.">
            {! schema ? <p className="text-[15px] text-white/55">Escolha a categoria na etapa Produto para cadastrar as variações.</p> : (
                <div className="space-y-5">
                    <div className="space-y-4" data-lista-variacoes={atuais.length}>
                        {atuais.map((v) => <CartaoVariante key={v.chave} m={m} v={v} eixos={eixos} onTirar={m.disabled ? null : acaoDeTirar(m, v, eixos)} />)}
                    </div>

                    {nova
                        ? <NovaVariacao m={m} eixos={eixos} schema={schema} onCancelar={() => setNova(false)} onCriada={() => setNova(false)} />
                        : ! m.disabled && (
                            <BotaoAcao onClick={() => setNova(true)} data-acao="adicionar-variacao">
                                <Plus size={16} aria-hidden="true" /> {temVariacoes ? 'Adicionar variação' : 'Adicionar variações (cor, voltagem, tamanho…)'}
                            </BotaoAcao>
                        )}

                    {orfas.length > 0 && (
                        <details className="text-[13px] text-white/55" data-orfas={orfas.length}>
                            <summary className="cursor-pointer rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Variações tiradas que ainda guardam dados</summary>
                            <ul className="mt-2 space-y-1.5">
                                {orfas.map((v) => (
                                    <li key={v.chave} className="flex flex-wrap items-center gap-3">
                                        <span>{v.rotulo} · estoque {v.estoque ?? '—'} · SKU {v.atributos?.SELLER_SKU?.value_name ?? '—'}</span>
                                        {! m.disabled && restauravel(v) && (
                                            <button type="button" onClick={() => m.salvarEixos(eixosComValores(eixos, v.valores))} className={LINK} data-restaurar-variacao={v.chave}>
                                                <RotateCcw size={14} aria-hidden="true" /> trazer de volta
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}

                    {! m.disabled && temVariacoes && (
                        <div className="border-t border-white/[0.08] pt-4">
                            <button type="button" onClick={() => setAvancado((x) => ! x)} aria-expanded={avancado} className={LINK} data-acao="editar-eixos">
                                {avancado ? 'Fechar a edição dos tipos de variação' : 'Editar os tipos de variação (avançado)'}
                            </button>
                            {avancado && (
                                <div className="mt-4">
                                    <EditorDeEixos eixos={eixos} schema={schema} maxEixos={limites.max_variation_axes ?? 3} disabled={m.disabled} onSalvar={m.salvarEixos} />
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </Secao>
    );
}
