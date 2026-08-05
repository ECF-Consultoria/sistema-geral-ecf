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
import { useForm, Link, router, useRemember, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Pencil, Eye, Trash2, Building2, ShoppingCart, Copy, Check, RotateCcw, Tag, Briefcase } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import { cn } from '@/lib/utils';
// Phase 72 Plan 03 v15.0 — Badge NPS pendente na coluna Empresa da listagem/pendências
import NpsPendingBadge from '@/Components/Nps/NpsPendingBadge';
// Phase 37 Plan 37-06 (REQ-37-07) — GruposManager removido daqui; aba Grupos
// migrou para /comercial/empresas/listagem (Plan 37-05). Briefcase removido
// junto pois o botao "Servico" inline tambem nao aparece mais (pendencia
// sem_servico migrou para o Comercial).
import { IMaskInput } from 'react-imask';

// Quick task 260805-eqk — MARKETPLACES_EXTRAS removida junto com a coluna
// companies.marketplaces_extras (substituida pela tabela company_marketplaces).

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

// ─── Pendências (5 tipos calculados no backend) ─────────────────────────────
// Phase 34 Plan 34-03 — empresa_nova adicionado (D-06): empresa recem-cadastrada
// que ainda nao foi triada pelo admin; sai via botao "Marcar como visto" inline.
// Phase 37 Plan 37-06 (REQ-37-07) — pendencia sem_servico removida desta lista;
// migrou para /comercial/empresas/listagem (Plan 37-05). Apos o scope Performance
// no backend, toda empresa em /companies ja tem >=1 contrato Performance ativo.
const PENDENCIAS = {
    sem_responsavel:       { label: 'Sem responsável',       cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
    sem_cust_id:           { label: 'Sem cust id',           cls: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    sem_email_colaborador: { label: 'Sem email colaborador', cls: 'bg-amber-500/10 text-amber-300 border-amber-500/20' },
    sem_grant_ativo:       { label: 'Sem grant ativo',       cls: 'bg-sky-500/10 text-sky-400 border-sky-500/20' },
    empresa_nova:          { label: 'Empresa nova',          cls: 'bg-yellow-500/15 text-ecf-yellow border-ecf-yellow/30' },
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

export default function Companies({ companies, users, estrategistas = [], analistas = [], grupos = [], servico_counts = [], servicos_disponiveis = [], filters = {}, nps_pendentes = [] }) {
    // Phase 72 Plan 03 v15.0 — Guard defensivo pra prop `nps_pendentes` (Plan 72-02 injection)
    const npsPendentesList = nps_pendentes ?? [];
    // Phase 34 Plan 34-03 — admin check para botao "Marcar como visto" (D-06)
    const { auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';

    // Lê a aba inicial do query param ?tab (deep-link vindo do menu lateral, ex: Empresas › Pendências).
    // Lazy initializer roda apenas uma vez no mount; valores inválidos/ausentes caem em 'empresas'.
    // Phase 37 Plan 37-06 (REQ-37-07) — aba 'grupos' removida; deep-links antigos
    // caem silenciosamente em 'empresas'. Gestao de grupos migrou para /comercial/empresas/listagem.
    const [tab, setTab] = useState(() => {
        const t = new URLSearchParams(window.location.search).get('tab');
        return ['empresas', 'pendencias'].includes(t) ? t : 'empresas';
    });
    const [search, setSearch] = useRemember('', 'companies-index-search');
    const [servicoFilter, setServicoFilter] = useState(''); // servico id (string) ou ''
    const custIdStatusFilter = filters.cust_id_status || '';
    // Phase 35 Plan 35-01 (D-02) — sort por created_at na aba Pendencias.
    // Backend so honra valores 'nova_recente'|'nova_antiga'; '' (default) cai no orderBy('name').
    const sortFilter = filters.sort || '';

    const aplicarCustIdFilter = (valor) => {
        router.get(route('companies.index'), valor ? { cust_id_status: valor } : {}, { preserveState: true, preserveScroll: true });
    };

    // Phase 35 Plan 35-01 (D-02) — aplica/limpa ?sort= preservando o tab=pendencias na URL.
    const aplicarSort = (valor) => {
        const params = {};
        if (custIdStatusFilter) params.cust_id_status = custIdStatusFilter;
        if (valor) params.sort = valor;
        params.tab = 'pendencias';
        router.get(route('companies.index'), params, { preserveState: true, preserveScroll: true });
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

    // Listas por CARGO (vindas do backend). Fallback defensivo por role legacy
    // caso o cargo não esteja preenchido, pra o select nunca ficar vazio.
    const analistasOptions = analistas.length > 0 ? analistas : users.filter(u => u.role === 'consultor');
    const estrategistasOptions = estrategistas.length > 0 ? estrategistas : users.filter(u => u.role === 'mentor');

    // ── Filtro da aba Empresas (busca por nome/segmento/cust id + serviço) ────
    const filtered = companies.filter(c => {
        const q = search.toLowerCase();
        const buscaOk = c.name.toLowerCase().includes(q)
            || (c.segment || '').toLowerCase().includes(q)
            || String(c.adman_account_id || '').includes(q)
            || String(c.ml_store_id || '').includes(q);
        const servicoOk = !servicoFilter || (c.contratos_servico || []).some(ct => String(ct.servico?.id) === servicoFilter);
        return buscaOk && servicoOk;
    });

    const totalAtivas = companies.filter(c => c.active).length;

    // ── Pendências (empresas ativas com ≥1 pendência) ────────────────────────
    // Phase 34 Plan 34-03 — pendCounts ganhou sem_servico (5o card existente) e empresa_nova (D-06).
    // Phase 37 Plan 37-06 (REQ-37-07) — sem_servico removido do pendCounts; migrou
    // para /comercial/empresas/listagem (Plan 37-05).
    const pendentes = companies.filter(c => c.active && (c.pendencias || []).length > 0);
    const pendCounts = { sem_responsavel: 0, sem_cust_id: 0, sem_email_colaborador: 0, sem_grant_ativo: 0, empresa_nova: 0 };
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
    // Phase 34 Plan 34-03 — email_colaborador SEPARADO de email_cliente. Quick
    // task 260805-eqk removeu os 5 campos mortos do close (nicho/dor/vende_ml/
    // faturamento_mensal/marketplaces_extras) e, com eles, o transform().
    const form = useForm({
        name: '', cnpj: '', adman_store_id: '', ml_store_id: '', segment: '', notes: '',
        consultor_id: '', estrategista_id: '', email_cliente: '', telefone: '', company_group_id: '',
        email_colaborador: '',
    });
    const { data, setData, processing, errors } = form;
    const put = form.put;

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
            // Phase 34 Plan 34-03 — pre-popula o campo do close que sobrou.
            email_colaborador: c.email_colaborador || '',
        });
        setOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('companies.update', editing.id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    // Phase 34 Plan 34-03 — handler do botao "Marcar como visto" (D-06)
    const marcarVisto = (c) => {
        router.post(route('companies.marcar-visto', c.id), {}, { preserveScroll: true });
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

    // Grupos nomeados: a gestão (cards/modal/atribuição) vive no componente
    // compartilhado GruposManager (mesma UI usada em /administrativo/empresas).

    // Phase 37 Plan 37-06 (REQ-37-07) — aba Grupos removida; migrou para
    // /comercial/empresas/listagem (Plan 37-05).
    const TABS = [
        { key: 'empresas',   label: `Empresas (${totalAtivas})` },
        { key: 'pendencias', label: `Pendências (${pendentes.length})` },
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
                                                        {/* Phase 72 Plan 03 v15.0 — Badge NPS pendente (compact pra tabela) */}
                                                        <NpsPendingBadge companyId={c.id} pendentes={npsPendentesList} variant="compact" />
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
                            {/* Phase 35 Plan 35-01 (D-02) — sort por created_at so quando filtro=empresa_nova.
                                Outras pendencias mantem ordem alfabetica (padrao do backend). */}
                            {pendenciaFilter === 'empresa_nova' && (
                                <div className="ml-auto flex items-center gap-2">
                                    <span className="text-[12px] text-white/40">Ordenar:</span>
                                    <select
                                        value={sortFilter}
                                        onChange={e => aplicarSort(e.target.value)}
                                        className="h-8 pl-2.5 pr-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                                        title="Ordenar empresas novas por data de cadastro"
                                    >
                                        <option value="">Padrão (nome)</option>
                                        <option value="nova_recente">Mais recente primeiro</option>
                                        <option value="nova_antiga">Mais antiga primeiro</option>
                                    </select>
                                </div>
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
                                    className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.1] bg-white/[0.05] text-[13px] text-white/80 cursor-pointer focus:outline-none focus:border-ecf-yellow/40"
                                >
                                    <option value="">Atribuir Analista…</option>
                                    {analistasOptions.map(u => <option key={u.id} value={u.id} className="bg-[#0f1116]">{u.name}</option>)}
                                </select>
                                <select
                                    value=""
                                    onChange={e => { if (e.target.value) bulkAssign('estrategista', Number(e.target.value)); e.target.value = ''; }}
                                    className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.1] bg-white/[0.05] text-[13px] text-white/80 cursor-pointer focus:outline-none focus:border-ecf-yellow/40"
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
                                                        {/* Phase 72 Plan 03 v15.0 — Badge NPS pendente na aba Pendências */}
                                                        <NpsPendingBadge companyId={c.id} pendentes={npsPendentesList} variant="compact" />
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
                                                        {/* Phase 34 Plan 34-03 (D-06) — botao "Marcar como visto"
                                                            so aparece quando a empresa esta com pendencia empresa_nova
                                                            e o user atual eh admin. Click chama POST /companies/{id}/marcar-visto
                                                            e remove a pendencia inline (preserveScroll preserva posicao na lista). */}
                                                        {(c.pendencias || []).includes('empresa_nova') && isAdmin && (
                                                            <button
                                                                type="button"
                                                                onClick={() => marcarVisto(c)}
                                                                title="Marcar empresa como vista (sai da lista de pendencias)"
                                                                className="inline-flex items-center justify-center h-8 w-8 rounded-md border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/15"
                                                            >
                                                                <Check className="h-4 w-4" />
                                                            </button>
                                                        )}
                                                        {/* Phase 37 Plan 37-06 (REQ-37-07) — botao inline "Servico"
                                                            removido junto com a pendencia sem_servico. Atribuicao de servico
                                                            agora vive em /comercial/empresas/listagem + /comercial/empresas/{id}/atribuir-servico. */}
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

                {/* Phase 37 Plan 37-06 (REQ-37-07) — aba Grupos migrou para /comercial/empresas/listagem (Plan 37-05). */}
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

            {/* Modal editar empresa (cadastro é só via /comercial/empresas).
                Hotfix 2026-06-19 — max-h-[90vh] + overflow-y-auto pra rolar
                quando o conteúdo ultrapassar a viewport (form admin tem ~6
                blocos de campos). max-w-3xl da mais respiro horizontal. */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
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
                                {/* Phase 34 D-08 — mascara CNPJ com IMaskInput (so fricao UX no front; backend nao valida formato) */}
                                <IMaskInput
                                    mask="00.000.000/0000-00"
                                    value={data.cnpj}
                                    onAccept={(value) => setData('cnpj', value)}
                                    placeholder="00.000.000/0001-00"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                />
                                {errors.cnpj && <p className="text-destructive text-xs">{errors.cnpj}</p>}
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
                                        {analistasOptions.map(u => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
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
                            {/* Phase 34 D-07 — email_cliente eh o email do PROPRIETARIO usado pelo NPS mensal.
                                NAO confundir com email_colaborador (proximo bloco), que eh criado pela ECF p/ acesso ML. */}
                            <div className="col-span-2 space-y-1.5">
                                <Label>Email do cliente (NPS)</Label>
                                <Input type="email" value={data.email_cliente} onChange={e => setData('email_cliente', e.target.value)} placeholder="proprietario@empresa.com.br" />
                                {errors.email_cliente && <p className="text-destructive text-xs">{errors.email_cliente}</p>}
                                <p className="text-white/30 text-[11px]">Email do proprietario, destinatario do NPS mensal.</p>
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Telefone</Label>
                                {/* Phase 34 D-08 — mascara telefone dinamica (8 ou 9 digitos) */}
                                <IMaskInput
                                    mask={[
                                        { mask: '(00) 0000-0000' },
                                        { mask: '(00) 00000-0000' },
                                    ]}
                                    value={data.telefone}
                                    onAccept={(value) => setData('telefone', value)}
                                    placeholder="(11) 99999-9999"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                />
                                {errors.telefone && <p className="text-destructive text-xs">{errors.telefone}</p>}
                            </div>
                        </div>

                        {/* ─── Phase 34 Plan 34-03 — Informacoes do close ──────────────
                            Quick task 260805-eqk esvaziou este bloco: nicho, dor,
                            vende_ml, faturamento_mensal e marketplaces_extras
                            deixaram de existir. Sobrou o email_colaborador, que o
                            admin continua podendo preencher (alimenta a pendencia
                            sem_email_colaborador). */}
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-4">
                            <div className="flex items-center gap-2">
                                <Briefcase size={14} className="text-ecf-yellow/70" />
                                <h3 className="text-white/85 text-sm font-semibold">Informacoes do close</h3>
                            </div>
                            {/* Phase 34 D-07 — email_colaborador SEPARADO de email_cliente.
                                Labels claras pra evitar confusao operacional. */}
                            <div className="space-y-1.5">
                                <Label>Email colaborador ECF</Label>
                                <Input
                                    type="email"
                                    value={data.email_colaborador}
                                    onChange={e => setData('email_colaborador', e.target.value)}
                                    placeholder="colaborador@ecfconsultoria.com.br"
                                />
                                {errors.email_colaborador && <p className="text-destructive text-xs">{errors.email_colaborador}</p>}
                                <p className="text-white/30 text-[11px]">Email que a ECF criou para acesso colaborador no ML.</p>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing}>{processing ? 'Salvando...' : 'Atualizar'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
