import { CalendarClock, CalendarPlus, Loader2, Lock, PenLine, Pencil, Video } from 'lucide-react';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { cn } from '@/lib/utils';
import { fmtData, textoPrazo } from '@/lib/demandasDev';
import { PrioridadeSelo, SituacaoSelo, StatusSelo } from './Selos';

// Painel lateral de uma demanda: dados, próxima ação e o diário completo.
export default function DemandaDrawer({ demanda, detalhe, eu, pode, hoje, onClose, onAtualizar, onEditar, onAgendarReuniao, onAbrirChamado }) {
    const carregando = !detalhe || detalhe.id !== demanda?.id;
    const podeAtualizar = demanda && (pode.gerenciar || demanda.responsavel?.id === eu.id);

    return (
        <Sheet open={!!demanda} onOpenChange={(v) => !v && onClose()}>
            {demanda && (
                <SheetContent className="max-w-2xl">
                    <SheetHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-[12px] text-white/50">{demanda.codigo}</span>
                            <PrioridadeSelo prioridade={demanda.prioridade} longo />
                            <SituacaoSelo situacao={demanda.situacao} />
                            {demanda.chamado && (
                                onAbrirChamado ? (
                                    <button type="button" onClick={() => onAbrirChamado(demanda.chamado.id)} className="rounded bg-white/[0.05] px-1.5 py-0.5 text-[11.5px] text-white/70 hover:bg-ecf-yellow/10 hover:text-ecf-yellow">
                                        Origem: chamado {demanda.chamado.codigo}
                                    </button>
                                ) : (
                                    <span className="rounded bg-white/[0.05] px-1.5 py-0.5 text-[11.5px] text-white/60">Origem: chamado {demanda.chamado.codigo}</span>
                                )
                            )}
                        </div>
                        <SheetTitle className="mt-1.5 text-[18px] font-semibold leading-snug text-white">{demanda.titulo}</SheetTitle>
                        <SheetDescription className="sr-only">Detalhes e histórico da demanda</SheetDescription>
                    </SheetHeader>

                    <SheetBody className="space-y-6">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                            <Dado rotulo="Status"><StatusSelo status={demanda.status} /></Dado>
                            <Dado rotulo="Responsável">{demanda.responsavel?.name ?? <span className="text-white/30">Sem responsável</span>}</Dado>
                            <Dado rotulo="Área">{demanda.area ?? '—'}</Dado>
                            <Dado rotulo="Entrada">{fmtData(demanda.data_entrada, hoje)}</Dado>
                            <Dado rotulo="Prazo">
                                {demanda.prazo ? fmtData(demanda.prazo, hoje) : 'Sem prazo'}
                                {demanda.prazo && !demanda.encerrada && (
                                    <span className={cn('ml-1.5 text-[11.5px]', demanda.situacao === 'atrasada' ? 'text-red-400' : 'text-white/40')}>
                                        ({textoPrazo(demanda, hoje)})
                                    </span>
                                )}
                            </Dado>
                            <Dado rotulo="Última atualização">{demanda.ultima_atualizacao ? fmtData(demanda.ultima_atualizacao, hoje) : 'nunca'}</Dado>
                        </dl>

                        <div className={cn('rounded-lg border px-4 py-3', demanda.bloqueado ? 'border-orange-500/25 bg-orange-500/[0.06]' : 'border-white/[0.06] bg-white/[0.02]')}>
                            {demanda.bloqueado && (
                                <div className="mb-1.5 flex items-start gap-1.5 text-[13px] text-orange-400">
                                    <Lock size={14} className="mt-0.5 shrink-0" />
                                    <span><strong className="font-semibold">Bloqueada:</strong> {demanda.motivo_bloqueio || 'sem motivo registrado'}</span>
                                </div>
                            )}
                            <div className="text-[11px] font-semibold uppercase tracking-wider text-white/40">Próxima ação</div>
                            <div className={cn('mt-0.5 text-[13.5px]', demanda.proxima_acao ? 'text-white/90' : 'italic text-white/40')}>
                                {demanda.proxima_acao || 'Registrar 1ª atualização'}
                            </div>
                        </div>

                        {demanda.escopo && (
                            <Secao titulo="Escopo / critério de conclusão">
                                <p className="whitespace-pre-line text-[13px] leading-relaxed text-white/70">{demanda.escopo}</p>
                            </Secao>
                        )}
                        {demanda.observacoes && (
                            <Secao titulo="Observações">
                                <p className="whitespace-pre-line text-[12.5px] leading-relaxed text-white/60">{demanda.observacoes}</p>
                            </Secao>
                        )}

                        {!carregando && detalhe.reunioes.length > 0 && (
                            <Secao titulo="Reuniões relacionadas">
                                <ul className="space-y-1">
                                    {detalhe.reunioes.map((r) => (
                                        <li key={r.id} className="flex items-center gap-2 text-[12.5px] text-white/70">
                                            <Video size={13} className="text-white/30" />
                                            <span className="font-mono text-white/40">{fmtData(r.data, hoje)}</span>
                                            {r.titulo}
                                        </li>
                                    ))}
                                </ul>
                            </Secao>
                        )}

                        <Secao titulo={`Histórico${!carregando ? ` · ${detalhe.atualizacoes.length}` : ''}`}>
                            {carregando ? (
                                <div className="flex items-center gap-2 py-4 text-[12.5px] text-white/40"><Loader2 size={14} className="animate-spin" /> Carregando…</div>
                            ) : detalhe.atualizacoes.length === 0 ? (
                                <p className="text-[12.5px] text-white/40">Nenhuma atualização ainda — a demanda está em Backlog.</p>
                            ) : (
                                <ol className="relative space-y-4 border-l border-white/[0.08] pl-5">
                                    {detalhe.atualizacoes.map((a, i) => (
                                        <li key={a.id} className="relative">
                                            <span className={cn(
                                                'absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-ecf-card',
                                                i === 0 ? 'bg-ecf-yellow' : 'bg-white/20',
                                            )} />
                                            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                <span className="font-mono text-[12px] text-white/60">{fmtData(a.data, hoje)}</span>
                                                <span className="text-[12.5px] text-white/80">{a.autor ?? '—'}</span>
                                                <StatusSelo status={a.status} className="text-[12px]" />
                                                {a.bloqueado && <span className="inline-flex items-center gap-1 text-[11.5px] text-orange-400"><Lock size={11} /> bloqueada</span>}
                                            </div>
                                            {a.feito && <p className="mt-1 whitespace-pre-line text-[13px] leading-relaxed text-white/70">{a.feito}</p>}
                                            {a.proxima_acao && (
                                                <p className="mt-1 text-[12.5px] text-white/50"><span className="text-white/30">Próxima:</span> {a.proxima_acao}</p>
                                            )}
                                            {a.bloqueado && a.motivo_bloqueio && (
                                                <p className="mt-0.5 text-[12.5px] text-orange-400/90"><span className="text-orange-400/60">Motivo:</span> {a.motivo_bloqueio}</p>
                                            )}
                                            {a.previsao_revisada && (
                                                <p className="mt-0.5 inline-flex items-center gap-1 text-[12px] text-white/50">
                                                    <CalendarClock size={12} /> Previsão revisada: {fmtData(a.previsao_revisada, hoje)}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Secao>
                    </SheetBody>

                    {(podeAtualizar || pode.gerenciar) && (
                        <SheetFooter className="flex items-center justify-end gap-2">
                            {onAgendarReuniao && (
                                <button type="button" onClick={() => onAgendarReuniao(demanda)} className="mr-auto inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-[13px] text-white/60 hover:bg-white/[0.04] hover:text-white">
                                    <CalendarPlus size={14} /> Agendar reunião
                                </button>
                            )}
                            {pode.gerenciar && (
                                <button type="button" onClick={() => onEditar(demanda)} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-[13px] text-white/60 hover:bg-white/[0.04] hover:text-white">
                                    <Pencil size={14} /> Editar
                                </button>
                            )}
                            {podeAtualizar && (
                                <button type="button" onClick={() => onAtualizar(demanda)} className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-4 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2">
                                    <PenLine size={14} /> Registrar atualização
                                </button>
                            )}
                        </SheetFooter>
                    )}
                </SheetContent>
            )}
        </Sheet>
    );
}

function Dado({ rotulo, children }) {
    return (
        <div>
            <dt className="text-[11px] uppercase tracking-wider text-white/40">{rotulo}</dt>
            <dd className="mt-0.5 text-[13px] text-white/80">{children}</dd>
        </div>
    );
}

function Secao({ titulo, children }) {
    return (
        <section>
            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/40">{titulo}</h3>
            {children}
        </section>
    );
}
