import { useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Loader2, Plus, Search, Ticket } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { cn } from '@/lib/utils';
import { encerrado, haQuanto, IMPACTOS, TIPOS } from '@/lib/chamados';
import { CampoArquivos, StatusChamado } from '@/Components/Chamados/Partes';

const campo = 'w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13.5px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/30';

// Recortes da lista de quem abriu — linguagem de quem pede ajuda.
const RECORTES = {
    andamento:  { rotulo: 'Em andamento',    casa: (c) => !encerrado(c.status) && c.status !== 'aguardando_solicitante' },
    voce:       { rotulo: 'Aguardando você', casa: (c) => c.status === 'aguardando_solicitante' },
    resolvidos: { rotulo: 'Resolvidos',      casa: (c) => encerrado(c.status) },
    todos:      { rotulo: 'Todos',           casa: () => true },
};

const iniciais = (nome) => nome.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();

/**
 * Central de Tickets — onde qualquer pessoa pede ajuda ao time de desenvolvimento
 * e acompanha o que pediu. Sem prioridade, backlog ou demanda: isso é da equipe.
 */
export default function TicketsIndex({ chamados, devs, areas }) {
    const [abrindo, setAbrindo] = useState(false);
    const [recorte, setRecorte] = useState(() => (chamados.some((c) => c.status === 'aguardando_solicitante') ? 'voce' : 'todos'));
    const [busca, setBusca] = useState('');

    const lista = useMemo(() => {
        const t = busca.trim().toLowerCase();
        return chamados
            .filter(RECORTES[recorte].casa)
            .filter((c) => !t || c.codigo.toLowerCase().includes(t) || c.titulo.toLowerCase().includes(t));
    }, [chamados, recorte, busca]);

    return (
        <AppLayout title="Tickets">
            <div className="mx-auto max-w-[1400px] space-y-6 px-4 py-6 sm:px-6">
                <header className="flex flex-wrap items-end gap-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2.5">
                            <Ticket size={22} className="text-ecf-yellow" />
                            <h1 className="font-display text-xl font-semibold text-white">Tickets</h1>
                        </div>
                        <p className="mt-1 text-[13px] text-white/50">Peça ajuda ao time de desenvolvimento e acompanhe cada pedido até ficar resolvido.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setAbrindo(true)}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2"
                    >
                        <Plus size={15} /> Abrir ticket
                    </button>
                </header>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    {/* ── Meus tickets ── */}
                    <section aria-labelledby="meus-tickets" className="min-w-0 space-y-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 id="meus-tickets" className="font-display text-[17px] font-semibold text-white">Meus tickets</h2>
                            <div className="flex flex-wrap gap-1" role="group" aria-label="Recorte">
                                {Object.entries(RECORTES).map(([k, r]) => {
                                    const n = chamados.filter(r.casa).length;
                                    return (
                                        <button
                                            key={k}
                                            type="button"
                                            aria-pressed={recorte === k}
                                            onClick={() => setRecorte(k)}
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12.5px] transition-colors',
                                                recorte === k ? 'bg-white/[0.08] text-white' : 'text-white/50 hover:text-white',
                                                k === 'voce' && n > 0 && recorte !== k && 'text-orange-400',
                                            )}
                                        >
                                            {r.rotulo} <span className="tabular-nums text-white/40">{n}</span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="relative ml-auto w-full sm:w-64">
                                <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                                <input
                                    value={busca}
                                    onChange={(e) => setBusca(e.target.value)}
                                    placeholder="Buscar por TKT ou título"
                                    className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] py-2 pl-8 pr-3 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none"
                                />
                            </div>
                        </div>

                        {chamados.length === 0 ? (
                            <div className="flex flex-col items-center rounded-xl border border-dashed border-white/[0.08] px-6 py-16 text-center">
                                <Ticket size={28} className="text-white/20" />
                                <p className="mt-3 text-[14px] font-medium text-white/80">Você ainda não abriu nenhum ticket.</p>
                                <p className="mt-1 max-w-sm text-[13px] text-white/50">Deu erro, ficou com dúvida ou precisa de algo novo no sistema? Abra um ticket em vez de mandar mensagem no privado — assim nada se perde.</p>
                                <button type="button" onClick={() => setAbrindo(true)} className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2">
                                    <Plus size={15} /> Abrir meu primeiro ticket
                                </button>
                            </div>
                        ) : lista.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-white/[0.08] px-6 py-10 text-center text-[13px] text-white/50">Nenhum ticket neste recorte.</p>
                        ) : (
                            <div className="overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card">
                                <div className="hidden grid-cols-[minmax(0,1fr)_130px_160px_150px_90px] gap-4 border-b border-white/[0.06] px-4 py-2.5 text-[11.5px] text-white/40 lg:grid">
                                    <span>Ticket</span><span>Área</span><span>Com quem está</span><span>Status</span><span className="text-right">Atualizado</span>
                                </div>
                                <ul className="divide-y divide-white/[0.05]">
                                    {lista.map((c) => (
                                        <li key={c.id}>
                                            <Link
                                                href={route('chamados.show', c.id)}
                                                className={cn(
                                                    'grid gap-x-4 gap-y-1.5 border-l-2 px-4 py-3.5 transition-colors hover:bg-white/[0.03] lg:grid-cols-[minmax(0,1fr)_130px_160px_150px_90px] lg:items-center',
                                                    c.status === 'aguardando_solicitante' ? 'border-orange-400' : 'border-transparent',
                                                )}
                                            >
                                                <span className="min-w-0">
                                                    <span className="flex items-baseline gap-2">
                                                        <span className="shrink-0 text-[12px] font-semibold tabular-nums text-white/45">{c.codigo}</span>
                                                        <span className="truncate text-[14px] font-medium text-white">{c.titulo}</span>
                                                    </span>
                                                    {c.ultima_publica && <span className="mt-0.5 block truncate text-[12.5px] text-white/50">{c.ultima_publica}</span>}
                                                </span>
                                                <span className="text-[12.5px] text-white/60">{c.area ?? '—'}</span>
                                                <span className="flex items-center gap-2 text-[12.5px]">
                                                    {c.responsavel ? (
                                                        <>
                                                            <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-white/[0.06] text-[10px] font-semibold text-white/70">{iniciais(c.responsavel.name)}</span>
                                                            <span className="truncate text-white/75">{c.responsavel.name}</span>
                                                        </>
                                                    ) : <span className="text-white/40">Aguardando a equipe</span>}
                                                </span>
                                                <span><StatusChamado status={c.status} paraSolicitante /></span>
                                                <span className="text-[12px] text-white/40 lg:text-right">{haQuanto(c.ultima_interacao_em)}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>

                    {/* ── Coluna de apoio ── */}
                    <aside className="space-y-4 lg:sticky lg:top-6 lg:self-start">
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                            <h2 className="text-[13.5px] font-semibold text-white">Como funciona</h2>
                            <ol className="mt-3 space-y-3">
                                {[
                                    ['Abra o ticket', 'Conte o que aconteceu ou do que precisa. Um print ajuda muito.'],
                                    ['A equipe responde aqui', 'Você recebe um aviso no sino a cada resposta — não precisa ficar conferindo.'],
                                    ['Acompanhe até resolver', 'Se o problema voltar, é só reabrir o mesmo ticket.'],
                                ].map(([t, d], i) => (
                                    <li key={t} className="grid grid-cols-[24px_1fr] gap-2.5">
                                        <span className="grid h-6 w-6 place-items-center rounded-full bg-ecf-yellow/10 text-[12px] font-semibold text-ecf-yellow">{i + 1}</span>
                                        <span>
                                            <span className="block text-[13px] font-medium text-white/90">{t}</span>
                                            <span className="block text-[12.5px] leading-relaxed text-white/50">{d}</span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </section>

                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                            <h2 className="text-[13.5px] font-semibold text-white">Para ser atendido mais rápido</h2>
                            <ul className="mt-2 space-y-1.5 text-[12.5px] leading-relaxed text-white/60">
                                <li>Diga em qual tela estava e o que clicou.</li>
                                <li>Anexe o print do erro, com a mensagem visível.</li>
                                <li>Um problema por ticket — fica mais fácil de acompanhar.</li>
                            </ul>
                        </section>

                        {devs.length > 0 && (
                            <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                                <h2 className="text-[13.5px] font-semibold text-white">Quem atende</h2>
                                <ul className="mt-3 space-y-2">
                                    {devs.map((d) => (
                                        <li key={d.id} className="flex items-center gap-2.5 text-[13px] text-white/75">
                                            <span className="grid h-7 w-7 place-items-center rounded-full bg-white/[0.06] text-[10.5px] font-semibold text-white/70">{iniciais(d.name)}</span>
                                            {d.name}
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-3 text-[12px] text-white/40">Não sabe quem cuida do assunto? Escolha "Não sei quem deve atender" — a equipe direciona.</p>
                            </section>
                        )}
                    </aside>
                </div>
            </div>

            <Sheet open={abrindo} onOpenChange={setAbrindo}>
                <SheetContent className="max-w-2xl">
                    {abrindo && <NovoTicket devs={devs} areas={areas} onCancelar={() => setAbrindo(false)} />}
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}

function NovoTicket({ devs, areas, onCancelar }) {
    const form = useForm({
        tipo: 'problema',
        area: '',
        responsavel_id: '',
        titulo: '',
        descricao: '',
        contexto_tentando: '',
        contexto_aconteceu: '',
        contexto_esperado: '',
        impacto: 'normal',
        anexos: [],
    });
    const { data, setData, errors, processing } = form;
    const [detalhes, setDetalhes] = useState(false);
    const mostrarContexto = data.tipo === 'problema' || detalhes;

    form.transform((d) => ({ ...d, responsavel_id: d.responsavel_id ? Number(d.responsavel_id) : null, area: d.area || null }));

    const enviar = (e) => {
        e.preventDefault();
        // preserveState: com erro de validação o painel continua aberto, com os erros; no sucesso vai para o ticket.
        form.post(route('chamados.store'), { forceFormData: true, preserveScroll: true, preserveState: true });
    };
    const erroAnexo = errors.anexos || Object.entries(errors).find(([k]) => k.startsWith('anexos.'))?.[1];

    return (
        <form onSubmit={enviar} className="flex h-full min-h-0 flex-col">
            <SheetHeader>
                <SheetTitle className="text-[17px]">Abrir ticket</SheetTitle>
                <SheetDescription>Quem abriu é você — a equipe já sabe quem responder.</SheetDescription>
            </SheetHeader>

            <SheetBody className="space-y-5">
                <fieldset>
                    <legend className="mb-2 text-[12.5px] font-medium text-white/70">Tipo de solicitação</legend>
                    <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                        {Object.entries(TIPOS).map(([v, l]) => (
                            <button
                                key={v}
                                type="button"
                                aria-pressed={data.tipo === v}
                                onClick={() => setData('tipo', v)}
                                className={cn(
                                    'rounded-lg border px-3 py-2 text-left text-[12.5px] transition-colors',
                                    data.tipo === v ? 'border-ecf-yellow/60 bg-ecf-yellow/10 text-white' : 'border-white/[0.08] text-white/60 hover:text-white',
                                )}
                            >
                                {l}
                            </button>
                        ))}
                    </div>
                </fieldset>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Rotulo texto="Área / Projeto" erro={errors.area}>
                        <select value={data.area} onChange={(e) => setData('area', e.target.value)} className={campo}>
                            <option value="">Escolha a área</option>
                            {areas.map((a) => <option key={a} value={a}>{a}</option>)}
                        </select>
                    </Rotulo>
                    <Rotulo texto="Para quem deseja enviar?" erro={errors.responsavel_id}>
                        <select value={data.responsavel_id} onChange={(e) => setData('responsavel_id', e.target.value)} className={campo}>
                            <option value="">Não sei quem deve atender</option>
                            {devs.map((d) => <option key={d.id} value={String(d.id)}>{d.name}</option>)}
                        </select>
                    </Rotulo>
                </div>

                <Rotulo texto="Título" erro={errors.titulo}>
                    <input value={data.titulo} onChange={(e) => setData('titulo', e.target.value)} maxLength={150} placeholder="Ex.: Erro ao cadastrar novo cliente" className={campo} data-autofocus />
                </Rotulo>

                <Rotulo texto="Explique o que aconteceu ou o que você precisa." erro={errors.descricao}>
                    <textarea rows={5} value={data.descricao} onChange={(e) => setData('descricao', e.target.value)} className={campo} />
                </Rotulo>

                {mostrarContexto ? (
                    <div className="space-y-4 rounded-lg border border-white/[0.06] bg-white/[0.02] p-4">
                        <Rotulo texto="O que você estava tentando fazer?" erro={errors.contexto_tentando}>
                            <textarea rows={2} value={data.contexto_tentando} onChange={(e) => setData('contexto_tentando', e.target.value)} className={campo} />
                        </Rotulo>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Rotulo texto="O que aconteceu?" erro={errors.contexto_aconteceu}>
                                <textarea rows={2} value={data.contexto_aconteceu} onChange={(e) => setData('contexto_aconteceu', e.target.value)} className={campo} />
                            </Rotulo>
                            <Rotulo texto="O que você esperava que acontecesse?" erro={errors.contexto_esperado}>
                                <textarea rows={2} value={data.contexto_esperado} onChange={(e) => setData('contexto_esperado', e.target.value)} className={campo} />
                            </Rotulo>
                        </div>
                    </div>
                ) : (
                    <button type="button" onClick={() => setDetalhes(true)} className="text-[12.5px] text-ecf-yellow/80 hover:text-ecf-yellow">
                        Adicionar mais detalhes
                    </button>
                )}

                <fieldset>
                    <legend className="mb-2 text-[12.5px] font-medium text-white/70">Qual o impacto?</legend>
                    <div className="grid gap-1.5 sm:grid-cols-2">
                        {Object.entries(IMPACTOS).map(([v, l]) => (
                            <label
                                key={v}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-[13px] transition-colors',
                                    data.impacto === v ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.06] text-white' : 'border-white/[0.08] text-white/70 hover:text-white',
                                )}
                            >
                                <input type="radio" name="impacto" value={v} checked={data.impacto === v} onChange={() => setData('impacto', v)} className="h-3.5 w-3.5 border-white/30 bg-transparent text-ecf-yellow focus:ring-ecf-yellow/40" />
                                {l}
                            </label>
                        ))}
                    </div>
                    {errors.impacto && <p className="mt-1 text-[11.5px] text-red-400">{errors.impacto}</p>}
                </fieldset>

                <CampoArquivos arquivos={data.anexos} onChange={(v) => setData('anexos', v)} erro={erroAnexo} />
            </SheetBody>

            <SheetFooter className="flex items-center justify-end gap-2">
                <button type="button" onClick={onCancelar} className="rounded-lg px-3.5 py-2 text-[13px] text-white/60 hover:bg-white/[0.04] hover:text-white">
                    Cancelar
                </button>
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2 disabled:opacity-60"
                >
                    {processing && <Loader2 size={14} className="animate-spin" />}
                    Enviar ticket
                </button>
            </SheetFooter>
        </form>
    );
}

function Rotulo({ texto, erro, children }) {
    return (
        <label className="block space-y-1.5">
            <span className="text-[12.5px] font-medium text-white/70">{texto}</span>
            {children}
            {erro && <span className="block text-[11.5px] text-red-400">{erro}</span>}
        </label>
    );
}
