import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { BarChart3, CalendarClock, CalendarDays, ExternalLink, ListTodo, Paperclip, RefreshCw, Send, UserCog } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

// Pendências do Analista de Conta (TKT-0035). Os mapas de cor ficam num .jsx de propósito: o
// Tailwind deste projeto só varre .jsx (learnings demandas-dev-e-chamados §4).
export const STATUS_PENDENCIA = {
    aberta:            'bg-sky-400/10 text-sky-300 border-sky-400/25',
    em_andamento:      'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30',
    aguardando_seller: 'bg-purple-400/10 text-purple-300 border-purple-400/25',
    devolvida:         'bg-orange-400/10 text-orange-300 border-orange-400/25',
    concluida:         'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    cancelada:         'bg-white/[0.05] text-white/45 border-white/[0.1]',
};

export const PRIORIDADE_PENDENCIA = {
    urgente: 'bg-red-500/15 text-red-300',
    alta:    'bg-orange-400/10 text-orange-300',
    media:   'bg-white/[0.06] text-white/70',
    baixa:   'bg-white/[0.04] text-white/45',
};

// Status com nome curto no selo (o completo fica no title).
const STATUS_CURTO = { aguardando_seller: 'Aguardando seller' };

// Hoje em Brasília (AAAA-MM-DD), para o mínimo do campo de prazo.
export const hojeIso = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' });

export function SeloStatus({ p, className }) {
    return (
        <span title={p.status_label}
              className={cn('inline-flex shrink-0 items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-[10.5px] font-semibold',
                  STATUS_PENDENCIA[p.status] ?? STATUS_PENDENCIA.aberta, className)}>
            {STATUS_CURTO[p.status] ?? p.status_label}
        </span>
    );
}

export function SeloPrioridade({ p }) {
    return (
        <span className={cn('inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10.5px] font-semibold', PRIORIDADE_PENDENCIA[p.prioridade])}>
            {p.prioridade_label}
        </span>
    );
}

export function SeloPrazo({ p }) {
    if (p.encerrada) return null;
    if (!p.prazo) return <span className="text-white/35 text-[11px]">sem prazo</span>;
    return p.atrasada ? (
        <span className="inline-flex shrink-0 items-center rounded bg-red-500/15 px-1.5 py-0.5 text-[10.5px] font-semibold text-red-300"
              title={`Prazo pedido: ${p.prazo}`}>
            Atrasada · {p.dias_atraso}d
        </span>
    ) : (
        <span className="text-white/50 text-[11px] tabular-nums" title="Prazo pedido por quem encaminhou">prazo {p.prazo.slice(0, 5)}</span>
    );
}

/**
 * O dia planejado pelo Analista de Conta (fatia 2): "Bruno vai fazer em 16/11". Fica em destaque
 * quando passa do prazo pedido; quando o dia passou sem concluir, "ficou para trás".
 */
export function SeloPlanejada({ p, curto = false }) {
    if (p.encerrada || !p.data_planejada) return null;
    if (p.para_tras) {
        return (
            <span title="O dia planejado passou e a pendência não foi concluída"
                  className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded bg-amber-400/10 px-1.5 py-0.5 text-[10.5px] font-semibold text-amber-300">
                <CalendarClock size={11} /> Ficou para trás · era {p.data_planejada}
            </span>
        );
    }
    return (
        <span title={p.depois_do_prazo ? `Planejada para depois do prazo pedido (${p.prazo})` : 'Dia planejado pelo Analista de Conta'}
              className={cn('inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded px-1.5 py-0.5 text-[10.5px] font-semibold',
                  p.depois_do_prazo ? 'bg-red-500/10 text-red-300' : 'bg-white/[0.05] text-white/65')}>
            <CalendarClock size={11} />
            {curto ? `em ${p.data_planejada}` : `${p.analista ?? 'Analista de Conta'} vai fazer em ${p.data_planejada}`}
            {p.depois_do_prazo && !curto && <span className="font-normal opacity-80">· depois do prazo</span>}
        </span>
    );
}

/** Prioridade editável — quem pediu sobe para pedir antecipação; o Analista de Conta ajusta. */
export function SeletorPrioridade({ p, opcoes }) {
    const [ocupado, setOcupado] = useState(false);
    const mudar = (valor) => {
        if (valor === p.prioridade) return;
        setOcupado(true);
        router.post(route('mlb.polos-painel.pendencias-conta.prioridade', p.id), { prioridade: valor }, {
            preserveScroll: true, preserveState: true, onFinish: () => setOcupado(false),
        });
    };
    return (
        <select value={p.prioridade} disabled={ocupado} onChange={(e) => mudar(e.target.value)} aria-label="Prioridade da pendência"
                title="Mudar a prioridade avisa quem está do outro lado"
                className={cn('cursor-pointer appearance-none rounded border-0 px-1.5 py-0.5 text-[10.5px] font-semibold outline-none disabled:opacity-50',
                    PRIORIDADE_PENDENCIA[p.prioridade])}>
            {opcoes.map((o) => <option key={o.valor} value={o.valor} className="bg-ecf-card text-white">{o.rotulo}</option>)}
        </select>
    );
}

const ABAS = [
    { chave: 'fila',           rotulo: 'Fila',           rota: 'mlb.polos-painel.analista-conta',                Icone: ListTodo },
    { chave: 'agenda',         rotulo: 'Agenda',         rota: 'mlb.polos-painel.analista-conta.agenda',         Icone: CalendarDays },
    { chave: 'acompanhamento', rotulo: 'Acompanhamento', rota: 'mlb.polos-painel.analista-conta.acompanhamento', Icone: BarChart3 },
];

/** Fila · Agenda · Acompanhamento — o acompanhamento só para coordenação e liderança. */
export function AbasAnalistaConta({ atual, podeAcompanhar = false }) {
    return (
        <nav className="flex flex-wrap items-center gap-1.5" aria-label="Analista de Conta">
            {ABAS.filter((a) => a.chave !== 'acompanhamento' || podeAcompanhar).map(({ chave, rotulo, rota, Icone }) => (
                <Link key={chave} href={route(rota)} aria-current={chave === atual ? 'page' : undefined}
                      className={cn('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-semibold transition',
                          chave === atual ? 'bg-white/[0.1] text-white' : 'text-white/50 hover:bg-white/[0.05] hover:text-white/80')}>
                    <Icone size={13} /> {rotulo}
                </Link>
            ))}
        </nav>
    );
}

/**
 * Uma pendência numa linha — o cartão da fila de revisão. O detalhe (descrição, quem pediu, o que
 * foi feito) vai no hover; o histórico mora na fila do Analista de Conta, aberta pelo clique.
 */
export function PendenciaCompacta({ p }) {
    const titulo = [
        `${p.tipo_label} · ${p.status_label}`,
        p.descricao,
        `Pedido por ${p.solicitante?.nome} em ${p.aberta_em}`,
        p.conclusao && `Feito: ${p.conclusao}`,
    ].filter(Boolean).join('\n');

    return (
        <a href={route('mlb.polos-painel.analista-conta', { pendencia: p.id })} title={titulo}
           className="group inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-md bg-white/[0.03] px-2 py-0.5 text-[11px] ring-1 ring-inset ring-white/[0.06] transition hover:bg-white/[0.06] hover:ring-ecf-yellow/25">
            <span className="shrink-0 font-semibold text-white/80 group-hover:text-white">{p.tipo_label}</span>
            <SeloStatus p={p} />
            {p.status === 'concluida' ? (
                <span className="min-w-0 max-w-[18rem] truncate text-white/50">{p.concluida_em?.slice(0, 5)} · {p.conclusao}</span>
            ) : (
                <>
                    {(!p.data_planejada || p.atrasada) && <SeloPrazo p={p} />}
                    <SeloPlanejada p={p} />
                </>
            )}
        </a>
    );
}

/**
 * "Encaminhar para Analista de Conta" — o formulário do ticket: tipo, descrição (obrigatória),
 * MLBs, prioridade, prazo e anexos. Quem pediu e quando o servidor preenche.
 */
export function EncaminharDialog({ empresa, opcoes, aberto, onFechar }) {
    const form = useForm({
        empresa_id: empresa?.id,
        tipo:       opcoes?.tipos?.[0]?.valor ?? 'publicacao',
        descricao:  '',
        mlbs:       '',
        prioridade: 'media',
        prazo:      '',
        anexos:     [],
    });

    const fechar = () => { form.reset(); form.clearErrors(); onFechar(); };

    const enviar = (ev) => {
        ev.preventDefault();
        form.post(route('mlb.polos-painel.pendencias-conta.store'), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: fechar,
        });
    };

    const erroAnexo = Object.entries(form.errors).find(([k]) => k.startsWith('anexos'))?.[1];

    return (
        <Dialog open={aberto} onOpenChange={(v) => { if (!v) fechar(); }}>
            <DialogContent className="max-w-lg border-white/[0.1] bg-ecf-card">
                <DialogHeader>
                    <DialogTitle className="text-white">Encaminhar para Analista de Conta</DialogTitle>
                    <DialogDescription className="text-white/50">
                        {empresa?.nome}. A empresa continua na sua carteira e na sua fila de revisão.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="space-y-3">
                    <Campo rotulo="Tipo">
                        <div className="flex flex-wrap gap-1.5">
                            {opcoes.tipos.map((t) => (
                                <Opcao key={t.valor} ativo={form.data.tipo === t.valor} onClick={() => form.setData('tipo', t.valor)}>{t.rotulo}</Opcao>
                            ))}
                        </div>
                    </Campo>

                    <Campo rotulo="O que precisa ser feito" erro={form.errors.descricao}>
                        <textarea value={form.data.descricao} onChange={(e) => form.setData('descricao', e.target.value)} rows={4} maxLength={5000} required
                                  placeholder="Ex.: publicar os 3 kits novos da planilha e conferir as fotos"
                                  className={CLASSE_ENTRADA} />
                    </Campo>

                    <Campo rotulo="MLBs envolvidos (opcional)" erro={form.errors.mlbs}>
                        <textarea value={form.data.mlbs} onChange={(e) => form.setData('mlbs', e.target.value)} rows={2} maxLength={3000}
                                  placeholder="MLB123456789, MLB987654321 (um por linha ou separados por vírgula)"
                                  className={CLASSE_ENTRADA} />
                    </Campo>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto]">
                        <Campo rotulo="Prioridade" erro={form.errors.prioridade}>
                            <div className="flex flex-wrap gap-1.5">
                                {opcoes.prioridades.map((p) => (
                                    <Opcao key={p.valor} ativo={form.data.prioridade === p.valor} onClick={() => form.setData('prioridade', p.valor)}
                                           tom={p.valor === 'urgente' ? 'vermelho' : null}>{p.rotulo}</Opcao>
                                ))}
                            </div>
                        </Campo>
                        <Campo rotulo="Prazo" erro={form.errors.prazo}>
                            <input type="date" value={form.data.prazo} min={hojeIso()} required onChange={(e) => form.setData('prazo', e.target.value)}
                                   className={cn(CLASSE_ENTRADA, '[color-scheme:dark]')} />
                        </Campo>
                    </div>

                    <Campo rotulo="Anexo ou print (opcional)" erro={erroAnexo}>
                        <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-white/[0.14] px-3 py-2 text-[12px] text-white/55 transition hover:border-ecf-yellow/40 hover:text-white/80">
                            <Paperclip size={13} />
                            {form.data.anexos.length > 0
                                ? form.data.anexos.map((f) => f.name).join(', ')
                                : 'Escolher arquivos (imagem, PDF, planilha — até 5, 10 MB cada)'}
                            <input type="file" multiple className="hidden" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.xlsx,.docx"
                                   onChange={(e) => form.setData('anexos', Array.from(e.target.files ?? []).slice(0, 5))} />
                        </label>
                    </Campo>

                    {form.errors.empresa_id && <p className="text-red-300 text-xs">{form.errors.empresa_id}</p>}

                    <div className="flex items-center justify-end gap-2 pt-1">
                        <button type="button" onClick={fechar} className="rounded-lg px-3 py-2 text-sm font-semibold text-white/60 hover:text-white">Cancelar</button>
                        <button type="submit" disabled={form.processing || !form.data.descricao.trim() || !form.data.prazo}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-2 text-sm font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40">
                            <Send size={14} /> {form.processing ? 'Encaminhando…' : 'Encaminhar'}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export const CLASSE_ENTRADA = 'w-full rounded-lg border border-white/[0.1] bg-black/20 px-3 py-2 text-sm text-white/90 outline-none placeholder:text-white/25 focus:border-ecf-yellow/40';

export function Campo({ rotulo, erro, children }) {
    return (
        <div>
            <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-white/40">{rotulo}</p>
            {children}
            {erro && <p className="mt-1 text-xs text-red-300">{erro}</p>}
        </div>
    );
}

export function Opcao({ ativo, onClick, tom = null, children }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={ativo}
                className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                    ativo
                        ? (tom === 'vermelho' ? 'bg-red-500 text-white' : 'bg-ecf-yellow text-black')
                        : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
            {children}
        </button>
    );
}

/** O histórico de uma pendência: quem fez o quê e quando, na ordem em que aconteceu. */
export function Historico({ itens = [] }) {
    if (itens.length === 0) return <p className="text-white/35 text-[12px]">Sem registros.</p>;
    return (
        <ol className="space-y-2 border-l border-white/[0.08] pl-3">
            {itens.map((h) => (
                <li key={h.id} className="relative">
                    <span className="absolute -left-[17px] top-1.5 h-2 w-2 rounded-full bg-white/25" />
                    <p className="text-[12px] text-white/70">
                        <span className="font-semibold text-white/85">{h.por}</span> {h.resumo}
                        <span className="text-white/35"> · {h.em}</span>
                    </p>
                    {h.texto && <p className="mt-0.5 whitespace-pre-line text-[12px] text-white/55">{h.texto}</p>}
                    {h.mlbs?.length > 0 && <ListaMlbs mlbs={h.mlbs} className="mt-1" />}
                    {h.anexo && (
                        <a href={h.anexo.url} target="_blank" rel="noopener noreferrer"
                           className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-sky-300 hover:text-sky-200">
                            <Paperclip size={11} /> {h.anexo.nome}
                        </a>
                    )}
                </li>
            ))}
        </ol>
    );
}

export function ListaMlbs({ mlbs = [], className }) {
    if (mlbs.length === 0) return null;
    return (
        <div className={cn('flex flex-wrap gap-1', className)}>
            {mlbs.map((m) => (
                <span key={m} className="rounded bg-white/[0.05] px-1.5 py-0.5 font-mono text-[10.5px] text-white/65">{m}</span>
            ))}
        </div>
    );
}

/**
 * Pendências do Analista de Conta na ficha da empresa (gaveta da linha no Painel Polos). `estado`
 * vem de `mlb.polos-painel.pendencias-conta.empresa`, buscado quando a gaveta abre:
 * `{ lista?: [...], loading?: bool, erro?: bool }` — o mesmo contrato do card de PPA.
 */
export default function PendenciasContaDaEmpresa({ estado }) {
    const lista = estado?.lista;
    const emAberto = lista?.filter((p) => !p.encerrada).length ?? 0;
    // A ficha abre para quem vê o Painel (mlb.projetos); a fila, só para quem opera ou encaminha.
    const permissoes = usePage().props.auth?.permissions ?? [];
    const abreFila = permissoes.includes('mlb.analista_conta') || permissoes.includes('mlb.faturamento_polos');

    let corpo;
    if (!lista && estado?.erro) {
        corpo = <p className="text-red-300/70 text-[12px]">Falha ao buscar as pendências.</p>;
    } else if (!lista) {
        corpo = <p className="text-white/30 text-[12px] inline-flex items-center gap-1.5"><RefreshCw size={12} className="animate-spin" /> Carregando…</p>;
    } else if (lista.length === 0) {
        corpo = <p className="text-white/30 text-[12px]">Nenhuma pendência encaminhada ao Analista de Conta.</p>;
    } else {
        corpo = (
            <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">
                {lista.map((p) => <CardPendencia key={p.id} p={p} />)}
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h4 className="text-white/60 text-[11px] font-semibold uppercase tracking-wider flex items-center gap-1.5">
                    <UserCog size={12} /> Analista de Conta
                    {emAberto > 0 && (
                        <span className="rounded-full bg-white/[0.06] px-1.5 py-px text-[10px] font-semibold text-white/50 tabular-nums" title="Em aberto">{emAberto}</span>
                    )}
                    {lista && estado?.loading && <RefreshCw size={10} className="animate-spin text-white/25" />}
                </h4>
                {abreFila && (
                    <a href={route('mlb.polos-painel.analista-conta')} target="_blank" rel="noopener noreferrer"
                       className="inline-flex items-center gap-1 text-[11px] text-white/35 hover:text-ecf-yellow transition">
                        Abrir fila do Analista de Conta <ExternalLink size={11} />
                    </a>
                )}
            </div>
            {corpo}
        </div>
    );
}

function CardPendencia({ p }) {
    const [aberto, setAberto] = useState(false);
    return (
        <div className="min-w-0 rounded-lg bg-white/[0.03] p-2.5 ring-1 ring-inset ring-white/[0.05]">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-[12px] font-semibold text-white/85">{p.tipo_label}</span>
                <SeloStatus p={p} />
                <SeloPrioridade p={p} />
                <SeloPrazo p={p} />
                <SeloPlanejada p={p} />
            </div>
            <p className="mt-1 line-clamp-3 whitespace-pre-line text-[12px] text-white/60" title={p.descricao}>{p.descricao}</p>
            <ListaMlbs mlbs={p.mlbs} className="mt-1" />
            {p.conclusao && (
                <p className="mt-1.5 text-[12px] text-emerald-300/80">
                    Feito por {p.analista ?? '—'} em {p.concluida_em}: <span className="text-white/65">{p.conclusao}</span>
                </p>
            )}
            <div className="mt-1.5 flex items-center justify-between gap-2 text-[10.5px] text-white/35">
                <span>Pedido por {p.solicitante?.nome} em {p.aberta_em}</span>
                <button type="button" onClick={() => setAberto((v) => !v)} className="font-semibold text-white/45 hover:text-white/80">
                    {aberto ? 'Esconder histórico' : `Histórico (${p.historico?.length ?? 0})`}
                </button>
            </div>
            {aberto && <div className="mt-2"><Historico itens={p.historico} /></div>}
        </div>
    );
}
