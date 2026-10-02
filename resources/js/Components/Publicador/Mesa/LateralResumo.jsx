import axios from 'axios';
import { AlertTriangle, CheckCircle2, Loader2 } from 'lucide-react';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import { NOME_TIPO, mensagemDe } from '../apoio';
import { BotaoPublicar } from './BarraDoEditor';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Lateral: Resumo do lançamento (UI-SPEC §8.6) ───────────────────────────
//
// Conta de destino, modo logístico, anúncios por tipo e o botão primário (o
// único amarelo sólido em ≥ 1360px). Depois de publicar, mostra o andamento
// por item — a lógica do trilho do piloto, em tom calmo: vermelho só para
// "Não foi publicado" e item que falhou.

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

export default function LateralResumo({ pub, empresa, produtoId, primario }) {
    const r = pub.resumo;
    const publicacao = pub.publicacao;
    const publicado = pub.m.estado?.rascunho?.status === 'PUBLISHED';
    const andamento = publicacao?.status === 'RUNNING';
    const variantes = pub.m.estado?.variantes ?? [];

    const apoio = ! pub.liberada
        ? 'A validação e a publicação no Mercado Livre esperam a liberação desta conta.'
        : (pub.prontas < pub.totalSecoes
            ? 'Complete os itens da validação e confira no Mercado Livre.'
            : 'Libera quando o Mercado Livre aprovar a conferência.');

    const reenviarDescricao = async (itemId) => {
        try {
            await axios.post(route('mlb.anuncios.publicador.descricao', { produto: produtoId, item: itemId }));
            pub.recarregar();
        } catch (e) {
            pub.setErro(mensagemDe(e));
        }
    };

    return (
        <section aria-labelledby="titulo-resumo" className="rounded-xl border border-white/[0.08] bg-ecf-card p-6" data-lateral="resumo">
            <h2 id="titulo-resumo" className="text-[15px] font-bold text-white">Resumo do lançamento</h2>

            {r && (
                <dl className="mt-2 divide-y divide-white/[0.06]">
                    <Par rotulo="Conta de destino">
                        <span className="break-words">{empresa.conta_nome ?? empresa.nome}</span>
                        {empresa.conta_ml_id && <span className="font-mono text-[11px] text-white/55"> · ML {empresa.conta_ml_id}</span>}
                    </Par>
                    <Par rotulo="Modo logístico">{r.modoLogistico}</Par>
                    <Par rotulo="Anúncios Clássico">{textoDoTipo(r.classico)}</Par>
                    <Par rotulo="Anúncios Premium">{textoDoTipo(r.premium)}</Par>
                    <Par rotulo="Total"><span className="font-bold text-white">{r.total === 1 ? '1 anúncio' : `${r.total} anúncios`}</span></Par>
                </dl>
            )}

            {! publicado && (
                <div className="mt-4">
                    <BotaoPublicar pub={pub} primario={primario} className="h-10 w-full" />
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
    );
}
