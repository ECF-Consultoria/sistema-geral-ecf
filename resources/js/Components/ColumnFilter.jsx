import { useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { Filter, Search, Check, Minus, ArrowUp, ArrowDown, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * ColumnFilter — dropdown de AutoFiltro (estilo Excel) para o cabeçalho de uma coluna.
 * Apresentacional/genérico: recebe as opções já calculadas (com contadores cruzados) e
 * callbacks; toda a lógica de filtragem vive no hook `useAutoFilter`.
 *
 * Usa Radix Popover (outside-click, Esc, portal, posicionamento e animações prontos).
 * Colunas `filterable={false}` mostram só a seção de ordenação (numéricas/moeda).
 */

const SORT_LABELS = {
    text:   { asc: 'Ordenar A → Z',           desc: 'Ordenar Z → A' },
    number: { asc: 'Ordenar (menor → maior)', desc: 'Ordenar (maior → menor)' },
    date:   { asc: 'Ordenar (mais antigo)',   desc: 'Ordenar (mais recente)' },
};

// Caixa de seleção temática (dark). state: 'on' | 'off' | 'ind'.
function Caixa({ state }) {
    return (
        <span className={cn('flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border transition-colors',
            state === 'off' ? 'border-white/25 bg-transparent' : 'border-ecf-yellow bg-ecf-yellow')}>
            {state === 'on'  && <Check size={11} className="text-black" strokeWidth={3} />}
            {state === 'ind' && <Minus size={11} className="text-black" strokeWidth={3} />}
        </span>
    );
}

function SortBtn({ ativo, onClick, icon: Icon, label }) {
    return (
        <button type="button" onClick={onClick}
            className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-[12px] transition text-left',
                ativo ? 'bg-ecf-yellow/15 text-ecf-yellow font-semibold' : 'text-white/70 hover:bg-white/[0.06]')}>
            <Icon size={13} className="shrink-0" /> {label}
        </button>
    );
}

export default function ColumnFilter({
    label,
    showLabel = true,
    type = 'text',
    filterable = true,
    sortable = true,
    getOptions,
    active = false,
    sortDir = null,
    onToggle,
    onSelectAll,
    onClear,
    onSort,
    alignRight = false,
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');

    // Opções só são calculadas quando o dropdown abre (evita varrer as linhas à toa).
    const options = (open && filterable && getOptions) ? getOptions() : [];
    const ql = q.trim().toLowerCase();
    const visiveis = ql ? options.filter((o) => o.label.toLowerCase().includes(ql)) : options;
    const marcados = visiveis.filter((o) => o.checked).length;
    const selState = visiveis.length === 0 ? 'off'
        : marcados === visiveis.length ? 'on'
        : marcados === 0 ? 'off' : 'ind';

    const sortLabels = SORT_LABELS[type] ?? SORT_LABELS.text;
    const podeLimpar = active || !!sortDir;

    return (
        <div className={cn('inline-flex items-center gap-1', alignRight && 'flex-row-reverse')}>
            {showLabel && <span className="truncate">{label}</span>}
            {sortable && sortDir && (sortDir === 'asc'
                ? <ArrowUp size={11} className="text-ecf-yellow shrink-0" />
                : <ArrowDown size={11} className="text-ecf-yellow shrink-0" />)}

            <Popover.Root open={open} onOpenChange={(v) => { setOpen(v); if (v) setQ(''); }}>
                <Popover.Trigger asChild>
                    <button type="button" title={`Filtrar / ordenar — ${label}`}
                        className={cn('inline-flex h-5 w-5 items-center justify-center rounded transition-colors shrink-0',
                            active ? 'text-ecf-yellow bg-ecf-yellow/15' : 'text-white/30 hover:text-white/70 hover:bg-white/[0.06]')}>
                        <Filter size={12} className={active ? 'fill-ecf-yellow/30' : ''} />
                    </button>
                </Popover.Trigger>

                <Popover.Portal>
                    <Popover.Content align={alignRight ? 'end' : 'start'} sideOffset={6}
                        onOpenAutoFocus={(e) => { if (!filterable) e.preventDefault(); }}
                        className={cn('z-50 w-60 rounded-xl border border-white/10 bg-ecf-card p-1.5 text-white shadow-2xl shadow-black/50 normal-case tracking-normal',
                            'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95')}>

                        {/* Ordenação */}
                        {sortable && (
                            <div className="flex flex-col gap-0.5 pb-1.5 mb-1.5 border-b border-white/[0.08]">
                                <SortBtn ativo={sortDir === 'asc'}  onClick={() => onSort(sortDir === 'asc'  ? null : 'asc')}  icon={ArrowUp}   label={sortLabels.asc} />
                                <SortBtn ativo={sortDir === 'desc'} onClick={() => onSort(sortDir === 'desc' ? null : 'desc')} icon={ArrowDown} label={sortLabels.desc} />
                            </div>
                        )}

                        {filterable && (
                            <>
                                {/* Pesquisa (filtra só a lista de opções) */}
                                <div className="relative mb-1.5">
                                    <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-white/30" />
                                    <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Pesquisar…"
                                        className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] pl-7 pr-2 py-1.5 text-[12px] font-normal text-white outline-none focus:border-ecf-yellow/40" />
                                </div>

                                {/* Selecionar tudo (tristate) */}
                                <button type="button" disabled={visiveis.length === 0}
                                    onClick={() => onSelectAll(visiveis.map((o) => o.value), selState !== 'on')}
                                    className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-[12px] font-semibold transition',
                                        visiveis.length === 0 ? 'text-white/20 cursor-default' : 'text-white/80 hover:bg-white/[0.06]')}>
                                    <Caixa state={selState} /> Selecionar tudo
                                </button>

                                {/* Lista de valores */}
                                <div className="max-h-64 overflow-y-auto py-0.5">
                                    {visiveis.length === 0 && <p className="px-2 py-3 text-center text-[11px] text-white/25">Nenhum valor.</p>}
                                    {visiveis.map((o) => (
                                        <button key={o.value} type="button" onClick={() => onToggle(o.value)}
                                            className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-[12px] hover:bg-white/[0.06] transition',
                                                o.count === 0 && 'opacity-40')}>
                                            <Caixa state={o.checked ? 'on' : 'off'} />
                                            <span className="flex-1 truncate text-left font-normal text-white/85">{o.label}</span>
                                            <span className="shrink-0 text-[11px] tabular-nums text-white/35">{o.count}</span>
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}

                        {/* Limpar coluna */}
                        <div className="mt-1.5 pt-1.5 border-t border-white/[0.08]">
                            <button type="button" onClick={onClear} disabled={!podeLimpar}
                                className={cn('flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-[12px] transition',
                                    podeLimpar ? 'text-white/60 hover:bg-white/[0.06] hover:text-white' : 'text-white/20 cursor-default')}>
                                <X size={12} /> Limpar filtro desta coluna
                            </button>
                        </div>
                    </Popover.Content>
                </Popover.Portal>
            </Popover.Root>
        </div>
    );
}
