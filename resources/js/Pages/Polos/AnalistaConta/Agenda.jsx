import { useMemo, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DndContext, DragOverlay, KeyboardSensor, MouseSensor, TouchSensor, closestCorners, useDraggable, useDroppable, useSensor, useSensors } from '@dnd-kit/core';
import { ArrowLeft, CalendarX, ChevronLeft, ChevronRight, Eye, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { AbasAnalistaConta, SeloPrioridade, hojeIso } from '../components/PendenciasConta';

// Inertia: seleção e rolagem sobrevivem a cada gravação (a página recarrega só as props).
const OPCOES_GRAVAR = { preserveScroll: true, preserveState: true };

const SEM_DATA  = 'sem-data';
const PARA_TRAS = 'para-tras';

/**
 * Polos/AnalistaConta/Agenda — a semana do Analista de Conta (TKT-0035, fatia 2): uma coluna por
 * dia útil, "Sem data" e, quando houver, "Ficaram para trás" (dia planejado já passou, fora da
 * semana exibida). Arrastar um cartão define o dia planejado; com cartões marcados, arrastar
 * um deles leva todos, e a barra de seleção planeja vários de uma vez.
 *
 * O dia planejado é do Analista de Conta; o prazo é o pedido por quem encaminhou. Cartão
 * planejado depois do prazo fica em destaque (e quem pediu é avisado no servidor). Os analistas
 * de Ads veem a agenda, mas não movem nada.
 */
export default function AgendaAnalistaConta({ agenda, podeOperar = false, podeAcompanhar = false, hoje }) {
    const { auth } = usePage().props;
    const veRevisao = (auth?.permissions ?? []).includes('mlb.faturamento_polos');
    const hojeStr   = hoje ?? hojeIso();

    const [selecionados, setSelecionados] = useState(() => new Set());
    const [arrastando, setArrastando]     = useState(null);
    const [ocupado, setOcupado]           = useState(false);
    const [erro, setErro]                 = useState(null);
    const [dataEmMassa, setDataEmMassa]   = useState('');
    const soltouEm = useRef(0);

    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 8 } }),
        useSensor(KeyboardSensor),
    );

    const colunas = useMemo(() => {
        const lista = [];
        if (agenda.para_tras.length > 0) {
            lista.push({ id: PARA_TRAS, titulo: 'Ficaram para trás', sub: 'dia planejado já passou', cartoes: agenda.para_tras, aceita: false, tom: 'atraso' });
        }
        agenda.dias.forEach((d) => lista.push({
            id: d.data, titulo: `${d.rotulo} ${d.dia}`, cartoes: d.cartoes, empresas: d.empresas, hoje: d.hoje, passado: d.passado,
            aceita: !d.passado, data: d.data,
        }));
        lista.push({ id: SEM_DATA, titulo: 'Sem data', sub: 'ainda não planejadas', cartoes: agenda.sem_data, aceita: true, data: null, tom: 'neutro' });
        return lista;
    }, [agenda]);

    const todos = useMemo(() => colunas.flatMap((c) => c.cartoes), [colunas]);
    const porId = useMemo(() => new Map(todos.map((c) => [c.id, c])), [todos]);

    const alternar = (id) => setSelecionados((s) => {
        const novo = new Set(s);
        novo.has(id) ? novo.delete(id) : novo.add(id);
        return novo;
    });

    const planejar = (ids, data) => {
        if (ids.length === 0 || ocupado) return;
        setOcupado(true);
        setErro(null);
        router.post(route('mlb.polos-painel.pendencias-conta.planejar'), { ids, data }, {
            ...OPCOES_GRAVAR,
            // Tira da seleção só o que esta gravação levou: o que foi marcado enquanto ela
            // estava em andamento continua marcado.
            onSuccess: () => {
                setSelecionados((s) => { const novo = new Set(s); ids.forEach((id) => novo.delete(id)); return novo; });
                setDataEmMassa('');
            },
            onError: (errs) => setErro(errs.data ?? errs.ids ?? 'Não foi possível planejar agora.'),
            onFinish: () => setOcupado(false),
        });
    };

    const aoSoltar = ({ active, over }) => {
        setArrastando(null);
        soltouEm.current = Date.now();
        if (!over) return;
        const coluna = colunas.find((c) => c.id === over.id);
        if (!coluna || !coluna.aceita) return;

        // Arrastar um cartão marcado leva a seleção inteira; um desmarcado vai sozinho.
        const ids = selecionados.has(active.id) ? [...selecionados] : [active.id];
        const mudam = ids.filter((id) => (porId.get(id)?.data_planejada_iso ?? null) !== coluna.data);
        if (mudam.length > 0) planejar(mudam, coluna.data);
    };

    const abrir = (id) => {
        // O clique que encerra um arraste não abre o cartão.
        if (Date.now() - soltouEm.current < 250) return;
        router.visit(route('mlb.polos-painel.analista-conta', { pendencia: id }));
    };

    // Dias da semana exibida que ainda dá para planejar — os atalhos da barra de seleção.
    const diasPlanejaveis = agenda.dias.filter((d) => !d.passado);
    const arrastado = arrastando ? porId.get(arrastando) : null;
    const levando   = arrastando && selecionados.has(arrastando) ? selecionados.size : 1;

    return (
        <AppLayout title="Agenda do Analista de Conta">
            <Head title="Agenda do Analista de Conta" />

            <div className="space-y-5 max-w-[1400px] mx-auto">
                <div className="flex flex-col gap-1">
                    {veRevisao && (
                        <Link href={route('mlb.polos-painel.fila')} className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-xs w-fit">
                            <ArrowLeft size={13} /> Voltar para a fila de revisão
                        </Link>
                    )}
                    <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Agenda do Analista de Conta</h1>
                    <p className="text-white/50 text-sm">
                        {podeOperar
                            ? 'Arraste as pendências para o dia em que vai fazer. Marque várias para planejar de uma vez. O prazo é o que quem encaminhou pediu; o dia planejado é seu.'
                            : 'O dia em que o Analista de Conta planejou fazer cada pendência. Para antecipar, suba a prioridade ou comente na pendência.'}
                    </p>
                </div>

                <AbasAnalistaConta atual="agenda" podeAcompanhar={podeAcompanhar} />

                {!podeOperar && (
                    <div className="flex items-start gap-2 rounded-xl border border-sky-400/20 bg-sky-400/[0.05] p-3">
                        <Eye size={15} className="text-sky-300 mt-0.5 shrink-0" />
                        <p className="text-sky-100/80 text-xs">Você vê a agenda; só o Analista de Conta move os cartões.</p>
                    </div>
                )}

                {/* Semana */}
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('mlb.polos-painel.analista-conta.agenda', { semana: agenda.semana.anterior })} preserveScroll
                          className="inline-flex items-center rounded-lg border border-white/[0.1] p-1.5 text-white/60 transition hover:border-white/25 hover:text-white" title="Semana anterior">
                        <ChevronLeft size={15} />
                    </Link>
                    <span className="min-w-[11rem] text-center text-sm font-semibold text-white tabular-nums">{agenda.semana.rotulo}</span>
                    <Link href={route('mlb.polos-painel.analista-conta.agenda', { semana: agenda.semana.proxima })} preserveScroll
                          className="inline-flex items-center rounded-lg border border-white/[0.1] p-1.5 text-white/60 transition hover:border-white/25 hover:text-white" title="Próxima semana">
                        <ChevronRight size={15} />
                    </Link>
                    {!agenda.semana.atual && (
                        <Link href={route('mlb.polos-painel.analista-conta.agenda')} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-white/55 hover:text-white">
                            Esta semana
                        </Link>
                    )}
                    <span className="ml-auto text-white/40 text-xs">
                        {agenda.total} em aberto{agenda.outras_semanas > 0 && ` · ${agenda.outras_semanas} planejada(s) para outras semanas`}
                    </span>
                </div>

                {/* Barra da seleção */}
                {podeOperar && selecionados.size > 0 && (
                    <div className="sticky top-2 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-ecf-yellow/30 bg-ecf-card/95 p-2.5 shadow-lg backdrop-blur">
                        <span className="px-1 text-sm font-semibold text-white">{selecionados.size} selecionada(s)</span>
                        <span className="text-white/40 text-xs">Planejar para</span>
                        {diasPlanejaveis.map((d) => (
                            <button key={d.data} type="button" disabled={ocupado} onClick={() => planejar([...selecionados], d.data)}
                                    className="rounded-lg bg-white/[0.06] px-2.5 py-1 text-xs font-semibold text-white/80 transition hover:bg-ecf-yellow hover:text-black disabled:opacity-40">
                                {d.rotulo} {d.dia}
                            </button>
                        ))}
                        <input type="date" value={dataEmMassa} min={hojeStr} onChange={(e) => setDataEmMassa(e.target.value)} aria-label="Outro dia"
                               className="rounded-lg border border-white/[0.1] bg-black/20 px-2 py-1 text-xs text-white/85 outline-none [color-scheme:dark] focus:border-ecf-yellow/40" />
                        <button type="button" disabled={ocupado || !dataEmMassa} onClick={() => planejar([...selecionados], dataEmMassa)}
                                className="rounded-lg bg-ecf-yellow px-2.5 py-1 text-xs font-semibold text-black transition hover:brightness-95 disabled:opacity-40">
                            Aplicar
                        </button>
                        <button type="button" disabled={ocupado} onClick={() => planejar([...selecionados], null)}
                                className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-white/55 hover:text-white disabled:opacity-40">
                            <CalendarX size={13} /> Tirar a data
                        </button>
                        <button type="button" onClick={() => setSelecionados(new Set())}
                                className="ml-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-white/45 hover:text-white">
                            <X size={13} /> Limpar seleção
                        </button>
                    </div>
                )}

                {erro && (
                    <div className="flex items-start gap-2 rounded-xl border border-red-500/25 bg-red-500/[0.06] p-3">
                        <Info size={15} className="text-red-300 mt-0.5 shrink-0" />
                        <p className="text-red-200/90 text-xs">{erro}</p>
                    </div>
                )}

                {/* Quadro */}
                <DndContext sensors={sensors} collisionDetection={closestCorners}
                            onDragStart={({ active }) => setArrastando(active.id)}
                            onDragCancel={() => setArrastando(null)}
                            onDragEnd={aoSoltar}>
                    <div className={cn('grid grid-flow-col auto-cols-[minmax(190px,1fr)] gap-3 overflow-x-auto pb-2', ocupado && 'opacity-70 transition-opacity')}>
                        {colunas.map((c) => (
                            <Coluna key={c.id} coluna={c} podeOperar={podeOperar} selecionados={selecionados}
                                    onAlternar={alternar} onAbrir={abrir} />
                        ))}
                    </div>
                    <DragOverlay dropAnimation={{ duration: 180, easing: 'cubic-bezier(0.2, 0, 0, 1)' }}>
                        {arrastado ? (
                            <div className="relative">
                                <ConteudoCartao c={arrastado} marcado={selecionados.has(arrastado.id)} />
                                {levando > 1 && (
                                    <span className="absolute -right-2 -top-2 rounded-full bg-ecf-yellow px-1.5 text-[11px] font-bold text-black tabular-nums">{levando}</span>
                                )}
                            </div>
                        ) : null}
                    </DragOverlay>
                </DndContext>
            </div>
        </AppLayout>
    );
}

function Coluna({ coluna: c, podeOperar, selecionados, onAlternar, onAbrir }) {
    const { setNodeRef, isOver } = useDroppable({ id: c.id, disabled: !podeOperar || !c.aceita });

    return (
        <section ref={setNodeRef}
                 className={cn('flex min-h-[220px] flex-col rounded-2xl border p-2 transition-colors',
                     c.tom === 'atraso' ? 'border-amber-400/25 bg-amber-400/[0.03]'
                         : c.hoje ? 'border-ecf-yellow/35 bg-ecf-yellow/[0.03]'
                         : 'border-white/[0.08] bg-white/[0.02]',
                     c.passado && 'opacity-60',
                     isOver && 'border-ecf-yellow/60 bg-ecf-yellow/[0.06]')}>
            <header className="mb-2 px-1.5 pt-1">
                <div className="flex items-center gap-1.5">
                    <h2 className={cn('text-[12px] font-bold uppercase tracking-wider',
                        c.tom === 'atraso' ? 'text-amber-300' : c.hoje ? 'text-ecf-yellow' : 'text-white/70')}>{c.titulo}</h2>
                    {c.hoje && <span className="rounded bg-ecf-yellow px-1 text-[9.5px] font-bold uppercase text-black">hoje</span>}
                </div>
                <p className="mt-0.5 text-[11px] text-white/40 tabular-nums">
                    {c.empresas !== undefined
                        ? `${c.empresas} ${c.empresas === 1 ? 'empresa planejada' : 'empresas planejadas'}${c.cartoes.length !== c.empresas ? ` · ${c.cartoes.length} pendências` : ''}`
                        : `${c.cartoes.length} · ${c.sub}`}
                </p>
            </header>
            <div className="flex flex-1 flex-col gap-2">
                {c.cartoes.map((cartao) => (
                    <Cartao key={cartao.id} c={cartao} podeOperar={podeOperar} marcado={selecionados.has(cartao.id)}
                            onAlternar={onAlternar} onAbrir={onAbrir} />
                ))}
                {c.cartoes.length === 0 && (
                    <p className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-white/[0.07] px-2 py-6 text-center text-[11px] text-white/25">
                        {c.aceita && podeOperar ? 'Solte aqui' : 'Nada'}
                    </p>
                )}
            </div>
        </section>
    );
}

function Cartao({ c, podeOperar, marcado, onAlternar, onAbrir }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: c.id, disabled: !podeOperar });

    return (
        <div ref={setNodeRef} {...attributes} {...listeners}
             style={{ touchAction: 'manipulation' }}
             onClick={() => onAbrir(c.id)}
             title={podeOperar ? 'Clique para abrir · arraste para mudar o dia' : 'Clique para abrir'}
             className={cn('cursor-pointer rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/50', isDragging && 'opacity-25')}>
            <ConteudoCartao c={c} marcado={marcado} onAlternar={podeOperar ? onAlternar : null} />
        </div>
    );
}

/** Empresa, tipo, quem pediu, prioridade e prazo — o mesmo desenho no quadro e no fantasma do arraste. */
function ConteudoCartao({ c, marcado = false, onAlternar = null }) {
    return (
        <div className={cn('rounded-xl bg-ecf-card p-2.5 ring-1 ring-inset transition',
            marcado ? 'ring-ecf-yellow/60' : c.depois_do_prazo ? 'ring-red-500/40' : c.para_tras ? 'ring-amber-400/30' : 'ring-white/[0.07]',
            'hover:ring-white/20')}>
            <div className="flex items-start gap-2">
                {onAlternar && (
                    <input type="checkbox" checked={marcado} aria-label={`Selecionar ${c.empresa?.nome}`}
                           onChange={() => onAlternar(c.id)} onClick={(e) => e.stopPropagation()} onPointerDown={(e) => e.stopPropagation()}
                           className="mt-0.5 h-3.5 w-3.5 shrink-0 cursor-pointer rounded border-white/30 bg-transparent text-ecf-yellow focus:ring-0 focus:ring-offset-0" />
                )}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[12.5px] font-semibold text-white" title={c.empresa?.nome}>{c.empresa?.nome}</p>
                    <p className="truncate text-[11px] text-white/55">{c.tipo_label}</p>
                </div>
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-1">
                <SeloPrioridade p={c} />
                {c.prazo && (
                    <span className={cn('rounded px-1.5 py-0.5 text-[10.5px] font-semibold tabular-nums',
                        c.atrasada ? 'bg-red-500/15 text-red-300' : c.depois_do_prazo ? 'bg-red-500/10 text-red-300' : 'bg-white/[0.05] text-white/55')}
                          title={c.depois_do_prazo ? 'Planejada para depois do prazo pedido' : 'Prazo pedido'}>
                        prazo {c.prazo.slice(0, 5)}
                    </span>
                )}
                {c.para_tras && <span className="rounded bg-amber-400/10 px-1.5 py-0.5 text-[10.5px] font-semibold text-amber-300">era {c.data_planejada}</span>}
            </div>
            <p className="mt-1 truncate text-[10.5px] text-white/35">por {c.solicitante?.nome}</p>
        </div>
    );
}
