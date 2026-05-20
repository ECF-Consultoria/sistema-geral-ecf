import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Crown, Users, Briefcase, Target, Plus, Trash2, ArrowRightLeft, X,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Dashboard do líder de setor. Mostra membros, metas e KPIs do setor liderado.
 * Acessível por admin OU usuário em setor_lideres.
 */
export default function LiderancaSetor({
    setor, membros, kpis, outros_setores, metric_labels, can_definir_metas,
}) {
    const [editMeta, setEditMeta] = useState(null);
    const [showNew, setShowNew] = useState(false);

    return (
        <AppLayout title={`Meu Setor · ${setor.nome}`}>
            <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
                <div className="flex items-center gap-3">
                    <Crown size={20} className="text-ecf-yellow" />
                    <div>
                        <h1 className="text-white font-display font-bold text-2xl">{setor.nome}</h1>
                        <p className="text-white/40 text-sm mt-0.5">{setor.descricao || 'Visão de liderança do setor'}</p>
                    </div>
                </div>
                {outros_setores?.length > 0 && (
                    <SetorSwitcher current={setor} outros={outros_setores} />
                )}
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <Kpi icon={Users}     label="Membros"        value={kpis.total_membros} />
                <Kpi icon={Users}     label="Ativos"         value={kpis.membros_ativos}  color="green" />
                <Kpi icon={Crown}     label="Líderes"        value={kpis.total_lideres}   color="yellow" />
                <Kpi icon={Target}    label="Metas ativas"   value={kpis.total_metas} />
            </div>

            {/* Membros */}
            <section className="card-ecf rounded-xl p-5 mb-4">
                <div className="flex items-center gap-2 mb-3">
                    <Users size={14} className="text-white/40" />
                    <h2 className="text-white font-display font-semibold text-base">Membros do setor</h2>
                    <span className="text-white/30 text-xs">· {membros.length}</span>
                </div>
                {membros.length === 0 ? (
                    <p className="text-white/40 text-sm">Setor sem membros. Atribua usuários em <b>Administração → Setores</b>.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="border-b border-white/[0.06]">
                                <tr>
                                    <th className="text-left py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Membro</th>
                                    <th className="text-left py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Cargo</th>
                                    <th className="text-center py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {membros.map(m => (
                                    <tr key={m.id} className="border-b border-white/[0.04]">
                                        <td className="py-2.5 px-3 text-[13px] text-white/85">
                                            {m.name}
                                            <span className="block text-white/40 text-[11px]">{m.email}</span>
                                        </td>
                                        <td className="py-2.5 px-3 text-[12px] text-white/60">{m.cargo_nome ?? '—'}</td>
                                        <td className="py-2.5 px-3 text-center">
                                            {m.active
                                                ? <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">Ativo</span>
                                                : <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-zinc-500/15 text-zinc-400 border border-zinc-500/30">Inativo</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {/* Metas */}
            <section className="card-ecf rounded-xl p-5">
                <div className="flex items-center justify-between gap-2 mb-3">
                    <div className="flex items-center gap-2">
                        <Target size={14} className="text-white/40" />
                        <h2 className="text-white font-display font-semibold text-base">Metas do setor</h2>
                        <span className="text-white/30 text-xs">· {setor.metas.length}</span>
                    </div>
                    {can_definir_metas && (
                        <button onClick={() => setShowNew(true)}
                            className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white text-xs">
                            <Plus size={12} /> Nova meta
                        </button>
                    )}
                </div>

                {setor.metas.length === 0 ? (
                    <p className="text-white/40 text-sm">
                        Nenhuma meta definida ainda.
                        {can_definir_metas && ' Crie a primeira pra acompanhar resultados do setor.'}
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {setor.metas.map(m => (
                            <MetaCard key={m.id} meta={m} metricLabels={metric_labels}
                                canEdit={can_definir_metas} onEdit={() => setEditMeta(m)} />
                        ))}
                    </ul>
                )}
            </section>

            {showNew && (
                <MetaFormModal setor={setor} metricLabels={metric_labels} onClose={() => setShowNew(false)} />
            )}
            {editMeta && (
                <MetaFormModal meta={editMeta} metricLabels={metric_labels} onClose={() => setEditMeta(null)} />
            )}
        </AppLayout>
    );
}

function Kpi({ icon: Icon, label, value, color = 'white' }) {
    const colors = { white: 'text-white', green: 'text-emerald-400', yellow: 'text-ecf-yellow' };
    return (
        <div className="card-ecf rounded-xl p-4">
            <div className="flex items-center gap-2 mb-2">
                <Icon size={13} className="text-white/40" />
                <span className="text-white/40 text-[10px] font-semibold uppercase tracking-wide">{label}</span>
            </div>
            <p className={cn('font-display font-bold text-2xl tabular-nums', colors[color])}>{value}</p>
        </div>
    );
}

function MetaCard({ meta, metricLabels, canEdit, onEdit }) {
    const ultimoResult = meta.results?.[0];
    const pct = ultimoResult?.pct_achieved;
    return (
        <li className="p-3 rounded-lg border border-white/[0.06] bg-white/[0.02]">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-white/85 text-[13px] font-medium">{metricLabels[meta.metric] ?? meta.metric}</p>
                    {meta.description && <p className="text-white/40 text-[11px] mt-0.5">{meta.description}</p>}
                    <p className="text-white/30 text-[11px] mt-1">
                        Período: <span className="text-white/50">{meta.period_type}</span>
                        {' · '}
                        Tipo: <span className="text-white/50">{meta.value_type}</span>
                    </p>
                </div>
                <div className="text-right shrink-0">
                    <p className="text-white font-bold tabular-nums text-base">
                        {ultimoResult?.value_realized != null ? (
                            <>
                                <span className={cn(
                                    pct >= 100 ? 'text-emerald-400' : pct >= 70 ? 'text-ecf-yellow' : 'text-red-400'
                                )}>{Number(ultimoResult.value_realized).toLocaleString('pt-BR')}</span>
                                <span className="text-white/40 text-[11px]"> / {Number(meta.target_value).toLocaleString('pt-BR')}</span>
                            </>
                        ) : (
                            <span className="text-white/30">— / {Number(meta.target_value).toLocaleString('pt-BR')}</span>
                        )}
                    </p>
                    {pct != null && <p className="text-white/40 text-[11px]">{Number(pct).toFixed(1)}% atingido</p>}
                    {canEdit && (
                        <button onClick={onEdit} className="mt-1 text-ecf-yellow/70 hover:text-ecf-yellow text-[11px] underline">
                            editar
                        </button>
                    )}
                </div>
            </div>
        </li>
    );
}

function MetaFormModal({ setor, meta, metricLabels, onClose }) {
    const isEdit = !!meta;
    const { data, setData, post, put, processing, errors } = useForm({
        metric:       meta?.metric ?? Object.keys(metricLabels)[0] ?? 'publicacoes_mes',
        target_value: meta?.target_value ?? 0,
        value_type:   meta?.value_type ?? 'absolute',
        period_type:  meta?.period_type ?? 'monthly',
        description:  meta?.description ?? '',
        active:       meta?.active ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(route('admin.setores.metas.update', meta.id), { onSuccess: onClose, preserveScroll: true });
        } else {
            post(route('admin.setores.metas.store', setor.id), { onSuccess: onClose, preserveScroll: true });
        }
    };

    const destroy = () => {
        if (!confirm('Excluir esta meta?')) return;
        router.delete(route('admin.setores.metas.destroy', meta.id), { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-md p-6">
                <div className="flex items-start justify-between mb-4">
                    <h3 className="text-white font-display font-bold text-lg">{isEdit ? 'Editar meta' : 'Nova meta'}</h3>
                    <button onClick={onClose} className="text-white/40 hover:text-white"><X size={18} /></button>
                </div>
                <form onSubmit={submit} className="space-y-3">
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Métrica</label>
                        <select value={data.metric} onChange={e => setData('metric', e.target.value)}
                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80">
                            {Object.entries(metricLabels).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                        </select>
                        {errors.metric && <p className="text-red-400 text-xs mt-1">{errors.metric}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Alvo</label>
                            <input type="number" step="any" value={data.target_value} onChange={e => setData('target_value', e.target.value)}
                                className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80" />
                        </div>
                        <div>
                            <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Tipo</label>
                            <select value={data.value_type} onChange={e => setData('value_type', e.target.value)}
                                className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80">
                                <option value="absolute">Absoluto</option>
                                <option value="percentage">Percentual</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Período</label>
                        <select value={data.period_type} onChange={e => setData('period_type', e.target.value)}
                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80">
                            <option value="monthly">Mensal</option>
                            <option value="quarterly">Trimestral</option>
                            <option value="annual">Anual</option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Descrição</label>
                        <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={2}
                            className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 resize-none" />
                    </div>
                    <label className="inline-flex items-center gap-2 text-white/70 text-xs cursor-pointer">
                        <input type="checkbox" checked={data.active} onChange={e => setData('active', e.target.checked)} className="w-3.5 h-3.5 accent-ecf-yellow" />
                        Meta ativa
                    </label>

                    <div className="flex justify-between gap-2 pt-2">
                        {isEdit && (
                            <button type="button" onClick={destroy}
                                className="inline-flex items-center gap-1 px-3 h-9 rounded-lg border border-red-500/30 bg-red-500/10 text-red-300 hover:bg-red-500/20 text-xs">
                                <Trash2 size={12} /> Excluir
                            </button>
                        )}
                        <div className="flex gap-2 ml-auto">
                            <button type="button" onClick={onClose}
                                className="px-4 h-9 rounded-lg border border-white/[0.08] text-white/60 hover:text-white text-[13px]">
                                Cancelar
                            </button>
                            <button type="submit" disabled={processing}
                                className="px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold">
                                {processing ? 'Salvando...' : isEdit ? 'Salvar' : 'Criar meta'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}

function SetorSwitcher({ current, outros }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="relative">
            <button onClick={() => setOpen(o => !o)}
                className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white text-xs">
                <ArrowRightLeft size={12} /> Mudar setor
            </button>
            {open && (
                <div className="absolute top-full right-0 mt-1 w-56 card-ecf rounded-lg overflow-hidden z-10">
                    {outros.map(s => (
                        <Link key={s.id} href={route('lideranca.setor', s.slug)}
                            className="block px-3 py-2 text-[13px] text-white/70 hover:bg-white/[0.05] hover:text-white">
                            {s.nome}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
