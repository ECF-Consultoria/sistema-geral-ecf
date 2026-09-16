import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import {
    ArrowUpRight, Building2, CalendarDays, CircleCheck, CircleDashed, CircleHelp, CircleX, Clock, ExternalLink,
    Info, Loader2, MapPin, Pencil, Repeat, Send, Trash2, UserRound, Video,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle,
} from '@/Components/ui/sheet';
import { PLATAFORMAS, TIPOS, corDoEvento, enviarJson, faixaDeHorario, primeiraMaiuscula } from './agenda';

const RESPOSTAS = {
    accepted:    { rotulo: 'Confirmou',   icone: CircleCheck,  cor: 'text-emerald-400' },
    declined:    { rotulo: 'Recusou',     icone: CircleX,      cor: 'text-rose-400' },
    tentative:   { rotulo: 'Talvez',      icone: CircleHelp,   cor: 'text-amber-300' },
    needsAction: { rotulo: 'Sem resposta', icone: CircleDashed, cor: 'text-white/30' },
};

const LADOS = { cliente: 'Cliente', ecf: 'ECF' };

/**
 * O detalhe de um compromisso, num drawer.
 *
 * O que dá para fazer depende de onde o evento veio (`evento.edicao`):
 * - `vinculo`: evento que o sistema criou para um onboarding — edita e cancela
 *   pela agenda de quem organizou;
 * - `google`: evento da própria pessoa, organizado por ela — edita e cancela
 *   direto no Google dela;
 * - `kickoff`: reunião de onboarding marcada sem convite — o que falta é
 *   enviar o convite;
 * - nada: só leitura, com o atalho para abrir no Google Agenda.
 */
export default function EventoDetalhe({ evento, aoFechar, aoEditar, aoEnviarConvite, aoMudou, contextoOnboardingId = null }) {
    const [confirmando, setConfirmando] = useState(false);
    const [cancelando, setCancelando] = useState(false);
    const [erro, setErro] = useState(null);

    useEffect(() => {
        setConfirmando(false);
        setErro(null);
    }, [evento?.id]);

    const aberto = Boolean(evento);
    const cor = evento ? corDoEvento(evento) : null;
    const plataforma = evento ? PLATAFORMAS[evento.plataforma] : null;
    const inicio = evento ? new Date(evento.inicio) : null;
    const participantes = (evento?.participantes ?? []).filter((p) => ! p.organizador);
    const podeEditar = evento?.edicao === 'vinculo' || evento?.edicao === 'google';

    const cancelar = async () => {
        setCancelando(true);
        setErro(null);
        const url = evento.edicao === 'vinculo'
            ? route('agenda.eventos.destroy', evento.vinculo.evento_id)
            : route('agenda.google.destroy', evento.google_event_id);
        const resultado = await enviarJson('delete', url);
        setCancelando(false);

        if (! resultado.ok) {
            setErro(resultado.mensagem);

            return;
        }

        aoMudou?.(resultado.mensagem);
        aoFechar();
    };

    return (
        <Sheet open={aberto} onOpenChange={(v) => ! v && aoFechar()}>
            <SheetContent className="max-w-lg">
                {evento && (
                    <>
                        <SheetHeader>
                            <div className="mb-2 flex flex-wrap items-center gap-1.5">
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-white/[0.08] bg-white/[0.03] px-2 py-0.5 text-[11px] text-white/65">
                                    <span className={cn('h-2 w-2 rounded-full', cor.ponto)} />
                                    {TIPOS[evento.tipo]?.rotulo ?? 'Google Agenda'}
                                </span>
                                {evento.recorrente && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-white/[0.05] px-2 py-0.5 text-[11px] text-white/55">
                                        <Repeat size={11} /> Se repete
                                    </span>
                                )}
                                {evento.sua_resposta === 'declined' && (
                                    <span className="rounded-full bg-rose-500/15 px-2 py-0.5 text-[11px] text-rose-300">Você recusou</span>
                                )}
                                {evento.vinculo?.sem_convite && (
                                    <span className="rounded-full bg-amber-400/15 px-2 py-0.5 text-[11px] text-amber-300">Sem convite no Google</span>
                                )}
                            </div>
                            <SheetTitle className="pr-2 leading-snug">{evento.titulo}</SheetTitle>
                            <SheetDescription className="flex items-center gap-1.5">
                                <CalendarDays size={13} />
                                {primeiraMaiuscula(format(inicio, "EEEE, d 'de' MMMM 'de' yyyy", { locale: ptBR }))}
                            </SheetDescription>
                        </SheetHeader>

                        <SheetBody className="space-y-5">
                            <Linha icone={Clock}>
                                <span className="tabular-nums">{faixaDeHorario(evento)}</span>
                            </Linha>

                            {evento.vinculo && (
                                <Linha icone={Building2}>
                                    <span className="block text-white/85">{evento.vinculo.empresa}</span>
                                    <span className="block text-[11.5px] text-white/40">
                                        Onboarding #{evento.vinculo.onboarding_id}
                                        {evento.vinculo.servico ? ` · ${evento.vinculo.servico}` : ''}
                                    </span>
                                    {contextoOnboardingId !== evento.vinculo.onboarding_id && (
                                        <Link
                                            href={evento.vinculo.url}
                                            className="mt-1 inline-flex items-center gap-1 text-[12px] text-ecf-yellow/85 hover:text-ecf-yellow"
                                        >
                                            Abrir a ficha do onboarding <ArrowUpRight size={12} />
                                        </Link>
                                    )}
                                </Linha>
                            )}

                            {(plataforma || evento.local) && (
                                <Linha icone={evento.plataforma === 'presencial' ? MapPin : Video}>
                                    <span className="block text-white/85">{plataforma?.rotulo ?? 'Local'}</span>
                                    {/* No Teams colado no "local", local e link são a mesma URL. */}
                                    {evento.local && evento.local !== evento.link && (
                                        <span className="block break-words text-[11.5px] text-white/45">{evento.local}</span>
                                    )}
                                    {evento.link && (
                                        <a
                                            href={evento.link}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-1.5 text-[12.5px] font-semibold text-ecf-bg transition-colors hover:bg-ecf-yellow/90"
                                        >
                                            <Video size={14} /> Entrar na reunião
                                        </a>
                                    )}
                                    {evento.link && (
                                        <span className="mt-1 block truncate text-[11px] text-white/30" title={evento.link}>{evento.link}</span>
                                    )}
                                </Linha>
                            )}

                            {evento.plataforma === null && ! evento.vinculo?.sem_convite && (
                                <Linha icone={Video}>
                                    <span className="text-white/40">Sem link de reunião.</span>
                                </Linha>
                            )}

                            <Linha icone={UserRound}>
                                <span className="block text-[11.5px] text-white/40">Organizador</span>
                                <span className="block text-white/85">
                                    {evento.organizador?.voce
                                        ? 'Você'
                                        : evento.organizador?.nome ?? evento.organizador?.email ?? '—'}
                                </span>
                            </Linha>

                            {participantes.length > 0 && (
                                <div>
                                    <p className="mb-2 text-[11.5px] font-semibold uppercase tracking-wide text-white/35">
                                        Participantes · {participantes.length}
                                    </p>
                                    <ul className="space-y-1.5">
                                        {participantes.map((p) => {
                                            const resposta = p.resposta ? RESPOSTAS[p.resposta] ?? RESPOSTAS.needsAction : null;
                                            const Icone = resposta?.icone;

                                            return (
                                                <li key={p.email} className="flex items-center gap-2.5">
                                                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white/[0.06] text-[11px] font-semibold uppercase text-white/60">
                                                        {(p.nome || p.email || '?').slice(0, 1)}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-[12.5px] text-white/85">
                                                            {p.nome || p.email}
                                                            {p.voce && <span className="text-white/40"> (você)</span>}
                                                        </span>
                                                        {p.nome && <span className="block truncate text-[11px] text-white/35">{p.email}</span>}
                                                    </span>
                                                    {p.lado && LADOS[p.lado] && (
                                                        <span className="rounded bg-white/[0.05] px-1.5 py-0.5 text-[10px] text-white/45">{LADOS[p.lado]}</span>
                                                    )}
                                                    {resposta && (
                                                        <span className={cn('inline-flex items-center gap-1 text-[11px]', resposta.cor)} title={resposta.rotulo}>
                                                            <Icone size={14} />
                                                        </span>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    {evento.origem === 'sistema' && (
                                        <p className="mt-2 text-[11px] text-white/30">
                                            As respostas aparecem para quem tem o evento no próprio Google Agenda.
                                        </p>
                                    )}
                                </div>
                            )}

                            {evento.descricao && (
                                <div>
                                    <p className="mb-1.5 text-[11.5px] font-semibold uppercase tracking-wide text-white/35">Descrição</p>
                                    <p className="whitespace-pre-wrap break-words text-[12.5px] leading-relaxed text-white/70">{evento.descricao}</p>
                                </div>
                            )}

                            {evento.edicao === null && evento.vinculo && evento.tipo === 'recorrente' && (
                                <Aviso>A rotina de reuniões se ajusta em "Rotina de reuniões", na ficha do onboarding.</Aviso>
                            )}
                            {evento.edicao === null && ! evento.vinculo && evento.recorrente && (
                                <Aviso>Evento que se repete se ajusta no Google Agenda, onde dá para escolher quais datas mudam.</Aviso>
                            )}
                            {evento.edicao === null && ! evento.vinculo && ! evento.recorrente && evento.organizador && ! evento.organizador.voce && (
                                <Aviso>Você foi convidado para este evento. Quem muda horário e convidados é quem organizou.</Aviso>
                            )}

                            {erro && (
                                <p className="rounded-lg border border-rose-400/25 bg-rose-500/10 px-3 py-2 text-[12px] text-rose-200">{erro}</p>
                            )}
                        </SheetBody>

                        <SheetFooter>
                            {confirmando ? (
                                <div className="space-y-3">
                                    <p className="text-[12.5px] text-white/75">
                                        Cancelar este evento?
                                        {participantes.length > 0 && ' O Google avisa os convidados por e-mail na hora.'}
                                        {evento.tipo === 'kickoff' && ' A data da reunião continua marcada no onboarding.'}
                                    </p>
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setConfirmando(false)}
                                            disabled={cancelando}
                                            className="rounded-lg px-3 py-2 text-[12.5px] text-white/60 hover:text-white"
                                        >
                                            Voltar
                                        </button>
                                        <button
                                            type="button"
                                            onClick={cancelar}
                                            disabled={cancelando}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-rose-500 px-3 py-2 text-[12.5px] font-semibold text-white hover:bg-rose-500/90 disabled:opacity-60"
                                        >
                                            {cancelando && <Loader2 size={14} className="animate-spin" />}
                                            Sim, cancelar evento
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        {evento.html_link && (
                                            <a
                                                href={evento.html_link}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.08] px-3 py-2 text-[12.5px] text-white/65 transition-colors hover:border-white/20 hover:text-white"
                                            >
                                                <ExternalLink size={13} /> Abrir no Google
                                            </a>
                                        )}
                                        {podeEditar && (
                                            <button
                                                type="button"
                                                onClick={() => setConfirmando(true)}
                                                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-[12.5px] text-rose-300/85 transition-colors hover:bg-rose-500/10 hover:text-rose-200"
                                            >
                                                <Trash2 size={13} /> Cancelar evento
                                            </button>
                                        )}
                                    </div>
                                    {podeEditar && (
                                        <button
                                            type="button"
                                            onClick={() => aoEditar?.(evento)}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[12.5px] font-semibold text-ecf-bg transition-colors hover:bg-ecf-yellow/90"
                                        >
                                            <Pencil size={13} /> Editar
                                        </button>
                                    )}
                                    {evento.edicao === 'kickoff' && (
                                        <button
                                            type="button"
                                            onClick={() => aoEnviarConvite?.(evento)}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[12.5px] font-semibold text-ecf-bg transition-colors hover:bg-ecf-yellow/90"
                                        >
                                            <Send size={13} /> Enviar convite pelo Google
                                        </button>
                                    )}
                                </div>
                            )}
                        </SheetFooter>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function Linha({ icone: Icone, children }) {
    return (
        <div className="flex items-start gap-3 text-[13px]">
            <Icone size={16} className="mt-0.5 shrink-0 text-white/35" />
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}

function Aviso({ children }) {
    return (
        <p className="flex items-start gap-2 rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2 text-[12px] text-white/50">
            <Info size={13} className="mt-0.5 shrink-0" /> {children}
        </p>
    );
}
