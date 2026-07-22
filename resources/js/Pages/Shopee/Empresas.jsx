import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Building2, Check, Wrench, Trash2, CheckCircle2, Clock, Copy, RefreshCw, Store } from 'lucide-react';
import { formatCurrency, formatDate, cn } from '@/lib/utils';

const SHOPEE_ORANGE = '#ee4d2d'; // laranja da marca Shopee

function daysLeft(expiresAt) {
    if (!expiresAt) return null;
    return Math.ceil((new Date(expiresAt) - new Date()) / (1000 * 60 * 60 * 24));
}

// Célula "Conexão" da Shopee: status do OAuth + gerar/copiar o link que o cliente
// usa para autorizar. Reusa a rota shopee.oauth.initiate — o líder do Setor Shopee
// e o admin (únicos que enxergam esta aba) já têm sistema.shopee_oauth.
function ShopeeConexao({ company }) {
    const token = company.shopee_token;
    const isConnected = token?.status === 'active';
    const [loading, setLoading]       = useState(false);
    const [copied, setCopied]         = useState(false);
    const [sessionUrl, setSessionUrl] = useState(null);

    const linkUrl   = sessionUrl ?? company.shopee_link_url;
    const pendente  = !!(sessionUrl || company.shopee_link_generated_at) && !!linkUrl;

    const gerarLink = async () => {
        setLoading(true);
        try {
            const res = await fetch(route('shopee.oauth.initiate', company.id), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.url) setSessionUrl(data.url);
        } finally {
            setLoading(false);
        }
    };

    const copiar = () => {
        if (!linkUrl) return;
        navigator.clipboard.writeText(linkUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    // Conectada → notifica com selo verde direto na linha da empresa.
    if (isConnected) {
        return (
            <span
                className="inline-flex items-center gap-1.5 whitespace-nowrap text-[11px] font-semibold text-emerald-400"
                title={token.shop_id ? `Loja #${token.shop_id} · renovação automática` : 'Conectada à Shopee'}
            >
                <CheckCircle2 className="h-3.5 w-3.5 shrink-0" /> Conectada
            </span>
        );
    }

    // Link já gerado (pendente) → mostra "Aguardando" + copiar + regerar.
    if (pendente) {
        const days = daysLeft(company.shopee_link_expires_at);
        const expired = days !== null && days <= 0;
        return (
            <div className="flex items-center gap-1.5 whitespace-nowrap">
                <span className={cn('inline-flex items-center gap-1 text-[11px] font-semibold', expired ? 'text-red-400' : 'text-amber-400')}>
                    <Clock className="h-3.5 w-3.5 shrink-0" />
                    {expired ? 'Link expirado' : `Aguardando${days !== null ? ` · ${days}d` : ''}`}
                </span>
                <button onClick={copiar} title="Copiar link" className="inline-flex items-center justify-center h-6 w-6 rounded-md border border-white/10 text-white/40 hover:text-white hover:border-white/25 transition-colors">
                    {copied ? <Check className="h-3 w-3 text-emerald-400" /> : <Copy className="h-3 w-3" />}
                </button>
                <button onClick={gerarLink} disabled={loading} title="Regerar link" className="inline-flex items-center justify-center h-6 w-6 rounded-md border border-white/10 text-white/40 hover:text-white hover:border-white/25 transition-colors disabled:opacity-40">
                    <RefreshCw className={cn('h-3 w-3', loading && 'animate-spin')} />
                </button>
            </div>
        );
    }

    // Sem link → botão "Gerar link".
    return (
        <button
            onClick={gerarLink}
            disabled={loading}
            className="inline-flex items-center gap-1.5 whitespace-nowrap h-7 px-2.5 rounded-md text-[11px] font-medium transition-colors hover:brightness-110 disabled:opacity-40"
            style={{ backgroundColor: `${SHOPEE_ORANGE}1a`, border: `1px solid ${SHOPEE_ORANGE}33`, color: SHOPEE_ORANGE }}
            title="Gerar o link de conexão Shopee para enviar ao cliente"
        >
            {loading ? <RefreshCw className="h-3.5 w-3.5 shrink-0 animate-spin" /> : <Store className="h-3.5 w-3.5 shrink-0" />}
            Gerar link
        </button>
    );
}

// ─── Página "Empresas" da Shopee (Phase 75; revisada na Phase 78 DEC-78-2/3/4) ─
// Versão ENXUTA de Companies/Index.jsx: SEM colunas de métrica/cust_id/grant.
// Ações: atribuir responsável em massa (bulk-assign), "Resolver" pendência
// (popup: atribui Analista/Estrategista Shopee — selects JÁ escopados ao Setor
// Shopee pelo backend — + contato) e "Excluir" (cancela SÓ o serviço Shopee,
// não apaga a empresa). O "Gerar NPS" avulso foi removido (o NPS passa a ser
// por modelo/disparo — Phase 79).
// Rotas: shopee.empresas.* (index, bulk-assign, resolver, cancelar-servico).

// Dicionário de pendências — SÓ as 3 chaves da DEC-2 (voltadas ao NPS).
const PENDENCIAS = {
    sem_responsavel: { label: 'Sem responsável', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
    sem_contato:     { label: 'Sem contato',     cls: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    empresa_nova:    { label: 'Empresa nova',    cls: 'bg-yellow-500/15 text-ecf-yellow border-ecf-yellow/30' },
};

// Badges de pendência de uma empresa (copiado verbatim do molde).
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

// Badges dos contratos de serviço ativos (mostra até 2 + contador).
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
                <span key={ct.id} title={tooltip(ct)} className="inline-flex items-center whitespace-nowrap bg-white/10 border border-white/10 text-white/85 text-[10px] leading-5 px-2 py-0.5 rounded-full">
                    {ct.servico?.nome ?? '—'}
                </span>
            ))}
            {extra > 0 && (
                <span title={contratos.slice(2).map(tooltip).join('\n')} className="inline-flex items-center whitespace-nowrap bg-white/10 border border-white/10 text-white/50 text-[10px] leading-5 px-2 py-0.5 rounded-full">
                    +{extra}
                </span>
            )}
        </div>
    );
}

export default function Empresas({ companies = [], estrategistas = [], analistas = [], grupos = [] }) {
    // ── Abas Todas / Pendências ──────────────────────────────────────────────
    const [tab, setTab] = useState('todas');
    const [search, setSearch] = useState('');

    const totalAtivas = companies.filter(c => c.active).length;

    // ── Pendências (empresas ativas com ≥1 pendência) ────────────────────────
    const pendentes = companies.filter(c => c.active && (c.pendencias || []).length > 0);
    const pendCounts = { sem_responsavel: 0, sem_contato: 0, empresa_nova: 0 };
    companies.forEach(c => {
        if (!c.active) return;
        (c.pendencias || []).forEach(p => { if (pendCounts[p] !== undefined) pendCounts[p]++; });
    });

    const [pendenciaFilter, setPendenciaFilter] = useState('');
    const pendentesView = pendenciaFilter
        ? pendentes.filter(c => (c.pendencias || []).includes(pendenciaFilter))
        : pendentes;
    const togglePendenciaFilter = (key) => {
        setPendenciaFilter(prev => (prev === key ? '' : key));
        setSelectedIds(new Set());
    };

    // ── Filtro da aba Todas (busca por nome/segmento) ────────────────────────
    const filtered = companies.filter(c => {
        const q = search.toLowerCase();
        return c.name.toLowerCase().includes(q) || (c.segment || '').toLowerCase().includes(q);
    });

    // ── Seleção + atribuição em massa (Analista/Estrategista) ─────────────────
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const toggleSelect = (id) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };
    const clearSelection = () => setSelectedIds(new Set());

    // Fonte da lista da view corrente (Todas vs Pendências) — orienta o "selecionar tudo".
    const viewList = tab === 'todas' ? filtered : pendentesView;
    const allViewSelected = viewList.length > 0 && viewList.every(c => selectedIds.has(c.id));
    const toggleSelectAll = () => setSelectedIds(allViewSelected ? new Set() : new Set(viewList.map(c => c.id)));

    // Fallback defensivo: se o backend não mandou listas por cargo, cai vazio (nunca quebra).
    const analistasOptions = analistas ?? [];
    const estrategistasOptions = estrategistas ?? [];

    // Atribuição em massa → rota dedicada Shopee (nunca a rota core de empresas).
    const bulkAssign = (role, userId) => {
        const ids = [...selectedIds];
        if (!ids.length || !userId) return;
        router.post(route('shopee.empresas.bulk-assign'), { ids, role, user_id: userId }, { preserveScroll: true, onSuccess: clearSelection });
    };

    // ── Resolver pendência (DEC-78-2): popup de edição da empresa ────────────
    // Atribui Analista/Estrategista Shopee (selects JÁ escopados ao Setor Shopee
    // pelo backend) + edita o contato. Salva em shopee.empresas.resolver.
    const [resolverEmpresa, setResolverEmpresa] = useState(null);
    const resolverForm = useForm({ company_id: '', analista_id: '', estrategista_id: '', email_cliente: '' });
    const abrirResolver = (company) => {
        setResolverEmpresa(company);
        resolverForm.setData({
            company_id: company.id,
            analista_id: company.consultor?.id ?? '',
            estrategista_id: company.estrategista?.id ?? '',
            email_cliente: company.email_cliente ?? '',
        });
    };
    const salvarResolver = (e) => {
        e.preventDefault();
        resolverForm.post(route('shopee.empresas.resolver'), {
            preserveScroll: true,
            onSuccess: () => setResolverEmpresa(null),
        });
    };

    // ── Excluir = cancelar SÓ o serviço Shopee (DEC-78-4) ────────────────────
    const excluirServico = (company) => {
        if (!window.confirm(`Cancelar o serviço Shopee de "${company.name}"? A empresa NÃO será apagada — ela só sai da aba Shopee (permanece nos outros serviços/ML).`)) return;
        router.post(route('shopee.empresas.cancelar-servico'), { company_id: company.id }, { preserveScroll: true });
    };

    const TABS = [
        { key: 'todas',      label: `Todas (${totalAtivas})` },
        { key: 'pendencias', label: `Pendências (${pendentes.length})` },
    ];

    // Botão "Excluir" (cancelar serviço Shopee) — reutilizado nas duas abas.
    const ExcluirButton = ({ company }) => (
        <Button
            size="sm"
            variant="ghost"
            className="gap-1.5 text-[12px] text-red-400/80 hover:text-red-400 hover:bg-red-500/10"
            onClick={() => excluirServico(company)}
            title="Cancelar o serviço Shopee (não apaga a empresa)"
        >
            <Trash2 className="h-3.5 w-3.5" /> Excluir
        </Button>
    );

    // Barra de ações em massa (aparece quando há seleção) — atribuição de responsáveis.
    const BulkBar = () => (
        selectedIds.size > 0 && (
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
                <button onClick={clearSelection} className="text-[12px] text-white/40 hover:text-white ml-auto">limpar seleção</button>
            </div>
        )
    );

    return (
        <AppLayout title="Empresas Shopee">
            <div className="space-y-4">

                {/* ─── Abas ─────────────────────────────────────────────────── */}
                <div className="flex gap-1 border-b border-white/[0.08]">
                    {TABS.map(t => (
                        <button
                            key={t.key}
                            onClick={() => { setTab(t.key); clearSelection(); }}
                            className={cn(
                                'px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px',
                                tab === t.key ? 'border-ecf-yellow text-white' : 'border-transparent text-white/50 hover:text-white/80'
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* ══════════════ ABA TODAS ══════════════ */}
                {tab === 'todas' && (
                    <>
                        <div className="flex items-center gap-2 flex-wrap">
                            <Input placeholder="Buscar empresa..." value={search} onChange={e => setSearch(e.target.value)} className="max-w-sm" />
                        </div>

                        <BulkBar />

                        <Card>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-10">
                                                <input type="checkbox" checked={allViewSelected} onChange={toggleSelectAll} className="accent-ecf-yellow w-4 h-4 cursor-pointer align-middle" title="Selecionar todas" />
                                            </TableHead>
                                            <TableHead>Empresa</TableHead>
                                            <TableHead>Segmento</TableHead>
                                            <TableHead>Analista</TableHead>
                                            <TableHead>Estrategista</TableHead>
                                            <TableHead title="Contratos de serviço ativos da empresa">Serviço</TableHead>
                                            <TableHead title="Conexão OAuth Shopee do cliente">Conexão</TableHead>
                                            <TableHead>Contato</TableHead>
                                            <TableHead>Pendências</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filtered.map(c => (
                                            <TableRow key={c.id} className={cn(selectedIds.has(c.id) && 'bg-ecf-yellow/[0.04]')}>
                                                <TableCell>
                                                    <input type="checkbox" checked={selectedIds.has(c.id)} onChange={() => toggleSelect(c.id)} className="accent-ecf-yellow w-4 h-4 cursor-pointer align-middle" />
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        {c.name}
                                                        {c.grupo && (
                                                            <span
                                                                title={`Grupo: ${c.grupo.name}`}
                                                                className="inline-flex items-center text-[10px] font-medium px-1.5 py-0.5 rounded-full border"
                                                                style={{ borderColor: `${c.grupo.color}55`, backgroundColor: `${c.grupo.color}18`, color: c.grupo.color }}
                                                            >
                                                                {c.grupo.name}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-sm text-white/70">{c.segment || <span className="text-white/25">—</span>}</TableCell>
                                                <TableCell className="text-sm">{c.consultor?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                                <TableCell className="text-sm">{c.estrategista?.name || <span className="text-muted-foreground">-</span>}</TableCell>
                                                <TableCell><ServicoBadges contratos={c.contratos_servico || []} /></TableCell>
                                                <TableCell><ShopeeConexao company={c} /></TableCell>
                                                <TableCell className="text-xs text-white/60">{c.email_cliente || <span className="text-white/25">—</span>}</TableCell>
                                                <TableCell><PendenciaBadges pendencias={c.pendencias} /></TableCell>
                                                <TableCell>
                                                    <Badge variant={c.active ? 'success' : 'destructive'}>{c.active ? 'Ativa' : 'Inativa'}</Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <ExcluirButton company={c} />
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {filtered.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={11} className="text-center text-muted-foreground py-8">
                                                    <Building2 className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                                    Nenhuma empresa Shopee encontrada
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

                        <BulkBar />

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
                                            <TableHead>Contato</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {pendentesView.map(c => (
                                            <TableRow key={c.id} className={cn(selectedIds.has(c.id) && 'bg-ecf-yellow/[0.04]')}>
                                                <TableCell>
                                                    <input type="checkbox" checked={selectedIds.has(c.id)} onChange={() => toggleSelect(c.id)} className="accent-ecf-yellow w-4 h-4 cursor-pointer align-middle" />
                                                </TableCell>
                                                <TableCell className="font-medium">{c.name}</TableCell>
                                                <TableCell><PendenciaBadges pendencias={c.pendencias} /></TableCell>
                                                <TableCell className="text-xs text-white/60">
                                                    {c.estrategista?.name || c.consultor?.name
                                                        ? [c.estrategista?.name, c.consultor?.name].filter(Boolean).join(' · ')
                                                        : <span className="text-white/30">ninguém</span>}
                                                </TableCell>
                                                <TableCell className="text-xs text-white/60">{c.email_cliente || <span className="text-white/25">—</span>}</TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="gap-1.5 text-[12px] border-ecf-yellow/30 text-ecf-yellow hover:bg-ecf-yellow/10"
                                                            onClick={() => abrirResolver(c)}
                                                            title="Resolver pendência (atribuir responsáveis Shopee + contato)"
                                                        >
                                                            <Wrench className="h-3.5 w-3.5" /> Resolver
                                                        </Button>
                                                        <ExcluirButton company={c} />
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {pendentesView.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={6} className="text-center text-muted-foreground py-10">
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
            </div>

            {/* Popup "Resolver pendência" — atribuir Analista/Estrategista Shopee + contato.
                Os selects usam as options JÁ escopadas ao Setor Shopee pelo backend (78-01). */}
            <Dialog open={!!resolverEmpresa} onOpenChange={(o) => { if (!o) setResolverEmpresa(null); }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Wrench size={16} className="text-ecf-yellow/70" /> Resolver pendência — {resolverEmpresa?.name}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={salvarResolver} className="space-y-4">
                        <div>
                            <label className="block text-[12px] text-white/60 mb-1">Analista Shopee</label>
                            <select
                                value={resolverForm.data.analista_id}
                                onChange={e => resolverForm.setData('analista_id', e.target.value)}
                                className="w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.1] bg-white/[0.05] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="" className="bg-[#0f1116]">— sem analista —</option>
                                {analistasOptions.map(u => <option key={u.id} value={u.id} className="bg-[#0f1116]">{u.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-[12px] text-white/60 mb-1">Estrategista Shopee</label>
                            <select
                                value={resolverForm.data.estrategista_id}
                                onChange={e => resolverForm.setData('estrategista_id', e.target.value)}
                                className="w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.1] bg-white/[0.05] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="" className="bg-[#0f1116]">— sem estrategista —</option>
                                {estrategistasOptions.map(u => <option key={u.id} value={u.id} className="bg-[#0f1116]">{u.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-[12px] text-white/60 mb-1">Email do cliente (contato)</label>
                            <Input
                                type="email"
                                value={resolverForm.data.email_cliente}
                                onChange={e => resolverForm.setData('email_cliente', e.target.value)}
                                placeholder="contato@empresa.com"
                            />
                        </div>
                        {resolverForm.errors.email_cliente && (
                            <p className="text-[12px] text-red-400">{resolverForm.errors.email_cliente}</p>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setResolverEmpresa(null)}>Cancelar</Button>
                            <Button type="submit" size="sm" disabled={resolverForm.processing}>Salvar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
