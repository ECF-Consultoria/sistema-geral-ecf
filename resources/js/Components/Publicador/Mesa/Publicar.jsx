import axios from 'axios';
import { AlertTriangle, CheckCircle2, ImageOff, Loader2, Lock } from 'lucide-react';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import { fotosDoGrupo } from '../FotosPorGrupo';
import { ETAPAS, GERAL, NOME_TIPO, etapaDoProblema, mensagemDe } from '../apoio';
import { BotaoConferir, BotaoPublicar, publicarEhOProximoPasso } from './AcoesDePublicacao';
import { fotosDaVariante } from './FotosEVariacoes';
import { LINK, Secao } from './comum';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Fim da etapa "Condições de venda": revisar e publicar ──────────────────
//
// 1. A situação da conferência (D26: local em tom calmo, com cadeado).
// 2. O que ainda falta, por etapa, com "Corrigir em …" — que leva à etapa já
//    com os campos marcados em vermelho e ACESOS em amarelo (10/10/2026:
//    aviso também, e mais de um de uma vez). Cada linha também é um atalho
//    para o campo dela. Sem contador de progresso.
// 3. Conferir e Publicar — o único amarelo desta etapa: Conferir até o Mercado
//    Livre aprovar, Publicar depois; desabilitado, diz o motivo.
// 4. Ao lado, a prévia do anúncio como o comprador vê — só com o que o
//    estado tem: capa, título, preço, condição. Nada inventado.

const CONDICAO = { new: 'Novo', used: 'Usado', refurbished: 'Recondicionado' };
const STATUS_PUBLICACAO = { RUNNING: 'Publicando…', PUBLISHED: 'Publicado no Mercado Livre', PARTIALLY_PUBLISHED: 'Parte foi publicada', FAILED: 'Não foi publicado' };
const COR_PUBLICACAO = { RUNNING: 'text-white/70', PUBLISHED: 'text-emerald-400', PARTIALLY_PUBLISHED: 'text-amber-300', FAILED: 'text-red-300' };
const STATUS_ITEM = { PENDING: 'na fila', SENT: 'enviando', UNKNOWN: 'confirmando…', FAILED: 'não publicado' };
const POR_ETAPA_A_VISTA = 4;

/** Tom e texto curto da situação da conferência. */
export function situacaoDaConferencia(pub) {
    if (pub.m.estado?.rascunho?.status === 'PUBLISHED') return { tom: 'completo', texto: 'Publicado no Mercado Livre' };
    const conf = pub.conferencia.estado;
    if (conf === 'conferindo') return { tom: 'neutro', texto: 'Conferindo…' };
    if (conf === 'ok') return { tom: 'completo', texto: 'Conferido: pronto para publicar' };
    if (conf === 'avisos') return { tom: 'completo', texto: 'Conferido, com avisos' };
    if (conf === 'bloqueado' || conf === 'local_bloqueado') return { tom: 'falta', texto: 'A conferência encontrou o que corrigir' };
    if (conf === 'editado') return { tom: 'neutro', texto: 'Editado: confira de novo' };
    if (conf === 'local') return { tom: 'neutro', texto: 'Conferido aqui (conta não liberada)' };
    if (conf === 'erro') return { tom: 'falta', texto: 'A conferência não terminou' };

    return { tom: 'neutro', texto: 'Ainda não conferido' };
}

function Previa({ m }) {
    const { estado, rasc } = m;
    const rascunho = estado.rascunho;
    const v = m.variantes.find((x) => ! x.orfa && x.ativa) ?? null;
    const ligados = (m.alvos ?? []).filter((x) => x.ativo);
    const a = ligados.find((x) => x.listing_type_id === 'gold_special') ?? ligados[0] ?? null;
    const titulo = a ? (a.titulo || a.titulo_efetivo || '') : '';
    const preco = v && a ? (v.precos?.[a.listing_type_id] ?? v.precos_efetivos?.[a.listing_type_id] ?? null) : null;
    const grupo = v ? fotosDaVariante(estado, v).grupo : GERAL;
    const proprias = grupo ? fotosDoGrupo(estado.imagens, estado.atribuicoes, grupo) : [];
    const gerais = fotosDoGrupo(estado.imagens, estado.atribuicoes, GERAL);
    const capa = (proprias[0] ?? ((grupo === GERAL || (rasc?.incluir_geral ?? rascunho.incluir_geral)) ? gerais[0] : null)) ?? null;
    const temVariacoes = (estado.eixos ?? []).some((e) => e.valores.length > 0);

    return (
        <div data-previa>
            <p className="mb-2 text-[13px] font-bold text-white/70">Como o comprador vê{a ? ` (${NOME_TIPO[a.listing_type_id]})` : ''}</p>
            {/* O cartão branco é o anúncio no Mercado Livre: só o que já está preenchido. */}
            <div className="rounded-lg bg-white p-4 text-neutral-900" data-previa-cartao>
                <div className="grid aspect-square w-full place-items-center overflow-hidden rounded border border-neutral-200 bg-neutral-50" data-previa-capa={capa ? 'sim' : 'nao'}>
                    {capa?.url ? <img src={capa.url} alt="" className="h-full w-full object-contain" /> : <ImageOff size={28} className="text-neutral-400" aria-label="Sem foto" />}
                </div>
                <p className="mt-3 text-[11px] text-neutral-500">{CONDICAO[rasc?.condicao ?? rascunho.condicao] ?? 'Novo'}</p>
                <p className={cn('mt-0.5 line-clamp-2 text-[15px] leading-snug', titulo ? 'text-neutral-900' : 'text-neutral-400')} data-previa-titulo>{titulo || 'Sem título'}</p>
                <p className={cn('mt-2 font-display text-[24px] leading-none tabular-nums', preco !== null ? 'text-neutral-950' : 'text-neutral-400')} data-previa-preco>
                    {preco !== null ? formatCurrency(preco) : 'Sem preço'}
                </p>
                {(rasc?.envio?.modo ?? 'me2') === 'me2' && rasc?.envio?.frete_gratis && <p className="mt-2 text-[13px] font-bold text-emerald-600" data-previa-frete>Frete grátis</p>}
                {temVariacoes && v && <p className="mt-2 text-[13px] text-neutral-600" data-previa-variacao>{v.rotulo}</p>}
            </div>
        </div>
    );
}

/**
 * `onIrPara(etapa, problema?)` leva à etapa com os campos marcados e acesos; com `problema`, direto ao campo dele.
 * `produtoId` é para reenviar a descrição.
 */
export default function Publicar({ pub, empresa, produtoId, onIrPara }) {
    const { m } = pub;
    const publicacao = pub.publicacao;
    const publicado = m.estado?.rascunho?.status === 'PUBLISHED';
    const andamento = publicacao?.status === 'RUNNING';
    const variantes = m.estado?.variantes ?? [];
    const situacao = situacaoDaConferencia(pub);
    const local = pub.conferencia.local;
    const estadoConf = pub.conferencia.estado;
    const publicarPrimario = publicarEhOProximoPasso(pub);
    // O texto longo da conferência costuma repetir a situação ("Ainda não conferido. Nesta conta…"): só o resto.
    const textoConf = pub.conferencia.texto ?? '';
    const detalhe = textoConf.startsWith(situacao.texto) ? textoConf.slice(situacao.texto.length).replace(/^[.\s]+/, '') : textoConf;

    // O que falta, por etapa: os bloqueios primeiro, depois os avisos.
    const lista = pub.problemas.filter((p) => p.severidade !== 'INFO');
    const bloqueios = lista.filter((p) => p.severidade === 'BLOCKER');
    const porEtapa = ETAPAS.map((e) => ({
        ...e,
        itens: lista.filter((p) => etapaDoProblema(p) === e.chave).sort((x, y) => (x.severidade === 'BLOCKER' ? 0 : 1) - (y.severidade === 'BLOCKER' ? 0 : 1)),
    })).filter((e) => e.itens.length > 0);

    const motivoDoPublicar = () => {
        if (! pub.liberada) return 'A validação e a publicação no Mercado Livre esperam a liberação desta conta.';
        if (bloqueios.length > 0) return 'Corrija o que está acima para liberar a publicação.';
        if (estadoConf === 'avisos' && ! pub.ciente) return 'Marque que leu os avisos da conferência para publicar.';
        if (! ['ok', 'avisos'].includes(estadoConf)) return 'Libera quando o Mercado Livre aprovar a conferência.';

        return null;
    };

    const reenviarDescricao = async (itemId) => {
        try {
            await axios.post(route('mlb.anuncios.publicador.descricao', { produto: produtoId, item: itemId }));
            pub.recarregar();
        } catch (e) {
            pub.setErro(mensagemDe(e));
        }
    };

    return (
        <Secao id="publicar" titulo="Revisar e publicar" descricao="O Mercado Livre confere o anúncio antes de publicar. Se algo faltar, a gente mostra onde corrigir.">
            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px]" data-publicar data-conferencia={estadoConf}>
                <div className="min-w-0 space-y-5">
                    <div className={cn('flex items-center gap-3 rounded-lg border p-4', situacao.tom === 'completo' ? 'border-emerald-500/25 bg-emerald-500/[0.06]' : (situacao.tom === 'falta' ? 'border-amber-400/25 bg-amber-400/[0.05]' : 'border-white/[0.10] bg-white/[0.02]'))}
                        data-situacao={situacao.tom} aria-live="polite">
                        {local
                            ? <Lock size={16} className="shrink-0 text-white/55" aria-hidden="true" />
                            : (situacao.tom === 'completo' ? <CheckCircle2 size={16} className="shrink-0 text-emerald-400" aria-hidden="true" /> : <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', situacao.tom === 'falta' ? 'bg-amber-400' : 'bg-white/30')} aria-hidden="true" />)}
                        <div className="min-w-0">
                            <p className={cn('text-[15px] font-bold', situacao.tom === 'completo' ? 'text-emerald-400' : (situacao.tom === 'falta' ? 'text-amber-300' : 'text-white'))}>{situacao.texto}</p>
                            {detalhe && <p className="text-[13px] text-white/55">{detalhe}</p>}
                        </div>
                    </div>

                    {porEtapa.length > 0 && (
                        <div className="space-y-4" data-pendencias>
                            {porEtapa.map((e) => (
                                <div key={e.chave} className="rounded-lg border border-white/[0.10] p-4" data-pendencias-etapa={e.chave}>
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-[15px] font-bold text-white">{e.titulo}</p>
                                        {onIrPara && <button type="button" onClick={() => onIrPara(e.chave)} className={LINK} data-ir-para={e.chave}>Corrigir em {e.titulo}</button>}
                                    </div>
                                    <ul className="space-y-1.5">
                                        {e.itens.slice(0, POR_ETAPA_A_VISTA).map((p, i) => (
                                            <li key={`${p.regra}-${i}`} className={cn('flex items-start gap-2 text-[13px]', p.severidade === 'BLOCKER' ? 'text-red-300' : 'text-white/65')} data-aviso={p.regra}>
                                                <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
                                                {onIrPara
                                                    ? <button type="button" onClick={() => onIrPara(e.chave, p)} data-ir-para-ponto={p.regra}
                                                        className="rounded text-left underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">{p.mensagem}</button>
                                                    : <span>{p.mensagem}</span>}
                                            </li>
                                        ))}
                                        {e.itens.length > POR_ETAPA_A_VISTA && <li className="text-[13px] text-white/45">e mais {e.itens.length - POR_ETAPA_A_VISTA}.</li>}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    )}

                    {! local && estadoConf === 'avisos' && (
                        <label className="flex items-start gap-3 text-[15px] text-white/80">
                            <input type="checkbox" checked={pub.ciente} onChange={(e) => pub.setCiente(e.target.checked)}
                                className="mt-1 h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-ciente />
                            Li os avisos da conferência e quero publicar assim mesmo.
                        </label>
                    )}

                    {! publicado && (
                        <div className="space-y-3 border-t border-white/[0.08] pt-5" data-acoes-publicacao>
                            <div className="flex flex-wrap gap-3">
                                <BotaoConferir pub={pub} primario={! publicarPrimario} className="h-11 px-5" />
                                <BotaoPublicar pub={pub} primario={publicarPrimario} className="h-11 px-5" />
                            </div>
                            {motivoDoPublicar() && <p className="text-[13px] text-white/55" data-motivo-publicar>{motivoDoPublicar()}</p>}
                            {! pub.liberada && <AvisoContaTravada variante="nota" />}
                            {pub.liberada && (empresa.conta_nome ?? empresa.nome) && <p className="text-[13px] text-white/45">Publica na conta {empresa.conta_nome ?? empresa.nome}{empresa.conta_ml_id ? ` · ML ${empresa.conta_ml_id}` : ''}.</p>}
                        </div>
                    )}

                    {publicacao && (
                        <div className="space-y-2" data-publicacao={publicacao.status} aria-live="polite">
                            <p className={cn('flex items-center gap-2 text-[15px] font-bold', COR_PUBLICACAO[publicacao.status])}>
                                {andamento
                                    ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
                                    : (publicacao.status === 'PUBLISHED' ? <CheckCircle2 size={16} aria-hidden="true" /> : <AlertTriangle size={16} aria-hidden="true" />)}
                                {STATUS_PUBLICACAO[publicacao.status]}
                            </p>
                            {publicacao.motivo && <p className="text-[13px] text-red-300">{publicacao.motivo}</p>}
                            <ul className="space-y-1.5 text-[13px]">
                                {(publicacao.itens ?? []).map((i) => (
                                    <li key={i.id} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-white/70" data-item-publicacao={i.status}>
                                        <span className="text-white/55">{NOME_TIPO[i.listing_type_id]} · {variantes.find((v) => v.chave === i.variante_chave)?.rotulo ?? '—'}</span>
                                        {i.ml_item_id
                                            ? <LinkMl mlb={i.ml_item_id} />
                                            : <span className={i.status === 'FAILED' ? 'text-red-300' : 'text-white/55'}>{STATUS_ITEM[i.status] ?? i.status}</span>}
                                        {i.descricao_status === 'FAILED' && (
                                            <button type="button" onClick={() => reenviarDescricao(i.id)} className={LINK}>reenviar descrição</button>
                                        )}
                                        {i.plano_b && <span className="text-sky-200">· confira o estoque por depósito no ML</span>}
                                        {i.mensagem && <span className="w-full text-amber-300">{i.mensagem}</span>}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                <Previa m={m} />
            </div>
        </Secao>
    );
}
