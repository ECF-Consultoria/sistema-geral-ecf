import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, Hourglass, MessageCircleQuestion, UserCheck, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { encerrado, fmtDataHora, haQuanto, IMPACTOS, TIPOS } from '@/lib/chamados';
import { CaixaDeResposta, LinhaDoTempo, ListaAnexos, StatusChamado } from '@/Components/Chamados/Partes';

// "O que acontece agora" — o próximo passo, na língua de quem pediu.
const PROXIMO = {
    aberto:                 { Icone: Hourglass,             tom: 'text-sky-400',     texto: 'Seu ticket está na fila. Assim que alguém da equipe responder, você recebe um aviso no sino.' },
    em_triagem:             { Icone: Hourglass,             tom: 'text-violet-400',  texto: 'A equipe está entendendo o seu pedido para decidir o melhor caminho.' },
    em_atendimento:         { Icone: UserCheck,             tom: 'text-amber-400',   texto: 'Alguém da equipe está cuidando disso. Novidades aparecem na conversa.' },
    aguardando_solicitante: { Icone: MessageCircleQuestion, tom: 'text-orange-400',  texto: 'A equipe precisa de uma resposta sua para continuar. Responda na conversa.' },
    resolvido:              { Icone: CheckCircle2,          tom: 'text-emerald-400', texto: 'Resolvido. Se o problema voltar, reabra este mesmo ticket.' },
    cancelado:              { Icone: XCircle,               tom: 'text-white/50',    texto: 'Ticket cancelado. Se precisar, abra um novo.' },
};

/**
 * Ticket visto por quem abriu. O payload já chega filtrado pelo servidor:
 * só mensagens e eventos públicos — nada de nota interna nem de demanda.
 */
export default function TicketShow({ chamado: c }) {
    const fechado = encerrado(c.status);
    const proximo = PROXIMO[c.status];

    return (
        <AppLayout title={c.codigo}>
            <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6">
                <Link href={route('chamados.index')} className="inline-flex items-center gap-1.5 text-[12.5px] text-white/50 hover:text-white">
                    <ArrowLeft size={14} /> Meus tickets
                </Link>

                <header className="mt-3 flex flex-wrap items-start gap-x-4 gap-y-2">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[12.5px] font-semibold tabular-nums text-white/50">{c.codigo}</span>
                            <StatusChamado status={c.status} paraSolicitante />
                        </div>
                        <h1 className="mt-1 font-display text-[22px] font-semibold leading-snug text-white">{c.titulo}</h1>
                    </div>
                </header>

                <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    {/* ── Pedido + conversa ── */}
                    <div className="min-w-0 space-y-6">
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                            <h2 className="text-[13px] font-semibold text-white/80">Seu pedido</h2>
                            <p className="mt-2 whitespace-pre-line break-words text-[14px] leading-relaxed text-white/85">{c.descricao}</p>
                            {(c.contexto_tentando || c.contexto_aconteceu || c.contexto_esperado) && (
                                <dl className="mt-4 grid gap-4 border-t border-white/[0.06] pt-4 sm:grid-cols-3">
                                    <Contexto titulo="Estava tentando" texto={c.contexto_tentando} />
                                    <Contexto titulo="Aconteceu" texto={c.contexto_aconteceu} />
                                    <Contexto titulo="Esperava" texto={c.contexto_esperado} />
                                </dl>
                            )}
                            <ListaAnexos anexos={c.anexos} className="mt-4" />
                        </section>

                        <section aria-labelledby="conversa" className="space-y-3">
                            <h2 id="conversa" className="text-[13px] font-semibold text-white/80">Conversa</h2>
                            <LinhaDoTempo itens={c.linha_do_tempo} paraSolicitante />
                            {!fechado && <CaixaDeResposta chamadoId={c.id} placeholder="Mande uma informação a mais, um print ou uma resposta para a equipe…" />}
                        </section>
                    </div>

                    {/* ── Detalhes e próximo passo ── */}
                    <aside className="space-y-4 lg:sticky lg:top-6 lg:self-start">
                        <section
                            className={cn(
                                'rounded-xl border px-5 py-4',
                                c.status === 'aguardando_solicitante' ? 'border-orange-500/30 bg-orange-500/[0.05]' : 'border-white/[0.08] bg-ecf-card',
                            )}
                        >
                            <h2 className="flex items-center gap-2 text-[13.5px] font-semibold text-white">
                                <proximo.Icone size={16} className={proximo.tom} /> O que acontece agora
                            </h2>
                            <p className="mt-1.5 text-[13px] leading-relaxed text-white/70">{proximo.texto}</p>
                            {c.status === 'resolvido' && c.resolucao && (
                                <p className="mt-3 whitespace-pre-line rounded-lg bg-emerald-500/[0.06] px-3 py-2 text-[13px] text-white/80">{c.resolucao}</p>
                            )}
                            {c.status === 'resolvido' && <Reabrir chamadoId={c.id} />}
                        </section>

                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                            <dl className="space-y-3 text-[13px]">
                                <Dado rotulo="Com quem está">{c.responsavel?.name ?? <span className="text-white/45">Aguardando alguém da equipe</span>}</Dado>
                                <Dado rotulo="Área / Projeto">{c.area ?? '—'}</Dado>
                                <Dado rotulo="Tipo">{TIPOS[c.tipo]}</Dado>
                                <Dado rotulo="Impacto">{IMPACTOS[c.impacto]}</Dado>
                                <Dado rotulo="Aberto em">{fmtDataHora(c.criado_em)}</Dado>
                                <Dado rotulo="Última movimentação">
                                    <span className="inline-flex items-center gap-1.5"><Clock size={13} className="text-white/35" /> {haQuanto(c.ultima_interacao_em)}</span>
                                </Dado>
                            </dl>
                        </section>

                        {!fechado && <Cancelar chamadoId={c.id} />}
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}

function Dado({ rotulo, children }) {
    return (
        <div>
            <dt className="text-[11.5px] text-white/40">{rotulo}</dt>
            <dd className="mt-0.5 text-white/85">{children}</dd>
        </div>
    );
}

function Contexto({ titulo, texto }) {
    if (!texto) return <div />;
    return (
        <div>
            <dt className="text-[11.5px] text-white/40">{titulo}</dt>
            <dd className="mt-0.5 whitespace-pre-line break-words text-[13px] text-white/75">{texto}</dd>
        </div>
    );
}

function Reabrir({ chamadoId }) {
    const [aberto, setAberto] = useState(false);
    const [motivo, setMotivo] = useState('');

    if (!aberto) {
        return (
            <button type="button" onClick={() => setAberto(true)} className="mt-3 text-[12.5px] text-white/60 underline underline-offset-2 hover:text-white">
                O problema continua? Reabrir ticket
            </button>
        );
    }
    return (
        <div className="mt-3 space-y-2">
            <textarea
                rows={2}
                value={motivo}
                onChange={(e) => setMotivo(e.target.value)}
                placeholder="Conte o que ainda não está certo"
                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13px] text-white placeholder:text-white/30 focus:outline-none"
            />
            <button
                type="button"
                onClick={() => router.post(route('chamados.reabrir', chamadoId), { motivo }, { preserveScroll: true })}
                className="rounded-lg bg-white/[0.06] px-3 py-1.5 text-[12.5px] font-medium text-white hover:bg-white/10"
            >
                Reabrir ticket
            </button>
        </div>
    );
}

function Cancelar({ chamadoId }) {
    const cancelar = () => {
        if (window.confirm('Cancelar este ticket? A equipe deixa de trabalhar nele.')) {
            router.post(route('chamados.cancelar', chamadoId), {}, { preserveScroll: true });
        }
    };
    return (
        <button type="button" onClick={cancelar} className="w-full rounded-lg px-3 py-2 text-left text-[12.5px] text-white/40 hover:bg-white/[0.03] hover:text-red-400">
            Não preciso mais — cancelar ticket
        </button>
    );
}
