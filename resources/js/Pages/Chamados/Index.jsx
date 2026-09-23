import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { LifeBuoy, Loader2, Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { haQuanto, IMPACTOS, TIPOS } from '@/lib/chamados';
import { CampoArquivos, StatusChamado } from '@/Components/Chamados/Partes';

const campo = 'w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13.5px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/30';

/**
 * Central de Chamados — onde qualquer pessoa pede ajuda ao time de desenvolvimento
 * e acompanha o que pediu. Linguagem de quem pede: nada de prioridade, backlog ou demanda.
 */
export default function ChamadosIndex({ chamados, devs, areas }) {
    const [abrindo, setAbrindo] = useState(chamados.length === 0);

    return (
        <AppLayout title="Chamados">
            <div className="mx-auto max-w-3xl space-y-8 px-4 py-6 sm:px-6">
                <header className="flex flex-wrap items-center gap-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2.5">
                            <LifeBuoy size={22} className="text-ecf-yellow" />
                            <h1 className="font-display text-xl font-semibold text-white">Central de Chamados</h1>
                        </div>
                        <p className="mt-1 text-[13px] text-white/50">Precisa de ajuda do time de desenvolvimento? Abra um chamado e acompanhe tudo por aqui.</p>
                    </div>
                    {!abrindo && (
                        <button
                            type="button"
                            onClick={() => setAbrindo(true)}
                            className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2"
                        >
                            <Plus size={15} /> Abrir chamado
                        </button>
                    )}
                </header>

                {abrindo && <NovoChamado devs={devs} areas={areas} onCancelar={chamados.length ? () => setAbrindo(false) : null} />}

                <section aria-labelledby="meus-chamados">
                    <h2 id="meus-chamados" className="font-display text-[17px] font-semibold text-white">Meus chamados</h2>
                    {chamados.length === 0 ? (
                        <p className="mt-2 text-[13px] text-white/40">Você ainda não abriu nenhum chamado.</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-white/[0.06] overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card">
                            {chamados.map((c) => (
                                <li key={c.id}>
                                    <Link href={route('chamados.show', c.id)} className="block px-4 py-3.5 transition-colors hover:bg-white/[0.03]">
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span className="text-[12px] font-semibold tabular-nums text-white/50">{c.codigo}</span>
                                            <span className="min-w-0 flex-1 truncate text-[14px] font-medium text-white">{c.titulo}</span>
                                            <StatusChamado status={c.status} paraSolicitante />
                                        </div>
                                        <p className="mt-1 text-[12.5px] text-white/50">
                                            {c.responsavel ? `Com ${c.responsavel.name}` : 'Aguardando a equipe'}
                                            {c.area && <> — {c.area}</>}
                                            <span className="text-white/30"> · aberto {haQuanto(c.criado_em)}</span>
                                        </p>
                                        {c.ultima_publica && <p className="mt-1 line-clamp-1 text-[12.5px] text-white/60">“{c.ultima_publica}”</p>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function NovoChamado({ devs, areas, onCancelar }) {
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
        form.post(route('chamados.store'), { forceFormData: true, preserveScroll: true });
    };

    const erroAnexo = errors.anexos || Object.entries(errors).find(([k]) => k.startsWith('anexos.'))?.[1];

    return (
        <form onSubmit={enviar} className="space-y-5 rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-5">
            <h2 className="font-display text-[17px] font-semibold text-white">Abrir chamado</h2>

            <fieldset>
                <legend className="mb-2 text-[12.5px] font-medium text-white/70">Tipo de solicitação</legend>
                <div className="flex flex-wrap gap-1.5">
                    {Object.entries(TIPOS).map(([v, l]) => (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={data.tipo === v}
                            onClick={() => setData('tipo', v)}
                            className={cn(
                                'rounded-lg border px-3 py-1.5 text-[12.5px] transition-colors',
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
                <input value={data.titulo} onChange={(e) => setData('titulo', e.target.value)} maxLength={150} placeholder="Ex.: Erro ao cadastrar novo cliente" className={campo} />
            </Rotulo>

            <Rotulo texto="Explique o que aconteceu ou o que você precisa." erro={errors.descricao}>
                <textarea rows={5} value={data.descricao} onChange={(e) => setData('descricao', e.target.value)} className={campo} />
            </Rotulo>

            {mostrarContexto ? (
                <div className="grid gap-4 sm:grid-cols-3">
                    <Rotulo texto="O que você estava tentando fazer?" erro={errors.contexto_tentando}>
                        <textarea rows={3} value={data.contexto_tentando} onChange={(e) => setData('contexto_tentando', e.target.value)} className={campo} />
                    </Rotulo>
                    <Rotulo texto="O que aconteceu?" erro={errors.contexto_aconteceu}>
                        <textarea rows={3} value={data.contexto_aconteceu} onChange={(e) => setData('contexto_aconteceu', e.target.value)} className={campo} />
                    </Rotulo>
                    <Rotulo texto="O que você esperava que acontecesse?" erro={errors.contexto_esperado}>
                        <textarea rows={3} value={data.contexto_esperado} onChange={(e) => setData('contexto_esperado', e.target.value)} className={campo} />
                    </Rotulo>
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

            <div className="flex items-center justify-end gap-2 border-t border-white/[0.06] pt-4">
                {onCancelar && (
                    <button type="button" onClick={onCancelar} className="rounded-lg px-3.5 py-2 text-[13px] text-white/60 hover:bg-white/[0.04] hover:text-white">
                        Cancelar
                    </button>
                )}
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2 disabled:opacity-60"
                >
                    {processing && <Loader2 size={14} className="animate-spin" />}
                    Enviar chamado
                </button>
            </div>
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
