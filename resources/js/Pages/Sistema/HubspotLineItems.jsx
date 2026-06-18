import { useState, useMemo } from 'react';
import { useForm, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Plus, Pencil, Trash2, Link2, Search } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Phase 37 Plan 37-07 (REQ-37-02) ────────────────────────────────────────
// UI admin do CRUD de mappings HubSpot line_item → Servico.
// Consumido pelo HubspotWebhookController via HubspotLineItemMapping::paraNome
// (case-insensitive). Permite ao admin cadastrar nomes novos vindos do HubSpot
// sem precisar de deploy — UX rapida com tabela + modal de edicao inline.

// Mapa setor → label pt-BR. Espelha App\Models\Servico::setoresLabels() no PHP
// (sincronia manual; ver convencao em CLAUDE.md "Domain constants").
const SETOR_LABELS = {
    performance: 'Performance',
    publicacao:  'Publicação',
    outros:      'Outros',
};

// Cores ECF tokens por setor — usadas no Badge da coluna Setor da tabela.
// Padrao dark theme + opacity slash, alinhado com Servicos/Index.jsx.
const SETOR_CLS = {
    performance: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    publicacao:  'bg-blue-500/15 text-blue-300 border-blue-500/30',
    outros:      'bg-white/10 text-white/60 border-white/15',
};

export default function HubspotLineItems({ mappings = [], servicos_disponiveis = [] }) {
    // Modal state:
    //   open=false        — fechado
    //   open='create'     — modal de criacao novo
    //   open=<mapping>    — modal de edicao do mapping selecionado
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);
    const [search, setSearch] = useState('');

    const { data, setData, post, put, processing, reset, errors, clearErrors } = useForm({
        line_item_name: '',
        servico_id:     '',
        ativo:          true,
        observacoes:    '',
    });

    // Lista filtrada por busca (line_item_name ou servico_nome) — client-side
    // pq universo eh pequeno (< 50 mappings tipicos).
    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return mappings;
        return mappings.filter(m =>
            (m.line_item_name || '').toLowerCase().includes(q) ||
            (m.servico_nome || '').toLowerCase().includes(q),
        );
    }, [mappings, search]);

    const openCreate = () => {
        reset();
        clearErrors();
        setData({
            line_item_name: '',
            servico_id:     servicos_disponiveis[0]?.id || '',
            ativo:          true,
            observacoes:    '',
        });
        setEditing(null);
        setOpen('create');
    };

    const openEdit = (m) => {
        clearErrors();
        setData({
            line_item_name: m.line_item_name || '',
            servico_id:     m.servico_id || '',
            ativo:          !!m.ativo,
            observacoes:    m.observacoes || '',
        });
        setEditing(m);
        setOpen('edit');
    };

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => {
            setOpen(false);
            setEditing(null);
            reset();
        };
        if (editing) {
            put(route('sistema.hubspot-line-items.update', editing.id), { onSuccess, preserveScroll: true });
        } else {
            post(route('sistema.hubspot-line-items.store'), { onSuccess, preserveScroll: true });
        }
    };

    const destroy = (m) => {
        router.delete(route('sistema.hubspot-line-items.destroy', m.id), {
            preserveScroll: true,
            onSuccess: () => setConfirmDelete(null),
        });
    };

    return (
        <AppLayout title="Mapeamentos HubSpot">
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-white">Mapeamentos HubSpot Line Items</h1>
                        <p className="text-sm text-white/60 mt-1">
                            Mapeie nomes de line items vindos do HubSpot (ex: <code className="text-ecf-yellow">MAP PREMIUM</code>, <code className="text-ecf-yellow">Polo SP</code>) para serviços do catálogo. O webhook usa este mapeamento para criar contratos automaticamente quando o deal vira "Fechado Ganho". Match é case-insensitive.
                        </p>
                    </div>
                    <Button onClick={openCreate} className="shrink-0 bg-ecf-yellow text-black hover:bg-ecf-yellow/90">
                        <Plus className="h-4 w-4 mr-1" /> Novo mapeamento
                    </Button>
                </div>

                {/* Busca */}
                <div className="relative max-w-sm">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-white/30" />
                    <Input
                        placeholder="Buscar por nome HubSpot ou serviço..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="pl-9"
                    />
                </div>

                {/* Tabela */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome no HubSpot</TableHead>
                                    <TableHead>Serviço</TableHead>
                                    <TableHead>Setor</TableHead>
                                    <TableHead>Ativo</TableHead>
                                    <TableHead>Observações</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.map(m => (
                                    <TableRow key={m.id} className={cn(!m.ativo && 'opacity-50')}>
                                        <TableCell className="font-mono text-[13px] text-white">{m.line_item_name}</TableCell>
                                        <TableCell className="text-white/80">{m.servico_nome || '—'}</TableCell>
                                        <TableCell>
                                            {m.servico_setor ? (
                                                <Badge className={cn('border', SETOR_CLS[m.servico_setor] || SETOR_CLS.outros)}>
                                                    {SETOR_LABELS[m.servico_setor] || m.servico_setor}
                                                </Badge>
                                            ) : (
                                                <span className="text-white/30">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <span className={cn(
                                                'inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wide',
                                                m.ativo
                                                    ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300'
                                                    : 'bg-white/5 border-white/10 text-white/40',
                                            )}>
                                                {m.ativo ? 'Sim' : 'Não'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-[12px] text-white/60 max-w-[280px] truncate" title={m.observacoes || ''}>
                                            {m.observacoes || '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(m)} title="Editar">
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="text-red-400 hover:text-red-300 hover:bg-red-500/10"
                                                    onClick={() => setConfirmDelete(m)}
                                                    title="Remover"
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
                                            <Link2 className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            {search
                                                ? 'Nenhum mapeamento encontrado para esta busca'
                                                : 'Nenhum mapeamento cadastrado ainda. Clique em "Novo mapeamento" para começar.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Modal Novo/Editar */}
            <Dialog open={!!open} onOpenChange={(v) => { if (!v) { setOpen(false); setEditing(null); } }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Editar mapeamento' : 'Novo mapeamento'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Nome no HubSpot *</Label>
                            <Input
                                value={data.line_item_name}
                                onChange={e => setData('line_item_name', e.target.value)}
                                required
                                placeholder="Ex: MAP PREMIUM"
                                autoFocus
                            />
                            <p className="text-[11px] text-white/40">
                                Exatamente como aparece no <code>line_item.name</code> da API HubSpot. Match é case-insensitive.
                            </p>
                            {errors.line_item_name && <p className="text-destructive text-xs">{errors.line_item_name}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Serviço do catálogo *</Label>
                            <select
                                value={data.servico_id}
                                onChange={e => setData('servico_id', e.target.value ? Number(e.target.value) : '')}
                                required
                                className="flex h-10 w-full rounded-md border border-white/10 bg-white/[0.02] px-3 py-2 text-sm text-white ring-offset-background placeholder:text-white/30 focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40 focus:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="" disabled>Selecione um serviço…</option>
                                {servicos_disponiveis.map(s => (
                                    <option key={s.id} value={s.id}>
                                        {s.nome} {s.setor ? `· ${SETOR_LABELS[s.setor] || s.setor}` : ''}
                                    </option>
                                ))}
                            </select>
                            {errors.servico_id && <p className="text-destructive text-xs">{errors.servico_id}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Observações</Label>
                            <Textarea
                                value={data.observacoes}
                                onChange={e => setData('observacoes', e.target.value)}
                                rows={3}
                                placeholder="Ex: criado para a campanha de novembro; revisar em 2027"
                            />
                            {errors.observacoes && <p className="text-destructive text-xs">{errors.observacoes}</p>}
                        </div>

                        <div className="flex items-center gap-2 pt-1">
                            <input
                                type="checkbox"
                                id="mapping-ativo"
                                checked={!!data.ativo}
                                onChange={e => setData('ativo', e.target.checked)}
                                className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                            />
                            <Label htmlFor="mapping-ativo" className="cursor-pointer text-sm text-white/80">
                                Mapeamento ativo (webhook usa para criar contratos)
                            </Label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => { setOpen(false); setEditing(null); }}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing} className="bg-ecf-yellow text-black hover:bg-ecf-yellow/90">
                                {processing ? 'Salvando...' : editing ? 'Atualizar' : 'Criar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal de confirmacao de delete */}
            <Dialog open={!!confirmDelete} onOpenChange={(v) => { if (!v) setConfirmDelete(null); }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Remover mapeamento?</DialogTitle>
                    </DialogHeader>
                    {confirmDelete && (
                        <p className="text-sm text-white/70">
                            O mapeamento <code className="text-ecf-yellow">{confirmDelete.line_item_name}</code> será removido. Line items com esse nome cairão como "não reconhecido" no webhook até que outro mapeamento seja cadastrado.
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirmDelete(null)}>
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            className="bg-red-600 text-white hover:bg-red-600/90"
                            onClick={() => confirmDelete && destroy(confirmDelete)}
                        >
                            Remover
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
