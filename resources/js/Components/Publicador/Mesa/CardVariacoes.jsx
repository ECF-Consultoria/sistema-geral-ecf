import { useEffect, useRef, useState } from 'react';
import { Layers, Plus, RotateCcw } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import EditorDeEixos from '../EditorDeEixos';
import { AvisosDasFotos, BlocoDeFotos } from '../FotosPorGrupo';
import { GERAL, estadoDasSecoes } from '../apoio';
import { eixosComValores, eixosSemValor, gerarEan13, gtinsEmUso, variantesSemGtin } from '../ferramentas';
import CartaoVariante from './CartaoVariante';
import NovaVariacao from './NovaVariacao';
import { CardMesa } from './comum';

// ─── Card 3 — Variações, fotos e estoque (checks "Variações", "Fotos" e "Estoque") ──
//
// Como no Mercado Livre (03/10/2026): cada variação é um cartão com as próprias
// fotos, estoque, código universal e SKU; "Nova variação" abre um cartão em
// branco. O card Fotos separado deixou de existir — as fotos moram aqui.
//
// Por baixo continuam os eixos do servidor (quem gera as combinações e guarda
// os dados das órfãs é ele); aqui só se envia a lista de eixos por `m.salvarEixos`.
// Sem eixo que defina a foto, liga-se "fotos por variação" para cada uma ter as
// suas; com Cor (que define a foto), as variações da mesma cor dividem as fotos.
//
// Docx §4: o código universal (EAN-13) nasce sozinho em toda variação ativa sem
// código — uma vez por variação; apagado à mão, não volta sozinho.

/** "Fundo branco, {largura}×{altura}px no mínimo" — os números só aparecem se o schema os trouxer. */
const regraDaFoto = (limites) => {
    const largura = limites?.min_picture_width ?? limites?.minimum_picture_width;
    const altura = limites?.min_picture_height ?? limites?.minimum_picture_height;

    return largura && altura ? `fundo branco, ${largura}×${altura}px no mínimo` : 'fundo branco';
};

function ChipTotal({ faltam, total }) {
    return (
        <span className="inline-flex items-center gap-3">
            <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55 tabular-nums" data-total-anuncios={total}>Total a gerar: {total} {total === 1 ? 'anúncio' : 'anúncios'}</span>
            {faltam === 0 ? (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-emerald-400" data-chip-secao="completo">Completo</span>
            ) : (
                <span className="inline-flex items-center gap-2 rounded-full bg-white/[0.04] px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-white/55" data-chip-secao={faltam}>
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-400" /> {faltam === 1 ? 'Falta 1' : `Faltam ${faltam}`}
                </span>
            )}
        </span>
    );
}

/** O grupo de fotos da variação, e com quais outras ela divide as fotos. */
function fotosDaVariante(estado, v, rotulos) {
    if (Object.keys(v.valores ?? {}).length === 0) return { grupo: GERAL, com: [] };
    const g = (estado.grupos_imagem ?? []).find((x) => (x.variantes ?? []).includes(v.chave) || x.chave === v.chave);
    if (! g) return { grupo: null, com: [] };

    return { grupo: g.chave, com: (g.variantes ?? []).filter((c) => c !== v.chave).map((c) => rotulos[c]).filter(Boolean) };
}

export default function CardVariacoes({ m, aberto = true, onAlternar }) {
    const [novas, setNovas] = useState([]);
    const [avancado, setAvancado] = useState(false);
    const gerados = useRef(new Set());
    const sequencia = useRef(0);
    const { estado, schema } = m;
    const eixos = estado.eixos ?? [];
    const atuais = m.variantes.filter((v) => ! v.orfa);
    const orfas = m.variantes.filter((v) => v.orfa);
    const alvosAtivos = (m.alvos ?? []).filter((a) => a.ativo);
    const total = alvosAtivos.length * atuais.filter((v) => v.ativa).length;
    const situacao = estadoDasSecoes([...m.problemasDaSecao('variacoes'), ...m.problemasDaSecao('variantes'), ...m.problemasDaSecao('fotos')], schema);
    const faltam = situacao.variacoes.faltam + situacao.variantes.faltam + situacao.fotos.faltam;
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const algumDefineFoto = eixos.some((e) => e.defines_picture && e.valores.length > 0);
    const rotulos = Object.fromEntries(m.variantes.map((v) => [v.chave, v.rotulo]));
    const limites = schema?.limites ?? {};
    const umEixo = eixos.length === 1;

    // Sem eixo que defina a foto, cada variação ganha as próprias fotos ("fotos por variação").
    const precisaPorVariacao = temVariacoes && ! algumDefineFoto;
    useEffect(() => {
        if (m.disabled || ! precisaPorVariacao || estado.rascunho?.fotos_por_variante || m.rasc?.fotos_por_variante) return;
        m.mudarRasc({ fotos_por_variante: true });
    }, [precisaPorVariacao, estado.rascunho?.fotos_por_variante, m.disabled]); // eslint-disable-line react-hooks/exhaustive-deps

    // EAN-13 automático (gerador interno, o mesmo do assistente antigo).
    const semGtin = m.disabled ? [] : variantesSemGtin(m.variantes, schema).filter((v) => ! gerados.current.has(v.chave));
    const chaveSemGtin = semGtin.map((v) => v.chave).join('|');
    useEffect(() => {
        if (! chaveSemGtin) return;
        const usados = gtinsEmUso(m.variantes);
        for (const v of semGtin) {
            const ean = gerarEan13(usados);
            usados.add(ean);
            gerados.current.add(v.chave);
            m.mudarVar(v.chave, { atributos: { ...(v.atributos ?? {}), GTIN: { value_name: ean } } });
        }
    }, [chaveSemGtin]); // eslint-disable-line react-hooks/exhaustive-deps

    const novaVariacao = () => {
        sequencia.current += 1;
        setNovas((l) => [...l, sequencia.current]);
        setTimeout(() => [...document.querySelectorAll('[data-nova-variacao]')].pop()?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 0);
    };
    const fecharNova = (id) => setNovas((l) => l.filter((x) => x !== id));

    // Tirar: com um eixo, o valor sai (a variação vira órfã e guarda os dados); com mais, ela é desativada.
    const removerDe = (v) => {
        if (v.publicada || Object.keys(v.valores ?? {}).length === 0) return null;
        if (! umEixo) return () => m.mudarVar(v.chave, { ativa: false });
        const eixo = eixos[0];

        return () => m.salvarEixos(eixosSemValor(eixos, eixo.chave, v.valores?.[eixo.chave]?.nome ?? ''));
    };
    const restauravel = (v) => Object.keys(v.valores ?? {}).length > 0 && Object.keys(v.valores).every((c) => eixos.some((e) => e.chave === c));

    const resumo = temVariacoes
        ? `${atuais.length} ${atuais.length === 1 ? 'variação' : 'variações'} por ${eixos.filter((e) => e.valores.length).map((e) => e.nome).join(' × ')}`
        : 'Sem variações: o anúncio sai como um produto único.';

    return (
        <CardMesa id="card-variacoes" icone={Layers} titulo="Variações, fotos e estoque" chip={<ChipTotal faltam={faltam} total={total} />} aberto={aberto} onAlternar={onAlternar}
            apoio={`Cada variação com as próprias fotos (${regraDaFoto(limites)}), estoque, código universal e SKU.`}>
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria para definir as variações e as fotos.</p>
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-[13px] text-white/70" data-resumo-eixos>{resumo}</p>
                        {! m.disabled && temVariacoes && (
                            <button type="button" onClick={() => setAvancado((x) => ! x)} aria-expanded={avancado} data-acao="editar-eixos"
                                className="text-[13px] text-white/55 underline-offset-4 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                {avancado ? 'Fechar edição dos eixos' : 'Editar eixos (avançado)'}
                            </button>
                        )}
                    </div>

                    {avancado && (
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
                            <EditorDeEixos eixos={eixos} schema={schema} maxEixos={limites.max_variation_axes ?? 3} disabled={m.disabled} onSalvar={m.salvarEixos} />
                        </div>
                    )}

                    <AvisosDasFotos imagens={estado.imagens} atribuicoes={estado.atribuicoes} envioAoMl={estado.publicacao_liberada === true} disabled={m.disabled} onReenviar={m.reenviarFoto} />

                    {temVariacoes && (
                        <BlocoDeFotos grupo={GERAL} titulo="Fotos para todas as variações" nota="opcional"
                            imagens={estado.imagens} atribuicoes={estado.atribuicoes} maxFotos={limites.max_pictures_per_item ?? 10}
                            enviando={m.enviandoFoto} disabled={m.disabled} envioAoMl={estado.publicacao_liberada === true}
                            onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos} onExcluir={m.removerFoto} onReenviar={m.reenviarFoto}>
                            <label className="mt-3 flex items-center gap-2 text-[13px] text-white/55">
                                <input type="checkbox" checked={!! (m.rasc?.incluir_geral ?? estado.rascunho.incluir_geral)} disabled={m.disabled}
                                    onChange={(e) => m.mudarRasc({ incluir_geral: e.target.checked })}
                                    className="rounded border-white/20 bg-transparent text-ecf-yellow" data-opcao="incluir-geral" />
                                Colocar estas fotos no fim das fotos de cada variação
                            </label>
                        </BlocoDeFotos>
                    )}

                    <div className="space-y-4">
                        {atuais.map((v, i) => {
                            const { grupo, com } = fotosDaVariante(estado, v, rotulos);

                            return <CartaoVariante key={v.chave} m={m} v={v} indice={i + 1} eixos={eixos} alvosAtivos={alvosAtivos} grupo={grupo} fotosCom={com} onRemover={removerDe(v)} />;
                        })}
                        {novas.map((id) => (
                            <NovaVariacao key={id} m={m} eixos={eixos} schema={schema} onCancelar={() => fecharNova(id)} onCriada={() => fecharNova(id)} />
                        ))}
                    </div>

                    {! m.disabled && (
                        <Botao onClick={novaVariacao} data-acao="adicionar-variacao"><Plus size={14} /> Nova variação</Botao>
                    )}

                    {orfas.length > 0 && (
                        <details className="text-[13px] text-white/55" data-orfas={orfas.length}>
                            <summary className="cursor-pointer">Variações tiradas que ainda guardam dados ({orfas.length})</summary>
                            <ul className="mt-2 space-y-1">
                                {orfas.map((v) => (
                                    <li key={v.chave} className="flex flex-wrap items-center gap-2">
                                        <span>{v.rotulo} · estoque {v.estoque ?? '—'} · SKU {v.atributos?.SELLER_SKU?.value_name ?? '—'}</span>
                                        {! m.disabled && restauravel(v) && (
                                            <button type="button" onClick={() => m.salvarEixos(eixosComValores(eixos, v.valores))} data-restaurar-variacao={v.chave}
                                                className="inline-flex items-center gap-1 text-[11px] font-bold text-white/70 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                                <RotateCcw size={12} /> trazer de volta
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </div>
            )}
        </CardMesa>
    );
}
