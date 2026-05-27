import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Package, Building2, ExternalLink, ChevronLeft, ChevronRight, ShoppingCart, TrendingUp, AlertTriangle, Trash2, X } from 'lucide-react';
import { cn } from '@/lib/utils';

function ExcluirModal({ pub, onClose }) {
    const [processing, setProcessing] = useState(false);

    function confirmar() {
        setProcessing(true);
        router.delete(route('mlb.destroy', pub.id), {
            onSuccess: onClose,
            onError: () => setProcessing(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2">
                        <Trash2 size={16} className="text-red-400" />
                        <h2 className="text-white font-bold text-base">Excluir publicação</h2>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>
                <p className="text-white/50 text-[13px] mb-1">Você está prestes a excluir o anúncio:</p>
                <p className="text-purple-400 font-mono text-[13px] font-bold mb-1">{pub.mlb_code}</p>
                <p className="text-white/40 text-[12px] mb-5">{pub.empresa} · {pub.data}</p>
                <p className="text-red-400/80 text-[12px] mb-5">Esta ação é irreversível e remove o registro do sistema.</p>
                <div className="flex gap-2">
                    <button onClick={confirmar} disabled={processing}
                        className="h-9 px-5 rounded-xl bg-red-500 text-white font-bold text-[13px] disabled:opacity-50 hover:bg-red-600 transition-colors">
                        {processing ? 'Excluindo…' : 'Excluir'}
                    </button>
                    <button type="button" onClick={onClose}
                        className="h-9 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    );
}

const MESES_PT = {
    '01':'Janeiro','02':'Fevereiro','03':'Março','04':'Abril',
    '05':'Maio','06':'Junho','07':'Julho','08':'Agosto',
    '09':'Setembro','10':'Outubro','11':'Novembro','12':'Dezembro',
};

function nomeMes(ym) {
    if (!ym) return 'Todos';
    const [y, m] = ym.split('-');
    return `${MESES_PT[m]} / ${y}`;
}

function fmt(n) { return Number(n ?? 0).toLocaleString('pt-BR'); }
function mlbUrl(code) { return `https://produto.mercadolivre.com.br/${code.replace('MLB', 'MLB-')}`; }

function KpiMini({ title, value, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow: { text:'text-ecf-yellow', bg:'bg-ecf-yellow/10', border:'border-ecf-yellow/20' },
        green:  { text:'text-emerald-400', bg:'bg-emerald-500/10', border:'border-emerald-500/20' },
        blue:   { text:'text-blue-400',   bg:'bg-blue-500/10',   border:'border-blue-500/20' },
        purple: { text:'text-purple-400', bg:'bg-purple-500/10', border:'border-purple-500/20' },
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-xl p-4 flex items-center gap-3">
            <div className={cn('w-9 h-9 rounded-xl flex items-center justify-center border shrink-0', c.bg, c.border)}>
                <Icon size={16} className={c.text} />
            </div>
            <div>
                <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">{title}</p>
                <p className={cn('font-display font-bold text-xl', c.text)}>{value}</p>
            </div>
        </div>
    );
}

function Select({ value, onChange, placeholder, options }) {
    return (
        <select
            value={value}
            onChange={e => onChange(e.target.value)}
            className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
        >
            {placeholder && <option value="">{placeholder}</option>}
            {options.map(o => (
                <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>
            ))}
        </select>
    );
}

export default function Historico({ publicacoes, meses, empresas, publicadores, totais, filters, isPub }) {
    const [f, setF] = useState({ ...filters });
    const [excluirModal, setExcluirModal] = useState(null);

    function applyFilters(updates) {
        let novo = { ...f, ...updates };
        // Ao trocar publicador, limpa empresa (lista vai ser recarregada filtrada)
        if ('func' in updates && updates.func !== f.func && novo.empresa) {
            novo = { ...novo, empresa: '' };
        }
        setF(novo);
        router.get(route('mlb.historico'), novo, { preserveState: true });
    }

    const pag = publicacoes ?? { data: [], links: [], current_page: 1, last_page: 1 };

    return (
        <AppLayout title="Histórico">
            <div className="mb-6">
                <h1 className="text-white font-display font-bold text-2xl">Histórico de Publicações</h1>
                <p className="text-white/40 text-sm mt-0.5">
                    {isPub ? 'Todas as suas publicações registradas' : 'Visão completa da equipe'}
                </p>
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-4 gap-4 mb-6">
                <KpiMini title="Registros"        value={fmt(totais?.total)}      icon={Package}      color="yellow" />
                <KpiMini title="Anúncios Vendidos" value={fmt(totais?.vendas)}    icon={ShoppingCart} color="green" />
                <KpiMini title="Unidades Vendidas" value={fmt(totais?.vendas_qty)} icon={TrendingUp}  color="purple" />
                <KpiMini title="Empresas Únicas"  value={fmt(totais?.empresas)}   icon={Building2}    color="blue" />
            </div>

            {/* Filtros */}
            <div className="card-ecf rounded-2xl p-4 mb-6 flex flex-wrap gap-3 items-center">
                <Select
                    value={f.mes}
                    onChange={v => applyFilters({ mes: v })}
                    placeholder="Todos os meses"
                    options={(meses ?? []).map(m => ({ value: m, label: nomeMes(m) }))}
                />

                {!isPub && publicadores?.length > 0 && (
                    <Select
                        value={f.func}
                        onChange={v => applyFilters({ func: v })}
                        placeholder="Todos os publicadores"
                        options={(publicadores ?? []).map(p => ({ value: p.id, label: p.nome }))}
                    />
                )}

                <Select
                    value={f.empresa}
                    onChange={v => applyFilters({ empresa: v })}
                    placeholder="Todas as empresas"
                    options={(empresas ?? []).map(e => ({ value: e, label: e }))}
                />

                <input
                    type="text"
                    value={f.busca}
                    onChange={e => setF(p => ({ ...p, busca: e.target.value }))}
                    onKeyDown={e => e.key === 'Enter' && applyFilters({ busca: f.busca })}
                    placeholder="Buscar MLB ou empresa…"
                    className="h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none placeholder:text-white/20 min-w-[200px]"
                />

                {/* Filtro problema */}
                <button
                    onClick={() => applyFilters({ problema: f.problema === '1' ? '' : '1' })}
                    className={cn(
                        'h-9 px-3 rounded-xl border text-[13px] font-semibold flex items-center gap-2 transition-colors',
                        f.problema === '1'
                            ? 'bg-red-500/20 border-red-500/40 text-red-400'
                            : 'border-white/[0.08] text-white/40 hover:text-red-400 hover:border-red-500/30'
                    )}
                >
                    <AlertTriangle size={13} />
                    {f.problema === '1' ? 'Só problemas' : 'Com problema'}
                </button>

                {(f.mes || f.func || f.empresa || f.busca || f.problema) && (
                    <button
                        onClick={() => applyFilters({ mes:'', func:'', empresa:'', busca:'', problema:'' })}
                        className="text-white/30 hover:text-white/60 text-xs underline"
                    >
                        Limpar filtros
                    </button>
                )}
            </div>

            {/* Tabela */}
            <div className="card-ecf rounded-2xl p-5">
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-white/[0.06]">
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Data</th>
                                {!isPub && <th className="text-left text-white/40 font-semibold py-2 pr-4">Publicador</th>}
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Empresa</th>
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Cust ID</th>
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Anúncio</th>
                                <th className="text-center text-white/40 font-semibold py-2 pr-4">Vendas</th>
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Comentário</th>
                                <th className="text-left text-white/40 font-semibold py-2 pr-4">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {(pag.data ?? []).map(p => (
                                <tr key={p.id} className={cn(
                                    'border-b hover:bg-white/[0.02]',
                                    p.problema
                                        ? 'border-red-500/20 bg-red-500/5 border-l-2 border-l-red-500/50'
                                        : 'border-white/[0.03]'
                                )}>
                                    <td className="py-2.5 pr-4 text-white/50">{p.data}</td>
                                    {!isPub && (
                                        <td className="py-2.5 pr-4">
                                            <span className="text-white font-medium">{p.usuario}</span>
                                            {p.usuario_removido && (
                                                <span className="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-red-500/15 border border-red-500/30 text-red-400">removido</span>
                                            )}
                                        </td>
                                    )}
                                    <td className="py-2.5 pr-4 text-white">{p.empresa}</td>
                                    <td className="py-2.5 pr-4 text-white/40 font-mono text-[12px]">{p.cust_id || '—'}</td>
                                    <td className="py-2.5 pr-4">
                                        <div className="flex items-center gap-1.5">
                                            <a href={mlbUrl(p.mlb_code)} target="_blank" rel="noopener noreferrer"
                                                className="text-purple-400 font-mono text-[12px] hover:text-purple-300 flex items-center gap-1">
                                                {p.mlb_code} <ExternalLink size={11} />
                                            </a>
                                            {p.tipo === 'variacao' && (
                                                <span className="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/15 border border-sky-500/30 text-sky-400">
                                                    Variação
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="py-2.5 pr-4 text-center">
                                        {p.vendido ? (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-[11px] font-bold">
                                                ✓ {p.vendas_qty > 0 ? `${p.vendas_qty} un.` : 'vendido'}
                                            </span>
                                        ) : (
                                            <span className="text-white/20 text-[12px]">—</span>
                                        )}
                                    </td>
                                    <td className="py-2.5 pr-4 max-w-[200px]">
                                        {p.comentario ? (
                                            <div>
                                                <div className="text-white/70 text-[12px] truncate" title={p.comentario}>
                                                    {p.comentario}
                                                </div>
                                                {p.comentario_resolvido
                                                    ? <span className="text-emerald-400 text-[10px]">✓ resolvido</span>
                                                    : <div className="text-white/30 text-[11px] mt-0.5">{p.comentario_autor}{p.comentario_em ? ` · ${p.comentario_em}` : ''}</div>
                                                }
                                            </div>
                                        ) : <span className="text-white/20">—</span>}
                                    </td>
                                    <td className="py-2.5 pr-4">
                                        {p.problema && (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-500/15 border border-red-500/30 text-red-400 text-[10px] font-bold" title={p.problema_nota}>
                                                <AlertTriangle size={9} /> Problema
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2.5 text-right">
                                        <button onClick={() => setExcluirModal(p)}
                                            className="p-1.5 rounded-lg text-white/20 hover:text-red-400 transition-colors">
                                            <Trash2 size={13} />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {(pag.data ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={9} className="py-12 text-center text-white/20">Nenhum registro encontrado com esses filtros.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Paginação */}
                {pag.last_page > 1 && (
                    <div className="flex items-center justify-between mt-4 pt-4 border-t border-white/[0.06]">
                        <p className="text-white/30 text-xs">
                            Página {pag.current_page} de {pag.last_page}
                        </p>
                        <div className="flex gap-2">
                            <button
                                disabled={pag.current_page <= 1}
                                onClick={() => applyFilters({ page: pag.current_page - 1 })}
                                className="w-8 h-8 rounded-lg border border-white/[0.08] flex items-center justify-center text-white/40 hover:text-white disabled:opacity-30"
                            >
                                <ChevronLeft size={14} />
                            </button>
                            <button
                                disabled={pag.current_page >= pag.last_page}
                                onClick={() => applyFilters({ page: pag.current_page + 1 })}
                                className="w-8 h-8 rounded-lg border border-white/[0.08] flex items-center justify-center text-white/40 hover:text-white disabled:opacity-30"
                            >
                                <ChevronRight size={14} />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {excluirModal && (
                <ExcluirModal pub={excluirModal} onClose={() => setExcluirModal(null)} />
            )}
        </AppLayout>
    );
}
