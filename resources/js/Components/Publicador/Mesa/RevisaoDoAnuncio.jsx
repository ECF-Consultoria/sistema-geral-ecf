import { CheckCircle2 } from 'lucide-react';
import { fotosDoGrupo } from '../FotosPorGrupo';
import { ETAPAS, GERAL, NOME_TIPO, estadoDasEtapas, valorVazio } from '../apoio';
import { fotosDaVariante } from './CardVariacoes';
import { ChipSecao } from './comum';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Revisão: o anúncio como ele vai sair, bloco a bloco (03/10/2026) ───────
//
// Um bloco por etapa de conteúdo, só leitura, com o estado dela, as pendências
// que o servidor aponta (locais e da conferência, já mescladas pelo hook) e
// "Editar", que leva à etapa. Nada aqui grava: é a leitura antes de publicar.

const CONDICAO = { new: 'Novo', used: 'Usado', refurbished: 'Recondicionado' };

const faixa = (t) => {
    if (! t?.ativo) return 'Desligado';
    if (t.min === null) return 'sem preço';

    return t.min === t.max ? formatCurrency(t.min) : `${formatCurrency(t.min)} a ${formatCurrency(t.max)}`;
};

/** Primeira pendência (BLOCKER) de todas, para a linha "Falta pouco". */
const primeiraPendencia = (pub) => {
    const locais = pub.m.estado?.problemas ?? [];
    const conf = pub.m.estado?.conferencia;
    const doMl = conf?.vale && ! conf.local ? (conf.issues ?? []) : [];

    return [...locais, ...doMl].find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null;
};

function Par({ rotulo, children }) {
    return (
        <div className="flex gap-3 py-1 text-[13px]">
            <dt className="w-[132px] shrink-0 font-normal text-white/45">{rotulo}</dt>
            <dd className="min-w-0 flex-1 font-normal text-white/80">{children}</dd>
        </div>
    );
}

function Bloco({ etapa, faltam, problemas, onEditar, children }) {
    const info = ETAPAS.find((e) => e.chave === etapa);
    const pendencias = (problemas ?? []).filter((p) => p.severidade !== 'INFO');

    return (
        <section aria-labelledby={`revisao-${etapa}`} className="rounded-[10px] border border-white/[0.08] bg-white/[0.02] p-4" data-revisao={etapa} data-faltam={faltam}>
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h3 id={`revisao-${etapa}`} className="text-[15px] font-bold text-white">{info?.titulo}</h3>
                <span className="flex items-center gap-3">
                    <ChipSecao faltam={faltam} />
                    <button type="button" onClick={() => onEditar(etapa)} data-editar-etapa={etapa}
                        className="rounded text-[13px] font-normal text-white/70 underline-offset-4 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                        Editar
                    </button>
                </span>
            </div>
            {pendencias.length > 0 && (
                <ul className="mt-3 space-y-1" data-pendencias-bloco={pendencias.length}>
                    {pendencias.map((p, i) => (
                        <li key={`${p.regra}-${i}`} className={cn('flex items-start gap-2 text-[13px]', p.severidade === 'BLOCKER' ? 'text-amber-200' : 'text-white/55')} data-problema={p.regra}>
                            <span className={cn('mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full', p.severidade === 'BLOCKER' ? 'bg-amber-400' : 'bg-white/30')} aria-hidden="true" />
                            <span>{p.mensagem}</span>
                        </li>
                    ))}
                </ul>
            )}
            <dl className="mt-3">{children}</dl>
        </section>
    );
}

export default function RevisaoDoAnuncio({ pub, onIrPara }) {
    const { m } = pub;
    const { estado, rasc, schema } = m;
    const { produto, rascunho } = estado;
    const estados = estadoDasEtapas(pub.secoes);
    const problemasDe = (chave) => ETAPAS.find((e) => e.chave === chave).secoes.flatMap((s) => m.problemasDaSecao(s));
    const tudoPronto = pub.prontas === pub.totalSecoes;
    const r = pub.resumo;

    // Produto e categoria
    const caminho = schema?.caminho ?? [];
    const condicao = CONDICAO[rasc.condicao ?? rascunho.condicao] ?? '—';

    // Ficha técnica
    const atributos = Object.values(schema?.atributos ?? {}).filter((a) => ['PRINCIPAIS', 'FICHA', 'AVANCADO'].includes(a.secao));
    const obrigatorios = atributos.filter((a) => a.secao === 'PRINCIPAIS' || a.obrigatoriedade === 'REQUIRED');
    const preenchidos = (lista) => lista.filter((a) => ! valorVazio(rasc.atributos?.[a.id]));
    const principais = preenchidos(atributos.filter((a) => a.secao === 'PRINCIPAIS')).slice(0, 6);
    const textoDoValor = (a) => {
        const v = rasc.atributos?.[a.id];
        if (v?.value_id === '-1') return 'Não se aplica';

        return v?.value_name ?? v?.value_number ?? '—';
    };

    // Variações e fotos
    const eixos = estado.eixos ?? [];
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const variantes = m.variantes.filter((v) => ! v.orfa);
    const rotulos = Object.fromEntries(m.variantes.map((v) => [v.chave, v.rotulo]));
    const fotosGerais = fotosDoGrupo(estado.imagens, estado.atribuicoes, GERAL).length;
    const fotosDe = (v) => {
        const { grupo } = fotosDaVariante(estado, v, rotulos);
        const proprias = grupo ? fotosDoGrupo(estado.imagens, estado.atribuicoes, grupo).length : 0;
        const gerais = grupo !== GERAL && (m.rasc?.incluir_geral ?? rascunho.incluir_geral) ? fotosGerais : 0;

        return proprias + gerais;
    };

    // Título e preço
    const alvos = m.alvos ?? [];
    const faixaDe = { gold_special: r?.classico, gold_pro: r?.premium };

    // Envio e garantia
    const medida = (id) => rasc.atributos?.[id]?.value_name ?? null;
    const dimensoes = ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH'].map(medida);
    const pacote = dimensoes.every(Boolean) ? `${dimensoes.join(' × ')}${medida('SELLER_PACKAGE_WEIGHT') ? ` · ${medida('SELLER_PACKAGE_WEIGHT')}` : ''}` : (medida('SELLER_PACKAGE_WEIGHT') ? `peso ${medida('SELLER_PACKAGE_WEIGHT')}` : null);
    const garantia = rasc.garantia ?? null;
    const nomeGarantia = garantia?.tipo ? (schema?.garantia?.tipos ?? []).find((g) => String(g.id) === String(garantia.tipo))?.name ?? garantia.tipo : null;
    const textoGarantia = nomeGarantia ? `${nomeGarantia}${garantia.tempo ? ` · ${garantia.tempo} ${garantia.unidade ?? ''}`.trimEnd() : ''}` : null;

    // Descrição
    const descricao = String(rasc.descricao ?? '');

    return (
        <div className="space-y-4" data-revisao-do-anuncio>
            <p className={cn('flex items-start gap-2 rounded-[10px] border p-3 text-[13px] font-normal', tudoPronto ? 'border-emerald-500/20 bg-emerald-500/[0.06] text-emerald-300' : 'border-white/[0.08] bg-white/[0.03] text-white/70')} data-nota-prontidao>
                {tudoPronto
                    ? <CheckCircle2 size={16} className="mt-px shrink-0" aria-hidden="true" />
                    : <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400" aria-hidden="true" />}
                <span>
                    {tudoPronto
                        ? 'Tudo pronto. Pode conferir no Mercado Livre.'
                        : <><span className="font-bold text-white">Falta pouco.</span> {primeiraPendencia(pub) ?? 'Complete as etapas com pendência.'}</>}
                </span>
            </p>

            <Bloco etapa="produto" faltam={estados.produto.faltam} problemas={problemasDe('produto')} onEditar={onIrPara}>
                <Par rotulo="Produto"><span className="font-bold text-white">{produto.nome}</span> <span className="font-mono text-[11px] text-white/45">{produto.sku}</span></Par>
                <Par rotulo="Condição">{condicao}</Par>
                <Par rotulo="Categoria">
                    {rascunho.categoria_id
                        ? <>{caminho.slice(0, -1).map((c) => <span key={c} className="text-white/45">{c} › </span>)}<span className="font-bold text-white">{caminho[caminho.length - 1] ?? rascunho.categoria_id}</span></>
                        : <span className="text-amber-200">Sem categoria</span>}
                </Par>
            </Bloco>

            <Bloco etapa="ficha" faltam={estados.ficha.faltam} problemas={problemasDe('ficha')} onEditar={onIrPara}>
                {schema ? (
                    <>
                        <Par rotulo="Preenchimento">{preenchidos(obrigatorios).length} de {obrigatorios.length} obrigatórios · {preenchidos(atributos).length} de {atributos.length} características</Par>
                        {principais.map((a) => <Par key={a.id} rotulo={a.nome}>{textoDoValor(a)}</Par>)}
                    </>
                ) : <Par rotulo="Características">Vêm da categoria.</Par>}
            </Bloco>

            <Bloco etapa="variacoes" faltam={estados.variacoes.faltam} problemas={problemasDe('variacoes')} onEditar={onIrPara}>
                <Par rotulo="Variações">{temVariacoes ? `${variantes.length} por ${eixos.filter((e) => e.valores.length).map((e) => e.nome).join(' × ')}` : 'Produto único, sem variações'}</Par>
                {variantes.map((v) => {
                    const fotos = fotosDe(v);

                    return (
                        <Par key={v.chave} rotulo={Object.keys(v.valores ?? {}).length === 0 ? 'Produto' : v.rotulo}>
                            <span className={cn(! v.ativa && 'text-white/45 line-through')}>
                                <span className={cn(fotos === 0 && v.ativa && 'text-amber-200')}>{fotos === 1 ? '1 foto' : `${fotos} fotos`}</span>
                                {' · '}estoque {v.estoque ?? Object.values(v.estoque_depositos ?? {}).reduce((s, n) => s + (Number(n) || 0), 0)}
                                {v.atributos?.SELLER_SKU?.value_name && <> · <span className="font-mono text-[11px]">SKU {v.atributos.SELLER_SKU.value_name}</span></>}
                                {v.atributos?.GTIN?.value_name && <> · <span className="font-mono text-[11px]">GTIN {v.atributos.GTIN.value_name}</span></>}
                            </span>
                            {! v.ativa && <span className="ml-2 text-[11px] text-white/45">desativada</span>}
                        </Par>
                    );
                })}
            </Bloco>

            <Bloco etapa="tipos" faltam={estados.tipos.faltam} problemas={problemasDe('tipos')} onEditar={onIrPara}>
                {alvos.map((a) => (
                    <Par key={a.listing_type_id} rotulo={NOME_TIPO[a.listing_type_id]}>
                        {a.ativo ? (
                            <>
                                <span className={cn('font-bold', (a.titulo || a.titulo_efetivo) ? 'text-white' : 'text-amber-200')}>{a.titulo || a.titulo_efetivo || 'Sem título'}</span>
                                <span className="text-white/45"> · {faixa(faixaDe[a.listing_type_id])}</span>
                            </>
                        ) : <span className="text-white/45">Desligado</span>}
                    </Par>
                ))}
            </Bloco>

            <Bloco etapa="logistica" faltam={estados.logistica.faltam} problemas={problemasDe('logistica')} onEditar={onIrPara}>
                <Par rotulo="Envio">{r?.modoLogistico ?? '—'}{(rasc.envio?.frete_gratis || m.frete?.obrigatorio) ? ' · frete grátis' : ''}</Par>
                <Par rotulo="Pacote fechado">{pacote ?? <span className="text-amber-200">Sem medidas</span>}</Par>
                <Par rotulo="Garantia">{textoGarantia ?? <span className="text-white/45">Não informada</span>}</Par>
            </Bloco>

            <Bloco etapa="descricao" faltam={estados.descricao.faltam} problemas={problemasDe('descricao')} onEditar={onIrPara}>
                <Par rotulo="Texto">
                    {descricao.trim()
                        ? <span className="line-clamp-3 whitespace-pre-line">{descricao}</span>
                        : <span className="text-amber-200">Sem descrição</span>}
                    {descricao.trim() && <span className="mt-1 block font-mono text-[11px] text-white/45">{descricao.length} caracteres</span>}
                </Par>
            </Bloco>
        </div>
    );
}
