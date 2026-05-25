import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { useForm, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Eye, Trash2, Building2 } from 'lucide-react';
import { formatCurrency, formatPercent } from '@/lib/utils';

export default function Companies({ companies, users, estrategistas = [], empresas_pendentes = [] }) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const consultores = users.filter(u => u.role === 'consultor');
    // "Mentor" foi renomeado pra "Estrategista" (DB + UI). Lista vem do backend
    // filtrada pelo cargo `estrategista` no pivot user_setores.
    const estrategistasOptions = estrategistas.length > 0 ? estrategistas : users.filter(u => u.role === 'mentor');

    const filtered = companies.filter(c =>
        c.name.toLowerCase().includes(search.toLowerCase()) ||
        (c.segment || '').toLowerCase().includes(search.toLowerCase())
    );

    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: '', cnpj: '', adman_store_id: '',
        ml_store_id: '', segment: '', notes: '', consultor_id: '', estrategista_id: '',
    });

    const openCreate = () => {
        reset();
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (c) => {
        setEditing(c);
        setData({
            name: c.name || '',
            cnpj: c.cnpj || '',
            adman_store_id: c.adman_store_id || '',
            ml_store_id: c.ml_store_id || '',
            segment: c.segment || '',
            notes: c.notes || '',
            consultor_id: String(c.consultor?.id || ''),
            estrategista_id: String(c.estrategista?.id || ''),
        });
        setOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('companies.update', editing.id), { onSuccess: () => setOpen(false) });
        } else {
            post(route('companies.store'), { onSuccess: () => setOpen(false) });
        }
    };

    const destroy = (c) => {
        if (confirm(`Excluir a empresa "${c.name}"? Esta ação não pode ser desfeita.`)) {
            router.delete(route('companies.destroy', c.id));
        }
    };

    return (
        <AppLayout title="Empresas">
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Input
                        placeholder="Buscar empresa..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <Button onClick={openCreate}>
                        <Plus className="h-4 w-4 mr-1" /> Nova Empresa
                    </Button>
                </div>

                {/* Seção Pendentes — companies cadastradas pelo Comercial aguardando Publicidade/Gestão */}
                {empresas_pendentes.length > 0 && (
                    <div className="rounded-2xl border border-ecf-yellow/20 bg-ecf-yellow/[0.03] p-5">
                        <div className="flex items-center gap-2 mb-3">
                            <h2 className="text-white font-semibold text-[14px]">Empresas Pendentes</h2>
                            <span className="inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow text-[10px] font-bold">
                                {empresas_pendentes.length}
                            </span>
                            <span className="text-white/30 text-[12px] ml-1">cadastradas pelo Comercial, aguardando complemento de dados</span>
                        </div>
                        <div className="space-y-2">
                            {empresas_pendentes.map(p => (
                                <div key={p.id} className="flex items-center gap-3 rounded-xl border border-ecf-yellow/10 bg-ecf-yellow/[0.03] px-4 py-2.5">
                                    <span className="text-white text-[13px] font-medium flex-1">{p.name}</span>
                                    <span className="text-ecf-yellow/60 text-[11px] font-medium capitalize">{p.service_type}</span>
                                    <span className="text-white/25 text-[11px]">
                                        {new Date(p.created_at).toLocaleDateString('pt-BR')}
                                    </span>
                                    <button
                                        onClick={() => openEdit(p)}
                                        className="inline-flex items-center gap-1 text-ecf-yellow text-[11px] font-medium hover:text-ecf-yellow/70 transition-colors whitespace-nowrap"
                                    >
                                        <Pencil size={11} />
                                        Preencher dados
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Segmento</TableHead>
                                    <TableHead>Analista</TableHead>
                                    <TableHead>Estrategista</TableHead>
                                    <TableHead>TACOS</TableHead>
                                    <TableHead>Faturamento</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.map(c => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-medium">{c.name}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{c.segment || '-'}</TableCell>
                                        <TableCell className="text-sm">{c.consultor?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                        <TableCell className="text-sm">{c.estrategista?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                        <TableCell>
                                            {c.tacos ? <span className="text-yellow-400 font-medium">{formatPercent(c.tacos)}</span> : <span className="text-muted-foreground">-</span>}
                                        </TableCell>
                                        <TableCell>
                                            {c.revenue ? <span className="text-blue-400 font-medium">{formatCurrency(c.revenue)}</span> : <span className="text-muted-foreground">-</span>}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={c.active ? 'success' : 'destructive'}>
                                                {c.active ? 'Ativa' : 'Inativa'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Link href={route('companies.show', c.id)}>
                                                    <Button size="icon" variant="ghost"><Eye className="h-4 w-4" /></Button>
                                                </Link>
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(c)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="text-red-400 hover:text-red-300 hover:bg-red-500/10"
                                                    onClick={() => destroy(c)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {filtered.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                                            <Building2 className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            Nenhuma empresa encontrada
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar Empresa' : 'Nova Empresa'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Nome *</Label>
                                <Input value={data.name} onChange={e => setData('name', e.target.value)} required />
                                {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>CNPJ</Label>
                                <Input value={data.cnpj} onChange={e => setData('cnpj', e.target.value)} placeholder="00.000.000/0001-00" />
                            </div>
                            <div className="space-y-1.5">
                                <Label>ID Loja ML</Label>
                                <Input value={data.ml_store_id} onChange={e => setData('ml_store_id', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Segmento</Label>
                                <Input value={data.segment} onChange={e => setData('segment', e.target.value)} placeholder="Ex: Moda, Eletrônicos" />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Analista</Label>
                                <Select value={data.consultor_id} onValueChange={v => setData('consultor_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Selecionar..." /></SelectTrigger>
                                    <SelectContent>
                                        {consultores.map(u => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Estrategista</Label>
                                <Select value={data.estrategista_id} onValueChange={v => setData('estrategista_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Selecionar..." /></SelectTrigger>
                                    <SelectContent>
                                        {estrategistasOptions.map(u => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Observações</Label>
                                <Textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={2} />
                            </div>
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
