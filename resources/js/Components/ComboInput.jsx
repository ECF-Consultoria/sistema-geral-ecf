import { useState, useEffect, useRef } from 'react';
import { ChevronDown, PlusCircle, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Combobox com:
 *  - Busca + criação de novo valor
 *  - Lixeira ao passar mouse em opção customizada (não-default)
 *  - Confirmação inline "Excluir 'X'? [Sim] [Não]"
 *
 * Props:
 *  - value: valor atual selecionado (string)
 *  - onChange(value): callback quando usuário seleciona/cria/limpa
 *  - options: lista completa de opções (default + customizadas, deduplicada)
 *  - defaults: lista de opções padrão (não excluíveis)
 *  - placeholder: texto quando nada selecionado
 *  - onDelete(valor): callback opcional. Se ausente, lixeira não aparece.
 *  - onCreateOption(valor): callback opcional. Chamado ao criar nova opção (não ao selecionar existente).
 *  - allowEmpty: se true, mostra opção "— sem valor —"
 */
export default function ComboInput({
    value,
    onChange,
    options = [],
    defaults = [],
    placeholder = 'Selecione ou crie',
    onDelete,
    onCreateOption,
    allowEmpty = true,
}) {
    const [open, setOpen] = useState(false);
    const [busca, setBusca] = useState('');
    const [confirmDelete, setConfirmDelete] = useState(null); // valor que está em confirmação
    const ref = useRef(null);

    useEffect(() => {
        function handle(e) {
            if (ref.current && !ref.current.contains(e.target)) {
                setOpen(false);
                setConfirmDelete(null);
            }
        }
        document.addEventListener('mousedown', handle);
        return () => document.removeEventListener('mousedown', handle);
    }, []);

    const defaultsLower = defaults.map(d => d.toLowerCase());
    const isDefault = (opt) => defaultsLower.includes(opt.toLowerCase());

    const filtered = options.filter(o => !busca || o.toLowerCase().includes(busca.toLowerCase()));
    const showCreate = busca.trim() && !options.some(o => o.toLowerCase() === busca.toLowerCase());

    function select(val) {
        onChange(val);
        setBusca('');
        setConfirmDelete(null);
        setOpen(false);
    }

    function create(val) {
        onChange(val);
        if (onCreateOption) onCreateOption(val);
        setBusca('');
        setConfirmDelete(null);
        setOpen(false);
    }

    function startDelete(opt, ev) {
        ev.stopPropagation();
        setConfirmDelete(opt);
    }

    function confirmDeleteOption(opt, ev) {
        ev.stopPropagation();
        if (onDelete) onDelete(opt);
        setConfirmDelete(null);
    }

    function cancelDelete(ev) {
        ev.stopPropagation();
        setConfirmDelete(null);
    }

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => { setOpen(o => !o); setBusca(''); setConfirmDelete(null); }}
                className={cn(
                    'w-full h-10 px-3 rounded-xl border text-left text-[13px] flex items-center justify-between gap-2 transition-colors',
                    open
                        ? 'border-ecf-yellow/40 bg-white/[0.05] text-white'
                        : 'border-white/[0.08] bg-white/[0.03] text-white hover:border-white/20'
                )}
            >
                <span className={value ? 'text-white' : 'text-white/30'}>{value || placeholder}</span>
                <ChevronDown size={14} className={cn('text-white/30 shrink-0 transition-transform', open && 'rotate-180')} />
            </button>

            {open && (
                <div className="absolute z-50 top-[calc(100%+4px)] left-0 right-0 rounded-xl border border-white/[0.10] bg-[#0f1116] shadow-xl overflow-hidden">
                    <div className="p-2 border-b border-white/[0.06]">
                        <input
                            autoFocus
                            type="text"
                            value={busca}
                            onChange={e => setBusca(e.target.value)}
                            onKeyDown={e => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    if (showCreate) create(busca.trim());
                                    else if (filtered.length > 0) select(filtered[0]);
                                }
                                if (e.key === 'Escape') setOpen(false);
                            }}
                            placeholder="Buscar ou criar novo…"
                            className="w-full h-8 px-2.5 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none placeholder:text-white/25"
                        />
                    </div>
                    <div className="max-h-56 overflow-y-auto py-1">
                        {allowEmpty && value && (
                            <button type="button" onClick={() => select('')}
                                className="w-full text-left px-3 py-1.5 text-[12px] text-white/30 hover:bg-white/[0.05] hover:text-white/60 transition-colors italic">
                                — sem valor —
                            </button>
                        )}

                        {filtered.map(o => {
                            const isConfirming = confirmDelete === o;
                            const canDelete = !!onDelete;

                            if (isConfirming) {
                                return (
                                    <div key={o} className="px-3 py-2 bg-red-500/10 border-y border-red-500/20 flex items-center gap-2">
                                        <span className="text-white/80 text-[12px] flex-1 truncate">
                                            Excluir <strong>"{o}"</strong>?
                                        </span>
                                        <button type="button" onClick={(ev) => confirmDeleteOption(o, ev)}
                                            className="px-2 py-0.5 text-[11px] font-bold rounded bg-red-500 text-white hover:bg-red-400">
                                            Sim
                                        </button>
                                        <button type="button" onClick={cancelDelete}
                                            className="px-2 py-0.5 text-[11px] font-bold rounded bg-white/10 text-white/70 hover:bg-white/20">
                                            Não
                                        </button>
                                    </div>
                                );
                            }

                            return (
                                <div key={o}
                                    className={cn(
                                        'group flex items-center gap-1 transition-colors',
                                        o === value ? 'bg-ecf-yellow/15' : 'hover:bg-white/[0.05]'
                                    )}>
                                    <button type="button" onClick={() => select(o)}
                                        className={cn(
                                            'flex-1 text-left px-3 py-1.5 text-[13px]',
                                            o === value ? 'text-ecf-yellow font-semibold' : 'text-white/80 group-hover:text-white'
                                        )}>
                                        {o}
                                    </button>
                                    {canDelete && (
                                        <button type="button" onClick={(ev) => startDelete(o, ev)}
                                            className="p-1.5 mr-1 rounded text-white/20 hover:text-red-400 hover:bg-red-500/10 opacity-0 group-hover:opacity-100 transition-all"
                                            title="Excluir esta opção">
                                            <Trash2 size={12} />
                                        </button>
                                    )}
                                </div>
                            );
                        })}

                        {showCreate && (
                            <button type="button" onClick={() => create(busca.trim())}
                                className="w-full text-left px-3 py-1.5 text-[12px] text-ecf-yellow/70 hover:bg-ecf-yellow/10 hover:text-ecf-yellow transition-colors flex items-center gap-2">
                                <PlusCircle size={12} /> Criar &ldquo;{busca.trim()}&rdquo;
                            </button>
                        )}
                        {filtered.length === 0 && !showCreate && (
                            <p className="px-3 py-2 text-[12px] text-white/25 italic">Nenhuma opção encontrada</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
