import { useEffect, useRef, useState } from 'react';
import { Plus, RotateCcw } from 'lucide-react';
import EditorDeEixos from '../EditorDeEixos';
import { AvisosDasFotos, fotosDoGrupo } from '../FotosPorGrupo';
import { GERAL, contarBloqueios, itemDaVariante, problemasDaVariante } from '../apoio';
import { eixosComValores, eixosSemValor, gerarEan13, gtinsEmUso, variantesSemGtin } from '../ferramentas';
import { corDaVariante } from './CartaoVariante';
import { BotaoAcao } from './botoes';
import { PontoDeStatus } from './comum';
import { cn } from '@/lib/utils';

// ─── Item "Variações" (o pai, na árvore) e os efeitos das variações ─────────
//
// Como no Mercado Livre (03/10/2026): cada variação é um item próprio da
// árvore, com as próprias fotos, estoque, código e preço (`CartaoVariante`).
// Aqui, no pai: por que o produto varia, a lista das variações com estado e
// atalho para abrir cada uma, as tiradas que ainda guardam dados e "Nova
// variação" (que abre o fluxo `NovaVariacao` no centro).
//
// Por baixo continuam os eixos do servidor (quem gera as combinações e guarda
// os dados das órfãs é ele); aqui só se envia a lista de eixos por `m.salvarEixos`.
//
// Os EFEITOS que antes moravam no card (ligar "fotos por variação" sem eixo de
// foto; EAN-13 automático, docx §4) viraram `useEfeitosDasVariacoes`, que a
// página chama sempre — o centro só monta um item por vez, e o EAN precisa
// nascer ao abrir a mesa, não ao abrir a variação.

/** "Fundo branco, {largura}×{altura}px no mínimo" — os números só aparecem se o schema os trouxer. */
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

/** Quantas fotos a variação leva: as do grupo dela e, se "incluir geral", as da galeria geral. */
export const fotosDaVariacao = (m, v) => {
    const { estado, rasc } = m;
    const { grupo } = fotosDaVariante(estado, v);
    const proprias = grupo ? fotosDoGrupo(estado.imagens, estado.atribuicoes, grupo).length : 0;
    const gerais = grupo !== GERAL && (rasc?.incluir_geral ?? estado.rascunho?.incluir_geral) ? fotosDoGrupo(estado.imagens, estado.atribuicoes, GERAL).length : 0;

    return proprias + gerais;
};

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
            m.mudarVar(v.chave, { atributos: { ...(v.atributos ?? {}), GTIN: { value_name: ean } } });
        }
    }, [chaveSemGtin]); // eslint-disable-line react-hooks/exhaustive-deps
}

/** `problemas` = todos os do rascunho (para o estado de cada variação); `onSelecionar` abre um item. */
export default function CardVariacoes({ m, problemas = [], onSelecionar }) {
    const [avancado, setAvancado] = useState(false);
    const { estado, schema } = m;
    const eixos = estado.eixos ?? [];
    const atuais = m.variantes.filter((v) => ! v.orfa);
    const orfas = m.variantes.filter((v) => v.orfa);
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const limites = schema?.limites ?? {};
    const restauravel = (v) => Object.keys(v.valores ?? {}).length > 0 && Object.keys(v.valores).every((c) => eixos.some((e) => e.chave === c));

    if (! schema) return <p className="text-[13px] text-white/55">Escolha a categoria para definir as variações.</p>;

    const resumo = temVariacoes
        ? `${atuais.length} ${atuais.length === 1 ? 'variação' : 'variações'} por ${eixos.filter((e) => e.valores.length).map((e) => e.nome).join(' × ')}`
        : 'Sem variações: o anúncio sai como um produto único.';

    return (
        <div className="space-y-5">
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

            {/* Uma linha por variação: estado, fotos, estoque, SKU — e "Abrir" para editar no centro. */}
            <ul className="divide-y divide-white/[0.06] rounded-xl border border-white/[0.08] bg-white/[0.02]" data-lista-variacoes={atuais.length}>
                {atuais.map((v) => {
                    const cor = corDaVariante(v, eixos);
                    const { grupo } = fotosDaVariante(estado, v);
                    const faltam = v.ativa ? contarBloqueios(problemasDaVariante(problemas, v, grupo)) : 0;
                    const fotos = fotosDaVariacao(m, v);
                    const semVariacao = Object.keys(v.valores ?? {}).length === 0;

                    return (
                        <li key={v.chave} className={cn('flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3', ! v.ativa && 'opacity-60')} data-linha-variacao={v.chave}>
                            <PontoDeStatus faltam={v.ativa ? faltam : null} />
                            {cor && <span className="h-3 w-3 rounded-full border border-white/20" style={{ backgroundColor: cor }} aria-hidden="true" />}
                            <span className="min-w-0 flex-1 text-[13px] font-bold text-white">{semVariacao ? 'Produto (sem variação)' : v.rotulo}{! v.ativa && <span className="ml-2 font-normal text-white/45">desativada</span>}</span>
                            <span className="text-[13px] text-white/55">
                                <span className={cn(fotos === 0 && v.ativa && 'text-amber-200')}>{fotos === 1 ? '1 foto' : `${fotos} fotos`}</span>
                                {' · '}estoque {v.estoque ?? Object.values(v.estoque_depositos ?? {}).reduce((s, n) => s + (Number(n) || 0), 0)}
                                {v.atributos?.SELLER_SKU?.value_name && <> · <span className="font-mono text-[11px]">SKU {v.atributos.SELLER_SKU.value_name}</span></>}
                            </span>
                            <BotaoAcao onClick={() => onSelecionar(itemDaVariante(v.chave))} className="h-8 px-3 font-normal" data-abrir-variacao={v.chave}>Abrir</BotaoAcao>
                        </li>
                    );
                })}
            </ul>

            {! m.disabled && (
                <BotaoAcao onClick={() => onSelecionar('variacoes/nova')} data-acao="adicionar-variacao"><Plus size={14} /> Nova variação</BotaoAcao>
            )}

            {orfas.length > 0 && (
                <details className="text-[13px] text-white/55" data-orfas={orfas.length}>
                    <summary className="cursor-pointer rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Variações tiradas que ainda guardam dados ({orfas.length})</summary>
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
    );
}
