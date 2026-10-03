import axios from 'axios';
import { AlertTriangle, CheckCircle2, Loader2 } from 'lucide-react';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import Problemas from '../Problemas';
import { NOME_TIPO, etapaDaEtapaMl, mensagemDe, secaoDoProblema } from '../apoio';
import { BotaoConferir, BotaoPublicar, publicarEhOProximoPasso } from './AcoesDePublicacao';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Revisão: números do lançamento e as ações (UI-SPEC §8.5/§8.6, juntas) ──
//
// A coluna da direita da etapa "Revisar e publicar": conta de destino, modo
// logístico, anúncios por tipo; Conferir, o resultado da conferência e
// Publicar. Só UM dos dois é amarelo: Conferir até o Mercado Livre aprovar,
// Publicar depois. As pendências que apontam para uma etapa ficam nos blocos
// da esquerda; aqui só as da conta/conferência (sem etapa) e os avisos.
//
// D26: em conta não liberada a conferência é só local — o resultado aparece
// como local (Lock, cinza), sem âmbar de alerta, sem vermelho e nunca como
// "o Mercado Livre apontou". Depois de publicar, o andamento por item: vermelho
// só para "Não foi publicado" e item que falhou.

const STATUS_PUBLICACAO = {
    RUNNING: 'Publicando…',
    PUBLISHED: 'Publicado no Mercado Livre',
    PARTIALLY_PUBLISHED: 'Parte foi publicada',
    FAILED: 'Não foi publicado',
};
const COR_PUBLICACAO = {
    RUNNING: 'text-white/70',
    PUBLISHED: 'text-emerald-400',
    PARTIALLY_PUBLISHED: 'text-amber-300',
    FAILED: 'text-red-300',
};
const STATUS_ITEM = { PENDING: 'na fila', SENT: 'enviando', UNKNOWN: 'confirmando…', FAILED: 'não publicado' };

/** "N anúncios (R$ min–máx)"; sem faixa de preço só a contagem. */
const textoDoTipo = (t) => {
    if (! t.ativo) return 'Desligado';
    const n = t.n === 1 ? '1 anúncio' : `${t.n} anúncios`;
    if (t.min === null) return n;

    return t.min === t.max ? `${n} (${formatCurrency(t.min)})` : `${n} (${formatCurrency(t.min)}–${formatCurrency(t.max)})`;
};

function Par({ rotulo, children }) {
    return (
        <div className="flex items-start justify-between gap-3 py-2 text-[13px]">
            <dt className="shrink-0 font-normal text-white/55">{rotulo}</dt>
            <dd className="min-w-0 text-right font-normal text-white/80">{children}</dd>
        </div>
    );
}

export default function RevisaoLancamento({ pub, empresa, produtoId, onIrPara }) {
    const r = pub.resumo;
    const publicacao = pub.publicacao;
    const publicado = pub.m.estado?.rascunho?.status === 'PUBLISHED';
    const andamento = publicacao?.status === 'RUNNING';
    const variantes = pub.m.estado?.variantes ?? [];

    const local = pub.conferencia.local;
    const estadoConf = pub.conferencia.estado;
    const conferindo = estadoConf === 'conferindo';
    const publicarPrimario = publicarEhOProximoPasso(pub);
    // WR-F07: tudo o que a conferência apontou entra; o que tem etapa já está nos blocos da esquerda.
    const semEtapa = (p) => secaoDoProblema(p) === null;
    const bloqueiosConf = (pub.conferencia.bloqueios ?? []).filter(semEtapa);
    const avisosConf = (pub.conferencia.listaDeAvisos ?? []).filter(semEtapa);
    const avisosComEtapa = (pub.conferencia.listaDeAvisos ?? []).length - avisosConf.length;

    const apoio = ! pub.liberada
        ? 'A validação e a publicação no Mercado Livre esperam a liberação desta conta.'
        : (pub.prontas < pub.totalSecoes
            ? 'Complete os itens da validação e confira no Mercado Livre.'
            : 'Libera quando o Mercado Livre aprovar a conferência.');

    const irPorEtapaMl = (etapaMl) => {
        const etapa = etapaDaEtapaMl(etapaMl);
        if (etapa) onIrPara(etapa);
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
        <div className="space-y-4" data-revisao-lancamento>
            <section aria-labelledby="titulo-resumo" className="rounded-[10px] border border-white/[0.08] bg-white/[0.02] p-4" data-lateral="resumo">
                <h3 id="titulo-resumo" className="text-[15px] font-bold text-white">Resumo do lançamento</h3>
                {r && (
                    <dl className="mt-1 divide-y divide-white/[0.06]">
                        <Par rotulo="Conta de destino">
                            <span className="break-words">{empresa.conta_nome ?? empresa.nome}</span>
                            {empresa.conta_ml_id && <span className="block font-mono text-[11px] text-white/55">ML {empresa.conta_ml_id}</span>}
                        </Par>
                        <Par rotulo="Modo logístico">{r.modoLogistico}</Par>
                        <Par rotulo="Anúncios Clássico">{textoDoTipo(r.classico)}</Par>
                        <Par rotulo="Anúncios Premium">{textoDoTipo(r.premium)}</Par>
                        <Par rotulo="Total"><span className="font-bold text-white">{r.total === 1 ? '1 anúncio' : `${r.total} anúncios`}</span></Par>
                    </dl>
                )}
            </section>

            <section aria-labelledby="titulo-validacao" className="rounded-[10px] border border-white/[0.08] bg-white/[0.02] p-4" data-lateral="validacao" data-conferencia={estadoConf}>
                <h3 id="titulo-validacao" className="text-[15px] font-bold text-white">Validação no Mercado Livre</h3>

                {! publicado && <BotaoConferir pub={pub} primario={! publicarPrimario} className="mt-3 w-full" />}

                {/* Linha da conferência: local (D26) em tom calmo, ou a do Mercado Livre. */}
                <div className="mt-3" aria-live="polite">
                    {local ? (
                        <AvisoContaTravada variante="linha">{pub.conferencia.texto}</AvisoContaTravada>
                    ) : (
                        <p className={cn('flex items-start gap-2 text-[13px] font-normal', estadoConf === 'ok' ? 'text-emerald-400' : 'text-white/70')}>
                            {conferindo
                                ? <Loader2 size={14} className="mt-1 shrink-0 animate-spin" aria-hidden="true" />
                                : (estadoConf === 'ok' && <CheckCircle2 size={14} className="mt-0.5 shrink-0" aria-hidden="true" />)}
                            <span>{pub.conferencia.texto}</span>
                        </p>
                    )}
                </div>

                {bloqueiosConf.length > 0 && (
                    <div className="mt-3" data-pendencias-conferencia><Problemas problemas={bloqueiosConf} onIr={irPorEtapaMl} /></div>
                )}

                {! local && (avisosConf.length > 0 || estadoConf === 'avisos') && (
                    <div className="mt-3 space-y-3">
                        {avisosConf.length > 0 && <Problemas problemas={avisosConf} onIr={irPorEtapaMl} />}
                        {avisosComEtapa > 0 && avisosConf.length === 0 && (
                            <p className="text-[13px] font-normal text-white/55">{avisosComEtapa === 1 ? 'O aviso está' : `Os ${avisosComEtapa} avisos estão`} no bloco da etapa, ao lado.</p>
                        )}
                        {estadoConf === 'avisos' && (
                            <label className="flex items-start gap-2 text-[13px] font-normal text-white/70">
                                <input
                                    type="checkbox"
                                    checked={pub.ciente}
                                    onChange={(e) => pub.setCiente(e.target.checked)}
                                    className="mt-1 rounded border-white/20 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                    data-ciente
                                />
                                Li os avisos da conferência e quero publicar assim mesmo.
                            </label>
                        )}
                    </div>
                )}

                {! publicado && (
                    <div className="mt-5 border-t border-white/[0.06] pt-4">
                        <BotaoPublicar pub={pub} primario={publicarPrimario} className="w-full" />
                        <p className="mt-2 text-center text-[11px] font-normal text-white/55">{apoio}</p>
                        {! pub.liberada && <AvisoContaTravada variante="nota" className="mt-3" />}
                    </div>
                )}

                {publicacao && (
                    <div className="mt-4 space-y-2 border-t border-white/[0.08] pt-4" data-publicacao={publicacao.status} aria-live="polite">
                        <p className={cn('flex items-center gap-2 text-[13px] font-bold', COR_PUBLICACAO[publicacao.status])}>
                            {andamento
                                ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
                                : (publicacao.status === 'PUBLISHED' ? <CheckCircle2 size={14} aria-hidden="true" /> : <AlertTriangle size={14} aria-hidden="true" />)}
                            {STATUS_PUBLICACAO[publicacao.status]}
                        </p>
                        {publicacao.motivo && <p className="text-[13px] font-normal text-red-300">{publicacao.motivo}</p>}
                        <ul className="space-y-1 text-[13px]">
                            {(publicacao.itens ?? []).map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center gap-x-2 gap-y-1 font-normal text-white/70" data-item-publicacao={i.status}>
                                    <span className="text-white/55">{NOME_TIPO[i.listing_type_id]} · {variantes.find((v) => v.chave === i.variante_chave)?.rotulo ?? '—'}</span>
                                    {i.ml_item_id
                                        ? <LinkMl mlb={i.ml_item_id} />
                                        : <span className={i.status === 'FAILED' ? 'text-red-300' : 'text-white/55'}>{STATUS_ITEM[i.status] ?? i.status}</span>}
                                    {i.descricao_status === 'FAILED' && (
                                        <button type="button" onClick={() => reenviarDescricao(i.id)} className="text-[13px] text-white/70 underline-offset-2 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                            reenviar descrição
                                        </button>
                                    )}
                                    {i.plano_b && <span className="text-sky-200">· confira o estoque por depósito no ML</span>}
                                    {i.mensagem && <span className="w-full text-amber-300">{i.mensagem}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </section>
        </div>
    );
}
