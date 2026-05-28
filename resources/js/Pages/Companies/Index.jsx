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
import { useForm, Link, router, useRemember } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Eye, Trash2, Building2, ShoppingCart, Copy, Check, Clock, CheckCircle2, Link2 } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import { cn } from '@/lib/utils';

/**
 * Badges dos contratos ativos de uma empresa.
 * Exibe até 2 contratos + indicador "+N" para o restante. Tooltip
 * (title) detalha valor + datas.
 */
function ServicoBadges({ contratos }) {
    if (!contratos || contratos.length === 0) {
        return <span className="text-white/30">—</span>;
    }

    const visible = contratos.slice(0, 2);
    const extra = contratos.length - 2;

    const tooltip = (ct) => {
        const nome = ct.servico?.nome ?? '—';
        const valor = formatCurrency(ct.valor_contratado);
        const inicio = ct.data_contratacao ? formatDate(ct.data_contratacao) : '—';
        const fim = ct.data_vencimento ? formatDate(ct.data_vencimento) : 'sem vencimento';
        return `${nome} — ${valor} — ${inicio} → ${fim}`;
    };

    return (
        <div className="flex flex-wrap items-center gap-1">
            {visible.map(ct => (
                <span
                    key={ct.id}
                    title={tooltip(ct)}
                    className="inline-flex items-center bg-white/10 border border-white/10 text-white/85 text-[10px] px-1.5 py-0.5 rounded-full"
                >
                    {ct.servico?.nome ?? '—'}
                </span>
            ))}
            {extra > 0 && (
                <span
                    title={contratos.slice(2).map(tooltip).join('\n')}
                    className="inline-flex items-center bg-white/10 border border-white/10 text-white/50 text-[10px] px-1.5 py-0.5 rounded-full"
                >
                    +{extra}
                </span>
            )}
        </div>
    );
}

// ─── Badge de status ML ──────────────────────────────────────────────────────
function MlStatusBadge({ status }) {
    if (status === 'active') {
        return (
            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/25 text-emerald-400">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                ML
            </span>
        );
    }
    if (status === 'expired' || status === 'revoked') {
        return (
            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-orange-500/15 border border-orange-500/25 text-orange-400">
                <span className="w-1.5 h-1.5 rounded-full bg-orange-400" />
                ML
            </span>
        );
    }
    return null;
}

export default function Companies({ companies, users, estrategistas = [], empresas_pendentes = [], ml_connected = [], ml_pending = [] }) {
    const [search, setSearch] = useRemember('', 'companies-index-search');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    // ── Link ML OAuth ──────────────────────────────────────────────────────────
    const [mlLinkOpen, setMlLinkOpen]     = useState(false);
    const [mlLinkUrl, setMlLinkUrl]       = useState('');
    const [mlLinkCompany, setMlLinkCompany] = useState(null);
    const [mlLinkLoading, setMlLinkLoading] = useState(false);
    const [mlLinkCopied, setMlLinkCopied] = useState(false);

    const gerarLinkMl = async (company) => {
        setMlLinkLoading(true);
        setMlLinkCompany(company);
        try {
            const res = await fetch(route('ml.oauth.initiate', company.id), {
                method:  'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept':       'application/json',
                    'Content-Type': 'application/json',
                },
            });
            const data = await res.json();
            setMlLinkUrl(data.url);
            setMlLinkOpen(true);
        } catch {
            alert('Erro ao gerar link. Tente novamente.');
        } finally {
            setMlLinkLoading(false);
        }
    };

    const copiarLinkMl = () => {
        navigator.clipboard.writeText(mlLinkUrl).then(() => {
            setMlLinkCopied(true);
            setTimeout(() => setMlLinkCopied(false), 2000);
        });
    };

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

                {/* Painel de status OAuth Mercado Livre */}
                {(ml_pending.length > 0 || ml_connected.length > 0) && (
                    <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-4">
                        <div className="flex items-center gap-2">
                            <Link2 size={15} className="text-[#ffe116]" />
                            <h2 className="text-white font-semibold text-[14px]">Mercado Livre OAuth</h2>
                        </div>

                        {ml_pending.length > 0 && (
                            <div>
                                <div className="flex items-center gap-2 mb-2">
                                    <Clock size={12} className="text-amber-400" />
                                    <span className="text-amber-400 text-[12px] font-semibold">Aguardando autorização</span>
                                    <span className="inline-flex items-center justify-center min-w-[18px] h-4 px-1 rounded-full bg-amber-400/10 border border-amber-400/20 text-amber-400 text-[10px] font-bold">
                                        {ml_pending.length}
                                    </span>
                                </div>
                                <div className="space-y-1.5">
                                    {ml_pending.map(p => {
                                        const expiresAt = new Date(p.expires_at);
                                        const now = new Date();
                                        const daysLeft = Math.ceil((expiresAt - now) / (1000 * 60 * 60 * 24));
                                        const expired = daysLeft <= 0;
                                        return (
                                            <div key={p.id} className="flex items-center gap-3 rounded-xl border border-white/[0.05] bg-white/[0.02] px-4 py-2">
                                                <span className="text-white text-[13px] font-medium flex-1">{p.name}</span>
                                                <span className={cn('text-[11px]', expired ? 'text-red-400' : 'text-white/40')}>
                                                    {expired ? 'Expirado' : `expira em ${daysLeft}d`}
                                                </span>
                                                <Link href={route('companies.show', p.id)} className="text-white/40 hover:text-white transition-colors">
                                                    <Eye size={13} />
                                                </Link>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        {ml_connected.length > 0 && (
                            <div>
                                <div className="flex items-center gap-2 mb-2">
                                    <CheckCircle2 size={12} className="text-emerald-400" />
                                    <span className="text-emerald-400 text-[12px] font-semibold">Conectadas</span>
                                    <span className="inline-flex items-center justify-center min-w-[18px] h-4 px-1 rounded-full bg-emerald-400/10 border border-emerald-400/20 text-emerald-400 text-[10px] font-bold">
                                        {ml_connected.length}
                                    </span>
                                </div>
                                <div className="space-y-1.5">
                                    {ml_connected.map(c => (
                                        <div key={c.id} className="flex items-center gap-3 rounded-xl border border-white/[0.05] bg-white/[0.02] px-4 py-2">
                                            <span className="text-white text-[13px] font-medium flex-1">{c.name}</span>
                                            <span className="text-white/30 text-[11px]">
                                                {c.connected_at ? `conectado em ${new Date(c.connected_at).toLocaleDateString('pt-BR')}` : '—'}
                                            </span>
                                            <Link href={route('companies.show', c.id)} className="text-white/40 hover:text-white transition-colors">
                                                <Eye size={13} />
                                            </Link>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}

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
                                    <span className="text-ecf-yellow/60 text-[11px] font-medium">
                                        {(Array.isArray(p.servicos_contratados) ? p.servicos_contratados : [])
                                            .map(s => typeof s === 'string' ? s : s.servico_nome)
                                            .filter(Boolean)
                                            .join(' + ') || '—'}
                                    </span>
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
                                    {/* Coluna Serviço (substitui TACOS + Faturamento 30d — Módulo Serviços / Frente A) */}
                                    <TableHead title="Contratos de serviço ativos da empresa">Serviço</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.map(c => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                {c.name}
                                                <MlStatusBadge status={c.ml_token_status} />
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">{c.segment || '-'}</TableCell>
                                        <TableCell className="text-sm">{c.consultor?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                        <TableCell className="text-sm">{c.estrategista?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                        <TableCell>
                                            <ServicoBadges contratos={c.contratos_servico || []} />
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
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    title="Gerar link de autorização ML"
                                                    className={cn(
                                                        c.ml_token_status === 'active'
                                                            ? 'text-emerald-400 hover:text-emerald-300 hover:bg-emerald-500/10'
                                                            : 'text-white/40 hover:text-white hover:bg-white/[0.05]'
                                                    )}
                                                    onClick={() => gerarLinkMl(c)}
                                                    disabled={mlLinkLoading}
                                                >
                                                    <ShoppingCart className="h-4 w-4" />
                                                </Button>
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
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
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

            {/* Modal link ML OAuth */}
            <Dialog open={mlLinkOpen} onOpenChange={setMlLinkOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShoppingCart size={16} className="text-ecf-yellow/70" />
                            Link de autorização — Mercado Livre
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        {mlLinkCompany && (
                            <p className="text-white/55 text-[13px]">
                                Envie este link ao cliente da empresa{' '}
                                <strong className="text-white/85">{mlLinkCompany.name}</strong>.
                                Após a autorização, a conta ML será vinculada automaticamente.
                                O link expira em <strong className="text-ecf-yellow">7 dias</strong>.
                            </p>
                        )}
                        <div className="flex gap-2">
                            <input
                                readOnly
                                value={mlLinkUrl}
                                onClick={e => e.target.select()}
                                className="flex-1 rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[12px] text-white/70 font-mono focus:outline-none cursor-text"
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="shrink-0 gap-1.5 text-[12px]"
                                onClick={copiarLinkMl}
                            >
                                {mlLinkCopied
                                    ? <><Check size={13} className="text-emerald-400" /> Copiado!</>
                                    : <><Copy size={13} /> Copiar</>
                                }
                            </Button>
                        </div>
                        <p className="text-white/30 text-[11px]">
                            Gere um novo link a qualquer momento — cada link invalida o anterior.
                        </p>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setMlLinkOpen(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

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
