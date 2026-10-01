import { router } from '@inertiajs/react';
import { CalendarClock, CalendarPlus, Trash2 } from 'lucide-react';
import { Botao, fmtData, fmtDiaSemana } from './comum';
import { cn } from '@/lib/utils';

// ─── A agenda desta oferta ──────────────────────────────────────────────────
//
// As publicações e Jardinagens marcadas para a oferta em foco, com as ações
// da aba Agenda (concluir o lado que falta, marcar a Jardinagem feita,
// remarcar, remover) — sem sair da estação.

export default function EstacaoAgenda({ oferta, vocabulario, onAgendar, onConcluir }) {
    const opcoes = { preserveScroll: true, preserveState: true };
    const marcar = (item, feita) => router.patch(route('portal.auth.estrutura.agenda.jardinagem', item.id), { feita }, opcoes);
    const remarcar = (item, data) => data && router.patch(route('portal.auth.estrutura.agenda.remarcar', item.id), { data }, opcoes);
    const remover = (item) => router.delete(route('portal.auth.estrutura.agenda.excluir', item.id), opcoes);

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4" data-agenda-oferta>
            <div className="flex items-center justify-between gap-2">
                <h4 className="text-[13.5px] font-semibold text-white">Agenda</h4>
                <Botao variante="fantasma" className="px-2 py-1 text-[12.5px]" onClick={onAgendar} data-acao="agendar-estacao"><CalendarPlus size={14} /> Agendar</Botao>
            </div>

            {oferta.agenda.length === 0 ? (
                <p className="mt-2 text-[12.5px] text-white/40">Nada agendado para esta oferta.</p>
            ) : (
                <ul className="mt-2 divide-y divide-white/[0.05]">
                    {oferta.agenda.map((i) => {
                        const publicacao = i.acao === 'publicacao';

                        return (
                            <li key={i.id} className={cn('flex flex-wrap items-center gap-x-4 gap-y-2 py-2.5', i.feita && 'opacity-60')} data-agenda-item={i.id} data-feita={i.feita ? '1' : '0'}>
                                <div className="w-16 shrink-0">
                                    <p className="text-[13px] text-white">{fmtData(i.data)}</p>
                                    <p className="text-[11px] text-white/40">{fmtDiaSemana(i.data)}</p>
                                </div>
                                <span className={cn('text-[12px] font-semibold', publicacao ? 'text-sky-300' : 'text-emerald-300')}>{vocabulario.acoes[i.acao]}</span>

                                {publicacao ? (
                                    i.feita
                                        ? <span className="text-[12px] text-emerald-300">✓ publicada</span>
                                        : (
                                            <span className="flex flex-wrap gap-1.5">
                                                {oferta.classicos === 0 && <Botao className="px-2 py-1 text-[12px]" onClick={() => onConcluir('classico')} data-acao="concluir-classico">Concluir {vocabulario.tipos_curtos.classico}</Botao>}
                                                {oferta.premiums === 0 && <Botao className="px-2 py-1 text-[12px]" onClick={() => onConcluir('premium')} data-acao="concluir-premium">Concluir {vocabulario.tipos_curtos.premium}</Botao>}
                                            </span>
                                        )
                                ) : (
                                    <label className="inline-flex items-center gap-2 text-[12.5px] text-white/75">
                                        <input type="checkbox" checked={i.feita} onChange={(e) => marcar(i, e.target.checked)} data-acao="jardinagem" />
                                        Feita — métricas olhadas e anúncio ajustado
                                    </label>
                                )}

                                <span className="ml-auto flex items-center gap-1">
                                    {! i.feita && (
                                        <label className="relative p-1 text-white/35 hover:text-white cursor-pointer" title="Remarcar">
                                            <CalendarClock size={14} />
                                            <input type="date" className="absolute inset-0 cursor-pointer opacity-0" aria-label="Remarcar" defaultValue={i.data} onChange={(e) => remarcar(i, e.target.value)} />
                                        </label>
                                    )}
                                    <button type="button" onClick={() => remover(i)} className="p-1 text-white/35 hover:text-red-300" aria-label="Remover da agenda"><Trash2 size={14} /></button>
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
