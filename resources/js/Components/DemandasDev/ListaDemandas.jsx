import { useMemo, useState } from 'react';
import { Lock, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    compararCodigo, fmtData, PRIORIDADE_LABELS, SITUACAO_LABELS, SITUACAO_ORDEM, STATUS_LABELS, textoPrazo,
} from '@/lib/demandasDev';
import { PrioridadeSelo, SituacaoSelo, StatusSelo } from './Selos';

const FILTRO_VAZIO = { busca: '', responsavel: '', area: '', status: '', prioridade: '', situacao: '', encerradas: false };

const selectClasse =
    'rounded-lg border border-white/[0.08] bg-white/[0.03] px-2.5 py-2 text-[12.5px] text-white focus:border-ecf-yellow/50 focus:outline-none';

export default function ListaDemandas({ demandas, pode, hoje, onAbrir }) {
    const [f, setF] = useState(FILTRO_VAZIO);
    const set = (k, v) => setF((atual) => ({ ...atual, [k]: v }));

    const responsaveis = useMemo(() => {
        const m = new Map();
        demandas.forEach((d) => d.responsavel && m.set(d.responsavel.id, d.responsavel.name));
        return [...m.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [demandas]);
    const areas = useMemo(() => [...new Set(demandas.map((d) => d.area).filter(Boolean))].sort(), [demandas]);

    const lista = useMemo(() => {
        const t = f.busca.trim().toLowerCase();
        return demandas
            // Encerradas só aparecem quando pedidas — ou quando o filtro de status/situação já as escolhe.
            .filter((d) => f.encerradas || f.status || ['concluido', 'cancelado'].includes(f.situacao) || !d.encerrada)
            .filter((d) => !t || d.codigo.toLowerCase().includes(t) || d.titulo.toLowerCase().includes(t) || (d.escopo ?? '').toLowerCase().includes(t))
            .filter((d) => !f.responsavel || String(d.responsavel?.id ?? 'nenhum') === f.responsavel)
            .filter((d) => !f.area || d.area === f.area)
            .filter((d) => !f.status || d.status === f.status)
            .filter((d) => f.prioridade === '' || String(d.prioridade) === f.prioridade)
            .filter((d) => !f.situacao || d.situacao === f.situacao)
            .sort((a, b) => compararCodigo(a.codigo, b.codigo));
    }, [demandas, f]);

    const filtrando = JSON.stringify(f) !== JSON.stringify(FILTRO_VAZIO);
    const encerradasOcultas = !f.encerradas && !f.status && !['concluido', 'cancelado'].includes(f.situacao)
        ? demandas.filter((d) => d.encerrada).length
        : 0;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                    <input
                        value={f.busca}
                        onChange={(e) => set('busca', e.target.value)}
                        placeholder="Buscar por código, demanda ou escopo"
                        className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] py-2 pl-8 pr-3 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none"
                    />
                </div>
                {pode.gerenciar && (
                    <select value={f.responsavel} onChange={(e) => set('responsavel', e.target.value)} className={selectClasse}>
                        <option value="">Responsável</option>
                        {responsaveis.map(([id, nome]) => <option key={id} value={String(id)}>{nome}</option>)}
                        <option value="nenhum">Sem responsável</option>
                    </select>
                )}
                <select value={f.area} onChange={(e) => set('area', e.target.value)} className={selectClasse}>
                    <option value="">Área</option>
                    {areas.map((a) => <option key={a} value={a}>{a}</option>)}
                </select>
                <select value={f.prioridade} onChange={(e) => set('prioridade', e.target.value)} className={selectClasse}>
                    <option value="">Prioridade</option>
                    {Object.entries(PRIORIDADE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <select value={f.status} onChange={(e) => set('status', e.target.value)} className={selectClasse}>
                    <option value="">Status</option>
                    {Object.entries(STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <select value={f.situacao} onChange={(e) => set('situacao', e.target.value)} className={selectClasse}>
                    <option value="">Situação</option>
                    {SITUACAO_ORDEM.map((s) => <option key={s} value={s}>{SITUACAO_LABELS[s]}</option>)}
                </select>
                <label className="flex cursor-pointer items-center gap-2 px-1 text-[12.5px] text-white/60">
                    <input
                        type="checkbox"
                        checked={f.encerradas}
                        onChange={(e) => set('encerradas', e.target.checked)}
                        className="h-3.5 w-3.5 rounded border-white/20 bg-transparent text-ecf-yellow focus:ring-ecf-yellow/40"
                    />
                    Encerradas
                </label>
                {filtrando && (
                    <button type="button" onClick={() => setF(FILTRO_VAZIO)} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-[12px] text-white/50 hover:text-white">
                        <X size={13} /> Limpar
                    </button>
                )}
            </div>

            <div className="text-[12px] text-white/40">
                {lista.length} demanda{lista.length === 1 ? '' : 's'}
                {encerradasOcultas > 0 && ` · ${encerradasOcultas} encerrada${encerradasOcultas === 1 ? '' : 's'} oculta${encerradasOcultas === 1 ? '' : 's'}`}
            </div>

            <div className="overflow-x-auto rounded-xl border border-white/[0.08] bg-ecf-card">
                <table className="w-full min-w-[980px] text-left">
                    <thead>
                        <tr className="border-b border-white/[0.06] text-[11px] uppercase tracking-wider text-white/40">
                            <th className="px-4 py-2.5 font-medium">Código</th>
                            <th className="px-3 py-2.5 font-medium">Demanda</th>
                            <th className="px-3 py-2.5 font-medium">Responsável</th>
                            <th className="px-3 py-2.5 font-medium">Prior.</th>
                            <th className="px-3 py-2.5 font-medium">Status</th>
                            <th className="px-3 py-2.5 font-medium">Prazo</th>
                            <th className="px-3 py-2.5 font-medium">Próxima ação</th>
                            <th className="px-4 py-2.5 text-right font-medium">Atualizada</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/[0.04]">
                        {lista.map((d) => (
                            <tr key={d.id} onClick={() => onAbrir(d.id)} className={cn('cursor-pointer align-top transition-colors hover:bg-white/[0.03]', d.encerrada && 'opacity-60')}>
                                <td className="whitespace-nowrap px-4 py-3 font-mono text-[12px] text-white/50">{d.codigo}</td>
                                <td className="max-w-[340px] px-3 py-3">
                                    <div className="truncate text-[13.5px] text-white">{d.titulo}</div>
                                    {d.area && <div className="text-[11.5px] text-white/40">{d.area}</div>}
                                </td>
                                <td className="whitespace-nowrap px-3 py-3 text-[12.5px] text-white/70">{d.responsavel?.name ?? <span className="text-white/30">—</span>}</td>
                                <td className="px-3 py-3"><PrioridadeSelo prioridade={d.prioridade} /></td>
                                <td className="px-3 py-3"><StatusSelo status={d.status} /></td>
                                <td className="whitespace-nowrap px-3 py-3">
                                    <SituacaoSelo situacao={d.situacao} />
                                    <div className="mt-1 text-[11.5px] text-white/40">{textoPrazo(d, hoje)}</div>
                                </td>
                                <td className="max-w-[280px] px-3 py-3">
                                    {d.bloqueado && (
                                        <div className="flex items-start gap-1 text-[12px] text-orange-400">
                                            <Lock size={12} className="mt-0.5 shrink-0" />
                                            <span className="line-clamp-1">{d.motivo_bloqueio || 'Bloqueada'}</span>
                                        </div>
                                    )}
                                    <div className={cn('line-clamp-2 text-[12.5px]', d.proxima_acao ? 'text-white/70' : 'italic text-white/30')}>
                                        {d.proxima_acao || 'Registrar 1ª atualização'}
                                    </div>
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-right text-[12px] text-white/50">
                                    {fmtData(d.ultima_atualizacao, hoje)}
                                    {d.total_atualizacoes > 0 && <div className="text-[11px] text-white/30">{d.total_atualizacoes} registro{d.total_atualizacoes === 1 ? '' : 's'}</div>}
                                </td>
                            </tr>
                        ))}
                        {lista.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-10 text-center text-[13px] text-white/40">Nenhuma demanda com esses filtros.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
