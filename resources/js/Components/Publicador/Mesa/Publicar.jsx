import { useState } from 'react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, ExternalLink, Loader2, Lock } from 'lucide-react';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import { linkAnuncioMl } from '@/Pages/Mlb/anuncioHistoricoUtils';
import { ETAPAS, NOME_TIPO, etapaDoProblema, mensagemDe } from '../apoio';
import { BotaoConferir, BotaoPublicar, publicarEhOProximoPasso } from './AcoesDePublicacao';
import { BASE_BOTAO, PRIMARIO } from './botoes';
import { LINK, Secao } from './comum';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Fim da etapa "Prévia": revisar e publicar ──────────────────────────────
//
// 20/10/2026: era o fim de "Condições de venda"; foi para baixo da Prévia
// (`EtapaPrevia`), que mostra o anúncio inteiro como no Mercado Livre — por
// isso o cartãozinho "Como o comprador vê" que ficava ao lado saiu daqui.
//
// 1. A situação da conferência (D26: local em tom calmo, com cadeado).
// 2. O que ainda falta, por etapa, com "Corrigir em …" — que leva à etapa já
//    com os campos marcados em vermelho e ACESOS em amarelo (10/10/2026:
//    aviso também, e mais de um de uma vez). Cada linha também é um atalho
//    para o campo dela. Sem contador de progresso.
// 3. Conferir e Publicar — o único amarelo desta etapa: Conferir até o Mercado
//    Livre aprovar, Publicar depois; desabilitado, diz o motivo.

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
    // Demo 23/10 (N13): com aviso não é verde — o aviso pode ser perder o frete grátis.
    if (conf === 'avisos') return { tom: 'aviso', texto: 'Conferido, com avisos: leia antes de publicar' };
    if (conf === 'bloqueado' || conf === 'local_bloqueado') return { tom: 'falta', texto: 'A conferência encontrou o que corrigir' };
    if (conf === 'editado') return { tom: 'neutro', texto: 'Editado: confira de novo' };
    if (conf === 'local') return { tom: 'neutro', texto: 'Conferido aqui (conta não liberada)' };
    if (conf === 'erro') return { tom: 'falta', texto: 'A conferência não terminou' };

    return { tom: 'neutro', texto: 'Ainda não conferido' };
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
    const ambar = situacao.tom === 'falta' || situacao.tom === 'aviso';
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

    // 11/10/2026 — a conferência compara o frete do Mercado Livre com o da Precificação (V-FRT-01). Quando
    // diverge, este botão leva o do Mercado Livre para lá: o preço do Portal é refeito e pede conferir de novo.
    const [levandoFrete, setLevandoFrete] = useState(false);
    // Com `itemId`, é a terceira conferência: o frete que o Mercado Livre cobra de UM anúncio já publicado.
    const levarFrete = async (itemId = null) => {
        setLevandoFrete(true);
        try {
            const { data } = itemId === null
                ? await axios.post(route('mlb.anuncios.publicador.frete-precificacao', { produto: produtoId }))
                : await axios.post(route('mlb.anuncios.publicador.itens.frete-precificacao', { produto: produtoId, item: itemId }));
            const n = data?.frete_levado?.aplicadas ?? 0;
            pub.setAviso(itemId === null
                ? `Frete do Mercado Livre levado para a Precificação (${n} ${n === 1 ? 'valor' : 'valores'}). O preço que vem do Portal foi refeito: confira de novo.`
                : 'Frete do anúncio levado para a Precificação. O preço do Portal foi refeito: atualize o preço deste anúncio no Mercado Livre.');
            pub.recarregar();
        } catch (e) {
            pub.setErro(mensagemDe(e));
        } finally {
            setLevandoFrete(false);
        }
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
            <div data-publicar data-conferencia={estadoConf}>
                <div className="min-w-0 space-y-5">
                    <div className={cn('flex items-center gap-3 rounded-lg border p-4', situacao.tom === 'completo' ? 'border-emerald-500/25 bg-emerald-500/[0.06]' : (ambar ? 'border-amber-400/25 bg-amber-400/[0.05]' : 'border-white/[0.10] bg-white/[0.02]'))}
                        data-situacao={situacao.tom} aria-live="polite">
                        {local
                            ? <Lock size={16} className="shrink-0 text-white/55" aria-hidden="true" />
                            : (situacao.tom === 'completo'
                                ? <CheckCircle2 size={16} className="shrink-0 text-emerald-400" aria-hidden="true" />
                                : (situacao.tom === 'aviso'
                                    ? <AlertTriangle size={16} className="shrink-0 text-amber-300" aria-hidden="true" />
                                    : <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', ambar ? 'bg-amber-400' : 'bg-white/30')} aria-hidden="true" />))}
                        <div className="min-w-0">
                            <p className={cn('text-[15px] font-bold', situacao.tom === 'completo' ? 'text-emerald-400' : (ambar ? 'text-amber-300' : 'text-white'))}>{situacao.texto}</p>
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
                                    {e.itens.some((p) => p.regra === 'V-FRT-01') && (
                                        <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-white/[0.08] pt-3" data-frete-para-precificacao>
                                            <button type="button" onClick={() => levarFrete()} disabled={levandoFrete} className={LINK} data-acao="levar-frete">
                                                {levandoFrete && <Loader2 size={14} className="animate-spin" aria-hidden="true" />} Levar o frete do Mercado Livre para a Precificação
                                            </button>
                                            <span className="text-[13px] text-white/45">O preço do Portal é refeito com ele; depois, confira de novo.</span>
                                        </div>
                                    )}
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
                            {motivoDoPublicar() && <p className="text-[15px] text-amber-300" data-motivo-publicar>{motivoDoPublicar()}</p>}
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
                            {/* Demo 23/10 (H20): o anúncio no ar é o fim do fluxo — botão grande, não só o código MLB. */}
                            {(publicacao.itens ?? []).some((i) => i.ml_item_id) && (
                                <div className="flex flex-wrap gap-3 pt-1" data-ver-no-ml>
                                    {(publicacao.itens ?? []).filter((i) => i.ml_item_id && linkAnuncioMl(i.ml_item_id)).map((i) => {
                                        const rotulo = variantes.length > 1 ? variantes.find((v) => v.chave === i.variante_chave)?.rotulo : null;

                                        return (
                                            <a key={i.id} href={linkAnuncioMl(i.ml_item_id)} target="_blank" rel="noopener noreferrer"
                                                className={cn(BASE_BOTAO, PRIMARIO, 'h-11 px-5 text-[15px]')}>
                                                Ver o {NOME_TIPO[i.listing_type_id] ?? 'anúncio'}{rotulo ? ` ${rotulo}` : ''} no Mercado Livre
                                                <ExternalLink size={16} aria-hidden="true" />
                                            </a>
                                        );
                                    })}
                                </div>
                            )}
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
                                        {/* 11/10/2026: o frete que o Mercado Livre cobra deste anúncio, contra o da Precificação. */}
                                        {i.frete?.aplicavel === false && <span className="text-white/45" data-frete-do-anuncio="nao-se-aplica">· sem frete do Mercado Livre (a combinar)</span>}
                                        {i.frete?.aplicavel && i.frete.nivel === 'igual' && <span className="text-white/45" data-frete-do-anuncio="igual">· frete de {formatCurrency(i.frete.ml)} conferido com a Precificação</span>}
                                        {/* Sem frete na Precificação não há com o que comparar, mas o que o ML cobra já é informação. */}
                                        {i.frete?.aplicavel && i.frete.nivel === null && i.frete.ml !== null && (
                                            <span className="text-white/45" data-frete-do-anuncio="sem-referencia">· o Mercado Livre cobra {formatCurrency(i.frete.ml)} de frete (a Precificação está sem frete para comparar)</span>
                                        )}
                                        {i.frete?.mensagem && (
                                            <span className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-amber-300" data-frete-do-anuncio={i.frete.nivel}>
                                                <span>{i.frete.mensagem}</span>
                                                {i.frete.pode_levar && (
                                                    <button type="button" onClick={() => levarFrete(i.id)} disabled={levandoFrete} className={LINK} data-acao="levar-frete-do-anuncio">
                                                        Levar para a Precificação
                                                    </button>
                                                )}
                                                {i.frete.levado_em && <span className="text-white/45">já levado para a Precificação</span>}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </Secao>
    );
}
