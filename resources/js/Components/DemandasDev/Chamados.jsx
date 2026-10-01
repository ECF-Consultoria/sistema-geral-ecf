import { useMemo, useState } from 'react';
import { Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { casaBusca, FILTROS_RAPIDOS, haQuanto, IMPACTO_CURTO, STATUS_CHAMADO, TIPOS } from '@/lib/chamados';
import { StatusChamado } from '@/Components/Chamados/Partes';

const VAZIO = { busca: '', responsavel: '', area: '', status: '', tipo: '', desde: '' };
const select = 'rounded-lg border border-white/[0.08] bg-white/[0.03] px-2.5 py-2 text-[12.5px] text-white focus:border-ecf-yellow/50 focus:outline-none';

// Impacto alto ganha cor; o resto fica neutro para não virar árvore de Natal.
const IMPACTO_CLASSE = {
    varias_pessoas: 'text-red-400',
    impedido:       'text-orange-400',
    atrapalha:      'text-white/70',
    normal:         'text-white/40',
};

/** Caixa de entrada de chamados da equipe dev. */
export default function Chamados({ chamados, eu, onAbrir }) {
    const temMeus = chamados.some((c) => c.responsavel?.id === eu.id);
    const [rapido, setRapido] = useState(temMeus ? 'meus' : 'todos');
    const [f, setF] = useState(VAZIO);
    const set = (k, v) => setF((a) => ({ ...a, [k]: v }));

    const responsaveis = useMemo(() => {
        const m = new Map();
        chamados.forEach((c) => c.responsavel && m.set(c.responsavel.id, c.responsavel.name));
        return [...m.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [chamados]);
    const areas = useMemo(() => [...new Set(chamados.map((c) => c.area).filter(Boolean))].sort(), [chamados]);

    const lista = useMemo(() => chamados
        .filter((c) => FILTROS_RAPIDOS[rapido].casa(c, eu.id))
        .filter((c) => casaBusca(c, f.busca))
        .filter((c) => !f.responsavel || String(c.responsavel?.id ?? 'fila') === f.responsavel)
        .filter((c) => !f.area || c.area === f.area)
        .filter((c) => !f.status || c.status === f.status)
        .filter((c) => !f.tipo || c.tipo === f.tipo)
        .filter((c) => !f.desde || c.criado_em.slice(0, 10) >= f.desde)
        // Quem precisa da equipe sobe; depois, o mais recente.
        .sort((a, b) => (b.precisa_atencao - a.precisa_atencao) || b.ultima_interacao_em.localeCompare(a.ultima_interacao_em)),
    [chamados, rapido, f, eu.id]);

    const contagem = (chave) => chamados.filter((c) => FILTROS_RAPIDOS[chave].casa(c, eu.id)).length;
    const filtrando = JSON.stringify(f) !== JSON.stringify(VAZIO);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-1.5" role="group" aria-label="Filtro rápido">
                {Object.entries(FILTROS_RAPIDOS).map(([k, { rotulo }]) => (
                    <button
                        key={k}
                        type="button"
                        aria-pressed={rapido === k}
                        onClick={() => setRapido(k)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] transition-colors',
                            rapido === k ? 'border-ecf-yellow/50 bg-ecf-yellow/10 text-white' : 'border-white/[0.08] text-white/60 hover:text-white',
                        )}
                    >
                        {rotulo} <span className="tabular-nums text-white/40">{contagem(k)}</span>
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                    <input
                        value={f.busca}
                        onChange={(e) => set('busca', e.target.value)}
                        placeholder="Buscar por TKT, título ou quem abriu"
                        className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] py-2 pl-8 pr-3 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none"
                    />
                </div>
                <select value={f.responsavel} onChange={(e) => set('responsavel', e.target.value)} className={select} aria-label="Responsável">
                    <option value="">Responsável</option>
                    <option value="fila">Sem responsável (fila)</option>
                    {responsaveis.map(([id, nome]) => <option key={id} value={String(id)}>{nome}</option>)}
                </select>
                <select value={f.area} onChange={(e) => set('area', e.target.value)} className={select} aria-label="Área">
                    <option value="">Área</option>
                    {areas.map((a) => <option key={a} value={a}>{a}</option>)}
                </select>
                <select value={f.status} onChange={(e) => set('status', e.target.value)} className={select} aria-label="Status">
                    <option value="">Status</option>
                    {Object.entries(STATUS_CHAMADO).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <select value={f.tipo} onChange={(e) => set('tipo', e.target.value)} className={select} aria-label="Tipo">
                    <option value="">Tipo</option>
                    {Object.entries(TIPOS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <label className="flex items-center gap-1.5 text-[12.5px] text-white/50">
                    Desde
                    <input type="date" value={f.desde} onChange={(e) => set('desde', e.target.value)} className={select} />
                </label>
                {filtrando && (
                    <button type="button" onClick={() => setF(VAZIO)} className="inline-flex items-center gap-1 px-2 py-1.5 text-[12px] text-white/50 hover:text-white">
                        <X size={13} /> Limpar
                    </button>
                )}
            </div>

            {lista.length === 0 ? (
                <div className="rounded-xl border border-dashed border-white/[0.08] px-6 py-12 text-center text-[13px] text-white/50">
                    {chamados.length === 0 ? 'Nenhum ticket ainda. Quando alguém pedir ajuda em Tickets, ele aparece aqui.' : 'Nenhum ticket com esses filtros.'}
                </div>
            ) : (
                <ul className="divide-y divide-white/[0.06] overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card">
                    {lista.map((c) => (
                        <li key={c.id}>
                            <button
                                type="button"
                                onClick={() => onAbrir(c.id)}
                                className="grid w-full grid-cols-[10px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-3 text-left transition-colors hover:bg-white/[0.03] focus-visible:bg-white/[0.04] focus-visible:outline-none lg:grid-cols-[10px_minmax(0,1fr)_130px_140px_150px_90px]"
                            >
                                <span className={cn('h-2 w-2 rounded-full', c.precisa_atencao ? 'bg-ecf-yellow' : 'bg-transparent')} title={c.precisa_atencao ? 'Precisa da equipe' : undefined} />
                                <span className="min-w-0">
                                    <span className="flex items-baseline gap-2">
                                        <span className="shrink-0 text-[12px] font-semibold tabular-nums text-white/50">{c.codigo}</span>
                                        <span className="truncate text-[13.5px] font-medium text-white">{c.titulo}</span>
                                    </span>
                                    <span className="mt-0.5 block truncate text-[12px] text-white/50">
                                        {c.solicitante}{c.area && <> — {c.area}</>} — {TIPOS[c.tipo]}
                                        {c.demanda && <span className="text-white/40"> — {c.demanda.codigo}</span>}
                                    </span>
                                </span>
                                <StatusChamado status={c.status} className="justify-self-end lg:hidden" />
                                <span className={cn('hidden text-[12px] lg:block', IMPACTO_CLASSE[c.impacto])}>{IMPACTO_CURTO[c.impacto]}</span>
                                <span className="hidden truncate text-[12.5px] lg:block">
                                    {c.responsavel ? <span className="text-white/70">{c.responsavel.name}</span> : <span className="text-white/40">Fila da equipe</span>}
                                </span>
                                <span className="hidden lg:block"><StatusChamado status={c.status} /></span>
                                <span className="hidden text-right text-[12px] text-white/40 lg:block">{haQuanto(c.ultima_interacao_em)}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
