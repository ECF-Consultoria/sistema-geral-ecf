import { ExternalLink, Pause, Play, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { COR_ITEM_DA_FILA } from './LinhaDoLote';
import {
    ROTULO_STATUS_FILA, ROTULO_STATUS_ITEM, comoLista, comoObjeto, fmtHora, fraseDoRitmo, numeroSeguro, textoSeguro,
} from './regrasDoLote.js';

// ─── O painel da fila de publicação da conta (10/10/2026) ───────────────────
//
// Rodadas de N produtos (Clássico + Premium, todas as cores), uma a cada X
// minutos — quem anda a fila é o servidor (`publicador:fila-publicacao`, todo
// minuto), esta tela só mostra e manda pausar, retomar, cancelar ou tirar um
// produto. Os horários são a previsão do servidor (rodadas, intervalo, janela).

const COR_FILA = {
    ativa: 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    pausada: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
    concluida: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200',
    cancelada: 'border-white/[0.10] bg-white/[0.03] text-white/55',
};

const NOME_TIPO = { gold_special: 'Clássico', gold_pro: 'Premium' };

/** O horário do próximo já passou (o servidor começa na próxima passada, a cada minuto)? */
export function proximoJaPassou(iso, agora = new Date()) {
    const d = new Date(String(iso ?? ''));

    return ! Number.isNaN(d.getTime()) && d.getTime() <= agora.getTime();
}

/**
 * @param {{fila: ?object, ocupado?: boolean, aoPausar?: Function, aoRetomar?: Function, aoCancelar?: Function, aoRemover?: (itemId: number) => void, agora?: Date}} props
 */
export default function PainelDaFila({ fila, ocupado = false, aoPausar, aoRetomar, aoCancelar, aoRemover, agora = null }) {
    const instante = agora instanceof Date ? agora : new Date();
    if (! fila || typeof fila !== 'object') return null;
    const f = comoObjeto(fila);
    const progresso = comoObjeto(f.progresso);
    const contagens = comoObjeto(f.contagens);
    const itens = comoLista(f.itens);
    const total = numeroSeguro(progresso.total) ?? 0;
    const andados = numeroSeguro(progresso.andados) ?? 0;
    const pct = Math.max(0, Math.min(100, numeroSeguro(progresso.pct) ?? 0));
    const janela = f.janela && typeof f.janela === 'object' ? f.janela : null;
    const ativa = f.status === 'ativa';
    const pausada = f.status === 'pausada';
    const intervalo = numeroSeguro(f.intervalo_minutos) ?? 10;
    const porRodada = numeroSeguro(f.produtos_por_rodada) ?? 1;
    const revisar = (numeroSeguro(contagens.precisa_revisar) ?? 0) + (numeroSeguro(contagens.falhou) ?? 0);

    return (
        <section aria-label="Fila de publicação" className="mb-6 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-[15px] font-bold text-white">Fila de publicação</h2>
                        <span className={cn('rounded-md border px-1.5 py-0.5 text-[11px] font-bold', COR_FILA[f.status] ?? COR_FILA.cancelada)}>
                            {textoSeguro(ROTULO_STATUS_FILA[f.status], 'Fila')}
                        </span>
                    </div>
                    <p className="mt-1 text-[13px] font-normal text-white/60">
                        {fraseDoRitmo(porRodada, intervalo)}
                        {janela ? `, só entre ${textoSeguro(janela.inicio)} e ${textoSeguro(janela.fim)}` : ''}
                        {textoSeguro(f.criada_por, '') !== '' ? ` · agendada por ${textoSeguro(f.criada_por)}` : ''}
                    </p>
                </div>
                {f.viva === true && (
                    <div className="flex flex-wrap gap-2">
                        {ativa && (
                            <BotaoAcao onClick={() => aoPausar?.()} disabled={ocupado}>
                                <Pause className="h-4 w-4" aria-hidden="true" />
                                Pausar
                            </BotaoAcao>
                        )}
                        {pausada && (
                            <BotaoAcao onClick={() => aoRetomar?.()} disabled={ocupado}>
                                <Play className="h-4 w-4" aria-hidden="true" />
                                Retomar
                            </BotaoAcao>
                        )}
                        <BotaoAcao onClick={() => aoCancelar?.()} disabled={ocupado}>Cancelar fila</BotaoAcao>
                    </div>
                )}
            </div>

            {pausada && textoSeguro(f.motivo_pausa, '') !== '' && (
                <p className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[13px] font-normal text-amber-200">
                    {textoSeguro(f.motivo_pausa)}
                </p>
            )}

            <div className="mt-4">
                <div className="h-2 overflow-hidden rounded-full bg-white/[0.06]" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label="Andamento da fila">
                    <div className="h-full rounded-full bg-ecf-yellow transition-[width]" style={{ width: `${pct}%` }} />
                </div>
                <p className="mt-2 text-[13px] font-normal text-white/70">
                    <span className="font-bold text-white">{andados} de {total}</span> produtos
                    {' · '}{numeroSeguro(progresso.feitos) ?? 0} publicados
                    {revisar > 0 && <span className="text-amber-300">{' · '}{revisar === 1 ? '1 para revisar' : `${revisar} para revisar`}</span>}
                    {ativa && f.proximo_em && (proximoJaPassou(f.proximo_em, instante)
                        ? <>{' · '}{porRodada > 1 ? 'a próxima rodada começa em instantes' : 'o próximo começa em instantes'}</>
                        : <>{' · '}{porRodada > 1 ? 'próxima rodada às' : 'próximo às'} <span className="font-bold text-white">{fmtHora(f.proximo_em, instante)}</span></>)}
                    {ativa && f.termina_em && <>{' · '}termina por volta de <span className="font-bold text-white">{fmtHora(f.termina_em, instante)}</span></>}
                </p>
            </div>

            {itens.length > 0 && (
                <ul className="mt-4 max-h-80 divide-y divide-white/[0.06] overflow-y-auto rounded-lg border border-white/[0.06]">
                    {itens.map((item) => {
                        const i = comoObjeto(item);
                        const mlbs = comoLista(i.mlbs);
                        const filaAtiva = f.status === 'ativa';
                        const podeTirar = i.status === 'agendado' && f.viva === true;

                        return (
                            <li key={textoSeguro(i.id, '')} className="flex flex-wrap items-start gap-3 px-3 py-2">
                                <span className="w-6 shrink-0 pt-0.5 text-right font-mono text-[11px] tabular-nums text-white/40">{textoSeguro(i.posicao, '')}</span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px] font-bold text-white/90">
                                        {textoSeguro(i.nome, 'Produto')}
                                        {textoSeguro(i.sku, '') !== '' && <span className="ml-2 font-mono text-[11px] font-normal text-white/45">{textoSeguro(i.sku)}</span>}
                                    </p>
                                    {textoSeguro(i.motivo, '') !== '' && i.status !== 'publicado' && (
                                        <p className="mt-0.5 text-[11px] font-normal text-white/55">{textoSeguro(i.motivo)}</p>
                                    )}
                                    {mlbs.length > 0 && (
                                        <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-normal">
                                            {mlbs.map((m) => {
                                                const mlb = comoObjeto(m);
                                                const link = textoSeguro(mlb.permalink, '');

                                                return link !== '' ? (
                                                    <a key={textoSeguro(mlb.ml_item_id, '')} href={link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-mono text-sky-200 hover:underline">
                                                        {textoSeguro(mlb.ml_item_id)} <span className="font-sans text-white/40">{NOME_TIPO[mlb.listing_type_id] ?? ''}</span>
                                                        <ExternalLink className="h-3 w-3" aria-hidden="true" />
                                                    </a>
                                                ) : (
                                                    <span key={textoSeguro(mlb.ml_item_id, '')} className="font-mono text-white/70">{textoSeguro(mlb.ml_item_id)}</span>
                                                );
                                            })}
                                            {textoSeguro(i.tarefa_url, '') !== '' && (
                                                <a href={textoSeguro(i.tarefa_url)} className="font-bold text-white/70 hover:text-ecf-yellow hover:underline">Alavancas</a>
                                            )}
                                        </p>
                                    )}
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    {i.status === 'agendado' && filaAtiva && i.previsto_em && (
                                        <span className="font-mono text-[11px] tabular-nums text-white/50" title="Previsão de início">{fmtHora(i.previsto_em, instante)}</span>
                                    )}
                                    <span className={cn('rounded-md border px-1.5 py-0.5 text-[11px] font-bold', COR_ITEM_DA_FILA[i.status] ?? COR_ITEM_DA_FILA.agendado)}>
                                        {textoSeguro(ROTULO_STATUS_ITEM[i.status], textoSeguro(i.status))}
                                    </span>
                                    {podeTirar && (
                                        <button
                                            type="button"
                                            onClick={() => aoRemover?.(i.id)}
                                            disabled={ocupado}
                                            aria-label={`Tirar ${textoSeguro(i.nome, 'o produto')} da fila`}
                                            className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-white/[0.10] text-white/60 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-40"
                                        >
                                            <X className="h-3.5 w-3.5" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
