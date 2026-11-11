import { useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Eye, MessageSquareText, Paperclip, RotateCcw, Search, X } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { AbasAnalistaConta, CLASSE_ENTRADA, Campo, Historico, ListaMlbs, SeletorPrioridade, SeloPlanejada, SeloPrazo, SeloPrioridade,
         SeloStatus } from '../components/PendenciasConta';

// Inertia: filtros e o que está aberto sobrevivem a cada gravação (a página recarrega só as props).
const OPCOES_GRAVAR = { preserveScroll: true, preserveState: true };

// "Para hoje" (fatia 2) = planejadas para hoje + as que ficaram para trás + atrasadas sem dia.
const VISOES = [
    { key: 'hoje',       label: 'Para hoje' },
    { key: 'abertas',    label: 'Todas em aberto' },
    { key: 'encerradas', label: 'Encerradas (30 dias)' },
];

const soParaHoje = (empresas) => empresas
    .map((e) => ({ ...e, pendencias: e.pendencias.filter((p) => p.para_hoje) }))
    .filter((e) => e.pendencias.length > 0);

const FILTROS_PADRAO = { busca: '', solicitante: 'todos', tipo: 'todos', status: 'todos', fase: 'todas', atrasadas: false };

const normaliza = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Filtra pendência a pendência; a empresa some quando nenhuma dela passa. */
function filtrar(empresas, f) {
    const termo = normaliza(f.busca.trim());
    return empresas
        .filter((e) => f.fase === 'todas' || e.fase === f.fase)
        .filter((e) => !termo || normaliza(e.nome).includes(termo) || String(e.cust_id ?? '').includes(termo))
        .map((e) => ({
            ...e,
            pendencias: e.pendencias.filter((p) =>
                (f.solicitante === 'todos' || String(p.solicitante?.id) === f.solicitante)
                && (f.tipo === 'todos' || p.tipo === f.tipo)
                && (f.status === 'todos' || p.status === f.status)
                && (!f.atrasadas || p.atrasada)),
        }))
        .filter((e) => e.pendencias.length > 0);
}

/**
 * Polos/AnalistaConta/Index — a fila do Analista de Conta (TKT-0035): as empresas com pendência
 * encaminhada pela fila de revisão, na ordem do servidor (prazo pedido, depois prioridade).
 *
 * Quem opera (`mlb.analista_conta` ou admin) muda o status, comenta e conclui com o que foi feito.
 * Os analistas de Ads abrem a mesma tela só para acompanhar o que encaminharam — a empresa segue
 * na carteira e na revisão deles normalmente. Quem opera abre em "Para hoje" (fatia 2).
 */
export default function AnalistaContaIndex({ fila = null, opcoes, podeOperar = false, podeAcompanhar = false, eu = null, foco = null, minhas = false }) {
    const { auth } = usePage().props;
    const veRevisao = (auth?.permissions ?? []).includes('mlb.faturamento_polos');

    const resumo     = fila?.resumo ?? {};
    const abertas    = fila?.abertas ?? [];
    const encerradas = fila?.encerradas ?? [];

    const paraHoje = useMemo(() => soParaHoje(abertas), [abertas]);

    // Aviso do sino (?pendencia=ID): abre na visão onde a pendência está, sem filtro.
    const focoEncerrada = !!foco && encerradas.some((e) => e.pendencias.some((p) => p.id === foco));
    const visaoInicial  = foco ? (focoEncerrada ? 'encerradas' : 'abertas') : (podeOperar && !minhas ? 'hoje' : 'abertas');
    const [visao, setVisao]     = useState(visaoInicial);
    const [filtros, setFiltros] = useState({ ...FILTROS_PADRAO, solicitante: minhas && !foco ? String(eu) : 'todos' });
    const mudar = (campo, valor) => setFiltros((f) => ({ ...f, [campo]: valor }));
    const filtrando = Object.keys(FILTROS_PADRAO).some((k) => filtros[k] !== FILTROS_PADRAO[k]);

    const listaDa = (v) => (v === 'hoje' ? paraHoje : v === 'abertas' ? abertas : encerradas);
    const base  = listaDa(visao);
    const lista = useMemo(() => filtrar(base, filtros), [base, filtros]);

    const solicitantes = useMemo(() => {
        const mapa = new Map();
        [...abertas, ...encerradas].forEach((e) => e.pendencias.forEach((p) => {
            if (p.solicitante?.id) mapa.set(String(p.solicitante.id), p.solicitante.nome);
        }));
        return [...mapa.entries()].sort((a, b) => a[1].localeCompare(b[1], 'pt-BR'));
    }, [abertas, encerradas]);

    const statusDaVisao = (opcoes?.status ?? []).filter((s) => (visao !== 'encerradas'
        ? !['concluida', 'cancelada'].includes(s.valor)
        : ['concluida', 'cancelada'].includes(s.valor)));

    const atalho = (v, extra = {}) => { setVisao(v); setFiltros({ ...FILTROS_PADRAO, ...extra }); };
    const totalPendencias = (empresas) => empresas.reduce((n, e) => n + e.pendencias.length, 0);

    return (
        <AppLayout title="Analista de Conta">
            <Head title="Analista de Conta" />

            <div className="space-y-5 max-w-[1240px] mx-auto">
                {/* Cabeçalho */}
                <div className="flex flex-col gap-1">
                    {veRevisao && (
                        <Link href={route('mlb.polos-painel.fila')} className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-xs w-fit">
                            <ArrowLeft size={13} /> Voltar para a fila de revisão
                        </Link>
                    )}
                    <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Fila do Analista de Conta</h1>
                    <p className="text-white/50 text-sm">
                        Pendências encaminhadas pela fila de revisão dos Polos, por prazo pedido e prioridade.
                        A empresa continua na carteira e na revisão de quem é responsável.
                    </p>
                </div>

                <AbasAnalistaConta atual="fila" podeAcompanhar={podeAcompanhar} />

                {!podeOperar && (
                    <div className="flex items-start gap-2 rounded-xl border border-sky-400/20 bg-sky-400/[0.05] p-3">
                        <Eye size={15} className="text-sky-300 mt-0.5 shrink-0" />
                        <p className="text-sky-100/80 text-xs">
                            Você acompanha esta fila. Quem muda o status e conclui é o Analista de Conta; você pode comentar nas pendências que encaminhou.
                        </p>
                    </div>
                )}

                {/* Cards do topo — também são atalhos para a lista */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Topo label="Para hoje" valor={resumo.para_hoje ?? 0} destaque onClick={() => atalho('hoje')}
                          sub={(resumo.para_tras ?? 0) > 0 ? `${resumo.para_tras} ficaram para trás` : 'planejadas e atrasadas'} />
                    <Topo label="Em aberto" valor={resumo.em_aberto ?? 0} onClick={() => atalho('abertas')}
                          sub={`${resumo.empresas ?? 0} empresa(s) · ${resumo.sem_data ?? 0} sem data`} />
                    <Topo label="Atrasadas" valor={resumo.atrasadas ?? 0} vermelho={(resumo.atrasadas ?? 0) > 0}
                          onClick={() => atalho('abertas', { atrasadas: true })} sub="prazo pedido já passou" />
                    <Topo label="Aguardando seller" valor={resumo.aguardando ?? 0}
                          onClick={() => atalho('abertas', { status: 'aguardando_seller' })} sub="ou informação" />
                    <Topo label="Devolvidas" valor={resumo.devolvidas ?? 0} amarelo={(resumo.devolvidas ?? 0) > 0}
                          onClick={() => atalho('abertas', { status: 'devolvida' })} sub="falta informação de quem pediu" />
                    <Topo label="Concluídas hoje" valor={resumo.concluidas_hoje ?? 0}
                          onClick={() => atalho('encerradas', { status: 'concluida' })} sub="ver as encerradas" />
                </div>

                {/* Visão e filtros */}
                <div className="space-y-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-3">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="mr-1 text-[10px] font-semibold uppercase tracking-wider text-white/35">Ver</span>
                        {VISOES.map((v) => (
                            <button key={v.key} type="button" aria-pressed={visao === v.key}
                                    onClick={() => { setVisao(v.key); mudar('status', 'todos'); }}
                                    className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                        visao === v.key ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                                {v.label} <span className="tabular-nums opacity-70">{totalPendencias(listaDa(v.key))}</span>
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative w-full sm:w-56">
                            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                            <input value={filtros.busca} onChange={(ev) => mudar('busca', ev.target.value)} placeholder="Buscar empresa ou Cust ID…"
                                   className="w-full rounded-lg border border-white/[0.1] bg-ecf-card py-1.5 pl-9 pr-3 text-sm text-white/90 outline-none placeholder:text-white/25 focus:border-ecf-yellow/40" />
                        </div>
                        <Escolha valor={filtros.solicitante} onChange={(v) => mudar('solicitante', v)} titulo="Quem pediu"
                                 opcoes={[['todos', 'Quem pediu: todos'], ...solicitantes.map(([id, nome]) => [id, Number(id) === eu ? `${nome} (eu)` : nome])]} />
                        <Escolha valor={filtros.tipo} onChange={(v) => mudar('tipo', v)} titulo="Tipo"
                                 opcoes={[['todos', 'Tipo: todos'], ...(opcoes?.tipos ?? []).map((t) => [t.valor, t.rotulo])]} />
                        <Escolha valor={filtros.status} onChange={(v) => mudar('status', v)} titulo="Status"
                                 opcoes={[['todos', 'Status: todos'], ...statusDaVisao.map((s) => [s.valor, s.rotulo])]} />
                        <Escolha valor={filtros.fase} onChange={(v) => mudar('fase', v)} titulo="Fase"
                                 opcoes={[['todas', 'Fase: todas'], ...['M1', 'M2', 'M3', 'M4'].map((f) => [f, f])]} />
                        {visao !== 'encerradas' && (
                            <button type="button" onClick={() => mudar('atrasadas', !filtros.atrasadas)} aria-pressed={filtros.atrasadas}
                                    className={cn('rounded-lg border px-3 py-1.5 text-sm transition',
                                        filtros.atrasadas ? 'border-red-500/40 text-red-300' : 'border-white/[0.1] text-white/70 hover:border-white/25')}>
                                Só atrasadas
                            </button>
                        )}
                        {filtrando && (
                            <button type="button" onClick={() => setFiltros(FILTROS_PADRAO)}
                                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-semibold text-white/50 hover:text-white/80">
                                <X size={13} /> Limpar
                            </button>
                        )}
                    </div>
                    {filtrando && (
                        <p className="text-white/40 text-[11px]">Mostrando {totalPendencias(lista)} de {totalPendencias(base)} pendência(s)</p>
                    )}
                </div>

                {/* Lista */}
                {base.length === 0 ? (
                    visao === 'hoje' ? (
                        <Vazio ok texto={<>Nada para hoje.{(resumo.sem_data ?? 0) > 0 && <> Há {resumo.sem_data} sem data:{' '}
                            <Link href={route('mlb.polos-painel.analista-conta.agenda')} className="text-ecf-yellow hover:underline">distribuir na agenda</Link>.</>}</>} />
                    ) : (
                        <Vazio ok={visao === 'abertas'} texto={visao === 'abertas'
                            ? 'Nenhuma pendência em aberto.'
                            : 'Nenhuma pendência encerrada nos últimos 30 dias.'} />
                    )
                ) : lista.length === 0 ? (
                    <Vazio texto="Nenhuma pendência com esses filtros." />
                ) : (
                    <div className="space-y-3">
                        {lista.map((e) => <CartaoEmpresa key={e.id} e={e} podeOperar={podeOperar} opcoes={opcoes} eu={eu} foco={foco} />)}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function Topo({ label, valor, sub, destaque = false, vermelho = false, amarelo = false, onClick }) {
    return (
        <button type="button" onClick={onClick} title="Mostrar na lista"
                className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3 text-left transition hover:border-white/20 hover:bg-white/[0.04]">
            <p className="text-white/40 text-[10px] font-semibold uppercase tracking-wider">{label}</p>
            <p className={cn('font-display font-extrabold text-2xl mt-1 tabular-nums',
                destaque ? 'text-ecf-yellow' : vermelho ? 'text-red-400' : amarelo ? 'text-amber-300' : 'text-white/90')}>
                {valor}
            </p>
            {sub && <p className="text-white/40 text-[11px] mt-1 leading-snug">{sub}</p>}
        </button>
    );
}

function Escolha({ valor, onChange, opcoes, titulo }) {
    const ativo = valor !== opcoes[0][0];
    return (
        <select value={valor} onChange={(ev) => onChange(ev.target.value)} title={titulo} aria-label={titulo}
                className={cn('rounded-lg border bg-ecf-card px-3 py-1.5 text-sm outline-none focus:border-ecf-yellow/40',
                    ativo ? 'border-ecf-yellow/40 text-ecf-yellow' : 'border-white/[0.1] text-white/80')}>
            {opcoes.map(([k, rotulo]) => <option key={k} value={k} className="bg-ecf-card text-white">{rotulo}</option>)}
        </select>
    );
}

function Vazio({ texto, ok = false }) {
    return (
        <div className="flex items-center justify-center gap-2 rounded-2xl border border-white/[0.08] bg-white/[0.02] px-4 py-10 text-sm text-white/50">
            {ok && <Check size={16} className="text-emerald-400" />} {texto}
        </div>
    );
}

function CartaoEmpresa({ e, podeOperar, opcoes, eu, foco }) {
    return (
        <div className={cn('rounded-2xl border bg-white/[0.02]', e.atrasada ? 'border-red-500/25' : 'border-white/[0.08]')}>
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-white/[0.06] px-4 py-3">
                <p className="text-white font-semibold">{e.nome}</p>
                <p className="text-white/45 text-xs">{[e.polo, e.fase, e.cust_id ? `Cust ${e.cust_id}` : 'sem Cust ID'].filter(Boolean).join(' · ')}</p>
                <p className="ml-auto text-white/40 text-xs">
                    Carteira de <span className="text-white/65">{e.responsavel ?? 'sem responsável'}</span>
                </p>
            </div>
            <div className="divide-y divide-white/[0.05]">
                {e.pendencias.map((p) => (
                    <LinhaPendencia key={p.id} p={p} podeOperar={podeOperar} opcoes={opcoes} emFoco={p.id === foco}
                                    podeComentar={podeOperar || p.solicitante?.id === eu || e.responsavel_id === eu} />
                ))}
            </div>
        </div>
    );
}

function LinhaPendencia({ p, podeOperar, opcoes, podeComentar, emFoco }) {
    const ref = useRef(null);
    const [historico, setHistorico]   = useState(emFoco);
    const [comentando, setComentando] = useState(false);
    const [concluindo, setConcluindo] = useState(false);
    const [pedindo, setPedindo]       = useState(null); // status que pede texto: devolvida | cancelada
    const [ocupado, setOcupado]       = useState(false);
    const [erro, setErro]             = useState(null);

    useEffect(() => {
        if (emFoco) ref.current?.scrollIntoView({ block: 'center' });
    }, [emFoco]);

    const enviarStatus = (status, texto = null, aoTerminar = null) => {
        setOcupado(true);
        setErro(null);
        router.post(route('mlb.polos-painel.pendencias-conta.status', p.id), { status, texto }, {
            ...OPCOES_GRAVAR,
            onSuccess: () => aoTerminar?.(),
            onError: (errs) => setErro(errs.texto ?? errs.status ?? 'Não foi possível mudar o status agora.'),
            onFinish: () => setOcupado(false),
        });
    };

    const mudarStatus = (status) => {
        if (status === p.status) return;
        if (status === 'devolvida' || status === 'cancelada') { setPedindo(status); return; }
        enviarStatus(status);
    };

    const manuais = (opcoes?.status ?? []).filter((s) => (opcoes?.status_manuais ?? []).includes(s.valor) && s.valor !== 'cancelada');

    return (
        <div ref={ref} className={cn('px-4 py-3', emFoco && 'bg-ecf-yellow/[0.04] ring-1 ring-inset ring-ecf-yellow/30')}>
            <div className="flex flex-col gap-3 md:flex-row md:items-start">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {podeComentar && !p.encerrada ? <SeletorPrioridade p={p} opcoes={opcoes?.prioridades ?? []} /> : <SeloPrioridade p={p} />}
                        <span className="text-white/90 text-sm font-semibold">{p.tipo_label}</span>
                        <SeloPrazo p={p} />
                        <SeloPlanejada p={p} />
                        {(p.encerrada || !podeOperar) && <SeloStatus p={p} />}
                    </div>
                    <p className="mt-1 whitespace-pre-line text-sm text-white/70">{p.descricao}</p>
                    <ListaMlbs mlbs={p.mlbs} className="mt-1.5" />
                    {p.conclusao && (
                        <div className="mt-2 rounded-lg bg-emerald-500/[0.06] px-3 py-2 text-[12px] ring-1 ring-inset ring-emerald-500/15">
                            <p className="font-semibold text-emerald-300/90">Feito por {p.analista ?? '—'} em {p.concluida_em}</p>
                            <p className="mt-0.5 whitespace-pre-line text-white/70">{p.conclusao}</p>
                            <ListaMlbs mlbs={p.mlbs_alterados} className="mt-1" />
                        </div>
                    )}
                    <p className="mt-1.5 text-[11px] text-white/40">
                        Pedido por <span className="text-white/60">{p.solicitante?.nome}</span> em {p.aberta_em}
                        {/* O selo do topo já mostra o prazo; a data cheia aqui só quando atrasou. */}
                        {p.atrasada && <> · prazo pedido {p.prazo}</>}
                        {p.analista && !p.encerrada && <> · com {p.analista}</>}
                    </p>
                </div>

                {podeOperar && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2 md:justify-end">
                        {p.encerrada ? (
                            <button type="button" disabled={ocupado} onClick={() => enviarStatus('aberta')}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.1] px-3 py-1.5 text-xs font-semibold text-white/70 transition hover:border-white/25 disabled:opacity-40">
                                <RotateCcw size={13} /> Reabrir
                            </button>
                        ) : (
                            <>
                                <select value={p.status} disabled={ocupado} onChange={(ev) => mudarStatus(ev.target.value)} aria-label="Status da pendência"
                                        className="rounded-lg border border-white/[0.1] bg-ecf-card px-2.5 py-1.5 text-xs font-semibold text-white/85 outline-none focus:border-ecf-yellow/40 disabled:opacity-50">
                                    {manuais.map((s) => <option key={s.valor} value={s.valor} className="bg-ecf-card text-white">{s.rotulo}</option>)}
                                </select>
                                <button type="button" disabled={ocupado} onClick={() => setConcluindo(true)}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-black transition hover:brightness-95 disabled:opacity-40">
                                    <Check size={13} strokeWidth={3} /> Concluir
                                </button>
                                <button type="button" disabled={ocupado} onClick={() => setPedindo('cancelada')} title="Cancelar a pendência"
                                        className="rounded-lg px-2 py-1.5 text-xs font-semibold text-white/40 transition hover:text-red-300 disabled:opacity-40">
                                    Cancelar
                                </button>
                            </>
                        )}
                    </div>
                )}
            </div>

            <div className="mt-2 flex items-center gap-4 text-[11px]">
                <button type="button" onClick={() => setHistorico((v) => !v)} className="font-semibold text-white/45 hover:text-white/80">
                    {historico ? 'Esconder histórico' : `Histórico (${p.historico?.length ?? 0})`}
                </button>
                {podeComentar && (
                    <button type="button" onClick={() => setComentando((v) => !v)}
                            className="inline-flex items-center gap-1 font-semibold text-white/45 hover:text-white/80">
                        <MessageSquareText size={12} /> Comentar
                    </button>
                )}
            </div>

            {erro && <p className="mt-2 text-xs text-red-300">{erro}</p>}
            {comentando && <FormComentario p={p} onFeito={() => { setComentando(false); setHistorico(true); }} />}
            {historico && <div className="mt-3"><Historico itens={p.historico} /></div>}

            {concluindo && <ConcluirDialog p={p} onFechar={() => setConcluindo(false)} />}
            {pedindo && (
                <TextoDoStatus p={p} status={pedindo} ocupado={ocupado} erro={erro}
                               onFechar={() => { setPedindo(null); setErro(null); }}
                               onEnviar={(texto) => enviarStatus(pedindo, texto, () => setPedindo(null))} />
            )}
        </div>
    );
}

/** Conclusão: o que foi feito (obrigatório) e os MLBs alterados — já vem com os pedidos. */
function ConcluirDialog({ p, onFechar }) {
    const form = useForm({ texto: '', mlbs_alterados: (p.mlbs ?? []).join(', ') });
    const enviar = (ev) => {
        ev.preventDefault();
        form.post(route('mlb.polos-painel.pendencias-conta.concluir', p.id), { ...OPCOES_GRAVAR, onSuccess: onFechar });
    };

    return (
        <Dialog open onOpenChange={(v) => { if (!v) onFechar(); }}>
            <DialogContent className="max-w-lg border-white/[0.1] bg-ecf-card">
                <DialogHeader>
                    <DialogTitle className="text-white">Concluir pendência</DialogTitle>
                    <DialogDescription className="text-white/50">
                        {p.tipo_label} · pedido por {p.solicitante?.nome}. Quem pediu é avisado no sistema.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={enviar} className="space-y-3">
                    <Campo rotulo="O que foi feito" erro={form.errors.texto}>
                        <textarea value={form.data.texto} onChange={(e) => form.setData('texto', e.target.value)} rows={4} maxLength={5000} required autoFocus
                                  placeholder="Ex.: publiquei os 3 kits e coloquei 2 na central de promoção" className={CLASSE_ENTRADA} />
                    </Campo>
                    <Campo rotulo="MLBs alterados" erro={form.errors.mlbs_alterados}>
                        <textarea value={form.data.mlbs_alterados} onChange={(e) => form.setData('mlbs_alterados', e.target.value)} rows={2} maxLength={3000}
                                  placeholder="MLB123456789, MLB987654321" className={CLASSE_ENTRADA} />
                    </Campo>
                    <div className="flex items-center justify-end gap-2 pt-1">
                        <button type="button" onClick={onFechar} className="rounded-lg px-3 py-2 text-sm font-semibold text-white/60 hover:text-white">Voltar</button>
                        <button type="submit" disabled={form.processing || !form.data.texto.trim()}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40">
                            <Check size={14} strokeWidth={3} /> {form.processing ? 'Concluindo…' : 'Concluir e avisar'}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Devolver pede o que falta (vai para quem pediu); cancelar aceita um motivo. */
function TextoDoStatus({ p, status, ocupado, erro, onFechar, onEnviar }) {
    const [texto, setTexto] = useState('');
    const devolver = status === 'devolvida';

    return (
        <Dialog open onOpenChange={(v) => { if (!v) onFechar(); }}>
            <DialogContent className="max-w-md border-white/[0.1] bg-ecf-card">
                <DialogHeader>
                    <DialogTitle className="text-white">{devolver ? 'Devolver para quem pediu' : 'Cancelar pendência'}</DialogTitle>
                    <DialogDescription className="text-white/50">
                        {devolver
                            ? `${p.solicitante?.nome} é avisado e, quando responder aqui, a pendência volta para a fila.`
                            : `${p.solicitante?.nome} é avisado do cancelamento.`}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={(ev) => { ev.preventDefault(); onEnviar(texto); }} className="space-y-3">
                    <Campo rotulo={devolver ? 'O que falta' : 'Motivo (opcional)'} erro={erro}>
                        <textarea value={texto} onChange={(e) => setTexto(e.target.value)} rows={3} maxLength={5000} required={devolver} autoFocus
                                  placeholder={devolver ? 'Ex.: qual é o MLB do kit? Não achei na conta.' : ''} className={CLASSE_ENTRADA} />
                    </Campo>
                    <div className="flex items-center justify-end gap-2">
                        <button type="button" onClick={onFechar} className="rounded-lg px-3 py-2 text-sm font-semibold text-white/60 hover:text-white">Voltar</button>
                        <button type="submit" disabled={ocupado || (devolver && !texto.trim())}
                                className={cn('rounded-lg px-3 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-40',
                                    devolver ? 'bg-orange-400 text-black hover:brightness-95' : 'bg-red-600 text-white hover:bg-red-600/90')}>
                            {devolver ? 'Devolver' : 'Cancelar pendência'}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FormComentario({ p, onFeito }) {
    const form = useForm({ texto: '', anexos: [] });
    const enviar = (ev) => {
        ev.preventDefault();
        form.post(route('mlb.polos-painel.pendencias-conta.comentar', p.id), {
            ...OPCOES_GRAVAR, forceFormData: true,
            onSuccess: () => { form.reset(); onFeito(); },
        });
    };
    const erroAnexo = Object.entries(form.errors).find(([k]) => k.startsWith('anexos'))?.[1];

    return (
        <form onSubmit={enviar} className="mt-2 space-y-2 rounded-xl border border-white/[0.06] bg-black/10 p-3">
            <textarea value={form.data.texto} onChange={(e) => form.setData('texto', e.target.value)} rows={2} maxLength={5000} autoFocus
                      placeholder={p.status === 'devolvida' ? 'Responda o que falta — a pendência volta para a fila' : 'Comentário'}
                      className={CLASSE_ENTRADA} />
            <div className="flex flex-wrap items-center gap-2">
                <label className="inline-flex cursor-pointer items-center gap-1.5 text-[11px] text-white/50 hover:text-white/80">
                    <Paperclip size={12} />
                    {form.data.anexos.length > 0 ? form.data.anexos.map((f) => f.name).join(', ') : 'Anexar'}
                    <input type="file" multiple className="hidden" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.xlsx,.docx"
                           onChange={(e) => form.setData('anexos', Array.from(e.target.files ?? []).slice(0, 5))} />
                </label>
                <button type="submit" disabled={form.processing || !form.data.texto.trim()}
                        className="ml-auto rounded-lg bg-ecf-yellow px-3 py-1.5 text-xs font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40">
                    {form.processing ? 'Enviando…' : 'Comentar'}
                </button>
            </div>
            {(form.errors.texto || erroAnexo) && <p className="text-xs text-red-300">{form.errors.texto ?? erroAnexo}</p>}
        </form>
    );
}
