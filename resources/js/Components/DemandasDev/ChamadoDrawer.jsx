import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowRightLeft, CheckCircle2, GitBranchPlus, Loader2, UserCheck, XCircle } from 'lucide-react';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { cn } from '@/lib/utils';
import { encerrado, fmtDataHora, IMPACTOS, STATUS_CHAMADO, STATUS_MANUAIS, TIPOS } from '@/lib/chamados';
import { PRIORIDADE_LABELS } from '@/lib/demandasDev';
import { CaixaDeResposta, LinhaDoTempo, ListaAnexos, StatusChamado } from '@/Components/Chamados/Partes';

/**
 * Chamado aberto pela equipe dev. Tudo que muda o chamado vai ao servidor e vira
 * evento no histórico; a tela nunca muda estado sozinha.
 */
export default function ChamadoDrawer({ aberto, chamado: c, devs, eu, onClose, onCriarDemanda, onAbrirDemanda }) {
    const carregando = aberto && !c;
    const [painel, setPainel] = useState(null); // 'transferir' | 'resolver' | null
    const [enviando, setEnviando] = useState(false);

    const post = (rota, dados, depois) => {
        setEnviando(true);
        router.post(rota, dados, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (p) => { if (!p.props.flash?.error) depois?.(); },
            onFinish: () => setEnviando(false),
        });
    };

    return (
        <Sheet open={aberto} onOpenChange={(v) => { if (!v) { setPainel(null); onClose(); } }}>
            <SheetContent className="max-w-3xl">
                {carregando || !c ? (
                    <div className="flex items-center gap-2 p-6 text-[13px] text-white/50">
                        <Loader2 size={15} className="animate-spin" /> Carregando ticket…
                        <SheetTitle className="sr-only">Ticket</SheetTitle>
                        <SheetDescription className="sr-only">Carregando</SheetDescription>
                    </div>
                ) : (
                    <>
                        <SheetHeader>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-[12.5px] font-semibold tabular-nums text-white/50">{c.codigo}</span>
                                <StatusChamado status={c.status} />
                                {c.demanda && (
                                    <button
                                        type="button"
                                        onClick={() => onAbrirDemanda(c.demanda.id)}
                                        className="rounded bg-white/[0.05] px-1.5 py-0.5 text-[11.5px] text-white/70 hover:bg-ecf-yellow/10 hover:text-ecf-yellow"
                                    >
                                        Demanda relacionada: {c.demanda.codigo}
                                    </button>
                                )}
                            </div>
                            <SheetTitle className="mt-1.5 text-[18px] font-semibold leading-snug text-white">{c.titulo}</SheetTitle>
                            <SheetDescription className="mt-1 text-[12.5px] text-white/50">
                                {c.solicitante} — {TIPOS[c.tipo]}{c.area && <> — {c.area}</>} — aberto em {fmtDataHora(c.criado_em)}
                            </SheetDescription>
                        </SheetHeader>

                        <SheetBody className="space-y-6">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                                <Dado rotulo="Responsável">{c.responsavel?.name ?? <span className="text-white/40">Fila da equipe</span>}</Dado>
                                <Dado rotulo="Impacto relatado">
                                    {IMPACTOS[c.impacto]}
                                    {c.prioridade_sugerida != null && (
                                        <span className="mt-0.5 block text-[11.5px] text-white/40">sugere {PRIORIDADE_LABELS[c.prioridade_sugerida]}</span>
                                    )}
                                </Dado>
                                <Dado rotulo="Status">
                                    {encerrado(c.status) ? STATUS_CHAMADO[c.status] : (
                                        <select
                                            value={c.status}
                                            disabled={enviando}
                                            onChange={(e) => post(route('dev.demandas.chamados.status', c.id), { status: e.target.value })}
                                            className="w-full rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-1 text-[13px] text-white focus:outline-none"
                                            aria-label="Mudar status"
                                        >
                                            {STATUS_MANUAIS.map((s) => <option key={s} value={s}>{STATUS_CHAMADO[s]}</option>)}
                                        </select>
                                    )}
                                </Dado>
                            </dl>

                            {c.demanda?.concluida && !encerrado(c.status) && (
                                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-emerald-500/25 bg-emerald-500/[0.06] px-4 py-3 text-[13px] text-emerald-300">
                                    <CheckCircle2 size={16} className="shrink-0" />
                                    <span className="flex-1">A demanda relacionada {c.demanda.codigo} foi concluída. Deseja resolver o ticket?</span>
                                    <button type="button" onClick={() => setPainel('resolver')} className="rounded-md bg-emerald-500/15 px-3 py-1.5 font-medium hover:bg-emerald-500/25">
                                        Resolver ticket
                                    </button>
                                </div>
                            )}

                            {!encerrado(c.status) && (
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {c.responsavel?.id !== eu.id && devs.some((d) => d.id === eu.id) && (
                                        <Acao onClick={() => post(route('dev.demandas.chamados.transferir', c.id), { responsavel_id: eu.id, motivo: c.responsavel ? 'Assumido por quem vai atender.' : null })} disabled={enviando}>
                                            <UserCheck size={14} /> Assumir
                                        </Acao>
                                    )}
                                    <Acao ativo={painel === 'transferir'} onClick={() => setPainel(painel === 'transferir' ? null : 'transferir')}>
                                        <ArrowRightLeft size={14} /> Transferir ticket
                                    </Acao>
                                    <Acao ativo={painel === 'resolver'} onClick={() => setPainel(painel === 'resolver' ? null : 'resolver')}>
                                        <CheckCircle2 size={14} /> Resolver
                                    </Acao>
                                    {!c.demanda && c.pode?.converter && (
                                        <Acao onClick={() => onCriarDemanda(c)}>
                                            <GitBranchPlus size={14} /> Criar demanda a partir deste ticket
                                        </Acao>
                                    )}
                                    <Acao
                                        className="ml-auto text-white/40 hover:text-red-400"
                                        onClick={() => window.confirm(`Cancelar o ${c.codigo}? Quem abriu é avisado.`) && post(route('chamados.cancelar', c.id), {})}
                                    >
                                        <XCircle size={14} /> Cancelar
                                    </Acao>
                                </div>
                            )}

                            {painel === 'transferir' && (
                                <Transferir c={c} devs={devs} enviando={enviando} onEnviar={(dados) => post(route('dev.demandas.chamados.transferir', c.id), dados, () => setPainel(null))} />
                            )}
                            {painel === 'resolver' && (
                                <Resolver enviando={enviando} onEnviar={(resolucao) => post(route('dev.demandas.chamados.resolver', c.id), { resolucao }, () => setPainel(null))} />
                            )}

                            {encerrado(c.status) && c.status === 'resolvido' && (
                                <div className="rounded-lg border border-emerald-500/25 bg-emerald-500/[0.05] px-4 py-3">
                                    <div className="text-[13px] font-medium text-emerald-400">Resolvido em {fmtDataHora(c.resolvido_em)}</div>
                                    {c.resolucao && <p className="mt-1 whitespace-pre-line text-[13px] text-white/75">{c.resolucao}</p>}
                                    <button type="button" onClick={() => post(route('chamados.reabrir', c.id), {})} className="mt-2 text-[12.5px] text-white/60 underline underline-offset-2 hover:text-white">
                                        Reabrir ticket
                                    </button>
                                </div>
                            )}

                            <section>
                                <h3 className="mb-2 text-[12.5px] font-semibold text-white/70">Pedido original</h3>
                                <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] px-4 py-3">
                                    <p className="whitespace-pre-line break-words text-[13.5px] leading-relaxed text-white/85">{c.descricao}</p>
                                    <Contexto titulo="O que estava tentando fazer" texto={c.contexto_tentando} />
                                    <Contexto titulo="O que aconteceu" texto={c.contexto_aconteceu} />
                                    <Contexto titulo="O que esperava" texto={c.contexto_esperado} />
                                    <ListaAnexos anexos={c.anexos} className="mt-3" />
                                </div>
                            </section>

                            <section className="space-y-3">
                                <h3 className="text-[12.5px] font-semibold text-white/70">Conversa e histórico</h3>
                                <LinhaDoTempo itens={c.linha_do_tempo} />
                                <CaixaDeResposta chamadoId={c.id} podeInterna placeholder="Responda a quem abriu — ex.: consegue me mandar um print do erro?" />
                            </section>
                        </SheetBody>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function Transferir({ c, devs, enviando, onEnviar }) {
    const opcoes = devs.filter((d) => d.id !== c.responsavel?.id);
    const [para, setPara] = useState(opcoes[0] ? String(opcoes[0].id) : '');
    const [motivo, setMotivo] = useState('');
    const exigeMotivo = !!c.responsavel;

    return (
        <div className="space-y-2.5 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
            <div className="grid gap-2.5 sm:grid-cols-[200px_1fr]">
                <label className="space-y-1">
                    <span className="text-[12px] text-white/60">Novo responsável</span>
                    <select value={para} onChange={(e) => setPara(e.target.value)} className="w-full rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-1.5 text-[13px] text-white focus:outline-none">
                        {opcoes.map((d) => <option key={d.id} value={String(d.id)}>{d.name}</option>)}
                    </select>
                </label>
                <label className="space-y-1">
                    <span className="text-[12px] text-white/60">Motivo da transferência{exigeMotivo ? '' : ' (opcional)'}</span>
                    <input value={motivo} onChange={(e) => setMotivo(e.target.value)} placeholder="Ex.: essa área está com o João" className="w-full rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-1.5 text-[13px] text-white placeholder:text-white/30 focus:outline-none" />
                </label>
            </div>
            <div className="flex justify-end">
                <button
                    type="button"
                    disabled={enviando || !para || (exigeMotivo && !motivo.trim())}
                    onClick={() => onEnviar({ responsavel_id: Number(para), motivo })}
                    className="rounded-lg bg-ecf-yellow px-3.5 py-1.5 text-[12.5px] font-semibold text-black hover:bg-ecf-yellow-2 disabled:opacity-50"
                >
                    Transferir
                </button>
            </div>
        </div>
    );
}

function Resolver({ enviando, onEnviar }) {
    const [texto, setTexto] = useState('');
    return (
        <div className="space-y-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/[0.03] p-3">
            <label className="block space-y-1">
                <span className="text-[12px] text-white/60">O que foi feito (vai para quem abriu)</span>
                <textarea rows={3} value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Ex.: corrigimos o cadastro, pode testar de novo." className="w-full rounded-md border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13px] text-white placeholder:text-white/30 focus:outline-none" />
            </label>
            <div className="flex justify-end">
                <button type="button" disabled={enviando || !texto.trim()} onClick={() => onEnviar(texto)} className="rounded-lg bg-emerald-500/20 px-3.5 py-1.5 text-[12.5px] font-semibold text-emerald-300 hover:bg-emerald-500/30 disabled:opacity-50">
                    Resolver ticket
                </button>
            </div>
        </div>
    );
}

function Acao({ ativo, className, children, ...props }) {
    return (
        <button
            type="button"
            {...props}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12.5px] transition-colors disabled:opacity-50',
                ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/10 text-white' : 'border-white/[0.08] text-white/70 hover:text-white',
                className,
            )}
        >
            {children}
        </button>
    );
}

function Dado({ rotulo, children }) {
    return (
        <div>
            <dt className="text-[11.5px] text-white/40">{rotulo}</dt>
            <dd className="mt-0.5 text-[13px] text-white/85">{children}</dd>
        </div>
    );
}

function Contexto({ titulo, texto }) {
    if (!texto) return null;
    return (
        <div className="mt-2.5">
            <div className="text-[11.5px] text-white/40">{titulo}</div>
            <p className="whitespace-pre-line break-words text-[13px] text-white/75">{texto}</p>
        </div>
    );
}
