import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { format, startOfMonth } from 'date-fns';
import {
    CalendarClock, CheckCircle2, Info, ListChecks, Loader2, Maximize2, Plus, RotateCw,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import MiniCalendario from '@/Components/Agenda/MiniCalendario';
import ProximosEventos from '@/Components/Agenda/ProximosEventos';
import EventoDetalhe from '@/Components/Agenda/EventoDetalhe';
import EventoFormulario from '@/Components/Agenda/EventoFormulario';
import useJson from '@/Components/Agenda/useJson';
import { marcasPorDia, ymd } from '@/Components/Agenda/agenda';

const DIAS = {
    1: 'segunda', 2: 'terça', 3: 'quarta', 4: 'quinta', 5: 'sexta', 6: 'sábado', 7: 'domingo',
};

/**
 * A Agenda da ficha do onboarding (16/09/2026): a visão rápida.
 *
 * ### O que ela é
 * Um mês em miniatura com os dias marcados, os três próximos compromissos
 * deste onboarding e a rotina combinada. Mora embaixo do checklist, na coluna
 * larga (17/09/2026): calendário e compromissos lado a lado ocupam o vazio que
 * ficava ali, e a coluna da direita fica só com o resumo e o diagnóstico.
 *
 * ### De onde vêm os eventos
 * Do que o sistema criou para ESTE onboarding — sem ler a agenda de ninguém a
 * cada visita. O servidor confere contra o Google o que estiver velho (evento
 * movido ou apagado por lá) antes de responder.
 *
 * ### O que se faz daqui
 * - "Agendar" abre o drawer de criação, sem sair da ficha;
 * - o título, o ícone de expandir e "Ver todos" abrem a Agenda completa, já
 *   filtrada por este onboarding;
 * - um dia do calendário abre a Agenda completa naquele dia;
 * - um evento abre o detalhe, com editar e cancelar.
 *
 * Depois de criar, editar ou cancelar, o cartão recarrega — e a ficha também,
 * porque a reunião de onboarding mexe na data que o checklist e o portal leem.
 */
export default function AgendaCompacta({ onboarding, reuniao, rotina, aoAjustarRotina }) {
    const { auth } = usePage().props;
    const [mes, setMes] = useState(() => startOfMonth(new Date()));
    const [pedido, setPedido] = useState(null);
    const [aberto, setAberto] = useState(null);
    const [aviso, setAviso] = useState(null);

    const { dados, erro, carregando, recarregar } = useJson(
        route('onboarding.agenda.eventos', { onboarding: onboarding.id, mes: format(mes, 'yyyy-MM') }),
    );

    useEffect(() => {
        if (! aviso) return undefined;
        const t = setTimeout(() => setAviso(null), 6000);

        return () => clearTimeout(t);
    }, [aviso]);

    const marcas = useMemo(() => marcasPorDia(dados?.eventos ?? []), [dados]);
    const organizadores = dados?.organizadores ?? [];
    const ninguemConectado = dados && organizadores.length > 0 && organizadores.every((o) => ! o.conectado);
    const euSemGoogle = organizadores.find((o) => o.e_voce && ! o.conectado);
    const urlCompleta = route('agenda.index', { onboarding: onboarding.id });

    const mudou = (mensagem) => {
        setAviso(mensagem);
        recarregar();
        router.reload({
            only: ['reuniao', 'agenda_google', 'passos', 'proxima_acao', 'linha_do_tempo', 'atividade'],
            preserveScroll: true,
        });
    };

    const agendar = (extra = {}) => setPedido({
        modo: 'criar',
        // Sem data marcada, a primeira coisa a fazer é a reunião de onboarding.
        tipo: reuniao?.agendada_para ? 'mapeamento' : 'kickoff',
        ...extra,
    });

    const textoRotina = rotina?.dia_semana && rotina?.horario
        ? `${rotina.periodicidade ? rotina.periodicidade.charAt(0).toUpperCase() + rotina.periodicidade.slice(1) : 'Rotina'}`
          + ` · ${DIAS[rotina.dia_semana]} às ${String(rotina.horario).slice(0, 5)}`
        : null;

    const semEventos = Boolean(dados) && (dados.proximos ?? []).length === 0;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5">
            <header className="mb-4 flex items-center justify-between gap-3">
                <Link
                    href={urlCompleta}
                    className="group flex min-w-0 items-center gap-2.5"
                    title="Abrir a Agenda completa"
                >
                    <CalendarClock size={18} className="shrink-0 text-ecf-yellow" />
                    <h2 className="font-display text-[15px] font-bold text-white">Agenda</h2>
                    <Maximize2 size={13} className="text-white/35 transition-colors group-hover:text-ecf-yellow" />
                </Link>

                <button
                    type="button"
                    onClick={() => agendar()}
                    disabled={dados && ! dados.pode_agendar}
                    title={dados && ! dados.pode_agendar ? 'O onboarding precisa estar em andamento' : undefined}
                    className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-ecf-yellow px-2.5 py-1 text-[11.5px] font-semibold text-ecf-bg transition-colors hover:bg-ecf-yellow/90 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Agendar <Plus size={12} strokeWidth={2.5} />
                </button>
            </header>

            {aviso && (
                <p className="mb-3 flex items-start gap-1.5 rounded-lg border border-emerald-400/20 bg-emerald-500/[0.08] px-2.5 py-2 text-[11.5px] text-emerald-200">
                    <CheckCircle2 size={12} className="mt-0.5 shrink-0" /> {aviso}
                </p>
            )}

            {/* Calendário à esquerda, compromissos e rotina à direita; em tela
                estreita, um embaixo do outro. */}
            <div className="grid gap-4 md:grid-cols-2 md:gap-0">
                <MiniCalendario
                    mes={mes}
                    aoMudarMes={setMes}
                    marcas={marcas}
                    className="md:pr-6"
                    aoSelecionar={(dia) => router.visit(route('agenda.index', {
                        onboarding: onboarding.id,
                        data: ymd(dia),
                        visao: 'dia',
                    }))}
                />

                <div className="flex min-w-0 flex-col border-t border-white/[0.06] pt-4 md:border-l md:border-t-0 md:pl-6 md:pt-0">
                    <div className="mb-2 flex min-h-7 items-center justify-between gap-2">
                        <p className="text-[13px] font-semibold text-white/85">Próximos eventos</p>
                        <div className="flex items-center gap-2">
                            {carregando && dados && <Loader2 size={12} className="animate-spin text-white/35" />}
                            <Link
                                href={route('agenda.index', { onboarding: onboarding.id, visao: 'lista' })}
                                className="text-[11.5px] font-medium text-ecf-yellow/85 hover:text-ecf-yellow"
                            >
                                Ver todos{dados?.total_proximos > 3 ? ` (${dados.total_proximos})` : ''}
                            </Link>
                        </div>
                    </div>

                    {/* Sem compromisso, o aviso fica no meio do espaço — e não
                        colado no título com um buraco embaixo. */}
                    <div className={cn('flex-1', semEventos && 'flex flex-col justify-center')}>
                        {! dados && carregando && (
                            <div className="space-y-2.5 py-1">
                                {[0, 1, 2].map((i) => (
                                    <div key={i} className="flex gap-2.5">
                                        <span className="h-9 w-[3px] animate-pulse rounded-full bg-white/10" />
                                        <span className="flex-1 space-y-1.5">
                                            <span className="block h-2.5 w-16 animate-pulse rounded bg-white/[0.07]" />
                                            <span className="block h-3 w-3/4 animate-pulse rounded bg-white/[0.07]" />
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}

                        {erro && (
                            <div className="flex items-center justify-between gap-2 rounded-lg bg-rose-500/[0.08] px-2.5 py-2 text-[11.5px] text-rose-200">
                                <span>{erro}</span>
                                <button type="button" onClick={recarregar} className="shrink-0 text-rose-100 hover:text-white" aria-label="Tentar de novo">
                                    <RotateCw size={12} />
                                </button>
                            </div>
                        )}

                        {dados && (
                            <ProximosEventos
                                eventos={dados.proximos}
                                limite={3}
                                mostrarEmpresa={false}
                                aoAbrir={setAberto}
                                className={semEventos ? 'py-6 text-[12.5px] text-white/50' : undefined}
                                vazio={reuniao?.agendada_para ? 'Nenhum evento pela frente.' : 'Nenhum evento marcado. Comece pela reunião de onboarding.'}
                            />
                        )}
                    </div>

                    {/* A rotina e o estado da reunião — o que o cartão antigo mostrava. */}
                    <div className="mt-3 space-y-1.5 border-t border-white/[0.06] pt-3 text-[12px]">
                        <div className="flex items-center justify-between gap-2">
                            <span className="flex min-w-0 items-center gap-2 text-white/55">
                                <ListChecks size={14} className="shrink-0" />
                                <span className="truncate">{textoRotina ?? 'Rotina de reuniões não combinada'}</span>
                            </span>
                            <button
                                type="button"
                                onClick={aoAjustarRotina}
                                className="shrink-0 text-white/45 transition-colors hover:text-white"
                            >
                                {textoRotina ? 'Ajustar' : 'Combinar'}
                            </button>
                        </div>
                        {reuniao?.realizada && (
                            <p className="flex items-center gap-1.5 text-emerald-300/85">
                                <CheckCircle2 size={12} /> Reunião de onboarding realizada
                            </p>
                        )}
                        {reuniao?.status === 'solicitada' && ! reuniao?.agendada_para && (
                            <p className="flex items-center gap-1.5 text-amber-300/90">
                                <Info size={12} /> O cliente pediu a reunião — falta marcar a data.
                            </p>
                        )}
                        {ninguemConectado && (
                            <p className="flex items-start gap-1.5 text-amber-300/85">
                                <Info size={12} className="mt-0.5 shrink-0" />
                                <span>
                                    Ninguém deste onboarding conectou o Google Agenda — os eventos não viram convite.
                                    {euSemGoogle && (
                                        <>
                                            {' '}
                                            <a
                                                href={route('google.connect', { retorno: window.location.pathname })}
                                                className="font-semibold text-ecf-yellow hover:underline"
                                            >
                                                Conectar o meu
                                            </a>
                                        </>
                                    )}
                                </span>
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <EventoDetalhe
                evento={aberto}
                aoFechar={() => setAberto(null)}
                aoMudou={mudou}
                contextoOnboardingId={onboarding.id}
                aoEditar={(evento) => {
                    setAberto(null);
                    setPedido({ modo: 'editar', evento });
                }}
                aoEnviarConvite={(evento) => {
                    setAberto(null);
                    agendar({ tipo: 'kickoff', inicio: new Date(evento.inicio) });
                }}
            />

            <EventoFormulario
                pedido={pedido}
                aoFechar={() => setPedido(null)}
                aoSalvar={mudou}
                onboardingFixo={{ id: onboarding.id, empresa: onboarding.empresa.nome }}
                usuario={{ id: auth?.user?.id, nome: auth?.user?.name, email: auth?.user?.email }}
                usuarioConectado={Boolean(organizadores.find((o) => o.e_voce)?.conectado ?? dados?.voce_conectado)}
            />
        </section>
    );
}
