import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, Power, PowerOff, Briefcase } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';

// ─── Constantes locais ──────────────────────────────────────────────────────
const TIPO_COBRANCA = {
    mensal: { label: 'Mensal', className: 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/25' },
    unica:  { label: 'Única',  className: 'bg-white/10 text-white/80 border-white/15' },
};

function TipoBadge({ tipo }) {
    const cfg = TIPO_COBRANCA[tipo] || { label: tipo, className: 'bg-white/10 text-white/60 border-white/10' };
    return (
        <span className={cn(
            'inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wide',
            cfg.className,
        )}>
            {cfg.label}
        </span>
    );
}

export default function ServicosIndex({ servicos }) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const filtered = (servicos || []).filter(s =>
        s.nome.toLowerCase().includes(search.toLowerCase())
    );

    const { data, setData, post, put, processing, reset, errors } = useForm({
        nome: '',
        valor_padrao: '',
        tipo_cobranca: 'mensal',
        ativo: true,
    });

    const openCreate = () => {
        reset();
        setData({ nome: '', valor_padrao: '', tipo_cobranca: 'mensal', ativo: true });
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (s) => {
        setEditing(s);
        setData({
            nome: s.nome || '',
            valor_padrao: s.valor_padrao ?? '',
            tipo_cobranca: s.tipo_cobranca || 'mensal',
            ativo: !!s.ativo,
        });
        setOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { setOpen(false); reset(); };
        if (editing) {
            put(route('servicos.update', editing.id), { onSuccess, preserveScroll: true });
        } else {
            post(route('servicos.store'), { onSuccess, preserveScroll: true });
        }
    };

    // Toggle inline ativo (PUT preservando todos os outros campos)
    const toggleAtivo = (s) => {
        router.put(route('servicos.update', s.id), {
            nome: s.nome,
            valor_padrao: s.valor_padrao,
            tipo_cobranca: s.tipo_cobranca,
            ativo: !s.ativo,
        }, { preserveScroll: true });
    };

    const destroy = (s) => {
        // Server decide: delete físico se sem contratos ativos, soft-deactivate caso contrário
        if (confirm(`Excluir o serviço "${s.nome}"?\n\nSe houver contratos ativos vinculados, o serviço será apenas desativado.`)) {
            router.delete(route('servicos.destroy', s.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout title="Serviços">
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between gap-3">
                    <Input
                        placeholder="Buscar serviço..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <Button onClick={openCreate}>
                        <Plus className="h-4 w-4 mr-1" /> Novo Serviço
                    </Button>
                </div>

                {/* Tabela do catálogo */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>Valor padrão</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Contratos ativos</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.map(s => (
                                    <TableRow key={s.id} className={cn(!s.ativo && 'opacity-50')}>
                                        <TableCell className="font-medium text-white">{s.nome}</TableCell>
                                        <TableCell className="text-white/80 tabular-nums">
                                            {formatCurrency(s.valor_padrao)}
                                        </TableCell>
                                        <TableCell>
                                            <TipoBadge tipo={s.tipo_cobranca} />
                                        </TableCell>
                                        <TableCell className="text-white/60 text-sm">
                                            {s.contratos_ativos_count ?? 0} {(s.contratos_ativos_count ?? 0) === 1 ? 'ativo' : 'ativos'}
                                        </TableCell>
                                        <TableCell>
                                            <button
                                                type="button"
                                                onClick={() => toggleAtivo(s)}
                                                title={s.ativo ? 'Desativar' : 'Ativar'}
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 px-2 py-1 rounded-md border text-[11px] font-medium transition-colors',
                                                    s.ativo
                                                        ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/20'
                                                        : 'bg-white/5 border-white/10 text-white/40 hover:bg-white/10',
                                                )}
                                            >
                                                {s.ativo ? <Power size={11} /> : <PowerOff size={11} />}
                                                {s.ativo ? 'Ativo' : 'Inativo'}
                                            </button>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(s)} title="Editar">
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="text-red-400 hover:text-red-300 hover:bg-red-500/10"
                                                    onClick={() => destroy(s)}
                                                    title="Excluir"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {filtered.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-12">
                                            <Briefcase className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            {search
                                                ? 'Nenhum serviço encontrado para esta busca'
                                                : 'Nenhum serviço cadastrado ainda. Clique em "Novo Serviço" para começar.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Modal Novo/Editar */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar serviço' : 'Novo serviço'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Nome *</Label>
                            <Input
                                value={data.nome}
                                onChange={e => setData('nome', e.target.value)}
                                required
                                placeholder="Ex: Consultoria mensal"
                            />
                            {errors.nome && <p className="text-destructive text-xs">{errors.nome}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Valor padrão (R$) *</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.valor_padrao}
                                onChange={e => setData('valor_padrao', e.target.value)}
                                required
                                placeholder="0,00"
                            />
                            {errors.valor_padrao && <p className="text-destructive text-xs">{errors.valor_padrao}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Tipo de cobrança *</Label>
                            <div className="grid grid-cols-2 gap-2">
                                {Object.entries(TIPO_COBRANCA).map(([key, cfg]) => (
                                    <button
                                        type="button"
                                        key={key}
                                        onClick={() => setData('tipo_cobranca', key)}
                                        className={cn(
                                            'rounded-lg border px-3 py-2 text-[13px] font-medium transition-all',
                                            data.tipo_cobranca === key
                                                ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                                : 'border-white/10 bg-white/[0.02] text-white/60 hover:bg-white/[0.05]',
                                        )}
                                    >
                                        {cfg.label}
                                    </button>
                                ))}
                            </div>
                            {errors.tipo_cobranca && <p className="text-destructive text-xs">{errors.tipo_cobranca}</p>}
                        </div>

                        <div className="flex items-center gap-2 pt-1">
                            <input
                                type="checkbox"
                                id="servico-ativo"
                                checked={!!data.ativo}
                                onChange={e => setData('ativo', e.target.checked)}
                                className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                            />
                            <Label htmlFor="servico-ativo" className="cursor-pointer text-sm text-white/80">
                                Serviço ativo (disponível para novos contratos)
                            </Label>
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
        </AppLayout>
    );
}
