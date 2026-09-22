import { useState } from 'react';
import { ExternalLink, FileText, Pencil, PlayCircle, Plus, Users } from 'lucide-react';
import { fmtData } from '@/lib/demandasDev';

export default function Reunioes({ reunioes, pode, hoje, onAbrir, onNova, onEditar }) {
    const [expandida, setExpandida] = useState(null);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
                <p className="text-[12.5px] text-white/50">
                    Dúvida sobre o que foi combinado? Procure aqui pelo código da demanda antes de perguntar.
                </p>
                {pode.gerenciar && (
                    <button
                        type="button"
                        onClick={onNova}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-white/[0.08] px-3 py-1.5 text-[12.5px] text-white/80 hover:border-ecf-yellow/50 hover:text-ecf-yellow"
                    >
                        <Plus size={14} /> Registrar reunião
                    </button>
                )}
            </div>

            {reunioes.length === 0 && (
                <div className="rounded-xl border border-dashed border-white/[0.08] px-6 py-12 text-center text-[13px] text-white/50">
                    Nenhuma reunião registrada.
                </div>
            )}

            {reunioes.map((r) => {
                const longa = (r.decisoes ?? '').length > 320;
                const aberta = expandida === r.id;
                return (
                    <article key={r.id} className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                        <div className="flex flex-wrap items-start gap-x-4 gap-y-2">
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 text-[12px] text-white/40">
                                    <span className="font-mono">{fmtData(r.data, hoje)}</span>
                                    {r.duracao && <span>· {r.duracao}</span>}
                                </div>
                                <h3 className="mt-0.5 text-[15px] font-semibold text-white">{r.titulo}</h3>
                                {r.participantes && (
                                    <div className="mt-1 flex items-center gap-1.5 text-[12px] text-white/50">
                                        <Users size={13} /> {r.participantes}
                                    </div>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                {r.link_gravacao && (
                                    <a href={r.link_gravacao} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-lg bg-white/[0.04] px-2.5 py-1.5 text-[12px] text-white/70 hover:text-ecf-yellow">
                                        <PlayCircle size={14} /> Gravação <ExternalLink size={11} className="text-white/30" />
                                    </a>
                                )}
                                {r.link_transcricao && (
                                    <a href={r.link_transcricao} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-lg bg-white/[0.04] px-2.5 py-1.5 text-[12px] text-white/70 hover:text-ecf-yellow">
                                        <FileText size={14} /> Transcrição <ExternalLink size={11} className="text-white/30" />
                                    </a>
                                )}
                                {pode.gerenciar && (
                                    <button type="button" onClick={() => onEditar(r)} title="Editar" className="grid h-8 w-8 place-items-center rounded-lg text-white/40 hover:bg-white/[0.04] hover:text-white">
                                        <Pencil size={14} />
                                    </button>
                                )}
                            </div>
                        </div>

                        {r.decisoes && (
                            <div className="mt-3">
                                <div className="text-[11px] font-semibold uppercase tracking-wider text-white/40">Decisões / combinados</div>
                                <p className={`mt-1 whitespace-pre-line text-[13px] leading-relaxed text-white/70 ${longa && !aberta ? 'line-clamp-4' : ''}`}>
                                    {r.decisoes}
                                </p>
                                {longa && (
                                    <button type="button" onClick={() => setExpandida(aberta ? null : r.id)} className="mt-1 text-[12px] text-ecf-yellow/80 hover:text-ecf-yellow">
                                        {aberta ? 'Mostrar menos' : 'Ler tudo'}
                                    </button>
                                )}
                            </div>
                        )}

                        {r.demandas.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {r.demandas.map((d) => (
                                    <button
                                        key={d.id}
                                        type="button"
                                        onClick={() => onAbrir(d.id)}
                                        title={d.titulo}
                                        className="rounded bg-white/[0.04] px-1.5 py-0.5 font-mono text-[11px] text-white/60 hover:bg-ecf-yellow/10 hover:text-ecf-yellow"
                                    >
                                        {d.codigo}
                                    </button>
                                ))}
                            </div>
                        )}
                    </article>
                );
            })}
        </div>
    );
}
