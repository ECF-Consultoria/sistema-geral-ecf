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
import { Plus, Pencil, Eye, Trash2, Building2, ShoppingCart, Copy, Check, RotateCcw, Tag, X } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import { cn } from '@/lib/utils';

/**
 * Badges dos contratos ativos de uma empresa.
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
                <span key={ct.id} title={tooltip(ct)} className="inline-flex items-center bg-white/10 border border-white/10 text-white/85 text-[10px] px-1.5 py-0.5 rounded-full">
                    {ct.servico?.nome ?? '—'}
                </span>
            ))}
            {extra > 0 && (
                <span title={contratos.slice(2).map(tooltip).join('\n')} className="inline-flex items-center bg-white/10 border border-white/10 text-white/50 text-[10px] px-1.5 py-0.5 rounded-full">
                    +{extra}
                </span>
            )}
        </div>
    );
}

// ─── Badge "Cust ID Inválido" (Phase 18 W5-T4) ──────────────────────────────
function CustIdInvalidoBadge({ status }) {
    if (status !== 'invalido') return null;
    return (
        <span title="Cust ID corrompido — Adman não reconhece. Conectar OAuth ML ou ajustar cadastro." className="inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/20 tracking-wide">
            Cust ID Inválido
        </span>
    );
}

// ─── Badge de status ML ──────────────────────────────────────────────────────
function MlStatusBadge({ status }) {
    if (status === 'active') {
        return (
            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/25 text-emerald-400">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" /> ML
            </span>
        );
    }
    if (status === 'expired' || status === 'revoked') {
        return (
            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-orange-500/15 border border-orange-500/25 text-orange-400">
                <span className="w-1.5 h-1.5 rounded-full bg-orange-400" /> ML
            </span>
        );
    }
    return null;
}

// ─── Pendências (4 tipos calculados no backend) ─────────────────────────────
const PENDENCIAS = {
    sem_responsavel:       { label: 'Sem responsável',       cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
    sem_cust_id:           { label: 'Sem cust id',           cls: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    sem_email_colaborador: { label: 'Sem email colaborador', cls: 'bg-amber-500/10 text-amber-300 border-amber-500/20' },
    sem_grant_ativo:       { label: 'Sem grant ativo',       cls: 'bg-sky-500/10 text-sky-400 border-sky-500/20' },
};

function PendenciaBadges({ pendencias }) {
    if (!pendencias?.length) return null;
    return (
        <div className="flex flex-wrap gap-1">
            {pendencias.map(p => {
                const cfg = PENDENCIAS[p];
                if (!cfg) return null;
                return <span key={p} className={cn('inline-flex text-[10px] font-semibold px-1.5 py-0.5 rounded border', cfg.cls)}>{cfg.label}</span>;
            })}
        </div>
    );
}

// ─── Badge do Grupo (cor custom do grupo) ───────────────────────────────────
function GrupoBadge({ grupo }) {
    if (!grupo) return null;
    return (
        <span
            title={`Grupo: ${grupo.name}`}
            className="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded-full border"
            style={{ borderColor: `${grupo.color}55`, backgroundColor: `${grupo.color}18`, color: grupo.color }}
        >
            <Tag size={9} />{grupo.name}
        </span>
    );
}

export default function Companies({ companies, users, estrategistas = [], grupos = [], servico_counts = [], filters = {} }) {
    // Lê a aba inicial do query param ?tab (deep-link vindo do menu lateral, ex: Empresas › Pendências).
    // Lazy initializer roda apenas uma vez no mount; valores inválidos/ausentes caem em 'empresas'.
    const [tab, setTab] = useState(() => {
        const t = new URLSearchParams(window.location.search).get('tab');
        return ['empresas', 'pendencias', 'grupos'].includes(t) ? t : 'empresas';
    });
    const [search, setSearch] = useRemember('', 'companies-index-search');
    const [servicoFilter, setServicoFilter] = useState(''); // servico id (string) ou ''
    const custIdStatusFilter = filters.cust_id_status || '';

    const aplicarCustIdFilter = (valor) => {
        router.get(route('companies.index'), valor ? { cust_id_status: valor } : {}, { preserveState: true, preserveScroll: true });
    };

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    // ── Link ML OAuth ──────────────────────────────────────────────────────────
    const [mlLinkOpen, setMlLinkOpen]       = useState(false);
    const [mlLinkUrl, setMlLinkUrl]         = useState('');
    const [mlLinkCompany, setMlLinkCompany] = useState(null);
    const [mlLinkLoading, setMlLinkLoading] = useState(false);
    const [mlLinkCopied, setMlLinkCopied]   = useState(false);

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
    const estrategistasOptions = estrategistas.length > 0 ? estrategistas : users.filter(u => u.role === 'mentor');

    // ── Filtro da aba Empresas (busca + serviço) ─────────────────────────────
    const filtered = companies.filter(c =>
        (c.name.toLowerCase().includes(search.toLowerCase()) || (c.segment || '').toLowerCase().includes(search.toLowerCase()))
        && (!servicoFilter || (c.contratos_servico || []).some(ct => String(ct.servico?.id) === servicoFilter))
    );

    const totalAtivas = companies.filter(c => c.active).length;

    // ── Pendências (empresas ativas com ≥1 pendência) ────────────────────────
    const pendentes = companies.filter(c => c.active && (c.pendencias || []).length > 0);
    const pendCounts = { sem_responsavel: 0, sem_cust_id: 0, sem_email_colaborador: 0, sem_grant_ativo: 0 };
    companies.forEach(c => {
        if (!c.active) return;
        (c.pendencias || []).forEach(p => { if (pendCounts[p] !== undefined) pendCounts[p]++; });
    });

    // ── Filtro por tag de pendência + seleção/ações em massa ─────────────────
    const [pendenciaFilter, setPendenciaFilter] = useState('');
    const [selectedIds, setSelectedIds] = useState(() => new Set());

    const pendentesView = pendenciaFilter
        ? pendentes.filter(c => (c.pendencias || []).includes(pendenciaFilter))
        : pendentes;

    const togglePendenciaFilter = (key) => {
        setPendenciaFilter(prev => (prev === key ? '' : key));
        setSelectedIds(new Set());
    };
    const toggleSelect = (id) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };
    const allViewSelected = pendentesView.length > 0 && pendentesView.every(c => selectedIds.has(c.id));
    const toggleSelectAll = () => setSelectedIds(allViewSelected ? new Set() : new Set(pendentesView.map(c => c.id)));
    const clearSelection = () => setSelectedIds(new Set());

    const bulkDelete = () => {
        const ids = [...selectedIds];
        if (!ids.length) return;
        if (confirm(`Excluir DEFINITIVAMENTE ${ids.length} empresa(s) selecionada(s)? Esta ação não pode ser desfeita.`)) {
            router.post(route('companies.bulk-destroy'), { ids }, { preserveScroll: true, onSuccess: clearSelection });
        }
    };
    const bulkAssign = (role, userId) => {
        const ids = [...selectedIds];
        if (!ids.length || !userId) return;
        router.post(route('companies.bulk-assign'), { ids, role, user_id: userId }, { preserveScroll: true, onSuccess: clearSelection });
    };

    // ── Form empresa (somente edição — cadastro é via /comercial/empresas) ───
    const { data, setData, put, processing, errors } = useForm({
        name: '', cnpj: '', adman_store_id: '', ml_store_id: '', segment: '', notes: '',
        consultor_id: '', estrategista_id: '', email_cliente: '', telefone: '', company_group_id: '',
    });

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
            email_cliente: c.email_cliente || '',
            telefone: c.telefone || '',
            company_group_id: c.company_group_id ? String(c.company_group_id) : '',
        });
        setOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('companies.update', editing.id), { onSuccess: () => setOpen(false) });
    };

    const destroy = (c) => {
        if (confirm(`Excluir a empresa "${c.name}"? Esta ação não pode ser desfeita.`)) {
            router.delete(route('companies.destroy', c.id));
        }
    };

    const ativar = (c) => {
        if (confirm(`Reativar a empresa "${c.name}"?`)) {
            router.post(route('companies.ativar', c.id), {}, { preserveScroll: true });
        }
    };

    // ── Grupos ───────────────────────────────────────────────────────────────
    const grupoForm = useForm({ name: '', color: '#ffe600' });
    const [grupoModalOpen, setGrupoModalOpen] = useState(false);
    const [editingGrupo, setEditingGrupo] = useState(null);

    const openCreateGrupo = () => {
        setEditingGrupo(null);
        grupoForm.setData({ name: '', color: '#ffe600' });
        setGrupoModalOpen(true);
    };
    const openEditGrupo = (g) => {
        setEditingGrupo(g);
        grupoForm.setData({ name: g.name, color: g.color || '#ffe600' });
        setGrupoModalOpen(true);
    };
    const submitGrupo = (e) => {
        e.preventDefault();
        const onSuccess = () => setGrupoModalOpen(false);
        if (editingGrupo) {
            grupoForm.put(route('company-groups.update', editingGrupo.id), { onSuccess });
        } else {
            grupoForm.post(route('company-groups.store'), { onSuccess });
        }
    };
    const deleteGrupo = (g) => {
        if (confirm(`Excluir o grupo "${g.name}"? As empresas serão desvinculadas (não excluídas).`)) {
            router.delete(route('company-groups.destroy', g.id), { preserveScroll: true });
        }
    };
    const setGroup = (companyId, groupId) => {
        router.put(route('companies.set-group', companyId), { company_group_id: groupId }, { preserveScroll: true });
    };

    const TABS = [
        { key: 'empresas',   label: `Empresas (${totalAtivas})` },
        { key: 'pendencias', label: `Pendências (${pendentes.length})` },
        { key: 'grupos',     label: `Grupos (${grupos.length})` },
    ];

    return (
        <AppLayout title="Empresas">
            <div className="space-y-4">

                {/* ─── Abas ─────────────────────────────────────────────────── */}
                <div className="flex gap-1 border-b border-white/[0.08]">
                    {TABS.map(t => (
                        <button
                            key={t.key}
                            onClick={() => setTab(t.key)}
                            className={cn(
                                'px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px',
                                tab === t.key ? 'border-ecf-yellow text-white' : 'border-transparent text-white/50 hover:text-white/80'
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* ══════════════ ABA EMPRESAS ══════════════ */}
                {tab === 'empresas' && (
                    <>
                        <div className="flex items-center gap-2 flex-wrap">
                            <Input placeholder="Buscar empresa..." value={search} onChange={e => setSearch(e.target.value)} className="max-w-sm" />
                            <select
                                value={custIdStatusFilter}
                                onChange={e => aplicarCustIdFilter(e.target.value)}
                                className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                                title="Filtrar por status do cust_id"
                            >
                                <option value="">Todas as empresas</option>
                                <option value="invalido">Apenas Cust ID Inválido</option>
                            </select>
                        </div>

                        {/* Chips de filtro por serviço com contagem total */}
                        <div className="flex flex-wrap gap-2">
                            <button
                                onClick={() => setServicoFilter('')}
                                className={cn('inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] border transition-colors',
                                    servicoFilter === '' ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow' : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90')}
                            >
                                Todos <span className="opacity-60">{totalAtivas}</span>
                            </button>
                            {servico_counts.map(s => (
                                <button
                                    key={s.id}
                                    onClick={() => setServicoFilter(servicoFilter === String(s.id) ? '' : String(s.id))}
                                    className={cn('inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] border transition-colors',
                                        servicoFilter === String(s.id) ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow' : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90')}
                                >
                                    {s.nome} <span className="inline-flex items-center justify-center min-w-[18px] h-4 px-1 rounded-full bg-white/10 text-[10px] font-bold">{s.total}</span>
                                </button>
                            ))}
                        </div>

                        <Card>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Empresa</TableHead>
                                            <TableHead>Grupo</TableHead>
                                            <TableHead>Analista</TableHead>
                                            <TableHead>Estrategista</TableHead>
                                            <TableHead title="Contratos de serviço ativos da empresa">Serviço</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filtered.map(c => (
                                            <TableRow key={c.id}>
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        {c.name}
                                                        <MlStatusBadge status={c.ml_token_status} />
                                                        <CustIdInvalidoBadge status={c.cust_id_status} />
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {c.grupo ? <GrupoBadge grupo={c.grupo} /> : <span className="text-white/25 text-xs">—</span>}
                                                </TableCell>
                                                <TableCell className="text-sm">{c.consultor?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                                <TableCell className="text-sm">{c.estrategista?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                                <TableCell><ServicoBadges contratos={c.contratos_servico || []} /></TableCell>
                                                <TableCell>
                                                    <Badge variant={c.active ? 'success' : 'destructive'}>{c.active ? 'Ativa' : 'Inativa'}</Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Link href={route('companies.show', c.id)}>
                                                            <Button size="icon" variant="ghost"><Eye className="h-4 w-4" /></Button>
                                                        </Link>
                                                        <Button
                                                            size="icon" variant="ghost" title="Gerar link de autorização ML"
                                                            className={cn(c.ml_token_status === 'active' ? 'text-emerald-400 hover:text-emerald-300 hover:bg-emerald-500/10' : 'text-white/40 hover:text-white hover:bg-white/[0.05]')}
                                                            onClick={() => gerarLinkMl(c)} disabled={mlLinkLoading}
                                                        >
                                                            <ShoppingCart className="h-4 w-4" />
                                                        </Button>
                                                        <Button size="icon" variant="ghost" onClick={() => openEdit(c)}><Pencil className="h-4 w-4" /></Button>
                                                        {c.active ? (
                                                            <Button size="icon" variant="ghost" title="Excluir empresa" className="text-red-400 hover:text-red-300 hover:bg-red-500/10" onClick={() => destroy(c)}>
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        ) : (
                                                            <Button size="icon" variant="ghost" title="Reativar empresa" className="text-emerald-400 hover:text-emerald-300 hover:bg-emerald-500/10" onClick={() => ativar(c)}>
                                                                <RotateCcw className="h-4 w-4" />
                                                            </Button>
                                                        )}
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
                    </>
                )}

                {/* ══════════════ ABA PENDÊNCIAS ══════════════ */}
                {tab === 'pendencias' && (
                    <>
                        {/* Cards clicáveis — filtram a lista por tipo de pendência */}
                        <div className="flex flex-wrap items-center gap-3">
                            {Object.entries(PENDENCIAS).map(([key, cfg]) => (
                                <button
                                    key={key}
                                    onClick={() => togglePendenciaFilter(key)}
                                    className={cn('rounded-xl border px-4 py-3 flex items-center gap-3 transition-all', cfg.cls,
                                        pendenciaFilter === key ? 'ring-2 ring-white/40' : 'opacity-90 hover:opacity-100')}
                                    title={`Mostrar só empresas com: ${cfg.label}`}
                                >
                                    <span className="text-2xl font-bold tabular-nums">{pendCounts[key]}</span>
                                    <span className="text-[12px] font-medium leading-tight text-left">{cfg.label}</span>
                                </button>
                            ))}
                            {pendenciaFilter && (
                                <button onClick={() => togglePendenciaFilter(pendenciaFilter)} className="text-[12px] text-white/50 hover:text-white underline">
                                    limpar filtro
                                </button>
                            )}
                        </div>

                        {/* Barra de ações em massa (aparece com seleção) */}
                        {selectedIds.size > 0 && (
                            <div className="flex items-center gap-3 flex-wrap rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.05] px-4 py-2.5">
                                <span className="text-[13px] text-white/85 font-medium">{selectedIds.size} selecionada(s)</span>
                                <div className="h-4 w-px bg-white/10" />
                                <select
                                    value=""
                                    onChange={e => { if (e.target.value) bulkAssign('consultor', Number(e.target.value)); e.target.value = ''; }}
                                    className="h-8 px-2 rounded-lg border border-white/[0.1] bg-white/[0.05] text-[12px] text-white/80 cursor-pointer focus:outline-none focus:border-ecf-yellow/40"
                                >
                                    <option value="">Atribuir Analista…</option>
                                    {consultores.map(u => <option key={u.id} value={u.id} className="bg-[#0f1116]">{u.name}</option>)}
                                </select>
                                <select
                                    value=""
                                    onChange={e => { if (e.target.value) bulkAssign('estrategista', Number(e.target.value)); e.target.value = ''; }}
                                    className="h-8 px-2 rounded-lg border border-white/[0.1] bg-white/[0.05] text-[12px] text-white/80 cursor-pointer focus:outline-none focus:border-ecf-yellow/40"
                                >
                                    <option value="">Atribuir Estrategista…</option>
                                    {estrategistasOptions.map(u => <option key={u.id} value={u.id} className="bg-[#0f1116]">{u.name}</option>)}
                                </select>
                                <Button size="sm" variant="outline" className="gap-1.5 text-red-400 border-red-500/30 hover:bg-red-500/10 hover:text-red-300" onClick={bulkDelete}>
                                    <Trash2 className="h-3.5 w-3.5" /> Excluir selecionadas
                                </Button>
                                <button onClick={clearSelection} className="text-[12px] text-white/40 hover:text-white ml-auto">limpar seleção</button>
                            </div>
                        )}

                        <Card>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-10">
                                                <input type="checkbox" checked={allViewSelected} onChange={toggleSelectAll} className="accent-ecf-yellow w-4 h-4 cursor-pointer align-middle" title="Selecionar todas" />
                                            </TableHead>
                                            <TableHead>Empresa</TableHead>
                                            <TableHead>Pendências</TableHead>
                                            <TableHead>Responsáveis</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {pendentesView.map(c => (
                                            <TableRow key={c.id} className={cn(selectedIds.has(c.id) && 'bg-ecf-yellow/[0.04]')}>
                                                <TableCell>
                                                    <input type="checkbox" checked={selectedIds.has(c.id)} onChange={() => toggleSelect(c.id)} className="accent-ecf-yellow w-4 h-4 cursor-pointer align-middle" />
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        {c.name}
                                                        {c.grupo && <GrupoBadge grupo={c.grupo} />}
                                                    </div>
                                                </TableCell>
                                                <TableCell><PendenciaBadges pendencias={c.pendencias} /></TableCell>
                                                <TableCell className="text-xs text-white/60">
                                                    {c.estrategista?.name || c.consultor?.name
                                                        ? [c.estrategista?.name, c.consultor?.name].filter(Boolean).join(' · ')
                                                        : <span className="text-white/30">ninguém</span>}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="sm" variant="outline" className="gap-1.5" onClick={() => openEdit(c)}>
                                                            <Pencil className="h-3.5 w-3.5" /> Resolver
                                                        </Button>
                                                        <Button size="icon" variant="ghost" title="Excluir empresa" className="text-red-400 hover:text-red-300 hover:bg-red-500/10" onClick={() => destroy(c)}>
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {pendentesView.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center text-muted-foreground py-10">
                                                    <Check className="h-8 w-8 mx-auto mb-2 text-emerald-400/60" />
                                                    {pendenciaFilter ? 'Nenhuma empresa com essa pendência.' : 'Nenhuma empresa com pendências. Tudo em dia!'}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </>
                )}

                {/* ══════════════ ABA GRUPOS ══════════════ */}
                {tab === 'grupos' && (
                    <>
                        <div className="flex items-center justify-between">
                            <p className="text-white/50 text-[13px]">Agrupe empresas em carteiras nomeadas para visão e gestão conjunta.</p>
                            <Button onClick={openCreateGrupo}><Plus className="h-4 w-4 mr-1" /> Novo Grupo</Button>
                        </div>

                        {grupos.length === 0 && (
                            <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-10 text-center text-white/40">
                                <Tag className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                Nenhum grupo criado ainda. Clique em "Novo Grupo" para começar.
                            </div>
                        )}

                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            {grupos.map(g => {
                                const membros = companies.filter(c => c.company_group_id === g.id);
                                const semGrupo = companies.filter(c => c.active && c.company_group_id !== g.id && !c.company_group_id);
                                return (
                                    <div key={g.id} className="rounded-2xl border bg-white/[0.02] overflow-hidden" style={{ borderColor: `${g.color}40` }}>
                                        <div className="px-4 py-3 flex items-center justify-between" style={{ backgroundColor: `${g.color}14` }}>
                                            <div className="flex items-center gap-2 min-w-0">
                                                <span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: g.color }} />
                                                <span className="text-white font-semibold text-[14px] truncate">{g.name}</span>
                                                <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-white/10 text-white/70 text-[10px] font-bold">{g.companies_count}</span>
                                            </div>
                                            <div className="flex items-center gap-1 shrink-0">
                                                <button onClick={() => openEditGrupo(g)} className="p-1 text-white/40 hover:text-white transition-colors" title="Editar grupo"><Pencil size={13} /></button>
                                                <button onClick={() => deleteGrupo(g)} className="p-1 text-white/40 hover:text-red-400 transition-colors" title="Excluir grupo"><Trash2 size={13} /></button>
                                            </div>
                                        </div>
                                        <div className="p-3 space-y-1.5">
                                            {membros.length === 0 && <p className="text-white/30 text-[12px] px-1 py-2">Nenhuma empresa neste grupo.</p>}
                                            {membros.map(c => (
                                                <div key={c.id} className="flex items-center justify-between gap-2 rounded-lg bg-white/[0.03] px-3 py-1.5">
                                                    <Link href={route('companies.show', c.id)} className="text-white/80 text-[13px] truncate hover:text-ecf-yellow">{c.name}</Link>
                                                    <button onClick={() => setGroup(c.id, null)} className="p-0.5 text-white/30 hover:text-red-400 transition-colors shrink-0" title="Remover do grupo"><X size={13} /></button>
                                                </div>
                                            ))}
                                            <select
                                                value=""
                                                onChange={e => { if (e.target.value) setGroup(Number(e.target.value), g.id); }}
                                                className="w-full mt-1 h-8 px-2 rounded-lg border border-dashed border-white/[0.12] bg-transparent text-[12px] text-white/50 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                                            >
                                                <option value="">+ Adicionar empresa…</option>
                                                {semGrupo.map(c => <option key={c.id} value={c.id} className="bg-[#0f1116]">{c.name}</option>)}
                                            </select>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>

            {/* Modal link ML OAuth */}
            <Dialog open={mlLinkOpen} onOpenChange={setMlLinkOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShoppingCart size={16} className="text-ecf-yellow/70" /> Link de autorização — Mercado Livre
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        {mlLinkCompany && (
                            <p className="text-white/55 text-[13px]">
                                Envie este link ao cliente da empresa <strong className="text-white/85">{mlLinkCompany.name}</strong>.
                                Após a autorização, a conta ML será vinculada automaticamente. O link expira em <strong className="text-ecf-yellow">7 dias</strong>.
                            </p>
                        )}
                        <div className="flex gap-2">
                            <input readOnly value={mlLinkUrl} onClick={e => e.target.select()} className="flex-1 rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[12px] text-white/70 font-mono focus:outline-none cursor-text" />
                            <Button type="button" size="sm" variant="outline" className="shrink-0 gap-1.5 text-[12px]" onClick={copiarLinkMl}>
                                {mlLinkCopied ? <><Check size={13} className="text-emerald-400" /> Copiado!</> : <><Copy size={13} /> Copiar</>}
                            </Button>
                        </div>
                        <p className="text-white/30 text-[11px]">Gere um novo link a qualquer momento — cada link invalida o anterior.</p>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setMlLinkOpen(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Modal editar empresa (cadastro é só via /comercial/empresas) */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Editar Empresa</DialogTitle>
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
                            <div className="space-y-1.5">
                                <Label>Grupo</Label>
                                <Select value={data.company_group_id || 'none'} onValueChange={v => setData('company_group_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Sem grupo" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Sem grupo</SelectItem>
                                        {grupos.map(g => <SelectItem key={g.id} value={String(g.id)}>{g.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Observações</Label>
                                <Textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={2} />
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Email do Colaborador (acesso ML do cliente / NPS)</Label>
                                <Input type="email" value={data.email_cliente} onChange={e => setData('email_cliente', e.target.value)} placeholder="colaborador@empresa.com.br" />
                                {errors.email_cliente && <p className="text-destructive text-xs">{errors.email_cliente}</p>}
                                <p className="text-white/30 text-[11px]">Email que criamos para o cliente acessar a conta ML como colaborador (também recebe o NPS mensal).</p>
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Telefone</Label>
                                <Input type="tel" value={data.telefone} onChange={e => setData('telefone', e.target.value)} placeholder="(11) 99999-9999" />
                                {errors.telefone && <p className="text-destructive text-xs">{errors.telefone}</p>}
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing}>{processing ? 'Salvando...' : 'Atualizar'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal criar/editar grupo */}
            <Dialog open={grupoModalOpen} onOpenChange={setGrupoModalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editingGrupo ? 'Editar Grupo' : 'Novo Grupo'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitGrupo} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Nome do grupo *</Label>
                            <Input value={grupoForm.data.name} onChange={e => grupoForm.setData('name', e.target.value)} placeholder="Ex: Grupo Camillo, Carteira Premium" required />
                            {grupoForm.errors.name && <p className="text-destructive text-xs">{grupoForm.errors.name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Cor</Label>
                            <div className="flex items-center gap-3">
                                <input type="color" value={grupoForm.data.color} onChange={e => grupoForm.setData('color', e.target.value)} className="h-9 w-14 rounded border border-white/10 bg-transparent cursor-pointer" />
                                <span className="inline-flex items-center gap-1.5 text-[12px] px-2 py-1 rounded-full border" style={{ borderColor: `${grupoForm.data.color}55`, backgroundColor: `${grupoForm.data.color}18`, color: grupoForm.data.color }}>
                                    <Tag size={11} /> {grupoForm.data.name || 'Prévia'}
                                </span>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setGrupoModalOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={grupoForm.processing}>{grupoForm.processing ? 'Salvando...' : editingGrupo ? 'Atualizar' : 'Criar'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
