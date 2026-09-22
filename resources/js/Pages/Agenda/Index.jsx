import { useEffect, useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { addDays, addMonths, addWeeks, isSameMonth, startOfMonth } from 'date-fns';
import {
    ArrowUpRight, Building2, CalendarDays, Check, CheckCircle2, ChevronLeft, ChevronRight, Info, Loader2, Plus,
    RotateCw, X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import GradeDeHorarios from '@/Components/Agenda/GradeDeHorarios';
import MiniCalendario from '@/Components/Agenda/MiniCalendario';
import ProximosEventos from '@/Components/Agenda/ProximosEventos';
import VisaoLista from '@/Components/Agenda/VisaoLista';
import VisaoMes from '@/Components/Agenda/VisaoMes';
import EventoDetalhe from '@/Components/Agenda/EventoDetalhe';
import EventoFormulario from '@/Components/Agenda/EventoFormulario';
import useJson from '@/Components/Agenda/useJson';
import {
    CORES, ORIGEM_GOOGLE, TIPOS, categoriaDoEvento, deYmd, inicioDaSemana, intervaloDaVisao, marcasPorDia,
    rotuloDoPeriodo, ymd,
} from '@/Components/Agenda/agenda';

const VISOES = [
    { valor: 'dia', rotulo: 'Dia' },
    { valor: 'semana', rotulo: 'Semana' },
    { valor: 'mes', rotulo: 'Mês' },
    { valor: 'lista', rotulo: 'Lista' },
];

/** Os filtros que existem de verdade: os tipos que o sistema cria e o Google da pessoa. */
const CATEGORIAS = [
    ...['kickoff', 'mapeamento', 'apresentacao', 'recorrente', 'outro'].map((tipo) => ({
        chave: tipo, rotulo: TIPOS[tipo].rotulo, cor: TIPOS[tipo].cor,
    })),
    { chave: 'google', rotulo: 'Outros compromissos do Google', cor: ORIGEM_GOOGLE.cor },
];

function lerUrl() {
    const params = new URLSearchParams(window.location.search);
    const visao = VISOES.some((v) => v.valor === params.get('visao')) ? params.get('visao') : 'semana';

    return { visao, ancora: deYmd(params.get('data')) ?? new Date() };
}

/**
 * Agenda — a página completa (16/09/2026).
 *
 * ### O que aparece
 * O Google Agenda de quem está logado, ao vivo, com os eventos que o sistema
 * criou para onboardings identificados por empresa e tipo — e, para quem conduz
 * um onboarding, os eventos dele mesmo que estejam na agenda de um colega.
 *
 * ### Aberta pela ficha
 * `?onboarding=ID` põe o onboarding em contexto: os eventos dele entram na
 * tela junto dos seus — inclusive os que estão na agenda de um colega — e o
 * "Novo evento" já vem com o cliente escolhido. Não há filtro que esconda o
 * resto (tirado em 17/09/2026, a pedido): quem abre a agenda pela ficha quer
 * ver onde o compromisso cabe no dia.
 *
 * ### Uma conta Google por pessoa
 * O sistema guarda um token por usuário (`google_tokens.user_id` é único), por
 * isso não existe "conectar outra conta" — existe conectar e reconectar.
 */
export default function AgendaIndex({ conectado, usuario, contexto = null, onboardings = [] }) {
    const inicial = useMemo(lerUrl, []);
    const [visao, setVisao] = useState(inicial.visao);
    const [ancora, setAncora] = useState(inicial.ancora);
    const [mesMini, setMesMini] = useState(() => startOfMonth(inicial.ancora));
    const [ocultos, setOcultos] = useState(() => new Set());
    const [pedido, setPedido] = useState(null);
    const [aberto, setAberto] = useState(null);
    const [aviso, setAviso] = useState(null);

    // A URL acompanha o que está na tela — dá para mandar o link da semana.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        params.set('visao', visao);
        params.set('data', ymd(ancora));
        if (contexto) params.set('onboarding', contexto.id);
        window.history.replaceState(window.history.state, '', `${window.location.pathname}?${params.toString()}`);
    }, [visao, ancora, contexto]);

    useEffect(() => {
        if (! aviso) return undefined;
        const t = setTimeout(() => setAviso(null), 7000);

        return () => clearTimeout(t);
    }, [aviso]);

    // Quando a navegação principal sai do mês do mini calendário, ele acompanha.
    useEffect(() => {
        if (! isSameMonth(ancora, mesMini)) setMesMini(startOfMonth(ancora));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ancora]);

    const [de, ate] = intervaloDaVisao(visao, ancora);
    const extra = contexto ? { onboarding: contexto.id } : {};

    const principal = useJson(route('agenda.eventos', { inicio: ymd(de), fim: ymd(ate), ...extra }));

    const [deMini, ateMini] = intervaloDaVisao('mes', mesMini);
    const mesmoIntervalo = ymd(deMini) === ymd(de) && ymd(ateMini) === ymd(ate);
    const doMini = useJson(mesmoIntervalo ? null : route('agenda.eventos', { inicio: ymd(deMini), fim: ymd(ateMini), ...extra }));

    const hoje = new Date();
    const proximos = useJson(route('agenda.eventos', { inicio: ymd(hoje), fim: ymd(addDays(hoje, 13)), ...extra }));

    const recarregar = () => {
        principal.recarregar();
        doMini.recarregar();
        proximos.recarregar();
    };

    const mudou = (mensagem) => {
        setAviso(mensagem);
        recarregar();
    };

    const visivel = (evento) => ! ocultos.has(categoriaDoEvento(evento));

    const eventos = useMemo(() => (principal.dados?.eventos ?? []).filter(visivel),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [principal.dados, ocultos]);

    const marcas = useMemo(() => {
        const fonte = mesmoIntervalo ? principal.dados?.eventos : doMini.dados?.eventos;

        return marcasPorDia((fonte ?? []).filter(visivel));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mesmoIntervalo, principal.dados, doMini.dados, ocultos]);

    const listaProximos = useMemo(() => (proximos.dados?.eventos ?? [])
        .filter(visivel)
        .filter((e) => new Date(e.fim) > new Date() && ! e.dia_inteiro),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [proximos.dados, ocultos]);

    const contagem = useMemo(() => {
        const mapa = {};
        (principal.dados?.eventos ?? []).forEach((e) => {
            const chave = categoriaDoEvento(e);
            mapa[chave] = (mapa[chave] ?? 0) + 1;
        });

        return mapa;
    }, [principal.dados]);

    const andar = (passo) => {
        if (visao === 'dia') setAncora((a) => addDays(a, passo));
        else if (visao === 'mes') setAncora((a) => addMonths(a, passo));
        else if (visao === 'lista') setAncora((a) => addDays(a, passo * 30));
        else setAncora((a) => addWeeks(a, passo));
    };

    const novoEvento = (inicio = null) => setPedido({
        modo: 'criar',
        onboardingId: contexto?.id ?? null,
        tipo: contexto ? 'mapeamento' : 'outro',
        inicio,
    });

    const alternarCategoria = (chave) => setOcultos((atual) => {
        const proximo = new Set(atual);
        if (proximo.has(chave)) proximo.delete(chave);
        else proximo.add(chave);

        return proximo;
    });

    const urlConectar = route('google.connect', { retorno: window.location.pathname + window.location.search });
    const dias = visao === 'dia'
        ? [ancora]
        : Array.from({ length: 7 }, (_, i) => addDays(inicioDaSemana(ancora), i));
    const semNada = principal.dados && ! principal.carregando && eventos.length === 0;

    return (
        <AppLayout title="Agenda">
            <Head title="Agenda" />

            <div className="space-y-4">
                {/* ─── Topo ─── */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-white/[0.08] bg-white/[0.03]">
                            <CalendarDays size={19} className="text-ecf-yellow/80" />
                        </span>
                        <div>
                            <h1 className="text-[20px] font-bold leading-tight text-white">Agenda</h1>
                            <p className="text-[12.5px] text-white/45">
                                Seus compromissos do Google Agenda e os eventos dos onboardings.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Sem conexão, quem oferece conectar é o aviso logo abaixo. */}
                        {conectado && (
                            <a
                                href={urlConectar}
                                title="Reconecte se a conexão expirou ou se foi feita antes de 15/09 (só leitura)"
                                className="inline-flex items-center gap-1.5 rounded-xl border border-white/[0.1] bg-white/[0.03] px-3 py-2 text-[12.5px] text-white/75 transition-colors hover:border-white/25 hover:text-white"
                            >
                                <CheckCircle2 size={14} className="text-emerald-400" /> Google conectado · reconectar
                            </a>
                        )}
                        <button
                            type="button"
                            onClick={() => novoEvento()}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-ecf-yellow px-3.5 py-2 text-[12.5px] font-semibold text-ecf-bg transition-colors hover:bg-ecf-yellow/90"
                        >
                            <Plus size={15} strokeWidth={2.5} /> Novo evento
                        </button>
                        <div className="flex rounded-xl border border-white/[0.08] bg-white/[0.02] p-0.5">
                            {VISOES.map((v) => (
                                <button
                                    key={v.valor}
                                    type="button"
                                    onClick={() => setVisao(v.valor)}
                                    className={cn(
                                        'rounded-[10px] px-3.5 py-1.5 text-[12.5px] transition-colors',
                                        visao === v.valor
                                            ? 'bg-ecf-yellow/[0.12] font-semibold text-ecf-yellow ring-1 ring-ecf-yellow/50'
                                            : 'text-white/55 hover:text-white',
                                    )}
                                >
                                    {v.rotulo}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                {contexto && (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/[0.04] px-3 py-2 text-[12.5px]">
                        <Building2 size={14} className="text-ecf-yellow/80" />
                        <span className="text-white/80">
                            Onboarding de <strong className="font-semibold text-white">{contexto.empresa}</strong>
                            {contexto.servico ? <span className="text-white/45"> · {contexto.servico}</span> : null}
                        </span>
                        <span className="flex-1" />
                        <Link href={contexto.url} className="inline-flex items-center gap-1 text-ecf-yellow/85 hover:text-ecf-yellow">
                            Voltar para a ficha <ArrowUpRight size={12} />
                        </Link>
                        <Link
                            href={route('agenda.index', { visao, data: ymd(ancora) })}
                            className="grid h-6 w-6 place-items-center rounded-md text-white/40 hover:bg-white/[0.06] hover:text-white"
                            aria-label="Tirar este onboarding do contexto"
                        >
                            <X size={13} />
                        </Link>
                    </div>
                )}

                {! conectado && (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-amber-400/20 bg-amber-400/[0.05] px-3 py-2.5 text-[12.5px] text-amber-200/90">
                        <Info size={14} className="shrink-0" />
                        <span className="flex-1">
                            Seu Google Agenda não está conectado. Os eventos dos onboardings que você conduz aparecem
                            mesmo assim; seus outros compromissos, só depois de conectar.
                        </span>
                        <a href={urlConectar} className="rounded-lg bg-ecf-yellow px-3 py-1.5 text-[12px] font-semibold text-ecf-bg hover:bg-ecf-yellow/90">
                            Conectar Google Agenda
                        </a>
                    </div>
                )}

                {aviso && (
                    <p className="flex items-center gap-2 rounded-xl border border-emerald-400/20 bg-emerald-500/[0.07] px-3 py-2 text-[12.5px] text-emerald-200">
                        <CheckCircle2 size={14} /> {aviso}
                    </p>
                )}

                <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
                    {/* ─── A agenda ─── */}
                    <section className="min-w-0 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-3">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <div className="flex rounded-lg border border-white/[0.08]">
                                <button
                                    type="button"
                                    onClick={() => andar(-1)}
                                    aria-label="Anterior"
                                    className="grid h-8 w-8 place-items-center text-white/60 hover:text-white"
                                >
                                    <ChevronLeft size={16} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => andar(1)}
                                    aria-label="Próximo"
                                    className="grid h-8 w-8 place-items-center border-l border-white/[0.08] text-white/60 hover:text-white"
                                >
                                    <ChevronRight size={16} />
                                </button>
                            </div>
                            <button
                                type="button"
                                onClick={() => setAncora(new Date())}
                                className="h-8 rounded-lg bg-white/[0.05] px-3 text-[12.5px] font-medium text-white/80 hover:bg-white/[0.09]"
                            >
                                Hoje
                            </button>
                            <h2 className="ml-1 text-[16px] font-semibold text-white/90">
                                {visao === 'lista'
                                    ? `A partir de ${rotuloDoPeriodo('dia', ancora).replace(/^./, (c) => c.toLowerCase())}`
                                    : rotuloDoPeriodo(visao, ancora)}
                            </h2>
                            <span className="flex-1" />
                            {principal.carregando && <Loader2 size={15} className="animate-spin text-white/40" />}
                            {semNada && (
                                <span className="text-[12px] text-white/35">Nenhum compromisso neste período.</span>
                            )}
                            <button
                                type="button"
                                onClick={recarregar}
                                aria-label="Recarregar"
                                className="grid h-8 w-8 place-items-center rounded-lg text-white/40 hover:bg-white/[0.05] hover:text-white"
                            >
                                <RotateCw size={14} />
                            </button>
                        </div>

                        {(principal.erro || principal.dados?.erro) && (
                            <p className="mb-3 flex items-start gap-2 rounded-lg border border-amber-400/20 bg-amber-400/[0.06] px-3 py-2 text-[12px] text-amber-200/90">
                                <Info size={13} className="mt-0.5 shrink-0" />
                                <span className="flex-1">{principal.erro ?? principal.dados.erro}</span>
                                {principal.erro && (
                                    <button type="button" onClick={principal.recarregar} className="font-semibold hover:underline">
                                        Tentar de novo
                                    </button>
                                )}
                            </p>
                        )}

                        {(visao === 'semana' || visao === 'dia') && (
                            <GradeDeHorarios
                                dias={dias}
                                eventos={eventos}
                                altura="calc(100vh - 290px)"
                                alturaHora={52}
                                aoAbrirEvento={setAberto}
                                aoClicarHorario={(quando) => novoEvento(quando)}
                                aoClicarDia={visao === 'semana' ? (dia) => { setAncora(dia); setVisao('dia'); } : undefined}
                                className="min-h-[480px]"
                            />
                        )}
                        {visao === 'mes' && (
                            <VisaoMes
                                ancora={ancora}
                                eventos={eventos}
                                aoAbrirEvento={setAberto}
                                aoClicarDia={(dia) => { setAncora(dia); setVisao('dia'); }}
                            />
                        )}
                        {visao === 'lista' && (
                            principal.dados ? <VisaoLista eventos={eventos} aoAbrirEvento={setAberto} /> : <Esqueleto />
                        )}
                    </section>

                    {/* ─── A lateral ─── */}
                    <aside className="space-y-4">
                        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
                            <MiniCalendario
                                mes={mesMini}
                                aoMudarMes={setMesMini}
                                selecionado={ancora}
                                aoSelecionar={setAncora}
                                marcas={marcas}
                            />
                        </div>

                        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="text-[13px] font-semibold text-white/85">Próximos eventos</p>
                                <button
                                    type="button"
                                    onClick={() => { setAncora(new Date()); setVisao('lista'); }}
                                    className="text-[12px] font-medium text-ecf-yellow/85 hover:text-ecf-yellow"
                                >
                                    Ver todos
                                </button>
                            </div>
                            {proximos.carregando && ! proximos.dados ? (
                                <Esqueleto linhas={3} />
                            ) : proximos.erro ? (
                                <p className="text-[12px] text-amber-300/85">{proximos.erro}</p>
                            ) : (
                                <ProximosEventos
                                    eventos={listaProximos}
                                    limite={5}
                                    aoAbrir={setAberto}
                                    vazio="Nada marcado para as próximas duas semanas."
                                />
                            )}
                        </div>

                        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
                            <p className="mb-2 text-[13px] font-semibold text-white/85">Mostrar</p>
                            <div className="space-y-0.5">
                                {CATEGORIAS.filter((c) => c.chave !== 'google' || conectado).map((c) => (
                                    <label
                                        key={c.chave}
                                        className="flex cursor-pointer items-center gap-2.5 rounded-lg px-1.5 py-1.5 hover:bg-white/[0.03]"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={! ocultos.has(c.chave)}
                                            onChange={() => alternarCategoria(c.chave)}
                                            className="sr-only"
                                        />
                                        <span
                                            className={cn(
                                                'grid h-4 w-4 shrink-0 place-items-center rounded border',
                                                ocultos.has(c.chave) ? 'border-white/20 bg-transparent' : cn(CORES[c.cor].ponto, 'border-transparent'),
                                            )}
                                        >
                                            {! ocultos.has(c.chave) && <Check size={11} strokeWidth={3} className="text-ecf-bg" />}
                                        </span>
                                        <span className={cn('flex-1 text-[12.5px]', ocultos.has(c.chave) ? 'text-white/35' : 'text-white/75')}>
                                            {c.rotulo}
                                        </span>
                                        {contagem[c.chave] > 0 && (
                                            <span className="text-[11px] tabular-nums text-white/30">{contagem[c.chave]}</span>
                                        )}
                                    </label>
                                ))}
                            </div>
                            <p className="mt-2 text-[11px] leading-relaxed text-white/30">
                                A contagem é do período na tela. "Outros compromissos" vêm do seu Google Agenda —
                                ninguém mais os vê.
                            </p>
                        </div>
                    </aside>
                </div>
            </div>

            <EventoDetalhe
                evento={aberto}
                aoFechar={() => setAberto(null)}
                aoMudou={mudou}
                contextoOnboardingId={null}
                aoEditar={(evento) => {
                    setAberto(null);
                    setPedido({ modo: 'editar', evento });
                }}
                aoEnviarConvite={(evento) => {
                    setAberto(null);
                    setPedido({
                        modo: 'criar',
                        tipo: 'kickoff',
                        onboardingId: evento.vinculo.onboarding_id,
                        inicio: new Date(evento.inicio),
                    });
                }}
            />

            <EventoFormulario
                pedido={pedido}
                aoFechar={() => setPedido(null)}
                aoSalvar={mudou}
                onboardings={onboardings}
                usuario={usuario}
                usuarioConectado={conectado}
            />
        </AppLayout>
    );
}

function Esqueleto({ linhas = 4 }) {
    return (
        <div className="space-y-2.5 py-1">
            {Array.from({ length: linhas }, (_, i) => (
                <div key={i} className="flex gap-2.5">
                    <span className="h-9 w-[3px] animate-pulse rounded-full bg-white/10" />
                    <span className="flex-1 space-y-1.5">
                        <span className="block h-2.5 w-16 animate-pulse rounded bg-white/[0.07]" />
                        <span className="block h-3 w-3/4 animate-pulse rounded bg-white/[0.07]" />
                    </span>
                </div>
            ))}
        </div>
    );
}
