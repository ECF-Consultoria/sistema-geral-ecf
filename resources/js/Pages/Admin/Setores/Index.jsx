import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Users, Shield, Briefcase, Crown, Target, Plus, Lock, ChevronRight, X,
} from 'lucide-react';
import { cn } from '@/lib/utils';

export default function SetoresIndex({ setores }) {
    const [showNew, setShowNew] = useState(false);

    return (
        <AppLayout title="Setores">
            <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Setores</h1>
                    <p className="text-white/40 text-sm mt-1">
                        Define quais telas cada grupo de usuários pode ver, e quem lidera o setor.
                    </p>
                </div>
                <button
                    onClick={() => setShowNew(true)}
                    className="inline-flex items-center gap-2 h-9 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 text-[13px] font-bold"
                >
                    <Plus size={14} />
                    Novo setor
                </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {setores.map(s => (
                    <Link
                        key={s.id}
                        href={route('admin.setores.show', s.id)}
                        className="card-ecf rounded-xl p-4 hover:bg-white/[0.04] transition-colors group"
                    >
                        <div className="flex items-start justify-between mb-3">
                            <div className="flex items-center gap-2 min-w-0">
                                <Shield size={14} className={cn(s.is_system ? 'text-ecf-yellow' : 'text-white/40')} />
                                <h3 className="text-white font-display font-semibold text-base truncate">{s.nome}</h3>
                                {s.is_system && (
                                    <span title="Setor de sistema">
                                        <Lock size={11} className="text-ecf-yellow shrink-0" />
                                    </span>
                                )}
                            </div>
                            <ChevronRight size={14} className="text-white/30 group-hover:text-white/60" />
                        </div>

                        {s.descricao && (
                            <p className="text-white/50 text-xs mb-3 line-clamp-2">{s.descricao}</p>
                        )}

                        <div className="grid grid-cols-3 gap-2 text-[10px] uppercase tracking-wider">
                            <Stat icon={Users} label="Membros" value={s.membros_count ?? 0} />
                            <Stat icon={Briefcase} label="Cargos" value={s.cargos_count ?? 0} />
                            <Stat icon={Crown} label="Líderes" value={s.lideres_count ?? 0} />
                        </div>

                        {!s.active && (
                            <span className="mt-3 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-zinc-500/15 text-zinc-400 border border-zinc-500/30">
                                Inativo
                            </span>
                        )}
                    </Link>
                ))}
            </div>

            {showNew && <NovoSetorModal onClose={() => setShowNew(false)} />}
        </AppLayout>
    );
}

function Stat({ icon: Icon, label, value }) {
    return (
        <div className="flex flex-col">
            <span className="flex items-center gap-1 text-white/30">
                <Icon size={9} />
                {label}
            </span>
            <span className="text-white/80 font-bold tabular-nums text-sm normal-case tracking-normal">{value}</span>
        </div>
    );
}

function NovoSetorModal({ onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        nome: '',
        descricao: '',
        active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.setores.store'), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-md p-6">
                <div className="flex items-start justify-between mb-4">
                    <h3 className="text-white font-display font-bold text-lg">Novo setor</h3>
                    <button onClick={onClose} className="text-white/40 hover:text-white"><X size={18} /></button>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Nome</label>
                        <input
                            type="text"
                            value={data.nome}
                            onChange={e => setData('nome', e.target.value)}
                            placeholder="Ex: Marketing"
                            autoFocus
                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                        />
                        {errors.nome && <p className="text-red-400 text-xs mt-1">{errors.nome}</p>}
                    </div>
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Descrição</label>
                        <textarea
                            value={data.descricao}
                            onChange={e => setData('descricao', e.target.value)}
                            rows={3}
                            placeholder="O que este setor faz (opcional)"
                            className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                        />
                    </div>
                    <label className="inline-flex items-center gap-2 text-white/70 text-xs cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.active}
                            onChange={e => setData('active', e.target.checked)}
                            className="w-3.5 h-3.5 accent-ecf-yellow"
                        />
                        Ativo
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={onClose}
                            className="px-4 h-9 rounded-lg border border-white/[0.08] text-white/60 hover:text-white text-[13px] font-medium">
                            Cancelar
                        </button>
                        <button type="submit" disabled={processing}
                            className="px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold">
                            {processing ? 'Criando...' : 'Criar setor'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
