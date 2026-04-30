import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, UserX, Users, Briefcase } from 'lucide-react';

export default function UsersIndex({ users }) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const filtered = users.filter(u =>
        u.name.toLowerCase().includes(search.toLowerCase()) ||
        u.email.toLowerCase().includes(search.toLowerCase())
    );

    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: '', email: '', password: '', password_confirmation: '',
        role: 'consultor', phone: '', active: true,
    });

    const openCreate = () => {
        reset();
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (u) => {
        setEditing(u);
        setData({ name: u.name, email: u.email, password: '', password_confirmation: '', role: u.role, phone: u.phone || '', active: u.active });
        setOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('users.update', editing.id), { onSuccess: () => setOpen(false) });
        } else {
            post(route('users.store'), { onSuccess: () => setOpen(false) });
        }
    };

    const deactivate = (id) => {
        if (confirm('Desativar este usuário?')) {
            router.delete(route('users.destroy', id));
        }
    };

    const roleColor = { consultor: 'default', mentor: 'secondary' };
    const roleLabel = { consultor: 'Analista', mentor: 'Mentor' };

    return (
        <AppLayout title="Usuários">
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Input placeholder="Buscar usuário..." value={search} onChange={e => setSearch(e.target.value)} className="max-w-sm" />
                    <Button onClick={openCreate}>
                        <Plus className="h-4 w-4 mr-1" /> Novo Usuário
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>E-mail</TableHead>
                                    <TableHead>Cargo</TableHead>
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
                                            <Badge variant={roleColor[u.role]}>{roleLabel[u.role]}</Badge>
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
                                                {(u.role === 'consultor' || u.role === 'mentor') && (
                                                    <Button size="icon" variant="ghost" title="Ver carteira" onClick={() => router.visit(route('portfolio.show', u.id))}>
                                                        <Briefcase className="h-4 w-4 text-ecf-yellow/60" />
                                                    </Button>
                                                )}
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(u)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                {u.active && (
                                                    <Button size="icon" variant="ghost" onClick={() => deactivate(u.id)}>
                                                        <UserX className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                )}
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
                                <Label>Cargo *</Label>
                                <Select value={data.role} onValueChange={v => setData('role', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="consultor">Analista</SelectItem>
                                        <SelectItem value="mentor">Mentor</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Senha {editing ? '(deixe vazio para manter)' : '*'}</Label>
                                <Input type="password" value={data.password} onChange={e => setData('password', e.target.value)} required={!editing} />
                                {errors.password && <p className="text-destructive text-xs">{errors.password}</p>}
                            </div>
                            {data.password && (
                                <div className="space-y-1.5 col-span-2">
                                    <Label>Confirmar Senha</Label>
                                    <Input type="password" value={data.password_confirmation} onChange={e => setData('password_confirmation', e.target.value)} />
                                </div>
                            )}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Salvando...' : editing ? 'Atualizar' : 'Criar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
