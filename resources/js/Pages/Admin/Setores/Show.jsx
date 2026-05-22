import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowLeft, Shield, Lock, Users, Briefcase, Crown, Target, Settings,
    Plus, Trash2, Edit3, Check, X, AlertTriangle,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const TABS = [
    { key: 'permissoes', label: 'Permissões', icon: Shield },
    { key: 'cargos',     label: 'Cargos',     icon: Briefcase },
    { key: 'membros',    label: 'Membros',    icon: Users },
    { key: 'lideres',    label: 'Líderes',    icon: Crown },
    { key: 'metas',      label: 'Metas',      icon: Target },
    { key: 'config',     label: 'Configuração', icon: Settings },
];

export default function SetorShow({ setor, membros, todos_users, catalogo_perms, permission_keys }) {
    const [tab, setTab] = useState('permissoes');

    return (
        <AppLayout title={`Setor · ${setor.nome}`}>
            <Link href={route('admin.setores.index')} className="inline-flex items-center gap-2 text-white/50 hover:text-white text-sm mb-4">
                <ArrowLeft size={14} /> Voltar para setores
            </Link>

            <div className="card-ecf rounded-xl p-5 mb-4">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-2">
                            <h1 className="text-white font-display font-bold text-xl truncate">{setor.nome}</h1>
                            {setor.is_system && (
                                <span title="Setor de sistema" className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30">
                                    <Lock size={10} />
                                    SISTEMA
                                </span>
                            )}
                            {!setor.active && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-zinc-500/15 text-zinc-400 border border-zinc-500/30">
                                    Inativo
                                </span>
                            )}
                        </div>
                        {setor.descricao && <p className="text-white/60 text-sm">{setor.descricao}</p>}
                        <p className="text-white/30 text-[11px] font-mono mt-1">slug: {setor.slug}</p>
                    </div>
                </div>
            </div>

            {/* Tabs */}
            <div className="flex items-center gap-1 border-b border-white/[0.06] mb-4 overflow-x-auto">
                {TABS.map(t => {
                    const Icon = t.icon;
                    const active = tab === t.key;
                    return (
                        <button
                            key={t.key}
                            onClick={() => setTab(t.key)}
                            className={cn(
                                'inline-flex items-center gap-1.5 px-3 h-9 text-[13px] font-medium border-b-2 -mb-px',
                                active
                                    ? 'border-ecf-yellow text-ecf-yellow'
                                    : 'border-transparent text-white/50 hover:text-white'
                            )}
                        >
                            <Icon size={13} />
                            {t.label}
                        </button>
                    );
                })}
            </div>

            {tab === 'permissoes' && <TabPermissoes setor={setor} catalogo={catalogo_perms} initialKeys={permission_keys} />}
            {tab === 'cargos'     && <TabCargos setor={setor} />}
            {tab === 'membros'    && <TabMembros setor={setor} membros={membros} todosUsers={todos_users} />}
            {tab === 'lideres'    && <TabLideres setor={setor} lideres={setor.lideres} todosUsers={todos_users} />}
            {tab === 'metas'      && <TabMetas setor={setor} />}
            {tab === 'config'     && <TabConfig setor={setor} />}
        </AppLayout>
    );
}

// ─── Tab: Permissões ────────────────────────────────────────────────────────
function TabPermissoes({ setor, catalogo, initialKeys }) {
    const [selected, setSelected] = useState(new Set(initialKeys || []));
    const [saving, setSaving] = useState(false);

    if (setor.is_system) {
        return (
            <div className="card-ecf rounded-xl p-5">
                <div className="flex items-center gap-2 text-amber-300 text-sm">
                    <Lock size={14} />
                    Setor de sistema — permissões fixas em <b>todas as keys</b>. Não editável.
                </div>
            </div>
        );
    }

    const toggle = (key) => {
        setSelected(prev => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key); else next.add(key);
            return next;
        });
    };

    const save = () => {
        setSaving(true);
        router.put(
            route('admin.setores.permissoes.sync', setor.id),
            { permissions: Array.from(selected) },
            { preserveScroll: true, onFinish: () => setSaving(false) }
        );
    };

    return (
        <div className="space-y-4">
            {Object.entries(catalogo).map(([grupo, perms]) => (
                <div key={grupo} className="card-ecf rounded-xl p-4">
                    <h3 className="text-white/80 font-display font-semibold text-sm mb-3">{grupo}</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                        {perms.map(p => {
                            const checked = selected.has(p.key);
                            return (
                                <label key={p.key} className={cn(
                                    'flex items-start gap-2 p-2 rounded-lg border cursor-pointer transition-colors',
                                    checked
                                        ? 'border-ecf-yellow/30 bg-ecf-yellow/[0.04]'
                                        : 'border-white/[0.06] hover:bg-white/[0.03]'
                                )}>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={() => toggle(p.key)}
                                        className="w-3.5 h-3.5 accent-ecf-yellow mt-1"
                                    />
                                    <div className="min-w-0">
                                        <div className="text-white/85 text-[13px] font-medium">{p.label}</div>
                                        {p.description && (
                                            <div className="text-white/40 text-[11px]">{p.description}</div>
                                        )}
                                        <div className="text-white/25 text-[10px] font-mono mt-0.5">{p.key}</div>
                                    </div>
                                </label>
                            );
                        })}
                    </div>
                </div>
            ))}

            <div className="sticky bottom-4 flex justify-end">
                <button
                    onClick={save}
                    disabled={saving}
                    className="inline-flex items-center gap-2 h-10 px-4 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold shadow-lg"
                >
                    <Check size={14} />
                    {saving ? 'Salvando...' : `Salvar (${selected.size} permissões)`}
                </button>
            </div>
        </div>
    );
}

// ─── Tab: Cargos ────────────────────────────────────────────────────────────
function TabCargos({ setor }) {
    const [showNew, setShowNew] = useState(false);

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-white/70 text-[13px]">{setor.cargos.length} cargo(s)</h3>
                <button
                    onClick={() => setShowNew(true)}
                    className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white text-xs"
                >
                    <Plus size={12} /> Novo cargo
                </button>
            </div>

            <div className="card-ecf rounded-xl overflow-hidden">
                {setor.cargos.length === 0 ? (
                    <p className="p-6 text-center text-white/40 text-sm">Nenhum cargo criado ainda.</p>
                ) : (
                    <table className="w-full">
                        <thead className="bg-white/[0.02] border-b border-white/[0.06]">
                            <tr>
                                <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Cargo</th>
                                <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Meta publ./mês</th>
                                <th className="text-center px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Ordem</th>
                                <th className="text-center px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Ativo</th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {setor.cargos.map(c => <CargoRow key={c.id} cargo={c} />)}
                        </tbody>
                    </table>
                )}
            </div>

            {showNew && <CargoFormModal setor={setor} onClose={() => setShowNew(false)} />}
        </div>
    );
}

function CargoRow({ cargo }) {
    const [editing, setEditing] = useState(false);
    const destroy = () => {
        if (!confirm(`Excluir cargo "${cargo.nome}"?`)) return;
        router.delete(route('admin.cargos.destroy', cargo.id), { preserveScroll: true });
    };
    return (
        <>
            <tr className="border-b border-white/[0.04]">
                <td className="px-4 py-3 text-[13px] text-white/85">{cargo.nome}</td>
                <td className="px-4 py-3 text-right text-[12px] text-white/60 tabular-nums">{cargo.meta_publicacoes ?? '—'}</td>
                <td className="px-4 py-3 text-center text-[12px] text-white/40 tabular-nums">{cargo.ordem}</td>
                <td className="px-4 py-3 text-center">
                    {cargo.active
                        ? <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">Sim</span>
                        : <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-zinc-500/15 text-zinc-400 border border-zinc-500/30">Não</span>}
                </td>
                <td className="px-4 py-3 text-right whitespace-nowrap">
                    <button onClick={() => setEditing(true)} className="text-white/40 hover:text-white px-2"><Edit3 size={13} /></button>
                    <button onClick={destroy} className="text-red-400/60 hover:text-red-400 px-2"><Trash2 size={13} /></button>
                </td>
            </tr>
            {editing && (
                <tr>
                    <td colSpan={5} className="p-0">
                        <CargoEditInline cargo={cargo} onClose={() => setEditing(false)} />
                    </td>
                </tr>
            )}
        </>
    );
}

function CargoFormModal({ setor, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        nome: '', meta_publicacoes: '', ordem: 0, active: true,
    });
    const submit = (e) => {
        e.preventDefault();
        post(route('admin.setores.cargos.store', setor.id), { onSuccess: onClose });
    };
    return (
        <ModalShell title="Novo cargo" onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
                <Field label="Nome" error={errors.nome}>
                    <input type="text" value={data.nome} onChange={e => setData('nome', e.target.value)} autoFocus
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40" />
                </Field>
                <Field label="Meta de publicações/mês (opcional)" error={errors.meta_publicacoes}>
                    <input type="number" min="0" value={data.meta_publicacoes} onChange={e => setData('meta_publicacoes', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40" />
                </Field>
                <Field label="Ordem (menor aparece primeiro)" error={errors.ordem}>
                    <input type="number" value={data.ordem} onChange={e => setData('ordem', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40" />
                </Field>
                <SubmitRow onCancel={onClose} processing={processing} label="Criar cargo" />
            </form>
        </ModalShell>
    );
}

function CargoEditInline({ cargo, onClose }) {
    const { data, setData, put, processing } = useForm({
        nome: cargo.nome,
        meta_publicacoes: cargo.meta_publicacoes ?? '',
        ordem: cargo.ordem,
        active: cargo.active,
    });
    const submit = (e) => {
        e.preventDefault();
        put(route('admin.cargos.update', cargo.id), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <form onSubmit={submit} className="p-4 bg-black/30 border-y border-white/[0.06] grid grid-cols-1 md:grid-cols-5 gap-2">
            <input type="text" value={data.nome} onChange={e => setData('nome', e.target.value)}
                className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80" />
            <input type="number" placeholder="Meta" value={data.meta_publicacoes} onChange={e => setData('meta_publicacoes', e.target.value)}
                className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80" />
            <input type="number" placeholder="Ordem" value={data.ordem} onChange={e => setData('ordem', e.target.value)}
                className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80" />
            <label className="inline-flex items-center gap-2 text-white/70 text-xs">
                <input type="checkbox" checked={data.active} onChange={e => setData('active', e.target.checked)} className="w-3.5 h-3.5 accent-ecf-yellow" />
                Ativo
            </label>
            <div className="flex gap-1 justify-end">
                <button type="button" onClick={onClose} className="h-9 px-3 rounded-lg border border-white/[0.08] text-white/60 text-xs">Cancelar</button>
                <button type="submit" disabled={processing} className="h-9 px-3 rounded-lg bg-ecf-yellow text-[#252525] text-xs font-bold disabled:opacity-50">Salvar</button>
            </div>
        </form>
    );
}

// ─── Tab: Membros ───────────────────────────────────────────────────────────
function TabMembros({ setor, membros, todosUsers }) {
    const [showAdd, setShowAdd] = useState(false);

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-white/70 text-[13px]">{membros.length} membro(s)</h3>
                <button onClick={() => setShowAdd(true)}
                    className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white text-xs">
                    <Plus size={12} /> Adicionar membro
                </button>
            </div>

            <div className="card-ecf rounded-xl overflow-hidden">
                {membros.length === 0 ? (
                    <p className="p-6 text-center text-white/40 text-sm">Nenhum membro neste setor.</p>
                ) : (
                    <table className="w-full">
                        <thead className="bg-white/[0.02] border-b border-white/[0.06]">
                            <tr>
                                <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Usuário</th>
                                <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Cargo</th>
                                <th className="text-center px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Principal</th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {membros.map(m => (
                                <tr key={m.id} className="border-b border-white/[0.04]">
                                    <td className="px-4 py-3 text-[13px] text-white/85">
                                        {m.name}
                                        <span className="text-white/40 text-[11px] block">{m.email}</span>
                                    </td>
                                    <td className="px-4 py-3 text-[12px] text-white/60">{m.cargo_nome ?? '—'}</td>
                                    <td className="px-4 py-3 text-center">
                                        {m.is_principal && <Check size={14} className="inline text-ecf-yellow" />}
                                    </td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                        <button
                                            onClick={() => {
                                                if (confirm(`Remover ${m.name} do setor?`)) {
                                                    router.delete(route('admin.setores.membros.destroy', [setor.id, m.id]), { preserveScroll: true });
                                                }
                                            }}
                                            className="text-red-400/60 hover:text-red-400 px-2"
                                        >
                                            <Trash2 size={13} />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {showAdd && <AddMembroModal setor={setor} todosUsers={todosUsers} onClose={() => setShowAdd(false)} />}
        </div>
    );
}

function AddMembroModal({ setor, todosUsers, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        user_id: '', cargo_id: '', is_principal: false,
    });
    const submit = (e) => {
        e.preventDefault();
        post(route('admin.setores.membros.store', setor.id), { onSuccess: onClose });
    };
    return (
        <ModalShell title="Adicionar membro" onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
                <Field label="Usuário" error={errors.user_id}>
                    <select value={data.user_id} onChange={e => setData('user_id', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80">
                        <option value="">— selecione —</option>
                        {todosUsers.map(u => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                    </select>
                </Field>
                <Field label="Cargo" error={errors.cargo_id}>
                    <select value={data.cargo_id} onChange={e => setData('cargo_id', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80">
                        <option value="">— sem cargo —</option>
                        {setor.cargos.filter(c => c.active).map(c => <option key={c.id} value={c.id}>{c.nome}</option>)}
                    </select>
                </Field>
                <label className="inline-flex items-center gap-2 text-white/70 text-xs">
                    <input type="checkbox" checked={data.is_principal} onChange={e => setData('is_principal', e.target.checked)} className="w-3.5 h-3.5 accent-ecf-yellow" />
                    Marcar como setor principal deste usuário
                </label>
                <SubmitRow onCancel={onClose} processing={processing} label="Adicionar" />
            </form>
        </ModalShell>
    );
}

// ─── Tab: Líderes ───────────────────────────────────────────────────────────
function TabLideres({ setor, lideres, todosUsers }) {
    const [showAdd, setShowAdd] = useState(false);

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-white/70 text-[13px]">{lideres.length} líder(es)</h3>
                <button onClick={() => setShowAdd(true)}
                    className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white text-xs">
                    <Plus size={12} /> Adicionar líder
                </button>
            </div>

            <div className="card-ecf rounded-xl overflow-hidden">
                {lideres.length === 0 ? (
                    <p className="p-6 text-center text-white/40 text-sm">Setor sem líderes.</p>
                ) : (
                    <ul className="divide-y divide-white/[0.04]">
                        {lideres.map(l => (
                            <li key={l.id} className="px-4 py-3 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Crown size={14} className="text-ecf-yellow" />
                                    <span className="text-white/85 text-[13px]">{l.name}</span>
                                    <span className="text-white/40 text-[11px]">{l.email}</span>
                                </div>
                                <button
                                    onClick={() => {
                                        if (confirm(`Remover ${l.name} como líder?`)) {
                                            router.delete(route('admin.setores.lideres.destroy', [setor.id, l.id]), { preserveScroll: true });
                                        }
                                    }}
                                    className="text-red-400/60 hover:text-red-400"
                                >
                                    <Trash2 size={13} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {showAdd && <AddLiderModal setor={setor} todosUsers={todosUsers} onClose={() => setShowAdd(false)} />}
        </div>
    );
}

function AddLiderModal({ setor, todosUsers, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ user_id: '' });
    const submit = (e) => {
        e.preventDefault();
        post(route('admin.setores.lideres.store', setor.id), { onSuccess: onClose });
    };
    return (
        <ModalShell title="Adicionar líder" onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
                <p className="text-white/40 text-xs">
                    Líderes ganham acesso automático ao Dashboard de Liderança deste setor.
                </p>
                <Field label="Usuário" error={errors.user_id}>
                    <select value={data.user_id} onChange={e => setData('user_id', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80">
                        <option value="">— selecione —</option>
                        {todosUsers.map(u => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                    </select>
                </Field>
                <SubmitRow onCancel={onClose} processing={processing} label="Promover a líder" />
            </form>
        </ModalShell>
    );
}

// ─── Tab: Metas ─────────────────────────────────────────────────────────────
function TabMetas({ setor }) {
    return (
        <div className="card-ecf rounded-xl p-5">
            {setor.metas.length === 0 ? (
                <p className="text-white/40 text-sm">
                    Nenhuma meta definida pra este setor ainda. O líder pode criar metas no
                    dashboard de liderança (em <b>Meu Setor</b>).
                </p>
            ) : (
                <ul className="space-y-2">
                    {setor.metas.map(m => (
                        <li key={m.id} className="flex items-center justify-between p-3 rounded-lg border border-white/[0.06] bg-white/[0.02]">
                            <div>
                                <p className="text-white/85 text-[13px] font-medium">{m.metric}</p>
                                <p className="text-white/40 text-[11px]">{m.description}</p>
                            </div>
                            <div className="text-right">
                                <p className="text-white/85 font-bold tabular-nums">{m.target_value}</p>
                                <p className="text-white/40 text-[11px]">{m.period_type}</p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// ─── Tab: Configuração (editar setor / excluir) ─────────────────────────────
function TabConfig({ setor }) {
    const { data, setData, put, processing, errors } = useForm({
        nome: setor.nome, descricao: setor.descricao ?? '', active: setor.active,
    });

    const update = (e) => {
        e.preventDefault();
        put(route('admin.setores.update', setor.id), { preserveScroll: true });
    };

    const destroy = () => {
        if (!confirm(`Excluir setor "${setor.nome}"? Esta ação é irreversível.`)) return;
        router.delete(route('admin.setores.destroy', setor.id));
    };

    return (
        <div className="space-y-4">
            <form onSubmit={update} className="card-ecf rounded-xl p-5 space-y-3">
                <h3 className="text-white font-display font-semibold text-base mb-1">Editar setor</h3>
                <Field label="Nome" error={errors.nome}>
                    <input type="text" value={data.nome} onChange={e => setData('nome', e.target.value)} disabled={setor.is_system}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 disabled:opacity-50" />
                </Field>
                <Field label="Descrição" error={errors.descricao}>
                    <textarea value={data.descricao} onChange={e => setData('descricao', e.target.value)} rows={3}
                        className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 resize-none" />
                </Field>
                <label className="inline-flex items-center gap-2 text-white/70 text-xs">
                    <input type="checkbox" checked={data.active} onChange={e => setData('active', e.target.checked)} className="w-3.5 h-3.5 accent-ecf-yellow" />
                    Ativo
                </label>
                <div className="flex justify-end">
                    <button type="submit" disabled={processing}
                        className="h-9 px-4 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold">
                        {processing ? 'Salvando...' : 'Salvar alterações'}
                    </button>
                </div>
            </form>

            {!setor.is_system && (
                <div className="card-ecf rounded-xl p-5 border border-red-500/20">
                    <h3 className="text-red-300 font-display font-semibold text-base mb-2 flex items-center gap-2">
                        <AlertTriangle size={14} /> Zona perigosa
                    </h3>
                    <p className="text-white/50 text-xs mb-3">
                        Excluir o setor remove todos os vínculos com cargos, membros, líderes e metas.
                        Bloqueado se houver membros.
                    </p>
                    <button onClick={destroy}
                        className="h-9 px-4 rounded-lg border border-red-500/30 bg-red-500/10 text-red-300 hover:bg-red-500/20 text-[13px] font-bold">
                        Excluir setor
                    </button>
                </div>
            )}
        </div>
    );
}

// ─── Helpers reutilizáveis ──────────────────────────────────────────────────
function ModalShell({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-md p-6">
                <div className="flex items-start justify-between mb-4">
                    <h3 className="text-white font-display font-bold text-lg">{title}</h3>
                    <button onClick={onClose} className="text-white/40 hover:text-white"><X size={18} /></button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">{label}</label>
            {children}
            {error && <p className="text-red-400 text-xs mt-1">{error}</p>}
        </div>
    );
}

function SubmitRow({ onCancel, processing, label }) {
    return (
        <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onCancel}
                className="px-4 h-9 rounded-lg border border-white/[0.08] text-white/60 hover:text-white text-[13px]">
                Cancelar
            </button>
            <button type="submit" disabled={processing}
                className="px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold">
                {processing ? 'Salvando...' : label}
            </button>
        </div>
    );
}
