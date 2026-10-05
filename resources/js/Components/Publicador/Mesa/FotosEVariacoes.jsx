import { useEffect, useRef, useState } from 'react';
import { ImagePlus, Plus, RotateCcw } from 'lucide-react';
import EditorDeEixos from '../EditorDeEixos';
import { AvisosDasFotos, BlocoDeFotos, fotosDoGrupo } from '../FotosPorGrupo';
import { GERAL } from '../apoio';
import { eixosComValores, eixosSemValor, gerarEan13, gtinsEmUso, nomeDaCor, tomDaCor, variantesSemGtin } from '../ferramentas';
import CartaoVariante from './CartaoVariante';
import NovaVariacao from './NovaVariacao';
import { BotaoAcao } from './botoes';
import { LINK, Secao } from './comum';

// ─── "Fotos e variações" (etapa Detalhes, 04/10/2026) ───────────────────────
//
// Como no Mercado Livre: cada variação é um bloco com as próprias fotos,
// estoque, SKU e código (`CartaoVariante`), um embaixo do outro, e "Adicionar
// variação" no fim. Produto sem variação = um bloco só, "Produto".
//
// Por baixo continuam os eixos do servidor (quem gera as combinações e guarda
// os dados das órfãs é ele); aqui só se envia a lista de eixos por
// `m.salvarEixos`. Editar os eixos à mão fica no "avançado".
//
// Os EFEITOS (ligar "fotos por variação" sem eixo de foto; EAN-13 automático,
// docx §4) ficam em `useEfeitosDasVariacoes`, que a página chama sempre — o EAN
// precisa nascer ao abrir o editor, em qualquer etapa.

/** "fundo branco, {largura}×{altura}px no mínimo" — os números só aparecem se o schema os trouxer. */
export const regraDaFoto = (limites) => {
    const largura = limites?.min_picture_width ?? limites?.minimum_picture_width;
    const altura = limites?.min_picture_height ?? limites?.minimum_picture_height;

    return largura && altura ? `fundo branco, ${largura}×${altura}px no mínimo` : 'fundo branco';
};

/** O grupo de fotos da variação, e com quais outras ela divide as fotos. */
export function fotosDaVariante(estado, v, rotulos = {}) {
    if (Object.keys(v.valores ?? {}).length === 0) return { grupo: GERAL, com: [] };
    const g = (estado?.grupos_imagem ?? []).find((x) => (x.variantes ?? []).includes(v.chave) || x.chave === v.chave);
    if (! g) return { grupo: null, com: [] };

    return { grupo: g.chave, com: (g.variantes ?? []).filter((c) => c !== v.chave).map((c) => rotulos[c]).filter(Boolean) };
}

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

/** Efeitos das variações (ver o comentário do topo). A página chama sempre, com ou sem estado. */
export function useEfeitosDasVariacoes(m) {
    const gerados = useRef(new Set());
    const { estado, schema } = m;
    const eixos = estado?.eixos ?? [];
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const algumDefineFoto = eixos.some((e) => e.defines_picture && e.valores.length > 0);

    // Sem eixo que defina a foto, cada variação ganha as próprias fotos ("fotos por variação").
    const precisaPorVariacao = Boolean(estado) && temVariacoes && ! algumDefineFoto;
    useEffect(() => {
        if (! estado || m.disabled || ! precisaPorVariacao || estado.rascunho?.fotos_por_variante || m.rasc?.fotos_por_variante) return;
        m.mudarRasc({ fotos_por_variante: true });
    }, [precisaPorVariacao, estado?.rascunho?.fotos_por_variante, m.disabled]); // eslint-disable-line react-hooks/exhaustive-deps

    // EAN-13 automático (gerador interno, o mesmo do assistente antigo): uma vez por variação.
    const semGtin = ! estado || m.disabled ? [] : variantesSemGtin(m.variantes, schema).filter((v) => ! gerados.current.has(v.chave));
    const chaveSemGtin = semGtin.map((v) => v.chave).join('|');
    useEffect(() => {
        if (! chaveSemGtin) return;
        const usados = gtinsEmUso(m.variantes);
        for (const v of semGtin) {
            const ean = gerarEan13(usados);
            usados.add(ean);
            gerados.current.add(v.chave);
            m.mudarVar(v.chave, (atual) => ({ atributos: { ...(atual.atributos ?? {}), GTIN: { value_name: ean } } }));
        }
    }, [chaveSemGtin]); // eslint-disable-line react-hooks/exhaustive-deps

    // Cor principal (MAIN_COLOR) pelo nome da cor, enquanto a pessoa não escolheu (ver CorPrincipal.jsx).
    // Nome que não dá tom tira o automático; escolha da pessoa ou da IA fica.
    const tomAttr = schema?.atributos?.MAIN_COLOR;
    const tons = ! estado || m.disabled || tomAttr?.secao !== 'VARIANTE' ? [] : m.variantes
        .filter((v) => ! v.orfa && ! v.publicada)
        .map((v) => {
            const atual = v.atributos?.MAIN_COLOR ?? null;
            if (atual && atual.origem !== 'auto') return null;
            const tom = tomDaCor(nomeDaCor(v, m.rasc?.atributos), tomAttr.valores);

            return String(tom?.id ?? '') === String(atual?.value_id ?? '') ? null : { chave: v.chave, tom };
        })
        .filter(Boolean);
    const chaveTons = tons.map(({ chave, tom }) => `${chave}:${tom?.id ?? ''}`).join('|');
    useEffect(() => {
        if (! chaveTons) return;
        for (const { chave, tom } of tons) {
            m.mudarVar(chave, (atual) => {
                const atributos = { ...(atual.atributos ?? {}) };
                if (tom) atributos.MAIN_COLOR = { value_id: String(tom.id), value_name: tom.name, origem: 'auto' }; else delete atributos.MAIN_COLOR;

                return { atributos };
            });
        }
    }, [chaveTons]); // eslint-disable-line react-hooks/exhaustive-deps
}

// O que são as "fotos para todas as variações" (análise do Publicador, 04/10/2026: a equipe
// não entendia para que servem). Desligar a inclusão deixa as fotos guardadas, mas fora de
// todos os anúncios (`ResolvedorGruposImagem`: sem `incluirGeral` a galeria geral não entra
// em variação que tem grupo próprio) — por isso o aviso quando está desligada.
const PARA_QUE_SERVEM = 'Para fotos que valem para qualquer variação: embalagem, detalhes, medidas, o produto em uso. Envie uma vez e elas entram em todas as variações, depois das fotos de cada uma. Não precisa repetir em cada variação.';

/** As fotos que valem para todas as variações (galeria geral). */
function FotosParaTodas({ m }) {
    const { estado, schema } = m;
    const limites = schema?.limites ?? {};
    const temGerais = fotosDoGrupo(estado.imagens, estado.atribuicoes, GERAL).length > 0;
    const [aberto, setAberto] = useState(temGerais);
    const incluir = !! (m.rasc?.incluir_geral ?? estado.rascunho.incluir_geral);

    if (! aberto && ! temGerais) {
        return (
            <div data-fotos-para-todas="fechado">
                <button type="button" onClick={() => setAberto(true)} disabled={m.disabled} className={LINK} data-acao="fotos-para-todas">
                    <ImagePlus size={16} aria-hidden="true" /> Adicionar fotos iguais para todas as variações
                </button>
                <p className="mt-1 text-[13px] text-white/50">{PARA_QUE_SERVEM}</p>
            </div>
        );
    }

    return (
        <BlocoDeFotos grupo={GERAL} titulo="Fotos para todas as variações" nota={incluir ? 'entram em todas, depois das fotos de cada uma' : 'fora dos anúncios'}
            imagens={estado.imagens} atribuicoes={estado.atribuicoes} maxFotos={limites.max_pictures_per_item ?? 10}
            enviando={m.enviandoFoto} disabled={m.disabled} envioAoMl={estado.publicacao_liberada === true}
            onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos} onExcluir={m.removerFoto} onReenviar={m.reenviarFoto}>
            <p className="mt-3 text-[13px] text-white/50" data-explicacao-fotos-para-todas>{PARA_QUE_SERVEM}</p>
            <label className="mt-3 flex items-center gap-2 text-[13px] text-white/70">
                <input type="checkbox" checked={incluir} disabled={m.disabled}
                    onChange={(e) => m.mudarRasc({ incluir_geral: e.target.checked })}
                    className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-opcao="incluir-geral" />
                Usar estas fotos em todas as variações
            </label>
            {! incluir && temGerais && (
                <p className="mt-1.5 text-[13px] text-amber-300" data-aviso-fotos-para-todas>Desmarcado: estas fotos ficam guardadas, mas não entram em nenhum anúncio.</p>
            )}
        </BlocoDeFotos>
    );
}

export default function FotosEVariacoes({ m }) {
    const [nova, setNova] = useState(false);
    const [avancado, setAvancado] = useState(false);
    const { estado, schema } = m;
    const eixos = estado.eixos ?? [];
    const atuais = m.variantes.filter((v) => ! v.orfa);
    const orfas = m.variantes.filter((v) => v.orfa);
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const limites = schema?.limites ?? {};
    const rotulos = Object.fromEntries(m.variantes.map((x) => [x.chave, x.rotulo]));
    const restauravel = (v) => Object.keys(v.valores ?? {}).length > 0 && Object.keys(v.valores).every((c) => eixos.some((e) => e.chave === c));

    return (
        <Secao id="variacoes" titulo="Fotos e variações"
            descricao={`Se o produto tem cores, voltagens ou tamanhos, cada um é uma variação com as próprias fotos, estoque e código. Fotos com ${regraDaFoto(limites)}; a primeira é a capa.`}>
            {! schema ? <p className="text-[15px] text-white/55">Escolha a categoria na etapa Produto para cadastrar as fotos e as variações.</p> : (
                <div className="space-y-5">
                    <AvisosDasFotos imagens={estado.imagens} atribuicoes={estado.atribuicoes} envioAoMl={estado.publicacao_liberada === true} disabled={m.disabled} onReenviar={m.reenviarFoto} />

                    <div className="space-y-4" data-lista-variacoes={atuais.length}>
                        {atuais.map((v) => {
                            const { grupo, com } = fotosDaVariante(estado, v, rotulos);

                            return <CartaoVariante key={v.chave} m={m} v={v} eixos={eixos} grupo={grupo} fotosCom={com} onTirar={m.disabled ? null : acaoDeTirar(m, v, eixos)} />;
                        })}
                    </div>

                    {nova
                        ? <NovaVariacao m={m} eixos={eixos} schema={schema} onCancelar={() => setNova(false)} onCriada={() => setNova(false)} />
                        : ! m.disabled && (
                            <BotaoAcao onClick={() => setNova(true)} data-acao="adicionar-variacao">
                                <Plus size={16} aria-hidden="true" /> {temVariacoes ? 'Adicionar variação' : 'Adicionar variações (cor, voltagem, tamanho…)'}
                            </BotaoAcao>
                        )}

                    {temVariacoes && <FotosParaTodas m={m} />}

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
