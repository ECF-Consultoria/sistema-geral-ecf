import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, FileText, LayoutDashboard } from 'lucide-react';

const statusColor = { draft: 'secondary', sent: 'default', completed: 'success' };
const statusLabel = { draft: 'Rascunho', sent: 'Enviado', completed: 'Concluído' };

function ProgressBar({ done, total }) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    return (
        <div className="flex items-center gap-2">
            <div className="w-16 h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                <div
                    className="h-full rounded-full bg-emerald-500 transition-all"
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="text-white/40 text-xs">{done}/{total}</span>
        </div>
    );
}

export default function PpaIndex({ ppas, companies }) {
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, processing, reset } = useForm({
        company_id: '', title: '', description: '', due_date: '',
        trello_board_url: '',
    });

    const editForm = useForm({
        title: '', status: 'draft', description: '', due_date: '',
        trello_board_url: '',
    });

    const openCreate = () => { reset(); setOpen(true); };

    const openEdit = (p) => {
        setEditing(p);
        editForm.setData({
            title: p.title,
            status: p.status,
            description: '',
            due_date: p.due_date || '',
            trello_board_url: p.trello_board_url || '',
        });
        setEditOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('ppa.store'), { onSuccess: () => { reset(); setOpen(false); } });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('ppa.update', editing.id), { onSuccess: () => setEditOpen(false) });
    };

    const remove = (id) => {
        if (confirm('Remover este PPA?')) router.delete(route('ppa.destroy', id));
    };

    return (
        <AppLayout title="PPA — Plano Prático de Ação">
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <p className="text-white/40 text-sm">{ppas.total} PPA(s)</p>
                    <Button onClick={openCreate}>
                        <Plus className="h-4 w-4 mr-1" /> Novo PPA
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Título</TableHead>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Mentor</TableHead>
                                    <TableHead>Tarefas</TableHead>
                                    <TableHead>Prazo</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ppas.data?.map(p => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-medium max-w-[180px] truncate">{p.title}</TableCell>
                                        <TableCell className="text-sm">{p.company_name}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{p.mentor_name}</TableCell>
                                        <TableCell>
                                            <ProgressBar done={p.tasks_done} total={p.tasks_count} />
                                        </TableCell>
                                        <TableCell className="text-sm">{p.due_date || '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={statusColor[p.status]}>{statusLabel[p.status]}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    title="Abrir Kanban"
                                                    onClick={() => router.get(route('ppa.kanban', p.id))}
                                                >
                                                    <LayoutDashboard className="h-4 w-4" />
                                                </Button>
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(p)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button size="icon" variant="ghost" onClick={() => remove(p.id)}>
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {(!ppas.data || ppas.data.length === 0) && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center py-10 text-muted-foreground">
                                            <FileText className="h-8 w-8 mx-auto mb-2 opacity-30" />
                                            Nenhum PPA encontrado
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {ppas.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {ppas.current_page > 1 && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route('ppa.index'), { page: ppas.current_page - 1 })}>Anterior</Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">Página {ppas.current_page} de {ppas.last_page}</span>
                        {ppas.current_page < ppas.last_page && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route('ppa.index'), { page: ppas.current_page + 1 })}>Próxima</Button>
                        )}
                    </div>
                )}
            </div>

            {/* Dialog: Novo PPA */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Novo PPA</DialogTitle>
                        <DialogDescription>Crie o plano — adicione as tarefas no quadro Kanban depois.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Empresa *</Label>
                                <Select value={data.company_id} onValueChange={v => setData('company_id', v)} required>
                                    <SelectTrigger><SelectValue placeholder="Selecionar..." /></SelectTrigger>
                                    <SelectContent>
                                        {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Prazo</Label>
                                <Input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)} />
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Título *</Label>
                                <Input value={data.title} onChange={e => setData('title', e.target.value)} required />
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Descrição / Análise</Label>
                                <Textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing || !data.company_id || !data.title}>
                                {processing ? 'Salvando...' : 'Criar PPA'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Editar PPA */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Editar PPA</DialogTitle>
                        <DialogDescription>{editing?.title}</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <form onSubmit={submitEdit} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Título</Label>
                                <Input value={editForm.data.title} onChange={e => editForm.setData('title', e.target.value)} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Status</Label>
                                    <Select value={editForm.data.status} onValueChange={v => editForm.setData('status', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="draft">Rascunho</SelectItem>
                                            <SelectItem value="sent">Enviado</SelectItem>
                                            <SelectItem value="completed">Concluído</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Prazo</Label>
                                    <Input type="date" value={editForm.data.due_date} onChange={e => editForm.setData('due_date', e.target.value)} />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editForm.processing}>
                                    {editForm.processing ? 'Salvando...' : 'Salvar'}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
