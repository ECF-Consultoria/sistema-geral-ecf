import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ComboInput from '@/Components/ComboInput';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, Users, Briefcase, BarChart2, Settings2, Shield, AlertTriangle, RotateCcw } from 'lucide-react';

const ALL_PUB_PERMISSIONS = [
    { key: 'treinamento', label: 'Treinamentos' },
    { key: 'meu_painel',  label: 'Meu Painel' },
    { key: 'publicacoes', label: 'Publicações' },
    { key: 'vendas',      label: 'Vendas' },
    { key: 'historico',   label: 'Histórico' },
    { key: 'revisao',     label: 'Revisão' },
    { key: 'empresas',    label: 'Empresas' },
    { key: 'dashboard',   label: 'Dashboard Equipe' },
    { key: 'projetos',    label: 'Projetos' },
];

// Setores padrão (não removíveis)
const DEFAULT_SETORES = ['Publicação', 'Marketing', 'Administrativo'];

const NO_PUB_ROLE = '__none__';

const initialForm = () => ({
    name: '', email: '', password: '', password_confirmation: '',
    is_admin: false,
    setor: '', phone: '', active: true,
    publication_role: NO_PUB_ROLE, publication_meta: 220,
    pub_perms_custom: false,
    publication_permissions: [],
});

export default function UsersIndex({ users, deletedUsers = [], setoresDb = [] }) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [forceDeleteTarget, setForceDeleteTarget] = useState(null);
    const [showDeleted, setShowDeleted] = useState(false);

    const setorOptions = [...new Set([...DEFAULT_SETORES, ...(setoresDb ?? [])])];

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
        const hasCustomPerms = u.publication_permissions !== null && u.publication_permissions !== undefined;
        setData({
            name:                    u.name,
            email:                   u.email,
            password:                '',
            password_confirmation:   '',
            is_admin:                !!u.is_admin,
            setor:                   u.setor || '',
            phone:                   u.phone || '',
            active:                  u.active,
            publication_role:        u.publication_role || NO_PUB_ROLE,
            publication_meta:        u.publication_meta ?? 220,
            pub_perms_custom:        hasCustomPerms,
            publication_permissions: u.publication_permissions ?? [],
        });
        setOpen(true);
    };

    const togglePerm = (key) => {
        const current = data.publication_permissions ?? [];
        setData('publication_permissions',
            current.includes(key) ? current.filter(k => k !== key) : [...current, key]
        );
    };

    const submit = (e) => {
        e.preventDefault();
        const payload = data.is_admin
            ? {
                name:     data.name,
                email:    data.email,
                password: data.password,
                password_confirmation: data.password_confirmation,
                is_admin: true,
                phone:    data.phone,
                active:   data.active,
            }
            : {
                name:             data.name,
                email:            data.email,
                password:         data.password,
                password_confirmation: data.password_confirmation,
                is_admin:         false,
                setor:            data.setor,
                phone:            data.phone,
                active:           data.active,
                publication_role: data.publication_role === NO_PUB_ROLE ? null : data.publication_role,
                publication_meta: data.publication_meta,
                publication_permissions: data.pub_perms_custom ? data.publication_permissions : null,
            };

        if (editing) {
            router.put(route('users.update', editing.id), payload, { onSuccess: () => setOpen(false) });
        } else {
            router.post(route('users.store'), payload, { onSuccess: () => setOpen(false) });
        }
    };

    const isAdmin    = data.is_admin;
    const hasPubRole = data.publication_role !== NO_PUB_ROLE;
    const setor      = data.setor;
    const isSetorPub = setor === 'Publicação';
    const isSetorMkt = setor === 'Marketing';
    const isSetorAdm = setor === 'Administrativo';
    // Mostra módulo pub se setor for Publicação, OU se já tem papel (backward compat)
    const showModPub = !isAdmin && (isSetorPub || hasPubRole);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(route('users.destroy', deleteTarget.id), {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const confirmForceDelete = () => {
        if (!forceDeleteTarget) return;
        router.delete(route('users.force-destroy', forceDeleteTarget.id), {
            onSuccess: () => setForceDeleteTarget(null),
        });
    };

    const restore = (u) => {
        router.post(route('users.restore', u.id));
    };

    const handleDeleteSetor = (valor) => {
        router.delete(route('users.opcao-setor.destroy'), {
            data: { valor },
            preserveScroll: true,
            preserveState: false,
        });
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
                                    <TableHead>Setor / Tipo</TableHead>
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
                                            ) : (
                                                <span className="text-sm text-white/80">
                                                    {u.setor || <span className="text-white/30 italic">—</span>}
                                                </span>
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
                                                <Button size="icon" variant="ghost" onClick={() => setDeleteTarget(u)} title="Excluir permanentemente">
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

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar Usuário' : 'Novo Usuário'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">

                        {/* Tipo de acesso: Usuário / Admin */}
                        <div className="grid grid-cols-2 gap-2">
                            <button type="button"
                                onClick={() => setData('is_admin', false)}
                                className={`px-3 py-2 rounded-lg border text-sm font-semibold transition-colors ${
                                    !data.is_admin
                                        ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow'
                                        : 'bg-white/[0.02] border-white/[0.08] text-white/60 hover:text-white hover:border-white/20'
                                }`}>
                                <Users size={13} className="inline mr-1.5" /> Usuário
                            </button>
                            <button type="button"
                                onClick={() => setData('is_admin', true)}
                                className={`px-3 py-2 rounded-lg border text-sm font-semibold transition-colors ${
                                    data.is_admin
                                        ? 'bg-red-500/15 border-red-500/40 text-red-400'
                                        : 'bg-white/[0.02] border-white/[0.08] text-white/60 hover:text-white hover:border-white/20'
                                }`}>
                                <Shield size={13} className="inline mr-1.5" /> Admin
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            {/* Nome */}
                            <div className="col-span-2 space-y-1.5">
                                <Label>Nome *</Label>
                                <Input
                                    value={data.name}
                                    onChange={e => setData('name', e.target.value)}
                                    required
                                />
                                {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                            </div>

                            {/* Email */}
                            <div className="space-y-1.5">
                                <Label>E-mail *</Label>
                                <Input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    required
                                />
                                {errors.email && <p className="text-destructive text-xs">{errors.email}</p>}
                            </div>

                            {/* Telefone */}
                            <div className="space-y-1.5">
                                <Label>Telefone</Label>
                                <Input
                                    value={data.phone}
                                    onChange={e => setData('phone', e.target.value)}
                                    placeholder="(11) 99999-9999"
                                />
                            </div>

                            {/* Senha */}
                            <div className="space-y-1.5">
                                <Label>Senha {editing ? '(deixe vazio para manter)' : '*'}</Label>
                                <Input
                                    type="password"
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                    required={!editing}
                                />
                                {errors.password && <p className="text-destructive text-xs">{errors.password}</p>}
                            </div>

                            {data.password && (
                                <div className="space-y-1.5">
                                    <Label>Confirmar Senha</Label>
                                    <Input
                                        type="password"
                                        value={data.password_confirmation}
                                        onChange={e => setData('password_confirmation', e.target.value)}
                                    />
                                </div>
                            )}

                            {/* Setor (ComboInput) — só pra Usuário */}
                            {!data.is_admin && (
                                <div className="col-span-2 space-y-1.5">
                                    <Label>Setor</Label>
                                    <ComboInput
                                        value={data.setor}
                                        onChange={v => setData('setor', v)}
                                        options={setorOptions}
                                        defaults={DEFAULT_SETORES}
                                        placeholder="Selecione ou crie um setor"
                                        onDelete={handleDeleteSetor}
                                    />
                                    {errors.setor && <p className="text-destructive text-xs">{errors.setor}</p>}
                                </div>
                            )}

                            {/* ── Módulo Publicação — setor Publicação ou backward compat ── */}
                            {showModPub && (
                                <div className="col-span-2 pt-2 border-t border-white/[0.06]">
                                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-3 flex items-center gap-1.5">
                                        <BarChart2 size={12} /> Módulo Publicação
                                    </p>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-1.5">
                                            <Label>Papel na Publicação</Label>
                                            <Select
                                                value={data.publication_role}
                                                onValueChange={v => setData('publication_role', v)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Sem acesso" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NO_PUB_ROLE}>Sem acesso</SelectItem>
                                                    <SelectItem value="gestor">Gestor (visão da equipe)</SelectItem>
                                                    <SelectItem value="analista">Analista (cadastra empresas/SKUs)</SelectItem>
                                                    <SelectItem value="lider">Líder (revisa + publica + atribui)</SelectItem>
                                                    <SelectItem value="publicador">Publicador</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Meta Mensal</Label>
                                            <Input
                                                type="number"
                                                min={0}
                                                max={9999}
                                                value={data.publication_meta}
                                                onChange={e => {
                                                    const v = parseInt(e.target.value);
                                                    setData('publication_meta', isNaN(v) ? 0 : v);
                                                }}
                                                disabled={!hasPubRole}
                                                placeholder="220"
                                            />
                                            <p className="text-white/30 text-[11px]">Anúncios/mês (0 = sem meta)</p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* ── Permissões Publicação ── */}
                            {showModPub && hasPubRole && (
                                <div className="col-span-2 pt-2 border-t border-white/[0.06]">
                                    <div className="flex items-center justify-between mb-3">
                                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide flex items-center gap-1.5">
                                            <Settings2 size={12} /> Permissões Publicação
                                        </p>
                                        <label className="flex items-center gap-2 cursor-pointer select-none">
                                            <span className="text-white/40 text-[11px]">Personalizar</span>
                                            <button
                                                type="button"
                                                onClick={() => setData('pub_perms_custom', !data.pub_perms_custom)}
                                                className={`relative w-9 h-5 rounded-full transition-colors ${data.pub_perms_custom ? 'bg-ecf-yellow' : 'bg-white/10'}`}
                                            >
                                                <span className={`absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform ${data.pub_perms_custom ? 'translate-x-4' : ''}`} />
                                            </button>
                                        </label>
                                    </div>

                                    {data.pub_perms_custom ? (
                                        <div className="grid grid-cols-2 gap-2">
                                            {ALL_PUB_PERMISSIONS.map(p => (
                                                <label key={p.key} className="flex items-center gap-2 cursor-pointer py-1">
                                                    <input
                                                        type="checkbox"
                                                        checked={(data.publication_permissions ?? []).includes(p.key)}
                                                        onChange={() => togglePerm(p.key)}
                                                        className="w-4 h-4 rounded accent-ecf-yellow"
                                                    />
                                                    <span className="text-white/70 text-[13px]">{p.label}</span>
                                                </label>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-white/30 text-[12px]">
                                            Usando permissões padrão do papel. Ative "Personalizar" para controlar o acesso individualmente.
                                        </p>
                                    )}
                                </div>
                            )}

                            {/* ── Módulo Marketing ── */}
                            {!isAdmin && isSetorMkt && (
                                <div className="col-span-2 pt-2 border-t border-white/[0.06]">
                                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-3">Módulo Marketing</p>
                                    <p className="text-white/30 text-[12px]">Módulo em desenvolvimento.</p>
                                </div>
                            )}

                            {/* ── Módulo Administrativo ── */}
                            {!isAdmin && isSetorAdm && (
                                <div className="col-span-2 pt-2 border-t border-white/[0.06]">
                                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-3">Módulo Administrativo</p>
                                    <p className="text-white/30 text-[12px]">Módulo em desenvolvimento.</p>
                                </div>
                            )}

                            {/* ── Módulo setor personalizado ── */}
                            {!isAdmin && setor && !isSetorPub && !isSetorMkt && !isSetorAdm && (
                                <div className="col-span-2 pt-2 border-t border-white/[0.06]">
                                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-3">Módulo {setor}</p>
                                    <p className="text-white/30 text-[12px]">Módulo em desenvolvimento.</p>
                                </div>
                            )}
                        </div>

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
            {/* Seção de lixeira */}
            {showDeleted && deletedUsers.length > 0 && (
                <Card className="border-destructive/30">
                    <CardContent className="p-0">
                        <div className="px-4 py-3 border-b border-destructive/20 flex items-center gap-2">
                            <Trash2 className="h-4 w-4 text-destructive" />
                            <span className="text-sm font-medium text-destructive">Usuários excluídos — podem ser restaurados</span>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>E-mail</TableHead>
                                    <TableHead>Setor</TableHead>
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
                                        <TableCell className="text-sm">{u.setor || '—'}</TableCell>
                                        <TableCell className="text-sm">{u.companies_count}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{u.deleted_at}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="outline" onClick={() => restore(u)} title="Restaurar usuário">
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

            {/* Modal confirmação de exclusão (soft delete — reversível) */}
            <Dialog open={!!deleteTarget} onOpenChange={v => !v && setDeleteTarget(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Trash2 className="h-5 w-5 text-destructive" /> Excluir usuário
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <p className="text-muted-foreground">
                            Excluir o usuário{' '}
                            <span className="font-semibold text-foreground">{deleteTarget?.name}</span>?
                        </p>
                        <div className="rounded-md bg-yellow-500/10 border border-yellow-500/20 px-3 py-2 text-yellow-300 text-xs">
                            O usuário vai para a lixeira e pode ser restaurado depois. Todos os registros dele (publicações, reuniões, etc.) são preservados.
                        </div>
                        {deleteTarget?.companies_count > 0 && (
                            <p className="text-muted-foreground text-xs">
                                Este usuário tem <span className="font-semibold text-foreground">{deleteTarget.companies_count}</span> empresa(s) vinculada(s).
                            </p>
                        )}
                    </div>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setDeleteTarget(null)}>Cancelar</Button>
                        <Button variant="destructive" onClick={confirmDelete}>Excluir</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Modal exclusão permanente (irreversível) */}
            <Dialog open={!!forceDeleteTarget} onOpenChange={v => !v && setForceDeleteTarget(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertTriangle className="h-5 w-5" /> Exclusão permanente
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <p className="text-muted-foreground">
                            Remover permanentemente o usuário{' '}
                            <span className="font-semibold text-foreground">{forceDeleteTarget?.name}</span>?
                        </p>
                        <div className="rounded-md bg-destructive/10 border border-destructive/30 px-3 py-2 text-red-400 text-xs">
                            Esta ação é irreversível. O usuário será apagado do banco de dados para sempre.
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
