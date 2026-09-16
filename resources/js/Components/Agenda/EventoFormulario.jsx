import { useEffect, useMemo, useState } from 'react';
import { addHours, format, setHours, startOfHour } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { AlertTriangle, Building2, Check, Info, Loader2, Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle,
} from '@/Components/ui/sheet';
import {
    PLATAFORMAS_DO_FORMULARIO, TIPOS, TIPOS_CRIAVEIS, conflitos, deYmd, duracaoEmMinutos, enviarJson,
    eventosDoDia, horaCurta, plataformaParaFormulario, primeiraMaiuscula, ymd,
} from './agenda';
import GradeDeHorarios from './GradeDeHorarios';
import useJson from './useJson';

const DURACOES = [15, 30, 45, 60, 90, 120, 180];
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const campo = 'w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13px] text-white/90 placeholder:text-white/25 focus:border-ecf-yellow/50 focus:outline-none';
const rotuloCampo = 'mb-1.5 block text-[11.5px] font-medium text-white/50';

/** Próxima hora cheia; depois das 18h, amanhã às 10h. */
function horarioSugerido() {
    const agora = new Date();
    const proxima = startOfHour(addHours(agora, 1));

    return proxima.getHours() >= 19 || proxima.getHours() < 8
        ? setHours(startOfHour(addHours(agora, 24)), 10)
        : proxima;
}

function tituloPadrao(tipo, empresa) {
    if (! TIPOS[tipo]?.comCliente) return '';

    return `ECF · ${empresa ?? 'Cliente'} — ${TIPOS[tipo].rotulo}`;
}

/**
 * Criar ou editar um compromisso, num drawer.
 *
 * ### De quem é a agenda
 * Com onboarding, o evento sai da agenda do analista OU do estrategista dele —
 * escolha feita aqui, com quem está conectado ao Google à vista. Sem
 * onboarding, sai da agenda de quem está marcando.
 *
 * ### Por que a coluna do dia
 * A pergunta de quem marca é "quando ele está livre?". A coluna mostra o dia do
 * organizador (o assunto dos compromissos dele fica escondido para os outros) e
 * um clique num vão preenche data e hora.
 *
 * ### O envio é o botão
 * Salvar manda o convite na hora — o Google avisa os convidados por e-mail. Por
 * isso a lista de quem recebe fica à vista até o último clique.
 */
export default function EventoFormulario({
    pedido,
    aoFechar,
    aoSalvar,
    onboardingFixo = null,
    onboardings = [],
    usuario,
    usuarioConectado = false,
}) {
    const aberto = Boolean(pedido);
    const editando = pedido?.modo === 'editar';
    const evento = pedido?.evento ?? null;

    const [form, setForm] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);
    const [errosCampo, setErrosCampo] = useState({});
    const [novoEmail, setNovoEmail] = useState('');

    const onboardingId = form?.onboardingId ? Number(form.onboardingId) : null;
    const empresa = onboardingFixo?.empresa
        ?? evento?.vinculo?.empresa
        ?? onboardings.find((o) => o.id === onboardingId)?.empresa
        ?? null;

    // Quem pode organizar e quem sugerir — o mesmo JSON do cartão da ficha.
    const contexto = useJson(aberto && onboardingId ? route('onboarding.agenda.eventos', onboardingId) : null);
    const pessoas = contexto.dados;

    // ─── Estado inicial ────────────────────────────────────────────────────
    useEffect(() => {
        if (! pedido) {
            setForm(null);

            return;
        }

        setErro(null);
        setErrosCampo({});
        setNovoEmail('');

        if (pedido.modo === 'editar' && pedido.evento) {
            const e = pedido.evento;
            const inicio = new Date(e.inicio);
            const { plataforma, link } = plataformaParaFormulario(e);

            setForm({
                onboardingId: e.vinculo?.onboarding_id ?? '',
                tipo: e.tipo ?? 'outro',
                titulo: e.titulo ?? '',
                tituloTocado: true,
                data: ymd(inicio),
                hora: horaCurta(inicio),
                duracao: duracaoEmMinutos(e),
                organizadorId: e.vinculo?.organizador_id ?? null,
                plataforma,
                link,
                descricao: (e.vinculo ? e.observacoes : e.descricao) ?? '',
                participantes: (e.participantes ?? [])
                    .filter((p) => ! p.organizador && ! p.voce && p.email)
                    .map((p) => ({ email: p.email, nome: p.nome, lado: p.lado ?? null, marcado: true })),
                enviarConvite: true,
            });

            return;
        }

        const inicio = pedido.inicio ?? horarioSugerido();
        const idOnboarding = onboardingFixo?.id ?? pedido.onboardingId ?? '';
        const tipo = pedido.tipo ?? (idOnboarding ? 'mapeamento' : 'outro');
        const nomeEmpresa = onboardingFixo?.empresa ?? onboardings.find((o) => o.id === Number(idOnboarding))?.empresa;

        setForm({
            onboardingId: idOnboarding,
            tipo,
            titulo: tituloPadrao(tipo, nomeEmpresa),
            tituloTocado: false,
            data: ymd(inicio),
            hora: horaCurta(inicio),
            duracao: 60,
            organizadorId: null,
            plataforma: 'google_meet',
            link: '',
            descricao: '',
            participantes: null, // vem do contexto do onboarding
            enviarConvite: true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pedido]);

    // Sugestões e organizador chegam depois do JSON do onboarding.
    useEffect(() => {
        if (! pessoas || ! form) return;

        setForm((f) => {
            if (! f) return f;
            const proximo = { ...f };

            if (! editando && ! proximo.organizadorId) {
                proximo.organizadorId = pessoas.organizador_padrao;
            }

            const sugeridos = (pessoas.participantes ?? []).map((p) => ({ ...p, marcado: ! editando }));
            const atuais = proximo.participantes ?? [];
            const porEmail = new Map(atuais.map((p) => [p.email, p]));
            sugeridos.forEach((s) => {
                if (porEmail.has(s.email)) {
                    porEmail.set(s.email, { ...s, ...porEmail.get(s.email), lado: porEmail.get(s.email).lado ?? s.lado });
                } else {
                    porEmail.set(s.email, s);
                }
            });
            proximo.participantes = [...porEmail.values()];

            return proximo;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pessoas]);

    // Sem onboarding, a lista começa vazia.
    useEffect(() => {
        if (form && ! onboardingId && form.participantes === null) {
            setForm((f) => ({ ...f, participantes: [] }));
        }
    }, [form, onboardingId]);

    const alterar = (mudancas) => setForm((f) => ({ ...f, ...mudancas }));

    const trocarTipo = (tipo) => setForm((f) => ({
        ...f,
        tipo,
        titulo: f.tituloTocado ? f.titulo : tituloPadrao(tipo, empresa),
    }));

    const trocarOnboarding = (id) => {
        const nomeEmpresa = onboardings.find((o) => o.id === Number(id))?.empresa;
        setForm((f) => ({
            ...f,
            onboardingId: id,
            tipo: id ? (f.tipo === 'outro' ? 'mapeamento' : f.tipo) : 'outro',
            titulo: f.tituloTocado ? f.titulo : tituloPadrao(id ? (f.tipo === 'outro' ? 'mapeamento' : f.tipo) : 'outro', nomeEmpresa),
            organizadorId: null,
            participantes: id ? null : [],
        }));
    };

    // ─── Organizador e agenda do dia ───────────────────────────────────────
    const organizadores = pessoas?.organizadores ?? [];
    const organizador = onboardingId
        ? organizadores.find((o) => o.id === Number(form?.organizadorId)) ?? null
        : { id: usuario?.id, nome: usuario?.nome, email: usuario?.email, conectado: usuarioConectado, e_voce: true };

    const organizadorEmail = (editando ? evento?.organizador?.email : organizador?.email)?.toLowerCase();

    const urlDoDia = useMemo(() => {
        if (! form?.data) return null;
        if (onboardingId) {
            const idOrganizador = editando ? evento?.vinculo?.organizador_id : form.organizadorId;
            if (! idOrganizador) return null;

            return route('onboarding.agenda.disponibilidade', {
                onboarding: onboardingId,
                inicio: form.data,
                organizador: idOrganizador,
            });
        }
        if (! usuarioConectado) return null;

        return route('agenda.eventos', { inicio: form.data, fim: form.data });
    }, [form?.data, form?.organizadorId, onboardingId, editando, evento, usuarioConectado]);

    const agendaDoDia = useJson(aberto ? urlDoDia : null);
    const dia = form?.data ? deYmd(form.data) : null;

    const ocupadosDoDia = useMemo(() => {
        if (! dia) return [];

        return eventosDoDia(agendaDoDia.dados?.eventos ?? [], dia).filter((e) => ! evento?.google_event_id
            || (e.google_event_id ?? e.id) !== evento.google_event_id);
    }, [agendaDoDia.dados, dia, evento]);

    const inicioEscolhido = form?.data && form?.hora ? new Date(`${form.data}T${form.hora}:00`) : null;
    const choques = inicioEscolhido ? conflitos(ocupadosDoDia, inicioEscolhido, Number(form.duracao)) : [];

    // ─── Participantes ─────────────────────────────────────────────────────
    const participantes = (form?.participantes ?? []).filter((p) => p.email?.toLowerCase() !== organizadorEmail);
    const marcados = participantes.filter((p) => p.marcado);
    const comCliente = TIPOS[form?.tipo]?.comCliente && onboardingId;
    const semCliente = comCliente && ! marcados.some((p) => p.lado === 'cliente');

    const adicionarEmail = () => {
        const email = novoEmail.trim().toLowerCase();
        if (! EMAIL.test(email)) {
            setErrosCampo((e) => ({ ...e, novoEmail: 'E-mail inválido.' }));

            return;
        }
        setErrosCampo((e) => ({ ...e, novoEmail: null }));
        setForm((f) => {
            const lista = f.participantes ?? [];
            if (lista.some((p) => p.email === email)) {
                return { ...f, participantes: lista.map((p) => (p.email === email ? { ...p, marcado: true } : p)) };
            }

            return { ...f, participantes: [...lista, { email, nome: null, lado: 'outro', marcado: true }] };
        });
        setNovoEmail('');
    };

    const alternar = (email) => setForm((f) => ({
        ...f,
        participantes: f.participantes.map((p) => (p.email === email ? { ...p, marcado: ! p.marcado } : p)),
    }));

    // ─── Envio ─────────────────────────────────────────────────────────────
    const ehKickoff = form?.tipo === 'kickoff';
    const organizadorSemGoogle = ! editando && organizador && ! organizador.conectado;
    const somenteData = ehKickoff && (! form?.enviarConvite || organizadorSemGoogle);

    let bloqueio = null;
    if (form) {
        if (! editando && onboardingId && pessoas && ! pessoas.pode_agendar) {
            bloqueio = 'O onboarding precisa estar em andamento para marcar eventos.';
        } else if (! editando && onboardingId && pessoas && organizadores.length === 0) {
            bloqueio = 'Defina o analista ou o estrategista do onboarding antes de marcar.';
        } else if (! editando && organizadorSemGoogle && ! ehKickoff) {
            bloqueio = organizador.e_voce
                ? 'Conecte o seu Google Agenda para criar o evento.'
                : `${organizador.nome} ainda não conectou o Google Agenda. Escolha outro organizador ou peça para conectar.`;
        } else if (! editando && ! onboardingId && form.tipo !== 'outro') {
            bloqueio = 'Escolha o cliente: este tipo de evento é de um onboarding.';
        }
    }

    const salvar = async () => {
        if (! form || salvando || bloqueio) return;

        const faltas = {};
        if (! form.titulo.trim()) faltas.titulo = 'Dê um título ao evento.';
        if (! form.data || ! form.hora) faltas.inicio = 'Escolha a data e o horário.';
        if (form.plataforma === 'link' && ! form.link.trim()) faltas.link = 'Cole o link da reunião.';
        if (Object.keys(faltas).length) {
            setErrosCampo(faltas);

            return;
        }

        const corpo = {
            titulo: form.titulo.trim(),
            inicio: `${form.data}T${form.hora}`,
            duracao: Number(form.duracao),
            plataforma: form.plataforma,
            link: ['link', 'presencial'].includes(form.plataforma) ? form.link.trim() || null : null,
            descricao: form.descricao.trim() || null,
            participantes: marcados.map((p) => ({ email: p.email, nome: p.nome ?? null })),
        };

        let metodo = 'post';
        let url = route('agenda.eventos.store');

        if (editando) {
            metodo = 'patch';
            url = evento.edicao === 'vinculo'
                ? route('agenda.eventos.update', evento.vinculo.evento_id)
                : route('agenda.google.update', evento.google_event_id);
        } else {
            Object.assign(corpo, {
                tipo: form.tipo,
                onboarding_id: onboardingId,
                organizador_id: onboardingId ? Number(form.organizadorId) || null : null,
                somente_data: somenteData,
            });
        }

        setSalvando(true);
        setErro(null);
        setErrosCampo({});
        const resultado = await enviarJson(metodo, url, corpo);
        setSalvando(false);

        if (! resultado.ok) {
            setErro(resultado.mensagem);
            if (resultado.erros) {
                setErrosCampo(Object.fromEntries(Object.entries(resultado.erros).map(([k, v]) => [k, v[0]])));
            }

            return;
        }

        aoSalvar?.(resultado.mensagem);
        aoFechar();
    };

    const rotuloDoBotao = editando
        ? 'Salvar alterações'
        : somenteData
            ? 'Marcar reunião'
            : marcados.length ? 'Criar e enviar convite' : 'Criar evento';

    return (
        <Sheet open={aberto} onOpenChange={(v) => ! v && ! salvando && aoFechar()}>
            <SheetContent className="max-w-[860px]">
                <SheetHeader>
                    <SheetTitle>{editando ? 'Editar evento' : 'Agendar evento'}</SheetTitle>
                    <SheetDescription>
                        {editando
                            ? 'As mudanças vão para o Google Agenda de quem organizou, e os convidados são avisados.'
                            : 'O evento nasce no Google Agenda do organizador, e os convidados recebem o convite por e-mail.'}
                    </SheetDescription>
                </SheetHeader>

                {form && (
                    <SheetBody className="p-0">
                        <div className="grid gap-0 md:grid-cols-[minmax(0,1fr)_280px]">
                            {/* ─── Os campos ─── */}
                            <div className="space-y-5 px-6 py-5">
                                {/* Cliente */}
                                <div>
                                    <span className={rotuloCampo}>Cliente / onboarding</span>
                                    {onboardingFixo || editando ? (
                                        <p className="flex items-center gap-2 rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2 text-[13px] text-white/80">
                                            <Building2 size={14} className="text-white/35" />
                                            {empresa ?? 'Sem cliente — só na sua agenda'}
                                            {onboardingId && <span className="text-white/35">· Onboarding #{onboardingId}</span>}
                                        </p>
                                    ) : (
                                        <select
                                            value={form.onboardingId}
                                            onChange={(e) => trocarOnboarding(e.target.value)}
                                            className={cn(campo, 'cursor-pointer')}
                                        >
                                            <option value="">Sem cliente — só na minha agenda</option>
                                            {onboardings.map((o) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.empresa}{o.servico ? ` · ${o.servico}` : ''} (#{o.id})
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </div>

                                {/* Tipo */}
                                <div>
                                    <span className={rotuloCampo}>Tipo de evento</span>
                                    <div className="grid grid-cols-2 gap-1.5">
                                        {TIPOS_CRIAVEIS.map((tipo) => {
                                            const indisponivel = editando
                                                ? tipo !== form.tipo
                                                : ! onboardingId && tipo !== 'outro';

                                            return (
                                                <button
                                                    key={tipo}
                                                    type="button"
                                                    disabled={indisponivel}
                                                    onClick={() => trocarTipo(tipo)}
                                                    className={cn(
                                                        'flex items-center gap-2 rounded-lg border px-2.5 py-2 text-left text-[12.5px] transition-colors',
                                                        form.tipo === tipo
                                                            ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.08] text-white'
                                                            : 'border-white/[0.08] bg-white/[0.02] text-white/65 hover:border-white/20',
                                                        indisponivel && 'cursor-not-allowed opacity-35 hover:border-white/[0.08]',
                                                    )}
                                                >
                                                    <span className={cn('h-2 w-2 shrink-0 rounded-full', {
                                                        kickoff: 'bg-ecf-yellow', mapeamento: 'bg-violet-400', apresentacao: 'bg-sky-400', outro: 'bg-rose-400',
                                                    }[tipo])} />
                                                    {TIPOS[tipo].rotulo}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {ehKickoff && ! editando && (
                                        <p className="mt-1.5 text-[11.5px] text-white/40">
                                            A data também vai para o onboarding — é a que o cliente vê no portal. Se já houver convite,
                                            ele é remarcado em vez de duplicado.
                                        </p>
                                    )}
                                </div>

                                {/* Título */}
                                <div>
                                    <label className={rotuloCampo} htmlFor="evento-titulo">Título</label>
                                    <input
                                        id="evento-titulo"
                                        value={form.titulo}
                                        maxLength={200}
                                        onChange={(e) => alterar({ titulo: e.target.value, tituloTocado: true })}
                                        placeholder="Ex.: Alinhamento de campanhas"
                                        className={cn(campo, errosCampo.titulo && 'border-rose-400/60')}
                                    />
                                    <Erro texto={errosCampo.titulo} />
                                </div>

                                {/* Quando */}
                                <div className="grid grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)] gap-2">
                                    <div>
                                        <label className={rotuloCampo} htmlFor="evento-data">Data</label>
                                        <input
                                            id="evento-data"
                                            type="date"
                                            value={form.data}
                                            onChange={(e) => alterar({ data: e.target.value })}
                                            className={campo}
                                        />
                                    </div>
                                    <div>
                                        <label className={rotuloCampo} htmlFor="evento-hora">Horário</label>
                                        <input
                                            id="evento-hora"
                                            type="time"
                                            step={300}
                                            value={form.hora}
                                            onChange={(e) => alterar({ hora: e.target.value })}
                                            className={campo}
                                        />
                                    </div>
                                    <div>
                                        <label className={rotuloCampo} htmlFor="evento-duracao">Duração</label>
                                        <select
                                            id="evento-duracao"
                                            value={form.duracao}
                                            onChange={(e) => alterar({ duracao: Number(e.target.value) })}
                                            className={cn(campo, 'cursor-pointer')}
                                        >
                                            {[...new Set([...DURACOES, Number(form.duracao)])].sort((a, b) => a - b).map((m) => (
                                                <option key={m} value={m}>
                                                    {m < 60 ? `${m} min` : `${Math.floor(m / 60)}h${m % 60 ? String(m % 60).padStart(2, '0') : ''}`}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                                <Erro texto={errosCampo.inicio} />
                                {choques.length > 0 && (
                                    <p className="-mt-3 flex items-start gap-1.5 rounded-lg border border-amber-400/25 bg-amber-400/[0.07] px-3 py-2 text-[12px] text-amber-200">
                                        <AlertTriangle size={13} className="mt-0.5 shrink-0" />
                                        <span>
                                            Choca com {choques.length === 1 ? 'um compromisso' : `${choques.length} compromissos`} de{' '}
                                            {organizador?.e_voce || ! onboardingId ? 'você' : organizador?.nome ?? 'quem organiza'}{' '}
                                            ({choques.map((c) => `${horaCurta(new Date(c.inicio))}${c.titulo ? ` ${c.titulo}` : ''}`).join(', ')}).
                                            Dá para marcar assim mesmo.
                                        </span>
                                    </p>
                                )}

                                {/* Organizador */}
                                {onboardingId && ! editando && (
                                    <div>
                                        <span className={rotuloCampo}>Sai da agenda de</span>
                                        {contexto.carregando && ! pessoas ? (
                                            <p className="flex items-center gap-2 text-[12px] text-white/40"><Loader2 size={13} className="animate-spin" /> Carregando…</p>
                                        ) : contexto.erro ? (
                                            <Erro texto={contexto.erro} />
                                        ) : (
                                            <div className="grid gap-1.5 sm:grid-cols-2">
                                                {organizadores.map((o) => (
                                                    <button
                                                        key={o.id}
                                                        type="button"
                                                        onClick={() => alterar({ organizadorId: o.id })}
                                                        className={cn(
                                                            'rounded-lg border px-3 py-2 text-left transition-colors',
                                                            Number(form.organizadorId) === o.id
                                                                ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.08]'
                                                                : 'border-white/[0.08] bg-white/[0.02] hover:border-white/20',
                                                        )}
                                                    >
                                                        <span className="block truncate text-[12.5px] font-medium text-white/85">
                                                            {o.nome}{o.e_voce && <span className="text-white/40"> (você)</span>}
                                                        </span>
                                                        <span className="block text-[11px] capitalize text-white/40">{o.papel}</span>
                                                        <span className={cn('mt-0.5 flex items-center gap-1 text-[11px]', o.conectado ? 'text-emerald-300/85' : 'text-amber-300/85')}>
                                                            {o.conectado ? <Check size={11} /> : <Info size={11} />}
                                                            {o.conectado ? 'Google conectado' : 'Google não conectado'}
                                                        </span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                        {organizadorSemGoogle && organizador?.e_voce && (
                                            <a
                                                href={route('google.connect', { retorno: window.location.pathname + window.location.search })}
                                                className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-2.5 py-1 text-[11.5px] font-semibold text-ecf-bg hover:bg-ecf-yellow/90"
                                            >
                                                Conectar meu Google Agenda
                                            </a>
                                        )}
                                    </div>
                                )}

                                {ehKickoff && ! editando && (
                                    <label className={cn('flex items-start gap-2.5 rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2.5', organizadorSemGoogle ? 'opacity-70' : 'cursor-pointer')}>
                                        <Checkbox
                                            checked={! somenteData}
                                            disabled={organizadorSemGoogle}
                                            onCheckedChange={(v) => alterar({ enviarConvite: Boolean(v) })}
                                            className="mt-0.5"
                                        />
                                        <span className="text-[12.5px] text-white/75">
                                            Enviar convite pelo Google Agenda
                                            <span className="block text-[11.5px] text-white/40">
                                                {organizadorSemGoogle
                                                    ? `${organizador?.nome ?? 'O organizador'} não conectou o Google — a data é salva no onboarding, sem convite.`
                                                    : 'Desmarcado, a data é salva só no onboarding.'}
                                            </span>
                                        </span>
                                    </label>
                                )}

                                {/* Sem convite, plataforma e convidados não são usados — ficam à vista, apagados. */}
                                <div className={cn('space-y-5', somenteData && 'pointer-events-none opacity-40')}>
                                {/* Plataforma */}
                                <div>
                                    <span className={rotuloCampo}>Onde acontece</span>
                                    <div className="grid grid-cols-2 gap-1.5">
                                        {PLATAFORMAS_DO_FORMULARIO.map(({ valor, rotulo, icone: Icone }) => (
                                            <button
                                                key={valor}
                                                type="button"
                                                onClick={() => alterar({ plataforma: valor, link: valor === form.plataforma ? form.link : '' })}
                                                className={cn(
                                                    'flex items-center gap-2 rounded-lg border px-2.5 py-2 text-left text-[12.5px] transition-colors',
                                                    form.plataforma === valor
                                                        ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.08] text-white'
                                                        : 'border-white/[0.08] bg-white/[0.02] text-white/65 hover:border-white/20',
                                                )}
                                            >
                                                <Icone size={14} className="shrink-0 text-white/45" />
                                                <span className="truncate">{rotulo}</span>
                                            </button>
                                        ))}
                                    </div>
                                    {form.plataforma === 'google_meet' && (
                                        <p className="mt-1.5 text-[11.5px] text-white/40">O Google cria a sala e o link vai no convite.</p>
                                    )}
                                    {form.plataforma === 'link' && (
                                        <div className="mt-2">
                                            <input
                                                value={form.link}
                                                onChange={(e) => alterar({ link: e.target.value })}
                                                placeholder="https://teams.microsoft.com/…"
                                                className={cn(campo, errosCampo.link && 'border-rose-400/60')}
                                            />
                                            <Erro texto={errosCampo.link} />
                                        </div>
                                    )}
                                    {form.plataforma === 'presencial' && (
                                        <input
                                            value={form.link}
                                            onChange={(e) => alterar({ link: e.target.value })}
                                            placeholder="Endereço (opcional)"
                                            className={cn(campo, 'mt-2')}
                                        />
                                    )}
                                </div>

                                {/* Participantes */}
                                <div>
                                    <span className={rotuloCampo}>Participantes</span>
                                    {form.participantes === null ? (
                                        <p className="flex items-center gap-2 text-[12px] text-white/40"><Loader2 size={13} className="animate-spin" /> Carregando contatos…</p>
                                    ) : (
                                        <div className="space-y-1">
                                            {participantes.map((p) => (
                                                <label
                                                    key={p.email}
                                                    className="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-white/[0.03]"
                                                >
                                                    <Checkbox checked={p.marcado} onCheckedChange={() => alternar(p.email)} />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-[12.5px] text-white/85">{p.nome || p.email}</span>
                                                        {p.nome && <span className="block truncate text-[11px] text-white/35">{p.email}</span>}
                                                    </span>
                                                    {p.lado === 'cliente' && <span className="rounded bg-sky-400/15 px-1.5 py-0.5 text-[10px] text-sky-200">Cliente</span>}
                                                    {p.lado === 'ecf' && <span className="rounded bg-white/[0.06] px-1.5 py-0.5 text-[10px] text-white/50">ECF</span>}
                                                </label>
                                            ))}
                                            {participantes.length === 0 && (
                                                <p className="px-2 py-1 text-[12px] text-white/35">Ninguém convidado ainda.</p>
                                            )}
                                            <div className="flex gap-1.5 pt-1">
                                                <input
                                                    type="email"
                                                    value={novoEmail}
                                                    onChange={(e) => setNovoEmail(e.target.value)}
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter') {
                                                            e.preventDefault();
                                                            adicionarEmail();
                                                        }
                                                    }}
                                                    placeholder="Adicionar e-mail"
                                                    className={cn(campo, 'py-1.5')}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={adicionarEmail}
                                                    className="grid w-9 shrink-0 place-items-center rounded-lg border border-white/[0.08] text-white/60 hover:border-white/20 hover:text-white"
                                                    aria-label="Adicionar participante"
                                                >
                                                    <Plus size={15} />
                                                </button>
                                            </div>
                                            <Erro texto={errosCampo.novoEmail} />
                                            {semCliente && (
                                                <p className="flex items-start gap-1.5 pt-1 text-[11.5px] text-amber-300/85">
                                                    <Info size={12} className="mt-0.5 shrink-0" />
                                                    Nenhum contato do cliente vai receber este convite.
                                                    {pessoas && (pessoas.participantes ?? []).every((p) => p.lado !== 'cliente') && ' Cadastre o e-mail dos contatos no Resumo do cliente.'}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>

                                </div>

                                {/* Observações */}
                                <div>
                                    <label className={rotuloCampo} htmlFor="evento-descricao">Observações</label>
                                    <textarea
                                        id="evento-descricao"
                                        rows={3}
                                        value={form.descricao}
                                        onChange={(e) => alterar({ descricao: e.target.value })}
                                        placeholder="Pauta, material para levar, combinados…"
                                        className={cn(campo, 'resize-y')}
                                    />
                                    {onboardingId && TIPOS[form.tipo]?.comCliente && (
                                        <p className="mt-1 text-[11px] text-white/30">O convite leva o nome da empresa e conta como reunião com o cliente.</p>
                                    )}
                                </div>

                            </div>

                            {/* ─── A agenda do dia ─── */}
                            <div className="border-t border-white/[0.06] px-4 py-5 md:border-l md:border-t-0">
                                <p className="text-[12px] font-semibold text-white/75">
                                    {onboardingId
                                        ? `Agenda de ${editando ? (evento?.organizador?.nome ?? 'quem organiza') : (organizador?.nome ?? '…')}`
                                        : 'Sua agenda'}
                                </p>
                                <p className="mb-2 text-[11px] text-white/35">
                                    {dia ? primeiraMaiuscula(format(dia, "EEEE, d 'de' MMMM", { locale: ptBR })) : '—'}
                                </p>
                                {agendaDoDia.dados && agendaDoDia.dados.conectado === false && (
                                    <p className="mb-2 text-[11px] text-amber-300/80">Sem Google conectado: os horários ocupados não aparecem.</p>
                                )}
                                {(agendaDoDia.erro || agendaDoDia.dados?.erro) && (
                                    <p className="mb-2 text-[11px] text-amber-300/80">{agendaDoDia.erro || agendaDoDia.dados.erro}</p>
                                )}
                                {! onboardingId && ! usuarioConectado && (
                                    <p className="mb-2 text-[11px] text-amber-300/80">Conecte o seu Google Agenda para ver os horários ocupados.</p>
                                )}
                                {dia && (
                                    <div className="relative">
                                        <GradeDeHorarios
                                            dias={[dia]}
                                            eventos={ocupadosDoDia}
                                            compacto
                                            alturaHora={40}
                                            altura={460}
                                            selecao={inicioEscolhido ? {
                                                inicio: inicioEscolhido,
                                                duracao: Number(form.duracao),
                                                rotulo: TIPOS[form.tipo]?.rotulo ?? 'evento',
                                            } : null}
                                            aoClicarHorario={(quando) => alterar({ data: ymd(quando), hora: horaCurta(quando) })}
                                        />
                                        {agendaDoDia.carregando && (
                                            <span className="absolute right-2 top-2 z-30"><Loader2 size={14} className="animate-spin text-white/40" /></span>
                                        )}
                                    </div>
                                )}
                                <p className="mt-2 text-[11px] text-white/30">Clique num horário livre para escolher.</p>
                            </div>
                        </div>
                    </SheetBody>
                )}

                <SheetFooter>
                    {erro && (
                        <p className="mb-3 flex items-start gap-1.5 rounded-lg border border-rose-400/25 bg-rose-500/10 px-3 py-2 text-[12px] text-rose-200">
                            <AlertTriangle size={13} className="mt-0.5 shrink-0" /> {erro}
                        </p>
                    )}
                    {bloqueio && (
                        <div className="mb-3 flex flex-wrap items-center gap-2 text-[12px] text-amber-300/90">
                            <Info size={13} className="shrink-0" /> <span>{bloqueio}</span>
                            {organizadorSemGoogle && organizador?.e_voce && (
                                <a
                                    href={route('google.connect', { retorno: window.location.pathname + window.location.search })}
                                    className="rounded-lg bg-ecf-yellow px-2.5 py-1 text-[11.5px] font-semibold text-ecf-bg hover:bg-ecf-yellow/90"
                                >
                                    Conectar meu Google Agenda
                                </a>
                            )}
                        </div>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-[11.5px] text-white/40">
                            {somenteData
                                ? 'Nenhum e-mail é enviado.'
                                : marcados.length
                                    ? `O Google avisa ${marcados.length} ${marcados.length === 1 ? 'convidado' : 'convidados'} por e-mail ao salvar.`
                                    : 'Sem convidados: o evento fica só na agenda do organizador.'}
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={aoFechar}
                                disabled={salvando}
                                className="rounded-lg px-3 py-2 text-[12.5px] text-white/60 hover:text-white"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={salvar}
                                disabled={salvando || Boolean(bloqueio) || ! form}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-4 py-2 text-[12.5px] font-semibold text-ecf-bg transition-colors hover:bg-ecf-yellow/90 disabled:opacity-50"
                            >
                                {salvando && <Loader2 size={14} className="animate-spin" />}
                                {salvando ? 'Salvando…' : rotuloDoBotao}
                            </button>
                        </div>
                    </div>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function Erro({ texto }) {
    if (! texto) return null;

    return (
        <p className="mt-1 flex items-center gap-1 text-[11.5px] text-rose-300">
            <X size={11} /> {texto}
        </p>
    );
}
