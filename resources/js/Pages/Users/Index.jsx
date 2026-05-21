import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, Users, Briefcase, Shield, AlertTriangle, RotateCcw, X, Star } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Vínculos (form local): array de objetos com {setor_id, cargo_id, is_principal}.
 * Backend recebe esse array e faz sync em user_setores.
 */
const initialForm = () => ({
    name: '', email: '', password: '', password_confirmation: '',
    is_admin: false,
    phone: '', active: true,
    vinculos: [],   // [{ setor_id, cargo_id, is_principal }]
});

export default function UsersIndex({ users, deletedUsers = [], setoresDisponiveis = [] }) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [forceDeleteTarget, setForceDeleteTarget] = useState(null);
    const [showDeleted, setShowDeleted] = useState(false);

    const filtered = users.filter(u =>
        u.name.toLowerCase().includes(search.toLowerCase()) ||
        u.email.toLowerCase().includes(search.toLowerCase())
    );

    const { data, setData, processing, reset, errors } = useForm(initialForm());

    const openCreate = () => {
        reset();
        setData(initialForm());
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (u) => {
        setEditing(u);
        setData({
            name:     u.name,
            email:    u.email,
            password: '',
            password_confirmation: '',
            is_admin: !!u.is_admin,
            phone:    u.phone || '',
            active:   u.active,
            vinculos: (u.setores || []).map(s => ({
                setor_id:     s.id,
                cargo_id:     s.cargo_id ?? null,
                is_principal: !!s.is_principal,
            })),
        });
        setOpen(true);
    };

    const addVinculo = () => {
        const usados = new Set(data.vinculos.map(v => v.setor_id));
        const livre = setoresDisponiveis.find(s => !usados.has(s.id));
        if (!livre) return;
        setData('vinculos', [...data.vinculos, {
            setor_id: livre.id,
            cargo_id: null,
            is_principal: data.vinculos.length === 0,
        }]);
    };

    const removeVinculo = (idx) => {
        const novos = data.vinculos.filter((_, i) => i !== idx);
        // Se removeu o principal, marca o primeiro restante como principal
        if (data.vinculos[idx]?.is_principal && novos.length > 0) {
            novos[0].is_principal = true;
        }
        setData('vinculos', novos);
    };

    const updateVinculo = (idx, patch) => {
        setData('vinculos', data.vinculos.map((v, i) => i === idx ? { ...v, ...patch } : v));
    };

    const setPrincipal = (idx) => {
        setData('vinculos', data.vinculos.map((v, i) => ({ ...v, is_principal: i === idx })));
    };

    const submit = (e) => {
        e.preventDefault();
        const payload = {
            name:     data.name,
            email:    data.email,
            password: data.password,
            password_confirmation: data.password_confirmation,
            is_admin: data.is_admin,
            phone:    data.phone,
            active:   data.active,
            vinculos: data.is_admin ? [] : data.vinculos,
        };
        const opts = { onSuccess: () => setOpen(false) };
        if (editing) router.put(route('users.update', editing.id), payload, opts);
        else router.post(route('users.store'), payload, opts);
    };

    const restore = (u) => router.post(route('users.restore', u.id));
    const confirmDelete = () => {
        if (deleteTarget) router.delete(route('users.destroy', deleteTarget.id), { onSuccess: () => setDeleteTarget(null) });
    };
    const confirmForceDelete = () => {
        if (forceDeleteTarget) router.delete(route('users.force-destroy', forceDeleteTarget.id), { onSuccess: () => setForceDeleteTarget(null) });
    };

    return (
        <AppLayout title="Usuários">
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Input
                        placeholder="Buscar usuário..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <div className="flex gap-2">
                        {deletedUsers.length > 0 && (
                            <Button variant="outline" onClick={() => setShowDeleted(v => !v)}>
                                <Trash2 className="h-4 w-4 mr-1 text-destructive" />
                                Lixeira ({deletedUsers.length})
                            </Button>
                        )}
                        <Button onClick={openCreate}>
                            <Plus className="h-4 w-4 mr-1" /> Novo Usuário
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>E-mail</TableHead>
                                    <TableHead>Setor(es) / Cargo</TableHead>
                                    <TableHead>Empresas</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Criado em</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.map(u => (
                                    <TableRow key={u.id}>
                                        <TableCell className="font-medium">{u.name}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{u.email}</TableCell>
                                        <TableCell>
                                            {u.is_admin ? (
                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-500/15 text-red-400 border border-red-500/30">
                                                    <Shield size={11} /> Admin
                                                </span>
                                            ) : u.setores?.length > 0 ? (
                                                <div className="flex flex-wrap gap-1">
                                                    {u.setores.map(s => (
                                                        <span key={s.id} className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] border ${
                                                            s.is_principal
                                                                ? 'bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/30'
                                                                : 'bg-white/[0.04] text-white/60 border-white/[0.08]'
                                                        }`}>
                                                            {s.nome}{s.cargo_nome ? ` · ${s.cargo_nome}` : ''}
                                                        </span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-white/30 italic text-sm">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">{u.companies_count}</TableCell>
                                        <TableCell>
                                            <Badge variant={u.active ? 'success' : 'destructive'}>
                                                {u.active ? 'Ativo' : 'Inativo'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{u.created_at}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                {!u.is_admin && (u.role === 'consultor' || u.role === 'mentor') && (
                                                    <Button size="icon" variant="ghost" title="Ver carteira" onClick={() => router.visit(route('portfolio.show', u.id))}>
                                                        <Briefcase className="h-4 w-4 text-ecf-yellow/60" />
                                                    </Button>
                                                )}
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(u)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button size="icon" variant="ghost" onClick={() => setDeleteTarget(u)} title="Excluir">
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {filtered.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            <Users className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            Nenhum usuário encontrado
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Modal criar/editar */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar Usuário' : 'Novo Usuário'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {/* Toggle Admin */}
                        <div className="grid grid-cols-2 gap-2">
                            <button type="button" onClick={() => setData('is_admin', false)}
                                className={`px-3 py-2 rounded-lg border text-sm font-semibold transition-colors ${
                                    !data.is_admin
                                        ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow'
                                        : 'bg-white/[0.02] border-white/[0.08] text-white/60 hover:text-white hover:border-white/20'
                                }`}>
                                <Users size={13} className="inline mr-1.5" /> Usuário
                            </button>
                            <button type="button" onClick={() => setData('is_admin', true)}
                                className={`px-3 py-2 rounded-lg border text-sm font-semibold transition-colors ${
                                    data.is_admin
                                        ? 'bg-red-500/15 border-red-500/40 text-red-400'
                                        : 'bg-white/[0.02] border-white/[0.08] text-white/60 hover:text-white hover:border-white/20'
                                }`}>
                                <Shield size={13} className="inline mr-1.5" /> Admin
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="col-span-2 space-y-1.5">
                                <Label>Nome *</Label>
                                <Input value={data.name} onChange={e => setData('name', e.target.value)} required />
                                {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>E-mail *</Label>
                                <Input type="email" value={data.email} onChange={e => setData('email', e.target.value)} required />
                                {errors.email && <p className="text-destructive text-xs">{errors.email}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Telefone</Label>
                                <Input value={data.phone} onChange={e => setData('phone', e.target.value)} placeholder="(11) 99999-9999" />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Senha {editing ? '(deixe vazio para manter)' : '*'}</Label>
                                <Input type="password" value={data.password} onChange={e => setData('password', e.target.value)} required={!editing} />
                                {errors.password && <p className="text-destructive text-xs">{errors.password}</p>}
                            </div>
                            {data.password && (
                                <div className="space-y-1.5">
                                    <Label>Confirmar Senha</Label>
                                    <Input type="password" value={data.password_confirmation} onChange={e => setData('password_confirmation', e.target.value)} />
                                </div>
                            )}
                        </div>

                        {/* Vínculos com setores (só pra não-admin) */}
                        {!data.is_admin && (
                            <div className="pt-3 border-t border-white/[0.06]">
                                <div className="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 className="text-white font-display font-semibold text-[14px] flex items-center gap-2">
                                            <Briefcase size={14} className="text-ecf-yellow" />
                                            Setores e cargos
                                        </h3>
                                        <p className="text-white/40 text-[11px] mt-0.5">
                                            Defina em quais setores o usuário atua e qual cargo ocupa em cada.
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={addVinculo}
                                        disabled={data.vinculos.length >= setoresDisponiveis.length}
                                    >
                                        <Plus className="h-3.5 w-3.5 mr-1" /> Adicionar setor
                                    </Button>
                                </div>

                                {data.vinculos.length === 0 ? (
                                    <div className="text-center py-6 rounded-lg border border-dashed border-white/[0.08] bg-white/[0.02]">
                                        <Briefcase size={20} className="text-white/20 mx-auto mb-2" />
                                        <p className="text-white/40 text-xs">Sem setor vinculado.</p>
                                        <p className="text-white/30 text-[11px]">Adicione pelo menos 1 para o usuário ter acesso ao sistema.</p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {data.vinculos.map((v, idx) => {
                                            const setor = setoresDisponiveis.find(s => s.id === v.setor_id);
                                            const cargosDoSetor = setor?.cargos ?? [];
                                            const cargoErr  = errors[`vinculos.${idx}.cargo_id`];
                                            const setorErr  = errors[`vinculos.${idx}.setor_id`];
                                            return (
                                                <div key={idx} className={cn(
                                                    'rounded-xl border p-3 transition-colors',
                                                    v.is_principal
                                                        ? 'border-ecf-yellow/30 bg-ecf-yellow/[0.03]'
                                                        : 'border-white/[0.06] bg-white/[0.02]'
                                                )}>
                                                    <div className="flex items-start gap-3">
                                                        <div className="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                            <div>
                                                                <label className="block text-white/40 text-[10px] font-semibold uppercase tracking-wider mb-1">Setor</label>
                                                                <select
                                                                    value={v.setor_id}
                                                                    onChange={e => updateVinculo(idx, { setor_id: parseInt(e.target.value), cargo_id: null })}
                                                                    className={cn(
                                                                        "w-full h-9 px-2.5 rounded-lg border bg-white/[0.03] text-[13px] text-white/90 focus:outline-none focus:border-ecf-yellow/40",
                                                                        setorErr ? 'border-red-500/40' : 'border-white/[0.08]'
                                                                    )}
                                                                >
                                                                    {setoresDisponiveis.map(s => (
                                                                        <option key={s.id} value={s.id}
                                                                            disabled={s.id !== v.setor_id && data.vinculos.some(x => x.setor_id === s.id)}>
                                                                            {s.nome}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                {setorErr && <p className="text-red-400 text-[10px] mt-1">{setorErr}</p>}
                                                            </div>
                                                            <div>
                                                                <label className="block text-white/40 text-[10px] font-semibold uppercase tracking-wider mb-1">Cargo</label>
                                                                <select
                                                                    value={v.cargo_id ?? ''}
                                                                    onChange={e => updateVinculo(idx, { cargo_id: e.target.value ? parseInt(e.target.value) : null })}
                                                                    className={cn(
                                                                        "w-full h-9 px-2.5 rounded-lg border bg-white/[0.03] text-[13px] text-white/90 focus:outline-none focus:border-ecf-yellow/40",
                                                                        cargoErr ? 'border-red-500/40' : 'border-white/[0.08]'
                                                                    )}
                                                                    disabled={cargosDoSetor.length === 0}
                                                                >
                                                                    <option value="">— sem cargo —</option>
                                                                    {cargosDoSetor.map(c => (
                                                                        <option key={c.id} value={c.id}>{c.nome}</option>
                                                                    ))}
                                                                </select>
                                                                {cargoErr && <p className="text-red-400 text-[10px] mt-1">{cargoErr}</p>}
                                                                {!cargoErr && cargosDoSetor.length === 0 && (
                                                                    <p className="text-white/30 text-[10px] mt-1">Setor sem cargos cadastrados.</p>
                                                                )}
                                                            </div>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => removeVinculo(idx)}
                                                            title="Remover este setor"
                                                            className="text-white/30 hover:text-red-400 p-1 mt-5 transition-colors"
                                                        >
                                                            <Trash2 size={14} />
                                                        </button>
                                                    </div>

                                                    {/* Toggle principal */}
                                                    <div className="mt-3 pt-2 border-t border-white/[0.04] flex items-center justify-between">
                                                        <button
                                                            type="button"
                                                            onClick={() => setPrincipal(idx)}
                                                            disabled={v.is_principal}
                                                            className={cn(
                                                                'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border transition-colors',
                                                                v.is_principal
                                                                    ? 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30 cursor-default'
                                                                    : 'text-white/50 hover:text-white border-white/[0.08] hover:bg-white/[0.04]'
                                                            )}
                                                        >
                                                            {v.is_principal ? <Star size={10} fill="currentColor" /> : <Star size={10} />}
                                                            {v.is_principal ? 'Setor principal' : 'Marcar como principal'}
                                                        </button>
                                                        {setor && (
                                                            <span className="text-white/30 text-[10px] font-mono">{setor.slug}</span>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                {/* Erros globais de vinculos (caso o backend mande mensagens não-indexadas) */}
                                {errors.vinculos && typeof errors.vinculos === 'string' && (
                                    <p className="text-red-400 text-xs mt-2">{errors.vinculos}</p>
                                )}

                                <p className="text-white/30 text-[11px] mt-3 leading-relaxed">
                                    Permissões do usuário são a união de todas as permissões dos setores em que ele é membro.
                                    Configure cada setor em <b>Administração → Setores</b>.
                                </p>
                            </div>
                        )}

                        {data.is_admin && (
                            <div className="rounded-md bg-red-500/10 border border-red-500/20 p-3 text-xs text-red-300">
                                <b>Admin</b> tem acesso a tudo no sistema. Setor "Administração" será atribuído automaticamente.
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Salvando...' : editing ? 'Atualizar' : 'Criar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Lixeira */}
            {showDeleted && deletedUsers.length > 0 && (
                <Card className="border-destructive/30 mt-4">
                    <CardContent className="p-0">
                        <div className="px-4 py-3 border-b border-destructive/20 flex items-center gap-2">
                            <Trash2 className="h-4 w-4 text-destructive" />
                            <span className="text-sm font-medium text-destructive">Usuários excluídos</span>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>E-mail</TableHead>
                                    <TableHead>Empresas</TableHead>
                                    <TableHead>Excluído em</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {deletedUsers.map(u => (
                                    <TableRow key={u.id} className="opacity-70">
                                        <TableCell className="font-medium">{u.name}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{u.email}</TableCell>
                                        <TableCell className="text-sm">{u.companies_count}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{u.deleted_at}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="outline" onClick={() => restore(u)} title="Restaurar">
                                                    <RotateCcw className="h-3.5 w-3.5 mr-1" /> Restaurar
                                                </Button>
                                                <Button size="icon" variant="ghost" onClick={() => setForceDeleteTarget(u)} title="Excluir permanentemente">
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}

            {/* Confirm soft delete */}
            <Dialog open={!!deleteTarget} onOpenChange={v => !v && setDeleteTarget(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Trash2 className="h-5 w-5 text-destructive" /> Excluir usuário
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <p className="text-muted-foreground">
                            Excluir <span className="font-semibold text-foreground">{deleteTarget?.name}</span>?
                        </p>
                        <div className="rounded-md bg-yellow-500/10 border border-yellow-500/20 px-3 py-2 text-yellow-300 text-xs">
                            Vai para a lixeira (pode restaurar depois). Registros vinculados são preservados.
                        </div>
                    </div>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setDeleteTarget(null)}>Cancelar</Button>
                        <Button variant="destructive" onClick={confirmDelete}>Excluir</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Confirm force delete */}
            <Dialog open={!!forceDeleteTarget} onOpenChange={v => !v && setForceDeleteTarget(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertTriangle className="h-5 w-5" /> Exclusão permanente
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <p className="text-muted-foreground">
                            Remover <span className="font-semibold text-foreground">{forceDeleteTarget?.name}</span> permanentemente?
                        </p>
                        <div className="rounded-md bg-destructive/10 border border-destructive/30 px-3 py-2 text-red-400 text-xs">
                            Esta ação é irreversível.
                        </div>
                    </div>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setForceDeleteTarget(null)}>Cancelar</Button>
                        <Button variant="destructive" onClick={confirmForceDelete}>Excluir permanentemente</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
