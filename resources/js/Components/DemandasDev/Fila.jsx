import { useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, ChevronDown, CircleDot, Lock, PenLine } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtData, ordenarFila, STATUS_EM_ANDAMENTO, textoPrazo } from '@/lib/demandasDev';
import { PrioridadeSelo, SituacaoSelo, StatusSelo } from './Selos';

// As regras da aba "Minha Semana" da planilha — como escolher o que fazer agora.
const REGRAS = [
    ['Bloqueio primeiro.', 'Se você tem demanda bloqueada, a primeira tarefa do dia é destravá-la: cobrar, escalar ou achar um caminho alternativo.'],
    ['Depois P0, depois P1.', 'Prioridade vence data de entrada. Nada de P2 enquanto existir P0 ou P1 aberta com você.'],
    ['Uma demanda principal por vez.', 'Cada dev mantém, de preferência, só UMA demanda em "Em desenvolvimento".'],
    ['Validação antes de puxar nova.', 'O que está "Em validação" precisa fechar antes de começar a próxima — validação parada é trabalho feito que ainda não virou entrega.'],
    ['Só então a próxima da fila.', 'Depois de liberar a principal é que se inicia a próxima.'],
    ['Todo dia, ao fim do expediente, uma atualização.', 'É isso que mantém esta fila correta.'],
];

export default function Fila({ demandas, usuarios, eu, pode, hoje, onAbrir, onAtualizar }) {
    // Admin escolhe de quem é a fila (padrão: a própria, se tiver demandas). Quem não é admin vê só a sua.
    const [quem, setQuem] = useState(pode.gerenciar && !eu.tem_demandas ? 'todos' : String(eu.id));
    const [regrasAbertas, setRegrasAbertas] = useState(false);

    const fila = useMemo(() => ordenarFila(demandas.filter((d) =>
        !d.encerrada && (quem === 'todos' || String(d.responsavel?.id ?? '') === quem))), [demandas, quem]);

    const emAndamento = fila.filter((d) => STATUS_EM_ANDAMENTO.includes(d.status));
    const atualizadasHoje = emAndamento.filter((d) => d.ultima_atualizacao === hoje).length;
    const emDev = fila.filter((d) => d.status === 'em_desenvolvimento').length;
    const mostrarResponsavel = quem === 'todos';

    // Só oferece no seletor quem tem demanda aberta.
    const comDemanda = useMemo(() => {
        const ids = new Set(demandas.filter((d) => !d.encerrada && d.responsavel).map((d) => d.responsavel.id));
        return usuarios.filter((u) => ids.has(u.id));
    }, [demandas, usuarios]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                {pode.gerenciar && (
                    <select
                        value={quem}
                        onChange={(e) => setQuem(e.target.value)}
                        className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/50 focus:outline-none"
                    >
                        <option value="todos">Todo mundo</option>
                        {comDemanda.map((u) => <option key={u.id} value={String(u.id)}>{u.id === eu.id ? `${u.name} (eu)` : u.name}</option>)}
                    </select>
                )}
                <span className="text-[12.5px] text-white/50">
                    {fila.length} aberta{fila.length === 1 ? '' : 's'} · trabalhe de cima para baixo
                </span>

                {emAndamento.length > 0 && (
                    <span className={cn(
                        'ml-auto inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px]',
                        atualizadasHoje === emAndamento.length ? 'bg-emerald-500/10 text-emerald-400' : 'bg-white/[0.04] text-white/60',
                    )}>
                        {atualizadasHoje === emAndamento.length ? <CheckCircle2 size={13} /> : <CircleDot size={13} />}
                        Atualizadas hoje: {atualizadasHoje} de {emAndamento.length} em andamento
                    </span>
                )}
            </div>

            {!mostrarResponsavel && emDev > 1 && (
                <div className="flex items-start gap-2.5 rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-4 py-2.5 text-[12.5px] text-amber-300">
                    <AlertTriangle size={15} className="mt-0.5 shrink-0" />
                    {emDev} demandas em desenvolvimento ao mesmo tempo. A regra é uma principal por vez — feche ou devolva as outras para "A fazer".
                </div>
            )}

            {/* Regras de escolha — recolhidas por padrão */}
            <div className="rounded-xl border border-white/[0.06] bg-white/[0.02]">
                <button type="button" onClick={() => setRegrasAbertas((v) => !v)} className="flex w-full items-center gap-2 px-4 py-2.5 text-left">
                    <span className="text-[12px] font-semibold uppercase tracking-wider text-white/50">Como escolher o que fazer agora</span>
                    <ChevronDown size={15} className={cn('ml-auto text-white/40 transition-transform', regrasAbertas && 'rotate-180')} />
                </button>
                {regrasAbertas && (
                    <ol className="grid gap-x-6 gap-y-2 px-4 pb-4 sm:grid-cols-2">
                        {REGRAS.map(([titulo, texto], i) => (
                            <li key={i} className="flex gap-2.5 text-[12.5px] leading-relaxed text-white/60">
                                <span className="font-mono text-white/30">{i + 1}.</span>
                                <span><strong className="font-semibold text-white/80">{titulo}</strong> {texto}</span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>

            {fila.length === 0 ? (
                <div className="rounded-xl border border-dashed border-white/[0.08] px-6 py-12 text-center text-[13px] text-white/50">
                    Nenhuma demanda aberta nesta fila.
                </div>
            ) : (
                <ol className="overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card divide-y divide-white/[0.06]">
                    {fila.map((d, i) => (
                        <li
                            key={d.id}
                            onClick={() => onAbrir(d.id)}
                            className={cn(
                                'group grid cursor-pointer grid-cols-[28px_minmax(0,1fr)] items-start gap-x-3 gap-y-2 px-4 py-3.5 transition-colors hover:bg-white/[0.03] lg:grid-cols-[36px_minmax(0,1.6fr)_150px_minmax(0,1.4fr)_auto] lg:items-center',
                                i === 0 && 'bg-ecf-yellow/[0.03]',
                            )}
                        >
                            <span className={cn('row-span-3 pt-0.5 font-display text-[20px] font-bold leading-none tabular-nums lg:row-span-1 lg:pt-0', i === 0 ? 'text-ecf-yellow' : 'text-white/25')}>
                                {i + 1}
                            </span>

                            {/* Demanda */}
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-[11.5px] text-white/40">{d.codigo}</span>
                                    <PrioridadeSelo prioridade={d.prioridade} />
                                    {d.area && <span className="truncate text-[11.5px] text-white/40">{d.area}</span>}
                                </div>
                                <div className="mt-0.5 truncate text-[14px] font-medium text-white">{d.titulo}</div>
                                <div className="mt-1 flex items-center gap-3">
                                    <StatusSelo status={d.status} />
                                    {mostrarResponsavel && <span className="truncate text-[12px] text-white/40">{d.responsavel?.name ?? 'Sem responsável'}</span>}
                                </div>
                            </div>

                            {/* Prazo + situação */}
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 lg:block lg:space-y-1">
                                <SituacaoSelo situacao={d.situacao} />
                                <div className={cn('text-[12px]', d.situacao === 'atrasada' ? 'text-red-400' : 'text-white/50')}>
                                    {textoPrazo(d, hoje)}{d.prazo && !d.encerrada && <span className="text-white/30"> · {fmtData(d.prazo, hoje)}</span>}
                                </div>
                            </div>

                            {/* Próxima ação / bloqueio */}
                            <div className="min-w-0">
                                {d.bloqueado ? (
                                    <div className="flex items-start gap-1.5 text-[12.5px] text-orange-400">
                                        <Lock size={13} className="mt-0.5 shrink-0" />
                                        <span className="line-clamp-2">{d.motivo_bloqueio || 'Bloqueada — sem motivo registrado'}</span>
                                    </div>
                                ) : null}
                                <div className={cn('line-clamp-2 text-[12.5px]', d.proxima_acao ? 'text-white/70' : 'italic text-white/30', d.bloqueado && 'mt-0.5')}>
                                    {d.proxima_acao || 'Registrar 1ª atualização'}
                                </div>
                                <div className="mt-0.5 flex items-center justify-between gap-2">
                                    <span className="text-[11px] text-white/30">
                                        {d.ultima_atualizacao ? `atualizada ${d.ultima_atualizacao === hoje ? 'hoje' : 'em ' + fmtData(d.ultima_atualizacao, hoje)}` : 'nunca atualizada'}
                                    </span>
                                    {/* No celular o botão mora aqui; no desktop, na última coluna. */}
                                    <AtualizarBotao demanda={d} eu={eu} pode={pode} onAtualizar={onAtualizar} className="lg:hidden" />
                                </div>
                            </div>

                            <AtualizarBotao demanda={d} eu={eu} pode={pode} onAtualizar={onAtualizar} className="hidden lg:inline-flex" />
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

function AtualizarBotao({ demanda, eu, pode, onAtualizar, className }) {
    if (!pode.gerenciar && demanda.responsavel?.id !== eu.id) return null;
    return (
        <button
            type="button"
            onClick={(e) => { e.stopPropagation(); onAtualizar(demanda); }}
            className={cn(
                'inline-flex items-center gap-1.5 justify-self-end rounded-lg border border-white/[0.08] px-2.5 py-1.5 text-[12.5px] text-white/70 transition-colors hover:border-ecf-yellow/50 hover:text-ecf-yellow',
                className,
            )}
        >
            <PenLine size={13} />
            Atualizar
        </button>
    );
}
